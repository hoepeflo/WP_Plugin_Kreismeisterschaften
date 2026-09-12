<?php
/**
 * Durchgänge eines Wettkampftags.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class DurchgangRepository extends Repository {

	protected const TABLE = 'durchgang';
	protected const INT_COLUMNS = ['id', 'nummer', 'sortierung'];

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function by_wettkampftag(int $wettkampftag_id): array {
		return $this->where(['wettkampftag_id' => $wettkampftag_id], 'beginn ASC, nummer ASC, id ASC');
	}
}
