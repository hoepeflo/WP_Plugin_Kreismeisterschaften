<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Integration;

use KSV\KMM\Application\BuchungService;
use KSV\KMM\Application\MeldungService;
use KSV\KMM\Application\RegeltabelleImporter;
use KSV\KMM\Application\SchuetzeService;
use KSV\KMM\Application\SportjahrService;
use KSV\KMM\Application\StartplanService;
use KSV\KMM\Application\VereinService;
use KSV\KMM\Auth\Rechte;
use KSV\KMM\Domain\Regeltabelle\Dokument;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\BuchungRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;
use KSV\KMM\Infrastructure\Repository\WettkampftagRepository;
use KSV\KMM\Support\Clock;

/**
 * Phase 2, Meilenstein 8: Freigabe und Buchung (Sofortbuchung, Eindeutigkeit auf DB-Ebene).
 */
final class BuchungTest extends IntegrationTestCase {

	private int $sid;
	/** @var array<string, array<string, mixed>> */
	private array $vereine = [];
	/** @var array<string, int> */
	private array $em = [];
	/** @var list<array<string, mixed>> */
	private array $mails = [];
	private int $lg;
	private int $lp;
	private int $tag;
	private int $dg1;
	private int $dg2;
	private int $dg3;
	private int $st1;
	private int $st2;

	protected function setUp(): void {
		parent::setUp();
		Rechte::cache_leeren();
		$this->sid = (new SportjahrService())->anlegen(2027);
		(new SportjahrRepository())->update($this->sid, ['meldung_beginn' => '2026-01-01 00:00:00', 'meldeschluss' => '2099-01-10 22:59:00']);
		remove_all_actions('kmm_regeln_geaendert');
		(new RegeltabelleImporter())->importieren($this->sid, Dokument::fromJson((string) file_get_contents(dirname(__DIR__, 2) . '/docs/regeltabelle-2026.json')));
		RegelwerkLader::cache_leeren();
		$this->mails = [];
		add_filter('pre_wp_mail', function ($null, array $atts) {
			$this->mails[] = $atts;
			return true;
		}, 10, 2);
		$rw = RegelwerkLader::engine($this->sid)->regelwerk();
		$this->lg = $rw->disziplin_nach_kennzahl('1.10')->id;
		$this->lp = $rw->disziplin_nach_kennzahl('2.10')->id;
		// Verein A: Schütze A1 in 1.10 und 2.10, A2 in 1.10. Verein B: B1 in 1.10. Verein C: nur 1.22 (nicht betroffen).
		foreach ([['A', '12345', [['A1', '1990-01-01', ['1.10', '2.10']], ['A2', '1985-01-01', ['1.10']]]], ['B', '12346', [['B1', '1980-01-01', ['1.10']]]], ['C', '12347', [['C1', '1980-01-01', ['1.22']]]]] as [$name, $vn, $schuetzen]) {
			$vid = (new VereinService())->speichern(0, 'SV ' . $name, $vn, [strtolower($name) . '@example.org'], true);
			$v = (array) (new VereinRepository())->find($vid);
			$v['sportjahr_id'] = $this->sid;
			$this->vereine[ $name ] = $v;
			$ss = new SchuetzeService($v, $this->sid);
			$ms = new MeldungService($v, $this->sid);
			foreach ($schuetzen as $i => [$n, $g, $dis]) {
				$s = $ss->speichern(0, ['nachname' => $n, 'vorname' => 'X', 'geburtsdatum' => $g, 'geschlecht' => 'm', 'mitgliedsnummer' => $vn . '000' . ($i + 1)])['id'];
				foreach ($dis as $kz) {
					$this->em[ $n . '_' . $kz ] = $ms->einzel_anlegen($s, $rw->disziplin_nach_kennzahl($kz)->id)['id'];
				}
			}
			$ms->ansprechpartner_speichern(['name' => 'SL ' . $name, 'email' => 'sl-' . strtolower($name) . '@example.org']);
			$ms->einreichen();
		}
		$this->mails = [];
		// Wettkampftag: 2 Stände; DG1 09:00–10:30 (1.10), DG2 10:00–11:30 (2.10, überschneidet DG1), DG3 12:00–13:00 (1.10 + 2.10).
		$sp = new StartplanService($this->sid);
		$this->tag = $sp->tag_speichern(0, ['datum' => '2027-03-06', 'bezeichnung' => 'KM Luftdruck', 'ort' => 'Bad Fallingbostel', 'buchungsfrist' => '2099-02-20 22:59:00']);
		$this->st1 = $sp->einheit_speichern(0, $this->tag, 'Stand 1', 1);
		$this->st2 = $sp->einheit_speichern(0, $this->tag, 'Stand 2', 1);
		$this->dg1 = $sp->durchgang_speichern(0, $this->tag, 1, '', Clock::local_to_utc('2027-03-06 09:00'), Clock::local_to_utc('2027-03-06 10:30'));
		$this->dg2 = $sp->durchgang_speichern(0, $this->tag, 2, '', Clock::local_to_utc('2027-03-06 10:00'), Clock::local_to_utc('2027-03-06 11:30'));
		$this->dg3 = $sp->durchgang_speichern(0, $this->tag, 3, '', Clock::local_to_utc('2027-03-06 12:00'), Clock::local_to_utc('2027-03-06 13:00'));
		$sp->zulassung_hinzufuegen($this->dg1, $this->lg, null);
		$sp->zulassung_hinzufuegen($this->dg2, $this->lp, null);
		$sp->zulassung_hinzufuegen($this->dg3, $this->lg, null);
		$sp->zulassung_hinzufuegen($this->dg3, $this->lp, null);
	}

	protected function tearDown(): void {
		remove_all_filters('pre_wp_mail');
		wp_set_current_user(1);
		Rechte::cache_leeren();
	}

	private function club(string $name): BuchungService {
		return new BuchungService($this->vereine[ $name ], $this->sid);
	}

	public function test_freigabe_macht_sichtbar_und_mailt_nur_betroffene_vereine(): void {
		$a = $this->club('A');
		$this->assertSame([], $a->tage(), 'Entwurf: unsichtbar');
		try {
			$a->raster($this->tag);
			$this->fail('Raster im Entwurf');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('nicht freigegeben', $e->getMessage());
		}
		try {
			$a->buchen($this->tag, $this->dg1, $this->st1, 1, $this->em['A1_1.10']);
			$this->fail('Buchen im Entwurf');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('nicht freigegeben', $e->getMessage());
		}

		$sp = new StartplanService($this->sid);
		$r = $sp->freigeben($this->tag);
		$this->assertSame(['gesendet' => 2, 'vereine' => 2, 'fehler' => []], $r, 'nur A und B haben passende Starter');
		$this->assertCount(2, $this->mails);
		$this->assertStringContainsString('Startplätze buchen', (string) $this->mails[0]['subject']);
		$this->assertMatchesRegularExpression('#/zugang/[a-f0-9]{64}/#', (string) $this->mails[0]['message']);
		$this->assertContains('sl-a@example.org', (array) $this->mails[0]['to']);
		$tag = (new WettkampftagRepository())->find($this->tag);
		$this->assertSame('freigegeben', $tag['status']);
		$this->assertNotNull($tag['erinnerung_am'], 'Erinnerung geplant (Frist − Tage)');

		$tage = $a->tage();
		$this->assertCount(1, $tage);
		$this->assertSame(3, $tage[0]['meldungen']);
		$this->assertSame(3, $tage[0]['ohne_platz']);
		$this->assertTrue($tage[0]['buchbar']);
		$this->assertSame([], $this->club('C')->tage(), 'C hat keine passenden Starter');

		try {
			$sp->freigeben($this->tag);
			$this->fail('doppelt freigeben');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('bereits', $e->getMessage());
		}
		$sp->freigabe_zuruecknehmen($this->tag);
		$this->assertSame('entwurf', (new WettkampftagRepository())->find($this->tag)['status']);
	}

	public function test_buchen_umbuchen_freigeben_und_fremde_plaetze(): void {
		(new StartplanService($this->sid))->freigeben($this->tag);
		$a = $this->club('A');
		$b = $this->club('B');

		$r = $a->buchen($this->tag, $this->dg1, $this->st1, 1, $this->em['A1_1.10']);
		$this->assertFalse($r['umgebucht']);
		$raster = $a->raster($this->tag);
		$zelle = $raster['durchgaenge'][0]['belegung'][ $this->st1 ][1];
		$this->assertTrue($zelle['eigen']);
		$this->assertSame('A1, X', $zelle['name']);
		// Aus Sicht von B: belegt, ohne Namen.
		$fremd = $b->raster($this->tag)['durchgaenge'][0]['belegung'][ $this->st1 ][1];
		$this->assertFalse($fremd['eigen']);
		$this->assertSame('', $fremd['name']);
		$this->assertNull($fremd['einzelmeldung_id']);

		// Gleicher Platz durch B: Datenbank lehnt ab (UNIQUE platz).
		try {
			$b->buchen($this->tag, $this->dg1, $this->st1, 1, $this->em['B1_1.10']);
			$this->fail('Doppelbuchung');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('anderen Verein', $e->getMessage());
		}
		$this->assertCount(1, (new BuchungRepository())->by_wettkampftag($this->tag));

		// Umbuchen von A1 auf Stand 2: ein UPDATE, alter Platz wird frei.
		$r = $a->buchen($this->tag, $this->dg1, $this->st2, 1, $this->em['A1_1.10']);
		$this->assertTrue($r['umgebucht']);
		$this->assertNull($a->raster($this->tag)['durchgaenge'][0]['belegung'][ $this->st1 ][1] ?? null);
		// B bucht den frei gewordenen Stand 1.
		$b->buchen($this->tag, $this->dg1, $this->st1, 1, $this->em['B1_1.10']);
		// Umbuchen von A1 auf den jetzt belegten Stand 1 scheitert; A1 behält Stand 2.
		try {
			$a->buchen($this->tag, $this->dg1, $this->st1, 1, $this->em['A1_1.10']);
			$this->fail('Umbuchen auf belegten Platz');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('anderen Verein', $e->getMessage());
		}
		$eigene = (new BuchungRepository())->by_einzelmeldung($this->em['A1_1.10']);
		$this->assertSame($this->st2, (int) $eigene['einheit_id'], 'alte Buchung bleibt bestehen');

		// Fremde Meldung, falscher Durchgang, ungültige Position, Einheit gesperrt.
		foreach ([
			[$this->dg1, $this->st2, 1, $this->em['B1_1.10'], 'nicht gefunden'],
			[$this->dg2, $this->st2, 1, $this->em['A2_1.10'], 'nicht zugelassen'],
			[$this->dg1, $this->st2, 2, $this->em['A2_1.10'], 'Position'],
		] as [$dg, $st, $pos, $em, $text]) {
			try {
				$a->buchen($this->tag, $dg, $st, $pos, $em);
				$this->fail($text);
			} catch (\RuntimeException $e) {
				$this->assertStringContainsString($text, $e->getMessage());
			}
		}

		// Platz freigeben (nur eigener), fremder nicht.
		$fremde_id = (int) (new BuchungRepository())->by_einzelmeldung($this->em['B1_1.10'])['id'];
		try {
			$a->freigeben($fremde_id);
			$this->fail('fremde Buchung');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('nicht gefunden', $e->getMessage());
		}
		$a->freigeben((int) $eigene['id']);
		$this->assertNull((new BuchungRepository())->by_einzelmeldung($this->em['A1_1.10']));
		$this->assertSame(1, $a->tage()[0]['mit_platz'] + 1 - 1 + (int) ($b->tage()[0]['mit_platz'] === 1));
	}

	public function test_schuetze_nicht_in_ueberschneidenden_durchgaengen_auch_ueber_disziplinen(): void {
		(new StartplanService($this->sid))->freigeben($this->tag);
		$a = $this->club('A');
		$a->buchen($this->tag, $this->dg1, $this->st1, 1, $this->em['A1_1.10']); // 09:00–10:30
		try {
			$a->buchen($this->tag, $this->dg2, $this->st1, 1, $this->em['A1_2.10']); // 10:00–11:30, überschneidet
			$this->fail('Überschneidung');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('überschneidet', $e->getMessage());
		}
		// Anderer Schütze im überschneidenden Durchgang ist in Ordnung; A1 in DG3 (12:00) ebenfalls.
		$a->buchen($this->tag, $this->dg3, $this->st1, 1, $this->em['A1_2.10']);
		$this->assertCount(2, (new BuchungRepository())->by_schuetze($this->sid, (int) (new \KSV\KMM\Infrastructure\Repository\EinzelmeldungRepository())->find($this->em['A1_1.10'])['schuetze_id']));
		// Umbuchen von A1 (2.10) in den überschneidenden DG2 scheitert ebenfalls.
		try {
			$a->buchen($this->tag, $this->dg2, $this->st2, 1, $this->em['A1_2.10']);
			$this->fail('Umbuchen in Überschneidung');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('überschneidet', $e->getMessage());
		}
	}

	public function test_gleichzeitige_buchung_desselben_platzes_nur_einer_gewinnt(): void {
		(new StartplanService($this->sid))->freigeben($this->tag);
		// Beide Vereine haben ihre fachlichen Prüfungen bestanden und schreiben „gleichzeitig“:
		// die Datenbank entscheidet über UNIQUE(durchgang_id, einheit_id, position).
		$repo = new BuchungRepository();
		$basis = ['sportjahr_id' => $this->sid, 'wettkampftag_id' => $this->tag, 'durchgang_id' => $this->dg1, 'einheit_id' => $this->st1, 'position' => 1, 'verein_id' => 0, 'gebucht_am' => Clock::now_utc()];
		$r1 = $repo->platz_buchen(array_merge($basis, ['einzelmeldung_id' => $this->em['A1_1.10'], 'verein_id' => (int) $this->vereine['A']['id']]));
		$r2 = $repo->platz_buchen(array_merge($basis, ['einzelmeldung_id' => $this->em['B1_1.10'], 'verein_id' => (int) $this->vereine['B']['id']]));
		$this->assertSame(BuchungRepository::ERGEBNIS_OK, $r1['ergebnis']);
		$this->assertSame(BuchungRepository::ERGEBNIS_PLATZ_BELEGT, $r2['ergebnis']);
		$this->assertCount(1, $repo->by_wettkampftag($this->tag));
		// Dieselbe Meldung zweimal (zwei Klicks desselben Vereins): UNIQUE(einzelmeldung_id).
		$r3 = $repo->platz_buchen(array_merge($basis, ['einheit_id' => $this->st2, 'einzelmeldung_id' => $this->em['A1_1.10'], 'verein_id' => (int) $this->vereine['A']['id']]));
		$this->assertSame(BuchungRepository::ERGEBNIS_SCHON_GEBUCHT, $r3['ergebnis']);
		// Umbuchen auf einen belegten Platz: UPDATE scheitert, Zeile unverändert.
		$repo->platz_buchen(array_merge($basis, ['einheit_id' => $this->st2, 'einzelmeldung_id' => $this->em['B1_1.10'], 'verein_id' => (int) $this->vereine['B']['id']]));
		$this->assertSame(BuchungRepository::ERGEBNIS_PLATZ_BELEGT, $repo->umbuchen($r1['id'], $this->dg1, $this->st2, 1));
		$this->assertSame($this->st1, (int) $repo->find($r1['id'])['einheit_id']);
		// Über den Service: der Verlierer bekommt die Meldung „anderer Verein“, das Raster bleibt konsistent.
		try {
			$this->club('A')->buchen($this->tag, $this->dg1, $this->st2, 1, $this->em['A2_1.10']);
			$this->fail('Platz belegt');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('anderen Verein', $e->getMessage());
		}
	}

	public function test_frist_sperrt_vereine_admin_darf_weiter_und_erinnerung(): void {
		$sp = new StartplanService($this->sid);
		$sp->freigeben($this->tag);
		$a = $this->club('A');
		$a->buchen($this->tag, $this->dg1, $this->st1, 1, $this->em['A1_1.10']);
		(new WettkampftagRepository())->update($this->tag, ['buchungsfrist' => '2026-01-01 00:00:00']);
		$this->assertFalse($a->tage()[0]['buchbar']);
		try {
			$a->buchen($this->tag, $this->dg1, $this->st2, 1, $this->em['A2_1.10']);
			$this->fail('nach Frist');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Buchungsfrist', $e->getMessage());
		}
		try {
			$a->freigeben((int) (new BuchungRepository())->by_einzelmeldung($this->em['A1_1.10'])['id']);
			$this->fail('freigeben nach Frist');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Buchungsfrist', $e->getMessage());
		}
		$admin = new BuchungService($this->vereine['A'], $this->sid, true);
		$admin->buchen($this->tag, $this->dg1, $this->st2, 1, $this->em['A2_1.10']);
		$this->assertSame(2, $admin->tage()[0]['mit_platz']);
		$this->assertCount(1, (new \KSV\KMM\Infrastructure\Repository\AenderungRepository())->offen_je_verein((int) $this->vereine['A']['id']), 'Admin-Zuteilung wird als Startplan-Änderung erfasst: ' . json_encode(array_column((new \KSV\KMM\Infrastructure\Repository\AenderungRepository())->offen_je_verein((int) $this->vereine['A']['id']), 'text'), JSON_UNESCAPED_UNICODE));

		// Erinnerung: fällig, nur Vereine mit Startern ohne Platz (B), kein Doppelversand.
		(new WettkampftagRepository())->update($this->tag, ['buchungsfrist' => '2099-02-20 22:59:00', 'erinnerung_am' => '2026-01-01 00:00:00', 'erinnerung_gesendet_am' => null]);
		$this->mails = [];
		StartplanService::cron_erinnerung();
		// A: A1 in 2.10 noch ohne Platz; B: B1 ohne Platz; C nicht betroffen.
		$this->assertSame([['sl-a@example.org', 'a@example.org'], ['sl-b@example.org', 'b@example.org']], array_map(static fn(array $m): array => (array) $m['to'], $this->mails));
		$this->assertStringContainsString('noch 1 von 3 Startern keinen Startplatz', (string) $this->mails[0]['message']);
		$this->assertStringContainsString('noch 1 von 1 Startern keinen Startplatz', (string) $this->mails[1]['message']);
		StartplanService::cron_erinnerung();
		$this->assertCount(2, $this->mails, 'kein Doppelversand');
	}
}
