<?php
/**
 * Öffentliche Ergebnisanzeige: Shortcode [kmm_ergebnisse], bei eingeschaltetem Schalter
 * „Anzeige übernehmen“ zusätzlich [srd_km] und die Adressen des bisherigen
 * Ergebnis-Plugins (/kreismeisterschaften/2025/, /2025/e/1_10_10/, …/raw/, /bogen/,
 * /blasrohr/ und die km_*-Parameter), damit Lesezeichen und Links weiter funktionieren.
 *
 * Solange das bisherige Plugin aktiv ist, übernimmt das KM-Portal nichts – auch nicht
 * bei eingeschaltetem Schalter. So gibt es nie zwei Anzeigen für dieselbe Adresse.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Http;

use KSV\KMM\Application\Ergebnisse\Dokumente;
use KSV\KMM\Application\Ergebnisse\Testjahr;
use KSV\KMM\Application\StartplanAnsicht;
use KSV\KMM\Domain\Ergebnis\Kategorie;
use KSV\KMM\Domain\Ergebnis\Schluessel;
use KSV\KMM\Infrastructure\Ergebnisablage;
use KSV\KMM\Infrastructure\Repository\DokumentRepository;
use KSV\KMM\Infrastructure\Repository\ErgebnisRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Support\ErgebnisEinstellungen;

final class ErgebnisFrontend {

	public const TAG = 'kmm_ergebnisse';
	public const SRD_TAG = 'srd_km';

	/** Alte Unterseiten des Ergebnis-Plugins → Sparte. */
	private const ALTE_SPARTEN = ['bogen' => 6, 'blasrohr' => 12];

	private const QUERY_VARS = ['srd_km_year', 'srd_km_discipline', 'srd_km_id', 'srd_km_art', 'srd_km_raw', 'srd_km_category'];

	private static bool $aktiv = false;

	public static function register(): void {
		add_shortcode(self::TAG, [self::class, 'shortcode']);
		// Erst bei init prüfen: das bisherige Plugin wird nach dem KM-Portal geladen.
		add_action('init', [self::class, 'init'], 6);
		add_action('wp_enqueue_scripts', [self::class, 'assets_registrieren']);
		// Wird das bisherige Plugin abgeschaltet, gelten ab dann die eigenen Regeln.
		add_action('deactivated_plugin', static function (string $plugin): void {
			if (str_starts_with($plugin, 'srd-kreismeisterschaften/')) {
				update_option('kmm_ergebnisse_rewrite_flush', 1, false);
			}
		});
	}

	/** Übernimmt das KM-Portal die alte Anzeige gerade? */
	public static function uebernimmt(): bool {
		return ErgebnisEinstellungen::uebernommen() && !ErgebnisEinstellungen::srd_aktiv();
	}

	public static function init(): void {
		self::$aktiv = self::uebernimmt();
		if (self::$aktiv) {
			if (!shortcode_exists(self::SRD_TAG)) {
				add_shortcode(self::SRD_TAG, [self::class, 'shortcode']);
			}
			add_filter('query_vars', [self::class, 'query_vars']);
			self::rewrites();
		}
		// Immer: auch [kmm_ergebnisse] allein braucht die Roh-Ausgabe für HTML-Ergebnisse.
		add_action('template_redirect', [self::class, 'template_redirect'], 0);
		if (get_option('kmm_ergebnisse_rewrite_flush')) {
			delete_option('kmm_ergebnisse_rewrite_flush');
			add_action('wp_loaded', static function (): void {
				flush_rewrite_rules(false);
			});
		}
	}

	/**
	 * @param list<string> $vars
	 * @return list<string>
	 */
	public static function query_vars(array $vars): array {
		return array_merge($vars, self::QUERY_VARS);
	}

	private static function pretty(): bool {
		return self::$aktiv && (int) ErgebnisEinstellungen::get('seite_id') > 0 && get_option('permalink_structure', '') !== '';
	}

	private static function slug(): string {
		$slug = sanitize_title((string) ErgebnisEinstellungen::get('slug'));
		return $slug !== '' ? $slug : 'kreismeisterschaften';
	}

	/** Dieselben Regeln wie im bisherigen Plugin (SRD_KM_Rewrite). */
	private static function rewrites(): void {
		$pid = (int) ErgebnisEinstellungen::get('seite_id');
		if ($pid <= 0 || get_option('permalink_structure', '') === '') {
			return;
		}
		$base = '^' . preg_quote(self::slug(), '#') . '/';
		$ziel = 'index.php?page_id=' . $pid;
		add_rewrite_rule($base . '([0-9]{4})/(e|m)/([^/]+)/raw/?$', $ziel . '&srd_km_year=$matches[1]&srd_km_art=$matches[2]&srd_km_id=$matches[3]&srd_km_raw=1', 'top');
		add_rewrite_rule($base . '([0-9]{4})/(e|m)/([^/]+)/?$', $ziel . '&srd_km_year=$matches[1]&srd_km_art=$matches[2]&srd_km_id=$matches[3]', 'top');
		add_rewrite_rule($base . '([0-9]{4})/bogen/?$', $ziel . '&srd_km_year=$matches[1]&srd_km_discipline=bogen', 'top');
		add_rewrite_rule($base . '([0-9]{4})/blasrohr/?$', $ziel . '&srd_km_year=$matches[1]&srd_km_discipline=blasrohr', 'top');
		add_rewrite_rule($base . '([0-9]{4})/?$', $ziel . '&srd_km_year=$matches[1]', 'top');
		add_rewrite_rule($base . '?$', $ziel, 'top');
	}

	public static function assets_registrieren(): void {
		wp_register_style('kmm-ergebnisse', KMM_PLUGIN_URL . 'assets/frontend/ergebnisse.css', [], KMM_VERSION);
	}

	// ----- Anfrage ---------------------------------------------------------------------------

	private static function anfrage(string $var, string $get): string {
		$v = get_query_var($var);
		if (is_scalar($v) && (string) $v !== '') {
			return (string) $v;
		}
		return isset($_GET[ $get ]) ? sanitize_text_field((string) wp_unslash($_GET[ $get ])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	}

	private static function jahr_anfrage(): int {
		$j = (int) self::anfrage('srd_km_year', 'km_year');
		return $j >= 1990 && $j <= 2100 ? $j : 0;
	}

	private static function sparte_anfrage(): int {
		$k = (int) self::anfrage('srd_km_category', 'km_category');
		$alt = strtolower(self::anfrage('srd_km_discipline', 'km_discipline'));
		if ($k === 0 && isset(self::ALTE_SPARTEN[ $alt ])) {
			$k = self::ALTE_SPARTEN[ $alt ];
		}
		return Kategorie::gibt_es($k) ? $k : 0;
	}

	/**
	 * Einzelnes Ergebnis aus der Anfrage, sonst null.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function ergebnis_anfrage(): ?array {
		$jahr = self::jahr_anfrage();
		$art = strtolower(self::anfrage('srd_km_art', 'km_art'));
		$id = Schluessel::normalisieren(self::anfrage('srd_km_id', 'km_id'));
		if ($jahr === 0 || !in_array($art, [Schluessel::EINZEL, Schluessel::MANNSCHAFT], true) || $id === null) {
			return null;
		}
		$r = (new ErgebnisRepository())->platz($jahr, $art, $id);
		return $r !== null && $r['status'] === ErgebnisRepository::STATUS_SICHTBAR ? $r : null;
	}

	/**
	 * Seite mit dem Ergebnis-Shortcode? Ohne Übernahme nur Seiten mit [kmm_ergebnisse],
	 * damit die Seite des bisherigen Plugins unberührt bleibt.
	 */
	private static function ist_ergebnisseite(): bool {
		$post = get_queried_object();
		$eigen = $post instanceof \WP_Post && has_shortcode($post->post_content, self::TAG);
		if (!self::$aktiv) {
			return $eigen;
		}
		$pid = (int) ErgebnisEinstellungen::get('seite_id');
		if ($pid > 0 && is_page($pid)) {
			return true;
		}
		return $eigen || ($post instanceof \WP_Post && has_shortcode($post->post_content, self::SRD_TAG));
	}

	/**
	 * Vor der Ausgabe: Roh-HTML für den iframe ausliefern, PDF-Adressen direkt zur Datei
	 * weiterleiten, alte /bogen/- und /blasrohr/-Adressen auf die Sparte umleiten.
	 */
	public static function template_redirect(): void {
		if (!self::ist_ergebnisseite()) {
			return;
		}
		$roh = self::anfrage('srd_km_raw', 'km_view');
		$hat_id = self::anfrage('srd_km_id', 'km_id') !== '';
		if ($hat_id) {
			$r = self::ergebnis_anfrage();
			if ($r === null) {
				if ($roh !== '') {
					status_header(404);
					nocache_headers();
					echo esc_html__('Datei nicht gefunden.', 'ksv-km-meldeportal');
					exit;
				}
				return;
			}
			$ablage = new Ergebnisablage();
			$datei = $ablage->pfad((string) $r['datei']);
			if ($datei === null) {
				return;
			}
			if ($r['format'] === 'pdf') {
				wp_redirect(esc_url_raw($ablage->url((string) $r['datei'])), 302); // phpcs:ignore WordPress.Security.SafeRedirect -- eigener Ergebnisordner aus den Einstellungen
				exit;
			}
			if ($roh === '1' || $roh === 'raw') {
				nocache_headers();
				header('Content-Type: text/html; charset=utf-8');
				header('X-Robots-Tag: noindex');
				// Eigener, abgeschotteter Ursprung: Skripte in alten HTML-Listen laufen nicht.
				header('Content-Security-Policy: sandbox; default-src \'none\'; style-src \'unsafe-inline\'; img-src * data:');
				header('X-Content-Type-Options: nosniff');
				readfile($datei);
				exit;
			}
			return;
		}
		$alt = strtolower(self::anfrage('srd_km_discipline', 'km_discipline'));
		if (isset(self::ALTE_SPARTEN[ $alt ]) && self::jahr_anfrage() > 0) {
			wp_safe_redirect(self::url(self::jahr_anfrage(), self::ALTE_SPARTEN[ $alt ]), 301);
			exit;
		}
	}

	// ----- Adressen --------------------------------------------------------------------------

	private static function basis_url(): string {
		if (self::pretty()) {
			return trailingslashit(home_url(user_trailingslashit(self::slug())));
		}
		$pid = (int) ErgebnisEinstellungen::get('seite_id');
		$url = $pid > 0 ? get_permalink($pid) : get_permalink();
		return is_string($url) && $url !== '' ? $url : home_url('/');
	}

	private static function url(int $jahr, int $sparte = 0): string {
		if (self::pretty()) {
			$url = trailingslashit(home_url(user_trailingslashit(self::slug() . '/' . $jahr)));
			return $sparte > 0 ? add_query_arg('km_category', $sparte, $url) : $url;
		}
		return add_query_arg(array_filter(['km_year' => $jahr, 'km_category' => $sparte]), self::basis_url());
	}

	/**
	 * Adresse eines Ergebnisses: PDF direkt, HTML über die Ergebnisseite (iframe).
	 *
	 * @param array<string, mixed> $r
	 */
	private static function ergebnis_url(array $r, Ergebnisablage $ablage, bool $roh = false): string {
		if ($r['format'] === 'pdf' && !$roh) {
			return $ablage->url((string) $r['datei']);
		}
		$jahr = (int) $r['jahr'];
		$art = (string) $r['art'];
		$id = strtolower((string) $r['schluessel']);
		if (self::pretty()) {
			return trailingslashit(home_url(user_trailingslashit(self::slug() . '/' . $jahr . '/' . $art . '/' . rawurlencode($id) . ($roh ? '/raw' : ''))));
		}
		$args = ['km_year' => $jahr, 'km_art' => $art, 'km_id' => $id];
		if ($roh) {
			$args['km_view'] = 'raw';
		}
		return add_query_arg($args, self::basis_url());
	}

	// ----- Ausgabe ---------------------------------------------------------------------------

	/**
	 * Jahre mit Inhalt (Ergebnisse, Unterlagen, Startpläne), neuestes zuerst.
	 *
	 * @return list<int>
	 */
	public static function jahre(): array {
		$jahre = array_merge((new ErgebnisRepository())->jahre_sichtbar(), (new DokumentRepository())->jahre());
		foreach ((new SportjahrRepository())->all() as $sj) {
			if (!Testjahr::ist_testjahr($sj) && StartplanAnsicht::tage((int) $sj['id']) !== []) {
				$jahre[] = (int) $sj['jahr'];
			}
		}
		$jahre = array_values(array_unique(array_map('intval', $jahre)));
		rsort($jahre);
		return $jahre;
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public static function shortcode($atts = []): string {
		wp_enqueue_style('kmm-ergebnisse');
		$jahre = self::jahre();
		$jahr = self::jahr_anfrage();
		if ($jahr === 0) {
			$jahr = $jahre[0] ?? (int) gmdate('Y');
		}
		$einzeln = self::ergebnis_anfrage();
		ob_start();
		echo '<div class="kmm-erg-front">';
		if ($einzeln !== null && $einzeln['format'] !== 'pdf') {
			self::einzelansicht($einzeln);
		} else {
			self::jahresansicht($jahr, $jahre, self::sparte_anfrage());
		}
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $r
	 */
	private static function einzelansicht(array $r): void {
		$ablage = new Ergebnisablage();
		$titel = trim(implode(' · ', array_filter([(string) $r['kennzahl'] . ' ' . (string) $r['disziplin_text'], (string) $r['klasse_text'], $r['art'] === 'm' ? __('Mannschaft', 'ksv-km-meldeportal') : __('Einzel', 'ksv-km-meldeportal')])));
		echo '<p class="kmm-erg-zurueck"><a href="' . esc_url(self::url((int) $r['jahr'])) . '">← ' . esc_html(sprintf(__('Alle Ergebnisse %d', 'ksv-km-meldeportal'), (int) $r['jahr'])) . '</a></p>';
		echo '<h2>' . esc_html($titel) . '</h2>';
		echo '<iframe class="kmm-erg-iframe" src="' . esc_url(self::ergebnis_url($r, $ablage, true)) . '" title="' . esc_attr($titel) . '" loading="lazy" sandbox referrerpolicy="no-referrer"></iframe>';
	}

	/**
	 * @param list<int> $jahre
	 */
	private static function jahresansicht(int $jahr, array $jahre, int $sparte): void {
		$ablage = new Ergebnisablage();
		echo '<div class="kmm-erg-kopf"><h2>' . esc_html(sprintf(__('Kreismeisterschaften %d', 'ksv-km-meldeportal'), $jahr)) . '</h2>';
		if (count($jahre) > 1) {
			echo '<label class="kmm-erg-jahrwahl">' . esc_html__('Jahr', 'ksv-km-meldeportal') . ' <select onchange="if(this.value){location.href=this.value;}">';
			foreach ($jahre as $j) {
				echo '<option value="' . esc_url(self::url($j, $sparte)) . '"' . selected($j, $jahr, false) . '>' . (int) $j . '</option>';
			}
			echo '</select></label>';
		}
		echo '</div>';

		self::unterlagen($jahr);

		$zeilen = (new ErgebnisRepository())->by_jahr($jahr, ErgebnisRepository::STATUS_SICHTBAR);
		if ($zeilen === []) {
			echo '<p class="kmm-erg-leer">' . esc_html__('Für dieses Jahr sind noch keine Ergebnisse veröffentlicht.', 'ksv-km-meldeportal') . '</p>';
			return;
		}
		$gruppen = self::gruppieren($zeilen);
		$sparten = array_values(array_unique(array_map(static fn(array $g): int => $g['sparte'], $gruppen)));
		sort($sparten);

		echo '<nav class="kmm-erg-sparten" aria-label="' . esc_attr__('Sparten', 'ksv-km-meldeportal') . '">';
		echo '<a href="' . esc_url(self::url($jahr)) . '"' . ($sparte === 0 ? ' aria-current="page"' : '') . '>' . esc_html__('Alle', 'ksv-km-meldeportal') . '</a>';
		foreach ($sparten as $k) {
			echo '<a href="' . esc_url(self::url($jahr, $k)) . '"' . ($sparte === $k ? ' aria-current="page"' : '') . '>' . esc_html(Kategorie::label($k)) . '</a>';
		}
		echo '</nav>';
		echo '<p class="kmm-erg-suchzeile"><input type="search" class="kmm-erg-suche" placeholder="' . esc_attr__('Disziplin oder Klasse suchen', 'ksv-km-meldeportal') . '" aria-label="' . esc_attr__('Ergebnisse durchsuchen', 'ksv-km-meldeportal') . '"></p>';

		$aktuelle = null;
		foreach ($gruppen as $g) {
			if ($sparte > 0 && $g['sparte'] !== $sparte) {
				continue;
			}
			if ($aktuelle !== $g['sparte']) {
				$aktuelle = $g['sparte'];
				echo '<h3 class="kmm-erg-sparte">' . esc_html(Kategorie::label($aktuelle)) . '</h3>';
			}
			echo '<section class="kmm-erg-disziplin" data-suche="' . esc_attr(mb_strtolower($g['titel'] . ' ' . implode(' ', array_keys($g['klassen'])))) . '">';
			echo '<header><h4><span class="kmm-erg-kz">' . esc_html($g['kennzahl']) . '</span> ' . esc_html($g['text']) . '</h4>';
			if ($g['sammel'] !== []) {
				echo '<span class="kmm-erg-gesamt">';
				foreach ($g['sammel'] as $art => $r) {
					echo '<a class="kmm-erg-btn is-gesamt" href="' . esc_url(self::ergebnis_url($r, $ablage)) . '">' . esc_html($art === 'm' ? __('Gesamtergebnis Mannschaft', 'ksv-km-meldeportal') : __('Gesamtergebnis', 'ksv-km-meldeportal')) . '</a>';
				}
				echo '</span>';
			}
			echo '</header>';
			if ($g['klassen'] !== []) {
				echo '<ul class="kmm-erg-klassen">';
				foreach ($g['klassen'] as $klasse => $arten) {
					echo '<li data-suche="' . esc_attr(mb_strtolower((string) $klasse)) . '"><span class="kmm-erg-klasse">' . esc_html((string) $klasse) . '</span><span class="kmm-erg-btns">';
					foreach (['e' => __('Einzel', 'ksv-km-meldeportal'), 'm' => __('Mannschaft', 'ksv-km-meldeportal')] as $art => $label) {
						if (isset($arten[ $art ])) {
							echo '<a class="kmm-erg-btn" href="' . esc_url(self::ergebnis_url($arten[ $art ], $ablage)) . '">' . esc_html($label) . '</a>';
						}
					}
					echo '</span></li>';
				}
				echo '</ul>';
			}
			if ($g['stand'] !== '') {
				echo '<p class="kmm-erg-datum">' . esc_html(sprintf(__('Stand: %s', 'ksv-km-meldeportal'), wp_date(get_option('date_format', 'd.m.Y'), (int) strtotime($g['stand'] . ' UTC')))) . '</p>';
			}
			echo '</section>';
		}
		self::suchskript();
	}

	/**
	 * Zeilen nach Disziplin gruppieren; Sammel-PDFs (ohne Klasse) als Gesamtergebnis.
	 *
	 * @param array<int, array<string, mixed>> $zeilen
	 * @return list<array{sparte: int, kennzahl: string, text: string, titel: string, sammel: array<string, array<string, mixed>>, klassen: array<string, array<string, array<string, mixed>>>, stand: string}>
	 */
	private static function gruppieren(array $zeilen): array {
		usort($zeilen, static fn(array $a, array $b): int => strcmp(Schluessel::sortierung((string) $a['schluessel']), Schluessel::sortierung((string) $b['schluessel'])) ?: strcmp((string) $a['art'], (string) $b['art']));
		$gruppen = [];
		foreach ($zeilen as $r) {
			$s = (string) $r['schluessel'];
			$d = Schluessel::disziplin_teil($s);
			if (!isset($gruppen[ $d ])) {
				$gruppen[ $d ] = ['sparte' => Schluessel::kategorie($s), 'kennzahl' => str_replace('_', '.', $d), 'text' => '', 'titel' => '', 'sammel' => [], 'klassen' => [], 'stand' => ''];
			}
			$g = &$gruppen[ $d ];
			if ($g['text'] === '' && (string) $r['disziplin_text'] !== '') {
				$g['text'] = (string) $r['disziplin_text'];
				$g['kennzahl'] = (string) $r['kennzahl'] !== '' ? (string) $r['kennzahl'] : $g['kennzahl'];
			}
			if (Schluessel::ist_sammel($s)) {
				$g['sammel'][ (string) $r['art'] ] = $r;
			} else {
				$klasse = (string) $r['klasse_text'] !== '' ? (string) $r['klasse_text'] : $s;
				$g['klassen'][ $klasse ][ (string) $r['art'] ] = $r;
			}
			$stand = (string) ($r['datei_stand'] ?? '');
			if ($stand > $g['stand']) {
				$g['stand'] = $stand;
			}
			$g['titel'] = $g['kennzahl'] . ' ' . $g['text'];
			unset($g);
		}
		return array_values($gruppen);
	}

	private static function unterlagen(int $jahr): void {
		$dokumente = (new Dokumente())->oeffentlich($jahr);
		$tage = [];
		$sj = (new SportjahrRepository())->by_jahr($jahr);
		if ($sj !== null && !Testjahr::ist_testjahr($sj)) {
			foreach (StartplanAnsicht::tage((int) $sj['id']) as $tag) {
				$url = !empty($tag['beitrag_id']) && get_post_status((int) $tag['beitrag_id']) === 'publish' ? get_permalink((int) $tag['beitrag_id']) : false;
				if (is_string($url)) {
					$tage[] = ['url' => $url, 'text' => trim(wp_date('d.m.Y', (int) strtotime((string) $tag['datum'] . ' 12:00 UTC')) . ' ' . (string) $tag['bezeichnung'])];
				}
			}
		}
		if ($dokumente === [] && $tage === []) {
			return;
		}
		echo '<div class="kmm-erg-unterlagen">';
		$allgemein = array_filter($dokumente, static fn(array $d): bool => $d['typ'] !== Dokumente::TYP_LICHT);
		$licht = array_filter($dokumente, static fn(array $d): bool => $d['typ'] === Dokumente::TYP_LICHT);
		if ($allgemein !== []) {
			echo '<h3>' . esc_html__('Ausschreibungen und Unterlagen', 'ksv-km-meldeportal') . '</h3><ul>';
			foreach ($allgemein as $d) {
				echo '<li><a href="' . esc_url((string) $d['link']) . '" target="_blank" rel="noopener">' . esc_html((string) $d['anzeige']) . '</a></li>';
			}
			echo '</ul>';
		}
		if ($tage !== []) {
			echo '<h3>' . esc_html__('Startpläne', 'ksv-km-meldeportal') . '</h3><ul>';
			foreach ($tage as $t) {
				echo '<li><a href="' . esc_url($t['url']) . '">' . esc_html($t['text']) . '</a></li>';
			}
			echo '</ul>';
		}
		if ($licht !== []) {
			echo '<h3>' . esc_html__('Lichtschießen', 'ksv-km-meldeportal') . '</h3><ul>';
			foreach ($licht as $d) {
				echo '<li><a href="' . esc_url((string) $d['link']) . '" target="_blank" rel="noopener">' . esc_html(basename((string) $d['url'])) . '</a></li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	private static function suchskript(): void {
		echo '<script>(function(){var s=document.querySelector(".kmm-erg-front .kmm-erg-suche");if(!s){return;}
s.addEventListener("input",function(){var q=s.value.toLowerCase().trim();
document.querySelectorAll(".kmm-erg-front .kmm-erg-disziplin").forEach(function(d){
var kopf=!q||d.querySelector("h4").textContent.toLowerCase().indexOf(q)!==-1,n=0;
d.querySelectorAll("li").forEach(function(li){var an=kopf||li.dataset.suche.indexOf(q)!==-1;li.hidden=!an;if(an){n++;}});
d.hidden=!kopf&&n===0;});
});})();</script>';
	}
}
