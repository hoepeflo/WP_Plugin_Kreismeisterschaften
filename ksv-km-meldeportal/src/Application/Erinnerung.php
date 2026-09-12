<?php
/**
 * Erinnerungsmail vor dem Meldeschluss an Vereine mit Status Offen oder Entwurf.
 *
 * Zeitpunkt: Feld erinnerung_am am Sportjahr. Läuft über kmm_hourly_tasks (WP-Cron,
 * idealerweise per Server-Cronjob angestoßen) oder manuell aus der Übersicht. Jede Mail
 * enthält einen neuen Zugangslink; bestehende Links bleiben gültig.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Http\Router;
use KSV\KMM\Infrastructure\Repository\MeldungRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinEmailRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;
use KSV\KMM\Support\Clock;

final class Erinnerung {

	public static function register(): void {
		add_action('kmm_hourly_tasks', [self::class, 'cron']);
	}

	/** Cron: fällige Erinnerung des aktiven Sportjahres versenden. */
	public static function cron(): void {
		$sportjahr = (new SportjahrRepository())->aktiv();
		if ($sportjahr === null || !self::faellig($sportjahr)) {
			return;
		}
		self::senden((int) $sportjahr['id'], false);
	}

	/**
	 * @param array<string, mixed> $sportjahr
	 */
	public static function faellig(array $sportjahr, ?string $jetzt = null): bool {
		$jetzt ??= Clock::now_utc();
		if ($sportjahr['erinnerung_am'] === null || $sportjahr['erinnerung_gesendet_am'] !== null) {
			return false;
		}
		if ($sportjahr['meldeschluss'] !== null && (string) $sportjahr['meldeschluss'] <= $jetzt) {
			return false; // nach Meldeschluss keine Erinnerung mehr
		}
		return (string) $sportjahr['erinnerung_am'] <= $jetzt;
	}

	/**
	 * Vereine, die eine Erinnerung bekommen: aktiv, mit Adresse, Status offen oder entwurf.
	 *
	 * @return list<array<string, mixed>> Verein mit Feldern status, adressen
	 */
	public static function empfaenger(int $sportjahr_id): array {
		$meldungen = (new MeldungRepository())->by_sportjahr($sportjahr_id);
		$adressen = (new VereinEmailRepository())->alle();
		$out = [];
		foreach ((new VereinRepository())->all(true) as $verein) {
			$vid = (int) $verein['id'];
			$status = isset($meldungen[ $vid ]) ? (string) $meldungen[ $vid ]['status'] : MeldungRepository::STATUS_OFFEN;
			if ($status === MeldungRepository::STATUS_EINGEREICHT || ($adressen[ $vid ] ?? []) === []) {
				continue;
			}
			$verein['status'] = $status;
			$verein['adressen'] = $adressen[ $vid ];
			$out[] = $verein;
		}
		return $out;
	}

	/**
	 * Sendet die Erinnerung an alle fälligen Vereine des Sportjahres.
	 *
	 * @param bool $manuell true = aus dem Backend ausgelöst (auch wenn nicht fällig / schon gesendet)
	 * @return array{gesendet: int, fehler: string[]}
	 */
	public static function senden(int $sportjahr_id, bool $manuell): array {
		$sportjahr = (new SportjahrRepository())->find($sportjahr_id);
		if ($sportjahr === null) {
			throw new \RuntimeException('Sportjahr nicht gefunden.');
		}
		$gesendet = 0;
		$fehler = [];
		foreach (self::empfaenger($sportjahr_id) as $verein) {
			$vid = (int) $verein['id'];
			try {
				$link = Zugang::link_erzeugen($vid, $sportjahr_id, 'erinnerung', false);
			} catch (\RuntimeException $e) {
				$fehler[] = (string) $verein['name'] . ': ' . $e->getMessage();
				continue;
			}
			$ok = Mailer::senden(
				$verein['adressen'],
				sprintf('Erinnerung: Meldung zur KM %d bis %s', (int) $sportjahr['jahr'], Clock::format_local($sportjahr['meldeschluss'], 'd.m.Y')),
				'erinnerung',
				[
					'verein'       => $verein,
					'sportjahr'    => $sportjahr,
					'status'       => (string) $verein['status'],
					'url'          => $link['url'],
					'anfordern'    => Router::url('link-anfordern'),
					'meldeschluss' => Clock::format_local($sportjahr['meldeschluss'], 'd.m.Y H:i'),
				],
				Mailer::TYP_ERINNERUNG,
				$sportjahr_id,
				$vid
			);
			if ($ok) {
				$gesendet++;
			} else {
				$fehler[] = (string) $verein['name'] . ': Mailversand fehlgeschlagen';
			}
		}
		(new SportjahrRepository())->update($sportjahr_id, ['erinnerung_gesendet_am' => Clock::now_utc()]);
		$text = sprintf('Erinnerungsmail an %d Vereine gesendet%s', $gesendet, $fehler !== [] ? ', ' . count($fehler) . ' Fehler' : '');
		if ($manuell) {
			Protokoll::admin('erinnerung.senden', $text, $sportjahr_id, null, 'sportjahr', $sportjahr_id, ['fehler' => $fehler]);
		} else {
			Protokoll::system('erinnerung.senden', $text, $sportjahr_id, null, 'sportjahr', $sportjahr_id, ['fehler' => $fehler]);
		}
		return ['gesendet' => $gesendet, 'fehler' => $fehler];
	}
}
