<?php
/**
 * Höhermeldungen je Schütze und Sportjahr.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class HoehermeldungRepository extends Repository {

	protected const TABLE = 'hoehermeldung';

	/**
	 * @return array<int, array<string, string>> schuetze_id => [bereich => ziel_stufe]
	 */
	public function by_sportjahr(int $sportjahr_id): array {
		$out = [];
		foreach ($this->where(['sportjahr_id' => $sportjahr_id]) as $row) {
			$out[ (int) $row['schuetze_id'] ][ (string) $row['bereich'] ] = (string) $row['ziel_stufe'];
		}
		return $out;
	}

	/**
	 * @return array<string, string> bereich => ziel_stufe
	 */
	public function by_schuetze(int $schuetze_id, int $sportjahr_id): array {
		$out = [];
		foreach ($this->where(['schuetze_id' => $schuetze_id, 'sportjahr_id' => $sportjahr_id]) as $row) {
			$out[ (string) $row['bereich'] ] = (string) $row['ziel_stufe'];
		}
		return $out;
	}

	public function set(int $schuetze_id, int $sportjahr_id, string $bereich, ?string $ziel_stufe): void {
		$row = $this->first(['schuetze_id' => $schuetze_id, 'sportjahr_id' => $sportjahr_id, 'bereich' => $bereich]);
		if ($ziel_stufe === null || $ziel_stufe === '') {
			if ($row !== null) {
				$this->delete((int) $row['id']);
			}
			return;
		}
		if ($row === null) {
			$this->insert(['schuetze_id' => $schuetze_id, 'sportjahr_id' => $sportjahr_id, 'bereich' => $bereich, 'ziel_stufe' => $ziel_stufe]);
		} else {
			$this->update((int) $row['id'], ['ziel_stufe' => $ziel_stufe]);
		}
	}
}
