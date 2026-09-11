<?php
/**
 * Baut ein Regelwerk aus Seed-Gruppen (Klassensatz) und einem Regeltabellen-Dokument –
 * ohne Datenbank, für Tests und Prüfungen.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain\Engine;

use KSV\KMM\Domain\KlassenRef;
use KSV\KMM\Domain\RegelModus;
use KSV\KMM\Domain\Regeltabelle\Dokument;

final class RegelwerkFabrik {

	/**
	 * @param array<int, array<string, mixed>> $gruppen Struktur wie Klassensatz::gruppen()
	 */
	public static function aus_dokument(array $gruppen, Dokument $doc, int $sportjahr, string $stichtag, bool $fitasc_damen = true, bool $tarif_override_ignorieren = false): Regelwerk {
		$rw = new Regelwerk($sportjahr, $stichtag, $doc->tarife, $fitasc_damen, $tarif_override_ignorieren);
		$ids = [];
		$next = 1;
		$alle_gruppen = $gruppen;
		foreach ($doc->gruppen as $g) {
			$alle_gruppen[] = $g;
		}
		foreach ($alle_gruppen as $g) {
			$rw->gruppe_hinzufuegen($g['code'], $g['hoehermeldung_bereich'], (bool) $g['ist_para']);
			foreach ($g['klassen'] as $k) {
				$ref = KlassenRef::make($g['code'], (int) $k['nummer'], (string) $k['geschlecht'])->key();
				if (isset($ids[ $ref ])) {
					continue;
				}
				$ids[ $ref ] = $next++;
				$rw->klasse_hinzufuegen(new Klasse(
					$ids[ $ref ],
					$g['code'],
					(int) $k['nummer'],
					(string) $k['geschlecht'],
					(string) $k['bezeichnung'],
					$k['alter_von'] !== null ? (int) $k['alter_von'] : null,
					$k['alter_bis'] !== null ? (int) $k['alter_bis'] : null,
					(string) $k['tarifstufe'],
					$k['stufe'] !== null && $k['stufe'] !== '' ? (string) $k['stufe'] : null,
					(bool) ($k['ist_para'] ?? false),
					(bool) ($k['ist_teamklasse'] ?? false),
					(bool) ($k['festgeschrieben'] ?? false),
				));
			}
		}
		$did = 1;
		foreach ($doc->disziplinen as $d) {
			$disziplin = new Disziplin(
				$did,
				$d['kennzahl'],
				$d['gruppe'],
				$d['bezeichnung'],
				$d['typ'],
				(bool) $d['angeboten'],
				(int) $d['mannschaft_groesse'],
				$d['ergebnis_format'],
				$d['tarif_override'],
				(float) $d['mannschaft_startgeld'],
				$d['mixteam_kennzahl_modus'],
			);
			$rw->disziplin_hinzufuegen($disziplin);
			foreach ($d['regeln'] as $r) {
				$klasse_id = $ids[ $r['klasse']->key() ] ?? null;
				if ($klasse_id === null) {
					throw new \RuntimeException(sprintf('Disziplin %s: Klasse %s unbekannt.', $d['kennzahl'], $r['klasse']->toString()));
				}
				$rw->regel_hinzufuegen(new Regel(
					$did,
					$klasse_id,
					$r['einzel'],
					$r['einzel'] === RegelModus::VERWEIS && $r['einzel_ziel'] instanceof KlassenRef ? ($ids[ $r['einzel_ziel']->key() ] ?? null) : null,
					$r['mannschaft'],
					$r['mannschaft'] === RegelModus::VERWEIS && $r['mannschaft_ziel'] instanceof KlassenRef ? ($ids[ $r['mannschaft_ziel']->key() ] ?? null) : null,
					$r['mindestalter'],
					$r['hinweis'],
				));
			}
			$did++;
		}
		return $rw;
	}
}
