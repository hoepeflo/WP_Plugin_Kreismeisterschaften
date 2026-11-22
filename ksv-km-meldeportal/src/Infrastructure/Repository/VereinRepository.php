<?php
/**
 * Vereine.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class VereinRepository extends Repository {

	protected const TABLE = 'verein';
	protected const BOOL_COLUMNS = ['ist_aktiv'];
	protected const NULLABLE_COLUMNS = ['notiz'];

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function all(bool $nur_aktive = false): array {
		return $this->where($nur_aktive ? ['ist_aktiv' => 1] : [], 'name ASC');
	}

	/**
	 * Alle Vereine aufsteigend nach Vereinsnummer (für die Backend-Seite „Vereine“).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all_nach_vn(bool $nur_aktive = false): array {
		return self::nach_vn($this->all($nur_aktive));
	}

	/**
	 * Sortiert Vereine aufsteigend nach Vereinsnummer, numerisch (VN ist varchar);
	 * bei gleicher Nummer nach Name.
	 *
	 * @param array<int, array<string, mixed>> $vereine
	 * @return array<int, array<string, mixed>>
	 */
	public static function nach_vn(array $vereine): array {
		usort($vereine, static fn(array $a, array $b): int => strnatcmp((string) $a['vn_nummer'], (string) $b['vn_nummer'])
			?: strcasecmp((string) $a['name'], (string) $b['name']));
		return $vereine;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function by_vn_nummer(string $vn): ?array {
		return $this->first(['vn_nummer' => $vn]);
	}
}
