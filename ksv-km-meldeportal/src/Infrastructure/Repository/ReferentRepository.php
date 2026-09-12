<?php
/**
 * Referenten (WordPress-Benutzer mit Zuständigkeiten und Einzelrechten).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class ReferentRepository extends Repository {

	protected const TABLE = 'referent';
	protected const BOOL_COLUMNS = ['darf_status', 'darf_meldungen', 'darf_startplan'];

	/**
	 * @return array<string, mixed>|null
	 */
	public function by_user(int $user_id): ?array {
		return $this->first(['user_id' => $user_id]);
	}
}
