<?php
/**
 * Sitzungen der Vereinsoberfläche (Cookie-Token, nur Hash gespeichert).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class SitzungRepository extends Repository {

	protected const TABLE = 'sitzung';
	protected const NULLABLE_COLUMNS = ['beendet_am'];

	/**
	 * @return array<string, mixed>|null
	 */
	public function by_hash(string $hash): ?array {
		return $this->first(['token_hash' => $hash]);
	}

	public function beenden_fuer_verein(int $verein_id, string $zeitpunkt): void {
		$this->db->query($this->db->prepare(
			"UPDATE {$this->table()} SET beendet_am = %s WHERE verein_id = %d AND beendet_am IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$zeitpunkt,
			$verein_id
		));
	}

	public function aufraeumen(string $vor): int {
		$result = $this->db->query($this->db->prepare("DELETE FROM {$this->table()} WHERE gueltig_bis < %s OR beendet_am < %s", $vor, $vor)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_int($result) ? $result : 0;
	}
}
