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

	/** Steht unter jedem Startplan (PDF und Web). */
	public const HINWEIS = 'Startplätze können untereinander getauscht werden. Ein Hinweis am Wettkampftag an das Personal vor Ort genügt.';

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
		self::script();
		$html = '<div class="kmm-startplan">';
		foreach ($plaene as $plan) {
			$html .= self::ein_tag($plan, $mit_titel, $disziplin);
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Ein Wettkampftag als Raster: Zeilen = Durchgänge mit Uhrzeit, Spalten = Stände –
	 * so, wie die Startpläne auf Papier seit jeher aussehen.
	 *
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
		$kopf[] = sprintf(__('%d Starter', 'ksv-km-meldeportal'), (int) $plan['starter']);
		// Was an diesem Tag nur einmal vorkommt, steht in der Kopfzeile statt in jeder Zelle.
		$mit_kennzahl = count($plan['disziplinen']) > 1;
		$mit_klasse = count($plan['klassen']) > 1;
		if (!$mit_kennzahl && $plan['disziplinen'] !== []) {
			$kopf[] = (string) $plan['disziplinen'][0];
		}
		if (!$mit_klasse && $plan['klassen'] !== []) {
			$kopf[] = (string) $plan['klassen'][0];
		}
		$html .= '<p class="kmm-startplan-kopf">' . esc_html(implode(' · ', $kopf)) . '</p>';
		if ($mit_kennzahl) {
			// Damit die Kennzahlen in den Feldern lesbar bleiben.
			$html .= '<p class="kmm-startplan-disziplinen">' . esc_html(__('Disziplinen:', 'ksv-km-meldeportal') . ' ' . implode(' · ', $plan['disziplinen'])) . '</p>';
		}
		if ((string) $tag['hinweis'] !== '') {
			$html .= '<p class="kmm-startplan-hinweis">' . esc_html((string) $tag['hinweis']) . '</p>';
		}

		$spalten = $plan['spalten'];
		// Ab zehn Spalten wird es eng: kompaktere Schrift, damit möglichst alles sichtbar bleibt.
		$eng = count($spalten) >= 10 ? ' is-eng' : '';
		$html .= '<div class="kmm-startplan-tabelle"><table class="kmm-startplan-raster' . $eng . '"><thead><tr><th class="kmm-sp-zeit">' . esc_html__('Zeit', 'ksv-km-meldeportal') . '</th>';
		foreach ($spalten as $sp) {
			$html .= '<th>' . esc_html(StartplanAnsicht::spalte_label($sp)) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ($plan['durchgaenge'] as $dg) {
			$html .= '<tr><th class="kmm-sp-zeit" scope="row">' . esc_html($dg['beginn']) . '<small>' . esc_html(sprintf(__('bis %s', 'ksv-km-meldeportal'), $dg['ende'])) . '</small>';
			$html .= '<small>' . esc_html(sprintf(__('Durchgang %d', 'ksv-km-meldeportal'), (int) $dg['nummer']) . ($dg['bezeichnung'] !== '' ? ' · ' . $dg['bezeichnung'] : '')) . '</small>';
			$html .= '</th>';
			foreach ($spalten as $sp) {
				$z = $dg['zellen'][ $sp['key'] ] ?? null;
				$label = StartplanAnsicht::spalte_label($sp);
				if ($z === null) {
					$html .= '<td class="kmm-sp-leer" data-label="' . esc_attr($label) . '"></td>';
					continue;
				}
				$html .= '<td data-label="' . esc_attr($label) . '">';
				$html .= '<span class="kmm-sp-verein">' . esc_html($z['verein']) . '</span>';
				$html .= '<span class="kmm-sp-name">' . esc_html(trim($z['name'] . ', ' . $z['vorname'], ', ')) . '</span>';
				$zusatz = array_filter([$mit_kennzahl ? $z['kennzahl'] : '', $mit_klasse ? $z['startklasse'] : '']);
				if ($zusatz !== []) {
					$html .= '<span class="kmm-sp-klasse">' . esc_html(implode(' · ', $zusatz)) . '</span>';
				}
				$html .= '</td>';
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table><p class="kmm-startplan-scrollhinweis" hidden>' . esc_html__('Die Tabelle lässt sich seitlich verschieben.', 'ksv-km-meldeportal') . '</p></div>';

		$html .= '<p class="kmm-startplan-fuss">' . esc_html(self::HINWEIS) . '</p>';
		return $html;
	}

	/**
	 * Zeigt den Hinweis auf das seitliche Verschieben nur, wenn die Tabelle breiter ist
	 * als der Platz im Theme. Ohne JavaScript bleibt der Hinweis einfach verborgen.
	 */
	private static function script(): void {
		static $gesetzt = false;
		if ($gesetzt) {
			return;
		}
		$gesetzt = true;
		$js = '(function(){function p(){document.querySelectorAll(".kmm-startplan-tabelle").forEach(function(d){'
			. 'var h=d.querySelector(".kmm-startplan-scrollhinweis");if(h){h.hidden=d.scrollWidth<=d.clientWidth+1;}});}'
			. 'if(document.readyState!=="loading"){p();}else{document.addEventListener("DOMContentLoaded",p);}'
			. 'window.addEventListener("resize",p);})();';
		wp_register_script('kmm-startplan', false, [], KMM_VERSION, true);
		wp_enqueue_script('kmm-startplan');
		wp_add_inline_script('kmm-startplan', $js);
	}

	/** Eigenes, kleines Stylesheet – der Startplan steht in einem fremden Theme. */
	private static function styles(): void {
		static $gesetzt = false;
		if ($gesetzt) {
			return;
		}
		$gesetzt = true;
		$css = '.kmm-startplan{margin:1.5em 0;--kmm-sp-linie:rgba(128,128,128,.35)}'
			. '.kmm-startplan-kopf{font-weight:600;margin:.2em 0 .3em}'
			. '.kmm-startplan-disziplinen{margin:0 0 .8em;font-size:.9em;opacity:.8}'
			. '.kmm-startplan-hinweis{padding:.6em .8em;border-left:3px solid currentColor;opacity:.85;margin:0 0 1em}'
			// Der Startplan ist breit; in schmalen Themes wird waagerecht gescrollt.
			. '.kmm-startplan-tabelle{overflow-x:auto;max-width:100%;scrollbar-width:thin}'
			. '.kmm-startplan-raster{width:100%;border-collapse:collapse;font-size:13px;line-height:1.25}'
			. '.kmm-startplan-raster th,.kmm-startplan-raster td{border:1px solid var(--kmm-sp-linie);padding:.35em .45em;text-align:center;vertical-align:middle}'
			. '.kmm-startplan-raster thead th{font-size:11px;text-transform:uppercase;letter-spacing:.03em;opacity:.8;white-space:nowrap}'
			. '.kmm-startplan-raster th.kmm-sp-zeit{font-weight:700;font-size:15px;white-space:nowrap;width:1%}'
			. '.kmm-startplan-raster tbody th.kmm-sp-zeit small{display:block;font-weight:400;opacity:.7;font-size:11px}'
			. '.kmm-startplan-raster td span{display:block}'
			. '.kmm-sp-verein{font-size:11px;opacity:.75}'
			. '.kmm-sp-name{font-weight:600;font-size:14px}'
			. '.kmm-sp-klasse{font-size:11px;opacity:.7}'
			. '.kmm-startplan-raster.is-eng{font-size:11px}'
			. '.kmm-startplan-raster.is-eng th,.kmm-startplan-raster.is-eng td{padding:.3em .25em}'
			. '.kmm-startplan-raster.is-eng .kmm-sp-name{font-size:12px}'
			. '.kmm-startplan-raster.is-eng .kmm-sp-verein,.kmm-startplan-raster.is-eng .kmm-sp-klasse{font-size:10px}'
			. '.kmm-startplan-scrollhinweis{margin:.4em 0 0;font-size:12px;opacity:.7}'
			. '.kmm-startplan-fuss{margin:.8em 0 0;font-size:.9em;opacity:.8}'
			// Unter 700 px wird aus jeder Rasterzeile eine Karte: Durchgang als Überschrift,
			// darunter Stand für Stand ein Eintrag. Leere Plätze entfallen.
			. '@media (max-width:700px){'
			. '.kmm-startplan-raster,.kmm-startplan-raster tbody,.kmm-startplan-raster tr,.kmm-startplan-raster td,.kmm-startplan-raster th{display:block}'
			. '.kmm-startplan-raster thead{display:none}'
			. '.kmm-startplan-raster tr{margin-bottom:1em;border:1px solid var(--kmm-sp-linie);border-radius:6px;overflow:hidden}'
			. '.kmm-startplan-raster th,.kmm-startplan-raster td{border:0;border-top:1px solid var(--kmm-sp-linie);text-align:left}'
			. '.kmm-startplan-raster tbody th.kmm-sp-zeit{width:auto;border-top:0;font-size:16px;background:rgba(128,128,128,.12)}'
			. '.kmm-startplan-raster tbody th.kmm-sp-zeit small{display:inline;margin-left:.3em}'
			. '.kmm-startplan-raster tbody th.kmm-sp-zeit small::before{content:"– "}'
			. '.kmm-startplan-raster td.kmm-sp-leer{display:none}'
			. '.kmm-startplan-raster td::before{content:attr(data-label);display:block;font-size:11px;opacity:.7}'
			. '}';
		wp_register_style('kmm-startplan', false, [], KMM_VERSION);
		wp_enqueue_style('kmm-startplan');
		wp_add_inline_style('kmm-startplan', $css);
	}
}
