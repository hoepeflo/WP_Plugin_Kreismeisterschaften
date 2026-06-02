<?php
/**
 * Shortcode, Assets und KM-Ansichten.
 *
 * @package SRD_Kreismeisterschaften
 */

if (!defined('ABSPATH')) {
	exit;
}

class SRD_KM_Frontend {

	private static ?self $instance = null;

	public static function instance(): self {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode('srd_km', array($this, 'shortcode'));
		add_action('wp_enqueue_scripts', array($this, 'register_assets'));
		add_action('template_redirect', array($this, 'maybe_serve_raw_html'), 0);
		add_action('wp_ajax_srd_km_disciplines', array($this, 'ajax_disciplines_list'));
		add_action('wp_ajax_nopriv_srd_km_disciplines', array($this, 'ajax_disciplines_list'));
	}

	public function register_assets(): void {
		wp_register_style(
			'srd-km-bootstrap',
			'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css',
			array(),
			'5.3.2'
		);
		wp_register_style(
			'srd-km-icons',
			'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css',
			array(),
			'1.11.1'
		);
		wp_register_style(
			'srd-km-embed',
			SRD_KM_PLUGIN_URL . 'assets/km-embed.css',
			array('srd-km-bootstrap'),
			SRD_KM_VERSION
		);
		wp_register_script(
			'srd-km-bootstrap',
			'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js',
			array(),
			'5.3.2',
			true
		);
		wp_register_script(
			'srd-km-disciplines',
			SRD_KM_PLUGIN_URL . 'assets/km-disciplines.js',
			array(),
			SRD_KM_VERSION,
			true
		);
	}

	/**
	 * @param int[] $years
	 */
	private function enqueue_disciplines_script(int $year, int $category, array $years): void {
		wp_enqueue_script('srd-km-disciplines');
		wp_localize_script(
			'srd-km-disciplines',
			'srdKmDisciplines',
			array(
				'ajaxUrl'       => admin_url('admin-ajax.php'),
				'nonce'         => wp_create_nonce('srd_km_disciplines'),
				'action'        => 'srd_km_disciplines',
				'year'          => $year,
				'category'      => $category,
				'years'         => array_map('intval', $years),
				'usePrettyUrls' => $this->use_pretty_urls(),
				'baseUrl'       => $this->km_base_url(),
				'strings'       => array(
					'loadError' => __('Die Disziplinenliste konnte nicht geladen werden.', 'srd-kreismeisterschaften'),
				),
			)
		);
	}

	public function ajax_disciplines_list(): void {
		check_ajax_referer('srd_km_disciplines', 'nonce');

		$year = isset($_POST['year']) ? absint(wp_unslash($_POST['year'])) : 0;
		if ($year < 1990 || $year > 2100) {
			wp_send_json_error(array('message' => 'invalid year'), 400);
		}

		$r = $this->results_paths();
		if (!is_dir($r['path'])) {
			wp_send_json_error(array('message' => 'results missing'), 500);
		}

		$lists = $this->get_disciplines_lists_html($year, SRD_KM_DB::kreis_rows_v3(), $r);
		$category = isset($_POST['category']) ? absint(wp_unslash($_POST['category'])) : 0;
		if ($category > 0 && !SRD_KM_Categories::is_valid($category)) {
			$category = 0;
		}

		wp_send_json_success(
			array(
				'year'  => $year,
				'cards' => $lists['cards'],
				'tbody' => $lists['tbody'],
			)
		);
	}

	/**
	 * @return array{path: string, url: string}
	 */
	private function results_paths(): array {
		$s = srd_km_get_settings();
		$path = isset($s['results_path']) ? (string) $s['results_path'] : '';
		$url = isset($s['results_url']) ? (string) $s['results_url'] : '';
		if ($path === '') {
			$path = trailingslashit(WP_CONTENT_DIR) . 'uploads/srd-results';
		}
		if ($url === '') {
			$url = content_url('/uploads/srd-results');
		}
		return array(
			'path' => untrailingslashit($path),
			'url'  => untrailingslashit($url),
		);
	}

	private function use_pretty_urls(): bool {
		$s = srd_km_get_settings();
		if (empty($s['rewrite_enabled']) || empty($s['page_id'])) {
			return false;
		}
		if (get_option('permalink_structure', '') === '') {
			return false;
		}
		return true;
	}

	private function rewrite_slug(): string {
		$s = srd_km_get_settings();
		$slug = isset($s['rewrite_slug']) ? sanitize_title((string) $s['rewrite_slug']) : 'kreismeisterschaften';
		return $slug !== '' ? $slug : 'kreismeisterschaften';
	}

	/**
	 * Basis-URL der KM-Oberfläche (Shortcode-Seite oder Pretty-Slug).
	 */
	private function km_base_url(): string {
		if ($this->use_pretty_urls()) {
			return trailingslashit(home_url(user_trailingslashit($this->rewrite_slug())));
		}
		$s = srd_km_get_settings();
		$page_id = (int) ($s['page_id'] ?? 0);
		if ($page_id > 0) {
			return trailingslashit(get_permalink($page_id) ?: home_url('/'));
		}
		return trailingslashit(get_permalink() ?: home_url('/'));
	}

	private function home_breadcrumb_url(): string {
		$s = srd_km_get_settings();
		$h = isset($s['home_url_custom']) ? trim((string) $s['home_url_custom']) : '';
		return $h !== '' ? $h : home_url('/');
	}

	/**
	 * Roh-HTML für iframe (Pretty oder Query-Parameter).
	 */
	private function km_raw_url(int $year, string $art, string $id): string {
		if ($this->use_pretty_urls()) {
			$path = $this->rewrite_slug() . '/' . $year . '/' . $art . '/' . rawurlencode($id) . '/raw/';
			return trailingslashit(home_url(user_trailingslashit($path)));
		}
		return add_query_arg(
			array(
				'km_view' => 'raw',
				'km_year' => (string) $year,
				'km_id'   => $id,
				'km_art'  => $art,
			),
			$this->km_base_url()
		);
	}

	/**
	 * @param array<string, string> $args km_year, km_discipline, km_id, km_art
	 */
	private function km_url(array $args = array()): string {
		$category = isset($args['km_category']) ? absint($args['km_category']) : 0;
		if ($category > 0 && !SRD_KM_Categories::is_valid($category)) {
			unset($args['km_category']);
			$category = 0;
		}

		if (!$this->use_pretty_urls()) {
			return add_query_arg($args, $this->km_base_url());
		}

		$slug = $this->rewrite_slug();
		$year = isset($args['km_year']) ? absint($args['km_year']) : 0;
		$disc = isset($args['km_discipline']) ? sanitize_key((string) $args['km_discipline']) : '';
		$id = isset($args['km_id']) ? (string) $args['km_id'] : '';
		$art = isset($args['km_art']) ? (string) $args['km_art'] : '';

		$path = $slug . '/';
		if ($year > 0 && $id !== '' && $art !== '' && in_array($art, array('e', 'm'), true) && $this->is_safe_file_id($id)) {
			$path .= $year . '/' . $art . '/' . rawurlencode($id) . '/';
		} elseif ($year > 0 && $disc === 'bogen') {
			$path .= $year . '/bogen/';
		} elseif ($year > 0 && $disc === 'blasrohr') {
			$path .= $year . '/blasrohr/';
		} elseif ($year > 0) {
			$path .= $year . '/';
		}

		$url = trailingslashit(home_url(user_trailingslashit($path)));
		if ($category > 0) {
			$url = add_query_arg('km_category', (string) $category, $url);
		}
		unset($args['km_year'], $args['km_discipline'], $args['km_id'], $args['km_art'], $args['km_category']);
		if ($args !== array()) {
			$url = add_query_arg($args, $url);
		}
		return $url;
	}

	private function request_year(): int {
		$y = get_query_var('srd_km_year');
		if ($y !== '' && $y !== false && $y !== null) {
			return absint($y);
		}
		return isset($_GET['km_year']) ? absint(wp_unslash($_GET['km_year'])) : 0;
	}

	private function request_discipline(): string {
		$d = get_query_var('srd_km_discipline');
		if (is_string($d) && $d !== '') {
			return sanitize_key($d);
		}
		return isset($_GET['km_discipline']) ? sanitize_key((string) wp_unslash($_GET['km_discipline'])) : '';
	}

	private function request_id(): string {
		$i = get_query_var('srd_km_id');
		if (is_string($i) && $i !== '') {
			return (string) wp_unslash($i);
		}
		return isset($_GET['km_id']) ? (string) wp_unslash($_GET['km_id']) : '';
	}

	private function request_art(): string {
		$a = get_query_var('srd_km_art');
		if (is_string($a) && $a !== '') {
			return (string) wp_unslash($a);
		}
		return isset($_GET['km_art']) ? (string) wp_unslash($_GET['km_art']) : '';
	}

	private function request_category(): int {
		$c = get_query_var('srd_km_category');
		if ($c !== '' && $c !== false && $c !== null) {
			$cat = absint($c);
			return SRD_KM_Categories::is_valid($cat) ? $cat : 0;
		}
		if (isset($_GET['km_category'])) {
			$cat = absint(wp_unslash($_GET['km_category']));
			return SRD_KM_Categories::is_valid($cat) ? $cat : 0;
		}
		return 0;
	}

	private function request_is_raw(): bool {
		$r = get_query_var('srd_km_raw');
		if ($r === '1' || $r === 1) {
			return true;
		}
		return isset($_GET['km_view']) && (string) wp_unslash($_GET['km_view']) === 'raw';
	}

	/**
	 * Erlaubt nur sichere Datei-IDs aus der Datenbank (datei-Feld).
	 */
	private function is_safe_file_id(string $id): bool {
		return (bool) preg_match('/^[a-zA-Z0-9_-]+$/', $id);
	}

	/**
	 * Absoluter Pfad zu results/km_YEAR/e|mID.ext – nur wenn unter results_path.
	 */
	private function resolve_under_results(string $relative): ?string {
		$r = $this->results_paths();
		$base = $r['path'];
		$realBase = realpath($base);
		if ($realBase === false || !is_dir($realBase)) {
			return null;
		}
		$full = $base . '/' . ltrim(str_replace(array('..', "\0"), '', $relative), '/');
		$real = realpath($full);
		if ($real === false || !is_file($real)) {
			return null;
		}
		$prefix = $realBase . DIRECTORY_SEPARATOR;
		if (strncmp($real, $prefix, strlen($prefix)) !== 0) {
			return null;
		}
		return $real;
	}

	public function maybe_serve_raw_html(): void {
		if (!$this->request_is_raw()) {
			return;
		}
		$s = srd_km_get_settings();
		$page_id = (int) ($s['page_id'] ?? 0);
		if ($page_id > 0) {
			if (!is_page($page_id)) {
				return;
			}
		} elseif (!is_singular()) {
			return;
		} else {
			$post = get_queried_object();
			if (!$post instanceof WP_Post || !has_shortcode($post->post_content, 'srd_km')) {
				return;
			}
		}
		$year = $this->request_year();
		$id = $this->request_id();
		$art = $this->request_art();
		if ($year < 1990 || $year > 2100 || !$this->is_safe_file_id($id) || !in_array($art, array('e', 'm'), true)) {
			status_header(400);
			nocache_headers();
			echo esc_html__('Ungültige Parameter.', 'srd-kreismeisterschaften');
			exit;
		}
		$rel = 'km_' . $year . '/' . $art . $id . '.html';
		$file = $this->resolve_under_results($rel);
		if ($file === null) {
			status_header(404);
			nocache_headers();
			echo esc_html__('Datei nicht gefunden.', 'srd-kreismeisterschaften');
			exit;
		}
		nocache_headers();
		header('Content-Type: text/html; charset=utf-8');
		readfile($file);
		exit;
	}

	private function enqueue_km_assets(): void {
		wp_enqueue_style('srd-km-bootstrap');
		wp_enqueue_style('srd-km-icons');
		wp_enqueue_style('srd-km-embed');
		wp_enqueue_script('srd-km-bootstrap');
	}

	/**
	 * @param array<string, string> $atts
	 */
	public function shortcode($atts): string {
		$this->enqueue_km_assets();
		$s = srd_km_get_settings();
		$admin_tip = '';
		if (SRD_KM_Capabilities::user_can_manage() && empty($s['page_id'])) {
			$admin_tip = '<div class="alert alert-warning">' .
				esc_html__('Tipp: Unter Einstellungen → SRD Kreismeisterschaften die KM-Seite festlegen, damit alle Links stabil auf dieselbe URL zeigen.', 'srd-kreismeisterschaften') .
				'</div>';
		}
		if (SRD_KM_Capabilities::user_can_manage() && !empty($s['rewrite_enabled']) && get_option('permalink_structure', '') === '') {
			$admin_tip .= '<div class="alert alert-info">' .
				esc_html__('Pretty-URLs sind aktiviert, aber WordPress nutzt noch „Einfache“ Permalinks. Unter Einstellungen → Permalinks eine andere Struktur wählen und speichern.', 'srd-kreismeisterschaften') .
				'</div>';
		}
		$r = $this->results_paths();
		if (!is_dir($r['path'])) {
			return $admin_tip . '<div class="srd-km-wrap"><div class="alert alert-danger">' .
				esc_html__('Der konfigurierte results-Ordner existiert nicht oder ist nicht lesbar.', 'srd-kreismeisterschaften') .
				'</div></div>';
		}

		$year = $this->request_year();
		$year = ($year > 0) ? $year : 0;
		$category = $this->request_category();
		$id = $this->request_id();
		$art = $this->request_art();

		if ($year > 0 && $id !== '' && $art !== '' && in_array($art, array('e', 'm'), true) && $this->is_safe_file_id($id)) {
			$body = $this->render_html_result($year, $id, $art);
		} else {
			$years = $this->available_years();
			if ($year <= 0) {
				$year = $this->default_year($years);
			}
			$body = $this->render_disciplines_view($year, $category, $years);
		}

		return $admin_tip . $body;
	}

	/**
	 * @return int[]
	 */
	private function available_years(): array {
		$years = SRD_KM_DB::distinct_sportjahre();
		if ($years === array()) {
			$years = array(2026, 2025, 2024, 2023, 2022, 2021, 2020, 2019, 2018, 2017, 2016);
		}
		return $this->merge_sportjahr_season_preview($years);
	}

	/**
	 * @param int[] $years
	 */
	private function default_year(array $years): int {
		if ($years !== array()) {
			return (int) $years[0];
		}
		return (int) gmdate('Y');
	}

	/**
	 * @param int[] $years
	 */
	private function render_disciplines_view(int $jahr, int $category_filter, array $years): string {
		$this->enqueue_disciplines_script($jahr, $category_filter, $years);

		$r = $this->results_paths();
		$rows = SRD_KM_DB::kreis_rows_v3();
		$lists = $this->get_disciplines_lists_html($jahr, $rows, $r);

		ob_start();
		?>
		<div class="srd-km-wrap container-fluid py-2">
			<div class="card shadow-sm">
				<div class="card-header bg-primary text-white srd-km-card-title">
					<h2 class="h4 mb-0 srd-km-page-title"><i class="bi bi-trophy me-2"></i><?php esc_html_e('Kreismeisterschaften', 'srd-kreismeisterschaften'); ?></h2>
				</div>
				<div id="srd-km-documents-wrap">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_frontend_html escapt Ausgabe
					echo SRD_KM_Documents::render_frontend_html();
					?>
				</div>
				<div class="card-body border-bottom srd-km-category-filter">
					<p class="small text-muted mb-2"><?php esc_html_e('Nach Kategorie filtern', 'srd-kreismeisterschaften'); ?></p>
					<?php $this->render_category_filter($jahr, $category_filter); ?>
				</div>
				<div
					id="srd-km-disciplines-panel"
					class="card-body p-0 srd-km-disciplines-panel"
					data-year="<?php echo esc_attr((string) $jahr); ?>"
					data-category="<?php echo esc_attr((string) $category_filter); ?>"
				>
					<?php $this->render_year_toolbar($jahr, $years); ?>
					<div id="srd-km-disciplines-cards" class="srd-km-disciplines-cards d-md-none" aria-label="<?php esc_attr_e('Disziplinen', 'srd-kreismeisterschaften'); ?>">
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_disciplines_lists_html escapt alle Ausgaben
						echo $lists['cards'];
						?>
					</div>
					<div class="table-responsive d-none d-md-block">
						<table class="table table-hover table-striped mb-0 srd-km-disciplines-table">
							<thead class="table-primary">
								<tr>
									<th><?php esc_html_e('Disziplin', 'srd-kreismeisterschaften'); ?></th>
									<th><?php esc_html_e('Klasse', 'srd-kreismeisterschaften'); ?></th>
									<th><?php esc_html_e('SpO', 'srd-kreismeisterschaften'); ?></th>
									<th><?php esc_html_e('Änderungsdatum', 'srd-kreismeisterschaften'); ?></th>
									<th><?php esc_html_e('Einzel', 'srd-kreismeisterschaften'); ?></th>
									<th><?php esc_html_e('Mannschaft', 'srd-kreismeisterschaften'); ?></th>
								</tr>
							</thead>
							<tbody id="srd-km-disciplines-tbody">
								<?php
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_disciplines_lists_html escapt alle Ausgaben
								echo $lists['tbody'];
								?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @param array{path: string, url: string} $r
	 * @return array{cards: string, tbody: string}
	 */
	private function get_disciplines_lists_html(int $jahr, array $rows, array $r): array {
		$extPreferred = ($jahr >= 2024) ? 'pdf' : 'html';

		$prepared = array();
		foreach ($rows as $dsatz) {
			$row = $this->prepare_discipline_row($dsatz, $jahr, $extPreferred, $r);
			if ($row !== null) {
				$prepared[] = $row;
			}
		}
		usort($prepared, array(SRD_KM_DB::class, 'compare_kreis_rows_by_disziplin'));

		ob_start();
		foreach ($prepared as $row) {
			$this->render_discipline_card($row);
		}
		$this->render_disciplines_empty_placeholders(true);
		$cards = (string) ob_get_clean();

		ob_start();
		foreach ($prepared as $row) {
			$this->render_discipline_table_row($row);
		}
		$this->render_disciplines_empty_placeholders(false);
		$tbody = (string) ob_get_clean();

		return array(
			'cards' => $cards,
			'tbody' => $tbody,
		);
	}

	private function render_category_filter(int $jahr, int $category_filter): void {
		$all_active = ($category_filter === 0) ? ' active' : '';
		$all_url = $this->km_url(array( 'km_year' => (string) $jahr ));
		?>
		<div class="d-flex flex-wrap gap-2" role="group" aria-label="<?php esc_attr_e('Kategoriefilter', 'srd-kreismeisterschaften'); ?>">
			<a
				href="<?php echo esc_url($all_url); ?>"
				class="btn btn-sm btn-outline-primary<?php echo esc_attr($all_active); ?>"
				data-srd-km-category="0"
				aria-pressed="<?php echo $category_filter === 0 ? 'true' : 'false'; ?>"
			>
				<?php esc_html_e('Alle', 'srd-kreismeisterschaften'); ?>
			</a>
			<?php foreach (SRD_KM_Categories::labels() as $cat_id => $cat_label) : ?>
				<?php
				$cat_active = ($category_filter === $cat_id) ? ' active' : '';
				$cat_url = $this->km_url(
					array(
						'km_year'     => (string) $jahr,
						'km_category' => (string) $cat_id,
					)
				);
				?>
				<a
					href="<?php echo esc_url($cat_url); ?>"
					class="btn btn-sm srd-km-cat-filter <?php echo esc_attr(SRD_KM_Categories::color_class($cat_id)); ?><?php echo esc_attr($cat_active); ?>"
					data-srd-km-category="<?php echo esc_attr((string) $cat_id); ?>"
					aria-pressed="<?php echo $category_filter === $cat_id ? 'true' : 'false'; ?>"
				>
					<?php echo esc_html($cat_label); ?>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function render_disciplines_empty_placeholders(bool $as_paragraph): void {
		if ($as_paragraph) {
			echo '<p class="srd-km-empty-message text-center text-muted py-4 px-3 mb-0 d-none" data-srd-km-empty="category">';
			esc_html_e('Für diese Kategorie liegen noch keine Ergebnisse vor.', 'srd-kreismeisterschaften');
			echo '</p>';
			echo '<p class="srd-km-empty-message text-center text-muted py-4 px-3 mb-0 d-none" data-srd-km-empty="year">';
			esc_html_e('Für dieses Sportjahr liegen noch keine Ergebnisse vor.', 'srd-kreismeisterschaften');
			echo '</p>';
			return;
		}
		?>
		<tr class="srd-km-empty-row d-none" data-srd-km-empty="category">
			<td colspan="6" class="text-center text-muted py-4">
				<?php esc_html_e('Für diese Kategorie liegen noch keine Ergebnisse vor.', 'srd-kreismeisterschaften'); ?>
			</td>
		</tr>
		<tr class="srd-km-empty-row d-none" data-srd-km-empty="year">
			<td colspan="6" class="text-center text-muted py-4">
				<?php esc_html_e('Für dieses Sportjahr liegen noch keine Ergebnisse vor.', 'srd-kreismeisterschaften'); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Ab 01.10. des Kalenderjahres das Folge-Sportjahr (Kalenderjahr + 1) in der Liste führen,
	 * sofern es noch nicht aus der Datenbank/Fallback stammt (Saisonwechsel).
	 *
	 * @param int[] $years
	 * @return int[] Absteigend sortiert, eindeutig
	 */
	private function merge_sportjahr_season_preview(array $years): array {
		$out = array();
		foreach ($years as $y) {
			$y = (int) $y;
			if ($y >= 1990 && $y <= 2100) {
				$out[ $y ] = $y;
			}
		}
		$dt = current_datetime();
		if ((int) $dt->format('n') >= 10) {
			$preview = (int) $dt->format('Y') + 1;
			if ($preview <= 2100) {
				$out[ $preview ] = $preview;
			}
		}
		$list = array_values($out);
		rsort($list, SORT_NUMERIC);
		return $list;
	}

	/**
	 * @param int[] $years
	 */
	private function render_year_toolbar(int $jahr, array $years): void {
		?>
		<div class="srd-km-year-toolbar px-3 py-2">
			<div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
				<span class="fw-semibold"><?php esc_html_e('Sportjahr', 'srd-kreismeisterschaften'); ?></span>
				<label class="visually-hidden" for="srd-km-year-select"><?php esc_html_e('Sportjahr', 'srd-kreismeisterschaften'); ?></label>
				<select id="srd-km-year-select" class="form-select form-select-sm srd-km-year-select" aria-label="<?php esc_attr_e('Sportjahr', 'srd-kreismeisterschaften'); ?>">
					<?php foreach ($years as $y) : ?>
						<?php
						$y = (int) $y;
						$year_url = $this->km_url(array( 'km_year' => (string) $y ));
						?>
						<option value="<?php echo esc_attr((string) $y); ?>" data-fallback-url="<?php echo esc_url($year_url); ?>" <?php selected($y, $jahr); ?>>
							<?php echo esc_html((string) $y); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<noscript>
					<p class="small mb-0 mt-2">
						<?php esc_html_e('Ohne JavaScript wird die Seite beim Wechsel des Sportjahrs neu geladen.', 'srd-kreismeisterschaften'); ?>
					</p>
				</noscript>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $dsatz
	 * @param array{path: string, url: string} $r
	 * @return array{
	 *   disziplin: string,
	 *   altersklasse: string,
	 *   spo: string,
	 *   datum: string,
	 *   category_id: int,
	 *   category_label: string,
	 *   einzel: array{available: bool, kind: string, url: string},
	 *   mannschaft: array{available: bool, kind: string, url: string}
	 * }|null
	 */
	private function prepare_discipline_row(array $dsatz, int $jahr, string $extPreferred, array $r): ?array {
		$datei = (string) ($dsatz['datei'] ?? '');
		if (!$this->is_safe_file_id($datei)) {
			return null;
		}
		$pfadEpdf = 'km_' . $jahr . '/e' . $datei . '.pdf';
		$pfadMpdf = 'km_' . $jahr . '/m' . $datei . '.pdf';
		$pfadEhtml = 'km_' . $jahr . '/e' . $datei . '.html';
		$pfadMhtml = 'km_' . $jahr . '/m' . $datei . '.html';
		$ext = $extPreferred;
		if ($extPreferred === 'pdf') {
			$hasPdf = $this->resolve_under_results($pfadEpdf) || $this->resolve_under_results($pfadMpdf);
			$hasHtml = $this->resolve_under_results($pfadEhtml) || $this->resolve_under_results($pfadMhtml);
			if ($hasPdf) {
				$ext = 'pdf';
			} elseif ($hasHtml) {
				$ext = 'html';
			} else {
				return null;
			}
		} else {
			$hasHtml = $this->resolve_under_results($pfadEhtml) || $this->resolve_under_results($pfadMhtml);
			$hasPdf = $this->resolve_under_results($pfadEpdf) || $this->resolve_under_results($pfadMpdf);
			if ($hasHtml) {
				$ext = 'html';
			} elseif ($hasPdf) {
				$ext = 'pdf';
			} else {
				return null;
			}
		}

		$pfadE = 'km_' . $jahr . '/e' . $datei . '.' . $ext;
		$pfadM = 'km_' . $jahr . '/m' . $datei . '.' . $ext;
		$fileE = $this->resolve_under_results($pfadE);
		$fileM = $this->resolve_under_results($pfadM);
		$datum = '-';
		if ($fileE) {
			$datum = wp_date('d.m.Y', (int) filemtime($fileE));
		}

		$category_id = SRD_KM_Categories::from_datei($datei);

		return array(
			'disziplin'       => (string) ( $dsatz['disziplin'] ?? '' ),
			'altersklasse'    => (string) ( $dsatz['altersklasse'] ?? '' ),
			'spo'             => (string) ( $dsatz['spo'] ?? '' ),
			'datum'           => $datum,
			'category_id'     => $category_id,
			'category_label'  => SRD_KM_Categories::label($category_id),
			'einzel'          => $this->prepare_result_action($fileE, $ext, $jahr, $datei, 'e', $r, $pfadE),
			'mannschaft'      => $this->prepare_result_action($fileM, $ext, $jahr, $datei, 'm', $r, $pfadM),
		);
	}

	/**
	 * @param array{path: string, url: string} $r
	 * @return array{available: bool, kind: string, url: string}
	 */
	private function prepare_result_action($file, string $ext, int $jahr, string $datei, string $art, array $r, string $pfad): array {
		if (!$file) {
			return array(
				'available' => false,
				'kind'      => 'none',
				'url'       => '',
			);
		}
		if ($ext === 'html') {
			return array(
				'available' => true,
				'kind'      => 'html',
				'url'       => $this->km_url(
					array(
						'km_year' => (string) $jahr,
						'km_id'   => $datei,
						'km_art'  => $art,
					)
				),
			);
		}
		return array(
			'available' => true,
			'kind'      => 'pdf',
			'url'       => $r['url'] . '/' . $pfad,
		);
	}

	/**
	 * @param array{
	 *   disziplin: string,
	 *   altersklasse: string,
	 *   spo: string,
	 *   datum: string,
	 *   category_id: int,
	 *   category_label: string,
	 *   einzel: array{available: bool, kind: string, url: string},
	 *   mannschaft: array{available: bool, kind: string, url: string}
	 * } $row
	 */
	private function render_discipline_table_row(array $row): void {
		echo '<tr class="srd-km-discipline-item" data-srd-km-category="' . esc_attr((string) $row['category_id']) . '">';
		echo '<td class="srd-km-discipline-cell">';
		echo '<strong>' . esc_html($row['disziplin']) . '</strong>';
		if ($row['category_label'] !== '') {
			echo ' <span class="badge ' . esc_attr(SRD_KM_Categories::color_class($row['category_id'])) . ' srd-km-category-tag">' . esc_html($row['category_label']) . '</span>';
		}
		echo '</td>';
		echo '<td>' . esc_html($row['altersklasse']) . '</td>';
		echo '<td>' . esc_html($row['spo']) . '</td>';
		echo '<td>' . esc_html($row['datum']) . '</td>';
		echo '<td>';
		$this->render_result_action($row['einzel'], 'e', true);
		echo '</td>';
		echo '<td>';
		$this->render_result_action($row['mannschaft'], 'm', true);
		echo '</td>';
		echo '</tr>';
	}

	/**
	 * @param array{
	 *   disziplin: string,
	 *   altersklasse: string,
	 *   spo: string,
	 *   datum: string,
	 *   category_id: int,
	 *   category_label: string,
	 *   einzel: array{available: bool, kind: string, url: string},
	 *   mannschaft: array{available: bool, kind: string, url: string}
	 * } $row
	 */
	private function render_discipline_card(array $row): void {
		?>
		<article class="srd-km-row-card srd-km-discipline-item" data-srd-km-category="<?php echo esc_attr((string) $row['category_id']); ?>">
			<h3 class="srd-km-row-card__title h6 mb-2">
				<strong><?php echo esc_html($row['disziplin']); ?></strong>
				<?php if ($row['category_label'] !== '') : ?>
					<span class="badge <?php echo esc_attr(SRD_KM_Categories::color_class($row['category_id'])); ?> srd-km-category-tag d-inline-block mt-1"><?php echo esc_html($row['category_label']); ?></span>
				<?php endif; ?>
			</h3>
			<p class="srd-km-row-card__meta small text-muted mb-3">
				<span class="srd-km-row-card__meta-item">
					<span class="srd-km-row-card__meta-label"><?php esc_html_e('Klasse', 'srd-kreismeisterschaften'); ?>:</span>
					<?php echo esc_html($row['altersklasse']); ?>
				</span>
				<span class="srd-km-row-card__meta-sep" aria-hidden="true">·</span>
				<span class="srd-km-row-card__meta-item">
					<span class="srd-km-row-card__meta-label"><?php esc_html_e('SpO', 'srd-kreismeisterschaften'); ?>:</span>
					<?php echo esc_html($row['spo']); ?>
				</span>
				<span class="srd-km-row-card__meta-sep" aria-hidden="true">·</span>
				<span class="srd-km-row-card__meta-item">
					<span class="srd-km-row-card__meta-label"><?php esc_html_e('Änderung', 'srd-kreismeisterschaften'); ?>:</span>
					<?php echo esc_html($row['datum']); ?>
				</span>
			</p>
			<div class="row g-2 srd-km-row-card__actions">
				<div class="col-6">
					<?php $this->render_result_action($row['einzel'], 'e', false); ?>
				</div>
				<div class="col-6">
					<?php $this->render_result_action($row['mannschaft'], 'm', false); ?>
				</div>
			</div>
		</article>
		<?php
	}

	/**
	 * @param array{available: bool, kind: string, url: string} $action
	 */
	private function render_result_action(array $action, string $art, bool $compact): void {
		if (!$action['available']) {
			if ($compact) {
				echo '<span class="text-muted">-</span>';
			} else {
				echo '<span class="srd-km-row-card__action-placeholder text-muted w-100 d-flex align-items-center justify-content-center">-</span>';
			}
			return;
		}

		$label = ($art === 'e')
			? __('Einzel', 'srd-kreismeisterschaften')
			: __('Mannschaft', 'srd-kreismeisterschaften');
		$icon = ($art === 'e') ? 'bi-person' : 'bi-people';
		$classes = array( 'btn' );
		if ($action['kind'] === 'html') {
			$classes[] = 'btn-outline-success';
		} else {
			$classes[] = 'btn-outline-primary';
		}
		if ($compact) {
			$classes[] = 'btn-sm';
		} else {
			$classes[] = 'w-100';
			$classes[] = 'srd-km-row-card__btn';
		}
		$class_attr = esc_attr(implode(' ', $classes));

		if ($action['kind'] === 'html') {
			echo '<a href="' . esc_url($action['url']) . '" class="' . $class_attr . '">';
			echo '<i class="bi ' . esc_attr($icon) . ' me-1" aria-hidden="true"></i>';
			echo esc_html($label);
			echo '</a>';
			return;
		}

		echo '<a href="' . esc_url($action['url']) . '" target="_blank" rel="noopener" class="' . $class_attr . '">';
		echo '<i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF';
		echo '</a>';
	}

	private function render_html_result(int $jahr, string $id, string $art): string {
		$rel = 'km_' . $jahr . '/' . $art . $id . '.html';
		if ($this->resolve_under_results($rel) === null) {
			return '<div class="srd-km-wrap"><div class="alert alert-warning">' . esc_html__('Die angeforderte Ergebnisdatei existiert nicht.', 'srd-kreismeisterschaften') . '</div></div>';
		}
		$iframe_src = $this->km_raw_url($jahr, $art, $id);
		ob_start();
		?>
		<div class="srd-km-wrap container-fluid py-2">
			<nav aria-label="breadcrumb">
				<ol class="breadcrumb">
					<li class="breadcrumb-item"><a href="<?php echo esc_url($this->home_breadcrumb_url()); ?>"><?php esc_html_e('Ergebnishistorie', 'srd-kreismeisterschaften'); ?></a></li>
					<li class="breadcrumb-item"><a href="<?php echo esc_url($this->km_url()); ?>"><?php esc_html_e('Kreismeisterschaften', 'srd-kreismeisterschaften'); ?></a></li>
					<li class="breadcrumb-item"><a href="<?php echo esc_url($this->km_url(array('km_year' => (string) $jahr))); ?>"><?php echo esc_html((string) $jahr); ?></a></li>
					<li class="breadcrumb-item active"><?php echo esc_html($art === 'e' ? __('Einzel', 'srd-kreismeisterschaften') : __('Mannschaft', 'srd-kreismeisterschaften')); ?></li>
				</ol>
			</nav>
			<p><a class="btn btn-primary btn-sm" href="<?php echo esc_url($this->km_url(array('km_year' => (string) $jahr))); ?>"><i class="bi bi-arrow-left me-1"></i><?php esc_html_e('Zurück zu den Disziplinen', 'srd-kreismeisterschaften'); ?></a></p>
			<iframe class="srd-km-html-frame" title="<?php esc_attr_e('Ergebnis', 'srd-kreismeisterschaften'); ?>" src="<?php echo esc_url($iframe_src); ?>"></iframe>
		</div>
		<?php
		return (string) ob_get_clean();
	}

}
