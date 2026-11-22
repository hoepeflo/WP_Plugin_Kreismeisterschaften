<?php
/**
 * Vorlage: als config.php neben index.php speichern und ausfüllen. config.php gehört
 * nicht ins Repository.
 *
 * Zufallswerte erzeugen, z. B.:  php -r 'echo bin2hex(random_bytes(24)), "\n";'
 * Passwort-Hash erzeugen:          php -r 'echo password_hash("MEIN-PASSWORT", PASSWORD_DEFAULT), "\n";'
 */

return [
	// Gleicher Wert wie „Zugangsschlüssel (Einwurf)“ im KM-Portal. Mindestens 24 Zeichen.
	'einwurf_token'     => '',
	// Schlüssel für den Cronjob-Aufruf ?aktion=wartung&schluessel=… (anderer Wert!).
	'wartung_token'     => '',
	// password_hash() des Passworts für die Download-Seite.
	'download_passwort' => '',
	// Ablage; am besten außerhalb des öffentlichen Webordners. Ohne Angabe: ./daten
	'ordner'            => __DIR__ . '/daten',
	'aufbewahrung_tage' => 90,
	'max_mb'            => 50,
	// Warnung, wenn sich das Portal so lange nicht meldet oder im Zeitraum keine Sicherung kommt.
	'warn_stunden'      => 36,
	'warn_mail'         => '',
	// Absender der Warnmail, z. B. 'KM-Sicherung <sicherung@meine-domain.de>'
	'absender'          => '',
];
