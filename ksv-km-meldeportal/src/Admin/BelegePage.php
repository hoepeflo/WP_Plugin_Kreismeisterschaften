<?php
/**
 * Backend: Buchhaltungsbelege je Verein erzeugen (einzeln oder alle), bisherige Belege
 * erneut herunterladen. Nur Administratoren (Konzept 10).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Admin;

use KSV\KMM\Application\BelegService;
use KSV\KMM\Application\Pdf;
use KSV\KMM\Infrastructure\Repository\BelegRepository;
use KSV\KMM\Infrastructure\Repository\MeldungRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;
use KSV\KMM\Support\Clock;

final class BelegePage extends AdminPage {

	public const SLUG = 'kmm-belege';

	public static function handle_post(): void {
		if (!self::is_own_post()) {
			return;
		}
		self::verify();
		$sid = self::post_int('sportjahr_id');
		$service = new BelegService();
		try {
			if (self::action() === 'erzeugen') {
				$r = $service->erzeugen($sid, self::post_int('verein_id'));
				self::download($r['pdf'], (string) $r['beleg']['dateiname']);
			}
			if (self::action() === 'alle') {
				$r = $service->alle($sid, !self::post_bool('entwuerfe'));
				self::download($r['pdf'], $r['dateiname']);
			}
			if (self::action() === 'download') {
				$beleg = (new BelegRepository())->find(self::post_int('beleg_id'));
				if ($beleg === null) {
					throw new \RuntimeException('Beleg nicht gefunden.');
				}
				self::download($service->pdf($beleg), (string) $beleg['dateiname']);
			}
		} catch (\RuntimeException $e) {
			self::redirect($e->getMessage(), 'error', ['sportjahr' => $sid]);
		}
	}

	private static function download(string $inhalt, string $dateiname): never {
		nocache_headers();
		header('Content-Type: application/pdf');
		header('Content-Disposition: attachment; filename="' . $dateiname . '"');
		header('Content-Length: ' . strlen($inhalt));
		echo $inhalt; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public static function render(): void {
		self::require_manage();
		$sportjahr = self::current_sportjahr();
		echo '<div class="wrap kmm-admin">';
		Menu::page_header(__('Buchhaltungsbelege', 'ksv-km-meldeportal'));
		self::show_notices();
		if ($sportjahr === null) {
			self::no_sportjahr_notice();
			echo '</div>';
			return;
		}
		$sid = (int) $sportjahr['id'];
		self::sportjahr_selector($sportjahr);
		if (!Pdf::verfuegbar()) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__('Die PDF-Bibliothek (mPDF) fehlt. Bitte das Plugin mit vendor/ ausliefern.', 'ksv-km-meldeportal') . '</p></div>';
		}
		$abgeschlossen = $sportjahr['abgeschlossen_am'] !== null;

		$service = new BelegService();
		$meldungen = (new MeldungRepository())->by_sportjahr($sid);
		$letzte = (new BelegRepository())->letzte_je_verein($sid);
		$zeilen = [];
		$summe = 0.0;
		$ungeprueft = 0;
		$ohne_beleg = 0;
		foreach ((new VereinRepository())->all() as $v) {
			$vid = (int) $v['id'];
			if (!isset($meldungen[ $vid ])) {
				continue;
			}
			$p = $abgeschlossen ? null : $service->positionen($sid, $v);
			if ($p !== null && $p['einzel'] === [] && $p['mannschaften'] === []) {
				continue;
			}
			$b = $letzte[ $vid ] ?? null;
			$zeilen[] = ['verein' => $v, 'meldung' => $meldungen[ $vid ], 'p' => $p, 'beleg' => $b];
			if ($p !== null) {
				$summe += $p['summe'];
				$ungeprueft += $p['ungeprueft'];
			}
			if ($b === null) {
				$ohne_beleg++;
			}
		}

		echo '<p class="description">' . esc_html__('Der Beleg ist keine Rechnung, sondern die Abrechnungsgrundlage für die Buchhaltung: je Verein Anzahl × Betrag nach Disziplin und Startklasse, danach die Mannschaften, am Ende die Gesamtsumme. Nicht startberechtigte Meldungen fehlen, abgemeldete stehen mit Vermerk und dem festgelegten Betrag. Jeder erzeugte Beleg wird mit Nummer gespeichert und kann unverändert erneut heruntergeladen werden.', 'ksv-km-meldeportal') . '</p>';
		if ($ungeprueft > 0) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html(sprintf(__('%d Meldungen sind noch ungeprüft. Belege sollten erst nach der Startrechtsprüfung erzeugt werden (Verarbeitung); erzeugte Belege tragen sonst einen Hinweis.', 'ksv-km-meldeportal'), $ungeprueft)) . ' <a href="' . esc_url(Menu::url(VerarbeitungPage::SLUG, ['sportjahr' => $sid])) . '">' . esc_html__('Zur Verarbeitung', 'ksv-km-meldeportal') . '</a></p></div>';
		}
		if ($abgeschlossen) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__('Das Sportjahr ist abgeschlossen. Belege können nur noch heruntergeladen werden.', 'ksv-km-meldeportal') . '</p></div>';
		}

		echo '<div class="kmm-kacheln">';
		echo '<div class="kmm-kachel"><div class="kmm-kachel-wert">' . count($zeilen) . '</div><div class="kmm-kachel-label">' . esc_html__('Vereine mit Meldungen', 'ksv-km-meldeportal') . '</div></div>';
		echo '<div class="kmm-kachel ' . ($ohne_beleg > 0 ? 'warn' : '') . '"><div class="kmm-kachel-wert">' . $ohne_beleg . '</div><div class="kmm-kachel-label">' . esc_html__('ohne Beleg', 'ksv-km-meldeportal') . '</div></div>';
		echo '<div class="kmm-kachel ' . ($ungeprueft > 0 ? 'warn' : '') . '"><div class="kmm-kachel-wert">' . $ungeprueft . '</div><div class="kmm-kachel-label">' . esc_html__('ungeprüft', 'ksv-km-meldeportal') . '</div></div>';
		if (!$abgeschlossen) {
			echo '<div class="kmm-kachel"><div class="kmm-kachel-wert">' . esc_html(self::geld($summe)) . '</div><div class="kmm-kachel-label">' . esc_html__('Startgeld aktuell', 'ksv-km-meldeportal') . '</div></div>';
		}
		echo '</div>';

		if (!$abgeschlossen) {
			self::form_open('alle', 'class="kmm-inline-form"');
			echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '">';
			echo self::checkbox('entwuerfe', false, __('auch Entwürfe (nicht eingereichte Meldungen)', 'ksv-km-meldeportal')) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			submit_button(__('Belege für alle Vereine erzeugen (ein PDF)', 'ksv-km-meldeportal'), 'primary', 'submit', false, $zeilen === [] ? ['disabled' => 'disabled'] : []);
			echo '</form>';
		}

		echo '<table class="widefat striped" style="margin-top:12px"><thead><tr><th>' . esc_html__('Verein', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Meldung', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Einzel', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Mannsch.', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Summe aktuell', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Hinweise', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Letzter Beleg', 'ksv-km-meldeportal') . '</th><th></th></tr></thead><tbody>';
		foreach ($zeilen as $z) {
			$v = $z['verein'];
			$p = $z['p'];
			$b = $z['beleg'];
			$hinweise = [];
			if ($p !== null) {
				if ($p['ungeprueft'] > 0) {
					$hinweise[] = '<span class="kmm-warn">' . esc_html(sprintf(__('%d ungeprüft', 'ksv-km-meldeportal'), $p['ungeprueft'])) . '</span>';
				}
				if ($p['nicht_startberechtigt'] > 0) {
					$hinweise[] = esc_html(sprintf(__('%d nicht startberechtigt', 'ksv-km-meldeportal'), $p['nicht_startberechtigt']));
				}
				if ($p['abgemeldet'] > 0) {
					$hinweise[] = esc_html(sprintf(__('%d abgemeldet', 'ksv-km-meldeportal'), $p['abgemeldet']));
				}
				if ($p['konflikte'] > 0) {
					$hinweise[] = '<span class="kmm-fail">' . esc_html(sprintf(__('%d Konflikte', 'ksv-km-meldeportal'), $p['konflikte'])) . '</span>';
				}
				if ($b !== null && abs((float) $b['summe'] - $p['summe']) > 0.005) {
					$hinweise[] = '<span class="kmm-warn">' . esc_html(sprintf(__('Summe seit Beleg geändert (%s)', 'ksv-km-meldeportal'), self::geld((float) $b['summe']))) . '</span>';
				}
			}
			echo '<tr>';
			echo '<td><strong>' . esc_html((string) $v['name']) . '</strong><br><small>VN ' . esc_html((string) $v['vn_nummer']) . '</small></td>';
			echo '<td>' . esc_html((string) $z['meldung']['status']) . '</td>';
			echo '<td class="r">' . ($p !== null ? (int) $p['anzahl_einzel'] : '–') . '</td>';
			echo '<td class="r">' . ($p !== null ? (int) $p['anzahl_mannschaften'] : '–') . '</td>';
			echo '<td class="r">' . ($p !== null ? esc_html(self::geld($p['summe'])) : '–') . '</td>';
			echo '<td>' . implode('<br>', $hinweise) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>';
			if ($b !== null) {
				$pos = json_decode((string) $b['positionen'], true);
				echo esc_html((string) (is_array($pos) ? ($pos['belegnummer'] ?? '') : '')) . '<br><small>' . esc_html(Clock::format_local($b['erstellt_am'])) . ' · ' . esc_html(self::geld((float) $b['summe'])) . ($b['ungeprueft_hinweis'] ? ' · ' . esc_html__('mit Hinweis', 'ksv-km-meldeportal') : '') . '</small>';
			} else {
				echo '<span class="kmm-muted">' . esc_html__('noch keiner', 'ksv-km-meldeportal') . '</span>';
			}
			echo '</td>';
			echo '<td class="kmm-nowrap">';
			if ($b !== null) {
				self::form_open('download', 'class="kmm-inline-form"');
				echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="beleg_id" value="' . (int) $b['id'] . '">';
				submit_button(__('PDF', 'ksv-km-meldeportal'), 'secondary small', 'submit', false);
				echo '</form> ';
			}
			if (!$abgeschlossen) {
				self::form_open('erzeugen', 'class="kmm-inline-form"');
				echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="verein_id" value="' . (int) $v['id'] . '">';
				submit_button($b !== null ? __('Neu erzeugen', 'ksv-km-meldeportal') : __('Beleg erzeugen', 'ksv-km-meldeportal'), 'primary small', 'submit', false);
				echo '</form>';
			}
			echo '</td></tr>';
		}
		if ($zeilen === []) {
			echo '<tr><td colspan="8">' . esc_html__('Keine Vereine mit Meldungen.', 'ksv-km-meldeportal') . '</td></tr>';
		}
		echo '</tbody></table>';

		$alle = (new BelegRepository())->where(['sportjahr_id' => $sid], 'id DESC');
		if ($alle !== []) {
			$vereine = [];
			foreach ((new VereinRepository())->all() as $v) {
				$vereine[ (int) $v['id'] ] = (string) $v['name'];
			}
			echo '<h2>' . esc_html__('Bisherige Belege', 'ksv-km-meldeportal') . '</h2>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Beleg-Nr.', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Verein', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Erstellt', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Summe', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Hinweis', 'ksv-km-meldeportal') . '</th><th></th></tr></thead><tbody>';
			foreach ($alle as $b) {
				$pos = json_decode((string) $b['positionen'], true);
				$user = $b['erstellt_von'] !== null ? get_userdata((int) $b['erstellt_von']) : null;
				echo '<tr><td><code>' . esc_html((string) (is_array($pos) ? ($pos['belegnummer'] ?? '') : '')) . '</code></td><td>' . esc_html($vereine[ (int) $b['verein_id'] ] ?? ('#' . (int) $b['verein_id'])) . '</td><td>' . esc_html(Clock::format_local($b['erstellt_am'], 'd.m.Y H:i:s')) . ($user instanceof \WP_User ? ' · ' . esc_html($user->display_name) : '') . '</td><td class="r">' . esc_html(self::geld((float) $b['summe'])) . '</td><td>' . ($b['ungeprueft_hinweis'] ? esc_html__('ungeprüfte Meldungen', 'ksv-km-meldeportal') : '') . '</td><td>';
				self::form_open('download', 'class="kmm-inline-form"');
				echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="beleg_id" value="' . (int) $b['id'] . '">';
				submit_button(__('PDF', 'ksv-km-meldeportal'), 'secondary small', 'submit', false);
				echo '</form></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}
}
