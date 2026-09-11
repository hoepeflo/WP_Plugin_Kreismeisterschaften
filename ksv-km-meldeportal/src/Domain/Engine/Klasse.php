<?php
/**
 * Klasse (Wertobjekt der Regel-Engine).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain\Engine;

use KSV\KMM\Domain\Geschlecht;

final class Klasse {

	public function __construct(
		public readonly int $id,
		public readonly string $gruppe,
		public readonly int $nummer,
		public readonly string $geschlecht,
		public readonly string $bezeichnung,
		public readonly ?int $alter_von,
		public readonly ?int $alter_bis,
		public readonly string $tarifstufe,
		public readonly ?string $stufe,
		public readonly bool $ist_para = false,
		public readonly bool $ist_teamklasse = false,
		public readonly bool $festgeschrieben = false,
	) {
	}

	public function ref(): string {
		return $this->gruppe . ':' . $this->nummer . $this->geschlecht;
	}

	public function passt_zu_geschlecht(string $geschlecht): bool {
		return Geschlecht::matches($this->geschlecht, $geschlecht);
	}

	public function passt_zu_alter(int $alter): bool {
		if ($this->alter_von !== null && $alter < $this->alter_von) {
			return false;
		}
		if ($this->alter_bis !== null && $alter > $this->alter_bis) {
			return false;
		}
		return true;
	}

	/** Altersklasse (kein Para, kein Team). */
	public function ist_altersklasse(): bool {
		return !$this->ist_para && !$this->ist_teamklasse;
	}
}
