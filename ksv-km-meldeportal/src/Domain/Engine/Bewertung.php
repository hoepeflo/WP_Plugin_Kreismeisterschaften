<?php
/**
 * Ergebnis der Regel-Engine für Schütze × Disziplin.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain\Engine;

final class Bewertung {

	/**
	 * @param Klasse|null  $klasse             eigentliche (berechnete bzw. gewählte Para-) Klasse
	 * @param Klasse|null  $startklasse        Einzel-Startklasse nach Auflösen der Verweise
	 * @param Klasse|null  $mannschaft_klasse  Mannschaftspool nach Auflösen der Verweise
	 * @param string[]     $hinweise           Anzeige-Texte (Regelhinweis, Höhermeldung, Kettenhinweise)
	 * @param string       $grund              Grund bei fehlendem Startrecht
	 */
	public function __construct(
		public readonly Disziplin $disziplin,
		public readonly ?Klasse $klasse,
		public readonly bool $startrecht,
		public readonly ?Klasse $startklasse,
		public readonly ?Klasse $mannschaft_klasse,
		public readonly bool $hoehermeldung_angewendet,
		public readonly float $startgeld,
		public readonly string $kennzahl,
		public readonly array $hinweise = [],
		public readonly string $grund = '',
	) {
	}

	public function hat_mannschaftspool(): bool {
		return $this->mannschaft_klasse !== null;
	}
}
