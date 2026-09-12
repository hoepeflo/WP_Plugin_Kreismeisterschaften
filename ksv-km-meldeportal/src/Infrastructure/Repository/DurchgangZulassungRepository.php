<?php
/**
 * Zugelassene Kombinationen Disziplin × Startklasse je Durchgang (Startklasse NULL = alle).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class DurchgangZulassungRepository extends Repository {

	protected const TABLE = 'durchgang_zulassung';
	protected const NULLABLE_COLUMNS = ['startklasse_id'];

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function by_durchgang(int $durchgang_id): array {
		return $this->where(['durchgang_id' => $durchgang_id], 'disziplin_id ASC, startklasse_id ASC');
	}

	/**
	 * @param array<int, array{disziplin_id: int, startklasse_id: ?int}> $eintraege
	 */
	public function setzen(int $durchgang_id, array $eintraege): void {
		$this->delete_where(['durchgang_id' => $durchgang_id]);
		foreach ($eintraege as $e) {
			$this->insert(['durchgang_id' => $durchgang_id, 'disziplin_id' => $e['disziplin_id'], 'startklasse_id' => $e['startklasse_id']]);
		}
	}
}
