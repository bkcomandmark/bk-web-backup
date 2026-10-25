<?php
/**
 * Historique des sauvegardes et restaurations (option WordPress) et journal technique (fichier).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BKWB_Log {

	const HISTORY = 'bkwb_history';
	const KEEP    = 100;

	/**
	 * Ajoute une entrée : type (backup, restore), status (success, partial, failed, cancelled),
	 * started, finished, name, size, remote, trigger, error, warnings.
	 */
	public static function add_history( array $entry ) {
		$list = self::history();
		array_unshift( $list, $entry );
		update_option( self::HISTORY, array_slice( $list, 0, self::KEEP ), false );
	}

	public static function history() {
		$list = get_option( self::HISTORY, array() );
		return is_array( $list ) ? $list : array();
	}

	public static function last_backup( $status = null ) {
		foreach ( self::history() as $h ) {
			if ( 'backup' !== $h['type'] ) {
				continue;
			}
			if ( null === $status || $h['status'] === $status ) {
				return $h;
			}
		}
		return null;
	}

	/**
	 * Dernière sauvegarde réussie, copie distante comprise si le stockage S3 est configuré.
	 */
	public static function last_success() {
		foreach ( self::history() as $h ) {
			if ( 'backup' === $h['type'] && 'success' === $h['status'] ) {
				return $h;
			}
		}
		return null;
	}

	public static function file() {
		return BKWB_Storage::base_dir() . '/bkwb.log';
	}

	public static function write( $message ) {
		$file = self::file();
		if ( @filesize( $file ) > 2 * MB_IN_BYTES ) {
			@rename( $file, $file . '.1' );
		}
		@file_put_contents( $file, '[' . wp_date( 'Y-m-d H:i:s' ) . '] ' . $message . "\n", FILE_APPEND );
	}

	public static function tail( $lines = 60 ) {
		$file = self::file();
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$size = filesize( $file );
		$fh   = fopen( $file, 'rb' );
		fseek( $fh, max( 0, $size - 32768 ) );
		$data = fread( $fh, 32768 );
		fclose( $fh );
		$all = array_filter( explode( "\n", (string) $data ) );
		return array_slice( $all, -$lines );
	}
}
