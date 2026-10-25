<?php
/**
 * Plugin Name:       BK WEB Backup
 * Description:       Sauvegardes automatiques (base de données + fichiers) vers un stockage S3 (OVHcloud), avec copie locale hors du site, alertes par e-mail, restauration depuis l'admin et script de secours.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            BK COM AND MARK
 * Update URI:        https://github.com/bkcomandmark/bk-web-backup
 * License:           GPL-2.0-or-later
 * Text Domain:       bk-web-backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BKWB_VERSION', '1.0.0' );
define( 'BKWB_FILE', __FILE__ );
define( 'BKWB_DIR', __DIR__ );

// Bibliothèques sans dépendance à WordPress (réutilisées par le script de secours).
require_once BKWB_DIR . '/lib/class-bkwb-tar-writer.php';
require_once BKWB_DIR . '/lib/class-bkwb-tar-reader.php';
require_once BKWB_DIR . '/lib/class-bkwb-sql-importer.php';
require_once BKWB_DIR . '/lib/class-bkwb-s3-client.php';

require_once BKWB_DIR . '/includes/class-bkwb-settings.php';
require_once BKWB_DIR . '/includes/class-bkwb-storage.php';
require_once BKWB_DIR . '/includes/class-bkwb-log.php';
require_once BKWB_DIR . '/includes/class-bkwb-jobs.php';
require_once BKWB_DIR . '/includes/class-bkwb-db-exporter.php';
require_once BKWB_DIR . '/includes/class-bkwb-file-scanner.php';
require_once BKWB_DIR . '/includes/class-bkwb-backup-job.php';
require_once BKWB_DIR . '/includes/class-bkwb-restore-job.php';
require_once BKWB_DIR . '/includes/class-bkwb-runner.php';
require_once BKWB_DIR . '/includes/class-bkwb-scheduler.php';
require_once BKWB_DIR . '/includes/class-bkwb-notifier.php';
require_once BKWB_DIR . '/includes/class-bkwb-rescue-builder.php';
require_once BKWB_DIR . '/includes/class-bkwb-admin-helpers.php';
require_once BKWB_DIR . '/includes/class-bkwb-updater.php';
require_once BKWB_DIR . '/includes/functions.php';

if ( is_admin() ) {
	require_once BKWB_DIR . '/includes/class-bkwb-admin.php';
	BKWB_Admin::init();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once BKWB_DIR . '/includes/class-bkwb-cli.php';
	WP_CLI::add_command( 'bkwb', 'BKWB_CLI' );
}

BKWB_Runner::init();
BKWB_Scheduler::init();
BKWB_Updater::init();
BKWB_Restore_Job::init_front_maintenance();

register_activation_hook( __FILE__, array( 'BKWB_Scheduler', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BKWB_Scheduler', 'deactivate' ) );
