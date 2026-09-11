<?php
/**
 * Plugin-Einstellungen (Option kmm_settings) mit Standardwerten.
 *
 * Offene Punkte aus Konzept Abschnitt 13 sind hier als Einstellung abgebildet,
 * nicht als feste Annahme.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Support;

final class Settings {

	public const OPTION = 'kmm_settings';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			// Route der Vereinsoberfläche (ohne Slashes), z. B. "km-meldung".
			'route_slug'                     => 'km-meldung',
			// Checkbox „Nicht-Meldung" in der Vereinsoberfläche anzeigen (Bedeutung offen).
			'nicht_meldung_sichtbar'         => false,
			// FITASC: Schützinnen ab 56 starten in Damen (61) oder Senioren (62).
			'fitasc_damen_ab_56'             => 'damen',
			// Lichtschießen-Tarif vereinheitlichen (Disziplin-Tarifüberschreibung ignorieren).
			'tarif_override_ignorieren'      => false,
			// Löschregel Schützenliste: Jahre ohne Meldung (0 = nie löschen).
			'schuetzen_loeschfrist_jahre'    => 2,
			// Erinnerungsmail: Tage vor Meldeschluss (Standard, pro Sportjahr überschreibbar).
			'erinnerung_tage_vor_schluss'    => 7,
			// Magic-Link-/Sitzungs-Cookie.
			'sitzung_dauer_tage'             => 30,
			// Rate-Limit „Link anfordern": Versuche pro Stunde je IP bzw. Adresse.
			'link_anfordern_limit'           => 5,
			// DAVID21-CSV (bis der Testimport das Format klärt).
			'csv_trennzeichen'               => ';',
			'csv_zeichensatz'                => 'windows-1252',
			'csv_ganze_ringe_format'         => 'ganz',
			'csv_verband_modus'              => 'vn_nummer',
			// Absender für Mails (leer = WordPress-Standard).
			'mail_absender_name'             => '',
			'mail_absender_adresse'          => '',
			// Deinstallation: Tabellen und Optionen löschen.
			'daten_bei_deinstallation_loeschen' => false,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option(self::OPTION, []);
		if (!is_array($stored)) {
			$stored = [];
		}
		return array_merge(self::defaults(), $stored);
	}

	public static function get(string $key): mixed {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public static function update(array $values): void {
		$current = self::all();
		$merged = array_merge($current, array_intersect_key($values, self::defaults()));
		update_option(self::OPTION, $merged, false);
	}

	public static function route_slug(): string {
		$slug = sanitize_title((string) self::get('route_slug'));
		return $slug !== '' ? $slug : 'km-meldung';
	}
}
