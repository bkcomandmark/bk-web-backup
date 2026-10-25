<?php
/**
 * Réglages du plugin. Les informations du stockage S3 peuvent aussi être définies dans
 * wp-config.php (constantes BKWB_S3_*), ce qui est recommandé pour la clé secrète.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BKWB_Settings {

	const OPTION = 'bkwb_settings';

	private static $constants = array(
		's3_endpoint'   => 'BKWB_S3_ENDPOINT',
		's3_region'     => 'BKWB_S3_REGION',
		's3_bucket'     => 'BKWB_S3_BUCKET',
		's3_prefix'     => 'BKWB_S3_PREFIX',
		's3_access_key' => 'BKWB_S3_ACCESS_KEY',
		's3_secret_key' => 'BKWB_S3_SECRET_KEY',
	);

	public static function defaults() {
		return array(
			's3_endpoint'    => 'https://s3.eu-west-par.io.cloud.ovh.net',
			's3_region'      => 'eu-west-par',
			's3_bucket'      => '',
			's3_prefix'      => '',
			's3_access_key'  => '',
			's3_secret_key'  => '',
			'schedule'       => 'daily',   // daily, twicedaily, weekly, off.
			'time'           => '02:30',   // Heure locale du site.
			'weekday'        => 1,         // 1 = lundi … 7 = dimanche.
			'content'        => 'full',    // full = fichiers + base, db = base seule.
			'local_keep'     => 1,         // Copies gardées sur l'hébergement.
			'excludes'       => '',
			'emails'         => '',
			'notify_success' => 0,
			'all_tables'     => 0,         // 1 = toutes les tables de la base, pas seulement celles du préfixe.
		);
	}

	public static function all() {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Valeur effective d'un réglage (constante de wp-config.php prioritaire).
	 */
	public static function get( $key ) {
		if ( self::from_constant( $key ) ) {
			return constant( self::$constants[ $key ] );
		}
		$all   = self::all();
		$value = isset( $all[ $key ] ) ? $all[ $key ] : null;
		if ( 's3_secret_key' === $key ) {
			$value = self::decrypt( (string) $value );
		}
		if ( 's3_prefix' === $key && '' === $value ) {
			$value = self::default_prefix();
		}
		return $value;
	}

	public static function from_constant( $key ) {
		return isset( self::$constants[ $key ] ) && defined( self::$constants[ $key ] );
	}

	public static function save( array $in ) {
		$current = self::all();
		$out     = $current;

		foreach ( array( 's3_endpoint', 's3_region', 's3_bucket', 's3_access_key' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( wp_unslash( $in[ $k ] ) );
			}
		}
		if ( isset( $in['s3_endpoint'] ) && '' !== $out['s3_endpoint'] && ! preg_match( '#^https?://#i', $out['s3_endpoint'] ) ) {
			$out['s3_endpoint'] = 'https://' . $out['s3_endpoint'];
		}
		if ( isset( $in['s3_prefix'] ) ) {
			$prefix           = trim( sanitize_text_field( wp_unslash( $in['s3_prefix'] ) ), '/ ' );
			$out['s3_prefix'] = '' === $prefix ? '' : $prefix . '/';
		}
		if ( ! empty( $in['s3_secret_key'] ) ) {
			$out['s3_secret_key'] = self::encrypt( trim( wp_unslash( $in['s3_secret_key'] ) ) );
		}

		if ( isset( $in['schedule'] ) && in_array( $in['schedule'], array( 'daily', 'twicedaily', 'weekly', 'off' ), true ) ) {
			$out['schedule'] = $in['schedule'];
		}
		if ( isset( $in['time'] ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $in['time'] ) ) {
			$out['time'] = $in['time'];
		}
		if ( isset( $in['weekday'] ) ) {
			$out['weekday'] = min( 7, max( 1, (int) $in['weekday'] ) );
		}
		if ( isset( $in['content'] ) && in_array( $in['content'], array( 'full', 'db' ), true ) ) {
			$out['content'] = $in['content'];
		}
		if ( isset( $in['local_keep'] ) ) {
			$out['local_keep'] = min( 10, max( 0, (int) $in['local_keep'] ) );
		}
		if ( isset( $in['excludes'] ) ) {
			$lines           = preg_split( '/[\r\n]+/', wp_unslash( $in['excludes'] ) );
			$lines           = array_filter( array_map( 'trim', array_map( 'sanitize_text_field', $lines ) ) );
			$out['excludes'] = implode( "\n", $lines );
		}
		if ( isset( $in['emails'] ) ) {
			$emails        = array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', wp_unslash( $in['emails'] ) ) ) );
			$out['emails'] = implode( ', ', $emails );
		}
		$out['notify_success'] = empty( $in['notify_success'] ) ? 0 : 1;
		$out['all_tables']     = empty( $in['all_tables'] ) ? 0 : 1;

		update_option( self::OPTION, $out, false );
		return $out;
	}

	public static function default_prefix() {
		return self::site_slug() . '/';
	}

	/**
	 * Identifiant lisible du site, utilisé pour nommer les sauvegardes (ex. unat-bfc.fr).
	 */
	public static function site_slug() {
		$url  = home_url();
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$slug = preg_replace( '/^www\./', '', strtolower( $host ) );
		if ( '' !== $path ) {
			$slug .= '-' . str_replace( '/', '-', strtolower( $path ) );
		}
		$slug = preg_replace( '/[^a-z0-9.-]+/', '-', $slug );
		return '' !== trim( $slug, '-.' ) ? trim( $slug, '-.' ) : 'site';
	}

	public static function s3_ready() {
		return '' !== (string) self::get( 's3_endpoint' )
			&& '' !== (string) self::get( 's3_bucket' )
			&& '' !== (string) self::get( 's3_access_key' )
			&& '' !== (string) self::get( 's3_secret_key' );
	}

	/**
	 * @return BKWB_S3_Client|null
	 */
	public static function s3_client() {
		if ( ! self::s3_ready() ) {
			return null;
		}
		return new BKWB_S3_Client(
			self::get( 's3_endpoint' ),
			self::get( 's3_region' ),
			self::get( 's3_bucket' ),
			self::get( 's3_access_key' ),
			self::get( 's3_secret_key' )
		);
	}

	public static function emails() {
		$list = array_filter( array_map( 'trim', explode( ',', (string) self::get( 'emails' ) ) ) );
		return $list ? $list : array( get_option( 'admin_email' ) );
	}

	public static function excludes() {
		return array_filter( array_map( 'trim', explode( "\n", (string) self::get( 'excludes' ) ) ) );
	}

	/**
	 * Vrai si une clé secrète est enregistrée mais ne peut plus être déchiffrée
	 * (les clés de sécurité de wp-config.php ont changé).
	 */
	public static function secret_unreadable() {
		$all = self::all();
		return ! self::from_constant( 's3_secret_key' ) && '' !== $all['s3_secret_key'] && '' === self::decrypt( $all['s3_secret_key'] );
	}

	private static function crypto_key() {
		return hash( 'sha256', wp_salt( 'auth' ) . 'bk-web-backup', true );
	}

	public static function encrypt( $plain ) {
		if ( '' === $plain ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'b64:' . base64_encode( $plain );
		}
		$iv = random_bytes( 16 );
		$c  = openssl_encrypt( $plain, 'aes-256-cbc', self::crypto_key(), OPENSSL_RAW_DATA, $iv );
		return 'enc:' . base64_encode( $iv . $c );
	}

	public static function decrypt( $stored ) {
		if ( '' === $stored ) {
			return '';
		}
		if ( 0 === strpos( $stored, 'b64:' ) ) {
			return (string) base64_decode( substr( $stored, 4 ) );
		}
		if ( 0 !== strpos( $stored, 'enc:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw   = base64_decode( substr( $stored, 4 ) );
		$plain = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', self::crypto_key(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		return false === $plain ? '' : $plain;
	}
}
