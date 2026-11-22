<?php
/**
 * Schlüssel einer Ergebnisdatei: SpO-Kennzahl und Klassennummer, mit Unterstrich verbunden.
 *
 * Beispiele:
 *   1.10 + Klasse 10        → 1_10_10    (Datei e1_10_10.pdf bzw. m1_10_10.pdf)
 *   „1.58 G“ + Klasse 10    → 1_58G_10
 *   Bogen 6.20, alle Klassen → 6_20      (Sammelergebnis ohne Klassennummer)
 *   geteilte Klasse 2008     → 1_10_10A
 *
 * Das Format entspricht den Datei-IDs des bisherigen Ergebnis-Plugins (srd_kreis_v3.datei),
 * deshalb bleiben alle vorhandenen Dateien und Adressen gültig. Reines PHP, unit-testbar.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain\Ergebnis;

final class Schluessel {

	public const EINZEL     = 'e';
	public const MANNSCHAFT = 'm';

	/** Erlaubte Endungen beim Einlesen (Archiv) – neue Uploads sind nur PDF. */
	public const ENDUNGEN = ['pdf', 'html', 'htm'];

	/** Ein Segment: Ziffern, optional ein Buchstabe (58G, 56S, 03F, 10A). */
	private const SEGMENT = '/^\d{1,3}[A-Z]?$/';

	/**
	 * Schlüssel aus Kennzahl und Klassennummer (null = Sammelergebnis der Disziplin).
	 */
	public static function aus_kennzahl(string $kennzahl, ?int $klassennummer = null, string $zusatz = ''): string {
		$teile = self::segmente_aus_kennzahl($kennzahl);
		if ($teile === []) {
			throw new \InvalidArgumentException(sprintf('Kennzahl „%s“ lässt sich nicht in einen Dateischlüssel übersetzen.', $kennzahl));
		}
		if ($klassennummer !== null) {
			$teile[] = $klassennummer . strtoupper($zusatz);
		}
		return implode('_', $teile);
	}

	/**
	 * Kennzahl → Segmente: „1.58 G“ → [1, 58G]; „2.03 F“ → [2, 03F]; „12.10“ → [12, 10].
	 *
	 * @return list<string>
	 */
	public static function segmente_aus_kennzahl(string $kennzahl): array {
		$k = strtoupper(trim($kennzahl));
		// Leerzeichen vor einem Buchstaben-Zusatz gehören zum vorherigen Segment.
		$k = (string) preg_replace('/\s+([A-Z])\b/', '$1', $k);
		$teile = preg_split('/[.\s_-]+/', $k, -1, PREG_SPLIT_NO_EMPTY);
		if ($teile === false || $teile === []) {
			return [];
		}
		foreach ($teile as $t) {
			if (!preg_match(self::SEGMENT, $t)) {
				return [];
			}
		}
		return array_values($teile);
	}

	/**
	 * Normalisiert einen eingegebenen Schlüssel (1-10-10, 1.10.10, 1_58g_10 → 1_58G_10).
	 */
	public static function normalisieren(string $roh): ?string {
		$roh = strtoupper(trim($roh));
		if ($roh === '') {
			return null;
		}
		$teile = preg_split('/[.\s_-]+/', $roh, -1, PREG_SPLIT_NO_EMPTY);
		if ($teile === false || count($teile) < 2 || count($teile) > 3) {
			return null;
		}
		foreach ($teile as $t) {
			if (!preg_match(self::SEGMENT, $t)) {
				return null;
			}
		}
		return implode('_', $teile);
	}

	/**
	 * Liest einen Dateinamen: E1-10-10.pdf, e1_10_10.PDF, m6_20.pdf, E-1-10-10.pdf.
	 *
	 * @return array{art: string, schluessel: string, endung: string}|null
	 */
	public static function aus_dateiname(string $dateiname): ?array {
		$name = basename(str_replace('\\', '/', trim($dateiname)));
		if (!preg_match('/^(.+)\.([A-Za-z]{3,4})$/', $name, $m)) {
			return null;
		}
		$endung = strtolower($m[2]);
		if (!in_array($endung, self::ENDUNGEN, true)) {
			return null;
		}
		if (!preg_match('/^([EeMm])[-_ ]?(\d.*)$/', $m[1], $t)) {
			return null;
		}
		$schluessel = self::normalisieren($t[2]);
		if ($schluessel === null) {
			return null;
		}
		return ['art' => strtolower($t[1]), 'schluessel' => $schluessel, 'endung' => $endung === 'htm' ? 'html' : $endung];
	}

	/** Dateiname im Ergebnisordner: e1_10_10.pdf */
	public static function dateiname(string $art, string $schluessel, string $endung = 'pdf'): string {
		self::art_pruefen($art);
		return $art . $schluessel . '.' . $endung;
	}

	/** Ordner des Jahres relativ zum Ergebnisordner: km_2027 */
	public static function jahresordner(int $jahr): string {
		if ($jahr < 1990 || $jahr > 2100) {
			throw new \InvalidArgumentException('Ungültiges Jahr.');
		}
		return 'km_' . $jahr;
	}

	/** Disziplin-Teil eines Schlüssels: 1_10_10 → 1_10, 6_20 → 6_20 (Sammelergebnis). */
	public static function disziplin_teil(string $schluessel): string {
		$teile = explode('_', $schluessel);
		return count($teile) >= 3 ? implode('_', array_slice($teile, 0, -1)) : $schluessel;
	}

	public static function ist_sammel(string $schluessel): bool {
		return count(explode('_', $schluessel)) === 2;
	}

	/** Kategorie = erste Zahl des Schlüssels (1 Gewehr, 2 Pistole, 6 Bogen, …). */
	public static function kategorie(string $schluessel): int {
		return (int) explode('_', $schluessel)[0];
	}

	/**
	 * Sortierschlüssel: numerisch je Segment, Buchstaben danach (1_2_10 vor 1_10_10).
	 */
	public static function sortierung(string $schluessel): string {
		$out = [];
		foreach (explode('_', $schluessel) as $seg) {
			preg_match('/^(\d+)([A-Z]?)$/', $seg, $m);
			$out[] = sprintf('%04d%s', (int) ($m[1] ?? 0), $m[2] ?? '');
		}
		return implode('.', $out);
	}

	public static function art_pruefen(string $art): void {
		if ($art !== self::EINZEL && $art !== self::MANNSCHAFT) {
			throw new \InvalidArgumentException('Art muss e (Einzel) oder m (Mannschaft) sein.');
		}
	}
}
