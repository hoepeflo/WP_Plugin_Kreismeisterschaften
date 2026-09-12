<?php
/**
 * Einheiten eines Wettkampftags (Stände, Scheiben, Rotten) mit Kapazität.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class EinheitRepository extends Repository {

	protected const TABLE = 'einheit';
	protected const INT_COLUMNS = ['id', 'kapazitaet', 'sortierung'];
	protected const NULLABLE_COLUMNS = ['standgruppe_id', 'nummer'];

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function by_wettkampftag(int $wettkampftag_id): array {
		return $this->where(['wettkampftag_id' => $wettkampftag_id], 'sortierung ASC, id ASC');
	}

	/**
	 * @param array<string, mixed> $einheit
	 * @return int[] Disziplin-IDs (leer = alle)
	 */
	public static function disziplin_ids(array $einheit): array {
		$raw = trim((string) ($einheit['disziplin_ids'] ?? ''));
		return $raw === '' ? [] : array_values(array_filter(array_map('intval', explode(',', $raw))));
	}
}
