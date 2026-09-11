<?php
/**
 * Mannschaften.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class MannschaftRepository extends Repository {

	protected const TABLE = 'mannschaft';
	protected const INT_COLUMNS = ['id', 'nummer'];
	protected const FLOAT_COLUMNS = ['startgeld'];
	protected const BOOL_COLUMNS = ['unvollstaendig'];

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function by_meldung(int $meldung_id): array {
		return $this->where(['meldung_id' => $meldung_id], 'disziplin_id ASC, nummer ASC');
	}
}
