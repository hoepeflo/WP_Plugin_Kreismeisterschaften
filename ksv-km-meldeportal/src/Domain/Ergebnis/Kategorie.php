<?php
/**
 * Kategorien der Ergebnisliste nach der ersten Zahl der SpO-Kennzahl
 * (gleiche Einteilung wie im bisherigen Ergebnis-Plugin).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain\Ergebnis;

final class Kategorie {

	public const ALLE = [
		1  => 'Gewehr',
		2  => 'Pistole und Revolver',
		3  => 'Flinte',
		4  => 'Laufende Scheibe',
		5  => 'Armbrust',
		6  => 'Bogen',
		7  => 'Vorderlader',
		8  => 'Targetsprint und Sommerbiathlon',
		9  => 'Auflageschießen',
		10 => 'Parasport',
		11 => 'Lichtschießen',
		12 => 'Blasrohr',
	];

	public static function label(int $id): string {
		return self::ALLE[ $id ] ?? 'Weitere';
	}

	public static function gibt_es(int $id): bool {
		return isset(self::ALLE[ $id ]);
	}
}
