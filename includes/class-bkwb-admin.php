<?php
/**
 * Interface d'administration : tableau de bord, sauvegardes, réglages, journal.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BKWB_Admin {

	const SLUG = 'bk-web-backup';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_post_bkwb_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_bkwb_download', array( __CLASS__, 'download' ) );
		add_action( 'admin_post_bkwb_delete_local', array( __CLASS__, 'delete_local' ) );
		add_action( 'admin_post_bkwb_rescue', array( __CLASS__, 'rescue' ) );
		foreach ( array( 'status', 'start_backup', 'start_restore', 'cancel', 'test_s3', 'remote_list' ) as $action ) {
			add_action( 'wp_ajax_bkwb_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
		add_filter(
			'plugin_action_links_' . plugin_basename( BKWB_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">Tableau de bord</a>' );
				return $links;
			}
		);
	}

	public static function url( $tab = '', array $args = array() ) {
		$args = array_merge( array( 'page' => self::SLUG ), $tab ? array( 'tab' => $tab ) : array(), $args );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public static function menu() {
		add_menu_page( 'BK WEB Backup', 'BK WEB Backup', 'manage_options', self::SLUG, array( __CLASS__, 'render' ), 'dashicons-backup', 81 );
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'bkwb-admin', plugins_url( 'assets/admin.css', BKWB_FILE ), array(), BKWB_VERSION );
		wp_enqueue_script( 'bkwb-admin', plugins_url( 'assets/admin.js', BKWB_FILE ), array(), BKWB_VERSION, true );
		wp_localize_script(
			'bkwb-admin',
			'BKWB',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'bkwb' ),
				'url'   => self::url(),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Notices
	 * ------------------------------------------------------------------ */

	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			return;
		}
		$last = BKWB_Log::last_backup();
		if ( $last && in_array( $last['status'], array( 'failed', 'partial' ), true ) && time() - $last['finished'] < 3 * DAY_IN_SECONDS ) {
			printf(
				'<div class="notice notice-error"><p><strong>BK WEB Backup :</strong> %s <a href="%s">Voir le tableau de bord</a></p></div>',
				esc_html( 'failed' === $last['status'] ? 'la dernière sauvegarde a échoué.' : 'la dernière sauvegarde n’a pas pu être envoyée sur le stockage OVH.' ),
				esc_url( self::url() )
			);
		} elseif ( ! BKWB_Settings::s3_ready() ) {
			printf(
				'<div class="notice notice-warning"><p><strong>BK WEB Backup :</strong> le stockage OVH n’est pas configuré, les sauvegardes restent uniquement sur l’hébergement. <a href="%s">Configurer</a></p></div>',
				esc_url( self::url( 'settings' ) )
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Pages
	 * ------------------------------------------------------------------ */

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = array(
			'dashboard' => 'Tableau de bord',
			'backups'   => 'Sauvegardes',
			'settings'  => 'Réglages',
			'log'       => 'Journal',
		);
		$tab  = isset( $_GET['tab'] ) && isset( $tabs[ $_GET['tab'] ] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="wrap bkwb">';
		echo '<h1 class="bkwb-title"><span class="dashicons dashicons-backup"></span> BK WEB Backup</h1>';
		self::flash();
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( self::url( 'dashboard' === $key ? '' : $key ) ), $tab === $key ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';
		call_user_func( array( __CLASS__, 'tab_' . $tab ) );
		echo '</div>';
	}

	private static function flash() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Réglages enregistrés.</p></div>';
		}
		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Sauvegarde locale supprimée.</p></div>';
		}
		if ( isset( $_GET['error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ) . '</p></div>';
		}
		// phpcs:enable
		if ( BKWB_Settings::secret_unreadable() ) {
			echo '<div class="notice notice-error"><p>La clé secrète du stockage OVH ne peut plus être lue (les clés de sécurité de wp-config.php ont changé). Saisissez-la à nouveau dans les réglages.</p></div>';
		}
	}

	private static function tab_dashboard() {
		$last_ok  = BKWB_Log::last_success();
		$last     = BKWB_Log::last_backup();
		$next     = wp_next_scheduled( BKWB_Scheduler::HOOK );
		$s3       = BKWB_Settings::s3_ready();
		$cron     = BKWB_Scheduler::cron_recently_seen();
		$cron_at  = (int) get_option( BKWB_Scheduler::CRON_SEEN, 0 );
		$interval = BKWB_Scheduler::interval();
		$late     = $interval && ( ! $last_ok || time() - $last_ok['finished'] > $interval + 6 * HOUR_IN_SECONDS );

		echo '<div class="bkwb-cards">';

		// Dernière sauvegarde réussie.
		if ( $last_ok ) {
			$body = '<strong>' . esc_html( wp_date( 'd/m/Y à H:i', $last_ok['finished'] ) ) . '</strong><br>'
				. esc_html( 'il y a ' . human_time_diff( $last_ok['finished'] ) . ' — ' . size_format( $last_ok['size'] ) )
				. '<br>' . ( $last_ok['remote'] ? '<span class="bkwb-ok">Copie sur OVH</span>' : '<span class="bkwb-warn">Uniquement sur l’hébergement</span>' );
		} else {
			$body = '<strong>Aucune</strong><br>Aucune sauvegarde n’a encore réussi.';
		}
		self::card( 'Dernière sauvegarde réussie', $body, $late ? 'bad' : 'good' );

		// Dernière tentative en échec.
		if ( $last && 'success' !== $last['status'] && 'cancelled' !== $last['status'] ) {
			self::card(
				'Dernière tentative',
				'<strong>' . esc_html( self::status_label( $last['status'] ) ) . '</strong> le ' . esc_html( wp_date( 'd/m/Y à H:i', $last['started'] ) ) . '<br>' . esc_html( $last['error'] ),
				'bad'
			);
		}

		// Planification.
		$body = $next
			? '<strong>' . esc_html( wp_date( 'd/m/Y à H:i', $next ) ) . '</strong><br>' . esc_html( self::schedule_label() )
			: '<strong>Désactivée</strong><br><a href="' . esc_url( self::url( 'settings' ) ) . '">Programmer des sauvegardes</a>';
		self::card( 'Prochaine sauvegarde', $body, $next ? 'good' : 'bad' );

		// Stockage.
		$body = $s3
			? '<strong>Configuré</strong><br><code>' . esc_html( BKWB_Settings::get( 's3_bucket' ) . '/' . BKWB_Settings::get( 's3_prefix' ) ) . '</code>'
			: '<strong>Non configuré</strong><br><a href="' . esc_url( self::url( 'settings' ) ) . '">Renseigner le stockage OVH</a>';
		self::card( 'Stockage OVH', $body, $s3 ? 'good' : 'bad' );

		// Tâche cron.
		$body = $cron
			? '<strong>Active</strong><br>Dernier passage il y a ' . esc_html( human_time_diff( $cron_at ) ) . '.'
			: '<strong>Non détectée</strong><br>Les sauvegardes dépendent des visites du site. <a href="' . esc_url( self::url( 'settings' ) ) . '#bkwb-cron">Activer la tâche cron</a>';
		self::card( 'Tâche cron du serveur', $body, $cron ? 'good' : 'warn' );

		echo '</div>';

		// Tâche en cours (remplie par admin.js).
		echo '<div id="bkwb-job" class="bkwb-panel" hidden>';
		echo '<h2 id="bkwb-job-title">Tâche en cours</h2>';
		echo '<div class="bkwb-progress"><div id="bkwb-job-bar" style="width:0%"></div></div>';
		echo '<p><strong id="bkwb-job-label"></strong> — <span id="bkwb-job-pct">0</span> %</p>';
		echo '<ul id="bkwb-job-log" class="bkwb-log"></ul>';
		echo '<p><button type="button" class="button" id="bkwb-cancel">Annuler</button></p>';
		echo '</div>';
		echo '<div id="bkwb-result" hidden></div>';

		echo '<div class="bkwb-panel"><h2>Sauvegarder maintenant</h2>';
		echo '<p><button type="button" class="button button-primary bkwb-start" data-content="full">Sauvegarde complète</button> ';
		echo '<button type="button" class="button bkwb-start" data-content="db">Base de données seule</button></p>';
		echo '<p class="description">Vous pouvez quitter cette page : la sauvegarde continue en arrière-plan.</p></div>';

		self::history_table();
	}

	private static function card( $title, $body, $tone ) {
		printf( '<div class="bkwb-card bkwb-%s"><h3>%s</h3><p>%s</p></div>', esc_attr( $tone ), esc_html( $title ), $body ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private static function history_table() {
		$history = array_slice( BKWB_Log::history(), 0, 20 );
		echo '<div class="bkwb-panel"><h2>Historique</h2>';
		if ( ! $history ) {
			echo '<p>Aucune opération pour l’instant.</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Opération</th><th>Statut</th><th>Taille</th><th>OVH</th><th>Détails</th></tr></thead><tbody>';
		foreach ( $history as $h ) {
			$op = 'restore' === $h['type']
				? 'Restauration (' . BKWB_Restore_Job::what_label( $h['content'] ) . ')'
				: ( 'db' === $h['content'] ? 'Sauvegarde base seule' : 'Sauvegarde complète' ) . ( $h['trigger'] ? ' — ' . $h['trigger'] : '' );
			$details = $h['error'];
			if ( ! empty( $h['warnings'] ) ) {
				$details .= ( $details ? ' — ' : '' ) . count( $h['warnings'] ) . ' avertissement(s)';
			}
			printf(
				'<tr><td>%s</td><td>%s</td><td><span class="bkwb-badge bkwb-%s">%s</span></td><td>%s</td><td>%s</td><td>%s%s</td></tr>',
				esc_html( wp_date( 'd/m/Y H:i', $h['started'] ) ),
				esc_html( $op ),
				esc_attr( $h['status'] ),
				esc_html( self::status_label( $h['status'] ) ),
				$h['size'] ? esc_html( size_format( $h['size'] ) ) : '—',
				'backup' === $h['type'] ? ( $h['remote'] ? '✓' : '✗' ) : '',
				esc_html( $details ),
				! empty( $h['warnings'] ) ? '<details><summary>Voir</summary><ul><li>' . implode( '</li><li>', array_map( 'esc_html', $h['warnings'] ) ) . '</li></ul></details>' : ''
			);
		}
		echo '</tbody></table></div>';
	}

	private static function tab_backups() {
		$local = BKWB_Storage::local_archives();
		echo '<div class="bkwb-panel"><h2>Sur l’hébergement</h2>';
		echo '<p class="description">Dossier : <code>' . esc_html( BKWB_Storage::base_dir() . '/archives' ) . '</code>';
		if ( BKWB_Storage::inside_site() ) {
			echo ' <span class="bkwb-warn">— dans le dossier du site (accès web bloqué par le plugin).</span>';
		}
		echo '</p>';
		if ( ! $local ) {
			echo '<p>Aucune sauvegarde locale.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Contenu</th><th>Taille</th><th>Actions</th></tr></thead><tbody>';
			foreach ( $local as $a ) {
				$dl  = wp_nonce_url( admin_url( 'admin-post.php?action=bkwb_download&name=' . rawurlencode( $a['name'] ) ), 'bkwb_download_' . $a['name'] );
				$del = wp_nonce_url( admin_url( 'admin-post.php?action=bkwb_delete_local&name=' . rawurlencode( $a['name'] ) ), 'bkwb_delete_' . $a['name'] );
				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td><td><a class="button" href="%s">Télécharger</a> <button type="button" class="button bkwb-restore" data-source="local" data-name="%s" data-content="%s">Restaurer…</button> <a class="button-link-delete" href="%s" onclick="return confirm(\'Supprimer cette sauvegarde de l’hébergement ?\')">Supprimer</a></td></tr>',
					esc_html( wp_date( 'd/m/Y H:i', $a['created'] ) ),
					esc_html( 'db' === $a['content'] ? 'Base seule' : 'Fichiers + base' ),
					esc_html( size_format( $a['size'] ) ),
					esc_url( $dl ),
					esc_attr( $a['name'] ),
					esc_attr( $a['content'] ),
					esc_url( $del )
				);
			}
			echo '</tbody></table>';
		}
		echo '</div>';

		echo '<div class="bkwb-panel"><h2>Sur le stockage OVH</h2>';
		if ( ! BKWB_Settings::s3_ready() ) {
			echo '<p>Stockage non configuré. <a href="' . esc_url( self::url( 'settings' ) ) . '">Configurer</a></p>';
		} else {
			echo '<p><button type="button" class="button" id="bkwb-remote-load">Afficher les sauvegardes sur OVH</button></p>';
			echo '<div id="bkwb-remote"></div>';
			echo '<p class="description">Les sauvegardes sont verrouillées sur OVH pendant la durée de conservation réglée sur le bucket : elles ne peuvent pas être supprimées depuis le site.</p>';
		}
		echo '</div>';

		// Fenêtre de restauration (affichée par admin.js).
		echo '<div id="bkwb-restore-box" class="bkwb-panel bkwb-danger" hidden>';
		echo '<h2>Restaurer <span id="bkwb-restore-name"></span></h2>';
		echo '<p><label><input type="radio" name="bkwb-what" value="full" checked> Fichiers et base de données</label><br>';
		echo '<label><input type="radio" name="bkwb-what" value="db"> Base de données seule</label><br>';
		echo '<label><input type="radio" name="bkwb-what" value="files"> Fichiers seuls</label></p>';
		echo '<p>Le contenu actuel du site sera remplacé. Le fichier <code>wp-config.php</code> et ce plugin ne sont pas modifiés. Les visiteurs voient une page de maintenance pendant la mise en place.<br>';
		echo 'Après la restauration de la base, vous devrez peut-être vous reconnecter.</p>';
		echo '<p>Tapez <strong>RESTAURER</strong> pour confirmer : <input type="text" id="bkwb-restore-confirm" autocomplete="off"></p>';
		echo '<p><button type="button" class="button button-primary" id="bkwb-restore-go">Lancer la restauration</button> <button type="button" class="button" id="bkwb-restore-close">Fermer</button></p>';
		echo '</div>';

		echo '<div id="bkwb-job" class="bkwb-panel" hidden><h2 id="bkwb-job-title">Tâche en cours</h2><div class="bkwb-progress"><div id="bkwb-job-bar" style="width:0%"></div></div>';
		echo '<p><strong id="bkwb-job-label"></strong> — <span id="bkwb-job-pct">0</span> %</p><ul id="bkwb-job-log" class="bkwb-log"></ul><p><button type="button" class="button" id="bkwb-cancel">Annuler</button></p></div>';
		echo '<div id="bkwb-result" hidden></div>';

		// Script de secours.
		echo '<div class="bkwb-panel"><h2>Script de secours</h2>';
		echo '<p>Si WordPress ne fonctionne plus du tout, ce petit fichier restaure une sauvegarde sans passer par WordPress. Gardez-le dans un endroit sûr (pas sur le serveur). Il est valable 30 jours.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bkwb_rescue' );
		echo '<input type="hidden" name="action" value="bkwb_rescue">';
		echo '<p><label>Mot de passe à choisir (12 caractères minimum)<br><input type="password" name="password" minlength="12" required autocomplete="new-password" class="regular-text"></label></p>';
		echo '<p><button class="button">Télécharger le script de secours</button></p>';
		echo '<p class="description">Mode d’emploi : envoyez <code>bkwb-secours.php</code> à la racine du site (à côté de wp-config.php) avec l’archive .tar.gz, ouvrez https://votre-site/bkwb-secours.php, puis supprimez le fichier une fois la restauration terminée.</p>';
		echo '</form></div>';
	}

	private static function tab_settings() {
		$all = BKWB_Settings::all();
		$c   = function ( $key ) {
			return BKWB_Settings::from_constant( $key );
		};
		$val = function ( $key ) use ( $all ) {
			return BKWB_Settings::from_constant( $key ) ? constant( 'BKWB_' . strtoupper( $key ) ) : $all[ $key ];
		};

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bkwb-settings">';
		wp_nonce_field( 'bkwb_settings' );
		echo '<input type="hidden" name="action" value="bkwb_save_settings">';

		echo '<div class="bkwb-panel"><h2>Stockage OVH (S3)</h2><table class="form-table">';
		self::field_text( 's3_endpoint', 'Adresse du serveur (endpoint)', $val( 's3_endpoint' ), $c( 's3_endpoint' ), 'Ex. https://s3.eu-west-par.io.cloud.ovh.net — visible dans OVH, onglet « Informations générales » du bucket.' );
		self::field_text( 's3_region', 'Région', $val( 's3_region' ), $c( 's3_region' ), 'Ex. eu-west-par (Paris), gra, sbg, rbx.' );
		self::field_text( 's3_bucket', 'Nom du bucket', $val( 's3_bucket' ), $c( 's3_bucket' ), 'Ex. bk-sauvegardes-wordpress' );
		self::field_text( 's3_prefix', 'Dossier dans le bucket', $c( 's3_prefix' ) ? $val( 's3_prefix' ) : $all['s3_prefix'], $c( 's3_prefix' ), 'Laisser vide pour utiliser « ' . BKWB_Settings::default_prefix() . ' ». Un dossier par site.', BKWB_Settings::default_prefix() );
		self::field_text( 's3_access_key', 'Clé d’accès', $val( 's3_access_key' ), $c( 's3_access_key' ), 'Clé de l’utilisateur S3 propre à ce site.' );
		$secret_set = $c( 's3_secret_key' ) || '' !== $all['s3_secret_key'];
		echo '<tr><th scope="row"><label for="bkwb-s3_secret_key">Clé secrète</label></th><td>';
		if ( $c( 's3_secret_key' ) ) {
			echo '<em>Définie dans wp-config.php.</em>';
		} else {
			printf( '<input type="password" id="bkwb-s3_secret_key" name="bkwb[s3_secret_key]" class="regular-text" autocomplete="new-password" placeholder="%s">', esc_attr( $secret_set ? '•••••••• (enregistrée — laisser vide pour la garder)' : '' ) );
			echo '<p class="description">Enregistrée chiffrée. Plus sûr : la définir dans wp-config.php (voir plus bas).</p>';
		}
		echo '</td></tr>';
		echo '<tr><th scope="row">Vérification</th><td><button type="button" class="button" id="bkwb-test-s3">Tester la connexion</button> <span id="bkwb-test-result"></span><p class="description">Teste les réglages enregistrés : pensez à enregistrer d’abord.</p></td></tr>';
		echo '</table></div>';

		echo '<div class="bkwb-panel"><h2>Planification</h2><table class="form-table">';
		echo '<tr><th scope="row"><label for="bkwb-schedule">Fréquence</label></th><td><select id="bkwb-schedule" name="bkwb[schedule]">';
		foreach ( array(
			'daily'      => 'Tous les jours',
			'twicedaily' => 'Deux fois par jour',
			'weekly'     => 'Une fois par semaine',
			'off'        => 'Désactivée (manuelle uniquement)',
		) as $k => $l ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $all['schedule'], $k, false ), esc_html( $l ) );
		}
		echo '</select></td></tr>';
		printf( '<tr><th scope="row"><label for="bkwb-time">Heure</label></th><td><input type="time" id="bkwb-time" name="bkwb[time]" value="%s"><p class="description">Heure du site (%s). Évitez minuit, l’heure la plus chargée.</p></td></tr>', esc_attr( $all['time'] ), esc_html( wp_timezone_string() ) );
		echo '<tr class="bkwb-weekday"><th scope="row"><label for="bkwb-weekday">Jour</label></th><td><select id="bkwb-weekday" name="bkwb[weekday]">';
		foreach ( array( 1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche' ) as $k => $l ) {
			printf( '<option value="%d"%s>%s</option>', (int) $k, selected( (int) $all['weekday'], $k, false ), esc_html( $l ) );
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="bkwb-content">Contenu</label></th><td><select id="bkwb-content" name="bkwb[content]">';
		printf( '<option value="full"%s>Fichiers et base de données</option>', selected( $all['content'], 'full', false ) );
		printf( '<option value="db"%s>Base de données seule</option>', selected( $all['content'], 'db', false ) );
		echo '</select></td></tr>';
		echo '</table></div>';

		echo '<div class="bkwb-panel"><h2>Copies et exclusions</h2><table class="form-table">';
		printf( '<tr><th scope="row"><label for="bkwb-local_keep">Copies gardées sur l’hébergement</label></th><td><input type="number" min="0" max="10" id="bkwb-local_keep" name="bkwb[local_keep]" value="%d" class="small-text"><p class="description">1 recommandé (restauration rapide). 0 = supprimée dès qu’elle est envoyée sur OVH. La conservation sur OVH est réglée sur le bucket.</p></td></tr>', (int) $all['local_keep'] );
		printf( '<tr><th scope="row"><label for="bkwb-excludes">Dossiers ou fichiers à exclure</label></th><td><textarea id="bkwb-excludes" name="bkwb[excludes]" rows="4" class="large-text code" placeholder="wp-content/uploads/gros-dossier">%s</textarea><p class="description">Un chemin par ligne, relatif à la racine du site. Le caractère * est accepté. Les caches et les sauvegardes d’autres extensions sont déjà exclus.</p></td></tr>', esc_textarea( $all['excludes'] ) );
		printf( '<tr><th scope="row">Base de données</th><td><label><input type="checkbox" name="bkwb[all_tables]" value="1"%s> Inclure toutes les tables de la base, pas seulement celles de WordPress (%s)</label></td></tr>', checked( $all['all_tables'], 1, false ), esc_html( $GLOBALS['wpdb']->prefix . '*' ) );
		echo '</table></div>';

		echo '<div class="bkwb-panel"><h2>Alertes par e-mail</h2><table class="form-table">';
		printf( '<tr><th scope="row"><label for="bkwb-emails">Destinataires</label></th><td><input type="text" id="bkwb-emails" name="bkwb[emails]" value="%s" class="regular-text" placeholder="%s"><p class="description">Séparés par des virgules. Vide = e-mail de l’administrateur. Une alerte part en cas d’échec, de copie OVH manquante ou de retard.</p></td></tr>', esc_attr( $all['emails'] ), esc_attr( get_option( 'admin_email' ) ) );
		printf( '<tr><th scope="row">Sauvegardes réussies</th><td><label><input type="checkbox" name="bkwb[notify_success]" value="1"%s> Envoyer aussi un e-mail quand tout s’est bien passé</label></td></tr>', checked( $all['notify_success'], 1, false ) );
		echo '</table></div>';

		submit_button( 'Enregistrer les réglages' );
		echo '</form>';

		// Aide o2switch.
		$php  = 'php';
		$cron = BKWB_DIR . '/bin/cron.php';
		echo '<div class="bkwb-panel" id="bkwb-cron"><h2>Tâche cron (o2switch)</h2>';
		echo '<p>Pour que les sauvegardes partent à l’heure même sans visite, et sans limite de temps, ajoutez cette tâche dans <strong>cPanel → Tâches Cron</strong>, réglage « Toutes les 15 minutes » (<code>*/15 * * * *</code>) :</p>';
		echo '<p><input type="text" readonly class="large-text code" onclick="this.select()" value="' . esc_attr( $php . ' ' . $cron . ' >/dev/null 2>&1' ) . '"></p>';
		echo '<p class="description">État : ' . ( BKWB_Scheduler::cron_recently_seen() ? '<span class="bkwb-ok">tâche cron détectée</span>' : '<span class="bkwb-warn">pas encore détectée</span>' ) . '. Facultatif : ajoutez <code>define( \'DISABLE_WP_CRON\', true );</code> dans wp-config.php pour ne plus déclencher WP-Cron à chaque visite.</p>';
		echo '</div>';

		echo '<div class="bkwb-panel"><h2>Réglages dans wp-config.php (recommandé pour la clé secrète)</h2>';
		echo '<p>Les valeurs définies ici remplacent celles du formulaire et ne sont pas stockées dans la base de données :</p>';
		echo '<pre class="bkwb-code">define( \'BKWB_S3_ENDPOINT\', \'https://s3.eu-west-par.io.cloud.ovh.net\' );' . "\n"
			. 'define( \'BKWB_S3_REGION\', \'eu-west-par\' );' . "\n"
			. 'define( \'BKWB_S3_BUCKET\', \'bk-sauvegardes-wordpress\' );' . "\n"
			. 'define( \'BKWB_S3_ACCESS_KEY\', \'…\' );' . "\n"
			. 'define( \'BKWB_S3_SECRET_KEY\', \'…\' );</pre>';
		echo '<p class="description">Dossier local des sauvegardes : <code>' . esc_html( BKWB_Storage::base_dir() ) . '</code> (modifiable avec <code>BKWB_LOCAL_DIR</code>).</p>';
		echo '</div>';
	}

	private static function field_text( $key, $label, $value, $locked, $help = '', $placeholder = '' ) {
		echo '<tr><th scope="row"><label for="bkwb-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( $locked ) {
			echo '<code>' . esc_html( $value ) . '</code> <em>(défini dans wp-config.php)</em>';
		} else {
			printf( '<input type="text" id="bkwb-%1$s" name="bkwb[%1$s]" value="%2$s" class="regular-text" placeholder="%3$s" autocomplete="off">', esc_attr( $key ), esc_attr( $value ), esc_attr( $placeholder ) );
		}
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	private static function tab_log() {
		echo '<div class="bkwb-panel"><h2>Journal technique</h2>';
		$lines = BKWB_Log::tail( 200 );
		if ( ! $lines ) {
			echo '<p>Journal vide.</p>';
		} else {
			echo '<pre class="bkwb-code bkwb-journal">' . esc_html( implode( "\n", array_reverse( $lines ) ) ) . '</pre>';
		}
		echo '<p class="description">Fichier : <code>' . esc_html( BKWB_Log::file() ) . '</code></p></div>';
	}

	public static function status_label( $status ) {
		$labels = array(
			'success'   => 'Réussie',
			'partial'   => 'Sans copie OVH',
			'failed'    => 'Échec',
			'cancelled' => 'Annulée',
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	private static function schedule_label() {
		$all  = BKWB_Settings::all();
		$days = array( 1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche' );
		switch ( $all['schedule'] ) {
			case 'daily':
				$label = 'Tous les jours à ' . $all['time'];
				break;
			case 'twicedaily':
				$label = 'Deux fois par jour';
				break;
			case 'weekly':
				$label = 'Chaque ' . $days[ (int) $all['weekday'] ] . ' à ' . $all['time'];
				break;
			default:
				$label = 'Désactivée';
		}
		return $label . ( 'db' === $all['content'] ? ' — base seule' : ' — fichiers + base' );
	}

	/* ---------------------------------------------------------------------
	 * Actions (admin-post)
	 * ------------------------------------------------------------------ */

	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Accès refusé.' );
		}
		check_admin_referer( 'bkwb_settings' );
		$input = isset( $_POST['bkwb'] ) && is_array( $_POST['bkwb'] ) ? $_POST['bkwb'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nettoyé dans BKWB_Settings::save().
		BKWB_Settings::save( $input );
		BKWB_Scheduler::reschedule();
		delete_transient( BKWB_Admin_Helpers::REMOTE_CACHE );
		wp_safe_redirect( self::url( 'settings', array( 'saved' => 1 ) ) );
		exit;
	}

	public static function download() {
		$name = isset( $_GET['name'] ) ? basename( sanitize_text_field( wp_unslash( $_GET['name'] ) ) ) : '';
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'bkwb_download_' . $name ) ) {
			wp_die( 'Accès refusé.' );
		}
		$file = BKWB_Storage::dir( 'archives' ) . '/' . $name . '.tar.gz';
		if ( ! is_file( $file ) ) {
			wp_die( 'Sauvegarde introuvable.' );
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		@set_time_limit( 0 );
		header( 'Content-Type: application/gzip' );
		header( 'Content-Disposition: attachment; filename="' . $name . '.tar.gz"' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'X-Content-Type-Options: nosniff' );
		$fh = fopen( $file, 'rb' );
		while ( ! feof( $fh ) ) {
			echo fread( $fh, MB_IN_BYTES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			flush();
		}
		fclose( $fh );
		exit;
	}

	public static function delete_local() {
		$name = isset( $_GET['name'] ) ? basename( sanitize_text_field( wp_unslash( $_GET['name'] ) ) ) : '';
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'bkwb_delete_' . $name ) ) {
			wp_die( 'Accès refusé.' );
		}
		BKWB_Storage::delete_archive( $name );
		wp_safe_redirect( self::url( 'backups', array( 'deleted' => 1 ) ) );
		exit;
	}

	public static function rescue() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Accès refusé.' );
		}
		check_admin_referer( 'bkwb_rescue' );
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		try {
			$code = BKWB_Rescue_Builder::build( $password );
		} catch ( Exception $e ) {
			wp_safe_redirect( self::url( 'backups', array( 'error' => $e->getMessage() ) ) );
			exit;
		}
		BKWB_Log::write( 'Script de secours téléchargé par ' . wp_get_current_user()->user_login . '.' );
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="bkwb-secours.php"' );
		header( 'Content-Length: ' . strlen( $code ) );
		echo $code; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */

	private static function check_ajax() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'bkwb', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Session expirée : rechargez la page (vous devrez peut-être vous reconnecter).' ), 403 );
		}
	}

	/**
	 * État de la tâche. Si la tâche ne progresse plus depuis quelques secondes (relance bloquée par
	 * l'hébergeur), cette requête la fait avancer elle-même : le tableau de bord ouvert suffit.
	 */
	public static function ajax_status() {
		self::check_ajax();
		$s = BKWB_Jobs::load();
		if ( $s && ! BKWB_Jobs::is_finished( $s ) && time() - (int) ( $s['last_tick'] ?? 0 ) > 8 ) {
			$s = BKWB_Runner::tick( min( 20, BKWB_Runner::budget() ) );
		}
		wp_send_json_success( self::job_payload( $s ) );
	}

	private static function job_payload( $s ) {
		if ( ! $s ) {
			return array( 'running' => false );
		}
		$running = ! BKWB_Jobs::is_finished( $s );
		return array(
			'running'    => $running,
			'type'       => $s['type'],
			'label'      => $s['label'],
			'progress'   => (int) $s['progress'],
			'log'        => array_slice( $s['log'], -10 ),
			'status'     => $s['status'],
			'result'     => isset( $s['result'] ) ? array(
				'status'   => $s['result']['status'],
				'label'    => self::status_label( $s['result']['status'] ),
				'error'    => $s['result']['error'],
				'size'     => $s['result']['size'] ? size_format( $s['result']['size'] ) : '',
				'remote'   => $s['result']['remote'],
				'finished' => wp_date( 'd/m/Y à H:i', $s['result']['finished'] ),
				'warnings' => count( $s['result']['warnings'] ),
			) : null,
			'can_cancel' => $running && ( 'backup' === $s['type'] || BKWB_Restore_Job::can_cancel( $s ) ),
			'waiting'    => $running && ! empty( $s['retry_at'] ) && $s['retry_at'] > time() ? 'Nouvel essai dans ' . human_time_diff( $s['retry_at'] ) : '',
		);
	}

	public static function ajax_start_backup() {
		self::check_ajax();
		$content = isset( $_POST['content'] ) && 'db' === $_POST['content'] ? 'db' : 'full';
		try {
			$s = BKWB_Runner::start_backup( 'manuelle', $content );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
		wp_send_json_success( self::job_payload( $s ) );
	}

	public static function ajax_start_restore() {
		self::check_ajax();
		$source  = isset( $_POST['source'] ) && 'remote' === $_POST['source'] ? 'remote' : 'local';
		$name    = isset( $_POST['name'] ) ? basename( sanitize_text_field( wp_unslash( $_POST['name'] ) ) ) : '';
		$what    = isset( $_POST['what'] ) ? sanitize_key( $_POST['what'] ) : 'full';
		$confirm = isset( $_POST['confirm'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) ) : '';
		if ( 'RESTAURER' !== $confirm ) {
			wp_send_json_error( array( 'message' => 'Tapez RESTAURER pour confirmer.' ) );
		}
		try {
			$s = BKWB_Runner::start_restore( $source, $name, $what );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
		wp_send_json_success( self::job_payload( $s ) );
	}

	public static function ajax_cancel() {
		self::check_ajax();
		try {
			$s = BKWB_Runner::cancel();
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
		wp_send_json_success( self::job_payload( $s ) );
	}

	public static function ajax_test_s3() {
		self::check_ajax();
		try {
			wp_send_json_success( array( 'message' => BKWB_Admin_Helpers::test_s3() ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public static function ajax_remote_list() {
		self::check_ajax();
		try {
			$list = BKWB_Admin_Helpers::remote_archives( ! empty( $_POST['refresh'] ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
		$local = BKWB_Storage::local_archives();
		$out   = array();
		foreach ( $list as $a ) {
			$out[] = array(
				'name'    => $a['name'],
				'date'    => wp_date( 'd/m/Y H:i', $a['created'] ),
				'size'    => size_format( $a['size'] ),
				'content' => $a['content'],
				'local'   => isset( $local[ $a['name'] ] ),
			);
		}
		wp_send_json_success( array( 'items' => $out ) );
	}
}
