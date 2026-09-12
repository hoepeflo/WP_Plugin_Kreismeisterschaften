<?php
/**
 * Buchhaltungsbelege (Konzept 11.3): keine Rechnung, sondern die Abrechnungsgrundlage je
 * Verein. Positionen nach Disziplin und Startklasse (Anzahl × Betrag), danach die
 * Mannschaften (auch mit 0,00 €), am Ende die Gesamtsumme. Nicht startberechtigte
 * Meldungen fehlen; abgemeldete stehen mit Vermerk und ihrem Betrag (ggf. 0,00 €).
 *
 * Jeder erzeugte Beleg wird mit den Positionen als Snapshot in kmm_beleg gespeichert und
 * kann später unverändert erneut als PDF ausgegeben werden. Nur Administratoren.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Domain\Verarbeitungsstatus;
use KSV\KMM\Http\View;
use KSV\KMM\Infrastructure\Repository\BelegRepository;
use KSV\KMM\Infrastructure\Repository\MeldungRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;
use KSV\KMM\Support\Clock;

final class BelegService {

	private BelegRepository $belege;

	public function __construct() {
		$this->belege = new BelegRepository();
	}

	/**
	 * Positionen eines Vereins aus dem aktuellen Stand der Meldung.
	 *
	 * @param array<string, mixed> $verein
	 * @return array<string, mixed>
	 */
	public function positionen(int $sportjahr_id, array $verein): array {
		$verein['sportjahr_id'] = $sportjahr_id;
		$z = (new MeldungService($verein, $sportjahr_id))->zusammenfassung();
		$gruppen = [];
		$ungeprueft = 0;
		$nicht_startberechtigt = 0;
		$konflikte = 0;
		$abgemeldet = 0;
		foreach ($z['einzelmeldungen'] as $e) {
			if ($e['typ'] === 'mixteam') {
				continue; // nur über das Mannschaftsstartgeld
			}
			if ($e['verarbeitungsstatus'] === Verarbeitungsstatus::NICHT_STARTBERECHTIGT) {
				$nicht_startberechtigt++;
				continue;
			}
			if (!$e['startrecht'] || $e['konflikt']) {
				$konflikte++;
				continue;
			}
			if ($e['verarbeitungsstatus'] === Verarbeitungsstatus::UNGEPRUEFT) {
				$ungeprueft++;
			}
			$abg = $e['abgemeldet_am'] !== null;
			$betrag = $abg && !$e['startgeld_berechnen'] ? 0.0 : (float) $e['startgeld'];
			if ($abg) {
				$abgemeldet++;
			}
			$key = implode('|', [$e['kennzahl'], $e['startklasse'], number_format($betrag, 2, '.', ''), $abg ? 'abg' : '']);
			$gruppen[ $key ] ??= ['sortierung' => (int) $e['sortierung'], 'kennzahl' => (string) $e['kennzahl'], 'disziplin' => (string) $e['disziplin'], 'startklasse' => (string) $e['startklasse'], 'kennzahl_voll' => (string) $e['kennzahl_voll'], 'abgemeldet' => $abg, 'anzahl' => 0, 'betrag' => $betrag, 'summe' => 0.0, 'namen' => []];
			$gruppen[ $key ]['anzahl']++;
			$gruppen[ $key ]['summe'] = round($gruppen[ $key ]['summe'] + $betrag, 2);
			$gruppen[ $key ]['namen'][] = $e['nachname'] . ', ' . $e['vorname'];
		}
		usort($gruppen, static fn(array $a, array $b): int => [$a['sortierung'], $a['kennzahl_voll'], $a['abgemeldet'] ? 1 : 0, -$a['betrag']] <=> [$b['sortierung'], $b['kennzahl_voll'], $b['abgemeldet'] ? 1 : 0, -$b['betrag']]);
		$einzel = array_values(array_map(static function (array $g): array {
			unset($g['sortierung']);
			return $g;
		}, $gruppen));

		$teams = [];
		foreach ($z['mannschaften'] as $m) {
			$key = $m['kennzahl'] . '|' . number_format((float) $m['startgeld'], 2, '.', '');
			$teams[ $key ] ??= ['kennzahl' => (string) $m['kennzahl'], 'disziplin' => (string) $m['disziplin'], 'anzahl' => 0, 'betrag' => (float) $m['startgeld'], 'summe' => 0.0, 'unvollstaendig' => 0];
			$teams[ $key ]['anzahl']++;
			$teams[ $key ]['summe'] = round($teams[ $key ]['summe'] + (float) $m['startgeld'], 2);
			if (!$m['vollstaendig']) {
				$teams[ $key ]['unvollstaendig']++;
			}
		}
		$mannschaften = array_values($teams);
		$summe_einzel = round(array_sum(array_column($einzel, 'summe')), 2);
		$summe_mannschaften = round(array_sum(array_column($mannschaften, 'summe')), 2);
		return [
			'verein'             => $z['verein'],
			'sportjahr'          => $z['sportjahr'],
			'meldung_status'     => (string) $z['status'],
			'ansprechpartner'    => $z['ansprechpartner'],
			'einzel'             => $einzel,
			'mannschaften'       => $mannschaften,
			'anzahl_einzel'      => array_sum(array_column($einzel, 'anzahl')),
			'anzahl_mannschaften' => array_sum(array_column($mannschaften, 'anzahl')),
			'summe_einzel'       => $summe_einzel,
			'summe_mannschaften' => $summe_mannschaften,
			'summe'              => round($summe_einzel + $summe_mannschaften, 2),
			'ungeprueft'         => $ungeprueft,
			'nicht_startberechtigt' => $nicht_startberechtigt,
			'konflikte'          => $konflikte,
			'abgemeldet'         => $abgemeldet,
		];
	}

	/**
	 * Beleg für einen Verein erzeugen und speichern.
	 *
	 * @return array{beleg: array<string, mixed>, pdf: string, positionen: array<string, mixed>}
	 */
	public function erzeugen(int $sportjahr_id, int $verein_id): array {
		$sportjahr = (new SportjahrRepository())->find($sportjahr_id);
		$verein = (new VereinRepository())->find($verein_id);
		if ($sportjahr === null || $verein === null) {
			throw new \RuntimeException('Verein oder Sportjahr nicht gefunden.');
		}
		if ($sportjahr['abgeschlossen_am'] !== null) {
			throw new \RuntimeException('Das Sportjahr ist abgeschlossen; Belege können nur noch heruntergeladen werden.');
		}
		$p = $this->positionen($sportjahr_id, $verein);
		if ($p['einzel'] === [] && $p['mannschaften'] === []) {
			throw new \RuntimeException(sprintf('%s hat keine abrechenbaren Meldungen.', (string) $verein['name']));
		}
		$nummer = $this->belege->count(['sportjahr_id' => $sportjahr_id, 'verein_id' => $verein_id]) + 1;
		$p['belegnummer'] = sprintf('%d-%s-%02d', (int) $sportjahr['jahr'], (string) $verein['vn_nummer'], $nummer);
		$p['erstellt_am'] = Clock::now_utc();
		$p['erstellt_von'] = get_current_user_id() > 0 ? wp_get_current_user()->display_name : '';
		$dateiname = sprintf('Beleg-%s.pdf', sanitize_file_name($p['belegnummer']));
		$id = $this->belege->insert([
			'sportjahr_id'       => $sportjahr_id,
			'verein_id'          => $verein_id,
			'summe'              => $p['summe'],
			'positionen'         => (string) wp_json_encode($p, JSON_UNESCAPED_UNICODE),
			'dateiname'          => $dateiname,
			'ungeprueft_hinweis' => $p['ungeprueft'] > 0,
			'erstellt_von'       => get_current_user_id() > 0 ? get_current_user_id() : null,
			'erstellt_am'        => $p['erstellt_am'],
		]);
		$beleg = (array) $this->belege->find($id);
		Protokoll::admin('beleg.erzeugen', sprintf('Beleg %s für %s: %s%s', $p['belegnummer'], (string) $verein['name'], number_format($p['summe'], 2, ',', '.') . ' €', $p['ungeprueft'] > 0 ? sprintf(' (%d ungeprüfte Meldungen)', $p['ungeprueft']) : ''), $sportjahr_id, $verein_id, 'beleg', $id, ['summe' => $p['summe'], 'ungeprueft' => $p['ungeprueft']]);
		return ['beleg' => $beleg, 'pdf' => Pdf::aus_html($this->html($p)), 'positionen' => $p];
	}

	/**
	 * Belege für alle Vereine mit abrechenbaren Meldungen erzeugen; ein gemeinsames PDF.
	 *
	 * @param bool $nur_eingereicht nur Vereine mit eingereichter/verarbeiteter Meldung
	 * @return array{pdf: string, anzahl: int, ungeprueft: int, dateiname: string, uebersprungen: string[]}
	 */
	public function alle(int $sportjahr_id, bool $nur_eingereicht = true): array {
		$sportjahr = (new SportjahrRepository())->find($sportjahr_id);
		if ($sportjahr === null) {
			throw new \RuntimeException('Sportjahr nicht gefunden.');
		}
		$meldungen = (new MeldungRepository())->by_sportjahr($sportjahr_id);
		$html = [];
		$ungeprueft = 0;
		$uebersprungen = [];
		foreach ((new VereinRepository())->all() as $verein) {
			$vid = (int) $verein['id'];
			$m = $meldungen[ $vid ] ?? null;
			if ($m === null || ($nur_eingereicht && (string) $m['status'] !== MeldungRepository::STATUS_EINGEREICHT)) {
				continue;
			}
			try {
				$r = $this->erzeugen($sportjahr_id, $vid);
			} catch (\RuntimeException $e) {
				$uebersprungen[] = $e->getMessage();
				continue;
			}
			$html[] = $this->html($r['positionen']);
			$ungeprueft += (int) $r['positionen']['ungeprueft'];
		}
		if ($html === []) {
			throw new \RuntimeException('Kein Verein mit abrechenbaren Meldungen.');
		}
		return [
			'pdf'           => Pdf::aus_html(implode('<pagebreak />', $html)),
			'anzahl'        => count($html),
			'ungeprueft'    => $ungeprueft,
			'dateiname'     => sprintf('Belege-KM-%d.pdf', (int) $sportjahr['jahr']),
			'uebersprungen' => $uebersprungen,
		];
	}

	/**
	 * Gespeicherten Beleg (Snapshot) erneut als PDF ausgeben.
	 *
	 * @param array<string, mixed> $beleg
	 */
	public function pdf(array $beleg): string {
		$p = json_decode((string) $beleg['positionen'], true);
		if (!is_array($p)) {
			throw new \RuntimeException('Beleg ohne gespeicherte Positionen.');
		}
		return Pdf::aus_html($this->html($p));
	}

	/**
	 * @param array<string, mixed> $p
	 */
	public function html(array $p): string {
		return View::capture('pdf/beleg', ['p' => $p]);
	}
}
