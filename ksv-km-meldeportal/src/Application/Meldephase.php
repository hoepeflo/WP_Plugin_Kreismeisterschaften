<?php
/**
 * Meldephase: Darf ein Verein gerade schreiben?
 *
 * Schreibbar zwischen Meldebeginn und Meldeschluss des Sportjahres, außerdem bei einer
 * Nachmeldungs-Freischaltung (meldung.nachmeldung_bis in der Zukunft, Phase 2).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Support\Clock;

final class Meldephase {

	/**
	 * @param array<string, mixed>      $sportjahr
	 * @param array<string, mixed>|null $meldung
	 * @return array{schreibbar: bool, grund: string, status: string}
	 *   status: vor_beginn | offen | nachmeldung | geschlossen | abgeschlossen
	 */
	public static function pruefen(array $sportjahr, ?array $meldung = null, ?string $jetzt = null): array {
		$jetzt ??= Clock::now_utc();
		if ($sportjahr['abgeschlossen_am'] !== null) {
			return ['schreibbar' => false, 'grund' => 'Das Sportjahr ist abgeschlossen.', 'status' => 'abgeschlossen'];
		}
		if ($meldung !== null && $meldung['nachmeldung_bis'] !== null && (string) $meldung['nachmeldung_bis'] > $jetzt) {
			return ['schreibbar' => true, 'grund' => '', 'status' => 'nachmeldung'];
		}
		$beginn = $sportjahr['meldung_beginn'];
		$schluss = $sportjahr['meldeschluss'];
		if ($beginn !== null && (string) $beginn > $jetzt) {
			return ['schreibbar' => false, 'grund' => sprintf('Die Meldephase beginnt am %s Uhr.', Clock::format_local((string) $beginn)), 'status' => 'vor_beginn'];
		}
		if ($schluss !== null && (string) $schluss <= $jetzt) {
			return ['schreibbar' => false, 'grund' => sprintf('Der Meldeschluss (%s Uhr) ist vorbei. Änderungen sind nur noch über den KSV möglich.', Clock::format_local((string) $schluss)), 'status' => 'geschlossen'];
		}
		if ($schluss === null) {
			return ['schreibbar' => false, 'grund' => 'Die Meldephase ist noch nicht eingerichtet (kein Meldeschluss).', 'status' => 'vor_beginn'];
		}
		return ['schreibbar' => true, 'grund' => '', 'status' => 'offen'];
	}
}
