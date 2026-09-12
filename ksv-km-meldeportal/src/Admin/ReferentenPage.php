<?php
/**
 * Backend: Referenten verwalten (nur Admin) – Zuständigkeiten und Einzelrechte je
 * WordPress-Benutzer mit der Rolle „KM-Referent“.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Admin;

use KSV\KMM\Application\ReferentService;
use KSV\KMM\Auth\Capabilities;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\GruppeRepository;
use KSV\KMM\Infrastructure\Repository\ReferentRepository;
use KSV\KMM\Infrastructure\Repository\ReferentZustaendigkeitRepository;

final class ReferentenPage extends AdminPage {

	public const SLUG = 'kmm-referenten';

	public static function handle_post(): void {
		if (!self::is_own_post()) {
			return;
		}
		self::verify();
		$service = new ReferentService();
		try {
			if (self::action() === 'speichern') {
				$gruppen = isset($_POST['gruppen']) && is_array($_POST['gruppen']) ? array_map(static fn($g): string => sanitize_key((string) $g), $_POST['gruppen']) : [];
				$disziplinen = isset($_POST['disziplinen']) && is_array($_POST['disziplinen']) ? array_map(static fn($d): string => sanitize_text_field((string) wp_unslash($d)), $_POST['disziplinen']) : [];
				$id = $service->speichern(
					self::post_int('id'),
					self::post_int('user_id'),
					['darf_status' => self::post_bool('darf_status'), 'darf_meldungen' => self::post_bool('darf_meldungen'), 'darf_startplan' => self::post_bool('darf_startplan')],
					$gruppen,
					$disziplinen,
					self::post_str('notiz')
				);
				self::redirect(__('Referent gespeichert.', 'ksv-km-meldeportal'), 'success', ['edit' => $id]);
			}
			if (self::action() === 'loeschen') {
				$service->loeschen(self::post_int('id'));
				self::redirect(__('Referent entfernt. Der WordPress-Benutzer bleibt bestehen.', 'ksv-km-meldeportal'));
			}
		} catch (\InvalidArgumentException | \RuntimeException $e) {
			self::redirect($e->getMessage(), 'error', self::post_int('id') > 0 ? ['edit' => self::post_int('id')] : []);
		}
	}

	public static function render(): void {
		self::require_manage();
		echo '<div class="wrap kmm-admin">';
		Menu::page_header(__('Referenten', 'ksv-km-meldeportal'));
		self::show_notices();
		$service = new ReferentService();
		$edit = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
		$neu = isset($_GET['neu']);

		echo '<p class="description">' . esc_html__('Referenten sind beliebige WordPress-Benutzer (jede Rolle, z. B. Redakteur des Ergebnis-Plugins); die nötigen Rechte werden beim Eintragen automatisch gesetzt. Hier werden ihnen Wettbewerbsgruppen oder einzelne Disziplinen zugewiesen; sie sehen im Backend nur diesen Bereich. Lesen und PDF-Listen sind immer erlaubt, weitere Rechte werden je Person freigeschaltet. Regeltabelle, Startgelder, Vereine, DAVID-Export, Belege und der Abschluss bleiben dem Admin vorbehalten.', 'ksv-km-meldeportal') . ' <a href="' . esc_url(admin_url('user-new.php')) . '">' . esc_html__('Benutzer anlegen', 'ksv-km-meldeportal') . '</a></p>';

		$liste = $service->liste();
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Referent', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Zuständig für', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Rechte', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Notiz', 'ksv-km-meldeportal') . '</th><th></th></tr></thead><tbody>';
		foreach ($liste as $r) {
			$z = ['gruppen' => [], 'disziplinen' => []];
			foreach ($r['zustaendigkeiten'] as $e) {
				$z[ $e['typ'] === ReferentZustaendigkeitRepository::TYP_GRUPPE ? 'gruppen' : 'disziplinen' ][] = (string) $e['schluessel'];
			}
			$rechte = array_keys(array_filter(['Status setzen' => $r['darf_status'], 'Meldungen bearbeiten' => $r['darf_meldungen'], 'Startplan bearbeiten' => $r['darf_startplan']]));
			echo '<tr><td><strong>' . esc_html((string) $r['name']) . '</strong>' . ($r['benutzer'] instanceof \WP_User ? '<br><small>' . esc_html($r['benutzer']->user_email) . '</small>' : '') . (!$r['rolle_ok'] ? '<br><span class="kmm-fail">' . esc_html__('Benutzer fehlt oder hat keinen Zugriff – bitte erneut speichern', 'ksv-km-meldeportal') . '</span>' : '') . '</td>';
			echo '<td>' . ($z['gruppen'] !== [] ? esc_html__('Gruppen:', 'ksv-km-meldeportal') . ' ' . esc_html(implode(', ', $z['gruppen'])) : '') . ($z['gruppen'] !== [] && $z['disziplinen'] !== [] ? '<br>' : '') . ($z['disziplinen'] !== [] ? esc_html__('Disziplinen:', 'ksv-km-meldeportal') . ' ' . esc_html(implode(', ', $z['disziplinen'])) : '') . '</td>';
			echo '<td>' . esc_html($rechte !== [] ? implode(', ', $rechte) : __('nur lesen und PDF-Listen', 'ksv-km-meldeportal')) . '</td>';
			echo '<td>' . esc_html((string) $r['notiz']) . '</td>';
			echo '<td class="kmm-nowrap"><a class="button button-small" href="' . esc_url(self::url(['edit' => (int) $r['id']])) . '">' . esc_html__('Bearbeiten', 'ksv-km-meldeportal') . '</a> ';
			self::form_open('loeschen', 'class="kmm-inline-form" onsubmit="return confirm(\'' . esc_js(__('Referent entfernen? Der Benutzer bleibt bestehen, verliert aber alle Zuständigkeiten und Rechte im KM-Portal.', 'ksv-km-meldeportal')) . '\')"');
			echo '<input type="hidden" name="id" value="' . (int) $r['id'] . '">';
			submit_button(__('Entfernen', 'ksv-km-meldeportal'), 'secondary small', 'submit', false);
			echo '</form></td></tr>';
		}
		if ($liste === []) {
			echo '<tr><td colspan="5">' . esc_html__('Noch keine Referenten.', 'ksv-km-meldeportal') . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><a class="button button-primary" href="' . esc_url(self::url(['neu' => 1])) . '">' . esc_html__('Referent hinzufügen', 'ksv-km-meldeportal') . '</a></p>';

		if ($edit > 0 || $neu) {
			self::render_form($service, $edit);
		}
		echo '</div>';
	}

	private static function render_form(ReferentService $service, int $edit): void {
		$r = $edit > 0 ? (new ReferentRepository())->find($edit) : null;
		if ($edit > 0 && $r === null) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__('Referent nicht gefunden.', 'ksv-km-meldeportal') . '</p></div>';
			return;
		}
		$zust = $r !== null ? (new ReferentZustaendigkeitRepository())->by_referent($edit) : [];
		$gruppen_aktiv = [];
		$disziplinen_aktiv = [];
		foreach ($zust as $e) {
			if ($e['typ'] === ReferentZustaendigkeitRepository::TYP_GRUPPE) {
				$gruppen_aktiv[] = (string) $e['schluessel'];
			} else {
				$disziplinen_aktiv[] = (string) $e['schluessel'];
			}
		}
		$sportjahr = self::current_sportjahr();
		$gruppen = [];
		$disziplinen = [];
		if ($sportjahr !== null) {
			foreach ((new GruppeRepository())->by_sportjahr((int) $sportjahr['id']) as $g) {
				$gruppen[ (string) $g['code'] ] = (string) $g['bezeichnung'];
			}
			foreach (RegelwerkLader::laden((int) $sportjahr['id'])->disziplinen() as $d) {
				$disziplinen[ $d->kennzahl ] = $d->kennzahl . ' ' . $d->bezeichnung;
			}
		}
		foreach ($disziplinen_aktiv as $k) {
			$disziplinen[ $k ] ??= $k;
		}
		foreach ($gruppen_aktiv as $k) {
			$gruppen[ $k ] ??= $k;
		}

		echo '<h2>' . esc_html($r !== null ? __('Referent bearbeiten', 'ksv-km-meldeportal') : __('Referent hinzufügen', 'ksv-km-meldeportal')) . '</h2>';
		self::form_open('speichern');
		echo '<input type="hidden" name="id" value="' . $edit . '">';
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__('Benutzer', 'ksv-km-meldeportal') . '</th><td>';
		if ($r !== null) {
			$user = get_userdata((int) $r['user_id']);
			echo '<input type="hidden" name="user_id" value="' . (int) $r['user_id'] . '"><strong>' . esc_html($user instanceof \WP_User ? $user->display_name . ' (' . $user->user_login . ')' : '#' . (int) $r['user_id']) . '</strong>';
		} else {
			$optionen = ['' => __('– Benutzer wählen –', 'ksv-km-meldeportal')];
			foreach ($service->kandidaten() as $u) {
				$optionen[ (int) $u->ID ] = $u->display_name . ' (' . $u->user_login . ')';
			}
			echo self::select('user_id', $optionen, ''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<p class="description">' . esc_html__('Alle WordPress-Benutzer, die noch nicht eingetragen sind – die Rolle spielt keine Rolle.', 'ksv-km-meldeportal') . '</p>';
		}
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__('Wettbewerbsgruppen', 'ksv-km-meldeportal') . '</th><td>';
		foreach ($gruppen as $code => $bez) {
			echo '<label style="display:inline-block;margin:0 14px 6px 0"><input type="checkbox" name="gruppen[]" value="' . esc_attr($code) . '" ' . checked(in_array($code, $gruppen_aktiv, true), true, false) . '> ' . esc_html($bez) . '</label>';
		}
		echo '<p class="description">' . esc_html__('Eine Gruppe umfasst alle ihre Disziplinen, auch in künftigen Sportjahren (Zuordnung über den Gruppencode).', 'ksv-km-meldeportal') . '</p></td></tr>';
		echo '<tr><th>' . esc_html__('Einzelne Disziplinen', 'ksv-km-meldeportal') . '</th><td><select name="disziplinen[]" multiple size="12" style="min-width:320px">';
		foreach ($disziplinen as $kz => $bez) {
			echo '<option value="' . esc_attr($kz) . '" ' . selected(in_array($kz, $disziplinen_aktiv, true), true, false) . '>' . esc_html($bez) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__('Zusätzlich oder statt Gruppen; Mehrfachauswahl mit Strg/Cmd. Zuordnung über die Kennzahl, gilt sportjahrübergreifend.', 'ksv-km-meldeportal') . '</p></td></tr>';
		echo '<tr><th>' . esc_html__('Rechte', 'ksv-km-meldeportal') . '</th><td>';
		echo self::checkbox('darf_status', $r !== null && $r['darf_status'], __('Verarbeitungsstatus setzen (Startrechtsprüfung, Sammelaktionen)', 'ksv-km-meldeportal')) . '<br>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::checkbox('darf_meldungen', $r !== null && $r['darf_meldungen'], __('Meldungen bearbeiten (Admin-Modus der Vereinsoberfläche: Nachmeldung, Abmeldung, Korrektur)', 'ksv-km-meldeportal')) . '<br>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::checkbox('darf_startplan', $r !== null && $r['darf_startplan'], __('Startplan bearbeiten (Wettkampftage, Durchgänge, Restverteilung)', 'ksv-km-meldeportal')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p class="description">' . esc_html__('Lesen und PDF-Listen im eigenen Bereich sind immer erlaubt.', 'ksv-km-meldeportal') . '</p></td></tr>';
		echo '<tr><th>' . esc_html__('Notiz', 'ksv-km-meldeportal') . '</th><td>' . self::input('notiz', $r['notiz'] ?? '', 'text', 'class="regular-text"') . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</table>';
		submit_button(__('Speichern', 'ksv-km-meldeportal'), 'primary', 'submit', false);
		echo ' <a class="button" href="' . esc_url(self::url()) . '">' . esc_html__('Abbrechen', 'ksv-km-meldeportal') . '</a>';
		echo '</form>';
	}
}
