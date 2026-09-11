<?php
/**
 * Wettbewerbsgruppen je Sportjahr.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class GruppeRepository extends Repository {

	protected const TABLE = 'wettbewerbsgruppe';
	protected const INT_COLUMNS = ['id', 'sortierung'];
	protected const BOOL_COLUMNS = ['ist_para'];
	protected const NULLABLE_COLUMNS = ['hoehermeldung_bereich'];

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function by_sportjahr(int $sportjahr_id): array {
		return $this->where(['sportjahr_id' => $sportjahr_id], 'sortierung ASC, id ASC');
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function by_code(int $sportjahr_id, string $code): ?array {
		return $this->first(['sportjahr_id' => $sportjahr_id, 'code' => $code]);
	}
}
