<?php
/**
 * admin_post-Handler für Disziplinen-CRUD.
 *
 * @package SRD_Kreismeisterschaften
 */

if (!defined('ABSPATH')) {
	exit;
}

class SRD_KM_Disciplines_Handler {

	public static function init(): void {
		add_action('admin_post_srd_km_save_discipline', array(__CLASS__, 'handle_save'));
		add_action('admin_post_srd_km_delete_discipline', array(__CLASS__, 'handle_delete'));
	}

	private static function list_url(): string {
		return admin_url('options-general.php?page=srd-kreismeisterschaften-disciplines');
	}

	private static function redirect_with(string $status, string $code, ?string $edit_id = null): void {
		$args = array(
			'srd_km_disc'   => $status,
			'srd_km_disc_c' => $code,
		);
		$url = self::list_url();
		if ($edit_id !== null && $status === 'ok' && in_array($code, array('created', 'updated'), true)) {
			$url = add_query_arg(
				array(
					'action' => 'edit',
					'id'     => $edit_id,
				) + $args,
				self::list_url()
			);
			wp_safe_redirect($url);
			exit;
		}
		wp_safe_redirect(add_query_arg($args, $url));
		exit;
	}

	/**
	 * @return array<string, string>|null
	 */
	private static function sanitize_discipline_input(): ?array {
		if (!isset($_POST['srd_km_discipline']) || !is_array($_POST['srd_km_discipline'])) {
			return null;
		}
		$raw = wp_unslash($_POST['srd_km_discipline']);
		$out = array();
		foreach (SRD_KM_DB::kreis_v3_editable_fields() as $field) {
			if (!isset($raw[ $field ])) {
				continue;
			}
			$val = (string) $raw[ $field ];
			if ($field === 'sportjahr') {
				$y = absint($val);
				if ($y < 1990 || $y > 2100) {
					return null;
				}
				$out[ $field ] = (string) $y;
			} elseif ($field === 'datei') {
				$val = sanitize_text_field($val);
				if (!preg_match('/^[a-zA-Z0-9_-]+$/', $val)) {
					return null;
				}
				$out[ $field ] = $val;
			} else {
				$out[ $field ] = sanitize_text_field($val);
			}
		}
		if (!isset($out['disziplin']) || $out['disziplin'] === '') {
			return null;
		}
		if (!isset($out['datei']) || $out['datei'] === '') {
			return null;
		}
		return $out;
	}

	public static function handle_save(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Keine Berechtigung.', 'srd-kreismeisterschaften'));
		}
		check_admin_referer('srd_km_save_discipline', 'srd_km_discipline_nonce');

		if (!SRD_KM_DB::table_available()) {
			self::redirect_with('err', 'no_table');
		}

		$data = self::sanitize_discipline_input();
		if ($data === null) {
			$code = 'invalid_data';
			if (isset($_POST['srd_km_discipline']['datei'])) {
				$datei = sanitize_text_field((string) wp_unslash($_POST['srd_km_discipline']['datei']));
				if ($datei !== '' && !preg_match('/^[a-zA-Z0-9_-]+$/', $datei)) {
					$code = 'invalid_datei';
				}
			}
			self::redirect_with('err', $code);
		}

		$id = isset($_POST['srd_km_discipline_id']) ? absint($_POST['srd_km_discipline_id']) : 0;

		if ($id > 0) {
			if (SRD_KM_DB::kreis_row_by_id($id) === null) {
				self::redirect_with('err', 'not_found');
			}
			if (!SRD_KM_DB::update_kreis_row($id, $data)) {
				self::redirect_with('err', 'save_failed');
			}
			self::redirect_with('ok', 'updated', (string) $id);
		}

		$result = SRD_KM_DB::insert_kreis_row($data);
		if ($result === false) {
			self::redirect_with('err', 'save_failed');
		}
		$new_id = is_int($result) ? $result : 0;
		if ($new_id <= 0) {
			self::redirect_with('ok', 'created');
		}
		self::redirect_with('ok', 'created', (string) $new_id);
	}

	public static function handle_delete(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Keine Berechtigung.', 'srd-kreismeisterschaften'));
		}
		check_admin_referer('srd_km_delete_discipline', 'srd_km_discipline_delete_nonce');

		if (!SRD_KM_DB::table_available()) {
			self::redirect_with('err', 'no_table');
		}

		$id = isset($_POST['srd_km_discipline_id']) ? absint($_POST['srd_km_discipline_id']) : 0;
		if ($id <= 0) {
			self::redirect_with('err', 'invalid_id');
		}
		if (SRD_KM_DB::kreis_row_by_id($id) === null) {
			self::redirect_with('err', 'not_found');
		}
		if (!SRD_KM_DB::delete_kreis_row($id)) {
			self::redirect_with('err', 'delete_failed');
		}
		self::redirect_with('ok', 'deleted');
	}
}
