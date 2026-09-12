<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Integration;

use KSV\KMM\Application\MeldungService;
use KSV\KMM\Application\RegeltabelleImporter;
use KSV\KMM\Application\SchuetzeService;
use KSV\KMM\Application\SportjahrService;
use KSV\KMM\Application\VereinService;
use KSV\KMM\Domain\Regeltabelle\Dokument;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;

final class MeldungServiceTest extends IntegrationTestCase {

	private int $sid;
	/** @var array<string, mixed> */
	private array $verein;
	/** @var array<int, array<string, mixed>> */
	private array $mails = [];

	protected function setUp(): void {
		parent::setUp();
		$this->sid = (new SportjahrService())->anlegen(2027);
		(new SportjahrRepository())->update($this->sid, ['meldung_beginn' => '2026-01-01 00:00:00', 'meldeschluss' => '2099-01-10 22:59:00']);
		remove_all_actions('kmm_regeln_geaendert');
		(new RegeltabelleImporter())->importieren($this->sid, Dokument::fromJson((string) file_get_contents(dirname(__DIR__, 2) . '/docs/regeltabelle-2026.json')));
		RegelwerkLader::cache_leeren();
		$vid = (new VereinService())->speichern(0, 'SV Vorwalsrode', '12345', ['sport@example.org'], true);
		$this->verein = (array) (new VereinRepository())->find($vid);
		$this->verein['sportjahr_id'] = $this->sid;
		add_filter('pre_wp_mail', function ($null, array $atts) {
			$this->mails[] = $atts;
			return true;
		}, 10, 2);
	}

	protected function tearDown(): void {
		remove_all_filters('pre_wp_mail');
	}

	private function schuetze(string $nachname, string $geb, string $g, string $nr = '123450001'): int {
		return (new SchuetzeService($this->verein, $this->sid))->speichern(0, ['nachname' => $nachname, 'vorname' => 'Test', 'geburtsdatum' => $geb, 'geschlecht' => $g, 'mitgliedsnummer' => $nr])['id'];
	}

	private function disziplin(string $kennzahl): int {
		return RegelwerkLader::engine($this->sid)->regelwerk()->disziplin_nach_kennzahl($kennzahl)->id;
	}

	public function test_schuetzenliste_validierung_und_warnung(): void {
		$service = new SchuetzeService($this->verein, $this->sid);
		$r = $service->speichern(0, ['nachname' => 'Muster', 'vorname' => 'Max', 'geburtsdatum' => '1985-06-15', 'geschlecht' => 'm', 'mitgliedsnummer' => '999990001']);
		$this->assertStringContainsString('beginnt nicht mit der VN-Nummer', $r['warnungen'][0]);
		$faelle = [
			[['mitgliedsnummer' => '12345'], '9 Ziffern'],
			[['geburtsdatum' => '2030-01-01'], 'Geburtsdatum'],
			[['geschlecht' => 'x'], 'Geschlecht'],
			[['nachname' => ''], 'Pflichtfelder'],
		];
		foreach ($faelle as [$override, $text]) {
			try {
				$service->speichern(0, array_merge(['nachname' => 'A', 'vorname' => 'B', 'geburtsdatum' => '1990-01-01', 'geschlecht' => 'm', 'mitgliedsnummer' => '123450002'], $override));
				$this->fail('erwartet Fehler: ' . $text);
			} catch (\InvalidArgumentException $e) {
				$this->assertStringContainsString($text, $e->getMessage());
			}
		}
		$liste = $service->liste();
		$this->assertCount(1, $liste);
		$this->assertSame(42, $liste[0]['alter']);
		$this->assertSame(['hd1' => 'Herren I'], $liste[0]['hoehermeldung_angebot']['uebrige']);
		$this->assertArrayNotHasKey('auflage', $liste[0]['hoehermeldung_angebot'], '42 ist bereits Senioren 0');

		// Höhermeldung setzen, unzulässige Stufe wird ignoriert.
		$service->speichern($r['id'], ['nachname' => 'Muster', 'vorname' => 'Max', 'geburtsdatum' => '1985-06-15', 'geschlecht' => 'm', 'mitgliedsnummer' => '999990001', 'hoehermeldungen' => ['uebrige' => 'hd1', 'auflage' => 'sen3']]);
		$this->assertSame(['uebrige' => 'hd1'], $service->liste()[0]['hoehermeldungen']);
	}

	public function test_meldeablauf_bis_einreichen(): void {
		$ms = new MeldungService($this->verein, $this->sid);
		$this->assertSame('offen', $ms->zusammenfassung()['status']);
		$this->assertTrue($ms->phase()['schreibbar']);

		$h1 = $this->schuetze('Eins', '1990-05-05', 'm', '123450001');
		$h2 = $this->schuetze('Zwei', '1985-05-05', 'm', '123450002');
		$h3 = $this->schuetze('Drei', '1972-05-05', 'm', '123450003');
		$d1 = $this->schuetze('Dame', '1992-05-05', 'w', '123450004');
		$j1 = $this->schuetze('Jugend', '2011-05-05', 'm', '123450005');

		$angebot = $ms->angebot($h2);
		$lg = array_values(array_filter($angebot, static fn(array $a): bool => $a['kennzahl'] === '1.10'))[0];
		$this->assertSame('Herren II', $lg['klasse']);
		$this->assertSame('1.10.12', $lg['kennzahl_voll']);
		$this->assertSame('Herren I', $lg['mannschaftspool']);
		$this->assertCount(4, $lg['para_optionen'], 'Para 90, 92, 94, 96 für m in 1.10');
		$this->assertNotContains('11.10', array_column($angebot, 'kennzahl'));

		$lg_id = $this->disziplin('1.10');
		$e1 = $ms->einzel_anlegen($h1, $lg_id);
		$this->assertSame('1.10.10', $e1['kennzahl_voll']);
		$this->assertSame(7.0, $e1['startgeld']);
		$e2 = $ms->einzel_anlegen($h2, $lg_id);
		$e3 = $ms->einzel_anlegen($h3, $lg_id); // Herren III, eigener Pool 14
		$ed = $ms->einzel_anlegen($d1, $lg_id);
		$ej = $ms->einzel_anlegen($j1, $lg_id);
		$this->assertSame('entwurf', $ms->zusammenfassung()['status']);

		try {
			$ms->einzel_anlegen($h1, $lg_id);
			$this->fail();
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('bereits gemeldet', $e->getMessage());
		}
		try {
			$ms->einzel_anlegen($j1, $this->disziplin('1.22'));
			$this->fail();
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Kein Startrecht', $e->getMessage());
		}

		// Meldeergebnis: ganze Ringe bei 1.22, Zehntel bei 1.10.
		$ms->einzel_aendern($e1['id'], ['meldeergebnis' => '389,4']);
		$eintrag = array_values(array_filter($ms->zusammenfassung()['einzelmeldungen'], static fn(array $x): bool => $x['id'] === $e1['id']))[0];
		$this->assertSame('389,4', $eintrag['meldeergebnis']);
		$ms->einzel_aendern($e1['id'], ['meldeergebnis' => '390']);
		try {
			$ms->einzel_aendern($e1['id'], ['meldeergebnis' => '389,45']);
			$this->fail();
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Zehntelringe', $e->getMessage());
		}
		$e22 = $ms->einzel_anlegen($h2, $this->disziplin('1.22'));
		try {
			$ms->einzel_aendern($e22['id'], ['meldeergebnis' => '375,4']);
			$this->fail();
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('ganze Ringe', $e->getMessage());
		}
		$ms->einzel_aendern($e22['id'], ['meldeergebnis' => '375']);

		// Mannschaften: Kandidaten nur gleicher Pool.
		$kand = $ms->kandidaten($lg_id);
		$this->assertSame(['Eins', 'Zwei', 'Drei', 'Dame', 'Jugend'], array_column($kand, 'nachname'));
		try {
			$ms->mannschaft_speichern(null, $lg_id, [$e1['id'], $e3['id']]);
			$this->fail();
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('verschiedenen Mannschaftsklassen', $e->getMessage());
		}
		$m1 = $ms->mannschaft_speichern(null, $lg_id, [$e1['id'], $e2['id']]);
		$this->assertSame(1, $m1['nummer']);
		$this->assertFalse($m1['vollstaendig']);
		$this->assertSame('Herren I', $m1['klasse']);
		$kand = $ms->kandidaten($lg_id, $m1['id']);
		$this->assertSame(['Eins', 'Zwei'], array_column(array_filter($kand, static fn(array $k): bool => $k['mitglied']), 'nachname'));
		$this->assertNotContains('Drei', array_column($kand, 'nachname'), 'anderer Pool');
		$m2 = $ms->mannschaft_speichern(null, $lg_id, [$e3['id']]);
		$this->assertSame(2, $m2['nummer'], 'Nummern fortlaufend über Klassen hinweg');

		// Einreichen scheitert: Ansprechpartner fehlt, Mannschaften unvollständig.
		$p = $ms->einreichen_pruefen();
		$this->assertStringContainsString('Ansprechpartner', implode(' ', $p['fehler']));
		$this->assertStringContainsString('unvollständig', implode(' ', $p['fehler']));
		$this->assertStringContainsString('ohne Meldeergebnis', implode(' ', $p['warnungen']));

		$ms->ansprechpartner_speichern(['name' => 'Sportleiter', 'email' => 'sl@example.org', 'telefon' => '0123']);
		$ms->mannschaft_loeschen($m2['id']);
		$h4 = $this->schuetze('Vier', '1988-01-01', 'm', '123450006');
		$e4 = $ms->einzel_anlegen($h4, $lg_id);
		$ms->mannschaft_speichern($m1['id'], $lg_id, [$e1['id'], $e2['id'], $e4['id']]);
		$this->assertSame([], $ms->einreichen_pruefen()['fehler']);

		$z = $ms->einreichen();
		$this->assertSame('eingereicht', $z['status']);
		$this->assertSame(47.0, $z['startgeld']['summe'], '6 Erwachsene à 7 € + Jugend 5 €');
		$this->assertCount(1, $this->mails);
		$this->assertSame(['sport@example.org', 'sl@example.org'], (array) $this->mails[0]['to']);
		$this->assertStringContainsString('1.10.12', $this->mails[0]['message']);
		$this->assertStringContainsString('Mannschaft 1', $this->mails[0]['message']);

		try {
			$ms->einzel_loeschen($e1['id']);
			$this->fail();
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('wieder öffnen', $e->getMessage());
		}
		$ms->wieder_oeffnen();
		$this->assertSame('entwurf', $ms->zusammenfassung()['status']);
		$ms->einzel_loeschen($e4['id']);
		$this->assertFalse($ms->zusammenfassung()['mannschaften'][0]['vollstaendig'], 'Mannschaft nach Entfernen unvollständig');

		// Nach Meldeschluss: schreibgeschützt.
		(new SportjahrRepository())->update($this->sid, ['meldeschluss' => '2020-01-10 22:59:00']);
		$ms2 = new MeldungService($this->verein, $this->sid);
		$this->assertFalse($ms2->phase()['schreibbar']);
		try {
			$ms2->einzel_anlegen($h4, $lg_id);
			$this->fail();
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Meldeschluss', $e->getMessage());
		}

		// PDF-HTML enthält die Meldung; mPDF-Erzeugung nur, wenn installiert.
		$html = (new \KSV\KMM\Application\PdfMeldung())->html($ms2->zusammenfassung());
		$this->assertStringContainsString('Eins, Test', $html);
		$this->assertStringContainsString('1.10.10', $html);
		if (\KSV\KMM\Application\Pdf::verfuegbar()) {
			$this->assertStringStartsWith('%PDF', (new \KSV\KMM\Application\PdfMeldung())->erzeugen($ms2->zusammenfassung()));
		}
	}

	public function test_mixteam_und_para(): void {
		$ms = new MeldungService($this->verein, $this->sid);
		$jm = $this->schuetze('Junge', '2011-01-01', 'm', '123450001');
		$jw = $this->schuetze('Mädchen', '2009-01-01', 'w', '123450002');
		$hw = $this->schuetze('Frau', '1990-01-01', 'w', '123450003');
		$mix = $this->disziplin('1.12');
		$a = $ms->einzel_anlegen($jm, $mix);
		$this->assertSame('Team Junioren', $a['startklasse']);
		$this->assertSame(0.0, $a['startgeld']);
		$b = $ms->einzel_anlegen($jw, $mix);
		$c = $ms->einzel_anlegen($hw, $mix);
		$this->assertContains('Junge', array_column($ms->kandidaten($mix), 'nachname'));
		try {
			$ms->mannschaft_speichern(null, $mix, [$a['id'], $c['id']]);
			$this->fail();
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('verschiedenen Mannschaftsklassen', $e->getMessage());
		}
		$t = $ms->mannschaft_speichern(null, $mix, [$a['id'], $b['id']]);
		$this->assertTrue($t['vollstaendig']);

		// Para: Klasse 92 in 1.10 wählbar, nur an der Meldung gespeichert.
		$hm = $this->schuetze('Para', '1980-01-01', 'm', '123450004');
		$para = array_values(array_filter(RegelwerkLader::engine($this->sid)->regelwerk()->para_klassen(), static fn($k) => $k->nummer === 92))[0];
		$p = $ms->einzel_anlegen($hm, $this->disziplin('1.10'), $para->id);
		$this->assertSame('1.10.92', $p['kennzahl_voll']);
		$this->assertSame('SH1/AB1 m ohne HM', $p['para']);
		$this->assertNull($p['mannschaftspool']);
		$liste = (new SchuetzeService($this->verein, $this->sid))->liste();
		$this->assertArrayNotHasKey('para', $liste[0], 'Para nicht in der Schützenliste');
	}

	public function test_fremder_verein_hat_keinen_zugriff(): void {
		$ms = new MeldungService($this->verein, $this->sid);
		$s = $this->schuetze('Eigen', '1990-01-01', 'm');
		$e = $ms->einzel_anlegen($s, $this->disziplin('1.10'));
		$fremd_id = (new VereinService())->speichern(0, 'SV Fremd', '99999', [], true);
		$fremd = (array) (new VereinRepository())->find($fremd_id);
		$fremd['sportjahr_id'] = $this->sid;
		$ms_fremd = new MeldungService($fremd, $this->sid);
		$this->assertSame([], $ms_fremd->zusammenfassung()['einzelmeldungen']);
		foreach ([
			fn() => $ms_fremd->einzel_loeschen($e['id']),
			fn() => $ms_fremd->einzel_aendern($e['id'], ['meldeergebnis' => '1']),
			fn() => $ms_fremd->einzel_anlegen($s, $this->disziplin('1.22')),
			fn() => (new SchuetzeService($fremd, $this->sid))->loeschen($s),
		] as $fn) {
			try {
				$fn();
				$this->fail('Fremdzugriff muss scheitern');
			} catch (\RuntimeException $ex) {
				$this->assertStringContainsString('nicht gefunden', $ex->getMessage());
			}
		}
	}
}
