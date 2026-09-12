<?php
/**
 * Shortcode [kmm_startplan] – veröffentlichter Startplan in einem beliebigen Beitrag
 * (Konzept 12.6). Unabhängig vom Kalender-Plugin; vor der Veröffentlichung erscheint
 * nichts bzw. ein kurzer Hinweis.
 *
 * Attribute:
 *   tag       ID des Wettkampftags; leer = alle veröffentlichten Tage des Sportjahres
 *   disziplin Kennzahl als Filter, z. B. „1.10“
 *   sportjahr ID; leer = alle Sportjahre (bei gesetztem tag ohne Bedeutung)
 *   titel     „nein“ blendet die Überschrift aus
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Http;

use KSV\KMM\Application\StartplanAnsicht;

final class Shortcode {

	public const TAG = 'kmm_startplan';

	public static function register(): void {
		add_shortcode(self::TAG, [self::class, 'startplan']);
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public static function startplan($atts = []): string {
		$a = shortcode_atts(['tag' => '', 'disziplin' => '', 'sportjahr' => '', 'titel' => 'ja'], is_array($atts) ? $atts : [], self::TAG);
		$disziplin = (string) $a['disziplin'];
		$mit_titel = strtolower((string) $a['titel']) !== 'nein';

		$plaene = [];
		$leer = false;
		if ((int) $a['tag'] > 0) {
			$plan = StartplanAnsicht::plan((int) $a['tag'], $disziplin);
			if ($plan !== null && $plan['durchgaenge'] !== []) {
				$plaene[] = $plan;
			}
			$leer = $plan !== null;
		} else {
			foreach (StartplanAnsicht::tage((int) $a['sportjahr'] > 0 ? (int) $a['sportjahr'] : null) as $tag) {
				$plan = StartplanAnsicht::plan((int) $tag['id'], $disziplin);
				if ($plan !== null && $plan['durchgaenge'] !== []) {
					$plaene[] = $plan;
				}
			}
		}
		if ($plaene === []) {
			$text = $leer
				? __('Für diese Auswahl ist noch kein Starter eingeteilt.', 'ksv-km-meldeportal')
				: __('Der Startplan ist noch nicht veröffentlicht.', 'ksv-km-meldeportal');
			return '<div class="kmm-startplan kmm-startplan-leer"><p>' . esc_html($text) . '</p></div>';
		}
		self::styles();
		$html = '<div class="kmm-startplan">';
		foreach ($plaene as $plan) {
			$html .= self::ein_tag($plan, $mit_titel, $disziplin);
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	private static function ein_tag(array $plan, bool $mit_titel, string $disziplin): string {
		$tag = $plan['tag'];
		$html = '';
		if ($mit_titel) {
			$html .= '<h2 class="kmm-startplan-titel">' . esc_html((string) $tag['bezeichnung']) . '</h2>';
		}
		$kopf = [$plan['datum']];
		if ((string) $tag['ort'] !== '') {
			$kopf[] = (string) $tag['ort'];
		}
		if ($disziplin !== '') {
			$kopf[] = sprintf(__('nur %s', 'ksv-km-meldeportal'), $disziplin);
		}
		$html .= '<p class="kmm-startplan-kopf">' . esc_html(implode(' · ', $kopf)) . '</p>';
		if ((string) $tag['hinweis'] !== '') {
			$html .= '<p class="kmm-startplan-hinweis">' . esc_html((string) $tag['hinweis']) . '</p>';
		}
		foreach ($plan['durchgaenge'] as $dg) {
			$html .= '<h3 class="kmm-startplan-durchgang">' . esc_html(sprintf(__('Durchgang %1$d · %2$s–%3$s Uhr', 'ksv-km-meldeportal'), $dg['nummer'], $dg['beginn'], $dg['ende']) . ($dg['bezeichnung'] !== '' ? ' · ' . $dg['bezeichnung'] : '')) . '</h3>';
			$html .= '<div class="kmm-startplan-tabelle"><table><thead><tr>';
			$html .= '<th>' . esc_html__('Stand', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Name', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Verein', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Klasse', 'ksv-km-meldeportal') . '</th>';
			if ($disziplin === '') {
				$html .= '<th>' . esc_html__('Disziplin', 'ksv-km-meldeportal') . '</th>';
			}
			$html .= '</tr></thead><tbody>';
			foreach ($dg['zeilen'] as $z) {
				$html .= '<tr>';
				$html .= '<td data-label="' . esc_attr__('Stand', 'ksv-km-meldeportal') . '">' . esc_html($z['einheit'] . ($z['mehrfach'] ? ' / ' . $z['position'] : '')) . '</td>';
				$html .= '<td data-label="' . esc_attr__('Name', 'ksv-km-meldeportal') . '">' . esc_html(trim($z['name'] . ', ' . $z['vorname'], ', ')) . '</td>';
				$html .= '<td data-label="' . esc_attr__('Verein', 'ksv-km-meldeportal') . '">' . esc_html($z['verein']) . '</td>';
				$html .= '<td data-label="' . esc_attr__('Klasse', 'ksv-km-meldeportal') . '">' . esc_html($z['startklasse']) . '</td>';
				if ($disziplin === '') {
					$html .= '<td data-label="' . esc_attr__('Disziplin', 'ksv-km-meldeportal') . '">' . esc_html(trim($z['kennzahl'] . ' ' . $z['disziplin'])) . '</td>';
				}
				$html .= '</tr>';
			}
			$html .= '</tbody></table></div>';
		}
		return $html;
	}

	/** Eigenes, sehr kleines Stylesheet – der Startplan steht in einem fremden Theme. */
	private static function styles(): void {
		static $gesetzt = false;
		if ($gesetzt) {
			return;
		}
		$gesetzt = true;
		$css = '.kmm-startplan{margin:1.5em 0}'
			. '.kmm-startplan-kopf{font-weight:600;margin:.2em 0 .8em}'
			. '.kmm-startplan-hinweis{padding:.6em .8em;border-left:3px solid currentColor;opacity:.85;margin:0 0 1em}'
			. '.kmm-startplan-durchgang{margin:1.4em 0 .4em;font-size:1.05em}'
			. '.kmm-startplan-tabelle{overflow-x:auto}'
			. '.kmm-startplan table{width:100%;border-collapse:collapse;font-size:.95em}'
			. '.kmm-startplan th,.kmm-startplan td{text-align:left;padding:.35em .6em;border-bottom:1px solid rgba(128,128,128,.3)}'
			. '.kmm-startplan th{font-size:.85em;text-transform:uppercase;letter-spacing:.03em;opacity:.75}'
			. '.kmm-startplan td:first-child{white-space:nowrap}'
			. '@media (max-width:600px){.kmm-startplan thead{display:none}'
			. '.kmm-startplan tr{display:block;margin-bottom:.8em;border:1px solid rgba(128,128,128,.3);border-radius:6px;padding:.3em .5em}'
			. '.kmm-startplan td{display:flex;justify-content:space-between;gap:1em;border:0;padding:.2em 0}'
			. '.kmm-startplan td::before{content:attr(data-label);font-size:.85em;opacity:.7}}';
		wp_register_style('kmm-startplan', false, [], KMM_VERSION);
		wp_enqueue_style('kmm-startplan');
		wp_add_inline_style('kmm-startplan', $css);
	}
}
