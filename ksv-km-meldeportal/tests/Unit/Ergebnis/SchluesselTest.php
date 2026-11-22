<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Unit\Ergebnis;

use KSV\KMM\Domain\Ergebnis\Schluessel;
use PHPUnit\Framework\TestCase;

final class SchluesselTest extends TestCase {

	/**
	 * @return array<string, array{string, ?int, string}>
	 */
	public static function kennzahlen(): array {
		return [
			'Luftgewehr Herren I'  => ['1.10', 10, '1_10_10'],
			'Blasrohr'             => ['12.10', 20, '12_10_20'],
			'Leerzeichen-Zusatz G' => ['1.58 G', 10, '1_58G_10'],
			'Zusatz ohne Leerzeichen' => ['1.56S', 12, '1_56S_12'],
			'führende Null'        => ['2.03 F', 10, '2_03F_10'],
			'Sammelergebnis Bogen' => ['6.20', null, '6_20'],
		];
	}

	/**
	 * @dataProvider kennzahlen
	 */
	public function test_aus_kennzahl(string $kennzahl, ?int $nummer, string $erwartet): void {
		$this->assertSame($erwartet, Schluessel::aus_kennzahl($kennzahl, $nummer));
	}

	public function test_ungueltige_kennzahl(): void {
		$this->expectException(\InvalidArgumentException::class);
		Schluessel::aus_kennzahl('Luftgewehr');
	}

	/**
	 * @return array<string, array{string, ?array{art: string, schluessel: string, endung: string}}>
	 */
	public static function dateinamen(): array {
		return [
			'bekanntes Schema'      => ['e1_10_10.pdf', ['art' => 'e', 'schluessel' => '1_10_10', 'endung' => 'pdf']],
			'Florians Schreibweise' => ['E1-10-10.pdf', ['art' => 'e', 'schluessel' => '1_10_10', 'endung' => 'pdf']],
			'Mannschaft groß'       => ['M1-10-10.PDF', ['art' => 'm', 'schluessel' => '1_10_10', 'endung' => 'pdf']],
			'Trenner nach Art'      => ['E-1-10-10.pdf', ['art' => 'e', 'schluessel' => '1_10_10', 'endung' => 'pdf']],
			'Zusatzbuchstabe'       => ['e1_58g_10.pdf', ['art' => 'e', 'schluessel' => '1_58G_10', 'endung' => 'pdf']],
			'geteilte Klasse'       => ['e1_10_10A.html', ['art' => 'e', 'schluessel' => '1_10_10A', 'endung' => 'html']],
			'htm'                   => ['m2_17_21.htm', ['art' => 'm', 'schluessel' => '2_17_21', 'endung' => 'html']],
			'Sammelergebnis'        => ['E6-20.pdf', ['art' => 'e', 'schluessel' => '6_20', 'endung' => 'pdf']],
			'mit Pfad'              => ['km_2025/e1_10_10.pdf', ['art' => 'e', 'schluessel' => '1_10_10', 'endung' => 'pdf']],
			'Ausschreibung'         => ['AusschreibungKreismeisterschaft2019.pdf', null],
			'Gesamt-PDF'            => ['2019-Gesamt.pdf', null],
			'falsche Endung'        => ['e1_10_10.xls', null],
			'nur Kennzahl'          => ['e1.pdf', null],
			'zu viele Teile'        => ['e1_10_10_10.pdf', null],
		];
	}

	/**
	 * @dataProvider dateinamen
	 * @param array{art: string, schluessel: string, endung: string}|null $erwartet
	 */
	public function test_aus_dateiname(string $name, ?array $erwartet): void {
		$this->assertSame($erwartet, Schluessel::aus_dateiname($name));
	}

	public function test_hilfen(): void {
		$this->assertSame('e1_10_10.pdf', Schluessel::dateiname('e', '1_10_10'));
		$this->assertSame('km_2027', Schluessel::jahresordner(2027));
		$this->assertSame('1_10', Schluessel::disziplin_teil('1_10_10'));
		$this->assertSame('6_20', Schluessel::disziplin_teil('6_20'));
		$this->assertTrue(Schluessel::ist_sammel('6_20'));
		$this->assertFalse(Schluessel::ist_sammel('6_20_10'));
		$this->assertSame(12, Schluessel::kategorie('12_10_20'));
		$this->assertLessThan(0, strcmp(Schluessel::sortierung('1_2_10'), Schluessel::sortierung('1_10_10')));
		$this->assertLessThan(0, strcmp(Schluessel::sortierung('1_10_10'), Schluessel::sortierung('1_10_10A')));
	}

	public function test_falsche_art(): void {
		$this->expectException(\InvalidArgumentException::class);
		Schluessel::dateiname('x', '1_10_10');
	}

	public function test_ungueltiges_jahr(): void {
		$this->expectException(\InvalidArgumentException::class);
		Schluessel::jahresordner(27);
	}
}
