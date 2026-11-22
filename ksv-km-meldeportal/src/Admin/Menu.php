<?php
/**
 * Backend-Menü „KM-Portal".
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Admin;

use KSV\KMM\Auth\Capabilities;

final class Menu {

	public const SLUG = 'kmm';

	/**
	 * Menüpunkte in der Reihenfolge des Arbeitsablaufs, mit Gruppen-Überschrift.
	 *
	 * @var array<int, array{0: string, 1: class-string<AdminPage>, 2: string, 3: string}> Titel, Klasse, Capability, Gruppe
	 */
	private const PAGES = [
		['Übersicht', UebersichtPage::class, Capabilities::VIEW, ''],
		['Verarbeitung', VerarbeitungPage::class, Capabilities::VIEW, 'meldungen'],
		['Export', ExportPage::class, Capabilities::VIEW, 'meldungen'],
		['Belege', BelegePage::class, Capabilities::MANAGE, 'meldungen'],
		['Wettkampftage', WettkampftagePage::class, Capabilities::VIEW, 'wettkampf'],
		['Schießstände', SchiessstaendePage::class, Capabilities::MANAGE, 'wettkampf'],
		['Ergebnisse', ErgebnissePage::class, Capabilities::VIEW, 'wettkampf'],
		['Vereine', VereinePage::class, Capabilities::MANAGE, 'vereine'],
		['Sportjahre', SportjahrePage::class, Capabilities::MANAGE, 'saison'],
		['Stammdaten', StammdatenPage::class, Capabilities::MANAGE, 'saison'],
		['Import / Export', ImportExportPage::class, Capabilities::MANAGE, 'saison'],
		['Referenten', ReferentenPage::class, Capabilities::MANAGE, 'verwaltung'],
		['Protokoll', ProtokollPage::class, Capabilities::VIEW, 'verwaltung'],
		['Sicherung', SicherungPage::class, Capabilities::MANAGE, 'verwaltung'],
		['Einstellungen', SettingsPage::class, Capabilities::MANAGE, 'verwaltung'],
	];

	/** Überschriften der Menügruppen (per CSS über dem ersten sichtbaren Punkt der Gruppe). */
	public const GRUPPEN = [
		'meldungen'  => 'Meldungen',
		'wettkampf'  => 'Wettkampf',
		'vereine'    => 'Vereine',
		'saison'     => 'Saison einrichten',
		'verwaltung' => 'Verwaltung',
	];

	public static function register(): void {
		add_action('admin_menu', [self::class, 'add_pages']);
		add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
		add_action('admin_init', [self::class, 'handle_posts']);
		add_filter('admin_footer_text', [self::class, 'footer_text'], 20);
		add_action('admin_head', [self::class, 'menu_css']);
		add_action('wp_ajax_kmm_startplan_verschieben', [WettkampftagePage::class, 'ajax_verschieben']);
		add_action('wp_ajax_kmm_dg_panel', [WettkampftagePage::class, 'ajax_panel']);
	}

	/** POST-Aktionen der Seiten vor der Ausgabe verarbeiten (Redirect danach). */
	public static function handle_posts(): void {
		if (!isset($_POST['kmm_page'])) {
			return;
		}
		foreach (self::PAGES as [, $class]) {
			$class::handle_post();
		}
	}

	public static function add_pages(): void {
		add_menu_page(
			__('KM-Portal', 'ksv-km-meldeportal'),
			__('KM-Portal', 'ksv-km-meldeportal'),
			Capabilities::VIEW,
			self::SLUG,
			[UebersichtPage::class, 'render'],
			'dashicons-clipboard',
			58
		);
		foreach (self::PAGES as $i => [$title, $class, $cap]) {
			add_submenu_page(self::SLUG, __($title, 'ksv-km-meldeportal'), __($title, 'ksv-km-meldeportal'), $cap, $i === 0 ? self::SLUG : $class::SLUG, [$class, 'render']); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		}
		add_submenu_page(self::SLUG, __('System', 'ksv-km-meldeportal'), __('System', 'ksv-km-meldeportal'), Capabilities::MANAGE, 'kmm-system', [SystemPage::class, 'render']);
		self::gruppen_markieren();
	}

	/**
	 * Erster sichtbarer Menüpunkt jeder Gruppe bekommt eine Klasse, über der die
	 * Gruppen-Überschrift steht (WordPress übernimmt Index 4 als CSS-Klasse des <li>).
	 */
	private static function gruppen_markieren(): void {
		global $submenu;
		if (!isset($submenu[ self::SLUG ]) || !is_array($submenu[ self::SLUG ])) {
			return;
		}
		$gruppe_je_slug = ['kmm-system' => 'verwaltung'];
		foreach (self::PAGES as $i => [, $class, , $gruppe]) {
			$gruppe_je_slug[ $i === 0 ? self::SLUG : $class::SLUG ] = $gruppe;
		}
		$letzte = '';
		foreach ($submenu[ self::SLUG ] as $k => $item) {
			$gruppe = $gruppe_je_slug[ (string) ($item[2] ?? '') ] ?? '';
			if (!current_user_can((string) ($item[1] ?? ''))) {
				continue;
			}
			if ($gruppe !== '' && $gruppe !== $letzte) {
				$submenu[ self::SLUG ][ $k ][4] = trim(($item[4] ?? '') . ' kmm-gruppe kmm-gruppe-' . $gruppe); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
			$letzte = $gruppe;
		}
	}

	/** Gruppen-Überschriften im Menü; steht auf allen Backend-Seiten, weil das Menü überall sichtbar ist. */
	public static function menu_css(): void {
		$css = '#adminmenu .wp-submenu li.kmm-gruppe::before{display:block;padding:10px 12px 3px;font-size:10px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:#a7aaad;pointer-events:none}'
			. '#adminmenu .wp-submenu li.kmm-gruppe:first-child::before{padding-top:4px}'
			. '#adminmenu .wp-submenu-wrap li.kmm-gruppe:not(:first-child),#adminmenu .wp-has-current-submenu .wp-submenu li.kmm-gruppe{margin-top:4px}';
		foreach (self::GRUPPEN as $key => $label) {
			$css .= '#adminmenu .wp-submenu li.kmm-gruppe-' . $key . '::before{content:"' . esc_attr(__($label, 'ksv-km-meldeportal')) . '"}'; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		}
		echo '<style id="kmm-menu-gruppen">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function enqueue_assets(string $hook): void {
		if (strpos($hook, self::SLUG) === false) {
			return;
		}
		wp_enqueue_style('kmm-admin', KMM_PLUGIN_URL . 'assets/admin/admin.css', [], KMM_VERSION);
		// Dieselbe Schrift wie die Vereinsoberfläche (liegt im Plugin, kein externer Abruf).
		$font = KMM_PLUGIN_URL . 'assets/fonts/roboto-latin.woff2?v=' . rawurlencode(KMM_VERSION);
		wp_add_inline_style('kmm-admin', '@font-face{font-family:"Roboto";font-style:normal;font-weight:400 700;font-display:swap;src:url("' . esc_url_raw($font) . '") format("woff2")}');
		wp_enqueue_script('kmm-admin', KMM_PLUGIN_URL . 'assets/admin/admin.js', [], KMM_VERSION, true);
		wp_localize_script('kmm-admin', 'kmmAdmin', [
			'texte' => [
				'abbrechen' => __('Abbrechen', 'ksv-km-meldeportal'),
				'ok'        => __('Ja, fortfahren', 'ksv-km-meldeportal'),
				/* translators: %s: Text, der zur Bestätigung eingetippt werden muss */
				'eingabe'   => __('Zur Bestätigung bitte %s eintippen:', 'ksv-km-meldeportal'),
			],
		]);
		if (strpos($hook, ErgebnissePage::SLUG) !== false) {
			wp_enqueue_style('kmm-ergebnisse-admin', KMM_PLUGIN_URL . 'assets/admin/ergebnisse.css', ['kmm-admin'], KMM_VERSION);
		}
		if (strpos($hook, WettkampftagePage::SLUG) !== false) {
			wp_enqueue_script('kmm-startplan-matrix', KMM_PLUGIN_URL . 'assets/admin/startplan.js', [], KMM_VERSION, true);
			wp_localize_script('kmm-startplan-matrix', 'kmmStartplan', [
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce'   => wp_create_nonce('kmm_' . WettkampftagePage::SLUG),
				'texte'   => [
					'fehler'  => __('Das Verschieben hat nicht geklappt.', 'ksv-km-meldeportal'),
					'laeuft'  => __('wird gespeichert …', 'ksv-km-meldeportal'),
					'gewaehlt' => __('Gewählt. Jetzt den Zielplatz anklicken.', 'ksv-km-meldeportal'),
					'frei'    => __('frei', 'ksv-km-meldeportal'),
				],
			]);
			// Nutzt Adresse und Nonce aus kmmStartplan.
			wp_enqueue_script('kmm-wettkampftag', KMM_PLUGIN_URL . 'assets/admin/wettkampftag.js', ['kmm-startplan-matrix'], KMM_VERSION, true);
			wp_enqueue_script('kmm-startplan-filter', KMM_PLUGIN_URL . 'assets/admin/startplan-filter.js', [], KMM_VERSION, true);
		}
	}

	/**
	 * @param array<string, string|int> $args
	 */
	public static function url(string $page = self::SLUG, array $args = []): string {
		$args = array_merge(['page' => $page], $args);
		return add_query_arg($args, admin_url('admin.php'));
	}

	/** Seitenkopf; der Autorenhinweis steht in der Fußzeile (footer_text). */
	public static function page_header(string $title): void {
		echo '<h1 class="wp-heading-inline">' . esc_html($title) . '</h1>';
		echo '<hr class="wp-header-end">';
	}

	/** Fußzeile auf den Seiten des KM-Portals: Version und Autor statt „Danke für dein Vertrauen …“. */
	public static function footer_text(mixed $text): string {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ($screen === null || strpos((string) $screen->id, self::SLUG) === false) {
			return is_string($text) ? $text : '';
		}
		return sprintf(
			/* translators: 1: Versionsnummer, 2: verlinkter Autorenname */
			esc_html__('KM-Portal %1$s · Plugin von %2$s', 'ksv-km-meldeportal'),
			esc_html(KMM_VERSION),
			'<a href="https://github.com/hoepeflo" target="_blank" rel="noopener noreferrer">' . esc_html__('Florian Höper', 'ksv-km-meldeportal') . '</a>'
		);
	}
}
