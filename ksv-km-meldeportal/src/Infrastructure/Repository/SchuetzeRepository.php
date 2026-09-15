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

	/**
	 * Schützen, die seit dem Stichjahr nicht mehr gemeldet wurden (Konzept 7.2).
	 *
	 * Wer nie gemeldet wurde (`zuletzt_gemeldet_jahr` ist NULL), zählt erst mit, wenn auch
	 * der Eintrag selbst älter ist als das Stichjahr – ein gerade angelegter Schütze soll
	 * nicht gleich wieder verschwinden.
	 *
	 * Schützen, auf die noch eine Einzelmeldung zeigt, bleiben in jedem Fall erhalten.
	 * Nach dem Abschluss eines Sportjahres ist dessen Personenbezug entfernt, alte Jahre
	 * geben ihre Schützen also nach und nach frei.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function ohne_meldung_seit(int $stichjahr): array {
		$em = \KSV\KMM\Infrastructure\Database\Tables::name('einzelmeldung');
		$sql = $this->db->prepare(
			"SELECT s.* FROM {$this->table()} s
			 WHERE (
			       (s.zuletzt_gemeldet_jahr IS NOT NULL AND s.zuletzt_gemeldet_jahr <= %d)
			    OR (s.zuletzt_gemeldet_jahr IS NULL AND YEAR(s.created_at) <= %d)
			 )
			 AND NOT EXISTS (SELECT 1 FROM {$em} e WHERE e.schuetze_id = s.id)
			 ORDER BY s.id ASC",
			$stichjahr,
			$stichjahr
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->db->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array($rows) ? array_map([$this, 'cast'], $rows) : [];
	}
}
