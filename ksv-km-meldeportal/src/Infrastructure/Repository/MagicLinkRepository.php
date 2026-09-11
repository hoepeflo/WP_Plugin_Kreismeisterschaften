<?php
/**
 * Magic Links (nur Hash gespeichert).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class MagicLinkRepository extends Repository {

	protected const TABLE = 'magic_link';
	protected const INT_COLUMNS = ['id', 'verwendungen'];
	protected const NULLABLE_COLUMNS = ['widerrufen_am', 'zuletzt_verwendet_am', 'erstellt_von'];

	/**
	 * @return array<string, mixed>|null
	 */
	public function by_hash(string $hash): ?array {
		return $this->first(['token_hash' => $hash]);
	}

	/**
	 * Neuester nicht widerrufener Link je Verein (für die Übersicht).
	 *
	 * @return array<int, array<string, mixed>> verein_id => Link
	 */
	public function aktuelle_je_verein(int $sportjahr_id): array {
		$out = [];
		foreach ($this->where(['sportjahr_id' => $sportjahr_id, 'widerrufen_am' => null], 'id DESC') as $row) {
			$vid = (int) $row['verein_id'];
			if (!isset($out[ $vid ])) {
				$out[ $vid ] = $row;
			}
		}
		return $out;
	}

	/** Widerruft alle Links eines Vereins im Sportjahr; liefert die IDs. */
	public function widerrufen(int $verein_id, int $sportjahr_id, string $zeitpunkt): void {
		$this->db->query($this->db->prepare(
			"UPDATE {$this->table()} SET widerrufen_am = %s WHERE verein_id = %d AND sportjahr_id = %d AND widerrufen_am IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$zeitpunkt,
			$verein_id,
			$sportjahr_id
		));
	}
}
