<?php
/**
 * Ergebnisse hochladen, ersetzen, ausblenden und klären.
 *
 * Ablauf beim Hochladen mehrerer Dateien (Ablagefläche):
 *   1. eingang():   Dateien (oder ein ZIP) landen in einer geschützten Zwischenablage.
 *   2. vorschau():  Zuordnung am Dateinamen mit Ampel (grün/gelb/rot).
 *   3. uebernehmen(): erst jetzt werden die Dateien in den Ergebnisordner gelegt.
 * Der Upload am einzelnen Platz (hochladen()) springt direkt zu Schritt 3.
 *
 * Ab dem Sportjahr 2027 kommen die Plätze aus dem Disziplinplan, davor aus dem Katalog.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application\Ergebnisse;

use KSV\KMM\Application\Protokoll;
use KSV\KMM\Auth\Rechte;
use KSV\KMM\Domain\Ergebnis\Platz;
use KSV\KMM\Domain\Ergebnis\Platzliste;
use KSV\KMM\Domain\Ergebnis\Schluessel;
use KSV\KMM\Domain\Ergebnis\UploadZuordnung;
use KSV\KMM\Infrastructure\Ergebnisablage;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\ErgebnisKatalogRepository;
use KSV\KMM\Infrastructure\Repository\ErgebnisRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Support\Clock;

final class ErgebnisService {

	/** Ab diesem Jahr gilt der Disziplinplan des Sportjahres; davor der Katalog. */
	public const ERSTES_PLANJAHR = 2027;

	/** Zwischenablage: so lange bleibt ein Eingang liegen. */
	private const EINGANG_STUNDEN = 12;

	private ErgebnisRepository $ergebnisse;

	private Ergebnisablage $ablage;

	/** @var array<int, Platzliste> */
	private array $plaetze_cache = [];

	public function __construct(?Ergebnisablage $ablage = null) {
		$this->ergebnisse = new ErgebnisRepository();
		$this->ablage = $ablage ?? new Ergebnisablage();
	}

	public function ablage(): Ergebnisablage {
		return $this->ablage;
	}

	/**
	 * Sportjahr, dessen Disziplinplan für das Jahr gilt (ab 2027), sonst null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function sportjahr_fuer(int $jahr): ?array {
		if ($jahr < self::ERSTES_PLANJAHR) {
			return null;
		}
		return (new SportjahrRepository())->by_jahr($jahr);
	}

	public function plaetze(int $jahr): Platzliste {
		if (!isset($this->plaetze_cache[ $jahr ])) {
			$sj = self::sportjahr_fuer($jahr);
			$this->plaetze_cache[ $jahr ] = $sj !== null
				? Platzliste::aus_regelwerk(RegelwerkLader::laden((int) $sj['id']))
				: Platzliste::aus_katalog(array_values((new ErgebnisKatalogRepository())->als_map()));
		}
		return $this->plaetze_cache[ $jahr ];
	}

	/**
	 * Archivjahre dürfen auch HTML bekommen, Planjahre nur PDF. HTML kann Skripte enthalten,
	 * deshalb nur für Benutzer mit dem WordPress-Recht unfiltered_html (Administratoren).
	 *
	 * @return list<string>
	 */
	public static function erlaubte_endungen(int $jahr): array {
		return $jahr < self::ERSTES_PLANJAHR && current_user_can('unfiltered_html') ? ['pdf', 'html'] : ['pdf'];
	}

	/**
	 * Vorhandene Ergebnisse des Jahres je Platz-ID.
	 *
	 * @return array<string, array<string, mixed>> e:1_10_10 => Zeile
	 */
	public function vorhandene(int $jahr): array {
		$out = [];
		foreach ($this->ergebnisse->by_jahr($jahr) as $r) {
			$out[ $r['art'] . ':' . $r['schluessel'] ] = $r;
		}
		return $out;
	}

	public static function darf_hochladen(): bool {
		return Rechte::hat_recht(Rechte::RECHT_ERGEBNISSE);
	}

	private static function recht_pruefen(): void {
		if (!self::darf_hochladen()) {
			throw new \RuntimeException('Keine Berechtigung zum Hochladen von Ergebnissen.');
		}
	}

	/**
	 * Eine Datei direkt an einem Platz ablegen (Upload am Disziplinplan). Der Dateiname
	 * ist egal; gespeichert wird unter dem Schlüssel des Platzes.
	 *
	 * @param array{name: string, tmp_name: string, error?: int} $datei Eintrag aus $_FILES
	 * @return array<string, mixed> gespeicherte Zeile
	 */
	public function hochladen(int $jahr, string $platz_id, array $datei, bool $entwurf = false): array {
		self::recht_pruefen();
		if (($datei['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file($datei['tmp_name'])) {
			throw new \InvalidArgumentException('Die Datei ist nicht angekommen (Upload-Fehler ' . (int) ($datei['error'] ?? 0) . ').');
		}
		$platz = $this->platz_aus_id($jahr, $platz_id);
		$endung = Ergebnisablage::datei_pruefen($datei['tmp_name'], $datei['name'], self::erlaubte_endungen($jahr));
		return $this->ablegen($jahr, $platz, $datei['tmp_name'], $datei['name'], $endung, true, $entwurf);
	}

	/**
	 * Schritt 1 der Ablagefläche: Dateien (PDF oder ZIP) in die Zwischenablage legen.
	 *
	 * @param array{name: array<int, string>|string, tmp_name: array<int, string>|string, error: array<int, int>|int} $files $_FILES-Feld mit multiple
	 * @return array{token: string, abgelehnt: list<string>}
	 */
	public function eingang(int $jahr, array $files): array {
		self::recht_pruefen();
		Schluessel::jahresordner($jahr);
		$token = wp_generate_password(20, false, false);
		$dir = $this->eingangsordner($token);
		$namen = (array) $files['name'];
		$tmps = (array) $files['tmp_name'];
		$fehler = (array) $files['error'];
		$eintraege = [];
		$abgelehnt = [];
		foreach ($namen as $i => $name) {
			$name = sanitize_file_name(basename((string) $name));
			$tmp = (string) ($tmps[ $i ] ?? '');
			if ((int) ($fehler[ $i ] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
				if ($name !== '') {
					$abgelehnt[] = $name . ': nicht angekommen';
				}
				continue;
			}
			if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'zip') {
				foreach ($this->zip_entpacken($tmp, $dir, count($eintraege), self::erlaubte_endungen($jahr), $abgelehnt) as $e) {
					$eintraege[] = $e;
				}
				continue;
			}
			try {
				$endung = Ergebnisablage::datei_pruefen($tmp, $name, self::erlaubte_endungen($jahr));
			} catch (\InvalidArgumentException $e) {
				$abgelehnt[] = $e->getMessage();
				continue;
			}
			$ziel = $dir . '/' . count($eintraege) . '.' . $endung;
			if (!@move_uploaded_file($tmp, $ziel)) {
				$abgelehnt[] = $name . ': konnte nicht zwischengespeichert werden';
				continue;
			}
			$eintraege[] = ['name' => $name, 'datei' => basename($ziel), 'endung' => $endung];
		}
		if ($eintraege === []) {
			$this->eingang_verwerfen($token);
			throw new \InvalidArgumentException("Keine verwendbare Datei dabei.\n" . implode("\n", $abgelehnt));
		}
		set_transient(self::eingang_key($token), ['jahr' => $jahr, 'user' => get_current_user_id(), 'dateien' => $eintraege], self::EINGANG_STUNDEN * HOUR_IN_SECONDS);
		return ['token' => $token, 'abgelehnt' => $abgelehnt];
	}

	/**
	 * Schritt 2: Vorschau der Zuordnung.
	 *
	 * @return array{jahr: int, zeilen: list<array{datei: string, status: string, platz: ?Platz, hinweis: string}>}|null
	 */
	public function vorschau(string $token): ?array {
		$eingang = $this->eingang_laden($token);
		if ($eingang === null) {
			return null;
		}
		$jahr = (int) $eingang['jahr'];
		$vorhanden = array_map(static fn(): bool => true, $this->vorhandene($jahr));
		$zeilen = UploadZuordnung::vorschau(array_column($eingang['dateien'], 'name'), $this->plaetze($jahr), $vorhanden);
		return ['jahr' => $jahr, 'zeilen' => $zeilen];
	}

	/**
	 * Schritt 3: übernehmen. $zuordnung: Index der Datei => Platz-ID ('' = auslassen).
	 *
	 * @param array<int, string> $zuordnung
	 * @return array{gespeichert: int, ersetzt: int, ausgelassen: int, fehler: list<string>}
	 */
	public function uebernehmen(string $token, array $zuordnung, bool $entwurf = false): array {
		self::recht_pruefen();
		$eingang = $this->eingang_laden($token);
		if ($eingang === null) {
			throw new \RuntimeException('Die Zwischenablage ist abgelaufen. Bitte die Dateien noch einmal hochladen.');
		}
		$jahr = (int) $eingang['jahr'];
		$dir = $this->eingangsordner($token);
		$ergebnis = ['gespeichert' => 0, 'ersetzt' => 0, 'ausgelassen' => 0, 'fehler' => []];
		$vergeben = [];
		foreach ($eingang['dateien'] as $i => $d) {
			$platz_id = trim((string) ($zuordnung[ $i ] ?? ''));
			if ($platz_id === '') {
				$ergebnis['ausgelassen']++;
				continue;
			}
			if (isset($vergeben[ $platz_id ])) {
				$ergebnis['fehler'][] = sprintf('„%s“: Platz ist schon „%s“ zugeordnet – ausgelassen.', $d['name'], $vergeben[ $platz_id ]);
				continue;
			}
			try {
				$platz = $this->platz_aus_id($jahr, $platz_id);
				$hatte = $this->ergebnisse->platz($jahr, $platz->art, $platz->schluessel) !== null;
				$this->ablegen($jahr, $platz, $dir . '/' . $d['datei'], (string) $d['name'], (string) $d['endung'], false, $entwurf);
				$vergeben[ $platz_id ] = $d['name'];
				$ergebnis['gespeichert']++;
				if ($hatte) {
					$ergebnis['ersetzt']++;
				}
			} catch (\RuntimeException | \InvalidArgumentException $e) {
				$ergebnis['fehler'][] = sprintf('„%s“: %s', $d['name'], $e->getMessage());
			}
		}
		$this->eingang_verwerfen($token);
		return $ergebnis;
	}

	public function eingang_verwerfen(string $token): void {
		if (!preg_match('/^[A-Za-z0-9]{20}$/', $token)) {
			return;
		}
		delete_transient(self::eingang_key($token));
		$dir = Ergebnisablage::sicherungsordner() . '/eingang/' . $token;
		foreach ((array) glob($dir . '/*') as $f) {
			@unlink((string) $f);
		}
		@rmdir($dir);
	}

	/**
	 * Sichtbarkeit ändern (sichtbar, Entwurf, ausgeblendet).
	 */
	public function status_setzen(int $id, string $status): void {
		self::recht_pruefen();
		if (!in_array($status, [ErgebnisRepository::STATUS_SICHTBAR, ErgebnisRepository::STATUS_ENTWURF, ErgebnisRepository::STATUS_AUSGEBLENDET], true)) {
			throw new \InvalidArgumentException('Unbekannter Status.');
		}
		$r = $this->zeile($id);
		if ($r['status'] === ErgebnisRepository::STATUS_UNGEKLAERT && $status === ErgebnisRepository::STATUS_SICHTBAR && (string) $r['disziplin_text'] === '') {
			throw new \InvalidArgumentException('Bitte zuerst die Bezeichnung nachtragen.');
		}
		$this->ergebnisse->update($id, ['status' => $status]);
		Protokoll::admin('ergebnis.status', sprintf('Ergebnis %d %s%s: %s', (int) $r['jahr'], strtoupper((string) $r['art']), $r['schluessel'], $status), $r['sportjahr_id'] !== null ? (int) $r['sportjahr_id'] : null, null, 'ergebnis', $id);
	}

	/**
	 * Ergebnis entfernen: Datei wandert in die Sicherung, die Zeile wird gelöscht.
	 */
	public function entfernen(int $id): void {
		self::recht_pruefen();
		$r = $this->zeile($id);
		$sicherung = $this->ablage->sichern((string) $r['datei']);
		$this->ergebnisse->delete($id);
		Protokoll::admin('ergebnis.entfernen', sprintf('Ergebnis %d %s%s entfernt', (int) $r['jahr'], strtoupper((string) $r['art']), $r['schluessel']), $r['sportjahr_id'] !== null ? (int) $r['sportjahr_id'] : null, null, 'ergebnis', $id, ['datei' => $r['datei'], 'sicherung' => $sicherung]);
	}

	/**
	 * Klärungsliste: Bezeichnung für eine Datei ohne Katalogeintrag nachtragen. Legt die
	 * Katalogzeile an (gilt dann für alle Archivjahre) und macht das Ergebnis sichtbar.
	 */
	public function klaeren(int $id, string $kennzahl, string $disziplin_text, string $klasse_text): void {
		self::recht_pruefen();
		$r = $this->zeile($id);
		$disziplin_text = trim($disziplin_text);
		if ($disziplin_text === '') {
			throw new \InvalidArgumentException('Bitte die Disziplin angeben.');
		}
		$schluessel = (string) $r['schluessel'];
		$katalog = new ErgebnisKatalogRepository();
		$spo = trim($kennzahl) !== '' ? trim($kennzahl) : str_replace('_', '.', $schluessel);
		$zeile = ['spo' => $spo, 'disziplin_text' => $disziplin_text, 'klasse_text' => trim($klasse_text)];
		$vorhanden = $katalog->by_schluessel($schluessel);
		if ($vorhanden === null) {
			$katalog->insert(['schluessel' => $schluessel, 'quelle' => 'manuell'] + $zeile);
		} else {
			$katalog->update((int) $vorhanden['id'], $zeile);
		}
		$kz = Schluessel::ist_sammel($schluessel) ? $spo : implode('.', array_slice(explode('.', $spo), 0, -1));
		// Alle Dateien mit diesem Schlüssel (alle Archivjahre) bekommen die Bezeichnung.
		foreach ($this->ergebnisse->where(['schluessel' => $schluessel]) as $e) {
			if ($e['sportjahr_id'] !== null) {
				continue;
			}
			$daten = ['kennzahl' => $kz, 'disziplin_text' => $disziplin_text, 'klasse_text' => trim($klasse_text)];
			if ($e['status'] === ErgebnisRepository::STATUS_UNGEKLAERT) {
				$daten['status'] = ErgebnisRepository::STATUS_SICHTBAR;
			}
			$this->ergebnisse->update((int) $e['id'], $daten);
		}
		$this->plaetze_cache = [];
		Protokoll::admin('ergebnis.klaeren', sprintf('Bezeichnung für %s nachgetragen: %s %s', $schluessel, $disziplin_text, $klasse_text), null, null, 'ergebnis', $id);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function zeile(int $id): array {
		$r = $this->ergebnisse->find($id);
		if ($r === null) {
			throw new \RuntimeException('Ergebnis nicht gefunden.');
		}
		return $r;
	}

	private function platz_aus_id(int $jahr, string $platz_id): Platz {
		if (!preg_match('/^([em]):([0-9A-Z_]+)$/', $platz_id, $m)) {
			throw new \InvalidArgumentException('Unbekannter Ergebnisplatz.');
		}
		$platz = $this->plaetze($jahr)->finde($m[1], $m[2]);
		if ($platz === null) {
			throw new \InvalidArgumentException(sprintf('%s%s steht nicht im Disziplinplan %d.', strtoupper($m[1]), $m[2], $jahr));
		}
		return $platz;
	}

	/**
	 * Legt die Datei unter dem Schlüssel des Platzes ab und trägt sie ins Verzeichnis ein.
	 * Eine andere Fassung desselben Platzes (z. B. HTML neben neuem PDF) wird mitgesichert,
	 * damit nur eine Datei je Platz im Ordner liegt.
	 *
	 * @return array<string, mixed>
	 */
	private function ablegen(int $jahr, Platz $platz, string $quelle, string $originalname, string $endung, bool $hochgeladen, bool $entwurf): array {
		$relativ = Schluessel::jahresordner($jahr) . '/' . $platz->dateiname($endung);
		$alt = $this->ergebnisse->platz($jahr, $platz->art, $platz->schluessel);
		if ($alt !== null && (string) $alt['datei'] !== $relativ) {
			$this->ablage->sichern((string) $alt['datei']);
		}
		$sicherung = $this->ablage->ablegen($quelle, $relativ, $hochgeladen);
		$pfad = $this->ablage->pfad($relativ);
		$sj = self::sportjahr_fuer($jahr);
		$id = $this->ergebnisse->speichern($jahr, $platz->art, $platz->schluessel, [
			'sportjahr_id'    => $sj !== null ? (int) $sj['id'] : null,
			'disziplin_id'    => $platz->disziplin_id,
			'klasse_id'       => $platz->klasse_id,
			'kennzahl'        => $platz->kennzahl,
			'disziplin_text'  => $platz->disziplin_text,
			'klasse_text'     => $platz->klasse_text,
			'datei'           => $relativ,
			'format'          => $endung,
			'groesse'         => $pfad !== null ? (int) filesize($pfad) : 0,
			'status'          => $entwurf ? ErgebnisRepository::STATUS_ENTWURF : ErgebnisRepository::STATUS_SICHTBAR,
			'quelle'          => ErgebnisRepository::QUELLE_UPLOAD,
			'datei_stand'     => Clock::now_utc(),
			'hochgeladen_von' => get_current_user_id() > 0 ? get_current_user_id() : null,
		]);
		Protokoll::admin(
			$alt !== null ? 'ergebnis.ersetzen' : 'ergebnis.hochladen',
			sprintf('%s %d: %s', $alt !== null ? 'Ergebnis ersetzt' : 'Ergebnis hochgeladen', $jahr, $platz->beschriftung()),
			$sj !== null ? (int) $sj['id'] : null,
			null,
			'ergebnis',
			$id,
			['datei' => $relativ, 'original' => $originalname, 'sicherung' => $sicherung]
		);
		do_action('kmm_ergebnis_geaendert', $jahr);
		$neu = $this->ergebnisse->find($id);
		return $neu ?? [];
	}

	private function eingangsordner(string $token): string {
		$dir = Ergebnisablage::sicherungsordner() . '/eingang/' . $token;
		if (!is_dir($dir) && !wp_mkdir_p($dir)) {
			throw new \RuntimeException('Die Zwischenablage kann nicht angelegt werden.');
		}
		return $dir;
	}

	/**
	 * @return array{jahr: int, user: int, dateien: list<array{name: string, datei: string, endung: string}>}|null
	 */
	private function eingang_laden(string $token): ?array {
		if (!preg_match('/^[A-Za-z0-9]{20}$/', $token)) {
			return null;
		}
		$e = get_transient(self::eingang_key($token));
		if (!is_array($e) || (int) ($e['user'] ?? 0) !== get_current_user_id()) {
			return null;
		}
		return $e;
	}

	private static function eingang_key(string $token): string {
		return 'kmm_erg_eingang_' . $token;
	}

	/**
	 * PDFs (bzw. HTML im Archiv) aus einem ZIP in die Zwischenablage; Ordner im ZIP werden ignoriert.
	 *
	 * @param list<string> $erlaubt
	 * @param list<string> $abgelehnt
	 * @return list<array{name: string, datei: string, endung: string}>
	 */
	private function zip_entpacken(string $zip_pfad, string $dir, int $start, array $erlaubt, array &$abgelehnt): array {
		if (!class_exists('ZipArchive')) {
			$abgelehnt[] = 'ZIP-Dateien werden auf diesem Server nicht unterstützt.';
			return [];
		}
		$zip = new \ZipArchive();
		if ($zip->open($zip_pfad) !== true) {
			$abgelehnt[] = 'Das ZIP konnte nicht geöffnet werden.';
			return [];
		}
		$out = [];
		$n = $start;
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$stat = $zip->statIndex($i);
			$name = $stat !== false ? (string) $stat['name'] : '';
			if ($name === '' || str_ends_with($name, '/') || str_contains($name, '__MACOSX')) {
				continue;
			}
			$basis = sanitize_file_name(basename(str_replace('\\', '/', $name)));
			$endung = strtolower(pathinfo($basis, PATHINFO_EXTENSION));
			$endung = $endung === 'htm' ? 'html' : $endung;
			if (!in_array($endung, $erlaubt, true)) {
				$abgelehnt[] = $basis . ': im ZIP ignoriert (nur ' . strtoupper(implode(', ', $erlaubt)) . ')';
				continue;
			}
			if (($stat['size'] ?? 0) > Ergebnisablage::MAX_BYTES) {
				$abgelehnt[] = $basis . ': größer als 50 MB';
				continue;
			}
			$inhalt = $zip->getFromIndex($i);
			if ($inhalt === false) {
				continue;
			}
			$ziel = $dir . '/' . $n . '.' . $endung;
			file_put_contents($ziel, $inhalt);
			try {
				Ergebnisablage::datei_pruefen($ziel, $basis, $erlaubt);
			} catch (\InvalidArgumentException $e) {
				@unlink($ziel);
				$abgelehnt[] = $e->getMessage();
				continue;
			}
			$out[] = ['name' => $basis, 'datei' => basename($ziel), 'endung' => $endung];
			$n++;
		}
		$zip->close();
		return $out;
	}
}
