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
use KSV\KMM\Application\Startplatzvergabe;
use KSV\KMM\Auth\Rechte;
use KSV\KMM\Domain\WettkampftagStatus;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\DurchgangRepository;
use KSV\KMM\Infrastructure\Repository\EinheitRepository;
use KSV\KMM\Infrastructure\Repository\StandgruppeRepository;
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
						'schiessstand_id' => self::post_int_or_null('schiessstand_id'),
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
				case 'staende_freigeben':
					$auswahl = [];
					if (isset($_POST['stand']) && is_array($_POST['stand'])) {
						foreach ($_POST['stand'] as $gid => $nummern) {
							$auswahl[ (int) $gid ] = is_array($nummern) ? array_map('intval', $nummern) : [];
						}
					}
					$r = $service->einheiten_aus_schiessstand($tag_id, $auswahl);
					self::redirect(sprintf(__('Stände übernommen: %1$d neu, %2$d entfernt, %3$d unverändert.', 'ksv-km-meldeportal'), $r['angelegt'], $r['entfernt'], $r['behalten']), 'success', $args);
				case 'durchgang_speichern':
					$id = $service->durchgang_speichern(self::post_int('id'), $tag_id, self::post_int('nummer'), self::post_str('bezeichnung', 100), Clock::local_to_utc(self::post_str('beginn')), Clock::local_to_utc(self::post_str('ende')), self::post_int('sortierung'));
					self::redirect(__('Durchgang gespeichert.', 'ksv-km-meldeportal'), 'success', $args + ['durchgang' => $id]);
				case 'durchgang_duplizieren':
					$neu = $service->durchgang_duplizieren(self::post_int('id'), self::post_int('anzahl') ?: 1, self::post_int('pause'));
					self::redirect(sprintf(_n('%d Durchgang kopiert.', '%d Durchgänge kopiert.', count($neu), 'ksv-km-meldeportal'), count($neu)), 'success', $args);
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
				case 'freigeben':
					$r = $service->freigeben(self::post_int('id'));
					$text = sprintf(__('Wettkampftag freigegeben. Mail an %1$d von %2$d Vereinen mit passenden Startern gesendet.', 'ksv-km-meldeportal'), $r['gesendet'], $r['vereine']);
					if ($r['fehler'] !== []) {
						$text .= "\n" . implode("\n", $r['fehler']);
					}
					self::redirect($text, $r['fehler'] === [] ? 'success' : 'warning', $args);
				case 'freigabe_zurueck':
					$service->freigabe_zuruecknehmen(self::post_int('id'));
					self::redirect(__('Freigabe zurückgenommen; der Wettkampftag ist wieder im Entwurf.', 'ksv-km-meldeportal'), 'success', $args);
				case 'restverteilung':
					$r = (new Startplatzvergabe($sid))->restverteilung($tag_id);
					$text = sprintf(__('Restverteilung: %d Starter auf freie Plätze gesetzt.', 'ksv-km-meldeportal'), $r['verteilt']);
					if ($r['offen'] !== []) {
						$text .= ' ' . sprintf(__('%d Starter blieben ohne Platz.', 'ksv-km-meldeportal'), count($r['offen']));
					}
					self::redirect($text, $r['offen'] === [] ? 'success' : 'warning', $args);
				case 'verschieben':
					$ziel = array_map('intval', explode('-', self::post_str('ziel', 40)));
					if (count($ziel) !== 3) {
						throw new \RuntimeException(__('Kein Zielplatz gewählt.', 'ksv-km-meldeportal'));
					}
					$r = (new Startplatzvergabe($sid))->verschieben(self::post_int('buchung_id'), $ziel[0], $ziel[1], $ziel[2]);
					self::redirect($r['getauscht'] ? sprintf(__('Plätze getauscht mit %s.', 'ksv-km-meldeportal'), $r['mit']) : __('Starter verschoben.', 'ksv-km-meldeportal'), 'success', $args);
				case 'veroeffentlichen':
					$r = $service->veroeffentlichen($tag_id, self::post_int('benachrichtigen') === 1);
					$text = __('Startplan veröffentlicht.', 'ksv-km-meldeportal');
					if ($r['vereine'] > 0) {
						$text .= ' ' . sprintf(__('Mail an %1$d von %2$d Vereinen.', 'ksv-km-meldeportal'), $r['gesendet'], $r['vereine']);
					}
					if ($r['fehler'] !== []) {
						$text .= "\n" . implode("\n", $r['fehler']);
					}
					self::redirect($text, $r['fehler'] === [] ? 'success' : 'warning', $args);
				case 'startplan_pdf':
					$pdf = (new \KSV\KMM\Application\PdfStartplan())->erzeugen($tag_id, self::post_str('disziplin', 16));
					nocache_headers();
					header('Content-Type: application/pdf');
					header('Content-Disposition: attachment; filename="' . $pdf['dateiname'] . '"');
					header('Content-Length: ' . strlen($pdf['inhalt']));
					echo $pdf['inhalt']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					exit;
				case 'veroeffentlichung_zurueck':
					$service->veroeffentlichung_zuruecknehmen($tag_id);
					self::redirect(__('Veröffentlichung zurückgenommen; der Startplan ist wieder nur für die Vereine sichtbar.', 'ksv-km-meldeportal'), 'success', $args);
			}
		} catch (\InvalidArgumentException | \RuntimeException $e) {
			self::redirect($e->getMessage(), 'error', $args + (self::post_int('durchgang_id') > 0 ? ['durchgang' => self::post_int('durchgang_id')] : []));
		}
	}

	/**
	 * Verschieben aus der Startplan-Matrix (Ziehen und Fallenlassen). Antwortet mit JSON,
	 * damit die Seite nicht bei jedem Zug neu lädt. Ohne JavaScript bleibt das Formular
	 * in der Buchungsliste der Weg.
	 */
	public static function ajax_verschieben(): void {
		check_ajax_referer('kmm_' . self::SLUG, 'nonce');
		if (!Rechte::hat_recht(Rechte::RECHT_STARTPLAN)) {
			wp_send_json_error(['message' => __('Keine Berechtigung.', 'ksv-km-meldeportal')], 403);
		}
		$sid = isset($_POST['sportjahr_id']) ? (int) $_POST['sportjahr_id'] : 0;
		$buchung = isset($_POST['buchung_id']) ? (int) $_POST['buchung_id'] : 0;
		$durchgang = isset($_POST['durchgang_id']) ? (int) $_POST['durchgang_id'] : 0;
		$einheit = isset($_POST['einheit_id']) ? (int) $_POST['einheit_id'] : 0;
		$position = isset($_POST['position']) ? (int) $_POST['position'] : 0;
		try {
			$r = (new Startplatzvergabe($sid))->verschieben($buchung, $durchgang, $einheit, $position);
		} catch (\InvalidArgumentException | \RuntimeException $e) {
			wp_send_json_error(['message' => $e->getMessage()]);
		}
		wp_send_json_success([
			'getauscht' => $r['getauscht'],
			'message'   => $r['getauscht']
				? sprintf(__('Plätze getauscht mit %s.', 'ksv-km-meldeportal'), $r['mit'])
				: __('Starter verschoben.', 'ksv-km-meldeportal'),
		]);
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
		$staende = ['' => __('– kein Schießstand aus den Stammdaten –', 'ksv-km-meldeportal')];
		foreach ((new \KSV\KMM\Infrastructure\Repository\SchiessstandRepository())->alle() as $st) {
			$staende[ (int) $st['id'] ] = (string) $st['bezeichnung'] . ((string) $st['ort'] !== '' ? ' (' . (string) $st['ort'] . ')' : '');
		}
		echo '<tr><th>' . esc_html__('Schießstand', 'ksv-km-meldeportal') . '</th><td>' . self::select('schiessstand_id', $staende, (string) ($t['schiessstand_id'] ?? '')) . '<p class="description">' . esc_html__('Stände des Schießstands lassen sich dann per Ankreuzen freigeben.', 'ksv-km-meldeportal') . ' <a href="' . esc_url(Menu::url(SchiessstaendePage::SLUG)) . '">' . esc_html__('Schießstände verwalten', 'ksv-km-meldeportal') . '</a></p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th>' . esc_html__('Ort (Anzeige)', 'ksv-km-meldeportal') . '</th><td>' . self::input('ort', $t['ort'] ?? '', 'text', 'class="regular-text"') . '<p class="description">' . esc_html__('Leer lassen, um Bezeichnung und Ort des Schießstands zu übernehmen.', 'ksv-km-meldeportal') . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
		if ($schreiben) {
			self::render_freigabe($sid, $tag_id, $t, $u);
		}
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

		// Stände des Schießstands per Ankreuzen freigeben
		if ($t['schiessstand_id'] !== null) {
			self::render_staende($sid, $tag_id, (int) $t['schiessstand_id'], $schreiben);
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
			echo '<tr><td>' . esc_html((string) $e['bezeichnung']) . ($e['standgruppe_id'] !== null ? ' <small class="kmm-muted">' . esc_html__('(Schießstand)', 'ksv-km-meldeportal') . '</small>' : '') . '</td><td class="r">' . (int) $e['kapazitaet'] . '</td><td>' . esc_html($ids === [] ? __('alle', 'ksv-km-meldeportal') : implode(', ', array_map(static fn(int $i): string => $disziplinen[ $i ] ?? '#' . $i, $ids))) . '</td><td class="r">' . (int) $e['sortierung'] . '</td><td class="kmm-nowrap">';
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
				self::form_open('durchgang_duplizieren', 'class="kmm-inline-form kmm-duplizieren"');
				echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tag_id" value="' . $tag_id . '"><input type="hidden" name="id" value="' . (int) $dg['id'] . '">';
				echo '<input type="number" name="anzahl" value="1" min="1" max="50" class="kmm-num" title="' . esc_attr__('Zahl der Kopien', 'ksv-km-meldeportal') . '" aria-label="' . esc_attr__('Zahl der Kopien', 'ksv-km-meldeportal') . '">';
				echo '<input type="number" name="pause" value="0" min="0" max="600" step="5" class="kmm-num" title="' . esc_attr__('Pause zwischen den Durchgängen in Minuten', 'ksv-km-meldeportal') . '" aria-label="' . esc_attr__('Pause in Minuten', 'ksv-km-meldeportal') . '">';
				submit_button(__('Duplizieren', 'ksv-km-meldeportal'), 'secondary small', 'submit', false);
				echo '</form> ';
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
		if ($schreiben && $u['durchgaenge'] !== []) {
			echo '<p class="description">' . esc_html__('„Duplizieren“ hängt Kopien mit gleicher Dauer, Bezeichnung und denselben Zulassungen hinten an: erste Zahl = Zahl der Kopien, zweite Zahl = Pause dazwischen in Minuten.', 'ksv-km-meldeportal') . '</p>';
		}

		self::render_matrix($sid, $tag_id, $schreiben);
		self::render_buchungen($sid, $tag_id, $u, $schreiben);
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

	private static function render_staende(int $sid, int $tag_id, int $schiessstand_id, bool $schreiben): void {
		$service = new \KSV\KMM\Application\SchiessstandService();
		try {
			$stand = $service->stand($schiessstand_id);
		} catch (\RuntimeException $e) {
			return;
		}
		$gruppen = $service->auswahl($schiessstand_id, $tag_id);
		echo '<h3>' . esc_html(sprintf(__('Stände freigeben: %s', 'ksv-km-meldeportal'), (string) $stand['bezeichnung'])) . '</h3>';
		if ($gruppen === []) {
			echo '<p class="description">' . esc_html__('Der Schießstand hat noch keine Standgruppen.', 'ksv-km-meldeportal') . ' <a href="' . esc_url(Menu::url(SchiessstaendePage::SLUG, ['stand' => $schiessstand_id])) . '">' . esc_html__('Standgruppen anlegen', 'ksv-km-meldeportal') . '</a></p>';
			return;
		}
		echo '<p class="description">' . esc_html__('Ankreuzen, welche Stände an diesem Wettkampftag zur Verfügung stehen. Abgewählte Stände werden entfernt, sofern sie keine Buchungen haben; von Hand angelegte Einheiten bleiben unberührt.', 'ksv-km-meldeportal') . '</p>';
		self::form_open('staende_freigeben', 'class="kmm-block-form" id="kmm-staende"');
		echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tag_id" value="' . $tag_id . '">';
		foreach ($gruppen as $g) {
			$gid = (int) $g['id'];
			echo '<div class="kmm-standgruppe"><p><strong>' . esc_html((string) $g['bezeichnung']) . '</strong> <span class="description">' . esc_html(sprintf(__('%1$d × %2$s, Kapazität %3$d', 'ksv-km-meldeportal'), (int) $g['anzahl'], $g['praefix'], (int) $g['kapazitaet'])) . ((string) $g['disziplin_kennzahlen'] !== '' ? ' · ' . esc_html__('nur', 'ksv-km-meldeportal') . ' ' . esc_html((string) $g['disziplin_kennzahlen']) : '') . '</span>';
			if ($schreiben) {
				echo ' <button type="button" class="button-link kmm-alle" data-gruppe="' . $gid . '" data-wert="1">' . esc_html__('alle', 'ksv-km-meldeportal') . '</button> · <button type="button" class="button-link kmm-alle" data-gruppe="' . $gid . '" data-wert="0">' . esc_html__('keine', 'ksv-km-meldeportal') . '</button>';
			}
			echo '</p><div class="kmm-staende">';
			foreach ($g['nummern'] as $nr) {
				$aktiv = isset($g['freigegeben'][ $nr ]);
				echo '<label class="kmm-stand ' . ($aktiv ? 'is-aktiv' : '') . '"><input type="checkbox" name="stand[' . $gid . '][]" value="' . (int) $nr . '" data-gruppe="' . $gid . '" ' . checked($aktiv, true, false) . ($schreiben ? '' : ' disabled') . '> ' . esc_html(StandgruppeRepository::bezeichnung($g, (int) $nr)) . '</label>';
			}
			echo '</div></div>';
		}
		if ($schreiben) {
			submit_button(__('Stände übernehmen', 'ksv-km-meldeportal'), 'primary', 'submit', false);
		}
		echo '</form>';
		echo '<script>document.querySelectorAll("#kmm-staende .kmm-alle").forEach(function(b){b.addEventListener("click",function(){document.querySelectorAll("#kmm-staende input[data-gruppe=\'"+b.dataset.gruppe+"\']").forEach(function(c){c.checked=b.dataset.wert==="1";});});});</script>';
	}

	/**
	 * @param array<string, mixed> $t
	 * @param array<string, mixed> $u
	 */
	private static function render_freigabe(int $sid, int $tag_id, array $t, array $u): void {
		$status = (string) $t['status'];
		echo '<div class="kmm-phase">';
		if ($status === WettkampftagStatus::ENTWURF) {
			$betroffene = \KSV\KMM\Application\BuchungService::betroffene_vereine($sid, $tag_id);
			echo '<p>' . esc_html(sprintf(__('Entwurf: Vereine sehen den Startplan noch nicht. Mit der Freigabe öffnet sich die Buchung; %d Vereine mit passenden Startern erhalten eine Mail mit ihrem Link.', 'ksv-km-meldeportal'), count($betroffene))) . '</p>';
			$bereit = $u['einheiten'] !== [] && $u['durchgaenge'] !== [] && array_sum(array_map(static fn(array $d): int => $d['zulassungen'] === [] ? 1 : 0, $u['durchgaenge'])) === 0;
			self::form_open('freigeben', 'class="kmm-inline-form" onsubmit="return confirm(\'' . esc_js(sprintf(__('Wettkampftag freigeben und %d Vereine benachrichtigen?', 'ksv-km-meldeportal'), count($betroffene))) . '\')"');
			echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tag_id" value="' . $tag_id . '"><input type="hidden" name="id" value="' . $tag_id . '">';
			submit_button(__('Freigeben und Vereine benachrichtigen', 'ksv-km-meldeportal'), 'primary', 'submit', false, $bereit ? [] : ['disabled' => 'disabled']);
			echo '</form>';
			if (!$bereit) {
				echo ' <span class="description">' . esc_html__('Vorher mindestens eine Einheit und einen Durchgang mit Zulassung anlegen.', 'ksv-km-meldeportal') . '</span>';
			}
		} elseif ($status === WettkampftagStatus::FREIGEGEBEN) {
			$offen = \KSV\KMM\Application\BuchungService::buchung_offen($t);
			echo '<p>' . esc_html(sprintf(__('Freigegeben am %s. %s', 'ksv-km-meldeportal'), Clock::format_local($t['freigegeben_am']), $offen['offen'] ? __('Die Vereine buchen bis zur Frist selbst.', 'ksv-km-meldeportal') : $offen['grund']));
			if ($t['erinnerung_am'] !== null) {
				echo ' ' . esc_html(sprintf(__('Erinnerung an Vereine mit Startern ohne Platz: %s%s.', 'ksv-km-meldeportal'), Clock::format_local($t['erinnerung_am']), $t['erinnerung_gesendet_am'] !== null ? ' (gesendet)' : ''));
			}
			echo '</p>';
			if ((int) $u['buchungen'] === 0) {
				self::form_open('freigabe_zurueck', 'class="kmm-inline-form"');
				echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tag_id" value="' . $tag_id . '"><input type="hidden" name="id" value="' . $tag_id . '">';
				submit_button(__('Freigabe zurücknehmen', 'ksv-km-meldeportal'), 'secondary', 'submit', false);
				echo '</form>';
			}
			self::render_nach_frist($sid, $tag_id, $t);
		} else {
			echo '<p>' . esc_html(sprintf(__('Veröffentlicht am %s. Der Startplan ist öffentlich sichtbar; Änderungen des KSV wirken sofort.', 'ksv-km-meldeportal'), Clock::format_local($t['veroeffentlicht_am']))) . '</p>';
			self::render_nach_frist($sid, $tag_id, $t);
			self::form_open('veroeffentlichung_zurueck', 'class="kmm-inline-form" onsubmit="return confirm(\'' . esc_js(__('Veröffentlichung zurücknehmen? Der Startplan verschwindet aus der Öffentlichkeit.', 'ksv-km-meldeportal')) . '\')"');
			echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tag_id" value="' . $tag_id . '">';
			submit_button(__('Veröffentlichung zurücknehmen', 'ksv-km-meldeportal'), 'secondary', 'submit', false);
			echo '</form>';
		}
		echo '</div>';
	}

	/**
	 * Nach der Buchungsfrist (Konzept 12.5): Restverteilung und Veröffentlichung.
	 *
	 * @param array<string, mixed> $t
	 */
	private static function render_nach_frist(int $sid, int $tag_id, array $t): void {
		$vergabe = new Startplatzvergabe($sid);
		$offen = $vergabe->ohne_platz($tag_id);
		$veroeffentlicht = (string) $t['status'] === WettkampftagStatus::VEROEFFENTLICHT;
		$frist_vorbei = $veroeffentlicht || $t['buchungsfrist'] === null || (string) $t['buchungsfrist'] <= Clock::now_utc();
		echo '<hr>';
		echo '<p><strong>' . esc_html__('Nach der Buchungsfrist', 'ksv-km-meldeportal') . '</strong></p>';
		if (!$frist_vorbei) {
			echo '<p class="description">' . esc_html__('Die Buchungsfrist läuft noch. Restverteilung und Veröffentlichung sind erst danach möglich.', 'ksv-km-meldeportal') . '</p>';
			return;
		}
		if ($offen === []) {
			echo '<p>' . esc_html__('Alle Starter dieses Tages haben einen Platz.', 'ksv-km-meldeportal') . '</p>';
		} else {
			$vorschau = $vergabe->restverteilung($tag_id, true);
			echo '<p>' . esc_html(sprintf(_n('%d Starter hat noch keinen Platz.', '%d Starter haben noch keinen Platz.', count($offen), 'ksv-km-meldeportal'), count($offen)));
			echo ' ' . esc_html(sprintf(__('„Rest verteilen“ würde %1$d davon setzen; %2$d blieben ohne Platz.', 'ksv-km-meldeportal'), $vorschau['verteilt'], count($vorschau['offen']))) . '</p>';
			echo '<ul class="kmm-liste-kompakt">';
			foreach (array_slice($offen, 0, 20) as $o) {
				echo '<li>' . esc_html($o['name'] . ' (' . $o['verein'] . ') – ' . $o['disziplin']) . '</li>';
			}
			if (count($offen) > 20) {
				echo '<li class="kmm-muted">' . esc_html(sprintf(__('… und %d weitere', 'ksv-km-meldeportal'), count($offen) - 20)) . '</li>';
			}
			echo '</ul>';
			self::form_open('restverteilung', 'class="kmm-inline-form" onsubmit="return confirm(\'' . esc_js(__('Alle Starter ohne Platz automatisch auf freie, passende Plätze setzen?', 'ksv-km-meldeportal')) . '\')"');
			echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tag_id" value="' . $tag_id . '">';
			submit_button(__('Rest verteilen', 'ksv-km-meldeportal'), 'primary', 'submit', false);
			echo '</form> ';
		}
		self::form_open('startplan_pdf', 'class="kmm-inline-form"');
		echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tag_id" value="' . $tag_id . '">';
		submit_button(__('Startplan als PDF', 'ksv-km-meldeportal'), 'secondary', 'submit', false);
		echo '</form> ';
		if ($veroeffentlicht) {
			echo '<p class="description">' . esc_html__('Shortcode für einen Beitrag (auch im Kalendertermin):', 'ksv-km-meldeportal') . ' <code>[kmm_startplan tag="' . $tag_id . '"]</code> ' . esc_html__('– optional mit', 'ksv-km-meldeportal') . ' <code>disziplin="1.10"</code>.</p>';
		}
		if (!$veroeffentlicht) {
			self::form_open('veroeffentlichen', 'class="kmm-inline-form" onsubmit="return confirm(\'' . esc_js(__('Startplan veröffentlichen? Er ist dann über den Shortcode öffentlich sichtbar.', 'ksv-km-meldeportal')) . '\')"');
			echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tag_id" value="' . $tag_id . '">';
			echo '<label class="kmm-stand"><input type="checkbox" name="benachrichtigen" value="1" checked> ' . esc_html__('Vereine per Mail informieren', 'ksv-km-meldeportal') . '</label> ';
			submit_button(__('Startplan veröffentlichen', 'ksv-km-meldeportal'), 'primary', 'submit', false);
			echo '</form>';
			echo '<p class="description">' . esc_html__('Nach der Veröffentlichung buchen die Vereine nicht mehr selbst; Änderungen nimmt nur noch der KSV vor und sie sind sofort sichtbar.', 'ksv-km-meldeportal') . '</p>';
		}
	}

	/**
	 * @param array<string, mixed> $u
	 */
	/**
	 * Der Startplan als Matrix: Zeilen = Durchgänge, Spalten = Plätze. Belegte Felder
	 * lassen sich mit der Maus auf einen anderen Platz ziehen; ist der belegt, tauschen
	 * beide. Ohne Maus (und ohne JavaScript-Unterstützung) geht dasselbe über Anklicken
	 * des Starters und dann des Zielplatzes.
	 */
	private static function render_matrix(int $sid, int $tag_id, bool $schreiben): void {
		$m = (new Startplatzvergabe($sid))->matrix($tag_id);
		if ($m['spalten'] === [] || $m['durchgaenge'] === []) {
			return;
		}
		echo '<h3>' . esc_html__('Startplan', 'ksv-km-meldeportal') . '</h3>';
		echo '<p class="description">' . esc_html(sprintf(__('%1$d von %2$d Plätzen belegt.', 'ksv-km-meldeportal'), (int) $m['gebucht'], (int) $m['plaetze']));
		if ($schreiben) {
			echo ' ' . esc_html__('Starter mit der Maus auf einen anderen Platz ziehen – oder den Starter anklicken und dann den Zielplatz. Ist der Zielplatz belegt, tauschen beide.', 'ksv-km-meldeportal');
		}
		echo '</p>';
		echo '<div class="kmm-matrix-huelle"><table class="kmm-matrix' . ($schreiben ? ' is-bearbeitbar' : '') . '" data-sportjahr="' . $sid . '"><thead><tr><th class="kmm-matrix-zeit">' . esc_html__('Zeit', 'ksv-km-meldeportal') . '</th>';
		foreach ($m['spalten'] as $sp) {
			echo '<th>' . esc_html((string) $sp['label']) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ($m['durchgaenge'] as $dg) {
			$bearbeitbar = $schreiben && $dg['zustaendig'];
			echo '<tr><th class="kmm-matrix-zeit" scope="row"><strong>' . esc_html($dg['beginn']) . '</strong><span>' . esc_html('bis ' . $dg['ende']) . '</span>';
			echo '<span>' . esc_html(sprintf(__('Durchgang %d', 'ksv-km-meldeportal'), (int) $dg['nummer'])) . '</span>';
			if ($dg['zulassungen'] !== '') {
				echo '<span class="kmm-matrix-zul">' . esc_html((string) $dg['zulassungen']) . '</span>';
			}
			echo '</th>';
			foreach ($m['spalten'] as $sp) {
				$key = (string) $sp['key'];
				$z = $dg['zellen'][ $key ] ?? null;
				if (!$dg['erlaubt'][ $key ] && $z === null) {
					echo '<td class="kmm-matrix-gesperrt" title="' . esc_attr__('In diesem Durchgang nicht vorgesehen', 'ksv-km-meldeportal') . '"></td>';
					continue;
				}
				$attr = ' data-durchgang="' . (int) $dg['id'] . '" data-einheit="' . (int) $sp['einheit_id'] . '" data-position="' . (int) $sp['position'] . '"';
				$attr .= ' data-platz="' . esc_attr(sprintf(__('Durchgang %1$d, %2$s', 'ksv-km-meldeportal'), (int) $dg['nummer'], (string) $sp['label'])) . '"';
				if ($z === null) {
					echo '<td class="kmm-matrix-frei"' . $attr . '><span>' . esc_html__('frei', 'ksv-km-meldeportal') . '</span></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					continue;
				}
				echo '<td class="kmm-matrix-belegt"' . $attr . ' data-buchung="' . (int) $z['buchung_id'] . '"' . ($bearbeitbar ? ' draggable="true" tabindex="0"' : '') . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<span class="kmm-matrix-verein">' . esc_html((string) $z['verein']) . '</span>';
				echo '<strong>' . esc_html((string) $z['name']) . '</strong>';
				echo '<span class="kmm-matrix-klasse">' . esc_html(trim((string) $z['kennzahl'] . ' · ' . (string) $z['klasse'], ' ·')) . '</span>';
				echo '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		echo '<p class="kmm-matrix-status" role="status" aria-live="polite"></p>';
	}

	/**
	 * Buchungen mit Verschieben/Tauschen (Konzept 12.5).
	 *
	 * @param array<string, mixed> $u
	 */
	private static function render_buchungen(int $sid, int $tag_id, array $u, bool $schreiben): void {
		if ((int) $u['buchungen'] === 0) {
			return;
		}
		$rw = RegelwerkLader::laden($sid);
		$einzel = new \KSV\KMM\Infrastructure\Repository\EinzelmeldungRepository();
		$schuetzen = new \KSV\KMM\Infrastructure\Repository\SchuetzeRepository();
		$vereine = [];
		foreach ((new \KSV\KMM\Infrastructure\Repository\VereinRepository())->all() as $v) {
			$vereine[ (int) $v['id'] ] = (string) $v['name'];
		}
		$einheiten = [];
		foreach ($u['einheiten'] as $e) {
			$einheiten[ (int) $e['id'] ] = $e;
		}
		$durchgaenge = [];
		foreach ($u['durchgaenge'] as $d) {
			$durchgaenge[ $d['id'] ] = $d;
		}
		$buchungen = (new \KSV\KMM\Infrastructure\Repository\BuchungRepository())->by_wettkampftag($tag_id);
		$belegt = [];
		foreach ($buchungen as $b) {
			$belegt[ (int) $b['durchgang_id'] . '-' . (int) $b['einheit_id'] . '-' . (int) $b['position'] ] = (int) $b['einzelmeldung_id'];
		}
		$namen = [];
		foreach ($buchungen as $b) {
			$em = $einzel->find((int) $b['einzelmeldung_id']);
			$sch = $em !== null && $em['schuetze_id'] !== null ? $schuetzen->find((int) $em['schuetze_id']) : null;
			$namen[ (int) $b['einzelmeldung_id'] ] = $sch !== null ? $sch['nachname'] . ', ' . $sch['vorname'] : '?';
		}
		echo '<details class="kmm-buchungsliste"><summary>' . esc_html(sprintf(__('Alle Buchungen als Liste (%d)', 'ksv-km-meldeportal'), (int) $u['buchungen'])) . '</summary>';
		if ($schreiben) {
			echo '<p class="description">' . esc_html__('Zum Verschieben ohne Maus: Zielplatz wählen und auf „Setzen“ klicken. Ist der Zielplatz belegt, tauschen beide Starter die Plätze.', 'ksv-km-meldeportal') . '</p>';
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Durchgang', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Einheit / Pos.', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Starter', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Disziplin', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Verein', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Gebucht', 'ksv-km-meldeportal') . '</th>' . ($schreiben ? '<th>' . esc_html__('Verschieben nach', 'ksv-km-meldeportal') . '</th>' : '') . '</tr></thead><tbody>';
		foreach ($buchungen as $b) {
			$em = $einzel->find((int) $b['einzelmeldung_id']);
			$d = $em !== null ? $rw->disziplin((int) $em['disziplin_id']) : null;
			$dg = $durchgaenge[ (int) $b['durchgang_id'] ] ?? null;
			$e = $einheiten[ (int) $b['einheit_id'] ] ?? null;
			echo '<tr><td>' . esc_html($dg !== null ? $dg['nummer'] . ' (' . $dg['zeit'] . ')' : '#' . (int) $b['durchgang_id']) . '</td><td>' . esc_html(($e !== null ? (string) $e['bezeichnung'] : '#' . (int) $b['einheit_id']) . ($e !== null && (int) $e['kapazitaet'] > 1 ? ' / ' . (int) $b['position'] : '')) . '</td><td>' . esc_html($namen[ (int) $b['einzelmeldung_id'] ] ?? '?') . '</td><td>' . esc_html($d !== null ? $d->kennzahl . ' ' . $d->bezeichnung : '') . '</td><td>' . esc_html($vereine[ (int) $b['verein_id'] ] ?? '') . '</td><td>' . esc_html(Clock::format_local($b['gebucht_am'], 'd.m. H:i') . ((string) $b['gebucht_von_typ'] === 'admin' ? ' (KSV)' : '')) . '</td>';
			if ($schreiben) {
				echo '<td class="kmm-nowrap">';
				self::form_open('verschieben', 'class="kmm-inline-form"');
				echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tag_id" value="' . $tag_id . '"><input type="hidden" name="buchung_id" value="' . (int) $b['id'] . '">';
				echo '<select name="ziel" class="kmm-ziel" required><option value="">' . esc_html__('– Platz wählen –', 'ksv-km-meldeportal') . '</option>';
				foreach ($u['durchgaenge'] as $zdg) {
					echo '<optgroup label="' . esc_attr(sprintf(__('Durchgang %1$d (%2$s)', 'ksv-km-meldeportal'), $zdg['nummer'], $zdg['zeit'])) . '">';
					foreach ($u['einheiten'] as $ze) {
						for ($p = 1; $p <= (int) $ze['kapazitaet']; $p++) {
							$key = (int) $zdg['id'] . '-' . (int) $ze['id'] . '-' . $p;
							if (isset($belegt[ $key ]) && $em !== null && $belegt[ $key ] === (int) $em['id']) {
								continue;
							}
							$label = (string) $ze['bezeichnung'] . ((int) $ze['kapazitaet'] > 1 ? ' / ' . $p : '');
							$label .= isset($belegt[ $key ]) ? ' – ' . ($namen[ $belegt[ $key ] ] ?? '?') : ' – ' . __('frei', 'ksv-km-meldeportal');
							echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
						}
					}
					echo '</optgroup>';
				}
				echo '</select> ';
				submit_button(__('Setzen', 'ksv-km-meldeportal'), 'secondary small', 'submit', false);
				echo '</form></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></details>';
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
