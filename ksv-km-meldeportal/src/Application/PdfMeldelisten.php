<?php
/**
 * PDF-Meldelisten für den Backend-Ausdruck (Konzept 11.2): Umfang Disziplin / Gruppe /
 * alle, jede Disziplin auf neuer Seite, Gruppierung nach Verein oder Klasse, innerhalb
 * alphabetisch. Startklasse mit eigentlicher Klasse, Mannschaftsnummern, auch Bogen.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Domain\Export\Meldezeile;
use KSV\KMM\Http\View;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\GruppeRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;

final class PdfMeldelisten {

	public const GRUPPIERUNG_VEREIN = 'verein';
	public const GRUPPIERUNG_KLASSE = 'klasse';

	/**
	 * @param list<Meldezeile> $zeilen
	 * @return array<string, mixed> Struktur für das Template: disziplinen[] mit gruppen[] mit zeilen[]
	 */
	public function struktur(array $zeilen, string $gruppierung, int $sportjahr_id): array {
		$rw = RegelwerkLader::laden($sportjahr_id);
		$disziplinen = [];
		foreach ($zeilen as $z) {
			$disziplinen[ $z->disziplin_kennzahl ] ??= ['kennzahl' => $z->disziplin_kennzahl, 'bezeichnung' => $z->disziplin, 'gruppen' => [], 'anzahl' => 0];
			$key = $gruppierung === self::GRUPPIERUNG_VEREIN ? $z->vn_name : $z->startklasse;
			$disziplinen[ $z->disziplin_kennzahl ]['gruppen'][ $key ] ??= ['titel' => $key, 'zeilen' => []];
			$disziplinen[ $z->disziplin_kennzahl ]['gruppen'][ $key ]['zeilen'][] = $z;
			$disziplinen[ $z->disziplin_kennzahl ]['anzahl']++;
		}
		foreach ($disziplinen as &$d) {
			if ($gruppierung === self::GRUPPIERUNG_KLASSE) {
				uksort($d['gruppen'], static function (string $a, string $b) use ($d): int {
					$na = $d['gruppen'][ $a ]['zeilen'][0]->startklasse_nummer;
					$nb = $d['gruppen'][ $b ]['zeilen'][0]->startklasse_nummer;
					return [$na, $a] <=> [$nb, $b];
				});
			} else {
				ksort($d['gruppen'], SORT_NATURAL | SORT_FLAG_CASE);
			}
			foreach ($d['gruppen'] as &$g) {
				usort($g['zeilen'], static fn(Meldezeile $a, Meldezeile $b): int => [$a->nachname, $a->vorname, $a->vn_name] <=> [$b->nachname, $b->vorname, $b->vn_name]);
			}
			unset($g);
			$d['gruppen'] = array_values($d['gruppen']);
		}
		unset($d);
		$sportjahr = (new SportjahrRepository())->find($sportjahr_id);
		return [
			'sportjahr'   => (int) ($sportjahr['jahr'] ?? 0),
			'gruppierung' => $gruppierung,
			'disziplinen' => array_values($disziplinen),
			'stand'       => wp_date('d.m.Y H:i'),
		];
	}

	/**
	 * @param list<Meldezeile> $zeilen
	 */
	public function html(array $zeilen, string $gruppierung, int $sportjahr_id, string $titel): string {
		return View::capture('pdf/meldelisten', ['s' => $this->struktur($zeilen, $gruppierung, $sportjahr_id), 'titel' => $titel]);
	}

	/**
	 * Erzeugt die Liste und protokolliert den Export.
	 *
	 * @return array{inhalt: string, dateiname: string, zeilen: int}
	 */
	public function erzeugen(int $sportjahr_id, string $umfang, ?int $disziplin_id, ?string $gruppe_code, string $gruppierung, bool $nur_eingereicht = true): array {
		$service = new ExportService();
		$zeilen = $service->zeilen($sportjahr_id, $umfang === 'disziplin' ? $disziplin_id : null, $umfang === 'gruppe' ? $gruppe_code : null, $nur_eingereicht, false);
		$rw = RegelwerkLader::laden($sportjahr_id);
		if (!\KSV\KMM\Auth\Rechte::ist_admin()) {
			// Referenten: nur Disziplinen des eigenen Zuständigkeitsbereichs (serverseitig).
			$zeilen = array_values(array_filter($zeilen, static function ($z) use ($rw): bool {
				$d = $rw->disziplin_nach_kennzahl($z->disziplin_kennzahl);
				return $d !== null && \KSV\KMM\Auth\Rechte::zustaendig($d);
			}));
		}
		$sportjahr = (new SportjahrRepository())->find($sportjahr_id);
		$titel = sprintf('Meldeliste KM %d', (int) ($sportjahr['jahr'] ?? 0));
		$teil = 'alle';
		$gruppe_id = null;
		if ($umfang === 'disziplin' && $disziplin_id !== null) {
			$d = $rw->disziplin($disziplin_id);
			$teil = $d !== null ? $d->kennzahl : 'disziplin';
			$titel .= $d !== null ? ' – ' . $d->kennzahl . ' ' . $d->bezeichnung : '';
		} elseif ($umfang === 'gruppe' && $gruppe_code !== null) {
			$g = (new GruppeRepository())->by_code($sportjahr_id, $gruppe_code);
			$teil = $gruppe_code;
			$gruppe_id = $g !== null ? (int) $g['id'] : null;
			$titel .= $g !== null ? ' – ' . (string) $g['bezeichnung'] : '';
		}
		$html = $this->html($zeilen, $gruppierung, $sportjahr_id, $titel);
		$pdf = Pdf::aus_html($html, 'A4-L');
		$dateiname = sprintf('meldeliste-km%d-%s-nach-%s.pdf', (int) ($sportjahr['jahr'] ?? 0), sanitize_file_name($teil), $gruppierung);
		$service->protokollieren($sportjahr_id, ExportService::TYP_PDF, $umfang === 'disziplin' ? $disziplin_id : null, $gruppe_id, $dateiname, count($zeilen), ['umfang' => $umfang, 'gruppierung' => $gruppierung, 'nur_eingereicht' => $nur_eingereicht]);
		return ['inhalt' => $pdf, 'dateiname' => $dateiname, 'zeilen' => count($zeilen)];
	}
}
