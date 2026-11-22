<?php
/**
 * Backend: externe Sicherung – Einstellungen, Status, Sichern von Hand, Schlüssel erzeugen,
 * Wiederherstellung (docs/SICHERUNG.md).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Admin;

use KSV\KMM\Application\Protokoll;
use KSV\KMM\Application\Sicherung\Sicherung;
use KSV\KMM\Application\Sicherung\Verschluesselung;
use KSV\KMM\Application\Sicherung\Wiederherstellung;
use KSV\KMM\Application\Sicherung\Zeitplan;
use KSV\KMM\Auth\Rechte;
use KSV\KMM\Support\Clock;
use KSV\KMM\Support\Settings;

final class SicherungPage extends AdminPage {

	public const SLUG = 'kmm-sicherung';

	/** PRG-Aktionen: speichern, jetzt sichern, Verbindung testen, lokale Datei herunterladen. */
	public static function handle_post(): void {
		if (!self::is_own_post() || in_array(self::action(), ['schluessel', 'pruefen', 'einspielen'], true)) {
			return; // die drei werden in render() behandelt, damit nichts gespeichert werden muss
		}
		self::verify();
		switch (self::action()) {
			case 'speichern':
				$datum = static fn(string $v): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : '';
				$url = esc_url_raw(self::post_str('sicherung_ziel_url', 500));
				$werte = [
					'sicherung_aktiv'      => self::post_bool('sicherung_aktiv'),
					'sicherung_von'        => $datum(self::post_str('sicherung_von', 10)),
					'sicherung_bis'        => $datum(self::post_str('sicherung_bis', 10)),
					'sicherung_uhrzeit'    => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', self::post_str('sicherung_uhrzeit', 5)) === 1 ? self::post_str('sicherung_uhrzeit', 5) : '03:00',
					'sicherung_ziel_url'   => str_starts_with($url, 'https://') ? $url : '',
					'sicherung_lokal_tage' => max(1, min(90, self::post_int('sicherung_lokal_tage'))),
				];
				$token = self::post_str('sicherung_ziel_token', 200);
				if ($token !== '') {
					$werte['sicherung_ziel_token'] = $token; // leer lassen = unverändert
				}
				$schluessel = self::post_str('sicherung_schluessel', 200);
				if ($schluessel !== '') {
					if (Verschluesselung::oeffentlich_roh($schluessel) === '') {
						self::redirect(__('Der öffentliche Schlüssel ist ungültig (er beginnt mit KMM-OEFFENTLICH-). Nichts gespeichert.', 'ksv-km-meldeportal'), 'error');
					}
					$werte['sicherung_schluessel'] = preg_replace('/\s+/', '', $schluessel);
				}
				if ($url !== '' && $werte['sicherung_ziel_url'] === '') {
					self::redirect(__('Die Adresse des Briefkastens muss mit https:// beginnen. Nichts gespeichert.', 'ksv-km-meldeportal'), 'error');
				}
				Settings::update($werte);
				$log = $werte;
				unset($log['sicherung_ziel_token']);
				Protokoll::admin('sicherung.einstellungen', 'Einstellungen der Sicherung gespeichert', null, null, '', null, $log);
				self::redirect(__('Einstellungen gespeichert.', 'ksv-km-meldeportal'));
			case 'jetzt':
				if (!Sicherung::eingerichtet()) {
					self::redirect(__('Bitte zuerst Briefkasten-Adresse, Zugangsschlüssel und öffentlichen Schlüssel eintragen.', 'ksv-km-meldeportal'), 'error');
				}
				$r = Sicherung::erstellen('manuell');
				if (!$r['ok']) {
					self::redirect(sprintf(__('Sicherung fehlgeschlagen: %s', 'ksv-km-meldeportal'), $r['fehler']), 'error');
				}
				self::redirect(
					$r['gesendet']
						? sprintf(__('Sicherung %1$s (%2$s) erstellt und an den Briefkasten übertragen.', 'ksv-km-meldeportal'), $r['datei'], size_format($r['bytes']))
						: sprintf(__('Sicherung %1$s lokal gespeichert, die Übertragung ist fehlgeschlagen: %2$s. Sie wird stündlich erneut versucht.', 'ksv-km-meldeportal'), $r['datei'], $r['fehler']),
					$r['gesendet'] ? 'success' : 'warning'
				);
			case 'testen':
				$fehler = Sicherung::verbindung_testen();
				self::redirect($fehler === '' ? __('Verbindung zum Briefkasten in Ordnung.', 'ksv-km-meldeportal') : sprintf(__('Verbindung fehlgeschlagen: %s', 'ksv-km-meldeportal'), $fehler), $fehler === '' ? 'success' : 'error');
			case 'laden':
				$name = self::post_str('datei', 100);
				$pfad = Sicherung::ordner() . '/' . $name;
				if (!Zeitplan::gueltiger_dateiname($name) || !is_file($pfad)) {
					self::redirect(__('Datei nicht gefunden.', 'ksv-km-meldeportal'), 'error');
				}
				nocache_headers();
				header('Content-Type: application/octet-stream');
				header('Content-Disposition: attachment; filename="' . $name . '"');
				header('Content-Length: ' . (string) filesize($pfad));
				readfile($pfad);
				exit;
		}
	}

	public static function render(): void {
		self::require_manage();
		echo '<div class="wrap kmm-admin">';
		Menu::page_header(__('Sicherung', 'ksv-km-meldeportal'));
		self::show_notices();
		$privat_neu = self::render_post();

		$s = Settings::all();
		$st = Sicherung::status();
		$row = static function (string $label, string $field, string $desc = ''): void {
			echo '<tr><th>' . esc_html($label) . '</th><td>' . $field . ($desc !== '' ? '<p class="description">' . esc_html($desc) . '</p>' : '') . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		};

		if ($privat_neu !== '') {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Neuer privater Schlüssel – nur jetzt sichtbar:', 'ksv-km-meldeportal') . '</strong></p>';
			echo '<p><textarea readonly rows="2" class="large-text code" onclick="this.select()">' . esc_textarea($privat_neu) . '</textarea></p>';
			echo '<p>' . esc_html__('Jetzt in den Passwort-Manager kopieren und für den Notfall-Umschlag ausdrucken. Er wird nirgends gespeichert. Ohne ihn lassen sich die Sicherungen nicht öffnen.', 'ksv-km-meldeportal') . '</p></div>';
		}

		// Status
		echo '<h2>' . esc_html__('Status', 'ksv-km-meldeportal') . '</h2><table class="widefat striped kmm-system-table"><tbody>';
		$row(__('Eingerichtet', 'ksv-km-meldeportal'), Sicherung::eingerichtet() ? '✔' : esc_html__('nein – Briefkasten-Adresse, Zugangsschlüssel und öffentlicher Schlüssel fehlen', 'ksv-km-meldeportal'));
		$row(__('Automatische Sicherung', 'ksv-km-meldeportal'), Sicherung::im_zeitraum() ? esc_html__('läuft (heute im Zeitraum)', 'ksv-km-meldeportal') : esc_html__('ruht (aus oder außerhalb des Zeitraums)', 'ksv-km-meldeportal'));
		$row(__('Letzte Sicherung erstellt', 'ksv-km-meldeportal'), esc_html($st['letzte_erstellt'] !== '' ? Clock::format_local($st['letzte_erstellt']) . ' – ' . $st['letzte_datei'] . ' (' . size_format($st['letzte_bytes']) . ')' : '–'));
		$row(__('Zuletzt übertragen', 'ksv-km-meldeportal'), esc_html($st['letzte_gesendet'] !== '' ? Clock::format_local($st['letzte_gesendet']) : '–'));
		$row(__('Offene Übertragungen', 'ksv-km-meldeportal'), esc_html((string) count(Sicherung::offen())));
		if ($st['letzter_fehler'] !== '') {
			$row(__('Letzter Fehler', 'ksv-km-meldeportal'), '<span class="kmm-fail">' . esc_html(Clock::format_local($st['letzter_fehler_am']) . ': ' . $st['letzter_fehler']) . '</span>');
		}
		echo '</tbody></table>';
		echo '<p>';
		self::form_open('jetzt', 'style="display:inline"');
		submit_button(__('Jetzt sichern', 'ksv-km-meldeportal'), 'primary', 'submit', false);
		echo '</form> ';
		self::form_open('testen', 'style="display:inline"');
		submit_button(__('Verbindung testen', 'ksv-km-meldeportal'), 'secondary', 'submit', false);
		echo '</form></p>';

		// Einstellungen
		echo '<h2>' . esc_html__('Einstellungen', 'ksv-km-meldeportal') . '</h2>';
		self::form_open('speichern');
		echo '<table class="form-table">';
		$row(__('Automatisch sichern', 'ksv-km-meldeportal'), self::checkbox('sicherung_aktiv', (bool) $s['sicherung_aktiv'], __('täglich und nach Meldungen und Startplan-Freigaben', 'ksv-km-meldeportal')), __('Von Hand sichern geht immer, auch außerhalb des Zeitraums.', 'ksv-km-meldeportal'));
		$row(__('Zeitraum', 'ksv-km-meldeportal'), self::input('sicherung_von', $s['sicherung_von'], 'date') . ' ' . esc_html__('bis', 'ksv-km-meldeportal') . ' ' . self::input('sicherung_bis', $s['sicherung_bis'], 'date'), __('z. B. KM-Eröffnung bis 30.04.; leeres Feld = offen', 'ksv-km-meldeportal'));
		$row(__('Tägliche Sicherung ab', 'ksv-km-meldeportal'), self::input('sicherung_uhrzeit', $s['sicherung_uhrzeit'], 'time', 'class="kmm-short"'), __('Ortszeit; läuft mit dem nächsten stündlichen Cron-Lauf nach dieser Uhrzeit.', 'ksv-km-meldeportal'));
		$row(__('Briefkasten-Adresse', 'ksv-km-meldeportal'), self::input('sicherung_ziel_url', $s['sicherung_ziel_url'], 'url', 'class="regular-text" placeholder="https://…/kmm-briefkasten/"'), __('Adresse des Skripts tools/briefkasten auf dem zweiten Webspace.', 'ksv-km-meldeportal'));
		$row(__('Zugangsschlüssel (Einwurf)', 'ksv-km-meldeportal'), self::input('sicherung_ziel_token', '', 'password', 'class="regular-text" autocomplete="new-password" placeholder="' . esc_attr((string) $s['sicherung_ziel_token'] !== '' ? __('gespeichert – leer lassen = unverändert', 'ksv-km-meldeportal') : '') . '"'), __('Derselbe Wert wie einwurf_token in der config.php des Briefkastens. Er erlaubt nur das Einwerfen neuer Dateien.', 'ksv-km-meldeportal'));
		$row(__('Öffentlicher Schlüssel', 'ksv-km-meldeportal'), self::input('sicherung_schluessel', '', 'text', 'class="large-text code" placeholder="' . esc_attr((string) $s['sicherung_schluessel']) . '"'), __('Beginnt mit KMM-OEFFENTLICH-. Leer lassen = unverändert. Kann nur verschlüsseln.', 'ksv-km-meldeportal'));
		$row(__('Lokale Kopien behalten (Tage)', 'ksv-km-meldeportal'), self::input('sicherung_lokal_tage', $s['sicherung_lokal_tage'], 'number', 'min="1" max="90" class="kmm-num"'), __('Verschlüsselte Kopien auf diesem Webspace; die 90 Tage gelten im Briefkasten.', 'ksv-km-meldeportal'));
		echo '</table>';
		submit_button(__('Einstellungen speichern', 'ksv-km-meldeportal'));
		echo '</form>';

		// Schlüssel
		echo '<h2>' . esc_html__('Schlüsselpaar erzeugen', 'ksv-km-meldeportal') . '</h2>';
		echo '<p class="description">' . esc_html__('Erzeugt ein neues Schlüsselpaar, speichert den öffentlichen Schlüssel und zeigt den privaten einmal an. Ältere Sicherungen brauchen weiterhin den alten privaten Schlüssel.', 'ksv-km-meldeportal') . '</p>';
		self::form_open('schluessel');
		if ((string) $s['sicherung_schluessel'] !== '') {
			echo '<p>' . self::checkbox('ersetzen', false, __('Ja, den vorhandenen Schlüssel ersetzen', 'ksv-km-meldeportal')) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		submit_button(__('Schlüsselpaar erzeugen', 'ksv-km-meldeportal'), 'secondary', 'submit', false);
		echo '</form>';

		// Lokale Dateien
		$lokale = Sicherung::lokale();
		echo '<h2>' . esc_html__('Sicherungen auf diesem Webspace', 'ksv-km-meldeportal') . '</h2>';
		if ($lokale === []) {
			echo '<p>' . esc_html__('Noch keine.', 'ksv-km-meldeportal') . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Datei', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Größe', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Übertragen', 'ksv-km-meldeportal') . '</th><th></th></tr></thead><tbody>';
			foreach (array_slice($lokale, 0, 50) as $d) {
				echo '<tr><td><code>' . esc_html($d['name']) . '</code></td><td>' . esc_html(size_format($d['bytes'])) . '</td><td>' . ($d['offen'] ? '<span class="kmm-fail">' . esc_html__('offen', 'ksv-km-meldeportal') . '</span>' : '✔') . '</td><td>';
				self::form_open('laden', 'style="display:inline"');
				echo '<input type="hidden" name="datei" value="' . esc_attr($d['name']) . '">';
				submit_button(__('Herunterladen', 'ksv-km-meldeportal'), 'small', 'submit', false);
				echo '</form></td></tr>';
			}
			echo '</tbody></table>';
		}

		// Wiederherstellung
		echo '<h2 id="einspielen">' . esc_html__('Sicherung einspielen', 'ksv-km-meldeportal') . '</h2>';
		echo '<p class="description">' . esc_html__('Ersetzt alle Daten des KM-Portals durch den Stand der Datei. Vorher wird der aktuelle Stand gesichert. Datei und privater Schlüssel werden nicht gespeichert. Erst „Prüfen“, dann „Einspielen“.', 'ksv-km-meldeportal') . '</p>';
		self::form_open('pruefen', 'enctype="multipart/form-data"');
		echo '<table class="form-table">';
		$row(__('Sicherungsdatei (.kmmsic)', 'ksv-km-meldeportal'), '<input type="file" name="sicherung_datei" accept=".kmmsic" required>');
		$row(__('Privater Schlüssel', 'ksv-km-meldeportal'), '<input type="password" name="privat" class="large-text code" autocomplete="off" required>');
		$row(__('Einspielen', 'ksv-km-meldeportal'), self::checkbox('bestaetigt', false, __('Ich habe die Vorschau geprüft: jetzt alle Daten ersetzen', 'ksv-km-meldeportal')), __('Ohne Haken wird nur geprüft und die Vorschau angezeigt.', 'ksv-km-meldeportal'));
		echo '</table>';
		submit_button(__('Prüfen bzw. einspielen', 'ksv-km-meldeportal'), 'secondary');
		echo '</form></div>';
	}

	/** POST-Aktionen ohne Umleitung; gibt einen neu erzeugten privaten Schlüssel zurück. */
	private static function render_post(): string {
		if (!self::is_own_post()) {
			return '';
		}
		$aktion = self::action();
		if ($aktion === 'schluessel') {
			self::verify();
			if ((string) Settings::get('sicherung_schluessel') !== '' && !self::post_bool('ersetzen')) {
				self::hinweis(__('Bitte bestätigen, dass der vorhandene Schlüssel ersetzt werden soll.', 'ksv-km-meldeportal'), 'error');
				return '';
			}
			$paar = Verschluesselung::schluesselpaar();
			Settings::update(['sicherung_schluessel' => $paar['oeffentlich']]);
			Protokoll::admin('sicherung.schluessel', 'Neues Schlüsselpaar für die Sicherung erzeugt');
			self::hinweis(__('Neuer öffentlicher Schlüssel gespeichert.', 'ksv-km-meldeportal'), 'success');
			return $paar['privat'];
		}
		if ($aktion !== 'pruefen') {
			return '';
		}
		self::verify();
		try {
			if (!Rechte::ist_admin()) {
				throw new \RuntimeException(__('Nur Administratoren dürfen eine Sicherung einspielen.', 'ksv-km-meldeportal'));
			}
			$tmp = isset($_FILES['sicherung_datei']['tmp_name']) ? (string) $_FILES['sicherung_datei']['tmp_name'] : '';
			if ($tmp === '' || !is_uploaded_file($tmp)) {
				throw new \RuntimeException(__('Bitte eine Sicherungsdatei auswählen.', 'ksv-km-meldeportal'));
			}
			$inhalt = (string) file_get_contents($tmp);
			$privat = isset($_POST['privat']) ? (string) wp_unslash($_POST['privat']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$daten = Wiederherstellung::lesen($inhalt, $privat);
			$z = Wiederherstellung::zusammenfassung($daten);
			$text = sprintf(
				/* translators: Vorschau einer Sicherung */
				__('Sicherung vom %1$s (%2$s, Plugin %3$s): %4$d Vereine, %5$d Meldungen, %6$d Einzelmeldungen, %7$d Schützen, %8$d Wettkampftage – %9$d Zeilen in %10$d Tabellen.', 'ksv-km-meldeportal'),
				Clock::format_local((string) $z['erstellt_am']),
				(string) $z['anlass'],
				(string) $z['plugin'],
				(int) $z['vereine'],
				(int) $z['meldungen'],
				(int) $z['einzelmeldungen'],
				(int) $z['schuetzen'],
				(int) $z['wettkampftage'],
				(int) $z['zeilen'],
				(int) $z['tabellen']
			);
			if (!self::post_bool('bestaetigt')) {
				self::hinweis(__('Prüfung in Ordnung. ', 'ksv-km-meldeportal') . $text . ' ' . __('Zum Einspielen Datei und Schlüssel erneut angeben und den Haken setzen.', 'ksv-km-meldeportal'), 'info');
				return '';
			}
			$r = Wiederherstellung::einspielen($daten);
			self::hinweis(sprintf(__('Eingespielt: %1$d Zeilen. Der vorherige Stand liegt in %2$s.', 'ksv-km-meldeportal'), $r['zeilen'], $r['vorher'] !== '' ? $r['vorher'] : '–') . ' ' . $text, 'success');
		} catch (\Throwable $e) {
			self::hinweis($e->getMessage(), 'error');
		}
		return '';
	}

	private static function hinweis(string $text, string $typ): void {
		echo '<div class="notice notice-' . esc_attr($typ) . '"><p>' . esc_html($text) . '</p></div>';
	}
}
