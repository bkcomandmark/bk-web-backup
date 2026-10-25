<?php
/**
 * Génère le script de secours autonome (un seul fichier PHP) : modèle + bibliothèques de lecture
 * d'archive et d'import SQL, avec le mot de passe choisi (haché) et une date d'expiration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BKWB_Rescue_Builder {

	const VALIDITY = 30 * DAY_IN_SECONDS;

	public static function build( $password ) {
		if ( strlen( $password ) < 12 ) {
			throw new RuntimeException( 'Le mot de passe du script de secours doit contenir au moins 12 caractères.' );
		}
		$expires = time() + self::VALIDITY;
		$tpl     = (string) file_get_contents( BKWB_DIR . '/rescue/rescue-template.tpl' );
		$tpl     = strtr(
			$tpl,
			array(
				'%%PASSWORD_HASH%%' => var_export( password_hash( $password, PASSWORD_DEFAULT ), true ),
				'%%EXPIRES%%'       => (string) $expires,
				'%%LOCAL_DIR%%'     => var_export( BKWB_Storage::base_dir(), true ),
				'%%SITE_EXPORT%%'   => var_export( home_url(), true ),
				'%%SITE%%'          => str_replace( '*/', '', home_url() ),
				'%%GENERATED%%'     => wp_date( 'd/m/Y H:i' ),
				'%%EXPIRES_HUMAN%%' => wp_date( 'd/m/Y', $expires ),
			)
		);
		foreach ( array( 'class-bkwb-tar-reader.php', 'class-bkwb-sql-importer.php' ) as $lib ) {
			$code = (string) file_get_contents( BKWB_DIR . '/lib/' . $lib );
			$tpl .= "\n" . preg_replace( '/^<\?php\s*/', '', $code );
		}
		return $tpl;
	}
}
