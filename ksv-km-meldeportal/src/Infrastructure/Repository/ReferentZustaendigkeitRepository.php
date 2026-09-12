<?php
/**
 * Zuständigkeiten eines Referenten: Wettbewerbsgruppe (Code) oder Disziplin (Kennzahl),
 * sportjahrübergreifend über den Schlüssel.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class ReferentZustaendigkeitRepository extends Repository {

	protected const TABLE = 'referent_zustaendigkeit';

	public const TYP_GRUPPE    = 'gruppe';
	public const TYP_DISZIPLIN = 'disziplin';

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function by_referent(int $referent_id): array {
		return $this->where(['referent_id' => $referent_id], 'typ ASC, schluessel ASC');
	}

	/**
	 * @param array<int, array{typ: string, schluessel: string}> $eintraege
	 */
	public function setzen(int $referent_id, array $eintraege): void {
		$this->delete_where(['referent_id' => $referent_id]);
		foreach ($eintraege as $e) {
			$this->insert(['referent_id' => $referent_id, 'typ' => $e['typ'], 'schluessel' => $e['schluessel']]);
		}
	}
}
