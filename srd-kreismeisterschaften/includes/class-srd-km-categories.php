<?php
/**
 * Disziplin-Kategorien (konfigurierbar, CRUD im Backend).
 *
 * @package SRD_Kreismeisterschaften
 */

if (!defined('ABSPATH')) {
	exit;
}

class SRD_KM_Categories {

	public const OPTION_KEY = 'srd_km_categories';

	/**
	 * Standard-Katalog (führende Ziffern der Datei-ID).
	 *
	 * @return array<int, array{id: int, label: string, prefix: string, active: int, order: int}>
	 */
	public static function default_records(): array {
		$labels = array(
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
		$records = array();
		foreach ($labels as $id => $label) {
			$records[] = array(
				'id'     => $id,
				'label'  => $label,
				'prefix' => (string) $id,
				'active' => 1,
				'order'  => $id,
			);
		}
		return $records;
	}

	public static function maybe_install_defaults(): void {
		$stored = get_option(self::OPTION_KEY, null);
		if ($stored !== null) {
			return;
		}
		update_option(self::OPTION_KEY, self::default_records(), false);
	}

	/**
	 * @return array<int, array{id: int, label: string, prefix: string, active: int, order: int}>
	 */
	public static function get_all(): array {
		self::maybe_install_defaults();
		$stored = get_option(self::OPTION_KEY, array());
		if (!is_array($stored) || $stored === array()) {
			return self::default_records();
		}
		$out = array();
		foreach ($stored as $row) {
			if (!is_array($row)) {
				continue;
			}
			$sanitized = self::sanitize_record($row, isset($row['id']) ? absint($row['id']) : 0);
			if ($sanitized === null) {
				continue;
			}
			$out[ $sanitized['id'] ] = $sanitized;
		}
		if ($out === array()) {
			return self::default_records();
		}
		usort(
			$out,
			static function (array $a, array $b): int {
				$oa = (int) $a['order'];
				$ob = (int) $b['order'];
				if ($oa !== $ob) {
					return $oa <=> $ob;
				}
				return (int) $a['id'] <=> (int) $b['id'];
			}
		);
		return array_values($out);
	}

	/**
	 * @return array<int, string> Kategorie-ID => Bezeichnung (nur aktive, für Frontend-Filter).
	 */
	public static function labels(bool $active_only = true): array {
		$out = array();
		foreach (self::get_all() as $cat) {
			if ($active_only && empty($cat['active'])) {
				continue;
			}
			$out[ (int) $cat['id'] ] = (string) $cat['label'];
		}
		return $out;
	}

	/**
	 * @return array<int, string> alle Kategorien (Backend).
	 */
	public static function all_labels(): array {
		return self::labels(false);
	}

	/**
	 * Kategorie aus Datei-ID (längster passender Präfix).
	 */
	public static function from_datei(string $datei): int {
		$datei = trim($datei);
		if ($datei === '') {
			return 0;
		}
		$cats = self::get_all();
		usort(
			$cats,
			static function (array $a, array $b): int {
				return strlen((string) $b['prefix']) <=> strlen((string) $a['prefix']);
			}
		);
		foreach ($cats as $cat) {
			$prefix = (string) $cat['prefix'];
			if ($prefix !== '' && strpos($datei, $prefix) === 0) {
				return (int) $cat['id'];
			}
		}
		return 0;
	}

	public static function label(int $category_id): string {
		foreach (self::get_all() as $cat) {
			if ((int) $cat['id'] === $category_id) {
				return (string) $cat['label'];
			}
		}
		return '';
	}

	public static function record(int $category_id): ?array {
		foreach (self::get_all() as $cat) {
			if ((int) $cat['id'] === $category_id) {
				return $cat;
			}
		}
		return null;
	}

	public static function is_valid(int $category_id, bool $active_only = true): bool {
		$cat = self::record($category_id);
		if ($cat === null) {
			return false;
		}
		if ($active_only && empty($cat['active'])) {
			return false;
		}
		return true;
	}

	public static function exists(int $category_id): bool {
		return self::record($category_id) !== null;
	}

	/**
	 * CSS-Klasse pro Kategorie (eigene Farbe über km-embed.css, 1–12).
	 */
	public static function color_class(int $category_id): string {
		if ($category_id >= 1 && $category_id <= 12) {
			return 'srd-km-cat srd-km-cat--' . $category_id;
		}
		return 'srd-km-cat srd-km-cat--0';
	}

	/**
	 * @param array<int, array{id: int, label: string, prefix: string, active: int, order: int}> $records
	 */
	public static function save_all(array $records): void {
		$out = array();
		foreach ($records as $row) {
			if (!is_array($row)) {
				continue;
			}
			$id = isset($row['id']) ? absint($row['id']) : 0;
			$sanitized = self::sanitize_record($row, $id);
			if ($sanitized === null) {
				continue;
			}
			$out[ $sanitized['id'] ] = $sanitized;
		}
		update_option(self::OPTION_KEY, array_values($out), false);
	}

	/**
	 * Nächste freie Kategorie-ID (ab 1).
	 */
	public static function suggest_next_id(): int {
		$max = 0;
		foreach (self::get_all() as $cat) {
			$max = max($max, (int) $cat['id']);
		}
		return $max + 1;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{id: int, label: string, prefix: string, active: int, order: int}|null
	 */
	public static function sanitize_record(array $input, int $id = 0): ?array {
		if ($id <= 0 && isset($input['id'])) {
			$id = absint($input['id']);
		}
		if ($id < 1 || $id > 999) {
			return null;
		}
		$label = isset($input['label']) ? sanitize_text_field((string) $input['label']) : '';
		if ($label === '') {
			return null;
		}
		$prefix = isset($input['prefix']) ? preg_replace('/\D/', '', (string) $input['prefix']) : '';
		if ($prefix === null || $prefix === '') {
			return null;
		}
		$order = isset($input['order']) ? (int) $input['order'] : $id;
		$active = empty($input['active']) ? 0 : 1;
		return array(
			'id'     => $id,
			'label'  => $label,
			'prefix' => $prefix,
			'active' => $active,
			'order'  => $order,
		);
	}

	/**
	 * Prüft, ob ein Präfix bereits von einer anderen Kategorie verwendet wird.
	 */
	public static function prefix_in_use(string $prefix, int $exclude_id = 0): bool {
		$prefix = preg_replace('/\D/', '', $prefix);
		if ($prefix === null || $prefix === '') {
			return false;
		}
		foreach (self::get_all() as $cat) {
			if ((int) $cat['id'] === $exclude_id) {
				continue;
			}
			if ((string) $cat['prefix'] === $prefix) {
				return true;
			}
		}
		return false;
	}

}
