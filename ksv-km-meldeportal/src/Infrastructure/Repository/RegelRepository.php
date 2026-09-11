<?php
/**
 * Regeln Disziplin × Klasse.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class RegelRepository extends Repository {

	protected const TABLE = 'regel';
	protected const INT_COLUMNS = ['id', 'mindestalter'];
	protected const NULLABLE_COLUMNS = ['einzel_ziel_klasse_id', 'mannschaft_ziel_klasse_id', 'mindestalter'];

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function by_sportjahr(int $sportjahr_id): array {
		return $this->where(['sportjahr_id' => $sportjahr_id], 'disziplin_id ASC, klasse_id ASC');
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function by_disziplin(int $disziplin_id): array {
		return $this->where(['disziplin_id' => $disziplin_id], 'klasse_id ASC');
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function by_key(int $disziplin_id, int $klasse_id): ?array {
		return $this->first(['disziplin_id' => $disziplin_id, 'klasse_id' => $klasse_id]);
	}
}
