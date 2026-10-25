<?php
/**
 * État de la tâche en cours (sauvegarde ou restauration), conservé dans un fichier du dossier
 * local et non dans la base : il survit ainsi à la restauration de la base de données.
 * Il est sérialisé avec serialize() plutôt qu'en JSON, car il contient des chemins de fichiers
 * qui ne sont pas toujours en UTF-8 valide.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BKWB_Jobs {

	private static $lock = null;

	public static function file() {
		return BKWB_Storage::dir( 'work' ) . '/job.dat';
	}

	public static function load() {
		$file = self::file();
		if ( ! is_file( $file ) ) {
			return null;
		}
		$data = @unserialize( (string) file_get_contents( $file ), array( 'allowed_classes' => false ) );
		return is_array( $data ) ? $data : null;
	}

	public static function save( array $state ) {
		$file = self::file();
		$tmp  = $file . '.tmp';
		if ( false === file_put_contents( $tmp, serialize( $state ) ) || ! rename( $tmp, $file ) ) {
			throw new RuntimeException( "Impossible d'enregistrer l'état de la tâche (espace disque plein ?)." );
		}
	}

	public static function clear() {
		@unlink( self::file() );
	}

	public static function is_finished( $state ) {
		return in_array( $state['status'], array( 'done', 'failed', 'cancelled' ), true );
	}

	/**
	 * Tâche en cours (non terminée), ou null.
	 */
	public static function current() {
		$state = self::load();
		return ( $state && ! self::is_finished( $state ) ) ? $state : null;
	}

	/**
	 * Verrou exclusif (flock) : une seule requête fait avancer la tâche à la fois.
	 * Il est libéré automatiquement si le processus s'arrête brutalement.
	 */
	public static function lock() {
		$fh = @fopen( BKWB_Storage::dir( 'work' ) . '/lock', 'c' );
		if ( ! $fh ) {
			return false;
		}
		if ( ! flock( $fh, LOCK_EX | LOCK_NB ) ) {
			fclose( $fh );
			return false;
		}
		self::$lock = $fh;
		return true;
	}

	public static function unlock() {
		if ( self::$lock ) {
			flock( self::$lock, LOCK_UN );
			fclose( self::$lock );
			self::$lock = null;
		}
	}

	/**
	 * Vide le dossier de travail (sauf l'état et le verrou).
	 */
	public static function clean_work( $keep_state = true ) {
		$dir   = BKWB_Storage::dir( 'work' );
		$items = @scandir( $dir );
		foreach ( $items ? $items : array() as $item ) {
			if ( in_array( $item, array( '.', '..', '.htaccess', 'index.php', 'web.config', 'lock' ), true ) ) {
				continue;
			}
			if ( $keep_state && 'job.dat' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				BKWB_Storage::rrmdir( $path );
			} else {
				@unlink( $path );
			}
		}
	}
}
