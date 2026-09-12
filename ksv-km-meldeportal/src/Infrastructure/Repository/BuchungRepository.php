<?php
/**
 * Buchungen (Platz × Meldung). Eindeutigkeit pro Platz und pro Meldung erzwingt die
 * Datenbank (UNIQUE KEYs); insert() liefert 0 bei Verletzung.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

use KSV\KMM\Infrastructure\Database\Tables;

final class BuchungRepository extends Repository {

	protected const TABLE = 'buchung';
	protected const INT_COLUMNS = ['id', 'position'];
	protected const NULLABLE_COLUMNS = ['gebucht_von_id'];

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function by_wettkampftag(int $wettkampftag_id): array {
		return $this->where(['wettkampftag_id' => $wettkampftag_id], 'durchgang_id ASC, einheit_id ASC, position ASC');
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function by_einzelmeldung(int $einzelmeldung_id): ?array {
		return $this->first(['einzelmeldung_id' => $einzelmeldung_id]);
	}

	/** Fehlercode der letzten Datenbankoperation ist eine Schlüsselverletzung? */
	public function letzter_fehler_ist_duplikat(): bool {
		return str_contains(strtolower($this->last_error()), 'duplicate');
	}

	public const ERGEBNIS_OK            = 'ok';
	public const ERGEBNIS_PLATZ_BELEGT  = 'platz_belegt';
	public const ERGEBNIS_SCHON_GEBUCHT = 'schon_gebucht';
	public const ERGEBNIS_FEHLER        = 'fehler';

	/**
	 * Sofortbuchung: ein einzelner INSERT ohne vorheriges Lesen und ohne Sperre. Die
	 * UNIQUE-Schlüssel der Datenbank entscheiden bei gleichzeitigen Klicks; verliert dieser
	 * Klick, liefert die Datenbank den Duplikatfehler 1062 und nichts wurde geschrieben.
	 *
	 * @param array<string, mixed> $data
	 * @return array{ergebnis: string, id: int}
	 */
	public function platz_buchen(array $data): array {
		$this->db->suppress_errors(true);
		$id = $this->insert($data);
		$this->db->suppress_errors(false);
		if ($id > 0) {
			return ['ergebnis' => self::ERGEBNIS_OK, 'id' => $id];
		}
		return ['ergebnis' => $this->duplikat_art(), 'id' => 0];
	}

	/**
	 * Umbuchen als ein einzelnes UPDATE auf die bestehende Zeile: Ist der Zielplatz
	 * inzwischen belegt, scheitert das UPDATE an UNIQUE(platz) und die alte Buchung bleibt
	 * unverändert bestehen – der Verein verliert nie seinen Platz.
	 */
	public function umbuchen(int $id, int $durchgang_id, int $einheit_id, int $position): string {
		$this->db->suppress_errors(true);
		$ok = $this->db->update($this->table(), ['durchgang_id' => $durchgang_id, 'einheit_id' => $einheit_id, 'position' => $position], ['id' => $id], ['%d', '%d', '%d'], ['%d']);
		$this->db->suppress_errors(false);
		if ($ok !== false) {
			return self::ERGEBNIS_OK;
		}
		return $this->duplikat_art();
	}

	private function duplikat_art(): string {
		$fehler = strtolower($this->last_error());
		if (!str_contains($fehler, 'duplicate')) {
			return self::ERGEBNIS_FEHLER;
		}
		return str_contains($fehler, 'einzelmeldung_id') ? self::ERGEBNIS_SCHON_GEBUCHT : self::ERGEBNIS_PLATZ_BELEGT;
	}

	/**
	 * Buchungen aller Einzelmeldungen eines Schützen im Sportjahr (für die Überschneidungsprüfung).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function by_schuetze(int $sportjahr_id, int $schuetze_id): array {
		$em = Tables::name('einzelmeldung');
		$sql = $this->db->prepare("SELECT b.* FROM {$this->table()} b INNER JOIN {$em} e ON e.id = b.einzelmeldung_id WHERE b.sportjahr_id = %d AND e.schuetze_id = %d", $sportjahr_id, $schuetze_id); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->db->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array($rows) ? array_map([$this, 'cast'], $rows) : [];
	}
}
