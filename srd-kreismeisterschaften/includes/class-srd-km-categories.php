<?php
/**
 * Disziplin-Kategorien nach führender Ziffer der Datei-ID.
 *
 * @package SRD_Kreismeisterschaften
 */

if (!defined('ABSPATH')) {
	exit;
}

class SRD_KM_Categories {

	/**
	 * @return array<int, string> Kategorie-ID => Bezeichnung
	 */
	public static function labels(): array {
		return array(
			1  => __('Gewehr', 'srd-kreismeisterschaften'),
			2  => __('Pistole und Revolver', 'srd-kreismeisterschaften'),
			3  => __('Flinte', 'srd-kreismeisterschaften'),
			4  => __('Laufende Scheibe', 'srd-kreismeisterschaften'),
			5  => __('Armbrust', 'srd-kreismeisterschaften'),
			6  => __('Bogen', 'srd-kreismeisterschaften'),
			7  => __('Vorderlader', 'srd-kreismeisterschaften'),
			8  => __('Targetsprint und Sommerbiathlon', 'srd-kreismeisterschaften'),
			9  => __('Auflageschießen', 'srd-kreismeisterschaften'),
			10 => __('Parasport', 'srd-kreismeisterschaften'),
			11 => __('Lichtschießen', 'srd-kreismeisterschaften'),
			12 => __('Blasrohr', 'srd-kreismeisterschaften'),
		);
	}

	/**
	 * Kategorie aus Datei-ID (längste führende Zifferngruppe 1–12).
	 */
	public static function from_datei(string $datei): int {
		if (preg_match('/^(12|11|10|[1-9])/', $datei, $m)) {
			return (int) $m[1];
		}
		return 0;
	}

	public static function label(int $category_id): string {
		$labels = self::labels();
		return $labels[ $category_id ] ?? '';
	}

	public static function is_valid(int $category_id): bool {
		return isset(self::labels()[ $category_id ]);
	}

	/**
	 * CSS-Klasse pro Kategorie (eigene Farbe über km-embed.css).
	 */
	public static function color_class(int $category_id): string {
		if ($category_id >= 1 && $category_id <= 12) {
			return 'srd-km-cat srd-km-cat--' . $category_id;
		}
		return 'srd-km-cat srd-km-cat--0';
	}

}
