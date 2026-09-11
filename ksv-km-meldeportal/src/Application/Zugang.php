<?php
/**
 * Zugang der Vereine: Magic Links und Sitzungen.
 *
 * Token: 32 Byte Zufall, nur der SHA-256-Hash wird gespeichert, Vergleich mit hash_equals.
 * Beim Aufruf des Links wird eine Sitzung erzeugt, deren Token in einem HttpOnly-,
 * Secure- (bei HTTPS) und SameSite=Lax-Cookie liegt, und auf eine URL ohne Token umgeleitet.
 * Links gelten bis zum Abschluss des Sportjahres; „Neu senden“ widerruft alte Links samt
 * Sitzungen. Schreibzugriff ist eine Frage der Meldephase, nicht des Zugangs.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Http\Router;
use KSV\KMM\Infrastructure\Repository\MagicLinkRepository;
use KSV\KMM\Infrastructure\Repository\SitzungRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinEmailRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;
use KSV\KMM\Support\Clock;
use KSV\KMM\Support\Settings;

final class Zugang {

	public const COOKIE = 'kmm_sitzung';

	public const ANLASS_ADMIN   = 'admin';
	public const ANLASS_ALLE    = 'alle';
	public const ANLASS_ANFRAGE = 'anfrage';

	/** @var array<string, mixed>|null|false */
	private static array|null|false $aktuelle = false;

	// ----- Token -------------------------------------------------------------------------

	public static function token_erzeugen(): string {
		return bin2hex(random_bytes(32));
	}

	public static function hash(string $token): string {
		return hash('sha256', $token);
	}

	public static function token_gueltig_format(string $token): bool {
		return (bool) preg_match('/^[a-f0-9]{64}$/', $token);
	}

	// ----- Magic Link ---------------------------------------------------------------------

	/**
	 * Erzeugt einen neuen Link für einen Verein im Sportjahr. Bei $alte_widerrufen werden
	 * bestehende Links und Sitzungen des Vereins ungültig.
	 *
	 * @return array{id: int, token: string, url: string, gueltig_bis: string}
	 */
	public static function link_erzeugen(int $verein_id, int $sportjahr_id, string $anlass, bool $alte_widerrufen = true): array {
		$sportjahr = (new SportjahrRepository())->find($sportjahr_id);
		if ($sportjahr === null) {
			throw new \RuntimeException('Sportjahr nicht gefunden.');
		}
		$jetzt = Clock::now_utc();
		if ($alte_widerrufen) {
			(new MagicLinkRepository())->widerrufen($verein_id, $sportjahr_id, $jetzt);
			(new SitzungRepository())->beenden_fuer_verein($verein_id, $jetzt);
		}
		$token = self::token_erzeugen();
		$gueltig_bis = sprintf('%d-12-31 23:59:59', (int) $sportjahr['jahr']);
		$id = (new MagicLinkRepository())->insert([
			'verein_id'    => $verein_id,
			'sportjahr_id' => $sportjahr_id,
			'token_hash'   => self::hash($token),
			'erstellt_am'  => $jetzt,
			'gueltig_bis'  => $gueltig_bis,
			'erstellt_von' => get_current_user_id() > 0 ? get_current_user_id() : null,
			'anlass'       => $anlass,
		]);
		return ['id' => $id, 'token' => $token, 'url' => Router::url('zugang/' . $token), 'gueltig_bis' => $gueltig_bis];
	}

	/**
	 * Link erzeugen und per Mail an alle Vereinsadressen senden.
	 *
	 * @return array{ok: bool, empfaenger: int}
	 */
	public static function link_senden(int $verein_id, int $sportjahr_id, string $anlass, bool $alte_widerrufen = true): array {
		$verein = (new VereinRepository())->find($verein_id);
		$sportjahr = (new SportjahrRepository())->find($sportjahr_id);
		if ($verein === null || $sportjahr === null) {
			throw new \RuntimeException('Verein oder Sportjahr nicht gefunden.');
		}
		$adressen = (new VereinEmailRepository())->adressen($verein_id);
		if ($adressen === []) {
			throw new \RuntimeException(sprintf('%s hat keine E-Mail-Adresse.', (string) $verein['name']));
		}
		$link = self::link_erzeugen($verein_id, $sportjahr_id, $anlass, $alte_widerrufen);
		$ok = Mailer::senden(
			$adressen,
			sprintf('Ihr Zugang zum KM-Meldeportal %d', (int) $sportjahr['jahr']),
			'magic-link',
			[
				'verein'       => $verein,
				'sportjahr'    => $sportjahr,
				'url'          => $link['url'],
				'anfordern'    => Router::url('link-anfordern'),
				'meldeschluss' => Clock::format_local($sportjahr['meldeschluss'], 'd.m.Y H:i'),
			],
			Mailer::TYP_MAGIC_LINK,
			$sportjahr_id,
			$verein_id
		);
		if ($anlass === self::ANLASS_ANFRAGE) {
			Protokoll::system('link.angefordert', sprintf('Zugangslink auf Anfrage an %s gesendet', (string) $verein['name']), $sportjahr_id, $verein_id, 'verein', $verein_id);
		} else {
			Protokoll::admin('link.senden', sprintf('Zugangslink an %s gesendet (%d Adressen)%s', (string) $verein['name'], count($adressen), $alte_widerrufen ? ', alter Link widerrufen' : ''), $sportjahr_id, $verein_id, 'verein', $verein_id);
		}
		return ['ok' => $ok, 'empfaenger' => count($adressen)];
	}

	/**
	 * Tauscht ein Token gegen eine Sitzung (Cookie). Liefert den Verein oder null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function link_einloesen(string $token): ?array {
		if (!self::token_gueltig_format($token)) {
			return null;
		}
		$links = new MagicLinkRepository();
		$link = $links->by_hash(self::hash($token));
		if ($link === null || !hash_equals((string) $link['token_hash'], self::hash($token))) {
			return null;
		}
		$jetzt = Clock::now_utc();
		if ($link['widerrufen_am'] !== null || (string) $link['gueltig_bis'] < $jetzt) {
			return null;
		}
		$sportjahr = (new SportjahrRepository())->find((int) $link['sportjahr_id']);
		if ($sportjahr === null || $sportjahr['abgeschlossen_am'] !== null) {
			return null;
		}
		$verein = (new VereinRepository())->find((int) $link['verein_id']);
		if ($verein === null || !$verein['ist_aktiv']) {
			return null;
		}
		$links->update((int) $link['id'], ['zuletzt_verwendet_am' => $jetzt, 'verwendungen' => (int) $link['verwendungen'] + 1]);

		$sitzung_token = self::token_erzeugen();
		$dauer = max(1, (int) Settings::get('sitzung_dauer_tage'));
		$gueltig_bis = gmdate(Clock::DB_FORMAT, time() + $dauer * DAY_IN_SECONDS);
		(new SitzungRepository())->insert([
			'verein_id'            => (int) $verein['id'],
			'magic_link_id'        => (int) $link['id'],
			'token_hash'           => self::hash($sitzung_token),
			'erstellt_am'          => $jetzt,
			'gueltig_bis'          => $gueltig_bis,
			'letzte_aktivitaet_am' => $jetzt,
		]);
		self::cookie_setzen($sitzung_token, time() + $dauer * DAY_IN_SECONDS);
		Protokoll::verein((int) $verein['id'], (string) $verein['name'], 'zugang.login', 'Anmeldung über Zugangslink', (int) $link['sportjahr_id']);
		self::$aktuelle = false;
		return $verein;
	}

	// ----- Sitzung -------------------------------------------------------------------------

	/**
	 * Verein der aktuellen Sitzung (Cookie) oder null. Ergebnis wird pro Request gecacht.
	 *
	 * @return array<string, mixed>|null  Verein mit Zusatzfeldern sitzung_id, sportjahr_id
	 */
	public static function aktueller_verein(): ?array {
		if (self::$aktuelle !== false) {
			return self::$aktuelle;
		}
		self::$aktuelle = null;
		$token = isset($_COOKIE[ self::COOKIE ]) ? (string) $_COOKIE[ self::COOKIE ] : '';
		if (!self::token_gueltig_format($token)) {
			return null;
		}
		$sitzungen = new SitzungRepository();
		$sitzung = $sitzungen->by_hash(self::hash($token));
		if ($sitzung === null || !hash_equals((string) $sitzung['token_hash'], self::hash($token))) {
			return null;
		}
		$jetzt = Clock::now_utc();
		if ($sitzung['beendet_am'] !== null || (string) $sitzung['gueltig_bis'] < $jetzt) {
			return null;
		}
		$link = (new MagicLinkRepository())->find((int) $sitzung['magic_link_id']);
		if ($link === null || $link['widerrufen_am'] !== null) {
			return null;
		}
		$sportjahr = (new SportjahrRepository())->find((int) $link['sportjahr_id']);
		if ($sportjahr === null || $sportjahr['abgeschlossen_am'] !== null) {
			return null;
		}
		$verein = (new VereinRepository())->find((int) $sitzung['verein_id']);
		if ($verein === null || !$verein['ist_aktiv']) {
			return null;
		}
		// Aktivität höchstens alle 5 Minuten schreiben.
		if (strtotime((string) $sitzung['letzte_aktivitaet_am'] . ' UTC') < time() - 300) {
			$sitzungen->update((int) $sitzung['id'], ['letzte_aktivitaet_am' => $jetzt]);
		}
		$verein['sitzung_id'] = (int) $sitzung['id'];
		$verein['sportjahr_id'] = (int) $link['sportjahr_id'];
		self::$aktuelle = $verein;
		return $verein;
	}

	public static function abmelden(): void {
		$verein = self::aktueller_verein();
		if ($verein !== null) {
			(new SitzungRepository())->update((int) $verein['sitzung_id'], ['beendet_am' => Clock::now_utc()]);
		}
		self::cookie_setzen('', time() - DAY_IN_SECONDS);
		self::$aktuelle = null;
	}

	private static function cookie_setzen(string $wert, int $ablauf): void {
		if (headers_sent()) {
			return;
		}
		setcookie(self::COOKIE, $wert, [
			'expires'  => $ablauf,
			'path'     => '/' . Settings::route_slug() . '/',
			'domain'   => '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		]);
	}

	/** Für Tests: Request-Cache zurücksetzen. */
	public static function cache_leeren(): void {
		self::$aktuelle = false;
	}
}
