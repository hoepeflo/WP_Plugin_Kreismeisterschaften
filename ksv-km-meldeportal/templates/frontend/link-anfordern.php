<?php
/**
 * Seite „Link anfordern".
 *
 * @var string $title
 * @var bool   $gesendet
 * @var bool   $limit
 * @var string $nonce
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}
?>
<div class="kmm-panel">
<h1><?php echo esc_html($title); ?></h1>
<?php if ($limit) : ?>
	<div class="kmm-alert kmm-alert-warn"><?php esc_html_e('Zu viele Anfragen. Bitte versuchen Sie es in einer Stunde erneut.', 'ksv-km-meldeportal'); ?></div>
<?php elseif ($gesendet) : ?>
	<div class="kmm-alert kmm-alert-ok"><?php esc_html_e('Wenn die Adresse bei einem Verein hinterlegt ist, wurde ein neuer Zugangslink dorthin gesendet. Bitte prüfen Sie auch den Spam-Ordner.', 'ksv-km-meldeportal'); ?></div>
<?php endif; ?>
<p><?php esc_html_e('Geben Sie eine E-Mail-Adresse ein, die beim KSV für Ihren Verein hinterlegt ist. Sie erhalten dann einen neuen Zugangslink.', 'ksv-km-meldeportal'); ?></p>
<form method="post" action="<?php echo esc_url(KSV\KMM\Http\Router::url('link-anfordern')); ?>" class="kmm-form">
	<input type="hidden" name="_kmm_nonce" value="<?php echo esc_attr($nonce); ?>">
	<label for="kmm-email"><?php esc_html_e('E-Mail-Adresse', 'ksv-km-meldeportal'); ?></label>
	<input type="email" id="kmm-email" name="email" required autocomplete="email" inputmode="email">
	<button type="submit" class="kmm-button"><?php esc_html_e('Link anfordern', 'ksv-km-meldeportal'); ?></button>
</form>
</div>
