<?php
/**
 * Inventaire des fichiers du site, reprenable d'une requête à l'autre.
 *
 * Écrit la liste des chemins (relatifs à la racine du site) dans un fichier texte, un par ligne ;
 * un dossier vide est noté avec un « / » final. Les dossiers de cache et les sauvegardes
 * d'autres extensions sont exclus.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BKWB_File_Scanner {

	public static function init( $root, $list_file ) {
		return array(
			'root'     => rtrim( wp_normalize_path( $root ), '/' ),
			'list'     => $list_file,
			'size'     => 0,
			'stack'    => array( '' ),
			'excludes' => self::exclusions( $root ),
			'files'    => 0,
			'bytes'    => 0,
			'skipped'  => 0,
			'warnings' => array(),
		);
	}

	public static function exclusions( $root ) {
		$root    = rtrim( wp_normalize_path( $root ), '/' ) . '/';
		$content = wp_normalize_path( WP_CONTENT_DIR );
		$c       = 0 === strpos( $content . '/', $root ) ? substr( $content, strlen( $root ) ) : 'wp-content';
		$list    = array(
			"$c/cache",
			"$c/wpvividbackups",
			"$c/updraft",
			"$c/ai1wm-backups",
			"$c/backups-dup-lite",
			"$c/backups-dup-pro",
			"$c/backup-db",
			"$c/backupwordpress-*",
			"$c/uploads/backwpup-*",
			"$c/upgrade",
			"$c/upgrade-temp-backup",
			"$c/et-cache",
			"$c/litespeed",
			"$c/maintenance-pilote-snapshots",
			"$c/bk-web-backup-*",
			"$c/debug.log",
			'.git',
			'*/.git',
			'bkwb-secours.php',
			'bkwb-secours-*',
			'.maintenance',
		);
		$base = wp_normalize_path( BKWB_Storage::base_dir() );
		if ( 0 === strpos( $base . '/', $root ) ) {
			$list[] = substr( $base, strlen( $root ) );
		}
		foreach ( BKWB_Settings::excludes() as $ex ) {
			$ex = wp_normalize_path( $ex );
			if ( 0 === strpos( $ex, $root ) ) {
				$ex = substr( $ex, strlen( $root ) );
			}
			$list[] = trim( $ex, '/' );
		}
		return array_values( array_unique( array_filter( $list ) ) );
	}

	public static function excluded( $rel, array $excludes ) {
		foreach ( $excludes as $ex ) {
			if ( false !== strpos( $ex, '*' ) ) {
				if ( fnmatch( $ex, $rel ) ) {
					return true;
				}
			} elseif ( $rel === $ex ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parcourt les dossiers jusqu'à la fin ou jusqu'à $deadline. Retourne true quand c'est terminé.
	 */
	public static function run( array &$st, $deadline ) {
		$fh = @fopen( $st['list'], 'c+b' );
		if ( ! $fh ) {
			throw new RuntimeException( "Impossible d'écrire la liste des fichiers : " . $st['list'] );
		}
		ftruncate( $fh, $st['size'] );
		fseek( $fh, $st['size'] );

		while ( $st['stack'] ) {
			$rel = array_pop( $st['stack'] );
			$abs = $st['root'] . ( '' === $rel ? '' : '/' . $rel );
			$dh  = @opendir( $abs );
			if ( ! $dh ) {
				self::warn( $st, 'Dossier illisible, ignoré : ' . $rel );
				continue;
			}
			$subdirs = array();
			$lines   = '';
			$count   = 0;
			while ( false !== ( $item = readdir( $dh ) ) ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$r = '' === $rel ? $item : $rel . '/' . $item;
				if ( false !== strpbrk( $item, "\r\n" ) ) {
					self::warn( $st, 'Nom de fichier invalide, ignoré : ' . $r );
					continue;
				}
				if ( self::excluded( $r, $st['excludes'] ) ) {
					continue;
				}
				$p = $abs . '/' . $item;
				if ( is_link( $p ) ) {
					$st['skipped']++;
					continue;
				}
				if ( is_dir( $p ) ) {
					$subdirs[] = $r;
					$count++;
					continue;
				}
				if ( ! is_file( $p ) ) {
					continue;
				}
				if ( ! is_readable( $p ) ) {
					self::warn( $st, 'Fichier illisible, ignoré : ' . $r );
					continue;
				}
				$lines       .= $r . "\n";
				$st['files']++;
				$st['bytes'] += (int) @filesize( $p );
				$count++;
			}
			closedir( $dh );
			if ( 0 === $count && '' !== $rel ) {
				$lines .= $rel . "/\n";
			}
			if ( '' !== $lines && strlen( $lines ) !== fwrite( $fh, $lines ) ) {
				fclose( $fh );
				throw new RuntimeException( "Écriture de la liste des fichiers impossible (espace disque plein ?)." );
			}
			rsort( $subdirs );
			foreach ( $subdirs as $d ) {
				$st['stack'][] = $d;
			}
			$st['size'] = ftell( $fh );
			if ( $deadline && microtime( true ) >= $deadline && $st['stack'] ) {
				fclose( $fh );
				return false;
			}
		}
		fclose( $fh );
		return true;
	}

	private static function warn( array &$st, $message ) {
		if ( count( $st['warnings'] ) < 50 ) {
			$st['warnings'][] = $message;
		}
	}
}
