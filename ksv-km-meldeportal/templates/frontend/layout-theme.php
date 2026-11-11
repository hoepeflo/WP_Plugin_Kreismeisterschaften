<?php
/**
 * Seitenrahmen der Vereinsoberfläche im Rahmen der Website: Kopf und Fuß des Themes,
 * dazwischen das Portal (Einstellung „Portal im Rahmen der Website anzeigen“).
 *
 * WordPress lädt diese Datei als Vorlage (Filter template_include, siehe Http\View).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}
$content = KSV\KMM\Http\View::theme_inhalt();

get_header();
?>
<div class="kmm kmm-im-theme">
	<main class="kmm-main">
		<div class="kmm-container">
			<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inhalt wurde im Template escaped. ?>
		</div>
	</main>
</div>
<?php
get_footer();
