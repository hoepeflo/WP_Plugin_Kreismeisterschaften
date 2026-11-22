<?php
/**
 * Verzeichnis der Ergebnisdateien (eine Zeile je Jahr, Art und Schlüssel).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class ErgebnisRepository extends Repository {

	public const STATUS_SICHTBAR     = 'sichtbar';
	public const STATUS_ENTWURF      = 'entwurf';
	public const STATUS_UNGEKLAERT   = 'ungeklaert';
	public const STATUS_AUSGEBLENDET = 'ausgeblendet';

	public const STATUS = [self::STATUS_SICHTBAR, self::STATUS_ENTWURF, self::STATUS_UNGEKLAERT, self::STATUS_AUSGEBLENDET];

	public const QUELLE_ARCHIV = 'archiv';
	public const QUELLE_UPLOAD = 'upload';

	protected const TABLE = 'ergebnis';
	protected const INT_COLUMNS = ['id', 'jahr', 'groesse'];
	protected const NULLABLE_COLUMNS = ['sportjahr_id', 'disziplin_id', 'klasse_id', 'datei_stand', 'hochgeladen_von'];

	/**
	 * @return array<string, mixed>|null
	 */
	public function platz(int $jahr, string $art, string $schluessel): ?array {
		return $this->first(['jahr' => $jahr, 'art' => $art, 'schluessel' => $schluessel]);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function by_jahr(int $jahr, ?string $status = null): array {
		$where = ['jahr' => $jahr];
		if ($status !== null) {
			$where['status'] = $status;
		}
		return $this->where($where, 'schluessel ASC, art ASC');
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function by_status(string $status): array {
		return $this->where(['status' => $status], 'jahr DESC, schluessel ASC');
	}

	/**
	 * Jahre mit mindestens einem sichtbaren Ergebnis, neuestes zuerst.
	 *
	 * @return list<int>
	 */
	public function jahre_sichtbar(): array {
		$rows = $this->db->get_col($this->db->prepare("SELECT DISTINCT jahr FROM {$this->table()} WHERE status = %s ORDER BY jahr DESC", self::STATUS_SICHTBAR)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map('intval', is_array($rows) ? $rows : []);
	}

	/**
	 * Anzahl je Jahr und Status.
	 *
	 * @return array<int, array<string, int>> jahr => status => Anzahl
	 */
	public function zaehlung(): array {
		$rows = $this->db->get_results("SELECT jahr, status, COUNT(*) AS n FROM {$this->table()} GROUP BY jahr, status ORDER BY jahr DESC", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out = [];
		foreach (is_array($rows) ? $rows : [] as $r) {
			$out[ (int) $r['jahr'] ][ (string) $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Legt an oder aktualisiert (Schlüssel: Jahr, Art, Schlüssel).
	 *
	 * @param array<string, mixed> $daten
	 * @return int ID
	 */
	public function speichern(int $jahr, string $art, string $schluessel, array $daten): int {
		$vorhanden = $this->platz($jahr, $art, $schluessel);
		if ($vorhanden !== null) {
			$this->update((int) $vorhanden['id'], $daten);
			return (int) $vorhanden['id'];
		}
		return $this->insert(['jahr' => $jahr, 'art' => $art, 'schluessel' => $schluessel] + $daten);
	}
}
