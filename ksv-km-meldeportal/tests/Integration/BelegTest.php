<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Integration;

use KSV\KMM\Application\BelegService;
use KSV\KMM\Application\MeldungService;
use KSV\KMM\Application\RegeltabelleImporter;
use KSV\KMM\Application\SchuetzeService;
use KSV\KMM\Application\SportjahrService;
use KSV\KMM\Application\VerarbeitungService;
use KSV\KMM\Application\VereinService;
use KSV\KMM\Auth\Rechte;
use KSV\KMM\Domain\Regeltabelle\Dokument;
use KSV\KMM\Domain\Verarbeitungsstatus;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\BelegRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;
use KSV\KMM\Support\Clock;

/**
 * Phase 2, Meilenstein 5: Buchhaltungsbelege.
 */
final class BelegTest extends IntegrationTestCase {

	private int $sid;
	/** @var array<string, mixed> */
	private array $verein;
	/** @var array<string, int> */
	private array $em = [];

	protected function setUp(): void {
		parent::setUp();
		Rechte::cache_leeren();
		$this->sid = (new SportjahrService())->anlegen(2027);
		(new SportjahrRepository())->update($this->sid, ['meldung_beginn' => '2026-01-01 00:00:00', 'meldeschluss' => '2099-01-10 22:59:00']);
		remove_all_actions('kmm_regeln_geaendert');
		(new RegeltabelleImporter())->importieren($this->sid, Dokument::fromJson((string) file_get_contents(dirname(__DIR__, 2) . '/docs/regeltabelle-2026.json')));
		RegelwerkLader::cache_leeren();
		add_filter('pre_wp_mail', static fn() => true);
		$vid = (new VereinService())->speichern(0, 'SV Test', '12345', ['sport@example.org'], true);
		(new VereinService())->speichern(0, 'SV Ohne', '12346', ['ohne@example.org'], true);
		$this->verein = (array) (new VereinRepository())->find($vid);
		$this->verein['sportjahr_id'] = $this->sid;
		$ss = new SchuetzeService($this->verein, $this->sid);
		$ms = new MeldungService($this->verein, $this->sid);
		$rw = RegelwerkLader::engine($this->sid)->regelwerk();
		$lg = $rw->disziplin_nach_kennzahl('1.10')->id;
		$ids = [];
		foreach ([['A', '1990-01-01'], ['B', '1985-01-01'], ['C', '1980-01-01']] as $i => [$n, $g]) {
			$s = $ss->speichern(0, ['nachname' => $n, 'vorname' => 'X', 'geburtsdatum' => $g, 'geschlecht' => 'm', 'mitgliedsnummer' => '12345000' . ($i + 1)])['id'];
			$this->em[ $n ] = $ms->einzel_anlegen($s, $lg)['id'];
			$ids[] = $this->em[ $n ];
			if ($n === 'A') {
				$this->em['A22'] = $ms->einzel_anlegen($s, $rw->disziplin_nach_kennzahl('1.22')->id)['id'];
			}
		}
		$ms->mannschaft_speichern(null, $lg, $ids);
		$ms->ansprechpartner_speichern(['name' => 'SL', 'email' => 'sl@example.org']);
		$ms->einreichen();
	}

	protected function tearDown(): void {
		remove_all_filters('pre_wp_mail');
		wp_set_current_user(1);
		Rechte::cache_leeren();
	}

	public function test_positionen_gruppiert_und_folgt_status_abmeldung_schalter(): void {
		$service = new BelegService();
		$p = $service->positionen($this->sid, $this->verein);
		$this->assertSame(4, $p['anzahl_einzel']);
		$this->assertSame(1, $p['anzahl_mannschaften']);
		$this->assertSame(4, $p['ungeprueft']);
		$this->assertEqualsWithDelta((new MeldungService($this->verein, $this->sid))->zusammenfassung()['startgeld']['summe'], $p['summe'], 0.001);
		// 1.10 mit drei Startern: B (1985) und C (1980) sind Herren II, A (1990) Herren I → zwei Gruppen + 1.22.
		$gruppen = array_map(static fn(array $g): string => $g['kennzahl'] . ' ' . $g['startklasse'] . ' ×' . $g['anzahl'], $p['einzel']);
		$this->assertSame(['1.10 Herren I ×1', '1.10 Herren II ×2', '1.22 Herren I ×1'], $gruppen);
		$this->assertSame(0.0, $p['mannschaften'][0]['betrag'], 'Mannschaftsstartgeld 0,00 wird gelistet');

		// Nicht startberechtigt fällt heraus, Mannschaft unvollständig.
		(new VerarbeitungService($this->sid))->setzen($this->em['B'], Verarbeitungsstatus::NICHT_STARTBERECHTIGT, 'fremder Verein');
		$p = $service->positionen($this->sid, $this->verein);
		$this->assertSame(3, $p['anzahl_einzel']);
		$this->assertSame(1, $p['nicht_startberechtigt']);
		$this->assertSame(1, $p['mannschaften'][0]['unvollstaendig']);

		// Abmeldung ohne Startgeld: eigene Zeile „abgemeldet“ mit 0,00 €.
		$admin = new MeldungService($this->verein, $this->sid, true);
		$admin->abmelden($this->em['C'], 'verletzt', false);
		$p = $service->positionen($this->sid, $this->verein);
		$abg = array_values(array_filter($p['einzel'], static fn(array $g): bool => $g['abgemeldet']));
		$this->assertCount(1, $abg);
		$this->assertSame(0.0, $abg[0]['betrag']);
		$this->assertSame(1, $p['abgemeldet']);
		$summe_ohne = $p['summe'];
		$this->assertEqualsWithDelta(14.0, $summe_ohne, 0.001, 'A in 1.10 und 1.22 je 7,00');

		// Schalter auf „berechnen“: Betrag kommt zurück.
		$admin->startgeld_schalter($this->em['C'], true);
		$p = $service->positionen($this->sid, $this->verein);
		$abg = array_values(array_filter($p['einzel'], static fn(array $g): bool => $g['abgemeldet']));
		$this->assertEqualsWithDelta(7.0, $abg[0]['betrag'], 0.001);
		$this->assertEqualsWithDelta($summe_ohne + 7.0, $p['summe'], 0.001);
	}

	public function test_erzeugen_speichert_snapshot_mit_nummer_und_hinweis(): void {
		$service = new BelegService();
		$r = $service->erzeugen($this->sid, (int) $this->verein['id']);
		$this->assertStringStartsWith('%PDF', $r['pdf']);
		$this->assertSame('2027-12345-01', $r['positionen']['belegnummer']);
		$this->assertSame('Beleg-2027-12345-01.pdf', $r['beleg']['dateiname']);
		$this->assertTrue($r['beleg']['ungeprueft_hinweis'], 'ungeprüfte Meldungen → Hinweis');
		$this->assertEqualsWithDelta(28.0, $r['beleg']['summe'], 0.001);

		// Nach Verarbeitung und Änderung: neuer Beleg mit neuer Nummer, alter Snapshot bleibt.
		(new VerarbeitungService($this->sid))->sammel_verarbeitet([]);
		(new MeldungService($this->verein, $this->sid, true))->einzel_loeschen($this->em['A22']);
		$r2 = $service->erzeugen($this->sid, (int) $this->verein['id']);
		$this->assertSame('2027-12345-02', $r2['positionen']['belegnummer']);
		$this->assertFalse($r2['beleg']['ungeprueft_hinweis']);
		$this->assertEqualsWithDelta(21.0, $r2['beleg']['summe'], 0.001);
		$alt = (new BelegRepository())->find((int) $r['beleg']['id']);
		$this->assertEqualsWithDelta(28.0, $alt['summe'], 0.001);
		$html = $service->html((array) json_decode((string) $alt['positionen'], true));
		$this->assertStringContainsString('2027-12345-01', $html);
		$this->assertStringContainsString('28,00 €', $html);
		$this->assertStringStartsWith('%PDF', $service->pdf($alt));
		$this->assertCount(2, (new BelegRepository())->where(['verein_id' => (int) $this->verein['id']]));
	}

	public function test_alle_nur_eingereichte_und_abgeschlossenes_sportjahr_sperrt(): void {
		$service = new BelegService();
		$r = $service->alle($this->sid, true);
		$this->assertSame(1, $r['anzahl'], 'SV Ohne hat keine Meldung');
		$this->assertSame(4, $r['ungeprueft']);
		$this->assertSame('Belege-KM-2027.pdf', $r['dateiname']);
		$this->assertStringStartsWith('%PDF', $r['pdf']);
		$this->assertCount(1, (new BelegRepository())->letzte_je_verein($this->sid));

		(new MeldungService($this->verein, $this->sid))->wieder_oeffnen();
		try {
			$service->alle($this->sid, true);
			$this->fail('Entwurf ohne Schalter');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Kein Verein', $e->getMessage());
		}
		$this->assertSame(1, $service->alle($this->sid, false)['anzahl']);

		(new SportjahrRepository())->update($this->sid, ['abgeschlossen_am' => Clock::now_utc()]);
		try {
			$service->erzeugen($this->sid, (int) $this->verein['id']);
			$this->fail('abgeschlossen');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('abgeschlossen', $e->getMessage());
		}
	}
}
