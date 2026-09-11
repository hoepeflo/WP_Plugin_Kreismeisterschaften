<?php
/**
 * Eigene Route der Vereinsoberfläche (Standard: /km-meldung/…), ohne Theme und ohne Cache.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Http;

use KSV\KMM\Support\Settings;

final class Router {

	public const QUERY_VAR = 'kmm_route';

	public static function register(): void {
		add_action('init', [self::class, 'register_rewrite_rules'], 5);
		add_filter('query_vars', [self::class, 'register_query_vars']);
		add_action('template_redirect', [self::class, 'dispatch'], 0);
		add_action('update_option_' . Settings::OPTION, [self::class, 'flush_on_settings_change'], 10, 2);
	}

	public static function register_rewrite_rules(): void {
		$slug = preg_quote(Settings::route_slug(), '#');
		add_rewrite_rule('^' . $slug . '/?$', 'index.php?' . self::QUERY_VAR . '=start', 'top');
		add_rewrite_rule('^' . $slug . '/([a-z0-9\-/]+?)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top');
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public static function register_query_vars(array $vars): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * @param mixed $old
	 * @param mixed $new
	 */
	public static function flush_on_settings_change($old, $new): void {
		$old_slug = is_array($old) ? ($old['route_slug'] ?? null) : null;
		$new_slug = is_array($new) ? ($new['route_slug'] ?? null) : null;
		if ($old_slug !== $new_slug) {
			self::register_rewrite_rules();
			flush_rewrite_rules(false);
		}
	}

	/** Absolute URL zur Vereinsoberfläche. */
	public static function url(string $path = ''): string {
		$base = home_url('/' . Settings::route_slug() . '/');
		$path = trim($path, '/');
		return $path === '' ? $base : $base . $path . '/';
	}

	/**
	 * Liefert die Meldeseite aus. Der Aufruf endet immer mit exit, damit weder
	 * Theme noch Divi noch ein Seitencache greifen.
	 */
	public static function dispatch(): void {
		$route = get_query_var(self::QUERY_VAR, '');
		if (!is_string($route) || $route === '') {
			return;
		}

		self::disable_caching();

		$route = trim($route, '/');
		$segments = $route === '' ? ['start'] : explode('/', $route);

		$controller = new FrontController();
		$controller->handle($segments);
		exit;
	}

	/**
	 * Verhindert das Cachen der Meldeseiten: DONOTCACHEPAGE für Caching-Plugins,
	 * nocache_headers() für Browser und Proxies. Zusätzlich muss die Route im
	 * Caching-Plugin ausgeschlossen werden (docs/INSTALLATION.md).
	 */
	public static function disable_caching(): void {
		if (!defined('DONOTCACHEPAGE')) {
			define('DONOTCACHEPAGE', true);
		}
		if (!defined('DONOTCACHEOBJECT')) {
			define('DONOTCACHEOBJECT', true);
		}
		if (!defined('DONOTCACHEDB')) {
			define('DONOTCACHEDB', true);
		}
		nocache_headers();
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
		header('X-Robots-Tag: noindex, nofollow', true);
	}
}
