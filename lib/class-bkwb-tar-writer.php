<?php
/**
 * Écriture d'une archive .tar.gz par petits morceaux, reprenable d'une requête à l'autre.
 *
 * L'archive est une suite de membres gzip indépendants (format standard : gzip et tar la lisent
 * comme un seul flux). Un membre est fermé à la fin de chaque requête et tous les MEMBER_SIZE
 * octets. L'état mémorise la taille du fichier au dernier membre complet : si une requête est
 * coupée, on tronque l'archive à cette taille et on reprend proprement.
 *
 * Aucune dépendance à WordPress : ce fichier est aussi embarqué dans le script de secours.
 */
class BKWB_Tar_Writer {

	const MEMBER_SIZE = 33554432; // 32 Mo non compressés par membre gzip.
	const CHUNK       = 1048576;  // Lecture des fichiers par blocs de 1 Mo.

	/** @var array État à conserver entre deux requêtes. */
	public $state;

	private $fh;
	private $ctx          = null;
	private $level;
	private $member_bytes = 0;

	public function __construct( $path, array $state = array(), $level = 5 ) {
		$this->level = (int) $level;
		$this->state = array_merge(
			array(
				'c_size'  => 0,    // Taille de l'archive au dernier membre gzip complet.
				'u_off'   => 0,    // Octets non compressés écrits (position dans le flux tar).
				'current' => null, // Fichier en cours d'écriture : src, size, written.
			),
			$state
		);
		$fh = @fopen( $path, 'c+b' );
		if ( ! $fh ) {
			throw new RuntimeException( "Impossible d'ouvrir l'archive : " . $path );
		}
		ftruncate( $fh, $this->state['c_size'] );
		fseek( $fh, $this->state['c_size'] );
		$this->fh = $fh;
	}

	/**
	 * Ajoute un petit fichier dont le contenu est en mémoire.
	 */
	public function add_string( $name, $data, $mtime = null ) {
		$this->write( $this->header( $name, strlen( $data ), null === $mtime ? time() : $mtime, 0644, '0' ) );
		$this->write( $data );
		$this->write( $this->padding( strlen( $data ) ) );
	}

	/**
	 * Ajoute un dossier (entrée vide).
	 */
	public function add_dir( $name, $mtime, $mode = 0755 ) {
		$this->write( $this->header( rtrim( $name, '/' ) . '/', 0, $mtime, $mode, '5' ) );
	}

	/**
	 * Commence l'ajout d'un fichier du disque. Le contenu est écrit par continue_file().
	 */
	public function begin_file( $name, $src, $size, $mtime, $mode = 0644 ) {
		$this->write( $this->header( $name, $size, $mtime, $mode, '0' ) );
		$this->state['current'] = array(
			'src'     => $src,
			'size'    => (int) $size,
			'written' => 0,
		);
	}

	/**
	 * Écrit la suite du fichier en cours. Retourne true quand il est complet,
	 * false si le temps imparti ($deadline, horodatage microtime) est écoulé.
	 */
	public function continue_file( $deadline = 0 ) {
		$cur = $this->state['current'];
		if ( ! $cur ) {
			return true;
		}
		$fh = @fopen( $cur['src'], 'rb' );
		if ( $fh && $cur['written'] > 0 ) {
			fseek( $fh, $cur['written'] );
		}
		while ( $cur['written'] < $cur['size'] ) {
			$len  = (int) min( self::CHUNK, $cur['size'] - $cur['written'] );
			$data = $fh ? fread( $fh, $len ) : '';
			if ( false === $data ) {
				$data = '';
			}
			if ( strlen( $data ) < $len ) {
				// Fichier disparu ou raccourci pendant la sauvegarde : on complète avec des zéros
				// pour que l'archive reste valide (la taille annoncée dans l'en-tête doit être respectée).
				$data .= str_repeat( "\0", $len - strlen( $data ) );
			}
			$this->write( $data );
			$cur['written']         += $len;
			$this->state['current'] = $cur;
			if ( $deadline && microtime( true ) >= $deadline && $cur['written'] < $cur['size'] ) {
				if ( $fh ) {
					fclose( $fh );
				}
				return false;
			}
		}
		if ( $fh ) {
			fclose( $fh );
		}
		$this->write( $this->padding( $cur['size'] ) );
		$this->state['current'] = null;
		return true;
	}

	/**
	 * Termine l'archive (deux blocs vides, fin du format tar).
	 */
	public function finish() {
		$this->write( str_repeat( "\0", 1024 ) );
		$this->end_member();
	}

	/**
	 * Ferme proprement le membre gzip en cours et le fichier. Retourne l'état à conserver.
	 */
	public function close() {
		$this->end_member();
		if ( $this->fh ) {
			fclose( $this->fh );
			$this->fh = null;
		}
		return $this->state;
	}

	private function write( $data ) {
		if ( '' === $data ) {
			return;
		}
		if ( null === $this->ctx ) {
			$this->ctx = deflate_init( ZLIB_ENCODING_GZIP, array( 'level' => $this->level ) );
			if ( false === $this->ctx ) {
				throw new RuntimeException( 'Compression gzip indisponible sur ce serveur.' );
			}
			$this->member_bytes = 0;
		}
		$this->put( deflate_add( $this->ctx, $data, ZLIB_NO_FLUSH ) );
		$this->state['u_off'] += strlen( $data );
		$this->member_bytes   += strlen( $data );
		if ( $this->member_bytes >= self::MEMBER_SIZE ) {
			$this->end_member();
		}
	}

	private function end_member() {
		if ( null === $this->ctx ) {
			return;
		}
		$this->put( deflate_add( $this->ctx, '', ZLIB_FINISH ) );
		$this->ctx = null;
		fflush( $this->fh );
		$this->state['c_size'] = ftell( $this->fh );
	}

	private function put( $bytes ) {
		if ( '' === $bytes || false === $bytes ) {
			return;
		}
		$n = fwrite( $this->fh, $bytes );
		if ( strlen( $bytes ) !== $n ) {
			throw new RuntimeException( "Écriture impossible dans l'archive (espace disque plein ?)." );
		}
	}

	private function padding( $size ) {
		$rest = $size % 512;
		return $rest ? str_repeat( "\0", 512 - $rest ) : '';
	}

	/**
	 * En-tête tar (ustar). Les chemins trop longs passent par un préfixe ustar,
	 * ou à défaut par une entrée GNU « LongLink » (lue par GNU tar et bsdtar).
	 */
	private function header( $name, $size, $mtime, $mode, $type ) {
		$out    = '';
		$prefix = '';
		if ( strlen( $name ) > 100 ) {
			$split = $this->split_ustar( $name );
			if ( $split ) {
				list( $prefix, $name ) = $split;
			} else {
				$out .= $this->raw_header( '././@LongLink', strlen( $name ) + 1, 0, 0644, 'L', '' );
				$out .= $name . "\0" . $this->padding( strlen( $name ) + 1 );
				$name = substr( $name, 0, 100 );
			}
		}
		return $out . $this->raw_header( $name, $size, $mtime, $mode, $type, $prefix );
	}

	private function split_ustar( $name ) {
		$len = strlen( $name );
		$pos = -1;
		while ( false !== ( $pos = strpos( $name, '/', $pos + 1 ) ) ) {
			$rest = $len - $pos - 1;
			if ( $pos > 155 ) {
				return null;
			}
			if ( $rest > 0 && $rest <= 100 ) {
				return array( substr( $name, 0, $pos ), substr( $name, $pos + 1 ) );
			}
		}
		return null;
	}

	private function raw_header( $name, $size, $mtime, $mode, $type, $prefix ) {
		$h = pack(
			'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
			$name,
			$this->octal( $mode & 07777, 8 ),
			$this->octal( 0, 8 ),
			$this->octal( 0, 8 ),
			$this->size_field( $size ),
			$this->octal( max( 0, (int) $mtime ), 12 ),
			'        ',
			$type,
			'',
			"ustar\0",
			'00',
			'',
			'',
			'',
			'',
			$prefix,
			''
		);
		$sum = 0;
		for ( $i = 0; $i < 512; $i++ ) {
			$sum += ord( $h[ $i ] );
		}
		return substr_replace( $h, sprintf( '%06o', $sum ) . "\0 ", 148, 8 );
	}

	private function octal( $value, $len ) {
		return sprintf( '%0' . ( $len - 1 ) . 'o', $value ) . "\0";
	}

	private function size_field( $size ) {
		if ( $size <= 8589934591 ) { // 077777777777 : limite du champ octal.
			return $this->octal( $size, 12 );
		}
		// Fichiers de plus de 8 Go : encodage binaire (base 256) reconnu par GNU tar et bsdtar.
		$bytes = '';
		for ( $i = 0; $i < 11; $i++ ) {
			$bytes = chr( $size & 0xFF ) . $bytes;
			$size  = intdiv( $size, 256 );
		}
		return chr( 0x80 ) . $bytes;
	}
}
