<?php
/**
 * Minimale Ersatzfunktionen für WordPress in Unit-Tests (kein WordPress geladen).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/../');
}
if (!defined('KMM_PLUGIN_DIR')) {
	define('KMM_PLUGIN_DIR', dirname(__DIR__) . '/');
}
if (!defined('KMM_VERSION')) {
	define('KMM_VERSION', 'test');
}
if (!function_exists('__')) {
	function __(string $text, string $domain = 'default'): string {
		return $text;
	}
}
if (!function_exists('esc_html')) {
	function esc_html(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
	}
}
if (!function_exists('sanitize_title')) {
	function sanitize_title(string $title): string {
		$title = strtolower(trim($title));
		$title = preg_replace('/[^a-z0-9\-]+/', '-', $title) ?? '';
		return trim($title, '-');
	}
}
