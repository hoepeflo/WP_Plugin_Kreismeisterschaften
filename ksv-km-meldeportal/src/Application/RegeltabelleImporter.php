<?php
/**
 * Import eines Regeltabellen-Dokuments in ein Sportjahr.
 *
 * Gruppen und Klassen werden über ihren Schlüssel (code bzw. nummer+geschlecht) angelegt
 * oder aktualisiert, nie gelöscht (Meldungen könnten sie referenzieren). Disziplinen
 * werden über die Kennzahl angelegt oder aktualisiert. Regeln einer importierten
 * Disziplin werden vollständig ersetzt. Disziplinen, die im Dokument fehlen, bleiben
 * bestehen (Modus "ergaenzen") oder werden gelöscht, sofern keine Meldung sie
 * referenziert (Modus "ersetzen").
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Domain\KlassenRef;
use KSV\KMM\Domain\RegelModus;
use KSV\KMM\Domain\Regeltabelle\Dokument;
use KSV\KMM\Infrastructure\Database\Tables;
use KSV\KMM\Infrastructure\Repository\DisziplinRepository;
use KSV\KMM\Infrastructure\Repository\GruppeRepository;
use KSV\KMM\Infrastructure\Repository\KlasseRepository;
use KSV\KMM\Infrastructure\Repository\RegelRepository;
use KSV\KMM\Infrastructure\Repository\TarifRepository;
use KSV\KMM\Support\Clock;

final class RegeltabelleImporter {

	public const MODUS_ERGAENZEN = 'ergaenzen';
	public const MODUS_ERSETZEN  = 'ersetzen';

	private GruppeRepository $gruppen;
	private KlasseRepository $klassen;
	private DisziplinRepository $disziplinen;
	private RegelRepository $regeln;
	private TarifRepository $tarife;

	/** @var array<string, int> "gruppe:nummer+geschlecht" => klasse_id */
	private array $klassen_index = [];

	/** @var array<string, int> code => gruppe_id */
	private array $gruppen_index = [];

	public function __construct() {
		$this->gruppen = new GruppeRepository();
		$this->klassen = new KlasseRepository();
		$this->disziplinen = new DisziplinRepository();
		$this->regeln = new RegelRepository();
		$this->tarife = new TarifRepository();
	}

	/**
	 * @return array{gruppen_neu: int, klassen_neu: int, klassen_aktualisiert: int, disziplinen_neu: int, disziplinen_aktualisiert: int, disziplinen_geloescht: int, regeln: int, warnungen: string[]}
	 * @throws \RuntimeException bei nicht auflösbaren Referenzen (nichts wird geschrieben).
	 */
	public function importieren(int $sportjahr_id, Dokument $doc, string $modus = self::MODUS_ERGAENZEN): array {
		$report = [
			'gruppen_neu'              => 0,
			'klassen_neu'              => 0,
			'klassen_aktualisiert'     => 0,
			'disziplinen_neu'          => 0,
			'disziplinen_aktualisiert' => 0,
			'disziplinen_geloescht'    => 0,
			'regeln'                   => 0,
			'warnungen'                => [],
		];

		$this->lade_index($sportjahr_id);

		// Vorprüfung: alle referenzierten Klassen müssen nach dem Import existieren.
		$fehler = [];
		foreach ($doc->gruppen as $g) {
			foreach ($g['klassen'] as $k) {
				$this->klassen_index[ KlassenRef::make($g['code'], $k['nummer'], $k['geschlecht'])->key() ] ??= -1;
			}
			$this->gruppen_index[ $g['code'] ] ??= -1;
		}
		foreach ($doc->disziplinen as $d) {
			if (!isset($this->gruppen_index[ $d['gruppe'] ])) {
				$fehler[] = sprintf('Disziplin %s: Gruppe "%s" existiert nicht.', $d['kennzahl'], $d['gruppe']);
			}
			foreach ($d['regeln'] as $r) {
				foreach (['klasse', 'einzel_ziel', 'mannschaft_ziel'] as $feld) {
					$ref = $r[ $feld ];
					if ($ref instanceof KlassenRef && !isset($this->klassen_index[ $ref->key() ])) {
						$fehler[] = sprintf('Disziplin %s: Klasse %s existiert nicht.', $d['kennzahl'], $ref->toString($d['gruppe']));
					}
				}
			}
		}
		if ($fehler !== []) {
			throw new \RuntimeException("Import abgebrochen:\n- " . implode("\n- ", array_unique($fehler)));
		}

		// Gruppen und Klassen anlegen/aktualisieren.
		foreach ($doc->gruppen as $g) {
			$gruppe = $this->gruppen->by_code($sportjahr_id, $g['code']);
			$daten = [
				'bezeichnung'           => $g['bezeichnung'],
				'hoehermeldung_bereich' => $g['hoehermeldung_bereich'],
				'ist_para'              => $g['ist_para'],
				'sortierung'            => $g['sortierung'],
			];
			if ($gruppe === null) {
				$daten['sportjahr_id'] = $sportjahr_id;
				$daten['code'] = $g['code'];
				$gruppe_id = $this->gruppen->insert($daten);
				$report['gruppen_neu']++;
			} else {
				$gruppe_id = (int) $gruppe['id'];
				$this->gruppen->update($gruppe_id, $daten);
			}
			$this->gruppen_index[ $g['code'] ] = $gruppe_id;
			foreach ($g['klassen'] as $k) {
				$key = KlassenRef::make($g['code'], $k['nummer'], $k['geschlecht'])->key();
				$vorhanden = $this->klassen->by_key($gruppe_id, $k['nummer'], $k['geschlecht']);
				if ($vorhanden === null) {
					$k['sportjahr_id'] = $sportjahr_id;
					$k['gruppe_id'] = $gruppe_id;
					$this->klassen_index[ $key ] = $this->klassen->insert($k);
					$report['klassen_neu']++;
				} else {
					$this->klassen->update((int) $vorhanden['id'], $k);
					$this->klassen_index[ $key ] = (int) $vorhanden['id'];
					$report['klassen_aktualisiert']++;
				}
			}
		}

		foreach ($doc->tarife as $stufe => $betrag) {
			$this->tarife->set($sportjahr_id, $stufe, $betrag);
		}

		// Disziplinen und Regeln.
		$importierte_kennzahlen = [];
		foreach ($doc->disziplinen as $d) {
			$importierte_kennzahlen[ $d['kennzahl'] ] = true;
			$gruppe_id = $this->gruppen_index[ $d['gruppe'] ];
			$daten = [
				'gruppe_id'              => $gruppe_id,
				'bezeichnung'            => $d['bezeichnung'] !== '' ? $d['bezeichnung'] : $d['kennzahl'],
				'typ'                    => $d['typ'],
				'angeboten'              => $d['angeboten'],
				'mannschaft_groesse'     => $d['mannschaft_groesse'],
				'ergebnis_format'        => $d['ergebnis_format'],
				'tarif_override'         => $d['tarif_override'],
				'mannschaft_startgeld'   => $d['mannschaft_startgeld'],
				'mixteam_kennzahl_modus' => $d['mixteam_kennzahl_modus'],
				'hinweis'                => $d['hinweis'],
				'sortierung'             => $d['sortierung'],
			];
			$vorhanden = $this->disziplinen->by_kennzahl($sportjahr_id, $d['kennzahl']);
			if ($vorhanden === null) {
				$daten['sportjahr_id'] = $sportjahr_id;
				$daten['kennzahl'] = $d['kennzahl'];
				$disziplin_id = $this->disziplinen->insert($daten);
				$report['disziplinen_neu']++;
			} else {
				$disziplin_id = (int) $vorhanden['id'];
				$this->disziplinen->update($disziplin_id, $daten);
				$report['disziplinen_aktualisiert']++;
			}

			$this->regeln->delete_where(['disziplin_id' => $disziplin_id]);
			foreach ($d['regeln'] as $r) {
				if ($r['einzel'] === RegelModus::KEINE && $r['mannschaft'] === RegelModus::KEINE) {
					continue;
				}
				$this->regeln->insert([
					'sportjahr_id'              => $sportjahr_id,
					'disziplin_id'              => $disziplin_id,
					'klasse_id'                 => $this->klassen_index[ $r['klasse']->key() ],
					'einzel_modus'              => $r['einzel'],
					'einzel_ziel_klasse_id'     => $r['einzel_ziel'] instanceof KlassenRef ? $this->klassen_index[ $r['einzel_ziel']->key() ] : null,
					'mannschaft_modus'          => $r['mannschaft'],
					'mannschaft_ziel_klasse_id' => $r['mannschaft_ziel'] instanceof KlassenRef ? $this->klassen_index[ $r['mannschaft_ziel']->key() ] : null,
					'mindestalter'              => $r['mindestalter'],
					'hinweis'                   => $r['hinweis'],
					'quelle'                    => 'import',
					'updated_at'                => Clock::now_utc(),
				]);
				$report['regeln']++;
			}
		}

		if ($modus === self::MODUS_ERSETZEN) {
			global $wpdb;
			foreach ($this->disziplinen->by_sportjahr($sportjahr_id) as $d) {
				if (isset($importierte_kennzahlen[ (string) $d['kennzahl'] ])) {
					continue;
				}
				$referenzen = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Tables::name('einzelmeldung') . ' WHERE disziplin_id = %d', (int) $d['id'])); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ($referenzen > 0) {
					$report['warnungen'][] = sprintf('Disziplin %s fehlt im Dokument, bleibt aber erhalten (%d Meldungen).', (string) $d['kennzahl'], $referenzen);
					continue;
				}
				$this->regeln->delete_where(['disziplin_id' => (int) $d['id']]);
				$this->disziplinen->delete((int) $d['id']);
				$report['disziplinen_geloescht']++;
			}
		}

		(new SportjahrService())->regeln_geaendert($sportjahr_id);
		Protokoll::admin(
			'regeltabelle.import',
			sprintf('Regeltabelle importiert (%s): %d Disziplinen neu, %d aktualisiert, %d gelöscht, %d Regeln', $modus, $report['disziplinen_neu'], $report['disziplinen_aktualisiert'], $report['disziplinen_geloescht'], $report['regeln']),
			$sportjahr_id,
			null,
			'sportjahr',
			$sportjahr_id,
			['stand' => $doc->stand, 'warnungen' => $report['warnungen']]
		);

		return $report;
	}

	private function lade_index(int $sportjahr_id): void {
		$this->gruppen_index = [];
		$this->klassen_index = [];
		$codes = [];
		foreach ($this->gruppen->by_sportjahr($sportjahr_id) as $g) {
			$this->gruppen_index[ (string) $g['code'] ] = (int) $g['id'];
			$codes[ (int) $g['id'] ] = (string) $g['code'];
		}
		foreach ($this->klassen->by_sportjahr($sportjahr_id) as $k) {
			$code = $codes[ (int) $k['gruppe_id'] ] ?? null;
			if ($code === null) {
				continue;
			}
			$this->klassen_index[ KlassenRef::make($code, (int) $k['nummer'], (string) $k['geschlecht'])->key() ] = (int) $k['id'];
		}
	}
}
