<?php
/**
 * Admin: Kategorien (CRUD, aktiv/deaktiv).
 *
 * @package SRD_Kreismeisterschaften
 */

if (!defined('ABSPATH')) {
	exit;
}

class SRD_KM_Categories_Admin {

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
			__('KM Kategorien', 'srd-kreismeisterschaften'),
			__('Kategorien', 'srd-kreismeisterschaften'),
			SRD_KM_Capabilities::CAP_MANAGE,
			'srd-kreismeisterschaften-categories',
			array($this, 'render_page')
		);
	}

	public function render_page(): void {
		if (!SRD_KM_Capabilities::user_can_manage()) {
			wp_die(esc_html__('Sie haben keinen Zugriff auf diese Seite.', 'srd-kreismeisterschaften'));
		}
		$action = isset($_GET['action']) ? sanitize_key((string) wp_unslash($_GET['action'])) : '';
		if ($action === 'edit' || $action === 'add') {
			$this->render_edit_form($action === 'add');
			return;
		}
		$this->render_list();
	}

	private function render_list(): void {
		$this->render_admin_notices();
		$categories = SRD_KM_Categories::get_all();
		$list_url = SRD_KM_Capabilities::admin_page_url('srd-kreismeisterschaften-categories');
		$settings_url = SRD_KM_Capabilities::admin_page_url('srd-kreismeisterschaften');
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e('Kategorien', 'srd-kreismeisterschaften'); ?></h1>
			<a href="<?php echo esc_url(add_query_arg('action', 'add', $list_url)); ?>" class="page-title-action">
				<?php esc_html_e('Neue Kategorie', 'srd-kreismeisterschaften'); ?>
			</a>
			<hr class="wp-header-end" />
			<p>
				<a href="<?php echo esc_url($settings_url); ?>">&larr; <?php esc_html_e('Zurück zu den Einstellungen', 'srd-kreismeisterschaften'); ?></a>
			</p>
			<p class="description">
				<?php esc_html_e('Kategorien werden anhand des Präfixes (führende Ziffern der Datei-ID) den Disziplinen zugeordnet. Deaktivierte Kategorien erscheinen nicht im Frontend-Filter, bleiben aber in der Disziplinenliste sichtbar.', 'srd-kreismeisterschaften'); ?>
			</p>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col" class="column-id"><?php esc_html_e('ID', 'srd-kreismeisterschaften'); ?></th>
						<th scope="col"><?php esc_html_e('Bezeichnung', 'srd-kreismeisterschaften'); ?></th>
						<th scope="col"><?php esc_html_e('Präfix (Datei-ID)', 'srd-kreismeisterschaften'); ?></th>
						<th scope="col"><?php esc_html_e('Reihenfolge', 'srd-kreismeisterschaften'); ?></th>
						<th scope="col"><?php esc_html_e('Status', 'srd-kreismeisterschaften'); ?></th>
						<th scope="col"><?php esc_html_e('Aktionen', 'srd-kreismeisterschaften'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ($categories === array()) : ?>
						<tr>
							<td colspan="6"><?php esc_html_e('Keine Kategorien vorhanden.', 'srd-kreismeisterschaften'); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ($categories as $cat) : ?>
							<?php
							$cat_id = (int) $cat['id'];
							$edit_url = add_query_arg(
								array(
									'action' => 'edit',
									'id'     => $cat_id,
								),
								$list_url
							);
							?>
							<tr>
								<td><?php echo esc_html((string) $cat_id); ?></td>
								<td><strong><?php echo esc_html((string) $cat['label']); ?></strong></td>
								<td><code><?php echo esc_html((string) $cat['prefix']); ?></code></td>
								<td><?php echo esc_html((string) (int) $cat['order']); ?></td>
								<td>
									<?php if (!empty($cat['active'])) : ?>
										<span class="dashicons dashicons-yes-alt" style="color:#2f6806" aria-hidden="true"></span>
										<?php esc_html_e('Aktiv', 'srd-kreismeisterschaften'); ?>
									<?php else : ?>
										<span class="dashicons dashicons-hidden" style="color:#787c82" aria-hidden="true"></span>
										<?php esc_html_e('Deaktiviert', 'srd-kreismeisterschaften'); ?>
									<?php endif; ?>
								</td>
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

	private function render_edit_form(bool $is_new): void {
		$this->render_admin_notices();
		$list_url = SRD_KM_Capabilities::admin_page_url('srd-kreismeisterschaften-categories');
		$id = isset($_GET['id']) ? absint($_GET['id']) : 0;
		$row = array(
			'id'     => $is_new ? SRD_KM_Categories::suggest_next_id() : $id,
			'label'  => '',
			'prefix' => '',
			'active' => 1,
			'order'  => SRD_KM_Categories::suggest_next_id(),
		);

		if (!$is_new) {
			if ($id <= 0 || !SRD_KM_Categories::exists($id)) {
				wp_safe_redirect($list_url);
				exit;
			}
			$existing = SRD_KM_Categories::record($id);
			if ($existing !== null) {
				$row = $existing;
			}
		}

		$title = $is_new
			? __('Neue Kategorie', 'srd-kreismeisterschaften')
			: __('Kategorie bearbeiten', 'srd-kreismeisterschaften');
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html($title); ?></h1>
			<p><a href="<?php echo esc_url($list_url); ?>">&larr; <?php esc_html_e('Zurück zur Übersicht', 'srd-kreismeisterschaften'); ?></a></p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('srd_km_save_category', 'srd_km_category_nonce'); ?>
				<input type="hidden" name="action" value="srd_km_save_category" />
				<input type="hidden" name="srd_km_category_is_new" value="<?php echo $is_new ? '1' : '0'; ?>" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="srd_km_cat_id"><?php esc_html_e('Kategorie-ID', 'srd-kreismeisterschaften'); ?></label></th>
						<td>
							<?php if ($is_new) : ?>
								<input type="number" class="small-text" name="srd_km_category[id]" id="srd_km_cat_id"
									value="<?php echo esc_attr((string) (int) $row['id']); ?>" min="1" max="999" required />
								<p class="description"><?php esc_html_e('Eindeutige Zahl (1–999). Wird u. a. für Farben und Dokumente verwendet.', 'srd-kreismeisterschaften'); ?></p>
							<?php else : ?>
								<strong><?php echo esc_html((string) (int) $row['id']); ?></strong>
								<input type="hidden" name="srd_km_category[id]" value="<?php echo esc_attr((string) (int) $row['id']); ?>" />
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="srd_km_cat_label"><?php esc_html_e('Bezeichnung', 'srd-kreismeisterschaften'); ?></label></th>
						<td>
							<input type="text" class="regular-text" name="srd_km_category[label]" id="srd_km_cat_label"
								value="<?php echo esc_attr((string) $row['label']); ?>" required />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="srd_km_cat_prefix"><?php esc_html_e('Präfix (Datei-ID)', 'srd-kreismeisterschaften'); ?></label></th>
						<td>
							<input type="text" class="small-text" name="srd_km_category[prefix]" id="srd_km_cat_prefix"
								value="<?php echo esc_attr((string) $row['prefix']); ?>" pattern="[0-9]+" required />
							<p class="description">
								<?php esc_html_e('Führende Ziffern der Datei-ID, z. B. „11“ für Lichtschießen (11…). Längere Präfixe haben Vorrang.', 'srd-kreismeisterschaften'); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="srd_km_cat_order"><?php esc_html_e('Reihenfolge', 'srd-kreismeisterschaften'); ?></label></th>
						<td>
							<input type="number" class="small-text" name="srd_km_category[order]" id="srd_km_cat_order"
								value="<?php echo esc_attr((string) (int) $row['order']); ?>" min="0" max="9999" />
							<p class="description"><?php esc_html_e('Sortierung im Frontend-Filter (aufsteigend).', 'srd-kreismeisterschaften'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Aktiv', 'srd-kreismeisterschaften'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="srd_km_category[active]" value="1" <?php checked(!empty($row['active'])); ?> />
								<?php esc_html_e('Im Frontend-Filter anzeigen', 'srd-kreismeisterschaften'); ?>
							</label>
							<p class="description"><?php esc_html_e('Deaktivierte Kategorien können weiterhin Disziplinen zugeordnet werden, erscheinen aber nicht in der Filterleiste.', 'srd-kreismeisterschaften'); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button($is_new ? __('Anlegen', 'srd-kreismeisterschaften') : __('Speichern', 'srd-kreismeisterschaften')); ?>
			</form>
			<?php if (!$is_new && (int) $row['id'] > 0) : ?>
				<hr />
				<h2><?php esc_html_e('Kategorie löschen', 'srd-kreismeisterschaften'); ?></h2>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
					onsubmit="return confirm('<?php echo esc_js(__('Diese Kategorie wirklich löschen?', 'srd-kreismeisterschaften')); ?>');">
					<?php wp_nonce_field('srd_km_delete_category', 'srd_km_category_delete_nonce'); ?>
					<input type="hidden" name="action" value="srd_km_delete_category" />
					<input type="hidden" name="srd_km_category_id" value="<?php echo esc_attr((string) (int) $row['id']); ?>" />
					<?php submit_button(__('Löschen', 'srd-kreismeisterschaften'), 'delete', 'submit', false); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_admin_notices(): void {
		if (!isset($_GET['srd_km_cat'])) {
			return;
		}
		$status = sanitize_key((string) wp_unslash($_GET['srd_km_cat']));
		$code = isset($_GET['srd_km_cat_c']) ? sanitize_key((string) wp_unslash($_GET['srd_km_cat_c'])) : '';
		if ($status === 'ok') {
			$map = array(
				'created' => __('Kategorie wurde angelegt.', 'srd-kreismeisterschaften'),
				'updated' => __('Kategorie wurde gespeichert.', 'srd-kreismeisterschaften'),
				'deleted' => __('Kategorie wurde gelöscht.', 'srd-kreismeisterschaften'),
			);
			$text = $map[ $code ] ?? __('Aktion erfolgreich.', 'srd-kreismeisterschaften');
			printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($text));
			return;
		}
		if ($status !== 'err') {
			return;
		}
		$map = array(
			'not_found'       => __('Kategorie wurde nicht gefunden.', 'srd-kreismeisterschaften'),
			'invalid_data'    => __('Ungültige oder unvollständige Eingaben.', 'srd-kreismeisterschaften'),
			'invalid_id'      => __('Ungültige Kategorie-ID.', 'srd-kreismeisterschaften'),
			'duplicate_id'    => __('Diese Kategorie-ID ist bereits vergeben.', 'srd-kreismeisterschaften'),
			'duplicate_prefix'=> __('Dieses Präfix wird bereits von einer anderen Kategorie verwendet.', 'srd-kreismeisterschaften'),
			'last_category'   => __('Die letzte Kategorie kann nicht gelöscht werden.', 'srd-kreismeisterschaften'),
		);
		$text = $map[ $code ] ?? __('Aktion fehlgeschlagen.', 'srd-kreismeisterschaften');
		printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html($text));
	}

}
