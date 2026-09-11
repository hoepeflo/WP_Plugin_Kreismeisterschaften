<?php
/**
 * Zentraler Einstieg: registriert alle Hooks.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM;

use KSV\KMM\Admin\Menu;
use KSV\KMM\Application\Revalidierung;
use KSV\KMM\Auth\Capabilities;
use KSV\KMM\Http\Router;
use KSV\KMM\Infrastructure\Database\Migrator;

final class Plugin {

	private static ?self $instance = null;

	private bool $booted = false;

	public static function instance(): self {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
	}

	public function boot(): void {
		if ($this->booted) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain('ksv-km-meldeportal', false, dirname(KMM_PLUGIN_BASENAME) . '/languages');

		Capabilities::register();
		Router::register();
		Cron::register();
		Revalidierung::register();

		if (is_admin()) {
			Migrator::maybe_migrate();
			Menu::register();
			add_filter('plugin_row_meta', [self::class, 'plugin_row_meta'], 10, 2);
		} elseif (defined('WP_CLI') && WP_CLI) {
			Migrator::maybe_migrate();
		}
	}

	/**
	 * @param array<int, string> $links
	 * @return array<int, string>
	 */
	public static function plugin_row_meta(array $links, string $file): array {
		if ($file !== KMM_PLUGIN_BASENAME) {
			return $links;
		}
		$links[] = '<a href="https://github.com/hoepeflo" target="_blank" rel="noopener noreferrer">' . esc_html__('Florian Höper', 'ksv-km-meldeportal') . '</a>';
		return $links;
	}
}
