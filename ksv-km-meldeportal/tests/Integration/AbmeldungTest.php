<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Integration;

use KSV\KMM\Application\ExportService;
use KSV\KMM\Application\MeldungService;
use KSV\KMM\Application\RegeltabelleImporter;
use KSV\KMM\Application\SchuetzeService;
use KSV\KMM\Application\SportjahrService;
use KSV\KMM\Application\VereinService;
use KSV\KMM\Auth\Rechte;
use KSV\KMM\Domain\AenderungTyp;
use KSV\KMM\Domain\Regeltabelle\Dokument;
use KSV\KMM\Domain\WettkampftagStatus;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\AenderungRepository;
use KSV\KMM\Infrastructure\Repository\BuchungRepository;
use KSV\KMM\Infrastructure\Repository\DurchgangRepository;
use KSV\KMM\Infrastructure\Repository\DurchgangZulassungRepository;
use KSV\KMM\Infrastructure\Repository\EinheitRepository;
use KSV\KMM\Infrastructure\Repository\EinzelmeldungRepository;
use KSV\KMM\Infrastructure\Repository\ExportRepository;
use KSV\KMM\Infrastructure\Repository\MannschaftRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;
use KSV\KMM\Infrastructure\Repository\WettkampftagRepository;
use KSV\KMM\Support\Clock;

/**
 * Phase 2, Meilenstein 3: Änderungen und Abmeldungen nach Meldeschluss.
 */
final class AbmeldungTest extends IntegrationTestCase {

	private int $sid;
	/** @var array<string, mixed> */
	private array $verein;
	/** @var array<string, int> */
	private array $em = [];
	/** @var array<string, int> */
	private array $schuetze = [];
	private int $lg;

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
		$this->verein = (array) (new VereinRepository())->find($vid);
		$this->verein['sportjahr_id'] = $this->sid;
		$ss = new SchuetzeService($this->verein, $this->sid);
		$ms = new MeldungService($this->verein, $this->sid);
		$rw = RegelwerkLader::engine($this->sid)->regelwerk();
		$this->lg = $rw->disziplin_nach_kennzahl('1.10')->id;
		$ids = [];
		foreach ([['A', '1990-01-01'], ['B', '1985-01-01'], ['C', '1980-01-01'], ['D', '1982-01-01']] as $i => [$n, $g]) {
			$this->schuetze[ $n ] = $ss->speichern(0, ['nachname' => $n, 'vorname' => 'X', 'geburtsdatum' => $g, 'geschlecht' => 'm', 'mitgliedsnummer' => '12345000' . ($i + 1)])['id'];
			if ($n !== 'D') {
				$this->em[ $n ] = $ms->einzel_anlegen($this->schuetze[ $n ], $this->lg)['id'];
				$ids[] = $this->em[ $n ];
			}
		}
		$ms->mannschaft_speichern(null, $this->lg, $ids);
		$ms->ansprechpartner_speichern(['name' => 'SL', 'email' => 'sl@example.org']);
		$ms->einreichen();
		// Meldeschluss ist vorbei.
		(new SportjahrRepository())->update($this->sid, ['meldeschluss' => '2026-01-10 22:59:00']);
	}

	protected function tearDown(): void {
		remove_all_filters('pre_wp_mail');
		wp_set_current_user(1);
		Rechte::cache_leeren();
	}

	private function admin(): MeldungService {
		return new MeldungService($this->verein, $this->sid, true);
	}

	private function club(): MeldungService {
		return new MeldungService($this->verein, $this->sid);
	}

	/**
	 * Veröffentlichter Wettkampftag mit einem Durchgang, der die Disziplin zulässt.
	 *
	 * @return array{tag: int, durchgang: int, einheit: int}
	 */
	private function veroeffentlichter_wettkampftag(int $disziplin_id): array {
		$jetzt = Clock::now_utc();
		$tag = (new WettkampftagRepository())->insert(['sportjahr_id' => $this->sid, 'datum' => '2027-03-06', 'bezeichnung' => 'KM LG', 'status' => WettkampftagStatus::VEROEFFENTLICHT, 'freigegeben_am' => $jetzt, 'veroeffentlicht_am' => $jetzt, 'created_at' => $jetzt, 'updated_at' => $jetzt]);
		$einheit = (new EinheitRepository())->insert(['wettkampftag_id' => $tag, 'bezeichnung' => 'Stand 1', 'kapazitaet' => 1, 'disziplin_ids' => (string) $disziplin_id]);
		$dg = (new DurchgangRepository())->insert(['wettkampftag_id' => $tag, 'nummer' => 1, 'beginn' => '2027-03-06 09:00:00', 'ende' => '2027-03-06 10:30:00']);
		(new DurchgangZulassungRepository())->insert(['durchgang_id' => $dg, 'disziplin_id' => $disziplin_id, 'startklasse_id' => null]);
		return ['tag' => $tag, 'durchgang' => $dg, 'einheit' => $einheit];
	}

	public function test_verein_gesperrt_admin_nachmeldung_wird_erfasst(): void {
		$club = $this->club();
		$this->assertFalse($club->phase()['schreibbar']);
		try {
			$club->einzel_anlegen($this->schuetze['D'], $this->lg);
			$this->fail('Verein darf nach Meldeschluss nicht melden');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Meldeschluss', $e->getMessage());
		}
		try {
			$club->abmelden($this->em['A']);
			$this->fail('Abmeldung nur im Backend');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Backend', $e->getMessage());
		}

		$admin = $this->admin();
		$this->assertTrue($admin->ist_admin_modus());
		$neu = $admin->einzel_anlegen($this->schuetze['D'], $this->lg);
		$this->assertTrue($neu['nachgemeldet']);
		$this->assertTrue((bool) (new EinzelmeldungRepository())->find($neu['id'])['nachgemeldet']);
		$offen = (new AenderungRepository())->offen_je_verein((int) $this->verein['id']);
		$this->assertCount(1, $offen);
		$this->assertSame(AenderungTyp::NACHMELDUNG, $offen[0]['typ']);
		$this->assertStringContainsString('D, X', (string) $offen[0]['text']);

		// Korrektur (Meldeergebnis) wird ebenfalls erfasst; Status der Meldung bleibt eingereicht.
		$admin->einzel_aendern($neu['id'], ['meldeergebnis' => '380']);
		$this->assertCount(2, (new AenderungRepository())->offen_je_verein((int) $this->verein['id']));
		$this->assertSame('eingereicht', $admin->zusammenfassung()['status']);
	}

	public function test_abmeldung_vor_veroeffentlichung_ohne_startgeld_platz_frei_mannschaft_unvollstaendig(): void {
		$admin = $this->admin();
		$vorher = $admin->zusammenfassung()['startgeld']['einzel'];
		$tag = $this->veroeffentlichter_wettkampftag(RegelwerkLader::engine($this->sid)->regelwerk()->disziplin_nach_kennzahl('1.22')->id); // andere Disziplin: für 1.10 nichts veröffentlicht
		(new BuchungRepository())->insert(['sportjahr_id' => $this->sid, 'wettkampftag_id' => $tag['tag'], 'durchgang_id' => $tag['durchgang'], 'einheit_id' => $tag['einheit'], 'position' => 1, 'einzelmeldung_id' => $this->em['B'], 'verein_id' => (int) $this->verein['id'], 'gebucht_am' => Clock::now_utc()]);
		(new WettkampftagRepository())->update($tag['tag'], ['status' => WettkampftagStatus::FREIGEGEBEN, 'veroeffentlicht_am' => null]);

		$em = $admin->abmelden($this->em['B'], 'verletzt');
		$this->assertNotNull($em['abgemeldet_am']);
		$this->assertSame('verletzt', $em['abmeldegrund']);
		$this->assertFalse($em['startgeld_berechnen'], 'vor Veröffentlichung: kein Startgeld');
		$this->assertNull((new BuchungRepository())->by_einzelmeldung($this->em['B']), 'Startplatz frei');
		$row = (new EinzelmeldungRepository())->find($this->em['B']);
		$this->assertTrue((new MannschaftRepository())->find((int) $row['mannschaft_id'])['unvollstaendig']);
		$z = $admin->zusammenfassung();
		$this->assertEqualsWithDelta($vorher - $em['startgeld'], $z['startgeld']['einzel'], 0.001, 'Startgeld der Abmeldung entfällt');
		$a = (new AenderungRepository())->offen_je_verein((int) $this->verein['id']);
		$this->assertSame(AenderungTyp::ABMELDUNG, end($a)['typ']);

		// Schalter übersteuern und Abmeldung aufheben.
		$this->assertTrue($admin->startgeld_schalter($this->em['B'], true)['startgeld_berechnen']);
		$this->assertEqualsWithDelta($vorher, $admin->zusammenfassung()['startgeld']['einzel'], 0.001);
		try {
			$admin->abmelden($this->em['B']);
			$this->fail('doppelt abmelden');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('bereits abgemeldet', $e->getMessage());
		}
		$em = $admin->abmeldung_aufheben($this->em['B']);
		$this->assertNull($em['abgemeldet_am']);
		$this->assertFalse((new MannschaftRepository())->find((int) $row['mannschaft_id'])['unvollstaendig'], 'Mannschaft wieder vollständig');
	}

	public function test_abmeldung_nach_veroeffentlichung_mit_startgeld_uebersteuerbar(): void {
		$admin = $this->admin();
		$tag = $this->veroeffentlichter_wettkampftag($this->lg);
		// Ohne Buchung: Zulassung des veröffentlichten Tages zählt.
		$em = $admin->abmelden($this->em['C'], '');
		$this->assertTrue($em['startgeld_berechnen'], 'nach Veröffentlichung: Startgeld');
		$summe = $admin->zusammenfassung()['startgeld']['einzel'];
		$this->assertEqualsWithDelta($summe, $admin->zusammenfassung()['startgeld']['einzel'], 0.001);

		// Mit Buchung auf dem veröffentlichten Tag, aber ausdrücklich ohne Startgeld.
		(new BuchungRepository())->insert(['sportjahr_id' => $this->sid, 'wettkampftag_id' => $tag['tag'], 'durchgang_id' => $tag['durchgang'], 'einheit_id' => $tag['einheit'], 'position' => 1, 'einzelmeldung_id' => $this->em['A'], 'verein_id' => (int) $this->verein['id'], 'gebucht_am' => Clock::now_utc()]);
		$em = $admin->abmelden($this->em['A'], 'auf Wunsch', false);
		$this->assertFalse($em['startgeld_berechnen']);
		$this->assertNull((new BuchungRepository())->by_einzelmeldung($this->em['A']));
		$this->assertEqualsWithDelta($summe - $em['startgeld'], $admin->zusammenfassung()['startgeld']['einzel'], 0.001);

		// Abgemeldete fallen aus dem Export, bleiben aber in der Vereinsansicht sichtbar.
		$zeilen = (new ExportService())->zeilen($this->sid, null, null, true, false);
		$this->assertSame(['B'], array_map(static fn($r) => $r->nachname, $zeilen));
		$this->assertCount(3, $admin->zusammenfassung()['einzelmeldungen']);
	}

	public function test_aenderungen_seit_export(): void {
		$admin = $this->admin();
		$admin->einzel_anlegen($this->schuetze['D'], $this->lg);
		$export = (new ExportService())->david($this->sid, null, true);
		$this->assertNotSame('', $export['inhalt']);
		$letzter = (new ExportRepository())->letzter($this->sid, ExportService::TYP_DAVID);
		$this->assertNotNull($letzter);
		// Zeitstempel auseinanderziehen (Sekundenauflösung): Nachmeldung vor dem Export, Abmeldung danach.
		$aenderungen = new AenderungRepository();
		foreach ($aenderungen->seit($this->sid, null) as $a) {
			$aenderungen->update((int) $a['id'], ['erstellt_am' => gmdate('Y-m-d H:i:s', time() - 10)]);
		}
		(new ExportRepository())->update((int) $letzter['id'], ['erstellt_am' => gmdate('Y-m-d H:i:s', time() - 5)]);
		$letzter = (new ExportRepository())->letzter($this->sid, ExportService::TYP_DAVID);

		$admin->abmelden($this->em['A'], 'krank');
		$seit = (new AenderungRepository())->seit($this->sid, (string) $letzter['erstellt_am']);
		$this->assertCount(1, $seit);
		$this->assertSame(AenderungTyp::ABMELDUNG, $seit[0]['typ']);
		$this->assertCount(2, (new AenderungRepository())->seit($this->sid, null), 'ohne Export: alle Änderungen');
	}

	public function test_nachmeldung_freischalten_oeffnet_verein_zeitweise(): void {
		$admin = $this->admin();
		$admin->nachmeldung_freischalten('2099-01-01 12:00:00');
		$club = $this->club();
		$phase = $club->phase();
		$this->assertTrue($phase['schreibbar']);
		$this->assertSame('nachmeldung', $phase['status']);
		$this->assertNotSame('', $club->zusammenfassung()['nachmeldung_bis']);
		$club->wieder_oeffnen();
		$neu = $club->einzel_anlegen($this->schuetze['D'], $this->lg);
		$this->assertTrue($neu['nachgemeldet'], 'Nachmeldung des Vereins nach Meldeschluss wird als solche markiert');
		$a = (new AenderungRepository())->offen_je_verein((int) $this->verein['id']);
		$this->assertSame(AenderungTyp::NACHMELDUNG, end($a)['typ']);
		$club->einreichen();

		$admin->nachmeldung_freischalten(null);
		$this->assertFalse($this->club()->phase()['schreibbar']);
	}
}
