<?php
/**
 * Abmeldungen nach Meldeschluss (Konzept 12.2): Zeitpunkt, Schalter „Startgeld berechnen".
 * Standard: vor Veröffentlichung des Startplans des betreffenden Wettkampftags kein
 * Startgeld, danach Startgeld; immer übersteuerbar.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Domain\WettkampftagStatus;
use KSV\KMM\Infrastructure\Repository\BuchungRepository;
use KSV\KMM\Infrastructure\Repository\DurchgangRepository;
use KSV\KMM\Infrastructure\Repository\DurchgangZulassungRepository;
use KSV\KMM\Infrastructure\Repository\WettkampftagRepository;

final class Abmeldung {

	/**
	 * Standardwert des Schalters „Startgeld berechnen" für eine Einzelmeldung:
	 * true, wenn der betreffende Wettkampftag (gebuchter Platz, sonst ein Wettkampftag mit
	 * passender Zulassung) bereits veröffentlicht ist.
	 *
	 * @param array<string, mixed> $em Einzelmeldung
	 */
	public static function startgeld_standard(array $em): bool {
		$tage = new WettkampftagRepository();
		$buchung = (new BuchungRepository())->by_einzelmeldung((int) $em['id']);
		if ($buchung !== null) {
			$tag = $tage->find((int) $buchung["wettkampftag_id"]);
			return $tag !== null && self::veroeffentlicht($tag);
		}
		$disziplin_id = (int) $em['disziplin_id'];
		$startklasse_id = $em['startklasse_id'] !== null ? (int) $em['startklasse_id'] : ($em['mannschaft_klasse_id'] !== null ? (int) $em['mannschaft_klasse_id'] : null);
		$zulassungen = new DurchgangZulassungRepository();
		$durchgaenge = new DurchgangRepository();
		foreach ($tage->by_sportjahr((int) $em['sportjahr_id']) as $tag) {
			if (!self::veroeffentlicht($tag)) {
				continue;
			}
			foreach ($durchgaenge->by_wettkampftag((int) $tag['id']) as $dg) {
				foreach ($zulassungen->by_durchgang((int) $dg['id']) as $z) {
					if ((int) $z['disziplin_id'] === $disziplin_id && ($z['startklasse_id'] === null || (int) $z['startklasse_id'] === $startklasse_id)) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $tag
	 */
	private static function veroeffentlicht(array $tag): bool {
		return (string) $tag['status'] === WettkampftagStatus::VEROEFFENTLICHT && $tag['veroeffentlicht_am'] !== null;
	}
}
