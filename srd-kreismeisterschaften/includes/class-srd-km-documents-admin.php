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
		$settings_url = SRD_KM_Capabilities::admin_page_url('srd-kreismeisterschaften');

		$this->render_notices();
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Ausschreibungen & Unterlagen', 'srd-kreismeisterschaften'); ?></h1>
			<p class="description">
				<?php esc_html_e('Legen Sie pro Sportjahr die erforderlichen Dokumente fest: als PDF-Upload oder als Verweis auf eine WordPress-Seite. Leere Einträge werden im Frontend nicht angezeigt.', 'srd-kreismeisterschaften'); ?>
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
				<table class="widefat striped srd-km-documents-admin-table" style="max-width: 960px;">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e('Kategorie', 'srd-kreismeisterschaften'); ?></th>
							<th scope="col"><?php esc_html_e('Art', 'srd-kreismeisterschaften'); ?></th>
							<th scope="col"><?php esc_html_e('Inhalt', 'srd-kreismeisterschaften'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach (SRD_KM_Documents::category_types() as $key => $label) : ?>
							<?php $this->render_document_row($key, $label, $year_docs, $pages); ?>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button(__('Speichern', 'srd-kreismeisterschaften')); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * @param array<string, array<string, mixed>> $year_docs
	 * @param WP_Post[] $pages
	 */
	private function render_document_row(string $key, string $label, array $year_docs, array $pages): void {
		$entry = isset($year_docs[ $key ]) && is_array($year_docs[ $key ]) ? $year_docs[ $key ] : array();
		$type = isset($entry['type']) ? (string) $entry['type'] : '';
		$attachment_id = isset($entry['attachment_id']) ? absint($entry['attachment_id']) : 0;
		$page_id = isset($entry['page_id']) ? absint($entry['page_id']) : 0;
		$pdf_name = '';
		if ($attachment_id > 0) {
			$pdf_name = basename((string) get_attached_file($attachment_id));
		}
		$name_prefix = 'srd_km_documents[' . $key . ']';
		$file_prefix = 'srd_km_documents[' . $key . ']';
		?>
		<tr>
			<td><strong><?php echo esc_html($label); ?></strong></td>
			<td>
				<select name="<?php echo esc_attr($name_prefix . '[type]'); ?>" class="srd-km-doc-type-select">
					<option value="" <?php selected($type, ''); ?>><?php esc_html_e('— keins —', 'srd-kreismeisterschaften'); ?></option>
					<option value="pdf" <?php selected($type, 'pdf'); ?>><?php esc_html_e('PDF', 'srd-kreismeisterschaften'); ?></option>
					<option value="page" <?php selected($type, 'page'); ?>><?php esc_html_e('WordPress-Seite', 'srd-kreismeisterschaften'); ?></option>
				</select>
			</td>
			<td>
				<div class="srd-km-doc-field srd-km-doc-field--pdf">
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
				<div class="srd-km-doc-field srd-km-doc-field--page">
					<select name="<?php echo esc_attr($name_prefix . '[page_id]'); ?>">
						<option value="0"><?php esc_html_e('— Seite wählen —', 'srd-kreismeisterschaften'); ?></option>
						<?php foreach ($pages as $p) : ?>
							<option value="<?php echo esc_attr((string) $p->ID); ?>" <?php selected($page_id, (int) $p->ID); ?>>
								<?php echo esc_html($p->post_title); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</td>
		</tr>
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
