<?php
/**
 * Zugriff auf den Ergebnisordner (uploads/results/km_JJJJ/) – den einen Ablageort für
 * alle Ergebnisse, alte wie künftige.
 *
 * Grundsätze:
 *   - Es wird nur innerhalb des Ergebnisordners geschrieben (realpath-Prüfung).
 *   - Eine ersetzte oder entfernte Datei wird nie gelöscht, sondern mit Zeitstempel in den
 *     geschützten Archivordner verschoben (uploads/kmm-archiv/ergebnisse/, kein Webzugriff).
 *   - Lesen verändert nichts.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure;

use KSV\KMM\Application\Abschluss;
use KSV\KMM\Support\ErgebnisEinstellungen;

final class Ergebnisablage {

	/** Obergrenze je Datei (wie im bisherigen Plugin). */
	public const MAX_BYTES = 52428800;

	private string $basis;

	public function __construct(?string $basis = null) {
		$this->basis = untrailingslashit($basis ?? ErgebnisEinstellungen::pfad());
	}

	public function basis(): string {
		return $this->basis;
	}

	public function vorhanden(): bool {
		return is_dir($this->basis);
	}

	/**
	 * Jahre mit Ordner km_JJJJ, neuestes zuerst.
	 *
	 * @return list<int>
	 */
	public function jahre(): array {
		$out = [];
		foreach ((array) glob($this->basis . '/km_*', GLOB_ONLYDIR) as $dir) {
			if (preg_match('/km_(\d{4})$/', (string) $dir, $m)) {
				$out[] = (int) $m[1];
			}
		}
		rsort($out);
		return $out;
	}

	/**
	 * Dateinamen (ohne Unterordner) im Jahresordner.
	 *
	 * @return list<string>
	 */
	public function dateien(int $jahr): array {
		$dir = $this->basis . '/km_' . $jahr;
		if (!is_dir($dir)) {
			return [];
		}
		$out = [];
		foreach ((array) scandir($dir) as $f) {
			$f = (string) $f;
			if ($f === '' || $f[0] === '.' || !is_file($dir . '/' . $f)) {
				continue;
			}
			$out[] = $f;
		}
		sort($out);
		return $out;
	}

	/** Absoluter Pfad zu einer relativen Angabe (km_2025/e1_10_10.pdf), nur wenn vorhanden und im Ordner. */
	public function pfad(string $relativ): ?string {
		$real_basis = realpath($this->basis);
		if ($real_basis === false) {
			return null;
		}
		$relativ = ltrim(str_replace(['\\', "\0"], ['/', ''], $relativ), '/');
		if ($relativ === '' || str_contains($relativ, '..')) {
			return null;
		}
		$real = realpath($real_basis . '/' . $relativ);
		if ($real === false || !is_file($real) || !str_starts_with($real, $real_basis . DIRECTORY_SEPARATOR)) {
			return null;
		}
		return $real;
	}

	public function url(string $relativ): string {
		$teile = array_map('rawurlencode', explode('/', ltrim($relativ, '/')));
		return ErgebnisEinstellungen::url() . '/' . implode('/', $teile);
	}

	/**
	 * Legt eine Datei unter dem relativen Pfad ab. Eine vorhandene Datei wird vorher
	 * gesichert. Gibt die Sicherung zurück (oder '').
	 *
	 * @param bool $hochgeladen true: $quelle ist eine PHP-Upload-Datei (move_uploaded_file)
	 * @throws \RuntimeException
	 */
	public function ablegen(string $quelle, string $relativ, bool $hochgeladen): string {
		if (!preg_match('#^km_\d{4}/[A-Za-z0-9_.-]+$#', $relativ)) {
			throw new \RuntimeException('Ungültiger Zielname.');
		}
		$dir = $this->basis . '/' . dirname($relativ);
		if (!is_dir($dir) && !wp_mkdir_p($dir)) {
			throw new \RuntimeException('Der Ergebnisordner kann nicht angelegt werden: ' . $dir);
		}
		if (!is_writable($dir)) {
			throw new \RuntimeException('Der Ergebnisordner ist nicht beschreibbar: ' . $dir);
		}
		$ziel = $this->basis . '/' . $relativ;
		$sicherung = is_file($ziel) ? $this->sichern($relativ) : '';
		$ok = $hochgeladen ? @move_uploaded_file($quelle, $ziel) : @rename($quelle, $ziel);
		if (!$ok) {
			throw new \RuntimeException('Die Datei konnte nicht im Ergebnisordner gespeichert werden.');
		}
		@chmod($ziel, 0644);
		if ($this->pfad($relativ) === null) {
			@unlink($ziel);
			throw new \RuntimeException('Die Datei liegt nicht im Ergebnisordner.');
		}
		return $sicherung;
	}

	/**
	 * Verschiebt eine Datei mit Zeitstempel in den geschützten Archivordner.
	 *
	 * @return string Pfad der Sicherung
	 * @throws \RuntimeException
	 */
	public function sichern(string $relativ): string {
		$quelle = $this->pfad($relativ);
		if ($quelle === null) {
			return '';
		}
		$ziel_dir = self::sicherungsordner() . '/' . dirname(ltrim($relativ, '/'));
		if (!is_dir($ziel_dir) && !wp_mkdir_p($ziel_dir)) {
			throw new \RuntimeException('Der Sicherungsordner kann nicht angelegt werden.');
		}
		// Zeitstempel plus Zufallsteil; vorhandene Sicherungen werden nie überschrieben.
		do {
			$ziel = $ziel_dir . '/' . basename($relativ) . '.' . gmdate('Y-m-d-His') . '-' . bin2hex(random_bytes(4)) . '.bak';
		} while (file_exists($ziel));
		if (!@rename($quelle, $ziel)) {
			throw new \RuntimeException('Die bisherige Datei konnte nicht gesichert werden; es wurde nichts ersetzt.');
		}
		return $ziel;
	}

	/** Geschützter Ordner für ersetzte Ergebnisse und Zwischenablage (kein Webzugriff). */
	public static function sicherungsordner(): string {
		$archiv = (new Abschluss())->archivordner();
		if ($archiv === '') {
			throw new \RuntimeException('Der geschützte Archivordner ist nicht verfügbar.');
		}
		$pfad = $archiv . '/ergebnisse';
		if (!is_dir($pfad)) {
			wp_mkdir_p($pfad);
		}
		return $pfad;
	}

	/**
	 * Lichtschießen-Ergebnisse als Dateien je Jahr (Entscheidung: nur als Link je Jahr):
	 * km-licht/JJJJ/*, km_JJJJ/licht/* und Dateien „…Licht…“ im Jahresordner, ohne
	 * Ausschreibungen und Meldebögen.
	 *
	 * @return array<int, list<string>> jahr => relative Pfade
	 */
	public function licht_dateien(): array {
		$out = [];
		$nehmen = static function (int $jahr, string $relativ) use (&$out): void {
			$name = strtolower(basename($relativ));
			if (!preg_match('/\.(pdf|html?)$/', $name) || preg_match('/ausschreibung|meldung|meldezettel/', $name)) {
				return;
			}
			$out[ $jahr ][] = $relativ;
		};
		foreach ((array) glob($this->basis . '/km-licht/*', GLOB_ONLYDIR) as $dir) {
			if (!preg_match('/(\d{4})$/', (string) $dir, $m)) {
				continue;
			}
			foreach ((array) scandir((string) $dir) as $f) {
				if (is_file($dir . '/' . $f)) {
					$nehmen((int) $m[1], 'km-licht/' . $m[1] . '/' . $f);
				}
			}
		}
		foreach ($this->jahre() as $jahr) {
			$sub = $this->basis . '/km_' . $jahr . '/licht';
			if (is_dir($sub)) {
				foreach ((array) scandir($sub) as $f) {
					if (is_file($sub . '/' . $f)) {
						$nehmen($jahr, 'km_' . $jahr . '/licht/' . $f);
					}
				}
			}
			foreach ($this->dateien($jahr) as $f) {
				if (stripos($f, 'licht') !== false) {
					$nehmen($jahr, 'km_' . $jahr . '/' . $f);
				}
			}
		}
		foreach ($out as &$liste) {
			sort($liste);
		}
		unset($liste);
		krsort($out);
		return $out;
	}

	/**
	 * Prüft eine hochgeladene Datei: wirklich ein PDF (bzw. HTML im Archiv) und nicht zu groß.
	 *
	 * @param list<string> $erlaubt Endungen, z. B. ['pdf'] oder ['pdf', 'html']
	 * @return string Endung (pdf|html)
	 * @throws \InvalidArgumentException
	 */
	public static function datei_pruefen(string $pfad, string $originalname, array $erlaubt): string {
		if (!is_file($pfad)) {
			throw new \InvalidArgumentException('Die Datei ist nicht angekommen.');
		}
		$groesse = (int) filesize($pfad);
		if ($groesse <= 0) {
			throw new \InvalidArgumentException(sprintf('„%s“ ist leer.', $originalname));
		}
		if ($groesse > self::MAX_BYTES) {
			throw new \InvalidArgumentException(sprintf('„%s“ ist größer als 50 MB.', $originalname));
		}
		$endung = strtolower(pathinfo($originalname, PATHINFO_EXTENSION));
		$endung = $endung === 'htm' ? 'html' : $endung;
		if (!in_array($endung, $erlaubt, true)) {
			throw new \InvalidArgumentException(sprintf('„%s“: erlaubt sind nur %s-Dateien.', $originalname, strtoupper(implode(', ', $erlaubt))));
		}
		$kopf = (string) file_get_contents($pfad, false, null, 0, 1024);
		if ($endung === 'pdf' && !str_starts_with(ltrim($kopf), '%PDF-')) {
			throw new \InvalidArgumentException(sprintf('„%s“ ist kein gültiges PDF.', $originalname));
		}
		if ($endung === 'html' && (str_contains($kopf, '<?php') || stripos($kopf, '<html') === false && stripos($kopf, '<table') === false)) {
			throw new \InvalidArgumentException(sprintf('„%s“ ist keine gültige HTML-Ergebnisliste.', $originalname));
		}
		return $endung;
	}
}
