<?php
/**
 * Bootstrap für Integrationstests gegen eine echte WordPress-Installation.
 *
 * Voraussetzung: Umgebungsvariable KMM_WP_ROOT zeigt auf ein WordPress-Verzeichnis
 * (mit wp-config.php und aktiviertem Plugin, Test-Datenbank!). Ohne die Variable
 * werden die Integrationstests übersprungen.
 */

declare(strict_types=1);

$root = getenv('KMM_WP_ROOT');
if (is_string($root) && $root !== '' && is_file(rtrim($root, '/') . '/wp-load.php')) {
	define('KMM_INTEGRATION', true);
	require_once rtrim($root, '/') . '/wp-load.php';
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	require_once __DIR__ . '/tests-autoload.php';
} else {
	define('KMM_INTEGRATION', false);
	require_once __DIR__ . '/bootstrap.php';
}
