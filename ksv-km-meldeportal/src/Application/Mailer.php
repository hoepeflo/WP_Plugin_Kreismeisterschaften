<?php
/**
 * Mailversand über wp_mail() (SMTP-Konfiguration von WP Mail SMTP greift), mit
 * Absender aus den Einstellungen und Eintrag im Mail-Protokoll (ohne Adressen).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Http\View;
use KSV\KMM\Infrastructure\Repository\MailLogRepository;
use KSV\KMM\Support\Clock;
use KSV\KMM\Support\Settings;

final class Mailer {

	public const TYP_MAGIC_LINK   = 'magic_link';
	public const TYP_BESTAETIGUNG = 'bestaetigung';
	public const TYP_ERINNERUNG   = 'erinnerung';
	public const TYP_SAMMELMAIL   = 'sammelmail';
	public const TYP_FREIGABE     = 'freigabe';
	public const TYP_BUCHUNG_ERINNERUNG = 'buchung_erinnerung';
	public const TYP_STARTPLAN     = 'startplan';

	/**
	 * @param string[]             $empfaenger
	 * @param array<string, mixed> $daten      Variablen für das Template templates/mail/<template>.php
	 */
	public static function senden(array $empfaenger, string $betreff, string $template, array $daten, string $typ, ?int $sportjahr_id = null, ?int $verein_id = null): bool {
		$empfaenger = array_values(array_unique(array_filter(array_map('sanitize_email', $empfaenger))));
		if ($empfaenger === []) {
			self::protokoll($typ, $betreff, 0, false, 'keine Empfängeradresse', $sportjahr_id, $verein_id);
			return false;
		}
		$daten['betreff'] = $betreff;
		$daten['site_name'] = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
		$text = View::capture('mail/' . $template, $daten);
		if ($text === '') {
			self::protokoll($typ, $betreff, count($empfaenger), false, 'Template fehlt: ' . $template, $sportjahr_id, $verein_id);
			return false;
		}

		$from_filter = static function (string $from): string {
			$adresse = (string) Settings::get('mail_absender_adresse');
			return is_email($adresse) ? $adresse : $from;
		};
		$name_filter = static function (string $name): string {
			$absender = (string) Settings::get('mail_absender_name');
			return $absender !== '' ? $absender : $name;
		};
		$fehler_text = '';
		$fehler_filter = static function (\WP_Error $error) use (&$fehler_text): void {
			$fehler_text = $error->get_error_message();
		};
		add_filter('wp_mail_from', $from_filter);
		add_filter('wp_mail_from_name', $name_filter);
		add_action('wp_mail_failed', $fehler_filter);

		$ok = wp_mail($empfaenger, $betreff, $text, ['Content-Type: text/plain; charset=UTF-8']);

		remove_filter('wp_mail_from', $from_filter);
		remove_filter('wp_mail_from_name', $name_filter);
		remove_action('wp_mail_failed', $fehler_filter);

		self::protokoll($typ, $betreff, count($empfaenger), (bool) $ok, $ok ? '' : ($fehler_text !== '' ? $fehler_text : 'wp_mail meldet Fehler'), $sportjahr_id, $verein_id);
		return (bool) $ok;
	}

	private static function protokoll(string $typ, string $betreff, int $anzahl, bool $ok, string $fehler, ?int $sportjahr_id, ?int $verein_id): void {
		(new MailLogRepository())->insert([
			'sportjahr_id'      => $sportjahr_id,
			'verein_id'         => $verein_id,
			'typ'               => $typ,
			'betreff'           => mb_substr($betreff, 0, 255),
			'empfaenger_anzahl' => $anzahl,
			'erfolgreich'       => $ok,
			'fehler'            => mb_substr($fehler, 0, 255),
			'gesendet_am'       => Clock::now_utc(),
		]);
	}
}
