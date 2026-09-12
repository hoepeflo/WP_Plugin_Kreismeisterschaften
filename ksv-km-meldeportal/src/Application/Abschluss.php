<?php
/**
 * Abschluss eines Sportjahres mit Anonymisierung (Konzept 7.4).
 *
 * Meldungen bleiben dauerhaft für die Statistik erhalten, aber ohne Personenbezug:
 * entfernt werden Name, Vorname, Geburtsdatum, Mitgliedsnummer und Ansprechpartner.
 * Erhalten bleiben Verein, Disziplin, Start- und eigentliche Klasse, Geschlecht,
 * Mannschaftszugehörigkeit und Startgeld – damit bleiben Teilnehmerzahlen je Verein,
 * Disziplin, Klasse und Jahr auswertbar.
 *
 * Konkret heißt das:
 *   - kmm_einzelmeldung: schuetze_id → NULL (die Verbindung zur Person fällt weg)
 *   - kmm_meldung: Ansprechpartner (Name, E-Mail, Telefon) → leer
 *   - kmm_aenderung: Text und Details des Änderungsprotokolls → leer (enthalten Namen)
 *   - kmm_protokoll: Zusammenfassung und Details dieses Sportjahres → leer; Aktion,
 *     Zeitpunkt, Verein und Akteurstyp bleiben, damit die Nachvollziehbarkeit bleibt.
 *     (Ohne diesen Schritt stünden die entfernten Namen weiter im Protokoll.)
 *   - kmm_magic_link: offene Zugänge werden widerrufen, Sitzungen beendet
 *   - veröffentlichte Startpläne des Jahres werden ausgeblendet
 *
 * Die Schützenliste der Vereine ist davon unabhängig (Konzept 7.2).
 *
 * Vor der Anonymisierung wird eine Sicherung aller betroffenen Zeilen als JSON in einem
 * geschützten Ordner unter uploads/ abgelegt; der Dateiname steht am Sportjahr. Der
 * Abschluss lässt sich zurücknehmen, solange nicht anonymisiert wurde.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Auth\Rechte;
use KSV\KMM\Domain\WettkampftagStatus;
use KSV\KMM\Infrastructure\Database\Tables;
use KSV\KMM\Infrastructure\Repository\AenderungRepository;
use KSV\KMM\Infrastructure\Repository\BelegRepository;
use KSV\KMM\Infrastructure\Repository\EinzelmeldungRepository;
use KSV\KMM\Infrastructure\Repository\MagicLinkRepository;
use KSV\KMM\Infrastructure\Repository\MeldungRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;
use KSV\KMM\Infrastructure\Repository\WettkampftagRepository;
use KSV\KMM\Support\Clock;

final class Abschluss {

	/** Unterordner in uploads/ für die Sicherungen. */
	public const ORDNER = 'kmm-archiv';

	private SportjahrRepository $sportjahre;

	public function __construct() {
		$this->sportjahre = new SportjahrRepository();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function sportjahr(int $sportjahr_id): array {
		$sj = $this->sportjahre->find($sportjahr_id);
		if ($sj === null) {
			throw new \RuntimeException('Sportjahr nicht gefunden.');
		}
		return $sj;
	}

	private function recht(): void {
		if (!Rechte::ist_admin()) {
			throw new \RuntimeException('Nur Administratoren dürfen ein Sportjahr abschließen.');
		}
	}

	// ----- Vorprüfung -------------------------------------------------------------------------

	/**
	 * Hinweise vor dem Abschluss – keine Sperren, nur Warnungen (Konzept 7.4, 11.3).
	 *
	 * @return array{hinweise: string[], meldungen: int, starter: int, vereine_ohne_beleg: string[], offene_aenderungen: int, tage_offen: int}
	 */
	public function pruefen(int $sportjahr_id): array {
		$sj = $this->sportjahr($sportjahr_id);
		$hinweise = [];
		$meldungen = new MeldungRepository();
		$eingereicht = 0;
		$entwurf = [];
		$vereine = [];
		foreach ((new VereinRepository())->all() as $v) {
			$vereine[ (int) $v['id'] ] = (string) $v['name'];
		}
		foreach ($meldungen->by_sportjahr($sportjahr_id) as $vid => $m) {
			if (in_array((string) $m['status'], [MeldungRepository::STATUS_ENTWURF, MeldungRepository::STATUS_OFFEN], true)) {
				$entwurf[] = $vereine[ $vid ] ?? ('#' . $vid);
				continue;
			}
			$eingereicht++;
		}
		if ($entwurf !== []) {
			$hinweise[] = sprintf('%d Meldung(en) wurden nie eingereicht: %s.', count($entwurf), implode(', ', array_slice($entwurf, 0, 8)));
		}
		$belege = [];
		foreach ((new BelegRepository())->where(['sportjahr_id' => $sportjahr_id]) as $b) {
			$belege[ (int) $b['verein_id'] ] = true;
		}
		$ohne_beleg = [];
		foreach ($meldungen->by_sportjahr($sportjahr_id) as $vid => $m) {
			if (!in_array((string) $m['status'], [MeldungRepository::STATUS_ENTWURF, MeldungRepository::STATUS_OFFEN], true) && !isset($belege[ $vid ])) {
				$ohne_beleg[] = $vereine[ $vid ] ?? ('#' . $vid);
			}
		}
		if ($ohne_beleg !== []) {
			$hinweise[] = sprintf('Für %d Verein(e) fehlt noch ein Buchhaltungsbeleg: %s.', count($ohne_beleg), implode(', ', array_slice($ohne_beleg, 0, 8)));
		}
		$offen = count((new AenderungRepository())->where(['sportjahr_id' => $sportjahr_id, 'versendet_am' => null]));
		if ($offen > 0) {
			$hinweise[] = sprintf('%d Änderung(en) wurden den Vereinen noch nicht per Sammelmail mitgeteilt.', $offen);
		}
		$tage_offen = 0;
		foreach ((new WettkampftagRepository())->by_sportjahr($sportjahr_id) as $tag) {
			if ((string) $tag['status'] !== WettkampftagStatus::VEROEFFENTLICHT) {
				$tage_offen++;
			}
		}
		if ($tage_offen > 0) {
			$hinweise[] = sprintf('%d Wettkampftag(e) sind noch nicht veröffentlicht.', $tage_offen);
		}
		$starter = count((new EinzelmeldungRepository())->by_sportjahr($sportjahr_id));
		if ($sj['abgeschlossen_am'] !== null) {
			$hinweise[] = sprintf('Das Sportjahr ist bereits am %s abgeschlossen worden.', Clock::format_local((string) $sj['abgeschlossen_am']));
		}
		return [
			'hinweise'           => $hinweise,
			'meldungen'          => $eingereicht,
			'starter'            => $starter,
			'vereine_ohne_beleg' => $ohne_beleg,
			'offene_aenderungen' => $offen,
			'tage_offen'         => $tage_offen,
		];
	}

	// ----- Abschluss --------------------------------------------------------------------------

	/**
	 * Sportjahr abschließen. Ohne $anonymisieren wird es nur geschlossen (kein Schreiben
	 * mehr, Zugänge enden); die Anonymisierung kann später nachgeholt werden.
	 *
	 * @return array{sicherung: string, anonymisiert: array<string, int>, tage: int}
	 */
	public function abschliessen(int $sportjahr_id, bool $anonymisieren = true): array {
		$this->recht();
		$sj = $this->sportjahr($sportjahr_id);
		if ($sj['anonymisiert_am'] !== null) {
			throw new \RuntimeException('Das Sportjahr ist bereits anonymisiert.');
		}
		$jetzt = Clock::now_utc();
		$sicherung = $this->sicherung_schreiben($sportjahr_id, (int) $sj['jahr']);
		$daten = ['abgeschlossen_am' => $jetzt];
		if ($sicherung !== '') {
			$daten['abschluss_backup'] = $sicherung;
		}
		$this->sportjahre->update($sportjahr_id, $daten);

		$tage = $this->startplaene_ausblenden($sportjahr_id, $jetzt);
		$this->zugaenge_beenden($sportjahr_id, $jetzt);

		$anonymisiert = [];
		if ($anonymisieren) {
			$anonymisiert = $this->anonymisieren($sportjahr_id);
			$this->sportjahre->update($sportjahr_id, ['anonymisiert_am' => $jetzt]);
		}
		Protokoll::admin(
			'sportjahr.abschliessen',
			sprintf('%s abgeschlossen%s (Sicherung: %s)', $this->titel($sj), $anonymisieren ? ', Meldungen anonymisiert' : '', $sicherung !== '' ? $sicherung : 'keine'),
			$sportjahr_id,
			null,
			'sportjahr',
			$sportjahr_id,
			['anonymisiert' => $anonymisiert, 'tage_ausgeblendet' => $tage]
		);
		return ['sicherung' => $sicherung, 'anonymisiert' => $anonymisiert, 'tage' => $tage];
	}

	/** Abschluss zurücknehmen – nur solange nicht anonymisiert wurde. */
	public function oeffnen(int $sportjahr_id): void {
		$this->recht();
		$sj = $this->sportjahr($sportjahr_id);
		if ($sj['abgeschlossen_am'] === null) {
			throw new \RuntimeException('Das Sportjahr ist nicht abgeschlossen.');
		}
		if ($sj['anonymisiert_am'] !== null) {
			throw new \RuntimeException('Das Sportjahr ist anonymisiert; das lässt sich nicht zurücknehmen.');
		}
		$this->sportjahre->update($sportjahr_id, ['abgeschlossen_am' => null]);
		(new WettkampftagRepository())->ausblenden_zuruecknehmen($sportjahr_id);
		Protokoll::admin('sportjahr.oeffnen', sprintf('%s wieder geöffnet', $this->titel($sj)), $sportjahr_id, null, 'sportjahr', $sportjahr_id);
	}

	/**
	 * Anonymisierung nachholen (Sportjahr ist schon abgeschlossen).
	 *
	 * @return array<string, int>
	 */
	public function nachtraeglich_anonymisieren(int $sportjahr_id): array {
		$this->recht();
		$sj = $this->sportjahr($sportjahr_id);
		if ($sj['abgeschlossen_am'] === null) {
			throw new \RuntimeException('Erst abschließen, dann anonymisieren.');
		}
		if ($sj['anonymisiert_am'] !== null) {
			throw new \RuntimeException('Das Sportjahr ist bereits anonymisiert.');
		}
		$r = $this->anonymisieren($sportjahr_id);
		$this->sportjahre->update($sportjahr_id, ['anonymisiert_am' => Clock::now_utc()]);
		Protokoll::admin('sportjahr.anonymisieren', sprintf('%s anonymisiert', $this->titel($sj)), $sportjahr_id, null, 'sportjahr', $sportjahr_id, $r);
		return $r;
	}

	/**
	 * @param array<string, mixed> $sj
	 */
	private function titel(array $sj): string {
		return (string) $sj['bezeichnung'] !== '' ? (string) $sj['bezeichnung'] : sprintf('Sportjahr %d', (int) $sj['jahr']);
	}

	// ----- Einzelschritte ---------------------------------------------------------------------

	/**
	 * Personenbezug aus den Meldungen und Protokollen dieses Jahres entfernen.
	 *
	 * @return array<string, int> Tabelle => geänderte Zeilen
	 */
	private function anonymisieren(int $sportjahr_id): array {
		global $wpdb;
		$out = [];
		$out['einzelmeldung'] = (int) $wpdb->query($wpdb->prepare('UPDATE ' . Tables::name('einzelmeldung') . ' SET schuetze_id = NULL WHERE sportjahr_id = %d AND schuetze_id IS NOT NULL', $sportjahr_id)); // phpcs:ignore WordPress.DB.PreparedSQL
		$out['meldung'] = (int) $wpdb->query($wpdb->prepare('UPDATE ' . Tables::name('meldung') . " SET ansprechpartner_name = '', ansprechpartner_email = '', ansprechpartner_telefon = '' WHERE sportjahr_id = %d", $sportjahr_id)); // phpcs:ignore WordPress.DB.PreparedSQL
		$out['aenderung'] = (int) $wpdb->query($wpdb->prepare('UPDATE ' . Tables::name('aenderung') . " SET text = '', details = NULL WHERE sportjahr_id = %d", $sportjahr_id)); // phpcs:ignore WordPress.DB.PreparedSQL
		$out['protokoll'] = (int) $wpdb->query($wpdb->prepare('UPDATE ' . Tables::name('protokoll') . " SET zusammenfassung = '', details = NULL WHERE sportjahr_id = %d", $sportjahr_id)); // phpcs:ignore WordPress.DB.PreparedSQL
		return $out;
	}

	/** Veröffentlichte Startpläne des Jahres ausblenden (Konzept 7.4). */
	private function startplaene_ausblenden(int $sportjahr_id, string $jetzt): int {
		$repo = new WettkampftagRepository();
		$n = 0;
		foreach ($repo->by_sportjahr($sportjahr_id) as $tag) {
			if ($tag['ausgeblendet_am'] === null) {
				$repo->update((int) $tag['id'], ['ausgeblendet_am' => $jetzt]);
				$n++;
			}
		}
		return $n;
	}

	/** Zugänge des Jahres schließen: offene Magic Links widerrufen, Sitzungen beenden. */
	private function zugaenge_beenden(int $sportjahr_id, string $jetzt): void {
		$links = new MagicLinkRepository();
		foreach ($links->where(['sportjahr_id' => $sportjahr_id, 'widerrufen_am' => null]) as $l) {
			$links->update((int) $l['id'], ['widerrufen_am' => $jetzt]);
		}
	}

	/**
	 * Sicherung aller Zeilen dieses Sportjahres als JSON in einem geschützten Ordner.
	 *
	 * @return string Dateiname relativ zum Archivordner, leer bei Schreibfehler
	 */
	private function sicherung_schreiben(int $sportjahr_id, int $jahr): string {
		global $wpdb;
		$tabellen = ['sportjahr' => 'id', 'meldung' => 'sportjahr_id', 'einzelmeldung' => 'sportjahr_id', 'mannschaft' => 'sportjahr_id', 'aenderung' => 'sportjahr_id', 'beleg' => 'sportjahr_id', 'protokoll' => 'sportjahr_id', 'wettkampftag' => 'sportjahr_id', 'einheit' => null, 'durchgang' => null, 'buchung' => 'sportjahr_id', 'hoehermeldung' => 'sportjahr_id'];
		$daten = ['sportjahr_id' => $sportjahr_id, 'jahr' => $jahr, 'erstellt_am' => Clock::now_utc(), 'plugin' => defined('KMM_VERSION') ? KMM_VERSION : '', 'tabellen' => []];
		foreach ($tabellen as $tabelle => $spalte) {
			if ($spalte === null) {
				continue;
			}
			$rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Tables::name($tabelle) . ' WHERE ' . $spalte . ' = %d', $sportjahr_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
			$daten['tabellen'][ $tabelle ] = is_array($rows) ? $rows : [];
		}
		// Schützen, auf die Meldungen dieses Jahres zeigen (die Liste selbst bleibt bestehen).
		$schuetzen = $wpdb->get_results($wpdb->prepare('SELECT s.* FROM ' . Tables::name('schuetze') . ' s INNER JOIN ' . Tables::name('einzelmeldung') . ' e ON e.schuetze_id = s.id WHERE e.sportjahr_id = %d GROUP BY s.id', $sportjahr_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
		$daten['tabellen']['schuetze'] = is_array($schuetzen) ? $schuetzen : [];

		$verzeichnis = $this->archivordner();
		if ($verzeichnis === '') {
			return '';
		}
		$name = sprintf('kmm-sportjahr-%d-%s.json', $jahr, gmdate('Ymd-His'));
		$json = wp_json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json === false || file_put_contents($verzeichnis . '/' . $name, $json) === false) {
			return '';
		}
		return $name;
	}

	/** Geschützter Archivordner unter uploads/; leer, wenn er nicht angelegt werden kann. */
	public function archivordner(): string {
		$basis = wp_upload_dir();
		if (!empty($basis['error'])) {
			return '';
		}
		$pfad = trailingslashit((string) $basis['basedir']) . self::ORDNER;
		if (!is_dir($pfad) && !wp_mkdir_p($pfad)) {
			return '';
		}
		if (!file_exists($pfad . '/index.php')) {
			file_put_contents($pfad . '/index.php', "<?php\n// Silence is golden.\n");
		}
		if (!file_exists($pfad . '/.htaccess')) {
			file_put_contents($pfad . '/.htaccess', "Deny from all\n");
		}
		return $pfad;
	}
}
