<?php
/**
 * Alle Ergebnisplätze eines Jahres.
 *
 * Ab 2027 kommen sie aus dem Disziplinplan des Sportjahres (Regelwerk):
 *   - nur angebotene Disziplinen,
 *   - Einzel für jede Klasse mit Regel „eigene Wertung“ im Einzel,
 *   - Mannschaft für jede Klasse mit Regel „eigene Wertung“ in der Mannschaft,
 *   - je Disziplin zusätzlich ein Sammelergebnis „alle Klassen“ (Einzel; Mannschaft,
 *     wenn die Disziplin Mannschaftswertungen hat).
 * Für Archivjahre kommen sie aus dem Katalog (früher srd_kreis_v3).
 *
 * Reines PHP, unit-testbar.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain\Ergebnis;

use KSV\KMM\Domain\Engine\Regelwerk;
use KSV\KMM\Domain\RegelModus;

final class Platzliste {

	public const ALLE_KLASSEN = 'alle Klassen';

	/** @var array<string, Platz> id => Platz */
	private array $plaetze = [];

	/** @var list<string> Hinweise beim Aufbau, z. B. doppelte Schlüssel */
	private array $warnungen = [];

	/**
	 * @param iterable<Platz> $plaetze
	 */
	public function __construct(iterable $plaetze = []) {
		foreach ($plaetze as $p) {
			$this->hinzufuegen($p);
		}
	}

	public static function aus_regelwerk(Regelwerk $rw): self {
		$liste = new self();
		foreach ($rw->disziplinen() as $d) {
			if (!$d->angeboten) {
				continue;
			}
			try {
				$basis = Schluessel::aus_kennzahl($d->kennzahl);
			} catch (\InvalidArgumentException $e) {
				$liste->warnungen[] = $e->getMessage();
				continue;
			}
			$hat_mannschaft = false;
			$regeln = $rw->regeln_der_disziplin($d->id);
			foreach ($regeln as $klasse_id => $r) {
				$k = $rw->klasse((int) $klasse_id);
				if ($k === null) {
					continue;
				}
				foreach ([Schluessel::EINZEL => $r->einzel_modus, Schluessel::MANNSCHAFT => $r->mannschaft_modus] as $art => $modus) {
					if ($modus !== RegelModus::EIGEN) {
						continue;
					}
					if ($art === Schluessel::MANNSCHAFT) {
						$hat_mannschaft = true;
					}
					$schluessel = $basis . '_' . $k->nummer;
					if ($liste->hat($art, $schluessel)) {
						// Gleiche Nummer zweimal in einer Disziplin (z. B. Teamklasse): Zusatz T.
						$alt = $schluessel;
						$schluessel .= 'T';
						$liste->warnungen[] = sprintf('%s %s: Schlüssel %s doppelt, %s erhält den Zusatz T (%s).', $d->kennzahl, $art === 'e' ? 'Einzel' : 'Mannschaft', $alt, $k->bezeichnung, $schluessel);
						if ($liste->hat($art, $schluessel)) {
							continue;
						}
					}
					$liste->hinzufuegen(new Platz($art, $schluessel, $d->kennzahl, $d->bezeichnung, $k->bezeichnung, $d->id, $k->id, self::klassen_sortierung($k->nummer, $k->ist_teamklasse)));
				}
			}
			if ($regeln === []) {
				continue;
			}
			$liste->hinzufuegen(new Platz(Schluessel::EINZEL, $basis, $d->kennzahl, $d->bezeichnung, self::ALLE_KLASSEN, $d->id, null));
			if ($hat_mannschaft) {
				$liste->hinzufuegen(new Platz(Schluessel::MANNSCHAFT, $basis, $d->kennzahl, $d->bezeichnung, self::ALLE_KLASSEN, $d->id, null));
			}
		}
		return $liste;
	}

	/**
	 * Archiv: Katalogzeilen (schluessel, spo, disziplin_text, klasse_text). Je Zeile ein
	 * Einzel- und ein Mannschaftsplatz, je Disziplin ein Sammelplatz.
	 *
	 * @param iterable<array<string, mixed>> $katalog
	 */
	public static function aus_katalog(iterable $katalog): self {
		$liste = new self();
		$disziplinen = [];
		foreach ($katalog as $z) {
			$schluessel = Schluessel::normalisieren((string) $z['schluessel']);
			if ($schluessel === null) {
				continue;
			}
			$kennzahl = self::kennzahl_aus_spo((string) ($z['spo'] ?? ''), $schluessel);
			$dtext = (string) ($z['disziplin_text'] ?? '');
			$ktext = (string) ($z['klasse_text'] ?? '');
			if (Schluessel::ist_sammel($schluessel)) {
				$ktext = $ktext !== '' ? $ktext : self::ALLE_KLASSEN;
			} else {
				$disziplinen[ Schluessel::disziplin_teil($schluessel) ] ??= [$kennzahl, $dtext];
			}
			foreach ([Schluessel::EINZEL, Schluessel::MANNSCHAFT] as $art) {
				$liste->hinzufuegen(new Platz($art, $schluessel, $kennzahl, $dtext, $ktext, null, null, self::nummer_sortierung($schluessel)));
			}
		}
		foreach ($disziplinen as $teil => [$kennzahl, $dtext]) {
			foreach ([Schluessel::EINZEL, Schluessel::MANNSCHAFT] as $art) {
				if (!$liste->hat($art, $teil)) {
					$liste->hinzufuegen(new Platz($art, $teil, self::disziplin_kennzahl($kennzahl), $dtext, self::ALLE_KLASSEN));
				}
			}
		}
		return $liste;
	}

	public function hinzufuegen(Platz $p): void {
		$this->plaetze[ $p->id() ] = $p;
	}

	public function hat(string $art, string $schluessel): bool {
		return isset($this->plaetze[ $art . ':' . $schluessel ]);
	}

	public function finde(string $art, string $schluessel): ?Platz {
		return $this->plaetze[ $art . ':' . $schluessel ] ?? null;
	}

	/**
	 * @return list<Platz> sortiert
	 */
	public function alle(): array {
		$liste = array_values($this->plaetze);
		usort($liste, static fn(Platz $a, Platz $b): int => strcmp($a->sortierung(), $b->sortierung()));
		return $liste;
	}

	public function anzahl(?string $art = null, bool $mit_sammel = false): int {
		$n = 0;
		foreach ($this->plaetze as $p) {
			if (($art === null || $p->art === $art) && ($mit_sammel || !$p->ist_sammel())) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * @return list<string>
	 */
	public function warnungen(): array {
		return $this->warnungen;
	}

	/**
	 * Nach Disziplin gruppiert (Reihenfolge wie alle()).
	 *
	 * @return array<string, list<Platz>> disziplin_schluessel => Plätze
	 */
	public function nach_disziplin(): array {
		$out = [];
		foreach ($this->alle() as $p) {
			$out[ $p->disziplin_schluessel() ][] = $p;
		}
		return $out;
	}

	/** Teamklassen hinter die Altersklassen derselben Nummer. */
	private static function klassen_sortierung(int $nummer, bool $team): int {
		return $nummer * 10 + ($team ? 5 : 0);
	}

	private static function nummer_sortierung(string $schluessel): int {
		$teile = explode('_', $schluessel);
		preg_match('/^(\d+)([A-Z]?)$/', (string) end($teile), $m);
		$zusatz = $m[2] ?? '';
		return (int) ($m[1] ?? 0) * 10 + ($zusatz !== '' ? ord($zusatz) - 64 : 0);
	}

	/** SpO 1.10.10 → Kennzahl 1.10; fehlt die SpO, aus dem Schlüssel. */
	private static function kennzahl_aus_spo(string $spo, string $schluessel): string {
		$basis = $spo !== '' ? $spo : str_replace('_', '.', $schluessel);
		return Schluessel::ist_sammel($schluessel) ? $basis : self::disziplin_kennzahl($basis);
	}

	private static function disziplin_kennzahl(string $spo): string {
		$teile = explode('.', $spo);
		return count($teile) >= 3 ? implode('.', array_slice($teile, 0, -1)) : $spo;
	}
}
