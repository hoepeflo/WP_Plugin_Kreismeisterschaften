<?php
/**
 * Rechteprüfung für Backend-Aktionen: Admin (kmm_manage) darf alles; Referenten
 * (Rolle kmm_referent) sehen nur ihren Zuständigkeitsbereich (Wettbewerbsgruppen oder
 * Disziplinen) und haben einzeln freigeschaltete Rechte. Jede Aktion prüft
 * serverseitig gegen diese Klasse, nicht nur die Navigation.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Auth;

use KSV\KMM\Domain\Engine\Disziplin;
use KSV\KMM\Infrastructure\Repository\ReferentRepository;
use KSV\KMM\Infrastructure\Repository\ReferentZustaendigkeitRepository;

final class Rechte {

	public const RECHT_STATUS    = 'darf_status';
	public const RECHT_MELDUNGEN = 'darf_meldungen';
	public const RECHT_STARTPLAN = 'darf_startplan';

	/** @var array<int, array<string, mixed>|null> */
	private static array $cache = [];

	public static function cache_leeren(): void {
		self::$cache = [];
	}

	public static function ist_admin(?int $user_id = null): bool {
		return $user_id === null ? current_user_can(Capabilities::MANAGE) : user_can($user_id, Capabilities::MANAGE);
	}

	/**
	 * Referenten-Datensatz des Benutzers (mit Zuständigkeiten) oder null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function referent(?int $user_id = null): ?array {
		$user_id ??= get_current_user_id();
		if ($user_id <= 0) {
			return null;
		}
		if (array_key_exists($user_id, self::$cache)) {
			return self::$cache[ $user_id ];
		}
		$r = (new ReferentRepository())->by_user($user_id);
		if ($r !== null) {
			$r['zustaendigkeiten'] = (new ReferentZustaendigkeitRepository())->by_referent((int) $r['id']);
		}
		self::$cache[ $user_id ] = $r;
		return $r;
	}

	/** Darf der Benutzer das Backend des Meldeportals überhaupt lesen? */
	public static function darf_lesen(?int $user_id = null): bool {
		if (self::ist_admin($user_id)) {
			return true;
		}
		$cap = $user_id === null ? current_user_can(Capabilities::VIEW) : user_can($user_id, Capabilities::VIEW);
		return $cap && self::referent($user_id) !== null;
	}

	/** Einzelrecht eines Referenten (Admin immer true). */
	public static function hat_recht(string $recht, ?int $user_id = null): bool {
		if (self::ist_admin($user_id)) {
			return true;
		}
		$r = self::referent($user_id);
		return $r !== null && !empty($r[ $recht ]);
	}

	/**
	 * Ist der Benutzer für eine Disziplin zuständig? Admin: immer. Referent: wenn die
	 * Wettbewerbsgruppe (Code) oder die Disziplin (Kennzahl) zugewiesen ist.
	 */
	public static function zustaendig(Disziplin $d, ?int $user_id = null): bool {
		if (self::ist_admin($user_id)) {
			return true;
		}
		$r = self::referent($user_id);
		if ($r === null) {
			return false;
		}
		foreach ($r['zustaendigkeiten'] as $z) {
			if ($z['typ'] === ReferentZustaendigkeitRepository::TYP_GRUPPE && $z['schluessel'] === $d->gruppe) {
				return true;
			}
			if ($z['typ'] === ReferentZustaendigkeitRepository::TYP_DISZIPLIN && $z['schluessel'] === $d->kennzahl) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Filtert Disziplinen auf den Zuständigkeitsbereich.
	 *
	 * @param list<Disziplin> $disziplinen
	 * @return list<Disziplin>
	 */
	public static function zustaendige(array $disziplinen, ?int $user_id = null): array {
		if (self::ist_admin($user_id)) {
			return $disziplinen;
		}
		return array_values(array_filter($disziplinen, static fn(Disziplin $d): bool => self::zustaendig($d, $user_id)));
	}

	/** Wirft, wenn kein Admin (für Admin-only-Seiten und -Endpunkte). */
	public static function nur_admin(): void {
		if (!self::ist_admin()) {
			wp_die(esc_html__('Keine Berechtigung (nur Administratoren).', 'ksv-km-meldeportal'), '', ['response' => 403]);
		}
	}
}
