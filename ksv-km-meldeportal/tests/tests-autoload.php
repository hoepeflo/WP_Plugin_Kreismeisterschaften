<?php
/**
 * Autoloader für den Test-Namespace KSV\KMM\Tests (Fallback ohne Composer autoload-dev).
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
	$prefix = 'KSV\\KMM\\Tests\\';
	if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
		return;
	}
	$file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (is_file($file)) {
		require_once $file;
	}
});
