<?php
/**
 * Export-Protokoll (Grundlage für „Änderungen seit Export“ in Phase 2).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class ExportRepository extends Repository {

	protected const TABLE = 'export';
	protected const INT_COLUMNS = ['id', 'zeilen'];
	protected const NULLABLE_COLUMNS = ['disziplin_id', 'gruppe_id', 'parameter', 'erstellt_von'];

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function by_sportjahr(int $sportjahr_id, int $limit = 50): array {
		return array_slice($this->where(['sportjahr_id' => $sportjahr_id], 'id DESC'), 0, $limit);
	}
}
