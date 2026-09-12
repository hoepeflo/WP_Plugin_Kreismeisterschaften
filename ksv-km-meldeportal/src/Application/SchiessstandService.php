<?php
/**
 * Schießstände als Stammdaten: ein Ort (z. B. „Schießstand SV Vorwalsrode“) mit
 * Standgruppen („10 m“: 12 Stände, „25 m“: 5 Stände, „Bogen“: 6 Scheiben à 4 Positionen).
 * Beim Wettkampftag werden daraus per Ankreuzen die Einheiten des Tages erzeugt.
 * Sportjahrübergreifend, nur Admin.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Auth\Rechte;
use KSV\KMM\Infrastructure\Repository\EinheitRepository;
use KSV\KMM\Infrastructure\Repository\SchiessstandRepository;
use KSV\KMM\Infrastructure\Repository\StandgruppeRepository;
use KSV\KMM\Infrastructure\Repository\WettkampftagRepository;
use KSV\KMM\Support\Clock;

final class SchiessstandService {

	private SchiessstandRepository $staende;
	private StandgruppeRepository $gruppen;

	public function __construct() {
		$this->staende = new SchiessstandRepository();
		$this->gruppen = new StandgruppeRepository();
	}

	private function nur_admin(): void {
		if (!Rechte::ist_admin()) {
			throw new \RuntimeException('Schießstände verwalten können nur Administratoren.');
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function stand(int $id): array {
		$s = $this->staende->find($id);
		if ($s === null) {
			throw new \RuntimeException('Schießstand nicht gefunden.');
		}
		return $s;
	}

	/**
	 * Alle Schießstände mit ihren Standgruppen.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function liste(): array {
		$out = [];
		foreach ($this->staende->alle() as $s) {
			$s['gruppen'] = $this->gruppen->by_schiessstand((int) $s['id']);
			$s['plaetze'] = array_sum(array_map(static fn(array $g): int => (int) $g['anzahl'] * (int) $g['kapazitaet'], $s['gruppen']));
			$out[] = $s;
		}
		return $out;
	}

	public function speichern(int $id, string $bezeichnung, string $ort, string $notiz = '', int $sortierung = 0): int {
		$this->nur_admin();
		$bezeichnung = mb_substr(trim($bezeichnung), 0, 150);
		if ($bezeichnung === '') {
			throw new \InvalidArgumentException('Bitte eine Bezeichnung angeben.');
		}
		$satz = ['bezeichnung' => $bezeichnung, 'ort' => mb_substr(trim($ort), 0, 150), 'notiz' => trim($notiz) !== '' ? trim($notiz) : null, 'sortierung' => $sortierung, 'updated_at' => Clock::now_utc()];
		if ($id > 0) {
			$this->stand($id);
			$this->staende->update($id, $satz);
			Protokoll::admin('schiessstand.aendern', $bezeichnung, null, null, 'schiessstand', $id);
			return $id;
		}
		$satz['created_at'] = Clock::now_utc();
		$id = $this->staende->insert($satz);
		Protokoll::admin('schiessstand.anlegen', $bezeichnung, null, null, 'schiessstand', $id);
		return $id;
	}

	public function loeschen(int $id): void {
		$this->nur_admin();
		$s = $this->stand($id);
		$tage = (new WettkampftagRepository())->count(['schiessstand_id' => $id]);
		if ($tage > 0) {
			throw new \RuntimeException(sprintf('Der Schießstand ist %d Wettkampftagen zugeordnet und kann nicht gelöscht werden.', $tage));
		}
		$this->gruppen->delete_where(['schiessstand_id' => $id]);
		$this->staende->delete($id);
		Protokoll::admin('schiessstand.loeschen', (string) $s['bezeichnung'], null, null, 'schiessstand', $id);
	}

	/**
	 * @param list<string> $disziplin_kennzahlen leer = alle Disziplinen
	 */
	public function gruppe_speichern(int $id, int $schiessstand_id, string $bezeichnung, string $praefix, int $anzahl, int $nummer_von, int $kapazitaet, array $disziplin_kennzahlen = [], int $sortierung = 0): int {
		$this->nur_admin();
		$this->stand($schiessstand_id);
		$bezeichnung = mb_substr(trim($bezeichnung), 0, 100);
		$praefix = mb_substr(trim($praefix), 0, 50);
		if ($bezeichnung === '') {
			throw new \InvalidArgumentException('Bitte eine Bezeichnung für die Standgruppe angeben (z. B. 10 m Stände).');
		}
		if ($praefix === '') {
			$praefix = 'Stand';
		}
		if ($anzahl < 1 || $anzahl > 200) {
			throw new \InvalidArgumentException('Die Anzahl muss zwischen 1 und 200 liegen.');
		}
		if ($kapazitaet < 1 || $kapazitaet > 200) {
			throw new \InvalidArgumentException('Die Kapazität (Positionen je Einheit) muss zwischen 1 und 200 liegen.');
		}
		$kennzahlen = array_values(array_unique(array_filter(array_map('trim', $disziplin_kennzahlen))));
		$satz = ['schiessstand_id' => $schiessstand_id, 'bezeichnung' => $bezeichnung, 'praefix' => $praefix, 'anzahl' => $anzahl, 'nummer_von' => max(1, $nummer_von), 'kapazitaet' => $kapazitaet, 'disziplin_kennzahlen' => implode(',', $kennzahlen), 'sortierung' => $sortierung];
		if ($id > 0) {
			$g = $this->gruppen->find($id);
			if ($g === null || (int) $g['schiessstand_id'] !== $schiessstand_id) {
				throw new \RuntimeException('Standgruppe nicht gefunden.');
			}
			$this->gruppen->update($id, $satz);
			return $id;
		}
		return $this->gruppen->insert($satz);
	}

	public function gruppe_loeschen(int $id): void {
		$this->nur_admin();
		$g = $this->gruppen->find($id);
		if ($g === null) {
			throw new \RuntimeException('Standgruppe nicht gefunden.');
		}
		$n = (new EinheitRepository())->count(['standgruppe_id' => $id]);
		if ($n > 0) {
			throw new \RuntimeException(sprintf('Aus dieser Standgruppe sind %d Einheiten an Wettkampftagen freigegeben; sie kann nicht gelöscht werden.', $n));
		}
		$this->gruppen->delete($id);
	}

	/**
	 * Standgruppen eines Schießstands mit den Nummern, die an einem Wettkampftag bereits
	 * als Einheit freigegeben sind.
	 *
	 * @return list<array<string, mixed>> Gruppe + nummern (alle) + freigegeben (nummer => einheit_id)
	 */
	public function auswahl(int $schiessstand_id, int $tag_id): array {
		$einheiten = (new EinheitRepository())->by_wettkampftag($tag_id);
		$out = [];
		foreach ($this->gruppen->by_schiessstand($schiessstand_id) as $g) {
			$frei = [];
			foreach ($einheiten as $e) {
				if ($e['standgruppe_id'] !== null && (int) $e['standgruppe_id'] === (int) $g['id'] && $e['nummer'] !== null) {
					$frei[ (int) $e['nummer'] ] = (int) $e['id'];
				}
			}
			$g['nummern'] = StandgruppeRepository::nummern($g);
			$g['freigegeben'] = $frei;
			$out[] = $g;
		}
		return $out;
	}
}
