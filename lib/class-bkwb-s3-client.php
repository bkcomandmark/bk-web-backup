<?php
/**
 * Client S3 minimal (signature AWS v4) pour OVHcloud Object Storage et tout service compatible S3.
 *
 * Gère l'envoi simple, l'envoi en plusieurs parties (multipart), la liste des objets et le
 * téléchargement par plages. Chaque envoi porte un en-tête Content-MD5 : il est exigé par les
 * buckets protégés par Object Lock et garantit que les données n'ont pas été altérées en route.
 *
 * Aucune dépendance à WordPress (utilise l'extension curl) : ce fichier est aussi embarqué
 * dans le script de secours.
 */
class BKWB_S3_Exception extends RuntimeException {

	/** @var int Code HTTP (0 si la connexion a échoué). */
	public $status = 0;

	/** @var string Code d'erreur S3 (AccessDenied, NoSuchKey…). */
	public $s3_code = '';

	/** @var int Octets déjà reçus lors d'un téléchargement interrompu. */
	public $written = 0;

	public function __construct( $message, $status = 0, $s3_code = '' ) {
		parent::__construct( $message );
		$this->status  = (int) $status;
		$this->s3_code = (string) $s3_code;
	}
}

class BKWB_S3_Client {

	/** @var int Délai maximal d'une requête, en secondes. */
	public $timeout = 300;

	private $scheme;
	private $host;
	private $region;
	private $bucket;
	private $access;
	private $secret;

	public function __construct( $endpoint, $region, $bucket, $access_key, $secret_key ) {
		if ( ! function_exists( 'curl_init' ) ) {
			throw new BKWB_S3_Exception( "L'extension PHP curl est nécessaire pour envoyer les sauvegardes." );
		}
		$parts = parse_url( trim( $endpoint ) );
		if ( empty( $parts['host'] ) ) {
			throw new BKWB_S3_Exception( 'Adresse du serveur S3 (endpoint) invalide.' );
		}
		$this->scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
		$this->host   = strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		$this->region = trim( $region );
		$this->bucket = trim( $bucket );
		$this->access = trim( $access_key );
		$this->secret = trim( $secret_key );
	}

	public function put_object( $key, $body, $content_type = 'application/octet-stream' ) {
		$res = $this->request( 'PUT', $key, array(), array( 'content-type' => $content_type ), $body );
		return trim( $this->header( $res, 'etag' ), '"' );
	}

	public function create_multipart( $key ) {
		$res = $this->request( 'POST', $key, array( 'uploads' => '' ), array( 'content-type' => 'application/gzip' ) );
		if ( ! preg_match( '#<UploadId>([^<]+)</UploadId>#', $res['body'], $m ) ) {
			throw new BKWB_S3_Exception( "Réponse inattendue du serveur S3 à l'ouverture de l'envoi." );
		}
		return $this->xml_text( $m[1] );
	}

	public function upload_part( $key, $upload_id, $number, $body ) {
		$res  = $this->request( 'PUT', $key, array( 'partNumber' => (int) $number, 'uploadId' => $upload_id ), array(), $body );
		$etag = $this->header( $res, 'etag' );
		if ( '' === $etag ) {
			throw new BKWB_S3_Exception( "Le serveur S3 n'a pas confirmé la réception de la partie " . (int) $number . '.' );
		}
		return $etag;
	}

	/**
	 * @param array $etags Numéro de partie => ETag.
	 */
	public function complete_multipart( $key, $upload_id, array $etags ) {
		ksort( $etags );
		$xml = '<CompleteMultipartUpload>';
		foreach ( $etags as $number => $etag ) {
			$xml .= '<Part><PartNumber>' . (int) $number . '</PartNumber><ETag>' . htmlspecialchars( $etag, ENT_XML1 ) . '</ETag></Part>';
		}
		$xml .= '</CompleteMultipartUpload>';
		$res  = $this->request( 'POST', $key, array( 'uploadId' => $upload_id ), array( 'content-type' => 'application/xml' ), $xml );
		// Particularité S3 : une erreur peut arriver avec un code 200.
		if ( false !== strpos( $res['body'], '<Error>' ) ) {
			throw $this->error_from( 200, $res['body'] );
		}
	}

	public function abort_multipart( $key, $upload_id ) {
		$this->request( 'DELETE', $key, array( 'uploadId' => $upload_id ) );
	}

	/**
	 * Liste les objets sous un préfixe. Retourne une liste de [key, size, modified (timestamp)].
	 */
	public function list_objects( $prefix, $limit = 5000 ) {
		$items = array();
		$token = null;
		do {
			$query = array(
				'list-type' => 2,
				'prefix'    => $prefix,
				'max-keys'  => 1000,
			);
			if ( $token ) {
				$query['continuation-token'] = $token;
			}
			$res = $this->request( 'GET', '', $query );
			if ( preg_match_all( '#<Contents>(.*?)</Contents>#s', $res['body'], $all ) ) {
				foreach ( $all[1] as $c ) {
					preg_match( '#<Key>(.*?)</Key>#s', $c, $k );
					preg_match( '#<Size>(\d+)</Size>#', $c, $s );
					preg_match( '#<LastModified>([^<]+)</LastModified>#', $c, $d );
					$items[] = array(
						'key'      => isset( $k[1] ) ? $this->xml_text( $k[1] ) : '',
						'size'     => isset( $s[1] ) ? (int) $s[1] : 0,
						'modified' => isset( $d[1] ) ? strtotime( $d[1] ) : 0,
					);
				}
			}
			$token = null;
			if ( false !== strpos( $res['body'], '<IsTruncated>true</IsTruncated>' )
				&& preg_match( '#<NextContinuationToken>([^<]+)</NextContinuationToken>#', $res['body'], $t ) ) {
				$token = $this->xml_text( $t[1] );
			}
		} while ( $token && count( $items ) < $limit );
		return $items;
	}

	public function get_object( $key ) {
		$res = $this->request( 'GET', $key );
		return $res['body'];
	}

	/**
	 * Taille d'un objet en octets, ou null s'il n'existe pas.
	 */
	public function object_size( $key ) {
		try {
			$res = $this->request( 'HEAD', $key );
		} catch ( BKWB_S3_Exception $e ) {
			if ( 404 === $e->status ) {
				return null;
			}
			throw $e;
		}
		return (int) $this->header( $res, 'content-length' );
	}

	/**
	 * Télécharge les octets [$start, $end] d'un objet et les écrit dans $fh. Retourne le nombre d'octets reçus.
	 */
	public function download_range( $key, $start, $end, $fh ) {
		$res = $this->request( 'GET', $key, array(), array( 'range' => 'bytes=' . (int) $start . '-' . (int) $end ), '', $fh );
		return (int) $res['written'];
	}

	/**
	 * Requête signée (AWS Signature v4), avec jusqu'à 3 tentatives en cas d'erreur réseau ou serveur.
	 */
	private function request( $method, $key, array $query = array(), array $headers = array(), $body = '', $sink = null ) {
		$attempt = 0;
		while ( true ) {
			$attempt++;
			try {
				return $this->send( $method, $key, $query, $headers, (string) $body, $sink );
			} catch ( BKWB_S3_Exception $e ) {
				$retry = 0 === $e->status || $e->status >= 500 || 'RequestTimeout' === $e->s3_code || 'SlowDown' === $e->s3_code;
				if ( ! $retry || $attempt >= 3 || ( $sink && $e->written > 0 ) ) {
					throw $e;
				}
				sleep( $attempt * 2 );
			}
		}
	}

	private function send( $method, $key, array $query, array $headers, $body, $sink ) {
		$path = '/' . rawurlencode( $this->bucket );
		if ( '' !== $key ) {
			$path .= '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
		}
		$amz_date = gmdate( 'Ymd\THis\Z' );
		$date     = substr( $amz_date, 0, 8 );
		$hash     = hash( 'sha256', $body );

		$headers                         = array_change_key_case( $headers, CASE_LOWER );
		$headers['host']                 = $this->host;
		$headers['x-amz-date']           = $amz_date;
		$headers['x-amz-content-sha256'] = $hash;
		if ( '' !== $body ) {
			$headers['content-md5'] = base64_encode( md5( $body, true ) );
		}
		ksort( $headers );

		$canonical_headers = '';
		foreach ( $headers as $k => $v ) {
			$canonical_headers .= $k . ':' . trim( (string) $v ) . "\n";
		}
		$signed = implode( ';', array_keys( $headers ) );

		ksort( $query );
		$pairs = array();
		foreach ( $query as $k => $v ) {
			$pairs[] = rawurlencode( $k ) . '=' . rawurlencode( (string) $v );
		}
		$canonical_query = implode( '&', $pairs );

		$canonical = $method . "\n" . $path . "\n" . $canonical_query . "\n" . $canonical_headers . "\n" . $signed . "\n" . $hash;
		$scope     = $date . '/' . $this->region . '/s3/aws4_request';
		$to_sign   = "AWS4-HMAC-SHA256\n" . $amz_date . "\n" . $scope . "\n" . hash( 'sha256', $canonical );
		$k         = hash_hmac( 'sha256', $date, 'AWS4' . $this->secret, true );
		$k         = hash_hmac( 'sha256', $this->region, $k, true );
		$k         = hash_hmac( 'sha256', 's3', $k, true );
		$k         = hash_hmac( 'sha256', 'aws4_request', $k, true );
		$signature = hash_hmac( 'sha256', $to_sign, $k );

		$lines = array();
		foreach ( $headers as $k2 => $v ) {
			if ( 'host' !== $k2 ) {
				$lines[] = $k2 . ': ' . $v;
			}
		}
		$lines[] = 'Authorization: AWS4-HMAC-SHA256 Credential=' . $this->access . '/' . $scope . ', SignedHeaders=' . $signed . ', Signature=' . $signature;
		$lines[] = 'Expect:';
		if ( '' === $body && in_array( $method, array( 'PUT', 'POST' ), true ) ) {
			$lines[] = 'Content-Length: 0';
		}

		$url  = $this->scheme . '://' . $this->host . $path . ( '' !== $canonical_query ? '?' . $canonical_query : '' );
		$resp = array(
			'status'  => 0,
			'headers' => array(),
			'body'    => '',
			'written' => 0,
		);

		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $method );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $lines );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 20 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, $this->timeout );
		curl_setopt(
			$ch,
			CURLOPT_HEADERFUNCTION,
			function ( $c, $line ) use ( &$resp ) {
				if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $m ) ) {
					$resp['status']  = (int) $m[1];
					$resp['headers'] = array();
				} elseif ( false !== strpos( $line, ':' ) ) {
					list( $name, $value )                         = explode( ':', $line, 2 );
					$resp['headers'][ strtolower( trim( $name ) ) ] = trim( $value );
				}
				return strlen( $line );
			}
		);
		curl_setopt(
			$ch,
			CURLOPT_WRITEFUNCTION,
			function ( $c, $data ) use ( &$resp, $sink ) {
				if ( $sink && $resp['status'] >= 200 && $resp['status'] < 300 ) {
					$n                = fwrite( $sink, $data );
					$resp['written'] += (int) $n;
					return (int) $n;
				}
				$resp['body'] .= $data;
				return strlen( $data );
			}
		);
		if ( 'HEAD' === $method ) {
			curl_setopt( $ch, CURLOPT_NOBODY, true );
		}
		if ( '' !== $body ) {
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
		}

		$ok    = curl_exec( $ch );
		$errno = curl_errno( $ch );
		$error = curl_error( $ch );
		if ( PHP_VERSION_ID < 80000 ) {
			curl_close( $ch ); // Sans effet depuis PHP 8.0, obsolète depuis PHP 8.5.
		}
		unset( $ch );

		if ( false === $ok || $errno ) {
			$e          = new BKWB_S3_Exception( 'Connexion au stockage S3 impossible : ' . $error, 0 );
			$e->written = $resp['written'];
			throw $e;
		}
		if ( $resp['status'] < 200 || $resp['status'] >= 300 ) {
			throw $this->error_from( $resp['status'], $resp['body'] );
		}
		return $resp;
	}

	private function error_from( $status, $body ) {
		$code    = preg_match( '#<Code>([^<]+)</Code>#', $body, $m ) ? $m[1] : '';
		$message = preg_match( '#<Message>([^<]*)</Message>#', $body, $m2 ) ? $this->xml_text( $m2[1] ) : '';
		$labels  = array(
			'AccessDenied'          => 'accès refusé (vérifiez la clé et ses droits sur le bucket)',
			'InvalidAccessKeyId'    => "clé d'accès inconnue",
			'SignatureDoesNotMatch' => 'clé secrète incorrecte',
			'NoSuchBucket'          => 'bucket introuvable (vérifiez son nom et la région)',
			'NoSuchKey'             => 'fichier introuvable sur le stockage',
			'NoSuchUpload'          => 'envoi en plusieurs parties expiré ou annulé',
			'EntityTooSmall'        => 'partie trop petite',
		);
		$label = isset( $labels[ $code ] ) ? $labels[ $code ] : ( '' !== $message ? $message : 'erreur HTTP ' . $status );
		return new BKWB_S3_Exception( 'Stockage S3 : ' . $label . ( $code ? ' (' . $code . ')' : '' ) . '.', $status, $code );
	}

	private function header( array $res, $name ) {
		return isset( $res['headers'][ $name ] ) ? $res['headers'][ $name ] : '';
	}

	private function xml_text( $s ) {
		return html_entity_decode( $s, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}
}
