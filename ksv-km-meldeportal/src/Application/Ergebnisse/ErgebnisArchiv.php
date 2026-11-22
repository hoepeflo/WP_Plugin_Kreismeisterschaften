<?php
/**
 * Übernahme der Altdaten aus dem bisherigen Ergebnis-Plugin – wiederholbar, mit Probelauf.
 *
 *   1. Katalog:        srd_kreis_v3 → kmm_ergebnis_katalog (Quelle wird nur gelesen)
 *   2. Ergebnisse:     jeder Ordner km_JJJJ wird gelesen und ins Verzeichnis eingetragen
 *   3. Lichtschießen:  je Jahr Link(s) auf die vorhandene(n) Gesamtdatei(en)
 *   4. Ausschreibungen: Option srd_km_documents → kmm_dokument
 *
 * Es wird keine Datei verschoben, umbenannt oder gelöscht. Ein zweiter Lauf ergänzt nur
 * Neues; von Hand gesetzte Status (ausgeblendet, Entwurf) und Uploads bleiben, wie sie sind.
 * Im Probelauf wird nichts geschrieben, nur gezählt.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application\Ergebnisse;

use KSV\KMM\Application\Protokoll;
use KSV\KMM\Domain\Ergebnis\ArchivAuswertung;
use KSV\KMM\Domain\Ergebnis\Kategorie;
use KSV\KMM\Domain\Ergebnis\Schluessel;
use KSV\KMM\Infrastructure\Ergebnisablage;
use KSV\KMM\Infrastructure\Repository\DokumentRepository;
use KSV\KMM\Infrastructure\Repository\ErgebnisKatalogRepository;
use KSV\KMM\Infrastructure\Repository\ErgebnisRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Support\Clock;

final class ErgebnisArchiv {

	/** Tabelle des bisherigen Plugins (ohne WordPress-Präfix). */
	public const SRD_TABELLE = 'srd_kreis_v3';

	/** Option des bisherigen Plugins mit den Ausschreibungen. */
	public const SRD_DOKUMENTE = 'srd_km_documents';

	/** Typen der Unterlagen: Schlüssel im alten Plugin => eigener Typ. */
	public const SRD_TYPEN = [
		'general'         => Dokumente::TYP_AUSSCHREIBUNG,
		'discipline_plan' => Dokumente::TYP_DISZIPLINENPLAN,
		'age_table'       => Dokumente::TYP_JAHRGANGSTABELLE,
		'schedule'        => Dokumente::TYP_TERMINPLAN,
	];

	private Ergebnisablage $ablage;

	private ErgebnisRepository $ergebnisse;

	private ErgebnisKatalogRepository $katalog;

	private DokumentRepository $dokumente;

	public function __construct(?Ergebnisablage $ablage = null) {
		$this->ablage = $ablage ?? new Ergebnisablage();
		$this->ergebnisse = new ErgebnisRepository();
		$this->katalog = new ErgebnisKatalogRepository();
		$this->dokumente = new DokumentRepository();
	}

	public static function srd_tabelle_vorhanden(): bool {
		global $wpdb;
		return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::SRD_TABELLE)) === self::SRD_TABELLE;
	}

	/**
	 * Alles in einem Lauf.
	 *
	 * @return array<string, mixed> Bericht
	 */
	public function einlesen(bool $probelauf): array {
		if (!$this->ablage->vorhanden()) {
			throw new \RuntimeException('Der Ergebnisordner wurde nicht gefunden: ' . $this->ablage->basis());
		}
		$bericht = [
			'probelauf' => $probelauf,
			'ordner'    => $this->ablage->basis(),
			'katalog'   => $this->katalog_uebernehmen($probelauf),
			'jahre'     => [],
			'licht'     => 0,
			'dokumente' => 0,
		];
		$katalog = $this->katalog_map($probelauf);
		foreach ($this->ablage->jahre() as $jahr) {
			if ($jahr >= ErgebnisService::ERSTES_PLANJAHR) {
				// Ordner ab 2027 gehören dem Upload; dort wird nichts aus dem Katalog eingetragen.
				continue;
			}
			$bericht['jahre'][ $jahr ] = $this->jahr_einlesen($jahr, $katalog, $probelauf);
		}
		$bericht['licht'] = $this->licht_einlesen($probelauf);
		$bericht['dokumente'] = $this->dokumente_uebernehmen($probelauf);
		if (!$probelauf) {
			Protokoll::admin('ergebnis.archiv', sprintf('Archiv eingelesen: %d Jahre, %d Ergebnisse', count($bericht['jahre']), array_sum(array_map(static fn(array $j): int => $j['e'] + $j['m'], $bericht['jahre']))), null, null, 'ergebnis', null, ['jahre' => array_map(static fn(array $j): array => array_diff_key($j, ['sonstige_liste' => 1, 'ungeklaert_liste' => 1]), $bericht['jahre'])]);
			do_action('kmm_ergebnis_geaendert', 0);
		}
		return $bericht;
	}

	/**
	 * @return array{gelesen: int, neu: int, vorhanden: bool}
	 */
	public function katalog_uebernehmen(bool $probelauf): array {
		$zeilen = $this->srd_zeilen();
		$bestand = $this->katalog->als_map();
		$neu = 0;
		foreach ($zeilen as $schluessel => $z) {
			if (isset($bestand[ $schluessel ])) {
				continue;
			}
			$neu++;
			if (!$probelauf) {
				$this->katalog->insert(['schluessel' => $schluessel, 'quelle' => 'srd', 'created_at' => Clock::now_utc()] + $z);
			}
		}
		return ['gelesen' => count($zeilen), 'neu' => $neu, 'vorhanden' => self::srd_tabelle_vorhanden()];
	}

	/**
	 * Katalog für die Auswertung; im Probelauf ergänzt um die noch nicht übernommenen Zeilen.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function katalog_map(bool $probelauf): array {
		$map = $this->katalog->als_map();
		if ($probelauf) {
			$map += $this->srd_zeilen();
		}
		return $map;
	}

	/**
	 * @return array<string, array{spo: string, disziplin_text: string, klasse_text: string}>
	 */
	private function srd_zeilen(): array {
		global $wpdb;
		if (!self::srd_tabelle_vorhanden()) {
			return [];
		}
		$rows = $wpdb->get_results('SELECT * FROM ' . self::SRD_TABELLE, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out = [];
		foreach (is_array($rows) ? $rows : [] as $r) {
			$r = array_change_key_case($r, CASE_LOWER);
			$schluessel = Schluessel::normalisieren((string) ($r['datei'] ?? ''));
			if ($schluessel === null) {
				continue;
			}
			$out[ $schluessel ] = [
				'spo'            => trim((string) ($r['spo'] ?? '')),
				'disziplin_text' => trim((string) ($r['disziplin'] ?? '')),
				'klasse_text'    => trim((string) ($r['altersklasse'] ?? '')),
			];
		}
		return $out;
	}

	/**
	 * @param array<string, array<string, mixed>> $katalog wird um Gruppen ergänzt
	 * @return array<string, mixed>
	 */
	private function jahr_einlesen(int $jahr, array &$katalog, bool $probelauf): array {
		$a = ArchivAuswertung::auswerten($jahr, $this->ablage->dateien($jahr), $katalog);
		$stand = $this->vorhandene_map($jahr);
		$neu = 0;
		foreach ($a->neue_katalogzeilen as $z) {
			$katalog[ $z['schluessel'] ] = $z;
			if (!$probelauf && $this->katalog->by_schluessel($z['schluessel']) === null) {
				$this->katalog->insert($z + ['quelle' => 'gruppe']);
			}
		}
		foreach ($a->treffer as $t) {
			$p = $t['platz'];
			$alt = $stand[ $t['art'] . ':' . $t['schluessel'] ] ?? null;
			if ($alt !== null && $alt['quelle'] === ErgebnisRepository::QUELLE_UPLOAD) {
				continue;
			}
			if ($alt === null) {
				$neu++;
			}
			if ($probelauf) {
				continue;
			}
			$daten = [
				'kennzahl'       => $p->kennzahl,
				'disziplin_text' => $p->disziplin_text,
				'klasse_text'    => $p->klasse_text,
				'datei'          => $t['datei'],
				'format'         => $t['format'],
				'groesse'        => $this->groesse($t['datei']),
				'datei_stand'    => $this->stand($t['datei']),
				'quelle'         => ErgebnisRepository::QUELLE_ARCHIV,
			];
			if ($alt === null || $alt['status'] === ErgebnisRepository::STATUS_UNGEKLAERT) {
				$daten['status'] = ErgebnisRepository::STATUS_SICHTBAR;
			}
			$this->ergebnisse->speichern($jahr, $t['art'], $t['schluessel'], $daten);
		}
		foreach ($a->ungeklaert as $u) {
			if (isset($stand[ $u['art'] . ':' . $u['schluessel'] ])) {
				continue;
			}
			if (!$probelauf) {
				$this->ergebnisse->speichern($jahr, $u['art'], $u['schluessel'], [
					'datei'       => $u['datei'],
					'format'      => $u['format'],
					'groesse'     => $this->groesse($u['datei']),
					'datei_stand' => $this->stand($u['datei']),
					'status'      => ErgebnisRepository::STATUS_UNGEKLAERT,
					'quelle'      => ErgebnisRepository::QUELLE_ARCHIV,
				]);
			}
		}
		return [
			'e'                => $a->anzahl('e'),
			'm'                => $a->anzahl('m'),
			'neu'              => $neu,
			'gruppen'          => count($a->neue_katalogzeilen),
			'ungeklaert'       => count($a->ungeklaert),
			'ungeklaert_liste' => array_map(static fn(array $u): string => basename($u['datei']), $a->ungeklaert),
			'sonstige'         => count($a->sonstige),
			'sonstige_liste'   => $a->sonstige,
			'doppelt'          => count($a->nicht_verwendet),
		];
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function vorhandene_map(int $jahr): array {
		$out = [];
		foreach ($this->ergebnisse->by_jahr($jahr) as $r) {
			$out[ $r['art'] . ':' . $r['schluessel'] ] = $r;
		}
		return $out;
	}

	private function groesse(string $relativ): int {
		$p = $this->ablage->pfad($relativ);
		return $p !== null ? (int) filesize($p) : 0;
	}

	private function stand(string $relativ): ?string {
		$p = $this->ablage->pfad($relativ);
		return $p !== null ? gmdate('Y-m-d H:i:s', (int) filemtime($p)) : null;
	}

	/** Lichtschießen: je Datei ein Link-Dokument „Ergebnisse Lichtschießen“. */
	private function licht_einlesen(bool $probelauf): int {
		$neu = 0;
		foreach ($this->ablage->licht_dateien() as $jahr => $dateien) {
			$mehrere = count($dateien) > 1;
			foreach ($dateien as $i => $relativ) {
				$herkunft = 'licht:' . $relativ;
				if ($this->dokumente->first(['herkunft' => $herkunft]) !== null) {
					continue;
				}
				$neu++;
				if ($probelauf) {
					continue;
				}
				$this->dokumente->insert([
					'jahr'       => $jahr,
					'typ'        => Dokumente::TYP_LICHT,
					'kategorie'  => 11,
					'titel'      => $mehrere ? sprintf('Ergebnisse Lichtschießen (%s)', pathinfo($relativ, PATHINFO_FILENAME)) : 'Ergebnisse Lichtschießen',
					'quelle'     => Dokumente::QUELLE_ERGEBNISORDNER,
					'url'        => $relativ,
					'sortierung' => 200 + $i,
					'herkunft'   => $herkunft,
				]);
			}
		}
		return $neu;
	}

	/**
	 * Ausschreibungen aus srd_km_documents. Die Sätze je Jahr gehen in ihr Jahr; der
	 * „aktuelle“ Satz (_current), den das alte Plugin für alle Jahre gezeigt hat, gehört
	 * zum aktiven Sportjahr und hat dort Vorrang.
	 */
	public function dokumente_uebernehmen(bool $probelauf): int {
		$alle = get_option(self::SRD_DOKUMENTE, []);
		if (!is_array($alle)) {
			return 0;
		}
		$aktiv = (new SportjahrRepository())->aktiv();
		$saetze = [];
		if (isset($alle['_current']) && is_array($alle['_current'])) {
			$saetze[] = [$aktiv !== null ? (int) $aktiv['jahr'] : (int) gmdate('Y'), '_current', $alle['_current']];
		}
		foreach ($alle as $schluessel => $satz) {
			if (is_array($satz) && preg_match('/^(19|20)\d{2}$/', (string) $schluessel)) {
				$saetze[] = [(int) $schluessel, (string) $schluessel, $satz];
			}
		}
		$neu = 0;
		$belegt = [];
		foreach ($saetze as [$jahr, $satzname, $satz]) {
			$sort = 0;
			foreach ($satz as $typ_alt => $e) {
				if (!is_array($e) || !in_array($e['type'] ?? '', ['pdf', 'page', 'url'], true)) {
					continue;
				}
				$sort++;
				[$typ, $kategorie, $titel] = $this->srd_typ((string) $typ_alt, $e);
				if ($typ === null) {
					continue;
				}
				$merkmal = $jahr . '|' . $typ . '|' . (int) $kategorie . '|' . $titel;
				if (isset($belegt[ $merkmal ])) {
					continue;
				}
				$belegt[ $merkmal ] = true;
				$herkunft = 'srd:' . $satzname . ':' . $typ_alt;
				if ($this->dokumente->first(['herkunft' => $herkunft]) !== null) {
					continue;
				}
				$neu++;
				if ($probelauf) {
					continue;
				}
				$this->dokumente->insert([
					'jahr'          => $jahr,
					'typ'           => $typ,
					'kategorie'     => $kategorie,
					'titel'         => $titel,
					'quelle'        => (string) $e['type'],
					'attachment_id' => $e['type'] === 'pdf' ? (int) ($e['attachment_id'] ?? 0) : null,
					'page_id'       => $e['type'] === 'page' ? (int) ($e['page_id'] ?? 0) : null,
					'url'           => $e['type'] === 'url' ? (string) ($e['url'] ?? '') : '',
					'sortierung'    => ($typ === Dokumente::TYP_KATEGORIE ? 100 : 0) + $sort,
					'herkunft'      => $herkunft,
				]);
			}
		}
		return $neu;
	}

	/**
	 * @param array<string, mixed> $e
	 * @return array{0: ?string, 1: ?int, 2: string}
	 */
	private function srd_typ(string $typ_alt, array $e): array {
		if (isset(self::SRD_TYPEN[ $typ_alt ])) {
			return [self::SRD_TYPEN[ $typ_alt ], null, ''];
		}
		if (preg_match('/^cat_(\d+)$/', $typ_alt, $m) && Kategorie::gibt_es((int) $m[1])) {
			return [Dokumente::TYP_KATEGORIE, (int) $m[1], ''];
		}
		if (str_starts_with($typ_alt, 'custom_')) {
			$kats = array_values(array_filter(array_map('intval', (array) ($e['categories'] ?? [])), [Kategorie::class, 'gibt_es']));
			return [Dokumente::TYP_KATEGORIE, $kats[0] ?? null, sanitize_text_field((string) ($e['label'] ?? ''))];
		}
		return [null, null, ''];
	}
}
