<?php
/**
 * Ein Ergebnisplatz: Disziplin × Klasse × Art (Einzel/Mannschaft), oder das
 * Sammelergebnis einer Disziplin über alle Klassen.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain\Ergebnis;

final class Platz {

	public function __construct(
		public readonly string $art,
		public readonly string $schluessel,
		public readonly string $kennzahl,
		public readonly string $disziplin_text,
		public readonly string $klasse_text,
		public readonly ?int $disziplin_id = null,
		public readonly ?int $klasse_id = null,
		public readonly int $klasse_sortierung = 0,
	) {
		Schluessel::art_pruefen($art);
	}

	public function ist_sammel(): bool {
		return Schluessel::ist_sammel($this->schluessel);
	}

	public function kategorie(): int {
		return Schluessel::kategorie($this->schluessel);
	}

	public function disziplin_schluessel(): string {
		return Schluessel::disziplin_teil($this->schluessel);
	}

	/** Eindeutig je Jahr: e:1_10_10 */
	public function id(): string {
		return $this->art . ':' . $this->schluessel;
	}

	public function dateiname(string $endung = 'pdf'): string {
		return Schluessel::dateiname($this->art, $this->schluessel, $endung);
	}

	public function art_text(): string {
		return $this->art === Schluessel::EINZEL ? 'Einzel' : 'Mannschaft';
	}

	/** „1.10 Luftgewehr · Herren I · Einzel“ */
	public function beschriftung(): string {
		$teile = array_filter([trim($this->kennzahl . ' ' . $this->disziplin_text), $this->klasse_text, $this->art_text()], static fn(string $t): bool => $t !== '');
		return implode(' · ', $teile);
	}

	/** Sortierung: Disziplin, darin Sammelergebnis zuerst, dann Klassen, Einzel vor Mannschaft. */
	public function sortierung(): string {
		return Schluessel::sortierung($this->disziplin_schluessel())
			. '|' . ($this->ist_sammel() ? '0' : '1')
			. '|' . sprintf('%05d', $this->klasse_sortierung)
			. '|' . Schluessel::sortierung($this->schluessel)
			. '|' . $this->art;
	}
}
