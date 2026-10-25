<?php
/**
 * Dossier local des sauvegardes. Il est placé de préférence hors du dossier public du site
 * (ex. /home/identifiant/bk-web-backup/mon-site.fr sur o2switch) : les archives ne peuvent
 * alors pas être téléchargées depuis le web. À défaut, un dossier protégé au nom aléatoire est
 * créé dans wp-content. La constante BKWB_LOCAL_DIR permet d'imposer un emplacement.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BKWB_Storage {

	const OPTION = 'bkwb_local_dir';

	private static $base = null;

	public static function base_dir() {
		if ( null !== self::$base ) {
			return self::$base;
		}
		if ( defined( 'BKWB_LOCAL_DIR' ) && BKWB_LOCAL_DIR ) {
			self::$base = rtrim( BKWB_LOCAL_DIR, '/\\' );
			self::ensure( self::$base );
			return self::$base;
		}
		$saved = get_option( self::OPTION );
		if ( $saved && self::ensure( $saved ) ) {
			self::$base = $saved;
			return self::$base;
		}
		$dir = self::pick_dir();
		update_option( self::OPTION, $dir, true );
		self::$base = $dir;
		return self::$base;
	}

	private static function pick_dir() {
		$root   = rtrim( ABSPATH, '/\\' );
		$parent = dirname( $root );
		// Hors du dossier public : à côté du dossier du site.
		if ( $parent && $parent !== $root && @is_dir( $parent ) && @is_writable( $parent ) ) {
			$dir = $parent . '/bk-web-backup/' . BKWB_Settings::site_slug();
			if ( self::ensure( $dir ) ) {
				return $dir;
			}
		}
		// Sinon dans wp-content, avec un nom impossible à deviner et un accès web bloqué.
		$dir = WP_CONTENT_DIR . '/bk-web-backup-' . substr( hash( 'sha256', wp_salt( 'secure_auth' ) . home_url() ), 0, 16 );
		self::ensure( $dir );
		return $dir;
	}

	private static function ensure( $dir ) {
		if ( ! @is_dir( $dir ) && ! @wp_mkdir_p( $dir ) ) {
			return false;
		}
		if ( ! @is_writable( $dir ) ) {
			return false;
		}
		self::protect( $dir );
		return true;
	}

	/**
	 * Bloque l'accès web au dossier (Apache, LiteSpeed et IIS).
	 */
	public static function protect( $dir ) {
		$files = array(
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'index.php'  => "<?php\n// Silence.\n",
			'web.config' => '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
		);
		foreach ( $files as $name => $content ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				@file_put_contents( $dir . '/' . $name, $content );
			}
		}
	}

	/**
	 * Sous-dossier (créé si besoin) : archives, work, restore.
	 */
	public static function dir( $sub ) {
		$dir = self::base_dir() . '/' . $sub;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			self::protect( $dir );
		}
		return $dir;
	}

	/**
	 * Vrai si le dossier local se trouve dans le dossier public du site.
	 */
	public static function inside_site() {
		return 0 === strpos( wp_normalize_path( self::base_dir() ) . '/', wp_normalize_path( ABSPATH ) );
	}

	/**
	 * Sauvegardes présentes sur l'hébergement, de la plus récente à la plus ancienne.
	 */
	public static function local_archives() {
		$dir   = self::dir( 'archives' );
		$list  = array();
		$files = glob( $dir . '/*.tar.gz' );
		foreach ( $files ? $files : array() as $file ) {
			$name = basename( $file, '.tar.gz' );
			$meta = self::read_meta( $dir . '/' . $name . '.json' );
			$list[ $name ] = array(
				'name'    => $name,
				'file'    => $file,
				'size'    => filesize( $file ),
				'created' => isset( $meta['created'] ) ? (int) $meta['created'] : filemtime( $file ),
				'content' => isset( $meta['content'] ) ? $meta['content'] : ( false !== strpos( $name, '_db' ) ? 'db' : 'full' ),
				'meta'    => $meta,
			);
		}
		uasort(
			$list,
			function ( $a, $b ) {
				return $b['created'] - $a['created'];
			}
		);
		return $list;
	}

	public static function read_meta( $file ) {
		if ( ! is_file( $file ) ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $data ) ? $data : array();
	}

	public static function delete_archive( $name ) {
		$name = basename( $name );
		$dir  = self::dir( 'archives' );
		@unlink( $dir . '/' . $name . '.tar.gz' );
		@unlink( $dir . '/' . $name . '.json' );
	}

	public static function free_space() {
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( self::base_dir() ) : false;
		return false === $free ? null : (float) $free;
	}

	public static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = @scandir( $dir );
		foreach ( $items ? $items : array() as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::rrmdir( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}
}
