<?php
/**
 * Typen der Änderungen nach Meldeschluss (Sammelmail, „Änderungen seit Export").
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain;

final class AenderungTyp {

	public const STATUS       = 'status';
	public const ABMELDUNG    = 'abmeldung';
	public const NACHMELDUNG  = 'nachmeldung';
	public const KORREKTUR    = 'korrektur';
	public const MANNSCHAFT   = 'mannschaft';
	public const STARTPLAN    = 'startplan';

	public static function label(string $value): string {
		return match ($value) {
			self::STATUS => 'Verarbeitungsstatus',
			self::ABMELDUNG => 'Abmeldung',
			self::NACHMELDUNG => 'Nachmeldung',
			self::KORREKTUR => 'Korrektur',
			self::MANNSCHAFT => 'Mannschaft',
			self::STARTPLAN => 'Startplan',
			default => $value,
		};
	}
}
