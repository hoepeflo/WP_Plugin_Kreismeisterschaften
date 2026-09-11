<?php
/**
 * Regelwerk eines Sportjahres: Gruppen, Klassen, Disziplinen, Regeln, Tarife, Stichtag.
 * Reines PHP, wird aus der Datenbank (RegelwerkLader) oder aus Seed + Dokument
 * (RegelwerkFabrik) aufgebaut.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain\Engine;

use KSV\KMM\Domain\HoehermeldungBereich;
use KSV\KMM\Domain\Tarifstufe;

final class Regelwerk {

	/** @var array<string, array{hoehermeldung_bereich: ?string, ist_para: bool}> code => Gruppe */
	private array $gruppen = [];

	/** @var array<int, Klasse> */
	private array $klassen = [];

	/** @var array<string, Klasse> "gruppe:nummer+geschlecht" => Klasse */
	private array $klassen_nach_ref = [];

	/** @var array<string, list<Klasse>> gruppe => Klassen */
	private array $klassen_nach_gruppe = [];

	/** @var array<int, Disziplin> */
	private array $disziplinen = [];

	/** @var array<string, Disziplin> */
	private array $disziplinen_nach_kennzahl = [];

	/** @var array<int, array<int, Regel>> disziplin_id => klasse_id => Regel */
	private array $regeln = [];

	/** @var array<string, float> */
	private array $tarife;

	/**
	 * Führende Gruppe je Höhermeldungsbereich (bestimmt die wählbaren Zielstufen).
	 *
	 * @var array<string, string>
	 */
	public const LEITGRUPPE = [
		HoehermeldungBereich::UEBRIGE => 'freihand',
		HoehermeldungBereich::AUFLAGE => 'auflage',
		HoehermeldungBereich::BOGEN   => 'bogen',
	];

	/**
	 * @param int                  $sportjahr   Sportjahr (Alter = Sportjahr − Geburtsjahr)
	 * @param string               $stichtag    Y-m-d, Stichtag für Mindestalter (Meldeschluss)
	 * @param array<string, float> $tarife      Tarifstufe => Betrag
	 * @param bool                 $fitasc_damen_bevorzugen  Einstellung fitasc_damen_ab_56 = damen
	 * @param bool                 $tarif_override_ignorieren
	 */
	public function __construct(
		public readonly int $sportjahr,
		public readonly string $stichtag,
		array $tarife = [],
		public readonly bool $fitasc_damen_bevorzugen = true,
		public readonly bool $tarif_override_ignorieren = false,
	) {
		$this->tarife = array_merge(Tarifstufe::STANDARD, $tarife);
	}

	public function gruppe_hinzufuegen(string $code, ?string $hoehermeldung_bereich, bool $ist_para): void {
		$this->gruppen[ $code ] = ['hoehermeldung_bereich' => $hoehermeldung_bereich, 'ist_para' => $ist_para];
	}

	public function klasse_hinzufuegen(Klasse $k): void {
		$this->klassen[ $k->id ] = $k;
		$this->klassen_nach_ref[ $k->ref() ] = $k;
		$this->klassen_nach_gruppe[ $k->gruppe ][] = $k;
	}

	public function disziplin_hinzufuegen(Disziplin $d): void {
		$this->disziplinen[ $d->id ] = $d;
		$this->disziplinen_nach_kennzahl[ $d->kennzahl ] = $d;
	}

	public function regel_hinzufuegen(Regel $r): void {
		$this->regeln[ $r->disziplin_id ][ $r->klasse_id ] = $r;
	}

	public function hoehermeldung_bereich(string $gruppe): ?string {
		return $this->gruppen[ $gruppe ]['hoehermeldung_bereich'] ?? null;
	}

	public function klasse(int $id): ?Klasse {
		return $this->klassen[ $id ] ?? null;
	}

	public function klasse_nach_ref(string $gruppe, int $nummer, string $geschlecht): ?Klasse {
		return $this->klassen_nach_ref[ $gruppe . ':' . $nummer . $geschlecht ] ?? null;
	}

	/**
	 * @return list<Klasse>
	 */
	public function klassen_der_gruppe(string $gruppe): array {
		return $this->klassen_nach_gruppe[ $gruppe ] ?? [];
	}

	/**
	 * @return list<Klasse>
	 */
	public function para_klassen(): array {
		return array_values(array_filter($this->klassen, static fn(Klasse $k): bool => $k->ist_para));
	}

	public function disziplin(int $id): ?Disziplin {
		return $this->disziplinen[ $id ] ?? null;
	}

	public function disziplin_nach_kennzahl(string $kennzahl): ?Disziplin {
		return $this->disziplinen_nach_kennzahl[ $kennzahl ] ?? null;
	}

	/**
	 * @return list<Disziplin>
	 */
	public function disziplinen(): array {
		return array_values($this->disziplinen);
	}

	public function regel(int $disziplin_id, int $klasse_id): ?Regel {
		return $this->regeln[ $disziplin_id ][ $klasse_id ] ?? null;
	}

	/**
	 * @return array<int, Regel> klasse_id => Regel
	 */
	public function regeln_der_disziplin(int $disziplin_id): array {
		return $this->regeln[ $disziplin_id ] ?? [];
	}

	public function tarif(string $tarifstufe): float {
		return $this->tarife[ $tarifstufe ] ?? 0.0;
	}
}
