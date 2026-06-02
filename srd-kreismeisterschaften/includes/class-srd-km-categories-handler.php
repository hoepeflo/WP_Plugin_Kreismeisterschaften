<?php
/**
 * admin_post-Handler für Kategorien-CRUD.
 *
 * @package SRD_Kreismeisterschaften
 */

if (!defined('ABSPATH')) {
	exit;
}

class SRD_KM_Categories_Handler {

	public static function init(): void {
		add_action('admin_post_srd_km_save_category', array(__CLASS__, 'handle_save'));
		add_action('admin_post_srd_km_delete_category', array(__CLASS__, 'handle_delete'));
	}

	private static function list_url(): string {
		return SRD_KM_Capabilities::admin_page_url('srd-kreismeisterschaften-categories');
	}

	private static function redirect_with(string $status, string $code): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'srd_km_cat'   => $status,
					'srd_km_cat_c' => $code,
				),
				self::list_url()
			)
		);
		exit;
	}

	public static function handle_save(): void {
		if (!SRD_KM_Capabilities::user_can_manage()) {
			wp_die(esc_html__('Keine Berechtigung.', 'srd-kreismeisterschaften'));
		}
		check_admin_referer('srd_km_save_category', 'srd_km_category_nonce');

		if (!isset($_POST['srd_km_category']) || !is_array($_POST['srd_km_category'])) {
			self::redirect_with('err', 'invalid_data');
		}

		$raw = wp_unslash($_POST['srd_km_category']);
		$is_new = !empty($_POST['srd_km_category_is_new']);
		$id = isset($raw['id']) ? absint($raw['id']) : 0;

		if ($is_new) {
			if ($id < 1) {
				self::redirect_with('err', 'invalid_id');
			}
			if (SRD_KM_Categories::exists($id)) {
				self::redirect_with('err', 'duplicate_id');
			}
		} elseif (!SRD_KM_Categories::exists($id)) {
			self::redirect_with('err', 'not_found');
		}

		$record = SRD_KM_Categories::sanitize_record($raw, $id);
		if ($record === null) {
			self::redirect_with('err', 'invalid_data');
		}

		if (SRD_KM_Categories::prefix_in_use($record['prefix'], $id)) {
			self::redirect_with('err', 'duplicate_prefix');
		}

		$all = SRD_KM_Categories::get_all();
		$by_id = array();
		foreach ($all as $cat) {
			$by_id[ (int) $cat['id'] ] = $cat;
		}
		$by_id[ $record['id'] ] = $record;
		SRD_KM_Categories::save_all(array_values($by_id));

		self::redirect_with('ok', $is_new ? 'created' : 'updated');
	}

	public static function handle_delete(): void {
		if (!SRD_KM_Capabilities::user_can_manage()) {
			wp_die(esc_html__('Keine Berechtigung.', 'srd-kreismeisterschaften'));
		}
		check_admin_referer('srd_km_delete_category', 'srd_km_category_delete_nonce');

		$id = isset($_POST['srd_km_category_id']) ? absint($_POST['srd_km_category_id']) : 0;
		if ($id < 1 || !SRD_KM_Categories::exists($id)) {
			self::redirect_with('err', 'not_found');
		}

		$remaining = array();
		foreach (SRD_KM_Categories::get_all() as $cat) {
			if ((int) $cat['id'] !== $id) {
				$remaining[] = $cat;
			}
		}
		if ($remaining === array()) {
			self::redirect_with('err', 'last_category');
		}
		SRD_KM_Categories::save_all($remaining);
		self::redirect_with('ok', 'deleted');
	}

}
