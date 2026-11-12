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
		// Eigene Variable: Im catch-Zweig soll die Aktion ohne Annahmen aus dem switch lesbar sein.
		$aktion = self::action();
		$args = array_filter(['sportjahr' => $sid, 'tag' => $tag_id, 'ansicht' => self::ANSICHT_NACH_AKTION[ $aktion ] ?? '']);
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
					// Ein neuer Tag geht mit dem nächsten Schritt weiter: den Ständen.
					self::redirect(self::post_int('id') === 0 ? __('Wettkampftag angelegt. Weiter mit den Ständen.', 'ksv-km-meldeportal') : __('Wettkampftag gespeichert.', 'ksv-km-meldeportal'), 'success', ['sportjahr' => $sid, 'tag' => $id, 'ansicht' => self::post_int('id') === 0 ? 'staende' : 'tag']);
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
					// Neu angelegt wird wahlweise gleich eine ganze Folge gleich langer Durchgänge.
					$anzahl = self::post_int('id') === 0 && isset($_POST['anzahl']) ? self::post_int('anzahl') : 1;
					if ($anzahl !== 1) {
						$neu = $service->durchgaenge_anlegen($tag_id, self::post_int('nummer'), self::post_str('bezeichnung', 100), Clock::local_to_utc(self::post_str('beginn')), Clock::local_to_utc(self::post_str('ende')), $anzahl, self::post_int('pause'));
						self::redirect(sprintf(__('%d Durchgänge angelegt. Über „+ Disziplin“ legen Sie fest, wer dort buchen darf.', 'ksv-km-meldeportal'), count($neu)), 'success', $args, 'kmm-dg-' . $neu[0]);
					}
					$neu_dg = self::post_int('id') === 0;
					$id = $service->durchgang_speichern(self::post_int('id'), $tag_id, self::post_int('nummer'), self::post_str('bezeichnung', 100), Clock::local_to_utc(self::post_str('beginn')), Clock::local_to_utc(self::post_str('ende')), self::post_int('sortierung'));
					// Ein neuer Durchgang braucht als Nächstes eine Zulassung: Die Auswahl steht gleich offen.
					self::redirect($neu_dg ? __('Durchgang angelegt. Jetzt festlegen, wer dort buchen darf.', 'ksv-km-meldeportal') : __('Durchgang gespeichert.', 'ksv-km-meldeportal'), 'success', $args + ($neu_dg ? ['durchgang' => $id, 'zul' => 0] : []), 'kmm-dg-' . $id);
				case 'durchgang_duplizieren':
					$neu = $service->durchgang_duplizieren(self::post_int('id'), self::post_int('anzahl') ?: 1, self::post_int('pause'));
					self::redirect(sprintf(_n('%d Durchgang kopiert.', '%d Durchgänge kopiert.', count($neu), 'ksv-km-meldeportal'), count($neu)), 'success', $args, 'kmm-dg-' . $neu[0]);
				case 'durchgang_loeschen':
					$service->durchgang_loeschen(self::post_int('id'));
					self::redirect(__('Durchgang gelöscht.', 'ksv-km-meldeportal'), 'success', $args);
				case 'zulassungen_setzen':
					$dg = self::post_int('durchgang_id');
					$klassen = isset($_POST['startklasse_ids']) && is_array($_POST['startklasse_ids']) ? array_map('intval', $_POST['startklasse_ids']) : [];
					$weitere = isset($_POST['weitere']) && is_array($_POST['weitere']) ? array_map('intval', $_POST['weitere']) : [];
					// Einzelne Klassen gehen vor; ohne Haken und ohne „alle“ wäre unklar, was gemeint ist.
					if ($klassen === [] && !self::post_bool('alle')) {
						throw new \InvalidArgumentException(__('Bitte mindestens eine Startklasse ankreuzen oder „alle Startklassen“ wählen. Zum Entfernen das ✕ an der Disziplin benutzen.', 'ksv-km-meldeportal'));
					}
					$r = $service->zulassungen_setzen($dg, self::post_int('disziplin_id'), $klassen, $weitere);
					$text = $r['neu'] + $r['entfernt'] === 0 ? __('Zulassung unverändert.', 'ksv-km-meldeportal') : ($r['durchgaenge'] > 1 ? sprintf(__('Zulassung für %d Durchgänge gespeichert.', 'ksv-km-meldeportal'), $r['durchgaenge']) : __('Zulassung gespeichert.', 'ksv-km-meldeportal'));
					self::redirect($text, 'success', $args, 'kmm-dg-' . $dg);
				case 'zulassungen_entfernen':
					$dg = self::post_int('durchgang_id');
					$service->zulassungen_entfernen($dg, self::post_int('disziplin_id'));
					self::redirect(__('Disziplin aus dem Durchgang genommen.', 'ksv-km-meldeportal'), 'success', $args, 'kmm-dg-' . $dg);
				case 'freigeben':
					$r = $service->freigeben(self::post_int('id'), self::post_bool('push'));
					$text = sprintf(__('Wettkampftag freigegeben. Mail an %1$d von %2$d Vereinen mit passenden Startern gesendet.', 'ksv-km-meldeportal'), $r['gesendet'], $r['vereine']);
					$text .= self::push_hinweis($r['push'], self::post_bool('push'));
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
					$r = $service->veroeffentlichen($tag_id, self::post_int('benachrichtigen') === 1, self::post_bool('push'));
					$text = __('Startplan veröffentlicht.', 'ksv-km-meldeportal');
					if ($r['vereine'] > 0) {
						$text .= ' ' . sprintf(__('Mail an %1$d von %2$d Vereinen.', 'ksv-km-meldeportal'), $r['gesendet'], $r['vereine']);
					}
					$text .= self::push_hinweis($r['push'], self::post_bool('push'));
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
			// Nach einem Fehler geht das Feld wieder auf, in dem er passiert ist.
			$dg = self::post_int('durchgang_id') ?: ($aktion === 'durchgang_speichern' ? self::post_int('id') : 0);
			if ($dg > 0) {
				$args += ['durchgang' => $dg] + ($aktion === 'zulassungen_setzen' ? ['zul' => self::post_int('disziplin_id')] : []);
			}
			self::redirect($e->getMessage(), 'error', $args, $dg > 0 ? 'kmm-dg-' . $dg : ($aktion === 'durchgang_speichern' ? 'kmm-dg-neu' : ''));
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
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Datum', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Bezeichnung / Ort', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Status', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Einheiten', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Durchgänge', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Plätze', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Bedarf', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Buchungsfrist', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Shortcode', 'ksv-km-meldeportal') . '</th><th></th></tr></thead><tbody>';
		foreach ($tage as $t) {
			$u = $service->uebersicht((int) $t['id']);
			$bedarf = array_sum(array_column($u['durchgaenge'], 'bedarf'));
			$datum = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $t['datum']);
			echo '<tr><td><strong>' . esc_html($datum !== false ? $datum->format('d.m.Y') : (string) $t['datum']) . '</strong></td>';
			echo '<td>' . esc_html((string) $t['bezeichnung']) . '<br><small>' . esc_html((string) $t['ort']) . '</small></td>';
			echo '<td class="kmm-nowrap">' . self::status_badge((string) $t['status']) . '</td>';
			echo '<td class="r">' . count($u['einheiten']) . '</td><td class="r">' . count($u['durchgaenge']) . '</td><td class="r">' . (int) $u['plaetze'] . '</td>';
			echo '<td class="r">' . ($bedarf > $u['plaetze'] ? '<span class="kmm-fail">' . $bedarf . '</span>' : $bedarf) . '</td>';
			echo '<td>' . esc_html($t['buchungsfrist'] !== null ? Clock::format_local($t['buchungsfrist']) : '–') . '</td>';
			echo '<td class="kmm-nowrap">' . self::shortcode_html((int) $t['id']) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td><a class="button button-small" href="' . esc_url(self::url(['sportjahr' => $sid, 'tag' => (int) $t['id']])) . '">' . esc_html($schreiben ? __('Bearbeiten', 'ksv-km-meldeportal') : __('Ansehen', 'ksv-km-meldeportal')) . '</a></td></tr>';
		}
		if ($tage === []) {
			echo '<tr><td colspan="10">' . esc_html__('Noch kein Wettkampftag.', 'ksv-km-meldeportal') . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__('Bedarf = buchbare Meldungen (eingereicht, Startrecht, nicht abgemeldet, nicht „nicht startberechtigt“), die zu einer Zulassung des Tages passen; rot, wenn mehr Bedarf als Plätze.', 'ksv-km-meldeportal') . ' ' . esc_html__('Der Shortcode zeigt den Startplan des Tages in einem Beitrag, einer Seite oder einem Kalendertermin – Inhalt erscheint erst nach der Veröffentlichung.', 'ksv-km-meldeportal') . '</p>';
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
		// Kalendertermin, Seite oder Beitrag mit dem Shortcode: auswählen statt eine ID abzutippen.
		$beitrag = isset($t['beitrag_id']) && (int) $t['beitrag_id'] > 0 ? (int) $t['beitrag_id'] : null;
		echo '<tr><th><label for="kmm-beitrag">' . esc_html__('Kalendertermin oder Beitrag', 'ksv-km-meldeportal') . '</label></th><td><select name="beitrag_id" id="kmm-beitrag" class="kmm-beitrag"><option value="">' . esc_html__('– keiner –', 'ksv-km-meldeportal') . '</option>';
		foreach (BeitragAuswahl::gruppen(isset($t['datum']) ? (string) $t['datum'] : null, $beitrag) as $gruppe => $liste) {
			echo '<optgroup label="' . esc_attr($gruppe) . '">';
			foreach ($liste as $id => $text) {
				echo '<option value="' . (int) $id . '"' . selected($beitrag, (int) $id, false) . '>' . esc_html($text) . '</option>';
			}
			echo '</optgroup>';
		}
		echo '</select>';
		if ($beitrag !== null && get_post($beitrag) instanceof \WP_Post) {
			$bearbeiten = get_edit_post_link($beitrag, 'raw');
			$ansehen = get_permalink($beitrag);
			if (is_string($bearbeiten) && $bearbeiten !== '') {
				echo ' <a class="button button-small" href="' . esc_url($bearbeiten) . '" target="_blank" rel="noopener">' . esc_html__('Bearbeiten', 'ksv-km-meldeportal') . '</a>';
			}
			if (is_string($ansehen) && $ansehen !== '') {
				echo ' <a class="button button-small" href="' . esc_url($ansehen) . '" target="_blank" rel="noopener">' . esc_html__('Ansehen', 'ksv-km-meldeportal') . '</a>';
			}
		}
		echo '<p class="description">' . esc_html__('Optional: der Termin, die Seite oder der Beitrag, in dem der Shortcode des Startplans steht. Die KSV-App verlinkt ihn nach der Veröffentlichung.', 'ksv-km-meldeportal') . ($t !== null ? ' ' . esc_html__('Shortcode dieses Tages:', 'ksv-km-meldeportal') . ' ' . self::shortcode_html((int) $t['id']) : '') . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th>' . esc_html__('Hinweis', 'ksv-km-meldeportal') . '</th><td><textarea name="hinweis" rows="2" class="large-text">' . esc_textarea((string) ($t['hinweis'] ?? '')) . '</textarea><p class="description">' . esc_html__('Erscheint im Buchungsraster der Vereine und im veröffentlichten Startplan.', 'ksv-km-meldeportal') . '</p></td></tr>';
		echo '</table>';
		submit_button($t !== null ? __('Wettkampftag speichern', 'ksv-km-meldeportal') : __('Wettkampftag anlegen', 'ksv-km-meldeportal'), 'primary', 'submit', false);
		echo '</form>';
	}

	/** Reiter der Tagesseite in der Reihenfolge, in der ein Wettkampftag entsteht. */
	private const ANSICHTEN = ['tag', 'staende', 'durchgaenge', 'freigabe', 'startplan'];

	/** Nach welcher Aktion welcher Reiter wieder aufgeht. */
	private const ANSICHT_NACH_AKTION = [
		'tag_speichern'             => 'tag',
		'einheit_speichern'         => 'staende',
		'einheit_loeschen'          => 'staende',
		'staende_freigeben'         => 'staende',
		'durchgang_speichern'       => 'durchgaenge',
		'durchgang_duplizieren'     => 'durchgaenge',
		'durchgang_loeschen'        => 'durchgaenge',
		'zulassungen_setzen'        => 'durchgaenge',
		'zulassungen_entfernen'     => 'durchgaenge',
		'freigeben'                 => 'freigabe',
		'freigabe_zurueck'          => 'freigabe',
		'restverteilung'            => 'startplan',
		'verschieben'               => 'startplan',
		'veroeffentlichen'          => 'startplan',
		'veroeffentlichung_zurueck' => 'startplan',
	];

	/**
	 * Welcher Reiter gezeigt wird: der gewünschte, sonst im Entwurf der erste Schritt, in dem
	 * noch etwas fehlt, und nach der Freigabe der Startplan.
	 *
	 * @param array<string, mixed> $u
	 */
	private static function ansicht(array $u): string {
		$wunsch = isset($_GET['ansicht']) ? sanitize_key((string) wp_unslash($_GET['ansicht'])) : '';
		if (in_array($wunsch, self::ANSICHTEN, true)) {
			return $wunsch;
		}
		if ((string) $u['tag']['status'] !== WettkampftagStatus::ENTWURF) {
			return 'startplan';
		}
		if ($u['einheiten'] === []) {
			return 'staende';
		}
		if ($u['durchgaenge'] === [] || self::ohne_zulassung($u) > 0) {
			return 'durchgaenge';
		}
		return 'freigabe';
	}

	/**
	 * @param array<string, mixed> $u
	 */
	private static function ohne_zulassung(array $u): int {
		return count(array_filter($u['durchgaenge'], static fn(array $d): bool => $d['zulassungen'] === []));
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
		$ansicht = self::ansicht($u);
		$datum = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $t['datum']);
		echo '<p><a href="' . esc_url(self::url(['sportjahr' => $sid])) . '">&larr; ' . esc_html__('Alle Wettkampftage', 'ksv-km-meldeportal') . '</a></p>';
		echo '<h2>' . esc_html(($datum !== false ? $datum->format('d.m.Y') : (string) $t['datum']) . ' ' . (string) $t['bezeichnung']) . ' ' . self::status_badge((string) $t['status']) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::render_schritte($sid, $tag_id, $u, $ansicht);

		if ($schreiben && (string) $t['status'] !== WettkampftagStatus::ENTWURF && in_array($ansicht, ['staende', 'durchgaenge'], true)) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__('Der Wettkampftag ist freigegeben bzw. veröffentlicht. Änderungen an Einheiten und Durchgängen wirken sofort auf die Buchung; Einheiten und Durchgänge mit Buchungen können nicht gelöscht werden.', 'ksv-km-meldeportal') . '</p></div>';
		}
		switch ($ansicht) {
			case 'tag':
				self::ansicht_tag($sid, $tag_id, $t, $schreiben);
				break;
			case 'staende':
				self::ansicht_staende($sid, $tag_id, $u, $schreiben);
				break;
			case 'durchgaenge':
				self::ansicht_durchgaenge($sid, $tag_id, $service, $u, $schreiben);
				break;
			case 'freigabe':
				self::ansicht_freigabe($sid, $tag_id, $u, $schreiben);
				break;
			default:
				self::ansicht_startplan($sid, $tag_id, $u, $schreiben);
		}
	}

	/**
	 * Die Reiter. Jeder sagt in einer Zeile, wie es um den Schritt steht – so sieht man ohne
	 * Blättern, wo noch etwas fehlt.
	 *
	 * @param array<string, mixed> $u
	 */
	private static function render_schritte(int $sid, int $tag_id, array $u, string $aktiv): void {
		$t = $u['tag'];
		$ohne = self::ohne_zulassung($u);
		$einheiten = count($u['einheiten']);
		$durchgaenge = count($u['durchgaenge']);
		$schritte = [
			'tag'         => [__('Tag', 'ksv-km-meldeportal'), $t['buchungsfrist'] !== null ? sprintf(__('Frist %s', 'ksv-km-meldeportal'), Clock::format_local($t['buchungsfrist'], 'd.m. H:i')) : __('ohne Buchungsfrist', 'ksv-km-meldeportal'), 'ok'],
			'staende'     => [__('Stände', 'ksv-km-meldeportal'), $einheiten > 0 ? sprintf(_n('%d Einheit', '%d Einheiten', $einheiten, 'ksv-km-meldeportal'), $einheiten) : __('noch keine', 'ksv-km-meldeportal'), $einheiten > 0 ? 'ok' : 'offen'],
			'durchgaenge' => [__('Durchgänge', 'ksv-km-meldeportal'), $durchgaenge === 0 ? __('noch keine', 'ksv-km-meldeportal') : ($ohne > 0 ? sprintf(__('%1$d, davon %2$d ohne Zulassung', 'ksv-km-meldeportal'), $durchgaenge, $ohne) : sprintf(_n('%d Durchgang', '%d Durchgänge', $durchgaenge, 'ksv-km-meldeportal'), $durchgaenge)), $durchgaenge > 0 && $ohne === 0 ? 'ok' : 'offen'],
			'freigabe'    => [__('Freigabe', 'ksv-km-meldeportal'), WettkampftagStatus::label((string) $t['status']), (string) $t['status'] === WettkampftagStatus::ENTWURF ? 'offen' : 'ok'],
			'startplan'   => [__('Startplan', 'ksv-km-meldeportal'), sprintf(__('%1$d von %2$d Plätzen belegt', 'ksv-km-meldeportal'), (int) $u['buchungen'], (int) $u['plaetze']), 'neutral'],
		];
		echo '<nav class="kmm-schritte" aria-label="' . esc_attr__('Schritte des Wettkampftags', 'ksv-km-meldeportal') . '">';
		$nr = 0;
		foreach ($schritte as $key => [$titel, $stand, $zustand]) {
			$nr++;
			echo '<a class="kmm-schritt is-' . esc_attr($zustand) . ($key === $aktiv ? ' is-aktiv' : '') . '" href="' . esc_url(self::url(['sportjahr' => $sid, 'tag' => $tag_id, 'ansicht' => $key])) . '"' . ($key === $aktiv ? ' aria-current="page"' : '') . '>';
			echo '<span class="kmm-schritt-nr" aria-hidden="true">' . ($zustand === 'ok' ? '✓' : $nr) . '</span>';
			echo '<span class="kmm-schritt-text"><strong>' . esc_html($titel) . '</strong><span>' . esc_html($stand) . '</span></span></a>';
		}
		echo '</nav>';
	}

	/** Knopf zum nächsten Schritt am Ende eines Reiters. */
	private static function weiter(int $sid, int $tag_id, string $ansicht, string $text): void {
		echo '<p class="kmm-weiter"><a class="button button-primary" href="' . esc_url(self::url(['sportjahr' => $sid, 'tag' => $tag_id, 'ansicht' => $ansicht])) . '">' . esc_html($text) . ' &rarr;</a></p>';
	}

	/**
	 * @param array<string, mixed> $t
	 */
	private static function ansicht_tag(int $sid, int $tag_id, array $t, bool $schreiben): void {
		if (!$schreiben) {
			echo '<p>' . esc_html(trim((string) $t['ort'] . ' · ' . ($t['buchungsfrist'] !== null ? __('Buchungsfrist', 'ksv-km-meldeportal') . ' ' . Clock::format_local($t['buchungsfrist']) : ''), ' ·')) . '</p>';
			return;
		}
		self::tag_form($sid, $t);
		if (Rechte::ist_admin()) {
			echo ' ';
			self::form_open('tag_loeschen', 'class="kmm-inline-form" onsubmit="return confirm(\'' . esc_js(__('Wettkampftag mit allen Einheiten und Durchgängen löschen?', 'ksv-km-meldeportal')) . '\')"');
			echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="id" value="' . $tag_id . '">';
			submit_button(__('Wettkampftag löschen', 'ksv-km-meldeportal'), 'delete small', 'submit', false);
			echo '</form>';
		}
	}

	/**
	 * Stände: Ankreuzen aus dem Schießstand, darunter die Einheiten im Einzelnen. Kommt alles
	 * aus dem Schießstand, ist die Einzelliste nur eine Wiederholung und bleibt zugeklappt.
	 *
	 * @param array<string, mixed> $u
	 */
	private static function ansicht_staende(int $sid, int $tag_id, array $u, bool $schreiben): void {
		$t = $u['tag'];
		$rw = RegelwerkLader::laden($sid);
		if ($t['schiessstand_id'] !== null) {
			self::render_staende($sid, $tag_id, (int) $t['schiessstand_id'], $schreiben);
		} elseif ($schreiben) {
			echo '<p class="description">' . esc_html__('Tipp: Ist im Reiter „Tag“ ein Schießstand aus den Stammdaten gewählt, lassen sich dessen Stände hier einfach ankreuzen.', 'ksv-km-meldeportal') . '</p>';
		}

		$edit_e = $schreiben && isset($_GET['einheit']) ? (new EinheitRepository())->find((int) $_GET['einheit']) : null;
		if ($edit_e !== null && (int) $edit_e['wettkampftag_id'] !== $tag_id) {
			$edit_e = null;
		}
		$von_hand = count(array_filter($u['einheiten'], static fn(array $e): bool => $e['standgruppe_id'] === null));
		$zuklappen = $t['schiessstand_id'] !== null && $edit_e === null && $von_hand === 0;
		if ($zuklappen) {
			echo '<details class="kmm-einzeln"><summary>' . esc_html(sprintf(__('Einheiten einzeln ansehen (%d) oder eine Sondereinheit ergänzen', 'ksv-km-meldeportal'), count($u['einheiten']))) . '</summary>';
		} else {
			echo '<h3>' . esc_html__('Einheiten (Stände, Scheiben, Rotten)', 'ksv-km-meldeportal') . '</h3>';
		}
		$disziplinen = [];
		foreach ($rw->disziplinen() as $d) {
			$disziplinen[ $d->id ] = $d->kennzahl . ' ' . $d->bezeichnung;
		}
		echo '<table class="widefat striped kmm-kompakt"><thead><tr><th>' . esc_html__('Bezeichnung', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Kapazität', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Beschränkt auf Disziplinen', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Reihenfolge', 'ksv-km-meldeportal') . '</th><th></th></tr></thead><tbody>';
		foreach ($u['einheiten'] as $e) {
			$ids = EinheitRepository::disziplin_ids($e);
			echo '<tr><td>' . esc_html((string) $e['bezeichnung']) . ($e['standgruppe_id'] !== null ? ' <small class="kmm-muted">' . esc_html__('(Schießstand)', 'ksv-km-meldeportal') . '</small>' : '') . '</td><td class="r">' . (int) $e['kapazitaet'] . '</td><td>' . esc_html($ids === [] ? __('alle', 'ksv-km-meldeportal') : implode(', ', array_map(static fn(int $i): string => $disziplinen[ $i ] ?? '#' . $i, $ids))) . '</td><td class="r">' . (int) $e['sortierung'] . '</td><td class="kmm-nowrap">';
			if ($schreiben) {
				echo '<a class="button button-small" href="' . esc_url(self::url(['sportjahr' => $sid, 'tag' => $tag_id, 'ansicht' => 'staende', 'einheit' => (int) $e['id']]) . '#kmm-einheit-form') . '">' . esc_html__('Bearbeiten', 'ksv-km-meldeportal') . '</a> ';
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
			$ids = $edit_e !== null ? EinheitRepository::disziplin_ids($edit_e) : [];
			self::form_open('einheit_speichern', 'class="kmm-block-form" id="kmm-einheit-form"');
			echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tag_id" value="' . $tag_id . '"><input type="hidden" name="id" value="' . ($edit_e !== null ? (int) $edit_e['id'] : 0) . '">';
			echo '<p><strong>' . esc_html($edit_e !== null ? __('Einheit bearbeiten', 'ksv-km-meldeportal') : __('Einheit hinzufügen', 'ksv-km-meldeportal')) . '</strong></p>';
			echo '<p>' . esc_html__('Bezeichnung', 'ksv-km-meldeportal') . ' ' . self::input('bezeichnung', $edit_e['bezeichnung'] ?? '', 'text', 'class="kmm-short" required placeholder="Stand 1"') . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo esc_html__('Kapazität', 'ksv-km-meldeportal') . ' ' . self::input('kapazitaet', $edit_e['kapazitaet'] ?? 1, 'number', 'class="kmm-num" min="1" max="200" required') . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo esc_html__('Reihenfolge', 'ksv-km-meldeportal') . ' ' . self::input('sortierung', $edit_e['sortierung'] ?? count($u['einheiten']) + 1, 'number', 'class="kmm-num" min="0"') . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$optionen = [];
			foreach ($rw->disziplinen() as $d) {
				$optionen[] = ['wert' => (string) $d->id, 'kennzahl' => $d->kennzahl, 'bezeichnung' => $d->bezeichnung];
			}
			self::disziplin_auswahl('disziplin_ids', $optionen, array_map('strval', $ids));
			submit_button($edit_e !== null ? __('Einheit speichern', 'ksv-km-meldeportal') : __('Einheit hinzufügen', 'ksv-km-meldeportal'), 'secondary', 'submit', false);
			if ($edit_e !== null) {
				echo ' <a class="button" href="' . esc_url(self::url(['sportjahr' => $sid, 'tag' => $tag_id, 'ansicht' => 'staende'])) . '">' . esc_html__('Abbrechen', 'ksv-km-meldeportal') . '</a>';
			}
			echo '</form>';
			self::disziplin_auswahl_script();
		}
		if ($zuklappen) {
			echo '</details>';
		}
		if ($schreiben && $u['einheiten'] !== []) {
			self::weiter($sid, $tag_id, 'durchgaenge', __('Weiter zu den Durchgängen', 'ksv-km-meldeportal'));
		}
	}

	/**
	 * Durchgänge: je Durchgang eine Karte mit Zeit, Zahlen und den zugelassenen Disziplinen.
	 * Bearbeitet wird direkt in der Karte – nach dem Speichern steht die Seite wieder dort.
	 *
	 * @param array<string, mixed> $u
	 */
	private static function ansicht_durchgaenge(int $sid, int $tag_id, StartplanService $service, array $u, bool $schreiben): void {
		$t = $u['tag'];
		$offen_id = $schreiben && isset($_GET['durchgang']) ? (int) $_GET['durchgang'] : 0;
		echo '<p class="description">' . esc_html__('Je Durchgang legen Sie fest, welche Disziplinen und Startklassen dort buchen dürfen. Ein Durchgang ohne Zulassung ist wirkungslos.', 'ksv-km-meldeportal') . '</p>';
		echo '<div class="kmm-dg-liste">';
		foreach ($u['durchgaenge'] as $dg) {
			$bearbeitbar = $schreiben && $dg['zustaendig'];
			$offen = $bearbeitbar && $dg['id'] === $offen_id;
			$link = ['sportjahr' => $sid, 'tag' => $tag_id, 'ansicht' => 'durchgaenge', 'durchgang' => (int) $dg['id']];
			$anker = '#kmm-dg-' . (int) $dg['id'];
			echo '<div class="kmm-dg' . ($dg['zulassungen'] === [] ? ' is-warn' : '') . ($offen ? ' is-offen' : '') . '" id="kmm-dg-' . (int) $dg['id'] . '">';
			echo '<div class="kmm-dg-kopf"><span class="kmm-dg-nr" title="' . esc_attr(sprintf(__('Durchgang %d', 'ksv-km-meldeportal'), (int) $dg['nummer'])) . '">' . (int) $dg['nummer'] . '</span>';
			echo '<strong class="kmm-dg-zeit">' . esc_html((string) $dg['zeit']) . '</strong>';
			if ((string) $dg['bezeichnung'] !== '') {
				echo '<span class="kmm-dg-name">' . esc_html((string) $dg['bezeichnung']) . '</span>';
			}
			echo '<span class="kmm-dg-zahlen">' . esc_html(sprintf(_n('%d Platz', '%d Plätze', (int) $dg['plaetze'], 'ksv-km-meldeportal'), (int) $dg['plaetze'])) . ' · ';
			echo $dg['bedarf'] > $dg['plaetze'] ? '<span class="kmm-fail">' . esc_html(sprintf(__('Bedarf %d', 'ksv-km-meldeportal'), (int) $dg['bedarf'])) . '</span>' : esc_html(sprintf(__('Bedarf %d', 'ksv-km-meldeportal'), (int) $dg['bedarf']));
			echo ' · ' . esc_html(sprintf(__('%d gebucht', 'ksv-km-meldeportal'), (int) $dg['gebucht'])) . '</span>';
			if ($bearbeitbar) {
				echo '<span class="kmm-dg-aktionen">';
				echo '<a class="button button-small" data-kmm-panel href="' . esc_url(self::url($link + ['auf' => 'zeit']) . $anker) . '">' . esc_html__('Zeit ändern', 'ksv-km-meldeportal') . '</a> ';
				echo '<details class="kmm-dg-dup"><summary class="button button-small">' . esc_html__('Duplizieren', 'ksv-km-meldeportal') . '</summary>';
				self::form_open('durchgang_duplizieren', 'class="kmm-dg-dup-form"');
				echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tag_id" value="' . $tag_id . '"><input type="hidden" name="id" value="' . (int) $dg['id'] . '">';
				echo '<label>' . esc_html__('Kopien', 'ksv-km-meldeportal') . ' <input type="number" name="anzahl" value="1" min="1" max="50" class="kmm-num"></label> ';
				echo '<label>' . esc_html__('Pause', 'ksv-km-meldeportal') . ' <input type="number" name="pause" value="0" min="0" max="600" step="5" class="kmm-num"> ' . esc_html__('Min.', 'ksv-km-meldeportal') . '</label> ';
				submit_button(__('Hinten anhängen', 'ksv-km-meldeportal'), 'secondary small', 'submit', false);
				echo '<p class="description">' . esc_html__('Gleiche Dauer, Bezeichnung und Zulassungen.', 'ksv-km-meldeportal') . '</p></form></details> ';
				self::form_open('durchgang_loeschen', 'class="kmm-inline-form" onsubmit="return confirm(\'' . esc_js(__('Durchgang mit Zulassungen löschen?', 'ksv-km-meldeportal')) . '\')"');
				echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tag_id" value="' . $tag_id . '"><input type="hidden" name="id" value="' . (int) $dg['id'] . '">';
				echo '<button type="submit" class="button button-small" title="' . esc_attr__('Durchgang löschen', 'ksv-km-meldeportal') . '" aria-label="' . esc_attr(sprintf(__('Durchgang %d löschen', 'ksv-km-meldeportal'), (int) $dg['nummer'])) . '">✕</button>';
				echo '</form></span>';
			}
			echo '</div>';

			// Zugelassene Disziplinen als Marken: Anklicken ändert die Startklassen.
			$je_disziplin = [];
			foreach ($dg['zulassungen'] as $z) {
				$je_disziplin[ $z['disziplin_id'] ][] = $z;
			}
			echo '<div class="kmm-dg-zul">';
			if ($je_disziplin === []) {
				echo '<span class="kmm-warn">' . esc_html__('Noch keine Zulassung – niemand kann buchen.', 'ksv-km-meldeportal') . '</span> ';
			}
			foreach ($je_disziplin as $did => $liste) {
				$klassen = $liste[0]['startklasse_id'] === null ? __('alle Startklassen', 'ksv-km-meldeportal') : implode(', ', array_map(static fn(array $z): string => (string) $z['startklasse'], $liste));
				echo '<span class="kmm-zul-marke">';
				if ($bearbeitbar && $liste[0]['zustaendig']) {
					echo '<a data-kmm-panel href="' . esc_url(self::url($link + ['zul' => (int) $did]) . $anker) . '" title="' . esc_attr__('Startklassen ändern', 'ksv-km-meldeportal') . '"><strong>' . esc_html((string) $liste[0]['disziplin']) . '</strong> <span>' . esc_html($klassen) . '</span></a>';
					self::form_open('zulassungen_entfernen', 'class="kmm-inline-form"');
					echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tag_id" value="' . $tag_id . '"><input type="hidden" name="durchgang_id" value="' . (int) $dg['id'] . '"><input type="hidden" name="disziplin_id" value="' . (int) $did . '">';
					echo '<button type="submit" class="button-link kmm-zul-weg" title="' . esc_attr__('Disziplin aus dem Durchgang nehmen', 'ksv-km-meldeportal') . '" aria-label="' . esc_attr(sprintf(__('%s aus dem Durchgang nehmen', 'ksv-km-meldeportal'), (string) $liste[0]['disziplin'])) . '">✕</button></form>';
				} else {
					echo '<strong>' . esc_html((string) $liste[0]['disziplin']) . '</strong> <span>' . esc_html($klassen) . '</span>';
				}
				echo '</span> ';
			}
			if ($bearbeitbar) {
				echo '<a class="button button-small kmm-zul-plus" data-kmm-panel href="' . esc_url(self::url($link + ['zul' => 0]) . $anker) . '">+ ' . esc_html__('Disziplin', 'ksv-km-meldeportal') . '</a>';
			}
			echo '</div>';

			echo '<div class="kmm-dg-panel">';
			if ($offen) {
				self::render_panel($sid, $tag_id, $service, $u, $dg, isset($_GET['zul']) ? (int) $_GET['zul'] : null);
			}
			echo '</div></div>';
		}
		echo '</div>';
		if ($u['durchgaenge'] === []) {
			echo '<p>' . esc_html__('Noch kein Durchgang.', 'ksv-km-meldeportal') . '</p>';
		}
		if (!$schreiben) {
			return;
		}

		// Neue Durchgänge: schließen an den letzten an und übernehmen dessen Dauer.
		$beginn = (string) $t['datum'] . 'T09:00';
		$ende = (string) $t['datum'] . 'T10:30';
		$letzter = null;
		foreach ($u['durchgaenge'] as $dg) {
			if ($letzter === null || $dg['ende'] > $letzter['ende']) {
				$letzter = $dg;
			}
		}
		$pause = 0;
		if ($letzter !== null) {
			$letztes_ende = (int) strtotime($letzter['ende'] . ' UTC');
			$dauer = max(60, $letztes_ende - (int) strtotime($letzter['beginn'] . ' UTC'));
			// Die Pause vor dem letzten Durchgang gilt als die übliche des Tages.
			$davor = 0;
			foreach ($u['durchgaenge'] as $dg) {
				if ($dg['ende'] <= $letzter['beginn']) {
					$davor = max($davor, (int) strtotime($dg['ende'] . ' UTC'));
				}
			}
			if ($davor > 0) {
				$pause = min(600, intdiv((int) strtotime($letzter['beginn'] . ' UTC') - $davor, 60));
			}
			$start = gmdate(Clock::DB_FORMAT, $letztes_ende + $pause * 60);
			if (Clock::format_local($start, 'Y-m-d') === (string) $t['datum']) {
				$beginn = Clock::utc_to_local_input($start);
				$ende = Clock::utc_to_local_input(gmdate(Clock::DB_FORMAT, $letztes_ende + $pause * 60 + $dauer));
			} else {
				$pause = 0;
			}
		}
		self::form_open('durchgang_speichern', 'class="kmm-block-form kmm-dg-neu" id="kmm-dg-neu"');
		echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tag_id" value="' . $tag_id . '"><input type="hidden" name="id" value="0">';
		echo '<p><strong>' . esc_html__('Durchgänge hinzufügen', 'ksv-km-meldeportal') . '</strong></p>';
		echo '<p><label>' . esc_html__('Nr.', 'ksv-km-meldeportal') . ' ' . self::input('nummer', count($u['durchgaenge']) + 1, 'number', 'class="kmm-num" min="0"') . '</label> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<label>' . esc_html__('Beginn', 'ksv-km-meldeportal') . ' ' . self::input('beginn', $beginn, 'datetime-local', 'required') . '</label> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<label>' . esc_html__('Ende', 'ksv-km-meldeportal') . ' ' . self::input('ende', $ende, 'datetime-local', 'required') . '</label> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<label>' . esc_html__('Bezeichnung', 'ksv-km-meldeportal') . ' ' . self::input('bezeichnung', '', 'text', 'class="kmm-short" placeholder="optional"') . '</label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		// Mehrere auf einmal: gleiche Dauer, jeweils im Anschluss, dazwischen die Pause.
		echo '<p class="kmm-dg-serie"><label>' . esc_html__('Anzahl', 'ksv-km-meldeportal') . ' ' . self::input('anzahl', 1, 'number', 'class="kmm-num" min="1" max="50" required') . '</label> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<label>' . esc_html__('Pause dazwischen', 'ksv-km-meldeportal') . ' ' . self::input('pause', $pause, 'number', 'class="kmm-num" min="0" max="600" step="5"') . ' ' . esc_html__('Minuten', 'ksv-km-meldeportal') . '</label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p class="description kmm-dg-vorschau" aria-live="polite">' . esc_html__('Bei mehr als einem Durchgang gelten Beginn und Ende für den ersten; die weiteren folgen mit gleicher Dauer und der Pause dazwischen.', 'ksv-km-meldeportal') . '</p>';
		submit_button(__('Durchgang hinzufügen', 'ksv-km-meldeportal'), 'secondary', 'submit', false);
		echo '</form>';
		self::durchgang_serie_script();
		if ($u['durchgaenge'] !== [] && self::ohne_zulassung($u) === 0 && (string) $t['status'] === WettkampftagStatus::ENTWURF) {
			self::weiter($sid, $tag_id, 'freigabe', __('Weiter zur Freigabe', 'ksv-km-meldeportal'));
		}
	}

	/**
	 * Bearbeitungsfeld in der Karte eines Durchgangs: Zeit ändern ($zul null) oder die
	 * Startklassen einer Disziplin festlegen ($zul 0 = erst die Disziplin wählen). Dieselbe
	 * Ausgabe kommt beim Seitenaufbau und beim Nachladen ohne Seitenwechsel.
	 *
	 * @param array<string, mixed> $u
	 * @param array<string, mixed> $dg
	 */
	private static function render_panel(int $sid, int $tag_id, StartplanService $service, array $u, array $dg, ?int $zul): void {
		$dg_id = (int) $dg['id'];
		$zurueck = self::url(['sportjahr' => $sid, 'tag' => $tag_id, 'ansicht' => 'durchgaenge']) . '#kmm-dg-' . $dg_id;
		$abbrechen = ' <a class="button" data-kmm-panel-zu href="' . esc_url($zurueck) . '">' . esc_html__('Abbrechen', 'ksv-km-meldeportal') . '</a>';
		$versteckt = '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tag_id" value="' . $tag_id . '">';

		if ($zul === null) {
			self::form_open('durchgang_speichern', 'class="kmm-dg-zeitform"');
			echo $versteckt . '<input type="hidden" name="id" value="' . $dg_id . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<p><label>' . esc_html__('Nr.', 'ksv-km-meldeportal') . ' ' . self::input('nummer', $dg['nummer'], 'number', 'class="kmm-num" min="0"') . '</label> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<label>' . esc_html__('Beginn', 'ksv-km-meldeportal') . ' ' . self::input('beginn', Clock::utc_to_local_input($dg['beginn']), 'datetime-local', 'required') . '</label> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<label>' . esc_html__('Ende', 'ksv-km-meldeportal') . ' ' . self::input('ende', Clock::utc_to_local_input($dg['ende']), 'datetime-local', 'required') . '</label> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<label>' . esc_html__('Bezeichnung', 'ksv-km-meldeportal') . ' ' . self::input('bezeichnung', $dg['bezeichnung'], 'text', 'class="kmm-short" placeholder="optional"') . '</label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			submit_button(__('Speichern', 'ksv-km-meldeportal'), 'primary', 'submit', false);
			echo $abbrechen . '</form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		$rw = RegelwerkLader::laden($sid);
		$je_disziplin = [];
		foreach ($dg['zulassungen'] as $z) {
			$je_disziplin[ $z['disziplin_id'] ][] = $z;
		}
		$gewaehlt = null;
		$auswahl = ['' => __('– Disziplin wählen –', 'ksv-km-meldeportal')];
		foreach (Rechte::zustaendige($rw->disziplinen()) as $d) {
			if ($d->id === $zul) {
				$gewaehlt = $d;
			}
			if (!isset($je_disziplin[ $d->id ]) || $d->id === $zul) {
				$auswahl[ $d->id ] = $d->kennzahl . ' ' . $d->bezeichnung;
			}
		}
		$neu = $gewaehlt === null || !isset($je_disziplin[ $gewaehlt->id ]);

		// Eine neue Disziplin wird erst gewählt; die Startklassen erscheinen darunter.
		if ($neu) {
			echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '#kmm-dg-' . $dg_id . '" class="kmm-zul-wahl">';
			echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '"><input type="hidden" name="sportjahr" value="' . $sid . '"><input type="hidden" name="tag" value="' . $tag_id . '"><input type="hidden" name="ansicht" value="durchgaenge"><input type="hidden" name="durchgang" value="' . $dg_id . '">';
			echo '<label><strong>' . esc_html__('Disziplin zulassen', 'ksv-km-meldeportal') . '</strong> ';
			echo self::select('zul', $auswahl, $gewaehlt !== null ? $gewaehlt->id : '', 'data-kmm-zul-wahl'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</label> <noscript><button type="submit" class="button">' . esc_html__('Startklassen anzeigen', 'ksv-km-meldeportal') . '</button></noscript>';
			if ($gewaehlt === null) {
				echo $abbrechen; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</form>';
			if ($gewaehlt === null) {
				return;
			}
		}

		// Startklassen der Disziplin. Angehakt ist, was schon zugelassen ist; neu heißt „alle“.
		$aktiv = [];
		$alle = $neu;
		foreach ($je_disziplin[ $gewaehlt->id ] ?? [] as $z) {
			if ($z['startklasse_id'] === null) {
				$alle = true;
			} else {
				$aktiv[] = (int) $z['startklasse_id'];
			}
		}
		// Startklassen aus mehreren Wettbewerbsgruppen (etwa eine Para-Klasse) stehen je Gruppe in einer eigenen Zeile.
		$je_gruppe = [];
		foreach ($service->startklassen($gewaehlt) as $k) {
			$je_gruppe[ $k->gruppe ][] = $k;
		}
		$gruppen_namen = [];
		foreach ((new \KSV\KMM\Infrastructure\Repository\GruppeRepository())->by_sportjahr($sid) as $g) {
			$gruppen_namen[ (string) $g['code'] ] = (string) $g['bezeichnung'];
		}
		self::form_open('zulassungen_setzen', 'class="kmm-zul-form"');
		echo $versteckt . '<input type="hidden" name="durchgang_id" value="' . $dg_id . '"><input type="hidden" name="disziplin_id" value="' . (int) $gewaehlt->id . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if (!$neu) {
			echo '<p><strong>' . esc_html(sprintf(__('Startklassen für %s', 'ksv-km-meldeportal'), $gewaehlt->kennzahl . ' ' . $gewaehlt->bezeichnung)) . '</strong></p>';
		}
		echo '<div class="kmm-staende"><label class="kmm-stand kmm-zul-alle ' . ($alle ? 'is-aktiv' : '') . '"><input type="checkbox" name="alle" value="1" ' . checked($alle, true, false) . '> <strong>' . esc_html__('alle Startklassen', 'ksv-km-meldeportal') . '</strong></label>';
		echo '<span class="description">' . esc_html__('oder einzelne ankreuzen – männlich und weiblich sind getrennte Startklassen:', 'ksv-km-meldeportal') . '</span></div>';
		foreach ($je_gruppe as $gruppe => $liste) {
			echo '<div class="kmm-staende">';
			if (count($je_gruppe) > 1) {
				echo '<span class="kmm-zul-gruppe">' . esc_html($gruppen_namen[ $gruppe ] ?? (string) $gruppe) . '</span>';
			}
			foreach ($liste as $k) {
				$an = in_array($k->id, $aktiv, true);
				echo '<label class="kmm-stand ' . ($an ? 'is-aktiv' : '') . '"><input type="checkbox" name="startklasse_ids[]" value="' . (int) $k->id . '" ' . checked($an, true, false) . '> ' . esc_html($k->bezeichnung) . '</label>';
			}
			echo '</div>';
		}
		// Dieselbe Auswahl gleich für weitere Durchgänge des Tages.
		$andere = array_filter($u['durchgaenge'], static fn(array $x): bool => $x['id'] !== $dg_id && $x['zustaendig']);
		if ($andere !== []) {
			echo '<div class="kmm-staende kmm-zul-weitere"><span class="kmm-zul-gruppe">' . esc_html__('Gilt auch für', 'ksv-km-meldeportal') . '</span>';
			foreach ($andere as $x) {
				echo '<label class="kmm-stand"><input type="checkbox" name="weitere[]" value="' . (int) $x['id'] . '"> ' . esc_html(sprintf(__('Durchgang %1$d (%2$s)', 'ksv-km-meldeportal'), (int) $x['nummer'], (string) $x['zeit'])) . '</label>';
			}
			if (count($andere) > 1) {
				echo '<button type="button" class="button-link" data-kmm-weitere-alle>' . esc_html__('alle', 'ksv-km-meldeportal') . '</button>';
			}
			echo '</div>';
		}
		submit_button(__('Zulassung speichern', 'ksv-km-meldeportal'), 'primary', 'submit', false);
		echo $abbrechen . '</form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Liefert das Bearbeitungsfeld eines Durchgangs als HTML-Stück, damit die Seite beim
	 * Aufklappen nicht neu lädt. Ohne JavaScript führen dieselben Links zur ganzen Seite.
	 */
	public static function ajax_panel(): void {
		check_ajax_referer('kmm_' . self::SLUG, 'nonce');
		if (!Rechte::hat_recht(Rechte::RECHT_STARTPLAN)) {
			wp_die(esc_html__('Keine Berechtigung.', 'ksv-km-meldeportal'), '', ['response' => 403]);
		}
		$sid = isset($_GET['sportjahr']) ? (int) $_GET['sportjahr'] : 0;
		$tag_id = isset($_GET['tag']) ? (int) $_GET['tag'] : 0;
		$dg_id = isset($_GET['durchgang']) ? (int) $_GET['durchgang'] : 0;
		$service = new StartplanService($sid);
		try {
			$u = $service->uebersicht($tag_id);
		} catch (\RuntimeException $e) {
			wp_die(esc_html($e->getMessage()), '', ['response' => 404]);
		}
		foreach ($u['durchgaenge'] as $dg) {
			if ($dg['id'] === $dg_id && $dg['zustaendig']) {
				self::render_panel($sid, $tag_id, $service, $u, $dg, isset($_GET['zul']) ? (int) $_GET['zul'] : null);
				wp_die();
			}
		}
		wp_die(esc_html__('Durchgang nicht gefunden.', 'ksv-km-meldeportal'), '', ['response' => 404]);
	}

	/**
	 * Freigabe: Zahlen des Tages, was noch fehlt, und der Schritt in die Buchung.
	 *
	 * @param array<string, mixed> $u
	 */
	private static function ansicht_freigabe(int $sid, int $tag_id, array $u, bool $schreiben): void {
		$t = $u['tag'];
		echo '<div class="kmm-kacheln">';
		foreach ([[__('Einheiten', 'ksv-km-meldeportal'), count($u['einheiten'])], [__('Durchgänge', 'ksv-km-meldeportal'), count($u['durchgaenge'])], [__('Plätze', 'ksv-km-meldeportal'), (int) $u['plaetze']], [__('Bedarf', 'ksv-km-meldeportal'), array_sum(array_column($u['durchgaenge'], 'bedarf'))], [__('Gebucht', 'ksv-km-meldeportal'), (int) $u['buchungen']]] as [$label, $wert]) {
			echo '<div class="kmm-kachel"><div class="kmm-kachel-wert">' . (int) $wert . '</div><div class="kmm-kachel-label">' . esc_html($label) . '</div></div>';
		}
		echo '</div>';
		if ((string) $t['status'] === WettkampftagStatus::ENTWURF) {
			$ohne = self::ohne_zulassung($u);
			$punkte = [
				[$u['einheiten'] !== [], sprintf(_n('%d Einheit angelegt', '%d Einheiten angelegt', count($u['einheiten']), 'ksv-km-meldeportal'), count($u['einheiten'])), __('Noch keine Stände oder Einheiten', 'ksv-km-meldeportal'), 'staende'],
				[$u['durchgaenge'] !== [], sprintf(_n('%d Durchgang angelegt', '%d Durchgänge angelegt', count($u['durchgaenge']), 'ksv-km-meldeportal'), count($u['durchgaenge'])), __('Noch kein Durchgang', 'ksv-km-meldeportal'), 'durchgaenge'],
				[$u['durchgaenge'] !== [] && $ohne === 0, __('Jeder Durchgang hat eine Zulassung', 'ksv-km-meldeportal'), $u['durchgaenge'] === [] ? __('Zulassungen fehlen', 'ksv-km-meldeportal') : sprintf(_n('%d Durchgang ohne Zulassung', '%d Durchgänge ohne Zulassung', $ohne, 'ksv-km-meldeportal'), $ohne), 'durchgaenge'],
				[$t['buchungsfrist'] !== null, sprintf(__('Buchungsfrist: %s', 'ksv-km-meldeportal'), $t['buchungsfrist'] !== null ? Clock::format_local($t['buchungsfrist']) : ''), __('Keine Buchungsfrist gesetzt (möglich, aber unüblich)', 'ksv-km-meldeportal'), 'tag'],
			];
			echo '<ul class="kmm-checkliste">';
			foreach ($punkte as [$ok, $gut, $fehlt, $ziel]) {
				echo '<li class="' . ($ok ? 'is-ok' : 'is-offen') . '">' . esc_html($ok ? $gut : $fehlt);
				if (!$ok && $schreiben) {
					echo ' – <a href="' . esc_url(self::url(['sportjahr' => $sid, 'tag' => $tag_id, 'ansicht' => $ziel])) . '">' . esc_html__('dort ergänzen', 'ksv-km-meldeportal') . '</a>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}
		if ($schreiben) {
			self::render_freigabe($sid, $tag_id, $t, $u);
		}
		echo '<p class="description">' . esc_html__('Bedarf = buchbare Meldungen, die zu einer Zulassung des Tages passen.', 'ksv-km-meldeportal') . '</p>';
	}

	/**
	 * Startplan: nach der Freigabe die Schritte nach der Buchungsfrist, darunter die Matrix
	 * und die Buchungen.
	 *
	 * @param array<string, mixed> $u
	 */
	private static function ansicht_startplan(int $sid, int $tag_id, array $u, bool $schreiben): void {
		$t = $u['tag'];
		$status = (string) $t['status'];
		if ($schreiben && $status !== WettkampftagStatus::ENTWURF) {
			echo '<div class="kmm-phase">';
			self::render_nach_frist($sid, $tag_id, $t);
			if ($status === WettkampftagStatus::VEROEFFENTLICHT) {
				self::form_open('veroeffentlichung_zurueck', 'class="kmm-inline-form" onsubmit="return confirm(\'' . esc_js(__('Veröffentlichung zurücknehmen? Der Startplan verschwindet aus der Öffentlichkeit.', 'ksv-km-meldeportal')) . '\')"');
				echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tag_id" value="' . $tag_id . '">';
				submit_button(__('Veröffentlichung zurücknehmen', 'ksv-km-meldeportal'), 'secondary', 'submit', false);
				echo '</form>';
			}
			echo '</div>';
		}
		if ($u['einheiten'] === [] || $u['durchgaenge'] === []) {
			echo '<p>' . esc_html__('Noch kein Startplan: Er entsteht aus den Ständen und den Durchgängen.', 'ksv-km-meldeportal') . '</p>';
			return;
		}
		if ($status === WettkampftagStatus::ENTWURF) {
			echo '<p class="description">' . esc_html__('Entwurf: So sieht das Raster aus, in dem die Vereine nach der Freigabe buchen.', 'ksv-km-meldeportal') . '</p>';
		}
		echo '<p class="kmm-shortcode-zeile">' . esc_html__('Shortcode für Beitrag, Seite oder Kalendertermin:', 'ksv-km-meldeportal') . ' ' . self::shortcode_html($tag_id) . ' <span class="description">' . esc_html($status === WettkampftagStatus::VEROEFFENTLICHT ? __('Mit disziplin="1.10" zeigt er nur eine Disziplin.', 'ksv-km-meldeportal') : __('Er lässt sich schon jetzt einsetzen und zeigt den Plan, sobald er veröffentlicht ist. Mit disziplin="1.10" zeigt er nur eine Disziplin.', 'ksv-km-meldeportal')) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::render_matrix($sid, $tag_id, $schreiben);
		self::render_buchungen($sid, $tag_id, $u, $schreiben);
	}

	/**
	 * Zeigt beim Anlegen, welche Zeiten entstehen, und beschriftet den Knopf passend.
	 * Ohne JavaScript bleibt der erklärende Satz stehen; angelegt wird gleich.
	 */
	private static function durchgang_serie_script(): void {
		$texte = [
			'eins'    => __('Durchgang hinzufügen', 'ksv-km-meldeportal'),
			/* translators: %d: Zahl der Durchgänge */
			'viele'   => __('%d Durchgänge hinzufügen', 'ksv-km-meldeportal'),
			'zeiten'  => __('Es entstehen:', 'ksv-km-meldeportal'),
			'tag'     => __('Achtung: Nicht alle Durchgänge beginnen noch am Wettkampftag – so wird nichts angelegt.', 'ksv-km-meldeportal'),
			'ende'    => __('Das Ende muss nach dem Beginn liegen.', 'ksv-km-meldeportal'),
			'weitere' => __('… und %d weitere, der letzte', 'ksv-km-meldeportal'),
		];
		?>
<script>
(function(){
var f = document.querySelector('form.kmm-dg-neu'); if (!f) { return; }
var T = <?php echo wp_json_encode($texte); ?>;
var aus = f.querySelector('.kmm-dg-vorschau'), knopf = f.querySelector('input[type=submit]'), ruhe = aus.textContent;
function zwei(n){ return (n < 10 ? '0' : '') + n; }
function uhr(d){ return zwei(d.getHours()) + ':' + zwei(d.getMinutes()); }
function zeigen(){
	var n = Math.max(1, Math.min(50, parseInt(f.elements.anzahl.value, 10) || 1));
	var pause = Math.max(0, parseInt(f.elements.pause.value, 10) || 0) * 60000;
	var a = new Date(f.elements.beginn.value), e = new Date(f.elements.ende.value);
	knopf.value = n > 1 ? T.viele.replace('%d', n) : T.eins;
	aus.classList.remove('kmm-warn');
	if (n < 2 || isNaN(a) || isNaN(e)) { aus.textContent = ruhe; return; }
	if (e <= a) { aus.textContent = T.ende; aus.classList.add('kmm-warn'); return; }
	var dauer = e - a, teile = [], tag = a.toDateString(), passt = true, start = a.getTime(), letzter = '';
	for (var i = 0; i < n; i++) {
		var b = new Date(start), z = new Date(start + dauer);
		if (b.toDateString() !== tag) { passt = false; }
		letzter = uhr(b) + '–' + uhr(z);
		if (i < 6) { teile.push(letzter); }
		start += dauer + pause;
	}
	var text = T.zeiten + ' ' + teile.join(', ');
	if (n > 6) { text += ' ' + T.weitere.replace('%d', n - 6) + ' ' + letzter; }
	aus.textContent = passt ? text : T.tag;
	aus.classList.toggle('kmm-warn', !passt);
}
['anzahl', 'pause', 'beginn', 'ende'].forEach(function(n){ f.elements[n].addEventListener('input', zeigen); });
zeigen();
})();
</script>
		<?php
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
			self::push_haekchen($t, 'app_startplaetze_am');
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
			echo '<p class="description">' . esc_html__('Buchungen, Restverteilung und Veröffentlichung finden Sie im Reiter „Startplan“.', 'ksv-km-meldeportal') . '</p>';
		} else {
			echo '<p>' . esc_html(sprintf(__('Veröffentlicht am %s. Der Startplan ist öffentlich sichtbar; Änderungen des KSV wirken sofort.', 'ksv-km-meldeportal'), Clock::format_local($t['veroeffentlicht_am']))) . '</p>';
			echo '<p class="description">' . esc_html__('PDF, Shortcode und das Zurücknehmen der Veröffentlichung finden Sie im Reiter „Startplan“.', 'ksv-km-meldeportal') . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Häkchen „Auch als Push-Nachricht senden“ (App-Modul, Standard: an). Wurde das
	 * Ereignis für diesen Tag schon ausgelöst, steht stattdessen ein Hinweis da – jedes
	 * Ereignis geht nur einmal an die App.
	 *
	 * @param array<string, mixed> $t
	 */
	private static function push_haekchen(array $t, string $vermerk): void {
		if (($t[ $vermerk ] ?? null) !== null) {
			echo '<span class="description kmm-stand">' . esc_html(sprintf(__('Push-Nachricht wurde bereits am %s gesendet.', 'ksv-km-meldeportal'), Clock::format_local((string) $t[ $vermerk ]))) . '</span> ';
			return;
		}
		echo '<label class="kmm-stand"><input type="checkbox" name="push" value="1" checked> ' . esc_html__('Auch als Push-Nachricht senden', 'ksv-km-meldeportal') . '</label> ';
	}

	/** Zusatz zur Erfolgsmeldung zum App-Ereignis. */
	private static function push_hinweis(bool $push, bool $haekchen): string {
		if ($push) {
			return ' ' . __('Push-Nachricht an die App übergeben.', 'ksv-km-meldeportal');
		}
		if (!$haekchen) {
			return '';
		}
		return ' ' . (\KSV\KMM\Application\App\KmApp::verbunden()
			? __('Keine Push-Nachricht: Sie geht nur für das aktive Sportjahr und je Wettkampftag nur einmal.', 'ksv-km-meldeportal')
			: __('Keine Push-Nachricht: Der KSV App Connector ist nicht aktiv.', 'ksv-km-meldeportal'));
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
		if (!$veroeffentlicht) {
			self::form_open('veroeffentlichen', 'class="kmm-inline-form" onsubmit="return confirm(\'' . esc_js(__('Startplan veröffentlichen? Er ist dann über den Shortcode öffentlich sichtbar.', 'ksv-km-meldeportal')) . '\')"');
			echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tag_id" value="' . $tag_id . '">';
			echo '<label class="kmm-stand"><input type="checkbox" name="benachrichtigen" value="1" checked> ' . esc_html__('Vereine per Mail informieren', 'ksv-km-meldeportal') . '</label> ';
			self::push_haekchen($t, 'app_startplan_am');
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

	/**
	 * Shortcode des Startplans zum Kopieren. Er lässt sich jederzeit in einen Beitrag setzen;
	 * Inhalt zeigt er erst nach der Veröffentlichung.
	 */
	private static function shortcode_html(int $tag_id): string {
		$code = '[kmm_startplan tag="' . $tag_id . '"]';
		return '<span class="kmm-shortcode"><code>' . esc_html($code) . '</code> <button type="button" class="button button-small" data-kmm-kopieren="' . esc_attr($code) . '" data-kmm-kopiert="' . esc_attr__('Kopiert', 'ksv-km-meldeportal') . '">' . esc_html__('Kopieren', 'ksv-km-meldeportal') . '</button></span>';
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
