<?php
/**
 * Prüft die Zusammensetzung einer Mannschaft aus Bewertungen der Mitglieder.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain\Engine;

use KSV\KMM\Domain\Geschlecht;

final class MannschaftPruefung {

	/**
	 * @param list<array{bewertung: Bewertung, geschlecht: string}> $mitglieder
	 * @return string[] Fehler (leer = gültig)
	 */
	public static function pruefe(Disziplin $d, array $mitglieder, bool $vollstaendig_verlangt = true): array {
		$fehler = [];
		if (!$d->hat_mannschaften()) {
			return ['In dieser Disziplin gibt es keine Mannschaften.'];
		}
		$pool = null;
		$m = 0;
		$w = 0;
		foreach ($mitglieder as $mitglied) {
			$b = $mitglied['bewertung'];
			if (!$b->startrecht || $b->mannschaft_klasse === null) {
				$fehler[] = 'Ein Mitglied hat in dieser Disziplin keinen Mannschaftspool.';
				continue;
			}
			if ($pool === null) {
				$pool = $b->mannschaft_klasse;
			} elseif ($pool->id !== $b->mannschaft_klasse->id) {
				$fehler[] = sprintf('Mitglieder gehören zu verschiedenen Mannschaftsklassen (%s / %s).', $pool->bezeichnung, $b->mannschaft_klasse->bezeichnung);
			}
			if ($mitglied['geschlecht'] === Geschlecht::M) {
				$m++;
			} else {
				$w++;
			}
		}
		$n = count($mitglieder);
		if ($n > $d->mannschaft_groesse) {
			$fehler[] = sprintf('Höchstens %d Mitglieder erlaubt.', $d->mannschaft_groesse);
		}
		if ($vollstaendig_verlangt && $n < $d->mannschaft_groesse) {
			$fehler[] = sprintf('Mannschaft unvollständig (%d von %d).', $n, $d->mannschaft_groesse);
		}
		if ($d->ist_mixteam() && $n === $d->mannschaft_groesse && ($m !== 1 || $w !== 1)) {
			$fehler[] = 'MixTeam: genau ein Mann und eine Frau.';
		}
		return array_values(array_unique($fehler));
	}

	/**
	 * Darf dieser Schütze (Bewertung) in die Mannschaft aufgenommen werden?
	 *
	 * @param list<array{bewertung: Bewertung, geschlecht: string}> $vorhanden
	 */
	public static function passt(Disziplin $d, Bewertung $kandidat, string $geschlecht, array $vorhanden): bool {
		if (!$kandidat->startrecht || $kandidat->mannschaft_klasse === null) {
			return false;
		}
		if (count($vorhanden) >= $d->mannschaft_groesse) {
			return false;
		}
		foreach ($vorhanden as $v) {
			if ($v['bewertung']->mannschaft_klasse === null || $v['bewertung']->mannschaft_klasse->id !== $kandidat->mannschaft_klasse->id) {
				return false;
			}
			if ($d->ist_mixteam() && $v['geschlecht'] === $geschlecht) {
				return false;
			}
		}
		return true;
	}
}
