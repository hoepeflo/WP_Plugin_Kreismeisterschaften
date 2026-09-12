<?php
/**
 * Vereinsmeldungen je Sportjahr.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class MeldungRepository extends Repository {

	protected const TABLE = 'meldung';
	protected const FLOAT_COLUMNS = ['startgeld_summe'];
	protected const NULLABLE_COLUMNS = ['eingereicht_am', 'wieder_geoeffnet_am', 'nachmeldung_bis', 'letzte_sammelmail_am'];

	public const STATUS_OFFEN       = 'offen';
	public const STATUS_ENTWURF     = 'entwurf';
	public const STATUS_EINGEREICHT = 'eingereicht';

	/**
	 * @return array<string, mixed>|null
	 */
	public function by_verein(int $verein_id, int $sportjahr_id): ?array {
		return $this->first(['verein_id' => $verein_id, 'sportjahr_id' => $sportjahr_id]);
	}

	/**
	 * @return array<int, array<string, mixed>> verein_id => Meldung
	 */
	public function by_sportjahr(int $sportjahr_id): array {
		$out = [];
		foreach ($this->where(['sportjahr_id' => $sportjahr_id]) as $m) {
			$out[ (int) $m['verein_id'] ] = $m;
		}
		return $out;
	}
}
