<?php
/**
 * Startseite ohne Sitzung: kurz erklären, wie die Meldung abläuft, und den Zugangslink
 * direkt hier anfordern lassen (ohne Umweg über eine zweite Seite).
 *
 * @var string $title
 * @var string $nonce
 * @var string $bezeichnung  Aktives Sportjahr, z. B. „Kreisverbandsmeisterschaft 2027“ (oder leer)
 * @var string $meldeschluss Meldeschluss, lokal formatiert (oder leer)
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}
?>
<div class="kmm-panel kmm-willkommen">
<h1><?php echo esc_html($title); ?></h1>
<p class="kmm-willkommen-intro"><?php
	echo esc_html(
		$bezeichnung !== ''
			/* translators: %s: Bezeichnung des Sportjahrs */
			? sprintf(__('Hier melden die Vereine des KSV Fallingbostel ihre Starter zur %s.', 'ksv-km-meldeportal'), $bezeichnung)
			: __('Hier melden die Vereine des KSV Fallingbostel ihre Starter zur Kreisverbandsmeisterschaft.', 'ksv-km-meldeportal')
	);
	?></p>
<?php if ($meldeschluss !== '') : ?>
	<p class="kmm-hinweis"><?php
	/* translators: %s: Datum und Uhrzeit des Meldeschlusses */
	echo esc_html(sprintf(__('Meldeschluss: %s Uhr', 'ksv-km-meldeportal'), $meldeschluss));
	?></p>
<?php endif; ?>

<ol class="kmm-ablauf" aria-label="<?php esc_attr_e('So funktioniert die Meldung', 'ksv-km-meldeportal'); ?>">
	<li><strong><?php esc_html_e('Zugangslink öffnen', 'ksv-km-meldeportal'); ?></strong><span><?php esc_html_e('Jeder Verein bekommt einen persönlichen Link per E-Mail. Ein Passwort ist nicht nötig.', 'ksv-km-meldeportal'); ?></span></li>
	<li><strong><?php esc_html_e('Schützen anlegen und melden', 'ksv-km-meldeportal'); ?></strong><span><?php esc_html_e('Die Schützenliste bleibt für die nächsten Jahre gespeichert.', 'ksv-km-meldeportal'); ?></span></li>
	<li><strong><?php esc_html_e('Verbindlich einreichen', 'ksv-km-meldeportal'); ?></strong><span><?php esc_html_e('Bis zum Meldeschluss lässt sich alles noch ändern.', 'ksv-km-meldeportal'); ?></span></li>
</ol>

<div class="kmm-link-box">
	<h2><?php esc_html_e('Link nicht zur Hand?', 'ksv-km-meldeportal'); ?></h2>
	<p><?php esc_html_e('Geben Sie eine E-Mail-Adresse ein, die beim KSV für Ihren Verein hinterlegt ist. Sie erhalten dann sofort einen neuen Zugangslink.', 'ksv-km-meldeportal'); ?></p>
	<form method="post" action="<?php echo esc_url(KSV\KMM\Http\Router::url('link-anfordern')); ?>" class="kmm-form kmm-link-form">
		<input type="hidden" name="_kmm_nonce" value="<?php echo esc_attr($nonce); ?>">
		<label for="kmm-email"><?php esc_html_e('E-Mail-Adresse des Vereins', 'ksv-km-meldeportal'); ?></label>
		<div class="kmm-link-zeile">
			<input type="email" id="kmm-email" name="email" required autocomplete="email" inputmode="email" placeholder="<?php esc_attr_e('name@verein.de', 'ksv-km-meldeportal'); ?>">
			<button type="submit" class="kmm-button"><?php esc_html_e('Link zusenden', 'ksv-km-meldeportal'); ?></button>
		</div>
	</form>
	<p class="kmm-muted"><?php esc_html_e('Der Link kommt innerhalb weniger Minuten. Bitte auch im Spam-Ordner nachsehen.', 'ksv-km-meldeportal'); ?></p>
</div>
</div>
