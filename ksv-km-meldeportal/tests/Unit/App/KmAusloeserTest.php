<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Unit\App;

use KSV\KMM\Application\App\KmAusloeser;
use PHPUnit\Framework\TestCase;

final class KmAusloeserTest extends TestCase {

	/**
	 * @param array<string, mixed> $abweichend
	 * @return array<string, mixed>
	 */
	private static function sj(array $abweichend = []): array {
		return $abweichend + [
			'id'                   => 3,
			'ist_aktiv'            => true,
			'meldung_beginn'       => '2026-11-01 00:00:00',
			'meldeschluss'         => '2027-01-10 22:59:00',
			'erinnerung_am'        => '2027-01-03 17:00:00',
			'abgeschlossen_am'     => null,
			'push_meldebeginn'     => true,
			'push_erinnerung'      => true,
			'app_meldung_offen_am' => null,
			'app_erinnerung_am'    => null,
		];
	}

	public function test_meldebeginn_erreicht(): void {
		$this->assertFalse(KmAusloeser::meldung_offen(self::sj(), '2026-10-31 23:59:59'));
		$this->assertTrue(KmAusloeser::meldung_offen(self::sj(), '2026-11-01 00:00:00'));
		$this->assertTrue(KmAusloeser::meldung_offen(self::sj(), '2026-11-01 23:00:00'));
	}

	public function test_meldebeginn_nur_einmal_und_mit_schalter(): void {
		$this->assertFalse(KmAusloeser::meldung_offen(self::sj(['app_meldung_offen_am' => '2026-11-01 00:05:00']), '2026-11-01 01:00:00'));
		$this->assertFalse(KmAusloeser::meldung_offen(self::sj(['push_meldebeginn' => false]), '2026-11-01 01:00:00'));
	}

	public function test_kein_nachholen_nach_karenz(): void {
		// Beim ersten Einspielen liegt der Meldebeginn lange zurück: keine Überraschungs-Push.
		$this->assertTrue(KmAusloeser::meldung_offen(self::sj(), '2026-11-02 00:00:00'));
		$this->assertFalse(KmAusloeser::meldung_offen(self::sj(), '2026-11-02 00:00:01'));
		$this->assertFalse(KmAusloeser::erinnerung(self::sj(), '2027-01-04 17:00:01'));
	}

	public function test_nur_aktives_offenes_sportjahr(): void {
		$jetzt = '2026-11-01 01:00:00';
		$this->assertFalse(KmAusloeser::meldung_offen(self::sj(['ist_aktiv' => false]), $jetzt));
		$this->assertFalse(KmAusloeser::meldung_offen(self::sj(['abgeschlossen_am' => '2026-10-01 00:00:00']), $jetzt));
		$this->assertFalse(KmAusloeser::meldung_offen(self::sj(['meldeschluss' => null]), $jetzt));
		$this->assertFalse(KmAusloeser::meldung_offen(self::sj(['meldung_beginn' => null]), $jetzt));
		$this->assertFalse(KmAusloeser::meldung_offen(self::sj(['meldeschluss' => '2026-11-01 00:30:00']), $jetzt));
	}

	public function test_fehlende_spalten_gelten_als_aus(): void {
		$sj = self::sj();
		unset($sj['push_meldebeginn'], $sj['app_erinnerung_am']);
		$this->assertFalse(KmAusloeser::meldung_offen($sj, '2026-11-01 01:00:00'));
		$this->assertFalse(KmAusloeser::erinnerung($sj, '2027-01-03 18:00:00'));
	}

	public function test_erinnerung(): void {
		$this->assertFalse(KmAusloeser::erinnerung(self::sj(), '2027-01-03 16:59:59'));
		$this->assertTrue(KmAusloeser::erinnerung(self::sj(), '2027-01-03 17:00:00'));
		$this->assertFalse(KmAusloeser::erinnerung(self::sj(['app_erinnerung_am' => '2027-01-03 17:00:00']), '2027-01-03 18:00:00'));
		$this->assertFalse(KmAusloeser::erinnerung(self::sj(['push_erinnerung' => false]), '2027-01-03 18:00:00'));
	}

	public function test_ohne_erinnerungszeitpunkt_keine_erinnerung(): void {
		$this->assertFalse(KmAusloeser::erinnerung(self::sj(['erinnerung_am' => null]), '2027-01-03 18:00:00'));
	}

	public function test_erinnerung_nicht_nach_meldeschluss_und_nicht_vor_beginn(): void {
		$this->assertFalse(KmAusloeser::erinnerung(self::sj(['erinnerung_am' => '2027-01-10 22:00:00']), '2027-01-10 23:00:00'));
		$this->assertFalse(KmAusloeser::erinnerung(self::sj(['erinnerung_am' => '2026-10-30 00:00:00']), '2026-10-30 01:00:00'));
	}

	/**
	 * @param array<string, mixed> $abweichend
	 * @return array<string, mixed>
	 */
	private static function tag(array $abweichend = []): array {
		return $abweichend + ['id' => 17, 'sportjahr_id' => 3, 'ausgeblendet_am' => null, 'app_startplaetze_am' => null, 'app_startplan_am' => null];
	}

	public function test_wettkampftag(): void {
		$this->assertTrue(KmAusloeser::wettkampftag(self::tag(), self::sj(), 'app_startplaetze_am', true));
		$this->assertFalse(KmAusloeser::wettkampftag(self::tag(), self::sj(), 'app_startplaetze_am', false), 'Häkchen abgewählt');
		$this->assertFalse(KmAusloeser::wettkampftag(self::tag(['app_startplaetze_am' => '2027-01-20 10:00:00']), self::sj(), 'app_startplaetze_am', true), 'schon ausgelöst');
		$this->assertTrue(KmAusloeser::wettkampftag(self::tag(['app_startplaetze_am' => '2027-01-20 10:00:00']), self::sj(), 'app_startplan_am', true));
		$this->assertFalse(KmAusloeser::wettkampftag(self::tag(), self::sj(['ist_aktiv' => false]), 'app_startplan_am', true), 'nicht aktives Sportjahr');
		$this->assertFalse(KmAusloeser::wettkampftag(self::tag(), null, 'app_startplan_am', true));
		$this->assertFalse(KmAusloeser::wettkampftag(self::tag(['sportjahr_id' => 4]), self::sj(), 'app_startplan_am', true));
		$ohne = self::tag();
		unset($ohne['app_startplan_am']);
		$this->assertFalse(KmAusloeser::wettkampftag($ohne, self::sj(), 'app_startplan_am', true), 'Schema nicht migriert');
	}

	public function test_relevante_aenderungen(): void {
		foreach (['sportjahr.speichern', 'sportjahr.aktivieren', 'sportjahr.abschliessen', 'wettkampftag.aendern', 'wettkampftag.freigeben', 'wettkampftag.veroeffentlichung_zurueck', 'schiessstand.aendern'] as $a) {
			$this->assertTrue(KmAusloeser::aenderung_relevant($a), $a);
		}
		foreach (['app.startplan', 'app.meldung_offen', 'buchung.buchen', 'wettkampftag.einheiten', 'wettkampftag.erinnerung', 'sportjahr.anonymisieren', 'erinnerung.senden'] as $a) {
			$this->assertFalse(KmAusloeser::aenderung_relevant($a), $a);
		}
	}
}
