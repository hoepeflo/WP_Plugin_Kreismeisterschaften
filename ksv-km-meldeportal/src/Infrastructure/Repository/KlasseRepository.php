<?php
/**
 * Klassen je Sportjahr und Gruppe.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class KlasseRepository extends Repository {

	protected const TABLE = 'klasse';
	protected const INT_COLUMNS = ['id', 'nummer', 'alter_von', 'alter_bis', 'sortierung'];
	protected const BOOL_COLUMNS = ['ist_para', 'ist_teamklasse', 'festgeschrieben'];
	protected const NULLABLE_COLUMNS = ['alter_von', 'alter_bis', 'stufe'];

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function by_sportjahr(int $sportjahr_id): array {
		return $this->where(['sportjahr_id' => $sportjahr_id], 'gruppe_id ASC, sortierung ASC, nummer ASC, geschlecht ASC');
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function by_gruppe(int $gruppe_id): array {
		return $this->where(['gruppe_id' => $gruppe_id], 'sortierung ASC, nummer ASC, geschlecht ASC');
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function by_key(int $gruppe_id, int $nummer, string $geschlecht): ?array {
		return $this->first(['gruppe_id' => $gruppe_id, 'nummer' => $nummer, 'geschlecht' => $geschlecht]);
	}
}
