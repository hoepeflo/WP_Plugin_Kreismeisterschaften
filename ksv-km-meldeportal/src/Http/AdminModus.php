<?php
/**
 * Admin-Modus der Vereinsoberfläche: Backend-Benutzer mit dem Recht „Meldungen bearbeiten“
 * öffnen die Meldung eines Vereins in derselben Oberfläche (Route /km-meldung/admin/<Verein>/).
 *
 * Zugang: WordPress-Login (kein Magic Link), Recht Rechte::RECHT_MELDUNGEN. REST-Aufrufe
 * tragen den WordPress-REST-Nonce (X-WP-Nonce) sowie Verein und Sportjahr als Header
 * (X-KMM-Verein, X-KMM-Sportjahr); beides wird serverseitig geprüft.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Http;

use KSV\KMM\Auth\Rechte;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;

final class AdminModus {

	public const HEADER_VEREIN    = 'X-KMM-Verein';
	public const HEADER_SPORTJAHR = 'X-KMM-Sportjahr';

	/** Darf der angemeldete WordPress-Benutzer Meldungen bearbeiten? */
	public static function erlaubt(): bool {
		return is_user_logged_in() && Rechte::hat_recht(Rechte::RECHT_MELDUNGEN);
	}

	/**
	 * Verein mit sportjahr_id (wie eine Vereinssitzung) oder null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function verein(int $verein_id, int $sportjahr_id): ?array {
		if ($verein_id <= 0 || $sportjahr_id <= 0) {
			return null;
		}
		$verein = (new VereinRepository())->find($verein_id);
		$sportjahr = (new SportjahrRepository())->find($sportjahr_id);
		if ($verein === null || $sportjahr === null) {
			return null;
		}
		$verein['sportjahr_id'] = $sportjahr_id;
		$verein['sitzung_id'] = 0;
		return $verein;
	}
}
