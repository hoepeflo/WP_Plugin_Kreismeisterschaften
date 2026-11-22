<?php
/**
 * Sicherung des KM-Portals am eigenen Rechner entschlüsseln (ohne WordPress).
 *
 *   php tools/sicherung-entschluesseln.php kmm-sicherung-….kmmsic > sicherung.json
 *
 * Der private Schlüssel (KMM-PRIVAT-…) wird abgefragt oder aus der Umgebungsvariablen
 * KMM_PRIVAT gelesen. Die JSON-Datei enthält Personendaten: nach Gebrauch löschen.
 */

declare(strict_types=1);

require __DIR__ . '/../src/Application/Sicherung/Verschluesselung.php';

use KSV\KMM\Application\Sicherung\Verschluesselung;

$datei = $argv[1] ?? '';
if ($datei === '' || !is_file($datei)) {
	fwrite(STDERR, "Aufruf: php tools/sicherung-entschluesseln.php DATEI.kmmsic > sicherung.json\n");
	exit(1);
}
$privat = (string) getenv('KMM_PRIVAT');
if ($privat === '') {
	fwrite(STDERR, 'Privater Schlüssel (KMM-PRIVAT-…): ');
	$privat = trim((string) fgets(STDIN));
}
try {
	$json = Verschluesselung::entschluesseln((string) file_get_contents($datei), $privat);
} catch (Throwable $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(2);
}
$daten = json_decode($json, true);
echo json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
if (is_array($daten) && is_array($daten['tabellen'] ?? null)) {
	fwrite(STDERR, sprintf("Sicherung vom %s (%s): %d Vereine, %d Meldungen, %d Schützen.\n", (string) ($daten['erstellt_am'] ?? '?'), (string) ($daten['anlass'] ?? '?'), count($daten['tabellen']['verein'] ?? []), count($daten['tabellen']['meldung'] ?? []), count($daten['tabellen']['schuetze'] ?? [])));
}
