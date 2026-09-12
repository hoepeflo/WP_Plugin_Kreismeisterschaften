<?php
/**
 * Backend: Einstellungen (inkl. offener Punkte aus Konzept 13).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Admin;

use KSV\KMM\Application\Protokoll;
use KSV\KMM\Support\Settings;

final class SettingsPage extends AdminPage {

	public const SLUG = 'kmm-einstellungen';

	public static function handle_post(): void {
		if (!self::is_own_post()) {
			return;
		}
		self::verify();
		if (self::action() !== 'speichern') {
			return;
		}
		$slug = sanitize_title(self::post_str('route_slug', 50));
		$werte = [
			'route_slug'                        => $slug !== '' ? $slug : 'km-meldung',
			'nicht_meldung_sichtbar'            => self::post_bool('nicht_meldung_sichtbar'),
			'fitasc_damen_ab_56'                => self::post_str('fitasc_damen_ab_56') === 'senioren' ? 'senioren' : 'damen',
			'tarif_override_ignorieren'         => self::post_bool('tarif_override_ignorieren'),
			'schuetzen_loeschfrist_jahre'       => max(0, min(20, self::post_int('schuetzen_loeschfrist_jahre'))),
			'erinnerung_tage_vor_schluss'       => max(0, min(60, self::post_int('erinnerung_tage_vor_schluss'))),
			'sitzung_dauer_tage'                => max(1, min(365, self::post_int('sitzung_dauer_tage'))),
			'link_anfordern_limit'              => max(1, min(100, self::post_int('link_anfordern_limit'))),
			'csv_trennzeichen'                  => in_array(self::post_str('csv_trennzeichen'), [';', ',', 'tab'], true) ? self::post_str('csv_trennzeichen') : ';',
			'csv_zeichensatz'                   => in_array(self::post_str('csv_zeichensatz'), ['windows-1252', 'utf-8', 'utf-8-bom'], true) ? self::post_str('csv_zeichensatz') : 'windows-1252',
			'csv_ganze_ringe_format'            => self::post_str('csv_ganze_ringe_format') === 'komma_null' ? 'komma_null' : 'ganz',
			'csv_verband_modus'                 => in_array(self::post_str('csv_verband_modus'), ['vn_nummer', 'leer', 'fest'], true) ? self::post_str('csv_verband_modus') : 'vn_nummer',
			'csv_verband_fest'                  => self::post_str('csv_verband_fest', 20),
			'csv_kopfzeile'                     => self::post_bool('csv_kopfzeile'),
			'mail_absender_name'                => self::post_str('mail_absender_name', 100),
			'mail_absender_adresse'             => sanitize_email(self::post_str('mail_absender_adresse', 190)),
			'daten_bei_deinstallation_loeschen' => self::post_bool('daten_bei_deinstallation_loeschen'),
		];
		Settings::update($werte);
		Protokoll::admin('einstellungen.speichern', 'Einstellungen gespeichert', null, null, '', null, $werte);
		self::redirect(__('Einstellungen gespeichert.', 'ksv-km-meldeportal'));
	}

	public static function render(): void {
		self::require_manage();
		$s = Settings::all();
		echo '<div class="wrap kmm-admin">';
		Menu::page_header(__('Einstellungen', 'ksv-km-meldeportal'));
		self::show_notices();
		self::form_open('speichern');
		$row = static function (string $label, string $field, string $desc = ''): void {
			echo '<tr><th>' . esc_html($label) . '</th><td>' . $field . ($desc !== '' ? '<p class="description">' . esc_html($desc) . '</p>' : '') . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		};

		echo '<h2>' . esc_html__('Vereinsoberfläche', 'ksv-km-meldeportal') . '</h2><table class="form-table">';
		$row(__('Route', 'ksv-km-meldeportal'), esc_html(home_url('/')) . self::input('route_slug', $s['route_slug'], 'text', 'class="kmm-short"') . '/', __('Nach einer Änderung die Ausschlussregel im Caching-Plugin anpassen.', 'ksv-km-meldeportal'));
		$row(__('Checkbox „Nicht-Meldung“', 'ksv-km-meldeportal'), self::checkbox('nicht_meldung_sichtbar', (bool) $s['nicht_meldung_sichtbar'], __('in der Vereinsoberfläche anzeigen', 'ksv-km-meldeportal')), __('Bedeutung der DAVID-Spalte ist noch offen; das Feld wird immer exportiert.', 'ksv-km-meldeportal'));
		$row(__('Sitzungsdauer (Tage)', 'ksv-km-meldeportal'), self::input('sitzung_dauer_tage', $s['sitzung_dauer_tage'], 'number', 'min="1" max="365" class="kmm-num"'), __('Gültigkeit des Cookies nach dem Klick auf den Magic Link.', 'ksv-km-meldeportal'));
		$row(__('„Link anfordern“ – Limit pro Stunde', 'ksv-km-meldeportal'), self::input('link_anfordern_limit', $s['link_anfordern_limit'], 'number', 'min="1" max="100" class="kmm-num"'), __('je IP-Adresse und je E-Mail-Adresse', 'ksv-km-meldeportal'));
		$row(__('Löschfrist Schützenliste (Jahre)', 'ksv-km-meldeportal'), self::input('schuetzen_loeschfrist_jahre', $s['schuetzen_loeschfrist_jahre'], 'number', 'min="0" max="20" class="kmm-num"'), __('Schützen ohne Meldung seit so vielen Sportjahren werden beim Abschluss eines Sportjahres gelöscht (0 = nie).', 'ksv-km-meldeportal'));
		echo '</table>';

		echo '<h2>' . esc_html__('Regeln und Startgeld', 'ksv-km-meldeportal') . '</h2><table class="form-table">';
		$row(__('FITASC: Schützinnen ab 56', 'ksv-km-meldeportal'), self::select('fitasc_damen_ab_56', ['damen' => __('starten in Damen (61)', 'ksv-km-meldeportal'), 'senioren' => __('starten in Senioren/Veteranen/Master (62/64/66)', 'ksv-km-meldeportal')], $s['fitasc_damen_ab_56']));
		$row(__('Tarif-Überschreibungen ignorieren', 'ksv-km-meldeportal'), self::checkbox('tarif_override_ignorieren', (bool) $s['tarif_override_ignorieren'], __('alle Disziplinen nach Tarifstufe abrechnen (z. B. Lichtschießen nicht mehr 2,50 €)', 'ksv-km-meldeportal')));
		$row(__('Erinnerung: Tage vor Meldeschluss', 'ksv-km-meldeportal'), self::input('erinnerung_tage_vor_schluss', $s['erinnerung_tage_vor_schluss'], 'number', 'min="0" max="60" class="kmm-num"'), __('Vorschlag beim Anlegen eines Sportjahres; der konkrete Zeitpunkt steht am Sportjahr.', 'ksv-km-meldeportal'));
		echo '</table>';

		echo '<h2>' . esc_html__('DAVID21-Export (bis der Testimport das Format klärt)', 'ksv-km-meldeportal') . '</h2><table class="form-table">';
		$row(__('Trennzeichen', 'ksv-km-meldeportal'), self::select('csv_trennzeichen', [';' => __('Semikolon (;)', 'ksv-km-meldeportal'), ',' => __('Komma (,)', 'ksv-km-meldeportal'), 'tab' => __('Tabulator', 'ksv-km-meldeportal')], $s['csv_trennzeichen']));
		$row(__('Zeichensatz', 'ksv-km-meldeportal'), self::select('csv_zeichensatz', ['windows-1252' => 'Windows-1252', 'utf-8' => 'UTF-8 ohne BOM', 'utf-8-bom' => 'UTF-8 mit BOM'], $s['csv_zeichensatz']));
		$row(__('Ganze Ringe schreiben als', 'ksv-km-meldeportal'), self::select('csv_ganze_ringe_format', ['ganz' => '375', 'komma_null' => '375,0'], $s['csv_ganze_ringe_format']));
		$row(__('Kopfzeile', 'ksv-km-meldeportal'), self::checkbox('csv_kopfzeile', (bool) ($s['csv_kopfzeile'] ?? true), __('erste Zeile mit Spaltennamen (wie im DAVID-Muster)', 'ksv-km-meldeportal')));
		$row(__('Spalte „Verband“', 'ksv-km-meldeportal'), self::select('csv_verband_modus', ['vn_nummer' => __('= VN-Nummer (laut Muster)', 'ksv-km-meldeportal'), 'leer' => __('leer', 'ksv-km-meldeportal'), 'fest' => __('fester Wert:', 'ksv-km-meldeportal')], $s['csv_verband_modus']) . ' ' . self::input('csv_verband_fest', $s['csv_verband_fest'] ?? '', 'text', 'class="kmm-short"'));
		echo '</table>';

		echo '<h2>' . esc_html__('Mail', 'ksv-km-meldeportal') . '</h2><table class="form-table">';
		$row(__('Absendername', 'ksv-km-meldeportal'), self::input('mail_absender_name', $s['mail_absender_name'], 'text', 'class="regular-text"'), __('leer = WordPress-Standard bzw. Einstellung von WP Mail SMTP', 'ksv-km-meldeportal'));
		$row(__('Absenderadresse', 'ksv-km-meldeportal'), self::input('mail_absender_adresse', $s['mail_absender_adresse'], 'email', 'class="regular-text"'));
		echo '</table>';

		echo '<h2>' . esc_html__('Deinstallation', 'ksv-km-meldeportal') . '</h2><table class="form-table">';
		$row(__('Daten löschen', 'ksv-km-meldeportal'), self::checkbox('daten_bei_deinstallation_loeschen', (bool) $s['daten_bei_deinstallation_loeschen'], __('Beim Löschen des Plugins alle kmm_-Tabellen und Optionen entfernen', 'ksv-km-meldeportal')));
		echo '</table>';

		submit_button(__('Einstellungen speichern', 'ksv-km-meldeportal'));
		echo '</form></div>';
	}
}
