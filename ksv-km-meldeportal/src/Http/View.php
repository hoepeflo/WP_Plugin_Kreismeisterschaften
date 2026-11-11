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
	 * Inhalt, der im Rahmen der Website ausgegeben wird, sobald WordPress die Vorlage lädt.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $im_theme = null;

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
	 * Bereitet die Seite im Rahmen der Website vor: Kopf und Fuß kommen vom Theme, dazwischen
	 * steht das Portal. Ausgegeben wird hier noch nichts – der Inhalt wird gemerkt und
	 * layout-theme.php als Vorlage eingehängt. So durchläuft die Seite den normalen Weg von
	 * WordPress, und alles, was Theme und Plugins beim Laden einer Vorlage einrichten, greift
	 * auch hier. Stylesheet und Skript laufen über die WordPress-Warteschlange, weil das
	 * Theme den <head> schreibt. Titel und „noindex“ werden wie bei der eigenen Seite
	 * gesetzt; die Cache-Sperre hat der Router schon gesendet.
	 *
	 * @param array<string, mixed> $data
	 */
	private static function im_theme(array $data): void {
		self::$im_theme = $data;
		// Nach den Filtern des Themes (Divi: 99), damit dessen Vorbereitung für Kopf und Fuß schon gelaufen ist.
		add_filter('template_include', static fn(): string => KMM_PLUGIN_DIR . 'templates/frontend/layout-theme.php', 1000);
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
	}

	/** Steht eine Seite bereit, die WordPress im Rahmen der Website ausgeben soll? */
	public static function wartet_auf_theme(): bool {
		return self::$im_theme !== null;
	}

	/** Der fertige Portal-Inhalt für layout-theme.php. */
	public static function theme_inhalt(): string {
		$content = self::$im_theme['content'] ?? '';
		return is_string($content) ? $content : '';
	}

	/** Nur für Tests: gemerkte Seite verwerfen. */
	public static function theme_zuruecksetzen(): void {
		self::$im_theme = null;
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
