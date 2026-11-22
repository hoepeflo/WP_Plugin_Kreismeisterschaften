<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Unit\Ergebnis;

use KSV\KMM\Application\Ergebnisse\Testjahr;
use PHPUnit\Framework\TestCase;

/**
 * Nur ein nicht aktives Sportjahr mit „TEST“ in der Bezeichnung darf entfernt werden.
 */
final class TestjahrTest extends TestCase {

	public function test_nur_inaktives_testjahr(): void {
		$this->assertTrue(Testjahr::ist_testjahr(['bezeichnung' => 'KM 2026 TEST', 'ist_aktiv' => 0]));
		$this->assertTrue(Testjahr::ist_testjahr(['bezeichnung' => 'Testlauf', 'ist_aktiv' => false]));
		$this->assertFalse(Testjahr::ist_testjahr(['bezeichnung' => 'KM 2026 TEST', 'ist_aktiv' => 1]), 'aktives Jahr nie');
		$this->assertFalse(Testjahr::ist_testjahr(['bezeichnung' => 'KM 2027', 'ist_aktiv' => 0]), 'echtes Jahr nie');
		$this->assertFalse(Testjahr::ist_testjahr(['bezeichnung' => '', 'ist_aktiv' => 0]));
	}
}
