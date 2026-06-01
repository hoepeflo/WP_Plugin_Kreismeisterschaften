<?php
/**
 * Speichern der Ausschreibungsdokumente (Admin-POST).
 *
 * @package SRD_Kreismeisterschaften
 */

if (!defined('ABSPATH')) {
	exit;
}

class SRD_KM_Documents_Handler {

	public static function init(): void {
		add_action('admin_post_srd_km_save_documents', array(__CLASS__, 'handle_save'));
	}

	public static function handle_save(): void {
		if (!SRD_KM_Capabilities::user_can_manage()) {
			wp_die(esc_html__('Sie haben keinen Zugriff.', 'srd-kreismeisterschaften'));
		}
		check_admin_referer('srd_km_save_documents', 'srd_km_documents_nonce');

		$year = isset($_POST['srd_km_doc_year']) ? absint(wp_unslash($_POST['srd_km_doc_year'])) : 0;
		if ($year < 1990 || $year > 2100) {
			self::redirect($year, 'err', 'bad_year');
		}

		$existing = SRD_KM_Documents::get_year($year);
		$raw = isset($_POST['srd_km_documents']) && is_array($_POST['srd_km_documents'])
			? wp_unslash($_POST['srd_km_documents'])
			: array();
		$files = isset($_FILES['srd_km_documents']) && is_array($_FILES['srd_km_documents'])
			? $_FILES['srd_km_documents']
			: array();

		$out = array();
		foreach (SRD_KM_Documents::all_types() as $key => $label) {
			unset($label);
			$row = isset($raw[ $key ]) && is_array($raw[ $key ]) ? $raw[ $key ] : array();
			$type = isset($row['type']) ? sanitize_key((string) $row['type']) : '';

			if ($type === 'page') {
				$out[ $key ] = SRD_KM_Documents::sanitize_entry(
					array(
						'type'    => 'page',
						'page_id' => isset($row['page_id']) ? absint($row['page_id']) : 0,
					)
				);
				continue;
			}

			if ($type === 'pdf') {
				$aid = isset($row['attachment_id']) ? absint($row['attachment_id']) : 0;
				if ($aid <= 0 && isset($existing[ $key ]['attachment_id'])) {
					$aid = absint($existing[ $key ]['attachment_id']);
				}
				if (self::has_pdf_upload($files, $key)) {
					$uploaded = self::handle_pdf_upload($files, $key);
					if (is_wp_error($uploaded)) {
						self::redirect($year, 'err', 'upload');
					}
					if ($uploaded > 0) {
						$aid = $uploaded;
					}
				}
				$out[ $key ] = SRD_KM_Documents::sanitize_entry(
					array(
						'type'            => 'pdf',
						'attachment_id'   => $aid,
					)
				);
				continue;
			}

			$out[ $key ] = SRD_KM_Documents::sanitize_entry(array());
		}

		SRD_KM_Documents::save_year($year, $out);
		self::redirect($year, 'ok', 'saved');
	}

	/**
	 * @param array<string, mixed> $files
	 */
	private static function has_pdf_upload(array $files, string $key): bool {
		if (!isset($files['name'][ $key ]['pdf_file'])) {
			return false;
		}
		$name = $files['name'][ $key ]['pdf_file'];
		return is_string($name) && $name !== '';
	}

	/**
	 * @param array<string, mixed> $files
	 * @return int|\WP_Error
	 */
	private static function handle_pdf_upload(array $files, string $key) {
		$tmp = $files['tmp_name'][ $key ]['pdf_file'] ?? '';
		if (!is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp)) {
			return 0;
		}
		$file = array(
			'name'     => $files['name'][ $key ]['pdf_file'],
			'type'     => $files['type'][ $key ]['pdf_file'] ?? '',
			'tmp_name' => $tmp,
			'error'    => $files['error'][ $key ]['pdf_file'] ?? 0,
			'size'     => $files['size'][ $key ]['pdf_file'] ?? 0,
		);
		$field = 'srd_km_doc_upload_' . $key;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_handle_upload validiert
		$_FILES[ $field ] = $file;
		$result = SRD_KM_Documents::ingest_pdf_upload($field);
		unset($_FILES[ $field ]);
		return $result;
	}

	private static function redirect(int $year, string $status, string $code): void {
		$url = add_query_arg(
			array(
				'page'           => 'srd-kreismeisterschaften-documents',
				'srd_km_doc'     => $status,
				'srd_km_doc_c'   => $code,
				'srd_km_doc_year' => $year > 0 ? (string) $year : null,
			),
			admin_url('admin.php')
		);
		wp_safe_redirect($url);
		exit;
	}
}
