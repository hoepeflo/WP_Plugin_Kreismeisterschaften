<?php
/**
 * Backend: Verarbeitungsstatus nach Meldeschluss – Filter (Verein, Gruppe, Disziplin,
 * Status, kombinierbar), Einzelstatus mit Grund, Sammelaktion.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Admin;

use KSV\KMM\Application\VerarbeitungService;
use KSV\KMM\Auth\Rechte;
use KSV\KMM\Domain\Meldeergebnis;
use KSV\KMM\Domain\Verarbeitungsstatus;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\GruppeRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;
use KSV\KMM\Support\Clock;

final class VerarbeitungPage extends AdminPage {

	public const SLUG = 'kmm-verarbeitung';

	public static function handle_post(): void {
		if (!self::is_own_post()) {
			return;
		}
		if (!Rechte::darf_lesen()) {
			wp_die(esc_html__('Keine Berechtigung.', 'ksv-km-meldeportal'), '', ['response' => 403]);
		}
		check_admin_referer('kmm_' . self::SLUG);
		$sid = self::post_int('sportjahr_id');
		$filter = self::filter_aus_post();
		$args = array_filter(['sportjahr' => $sid] + $filter);
		$service = new VerarbeitungService($sid);
		try {
			switch (self::action()) {
				case 'status':
					$service->setzen(self::post_int('id'), self::post_str('status'), self::post_str('grund'));
					self::redirect(__('Status gespeichert.', 'ksv-km-meldeportal'), 'success', $args);
				case 'status_mehrere':
					$ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
					$status = self::post_str('status');
					$grund = self::post_str('grund');
					$n = 0;
					foreach ($ids as $id) {
						$service->setzen($id, $status, $grund);
						$n++;
					}
					self::redirect(sprintf(__('%d Meldungen auf „%s“ gesetzt.', 'ksv-km-meldeportal'), $n, Verarbeitungsstatus::label($status)), 'success', $args);
				case 'sammel':
					$n = $service->sammel_verarbeitet($filter);
					self::redirect(sprintf(__('%d ungeprüfte Meldungen als verarbeitet markiert.', 'ksv-km-meldeportal'), $n), 'success', $args);
			}
		} catch (\RuntimeException $e) {
			self::redirect($e->getMessage(), 'error', $args);
		}
	}

	/**
	 * @return array{verein_id: int, disziplin_id: int, gruppe: string, status: string}
	 */
	private static function filter_aus_post(): array {
		return ['verein_id' => self::post_int('verein_id'), 'disziplin_id' => self::post_int('disziplin_id'), 'gruppe' => self::post_str('gruppe'), 'status' => self::post_str('status_filter')];
	}

	/**
	 * @return array{verein_id: int, disziplin_id: int, gruppe: string, status: string}
	 */
	private static function filter_aus_get(): array {
		return [
			'verein_id'    => isset($_GET['verein_id']) ? (int) $_GET['verein_id'] : 0,
			'disziplin_id' => isset($_GET['disziplin_id']) ? (int) $_GET['disziplin_id'] : 0,
			'gruppe'       => isset($_GET['gruppe']) ? sanitize_key((string) $_GET['gruppe']) : '',
			'status'       => isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : '',
		];
	}

	public static function render(): void {
		if (!Rechte::darf_lesen()) {
			wp_die(esc_html__('Keine Berechtigung.', 'ksv-km-meldeportal'));
		}
		$sportjahr = self::current_sportjahr();
		echo '<div class="wrap kmm-admin">';
		Menu::page_header(__('Verarbeitungsstatus', 'ksv-km-meldeportal'));
		self::show_notices();
		if ($sportjahr === null) {
			self::no_sportjahr_notice();
			echo '</div>';
			return;
		}
		$sid = (int) $sportjahr['id'];
		$filter = self::filter_aus_get();
		$darf = Rechte::hat_recht(Rechte::RECHT_STATUS);
		$rw = RegelwerkLader::laden($sid);
		$service = new VerarbeitungService($sid);

		$vereine = [0 => __('alle Vereine', 'ksv-km-meldeportal')];
		foreach ((new VereinRepository())->all() as $v) {
			$vereine[ (int) $v['id'] ] = (string) $v['name'];
		}
		$gruppen = ['' => __('alle Gruppen', 'ksv-km-meldeportal')];
		foreach ((new GruppeRepository())->by_sportjahr($sid) as $g) {
			$gruppen[ (string) $g['code'] ] = (string) $g['bezeichnung'];
		}
		$disziplinen = [0 => __('alle Disziplinen', 'ksv-km-meldeportal')];
		foreach (Rechte::zustaendige($rw->disziplinen()) as $d) {
			$disziplinen[ $d->id ] = $d->kennzahl . ' ' . $d->bezeichnung;
		}
		$status_optionen = ['' => __('alle Status', 'ksv-km-meldeportal')];
		foreach (Verarbeitungsstatus::ALLE as $st) {
			$status_optionen[ $st ] = Verarbeitungsstatus::label($st);
		}

		echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="kmm-filter"><input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '"><input type="hidden" name="sportjahr" value="' . $sid . '">';
		echo self::select('verein_id', $vereine, $filter['verein_id']) . ' ' . self::select('gruppe', $gruppen, $filter['gruppe']) . ' ' . self::select('disziplin_id', $disziplinen, $filter['disziplin_id']) . ' ' . self::select('status', $status_optionen, $filter['status']) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		submit_button(__('Filtern', 'ksv-km-meldeportal'), 'secondary', '', false);
		echo '</form>';

		$zeilen = $service->liste($filter);
		$ungeprueft = count(array_filter($zeilen, static fn(array $z): bool => $z['verarbeitungsstatus'] === Verarbeitungsstatus::UNGEPRUEFT));
		echo '<p class="description">' . esc_html(sprintf(__('%d Meldungen in der Auswahl, davon %d ungeprüft. Nur eingereichte Vereinsmeldungen.', 'ksv-km-meldeportal'), count($zeilen), $ungeprueft)) . '</p>';

		if ($darf && $ungeprueft > 0) {
			self::form_open('sammel', 'class="kmm-inline-form" onsubmit="return confirm(\'' . esc_js(sprintf(__('%d ungeprüfte Meldungen der aktuellen Auswahl als verarbeitet markieren? Bereits als nicht startberechtigt markierte bleiben unberührt.', 'ksv-km-meldeportal'), $ungeprueft)) . '\')"');
			self::filter_hidden($sid, $filter);
			submit_button(sprintf(__('Alle %d ungeprüften als verarbeitet markieren', 'ksv-km-meldeportal'), $ungeprueft), 'primary', 'submit', false);
			echo '</form>';
		}

		if ($zeilen === []) {
			echo '<p>' . esc_html__('Keine Meldungen in der Auswahl.', 'ksv-km-meldeportal') . '</p></div>';
			return;
		}

		self::form_open('status_mehrere', 'id="kmm-status-mehrere"');
		self::filter_hidden($sid, $filter);
		echo '<table class="widefat striped kmm-verarbeitung"><thead><tr>';
		if ($darf) {
			echo '<td class="check-column"><input type="checkbox" onclick="document.querySelectorAll(\'#kmm-status-mehrere input[name=\\\'ids[]\\\']\').forEach(c=>c.checked=this.checked)"></td>';
		}
		echo '<th>Kennzahl</th><th>' . esc_html__('Verein', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Name', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Klasse → Start', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Ergebnis', 'ksv-km-meldeportal') . '</th><th>M.</th><th>' . esc_html__('Status', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Grund', 'ksv-km-meldeportal') . '</th></tr></thead><tbody>';
		$aktuell = '';
		foreach ($zeilen as $z) {
			$d = $z['disziplin'];
			if ($d->kennzahl !== $aktuell) {
				$aktuell = $d->kennzahl;
				echo '<tr class="kmm-group-row"><th colspan="9">' . esc_html($d->kennzahl . ' ' . $d->bezeichnung) . '</th></tr>';
			}
			$st = (string) $z['verarbeitungsstatus'];
			$zeilen_klasse = match ($st) {
				Verarbeitungsstatus::NICHT_STARTBERECHTIGT => 'kmm-zeile-konflikt',
				Verarbeitungsstatus::VERARBEITET => 'kmm-zeile-ok',
				default => '',
			};
			echo '<tr class="' . esc_attr($zeilen_klasse) . '">';
			if ($darf) {
				echo '<th class="check-column"><input type="checkbox" name="ids[]" value="' . (int) $z['id'] . '"></th>';
			}
			echo '<td>' . esc_html($d->kennzahl . '.' . ($z['startklasse']?->nummer ?? '')) . '</td>';
			echo '<td>' . esc_html((string) ($z['verein']['name'] ?? '')) . '</td>';
			echo '<td>' . esc_html(($z['schuetze']['nachname'] ?? '?') . ', ' . ($z['schuetze']['vorname'] ?? '')) . ($z['abgemeldet_am'] !== null ? ' <span class="kmm-badge">' . esc_html__('abgemeldet', 'ksv-km-meldeportal') . '</span>' : '') . ($z['nachgemeldet'] ? ' <span class="kmm-badge">' . esc_html__('Nachmeldung', 'ksv-km-meldeportal') . '</span>' : '') . '</td>';
			echo '<td>' . esc_html(($z['klasse']?->bezeichnung ?? '') . ' → ' . ($z['startklasse']?->bezeichnung ?? '')) . '</td>';
			echo '<td>' . esc_html(Meldeergebnis::format($z['meldeergebnis'] !== null ? (float) $z['meldeergebnis'] : null, $d->ergebnis_format) ?: '–') . '</td>';
			echo '<td>' . ($z['mannschaft'] !== null ? 'M' . (int) $z['mannschaft']['nummer'] . ($z['mannschaft']['unvollstaendig'] ? ' <span class="kmm-fail" title="unvollständig">!</span>' : '') : '') . '</td>';
			echo '<td><span class="kmm-badge kmm-status-' . esc_attr($st) . '">' . esc_html(Verarbeitungsstatus::label($st)) . '</span>' . ($z['verarbeitet_am'] ? '<br><small>' . esc_html(Clock::format_local($z['verarbeitet_am'], 'd.m. H:i')) . '</small>' : '') . '</td>';
			echo '<td>' . esc_html((string) $z['verarbeitungsgrund']);
			if ($darf) {
				echo '<details class="kmm-status-edit"><summary>' . esc_html__('ändern', 'ksv-km-meldeportal') . '</summary>';
				echo '<div class="kmm-status-form" data-id="' . (int) $z['id'] . '">' . self::select('st_' . (int) $z['id'], array_combine(Verarbeitungsstatus::ALLE, array_map([Verarbeitungsstatus::class, 'label'], Verarbeitungsstatus::ALLE)), $st) . ' ' . self::input('gr_' . (int) $z['id'], $z['verarbeitungsgrund'], 'text', 'placeholder="' . esc_attr__('Grund', 'ksv-km-meldeportal') . '"') . ' <button type="submit" class="button button-small" name="kmm_einzel" value="' . (int) $z['id'] . '">' . esc_html__('Speichern', 'ksv-km-meldeportal') . '</button></div></details>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		if ($darf) {
			echo '<div class="kmm-sammel-leiste"><strong>' . esc_html__('Markierte:', 'ksv-km-meldeportal') . '</strong> ' . self::select('status', array_combine(Verarbeitungsstatus::ALLE, array_map([Verarbeitungsstatus::class, 'label'], Verarbeitungsstatus::ALLE)), Verarbeitungsstatus::VERARBEITET) . ' ' . self::input('grund', '', 'text', 'placeholder="' . esc_attr__('Grund (bei nicht startberechtigt Pflicht)', 'ksv-km-meldeportal') . '" class="regular-text"') . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			submit_button(__('Status für markierte setzen', 'ksv-km-meldeportal'), 'secondary', 'submit', false);
			echo '</div>';
		}
		echo '</form>';
		// Einzel-Speichern: kleines Skript setzt die Felder des Einzelformulars um.
		echo '<script>document.addEventListener("click",function(e){var b=e.target.closest("button[name=kmm_einzel]");if(!b){return;}e.preventDefault();var id=b.value,f=document.getElementById("kmm-status-mehrere");f.querySelector("[name=kmm_action]").value="status";var i=document.createElement("input");i.type="hidden";i.name="id";i.value=id;f.appendChild(i);f.querySelector("[name=status]").value=f.querySelector("[name=st_"+id+"]").value;f.querySelector("[name=grund]").value=f.querySelector("[name=gr_"+id+"]").value;f.submit();});</script>';
		echo '</div>';
	}

	/**
	 * @param array{verein_id: int, disziplin_id: int, gruppe: string, status: string} $filter
	 */
	private static function filter_hidden(int $sid, array $filter): void {
		echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="verein_id" value="' . (int) $filter['verein_id'] . '"><input type="hidden" name="disziplin_id" value="' . (int) $filter['disziplin_id'] . '"><input type="hidden" name="gruppe" value="' . esc_attr($filter['gruppe']) . '"><input type="hidden" name="status_filter" value="' . esc_attr($filter['status']) . '">';
	}
}
