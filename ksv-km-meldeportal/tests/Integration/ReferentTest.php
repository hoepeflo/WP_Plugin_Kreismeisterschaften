<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Integration;

use KSV\KMM\Application\MeldungService;
use KSV\KMM\Application\PdfMeldelisten;
use KSV\KMM\Application\ReferentService;
use KSV\KMM\Application\RegeltabelleImporter;
use KSV\KMM\Application\SchuetzeService;
use KSV\KMM\Application\SportjahrService;
use KSV\KMM\Application\VereinService;
use KSV\KMM\Auth\Capabilities;
use KSV\KMM\Auth\Rechte;
use KSV\KMM\Domain\Regeltabelle\Dokument;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\ReferentRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;

/**
 * Phase 2, Meilenstein 6: Referenten – Verwaltung und serverseitige Rechteprüfung.
 */
final class ReferentTest extends IntegrationTestCase {

	private int $sid;
	/** @var array<string, mixed> */
	private array $verein;
	/** @var array<string, int> */
	private array $em = [];
	private int $schuetze_a;
	private int $user_id;

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
		$this->schuetze_a = $ss->speichern(0, ['nachname' => 'A', 'vorname' => 'X', 'geburtsdatum' => '1990-01-01', 'geschlecht' => 'm', 'mitgliedsnummer' => '123450001'])['id'];
		$this->em['lg'] = $ms->einzel_anlegen($this->schuetze_a, $rw->disziplin_nach_kennzahl('1.10')->id)['id'];
		$this->em['lp'] = $ms->einzel_anlegen($this->schuetze_a, $rw->disziplin_nach_kennzahl('2.10')->id)['id'];
		$ms->ansprechpartner_speichern(['name' => 'SL', 'email' => 'sl@example.org']);
		$ms->einreichen();
		$uid = wp_insert_user(['user_login' => 'ref_gewehr', 'user_pass' => 'x', 'role' => Capabilities::ROLE_REFERENT, 'display_name' => 'Ref Gewehr']);
		$this->assertIsInt($uid);
		$this->user_id = $uid;
	}

	protected function tearDown(): void {
		remove_all_filters('pre_wp_mail');
		wp_set_current_user(1);
		wp_delete_user($this->user_id);
		Rechte::cache_leeren();
	}

	public function test_verwaltung_prueft_rolle_und_zustaendigkeit(): void {
		$service = new ReferentService();
		$this->assertCount(1, $service->kandidaten());
		try {
			$service->speichern(0, $this->user_id, [], [], []);
			$this->fail('ohne Zuständigkeit');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('mindestens', $e->getMessage());
		}
		$abo = wp_insert_user(['user_login' => 'abonnent', 'user_pass' => 'x', 'role' => 'subscriber']);
		try {
			$service->speichern(0, (int) $abo, [], ['freihand'], []);
			$this->fail('falsche Rolle');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('KM-Referent', $e->getMessage());
		}
		wp_delete_user((int) $abo);

		$id = $service->speichern(0, $this->user_id, ['darf_status' => true], ['freihand'], ['2.10'], 'Gewehr und LP');
		$liste = $service->liste();
		$this->assertCount(1, $liste);
		$this->assertSame('Ref Gewehr', $liste[0]['name']);
		$this->assertTrue($liste[0]['rolle_ok']);
		$this->assertCount(2, $liste[0]['zustaendigkeiten']);
		$this->assertCount(0, $service->kandidaten(), 'bereits eingetragen');
		try {
			$service->speichern(0, $this->user_id, [], ['auflage'], []);
			$this->fail('doppelt');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('bereits', $e->getMessage());
		}
		$service->speichern($id, $this->user_id, ['darf_meldungen' => true], [], ['1.10']);
		$r = (new ReferentRepository())->find($id);
		$this->assertFalse($r['darf_status']);
		$this->assertTrue($r['darf_meldungen']);
		$this->assertCount(1, $service->liste()[0]['zustaendigkeiten']);
		$service->loeschen($id);
		$this->assertSame([], $service->liste());
		$this->assertCount(1, $service->kandidaten());
	}

	public function test_referent_ohne_schreibrecht_und_fremde_disziplin_serverseitig_abgelehnt(): void {
		$service = new ReferentService();
		$id = $service->speichern(0, $this->user_id, [], [], ['1.10']);
		wp_set_current_user($this->user_id);
		Rechte::cache_leeren();
		$this->assertTrue(Rechte::darf_lesen());
		$this->assertFalse(Rechte::hat_recht(Rechte::RECHT_MELDUNGEN));

		// Admin-Modus ohne Recht „Meldungen bearbeiten“: FrontController/REST lehnen über AdminModus ab.
		$this->assertFalse(\KSV\KMM\Http\AdminModus::erlaubt());

		// Recht freischalten: eigene Disziplin geht, fremde nicht.
		wp_set_current_user(1);
		$service->speichern($id, $this->user_id, ['darf_meldungen' => true], [], ['1.10']);
		wp_set_current_user($this->user_id);
		Rechte::cache_leeren();
		$this->assertTrue(\KSV\KMM\Http\AdminModus::erlaubt());
		$admin = new MeldungService($this->verein, $this->sid, true);
		$admin->einzel_aendern($this->em['lg'], ['meldeergebnis' => '380']);
		foreach ([
			fn() => $admin->einzel_aendern($this->em['lp'], ['meldeergebnis' => '370']),
			fn() => $admin->abmelden($this->em['lp'], 'x'),
			fn() => $admin->einzel_loeschen($this->em['lp']),
			fn() => $admin->einzel_anlegen($this->schuetze_a, RegelwerkLader::engine($this->sid)->regelwerk()->disziplin_nach_kennzahl('2.20')->id),
			fn() => $admin->nachmeldung_freischalten('2099-01-01 00:00:00'),
		] as $i => $fn) {
			try {
				$fn();
				$this->fail('Aktion ' . $i . ' außerhalb der Zuständigkeit muss abgelehnt werden');
			} catch (\RuntimeException $e) {
				$this->assertMatchesRegularExpression('/Zuständigkeit|Administratoren/', $e->getMessage(), 'Aktion ' . $i);
			}
		}
		$z = $admin->zusammenfassung();
		$this->assertFalse($z['admin_voll']);
		$flags = [];
		foreach ($z['einzelmeldungen'] as $e) {
			$flags[ $e['kennzahl'] ] = $e['zustaendig'];
		}
		$this->assertSame(['1.10' => true, '2.10' => false], $flags);
		$this->assertSame(['1.10'], array_column($admin->angebot($this->schuetze_a), 'kennzahl'), 'Angebot nur zuständige Disziplinen');
	}

	public function test_pdf_listen_nur_eigener_bereich(): void {
		(new ReferentService())->speichern(0, $this->user_id, [], [], ['2.10']);
		wp_set_current_user($this->user_id);
		Rechte::cache_leeren();
		$r = (new PdfMeldelisten())->erzeugen($this->sid, 'alle', null, null, PdfMeldelisten::GRUPPIERUNG_VEREIN, true);
		$this->assertSame(1, $r['zeilen'], 'nur 2.10, nicht 1.10');
		$r = (new PdfMeldelisten())->erzeugen($this->sid, 'gruppe', null, 'freihand', PdfMeldelisten::GRUPPIERUNG_VEREIN, true);
		$this->assertSame(1, $r['zeilen'], 'Gruppe Freihand enthält 1.10 und 2.10, Referent sieht nur 2.10');
		wp_set_current_user(1);
		Rechte::cache_leeren();
		$this->assertSame(2, (new PdfMeldelisten())->erzeugen($this->sid, 'alle', null, null, PdfMeldelisten::GRUPPIERUNG_VEREIN, true)['zeilen']);
	}
}
