<?php
/**
 * Revalidierung: Nach einer Regeländerung werden alle Einzelmeldungen des Sportjahres
 * neu bewertet; Abweichungen werden übernommen und als Konflikt markiert, damit Admin
 * und Verein sie sehen. Läuft über die Action kmm_regeln_geaendert.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Domain\Engine\Bewertung;
use KSV\KMM\Domain\Engine\Engine;
use KSV\KMM\Domain\Engine\Schuetze;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\EinzelmeldungRepository;
use KSV\KMM\Infrastructure\Repository\HoehermeldungRepository;
use KSV\KMM\Infrastructure\Repository\MannschaftRepository;
use KSV\KMM\Infrastructure\Repository\SchuetzeRepository;

final class Revalidierung {

	public static function register(): void {
		add_action('kmm_regeln_geaendert', [self::class, 'sportjahr'], 10, 1);
	}

	/**
	 * Bewertet eine Einzelmeldung neu (Schütze aus der Schützenliste, Höhermeldungen,
	 * Para-Klasse der Meldung).
	 *
	 * @param array<string, mixed>          $em
	 * @param array<string, mixed>          $schuetze
	 * @param array<string, string>         $hoehermeldungen
	 */
	public static function bewerte_einzelmeldung(Engine $engine, array $em, array $schuetze, array $hoehermeldungen): ?Bewertung {
		$disziplin = $engine->regelwerk()->disziplin((int) $em['disziplin_id']);
		if ($disziplin === null) {
			return null;
		}
		$s = new Schuetze(
			(string) $schuetze['geburtsdatum'],
			(string) $schuetze['geschlecht'],
			$hoehermeldungen,
			$em['para_klasse_id'] !== null ? (int) $em['para_klasse_id'] : null
		);
		return $engine->bewerte($disziplin, $s);
	}

	/**
	 * Felder, die aus einer Bewertung in die Einzelmeldung geschrieben werden.
	 *
	 * @return array<string, mixed>
	 */
	public static function felder(Bewertung $b): array {
		return [
			'klasse_id'                => $b->klasse?->id,
			'startklasse_id'           => $b->startklasse?->id,
			'mannschaft_klasse_id'     => $b->mannschaft_klasse?->id,
			'hoehermeldung_angewendet' => $b->hoehermeldung_angewendet,
			'startrecht'               => $b->startrecht,
			'startgeld'                => $b->startgeld,
		];
	}

	/**
	 * @return array{geprueft: int, geaendert: int, konflikte: int}
	 */
	public static function sportjahr(int $sportjahr_id): array {
		$engine = RegelwerkLader::engine($sportjahr_id, true);
		$einzel_repo = new EinzelmeldungRepository();
		$mannschaft_repo = new MannschaftRepository();
		$schuetzen = [];
		foreach ((new SchuetzeRepository())->where([]) as $s) {
			$schuetzen[ (int) $s['id'] ] = $s;
		}
		$hoeher = (new HoehermeldungRepository())->by_sportjahr($sportjahr_id);
		$mannschaften = [];
		foreach ($mannschaft_repo->where(['sportjahr_id' => $sportjahr_id]) as $m) {
			$mannschaften[ (int) $m['id'] ] = $m;
		}

		$geprueft = 0;
		$geaendert = 0;
		$konflikte = 0;
		$betroffene_mannschaften = [];
		foreach ($einzel_repo->by_sportjahr($sportjahr_id) as $em) {
			if ($em['schuetze_id'] === null || !isset($schuetzen[ (int) $em['schuetze_id'] ])) {
				continue; // anonymisiert oder Schütze gelöscht
			}
			$geprueft++;
			$b = self::bewerte_einzelmeldung($engine, $em, $schuetzen[ (int) $em['schuetze_id'] ], $hoeher[ (int) $em['schuetze_id'] ] ?? []);
			if ($b === null) {
				continue;
			}
			$neu = self::felder($b);
			$diff = [];
			foreach ($neu as $feld => $wert) {
				$alt = $em[ $feld ];
				if (is_float($wert) ? abs((float) $alt - $wert) > 0.001 : ($alt === null ? $wert !== null : (is_bool($wert) ? (bool) $alt !== $wert : (int) $alt !== (int) $wert))) {
					$diff[] = $feld;
				}
			}
			if ($diff === []) {
				continue;
			}
			$geaendert++;
			$text = self::konflikt_text($engine, $em, $b, $diff);
			$update = $neu;
			$update['konflikt'] = true;
			$update['konflikt_text'] = mb_substr($text, 0, 255);
			// Mannschaftszuordnung bleibt, wird aber als Konflikt markiert, wenn der Pool wechselt.
			if (in_array('mannschaft_klasse_id', $diff, true) && $em['mannschaft_id'] !== null) {
				$betroffene_mannschaften[ (int) $em['mannschaft_id'] ] = true;
			}
			if (!$b->startrecht && $em['mannschaft_id'] !== null) {
				$betroffene_mannschaften[ (int) $em['mannschaft_id'] ] = true;
			}
			$einzel_repo->update((int) $em['id'], $update);
			$konflikte++;
		}
		foreach (array_keys($betroffene_mannschaften) as $mid) {
			if (isset($mannschaften[ $mid ])) {
				$mannschaft_repo->update($mid, ['unvollstaendig' => true]);
			}
		}
		if ($geprueft > 0) {
			Protokoll::system('revalidierung', sprintf('Revalidierung: %d Meldungen geprüft, %d geändert (Konflikte markiert)', $geprueft, $geaendert), $sportjahr_id);
		}
		return ['geprueft' => $geprueft, 'geaendert' => $geaendert, 'konflikte' => $konflikte];
	}

	/**
	 * @param array<string, mixed> $em
	 * @param string[]             $diff
	 */
	private static function konflikt_text(Engine $engine, array $em, Bewertung $b, array $diff): string {
		$rw = $engine->regelwerk();
		$teile = [];
		if (!$b->startrecht) {
			$teile[] = 'Kein Startrecht mehr: ' . $b->grund;
		}
		foreach ($diff as $feld) {
			if ($feld === 'startrecht' && !$b->startrecht) {
				continue;
			}
			$alt = $em[ $feld ];
			$neu = self::felder($b)[ $feld ];
			$label = match ($feld) {
				'klasse_id' => 'Klasse',
				'startklasse_id' => 'Startklasse',
				'mannschaft_klasse_id' => 'Mannschaftspool',
				'startgeld' => 'Startgeld',
				'hoehermeldung_angewendet' => 'Höhermeldung',
				'startrecht' => 'Startrecht',
				default => $feld,
			};
			if (str_ends_with($feld, '_id')) {
				$alt = $alt !== null ? ($rw->klasse((int) $alt)?->bezeichnung ?? (string) $alt) : '–';
				$neu = $neu !== null ? ($rw->klasse((int) $neu)?->bezeichnung ?? (string) $neu) : '–';
			} elseif (is_float($neu)) {
				$alt = number_format((float) $alt, 2, ',', '');
				$neu = number_format($neu, 2, ',', '');
			} elseif (is_bool($neu)) {
				$alt = $alt ? 'ja' : 'nein';
				$neu = $neu ? 'ja' : 'nein';
			}
			$teile[] = sprintf('%s: %s → %s', $label, $alt, $neu);
		}
		return 'Regeländerung. ' . implode('; ', $teile);
	}
}
