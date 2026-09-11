<?php
/**
 * Textreferenz auf eine Klasse: "<nummer><geschlecht>" innerhalb einer Gruppe oder
 * "<gruppe>:<nummer><geschlecht>" gruppenübergreifend, z. B. "10m", "40x", "para:92m".
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain;

final class KlassenRef {

	public function __construct(
		public readonly ?string $gruppe,
		public readonly int $nummer,
		public readonly string $geschlecht,
	) {
	}

	/**
	 * @throws \InvalidArgumentException bei ungültigem Format.
	 */
	public static function parse(string $ref, ?string $default_gruppe = null): self {
		$ref = trim($ref);
		if (!preg_match('/^(?:([a-z][a-z0-9_]*):)?(\d{1,3})([mwx])$/', $ref, $m)) {
			throw new \InvalidArgumentException(sprintf('Ungültige Klassenreferenz "%s" (erwartet z. B. "10m", "40x" oder "para:92m").', $ref));
		}
		$gruppe = $m[1] !== '' ? $m[1] : $default_gruppe;
		return new self($gruppe, (int) $m[2], $m[3]);
	}

	public static function make(string $gruppe, int $nummer, string $geschlecht): self {
		return new self($gruppe, $nummer, $geschlecht);
	}

	public function key(): string {
		return ($this->gruppe ?? '') . ':' . $this->nummer . $this->geschlecht;
	}

	/** Kurzform ohne Gruppe, wenn die Gruppe der Kontextgruppe entspricht. */
	public function toString(?string $context_gruppe = null): string {
		$short = $this->nummer . $this->geschlecht;
		if ($this->gruppe === null || $this->gruppe === $context_gruppe) {
			return $short;
		}
		return $this->gruppe . ':' . $short;
	}
}
