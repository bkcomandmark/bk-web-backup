<?php
/**
 * Export de la base de données en SQL, par lots, reprenable d'une requête à l'autre.
 *
 * Chaque instruction tient sur une seule ligne terminée par « ; » : l'import peut ainsi
 * reprendre exactement où il s'était arrêté. Les grandes tables avec une clé primaire
 * numérique sont parcourues par clé (rapide), les autres par décalage (LIMIT).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BKWB_Db_Exporter {

	const MAX_STATEMENT = 1000000; // Taille maximale d'une instruction INSERT (1 Mo).

	public static function init( $file ) {
		global $wpdb;
		$all    = (bool) BKWB_Settings::get( 'all_tables' );
		$tables = array();
		foreach ( (array) $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N ) as $row ) {
			if ( isset( $row[1] ) && 'BASE TABLE' !== strtoupper( $row[1] ) ) {
				continue; // Les vues ne sont pas exportées.
			}
			$name = $row[0];
			if ( 0 === strpos( $name, 'bkr_' ) || 0 === strpos( $name, 'bko_' ) ) {
				continue; // Tables temporaires d'une restauration.
			}
			if ( ! $all && 0 !== strpos( $name, $wpdb->prefix ) ) {
				continue;
			}
			$tables[] = $name;
		}
		$total = 0;
		$est   = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s', DB_NAME ), ARRAY_N );
		foreach ( (array) $est as $row ) {
			if ( in_array( $row[0], $tables, true ) ) {
				$total += (int) $row[1];
			}
		}
		return array(
			'file'       => $file,
			'size'       => 0,
			'tables'     => $tables,
			'ti'         => 0,
			'table'      => null,
			'batch'      => 500,
			'rows_done'  => 0,
			'rows_total' => max( 1, $total ),
		);
	}

	/**
	 * Exporte jusqu'à la fin ou jusqu'à $deadline. Retourne true quand l'export est terminé.
	 */
	public static function run( array &$st, $deadline ) {
		global $wpdb;
		$fh = @fopen( $st['file'], 'c+b' );
		if ( ! $fh ) {
			throw new RuntimeException( "Impossible d'écrire l'export de la base : " . $st['file'] );
		}
		ftruncate( $fh, $st['size'] );
		fseek( $fh, $st['size'] );

		$suppress = $wpdb->suppress_errors( true );
		$wpdb->query( "SET time_zone = '+00:00'" );

		try {
			if ( 0 === (int) $st['size'] ) {
				$charset = $wpdb->charset ? $wpdb->charset : 'utf8mb4';
				self::put(
					$fh,
					'-- BK WEB Backup ' . BKWB_VERSION . ' — ' . home_url() . ' — ' . gmdate( 'c' ) . "\n"
					. "SET NAMES $charset;\n"
					. "SET FOREIGN_KEY_CHECKS = 0;\n"
					. "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n"
					. "SET time_zone = '+00:00';\n"
				);
			}

			$count = count( $st['tables'] );
			while ( $st['ti'] < $count ) {
				$table = $st['tables'][ $st['ti'] ];
				$qt    = self::quote_name( $table );

				if ( null === $st['table'] ) {
					$create = $wpdb->get_row( "SHOW CREATE TABLE $qt", ARRAY_N );
					if ( ! $create || empty( $create[1] ) ) {
						$st['ti']++; // Table supprimée entre-temps.
						continue;
					}
					self::put( $fh, "DROP TABLE IF EXISTS $qt;\n" . preg_replace( '/\s*\n\s*/', ' ', $create[1] ) . ";\n" );
					$st['table'] = self::describe( $table );
				}

				$tb    = $st['table'];
				$cols  = implode( ',', array_map( array( __CLASS__, 'quote_name' ), $tb['cols'] ) );
				$limit = (int) $st['batch'];
				if ( 'key' === $tb['mode'] ) {
					$pk    = self::quote_name( $tb['pk'] );
					$where = null === $tb['cursor'] ? '' : " WHERE $pk > " . $tb['cursor'];
					$rows  = $wpdb->get_results( "SELECT $cols FROM $qt$where ORDER BY $pk ASC LIMIT $limit", ARRAY_N );
				} else {
					$rows = $wpdb->get_results( "SELECT $cols FROM $qt LIMIT " . (int) $tb['offset'] . ", $limit", ARRAY_N );
				}
				if ( $wpdb->last_error ) {
					throw new RuntimeException( 'Lecture de la table ' . $table . ' impossible : ' . $wpdb->last_error );
				}

				if ( $rows ) {
					$bytes = self::write_rows( $fh, $qt, $cols, $rows );
					$last  = end( $rows );
					if ( 'key' === $tb['mode'] ) {
						$tb['cursor'] = preg_replace( '/[^0-9-]/', '', (string) $last[ $tb['pk_index'] ] );
					} else {
						$tb['offset'] += count( $rows );
					}
					$st['rows_done'] += count( $rows );
					$st['table']      = $tb;
					// Ajuste la taille des lots selon le poids des lignes.
					if ( $bytes > 8 * MB_IN_BYTES ) {
						$st['batch'] = max( 25, intdiv( $limit, 2 ) );
					} elseif ( $bytes < 2 * MB_IN_BYTES && $limit < 4000 ) {
						$st['batch'] = $limit * 2;
					}
				}
				if ( ! $rows || count( $rows ) < $limit ) {
					$st['table'] = null;
					$st['ti']++;
				}
				fflush( $fh );
				$st['size'] = ftell( $fh );
				if ( $deadline && microtime( true ) >= $deadline && $st['ti'] < $count ) {
					fclose( $fh );
					$wpdb->suppress_errors( $suppress );
					return false;
				}
			}

			self::put( $fh, "SET FOREIGN_KEY_CHECKS = 1;\n" );
			fflush( $fh );
			$st['size'] = ftell( $fh );
		} catch ( Exception $e ) {
			fclose( $fh );
			$wpdb->suppress_errors( $suppress );
			throw $e;
		}
		fclose( $fh );
		$wpdb->suppress_errors( $suppress );
		return true;
	}

	/**
	 * Colonnes à exporter (hors colonnes calculées) et mode de parcours de la table.
	 */
	private static function describe( $table ) {
		global $wpdb;
		$qt    = self::quote_name( $table );
		$cols  = array();
		$types = array();
		foreach ( (array) $wpdb->get_results( "SHOW COLUMNS FROM $qt", ARRAY_A ) as $c ) {
			if ( isset( $c['Extra'] ) && false !== stripos( $c['Extra'], 'GENERATED' ) ) {
				continue;
			}
			$cols[]                = $c['Field'];
			$types[ $c['Field'] ] = strtolower( $c['Type'] );
		}
		$keys = (array) $wpdb->get_results( "SHOW KEYS FROM $qt WHERE Key_name = 'PRIMARY'", ARRAY_A );
		$mode = 'offset';
		$pk   = null;
		if ( 1 === count( $keys ) ) {
			$col = $keys[0]['Column_name'];
			if ( isset( $types[ $col ] ) && false !== strpos( $types[ $col ], 'int' ) ) {
				$mode = 'key';
				$pk   = $col;
			}
		}
		return array(
			'cols'     => $cols,
			'mode'     => $mode,
			'pk'       => $pk,
			'pk_index' => null === $pk ? null : array_search( $pk, $cols, true ),
			'cursor'   => null,
			'offset'   => 0,
		);
	}

	private static function write_rows( $fh, $qt, $cols, array $rows ) {
		global $wpdb;
		$dbh    = $wpdb->dbh;
		$mysqli = $dbh instanceof mysqli;
		$head   = "INSERT INTO $qt ($cols) VALUES ";
		$buf    = '';
		$bytes  = 0;
		foreach ( $rows as $row ) {
			$vals = array();
			foreach ( $row as $v ) {
				if ( null === $v ) {
					$vals[] = 'NULL';
				} elseif ( $mysqli ) {
					$vals[] = "'" . mysqli_real_escape_string( $dbh, $v ) . "'";
				} else {
					$vals[] = "'" . $wpdb->remove_placeholder_escape( esc_sql( $v ) ) . "'";
				}
			}
			$tuple = '(' . implode( ',', $vals ) . ')';
			if ( '' !== $buf && strlen( $buf ) + strlen( $tuple ) > self::MAX_STATEMENT ) {
				self::put( $fh, $head . $buf . ";\n" );
				$buf = '';
			}
			$buf   .= ( '' === $buf ? '' : ',' ) . $tuple;
			$bytes += strlen( $tuple );
		}
		if ( '' !== $buf ) {
			self::put( $fh, $head . $buf . ";\n" );
		}
		return $bytes;
	}

	public static function quote_name( $name ) {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	private static function put( $fh, $data ) {
		if ( strlen( $data ) !== fwrite( $fh, $data ) ) {
			throw new RuntimeException( "Écriture de l'export de la base impossible (espace disque plein ?)." );
		}
	}
}
