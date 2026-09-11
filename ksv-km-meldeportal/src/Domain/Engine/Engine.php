<?php
/**
 * Regel-Engine: Klassenberechnung, Höhermeldung, Startrecht, Startklasse,
 * Mannschaftspool, Startgeld, Kennzahl. Reines PHP ohne WordPress.
 *
 * Alter = Sportjahr − Geburtsjahr. Verweise werden als Kette bis zu einer Klasse mit
 * eigener Wertung aufgelöst. Mindestalter wird mit dem vollen Geburtsdatum zum
 * Stichtag (Meldeschluss) geprüft.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain\Engine;

use KSV\KMM\Domain\Geschlecht;
use KSV\KMM\Domain\MixteamKennzahlModus;
use KSV\KMM\Domain\RegelModus;

final class Engine {

	public function __construct(private readonly Regelwerk $rw) {
	}

	public function regelwerk(): Regelwerk {
		return $this->rw;
	}

	public function alter(Schuetze $s): int {
		return $this->rw->sportjahr - $s->geburtsjahr();
	}

	// ----- Klassenberechnung ------------------------------------------------------

	/**
	 * Altersklasse einer Gruppe ohne Höhermeldung und ohne Para.
	 */
	public function altersklasse(string $gruppe, Schuetze $s): ?Klasse {
		$alter = $this->alter($s);
		$treffer = [];
		foreach ($this->rw->klassen_der_gruppe($gruppe) as $k) {
			if ($k->ist_altersklasse() && $k->passt_zu_geschlecht($s->geschlecht) && $k->passt_zu_alter($alter)) {
				$treffer[] = $k;
			}
		}
		if ($treffer === []) {
			return null;
		}
		if (count($treffer) === 1) {
			return $treffer[0];
		}
		// Mehrdeutig (FITASC Damen 61 vs. Senioren 62 für w ab 56): Einstellung entscheidet.
		foreach ($treffer as $k) {
			$spezifisch = $k->geschlecht !== Geschlecht::BEIDE;
			if ($spezifisch === $this->rw->fitasc_damen_bevorzugen) {
				return $k;
			}
		}
		return $treffer[0];
	}

	/**
	 * Eigentliche Klasse für eine Disziplin: Para (gewählt) oder Altersklasse, ggf. mit
	 * Höhermeldung übersteuert.
	 *
	 * @return array{0: ?Klasse, 1: bool, 2: string} Klasse, Höhermeldung angewendet, Hinweis
	 */
	public function klasse_fuer(Disziplin $d, Schuetze $s): array {
		if ($s->para_klasse_id !== null) {
			$para = $this->rw->klasse($s->para_klasse_id);
			if ($para === null || !$para->ist_para) {
				return [null, false, 'Ungültige Para-Klasse.'];
			}
			if (!$para->passt_zu_geschlecht($s->geschlecht)) {
				return [null, false, sprintf('Para-Klasse %s gilt nicht für %s.', $para->bezeichnung, Geschlecht::label($s->geschlecht))];
			}
			return [$para, false, ''];
		}

		$klasse = $this->altersklasse($d->gruppe, $s);
		if ($klasse === null) {
			return [null, false, ''];
		}
		if ($klasse->festgeschrieben) {
			return [$klasse, false, ''];
		}
		$bereich = $this->rw->hoehermeldung_bereich($d->gruppe);
		if ($bereich === null || !isset($s->hoehermeldungen[ $bereich ])) {
			return [$klasse, false, ''];
		}
		$ziel = $this->klasse_nach_stufe($d->gruppe, (string) $s->hoehermeldungen[ $bereich ], $s->geschlecht);
		if ($ziel === null || $ziel->id === $klasse->id) {
			return [$klasse, false, ''];
		}
		if (!$this->ist_hoeher($ziel, $klasse)) {
			return [$klasse, false, sprintf('Höhermeldung auf %s ignoriert (nicht höher als %s).', $ziel->bezeichnung, $klasse->bezeichnung)];
		}
		return [$ziel, true, sprintf('Höhermeldung: %s statt %s.', $ziel->bezeichnung, $klasse->bezeichnung)];
	}

	private function klasse_nach_stufe(string $gruppe, string $stufe, string $geschlecht): ?Klasse {
		$allgemein = null;
		foreach ($this->rw->klassen_der_gruppe($gruppe) as $k) {
			if ($k->stufe !== $stufe || !$k->ist_altersklasse() || !$k->passt_zu_geschlecht($geschlecht)) {
				continue;
			}
			if ($k->geschlecht === $geschlecht) {
				return $k;
			}
			$allgemein = $k;
		}
		return $allgemein;
	}

	/**
	 * „Höher“ im Sinne der SpO: Junioren (und andere Jugendklassen, sofern nicht
	 * festgeschrieben) dürfen nur in die Basisklasse der Erwachsenen (jüngste Klasse ab 21,
	 * z. B. Herren/Damen I). Erwachsene dürfen in jede jüngere Erwachsenenklasse
	 * (Herren III → Herren II oder I, Senioren II → Senioren I oder 0).
	 */
	private function ist_hoeher(Klasse $ziel, Klasse $aktuell): bool {
		if ($ziel->festgeschrieben || $ziel->alter_von === null || $ziel->alter_von < 21 || $ziel->gruppe !== $aktuell->gruppe) {
			return false;
		}
		$aktuell_jugend = $aktuell->alter_bis !== null && $aktuell->alter_bis <= 20;
		if ($aktuell_jugend) {
			return $ziel->id === $this->basisklasse($aktuell->gruppe, $ziel->geschlecht === Geschlecht::BEIDE ? $aktuell->geschlecht : $ziel->geschlecht)?->id;
		}
		return $ziel->alter_von < ($aktuell->alter_von ?? 0);
	}

	/** Jüngste Erwachsenenklasse (alter_von >= 21) einer Gruppe für ein Geschlecht. */
	private function basisklasse(string $gruppe, string $geschlecht): ?Klasse {
		$basis = null;
		foreach ($this->rw->klassen_der_gruppe($gruppe) as $k) {
			if (!$k->ist_altersklasse() || $k->festgeschrieben || $k->alter_von === null || $k->alter_von < 21 || !$k->passt_zu_geschlecht($geschlecht)) {
				continue;
			}
			if ($basis === null || $k->alter_von < $basis->alter_von || ($k->alter_von === $basis->alter_von && $k->geschlecht === $geschlecht)) {
				$basis = $k;
			}
		}
		return $basis;
	}

	/**
	 * Wählbare Zielstufen für eine Höhermeldung in einem Bereich.
	 *
	 * @return array<string, string> Stufe => Bezeichnung (leer, wenn keine Höhermeldung möglich)
	 */
	public function hoehermeldung_angebot(string $bereich, Schuetze $s): array {
		$gruppe = Regelwerk::LEITGRUPPE[ $bereich ] ?? null;
		if ($gruppe === null) {
			return [];
		}
		$ohne = new Schuetze($s->geburtsdatum, $s->geschlecht, [], null);
		$aktuell = $this->altersklasse($gruppe, $ohne);
		if ($aktuell === null || $aktuell->festgeschrieben) {
			return [];
		}
		$angebot = [];
		foreach ($this->rw->klassen_der_gruppe($gruppe) as $k) {
			if ($k->stufe === null || $k->stufe === $aktuell->stufe || !$k->ist_altersklasse() || !$k->passt_zu_geschlecht($s->geschlecht)) {
				continue;
			}
			if ($this->ist_hoeher($k, $aktuell)) {
				$angebot[ $k->stufe ] = $k->bezeichnung;
			}
		}
		return $angebot;
	}

	// ----- Bewertung -------------------------------------------------------------------

	public function bewerte(Disziplin $d, Schuetze $s): Bewertung {
		[$klasse, $hoeher, $hinweis] = $this->klasse_fuer($d, $s);
		$hinweise = $hinweis !== '' ? [$hinweis] : [];

		if ($klasse === null) {
			return $this->ohne_startrecht($d, null, $hinweis !== '' ? $hinweis : 'Keine passende Klasse in dieser Gruppe.', $hinweise);
		}
		$regel = $this->rw->regel($d->id, $klasse->id);
		if ($regel === null) {
			return $this->ohne_startrecht($d, $klasse, sprintf('Kein Startrecht für %s.', $klasse->bezeichnung), $hinweise);
		}
		if ($regel->hinweis !== '') {
			$hinweise[] = $regel->hinweis;
		}
		if ($regel->mindestalter !== null && $s->alter_am($this->rw->stichtag) < $regel->mindestalter) {
			return $this->ohne_startrecht($d, $klasse, sprintf('Mindestalter %d Jahre am %s nicht erreicht.', $regel->mindestalter, $this->stichtag_de()), $hinweise);
		}

		if ($d->ist_mixteam()) {
			$pool = $this->aufloesen($d, $klasse, 'mannschaft', $hinweise);
			if ($pool === null) {
				return $this->ohne_startrecht($d, $klasse, sprintf('Kein Startrecht für %s.', $klasse->bezeichnung), $hinweise);
			}
			$kennzahl_klasse = $d->mixteam_kennzahl_modus === MixteamKennzahlModus::GESCHLECHT ? $klasse : $pool;
			return new Bewertung($d, $klasse, true, null, $pool, $hoeher, 0.0, $this->kennzahl($d, $kennzahl_klasse), $hinweise);
		}

		$startklasse = $this->aufloesen($d, $klasse, 'einzel', $hinweise);
		if ($startklasse === null) {
			return $this->ohne_startrecht($d, $klasse, sprintf('Kein Startrecht für %s.', $klasse->bezeichnung), $hinweise);
		}
		$pool = $d->hat_mannschaften() ? $this->aufloesen($d, $klasse, 'mannschaft', $hinweise) : null;

		return new Bewertung($d, $klasse, true, $startklasse, $pool, $hoeher, $this->startgeld($d, $startklasse), $this->kennzahl($d, $startklasse), $hinweise);
	}

	/**
	 * Löst Einzel- bzw. Mannschaftsverweise als Kette bis zu einer Klasse mit eigener
	 * Wertung auf. Endet die Kette ohne eigene Wertung, gilt die zuletzt genannte Klasse
	 * (mit Hinweis), damit ein lückenhafter Plan kein Startrecht verschluckt.
	 *
	 * @param string[] $hinweise
	 */
	private function aufloesen(Disziplin $d, Klasse $start, string $teil, array &$hinweise): ?Klasse {
		$aktuell = $start;
		$besucht = [];
		for ($i = 0; $i < 10; $i++) {
			$besucht[ $aktuell->id ] = true;
			$regel = $this->rw->regel($d->id, $aktuell->id);
			$modus = $regel === null ? RegelModus::KEINE : ($teil === 'einzel' ? $regel->einzel_modus : $regel->mannschaft_modus);
			$ziel_id = $regel === null ? null : ($teil === 'einzel' ? $regel->einzel_ziel_klasse_id : $regel->mannschaft_ziel_klasse_id);

			if ($modus === RegelModus::EIGEN) {
				return $aktuell;
			}
			if ($modus === RegelModus::KEINE) {
				if ($aktuell->id === $start->id) {
					return null;
				}
				$hinweise[] = sprintf('%s: Verweis endet bei %s ohne eigene Wertung.', $teil === 'einzel' ? 'Einzel' : 'Mannschaft', $aktuell->bezeichnung);
				return $aktuell;
			}
			$ziel = $ziel_id !== null ? $this->rw->klasse($ziel_id) : null;
			if ($ziel === null) {
				$hinweise[] = sprintf('%s: Verweis von %s ohne Zielklasse.', $teil === 'einzel' ? 'Einzel' : 'Mannschaft', $aktuell->bezeichnung);
				return $aktuell->id === $start->id ? null : $aktuell;
			}
			if (isset($besucht[ $ziel->id ])) {
				$hinweise[] = sprintf('%s: zirkulärer Verweis bei %s.', $teil === 'einzel' ? 'Einzel' : 'Mannschaft', $ziel->bezeichnung);
				return $ziel;
			}
			$aktuell = $ziel;
		}
		return $aktuell;
	}

	/**
	 * @param string[] $hinweise
	 */
	private function ohne_startrecht(Disziplin $d, ?Klasse $klasse, string $grund, array $hinweise): Bewertung {
		return new Bewertung($d, $klasse, false, null, null, false, 0.0, '', $hinweise, $grund);
	}

	// ----- Startgeld und Kennzahl ------------------------------------------------------

	public function startgeld(Disziplin $d, Klasse $startklasse): float {
		if ($d->ist_mixteam()) {
			return 0.0;
		}
		if ($d->tarif_override !== null && !$this->rw->tarif_override_ignorieren) {
			return round($d->tarif_override, 2);
		}
		return round($this->rw->tarif($startklasse->tarifstufe), 2);
	}

	public function kennzahl(Disziplin $d, Klasse $klasse): string {
		return $d->kennzahl . '.' . $klasse->nummer;
	}

	private function stichtag_de(): string {
		$t = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->rw->stichtag);
		return $t === false ? $this->rw->stichtag : $t->format('d.m.Y');
	}

	// ----- Disziplinangebot ----------------------------------------------------------------

	/**
	 * Alle angebotenen Disziplinen mit Startrecht für einen Schützen (ohne Para-Wahl).
	 *
	 * @return list<Bewertung>
	 */
	public function angebot(Schuetze $s): array {
		$out = [];
		foreach ($this->rw->disziplinen() as $d) {
			if (!$d->angeboten) {
				continue;
			}
			$b = $this->bewerte($d, $s);
			if ($b->startrecht) {
				$out[] = $b;
			}
		}
		return $out;
	}

	/**
	 * Para-Klassen, mit denen ein Schütze in einer Disziplin starten dürfte.
	 *
	 * @return list<Klasse>
	 */
	public function para_angebot(Disziplin $d, Schuetze $s): array {
		$out = [];
		foreach ($this->rw->para_klassen() as $p) {
			if (!$p->passt_zu_geschlecht($s->geschlecht)) {
				continue;
			}
			if ($this->bewerte($d, $s->mit_para_klasse($p->id))->startrecht) {
				$out[] = $p;
			}
		}
		return $out;
	}
}
