<?php
/**
 * Backend: Schießstände mit Standgruppen (Stammdaten, sportjahrübergreifend, nur Admin).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Admin;

use KSV\KMM\Application\SchiessstandService;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\StandgruppeRepository;

final class SchiessstaendePage extends AdminPage {

	public const SLUG = 'kmm-schiessstaende';

	public static function handle_post(): void {
		if (!self::is_own_post()) {
			return;
		}
		self::verify();
		$service = new SchiessstandService();
		$stand_id = self::post_int('stand_id');
		try {
			switch (self::action()) {
				case 'stand_speichern':
					$id = $service->speichern(self::post_int('id'), self::post_str('bezeichnung', 150), self::post_str('ort', 150), self::post_text('notiz'), self::post_int('sortierung'));
					self::redirect(__('Schießstand gespeichert.', 'ksv-km-meldeportal'), 'success', ['stand' => $id]);
				case 'stand_loeschen':
					$service->loeschen(self::post_int('id'));
					self::redirect(__('Schießstand gelöscht.', 'ksv-km-meldeportal'));
				case 'gruppe_speichern':
					$kz = isset($_POST['disziplin_kennzahlen']) && is_array($_POST['disziplin_kennzahlen']) ? array_map(static fn($k): string => sanitize_text_field((string) wp_unslash($k)), $_POST['disziplin_kennzahlen']) : [];
					$service->gruppe_speichern(self::post_int('id'), $stand_id, self::post_str('bezeichnung', 100), self::post_str('praefix', 50), self::post_int('anzahl'), self::post_int('nummer_von'), self::post_int('kapazitaet'), $kz, self::post_int('sortierung'));
					self::redirect(__('Standgruppe gespeichert.', 'ksv-km-meldeportal'), 'success', ['stand' => $stand_id]);
				case 'gruppe_loeschen':
					$service->gruppe_loeschen(self::post_int('id'));
					self::redirect(__('Standgruppe gelöscht.', 'ksv-km-meldeportal'), 'success', ['stand' => $stand_id]);
			}
		} catch (\InvalidArgumentException | \RuntimeException $e) {
			self::redirect($e->getMessage(), 'error', $stand_id > 0 ? ['stand' => $stand_id] : []);
		}
	}

	public static function render(): void {
		self::require_manage();
		echo '<div class="wrap kmm-admin">';
		Menu::page_header(__('Schießstände', 'ksv-km-meldeportal'));
		self::show_notices();
		$service = new SchiessstandService();
		$stand_id = isset($_GET['stand']) ? (int) $_GET['stand'] : 0;
		echo '<p class="description">' . esc_html__('Ein Schießstand ist ein Ort mit Standgruppen, z. B. „10 m“: 12 Stände, „25 m“: 5 Stände, „Bogen“: 6 Scheiben mit je 4 Positionen. Beim Wettkampftag wählst du den Schießstand und kreuzt an, welche Stände an diesem Tag freigegeben sind – die Einheiten entstehen automatisch.', 'ksv-km-meldeportal') . '</p>';

		$liste = $service->liste();
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Schießstand', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Ort', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Standgruppen', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Plätze', 'ksv-km-meldeportal') . '</th><th></th></tr></thead><tbody>';
		foreach ($liste as $s) {
			echo '<tr><td><strong>' . esc_html((string) $s['bezeichnung']) . '</strong></td><td>' . esc_html((string) $s['ort']) . '</td><td>' . esc_html(implode('; ', array_map(static fn(array $g): string => sprintf('%s: %d × %s (%d)', $g['bezeichnung'], (int) $g['anzahl'], $g['praefix'], (int) $g['kapazitaet']), $s['gruppen']))) . '</td><td class="r">' . (int) $s['plaetze'] . '</td>';
			echo '<td class="kmm-nowrap"><a class="button button-small" href="' . esc_url(self::url(['stand' => (int) $s['id']])) . '">' . esc_html__('Bearbeiten', 'ksv-km-meldeportal') . '</a> ';
			self::form_open('stand_loeschen', 'class="kmm-inline-form" onsubmit="return confirm(\'' . esc_js(__('Schießstand mit allen Standgruppen löschen?', 'ksv-km-meldeportal')) . '\')"');
			echo '<input type="hidden" name="id" value="' . (int) $s['id'] . '">';
			submit_button(__('Löschen', 'ksv-km-meldeportal'), 'secondary small', 'submit', false);
			echo '</form></td></tr>';
		}
		if ($liste === []) {
			echo '<tr><td colspan="5">' . esc_html__('Noch kein Schießstand.', 'ksv-km-meldeportal') . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><a class="button button-primary" href="' . esc_url(self::url(['stand' => 'neu'])) . '">' . esc_html__('Schießstand hinzufügen', 'ksv-km-meldeportal') . '</a></p>';

		$neu = isset($_GET['stand']) && $_GET['stand'] === 'neu';
		$stand = $stand_id > 0 ? (new \KSV\KMM\Infrastructure\Repository\SchiessstandRepository())->find($stand_id) : null;
		if ($stand !== null || $neu) {
			self::render_form($stand);
		}
		if ($stand !== null) {
			self::render_gruppen($stand);
		}
		echo '</div>';
	}

	/**
	 * @param array<string, mixed>|null $s
	 */
	private static function render_form(?array $s): void {
		echo '<h2>' . esc_html($s !== null ? __('Schießstand bearbeiten', 'ksv-km-meldeportal') : __('Schießstand hinzufügen', 'ksv-km-meldeportal')) . '</h2>';
		self::form_open('stand_speichern');
		echo '<input type="hidden" name="id" value="' . ($s !== null ? (int) $s['id'] : 0) . '">';
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__('Bezeichnung', 'ksv-km-meldeportal') . '</th><td>' . self::input('bezeichnung', $s['bezeichnung'] ?? '', 'text', 'class="regular-text" required placeholder="Schießstand SV Vorwalsrode"') . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th>' . esc_html__('Ort / Adresse', 'ksv-km-meldeportal') . '</th><td>' . self::input('ort', $s['ort'] ?? '', 'text', 'class="regular-text"') . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th>' . esc_html__('Notiz', 'ksv-km-meldeportal') . '</th><td><textarea name="notiz" rows="2" class="large-text">' . esc_textarea((string) ($s['notiz'] ?? '')) . '</textarea></td></tr>';
		echo '<tr><th>' . esc_html__('Reihenfolge', 'ksv-km-meldeportal') . '</th><td>' . self::input('sortierung', $s['sortierung'] ?? 0, 'number', 'class="kmm-num" min="0"') . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</table>';
		submit_button(__('Speichern', 'ksv-km-meldeportal'), 'primary', 'submit', false);
		echo ' <a class="button" href="' . esc_url(self::url()) . '">' . esc_html__('Schließen', 'ksv-km-meldeportal') . '</a></form>';
	}

	/**
	 * @param array<string, mixed> $s
	 */
	private static function render_gruppen(array $s): void {
		$sid = (int) $s['id'];
		$gruppen = (new StandgruppeRepository())->by_schiessstand($sid);
		$edit = isset($_GET['gruppe']) ? (int) $_GET['gruppe'] : 0;
		$g = $edit > 0 ? (new StandgruppeRepository())->find($edit) : null;
		if ($g !== null && (int) $g['schiessstand_id'] !== $sid) {
			$g = null;
		}
		echo '<h2>' . esc_html__('Standgruppen', 'ksv-km-meldeportal') . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Bezeichnung', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Einheiten', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Kapazität', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Nur für Disziplinen', 'ksv-km-meldeportal') . '</th><th></th></tr></thead><tbody>';
		foreach ($gruppen as $gr) {
			$nummern = StandgruppeRepository::nummern($gr);
			echo '<tr><td><strong>' . esc_html((string) $gr['bezeichnung']) . '</strong></td><td>' . esc_html(sprintf('%d × „%s“ (%s %d – %s %d)', (int) $gr['anzahl'], $gr['praefix'], $gr['praefix'], $nummern[0], $gr['praefix'], end($nummern))) . '</td><td class="r">' . (int) $gr['kapazitaet'] . '</td><td>' . esc_html((string) $gr['disziplin_kennzahlen'] !== '' ? (string) $gr['disziplin_kennzahlen'] : __('alle', 'ksv-km-meldeportal')) . '</td>';
			echo '<td class="kmm-nowrap"><a class="button button-small" href="' . esc_url(self::url(['stand' => $sid, 'gruppe' => (int) $gr['id']])) . '">' . esc_html__('Bearbeiten', 'ksv-km-meldeportal') . '</a> ';
			self::form_open('gruppe_loeschen', 'class="kmm-inline-form" onsubmit="return confirm(\'' . esc_js(__('Standgruppe löschen?', 'ksv-km-meldeportal')) . '\')"');
			echo '<input type="hidden" name="stand_id" value="' . $sid . '"><input type="hidden" name="id" value="' . (int) $gr['id'] . '">';
			submit_button('✕', 'secondary small', 'submit', false);
			echo '</form></td></tr>';
		}
		if ($gruppen === []) {
			echo '<tr><td colspan="5">' . esc_html__('Noch keine Standgruppe. Beispiele: „10 m“ 12 × Stand, Kapazität 1; „Bogen“ 6 × Scheibe, Kapazität 4; „Flinte“ 1 × Rotte, Kapazität 6.', 'ksv-km-meldeportal') . '</td></tr>';
		}
		echo '</tbody></table>';

		$kennzahlen = [];
		$sportjahr = self::current_sportjahr();
		if ($sportjahr !== null) {
			foreach (RegelwerkLader::laden((int) $sportjahr['id'])->disziplinen() as $d) {
				$kennzahlen[ $d->kennzahl ] = $d->kennzahl . ' ' . $d->bezeichnung;
			}
		}
		$aktiv = $g !== null ? array_filter(array_map('trim', explode(',', (string) $g['disziplin_kennzahlen']))) : [];
		foreach ($aktiv as $k) {
			$kennzahlen[ $k ] ??= $k;
		}
		self::form_open('gruppe_speichern', 'class="kmm-block-form"');
		echo '<input type="hidden" name="stand_id" value="' . $sid . '"><input type="hidden" name="id" value="' . ($g !== null ? (int) $g['id'] : 0) . '">';
		echo '<p><strong>' . esc_html($g !== null ? __('Standgruppe bearbeiten', 'ksv-km-meldeportal') : __('Standgruppe hinzufügen', 'ksv-km-meldeportal')) . '</strong></p>';
		echo '<p>' . esc_html__('Bezeichnung', 'ksv-km-meldeportal') . ' ' . self::input('bezeichnung', $g['bezeichnung'] ?? '', 'text', 'class="kmm-short" required placeholder="10 m Stände"') . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo esc_html__('Anzahl', 'ksv-km-meldeportal') . ' ' . self::input('anzahl', $g['anzahl'] ?? 10, 'number', 'class="kmm-num" min="1" max="200" required') . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo esc_html__('Name je Einheit', 'ksv-km-meldeportal') . ' ' . self::input('praefix', $g['praefix'] ?? 'Stand', 'text', 'class="kmm-short" placeholder="Stand"') . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo esc_html__('ab Nummer', 'ksv-km-meldeportal') . ' ' . self::input('nummer_von', $g['nummer_von'] ?? 1, 'number', 'class="kmm-num" min="1"') . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo esc_html__('Kapazität je Einheit', 'ksv-km-meldeportal') . ' ' . self::input('kapazitaet', $g['kapazitaet'] ?? 1, 'number', 'class="kmm-num" min="1" max="200" required') . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo esc_html__('Reihenfolge', 'ksv-km-meldeportal') . ' ' . self::input('sortierung', $g['sortierung'] ?? count($gruppen) + 1, 'number', 'class="kmm-num" min="0"') . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p class="description">' . esc_html__('Beispiel: 12 × „Stand“ ab 1 mit Kapazität 1 ergibt Stand 1 bis Stand 12. Bogenscheiben: „Scheibe“ mit Kapazität 4; Flinte: „Rotte“ mit Kapazität 6.', 'ksv-km-meldeportal') . '</p>';
		echo '<p>' . esc_html__('Nur für Disziplinen (optional, Mehrfachauswahl, z. B. Auflagetische; leer = alle):', 'ksv-km-meldeportal') . '<br><select name="disziplin_kennzahlen[]" multiple size="6" style="min-width:320px">';
		foreach ($kennzahlen as $kz => $label) {
			echo '<option value="' . esc_attr($kz) . '" ' . selected(in_array($kz, $aktiv, true), true, false) . '>' . esc_html($label) . '</option>';
		}
		echo '</select></p>';
		submit_button($g !== null ? __('Standgruppe speichern', 'ksv-km-meldeportal') : __('Standgruppe hinzufügen', 'ksv-km-meldeportal'), 'secondary', 'submit', false);
		if ($g !== null) {
			echo ' <a class="button" href="' . esc_url(self::url(['stand' => $sid])) . '">' . esc_html__('Abbrechen', 'ksv-km-meldeportal') . '</a>';
		}
		echo '</form>';
	}
}
