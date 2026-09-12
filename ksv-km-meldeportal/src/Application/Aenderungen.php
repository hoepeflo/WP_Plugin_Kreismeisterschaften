<?php
/**
 * Erfasst Änderungen nach Meldeschluss je Verein (Sammelmail, „Änderungen seit Export").
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Infrastructure\Repository\AenderungRepository;
use KSV\KMM\Support\Clock;

final class Aenderungen {

	/**
	 * @param array<string, mixed> $details
	 */
	public static function erfassen(int $sportjahr_id, int $verein_id, ?int $einzelmeldung_id, string $typ, string $text, array $details = []): int {
		return (new AenderungRepository())->insert([
			'sportjahr_id'     => $sportjahr_id,
			'verein_id'        => $verein_id,
			'einzelmeldung_id' => $einzelmeldung_id,
			'typ'              => $typ,
			'text'             => mb_substr($text, 0, 255),
			'details'          => $details === [] ? null : (string) json_encode($details, JSON_UNESCAPED_UNICODE),
			'erstellt_am'      => Clock::now_utc(),
		]);
	}
}
