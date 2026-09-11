<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Unit\Support;

use KSV\KMM\Support\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsDefaultsTest extends TestCase {

	public function test_open_points_from_concept_are_settings(): void {
		$d = Settings::defaults();
		foreach (['nicht_meldung_sichtbar', 'fitasc_damen_ab_56', 'csv_trennzeichen', 'csv_zeichensatz', 'csv_ganze_ringe_format', 'csv_verband_modus', 'schuetzen_loeschfrist_jahre', 'tarif_override_ignorieren'] as $key) {
			$this->assertArrayHasKey($key, $d);
		}
		$this->assertSame('km-meldung', $d['route_slug']);
	}
}
