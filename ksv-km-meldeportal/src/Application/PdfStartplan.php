<?php
/**
 * PDF-Startplan für Aushang und Standaufsicht (Konzept 12.6).
 *
 * Querformat als Raster, wie die Startpläne auf Papier seit jeher aussehen: Zeilen sind
 * die Durchgänge mit ihrer Uhrzeit, Spalten die Stände, in der Zelle Verein und Name.
 * Die Startklasse steht immer am Startplatz; die Disziplin nur, wenn es an diesem Tag
 * mehrere gibt – sonst reicht die Kopfzeile.
 *
 * Ein noch nicht veröffentlichter Tag lässt sich im Backend als Vorschau drucken; das
 * PDF trägt dann einen deutlichen Entwurfsvermerk.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Http\Shortcode;
use KSV\KMM\Support\Clock;

final class PdfStartplan {

	/**
	 * @return array{dateiname: string, inhalt: string, starter: int}
	 */
	public function erzeugen(int $tag_id, string $disziplin = ''): array {
		$plan = StartplanAnsicht::plan($tag_id, $disziplin, true);
		if ($plan === null) {
			throw new \RuntimeException('Wettkampftag nicht gefunden.');
		}
		if ($plan['durchgaenge'] === []) {
			throw new \RuntimeException('Für diesen Wettkampftag gibt es noch keine Buchungen.');
		}
		$tag = $plan['tag'];
		$name = sanitize_file_name(sprintf('startplan-%s-%s%s.pdf', (string) $tag['datum'], sanitize_title((string) $tag['bezeichnung']), $disziplin !== '' ? '-' . sanitize_title($disziplin) : ''));
		$inhalt = Pdf::aus_html($this->html($plan, $disziplin), 'A4-L', ['left' => 10, 'right' => 10, 'top' => 12, 'bottom' => 12]);
		return ['dateiname' => $name, 'inhalt' => $inhalt, 'starter' => (int) $plan['starter']];
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	public function html(array $plan, string $disziplin = ''): string {
		$tag = $plan['tag'];
		$entwurf = !StartplanAnsicht::oeffentlich($tag);
		$spalten = $plan['spalten'];
		$mit_kennzahl = count($plan['disziplinen']) > 1;
		// Spaltenbreite: Zeitspalte fest, der Rest zu gleichen Teilen.
		$breite = $spalten !== [] ? round(86 / count($spalten), 2) : 86;
		$h = static fn(string $s): string => esc_html($s);

		// Ab zehn Spalten wird es eng: Schrift eine Stufe kleiner, damit Namen nicht umbrechen.
		$html = '<html><head><meta charset="utf-8"><style>' . $this->css(count($spalten) >= 10) . '</style></head><body>';
		$html .= '<h1>' . $h((string) $tag['bezeichnung']) . '</h1>';
		$kopf = [(string) $plan['datum']];
		if ((string) $tag['ort'] !== '') {
			$kopf[] = (string) $tag['ort'];
		}
		$kopf[] = sprintf('%d Starter', (int) $plan['starter']);
		// Die Startklasse steht immer am Startplatz; die Disziplin nur, wenn es mehrere gibt.
		if (!$mit_kennzahl && $plan['disziplinen'] !== []) {
			$kopf[] = (string) $plan['disziplinen'][0];
		}
		$html .= '<p class="kopf">' . $h(implode(' · ', $kopf)) . '</p>';
		if ($mit_kennzahl) {
			$html .= '<p class="disziplinen">' . $h('Disziplinen: ' . implode(' · ', $plan['disziplinen'])) . '</p>';
		}
		if ($entwurf) {
			$html .= '<p class="entwurf">Entwurf – der Startplan ist noch nicht veröffentlicht.</p>';
		}
		if ((string) $tag['hinweis'] !== '') {
			$html .= '<p class="hinweis">' . $h((string) $tag['hinweis']) . '</p>';
		}

		$html .= '<table><thead><tr><th class="zeit" width="14%">Zeit</th>';
		foreach ($spalten as $sp) {
			$html .= '<th width="' . $breite . '%">' . $h(StartplanAnsicht::spalte_label($sp)) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ($plan['durchgaenge'] as $dg) {
			$html .= '<tr><th class="zeit"><div class="von">' . $h($dg['beginn']) . '</div><div class="bis">' . $h('bis ' . $dg['ende']) . '</div>';
			$html .= '<div class="dg">' . $h(sprintf('Durchgang %d', (int) $dg['nummer']) . ($dg['bezeichnung'] !== '' ? ' · ' . $dg['bezeichnung'] : '')) . '</div>';
			$html .= '</th>';
			foreach ($spalten as $sp) {
				$z = $dg['zellen'][ $sp['key'] ] ?? null;
				if ($z === null) {
					$html .= '<td></td>';
					continue;
				}
				$html .= '<td>';
				$html .= '<div class="verein">' . $h($z['verein']) . '</div>';
				$html .= '<div class="name">' . $h(trim($z['name'] . ', ' . $z['vorname'], ', ')) . '</div>';
				$zusatz = array_filter([$mit_kennzahl ? $z['kennzahl'] : '', $z['startklasse']]);
				if ($zusatz !== []) {
					$html .= '<div class="klasse">' . $h(implode(' · ', $zusatz)) . '</div>';
				}
				$html .= '</td>';
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table>';

		$html .= '<p class="fuss">' . $h(Shortcode::HINWEIS) . '</p>';
		$html .= '<p class="fuss">' . $h(sprintf('KSV Fallingbostel · %s · Stand: %s Uhr', StartplanAnsicht::sportjahr_titel((int) $tag['sportjahr_id']), Clock::format_local(Clock::now_utc()))) . '</p>';
		$html .= '</body></html>';
		return $html;
	}

	private function css(bool $eng): string {
		return 'body{font-family:dejavusans,sans-serif;font-size:' . ($eng ? '7.5pt' : '8.5pt') . ';color:#111}'
			. 'h1{font-size:14pt;margin:0 0 1mm}'
			. '.kopf{font-size:9pt;color:#333;margin:0 0 1mm;font-weight:bold}'
			. '.disziplinen{font-size:8pt;color:#444;margin:0 0 3mm}'
			. '.entwurf{border:1px solid #9b1c1c;color:#9b1c1c;padding:1.5mm 3mm;margin:0 0 3mm;font-weight:bold}'
			. '.hinweis{border-left:2px solid #666;padding:1mm 3mm;margin:0 0 3mm;color:#333}'
			. 'table{width:100%;border-collapse:collapse}'
			. 'th,td{border:0.3mm solid #444;padding:' . ($eng ? '1mm 0.8mm' : '1.2mm 1.5mm') . ';text-align:center;vertical-align:middle}'
			. 'thead th{background:#eee;font-size:' . ($eng ? '7pt' : '8pt') . ';font-weight:bold}'
			. 'th.zeit{background:#f4f4f4}'
			. 'th.zeit div.von{font-weight:bold;font-size:' . ($eng ? '9pt' : '10pt') . '}'
			. 'th.zeit div.bis{font-weight:normal;font-size:7.5pt;color:#444}'
			. 'th.zeit div.dg{font-weight:normal;font-size:7pt;color:#555;margin-top:0.6mm}'
			. 'td div{margin:0}'
			. '.verein{font-size:' . ($eng ? '6.5pt' : '7.5pt') . ';color:#444}'
			. '.name{font-weight:bold;font-size:' . ($eng ? '7.5pt' : '9pt') . '}'
			. '.klasse{font-size:' . ($eng ? '6.5pt' : '7pt') . ';color:#444}'
			. '.fuss{font-size:7.5pt;color:#555;margin:2mm 0 0}';
	}
}
