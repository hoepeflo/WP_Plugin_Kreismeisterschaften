<?php
/**
 * PHPUnit-Bootstrap: lädt den Autoloader. Die Unit-Tests benötigen kein WordPress;
 * wo einzelne WP-Funktionen gebraucht werden, stellt tests/wp-stubs.php Ersatz bereit.
 */

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_readable($autoload)) {
	require_once $autoload;
} else {
	require_once __DIR__ . '/../src/Autoloader.php';
	KSV\KMM\Autoloader::register(__DIR__ . '/../src/');
}

require_once __DIR__ . '/tests-autoload.php';
require_once __DIR__ . '/wp-stubs.php';
