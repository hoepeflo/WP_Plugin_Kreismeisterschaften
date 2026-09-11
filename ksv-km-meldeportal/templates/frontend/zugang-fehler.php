<?php
/**
 * Ungültiger oder abgelaufener Zugangslink.
 *
 * @var string $title
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}
?>
<h1><?php echo esc_html($title); ?></h1>
<div class="kmm-alert kmm-alert-error"><?php esc_html_e('Dieser Zugangslink ist ungültig oder wurde durch einen neueren Link ersetzt.', 'ksv-km-meldeportal'); ?></div>
<p><a class="kmm-button" href="<?php echo esc_url(KSV\KMM\Http\Router::url('link-anfordern')); ?>"><?php esc_html_e('Neuen Zugangslink anfordern', 'ksv-km-meldeportal'); ?></a></p>
