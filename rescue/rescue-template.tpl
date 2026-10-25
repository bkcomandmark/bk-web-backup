<?php
/**
 * BK WEB Backup — script de secours.
 *
 * À utiliser quand WordPress ne fonctionne plus du tout. Ce fichier restaure une sauvegarde
 * .tar.gz de BK WEB Backup sans passer par WordPress :
 *   1. Envoyez ce fichier à la racine du site (à côté de wp-config.php), par FTP ou par le
 *      gestionnaire de fichiers de cPanel.
 *   2. Placez l'archive .tar.gz à côté, ou laissez-la dans le dossier local de BK WEB Backup.
 *   3. Ouvrez https://votre-site/bkwb-secours.php et saisissez le mot de passe choisi.
 *   4. SUPPRIMEZ ce fichier une fois la restauration terminée.
 *
 * En ligne de commande (SSH ou terminal cPanel) :
 *   php bkwb-secours.php chemin/vers/archive.tar.gz [--sans-base] [--sans-fichiers] [--avec-wp-config]
 *
 * Généré le %%GENERATED%% pour %%SITE%%. Valable jusqu'au %%EXPIRES_HUMAN%%.
 */

define( 'BKWB_RESCUE_HASH', %%PASSWORD_HASH%% );
define( 'BKWB_RESCUE_EXPIRES', %%EXPIRES%% );
define( 'BKWB_RESCUE_LOCAL_DIR', %%LOCAL_DIR%% );
define( 'BKWB_RESCUE_SITE', %%SITE_EXPORT%% );

error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE );
@ini_set( 'display_errors', '0' );

$bkwb_root  = __DIR__;
$bkwb_state = $bkwb_root . '/bkwb-secours-state.dat';

if ( 'cli' === PHP_SAPI ) {
	bkwb_rescue_cli( $argv );
	exit;
}

if ( time() > BKWB_RESCUE_EXPIRES ) {
	bkwb_rescue_page( 'Script expiré', '<p>Ce script de secours a expiré. Téléchargez-en un nouveau depuis le tableau de bord de BK WEB Backup, ou utilisez une copie fraîche.</p><p><strong>Supprimez ce fichier du serveur.</strong></p>' );
	exit;
}

session_name( 'bkwb_secours' );
session_start();

// Connexion.
if ( empty( $_SESSION['bkwb_ok'] ) ) {
	$error = '';
	if ( isset( $_POST['password'] ) ) {
		if ( password_verify( (string) $_POST['password'], BKWB_RESCUE_HASH ) ) {
			session_regenerate_id( true );
			$_SESSION['bkwb_ok']   = true;
			$_SESSION['bkwb_csrf'] = bin2hex( random_bytes( 16 ) );
			header( 'Location: ' . bkwb_rescue_self() );
			exit;
		}
		sleep( 2 );
		$error = '<p class="err">Mot de passe incorrect.</p>';
	}
	bkwb_rescue_page(
		'Connexion',
		$error . '<form method="post"><p><label>Mot de passe du script de secours<br><input type="password" name="password" autofocus required></label></p><p><button>Se connecter</button></p></form>'
	);
	exit;
}

$csrf = $_SESSION['bkwb_csrf'];

// Suppression du script.
if ( isset( $_POST['delete'] ) && hash_equals( $csrf, (string) $_POST['csrf'] ) ) {
	@unlink( $bkwb_state );
	$ok = @unlink( __FILE__ );
	session_destroy();
	bkwb_rescue_page( 'Terminé', $ok ? '<p>Le script de secours a été supprimé du serveur.</p>' : '<p class="err">Suppression impossible : supprimez <code>' . bkwb_h( basename( __FILE__ ) ) . '</code> par FTP.</p>' );
	exit;
}

// Lancement d'une restauration.
if ( isset( $_POST['start'] ) && hash_equals( $csrf, (string) $_POST['csrf'] ) ) {
	$archives = bkwb_rescue_archives();
	$pick     = isset( $_POST['archive'] ) ? (string) $_POST['archive'] : '';
	if ( ! isset( $archives[ $pick ] ) ) {
		bkwb_rescue_page( 'Erreur', '<p class="err">Archive introuvable.</p><p><a href="' . bkwb_h( bkwb_rescue_self() ) . '">Retour</a></p>' );
		exit;
	}
	if ( 'RESTAURER' !== trim( (string) $_POST['confirm'] ) ) {
		bkwb_rescue_page( 'Confirmation manquante', '<p class="err">Tapez RESTAURER pour confirmer.</p><p><a href="' . bkwb_h( bkwb_rescue_self() ) . '">Retour</a></p>' );
		exit;
	}
	$state = bkwb_rescue_new_state( $archives[ $pick ]['path'], ! empty( $_POST['files'] ), ! empty( $_POST['db'] ), ! empty( $_POST['wpconfig'] ) );
	bkwb_rescue_save( $state );
	header( 'Location: ' . bkwb_rescue_self() . '?run=1' );
	exit;
}

$state = bkwb_rescue_load();

// Restauration en cours : une tranche de travail par requête, puis rechargement automatique.
if ( $state && 'done' !== $state['step'] && 'failed' !== $state['step'] ) {
	if ( isset( $_GET['run'] ) ) {
		$budget = (int) ini_get( 'max_execution_time' );
		@set_time_limit( 120 );
		$budget = $budget > 0 ? max( 8, min( 25, $budget - 8 ) ) : 25;
		ignore_user_abort( true );
		bkwb_rescue_run( $state, microtime( true ) + $budget );
		bkwb_rescue_save( $state );
	}
	if ( 'done' !== $state['step'] && 'failed' !== $state['step'] ) {
		bkwb_rescue_page(
			'Restauration en cours',
			'<p>' . bkwb_h( $state['label'] ) . '…</p><progress max="100" value="' . (int) $state['progress'] . '"></progress> ' . (int) $state['progress'] . ' %'
			. '<p>Ne fermez pas cette page : elle se recharge toute seule.</p>',
			'<meta http-equiv="refresh" content="1;url=' . bkwb_h( bkwb_rescue_self() ) . '?run=1">'
		);
		exit;
	}
}

if ( $state && in_array( $state['step'], array( 'done', 'failed' ), true ) ) {
	$body = 'done' === $state['step']
		? '<p class="ok">Restauration terminée : ' . (int) $state['files'] . ' fichiers' . ( $state['db'] ? ', base de données importée (' . (int) $state['statements'] . ' instructions)' : '' ) . '.</p>'
		: '<p class="err">La restauration a échoué : ' . bkwb_h( $state['error'] ) . '</p>';
	if ( $state['warnings'] ) {
		$body .= '<details><summary>Avertissements</summary><ul><li>' . implode( '</li><li>', array_map( 'bkwb_h', $state['warnings'] ) ) . '</li></ul></details>';
	}
	$body .= '<p>Vérifiez le site, videz le cache éventuel, puis supprimez ce script.</p>';
	$body .= '<form method="post"><input type="hidden" name="csrf" value="' . bkwb_h( $csrf ) . '"><button name="delete" value="1" class="danger">Supprimer le script de secours maintenant</button></form>';
	$body .= '<p><a href="' . bkwb_h( bkwb_rescue_self() ) . '?new=1">Lancer une autre restauration</a></p>';
	if ( isset( $_GET['new'] ) ) {
		@unlink( $bkwb_state );
		header( 'Location: ' . bkwb_rescue_self() );
		exit;
	}
	bkwb_rescue_page( 'done' === $state['step'] ? 'Restauration terminée' : 'Échec de la restauration', $body );
	exit;
}

// Choix de l'archive.
$archives = bkwb_rescue_archives();
$config   = bkwb_rescue_db_config( $bkwb_root . '/wp-config.php' );
$body     = '<p>Site : <strong>' . bkwb_h( BKWB_RESCUE_SITE ) . '</strong> — dossier : <code>' . bkwb_h( $bkwb_root ) . '</code></p>';
if ( ! $config ) {
	$body .= '<p class="err">wp-config.php introuvable ou illisible : seuls les fichiers pourront être restaurés (la restauration remettra aussi wp-config.php).</p>';
}
if ( ! $archives ) {
	$body .= '<p class="err">Aucune archive .tar.gz trouvée. Déposez-la à côté de ce script (téléchargée depuis le stockage OVH), puis rechargez la page.</p>';
} else {
	$body .= '<form method="post"><input type="hidden" name="csrf" value="' . bkwb_h( $csrf ) . '"><table><tr><th></th><th>Archive</th><th>Taille</th><th>Emplacement</th></tr>';
	$first = true;
	foreach ( $archives as $id => $a ) {
		$body .= '<tr><td><input type="radio" name="archive" value="' . bkwb_h( $id ) . '"' . ( $first ? ' checked' : '' ) . '></td><td>' . bkwb_h( basename( $a['path'] ) ) . '</td><td>' . bkwb_size( $a['size'] ) . '</td><td><code>' . bkwb_h( dirname( $a['path'] ) ) . '</code></td></tr>';
		$first = false;
	}
	$body .= '</table>';
	$body .= '<p><label><input type="checkbox" name="files" value="1" checked> Restaurer les fichiers</label><br>';
	$body .= '<label><input type="checkbox" name="db" value="1"' . ( $config ? ' checked' : ' disabled' ) . '> Restaurer la base de données</label><br>';
	$body .= '<label><input type="checkbox" name="wpconfig" value="1"' . ( $config ? '' : ' checked' ) . '> Remplacer aussi wp-config.php</label></p>';
	$body .= '<p>Les fichiers et la base actuels seront écrasés. Tapez <strong>RESTAURER</strong> pour confirmer :<br><input name="confirm" autocomplete="off" required></p>';
	$body .= '<p><button name="start" value="1" class="danger">Lancer la restauration</button></p></form>';
}
bkwb_rescue_page( 'Restaurer une sauvegarde', $body );
exit;

/* ------------------------------------------------------------------------- */

function bkwb_rescue_new_state( $archive, $files, $db, $wpconfig ) {
	return array(
		'archive'    => $archive,
		'files_on'   => (bool) $files,
		'db'         => (bool) $db,
		'wpconfig'   => (bool) $wpconfig,
		'step'       => 'extract',
		'label'      => 'Extraction de l’archive',
		'progress'   => 0,
		'size'       => filesize( $archive ),
		'reader'     => array(),
		'import'     => array(),
		'sql'        => __DIR__ . '/bkwb-secours-database.sql',
		'has_db'     => false,
		'files'      => 0,
		'statements' => 0,
		'warnings'   => array(),
		'error'      => '',
	);
}

function bkwb_rescue_run( array &$s, $deadline ) {
	try {
		if ( 'extract' === $s['step'] ) {
			$handler = new BKWB_Rescue_Extractor( $s );
			$reader  = new BKWB_Tar_Reader( $s['archive'], $s['reader'] );
			try {
				$done = $reader->run( $handler, $deadline );
			} finally {
				$handler->close();
				$s['reader'] = $reader->state;
			}
			$s['progress'] = (int) round( 70 * $reader->state['m_c'] / max( 1, $s['size'] ) );
			if ( ! $done ) {
				return;
			}
			if ( $s['db'] && ! $s['has_db'] ) {
				throw new RuntimeException( 'Cette archive ne contient pas de base de données.' );
			}
			$s['step']  = $s['db'] ? 'import' : 'done';
			$s['label'] = 'Import de la base de données';
		}
		if ( 'import' === $s['step'] ) {
			$db       = bkwb_rescue_connect();
			$importer = new BKWB_Sql_Importer( $db, $s['sql'], $s['import'] );
			try {
				$done = $importer->run( $deadline );
			} finally {
				$s['import']     = $importer->state;
				$s['statements'] = $importer->state['statements'];
			}
			$s['progress'] = 70 + (int) round( 29 * $importer->state['offset'] / max( 1, (int) @filesize( $s['sql'] ) ) );
			if ( ! $done ) {
				return;
			}
			@unlink( $s['sql'] );
			$s['step'] = 'done';
		}
		if ( 'done' === $s['step'] ) {
			$s['progress'] = 100;
			$s['label']    = 'Terminé';
		}
	} catch ( Throwable $e ) {
		$s['step']  = 'failed';
		$s['error'] = $e->getMessage();
		@unlink( $s['sql'] );
	}
}

function bkwb_rescue_cli( array $argv ) {
	$args = array_slice( $argv, 1 );
	$file = null;
	foreach ( $args as $a ) {
		if ( '-' !== substr( $a, 0, 1 ) ) {
			$file = $a;
		}
	}
	if ( ! $file || ! is_file( $file ) ) {
		fwrite( STDERR, "Usage : php bkwb-secours.php archive.tar.gz [--sans-base] [--sans-fichiers] [--avec-wp-config]\n" );
		exit( 1 );
	}
	$state = bkwb_rescue_new_state( realpath( $file ), ! in_array( '--sans-fichiers', $args, true ), ! in_array( '--sans-base', $args, true ), in_array( '--avec-wp-config', $args, true ) );
	$label = '';
	while ( ! in_array( $state['step'], array( 'done', 'failed' ), true ) ) {
		bkwb_rescue_run( $state, 0 );
		if ( $state['label'] !== $label ) {
			$label = $state['label'];
			echo $label . "\n";
		}
	}
	foreach ( $state['warnings'] as $w ) {
		echo 'Avertissement : ' . $w . "\n";
	}
	if ( 'failed' === $state['step'] ) {
		fwrite( STDERR, 'Échec : ' . $state['error'] . "\n" );
		exit( 1 );
	}
	echo 'Restauration terminée : ' . $state['files'] . ' fichiers' . ( $state['db'] ? ', ' . $state['statements'] . ' instructions SQL' : '' ) . ".\n";
}

function bkwb_rescue_archives() {
	$list  = array();
	$paths = glob( __DIR__ . '/*.tar.gz' );
	if ( BKWB_RESCUE_LOCAL_DIR && is_dir( BKWB_RESCUE_LOCAL_DIR . '/archives' ) ) {
		$paths = array_merge( $paths ? $paths : array(), glob( BKWB_RESCUE_LOCAL_DIR . '/archives/*.tar.gz' ) ?: array() );
	}
	foreach ( $paths ? $paths : array() as $p ) {
		$list[ md5( $p ) ] = array(
			'path'  => $p,
			'size'  => filesize( $p ),
			'mtime' => filemtime( $p ),
		);
	}
	uasort(
		$list,
		function ( $a, $b ) {
			return strcmp( basename( $b['path'] ), basename( $a['path'] ) );
		}
	);
	return $list;
}

function bkwb_rescue_db_config( $file ) {
	if ( ! is_readable( $file ) ) {
		return null;
	}
	$src = (string) file_get_contents( $file );
	$get = function ( $name ) use ( $src ) {
		if ( preg_match( '/define\s*\(\s*[\'"]' . $name . '[\'"]\s*,\s*([\'"])(.*?)(?<!\\\\)\1\s*\)/s', $src, $m ) ) {
			return str_replace( array( '\\' . $m[1], '\\\\' ), array( $m[1], '\\' ), $m[2] );
		}
		return null;
	};
	$conf = array(
		'name'     => $get( 'DB_NAME' ),
		'user'     => $get( 'DB_USER' ),
		'password' => $get( 'DB_PASSWORD' ),
		'host'     => $get( 'DB_HOST' ),
		'charset'  => $get( 'DB_CHARSET' ),
	);
	return null === $conf['name'] || null === $conf['user'] ? null : $conf;
}

function bkwb_rescue_connect() {
	$c = bkwb_rescue_db_config( __DIR__ . '/wp-config.php' );
	if ( ! $c ) {
		throw new RuntimeException( 'Identifiants de la base introuvables dans wp-config.php.' );
	}
	if ( ! class_exists( 'mysqli' ) ) {
		throw new RuntimeException( "L'extension PHP mysqli est nécessaire." );
	}
	$host   = $c['host'] ? $c['host'] : 'localhost';
	$port   = null;
	$socket = null;
	if ( preg_match( '/^(.*):(\d+)$/', $host, $m ) ) {
		$host = $m[1];
		$port = (int) $m[2];
	} elseif ( preg_match( '#^(.*):(/.+)$#', $host, $m ) ) {
		$host   = $m[1];
		$socket = $m[2];
	}
	mysqli_report( MYSQLI_REPORT_OFF );
	$db = mysqli_init();
	if ( ! @$db->real_connect( '' !== $host ? $host : 'localhost', $c['user'], (string) $c['password'], $c['name'], $port, $socket ) ) {
		throw new RuntimeException( 'Connexion à la base impossible : ' . mysqli_connect_error() );
	}
	$db->set_charset( $c['charset'] ? $c['charset'] : 'utf8mb4' );
	return $db;
}

function bkwb_rescue_load() {
	global $bkwb_state;
	if ( ! is_file( $bkwb_state ) ) {
		return null;
	}
	$data = @unserialize( (string) file_get_contents( $bkwb_state ), array( 'allowed_classes' => false ) );
	return is_array( $data ) ? $data : null;
}

function bkwb_rescue_save( array $state ) {
	global $bkwb_state;
	file_put_contents( $bkwb_state . '.tmp', serialize( $state ) );
	rename( $bkwb_state . '.tmp', $bkwb_state );
}

function bkwb_rescue_self() {
	return strtok( $_SERVER['REQUEST_URI'], '?' );
}

function bkwb_h( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function bkwb_size( $bytes ) {
	$units = array( 'o', 'Ko', 'Mo', 'Go', 'To' );
	$i     = 0;
	while ( $bytes >= 1024 && $i < 4 ) {
		$bytes /= 1024;
		$i++;
	}
	return round( $bytes, 1 ) . ' ' . $units[ $i ];
}

function bkwb_rescue_page( $title, $body, $head = '' ) {
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'Cache-Control: no-store' );
	echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">' . $head
		. '<title>' . bkwb_h( $title ) . ' — BK WEB Backup</title><style>'
		. 'body{font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f4f5f7;color:#1d2327;margin:0;padding:16px}'
		. 'main{max-width:760px;margin:24px auto;background:#fff;border-radius:10px;padding:24px 28px;box-shadow:0 1px 3px rgba(0,0,0,.08)}'
		. 'h1{font-size:22px;margin:0 0 4px}.sub{color:#646970;margin:0 0 20px}table{border-collapse:collapse;width:100%;margin:12px 0}'
		. 'th,td{text-align:left;padding:6px 8px;border-bottom:1px solid #e5e5e5;font-size:14px}code{font-size:13px;word-break:break-all}'
		. 'button{background:#1d2327;color:#fff;border:0;border-radius:6px;padding:10px 16px;font-size:15px;cursor:pointer}button.danger{background:#b32d2e}'
		. 'input[type=password],input[name=confirm]{padding:8px;font-size:15px;border:1px solid #c3c4c7;border-radius:6px;width:260px;max-width:100%}'
		. 'progress{width:100%;height:18px}.err{color:#b32d2e;font-weight:600}.ok{color:#00703c;font-weight:600}'
		. '</style></head><body><main><h1>' . bkwb_h( $title ) . '</h1><p class="sub">BK WEB Backup — script de secours</p>' . $body . '</main></body></html>';
}

/**
 * Écrit les entrées de l'archive directement à la racine du site.
 */
class BKWB_Rescue_Extractor {

	private $s;
	private $fh   = null;
	private $path = null;
	private $mode = null;

	public function __construct( array &$state ) {
		$this->s = &$state;
	}

	public function start( array $e ) {
		$name = $e['name'];
		if ( 'database.sql' === $name ) {
			if ( ! $this->s['db'] ) {
				return false;
			}
			$this->s['has_db'] = true;
			return $this->open( $this->s['sql'], 'sql', 0 );
		}
		if ( 0 !== strpos( $name, 'files/' ) || ! $this->s['files_on'] ) {
			return false;
		}
		$rel = $this->safe_rel( substr( $name, 6 ) );
		if ( null === $rel ) {
			return false;
		}
		$target = __DIR__ . '/' . $rel;
		if ( '5' === $e['type'] ) {
			if ( ! is_dir( $target ) ) {
				@mkdir( rtrim( $target, '/' ), 0755, true );
			}
			return false;
		}
		if ( ! is_dir( dirname( $target ) ) ) {
			@mkdir( dirname( $target ), 0755, true );
		}
		$this->s['files']++;
		return $this->open( $target, 'file', 0 );
	}

	public function resume( array $e ) {
		if ( 'database.sql' === $e['name'] ) {
			$this->open( $this->s['sql'], 'sql', $e['done'] );
			return;
		}
		$rel = $this->safe_rel( substr( $e['name'], 6 ) );
		$this->open( __DIR__ . '/' . $rel, 'file', $e['done'] );
	}

	public function data( $bytes ) {
		if ( $this->fh && strlen( $bytes ) !== fwrite( $this->fh, $bytes ) ) {
			throw new RuntimeException( 'Écriture impossible (espace disque plein ?) : ' . $this->path );
		}
	}

	public function end( array $e ) {
		$this->close();
		if ( 'file' === $this->mode && $this->path && ! empty( $e['mtime'] ) ) {
			@touch( $this->path, (int) $e['mtime'] );
		}
		$this->mode = null;
		$this->path = null;
	}

	public function close() {
		if ( $this->fh ) {
			fclose( $this->fh );
			$this->fh = null;
		}
	}

	private function open( $path, $mode, $offset ) {
		$this->close();
		$fh = @fopen( $path, 'c+b' );
		if ( ! $fh ) {
			if ( 'file' === $mode && count( $this->s['warnings'] ) < 100 ) {
				$this->s['warnings'][] = 'Fichier impossible à écrire : ' . $path;
				return false;
			}
			throw new RuntimeException( 'Impossible de créer le fichier : ' . $path );
		}
		ftruncate( $fh, $offset );
		fseek( $fh, $offset );
		$this->fh   = $fh;
		$this->path = $path;
		$this->mode = $mode;
		return true;
	}

	private function safe_rel( $rel ) {
		$rel = ltrim( str_replace( '\\', '/', $rel ), '/' );
		if ( '' === $rel || preg_match( '#(^|/)\.\.(/|$)#', $rel ) ) {
			return null;
		}
		$clean = rtrim( $rel, '/' );
		if ( 'wp-config.php' === $clean && ! $this->s['wpconfig'] ) {
			return null;
		}
		if ( basename( __FILE__ ) === $clean || 0 === strpos( $clean, 'bkwb-secours' ) ) {
			return null;
		}
		return $rel;
	}
}

/* Bibliothèques de BK WEB Backup (lecture d'archive et import SQL), ajoutées à la génération. */
