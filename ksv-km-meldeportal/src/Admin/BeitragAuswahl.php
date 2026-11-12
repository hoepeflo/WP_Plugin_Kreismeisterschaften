<?php
/**
 * Auswahl des Beitrags, in dem der Shortcode eines Startplans steht: Kalendertermine,
 * Seiten und Beiträge zum Anklicken statt einer ID zum Abtippen.
 *
 * Kalendertermine kommen aus dem Beitragstyp des Kalender-Plugins (The Events Calendar:
 * tribe_events). Das Portal bleibt davon unabhängig: Gibt es den Typ nicht, bleiben Seiten
 * und Beiträge. Weitere Typen lassen sich über den Filter kmm_beitrag_post_types ergänzen.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Admin;

final class BeitragAuswahl {

	/** Beitragstypen bekannter Kalender-Plugins mit dem Metafeld ihres Beginns. */
	private const KALENDER = [
		'tribe_events' => '_EventStartDate',
	];

	private const STATUS = ['publish', 'future', 'draft', 'private'];

	/**
	 * Wählbare Beiträge in Gruppen, die Termine am Wettkampftag zuerst.
	 *
	 * @param string|null $datum    Datum des Wettkampftags (Y-m-d) oder null
	 * @param int|null    $gewaehlt gespeicherte Beitrags-ID; bleibt wählbar, auch wenn sie in keiner Liste steht
	 * @return array<string, array<int, string>> Gruppenname => [Beitrags-ID => Beschriftung]
	 */
	public static function gruppen(?string $datum, ?int $gewaehlt): array {
		$gruppen = [];
		$gesehen = [];
		foreach (self::typen() as $typ => $meta) {
			$objekt = get_post_type_object($typ);
			if ($objekt === null) {
				continue;
			}
			$name = (string) ($objekt->labels->name ?? $typ);
			if ($meta !== null) {
				// Termine: ab vor zwei Monaten, nach Beginn sortiert; die am Wettkampftag stehen vorn.
				$posts = get_posts([
					'post_type'   => $typ,
					'post_status' => self::STATUS,
					'numberposts' => 300,
					'meta_key'    => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'orderby'     => 'meta_value',
					'order'       => 'ASC',
					'meta_query'  => [['key' => $meta, 'value' => gmdate('Y-m-d 00:00:00', time() - 60 * DAY_IN_SECONDS), 'compare' => '>=', 'type' => 'DATETIME']], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				]);
				$am_tag = [];
				$andere = [];
				foreach ($posts as $p) {
					$beginn = (string) get_post_meta($p->ID, $meta, true);
					$tag = substr($beginn, 0, 10);
					$zeit = \DateTimeImmutable::createFromFormat('!Y-m-d', $tag);
					$text = ($zeit !== false ? $zeit->format('d.m.Y') . ' · ' : '') . self::titel($p);
					if ($datum !== null && $tag === $datum) {
						$am_tag[ $p->ID ] = $text;
					} else {
						$andere[ $p->ID ] = $text;
					}
					$gesehen[ $p->ID ] = true;
				}
				if ($am_tag !== []) {
					$gruppen[ sprintf(/* translators: %s: Name des Beitragstyps, z. B. Veranstaltungen */ __('%s am Wettkampftag', 'ksv-km-meldeportal'), $name) ] = $am_tag;
				}
				if ($andere !== []) {
					$gruppen[ $name ] = $andere;
				}
				continue;
			}
			$seiten = $typ === 'page';
			$posts = get_posts([
				'post_type'   => $typ,
				'post_status' => self::STATUS,
				'numberposts' => $seiten ? 300 : 50,
				'orderby'     => $seiten ? 'title' : 'date',
				'order'       => $seiten ? 'ASC' : 'DESC',
			]);
			$liste = [];
			foreach ($posts as $p) {
				$liste[ $p->ID ] = self::titel($p);
				$gesehen[ $p->ID ] = true;
			}
			if ($liste !== []) {
				$gruppen[ $seiten ? $name : sprintf(/* translators: %s: Name des Beitragstyps */ __('%s (die neuesten)', 'ksv-km-meldeportal'), $name) ] = $liste;
			}
		}
		// Eine früher eingetragene ID bleibt erhalten, auch wenn der Beitrag in keiner Liste steht.
		if ($gewaehlt !== null && $gewaehlt > 0 && !isset($gesehen[ $gewaehlt ])) {
			$p = get_post($gewaehlt);
			$text = $p instanceof \WP_Post ? self::titel($p) : __('nicht mehr vorhanden', 'ksv-km-meldeportal');
			$gruppen = [__('Bisher gewählt', 'ksv-km-meldeportal') => [$gewaehlt => sprintf('%s (ID %d)', $text, $gewaehlt)]] + $gruppen;
		}
		return $gruppen;
	}

	/**
	 * Beitragstypen in der Reihenfolge der Auswahl: Kalender (mit Metafeld des Beginns), Seiten, Beiträge.
	 *
	 * @return array<string, string|null> Typ => Metafeld des Beginns oder null
	 */
	private static function typen(): array {
		$typen = [];
		foreach (self::KALENDER as $typ => $meta) {
			if (post_type_exists($typ)) {
				$typen[ $typ ] = $meta;
			}
		}
		$typen += ['page' => null, 'post' => null];
		/**
		 * Beitragstypen der Auswahl. Schlüssel = Typ, Wert = Metafeld mit dem Beginn eines
		 * Termins (Format Y-m-d …) oder null für gewöhnliche Beiträge.
		 *
		 * @param array<string, string|null> $typen
		 */
		$gefiltert = apply_filters('kmm_beitrag_post_types', $typen);
		return is_array($gefiltert) ? $gefiltert : $typen;
	}

	private static function titel(\WP_Post $p): string {
		$titel = trim(wp_strip_all_tags((string) $p->post_title));
		if ($titel === '') {
			$titel = sprintf(/* translators: %d: Beitrags-ID */ __('(ohne Titel, ID %d)', 'ksv-km-meldeportal'), $p->ID);
		}
		return match ($p->post_status) {
			'draft'   => $titel . ' – ' . __('Entwurf', 'ksv-km-meldeportal'),
			'future'  => $titel . ' – ' . __('geplant', 'ksv-km-meldeportal'),
			'private' => $titel . ' – ' . __('privat', 'ksv-km-meldeportal'),
			default   => $titel,
		};
	}
}
