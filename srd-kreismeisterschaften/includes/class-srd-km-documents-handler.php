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

		$existing = SRD_KM_Documents::get_current();
		$raw = isset($_POST['srd_km_documents']) && is_array($_POST['srd_km_documents'])
			? wp_unslash($_POST['srd_km_documents'])
			: array();
		$files = isset($_FILES['srd_km_documents_files']) && is_array($_FILES['srd_km_documents_files'])
			? $_FILES['srd_km_documents_files']
			: array();
		$order_raw = isset($_POST['srd_km_category_order']) && is_array($_POST['srd_km_category_order'])
			? wp_unslash($_POST['srd_km_category_order'])
			: array();

		$out = array();
		foreach (SRD_KM_Documents::fixed_types() as $key => $label) {
			unset($label);
			$out[ $key ] = self::process_entry($key, $raw, $files, $existing);
		}

		$category_order = array();
		foreach ($order_raw as $key) {
			$key = sanitize_key((string) $key);
			if ($key === '') {
				continue;
			}
			if (SRD_KM_Documents::is_standard_category_key($key) || SRD_KM_Documents::is_custom_category_key($key)) {
				$category_order[] = $key;
			}
		}
		$category_order = array_values(array_unique($category_order));

		foreach ($category_order as $key) {
			if (SRD_KM_Documents::is_custom_category_key($key)) {
				$row = isset($raw[ $key ]) && is_array($raw[ $key ]) ? $raw[ $key ] : array();
				$meta = SRD_KM_Documents::sanitize_custom_category_meta($row);
				if ($meta['label'] === '') {
					continue;
				}
				$entry = self::process_entry($key, $raw, $files, $existing);
				$out[ $key ] = array_merge(
					$entry,
					array(
						'label'      => $meta['label'],
						'categories' => $meta['categories'],
					)
				);
				continue;
			}
			if (SRD_KM_Documents::is_standard_category_key($key)) {
				$out[ $key ] = self::process_entry($key, $raw, $files, $existing);
			}
		}

		$out['category_order'] = $category_order;

		SRD_KM_Documents::save_current($out);
		self::redirect('ok', 'saved');
	}

	/**
	 * @param array<string, mixed> $raw
	 * @param array<string, mixed> $files
	 * @param array<string, array<string, mixed>> $existing
	 * @return array{type: string, attachment_id: int, page_id: int}
	 */
	private static function process_entry(string $key, array $raw, array $files, array $existing): array {
		$row = isset($raw[ $key ]) && is_array($raw[ $key ]) ? $raw[ $key ] : array();
		$type = isset($row['type']) ? sanitize_key((string) $row['type']) : '';

		if ($type === 'page') {
			return SRD_KM_Documents::sanitize_entry(
				array(
					'type'    => 'page',
					'page_id' => isset($row['page_id']) ? absint($row['page_id']) : 0,
				)
			);
		}

		if ($type === 'url') {
			return SRD_KM_Documents::sanitize_entry(
				array(
					'type' => 'url',
					'url'  => isset($row['url']) ? (string) $row['url'] : '',
				)
			);
		}

		if ($type === 'pdf') {
			$aid = isset($row['attachment_id']) ? absint($row['attachment_id']) : 0;
			if ($aid <= 0 && isset($existing[ $key ]['attachment_id'])) {
				$aid = absint($existing[ $key ]['attachment_id']);
			}
			if (self::has_pdf_upload($files, $key)) {
				$uploaded = self::handle_pdf_upload($files, $key);
				if (is_wp_error($uploaded)) {
					self::redirect('err', 'upload');
				}
				if ($uploaded > 0) {
					$aid = $uploaded;
				}
			}
			return SRD_KM_Documents::sanitize_entry(
				array(
					'type'          => 'pdf',
					'attachment_id' => $aid,
				)
			);
		}

		return SRD_KM_Documents::sanitize_entry(array());
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

	private static function redirect(string $status, string $code): void {
		$url = add_query_arg(
			array(
				'page'         => 'srd-kreismeisterschaften-documents',
				'srd_km_doc'   => $status,
				'srd_km_doc_c' => $code,
			),
			admin_url('admin.php')
		);
		wp_safe_redirect($url);
		exit;
	}
}
