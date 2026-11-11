<?php
/**
 * Serverseitiges Rendern der Templates aus templates/ mit gemeinsamem Layout.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Http;

use KSV\KMM\Support\Settings;

final class View {

	/**
	 * Rendert ein Frontend-Template innerhalb des Layouts und gibt es aus.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function render(string $template, array $data = []): void {
		$content = self::capture($template, $data);
		$data['content'] = $content;
		if ((bool) Settings::get('portal_im_theme')) {
			self::im_theme($data);
			return;
		}
		echo self::capture('frontend/layout', $data); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Templates escapen selbst.
	}

	/**
	 * Gibt die Seite im Rahmen der Website aus: Kopf und Fuß kommen vom Theme, dazwischen
	 * steht das Portal. Stylesheet und Skript laufen über die WordPress-Warteschlange, weil
	 * hier das Theme den <head> schreibt. Titel und „noindex“ werden wie bei der eigenen
	 * Seite gesetzt; die Cache-Sperre hat der Router schon gesendet.
	 *
	 * Wird nicht gepuffert: Themes geben Kopf und Fuß direkt aus.
	 *
	 * @param array<string, mixed> $data
	 */
	private static function im_theme(array $data): void {
		$title = isset($data['title']) && is_string($data['title']) ? $data['title'] : 'KM-Portal';
		add_filter('pre_get_document_title', static fn(): string => $title . ' – ' . get_bloginfo('name'), 99);
		add_filter('wp_robots', static function (array $robots): array {
			unset($robots['index'], $robots['follow'], $robots['max-image-preview']);
			$robots['noindex'] = true;
			$robots['nofollow'] = true;
			return $robots;
		}, 99);
		add_filter('body_class', static function (array $klassen): array {
			$klassen[] = 'kmm-seite';
			return $klassen;
		});
		add_action('wp_enqueue_scripts', static function (): void {
			wp_enqueue_style('kmm-app', KMM_PLUGIN_URL . 'assets/frontend/app.css', [], KMM_VERSION);
			// Dieselbe Schriftdatei wie auf der eigenen Seite, damit das Portal in jedem Theme gleich aussieht.
			wp_add_inline_style('kmm-app', '@font-face{font-family:"Roboto";font-style:normal;font-weight:400 700;font-display:swap;src:url("' . esc_url(KMM_PLUGIN_URL . 'assets/fonts/roboto-latin.woff2?v=' . rawurlencode(KMM_VERSION)) . '") format("woff2")}');
			wp_enqueue_script('kmm-app', KMM_PLUGIN_URL . 'assets/frontend/app.js', [], KMM_VERSION, ['in_footer' => true, 'strategy' => 'defer']);
		}, 99);
		include KMM_PLUGIN_DIR . 'templates/frontend/layout-theme.php';
	}

	/**
	 * Rendert ein Template in einen String (auch für Mails und Admin-Teile nutzbar).
	 *
	 * @param array<string, mixed> $data
	 */
	public static function capture(string $template, array $data = []): string {
		$file = KMM_PLUGIN_DIR . 'templates/' . $template . '.php';
		if (!is_file($file)) {
			return '';
		}
		ob_start();
		(static function (string $__file, array $__data): void {
			extract($__data, EXTR_SKIP); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			include $__file;
		})($file, $data);
		return (string) ob_get_clean();
	}
}
