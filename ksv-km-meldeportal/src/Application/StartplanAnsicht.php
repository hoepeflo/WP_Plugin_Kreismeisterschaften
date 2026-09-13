<?php
/**
 * Veröffentlichter Startplan (Konzept 12.6): die Daten, die nach der Veröffentlichung
 * öffentlich sind – Name, Vorname, Verein, Startklasse, Einheit/Position und Uhrzeit.
 *
 * Mehr nicht: kein Geburtsdatum, keine Mitgliedsnummer, kein Meldeergebnis, kein
 * Startgeld. Sichtbar ist ein Wettkampftag erst im Status „veröffentlicht“ und nur,
 * solange er nicht durch den Abschluss des Sportjahres ausgeblendet wurde.
 *
 * Grundlage für den Shortcode und den PDF-Startplan.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Domain\WettkampftagStatus;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\BuchungRepository;
use KSV\KMM\Infrastructure\Repository\DurchgangRepository;
use KSV\KMM\Infrastructure\Repository\EinheitRepository;
use KSV\KMM\Infrastructure\Repository\EinzelmeldungRepository;
use KSV\KMM\Infrastructure\Repository\SchuetzeRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;
use KSV\KMM\Infrastructure\Repository\WettkampftagRepository;
use KSV\KMM\Support\Clock;

final class StartplanAnsicht {

	/**
	 * Öffentlich sichtbare Wettkampftage, neueste zuerst.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function tage(?int $sportjahr_id = null): array {
		$repo = new WettkampftagRepository();
		$where = ['status' => WettkampftagStatus::VEROEFFENTLICHT];
		if ($sportjahr_id !== null) {
			$where['sportjahr_id'] = $sportjahr_id;
		}
		$out = [];
		foreach ($repo->where($where, 'datum ASC') as $tag) {
			if ($tag['ausgeblendet_am'] === null) {
				$out[] = $tag;
			}
		}
		return $out;
	}

	/**
	 * Ist dieser Wettkampftag öffentlich?
	 *
	 * @param array<string, mixed> $tag
	 */
	public static function oeffentlich(array $tag): bool {
		return (string) $tag['status'] === WettkampftagStatus::VEROEFFENTLICHT && $tag['ausgeblendet_am'] === null;
	}

	/**
	 * Startplan eines Wettkampftags, sortiert nach Durchgang und Einheit.
	 *
	 * @param string $disziplin Filter: Kennzahl (z. B. „1.10“) oder leer für alle
	 * @param bool   $intern    true = auch ein noch nicht veröffentlichter Tag (Backend-Vorschau, PDF)
	 * @return array{tag: array<string, mixed>, datum: string, durchgaenge: list<array<string, mixed>>, starter: int}|null
	 */
	public static function plan(int $tag_id, string $disziplin = '', bool $intern = false): ?array {
		$tag = (new WettkampftagRepository())->find($tag_id);
		if ($tag === null || (!$intern && !self::oeffentlich($tag))) {
			return null;
		}
		$sportjahr_id = (int) $tag['sportjahr_id'];
		$rw = RegelwerkLader::laden($sportjahr_id);
		$einzel = new EinzelmeldungRepository();
		$schuetzen = new SchuetzeRepository();
		$vereine = [];
		foreach ((new VereinRepository())->all() as $v) {
			$vereine[ (int) $v['id'] ] = (string) $v['name'];
		}
		$einheiten = [];
		foreach ((new EinheitRepository())->by_wettkampftag($tag_id) as $e) {
			$einheiten[ (int) $e['id'] ] = $e;
		}
		$buchungen = [];
		foreach ((new BuchungRepository())->by_wettkampftag($tag_id) as $b) {
			$buchungen[ (int) $b['durchgang_id'] ][] = $b;
		}
		$filter = trim($disziplin);
		$durchgaenge = [];
		$spalten = [];
		$disziplinen = [];
		$klassen = [];
		$starter = 0;
		foreach ((new DurchgangRepository())->by_wettkampftag($tag_id) as $dg) {
			$zeilen = [];
			foreach ($buchungen[ (int) $dg['id'] ] ?? [] as $b) {
				$em = $einzel->find((int) $b['einzelmeldung_id']);
				if ($em === null || $em['abgemeldet_am'] !== null) {
					continue;
				}
				$d = $rw->disziplin((int) $em['disziplin_id']);
				if ($filter !== '' && ($d === null || $d->kennzahl !== $filter)) {
					continue;
				}
				$s = $em['schuetze_id'] !== null ? $schuetzen->find((int) $em['schuetze_id']) : null;
				$klasse_id = $em['startklasse_id'] ?? $em['mannschaft_klasse_id'];
				$k = $klasse_id !== null ? $rw->klasse((int) $klasse_id) : null;
				$e = $einheiten[ (int) $b['einheit_id'] ] ?? null;
				$spalte = (int) $b['einheit_id'] . '-' . (int) $b['position'];
				$zeilen[] = [
					'spalte'      => $spalte,
					'einheit'     => $e !== null ? (string) $e['bezeichnung'] : '',
					'position'    => (int) $b['position'],
					'mehrfach'    => $e !== null && (int) $e['kapazitaet'] > 1,
					'sortierung'  => $e !== null ? (int) $e['sortierung'] : 0,
					'name'        => $s !== null ? (string) $s['nachname'] : '',
					'vorname'     => $s !== null ? (string) $s['vorname'] : '',
					'verein'      => $vereine[ (int) $b['verein_id'] ] ?? '',
					'startklasse' => $k?->bezeichnung ?? '',
					'kennzahl'    => $d?->kennzahl ?? '',
					'disziplin'   => $d?->bezeichnung ?? '',
				];
				$spalten[ $spalte ] = [
					'key'        => $spalte,
					'einheit'    => $e !== null ? (string) $e['bezeichnung'] : '',
					'position'   => (int) $b['position'],
					'mehrfach'   => $e !== null && (int) $e['kapazitaet'] > 1,
					'sortierung' => $e !== null ? (int) $e['sortierung'] : 0,
				];
				if ($d !== null) {
					$disziplinen[ $d->kennzahl ] = $d->kennzahl . ' ' . $d->bezeichnung;
				}
				if (($k?->bezeichnung ?? '') !== '') {
					$klassen[ (string) $k?->bezeichnung ] = true;
				}
			}
			if ($zeilen === []) {
				continue;
			}
			usort($zeilen, static fn(array $a, array $b): int => [$a['sortierung'], $a['einheit'], $a['position']] <=> [$b['sortierung'], $b['einheit'], $b['position']]);
			$starter += count($zeilen);
			$zellen = [];
			foreach ($zeilen as $z) {
				$zellen[ $z['spalte'] ] = $z;
			}
			$durchgaenge[] = [
				'nummer'      => (int) $dg['nummer'],
				'bezeichnung' => (string) $dg['bezeichnung'],
				'beginn'      => Clock::format_local((string) $dg['beginn'], 'H:i'),
				'ende'        => Clock::format_local((string) $dg['ende'], 'H:i'),
				'zeilen'      => $zeilen,
				'zellen'      => $zellen,
			];
		}
		uasort($spalten, static fn(array $a, array $b): int => [$a['sortierung'], $a['einheit'], $a['position']] <=> [$b['sortierung'], $b['einheit'], $b['position']]);
		$datum = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $tag['datum']);
		ksort($disziplinen);
		ksort($klassen);
		return [
			'tag'         => $tag,
			'datum'       => $datum !== false ? wp_date('D, d.m.Y', $datum->getTimestamp()) : (string) $tag['datum'],
			'durchgaenge' => $durchgaenge,
			'spalten'     => array_values($spalten),
			'disziplinen' => array_values($disziplinen),
			'klassen'     => array_keys($klassen),
			'starter'     => $starter,
		];
	}

	/**
	 * Beschriftung einer Spalte: Einheit, bei mehreren Positionen mit Nummer.
	 *
	 * @param array<string, mixed> $spalte
	 */
	public static function spalte_label(array $spalte): string {
		return (string) $spalte['einheit'] . ($spalte['mehrfach'] ? ' / ' . (int) $spalte['position'] : '');
	}

	/** Bezeichnung des Sportjahres für Überschriften. */
	public static function sportjahr_titel(int $sportjahr_id): string {
		$sj = (new SportjahrRepository())->find($sportjahr_id);
		if ($sj === null) {
			return '';
		}
		return (string) $sj['bezeichnung'] !== '' ? (string) $sj['bezeichnung'] : sprintf('Sportjahr %d', (int) $sj['jahr']);
	}
}
