<?php
/**
 * Seitenrahmen der Vereinsoberfläche (ohne Theme).
 *
 * @var string $title
 * @var string $content
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}
$title = isset($title) && is_string($title) ? $title : 'KM-Portal';
$content = isset($content) && is_string($content) ? $content : '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#1c5d3a">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html($title); ?> – KSV Fallingbostel</title>
<link rel="stylesheet" href="<?php echo esc_url(KMM_PLUGIN_URL . 'assets/frontend/app.css?v=' . rawurlencode(KMM_VERSION)); ?>">
</head>
<body class="kmm">
<header class="kmm-header">
	<div class="kmm-container">
		<a class="kmm-brand" href="<?php echo esc_url(KSV\KMM\Http\Router::url()); ?>">
			<span class="kmm-brand-mark" aria-hidden="true"></span>
			<span class="kmm-brand-text">KM-Portal<small><?php esc_html_e('KSV Fallingbostel', 'ksv-km-meldeportal'); ?></small></span>
		</a>
	</div>
</header>
<main class="kmm-main">
	<div class="kmm-container">
		<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wurde im Template escaped. ?>
	</div>
</main>
<footer class="kmm-footer">
	<div class="kmm-container">
		<a href="<?php echo esc_url(home_url('/')); ?>">ksv-fallingbostel.de</a>
	</div>
</footer>
<script src="<?php echo esc_url(KMM_PLUGIN_URL . 'assets/frontend/app.js?v=' . rawurlencode(KMM_VERSION)); ?>" defer></script>
</body>
</html>
