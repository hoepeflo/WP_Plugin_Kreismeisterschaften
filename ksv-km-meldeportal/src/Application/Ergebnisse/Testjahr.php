<?php
/**
 * Entfernt ein Test-Sportjahr (z. B. „KM 2026 TEST“) vollständig: alle Zeilen mit seiner
 * sportjahr_id, die Kindzeilen der Wettkampftage und Zugänge, Schützen, die nur in
 * diesem Jahr vorkommen, und sein Protokoll (Entscheidung vom 22.11.2026).
 *
 * Schutz: nur Administratoren, nur ein nicht aktives Sportjahr mit „TEST“ in der
 * Bezeichnung, das Jahr muss von Hand bestätigt werden. Vorher wird alles als JSON in den
 * geschützten Archivordner gesichert. Ein Probelauf zählt nur.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application\Ergebnisse;

use KSV\KMM\Application\Abschluss;
use KSV\KMM\Application\Protokoll;
use KSV\KMM\Auth\Rechte;
use KSV\KMM\Infrastructure\Database\Tables;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Support\Clock;

final class Testjahr {

	/** Tabellen mit Spalte sportjahr_id, in Löschreihenfolge (Kinder zuerst). */
	private const SPORTJAHR_TABELLEN = [
		'buchung', 'aenderung', 'beleg', 'export', 'mail_log', 'einzelmeldung', 'mannschaft',
		'meldung', 'hoehermeldung', 'magic_link', 'regel', 'startgeld_tarif', 'disziplin',
		'klasse', 'wettbewerbsgruppe', 'ergebnis', 'wettkampftag', 'protokoll',
	];

	/**
	 * Test-Sportjahre, die entfernt werden dürfen.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function kandidaten(): array {
		$out = [];
		foreach ((new SportjahrRepository())->all() as $sj) {
			if (self::ist_testjahr($sj)) {
				$out[] = $sj;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $sj
	 */
	public static function ist_testjahr(array $sj): bool {
		return empty($sj['ist_aktiv']) && stripos((string) ($sj['bezeichnung'] ?? ''), 'test') !== false;
	}

	/**
	 * Zählt, was entfernt würde (verändert nichts).
	 *
	 * @return array<string, int>
	 */
	public function zaehlen(int $sportjahr_id): array {
		global $wpdb;
		$this->sportjahr($sportjahr_id);
		$out = [];
		foreach (self::SPORTJAHR_TABELLEN as $t) {
			$out[ $t ] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Tables::name($t) . ' WHERE sportjahr_id = %d', $sportjahr_id)); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		$tage = $this->ids('wettkampftag', $sportjahr_id);
		$durchgaenge = $this->kind_ids('durchgang', 'wettkampftag_id', $tage);
		$out['einheit'] = count($this->kind_ids('einheit', 'wettkampftag_id', $tage));
		$out['durchgang'] = count($durchgaenge);
		$out['durchgang_zulassung'] = count($this->kind_ids('durchgang_zulassung', 'durchgang_id', $durchgaenge));
		$out['sitzung'] = count($this->kind_ids('sitzung', 'magic_link_id', $this->ids('magic_link', $sportjahr_id)));
		$out['schuetze'] = count($this->nur_hier_schuetzen($sportjahr_id));
		$out['sportjahr'] = 1;
		return $out;
	}

	/**
	 * Entfernt das Test-Sportjahr. $bestaetigung muss das Jahr sein.
	 *
	 * @return array{zaehlung: array<string, int>, sicherung: string}
	 * @throws \RuntimeException
	 */
	public function entfernen(int $sportjahr_id, string $bestaetigung): array {
		global $wpdb;
		if (!Rechte::ist_admin()) {
			throw new \RuntimeException(__('Nur Administratoren dürfen ein Testjahr entfernen.', 'ksv-km-meldeportal'));
		}
		$sj = $this->sportjahr($sportjahr_id);
		if (trim($bestaetigung) !== (string) $sj['jahr']) {
			throw new \RuntimeException(__('Zur Bestätigung bitte das Jahr eintippen.', 'ksv-km-meldeportal'));
		}
		$zaehlung = $this->zaehlen($sportjahr_id);
		$sicherung = $this->sichern($sportjahr_id, $sj);

		$tage = $this->ids('wettkampftag', $sportjahr_id);
		$durchgaenge = $this->kind_ids('durchgang', 'wettkampftag_id', $tage);
		$links = $this->ids('magic_link', $sportjahr_id);
		$schuetzen = $this->nur_hier_schuetzen($sportjahr_id);

		$wpdb->query('START TRANSACTION');
		try {
			$this->loeschen_in('durchgang_zulassung', 'durchgang_id', $durchgaenge);
			$this->loeschen_in('durchgang', 'id', $durchgaenge);
			$this->loeschen_in('einheit', 'wettkampftag_id', $tage);
			$this->loeschen_in('sitzung', 'magic_link_id', $links);
			foreach (self::SPORTJAHR_TABELLEN as $t) {
				$r = $wpdb->query($wpdb->prepare('DELETE FROM ' . Tables::name($t) . ' WHERE sportjahr_id = %d', $sportjahr_id)); // phpcs:ignore WordPress.DB.PreparedSQL
				if ($r === false) {
					throw new \RuntimeException(sprintf('Fehler beim Löschen in %s: %s', $t, $wpdb->last_error));
				}
			}
			$this->loeschen_in('schuetze', 'id', $schuetzen);
			$wpdb->query($wpdb->prepare('DELETE FROM ' . Tables::name('sportjahr') . ' WHERE id = %d', $sportjahr_id)); // phpcs:ignore WordPress.DB.PreparedSQL
		} catch (\Throwable $e) {
			$wpdb->query('ROLLBACK');
			throw $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage(), 0, $e);
		}
		$wpdb->query('COMMIT');

		Protokoll::admin(
			'sportjahr.testjahr_entfernt',
			sprintf('Testjahr %d (%s) entfernt', (int) $sj['jahr'], (string) $sj['bezeichnung']),
			null,
			null,
			'sportjahr',
			$sportjahr_id,
			['zaehlung' => $zaehlung, 'sicherung' => $sicherung]
		);
		return ['zaehlung' => $zaehlung, 'sicherung' => $sicherung];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function sportjahr(int $sportjahr_id): array {
		$sj = (new SportjahrRepository())->find($sportjahr_id);
		if ($sj === null) {
			throw new \RuntimeException(__('Sportjahr nicht gefunden.', 'ksv-km-meldeportal'));
		}
		if (!self::ist_testjahr($sj)) {
			throw new \RuntimeException(__('Nur ein nicht aktives Sportjahr mit „TEST“ in der Bezeichnung kann entfernt werden.', 'ksv-km-meldeportal'));
		}
		return $sj;
	}

	/**
	 * Schützen, die nur in diesem Sportjahr gemeldet sind und sonst nirgends vorkommen.
	 *
	 * @return list<int>
	 */
	private function nur_hier_schuetzen(int $sportjahr_id): array {
		global $wpdb;
		$s = Tables::name('schuetze');
		$e = Tables::name('einzelmeldung');
		$h = Tables::name('hoehermeldung');
		$sql = "SELECT DISTINCT x.id FROM {$s} x
			WHERE (x.id IN (SELECT schuetze_id FROM {$e} WHERE sportjahr_id = %d AND schuetze_id IS NOT NULL)
				OR x.id IN (SELECT schuetze_id FROM {$h} WHERE sportjahr_id = %d))
			AND x.id NOT IN (SELECT schuetze_id FROM {$e} WHERE sportjahr_id <> %d AND schuetze_id IS NOT NULL)
			AND x.id NOT IN (SELECT schuetze_id FROM {$h} WHERE sportjahr_id <> %d)";
		$ids = $wpdb->get_col($wpdb->prepare($sql, $sportjahr_id, $sportjahr_id, $sportjahr_id, $sportjahr_id)); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map('intval', is_array($ids) ? $ids : []);
	}

	/**
	 * @return list<int>
	 */
	private function ids(string $tabelle, int $sportjahr_id): array {
		global $wpdb;
		$ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Tables::name($tabelle) . ' WHERE sportjahr_id = %d', $sportjahr_id)); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map('intval', is_array($ids) ? $ids : []);
	}

	/**
	 * @param list<int> $eltern
	 * @return list<int>
	 */
	private function kind_ids(string $tabelle, string $spalte, array $eltern): array {
		global $wpdb;
		if ($eltern === []) {
			return [];
		}
		$liste = implode(',', array_map('intval', $eltern));
		$ids = $wpdb->get_col('SELECT id FROM ' . Tables::name($tabelle) . " WHERE {$spalte} IN ({$liste})"); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map('intval', is_array($ids) ? $ids : []);
	}

	/**
	 * @param list<int> $werte
	 */
	private function loeschen_in(string $tabelle, string $spalte, array $werte): void {
		global $wpdb;
		if ($werte === []) {
			return;
		}
		$liste = implode(',', array_map('intval', $werte));
		if ($wpdb->query('DELETE FROM ' . Tables::name($tabelle) . " WHERE {$spalte} IN ({$liste})") === false) { // phpcs:ignore WordPress.DB.PreparedSQL
			throw new \RuntimeException(sprintf('Fehler beim Löschen in %s: %s', $tabelle, $wpdb->last_error));
		}
	}

	/**
	 * Vollständige Sicherung aller betroffenen Zeilen als JSON im Archivordner.
	 *
	 * @param array<string, mixed> $sj
	 * @throws \RuntimeException
	 */
	private function sichern(int $sportjahr_id, array $sj): string {
		global $wpdb;
		$daten = ['sportjahr' => $sj, 'erstellt_am' => Clock::now_utc(), 'plugin' => defined('KMM_VERSION') ? KMM_VERSION : '', 'tabellen' => []];
		foreach (self::SPORTJAHR_TABELLEN as $t) {
			$daten['tabellen'][ $t ] = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Tables::name($t) . ' WHERE sportjahr_id = %d', $sportjahr_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		$tage = $this->ids('wettkampftag', $sportjahr_id);
		$durchgaenge = $this->kind_ids('durchgang', 'wettkampftag_id', $tage);
		foreach ([
			'einheit'             => ['wettkampftag_id', $tage],
			'durchgang'           => ['id', $durchgaenge],
			'durchgang_zulassung' => ['durchgang_id', $durchgaenge],
			'sitzung'             => ['magic_link_id', $this->ids('magic_link', $sportjahr_id)],
			'schuetze'            => ['id', $this->nur_hier_schuetzen($sportjahr_id)],
		] as $t => [$spalte, $werte]) {
			$daten['tabellen'][ $t ] = $werte === [] ? [] : (array) $wpdb->get_results('SELECT * FROM ' . Tables::name($t) . " WHERE {$spalte} IN (" . implode(',', array_map('intval', $werte)) . ')', ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		$ordner = (new Abschluss())->archivordner();
		$json = wp_json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$name = sprintf('kmm-testjahr-%d-%s.json', (int) $sj['jahr'], gmdate('Ymd-His'));
		if ($ordner === '' || $json === false || file_put_contents($ordner . '/' . $name, $json) === false) {
			throw new \RuntimeException(__('Die Sicherung konnte nicht geschrieben werden; es wurde nichts gelöscht.', 'ksv-km-meldeportal'));
		}
		return $name;
	}
}
