<?php
/**
 * Baut das Regelwerk eines Sportjahres aus der Datenbank (mit Einstellungen).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure;

use KSV\KMM\Domain\Engine\Disziplin;
use KSV\KMM\Domain\Engine\Engine;
use KSV\KMM\Domain\Engine\Klasse;
use KSV\KMM\Domain\Engine\Regel;
use KSV\KMM\Domain\Engine\Regelwerk;
use KSV\KMM\Infrastructure\Repository\DisziplinRepository;
use KSV\KMM\Infrastructure\Repository\GruppeRepository;
use KSV\KMM\Infrastructure\Repository\KlasseRepository;
use KSV\KMM\Infrastructure\Repository\RegelRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\TarifRepository;
use KSV\KMM\Support\Settings;

final class RegelwerkLader {

	/** @var array<int, Regelwerk> */
	private static array $cache = [];

	public static function laden(int $sportjahr_id, bool $frisch = false): Regelwerk {
		if (!$frisch && isset(self::$cache[ $sportjahr_id ])) {
			return self::$cache[ $sportjahr_id ];
		}
		$sportjahr = (new SportjahrRepository())->find($sportjahr_id);
		if ($sportjahr === null) {
			throw new \RuntimeException('Sportjahr nicht gefunden.');
		}
		$stichtag = is_string($sportjahr['meldeschluss']) && $sportjahr['meldeschluss'] !== ''
			? substr((string) $sportjahr['meldeschluss'], 0, 10)
			: sprintf('%d-01-10', (int) $sportjahr['jahr']);

		$rw = new Regelwerk(
			(int) $sportjahr['jahr'],
			$stichtag,
			(new TarifRepository())->by_sportjahr($sportjahr_id),
			Settings::get('fitasc_damen_ab_56') !== 'senioren',
			(bool) Settings::get('tarif_override_ignorieren')
		);

		$codes = [];
		foreach ((new GruppeRepository())->by_sportjahr($sportjahr_id) as $g) {
			$codes[ (int) $g['id'] ] = (string) $g['code'];
			$rw->gruppe_hinzufuegen((string) $g['code'], $g['hoehermeldung_bereich'] !== null ? (string) $g['hoehermeldung_bereich'] : null, (bool) $g['ist_para']);
		}
		foreach ((new KlasseRepository())->by_sportjahr($sportjahr_id) as $k) {
			$code = $codes[ (int) $k['gruppe_id'] ] ?? null;
			if ($code === null) {
				continue;
			}
			$rw->klasse_hinzufuegen(new Klasse(
				(int) $k['id'],
				$code,
				(int) $k['nummer'],
				(string) $k['geschlecht'],
				(string) $k['bezeichnung'],
				$k['alter_von'] !== null ? (int) $k['alter_von'] : null,
				$k['alter_bis'] !== null ? (int) $k['alter_bis'] : null,
				(string) $k['tarifstufe'],
				$k['stufe'] !== null && $k['stufe'] !== '' ? (string) $k['stufe'] : null,
				(bool) $k['ist_para'],
				(bool) $k['ist_teamklasse'],
				(bool) $k['festgeschrieben'],
			));
		}
		foreach ((new DisziplinRepository())->by_sportjahr($sportjahr_id) as $d) {
			$code = $codes[ (int) $d['gruppe_id'] ] ?? null;
			if ($code === null) {
				continue;
			}
			$rw->disziplin_hinzufuegen(new Disziplin(
				(int) $d['id'],
				(string) $d['kennzahl'],
				$code,
				(string) $d['bezeichnung'],
				(string) $d['typ'],
				(bool) $d['angeboten'],
				(int) $d['mannschaft_groesse'],
				(string) $d['ergebnis_format'],
				$d['tarif_override'] !== null ? (float) $d['tarif_override'] : null,
				(float) $d['mannschaft_startgeld'],
				$d['mixteam_kennzahl_modus'] !== null ? (string) $d['mixteam_kennzahl_modus'] : null,
			));
		}
		foreach ((new RegelRepository())->by_sportjahr($sportjahr_id) as $r) {
			$rw->regel_hinzufuegen(new Regel(
				(int) $r['disziplin_id'],
				(int) $r['klasse_id'],
				(string) $r['einzel_modus'],
				$r['einzel_ziel_klasse_id'] !== null ? (int) $r['einzel_ziel_klasse_id'] : null,
				(string) $r['mannschaft_modus'],
				$r['mannschaft_ziel_klasse_id'] !== null ? (int) $r['mannschaft_ziel_klasse_id'] : null,
				$r['mindestalter'] !== null ? (int) $r['mindestalter'] : null,
				(string) $r['hinweis'],
			));
		}
		self::$cache[ $sportjahr_id ] = $rw;
		return $rw;
	}

	public static function engine(int $sportjahr_id, bool $frisch = false): Engine {
		return new Engine(self::laden($sportjahr_id, $frisch));
	}

	public static function cache_leeren(): void {
		self::$cache = [];
	}
}
