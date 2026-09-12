<?php
/**
 * Tägliche Sammelmail (Konzept 12.1): Einmal täglich zur eingestellten Uhrzeit erhält
 * jeder Verein, bei dem sich seit der letzten Mail etwas geändert hat, eine Mail mit
 * allen Änderungen samt Gründen und seinem Zugangslink.
 *
 * Idempotenz: Die offenen Änderungen eines Vereins werden vor dem Versand atomar
 * beansprucht (UPDATE … WHERE versendet_am IS NULL); ein zweiter Lauf – ob durch den
 * täglichen Cron, den stündlichen Fallback oder einen doppelten Aufruf – findet nichts
 * mehr und sendet daher keine zweite Mail. Schlägt der Versand fehl, werden die
 * Änderungen wieder freigegeben und beim nächsten Lauf erneut versucht. Zusätzlich
 * merkt sich das Plugin das Datum des letzten Cron-Laufs (Option kmm_sammelmail_lauf).
 *
 * Abschaltbar über die Einstellung sammelmail_aktiv; der manuelle Versand aus der
 * Übersicht bleibt möglich.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Domain\AenderungTyp;
use KSV\KMM\Http\Router;
use KSV\KMM\Infrastructure\Repository\AenderungRepository;
use KSV\KMM\Infrastructure\Repository\MeldungRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinEmailRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;
use KSV\KMM\Support\Clock;
use KSV\KMM\Support\Settings;

final class Sammelmail {

	public const OPTION_LAUF = 'kmm_sammelmail_lauf';

	public static function register(): void {
		add_action('kmm_daily_tasks', [self::class, 'cron']);
		add_action('kmm_hourly_tasks', [self::class, 'cron']); // Fallback, falls das tägliche Ereignis ausfällt
	}

	/** Cron: fällige Sammelmail des aktiven Sportjahres versenden (einmal je Tag). */
	public static function cron(): void {
		$sportjahr = (new SportjahrRepository())->aktiv();
		if ($sportjahr === null || !self::faellig()) {
			return;
		}
		self::lauf_vermerken();
		self::senden((int) $sportjahr['id'], false);
	}

	/**
	 * Fällig, wenn die Sammelmail aktiv ist, die eingestellte Uhrzeit (Ortszeit) heute
	 * erreicht ist und heute noch kein Lauf stattfand.
	 */
	public static function faellig(?int $jetzt = null): bool {
		if (!Settings::get('sammelmail_aktiv')) {
			return false;
		}
		$jetzt ??= time();
		$lokal = (new \DateTimeImmutable('@' . $jetzt))->setTimezone(wp_timezone());
		$uhrzeit = (string) Settings::get('sammelmail_uhrzeit');
		[$h, $m] = preg_match('/^(\d{1,2}):(\d{2})$/', $uhrzeit, $t) === 1 ? [(int) $t[1], (int) $t[2]] : [18, 0];
		if ($lokal < $lokal->setTime($h, $m, 0)) {
			return false;
		}
		$lauf = get_option(self::OPTION_LAUF, []);
		return !is_array($lauf) || ($lauf['datum'] ?? '') !== $lokal->format('Y-m-d');
	}

	private static function lauf_vermerken(): void {
		update_option(self::OPTION_LAUF, ['datum' => wp_date('Y-m-d'), 'am' => Clock::now_utc()], false);
	}

	/**
	 * @return array{datum: string, am: string}|null Letzter Cron-Lauf
	 */
	public static function letzter_lauf(): ?array {
		$lauf = get_option(self::OPTION_LAUF, []);
		return is_array($lauf) && isset($lauf['datum'], $lauf['am']) ? ['datum' => (string) $lauf['datum'], 'am' => (string) $lauf['am']] : null;
	}

	/**
	 * Empfänger eines Vereins: Ansprechpartner der Meldung und alle Vereinsadressen.
	 *
	 * @param array<string, mixed>|null $meldung
	 * @return string[]
	 */
	public static function empfaenger(int $verein_id, ?array $meldung): array {
		$adressen = (new VereinEmailRepository())->adressen($verein_id);
		if ($meldung !== null && is_email((string) $meldung['ansprechpartner_email'])) {
			array_unshift($adressen, (string) $meldung['ansprechpartner_email']);
		}
		return array_values(array_unique($adressen));
	}

	/**
	 * Sendet an alle Vereine mit offenen Änderungen je eine Sammelmail.
	 *
	 * @param bool $manuell true = aus dem Backend ausgelöst (unabhängig von Uhrzeit und Schalter)
	 * @return array{gesendet: int, aenderungen: int, fehler: string[]}
	 */
	public static function senden(int $sportjahr_id, bool $manuell): array {
		$sportjahr = (new SportjahrRepository())->find($sportjahr_id);
		if ($sportjahr === null) {
			throw new \RuntimeException('Sportjahr nicht gefunden.');
		}
		$repo = new AenderungRepository();
		$offen = $repo->offen_je_sportjahr($sportjahr_id);
		if ($offen === []) {
			return ['gesendet' => 0, 'aenderungen' => 0, 'fehler' => []];
		}
		$vereine = new VereinRepository();
		$meldungen = (new MeldungRepository())->by_sportjahr($sportjahr_id);
		$gesendet = 0;
		$anzahl = 0;
		$fehler = [];
		foreach ($offen as $vid => $aenderungen) {
			$verein = $vereine->find($vid);
			if ($verein === null) {
				continue;
			}
			$empfaenger = self::empfaenger($vid, $meldungen[ $vid ] ?? null);
			if ($empfaenger === []) {
				$fehler[] = sprintf('%s: keine E-Mail-Adresse (%d Änderungen bleiben offen)', (string) $verein['name'], count($aenderungen));
				continue;
			}
			$ids = array_map(static fn(array $a): int => (int) $a['id'], $aenderungen);
			$jetzt = Clock::now_utc();
			$beansprucht = $repo->beanspruchen($ids, $jetzt);
			if ($beansprucht === 0) {
				continue; // ein gleichzeitiger Lauf hat diese Änderungen bereits versendet
			}
			if ($beansprucht < count($ids)) {
				// Teilmenge bereits versendet: nur die tatsächlich beanspruchten Zeilen mailen.
				$aenderungen = array_values(array_filter($aenderungen, static function (array $a) use ($repo, $jetzt): bool {
					$row = $repo->find((int) $a['id']);
					return $row !== null && (string) $row['versendet_am'] === $jetzt;
				}));
				$ids = array_map(static fn(array $a): int => (int) $a['id'], $aenderungen);
			}
			try {
				$link = Zugang::link_erzeugen($vid, $sportjahr_id, 'sammelmail', false);
			} catch (\RuntimeException $e) {
				$repo->freigeben($ids);
				$fehler[] = (string) $verein['name'] . ': ' . $e->getMessage();
				continue;
			}
			$ok = Mailer::senden(
				$empfaenger,
				sprintf('KM %d: %s zu Ihrer Meldung', (int) $sportjahr['jahr'], count($aenderungen) === 1 ? '1 Änderung' : count($aenderungen) . ' Änderungen'),
				'sammelmail',
				[
					'verein'      => $verein,
					'sportjahr'   => $sportjahr,
					'aenderungen' => array_map([self::class, 'zeile'], $aenderungen),
					'url'         => $link['url'],
					'anfordern'   => Router::url('link-anfordern'),
				],
				Mailer::TYP_SAMMELMAIL,
				$sportjahr_id,
				$vid
			);
			if ($ok) {
				$gesendet++;
				$anzahl += count($aenderungen);
			} else {
				$repo->freigeben($ids);
				$fehler[] = (string) $verein['name'] . ': Mailversand fehlgeschlagen';
			}
		}
		$text = sprintf('Sammelmail an %d Vereine gesendet (%d Änderungen)%s', $gesendet, $anzahl, $fehler !== [] ? ', ' . count($fehler) . ' Fehler' : '');
		if ($manuell) {
			Protokoll::admin('sammelmail.senden', $text, $sportjahr_id, null, 'sportjahr', $sportjahr_id, ['fehler' => $fehler]);
		} elseif ($gesendet > 0 || $fehler !== []) {
			Protokoll::system('sammelmail.senden', $text, $sportjahr_id, null, 'sportjahr', $sportjahr_id, ['fehler' => $fehler]);
		}
		return ['gesendet' => $gesendet, 'aenderungen' => $anzahl, 'fehler' => $fehler];
	}

	public static function typ_label(string $typ): string {
		return match ($typ) {
			AenderungTyp::STATUS      => 'Startrechtsprüfung',
			AenderungTyp::ABMELDUNG   => 'Abmeldung',
			AenderungTyp::NACHMELDUNG => 'Nachmeldung',
			AenderungTyp::KORREKTUR   => 'Korrektur',
			AenderungTyp::MANNSCHAFT  => 'Mannschaft',
			AenderungTyp::STARTPLAN   => 'Startplan',
			default                   => $typ,
		};
	}

	/**
	 * @param array<string, mixed> $a
	 * @return array{zeit: string, typ: string, text: string}
	 */
	private static function zeile(array $a): array {
		return ['zeit' => Clock::format_local($a['erstellt_am'], 'd.m.Y H:i'), 'typ' => self::typ_label((string) $a['typ']), 'text' => (string) $a['text']];
	}
}
