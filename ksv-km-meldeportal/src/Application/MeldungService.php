<?php
/**
 * Vereinsmeldung: Einzelmeldungen, Mannschaften, Ansprechpartner, Einreichen, Wieder öffnen,
 * Startgeldvorschau, Zusammenfassung. Alle Zugriffe sind auf den Verein der Sitzung begrenzt.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Domain\AenderungTyp;
use KSV\KMM\Domain\Engine\Bewertung;
use KSV\KMM\Domain\Engine\Engine;
use KSV\KMM\Domain\Engine\MannschaftPruefung;
use KSV\KMM\Domain\Engine\Schuetze;
use KSV\KMM\Domain\ErgebnisFormat;
use KSV\KMM\Domain\Verarbeitungsstatus;
use KSV\KMM\Domain\Meldeergebnis;
use KSV\KMM\Http\Router;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\EinzelmeldungRepository;
use KSV\KMM\Infrastructure\Repository\HoehermeldungRepository;
use KSV\KMM\Infrastructure\Repository\MannschaftRepository;
use KSV\KMM\Infrastructure\Repository\MeldungRepository;
use KSV\KMM\Infrastructure\Repository\SchuetzeRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinEmailRepository;
use KSV\KMM\Support\Clock;
use KSV\KMM\Support\Settings;

final class MeldungService {

	private MeldungRepository $meldungen;
	private EinzelmeldungRepository $einzel;
	private MannschaftRepository $mannschaften;
	private SchuetzeRepository $schuetzen;
	private HoehermeldungRepository $hoeher;

	/** @var array<string, mixed> */
	private array $sportjahr;

	private Engine $engine;

	/**
	 * @param array<string, mixed> $verein Verein der Sitzung
	 * @param bool $admin Admin-Modus (Backend-Bearbeitung nach Meldeschluss): keine Phasen- und
	 *                    Statusprüfung, Änderungen werden für Sammelmail und Export erfasst.
	 */
	public function __construct(private readonly array $verein, int $sportjahr_id, private readonly bool $admin = false) {
		$this->meldungen = new MeldungRepository();
		$this->einzel = new EinzelmeldungRepository();
		$this->mannschaften = new MannschaftRepository();
		$this->schuetzen = new SchuetzeRepository();
		$this->hoeher = new HoehermeldungRepository();
		$sportjahr = (new SportjahrRepository())->find($sportjahr_id);
		if ($sportjahr === null) {
			throw new \RuntimeException('Sportjahr nicht gefunden.');
		}
		$this->sportjahr = $sportjahr;
		$this->engine = RegelwerkLader::engine($sportjahr_id);
	}

	private function sportjahr_id(): int {
		return (int) $this->sportjahr['id'];
	}

	private function verein_id(): int {
		return (int) $this->verein['id'];
	}

	// ----- Meldung ---------------------------------------------------------------------

	/**
	 * @return array<string, mixed>|null
	 */
	public function meldung(): ?array {
		return $this->meldungen->by_verein($this->verein_id(), $this->sportjahr_id());
	}

	/**
	 * @return array<string, mixed>
	 */
	private function meldung_oder_anlegen(): array {
		$m = $this->meldung();
		if ($m !== null) {
			return $m;
		}
		$id = $this->meldungen->insert(['verein_id' => $this->verein_id(), 'sportjahr_id' => $this->sportjahr_id(), 'status' => MeldungRepository::STATUS_ENTWURF]);
		$m = $this->meldungen->find($id);
		if ($m === null) {
			throw new \RuntimeException('Meldung konnte nicht angelegt werden.');
		}
		return $m;
	}

	/**
	 * @return array{schreibbar: bool, grund: string, status: string}
	 */
	public function phase(): array {
		return Meldephase::pruefen($this->sportjahr, $this->meldung());
	}

	/**
	 * Wirft, wenn der Verein gerade nicht schreiben darf (Phase oder Status eingereicht).
	 *
	 * @return array<string, mixed> die Meldung
	 */
	private function schreibrecht(): array {
		if ($this->admin) {
			if ($this->sportjahr['abgeschlossen_am'] !== null) {
				throw new \RuntimeException('Das Sportjahr ist abgeschlossen.');
			}
			return $this->meldung_oder_anlegen();
		}
		$phase = $this->phase();
		if (!$phase['schreibbar']) {
			throw new \RuntimeException($phase['grund']);
		}
		$m = $this->meldung_oder_anlegen();
		if ((string) $m['status'] === MeldungRepository::STATUS_EINGEREICHT) {
			throw new \RuntimeException('Die Meldung ist eingereicht. Zum Bearbeiten bitte zuerst „Meldung wieder öffnen“.');
		}
		return $m;
	}

	public function ist_admin_modus(): bool {
		return $this->admin;
	}

	/** Nach dem Meldeschluss (Änderungen werden für Sammelmail und Export erfasst). */
	private function nach_meldeschluss(): bool {
		$schluss = $this->sportjahr['meldeschluss'];
		return $schluss !== null && (string) $schluss <= Clock::now_utc();
	}

	/**
	 * Änderung nach Meldeschluss erfassen (Sammelmail, „Änderungen seit Export").
	 *
	 * @param array<string, mixed> $details
	 */
	private function aenderung(?int $einzelmeldung_id, string $typ, string $text, array $details = []): void {
		if (!$this->nach_meldeschluss()) {
			return; // Während der Meldephase (auch Admin-Bearbeitung) ist es keine „Änderung nach Meldeschluss".
		}
		Aenderungen::erfassen($this->sportjahr_id(), $this->verein_id(), $einzelmeldung_id, $typ, $text, $details);
	}

	/**
	 * @param array<string, mixed> $details
	 */
	private function protokoll(string $aktion, string $text, string $objekt_typ = '', ?int $objekt_id = null, array $details = []): void {
		if ($this->admin) {
			Protokoll::admin($aktion, sprintf('[%s] %s', (string) $this->verein['name'], $text), $this->sportjahr_id(), $this->verein_id(), $objekt_typ, $objekt_id, $details);
		} else {
			Protokoll::verein($this->verein_id(), (string) $this->verein['name'], $aktion, $text, $this->sportjahr_id(), $objekt_typ, $objekt_id, $details);
		}
	}

	private function status_entwurf(int $meldung_id): void {
		$m = $this->meldungen->find($meldung_id);
		if ($m !== null && (string) $m['status'] === MeldungRepository::STATUS_OFFEN) {
			$this->meldungen->update($meldung_id, ['status' => MeldungRepository::STATUS_ENTWURF]);
		}
	}

	// ----- Angebot ------------------------------------------------------------------------

	/**
	 * Disziplinen mit Startrecht für einen Schützen, inklusive Para-Optionen.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function angebot(int $schuetze_id): array {
		$s = $this->eigener_schuetze($schuetze_id);
		$dto = $this->dto($s);
		$gemeldet = [];
		foreach ($this->einzel->where(['schuetze_id' => $schuetze_id, 'sportjahr_id' => $this->sportjahr_id()]) as $em) {
			$gemeldet[ (int) $em['disziplin_id'] ] = (int) $em['id'];
		}
		$out = [];
		foreach ($this->engine->regelwerk()->disziplinen() as $d) {
			if (!$d->angeboten) {
				continue;
			}
			$b = $this->engine->bewerte($d, $dto);
			$para = [];
			foreach ($this->engine->para_angebot($d, $dto) as $p) {
				$para[] = ['id' => $p->id, 'bezeichnung' => $p->bezeichnung];
			}
			if (!$b->startrecht && $para === []) {
				continue;
			}
			$out[] = [
				'disziplin_id'     => $d->id,
				'kennzahl'         => $d->kennzahl,
				'bezeichnung'      => $d->bezeichnung,
				'gruppe'           => $d->gruppe,
				'typ'              => $d->typ,
				'ergebnis_format'  => $d->ergebnis_format,
				'startrecht'       => $b->startrecht,
				'klasse'           => $b->klasse?->bezeichnung,
				'startklasse'      => $b->startklasse?->bezeichnung ?? $b->mannschaft_klasse?->bezeichnung,
				'kennzahl_voll'    => $b->kennzahl,
				'mannschaftspool'  => $b->mannschaft_klasse?->bezeichnung,
				'startgeld'        => $b->startgeld,
				'hinweise'         => $b->hinweise,
				'para_optionen'    => $para,
				'gemeldet_id'      => $gemeldet[ $d->id ] ?? null,
			];
		}
		return $out;
	}

	// ----- Einzelmeldungen ------------------------------------------------------------

	/**
	 * @return array<string, mixed> die neue Einzelmeldung (Ansicht)
	 */
	public function einzel_anlegen(int $schuetze_id, int $disziplin_id, ?int $para_klasse_id = null): array {
		$m = $this->schreibrecht();
		$s = $this->eigener_schuetze($schuetze_id);
		$d = $this->engine->regelwerk()->disziplin($disziplin_id);
		if ($d === null || !$d->angeboten) {
			throw new \RuntimeException('Disziplin wird nicht angeboten.');
		}
		if ($this->einzel->first(['meldung_id' => (int) $m['id'], 'schuetze_id' => $schuetze_id, 'disziplin_id' => $disziplin_id]) !== null) {
			throw new \RuntimeException('Der Schütze ist in dieser Disziplin bereits gemeldet.');
		}
		$b = $this->engine->bewerte($d, $this->dto($s, $para_klasse_id));
		if (!$b->startrecht) {
			throw new \RuntimeException($b->grund !== '' ? $b->grund : 'Kein Startrecht in dieser Disziplin.');
		}
		$id = $this->einzel->insert(array_merge([
			'meldung_id'     => (int) $m['id'],
			'sportjahr_id'   => $this->sportjahr_id(),
			'verein_id'      => $this->verein_id(),
			'schuetze_id'    => $schuetze_id,
			'disziplin_id'   => $disziplin_id,
			'geschlecht'     => (string) $s['geschlecht'],
			'geburtsjahr'    => (int) substr((string) $s['geburtsdatum'], 0, 4),
			'para_klasse_id' => $para_klasse_id,
		], Revalidierung::felder($b)));
		if ($id <= 0) {
			throw new \RuntimeException('Meldung konnte nicht gespeichert werden: ' . $this->einzel->last_error());
		}
		$this->status_entwurf((int) $m['id']);
		$this->schuetzen->update($schuetze_id, ['zuletzt_gemeldet_jahr' => (int) $this->sportjahr['jahr']]);
		$text = sprintf('%s, %s in %s (%s)', (string) $s['nachname'], (string) $s['vorname'], $d->kennzahl, $b->kennzahl);
		if ($this->nach_meldeschluss()) {
			$this->einzel->update($id, ['nachgemeldet' => true]);
			$this->aenderung($id, AenderungTyp::NACHMELDUNG, $text);
		}
		$this->protokoll('meldung.einzel.anlegen', $text, 'einzelmeldung', $id);
		return $this->einzel_ansicht((array) $this->einzel->find($id), $s);
	}

	/**
	 * @param array<string, mixed> $daten meldeergebnis (string), nicht_meldung (bool), para_klasse_id
	 * @return array<string, mixed>
	 */
	public function einzel_aendern(int $id, array $daten): array {
		$this->schreibrecht();
		$em = $this->eigene_einzelmeldung($id);
		$d = $this->engine->regelwerk()->disziplin((int) $em['disziplin_id']);
		$update = [];
		if (array_key_exists('meldeergebnis', $daten)) {
			try {
				$update['meldeergebnis'] = Meldeergebnis::parse((string) $daten['meldeergebnis'], $d?->ergebnis_format ?? ErgebnisFormat::GANZ);
			} catch (\InvalidArgumentException $e) {
				throw new \RuntimeException($e->getMessage());
			}
		}
		if (array_key_exists('nicht_meldung', $daten)) {
			$update['nicht_meldung'] = (bool) $daten['nicht_meldung'];
		}
		if (array_key_exists('para_klasse_id', $daten) && $d !== null) {
			$s = $this->eigener_schuetze((int) $em['schuetze_id']);
			$para = $daten['para_klasse_id'] !== null && $daten['para_klasse_id'] !== '' ? (int) $daten['para_klasse_id'] : null;
			$b = $this->engine->bewerte($d, $this->dto($s, $para));
			if (!$b->startrecht) {
				throw new \RuntimeException($b->grund);
			}
			$update = array_merge($update, Revalidierung::felder($b), ['para_klasse_id' => $para, 'konflikt' => false, 'konflikt_text' => '']);
			if ($em['mannschaft_id'] !== null && $b->mannschaft_klasse?->id !== (int) $em['mannschaft_klasse_id']) {
				$this->aus_mannschaft_entfernen($em);
			}
		}
		if ($update !== []) {
			$this->einzel->update($id, $update);
			if ($this->nach_meldeschluss()) {
				$ansicht = $this->einzel_ansicht((array) $this->einzel->find($id));
				$was = [];
				if (array_key_exists('meldeergebnis', $update)) {
					$was[] = 'Meldeergebnis ' . ($ansicht['meldeergebnis'] !== '' ? $ansicht['meldeergebnis'] : 'entfernt');
				}
				if (array_key_exists('nicht_meldung', $update)) {
					$was[] = 'Nicht-Meldung ' . ($update['nicht_meldung'] ? 'gesetzt' : 'entfernt');
				}
				if (array_key_exists('para_klasse_id', $update)) {
					$was[] = 'Para-Klasse ' . ($ansicht['para'] ?? 'entfernt') . ', Startklasse ' . $ansicht['startklasse'];
				}
				$this->aenderung($id, AenderungTyp::KORREKTUR, sprintf('%s, %s in %s: %s', $ansicht['nachname'], $ansicht['vorname'], $ansicht['kennzahl'], implode('; ', $was)), $update);
			}
		}
		return $this->einzel_ansicht((array) $this->einzel->find($id));
	}

	public function einzel_loeschen(int $id): void {
		$this->schreibrecht();
		$em = $this->eigene_einzelmeldung($id);
		if ($em['mannschaft_id'] !== null) {
			$this->aus_mannschaft_entfernen($em);
		}
		$buchung = (new \KSV\KMM\Infrastructure\Repository\BuchungRepository())->by_einzelmeldung($id);
		if ($buchung !== null) {
			(new \KSV\KMM\Infrastructure\Repository\BuchungRepository())->delete((int) $buchung['id']);
		}
		$this->einzel->delete($id);
		$s = $em['schuetze_id'] !== null ? $this->schuetzen->find((int) $em['schuetze_id']) : null;
		$d = $this->engine->regelwerk()->disziplin((int) $em['disziplin_id']);
		$text = sprintf('%s in %s', $s !== null ? $s['nachname'] . ', ' . $s['vorname'] : '?', $d?->kennzahl ?? '?');
		$this->aenderung(null, AenderungTyp::KORREKTUR, 'Meldung entfernt: ' . $text);
		$this->protokoll('meldung.einzel.loeschen', $text, 'einzelmeldung', $id);
	}

	// ----- Abmeldung (nur Backend) ------------------------------------------------------------

	/**
	 * Meldung abmelden (Zeitpunkt, Grund, Schalter „Startgeld berechnen"). Gebuchter Platz
	 * wird frei, die Mannschaft ggf. als unvollständig markiert.
	 *
	 * @return array<string, mixed>
	 */
	public function abmelden(int $id, string $grund = '', ?bool $startgeld_berechnen = null): array {
		$this->admin_only();
		$em = $this->eigene_einzelmeldung($id);
		if ($em['abgemeldet_am'] !== null) {
			throw new \RuntimeException('Die Meldung ist bereits abgemeldet.');
		}
		$berechnen = $startgeld_berechnen ?? Abmeldung::startgeld_standard($em);
		$this->einzel->update($id, ['abgemeldet_am' => Clock::now_utc(), 'abmeldegrund' => mb_substr(trim($grund), 0, 255), 'startgeld_berechnen' => $berechnen]);
		$buchungen = new \KSV\KMM\Infrastructure\Repository\BuchungRepository();
		$b = $buchungen->by_einzelmeldung($id);
		if ($b !== null) {
			$buchungen->delete((int) $b['id']);
		}
		if ($em['mannschaft_id'] !== null) {
			(new VerarbeitungService($this->sportjahr_id()))->mannschaft_pruefen((int) $em['mannschaft_id']);
		}
		$ansicht = $this->einzel_ansicht((array) $this->einzel->find($id));
		$text = sprintf('%s, %s in %s abgemeldet%s (Startgeld %s)', $ansicht['nachname'], $ansicht['vorname'], $ansicht['kennzahl'], $grund !== '' ? ' – ' . $grund : '', $berechnen ? 'wird berechnet' : 'entfällt');
		Aenderungen::erfassen($this->sportjahr_id(), $this->verein_id(), $id, AenderungTyp::ABMELDUNG, $text, ['startgeld_berechnen' => $berechnen, 'platz_frei' => $b !== null]);
		$this->protokoll('meldung.abmelden', $text, 'einzelmeldung', $id, ['startgeld_berechnen' => $berechnen]);
		return $ansicht;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function abmeldung_aufheben(int $id): array {
		$this->admin_only();
		$em = $this->eigene_einzelmeldung($id);
		if ($em['abgemeldet_am'] === null) {
			throw new \RuntimeException('Die Meldung ist nicht abgemeldet.');
		}
		$this->einzel->update($id, ['abgemeldet_am' => null, 'abmeldegrund' => '', 'startgeld_berechnen' => true]);
		if ($em['mannschaft_id'] !== null) {
			(new VerarbeitungService($this->sportjahr_id()))->mannschaft_pruefen((int) $em['mannschaft_id']);
		}
		$ansicht = $this->einzel_ansicht((array) $this->einzel->find($id));
		$text = sprintf('Abmeldung aufgehoben: %s, %s in %s', $ansicht['nachname'], $ansicht['vorname'], $ansicht['kennzahl']);
		Aenderungen::erfassen($this->sportjahr_id(), $this->verein_id(), $id, AenderungTyp::KORREKTUR, $text);
		$this->protokoll('meldung.abmeldung_aufheben', $text, 'einzelmeldung', $id);
		return $ansicht;
	}

	/**
	 * Schalter „Startgeld berechnen" (in beide Richtungen übersteuerbar).
	 *
	 * @return array<string, mixed>
	 */
	public function startgeld_schalter(int $id, bool $berechnen): array {
		$this->admin_only();
		$this->eigene_einzelmeldung($id);
		$this->einzel->update($id, ['startgeld_berechnen' => $berechnen]);
		$ansicht = $this->einzel_ansicht((array) $this->einzel->find($id));
		$this->protokoll('meldung.startgeld_schalter', sprintf('%s, %s in %s: Startgeld %s', $ansicht['nachname'], $ansicht['vorname'], $ansicht['kennzahl'], $berechnen ? 'wird berechnet' : 'entfällt'), 'einzelmeldung', $id);
		return $ansicht;
	}

	/** Nachmeldungs-Freischaltung für den Verein (Backend). */
	public function nachmeldung_freischalten(?string $bis_utc): void {
		$this->admin_only();
		$m = $this->meldung_oder_anlegen();
		$this->meldungen->update((int) $m['id'], ['nachmeldung_bis' => $bis_utc]);
		$this->protokoll('meldung.nachmeldung', $bis_utc !== null ? sprintf('Nachmeldung freigeschaltet bis %s', Clock::format_local($bis_utc)) : 'Nachmeldungs-Freischaltung beendet', 'meldung', (int) $m['id']);
	}

	private function admin_only(): void {
		if (!$this->admin) {
			throw new \RuntimeException('Nur im Backend möglich.');
		}
	}

	/**
	 * Konflikt nach Regeländerung: Verein bestätigt die neue Bewertung.
	 */
	public function konflikt_bestaetigen(int $id): void {
		$this->schreibrecht();
		$em = $this->eigene_einzelmeldung($id);
		if (!$em['startrecht']) {
			throw new \RuntimeException('Kein Startrecht mehr – bitte die Meldung entfernen.');
		}
		$this->einzel->update($id, ['konflikt' => false, 'konflikt_text' => '']);
	}

	// ----- Mannschaften ------------------------------------------------------------------

	/**
	 * Einzelmeldungen, die in eine Mannschaft dieser Disziplin passen (frei oder in $mannschaft_id).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function kandidaten(int $disziplin_id, ?int $mannschaft_id = null): array {
		$m = $this->meldung();
		if ($m === null) {
			return [];
		}
		$d = $this->engine->regelwerk()->disziplin($disziplin_id);
		if ($d === null || !$d->hat_mannschaften()) {
			return [];
		}
		$vorhanden = [];
		if ($mannschaft_id !== null) {
			foreach ($this->einzel->where(['mannschaft_id' => $mannschaft_id]) as $em) {
				$vorhanden[] = ['bewertung' => $this->bewertung_aus_zeile($em), 'geschlecht' => (string) $em['geschlecht']];
			}
		}
		$out = [];
		foreach ($this->einzel->where(['meldung_id' => (int) $m['id'], 'disziplin_id' => $disziplin_id]) as $em) {
			if (!$em['startrecht'] || $em['konflikt'] || $em['abgemeldet_am'] !== null || (string) $em['verarbeitungsstatus'] === Verarbeitungsstatus::NICHT_STARTBERECHTIGT) {
				continue;
			}
			if ($em['mannschaft_id'] !== null && (int) $em['mannschaft_id'] !== $mannschaft_id) {
				continue;
			}
			$b = $this->bewertung_aus_zeile($em);
			$ist_mitglied = $em['mannschaft_id'] !== null;
			$andere = array_values(array_filter($vorhanden, static fn(array $v): bool => true));
			if (!$ist_mitglied && !MannschaftPruefung::passt($d, $b, (string) $em['geschlecht'], $andere)) {
				continue;
			}
			$out[] = $this->einzel_ansicht($em) + ['mitglied' => $ist_mitglied];
		}
		return $out;
	}

	/**
	 * @param int[] $einzelmeldung_ids
	 * @return array<string, mixed>
	 */
	public function mannschaft_speichern(?int $mannschaft_id, int $disziplin_id, array $einzelmeldung_ids): array {
		$m = $this->schreibrecht();
		$d = $this->engine->regelwerk()->disziplin($disziplin_id);
		if ($d === null || !$d->hat_mannschaften()) {
			throw new \RuntimeException('In dieser Disziplin gibt es keine Mannschaften.');
		}
		$einzelmeldung_ids = array_values(array_unique(array_map('intval', $einzelmeldung_ids)));
		if ($einzelmeldung_ids === []) {
			throw new \RuntimeException('Bitte mindestens einen Schützen wählen.');
		}
		$mannschaft = null;
		if ($mannschaft_id !== null) {
			$mannschaft = $this->eigene_mannschaft($mannschaft_id);
			if ((int) $mannschaft['disziplin_id'] !== $disziplin_id) {
				throw new \RuntimeException('Mannschaft gehört zu einer anderen Disziplin.');
			}
		}
		$mitglieder = [];
		$rows = [];
		foreach ($einzelmeldung_ids as $eid) {
			$em = $this->eigene_einzelmeldung($eid);
			if ((int) $em['disziplin_id'] !== $disziplin_id) {
				throw new \RuntimeException('Ein gewählter Schütze ist nicht in dieser Disziplin gemeldet.');
			}
			if ($em['mannschaft_id'] !== null && (int) $em['mannschaft_id'] !== $mannschaft_id) {
				throw new \RuntimeException('Ein gewählter Schütze steht bereits in einer anderen Mannschaft dieser Disziplin.');
			}
			if (!$em['startrecht'] || $em['konflikt']) {
				throw new \RuntimeException('Ein gewählter Schütze hat einen ungeklärten Konflikt oder kein Startrecht.');
			}
			$mitglieder[] = ['bewertung' => $this->bewertung_aus_zeile($em), 'geschlecht' => (string) $em['geschlecht']];
			$rows[] = $em;
		}
		$fehler = MannschaftPruefung::pruefe($d, $mitglieder, false);
		if ($fehler !== []) {
			throw new \RuntimeException(implode(' ', $fehler));
		}
		$pool = $mitglieder[0]['bewertung']->mannschaft_klasse;
		$vollstaendig = count($mitglieder) === $d->mannschaft_groesse;
		if ($mannschaft === null) {
			$nummer = 1;
			foreach ($this->mannschaften->where(['meldung_id' => (int) $m['id'], 'disziplin_id' => $disziplin_id]) as $vorh) {
				$nummer = max($nummer, (int) $vorh['nummer'] + 1);
			}
			$mannschaft_id = $this->mannschaften->insert([
				'meldung_id'     => (int) $m['id'],
				'sportjahr_id'   => $this->sportjahr_id(),
				'verein_id'      => $this->verein_id(),
				'disziplin_id'   => $disziplin_id,
				'klasse_id'      => $pool?->id,
				'nummer'         => $nummer,
				'startgeld'      => $d->mannschaft_startgeld,
				'unvollstaendig' => !$vollstaendig,
			]);
			$aktion = 'meldung.mannschaft.anlegen';
		} else {
			$this->mannschaften->update($mannschaft_id, ['klasse_id' => $pool?->id, 'startgeld' => $d->mannschaft_startgeld, 'unvollstaendig' => !$vollstaendig]);
			foreach ($this->einzel->where(['mannschaft_id' => $mannschaft_id]) as $alt) {
				if (!in_array((int) $alt['id'], $einzelmeldung_ids, true)) {
					$this->einzel->update((int) $alt['id'], ['mannschaft_id' => null]);
				}
			}
			$aktion = 'meldung.mannschaft.aendern';
		}
		foreach ($rows as $em) {
			$this->einzel->update((int) $em['id'], ['mannschaft_id' => $mannschaft_id]);
		}
		$this->status_entwurf((int) $m['id']);
		$text = sprintf('%s Mannschaft %d (%d Mitglieder)', $d->kennzahl, (int) ($this->mannschaften->find((int) $mannschaft_id)['nummer'] ?? 0), count($rows));
		$this->aenderung(null, AenderungTyp::MANNSCHAFT, ($aktion === 'meldung.mannschaft.anlegen' ? 'Mannschaft angelegt: ' : 'Mannschaft geändert: ') . $text);
		$this->protokoll($aktion, $text, 'mannschaft', $mannschaft_id);
		return $this->mannschaft_ansicht((array) $this->mannschaften->find((int) $mannschaft_id));
	}

	public function mannschaft_loeschen(int $mannschaft_id): void {
		$this->schreibrecht();
		$mannschaft = $this->eigene_mannschaft($mannschaft_id);
		foreach ($this->einzel->where(['mannschaft_id' => $mannschaft_id]) as $em) {
			$this->einzel->update((int) $em['id'], ['mannschaft_id' => null]);
		}
		$this->mannschaften->delete($mannschaft_id);
		$d = $this->engine->regelwerk()->disziplin((int) $mannschaft['disziplin_id']);
		$text = sprintf('%s Mannschaft %d', $d?->kennzahl ?? '?', (int) $mannschaft['nummer']);
		$this->aenderung(null, AenderungTyp::MANNSCHAFT, 'Mannschaft aufgelöst: ' . $text);
		$this->protokoll('meldung.mannschaft.loeschen', $text, 'mannschaft', $mannschaft_id);
	}

	/**
	 * @param array<string, mixed> $em
	 */
	private function aus_mannschaft_entfernen(array $em): void {
		$mid = (int) $em['mannschaft_id'];
		$this->einzel->update((int) $em['id'], ['mannschaft_id' => null]);
		$rest = $this->einzel->count(['mannschaft_id' => $mid]) - 1;
		if ($rest <= 0) {
			$this->mannschaften->delete($mid);
		} else {
			$this->mannschaften->update($mid, ['unvollstaendig' => true]);
		}
	}

	// ----- Ansprechpartner, Einreichen, Öffnen -------------------------------------------

	/**
	 * @param array<string, mixed> $daten name, email, telefon
	 */
	public function ansprechpartner_speichern(array $daten): void {
		$m = $this->schreibrecht();
		$name = trim((string) ($daten['name'] ?? ''));
		$email = sanitize_email((string) ($daten['email'] ?? ''));
		$telefon = trim((string) ($daten['telefon'] ?? ''));
		if ($name === '' || !is_email($email)) {
			throw new \RuntimeException('Bitte Name und eine gültige E-Mail-Adresse des Ansprechpartners angeben.');
		}
		$this->meldungen->update((int) $m['id'], [
			'ansprechpartner_name'    => mb_substr($name, 0, 150),
			'ansprechpartner_email'   => $email,
			'ansprechpartner_telefon' => mb_substr($telefon, 0, 50),
		]);
		$this->status_entwurf((int) $m['id']);
	}

	/**
	 * Prüfungen vor dem Einreichen.
	 *
	 * @return array{fehler: string[], warnungen: string[]}
	 */
	public function einreichen_pruefen(): array {
		$m = $this->meldung();
		$fehler = [];
		$warnungen = [];
		if ($m === null) {
			return ['fehler' => ['Es ist noch nichts gemeldet.'], 'warnungen' => []];
		}
		$phase = $this->phase();
		if (!$phase['schreibbar']) {
			$fehler[] = $phase['grund'];
		}
		if ((string) $m['ansprechpartner_name'] === '' || !is_email((string) $m['ansprechpartner_email'])) {
			$fehler[] = 'Ansprechpartner mit Name und E-Mail-Adresse fehlt.';
		}
		$einzel = $this->einzel->by_meldung((int) $m['id']);
		if ($einzel === []) {
			$fehler[] = 'Es ist noch kein Starter gemeldet.';
		}
		$konflikte = 0;
		$ohne_ergebnis = 0;
		foreach ($einzel as $em) {
			if ($em['konflikt'] || !$em['startrecht']) {
				$konflikte++;
			}
			$d = $this->engine->regelwerk()->disziplin((int) $em['disziplin_id']);
			if ($em['meldeergebnis'] === null && $d !== null && !$d->ist_mixteam()) {
				$ohne_ergebnis++;
			}
		}
		if ($konflikte > 0) {
			$fehler[] = sprintf('%d Meldung(en) mit ungeklärtem Konflikt oder ohne Startrecht.', $konflikte);
		}
		foreach ($this->mannschaften->by_meldung((int) $m['id']) as $ma) {
			$n = $this->einzel->count(['mannschaft_id' => (int) $ma['id']]);
			$d = $this->engine->regelwerk()->disziplin((int) $ma['disziplin_id']);
			if ($d !== null && $n !== $d->mannschaft_groesse) {
				$fehler[] = sprintf('%s Mannschaft %d ist unvollständig (%d von %d).', $d->kennzahl, (int) $ma['nummer'], $n, $d->mannschaft_groesse);
			}
		}
		if ($ohne_ergebnis > 0) {
			$warnungen[] = sprintf('%d Meldung(en) ohne Meldeergebnis. Das Ergebnis der Vereinsmeisterschaft wird für die Startplanung dringend benötigt.', $ohne_ergebnis);
		}
		return ['fehler' => $fehler, 'warnungen' => $warnungen];
	}

	/**
	 * @return array<string, mixed> Zusammenfassung
	 */
	public function einreichen(): array {
		$m = $this->schreibrecht();
		$pruefung = $this->einreichen_pruefen();
		if ($pruefung['fehler'] !== []) {
			throw new \RuntimeException(implode(' ', $pruefung['fehler']));
		}
		$zusammenfassung = $this->zusammenfassung();
		$this->meldungen->update((int) $m['id'], [
			'status'          => MeldungRepository::STATUS_EINGEREICHT,
			'eingereicht_am'  => Clock::now_utc(),
			'startgeld_summe' => $zusammenfassung['startgeld']['summe'],
		]);
		Protokoll::verein($this->verein_id(), (string) $this->verein['name'], 'meldung.einreichen', sprintf('%d Einzelmeldungen, %d Mannschaften, Startgeld %s', count($zusammenfassung['einzelmeldungen']), count($zusammenfassung['mannschaften']), number_format((float) $zusammenfassung['startgeld']['summe'], 2, ',', '.') . ' €'), $this->sportjahr_id(), 'meldung', (int) $m['id']);

		$empfaenger = (new VereinEmailRepository())->adressen($this->verein_id());
		$empfaenger[] = (string) $m['ansprechpartner_email'];
		Mailer::senden(
			$empfaenger,
			sprintf('KM %d: Meldung von %s eingereicht', (int) $this->sportjahr['jahr'], (string) $this->verein['name']),
			'bestaetigung',
			['zusammenfassung' => $zusammenfassung, 'verein' => $this->verein, 'sportjahr' => $this->sportjahr, 'meldung' => $this->meldungen->find((int) $m['id']), 'url' => Router::url(), 'warnungen' => $pruefung['warnungen']],
			Mailer::TYP_BESTAETIGUNG,
			$this->sportjahr_id(),
			$this->verein_id()
		);
		return $this->zusammenfassung();
	}

	public function wieder_oeffnen(): void {
		$phase = $this->phase();
		if (!$phase['schreibbar']) {
			throw new \RuntimeException($phase['grund']);
		}
		$m = $this->meldung();
		if ($m === null || (string) $m['status'] !== MeldungRepository::STATUS_EINGEREICHT) {
			throw new \RuntimeException('Die Meldung ist nicht eingereicht.');
		}
		$this->meldungen->update((int) $m['id'], ['status' => MeldungRepository::STATUS_ENTWURF, 'wieder_geoeffnet_am' => Clock::now_utc()]);
		Protokoll::verein($this->verein_id(), (string) $this->verein['name'], 'meldung.oeffnen', 'Meldung wieder geöffnet', $this->sportjahr_id(), 'meldung', (int) $m['id']);
	}

	// ----- Zusammenfassung ------------------------------------------------------------------

	/**
	 * Vollständiger Zustand für Oberfläche, Mail und PDF.
	 *
	 * @return array<string, mixed>
	 */
	public function zusammenfassung(): array {
		$m = $this->meldung();
		$schuetzen = [];
		foreach ($this->schuetzen->by_verein($this->verein_id()) as $s) {
			$schuetzen[ (int) $s['id'] ] = $s;
		}
		$einzel = [];
		$roh = [];
		$mannschaften = [];
		$summe_einzel = 0.0;
		$summe_mannschaft = 0.0;
		$ohne_ergebnis = 0;
		$konflikte = 0;
		if ($m !== null) {
			foreach ($this->einzel->by_meldung((int) $m['id']) as $em) {
				$roh[] = $em;
				$ansicht = $this->einzel_ansicht($em, $schuetzen[ (int) $em['schuetze_id'] ] ?? null);
				$einzel[] = $ansicht;
				$zaehlt = $em['startrecht'] && !$em['konflikt'] && (string) $em['verarbeitungsstatus'] !== Verarbeitungsstatus::NICHT_STARTBERECHTIGT && ($em['abgemeldet_am'] === null || $em['startgeld_berechnen']);
				if ($zaehlt) {
					$summe_einzel += (float) $em['startgeld'];
				}
				if ($ansicht['meldeergebnis'] === '' && $ansicht['typ'] !== 'mixteam') {
					$ohne_ergebnis++;
				}
				if ($em['konflikt'] || !$em['startrecht']) {
					$konflikte++;
				}
			}
			foreach ($this->mannschaften->by_meldung((int) $m['id']) as $ma) {
				$ansicht = $this->mannschaft_ansicht($ma);
				$mannschaften[] = $ansicht;
				$summe_mannschaft += (float) $ma['startgeld'];
			}
		}
		usort($einzel, static fn(array $a, array $b): int => [$a['sortierung'], $a['nachname'], $a['vorname']] <=> [$b['sortierung'], $b['nachname'], $b['vorname']]);
		return [
			'verein'          => ['id' => $this->verein_id(), 'name' => (string) $this->verein['name'], 'vn_nummer' => (string) $this->verein['vn_nummer']],
			'sportjahr'       => ['id' => $this->sportjahr_id(), 'jahr' => (int) $this->sportjahr['jahr'], 'meldeschluss' => Clock::format_local($this->sportjahr['meldeschluss']), 'meldung_beginn' => Clock::format_local($this->sportjahr['meldung_beginn'])],
			'status'          => VerarbeitungService::vereinsstatus($m !== null ? (string) $m['status'] : MeldungRepository::STATUS_OFFEN, $roh),
			'eingereicht_am'  => $m !== null ? Clock::format_local($m['eingereicht_am']) : '',
			'phase'           => $this->phase(),
			'ansprechpartner' => ['name' => (string) ($m['ansprechpartner_name'] ?? ''), 'email' => (string) ($m['ansprechpartner_email'] ?? ''), 'telefon' => (string) ($m['ansprechpartner_telefon'] ?? '')],
			'einzelmeldungen' => $einzel,
			'mannschaften'    => $mannschaften,
			'startgeld'       => ['einzel' => round($summe_einzel, 2), 'mannschaften' => round($summe_mannschaft, 2), 'summe' => round($summe_einzel + $summe_mannschaft, 2)],
			'ohne_ergebnis'   => $ohne_ergebnis,
			'konflikte'       => $konflikte,
			'einstellungen'   => ['nicht_meldung_sichtbar' => (bool) Settings::get('nicht_meldung_sichtbar')],
			'admin'           => $this->admin,
			'nach_meldeschluss' => $this->nach_meldeschluss(),
			'nachmeldung_bis' => $m !== null && $m['nachmeldung_bis'] !== null ? Clock::format_local($m['nachmeldung_bis']) : '',
			'nachmeldung_bis_input' => $m !== null ? Clock::utc_to_local_input($m['nachmeldung_bis']) : '',
			'pruefung'        => $this->einreichen_pruefen(),
		];
	}

	/**
	 * @param array<string, mixed>      $em
	 * @param array<string, mixed>|null $s
	 * @return array<string, mixed>
	 */
	private function einzel_ansicht(array $em, ?array $s = null): array {
		$rw = $this->engine->regelwerk();
		$d = $rw->disziplin((int) $em['disziplin_id']);
		$s ??= $em['schuetze_id'] !== null ? $this->schuetzen->find((int) $em['schuetze_id']) : null;
		$klasse = $em['klasse_id'] !== null ? $rw->klasse((int) $em['klasse_id']) : null;
		$startklasse = $em['startklasse_id'] !== null ? $rw->klasse((int) $em['startklasse_id']) : null;
		$pool = $em['mannschaft_klasse_id'] !== null ? $rw->klasse((int) $em['mannschaft_klasse_id']) : null;
		$para = $em['para_klasse_id'] !== null ? $rw->klasse((int) $em['para_klasse_id']) : null;
		$kennzahl = '';
		$hinweise = [];
		if ($d !== null && $s !== null) {
			$b = $this->bewertung_aus_zeile($em, $s);
			$kennzahl = $b->kennzahl;
			$hinweise = $b->hinweise;
		}
		$mannschaft = $em['mannschaft_id'] !== null ? $this->mannschaften->find((int) $em['mannschaft_id']) : null;
		return [
			'id'                 => (int) $em['id'],
			'schuetze_id'        => $em['schuetze_id'] !== null ? (int) $em['schuetze_id'] : null,
			'nachname'           => (string) ($s['nachname'] ?? ''),
			'vorname'            => (string) ($s['vorname'] ?? ''),
			'geschlecht'         => (string) $em['geschlecht'],
			'disziplin_id'       => (int) $em['disziplin_id'],
			'kennzahl'           => $d?->kennzahl ?? '',
			'disziplin'          => $d?->bezeichnung ?? '',
			'typ'                => $d?->typ ?? 'normal',
			'ergebnis_format'    => $d?->ergebnis_format ?? 'ganz',
			'klasse'             => $klasse?->bezeichnung ?? '',
			'startklasse'        => $startklasse?->bezeichnung ?? ($d !== null && $d->ist_mixteam() ? ($pool?->bezeichnung ?? '') : ''),
			'startklasse_nummer' => $startklasse?->nummer,
			'kennzahl_voll'      => $kennzahl,
			'mannschaftspool'    => $pool?->bezeichnung,
			'mannschaft_moeglich' => $d !== null && $d->hat_mannschaften() && $pool !== null,
			'para'               => $para?->bezeichnung,
			'para_klasse_id'     => $em['para_klasse_id'] !== null ? (int) $em['para_klasse_id'] : null,
			'hoehermeldung'      => (bool) $em['hoehermeldung_angewendet'],
			'startrecht'         => (bool) $em['startrecht'],
			'meldeergebnis'      => Meldeergebnis::format($em['meldeergebnis'] !== null ? (float) $em['meldeergebnis'] : null, $d?->ergebnis_format ?? 'ganz'),
			'nicht_meldung'      => (bool) $em['nicht_meldung'],
			'mannschaft_id'      => $em['mannschaft_id'] !== null ? (int) $em['mannschaft_id'] : null,
			'mannschaft_nummer'  => $mannschaft !== null ? (int) $mannschaft['nummer'] : null,
			'startgeld'          => (float) $em['startgeld'],
			'konflikt'           => (bool) $em['konflikt'],
			'konflikt_text'      => (string) $em['konflikt_text'],
			'verarbeitungsstatus' => (string) $em['verarbeitungsstatus'],
			'verarbeitungsgrund' => (string) $em['verarbeitungsgrund'],
			'abgemeldet_am'      => $em['abgemeldet_am'] !== null ? Clock::format_local($em['abgemeldet_am']) : null,
			'abmeldegrund'       => (string) $em['abmeldegrund'],
			'startgeld_berechnen' => (bool) $em['startgeld_berechnen'],
			'nachgemeldet'       => (bool) $em['nachgemeldet'],
			'hinweise'           => $hinweise,
			'sortierung'         => $d !== null ? ($rw->disziplin($d->id) ? $this->disziplin_sort($d->kennzahl) : 0) : 0,
		];
	}

	/**
	 * @param array<string, mixed> $ma
	 * @return array<string, mixed>
	 */
	private function mannschaft_ansicht(array $ma): array {
		$rw = $this->engine->regelwerk();
		$d = $rw->disziplin((int) $ma['disziplin_id']);
		$pool = $ma['klasse_id'] !== null ? $rw->klasse((int) $ma['klasse_id']) : null;
		$mitglieder = [];
		foreach ($this->einzel->where(['mannschaft_id' => (int) $ma['id']], 'id ASC') as $em) {
			$mitglieder[] = $this->einzel_ansicht($em);
		}
		return [
			'id'               => (int) $ma['id'],
			'disziplin_id'     => (int) $ma['disziplin_id'],
			'kennzahl'         => $d?->kennzahl ?? '',
			'disziplin'        => $d?->bezeichnung ?? '',
			'typ'              => $d?->typ ?? 'normal',
			'nummer'           => (int) $ma['nummer'],
			'klasse'           => $pool?->bezeichnung ?? '',
			'groesse'          => $d?->mannschaft_groesse ?? 3,
			'mitglieder'       => $mitglieder,
			'vollstaendig'     => $d !== null && count($mitglieder) === $d->mannschaft_groesse && !$ma['unvollstaendig'],
			'unvollstaendig_grund' => $ma['unvollstaendig'] ? 'Mitglied nicht startberechtigt oder abgemeldet' : (count($mitglieder) < ($d?->mannschaft_groesse ?? 3) ? 'zu wenige Mitglieder' : ''),
			'startgeld'        => (float) $ma['startgeld'],
		];
	}

	private function disziplin_sort(string $kennzahl): int {
		$teile = preg_split('/[.\s]+/', $kennzahl) ?: [];
		$n = 0;
		foreach (array_slice($teile, 0, 2) as $i => $t) {
			$n += (int) $t * ($i === 0 ? 1000 : 1);
		}
		return $n;
	}

	// ----- Hilfen ---------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $s
	 */
	private function dto(array $s, ?int $para_klasse_id = null): Schuetze {
		return new Schuetze((string) $s['geburtsdatum'], (string) $s['geschlecht'], $this->hoeher->by_schuetze((int) $s['id'], $this->sportjahr_id()), $para_klasse_id);
	}

	/**
	 * @param array<string, mixed>      $em
	 * @param array<string, mixed>|null $s
	 */
	private function bewertung_aus_zeile(array $em, ?array $s = null): Bewertung {
		$s ??= $this->schuetzen->find((int) $em['schuetze_id']);
		$d = $this->engine->regelwerk()->disziplin((int) $em['disziplin_id']);
		if ($s === null || $d === null) {
			throw new \RuntimeException('Meldung unvollständig.');
		}
		return $this->engine->bewerte($d, $this->dto($s, $em['para_klasse_id'] !== null ? (int) $em['para_klasse_id'] : null));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function eigener_schuetze(int $id): array {
		return (new SchuetzeService($this->verein, $this->sportjahr_id()))->eigener($id);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function eigene_einzelmeldung(int $id): array {
		$em = $this->einzel->find($id);
		if ($em === null || (int) $em['verein_id'] !== $this->verein_id() || (int) $em['sportjahr_id'] !== $this->sportjahr_id()) {
			throw new \RuntimeException('Meldung nicht gefunden.');
		}
		return $em;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function eigene_mannschaft(int $id): array {
		$ma = $this->mannschaften->find($id);
		if ($ma === null || (int) $ma['verein_id'] !== $this->verein_id() || (int) $ma['sportjahr_id'] !== $this->sportjahr_id()) {
			throw new \RuntimeException('Mannschaft nicht gefunden.');
		}
		return $ma;
	}
}
