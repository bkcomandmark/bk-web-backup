<?php
/**
 * Désinstallation : supprime les réglages et l'historique. Les archives déjà créées (sur
 * l'hébergement et sur OVH) sont volontairement conservées.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'bkwb_settings', 'bkwb_history', 'bkwb_local_dir', 'bkwb_tick_token', 'bkwb_cron_seen', 'bkwb_installed', 'bkwb_late_alert', 'bkwb_front_maintenance' ) as $bkwb_option ) {
	delete_option( $bkwb_option );
}
delete_transient( 'bkwb_remote_list' );
wp_clear_scheduled_hook( 'bkwb_scheduled_backup' );
wp_clear_scheduled_hook( 'bkwb_watchdog' );
wp_clear_scheduled_hook( 'bkwb_tick' );
