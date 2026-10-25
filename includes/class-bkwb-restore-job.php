<?php
/**
 * Tâche de restauration, découpée en étapes :
 * téléchargement depuis S3 (si besoin) → extraction dans un dossier temporaire →
 * import de la base dans des tables temporaires (bkr_*) → mise en place des fichiers →
 * échange des tables → nettoyage.
 *
 * Le site reste intact tant que l'échange final n'a pas eu lieu. L'échange des tables est
 * fait en une seule instruction RENAME TABLE (atomique), juste après la mise en place des
 * fichiers. Le fichier wp-config.php et le dossier de ce plugin ne sont jamais remplacés.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BKWB_Restore_Job {

	const TMP = 'bkr_';
	const OLD = 'bko_';

	private static $labels = array(
		'download' => 'Téléchargement depuis le stockage OVH',
		'extract'  => 'Extraction de l’archive',
		'import'   => 'Import de la base de données',
		'move'     => 'Mise en place des fichiers',
		'swap'     => 'Activation de la base restaurée',
		'cleanup'  => 'Nettoyage',
	);

	/**
	 * @param string $source local ou remote.
	 * @param string $name   Nom de la sauvegarde (sans extension).
	 * @param string $what   full, db ou files.
	 */
	public static function create( $source, $name, $what = 'full' ) {
		global $wpdb;
		$name = basename( $name );
		$what = in_array( $what, array( 'full', 'db', 'files' ), true ) ? $what : 'full';
		if ( 'files' !== $what && ! ( $wpdb->dbh instanceof mysqli ) ) {
			throw new RuntimeException( 'La restauration de la base nécessite l’extension mysqli.' );
		}

		BKWB_Jobs::clean_work( false );
		$restore = BKWB_Storage::dir( 'restore' );
		BKWB_Storage::rrmdir( $restore . '/staging' );
		@unlink( $restore . '/database.sql' );

		$state = array(
			'type'       => 'restore',
			'status'     => 'running',
			'step'       => 'extract',
			'source'     => $source,
			'name'       => $name,
			'what'       => $what,
			'started'    => time(),
			'ticks'      => 0,
			'crashes'    => 0,
			'in_tick'    => false,
			'retry_at'   => 0,
			'progress'   => 0,
			'label'      => '',
			'log'        => array(),
			'warnings'   => array(),
			'error'      => '',
			'staging'    => $restore . '/staging',
			'sql'        => $restore . '/database.sql',
			'reader'     => array(),
			'manifest'   => null,
			'has_db'     => false,
			'files'      => 0,
			'import'     => null,
			'move'       => null,
			'failures'   => 0,
		);

		if ( 'local' === $source ) {
			$file = BKWB_Storage::dir( 'archives' ) . '/' . $name . '.tar.gz';
			if ( ! is_file( $file ) ) {
				throw new RuntimeException( 'Sauvegarde introuvable sur l’hébergement : ' . $name );
			}
			$state['archive'] = $file;
			$state['size']    = filesize( $file );
			$meta             = BKWB_Storage::read_meta( BKWB_Storage::dir( 'archives' ) . '/' . $name . '.json' );
		} else {
			$client = BKWB_Settings::s3_client();
			if ( ! $client ) {
				throw new RuntimeException( 'Stockage OVH non configuré.' );
			}
			$key  = BKWB_Settings::get( 's3_prefix' ) . $name . '.tar.gz';
			$size = $client->object_size( $key );
			if ( null === $size ) {
				throw new RuntimeException( 'Sauvegarde introuvable sur le stockage OVH : ' . $key );
			}
			$state['step']       = 'download';
			$state['remote_key'] = $key;
			$state['size']       = $size;
			$state['downloaded'] = 0;
			$state['archive']    = $restore . '/' . $name . '.tar.gz';
			@unlink( $state['archive'] );
			$meta = array();
			try {
				$meta = json_decode( $client->get_object( BKWB_Settings::get( 's3_prefix' ) . $name . '.json' ), true );
			} catch ( BKWB_S3_Exception $e ) {
				unset( $e ); // Pas de fiche descriptive : la vérification se fera à l'extraction.
			}
		}

		if ( is_array( $meta ) && $meta ) {
			self::check_manifest( $state, $meta );
			$need = (int) ( $meta['db_size'] ?? 0 ) + ( 'db' === $what ? 0 : (int) ( $meta['files_bytes'] ?? 0 ) );
			$free = BKWB_Storage::free_space();
			if ( null !== $free && $need > 0 && $free < $need * 1.1 + ( 'remote' === $source ? $state['size'] : 0 ) ) {
				throw new RuntimeException( sprintf( 'Espace disque insuffisant : %s nécessaires, %s disponibles.', size_format( $need ), size_format( $free ) ) );
			}
		}

		$state['label'] = self::$labels[ $state['step'] ];
		self::log( $state, 'Début de la restauration de ' . $name . ' (' . self::what_label( $what ) . ').' );
		BKWB_Jobs::save( $state );
		return $state;
	}

	public static function step( array &$s, $deadline ) {
		$s['label'] = self::$labels[ $s['step'] ];
		switch ( $s['step'] ) {
			case 'download':
				if ( self::run_download( $s, $deadline ) ) {
					$s['step'] = 'extract';
				}
				break;

			case 'extract':
				if ( self::run_extract( $s, $deadline ) ) {
					self::log( $s, sprintf( 'Archive extraite : %s fichiers.', number_format_i18n( $s['files'] ) ) );
					if ( 'files' !== $s['what'] && ! $s['has_db'] ) {
						throw new RuntimeException( 'Cette sauvegarde ne contient pas de base de données.' );
					}
					$s['step'] = 'files' === $s['what'] ? 'move' : 'import';
				}
				break;

			case 'import':
				if ( self::run_import( $s, $deadline ) ) {
					self::log( $s, sprintf( 'Base importée dans des tables temporaires : %d tables.', count( $s['import']['tables'] ) ) );
					$s['step'] = 'db' === $s['what'] ? 'swap' : 'move';
				}
				break;

			case 'move':
				if ( ! get_option( 'bkwb_front_maintenance' ) ) {
					update_option( 'bkwb_front_maintenance', time(), true );
				}
				if ( self::run_move( $s, $deadline ) ) {
					self::log( $s, sprintf( 'Fichiers mis en place : %s.', number_format_i18n( $s['move']['moved'] ) ) );
					$s['step'] = 'files' === $s['what'] ? 'cleanup' : 'swap';
				}
				break;

			case 'swap':
				self::swap_tables( $s );
				$s['step'] = 'cleanup';
				break;

			case 'cleanup':
				self::cleanup( $s );
				self::finish( $s, 'success' );
				break;
		}
		$s['progress'] = self::progress( $s );
	}

	private static function run_download( array &$s, $deadline ) {
		$client = BKWB_Settings::s3_client();
		if ( ! $client ) {
			throw new RuntimeException( 'Stockage OVH non configuré.' );
		}
		$fh = fopen( $s['archive'], 'c+b' );
		ftruncate( $fh, $s['downloaded'] );
		fseek( $fh, $s['downloaded'] );
		try {
			while ( $s['downloaded'] < $s['size'] ) {
				$end = min( $s['size'] - 1, $s['downloaded'] + 16 * MB_IN_BYTES - 1 );
				$n   = $client->download_range( $s['remote_key'], $s['downloaded'], $end, $fh );
				if ( $n <= 0 ) {
					throw new BKWB_S3_Exception( 'Téléchargement interrompu (aucune donnée reçue).' );
				}
				fflush( $fh );
				$s['downloaded'] += $n;
				$s['failures']    = 0;
				if ( $deadline && microtime( true ) >= $deadline && $s['downloaded'] < $s['size'] ) {
					fclose( $fh );
					return false;
				}
			}
		} catch ( BKWB_S3_Exception $e ) {
			fclose( $fh );
			$s['downloaded'] = filesize( $s['archive'] );
			$s['failures']++;
			self::log( $s, 'Échec du téléchargement (tentative ' . $s['failures'] . ') : ' . $e->getMessage() );
			if ( $s['failures'] >= 5 || in_array( $e->s3_code, array( 'AccessDenied', 'NoSuchKey', 'InvalidAccessKeyId', 'SignatureDoesNotMatch' ), true ) ) {
				throw $e;
			}
			$s['retry_at'] = time() + 30 * $s['failures'];
			return false;
		}
		fclose( $fh );
		self::log( $s, 'Archive téléchargée : ' . size_format( $s['size'] ) . '.' );
		return true;
	}

	private static function run_extract( array &$s, $deadline ) {
		$handler = new BKWB_Restore_Extractor( $s );
		$reader  = new BKWB_Tar_Reader( $s['archive'], $s['reader'] );
		try {
			$done = $reader->run( $handler, $deadline );
		} finally {
			$handler->close();
			$s['reader'] = $reader->state;
		}
		return $done;
	}

	private static function run_import( array &$s, $deadline ) {
		global $wpdb;
		if ( null === $s['import'] ) {
			self::drop_prefixed( self::TMP );
			$s['import'] = array();
		}
		$importer = new BKWB_Sql_Importer( $wpdb->dbh, $s['sql'], $s['import'], self::TMP );
		try {
			$done = $importer->run( $deadline );
		} finally {
			$s['import'] = $importer->state;
			// Remet la connexion de WordPress dans son état normal.
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
			$wpdb->query( 'SET time_zone = @@global.time_zone' );
			$wpdb->set_charset( $wpdb->dbh );
			$wpdb->set_sql_mode();
		}
		return $done;
	}

	private static function run_move( array &$s, $deadline ) {
		if ( null === $s['move'] ) {
			$s['move'] = array(
				'stack'  => array( '' ),
				'moved'  => 0,
				'failed' => 0,
			);
		}
		$root = rtrim( ABSPATH, '/\\' );
		while ( $s['move']['stack'] ) {
			$rel = array_pop( $s['move']['stack'] );
			$src = $s['staging'] . ( '' === $rel ? '' : '/' . $rel );
			$dst = $root . ( '' === $rel ? '' : '/' . $rel );
			$dh  = @opendir( $src );
			if ( ! $dh ) {
				continue;
			}
			while ( false !== ( $item = readdir( $dh ) ) ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$from = $src . '/' . $item;
				$to   = $dst . '/' . $item;
				$r    = '' === $rel ? $item : $rel . '/' . $item;
				if ( is_dir( $from ) ) {
					if ( ! is_dir( $to ) && ! @mkdir( $to, 0755, true ) ) {
						self::warn( $s, 'Dossier impossible à créer : ' . $r );
						$s['move']['failed']++;
						continue;
					}
					$s['move']['stack'][] = $r;
					continue;
				}
				if ( is_dir( $to ) ) {
					self::warn( $s, 'Un dossier porte le nom du fichier à restaurer : ' . $r );
					$s['move']['failed']++;
					continue;
				}
				if ( ! @rename( $from, $to ) ) {
					if ( @copy( $from, $to ) ) {
						@touch( $to, (int) @filemtime( $from ) );
						@unlink( $from );
					} else {
						self::warn( $s, 'Fichier impossible à remplacer : ' . $r );
						$s['move']['failed']++;
						continue;
					}
				}
				$s['move']['moved']++;
			}
			closedir( $dh );
			if ( $deadline && microtime( true ) >= $deadline && $s['move']['stack'] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Remplace les tables du site par les tables importées, en une seule opération.
	 */
	private static function swap_tables( array &$s ) {
		global $wpdb;
		$keep     = self::own_options();
		$tables   = $s['import']['tables'];
		$existing = (array) $wpdb->get_col( 'SHOW TABLES' );
		self::drop_prefixed( self::OLD );

		$pairs = array();
		$old   = array();
		foreach ( $tables as $t ) {
			if ( ! in_array( self::TMP . $t, $existing, true ) ) {
				continue;
			}
			if ( in_array( $t, $existing, true ) ) {
				$pairs[] = BKWB_Db_Exporter::quote_name( $t ) . ' TO ' . BKWB_Db_Exporter::quote_name( self::OLD . $t );
				$old[]   = self::OLD . $t;
			}
			$pairs[] = BKWB_Db_Exporter::quote_name( self::TMP . $t ) . ' TO ' . BKWB_Db_Exporter::quote_name( $t );
		}
		if ( ! $pairs ) {
			throw new RuntimeException( 'Aucune table importée à activer.' );
		}
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
		if ( false === $wpdb->query( 'RENAME TABLE ' . implode( ', ', $pairs ) ) ) {
			throw new RuntimeException( 'Échange des tables impossible : ' . $wpdb->last_error );
		}
		foreach ( $old as $o ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . BKWB_Db_Exporter::quote_name( $o ) );
		}
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
		self::log( $s, 'Base de données restaurée.' );

		// La base restaurée contient les anciens réglages du plugin (ou aucun) : on remet les actuels.
		wp_cache_flush();
		self::restore_own_options( $keep );
	}

	private static function own_options() {
		return array(
			'settings' => get_option( BKWB_Settings::OPTION, array() ),
			'history'  => get_option( BKWB_Log::HISTORY, array() ),
			'dir'      => get_option( BKWB_Storage::OPTION ),
			'token'    => get_option( 'bkwb_tick_token' ),
		);
	}

	private static function restore_own_options( array $keep ) {
		update_option( BKWB_Settings::OPTION, $keep['settings'], false );
		update_option( BKWB_Log::HISTORY, $keep['history'], false );
		if ( $keep['dir'] ) {
			update_option( BKWB_Storage::OPTION, $keep['dir'], true );
		}
		if ( $keep['token'] ) {
			update_option( 'bkwb_tick_token', $keep['token'], false );
		}
		$plugin = plugin_basename( BKWB_FILE );
		$active = (array) get_option( 'active_plugins', array() );
		if ( ! in_array( $plugin, $active, true ) ) {
			$active[] = $plugin;
			update_option( 'active_plugins', $active );
		}
		update_option( 'bkwb_front_maintenance', time(), true );
		BKWB_Scheduler::reschedule();
	}

	private static function drop_prefixed( $prefix ) {
		global $wpdb;
		$like = $wpdb->esc_like( $prefix ) . '%';
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) as $t ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . BKWB_Db_Exporter::quote_name( $t ) );
		}
	}

	private static function cleanup( array &$s ) {
		BKWB_Storage::rrmdir( $s['staging'] );
		@unlink( $s['sql'] );
		if ( 'remote' === $s['source'] ) {
			@unlink( $s['archive'] );
		}
		delete_option( 'bkwb_front_maintenance' );
	}

	public static function finish( array &$s, $status ) {
		if ( ! empty( $s['finalized'] ) ) {
			return;
		}
		$s['finalized'] = true;
		$entry          = array(
			'type'     => 'restore',
			'status'   => $status,
			'name'     => $s['name'],
			'content'  => $s['what'],
			'trigger'  => 'manual',
			'started'  => $s['started'],
			'finished' => time(),
			'size'     => (int) $s['size'],
			'remote'   => 'remote' === $s['source'],
			'files'    => isset( $s['move']['moved'] ) ? (int) $s['move']['moved'] : 0,
			'tables'   => isset( $s['import']['tables'] ) ? count( $s['import']['tables'] ) : 0,
			'error'    => $s['error'],
			'warnings' => array_slice( $s['warnings'], 0, 20 ),
		);
		BKWB_Log::add_history( $entry );
		BKWB_Log::write( 'Restauration ' . $s['name'] . ' : ' . $status . ( $s['error'] ? ' — ' . $s['error'] : '' ) );
		if ( 'cancelled' !== $status ) {
			BKWB_Notifier::restore_finished( $entry );
		}
		$s['status']   = 'success' === $status ? 'done' : $status;
		$s['result']   = $entry;
		$s['progress'] = 100;
		BKWB_Jobs::clean_work();
		do_action( 'bkwb_restore_finished', $entry );
	}

	public static function fail( array &$s, $message ) {
		$s['error'] = $message;
		self::log( $s, 'Erreur : ' . $message );
		self::abandon( $s );
		self::finish( $s, 'failed' );
	}

	/**
	 * Annulation possible tant que le site n'a pas commencé à être modifié.
	 */
	public static function can_cancel( array $s ) {
		return in_array( $s['step'], array( 'download', 'extract', 'import' ), true );
	}

	public static function cancel( array &$s ) {
		self::log( $s, 'Restauration annulée.' );
		self::abandon( $s );
		self::finish( $s, 'cancelled' );
	}

	private static function abandon( array $s ) {
		if ( in_array( $s['step'], array( 'download', 'extract', 'import', 'move' ), true ) ) {
			self::drop_prefixed( self::TMP );
		}
		BKWB_Storage::rrmdir( $s['staging'] );
		@unlink( $s['sql'] );
		if ( 'remote' === $s['source'] && ! empty( $s['archive'] ) ) {
			@unlink( $s['archive'] );
		}
		delete_option( 'bkwb_front_maintenance' );
	}

	/**
	 * Vérifie que la sauvegarde est compatible avec ce site.
	 */
	public static function check_manifest( array &$s, array $meta ) {
		global $wpdb;
		$s['manifest'] = $meta;
		if ( 'files' !== $s['what'] && isset( $meta['table_prefix'] ) && $meta['table_prefix'] !== $wpdb->prefix ) {
			throw new RuntimeException(
				sprintf(
					'Le préfixe des tables de la sauvegarde (%s) est différent de celui du site (%s) : restauration de la base impossible depuis l’admin.',
					$meta['table_prefix'],
					$wpdb->prefix
				)
			);
		}
		if ( ! empty( $meta['multisite'] ) !== is_multisite() ) {
			throw new RuntimeException( 'La sauvegarde et le site ne sont pas du même type (multisite / site simple).' );
		}
	}

	/**
	 * Affiche une page de maintenance aux visiteurs pendant la mise en place des fichiers.
	 */
	public static function init_front_maintenance() {
		add_action(
			'init',
			function () {
				if ( ! get_option( 'bkwb_front_maintenance' ) ) {
					return;
				}
				// Sécurité : si la restauration s'est arrêtée sans nettoyer, on lève la maintenance après une heure.
				if ( time() - (int) get_option( 'bkwb_front_maintenance' ) > HOUR_IN_SECONDS && ! BKWB_Jobs::current() ) {
					delete_option( 'bkwb_front_maintenance' );
					return;
				}
				if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI )
					|| ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] )
					|| current_user_can( 'manage_options' ) ) {
					return;
				}
				header( 'Retry-After: 300' );
				wp_die(
					'<h1>Maintenance en cours</h1><p>Le site est en cours de restauration. Merci de revenir dans quelques minutes.</p>',
					'Maintenance en cours',
					array( 'response' => 503 )
				);
			},
			0
		);
	}

	private static function progress( array $s ) {
		if ( BKWB_Jobs::is_finished( $s ) ) {
			return 100;
		}
		switch ( $s['step'] ) {
			case 'download':
				return (int) round( 20 * $s['downloaded'] / max( 1, $s['size'] ) );
			case 'extract':
				$base = 'remote' === $s['source'] ? 20 : 0;
				$c    = isset( $s['reader']['m_c'] ) ? $s['reader']['m_c'] : 0;
				return (int) round( $base + ( 50 - $base ) * $c / max( 1, $s['size'] ) );
			case 'import':
				$size = @filesize( $s['sql'] );
				$off  = isset( $s['import']['offset'] ) ? $s['import']['offset'] : 0;
				return (int) round( 50 + 30 * $off / max( 1, (int) $size ) );
			case 'move':
				$moved = isset( $s['move']['moved'] ) ? $s['move']['moved'] : 0;
				return (int) round( 80 + 15 * $moved / max( 1, $s['files'] ) );
			case 'swap':
				return 96;
			case 'cleanup':
				return 99;
		}
		return (int) $s['progress'];
	}

	public static function what_label( $what ) {
		$labels = array(
			'full'  => 'fichiers et base de données',
			'db'    => 'base de données seule',
			'files' => 'fichiers seuls',
		);
		return isset( $labels[ $what ] ) ? $labels[ $what ] : $what;
	}

	public static function log( array &$s, $message ) {
		$s['log'][] = wp_date( 'H:i:s' ) . ' — ' . $message;
		$s['log']   = array_slice( $s['log'], -40 );
		BKWB_Log::write( '[restauration ' . $s['name'] . '] ' . $message );
	}

	public static function warn( array &$s, $message ) {
		if ( count( $s['warnings'] ) < 100 ) {
			$s['warnings'][] = $message;
		}
	}
}

/**
 * Reçoit les entrées de l'archive et les écrit dans le dossier temporaire de restauration.
 */
class BKWB_Restore_Extractor {

	/** @var bool Lu par BKWB_Tar_Reader : arrête la lecture (base seule, déjà extraite). */
	public $stop = false;

	private $s;
	private $fh   = null;
	private $path = null;
	private $mode = null; // manifest, sql, file.
	private $buf  = '';
	private $skip_prefixes;

	public function __construct( array &$state ) {
		$this->s = &$state;
		$root    = wp_normalize_path( rtrim( ABSPATH, '/\\' ) ) . '/';
		$plugin  = wp_normalize_path( BKWB_DIR ) . '/';
		$this->skip_prefixes = array();
		if ( 0 === strpos( $plugin, $root ) ) {
			$this->skip_prefixes[] = substr( $plugin, strlen( $root ) );
		}
		$base = wp_normalize_path( BKWB_Storage::base_dir() ) . '/';
		if ( 0 === strpos( $base, $root ) ) {
			$this->skip_prefixes[] = substr( $base, strlen( $root ) );
		}
	}

	public function start( array $e ) {
		$name = $e['name'];
		if ( 'bkwb-manifest.json' === $name ) {
			$this->mode = 'manifest';
			$this->buf  = '';
			return true;
		}
		if ( 'database.sql' === $name ) {
			if ( 'files' === $this->s['what'] ) {
				return false;
			}
			$this->s['has_db'] = true;
			return $this->open( $this->s['sql'], 'sql', 0 );
		}
		if ( 0 !== strpos( $name, 'files/' ) || 'db' === $this->s['what'] ) {
			return false;
		}
		$rel = $this->safe_rel( substr( $name, 6 ) );
		if ( null === $rel ) {
			return false;
		}
		$target = $this->s['staging'] . '/' . $rel;
		if ( '5' === $e['type'] ) {
			wp_mkdir_p( rtrim( $target, '/' ) );
			return false;
		}
		wp_mkdir_p( dirname( $target ) );
		$this->s['files']++;
		return $this->open( $target, 'file', 0 );
	}

	public function resume( array $e ) {
		$name = $e['name'];
		if ( 'bkwb-manifest.json' === $name ) {
			// Coupure au milieu du manifeste (rarissime) : on ignore la fin, la fiche descriptive
			// a déjà servi aux vérifications au lancement de la restauration.
			$this->mode = 'skip';
			return;
		}
		if ( 'database.sql' === $name ) {
			$this->open( $this->s['sql'], 'sql', $e['done'] );
			return;
		}
		$rel = $this->safe_rel( substr( $name, 6 ) );
		$this->open( $this->s['staging'] . '/' . $rel, 'file', $e['done'] );
	}

	public function data( $bytes ) {
		if ( 'manifest' === $this->mode ) {
			$this->buf .= $bytes;
			return;
		}
		if ( 'skip' === $this->mode ) {
			return;
		}
		if ( $this->fh && strlen( $bytes ) !== fwrite( $this->fh, $bytes ) ) {
			throw new RuntimeException( 'Écriture impossible pendant l’extraction (espace disque plein ?) : ' . $this->path );
		}
	}

	public function end( array $e ) {
		if ( 'skip' === $this->mode ) {
			$this->mode = null;
			return;
		}
		if ( 'manifest' === $this->mode ) {
			$meta = json_decode( $this->buf, true );
			if ( is_array( $meta ) ) {
				BKWB_Restore_Job::check_manifest( $this->s, $meta );
			}
			$this->mode = null;
			return;
		}
		$this->close();
		if ( 'file' === $this->mode && $this->path && ! empty( $e['mtime'] ) ) {
			@touch( $this->path, (int) $e['mtime'] );
		}
		if ( 'sql' === $this->mode && 'db' === $this->s['what'] ) {
			$this->stop = true; // Base seule : inutile de lire le reste de l'archive.
		}
		$this->mode = null;
		$this->path = null;
	}

	public function close() {
		if ( $this->fh ) {
			fclose( $this->fh );
			$this->fh = null;
		}
	}

	private function open( $path, $mode, $offset ) {
		$this->close();
		$fh = @fopen( $path, 'c+b' );
		if ( ! $fh ) {
			throw new RuntimeException( 'Impossible de créer le fichier : ' . $path );
		}
		ftruncate( $fh, $offset );
		fseek( $fh, $offset );
		$this->fh   = $fh;
		$this->path = $path;
		$this->mode = $mode;
		return true;
	}

	/**
	 * Chemin relatif sûr, ou null si l'entrée doit être ignorée.
	 */
	private function safe_rel( $rel ) {
		$rel = ltrim( str_replace( '\\', '/', $rel ), '/' );
		if ( '' === $rel || preg_match( '#(^|/)\.\.(/|$)#', $rel ) ) {
			return null;
		}
		$clean = rtrim( $rel, '/' );
		if ( 'wp-config.php' === $clean || '.maintenance' === $clean ) {
			return null;
		}
		foreach ( $this->skip_prefixes as $p ) {
			if ( 0 === strpos( $clean . '/', $p ) ) {
				return null;
			}
		}
		return $rel;
	}
}
