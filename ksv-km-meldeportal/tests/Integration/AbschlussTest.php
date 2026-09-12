<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Integration;

use KSV\KMM\Application\Abschluss;
use KSV\KMM\Application\BuchungService;
use KSV\KMM\Application\MeldungService;
use KSV\KMM\Application\PdfStartplan;
use KSV\KMM\Application\RegeltabelleImporter;
use KSV\KMM\Application\SchuetzeService;
use KSV\KMM\Application\SportjahrService;
use KSV\KMM\Application\StartplanAnsicht;
use KSV\KMM\Application\StartplanService;
use KSV\KMM\Application\VereinService;
use KSV\KMM\Auth\Rechte;
use KSV\KMM\Domain\Regeltabelle\Dokument;
use KSV\KMM\Http\Shortcode;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\AenderungRepository;
use KSV\KMM\Infrastructure\Repository\EinzelmeldungRepository;
use KSV\KMM\Infrastructure\Repository\MagicLinkRepository;
use KSV\KMM\Infrastructure\Repository\MeldungRepository;
use KSV\KMM\Infrastructure\Repository\SchuetzeRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;
use KSV\KMM\Infrastructure\Repository\WettkampftagRepository;
use KSV\KMM\Support\Clock;

/**
 * Phase 2, Meilenstein 10: Veröffentlichung (Shortcode, PDF) und Abschluss des
 * Sportjahres mit Anonymisierung (Konzept 12.6 und 7.4).
 */
final class AbschlussTest extends IntegrationTestCase {

	private int $sid;
	/** @var array<string, mixed> */
	private array $verein;
	private int $schuetze;
	private int $einzelmeldung;
	private int $tag;

	protected function setUp(): void {
		parent::setUp();
		Rechte::cache_leeren();
		$this->sid = (new SportjahrService())->anlegen(2027, true, 'KM 2027');
		(new SportjahrRepository())->update($this->sid, ['meldung_beginn' => '2026-01-01 00:00:00', 'meldeschluss' => '2099-01-10 22:59:00']);
		remove_all_actions('kmm_regeln_geaendert');
		(new RegeltabelleImporter())->importieren($this->sid, Dokument::fromJson((string) file_get_contents(dirname(__DIR__, 2) . '/docs/regeltabelle-2026.json')));
		RegelwerkLader::cache_leeren();
		add_filter('pre_wp_mail', static fn() => true);
		$rw = RegelwerkLader::engine($this->sid)->regelwerk();
		$lg = $rw->disziplin_nach_kennzahl('1.10')->id;

		$vid = (new VereinService())->speichern(0, 'SV Musterhausen', '12345', ['info@example.org'], true);
		$this->verein = (array) (new VereinRepository())->find($vid);
		$this->verein['sportjahr_id'] = $this->sid;
		$ss = new SchuetzeService($this->verein, $this->sid);
		$ms = new MeldungService($this->verein, $this->sid);
		$this->schuetze = $ss->speichern(0, ['nachname' => 'Bauer', 'vorname' => 'Anna', 'geburtsdatum' => '1998-04-12', 'geschlecht' => 'w', 'mitgliedsnummer' => '123450001'])['id'];
		$this->einzelmeldung = $ms->einzel_anlegen($this->schuetze, $lg)['id'];
		$ms->einzel_aendern($this->einzelmeldung, ['meldeergebnis' => '374']);
		$ms->ansprechpartner_speichern(['name' => 'Sportleiter', 'email' => 'sl@example.org', 'telefon' => '05162 1234']);
		$ms->einreichen();

		$sp = new StartplanService($this->sid);
		$this->tag = $sp->tag_speichern(0, ['datum' => '2027-03-06', 'bezeichnung' => 'KM Luftdruck', 'ort' => 'Bad Fallingbostel', 'buchungsfrist' => '2099-02-20 22:59:00', 'hinweis' => 'Bitte 20 Minuten vorher da sein.']);
		$stand = $sp->einheit_speichern(0, $this->tag, 'Stand 1', 1);
		$dg = $sp->durchgang_speichern(0, $this->tag, 1, '', Clock::local_to_utc('2027-03-06 09:00'), Clock::local_to_utc('2027-03-06 10:30'));
		$sp->zulassung_hinzufuegen($dg, $lg, null);
		$sp->freigeben($this->tag);
		(new BuchungService($this->verein, $this->sid))->buchen($this->tag, $dg, $stand, 1, $this->einzelmeldung);
	}

	protected function tearDown(): void {
		remove_all_filters('pre_wp_mail');
		wp_set_current_user(1);
		Rechte::cache_leeren();
	}

	public function test_startplan_ist_erst_nach_der_veroeffentlichung_oeffentlich(): void {
		$this->assertNull(StartplanAnsicht::plan($this->tag), 'freigegeben ist noch nicht öffentlich');
		$this->assertSame([], StartplanAnsicht::tage($this->sid));
		$this->assertStringContainsString('noch nicht veröffentlicht', Shortcode::startplan(['tag' => (string) $this->tag]));

		(new StartplanService($this->sid))->veroeffentlichen($this->tag, false);
		$plan = StartplanAnsicht::plan($this->tag);
		$this->assertNotNull($plan);
		$this->assertSame(1, $plan['starter']);
		$this->assertCount(1, $plan['durchgaenge']);
		$zeile = $plan['durchgaenge'][0]['zeilen'][0];
		$this->assertSame('Bauer', $zeile['name']);
		$this->assertSame('SV Musterhausen', $zeile['verein']);
		$this->assertSame('Stand 1', $zeile['einheit']);
		$this->assertSame('09:00', $plan['durchgaenge'][0]['beginn']);
		$this->assertArrayNotHasKey('geburtsdatum', $zeile, 'nur die öffentlichen Angaben');
		$this->assertArrayNotHasKey('meldeergebnis', $zeile);
	}

	public function test_shortcode_zeigt_den_plan_und_filtert_nach_disziplin(): void {
		(new StartplanService($this->sid))->veroeffentlichen($this->tag, false);
		$html = Shortcode::startplan(['tag' => (string) $this->tag]);
		$this->assertStringContainsString('Bauer, Anna', $html);
		$this->assertStringContainsString('SV Musterhausen', $html);
		$this->assertStringContainsString('Bitte 20 Minuten vorher', $html);
		$this->assertStringNotContainsString('123450001', $html, 'keine Mitgliedsnummer');
		$this->assertStringNotContainsString('374', $html, 'kein Meldeergebnis');

		$this->assertStringContainsString('Bauer, Anna', Shortcode::startplan(['tag' => (string) $this->tag, 'disziplin' => '1.10']));
		$this->assertStringContainsString('kein Starter eingeteilt', Shortcode::startplan(['tag' => (string) $this->tag, 'disziplin' => '2.10']), 'andere Disziplin: nichts zu zeigen');

		// Ohne tag: alle veröffentlichten Tage.
		$this->assertStringContainsString('KM Luftdruck', Shortcode::startplan(['sportjahr' => (string) $this->sid]));
	}

	public function test_pdf_startplan_enthaelt_durchgang_und_stand(): void {
		$plan = StartplanAnsicht::plan($this->tag, '', true);
		$this->assertNotNull($plan);
		$html = (new PdfStartplan())->html($plan);
		$this->assertStringContainsString('Durchgang 1', $html);
		$this->assertStringContainsString('09:00', $html);
		$this->assertStringContainsString('Stand 1', $html);
		$this->assertStringContainsString('Bauer, Anna', $html);
		$this->assertStringContainsString('Entwurf', $html, 'vor der Veröffentlichung als Entwurf gekennzeichnet');

		(new StartplanService($this->sid))->veroeffentlichen($this->tag, false);
		$html = (new PdfStartplan())->html((array) StartplanAnsicht::plan($this->tag));
		$this->assertStringNotContainsString('Entwurf', $html);
	}

	public function test_abschluss_anonymisiert_und_blendet_startplaene_aus(): void {
		(new StartplanService($this->sid))->veroeffentlichen($this->tag, false);
		$service = new Abschluss();
		$pruefung = $service->pruefen($this->sid);
		$this->assertSame(1, $pruefung['meldungen']);
		$this->assertSame(['SV Musterhausen'], $pruefung['vereine_ohne_beleg']);

		$r = $service->abschliessen($this->sid, true);
		$this->assertNotSame('', $r['sicherung'], 'Sicherung geschrieben');
		$this->assertSame(1, $r['anonymisiert']['einzelmeldung']);
		$this->assertSame(1, $r['tage']);

		$sj = (array) (new SportjahrRepository())->find($this->sid);
		$this->assertNotNull($sj['abgeschlossen_am']);
		$this->assertNotNull($sj['anonymisiert_am']);
		$this->assertSame($r['sicherung'], (string) $sj['abschluss_backup']);

		// Personenbezug ist weg, die Statistik bleibt.
		$em = (array) (new EinzelmeldungRepository())->find($this->einzelmeldung);
		$this->assertNull($em['schuetze_id']);
		$this->assertSame('w', (string) $em['geschlecht']);
		$this->assertNotNull($em['klasse_id']);
		$this->assertGreaterThan(0, (float) $em['startgeld']);
		$m = (array) (new MeldungRepository())->by_verein((int) $this->verein['id'], $this->sid);
		$this->assertSame('', (string) $m['ansprechpartner_name']);
		$this->assertSame('', (string) $m['ansprechpartner_email']);
		$this->assertSame('', (string) $m['ansprechpartner_telefon']);
		foreach ((new AenderungRepository())->where(['sportjahr_id' => $this->sid]) as $a) {
			$this->assertSame('', (string) $a['text']);
		}

		// Schützenliste bleibt erhalten (Konzept 7.2).
		$s = (new SchuetzeRepository())->find($this->schuetze);
		$this->assertNotNull($s);
		$this->assertSame('Bauer', (string) $s['nachname']);

		// Startplan ist nicht mehr öffentlich; Zugänge sind widerrufen.
		$this->assertNull(StartplanAnsicht::plan($this->tag));
		$this->assertStringContainsString('noch nicht veröffentlicht', Shortcode::startplan(['tag' => (string) $this->tag]));
		foreach ((new MagicLinkRepository())->where(['sportjahr_id' => $this->sid]) as $l) {
			$this->assertNotNull($l['widerrufen_am']);
		}

		// Schreiben ist gesperrt.
		try {
			(new StartplanService($this->sid))->tag_speichern($this->tag, ['datum' => '2027-03-07', 'bezeichnung' => 'X', 'ort' => '', 'buchungsfrist' => null, 'hinweis' => '', 'beitrag_id' => null, 'schiessstand_id' => null, 'ergebnis_url' => '']);
			$this->fail('Schreiben im abgeschlossenen Sportjahr');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('abgeschlossen', $e->getMessage());
		}
	}

	public function test_abschluss_ohne_anonymisierung_laesst_sich_zuruecknehmen(): void {
		$service = new Abschluss();
		$service->abschliessen($this->sid, false);
		$sj = (array) (new SportjahrRepository())->find($this->sid);
		$this->assertNotNull($sj['abgeschlossen_am']);
		$this->assertNull($sj['anonymisiert_am']);
		$this->assertNotNull((new EinzelmeldungRepository())->find($this->einzelmeldung)['schuetze_id'] ?? null);

		$service->oeffnen($this->sid);
		$sj = (array) (new SportjahrRepository())->find($this->sid);
		$this->assertNull($sj['abgeschlossen_am']);
		$this->assertNull(((array) (new WettkampftagRepository())->find($this->tag))['ausgeblendet_am'], 'Ausblenden zurückgenommen');

		// Anonymisierung kann später nachgeholt werden – danach ist Öffnen gesperrt.
		$service->abschliessen($this->sid, false);
		$service->nachtraeglich_anonymisieren($this->sid);
		try {
			$service->oeffnen($this->sid);
			$this->fail('Öffnen nach der Anonymisierung');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('anonymisiert', $e->getMessage());
		}
	}
}
