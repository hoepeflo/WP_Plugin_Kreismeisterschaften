<?php
/**
 * Zugriffsrechte: Administratoren und freigeschaltete Benutzer.
 *
 * @package SRD_Kreismeisterschaften
 */

if (!defined('ABSPATH')) {
	exit;
}

class SRD_KM_Capabilities {

	public const CAP_MANAGE = 'srd_km_manage';

	private static ?self $instance = null;

	public static function init(): void {
		self::instance();
	}

	public static function instance(): self {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter('user_has_cap', array($this, 'grant_manage_cap'), 10, 4);
	}

	/**
	 * @param array<string, bool> $allcaps
	 * @param array<int, string>  $caps
	 * @param array<int, mixed>   $args
	 * @param WP_User             $user
	 * @return array<string, bool>
	 */
	public function grant_manage_cap(array $allcaps, array $caps, array $args, WP_User $user): array {
		if (!in_array(self::CAP_MANAGE, $caps, true)) {
			return $allcaps;
		}
		if (!empty($allcaps['manage_options'])) {
			$allcaps[ self::CAP_MANAGE ] = true;
			return $allcaps;
		}
		$allowed = self::allowed_user_ids();
		if (in_array((int) $user->ID, $allowed, true)) {
			$allcaps[ self::CAP_MANAGE ] = true;
		}
		return $allcaps;
	}

	/**
	 * @return array<int, int>
	 */
	public static function allowed_user_ids(): array {
		$s = srd_km_get_settings();
		$raw = isset($s['allowed_user_ids']) && is_array($s['allowed_user_ids']) ? $s['allowed_user_ids'] : array();
		$ids = array();
		foreach ($raw as $id) {
			$id = absint($id);
			if ($id > 0) {
				$ids[] = $id;
			}
		}
		return array_values(array_unique($ids));
	}

	public static function user_can_manage(?int $user_id = null): bool {
		if ($user_id === null) {
			return current_user_can(self::CAP_MANAGE);
		}
		return user_can($user_id, self::CAP_MANAGE);
	}

	public static function user_can_configure_plugin(?int $user_id = null): bool {
		if ($user_id === null) {
			return current_user_can('manage_options');
		}
		return user_can($user_id, 'manage_options');
	}

	public static function admin_page_url(string $page = 'srd-kreismeisterschaften'): string {
		return admin_url('admin.php?page=' . $page);
	}
}
