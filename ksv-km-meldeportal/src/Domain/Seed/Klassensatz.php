<?php
/**
 * Seed-Daten: Wettbewerbsgruppen, Klassen und Standardtarife nach Konzept Abschnitt 4.1
 * und 11.3. Reines PHP, wird beim Anlegen eines Sportjahres eingespielt.
 *
 * Klassenschlüssel ist Gruppe + Nummer + Geschlecht. "stufe" ist die gruppenübergreifende
 * Klassenstufe für Höhermeldungen (z. B. hd1 = Herren/Damen I in Freihand und Blasrohr).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain\Seed;

use KSV\KMM\Domain\Geschlecht;
use KSV\KMM\Domain\HoehermeldungBereich;
use KSV\KMM\Domain\Tarifstufe;

final class Klassensatz {

	public const GRUPPE_FREIHAND       = 'freihand';
	public const GRUPPE_AUFLAGE        = 'auflage';
	public const GRUPPE_FITASC         = 'fitasc';
	public const GRUPPE_LICHTSCHIESSEN = 'lichtschiessen';
	public const GRUPPE_BLASROHR       = 'blasrohr';
	public const GRUPPE_BOGEN          = 'bogen';
	public const GRUPPE_PARA           = 'para';

	/**
	 * @return array<string, float> Tarifstufe => Betrag
	 */
	public static function tarife(): array {
		return Tarifstufe::STANDARD;
	}

	/**
	 * @return array<int, array{code: string, bezeichnung: string, hoehermeldung_bereich: ?string, ist_para: bool, sortierung: int, klassen: array<int, array<string, mixed>>}>
	 */
	public static function gruppen(): array {
		return [
			[
				'code'                  => self::GRUPPE_FREIHAND,
				'bezeichnung'           => 'Gewehr, Pistole, Flinte, Vorderlader (Freihand)',
				'hoehermeldung_bereich' => HoehermeldungBereich::UEBRIGE,
				'ist_para'              => false,
				'sortierung'            => 10,
				'klassen'               => array_merge(
					self::schueler_jugend_junioren(1),
					self::herren_damen(10),
					[
						self::team(40, 'Team Junioren', Tarifstufe::JUGEND, 30),
						self::team(10, 'Team Damen/Herren', Tarifstufe::ERWACHSENE, 31),
					]
				),
			],
			[
				'code'                  => self::GRUPPE_AUFLAGE,
				'bezeichnung'           => 'Auflage',
				'hoehermeldung_bereich' => HoehermeldungBereich::AUFLAGE,
				'ist_para'              => false,
				'sortierung'            => 20,
				'klassen'               => self::flat([
					self::mw(50, 'Senioren 0', 41, 50, Tarifstufe::ERWACHSENE, 'sen0', 1, false, 'nur bis LV'),
					self::mw(70, 'Senioren I', 51, 60, Tarifstufe::ERWACHSENE, 'sen1', 2),
					self::mw(72, 'Senioren II', 61, 65, Tarifstufe::ERWACHSENE, 'sen2', 3),
					self::mw(74, 'Senioren III', 66, 70, Tarifstufe::ERWACHSENE, 'sen3', 4),
					self::mw(76, 'Senioren IV', 71, 75, Tarifstufe::ERWACHSENE, 'sen4', 5),
					self::mw(78, 'Senioren V', 76, 80, Tarifstufe::ERWACHSENE, 'sen5', 6),
					self::mw(80, 'Senioren VI', 81, null, Tarifstufe::ERWACHSENE, 'sen6', 7),
				]),
			],
			[
				'code'                  => self::GRUPPE_FITASC,
				'bezeichnung'           => 'Flinte FITASC',
				'hoehermeldung_bereich' => HoehermeldungBereich::UEBRIGE,
				'ist_para'              => false,
				'sortierung'            => 30,
				'klassen'               => self::flat([
					self::klasse(68, Geschlecht::BEIDE, 'Junioren', 15, 20, Tarifstufe::JUGEND, 'jun1', 1, false),
					self::klasse(60, Geschlecht::M, 'Herren', 21, 55, Tarifstufe::ERWACHSENE, 'hd1', 2),
					self::klasse(61, Geschlecht::W, 'Damen', 21, null, Tarifstufe::ERWACHSENE, 'hd1', 3),
					// 62/64/66 gelten für beide Geschlechter; ob Schützinnen ab 56 hier oder in
					// Damen (61) starten, entscheidet die Einstellung fitasc_damen_ab_56 (Konzept 13).
					self::klasse(62, Geschlecht::BEIDE, 'Senioren', 56, 65, Tarifstufe::ERWACHSENE, 'fitasc_sen', 4),
					self::klasse(64, Geschlecht::BEIDE, 'Veteranen', 66, 72, Tarifstufe::ERWACHSENE, 'fitasc_vet', 5),
					self::klasse(66, Geschlecht::BEIDE, 'Master', 73, null, Tarifstufe::ERWACHSENE, 'fitasc_master', 6),
				]),
			],
			[
				'code'                  => self::GRUPPE_LICHTSCHIESSEN,
				'bezeichnung'           => 'Lichtschießen',
				'hoehermeldung_bereich' => HoehermeldungBereich::UEBRIGE,
				'ist_para'              => false,
				'sortierung'            => 40,
				'klassen'               => self::flat([
					self::mw(26, 'Schüler IV', 6, 8, Tarifstufe::SCHUELER, 'schueler4', 1, true, 'Mindestalter 6 Jahre'),
					self::mw(24, 'Schüler III', 9, 10, Tarifstufe::SCHUELER, 'schueler3', 2, true),
					self::mw(22, 'Schüler II', 11, 12, Tarifstufe::SCHUELER, 'schueler2', 3, true),
					self::mw(20, 'Schüler I', 13, 14, Tarifstufe::SCHUELER, 'schueler1', 4, true),
				]),
			],
			[
				'code'                  => self::GRUPPE_BLASROHR,
				'bezeichnung'           => 'Blasrohr',
				'hoehermeldung_bereich' => HoehermeldungBereich::UEBRIGE,
				'ist_para'              => false,
				'sortierung'            => 50,
				'klassen'               => array_merge(
					self::flat([
						self::mw(24, 'Schüler III', null, 10, Tarifstufe::SCHUELER, 'schueler3', 1, true),
						self::mw(22, 'Schüler II', 11, 12, Tarifstufe::SCHUELER, 'schueler2', 2, true),
						self::mw(20, 'Schüler I', 13, 14, Tarifstufe::SCHUELER, 'schueler1', 3, true),
					]),
					self::jugend_junioren(4),
					self::herren_damen(20)
				),
			],
			[
				'code'                  => self::GRUPPE_BOGEN,
				'bezeichnung'           => 'Bogen',
				'hoehermeldung_bereich' => HoehermeldungBereich::BOGEN,
				'ist_para'              => false,
				'sortierung'            => 60,
				'klassen'               => self::flat([
					self::mw(24, 'Schüler C', null, 10, Tarifstufe::SCHUELER, 'schueler3', 1, true),
					self::mw(22, 'Schüler B', 11, 12, Tarifstufe::SCHUELER, 'schueler2', 2, true),
					self::mw(20, 'Schüler A', 13, 14, Tarifstufe::SCHUELER, 'schueler1', 3, true),
					self::mw(30, 'Jugend', 15, 17, Tarifstufe::JUGEND, 'jugend', 4, true),
					self::mw(40, 'Junioren', 18, 20, Tarifstufe::JUGEND, 'jun1', 5),
					self::mw(10, 'Herren/Damen', 21, 49, Tarifstufe::ERWACHSENE, 'hd1', 6, false, '', ['Herren', 'Damen']),
					self::mw(12, 'Master', 50, 65, Tarifstufe::ERWACHSENE, 'bogen_master', 7),
					self::mw(14, 'Senioren', 66, null, Tarifstufe::ERWACHSENE, 'bogen_sen', 8),
				]),
			],
			[
				'code'                  => self::GRUPPE_PARA,
				'bezeichnung'           => 'Para',
				'hoehermeldung_bereich' => null,
				'ist_para'              => true,
				'sortierung'            => 70,
				'klassen'               => self::flat([
					self::para(90, Geschlecht::BEIDE, 'SH2/AB2 m/w mit HM', 1),
					self::para(92, Geschlecht::M, 'SH1/AB1 m ohne HM', 2),
					self::para(93, Geschlecht::W, 'SH1/AB1 w ohne HM', 3),
					self::para(94, Geschlecht::BEIDE, 'AB3 m/w mit HM', 4),
					self::para(96, Geschlecht::BEIDE, 'SH3 m/w ohne HM', 5),
				]),
			],
		];
	}

	/**
	 * Schüler, Jugend, Junioren II, Junioren I (Freihand-Nummern).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function schueler_jugend_junioren(int $sort): array {
		return array_merge(
			self::mw(20, 'Schüler', null, 14, Tarifstufe::SCHUELER, 'schueler', $sort, true),
			self::jugend_junioren($sort + 1)
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function jugend_junioren(int $sort): array {
		return self::flat([
			self::mw(30, 'Jugend', 15, 16, Tarifstufe::JUGEND, 'jugend', $sort, true),
			self::mw(42, 'Junioren II', 17, 18, Tarifstufe::JUGEND, 'jun2', $sort + 1),
			self::mw(40, 'Junioren I', 19, 20, Tarifstufe::JUGEND, 'jun1', $sort + 2),
		]);
	}

	/**
	 * Flacht eine Liste aus Einzelklassen und Klassenpaaren (mw) auf eine Ebene ab.
	 *
	 * @param array<int, array<string, mixed>|array<int, array<string, mixed>>> $eintraege
	 * @return array<int, array<string, mixed>>
	 */
	private static function flat(array $eintraege): array {
		$out = [];
		foreach ($eintraege as $e) {
			if (isset($e['nummer'])) {
				$out[] = $e;
			} else {
				foreach ($e as $k) {
					$out[] = $k;
				}
			}
		}
		return $out;
	}

	/**
	 * Herren/Damen I–V (10–19).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function herren_damen(int $sort): array {
		$out = [];
		$stufen = [
			[10, 'I', 21, 40],
			[12, 'II', 41, 50],
			[14, 'III', 51, 60],
			[16, 'IV', 61, 70],
			[18, 'V', 71, null],
		];
		foreach ($stufen as $i => [$nummer, $roemisch, $von, $bis]) {
			$out[] = self::klasse($nummer, Geschlecht::M, 'Herren ' . $roemisch, $von, $bis, Tarifstufe::ERWACHSENE, 'hd' . ($i + 1), $sort + $i * 2);
			$out[] = self::klasse($nummer + 1, Geschlecht::W, 'Damen ' . $roemisch, $von, $bis, Tarifstufe::ERWACHSENE, 'hd' . ($i + 1), $sort + $i * 2 + 1);
		}
		return $out;
	}

	/**
	 * Klassenpaar m/w mit aufeinanderfolgenden Nummern.
	 *
	 * @param array{0: string, 1: string}|null $namen Abweichende Bezeichnungen für m und w.
	 * @return array<int, array<string, mixed>>
	 */
	private static function mw(int $nummer, string $bezeichnung, ?int $von, ?int $bis, string $tarif, ?string $stufe, int $sort, bool $festgeschrieben = false, string $hinweis = '', ?array $namen = null): array {
		return [
			self::klasse($nummer, Geschlecht::M, $namen[0] ?? $bezeichnung . ' m', $von, $bis, $tarif, $stufe, $sort * 2, $festgeschrieben, $hinweis),
			self::klasse($nummer + 1, Geschlecht::W, $namen[1] ?? $bezeichnung . ' w', $von, $bis, $tarif, $stufe, $sort * 2 + 1, $festgeschrieben, $hinweis),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function klasse(int $nummer, string $geschlecht, string $bezeichnung, ?int $von, ?int $bis, string $tarif, ?string $stufe, int $sort, bool $festgeschrieben = false, string $hinweis = ''): array {
		return [
			'nummer'          => $nummer,
			'geschlecht'      => $geschlecht,
			'bezeichnung'     => $bezeichnung,
			'alter_von'       => $von,
			'alter_bis'       => $bis,
			'tarifstufe'      => $tarif,
			'stufe'           => $stufe,
			'ist_para'        => false,
			'ist_teamklasse'  => false,
			'festgeschrieben' => $festgeschrieben,
			'hinweis'         => $hinweis,
			'sortierung'      => $sort,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function team(int $nummer, string $bezeichnung, string $tarif, int $sort): array {
		$k = self::klasse($nummer, Geschlecht::BEIDE, $bezeichnung, null, null, $tarif, null, $sort * 2);
		$k['ist_teamklasse'] = true;
		return $k;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function para(int $nummer, string $geschlecht, string $bezeichnung, int $sort): array {
		$k = self::klasse($nummer, $geschlecht, $bezeichnung, null, null, Tarifstufe::ERWACHSENE, null, $sort);
		$k['ist_para'] = true;
		return $k;
	}

	/**
	 * Klasse einer Gruppe nach Alter und Geschlecht (ohne Höhermeldung, ohne Para).
	 * Bei mehreren Treffern (z. B. FITASC Damen 61 und Senioren 62 für w ab 56) gewinnt die
	 * geschlechtsspezifische Klasse, wenn $bevorzuge_geschlechtsspezifisch gesetzt ist,
	 * sonst die Klasse für beide Geschlechter.
	 *
	 * @param array<int, array<string, mixed>> $klassen
	 * @return array<string, mixed>|null
	 */
	public static function finde_klasse(array $klassen, int $alter, string $geschlecht, bool $bevorzuge_geschlechtsspezifisch = true): ?array {
		$treffer = [];
		foreach ($klassen as $k) {
			if (!empty($k['ist_para']) || !empty($k['ist_teamklasse'])) {
				continue;
			}
			if (!Geschlecht::matches((string) $k['geschlecht'], $geschlecht)) {
				continue;
			}
			if ($k['alter_von'] !== null && $alter < (int) $k['alter_von']) {
				continue;
			}
			if ($k['alter_bis'] !== null && $alter > (int) $k['alter_bis']) {
				continue;
			}
			$treffer[] = $k;
		}
		if ($treffer === []) {
			return null;
		}
		if (count($treffer) === 1) {
			return $treffer[0];
		}
		foreach ($treffer as $k) {
			$spezifisch = $k['geschlecht'] !== Geschlecht::BEIDE;
			if ($spezifisch === $bevorzuge_geschlechtsspezifisch) {
				return $k;
			}
		}
		return $treffer[0];
	}
}
