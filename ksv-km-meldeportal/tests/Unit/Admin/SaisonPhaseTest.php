<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Unit\Admin;

use KSV\KMM\Admin\UebersichtPage;
use KSV\KMM\Domain\WettkampftagStatus;
use PHPUnit\Framework\TestCase;

final class SaisonPhaseTest extends TestCase {

	private const HEUTE = '2027-01-20';

	/** @return array<int, array<string, mixed>> */
	private function tage(string ...$status): array {
		$out = [];
		foreach ($status as $i => $s) {
			$out[] = ['datum' => '2027-02-0' . (6 + $i), 'status' => $s];
		}
		return $out;
	}

	public function test_vor_beginn_ist_vorbereitung(): void {
		$this->assertSame(0, UebersichtPage::phase_index('vor_beginn', false, [], self::HEUTE));
	}

	public function test_offene_meldephase(): void {
		$this->assertSame(1, UebersichtPage::phase_index('offen', false, $this->tage(WettkampftagStatus::ENTWURF), self::HEUTE));
	}

	public function test_nach_meldeschluss_verarbeitung_bis_zur_freigabe(): void {
		$this->assertSame(2, UebersichtPage::phase_index('geschlossen', false, $this->tage(WettkampftagStatus::ENTWURF, WettkampftagStatus::ENTWURF), self::HEUTE));
		$this->assertSame(3, UebersichtPage::phase_index('geschlossen', false, $this->tage(WettkampftagStatus::FREIGEGEBEN, WettkampftagStatus::ENTWURF), self::HEUTE));
	}

	public function test_ab_dem_ersten_wettkampftag_ergebnisse(): void {
		$this->assertSame(4, UebersichtPage::phase_index('geschlossen', false, $this->tage(WettkampftagStatus::VEROEFFENTLICHT), '2027-02-06'));
	}

	public function test_abgeschlossen(): void {
		$this->assertSame(5, UebersichtPage::phase_index('abgeschlossen', true, [], self::HEUTE));
	}
}
