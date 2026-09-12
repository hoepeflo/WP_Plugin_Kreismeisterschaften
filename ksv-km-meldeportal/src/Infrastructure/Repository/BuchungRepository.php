<?php
/**
 * Buchungen (Platz × Meldung). Eindeutigkeit pro Platz und pro Meldung erzwingt die
 * Datenbank (UNIQUE KEYs); insert() liefert 0 bei Verletzung.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

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
}
