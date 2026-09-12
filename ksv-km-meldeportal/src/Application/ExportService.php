<?php
/**
 * Stellt die neutralen Meldezeilen eines Sportjahres bereit und protokolliert Exporte.
 * Kennt kein Zielformat; das übernimmt der Exporter.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Domain\Export\ExporterInterface;
use KSV\KMM\Domain\Export\Meldezeile;
use KSV\KMM\Domain\MixteamKennzahlModus;
use KSV\KMM\Infrastructure\Export\David21Exporter;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\EinzelmeldungRepository;
use KSV\KMM\Infrastructure\Repository\ExportRepository;
use KSV\KMM\Infrastructure\Repository\MannschaftRepository;
use KSV\KMM\Infrastructure\Repository\MeldungRepository;
use KSV\KMM\Infrastructure\Repository\SchuetzeRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;
use KSV\KMM\Support\Clock;
use KSV\KMM\Support\Settings;

final class ExportService {

	public const TYP_DAVID = 'david_csv';
	public const TYP_PDF   = 'pdf_liste';

	/**
	 * @param int|null $disziplin_id     nur diese Disziplin (null = alle)
	 * @param string|null $gruppe        nur diese Wettbewerbsgruppe (Code)
	 * @param bool $nur_eingereicht      Entwürfe ausschließen
	 * @param bool $ohne_bogen           Bogen-Disziplinen ausschließen (DAVID)
	 * @return list<Meldezeile>
	 */
	public function zeilen(int $sportjahr_id, ?int $disziplin_id = null, ?string $gruppe = null, bool $nur_eingereicht = true, bool $ohne_bogen = false): array {
		$rw = RegelwerkLader::laden($sportjahr_id);
		$vereine = [];
		foreach ((new VereinRepository())->all() as $v) {
			$vereine[ (int) $v['id'] ] = $v;
		}
		$meldungen = (new MeldungRepository())->by_sportjahr($sportjahr_id);
		$schuetzen = [];
		foreach ((new SchuetzeRepository())->where([]) as $s) {
			$schuetzen[ (int) $s['id'] ] = $s;
		}
		$mannschaften = [];
		foreach ((new MannschaftRepository())->where(['sportjahr_id' => $sportjahr_id]) as $m) {
			$mannschaften[ (int) $m['id'] ] = $m;
		}
		$zeilen = [];
		foreach ((new EinzelmeldungRepository())->by_sportjahr($sportjahr_id) as $em) {
			if (!$em['startrecht'] || $em['konflikt'] || $em['schuetze_id'] === null || $em['abgemeldet_am'] !== null) {
				continue;
			}
			$meldung = $meldungen[ (int) $em['verein_id'] ] ?? null;
			$status = $meldung !== null ? (string) $meldung['status'] : MeldungRepository::STATUS_OFFEN;
			if ($nur_eingereicht && $status !== MeldungRepository::STATUS_EINGEREICHT) {
				continue;
			}
			$d = $rw->disziplin((int) $em['disziplin_id']);
			$s = $schuetzen[ (int) $em['schuetze_id'] ] ?? null;
			$v = $vereine[ (int) $em['verein_id'] ] ?? null;
			if ($d === null || $s === null || $v === null) {
				continue;
			}
			if ($disziplin_id !== null && $d->id !== $disziplin_id) {
				continue;
			}
			if ($gruppe !== null && $d->gruppe !== $gruppe) {
				continue;
			}
			if ($ohne_bogen && $d->ist_bogen()) {
				continue;
			}
			$klasse = $em['klasse_id'] !== null ? $rw->klasse((int) $em['klasse_id']) : null;
			$startklasse = $em['startklasse_id'] !== null ? $rw->klasse((int) $em['startklasse_id']) : null;
			$pool = $em['mannschaft_klasse_id'] !== null ? $rw->klasse((int) $em['mannschaft_klasse_id']) : null;
			$para = $em['para_klasse_id'] !== null ? $rw->klasse((int) $em['para_klasse_id']) : null;
			if ($d->ist_mixteam()) {
				$kz_klasse = $d->mixteam_kennzahl_modus === MixteamKennzahlModus::GESCHLECHT ? $klasse : $pool;
				$start = $pool;
			} else {
				$kz_klasse = $startklasse;
				$start = $startklasse;
			}
			if ($kz_klasse === null || $start === null) {
				continue;
			}
			$ma = $em['mannschaft_id'] !== null ? ($mannschaften[ (int) $em['mannschaft_id'] ] ?? null) : null;
			$zeilen[] = new Meldezeile(
				$d->kennzahl . '.' . $kz_klasse->nummer,
				$d->kennzahl,
				$d->bezeichnung,
				(string) $s['nachname'],
				(string) $s['vorname'],
				(string) $v['vn_nummer'],
				(string) $v['name'],
				$em['meldeergebnis'] !== null ? (float) $em['meldeergebnis'] : null,
				$d->ergebnis_format,
				(string) $s['geburtsdatum'],
				(string) $s['mitgliedsnummer'],
				(bool) $em['nicht_meldung'],
				$ma !== null ? (int) $ma['nummer'] : null,
				$klasse?->bezeichnung ?? '',
				$start->bezeichnung,
				$start->nummer,
				(string) $em['geschlecht'],
				$status,
				(bool) $em['hoehermeldung_angewendet'],
				$para?->bezeichnung,
				$pool?->bezeichnung ?? ''
			);
		}
		usort($zeilen, static fn(Meldezeile $a, Meldezeile $b): int => [self::sortkey($a->disziplin_kennzahl), $a->startklasse_nummer, $a->vn_name, $a->nachname, $a->vorname] <=> [self::sortkey($b->disziplin_kennzahl), $b->startklasse_nummer, $b->vn_name, $b->nachname, $b->vorname]);
		return $zeilen;
	}

	/**
	 * @return array<int, mixed>
	 */
	public static function sortkey(string $kennzahl): array {
		$out = [];
		foreach (preg_split('/[.\s]+/', $kennzahl) ?: [] as $t) {
			$out[] = preg_match('/^(\d+)(.*)$/', $t, $m) ? [(int) $m[1], $m[2]] : [9999, $t];
		}
		return $out;
	}

	/** DAVID-Exporter mit den Einstellungen. */
	public static function david_exporter(): ExporterInterface {
		$s = Settings::all();
		return new David21Exporter(
			(string) $s['csv_trennzeichen'],
			(string) $s['csv_zeichensatz'],
			(string) $s['csv_ganze_ringe_format'],
			(string) $s['csv_verband_modus'],
			(string) ($s['csv_verband_fest'] ?? ''),
			(bool) ($s['csv_kopfzeile'] ?? true)
		);
	}

	/**
	 * DAVID-Export erzeugen und protokollieren.
	 *
	 * @return array{inhalt: string, dateiname: string, content_type: string, zeilen: int}
	 */
	public function david(int $sportjahr_id, ?int $disziplin_id, bool $nur_eingereicht = true): array {
		$sportjahr = (new SportjahrRepository())->find($sportjahr_id);
		if ($sportjahr === null) {
			throw new \RuntimeException('Sportjahr nicht gefunden.');
		}
		$exporter = self::david_exporter();
		$zeilen = $this->zeilen($sportjahr_id, $disziplin_id, null, $nur_eingereicht, true);
		$inhalt = $exporter->exportieren($zeilen);
		$rw = RegelwerkLader::laden($sportjahr_id);
		$d = $disziplin_id !== null ? $rw->disziplin($disziplin_id) : null;
		$dateiname = sprintf('david21-km%d-%s.%s', (int) $sportjahr['jahr'], $d !== null ? sanitize_file_name($d->kennzahl) : 'alle', $exporter->dateiendung());
		$this->protokollieren($sportjahr_id, self::TYP_DAVID, $disziplin_id, null, $dateiname, count($zeilen), ['nur_eingereicht' => $nur_eingereicht, 'exporter' => $exporter->name()]);
		return ['inhalt' => $inhalt, 'dateiname' => $dateiname, 'content_type' => $exporter->content_type(), 'zeilen' => count($zeilen)];
	}

	/**
	 * @param array<string, mixed> $parameter
	 */
	public function protokollieren(int $sportjahr_id, string $typ, ?int $disziplin_id, ?int $gruppe_id, string $dateiname, int $zeilen, array $parameter = []): int {
		$id = (new ExportRepository())->insert([
			'sportjahr_id' => $sportjahr_id,
			'typ'          => $typ,
			'disziplin_id' => $disziplin_id,
			'gruppe_id'    => $gruppe_id,
			'parameter'    => (string) json_encode($parameter, JSON_UNESCAPED_UNICODE),
			'dateiname'    => $dateiname,
			'zeilen'       => $zeilen,
			'erstellt_von' => get_current_user_id() > 0 ? get_current_user_id() : null,
			'erstellt_am'  => Clock::now_utc(),
		]);
		Protokoll::admin('export.' . $typ, sprintf('%s (%d Zeilen)', $dateiname, $zeilen), $sportjahr_id, null, 'export', $id, $parameter);
		return $id;
	}
}
