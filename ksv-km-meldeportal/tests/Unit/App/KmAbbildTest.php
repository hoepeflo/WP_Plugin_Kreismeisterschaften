<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Unit\App;

use KSV\KMM\Application\App\KmAbbild;
use PHPUnit\Framework\TestCase;

/**
 * Antwort „Kreismeisterschaft“ von GET ksv-app/v1/km (API-Vertrag 3.3).
 */
final class KmAbbildTest extends TestCase {

	private const LINKS = ['meldeportal' => 'https://example.org/km-meldung/', 'startplaene' => null];

	private static function tz(): \DateTimeZone {
		return new \DateTimeZone('Europe/Berlin');
	}

	/** @return array<string, mixed> */
	private static function sportjahr(): array {
		return [
			'id' => 3, 'jahr' => 2027, 'bezeichnung' => 'Kreismeisterschaft 2027', 'ist_aktiv' => true,
			'meldung_beginn' => '2026-10-31 23:00:00', 'meldeschluss' => '2027-01-10 22:59:00', 'erinnerung_am' => '2027-01-03 17:00:00',
			'erinnerung_gesendet_am' => null, 'abgeschlossen_am' => null, 'anonymisiert_am' => null, 'abschluss_backup' => '/geheim/pfad.json',
			'created_at' => '2026-09-01 10:00:00', 'updated_at' => '2026-09-02 10:00:00',
		];
	}

	/**
	 * @param array<string, mixed> $abweichend
	 * @return array<string, mixed>
	 */
	private static function tag(array $abweichend = []): array {
		return $abweichend + [
			'id' => 17, 'sportjahr_id' => 3, 'datum' => '2027-02-20', 'bezeichnung' => 'Luftdruck', 'ort' => 'Schützenhaus Adolphsheide-Vierde',
			'buchungsfrist' => '2027-02-10 22:59:00', 'status' => 'freigegeben', 'ausgeblendet_am' => null, 'beitrag_id' => null,
			'schiessstand_id' => null, 'hinweis' => 'Bitte pünktlich', 'updated_at' => '2026-09-03 10:00:00',
		];
	}

	public function test_form_nach_vertrag(): void {
		$d = KmAbbild::bauen(self::sportjahr(), 'offen', [self::tag()], [], [], self::LINKS, self::tz());
		$this->assertSame(['sportjahr', 'wettkampftage', 'links', 'updated_at'], array_keys($d));
		$this->assertSame([
			'id' => 3, 'jahr' => 2027, 'bezeichnung' => 'Kreismeisterschaft 2027',
			'meldung_beginn' => '2026-11-01T00:00:00+01:00',
			'meldeschluss' => '2027-01-10T23:59:00+01:00',
			'erinnerung_am' => '2027-01-03T18:00:00+01:00',
			'phase' => 'offen',
			'updated_at' => '2026-09-02T12:00:00+02:00',
		], $d['sportjahr']);
		$this->assertSame([[
			'id' => 17, 'datum' => '2027-02-20', 'bezeichnung' => 'Luftdruck', 'ort' => 'Schützenhaus Adolphsheide-Vierde',
			'status' => 'buchbar', 'buchungsfrist' => '2027-02-10T23:59:00+01:00', 'hinweis' => 'Bitte pünktlich',
			'startplan_url' => null, 'updated_at' => '2026-09-03T12:00:00+02:00',
		]], $d['wettkampftage']);
		$this->assertSame(self::LINKS, $d['links']);
		$this->assertSame('2026-09-03T12:00:00+02:00', $d['updated_at']);
		$json = (string) json_encode($d);
		$this->assertStringNotContainsString('abschluss_backup', $json, 'keine internen Felder');
		$this->assertStringNotContainsString('geheim', $json);
	}

	public function test_ohne_aktives_sportjahr(): void {
		$d = KmAbbild::bauen(null, 'geschlossen', [], [], [], self::LINKS, self::tz());
		$this->assertNull($d['sportjahr']);
		$this->assertSame([], $d['wettkampftage']);
		$this->assertNull($d['updated_at']);
	}

	public function test_phase_und_status(): void {
		$this->assertSame('vor_beginn', KmAbbild::phase('vor_beginn'));
		$this->assertSame('offen', KmAbbild::phase('offen'));
		$this->assertSame('geschlossen', KmAbbild::phase('geschlossen'));
		$this->assertSame('geschlossen', KmAbbild::phase('abgeschlossen'));
		$this->assertSame('geplant', KmAbbild::status('entwurf'));
		$this->assertSame('buchbar', KmAbbild::status('freigegeben'));
		$this->assertSame('veroeffentlicht', KmAbbild::status('veroeffentlicht'));
		$this->assertNull(KmAbbild::status('unbekannt'));
	}

	public function test_ausgeblendete_tage_fehlen(): void {
		$d = KmAbbild::bauen(self::sportjahr(), 'offen', [self::tag(['ausgeblendet_am' => '2027-03-01 00:00:00']), self::tag(['id' => 18])], [], [], self::LINKS, self::tz());
		$this->assertSame([18], array_column($d['wettkampftage'], 'id'));
	}

	public function test_startplan_url_nur_wenn_veroeffentlicht(): void {
		$urls = [17 => 'https://example.org/startplan-17/', 18 => 'https://example.org/startplan-18/'];
		$tage = [self::tag(['status' => 'veroeffentlicht']), self::tag(['id' => 18, 'status' => 'freigegeben']), self::tag(['id' => 19, 'status' => 'veroeffentlicht'])];
		$d = KmAbbild::bauen(self::sportjahr(), 'geschlossen', $tage, [], $urls, self::LINKS, self::tz());
		$this->assertSame(['https://example.org/startplan-17/', null, null], array_column($d['wettkampftage'], 'startplan_url'));
		$this->assertSame('geschlossen', $d['sportjahr']['phase']);
	}

	public function test_ort_aus_schiessstand(): void {
		$stand = ['id' => 5, 'bezeichnung' => 'Schützenhaus', 'ort' => 'Walsrode', 'updated_at' => '2026-09-05 10:00:00'];
		$d = KmAbbild::bauen(self::sportjahr(), 'offen', [self::tag(['ort' => '', 'schiessstand_id' => 5])], [5 => $stand], [], self::LINKS, self::tz());
		$this->assertSame('Schützenhaus, Walsrode', $d['wettkampftage'][0]['ort']);
		$this->assertSame('2026-09-05T12:00:00+02:00', $d['updated_at'], 'Änderung am Schießstand zählt mit');
		$this->assertSame('Halle 2', KmAbbild::ort(['ort' => 'Halle 2'], $stand));
		$this->assertSame('', KmAbbild::ort(['ort' => ''], null));
	}

	public function test_leere_zeiten_sind_null(): void {
		$d = KmAbbild::bauen(self::sportjahr(), 'offen', [self::tag(['buchungsfrist' => null, 'hinweis' => null])], [], [], self::LINKS, self::tz());
		$this->assertNull($d['wettkampftage'][0]['buchungsfrist']);
		$this->assertSame('', $d['wettkampftage'][0]['hinweis']);
		$this->assertNull(KmAbbild::iso('', self::tz()));
		$this->assertNull(KmAbbild::iso(null, self::tz()));
	}

	public function test_etag(): void {
		$a = KmAbbild::etag(['x' => 1]);
		$this->assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $a);
		$this->assertNotSame($a, KmAbbild::etag(['x' => 2]));
		$this->assertTrue(KmAbbild::etag_passt($a, $a));
		$this->assertTrue(KmAbbild::etag_passt('"anders", W/' . $a, $a));
		$this->assertTrue(KmAbbild::etag_passt(substr($a, 0, -1) . '-gzip"', $a));
		$this->assertTrue(KmAbbild::etag_passt('*', $a));
		$this->assertFalse(KmAbbild::etag_passt('', $a));
		$this->assertFalse(KmAbbild::etag_passt('"anders"', $a));
	}
}
