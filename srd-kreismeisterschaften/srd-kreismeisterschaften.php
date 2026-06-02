<?php
/**
 * Plugin Name: SRD Kreismeisterschaften
 * Description: Kreismeisterschaften (KM) aus dem SRD-Ergebnisdienst in WordPress – Disziplinenliste mit Kategorien, PDF/HTML-Ergebnisse.
 * Version: 1.6.4
 * Author: KSV Fallingbostel / SRD
 * Author URI: https://github.com/hoepeflo
 * License: MIT
 * Text Domain: srd-kreismeisterschaften
 *
 * @package SRD_Kreismeisterschaften
 */

if (!defined('ABSPATH')) {
	exit;
}

define('SRD_KM_VERSION', '1.6.3');
define('SRD_KM_PLUGIN_FILE', __FILE__);
define('SRD_KM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SRD_KM_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once SRD_KM_PLUGIN_DIR . 'includes/class-srd-km-capabilities.php';
require_once SRD_KM_PLUGIN_DIR . 'includes/class-srd-km-db.php';
require_once SRD_KM_PLUGIN_DIR . 'includes/class-srd-km-categories.php';
require_once SRD_KM_PLUGIN_DIR . 'includes/class-srd-km-categories-admin.php';
require_once SRD_KM_PLUGIN_DIR . 'includes/class-srd-km-categories-handler.php';
require_once SRD_KM_PLUGIN_DIR . 'includes/class-srd-km-rewrite.php';
require_once SRD_KM_PLUGIN_DIR . 'includes/class-srd-km-results-upload.php';
require_once SRD_KM_PLUGIN_DIR . 'includes/class-srd-km-admin.php';
require_once SRD_KM_PLUGIN_DIR . 'includes/class-srd-km-disciplines-admin.php';
require_once SRD_KM_PLUGIN_DIR . 'includes/class-srd-km-disciplines-handler.php';
require_once SRD_KM_PLUGIN_DIR . 'includes/class-srd-km-documents.php';
require_once SRD_KM_PLUGIN_DIR . 'includes/class-srd-km-documents-admin.php';
require_once SRD_KM_PLUGIN_DIR . 'includes/class-srd-km-documents-handler.php';
require_once SRD_KM_PLUGIN_DIR . 'includes/class-srd-km-frontend.php';

/**
 * @return array<string, mixed>
 */
/**
 * Autorenhinweis auf Kreismeisterschaften-Adminseiten (neben der Überschrift).
 */
function srd_km_render_plugin_author_credit(): void {
	$url = 'https://github.com/hoepeflo';
	echo '<span class="srd-km-plugin-author-credit description" style="margin-left:12px;vertical-align:middle;">';
	printf(
		/* translators: %s: linked author name */
		esc_html__('Plugin: %s', 'srd-kreismeisterschaften'),
		'<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Florian Höper', 'srd-kreismeisterschaften') . '</a>'
	);
	echo '</span>';
}

/**
 * @param array<int, string> $links
 * @param string             $file
 * @return array<int, string>
 */
function srd_km_plugin_row_meta(array $links, string $file): array {
	if ($file !== plugin_basename(SRD_KM_PLUGIN_FILE)) {
		return $links;
	}
	$links[] = '<a href="https://github.com/hoepeflo" target="_blank" rel="noopener noreferrer">' . esc_html__('Florian Höper', 'srd-kreismeisterschaften') . '</a>';
	return $links;
}

add_filter('plugin_row_meta', 'srd_km_plugin_row_meta', 10, 2);

/**
 * @return array<string, mixed>
 */
function srd_km_get_settings(): array {
	$defaults = array(
		'page_id'            => 0,
		'results_path'       => '',
		'results_url'        => '',
		'home_url_custom'    => '',
		'rewrite_enabled'    => 1,
		'rewrite_slug'       => 'kreismeisterschaften',
		'allowed_user_ids'   => array(),
	);
	$stored = get_option('srd_km_settings', array());
	return array_merge($defaults, is_array($stored) ? $stored : array());
}

function srd_km_bootstrap(): void {
	SRD_KM_Capabilities::init();
	SRD_KM_Rewrite::init();
	SRD_KM_Results_Upload::init();
	SRD_KM_Admin::instance();
	SRD_KM_Categories_Admin::init();
	SRD_KM_Categories_Handler::init();
	SRD_KM_Disciplines_Admin::init();
	SRD_KM_Disciplines_Handler::init();
	SRD_KM_Documents_Admin::init();
	SRD_KM_Documents_Handler::init();
	SRD_KM_Frontend::instance();
}

add_action('plugins_loaded', 'srd_km_bootstrap');

add_action(
	'plugins_loaded',
	static function (): void {
		SRD_KM_Categories::maybe_install_defaults();
		SRD_KM_Documents::maybe_migrate_from_yearly();
	},
	20
);

register_activation_hook(__FILE__, static function (): void {
	if (!get_option('srd_km_settings')) {
		add_option('srd_km_settings', array());
	}
	if (!get_option('srd_km_documents')) {
		add_option('srd_km_documents', array(), false);
	}
	SRD_KM_Categories::maybe_install_defaults();
	SRD_KM_Documents::maybe_migrate_from_yearly();
	SRD_KM_Rewrite::flush_rules();
});

add_action(
	'update_option_srd_km_settings',
	static function ($old_value, $value): void {
		unset($old_value, $value);
		SRD_KM_Rewrite::flush_rules();
	},
	10,
	2
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		flush_rewrite_rules();
	}
);
