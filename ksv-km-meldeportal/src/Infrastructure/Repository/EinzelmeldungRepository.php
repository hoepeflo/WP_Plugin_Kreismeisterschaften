<?php
/**
 * Einzelmeldungen (Schütze × Disziplin).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class EinzelmeldungRepository extends Repository {

	protected const TABLE = 'einzelmeldung';
	protected const INT_COLUMNS = ['id', 'geburtsjahr'];
	protected const FLOAT_COLUMNS = ['meldeergebnis', 'startgeld'];
	protected const BOOL_COLUMNS = ['hoehermeldung_angewendet', 'startrecht', 'nicht_meldung', 'startgeld_berechnen', 'konflikt'];
	protected const NULLABLE_COLUMNS = ['schuetze_id', 'geburtsjahr', 'klasse_id', 'startklasse_id', 'mannschaft_klasse_id', 'para_klasse_id', 'meldeergebnis', 'mannschaft_id', 'verarbeitet_am', 'abgemeldet_am'];

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function by_sportjahr(int $sportjahr_id): array {
		return $this->where(['sportjahr_id' => $sportjahr_id], 'verein_id ASC, disziplin_id ASC, id ASC');
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function by_meldung(int $meldung_id): array {
		return $this->where(['meldung_id' => $meldung_id], 'disziplin_id ASC, id ASC');
	}
}
