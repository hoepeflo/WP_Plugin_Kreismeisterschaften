<?php
/**
 * Buchung von Startplätzen durch die Vereine (Konzept 12.4).
 *
 * Eindeutigkeit und gleichzeitige Zugriffe werden auf Datenbankebene gelöst:
 * UNIQUE(durchgang_id, einheit_id, position) lässt pro Platz nur eine Buchung zu,
 * UNIQUE(einzelmeldung_id) pro Meldung nur einen Platz. Jeder Klick ist ein einzelner
 * INSERT (Neubuchung) bzw. ein einzelnes UPDATE (Umbuchung) ohne Sperren und ohne
 * vorheriges Lesen; verliert ein Klick gegen einen gleichzeitigen, scheitert nur dieser
 * eine Klick mit dem Duplikatfehler der Datenbank, alle bestehenden Buchungen bleiben.
 * Zeitsperren gibt es nicht (Konzept: keine blockierten Plätze durch abgebrochene Sitzungen).
 *
 * Fachliche Prüfungen vor dem Schreiben: Tag freigegeben und Frist offen, Meldung gehört
 * dem Verein und passt zur Zulassung des Durchgangs, Einheit erlaubt die Disziplin,
 * Position innerhalb der Kapazität, Schütze steht in keinem zeitlich überschneidenden
 * Durchgang. Die Überschneidung wird nach dem Schreiben noch einmal geprüft; im
 * Konfliktfall wird die eigene, gerade angelegte Buchung wieder entfernt.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Domain\AenderungTyp;
use KSV\KMM\Domain\WettkampftagStatus;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\BuchungRepository;
use KSV\KMM\Infrastructure\Repository\DurchgangRepository;
use KSV\KMM\Infrastructure\Repository\DurchgangZulassungRepository;
use KSV\KMM\Infrastructure\Repository\EinheitRepository;
use KSV\KMM\Infrastructure\Repository\EinzelmeldungRepository;
use KSV\KMM\Infrastructure\Repository\SchuetzeRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;
use KSV\KMM\Infrastructure\Repository\WettkampftagRepository;
use KSV\KMM\Support\Clock;
use KSV\KMM\Support\Settings;

final class BuchungService {

	private WettkampftagRepository $tage;
	private EinheitRepository $einheiten;
	private DurchgangRepository $durchgaenge;
	private DurchgangZulassungRepository $zulassungen;
	private BuchungRepository $buchungen;
	private EinzelmeldungRepository $einzel;
	private StartplanService $startplan;

	/**
	 * @param array<string, mixed> $verein Verein (Sitzung oder Admin-Modus)
	 * @param bool                 $admin  Admin-Modus: Frist und Status werden nicht geprüft (Restverteilung, Verschieben)
	 */
	public function __construct(private readonly array $verein, private readonly int $sportjahr_id, private readonly bool $admin = false) {
		$this->tage = new WettkampftagRepository();
		$this->einheiten = new EinheitRepository();
		$this->durchgaenge = new DurchgangRepository();
		$this->zulassungen = new DurchgangZulassungRepository();
		$this->buchungen = new BuchungRepository();
		$this->einzel = new EinzelmeldungRepository();
		$this->startplan = new StartplanService($sportjahr_id);
	}

	private function verein_id(): int {
		return (int) $this->verein['id'];
	}

	// ----- Sichtbarkeit und Frist -----------------------------------------------------------------

	/**
	 * Ist der Tag für Vereine sichtbar (freigegeben oder veröffentlicht)?
	 *
	 * @param array<string, mixed> $tag
	 */
	public static function sichtbar(array $tag): bool {
		return in_array((string) $tag['status'], [WettkampftagStatus::FREIGEGEBEN, WettkampftagStatus::VEROEFFENTLICHT], true) && $tag['ausgeblendet_am'] === null;
	}

	/**
	 * Dürfen Vereine gerade buchen? Freigegeben (nicht veröffentlicht) und Frist nicht vorbei.
	 *
	 * @param array<string, mixed> $tag
	 * @return array{offen: bool, grund: string}
	 */
	public static function buchung_offen(array $tag, ?string $jetzt = null): array {
		$jetzt ??= Clock::now_utc();
		if ((string) $tag['status'] === WettkampftagStatus::ENTWURF) {
			return ['offen' => false, 'grund' => 'Der Startplan ist noch nicht freigegeben.'];
		}
		if ((string) $tag['status'] === WettkampftagStatus::VEROEFFENTLICHT) {
			return ['offen' => false, 'grund' => 'Der Startplan ist veröffentlicht. Änderungen nur noch über den KSV.'];
		}
		if ($tag['buchungsfrist'] !== null && (string) $tag['buchungsfrist'] <= $jetzt) {
			return ['offen' => false, 'grund' => sprintf('Die Buchungsfrist (%s Uhr) ist vorbei. Freie Plätze verteilt der KSV.', Clock::format_local((string) $tag['buchungsfrist']))];
		}
		return ['offen' => true, 'grund' => ''];
	}

	// ----- Meldungen des Vereins für einen Tag -----------------------------------------------------

	/**
	 * Buchbare Meldungen des Vereins, die zu mindestens einer Zulassung des Tages passen.
	 *
	 * @return list<array<string, mixed>> Einzelmeldung + passende Durchgang-IDs
	 */
	public function passende_meldungen(int $tag_id): array {
		$durchgaenge = $this->durchgaenge->by_wettkampftag($tag_id);
		$zul = [];
		foreach ($durchgaenge as $dg) {
			$zul[ (int) $dg['id'] ] = $this->zulassungen->by_durchgang((int) $dg['id']);
		}
		$out = [];
		foreach ($this->startplan->buchbare_meldungen() as $em) {
			if ((int) $em['verein_id'] !== $this->verein_id()) {
				continue;
			}
			$passt = [];
			foreach ($zul as $dg_id => $liste) {
				foreach ($liste as $z) {
					if (StartplanService::passt($em, $z)) {
						$passt[] = $dg_id;
						break;
					}
				}
			}
			if ($passt !== []) {
				$em['durchgang_ids'] = $passt;
				$out[] = $em;
			}
		}
		return $out;
	}

	/**
	 * Für Vereine sichtbare Wettkampftage mit Zahl der eigenen Meldungen und Plätze.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function tage(): array {
		$out = [];
		foreach ($this->tage->by_sportjahr($this->sportjahr_id) as $tag) {
			if (!self::sichtbar($tag) && !$this->admin) {
				continue;
			}
			$meldungen = $this->passende_meldungen((int) $tag['id']);
			if ($meldungen === [] && !$this->admin) {
				continue;
			}
			$mit_platz = 0;
			foreach ($meldungen as $em) {
				if ($this->buchungen->by_einzelmeldung((int) $em['id']) !== null) {
					$mit_platz++;
				}
			}
			$offen = self::buchung_offen($tag);
			$out[] = [
				'id'            => (int) $tag['id'],
				'datum'         => (string) $tag['datum'],
				'datum_lang'    => wp_date('D, d.m.Y', (int) strtotime((string) $tag['datum'] . ' 12:00:00')),
				'bezeichnung'   => (string) $tag['bezeichnung'],
				'ort'           => (string) $tag['ort'],
				'status'        => (string) $tag['status'],
				'buchungsfrist' => $tag['buchungsfrist'] !== null ? Clock::format_local((string) $tag['buchungsfrist']) : '',
				'buchbar'       => $offen['offen'],
				'grund'         => $offen['grund'],
				'meldungen'     => count($meldungen),
				'mit_platz'     => $mit_platz,
				'ohne_platz'    => count($meldungen) - $mit_platz,
			];
		}
		return $out;
	}

	// ----- Raster ------------------------------------------------------------------------------------

	/**
	 * Buchungsraster eines Tages aus Sicht des Vereins: Durchgänge × Einheiten × Positionen.
	 * Fremde Buchungen erscheinen nur als „belegt“ (keine Namen vor der Veröffentlichung).
	 *
	 * @return array<string, mixed>
	 */
	public function raster(int $tag_id): array {
		$tag = $this->startplan->tag($tag_id);
		if (!self::sichtbar($tag) && !$this->admin) {
			throw new \RuntimeException('Der Startplan ist noch nicht freigegeben.');
		}
		$rw = RegelwerkLader::laden($this->sportjahr_id);
		$schuetzen = new SchuetzeRepository();
		$meldungen = [];
		$em_index = [];
		foreach ($this->passende_meldungen($tag_id) as $em) {
			$s = $schuetzen->find((int) $em['schuetze_id']);
			$d = $rw->disziplin((int) $em['disziplin_id']);
			$sk = $em['startklasse_id'] !== null ? $rw->klasse((int) $em['startklasse_id']) : ($em['mannschaft_klasse_id'] !== null ? $rw->klasse((int) $em['mannschaft_klasse_id']) : null);
			$b = $this->buchungen->by_einzelmeldung((int) $em['id']);
			$zeile = [
				'id'            => (int) $em['id'],
				'schuetze_id'   => (int) $em['schuetze_id'],
				'name'          => $s !== null ? $s['nachname'] . ', ' . $s['vorname'] : '?',
				'kennzahl'      => $d?->kennzahl ?? '',
				'disziplin'     => $d?->bezeichnung ?? '',
				'startklasse'   => $sk?->bezeichnung ?? '',
				'durchgang_ids' => $em['durchgang_ids'],
				'buchung'       => $b !== null ? ['id' => (int) $b['id'], 'durchgang_id' => (int) $b['durchgang_id'], 'einheit_id' => (int) $b['einheit_id'], 'position' => (int) $b['position']] : null,
			];
			$meldungen[] = $zeile;
			$em_index[ (int) $em['id'] ] = $zeile;
		}
		// Namen fremder Starter erst mit der Veröffentlichung (Konzept 12.6); vorher nur „belegt“.
		$offen_sichtbar = (string) $tag['status'] === WettkampftagStatus::VEROEFFENTLICHT;
		$vereine = [];
		$belegung = [];
		foreach ($this->buchungen->by_wettkampftag($tag_id) as $b) {
			$eigen = (int) $b['verein_id'] === $this->verein_id();
			$zeige_name = $eigen || $offen_sichtbar || $this->admin;
			if (!$eigen && $zeige_name && !isset($vereine[ (int) $b['verein_id'] ])) {
				$v = (new VereinRepository())->find((int) $b['verein_id']);
				$vereine[ (int) $b['verein_id'] ] = $v !== null ? (string) $v['name'] : '';
			}
			$belegung[ (int) $b['durchgang_id'] ][ (int) $b['einheit_id'] ][ (int) $b['position'] ] = [
				'buchung_id'       => (int) $b['id'],
				'eigen'            => $eigen,
				'einzelmeldung_id' => $eigen ? (int) $b['einzelmeldung_id'] : null,
				'name'             => $zeige_name ? ($em_index[ (int) $b['einzelmeldung_id'] ]['name'] ?? $this->name_von((int) $b['einzelmeldung_id'])) : '',
				'verein'           => $eigen ? '' : ($vereine[ (int) $b['verein_id'] ] ?? ''),
				'kennzahl'         => $eigen ? ($em_index[ (int) $b['einzelmeldung_id'] ]['kennzahl'] ?? '') : '',
			];
		}
		$einheiten = [];
		foreach ($this->einheiten->by_wettkampftag($tag_id) as $e) {
			$einheiten[] = ['id' => (int) $e['id'], 'bezeichnung' => (string) $e['bezeichnung'], 'kapazitaet' => (int) $e['kapazitaet'], 'disziplin_ids' => EinheitRepository::disziplin_ids($e)];
		}
		$durchgaenge = [];
		foreach ($this->durchgaenge->by_wettkampftag($tag_id) as $dg) {
			$zul = [];
			$dis_ids = [];
			foreach ($this->zulassungen->by_durchgang((int) $dg['id']) as $z) {
				$d = $rw->disziplin((int) $z['disziplin_id']);
				$k = $z['startklasse_id'] !== null ? $rw->klasse((int) $z['startklasse_id']) : null;
				$zul[] = ($d?->kennzahl ?? '?') . ' ' . ($d?->bezeichnung ?? '') . ($k !== null ? ' (' . $k->bezeichnung . ')' : '');
				$dis_ids[ (int) $z['disziplin_id'] ] = true;
			}
			$spalten = [];
			foreach ($einheiten as $e) {
				if ($e['disziplin_ids'] === [] || array_intersect($e['disziplin_ids'], array_keys($dis_ids)) !== []) {
					$spalten[] = $e['id'];
				}
			}
			$durchgaenge[] = [
				'id'          => (int) $dg['id'],
				'nummer'      => (int) $dg['nummer'],
				'bezeichnung' => (string) $dg['bezeichnung'],
				'zeit'        => Clock::format_local((string) $dg['beginn'], 'H:i') . '–' . Clock::format_local((string) $dg['ende'], 'H:i'),
				'zulassungen' => $zul,
				'einheit_ids' => $spalten,
				'belegung'    => $belegung[ (int) $dg['id'] ] ?? [],
			];
		}
		$offen = self::buchung_offen($tag);
		return [
			'tag'           => ['id' => (int) $tag['id'], 'datum' => (string) $tag['datum'], 'bezeichnung' => (string) $tag['bezeichnung'], 'ort' => (string) $tag['ort'], 'hinweis' => (string) ($tag['hinweis'] ?? ''), 'status' => (string) $tag['status'], 'buchungsfrist' => $tag['buchungsfrist'] !== null ? Clock::format_local((string) $tag['buchungsfrist']) : ''],
			'buchbar'       => $offen['offen'] || $this->admin,
			'grund'         => $offen['grund'],
			'einheiten'     => $einheiten,
			'durchgaenge'   => $durchgaenge,
			'meldungen'     => $meldungen,
			'intervall'     => max(3, (int) Settings::get('buchung_raster_intervall')),
			'stand'         => Clock::now_utc(),
		];
	}

	private function name_von(int $einzelmeldung_id): string {
		$em = $this->einzel->find($einzelmeldung_id);
		$s = $em !== null && $em['schuetze_id'] !== null ? (new SchuetzeRepository())->find((int) $em['schuetze_id']) : null;
		return $s !== null ? $s['nachname'] . ', ' . $s['vorname'] : '';
	}

	// ----- Buchen -------------------------------------------------------------------------------------

	/**
	 * Platz buchen (oder umbuchen, wenn die Meldung schon einen Platz hat).
	 *
	 * @return array{buchung_id: int, umgebucht: bool}
	 */
	public function buchen(int $tag_id, int $durchgang_id, int $einheit_id, int $position, int $einzelmeldung_id): array {
		$tag = $this->startplan->tag($tag_id);
		if (!$this->admin) {
			$offen = self::buchung_offen($tag);
			if (!$offen['offen']) {
				throw new \RuntimeException($offen['grund']);
			}
		}
		$em = $this->einzel->find($einzelmeldung_id);
		if ($em === null || (int) $em['verein_id'] !== $this->verein_id() || (int) $em['sportjahr_id'] !== $this->sportjahr_id) {
			throw new \RuntimeException('Meldung nicht gefunden.');
		}
		$passend = null;
		foreach ($this->passende_meldungen($tag_id) as $p) {
			if ((int) $p['id'] === $einzelmeldung_id) {
				$passend = $p;
			}
		}
		if ($passend === null) {
			throw new \RuntimeException('Diese Meldung kann an diesem Wettkampftag nicht gebucht werden (kein Startrecht, abgemeldet oder keine passende Zulassung).');
		}
		$dg = $this->durchgaenge->find($durchgang_id);
		if ($dg === null || (int) $dg['wettkampftag_id'] !== $tag_id) {
			throw new \RuntimeException('Durchgang nicht gefunden.');
		}
		if (!in_array($durchgang_id, $passend['durchgang_ids'], true)) {
			throw new \RuntimeException('Die Meldung ist für diesen Durchgang nicht zugelassen.');
		}
		$e = $this->einheiten->find($einheit_id);
		if ($e === null || (int) $e['wettkampftag_id'] !== $tag_id) {
			throw new \RuntimeException('Einheit nicht gefunden.');
		}
		$erlaubt = EinheitRepository::disziplin_ids($e);
		if ($erlaubt !== [] && !in_array((int) $em['disziplin_id'], $erlaubt, true)) {
			throw new \RuntimeException(sprintf('%s ist für diese Disziplin nicht vorgesehen.', (string) $e['bezeichnung']));
		}
		if ($position < 1 || $position > (int) $e['kapazitaet']) {
			throw new \RuntimeException('Ungültige Position.');
		}
		$this->ueberschneidung_pruefen((int) $em['schuetze_id'], $dg, $einzelmeldung_id);

		$bestehend = $this->buchungen->by_einzelmeldung($einzelmeldung_id);
		$umgebucht = false;
		if ($bestehend !== null) {
			// Umbuchung: ein UPDATE; bei belegtem Zielplatz bleibt die alte Buchung bestehen.
			$ergebnis = $this->buchungen->umbuchen((int) $bestehend['id'], $durchgang_id, $einheit_id, $position);
			$buchung_id = (int) $bestehend['id'];
			$umgebucht = true;
		} else {
			$r = $this->buchungen->platz_buchen([
				'sportjahr_id'     => $this->sportjahr_id,
				'wettkampftag_id'  => $tag_id,
				'durchgang_id'     => $durchgang_id,
				'einheit_id'       => $einheit_id,
				'position'         => $position,
				'einzelmeldung_id' => $einzelmeldung_id,
				'verein_id'        => $this->verein_id(),
				'gebucht_von_typ'  => $this->admin ? 'admin' : 'verein',
				'gebucht_von_id'   => $this->admin && get_current_user_id() > 0 ? get_current_user_id() : null,
				'gebucht_am'       => Clock::now_utc(),
			]);
			$ergebnis = $r['ergebnis'];
			$buchung_id = $r['id'];
		}
		if ($ergebnis === BuchungRepository::ERGEBNIS_PLATZ_BELEGT) {
			throw new \RuntimeException('Der Platz wurde gerade von einem anderen Verein gebucht. Bitte einen anderen Platz wählen.');
		}
		if ($ergebnis === BuchungRepository::ERGEBNIS_SCHON_GEBUCHT) {
			throw new \RuntimeException('Diese Meldung hat inzwischen schon einen Platz. Bitte die Seite neu laden.');
		}
		if ($ergebnis !== BuchungRepository::ERGEBNIS_OK) {
			throw new \RuntimeException('Die Buchung konnte nicht gespeichert werden.');
		}
		// Nachprüfung der Überschneidung (zwei gleichzeitige eigene Klicks); im Konfliktfall zurück.
		try {
			$this->ueberschneidung_pruefen((int) $em['schuetze_id'], $dg, $einzelmeldung_id);
		} catch (\RuntimeException $ex) {
			if ($umgebucht && $bestehend !== null) {
				$this->buchungen->umbuchen($buchung_id, (int) $bestehend['durchgang_id'], (int) $bestehend['einheit_id'], (int) $bestehend['position']);
			} else {
				$this->buchungen->delete($buchung_id);
			}
			throw $ex;
		}
		$text = sprintf('%s: %s %s, Durchgang %d, %s Platz %d', (string) $tag['bezeichnung'], $passend['durchgang_ids'] !== [] ? '' : '', $this->name_von($einzelmeldung_id), (int) $dg['nummer'], (string) $e['bezeichnung'], $position);
		$text = preg_replace('/\s+/', ' ', $text) ?? $text;
		if ($this->admin) {
			Protokoll::admin($umgebucht ? 'buchung.umbuchen' : 'buchung.buchen', '[' . (string) $this->verein['name'] . '] ' . $text, $this->sportjahr_id, $this->verein_id(), 'buchung', $buchung_id);
			Aenderungen::erfassen($this->sportjahr_id, $this->verein_id(), $einzelmeldung_id, AenderungTyp::STARTPLAN, ($umgebucht ? 'Startplatz geändert: ' : 'Startplatz zugeteilt: ') . $text);
		} else {
			Protokoll::verein($this->verein_id(), (string) $this->verein['name'], $umgebucht ? 'buchung.umbuchen' : 'buchung.buchen', $text, $this->sportjahr_id, 'buchung', $buchung_id);
		}
		return ['buchung_id' => $buchung_id, 'umgebucht' => $umgebucht];
	}

	/** Eigenen Platz wieder freigeben (bis zur Frist; Admin jederzeit). */
	public function freigeben(int $buchung_id): void {
		$b = $this->buchungen->find($buchung_id);
		if ($b === null || (int) $b['verein_id'] !== $this->verein_id()) {
			throw new \RuntimeException('Buchung nicht gefunden.');
		}
		$tag = $this->startplan->tag((int) $b['wettkampftag_id']);
		if (!$this->admin) {
			$offen = self::buchung_offen($tag);
			if (!$offen['offen']) {
				throw new \RuntimeException($offen['grund']);
			}
		}
		$this->buchungen->delete($buchung_id);
		$text = sprintf('%s: %s, Platz freigegeben', (string) $tag['bezeichnung'], $this->name_von((int) $b['einzelmeldung_id']));
		if ($this->admin) {
			Protokoll::admin('buchung.freigeben', '[' . (string) $this->verein['name'] . '] ' . $text, $this->sportjahr_id, $this->verein_id(), 'buchung', $buchung_id);
			Aenderungen::erfassen($this->sportjahr_id, $this->verein_id(), (int) $b['einzelmeldung_id'], AenderungTyp::STARTPLAN, 'Startplatz entfernt: ' . $text);
		} else {
			Protokoll::verein($this->verein_id(), (string) $this->verein['name'], 'buchung.freigeben', $text, $this->sportjahr_id, 'buchung', $buchung_id);
		}
	}

	/**
	 * Ein Schütze darf nicht in zwei Durchgängen stehen, die sich zeitlich überschneiden –
	 * auch nicht über Disziplinen oder Wettkampftage hinweg.
	 *
	 * @param array<string, mixed> $dg Ziel-Durchgang
	 */
	private function ueberschneidung_pruefen(int $schuetze_id, array $dg, int $eigene_einzelmeldung_id): void {
		foreach ($this->buchungen->by_schuetze($this->sportjahr_id, $schuetze_id) as $b) {
			if ((int) $b['einzelmeldung_id'] === $eigene_einzelmeldung_id) {
				continue;
			}
			$anderer = $this->durchgaenge->find((int) $b['durchgang_id']);
			if ($anderer === null) {
				continue;
			}
			if ((string) $anderer['beginn'] < (string) $dg['ende'] && (string) $dg['beginn'] < (string) $anderer['ende']) {
				throw new \RuntimeException(sprintf('%s steht bereits im Durchgang %s (%s–%s Uhr), der sich mit diesem überschneidet.', $this->name_von((int) $b['einzelmeldung_id']), (string) $anderer['nummer'], Clock::format_local((string) $anderer['beginn'], 'H:i'), Clock::format_local((string) $anderer['ende'], 'H:i')));
			}
		}
	}

	/**
	 * Vereine mit passenden Meldungen zu einem Tag (für Freigabe- und Erinnerungsmail).
	 *
	 * @return list<array{verein: array<string, mixed>, meldungen: int, ohne_platz: int}>
	 */
	public static function betroffene_vereine(int $sportjahr_id, int $tag_id): array {
		$out = [];
		foreach ((new VereinRepository())->all(true) as $verein) {
			$verein['sportjahr_id'] = $sportjahr_id;
			$service = new self($verein, $sportjahr_id, true);
			$meldungen = $service->passende_meldungen($tag_id);
			if ($meldungen === []) {
				continue;
			}
			$ohne = 0;
			foreach ($meldungen as $em) {
				if ($service->buchungen->by_einzelmeldung((int) $em['id']) === null) {
					$ohne++;
				}
			}
			$out[] = ['verein' => $verein, 'meldungen' => count($meldungen), 'ohne_platz' => $ohne];
		}
		return $out;
	}
}
