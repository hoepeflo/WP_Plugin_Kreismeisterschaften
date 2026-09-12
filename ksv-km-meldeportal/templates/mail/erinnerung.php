<?php
/**
 * Mail: Erinnerung vor dem Meldeschluss.
 *
 * @var array<string, mixed> $verein
 * @var array<string, mixed> $sportjahr
 * @var string $status
 * @var string $url
 * @var string $anfordern
 * @var string $meldeschluss
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}
echo "Guten Tag,\n\n";
if ($status === 'entwurf') {
	echo sprintf("die Meldung des Vereins %s (VN %s) zur Kreisverbandsmeisterschaft %d ist begonnen, aber noch nicht eingereicht.\n\n", $verein['name'], $verein['vn_nummer'], (int) $sportjahr['jahr']);
} else {
	echo sprintf("für den Verein %s (VN %s) liegt zur Kreisverbandsmeisterschaft %d noch keine Meldung vor.\n\n", $verein['name'], $verein['vn_nummer'], (int) $sportjahr['jahr']);
}
echo sprintf("Meldeschluss ist am %s Uhr. Bis dahin können Sie Ihre Starter im Meldeportal erfassen und die Meldung einreichen:\n\n%s\n\n", $meldeschluss, $url);
echo "Der Link öffnet direkt Ihre Vereinsseite; ein früher erhaltener Link bleibt ebenfalls gültig.\n";
echo sprintf("Falls kein Link mehr funktioniert: %s\n\n", $anfordern);
echo "Sollte Ihr Verein in diesem Jahr nicht teilnehmen, können Sie diese Mail ignorieren.\n\n";
echo "Mit sportlichen Grüßen\nKSV Fallingbostel\n";
