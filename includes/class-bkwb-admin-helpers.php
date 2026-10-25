<?php
/**
 * Outils partagés par l'administration et WP-CLI : liste des sauvegardes distantes, test S3.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BKWB_Admin_Helpers {

	const REMOTE_CACHE = 'bkwb_remote_list';

	/**
	 * Sauvegardes présentes sur le stockage S3 (mises en cache 10 minutes).
	 */
	public static function remote_archives( $force = false ) {
		$cache = get_transient( self::REMOTE_CACHE );
		if ( ! $force && is_array( $cache ) ) {
			return $cache;
		}
		$client = BKWB_Settings::s3_client();
		if ( ! $client ) {
			return array();
		}
		$prefix = BKWB_Settings::get( 's3_prefix' );
		$list   = array();
		foreach ( $client->list_objects( $prefix ) as $item ) {
			$rest = substr( $item['key'], strlen( $prefix ) );
			if ( '.tar.gz' !== substr( $rest, -7 ) || false !== strpos( $rest, '/' ) ) {
				continue;
			}
			$name          = substr( $rest, 0, -7 );
			$created       = self::created_from_name( $name );
			$list[ $name ] = array(
				'name'    => $name,
				'key'     => $item['key'],
				'size'    => $item['size'],
				'created' => $created ? $created : $item['modified'],
				'content' => '_db' === substr( $name, -3 ) ? 'db' : 'full',
			);
		}
		uasort(
			$list,
			function ( $a, $b ) {
				return $b['created'] - $a['created'];
			}
		);
		set_transient( self::REMOTE_CACHE, $list, 10 * MINUTE_IN_SECONDS );
		return $list;
	}

	public static function created_from_name( $name ) {
		if ( preg_match( '/_(\d{8}-\d{6})_(full|db)$/', $name, $m ) ) {
			$date = DateTimeImmutable::createFromFormat( 'Ymd-His', $m[1], wp_timezone() );
			return $date ? $date->getTimestamp() : 0;
		}
		return 0;
	}

	/**
	 * Vérifie la lecture et l'écriture sur le stockage. Retourne un message de réussite.
	 */
	public static function test_s3() {
		$client = BKWB_Settings::s3_client();
		if ( ! $client ) {
			if ( BKWB_Settings::secret_unreadable() ) {
				throw new RuntimeException( 'La clé secrète enregistrée ne peut plus être lue (les clés de sécurité de wp-config.php ont changé) : saisissez-la à nouveau.' );
			}
			throw new RuntimeException( 'Stockage OVH incomplet : renseignez le bucket, la clé d’accès et la clé secrète.' );
		}
		$prefix = BKWB_Settings::get( 's3_prefix' );
		$client->list_objects( $prefix, 1 );
		$client->put_object( $prefix . 'bkwb-test-connexion.txt', 'Test de connexion BK WEB Backup — ' . gmdate( 'c' ), 'text/plain; charset=utf-8' );
		delete_transient( self::REMOTE_CACHE );
		return 'Connexion réussie : lecture et écriture autorisées dans ' . BKWB_Settings::get( 's3_bucket' ) . '/' . $prefix;
	}
}
