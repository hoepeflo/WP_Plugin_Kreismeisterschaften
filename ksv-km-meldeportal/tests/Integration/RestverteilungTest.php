<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Integration;

use KSV\KMM\Application\BuchungService;
use KSV\KMM\Application\MeldungService;
use KSV\KMM\Application\PdfStartplan;
use KSV\KMM\Application\RegeltabelleImporter;
use KSV\KMM\Application\SchuetzeService;
use KSV\KMM\Application\SportjahrService;
use KSV\KMM\Application\StartplanAnsicht;
use KSV\KMM\Application\StartplanService;
use KSV\KMM\Application\Startplatzvergabe;
use KSV\KMM\Application\VereinService;
use KSV\KMM\Auth\Rechte;
use KSV\KMM\Domain\Regeltabelle\Dokument;
use KSV\KMM\Domain\WettkampftagStatus;
use KSV\KMM\Http\Shortcode;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\BuchungRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;
use KSV\KMM\Infrastructure\Repository\WettkampftagRepository;
use KSV\KMM\Support\Clock;

/**
 * Phase 2, Meilenstein 9: Restverteilung, Verschieben, Tauschen, Veröffentlichung (Konzept 12.5/12.6).
 */
final class RestverteilungTest extends IntegrationTestCase {

	private int $sid;
	/** @var array<string, array<string, mixed>> */
	private array $vereine = [];
	/** @var array<string, int> */
	private array $em = [];
	private int $lg;
	private int $lp;
	private int $tag;
	private int $dg1;
	private int $dg2;
	private int $dg3;
	/** @var list<int> */
	private array $staende = [];

	protected function setUp(): void {
		parent::setUp();
		Rechte::cache_leeren();
		$this->sid = (new SportjahrService())->anlegen(2027);
		(new SportjahrRepository())->update($this->sid, ['meldung_beginn' => '2026-01-01 00:00:00', 'meldeschluss' => '2099-01-10 22:59:00']);
		remove_all_actions('kmm_regeln_geaendert');
		(new RegeltabelleImporter())->importieren($this->sid, Dokument::fromJson((string) file_get_contents(dirname(__DIR__, 2) . '/docs/regeltabelle-2026.json')));
		RegelwerkLader::cache_leeren();
		add_filter('pre_wp_mail', static fn() => true);
		$rw = RegelwerkLader::engine($this->sid)->regelwerk();
		$this->lg = $rw->disziplin_nach_kennzahl('1.10')->id;
		$this->lp = $rw->disziplin_nach_kennzahl('2.10')->id;
		// Verein A: A1 (1.10 + 2.10), A2 (1.10). Verein B: B1, B2 (1.10).
		foreach ([['A', '12345', [['A1', ['1.10', '2.10']], ['A2', ['1.10']]]], ['B', '12346', [['B1', ['1.10']], ['B2', ['1.10']]]]] as [$name, $vn, $leute]) {
			$vid = (new VereinService())->speichern(0, 'SV ' . $name, $vn, [strtolower($name) . '@example.org'], true);
			$v = (array) (new VereinRepository())->find($vid);
			$v['sportjahr_id'] = $this->sid;
			$this->vereine[ $name ] = $v;
			$ss = new SchuetzeService($v, $this->sid);
			$ms = new MeldungService($v, $this->sid);
			foreach ($leute as $i => [$n, $dis]) {
				$s = $ss->speichern(0, ['nachname' => $n, 'vorname' => 'X', 'geburtsdatum' => '1990-01-01', 'geschlecht' => 'm', 'mitgliedsnummer' => $vn . '000' . ($i + 1)])['id'];
				foreach ($dis as $kz) {
					$this->em[ $n . '_' . $kz ] = $ms->einzel_anlegen($s, $rw->disziplin_nach_kennzahl($kz)->id)['id'];
				}
			}
			$ms->ansprechpartner_speichern(['name' => 'SL ' . $name, 'email' => 'sl-' . strtolower($name) . '@example.org']);
			$ms->einreichen();
		}
		// Tag mit zwei Ständen; DG1 09:00–10:30 (1.10), DG2 10:00–11:30 (2.10, überschneidet DG1),
		// DG3 12:00–13:00 (2.10, ohne Überschneidung).
		$sp = new StartplanService($this->sid);
		$this->tag = $sp->tag_speichern(0, ['datum' => '2027-03-06', 'bezeichnung' => 'KM Luftdruck', 'ort' => 'Bad Fallingbostel', 'buchungsfrist' => '2099-02-20 22:59:00']);
		$this->staende = [$sp->einheit_speichern(0, $this->tag, 'Stand 1', 1), $sp->einheit_speichern(0, $this->tag, 'Stand 2', 1)];
		$this->dg1 = $sp->durchgang_speichern(0, $this->tag, 1, '', Clock::local_to_utc('2027-03-06 09:00'), Clock::local_to_utc('2027-03-06 10:30'));
		$this->dg2 = $sp->durchgang_speichern(0, $this->tag, 2, '', Clock::local_to_utc('2027-03-06 10:00'), Clock::local_to_utc('2027-03-06 11:30'));
		$sp->zulassung_hinzufuegen($this->dg1, $this->lg, null);
		$this->dg3 = $sp->durchgang_speichern(0, $this->tag, 3, '', Clock::local_to_utc('2027-03-06 12:00'), Clock::local_to_utc('2027-03-06 13:00'));
		$sp->zulassung_hinzufuegen($this->dg2, $this->lp, null);
		$sp->zulassung_hinzufuegen($this->dg3, $this->lp, null);
		$sp->freigeben($this->tag);
	}

	protected function tearDown(): void {
		remove_all_filters('pre_wp_mail');
		wp_set_current_user(1);
		Rechte::cache_leeren();
	}

	/** Buchungsfrist auf die Vergangenheit setzen. */
	private function frist_beenden(): void {
		(new WettkampftagRepository())->update($this->tag, ['buchungsfrist' => '2020-01-01 00:00:00']);
	}

	public function test_restverteilung_erst_nach_der_frist(): void {
		$v = new Startplatzvergabe($this->sid);
		try {
			$v->restverteilung($this->tag);
			$this->fail('Restverteilung vor der Frist');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Buchungsfrist läuft noch', $e->getMessage());
		}
		$this->frist_beenden();
		$r = $v->restverteilung($this->tag);
		$this->assertSame(3, $r['verteilt'], 'zwei von vier Startern in 1.10 und A1 in 2.10');
	}

	public function test_restverteilung_fuellt_freie_plaetze_und_meldet_den_rest(): void {
		$this->frist_beenden();
		$vergabe = new Startplatzvergabe($this->sid);
		$offen = $vergabe->ohne_platz($this->tag);
		$this->assertCount(5, $offen, 'vier Starter in 1.10 und einer in 2.10');

		$vorschau = $vergabe->restverteilung($this->tag, true);
		$this->assertSame(0, (new BuchungRepository())->count(['wettkampftag_id' => $this->tag]), 'Vorschau schreibt nichts');

		$r = $vergabe->restverteilung($this->tag);
		$this->assertSame($vorschau['verteilt'], $r['verteilt'], 'Vorschau und Lauf stimmen überein');
		$this->assertSame(3, $r['verteilt'], 'zwei Plätze in DG1 (1.10) und einer für A1 in 2.10');
		$this->assertCount(2, $r['offen'], 'zwei Starter aus 1.10 bleiben ohne Platz');
		$this->assertStringContainsString('kein freier Platz', $r['offen'][0]['grund']);
		$this->assertSame(3, (new BuchungRepository())->count(['wettkampftag_id' => $this->tag]));

		// A1 steht in DG1 (1.10); DG2 überschneidet sich damit, deshalb geht 2.10 in DG3.
		$nach_meldung = [];
		foreach ((new BuchungRepository())->by_wettkampftag($this->tag) as $b) {
			$nach_meldung[ (int) $b['einzelmeldung_id'] ] = (int) $b['durchgang_id'];
		}
		$this->assertSame($this->dg1, $nach_meldung[ $this->em['A1_1.10'] ] ?? 0);
		$this->assertSame($this->dg3, $nach_meldung[ $this->em['A1_2.10'] ] ?? 0, 'DG2 überschneidet sich, DG3 nicht');

		// Zweiter Lauf ändert nichts mehr.
		$this->assertSame(0, $vergabe->restverteilung($this->tag)['verteilt']);
	}

	public function test_restverteilung_beachtet_zeitueberschneidung(): void {
		$this->frist_beenden();
		// A1 bucht selbst 1.10 in DG1 – 2.10 in DG2 überschneidet sich und darf nicht zugeteilt werden.
		$admin = new BuchungService($this->vereine['A'], $this->sid, true);
		$admin->buchen($this->tag, $this->dg1, $this->staende[0], 1, $this->em['A1_1.10']);
		$r = (new Startplatzvergabe($this->sid))->restverteilung($this->tag);
		foreach ((new BuchungRepository())->by_wettkampftag($this->tag) as $b) {
			if ((int) $b['einzelmeldung_id'] === $this->em['A1_2.10']) {
				$this->assertSame($this->dg3, (int) $b['durchgang_id'], 'A1 darf nicht in den überschneidenden DG2, sondern nur in DG3');
			}
		}
		$this->assertGreaterThan(0, $r['verteilt']);
	}

	public function test_verschieben_auf_freien_platz(): void {
		$this->frist_beenden();
		$admin = new BuchungService($this->vereine['A'], $this->sid, true);
		$id = $admin->buchen($this->tag, $this->dg1, $this->staende[0], 1, $this->em['A2_1.10'])['buchung_id'];
		$r = (new Startplatzvergabe($this->sid))->verschieben($id, $this->dg1, $this->staende[1], 1);
		$this->assertFalse($r['getauscht']);
		$b = (array) (new BuchungRepository())->find($id);
		$this->assertSame($this->staende[1], (int) $b['einheit_id']);
	}

	public function test_verschieben_auf_belegten_platz_tauscht(): void {
		$this->frist_beenden();
		$a = new BuchungService($this->vereine['A'], $this->sid, true);
		$b = new BuchungService($this->vereine['B'], $this->sid, true);
		$id_a = $a->buchen($this->tag, $this->dg1, $this->staende[0], 1, $this->em['A2_1.10'])['buchung_id'];
		$id_b = $b->buchen($this->tag, $this->dg1, $this->staende[1], 1, $this->em['B1_1.10'])['buchung_id'];
		$r = (new Startplatzvergabe($this->sid))->verschieben($id_a, $this->dg1, $this->staende[1], 1);
		$this->assertTrue($r['getauscht']);
		$this->assertStringContainsString('B1', $r['mit']);
		$repo = new BuchungRepository();
		$this->assertSame($this->staende[1], (int) ((array) $repo->find($id_a))['einheit_id']);
		$this->assertSame($this->staende[0], (int) ((array) $repo->find($id_b))['einheit_id']);
		$this->assertSame(2, $repo->count(['wettkampftag_id' => $this->tag]), 'beide Buchungen bleiben erhalten');
	}

	public function test_verschieben_prueft_zulassung(): void {
		$this->frist_beenden();
		$a = new BuchungService($this->vereine['A'], $this->sid, true);
		$id = $a->buchen($this->tag, $this->dg1, $this->staende[0], 1, $this->em['A2_1.10'])['buchung_id'];
		try {
			(new Startplatzvergabe($this->sid))->verschieben($id, $this->dg2, $this->staende[0], 1);
			$this->fail('Verschieben in einen Durchgang ohne Zulassung');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('nicht zugelassen', $e->getMessage());
		}
	}

	public function test_startplan_zeigt_disziplin_und_klasse_nur_wenn_sie_unterscheiden(): void {
		$this->frist_beenden();
		(new Startplatzvergabe($this->sid))->restverteilung($this->tag);
		(new StartplanService($this->sid))->veroeffentlichen($this->tag, false);

		// Zwei Disziplinen, aber nur eine Klasse (alle Herren I): die Kennzahl gehört in jede
		// Zelle, die Klasse dagegen nur in die Kopfzeile.
		$plan = (array) StartplanAnsicht::plan($this->tag);
		$this->assertCount(2, $plan['disziplinen'], '1.10 und 2.10');
		$this->assertSame(['Herren I'], $plan['klassen']);
		$html = Shortcode::startplan(['tag' => (string) $this->tag]);
		$this->assertStringContainsString('Disziplinen:', $html, 'Kennzahlen werden in der Kopfzeile aufgeschlüsselt');
		$this->assertGreaterThan(1, substr_count($html, '1.10'), 'Kennzahl steht an jedem Startplatz');
		$this->assertSame(1, substr_count($html, 'Herren I'), 'einzige Klasse nur in der Kopfzeile');
		$pdf = (new PdfStartplan())->html($plan);
		$this->assertGreaterThan(1, substr_count($pdf, '1.10'), 'auch im PDF');
		$this->assertSame(1, substr_count($pdf, 'Herren I'), 'auch im PDF nur in der Kopfzeile');

		// Mit Filter auf eine Disziplin bleibt nur eine übrig: dann reicht die Kopfzeile.
		$gefiltert = (array) StartplanAnsicht::plan($this->tag, '1.10');
		$this->assertCount(1, $gefiltert['disziplinen']);
		$html = Shortcode::startplan(['tag' => (string) $this->tag, 'disziplin' => '1.10']);
		$this->assertStringNotContainsString('Disziplinen:', $html);
		$this->assertSame(1, substr_count($html, '1.10'), 'Kennzahl nur noch in der Kopfzeile');
	}

	public function test_veroeffentlichung_sperrt_die_buchung_und_bleibt_sichtbar(): void {
		$this->frist_beenden();
		$sp = new StartplanService($this->sid);
		try {
			$sp->veroeffentlichen($this->tag);
			$this->fail('Veröffentlichung ohne Buchungen');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('keine Buchung', $e->getMessage());
		}
		(new Startplatzvergabe($this->sid))->restverteilung($this->tag);
		$sp->veroeffentlichen($this->tag, false);
		$tag = (array) (new WettkampftagRepository())->find($this->tag);
		$this->assertSame(WettkampftagStatus::VEROEFFENTLICHT, (string) $tag['status']);
		$this->assertNotNull($tag['veroeffentlicht_am']);

		$verein = new BuchungService($this->vereine['A'], $this->sid);
		$tage = $verein->tage();
		$this->assertCount(1, $tage, 'veröffentlichte Tage bleiben für Vereine sichtbar');
		$this->assertFalse($tage[0]['buchbar']);
		$this->assertStringContainsString('veröffentlicht', $tage[0]['grund']);

		// Nach der Veröffentlichung sehen Vereine auch fremde Starter mit Namen (Konzept 12.6).
		$raster = (new BuchungService($this->vereine['B'], $this->sid))->raster($this->tag);
		$fremde = [];
		foreach ($raster['durchgaenge'] as $dg) {
			foreach ($dg['belegung'] as $plaetze) {
				foreach ($plaetze as $platz) {
					if (!$platz['eigen']) {
						$fremde[] = $platz;
					}
				}
			}
		}
		$this->assertNotSame([], $fremde, 'Verein B sieht Plätze von Verein A');
		$this->assertNotSame('', $fremde[0]['name'], 'mit Namen');
		$this->assertSame('SV A', $fremde[0]['verein']);

		$sp->veroeffentlichung_zuruecknehmen($this->tag);
		$raster = (new BuchungService($this->vereine['B'], $this->sid))->raster($this->tag);
		foreach ($raster['durchgaenge'] as $dg) {
			foreach ($dg['belegung'] as $plaetze) {
				foreach ($plaetze as $platz) {
					if (!$platz['eigen']) {
						$this->assertSame('', $platz['name'], 'vor der Veröffentlichung nur „belegt“');
					}
				}
			}
		}
		$this->assertSame(WettkampftagStatus::FREIGEGEBEN, (string) ((array) (new WettkampftagRepository())->find($this->tag))['status']);
	}
}
