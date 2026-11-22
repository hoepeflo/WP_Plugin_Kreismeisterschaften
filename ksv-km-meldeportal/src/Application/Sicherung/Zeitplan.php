<?php
/**
 * Reine Zeitlogik der Sicherung (ohne WordPress, damit testbar).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application\Sicherung;

final class Zeitplan {

	/** Anlässe aus dem Protokoll, nach denen eine Ereignis-Sicherung geplant wird. */
	public const EREIGNISSE = [
		'meldung.einreichen',
		'wettkampftag.freigeben',
		'wettkampftag.veroeffentlichen',
		'sportjahr.abschliessen',
	];

	/** Wartezeit nach einem Ereignis; weitere Ereignisse in dieser Zeit landen in derselben Sicherung. */
	public const SAMMELZEIT_SEKUNDEN = 600;

	/**
	 * Liegt der Tag im eingestellten Zeitraum? Leeres Von oder Bis = offen.
	 *
	 * @param string $heute Y-m-d (Ortszeit)
	 */
	public static function im_zeitraum(bool $aktiv, string $von, string $bis, string $heute): bool {
		if (!$aktiv) {
			return false;
		}
		if ($von !== '' && $heute < $von) {
			return false;
		}
		if ($bis !== '' && $heute > $bis) {
			return false;
		}
		return true;
	}

	/**
	 * Ist die tägliche Sicherung fällig?
	 *
	 * @param string $letzter_tag Y-m-d der letzten täglichen Sicherung (leer = nie)
	 * @param string $heute       Y-m-d (Ortszeit)
	 * @param string $jetzt       H:i (Ortszeit)
	 * @param string $uhrzeit     H:i, ab wann gesichert wird
	 */
	public static function taeglich_faellig(string $letzter_tag, string $heute, string $jetzt, string $uhrzeit): bool {
		return $letzter_tag !== $heute && $jetzt >= $uhrzeit;
	}

	public static function ist_ereignis(string $aktion): bool {
		return in_array($aktion, self::EREIGNISSE, true);
	}

	/** Dateiname einer Sicherung, z. B. kmm-sicherung-20261122-030512-taeglich.kmmsic */
	public static function dateiname(string $anlass, int $zeit): string {
		$anlass = preg_replace('/[^a-z-]/', '', strtolower($anlass)) ?? '';
		$anlass = substr($anlass !== '' ? $anlass : 'manuell', 0, 24);
		return sprintf('kmm-sicherung-%s-%s.kmmsic', gmdate('Ymd-His', $zeit), $anlass);
	}

	/** Prüft einen Dateinamen wie von dateiname() erzeugt (auch für den Briefkasten). */
	public static function gueltiger_dateiname(string $name): bool {
		return preg_match('/^kmm-sicherung-\d{8}-\d{6}-[a-z-]{1,24}\.kmmsic$/', $name) === 1;
	}
}
