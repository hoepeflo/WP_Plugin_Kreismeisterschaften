<?php
/**
 * Externe Sicherung aller Portal-Daten (docs/SICHERUNG.md).
 *
 * Ablauf: Alle kmm_*-Tabellen werden als JSON zusammengefasst, komprimiert und mit dem
 * öffentlichen Schlüssel verschlüsselt (Verschluesselung). Die Datei landet zuerst in einem
 * geschützten Ordner unter uploads/ und wird dann an den Briefkasten auf dem zweiten
 * Webspace geschickt (tools/briefkasten/). Der Briefkasten nimmt nur neue Dateien an und
 * kann nichts löschen; aufgeräumt wird dort von einem eigenen Cronjob.
 *
 * Auslöser:
 *   - täglich ab der eingestellten Uhrzeit (über kmm_hourly_tasks), nur im Zeitraum
 *   - nach Ereignissen aus dem Protokoll (Zeitplan::EREIGNISSE), gesammelt 10 Minuten
 *   - von Hand auf der Seite „Sicherung“, auch außerhalb des Zeitraums
 *
 * Schlägt die Übertragung fehl, bleibt die Datei lokal als „offen“ und wird stündlich
 * erneut geschickt; der Administrator bekommt höchstens einmal am Tag eine Mail.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application\Sicherung;

use KSV\KMM\Application\Abschluss;
use KSV\KMM\Application\Protokoll;
use KSV\KMM\Infrastructure\Database\Schema;
use KSV\KMM\Infrastructure\Database\Tables;
use KSV\KMM\Support\Clock;
use KSV\KMM\Support\Settings;

final class Sicherung {

	public const EREIGNIS_HOOK = 'kmm_sicherung_ereignis';
	public const STATUS_OPTION = 'kmm_sicherung_status';
	public const UNTERORDNER   = 'sicherung';
	public const FORMAT        = 'kmm-sicherung';
	public const FORMAT_VERSION = 1;

	/** Verhindert, dass die Protokolleinträge der Sicherung selbst neue Sicherungen auslösen. */
	private static bool $laeuft = false;

	public static function register(): void {
		add_action('kmm_protokolliert', [self::class, 'nach_protokoll'], 10, 1);
		add_action(self::EREIGNIS_HOOK, [self::class, 'ereignis_ausfuehren']);
		add_action('kmm_hourly_tasks', [self::class, 'cron']);
	}

	// ----- Auslöser ---------------------------------------------------------------------------

	/** Nach einem Protokolleintrag: bei passenden Aktionen eine Sicherung in 10 Minuten planen. */
	public static function nach_protokoll(string $aktion = ''): void {
		if (self::$laeuft || !Zeitplan::ist_ereignis($aktion) || !self::eingerichtet() || !self::im_zeitraum()) {
			return;
		}
		if (wp_next_scheduled(self::EREIGNIS_HOOK) === false) {
			wp_schedule_single_event(time() + Zeitplan::SAMMELZEIT_SEKUNDEN, self::EREIGNIS_HOOK);
		}
	}

	public static function ereignis_ausfuehren(): void {
		if (self::eingerichtet() && self::im_zeitraum()) {
			self::erstellen('ereignis');
		}
	}

	/** Stündlich: tägliche Sicherung, offene Übertragungen nachholen, Lebenszeichen, Aufräumen. */
	public static function cron(): void {
		if (!self::eingerichtet()) {
			return;
		}
		$status = self::status();
		$jetzt = Clock::now()->setTimezone(wp_timezone());
		$heute = $jetzt->format('Y-m-d');
		$im_zeitraum = self::im_zeitraum();
		if ($im_zeitraum && Zeitplan::taeglich_faellig((string) $status['letzter_taeglicher_tag'], $heute, $jetzt->format('H:i'), (string) Settings::get('sicherung_uhrzeit'))) {
			self::status_setzen(['letzter_taeglicher_tag' => $heute]);
			self::erstellen('taeglich');
		}
		self::offene_senden();
		self::puls($im_zeitraum);
		self::lokal_aufraeumen();
	}

	public static function eingerichtet(): bool {
		return Verschluesselung::oeffentlich_roh((string) Settings::get('sicherung_schluessel')) !== ''
			&& (string) Settings::get('sicherung_ziel_url') !== ''
			&& (string) Settings::get('sicherung_ziel_token') !== '';
	}

	public static function im_zeitraum(): bool {
		$heute = Clock::now()->setTimezone(wp_timezone())->format('Y-m-d');
		return Zeitplan::im_zeitraum((bool) Settings::get('sicherung_aktiv'), (string) Settings::get('sicherung_von'), (string) Settings::get('sicherung_bis'), $heute);
	}

	// ----- Erstellen und Senden ---------------------------------------------------------------

	/**
	 * Sicherung erzeugen, lokal ablegen und an den Briefkasten schicken.
	 *
	 * @return array{ok: bool, datei: string, gesendet: bool, fehler: string, bytes: int}
	 */
	public static function erstellen(string $anlass): array {
		$ergebnis = ['ok' => false, 'datei' => '', 'gesendet' => false, 'fehler' => '', 'bytes' => 0];
		self::$laeuft = true;
		try {
			$schluessel = (string) Settings::get('sicherung_schluessel');
			$json = wp_json_encode(self::daten($anlass), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if ($json === false) {
				throw new \RuntimeException('Daten konnten nicht als JSON geschrieben werden.');
			}
			$inhalt = Verschluesselung::verschluesseln($json, $schluessel);
			$ordner = self::ordner();
			if ($ordner === '') {
				throw new \RuntimeException('Der Sicherungsordner unter uploads/ konnte nicht angelegt werden.');
			}
			$name = Zeitplan::dateiname($anlass, time());
			if (file_put_contents($ordner . '/' . $name, $inhalt) === false) {
				throw new \RuntimeException('Die Sicherung konnte nicht lokal gespeichert werden.');
			}
			$ergebnis['ok'] = true;
			$ergebnis['datei'] = $name;
			$ergebnis['bytes'] = strlen($inhalt);
			self::status_setzen(['letzte_erstellt' => Clock::now_utc(), 'letzte_datei' => $name, 'letzte_bytes' => strlen($inhalt)]);
			self::offen_merken($name);
			$fehler = self::senden($name, $inhalt);
			$ergebnis['gesendet'] = $fehler === '';
			$ergebnis['fehler'] = $fehler;
		} catch (\Throwable $e) {
			$ergebnis['fehler'] = $e->getMessage();
			self::fehler($e->getMessage());
		} finally {
			Protokoll::system(
				'sicherung.erstellen',
				$ergebnis['ok']
					? sprintf('Sicherung (%s) %s, %s', $anlass, $ergebnis['gesendet'] ? 'übertragen' : 'lokal gespeichert, Übertragung offen', size_format($ergebnis['bytes']))
					: sprintf('Sicherung (%s) fehlgeschlagen: %s', $anlass, $ergebnis['fehler']),
				null,
				null,
				'',
				null,
				['datei' => $ergebnis['datei'], 'fehler' => $ergebnis['fehler']]
			);
			self::$laeuft = false;
		}
		return $ergebnis;
	}

	/**
	 * Alle Tabellen des Plugins vollständig.
	 *
	 * @return array<string, mixed>
	 */
	public static function daten(string $anlass): array {
		global $wpdb;
		$tabellen = [];
		// Alle Tabellen aus demselben Stand lesen (InnoDB-Snapshot), damit keine Meldung auf
		// einen Schützen zeigt, der erst nach dem Lesen der Schützentabelle angelegt wurde.
		$wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');
		try {
			foreach (Schema::TABLES as $tabelle) {
				$wpdb->last_error = '';
				$rows = $wpdb->get_results('SELECT * FROM ' . Tables::name($tabelle), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
				// Ein Lesefehler darf nie als leere Tabelle in der Sicherung landen: Beim
				// Einspielen würde die Tabelle sonst geleert.
				$fehler = self::db_fehler();
				if (!is_array($rows) || $fehler !== '') {
					throw new \RuntimeException(sprintf('Tabelle %s konnte nicht gelesen werden: %s', $tabelle, $fehler !== '' ? $fehler : 'unbekannter Fehler'));
				}
				$tabellen[ $tabelle ] = $rows;
			}
		} finally {
			$wpdb->query('COMMIT');
		}
		return [
			'format'      => self::FORMAT,
			'version'     => self::FORMAT_VERSION,
			'anlass'      => $anlass,
			'erstellt_am' => Clock::now_utc(),
			'website'     => home_url('/'),
			'plugin'      => defined('KMM_VERSION') ? KMM_VERSION : '',
			'schema'      => (int) get_option(Schema::OPTION_VERSION, 0),
			'tabellen'    => $tabellen,
		];
	}

	/** Letzter Datenbankfehler (eigene Methode, damit er nach jeder Abfrage frisch gelesen wird). */
	private static function db_fehler(): string {
		global $wpdb;
		return (string) $wpdb->last_error;
	}

	/** Datei an den Briefkasten schicken; leer bei Erfolg, sonst die Fehlermeldung. */
	private static function senden(string $name, string $inhalt): string {
		$antwort = wp_remote_post(self::ziel('einwurf'), [
			'timeout' => 60,
			'headers' => [
				'Authorization' => 'Bearer ' . (string) Settings::get('sicherung_ziel_token'),
				'Content-Type'  => 'application/octet-stream',
				'X-KMM-Datei'   => $name,
			],
			'body'    => $inhalt,
		]);
		$fehler = self::antwort_fehler($antwort);
		if ($fehler !== '') {
			self::fehler('Übertragung von ' . $name . ': ' . $fehler);
			return $fehler;
		}
		self::offen_erledigt($name);
		self::status_setzen(['letzte_gesendet' => Clock::now_utc(), 'letzter_fehler' => '', 'letzter_fehler_am' => '']);
		return '';
	}

	/** Lebenszeichen an den Briefkasten: Er warnt, wenn im Zeitraum keine Sicherungen kommen. */
	private static function puls(bool $im_zeitraum): void {
		$antwort = wp_remote_post(self::ziel('puls'), [
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Bearer ' . (string) Settings::get('sicherung_ziel_token'),
				'Content-Type'  => 'application/json',
			],
			'body'    => (string) wp_json_encode(['im_zeitraum' => $im_zeitraum, 'zeit' => time()]),
		]);
		if (self::antwort_fehler($antwort) === '') {
			self::status_setzen(['letzter_puls' => Clock::now_utc()]);
		}
	}

	/**
	 * Verbindung zum Briefkasten prüfen (Seite „Sicherung“).
	 *
	 * @return string leer = in Ordnung
	 */
	public static function verbindung_testen(): string {
		$antwort = wp_remote_post(self::ziel('test'), [
			'timeout' => 20,
			'headers' => ['Authorization' => 'Bearer ' . (string) Settings::get('sicherung_ziel_token')],
			'body'    => '',
		]);
		return self::antwort_fehler($antwort);
	}

	/**
	 * @param array<string, mixed>|\WP_Error $antwort
	 */
	private static function antwort_fehler($antwort): string {
		if (is_wp_error($antwort)) {
			return $antwort->get_error_message();
		}
		$code = (int) wp_remote_retrieve_response_code($antwort);
		$body = json_decode((string) wp_remote_retrieve_body($antwort), true);
		if ($code === 200 && is_array($body) && ($body['ok'] ?? false) === true) {
			return '';
		}
		$meldung = is_array($body) && isset($body['fehler']) ? (string) $body['fehler'] : 'unerwartete Antwort';
		return sprintf('HTTP %d: %s', $code, $meldung);
	}

	private static function ziel(string $aktion): string {
		return add_query_arg('aktion', $aktion, (string) Settings::get('sicherung_ziel_url'));
	}

	// ----- Offene Übertragungen, lokaler Ordner -----------------------------------------------

	private static function offen_merken(string $name): void {
		$offen = self::offen();
		$offen[] = $name;
		update_option('kmm_sicherung_offen', array_values(array_unique($offen)), false);
	}

	private static function offen_erledigt(string $name): void {
		update_option('kmm_sicherung_offen', array_values(array_diff(self::offen(), [$name])), false);
	}

	/** @return list<string> */
	public static function offen(): array {
		$offen = get_option('kmm_sicherung_offen', []);
		return is_array($offen) ? array_values(array_filter(array_map('strval', $offen), [Zeitplan::class, 'gueltiger_dateiname'])) : [];
	}

	private static function offene_senden(): void {
		$ordner = self::ordner();
		foreach (self::offen() as $name) {
			$pfad = $ordner . '/' . $name;
			$inhalt = is_file($pfad) ? file_get_contents($pfad) : false;
			if ($inhalt === false) {
				self::offen_erledigt($name); // lokal schon weg, nichts mehr zu schicken
				continue;
			}
			if (self::senden($name, $inhalt) !== '') {
				return; // Ziel weiter nicht erreichbar, nächste Stunde erneut
			}
		}
	}

	/** Lokale Kopien älter als die eingestellte Frist löschen (offene bleiben). */
	private static function lokal_aufraeumen(): void {
		$ordner = self::ordner();
		if ($ordner === '') {
			return;
		}
		$grenze = time() - max(1, (int) Settings::get('sicherung_lokal_tage')) * DAY_IN_SECONDS;
		$offen = self::offen();
		foreach ((array) glob($ordner . '/kmm-sicherung-*.kmmsic') as $pfad) {
			$pfad = (string) $pfad;
			if (!in_array(basename($pfad), $offen, true) && (int) filemtime($pfad) < $grenze) {
				wp_delete_file($pfad);
			}
		}
	}

	/**
	 * Lokale Sicherungen, neueste zuerst.
	 *
	 * @return list<array{name: string, bytes: int, zeit: int, offen: bool}>
	 */
	public static function lokale(): array {
		$ordner = self::ordner();
		if ($ordner === '') {
			return [];
		}
		$offen = self::offen();
		$liste = [];
		foreach ((array) glob($ordner . '/kmm-sicherung-*.kmmsic') as $pfad) {
			$pfad = (string) $pfad;
			$liste[] = ['name' => basename($pfad), 'bytes' => (int) filesize($pfad), 'zeit' => (int) filemtime($pfad), 'offen' => in_array(basename($pfad), $offen, true)];
		}
		usort($liste, static fn(array $a, array $b): int => $b['name'] <=> $a['name']);
		return $liste;
	}

	/** Geschützter Ordner uploads/kmm-archiv/sicherung; leer, wenn er nicht angelegt werden kann. */
	public static function ordner(): string {
		$basis = (new Abschluss())->archivordner();
		if ($basis === '') {
			return '';
		}
		$pfad = $basis . '/' . self::UNTERORDNER;
		if (!is_dir($pfad) && !wp_mkdir_p($pfad)) {
			return '';
		}
		if (!file_exists($pfad . '/.htaccess')) {
			file_put_contents($pfad . '/.htaccess', "Deny from all\n");
		}
		if (!file_exists($pfad . '/index.php')) {
			file_put_contents($pfad . '/index.php', "<?php\n// Silence is golden.\n");
		}
		return $pfad;
	}

	// ----- Status und Warnung -----------------------------------------------------------------

	/**
	 * @return array{letzte_erstellt: string, letzte_gesendet: string, letzte_datei: string, letzte_bytes: int, letzter_fehler: string, letzter_fehler_am: string, letzte_warnung: string, letzter_taeglicher_tag: string, letzter_puls: string}
	 */
	public static function status(): array {
		$s = get_option(self::STATUS_OPTION, []);
		$s = is_array($s) ? $s : [];
		return [
			'letzte_erstellt'        => (string) ($s['letzte_erstellt'] ?? ''),
			'letzte_gesendet'        => (string) ($s['letzte_gesendet'] ?? ''),
			'letzte_datei'           => (string) ($s['letzte_datei'] ?? ''),
			'letzte_bytes'           => (int) ($s['letzte_bytes'] ?? 0),
			'letzter_fehler'         => (string) ($s['letzter_fehler'] ?? ''),
			'letzter_fehler_am'      => (string) ($s['letzter_fehler_am'] ?? ''),
			'letzte_warnung'         => (string) ($s['letzte_warnung'] ?? ''),
			'letzter_taeglicher_tag' => (string) ($s['letzter_taeglicher_tag'] ?? ''),
			'letzter_puls'           => (string) ($s['letzter_puls'] ?? ''),
		];
	}

	/**
	 * @param array<string, string|int> $werte
	 */
	private static function status_setzen(array $werte): void {
		update_option(self::STATUS_OPTION, array_merge(self::status(), $werte), false);
	}

	/** Fehler merken und den Administrator höchstens einmal in 24 Stunden per Mail warnen. */
	private static function fehler(string $text): void {
		$status = self::status();
		self::status_setzen(['letzter_fehler' => mb_substr($text, 0, 500), 'letzter_fehler_am' => Clock::now_utc()]);
		if ($status['letzte_warnung'] !== '' && strtotime($status['letzte_warnung'] . ' UTC') > time() - DAY_IN_SECONDS) {
			return;
		}
		self::status_setzen(['letzte_warnung' => Clock::now_utc()]);
		wp_mail(
			(string) get_option('admin_email'),
			'[KM-Portal] Sicherung fehlgeschlagen',
			"Die Sicherung des KM-Portals hat nicht geklappt:\n\n" . $text . "\n\nDie Datei bleibt auf dem Webspace und wird stündlich erneut übertragen.\nStatus: " . admin_url('admin.php?page=kmm-sicherung') . "\n"
		);
	}
}
