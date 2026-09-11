<?php
/**
 * Mail: Zugangslink.
 *
 * @var array<string, mixed> $verein
 * @var array<string, mixed> $sportjahr
 * @var string $url
 * @var string $anfordern
 * @var string $meldeschluss
 * @var string $site_name
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}
echo "Guten Tag,\n\n";
echo sprintf("hier ist der persönliche Zugang des Vereins %s (VN %s) zum KM-Meldeportal des KSV Fallingbostel für das Sportjahr %d:\n\n", $verein['name'], $verein['vn_nummer'], (int) $sportjahr['jahr']);
echo $url . "\n\n";
echo "Bitte geben Sie den Link nur an Personen weiter, die für Ihren Verein melden dürfen. Der Link öffnet direkt Ihre Vereinsseite, ein Passwort ist nicht nötig.\n\n";
if ($meldeschluss !== '') {
	echo sprintf("Meldeschluss: %s Uhr. Bis dahin können Sie Ihre Meldung bearbeiten und erneut einreichen.\n\n", $meldeschluss);
}
echo sprintf("Falls der Link nicht mehr funktioniert, können Sie unter %s jederzeit einen neuen anfordern.\n\n", $anfordern);
echo "Mit sportlichen Grüßen\nKSV Fallingbostel\n";
