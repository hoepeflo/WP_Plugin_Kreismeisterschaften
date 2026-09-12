<?php
/**
 * Buchhaltungsbelege.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class BelegRepository extends Repository {

	protected const TABLE = 'beleg';
	protected const FLOAT_COLUMNS = ['summe'];
	protected const BOOL_COLUMNS = ['ungeprueft_hinweis'];
	protected const NULLABLE_COLUMNS = ['positionen', 'erstellt_von'];

	/**
	 * @return array<int, array<string, mixed>> verein_id => letzter Beleg
	 */
	public function letzte_je_verein(int $sportjahr_id): array {
		$out = [];
		foreach ($this->where(['sportjahr_id' => $sportjahr_id], 'id DESC') as $b) {
			$out[ (int) $b['verein_id'] ] ??= $b;
		}
		return $out;
	}
}
