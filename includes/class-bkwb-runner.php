<?php
/**
 * Fait avancer la tâche en cours par tranches de temps, pour rester sous la limite d'exécution
 * de l'hébergement. Une tranche se termine en relançant la suivante :
 *  - en ligne de commande (tâche cron o2switch ou WP-CLI) : pas de limite, tout s'enchaîne ;
 *  - sur le web : requête de relance non bloquante vers admin-ajax.php, avec en secours un
 *    événement WP-Cron une minute et demie plus tard.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BKWB_Runner {

	const TICK_HOOK = 'bkwb_tick';
	const TOKEN     = 'bkwb_tick_token';
	const STALE     = 21600; // Tâche sans activité depuis 6 h : considérée comme bloquée.

	public static function init() {
		add_action( self::TICK_HOOK, array( __CLASS__, 'cron_tick' ) );
		add_action( 'wp_ajax_nopriv_bkwb_tick', array( __CLASS__, 'ajax_tick' ) );
		add_action( 'wp_ajax_bkwb_tick', array( __CLASS__, 'ajax_tick' ) );
	}

	public static function is_cli() {
		return 'cli' === PHP_SAPI;
	}

	public static function token() {
		$token = get_option( self::TOKEN );
		if ( ! $token ) {
			$token = wp_generate_password( 32, false, false );
			update_option( self::TOKEN, $token, false );
		}
		return $token;
	}

	/**
	 * Durée d'une tranche, en secondes (0 = sans limite).
	 */
	public static function budget() {
		if ( self::is_cli() ) {
			return 0;
		}
		// Hébergement qui coupe les requêtes plus tôt que sa limite affichée : durée imposée dans wp-config.php.
		if ( defined( 'BKWB_TICK_BUDGET' ) && BKWB_TICK_BUDGET > 0 ) {
			return (int) BKWB_TICK_BUDGET;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}
		$max = (int) ini_get( 'max_execution_time' );
		if ( $max <= 0 ) {
			return 40;
		}
		return max( 8, min( 40, $max - 10 ) );
	}

	/**
	 * Fait avancer la tâche en cours pendant une tranche. Retourne l'état de la tâche (ou null).
	 */
	public static function tick( $budget = null ) {
		if ( ! BKWB_Jobs::lock() ) {
			return BKWB_Jobs::load(); // Une autre requête s'en occupe déjà.
		}
		$s = null;
		try {
			$s = BKWB_Jobs::current();
			if ( ! $s ) {
				return BKWB_Jobs::load();
			}
			if ( ! empty( $s['retry_at'] ) && $s['retry_at'] > time() ) {
				self::schedule_fallback( $s['retry_at'] );
				return $s;
			}
			if ( null === $budget ) {
				$budget = self::budget();
			}
			ignore_user_abort( true );
			wp_raise_memory_limit( 'admin' );

			$class = 'restore' === $s['type'] ? 'BKWB_Restore_Job' : 'BKWB_Backup_Job';
			if ( ! empty( $s['in_tick'] ) ) {
				// La tranche précédente s'est arrêtée brutalement (limite de temps ou de mémoire).
				$s['crashes']++;
				$class::log( $s, 'Reprise après une interruption du serveur (' . $s['crashes'] . ').' );
			}
			if ( $s['crashes'] > 5 ) {
				$class::fail( $s, "Le traitement a été interrompu par le serveur 5 fois de suite au même endroit (limite de mémoire ou de temps de l'hébergement)." );
				BKWB_Jobs::save( $s );
				return $s;
			}

			$s['in_tick']   = true;
			$s['ticks']     = (int) $s['ticks'] + 1;
			$s['last_tick'] = time();
			$s['retry_at']  = 0;
			BKWB_Jobs::save( $s );

			$deadline = $budget ? microtime( true ) + $budget : 0;
			try {
				while ( ! BKWB_Jobs::is_finished( $s ) ) {
					$class::step( $s, $deadline );
					if ( $s['retry_at'] > time() ) {
						break;
					}
					if ( $deadline && microtime( true ) >= $deadline ) {
						break;
					}
				}
			} catch ( Throwable $e ) {
				$class::fail( $s, $e->getMessage() );
			}
			$s['in_tick'] = false;
			$s['crashes'] = 0;
			BKWB_Jobs::save( $s );
		} finally {
			BKWB_Jobs::unlock();
		}
		if ( $s && ! BKWB_Jobs::is_finished( $s ) && ! self::is_cli() ) {
			self::kick( $s );
		}
		return $s;
	}

	/**
	 * Relance la tranche suivante sans attendre (et prévoit un secours via WP-Cron).
	 */
	public static function kick( $s = null ) {
		if ( $s && ! empty( $s['retry_at'] ) && $s['retry_at'] > time() ) {
			self::schedule_fallback( $s['retry_at'] );
			return;
		}
		self::schedule_fallback( time() + 90 );
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'action' => 'bkwb_tick',
					'token'  => self::token(),
				),
			)
		);
	}

	public static function schedule_fallback( $timestamp ) {
		$next = wp_next_scheduled( self::TICK_HOOK );
		if ( $next && $next <= $timestamp + 30 ) {
			return;
		}
		if ( $next ) {
			wp_unschedule_event( $next, self::TICK_HOOK );
		}
		wp_schedule_single_event( $timestamp, self::TICK_HOOK );
	}

	public static function ajax_tick() {
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! hash_equals( self::token(), $token ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		self::tick();
		wp_die( 'ok' );
	}

	public static function cron_tick() {
		self::tick();
	}

	/**
	 * Ligne de commande : fait tourner la tâche en cours jusqu'au bout.
	 */
	public static function run_until_done( $on_progress = null ) {
		$last = null;
		while ( $s = BKWB_Jobs::current() ) {
			if ( ! empty( $s['retry_at'] ) && $s['retry_at'] > time() ) {
				sleep( (int) min( 60, $s['retry_at'] - time() ) );
				continue;
			}
			$s = self::tick( 0 );
			if ( $on_progress ) {
				call_user_func( $on_progress, $s );
			}
			if ( $s && $last === $s['ticks'] ) {
				sleep( 5 ); // Une requête web détient le verrou : on attend qu'elle termine.
			}
			$last = $s ? $s['ticks'] : null;
		}
		return BKWB_Jobs::load();
	}

	public static function start_backup( $trigger = 'manual', $content = null ) {
		self::ensure_free();
		if ( ! BKWB_Jobs::lock() ) {
			throw new RuntimeException( 'Une tâche est en cours de traitement, réessayez dans une minute.' );
		}
		try {
			$s = BKWB_Backup_Job::create( $trigger, $content );
		} finally {
			BKWB_Jobs::unlock();
		}
		if ( ! self::is_cli() ) {
			self::kick( $s );
		}
		return $s;
	}

	public static function start_restore( $source, $name, $what ) {
		self::ensure_free();
		if ( ! BKWB_Jobs::lock() ) {
			throw new RuntimeException( 'Une tâche est en cours de traitement, réessayez dans une minute.' );
		}
		try {
			$s = BKWB_Restore_Job::create( $source, $name, $what );
		} finally {
			BKWB_Jobs::unlock();
		}
		if ( ! self::is_cli() ) {
			self::kick( $s );
		}
		return $s;
	}

	/**
	 * Annule la tâche en cours. Retourne l'état final.
	 */
	public static function cancel() {
		$tries = 0;
		while ( ! BKWB_Jobs::lock() ) {
			if ( ++$tries > 25 ) {
				throw new RuntimeException( 'La tâche est en plein traitement, réessayez dans une minute.' );
			}
			sleep( 2 );
		}
		try {
			$s = BKWB_Jobs::current();
			if ( ! $s ) {
				return BKWB_Jobs::load();
			}
			if ( 'restore' === $s['type'] ) {
				if ( ! BKWB_Restore_Job::can_cancel( $s ) ) {
					throw new RuntimeException( 'La restauration modifie déjà le site : elle ne peut plus être annulée.' );
				}
				BKWB_Restore_Job::cancel( $s );
			} else {
				BKWB_Backup_Job::cancel( $s );
			}
			BKWB_Jobs::save( $s );
			return $s;
		} finally {
			BKWB_Jobs::unlock();
		}
	}

	/**
	 * Refuse de lancer une tâche si une autre est en cours (sauf si elle est bloquée depuis longtemps).
	 */
	private static function ensure_free() {
		$cur = BKWB_Jobs::current();
		if ( ! $cur ) {
			return;
		}
		$last = ! empty( $cur['last_tick'] ) ? $cur['last_tick'] : $cur['started'];
		if ( time() - $last < self::STALE ) {
			throw new RuntimeException( 'restore' === $cur['type'] ? 'Une restauration est déjà en cours.' : 'Une sauvegarde est déjà en cours.' );
		}
		if ( BKWB_Jobs::lock() ) {
			$class = 'restore' === $cur['type'] ? 'BKWB_Restore_Job' : 'BKWB_Backup_Job';
			$class::fail( $cur, 'Tâche bloquée depuis plus de 6 heures, abandonnée.' );
			BKWB_Jobs::save( $cur );
			BKWB_Jobs::unlock();
		}
	}
}
