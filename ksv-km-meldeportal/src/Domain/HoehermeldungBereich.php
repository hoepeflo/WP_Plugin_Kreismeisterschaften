<?php
/**
 * Bereiche der Höhermeldung nach SpO 0.7.1.1: Bogen, Auflage, übrige Wettbewerbe.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain;

final class HoehermeldungBereich {

	public const UEBRIGE = 'uebrige';
	public const AUFLAGE = 'auflage';
	public const BOGEN   = 'bogen';

	public const ALLE = [self::UEBRIGE, self::AUFLAGE, self::BOGEN];

	public static function is_valid(string $value): bool {
		return in_array($value, self::ALLE, true);
	}

	public static function label(string $value): string {
		return match ($value) {
			self::UEBRIGE => 'übrige Wettbewerbe',
			self::AUFLAGE => 'Auflage',
			self::BOGEN => 'Bogen',
			default => $value,
		};
	}
}
