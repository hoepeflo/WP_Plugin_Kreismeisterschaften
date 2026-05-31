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

		return trailingslashit(home_url(user_trailingslashit($path)));
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
		if (current_user_can('manage_options') && empty($s['page_id'])) {
			$admin_tip = '<div class="alert alert-warning">' .
				esc_html__('Tipp: Unter Einstellungen → SRD Kreismeisterschaften die KM-Seite festlegen, damit alle Links stabil auf dieselbe URL zeigen.', 'srd-kreismeisterschaften') .
				'</div>';
		}
		if (current_user_can('manage_options') && !empty($s['rewrite_enabled']) && get_option('permalink_structure', '') === '') {
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
		$disc = $this->request_discipline();
		$id = $this->request_id();
		$art = $this->request_art();

		$body = $this->render_overview();
		if ($disc === 'bogen' && $year > 0) {
			$body = $this->render_bogen($year);
		} elseif ($disc === 'blasrohr' && $year > 0) {
			$body = $this->render_blasrohr($year);
		} elseif ($year > 0 && $id !== '' && $art !== '' && in_array($art, array('e', 'm'), true) && $this->is_safe_file_id($id)) {
			$body = $this->render_html_result($year, $id, $art);
		} elseif ($year > 0) {
			$body = $this->render_year($year);
		}

		return $admin_tip . $body;
	}

	private function render_overview(): string {
		$years = array(2026, 2025, 2024, 2023, 2022, 2021, 2020, 2019, 2018, 2017, 2016);
		$dbYears = SRD_KM_DB::distinct_sportjahre();
		if (!empty($dbYears)) {
			$years = $dbYears;
		}
		$recent_years = array_slice($years, 0, 5);
		$older_years  = array_slice($years, 5);
		$r = $this->results_paths();
		$accordion_id = 'srd-km-older-' . str_replace('.', '', uniqid('', true));
		$collapse_id  = $accordion_id . '-collapse';

		ob_start();
		?>
		<div class="srd-km-wrap container-fluid py-2">
			<div class="row mb-3">
				<div class="col">
					<p class="lead text-muted"><?php esc_html_e('Ergebnisse der Kreismeisterschaften', 'srd-kreismeisterschaften'); ?></p>
				</div>
			</div>
			<div class="card shadow-sm">
				<div class="card-header bg-primary text-white">
					<h2 class="h5 mb-0"><i class="bi bi-calendar me-2"></i><?php esc_html_e('Sportjahr und Disziplin auswählen', 'srd-kreismeisterschaften'); ?></h2>
				</div>
				<div class="card-body p-0">
					<div class="table-responsive">
						<table class="table table-hover table-striped mb-0">
							<thead class="table-primary">
								<tr>
									<th class="col-year"><?php esc_html_e('Jahr', 'srd-kreismeisterschaften'); ?></th>
									<th class="col-equal text-center"><?php esc_html_e('Kugel', 'srd-kreismeisterschaften'); ?></th>
									<th class="col-equal text-center"><?php esc_html_e('Lichtschießen', 'srd-kreismeisterschaften'); ?></th>
									<th class="col-equal text-center"><?php esc_html_e('Bogen', 'srd-kreismeisterschaften'); ?></th>
									<th class="col-equal text-center"><?php esc_html_e('Blasrohr', 'srd-kreismeisterschaften'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($recent_years as $year) : ?>
									<?php $this->render_overview_year_row((int) $year, $r); ?>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php if (!empty($older_years)) : ?>
						<div class="accordion accordion-flush srd-km-overview-accordion" id="<?php echo esc_attr($accordion_id); ?>">
							<div class="accordion-item border-0 border-top rounded-0">
								<h3 class="accordion-header" id="<?php echo esc_attr($collapse_id); ?>-heading">
									<button class="accordion-button collapsed py-3 rounded-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo esc_attr($collapse_id); ?>" aria-expanded="false" aria-controls="<?php echo esc_attr($collapse_id); ?>">
										<i class="bi bi-calendar-range me-2" aria-hidden="true"></i><?php echo esc_html(sprintf(/* translators: %d: Anzahl älterer Sportjahre in der Liste */ _n('%d weiteres Jahr', '%d weitere Jahre', count($older_years), 'srd-kreismeisterschaften'), count($older_years))); ?>
									</button>
								</h3>
								<div id="<?php echo esc_attr($collapse_id); ?>" class="accordion-collapse collapse" data-bs-parent="#<?php echo esc_attr($accordion_id); ?>" role="region" aria-labelledby="<?php echo esc_attr($collapse_id); ?>-heading">
									<div class="accordion-body p-0">
										<div class="table-responsive">
											<table class="table table-hover table-striped mb-0">
												<thead class="table-primary">
													<tr>
														<th class="col-year"><?php esc_html_e('Jahr', 'srd-kreismeisterschaften'); ?></th>
														<th class="col-equal text-center"><?php esc_html_e('Kugel', 'srd-kreismeisterschaften'); ?></th>
														<th class="col-equal text-center"><?php esc_html_e('Lichtschießen', 'srd-kreismeisterschaften'); ?></th>
														<th class="col-equal text-center"><?php esc_html_e('Bogen', 'srd-kreismeisterschaften'); ?></th>
														<th class="col-equal text-center"><?php esc_html_e('Blasrohr', 'srd-kreismeisterschaften'); ?></th>
													</tr>
												</thead>
												<tbody>
													<?php foreach ($older_years as $year) : ?>
														<?php $this->render_overview_year_row((int) $year, $r); ?>
													<?php endforeach; ?>
												</tbody>
											</table>
										</div>
									</div>
								</div>
							</div>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
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
	 * @param array{path: string, url: string} $r
	 */
	private function render_overview_year_row(int $year, array $r): void {
		?>
		<tr>
			<td><strong><?php echo esc_html((string) $year); ?></strong></td>
			<td class="text-center">
				<a href="<?php echo esc_url($this->km_url(array('km_year' => (string) $year))); ?>" class="btn btn-outline-primary btn-sm">
					<i class="bi bi-trophy me-1"></i><?php esc_html_e('Ergebnisse', 'srd-kreismeisterschaften'); ?>
				</a>
			</td>
			<td class="text-center">
				<?php echo $this->cell_lichtschiessen($year, $r); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — Zelle liefert escaptes HTML ?>
			</td>
			<td class="text-center">
				<?php if ($year >= 2024) : ?>
					<a href="<?php echo esc_url($this->km_url(array('km_year' => (string) $year, 'km_discipline' => 'bogen'))); ?>" class="btn btn-outline-success btn-sm">
						<i class="bi bi-link-45deg me-1"></i><?php esc_html_e('Link', 'srd-kreismeisterschaften'); ?>
					</a>
				<?php else : ?>
					<span class="text-muted">-</span>
				<?php endif; ?>
			</td>
			<td class="text-center">
				<?php if ($year >= 2024) : ?>
					<a href="<?php echo esc_url($this->km_url(array('km_year' => (string) $year, 'km_discipline' => 'blasrohr'))); ?>" class="btn btn-outline-success btn-sm">
						<i class="bi bi-link-45deg me-1"></i><?php esc_html_e('Link', 'srd-kreismeisterschaften'); ?>
					</a>
				<?php else : ?>
					<span class="text-muted">-</span>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * @param array{path: string, url: string} $r
	 */
	private function cell_lichtschiessen(int $year, array $r): string {
		$lichtPath = '';
		if ($year === 2026) {
			$lichtPath = 'km_2026/2026_Lichtschießen.pdf';
		} elseif ($year === 2025) {
			$lichtPath = 'km_' . $year . '/licht/Ergebnisse.pdf';
		} elseif ($year === 2018) {
			$lichtPath = 'km-licht/' . $year . '/' . $year . '-Gesamt.pdf';
		} elseif ($year >= 2017) {
			$lichtPath = 'km-licht/' . $year . '/KM_Licht' . $year . '-gesamt.pdf';
		}
		if ($lichtPath === '') {
			return '<span class="text-muted">-</span>';
		}
		if ($this->resolve_under_results($lichtPath) === null) {
			return '<span class="text-muted">-</span>';
		}
		$url = $r['url'] . '/' . ltrim($lichtPath, '/');
		if (strpos($lichtPath, '.pdf') !== false) {
			return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>';
		}
		return '<a href="' . esc_url($url) . '" class="btn btn-outline-success btn-sm"><i class="bi bi-link-45deg me-1"></i>Link</a>';
	}

	private function render_year(int $jahr): string {
		$r = $this->results_paths();

		ob_start();
		?>
		<div class="srd-km-wrap container-fluid py-2">
			<div class="row mb-3">
				<div class="col">
					<h1 class="h2 fw-bold text-primary"><?php echo esc_html(sprintf(/* translators: %d year */ __('Kreismeisterschaften %d', 'srd-kreismeisterschaften'), $jahr)); ?></h1>
					<p class="lead text-muted"><?php esc_html_e('Ergebnisse der Kreismeisterschaften', 'srd-kreismeisterschaften'); ?></p>
				</div>
			</div>
			<div class="card shadow-sm">
				<div class="card-header bg-primary text-white">
					<h2 class="h5 mb-0"><i class="bi bi-trophy me-2"></i><?php echo esc_html(sprintf(__('Disziplinen %d', 'srd-kreismeisterschaften'), $jahr)); ?></h2>
				</div>
				<div class="card-body p-0">
					<div class="table-responsive">
						<table class="table table-hover table-striped mb-0">
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
							<tbody>
								<?php
								$extPreferred = ($jahr >= 2024) ? 'pdf' : 'html';
								$rows = SRD_KM_DB::kreis_rows_v3();
								foreach ($rows as $dsatz) {
									$this->render_row($dsatz, $jahr, $extPreferred, $r);
								}
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
	 * @param array<string, mixed> $dsatz
	 * @param array{path: string, url: string} $r
	 */
	private function render_row(array $dsatz, int $jahr, string $extPreferred, array $r): void {
		$datei = (string) ($dsatz['datei'] ?? '');
		if (!$this->is_safe_file_id($datei)) {
			return;
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
				return;
			}
		} else {
			$hasHtml = $this->resolve_under_results($pfadEhtml) || $this->resolve_under_results($pfadMhtml);
			$hasPdf = $this->resolve_under_results($pfadEpdf) || $this->resolve_under_results($pfadMpdf);
			if ($hasHtml) {
				$ext = 'html';
			} elseif ($hasPdf) {
				$ext = 'pdf';
			} else {
				return;
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

		echo '<tr>';
		echo '<td><strong>' . esc_html((string) ($dsatz['disziplin'] ?? '')) . '</strong></td>';
		echo '<td>' . esc_html((string) ($dsatz['altersklasse'] ?? '')) . '</td>';
		echo '<td>' . esc_html((string) ($dsatz['spo'] ?? '')) . '</td>';
		echo '<td>' . esc_html($datum) . '</td>';

		if ($fileE) {
			if ($ext === 'html') {
				$url = $this->km_url(
					array(
						'km_year' => (string) $jahr,
						'km_id'   => $datei,
						'km_art'  => 'e',
					)
				);
				echo '<td><a href="' . esc_url($url) . '" class="btn btn-outline-success btn-sm"><i class="bi bi-person me-1"></i>' . esc_html__('Einzel', 'srd-kreismeisterschaften') . '</a></td>';
			} else {
				$pdfUrl = $r['url'] . '/' . $pfadE;
				echo '<td><a href="' . esc_url($pdfUrl) . '" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a></td>';
			}
		} else {
			echo '<td><span class="text-muted">-</span></td>';
		}

		if ($fileM) {
			if ($ext === 'html') {
				$url = $this->km_url(
					array(
						'km_year' => (string) $jahr,
						'km_id'   => $datei,
						'km_art'  => 'm',
					)
				);
				echo '<td><a href="' . esc_url($url) . '" class="btn btn-outline-success btn-sm"><i class="bi bi-people me-1"></i>' . esc_html__('Mannschaft', 'srd-kreismeisterschaften') . '</a></td>';
			} else {
				$pdfUrl = $r['url'] . '/' . $pfadM;
				echo '<td><a href="' . esc_url($pdfUrl) . '" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a></td>';
			}
		} else {
			echo '<td><span class="text-muted">-</span></td>';
		}
		echo '</tr>';
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

	private function render_bogen(int $jahr): string {
		ob_start();
		?>
		<div class="srd-km-wrap container-fluid py-2">
			<nav aria-label="breadcrumb">
				<ol class="breadcrumb">
					<li class="breadcrumb-item"><a href="<?php echo esc_url($this->home_breadcrumb_url()); ?>"><?php esc_html_e('Ergebnishistorie', 'srd-kreismeisterschaften'); ?></a></li>
					<li class="breadcrumb-item"><a href="<?php echo esc_url($this->km_url()); ?>"><?php esc_html_e('Kreismeisterschaften', 'srd-kreismeisterschaften'); ?></a></li>
					<li class="breadcrumb-item active"><?php echo esc_html(sprintf(__('Bogen %d', 'srd-kreismeisterschaften'), $jahr)); ?></li>
				</ol>
			</nav>
			<div class="row mb-3">
				<div class="col">
					<h1 class="h2 fw-bold text-primary"><?php echo esc_html(sprintf(__('Kreismeisterschaften Bogen %d', 'srd-kreismeisterschaften'), $jahr)); ?></h1>
					<p class="lead text-muted"><?php esc_html_e('Ergebnisse der Bogenwettbewerbe', 'srd-kreismeisterschaften'); ?></p>
				</div>
			</div>
			<div class="alert alert-info">
				<h2 class="h5 alert-heading"><i class="bi bi-info-circle me-2"></i><?php esc_html_e('Hinweis', 'srd-kreismeisterschaften'); ?></h2>
				<p class="mb-0"><?php esc_html_e('Die Bogenwettbewerbe werden separat von den Hauptdisziplinen durchgeführt. Ergebnisse werden hier veröffentlicht, sobald sie verfügbar sind.', 'srd-kreismeisterschaften'); ?></p>
			</div>
			<div class="card shadow-sm">
				<div class="card-header bg-primary text-white">
					<h2 class="h5 mb-0"><i class="bi bi-bullseye me-2"></i><?php echo esc_html(sprintf(__('Bogenwettbewerbe %d', 'srd-kreismeisterschaften'), $jahr)); ?></h2>
				</div>
				<div class="card-body text-center py-5">
					<i class="bi bi-bullseye display-4 text-muted mb-3 d-block"></i>
					<h3 class="h5 text-muted"><?php esc_html_e('Ergebnisse werden hier veröffentlicht', 'srd-kreismeisterschaften'); ?></h3>
					<a href="<?php echo esc_url($this->km_url()); ?>" class="btn btn-primary mt-2"><i class="bi bi-arrow-left me-1"></i><?php esc_html_e('Zurück zu Kreismeisterschaften', 'srd-kreismeisterschaften'); ?></a>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function render_blasrohr(int $jahr): string {
		ob_start();
		?>
		<div class="srd-km-wrap container-fluid py-2">
			<nav aria-label="breadcrumb">
				<ol class="breadcrumb">
					<li class="breadcrumb-item"><a href="<?php echo esc_url($this->home_breadcrumb_url()); ?>"><?php esc_html_e('Ergebnishistorie', 'srd-kreismeisterschaften'); ?></a></li>
					<li class="breadcrumb-item"><a href="<?php echo esc_url($this->km_url()); ?>"><?php esc_html_e('Kreismeisterschaften', 'srd-kreismeisterschaften'); ?></a></li>
					<li class="breadcrumb-item active"><?php echo esc_html(sprintf(__('Blasrohr %d', 'srd-kreismeisterschaften'), $jahr)); ?></li>
				</ol>
			</nav>
			<div class="row mb-3">
				<div class="col">
					<h1 class="h2 fw-bold text-primary"><?php echo esc_html(sprintf(__('Kreismeisterschaften Blasrohr %d', 'srd-kreismeisterschaften'), $jahr)); ?></h1>
					<p class="lead text-muted"><?php esc_html_e('Ergebnisse der Blasrohrwettbewerbe', 'srd-kreismeisterschaften'); ?></p>
				</div>
			</div>
			<div class="alert alert-info">
				<h2 class="h5 alert-heading"><i class="bi bi-info-circle me-2"></i><?php esc_html_e('Hinweis', 'srd-kreismeisterschaften'); ?></h2>
				<p class="mb-0"><?php esc_html_e('Die Blasrohrwettbewerbe werden separat von den Hauptdisziplinen durchgeführt. Ergebnisse werden hier veröffentlicht, sobald sie verfügbar sind.', 'srd-kreismeisterschaften'); ?></p>
			</div>
			<div class="card shadow-sm">
				<div class="card-header bg-primary text-white">
					<h2 class="h5 mb-0"><i class="bi bi-bullseye me-2"></i><?php echo esc_html(sprintf(__('Blasrohrwettbewerbe %d', 'srd-kreismeisterschaften'), $jahr)); ?></h2>
				</div>
				<div class="card-body text-center py-5">
					<i class="bi bi-bullseye display-4 text-muted mb-3 d-block"></i>
					<h3 class="h5 text-muted"><?php esc_html_e('Ergebnisse werden hier veröffentlicht', 'srd-kreismeisterschaften'); ?></h3>
					<a href="<?php echo esc_url($this->km_url()); ?>" class="btn btn-primary mt-2"><i class="bi bi-arrow-left me-1"></i><?php esc_html_e('Zurück zu Kreismeisterschaften', 'srd-kreismeisterschaften'); ?></a>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
