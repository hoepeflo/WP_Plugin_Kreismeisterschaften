<?php
/**
 * Anlegen und Migrieren der kmm_-Tabellen über dbDelta() mit Schema-Version.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Database;

final class Migrator {

	/**
	 * Führt die Migration aus, wenn die gespeicherte Schema-Version abweicht.
	 * Wird auf plugins_loaded aufgerufen (nur im Backend oder per Cron/CLI), damit
	 * ein per FTP aktualisiertes Plugin ohne erneute Aktivierung migriert.
	 */
	public static function maybe_migrate(): void {
		$installed = (int) get_option(Schema::OPTION_VERSION, 0);
		if ($installed === Schema::VERSION) {
			return;
		}
		self::migrate($installed);
	}

	/**
	 * Erzwingt die Migration (Aktivierung, Systemseite).
	 *
	 * @return array<string, string> dbDelta-Meldungen (Tabelle => Text).
	 */
	public static function migrate(?int $from_version = null): array {
		global $wpdb;

		if ($from_version === null) {
			$from_version = (int) get_option(Schema::OPTION_VERSION, 0);
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		self::run_steps($from_version, Schema::VERSION, 'before');

		$messages = [];
		foreach (Schema::definitions($wpdb->prefix, $wpdb->get_charset_collate()) as $table => $sql) {
			$result = dbDelta($sql);
			if (is_array($result) && $result !== []) {
				$messages[ $table ] = implode('; ', array_map('strval', $result));
			}
		}

		self::run_steps($from_version, Schema::VERSION, 'after');

		update_option(Schema::OPTION_VERSION, Schema::VERSION, true);

		return $messages;
	}

	/**
	 * Versionsgebundene Schritte, die dbDelta nicht abdeckt (Umbenennungen,
	 * Datenkonvertierungen). Jeder Schritt läuft genau einmal beim Übergang auf
	 * die jeweilige Version: before_N() vor dbDelta, step_N() danach.
	 */
	private static function run_steps(int $from, int $to, string $phase): void {
		for ($v = $from + 1; $v <= $to; $v++) {
			$method = ($phase === 'before' ? 'before_' : 'step_') . $v;
			if (method_exists(self::class, $method)) {
				self::$method();
			}
		}
	}

	/** Version 1: Erstanlage, keine Zusatzschritte. */
	private static function step_1(): void {
	}

	/**
	 * Version 2: Klassenschlüssel ist Gruppe + Nummer + Geschlecht (MixTeam-Teamklassen
	 * tragen dieselbe Nummer wie Junioren I bzw. Herren I). Alten Unique-Index entfernen,
	 * dbDelta legt den neuen an.
	 */
	private static function before_2(): void {
		global $wpdb;
		$table = Schema::table('klasse', $wpdb->prefix);
		$index = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", 'gruppe_nummer')); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ($index !== null) {
			$wpdb->query("ALTER TABLE {$table} DROP INDEX gruppe_nummer"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Version 3 (Phase 2): nur additive Tabellen und Spalten mit Standardwerten
	 * (dbDelta); Referenten-Rolle anlegen. Bestehende Daten werden nicht verändert.
	 */
	private static function step_3(): void {
		\KSV\KMM\Auth\Capabilities::add_to_roles();
	}

	/**
	 * Status aller Tabellen für die Systemseite.
	 *
	 * @return array<string, bool> Tabellenname => existiert.
	 */
	public static function table_status(): array {
		global $wpdb;
		$status = [];
		foreach (Schema::TABLES as $short) {
			$name = Schema::table($short, $wpdb->prefix);
			$found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name));
			$status[ $name ] = is_string($found) && $found === $name;
		}
		return $status;
	}

	/**
	 * Löscht alle Tabellen (nur Deinstallation mit ausdrücklicher Zustimmung).
	 */
	public static function drop_all(): void {
		global $wpdb;
		foreach (array_reverse(Schema::TABLES) as $short) {
			$name = Schema::table($short, $wpdb->prefix);
			// Tabellenname stammt aus einer festen Liste, kein Nutzereingabewert.
			$wpdb->query("DROP TABLE IF EXISTS {$name}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		delete_option(Schema::OPTION_VERSION);
	}
}
