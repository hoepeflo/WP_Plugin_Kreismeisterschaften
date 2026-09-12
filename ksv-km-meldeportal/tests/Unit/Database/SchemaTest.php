<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Unit\Database;

use KSV\KMM\Infrastructure\Database\Schema;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase {

	private const PREFIX = 'wp_';
	private const CHARSET = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';

	public function test_table_names_use_kmm_prefix(): void {
		foreach (Schema::TABLES as $short) {
			$this->assertSame('wp_kmm_' . $short, Schema::table($short, self::PREFIX));
		}
	}

	public function test_unknown_table_throws(): void {
		$this->expectException(\InvalidArgumentException::class);
		Schema::table('gibt_es_nicht', self::PREFIX);
	}

	public function test_every_table_has_a_definition_and_vice_versa(): void {
		$defs = Schema::definitions(self::PREFIX, self::CHARSET);
		$this->assertSame(Schema::TABLES, array_keys($defs));
	}

	/**
	 * dbDelta() ist empfindlich gegenüber dem Format der CREATE-TABLE-Anweisung.
	 */
	public function test_definitions_follow_dbdelta_format(): void {
		foreach (Schema::definitions(self::PREFIX, self::CHARSET) as $short => $sql) {
			$name = Schema::table($short, self::PREFIX);
			$this->assertStringStartsWith("CREATE TABLE {$name} (\n", $sql, $short);
			$this->assertStringEndsWith(") " . self::CHARSET . ";", $sql, $short);
			$this->assertStringContainsString("\n  PRIMARY KEY  (id),", $sql, "$short: PRIMARY KEY mit zwei Leerzeichen");
			$this->assertStringContainsString("\n  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n", $sql, $short);
			$this->assertStringNotContainsString('`', $sql, "$short: keine Backticks");
			$this->assertStringNotContainsString('INDEX ', $sql, "$short: KEY statt INDEX");
			$this->assertDoesNotMatchRegularExpression('/\benum\s*\(/i', $sql, "$short: keine ENUMs");
			$this->assertStringNotContainsString('FOREIGN KEY', $sql, "$short: keine Foreign Keys (dbDelta)");

			$lines = explode("\n", $sql);
			foreach (array_slice($lines, 1, -1) as $line) {
				$this->assertMatchesRegularExpression('/^  \S/', $line, "$short: jedes Feld auf eigener Zeile mit zwei Leerzeichen Einzug: '$line'");
			}
			// Jede Spalten-/Key-Zeile außer der letzten endet mit Komma.
			$body = array_slice($lines, 1, -1);
			$last = array_pop($body);
			$this->assertStringEndsNotWith(',', (string) $last, $short);
			foreach ($body as $line) {
				$this->assertStringEndsWith(',', $line, "$short: fehlendes Komma in '$line'");
			}
		}
	}

	public function test_column_names_are_unique_per_table(): void {
		foreach (Schema::definitions(self::PREFIX, self::CHARSET) as $short => $sql) {
			$columns = self::column_names($sql);
			$this->assertSame(array_unique($columns), $columns, "$short: doppelte Spalte");
		}
	}

	public function test_keys_reference_existing_columns(): void {
		foreach (Schema::definitions(self::PREFIX, self::CHARSET) as $short => $sql) {
			$columns = self::column_names($sql);
			preg_match_all('/^\s+(?:UNIQUE KEY|KEY|PRIMARY KEY)\s+\w*\s*\(([^)]+)\)/m', $sql, $m);
			foreach ($m[1] as $list) {
				foreach (explode(',', $list) as $col) {
					$this->assertContains(trim($col), $columns, "$short: Key verweist auf unbekannte Spalte " . trim($col));
				}
			}
		}
	}

	public function test_phase2_fields_are_present(): void {
		$defs = Schema::definitions(self::PREFIX, self::CHARSET);
		$einzel = self::column_names($defs['einzelmeldung']);
		foreach (['verarbeitungsstatus', 'verarbeitungsgrund', 'abgemeldet_am', 'startgeld_berechnen', 'ergebnis_ref', 'para_klasse_id', 'nicht_meldung', 'konflikt'] as $col) {
			$this->assertContains($col, $einzel, $col);
		}
		$meldung = self::column_names($defs['meldung']);
		$this->assertContains('nachmeldung_bis', $meldung);
		$this->assertContains('letzte_sammelmail_am', $meldung);
		$sportjahr = self::column_names($defs['sportjahr']);
		$this->assertContains('abgeschlossen_am', $sportjahr);
		$this->assertContains('anonymisiert_am', $sportjahr);
		foreach (['aenderung', 'beleg', 'referent', 'referent_zustaendigkeit', 'wettkampftag', 'einheit', 'durchgang', 'durchgang_zulassung', 'buchung'] as $t) {
			$this->assertArrayHasKey($t, $defs, $t);
		}
		$this->assertStringContainsString('UNIQUE KEY platz (durchgang_id,einheit_id,position)', $defs['buchung']);
		$this->assertStringContainsString('UNIQUE KEY einzelmeldung_id (einzelmeldung_id)', $defs['buchung']);
	}

	public function test_versioned_master_data_carry_sportjahr_id(): void {
		$defs = Schema::definitions(self::PREFIX, self::CHARSET);
		foreach (['wettbewerbsgruppe', 'klasse', 'disziplin', 'regel', 'startgeld_tarif', 'hoehermeldung', 'meldung', 'einzelmeldung', 'mannschaft'] as $t) {
			$this->assertContains('sportjahr_id', self::column_names($defs[ $t ]), $t);
		}
	}

	/**
	 * @return string[]
	 */
	private static function column_names(string $sql): array {
		$names = [];
		foreach (explode("\n", $sql) as $line) {
			if (preg_match('/^  ([a-z_]+) (?!KEY)\S/', $line, $m) && !in_array($m[1], ['PRIMARY', 'UNIQUE', 'KEY'], true)) {
				$names[] = $m[1];
			}
		}
		return $names;
	}
}
