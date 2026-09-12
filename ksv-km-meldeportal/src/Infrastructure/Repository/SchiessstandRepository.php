<?php
/**
 * Schießstände (sportjahrübergreifende Stammdaten): Ort mit Standgruppen.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class SchiessstandRepository extends Repository {

	protected const TABLE = 'schiessstand';
	protected const INT_COLUMNS = ['id', 'sortierung'];
	protected const NULLABLE_COLUMNS = ['notiz'];

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function alle(): array {
		return $this->where([], 'sortierung ASC, bezeichnung ASC');
	}
}
