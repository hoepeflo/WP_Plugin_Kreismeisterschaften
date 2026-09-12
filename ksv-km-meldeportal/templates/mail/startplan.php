<?php
/**
 * Mail: Startplan veröffentlicht.
 *
 * @var array<string, mixed> $verein
 * @var array<string, mixed> $tag
 * @var string $datum
 * @var int $meldungen
 * @var int $ohne_platz
 * @var string $url
 * @var string $anfordern
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}
echo "Guten Tag,\n\n";
echo sprintf("der Startplan für %s am %s%s ist veröffentlicht. Für den Verein %s (VN %s) stehen dort %d Starter.\n\n", $tag['bezeichnung'], $datum, $tag['ort'] !== '' ? ' (' . $tag['ort'] . ')' : '', $verein['name'], $verein['vn_nummer'], $meldungen);
if ($ohne_platz > 0) {
	echo sprintf("Davon %d ohne Platz. Bitte melden Sie sich beim KSV.\n\n", $ohne_platz);
}
echo "Ihre Startzeiten und Stände sehen Sie im KM-Portal unter „Startplätze“:\n";
echo $url . "\n\n";
if (!empty($tag['hinweis'])) {
	echo "Hinweis des KSV: " . $tag['hinweis'] . "\n\n";
}
echo "Änderungen nimmt ab jetzt nur noch der KSV vor; sie sind sofort sichtbar.\n";
echo sprintf("Falls kein Link mehr funktioniert: %s\n\n", $anfordern);
echo "Mit sportlichen Grüßen\nKSV Fallingbostel\n";
