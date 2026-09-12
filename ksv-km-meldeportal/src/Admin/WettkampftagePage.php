<?php
/**
 * Backend: Wettkampftage aufbauen – Einheiten, Durchgänge, Zulassungen (Konzept 12.3).
 * Lesen: kmm_view; Schreiben: Recht „Startplan bearbeiten“ (Referenten im eigenen Bereich).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Admin;

use KSV\KMM\Application\StartplanService;
use KSV\KMM\Auth\Rechte;
use KSV\KMM\Domain\WettkampftagStatus;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\DurchgangRepository;
use KSV\KMM\Infrastructure\Repository\EinheitRepository;
use KSV\KMM\Infrastructure\Repository\WettkampftagRepository;
use KSV\KMM\Support\Clock;

final class WettkampftagePage extends AdminPage {

	public const SLUG = 'kmm-wettkampftage';

	public static function handle_post(): void {
		if (!self::is_own_post()) {
			return;
		}
		if (!Rechte::darf_lesen()) {
			wp_die(esc_html__('Keine Berechtigung.', 'ksv-km-meldeportal'), '', ['response' => 403]);
		}
		check_admin_referer('kmm_' . self::SLUG);
		$sid = self::post_int('sportjahr_id');
		$tag_id = self::post_int('tag_id');
		$service = new StartplanService($sid);
		$args = array_filter(['sportjahr' => $sid, 'tag' => $tag_id]);
		try {
			switch (self::action()) {
				case 'tag_speichern':
					$id = $service->tag_speichern(self::post_int('id'), [
						'datum'         => self::post_str('datum', 10),
						'bezeichnung'   => self::post_str('bezeichnung', 150),
						'ort'           => self::post_str('ort', 150),
						'buchungsfrist' => Clock::local_to_utc(self::post_str('buchungsfrist')),
						'hinweis'       => self::post_text('hinweis'),
						'beitrag_id'    => self::post_int_or_null('beitrag_id'),
						'ergebnis_url'  => self::post_str('ergebnis_url'),
					]);
					self::redirect(__('Wettkampftag gespeichert.', 'ksv-km-meldeportal'), 'success', ['sportjahr' => $sid, 'tag' => $id]);
				case 'tag_loeschen':
					$service->tag_loeschen(self::post_int('id'));
					self::redirect(__('Wettkampftag gelöscht.', 'ksv-km-meldeportal'), 'success', ['sportjahr' => $sid]);
				case 'einheit_speichern':
					$ids = isset($_POST['disziplin_ids']) && is_array($_POST['disziplin_ids']) ? array_map('intval', $_POST['disziplin_ids']) : [];
					$service->einheit_speichern(self::post_int('id'), $tag_id, self::post_str('bezeichnung', 100), self::post_int('kapazitaet'), $ids, self::post_int('sortierung'));
					self::redirect(__('Einheit gespeichert.', 'ksv-km-meldeportal'), 'success', $args);
				case 'einheit_loeschen':
					$service->einheit_loeschen(self::post_int('id'));
					self::redirect(__('Einheit gelöscht.', 'ksv-km-meldeportal'), 'success', $args);
				case 'durchgang_speichern':
					$id = $service->durchgang_speichern(self::post_int('id'), $tag_id, self::post_int('nummer'), self::post_str('bezeichnung', 100), Clock::local_to_utc(self::post_str('beginn')), Clock::local_to_utc(self::post_str('ende')), self::post_int('sortierung'));
					self::redirect(__('Durchgang gespeichert.', 'ksv-km-meldeportal'), 'success', $args + ['durchgang' => $id]);
				case 'durchgang_loeschen':
					$service->durchgang_loeschen(self::post_int('id'));
					self::redirect(__('Durchgang gelöscht.', 'ksv-km-meldeportal'), 'success', $args);
				case 'zulassung_hinzufuegen':
					$dg = self::post_int('durchgang_id');
					$service->zulassung_hinzufuegen($dg, self::post_int('disziplin_id'), self::post_int_or_null('startklasse_id'));
					self::redirect(__('Zulassung hinzugefügt.', 'ksv-km-meldeportal'), 'success', $args + ['durchgang' => $dg]);
				case 'zulassung_entfernen':
					$service->zulassung_entfernen(self::post_int('id'));
					self::redirect(__('Zulassung entfernt.', 'ksv-km-meldeportal'), 'success', $args + ['durchgang' => self::post_int('durchgang_id')]);
			}
		} catch (\InvalidArgumentException | \RuntimeException $e) {
			self::redirect($e->getMessage(), 'error', $args + (self::post_int('durchgang_id') > 0 ? ['durchgang' => self::post_int('durchgang_id')] : []));
		}
	}

	public static function render(): void {
		if (!Rechte::darf_lesen()) {
			wp_die(esc_html__('Keine Berechtigung.', 'ksv-km-meldeportal'));
		}
		$sportjahr = self::current_sportjahr();
		echo '<div class="wrap kmm-admin">';
		Menu::page_header(__('Wettkampftage und Startplan', 'ksv-km-meldeportal'));
		self::show_notices();
		if ($sportjahr === null) {
			self::no_sportjahr_notice();
			echo '</div>';
			return;
		}
		$sid = (int) $sportjahr['id'];
		$tag_id = isset($_GET['tag']) ? (int) $_GET['tag'] : 0;
		$schreiben = Rechte::hat_recht(Rechte::RECHT_STARTPLAN) && $sportjahr['abgeschlossen_am'] === null;
		if ($tag_id > 0) {
			self::render_tag($sid, $tag_id, $schreiben);
		} else {
			self::sportjahr_selector($sportjahr);
			self::render_liste($sid, $schreiben);
		}
		echo '</div>';
	}

	private static function render_liste(int $sid, bool $schreiben): void {
		$service = new StartplanService($sid);
		$tage = (new WettkampftagRepository())->by_sportjahr($sid);
		echo '<p class="description">' . esc_html__('Ein Wettkampftag besteht aus Einheiten (Stände, Scheiben, Rotten mit Kapazität) und Durchgängen (Beginn, Ende, zugelassene Disziplinen und Startklassen). Platz = Durchgang × Einheit × Position. Im Status „Entwurf“ sehen Vereine nichts; erst die Freigabe öffnet die Buchung.', 'ksv-km-meldeportal') . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Datum', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Bezeichnung / Ort', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Status', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Einheiten', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Durchgänge', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Plätze', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Bedarf', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Buchungsfrist', 'ksv-km-meldeportal') . '</th><th></th></tr></thead><tbody>';
		foreach ($tage as $t) {
			$u = $service->uebersicht((int) $t['id']);
			$bedarf = array_sum(array_column($u['durchgaenge'], 'bedarf'));
			$datum = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $t['datum']);
			echo '<tr><td><strong>' . esc_html($datum !== false ? $datum->format('d.m.Y') : (string) $t['datum']) . '</strong></td>';
			echo '<td>' . esc_html((string) $t['bezeichnung']) . '<br><small>' . esc_html((string) $t['ort']) . '</small></td>';
			echo '<td>' . self::status_badge((string) $t['status']) . '</td>';
			echo '<td class="r">' . count($u['einheiten']) . '</td><td class="r">' . count($u['durchgaenge']) . '</td><td class="r">' . (int) $u['plaetze'] . '</td>';
			echo '<td class="r">' . ($bedarf > $u['plaetze'] ? '<span class="kmm-fail">' . $bedarf . '</span>' : $bedarf) . '</td>';
			echo '<td>' . esc_html($t['buchungsfrist'] !== null ? Clock::format_local($t['buchungsfrist']) : '–') . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url(self::url(['sportjahr' => $sid, 'tag' => (int) $t['id']])) . '">' . esc_html($schreiben ? __('Bearbeiten', 'ksv-km-meldeportal') : __('Ansehen', 'ksv-km-meldeportal')) . '</a></td></tr>';
		}
		if ($tage === []) {
			echo '<tr><td colspan="9">' . esc_html__('Noch kein Wettkampftag.', 'ksv-km-meldeportal') . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__('Bedarf = buchbare Meldungen (eingereicht, Startrecht, nicht abgemeldet, nicht „nicht startberechtigt“), die zu einer Zulassung des Tages passen; rot, wenn mehr Bedarf als Plätze.', 'ksv-km-meldeportal') . '</p>';
		if ($schreiben) {
			echo '<h2>' . esc_html__('Neuer Wettkampftag', 'ksv-km-meldeportal') . '</h2>';
			self::tag_form($sid, null);
		}
	}

	/**
	 * @param array<string, mixed>|null $t
	 */
	private static function tag_form(int $sid, ?array $t): void {
		self::form_open('tag_speichern');
		echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="id" value="' . ($t !== null ? (int) $t['id'] : 0) . '">';
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__('Datum', 'ksv-km-meldeportal') . '</th><td>' . self::input('datum', $t['datum'] ?? '', 'date', 'required') . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th>' . esc_html__('Bezeichnung', 'ksv-km-meldeportal') . '</th><td>' . self::input('bezeichnung', $t['bezeichnung'] ?? '', 'text', 'class="regular-text" placeholder="z. B. KM Luftgewehr / Luftpistole"') . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th>' . esc_html__('Schießstand / Ort', 'ksv-km-meldeportal') . '</th><td>' . self::input('ort', $t['ort'] ?? '', 'text', 'class="regular-text"') . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th>' . esc_html__('Buchungsfrist', 'ksv-km-meldeportal') . '</th><td>' . self::input('buchungsfrist', Clock::utc_to_local_input($t['buchungsfrist'] ?? null), 'datetime-local') . '<p class="description">' . esc_html__('Bis dahin buchen die Vereine selbst (nach Freigabe); danach Restverteilung durch den KSV.', 'ksv-km-meldeportal') . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th>' . esc_html__('Kalenderbeitrag (ID)', 'ksv-km-meldeportal') . '</th><td>' . self::input('beitrag_id', $t['beitrag_id'] ?? '', 'number', 'class="kmm-num" min="0"') . '<p class="description">' . esc_html__('Optional: Beitrag, in dem der Shortcode des Startplans steht.', 'ksv-km-meldeportal') . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th>' . esc_html__('Hinweis', 'ksv-km-meldeportal') . '</th><td><textarea name="hinweis" rows="2" class="large-text">' . esc_textarea((string) ($t['hinweis'] ?? '')) . '</textarea><p class="description">' . esc_html__('Erscheint im Buchungsraster der Vereine und im veröffentlichten Startplan.', 'ksv-km-meldeportal') . '</p></td></tr>';
		echo '</table>';
		submit_button($t !== null ? __('Wettkampftag speichern', 'ksv-km-meldeportal') : __('Wettkampftag anlegen', 'ksv-km-meldeportal'), 'primary', 'submit', false);
		echo '</form>';
	}

	private static function render_tag(int $sid, int $tag_id, bool $schreiben): void {
		$service = new StartplanService($sid);
		try {
			$u = $service->uebersicht($tag_id);
		} catch (\RuntimeException $e) {
			echo '<div class="notice notice-error inline"><p>' . esc_html($e->getMessage()) . '</p></div></div>';
			return;
		}
		$t = $u['tag'];
		$rw = RegelwerkLader::laden($sid);
		$datum = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $t['datum']);
		echo '<p><a href="' . esc_url(self::url(['sportjahr' => $sid])) . '">&larr; ' . esc_html__('Alle Wettkampftage', 'ksv-km-meldeportal') . '</a></p>';
		echo '<h2>' . esc_html(($datum !== false ? $datum->format('d.m.Y') : (string) $t['datum']) . ' ' . (string) $t['bezeichnung']) . ' ' . self::status_badge((string) $t['status']) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="kmm-kacheln">';
		foreach ([[__('Einheiten', 'ksv-km-meldeportal'), count($u['einheiten'])], [__('Durchgänge', 'ksv-km-meldeportal'), count($u['durchgaenge'])], [__('Plätze', 'ksv-km-meldeportal'), (int) $u['plaetze']], [__('Bedarf', 'ksv-km-meldeportal'), array_sum(array_column($u['durchgaenge'], 'bedarf'))], [__('Gebucht', 'ksv-km-meldeportal'), (int) $u['buchungen']]] as [$label, $wert]) {
			echo '<div class="kmm-kachel"><div class="kmm-kachel-wert">' . (int) $wert . '</div><div class="kmm-kachel-label">' . esc_html($label) . '</div></div>';
		}
		echo '</div>';
		if ($schreiben && (string) $t['status'] !== WettkampftagStatus::ENTWURF) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__('Der Wettkampftag ist freigegeben bzw. veröffentlicht. Änderungen an Einheiten und Durchgängen wirken sofort auf die Buchung; Einheiten und Durchgänge mit Buchungen können nicht gelöscht werden.', 'ksv-km-meldeportal') . '</p></div>';
		}

		// Stammdaten des Tags
		echo '<h3>' . esc_html__('Wettkampftag', 'ksv-km-meldeportal') . '</h3>';
		if ($schreiben) {
			self::tag_form($sid, $t);
			if (Rechte::ist_admin()) {
				self::form_open('tag_loeschen', 'class="kmm-inline-form" onsubmit="return confirm(\'' . esc_js(__('Wettkampftag mit allen Einheiten und Durchgängen löschen?', 'ksv-km-meldeportal')) . '\')"');
				echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="id" value="' . $tag_id . '">';
				submit_button(__('Wettkampftag löschen', 'ksv-km-meldeportal'), 'delete small', 'submit', false);
				echo '</form>';
			}
		} else {
			echo '<p>' . esc_html(trim((string) $t['ort'] . ' · ' . ($t['buchungsfrist'] !== null ? __('Buchungsfrist', 'ksv-km-meldeportal') . ' ' . Clock::format_local($t['buchungsfrist']) : ''), ' ·')) . '</p>';
		}

		// Einheiten
		echo '<h3>' . esc_html__('Einheiten (Stände, Scheiben, Rotten)', 'ksv-km-meldeportal') . '</h3>';
		$disziplinen = [];
		foreach ($rw->disziplinen() as $d) {
			$disziplinen[ $d->id ] = $d->kennzahl . ' ' . $d->bezeichnung;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Bezeichnung', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Kapazität', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Beschränkt auf Disziplinen', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Reihenfolge', 'ksv-km-meldeportal') . '</th><th></th></tr></thead><tbody>';
		foreach ($u['einheiten'] as $e) {
			$ids = EinheitRepository::disziplin_ids($e);
			echo '<tr><td>' . esc_html((string) $e['bezeichnung']) . '</td><td class="r">' . (int) $e['kapazitaet'] . '</td><td>' . esc_html($ids === [] ? __('alle', 'ksv-km-meldeportal') : implode(', ', array_map(static fn(int $i): string => $disziplinen[ $i ] ?? '#' . $i, $ids))) . '</td><td class="r">' . (int) $e['sortierung'] . '</td><td class="kmm-nowrap">';
			if ($schreiben) {
				echo '<a class="button button-small" href="' . esc_url(self::url(['sportjahr' => $sid, 'tag' => $tag_id, 'einheit' => (int) $e['id']])) . '">' . esc_html__('Bearbeiten', 'ksv-km-meldeportal') . '</a> ';
				self::form_open('einheit_loeschen', 'class="kmm-inline-form" onsubmit="return confirm(\'' . esc_js(__('Einheit löschen?', 'ksv-km-meldeportal')) . '\')"');
				echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tag_id" value="' . $tag_id . '"><input type="hidden" name="id" value="' . (int) $e['id'] . '">';
				submit_button('✕', 'secondary small', 'submit', false);
				echo '</form>';
			}
			echo '</td></tr>';
		}
		if ($u['einheiten'] === []) {
			echo '<tr><td colspan="5">' . esc_html__('Noch keine Einheit. Beispiele: Stand 1–10 mit Kapazität 1, Bogenscheibe A mit 4 Positionen, Flinte-Rotte 1 mit 6.', 'ksv-km-meldeportal') . '</td></tr>';
		}
		echo '</tbody></table>';
		if ($schreiben) {
			$edit_e = isset($_GET['einheit']) ? (new EinheitRepository())->find((int) $_GET['einheit']) : null;
			if ($edit_e !== null && (int) $edit_e['wettkampftag_id'] !== $tag_id) {
				$edit_e = null;
			}
			$ids = $edit_e !== null ? EinheitRepository::disziplin_ids($edit_e) : [];
			self::form_open('einheit_speichern', 'class="kmm-block-form"');
			echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tag_id" value="' . $tag_id . '"><input type="hidden" name="id" value="' . ($edit_e !== null ? (int) $edit_e['id'] : 0) . '">';
			echo '<p><strong>' . esc_html($edit_e !== null ? __('Einheit bearbeiten', 'ksv-km-meldeportal') : __('Einheit hinzufügen', 'ksv-km-meldeportal')) . '</strong></p>';
			echo '<p>' . esc_html__('Bezeichnung', 'ksv-km-meldeportal') . ' ' . self::input('bezeichnung', $edit_e['bezeichnung'] ?? '', 'text', 'class="kmm-short" required placeholder="Stand 1"') . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo esc_html__('Kapazität', 'ksv-km-meldeportal') . ' ' . self::input('kapazitaet', $edit_e['kapazitaet'] ?? 1, 'number', 'class="kmm-num" min="1" max="200" required') . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo esc_html__('Reihenfolge', 'ksv-km-meldeportal') . ' ' . self::input('sortierung', $edit_e['sortierung'] ?? count($u['einheiten']) + 1, 'number', 'class="kmm-num" min="0"') . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<p>' . esc_html__('Nur für Disziplinen (optional, Mehrfachauswahl; leer = alle):', 'ksv-km-meldeportal') . '<br><select name="disziplin_ids[]" multiple size="6" style="min-width:320px">';
			foreach ($disziplinen as $did => $label) {
				echo '<option value="' . (int) $did . '" ' . selected(in_array($did, $ids, true), true, false) . '>' . esc_html($label) . '</option>';
			}
			echo '</select></p>';
			submit_button($edit_e !== null ? __('Einheit speichern', 'ksv-km-meldeportal') : __('Einheit hinzufügen', 'ksv-km-meldeportal'), 'secondary', 'submit', false);
			if ($edit_e !== null) {
				echo ' <a class="button" href="' . esc_url(self::url(['sportjahr' => $sid, 'tag' => $tag_id])) . '">' . esc_html__('Abbrechen', 'ksv-km-meldeportal') . '</a>';
			}
			echo '</form>';
		}

		// Durchgänge
		echo '<h3>' . esc_html__('Durchgänge', 'ksv-km-meldeportal') . '</h3>';
		$edit_dg = isset($_GET['durchgang']) ? (int) $_GET['durchgang'] : 0;
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Nr.', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Zeit', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Bezeichnung', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Zugelassen', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Plätze', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Bedarf', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Gebucht', 'ksv-km-meldeportal') . '</th><th></th></tr></thead><tbody>';
		foreach ($u['durchgaenge'] as $dg) {
			echo '<tr' . ($dg['zulassungen'] === [] ? ' class="kmm-zeile-warn"' : '') . '><td>' . (int) $dg['nummer'] . '</td><td>' . esc_html($dg['zeit']) . '</td><td>' . esc_html($dg['bezeichnung']) . '</td>';
			echo '<td>' . ($dg['zulassungen'] === [] ? '<span class="kmm-warn">' . esc_html__('keine Zulassung – niemand kann buchen', 'ksv-km-meldeportal') . '</span>' : esc_html(implode('; ', array_map(static fn(array $z): string => $z['disziplin'] . ' (' . $z['startklasse'] . ')', $dg['zulassungen'])))) . '</td>';
			echo '<td class="r">' . (int) $dg['plaetze'] . '</td><td class="r">' . ($dg['bedarf'] > $dg['plaetze'] ? '<span class="kmm-fail">' . (int) $dg['bedarf'] . '</span>' : (int) $dg['bedarf']) . '</td><td class="r">' . (int) $dg['gebucht'] . '</td>';
			echo '<td class="kmm-nowrap">';
			if ($schreiben && $dg['zustaendig']) {
				echo '<a class="button button-small" href="' . esc_url(self::url(['sportjahr' => $sid, 'tag' => $tag_id, 'durchgang' => (int) $dg['id']])) . '">' . esc_html__('Bearbeiten', 'ksv-km-meldeportal') . '</a> ';
				self::form_open('durchgang_loeschen', 'class="kmm-inline-form" onsubmit="return confirm(\'' . esc_js(__('Durchgang mit Zulassungen löschen?', 'ksv-km-meldeportal')) . '\')"');
				echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tag_id" value="' . $tag_id . '"><input type="hidden" name="id" value="' . (int) $dg['id'] . '">';
				submit_button('✕', 'secondary small', 'submit', false);
				echo '</form>';
			}
			echo '</td></tr>';
		}
		if ($u['durchgaenge'] === []) {
			echo '<tr><td colspan="8">' . esc_html__('Noch kein Durchgang.', 'ksv-km-meldeportal') . '</td></tr>';
		}
		echo '</tbody></table>';

		if (!$schreiben) {
			return;
		}
		$dg_row = $edit_dg > 0 ? (new DurchgangRepository())->find($edit_dg) : null;
		if ($dg_row !== null && ((int) $dg_row['wettkampftag_id'] !== $tag_id || !$service->durchgang_zustaendig($edit_dg))) {
			$dg_row = null;
			$edit_dg = 0;
		}
		self::form_open('durchgang_speichern', 'class="kmm-block-form"');
		echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tag_id" value="' . $tag_id . '"><input type="hidden" name="id" value="' . $edit_dg . '">';
		echo '<p><strong>' . esc_html($dg_row !== null ? sprintf(__('Durchgang %d bearbeiten', 'ksv-km-meldeportal'), (int) $dg_row['nummer']) : __('Durchgang hinzufügen', 'ksv-km-meldeportal')) . '</strong></p>';
		$std_beginn = (string) $t['datum'] . 'T09:00';
		echo '<p>' . esc_html__('Nr.', 'ksv-km-meldeportal') . ' ' . self::input('nummer', $dg_row['nummer'] ?? count($u['durchgaenge']) + 1, 'number', 'class="kmm-num" min="0"') . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo esc_html__('Beginn', 'ksv-km-meldeportal') . ' ' . self::input('beginn', $dg_row !== null ? Clock::utc_to_local_input($dg_row['beginn']) : $std_beginn, 'datetime-local', 'required') . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo esc_html__('Ende', 'ksv-km-meldeportal') . ' ' . self::input('ende', $dg_row !== null ? Clock::utc_to_local_input($dg_row['ende']) : (string) $t['datum'] . 'T10:30', 'datetime-local', 'required') . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo esc_html__('Bezeichnung', 'ksv-km-meldeportal') . ' ' . self::input('bezeichnung', $dg_row['bezeichnung'] ?? '', 'text', 'class="kmm-short" placeholder="optional"') . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		submit_button($dg_row !== null ? __('Durchgang speichern', 'ksv-km-meldeportal') : __('Durchgang hinzufügen', 'ksv-km-meldeportal'), 'secondary', 'submit', false);
		if ($dg_row !== null) {
			echo ' <a class="button" href="' . esc_url(self::url(['sportjahr' => $sid, 'tag' => $tag_id])) . '">' . esc_html__('Schließen', 'ksv-km-meldeportal') . '</a>';
		}
		echo '</form>';

		if ($dg_row !== null) {
			self::render_zulassungen($sid, $tag_id, $service, $u, $edit_dg);
		}
	}

	/**
	 * @param array<string, mixed> $u
	 */
	private static function render_zulassungen(int $sid, int $tag_id, StartplanService $service, array $u, int $dg_id): void {
		$rw = RegelwerkLader::laden($sid);
		$dg = null;
		foreach ($u['durchgaenge'] as $x) {
			if ($x['id'] === $dg_id) {
				$dg = $x;
			}
		}
		if ($dg === null) {
			return;
		}
		echo '<h4>' . esc_html(sprintf(__('Zulassungen für Durchgang %d (%s)', 'ksv-km-meldeportal'), $dg['nummer'], $dg['zeit'])) . '</h4>';
		echo '<p class="description">' . esc_html__('Wer hier buchen darf: Disziplin mit allen oder einzelnen Startklassen. Mehrere Disziplinen pro Durchgang sind möglich (z. B. Luftgewehr und Luftpistole parallel).', 'ksv-km-meldeportal') . '</p>';
		if ($dg['zulassungen'] !== []) {
			echo '<ul class="kmm-liste-kompakt">';
			foreach ($dg['zulassungen'] as $z) {
				echo '<li>' . esc_html($z['disziplin'] . ' – ' . $z['startklasse']) . ' ';
				if ($z['zustaendig']) {
					self::form_open('zulassung_entfernen', 'class="kmm-inline-form"');
					echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tag_id" value="' . $tag_id . '"><input type="hidden" name="durchgang_id" value="' . $dg_id . '"><input type="hidden" name="id" value="' . (int) $z['id'] . '">';
					submit_button('✕', 'secondary small', 'submit', false);
					echo '</form>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}
		$disziplinen = ['' => __('– Disziplin –', 'ksv-km-meldeportal')];
		$klassen = ['' => __('alle Startklassen', 'ksv-km-meldeportal')];
		$gesehen = [];
		foreach (Rechte::zustaendige($rw->disziplinen()) as $d) {
			$disziplinen[ $d->id ] = $d->kennzahl . ' ' . $d->bezeichnung;
			foreach ($service->startklassen($d) as $k) {
				if (!isset($gesehen[ $k->id ])) {
					$gesehen[ $k->id ] = true;
					$klassen[ $k->id ] = $k->bezeichnung . ' (' . $k->gruppe . ')';
				}
			}
		}
		self::form_open('zulassung_hinzufuegen', 'class="kmm-inline-form"');
		echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tag_id" value="' . $tag_id . '"><input type="hidden" name="durchgang_id" value="' . $dg_id . '">';
		echo self::select('disziplin_id', $disziplinen, '', 'required') . ' ' . self::select('startklasse_id', $klassen, '') . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		submit_button(__('Zulassung hinzufügen', 'ksv-km-meldeportal'), 'secondary', 'submit', false);
		echo '</form>';
		echo '<p class="description">' . esc_html__('Die Startklasse muss in der gewählten Disziplin eine Klasse mit eigener Wertung sein; „alle Startklassen“ ist der Normalfall.', 'ksv-km-meldeportal') . '</p>';
	}

	private static function status_badge(string $status): string {
		$klasse = match ($status) {
			WettkampftagStatus::FREIGEGEBEN => 'kmm-badge-eingereicht',
			WettkampftagStatus::VEROEFFENTLICHT => 'kmm-badge-verarbeitet',
			default => 'kmm-badge-entwurf',
		};
		return '<span class="kmm-badge ' . esc_attr($klasse) . '">' . esc_html(WettkampftagStatus::label($status)) . '</span>';
	}
}
