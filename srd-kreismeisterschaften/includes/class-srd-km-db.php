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
		$con = @mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
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
	 * Disziplinnummer aus einer Tabellenzeile (Spaltenname case-insensitive).
	 */
	public static function row_disziplin(array $row): string {
		if (isset($row['disziplin'])) {
			return trim((string) $row['disziplin']);
		}
		foreach ($row as $key => $value) {
			if (is_string($key) && strcasecmp($key, 'disziplin') === 0) {
				return trim((string) $value);
			}
		}
		return '';
	}

	/**
	 * Numerische Segmente der Disziplinnummer (z. B. 1.02.03 → [1, 2, 3]).
	 * Trennzeichen . , - / und Leerzeichen werden ignoriert.
	 *
	 * @return int[]
	 */
	public static function disziplin_sort_segments(string $disziplin): array {
		$disziplin = trim($disziplin);
		if ($disziplin === '') {
			return array();
		}
		if (!preg_match_all('/\d+/', $disziplin, $matches)) {
			return array();
		}
		return array_map('intval', $matches[0]);
	}

	/**
	 * @param int[] $a
	 * @param int[] $b
	 */
	public static function compare_disziplin_segments(array $a, array $b): int {
		$len = max(count($a), count($b));
		for ($i = 0; $i < $len; $i++) {
			$va = $a[ $i ] ?? 0;
			$vb = $b[ $i ] ?? 0;
			if ($va !== $vb) {
				return $va <=> $vb;
			}
		}
		return 0;
	}

	/**
	 * Vergleich zweier Disziplinnummern (numerisch segmentweise, sonst strnatcmp).
	 */
	public static function compare_disziplin_strings(string $a, string $b): int {
		$seg_a = self::disziplin_sort_segments($a);
		$seg_b = self::disziplin_sort_segments($b);
		if ($seg_a !== array() || $seg_b !== array()) {
			$cmp = self::compare_disziplin_segments($seg_a, $seg_b);
			if ($cmp !== 0) {
				return $cmp;
			}
		}
		return strnatcmp($a, $b);
	}

	/**
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 */
	public static function compare_kreis_rows_by_disziplin(array $a, array $b): int {
		return self::compare_disziplin_strings(self::row_disziplin($a), self::row_disziplin($b));
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private static function sort_kreis_rows_by_disziplin(array &$rows): void {
		usort($rows, array(__CLASS__, 'compare_kreis_rows_by_disziplin'));
	}

	/**
	 * Disziplinzeilen aus srd_kreis_v3 (numerisch nach Disziplinnummer).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function kreis_rows_v3(): array {
		$con = self::connection();
		if (!$con) {
			return array();
		}
		$rows = array();
		$res = @mysqli_query($con, 'SELECT * FROM `srd_kreis_v3`');
		if ($res) {
			while ($dsatz = mysqli_fetch_assoc($res)) {
				$rows[] = $dsatz;
			}
		}
		mysqli_close($con);
		self::sort_kreis_rows_by_disziplin($rows);
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
