<?php
/**
 * Format des Meldeergebnisses: ganze Ringe oder Zehntelringe.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain;

final class ErgebnisFormat {

	public const GANZ    = 'ganz';
	public const ZEHNTEL = 'zehntel';

	public const ALLE = [self::GANZ, self::ZEHNTEL];

	public static function is_valid(string $value): bool {
		return in_array($value, self::ALLE, true);
	}

	public static function label(string $value): string {
		return match ($value) {
			self::GANZ => 'ganze Ringe',
			self::ZEHNTEL => 'Zehntelringe',
			default => $value,
		};
	}

	public static function platzhalter(string $value): string {
		return $value === self::ZEHNTEL ? '389,4' : '375';
	}
}
