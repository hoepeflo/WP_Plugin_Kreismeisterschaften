<?php
/**
 * Ausschreibungen und Unterlagen je Jahr (bisher Option srd_km_documents).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class DokumentRepository extends Repository {

	protected const TABLE = 'dokument';
	protected const INT_COLUMNS = ['id', 'jahr', 'sortierung', 'kategorie'];
	protected const NULLABLE_COLUMNS = ['kategorie', 'attachment_id', 'page_id'];

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function by_jahr(int $jahr): array {
		return $this->where(['jahr' => $jahr], 'sortierung ASC, id ASC');
	}

	/**
	 * @return list<int>
	 */
	public function jahre(): array {
		$rows = $this->db->get_col("SELECT DISTINCT jahr FROM {$this->table()} ORDER BY jahr DESC"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map('intval', is_array($rows) ? $rows : []);
	}
}
