<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Unit\Ergebnis;

use KSV\KMM\Domain\Ergebnis\ArchivAuswertung;
use PHPUnit\Framework\TestCase;

final class ArchivAuswertungTest extends TestCase {

	/** @return array<string, array<string, string>> */
	private static function katalog(): array {
		return [
			'1_10_10' => ['spo' => '1.10.10', 'disziplin_text' => 'Luftgewehr', 'klasse_text' => 'Herren I'],
			'1_10_11' => ['spo' => '1.10.11', 'disziplin_text' => 'Luftgewehr', 'klasse_text' => 'Damen I'],
			'6_20_10' => ['spo' => '6.20.10', 'disziplin_text' => 'Bogen Halle Recurve', 'klasse_text' => 'Herren I'],
		];
	}

	public function test_zuordnung_und_sonstige(): void {
		$a = ArchivAuswertung::auswerten(2025, [
			'e1_10_10.pdf', 'm1_10_10.pdf', 'e1_10_11.pdf', 'e7_10_14.pdf', 'Meldung_Luftdruck.zip', 'KM_09-2024-ALLGEMEIN.DF1',
		], self::katalog());
		$this->assertSame(2, $a->anzahl('e'));
		$this->assertSame(1, $a->anzahl('m'));
		$this->assertSame('km_2025/e1_10_10.pdf', $a->treffer[0]['datei']);
		$this->assertSame('Herren I', $a->treffer[0]['platz']->klasse_text);
		$this->assertCount(1, $a->ungeklaert);
		$this->assertSame('7_10_14', $a->ungeklaert[0]['schluessel']);
		$this->assertSame(['Meldung_Luftdruck.zip', 'KM_09-2024-ALLGEMEIN.DF1'], $a->sonstige);
	}

	public function test_pdf_vor_html_ab_2024_sonst_html(): void {
		$neu = ArchivAuswertung::auswerten(2024, ['e1_10_10.html', 'e1_10_10.pdf'], self::katalog());
		$this->assertSame('pdf', $neu->treffer[0]['format']);
		$this->assertSame(['e1_10_10.html'], $neu->nicht_verwendet);

		$alt = ArchivAuswertung::auswerten(2019, ['e1_10_10.html', 'e1_10_10.pdf'], self::katalog());
		$this->assertSame('html', $alt->treffer[0]['format']);
	}

	public function test_geteilte_klassen_werden_gruppen(): void {
		$a = ArchivAuswertung::auswerten(2008, ['e1_10_10A.html', 'e1_10_10B.html', 'e1_10_12A.html'], self::katalog());
		$this->assertSame(2, $a->anzahl('e'));
		$this->assertCount(2, $a->neue_katalogzeilen);
		$this->assertSame('Herren I Gruppe A', $a->neue_katalogzeilen[0]['klasse_text']);
		$this->assertSame('1.10.10A', $a->neue_katalogzeilen[0]['spo']);
		// 1_10_12 steht nicht im Katalog, also auch keine Gruppe.
		$this->assertSame('1_10_12A', $a->ungeklaert[0]['schluessel']);
	}

	public function test_sammelergebnis_ohne_klasse(): void {
		$a = ArchivAuswertung::auswerten(2025, ['e6_20.pdf'], self::katalog());
		$this->assertSame(1, $a->anzahl('e'));
		$this->assertTrue($a->treffer[0]['platz']->ist_sammel());
		$this->assertSame('Bogen Halle Recurve', $a->treffer[0]['platz']->disziplin_text);
	}
}
