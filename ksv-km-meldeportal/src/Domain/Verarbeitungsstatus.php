<?php
/**
 * Verarbeitungsstatus einer Einzelmeldung (Phase 2, Konzept 12.1).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain;

final class Verarbeitungsstatus {

	public const UNGEPRUEFT           = 'ungeprueft';
	public const VERARBEITET          = 'verarbeitet';
	public const NICHT_STARTBERECHTIGT = 'nicht_startberechtigt';

	public const ALLE = [self::UNGEPRUEFT, self::VERARBEITET, self::NICHT_STARTBERECHTIGT];

	public static function is_valid(string $value): bool {
		return in_array($value, self::ALLE, true);
	}

	public static function label(string $value): string {
		return match ($value) {
			self::UNGEPRUEFT => 'ungeprüft',
			self::VERARBEITET => 'verarbeitet',
			self::NICHT_STARTBERECHTIGT => 'nicht startberechtigt',
			default => $value,
		};
	}
}
