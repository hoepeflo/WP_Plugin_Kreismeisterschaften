<?php
/**
 * Starttage je Disziplin und Startklasse für die Vereinsoberfläche.
 *
 * Quelle ist der Startplan: Ein Wettkampftag gilt für eine Disziplin und Startklasse,
 * wenn einer seiner Durchgänge dafür zugelassen ist (Zulassung ohne Startklasse = alle
 * Klassen der Disziplin). Tage im Entwurf werden als „vorläufig“ gekennzeichnet,
 * ausgeblendete Tage nicht angezeigt.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Domain\WettkampftagStatus;
use KSV\KMM\Infrastructure\Repository\DurchgangRepository;
use KSV\KMM\Infrastructure\Repository\DurchgangZulassungRepository;
use KSV\KMM\Infrastructure\Repository\WettkampftagRepository;

final class Starttage {

	private const WOCHENTAGE = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

	/** @var array<int, array<string, mixed>> Tag-ID => Wettkampftag */
	private array $tage = [];

	/** @var array<int, array{tag: int, disziplin: int, klasse: int|null}> */
	private array $zulassungen = [];

	/** @var array<string, array<int, array<string, mixed>>> */
	private array $cache = [];

	public function __construct(int $sportjahr_id) {
		$durchgaenge = new DurchgangRepository();
		$zulassung = new DurchgangZulassungRepository();
		foreach ((new WettkampftagRepository())->by_sportjahr($sportjahr_id) as $tag) {
			if ($tag['ausgeblendet_am'] !== null) {
				continue;
			}
			$tid = (int) $tag['id'];
			$this->tage[ $tid ] = $tag;
			foreach ($durchgaenge->by_wettkampftag($tid) as $dg) {
				foreach ($zulassung->by_durchgang((int) $dg['id']) as $z) {
					$this->zulassungen[] = [
						'tag'       => $tid,
						'disziplin' => (int) $z['disziplin_id'],
						'klasse'    => $z['startklasse_id'] !== null ? (int) $z['startklasse_id'] : null,
					];
				}
			}
		}
	}

	/**
	 * Starttage einer Meldung bzw. eines Angebots, nach Datum sortiert.
	 *
	 * @return array<int, array{datum: string, iso: string, bezeichnung: string, ort: string, vorlaeufig: bool}>
	 */
	public function fuer(int $disziplin_id, ?int $startklasse_id): array {
		$key = $disziplin_id . ':' . ($startklasse_id ?? 0);
		if (!isset($this->cache[ $key ])) {
			$this->cache[ $key ] = self::aufloesen($this->zulassungen, $this->tage, $disziplin_id, $startklasse_id);
		}
		return $this->cache[ $key ];
	}

	/**
	 * Reine Logik, ohne Datenbank (testbar).
	 *
	 * @param array<int, array{tag: int, disziplin: int, klasse: int|null}> $zulassungen
	 * @param array<int, array<string, mixed>> $tage Tag-ID => Wettkampftag (datum Y-m-d, bezeichnung, ort, status)
	 * @return array<int, array{datum: string, iso: string, bezeichnung: string, ort: string, vorlaeufig: bool}>
	 */
	public static function aufloesen(array $zulassungen, array $tage, int $disziplin_id, ?int $startklasse_id): array {
		$ids = [];
		foreach ($zulassungen as $z) {
			if ($z['disziplin'] !== $disziplin_id || !isset($tage[ $z['tag'] ])) {
				continue;
			}
			if ($z['klasse'] === null || ($startklasse_id !== null && $z['klasse'] === $startklasse_id)) {
				$ids[ $z['tag'] ] = true;
			}
		}
		$out = [];
		foreach (array_keys($ids) as $tid) {
			$t = $tage[ $tid ];
			$iso = (string) $t['datum'];
			$dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso);
			$out[] = [
				'datum'       => $dt !== false ? self::WOCHENTAGE[ (int) $dt->format('w') ] . ', ' . $dt->format('d.m.Y') : $iso,
				'iso'         => $iso,
				'bezeichnung' => (string) ($t['bezeichnung'] ?? ''),
				'ort'         => (string) ($t['ort'] ?? ''),
				'vorlaeufig'  => (string) ($t['status'] ?? '') === WettkampftagStatus::ENTWURF,
			];
		}
		usort($out, static fn (array $a, array $b): int => strcmp($a['iso'], $b['iso']));
		return $out;
	}
}
