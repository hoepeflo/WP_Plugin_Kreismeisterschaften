<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Unit\Sicherung;

use KSV\KMM\Application\Sicherung\Zeitplan;
use PHPUnit\Framework\TestCase;

final class ZeitplanTest extends TestCase {

	public function test_zeitraum(): void {
		$this->assertFalse(Zeitplan::im_zeitraum(false, '', '', '2027-01-10'));
		$this->assertTrue(Zeitplan::im_zeitraum(true, '', '', '2027-01-10'));
		$this->assertTrue(Zeitplan::im_zeitraum(true, '2026-11-01', '2027-04-30', '2026-11-01'));
		$this->assertTrue(Zeitplan::im_zeitraum(true, '2026-11-01', '2027-04-30', '2027-04-30'));
		$this->assertFalse(Zeitplan::im_zeitraum(true, '2026-11-01', '2027-04-30', '2027-05-01'));
		$this->assertFalse(Zeitplan::im_zeitraum(true, '2026-11-01', '2027-04-30', '2026-10-31'));
		$this->assertTrue(Zeitplan::im_zeitraum(true, '2026-11-01', '', '2030-01-01'));
	}

	public function test_taeglich_einmal_ab_uhrzeit(): void {
		$this->assertFalse(Zeitplan::taeglich_faellig('', '2026-11-22', '02:59', '03:00'));
		$this->assertTrue(Zeitplan::taeglich_faellig('', '2026-11-22', '03:00', '03:00'));
		$this->assertTrue(Zeitplan::taeglich_faellig('2026-11-21', '2026-11-22', '14:00', '03:00'));
		$this->assertFalse(Zeitplan::taeglich_faellig('2026-11-22', '2026-11-22', '23:00', '03:00'));
	}

	public function test_ereignisse(): void {
		$this->assertTrue(Zeitplan::ist_ereignis('meldung.einreichen'));
		$this->assertTrue(Zeitplan::ist_ereignis('wettkampftag.veroeffentlichen'));
		$this->assertFalse(Zeitplan::ist_ereignis('sicherung.erstellen'));
		$this->assertFalse(Zeitplan::ist_ereignis('meldung.einzel.anlegen'));
	}

	public function test_dateiname(): void {
		$name = Zeitplan::dateiname('vor-wiederherstellung', 1795000000);
		$this->assertSame('kmm-sicherung-20261118-110640-vor-wiederherstellung.kmmsic', $name);
		$this->assertTrue(Zeitplan::gueltiger_dateiname($name));
		$this->assertTrue(Zeitplan::gueltiger_dateiname(Zeitplan::dateiname('../böse/', 0)));
		$this->assertFalse(Zeitplan::gueltiger_dateiname('../kmm-sicherung-20261116-061320-x.kmmsic'));
	}
}
