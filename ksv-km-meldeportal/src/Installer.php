<?php
/**
 * Aktivierung, Deaktivierung, Deinstallation.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM;

use KSV\KMM\Auth\Capabilities;
use KSV\KMM\Http\Router;
use KSV\KMM\Infrastructure\Database\Migrator;
use KSV\KMM\Support\Settings;

final class Installer {

	public static function activate(): void {
		if (version_compare(PHP_VERSION, '8.1', '<')) {
			deactivate_plugins(KMM_PLUGIN_BASENAME);
			wp_die(esc_html__('KSV KM-Meldeportal benötigt PHP 8.1 oder neuer.', 'ksv-km-meldeportal'));
		}
		if (!get_option(Settings::OPTION)) {
			add_option(Settings::OPTION, [], '', false);
		}
		Migrator::migrate();
		Capabilities::add_to_roles();
		Router::register_rewrite_rules();
		flush_rewrite_rules(false);
		Cron::schedule();
	}

	public static function deactivate(): void {
		Cron::unschedule();
		flush_rewrite_rules(false);
	}

	/** Wird aus uninstall.php aufgerufen. Tabellen nur auf ausdrücklichen Wunsch löschen. */
	public static function uninstall(): void {
		$settings = get_option(Settings::OPTION, []);
		$delete_data = is_array($settings) && !empty($settings['daten_bei_deinstallation_loeschen']);

		Capabilities::remove_from_roles();
		if ($delete_data) {
			Migrator::drop_all();
			delete_option(Settings::OPTION);
		}
	}
}
