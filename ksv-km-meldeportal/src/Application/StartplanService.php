<?php
/**
 * Aufbau eines Wettkampftags (Konzept 12.3): Wettkampftag → Einheiten (Stände, Scheiben,
 * Rotten mit Kapazität) → Durchgänge (Beginn, Ende, zugelassene Kombinationen Disziplin ×
 * Startklasse). Platz = Durchgang × Einheit × Position.
 *
 * Alles im Status „Entwurf“ ist für Vereine unsichtbar; Freigabe und Buchung folgen
 * (Meilenstein 8). Schreiben verlangt das Recht „Startplan bearbeiten“; Referenten
 * dürfen Zulassungen nur für Disziplinen ihres Zuständigkeitsbereichs setzen.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Auth\Rechte;
use KSV\KMM\Domain\Engine\Disziplin;
use KSV\KMM\Domain\Engine\Klasse;
use KSV\KMM\Domain\RegelModus;
use KSV\KMM\Domain\Verarbeitungsstatus;
use KSV\KMM\Domain\WettkampftagStatus;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\BuchungRepository;
use KSV\KMM\Infrastructure\Repository\DurchgangRepository;
use KSV\KMM\Infrastructure\Repository\DurchgangZulassungRepository;
use KSV\KMM\Infrastructure\Repository\EinheitRepository;
use KSV\KMM\Infrastructure\Repository\EinzelmeldungRepository;
use KSV\KMM\Infrastructure\Repository\MeldungRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\WettkampftagRepository;
use KSV\KMM\Http\Router;
use KSV\KMM\Support\Clock;
use KSV\KMM\Support\Settings;

final class StartplanService {

	private WettkampftagRepository $tage;
	private EinheitRepository $einheiten;
	private DurchgangRepository $durchgaenge;
	private DurchgangZulassungRepository $zulassungen;
	private BuchungRepository $buchungen;

	public function __construct(private readonly int $sportjahr_id) {
		$this->tage = new WettkampftagRepository();
		$this->einheiten = new EinheitRepository();
		$this->durchgaenge = new DurchgangRepository();
		$this->zulassungen = new DurchgangZulassungRepository();
		$this->buchungen = new BuchungRepository();
	}

	// ----- Rechte ---------------------------------------------------------------------------

	private function schreibrecht(): void {
		if (!Rechte::hat_recht(Rechte::RECHT_STARTPLAN)) {
			throw new \RuntimeException('Kein Recht, den Startplan zu bearbeiten.');
		}
		$sportjahr = (new SportjahrRepository())->find($this->sportjahr_id);
		if ($sportjahr === null || $sportjahr['abgeschlossen_am'] !== null) {
			throw new \RuntimeException('Das Sportjahr ist abgeschlossen.');
		}
	}

	/**
	 * Darf der Benutzer diesen Durchgang anfassen? Referenten nur, wenn alle zugelassenen
	 * Disziplinen in ihrem Bereich liegen (ein leerer Durchgang gehört allen).
	 */
	public function durchgang_zustaendig(int $durchgang_id): bool {
		if (Rechte::ist_admin()) {
			return true;
		}
		$rw = RegelwerkLader::laden($this->sportjahr_id);
		foreach ($this->zulassungen->by_durchgang($durchgang_id) as $z) {
			$d = $rw->disziplin((int) $z['disziplin_id']);
			if ($d === null || !Rechte::zustaendig($d)) {
				return false;
			}
		}
		return true;
	}

	// ----- Wettkampftag --------------------------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	public function tag(int $id): array {
		$tag = $this->tage->find($id);
		if ($tag === null || (int) $tag['sportjahr_id'] !== $this->sportjahr_id) {
			throw new \RuntimeException('Wettkampftag nicht gefunden.');
		}
		return $tag;
	}

	/**
	 * @param array<string, mixed> $daten datum (Y-m-d), bezeichnung, ort, buchungsfrist (UTC|null), hinweis, beitrag_id, ergebnis_url
	 */
	public function tag_speichern(int $id, array $daten): int {
		$this->schreibrecht();
		$datum = trim((string) ($daten['datum'] ?? ''));
		$dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $datum);
		if ($dt === false || $dt->format('Y-m-d') !== $datum) {
			throw new \InvalidArgumentException('Bitte ein gültiges Datum angeben.');
		}
		$satz = [
			'datum'        => $datum,
			'bezeichnung'  => mb_substr(trim((string) ($daten['bezeichnung'] ?? '')), 0, 150),
			'ort'          => mb_substr(trim((string) ($daten['ort'] ?? '')), 0, 150),
			'buchungsfrist' => $daten['buchungsfrist'] ?? null,
			'hinweis'      => $daten['hinweis'] ?? null,
			'beitrag_id'   => isset($daten['beitrag_id']) && (int) $daten['beitrag_id'] > 0 ? (int) $daten['beitrag_id'] : null,
			'ergebnis_url' => isset($daten['ergebnis_url']) ? esc_url_raw((string) $daten['ergebnis_url']) : '',
			'sortierung'   => (int) ($daten['sortierung'] ?? 0),
			'updated_at'   => Clock::now_utc(),
		];
		if ($id > 0) {
			$this->tag($id);
			$this->tage->update($id, $satz);
			Protokoll::admin('wettkampftag.aendern', sprintf('%s %s', $datum, $satz['bezeichnung']), $this->sportjahr_id, null, 'wettkampftag', $id);
			return $id;
		}
		$satz += ['sportjahr_id' => $this->sportjahr_id, 'status' => WettkampftagStatus::ENTWURF, 'created_at' => Clock::now_utc()];
		$id = $this->tage->insert($satz);
		Protokoll::admin('wettkampftag.anlegen', sprintf('%s %s', $datum, $satz['bezeichnung']), $this->sportjahr_id, null, 'wettkampftag', $id);
		return $id;
	}

	public function tag_loeschen(int $id): void {
		$this->schreibrecht();
		if (!Rechte::ist_admin()) {
			throw new \RuntimeException('Wettkampftage löschen können nur Administratoren.');
		}
		$tag = $this->tag($id);
		if ($this->buchungen->count(['wettkampftag_id' => $id]) > 0) {
			throw new \RuntimeException('Der Wettkampftag hat Buchungen und kann nicht gelöscht werden.');
		}
		foreach ($this->durchgaenge->by_wettkampftag($id) as $dg) {
			$this->zulassungen->delete_where(['durchgang_id' => (int) $dg['id']]);
		}
		$this->durchgaenge->delete_where(['wettkampftag_id' => $id]);
		$this->einheiten->delete_where(['wettkampftag_id' => $id]);
		$this->tage->delete($id);
		Protokoll::admin('wettkampftag.loeschen', sprintf('%s %s', (string) $tag['datum'], (string) $tag['bezeichnung']), $this->sportjahr_id, null, 'wettkampftag', $id);
	}

	// ----- Einheiten ---------------------------------------------------------------------------

	/**
	 * @param int[] $disziplin_ids leer = alle Disziplinen
	 */
	public function einheit_speichern(int $id, int $tag_id, string $bezeichnung, int $kapazitaet, array $disziplin_ids = [], int $sortierung = 0): int {
		$this->schreibrecht();
		$this->tag($tag_id);
		$bezeichnung = mb_substr(trim($bezeichnung), 0, 100);
		if ($bezeichnung === '') {
			throw new \InvalidArgumentException('Bitte eine Bezeichnung angeben (z. B. Stand 1, Scheibe A, Rotte 1).');
		}
		if ($kapazitaet < 1 || $kapazitaet > 200) {
			throw new \InvalidArgumentException('Die Kapazität muss zwischen 1 und 200 liegen.');
		}
		$rw = RegelwerkLader::laden($this->sportjahr_id);
		$ids = [];
		foreach ($disziplin_ids as $did) {
			if ($rw->disziplin((int) $did) !== null) {
				$ids[] = (int) $did;
			}
		}
		$satz = ['wettkampftag_id' => $tag_id, 'bezeichnung' => $bezeichnung, 'kapazitaet' => $kapazitaet, 'disziplin_ids' => implode(',', array_unique($ids)), 'sortierung' => $sortierung];
		if ($id > 0) {
			$e = $this->einheiten->find($id);
			if ($e === null || (int) $e['wettkampftag_id'] !== $tag_id) {
				throw new \RuntimeException('Einheit nicht gefunden.');
			}
			$belegt = $this->buchungen->where(['einheit_id' => $id]);
			foreach ($belegt as $b) {
				if ((int) $b['position'] > $kapazitaet) {
					throw new \RuntimeException(sprintf('Position %d ist gebucht; die Kapazität kann nicht darunter gesenkt werden.', (int) $b['position']));
				}
			}
			$this->einheiten->update($id, $satz);
			return $id;
		}
		return $this->einheiten->insert($satz);
	}

	public function einheit_loeschen(int $id): void {
		$this->schreibrecht();
		$e = $this->einheiten->find($id);
		if ($e === null) {
			throw new \RuntimeException('Einheit nicht gefunden.');
		}
		$this->tag((int) $e['wettkampftag_id']);
		if ($this->buchungen->count(['einheit_id' => $id]) > 0) {
			throw new \RuntimeException('Die Einheit hat Buchungen und kann nicht gelöscht werden.');
		}
		$this->einheiten->delete($id);
	}

	// ----- Durchgänge --------------------------------------------------------------------------

	/**
	 * @param string $beginn_utc UTC-Datetime
	 * @param string $ende_utc   UTC-Datetime
	 */
	public function durchgang_speichern(int $id, int $tag_id, int $nummer, string $bezeichnung, ?string $beginn_utc, ?string $ende_utc, int $sortierung = 0): int {
		$this->schreibrecht();
		$tag = $this->tag($tag_id);
		if ($beginn_utc === null || $ende_utc === null) {
			throw new \InvalidArgumentException('Bitte Beginn und Ende angeben.');
		}
		if ($ende_utc <= $beginn_utc) {
			throw new \InvalidArgumentException('Das Ende muss nach dem Beginn liegen.');
		}
		if (Clock::format_local($beginn_utc, 'Y-m-d') !== (string) $tag['datum']) {
			$datum = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $tag['datum']);
			throw new \InvalidArgumentException(sprintf('Der Durchgang muss am Wettkampftag (%s) beginnen.', $datum !== false ? $datum->format('d.m.Y') : (string) $tag['datum']));
		}
		if ($id > 0 && !$this->durchgang_zustaendig($id)) {
			throw new \RuntimeException('Keine Berechtigung: Der Durchgang enthält Disziplinen außerhalb Ihrer Zuständigkeit.');
		}
		$satz = ['wettkampftag_id' => $tag_id, 'nummer' => max(0, $nummer), 'bezeichnung' => mb_substr(trim($bezeichnung), 0, 100), 'beginn' => $beginn_utc, 'ende' => $ende_utc, 'sortierung' => $sortierung];
		if ($id > 0) {
			$dg = $this->durchgaenge->find($id);
			if ($dg === null || (int) $dg['wettkampftag_id'] !== $tag_id) {
				throw new \RuntimeException('Durchgang nicht gefunden.');
			}
			$this->durchgaenge->update($id, $satz);
			return $id;
		}
		if ($nummer <= 0) {
			$satz['nummer'] = count($this->durchgaenge->by_wettkampftag($tag_id)) + 1;
		}
		return $this->durchgaenge->insert($satz);
	}

	public function durchgang_loeschen(int $id): void {
		$this->schreibrecht();
		$dg = $this->durchgaenge->find($id);
		if ($dg === null) {
			throw new \RuntimeException('Durchgang nicht gefunden.');
		}
		$this->tag((int) $dg['wettkampftag_id']);
		if (!$this->durchgang_zustaendig($id)) {
			throw new \RuntimeException('Keine Berechtigung: Der Durchgang enthält Disziplinen außerhalb Ihrer Zuständigkeit.');
		}
		if ($this->buchungen->count(['durchgang_id' => $id]) > 0) {
			throw new \RuntimeException('Der Durchgang hat Buchungen und kann nicht gelöscht werden.');
		}
		$this->zulassungen->delete_where(['durchgang_id' => $id]);
		$this->durchgaenge->delete($id);
	}

	/**
	 * Startklassen einer Disziplin (Klassen mit eigener Wertung laut Regeltabelle).
	 *
	 * @return list<Klasse>
	 */
	public function startklassen(Disziplin $d): array {
		$rw = RegelwerkLader::laden($this->sportjahr_id);
		$out = [];
		foreach ($rw->regeln_der_disziplin($d->id) as $klasse_id => $regel) {
			if ($regel->einzel_modus === RegelModus::EIGEN || ($d->ist_mixteam() && $regel->mannschaft_modus === RegelModus::EIGEN)) {
				$k = $rw->klasse((int) $klasse_id);
				if ($k !== null) {
					$out[] = $k;
				}
			}
		}
		usort($out, static fn(Klasse $a, Klasse $b): int => [$a->gruppe, $a->nummer, $a->geschlecht] <=> [$b->gruppe, $b->nummer, $b->geschlecht]);
		return $out;
	}

	/** Zulassung hinzufügen (startklasse_id null = alle Startklassen der Disziplin). */
	public function zulassung_hinzufuegen(int $durchgang_id, int $disziplin_id, ?int $startklasse_id): void {
		$this->schreibrecht();
		$dg = $this->durchgaenge->find($durchgang_id);
		if ($dg === null) {
			throw new \RuntimeException('Durchgang nicht gefunden.');
		}
		$this->tag((int) $dg['wettkampftag_id']);
		$rw = RegelwerkLader::laden($this->sportjahr_id);
		$d = $rw->disziplin($disziplin_id);
		if ($d === null) {
			throw new \InvalidArgumentException('Disziplin nicht gefunden.');
		}
		if (!Rechte::zustaendig($d) || !$this->durchgang_zustaendig($durchgang_id)) {
			throw new \RuntimeException('Keine Berechtigung: Die Disziplin liegt außerhalb Ihrer Zuständigkeit.');
		}
		if ($startklasse_id !== null) {
			$erlaubt = array_map(static fn(Klasse $k): int => $k->id, $this->startklassen($d));
			if (!in_array($startklasse_id, $erlaubt, true)) {
				throw new \InvalidArgumentException('Die Klasse ist in dieser Disziplin keine Startklasse.');
			}
		}
		foreach ($this->zulassungen->by_durchgang($durchgang_id) as $z) {
			if ((int) $z['disziplin_id'] === $disziplin_id && ($z['startklasse_id'] === null ? null : (int) $z['startklasse_id']) === $startklasse_id) {
				return; // schon vorhanden
			}
		}
		$this->zulassungen->insert(['durchgang_id' => $durchgang_id, 'disziplin_id' => $disziplin_id, 'startklasse_id' => $startklasse_id]);
	}

	public function zulassung_entfernen(int $zulassung_id): void {
		$this->schreibrecht();
		$z = $this->zulassungen->find($zulassung_id);
		if ($z === null) {
			throw new \RuntimeException('Zulassung nicht gefunden.');
		}
		$d = RegelwerkLader::laden($this->sportjahr_id)->disziplin((int) $z['disziplin_id']);
		if ($d !== null && !Rechte::zustaendig($d)) {
			throw new \RuntimeException('Keine Berechtigung: Die Disziplin liegt außerhalb Ihrer Zuständigkeit.');
		}
		$this->zulassungen->delete($zulassung_id);
	}

	// ----- Auswertung ---------------------------------------------------------------------------

	/**
	 * Passt eine Einzelmeldung zu einer Zulassung?
	 *
	 * @param array<string, mixed> $em Einzelmeldung (Zeile)
	 * @param array<string, mixed> $z  Zulassung
	 */
	public static function passt(array $em, array $z): bool {
		if ((int) $em['disziplin_id'] !== (int) $z['disziplin_id']) {
			return false;
		}
		if ($z['startklasse_id'] === null) {
			return true;
		}
		$sk = $em['startklasse_id'] !== null ? (int) $em['startklasse_id'] : ($em['mannschaft_klasse_id'] !== null ? (int) $em['mannschaft_klasse_id'] : null);
		return $sk === (int) $z['startklasse_id'];
	}

	/**
	 * Buchbare Einzelmeldungen des Sportjahres (Startrecht, kein Konflikt, nicht abgemeldet,
	 * nicht „nicht startberechtigt“, Vereinsmeldung eingereicht).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function buchbare_meldungen(): array {
		$meldungen = (new MeldungRepository())->by_sportjahr($this->sportjahr_id);
		$out = [];
		foreach ((new EinzelmeldungRepository())->by_sportjahr($this->sportjahr_id) as $em) {
			$m = $meldungen[ (int) $em['verein_id'] ] ?? null;
			if ($m === null || (string) $m['status'] === MeldungRepository::STATUS_ENTWURF || (string) $m['status'] === MeldungRepository::STATUS_OFFEN) {
				continue;
			}
			if (!$em['startrecht'] || $em['konflikt'] || $em['abgemeldet_am'] !== null || (string) $em['verarbeitungsstatus'] === Verarbeitungsstatus::NICHT_STARTBERECHTIGT) {
				continue;
			}
			$out[] = $em;
		}
		return $out;
	}

	/**
	 * Struktur eines Wettkampftags mit Kapazität und Bedarf je Durchgang.
	 *
	 * @return array<string, mixed>
	 */
	public function uebersicht(int $tag_id): array {
		$tag = $this->tag($tag_id);
		$rw = RegelwerkLader::laden($this->sportjahr_id);
		$einheiten = $this->einheiten->by_wettkampftag($tag_id);
		$buchbar = $this->buchbare_meldungen();
		$buchungen = [];
		foreach ($this->buchungen->where(['wettkampftag_id' => $tag_id]) as $b) {
			$buchungen[ (int) $b['durchgang_id'] ][] = $b;
		}
		$durchgaenge = [];
		$gesamt_plaetze = 0;
		foreach ($this->durchgaenge->by_wettkampftag($tag_id) as $dg) {
			$zul = [];
			$disziplin_ids = [];
			foreach ($this->zulassungen->by_durchgang((int) $dg['id']) as $z) {
				$d = $rw->disziplin((int) $z['disziplin_id']);
				$k = $z['startklasse_id'] !== null ? $rw->klasse((int) $z['startklasse_id']) : null;
				$zul[] = ['id' => (int) $z['id'], 'disziplin_id' => (int) $z['disziplin_id'], 'startklasse_id' => $z['startklasse_id'] !== null ? (int) $z['startklasse_id'] : null, 'disziplin' => $d !== null ? $d->kennzahl . ' ' . $d->bezeichnung : '#' . (int) $z['disziplin_id'], 'startklasse' => $k?->bezeichnung ?? 'alle Startklassen', 'zustaendig' => $d !== null && Rechte::zustaendig($d), 'roh' => $z];
				$disziplin_ids[ (int) $z['disziplin_id'] ] = true;
			}
			$plaetze = 0;
			foreach ($einheiten as $e) {
				$erlaubt = EinheitRepository::disziplin_ids($e);
				if ($erlaubt === [] || array_intersect($erlaubt, array_keys($disziplin_ids)) !== []) {
					$plaetze += (int) $e['kapazitaet'];
				}
			}
			$bedarf = 0;
			foreach ($buchbar as $em) {
				foreach ($zul as $z) {
					if (self::passt($em, $z['roh'])) {
						$bedarf++;
						break;
					}
				}
			}
			$gesamt_plaetze += $plaetze;
			$durchgaenge[] = [
				'id'          => (int) $dg['id'],
				'nummer'      => (int) $dg['nummer'],
				'bezeichnung' => (string) $dg['bezeichnung'],
				'beginn'      => (string) $dg['beginn'],
				'ende'        => (string) $dg['ende'],
				'zeit'        => Clock::format_local((string) $dg['beginn'], 'H:i') . '–' . Clock::format_local((string) $dg['ende'], 'H:i'),
				'zulassungen' => $zul,
				'plaetze'     => $plaetze,
				'bedarf'      => $bedarf,
				'gebucht'     => count($buchungen[ (int) $dg['id'] ] ?? []),
				'zustaendig'  => $this->durchgang_zustaendig((int) $dg['id']),
			];
		}
		return ['tag' => $tag, 'einheiten' => $einheiten, 'durchgaenge' => $durchgaenge, 'plaetze' => $gesamt_plaetze, 'buchungen' => array_sum(array_map('count', $buchungen))];
	}

	// ----- Freigabe (Konzept 12.4) ------------------------------------------------------------------

	public static function register(): void {
		add_action('kmm_hourly_tasks', [self::class, 'cron_erinnerung']);
	}

	/**
	 * Wettkampftag freigeben: Buchung öffnet sich, alle Vereine mit passenden Startern
	 * erhalten eine Mail mit ihrem Link.
	 *
	 * @return array{gesendet: int, vereine: int, fehler: string[]}
	 */
	public function freigeben(int $tag_id): array {
		$this->schreibrecht();
		$tag = $this->tag($tag_id);
		if ((string) $tag['status'] !== WettkampftagStatus::ENTWURF) {
			throw new \RuntimeException('Der Wettkampftag ist bereits freigegeben.');
		}
		$u = $this->uebersicht($tag_id);
		if ($u['einheiten'] === [] || $u['durchgaenge'] === []) {
			throw new \RuntimeException('Vor der Freigabe braucht der Wettkampftag mindestens eine Einheit und einen Durchgang.');
		}
		foreach ($u['durchgaenge'] as $dg) {
			if (!$dg['zustaendig']) {
				throw new \RuntimeException('Keine Berechtigung: Der Wettkampftag enthält Durchgänge außerhalb Ihrer Zuständigkeit.');
			}
			if ($dg['zulassungen'] === []) {
				throw new \RuntimeException(sprintf('Durchgang %d hat keine Zulassung.', $dg['nummer']));
			}
		}
		$jetzt = Clock::now_utc();
		$erinnerung = null;
		$tage = (int) Settings::get('buchung_erinnerung_tage');
		if ($tag['buchungsfrist'] !== null && $tage > 0) {
			$erinnerung = gmdate(Clock::DB_FORMAT, (int) strtotime((string) $tag['buchungsfrist'] . ' UTC') - $tage * 86400);
		}
		$this->tage->update($tag_id, ['status' => WettkampftagStatus::FREIGEGEBEN, 'freigegeben_am' => $jetzt, 'erinnerung_am' => $erinnerung, 'erinnerung_gesendet_am' => null]);
		$tag = $this->tag($tag_id);
		$r = $this->mail_an_vereine($tag, 'freigabe', false);
		Protokoll::admin('wettkampftag.freigeben', sprintf('%s %s freigegeben, Mail an %d Vereine%s', (string) $tag['datum'], (string) $tag['bezeichnung'], $r['gesendet'], $r['fehler'] !== [] ? ', ' . count($r['fehler']) . ' Fehler' : ''), $this->sportjahr_id, null, 'wettkampftag', $tag_id, ['fehler' => $r['fehler']]);
		return $r;
	}

	/** Freigabe zurücknehmen (nur ohne Buchungen). */
	public function freigabe_zuruecknehmen(int $tag_id): void {
		$this->schreibrecht();
		$tag = $this->tag($tag_id);
		if ((string) $tag['status'] !== WettkampftagStatus::FREIGEGEBEN) {
			throw new \RuntimeException('Der Wettkampftag ist nicht im Status „freigegeben“.');
		}
		if ($this->buchungen->count(['wettkampftag_id' => $tag_id]) > 0) {
			throw new \RuntimeException('Es gibt schon Buchungen; die Freigabe kann nicht zurückgenommen werden.');
		}
		$this->tage->update($tag_id, ['status' => WettkampftagStatus::ENTWURF, 'freigegeben_am' => null, 'erinnerung_am' => null]);
		Protokoll::admin('wettkampftag.freigabe_zurueck', sprintf('%s %s zurück in Entwurf', (string) $tag['datum'], (string) $tag['bezeichnung']), $this->sportjahr_id, null, 'wettkampftag', $tag_id);
	}

	/**
	 * Mail an alle Vereine mit passenden Startern (Freigabe) bzw. mit Startern ohne Platz
	 * (Erinnerung vor der Buchungsfrist).
	 *
	 * @param array<string, mixed> $tag
	 * @return array{gesendet: int, vereine: int, fehler: string[]}
	 */
	private function mail_an_vereine(array $tag, string $template, bool $nur_ohne_platz): array {
		$adressen = new \KSV\KMM\Infrastructure\Repository\VereinEmailRepository();
		$meldungen = new MeldungRepository();
		$gesendet = 0;
		$fehler = [];
		$betroffene = BuchungService::betroffene_vereine($this->sportjahr_id, (int) $tag['id']);
		foreach ($betroffene as $b) {
			if ($nur_ohne_platz && $b['ohne_platz'] === 0) {
				continue;
			}
			$vid = (int) $b['verein']['id'];
			$empfaenger = $adressen->adressen($vid);
			$m = $meldungen->by_verein($vid, $this->sportjahr_id);
			if ($m !== null && is_email((string) $m['ansprechpartner_email'])) {
				array_unshift($empfaenger, (string) $m['ansprechpartner_email']);
			}
			$empfaenger = array_values(array_unique($empfaenger));
			if ($empfaenger === []) {
				$fehler[] = (string) $b['verein']['name'] . ': keine E-Mail-Adresse';
				continue;
			}
			try {
				$link = Zugang::link_erzeugen($vid, $this->sportjahr_id, $template, false);
			} catch (\RuntimeException $e) {
				$fehler[] = (string) $b['verein']['name'] . ': ' . $e->getMessage();
				continue;
			}
			$datum = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $tag['datum']);
			$ok = Mailer::senden(
				$empfaenger,
				$template === 'freigabe'
					? sprintf('Startplätze buchen: %s am %s', (string) $tag['bezeichnung'], $datum !== false ? $datum->format('d.m.Y') : (string) $tag['datum'])
					: sprintf('Erinnerung: Startplätze für %s bis %s buchen', (string) $tag['bezeichnung'], Clock::format_local((string) $tag['buchungsfrist'], 'd.m.Y H:i')),
				$template,
				[
					'verein'     => $b['verein'],
					'tag'        => $tag,
					'datum'      => $datum !== false ? $datum->format('d.m.Y') : (string) $tag['datum'],
					'frist'      => $tag['buchungsfrist'] !== null ? Clock::format_local((string) $tag['buchungsfrist'], 'd.m.Y H:i') : '',
					'meldungen'  => $b['meldungen'],
					'ohne_platz' => $b['ohne_platz'],
					'url'        => $link['url'],
					'anfordern'  => Router::url('link-anfordern'),
				],
				$template === 'freigabe' ? Mailer::TYP_FREIGABE : Mailer::TYP_BUCHUNG_ERINNERUNG,
				$this->sportjahr_id,
				$vid
			);
			if ($ok) {
				$gesendet++;
			} else {
				$fehler[] = (string) $b['verein']['name'] . ': Mailversand fehlgeschlagen';
			}
		}
		return ['gesendet' => $gesendet, 'vereine' => count($betroffene), 'fehler' => $fehler];
	}

	/** Cron: Erinnerung an Vereine mit Startern ohne Platz, einige Tage vor der Buchungsfrist. */
	public static function cron_erinnerung(): void {
		$jetzt = Clock::now_utc();
		$tage = new WettkampftagRepository();
		foreach ($tage->where(['status' => WettkampftagStatus::FREIGEGEBEN]) as $tag) {
			if ($tag['erinnerung_am'] === null || $tag['erinnerung_gesendet_am'] !== null || (string) $tag['erinnerung_am'] > $jetzt) {
				continue;
			}
			if ($tag['buchungsfrist'] !== null && (string) $tag['buchungsfrist'] <= $jetzt) {
				continue;
			}
			$tage->update((int) $tag['id'], ['erinnerung_gesendet_am' => $jetzt]); // zuerst vermerken: kein Doppelversand
			$service = new self((int) $tag['sportjahr_id']);
			$r = $service->mail_an_vereine($tag, 'buchung-erinnerung', true);
			Protokoll::system('wettkampftag.erinnerung', sprintf('%s %s: Erinnerung an %d Vereine mit Startern ohne Platz%s', (string) $tag['datum'], (string) $tag['bezeichnung'], $r['gesendet'], $r['fehler'] !== [] ? ', ' . count($r['fehler']) . ' Fehler' : ''), (int) $tag['sportjahr_id'], null, 'wettkampftag', (int) $tag['id'], ['fehler' => $r['fehler']]);
		}
	}
}
