<?php
/**
 * Mises à jour depuis GitHub (releases du dépôt BKWB_GITHUB_REPO), sur le modèle de Maintenance Pilote.
 * Chaque release doit contenir un fichier bk-web-backup.zip ou bk-web-backup-X.Y.Z.zip
 * (dossier bk-web-backup/ à la racine).
 * Tag : v1.0.0, v1.1.0…
 *
 * La mise à jour automatique est activée, sauf pendant une sauvegarde ou une restauration :
 * remplacer les fichiers du plugin au milieu d'une tâche pourrait la casser.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BKWB_Updater {

	const REPO      = 'bkcomandmark/bk-web-backup';
	const SLUG      = 'bk-web-backup';
	const TRANSIENT = 'bkwb_github_latest';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_source' ), 10, 4 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 10, 2 );
		add_action(
			'upgrader_process_complete',
			function () {
				delete_site_transient( self::TRANSIENT );
			}
		);
	}

	/**
	 * Dernière version publiée sur GitHub (mise en cache 12 h, 1 h en cas d'échec).
	 */
	public static function latest( $force = false ) {
		$cached = get_site_transient( self::TRANSIENT );
		if ( ! $force && is_array( $cached ) ) {
			return $cached;
		}
		$r    = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'BKWebBackup/' . BKWB_VERSION . '; ' . home_url( '/' ),
				),
			)
		);
		$info = array(
			'ok'      => false,
			'checked' => time(),
		);
		if ( is_wp_error( $r ) ) {
			$info['error'] = 'GitHub injoignable : ' . $r->get_error_message();
		} else {
			$code = (int) wp_remote_retrieve_response_code( $r );
			$data = json_decode( (string) wp_remote_retrieve_body( $r ), true );
			if ( 200 === $code && is_array( $data ) && ! empty( $data['tag_name'] ) ) {
				// Fichier .zip joint à la release (bk-web-backup.zip de préférence, ou bk-web-backup-X.Y.Z.zip).
				$package = null;
				foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
					if ( isset( $asset['name'], $asset['browser_download_url'] ) && preg_match( '/^bk-web-backup.*\.zip$/i', $asset['name'] ) ) {
						$package = $asset['browser_download_url'];
						if ( 'bk-web-backup.zip' === $asset['name'] ) {
							break;
						}
					}
				}
				$info = array(
					'ok'        => (bool) $package,
					'checked'   => time(),
					'version'   => ltrim( (string) $data['tag_name'], 'vV' ),
					'package'   => $package,
					'notes'     => (string) ( $data['body'] ?? '' ),
					'url'       => (string) ( $data['html_url'] ?? 'https://github.com/' . self::REPO ),
					'published' => (string) ( $data['published_at'] ?? '' ),
				);
				if ( ! $package ) {
					$info['error'] = 'La dernière release ne contient pas de fichier bk-web-backup*.zip.';
				}
			} elseif ( 404 === $code ) {
				$info['error'] = 'Aucune release publiée, ou dépôt privé ou introuvable (' . self::REPO . ').';
			} elseif ( 403 === $code || 429 === $code ) {
				$info['error'] = 'Limite de requêtes GitHub atteinte, nouvel essai dans une heure.';
			} else {
				$info['error'] = 'Réponse inattendue de GitHub (code ' . $code . ').';
			}
		}
		set_site_transient( self::TRANSIENT, $info, $info['ok'] ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $info;
	}

	private static function update_object( $info ) {
		return (object) array(
			'id'            => 'github.com/' . self::REPO,
			'slug'          => self::SLUG,
			'plugin'        => plugin_basename( BKWB_FILE ),
			'new_version'   => $info['version'],
			'url'           => $info['url'],
			'package'       => $info['package'],
			'requires_php'  => '7.4',
			'icons'         => array(),
			'banners'       => array(),
			'compatibility' => new stdClass(),
		);
	}

	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$info = self::latest();
		$base = plugin_basename( BKWB_FILE );
		if ( ! empty( $info['ok'] ) && version_compare( $info['version'], BKWB_VERSION, '>' ) ) {
			$transient->response[ $base ] = self::update_object( $info );
			unset( $transient->no_update[ $base ] );
		} else {
			$obj = ! empty( $info['ok'] ) ? self::update_object( $info ) : (object) array(
				'slug'    => self::SLUG,
				'plugin'  => $base,
				'url'     => 'https://github.com/' . self::REPO,
				'package' => '',
			);
			$obj->new_version              = BKWB_VERSION;
			$transient->no_update[ $base ] = $obj;
			unset( $transient->response[ $base ] );
		}
		return $transient;
	}

	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$info = self::latest();
		return (object) array(
			'name'          => 'BK WEB Backup',
			'slug'          => self::SLUG,
			'version'       => ! empty( $info['ok'] ) ? $info['version'] : BKWB_VERSION,
			'author'        => 'BK COM AND MARK',
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => $info['package'] ?? '',
			'last_updated'  => $info['published'] ?? '',
			'requires_php'  => '7.4',
			'sections'      => array(
				'changelog' => ! empty( $info['notes'] ) ? wpautop( esc_html( $info['notes'] ) ) : 'Voir les releases sur GitHub.',
			),
		);
	}

	/**
	 * Le .zip doit installer le dossier bk-web-backup/ (renommage de sécurité si besoin).
	 */
	public static function fix_source( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || plugin_basename( BKWB_FILE ) !== $hook_extra['plugin'] ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . self::SLUG . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( $source, $wanted, true ) ) {
			return $wanted;
		}
		return new WP_Error( 'bkwb_source', 'Archive de mise à jour inattendue : dossier bk-web-backup introuvable.' );
	}

	/**
	 * Mise à jour automatique de BK WEB Backup (désactivable par BKWB_DISABLE_AUTO_UPDATE),
	 * repoussée tant qu'une tâche est en cours.
	 */
	public static function auto_update( $update, $item ) {
		if ( ! isset( $item->plugin ) || plugin_basename( BKWB_FILE ) !== $item->plugin ) {
			return $update;
		}
		if ( defined( 'BKWB_DISABLE_AUTO_UPDATE' ) && BKWB_DISABLE_AUTO_UPDATE ) {
			return false;
		}
		return ! BKWB_Jobs::current();
	}
}
