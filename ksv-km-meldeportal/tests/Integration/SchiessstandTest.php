<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Integration;

use KSV\KMM\Application\RegeltabelleImporter;
use KSV\KMM\Application\SchiessstandService;
use KSV\KMM\Application\SportjahrService;
use KSV\KMM\Application\StartplanService;
use KSV\KMM\Auth\Rechte;
use KSV\KMM\Domain\Regeltabelle\Dokument;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\BuchungRepository;
use KSV\KMM\Infrastructure\Repository\EinheitRepository;
use KSV\KMM\Infrastructure\Repository\WettkampftagRepository;
use KSV\KMM\Support\Clock;

/**
 * Schießstände als Stammdaten, Stände per Ankreuzen freigeben.
 */
final class SchiessstandTest extends IntegrationTestCase {

	private int $sid;

	protected function setUp(): void {
		parent::setUp();
		Rechte::cache_leeren();
		$this->sid = (new SportjahrService())->anlegen(2027);
		remove_all_actions('kmm_regeln_geaendert');
		(new RegeltabelleImporter())->importieren($this->sid, Dokument::fromJson((string) file_get_contents(dirname(__DIR__, 2) . '/docs/regeltabelle-2026.json')));
		RegelwerkLader::cache_leeren();
	}

	public function test_schiessstand_mit_gruppen_und_freigabe_der_staende(): void {
		$s = new SchiessstandService();
		$stand = $s->speichern(0, 'Schießstand SV Vorwalsrode', 'Vorwalsrode, Am Schießstand 1');
		try {
			$s->speichern(0, '', '');
			$this->fail('ohne Bezeichnung');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('Bezeichnung', $e->getMessage());
		}
		$g10 = $s->gruppe_speichern(0, $stand, '10 m Stände', 'Stand', 12, 1, 1);
		$g25 = $s->gruppe_speichern(0, $stand, '25 m Stände', 'Stand', 5, 13, 1);
		$g50 = $s->gruppe_speichern(0, $stand, '50 m Auflage', 'Stand', 5, 18, 1, ['1.36', '1.41']);
		$bogen = $s->gruppe_speichern(0, $stand, 'Bogen', 'Scheibe', 2, 1, 4);
		$liste = $s->liste();
		$this->assertCount(1, $liste);
		$this->assertCount(4, $liste[0]['gruppen']);
		$this->assertSame(12 + 5 + 5 + 8, $liste[0]['plaetze']);

		// Wettkampftag mit Schießstand: Ort wird übernommen.
		$sp = new StartplanService($this->sid);
		$tag = $sp->tag_speichern(0, ['datum' => '2027-03-06', 'bezeichnung' => 'KM LG/LP', 'schiessstand_id' => $stand]);
		$row = (new WettkampftagRepository())->find($tag);
		$this->assertSame($stand, (int) $row['schiessstand_id']);
		$this->assertSame('Schießstand SV Vorwalsrode, Vorwalsrode, Am Schießstand 1', $row['ort']);

		// Stände 1–8 (10 m) und Scheibe 1 freigeben, dazu eine manuelle Einheit.
		$sp->einheit_speichern(0, $tag, 'Rotte manuell', 6);
		$r = $sp->einheiten_aus_schiessstand($tag, [$g10 => range(1, 8), $bogen => [1]]);
		$this->assertSame(['angelegt' => 9, 'entfernt' => 0, 'behalten' => 0], $r);
		$einheiten = (new EinheitRepository())->by_wettkampftag($tag);
		$this->assertCount(10, $einheiten);
		$namen = array_map(static fn(array $e): string => (string) $e['bezeichnung'], $einheiten);
		$this->assertContains('Stand 1', $namen);
		$this->assertContains('Stand 8', $namen);
		$this->assertContains('Scheibe 1', $namen);
		$this->assertNotContains('Stand 9', $namen);
		$scheibe = array_values(array_filter($einheiten, static fn(array $e): bool => $e['bezeichnung'] === 'Scheibe 1'))[0];
		$this->assertSame(4, (int) $scheibe['kapazitaet']);
		$auswahl = $s->auswahl($stand, $tag);
		$this->assertSame(range(1, 8), array_keys($auswahl[0]['freigegeben']));
		$this->assertSame(range(13, 17), $auswahl[1]['nummern']);

		// Erneut: 1–6 statt 1–8, zusätzlich 50-m-Stand 18 (Disziplinbeschränkung wird übernommen).
		$r = $sp->einheiten_aus_schiessstand($tag, [$g10 => range(1, 6), $g50 => [18], $bogen => [1]]);
		$this->assertSame(['angelegt' => 1, 'entfernt' => 2, 'behalten' => 7], $r);
		$einheiten = (new EinheitRepository())->by_wettkampftag($tag);
		$this->assertCount(9, $einheiten, 'manuelle Einheit bleibt');
		$s18 = array_values(array_filter($einheiten, static fn(array $e): bool => $e['bezeichnung'] === 'Stand 18'))[0];
		$rw = RegelwerkLader::engine($this->sid)->regelwerk();
		$this->assertSame([$rw->disziplin_nach_kennzahl('1.36')->id, $rw->disziplin_nach_kennzahl('1.41')->id], EinheitRepository::disziplin_ids($s18));

		// Abwählen mit Buchung ist gesperrt.
		$stand1 = array_values(array_filter($einheiten, static fn(array $e): bool => $e['bezeichnung'] === 'Stand 1'))[0];
		$dg = $sp->durchgang_speichern(0, $tag, 1, '', Clock::local_to_utc('2027-03-06 09:00'), Clock::local_to_utc('2027-03-06 10:00'));
		(new BuchungRepository())->insert(['sportjahr_id' => $this->sid, 'wettkampftag_id' => $tag, 'durchgang_id' => $dg, 'einheit_id' => (int) $stand1['id'], 'position' => 1, 'einzelmeldung_id' => 1, 'verein_id' => 1, 'gebucht_am' => Clock::now_utc()]);
		try {
			$sp->einheiten_aus_schiessstand($tag, [$g10 => [2, 3], $g50 => [18], $bogen => [1]]);
			$this->fail('Buchung');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Stand 1', $e->getMessage());
		}

		// Standgruppe mit freigegebenen Einheiten und Schießstand mit Tagen sind geschützt.
		try {
			$s->gruppe_loeschen($g10);
			$this->fail('Gruppe in Verwendung');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('freigegeben', $e->getMessage());
		}
		$s->gruppe_loeschen($g25);
		try {
			$s->loeschen($stand);
			$this->fail('Stand in Verwendung');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Wettkampftagen', $e->getMessage());
		}
		$this->assertSame(3, count($s->liste()[0]['gruppen']));
	}
}
