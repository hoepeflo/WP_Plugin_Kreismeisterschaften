<?php
/**
 * DAVID21-Sport-Import als CSV (Konzept 11.1).
 *
 * Spalten: Kennzahl, Name, Vorname, Verband, VN-Nummer, VN-Name, Meldeergebnis,
 * Geburtsdatum, Mitgliedsnummer, Nicht-Meldung, DAS, Mannschaft.
 * Trennzeichen, Zeichensatz, Kopfzeile, Schreibweise ganzer Ringe und Feld „Verband“
 * sind Optionen, bis der Testimport sie klärt.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Export;

use KSV\KMM\Domain\ErgebnisFormat;
use KSV\KMM\Domain\Export\ExporterInterface;
use KSV\KMM\Domain\Export\Meldezeile;

final class David21Exporter implements ExporterInterface {

	public const SPALTEN = ['Kennzahl', 'Name', 'Vorname', 'Verband', 'VN-Nummer', 'VN-Name', 'Meldeergebnis', 'Geburtsdatum', 'Mitgliedsnummer', 'Nicht-Meldung', 'DAS', 'Mannschaft'];

	/**
	 * @param string $trennzeichen ";" | "," | "tab"
	 * @param string $zeichensatz  "windows-1252" | "utf-8" | "utf-8-bom"
	 * @param string $ganze_ringe  "ganz" (375) | "komma_null" (375,0)
	 * @param string $verband_modus "vn_nummer" | "leer" | "fest"
	 */
	public function __construct(
		private readonly string $trennzeichen = ';',
		private readonly string $zeichensatz = 'windows-1252',
		private readonly string $ganze_ringe = 'ganz',
		private readonly string $verband_modus = 'vn_nummer',
		private readonly string $verband_fest = '',
		private readonly bool $kopfzeile = true,
	) {
	}

	public function name(): string {
		return 'david21';
	}

	public function content_type(): string {
		return 'text/csv; charset=' . ($this->zeichensatz === 'windows-1252' ? 'windows-1252' : 'utf-8');
	}

	public function dateiendung(): string {
		return 'csv';
	}

	public function exportieren(array $zeilen): string {
		$sep = $this->trennzeichen === 'tab' ? "\t" : $this->trennzeichen;
		$lines = [];
		if ($this->kopfzeile) {
			$lines[] = $this->zeile(self::SPALTEN, $sep);
		}
		foreach ($zeilen as $z) {
			$lines[] = $this->zeile([
				$z->kennzahl,
				$z->nachname,
				$z->vorname,
				$this->verband($z),
				$z->vn_nummer,
				$z->vn_name,
				$this->ergebnis($z),
				$this->datum($z->geburtsdatum),
				$z->mitgliedsnummer,
				$z->nicht_meldung ? '1' : '0',
				'0',
				$z->mannschaft_nummer !== null ? (string) $z->mannschaft_nummer : '',
			], $sep);
		}
		$text = implode("\r\n", $lines) . "\r\n";
		return $this->kodieren($text);
	}

	private function verband(Meldezeile $z): string {
		return match ($this->verband_modus) {
			'leer' => '',
			'fest' => $this->verband_fest,
			default => $z->vn_nummer,
		};
	}

	public function ergebnis(Meldezeile $z): string {
		if ($z->meldeergebnis === null) {
			return '';
		}
		if ($z->ergebnis_format === ErgebnisFormat::ZEHNTEL) {
			return number_format($z->meldeergebnis, 1, ',', '');
		}
		return $this->ganze_ringe === 'komma_null' ? number_format($z->meldeergebnis, 1, ',', '') : (string) (int) round($z->meldeergebnis);
	}

	private function datum(string $ymd): string {
		$d = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
		return $d === false ? $ymd : $d->format('d.m.Y');
	}

	/**
	 * @param string[] $felder
	 */
	private function zeile(array $felder, string $sep): string {
		$out = [];
		foreach ($felder as $f) {
			$f = str_replace(["\r", "\n"], ' ', $f);
			if (str_contains($f, $sep) || str_contains($f, '"')) {
				$f = '"' . str_replace('"', '""', $f) . '"';
			}
			$out[] = $f;
		}
		return implode($sep, $out);
	}

	private function kodieren(string $utf8): string {
		return match ($this->zeichensatz) {
			'windows-1252' => (string) iconv('UTF-8', 'Windows-1252//TRANSLIT', $utf8),
			'utf-8-bom' => "\xEF\xBB\xBF" . $utf8,
			default => $utf8,
		};
	}
}
