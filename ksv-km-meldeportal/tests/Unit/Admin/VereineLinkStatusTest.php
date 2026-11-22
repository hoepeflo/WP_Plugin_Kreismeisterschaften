<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Unit\Admin;

use KSV\KMM\Admin\VereinePage;
use PHPUnit\Framework\TestCase;

final class VereineLinkStatusTest extends TestCase {

	public function test_ohne_link_rot(): void {
		$this->assertSame('kein', VereinePage::link_status(null, null, false));
	}

	public function test_fehlgeschlagene_mail_gilt_als_nicht_erhalten(): void {
		$this->assertSame('kein', VereinePage::link_status(['verwendungen' => 0], ['erfolgreich' => false], false));
	}

	public function test_gesendet_aber_nie_benutzt_orange(): void {
		$this->assertSame('gesendet', VereinePage::link_status(['verwendungen' => 0], ['erfolgreich' => true], false));
		$this->assertSame('gesendet', VereinePage::link_status(['verwendungen' => 0], null, false));
	}

	public function test_benutzt_gruen(): void {
		$this->assertSame('verwendet', VereinePage::link_status(['verwendungen' => 2], ['erfolgreich' => true], false));
	}

	public function test_frueher_benutzter_ersetzter_link_bleibt_gruen(): void {
		$this->assertSame('verwendet', VereinePage::link_status(['verwendungen' => 0], ['erfolgreich' => true], true));
	}
}
