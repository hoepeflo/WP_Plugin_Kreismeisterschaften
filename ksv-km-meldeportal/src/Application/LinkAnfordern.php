<?php
/**
 * „Link anfordern“: immer dieselbe Antwort, Rate-Limit je IP und Adresse (Transients).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinEmailRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;
use KSV\KMM\Support\Settings;

final class LinkAnfordern {

	private const FENSTER = HOUR_IN_SECONDS;

	/**
	 * Verarbeitet eine Anfrage. Liefert immer true (neutrale Antwort), außer bei
	 * Rate-Limit-Überschreitung (false), damit die Seite einen Hinweis zeigen kann.
	 */
	public static function verarbeiten(string $email, string $ip): bool {
		$email = strtolower(trim($email));
		$limit = max(1, (int) Settings::get('link_anfordern_limit'));
		if (!self::zaehlen('ip_' . hash('sha256', $ip), $limit) || ($email !== '' && !self::zaehlen('mail_' . hash('sha256', $email), $limit))) {
			return false;
		}
		if (!is_email($email)) {
			return true;
		}
		$sportjahr = (new SportjahrRepository())->aktiv();
		if ($sportjahr === null || $sportjahr['abgeschlossen_am'] !== null) {
			return true;
		}
		$vereine = new VereinRepository();
		foreach ((new VereinEmailRepository())->vereine_mit_adresse($email) as $verein_id) {
			$verein = $vereine->find($verein_id);
			if ($verein === null || !$verein['ist_aktiv']) {
				continue;
			}
			try {
				// Alte Links bleiben gültig: ein Kollege mit bestehendem Link wird nicht ausgesperrt.
				Zugang::link_senden($verein_id, (int) $sportjahr['id'], Zugang::ANLASS_ANFRAGE, false);
			} catch (\RuntimeException $e) {
				continue;
			}
		}
		return true;
	}

	/** Zählt Versuche im Zeitfenster; false, wenn das Limit erreicht ist. */
	private static function zaehlen(string $schluessel, int $limit): bool {
		$key = 'kmm_rl_' . substr($schluessel, 0, 40);
		$n = (int) get_transient($key);
		if ($n >= $limit) {
			return false;
		}
		set_transient($key, $n + 1, self::FENSTER);
		return true;
	}
}
