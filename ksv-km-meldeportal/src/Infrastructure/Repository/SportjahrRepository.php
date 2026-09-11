<?php
/**
 * Sportjahre.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class SportjahrRepository extends Repository {

	protected const TABLE = 'sportjahr';
	protected const INT_COLUMNS = ['id', 'jahr'];
	protected const BOOL_COLUMNS = ['ist_aktiv'];
	protected const NULLABLE_COLUMNS = ['meldung_beginn', 'meldeschluss', 'erinnerung_am', 'erinnerung_gesendet_am', 'abgeschlossen_am', 'anonymisiert_am', 'regeln_geaendert_am'];

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		return $this->where([], 'jahr DESC');
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function by_jahr(int $jahr): ?array {
		return $this->first(['jahr' => $jahr]);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function aktiv(): ?array {
		return $this->first(['ist_aktiv' => 1]);
	}

	public function set_aktiv(int $id): void {
		$this->db->query($this->db->prepare("UPDATE {$this->table()} SET ist_aktiv = IF(id = %d, 1, 0)", $id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
