<?php
/**
 * Mail-Protokoll (ohne Adressen).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class MailLogRepository extends Repository {

	protected const TABLE = 'mail_log';
	protected const INT_COLUMNS = ['id', 'empfaenger_anzahl'];
	protected const BOOL_COLUMNS = ['erfolgreich'];
	protected const NULLABLE_COLUMNS = ['sportjahr_id', 'verein_id'];

	/**
	 * Letzter Versand je Verein und Typ.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function letzte_je_verein(int $sportjahr_id, string $typ): array {
		$out = [];
		foreach ($this->where(['sportjahr_id' => $sportjahr_id, 'typ' => $typ], 'id DESC') as $row) {
			$vid = (int) $row['verein_id'];
			if (!isset($out[ $vid ])) {
				$out[ $vid ] = $row;
			}
		}
		return $out;
	}
}
