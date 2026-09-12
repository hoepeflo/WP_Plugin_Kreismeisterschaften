<?php
/**
 * Meldeergebnis: Eingabe prüfen und normalisieren (ganze Ringe / Zehntelringe).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain;

final class Meldeergebnis {

	/**
	 * @return float|null normalisierter Wert (ganz: x.0, zehntel: eine Nachkommastelle); null = leer
	 * @throws \InvalidArgumentException bei ungültigem Format
	 */
	public static function parse(string $eingabe, string $format): ?float {
		$e = trim(str_replace(' ', '', $eingabe));
		if ($e === '') {
			return null;
		}
		$e = str_replace(',', '.', $e);
		if ($format === ErgebnisFormat::ZEHNTEL) {
			if (!preg_match('/^\d{1,4}(?:\.\d)?$/', $e)) {
				throw new \InvalidArgumentException('Bitte Zehntelringe mit genau einer Nachkommastelle eingeben, z. B. 389,4 (oder eine ganze Zahl).');
			}
			return round((float) $e, 1);
		}
		if (!preg_match('/^\d{1,4}(?:\.0)?$/', $e)) {
			throw new \InvalidArgumentException('Bitte ganze Ringe ohne Nachkommastelle eingeben, z. B. 375.');
		}
		return (float) (int) $e;
	}

	/** Anzeige im Format der Disziplin (Dezimalkomma). */
	public static function format(?float $wert, string $format): string {
		if ($wert === null) {
			return '';
		}
		return $format === ErgebnisFormat::ZEHNTEL ? number_format($wert, 1, ',', '') : (string) (int) round($wert);
	}
}
