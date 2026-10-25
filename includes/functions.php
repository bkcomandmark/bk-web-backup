<?php
/**
 * Fonctions publiques, notamment pour Maintenance Pilote.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * État des sauvegardes, dans le même format que l'action « sauvegardes » de Maintenance Pilote.
 *
 * Utilisation dans Maintenance Pilote :
 *   if ( function_exists( 'bkwb_get_status' ) ) { return bkwb_get_status(); }
 *
 * @return array
 */
function bkwb_get_status() {
	$history = BKWB_Log::history();
	$backups = array_values(
		array_filter(
			$history,
			function ( $h ) {
				return 'backup' === $h['type'];
			}
		)
	);

	$last_ok = BKWB_Log::last_success();
	$last    = $backups ? $backups[0] : null;
	$since   = time() - 30 * DAY_IN_SECONDS;
	$ok_30   = 0;
	$ko_30   = 0;
	foreach ( $backups as $b ) {
		if ( $b['started'] < $since ) {
			continue;
		}
		if ( 'success' === $b['status'] ) {
			$ok_30++;
		} elseif ( in_array( $b['status'], array( 'failed', 'partial' ), true ) ) {
			$ko_30++;
		}
	}

	$statut = function ( $b ) {
		$labels = array(
			'success'   => 'reussie',
			'partial'   => 'partielle (copie distante manquante)',
			'failed'    => 'echouee',
			'cancelled' => 'annulee',
		);
		$s = isset( $labels[ $b['status'] ] ) ? $labels[ $b['status'] ] : $b['status'];
		return $b['error'] && 'success' !== $b['status'] ? $s . ' : ' . $b['error'] : $s;
	};

	$job    = BKWB_Jobs::current();
	$next   = wp_next_scheduled( BKWB_Scheduler::HOOK );
	$remote = BKWB_Settings::s3_ready();

	$historique = array();
	foreach ( array_slice( $backups, 0, 20 ) as $b ) {
		$historique[] = array(
			'date'     => gmdate( 'c', (int) $b['started'] ),
			'statut'   => $statut( $b ),
			'taille'   => (int) $b['size'],
			'distante' => ! empty( $b['remote'] ),
			'contenu'  => 'db' === $b['content'] ? 'db' : 'files+db',
		);
	}

	$last_error = null;
	if ( $last && 'success' !== $last['status'] && $last['error'] ) {
		$last_error = $last['error'];
	}

	return array(
		'outil'                       => 'BK WEB Backup',
		'version'                     => BKWB_VERSION,
		'derniere_reussie'            => $last_ok ? gmdate( 'c', (int) $last_ok['finished'] ) : null,
		'age_heures_derniere_reussie' => $last_ok ? round( ( time() - (int) $last_ok['finished'] ) / 3600, 1 ) : null,
		'derniere_tentative'          => $last ? array(
			'date'   => gmdate( 'c', (int) $last['started'] ),
			'statut' => $statut( $last ),
		) : null,
		'reussites_30j'               => $ok_30,
		'echecs_30j'                  => $ko_30,
		'historique'                  => $historique,
		'planification'               => array(
			'active'    => 'off' !== BKWB_Settings::get( 'schedule' ),
			'frequence' => BKWB_Settings::get( 'schedule' ),
			'contenu'   => 'db' === BKWB_Settings::get( 'content' ) ? 'db' : 'files+db',
			'prochaine' => $next ? gmdate( 'c', $next ) : null,
			'cron_serveur_actif' => BKWB_Scheduler::cron_recently_seen(),
		),
		'stockage_distant'            => array(
			'configure'                => $remote,
			'types'                    => $remote ? array( 's3' ) : array(),
			'utilise_par_planification' => $remote,
			'details'                  => $remote ? array(
				array(
					'type'     => 's3',
					'endpoint' => (string) wp_parse_url( BKWB_Settings::get( 's3_endpoint' ), PHP_URL_HOST ),
					'bucket'   => BKWB_Settings::get( 's3_bucket' ),
					'dossier'  => BKWB_Settings::get( 's3_prefix' ),
				),
			) : array(),
		),
		'sauvegardes_conservees'      => count( BKWB_Storage::local_archives() ),
		'en_cours'                    => $job ? array(
			'type'        => $job['type'],
			'etape'       => $job['label'],
			'progression' => (int) $job['progress'],
		) : null,
		'derniere_erreur'             => $last_error,
		'autres_extensions_sauvegarde' => bkwb_other_backup_plugins(),
		'verifie_le'                  => gmdate( 'c' ),
	);
}

/**
 * Lance une sauvegarde en arrière-plan (utilisé par Maintenance Pilote, par exemple avant une
 * mise à jour du cœur de WordPress). N'attend pas la fin : suivre l'avancement avec
 * bkwb_get_status() (clé « en_cours », puis « derniere_reussie »).
 *
 * @param string $content full (fichiers + base) ou db (base seule).
 * @param string $trigger Origine affichée dans l'historique.
 * @return array|WP_Error Nom de la sauvegarde lancée, ou erreur (ex. une tâche est déjà en cours).
 */
function bkwb_start_backup( $content = 'full', $trigger = 'maintenance-pilote' ) {
	try {
		$s = BKWB_Runner::start_backup( sanitize_text_field( $trigger ), 'db' === $content ? 'db' : 'full' );
	} catch ( Exception $e ) {
		return new WP_Error( 'bkwb_start', $e->getMessage() );
	}
	return array(
		'nom'      => $s['name'],
		'contenu'  => 'db' === $s['content'] ? 'db' : 'files+db',
		'demarree' => gmdate( 'c', $s['started'] ),
		'distante' => (bool) $s['remote'],
	);
}

/**
 * Autres extensions de sauvegarde actives (pour signaler les doublons).
 */
function bkwb_other_backup_plugins() {
	$known = array(
		'wpvivid-backuprestore/wpvivid-backuprestore.php' => 'WPvivid',
		'updraftplus/updraftplus.php'                    => 'UpdraftPlus',
		'backwpup/backwpup.php'                          => 'BackWPup',
		'duplicator/duplicator.php'                      => 'Duplicator',
		'all-in-one-wp-migration/all-in-one-wp-migration.php' => 'All-in-One WP Migration',
		'jetpack-backup/jetpack-backup.php'              => 'Jetpack VaultPress Backup',
	);
	$active = (array) get_option( 'active_plugins', array() );
	$found  = array();
	foreach ( $known as $file => $label ) {
		if ( in_array( $file, $active, true ) ) {
			$found[] = $label;
		}
	}
	return $found;
}
