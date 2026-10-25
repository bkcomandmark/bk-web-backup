<?php
/**
 * Planification des sauvegardes (WP-Cron) et surveillance des retards.
 *
 * Sur o2switch, pour que les sauvegardes partent à l'heure même sans visite et sans limite de
 * temps, on ajoute dans cPanel une tâche cron qui lance bin/cron.php toutes les 15 minutes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BKWB_Scheduler {

	const HOOK      = 'bkwb_scheduled_backup';
	const WATCHDOG  = 'bkwb_watchdog';
	const CRON_SEEN = 'bkwb_cron_seen';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_scheduled' ) );
		add_action( self::WATCHDOG, array( __CLASS__, 'watchdog' ) );
		add_action( 'init', array( __CLASS__, 'ensure_events' ) );
	}

	public static function activate() {
		add_option( 'bkwb_installed', time(), '', false );
		self::reschedule();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::WATCHDOG );
		wp_clear_scheduled_hook( BKWB_Runner::TICK_HOOK );
	}

	/**
	 * Recrée les événements s'ils ont disparu (ex. après une restauration ou un nettoyage de WP-Cron).
	 */
	public static function ensure_events() {
		if ( ! wp_next_scheduled( self::WATCHDOG ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::WATCHDOG );
		}
		if ( 'off' !== BKWB_Settings::get( 'schedule' ) && ! wp_next_scheduled( self::HOOK ) ) {
			self::reschedule();
		}
	}

	public static function reschedule() {
		wp_clear_scheduled_hook( self::HOOK );
		if ( ! wp_next_scheduled( self::WATCHDOG ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::WATCHDOG );
		}
		$next = self::next_run();
		if ( $next ) {
			wp_schedule_single_event( $next, self::HOOK );
		}
	}

	/**
	 * Prochaine sauvegarde planifiée (timestamp), ou null si la planification est désactivée.
	 */
	public static function next_run( $from = null ) {
		$schedule = BKWB_Settings::get( 'schedule' );
		if ( 'off' === $schedule ) {
			return null;
		}
		$tz  = wp_timezone();
		$now = ( new DateTimeImmutable( '@' . ( $from ? $from : time() ) ) )->setTimezone( $tz );
		list( $h, $m ) = array_map( 'intval', explode( ':', BKWB_Settings::get( 'time' ) ) );

		$candidates = array();
		for ( $d = 0; $d <= 7; $d++ ) {
			$day = $now->modify( '+' . $d . ' day' )->setTime( $h, $m );
			if ( 'weekly' === $schedule && (int) $day->format( 'N' ) !== (int) BKWB_Settings::get( 'weekday' ) ) {
				continue;
			}
			$candidates[] = $day;
			if ( 'twicedaily' === $schedule ) {
				$candidates[] = $day->modify( '+12 hours' );
				$candidates[] = $day->modify( '-12 hours' );
			}
		}
		$best = null;
		foreach ( $candidates as $c ) {
			if ( $c->getTimestamp() > $now->getTimestamp() + 60 && ( null === $best || $c->getTimestamp() < $best ) ) {
				$best = $c->getTimestamp();
			}
		}
		return $best;
	}

	/**
	 * Intervalle attendu entre deux sauvegardes, en secondes.
	 */
	public static function interval() {
		switch ( BKWB_Settings::get( 'schedule' ) ) {
			case 'twicedaily':
				return 12 * HOUR_IN_SECONDS;
			case 'weekly':
				return WEEK_IN_SECONDS;
			case 'daily':
				return DAY_IN_SECONDS;
		}
		return 0;
	}

	public static function run_scheduled() {
		self::reschedule();
		if ( BKWB_Jobs::current() ) {
			BKWB_Log::write( 'Sauvegarde planifiée ignorée : une tâche est déjà en cours.' );
			return;
		}
		try {
			BKWB_Runner::start_backup( 'planifiée' );
			// En ligne de commande (cron o2switch), on enchaîne directement.
			if ( BKWB_Runner::is_cli() ) {
				BKWB_Runner::run_until_done();
			}
		} catch ( Exception $e ) {
			BKWB_Log::write( 'Lancement de la sauvegarde planifiée impossible : ' . $e->getMessage() );
			BKWB_Notifier::send(
				'Échec du lancement de la sauvegarde',
				array( 'La sauvegarde planifiée n’a pas pu démarrer :', $e->getMessage() )
			);
		}
	}

	/**
	 * Vérifie chaque heure qu'une sauvegarde a réussi récemment ; alerte au plus une fois par jour.
	 */
	public static function watchdog() {
		$interval = self::interval();
		if ( ! $interval ) {
			return;
		}
		$last = BKWB_Log::last_success();
		$ref  = $last ? (int) $last['finished'] : (int) get_option( 'bkwb_installed', time() );
		$late = time() - $ref > $interval + 6 * HOUR_IN_SECONDS;
		if ( ! $late || BKWB_Jobs::current() ) {
			return;
		}
		if ( time() - (int) get_option( 'bkwb_late_alert', 0 ) < DAY_IN_SECONDS ) {
			return;
		}
		update_option( 'bkwb_late_alert', time(), false );
		$lines = array(
			$last
				? 'La dernière sauvegarde réussie date du ' . wp_date( 'd/m/Y à H:i', $ref ) . '.'
				: 'Aucune sauvegarde n’a encore réussi sur ce site.',
		);
		$failed = BKWB_Log::last_backup();
		if ( $failed && 'success' !== $failed['status'] && ! empty( $failed['error'] ) ) {
			$lines[] = 'Dernière erreur : ' . $failed['error'];
		}
		if ( ! self::cron_recently_seen() ) {
			$lines[] = 'La tâche cron du serveur ne semble pas active : les sauvegardes ne partent que lorsque le site reçoit des visites.';
		}
		BKWB_Notifier::send( 'Sauvegarde en retard', $lines );
	}

	public static function mark_cron_seen() {
		update_option( self::CRON_SEEN, time(), false );
	}

	public static function cron_recently_seen() {
		return time() - (int) get_option( self::CRON_SEEN, 0 ) < 2 * HOUR_IN_SECONDS;
	}
}
