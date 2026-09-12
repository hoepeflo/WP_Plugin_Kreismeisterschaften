<?php
/**
 * Änderungen nach Meldeschluss (Warteschlange der Sammelmail, „Änderungen seit Export").
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class AenderungRepository extends Repository {

	protected const TABLE = 'aenderung';
	protected const NULLABLE_COLUMNS = ['einzelmeldung_id', 'details', 'versendet_am'];

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function offen_je_verein(int $verein_id): array {
		return $this->where(['verein_id' => $verein_id, 'versendet_am' => null], 'id ASC');
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function seit(int $sportjahr_id, ?string $zeitpunkt): array {
		$rows = $this->where(['sportjahr_id' => $sportjahr_id], 'id ASC');
		if ($zeitpunkt === null) {
			return $rows;
		}
		return array_values(array_filter($rows, static fn(array $r): bool => (string) $r['erstellt_am'] > $zeitpunkt));
	}

	/**
	 * Offene (noch nicht versendete) Änderungen des Sportjahres, gruppiert je Verein.
	 *
	 * @return array<int, list<array<string, mixed>>>
	 */
	public function offen_je_sportjahr(int $sportjahr_id): array {
		$out = [];
		foreach ($this->where(['sportjahr_id' => $sportjahr_id, 'versendet_am' => null], 'id ASC') as $row) {
			$out[ (int) $row['verein_id'] ][] = $row;
		}
		return $out;
	}

	/**
	 * Markiert Änderungen atomar als versendet – nur solche, die noch offen sind.
	 * Liefert die Zahl der tatsächlich beanspruchten Zeilen; ein zweiter, gleichzeitiger
	 * Lauf bekommt 0 und sendet daher keine zweite Mail.
	 *
	 * @param list<int> $ids
	 */
	public function beanspruchen(array $ids, string $versendet_am): int {
		$ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
		if ($ids === []) {
			return 0;
		}
		$platzhalter = implode(',', array_fill(0, count($ids), '%d'));
		$sql = $this->db->prepare("UPDATE {$this->table()} SET versendet_am = %s WHERE versendet_am IS NULL AND id IN ({$platzhalter})", ...array_merge([$versendet_am], $ids)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = $this->db->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_int($n) ? $n : 0;
	}

	/**
	 * Beanspruchte Änderungen wieder freigeben (Mailversand fehlgeschlagen → nächster Lauf).
	 *
	 * @param list<int> $ids
	 */
	public function freigeben(array $ids): void {
		foreach ($ids as $id) {
			$this->update((int) $id, ['versendet_am' => null]);
		}
	}
}
