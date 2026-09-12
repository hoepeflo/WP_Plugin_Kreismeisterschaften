<?php
/**
 * Mail: Bestätigung der eingereichten Meldung mit Zusammenfassung.
 *
 * @var array<string, mixed> $zusammenfassung
 * @var array<string, mixed> $verein
 * @var array<string, mixed> $sportjahr
 * @var array<string, mixed>|null $meldung
 * @var string   $url
 * @var string[] $warnungen
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}
$z = $zusammenfassung;
$geld = static fn(float $b): string => number_format($b, 2, ',', '.') . ' €';
echo "Guten Tag,\n\n";
echo sprintf("die Meldung des Vereins %s (VN %s) zur Kreisverbandsmeisterschaft %d wurde eingereicht.\n", $verein['name'], $verein['vn_nummer'], (int) $sportjahr['jahr']);
echo sprintf("Ansprechpartner: %s, %s%s\n\n", $z['ansprechpartner']['name'], $z['ansprechpartner']['email'], $z['ansprechpartner']['telefon'] !== '' ? ', ' . $z['ansprechpartner']['telefon'] : '');
echo sprintf("EINZELMELDUNGEN (%d)\n", count($z['einzelmeldungen']));
$aktuell = '';
foreach ($z['einzelmeldungen'] as $e) {
	if ($e['kennzahl'] !== $aktuell) {
		$aktuell = $e['kennzahl'];
		echo sprintf("\n%s %s\n", $e['kennzahl'], $e['disziplin']);
	}
	echo sprintf("  %s, %s – %s (Startklasse %s, %s)%s%s\n", $e['nachname'], $e['vorname'], $e['klasse'], $e['startklasse'], $e['kennzahl_voll'], $e['meldeergebnis'] !== '' ? ', Ergebnis ' . $e['meldeergebnis'] : ', ohne Ergebnis', $e['mannschaft_nummer'] ? ', Mannschaft ' . $e['mannschaft_nummer'] : '');
}
if ($z['mannschaften'] !== []) {
	echo sprintf("\nMANNSCHAFTEN (%d)\n", count($z['mannschaften']));
	foreach ($z['mannschaften'] as $m) {
		echo sprintf("  %s %s, Mannschaft %d (%s): %s\n", $m['kennzahl'], $m['disziplin'], $m['nummer'], $m['klasse'], implode(', ', array_map(static fn(array $x): string => $x['nachname'] . ', ' . $x['vorname'], $m['mitglieder'])));
	}
}
echo "\nSTARTGELD (vorläufig)\n";
echo sprintf("  Einzelmeldungen: %s\n  Mannschaften: %s\n  Summe: %s\n", $geld((float) $z['startgeld']['einzel']), $geld((float) $z['startgeld']['mannschaften']), $geld((float) $z['startgeld']['summe']));
if ($warnungen !== []) {
	echo "\nHINWEISE\n";
	foreach ($warnungen as $w) {
		echo "  - " . $w . "\n";
	}
}
echo sprintf("\nBis zum Meldeschluss (%s Uhr) können Sie die Meldung unter %s wieder öffnen, ändern und erneut einreichen.\n\n", $z['sportjahr']['meldeschluss'], $url);
echo "Mit sportlichen Grüßen\nKSV Fallingbostel\n";
