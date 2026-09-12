<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Integration;

use KSV\KMM\Application\ExportService;
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
use KSV\KMM\Infrastructure\Repository\AenderungRepository;
use KSV\KMM\Infrastructure\Repository\BuchungRepository;
use KSV\KMM\Infrastructure\Repository\EinzelmeldungRepository;
use KSV\KMM\Infrastructure\Repository\MannschaftRepository;
use KSV\KMM\Infrastructure\Repository\ReferentRepository;
use KSV\KMM\Infrastructure\Repository\ReferentZustaendigkeitRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;
use KSV\KMM\Support\Clock;

final class VerarbeitungTest extends IntegrationTestCase {

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
		$this->verein = (array) (new VereinRepository())->find($vid);
		$this->verein['sportjahr_id'] = $this->sid;
		$ss = new SchuetzeService($this->verein, $this->sid);
		$ms = new MeldungService($this->verein, $this->sid);
		$rw = RegelwerkLader::engine($this->sid)->regelwerk();
		$lg = $rw->disziplin_nach_kennzahl('1.10')->id;
		$ids = [];
		foreach ([['A', '1990-01-01'], ['B', '1985-01-01'], ['C', '1980-01-01']] as $i => [$n, $g]) {
			$sid = $ss->speichern(0, ['nachname' => $n, 'vorname' => 'X', 'geburtsdatum' => $g, 'geschlecht' => 'm', 'mitgliedsnummer' => '12345000' . ($i + 1)])['id'];
			$this->em[ $n ] = $ms->einzel_anlegen($sid, $lg)['id'];
			$ids[] = $this->em[ $n ];
			if ($n === 'A') {
				$this->em['A22'] = $ms->einzel_anlegen($sid, $rw->disziplin_nach_kennzahl('1.22')->id)['id'];
				$this->em['A11'] = $ms->einzel_anlegen($sid, $rw->disziplin_nach_kennzahl('2.10')->id)['id'];
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

	public function test_status_setzen_sammelaktion_und_folgen(): void {
		$service = new VerarbeitungService($this->sid);
		$this->assertCount(5, $service->liste());
		$this->assertCount(3, $service->liste(['disziplin_id' => RegelwerkLader::engine($this->sid)->regelwerk()->disziplin_nach_kennzahl('1.10')->id]));
		$this->assertCount(5, $service->liste(['gruppe' => 'freihand', 'verein_id' => (int) $this->verein['id']]));
		$this->assertCount(0, $service->liste(['gruppe' => 'auflage']));

		// Buchung simulieren, die bei „nicht startberechtigt" frei werden muss.
		(new BuchungRepository())->insert(['sportjahr_id' => $this->sid, 'wettkampftag_id' => 1, 'durchgang_id' => 1, 'einheit_id' => 1, 'position' => 1, 'einzelmeldung_id' => $this->em['B'], 'verein_id' => (int) $this->verein['id'], 'gebucht_am' => Clock::now_utc()]);

		try {
			$service->setzen($this->em['B'], Verarbeitungsstatus::NICHT_STARTBERECHTIGT, '');
			$this->fail();
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Grund', $e->getMessage());
		}
		$service->setzen($this->em['B'], Verarbeitungsstatus::NICHT_STARTBERECHTIGT, 'kein Startrecht für diesen Verein');
		$b = (new EinzelmeldungRepository())->find($this->em['B']);
		$this->assertSame('nicht_startberechtigt', $b['verarbeitungsstatus']);
		$this->assertNotNull($b['verarbeitet_am']);
		$this->assertTrue((new MannschaftRepository())->find((int) $b['mannschaft_id'])['unvollstaendig'], 'Mannschaft unvollständig markiert');
		$this->assertNull((new BuchungRepository())->by_einzelmeldung($this->em['B']), 'Startplatz freigegeben');
		$this->assertCount(1, (new AenderungRepository())->offen_je_verein((int) $this->verein['id']));

		// Sammelaktion nur auf 1.10: A und C werden verarbeitet, B bleibt, 1.22/2.10 bleiben ungeprüft.
		$n = $service->sammel_verarbeitet(['disziplin_id' => RegelwerkLader::engine($this->sid)->regelwerk()->disziplin_nach_kennzahl('1.10')->id]);
		$this->assertSame(2, $n);
		$repo = new EinzelmeldungRepository();
		$this->assertSame('verarbeitet', $repo->find($this->em['A'])['verarbeitungsstatus']);
		$this->assertSame('nicht_startberechtigt', $repo->find($this->em['B'])['verarbeitungsstatus']);
		$this->assertSame('ungeprueft', $repo->find($this->em['A22'])['verarbeitungsstatus']);

		// Vereinsstatus bleibt eingereicht, bis alle geprüft sind.
		$ms = new MeldungService($this->verein, $this->sid);
		$this->assertSame('eingereicht', $ms->zusammenfassung()['status']);
		$service->sammel_verarbeitet([]);
		$z = $ms->zusammenfassung();
		$this->assertSame('verarbeitet', $z['status']);
		$eb = array_values(array_filter($z['einzelmeldungen'], fn(array $e): bool => $e['id'] === $this->em['B']))[0];
		$this->assertSame('nicht_startberechtigt', $eb['verarbeitungsstatus']);
		$this->assertSame('kein Startrecht für diesen Verein', $eb['verarbeitungsgrund']);

		// Nicht startberechtigt fällt aus PDF-Listen/Export heraus.
		$zeilen = (new ExportService())->zeilen($this->sid, null, null, true, false);
		$this->assertNotContains('B', array_map(static fn($r) => $r->nachname, $zeilen));
		$this->assertCount(4, $zeilen);

		// Zurück auf verarbeitet: Mannschaft wieder vollständig.
		$service->setzen($this->em['B'], Verarbeitungsstatus::VERARBEITET);
		$this->assertFalse((new MannschaftRepository())->find((int) $b['mannschaft_id'])['unvollstaendig']);
	}

	public function test_referent_rechte_serverseitig(): void {
		$user_id = wp_insert_user(['user_login' => 'referent1', 'user_pass' => 'x', 'role' => 'kmm_referent']);
		$this->assertIsInt($user_id);
		$ref_id = (new ReferentRepository())->insert(['user_id' => $user_id, 'darf_status' => 0]);
		(new ReferentZustaendigkeitRepository())->setzen($ref_id, [['typ' => 'disziplin', 'schluessel' => '1.10']]);
		wp_set_current_user($user_id);
		Rechte::cache_leeren();

		$this->assertTrue(Rechte::darf_lesen());
		$this->assertFalse(Rechte::ist_admin());
		$service = new VerarbeitungService($this->sid);
		$this->assertSame(['1.10', '1.10', '1.10'], array_map(static fn(array $z) => $z['disziplin']->kennzahl, $service->liste()), 'nur zuständige Disziplin sichtbar');
		try {
			$service->setzen($this->em['A'], Verarbeitungsstatus::VERARBEITET);
			$this->fail('ohne darf_status');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Kein Recht', $e->getMessage());
		}
		(new ReferentRepository())->update($ref_id, ['darf_status' => 1]);
		Rechte::cache_leeren();
		$service->setzen($this->em['A'], Verarbeitungsstatus::VERARBEITET);
		try {
			$service->setzen($this->em['A22'], Verarbeitungsstatus::VERARBEITET);
			$this->fail('fremde Disziplin');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Zuständigkeit', $e->getMessage());
		}
		$this->assertSame(2, $service->sammel_verarbeitet(['gruppe' => 'freihand']), 'Sammelaktion nur im Zuständigkeitsbereich: B und C');
		$this->assertSame('ungeprueft', (new EinzelmeldungRepository())->find($this->em['A22'])['verarbeitungsstatus'], 'fremde Disziplin unberührt');
		wp_delete_user($user_id);
	}
}
