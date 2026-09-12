<?php
/**
 * PDF-Startplan für Aushang und Standaufsicht (Konzept 12.6): sortiert nach Durchgang
 * und Einheit, mit denselben Angaben wie der öffentliche Startplan.
 *
 * Ein noch nicht veröffentlichter Tag lässt sich im Backend als Vorschau drucken; das
 * PDF trägt dann einen deutlichen Entwurfsvermerk.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

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
		return ['dateiname' => $name, 'inhalt' => Pdf::aus_html($this->html($plan, $disziplin)), 'starter' => (int) $plan['starter']];
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	public function html(array $plan, string $disziplin = ''): string {
		$tag = $plan['tag'];
		$entwurf = !StartplanAnsicht::oeffentlich($tag);
		$css = 'body{font-family:dejavusans,sans-serif;font-size:9.5pt;color:#111}'
			. 'h1{font-size:15pt;margin:0 0 2mm}h2{font-size:11pt;margin:6mm 0 1.5mm;padding-bottom:1mm;border-bottom:1px solid #444}'
			. '.kopf{font-size:9pt;color:#444;margin:0 0 4mm}'
			. '.entwurf{border:1px solid #9b1c1c;color:#9b1c1c;padding:1.5mm 3mm;margin:0 0 4mm;font-weight:bold}'
			. '.hinweis{border-left:2px solid #666;padding:1mm 3mm;margin:0 0 4mm;color:#333}'
			. 'table{width:100%;border-collapse:collapse}'
			. 'th{background:#eee;font-size:8pt;text-transform:uppercase;text-align:left;padding:1.2mm 2mm;border-bottom:1px solid #999}'
			. 'td{padding:1.2mm 2mm;border-bottom:1px solid #ddd}'
			. 'tr.z td{background:#fafafa}'
			. '.fuss{font-size:7.5pt;color:#666;margin-top:6mm}';
		$h = static fn(string $s): string => esc_html($s);
		$html = '<html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>';
		$html .= '<h1>' . $h((string) $tag['bezeichnung']) . '</h1>';
		$kopf = [(string) $plan['datum']];
		if ((string) $tag['ort'] !== '') {
			$kopf[] = (string) $tag['ort'];
		}
		$kopf[] = sprintf('%d Starter', (int) $plan['starter']);
		if ($disziplin !== '') {
			$kopf[] = 'nur ' . $disziplin;
		}
		$html .= '<p class="kopf">' . $h(implode(' · ', $kopf)) . '</p>';
		if ($entwurf) {
			$html .= '<p class="entwurf">Entwurf – der Startplan ist noch nicht veröffentlicht.</p>';
		}
		if ((string) $tag['hinweis'] !== '') {
			$html .= '<p class="hinweis">' . $h((string) $tag['hinweis']) . '</p>';
		}
		foreach ($plan['durchgaenge'] as $dg) {
			$titel = sprintf('Durchgang %d · %s–%s Uhr', $dg['nummer'], $dg['beginn'], $dg['ende']);
			if ($dg['bezeichnung'] !== '') {
				$titel .= ' · ' . $dg['bezeichnung'];
			}
			$html .= '<h2>' . $h($titel) . '</h2>';
			$html .= '<table><thead><tr><th style="width:16%">Stand</th><th style="width:28%">Name</th><th style="width:25%">Verein</th><th style="width:20%">Klasse</th>' . ($disziplin === '' ? '<th style="width:11%">Disziplin</th>' : '') . '</tr></thead><tbody>';
			foreach ($dg['zeilen'] as $i => $z) {
				$html .= '<tr' . ($i % 2 === 1 ? ' class="z"' : '') . '>';
				$html .= '<td>' . $h($z['einheit'] . ($z['mehrfach'] ? ' / ' . $z['position'] : '')) . '</td>';
				$html .= '<td>' . $h(trim($z['name'] . ', ' . $z['vorname'], ', ')) . '</td>';
				$html .= '<td>' . $h($z['verein']) . '</td>';
				$html .= '<td>' . $h($z['startklasse']) . '</td>';
				if ($disziplin === '') {
					$html .= '<td>' . $h($z['kennzahl']) . '</td>';
				}
				$html .= '</tr>';
			}
			$html .= '</tbody></table>';
		}
		$html .= '<p class="fuss">' . $h(sprintf('KSV Fallingbostel · %s · Stand: %s Uhr', StartplanAnsicht::sportjahr_titel((int) $tag['sportjahr_id']), Clock::format_local(Clock::now_utc()))) . '</p>';
		$html .= '</body></html>';
		return $html;
	}
}
