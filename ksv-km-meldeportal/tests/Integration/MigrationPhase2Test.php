<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Integration;

use KSV\KMM\Application\RegeltabelleImporter;
use KSV\KMM\Application\SportjahrService;
use KSV\KMM\Auth\Capabilities;
use KSV\KMM\Domain\Regeltabelle\Dokument;
use KSV\KMM\Infrastructure\Database\Migrator;
use KSV\KMM\Infrastructure\Database\Schema;
use KSV\KMM\Infrastructure\Database\Tables;
use KSV\KMM\Infrastructure\Repository\BuchungRepository;
use KSV\KMM\Infrastructure\Repository\DisziplinRepository;
use KSV\KMM\Infrastructure\Repository\EinzelmeldungRepository;
use KSV\KMM\Infrastructure\Repository\RegelRepository;
use KSV\KMM\Support\Clock;

final class MigrationPhase2Test extends IntegrationTestCase {

	public function test_migration_von_version_2_erhaelt_daten_und_ergaenzt_tabellen(): void {
		global $wpdb;
		// Bestehende Daten aus Phase 1 anlegen.
		$sid = (new SportjahrService())->anlegen(2027);
		remove_all_actions('kmm_regeln_geaendert');
		(new RegeltabelleImporter())->importieren($sid, Dokument::fromJson((string) file_get_contents(dirname(__DIR__, 2) . '/docs/regeltabelle-2026.json')));
		$regeln_vorher = (new RegelRepository())->count(['sportjahr_id' => $sid]);
		$disziplinen_vorher = (new DisziplinRepository())->count(['sportjahr_id' => $sid]);

		// Zustand „Version 2": neue Tabellen fehlen, neue Spalten fehlen.
		foreach (['aenderung', 'beleg', 'referent', 'referent_zustaendigkeit', 'wettkampftag', 'einheit', 'durchgang', 'durchgang_zulassung', 'buchung', 'schiessstand', 'standgruppe'] as $t) {
			$wpdb->query('DROP TABLE IF EXISTS ' . Tables::name($t)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$wpdb->query('ALTER TABLE ' . Tables::name('einzelmeldung') . ' DROP COLUMN nachgemeldet, DROP COLUMN abmeldegrund'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query('ALTER TABLE ' . Tables::name('sportjahr') . ' DROP COLUMN abschluss_backup'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		remove_role(Capabilities::ROLE_REFERENT);
		update_option(Schema::OPTION_VERSION, 2);

		Migrator::maybe_migrate();

		$this->assertSame(Schema::VERSION, (int) get_option(Schema::OPTION_VERSION));
		foreach (Migrator::table_status() as $name => $ok) {
			$this->assertTrue($ok, $name);
		}
		$this->assertSame($regeln_vorher, (new RegelRepository())->count(['sportjahr_id' => $sid]), 'Regeln unverändert');
		$this->assertSame($disziplinen_vorher, (new DisziplinRepository())->count(['sportjahr_id' => $sid]));
		$spalten = $wpdb->get_col('SHOW COLUMNS FROM ' . Tables::name('einzelmeldung')); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertContains('nachgemeldet', $spalten);
		$this->assertContains('abmeldegrund', $spalten);
		$this->assertInstanceOf(\WP_Role::class, get_role(Capabilities::ROLE_REFERENT));
		$this->assertTrue(get_role(Capabilities::ROLE_REFERENT)->has_cap(Capabilities::VIEW));
		$this->assertFalse(get_role(Capabilities::ROLE_REFERENT)->has_cap(Capabilities::MANAGE));

		// Zweiter Lauf ändert nichts.
		$this->assertSame([], Migrator::migrate(Schema::VERSION));
	}

	public function test_buchung_eindeutigkeit_auf_datenbankebene(): void {
		$repo = new BuchungRepository();
		$basis = ['sportjahr_id' => 1, 'wettkampftag_id' => 1, 'durchgang_id' => 1, 'einheit_id' => 1, 'position' => 1, 'verein_id' => 1, 'gebucht_am' => Clock::now_utc()];
		$id = $repo->insert($basis + ["einzelmeldung_id" => 100]);
		$this->assertGreaterThan(0, $id, $repo->last_error());
		$wpdb_suppress = $GLOBALS['wpdb']->suppress_errors(true);
		$this->assertSame(0, $repo->insert($basis + ['einzelmeldung_id' => 101]), 'gleicher Platz');
		$this->assertTrue($repo->letzter_fehler_ist_duplikat());
		$this->assertSame(0, $repo->insert(array_merge($basis, ['position' => 2, 'einzelmeldung_id' => 100])), 'gleiche Meldung');
		$this->assertTrue($repo->letzter_fehler_ist_duplikat());
		$this->assertGreaterThan(0, $repo->insert(array_merge($basis, ['position' => 2, 'einzelmeldung_id' => 101])));
		$GLOBALS['wpdb']->suppress_errors($wpdb_suppress);
		$this->assertSame(2, $repo->count([]));
	}
}
