<?php
/**
 * Mail: Startplan freigegeben – Verein kann Plätze buchen.
 *
 * @var array<string, mixed> $verein
 * @var array<string, mixed> $tag
 * @var string $datum
 * @var string $frist
 * @var int $meldungen
 * @var string $url
 * @var string $anfordern
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}
echo "Guten Tag,\n\n";
echo sprintf("der Startplan für %s am %s%s ist freigegeben. Für den Verein %s (VN %s) sind dort %d Starter gemeldet.\n\n", $tag['bezeichnung'], $datum, $tag['ort'] !== '' ? ' (' . $tag['ort'] . ')' : '', $verein['name'], $verein['vn_nummer'], $meldungen);
echo "Bitte buchen Sie im Meldeportal unter „Startplätze“ für jeden Starter einen Platz: Schützen wählen, freien Platz antippen. Bis zur Frist können Sie umbuchen und Plätze wieder freigeben.\n\n";
if ($frist !== '') {
	echo sprintf("Buchungsfrist: %s Uhr. Danach verteilt der KSV die restlichen Starter auf freie Plätze.\n\n", $frist);
}
if (!empty($tag['hinweis'])) {
	echo "Hinweis des KSV: " . $tag['hinweis'] . "\n\n";
}
echo $url . "\n\n";
echo "Der Link öffnet direkt Ihre Vereinsseite; ein früher erhaltener Link bleibt ebenfalls gültig.\n";
echo sprintf("Falls kein Link mehr funktioniert: %s\n\n", $anfordern);
echo "Mit sportlichen Grüßen\nKSV Fallingbostel\n";
