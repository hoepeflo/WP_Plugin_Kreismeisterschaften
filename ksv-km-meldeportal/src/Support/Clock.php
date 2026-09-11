<?php
/**
 * Zeitfunktionen: Speicherung in UTC, Anzeige in der WordPress-Zeitzone.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Support;

final class Clock {

	public const DB_FORMAT = 'Y-m-d H:i:s';

	/** Aktueller Zeitpunkt als UTC-String für die Datenbank. */
	public static function now_utc(): string {
		return gmdate(self::DB_FORMAT);
	}

	/** Aktueller Zeitpunkt als Objekt in UTC. */
	public static function now(): \DateTimeImmutable {
		return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
	}

	/** UTC-Datenbankwert in der Site-Zeitzone formatiert (leer bei NULL). */
	public static function format_local(?string $utc, string $format = 'd.m.Y H:i'): string {
		if ($utc === null || $utc === '' || $utc === '0000-00-00 00:00:00') {
			return '';
		}
		$dt = \DateTimeImmutable::createFromFormat(self::DB_FORMAT, $utc, new \DateTimeZone('UTC'));
		if ($dt === false) {
			return '';
		}
		return wp_date($format, $dt->getTimestamp());
	}

	/**
	 * Lokale Eingabe (datetime-local, "Y-m-d\TH:i" oder "Y-m-d H:i") nach UTC.
	 */
	public static function local_to_utc(string $local): ?string {
		$local = trim(str_replace('T', ' ', $local));
		if ($local === '') {
			return null;
		}
		$tz = wp_timezone();
		$dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $local, $tz);
		if ($dt === false) {
			$dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $local, $tz);
		}
		if ($dt === false) {
			return null;
		}
		return $dt->setTimezone(new \DateTimeZone('UTC'))->format(self::DB_FORMAT);
	}

	/** UTC-Datenbankwert als Wert für ein datetime-local-Feld (Site-Zeitzone). */
	public static function utc_to_local_input(?string $utc): string {
		if ($utc === null || $utc === '') {
			return '';
		}
		$dt = \DateTimeImmutable::createFromFormat(self::DB_FORMAT, $utc, new \DateTimeZone('UTC'));
		if ($dt === false) {
			return '';
		}
		return $dt->setTimezone(wp_timezone())->format('Y-m-d\TH:i');
	}
}
