<?php
/**
 * Tâche de sauvegarde, découpée en étapes qui avancent par tranches de quelques secondes :
 * export de la base → inventaire des fichiers → archive .tar.gz → envoi vers S3 → nettoyage.
 * Chaque étape sait reprendre là où la précédente tranche s'est arrêtée.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BKWB_Backup_Job {

	const PART_SIZE = 16777216; // 16 Mo par partie envoyée.

	private static $labels = array(
		'db'        => 'Export de la base de données',
		'scan'      => 'Inventaire des fichiers',
		'archive'   => 'Création de l’archive',
		'finalize'  => 'Finalisation de l’archive',
		'upload'    => 'Envoi vers le stockage OVH',
		'retention' => 'Nettoyage des anciennes copies',
	);

	public static function create( $trigger = 'manual', $content = null ) {
		if ( ! function_exists( 'deflate_init' ) ) {
			throw new RuntimeException( "L'extension PHP zlib est nécessaire pour créer les archives." );
		}
		$content = in_array( $content, array( 'full', 'db' ), true ) ? $content : BKWB_Settings::get( 'content' );
		$id      = wp_date( 'Ymd-His' );
		$name    = BKWB_Settings::site_slug() . '_' . $id . '_' . $content;

		BKWB_Jobs::clean_work( false );
		$work  = BKWB_Storage::dir( 'work' );
		$state = array(
			'type'     => 'backup',
			'status'   => 'running',
			'step'     => 'db',
			'trigger'  => $trigger,
			'content'  => $content,
			'id'       => $id,
			'name'     => $name,
			'started'  => time(),
			'ticks'    => 0,
			'crashes'  => 0,
			'in_tick'  => false,
			'retry_at' => 0,
			'remote'   => BKWB_Settings::s3_ready(),
			'progress' => 0,
			'label'    => self::$labels['db'],
			'warnings' => array(),
			'log'      => array(),
			'error'    => '',
			'db'       => BKWB_Db_Exporter::init( $work . '/database.sql' ),
			'scan'     => 'full' === $content ? BKWB_File_Scanner::init( ABSPATH, $work . '/files.txt' ) : null,
			'archive'  => null,
			'upload'   => null,
		);
		self::log( $state, 'Début de la sauvegarde ' . ( 'full' === $content ? 'complète' : 'de la base seule' ) . ' (' . $trigger . ').' );
		if ( ! $state['remote'] ) {
			self::warn( $state, 'Stockage OVH non configuré : la sauvegarde restera uniquement sur l’hébergement.' );
		}
		BKWB_Jobs::save( $state );
		return $state;
	}

	/**
	 * Fait avancer la tâche d'une étape (ou d'une partie d'étape).
	 */
	public static function step( array &$s, $deadline ) {
		$s['label'] = isset( self::$labels[ $s['step'] ] ) ? self::$labels[ $s['step'] ] : $s['label'];
		switch ( $s['step'] ) {
			case 'db':
				if ( BKWB_Db_Exporter::run( $s['db'], $deadline ) ) {
					self::log( $s, sprintf( 'Base de données exportée : %d tables, %s.', count( $s['db']['tables'] ), size_format( $s['db']['size'] ) ) );
					$s['step'] = $s['scan'] ? 'scan' : 'archive';
				}
				break;

			case 'scan':
				if ( BKWB_File_Scanner::run( $s['scan'], $deadline ) ) {
					foreach ( $s['scan']['warnings'] as $w ) {
						self::warn( $s, $w );
					}
					self::log( $s, sprintf( 'Fichiers à sauvegarder : %s fichiers, %s.', number_format_i18n( $s['scan']['files'] ), size_format( $s['scan']['bytes'] ) ) );
					$s['step'] = 'archive';
				}
				break;

			case 'archive':
				if ( null === $s['archive'] ) {
					$s['archive'] = array(
						'part'       => BKWB_Storage::dir( 'work' ) . '/' . $s['name'] . '.tar.gz.part',
						'writer'     => array(),
						'stage'      => 'manifest',
						'list_off'   => 0,
						'files_done' => 0,
					);
				}
				if ( self::run_archive( $s, $deadline ) ) {
					$s['step'] = 'finalize';
				}
				break;

			case 'finalize':
				self::finalize( $s );
				$s['step'] = $s['remote'] ? 'upload' : 'retention';
				break;

			case 'upload':
				if ( self::run_upload( $s, $deadline ) ) {
					$s['step'] = 'retention';
				}
				break;

			case 'retention':
				self::retention( $s );
				self::finish( $s, 'success' );
				break;
		}
		$s['progress'] = self::progress( $s );
	}

	private static function run_archive( array &$s, $deadline ) {
		$a      = $s['archive'];
		$writer = new BKWB_Tar_Writer( $a['part'], $a['writer'] );
		$done   = false;
		try {
			if ( 'manifest' === $a['stage'] ) {
				$writer->add_string( 'bkwb-manifest.json', wp_json_encode( self::manifest( $s ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
				$a['stage'] = 'db';
			}

			if ( 'db' === $a['stage'] ) {
				if ( null === $writer->state['current'] ) {
					$writer->begin_file( 'database.sql', $s['db']['file'], (int) $s['db']['size'], time() );
				}
				if ( ! $writer->continue_file( $deadline ) ) {
					return false;
				}
				$a['stage'] = $s['scan'] ? 'files' : 'end';
			}

			if ( 'files' === $a['stage'] ) {
				if ( null !== $writer->state['current'] ) {
					if ( ! $writer->continue_file( $deadline ) ) {
						return false;
					}
					$a['files_done']++;
				}
				$root = $s['scan']['root'];
				$lh   = fopen( $s['scan']['list'], 'rb' );
				fseek( $lh, $a['list_off'] );
				while ( false !== ( $line = fgets( $lh ) ) ) {
					$rel = rtrim( $line, "\n" );
					if ( '' !== $rel ) {
						$abs = $root . '/' . rtrim( $rel, '/' );
						if ( '/' === substr( $rel, -1 ) ) {
							$writer->add_dir( 'files/' . $rel, (int) @filemtime( $abs ) );
						} else {
							$size = @filesize( $abs );
							if ( false === $size || ! is_readable( $abs ) ) {
								self::warn( $s, 'Fichier disparu pendant la sauvegarde : ' . $rel );
							} else {
								$writer->begin_file( 'files/' . $rel, $abs, $size, (int) @filemtime( $abs ), @fileperms( $abs ) & 0777 );
								$a['list_off'] = ftell( $lh );
								if ( ! $writer->continue_file( $deadline ) ) {
									fclose( $lh );
									return false;
								}
								$a['files_done']++;
							}
						}
					}
					$a['list_off'] = ftell( $lh );
					if ( $deadline && microtime( true ) >= $deadline ) {
						fclose( $lh );
						return false;
					}
				}
				fclose( $lh );
				$a['stage'] = 'end';
			}

			if ( 'end' === $a['stage'] ) {
				$writer->finish();
				$done = true;
			}
		} finally {
			$a['writer']  = $writer->close();
			$s['archive'] = $a;
		}
		return $done;
	}

	private static function manifest( array $s ) {
		global $wpdb, $wp_version;
		return array(
			'format'         => 'bk-web-backup-1',
			'plugin_version' => BKWB_VERSION,
			'site_url'       => home_url(),
			'site_slug'      => BKWB_Settings::site_slug(),
			'name'           => $s['name'],
			'created'        => $s['started'],
			'content'        => $s['content'],
			'wp_version'     => $wp_version,
			'php_version'    => PHP_VERSION,
			'table_prefix'   => $wpdb->prefix,
			'abspath'        => ABSPATH,
			'multisite'      => is_multisite(),
			'tables'         => $s['db']['tables'],
			'db_size'        => (int) $s['db']['size'],
			'files'          => $s['scan'] ? (int) $s['scan']['files'] : 0,
			'files_bytes'    => $s['scan'] ? (int) $s['scan']['bytes'] : 0,
		);
	}

	private static function finalize( array &$s ) {
		$dir   = BKWB_Storage::dir( 'archives' );
		$final = $dir . '/' . $s['name'] . '.tar.gz';
		// Déjà déplacée si la requête précédente a été coupée juste après.
		$moved = ! is_file( $s['archive']['part'] ) && is_file( $final );
		if ( ! $moved && ! @rename( $s['archive']['part'], $final ) ) {
			throw new RuntimeException( "Impossible de déplacer l'archive terminée vers " . $dir );
		}
		$meta              = self::manifest( $s );
		$meta['size']      = filesize( $final );
		$meta['finished']  = time();
		$meta['warnings']  = count( $s['warnings'] );
		file_put_contents( $dir . '/' . $s['name'] . '.json', wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$s['archive']['final'] = $final;
		$s['archive']['size']  = $meta['size'];
		@unlink( $s['db']['file'] );
		self::log( $s, 'Archive terminée : ' . size_format( $meta['size'] ) . '.' );
	}

	private static function run_upload( array &$s, $deadline ) {
		$client = BKWB_Settings::s3_client();
		if ( ! $client ) {
			$s['upload_error'] = 'Stockage OVH non configuré.';
			return true;
		}
		$file = $s['archive']['final'];
		if ( null === $s['upload'] ) {
			$size        = filesize( $file );
			$part        = max( self::PART_SIZE, (int) ceil( $size / 9000 / MB_IN_BYTES ) * MB_IN_BYTES );
			$s['upload'] = array(
				'key'       => BKWB_Settings::get( 's3_prefix' ) . $s['name'] . '.tar.gz',
				'meta_key'  => BKWB_Settings::get( 's3_prefix' ) . $s['name'] . '.json',
				'size'      => $size,
				'part_size' => $part,
				'upload_id' => null,
				'parts'     => array(),
				'next'      => 1,
				'sent'      => 0,
				'main_done' => false,
				'meta_done' => false,
				'failures'  => 0,
			);
			self::log( $s, 'Envoi vers ' . BKWB_Settings::get( 's3_bucket' ) . '/' . $s['upload']['key'] . '.' );
		}
		$u = &$s['upload'];
		try {
			if ( ! $u['main_done'] ) {
				if ( $u['size'] <= $u['part_size'] ) {
					$client->put_object( $u['key'], (string) file_get_contents( $file ), 'application/gzip' );
					$u['sent']      = $u['size'];
					$u['main_done'] = true;
				} else {
					if ( ! $u['upload_id'] ) {
						$u['upload_id'] = $client->create_multipart( $u['key'] );
					}
					$total = (int) ceil( $u['size'] / $u['part_size'] );
					$fh    = fopen( $file, 'rb' );
					while ( $u['next'] <= $total ) {
						$n = $u['next'];
						fseek( $fh, ( $n - 1 ) * $u['part_size'] );
						$u['parts'][ $n ] = $client->upload_part( $u['key'], $u['upload_id'], $n, fread( $fh, $u['part_size'] ) );
						$u['next']++;
						$u['sent']     = min( $u['size'], $n * $u['part_size'] );
						$u['failures'] = 0;
						if ( $deadline && microtime( true ) >= $deadline && $u['next'] <= $total ) {
							fclose( $fh );
							return false;
						}
					}
					fclose( $fh );
					$client->complete_multipart( $u['key'], $u['upload_id'], $u['parts'] );
					$u['main_done'] = true;
				}
			}
			if ( ! $u['meta_done'] ) {
				$meta = BKWB_Storage::dir( 'archives' ) . '/' . $s['name'] . '.json';
				$client->put_object( $u['meta_key'], (string) file_get_contents( $meta ), 'application/json' );
				$u['meta_done'] = true;
			}
			$s['uploaded'] = true;
			self::log( $s, 'Copie envoyée sur le stockage OVH.' );
			return true;
		} catch ( BKWB_S3_Exception $e ) {
			$u['failures']++;
			self::log( $s, 'Échec de l’envoi (tentative ' . $u['failures'] . ') : ' . $e->getMessage() );
			if ( 'NoSuchUpload' === $e->s3_code ) {
				$u['upload_id'] = null;
				$u['parts']     = array();
				$u['next']      = 1;
				$u['sent']      = 0;
			}
			$fatal = in_array( $e->s3_code, array( 'AccessDenied', 'InvalidAccessKeyId', 'SignatureDoesNotMatch', 'NoSuchBucket' ), true );
			if ( $fatal || $u['failures'] >= 5 ) {
				if ( $u['upload_id'] ) {
					try {
						$client->abort_multipart( $u['key'], $u['upload_id'] );
					} catch ( Exception $ignored ) {
						unset( $ignored );
					}
				}
				$s['upload_error'] = $e->getMessage();
				return true;
			}
			$s['retry_at'] = time() + 60 * $u['failures'];
			return false;
		}
	}

	private static function retention( array &$s ) {
		$keep = (int) BKWB_Settings::get( 'local_keep' );
		if ( empty( $s['uploaded'] ) ) {
			$keep = max( 1, $keep ); // Jamais supprimer la seule copie existante.
		}
		$i = 0;
		foreach ( BKWB_Storage::local_archives() as $name => $archive ) {
			if ( $i++ < $keep ) {
				continue;
			}
			BKWB_Storage::delete_archive( $name );
		}
	}

	/**
	 * Termine la tâche : historique, alerte e-mail, nettoyage.
	 */
	public static function finish( array &$s, $status ) {
		if ( ! empty( $s['finalized'] ) ) {
			return;
		}
		$s['finalized'] = true;
		if ( 'success' === $status && ! empty( $s['upload_error'] ) ) {
			$status = 'partial';
		}
		if ( 'success' === $status && ! empty( $s['remote'] ) && empty( $s['uploaded'] ) ) {
			$status = 'partial';
		}
		$entry = array(
			'type'     => 'backup',
			'status'   => $status,
			'name'     => $s['name'],
			'content'  => $s['content'],
			'trigger'  => $s['trigger'],
			'started'  => $s['started'],
			'finished' => time(),
			'size'     => isset( $s['archive']['size'] ) ? (int) $s['archive']['size'] : 0,
			'remote'   => ! empty( $s['uploaded'] ),
			'files'    => $s['scan'] ? (int) $s['scan']['files'] : 0,
			'tables'   => count( $s['db']['tables'] ),
			'error'    => '' !== $s['error'] ? $s['error'] : ( isset( $s['upload_error'] ) ? $s['upload_error'] : '' ),
			'warnings' => array_slice( $s['warnings'], 0, 20 ),
		);
		BKWB_Log::add_history( $entry );
		BKWB_Log::write( 'Sauvegarde ' . $s['name'] . ' : ' . $status . ( $entry['error'] ? ' — ' . $entry['error'] : '' ) );
		if ( 'cancelled' !== $status ) {
			BKWB_Notifier::backup_finished( $entry );
		}
		$s['status']   = 'failed' === $status ? 'failed' : ( 'cancelled' === $status ? 'cancelled' : 'done' );
		$s['result']   = $entry;
		$s['progress'] = 100;
		BKWB_Jobs::clean_work();
		delete_transient( BKWB_Admin_Helpers::REMOTE_CACHE );
		do_action( 'bkwb_backup_finished', $entry );
	}

	public static function fail( array &$s, $message ) {
		$s['error'] = $message;
		self::log( $s, 'Erreur : ' . $message );
		self::abort_upload( $s );
		if ( ! empty( $s['archive']['part'] ) ) {
			@unlink( $s['archive']['part'] );
		}
		self::finish( $s, 'failed' );
	}

	public static function cancel( array &$s ) {
		self::log( $s, 'Sauvegarde annulée.' );
		self::abort_upload( $s );
		if ( ! empty( $s['archive']['part'] ) ) {
			@unlink( $s['archive']['part'] );
		}
		self::finish( $s, 'cancelled' );
	}

	private static function abort_upload( array $s ) {
		if ( empty( $s['upload']['upload_id'] ) || ! empty( $s['upload']['main_done'] ) ) {
			return;
		}
		try {
			$client = BKWB_Settings::s3_client();
			if ( $client ) {
				$client->abort_multipart( $s['upload']['key'], $s['upload']['upload_id'] );
			}
		} catch ( Exception $ignored ) {
			unset( $ignored );
		}
	}

	private static function progress( array $s ) {
		if ( BKWB_Jobs::is_finished( $s ) ) {
			return 100;
		}
		$full = (bool) $s['scan'];
		switch ( $s['step'] ) {
			case 'db':
				$r = min( 1, $s['db']['rows_done'] / max( 1, $s['db']['rows_total'] ) );
				return (int) round( $r * ( $full ? 20 : 40 ) );
			case 'scan':
				return 22;
			case 'archive':
				$total = (int) $s['db']['size'] + ( $full ? (int) $s['scan']['bytes'] : 0 );
				$done  = isset( $s['archive']['writer']['u_off'] ) ? (int) $s['archive']['writer']['u_off'] : 0;
				$r     = min( 1, $done / max( 1, $total ) );
				return (int) round( ( $full ? 25 : 40 ) + $r * ( $full ? 55 : 30 ) );
			case 'finalize':
				return 80;
			case 'upload':
				$r = isset( $s['upload']['sent'] ) ? $s['upload']['sent'] / max( 1, $s['upload']['size'] ) : 0;
				return (int) round( 80 + $r * 18 );
			case 'retention':
				return 99;
		}
		return (int) $s['progress'];
	}

	public static function log( array &$s, $message ) {
		$s['log'][] = wp_date( 'H:i:s' ) . ' — ' . $message;
		$s['log']   = array_slice( $s['log'], -40 );
		BKWB_Log::write( '[' . $s['name'] . '] ' . $message );
	}

	public static function warn( array &$s, $message ) {
		if ( count( $s['warnings'] ) < 100 ) {
			$s['warnings'][] = $message;
		}
	}
}
