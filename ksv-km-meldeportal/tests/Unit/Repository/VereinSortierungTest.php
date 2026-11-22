<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Unit\Repository;

use KSV\KMM\Infrastructure\Repository\VereinRepository;
use PHPUnit\Framework\TestCase;

final class VereinSortierungTest extends TestCase {

	public function test_sortiert_aufsteigend_nach_vereinsnummer(): void {
		$vereine = [
			['name' => 'SV Vorwalsrode', 'vn_nummer' => '30101'],
			['name' => 'SV Eickeloh', 'vn_nummer' => '30105'],
			['name' => 'SC Düshorn', 'vn_nummer' => '30106'],
			['name' => 'SK Walsrode', 'vn_nummer' => '30102'],
			['name' => 'SV Kurz', 'vn_nummer' => '999'],
		];
		$vn = array_column(VereinRepository::nach_vn($vereine), 'vn_nummer');
		$this->assertSame(['999', '30101', '30102', '30105', '30106'], $vn);
	}

	public function test_bei_gleicher_nummer_nach_name(): void {
		$vereine = [
			['name' => 'B-Verein', 'vn_nummer' => '30101'],
			['name' => 'A-Verein', 'vn_nummer' => '30101'],
		];
		$this->assertSame(['A-Verein', 'B-Verein'], array_column(VereinRepository::nach_vn($vereine), 'name'));
	}
}
