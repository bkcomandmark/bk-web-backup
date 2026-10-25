<?php
/**
 * Point d'entrée pour la tâche cron du serveur (cPanel o2switch → Tâches Cron).
 *
 * Commande à programmer toutes les 15 minutes :
 *   php /home/IDENTIFIANT/DOSSIER-DU-SITE/wp-content/plugins/bk-web-backup/bin/cron.php
 *
 * Exécute wp-cron.php (tâches arrivées à échéance, dont la sauvegarde planifiée), puis fait
 * avancer jusqu'au bout la tâche en cours, sans limite de temps.
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( 'Accès refusé.' );
}

// Remonte les dossiers jusqu'à trouver la racine de WordPress. On part du chemin tel qu'il a été
// tapé dans la commande (qui garde les liens symboliques), puis du chemin réel du fichier.
$bkwb_root   = null;
$bkwb_starts = array( __DIR__ );
if ( ! empty( $_SERVER['SCRIPT_FILENAME'] ) ) {
	array_unshift( $bkwb_starts, dirname( $_SERVER['SCRIPT_FILENAME'] ) );
}
foreach ( $bkwb_starts as $bkwb_dir ) {
	for ( $bkwb_i = 0; $bkwb_i < 8 && ! $bkwb_root; $bkwb_i++ ) {
		$bkwb_dir = dirname( $bkwb_dir );
		if ( is_file( $bkwb_dir . '/wp-load.php' ) && is_file( $bkwb_dir . '/wp-cron.php' ) ) {
			$bkwb_root = $bkwb_dir;
		}
	}
}
if ( ! $bkwb_root ) {
	fwrite( STDERR, "Racine de WordPress introuvable.\n" );
	exit( 1 );
}

// wp-cron.php se termine par die() : la suite du travail se fait à l'arrêt du script.
register_shutdown_function(
	function () {
		if ( ! class_exists( 'BKWB_Runner' ) ) {
			fwrite( STDERR, "BK WEB Backup n'est pas activé sur ce site.\n" );
			return;
		}
		BKWB_Scheduler::mark_cron_seen();
		if ( BKWB_Jobs::current() ) {
			BKWB_Runner::run_until_done();
		}
	}
);

chdir( $bkwb_root );
require $bkwb_root . '/wp-cron.php';
