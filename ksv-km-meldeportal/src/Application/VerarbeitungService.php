<?php
/**
 * Verarbeitungsstatus nach Meldeschluss (Konzept 12.1): einzeln oder gesammelt setzen,
 * Folgen für Mannschaften und Buchungen, Änderungserfassung für die Sammelmail.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Auth\Rechte;
use KSV\KMM\Domain\AenderungTyp;
use KSV\KMM\Domain\Verarbeitungsstatus;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\BuchungRepository;
use KSV\KMM\Infrastructure\Repository\EinzelmeldungRepository;
use KSV\KMM\Infrastructure\Repository\MannschaftRepository;
use KSV\KMM\Infrastructure\Repository\MeldungRepository;
use KSV\KMM\Infrastructure\Repository\SchuetzeRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;
use KSV\KMM\Support\Clock;

final class VerarbeitungService {

	private EinzelmeldungRepository $einzel;

	public function __construct(private readonly int $sportjahr_id) {
		$this->einzel = new EinzelmeldungRepository();
	}

	/**
	 * Gefilterte Einzelmeldungen (kombinierbar), nur im Zuständigkeitsbereich des Benutzers.
	 *
	 * @param array{verein_id?: int, disziplin_id?: int, gruppe?: string, status?: string} $filter
	 * @return list<array<string, mixed>> Zeilen mit Zusatzfeldern (verein, schuetze, disziplin, klassen)
	 */
	public function liste(array $filter = []): array {
		$rw = RegelwerkLader::laden($this->sportjahr_id);
		$vereine = [];
		foreach ((new VereinRepository())->all() as $v) {
			$vereine[ (int) $v['id'] ] = $v;
		}
		$schuetzen = [];
		foreach ((new SchuetzeRepository())->where([]) as $s) {
			$schuetzen[ (int) $s['id'] ] = $s;
		}
		$meldungen = (new MeldungRepository())->by_sportjahr($this->sportjahr_id);
		$mannschaften = [];
		foreach ((new MannschaftRepository())->where(['sportjahr_id' => $this->sportjahr_id]) as $m) {
			$mannschaften[ (int) $m['id'] ] = $m;
		}
		$out = [];
		foreach ($this->einzel->by_sportjahr($this->sportjahr_id) as $em) {
			$d = $rw->disziplin((int) $em['disziplin_id']);
			if ($d === null || !Rechte::zustaendig($d)) {
				continue;
			}
			if (isset($filter['verein_id']) && $filter['verein_id'] > 0 && (int) $em['verein_id'] !== $filter['verein_id']) {
				continue;
			}
			if (isset($filter['disziplin_id']) && $filter['disziplin_id'] > 0 && $d->id !== $filter['disziplin_id']) {
				continue;
			}
			if (isset($filter['gruppe']) && $filter['gruppe'] !== '' && $d->gruppe !== $filter['gruppe']) {
				continue;
			}
			if (isset($filter['status']) && $filter['status'] !== '' && (string) $em['verarbeitungsstatus'] !== $filter['status']) {
				continue;
			}
			$m = $meldungen[ (int) $em['verein_id'] ] ?? null;
			if (($filter['nur_eingereicht'] ?? true) && ($m === null || $m['status'] !== MeldungRepository::STATUS_EINGEREICHT)) {
				continue;
			}
			$s = $em['schuetze_id'] !== null ? ($schuetzen[ (int) $em['schuetze_id'] ] ?? null) : null;
			$em['verein'] = $vereine[ (int) $em['verein_id'] ] ?? null;
			$em['schuetze'] = $s;
			$em['disziplin'] = $d;
			$em['klasse'] = $em['klasse_id'] !== null ? $rw->klasse((int) $em['klasse_id']) : null;
			$em['startklasse'] = $em['startklasse_id'] !== null ? $rw->klasse((int) $em['startklasse_id']) : ($em['mannschaft_klasse_id'] !== null ? $rw->klasse((int) $em['mannschaft_klasse_id']) : null);
			$em['mannschaft'] = $em['mannschaft_id'] !== null ? ($mannschaften[ (int) $em['mannschaft_id'] ] ?? null) : null;
			$out[] = $em;
		}
		usort($out, static fn(array $a, array $b): int => [ExportService::sortkey($a['disziplin']->kennzahl), (string) ($a['verein']['name'] ?? ''), (string) ($a['schuetze']['nachname'] ?? ''), (string) ($a['schuetze']['vorname'] ?? '')] <=> [ExportService::sortkey($b['disziplin']->kennzahl), (string) ($b['verein']['name'] ?? ''), (string) ($b['schuetze']['nachname'] ?? ''), (string) ($b['schuetze']['vorname'] ?? '')]);
		return $out;
	}

	/**
	 * Status einer Einzelmeldung setzen.
	 *
	 * @throws \RuntimeException bei fehlendem Recht oder unbekannter Meldung
	 */
	public function setzen(int $einzelmeldung_id, string $status, string $grund = ''): void {
		if (!Verarbeitungsstatus::is_valid($status)) {
			throw new \RuntimeException('Ungültiger Status.');
		}
		if (!Rechte::hat_recht(Rechte::RECHT_STATUS)) {
			throw new \RuntimeException('Kein Recht, den Verarbeitungsstatus zu setzen.');
		}
		$em = $this->einzel->find($einzelmeldung_id);
		if ($em === null || (int) $em['sportjahr_id'] !== $this->sportjahr_id) {
			throw new \RuntimeException('Meldung nicht gefunden.');
		}
		$rw = RegelwerkLader::laden($this->sportjahr_id);
		$d = $rw->disziplin((int) $em['disziplin_id']);
		if ($d === null || !Rechte::zustaendig($d)) {
			throw new \RuntimeException('Keine Zuständigkeit für diese Disziplin.');
		}
		$grund = mb_substr(trim($grund), 0, 255);
		if ($status === Verarbeitungsstatus::NICHT_STARTBERECHTIGT && $grund === '') {
			throw new \RuntimeException('Bitte einen Grund angeben.');
		}
		$alt = (string) $em['verarbeitungsstatus'];
		if ($alt === $status && (string) $em['verarbeitungsgrund'] === $grund) {
			return;
		}
		$this->einzel->update($einzelmeldung_id, [
			'verarbeitungsstatus' => $status,
			'verarbeitungsgrund'  => $status === Verarbeitungsstatus::UNGEPRUEFT ? '' : $grund,
			'verarbeitet_am'      => $status === Verarbeitungsstatus::UNGEPRUEFT ? null : Clock::now_utc(),
		]);
		$this->folgen($em, $status);

		$s = $em['schuetze_id'] !== null ? (new SchuetzeRepository())->find((int) $em['schuetze_id']) : null;
		$name = $s !== null ? $s['nachname'] . ', ' . $s['vorname'] : 'Meldung #' . $einzelmeldung_id;
		$text = sprintf('%s in %s: %s%s', $name, $d->kennzahl, Verarbeitungsstatus::label($status), $grund !== '' ? ' (' . $grund . ')' : '');
		Protokoll::admin('verarbeitung.status', $text, $this->sportjahr_id, (int) $em['verein_id'], 'einzelmeldung', $einzelmeldung_id, ['von' => $alt, 'nach' => $status, 'grund' => $grund]);
		if ($status !== Verarbeitungsstatus::UNGEPRUEFT && $alt !== $status) {
			Aenderungen::erfassen($this->sportjahr_id, (int) $em['verein_id'], $einzelmeldung_id, AenderungTyp::STATUS, $text, ['status' => $status, 'grund' => $grund]);
		}
	}

	/**
	 * Folgen einer Statusänderung: nicht startberechtigt → Mannschaft als unvollständig
	 * markieren, Startplatz freigeben. Zurück auf startberechtigt → Mannschaft neu prüfen.
	 *
	 * @param array<string, mixed> $em
	 */
	private function folgen(array $em, string $status): void {
		$mannschaften = new MannschaftRepository();
		if ($status === Verarbeitungsstatus::NICHT_STARTBERECHTIGT) {
			if ($em['mannschaft_id'] !== null) {
				$mannschaften->update((int) $em['mannschaft_id'], ['unvollstaendig' => true]);
			}
			$buchungen = new BuchungRepository();
			$b = $buchungen->by_einzelmeldung((int) $em['id']);
			if ($b !== null) {
				$buchungen->delete((int) $b['id']);
				Protokoll::system('startplan.platz_frei', sprintf('Startplatz nach Status „nicht startberechtigt" freigegeben (Meldung #%d)', (int) $em['id']), $this->sportjahr_id, (int) $em['verein_id'], 'buchung', (int) $b['id']);
			}
			return;
		}
		if ($em['mannschaft_id'] !== null) {
			$this->mannschaft_pruefen((int) $em['mannschaft_id']);
		}
	}

	/** Setzt „unvollständig" einer Mannschaft anhand der startberechtigten Mitglieder. */
	public function mannschaft_pruefen(int $mannschaft_id): void {
		$mannschaften = new MannschaftRepository();
		$m = $mannschaften->find($mannschaft_id);
		if ($m === null) {
			return;
		}
		$rw = RegelwerkLader::laden($this->sportjahr_id);
		$d = $rw->disziplin((int) $m['disziplin_id']);
		$n = 0;
		foreach ($this->einzel->where(['mannschaft_id' => $mannschaft_id]) as $mitglied) {
			if ($mitglied['startrecht'] && !$mitglied['konflikt'] && $mitglied['verarbeitungsstatus'] !== Verarbeitungsstatus::NICHT_STARTBERECHTIGT && $mitglied['abgemeldet_am'] === null) {
				$n++;
			}
		}
		$mannschaften->update($mannschaft_id, ['unvollstaendig' => $d === null || $n !== $d->mannschaft_groesse]);
	}

	/**
	 * Sammelaktion: alle ungeprüften Meldungen der gefilterten Menge als verarbeitet
	 * markieren. Bereits als nicht startberechtigt markierte bleiben unberührt.
	 *
	 * @param array{verein_id?: int, disziplin_id?: int, gruppe?: string} $filter
	 * @return int Anzahl geänderter Meldungen
	 */
	public function sammel_verarbeitet(array $filter): int {
		if (!Rechte::hat_recht(Rechte::RECHT_STATUS)) {
			throw new \RuntimeException('Kein Recht, den Verarbeitungsstatus zu setzen.');
		}
		$filter['status'] = Verarbeitungsstatus::UNGEPRUEFT;
		$n = 0;
		$vereine = [];
		foreach ($this->liste($filter) as $em) {
			$this->einzel->update((int) $em['id'], ['verarbeitungsstatus' => Verarbeitungsstatus::VERARBEITET, 'verarbeitungsgrund' => '', 'verarbeitet_am' => Clock::now_utc()]);
			$vereine[ (int) $em['verein_id'] ][] = $em;
			$n++;
		}
		foreach ($vereine as $vid => $liste) {
			$text = sprintf('%d Meldung(en) als verarbeitet markiert', count($liste));
			Aenderungen::erfassen($this->sportjahr_id, $vid, null, AenderungTyp::STATUS, $text, ['einzelmeldungen' => array_map(static fn(array $e): int => (int) $e['id'], $liste)]);
		}
		Protokoll::admin('verarbeitung.sammel', sprintf('Sammelaktion: %d Meldungen als verarbeitet markiert', $n), $this->sportjahr_id, null, '', null, $filter);
		return $n;
	}

	/**
	 * Abgeleiteter Vereinsstatus: „verarbeitet", wenn eingereicht und keine Einzelmeldung
	 * mehr ungeprüft ist.
	 *
	 * @param list<array<string, mixed>> $einzelmeldungen
	 */
	public static function vereinsstatus(string $meldung_status, array $einzelmeldungen): string {
		if ($meldung_status !== MeldungRepository::STATUS_EINGEREICHT || $einzelmeldungen === []) {
			return $meldung_status;
		}
		foreach ($einzelmeldungen as $em) {
			if (($em['verarbeitungsstatus'] ?? Verarbeitungsstatus::UNGEPRUEFT) === Verarbeitungsstatus::UNGEPRUEFT && !$em['konflikt']) {
				return $meldung_status;
			}
		}
		return 'verarbeitet';
	}
}
