<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Unit\Export;

use KSV\KMM\Domain\Export\Meldezeile;
use KSV\KMM\Infrastructure\Export\David21Exporter;
use PHPUnit\Framework\TestCase;

final class David21ExporterTest extends TestCase {

	private function zeile(?float $ergebnis, string $format = 'ganz', ?int $mannschaft = 1, string $name = 'Müller'): Meldezeile {
		return new Meldezeile('1.10.12', '1.10', 'Luftgewehr', $name, 'Hans', '28006', 'SV Katzenstein', $ergebnis, $format, '2002-01-01', '280060300', false, $mannschaft, 'Herren II', 'Herren II', 12, 'm', 'eingereicht');
	}

	public function test_format_wie_muster_windows_1252(): void {
		$csv = (new David21Exporter())->exportieren([$this->zeile(375.0), $this->zeile(389.4, 'zehntel', null)]);
		$lines = explode("\r\n", trim($csv));
		$this->assertSame('Kennzahl;Name;Vorname;Verband;VN-Nummer;VN-Name;Meldeergebnis;Geburtsdatum;Mitgliedsnummer;Nicht-Meldung;DAS;Mannschaft', $lines[0]);
		$this->assertSame(iconv('UTF-8', 'Windows-1252', '1.10.12;Müller;Hans;28006;28006;SV Katzenstein;375;01.01.2002;280060300;0;0;1'), $lines[1]);
		$this->assertSame(iconv('UTF-8', 'Windows-1252', '1.10.12;Müller;Hans;28006;28006;SV Katzenstein;389,4;01.01.2002;280060300;0;0;'), $lines[2]);
		$this->assertStringNotContainsString("\xC3\xBC", $csv, 'kein UTF-8 ü');
	}

	public function test_optionen(): void {
		$e = new David21Exporter(',', 'utf-8-bom', 'komma_null', 'fest', 'NSSV', false);
		$csv = $e->exportieren([$this->zeile(375.0, 'ganz', null, 'O\'Brien, Jr')]);
		$this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
		$this->assertSame('1.10.12,"O\'Brien, Jr",Hans,NSSV,28006,SV Katzenstein,"375,0",01.01.2002,280060300,0,0,', trim(substr($csv, 3)));
		$tab = (new David21Exporter('tab', 'utf-8', 'ganz', 'leer', '', false))->exportieren([$this->zeile(null)]);
		$this->assertSame("1.10.12\tMüller\tHans\t\t28006\tSV Katzenstein\t\t01.01.2002\t280060300\t0\t0\t1", trim($tab));
	}
}
