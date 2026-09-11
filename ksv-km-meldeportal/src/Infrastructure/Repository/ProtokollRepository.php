<?php
/**
 * Änderungsprotokoll.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class ProtokollRepository extends Repository {

	protected const TABLE = 'protokoll';
	protected const NULLABLE_COLUMNS = ['sportjahr_id', 'verein_id', 'akteur_id', 'objekt_id', 'details'];

	/**
	 * @param array<string, mixed> $filter
	 * @return array<int, array<string, mixed>>
	 */
	public function neueste(array $filter = [], int $limit = 200): array {
		$rows = $this->where($filter, 'id DESC');
		return array_slice($rows, 0, $limit);
	}
}
