<?php
/**
 * Startseite mit Sitzung (Platzhalter bis Meilenstein 6).
 *
 * @var string               $title
 * @var array<string, mixed> $verein
 * @var array<string, mixed>|null $sportjahr
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}
?>
<h1><?php echo esc_html($verein['name']); ?></h1>
<p><?php echo esc_html(sprintf(__('VN %s · Sportjahr %d', 'ksv-km-meldeportal'), $verein['vn_nummer'], (int) ($sportjahr['jahr'] ?? 0))); ?></p>
<p><?php esc_html_e('Die Meldeoberfläche (Schützenliste, Meldung, Mannschaften) wird im nächsten Schritt freigeschaltet.', 'ksv-km-meldeportal'); ?></p>
<p><a href="<?php echo esc_url(KSV\KMM\Http\Router::url('abmelden')); ?>"><?php esc_html_e('Abmelden', 'ksv-km-meldeportal'); ?></a></p>
