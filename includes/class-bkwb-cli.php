<?php
/**
 * Commandes WP-CLI : wp bkwb backup | status | list | restore | test-s3 | cancel.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BKWB_CLI {

	/**
	 * Lance une sauvegarde et attend la fin.
	 *
	 * ## OPTIONS
	 *
	 * [--db-only]
	 * : Sauvegarder uniquement la base de données.
	 */
	public function backup( $args, $assoc ) {
		try {
			BKWB_Runner::start_backup( 'wp-cli', ! empty( $assoc['db-only'] ) ? 'db' : 'full' );
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		$this->follow();
	}

	/**
	 * Restaure une sauvegarde.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Nom de la sauvegarde (voir wp bkwb list).
	 *
	 * [--remote]
	 * : Télécharger depuis le stockage S3 plutôt qu'utiliser la copie locale.
	 *
	 * [--what=<what>]
	 * : full (défaut), db ou files.
	 *
	 * [--yes]
	 * : Ne pas demander de confirmation.
	 */
	public function restore( $args, $assoc ) {
		WP_CLI::confirm( 'Le site va être remplacé par la sauvegarde ' . $args[0] . '. Continuer ?', $assoc );
		try {
			BKWB_Runner::start_restore( ! empty( $assoc['remote'] ) ? 'remote' : 'local', $args[0], isset( $assoc['what'] ) ? $assoc['what'] : 'full' );
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		$this->follow();
	}

	/**
	 * Affiche l'état des sauvegardes (format Maintenance Pilote).
	 */
	public function status() {
		WP_CLI::line( wp_json_encode( bkwb_get_status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Liste les sauvegardes locales et distantes.
	 */
	public function list() {
		$rows = array();
		foreach ( BKWB_Storage::local_archives() as $a ) {
			$rows[ $a['name'] ] = array(
				'name'  => $a['name'],
				'date'  => wp_date( 'Y-m-d H:i', $a['created'] ),
				'size'  => size_format( $a['size'] ),
				'local' => 'oui',
				's3'    => '',
			);
		}
		try {
			foreach ( BKWB_Admin_Helpers::remote_archives() as $a ) {
				if ( ! isset( $rows[ $a['name'] ] ) ) {
					$rows[ $a['name'] ] = array(
						'name'  => $a['name'],
						'date'  => wp_date( 'Y-m-d H:i', $a['created'] ),
						'size'  => size_format( $a['size'] ),
						'local' => '',
						's3'    => 'oui',
					);
				} else {
					$rows[ $a['name'] ]['s3'] = 'oui';
				}
			}
		} catch ( Exception $e ) {
			WP_CLI::warning( $e->getMessage() );
		}
		krsort( $rows );
		WP_CLI\Utils\format_items( 'table', array_values( $rows ), array( 'name', 'date', 'size', 'local', 's3' ) );
	}

	/**
	 * Vérifie la connexion au stockage S3 (liste + écriture d'un petit fichier de test).
	 *
	 * @subcommand test-s3
	 */
	public function test_s3() {
		try {
			WP_CLI::success( BKWB_Admin_Helpers::test_s3() );
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Annule la tâche en cours.
	 */
	public function cancel() {
		try {
			BKWB_Runner::cancel();
			WP_CLI::success( 'Tâche annulée.' );
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	private function follow() {
		$label = '';
		$s     = BKWB_Runner::run_until_done(
			function ( $s ) use ( &$label ) {
				if ( $s && $s['label'] !== $label ) {
					$label = $s['label'];
					WP_CLI::line( $s['progress'] . ' % — ' . $label );
				}
			}
		);
		if ( ! $s ) {
			WP_CLI::error( 'Tâche introuvable.' );
		}
		foreach ( $s['log'] as $line ) {
			WP_CLI::line( $line );
		}
		$status = isset( $s['result']['status'] ) ? $s['result']['status'] : $s['status'];
		if ( 'success' === $status ) {
			WP_CLI::success( 'Terminé.' );
		} elseif ( 'partial' === $status ) {
			WP_CLI::warning( 'Terminé, mais la copie distante a échoué : ' . $s['result']['error'] );
		} else {
			WP_CLI::error( 'Échec : ' . $s['error'] );
		}
	}
}
