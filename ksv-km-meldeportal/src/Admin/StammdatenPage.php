<?php
/**
 * Backend: Stammdaten eines Sportjahres – Klassen, Disziplinen, Regeln, Tarife.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Admin;

use KSV\KMM\Application\Protokoll;
use KSV\KMM\Application\SportjahrService;
use KSV\KMM\Domain\DisziplinTyp;
use KSV\KMM\Domain\ErgebnisFormat;
use KSV\KMM\Domain\Geschlecht;
use KSV\KMM\Domain\HoehermeldungBereich;
use KSV\KMM\Domain\MixteamKennzahlModus;
use KSV\KMM\Domain\RegelModus;
use KSV\KMM\Domain\Tarifstufe;
use KSV\KMM\Infrastructure\Database\Tables;
use KSV\KMM\Infrastructure\Repository\DisziplinRepository;
use KSV\KMM\Infrastructure\Repository\GruppeRepository;
use KSV\KMM\Infrastructure\Repository\KlasseRepository;
use KSV\KMM\Infrastructure\Repository\RegelRepository;
use KSV\KMM\Infrastructure\Repository\TarifRepository;
use KSV\KMM\Support\Clock;

final class StammdatenPage extends AdminPage {

	public const SLUG = 'kmm-stammdaten';

	private const TABS = ['klassen', 'disziplinen', 'regeln', 'tarife'];

	public static function handle_post(): void {
		if (!self::is_own_post()) {
			return;
		}
		self::verify();
		$sportjahr_id = self::post_int('sportjahr_id');
		$tab = self::post_str('tab');
		$args = ['sportjahr' => $sportjahr_id, 'tab' => $tab];
		try {
			switch (self::action()) {
				case 'klassen_speichern':
					self::save_klassen($sportjahr_id);
					self::redirect(__('Klassen gespeichert.', 'ksv-km-meldeportal'), 'success', $args);
				case 'klasse_loeschen':
					self::delete_klasse($sportjahr_id, self::post_int('id'));
					self::redirect(__('Klasse gelöscht.', 'ksv-km-meldeportal'), 'success', $args);
				case 'disziplin_speichern':
					$id = self::save_disziplin($sportjahr_id);
					self::redirect(__('Disziplin gespeichert.', 'ksv-km-meldeportal'), 'success', $args + ['disziplin' => $id]);
				case 'disziplin_loeschen':
					self::delete_disziplin($sportjahr_id, self::post_int('id'));
					self::redirect(__('Disziplin gelöscht.', 'ksv-km-meldeportal'), 'success', $args);
				case 'disziplinen_schnell':
					$n = self::save_disziplinen_schnell($sportjahr_id);
					self::redirect($n > 0 ? sprintf(__('%d Disziplinen geändert. Bestehende Meldungen werden neu geprüft.', 'ksv-km-meldeportal'), $n) : __('Keine Änderungen.', 'ksv-km-meldeportal'), 'success', $args);
				case 'regeln_speichern':
					$n = self::save_regeln($sportjahr_id, self::post_int('disziplin_id'));
					self::redirect(sprintf(__('%d Regeln gespeichert. Bestehende Meldungen werden neu geprüft.', 'ksv-km-meldeportal'), $n), 'success', $args + ['disziplin' => self::post_int('disziplin_id')]);
				case 'tarife_speichern':
					self::save_tarife($sportjahr_id);
					self::redirect(__('Tarife gespeichert.', 'ksv-km-meldeportal'), 'success', $args);
			}
		} catch (\RuntimeException $e) {
			self::redirect($e->getMessage(), 'error', $args);
		}
	}

	public static function render(): void {
		self::require_manage();
		$sportjahr = self::current_sportjahr();
		$tab = isset($_GET['tab']) && in_array($_GET['tab'], self::TABS, true) ? (string) $_GET['tab'] : 'disziplinen';

		echo '<div class="wrap kmm-admin">';
		Menu::page_header(__('Stammdaten', 'ksv-km-meldeportal'));
		self::show_notices();
		if ($sportjahr === null) {
			self::no_sportjahr_notice();
			echo '</div>';
			return;
		}
		$sid = (int) $sportjahr['id'];
		self::sportjahr_selector($sportjahr, ['tab' => $tab]);

		echo '<nav class="nav-tab-wrapper">';
		foreach (self::TABS as $t) {
			$label = match ($t) {
				'klassen' => __('Klassen', 'ksv-km-meldeportal'),
				'disziplinen' => __('Disziplinen', 'ksv-km-meldeportal'),
				'regeln' => __('Regeln', 'ksv-km-meldeportal'),
				default => __('Startgeldtarife', 'ksv-km-meldeportal'),
			};
			echo '<a class="nav-tab' . ($t === $tab ? ' nav-tab-active' : '') . '" href="' . esc_url(self::url(['sportjahr' => $sid, 'tab' => $t])) . '">' . esc_html($label) . '</a>';
		}
		echo '</nav>';

		match ($tab) {
			'klassen' => self::render_klassen($sid),
			'disziplinen' => self::render_disziplinen($sid),
			'regeln' => self::render_regeln($sid),
			default => self::render_tarife($sid),
		};
		echo '</div>';
	}

	// ----- Klassen ---------------------------------------------------------------

	private static function render_klassen(int $sid): void {
		$gruppen = (new GruppeRepository())->by_sportjahr($sid);
		$klassen_repo = new KlasseRepository();
		$tarif_options = [];
		foreach (Tarifstufe::ALLE as $t) {
			$tarif_options[ $t ] = Tarifstufe::label($t);
		}
		$geschlecht_options = [];
		foreach (Geschlecht::ALLE as $g) {
			$geschlecht_options[ $g ] = Geschlecht::label($g);
		}

		echo '<p class="description">' . esc_html__('Klassenschlüssel ist Gruppe + Nummer + Geschlecht. Alter im Sportjahr (leer = offen). „Stufe“ verknüpft gleichwertige Klassen über Gruppen hinweg für Höhermeldungen (z. B. hd1 = Herren/Damen I). „Fest“ = keine Höhermeldung möglich (Schüler, Jugend).', 'ksv-km-meldeportal') . '</p>';
		self::form_open('klassen_speichern');
		echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tab" value="klassen">';
		foreach ($gruppen as $g) {
			$gid = (int) $g['id'];
			echo '<h2>' . esc_html((string) $g['bezeichnung']) . ' <code>' . esc_html((string) $g['code']) . '</code>';
			echo ' <small>' . esc_html($g['hoehermeldung_bereich'] ? __('Höhermeldung:', 'ksv-km-meldeportal') . ' ' . HoehermeldungBereich::label((string) $g['hoehermeldung_bereich']) : __('Klasse wird gewählt (Para)', 'ksv-km-meldeportal')) . '</small></h2>';
			echo '<table class="widefat striped kmm-klassen"><thead><tr><th>Nr.</th><th>Geschl.</th><th>Bezeichnung</th><th>Alter von</th><th>bis</th><th>Tarifstufe</th><th>Stufe</th><th>Fest</th><th>Team</th><th>Hinweis</th><th>Sort.</th><th></th></tr></thead><tbody>';
			foreach ($klassen_repo->by_gruppe($gid) as $k) {
				$p = 'k[' . (int) $k['id'] . ']';
				echo '<tr>';
				echo '<td><strong>' . (int) $k['nummer'] . '</strong></td>';
				echo '<td>' . esc_html(Geschlecht::label((string) $k['geschlecht'])) . '</td>';
				echo '<td>' . self::input($p . '[bezeichnung]', $k['bezeichnung'], 'text', 'required') . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td>' . self::input($p . '[alter_von]', $k['alter_von'], 'number', 'min="0" max="120" class="kmm-num"') . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td>' . self::input($p . '[alter_bis]', $k['alter_bis'], 'number', 'min="0" max="120" class="kmm-num"') . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td>' . self::select($p . '[tarifstufe]', $tarif_options, $k['tarifstufe']) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td>' . self::input($p . '[stufe]', $k['stufe'], 'text', 'class="kmm-short"') . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td>' . self::checkbox($p . '[festgeschrieben]', (bool) $k['festgeschrieben']) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td>' . self::checkbox($p . '[ist_teamklasse]', (bool) $k['ist_teamklasse']) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td>' . self::input($p . '[hinweis]', $k['hinweis']) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td>' . self::input($p . '[sortierung]', $k['sortierung'], 'number', 'class="kmm-num"') . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td><button type="submit" class="button-link kmm-danger" name="kmm_delete_klasse" value="' . (int) $k['id'] . '" formaction="' . esc_url(self::url()) . '" onclick="return confirm(\'' . esc_js(__('Klasse löschen?', 'ksv-km-meldeportal')) . '\')">✕</button></td>';
				echo '</tr>';
			}
			// Neue Klasse
			$p = 'neu[' . $gid . ']';
			echo '<tr class="kmm-new"><td>' . self::input($p . '[nummer]', '', 'number', 'min="1" max="999" class="kmm-num" placeholder="Nr."') . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . self::select($p . '[geschlecht]', $geschlecht_options, 'm') . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . self::input($p . '[bezeichnung]', '', 'text', 'placeholder="' . esc_attr__('Neue Klasse', 'ksv-km-meldeportal') . '"') . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . self::input($p . '[alter_von]', '', 'number', 'class="kmm-num"') . '</td><td>' . self::input($p . '[alter_bis]', '', 'number', 'class="kmm-num"') . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . self::select($p . '[tarifstufe]', $tarif_options, Tarifstufe::ERWACHSENE) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . self::input($p . '[stufe]', '', 'text', 'class="kmm-short"') . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . self::checkbox($p . '[festgeschrieben]', false) . '</td><td>' . self::checkbox($p . '[ist_teamklasse]', false) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . self::input($p . '[hinweis]', '') . '</td><td>' . self::input($p . '[sortierung]', 0, 'number', 'class="kmm-num"') . '</td><td></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</tbody></table>';
		}
		submit_button(__('Alle Klassen speichern', 'ksv-km-meldeportal'));
		echo '</form>';
	}

	private static function save_klassen(int $sid): void {
		if (isset($_POST['kmm_delete_klasse'])) {
			self::delete_klasse($sid, (int) $_POST['kmm_delete_klasse']);
			self::redirect(__('Klasse gelöscht.', 'ksv-km-meldeportal'), 'success', ['sportjahr' => $sid, 'tab' => 'klassen']);
		}
		$repo = new KlasseRepository();
		$rows = isset($_POST['k']) && is_array($_POST['k']) ? wp_unslash($_POST['k']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ($rows as $id => $d) {
			$k = $repo->find((int) $id);
			if ($k === null || (int) $k['sportjahr_id'] !== $sid || !is_array($d)) {
				continue;
			}
			$repo->update((int) $id, self::klasse_daten($d));
		}
		$neu = isset($_POST['neu']) && is_array($_POST['neu']) ? wp_unslash($_POST['neu']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$gruppen = new GruppeRepository();
		foreach ($neu as $gid => $d) {
			if (!is_array($d) || trim((string) ($d['nummer'] ?? '')) === '' || trim((string) ($d['bezeichnung'] ?? '')) === '') {
				continue;
			}
			$g = $gruppen->find((int) $gid);
			if ($g === null || (int) $g['sportjahr_id'] !== $sid) {
				continue;
			}
			$daten = self::klasse_daten($d);
			$daten['nummer'] = (int) $d['nummer'];
			$daten['geschlecht'] = Geschlecht::is_valid((string) ($d['geschlecht'] ?? '')) ? (string) $d['geschlecht'] : Geschlecht::M;
			$daten['ist_para'] = (bool) $g['ist_para'];
			if ($repo->by_key((int) $gid, $daten['nummer'], $daten['geschlecht']) !== null) {
				throw new \RuntimeException(sprintf('Klasse %d%s existiert in %s bereits.', $daten['nummer'], $daten['geschlecht'], (string) $g['bezeichnung']));
			}
			$daten['sportjahr_id'] = $sid;
			$daten['gruppe_id'] = (int) $gid;
			$repo->insert($daten);
		}
		Protokoll::admin('klassen.speichern', 'Klassen bearbeitet', $sid, null, 'sportjahr', $sid);
		(new SportjahrService())->regeln_geaendert($sid);
	}

	/**
	 * @param array<string, mixed> $d
	 * @return array<string, mixed>
	 */
	private static function klasse_daten(array $d): array {
		$tarif = (string) ($d['tarifstufe'] ?? Tarifstufe::ERWACHSENE);
		return [
			'bezeichnung'     => mb_substr(sanitize_text_field((string) ($d['bezeichnung'] ?? '')), 0, 100),
			'alter_von'       => trim((string) ($d['alter_von'] ?? '')) === '' ? null : (int) $d['alter_von'],
			'alter_bis'       => trim((string) ($d['alter_bis'] ?? '')) === '' ? null : (int) $d['alter_bis'],
			'tarifstufe'      => Tarifstufe::is_valid($tarif) ? $tarif : Tarifstufe::ERWACHSENE,
			'stufe'           => trim((string) ($d['stufe'] ?? '')) === '' ? null : mb_substr(sanitize_key((string) $d['stufe']), 0, 32),
			'festgeschrieben' => !empty($d['festgeschrieben']),
			'ist_teamklasse'  => !empty($d['ist_teamklasse']),
			'hinweis'         => mb_substr(sanitize_text_field((string) ($d['hinweis'] ?? '')), 0, 255),
			'sortierung'      => (int) ($d['sortierung'] ?? 0),
		];
	}

	private static function delete_klasse(int $sid, int $id): void {
		$repo = new KlasseRepository();
		$k = $repo->find($id);
		if ($k === null || (int) $k['sportjahr_id'] !== $sid) {
			throw new \RuntimeException('Klasse nicht gefunden.');
		}
		global $wpdb;
		$regeln = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Tables::name('regel') . ' WHERE klasse_id = %d OR einzel_ziel_klasse_id = %d OR mannschaft_ziel_klasse_id = %d', $id, $id, $id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$meldungen = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Tables::name('einzelmeldung') . ' WHERE klasse_id = %d OR startklasse_id = %d OR mannschaft_klasse_id = %d OR para_klasse_id = %d', $id, $id, $id, $id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ($regeln > 0 || $meldungen > 0) {
			throw new \RuntimeException(sprintf('Klasse wird noch verwendet (%d Regeln, %d Meldungen).', $regeln, $meldungen));
		}
		$repo->delete($id);
		Protokoll::admin('klasse.loeschen', sprintf('Klasse %d%s gelöscht', (int) $k['nummer'], (string) $k['geschlecht']), $sid, null, 'klasse', $id);
	}

	// ----- Disziplinen -----------------------------------------------------------

	private static function render_disziplinen(int $sid): void {
		$repo = new DisziplinRepository();
		$gruppen = [];
		foreach ((new GruppeRepository())->by_sportjahr($sid) as $g) {
			$gruppen[ (int) $g['id'] ] = (string) $g['bezeichnung'];
		}
		$edit_id = isset($_GET['disziplin']) ? (int) $_GET['disziplin'] : 0;
		$edit = $edit_id > 0 ? $repo->find($edit_id) : null;
		if ($edit !== null && (int) $edit['sportjahr_id'] !== $sid) {
			$edit = null;
		}
		$neu = isset($_GET['disziplin']) && $_GET['disziplin'] === 'neu';

		if ($edit !== null || $neu) {
			self::render_disziplin_form($sid, $edit, $gruppen);
		}

		$regel_counts = [];
		foreach ((new RegelRepository())->by_sportjahr($sid) as $r) {
			$regel_counts[ (int) $r['disziplin_id'] ] = ($regel_counts[ (int) $r['disziplin_id'] ] ?? 0) + 1;
		}

		echo '<p><a class="button button-primary" href="' . esc_url(self::url(['sportjahr' => $sid, 'tab' => 'disziplinen', 'disziplin' => 'neu'])) . '">' . esc_html__('Neue Disziplin', 'ksv-km-meldeportal') . '</a> ';
		echo '<a class="button" href="' . esc_url(Menu::url(ImportExportPage::SLUG, ['sportjahr' => $sid])) . '">' . esc_html__('Import aus JSON', 'ksv-km-meldeportal') . '</a></p>';
		$list = $repo->by_sportjahr($sid);
		if ($list === []) {
			echo '<p>' . esc_html__('Noch keine Disziplinen. Am schnellsten geht der Import des Konvertierungsergebnisses (JSON).', 'ksv-km-meldeportal') . '</p>';
			return;
		}
		// Schnellbearbeitung: Angeboten und Ergebnisformat direkt in der Tabelle. Die Felder
		// hängen über das form-Attribut an diesem Formular (keine verschachtelten Formulare).
		$schnell_id = 'kmm-disziplinen-schnell';
		self::form_open('disziplinen_schnell', 'id="' . $schnell_id . '" class="kmm-inline-form"');
		echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tab" value="disziplinen">';
		submit_button(__('Änderungen an Angeboten / Ergebnis speichern', 'ksv-km-meldeportal'), 'secondary', 'submit', false, ['id' => 'kmm-schnell-speichern']);
		echo ' <span class="description" id="kmm-schnell-hinweis">' . esc_html__('Änderungen in den Spalten „Angeboten“ und „Ergebnis“ werden direkt gespeichert.', 'ksv-km-meldeportal') . '</span></form>';
		$format_options = [];
		foreach (ErgebnisFormat::ALLE as $f) {
			$format_options[ $f ] = ErgebnisFormat::label($f);
		}
		echo '<table class="widefat striped"><thead><tr><th>Kennzahl</th><th>Bezeichnung</th><th>Gruppe</th><th>Typ</th><th>Angeboten</th><th>Mannsch.</th><th>Ergebnis</th><th>Tarif</th><th>M.-Startgeld</th><th>Regeln</th><th></th></tr></thead><tbody>';
		foreach ($list as $d) {
			$id = (int) $d['id'];
			echo '<tr' . ($d['angeboten'] ? '' : ' class="kmm-muted"') . '>';
			echo '<td><strong>' . esc_html((string) $d['kennzahl']) . '</strong></td>';
			echo '<td>' . esc_html((string) $d['bezeichnung']) . '</td>';
			echo '<td>' . esc_html($gruppen[ (int) $d['gruppe_id'] ] ?? '?') . '</td>';
			echo '<td>' . esc_html(DisziplinTyp::label((string) $d['typ'])) . '</td>';
			echo '<td><input type="checkbox" name="angeboten[' . $id . ']" value="1" form="' . $schnell_id . '" ' . checked((bool) $d['angeboten'], true, false) . ' title="' . esc_attr__('Bei der KM angeboten', 'ksv-km-meldeportal') . '"></td>';
			echo '<td>' . (int) $d['mannschaft_groesse'] . '</td>';
			echo '<td><input type="hidden" name="ids[]" value="' . $id . '" form="' . $schnell_id . '">' . self::select('ergebnis_format[' . $id . ']', $format_options, (string) $d['ergebnis_format'], 'form="' . $schnell_id . '"') . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . esc_html($d['tarif_override'] !== null ? self::geld($d['tarif_override']) : __('Stufe', 'ksv-km-meldeportal')) . '</td>';
			echo '<td>' . esc_html(self::geld($d['mannschaft_startgeld'])) . '</td>';
			echo '<td><a href="' . esc_url(self::url(['sportjahr' => $sid, 'tab' => 'regeln', 'disziplin' => $id])) . '">' . (int) ($regel_counts[ $id ] ?? 0) . '</a></td>';
			echo '<td class="kmm-actions"><a class="button button-small" href="' . esc_url(self::url(['sportjahr' => $sid, 'tab' => 'disziplinen', 'disziplin' => $id])) . '">' . esc_html__('Bearbeiten', 'ksv-km-meldeportal') . '</a> ';
			self::form_open('disziplin_loeschen', 'class="kmm-inline-form" onsubmit="return confirm(\'' . esc_js(__('Disziplin mit allen Regeln löschen?', 'ksv-km-meldeportal')) . '\')"');
			echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tab" value="disziplinen"><input type="hidden" name="id" value="' . $id . '">';
			submit_button(__('Löschen', 'ksv-km-meldeportal'), 'small kmm-danger', 'submit', false);
			echo '</form></td></tr>';
		}
		echo '</tbody></table>';
		// Direkt speichern, sobald ein Feld geändert wird (ohne JavaScript bleibt der Button).
		echo '<script>(function(){var f=document.getElementById("' . $schnell_id . '");if(!f)return;document.querySelectorAll("[form=\'' . $schnell_id . '\']").forEach(function(el){el.addEventListener("change",function(){document.getElementById("kmm-schnell-hinweis").textContent="' . esc_js(__('Speichern …', 'ksv-km-meldeportal')) . '";f.requestSubmit?f.requestSubmit():f.submit();});});})();</script>';
	}

	/**
	 * Schnellbearbeitung der Tabellenspalten „Angeboten“ und „Ergebnis“.
	 *
	 * @return int Zahl der geänderten Disziplinen
	 */
	private static function save_disziplinen_schnell(int $sid): int {
		$repo = new DisziplinRepository();
		$ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
		$angeboten = isset($_POST['angeboten']) && is_array($_POST['angeboten']) ? $_POST['angeboten'] : [];
		$formate = isset($_POST['ergebnis_format']) && is_array($_POST['ergebnis_format']) ? $_POST['ergebnis_format'] : [];
		$n = 0;
		$geaendert = [];
		foreach ($ids as $id) {
			$d = $repo->find($id);
			if ($d === null || (int) $d['sportjahr_id'] !== $sid) {
				continue;
			}
			$format = sanitize_key((string) ($formate[ $id ] ?? $d['ergebnis_format']));
			$daten = [
				'angeboten'       => isset($angeboten[ $id ]),
				'ergebnis_format' => ErgebnisFormat::is_valid($format) ? $format : (string) $d['ergebnis_format'],
			];
			if ($daten['angeboten'] === (bool) $d['angeboten'] && $daten['ergebnis_format'] === (string) $d['ergebnis_format']) {
				continue;
			}
			$repo->update($id, $daten);
			$geaendert[] = sprintf('%s: %s, %s', (string) $d['kennzahl'], $daten['angeboten'] ? 'angeboten' : 'nicht angeboten', ErgebnisFormat::label($daten['ergebnis_format']));
			$n++;
		}
		if ($n > 0) {
			Protokoll::admin('disziplin.schnell', sprintf('%d Disziplinen geändert: %s', $n, implode('; ', $geaendert)), $sid, null, 'sportjahr', $sid);
			(new SportjahrService())->regeln_geaendert($sid);
		}
		return $n;
	}

	/**
	 * @param array<string, mixed>|null $d
	 * @param array<int, string>        $gruppen
	 */
	private static function render_disziplin_form(int $sid, ?array $d, array $gruppen): void {
		$typ_options = [];
		foreach (DisziplinTyp::ALLE as $t) {
			$typ_options[ $t ] = DisziplinTyp::label($t);
		}
		$format_options = [];
		foreach (ErgebnisFormat::ALLE as $f) {
			$format_options[ $f ] = ErgebnisFormat::label($f);
		}
		$mix_options = ['' => __('– (kein MixTeam)', 'ksv-km-meldeportal')];
		foreach (MixteamKennzahlModus::ALLE as $m) {
			$mix_options[ $m ] = MixteamKennzahlModus::label($m);
		}
		echo '<h2>' . esc_html($d === null ? __('Neue Disziplin', 'ksv-km-meldeportal') : sprintf(__('Disziplin %s bearbeiten', 'ksv-km-meldeportal'), (string) $d['kennzahl'])) . '</h2>';
		self::form_open('disziplin_speichern');
		echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tab" value="disziplinen"><input type="hidden" name="id" value="' . (int) ($d['id'] ?? 0) . '">';
		echo '<table class="form-table">';
		$row = static function (string $label, string $field, string $desc = ''): void {
			echo '<tr><th>' . esc_html($label) . '</th><td>' . $field . ($desc !== '' ? '<p class="description">' . esc_html($desc) . '</p>' : '') . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		};
		$row(__('Kennzahl', 'ksv-km-meldeportal'), self::input('kennzahl', $d['kennzahl'] ?? '', 'text', 'required class="kmm-short"'), __('z. B. 1.10, 1.56S, 12.10 (Blasrohr)', 'ksv-km-meldeportal'));
		$row(__('Bezeichnung', 'ksv-km-meldeportal'), self::input('bezeichnung', $d['bezeichnung'] ?? '', 'text', 'required class="regular-text"'));
		$row(__('Wettbewerbsgruppe', 'ksv-km-meldeportal'), self::select('gruppe_id', $gruppen, $d['gruppe_id'] ?? array_key_first($gruppen)));
		$row(__('Typ', 'ksv-km-meldeportal'), self::select('typ', $typ_options, $d['typ'] ?? DisziplinTyp::NORMAL), __('MixTeam: genau 1 m + 1 w, keine Einzelwertung. Bogen: kein DAVID-Export, keine Mannschaften.', 'ksv-km-meldeportal'));
		$row(__('Bei der KM angeboten', 'ksv-km-meldeportal'), self::checkbox('angeboten', $d === null ? true : (bool) $d['angeboten']));
		$row(__('Mannschaftsgröße', 'ksv-km-meldeportal'), self::input('mannschaft_groesse', $d['mannschaft_groesse'] ?? 3, 'number', 'min="0" max="10" class="kmm-num"'), __('0 = keine Mannschaften', 'ksv-km-meldeportal'));
		$row(__('Ergebnisformat', 'ksv-km-meldeportal'), self::select('ergebnis_format', $format_options, $d['ergebnis_format'] ?? ErgebnisFormat::GANZ));
		$row(__('Tarif-Überschreibung (€)', 'ksv-km-meldeportal'), self::input('tarif_override', $d !== null && $d['tarif_override'] !== null ? number_format((float) $d['tarif_override'], 2, ',', '') : '', 'text', 'class="kmm-short" placeholder="z. B. 2,50"'), __('Leer = Tarif der Tarifstufe der Startklasse.', 'ksv-km-meldeportal'));
		$row(__('Mannschaftsstartgeld (€)', 'ksv-km-meldeportal'), self::input('mannschaft_startgeld', number_format((float) ($d['mannschaft_startgeld'] ?? 0), 2, ',', ''), 'text', 'class="kmm-short"'));
		$row(__('MixTeam: Kennzahl mit', 'ksv-km-meldeportal'), self::select('mixteam_kennzahl_modus', $mix_options, $d['mixteam_kennzahl_modus'] ?? ''), __('Team- oder Geschlechterklasse in der DAVID-Kennzahl (offener Punkt).', 'ksv-km-meldeportal'));
		$row(__('Hinweis (Sonstiges)', 'ksv-km-meldeportal'), '<textarea name="hinweis" rows="2" class="large-text">' . esc_textarea((string) ($d['hinweis'] ?? '')) . '</textarea>');
		$row(__('Sortierung', 'ksv-km-meldeportal'), self::input('sortierung', $d['sortierung'] ?? 0, 'number', 'class="kmm-num"'));
		echo '</table>';
		submit_button(__('Speichern', 'ksv-km-meldeportal'), 'primary', 'submit', false);
		echo ' <a class="button" href="' . esc_url(self::url(['sportjahr' => $sid, 'tab' => 'disziplinen'])) . '">' . esc_html__('Abbrechen', 'ksv-km-meldeportal') . '</a>';
		echo '</form><hr>';
	}

	private static function save_disziplin(int $sid): int {
		$repo = new DisziplinRepository();
		$id = self::post_int('id');
		$kennzahl = self::post_str('kennzahl', 16);
		if (!preg_match('/^\d{1,2}\.\d{2}(?:\s?[A-Za-z]{1,2})?$/', $kennzahl)) {
			throw new \RuntimeException('Ungültige Kennzahl.');
		}
		$vorhanden = $repo->by_kennzahl($sid, $kennzahl);
		if ($vorhanden !== null && (int) $vorhanden['id'] !== $id) {
			throw new \RuntimeException(sprintf('Kennzahl %s existiert bereits.', $kennzahl));
		}
		$gruppe = (new GruppeRepository())->find(self::post_int('gruppe_id'));
		if ($gruppe === null || (int) $gruppe['sportjahr_id'] !== $sid) {
			throw new \RuntimeException('Ungültige Gruppe.');
		}
		$typ = self::post_str('typ');
		$format = self::post_str('ergebnis_format');
		$mix = self::post_str('mixteam_kennzahl_modus');
		$daten = [
			'kennzahl'               => $kennzahl,
			'bezeichnung'            => self::post_str('bezeichnung', 150),
			'gruppe_id'              => (int) $gruppe['id'],
			'typ'                    => DisziplinTyp::is_valid($typ) ? $typ : DisziplinTyp::NORMAL,
			'angeboten'              => self::post_bool('angeboten'),
			'mannschaft_groesse'     => max(0, min(10, self::post_int('mannschaft_groesse'))),
			'ergebnis_format'        => ErgebnisFormat::is_valid($format) ? $format : ErgebnisFormat::GANZ,
			'tarif_override'         => self::post_float_or_null('tarif_override'),
			'mannschaft_startgeld'   => self::post_float_or_null('mannschaft_startgeld') ?? 0.0,
			'mixteam_kennzahl_modus' => MixteamKennzahlModus::is_valid($mix) ? $mix : null,
			'hinweis'                => self::post_text('hinweis'),
			'sortierung'             => self::post_int('sortierung'),
		];
		if ($daten['typ'] === DisziplinTyp::MIXTEAM && $daten['mixteam_kennzahl_modus'] === null) {
			$daten['mixteam_kennzahl_modus'] = MixteamKennzahlModus::TEAM;
		}
		if ($id > 0) {
			$d = $repo->find($id);
			if ($d === null || (int) $d['sportjahr_id'] !== $sid) {
				throw new \RuntimeException('Disziplin nicht gefunden.');
			}
			$repo->update($id, $daten);
		} else {
			$daten['sportjahr_id'] = $sid;
			$id = $repo->insert($daten);
		}
		Protokoll::admin('disziplin.speichern', sprintf('Disziplin %s gespeichert', $kennzahl), $sid, null, 'disziplin', $id, $daten);
		(new SportjahrService())->regeln_geaendert($sid);
		return $id;
	}

	private static function delete_disziplin(int $sid, int $id): void {
		$repo = new DisziplinRepository();
		$d = $repo->find($id);
		if ($d === null || (int) $d['sportjahr_id'] !== $sid) {
			throw new \RuntimeException('Disziplin nicht gefunden.');
		}
		global $wpdb;
		$meldungen = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Tables::name('einzelmeldung') . ' WHERE disziplin_id = %d', $id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ($meldungen > 0) {
			throw new \RuntimeException(sprintf('Disziplin hat %d Meldungen und kann nicht gelöscht werden. Stattdessen „angeboten“ abschalten.', $meldungen));
		}
		(new RegelRepository())->delete_where(['disziplin_id' => $id]);
		$repo->delete($id);
		Protokoll::admin('disziplin.loeschen', sprintf('Disziplin %s gelöscht', (string) $d['kennzahl']), $sid, null, 'disziplin', $id);
		(new SportjahrService())->regeln_geaendert($sid);
	}

	// ----- Regeln ----------------------------------------------------------------

	private static function render_regeln(int $sid): void {
		$disziplinen = (new DisziplinRepository())->by_sportjahr($sid);
		if ($disziplinen === []) {
			echo '<p>' . esc_html__('Noch keine Disziplinen vorhanden.', 'ksv-km-meldeportal') . '</p>';
			return;
		}
		$options = [];
		foreach ($disziplinen as $d) {
			$options[ (int) $d['id'] ] = $d['kennzahl'] . ' ' . $d['bezeichnung'];
		}
		$disziplin_id = isset($_GET['disziplin']) ? (int) $_GET['disziplin'] : (int) $disziplinen[0]['id'];
		if (!isset($options[ $disziplin_id ])) {
			$disziplin_id = (int) $disziplinen[0]['id'];
		}
		echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="kmm-inline-form">';
		echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '"><input type="hidden" name="sportjahr" value="' . $sid . '"><input type="hidden" name="tab" value="regeln">';
		echo '<label>' . esc_html__('Disziplin', 'ksv-km-meldeportal') . ' ' . self::select('disziplin', $options, $disziplin_id, 'onchange="this.form.submit()"') . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</form>';

		$disziplin = (new DisziplinRepository())->find($disziplin_id);
		if ($disziplin === null) {
			return;
		}
		$gruppen = (new GruppeRepository())->by_sportjahr($sid);
		$klassen_repo = new KlasseRepository();
		$alle_klassen = $klassen_repo->by_sportjahr($sid);
		$gruppen_by_id = [];
		foreach ($gruppen as $g) {
			$gruppen_by_id[ (int) $g['id'] ] = $g;
		}
		// Zeilen: Klassen der Disziplingruppe + Para-Klassen. Ziele: alle Klassen des Sportjahres.
		$zeilen = array_values(array_filter($alle_klassen, static fn(array $k): bool => (int) $k['gruppe_id'] === (int) $disziplin['gruppe_id'] || (bool) $k['ist_para']));
		$ziel_options = ['' => '–'];
		foreach ($alle_klassen as $k) {
			$g = $gruppen_by_id[ (int) $k['gruppe_id'] ] ?? null;
			$prefix = $g !== null && (int) $g['id'] !== (int) $disziplin['gruppe_id'] ? $g['code'] . ':' : '';
			$ziel_options[ (int) $k['id'] ] = $prefix . $k['nummer'] . $k['geschlecht'] . ' ' . $k['bezeichnung'];
		}
		$regeln = [];
		foreach ((new RegelRepository())->by_disziplin($disziplin_id) as $r) {
			$regeln[ (int) $r['klasse_id'] ] = $r;
		}
		$modus_options = [];
		foreach (RegelModus::ALLE as $m) {
			$modus_options[ $m ] = RegelModus::label($m);
		}

		echo '<h2>' . esc_html($disziplin['kennzahl'] . ' ' . $disziplin['bezeichnung']) . ' <small>(' . esc_html(DisziplinTyp::label((string) $disziplin['typ'])) . ')</small></h2>';
		echo '<p class="description">' . esc_html__('Je Klasse: Einzel und Mannschaft mit „kein Startrecht“, „eigene Wertung“ oder „startet in Klasse X“ (Ziel wählen). Fehlende Zeilen bedeuten kein Startrecht. Mindestalter prüft das volle Geburtsdatum (z. B. 18 bei Vorderlader Junioren II). Bei MixTeams ist nur der Mannschaftsteil relevant; Teamklassen (x) sind das Ziel.', 'ksv-km-meldeportal') . '</p>';
		self::form_open('regeln_speichern');
		echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tab" value="regeln"><input type="hidden" name="disziplin_id" value="' . $disziplin_id . '">';
		echo '<table class="widefat striped kmm-regeln"><thead><tr><th>Klasse</th><th>Einzel</th><th>Einzel-Ziel</th><th>Mannschaft</th><th>Mannschaft-Ziel</th><th>Mindestalter</th><th>Hinweis</th></tr></thead><tbody>';
		$letzte_gruppe = null;
		foreach ($zeilen as $k) {
			$kid = (int) $k['id'];
			if ($letzte_gruppe !== (int) $k['gruppe_id']) {
				$letzte_gruppe = (int) $k['gruppe_id'];
				echo '<tr class="kmm-group-row"><th colspan="7">' . esc_html((string) ($gruppen_by_id[ $letzte_gruppe ]['bezeichnung'] ?? '')) . '</th></tr>';
			}
			$r = $regeln[ $kid ] ?? null;
			$p = 'r[' . $kid . ']';
			$aktiv = $r !== null && ($r['einzel_modus'] !== RegelModus::KEINE || $r['mannschaft_modus'] !== RegelModus::KEINE);
			echo '<tr class="' . ($aktiv ? 'kmm-rule-active' : 'kmm-rule-none') . '">';
			echo '<td><strong>' . (int) $k['nummer'] . esc_html((string) $k['geschlecht']) . '</strong> ' . esc_html((string) $k['bezeichnung']) . '</td>';
			echo '<td>' . self::select($p . '[einzel]', $modus_options, $r['einzel_modus'] ?? RegelModus::KEINE) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . self::select($p . '[einzel_ziel]', $ziel_options, $r['einzel_ziel_klasse_id'] ?? '') . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . self::select($p . '[mannschaft]', $modus_options, $r['mannschaft_modus'] ?? RegelModus::KEINE) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . self::select($p . '[mannschaft_ziel]', $ziel_options, $r['mannschaft_ziel_klasse_id'] ?? '') . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . self::input($p . '[mindestalter]', $r['mindestalter'] ?? '', 'number', 'min="0" max="99" class="kmm-num"') . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . self::input($p . '[hinweis]', $r['hinweis'] ?? '') . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</tr>';
		}
		echo '</tbody></table>';
		submit_button(__('Regeln speichern', 'ksv-km-meldeportal'));
		echo '</form>';
	}

	private static function save_regeln(int $sid, int $disziplin_id): int {
		$disziplin = (new DisziplinRepository())->find($disziplin_id);
		if ($disziplin === null || (int) $disziplin['sportjahr_id'] !== $sid) {
			throw new \RuntimeException('Disziplin nicht gefunden.');
		}
		$klassen_repo = new KlasseRepository();
		$klassen_ids = [];
		foreach ($klassen_repo->by_sportjahr($sid) as $k) {
			$klassen_ids[ (int) $k['id'] ] = true;
		}
		$rows = isset($_POST['r']) && is_array($_POST['r']) ? wp_unslash($_POST['r']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// Erst vollständig prüfen, dann ersetzen – ein Fehler darf keine Regeln löschen.
		$neu = [];
		foreach ($rows as $kid => $d) {
			$kid = (int) $kid;
			if (!isset($klassen_ids[ $kid ]) || !is_array($d)) {
				continue;
			}
			$einzel = RegelModus::is_valid((string) ($d['einzel'] ?? '')) ? (string) $d['einzel'] : RegelModus::KEINE;
			$mannschaft = RegelModus::is_valid((string) ($d['mannschaft'] ?? '')) ? (string) $d['mannschaft'] : RegelModus::KEINE;
			$einzel_ziel = (int) ($d['einzel_ziel'] ?? 0);
			$mannschaft_ziel = (int) ($d['mannschaft_ziel'] ?? 0);
			if ($einzel === RegelModus::VERWEIS && !isset($klassen_ids[ $einzel_ziel ])) {
				throw new \RuntimeException(sprintf('Klasse %d: Einzel-Verweis ohne Zielklasse.', $kid));
			}
			if ($mannschaft === RegelModus::VERWEIS && !isset($klassen_ids[ $mannschaft_ziel ])) {
				throw new \RuntimeException(sprintf('Klasse %d: Mannschafts-Verweis ohne Zielklasse.', $kid));
			}
			if ($einzel === RegelModus::KEINE && $mannschaft === RegelModus::KEINE) {
				continue;
			}
			$neu[] = [
				'sportjahr_id'              => $sid,
				'disziplin_id'              => $disziplin_id,
				'klasse_id'                 => $kid,
				'einzel_modus'              => $einzel,
				'einzel_ziel_klasse_id'     => $einzel === RegelModus::VERWEIS ? $einzel_ziel : null,
				'mannschaft_modus'          => $mannschaft,
				'mannschaft_ziel_klasse_id' => $mannschaft === RegelModus::VERWEIS ? $mannschaft_ziel : null,
				'mindestalter'              => trim((string) ($d['mindestalter'] ?? '')) === '' ? null : (int) $d['mindestalter'],
				'hinweis'                   => mb_substr(sanitize_text_field((string) ($d['hinweis'] ?? '')), 0, 255),
				'quelle'                    => 'manuell',
				'updated_at'                => Clock::now_utc(),
			];
		}
		$repo = new RegelRepository();
		global $wpdb;
		$wpdb->query('START TRANSACTION');
		$repo->delete_where(['disziplin_id' => $disziplin_id]);
		$n = 0;
		foreach ($neu as $r) {
			if ($repo->insert($r) <= 0) {
				$wpdb->query('ROLLBACK');
				throw new \RuntimeException('Regel konnte nicht gespeichert werden: ' . $repo->last_error());
			}
			$n++;
		}
		$wpdb->query('COMMIT');
		Protokoll::admin('regeln.speichern', sprintf('Regeln für %s gespeichert (%d)', (string) $disziplin['kennzahl'], $n), $sid, null, 'disziplin', $disziplin_id);
		(new SportjahrService())->regeln_geaendert($sid);
		return $n;
	}

	// ----- Tarife ----------------------------------------------------------------

	private static function render_tarife(int $sid): void {
		$tarife = (new TarifRepository())->by_sportjahr($sid);
		self::form_open('tarife_speichern');
		echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '"><input type="hidden" name="tab" value="tarife">';
		echo '<table class="form-table">';
		foreach (Tarifstufe::ALLE as $stufe) {
			echo '<tr><th>' . esc_html(Tarifstufe::label($stufe)) . '</th><td>' . self::input('tarif[' . $stufe . ']', number_format($tarife[ $stufe ], 2, ',', ''), 'text', 'class="kmm-short"') . ' €</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</table>';
		echo '<p class="description">' . esc_html__('Maßgeblich ist die Tarifstufe der Startklasse. Abweichende Tarife je Disziplin (z. B. Lichtschießen) und Mannschaftsstartgelder werden an der Disziplin gepflegt.', 'ksv-km-meldeportal') . '</p>';
		submit_button(__('Tarife speichern', 'ksv-km-meldeportal'));
		echo '</form>';
	}

	private static function save_tarife(int $sid): void {
		$repo = new TarifRepository();
		$eingabe = isset($_POST['tarif']) && is_array($_POST['tarif']) ? wp_unslash($_POST['tarif']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$werte = [];
		foreach (Tarifstufe::ALLE as $stufe) {
			$betrag = round((float) str_replace(',', '.', (string) ($eingabe[ $stufe ] ?? '0')), 2);
			if ($betrag < 0) {
				throw new \RuntimeException('Negative Beträge sind nicht erlaubt.');
			}
			$repo->set($sid, $stufe, $betrag);
			$werte[ $stufe ] = $betrag;
		}
		Protokoll::admin('tarife.speichern', 'Startgeldtarife gespeichert', $sid, null, 'sportjahr', $sid, $werte);
		(new SportjahrService())->regeln_geaendert($sid);
	}
}
