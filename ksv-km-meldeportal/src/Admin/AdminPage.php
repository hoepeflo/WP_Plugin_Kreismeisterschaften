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
	 * @param string                    $anker Sprungmarke (ohne #), an der die Seite wieder aufgeht
	 */
	protected static function redirect(string $message, string $type = 'success', array $args = [], string $anker = ''): never {
		$notices = get_transient(self::notice_key());
		$notices = is_array($notices) ? $notices : [];
		$notices[] = ['type' => $type, 'text' => $message];
		set_transient(self::notice_key(), $notices, 60);
		wp_safe_redirect(static::url($args) . ($anker !== '' ? '#' . rawurlencode($anker) : ''));
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

	/** Kennzahl-Präfix → Sparte, wie in der Vereinsoberfläche (assets/frontend/app.js). */
	private const SPARTEN = ['1' => 'Gewehr', '2' => 'Pistole', '3' => 'Flinte', '6' => 'Bogen', '7' => 'Vorderlader', '11' => 'Lichtschießen', '12' => 'Blasrohr'];

	/**
	 * Disziplinen einschränken – nach Sparten gruppiert, mit „alle“/„keine“ je Sparte und
	 * Suchfeld. Keine Auswahl bedeutet wie bisher: gilt für alle Disziplinen.
	 *
	 * Die Einschränkung ist der Ausnahmefall (Auflagetische, Bogenscheiben), deshalb steckt
	 * die Liste in einem zugeklappten `details`. Das klappt auch ohne JavaScript auf;
	 * das Skript ergänzt nur Zähler, „alle“/„keine“ und die Suche.
	 *
	 * @param string                                                          $feld     Feldname ohne []
	 * @param list<array{wert: string, kennzahl: string, bezeichnung: string}> $optionen
	 * @param list<string>                                                    $aktiv    gewählte Werte
	 * @param array{leer?: string, eine?: string, mehrere?: string}           $texte    Kopfzeile; %d = Anzahl
	 */
	protected static function disziplin_auswahl(string $feld, array $optionen, array $aktiv, array $texte = []): void {
		$texte += [
			'leer'    => __('Gilt für alle Disziplinen – zum Einschränken aufklappen', 'ksv-km-meldeportal'),
			'eine'    => __('Gilt nur für %d Disziplin', 'ksv-km-meldeportal'),
			'mehrere' => __('Gilt nur für %d Disziplinen', 'ksv-km-meldeportal'),
		];
		$sparten = [];
		foreach ($optionen as $o) {
			$praefix = explode('.', $o['kennzahl'])[0];
			$label = self::SPARTEN[ $praefix ] ?? __('Weitere', 'ksv-km-meldeportal');
			$sparten[ $label ][] = $o;
		}
		echo '<details class="kmm-dis-auswahl" ' . ($aktiv !== [] ? 'open' : '') . '
			data-leer="' . esc_attr($texte['leer']) . '" data-eine="' . esc_attr($texte['eine']) . '" data-mehrere="' . esc_attr($texte['mehrere']) . '">';
		echo '<summary><span class="kmm-dis-stand">' . esc_html(self::disziplin_zusammenfassung($texte, count($aktiv))) . '</span></summary>';
		echo '<div class="kmm-dis-liste">';
		echo '<p class="kmm-dis-suchzeile"><input type="search" class="kmm-dis-suche" placeholder="' . esc_attr__('Suchen, z. B. Auflage oder 1.11', 'ksv-km-meldeportal') . '" aria-label="' . esc_attr__('Disziplinen durchsuchen', 'ksv-km-meldeportal') . '"></p>';
		foreach ($sparten as $label => $liste) {
			echo '<div class="kmm-standgruppe kmm-dis-sparte" data-gesamt="' . count($liste) . '"><p><strong>' . esc_html((string) $label) . '</strong> <span class="description kmm-dis-anzahl">' . esc_html(sprintf(_n('%d Disziplin', '%d Disziplinen', count($liste), 'ksv-km-meldeportal'), count($liste))) . '</span> ';
			echo '<button type="button" class="button-link kmm-dis-alle" data-wert="1">' . esc_html__('alle', 'ksv-km-meldeportal') . '</button> · <button type="button" class="button-link kmm-dis-alle" data-wert="0">' . esc_html__('keine', 'ksv-km-meldeportal') . '</button></p>';
			echo '<div class="kmm-staende">';
			foreach ($liste as $o) {
				$an = in_array($o['wert'], $aktiv, true);
				echo '<label class="kmm-stand ' . ($an ? 'is-aktiv' : '') . '" data-suche="' . esc_attr(mb_strtolower($o['kennzahl'] . ' ' . $o['bezeichnung'])) . '">';
				echo '<input type="checkbox" name="' . esc_attr($feld) . '[]" value="' . esc_attr($o['wert']) . '" ' . checked($an, true, false) . '> ';
				echo '<span class="kmm-dis-kz">' . esc_html($o['kennzahl']) . '</span> ' . esc_html($o['bezeichnung']) . '</label>';
			}
			echo '</div></div>';
		}
		echo '</div></details>';
	}

	/**
	 * Text der Kopfzeile.
	 *
	 * @param array{leer: string, eine: string, mehrere: string} $texte
	 */
	private static function disziplin_zusammenfassung(array $texte, int $anzahl): string {
		if ($anzahl === 0) {
			return $texte['leer'];
		}
		return sprintf($anzahl === 1 ? $texte['eine'] : $texte['mehrere'], $anzahl);
	}

	/** Einmal je Seite: Zähler, „alle“/„keine“ und Suche der Disziplinauswahl. */
	protected static function disziplin_auswahl_script(): void {
		static $gesetzt = false;
		if ($gesetzt) {
			return;
		}
		$gesetzt = true;
		$texte = wp_json_encode(['von' => __('von', 'ksv-km-meldeportal')]);
		echo '<script>(function(){
var T = ' . ($texte !== false ? $texte : '{}') . ';
document.querySelectorAll(".kmm-dis-auswahl").forEach(function(box){
	var stand = box.querySelector(".kmm-dis-stand");
	function kaesten(){ return Array.prototype.slice.call(box.querySelectorAll("input[type=checkbox]")); }
	function zeigen(){
		var n = kaesten().filter(function(c){ return c.checked; }).length;
		var vorlage = n === 0 ? box.dataset.leer : (n === 1 ? box.dataset.eine : box.dataset.mehrere);
		stand.textContent = vorlage.replace("%d", n);
		kaesten().forEach(function(c){ c.closest(".kmm-stand").classList.toggle("is-aktiv", c.checked); });
	}
	box.addEventListener("change", zeigen);
	box.querySelectorAll(".kmm-dis-alle").forEach(function(b){
		b.addEventListener("click", function(){
			b.closest(".kmm-dis-sparte").querySelectorAll("input[type=checkbox]").forEach(function(c){
				if (c.closest(".kmm-stand").hidden) { return; }
				c.checked = b.dataset.wert === "1";
			});
			zeigen();
		});
	});
	box.querySelectorAll(".kmm-dis-anzahl").forEach(function(a){ a.dataset.voll = a.textContent; });
	var suche = box.querySelector(".kmm-dis-suche");
	if (suche) {
		suche.addEventListener("input", function(){
			var q = suche.value.trim().toLowerCase();
			box.querySelectorAll(".kmm-dis-sparte").forEach(function(gruppe){
				var sichtbar = 0;
				gruppe.querySelectorAll(".kmm-stand").forEach(function(l){
					var treffer = q === "" || (l.dataset.suche || "").indexOf(q) >= 0;
					l.hidden = !treffer;
					if (treffer) { sichtbar++; }
				});
				gruppe.hidden = sichtbar === 0;
				var anzahl = gruppe.querySelector(".kmm-dis-anzahl");
				if (anzahl) { anzahl.textContent = q === "" ? anzahl.dataset.voll : sichtbar + " " + T.von + " " + gruppe.dataset.gesamt; }
			});
		});
	}
	zeigen();
});
})();</script>';
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
