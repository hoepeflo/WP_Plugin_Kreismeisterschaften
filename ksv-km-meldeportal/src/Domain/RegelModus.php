<?php
/**
 * Modus einer Regel (Einzel bzw. Mannschaft): kein Startrecht, eigene Wertung, Verweis.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain;

final class RegelModus {

	public const KEINE   = 'keine';
	public const EIGEN   = 'eigen';
	public const VERWEIS = 'verweis';

	public const ALLE = [self::KEINE, self::EIGEN, self::VERWEIS];

	public static function is_valid(string $value): bool {
		return in_array($value, self::ALLE, true);
	}

	public static function label(string $value): string {
		return match ($value) {
			self::KEINE => 'kein Startrecht',
			self::EIGEN => 'eigene Wertung',
			self::VERWEIS => 'startet in Klasse',
			default => $value,
		};
	}
}
