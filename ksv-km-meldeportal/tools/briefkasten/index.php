<?php
/**
 * Einstieg des Briefkastens. Aufrufe:
 *
 *   POST ?aktion=einwurf   Sicherung einwerfen (Bearer-Token, Kopf X-KMM-Datei)
 *   POST ?aktion=puls      Lebenszeichen des Portals (Bearer-Token)
 *   POST ?aktion=test      Verbindung prüfen (Bearer-Token)
 *   GET  ?aktion=wartung&schluessel=…   Aufräumen + Warnung (Cronjob)
 *   GET  ?aktion=liste     Download-Seite (Passwort)
 *   GET  ?aktion=holen&datei=…          Datei herunterladen (nach Anmeldung)
 *
 * Auf der Kommandozeile: php index.php wartung
 */

declare(strict_types=1);

require __DIR__ . '/Briefkasten.php';

$config_datei = getenv('KMM_BRIEFKASTEN_CONFIG') ?: __DIR__ . '/config.php';
if (!is_file($config_datei)) {
	http_response_code(500);
	exit('config.php fehlt (siehe config-beispiel.php).');
}
/** @var array<string, mixed> $config */
$config = require $config_datei;

try {
	$bk = new Briefkasten($config);
} catch (Throwable $e) {
	http_response_code(500);
	exit($e->getMessage());
}

if (PHP_SAPI === 'cli') {
	if (($argv[1] ?? '') === 'wartung') {
		$r = $bk->wartung();
		echo 'Gelöscht: ' . $r['geloescht'] . ($r['warnung'] !== '' ? "\nWarnung: " . $r['warnung'] : '') . "\n";
		exit(0);
	}
	exit("Aufruf: php index.php wartung\n");
}

$aktion = (string) ($_GET['aktion'] ?? 'liste');

/** @param array<string, mixed> $daten */
function kmm_json(int $status, array $daten): never {
	http_response_code($status);
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	echo json_encode($daten, JSON_UNESCAPED_UNICODE);
	exit;
}

function kmm_h(string $s): string {
	return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

if (in_array($aktion, ['einwurf', 'puls', 'test'], true)) {
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
		kmm_json(405, ['ok' => false, 'fehler' => 'Nur POST.']);
	}
	$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
	if (!$bk->berechtigt($auth)) {
		sleep(1);
		kmm_json(403, ['ok' => false, 'fehler' => 'Zugangsschlüssel falsch.']);
	}
	$body = (string) file_get_contents('php://input');
	if ($aktion === 'einwurf') {
		[$status, $antwort] = $bk->einwurf((string) ($_SERVER['HTTP_X_KMM_DATEI'] ?? ''), $body);
		kmm_json($status, $antwort);
	}
	if ($aktion === 'puls') {
		[$status, $antwort] = $bk->puls($body);
		kmm_json($status, $antwort);
	}
	$bk->ordner();
	kmm_json(200, ['ok' => true, 'dateien' => count($bk->liste())]);
}

if ($aktion === 'wartung') {
	if (!$bk->wartung_berechtigt((string) ($_GET['schluessel'] ?? ''))) {
		sleep(1);
		kmm_json(403, ['ok' => false, 'fehler' => 'Schlüssel falsch.']);
	}
	kmm_json(200, ['ok' => true] + $bk->wartung());
}

// ----- Download-Seite (nur lesen) -----------------------------------------------------------

session_set_cookie_params(['lifetime' => 0, 'path' => strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?') ?: '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
session_name('kmm_bk');
session_start();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['passwort'])) {
	if ($bk->passwort_richtig((string) $_POST['passwort'])) {
		session_regenerate_id(true);
		$_SESSION['kmm_ok'] = time();
	} else {
		sleep(2);
		$fehler = 'Passwort falsch.';
	}
}
if (isset($_GET['abmelden'])) {
	$_SESSION = [];
	session_destroy();
	header('Location: ?aktion=liste');
	exit;
}
$angemeldet = isset($_SESSION['kmm_ok']) && (int) $_SESSION['kmm_ok'] > time() - 3600;

if ($angemeldet && $aktion === 'holen') {
	$pfad = $bk->pfad((string) ($_GET['datei'] ?? ''));
	if ($pfad === '') {
		http_response_code(404);
		exit('Nicht gefunden.');
	}
	header('Content-Type: application/octet-stream');
	header('Content-Disposition: attachment; filename="' . basename($pfad) . '"');
	header('Content-Length: ' . (string) filesize($pfad));
	readfile($pfad);
	exit;
}
?>
<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>KM-Portal – Sicherungen</title>
<style>body{font-family:system-ui,sans-serif;max-width:760px;margin:2em auto;padding:0 16px;color:#222}table{border-collapse:collapse;width:100%}td,th{padding:6px 8px;border-bottom:1px solid #ddd;text-align:left}.f{color:#b00}</style>
</head><body>
<h1>KM-Portal – Sicherungen</h1>
<?php if (!$angemeldet) : ?>
	<?php if (isset($fehler)) : ?><p class="f"><?php echo kmm_h($fehler); ?></p><?php endif; ?>
	<form method="post" action="?aktion=liste"><label>Passwort <input type="password" name="passwort" autofocus required></label> <button>Anmelden</button></form>
<?php else : ?>
	<p>Die Dateien sind verschlüsselt. Zum Öffnen braucht es den privaten Schlüssel (Notfall-Umschlag). Eingespielt werden sie im WordPress-Backend unter KM-Portal → Sicherung. <a href="?aktion=liste&abmelden=1">Abmelden</a></p>
	<?php $z = $bk->zustand(); ?>
	<p>Letztes Lebenszeichen des Portals: <?php echo !empty($z['letzter_puls']) ? kmm_h(date('d.m.Y H:i', (int) $z['letzter_puls'])) : '–'; ?></p>
	<table><tr><th>Datei</th><th>Größe</th><th>Eingegangen</th></tr>
	<?php foreach ($bk->liste() as $d) : ?>
		<tr><td><a href="?aktion=holen&amp;datei=<?php echo rawurlencode($d['name']); ?>"><?php echo kmm_h($d['name']); ?></a></td><td><?php echo kmm_h(number_format($d['bytes'] / 1024, 0, ',', '.')); ?> KB</td><td><?php echo kmm_h(date('d.m.Y H:i', $d['zeit'])); ?></td></tr>
	<?php endforeach; ?>
	</table>
<?php endif; ?>
</body></html>
