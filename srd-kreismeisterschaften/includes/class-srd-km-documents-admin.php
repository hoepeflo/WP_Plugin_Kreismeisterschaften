<?php
/**
 * Admin: Ausschreibungsdokumente pro Sportjahr.
 *
 * @package SRD_Kreismeisterschaften
 */

if (!defined('ABSPATH')) {
	exit;
}

class SRD_KM_Documents_Admin {

	private static ?self $instance = null;

	public static function init(): void {
		self::instance();
	}

	public static function instance(): self {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action('admin_menu', array($this, 'register_menu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
	}

	public function register_menu(): void {
		add_submenu_page(
			'srd-kreismeisterschaften',
			__('Ausschreibungen', 'srd-kreismeisterschaften'),
			__('Ausschreibungen', 'srd-kreismeisterschaften'),
			SRD_KM_Capabilities::CAP_MANAGE,
			'srd-kreismeisterschaften-documents',
			array($this, 'render_page')
		);
	}

	public function enqueue_assets(string $hook): void {
		if ($hook !== 'srd-kreismeisterschaften_page_srd-kreismeisterschaften-documents') {
			return;
		}
		wp_enqueue_script('jquery-ui-sortable');
		wp_enqueue_script(
			'srd-km-documents-admin',
			SRD_KM_PLUGIN_URL . 'assets/km-documents-admin.js',
			array('jquery', 'jquery-ui-sortable'),
			SRD_KM_VERSION,
			true
		);
		wp_localize_script(
			'srd-km-documents-admin',
			'srdKmDocumentsAdmin',
			array(
				'strings' => array(
					'confirmRemove' => __('Diese Kategorieausschreibung wirklich entfernen?', 'srd-kreismeisterschaften'),
				),
			)
		);
		wp_add_inline_style(
			'wp-admin',
			'.srd-km-doc-drag-handle{cursor:grab;color:#787c82;padding:0 8px 0 0;user-select:none}'
			. '.srd-km-doc-sort-placeholder{outline:2px dashed #c3c4c7;background:#f6f7f7;height:48px}'
			. '.srd-km-doc-field--pdf,.srd-km-doc-field--page{display:none}'
			. '.srd-km-doc-categories-checkboxes{display:flex;flex-wrap:wrap;gap:4px 12px;max-width:420px}'
			. '.srd-km-doc-categories-checkboxes label{display:inline-flex;align-items:center;gap:4px;margin:0;font-size:12px}'
		);
	}

	public function render_page(): void {
		if (!SRD_KM_Capabilities::user_can_manage()) {
			wp_die(esc_html__('Sie haben keinen Zugriff auf diese Seite.', 'srd-kreismeisterschaften'));
		}

		$cy = (int) wp_date('Y');
		$cm = (int) wp_date('n');
		$default_year = ($cm >= 10) ? $cy + 1 : $cy;
		$year = isset($_GET['srd_km_doc_year']) ? absint(wp_unslash($_GET['srd_km_doc_year'])) : $default_year;
		if ($year < 1990 || $year > 2100) {
			$year = $default_year;
		}

		$years = SRD_KM_DB::distinct_sportjahre();
		if ($years === array()) {
			$years = array( $default_year, $cy, $cy - 1 );
		}
		if (!in_array($year, $years, true)) {
			array_unshift($years, $year);
		}
		$years = array_values(array_unique(array_map('intval', $years)));
		rsort($years, SORT_NUMERIC);

		$pages = get_pages(array('sort_column' => 'post_title'));
		$year_docs = SRD_KM_Documents::get_year($year);
		$category_order = SRD_KM_Documents::category_order_for_year($year_docs);
		$settings_url = SRD_KM_Capabilities::admin_page_url('srd-kreismeisterschaften');

		$this->render_notices();
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Ausschreibungen & Unterlagen', 'srd-kreismeisterschaften'); ?></h1>
			<p class="description">
				<?php esc_html_e('Legen Sie pro Sportjahr die erforderlichen Dokumente fest: als PDF-Upload oder als Verweis auf eine WordPress-Seite. Leere Einträge werden im Frontend nicht angezeigt. Eigene Kategorieausschreibungen (z. B. „Kugelbereich“) können mehrere Disziplin-Kategorien zusammenfassen. Die Reihenfolge lässt sich per Drag & Drop ändern.', 'srd-kreismeisterschaften'); ?>
			</p>
			<p>
				<a href="<?php echo esc_url($settings_url); ?>">&larr; <?php esc_html_e('Zurück zu den Einstellungen', 'srd-kreismeisterschaften'); ?></a>
			</p>

			<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="srd-km-doc-year-picker" style="margin-bottom: 1.5em;">
				<input type="hidden" name="page" value="srd-kreismeisterschaften-documents" />
				<label for="srd_km_doc_year_select"><strong><?php esc_html_e('Sportjahr', 'srd-kreismeisterschaften'); ?></strong></label>
				<select name="srd_km_doc_year" id="srd_km_doc_year_select" onchange="this.form.submit()">
					<?php for ($y = $cy + 3; $y >= 1990; $y--) : ?>
						<option value="<?php echo esc_attr((string) $y); ?>" <?php selected($year, $y); ?>><?php echo esc_html((string) $y); ?></option>
					<?php endfor; ?>
				</select>
			</form>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field('srd_km_save_documents', 'srd_km_documents_nonce'); ?>
				<input type="hidden" name="action" value="srd_km_save_documents" />
				<input type="hidden" name="srd_km_doc_year" value="<?php echo esc_attr((string) $year); ?>" />

				<h2><?php esc_html_e('Allgemeine Unterlagen', 'srd-kreismeisterschaften'); ?></h2>
				<table class="widefat striped srd-km-documents-admin-table" style="max-width: 960px;">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e('Dokument', 'srd-kreismeisterschaften'); ?></th>
							<th scope="col"><?php esc_html_e('Art', 'srd-kreismeisterschaften'); ?></th>
							<th scope="col"><?php esc_html_e('Inhalt', 'srd-kreismeisterschaften'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach (SRD_KM_Documents::fixed_types() as $key => $label) : ?>
							<?php $this->render_document_row($key, $label, $year_docs, $pages); ?>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h2 style="margin-top: 2em;"><?php esc_html_e('Ausschreibungen je Kategorie', 'srd-kreismeisterschaften'); ?></h2>
				<p class="description">
					<?php esc_html_e('Standard-Kategorien oder eigene Gruppen (z. B. Kugelbereich für Gewehr, Pistole, Auflage und Parasport). Reihenfolge per Ziehen am Griff-Symbol ändern.', 'srd-kreismeisterschaften'); ?>
				</p>
				<table class="widefat striped srd-km-documents-admin-table" style="max-width: 960px;">
					<thead>
						<tr>
							<th scope="col" style="width:2em;"></th>
							<th scope="col"><?php esc_html_e('Bezeichnung / Kategorie', 'srd-kreismeisterschaften'); ?></th>
							<th scope="col"><?php esc_html_e('Art', 'srd-kreismeisterschaften'); ?></th>
							<th scope="col"><?php esc_html_e('Inhalt', 'srd-kreismeisterschaften'); ?></th>
							<th scope="col" style="width:4em;"></th>
						</tr>
					</thead>
					<tbody id="srd-km-category-docs-tbody">
						<?php foreach ($category_order as $key) : ?>
							<?php
							if (SRD_KM_Documents::is_standard_category_key($key)) {
								$label = SRD_KM_Documents::label_for_category_key($key);
								$this->render_category_document_row($key, $label, $year_docs, $pages, false);
							} elseif (SRD_KM_Documents::is_custom_category_key($key)) {
								$entry = isset($year_docs[ $key ]) && is_array($year_docs[ $key ]) ? $year_docs[ $key ] : array();
								$label = SRD_KM_Documents::label_for_category_key($key, $entry);
								$this->render_category_document_row($key, $label, $year_docs, $pages, true);
							}
							?>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<button type="button" class="button" id="srd-km-add-custom-doc">
						<?php esc_html_e('+ Eigene Kategorieausschreibung', 'srd-kreismeisterschaften'); ?>
					</button>
				</p>

				<?php submit_button(__('Speichern', 'srd-kreismeisterschaften')); ?>
			</form>

			<script type="text/template" id="srd-km-custom-doc-row-template">
				<?php
				ob_start();
				$this->render_category_document_row('__KEY__', '', array(), $pages, true);
				echo ob_get_clean();
				?>
			</script>
		</div>
		<?php
	}

	/**
	 * @param array<string, array<string, mixed>> $year_docs
	 * @param WP_Post[] $pages
	 */
	private function render_document_row(string $key, string $label, array $year_docs, array $pages): void {
		$entry = isset($year_docs[ $key ]) && is_array($year_docs[ $key ]) ? $year_docs[ $key ] : array();
		?>
		<tr>
			<td><strong><?php echo esc_html($label); ?></strong></td>
			<td><?php $this->render_type_select($key, $entry); ?></td>
			<td><?php $this->render_content_fields($key, $entry, $pages); ?></td>
		</tr>
		<?php
	}

	/**
	 * @param array<string, array<string, mixed>> $year_docs
	 * @param WP_Post[] $pages
	 */
	private function render_category_document_row(string $key, string $label, array $year_docs, array $pages, bool $is_custom): void {
		$entry = isset($year_docs[ $key ]) && is_array($year_docs[ $key ]) ? $year_docs[ $key ] : array();
		$meta = SRD_KM_Documents::sanitize_custom_category_meta($entry);
		$selected_cats = $meta['categories'];
		$custom_label = $is_custom ? $meta['label'] : '';
		?>
		<tr class="srd-km-category-doc-row" data-doc-key="<?php echo esc_attr($key); ?>">
			<td>
				<span class="srd-km-doc-drag-handle dashicons dashicons-menu" title="<?php esc_attr_e('Reihenfolge ändern', 'srd-kreismeisterschaften'); ?>" aria-hidden="true"></span>
				<input type="hidden" name="srd_km_category_order[]" value="<?php echo esc_attr($key); ?>" />
			</td>
			<td>
				<?php if ($is_custom) : ?>
					<input
						type="text"
						class="regular-text"
						name="<?php echo esc_attr('srd_km_documents[' . $key . '][label]'); ?>"
						value="<?php echo esc_attr($custom_label); ?>"
						placeholder="<?php esc_attr_e('z. B. Kugelbereich', 'srd-kreismeisterschaften'); ?>"
					/>
					<div class="srd-km-doc-categories-checkboxes" style="margin-top:6px;">
						<?php foreach (SRD_KM_Categories::labels() as $cat_id => $cat_label) : ?>
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr('srd_km_documents[' . $key . '][categories][]'); ?>"
									value="<?php echo esc_attr((string) $cat_id); ?>"
									<?php checked(in_array($cat_id, $selected_cats, true)); ?>
								/>
								<?php echo esc_html($cat_label); ?>
							</label>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<strong><?php echo esc_html($label); ?></strong>
					<input type="hidden" name="<?php echo esc_attr('srd_km_documents[' . $key . '][standard]'); ?>" value="1" />
				<?php endif; ?>
			</td>
			<td><?php $this->render_type_select($key, $entry); ?></td>
			<td><?php $this->render_content_fields($key, $entry, $pages); ?></td>
			<td>
				<?php if ($is_custom) : ?>
					<button type="button" class="button-link-delete srd-km-remove-custom-doc" aria-label="<?php esc_attr_e('Entfernen', 'srd-kreismeisterschaften'); ?>">&times;</button>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private function render_type_select(string $key, array $entry): void {
		$type = isset($entry['type']) ? (string) $entry['type'] : '';
		$name_prefix = 'srd_km_documents[' . $key . ']';
		?>
		<select name="<?php echo esc_attr($name_prefix . '[type]'); ?>" class="srd-km-doc-type-select">
			<option value="" <?php selected($type, ''); ?>><?php esc_html_e('— keins —', 'srd-kreismeisterschaften'); ?></option>
			<option value="pdf" <?php selected($type, 'pdf'); ?>><?php esc_html_e('PDF', 'srd-kreismeisterschaften'); ?></option>
			<option value="page" <?php selected($type, 'page'); ?>><?php esc_html_e('WordPress-Seite', 'srd-kreismeisterschaften'); ?></option>
		</select>
		<?php
	}

	/**
	 * @param array<string, mixed> $entry
	 * @param WP_Post[] $pages
	 */
	private function render_content_fields(string $key, array $entry, array $pages): void {
		$type = isset($entry['type']) ? (string) $entry['type'] : '';
		$attachment_id = isset($entry['attachment_id']) ? absint($entry['attachment_id']) : 0;
		$page_id = isset($entry['page_id']) ? absint($entry['page_id']) : 0;
		$pdf_name = '';
		if ($attachment_id > 0) {
			$pdf_name = basename((string) get_attached_file($attachment_id));
		}
		$name_prefix = 'srd_km_documents[' . $key . ']';
		$file_prefix = 'srd_km_documents_files[' . $key . ']';
		?>
		<div class="srd-km-doc-field srd-km-doc-field--pdf"<?php echo $type === 'pdf' ? '' : ' style="display:none"'; ?>>
			<?php if ($pdf_name !== '') : ?>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: Dateiname */
							__('Aktuell: %s', 'srd-kreismeisterschaften'),
							$pdf_name
						)
					);
					?>
				</p>
				<input type="hidden" name="<?php echo esc_attr($name_prefix . '[attachment_id]'); ?>" value="<?php echo esc_attr((string) $attachment_id); ?>" />
			<?php endif; ?>
			<input type="file" name="<?php echo esc_attr($file_prefix . '[pdf_file]'); ?>" accept=".pdf,application/pdf" />
			<p class="description"><?php esc_html_e('Neue PDF ersetzt die bestehende Datei.', 'srd-kreismeisterschaften'); ?></p>
		</div>
		<div class="srd-km-doc-field srd-km-doc-field--page"<?php echo $type === 'page' ? '' : ' style="display:none"'; ?>>
			<select name="<?php echo esc_attr($name_prefix . '[page_id]'); ?>">
				<option value="0"><?php esc_html_e('— Seite wählen —', 'srd-kreismeisterschaften'); ?></option>
				<?php foreach ($pages as $p) : ?>
					<option value="<?php echo esc_attr((string) $p->ID); ?>" <?php selected($page_id, (int) $p->ID); ?>>
						<?php echo esc_html($p->post_title); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}

	private function render_notices(): void {
		if (!isset($_GET['srd_km_doc'])) {
			return;
		}
		$status = sanitize_key((string) wp_unslash($_GET['srd_km_doc']));
		$code = isset($_GET['srd_km_doc_c']) ? sanitize_key((string) wp_unslash($_GET['srd_km_doc_c'])) : '';
		if ($status === 'ok') {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__('Die Dokumente wurden gespeichert.', 'srd-kreismeisterschaften')
			);
			return;
		}
		if ($status !== 'err') {
			return;
		}
		$map = array(
			'bad_year' => __('Ungültiges Sportjahr.', 'srd-kreismeisterschaften'),
			'upload'   => __('Mindestens eine PDF konnte nicht hochgeladen werden.', 'srd-kreismeisterschaften'),
		);
		$text = $map[ $code ] ?? __('Speichern fehlgeschlagen.', 'srd-kreismeisterschaften');
		printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html($text));
	}
}
