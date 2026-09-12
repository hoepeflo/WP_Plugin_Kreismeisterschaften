<?php
/**
 * Startseite ohne Sitzung.
 *
 * @var string $title
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}
?>
<div class="kmm-panel">
<h1><?php echo esc_html($title); ?></h1>
<p><?php esc_html_e('Hier melden die Vereine des KSV Fallingbostel ihre Starter zur Kreisverbandsmeisterschaft. Jeder Verein hat einen persönlichen Zugangslink per E-Mail erhalten.', 'ksv-km-meldeportal'); ?></p>
<p><a class="kmm-button" href="<?php echo esc_url(KSV\KMM\Http\Router::url('link-anfordern')); ?>"><?php esc_html_e('Zugangslink anfordern', 'ksv-km-meldeportal'); ?></a></p>
</div>
