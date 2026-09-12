<?php
/**
 * Systemseite: Schema-Version, Tabellenstatus, Route, Cron, Migration erneut ausführen.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Admin;

use KSV\KMM\Auth\Capabilities;
use KSV\KMM\Cron;
use KSV\KMM\Http\Router;
use KSV\KMM\Infrastructure\Database\Migrator;
use KSV\KMM\Infrastructure\Database\Schema;
use KSV\KMM\Support\Clock;

final class SystemPage {

	public static function render(): void {
		if (!current_user_can(Capabilities::MANAGE)) {
			wp_die(esc_html__('Keine Berechtigung.', 'ksv-km-meldeportal'));
		}

		$notice = '';
		if (isset($_POST['kmm_action']) && $_POST['kmm_action'] === 'migrate') {
			check_admin_referer('kmm_migrate');
			$messages = Migrator::migrate(0);
			$notice = $messages === []
				? __('Migration ausgeführt, keine Änderungen nötig.', 'ksv-km-meldeportal')
				: __('Migration ausgeführt: ', 'ksv-km-meldeportal') . implode(' | ', $messages);
		}

		$tables = Migrator::table_status();
		$next_cron = wp_next_scheduled(Cron::HOURLY_HOOK);

		echo '<div class="wrap kmm-admin">';
		Menu::page_header(__('KM-Meldeportal – System', 'ksv-km-meldeportal'));

		if ($notice !== '') {
			echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';
		}

		echo '<table class="widefat striped kmm-system-table"><tbody>';
		self::row(__('Plugin-Version', 'ksv-km-meldeportal'), KMM_VERSION);
		self::row(
			__('Schema-Version', 'ksv-km-meldeportal'),
			sprintf('%d (Code: %d)', (int) get_option(Schema::OPTION_VERSION, 0), Schema::VERSION)
		);
		self::row(__('PHP-Version', 'ksv-km-meldeportal'), PHP_VERSION);
		self::row(__('Vereinsoberfläche', 'ksv-km-meldeportal'), '<a href="' . esc_url(Router::url()) . '" target="_blank" rel="noopener">' . esc_html(Router::url()) . '</a>', false);
		self::row(
			__('Nächster Cron-Lauf (kmm_hourly)', 'ksv-km-meldeportal'),
			$next_cron ? Clock::format_local(gmdate(Clock::DB_FORMAT, (int) $next_cron)) : __('nicht geplant', 'ksv-km-meldeportal')
		);
		$next_daily = wp_next_scheduled(Cron::DAILY_HOOK);
		self::row(
			__('Nächste Sammelmail (kmm_sammelmail)', 'ksv-km-meldeportal'),
			$next_daily ? Clock::format_local(gmdate(Clock::DB_FORMAT, (int) $next_daily)) : __('nicht geplant', 'ksv-km-meldeportal')
		);
		self::row(
			__('WP-Cron', 'ksv-km-meldeportal'),
			(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)
				? __('deaktiviert (Server-Cronjob erwartet)', 'ksv-km-meldeportal')
				: __('läuft bei Seitenaufrufen (Server-Cronjob empfohlen, siehe docs/INSTALLATION.md)', 'ksv-km-meldeportal')
		);
		echo '</tbody></table>';

		echo '<h2>' . esc_html__('Tabellen', 'ksv-km-meldeportal') . '</h2>';
		echo '<table class="widefat striped kmm-system-table"><thead><tr><th>' . esc_html__('Tabelle', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Status', 'ksv-km-meldeportal') . '</th></tr></thead><tbody>';
		foreach ($tables as $name => $exists) {
			echo '<tr><td><code>' . esc_html($name) . '</code></td><td>' . ($exists ? '<span class="kmm-ok">✔ ' . esc_html__('vorhanden', 'ksv-km-meldeportal') . '</span>' : '<span class="kmm-fail">✘ ' . esc_html__('fehlt', 'ksv-km-meldeportal') . '</span>') . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<form method="post" style="margin-top:1em">';
		wp_nonce_field('kmm_migrate');
		echo '<input type="hidden" name="kmm_action" value="migrate">';
		submit_button(__('Migration erneut ausführen', 'ksv-km-meldeportal'), 'secondary', 'submit', false);
		echo ' <span class="description">' . esc_html__('Legt fehlende Tabellen und Spalten an (dbDelta). Bestehende Daten bleiben erhalten.', 'ksv-km-meldeportal') . '</span>';
		echo '</form>';

		echo '</div>';
	}

	private static function row(string $label, string $value, bool $escape = true): void {
		echo '<tr><th scope="row" style="width:260px">' . esc_html($label) . '</th><td>' . ($escape ? esc_html($value) : $value) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
