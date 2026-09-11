<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Integration;

use KSV\KMM\Application\RegeltabelleExporter;
use KSV\KMM\Application\RegeltabelleImporter;
use KSV\KMM\Application\SportjahrService;
use KSV\KMM\Domain\Regeltabelle\Dokument;
use KSV\KMM\Domain\Seed\Klassensatz;
use KSV\KMM\Infrastructure\Repository\DisziplinRepository;
use KSV\KMM\Infrastructure\Repository\KlasseRepository;
use KSV\KMM\Infrastructure\Repository\ProtokollRepository;
use KSV\KMM\Infrastructure\Repository\RegelRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\TarifRepository;

final class SportjahrServiceTest extends IntegrationTestCase {

	public function test_anlegen_mit_seed_und_erstes_jahr_aktiv(): void {
		$service = new SportjahrService();
		$id = $service->anlegen(2026);

		$jahr = (new SportjahrRepository())->find($id);
		$this->assertSame(2026, $jahr['jahr']);
		$this->assertTrue($jahr['ist_aktiv']);

		$klassen = (new KlasseRepository())->by_sportjahr($id);
		$erwartet = array_sum(array_map(static fn(array $g): int => count($g['klassen']), Klassensatz::gruppen()));
		$this->assertCount($erwartet, $klassen);
		$this->assertSame(['schueler' => 3.0, 'jugend' => 5.0, 'erwachsene' => 7.0], (new TarifRepository())->by_sportjahr($id));

		// Seed erneut einspielen legt nichts doppelt an.
		$this->assertSame(['gruppen' => 0, 'klassen' => 0], $service->seed_einspielen($id));

		$this->expectException(\RuntimeException::class);
		$service->anlegen(2026);
	}

	public function test_import_export_roundtrip_und_kopieren(): void {
		$service = new SportjahrService();
		$id = $service->anlegen(2026);

		$doc = Dokument::fromArray([
			'format' => 'kmm-regeltabelle', 'version' => 1, 'sportjahr' => 2026,
			'tarife' => ['schueler' => 2.5],
			'disziplinen' => [
				[
					'kennzahl' => '1.10', 'bezeichnung' => 'Luftgewehr', 'gruppe' => 'freihand', 'ergebnis_format' => 'zehntel',
					'regeln' => [
						['klasse' => '10m', 'einzel' => 'eigen', 'mannschaft' => 'eigen'],
						['klasse' => '12m', 'einzel' => 'eigen', 'mannschaft' => 'verweis', 'mannschaft_ziel' => '10m'],
						['klasse' => '20m', 'einzel' => 'eigen', 'mannschaft' => 'eigen'],
						['klasse' => '21w', 'einzel' => 'eigen', 'mannschaft' => 'verweis', 'mannschaft_ziel' => '20m'],
						['klasse' => 'para:92m', 'einzel' => 'eigen'],
						['klasse' => '30m', 'einzel' => 'keine', 'mannschaft' => 'keine'],
					],
				],
				[
					'kennzahl' => '1.12', 'bezeichnung' => 'LG MixTeam', 'gruppe' => 'freihand', 'typ' => 'mixteam',
					'regeln' => [
						['klasse' => '40x', 'mannschaft' => 'eigen'],
						['klasse' => '40m', 'mannschaft' => 'verweis', 'mannschaft_ziel' => '40x'],
					],
				],
				['kennzahl' => '2.10', 'bezeichnung' => 'Luftpistole', 'gruppe' => 'freihand', 'regeln' => [['klasse' => '10m', 'einzel' => 'eigen']]],
			],
		]);

		$importer = new RegeltabelleImporter();
		$report = $importer->importieren($id, $doc);
		$this->assertSame(3, $report['disziplinen_neu']);
		$this->assertSame(8, $report['regeln'], 'Regel mit keine/keine wird nicht gespeichert');
		$this->assertSame(2.5, (new TarifRepository())->by_sportjahr($id)['schueler']);

		$export = (new RegeltabelleExporter())->exportieren($id)->toArray();
		$this->assertCount(7, $export['gruppen']);
		$this->assertSame(['1.10', '1.12', '2.10'], array_column($export['disziplinen'], 'kennzahl'));
		$lg = $export['disziplinen'][0];
		$this->assertSame('zehntel', $lg['ergebnis_format']);
		$refs = array_column($lg['regeln'], 'klasse');
		$this->assertContains('para:92m', $refs);
		$this->assertContains('21w', $refs);
		$r21 = array_values(array_filter($lg['regeln'], static fn(array $r): bool => $r['klasse'] === '21w'))[0];
		$this->assertSame('20m', $r21['mannschaft_ziel']);
		$mix = $export['disziplinen'][1];
		$this->assertSame(2, $mix['mannschaft_groesse']);
		$this->assertSame('team', $mix['mixteam_kennzahl_modus']);

		// Zweiter Import (ersetzen) ohne 2.10 löscht 2.10, aktualisiert 1.10.
		$doc2 = Dokument::fromArray([
			'format' => 'kmm-regeltabelle', 'version' => 1,
			'disziplinen' => [
				['kennzahl' => '1.10', 'bezeichnung' => 'Luftgewehr NEU', 'gruppe' => 'freihand', 'regeln' => [['klasse' => '10m', 'einzel' => 'eigen']]],
				['kennzahl' => '1.12', 'gruppe' => 'freihand', 'typ' => 'mixteam', 'regeln' => [['klasse' => '40x', 'mannschaft' => 'eigen']]],
			],
		]);
		$report2 = $importer->importieren($id, $doc2, RegeltabelleImporter::MODUS_ERSETZEN);
		$this->assertSame(2, $report2['disziplinen_aktualisiert']);
		$this->assertSame(1, $report2['disziplinen_geloescht']);
		$disziplinen = (new DisziplinRepository())->by_sportjahr($id);
		$this->assertCount(2, $disziplinen);
		$this->assertSame('Luftgewehr NEU', $disziplinen[0]['bezeichnung']);
		$this->assertCount(2, (new RegelRepository())->by_sportjahr($id));

		// Kopieren ins Folgejahr: gleiche Struktur, neue IDs, Verweise umgehängt.
		$neu = $service->kopieren($id, 2027);
		$export_neu = (new RegeltabelleExporter())->exportieren($neu)->toArray();
		$export_alt = (new RegeltabelleExporter())->exportieren($id)->toArray();
		unset($export_neu['stand'], $export_alt['stand'], $export_neu['sportjahr'], $export_alt['sportjahr']);
		$this->assertSame($export_alt, $export_neu);
		$this->assertFalse((new SportjahrRepository())->find($neu)['ist_aktiv']);

		$service->aktivieren($neu);
		$this->assertTrue((new SportjahrRepository())->find($neu)['ist_aktiv']);
		$this->assertFalse((new SportjahrRepository())->find($id)['ist_aktiv']);

		$aktionen = array_column((new ProtokollRepository())->neueste(), 'aktion');
		$this->assertContains('sportjahr.kopieren', $aktionen);
		$this->assertContains('regeltabelle.import', $aktionen);
		$this->assertContains('sportjahr.aktivieren', $aktionen);

		$service->loeschen($id);
		$this->assertNull((new SportjahrRepository())->find($id));
		$this->assertCount(0, (new KlasseRepository())->by_sportjahr($id));
	}

	public function test_import_lehnt_unbekannte_klasse_ab_ohne_zu_schreiben(): void {
		$id = (new SportjahrService())->anlegen(2026);
		$doc = Dokument::fromArray([
			'format' => 'kmm-regeltabelle', 'version' => 1,
			'disziplinen' => [['kennzahl' => '1.10', 'gruppe' => 'freihand', 'regeln' => [['klasse' => '99m', 'einzel' => 'eigen']]]],
		]);
		try {
			(new RegeltabelleImporter())->importieren($id, $doc);
			$this->fail('Erwartet RuntimeException');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Klasse 99m existiert nicht', $e->getMessage());
		}
		$this->assertCount(0, (new DisziplinRepository())->by_sportjahr($id));
	}
}
