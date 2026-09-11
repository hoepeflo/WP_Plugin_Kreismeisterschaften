<?php
/**
 * Schützenliste der Vereine (jahresübergreifend).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class SchuetzeRepository extends Repository {

	protected const TABLE = 'schuetze';
	protected const INT_COLUMNS = ['id', 'zuletzt_gemeldet_jahr'];
	protected const NULLABLE_COLUMNS = ['zuletzt_gemeldet_jahr'];

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function by_verein(int $verein_id): array {
		return $this->where(['verein_id' => $verein_id], 'nachname ASC, vorname ASC');
	}
}
