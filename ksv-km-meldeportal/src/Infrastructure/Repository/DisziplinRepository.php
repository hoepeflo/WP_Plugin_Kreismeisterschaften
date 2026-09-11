<?php
/**
 * Disziplinen je Sportjahr.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class DisziplinRepository extends Repository {

	protected const TABLE = 'disziplin';
	protected const INT_COLUMNS = ['id', 'mannschaft_groesse', 'sortierung'];
	protected const FLOAT_COLUMNS = ['tarif_override', 'mannschaft_startgeld'];
	protected const BOOL_COLUMNS = ['angeboten'];
	protected const NULLABLE_COLUMNS = ['tarif_override', 'mixteam_kennzahl_modus', 'hinweis'];

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function by_sportjahr(int $sportjahr_id): array {
		return $this->where(['sportjahr_id' => $sportjahr_id], 'sortierung ASC, kennzahl ASC');
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function by_kennzahl(int $sportjahr_id, string $kennzahl): ?array {
		return $this->first(['sportjahr_id' => $sportjahr_id, 'kennzahl' => $kennzahl]);
	}
}
