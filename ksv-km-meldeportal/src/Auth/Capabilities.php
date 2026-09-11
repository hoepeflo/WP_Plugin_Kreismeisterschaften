<?php
/**
 * Capabilities des Meldeportals.
 *
 * kmm_manage: volle Verwaltung (Admin). kmm_view: lesender Zugriff (Phase 2: Referenten).
 * Administratoren erhalten beide Capabilities bei der Aktivierung; zusätzlich greift
 * ein Filter, der manage_options immer als kmm_manage gelten lässt.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Auth;

final class Capabilities {

	public const MANAGE = 'kmm_manage';
	public const VIEW   = 'kmm_view';

	public static function register(): void {
		add_filter('user_has_cap', [self::class, 'grant_to_admins'], 10, 3);
	}

	/**
	 * @param array<string, bool> $allcaps
	 * @param array<int, string>  $caps
	 * @param array<int, mixed>   $args
	 * @return array<string, bool>
	 */
	public static function grant_to_admins(array $allcaps, array $caps, array $args): array {
		if (empty($allcaps['manage_options'])) {
			return $allcaps;
		}
		foreach ($caps as $cap) {
			if ($cap === self::MANAGE || $cap === self::VIEW) {
				$allcaps[ $cap ] = true;
			}
		}
		return $allcaps;
	}

	/** Bei Aktivierung: Capabilities fest an die Administrator-Rolle hängen. */
	public static function add_to_roles(): void {
		$role = get_role('administrator');
		if ($role instanceof \WP_Role) {
			$role->add_cap(self::MANAGE);
			$role->add_cap(self::VIEW);
		}
	}

	public static function remove_from_roles(): void {
		foreach (['administrator', 'kmm_referent'] as $name) {
			$role = get_role($name);
			if ($role instanceof \WP_Role) {
				$role->remove_cap(self::MANAGE);
				$role->remove_cap(self::VIEW);
			}
		}
	}
}
