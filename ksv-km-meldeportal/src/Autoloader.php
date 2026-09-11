<?php
/**
 * Minimaler PSR-4-Autoloader für den Namespace KSV\KMM (Fallback ohne Composer).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM;

final class Autoloader {

	private const PREFIX = 'KSV\\KMM\\';

	public static function register(string $base_dir): void {
		$base_dir = rtrim($base_dir, '/\\') . '/';
		spl_autoload_register(static function (string $class) use ($base_dir): void {
			if (strncmp($class, self::PREFIX, strlen(self::PREFIX)) !== 0) {
				return;
			}
			$relative = substr($class, strlen(self::PREFIX));
			$file = $base_dir . str_replace('\\', '/', $relative) . '.php';
			if (is_file($file)) {
				require_once $file;
			}
		});
	}
}
