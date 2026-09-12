<?php
/**
 * Standgruppen eines Schießstands, z. B. „10 m“: 12 Stände „Stand 1“ … „Stand 12“ mit
 * Kapazität 1; „Bogen“: 6 Scheiben mit 4 Positionen.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class StandgruppeRepository extends Repository {

	protected const TABLE = 'standgruppe';
	protected const INT_COLUMNS = ['id', 'anzahl', 'nummer_von', 'kapazitaet', 'sortierung'];

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function by_schiessstand(int $schiessstand_id): array {
		return $this->where(['schiessstand_id' => $schiessstand_id], 'sortierung ASC, id ASC');
	}

	/**
	 * Nummern der Stände einer Gruppe (nummer_von … nummer_von + anzahl − 1).
	 *
	 * @param array<string, mixed> $gruppe
	 * @return list<int>
	 */
	public static function nummern(array $gruppe): array {
		$von = max(1, (int) $gruppe['nummer_von']);
		return range($von, $von + max(1, (int) $gruppe['anzahl']) - 1);
	}

	/**
	 * @param array<string, mixed> $gruppe
	 */
	public static function bezeichnung(array $gruppe, int $nummer): string {
		return trim((string) $gruppe['praefix']) . ' ' . $nummer;
	}
}
