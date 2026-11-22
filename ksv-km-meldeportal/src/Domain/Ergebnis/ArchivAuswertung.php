<?php
/**
 * Wertet die Dateiliste eines Jahresordners (km_JJJJ) aus, ohne etwas zu verändern.
 *
 *   - Ergebnisdateien nach dem Muster e|m<Schlüssel>.<pdf|html> werden den Plätzen
 *     des Katalogs zugeordnet. Gibt es eine Datei als PDF und als HTML, gilt wie im
 *     bisherigen Plugin: ab 2024 PDF zuerst, davor HTML.
 *   - Geteilte Klassen (1_10_10A, …B) werden zu eigenen Katalogzeilen „… Gruppe A“,
 *     wenn die Grundklasse im Katalog steht (Entscheidung vom 22.11.2026).
 *   - Alles andere kommt auf die Klärungsliste (passt zum Muster, aber ohne Platz)
 *     oder unter „sonstige Dateien“ (anderes Muster; bleibt unberührt).
 *
 * Reines PHP, unit-testbar.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain\Ergebnis;

final class ArchivAuswertung {

	/** Ab diesem Jahr wird PDF vor HTML bevorzugt (wie bisher). */
	public const PDF_AB = 2024;

	/** @var list<array{art: string, schluessel: string, datei: string, format: string, platz: Platz}> */
	public array $treffer = [];

	/** @var list<array{schluessel: string, spo: string, disziplin_text: string, klasse_text: string}> */
	public array $neue_katalogzeilen = [];

	/** @var list<array{art: string, schluessel: string, datei: string, format: string}> */
	public array $ungeklaert = [];

	/** @var list<string> */
	public array $sonstige = [];

	/** @var list<string> Doppelte Fassungen (z. B. HTML neben PDF), die nicht verwendet werden */
	public array $nicht_verwendet = [];

	/**
	 * @param list<string>                         $dateien Dateinamen im Jahresordner (ohne Pfad)
	 * @param array<string, array<string, mixed>>  $katalog schluessel => Zeile (spo, disziplin_text, klasse_text)
	 */
	public static function auswerten(int $jahr, array $dateien, array $katalog): self {
		$a = new self();
		$ordner = Schluessel::jahresordner($jahr);

		// 1. Dateien lesen und je Art + Schlüssel sammeln.
		$je_platz = [];
		foreach ($dateien as $datei) {
			$info = Schluessel::aus_dateiname($datei);
			if ($info === null) {
				$a->sonstige[] = $datei;
				continue;
			}
			$je_platz[ $info['art'] . ':' . $info['schluessel'] ][ $info['endung'] ] = $datei;
		}

		// 2. Geteilte Klassen als neue Katalogzeilen vorschlagen.
		foreach (array_keys($je_platz) as $id) {
			[, $schluessel] = explode(':', $id, 2);
			if (isset($katalog[ $schluessel ]) || !preg_match('/^(.+_\d+)([A-Z])$/', $schluessel, $m) || !isset($katalog[ $m[1] ])) {
				continue;
			}
			$basis = $katalog[ $m[1] ];
			$zeile = [
				'schluessel'     => $schluessel,
				'spo'            => (string) ($basis['spo'] ?? '') . $m[2],
				'disziplin_text' => (string) ($basis['disziplin_text'] ?? ''),
				'klasse_text'    => trim((string) ($basis['klasse_text'] ?? '') . ' Gruppe ' . $m[2]),
			];
			$katalog[ $schluessel ] = $zeile;
			$a->neue_katalogzeilen[ $schluessel ] = $zeile;
		}
		$a->neue_katalogzeilen = array_values($a->neue_katalogzeilen);

		$plaetze = Platzliste::aus_katalog(array_map(
			static fn(string $s, array $z): array => ['schluessel' => $s] + $z,
			array_keys($katalog),
			array_values($katalog)
		));

		// 3. Zuordnen, bevorzugte Fassung wählen.
		$vorzug = $jahr >= self::PDF_AB ? ['pdf', 'html'] : ['html', 'pdf'];
		ksort($je_platz);
		foreach ($je_platz as $id => $fassungen) {
			[$art, $schluessel] = explode(':', $id, 2);
			$format = isset($fassungen[ $vorzug[0] ]) ? $vorzug[0] : $vorzug[1];
			$datei = $fassungen[ $format ];
			foreach ($fassungen as $f => $andere) {
				if ($f !== $format) {
					$a->nicht_verwendet[] = $andere;
				}
			}
			$eintrag = ['art' => $art, 'schluessel' => $schluessel, 'datei' => $ordner . '/' . $datei, 'format' => $format];
			$platz = $plaetze->finde($art, $schluessel);
			if ($platz === null) {
				$a->ungeklaert[] = $eintrag;
				continue;
			}
			$a->treffer[] = $eintrag + ['platz' => $platz];
		}
		return $a;
	}

	public function anzahl(string $art): int {
		return count(array_filter($this->treffer, static fn(array $t): bool => $t['art'] === $art));
	}
}
