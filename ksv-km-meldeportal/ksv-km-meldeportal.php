<?php
/**
 * Plugin Name: KSV KM-Meldeportal
 * Plugin URI:  https://github.com/hoepeflo/WP_Plugin_Kreismeisterschaften
 * Description: Meldeportal der Vereine zur Kreisverbandsmeisterschaft des KSV Fallingbostel – Schützenlisten, Meldungen, Mannschaften, DAVID21-Export und PDF-Meldelisten.
 * Version:     0.1.0
 * Author:      Florian Höper / KSV Fallingbostel
 * Author URI:  https://github.com/hoepeflo
 * License:     MIT
 * Text Domain: ksv-km-meldeportal
 * Requires PHP: 8.1
 * Requires at least: 6.4
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

define('KMM_VERSION', '0.1.0');
define('KMM_PLUGIN_FILE', __FILE__);
define('KMM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('KMM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('KMM_PLUGIN_BASENAME', plugin_basename(__FILE__));

/*
 * Autoloading: Composer-Autoloader, falls vendor/ ausgeliefert wurde (Produktion,
 * enthält später Dompdf o. ä.). Fallback ist ein eigener PSR-4-Loader für src/,
 * damit das Plugin auch ohne vendor/ läuft, solange keine Laufzeit-Abhängigkeiten
 * benötigt werden.
 */
if (is_readable(KMM_PLUGIN_DIR . 'vendor/autoload.php')) {
	require_once KMM_PLUGIN_DIR . 'vendor/autoload.php';
}
if (!class_exists(KSV\KMM\Plugin::class, false)) {
	require_once KMM_PLUGIN_DIR . 'src/Autoloader.php';
	KSV\KMM\Autoloader::register(KMM_PLUGIN_DIR . 'src/');
}

register_activation_hook(__FILE__, [KSV\KMM\Installer::class, 'activate']);
register_deactivation_hook(__FILE__, [KSV\KMM\Installer::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
	KSV\KMM\Plugin::instance()->boot();
});
