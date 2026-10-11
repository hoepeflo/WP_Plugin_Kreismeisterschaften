<?php
/**
 * App-Modul des KM-Portals: löst die Ereignisse für die App „KSV Fallingbostel“ aus
 * (do_action('ksv_app_ereignis', …), API-Vertrag Abschnitt 2 und 3.3 im Repo
 * APP_KSV-Fallingbostel, docs/API-VERTRAG.md).
 *
 * Ohne den KSV App Connector hat der Hook keine Wirkung; das Portal läuft unverändert.
 * Jedes Push-Ereignis wird je Sportjahr bzw. Wettkampftag nur einmal ausgelöst
 * (Vermerk-Spalten app_*_am, atomar gesetzt); der Connector verwirft Wiederholungen
 * zusätzlich anhand des Schlüssels.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application\App;

use KSV\KMM\Application\Protokoll;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\WettkampftagRepository;
use KSV\KMM\Support\Clock;

final class KmApp {

	public const HOOK = 'ksv_app_ereignis';

	/** Option: zuletzt an die App gemeldete Meldephase („<sportjahr_id>:<phase>“, siehe KmAusloeser). */
	public const OPTION_PHASE = 'kmm_app_phase';

	/** Wurde in diesem Request eine Änderung an den KM-Daten protokolliert? */
	private static bool $geaendert = false;

	/** Wurde in diesem Request schon ein Ereignis mit Abgleich „km“ ausgelöst? */
	private static bool $abgleich_ausgeloest = false;

	public static function register(): void {
		add_action('kmm_hourly_tasks', [self::class, 'cron']);
		add_action('kmm_protokolliert', [self::class, 'protokolliert'], 10, 1);
	}

	/** Gibt es einen Empfänger (KSV App Connector)? */
	public static function verbunden(): bool {
		return has_action(self::HOOK) !== false;
	}

	/**
	 * Ereignis an den Hook übergeben. Fehler eines Empfängers dürfen die eigentliche
	 * Aktion (Freigabe, Cron) nicht abbrechen.
	 *
	 * @param array<string, mixed> $ereignis
	 */
	public static function ausloesen(array $ereignis): void {
		if (in_array('km', (array) ($ereignis['abgleich'] ?? []), true)) {
			self::$abgleich_ausgeloest = true;
		}
		try {
			do_action(self::HOOK, $ereignis);
		} catch (\Throwable $e) {
			Protokoll::system('app.fehler', mb_substr('App-Ereignis ' . (string) $ereignis['schluessel'] . ': ' . $e->getMessage(), 0, 255));
		}
	}

	// ----- Zeitgesteuert (stündlicher Cron) -------------------------------------------------

	/** Cron (kmm_hourly_tasks). do_action() übergibt ohne Argumente einen Leerstring, daher ohne Parameter. */
	public static function cron(): void {
		self::zeitgesteuert(Clock::now_utc());
	}

	/**
	 * Meldebeginn und Erinnerung des aktiven Sportjahres prüfen und ggf. auslösen; danach
	 * einen reinen Phasenwechsel (Uhr) als Abgleich melden.
	 */
	public static function zeitgesteuert(string $jetzt): void {
		$repo = new SportjahrRepository();
		$sportjahr = $repo->aktiv();
		// Ohne Connector nichts vermerken: wird er später aktiviert, gilt weiterhin die Karenz
		// und der erste Lauf meldet den dann aktuellen Phasenstand.
		if (!self::verbunden()) {
			return;
		}
		if ($sportjahr !== null) {
			self::push_ereignisse($repo, $sportjahr, $jetzt);
		}
		self::phasenwechsel($sportjahr, $jetzt);
	}

	/**
	 * Die Meldephase (vor_beginn → offen → geschlossen) ändert sich allein mit der Uhr; ohne
	 * Ereignis bliebe GET /km in Firestore bis zur nächsten Änderung veraltet. Weicht die Phase
	 * vom zuletzt gemeldeten Stand ab, folgt kmm.geaendert.<zeit> (ohne Push, Abgleich „km“).
	 *
	 * @param array<string, mixed>|null $sportjahr
	 * @return bool true, wenn ein Abgleich-Ereignis ausgelöst wurde.
	 */
	public static function phasenwechsel(?array $sportjahr, string $jetzt): bool {
		$vorher = (string) get_option(self::OPTION_PHASE, '');
		if (!KmAusloeser::phase_gewechselt($sportjahr, $jetzt, $vorher)) {
			return false;
		}
		$kennung = KmAusloeser::phase_kennung($sportjahr, $jetzt);
		update_option(self::OPTION_PHASE, $kennung, false);
		if (self::$abgleich_ausgeloest) {
			return false; // ein Push-Ereignis dieses Laufs stößt den Abgleich „km“ schon an
		}
		self::ausloesen(KmEreignis::geaendert(time()));
		$id = $sportjahr === null ? null : (int) $sportjahr['id'];
		Protokoll::system('app.phase', sprintf('App-Abgleich nach Phasenwechsel ausgelöst (%s → %s)', $vorher === '' ? '–' : $vorher, $kennung), $id, null, $id === null ? '' : 'sportjahr', $id);
		return true;
	}

	/**
	 * @param array<string, mixed> $sportjahr
	 */
	private static function push_ereignisse(SportjahrRepository $repo, array $sportjahr, string $jetzt): void {
		$id = (int) $sportjahr['id'];
		$tz = wp_timezone();
		if (KmAusloeser::meldung_offen($sportjahr, $jetzt) && $repo->setzen_wenn_leer($id, 'app_meldung_offen_am', $jetzt)) {
			$e = KmEreignis::meldung_offen($sportjahr, $tz);
			self::ausloesen($e);
			Protokoll::system('app.meldung_offen', 'App-Ereignis „Meldeportal geöffnet“ ausgelöst', $id, null, 'sportjahr', $id, ['schluessel' => $e['schluessel']]);
		}
		if (KmAusloeser::erinnerung($sportjahr, $jetzt) && $repo->setzen_wenn_leer($id, 'app_erinnerung_am', $jetzt)) {
			$e = KmEreignis::erinnerung($sportjahr, $tz);
			self::ausloesen($e);
			Protokoll::system('app.erinnerung', 'App-Ereignis „Meldeschluss naht“ ausgelöst', $id, null, 'sportjahr', $id, ['schluessel' => $e['schluessel']]);
		}
	}

	// ----- Freigabe / Veröffentlichung -------------------------------------------------------

	/**
	 * Nach der Freigabe eines Wettkampftags (Häkchen „Auch als Push-Nachricht senden“).
	 *
	 * @return bool true, wenn ein Push-Ereignis ausgelöst wurde.
	 */
	public static function startplaetze(int $tag_id, bool $haekchen): bool {
		return self::tag_ereignis($tag_id, $haekchen, 'app_startplaetze_am', 'app.startplaetze');
	}

	/**
	 * Nach der Veröffentlichung eines Startplans (Häkchen „Auch als Push-Nachricht senden“).
	 *
	 * @return bool true, wenn ein Push-Ereignis ausgelöst wurde.
	 */
	public static function startplan(int $tag_id, bool $haekchen): bool {
		return self::tag_ereignis($tag_id, $haekchen, 'app_startplan_am', 'app.startplan');
	}

	private static function tag_ereignis(int $tag_id, bool $haekchen, string $spalte, string $aktion): bool {
		if (!$haekchen || !self::verbunden()) {
			return false;
		}
		$tage = new WettkampftagRepository();
		$tag = $tage->find($tag_id);
		if ($tag === null) {
			return false;
		}
		$sportjahr = (new SportjahrRepository())->find((int) $tag['sportjahr_id']);
		if (!KmAusloeser::wettkampftag($tag, $sportjahr, $spalte, $haekchen)) {
			return false;
		}
		if (!$tage->setzen_wenn_leer($tag_id, $spalte, Clock::now_utc())) {
			return false; // parallel schon ausgelöst
		}
		$e = $spalte === 'app_startplaetze_am' ? KmEreignis::startplaetze($tag) : KmEreignis::startplan($tag);
		self::ausloesen($e);
		Protokoll::admin($aktion, sprintf('App-Ereignis „%s“ ausgelöst', (string) $e['titel']), (int) $tag['sportjahr_id'], null, 'wettkampftag', $tag_id, ['schluessel' => $e['schluessel']]);
		return true;
	}

	// ----- Daten-Abgleich ohne Push -----------------------------------------------------------

	/**
	 * Wird von Protokoll für jede protokollierte Aktion aufgerufen. Betrifft sie Sportjahr
	 * oder Wettkampftag, folgt am Ende des Requests genau ein Ereignis kmm.geaendert.<zeit>.
	 */
	public static function protokolliert(string $aktion): void {
		if (self::$geaendert || !KmAusloeser::aenderung_relevant($aktion)) {
			return;
		}
		self::$geaendert = true;
		add_action('shutdown', [self::class, 'geaendert_senden']);
	}

	public static function geaendert_senden(): void {
		if (!self::$geaendert) {
			return;
		}
		self::$geaendert = false;
		if (self::$abgleich_ausgeloest) {
			return; // ein Push-Ereignis dieses Requests stößt den Abgleich „km“ schon an
		}
		self::ausloesen(KmEreignis::geaendert(time()));
	}
}
