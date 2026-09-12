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
	protected const NULLABLE_COLUMNS = ['buchungsfrist', 'freigegeben_am', 'veroeffentlicht_am', 'ausgeblendet_am', 'beitrag_id', 'erinnerung_am', 'erinnerung_gesendet_am', 'hinweis', 'schiessstand_id'];

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function by_sportjahr(int $sportjahr_id): array {
		return $this->where(['sportjahr_id' => $sportjahr_id], 'datum ASC, sortierung ASC, id ASC');
	}

	/** Ausblenden des Abschlusses zurücknehmen (Sportjahr wieder geöffnet). */
	public function ausblenden_zuruecknehmen(int $sportjahr_id): int {
		$n = 0;
		foreach ($this->by_sportjahr($sportjahr_id) as $tag) {
			if ($tag['ausgeblendet_am'] !== null) {
				$this->update((int) $tag['id'], ['ausgeblendet_am' => null]);
				$n++;
			}
		}
		return $n;
	}
}
