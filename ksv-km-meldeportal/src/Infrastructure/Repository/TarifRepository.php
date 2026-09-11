<?php
/**
 * Startgeldtarife je Sportjahr und Tarifstufe.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

use KSV\KMM\Domain\Tarifstufe;

final class TarifRepository extends Repository {

	protected const TABLE = 'startgeld_tarif';
	protected const FLOAT_COLUMNS = ['betrag'];

	/**
	 * @return array<string, float> Tarifstufe => Betrag (fehlende Stufen mit Standard).
	 */
	public function by_sportjahr(int $sportjahr_id): array {
		$out = Tarifstufe::STANDARD;
		foreach ($this->where(['sportjahr_id' => $sportjahr_id]) as $row) {
			$out[ (string) $row['tarifstufe'] ] = (float) $row['betrag'];
		}
		return $out;
	}

	public function set(int $sportjahr_id, string $tarifstufe, float $betrag): void {
		$row = $this->first(['sportjahr_id' => $sportjahr_id, 'tarifstufe' => $tarifstufe]);
		if ($row === null) {
			$this->insert(['sportjahr_id' => $sportjahr_id, 'tarifstufe' => $tarifstufe, 'betrag' => $betrag]);
		} else {
			$this->update((int) $row['id'], ['betrag' => $betrag]);
		}
	}
}
