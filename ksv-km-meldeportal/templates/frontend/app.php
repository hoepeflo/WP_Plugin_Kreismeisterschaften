<?php
/**
 * Vereinsoberfläche: Seitengerüst; Inhalte rendert assets/frontend/app.js aus dem
 * eingebetteten Anfangszustand und den REST-Endpunkten.
 *
 * @var string               $title
 * @var array<string, mixed> $verein
 * @var array<string, mixed>|null $sportjahr
 * @var array<string, mixed> $state
 * @var array<int, mixed>    $schuetzen
 * @var array<int, mixed>    $startplan Freigegebene Wettkampftage mit Meldungen des Vereins (leer = Tab unsichtbar)
 * @var string $csrf
 * @var string $api
 * @var string $pdf_url
 * @var string $csv_url
 * @var string $abmelden
 * @var array<string, mixed>|null $admin Admin-Modus (verein_id, sportjahr_id, nonce, zurueck, benutzer) oder null
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}
$config = [
	'api'       => $api,
	'csrf'      => $csrf,
	'pdfUrl'    => $pdf_url,
	'csvUrl'    => $csv_url,
	'abmelden'  => $abmelden,
	'meldung'   => $state,
	'schuetzen' => $schuetzen,
	'startplan' => $startplan,
	'admin'     => $admin,
];
?>
<div id="kmm-app" class="kmm-app<?php echo $admin !== null ? ' kmm-app-admin' : ''; ?>" data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>">
	<?php if ($admin !== null) : ?>
	<div class="kmm-admin-leiste"><strong><?php esc_html_e('Admin-Modus', 'ksv-km-meldeportal'); ?></strong> · <?php echo esc_html(sprintf(__('angemeldet als %s', 'ksv-km-meldeportal'), (string) $admin['benutzer'])); ?> · <a href="<?php echo esc_url((string) $admin['zurueck']); ?>"><?php esc_html_e('Zurück zur Übersicht', 'ksv-km-meldeportal'); ?></a></div>
	<?php endif; ?>
	<noscript><div class="kmm-alert kmm-alert-error"><?php esc_html_e('Für das KM-Portal muss JavaScript aktiviert sein.', 'ksv-km-meldeportal'); ?></div></noscript>
	<div class="kmm-app-kopf">
		<div>
			<h1><?php echo esc_html($verein['name']); ?></h1>
			<div class="kmm-muted"><?php echo esc_html(sprintf(__('VN %s · Kreisverbandsmeisterschaft %d', 'ksv-km-meldeportal'), $verein['vn_nummer'], (int) ($sportjahr['jahr'] ?? 0))); ?></div>
		</div>
		<div class="kmm-app-status" id="kmm-status"></div>
	</div>
	<div id="kmm-banner"></div>
	<nav class="kmm-tabs" id="kmm-tabs" aria-label="<?php esc_attr_e('Schritte', 'ksv-km-meldeportal'); ?>">
		<?php
		$schritte = [
			'schuetzen'    => __('Schützen', 'ksv-km-meldeportal'),
			'meldung'      => __('Meldung', 'ksv-km-meldeportal'),
			'mannschaften' => __('Mannschaften', 'ksv-km-meldeportal'),
			'einreichen'   => $admin !== null ? __('Ansprechpartner & Nachmeldung', 'ksv-km-meldeportal') : __('Prüfen & Einreichen', 'ksv-km-meldeportal'),
			'startplan'    => __('Startplätze', 'ksv-km-meldeportal'),
		];
		$nr = 0;
		foreach ($schritte as $key => $label) :
			++$nr;
			?>
		<button type="button" data-tab="<?php echo esc_attr($key); ?>" class="kmm-schritt<?php echo $key === 'schuetzen' ? ' is-active' : ''; ?>"<?php echo $key === 'startplan' ? ' id="kmm-tab-button-startplan"' . ($startplan === [] ? ' hidden' : '') : ''; ?> aria-controls="kmm-tab-<?php echo esc_attr($key); ?>">
			<span class="kmm-schritt-nr" aria-hidden="true"><?php echo (int) $nr; ?></span>
			<span class="kmm-schritt-text"><span class="kmm-schritt-name"><?php echo esc_html($label); ?></span><small class="kmm-schritt-info" data-info="<?php echo esc_attr($key); ?>"></small></span>
		</button>
		<?php endforeach; ?>
	</nav>
	<section id="kmm-tab-schuetzen" class="kmm-tab"></section>
	<section id="kmm-tab-meldung" class="kmm-tab" hidden></section>
	<section id="kmm-tab-mannschaften" class="kmm-tab" hidden></section>
	<section id="kmm-tab-einreichen" class="kmm-tab" hidden></section>
	<section id="kmm-tab-startplan" class="kmm-tab" hidden></section>
	<div class="kmm-schritt-nav" id="kmm-schritt-nav"></div>
	<dialog id="kmm-dialog" class="kmm-dialog"></dialog>
	<div id="kmm-toast" class="kmm-toast" role="status" aria-live="polite"></div>
	<p class="kmm-app-fuss">
		<a href="<?php echo esc_url($pdf_url); ?>"><?php esc_html_e('Meldung als PDF', 'ksv-km-meldeportal'); ?></a> ·
		<a href="<?php echo esc_url($csv_url); ?>"><?php esc_html_e('als Excel-Liste (CSV)', 'ksv-km-meldeportal'); ?></a> ·
		<?php if ($admin !== null) : ?>
		<a href="<?php echo esc_url((string) $admin['zurueck']); ?>"><?php esc_html_e('Zurück zur Übersicht', 'ksv-km-meldeportal'); ?></a>
		<?php else : ?>
		<a href="<?php echo esc_url($abmelden); ?>"><?php esc_html_e('Abmelden', 'ksv-km-meldeportal'); ?></a>
		<?php endif; ?>
	</p>
	<details class="kmm-datenschutz">
		<summary><?php esc_html_e('Datenschutzhinweis', 'ksv-km-meldeportal'); ?></summary>
		<p><?php esc_html_e('Die hier erfassten Daten (Name, Geburtsdatum, Geschlecht, Mitgliedsnummer, Meldeergebnis, Ansprechpartner) werden ausschließlich zur Durchführung der Kreisverbandsmeisterschaft verarbeitet: Startrechtsprüfung, Klasseneinteilung, Startplanung, Ergebnisdienst und Abrechnung mit dem Verein. Die Meldedaten werden an den NSSV bzw. in das Wettkampfprogramm DAVID21 übertragen. Nach Freigabe eines Startplans buchen die Vereine Startplätze; mit der Veröffentlichung des Startplans werden Name, Vorname, Verein, Startklasse, Einheit und Uhrzeit der Starter auf der Website des KSV veröffentlicht. Nach Abschluss des Sportjahres werden Namen, Geburtsdaten, Mitgliedsnummern und Ansprechpartner aus den Meldungen entfernt; erhalten bleiben anonyme Statistikdaten. Eine gewählte Para-Klasse wird nur an der Meldung gespeichert und nach der Meisterschaft gelöscht. Die Schützenliste des Vereins bleibt für Folgejahre erhalten; Schützen ohne Meldung in mehreren Jahren werden gelöscht.', 'ksv-km-meldeportal'); ?></p>
	</details>
</div>
