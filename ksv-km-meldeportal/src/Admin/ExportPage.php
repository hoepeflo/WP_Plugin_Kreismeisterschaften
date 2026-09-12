<?php
/**
 * Backend: DAVID21-Export und PDF-Meldelisten, Export-Protokoll.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Admin;

use KSV\KMM\Application\ExportService;
use KSV\KMM\Application\Pdf;
use KSV\KMM\Application\PdfMeldelisten;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\ExportRepository;
use KSV\KMM\Infrastructure\Repository\GruppeRepository;
use KSV\KMM\Infrastructure\Repository\MeldungRepository;
use KSV\KMM\Support\Clock;
use KSV\KMM\Support\Settings;

final class ExportPage extends AdminPage {

	public const SLUG = 'kmm-export';

	public static function handle_post(): void {
		if (!self::is_own_post()) {
			return;
		}
		self::verify();
		$sid = self::post_int('sportjahr_id');
		$nur_eingereicht = !self::post_bool('entwuerfe');
		try {
			if (self::action() === 'david') {
				$disziplin = self::post_int_or_null('disziplin_id');
				$r = (new ExportService())->david($sid, $disziplin !== null && $disziplin > 0 ? $disziplin : null, $nur_eingereicht);
				self::download($r['inhalt'], $r['dateiname'], $r['content_type']);
			}
			if (self::action() === 'pdf') {
				$umfang = self::post_str('umfang');
				$gruppierung = self::post_str('gruppierung') === PdfMeldelisten::GRUPPIERUNG_KLASSE ? PdfMeldelisten::GRUPPIERUNG_KLASSE : PdfMeldelisten::GRUPPIERUNG_VEREIN;
				$disziplin = self::post_int_or_null('disziplin_id');
				$gruppe = self::post_str('gruppe');
				$r = (new PdfMeldelisten())->erzeugen($sid, in_array($umfang, ['disziplin', 'gruppe'], true) ? $umfang : 'alle', $disziplin !== null && $disziplin > 0 ? $disziplin : null, $gruppe !== '' ? $gruppe : null, $gruppierung, $nur_eingereicht);
				self::download($r['inhalt'], $r['dateiname'], 'application/pdf');
			}
		} catch (\RuntimeException $e) {
			self::redirect($e->getMessage(), 'error', ['sportjahr' => $sid]);
		}
	}

	private static function download(string $inhalt, string $dateiname, string $content_type): never {
		nocache_headers();
		header('Content-Type: ' . $content_type);
		header('Content-Disposition: attachment; filename="' . $dateiname . '"');
		header('Content-Length: ' . strlen($inhalt));
		echo $inhalt; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public static function render(): void {
		self::require_manage();
		$sportjahr = self::current_sportjahr();
		echo '<div class="wrap kmm-admin">';
		Menu::page_header(__('Export: DAVID21 und Meldelisten', 'ksv-km-meldeportal'));
		self::show_notices();
		if ($sportjahr === null) {
			self::no_sportjahr_notice();
			echo '</div>';
			return;
		}
		$sid = (int) $sportjahr['id'];
		self::sportjahr_selector($sportjahr);

		$rw = RegelwerkLader::laden($sid);
		$disziplinen = ['' => __('– alle Disziplinen –', 'ksv-km-meldeportal')];
		$disziplinen_ohne_bogen = $disziplinen;
		foreach ($rw->disziplinen() as $d) {
			$label = $d->kennzahl . ' ' . $d->bezeichnung;
			$disziplinen[ $d->id ] = $label;
			if (!$d->ist_bogen()) {
				$disziplinen_ohne_bogen[ $d->id ] = $label;
			}
		}
		$gruppen = [];
		foreach ((new GruppeRepository())->by_sportjahr($sid) as $g) {
			$gruppen[ (string) $g['code'] ] = (string) $g['bezeichnung'];
		}
		$meldungen = (new MeldungRepository())->by_sportjahr($sid);
		$eingereicht = count(array_filter($meldungen, static fn(array $m): bool => $m['status'] === MeldungRepository::STATUS_EINGEREICHT));
		$entwuerfe = count($meldungen) - $eingereicht;
		$s = Settings::all();

		echo '<p class="description">' . esc_html(sprintf(__('%d Vereine eingereicht, %d im Entwurf. Exportiert werden Meldungen mit Startrecht und ohne offenen Konflikt.', 'ksv-km-meldeportal'), $eingereicht, $entwuerfe)) . '</p>';

		echo '<h2>' . esc_html__('DAVID21-Import (CSV)', 'ksv-km-meldeportal') . '</h2>';
		echo '<p class="description">' . esc_html(sprintf(__('Format laut Einstellungen: Trennzeichen „%s“, Zeichensatz %s, ganze Ringe als %s, Verband = %s, Kopfzeile %s. Ohne Bogen. Jeder Export wird mit Zeitstempel protokolliert.', 'ksv-km-meldeportal'), $s['csv_trennzeichen'] === 'tab' ? 'Tab' : $s['csv_trennzeichen'], $s['csv_zeichensatz'], $s['csv_ganze_ringe_format'] === 'komma_null' ? '375,0' : '375', $s['csv_verband_modus'], ($s['csv_kopfzeile'] ?? true) ? 'ja' : 'nein')) . ' <a href="' . esc_url(Menu::url(SettingsPage::SLUG)) . '">' . esc_html__('Einstellungen', 'ksv-km-meldeportal') . '</a></p>';
		self::form_open('david');
		echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '">';
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__('Umfang', 'ksv-km-meldeportal') . '</th><td>' . self::select('disziplin_id', $disziplinen_ohne_bogen, '') . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th></th><td>' . self::checkbox('entwuerfe', false, __('Entwürfe (nicht eingereichte Meldungen) einbeziehen', 'ksv-km-meldeportal')) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</table>';
		submit_button(__('CSV herunterladen', 'ksv-km-meldeportal'), 'primary', 'submit', false);
		echo '</form>';

		echo '<h2>' . esc_html__('PDF-Meldelisten', 'ksv-km-meldeportal') . '</h2>';
		if (!Pdf::verfuegbar()) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__('Die PDF-Bibliothek (mPDF) fehlt. Bitte das Plugin mit vendor/ ausliefern.', 'ksv-km-meldeportal') . '</p></div>';
		}
		self::form_open('pdf');
		echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '">';
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__('Umfang', 'ksv-km-meldeportal') . '</th><td>';
		echo '<label><input type="radio" name="umfang" value="alle" checked> ' . esc_html__('alle Disziplinen', 'ksv-km-meldeportal') . '</label><br>';
		echo '<label><input type="radio" name="umfang" value="gruppe"> ' . esc_html__('Wettbewerbsgruppe', 'ksv-km-meldeportal') . '</label> ' . self::select('gruppe', $gruppen, 'freihand') . '<br>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<label><input type="radio" name="umfang" value="disziplin"> ' . esc_html__('eine Disziplin', 'ksv-km-meldeportal') . '</label> ' . self::select('disziplin_id', $disziplinen, ''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p class="description">' . esc_html__('Jede Disziplin beginnt auf einer neuen Seite. Bogen ist enthalten.', 'ksv-km-meldeportal') . '</p></td></tr>';
		echo '<tr><th>' . esc_html__('Gruppierung', 'ksv-km-meldeportal') . '</th><td><label><input type="radio" name="gruppierung" value="verein" checked> ' . esc_html__('nach Verein', 'ksv-km-meldeportal') . '</label> &nbsp; <label><input type="radio" name="gruppierung" value="klasse"> ' . esc_html__('nach Startklasse', 'ksv-km-meldeportal') . '</label><p class="description">' . esc_html__('Innerhalb alphabetisch nach Name. Startklasse mit eigentlicher Klasse in Klammern, Mannschaftsnummern.', 'ksv-km-meldeportal') . '</p></td></tr>';
		echo '<tr><th></th><td>' . self::checkbox('entwuerfe', false, __('Entwürfe einbeziehen (in der Liste gekennzeichnet)', 'ksv-km-meldeportal')) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</table>';
		submit_button(__('PDF herunterladen', 'ksv-km-meldeportal'), 'primary', 'submit', false);
		echo '</form>';

		echo '<h2>' . esc_html__('Bisherige Exporte', 'ksv-km-meldeportal') . '</h2>';
		$exporte = (new ExportRepository())->by_sportjahr($sid);
		if ($exporte === []) {
			echo '<p>' . esc_html__('Noch kein Export.', 'ksv-km-meldeportal') . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Zeitpunkt', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Typ', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Datei', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Zeilen', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Durch', 'ksv-km-meldeportal') . '</th></tr></thead><tbody>';
			foreach ($exporte as $e) {
				$user = $e['erstellt_von'] !== null ? get_userdata((int) $e['erstellt_von']) : null;
				echo '<tr><td>' . esc_html(Clock::format_local($e['erstellt_am'], 'd.m.Y H:i:s')) . '</td><td>' . esc_html($e['typ'] === ExportService::TYP_DAVID ? 'DAVID21-CSV' : 'PDF-Liste') . '</td><td><code>' . esc_html((string) $e['dateiname']) . '</code></td><td class="r">' . (int) $e['zeilen'] . '</td><td>' . esc_html($user instanceof \WP_User ? $user->display_name : '') . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}
}
