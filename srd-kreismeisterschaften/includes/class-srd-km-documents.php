<?php
/**
 * Ausschreibungs- und Planungsdokumente (aktuell, ohne Jahresarchiv).
 *
 * @package SRD_Kreismeisterschaften
 */

if (!defined('ABSPATH')) {
	exit;
}

class SRD_KM_Documents {

	public const OPTION_KEY = 'srd_km_documents';

	/** Optionsschlüssel für die aktuell gültigen Unterlagen (kein Jahresarchiv). */
	public const CURRENT_KEY = '_current';

	/**
	 * Feste Dokumenttypen (ohne Kategorie).
	 *
	 * @return array<string, string> key => Admin-/Frontend-Label
	 */
	public static function fixed_types(): array {
		return array(
			'general'         => __('Allgemeine Ausschreibung', 'srd-kreismeisterschaften'),
			'discipline_plan' => __('Disziplinenplan', 'srd-kreismeisterschaften'),
			'age_table'       => __('Jahrgangstabelle', 'srd-kreismeisterschaften'),
			'schedule'        => __('Terminplan', 'srd-kreismeisterschaften'),
		);
	}

	/**
	 * @return array<string, string> key (cat_N) => Label
	 */
	public static function category_types(): array {
		$out = array();
		foreach (SRD_KM_Categories::labels() as $id => $label) {
			$out[ 'cat_' . $id ] = sprintf(
				/* translators: %s: Kategoriename */
				__('Ausschreibung %s', 'srd-kreismeisterschaften'),
				$label
			);
		}
		return $out;
	}

	/**
	 * @return array<string, string>
	 */
	public static function all_types(): array {
		return array_merge(self::fixed_types(), self::category_types());
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_all(): array {
		$stored = get_option(self::OPTION_KEY, array());
		return is_array($stored) ? $stored : array();
	}

	/**
	 * Aktuelle Unterlagen (ein Satz für alle Sportjahre im Frontend).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_current(): array {
		self::maybe_migrate_from_yearly();
		$all = self::get_all();
		if (!isset($all[ self::CURRENT_KEY ]) || !is_array($all[ self::CURRENT_KEY ])) {
			return array();
		}
		return $all[ self::CURRENT_KEY ];
	}

	/**
	 * @param array<string, array<string, mixed>> $docs
	 */
	public static function save_current(array $docs): void {
		$all = self::get_all();
		$all[ self::CURRENT_KEY ] = $docs;
		update_option(self::OPTION_KEY, $all, false);
	}

	/**
	 * Einmalige Übernahme aus alter jahresweiser Speicherung.
	 */
	public static function maybe_migrate_from_yearly(): void {
		$all = get_option(self::OPTION_KEY, array());
		if (!is_array($all)) {
			return;
		}
		if (isset($all[ self::CURRENT_KEY ]) && is_array($all[ self::CURRENT_KEY ]) && self::docs_have_content($all[ self::CURRENT_KEY ])) {
			return;
		}
		$year_keys = array();
		foreach ($all as $key => $value) {
			if (!is_array($value)) {
				continue;
			}
			if (preg_match('/^(19|20)\d{2}$/', (string) $key)) {
				$year_keys[] = (int) $key;
			}
		}
		if ($year_keys === array()) {
			return;
		}
		rsort($year_keys, SORT_NUMERIC);
		$preferred = self::default_sport_year();
		$source = 0;
		if (in_array($preferred, $year_keys, true) && self::docs_have_content($all[ (string) $preferred ])) {
			$source = $preferred;
		} else {
			foreach ($year_keys as $y) {
				if (self::docs_have_content($all[ (string) $y ])) {
					$source = $y;
					break;
				}
			}
			if ($source === 0) {
				$source = $year_keys[0];
			}
		}
		if ($source > 0 && isset($all[ (string) $source ]) && is_array($all[ (string) $source ])) {
			$all[ self::CURRENT_KEY ] = $all[ (string) $source ];
			update_option(self::OPTION_KEY, $all, false);
		}
	}

	/**
	 * Standard-Sportjahr (Oktober-Regel), z. B. für Migration.
	 */
	public static function default_sport_year(): int {
		$cy = (int) wp_date('Y');
		$cm = (int) wp_date('n');
		return ($cm >= 10) ? $cy + 1 : $cy;
	}

	/**
	 * @param array<string, mixed> $docs
	 */
	private static function docs_have_content(array $docs): bool {
		foreach ($docs as $key => $entry) {
			if ($key === 'category_order' || !is_array($entry)) {
				continue;
			}
			if (self::sanitize_entry($entry)['type'] !== '') {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{type: string, attachment_id: int, page_id: int, url: string}
	 */
	public static function sanitize_entry($input): array {
		$empty = array(
			'type'            => '',
			'attachment_id'   => 0,
			'page_id'         => 0,
			'url'             => '',
		);
		if (!is_array($input)) {
			return $empty;
		}
		$type = isset($input['type']) ? sanitize_key((string) $input['type']) : '';
		if ($type === 'url') {
			$url = self::sanitize_document_url(isset($input['url']) ? (string) $input['url'] : '');
			if ($url === '') {
				return $empty;
			}
			return array(
				'type'            => 'url',
				'attachment_id'   => 0,
				'page_id'         => 0,
				'url'             => $url,
			);
		}
		if (!in_array($type, array('pdf', 'page'), true)) {
			return $empty;
		}
		if ($type === 'pdf') {
			$aid = isset($input['attachment_id']) ? absint($input['attachment_id']) : 0;
			if ($aid <= 0 || get_post_type($aid) !== 'attachment') {
				return $empty;
			}
			$mime = get_post_mime_type($aid);
			if ($mime !== 'application/pdf') {
				return $empty;
			}
			return array(
				'type'          => 'pdf',
				'attachment_id' => $aid,
				'page_id'       => 0,
				'url'           => '',
			);
		}
		$pid = isset($input['page_id']) ? absint($input['page_id']) : 0;
		if ($pid <= 0 || get_post_type($pid) !== 'page') {
			return $empty;
		}
		return array(
			'type'            => 'page',
			'attachment_id'   => 0,
			'page_id'         => $pid,
			'url'             => '',
		);
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array{url: string, label: string, kind: string, is_external: bool}|null
	 */
	public static function resolve_entry(array $entry, string $label): ?array {
		$type = isset($entry['type']) ? (string) $entry['type'] : '';
		if ($type === 'pdf') {
			$aid = isset($entry['attachment_id']) ? absint($entry['attachment_id']) : 0;
			if ($aid <= 0) {
				return null;
			}
			$url = wp_get_attachment_url($aid);
			if (!$url) {
				return null;
			}
			return array(
				'url'          => $url,
				'label'        => $label,
				'kind'         => 'pdf',
				'is_external'  => true,
			);
		}
		if ($type === 'page') {
			$pid = isset($entry['page_id']) ? absint($entry['page_id']) : 0;
			if ($pid <= 0) {
				return null;
			}
			$url = get_permalink($pid);
			if (!$url) {
				return null;
			}
			$page_title = get_the_title($pid);
			$display = $label;
			if ($page_title !== '') {
				$display = $label . ' – ' . $page_title;
			}
			return array(
				'url'          => $url,
				'label'        => $display,
				'kind'         => 'page',
				'is_external'  => false,
			);
		}
		if ($type === 'url') {
			$url = self::sanitize_document_url(isset($entry['url']) ? (string) $entry['url'] : '');
			if ($url === '') {
				return null;
			}
			return array(
				'url'          => $url,
				'label'        => $label,
				'kind'         => 'url',
				'is_external'  => self::is_external_url($url),
			);
		}
		return null;
	}

	/**
	 * URL für Dokumentlinks normalisieren (absolut, relativ, ohne Schema).
	 */
	public static function sanitize_document_url(string $raw): string {
		$raw = trim(wp_strip_all_tags($raw));
		if ($raw === '') {
			return '';
		}
		if (str_starts_with($raw, '/')) {
			return esc_url_raw(home_url($raw));
		}
		if (str_starts_with($raw, '//')) {
			return esc_url_raw('https:' . $raw);
		}
		$url = esc_url_raw($raw);
		if ($url !== '') {
			return $url;
		}
		if (preg_match('#^[\w.-]+\.[a-z]{2,}(/.*)?$#i', $raw)) {
			$url = esc_url_raw('https://' . $raw);
			if ($url !== '') {
				return $url;
			}
		}
		if (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $raw)) {
			return esc_url_raw(home_url('/' . ltrim($raw, '/')));
		}
		return '';
	}

	/**
	 * Externe URL, wenn Host von home_url() abweicht.
	 */
	private static function is_external_url(string $url): bool {
		$home_host = wp_parse_url(home_url(), PHP_URL_HOST);
		$url_host = wp_parse_url($url, PHP_URL_HOST);
		if (!is_string($home_host) || $home_host === '' || !is_string($url_host) || $url_host === '') {
			return true;
		}
		return strcasecmp($home_host, $url_host) !== 0;
	}

	/**
	 * Prüft, ob ein Schlüssel eine Standard-Kategorie (cat_N) ist.
	 */
	public static function is_standard_category_key(string $key): bool {
		return (bool) preg_match('/^cat_([1-9]|1[0-2])$/', $key);
	}

	/**
	 * Prüft, ob ein Schlüssel eine eigene Kategorieausschreibung ist.
	 */
	public static function is_custom_category_key(string $key): bool {
		return str_starts_with($key, 'custom_') && preg_match('/^custom_[a-z0-9_]+$/', $key);
	}

	/**
	 * Kategorie-IDs aus einem Standard- oder Custom-Schlüssel.
	 *
	 * @return int[]
	 */
	public static function category_ids_for_key(string $key, array $entry = array()): array {
		if (self::is_standard_category_key($key)) {
			$id = (int) substr($key, 4);
			return SRD_KM_Categories::is_valid($id) ? array( $id ) : array();
		}
		if (!self::is_custom_category_key($key)) {
			return array();
		}
		$raw = isset($entry['categories']) && is_array($entry['categories']) ? $entry['categories'] : array();
		$out = array();
		foreach ($raw as $cat_id) {
			$id = absint($cat_id);
			if (SRD_KM_Categories::is_valid($id)) {
				$out[ $id ] = $id;
			}
		}
		return array_values($out);
	}

	/**
	 * Anzeigelabel für einen Kategorie-Dokumenteintrag.
	 */
	public static function label_for_category_key(string $key, array $entry = array()): string {
		if (self::is_standard_category_key($key)) {
			$cat_id = (int) substr($key, 4);
			$cat_label = SRD_KM_Categories::label($cat_id);
			return sprintf(
				/* translators: %s: Kategoriename */
				__('Ausschreibung %s', 'srd-kreismeisterschaften'),
				$cat_label
			);
		}
		if (self::is_custom_category_key($key)) {
			$label = isset($entry['label']) ? sanitize_text_field((string) $entry['label']) : '';
			if ($label !== '') {
				return $label;
			}
		}
		return '';
	}

	/**
	 * Alle konfigurierbaren Kategorie-Schlüssel (Standard + Custom).
	 *
	 * @param array<string, array<string, mixed>> $docs
	 * @return string[]
	 */
	public static function category_keys_in_docs(array $docs): array {
		$keys = array();
		foreach (self::category_types() as $key => $label) {
			unset($label);
			$keys[] = $key;
		}
		foreach ($docs as $key => $entry) {
			if (self::is_custom_category_key((string) $key)) {
				$keys[] = (string) $key;
			}
		}
		return array_values(array_unique($keys));
	}

	/**
	 * Reihenfolge der Kategorieausschreibungen (Fallback: Standard-Kategorien 1–12, dann Custom).
	 *
	 * @param array<string, array<string, mixed>> $docs
	 * @return string[]
	 */
	public static function category_order_for_docs(array $docs): array {
		$stored = isset($docs['category_order']) && is_array($docs['category_order'])
			? $docs['category_order']
			: array();
		$known = self::category_keys_in_docs($docs);
		$known_flip = array_flip($known);
		$order = array();
		foreach ($stored as $key) {
			$key = (string) $key;
			if (isset($known_flip[ $key ])) {
				$order[] = $key;
				unset($known_flip[ $key ]);
			}
		}
		foreach (array_keys($known_flip) as $remaining) {
			$order[] = $remaining;
		}
		return $order;
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array{categories: int[], label: string}
	 */
	public static function sanitize_custom_category_meta($entry): array {
		$empty = array(
			'categories' => array(),
			'label'      => '',
		);
		if (!is_array($entry)) {
			return $empty;
		}
		$label = isset($entry['label']) ? sanitize_text_field((string) $entry['label']) : '';
		$raw_cats = isset($entry['categories']) && is_array($entry['categories']) ? $entry['categories'] : array();
		$categories = array();
		foreach ($raw_cats as $cat_id) {
			$id = absint($cat_id);
			if (SRD_KM_Categories::is_valid($id)) {
				$categories[ $id ] = $id;
			}
		}
		return array(
			'label'      => $label,
			'categories' => array_values($categories),
		);
	}

	/**
	 * @return array<int, array{url: string, label: string, kind: string, is_external: bool, category_ids: int[], key: string}>
	 */
	public static function resolved(): array {
		$docs = self::get_current();
		$fixed = array();
		foreach (self::fixed_types() as $key => $label) {
			$entry = isset($docs[ $key ]) && is_array($docs[ $key ]) ? $docs[ $key ] : array();
			$resolved = self::resolve_entry($entry, $label);
			if ($resolved !== null) {
				$fixed[ $key ] = $resolved;
			}
		}

		$categories = array();
		foreach (self::category_order_for_docs($docs) as $key) {
			$entry = isset($docs[ $key ]) && is_array($docs[ $key ]) ? $docs[ $key ] : array();
			$doc_label = self::label_for_category_key($key, $entry);
			if ($doc_label === '') {
				continue;
			}
			$resolved = self::resolve_entry($entry, $doc_label);
			if ($resolved === null) {
				continue;
			}
			$resolved['category_ids'] = self::category_ids_for_key($key, $entry);
			$resolved['key'] = $key;
			$categories[] = $resolved;
		}

		return array(
			'fixed'      => $fixed,
			'categories' => $categories,
		);
	}

	public static function has_any(): bool {
		$r = self::resolved();
		return $r['fixed'] !== array() || $r['categories'] !== array();
	}

	/**
	 * PDF aus Admin-Upload in die Mediathek legen.
	 *
	 * @return int|\WP_Error Attachment-ID oder Fehler.
	 */
	public static function ingest_pdf_upload(string $file_key) {
		if (empty($_FILES[ $file_key ]['tmp_name']) || !is_uploaded_file((string) $_FILES[ $file_key ]['tmp_name'])) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$overrides = array(
			'test_form' => false,
			'mimes'     => array('pdf' => 'application/pdf'),
		);
		$upload = wp_handle_upload($_FILES[ $file_key ], $overrides);
		if (isset($upload['error'])) {
			return new WP_Error('upload', (string) $upload['error']);
		}
		if (empty($upload['file']) || empty($upload['type']) || $upload['type'] !== 'application/pdf') {
			return new WP_Error('upload', __('Nur PDF-Dateien sind erlaubt.', 'srd-kreismeisterschaften'));
		}
		$attachment = array(
			'post_mime_type' => 'application/pdf',
			'post_title'     => preg_replace('/\.[^.]+$/', '', basename((string) $upload['file'])),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);
		$attach_id = wp_insert_attachment($attachment, $upload['file']);
		if (is_wp_error($attach_id)) {
			return $attach_id;
		}
		wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $upload['file']));
		return (int) $attach_id;
	}

	/**
	 * Frontend-HTML für den Dokumentenblock (immer aktuelle Unterlagen).
	 */
	public static function render_frontend_html(int $highlight_category = 0): string {
		$resolved = self::resolved();
		if ($resolved['fixed'] === array() && $resolved['categories'] === array()) {
			return '';
		}

		ob_start();
		?>
		<section
			id="srd-km-documents"
			class="card-body border-bottom srd-km-documents"
			aria-labelledby="srd-km-documents-heading"
		>
			<h3 id="srd-km-documents-heading" class="h5 mb-3">
				<i class="bi bi-file-earmark-text me-2" aria-hidden="true"></i>
				<?php esc_html_e('Ausschreibung & Unterlagen', 'srd-kreismeisterschaften'); ?>
			</h3>

			<?php if ($resolved['fixed'] !== array()) : ?>
				<div class="srd-km-documents__section mb-3">
					<p class="small text-muted mb-2"><?php esc_html_e('Allgemeine Unterlagen', 'srd-kreismeisterschaften'); ?></p>
					<div class="row g-2 srd-km-documents__grid">
						<?php foreach (self::fixed_types() as $key => $label) : ?>
							<?php
							if (!isset($resolved['fixed'][ $key ])) {
								continue;
							}
							self::render_document_link($resolved['fixed'][ $key ], 'col-12 col-sm-6 col-lg-3');
							?>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ($resolved['categories'] !== array()) : ?>
				<div class="srd-km-documents__section">
					<p class="small text-muted mb-2"><?php esc_html_e('Ausschreibungen je Kategorie', 'srd-kreismeisterschaften'); ?></p>
					<div class="row g-2 srd-km-documents__grid srd-km-documents__grid--categories">
						<?php foreach ($resolved['categories'] as $doc) : ?>
							<?php
							$col = 'col-12 col-sm-6 col-md-4 col-xl-3';
							$cat_ids = isset($doc['category_ids']) && is_array($doc['category_ids']) ? $doc['category_ids'] : array();
							$extra = '';
							if ($highlight_category > 0 && in_array($highlight_category, $cat_ids, true)) {
								$extra = ' srd-km-documents__link--highlight srd-km-cat--' . (int) $highlight_category;
							}
							self::render_document_link($doc, $col, $extra, $cat_ids);
							?>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param array{url: string, label: string, kind: string, is_external: bool} $doc
	 * @param int[] $category_ids
	 */
	private static function render_document_link(array $doc, string $col_class, string $extra_class = '', array $category_ids = array()): void {
		$icon = 'bi-box-arrow-up-right';
		if ($doc['kind'] === 'pdf') {
			$icon = 'bi-file-earmark-pdf';
		} elseif ($doc['kind'] === 'url') {
			$icon = 'bi-calendar-event';
		}
		$target = $doc['is_external'] ? '_blank' : '_self';
		$rel = $doc['is_external'] ? 'noopener noreferrer' : '';
		$cat_attr = '';
		if ($category_ids !== array()) {
			$cat_attr = ' data-srd-km-doc-categories="' . esc_attr(implode(',', array_map('strval', $category_ids))) . '"';
		}
		$cat_badge = '';
		foreach ($category_ids as $category_id) {
			$short = SRD_KM_Categories::label($category_id);
			if ($short !== '') {
				$cat_badge .= '<span class="badge srd-km-cat ' . esc_attr(SRD_KM_Categories::color_class($category_id)) . ' srd-km-documents__cat-badge">' . esc_html($short) . '</span>';
			}
		}
		?>
		<div class="<?php echo esc_attr($col_class); ?>">
			<a
				href="<?php echo esc_url($doc['url']); ?>"
				class="srd-km-documents__link btn btn-outline-primary w-100 text-start<?php echo esc_attr($extra_class); ?>"
				target="<?php echo esc_attr($target); ?>"
				<?php echo $rel !== '' ? 'rel="' . esc_attr($rel) . '"' : ''; ?>
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr oben
				echo $cat_attr;
				?>
			>
				<span class="srd-km-documents__link-inner">
					<i class="bi <?php echo esc_attr($icon); ?> srd-km-documents__icon" aria-hidden="true"></i>
					<span class="srd-km-documents__link-text">
						<?php echo esc_html($doc['label']); ?>
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Badge intern escaped
						echo $cat_badge;
						?>
					</span>
				</span>
			</a>
		</div>
		<?php
	}
}
