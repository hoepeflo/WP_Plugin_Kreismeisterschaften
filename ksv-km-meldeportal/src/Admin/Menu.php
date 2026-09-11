<?php
/**
 * Backend-Menü „KM-Meldeportal".
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Admin;

use KSV\KMM\Auth\Capabilities;

final class Menu {

	public const SLUG = 'kmm';

	/** @var array<int, array{0: string, 1: class-string<AdminPage>, 2: string}> Titel, Klasse, Capability */
	private const PAGES = [
		['Sportjahre', SportjahrePage::class, Capabilities::MANAGE],
		['Stammdaten', StammdatenPage::class, Capabilities::MANAGE],
		['Import / Export', ImportExportPage::class, Capabilities::MANAGE],
		['Einstellungen', SettingsPage::class, Capabilities::MANAGE],
	];

	public static function register(): void {
		add_action('admin_menu', [self::class, 'add_pages']);
		add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
		add_action('admin_init', [self::class, 'handle_posts']);
	}

	/** POST-Aktionen der Seiten vor der Ausgabe verarbeiten (Redirect danach). */
	public static function handle_posts(): void {
		if (!isset($_POST['kmm_page'])) {
			return;
		}
		foreach (self::PAGES as [, $class]) {
			$class::handle_post();
		}
	}

	public static function add_pages(): void {
		add_menu_page(
			__('KM-Meldeportal', 'ksv-km-meldeportal'),
			__('KM-Meldeportal', 'ksv-km-meldeportal'),
			Capabilities::VIEW,
			self::SLUG,
			[SystemPage::class, 'render'],
			'dashicons-clipboard',
			58
		);
		add_submenu_page(self::SLUG, __('System', 'ksv-km-meldeportal'), __('System', 'ksv-km-meldeportal'), Capabilities::MANAGE, self::SLUG, [SystemPage::class, 'render']);
		foreach (self::PAGES as [$title, $class, $cap]) {
			add_submenu_page(self::SLUG, __($title, 'ksv-km-meldeportal'), __($title, 'ksv-km-meldeportal'), $cap, $class::SLUG, [$class, 'render']); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		}
	}

	public static function enqueue_assets(string $hook): void {
		if (strpos($hook, self::SLUG) === false) {
			return;
		}
		wp_enqueue_style('kmm-admin', KMM_PLUGIN_URL . 'assets/admin/admin.css', [], KMM_VERSION);
	}

	/**
	 * @param array<string, string|int> $args
	 */
	public static function url(string $page = self::SLUG, array $args = []): string {
		$args = array_merge(['page' => $page], $args);
		return add_query_arg($args, admin_url('admin.php'));
	}

	/** Seitenkopf mit Autorenhinweis wie im Ergebnis-Plugin. */
	public static function page_header(string $title): void {
		echo '<h1 class="wp-heading-inline">' . esc_html($title) . '</h1>';
		echo '<span class="kmm-plugin-credit description">';
		printf(
			/* translators: %s: verlinkter Autorenname */
			esc_html__('Plugin: %s', 'ksv-km-meldeportal'),
			'<a href="https://github.com/hoepeflo" target="_blank" rel="noopener noreferrer">' . esc_html__('Florian Höper', 'ksv-km-meldeportal') . '</a>'
		);
		echo '</span><hr class="wp-header-end">';
	}
}
