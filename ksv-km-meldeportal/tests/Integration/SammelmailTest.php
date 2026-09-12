<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Integration;

use KSV\KMM\Application\MeldungService;
use KSV\KMM\Application\RegeltabelleImporter;
use KSV\KMM\Application\Sammelmail;
use KSV\KMM\Application\SchuetzeService;
use KSV\KMM\Application\SportjahrService;
use KSV\KMM\Application\VereinService;
use KSV\KMM\Cron;
use KSV\KMM\Domain\Regeltabelle\Dokument;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\AenderungRepository;
use KSV\KMM\Infrastructure\Repository\MailLogRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;
use KSV\KMM\Support\Settings;

/**
 * Phase 2, Meilenstein 4: tägliche Sammelmail (idempotent, abschaltbar).
 */
final class SammelmailTest extends IntegrationTestCase {

	private int $sid;
	/** @var array<string, mixed> */
	private array $verein;
	/** @var array<string, mixed> */
	private array $verein2;
	/** @var array<string, int> */
	private array $em = [];
	/** @var list<array<string, mixed>> */
	private array $mails = [];

	protected function setUp(): void {
		parent::setUp();
		delete_option(Sammelmail::OPTION_LAUF);
		Settings::update(['sammelmail_aktiv' => true, 'sammelmail_uhrzeit' => '18:00']);
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
		$vid = (new VereinService())->speichern(0, 'SV Test', '12345', ['sport@example.org'], true);
		$this->verein = (array) (new VereinRepository())->find($vid);
		$this->verein['sportjahr_id'] = $this->sid;
		$vid2 = (new VereinService())->speichern(0, 'SV Zwei', '12346', ['zwei@example.org'], true);
		$this->verein2 = (array) (new VereinRepository())->find($vid2);
		$this->verein2['sportjahr_id'] = $this->sid;
		$ss = new SchuetzeService($this->verein, $this->sid);
		$ms = new MeldungService($this->verein, $this->sid);
		$lg = RegelwerkLader::engine($this->sid)->regelwerk()->disziplin_nach_kennzahl('1.10')->id;
		foreach ([['A', '1990-01-01'], ['B', '1985-01-01']] as $i => [$n, $g]) {
			$s = $ss->speichern(0, ['nachname' => $n, 'vorname' => 'X', 'geburtsdatum' => $g, 'geschlecht' => 'm', 'mitgliedsnummer' => '12345000' . ($i + 1)])['id'];
			$this->em[ $n ] = $ms->einzel_anlegen($s, $lg)['id'];
		}
		$ms->ansprechpartner_speichern(['name' => 'SL', 'email' => 'sl@example.org']);
		$ms->einreichen();
		$this->mails = []; // Bestätigungsmail nicht mitzählen
		(new SportjahrRepository())->update($this->sid, ['meldeschluss' => '2026-01-10 22:59:00']);
	}

	protected function tearDown(): void {
		remove_all_filters('pre_wp_mail');
		delete_option(Sammelmail::OPTION_LAUF);
		Settings::update(['sammelmail_aktiv' => true, 'sammelmail_uhrzeit' => '18:00']);
		wp_set_current_user(1);
	}

	private function admin(): MeldungService {
		return new MeldungService($this->verein, $this->sid, true);
	}

	public function test_sammelmail_einmal_je_verein_und_nicht_doppelt(): void {
		$admin = $this->admin();
		$admin->abmelden($this->em['A'], 'verletzt');
		$admin->einzel_aendern($this->em['B'], ['meldeergebnis' => '370']);
		$this->assertCount(2, (new AenderungRepository())->offen_je_verein((int) $this->verein['id']));

		$r = Sammelmail::senden($this->sid, false);
		$this->assertSame(['gesendet' => 1, 'aenderungen' => 2, 'fehler' => []], $r);
		$this->assertCount(1, $this->mails, 'eine Mail für zwei Änderungen');
		$mail = $this->mails[0];
		$this->assertSame(['sl@example.org', 'sport@example.org'], $mail['to'], 'Ansprechpartner zuerst, dann Vereinsadressen');
		$this->assertStringContainsString('2 Änderungen', (string) $mail['subject']);
		$this->assertStringContainsString('Abmeldung: A, X in 1.10 abgemeldet', (string) $mail['message']);
		$this->assertStringContainsString('verletzt', (string) $mail['message']);
		$this->assertStringContainsString('Korrektur: B, X in 1.10: Meldeergebnis 370', (string) $mail['message']);
		$this->assertMatchesRegularExpression('#/zugang/[a-f0-9]{64}/#', (string) $mail['message'], 'Zugangslink enthalten');
		$this->assertCount(0, (new AenderungRepository())->offen_je_verein((int) $this->verein['id']));
		$this->assertCount(1, (new MailLogRepository())->where(['typ' => 'sammelmail']));

		// Zweiter Lauf: nichts mehr offen, keine zweite Mail.
		$r = Sammelmail::senden($this->sid, false);
		$this->assertSame(0, $r['gesendet']);
		$this->assertCount(1, $this->mails);

		// Verein ohne Änderungen bekommt nichts.
		$this->assertCount(0, array_filter($this->mails, fn(array $m): bool => in_array('zwei@example.org', (array) $m['to'], true)));
	}

	public function test_cron_zweimal_hintereinander_sendet_nur_einmal(): void {
		$this->admin()->abmelden($this->em['A'], 'krank');
		// Uhrzeit auf „schon erreicht“ setzen: 00:00 Ortszeit ist immer vorbei.
		Settings::update(['sammelmail_uhrzeit' => '00:00']);
		$this->assertTrue(Sammelmail::faellig());
		Sammelmail::cron();
		$this->assertCount(1, $this->mails);
		$this->assertFalse(Sammelmail::faellig(), 'heute schon gelaufen');
		Sammelmail::cron();
		Cron::run_daily();
		Cron::run_hourly();
		$this->assertCount(1, $this->mails, 'kein doppelter Versand');

		// Neue Änderung am selben Tag wartet bis zum nächsten Lauf; manueller Versand geht sofort.
		$this->admin()->abmeldung_aufheben($this->em['A']);
		Sammelmail::cron();
		$this->assertCount(1, $this->mails);
		$this->assertSame(1, Sammelmail::senden($this->sid, true)['gesendet']);
		$this->assertCount(2, $this->mails);
	}

	public function test_faellig_uhrzeit_und_schalter(): void {
		$tz = wp_timezone();
		$heute = (new \DateTimeImmutable('now', $tz))->setTime(12, 0);
		Settings::update(['sammelmail_uhrzeit' => '18:00']);
		$this->assertFalse(Sammelmail::faellig($heute->getTimestamp()), 'vor 18:00 nicht fällig');
		$this->assertTrue(Sammelmail::faellig($heute->setTime(18, 0)->getTimestamp()), 'ab 18:00 fällig');
		$this->assertTrue(Sammelmail::faellig($heute->setTime(23, 59)->getTimestamp()));
		Settings::update(['sammelmail_aktiv' => false]);
		$this->assertFalse(Sammelmail::faellig($heute->setTime(20, 0)->getTimestamp()), 'abgeschaltet');
		Settings::update(['sammelmail_aktiv' => true]);
		$naechster = (new \DateTimeImmutable('@' . Cron::naechster_taeglicher_lauf($heute->getTimestamp())))->setTimezone($tz);
		$this->assertSame($heute->format('Y-m-d') . ' 18:00', $naechster->format('Y-m-d H:i'));
		$naechster = (new \DateTimeImmutable('@' . Cron::naechster_taeglicher_lauf($heute->setTime(19, 0)->getTimestamp())))->setTimezone($tz);
		$this->assertSame($heute->modify('+1 day')->format('Y-m-d') . ' 18:00', $naechster->format('Y-m-d H:i'));
	}

	public function test_mailfehler_gibt_aenderungen_frei_und_ohne_adresse_bleiben_offen(): void {
		$this->admin()->abmelden($this->em['A'], 'x');
		remove_all_filters('pre_wp_mail');
		add_filter('pre_wp_mail', static fn() => false);
		$r = Sammelmail::senden($this->sid, false);
		$this->assertSame(0, $r['gesendet']);
		$this->assertCount(1, $r['fehler']);
		$this->assertCount(1, (new AenderungRepository())->offen_je_verein((int) $this->verein['id']), 'bleibt offen für den nächsten Lauf');

		// Verein ohne Adresse und ohne Ansprechpartner.
		(new VereinService())->speichern((int) $this->verein['id'], 'SV Test', '12345', [], true);
		(new \KSV\KMM\Infrastructure\Repository\MeldungRepository())->update((int) $this->admin()->meldung()['id'], ['ansprechpartner_email' => '']);
		$r = Sammelmail::senden($this->sid, false);
		$this->assertStringContainsString('keine E-Mail-Adresse', $r['fehler'][0]);
		$this->assertCount(1, (new AenderungRepository())->offen_je_verein((int) $this->verein['id']));
	}
}
