<?php
/**
 * Deinstallation: Entfernt Optionen und – nur wenn in den Einstellungen ausdrücklich
 * gewünscht – alle kmm_-Tabellen. Standard ist: Daten bleiben erhalten.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

require_once __DIR__ . '/src/Autoloader.php';
KSV\KMM\Autoloader::register(__DIR__ . '/src/');

KSV\KMM\Installer::uninstall();
