<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Unit\App;

use KSV\KMM\Application\Starttage;
use PHPUnit\Framework\TestCase;

final class StarttageTest extends TestCase {

	/** @return array<int, array<string, mixed>> */
	private function tage(): array {
		return [
			2 => ['datum' => '2027-02-20', 'bezeichnung' => 'LP Nachwuchs', 'ort' => 'Vierde', 'status' => 'entwurf'],
			3 => ['datum' => '2027-02-21', 'bezeichnung' => 'Luftpistole', 'ort' => 'Vorwalsrode', 'status' => 'freigegeben'],
		];
	}

	public function test_startklasse_entscheidet_den_tag(): void {
		$z = [
			['tag' => 2, 'disziplin' => 10, 'klasse' => 92],
			['tag' => 3, 'disziplin' => 10, 'klasse' => 100],
		];
		$jugend = Starttage::aufloesen($z, $this->tage(), 10, 92);
		$this->assertCount(1, $jugend);
		$this->assertSame('Sa, 20.02.2027', $jugend[0]['datum']);
		$this->assertTrue($jugend[0]['vorlaeufig']);

		$herren = Starttage::aufloesen($z, $this->tage(), 10, 100);
		$this->assertSame('So, 21.02.2027', $herren[0]['datum']);
		$this->assertFalse($herren[0]['vorlaeufig']);
	}

	public function test_zulassung_ohne_klasse_gilt_fuer_alle_und_sortiert(): void {
		$z = [
			['tag' => 3, 'disziplin' => 11, 'klasse' => null],
			['tag' => 2, 'disziplin' => 11, 'klasse' => null],
			['tag' => 2, 'disziplin' => 11, 'klasse' => 5],
		];
		$t = Starttage::aufloesen($z, $this->tage(), 11, 77);
		$this->assertSame(['2027-02-20', '2027-02-21'], array_column($t, 'iso'));
	}

	public function test_ohne_planung_kein_tag(): void {
		$z = [['tag' => 9, 'disziplin' => 12, 'klasse' => null], ['tag' => 2, 'disziplin' => 10, 'klasse' => 92]];
		$this->assertSame([], Starttage::aufloesen($z, $this->tage(), 12, null));
		$this->assertSame([], Starttage::aufloesen($z, $this->tage(), 10, null));
	}
}
