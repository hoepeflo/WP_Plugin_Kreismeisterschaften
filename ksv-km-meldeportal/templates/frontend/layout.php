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
// Wie auf der Website des KSV: Website-Icon als Logo, rechtliche Seiten im Fuß – jeweils nur, wenn vorhanden.
$logo = function_exists('get_site_icon_url') ? (string) get_site_icon_url(96) : '';
$impressum = get_page_by_path('impressum');
$impressum_url = $impressum instanceof WP_Post && $impressum->post_status === 'publish' ? (string) get_permalink($impressum) : '';
$datenschutz_url = function_exists('get_privacy_policy_url') ? (string) get_privacy_policy_url() : '';
$font_url = KMM_PLUGIN_URL . 'assets/fonts/roboto-latin.woff2?v=' . rawurlencode(KMM_VERSION);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" media="(prefers-color-scheme: light)" content="#2f6806">
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="#16260d">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html($title); ?> – KSV Fallingbostel</title>
<?php
if (function_exists('wp_site_icon')) {
	wp_site_icon();
}
?>
<link rel="preload" href="<?php echo esc_url($font_url); ?>" as="font" type="font/woff2" crossorigin>
<style>@font-face{font-family:"Roboto";font-style:normal;font-weight:400 700;font-display:swap;src:url("<?php echo esc_url($font_url); ?>") format("woff2")}</style>
<link rel="stylesheet" href="<?php echo esc_url(KMM_PLUGIN_URL . 'assets/frontend/app.css?v=' . rawurlencode(KMM_VERSION)); ?>">
</head>
<body class="kmm">
<div class="kmm-leiste">
	<div class="kmm-container">
		<span><?php esc_html_e('Kreisschützenverband Fallingbostel e. V.', 'ksv-km-meldeportal'); ?></span>
		<a href="<?php echo esc_url(home_url('/')); ?>">ksv-fallingbostel.de</a>
	</div>
</div>
<header class="kmm-header">
	<div class="kmm-container">
		<a class="kmm-brand" href="<?php echo esc_url(KSV\KMM\Http\Router::url()); ?>">
			<?php if ($logo !== '') : ?>
			<img class="kmm-brand-logo" src="<?php echo esc_url($logo); ?>" alt="" width="44" height="44">
			<?php else : ?>
			<span class="kmm-brand-mark" aria-hidden="true"></span>
			<?php endif; ?>
			<span class="kmm-brand-text">KM-Portal<small><?php esc_html_e('Kreisverbandsmeisterschaft', 'ksv-km-meldeportal'); ?></small></span>
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
		<div class="kmm-footer-wer">
			<strong><?php esc_html_e('Kreisschützenverband Fallingbostel e. V.', 'ksv-km-meldeportal'); ?></strong>
			<span><?php esc_html_e('KM-Portal – Meldung zur Kreisverbandsmeisterschaft', 'ksv-km-meldeportal'); ?></span>
		</div>
		<nav class="kmm-footer-links" aria-label="<?php esc_attr_e('Rechtliches', 'ksv-km-meldeportal'); ?>">
			<a href="<?php echo esc_url(home_url('/')); ?>">ksv-fallingbostel.de</a>
			<?php if ($impressum_url !== '') : ?>
			<a href="<?php echo esc_url($impressum_url); ?>"><?php esc_html_e('Impressum', 'ksv-km-meldeportal'); ?></a>
			<?php endif; ?>
			<?php if ($datenschutz_url !== '') : ?>
			<a href="<?php echo esc_url($datenschutz_url); ?>"><?php esc_html_e('Datenschutzerklärung', 'ksv-km-meldeportal'); ?></a>
			<?php endif; ?>
		</nav>
	</div>
</footer>
<script src="<?php echo esc_url(KMM_PLUGIN_URL . 'assets/frontend/app.js?v=' . rawurlencode(KMM_VERSION)); ?>" defer></script>
</body>
</html>
