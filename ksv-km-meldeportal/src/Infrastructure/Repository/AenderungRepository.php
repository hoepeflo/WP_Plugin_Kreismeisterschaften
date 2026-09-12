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
}
