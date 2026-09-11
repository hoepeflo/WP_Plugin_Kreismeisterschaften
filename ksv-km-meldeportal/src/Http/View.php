<?php
/**
 * Serverseitiges Rendern der Templates aus templates/ mit gemeinsamem Layout.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Http;

final class View {

	/**
	 * Rendert ein Frontend-Template innerhalb des Layouts und gibt es aus.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function render(string $template, array $data = []): void {
		$content = self::capture($template, $data);
		$data['content'] = $content;
		echo self::capture('frontend/layout', $data); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Templates escapen selbst.
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
