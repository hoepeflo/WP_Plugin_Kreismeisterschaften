<?php
/**
 * Konstanten für PHPStan (werden sonst in der Plugin-Hauptdatei definiert).
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 3) . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
define('KMM_VERSION', '0.0.0');
define('KMM_PLUGIN_FILE', __DIR__ . '/../ksv-km-meldeportal.php');
define('KMM_PLUGIN_DIR', dirname(__DIR__) . '/');
define('KMM_PLUGIN_URL', 'https://example.org/wp-content/plugins/ksv-km-meldeportal/');
define('KMM_PLUGIN_BASENAME', 'ksv-km-meldeportal/ksv-km-meldeportal.php');
