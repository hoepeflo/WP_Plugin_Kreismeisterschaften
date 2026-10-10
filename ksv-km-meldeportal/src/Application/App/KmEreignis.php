<?php
/**
 * Baut die App-Ereignisse des KM-Portals nach API-Vertrag Abschnitt 2 und 3.3
 * (Repo APP_KSV-Fallingbostel, docs/API-VERTRAG.md).
 *
 * Reines PHP ohne WordPress-Abhängigkeit (unit-testbar). Ereignisse enthalten nie
 * Personendaten – nur Sportjahr, Wettkampftag, Datum und Termine.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application\App;

final class KmEreignis {

	public const QUELLE = 'ksv-km-meldeportal';
	public const ZIEL = 'km';

	public const THEMA_MELDUNG       = 'km_meldung';
	public const THEMA_ERINNERUNG    = 'km_erinnerung';
	public const THEMA_STARTPLAETZE  = 'km_startplaetze';
	public const THEMA_STARTPLAN     = 'km_startplan';

	/** Längen laut Vertrag. */
	public const MAX_SCHLUESSEL = 190;
	public const MAX_TITEL = 65;
	public const MAX_TEXT = 240;

	/** Bezeichnungen eines Wettkampftags bis zu dieser Länge kommen in den Text. */
	public const MAX_BEZEICHNUNG_IM_TEXT = 40;

	/**
	 * „Meldeportal geöffnet“ (meldung_beginn des aktiven Sportjahres erreicht).
	 *
	 * @param array<string, mixed> $sportjahr Zeile aus kmm_sportjahr (Zeiten in UTC)
	 * @return array<string, mixed>
	 */
	public static function meldung_offen(array $sportjahr, \DateTimeZone $tz): array {
		$jahr = (int) $sportjahr['jahr'];
		return self::ereignis(
			'kmm.meldung_offen.' . (int) $sportjahr['id'],
			self::THEMA_MELDUNG,
			'Meldeportal geöffnet',
			sprintf('Die Meldung zur Kreismeisterschaft %d ist geöffnet. Meldeschluss: %s.', $jahr, self::datum_utc($sportjahr['meldeschluss'] ?? null, $tz)),
			['sportjahr_id' => (int) $sportjahr['id'], 'jahr' => $jahr]
		);
	}

	/**
	 * „Meldeschluss naht“ (erinnerung_am des aktiven Sportjahres erreicht).
	 *
	 * @param array<string, mixed> $sportjahr
	 * @return array<string, mixed>
	 */
	public static function erinnerung(array $sportjahr, \DateTimeZone $tz): array {
		$jahr = (int) $sportjahr['jahr'];
		return self::ereignis(
			'kmm.erinnerung.' . (int) $sportjahr['id'],
			self::THEMA_ERINNERUNG,
			'Meldeschluss naht',
			sprintf('Meldeschluss für die Kreismeisterschaft %d ist am %s.', $jahr, self::datum_utc($sportjahr['meldeschluss'] ?? null, $tz)),
			['sportjahr_id' => (int) $sportjahr['id'], 'jahr' => $jahr]
		);
	}

	/**
	 * „Startplätze buchbar“ (Wettkampftag freigegeben).
	 *
	 * @param array<string, mixed> $tag Zeile aus kmm_wettkampftag
	 * @return array<string, mixed>
	 */
	public static function startplaetze(array $tag): array {
		$bez = self::kurze_bezeichnung($tag);
		$datum = self::datum_tag((string) $tag['datum']);
		$text = $bez !== null
			? sprintf('Für den Wettkampftag „%s“ am %s können jetzt Startplätze gebucht werden.', $bez, $datum)
			: sprintf('Für den Wettkampftag am %s können jetzt Startplätze gebucht werden.', $datum);
		return self::ereignis('kmm.startplaetze.' . (int) $tag['id'], self::THEMA_STARTPLAETZE, 'Startplätze buchbar', $text, self::tag_daten($tag));
	}

	/**
	 * „Startplan veröffentlicht“ (Wettkampftag veröffentlicht).
	 *
	 * @param array<string, mixed> $tag
	 * @return array<string, mixed>
	 */
	public static function startplan(array $tag): array {
		$bez = self::kurze_bezeichnung($tag);
		$datum = self::datum_tag((string) $tag['datum']);
		$text = $bez !== null
			? sprintf('Der Startplan für den Wettkampftag „%s“ am %s ist veröffentlicht.', $bez, $datum)
			: sprintf('Der Startplan für den Wettkampftag am %s ist veröffentlicht.', $datum);
		return self::ereignis('kmm.startplan.' . (int) $tag['id'], self::THEMA_STARTPLAN, 'Startplan veröffentlicht', $text, self::tag_daten($tag));
	}

	/**
	 * Nur Daten-Abgleich (kein Push): Sportjahr oder Wettkampftag geändert.
	 *
	 * @return array<string, mixed>
	 */
	public static function geaendert(int $zeitstempel): array {
		return [
			'schluessel' => 'kmm.geaendert.' . $zeitstempel,
			'quelle'     => self::QUELLE,
			'push'       => false,
			'thema'      => null,
			'titel'      => '',
			'text'       => '',
			'ziel'       => self::ZIEL,
			'abgleich'   => ['km'],
			'daten'      => [],
		];
	}

	/**
	 * @param array<string, mixed> $daten
	 * @return array<string, mixed>
	 */
	private static function ereignis(string $schluessel, string $thema, string $titel, string $text, array $daten): array {
		return [
			'schluessel' => substr($schluessel, 0, self::MAX_SCHLUESSEL),
			'quelle'     => self::QUELLE,
			'push'       => true,
			'thema'      => $thema,
			'titel'      => self::kuerzen($titel, self::MAX_TITEL),
			'text'       => self::kuerzen($text, self::MAX_TEXT),
			'ziel'       => self::ZIEL,
			'abgleich'   => ['km'],
			'daten'      => $daten,
		];
	}

	/**
	 * @param array<string, mixed> $tag
	 * @return array<string, mixed>
	 */
	private static function tag_daten(array $tag): array {
		return ['wettkampftag_id' => (int) $tag['id'], 'datum' => (string) $tag['datum']];
	}

	/**
	 * Bezeichnung des Tages, wenn sie kurz genug für die Push-Nachricht ist.
	 *
	 * @param array<string, mixed> $tag
	 */
	public static function kurze_bezeichnung(array $tag): ?string {
		$bez = trim(preg_replace('/\s+/u', ' ', (string) ($tag['bezeichnung'] ?? '')) ?? '');
		if ($bez === '' || mb_strlen($bez) > self::MAX_BEZEICHNUNG_IM_TEXT) {
			return null;
		}
		return $bez;
	}

	/** Text auf eine Höchstlänge kürzen (mit Auslassungszeichen). */
	public static function kuerzen(string $text, int $max): string {
		if (mb_strlen($text) <= $max) {
			return $text;
		}
		return rtrim(mb_substr($text, 0, $max - 1)) . '…';
	}

	/** UTC-Datenbankwert als TT.MM.JJJJ in der Zeitzone der Website ('' bei NULL). */
	public static function datum_utc(mixed $utc, \DateTimeZone $tz): string {
		if (!is_string($utc) || $utc === '') {
			return '';
		}
		$dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new \DateTimeZone('UTC'));
		return $dt === false ? '' : $dt->setTimezone($tz)->format('d.m.Y');
	}

	/** Kalendertag (YYYY-MM-DD) als TT.MM.JJJJ. */
	public static function datum_tag(string $datum): string {
		$dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $datum);
		return $dt === false ? $datum : $dt->format('d.m.Y');
	}
}
