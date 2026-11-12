<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Integration;

use KSV\KMM\Application\Abmeldung;
use KSV\KMM\Application\MeldungService;
use KSV\KMM\Application\ReferentService;
use KSV\KMM\Application\RegeltabelleImporter;
use KSV\KMM\Application\SchuetzeService;
use KSV\KMM\Application\SportjahrService;
use KSV\KMM\Application\StartplanService;
use KSV\KMM\Application\VerarbeitungService;
use KSV\KMM\Application\VereinService;
use KSV\KMM\Auth\Capabilities;
use KSV\KMM\Auth\Rechte;
use KSV\KMM\Domain\Regeltabelle\Dokument;
use KSV\KMM\Domain\Verarbeitungsstatus;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\BuchungRepository;
use KSV\KMM\Infrastructure\Repository\DurchgangRepository;
use KSV\KMM\Infrastructure\Repository\DurchgangZulassungRepository;
use KSV\KMM\Infrastructure\Repository\EinheitRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;
use KSV\KMM\Infrastructure\Repository\WettkampftagRepository;
use KSV\KMM\Support\Clock;

/**
 * Phase 2, Meilenstein 7: Aufbau eines Wettkampftags.
 */
final class StartplanTest extends IntegrationTestCase {

	private int $sid;
	/** @var array<string, mixed> */
	private array $verein;
	/** @var array<string, int> */
	private array $em = [];
	private int $lg;
	private int $lp;

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
		$this->lg = $rw->disziplin_nach_kennzahl('1.10')->id;
		$this->lp = $rw->disziplin_nach_kennzahl('2.10')->id;
		foreach ([['A', '1990-01-01', 'm'], ['B', '1985-01-01', 'm'], ['C', '2011-01-01', 'w']] as $i => [$n, $g, $ge]) {
			$s = $ss->speichern(0, ['nachname' => $n, 'vorname' => 'X', 'geburtsdatum' => $g, 'geschlecht' => $ge, 'mitgliedsnummer' => '12345000' . ($i + 1)])['id'];
			$this->em[ $n ] = $ms->einzel_anlegen($s, $this->lg)['id'];
			if ($n === 'A') {
				$this->em['A_lp'] = $ms->einzel_anlegen($s, $this->lp)['id'];
			}
		}
		$ms->ansprechpartner_speichern(['name' => 'SL', 'email' => 'sl@example.org']);
		$ms->einreichen();
	}

	protected function tearDown(): void {
		remove_all_filters('pre_wp_mail');
		wp_set_current_user(1);
		Rechte::cache_leeren();
	}

	private function tag(StartplanService $s, string $datum = '2027-03-06'): int {
		return $s->tag_speichern(0, ['datum' => $datum, 'bezeichnung' => 'KM Luftdruck', 'ort' => 'Bad Fallingbostel', 'buchungsfrist' => '2027-02-20 22:59:00', 'hinweis' => 'Bitte 30 Minuten vorher da sein.']);
	}

	public function test_aufbau_validierung_und_uebersicht(): void {
		$s = new StartplanService($this->sid);
		try {
			$s->tag_speichern(0, ['datum' => '2027-13-01']);
			$this->fail('ungültiges Datum');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('Datum', $e->getMessage());
		}
		$tag = $this->tag($s);
		$this->assertSame('entwurf', (new WettkampftagRepository())->find($tag)['status'], 'neu = Entwurf, für Vereine unsichtbar');

		// Einheiten
		$st1 = $s->einheit_speichern(0, $tag, 'Stand 1', 1);
		$st2 = $s->einheit_speichern(0, $tag, 'Stand 2', 1);
		$auflage = $s->einheit_speichern(0, $tag, 'Stand 3 (Auflage)', 1, [RegelwerkLader::engine($this->sid)->regelwerk()->disziplin_nach_kennzahl('1.11')->id]);
		try {
			$s->einheit_speichern(0, $tag, '', 1);
			$this->fail('ohne Bezeichnung');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('Bezeichnung', $e->getMessage());
		}
		try {
			$s->einheit_speichern(0, $tag, 'X', 0);
			$this->fail('Kapazität 0');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('Kapazität', $e->getMessage());
		}

		// Durchgänge: Ende vor Beginn, falscher Tag
		try {
			$s->durchgang_speichern(0, $tag, 1, '', '2027-03-06 09:00:00', '2027-03-06 08:00:00');
			$this->fail('Ende vor Beginn');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('Ende', $e->getMessage());
		}
		try {
			$s->durchgang_speichern(0, $tag, 1, '', '2027-03-07 09:00:00', '2027-03-07 10:00:00');
			$this->fail('anderer Tag');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('06.03.2027', $e->getMessage());
		}
		$dg1 = $s->durchgang_speichern(0, $tag, 0, '', Clock::local_to_utc('2027-03-06 09:00'), Clock::local_to_utc('2027-03-06 10:30'));
		$dg2 = $s->durchgang_speichern(0, $tag, 0, '', Clock::local_to_utc('2027-03-06 10:00'), Clock::local_to_utc('2027-03-06 11:30'), 0); // überlappt dg1: erlaubt (verschiedene Einheiten); Konflikt greift erst je Schütze bei der Buchung
		$this->assertSame(2, (new DurchgangRepository())->find($dg2)['nummer'], 'Nummer automatisch');

		// Zulassungen
		$s->zulassung_hinzufuegen($dg1, $this->lg, null);
		$s->zulassung_hinzufuegen($dg1, $this->lg, null); // doppelt → ignoriert
		$this->assertCount(1, (new DurchgangZulassungRepository())->by_durchgang($dg1));
		$rw = RegelwerkLader::engine($this->sid)->regelwerk();
		$jugend_w = $rw->klasse_nach_ref('freihand', 31, 'w');
		$this->assertNotNull($jugend_w);
		$s->zulassung_hinzufuegen($dg2, $this->lg, $jugend_w->id);
		$s->zulassung_hinzufuegen($dg2, $this->lp, null);
		try {
			$s->zulassung_hinzufuegen($dg2, $this->lg, $rw->klassen_der_gruppe('auflage')[0]->id);
			$this->fail('fremde Klasse');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('Startklasse', $e->getMessage());
		}
		$this->assertNotEmpty($s->startklassen($rw->disziplin($this->lg)));

		// Übersicht: Plätze und Bedarf
		$u = $s->uebersicht($tag);
		$this->assertCount(3, $u['einheiten']);
		$this->assertCount(2, $u['durchgaenge']);
		$this->assertSame(2, $u['durchgaenge'][0]['plaetze'], 'Auflage-Stand zählt nicht für Luftgewehr');
		$this->assertSame(3, $u['durchgaenge'][0]['bedarf'], 'A, B, C in 1.10');
		$this->assertSame(2, $u['durchgaenge'][1]['bedarf'], 'C (Jugend w) in 1.10 und A in 2.10');
		$this->assertSame(0, $u['buchungen']);

		// Nicht startberechtigt / abgemeldet fallen aus dem Bedarf.
		(new VerarbeitungService($this->sid))->setzen($this->em['B'], Verarbeitungsstatus::NICHT_STARTBERECHTIGT, 'x');
		(new MeldungService($this->verein, $this->sid, true))->abmelden($this->em['C'], 'krank');
		$u = $s->uebersicht($tag);
		$this->assertSame(1, $u['durchgaenge'][0]['bedarf']);
		$this->assertSame(1, $u['durchgaenge'][1]['bedarf']);

		// Für den Verein ist nichts sichtbar / kein Startgeld-Standard durch den Entwurf.
		$this->assertFalse(Abmeldung::startgeld_standard((array) (new \KSV\KMM\Infrastructure\Repository\EinzelmeldungRepository())->find($this->em['A'])));

		// Löschen mit Buchung gesperrt
		(new BuchungRepository())->insert(['sportjahr_id' => $this->sid, 'wettkampftag_id' => $tag, 'durchgang_id' => $dg1, 'einheit_id' => $st1, 'position' => 1, 'einzelmeldung_id' => $this->em['A'], 'verein_id' => (int) $this->verein['id'], 'gebucht_am' => Clock::now_utc()]);
		foreach ([fn() => $s->einheit_loeschen($st1), fn() => $s->durchgang_loeschen($dg1), fn() => $s->tag_loeschen($tag), fn() => $s->einheit_speichern($st1, $tag, 'Stand 1', 1)] as $i => $fn) {
			try {
				$fn();
				if ($i < 3) {
					$this->fail('Löschen mit Buchung ' . $i);
				}
			} catch (\RuntimeException $e) {
				$this->assertStringContainsString('Buchung', $e->getMessage());
			}
		}
		$this->assertSame(1, $s->uebersicht($tag)['durchgaenge'][0]['gebucht']);
		(new BuchungRepository())->delete_where(['wettkampftag_id' => $tag]);
		$s->einheit_loeschen($st2);
		$s->durchgang_loeschen($dg2);
		$s->tag_loeschen($tag);
		$this->assertSame([], (new EinheitRepository())->by_wettkampftag($tag));
		$this->assertSame([], (new WettkampftagRepository())->by_sportjahr($this->sid));
		$this->assertSame(0, (new DurchgangZulassungRepository())->count(['durchgang_id' => $dg1]));
	}

	public function test_referent_braucht_recht_und_bleibt_im_eigenen_bereich(): void {
		$s = new StartplanService($this->sid);
		$tag = $this->tag($s);
		$dg_lp = $s->durchgang_speichern(0, $tag, 0, 'Pistole', Clock::local_to_utc('2027-03-06 13:00'), Clock::local_to_utc('2027-03-06 14:00'));
		$s->zulassung_hinzufuegen($dg_lp, $this->lp, null);

		$alt = get_user_by('login', 'ref_lg');
		if ($alt instanceof \WP_User) {
			wp_delete_user($alt->ID);
		}
		$uid = wp_insert_user(['user_login' => 'ref_lg', 'user_pass' => 'x', 'role' => Capabilities::ROLE_REFERENT]);
		$this->assertIsInt($uid);
		$rid = (new ReferentService())->speichern(0, $uid, [], [], ['1.10']);
		wp_set_current_user($uid);
		Rechte::cache_leeren();
		try {
			$s->einheit_speichern(0, $tag, 'Stand 9', 1);
			$this->fail('ohne darf_startplan');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Kein Recht', $e->getMessage());
		}
		wp_set_current_user(1);
		(new ReferentService())->speichern($rid, $uid, ['darf_startplan' => true], [], ['1.10']);
		wp_set_current_user($uid);
		Rechte::cache_leeren();

		$s->einheit_speichern(0, $tag, 'Stand 9', 1);
		$dg_lg = $s->durchgang_speichern(0, $tag, 0, 'Gewehr', Clock::local_to_utc('2027-03-06 09:00'), Clock::local_to_utc('2027-03-06 10:00'));
		$s->zulassung_hinzufuegen($dg_lg, $this->lg, null);
		try {
			$s->zulassung_hinzufuegen($dg_lg, $this->lp, null);
			$this->fail('fremde Disziplin');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Zuständigkeit', $e->getMessage());
		}
		$this->assertFalse($s->durchgang_zustaendig($dg_lp));
		foreach ([fn() => $s->durchgang_loeschen($dg_lp), fn() => $s->durchgang_speichern($dg_lp, $tag, 5, 'x', Clock::local_to_utc('2027-03-06 13:00'), Clock::local_to_utc('2027-03-06 14:30')), fn() => $s->tag_loeschen($tag)] as $i => $fn) {
			try {
				$fn();
				$this->fail('fremder Durchgang / Tag löschen ' . $i);
			} catch (\RuntimeException $e) {
				$this->assertMatchesRegularExpression('/Zuständigkeit|Administratoren/', $e->getMessage());
			}
		}
		$z = (new DurchgangZulassungRepository())->by_durchgang($dg_lp)[0];
		try {
			$s->zulassung_entfernen((int) $z['id']);
			$this->fail('fremde Zulassung entfernen');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Zuständigkeit', $e->getMessage());
		}
		wp_delete_user($uid);
	}

	/** Mehrere gleich lange Durchgänge in einem Schritt: Zeiten, Nummern, alles oder nichts. */
	public function test_durchgaenge_in_einem_schritt_anlegen(): void {
		$s = new StartplanService($this->sid);
		$tag = $s->tag_speichern(0, ['datum' => '2027-03-06', 'bezeichnung' => 'KM Luftdruck', 'ort' => 'X', 'buchungsfrist' => '2099-02-20 22:59:00']);
		$repo = new DurchgangRepository();
		$zeiten = static fn(): array => array_map(static fn(array $d): string => (int) $d['nummer'] . ' ' . Clock::format_local($d['beginn'], 'H:i') . '-' . Clock::format_local($d['ende'], 'H:i') . ' ' . $d['bezeichnung'], $repo->by_wettkampftag($tag));

		// Vier Durchgänge à 75 Minuten mit 15 Minuten Pause.
		$ids = $s->durchgaenge_anlegen($tag, 1, 'Luftpistole', Clock::local_to_utc('2027-03-06 09:00'), Clock::local_to_utc('2027-03-06 10:15'), 4, 15);
		$this->assertCount(4, $ids);
		$this->assertSame(['1 09:00-10:15 Luftpistole', '2 10:30-11:45 Luftpistole', '3 12:00-13:15 Luftpistole', '4 13:30-14:45 Luftpistole'], $zeiten());
		foreach ($ids as $id) {
			$this->assertSame([], (new DurchgangZulassungRepository())->by_durchgang($id), 'neue Durchgänge haben noch keine Zulassung');
		}

		// Nummer 0 hängt hinten an; ohne Pause schließen die Durchgänge direkt an.
		$s->durchgaenge_anlegen($tag, 0, '', Clock::local_to_utc('2027-03-06 15:00'), Clock::local_to_utc('2027-03-06 16:00'), 2);
		$this->assertSame(['5 15:00-16:00 ', '6 16:00-17:00 '], array_slice($zeiten(), 4));

		// Anzahl 1 verhält sich wie das einfache Anlegen.
		$this->assertCount(1, $s->durchgaenge_anlegen($tag, 0, 'einzeln', Clock::local_to_utc('2027-03-06 17:00'), Clock::local_to_utc('2027-03-06 18:00')));
		$this->assertCount(7, $zeiten());

		// Reicht die Folge über Mitternacht, entsteht keiner – auch nicht die, die noch gepasst hätten.
		try {
			$s->durchgaenge_anlegen($tag, 0, '', Clock::local_to_utc('2027-03-06 20:00'), Clock::local_to_utc('2027-03-06 22:00'), 3, 30);
			$this->fail('Folge über Mitternacht muss scheitern');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('Durchgang 3 von 3', $e->getMessage());
		}
		$this->assertCount(7, $zeiten());

		// Unsinnige Angaben.
		foreach ([[0, 0], [51, 0], [2, -5], [2, 601]] as [$anzahl, $pause]) {
			try {
				$s->durchgaenge_anlegen($tag, 0, '', Clock::local_to_utc('2027-03-06 09:00'), Clock::local_to_utc('2027-03-06 10:00'), $anzahl, $pause);
				$this->fail("Anzahl $anzahl / Pause $pause muss scheitern");
			} catch (\InvalidArgumentException) {
				$this->assertCount(7, $zeiten());
			}
		}
		try {
			$s->durchgaenge_anlegen($tag, 0, '', Clock::local_to_utc('2027-03-06 10:00'), Clock::local_to_utc('2027-03-06 09:00'), 2);
			$this->fail('Ende vor Beginn muss scheitern');
		} catch (\InvalidArgumentException) {
			$this->assertCount(7, $zeiten());
		}
	}

	/** Eine Disziplin mit mehreren Startklassen in einem Schritt zulassen und wieder ändern. */
	public function test_zulassungen_setzen_je_disziplin(): void {
		$s = new StartplanService($this->sid);
		$tag = $s->tag_speichern(0, ['datum' => '2027-03-06', 'bezeichnung' => 'KM Luftdruck', 'ort' => 'X', 'buchungsfrist' => '2099-02-20 22:59:00']);
		$s->einheit_speichern(0, $tag, 'Stand 1', 1);
		$dg = $s->durchgang_speichern(0, $tag, 1, '', Clock::local_to_utc('2027-03-06 09:00'), Clock::local_to_utc('2027-03-06 10:30'));
		$rw = RegelwerkLader::engine($this->sid)->regelwerk();
		$lp = $rw->disziplin($this->lp);
		$klassen = $s->startklassen($lp);
		$this->assertGreaterThan(4, count($klassen));
		[$k1, $k2, $k3] = [$klassen[0]->id, $klassen[1]->id, $klassen[2]->id];
		$repo = new DurchgangZulassungRepository();
		$stand = static function () use ($repo, $dg): array {
			$out = [];
			foreach ($repo->by_durchgang($dg) as $z) {
				$out[ (int) $z['disziplin_id'] ][] = $z['startklasse_id'] === null ? null : (int) $z['startklasse_id'];
			}
			return $out;
		};

		// Mehrere Klassen auf einmal
		$this->assertSame(['neu' => 2, 'entfernt' => 0, 'behalten' => 0, 'durchgaenge' => 1], $s->zulassungen_setzen($dg, $this->lp, [$k1, $k2]));
		$this->assertEqualsCanonicalizing([$k1, $k2], $stand()[ $this->lp ]);
		// Ändern: eine Klasse raus, eine dazu – die übrige bleibt, wie sie ist
		$this->assertSame(['neu' => 1, 'entfernt' => 1, 'behalten' => 1, 'durchgaenge' => 1], $s->zulassungen_setzen($dg, $this->lp, [$k2, $k3, $k3]));
		$this->assertEqualsCanonicalizing([$k2, $k3], $stand()[ $this->lp ]);
		// Unverändert speichern ändert nichts
		$this->assertSame(['neu' => 0, 'entfernt' => 0, 'behalten' => 2, 'durchgaenge' => 1], $s->zulassungen_setzen($dg, $this->lp, [$k3, $k2]));
		// Eine andere Disziplin bleibt unberührt
		$s->zulassungen_setzen($dg, $this->lg, []);
		$this->assertSame([null], $stand()[ $this->lg ]);
		$this->assertEqualsCanonicalizing([$k2, $k3], $stand()[ $this->lp ]);
		// Leere Liste = alle Startklassen; die einzelnen entfallen
		$this->assertSame(['neu' => 1, 'entfernt' => 2, 'behalten' => 0, 'durchgaenge' => 1], $s->zulassungen_setzen($dg, $this->lp, []));
		$this->assertSame([null], $stand()[ $this->lp ]);
		// Einzelne Klassen ersetzen „alle“ – sonst bliebe die Einschränkung wirkungslos
		$s->zulassungen_setzen($dg, $this->lp, [$k1]);
		$this->assertSame([$k1], $stand()[ $this->lp ]);
		// Alle Klassen einzeln angekreuzt heißt „alle“
		$s->zulassungen_setzen($dg, $this->lp, array_map(static fn($k): int => $k->id, $klassen));
		$this->assertSame([null], $stand()[ $this->lp ]);

		// Fremde Klasse: Fehler, und nichts ändert sich
		$s->zulassungen_setzen($dg, $this->lp, [$k1, $k2]);
		try {
			$s->zulassungen_setzen($dg, $this->lp, [$k3, $rw->klassen_der_gruppe('auflage')[0]->id]);
			$this->fail('fremde Klasse');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('Startklasse', $e->getMessage());
		}
		$this->assertEqualsCanonicalizing([$k1, $k2], $stand()[ $this->lp ]);

		// Lesbare Zusammenfassung je Disziplin
		$this->assertSame(
			['2.10 10m Luftpistole (Schüler m, Schüler w)', '1.10 Luftgewehr (alle Startklassen)'],
			StartplanService::zulassungen_lesbar([
				['disziplin' => '2.10 10m Luftpistole', 'startklasse' => 'Schüler m'],
				['disziplin' => '1.10 Luftgewehr', 'startklasse' => null],
				['disziplin' => '2.10 10m Luftpistole', 'startklasse' => 'Schüler w'],
			], true)
		);
		$this->assertSame(['1.10 Luftgewehr'], StartplanService::zulassungen_lesbar([['disziplin' => '1.10 Luftgewehr', 'startklasse' => null]]));
	}

	/** Dieselbe Zulassung für mehrere Durchgänge in einem Schritt; eine Disziplin ganz herausnehmen. */
	public function test_zulassung_fuer_mehrere_durchgaenge_und_entfernen(): void {
		$s = new StartplanService($this->sid);
		$tag = $s->tag_speichern(0, ['datum' => '2027-03-06', 'bezeichnung' => 'KM Luftdruck', 'ort' => 'X', 'buchungsfrist' => '2099-02-20 22:59:00']);
		$anderer_tag = $s->tag_speichern(0, ['datum' => '2027-03-07', 'bezeichnung' => 'KM Bogen', 'ort' => 'X', 'buchungsfrist' => '2099-02-20 22:59:00']);
		[$dg1, $dg2, $dg3] = $s->durchgaenge_anlegen($tag, 1, '', Clock::local_to_utc('2027-03-06 09:00'), Clock::local_to_utc('2027-03-06 10:00'), 3, 15);
		$fremd = $s->durchgang_speichern(0, $anderer_tag, 1, '', Clock::local_to_utc('2027-03-07 09:00'), Clock::local_to_utc('2027-03-07 10:00'));
		$klassen = $s->startklassen(RegelwerkLader::engine($this->sid)->regelwerk()->disziplin($this->lp));
		[$k1, $k2, $k3] = [$klassen[0]->id, $klassen[1]->id, $klassen[2]->id];
		$repo = new DurchgangZulassungRepository();
		$stand = static function (int $dg) use ($repo): array {
			$out = [];
			foreach ($repo->by_durchgang($dg) as $z) {
				$out[ (int) $z['disziplin_id'] ][] = $z['startklasse_id'] === null ? null : (int) $z['startklasse_id'];
			}
			ksort($out);
			return array_map(static function (array $k): array { sort($k); return $k; }, $out);
		};

		// Durchgang 2 hat schon etwas anderes: Luftgewehr bleibt, Luftpistole wird ersetzt.
		$s->zulassungen_setzen($dg2, $this->lg, []);
		$s->zulassungen_setzen($dg2, $this->lp, [$k3]);

		$r = $s->zulassungen_setzen($dg1, $this->lp, [$k1, $k2], [$dg2, $dg3, $dg1, $dg3]);
		$this->assertSame(['neu' => 6, 'entfernt' => 1, 'behalten' => 0, 'durchgaenge' => 3], $r);
		$soll = [$k1, $k2];
		sort($soll);
		$this->assertSame([$this->lp => $soll], $stand($dg1));
		$this->assertSame([$this->lp => $soll], $stand($dg3));
		$erwartet = [$this->lg => [null], $this->lp => $soll];
		ksort($erwartet);
		$this->assertSame($erwartet, $stand($dg2));

		// Ein Durchgang eines anderen Tages: Fehler, und kein Durchgang ändert sich.
		try {
			$s->zulassungen_setzen($dg1, $this->lp, [], [$dg3, $fremd]);
			$this->fail('fremder Durchgang');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('Wettkampftag', $e->getMessage());
		}
		$this->assertSame([$this->lp => $soll], $stand($dg1));
		$this->assertSame([$this->lp => $soll], $stand($dg3));
		$this->assertSame([], $stand($fremd));

		// Eine Disziplin ganz herausnehmen: nur diese, nur in diesem Durchgang.
		$this->assertSame(2, $s->zulassungen_entfernen($dg2, $this->lp));
		$this->assertSame([$this->lg => [null]], $stand($dg2));
		$this->assertSame([$this->lp => $soll], $stand($dg1));
		$this->assertSame(0, $s->zulassungen_entfernen($dg2, $this->lp));
	}
}
