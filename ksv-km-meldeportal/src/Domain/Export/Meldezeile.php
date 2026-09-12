<?php
/**
 * Neutrale Exportzeile (Schütze × Disziplin), unabhängig vom Zielformat.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain\Export;

final class Meldezeile {

	public function __construct(
		public readonly string $kennzahl,            // vollständige Kennzahl, z. B. 1.10.12
		public readonly string $disziplin_kennzahl,  // 1.10
		public readonly string $disziplin,
		public readonly string $nachname,
		public readonly string $vorname,
		public readonly string $vn_nummer,
		public readonly string $vn_name,
		public readonly ?float $meldeergebnis,
		public readonly string $ergebnis_format,     // ganz | zehntel
		public readonly string $geburtsdatum,        // Y-m-d
		public readonly string $mitgliedsnummer,
		public readonly bool $nicht_meldung,
		public readonly ?int $mannschaft_nummer,
		public readonly string $klasse,              // eigentliche Klasse (Bezeichnung)
		public readonly string $startklasse,         // Startklasse (Bezeichnung)
		public readonly int $startklasse_nummer,
		public readonly string $geschlecht,
		public readonly string $status,              // Status der Vereinsmeldung
		public readonly bool $hoehermeldung = false,
		public readonly ?string $para = null,
		public readonly string $mannschaft_klasse = '',
	) {
	}
}
