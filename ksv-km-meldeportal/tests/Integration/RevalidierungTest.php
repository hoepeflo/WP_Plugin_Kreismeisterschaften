<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Integration;

use KSV\KMM\Application\RegeltabelleImporter;
use KSV\KMM\Application\Revalidierung;
use KSV\KMM\Application\SportjahrService;
use KSV\KMM\Domain\Regeltabelle\Dokument;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\DisziplinRepository;
use KSV\KMM\Infrastructure\Repository\EinzelmeldungRepository;
use KSV\KMM\Infrastructure\Repository\HoehermeldungRepository;
use KSV\KMM\Infrastructure\Repository\KlasseRepository;
use KSV\KMM\Infrastructure\Repository\MannschaftRepository;
use KSV\KMM\Infrastructure\Repository\RegelRepository;
use KSV\KMM\Infrastructure\Repository\SchuetzeRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Support\Clock;

final class RevalidierungTest extends IntegrationTestCase {

	public function test_regelaenderung_markiert_konflikte(): void {
		$service = new SportjahrService();
		$sid = $service->anlegen(2027);
		(new SportjahrRepository())->update($sid, ['meldeschluss' => '2027-01-10 22:59:00']);
		$json = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/regeltabelle-2026.json');
		remove_all_actions('kmm_regeln_geaendert');
		(new RegeltabelleImporter())->importieren($sid, Dokument::fromJson($json));
		add_action('kmm_regeln_geaendert', [Revalidierung::class, 'sportjahr']);

		$engine = RegelwerkLader::engine($sid, true);
		$rw = $engine->regelwerk();
		$lg = $rw->disziplin_nach_kennzahl('1.10');
		$this->assertNotNull($lg);

		// Verein, Schütze (Herren II, 42), Meldung, Einzelmeldung mit korrekter Bewertung.
		global $wpdb;
		$wpdb->insert($wpdb->prefix . 'kmm_verein', ['name' => 'SV Test', 'vn_nummer' => '12345', 'created_at' => Clock::now_utc(), 'updated_at' => Clock::now_utc()]);
		$verein_id = (int) $wpdb->insert_id;
		$schuetze_id = (new SchuetzeRepository())->insert(['verein_id' => $verein_id, 'nachname' => 'Muster', 'vorname' => 'Max', 'geburtsdatum' => '1985-06-15', 'geschlecht' => 'm', 'mitgliedsnummer' => '123450001']);
		$wpdb->insert($wpdb->prefix . 'kmm_meldung', ['verein_id' => $verein_id, 'sportjahr_id' => $sid, 'status' => 'entwurf', 'created_at' => Clock::now_utc(), 'updated_at' => Clock::now_utc()]);
		$meldung_id = (int) $wpdb->insert_id;

		$b = $engine->bewerte($lg, new \KSV\KMM\Domain\Engine\Schuetze('1985-06-15', 'm'));
		$this->assertSame('1.10.12', $b->kennzahl);
		$einzel = new EinzelmeldungRepository();
		$em_id = $einzel->insert(array_merge([
			'meldung_id' => $meldung_id, 'sportjahr_id' => $sid, 'verein_id' => $verein_id, 'schuetze_id' => $schuetze_id,
			'disziplin_id' => $lg->id, 'geschlecht' => 'm', 'geburtsjahr' => 1985,
		], Revalidierung::felder($b)));
		$mannschaft_id = (new MannschaftRepository())->insert(['meldung_id' => $meldung_id, 'sportjahr_id' => $sid, 'verein_id' => $verein_id, 'disziplin_id' => $lg->id, 'klasse_id' => $b->mannschaft_klasse->id, 'nummer' => 1]);
		$einzel->update($em_id, ['mannschaft_id' => $mannschaft_id]);

		// Unverändert: keine Konflikte.
		$r = Revalidierung::sportjahr($sid);
		$this->assertSame(['geprueft' => 1, 'geaendert' => 0, 'konflikte' => 0], $r);

		// Regeländerung: Herren II bekommen in 1.10 eine eigene Mannschaftswertung.
		$k12 = (new KlasseRepository())->by_key((int) (new DisziplinRepository())->find($lg->id)['gruppe_id'], 12, 'm');
		$regel = (new RegelRepository())->by_key($lg->id, (int) $k12['id']);
		(new RegelRepository())->update((int) $regel['id'], ['mannschaft_modus' => 'eigen', 'mannschaft_ziel_klasse_id' => null]);
		$service->regeln_geaendert($sid); // löst die Action aus

		$em = $einzel->find($em_id);
		$this->assertTrue($em['konflikt']);
		$this->assertStringContainsString('Mannschaftspool: Herren I → Herren II', $em['konflikt_text']);
		$this->assertSame((int) $k12['id'], $em['mannschaft_klasse_id']);
		$this->assertTrue((new MannschaftRepository())->find($mannschaft_id)['unvollstaendig']);

		// Höhermeldung auf Herren I: Startklasse und Startgeld bleiben, Klasse wechselt.
		(new HoehermeldungRepository())->set($schuetze_id, $sid, 'uebrige', 'hd1');
		$einzel->update($em_id, ['konflikt' => false, 'konflikt_text' => '']);
		$r = Revalidierung::sportjahr($sid);
		$this->assertSame(1, $r['geaendert']);
		$em = $einzel->find($em_id);
		$this->assertTrue($em['hoehermeldung_angewendet']);
		$this->assertStringContainsString('Klasse: Herren II → Herren I', $em['konflikt_text']);

		// Kein Startrecht mehr: Regel für Herren I löschen (Kette 12 → eigen bleibt, aber Klasse ist jetzt Herren I).
		$k10 = (new KlasseRepository())->by_key((int) $k12['gruppe_id'], 10, 'm');
		(new RegelRepository())->delete((int) (new RegelRepository())->by_key($lg->id, (int) $k10['id'])['id']);
		$einzel->update($em_id, ['konflikt' => false, 'konflikt_text' => '']);
		Revalidierung::sportjahr($sid);
		$em = $einzel->find($em_id);
		$this->assertFalse($em['startrecht']);
		$this->assertSame(0.0, $em['startgeld']);
		$this->assertStringContainsString('Kein Startrecht mehr', $em['konflikt_text']);
	}
}
