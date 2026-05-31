<?php
/**
 * Pretty Permalinks für Kreismeisterschaften (Rewrite-Regeln).
 *
 * @package SRD_Kreismeisterschaften
 */

if (!defined('ABSPATH')) {
	exit;
}

class SRD_KM_Rewrite {

	public static function init(): void {
		add_action('init', array(__CLASS__, 'register_rewrites'), 5);
		add_filter('query_vars', array(__CLASS__, 'register_query_vars'));
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public static function register_query_vars(array $vars): array {
		$vars[] = 'srd_km_year';
		$vars[] = 'srd_km_discipline';
		$vars[] = 'srd_km_id';
		$vars[] = 'srd_km_art';
		$vars[] = 'srd_km_raw';
		return $vars;
	}

	public static function register_rewrites(): void {
		$s = srd_km_get_settings();
		if (empty($s['rewrite_enabled']) || empty($s['page_id'])) {
			return;
		}
		if (get_option('permalink_structure', '') === '') {
			return;
		}
		$slug = isset($s['rewrite_slug']) ? sanitize_title((string) $s['rewrite_slug']) : 'kreismeisterschaften';
		if ($slug === '') {
			$slug = 'kreismeisterschaften';
		}
		$pid = (int) $s['page_id'];
		$base = '^' . preg_quote($slug, '#') . '/';

		add_rewrite_rule($base . '([0-9]{4})/(e|m)/([^/]+)/raw/?$', 'index.php?page_id=' . $pid . '&srd_km_year=$matches[1]&srd_km_art=$matches[2]&srd_km_id=$matches[3]&srd_km_raw=1', 'top');
		add_rewrite_rule($base . '([0-9]{4})/(e|m)/([^/]+)/?$', 'index.php?page_id=' . $pid . '&srd_km_year=$matches[1]&srd_km_art=$matches[2]&srd_km_id=$matches[3]', 'top');
		add_rewrite_rule($base . '([0-9]{4})/bogen/?$', 'index.php?page_id=' . $pid . '&srd_km_year=$matches[1]&srd_km_discipline=bogen', 'top');
		add_rewrite_rule($base . '([0-9]{4})/blasrohr/?$', 'index.php?page_id=' . $pid . '&srd_km_year=$matches[1]&srd_km_discipline=blasrohr', 'top');
		add_rewrite_rule($base . '([0-9]{4})/?$', 'index.php?page_id=' . $pid . '&srd_km_year=$matches[1]', 'top');
		add_rewrite_rule($base . '?$', 'index.php?page_id=' . $pid, 'top');
	}

	public static function flush_rules(): void {
		self::register_rewrites();
		flush_rewrite_rules(false);
	}
}
