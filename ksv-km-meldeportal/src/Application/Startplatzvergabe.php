<?php
/**
 * Nach der Buchungsfrist (Konzept 12.5): Restverteilung, Verschieben und Tauschen.
 *
 * Die Buchung durch die Vereine ist dann geschlossen (BuchungService::buchung_offen).
 * „Rest verteilen“ setzt alle startberechtigten Meldungen ohne Platz auf freie, passende
 * Plätze; jede einzelne Zuteilung ist wie eine Vereinsbuchung ein einzelner INSERT, über
 * dessen Eindeutigkeit die Datenbank entscheidet (UNIQUE je Platz und je Meldung). Ein
 * gleichzeitig belegter Platz führt deshalb nicht zum Abbruch, sondern zum nächsten Platz.
 *
 * Verschieben ist ein einzelnes UPDATE; ist der Zielplatz belegt, wird getauscht. Der
 * Tausch läuft in einer Transaktion über eine Parkposition (0), weil UNIQUE(Platz) einen
 * direkten Ringtausch nicht zulässt.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Auth\Rechte;
use KSV\KMM\Domain\AenderungTyp;
use KSV\KMM\Domain\WettkampftagStatus;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\BuchungRepository;
use KSV\KMM\Infrastructure\Repository\DurchgangRepository;
use KSV\KMM\Infrastructure\Repository\DurchgangZulassungRepository;
use KSV\KMM\Infrastructure\Repository\EinheitRepository;
use KSV\KMM\Infrastructure\Repository\EinzelmeldungRepository;
use KSV\KMM\Infrastructure\Repository\SchuetzeRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;
use KSV\KMM\Support\Clock;

final class Startplatzvergabe {

	/** Parkposition beim Tausch (echte Positionen beginnen bei 1). */
	private const PARKEN = 0;

	private BuchungRepository $buchungen;
	private DurchgangRepository $durchgaenge;
	private DurchgangZulassungRepository $zulassungen;
	private EinheitRepository $einheiten;
	private EinzelmeldungRepository $einzel;
	private SchuetzeRepository $schuetzen;
	private StartplanService $startplan;

	public function __construct(private readonly int $sportjahr_id) {
		$this->buchungen = new BuchungRepository();
		$this->durchgaenge = new DurchgangRepository();
		$this->zulassungen = new DurchgangZulassungRepository();
		$this->einheiten = new EinheitRepository();
		$this->einzel = new EinzelmeldungRepository();
		$this->schuetzen = new SchuetzeRepository();
		$this->startplan = new StartplanService($sportjahr_id);
	}

	private function schreibrecht(): void {
		if (!Rechte::hat_recht(Rechte::RECHT_STARTPLAN)) {
			throw new \RuntimeException('Kein Recht, den Startplan zu bearbeiten.');
		}
		$sportjahr = (new SportjahrRepository())->find($this->sportjahr_id);
		if ($sportjahr === null) {
			throw new \RuntimeException('Sportjahr nicht gefunden.');
		}
		if ($sportjahr['abgeschlossen_am'] !== null) {
			throw new \RuntimeException('Das Sportjahr ist abgeschlossen.');
		}
	}

	// ----- Restverteilung ---------------------------------------------------------------------

	/**
	 * Alle startberechtigten Meldungen ohne Platz auf freie, passende Plätze setzen.
	 *
	 * Reihenfolge: Disziplin, dann Verein, dann Name – damit dieselbe Ausgangslage immer
	 * dasselbe Ergebnis liefert und Starter eines Vereins möglichst beieinander stehen.
	 *
	 * @param bool $vorschau true = nur rechnen, nichts schreiben
	 * @return array{verteilt: int, offen: list<array{name: string, verein: string, disziplin: string, grund: string}>, vorschau: bool}
	 */
	public function restverteilung(int $tag_id, bool $vorschau = false): array {
		if (!$vorschau) {
			$this->schreibrecht();
		}
		$tag = $this->startplan->tag($tag_id);
		if ((string) $tag['status'] === WettkampftagStatus::ENTWURF) {
			throw new \RuntimeException('Der Wettkampftag ist noch im Entwurf. Erst freigeben, dann verteilen.');
		}
		if (!$vorschau && !$this->frist_vorbei($tag)) {
			throw new \RuntimeException('Die Buchungsfrist läuft noch. Die Restverteilung ist erst danach vorgesehen.');
		}
		$plan = $this->plan($tag_id);
		if ($plan['durchgaenge'] === []) {
			return ['verteilt' => 0, 'offen' => [], 'vorschau' => $vorschau];
		}
		$vereine = [];
		foreach ((new VereinRepository())->all() as $v) {
			$vereine[ (int) $v['id'] ] = $v;
		}
		$rw = RegelwerkLader::laden($this->sportjahr_id);

		$verteilt = 0;
		$offen = [];
		foreach ($this->offene_meldungen($tag_id, $plan) as $em) {
			$d = $rw->disziplin((int) $em['disziplin_id']);
			$zeile = [
				'name'      => $em['_name'],
				'verein'    => (string) ($vereine[ (int) $em['verein_id'] ]['name'] ?? ''),
				'disziplin' => $d !== null ? $d->kennzahl . ' ' . $d->bezeichnung : '',
				'grund'     => '',
			];
			$platz = $this->freien_platz_suchen($em, $plan);
			if ($platz === null) {
				$zeile['grund'] = 'kein freier Platz in einem passenden Durchgang';
				$offen[] = $zeile;
				continue;
			}
			if ($vorschau) {
				$this->platz_vormerken($em, $platz, $plan);
				$verteilt++;
				continue;
			}
			$ergebnis = $this->zuteilen($tag, $em, $platz, $vereine[ (int) $em['verein_id'] ] ?? null);
			if ($ergebnis === BuchungRepository::ERGEBNIS_OK) {
				$this->platz_vormerken($em, $platz, $plan);
				$verteilt++;
				continue;
			}
			// Platz war gerade belegt: Belegung merken und einen weiteren Versuch wagen.
			$plan['belegt'][ $platz['durchgang_id'] ][ $platz['einheit_id'] ][ $platz['position'] ] = true;
			$zweiter = $this->freien_platz_suchen($em, $plan);
			if ($zweiter !== null && $this->zuteilen($tag, $em, $zweiter, $vereine[ (int) $em['verein_id'] ] ?? null) === BuchungRepository::ERGEBNIS_OK) {
				$this->platz_vormerken($em, $zweiter, $plan);
				$verteilt++;
				continue;
			}
			$zeile['grund'] = 'Platz wurde zwischenzeitlich belegt';
			$offen[] = $zeile;
		}
		if (!$vorschau) {
			Protokoll::admin(
				'startplan.restverteilung',
				sprintf('%s %s: %d Starter zugeteilt, %d ohne Platz', (string) $tag['datum'], (string) $tag['bezeichnung'], $verteilt, count($offen)),
				$this->sportjahr_id,
				null,
				'wettkampftag',
				$tag_id,
				['offen' => $offen]
			);
		}
		return ['verteilt' => $verteilt, 'offen' => $offen, 'vorschau' => $vorschau];
	}

	/**
	 * @param array<string, mixed> $tag
	 */
	private function frist_vorbei(array $tag): bool {
		if ((string) $tag['status'] === WettkampftagStatus::VEROEFFENTLICHT) {
			return true;
		}
		return $tag['buchungsfrist'] === null || (string) $tag['buchungsfrist'] <= Clock::now_utc();
	}

	/**
	 * Struktur des Tages für die Verteilung: Durchgänge mit Zulassungen und erlaubten
	 * Einheiten, Kapazitäten, bereits belegte Plätze und belegte Zeiten je Schütze.
	 *
	 * @return array{durchgaenge: list<array<string, mixed>>, einheiten: array<int, array<string, mixed>>, belegt: array<int, array<int, array<int, bool>>>, zeiten: array<int, list<array{beginn: string, ende: string}>>}
	 */
	private function plan(int $tag_id): array {
		$einheiten = [];
		foreach ($this->einheiten->by_wettkampftag($tag_id) as $e) {
			$einheiten[ (int) $e['id'] ] = $e;
		}
		$durchgaenge = [];
		foreach ($this->durchgaenge->by_wettkampftag($tag_id) as $dg) {
			$zul = $this->zulassungen->by_durchgang((int) $dg['id']);
			if ($zul === []) {
				continue;
			}
			$dis_ids = [];
			foreach ($zul as $z) {
				$dis_ids[ (int) $z['disziplin_id'] ] = true;
			}
			$erlaubte_einheiten = [];
			foreach ($einheiten as $eid => $e) {
				$nur = EinheitRepository::disziplin_ids($e);
				if ($nur === [] || array_intersect($nur, array_keys($dis_ids)) !== []) {
					$erlaubte_einheiten[] = $eid;
				}
			}
			$durchgaenge[] = ['row' => $dg, 'zulassungen' => $zul, 'einheiten' => $erlaubte_einheiten];
		}
		$belegt = [];
		foreach ($this->buchungen->by_wettkampftag($tag_id) as $b) {
			$belegt[ (int) $b['durchgang_id'] ][ (int) $b['einheit_id'] ][ (int) $b['position'] ] = true;
		}
		return ['durchgaenge' => $durchgaenge, 'einheiten' => $einheiten, 'belegt' => $belegt, 'zeiten' => $this->zeiten_je_schuetze()];
	}

	/**
	 * Belegte Zeitfenster je Schütze über alle Wettkampftage des Sportjahres.
	 *
	 * @return array<int, list<array{beginn: string, ende: string}>>
	 */
	private function zeiten_je_schuetze(): array {
		$durchgaenge = [];
		$out = [];
		foreach ($this->buchungen->where(['sportjahr_id' => $this->sportjahr_id]) as $b) {
			$em = $this->einzel->find((int) $b['einzelmeldung_id']);
			if ($em === null || $em['schuetze_id'] === null) {
				continue;
			}
			$did = (int) $b['durchgang_id'];
			if (!isset($durchgaenge[ $did ])) {
				$durchgaenge[ $did ] = $this->durchgaenge->find($did);
			}
			$dg = $durchgaenge[ $did ];
			if ($dg === null) {
				continue;
			}
			$out[ (int) $em['schuetze_id'] ][] = ['beginn' => (string) $dg['beginn'], 'ende' => (string) $dg['ende']];
		}
		return $out;
	}

	/**
	 * Meldungen ohne Platz, die zu mindestens einer Zulassung des Tages passen.
	 *
	 * @param array<string, mixed> $plan
	 * @return list<array<string, mixed>>
	 */
	private function offene_meldungen(int $tag_id, array $plan): array {
		$out = [];
		foreach ($this->startplan->buchbare_meldungen() as $em) {
			if ($this->buchungen->by_einzelmeldung((int) $em['id']) !== null) {
				continue;
			}
			$passt = false;
			foreach ($plan['durchgaenge'] as $d) {
				foreach ($d['zulassungen'] as $z) {
					if (StartplanService::passt($em, $z)) {
						$passt = true;
						break 2;
					}
				}
			}
			if (!$passt) {
				continue;
			}
			$s = $em['schuetze_id'] !== null ? $this->schuetzen->find((int) $em['schuetze_id']) : null;
			$em['_name'] = $s !== null ? $s['nachname'] . ', ' . $s['vorname'] : '?';
			$out[] = $em;
		}
		usort($out, static function (array $a, array $b): int {
			return [(int) $a['disziplin_id'], (int) $a['verein_id'], (string) $a['_name']]
				<=> [(int) $b['disziplin_id'], (int) $b['verein_id'], (string) $b['_name']];
		});
		return $out;
	}

	/**
	 * Ersten freien Platz für eine Meldung suchen (Zulassung, Einheit, Kapazität, Zeit).
	 *
	 * @param array<string, mixed> $em
	 * @param array<string, mixed> $plan
	 * @return array{durchgang_id: int, einheit_id: int, position: int, durchgang: array<string, mixed>, einheit: array<string, mixed>}|null
	 */
	private function freien_platz_suchen(array $em, array $plan): ?array {
		$disziplin_id = (int) $em['disziplin_id'];
		foreach ($plan['durchgaenge'] as $d) {
			$passt = false;
			foreach ($d['zulassungen'] as $z) {
				if (StartplanService::passt($em, $z)) {
					$passt = true;
					break;
				}
			}
			if (!$passt || $this->zeitkonflikt($em, $d['row'], $plan)) {
				continue;
			}
			foreach ($d['einheiten'] as $eid) {
				$e = $plan['einheiten'][ $eid ];
				$nur = EinheitRepository::disziplin_ids($e);
				if ($nur !== [] && !in_array($disziplin_id, $nur, true)) {
					continue;
				}
				for ($p = 1; $p <= (int) $e['kapazitaet']; $p++) {
					if (!isset($plan['belegt'][ (int) $d['row']['id'] ][ $eid ][ $p ])) {
						return ['durchgang_id' => (int) $d['row']['id'], 'einheit_id' => $eid, 'position' => $p, 'durchgang' => $d['row'], 'einheit' => $e];
					}
				}
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $em
	 * @param array<string, mixed> $dg
	 * @param array<string, mixed> $plan
	 */
	private function zeitkonflikt(array $em, array $dg, array $plan): bool {
		if ($em['schuetze_id'] === null) {
			return false;
		}
		foreach ($plan['zeiten'][ (int) $em['schuetze_id'] ] ?? [] as $z) {
			if ($z['beginn'] < (string) $dg['ende'] && (string) $dg['beginn'] < $z['ende']) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, mixed>  $em
	 * @param array<string, mixed>  $platz
	 * @param array<string, mixed>  $plan
	 */
	private function platz_vormerken(array $em, array $platz, array &$plan): void {
		$plan['belegt'][ $platz['durchgang_id'] ][ $platz['einheit_id'] ][ $platz['position'] ] = true;
		if ($em['schuetze_id'] !== null) {
			$plan['zeiten'][ (int) $em['schuetze_id'] ][] = ['beginn' => (string) $platz['durchgang']['beginn'], 'ende' => (string) $platz['durchgang']['ende']];
		}
	}

	/**
	 * @param array<string, mixed>      $tag
	 * @param array<string, mixed>      $em
	 * @param array<string, mixed>      $platz
	 * @param array<string, mixed>|null $verein
	 */
	private function zuteilen(array $tag, array $em, array $platz, ?array $verein): string {
		$r = $this->buchungen->platz_buchen([
			'sportjahr_id'     => $this->sportjahr_id,
			'wettkampftag_id'  => (int) $tag['id'],
			'durchgang_id'     => $platz['durchgang_id'],
			'einheit_id'       => $platz['einheit_id'],
			'position'         => $platz['position'],
			'einzelmeldung_id' => (int) $em['id'],
			'verein_id'        => (int) $em['verein_id'],
			'gebucht_von_typ'  => 'admin',
			'gebucht_von_id'   => get_current_user_id() > 0 ? get_current_user_id() : null,
			'gebucht_am'       => Clock::now_utc(),
		]);
		if ($r['ergebnis'] !== BuchungRepository::ERGEBNIS_OK) {
			return $r['ergebnis'];
		}
		$text = sprintf(
			'%s: %s, Durchgang %d, %s Platz %d',
			(string) $tag['bezeichnung'],
			(string) $em['_name'],
			(int) $platz['durchgang']['nummer'],
			(string) $platz['einheit']['bezeichnung'],
			(int) $platz['position']
		);
		Aenderungen::erfassen($this->sportjahr_id, (int) $em['verein_id'], (int) $em['id'], AenderungTyp::STARTPLAN, 'Startplatz zugeteilt: ' . $text);
		unset($verein);
		return BuchungRepository::ERGEBNIS_OK;
	}

	/**
	 * Starter dieses Tages, die (noch) keinen Platz haben.
	 *
	 * @return list<array{einzelmeldung_id: int, name: string, verein: string, disziplin: string}>
	 */
	public function ohne_platz(int $tag_id): array {
		$plan = $this->plan($tag_id);
		if ($plan['durchgaenge'] === []) {
			return [];
		}
		$rw = RegelwerkLader::laden($this->sportjahr_id);
		$vereine = [];
		foreach ((new VereinRepository())->all() as $v) {
			$vereine[ (int) $v['id'] ] = (string) $v['name'];
		}
		$out = [];
		foreach ($this->offene_meldungen($tag_id, $plan) as $em) {
			$d = $rw->disziplin((int) $em['disziplin_id']);
			$out[] = [
				'einzelmeldung_id' => (int) $em['id'],
				'name'             => (string) $em['_name'],
				'verein'           => $vereine[ (int) $em['verein_id'] ] ?? '',
				'disziplin'        => $d !== null ? $d->kennzahl . ' ' . $d->bezeichnung : '',
			];
		}
		return $out;
	}

	/**
	 * Der ganze Wettkampftag als Matrix für das Backend: Zeilen = Durchgänge,
	 * Spalten = alle Plätze (Einheit × Position), auch die freien. Anders als die
	 * öffentliche Ansicht zeigt sie leere Plätze und markiert, welcher Platz in welchem
	 * Durchgang überhaupt in Frage kommt – sonst wüsste man beim Verschieben nicht, wohin.
	 *
	 * @return array{spalten: list<array<string, mixed>>, durchgaenge: list<array<string, mixed>>, gebucht: int, plaetze: int}
	 */
	public function matrix(int $tag_id): array {
		$this->startplan->tag($tag_id);
		$rw = RegelwerkLader::laden($this->sportjahr_id);
		$schuetzen = $this->schuetzen;
		$vereine = [];
		foreach ((new VereinRepository())->all() as $v) {
			$vereine[ (int) $v['id'] ] = (string) $v['name'];
		}
		$einheiten = $this->einheiten->by_wettkampftag($tag_id);
		$spalten = [];
		foreach ($einheiten as $e) {
			for ($p = 1; $p <= (int) $e['kapazitaet']; $p++) {
				$spalten[] = [
					'key'       => (int) $e['id'] . '-' . $p,
					'einheit_id' => (int) $e['id'],
					'position'  => $p,
					'label'     => (string) $e['bezeichnung'] . ((int) $e['kapazitaet'] > 1 ? ' / ' . $p : ''),
					'disziplin_ids' => EinheitRepository::disziplin_ids($e),
				];
			}
		}
		$belegung = [];
		$gebucht = 0;
		foreach ($this->buchungen->by_wettkampftag($tag_id) as $b) {
			$em = $this->einzel->find((int) $b['einzelmeldung_id']);
			$s = $em !== null && $em['schuetze_id'] !== null ? $schuetzen->find((int) $em['schuetze_id']) : null;
			$d = $em !== null ? $rw->disziplin((int) $em['disziplin_id']) : null;
			$klasse_id = $em !== null ? ($em['startklasse_id'] ?? $em['mannschaft_klasse_id']) : null;
			$k = $klasse_id !== null ? $rw->klasse((int) $klasse_id) : null;
			$belegung[ (int) $b['durchgang_id'] ][ (int) $b['einheit_id'] . '-' . (int) $b['position'] ] = [
				'buchung_id' => (int) $b['id'],
				'name'       => $s !== null ? $s['nachname'] . ', ' . $s['vorname'] : '?',
				'verein'     => $vereine[ (int) $b['verein_id'] ] ?? '',
				'kennzahl'   => $d?->kennzahl ?? '',
				'klasse'     => $k?->bezeichnung ?? '',
				'admin'      => (string) $b['gebucht_von_typ'] === 'admin',
			];
			$gebucht++;
		}
		$durchgaenge = [];
		$plaetze = 0;
		foreach ($this->durchgaenge->by_wettkampftag($tag_id) as $dg) {
			$zul = $this->zulassungen->by_durchgang((int) $dg['id']);
			$dis_ids = [];
			$texte = [];
			foreach ($zul as $z) {
				$dis_ids[ (int) $z['disziplin_id'] ] = true;
				$d = $rw->disziplin((int) $z['disziplin_id']);
				$kl = $z['startklasse_id'] !== null ? $rw->klasse((int) $z['startklasse_id']) : null;
				$texte[] = ($d?->kennzahl ?? '?') . ($kl !== null ? ' (' . $kl->bezeichnung . ')' : '');
			}
			$erlaubt = [];
			foreach ($spalten as $sp) {
				$ok = $zul !== [] && ($sp['disziplin_ids'] === [] || array_intersect($sp['disziplin_ids'], array_keys($dis_ids)) !== []);
				$erlaubt[ $sp['key'] ] = $ok;
				if ($ok) {
					$plaetze++;
				}
			}
			$durchgaenge[] = [
				'id'          => (int) $dg['id'],
				'nummer'      => (int) $dg['nummer'],
				'bezeichnung' => (string) $dg['bezeichnung'],
				'beginn'      => Clock::format_local((string) $dg['beginn'], 'H:i'),
				'ende'        => Clock::format_local((string) $dg['ende'], 'H:i'),
				'zulassungen' => implode('; ', $texte),
				'zustaendig'  => $this->startplan->durchgang_zustaendig((int) $dg['id']),
				'erlaubt'     => $erlaubt,
				'zellen'      => $belegung[ (int) $dg['id'] ] ?? [],
			];
		}
		return ['spalten' => $spalten, 'durchgaenge' => $durchgaenge, 'gebucht' => $gebucht, 'plaetze' => $plaetze];
	}

	// ----- Verschieben und Tauschen ---------------------------------------------------------------

	/**
	 * Starter auf einen anderen Platz setzen. Ist der Zielplatz belegt, tauschen beide
	 * Starter die Plätze.
	 *
	 * @return array{getauscht: bool, mit: string}
	 */
	public function verschieben(int $buchung_id, int $durchgang_id, int $einheit_id, int $position): array {
		$this->schreibrecht();
		$b = $this->buchungen->find($buchung_id);
		if ($b === null || (int) $b['sportjahr_id'] !== $this->sportjahr_id) {
			throw new \RuntimeException('Buchung nicht gefunden.');
		}
		$tag = $this->startplan->tag((int) $b['wettkampftag_id']);
		$ziel = $this->buchungen->first(['durchgang_id' => $durchgang_id, 'einheit_id' => $einheit_id, 'position' => $position]);
		if ($ziel !== null && (int) $ziel['id'] === $buchung_id) {
			return ['getauscht' => false, 'mit' => ''];
		}
		$this->platz_pruefen($b, $durchgang_id, $einheit_id, $position, $tag);
		if ($ziel !== null) {
			$this->platz_pruefen($ziel, (int) $b['durchgang_id'], (int) $b['einheit_id'], (int) $b['position'], $tag);
		}
		$name_a = $this->name_von((int) $b['einzelmeldung_id']);
		$dg = $this->durchgaenge->find($durchgang_id);
		$e = $this->einheiten->find($einheit_id);
		$ziel_text = sprintf('Durchgang %d, %s Platz %d', (int) ($dg['nummer'] ?? 0), (string) ($e['bezeichnung'] ?? ''), $position);

		if ($ziel === null) {
			if ($this->buchungen->umbuchen($buchung_id, $durchgang_id, $einheit_id, $position) !== BuchungRepository::ERGEBNIS_OK) {
				throw new \RuntimeException('Der Platz ist inzwischen belegt. Bitte die Seite neu laden.');
			}
			Protokoll::admin('startplan.verschieben', sprintf('%s: %s → %s', (string) $tag['bezeichnung'], $name_a, $ziel_text), $this->sportjahr_id, (int) $b['verein_id'], 'buchung', $buchung_id);
			Aenderungen::erfassen($this->sportjahr_id, (int) $b['verein_id'], (int) $b['einzelmeldung_id'], AenderungTyp::STARTPLAN, sprintf('Startplatz geändert: %s, %s, %s', (string) $tag['bezeichnung'], $name_a, $ziel_text));
			return ['getauscht' => false, 'mit' => ''];
		}

		$name_b = $this->name_von((int) $ziel['einzelmeldung_id']);
		$this->tauschen($b, $ziel);
		$quelle_text = sprintf('Durchgang %d, %s Platz %d', (int) ($this->durchgaenge->find((int) $b['durchgang_id'])['nummer'] ?? 0), (string) ($this->einheiten->find((int) $b['einheit_id'])['bezeichnung'] ?? ''), (int) $b['position']);
		Protokoll::admin('startplan.tauschen', sprintf('%s: %s ↔ %s', (string) $tag['bezeichnung'], $name_a, $name_b), $this->sportjahr_id, (int) $b['verein_id'], 'buchung', $buchung_id);
		Aenderungen::erfassen($this->sportjahr_id, (int) $b['verein_id'], (int) $b['einzelmeldung_id'], AenderungTyp::STARTPLAN, sprintf('Startplatz getauscht: %s, %s, %s', (string) $tag['bezeichnung'], $name_a, $ziel_text));
		Aenderungen::erfassen($this->sportjahr_id, (int) $ziel['verein_id'], (int) $ziel['einzelmeldung_id'], AenderungTyp::STARTPLAN, sprintf('Startplatz getauscht: %s, %s, %s', (string) $tag['bezeichnung'], $name_b, $quelle_text));
		return ['getauscht' => true, 'mit' => $name_b];
	}

	/**
	 * Ringtausch zweier Buchungen: UNIQUE(Platz) lässt kein direktes Vertauschen zu,
	 * deshalb geht die erste Buchung kurz auf die Parkposition.
	 *
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 */
	private function tauschen(array $a, array $b): void {
		$a_platz = [(int) $a['durchgang_id'], (int) $a['einheit_id'], (int) $a['position']];
		$b_platz = [(int) $b['durchgang_id'], (int) $b['einheit_id'], (int) $b['position']];
		$this->buchungen->transaktion(function () use ($a, $b, $a_platz, $b_platz): void {
			if ($this->buchungen->umbuchen((int) $a['id'], $a_platz[0], $a_platz[1], self::PARKEN) !== BuchungRepository::ERGEBNIS_OK) {
				throw new \RuntimeException('Der Tausch konnte nicht gespeichert werden.');
			}
			if ($this->buchungen->umbuchen((int) $b['id'], ...$a_platz) !== BuchungRepository::ERGEBNIS_OK) {
				throw new \RuntimeException('Der Tausch konnte nicht gespeichert werden.');
			}
			if ($this->buchungen->umbuchen((int) $a['id'], ...$b_platz) !== BuchungRepository::ERGEBNIS_OK) {
				throw new \RuntimeException('Der Tausch konnte nicht gespeichert werden.');
			}
		});
	}

	/**
	 * Darf diese Buchung auf diesen Platz? Zulassung, Einheit, Kapazität, Zeitüberschneidung.
	 *
	 * @param array<string, mixed> $b
	 * @param array<string, mixed> $tag
	 */
	private function platz_pruefen(array $b, int $durchgang_id, int $einheit_id, int $position, array $tag): void {
		$em = $this->einzel->find((int) $b['einzelmeldung_id']);
		if ($em === null) {
			throw new \RuntimeException('Meldung nicht gefunden.');
		}
		$dg = $this->durchgaenge->find($durchgang_id);
		if ($dg === null || (int) $dg['wettkampftag_id'] !== (int) $tag['id']) {
			throw new \RuntimeException('Durchgang gehört nicht zu diesem Wettkampftag.');
		}
		if (!$this->startplan->durchgang_zustaendig($durchgang_id) || !$this->startplan->durchgang_zustaendig((int) $b['durchgang_id'])) {
			throw new \RuntimeException('Keine Berechtigung: Der Durchgang liegt außerhalb Ihrer Zuständigkeit.');
		}
		$passt = false;
		foreach ($this->zulassungen->by_durchgang($durchgang_id) as $z) {
			if (StartplanService::passt($em, $z)) {
				$passt = true;
				break;
			}
		}
		if (!$passt) {
			throw new \RuntimeException(sprintf('%s ist für Durchgang %d nicht zugelassen.', $this->name_von((int) $b['einzelmeldung_id']), (int) $dg['nummer']));
		}
		$e = $this->einheiten->find($einheit_id);
		if ($e === null || (int) $e['wettkampftag_id'] !== (int) $tag['id']) {
			throw new \RuntimeException('Einheit gehört nicht zu diesem Wettkampftag.');
		}
		$nur = EinheitRepository::disziplin_ids($e);
		if ($nur !== [] && !in_array((int) $em['disziplin_id'], $nur, true)) {
			throw new \RuntimeException(sprintf('%s ist für diese Disziplin nicht vorgesehen.', (string) $e['bezeichnung']));
		}
		if ($position < 1 || $position > (int) $e['kapazitaet']) {
			throw new \RuntimeException('Ungültige Position.');
		}
		if ($em['schuetze_id'] === null) {
			return;
		}
		foreach ($this->buchungen->by_schuetze($this->sportjahr_id, (int) $em['schuetze_id']) as $andere) {
			if ((int) $andere['id'] === (int) $b['id'] || (int) $andere['durchgang_id'] === $durchgang_id) {
				continue;
			}
			$x = $this->durchgaenge->find((int) $andere['durchgang_id']);
			if ($x !== null && (string) $x['beginn'] < (string) $dg['ende'] && (string) $dg['beginn'] < (string) $x['ende']) {
				throw new \RuntimeException(sprintf('%s steht bereits in Durchgang %d, der sich zeitlich überschneidet.', $this->name_von((int) $b['einzelmeldung_id']), (int) $x['nummer']));
			}
		}
	}

	private function name_von(int $einzelmeldung_id): string {
		$em = $this->einzel->find($einzelmeldung_id);
		$s = $em !== null && $em['schuetze_id'] !== null ? $this->schuetzen->find((int) $em['schuetze_id']) : null;
		return $s !== null ? $s['nachname'] . ', ' . $s['vorname'] : '?';
	}
}
