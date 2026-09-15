<?php
/**
 * Sportjahre: anlegen (mit Seed), aktivieren, ins Folgejahr kopieren, löschen.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Domain\Seed\Klassensatz;
use KSV\KMM\Infrastructure\Database\Tables;
use KSV\KMM\Infrastructure\Repository\DisziplinRepository;
use KSV\KMM\Infrastructure\Repository\GruppeRepository;
use KSV\KMM\Infrastructure\Repository\KlasseRepository;
use KSV\KMM\Infrastructure\Repository\RegelRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\TarifRepository;
use KSV\KMM\Support\Clock;
use KSV\KMM\Support\Settings;

final class SportjahrService {

	private SportjahrRepository $sportjahre;
	private GruppeRepository $gruppen;
	private KlasseRepository $klassen;
	private DisziplinRepository $disziplinen;
	private RegelRepository $regeln;
	private TarifRepository $tarife;

	public function __construct() {
		$this->sportjahre = new SportjahrRepository();
		$this->gruppen = new GruppeRepository();
		$this->klassen = new KlasseRepository();
		$this->disziplinen = new DisziplinRepository();
		$this->regeln = new RegelRepository();
		$this->tarife = new TarifRepository();
	}

	/**
	 * Legt ein Sportjahr an, optional mit Seed (Gruppen, Klassen, Standardtarife).
	 *
	 * @throws \RuntimeException wenn das Jahr bereits existiert.
	 */
	public function anlegen(int $jahr, bool $mit_seed = true, string $bezeichnung = ''): int {
		if ($this->sportjahre->by_jahr($jahr) !== null) {
			throw new \RuntimeException(sprintf('Sportjahr %d existiert bereits.', $jahr));
		}
		$id = $this->sportjahre->insert([
			'jahr'        => $jahr,
			'bezeichnung' => $bezeichnung !== '' ? $bezeichnung : sprintf('KM %d', $jahr),
			'ist_aktiv'   => $this->sportjahre->count() === 0 ? 1 : 0,
		]);
		if ($id <= 0) {
			throw new \RuntimeException('Sportjahr konnte nicht angelegt werden: ' . $this->sportjahre->last_error());
		}
		if ($mit_seed) {
			$this->seed_einspielen($id);
		}
		Protokoll::admin('sportjahr.anlegen', sprintf('Sportjahr %d angelegt%s', $jahr, $mit_seed ? ' (mit Klassensatz)' : ''), $id, null, 'sportjahr', $id);
		return $id;
	}

	/**
	 * Spielt Gruppen, Klassen und Tarife aus dem Seed ein (nur fehlende Einträge).
	 *
	 * @return array{gruppen: int, klassen: int}
	 */
	public function seed_einspielen(int $sportjahr_id): array {
		$n_gruppen = 0;
		$n_klassen = 0;
		foreach (Klassensatz::gruppen() as $g) {
			$gruppe = $this->gruppen->by_code($sportjahr_id, $g['code']);
			if ($gruppe === null) {
				$gruppe_id = $this->gruppen->insert([
					'sportjahr_id'          => $sportjahr_id,
					'code'                  => $g['code'],
					'bezeichnung'           => $g['bezeichnung'],
					'hoehermeldung_bereich' => $g['hoehermeldung_bereich'],
					'ist_para'              => $g['ist_para'],
					'sortierung'            => $g['sortierung'],
				]);
				$n_gruppen++;
			} else {
				$gruppe_id = (int) $gruppe['id'];
			}
			foreach ($g['klassen'] as $k) {
				if ($this->klassen->by_key($gruppe_id, (int) $k['nummer'], (string) $k['geschlecht']) !== null) {
					continue;
				}
				$k['sportjahr_id'] = $sportjahr_id;
				$k['gruppe_id'] = $gruppe_id;
				$this->klassen->insert($k);
				$n_klassen++;
			}
		}
		$vorhanden = $this->tarife->where(['sportjahr_id' => $sportjahr_id]);
		if ($vorhanden === []) {
			foreach (Klassensatz::tarife() as $stufe => $betrag) {
				$this->tarife->set($sportjahr_id, $stufe, $betrag);
			}
		}
		return ['gruppen' => $n_gruppen, 'klassen' => $n_klassen];
	}

	/**
	 * Ist ein Meldeschluss gesetzt, aber kein Erinnerungszeitpunkt, schlägt das Portal einen
	 * vor: so viele Tage vor dem Meldeschluss, wie in den Einstellungen hinterlegt
	 * (`erinnerung_tage_vor_schluss`, 0 = kein Vorschlag). Ein eingetragener Zeitpunkt wird
	 * nie überschrieben.
	 *
	 * @param array<string, mixed> $daten
	 * @return array<string, mixed>
	 */
	private function erinnerung_vorschlagen(array $daten): array {
		if (!array_key_exists('erinnerung_am', $daten) || (string) ($daten['erinnerung_am'] ?? '') !== '') {
			return $daten;
		}
		$schluss = (string) ($daten['meldeschluss'] ?? '');
		$tage = (int) Settings::get('erinnerung_tage_vor_schluss');
		if ($schluss === '' || $tage <= 0) {
			return $daten;
		}
		$zeit = strtotime($schluss . ' UTC');
		if ($zeit === false) {
			return $daten;
		}
		$daten['erinnerung_am'] = gmdate(Clock::DB_FORMAT, $zeit - $tage * 86400);
		return $daten;
	}

	public function aktivieren(int $sportjahr_id): void {
		$jahr = $this->sportjahre->find($sportjahr_id);
		if ($jahr === null) {
			throw new \RuntimeException('Sportjahr nicht gefunden.');
		}
		$this->sportjahre->set_aktiv($sportjahr_id);
		Protokoll::admin('sportjahr.aktivieren', sprintf('Sportjahr %d aktiviert', (int) $jahr['jahr']), $sportjahr_id, null, 'sportjahr', $sportjahr_id);
	}

	/**
	 * @param array<string, mixed> $daten bezeichnung, meldung_beginn, meldeschluss, erinnerung_am (UTC oder null)
	 */
	public function speichern(int $sportjahr_id, array $daten): void {
		$erlaubt = array_intersect_key($daten, array_flip(['bezeichnung', 'meldung_beginn', 'meldeschluss', 'erinnerung_am']));
		$erlaubt = $this->erinnerung_vorschlagen($erlaubt);
		$this->sportjahre->update($sportjahr_id, $erlaubt);
		Protokoll::admin('sportjahr.speichern', 'Sportjahr-Einstellungen gespeichert', $sportjahr_id, null, 'sportjahr', $sportjahr_id, $erlaubt);
	}

	/**
	 * Kopiert Gruppen, Klassen, Disziplinen, Regeln und Tarife in ein neues Sportjahr.
	 * Höhermeldungen werden nicht kopiert (gelten nur im jeweiligen Sportjahr).
	 *
	 * @return int ID des neuen Sportjahres.
	 * @throws \RuntimeException
	 */
	public function kopieren(int $quelle_id, int $ziel_jahr): int {
		$quelle = $this->sportjahre->find($quelle_id);
		if ($quelle === null) {
			throw new \RuntimeException('Quell-Sportjahr nicht gefunden.');
		}
		if ($this->sportjahre->by_jahr($ziel_jahr) !== null) {
			throw new \RuntimeException(sprintf('Sportjahr %d existiert bereits.', $ziel_jahr));
		}
		$ziel_id = $this->sportjahre->insert([
			'jahr'        => $ziel_jahr,
			'bezeichnung' => sprintf('KM %d', $ziel_jahr),
			'ist_aktiv'   => 0,
		]);
		if ($ziel_id <= 0) {
			throw new \RuntimeException('Sportjahr konnte nicht angelegt werden.');
		}

		$gruppen_map = [];
		foreach ($this->gruppen->by_sportjahr($quelle_id) as $g) {
			$alt = (int) $g['id'];
			unset($g['id']);
			$g['sportjahr_id'] = $ziel_id;
			$gruppen_map[ $alt ] = $this->gruppen->insert($g);
		}
		$klassen_map = [];
		foreach ($this->klassen->by_sportjahr($quelle_id) as $k) {
			$alt = (int) $k['id'];
			unset($k['id']);
			$k['sportjahr_id'] = $ziel_id;
			$k['gruppe_id'] = $gruppen_map[ (int) $k['gruppe_id'] ] ?? 0;
			$klassen_map[ $alt ] = $this->klassen->insert($k);
		}
		$disziplin_map = [];
		foreach ($this->disziplinen->by_sportjahr($quelle_id) as $d) {
			$alt = (int) $d['id'];
			unset($d['id']);
			$d['sportjahr_id'] = $ziel_id;
			$d['gruppe_id'] = $gruppen_map[ (int) $d['gruppe_id'] ] ?? 0;
			$disziplin_map[ $alt ] = $this->disziplinen->insert($d);
		}
		$n_regeln = 0;
		foreach ($this->regeln->by_sportjahr($quelle_id) as $r) {
			unset($r['id']);
			$r['sportjahr_id'] = $ziel_id;
			$r['disziplin_id'] = $disziplin_map[ (int) $r['disziplin_id'] ] ?? 0;
			$r['klasse_id'] = $klassen_map[ (int) $r['klasse_id'] ] ?? 0;
			$r['einzel_ziel_klasse_id'] = $r['einzel_ziel_klasse_id'] !== null ? ($klassen_map[ (int) $r['einzel_ziel_klasse_id'] ] ?? null) : null;
			$r['mannschaft_ziel_klasse_id'] = $r['mannschaft_ziel_klasse_id'] !== null ? ($klassen_map[ (int) $r['mannschaft_ziel_klasse_id'] ] ?? null) : null;
			$r['quelle'] = 'kopie';
			$r['updated_at'] = Clock::now_utc();
			if ($r['disziplin_id'] > 0 && $r['klasse_id'] > 0) {
				$this->regeln->insert($r);
				$n_regeln++;
			}
		}
		foreach ($this->tarife->by_sportjahr($quelle_id) as $stufe => $betrag) {
			$this->tarife->set($ziel_id, $stufe, $betrag);
		}

		Protokoll::admin(
			'sportjahr.kopieren',
			sprintf('Sportjahr %d aus %d kopiert (%d Klassen, %d Disziplinen, %d Regeln)', $ziel_jahr, (int) $quelle['jahr'], count($klassen_map), count($disziplin_map), $n_regeln),
			$ziel_id,
			null,
			'sportjahr',
			$ziel_id
		);
		return $ziel_id;
	}

	/**
	 * Löscht ein Sportjahr samt Stammdaten. Nur möglich, wenn keine Meldungen existieren.
	 *
	 * @throws \RuntimeException
	 */
	public function loeschen(int $sportjahr_id): void {
		$jahr = $this->sportjahre->find($sportjahr_id);
		if ($jahr === null) {
			throw new \RuntimeException('Sportjahr nicht gefunden.');
		}
		global $wpdb;
		$meldungen = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Tables::name('meldung') . ' WHERE sportjahr_id = %d', $sportjahr_id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ($meldungen > 0) {
			throw new \RuntimeException('Sportjahr hat Meldungen und kann nicht gelöscht werden.');
		}
		$this->regeln->delete_where(['sportjahr_id' => $sportjahr_id]);
		$this->disziplinen->delete_where(['sportjahr_id' => $sportjahr_id]);
		$this->klassen->delete_where(['sportjahr_id' => $sportjahr_id]);
		$this->gruppen->delete_where(['sportjahr_id' => $sportjahr_id]);
		$this->tarife->delete_where(['sportjahr_id' => $sportjahr_id]);
		foreach (['hoehermeldung', 'magic_link'] as $t) {
			$wpdb->delete(Tables::name($t), ['sportjahr_id' => $sportjahr_id], ['%d']);
		}
		$this->sportjahre->delete($sportjahr_id);
		Protokoll::admin('sportjahr.loeschen', sprintf('Sportjahr %d gelöscht', (int) $jahr['jahr']), null, null, 'sportjahr', $sportjahr_id);
	}

	/** Markiert eine Regeländerung (Auslöser für die Revalidierung, Meilenstein 4). */
	public function regeln_geaendert(int $sportjahr_id): void {
		$this->sportjahre->update($sportjahr_id, ['regeln_geaendert_am' => Clock::now_utc()]);
		do_action('kmm_regeln_geaendert', $sportjahr_id);
	}
}
