<?php
/**
 * Eingabe der Regel-Engine: ein Schütze mit seinen Höhermeldungen und optional der
 * für die Meldung gewählten Para-Klasse.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain\Engine;

final class Schuetze {

	/**
	 * @param string                $geburtsdatum   Y-m-d
	 * @param string                $geschlecht     m / w
	 * @param array<string, string> $hoehermeldungen Bereich => Zielstufe (z. B. ['uebrige' => 'hd1'])
	 * @param int|null              $para_klasse_id gewählte Para-Klasse (nur an der Meldung)
	 */
	public function __construct(
		public readonly string $geburtsdatum,
		public readonly string $geschlecht,
		public readonly array $hoehermeldungen = [],
		public readonly ?int $para_klasse_id = null,
	) {
	}

	public function geburtsjahr(): int {
		return (int) substr($this->geburtsdatum, 0, 4);
	}

	public function mit_para_klasse(?int $para_klasse_id): self {
		return new self($this->geburtsdatum, $this->geschlecht, $this->hoehermeldungen, $para_klasse_id);
	}

	/** Volles Alter am Stichtag (Y-m-d). */
	public function alter_am(string $stichtag): int {
		$geb = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->geburtsdatum);
		$tag = \DateTimeImmutable::createFromFormat('!Y-m-d', $stichtag);
		if ($geb === false || $tag === false) {
			return 0;
		}
		return (int) $geb->diff($tag)->y;
	}
}
