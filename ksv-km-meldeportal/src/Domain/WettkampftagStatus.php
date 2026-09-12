<?php
/**
 * Status eines Wettkampftags: Entwurf → freigegeben (Buchung) → veröffentlicht.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain;

final class WettkampftagStatus {

	public const ENTWURF        = 'entwurf';
	public const FREIGEGEBEN    = 'freigegeben';
	public const VEROEFFENTLICHT = 'veroeffentlicht';

	public const ALLE = [self::ENTWURF, self::FREIGEGEBEN, self::VEROEFFENTLICHT];

	public static function label(string $value): string {
		return match ($value) {
			self::ENTWURF => 'Entwurf',
			self::FREIGEGEBEN => 'freigegeben (Buchung läuft)',
			self::VEROEFFENTLICHT => 'veröffentlicht',
			default => $value,
		};
	}
}
