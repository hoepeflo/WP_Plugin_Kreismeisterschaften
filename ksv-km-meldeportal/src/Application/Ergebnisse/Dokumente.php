<?php
/**
 * Ausschreibungen und Unterlagen je Jahr (Tabelle kmm_dokument, bisher Option
 * srd_km_documents des alten Ergebnis-Plugins).
 *
 * Ein Dokument verweist entweder auf ein PDF der Mediathek, eine WordPress-Seite, eine
 * Adresse oder eine Datei im Ergebnisordner (Lichtschießen). Es wird nichts kopiert.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application\Ergebnisse;

use KSV\KMM\Application\Protokoll;
use KSV\KMM\Domain\Ergebnis\Kategorie;
use KSV\KMM\Infrastructure\Ergebnisablage;
use KSV\KMM\Infrastructure\Repository\DokumentRepository;

final class Dokumente {

	public const TYP_AUSSCHREIBUNG    = 'ausschreibung';
	public const TYP_DISZIPLINENPLAN  = 'disziplinenplan';
	public const TYP_JAHRGANGSTABELLE = 'jahrgangstabelle';
	public const TYP_TERMINPLAN       = 'terminplan';
	public const TYP_KATEGORIE        = 'kategorie';
	public const TYP_LICHT            = 'licht';

	public const QUELLE_PDF            = 'pdf';
	public const QUELLE_SEITE          = 'page';
	public const QUELLE_URL            = 'url';
	public const QUELLE_ERGEBNISORDNER = 'ordner';

	private DokumentRepository $repo;

	public function __construct(?DokumentRepository $repo = null) {
		$this->repo = $repo ?? new DokumentRepository();
	}

	/**
	 * @return array<string, string>
	 */
	public static function typen(): array {
		return [
			self::TYP_AUSSCHREIBUNG    => __('Allgemeine Ausschreibung', 'ksv-km-meldeportal'),
			self::TYP_DISZIPLINENPLAN  => __('Disziplinenplan', 'ksv-km-meldeportal'),
			self::TYP_JAHRGANGSTABELLE => __('Jahrgangstabelle', 'ksv-km-meldeportal'),
			self::TYP_TERMINPLAN       => __('Terminplan', 'ksv-km-meldeportal'),
			self::TYP_KATEGORIE        => __('Ausschreibung einer Sparte', 'ksv-km-meldeportal'),
			self::TYP_LICHT            => __('Lichtschießen', 'ksv-km-meldeportal'),
		];
	}

	/**
	 * Anzeigetitel: eigener Titel, sonst Typ (bei Sparten mit Spartenname).
	 *
	 * @param array<string, mixed> $d
	 */
	public static function titel(array $d): string {
		$titel = trim((string) ($d['titel'] ?? ''));
		if ($titel !== '') {
			return $titel;
		}
		$typ = (string) ($d['typ'] ?? '');
		if ($typ === self::TYP_KATEGORIE && !empty($d['kategorie'])) {
			return sprintf(__('Ausschreibung %s', 'ksv-km-meldeportal'), Kategorie::label((int) $d['kategorie']));
		}
		if ($typ === self::TYP_LICHT) {
			return sprintf(__('Lichtschießen: %s', 'ksv-km-meldeportal'), basename((string) ($d['url'] ?? '')));
		}
		return self::typen()[ $typ ] ?? $typ;
	}

	/**
	 * Öffentliche Adresse eines Dokuments oder '' wenn das Ziel fehlt.
	 *
	 * @param array<string, mixed> $d
	 */
	public static function aufloesen(array $d): string {
		switch ((string) ($d['quelle'] ?? '')) {
			case self::QUELLE_PDF:
				$id = (int) ($d['attachment_id'] ?? 0);
				$url = $id > 0 ? wp_get_attachment_url($id) : false;
				return is_string($url) ? $url : '';
			case self::QUELLE_SEITE:
				$id = (int) ($d['page_id'] ?? 0);
				if ($id <= 0 || get_post_status($id) !== 'publish') {
					return '';
				}
				$url = get_permalink($id);
				return is_string($url) ? $url : '';
			case self::QUELLE_URL:
				$url = (string) ($d['url'] ?? '');
				return wp_http_validate_url($url) !== false ? $url : '';
			case self::QUELLE_ERGEBNISORDNER:
				$ablage = new Ergebnisablage();
				$rel = (string) ($d['url'] ?? '');
				return $ablage->pfad($rel) !== null ? $ablage->url($rel) : '';
		}
		return '';
	}

	/**
	 * Dokumente eines Jahres mit aufgelöster Adresse (nur erreichbare).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function oeffentlich(int $jahr): array {
		$out = [];
		foreach ($this->repo->by_jahr($jahr) as $d) {
			$url = self::aufloesen($d);
			if ($url === '') {
				continue;
			}
			$d['link'] = $url;
			$d['anzeige'] = self::titel($d);
			$out[] = $d;
		}
		return $out;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function by_jahr(int $jahr): array {
		return $this->repo->by_jahr($jahr);
	}

	/**
	 * @return list<int>
	 */
	public function jahre(): array {
		return $this->repo->jahre();
	}

	/**
	 * Legt ein Dokument an. $ziel je nach Quelle: Anhang-ID, Seiten-ID oder Adresse.
	 *
	 * @throws \InvalidArgumentException
	 */
	public function anlegen(int $jahr, string $typ, ?int $kategorie, string $titel, string $quelle, string $ziel): int {
		if ($jahr < 1990 || $jahr > 2100) {
			throw new \InvalidArgumentException(__('Ungültiges Jahr.', 'ksv-km-meldeportal'));
		}
		if (!isset(self::typen()[ $typ ])) {
			throw new \InvalidArgumentException(__('Unbekannte Dokumentart.', 'ksv-km-meldeportal'));
		}
		if ($typ === self::TYP_KATEGORIE && ($kategorie === null || !Kategorie::gibt_es($kategorie))) {
			throw new \InvalidArgumentException(__('Bitte eine Sparte wählen.', 'ksv-km-meldeportal'));
		}
		$zeile = [
			'jahr'          => $jahr,
			'typ'           => $typ,
			'kategorie'     => $typ === self::TYP_KATEGORIE ? $kategorie : null,
			'titel'         => sanitize_text_field($titel),
			'quelle'        => $quelle,
			'attachment_id' => null,
			'page_id'       => null,
			'url'           => '',
			'sortierung'    => count($this->repo->by_jahr($jahr)) + 1,
			'herkunft'      => 'manuell',
		];
		switch ($quelle) {
			case self::QUELLE_PDF:
				$id = (int) $ziel;
				if ($id <= 0 || get_post_mime_type($id) !== 'application/pdf') {
					throw new \InvalidArgumentException(__('Bitte ein PDF aus der Mediathek wählen.', 'ksv-km-meldeportal'));
				}
				$zeile['attachment_id'] = $id;
				break;
			case self::QUELLE_SEITE:
				$id = (int) $ziel;
				if ($id <= 0 || get_post_type($id) !== 'page') {
					throw new \InvalidArgumentException(__('Bitte eine Seite wählen.', 'ksv-km-meldeportal'));
				}
				$zeile['page_id'] = $id;
				break;
			case self::QUELLE_URL:
				$url = esc_url_raw(trim($ziel));
				if ($url === '' || wp_http_validate_url($url) === false) {
					throw new \InvalidArgumentException(__('Bitte eine gültige Adresse (https://…) angeben.', 'ksv-km-meldeportal'));
				}
				$zeile['url'] = $url;
				break;
			default:
				throw new \InvalidArgumentException(__('Unbekannte Quelle.', 'ksv-km-meldeportal'));
		}
		$id = $this->repo->insert($zeile);
		Protokoll::admin('dokument_angelegt', sprintf('%d: %s', $jahr, self::titel($zeile)), null, null, 'dokument', $id);
		return $id;
	}

	/**
	 * Lädt ein PDF in die Mediathek und legt das Dokument an.
	 *
	 * @param array<string, mixed> $datei Eintrag aus $_FILES
	 * @throws \InvalidArgumentException
	 */
	public function hochladen(int $jahr, string $typ, ?int $kategorie, string $titel, array $datei): int {
		if ((int) ($datei['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			throw new \InvalidArgumentException(__('Die Datei ist nicht angekommen.', 'ksv-km-meldeportal'));
		}
		Ergebnisablage::datei_pruefen((string) $datei['tmp_name'], (string) $datei['name'], ['pdf']);
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$_FILES['kmm_dokument_upload'] = $datei;
		$id = media_handle_upload('kmm_dokument_upload', 0, ['post_title' => $titel !== '' ? $titel : (string) $datei['name']]);
		if (is_wp_error($id)) {
			throw new \InvalidArgumentException($id->get_error_message());
		}
		return $this->anlegen($jahr, $typ, $kategorie, $titel, self::QUELLE_PDF, (string) $id);
	}

	/** Entfernt nur den Verweis; das PDF bleibt in der Mediathek. */
	public function loeschen(int $id): void {
		$d = $this->repo->find($id);
		if ($d === null) {
			return;
		}
		$this->repo->delete($id);
		Protokoll::admin('dokument_entfernt', sprintf('%d: %s', (int) $d['jahr'], self::titel($d)), null, null, 'dokument', $id);
	}

	/** Verschiebt ein Dokument innerhalb seines Jahres um eine Stelle (-1 hoch, +1 runter). */
	public function verschieben(int $id, int $richtung): void {
		$d = $this->repo->find($id);
		if ($d === null) {
			return;
		}
		$liste = array_values($this->repo->by_jahr((int) $d['jahr']));
		$pos = null;
		foreach ($liste as $i => $zeile) {
			if ((int) $zeile['id'] === $id) {
				$pos = $i;
			}
		}
		$ziel = $pos === null ? null : $pos + ($richtung < 0 ? -1 : 1);
		if ($ziel === null || !isset($liste[ $ziel ])) {
			return;
		}
		[$liste[ $pos ], $liste[ $ziel ]] = [$liste[ $ziel ], $liste[ $pos ]];
		foreach ($liste as $i => $zeile) {
			if ((int) $zeile['sortierung'] !== $i + 1) {
				$this->repo->update((int) $zeile['id'], ['sortierung' => $i + 1]);
			}
		}
	}
}
