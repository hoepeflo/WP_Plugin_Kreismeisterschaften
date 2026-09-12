<?php
/**
 * Mail: tägliche Sammelmail mit den Änderungen an der Meldung eines Vereins.
 *
 * @var array<string, mixed> $verein
 * @var array<string, mixed> $sportjahr
 * @var list<array{zeit: string, typ: string, text: string}> $aenderungen
 * @var string $url
 * @var string $anfordern
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}
echo "Guten Tag,\n\n";
echo sprintf("an der Meldung des Vereins %s (VN %s) zur Kreisverbandsmeisterschaft %d gab es %s:\n\n", $verein['name'], $verein['vn_nummer'], (int) $sportjahr['jahr'], count($aenderungen) === 1 ? 'eine Änderung' : count($aenderungen) . ' Änderungen');
foreach ($aenderungen as $a) {
	echo sprintf("- %s Uhr – %s: %s\n", $a['zeit'], $a['typ'], $a['text']);
}
echo "\nDen aktuellen Stand Ihrer Meldung sehen Sie im Meldeportal:\n\n" . $url . "\n\n";
echo "Der Link öffnet direkt Ihre Vereinsseite; ein früher erhaltener Link bleibt ebenfalls gültig.\n";
echo sprintf("Falls kein Link mehr funktioniert: %s\n\n", $anfordern);
echo "Bei Fragen zu einer Änderung wenden Sie sich bitte an den KSV.\n\n";
echo "Mit sportlichen Grüßen\nKSV Fallingbostel\n";
