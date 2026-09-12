<?php
/**
 * Schützenliste eines Vereins: anlegen, bearbeiten, löschen, Höhermeldungen.
 * Jede Änderung wird serverseitig auf den Verein der Sitzung geprüft.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Domain\Engine\Schuetze;
use KSV\KMM\Domain\Geschlecht;
use KSV\KMM\Domain\HoehermeldungBereich;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\EinzelmeldungRepository;
use KSV\KMM\Infrastructure\Repository\HoehermeldungRepository;
use KSV\KMM\Infrastructure\Repository\SchuetzeRepository;

final class SchuetzeService {

	private SchuetzeRepository $schuetzen;
	private HoehermeldungRepository $hoeher;

	/**
	 * @param array<string, mixed> $verein Verein der Sitzung (mit sportjahr_id)
	 */
	public function __construct(private readonly array $verein, private readonly int $sportjahr_id) {
		$this->schuetzen = new SchuetzeRepository();
		$this->hoeher = new HoehermeldungRepository();
	}

	/**
	 * Liste mit Höhermeldungen, Angebot der Höhermeldung und Warnungen.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function liste(): array {
		$engine = RegelwerkLader::engine($this->sportjahr_id);
		$alle_hoeher = $this->hoeher->by_sportjahr($this->sportjahr_id);
		$gemeldet = [];
		foreach ((new EinzelmeldungRepository())->where(['verein_id' => (int) $this->verein['id'], 'sportjahr_id' => $this->sportjahr_id]) as $em) {
			if ($em['schuetze_id'] !== null) {
				$gemeldet[ (int) $em['schuetze_id'] ] = ($gemeldet[ (int) $em['schuetze_id'] ] ?? 0) + 1;
			}
		}
		$out = [];
		foreach ($this->schuetzen->by_verein((int) $this->verein['id']) as $s) {
			$id = (int) $s['id'];
			$hm = $alle_hoeher[ $id ] ?? [];
			$dto = new Schuetze((string) $s['geburtsdatum'], (string) $s['geschlecht'], $hm);
			$angebot = [];
			foreach (HoehermeldungBereich::ALLE as $bereich) {
				$a = $engine->hoehermeldung_angebot($bereich, $dto);
				if ($a !== []) {
					$angebot[ $bereich ] = $a;
				}
			}
			$out[] = [
				'id'                    => $id,
				'nachname'              => (string) $s['nachname'],
				'vorname'               => (string) $s['vorname'],
				'geburtsdatum'          => (string) $s['geburtsdatum'],
				'geschlecht'            => (string) $s['geschlecht'],
				'mitgliedsnummer'       => (string) $s['mitgliedsnummer'],
				'alter'                 => $engine->alter($dto),
				'hoehermeldungen'       => $hm,
				'hoehermeldung_angebot' => $angebot,
				'warnungen'             => $this->warnungen($s),
				'meldungen'             => $gemeldet[ $id ] ?? 0,
			];
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $s
	 * @return string[]
	 */
	public function warnungen(array $s): array {
		$w = [];
		$mn = (string) $s['mitgliedsnummer'];
		if ($mn !== '' && !str_starts_with($mn, (string) $this->verein['vn_nummer'])) {
			$w[] = sprintf('Mitgliedsnummer beginnt nicht mit der VN-Nummer %s (Zweitverein?).', (string) $this->verein['vn_nummer']);
		}
		return $w;
	}

	/**
	 * @param array<string, mixed> $daten nachname, vorname, geburtsdatum (Y-m-d), geschlecht, mitgliedsnummer, hoehermeldungen[bereich => stufe]
	 * @return array{id: int, warnungen: string[], konflikte: int}
	 * @throws \InvalidArgumentException
	 */
	public function speichern(int $id, array $daten): array {
		$nachname = trim((string) ($daten['nachname'] ?? ''));
		$vorname = trim((string) ($daten['vorname'] ?? ''));
		$geburtsdatum = trim((string) ($daten['geburtsdatum'] ?? ''));
		$geschlecht = (string) ($daten['geschlecht'] ?? '');
		$mitgliedsnummer = preg_replace('/\s+/', '', (string) ($daten['mitgliedsnummer'] ?? '')) ?? '';
		if ($nachname === '' || $vorname === '') {
			throw new \InvalidArgumentException('Name und Vorname sind Pflichtfelder.');
		}
		$geb = \DateTimeImmutable::createFromFormat('!Y-m-d', $geburtsdatum);
		if ($geb === false || $geb->format('Y-m-d') !== $geburtsdatum || (int) $geb->format('Y') < 1900 || $geb > new \DateTimeImmutable('now')) {
			throw new \InvalidArgumentException('Bitte ein gültiges Geburtsdatum angeben.');
		}
		if (!in_array($geschlecht, [Geschlecht::M, Geschlecht::W], true)) {
			throw new \InvalidArgumentException('Bitte das Geschlecht wählen (m/w).');
		}
		if (!preg_match('/^\d{9}$/', $mitgliedsnummer)) {
			throw new \InvalidArgumentException('Die Mitgliedsnummer muss aus genau 9 Ziffern bestehen.');
		}
		$satz = [
			'nachname'        => mb_substr($nachname, 0, 100),
			'vorname'         => mb_substr($vorname, 0, 100),
			'geburtsdatum'    => $geburtsdatum,
			'geschlecht'      => $geschlecht,
			'mitgliedsnummer' => $mitgliedsnummer,
		];
		if ($id > 0) {
			$alt = $this->eigener($id);
			$this->schuetzen->update($id, $satz);
			$aktion = 'schuetze.bearbeiten';
		} else {
			$satz['verein_id'] = (int) $this->verein['id'];
			$id = $this->schuetzen->insert($satz);
			if ($id <= 0) {
				throw new \RuntimeException('Schütze konnte nicht gespeichert werden: ' . $this->schuetzen->last_error());
			}
			$alt = null;
			$aktion = 'schuetze.anlegen';
		}

		// Höhermeldungen: nur angebotene Stufen, sonst entfernen.
		$engine = RegelwerkLader::engine($this->sportjahr_id);
		$dto = new Schuetze($geburtsdatum, $geschlecht);
		$hoeher_neu = is_array($daten['hoehermeldungen'] ?? null) ? $daten['hoehermeldungen'] : [];
		foreach (HoehermeldungBereich::ALLE as $bereich) {
			$stufe = isset($hoeher_neu[ $bereich ]) ? (string) $hoeher_neu[ $bereich ] : '';
			$angebot = $engine->hoehermeldung_angebot($bereich, $dto);
			$this->hoeher->set($id, $this->sportjahr_id, $bereich, $stufe !== '' && isset($angebot[ $stufe ]) ? $stufe : null);
		}

		Protokoll::verein((int) $this->verein['id'], (string) $this->verein['name'], $aktion, sprintf('%s, %s', $satz['nachname'], $satz['vorname']), $this->sportjahr_id, 'schuetze', $id, $alt !== null ? ['vorher' => array_intersect_key($alt, $satz)] : []);

		$konflikte = $this->meldungen_neu_bewerten($id);
		return ['id' => $id, 'warnungen' => $this->warnungen($satz), 'konflikte' => $konflikte];
	}

	/**
	 * Nach Änderung von Geburtsdatum, Geschlecht oder Höhermeldung: Einzelmeldungen des
	 * Sportjahres neu bewerten; Abweichungen werden übernommen und als Konflikt markiert.
	 */
	private function meldungen_neu_bewerten(int $schuetze_id): int {
		$engine = RegelwerkLader::engine($this->sportjahr_id);
		$einzel = new EinzelmeldungRepository();
		$s = $this->schuetzen->find($schuetze_id);
		if ($s === null) {
			return 0;
		}
		$hm = $this->hoeher->by_schuetze($schuetze_id, $this->sportjahr_id);
		$konflikte = 0;
		foreach ($einzel->where(['schuetze_id' => $schuetze_id, 'sportjahr_id' => $this->sportjahr_id]) as $em) {
			$b = Revalidierung::bewerte_einzelmeldung($engine, $em, $s, $hm);
			if ($b === null) {
				continue;
			}
			$felder = Revalidierung::felder($b);
			$felder['geschlecht'] = (string) $s['geschlecht'];
			$felder['geburtsjahr'] = (int) substr((string) $s['geburtsdatum'], 0, 4);
			$geaendert = false;
			foreach (['klasse_id', 'startklasse_id', 'mannschaft_klasse_id', 'startrecht'] as $f) {
				if ((int) ($em[ $f ] ?? 0) !== (int) ($felder[ $f ] ?? 0)) {
					$geaendert = true;
				}
			}
			if ($geaendert) {
				$felder['konflikt'] = true;
				$felder['konflikt_text'] = $b->startrecht ? 'Schützendaten geändert: Klasse oder Mannschaftspool haben sich geändert, bitte Meldung und Mannschaft prüfen.' : 'Schützendaten geändert: kein Startrecht mehr. ' . $b->grund;
				$konflikte++;
			}
			$einzel->update((int) $em['id'], $felder);
		}
		return $konflikte;
	}

	/**
	 * @throws \RuntimeException wenn der Schütze Meldungen hat.
	 */
	public function loeschen(int $id): void {
		$s = $this->eigener($id);
		$n = (new EinzelmeldungRepository())->count(['schuetze_id' => $id]);
		if ($n > 0) {
			throw new \RuntimeException('Der Schütze hat Meldungen (auch aus früheren Jahren) und kann nicht gelöscht werden. Bitte zuerst die Meldungen entfernen.');
		}
		$this->hoeher->delete_where(['schuetze_id' => $id]);
		$this->schuetzen->delete($id);
		Protokoll::verein((int) $this->verein['id'], (string) $this->verein['name'], 'schuetze.loeschen', sprintf('%s, %s', (string) $s['nachname'], (string) $s['vorname']), $this->sportjahr_id, 'schuetze', $id);
	}

	/**
	 * Schütze des eigenen Vereins oder Exception.
	 *
	 * @return array<string, mixed>
	 */
	public function eigener(int $id): array {
		$s = $this->schuetzen->find($id);
		if ($s === null || (int) $s['verein_id'] !== (int) $this->verein['id']) {
			throw new \RuntimeException('Schütze nicht gefunden.');
		}
		return $s;
	}
}
