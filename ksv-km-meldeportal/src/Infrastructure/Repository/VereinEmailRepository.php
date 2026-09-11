<?php
/**
 * E-Mail-Adressen der Vereine.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Repository;

final class VereinEmailRepository extends Repository {

	protected const TABLE = 'verein_email';
	protected const INT_COLUMNS = ['id', 'sortierung'];

	/**
	 * @return string[]
	 */
	public function adressen(int $verein_id): array {
		return array_map(static fn(array $r): string => (string) $r['email'], $this->where(['verein_id' => $verein_id], 'sortierung ASC, id ASC'));
	}

	/**
	 * @return array<int, string[]> verein_id => Adressen
	 */
	public function alle(): array {
		$out = [];
		foreach ($this->where([], 'verein_id ASC, sortierung ASC, id ASC') as $r) {
			$out[ (int) $r['verein_id'] ][] = (string) $r['email'];
		}
		return $out;
	}

	/**
	 * Vereins-IDs, bei denen die Adresse hinterlegt ist.
	 *
	 * @return int[]
	 */
	public function vereine_mit_adresse(string $email): array {
		return array_map(static fn(array $r): int => (int) $r['verein_id'], $this->where(['email' => $email]));
	}

	/**
	 * @param string[] $adressen
	 */
	public function setzen(int $verein_id, array $adressen): void {
		$this->delete_where(['verein_id' => $verein_id]);
		$sort = 0;
		foreach (array_values(array_unique($adressen)) as $email) {
			$this->insert(['verein_id' => $verein_id, 'email' => $email, 'sortierung' => $sort++]);
		}
	}
}
