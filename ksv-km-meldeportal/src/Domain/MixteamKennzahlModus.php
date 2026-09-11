<?php
/**
 * MixTeam: Welche Klasse kommt in die DAVID-Kennzahl (offener Punkt Konzept 13).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain;

final class MixteamKennzahlModus {

	public const TEAM       = 'team';
	public const GESCHLECHT = 'geschlecht';

	public const ALLE = [self::TEAM, self::GESCHLECHT];

	public static function is_valid(string $value): bool {
		return in_array($value, self::ALLE, true);
	}

	public static function label(string $value): string {
		return match ($value) {
			self::TEAM => 'Teamklasse',
			self::GESCHLECHT => 'Geschlechterklasse',
			default => $value,
		};
	}
}
