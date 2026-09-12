<?php
/**
 * Allgemeine Fehlerseite.
 *
 * @var string $title
 * @var string $text
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}
?>
<h1><?php echo esc_html($title); ?></h1>
<div class="kmm-alert kmm-alert-error"><?php echo esc_html($text); ?></div>
<p><a class="kmm-button" href="<?php echo esc_url(KSV\KMM\Http\Router::url()); ?>"><?php esc_html_e('Zurück zur Meldung', 'ksv-km-meldeportal'); ?></a></p>
