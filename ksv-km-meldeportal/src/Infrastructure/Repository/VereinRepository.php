<?php
/**
 * Vereine.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class VereinRepository extends Repository {

	protected const TABLE = 'verein';
	protected const BOOL_COLUMNS = ['ist_aktiv'];
	protected const NULLABLE_COLUMNS = ['notiz'];

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function all(bool $nur_aktive = false): array {
		return $this->where($nur_aktive ? ['ist_aktiv' => 1] : [], 'name ASC');
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function by_vn_nummer(string $vn): ?array {
		return $this->first(['vn_nummer' => $vn]);
	}
}
