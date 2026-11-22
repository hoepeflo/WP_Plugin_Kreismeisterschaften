<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Unit\Admin;

use KSV\KMM\Admin\WettkampftagePage;
use KSV\KMM\Domain\WettkampftagStatus;
use PHPUnit\Framework\TestCase;

final class WettkampftagFristTest extends TestCase {

	private const JETZT = '2026-11-22 18:00:00';
	private const TAGENDE = '2027-01-16 22:59:00';

	public function test_ohne_frist_kein_hinweis(): void {
		$this->assertNull(WettkampftagePage::frist_problem(null, self::TAGENDE, WettkampftagStatus::ENTWURF, self::JETZT));
		$this->assertNull(WettkampftagePage::frist_problem('', self::TAGENDE, WettkampftagStatus::ENTWURF, self::JETZT));
	}

	public function test_frist_zwischen_heute_und_wettkampftag_ist_in_ordnung(): void {
		$this->assertNull(WettkampftagePage::frist_problem('2027-01-10 22:59:00', self::TAGENDE, WettkampftagStatus::ENTWURF, self::JETZT));
	}

	public function test_frist_nach_dem_wettkampftag_wird_immer_gemeldet(): void {
		foreach (WettkampftagStatus::ALLE as $status) {
			$this->assertNotNull(WettkampftagePage::frist_problem('2027-01-20 12:00:00', self::TAGENDE, $status, self::JETZT), $status);
		}
	}

	public function test_vergangene_frist_nur_im_entwurf(): void {
		$frist = '2026-11-01 22:59:00';
		$this->assertNotNull(WettkampftagePage::frist_problem($frist, self::TAGENDE, WettkampftagStatus::ENTWURF, self::JETZT));
		// Nach der Freigabe ist eine abgelaufene Frist der normale Verlauf, kein Fehler.
		$this->assertNull(WettkampftagePage::frist_problem($frist, self::TAGENDE, WettkampftagStatus::FREIGEGEBEN, self::JETZT));
	}
}
