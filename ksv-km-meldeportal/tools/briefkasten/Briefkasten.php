<?php
/**
 * Briefkasten für die Sicherungen des KM-Portals (läuft auf dem zweiten Webspace).
 *
 * Nimmt verschlüsselte Sicherungen an, kann sie aber nicht löschen oder überschreiben:
 * Es gibt keinen Befehl dafür. Gelöscht wird nur von der Wartung (Cronjob) nach der
 * Aufbewahrungsfrist. Wer den Zugangsschlüssel des Portals stiehlt, kann also höchstens
 * neue Dateien einwerfen, aber nichts lesen oder entfernen.
 *
 * Ohne WordPress, ohne Abhängigkeiten, PHP ab 8.1. Siehe README.md.
 */

declare(strict_types=1);

final class Briefkasten {

	public const KENNUNG = "KMMSIC1\n";
	public const MUSTER  = '/^kmm-sicherung-\d{8}-\d{6}-[a-z-]{1,24}\.kmmsic$/';

	/** @var array<string, mixed> */
	private array $config;

	private string $ordner;

	/**
	 * @param array<string, mixed> $config aus config.php
	 */
	public function __construct(array $config) {
		$this->config = $config + [
			'einwurf_token'     => '',
			'wartung_token'     => '',
			'download_passwort' => '',
			'ordner'            => __DIR__ . '/daten',
			'aufbewahrung_tage' => 90,
			'max_mb'            => 50,
			'warn_mail'         => '',
			'warn_stunden'      => 36,
			'absender'          => '',
		];
		$this->ordner = rtrim((string) $this->config['ordner'], '/');
		if (strlen((string) $this->config['einwurf_token']) < 24) {
			throw new RuntimeException('einwurf_token in config.php fehlt oder ist kürzer als 24 Zeichen.');
		}
	}

	public function ordner(): string {
		if (!is_dir($this->ordner) && !mkdir($this->ordner, 0750, true) && !is_dir($this->ordner)) {
			throw new RuntimeException('Datenordner kann nicht angelegt werden.');
		}
		if (!is_file($this->ordner . '/.htaccess')) {
			file_put_contents($this->ordner . '/.htaccess', "Require all denied\nDeny from all\n");
		}
		return $this->ordner;
	}

	/** Bearer-Token aus dem Authorization-Header prüfen. */
	public function berechtigt(string $authorization): bool {
		if (!str_starts_with($authorization, 'Bearer ')) {
			return false;
		}
		return hash_equals((string) $this->config['einwurf_token'], substr($authorization, 7));
	}

	/**
	 * Neue Sicherung ablegen. Schon vorhandene Datei mit gleichem Inhalt gilt als Erfolg
	 * (erneuter Versand nach einer Zeitüberschreitung), abweichender Inhalt wird abgelehnt.
	 *
	 * @return array{0: int, 1: array<string, mixed>} HTTP-Status, Antwort
	 */
	public function einwurf(string $name, string $inhalt): array {
		if (preg_match(self::MUSTER, $name) !== 1) {
			return [400, ['ok' => false, 'fehler' => 'Ungültiger Dateiname.']];
		}
		if (!str_starts_with($inhalt, self::KENNUNG)) {
			return [400, ['ok' => false, 'fehler' => 'Keine verschlüsselte Sicherung des KM-Portals.']];
		}
		if (strlen($inhalt) > (int) $this->config['max_mb'] * 1024 * 1024) {
			return [413, ['ok' => false, 'fehler' => 'Datei zu groß.']];
		}
		$pfad = $this->ordner() . '/' . $name;
		$h = @fopen($pfad, 'x'); // nie überschreiben
		if ($h === false) {
			if (is_file($pfad) && hash_file('sha256', $pfad) === hash('sha256', $inhalt)) {
				return [200, ['ok' => true, 'datei' => $name, 'hinweis' => 'schon vorhanden']];
			}
			return [409, ['ok' => false, 'fehler' => 'Datei existiert bereits mit anderem Inhalt.']];
		}
		$ok = fwrite($h, $inhalt) === strlen($inhalt);
		fclose($h);
		if (!$ok) {
			@unlink($pfad);
			return [507, ['ok' => false, 'fehler' => 'Speichern fehlgeschlagen.']];
		}
		$this->zustand_setzen(['letzter_einwurf' => time()]);
		return [200, ['ok' => true, 'datei' => $name, 'bytes' => strlen($inhalt)]];
	}

	/**
	 * Lebenszeichen des Portals.
	 *
	 * @return array{0: int, 1: array<string, mixed>}
	 */
	public function puls(string $json): array {
		$daten = json_decode($json, true);
		$im_zeitraum = is_array($daten) && !empty($daten['im_zeitraum']);
		$z = $this->zustand();
		$werte = ['letzter_puls' => time(), 'im_zeitraum' => $im_zeitraum];
		// Beginn des Zeitraums merken: Erst 36 Stunden danach wird eine fehlende Sicherung gemeldet.
		if ($im_zeitraum && empty($z['im_zeitraum'])) {
			$werte['zeitraum_seit'] = time();
		}
		$this->zustand_setzen($werte);
		return [200, ['ok' => true]];
	}

	/**
	 * Aufräumen und bei Bedarf warnen. Läuft per Cronjob.
	 *
	 * @return array{geloescht: int, warnung: string}
	 */
	public function wartung(?int $jetzt = null): array {
		$jetzt ??= time();
		$grenze = $jetzt - max(1, (int) $this->config['aufbewahrung_tage']) * 86400;
		$geloescht = 0;
		$neueste = 0;
		foreach ($this->liste() as $d) {
			if ($d['zeit'] < $grenze) {
				if (@unlink($this->ordner() . '/' . $d['name'])) {
					$geloescht++;
				}
				continue;
			}
			$neueste = max($neueste, $d['zeit']);
		}
		$warnung = $this->warnung($jetzt, $neueste);
		$z = $this->zustand();
		$this->zustand_setzen(['letzte_wartung' => $jetzt]);
		if ($warnung !== '' && (int) ($z['letzte_warnung'] ?? 0) < $jetzt - 86400) {
			$this->zustand_setzen(['letzte_warnung' => $jetzt]);
			$this->mail('[KM-Portal] Sicherung: ' . $warnung, $warnung . "\n\nBitte im KM-Portal unter KM-Portal → Sicherung nachsehen.\n");
		}
		return ['geloescht' => $geloescht, 'warnung' => $warnung];
	}

	/** Warntext oder leer. Gewarnt wird erst, wenn sich das Portal schon einmal gemeldet hat. */
	public function warnung(int $jetzt, int $neueste): string {
		$z = $this->zustand();
		$puls = (int) ($z['letzter_puls'] ?? 0);
		$frist = max(1, (int) $this->config['warn_stunden']) * 3600;
		if ($puls === 0) {
			return '';
		}
		if ($puls < $jetzt - $frist) {
			return sprintf('Das KM-Portal hat sich seit %s nicht mehr gemeldet (Cronjob oder Website ausgefallen?).', date('d.m.Y H:i', $puls));
		}
		$seit = max($neueste, (int) ($z['zeitraum_seit'] ?? 0));
		if (!empty($z['im_zeitraum']) && $seit < $jetzt - $frist) {
			return $neueste === 0
				? 'Im Sicherungszeitraum ist noch keine Sicherung angekommen.'
				: sprintf('Seit %s ist keine neue Sicherung angekommen, obwohl der Sicherungszeitraum läuft.', date('d.m.Y H:i', $neueste));
		}
		return '';
	}

	/**
	 * Abgelegte Sicherungen, neueste zuerst.
	 *
	 * @return list<array{name: string, bytes: int, zeit: int}>
	 */
	public function liste(): array {
		$liste = [];
		foreach ((array) glob($this->ordner() . '/kmm-sicherung-*.kmmsic') as $pfad) {
			$pfad = (string) $pfad;
			if (preg_match(self::MUSTER, basename($pfad)) === 1) {
				$liste[] = ['name' => basename($pfad), 'bytes' => (int) filesize($pfad), 'zeit' => (int) filemtime($pfad)];
			}
		}
		usort($liste, static fn(array $a, array $b): int => $b['name'] <=> $a['name']);
		return $liste;
	}

	/** Pfad einer vorhandenen Sicherung oder leer. */
	public function pfad(string $name): string {
		$pfad = $this->ordner() . '/' . $name;
		return preg_match(self::MUSTER, $name) === 1 && is_file($pfad) ? $pfad : '';
	}

	public function wartung_berechtigt(string $schluessel): bool {
		$soll = (string) $this->config['wartung_token'];
		return $soll !== '' && hash_equals($soll, $schluessel);
	}

	public function passwort_richtig(string $passwort): bool {
		$hash = (string) $this->config['download_passwort'];
		return $hash !== '' && password_verify($passwort, $hash);
	}

	/** @return array<string, mixed> */
	public function zustand(): array {
		$datei = $this->ordner() . '/zustand.json';
		$z = is_file($datei) ? json_decode((string) file_get_contents($datei), true) : null;
		return is_array($z) ? $z : [];
	}

	/** @param array<string, mixed> $werte */
	private function zustand_setzen(array $werte): void {
		file_put_contents($this->ordner() . '/zustand.json', (string) json_encode(array_merge($this->zustand(), $werte)), LOCK_EX);
	}

	private function mail(string $betreff, string $text): void {
		$an = (string) $this->config['warn_mail'];
		if ($an === '') {
			return;
		}
		$kopf = ['Content-Type: text/plain; charset=UTF-8'];
		if ((string) $this->config['absender'] !== '') {
			$kopf[] = 'From: ' . (string) $this->config['absender'];
		}
		mail($an, '=?UTF-8?B?' . base64_encode($betreff) . '?=', $text, implode("\r\n", $kopf));
	}
}
