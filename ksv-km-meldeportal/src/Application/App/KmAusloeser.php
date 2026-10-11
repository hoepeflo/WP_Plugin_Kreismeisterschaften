<?php
/**
 * Entscheidet, ob ein App-Ereignis des KM-Portals ausgelöst wird (API-Vertrag 3.3).
 *
 * Reines PHP ohne WordPress-Abhängigkeit (unit-testbar). Alle Zeiten als UTC-Strings
 * im Datenbankformat (Y-m-d H:i:s).
 *
 * Zeitgesteuert (stündlicher Cron, nur aktives Sportjahr):
 *  - Meldebeginn: meldung_beginn erreicht, Meldeschluss noch nicht, Schalter an, noch nicht ausgelöst.
 *  - Erinnerung: erinnerung_am erreicht, Meldephase läuft, Schalter an, noch nicht ausgelöst.
 *    Ohne erinnerung_am gibt es keine Erinnerung.
 *  - Liegt der Zeitpunkt mehr als KARENZ Sekunden zurück, wird nichts mehr gesendet
 *    („keine Nachrichten für Vergangenes“, z. B. beim ersten Einspielen des App-Moduls
 *    oder nach längerem Cron-Ausfall).
 *  - Phasenwechsel: Weicht die Meldephase (vor_beginn/offen/geschlossen/…) vom zuletzt
 *    gemeldeten Stand ab, folgt ein Abgleich ohne Push (kmm.geaendert.<zeit>).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application\App;

use KSV\KMM\Application\Meldephase;

final class KmAusloeser {

	/** Höchstens so lange (Sekunden) nach dem Zeitpunkt wird noch gesendet. */
	public const KARENZ = 86400;

	/**
	 * @param array<string, mixed> $sportjahr
	 */
	public static function meldung_offen(array $sportjahr, string $jetzt): bool {
		if (!self::grundbedingungen($sportjahr, 'push_meldebeginn', 'app_meldung_offen_am', $jetzt)) {
			return false;
		}
		return self::zeitpunkt_frisch($sportjahr['meldung_beginn'] ?? null, $jetzt);
	}

	/**
	 * @param array<string, mixed> $sportjahr
	 */
	public static function erinnerung(array $sportjahr, string $jetzt): bool {
		if (!self::grundbedingungen($sportjahr, 'push_erinnerung', 'app_erinnerung_am', $jetzt)) {
			return false;
		}
		$beginn = $sportjahr['meldung_beginn'] ?? null;
		if (is_string($beginn) && $beginn !== '' && $beginn > $jetzt) {
			return false; // Erinnerung vor dem Meldebeginn ergibt keinen Sinn
		}
		return self::zeitpunkt_frisch($sportjahr['erinnerung_am'] ?? null, $jetzt);
	}

	/**
	 * Freigabe / Veröffentlichung: Häkchen gesetzt, Sportjahr aktiv und noch nicht ausgelöst.
	 *
	 * @param array<string, mixed> $tag
	 * @param array<string, mixed>|null $sportjahr
	 */
	public static function wettkampftag(array $tag, ?array $sportjahr, string $vermerk_spalte, bool $haekchen): bool {
		if (!$haekchen || $sportjahr === null || empty($sportjahr['ist_aktiv']) || ($sportjahr['abgeschlossen_am'] ?? null) !== null) {
			return false;
		}
		if ((int) ($tag['sportjahr_id'] ?? 0) !== (int) ($sportjahr['id'] ?? -1)) {
			return false;
		}
		if (!array_key_exists($vermerk_spalte, $tag) || $tag[ $vermerk_spalte ] !== null) {
			return false; // Spalte fehlt (Schema nicht migriert) oder schon ausgelöst
		}
		return ($tag['ausgeblendet_am'] ?? null) === null;
	}

	/**
	 * Betrifft eine protokollierte Aktion die Daten von GET /km (Sportjahr, Wettkampftag,
	 * Ort über den Schießstand)? Dann folgt ein Abgleich-Ereignis kmm.geaendert.<zeit>.
	 */
	public static function aenderung_relevant(string $aktion): bool {
		if (in_array($aktion, ['sportjahr.anonymisieren', 'wettkampftag.einheiten', 'wettkampftag.erinnerung'], true)) {
			return false; // ändert nichts an den öffentlichen KM-Daten
		}
		return str_starts_with($aktion, 'sportjahr.')
			|| str_starts_with($aktion, 'wettkampftag.')
			|| in_array($aktion, ['schiessstand.aendern', 'schiessstand.loeschen'], true);
	}

	/**
	 * Kennung der aktuellen Meldephase für den Vergleich mit dem zuletzt an die App gemeldeten
	 * Stand: „<sportjahr_id>:<phase>“ (Phase aus Meldephase, wie in GET /km). Ohne aktives
	 * Sportjahr „0:geschlossen“. Die Phase wechselt allein durch die Uhr (meldung_beginn,
	 * meldeschluss), ohne dass eine Aktion protokolliert wird.
	 *
	 * @param array<string, mixed>|null $sportjahr
	 */
	public static function phase_kennung(?array $sportjahr, string $jetzt): string {
		if ($sportjahr === null) {
			return '0:geschlossen';
		}
		return (int) ($sportjahr['id'] ?? 0) . ':' . Meldephase::pruefen($sportjahr, null, $jetzt)['status'];
	}

	/**
	 * Hat die Meldephase gegenüber dem zuletzt gemeldeten Stand gewechselt? Dann folgt ein
	 * Abgleich-Ereignis kmm.geaendert.<zeit>. Ein leerer Stand (Erstlauf) gilt als Wechsel.
	 *
	 * @param array<string, mixed>|null $sportjahr
	 */
	public static function phase_gewechselt(?array $sportjahr, string $jetzt, string $vorher): bool {
		return self::phase_kennung($sportjahr, $jetzt) !== $vorher;
	}

	/**
	 * Gemeinsame Bedingungen der zeitgesteuerten Ereignisse.
	 *
	 * @param array<string, mixed> $sportjahr
	 */
	private static function grundbedingungen(array $sportjahr, string $schalter, string $vermerk, string $jetzt): bool {
		// Fehlende Spalten (Schema noch nicht migriert) gelten als „aus“.
		if (!array_key_exists($schalter, $sportjahr) || !array_key_exists($vermerk, $sportjahr)) {
			return false;
		}
		if (!(bool) $sportjahr[ $schalter ] || $sportjahr[ $vermerk ] !== null) {
			return false;
		}
		if (empty($sportjahr['ist_aktiv']) || ($sportjahr['abgeschlossen_am'] ?? null) !== null) {
			return false;
		}
		$schluss = $sportjahr['meldeschluss'] ?? null;
		// Ohne Meldeschluss ist die Meldephase nicht eingerichtet; danach ist sie vorbei.
		return is_string($schluss) && $schluss !== '' && $schluss > $jetzt;
	}

	private static function zeitpunkt_frisch(mixed $zeitpunkt, string $jetzt): bool {
		if (!is_string($zeitpunkt) || $zeitpunkt === '' || $zeitpunkt > $jetzt) {
			return false;
		}
		$z = strtotime($zeitpunkt . ' UTC');
		$j = strtotime($jetzt . ' UTC');
		if ($z === false || $j === false) {
			return false;
		}
		return $j - $z <= self::KARENZ;
	}
}
