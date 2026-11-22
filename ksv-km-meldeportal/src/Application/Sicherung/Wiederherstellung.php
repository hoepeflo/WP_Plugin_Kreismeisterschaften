<?php
/**
 * Einspielen einer Sicherung (docs/SICHERUNG.md, Abschnitt Wiederherstellung).
 *
 * Die verschlüsselte Datei und der private Schlüssel werden nur für diesen einen Aufruf
 * verwendet und nirgends gespeichert. Vor dem Einspielen wird der aktuelle Stand gesichert;
 * danach werden alle in der Datei enthaltenen kmm_*-Tabellen geleert und neu befüllt.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application\Sicherung;

use KSV\KMM\Application\Protokoll;
use KSV\KMM\Auth\Rechte;
use KSV\KMM\Infrastructure\Database\Schema;
use KSV\KMM\Infrastructure\Database\Tables;

final class Wiederherstellung {

	/**
	 * Datei entschlüsseln und prüfen.
	 *
	 * @return array<string, mixed> die Sicherungsdaten
	 */
	public static function lesen(string $datei, string $privat): array {
		$json = Verschluesselung::entschluesseln($datei, $privat);
		return self::pruefen($json);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function pruefen(string $json): array {
		$daten = json_decode($json, true);
		if (!is_array($daten) || ($daten['format'] ?? '') !== Sicherung::FORMAT || !is_array($daten['tabellen'] ?? null)) {
			throw new \RuntimeException('Die Datei enthält keine gültige Sicherung des KM-Portals.');
		}
		if ((int) ($daten['version'] ?? 0) > Sicherung::FORMAT_VERSION) {
			throw new \RuntimeException('Die Sicherung stammt aus einer neueren Plugin-Version. Bitte zuerst das Plugin aktualisieren.');
		}
		foreach ($daten['tabellen'] as $name => $rows) {
			if (!in_array($name, Schema::TABLES, true) || !is_array($rows)) {
				throw new \RuntimeException(sprintf('Unbekannte Tabelle "%s" in der Sicherung.', (string) $name));
			}
		}
		return $daten;
	}

	/**
	 * Kennzahlen für die Vorschau.
	 *
	 * @param array<string, mixed> $daten
	 * @return array<string, int|string>
	 */
	public static function zusammenfassung(array $daten): array {
		/** @var array<string, list<array<string, mixed>>> $t */
		$t = $daten['tabellen'];
		$zeilen = 0;
		foreach ($t as $rows) {
			$zeilen += count($rows);
		}
		return [
			'erstellt_am'    => (string) ($daten['erstellt_am'] ?? ''),
			'anlass'         => (string) ($daten['anlass'] ?? ''),
			'plugin'         => (string) ($daten['plugin'] ?? ''),
			'schema'         => (int) ($daten['schema'] ?? 0),
			'vereine'        => count($t['verein'] ?? []),
			'meldungen'      => count($t['meldung'] ?? []),
			'einzelmeldungen' => count($t['einzelmeldung'] ?? []),
			'schuetzen'      => count($t['schuetze'] ?? []),
			'wettkampftage'  => count($t['wettkampftag'] ?? []),
			'tabellen'       => count($t),
			'zeilen'         => $zeilen,
		];
	}

	/**
	 * Sicherung einspielen. Sichert vorher den aktuellen Stand.
	 *
	 * @param array<string, mixed> $daten aus lesen()
	 * @return array{vorher: string, zeilen: int}
	 */
	public static function einspielen(array $daten): array {
		global $wpdb;
		if (!Rechte::ist_admin()) {
			throw new \RuntimeException('Nur Administratoren dürfen eine Sicherung einspielen.');
		}
		$vorher = Sicherung::eingerichtet() ? Sicherung::erstellen('vor-wiederherstellung') : ['ok' => false, 'datei' => ''];
		if (Sicherung::eingerichtet() && !$vorher['ok']) {
			throw new \RuntimeException('Der aktuelle Stand konnte nicht gesichert werden. Abgebrochen, es wurde nichts verändert.');
		}
		/** @var array<string, list<array<string, mixed>>> $tabellen */
		$tabellen = $daten['tabellen'];
		$zeilen = 0;
		$wpdb->query('START TRANSACTION');
		try {
			foreach ($tabellen as $name => $rows) {
				$tabelle = Tables::name($name);
				$spalten = $wpdb->get_col('SHOW COLUMNS FROM ' . $tabelle); // phpcs:ignore WordPress.DB.PreparedSQL
				if ($wpdb->query('DELETE FROM ' . $tabelle) === false) { // phpcs:ignore WordPress.DB.PreparedSQL
					throw new \RuntimeException('Tabelle ' . $name . ' konnte nicht geleert werden: ' . $wpdb->last_error);
				}
				foreach ($rows as $row) {
					// Nur Spalten, die es im aktuellen Schema gibt; neue Spalten behalten ihren Standard.
					$row = array_intersect_key($row, array_flip($spalten));
					if ($wpdb->insert($tabelle, $row) === false) {
						throw new \RuntimeException('Fehler beim Einspielen in ' . $name . ': ' . $wpdb->last_error);
					}
					$zeilen++;
				}
			}
			$wpdb->query('COMMIT');
		} catch (\Throwable $e) {
			$wpdb->query('ROLLBACK');
			throw $e;
		}
		wp_cache_flush();
		Protokoll::admin('sicherung.einspielen', sprintf('Sicherung vom %s eingespielt (%d Zeilen)', (string) ($daten['erstellt_am'] ?? '?'), $zeilen), null, null, '', null, ['vorher' => $vorher['datei']]);
		return ['vorher' => (string) $vorher['datei'], 'zeilen' => $zeilen];
	}
}
