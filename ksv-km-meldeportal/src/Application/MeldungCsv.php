<?php
/**
 * Meldung des Vereins als CSV-Datei für Excel (eigene Liste, kein DAVID-Format).
 *
 * Semikolon als Trennzeichen und UTF-8 mit BOM, damit Excel Umlaute und Spalten
 * ohne Import-Assistent richtig erkennt.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

final class MeldungCsv {

	public const SPALTEN = ['Kennzahl', 'Disziplin', 'Nachname', 'Vorname', 'Klasse', 'Startklasse', 'Meldeergebnis', 'Nicht zur LM', 'Mannschaft', 'Startgeld', 'Starttag', 'Ort'];

	/**
	 * @param array<string, mixed> $z Zusammenfassung (MeldungService::zusammenfassung)
	 */
	public static function erzeugen(array $z): string {
		$zeilen = [self::SPALTEN];
		foreach ($z['einzelmeldungen'] as $e) {
			$tage = $e['starttage'] ?? [];
			$zeilen[] = [
				(string) $e['kennzahl_voll'],
				(string) $e['disziplin'],
				(string) $e['nachname'],
				(string) $e['vorname'],
				(string) $e['klasse'],
				(string) $e['startklasse'],
				(string) $e['meldeergebnis'],
				$e['nicht_meldung'] ? 'ja' : '',
				$e['mannschaft_nummer'] ? 'M' . (int) $e['mannschaft_nummer'] : '',
				$e['typ'] === 'mixteam' ? '' : number_format((float) $e['startgeld'], 2, ',', ''),
				implode(' / ', array_map(static fn (array $t): string => $t['datum'] . ($t['vorlaeufig'] ? ' (vorläufig)' : ''), $tage)),
				implode(' / ', array_unique(array_map(static fn (array $t): string => (string) $t['ort'], $tage))),
			];
		}
		$out = "\xEF\xBB\xBF";
		foreach ($zeilen as $zeile) {
			$out .= implode(';', array_map([self::class, 'feld'], $zeile)) . "\r\n";
		}
		return $out;
	}

	private static function feld(string $wert): string {
		// Formel-Injektion in Excel verhindern (=, +, -, @ am Anfang).
		if ($wert !== '' && in_array($wert[0], ['=', '+', '-', '@'], true)) {
			$wert = "'" . $wert;
		}
		if (preg_match('/[;"\r\n]/', $wert) === 1) {
			return '"' . str_replace('"', '""', $wert) . '"';
		}
		return $wert;
	}
}
