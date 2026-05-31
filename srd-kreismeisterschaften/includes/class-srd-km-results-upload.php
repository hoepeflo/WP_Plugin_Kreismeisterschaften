<?php
/**
 * Ergebnis-Uploads (Einzeldatei oder ZIP) in den konfigurierten results-Ordner.
 *
 * @package SRD_Kreismeisterschaften
 */

if (!defined('ABSPATH')) {
	exit;
}

class SRD_KM_Results_Upload {

	private const MAX_ENTRY_BYTES = 52428800; // 50 MiB

	public static function init(): void {
		add_action('admin_post_srd_km_upload_results', array(__CLASS__, 'handle_post'));
	}

	public static function handle_post(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Keine Berechtigung.', 'srd-kreismeisterschaften'));
		}
		check_admin_referer('srd_km_upload_results', 'srd_km_upload_nonce');

		$redirect = add_query_arg(
			array('page' => 'srd-kreismeisterschaften'),
			admin_url('options-general.php')
		);

		$kind = isset($_POST['srd_km_upload_kind']) ? sanitize_key((string) wp_unslash($_POST['srd_km_upload_kind'])) : '';
		if (!in_array($kind, array('file', 'zip'), true)) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'srd_km_upload'   => 'err',
						'srd_km_upload_c' => rawurlencode('bad_kind'),
					),
					$redirect
				)
			);
			exit;
		}

		$base = self::resolved_results_base();
		if ($base === null) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'srd_km_upload'   => 'err',
						'srd_km_upload_c' => rawurlencode('no_dir'),
					),
					$redirect
				)
			);
			exit;
		}

		if (empty($_FILES['srd_km_upload_file']['tmp_name']) || !is_uploaded_file((string) $_FILES['srd_km_upload_file']['tmp_name'])) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'srd_km_upload'   => 'err',
						'srd_km_upload_c' => rawurlencode('no_file'),
					),
					$redirect
				)
			);
			exit;
		}

		$tmp = (string) $_FILES['srd_km_upload_file']['tmp_name'];
		$name = isset($_FILES['srd_km_upload_file']['name']) ? (string) $_FILES['srd_km_upload_file']['name'] : '';

		if ($kind === 'zip') {
			$result = self::ingest_zip($tmp, $base);
		} else {
			$year = isset($_POST['srd_km_upload_year']) ? absint($_POST['srd_km_upload_year']) : 0;
			$result = self::ingest_single_file($tmp, $name, $year, $base);
		}

		$arg = $result['ok'] ? 'ok' : 'err';
		$redirect = add_query_arg(
			array(
				'srd_km_upload'   => $arg,
				'srd_km_upload_c' => rawurlencode((string) $result['code']),
			),
			$redirect
		);
		wp_safe_redirect($redirect);
		exit;
	}

	/**
	 * @return string|null Realpath des results-Stammordners
	 */
	private static function resolved_results_base(): ?string {
		$s = srd_km_get_settings();
		$path = isset($s['results_path']) ? trim((string) $s['results_path']) : '';
		if ($path === '') {
			$path = trailingslashit(WP_CONTENT_DIR) . 'uploads/srd-results';
		}
		$path = untrailingslashit($path);
		if (!wp_mkdir_p($path)) {
			return null;
		}
		$real = realpath($path);
		if ($real === false || !is_dir($real) || !is_writable($real)) {
			return null;
		}
		return $real;
	}

	/**
	 * @return array{ok: bool, code: string}
	 */
	private static function ingest_single_file(string $tmp, string $original_name, int $year, string $realBase): array {
		if ($year < 1990 || $year > 2100) {
			return array('ok' => false, 'code' => 'bad_year');
		}
		$ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
		if (!in_array($ext, array('pdf', 'html', 'htm'), true)) {
			return array('ok' => false, 'code' => 'bad_ext');
		}
		$baseName = basename((string) pathinfo($original_name, PATHINFO_BASENAME));
		$safe = sanitize_file_name($baseName);
		if ($safe === '' || strpos($safe, '.') === false) {
			return array('ok' => false, 'code' => 'bad_name');
		}
		$safeExt = strtolower(pathinfo($safe, PATHINFO_EXTENSION));
		if (!in_array($safeExt, array('pdf', 'html', 'htm'), true)) {
			return array('ok' => false, 'code' => 'bad_name');
		}
		$subdir = 'km_' . $year;
		$dir = $realBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subdir);
		if (!wp_mkdir_p($dir)) {
			return array('ok' => false, 'code' => 'mkdir');
		}
		$target = $dir . DIRECTORY_SEPARATOR . $safe;
		if (!@move_uploaded_file($tmp, $target)) {
			return array('ok' => false, 'code' => 'move');
		}
		if (!self::path_is_under_base($realBase, $target)) {
			@unlink($target);
			return array('ok' => false, 'code' => 'path');
		}
		return array('ok' => true, 'code' => 'file_ok');
	}

	/**
	 * @return array{ok: bool, code: string}
	 */
	private static function ingest_zip(string $tmpZip, string $realBase): array {
		if (!class_exists('ZipArchive')) {
			return array('ok' => false, 'code' => 'no_ziparchive');
		}
		$zip = new ZipArchive();
		if ($zip->open($tmpZip) !== true) {
			return array('ok' => false, 'code' => 'zip_open');
		}
		$written = 0;
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$stat = $zip->statIndex($i);
			if ($stat === false || ($stat['size'] ?? 0) > self::MAX_ENTRY_BYTES) {
				continue;
			}
			$name = $zip->getNameIndex($i);
			if (!is_string($name) || $name === '' || substr($name, -1) === '/') {
				continue;
			}
			$norm = str_replace('\\', '/', $name);
			if (strpos($norm, '..') !== false || strpos($norm, "\0") !== false) {
				continue;
			}
			$norm = ltrim($norm, '/');
			if ($norm === '' || !preg_match('#^[a-zA-Z0-9_./-]+$#', $norm)) {
				continue;
			}
			$ext = strtolower(pathinfo($norm, PATHINFO_EXTENSION));
			if (!in_array($ext, array('pdf', 'html', 'htm'), true)) {
				continue;
			}
			$target = $realBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $norm);
			$targetDir = dirname($target);
			if (!wp_mkdir_p($targetDir)) {
				continue;
			}
			$body = $zip->getFromIndex($i);
			if ($body === false) {
				continue;
			}
			if (strlen($body) > self::MAX_ENTRY_BYTES) {
				continue;
			}
			if (file_put_contents($target, $body) === false) {
				continue;
			}
			if (!self::path_is_under_base($realBase, $target)) {
				@unlink($target);
				continue;
			}
			++$written;
		}
		$zip->close();
		if ($written === 0) {
			return array('ok' => false, 'code' => 'zip_empty');
		}
		return array('ok' => true, 'code' => 'zip_ok:' . (string) $written);
	}

	private static function path_is_under_base(string $realBase, string $path): bool {
		$real = realpath($path);
		if ($real === false || !is_file($real)) {
			return false;
		}
		$prefix = $realBase . DIRECTORY_SEPARATOR;
		return ($real === $realBase) || (strncmp($real, $prefix, strlen($prefix)) === 0);
	}
}
