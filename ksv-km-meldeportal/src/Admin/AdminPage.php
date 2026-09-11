<?php
/**
 * Gemeinsame Helfer der Backend-Seiten: Rechteprüfung, Nonces, Hinweise (PRG-Muster),
 * Formularfelder, Sportjahr-Auswahl.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Admin;

use KSV\KMM\Auth\Capabilities;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;

abstract class AdminPage {

	/** Slug der Seite (page=…). */
	public const SLUG = '';

	abstract public static function render(): void;

	/** Verarbeitet POST-Aktionen vor der Ausgabe (admin_init), leitet danach um. */
	public static function handle_post(): void {
	}

	protected static function require_manage(): void {
		if (!current_user_can(Capabilities::MANAGE)) {
			wp_die(esc_html__('Keine Berechtigung.', 'ksv-km-meldeportal'));
		}
	}

	protected static function is_own_post(): bool {
		return isset($_POST['kmm_page']) && $_POST['kmm_page'] === static::SLUG && isset($_POST['kmm_action']);
	}

	protected static function action(): string {
		return isset($_POST['kmm_action']) ? sanitize_key((string) wp_unslash($_POST['kmm_action'])) : '';
	}

	/** Prüft Nonce und Recht für eine POST-Aktion. */
	protected static function verify(): void {
		static::require_manage();
		check_admin_referer('kmm_' . static::SLUG);
	}

	/**
	 * @param array<string, string|int> $args
	 */
	protected static function url(array $args = []): string {
		return Menu::url(static::SLUG, $args);
	}

	protected static function form_open(string $action, string $extra_attr = ''): void {
		echo '<form method="post" action="' . esc_url(static::url()) . '" ' . $extra_attr . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_nonce_field('kmm_' . static::SLUG);
		echo '<input type="hidden" name="kmm_page" value="' . esc_attr(static::SLUG) . '">';
		echo '<input type="hidden" name="kmm_action" value="' . esc_attr($action) . '">';
	}

	/**
	 * Hinweis für die nächste Seitenansicht merken und umleiten.
	 *
	 * @param array<string, string|int> $args
	 */
	protected static function redirect(string $message, string $type = 'success', array $args = []): never {
		$notices = get_transient(self::notice_key());
		$notices = is_array($notices) ? $notices : [];
		$notices[] = ['type' => $type, 'text' => $message];
		set_transient(self::notice_key(), $notices, 60);
		wp_safe_redirect(static::url($args));
		exit;
	}

	protected static function show_notices(): void {
		$notices = get_transient(self::notice_key());
		if (!is_array($notices)) {
			return;
		}
		delete_transient(self::notice_key());
		foreach ($notices as $n) {
			$type = in_array($n['type'], ['success', 'error', 'warning', 'info'], true) ? $n['type'] : 'info';
			echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . nl2br(esc_html((string) $n['text'])) . '</p></div>';
		}
	}

	private static function notice_key(): string {
		return 'kmm_notice_' . get_current_user_id();
	}

	protected static function post_str(string $key, int $max = 255): string {
		if (!isset($_POST[ $key ])) {
			return '';
		}
		return mb_substr(sanitize_text_field((string) wp_unslash($_POST[ $key ])), 0, $max);
	}

	protected static function post_text(string $key): string {
		return isset($_POST[ $key ]) ? sanitize_textarea_field((string) wp_unslash($_POST[ $key ])) : '';
	}

	protected static function post_int(string $key): int {
		return isset($_POST[ $key ]) ? (int) $_POST[ $key ] : 0;
	}

	protected static function post_int_or_null(string $key): ?int {
		if (!isset($_POST[ $key ]) || trim((string) $_POST[ $key ]) === '') {
			return null;
		}
		return (int) $_POST[ $key ];
	}

	protected static function post_float_or_null(string $key): ?float {
		if (!isset($_POST[ $key ]) || trim((string) $_POST[ $key ]) === '') {
			return null;
		}
		return round((float) str_replace(',', '.', (string) $_POST[ $key ]), 2);
	}

	protected static function post_bool(string $key): bool {
		return !empty($_POST[ $key ]);
	}

	/**
	 * @param array<int|string, string> $options Wert => Beschriftung
	 */
	protected static function select(string $name, array $options, mixed $selected, string $attr = ''): string {
		$html = '<select name="' . esc_attr($name) . '" ' . $attr . '>';
		foreach ($options as $value => $label) {
			$html .= '<option value="' . esc_attr((string) $value) . '"' . selected((string) $selected, (string) $value, false) . '>' . esc_html($label) . '</option>';
		}
		return $html . '</select>';
	}

	protected static function input(string $name, mixed $value, string $type = 'text', string $attr = ''): string {
		return '<input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '" ' . $attr . '>';
	}

	protected static function checkbox(string $name, bool $checked, string $label = ''): string {
		$html = '<label><input type="checkbox" name="' . esc_attr($name) . '" value="1"' . checked($checked, true, false) . '> ' . esc_html($label) . '</label>';
		return $html;
	}

	/**
	 * Sportjahr aus GET (sportjahr=ID) oder das aktive Sportjahr.
	 *
	 * @return array<string, mixed>|null
	 */
	protected static function current_sportjahr(): ?array {
		$repo = new SportjahrRepository();
		$id = isset($_GET['sportjahr']) ? (int) $_GET['sportjahr'] : 0;
		if ($id > 0) {
			$row = $repo->find($id);
			if ($row !== null) {
				return $row;
			}
		}
		return $repo->aktiv() ?? ($repo->all()[0] ?? null);
	}

	/**
	 * Auswahlliste der Sportjahre als GET-Formular.
	 *
	 * @param array<string, mixed>|null $current
	 * @param array<string, string>     $keep Weitere GET-Parameter, die erhalten bleiben.
	 */
	protected static function sportjahr_selector(?array $current, array $keep = []): void {
		$repo = new SportjahrRepository();
		$all = $repo->all();
		echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="kmm-inline-form">';
		echo '<input type="hidden" name="page" value="' . esc_attr(static::SLUG) . '">';
		foreach ($keep as $k => $v) {
			echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
		}
		$options = [];
		foreach ($all as $row) {
			$options[ (int) $row['id'] ] = sprintf('%d – %s%s', (int) $row['jahr'], (string) $row['bezeichnung'], $row['ist_aktiv'] ? ' (aktiv)' : '');
		}
		echo '<label>' . esc_html__('Sportjahr', 'ksv-km-meldeportal') . ' ' . self::select('sportjahr', $options, $current['id'] ?? 0, 'onchange="this.form.submit()"') . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<noscript><button class="button">' . esc_html__('Wechseln', 'ksv-km-meldeportal') . '</button></noscript>';
		echo '</form>';
	}

	protected static function no_sportjahr_notice(): void {
		echo '<div class="notice notice-warning"><p>' . sprintf(
			/* translators: %s: Link zur Sportjahr-Seite */
			esc_html__('Es ist noch kein Sportjahr angelegt. Bitte zuerst unter %s ein Sportjahr anlegen.', 'ksv-km-meldeportal'),
			'<a href="' . esc_url(Menu::url(SportjahrePage::SLUG)) . '">' . esc_html__('Sportjahre', 'ksv-km-meldeportal') . '</a>'
		) . '</p></div>';
	}

	protected static function geld(float|string|null $betrag): string {
		if ($betrag === null || $betrag === '') {
			return '–';
		}
		return number_format((float) $betrag, 2, ',', '.') . ' €';
	}
}
