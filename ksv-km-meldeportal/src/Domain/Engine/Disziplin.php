<?php
/**
 * Disziplin (Wertobjekt der Regel-Engine).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain\Engine;

use KSV\KMM\Domain\DisziplinTyp;

final class Disziplin {

	public function __construct(
		public readonly int $id,
		public readonly string $kennzahl,
		public readonly string $gruppe,
		public readonly string $bezeichnung = '',
		public readonly string $typ = DisziplinTyp::NORMAL,
		public readonly bool $angeboten = true,
		public readonly int $mannschaft_groesse = 3,
		public readonly string $ergebnis_format = 'ganz',
		public readonly ?float $tarif_override = null,
		public readonly float $mannschaft_startgeld = 0.0,
		public readonly ?string $mixteam_kennzahl_modus = null,
	) {
	}

	public function ist_mixteam(): bool {
		return $this->typ === DisziplinTyp::MIXTEAM;
	}

	public function ist_bogen(): bool {
		return $this->typ === DisziplinTyp::BOGEN;
	}

	public function hat_mannschaften(): bool {
		return $this->mannschaft_groesse > 0 && !$this->ist_bogen();
	}
}
