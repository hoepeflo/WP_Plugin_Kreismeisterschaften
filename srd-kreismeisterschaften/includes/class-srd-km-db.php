<?php
/**
 * Datenbankzugriff (MySQLi), kompatibel mit SRD-Tabellen ohne wp_-Präfix.
 *
 * @package SRD_Kreismeisterschaften
 */

if (!defined('ABSPATH')) {
	exit;
}

class SRD_KM_DB {

	/**
	 * @return mysqli|null
	 */
	public static function connection() {
		$settings = srd_km_get_settings();
		if (!empty($settings['db_use_wp'])) {
			$host = DB_HOST;
			$user = DB_USER;
			$pass = DB_PASSWORD;
			$name = DB_NAME;
		} else {
			$host = $settings['db_host'] ?: 'localhost';
			$user = $settings['db_user'] ?? '';
			$pass = $settings['db_pass'] ?? '';
			$name = $settings['db_name'] ?? '';
		}
		if ($name === '') {
			return null;
		}
		$con = @mysqli_connect($host, $user, $pass, $name);
		if (!$con) {
			return null;
		}
		$con->set_charset('utf8');
		return $con;
	}

	/**
	 * Jahresliste aus srd_kreis_v2 / srd_kreis_v3 (DISTINCT sportjahr).
	 *
	 * @return int[] absteigend sortiert
	 */
	public static function distinct_sportjahre(): array {
		$con = self::connection();
		if (!$con) {
			return array();
		}
		$years = array();
		$tables = array('srd_kreis_v3', 'srd_kreis_v2');
		foreach ($tables as $table) {
			$table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
			if ($table === '') {
				continue;
			}
			$sql = "SELECT DISTINCT sportjahr FROM `{$table}` ORDER BY sportjahr DESC";
			$result = mysqli_query($con, $sql);
			if ($result) {
				while ($row = mysqli_fetch_assoc($result)) {
					$y = (int) $row['sportjahr'];
					if ($y > 0) {
						$years[$y] = $y;
					}
				}
			}
		}
		mysqli_close($con);
		$list = array_values($years);
		rsort($list, SORT_NUMERIC);
		return $list;
	}

	/**
	 * Disziplinzeilen aus srd_kreis_v3 (ORDER BY spo).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function kreis_rows_v3(): array {
		$con = self::connection();
		if (!$con) {
			return array();
		}
		$rows = array();
		$res = @mysqli_query($con, 'SELECT * FROM `srd_kreis_v3` ORDER BY spo');
		if ($res) {
			while ($dsatz = mysqli_fetch_assoc($res)) {
				$rows[] = $dsatz;
			}
		}
		mysqli_close($con);
		return $rows;
	}
}
