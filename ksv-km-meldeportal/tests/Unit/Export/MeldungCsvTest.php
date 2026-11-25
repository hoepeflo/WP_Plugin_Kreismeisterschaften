<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Unit\Export;

use KSV\KMM\Application\MeldungCsv;
use PHPUnit\Framework\TestCase;

final class MeldungCsvTest extends TestCase {

	public function test_zeilen_mit_starttag_und_bom(): void {
		$z = ['einzelmeldungen' => [[
			'kennzahl_voll' => '2.10.30', 'disziplin' => 'Luftpistole', 'nachname' => 'Müller', 'vorname' => 'Karin',
			'klasse' => 'Jugend w', 'startklasse' => 'Jugend', 'meldeergebnis' => '350', 'nicht_meldung' => true,
			'mannschaft_nummer' => 2, 'typ' => 'normal', 'startgeld' => 7.5,
			'starttage' => [['datum' => 'Sa, 20.02.2027', 'iso' => '2027-02-20', 'bezeichnung' => 'LP', 'ort' => 'Vierde; Halle 1', 'vorlaeufig' => true]],
		]]];
		$csv = MeldungCsv::erzeugen($z);
		$this->assertStringStartsWith("\xEF\xBB\xBFKennzahl;Disziplin;", $csv);
		$zeilen = explode("\r\n", trim($csv));
		$this->assertSame('2.10.30;Luftpistole;Müller;Karin;Jugend w;Jugend;350;ja;M2;7,50;Sa, 20.02.2027 (vorläufig);"Vierde; Halle 1"', $zeilen[1]);
	}

	public function test_formel_wird_entschaerft(): void {
		$z = ['einzelmeldungen' => [[
			'kennzahl_voll' => '1.10', 'disziplin' => 'LG', 'nachname' => '=HYPERLINK("x")', 'vorname' => 'A',
			'klasse' => '', 'startklasse' => '', 'meldeergebnis' => '', 'nicht_meldung' => false,
			'mannschaft_nummer' => null, 'typ' => 'mixteam', 'startgeld' => 0.0, 'starttage' => [],
		]]];
		$zeilen = explode("\r\n", trim(MeldungCsv::erzeugen($z)));
		$this->assertStringContainsString(';"\'=HYPERLINK(""x"")";', $zeilen[1]);
	}
}
