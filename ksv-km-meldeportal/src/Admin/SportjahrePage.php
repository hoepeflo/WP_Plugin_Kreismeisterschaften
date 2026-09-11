<?php
/**
 * Backend: Sportjahre anlegen, bearbeiten, aktivieren, kopieren, löschen.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Admin;

use KSV\KMM\Application\SportjahrService;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Support\Clock;

final class SportjahrePage extends AdminPage {

	public const SLUG = 'kmm-sportjahre';

	public static function handle_post(): void {
		if (!self::is_own_post()) {
			return;
		}
		self::verify();
		$service = new SportjahrService();
		try {
			switch (self::action()) {
				case 'anlegen':
					$jahr = self::post_int('jahr');
					if ($jahr < 2000 || $jahr > 2100) {
						self::redirect(__('Bitte ein gültiges Jahr angeben.', 'ksv-km-meldeportal'), 'error');
					}
					$id = $service->anlegen($jahr, self::post_bool('mit_seed'), self::post_str('bezeichnung'));
					self::redirect(sprintf(__('Sportjahr %d angelegt.', 'ksv-km-meldeportal'), $jahr), 'success', ['edit' => $id]);
				case 'speichern':
					$id = self::post_int('id');
					$service->speichern($id, [
						'bezeichnung'    => self::post_str('bezeichnung', 100),
						'meldung_beginn' => Clock::local_to_utc(self::post_str('meldung_beginn')),
						'meldeschluss'   => Clock::local_to_utc(self::post_str('meldeschluss')),
						'erinnerung_am'  => Clock::local_to_utc(self::post_str('erinnerung_am')),
					]);
					self::redirect(__('Sportjahr gespeichert.', 'ksv-km-meldeportal'));
				case 'aktivieren':
					$service->aktivieren(self::post_int('id'));
					self::redirect(__('Sportjahr aktiviert.', 'ksv-km-meldeportal'));
				case 'kopieren':
					$ziel = self::post_int('ziel_jahr');
					$neu = $service->kopieren(self::post_int('id'), $ziel);
					self::redirect(sprintf(__('Sportjahr %d als Kopie angelegt. Höhermeldungen wurden nicht übernommen.', 'ksv-km-meldeportal'), $ziel), 'success', ['edit' => $neu]);
				case 'loeschen':
					$service->loeschen(self::post_int('id'));
					self::redirect(__('Sportjahr gelöscht.', 'ksv-km-meldeportal'));
			}
		} catch (\RuntimeException $e) {
			self::redirect($e->getMessage(), 'error');
		}
	}

	public static function render(): void {
		self::require_manage();
		$repo = new SportjahrRepository();
		$all = $repo->all();
		$edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
		$edit = $edit_id > 0 ? $repo->find($edit_id) : null;

		echo '<div class="wrap kmm-admin">';
		Menu::page_header(__('Sportjahre', 'ksv-km-meldeportal'));
		self::show_notices();

		if ($edit !== null) {
			self::render_edit_form($edit);
		}

		echo '<h2>' . esc_html__('Vorhandene Sportjahre', 'ksv-km-meldeportal') . '</h2>';
		if ($all === []) {
			echo '<p>' . esc_html__('Noch kein Sportjahr angelegt.', 'ksv-km-meldeportal') . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			foreach ([__('Jahr', 'ksv-km-meldeportal'), __('Bezeichnung', 'ksv-km-meldeportal'), __('Meldebeginn', 'ksv-km-meldeportal'), __('Meldeschluss', 'ksv-km-meldeportal'), __('Erinnerung', 'ksv-km-meldeportal'), __('Status', 'ksv-km-meldeportal'), __('Aktionen', 'ksv-km-meldeportal')] as $h) {
				echo '<th>' . esc_html($h) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ($all as $row) {
				$id = (int) $row['id'];
				echo '<tr>';
				echo '<td><strong>' . (int) $row['jahr'] . '</strong></td>';
				echo '<td>' . esc_html((string) $row['bezeichnung']) . '</td>';
				echo '<td>' . esc_html(Clock::format_local($row['meldung_beginn'])) . '</td>';
				echo '<td>' . esc_html(Clock::format_local($row['meldeschluss'])) . '</td>';
				echo '<td>' . esc_html(Clock::format_local($row['erinnerung_am'])) . ($row['erinnerung_gesendet_am'] ? ' ✔' : '') . '</td>';
				echo '<td>' . ($row['ist_aktiv'] ? '<span class="kmm-ok">' . esc_html__('aktiv', 'ksv-km-meldeportal') . '</span>' : '') . '</td>';
				echo '<td class="kmm-actions">';
				echo '<a class="button button-small" href="' . esc_url(self::url(['edit' => $id])) . '">' . esc_html__('Bearbeiten', 'ksv-km-meldeportal') . '</a> ';
				echo '<a class="button button-small" href="' . esc_url(Menu::url(StammdatenPage::SLUG, ['sportjahr' => $id])) . '">' . esc_html__('Stammdaten', 'ksv-km-meldeportal') . '</a> ';
				if (!$row['ist_aktiv']) {
					self::form_open('aktivieren', 'class="kmm-inline-form"');
					echo '<input type="hidden" name="id" value="' . $id . '">';
					submit_button(__('Aktivieren', 'ksv-km-meldeportal'), 'small', 'submit', false);
					echo '</form> ';
				}
				self::form_open('kopieren', 'class="kmm-inline-form"');
				echo '<input type="hidden" name="id" value="' . $id . '">';
				echo '<input type="number" name="ziel_jahr" value="' . ((int) $row['jahr'] + 1) . '" min="2000" max="2100" style="width:5em"> ';
				submit_button(__('Kopieren nach', 'ksv-km-meldeportal'), 'small', 'submit', false);
				echo '</form> ';
				self::form_open('loeschen', 'class="kmm-inline-form" onsubmit="return confirm(\'' . esc_js(__('Sportjahr mit allen Stammdaten löschen?', 'ksv-km-meldeportal')) . '\')"');
				echo '<input type="hidden" name="id" value="' . $id . '">';
				submit_button(__('Löschen', 'ksv-km-meldeportal'), 'small kmm-danger', 'submit', false);
				echo '</form>';
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html__('Neues Sportjahr', 'ksv-km-meldeportal') . '</h2>';
		self::form_open('anlegen');
		echo '<table class="form-table"><tr><th><label for="kmm-jahr">' . esc_html__('Jahr', 'ksv-km-meldeportal') . '</label></th><td>';
		$vorschlag = $all !== [] ? (int) $all[0]['jahr'] + 1 : (int) gmdate('Y') + 1;
		echo self::input('jahr', $vorschlag, 'number', 'id="kmm-jahr" min="2000" max="2100" required'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p class="description">' . esc_html__('Das Sportjahr bestimmt das Alter: Alter = Sportjahr − Geburtsjahr.', 'ksv-km-meldeportal') . '</p></td></tr>';
		echo '<tr><th><label for="kmm-bez">' . esc_html__('Bezeichnung', 'ksv-km-meldeportal') . '</label></th><td>' . self::input('bezeichnung', '', 'text', 'id="kmm-bez" class="regular-text" placeholder="KM ' . $vorschlag . '"') . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th>' . esc_html__('Klassensatz', 'ksv-km-meldeportal') . '</th><td>' . self::checkbox('mit_seed', true, __('Wettbewerbsgruppen, Klassen und Standardtarife nach Konzept einspielen', 'ksv-km-meldeportal')) . '<p class="description">' . esc_html__('Für ein Folgejahr besser „Kopieren nach“ verwenden, damit Disziplinen und Regeln mitkommen.', 'ksv-km-meldeportal') . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</table>';
		submit_button(__('Sportjahr anlegen', 'ksv-km-meldeportal'));
		echo '</form>';
		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function render_edit_form(array $row): void {
		echo '<h2>' . sprintf(esc_html__('Sportjahr %d bearbeiten', 'ksv-km-meldeportal'), (int) $row['jahr']) . '</h2>';
		self::form_open('speichern');
		echo '<input type="hidden" name="id" value="' . (int) $row['id'] . '">';
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__('Bezeichnung', 'ksv-km-meldeportal') . '</th><td>' . self::input('bezeichnung', $row['bezeichnung'], 'text', 'class="regular-text"') . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		foreach (['meldung_beginn' => __('Meldebeginn', 'ksv-km-meldeportal'), 'meldeschluss' => __('Meldeschluss', 'ksv-km-meldeportal'), 'erinnerung_am' => __('Erinnerungsmail am', 'ksv-km-meldeportal')] as $field => $label) {
			echo '<tr><th>' . esc_html($label) . '</th><td>' . self::input($field, Clock::utc_to_local_input($row[ $field ]), 'datetime-local') . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</table>';
		echo '<p class="description">' . esc_html__('Zeiten in der WordPress-Zeitzone. Bis zum Meldeschluss können Vereine melden und Meldungen wieder öffnen; Magic Links gelten bis zum Meldeschluss. Die Erinnerungsmail geht an Vereine mit Status Offen oder Entwurf.', 'ksv-km-meldeportal') . '</p>';
		submit_button(__('Speichern', 'ksv-km-meldeportal'), 'primary', 'submit', false);
		echo ' <a class="button" href="' . esc_url(self::url()) . '">' . esc_html__('Schließen', 'ksv-km-meldeportal') . '</a>';
		echo '</form><hr>';
	}
}
