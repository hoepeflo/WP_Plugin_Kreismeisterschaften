<?php
/**
 * Geschlecht: m, w oder x (beide, z. B. gemischte Team- und Para-Klassen).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain;

final class Geschlecht {

	public const M     = 'm';
	public const W     = 'w';
	public const BEIDE = 'x';

	public const ALLE = [self::M, self::W, self::BEIDE];

	public static function is_valid(string $value): bool {
		return in_array($value, self::ALLE, true);
	}

	public static function label(string $value): string {
		return match ($value) {
			self::M => 'männlich',
			self::W => 'weiblich',
			self::BEIDE => 'beide',
			default => $value,
		};
	}

	/** Passt das Geschlecht eines Schützen (m/w) zu einer Klasse (m/w/x)? */
	public static function matches(string $klasse, string $schuetze): bool {
		return $klasse === self::BEIDE || $klasse === $schuetze;
	}
}
