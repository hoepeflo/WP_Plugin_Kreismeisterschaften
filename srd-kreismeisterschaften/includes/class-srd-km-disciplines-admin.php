<?php
/**
 * Admin: Disziplinen (CRUD) für Tabelle srd_kreis_v3.
 *
 * @package SRD_Kreismeisterschaften
 */

if (!defined('ABSPATH')) {
	exit;
}

class SRD_KM_Disciplines_Admin {

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
			__('KM Disziplinen', 'srd-kreismeisterschaften'),
			__('Disziplinen', 'srd-kreismeisterschaften'),
			SRD_KM_Capabilities::CAP_MANAGE,
			'srd-kreismeisterschaften-disciplines',
			array($this, 'render_page')
		);
	}

	public function render_page(): void {
		if (!SRD_KM_Capabilities::user_can_manage()) {
			wp_die(esc_html__('Sie haben keinen Zugriff auf diese Seite.', 'srd-kreismeisterschaften'));
		}
		$action = isset($_GET['action']) ? sanitize_key((string) wp_unslash($_GET['action'])) : '';
		if ($action === 'edit' || $action === 'add') {
			$this->render_edit_form($action);
			return;
		}
		$this->render_list();
	}

	private function render_list(): void {
		$this->render_admin_notices();
		$pk = SRD_KM_DB::kreis_v3_primary_key();
		$rows = SRD_KM_DB::kreis_rows_v3();
		$list_url = SRD_KM_Capabilities::admin_page_url('srd-kreismeisterschaften-disciplines');
		$settings_url = SRD_KM_Capabilities::admin_page_url('srd-kreismeisterschaften');
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e('Disziplinen (Kugel)', 'srd-kreismeisterschaften'); ?></h1>
			<?php if (SRD_KM_DB::table_available()) : ?>
				<a href="<?php echo esc_url(add_query_arg('action', 'add', $list_url)); ?>" class="page-title-action"><?php esc_html_e('Neue Disziplin', 'srd-kreismeisterschaften'); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end" />
			<p>
				<a href="<?php echo esc_url($settings_url); ?>">&larr; <?php esc_html_e('Zurück zu den Einstellungen', 'srd-kreismeisterschaften'); ?></a>
			</p>
			<p class="description">
				<?php esc_html_e('Einträge aus der SRD-Tabelle srd_kreis_v3. Sie steuern die Kugel-Disziplinenliste im Frontend; Ergebnisdateien liegen weiterhin unter results/km_JJJJ/.', 'srd-kreismeisterschaften'); ?>
			</p>
			<?php if (!SRD_KM_DB::table_available()) : ?>
				<div class="notice notice-error"><p>
					<?php esc_html_e('Die Tabelle srd_kreis_v3 ist nicht erreichbar oder hat keinen Primärschlüssel. Bitte Datenbankverbindung unter „SRD Kreismeisterschaften“ prüfen.', 'srd-kreismeisterschaften'); ?>
				</p></div>
				<?php return; ?>
			<?php endif; ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<?php if ($pk) : ?>
							<th scope="col" class="column-id"><?php echo esc_html($pk); ?></th>
						<?php endif; ?>
						<th scope="col"><?php esc_html_e('Disziplin', 'srd-kreismeisterschaften'); ?></th>
						<th scope="col"><?php esc_html_e('Altersklasse', 'srd-kreismeisterschaften'); ?></th>
						<th scope="col"><?php esc_html_e('SpO', 'srd-kreismeisterschaften'); ?></th>
						<th scope="col"><?php esc_html_e('Datei-ID', 'srd-kreismeisterschaften'); ?></th>
						<?php if (isset(SRD_KM_DB::kreis_v3_column_meta()['sportjahr'])) : ?>
							<th scope="col"><?php esc_html_e('Sportjahr', 'srd-kreismeisterschaften'); ?></th>
						<?php endif; ?>
						<th scope="col"><?php esc_html_e('Aktionen', 'srd-kreismeisterschaften'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ($rows === array()) : ?>
						<tr>
							<td colspan="7"><?php esc_html_e('Keine Disziplinen vorhanden.', 'srd-kreismeisterschaften'); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ($rows as $row) : ?>
							<?php
							$row_id = $pk ? (int) ($row[ $pk ] ?? 0) : 0;
							$edit_url = add_query_arg(
								array(
									'action' => 'edit',
									'id'     => $row_id,
								),
								$list_url
							);
							?>
							<tr>
								<?php if ($pk) : ?>
									<td><?php echo esc_html((string) $row_id); ?></td>
								<?php endif; ?>
								<td><strong><?php echo esc_html((string) ($row['disziplin'] ?? '')); ?></strong></td>
								<td><?php echo esc_html((string) ($row['altersklasse'] ?? '')); ?></td>
								<td><?php echo esc_html((string) ($row['spo'] ?? '')); ?></td>
								<td><code><?php echo esc_html((string) ($row['datei'] ?? '')); ?></code></td>
								<?php if (isset(SRD_KM_DB::kreis_v3_column_meta()['sportjahr'])) : ?>
									<td><?php echo esc_html((string) ($row['sportjahr'] ?? '')); ?></td>
								<?php endif; ?>
								<td>
									<a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Bearbeiten', 'srd-kreismeisterschaften'); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_edit_form(string $action): void {
		$this->render_admin_notices();
		$pk = SRD_KM_DB::kreis_v3_primary_key();
		$list_url = SRD_KM_Capabilities::admin_page_url('srd-kreismeisterschaften-disciplines');
		$id = isset($_GET['id']) ? absint($_GET['id']) : 0;
		$row = array();
		$is_new = ($action === 'add');

		if (!$is_new) {
			if ($id <= 0 || $pk === null) {
				wp_safe_redirect($list_url);
				exit;
			}
			$fetched = SRD_KM_DB::kreis_row_by_id($id);
			if ($fetched === null) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'srd_km_disc' => 'err',
							'srd_km_disc_c' => 'not_found',
						),
						$list_url
					)
				);
				exit;
			}
			$row = $fetched;
		}

		if (!SRD_KM_DB::table_available()) {
			wp_safe_redirect($list_url);
			exit;
		}

		$fields = SRD_KM_DB::kreis_v3_editable_fields();
		$labels = array(
			'disziplin'    => __('Disziplin', 'srd-kreismeisterschaften'),
			'altersklasse' => __('Altersklasse', 'srd-kreismeisterschaften'),
			'spo'          => __('SpO', 'srd-kreismeisterschaften'),
			'datei'        => __('Datei-ID (für e/m-Ergebnisse)', 'srd-kreismeisterschaften'),
			'sportjahr'    => __('Sportjahr', 'srd-kreismeisterschaften'),
		);
		$title = $is_new
			? __('Neue Disziplin', 'srd-kreismeisterschaften')
			: __('Disziplin bearbeiten', 'srd-kreismeisterschaften');
		?>
		<div class="wrap">
			<h1><?php echo esc_html($title); ?></h1>
			<p><a href="<?php echo esc_url($list_url); ?>">&larr; <?php esc_html_e('Zurück zur Liste', 'srd-kreismeisterschaften'); ?></a></p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('srd_km_save_discipline', 'srd_km_discipline_nonce'); ?>
				<input type="hidden" name="action" value="srd_km_save_discipline" />
				<input type="hidden" name="srd_km_discipline_id" value="<?php echo esc_attr($is_new ? '0' : (string) $id); ?>" />
				<table class="form-table" role="presentation">
					<?php foreach ($fields as $field) : ?>
						<tr>
							<th scope="row">
								<label for="srd_km_field_<?php echo esc_attr($field); ?>">
									<?php echo esc_html($labels[ $field ] ?? $field); ?>
								</label>
							</th>
							<td>
								<?php if ($field === 'sportjahr') : ?>
									<input type="number" class="small-text" name="srd_km_discipline[<?php echo esc_attr($field); ?>]" id="srd_km_field_<?php echo esc_attr($field); ?>"
										value="<?php echo esc_attr((string) ($row[ $field ] ?? '')); ?>" min="1990" max="2100" step="1" />
								<?php else : ?>
									<input type="text" class="regular-text" name="srd_km_discipline[<?php echo esc_attr($field); ?>]" id="srd_km_field_<?php echo esc_attr($field); ?>"
										value="<?php echo esc_attr((string) ($row[ $field ] ?? '')); ?>" <?php echo $field === 'datei' ? 'pattern="[a-zA-Z0-9_-]+" required' : ''; ?> />
								<?php endif; ?>
								<?php if ($field === 'datei') : ?>
									<p class="description"><?php esc_html_e('Nur Buchstaben, Ziffern, Unterstrich und Bindestrich. Ergebnisdateien: km_JJJJ/e{ID}.pdf bzw. m{ID}.pdf', 'srd-kreismeisterschaften'); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button($is_new ? __('Anlegen', 'srd-kreismeisterschaften') : __('Speichern', 'srd-kreismeisterschaften')); ?>
			</form>
			<?php if (!$is_new && $id > 0) : ?>
				<hr />
				<h2><?php esc_html_e('Disziplin löschen', 'srd-kreismeisterschaften'); ?></h2>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Diese Disziplin wirklich löschen?', 'srd-kreismeisterschaften')); ?>');">
					<?php wp_nonce_field('srd_km_delete_discipline', 'srd_km_discipline_delete_nonce'); ?>
					<input type="hidden" name="action" value="srd_km_delete_discipline" />
					<input type="hidden" name="srd_km_discipline_id" value="<?php echo esc_attr((string) $id); ?>" />
					<?php submit_button(__('Löschen', 'srd-kreismeisterschaften'), 'delete', 'submit', false); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_admin_notices(): void {
		if (!isset($_GET['srd_km_disc'])) {
			return;
		}
		$status = sanitize_key((string) wp_unslash($_GET['srd_km_disc']));
		$code = isset($_GET['srd_km_disc_c']) ? sanitize_key((string) wp_unslash($_GET['srd_km_disc_c'])) : '';
		if ($status === 'ok') {
			$map = array(
				'created' => __('Disziplin wurde angelegt.', 'srd-kreismeisterschaften'),
				'updated' => __('Disziplin wurde gespeichert.', 'srd-kreismeisterschaften'),
				'deleted' => __('Disziplin wurde gelöscht.', 'srd-kreismeisterschaften'),
			);
			$text = $map[ $code ] ?? __('Aktion erfolgreich.', 'srd-kreismeisterschaften');
			printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($text));
			return;
		}
		if ($status !== 'err') {
			return;
		}
		$map = array(
			'not_found'   => __('Disziplin wurde nicht gefunden.', 'srd-kreismeisterschaften'),
			'no_db'       => __('Keine Datenbankverbindung.', 'srd-kreismeisterschaften'),
			'no_table'    => __('Tabelle srd_kreis_v3 ist nicht verfügbar.', 'srd-kreismeisterschaften'),
			'invalid_id'  => __('Ungültige ID.', 'srd-kreismeisterschaften'),
			'invalid_data'=> __('Ungültige oder unvollständige Eingaben.', 'srd-kreismeisterschaften'),
			'invalid_datei' => __('Ungültige Datei-ID (nur a-z, A-Z, 0-9, _ und -).', 'srd-kreismeisterschaften'),
			'save_failed' => __('Speichern fehlgeschlagen.', 'srd-kreismeisterschaften'),
			'delete_failed' => __('Löschen fehlgeschlagen.', 'srd-kreismeisterschaften'),
		);
		$text = $map[ $code ] ?? __('Aktion fehlgeschlagen.', 'srd-kreismeisterschaften');
		printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html($text));
	}
}
