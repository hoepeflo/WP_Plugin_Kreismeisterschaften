<?php
/**
 * Auflösung der Tabellennamen mit dem aktuellen WordPress-Präfix.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Database;

final class Tables {

	/**
	 * @param string $table Kurzname, z. B. "klasse".
	 */
	public static function name(string $table): string {
		global $wpdb;
		return Schema::table($table, (string) $wpdb->prefix);
	}
}
