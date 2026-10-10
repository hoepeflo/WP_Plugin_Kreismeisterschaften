<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Unit\App;

use KSV\KMM\Application\App\KmEreignis;
use PHPUnit\Framework\TestCase;

/**
 * Ereignisse nach API-Vertrag 2 und 3.3 (Repo APP_KSV-Fallingbostel, docs/API-VERTRAG.md).
 */
final class KmEreignisTest extends TestCase {

	private const SCHLUESSEL = ['schluessel', 'quelle', 'push', 'thema', 'titel', 'text', 'ziel', 'abgleich', 'daten'];

	private static function tz(): \DateTimeZone {
		return new \DateTimeZone('Europe/Berlin');
	}

	/** @return array<string, mixed> */
	private static function sportjahr(): array {
		// 10.01.2027 23:59 Berlin = 22:59 UTC
		return ['id' => 3, 'jahr' => 2027, 'meldeschluss' => '2027-01-10 22:59:00'];
	}

	public function test_meldung_offen_nach_vertrag(): void {
		$e = KmEreignis::meldung_offen(self::sportjahr(), self::tz());
		$this->assertSame(self::SCHLUESSEL, array_keys($e));
		$this->assertSame('kmm.meldung_offen.3', $e['schluessel']);
		$this->assertSame('ksv-km-meldeportal', $e['quelle']);
		$this->assertTrue($e['push']);
		$this->assertSame('km_meldung', $e['thema']);
		$this->assertSame('Meldeportal geöffnet', $e['titel']);
		$this->assertSame('Die Meldung zur Kreismeisterschaft 2027 ist geöffnet. Meldeschluss: 10.01.2027.', $e['text']);
		$this->assertSame('km', $e['ziel']);
		$this->assertSame(['km'], $e['abgleich']);
	}

	public function test_erinnerung_nach_vertrag(): void {
		$e = KmEreignis::erinnerung(self::sportjahr(), self::tz());
		$this->assertSame('kmm.erinnerung.3', $e['schluessel']);
		$this->assertSame('km_erinnerung', $e['thema']);
		$this->assertSame('Meldeschluss naht', $e['titel']);
		$this->assertSame('Meldeschluss für die Kreismeisterschaft 2027 ist am 10.01.2027.', $e['text']);
	}

	public function test_datum_in_ortszeit(): void {
		// 31.12.2026 23:30 UTC ist in Berlin schon der 01.01.2027.
		$this->assertSame('01.01.2027', KmEreignis::datum_utc('2026-12-31 23:30:00', self::tz()));
		$this->assertSame('', KmEreignis::datum_utc(null, self::tz()));
	}

	public function test_startplaetze_mit_kurzer_bezeichnung(): void {
		$e = KmEreignis::startplaetze(['id' => 17, 'datum' => '2027-02-20', 'bezeichnung' => 'Luftdruck']);
		$this->assertSame('kmm.startplaetze.17', $e['schluessel']);
		$this->assertSame('km_startplaetze', $e['thema']);
		$this->assertSame('Startplätze buchbar', $e['titel']);
		$this->assertSame('Für den Wettkampftag „Luftdruck“ am 20.02.2027 können jetzt Startplätze gebucht werden.', $e['text']);
		$this->assertSame(['wettkampftag_id' => 17, 'datum' => '2027-02-20'], $e['daten']);
	}

	public function test_startplaetze_ohne_oder_mit_langer_bezeichnung(): void {
		$text = 'Für den Wettkampftag am 20.02.2027 können jetzt Startplätze gebucht werden.';
		$this->assertSame($text, KmEreignis::startplaetze(['id' => 17, 'datum' => '2027-02-20', 'bezeichnung' => ''])['text']);
		$lang = str_repeat('Kreismeisterschaft ', 5);
		$this->assertSame($text, KmEreignis::startplaetze(['id' => 17, 'datum' => '2027-02-20', 'bezeichnung' => $lang])['text']);
	}

	public function test_startplan_nach_vertrag(): void {
		$e = KmEreignis::startplan(['id' => 17, 'datum' => '2027-02-20', 'bezeichnung' => 'Bogen']);
		$this->assertSame('kmm.startplan.17', $e['schluessel']);
		$this->assertSame('km_startplan', $e['thema']);
		$this->assertSame('Startplan veröffentlicht', $e['titel']);
		$this->assertSame('Der Startplan für den Wettkampftag „Bogen“ am 20.02.2027 ist veröffentlicht.', $e['text']);
		$this->assertSame('km', $e['ziel']);
	}

	public function test_geaendert_ohne_push(): void {
		$e = KmEreignis::geaendert(1790000000);
		$this->assertSame(self::SCHLUESSEL, array_keys($e));
		$this->assertSame('kmm.geaendert.1790000000', $e['schluessel']);
		$this->assertFalse($e['push']);
		$this->assertNull($e['thema']);
		$this->assertSame(['km'], $e['abgleich']);
	}

	public function test_laengen_grenzen(): void {
		foreach ([
			KmEreignis::meldung_offen(self::sportjahr(), self::tz()),
			KmEreignis::erinnerung(self::sportjahr(), self::tz()),
			KmEreignis::startplaetze(['id' => 1, 'datum' => '2027-02-20', 'bezeichnung' => str_repeat('x', 40)]),
			KmEreignis::startplan(['id' => 1, 'datum' => '2027-02-20', 'bezeichnung' => str_repeat('ä', 40)]),
		] as $e) {
			$this->assertLessThanOrEqual(65, mb_strlen($e['titel']));
			$this->assertLessThanOrEqual(240, mb_strlen($e['text']));
			$this->assertLessThanOrEqual(190, strlen($e['schluessel']));
		}
		$this->assertSame(5, mb_strlen(KmEreignis::kuerzen('abcdefgh', 5)));
		$this->assertStringEndsWith('…', KmEreignis::kuerzen('abcdefgh', 5));
	}
}
