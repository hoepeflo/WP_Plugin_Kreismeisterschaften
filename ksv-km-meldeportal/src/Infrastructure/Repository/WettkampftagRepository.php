<?php
/**
 * Wettkampftage.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class WettkampftagRepository extends Repository {

	protected const TABLE = 'wettkampftag';
	protected const INT_COLUMNS = ['id', 'sortierung'];
	protected const NULLABLE_COLUMNS = ['buchungsfrist', 'freigegeben_am', 'veroeffentlicht_am', 'ausgeblendet_am', 'beitrag_id', 'erinnerung_am', 'erinnerung_gesendet_am', 'hinweis'];

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function by_sportjahr(int $sportjahr_id): array {
		return $this->where(['sportjahr_id' => $sportjahr_id], 'datum ASC, sortierung ASC, id ASC');
	}
}
