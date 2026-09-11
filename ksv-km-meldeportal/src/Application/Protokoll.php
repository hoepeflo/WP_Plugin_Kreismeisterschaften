<?php
/**
 * Änderungsprotokoll: wer, wann, was – für Vereins-, Admin- und Systemaktionen.
 * Keine personenbezogenen Daten über das Nötige hinaus, keine IP-Adressen.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Infrastructure\Repository\ProtokollRepository;
use KSV\KMM\Support\Clock;

final class Protokoll {

	public const AKTEUR_ADMIN  = 'admin';
	public const AKTEUR_VEREIN = 'verein';
	public const AKTEUR_SYSTEM = 'system';

	/**
	 * Eintrag durch den angemeldeten WordPress-Benutzer (Backend).
	 *
	 * @param array<string, mixed> $details
	 */
	public static function admin(string $aktion, string $zusammenfassung, ?int $sportjahr_id = null, ?int $verein_id = null, string $objekt_typ = '', ?int $objekt_id = null, array $details = []): void {
		$user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
		self::schreibe(
			self::AKTEUR_ADMIN,
			$user instanceof \WP_User && $user->ID > 0 ? (int) $user->ID : null,
			$user instanceof \WP_User && $user->ID > 0 ? (string) $user->display_name : '',
			$aktion,
			$zusammenfassung,
			$sportjahr_id,
			$verein_id,
			$objekt_typ,
			$objekt_id,
			$details
		);
	}

	/**
	 * Eintrag durch einen Verein (Vereinsoberfläche).
	 *
	 * @param array<string, mixed> $details
	 */
	public static function verein(int $verein_id, string $verein_name, string $aktion, string $zusammenfassung, ?int $sportjahr_id = null, string $objekt_typ = '', ?int $objekt_id = null, array $details = []): void {
		self::schreibe(self::AKTEUR_VEREIN, $verein_id, $verein_name, $aktion, $zusammenfassung, $sportjahr_id, $verein_id, $objekt_typ, $objekt_id, $details);
	}

	/**
	 * @param array<string, mixed> $details
	 */
	public static function system(string $aktion, string $zusammenfassung, ?int $sportjahr_id = null, ?int $verein_id = null, string $objekt_typ = '', ?int $objekt_id = null, array $details = []): void {
		self::schreibe(self::AKTEUR_SYSTEM, null, 'System', $aktion, $zusammenfassung, $sportjahr_id, $verein_id, $objekt_typ, $objekt_id, $details);
	}

	/**
	 * @param array<string, mixed> $details
	 */
	private static function schreibe(string $akteur_typ, ?int $akteur_id, string $akteur_name, string $aktion, string $zusammenfassung, ?int $sportjahr_id, ?int $verein_id, string $objekt_typ, ?int $objekt_id, array $details): void {
		(new ProtokollRepository())->insert([
			'sportjahr_id'    => $sportjahr_id,
			'verein_id'       => $verein_id,
			'akteur_typ'      => $akteur_typ,
			'akteur_id'       => $akteur_id,
			'akteur_name'     => mb_substr($akteur_name, 0, 150),
			'aktion'          => mb_substr($aktion, 0, 64),
			'objekt_typ'      => mb_substr($objekt_typ, 0, 32),
			'objekt_id'       => $objekt_id,
			'zusammenfassung' => mb_substr($zusammenfassung, 0, 255),
			'details'         => $details === [] ? null : (string) json_encode($details, JSON_UNESCAPED_UNICODE),
			'created_at'      => Clock::now_utc(),
		]);
	}
}
