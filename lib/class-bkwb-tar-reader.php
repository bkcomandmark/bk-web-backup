<?php
/**
 * Lecture d'une archive .tar.gz par morceaux, reprenable d'une requête à l'autre.
 *
 * Les archives de BK WEB Backup sont faites de plusieurs membres gzip. Le lecteur mémorise le
 * début du membre en cours : à la reprise, il repart de ce membre et saute les octets déjà
 * traités, au lieu de tout décompresser depuis le début. Fonctionne aussi avec une archive
 * .tar.gz ordinaire (un seul membre) : la reprise est alors simplement plus lente.
 *
 * Le traitement des entrées est délégué à un objet « handler » :
 *   start( array $entry ) : bool  — début d'une entrée (false = l'ignorer) ;
 *   resume( array $entry )        — reprise d'une entrée commencée lors d'une requête précédente ;
 *   data( string $bytes )         — contenu ;
 *   end( array $entry )           — fin de l'entrée.
 * Une entrée contient : name, type ('0' fichier, '5' dossier), size, done, mode, mtime.
 *
 * Aucune dépendance à WordPress : ce fichier est aussi embarqué dans le script de secours.
 */
class BKWB_Tar_Reader {

	const READ = 65536;

	/** @var array État à conserver entre deux requêtes. */
	public $state;

	private $path;
	private $hbuf = '';

	public function __construct( $path, array $state = array() ) {
		$this->path  = $path;
		$this->state = array_merge(
			array(
				'm_c'      => 0,     // Position compressée du début du membre gzip courant.
				'm_u'      => 0,     // Position non compressée au début de ce membre.
				'pos'      => 0,     // Octets non compressés déjà traités.
				'entry'    => null,  // Entrée en cours.
				'pad'      => 0,     // Octets de bourrage restant à sauter.
				'longname' => null,  // Nom long (GNU LongLink) en attente.
				'finished' => false,
			),
			$state
		);
	}

	/**
	 * Lit jusqu'à la fin de l'archive ou jusqu'à $deadline (horodatage microtime, 0 = sans limite).
	 * Retourne true quand l'archive a été entièrement lue.
	 */
	public function run( $handler, $deadline = 0 ) {
		if ( $this->state['finished'] ) {
			return true;
		}
		$fh = @fopen( $this->path, 'rb' );
		if ( ! $fh ) {
			throw new RuntimeException( "Impossible d'ouvrir l'archive : " . $this->path );
		}
		fseek( $fh, $this->state['m_c'] );

		$entry = $this->state['entry'];
		if ( $entry && empty( $entry['skip'] ) && empty( $entry['capture'] ) ) {
			$handler->resume( $entry );
		}

		$ctx      = $this->new_inflate();
		$fed      = 0;                    // Octets compressés donnés au membre courant.
		$member_c = $this->state['m_c'];
		$u        = $this->state['m_u'];  // Position non compressée du prochain octet produit.
		$skip_to    = $this->state['pos'];
		$progressed = false;
		$this->hbuf = '';

		while ( true ) {
			$chunk = fread( $fh, self::READ );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$at = 0;
			$n  = strlen( $chunk );
			while ( $at < $n ) {
				$piece = $at ? substr( $chunk, $at ) : $chunk;
				$out   = @inflate_add( $ctx, $piece, ZLIB_SYNC_FLUSH );
				if ( false === $out ) {
					fclose( $fh );
					throw new RuntimeException( 'Archive corrompue (décompression impossible).' );
				}
				$ended = ( ZLIB_STREAM_END === inflate_get_status( $ctx ) );
				if ( $ended ) {
					$total    = inflate_get_read_len( $ctx );
					$consumed = $total - $fed;
					$fed      = $total;
				} else {
					$consumed = strlen( $piece );
					$fed     += $consumed;
				}
				$at += $consumed;

				// Traitement des octets produits, en sautant ceux déjà traités.
				$len = strlen( $out );
				if ( $len ) {
					if ( $u + $len > $skip_to ) {
						$progressed = true;
						$start      = $u < $skip_to ? $skip_to - $u : 0;
						$done  = $this->process( $start ? substr( $out, $start ) : $out, $u + $start, $handler, $deadline );
						if ( true !== $done ) {
							// Temps écoulé : on s'arrête à une position propre. m_c et m_u désignent
							// déjà le membre en cours : la reprise repartira de son début.
							$this->state['pos'] = $done;
							fclose( $fh );
							return false;
						}
						$skip_to = $u + $len;
					}
					$u += $len;
				}
				if ( $this->state['finished'] ) {
					fclose( $fh );
					return true;
				}
				if ( $ended ) {
					// Fin d'un membre gzip : le suivant commence juste après.
					$member_c             = $member_c + $fed;
					$this->state['m_c']   = $member_c;
					$this->state['m_u']   = $u;
					$this->state['pos']   = max( $this->state['pos'], $u );
					$ctx                  = $this->new_inflate();
					$fed                  = 0;
				}
				// Temps écoulé : arrêt entre deux morceaux, si aucun en-tête n'est à moitié lu
				// et que cette tranche a fait avancer la lecture.
				if ( $deadline && $progressed && microtime( true ) >= $deadline && '' === $this->hbuf ) {
					$this->state['pos'] = $u;
					fclose( $fh );
					return false;
				}
			}
		}
		fclose( $fh );
		if ( ! $this->state['finished'] ) {
			throw new RuntimeException( 'Archive incomplète : la fin du fichier a été atteinte avant la fin de la sauvegarde.' );
		}
		return true;
	}

	private function new_inflate() {
		$ctx = inflate_init( ZLIB_ENCODING_GZIP );
		if ( false === $ctx ) {
			throw new RuntimeException( 'Décompression gzip indisponible sur ce serveur.' );
		}
		return $ctx;
	}

	/**
	 * Fait avancer l'automate tar. Retourne true si tout $data a été traité,
	 * sinon la position absolue (non compressée) où l'on s'est arrêté.
	 */
	private function process( $data, $abs, $handler, $deadline ) {
		$n = strlen( $data );
		$i = 0;
		while ( $i < $n ) {
			if ( $this->state['finished'] ) {
				return true;
			}
			$entry = $this->state['entry'];

			// Contenu d'une entrée.
			if ( $entry && $entry['done'] < $entry['size'] ) {
				$take = (int) min( $n - $i, $entry['size'] - $entry['done'] );
				$part = substr( $data, $i, $take );
				if ( ! empty( $entry['capture'] ) ) {
					$this->state['longname'] .= $part;
				} elseif ( empty( $entry['skip'] ) ) {
					$handler->data( $part );
				}
				$entry['done']         += $take;
				$i                     += $take;
				$this->state['entry']   = $entry;
				if ( $entry['done'] >= $entry['size'] ) {
					$this->end_entry( $handler );
				}
				if ( $deadline && microtime( true ) >= $deadline && $i < $n ) {
					return $abs + $i;
				}
				continue;
			}

			// Bourrage jusqu'au bloc de 512 octets suivant.
			if ( $this->state['pad'] > 0 ) {
				$take                 = (int) min( $n - $i, $this->state['pad'] );
				$this->state['pad']  -= $take;
				$i                   += $take;
				continue;
			}

			// En-tête de 512 octets (peut être à cheval sur deux morceaux).
			$take        = min( 512 - strlen( $this->hbuf ), $n - $i );
			$this->hbuf .= substr( $data, $i, $take );
			$i          += $take;
			if ( strlen( $this->hbuf ) < 512 ) {
				continue;
			}
			$header     = $this->hbuf;
			$this->hbuf = '';
			$this->parse_header( $header, $handler );
			if ( $deadline && microtime( true ) >= $deadline && $i < $n && ! $this->state['finished'] ) {
				return $abs + $i;
			}
		}
		return true;
	}

	private function end_entry( $handler ) {
		$entry = $this->state['entry'];
		if ( ! empty( $entry['capture'] ) ) {
			$this->state['longname'] = rtrim( $this->state['longname'], "\0" );
		} elseif ( empty( $entry['skip'] ) ) {
			$handler->end( $entry );
		}
		$rest                 = $entry['size'] % 512;
		$this->state['pad']   = $rest ? 512 - $rest : 0;
		$this->state['entry'] = null;
		// Le handler peut demander d'arrêter la lecture (ex. seule la base était demandée).
		if ( ! empty( $handler->stop ) ) {
			$this->state['finished'] = true;
		}
	}

	private function parse_header( $h, $handler ) {
		if ( str_repeat( "\0", 512 ) === $h ) {
			$this->state['finished'] = true;
			return;
		}
		$stored = octdec( trim( substr( $h, 148, 8 ), "\0 " ) );
		$sum    = 0;
		for ( $i = 0; $i < 512; $i++ ) {
			$sum += ( $i >= 148 && $i < 156 ) ? 32 : ord( $h[ $i ] );
		}
		if ( $sum !== (int) $stored ) {
			throw new RuntimeException( 'Archive corrompue (en-tête invalide).' );
		}

		$name = rtrim( substr( $h, 0, 100 ), "\0" );
		if ( 'ustar' === substr( $h, 257, 5 ) ) {
			$prefix = rtrim( substr( $h, 345, 155 ), "\0" );
			if ( '' !== $prefix ) {
				$name = $prefix . '/' . $name;
			}
		}
		$type = $h[156];
		$size = $this->parse_size( substr( $h, 124, 12 ) );

		if ( 'L' === $type ) {
			$this->state['longname'] = '';
			$this->state['entry']    = array(
				'type'    => 'L',
				'size'    => $size,
				'done'    => 0,
				'capture' => true,
			);
			if ( 0 === $size ) {
				$this->end_entry( $handler );
			}
			return;
		}
		if ( null !== $this->state['longname'] ) {
			$name                    = $this->state['longname'];
			$this->state['longname'] = null;
		}
		if ( "\0" === $type ) {
			$type = '0';
		}
		// Liens et fichiers spéciaux : pas de contenu stocké.
		if ( in_array( $type, array( '1', '2', '3', '4', '5', '6' ), true ) ) {
			$size = 0;
		}
		$entry = array(
			'name'  => $name,
			'type'  => $type,
			'size'  => $size,
			'done'  => 0,
			'mode'  => octdec( trim( substr( $h, 100, 8 ), "\0 " ) ),
			'mtime' => octdec( trim( substr( $h, 136, 12 ), "\0 " ) ),
		);
		$wanted        = in_array( $type, array( '0', '7', '5' ), true );
		$entry['skip'] = ! $wanted || ! $handler->start( $entry );

		$this->state['entry'] = $entry;
		if ( 0 === $size ) {
			$this->end_entry( $handler );
		}
	}

	private function parse_size( $field ) {
		if ( ord( $field[0] ) & 0x80 ) {
			$value = 0;
			for ( $i = 1; $i < 12; $i++ ) {
				$value = $value * 256 + ord( $field[ $i ] );
			}
			return $value;
		}
		return (int) octdec( trim( $field, "\0 " ) );
	}
}
