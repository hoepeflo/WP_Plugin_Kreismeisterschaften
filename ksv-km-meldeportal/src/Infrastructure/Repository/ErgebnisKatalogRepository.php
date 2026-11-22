<?php
/**
 * Katalog der Archivjahre: Bezeichnungen je Dateischlüssel, einmalig aus srd_kreis_v3
 * übernommen und um geteilte Klassen (Gruppe A/B/C) ergänzt.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class ErgebnisKatalogRepository extends Repository {

	protected const TABLE = 'ergebnis_katalog';

	/**
	 * @return array<string, array<string, mixed>> schluessel => Zeile
	 */
	public function als_map(): array {
		$out = [];
		foreach ($this->where([], 'schluessel ASC') as $z) {
			$out[ (string) $z['schluessel'] ] = $z;
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function by_schluessel(string $schluessel): ?array {
		return $this->first(['schluessel' => $schluessel]);
	}
}
