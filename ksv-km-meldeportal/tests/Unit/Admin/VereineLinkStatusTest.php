<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Unit\Admin;

use KSV\KMM\Admin\VereinePage;
use PHPUnit\Framework\TestCase;

final class VereineLinkStatusTest extends TestCase {

	public function test_ohne_gueltigen_link_rot(): void {
		$this->assertSame('kein', VereinePage::link_status(null, null, false));
		$this->assertSame('kein', VereinePage::link_status(null, '2026-09-13 06:35:00', false));
	}

	public function test_link_ohne_erfolgreiche_mail_rot(): void {
		$this->assertSame('kein', VereinePage::link_status('2026-09-13 06:35:00', null, false));
	}

	public function test_erfolgreiche_mail_nur_vor_dem_gueltigen_link_zaehlt_nicht(): void {
		// Alte Mail ging durch, der Link daraus wurde widerrufen, die neue Mail scheiterte.
		$this->assertSame('kein', VereinePage::link_status('2026-10-01 08:00:00', '2026-09-13 06:35:00', false));
	}

	public function test_erfolgreiche_mail_mit_gueltigem_link_orange(): void {
		$this->assertSame('gesendet', VereinePage::link_status('2026-09-13 06:35:00', '2026-09-13 06:35:00', false));
		// z. B. Erinnerung kam nach einer fehlgeschlagenen Zugangsmail an
		$this->assertSame('gesendet', VereinePage::link_status('2026-09-13 06:35:00', '2026-12-01 18:00:00', false));
	}

	public function test_benutzt_gruen(): void {
		$this->assertSame('verwendet', VereinePage::link_status(null, null, true));
		$this->assertSame('verwendet', VereinePage::link_status('2026-09-13 06:35:00', null, true));
	}
}
