<?php
/**
 * HTML der öffentlichen Ergebnisseite. Bekommt fertig aufbereitete Daten (siehe
 * ErgebnisFrontend::modell()) und gibt nur aus – keine Datenbank, keine Dateien. So lässt
 * sich die Darstellung auch ohne WordPress-Seite prüfen.
 *
 * Aufbau wie im bisherigen Ergebnis-Plugin: Karte mit grünem Kopf und Jahreswahl,
 * Unterlagen als Kacheln, Sparten-Filter, Tabelle Disziplin/Klasse/SpO/Stand/Einzel/
 * Mannschaft (auf dem Handy als Karten).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Http;

final class ErgebnisAnsicht {

	private const ICONS = [
		'pokal'     => '<path d="M7 4h10v3a5 5 0 0 1-10 0V4Z"/><path d="M7 5H4v1a3 3 0 0 0 3 3M17 5h3v1a3 3 0 0 1-3 3M12 12v4M8 20h8M9 16h6v4H9z"/>',
		'dokument'  => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
		'tabelle'   => '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M4 10h16M4 15h16M10 4v16"/>',
		'kalender'  => '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/>',
		'ziel'      => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="4"/><circle cx="12" cy="12" r="0.5"/>',
		'licht'     => '<path d="M12 3v2M5.6 5.6l1.4 1.4M3 12h2M18.4 5.6 17 7M21 12h-2"/><path d="M8 15a4 4 0 1 1 8 0c0 1.5-1 2.3-1.5 3h-5C9 17.3 8 16.5 8 15ZM10 21h4"/>',
		'person'    => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
		'gruppe'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6.5 6.5 0 0 1 3.5 6"/>',
		'suche'     => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
		'zurueck'   => '<path d="M15 18l-6-6 6-6"/>',
		'post'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
		'extern'    => '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
	];

	public static function icon(string $name, string $klasse = 'kmm-erg-icon'): string {
		return '<svg class="' . esc_attr($klasse) . '" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . (self::ICONS[ $name ] ?? '') . '</svg>';
	}

	/**
	 * Jahresseite.
	 *
	 * @param array{
	 *   jahr: int,
	 *   jahre: array<int, string>,
	 *   sparte: int,
	 *   sparten: array<int, array{label: string, url: string, anzahl: int}>,
	 *   alle_url: string,
	 *   unterlagen: list<array{titel: string, url: string, icon: string, hinweis: string, extern: bool}>,
	 *   gruppen: list<array{sparte: int, sparte_label: string, kennzahl: string, text: string, sammel: list<array{art: string, url: string, format: string}>, klassen: list<array{klasse: string, spo: string, stand: string, e: ?array{url: string, format: string}, m: ?array{url: string, format: string}}>}>,
	 *   anzahl: int
	 * } $m
	 */
	public static function jahr(array $m): string {
		$h = '<div class="kmm-erg-front" data-sparte="' . (int) $m['sparte'] . '">';
		$h .= '<section class="kmm-erg-karte">';
		$h .= self::kopf($m);
		if (isset($m['portal'])) {
			$h .= self::portal($m['portal']);
		}
		$h .= self::unterlagen($m['unterlagen'], (int) $m['jahr']);
		if ($m['gruppen'] === []) {
			$h .= '<p class="kmm-erg-leer">' . esc_html__('Für dieses Jahr sind noch keine Ergebnisse veröffentlicht.', 'ksv-km-meldeportal') . '</p>';
		} else {
			$h .= self::werkzeug($m);
			$h .= self::tabelle($m['gruppen'], (int) $m['sparte']);
		}
		$h .= '</section>';
		$h .= self::skript();
		return $h . '</div>';
	}

	/**
	 * @param array<string, mixed> $m
	 */
	private static function kopf(array $m): string {
		$h = '<header class="kmm-erg-kopf">';
		$h .= '<div class="kmm-erg-kopf-titel">' . self::icon('pokal', 'kmm-erg-icon kmm-erg-icon-gross');
		$h .= '<div><h2>' . esc_html__('Kreismeisterschaften', 'ksv-km-meldeportal') . '</h2>';
		$h .= '<p>' . esc_html__('Ergebnisse, Ausschreibungen und Startpläne', 'ksv-km-meldeportal') . '</p></div></div>';
		if (count($m['jahre']) > 0) {
			$h .= '<label class="kmm-erg-jahrwahl"><span>' . esc_html__('Sportjahr', 'ksv-km-meldeportal') . '</span>';
			$h .= '<select onchange="if(this.value){location.href=this.value;}" aria-label="' . esc_attr__('Sportjahr wählen', 'ksv-km-meldeportal') . '">';
			foreach ($m['jahre'] as $j => $url) {
				$h .= '<option value="' . esc_url($url) . '"' . ((int) $j === (int) $m['jahr'] ? ' selected' : '') . '>' . (int) $j . '</option>';
			}
			$h .= '</select></label>';
		}
		return $h . '</header>';
	}

	/**
	 * Hinweis aufs Meldeportal. Angemeldet wird per E-Mail-Link, deshalb führt der Knopf
	 * auf die Startseite des Portals mit „Link zusenden“.
	 *
	 * @param array{url: string, meldeschluss: string, bezeichnung: string} $p
	 */
	private static function portal(array $p): string {
		$h = '<aside class="kmm-erg-portal">';
		$h .= '<span class="kmm-erg-portal-icon">' . self::icon('post') . '</span>';
		$h .= '<div class="kmm-erg-portal-text"><strong>' . esc_html__('Meldeportal für Vereine', 'ksv-km-meldeportal') . '</strong>';
		if ($p['meldeschluss'] !== '') {
			$text = $p['bezeichnung'] !== ''
				? sprintf(__('Meldungen für %1$s sind bis %2$s möglich. Den Zugang gibt es per E-Mail-Link.', 'ksv-km-meldeportal'), $p['bezeichnung'], $p['meldeschluss'])
				: sprintf(__('Meldungen sind bis %s möglich. Den Zugang gibt es per E-Mail-Link.', 'ksv-km-meldeportal'), $p['meldeschluss']);
		} else {
			$text = __('Vereine melden ihre Schützen online. Den Zugang gibt es per E-Mail-Link, ein Passwort ist nicht nötig.', 'ksv-km-meldeportal');
		}
		$h .= '<span>' . esc_html($text) . '</span></div>';
		$h .= '<a class="kmm-erg-btn is-primaer" href="' . esc_url($p['url']) . '">' . esc_html__('Zugangslink anfordern', 'ksv-km-meldeportal') . '</a>';
		return $h . '</aside>';
	}

	/**
	 * @param list<array{titel: string, url: string, icon: string, hinweis: string, extern: bool}> $liste
	 */
	private static function unterlagen(array $liste, int $jahr): string {
		if ($liste === []) {
			return '';
		}
		$h = '<div class="kmm-erg-unterlagen"><h3>' . esc_html(sprintf(__('Unterlagen %d', 'ksv-km-meldeportal'), $jahr)) . '</h3><ul class="kmm-erg-kacheln">';
		foreach ($liste as $u) {
			$h .= '<li><a class="kmm-erg-kachel" href="' . esc_url($u['url']) . '"' . ($u['extern'] ? ' target="_blank" rel="noopener"' : '') . '>';
			$h .= '<span class="kmm-erg-kachel-icon">' . self::icon($u['icon']) . '</span>';
			$h .= '<span class="kmm-erg-kachel-text"><strong>' . esc_html($u['titel']) . '</strong>';
			if ($u['hinweis'] !== '') {
				$h .= '<small>' . esc_html($u['hinweis']) . '</small>';
			}
			$h .= '</span></a></li>';
		}
		return $h . '</ul></div>';
	}

	/**
	 * @param array<string, mixed> $m
	 */
	private static function werkzeug(array $m): string {
		$h = '<div class="kmm-erg-werkzeug">';
		$h .= '<div class="kmm-erg-sparten" role="group" aria-label="' . esc_attr__('Nach Sparte filtern', 'ksv-km-meldeportal') . '">';
		$h .= '<a href="' . esc_url($m['alle_url']) . '" class="kmm-erg-pille kmm-erg-sparte-0" data-sparte="0" aria-pressed="' . ($m['sparte'] === 0 ? 'true' : 'false') . '">' . esc_html__('Alle', 'ksv-km-meldeportal') . ' <span>' . (int) $m['anzahl'] . '</span></a>';
		foreach ($m['sparten'] as $nr => $s) {
			$h .= '<a href="' . esc_url($s['url']) . '" class="kmm-erg-pille kmm-erg-sparte-' . (int) $nr . '" data-sparte="' . (int) $nr . '" aria-pressed="' . ($m['sparte'] === $nr ? 'true' : 'false') . '">' . esc_html($s['label']) . ' <span>' . (int) $s['anzahl'] . '</span></a>';
		}
		$h .= '</div>';
		$h .= '<label class="kmm-erg-suche">' . self::icon('suche') . '<input type="search" placeholder="' . esc_attr__('Disziplin, Klasse oder SpO-Nummer', 'ksv-km-meldeportal') . '" aria-label="' . esc_attr__('Ergebnisse durchsuchen', 'ksv-km-meldeportal') . '"></label>';
		return $h . '</div>';
	}

	/**
	 * @param list<array<string, mixed>> $gruppen
	 */
	private static function tabelle(array $gruppen, int $sparte): string {
		$h = '<table class="kmm-erg-tabelle"><thead><tr>';
		foreach ([__('Klasse', 'ksv-km-meldeportal'), __('SpO', 'ksv-km-meldeportal'), __('Stand', 'ksv-km-meldeportal'), __('Einzel', 'ksv-km-meldeportal'), __('Mannschaft', 'ksv-km-meldeportal')] as $th) {
			$h .= '<th scope="col">' . esc_html($th) . '</th>';
		}
		$h .= '</tr></thead>';
		foreach ($gruppen as $g) {
			$versteckt = $sparte > 0 && $g['sparte'] !== $sparte;
			$suche = mb_strtolower($g['kennzahl'] . ' ' . $g['text']);
			$h .= '<tbody class="kmm-erg-gruppe" data-sparte="' . (int) $g['sparte'] . '" data-suche="' . esc_attr($suche) . '"' . ($versteckt ? ' hidden' : '') . '>';
			$h .= '<tr class="kmm-erg-gruppenkopf"><th colspan="5" scope="rowgroup"><div class="kmm-erg-gruppenkopf-inhalt">';
			$h .= '<span class="kmm-erg-kz">' . esc_html($g['kennzahl']) . '</span><span class="kmm-erg-disziplin">' . esc_html($g['text']) . '</span>';
			$h .= '<span class="kmm-erg-badge kmm-erg-sparte-' . (int) $g['sparte'] . '">' . esc_html($g['sparte_label']) . '</span>';
			foreach ($g['sammel'] as $s) {
				$h .= '<a class="kmm-erg-btn is-gesamt" href="' . esc_url($s['url']) . '"' . self::ziel($s['format']) . '>' . self::icon('tabelle') . esc_html($s['art'] === 'm' ? __('Gesamtergebnis Mannschaft', 'ksv-km-meldeportal') : __('Gesamtergebnis', 'ksv-km-meldeportal')) . '</a>';
			}
			$h .= '</div></th></tr>';
			foreach ($g['klassen'] as $k) {
				$h .= '<tr class="kmm-erg-zeile" data-suche="' . esc_attr(mb_strtolower($k['klasse'] . ' ' . $k['spo'])) . '">';
				$h .= '<td class="kmm-erg-klasse" data-label="' . esc_attr__('Klasse', 'ksv-km-meldeportal') . '">' . esc_html($k['klasse']) . '</td>';
				$h .= '<td class="kmm-erg-spo" data-label="' . esc_attr__('SpO', 'ksv-km-meldeportal') . '">' . esc_html($k['spo']) . '</td>';
				$h .= '<td class="kmm-erg-stand" data-label="' . esc_attr__('Stand', 'ksv-km-meldeportal') . '">' . esc_html($k['stand'] !== '' ? $k['stand'] : '–') . '</td>';
				$h .= '<td class="kmm-erg-aktion">' . self::knopf($k['e'], 'e') . '</td>';
				$h .= '<td class="kmm-erg-aktion">' . self::knopf($k['m'], 'm') . '</td>';
				$h .= '</tr>';
			}
			$h .= '</tbody>';
		}
		$h .= '</table>';
		$h .= '<p class="kmm-erg-leer" data-leer hidden>' . esc_html__('Keine passenden Ergebnisse.', 'ksv-km-meldeportal') . '</p>';
		return $h;
	}

	/**
	 * @param array{url: string, format: string}|null $e
	 */
	private static function knopf(?array $e, string $art): string {
		$label = $art === 'm' ? __('Mannschaft', 'ksv-km-meldeportal') : __('Einzel', 'ksv-km-meldeportal');
		if ($e === null) {
			return '<span class="kmm-erg-fehlt" aria-label="' . esc_attr(sprintf(__('%s: kein Ergebnis', 'ksv-km-meldeportal'), $label)) . '">–</span>';
		}
		$h = '<a class="kmm-erg-btn" href="' . esc_url($e['url']) . '"' . self::ziel($e['format']) . '>' . self::icon($art === 'm' ? 'gruppe' : 'person') . esc_html($label);
		if ($e['format'] === 'pdf') {
			$h .= '<span class="kmm-erg-format">PDF</span>';
		}
		return $h . '</a>';
	}

	private static function ziel(string $format): string {
		return $format === 'pdf' ? ' target="_blank" rel="noopener"' : '';
	}

	/**
	 * Einzelnes HTML-Ergebnis im abgeschotteten iframe.
	 */
	public static function einzeln(string $titel, string $zurueck_url, string $zurueck_text, string $roh_url): string {
		$h = '<div class="kmm-erg-front"><section class="kmm-erg-karte">';
		$h .= '<header class="kmm-erg-kopf"><div class="kmm-erg-kopf-titel">' . self::icon('pokal', 'kmm-erg-icon kmm-erg-icon-gross') . '<div><h2>' . esc_html($titel) . '</h2></div></div>';
		$h .= '<a class="kmm-erg-zurueck" href="' . esc_url($zurueck_url) . '">' . self::icon('zurueck') . esc_html($zurueck_text) . '</a></header>';
		$h .= '<div class="kmm-erg-rahmen"><iframe class="kmm-erg-iframe" src="' . esc_url($roh_url) . '" title="' . esc_attr($titel) . '" loading="lazy" sandbox referrerpolicy="no-referrer"></iframe></div>';
		return $h . '</section></div>';
	}

	/** Filter und Suche ohne Neuladen; die Links funktionieren auch ohne JavaScript. */
	private static function skript(): string {
		return '<script>(function(){var w=document.currentScript.parentNode;if(!w){return;}
var pillen=w.querySelectorAll(".kmm-erg-pille"),feld=w.querySelector(".kmm-erg-suche input"),leer=w.querySelector("[data-leer]"),sparte=parseInt(w.dataset.sparte||"0",10);
function anwenden(){var q=(feld&&feld.value||"").toLowerCase().trim(),n=0;
w.querySelectorAll(".kmm-erg-gruppe").forEach(function(g){var an=sparte===0||parseInt(g.dataset.sparte,10)===sparte,kopf=!q||g.dataset.suche.indexOf(q)!==-1,z=0;
g.querySelectorAll(".kmm-erg-zeile").forEach(function(r){var t=an&&(kopf||r.dataset.suche.indexOf(q)!==-1);r.hidden=!t;if(t){z++;}});
var sichtbar=an&&(z>0||(kopf&&g.querySelector(".is-gesamt")));g.hidden=!sichtbar;if(sichtbar){n++;}});
if(leer){leer.hidden=n>0;}}
pillen.forEach(function(p){p.addEventListener("click",function(e){e.preventDefault();sparte=parseInt(p.dataset.sparte,10);pillen.forEach(function(x){x.setAttribute("aria-pressed",x===p?"true":"false");});try{history.replaceState(null,"",p.href);}catch(err){}anwenden();});});
if(feld){feld.addEventListener("input",anwenden);}
})();</script>';
	}
}
