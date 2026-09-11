<?php
/**
 * Vereinsverwaltung: anlegen, bearbeiten, löschen, Adressen.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Infrastructure\Database\Tables;
use KSV\KMM\Infrastructure\Repository\VereinEmailRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;

final class VereinService {

	private VereinRepository $vereine;
	private VereinEmailRepository $emails;

	public function __construct() {
		$this->vereine = new VereinRepository();
		$this->emails = new VereinEmailRepository();
	}

	/**
	 * @param string[] $adressen
	 * @throws \RuntimeException
	 */
	public function speichern(int $id, string $name, string $vn_nummer, array $adressen, bool $aktiv, string $notiz = ''): int {
		$name = trim($name);
		$vn_nummer = trim($vn_nummer);
		if ($name === '') {
			throw new \RuntimeException('Bitte einen Vereinsnamen angeben.');
		}
		if (!preg_match('/^\d{5}$/', $vn_nummer)) {
			throw new \RuntimeException('Die VN-Nummer muss aus genau 5 Ziffern bestehen.');
		}
		$vorhanden = $this->vereine->by_vn_nummer($vn_nummer);
		if ($vorhanden !== null && (int) $vorhanden['id'] !== $id) {
			throw new \RuntimeException(sprintf('Die VN-Nummer %s ist bereits vergeben (%s).', $vn_nummer, (string) $vorhanden['name']));
		}
		$gueltig = [];
		foreach ($adressen as $a) {
			$a = strtolower(trim($a));
			if ($a === '') {
				continue;
			}
			if (!is_email($a)) {
				throw new \RuntimeException(sprintf('Ungültige E-Mail-Adresse: %s', $a));
			}
			$gueltig[] = $a;
		}
		$daten = ['name' => mb_substr($name, 0, 150), 'vn_nummer' => $vn_nummer, 'ist_aktiv' => $aktiv, 'notiz' => $notiz];
		if ($id > 0) {
			if ($this->vereine->find($id) === null) {
				throw new \RuntimeException('Verein nicht gefunden.');
			}
			$this->vereine->update($id, $daten);
			$aktion = 'verein.bearbeiten';
		} else {
			$id = $this->vereine->insert($daten);
			if ($id <= 0) {
				throw new \RuntimeException('Verein konnte nicht angelegt werden: ' . $this->vereine->last_error());
			}
			$aktion = 'verein.anlegen';
		}
		$this->emails->setzen($id, $gueltig);
		Protokoll::admin($aktion, sprintf('%s (%s), %d Adresse(n)', $name, $vn_nummer, count($gueltig)), null, $id, 'verein', $id);
		return $id;
	}

	/**
	 * @throws \RuntimeException wenn der Verein Schützen oder Meldungen hat.
	 */
	public function loeschen(int $id): void {
		$verein = $this->vereine->find($id);
		if ($verein === null) {
			throw new \RuntimeException('Verein nicht gefunden.');
		}
		global $wpdb;
		foreach (['schuetze', 'meldung'] as $t) {
			$n = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Tables::name($t) . ' WHERE verein_id = %d', $id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ($n > 0) {
				throw new \RuntimeException('Verein hat Schützen oder Meldungen und kann nicht gelöscht werden. Stattdessen deaktivieren.');
			}
		}
		foreach (['magic_link', 'sitzung', 'verein_email'] as $t) {
			$wpdb->delete(Tables::name($t), ['verein_id' => $id], ['%d']);
		}
		$this->vereine->delete($id);
		Protokoll::admin('verein.loeschen', sprintf('%s (%s) gelöscht', (string) $verein['name'], (string) $verein['vn_nummer']), null, $id, 'verein', $id);
	}
}
