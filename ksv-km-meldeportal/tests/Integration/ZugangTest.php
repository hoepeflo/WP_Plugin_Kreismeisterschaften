<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Integration;

use KSV\KMM\Application\LinkAnfordern;
use KSV\KMM\Application\SportjahrService;
use KSV\KMM\Application\VereinService;
use KSV\KMM\Application\Zugang;
use KSV\KMM\Infrastructure\Repository\MagicLinkRepository;
use KSV\KMM\Infrastructure\Repository\MailLogRepository;
use KSV\KMM\Infrastructure\Repository\SitzungRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinEmailRepository;

final class ZugangTest extends IntegrationTestCase {

	/** @var array<int, array<string, mixed>> */
	private array $mails = [];

	protected function setUp(): void {
		parent::setUp();
		$this->mails = [];
		add_filter('pre_wp_mail', function ($null, array $atts) {
			$this->mails[] = $atts;
			return true;
		}, 10, 2);
		global $wpdb;
		$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_kmm_rl_%' OR option_name LIKE '_transient_timeout_kmm_rl_%'");
	}

	protected function tearDown(): void {
		remove_all_filters('pre_wp_mail');
		Zugang::cache_leeren();
		unset($_COOKIE[ Zugang::COOKIE ]);
	}

	public function test_verein_anlegen_validierung(): void {
		$service = new VereinService();
		$id = $service->speichern(0, 'SV Vorwalsrode', '12345', ['sport@example.org', 'SPORT@example.org', 'zweite@example.org'], true);
		$this->assertGreaterThan(0, $id);
		$this->assertSame(['sport@example.org', 'zweite@example.org'], (new VereinEmailRepository())->adressen($id));
		try {
			$service->speichern(0, 'Anderer', '12345', [], true);
			$this->fail();
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('bereits vergeben', $e->getMessage());
		}
		try {
			$service->speichern(0, 'Anderer', '1234', [], true);
			$this->fail();
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('5 Ziffern', $e->getMessage());
		}
		try {
			$service->speichern($id, 'SV Vorwalsrode', '12345', ['kein-mail'], true);
			$this->fail();
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Ungültige E-Mail', $e->getMessage());
		}
	}

	public function test_magic_link_lebenszyklus(): void {
		$sid = (new SportjahrService())->anlegen(2027);
		$verein_id = (new VereinService())->speichern(0, 'SV Test', '12345', ['sport@example.org'], true);

		$r = Zugang::link_senden($verein_id, $sid, Zugang::ANLASS_ADMIN);
		$this->assertTrue($r['ok']);
		$this->assertCount(1, $this->mails);
		$this->assertStringContainsString('/km-meldung/zugang/', $this->mails[0]['message']);
		$this->assertSame(['sport@example.org'], (array) $this->mails[0]['to']);
		preg_match('#/km-meldung/zugang/([a-f0-9]{64})/#', $this->mails[0]['message'], $m);
		$token = $m[1];

		// Nur der Hash ist gespeichert.
		$link = (new MagicLinkRepository())->where(['verein_id' => $verein_id])[0];
		$this->assertSame(hash('sha256', $token), $link['token_hash']);
		$this->assertStringNotContainsString($token, (string) json_encode($link));
		$this->assertStringContainsString('2027-12-31', (string) $link['gueltig_bis']);

		// Einlösen erzeugt eine Sitzung; das Cookie können wir hier nur simulieren.
		$this->assertNull(Zugang::link_einloesen('falsch'));
		$this->assertNull(Zugang::link_einloesen(str_repeat('a', 64)));
		$verein = Zugang::link_einloesen($token);
		$this->assertSame('SV Test', $verein['name']);
		$sitzung = (new SitzungRepository())->where(['verein_id' => $verein_id])[0];
		$this->assertSame(1, (int) (new MagicLinkRepository())->find((int) $link['id'])['verwendungen']);

		// Sitzung über Cookie prüfen: Hash muss zum Cookie passen (wir kennen das Token nicht, also Sitzung manuell).
		$sitzung_token = Zugang::token_erzeugen();
		(new SitzungRepository())->update((int) $sitzung['id'], ['token_hash' => Zugang::hash($sitzung_token)]);
		$_COOKIE[ Zugang::COOKIE ] = $sitzung_token;
		Zugang::cache_leeren();
		$aktuell = Zugang::aktueller_verein();
		$this->assertSame($verein_id, (int) $aktuell['id']);
		$this->assertSame($sid, $aktuell['sportjahr_id']);

		// Neu senden widerruft Link und Sitzung.
		Zugang::link_senden($verein_id, $sid, Zugang::ANLASS_ADMIN);
		Zugang::cache_leeren();
		$this->assertNull(Zugang::aktueller_verein(), 'Sitzung nach Widerruf ungültig');
		$this->assertNull(Zugang::link_einloesen($token), 'alter Link ungültig');
		$this->assertNotNull((new MagicLinkRepository())->find((int) $link['id'])['widerrufen_am']);

		// Abgeschlossenes Sportjahr sperrt den Zugang.
		preg_match('#/km-meldung/zugang/([a-f0-9]{64})/#', $this->mails[1]['message'], $m);
		(new SportjahrRepository())->update($sid, ['abgeschlossen_am' => '2027-06-01 00:00:00']);
		$this->assertNull(Zugang::link_einloesen($m[1]));
		(new SportjahrRepository())->update($sid, ['abgeschlossen_am' => null]);
		$this->assertNotNull(Zugang::link_einloesen($m[1]));

		// Inaktiver Verein.
		(new VereinService())->speichern($verein_id, 'SV Test', '12345', ['sport@example.org'], false);
		$this->assertNull(Zugang::link_einloesen($m[1]));
	}

	public function test_link_anfordern_neutral_und_rate_limit(): void {
		$sid = (new SportjahrService())->anlegen(2027);
		$verein_id = (new VereinService())->speichern(0, 'SV Test', '12345', ['sport@example.org'], true);
		(new VereinService())->speichern(0, 'SV Zwei', '22222', ['sport@example.org'], true);

		$this->assertTrue(LinkAnfordern::verarbeiten('unbekannt@example.org', '10.0.0.1'));
		$this->assertCount(0, $this->mails, 'unbekannte Adresse: keine Mail, gleiche Antwort');
		$this->assertTrue(LinkAnfordern::verarbeiten('Sport@Example.org', '10.0.0.1'));
		$this->assertCount(2, $this->mails, 'beide Vereine mit dieser Adresse');
		$this->assertCount(1, (new MagicLinkRepository())->where(['verein_id' => $verein_id, 'widerrufen_am' => null]));
		$this->assertTrue(LinkAnfordern::verarbeiten('sport@example.org', '10.0.0.1'));
		$this->assertCount(2, (new MagicLinkRepository())->where(['verein_id' => $verein_id, 'widerrufen_am' => null]), 'Anfrage widerruft alte Links nicht');

		// Rate-Limit je IP (Standard 5/h): 3 Anfragen gestellt, zwei weitere gehen, die sechste nicht.
		$this->assertTrue(LinkAnfordern::verarbeiten('x@example.org', '10.0.0.1'));
		$this->assertTrue(LinkAnfordern::verarbeiten('y@example.org', '10.0.0.1'));
		$this->assertFalse(LinkAnfordern::verarbeiten('z@example.org', '10.0.0.1'));
		$this->assertTrue(LinkAnfordern::verarbeiten('z@example.org', '10.0.0.2'), 'andere IP');
		// Rate-Limit je Adresse.
		for ($i = 0; $i < 5; $i++) {
			LinkAnfordern::verarbeiten('viel@example.org', '10.0.1.' . $i);
		}
		$this->assertFalse(LinkAnfordern::verarbeiten('viel@example.org', '10.0.2.1'));

		$logs = (new MailLogRepository())->where(['typ' => 'magic_link']);
		$this->assertGreaterThanOrEqual(4, count($logs));
		$this->assertStringNotContainsString('example.org', (string) json_encode($logs), 'keine Adressen im Mail-Protokoll');
	}
}
