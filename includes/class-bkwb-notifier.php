<?php
/**
 * Alertes par e-mail : échec ou copie distante manquante, retard, restauration terminée.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BKWB_Notifier {

	public static function send( $subject, array $lines ) {
		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		$body = implode( "\n", $lines )
			. "\n\n— \nSite : " . home_url()
			. "\nTableau de bord : " . admin_url( 'admin.php?page=bk-web-backup' )
			. "\nMessage envoyé par BK WEB Backup.";
		return wp_mail( BKWB_Settings::emails(), '[BK WEB Backup] ' . $subject . ' – ' . $site, $body );
	}

	public static function backup_finished( array $entry ) {
		if ( 'success' === $entry['status'] ) {
			if ( BKWB_Settings::get( 'notify_success' ) ) {
				self::send(
					'Sauvegarde réussie',
					array(
						'La sauvegarde ' . $entry['name'] . ' s’est terminée avec succès.',
						'Taille : ' . size_format( $entry['size'] ) . ( $entry['remote'] ? ' — copie envoyée sur le stockage OVH.' : '.' ),
					)
				);
			}
			return;
		}
		if ( 'partial' === $entry['status'] ) {
			self::send(
				'Copie distante manquante',
				array(
					'La sauvegarde ' . $entry['name'] . ' a bien été créée sur l’hébergement, mais elle n’a pas pu être envoyée sur le stockage OVH.',
					'Erreur : ' . ( $entry['error'] ? $entry['error'] : 'inconnue' ),
					'La copie locale est conservée. Vérifiez les réglages du stockage dans le tableau de bord.',
				)
			);
			return;
		}
		self::send(
			'Échec de la sauvegarde',
			array(
				'La sauvegarde du ' . wp_date( 'd/m/Y à H:i', $entry['started'] ) . ' a échoué.',
				'Erreur : ' . ( $entry['error'] ? $entry['error'] : 'inconnue' ),
			)
		);
	}

	public static function restore_finished( array $entry ) {
		$ok = 'success' === $entry['status'];
		$lines = array(
			$ok
				? 'La restauration de ' . $entry['name'] . ' (' . BKWB_Restore_Job::what_label( $entry['content'] ) . ') est terminée.'
				: 'La restauration de ' . $entry['name'] . ' a échoué. Le site n’a pas été modifié, sauf mention contraire ci-dessous.',
		);
		if ( ! $ok && $entry['error'] ) {
			$lines[] = 'Erreur : ' . $entry['error'];
		}
		if ( $entry['warnings'] ) {
			$lines[] = 'Avertissements : ' . implode( ' ; ', array_slice( $entry['warnings'], 0, 5 ) );
		}
		self::send( $ok ? 'Restauration terminée' : 'Échec de la restauration', $lines );
	}
}
