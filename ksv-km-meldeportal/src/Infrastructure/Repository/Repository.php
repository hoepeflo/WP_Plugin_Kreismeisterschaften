<?php
/**
 * Basis für Tabellenzugriffe über $wpdb (prepare, insert, update, delete).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

use KSV\KMM\Infrastructure\Database\Tables;
use KSV\KMM\Support\Clock;

abstract class Repository {

	/** Kurzname der Tabelle (ohne Präfix). */
	protected const TABLE = '';

	/** Spalten, die als int gelesen werden. */
	protected const INT_COLUMNS = ['id'];

	/** Spalten, die als float gelesen werden. */
	protected const FLOAT_COLUMNS = [];

	/** Spalten, die als bool gelesen werden. */
	protected const BOOL_COLUMNS = [];

	/** Spalten, die NULL enthalten dürfen (sonst wird '' zu NULL nicht umgesetzt). */
	protected const NULLABLE_COLUMNS = [];

	protected \wpdb $db;

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
	}

	public function table(): string {
		return Tables::name(static::TABLE);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find(int $id): ?array {
		if ($id <= 0) {
			return null;
		}
		$row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array($row) ? $this->cast($row) : null;
	}

	/**
	 * @param array<string, mixed> $where Spalte => Wert (AND-verknüpft).
	 * @return array<int, array<string, mixed>>
	 */
	public function where(array $where, string $order_by = 'id ASC'): array {
		[$sql, $args] = $this->where_sql($where);
		$order_by = preg_replace('/[^a-zA-Z0-9_, ]/', '', $order_by) ?? 'id ASC';
		$query = "SELECT * FROM {$this->table()}{$sql} ORDER BY {$order_by}";
		$rows = $args === [] ? $this->db->get_results($query, ARRAY_A) : $this->db->get_results($this->db->prepare($query, ...$args), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map([$this, 'cast'], is_array($rows) ? $rows : []);
	}

	/**
	 * @param array<string, mixed> $where
	 * @return array<string, mixed>|null
	 */
	public function first(array $where): ?array {
		$rows = $this->where($where);
		return $rows[0] ?? null;
	}

	/**
	 * @param array<string, mixed> $where
	 */
	public function count(array $where = []): int {
		[$sql, $args] = $this->where_sql($where);
		$query = "SELECT COUNT(*) FROM {$this->table()}{$sql}";
		$n = $args === [] ? $this->db->get_var($query) : $this->db->get_var($this->db->prepare($query, ...$args)); // phpcs:ignore WordPress.DB.PreparedSQL
		return (int) $n;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return int Neue ID (0 bei Fehler).
	 */
	public function insert(array $data): int {
		$data = $this->prepare_data($data);
		if (in_array('created_at', $this->columns(), true) && !isset($data['created_at'])) {
			$data['created_at'] = Clock::now_utc();
		}
		if (in_array('updated_at', $this->columns(), true) && !isset($data['updated_at'])) {
			$data['updated_at'] = Clock::now_utc();
		}
		$ok = $this->db->insert($this->table(), $data, $this->formats($data));
		return $ok ? (int) $this->db->insert_id : 0;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update(int $id, array $data): bool {
		$data = $this->prepare_data($data);
		if (in_array('updated_at', $this->columns(), true)) {
			$data['updated_at'] = Clock::now_utc();
		}
		$result = $this->db->update($this->table(), $data, ['id' => $id], $this->formats($data), ['%d']);
		return $result !== false;
	}

	public function delete(int $id): bool {
		return (bool) $this->db->delete($this->table(), ['id' => $id], ['%d']);
	}

	/**
	 * @param array<string, mixed> $where
	 */
	public function delete_where(array $where): int {
		if ($where === []) {
			return 0;
		}
		[$sql, $args] = $this->where_sql($where);
		$result = $this->db->query($this->db->prepare("DELETE FROM {$this->table()}{$sql}", ...$args)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_int($result) ? $result : 0;
	}

	public function last_error(): string {
		return (string) $this->db->last_error;
	}

	/**
	 * Spaltennamen der Tabelle (gecacht pro Request).
	 *
	 * @return string[]
	 */
	protected function columns(): array {
		static $cache = [];
		$table = $this->table();
		if (!isset($cache[ $table ])) {
			$cols = $this->db->get_col("SHOW COLUMNS FROM {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$cache[ $table ] = is_array($cols) ? array_map('strval', $cols) : [];
		}
		return $cache[ $table ];
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	protected function cast(array $row): array {
		foreach ($row as $col => $val) {
			if ($val === null) {
				continue;
			}
			if (in_array($col, static::INT_COLUMNS, true) || (str_ends_with((string) $col, '_id') && is_numeric($val))) {
				$row[ $col ] = (int) $val;
			} elseif (in_array($col, static::FLOAT_COLUMNS, true)) {
				$row[ $col ] = (float) $val;
			} elseif (in_array($col, static::BOOL_COLUMNS, true)) {
				$row[ $col ] = (bool) (int) $val;
			}
		}
		return $row;
	}

	/**
	 * Nur bekannte Spalten, bool → int, '' bei nullable → NULL.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	protected function prepare_data(array $data): array {
		$out = [];
		$columns = $this->columns();
		foreach ($data as $col => $val) {
			if (!in_array($col, $columns, true) || $col === 'id') {
				continue;
			}
			if (is_bool($val)) {
				$val = $val ? 1 : 0;
			}
			if (($val === '' || $val === null) && in_array($col, static::NULLABLE_COLUMNS, true)) {
				$val = null;
			}
			$out[ $col ] = $val;
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<int, string|null>
	 */
	private function formats(array $data): array {
		$formats = [];
		foreach ($data as $val) {
			if ($val === null) {
				$formats[] = null; // $wpdb setzt NULL bei null-Format-Eintrag nicht; Behandlung unten.
			} elseif (is_int($val)) {
				$formats[] = '%d';
			} elseif (is_float($val)) {
				$formats[] = '%f';
			} else {
				$formats[] = '%s';
			}
		}
		// $wpdb->insert/update behandeln null-Werte korrekt (setzen NULL), das Format wird ignoriert.
		return array_map(static fn($f) => $f ?? '%s', $formats);
	}

	/**
	 * @param array<string, mixed> $where
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function where_sql(array $where): array {
		if ($where === []) {
			return ['', []];
		}
		$parts = [];
		$args = [];
		foreach ($where as $col => $val) {
			$col = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $col);
			if ($val === null) {
				$parts[] = "{$col} IS NULL";
			} elseif (is_array($val)) {
				if ($val === []) {
					$parts[] = '1=0';
					continue;
				}
				$placeholders = implode(',', array_fill(0, count($val), is_int(reset($val)) ? '%d' : '%s'));
				$parts[] = "{$col} IN ({$placeholders})";
				foreach ($val as $v) {
					$args[] = $v;
				}
			} else {
				$parts[] = "{$col} = " . (is_int($val) ? '%d' : '%s');
				$args[] = $val;
			}
		}
		return [' WHERE ' . implode(' AND ', $parts), $args];
	}
}
