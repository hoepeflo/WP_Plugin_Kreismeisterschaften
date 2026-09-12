<?php
/**
 * Export der Stammdaten eines Sportjahres als Regeltabellen-Dokument.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Domain\KlassenRef;
use KSV\KMM\Domain\RegelModus;
use KSV\KMM\Domain\Regeltabelle\Dokument;
use KSV\KMM\Infrastructure\Repository\DisziplinRepository;
use KSV\KMM\Infrastructure\Repository\GruppeRepository;
use KSV\KMM\Infrastructure\Repository\KlasseRepository;
use KSV\KMM\Infrastructure\Repository\RegelRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\TarifRepository;

final class RegeltabelleExporter {

	public function exportieren(int $sportjahr_id): Dokument {
		$sportjahr = (new SportjahrRepository())->find($sportjahr_id);
		if ($sportjahr === null) {
			throw new \RuntimeException('Sportjahr nicht gefunden.');
		}
		$gruppen_repo = new GruppeRepository();
		$klassen_repo = new KlasseRepository();

		$codes = [];
		$gruppen = [];
		foreach ($gruppen_repo->by_sportjahr($sportjahr_id) as $g) {
			$codes[ (int) $g['id'] ] = (string) $g['code'];
			$klassen = [];
			foreach ($klassen_repo->by_gruppe((int) $g['id']) as $k) {
				$klassen[] = [
					'nummer'          => (int) $k['nummer'],
					'geschlecht'      => (string) $k['geschlecht'],
					'bezeichnung'     => (string) $k['bezeichnung'],
					'alter_von'       => $k['alter_von'],
					'alter_bis'       => $k['alter_bis'],
					'tarifstufe'      => (string) $k['tarifstufe'],
					'stufe'           => $k['stufe'],
					'ist_para'        => (bool) $k['ist_para'],
					'ist_teamklasse'  => (bool) $k['ist_teamklasse'],
					'festgeschrieben' => (bool) $k['festgeschrieben'],
					'hinweis'         => (string) $k['hinweis'],
					'sortierung'      => (int) $k['sortierung'],
				];
			}
			$gruppen[] = [
				'code'                  => (string) $g['code'],
				'bezeichnung'           => (string) $g['bezeichnung'],
				'hoehermeldung_bereich' => $g['hoehermeldung_bereich'],
				'ist_para'              => (bool) $g['ist_para'],
				'sortierung'            => (int) $g['sortierung'],
				'klassen'               => $klassen,
			];
		}

		$klassen_refs = [];
		foreach ($klassen_repo->by_sportjahr($sportjahr_id) as $k) {
			$code = $codes[ (int) $k['gruppe_id'] ] ?? '';
			$klassen_refs[ (int) $k['id'] ] = KlassenRef::make($code, (int) $k['nummer'], (string) $k['geschlecht'])->toString();
		}

		$regeln_repo = new RegelRepository();
		$regeln_nach_disziplin = [];
		foreach ($regeln_repo->by_sportjahr($sportjahr_id) as $r) {
			$regeln_nach_disziplin[ (int) $r['disziplin_id'] ][] = $r;
		}

		$disziplinen = [];
		foreach ((new DisziplinRepository())->by_sportjahr($sportjahr_id) as $d) {
			$regeln = [];
			foreach ($regeln_nach_disziplin[ (int) $d['id'] ] ?? [] as $r) {
				$regeln[] = [
					'klasse'          => $klassen_refs[ (int) $r['klasse_id'] ] ?? '',
					'einzel'          => (string) $r['einzel_modus'],
					'einzel_ziel'     => $r['einzel_modus'] === RegelModus::VERWEIS ? ($klassen_refs[ (int) $r['einzel_ziel_klasse_id'] ] ?? null) : null,
					'mannschaft'      => (string) $r['mannschaft_modus'],
					'mannschaft_ziel' => $r['mannschaft_modus'] === RegelModus::VERWEIS ? ($klassen_refs[ (int) $r['mannschaft_ziel_klasse_id'] ] ?? null) : null,
					'mindestalter'    => $r['mindestalter'],
					'hinweis'         => (string) $r['hinweis'],
				];
			}
			$disziplinen[] = [
				'kennzahl'               => (string) $d['kennzahl'],
				'bezeichnung'            => (string) $d['bezeichnung'],
				'gruppe'                 => $codes[ (int) $d['gruppe_id'] ] ?? '',
				'typ'                    => (string) $d['typ'],
				'angeboten'              => (bool) $d['angeboten'],
				'mannschaft_groesse'     => (int) $d['mannschaft_groesse'],
				'ergebnis_format'        => (string) $d['ergebnis_format'],
				'tarif_override'         => $d['tarif_override'],
				'mannschaft_startgeld'   => (float) $d['mannschaft_startgeld'],
				'mixteam_kennzahl_modus' => $d['mixteam_kennzahl_modus'],
				'hinweis'                => (string) ($d['hinweis'] ?? ''),
				'sortierung'             => (int) $d['sortierung'],
				'regeln'                 => $regeln,
			];
		}

		return Dokument::fromArray([
			'format'      => Dokument::FORMAT,
			'version'     => Dokument::VERSION,
			'sportjahr'   => (int) $sportjahr['jahr'],
			'stand'       => sprintf('Export aus dem KM-Portal am %s', gmdate('Y-m-d H:i') . ' UTC'),
			'gruppen'     => $gruppen,
			'tarife'      => (new TarifRepository())->by_sportjahr($sportjahr_id),
			'disziplinen' => $disziplinen,
		]);
	}
}
