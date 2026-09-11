<?php
/**
 * Typ einer Disziplin: normal, MixTeam, Bogen.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain;

final class DisziplinTyp {

	public const NORMAL  = 'normal';
	public const MIXTEAM = 'mixteam';
	public const BOGEN   = 'bogen';

	public const ALLE = [self::NORMAL, self::MIXTEAM, self::BOGEN];

	public static function is_valid(string $value): bool {
		return in_array($value, self::ALLE, true);
	}

	public static function label(string $value): string {
		return match ($value) {
			self::NORMAL => 'normal',
			self::MIXTEAM => 'MixTeam',
			self::BOGEN => 'Bogen',
			default => $value,
		};
	}
}
