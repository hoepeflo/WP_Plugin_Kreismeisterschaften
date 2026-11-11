<?php
/**
 * Seitenrahmen der Vereinsoberfläche im Rahmen der Website: Kopf und Fuß des Themes,
 * dazwischen das Portal (Einstellung „Portal im Rahmen der Website anzeigen“).
 *
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}
$content = isset($data['content']) && is_string($data['content']) ? $data['content'] : '';

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
