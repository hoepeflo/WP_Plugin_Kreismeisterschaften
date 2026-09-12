<?php
/**
 * Backend: Änderungsprotokoll (wer, wann, was) mit Filtern.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Admin;

use KSV\KMM\Auth\Capabilities;
use KSV\KMM\Infrastructure\Repository\ProtokollRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;
use KSV\KMM\Support\Clock;

final class ProtokollPage extends AdminPage {

	public const SLUG = 'kmm-protokoll';

	private const PRO_SEITE = 100;

	public static function render(): void {
		if (!current_user_can(Capabilities::VIEW)) {
			wp_die(esc_html__('Keine Berechtigung.', 'ksv-km-meldeportal'));
		}
		$sportjahr_id = isset($_GET['sportjahr']) ? (int) $_GET['sportjahr'] : 0;
		$verein_id = isset($_GET['verein']) ? (int) $_GET['verein'] : 0;
		$akteur = isset($_GET['akteur']) ? sanitize_key((string) $_GET['akteur']) : '';
		$suche = isset($_GET['s']) ? sanitize_text_field((string) wp_unslash($_GET['s'])) : '';
		$seite = max(1, isset($_GET['seite']) ? (int) $_GET['seite'] : 1);

		echo '<div class="wrap kmm-admin">';
		Menu::page_header(__('Änderungsprotokoll', 'ksv-km-meldeportal'));

		$sportjahre = [0 => __('alle Sportjahre', 'ksv-km-meldeportal')];
		foreach ((new SportjahrRepository())->all() as $s) {
			$sportjahre[ (int) $s['id'] ] = (string) $s['jahr'];
		}
		$vereine = [0 => __('alle Vereine', 'ksv-km-meldeportal')];
		$vereins_namen = [];
		foreach ((new VereinRepository())->all() as $v) {
			$vereine[ (int) $v['id'] ] = (string) $v['name'];
			$vereins_namen[ (int) $v['id'] ] = (string) $v['name'];
		}
		echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="kmm-filter"><input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '">';
		echo self::select('sportjahr', $sportjahre, $sportjahr_id) . ' ' . self::select('verein', $vereine, $verein_id) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::select('akteur', ['' => __('alle Akteure', 'ksv-km-meldeportal'), 'admin' => 'Admin', 'verein' => 'Verein', 'system' => 'System'], $akteur) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::input('s', $suche, 'search', 'placeholder="' . esc_attr__('Aktion oder Text', 'ksv-km-meldeportal') . '"') . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		submit_button(__('Filtern', 'ksv-km-meldeportal'), 'secondary', '', false);
		echo '</form>';

		$filter = [];
		if ($sportjahr_id > 0) {
			$filter['sportjahr_id'] = $sportjahr_id;
		}
		if ($verein_id > 0) {
			$filter['verein_id'] = $verein_id;
		}
		if ($akteur !== '') {
			$filter['akteur_typ'] = $akteur;
		}
		$alle = (new ProtokollRepository())->neueste($filter, 5000);
		if ($suche !== '') {
			$needle = mb_strtolower($suche);
			$alle = array_values(array_filter($alle, static fn(array $r): bool => str_contains(mb_strtolower($r['aktion'] . ' ' . $r['zusammenfassung'] . ' ' . $r['akteur_name']), $needle)));
		}
		$gesamt = count($alle);
		$seiten = max(1, (int) ceil($gesamt / self::PRO_SEITE));
		$seite = min($seite, $seiten);
		$rows = array_slice($alle, ($seite - 1) * self::PRO_SEITE, self::PRO_SEITE);

		echo '<p class="description">' . esc_html(sprintf(__('%d Einträge, Seite %d von %d', 'ksv-km-meldeportal'), $gesamt, $seite, $seiten)) . '</p>';
		echo '<table class="widefat striped kmm-protokoll"><thead><tr><th>' . esc_html__('Zeitpunkt', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Wer', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Verein', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Aktion', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Was', 'ksv-km-meldeportal') . '</th></tr></thead><tbody>';
		foreach ($rows as $r) {
			echo '<tr><td>' . esc_html(Clock::format_local($r['created_at'], 'd.m.Y H:i:s')) . '</td>';
			echo '<td><span class="kmm-badge kmm-akteur-' . esc_attr((string) $r['akteur_typ']) . '">' . esc_html((string) $r['akteur_typ']) . '</span> ' . esc_html((string) $r['akteur_name']) . '</td>';
			echo '<td>' . esc_html($r['verein_id'] !== null ? ($vereins_namen[ (int) $r['verein_id'] ] ?? '#' . (int) $r['verein_id']) : '') . '</td>';
			echo '<td><code>' . esc_html((string) $r['aktion']) . '</code></td>';
			echo '<td>' . esc_html((string) $r['zusammenfassung']);
			if ($r['details'] !== null && $r['details'] !== '') {
				echo '<details><summary>' . esc_html__('Details', 'ksv-km-meldeportal') . '</summary><pre>' . esc_html((string) wp_json_encode(json_decode((string) $r['details'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre></details>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		if ($seiten > 1) {
			echo '<p>';
			for ($i = 1; $i <= $seiten; $i++) {
				$args = array_filter(['sportjahr' => $sportjahr_id, 'verein' => $verein_id, 'akteur' => $akteur, 's' => $suche, 'seite' => $i]);
				echo $i === $seite ? '<strong>' . $i . '</strong> ' : '<a href="' . esc_url(self::url($args)) . '">' . $i . '</a> ';
			}
			echo '</p>';
		}
		echo '</div>';
	}
}
