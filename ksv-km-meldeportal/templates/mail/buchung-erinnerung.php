<?php
/**
 * Mail: Erinnerung vor der Buchungsfrist an Vereine mit Startern ohne Platz.
 *
 * @var array<string, mixed> $verein
 * @var array<string, mixed> $tag
 * @var string $datum
 * @var string $frist
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
echo sprintf("für %s am %s haben vom Verein %s (VN %s) noch %d von %d Startern keinen Startplatz.\n\n", $tag['bezeichnung'], $datum, $verein['name'], $verein['vn_nummer'], $ohne_platz, $meldungen);
echo sprintf("Die Buchungsfrist endet am %s Uhr. Bis dahin können Sie die Plätze im KM-Portal unter „Startplätze“ selbst wählen; danach verteilt der KSV die restlichen Starter auf freie Plätze.\n\n", $frist);
echo $url . "\n\n";
echo sprintf("Falls kein Link mehr funktioniert: %s\n\n", $anfordern);
echo "Mit sportlichen Grüßen\nKSV Fallingbostel\n";
