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

	/** @var array<string, array<string, string>>|null */
	private static $kreis_v3_columns = null;

	/**
	 * Spaltenmetadaten von srd_kreis_v3 (SHOW COLUMNS).
	 *
	 * @return array<string, array<string, string>> Feldname => Spalteninfo
	 */
	public static function kreis_v3_column_meta(): array {
		if (self::$kreis_v3_columns !== null) {
			return self::$kreis_v3_columns;
		}
		self::$kreis_v3_columns = array();
		$con = self::connection();
		if (!$con) {
			return self::$kreis_v3_columns;
		}
		$res = @mysqli_query($con, 'SHOW COLUMNS FROM `srd_kreis_v3`');
		if ($res) {
			while ($row = mysqli_fetch_assoc($res)) {
				$field = (string) ($row['Field'] ?? '');
				if ($field !== '') {
					self::$kreis_v3_columns[ $field ] = $row;
				}
			}
		}
		mysqli_close($con);
		return self::$kreis_v3_columns;
	}

	public static function kreis_v3_primary_key(): ?string {
		foreach (self::kreis_v3_column_meta() as $field => $meta) {
			if (($meta['Key'] ?? '') === 'PRI') {
				return $field;
			}
		}
		return null;
	}

	/**
	 * Für Admin-Formulare bearbeitbare Felder (Schnittmenge mit Tabellenspalten).
	 *
	 * @return string[]
	 */
	public static function kreis_v3_editable_fields(): array {
		$wanted = array('disziplin', 'altersklasse', 'spo', 'datei', 'sportjahr');
		$cols = self::kreis_v3_column_meta();
		if ($cols === array()) {
			return $wanted;
		}
		$pk = self::kreis_v3_primary_key();
		$out = array();
		foreach ($wanted as $field) {
			if (isset($cols[ $field ]) && $field !== $pk) {
				$out[] = $field;
			}
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function kreis_row_by_id(int $id): ?array {
		$pk = self::kreis_v3_primary_key();
		if ($pk === null || $id <= 0) {
			return null;
		}
		$con = self::connection();
		if (!$con) {
			return null;
		}
		$sql = 'SELECT * FROM `srd_kreis_v3` WHERE `' . self::escape_identifier($pk) . '` = ? LIMIT 1';
		$stmt = mysqli_prepare($con, $sql);
		if (!$stmt) {
			mysqli_close($con);
			return null;
		}
		mysqli_stmt_bind_param($stmt, 'i', $id);
		mysqli_stmt_execute($stmt);
		$result = mysqli_stmt_get_result($stmt);
		$row = ($result && ($data = mysqli_fetch_assoc($result))) ? $data : null;
		mysqli_stmt_close($stmt);
		mysqli_close($con);
		return is_array($row) ? $row : null;
	}

	/**
	 * @param array<string, string> $data
	 * @return int|false Neue ID bei Erfolg, true wenn ohne Auto-Increment-PK, false bei Fehler
	 */
	public static function insert_kreis_row(array $data) {
		$fields = self::filter_row_data($data);
		if ($fields === array()) {
			return false;
		}
		$con = self::connection();
		if (!$con) {
			return false;
		}
		$cols = array();
		$placeholders = array();
		$values = array();
		$types = '';
		foreach ($fields as $col => $val) {
			$cols[] = '`' . self::escape_identifier($col) . '`';
			$placeholders[] = '?';
			$values[] = $val;
			$types .= 's';
		}
		$sql = 'INSERT INTO `srd_kreis_v3` (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')';
		$stmt = mysqli_prepare($con, $sql);
		if (!$stmt) {
			mysqli_close($con);
			return false;
		}
		mysqli_stmt_bind_param($stmt, $types, ...$values);
		$ok = mysqli_stmt_execute($stmt);
		$new_id = $ok ? (int) mysqli_insert_id($con) : 0;
		mysqli_stmt_close($stmt);
		mysqli_close($con);
		if (!$ok) {
			return false;
		}
		return $new_id > 0 ? $new_id : true;
	}

	/**
	 * @param array<string, string> $data
	 */
	public static function update_kreis_row(int $id, array $data): bool {
		$pk = self::kreis_v3_primary_key();
		if ($pk === null || $id <= 0) {
			return false;
		}
		$fields = self::filter_row_data($data);
		if ($fields === array()) {
			return false;
		}
		$con = self::connection();
		if (!$con) {
			return false;
		}
		$sets = array();
		$values = array();
		$types = '';
		foreach ($fields as $col => $val) {
			$sets[] = '`' . self::escape_identifier($col) . '` = ?';
			$values[] = $val;
			$types .= 's';
		}
		$values[] = (string) $id;
		$types .= 'i';
		$sql = 'UPDATE `srd_kreis_v3` SET ' . implode(', ', $sets) . ' WHERE `' . self::escape_identifier($pk) . '` = ?';
		$stmt = mysqli_prepare($con, $sql);
		if (!$stmt) {
			mysqli_close($con);
			return false;
		}
		mysqli_stmt_bind_param($stmt, $types, ...$values);
		$ok = (bool) mysqli_stmt_execute($stmt);
		mysqli_stmt_close($stmt);
		mysqli_close($con);
		return $ok;
	}

	public static function delete_kreis_row(int $id): bool {
		$pk = self::kreis_v3_primary_key();
		if ($pk === null || $id <= 0) {
			return false;
		}
		$con = self::connection();
		if (!$con) {
			return false;
		}
		$sql = 'DELETE FROM `srd_kreis_v3` WHERE `' . self::escape_identifier($pk) . '` = ? LIMIT 1';
		$stmt = mysqli_prepare($con, $sql);
		if (!$stmt) {
			mysqli_close($con);
			return false;
		}
		mysqli_stmt_bind_param($stmt, 'i', $id);
		$ok = (bool) mysqli_stmt_execute($stmt);
		$affected = mysqli_stmt_affected_rows($stmt);
		mysqli_stmt_close($stmt);
		mysqli_close($con);
		return $ok && $affected > 0;
	}

	public static function table_available(): bool {
		return self::kreis_v3_column_meta() !== array() && self::kreis_v3_primary_key() !== null;
	}

	/**
	 * @param array<string, string> $data
	 * @return array<string, string>
	 */
	private static function filter_row_data(array $data): array {
		$allowed = array_flip(self::kreis_v3_editable_fields());
		$out = array();
		foreach ($data as $key => $value) {
			if (isset($allowed[ $key ])) {
				$out[ $key ] = (string) $value;
			}
		}
		return $out;
	}

	private static function escape_identifier(string $name): string {
		return str_replace('`', '', $name);
	}
}
