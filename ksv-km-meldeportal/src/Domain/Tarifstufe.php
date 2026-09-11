<?php
/**
 * Tarifstufen der Startgelder (Konzept 11.3).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain;

final class Tarifstufe {

	public const SCHUELER   = 'schueler';
	public const JUGEND     = 'jugend';
	public const ERWACHSENE = 'erwachsene';

	public const ALLE = [self::SCHUELER, self::JUGEND, self::ERWACHSENE];

	/** Standardbeträge in Euro. */
	public const STANDARD = [
		self::SCHUELER   => 3.00,
		self::JUGEND     => 5.00,
		self::ERWACHSENE => 7.00,
	];

	public static function is_valid(string $value): bool {
		return in_array($value, self::ALLE, true);
	}

	public static function label(string $value): string {
		return match ($value) {
			self::SCHUELER => 'Schüler',
			self::JUGEND => 'Jugend/Junioren',
			self::ERWACHSENE => 'Erwachsene/Senioren',
			default => $value,
		};
	}
}
