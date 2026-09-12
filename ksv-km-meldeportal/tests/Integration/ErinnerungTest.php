<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Integration;

use KSV\KMM\Application\Erinnerung;
use KSV\KMM\Application\SportjahrService;
use KSV\KMM\Application\VereinService;
use KSV\KMM\Infrastructure\Repository\MagicLinkRepository;
use KSV\KMM\Infrastructure\Repository\MeldungRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Support\Clock;

final class ErinnerungTest extends IntegrationTestCase {

	/** @var array<int, array<string, mixed>> */
	private array $mails = [];

	protected function setUp(): void {
		parent::setUp();
		add_filter('pre_wp_mail', function ($null, array $atts) {
			$this->mails[] = $atts;
			return true;
		}, 10, 2);
	}

	protected function tearDown(): void {
		remove_all_filters('pre_wp_mail');
	}

	public function test_erinnerung_nur_an_offene_und_entwurf_und_nur_einmal(): void {
		$sid = (new SportjahrService())->anlegen(2027);
		$repo = new SportjahrRepository();
		$repo->update($sid, ['meldeschluss' => '2099-01-10 22:59:00', 'erinnerung_am' => '2000-01-01 00:00:00']);
		$offen = (new VereinService())->speichern(0, 'SV Offen', '10001', ['offen@example.org'], true);
		$entwurf = (new VereinService())->speichern(0, 'SV Entwurf', '10002', ['entwurf@example.org'], true);
		$fertig = (new VereinService())->speichern(0, 'SV Fertig', '10003', ['fertig@example.org'], true);
		(new VereinService())->speichern(0, 'SV Ohne Mail', '10004', [], true);
		(new VereinService())->speichern(0, 'SV Inaktiv', '10005', ['inaktiv@example.org'], false);
		$m = new MeldungRepository();
		$m->insert(['verein_id' => $entwurf, 'sportjahr_id' => $sid, 'status' => 'entwurf']);
		$m->insert(['verein_id' => $fertig, 'sportjahr_id' => $sid, 'status' => 'eingereicht', 'eingereicht_am' => Clock::now_utc()]);

		$this->assertTrue(Erinnerung::faellig((array) $repo->find($sid)));
		$this->assertEqualsCanonicalizing(['SV Offen', 'SV Entwurf'], array_column(Erinnerung::empfaenger($sid), 'name'));

		Erinnerung::cron();
		$this->assertCount(2, $this->mails);
		$texte = implode("\n", array_column($this->mails, 'message'));
		$this->assertStringContainsString('noch keine Meldung vor', $texte);
		$this->assertStringContainsString('noch nicht eingereicht', $texte);
		$this->assertMatchesRegularExpression('#/km-meldung/zugang/[a-f0-9]{64}/#', $this->mails[0]['message']);
		$this->assertCount(1, (new MagicLinkRepository())->where(['verein_id' => $offen, 'anlass' => 'erinnerung']));
		$this->assertNotNull($repo->find($sid)['erinnerung_gesendet_am']);

		// Zweiter Cron-Lauf sendet nicht erneut.
		Erinnerung::cron();
		$this->assertCount(2, $this->mails);
		$this->assertFalse(Erinnerung::faellig((array) $repo->find($sid)));

		// Nach Meldeschluss nicht mehr fällig, auch wenn zurückgesetzt.
		$repo->update($sid, ['erinnerung_gesendet_am' => null, 'meldeschluss' => '2001-01-01 00:00:00']);
		$this->assertFalse(Erinnerung::faellig((array) $repo->find($sid)));
	}
}
