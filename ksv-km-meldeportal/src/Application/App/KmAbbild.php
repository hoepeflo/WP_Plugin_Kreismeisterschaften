<?php
/**
 * Antwort von GET ksv-app/v1/km („Kreismeisterschaft“, API-Vertrag 3.3).
 *
 * Reines PHP ohne WordPress-Abhängigkeit (unit-testbar): bekommt die Datenbankzeilen
 * (Zeiten in UTC) und liefert das Vertragsformat mit ISO-8601-Zeiten in der Zeitzone
 * der Website. Nur öffentliche Daten, keine Personendaten.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application\App;

use KSV\KMM\Domain\WettkampftagStatus;

final class KmAbbild {

	/**
	 * @param array<string, mixed>|null      $sportjahr    aktives Sportjahr oder null
	 * @param string                         $meldephase   Status aus Meldephase::pruefen()
	 * @param list<array<string, mixed>>     $tage         Wettkampftage des Sportjahres
	 * @param array<int, array<string, mixed>> $staende    Schießstände nach ID (für den Ort)
	 * @param array<int, string|null>        $startplan_urls Wettkampftag-ID => URL des Beitrags
	 * @param array{meldeportal: string, startplaene: string|null} $links
	 * @return array<string, mixed>
	 */
	public static function bauen(?array $sportjahr, string $meldephase, array $tage, array $staende, array $startplan_urls, array $links, \DateTimeZone $tz): array {
		$aenderungen = [];
		$sj = null;
		$liste = [];
		if ($sportjahr !== null) {
			$aenderungen[] = $sportjahr['updated_at'] ?? null;
			$sj = [
				'id'             => (int) $sportjahr['id'],
				'jahr'           => (int) $sportjahr['jahr'],
				'bezeichnung'    => (string) $sportjahr['bezeichnung'],
				'meldung_beginn' => self::iso($sportjahr['meldung_beginn'] ?? null, $tz),
				'meldeschluss'   => self::iso($sportjahr['meldeschluss'] ?? null, $tz),
				'erinnerung_am'  => self::iso($sportjahr['erinnerung_am'] ?? null, $tz),
				'phase'          => self::phase($meldephase),
				'updated_at'     => self::iso($sportjahr['updated_at'] ?? null, $tz),
			];
			foreach ($tage as $tag) {
				if (($tag['ausgeblendet_am'] ?? null) !== null) {
					continue;
				}
				$status = self::status((string) $tag['status']);
				if ($status === null) {
					continue;
				}
				$stand = isset($tag['schiessstand_id']) ? ($staende[ (int) $tag['schiessstand_id'] ] ?? null) : null;
				$aenderungen[] = $tag['updated_at'] ?? null;
				if ($stand !== null) {
					$aenderungen[] = $stand['updated_at'] ?? null;
				}
				$url = $status === 'veroeffentlicht' ? ($startplan_urls[ (int) $tag['id'] ] ?? null) : null;
				$hinweis = trim((string) ($tag['hinweis'] ?? ''));
				$liste[] = [
					'id'            => (int) $tag['id'],
					'datum'         => (string) $tag['datum'],
					'bezeichnung'   => (string) $tag['bezeichnung'],
					'ort'           => self::ort($tag, $stand),
					'status'        => $status,
					'buchungsfrist' => self::iso($tag['buchungsfrist'] ?? null, $tz),
					'hinweis'       => $hinweis,
					'startplan_url' => $url !== null && $url !== '' ? $url : null,
					'updated_at'    => self::iso($tag['updated_at'] ?? null, $tz),
				];
			}
		}
		return [
			'sportjahr'     => $sj,
			'wettkampftage' => $liste,
			'links'         => $links,
			'updated_at'    => self::iso(self::juengste($aenderungen), $tz),
		];
	}

	/** Meldephase → Vertragswert vor_beginn | offen | geschlossen. */
	public static function phase(string $meldephase): string {
		return match ($meldephase) {
			'vor_beginn' => 'vor_beginn',
			'offen', 'nachmeldung' => 'offen',
			default => 'geschlossen', // geschlossen, abgeschlossen
		};
	}

	/** Status des Wettkampftags → Vertragswert (null = unbekannt, wird ausgelassen). */
	public static function status(string $status): ?string {
		return match ($status) {
			WettkampftagStatus::ENTWURF => 'geplant',
			WettkampftagStatus::FREIGEGEBEN => 'buchbar',
			WettkampftagStatus::VEROEFFENTLICHT => 'veroeffentlicht',
			default => null,
		};
	}

	/**
	 * Ort: das Feld am Wettkampftag, sonst Bezeichnung und Ort des Schießstands.
	 *
	 * @param array<string, mixed>      $tag
	 * @param array<string, mixed>|null $stand
	 */
	public static function ort(array $tag, ?array $stand): string {
		$ort = trim((string) ($tag['ort'] ?? ''));
		if ($ort !== '' || $stand === null) {
			return $ort;
		}
		$teile = array_filter([trim((string) ($stand['bezeichnung'] ?? '')), trim((string) ($stand['ort'] ?? ''))], static fn(string $s): bool => $s !== '');
		return implode(', ', $teile);
	}

	/** UTC-Datenbankwert → ISO 8601 mit Zeitzonen-Versatz der Website (null bei leer). */
	public static function iso(mixed $utc, \DateTimeZone $tz): ?string {
		if (!is_string($utc) || $utc === '' || $utc === '0000-00-00 00:00:00') {
			return null;
		}
		$dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new \DateTimeZone('UTC'));
		return $dt === false ? null : $dt->setTimezone($tz)->format('Y-m-d\TH:i:sP');
	}

	/**
	 * @param array<int, mixed> $werte
	 */
	private static function juengste(array $werte): ?string {
		$max = null;
		foreach ($werte as $w) {
			if (is_string($w) && $w !== '' && ($max === null || $w > $max)) {
				$max = $w;
			}
		}
		return $max;
	}

	/**
	 * ETag über den JSON-Body (Vertrag 1.1).
	 *
	 * @param array<string, mixed> $daten
	 */
	public static function etag(array $daten): string {
		return '"' . md5((string) json_encode($daten, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '"';
	}

	/** Passt If-None-Match zum ETag? (Liste, schwache ETags und * werden berücksichtigt.) */
	public static function etag_passt(string $if_none_match, string $etag): bool {
		$if_none_match = trim($if_none_match);
		if ($if_none_match === '') {
			return false;
		}
		if ($if_none_match === '*') {
			return true;
		}
		foreach (explode(',', $if_none_match) as $kandidat) {
			$kandidat = trim($kandidat);
			if (str_starts_with($kandidat, 'W/')) {
				$kandidat = substr($kandidat, 2);
			}
			// Manche Server hängen beim Komprimieren „-gzip“ an das ETag.
			$kandidat = (string) preg_replace('/-gzip"$/', '"', $kandidat);
			if ($kandidat === $etag) {
				return true;
			}
		}
		return false;
	}
}
