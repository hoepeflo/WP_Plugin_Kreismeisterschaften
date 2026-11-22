<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Unit\Sicherung;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../tools/briefkasten/Briefkasten.php';

final class BriefkastenTest extends TestCase {

	private string $ordner;

	protected function setUp(): void {
		$this->ordner = sys_get_temp_dir() . '/kmm-bk-' . bin2hex(random_bytes(4));
	}

	protected function tearDown(): void {
		foreach ((array) glob($this->ordner . '/{,.}*', GLOB_BRACE) as $f) {
			if (is_file((string) $f)) {
				unlink((string) $f);
			}
		}
		@rmdir($this->ordner);
	}

	private function bk(): \Briefkasten {
		return new \Briefkasten(['einwurf_token' => str_repeat('a', 32), 'wartung_token' => 'w', 'ordner' => $this->ordner, 'aufbewahrung_tage' => 90, 'warn_stunden' => 36]);
	}

	public function test_token(): void {
		$bk = $this->bk();
		$this->assertTrue($bk->berechtigt('Bearer ' . str_repeat('a', 32)));
		$this->assertFalse($bk->berechtigt('Bearer falsch'));
		$this->assertFalse($bk->berechtigt(str_repeat('a', 32)));
		$this->expectException(\RuntimeException::class);
		new \Briefkasten(['einwurf_token' => 'kurz']);
	}

	public function test_einwurf_nimmt_nur_neue_sicherungen(): void {
		$bk = $this->bk();
		$name = 'kmm-sicherung-20261122-030000-taeglich.kmmsic';
		[$s] = $bk->einwurf($name, "KMMSIC1\nabc");
		$this->assertSame(200, $s);
		[$s] = $bk->einwurf($name, "KMMSIC1\nabc");
		$this->assertSame(200, $s, 'gleicher Inhalt erneut = in Ordnung');
		[$s] = $bk->einwurf($name, "KMMSIC1\nanders");
		$this->assertSame(409, $s, 'nie überschreiben');
		$this->assertSame("KMMSIC1\nabc", file_get_contents($this->ordner . '/' . $name));
		$this->assertSame(400, $bk->einwurf('../x.kmmsic', "KMMSIC1\n")[0]);
		$this->assertSame(400, $bk->einwurf('kmm-sicherung-20261122-030000-x.kmmsic', '{"klartext":1}')[0]);
		$this->assertCount(1, $bk->liste());
	}

	public function test_wartung_loescht_nach_frist(): void {
		$bk = $this->bk();
		$bk->einwurf('kmm-sicherung-20260801-030000-taeglich.kmmsic', "KMMSIC1\nalt");
		$bk->einwurf('kmm-sicherung-20261122-030000-taeglich.kmmsic', "KMMSIC1\nneu");
		touch($this->ordner . '/kmm-sicherung-20260801-030000-taeglich.kmmsic', time() - 91 * 86400);
		$r = $bk->wartung();
		$this->assertSame(1, $r['geloescht']);
		$this->assertSame(['kmm-sicherung-20261122-030000-taeglich.kmmsic'], array_column($bk->liste(), 'name'));
	}

	/** @param array<string, mixed> $z */
	private function zustand(array $z): void {
		file_put_contents($this->ordner . '/zustand.json', (string) json_encode($z));
	}

	public function test_warnungen(): void {
		$bk = $this->bk();
		$bk->ordner();
		$t = 1_800_000_000;
		$h = 3600;
		$this->assertSame('', $bk->warnung($t, 0), 'ohne ersten Puls keine Warnung');

		$this->zustand(['letzter_puls' => $t, 'im_zeitraum' => true, 'zeitraum_seit' => $t]);
		$this->assertSame('', $bk->warnung($t + $h, 0), 'Zeitraum hat gerade erst begonnen');

		$this->zustand(['letzter_puls' => $t + 37 * $h, 'im_zeitraum' => true, 'zeitraum_seit' => $t]);
		$this->assertStringContainsString('noch keine Sicherung', $bk->warnung($t + 37 * $h, 0));
		$this->assertStringContainsString('keine neue Sicherung', $bk->warnung($t + 37 * $h, $t + 10));
		$this->assertSame('', $bk->warnung($t + 37 * $h, $t + 30 * $h));

		$this->zustand(['letzter_puls' => $t, 'im_zeitraum' => true, 'zeitraum_seit' => $t]);
		$this->assertStringContainsString('nicht mehr gemeldet', $bk->warnung($t + 37 * $h, $t));

		$this->zustand(['letzter_puls' => $t + 37 * $h, 'im_zeitraum' => false]);
		$this->assertSame('', $bk->warnung($t + 37 * $h, 0), 'außerhalb des Zeitraums keine Sicherung nötig');
	}

	public function test_puls_merkt_beginn_des_zeitraums(): void {
		$bk = $this->bk();
		$bk->puls('{"im_zeitraum":true}');
		$seit = (int) $bk->zustand()['zeitraum_seit'];
		$this->assertGreaterThan(0, $seit);
		$bk->puls('{"im_zeitraum":true}');
		$this->assertSame($seit, (int) $bk->zustand()['zeitraum_seit']);
	}
}
