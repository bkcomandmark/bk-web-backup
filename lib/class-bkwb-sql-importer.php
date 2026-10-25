<?php
/**
 * Import d'un fichier SQL par morceaux, reprenable d'une requête à l'autre.
 *
 * Lit le fichier ligne par ligne et exécute chaque instruction terminée par « ; » en fin de
 * ligne (format écrit par BK WEB Backup, compatible avec la plupart des exports mysqldump).
 * Avec un préfixe de réécriture, les tables sont importées sous un nom temporaire
 * (ex. bkr_wp_options) pour ne pas toucher au site tant que l'import n'est pas terminé.
 *
 * Aucune dépendance à WordPress : ce fichier est aussi embarqué dans le script de secours.
 */
class BKWB_Sql_Importer {

	/** @var array État à conserver : offset, statements, tables. */
	public $state;

	private $db;
	private $file;
	private $rewrite;

	public function __construct( mysqli $db, $file, array $state = array(), $rewrite_prefix = '' ) {
		$this->db      = $db;
		$this->file    = $file;
		$this->rewrite = (string) $rewrite_prefix;
		$this->state   = array_merge(
			array(
				'offset'     => 0,
				'statements' => 0,
				'tables'     => array(),
			),
			$state
		);
	}

	/**
	 * Importe jusqu'à la fin du fichier ou jusqu'à $deadline. Retourne true quand c'est terminé.
	 */
	public function run( $deadline = 0 ) {
		$fh = @fopen( $this->file, 'rb' );
		if ( ! $fh ) {
			throw new RuntimeException( "Impossible d'ouvrir le fichier SQL : " . $this->file );
		}
		fseek( $fh, $this->state['offset'] );
		$this->db->query( 'SET FOREIGN_KEY_CHECKS = 0' );
		$this->db->query( "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'" );

		$stmt = '';
		while ( false !== ( $line = fgets( $fh ) ) ) {
			if ( '' === $stmt ) {
				$trim = ltrim( $line );
				if ( '' === trim( $line ) || 0 === strpos( $trim, '-- ' ) || "--\n" === $trim || 0 === strpos( $trim, '#' ) ) {
					$this->state['offset'] = ftell( $fh );
					continue;
				}
			}
			$stmt .= $line;
			if ( ';' !== substr( rtrim( $line ), -1 ) ) {
				continue;
			}
			$this->execute( rtrim( $stmt ) );
			$stmt                       = '';
			$this->state['offset']      = ftell( $fh );
			$this->state['statements'] += 1;
			if ( $deadline && microtime( true ) >= $deadline ) {
				fclose( $fh );
				return false;
			}
		}
		fclose( $fh );
		if ( '' !== trim( $stmt ) ) {
			$this->execute( rtrim( $stmt ) );
			$this->state['statements'] += 1;
		}
		return true;
	}

	private function execute( $sql ) {
		if ( '' !== $this->rewrite ) {
			$sql = $this->rewrite_tables( $sql );
		}
		if ( $this->db->query( $sql ) ) {
			return;
		}
		$errno = $this->db->errno;
		// Export venant de MySQL 8 importé sur MariaDB : interclassement inconnu.
		if ( 1273 === $errno && false !== strpos( $sql, 'utf8mb4_0900_' ) ) {
			$sql = preg_replace( '/utf8mb4_0900_[a-z_]+/', 'utf8mb4_unicode_ci', $sql );
			if ( $this->db->query( $sql ) ) {
				return;
			}
		}
		throw new RuntimeException( 'Erreur SQL (' . $this->db->errno . ') : ' . $this->db->error . ' — instruction : ' . substr( $sql, 0, 160 ) );
	}

	private function rewrite_tables( $sql ) {
		$prefix = $this->rewrite;
		$tables = &$this->state['tables'];
		$sql    = preg_replace_callback(
			'/^(DROP TABLE IF EXISTS|CREATE TABLE IF NOT EXISTS|CREATE TABLE|INSERT INTO|REPLACE INTO|LOCK TABLES|ALTER TABLE)\s+`([^`]+)`/i',
			function ( $m ) use ( $prefix, &$tables ) {
				if ( ! in_array( $m[2], $tables, true ) ) {
					$tables[] = $m[2];
				}
				return $m[1] . ' `' . $prefix . $m[2] . '`';
			},
			$sql,
			1
		);
		if ( 0 === stripos( $sql, 'CREATE TABLE' ) && false !== stripos( $sql, 'REFERENCES' ) ) {
			$sql = preg_replace( '/REFERENCES\s+`([^`]+)`/i', 'REFERENCES `' . $prefix . '$1`', $sql );
		}
		return $sql;
	}
}
