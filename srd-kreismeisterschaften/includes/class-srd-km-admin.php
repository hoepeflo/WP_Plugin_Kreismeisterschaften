<?php
/**
 * Einstellungsseite unter Einstellungen → SRD Kreismeisterschaften.
 *
 * @package SRD_Kreismeisterschaften
 */

if (!defined('ABSPATH')) {
	exit;
}

class SRD_KM_Admin {

	private static ?self $instance = null;

	public static function instance(): self {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action('admin_menu', array($this, 'register_menu'));
		add_action('admin_init', array($this, 'register_settings'));
	}

	public function register_menu(): void {
		add_options_page(
			__('SRD Kreismeisterschaften', 'srd-kreismeisterschaften'),
			__('SRD Kreismeisterschaften', 'srd-kreismeisterschaften'),
			'manage_options',
			'srd-kreismeisterschaften',
			array($this, 'render_page')
		);
	}

	public function register_settings(): void {
		register_setting(
			'srd_km_settings_group',
			'srd_km_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array($this, 'sanitize_settings'),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function sanitize_settings($input): array {
		$out = srd_km_get_settings();
		$prev = is_array(get_option('srd_km_settings', array())) ? get_option('srd_km_settings', array()) : array();
		if (!is_array($input)) {
			return $out;
		}
		$out['page_id'] = isset($input['page_id']) ? absint($input['page_id']) : 0;
		$out['db_use_wp'] = empty($input['db_use_wp']) ? 0 : 1;
		$out['db_host'] = isset($input['db_host']) ? sanitize_text_field((string) $input['db_host']) : '';
		$out['db_user'] = isset($input['db_user']) ? sanitize_text_field((string) $input['db_user']) : '';
		$pass_in = isset($input['db_pass']) ? (string) $input['db_pass'] : '';
		$out['db_pass'] = ($pass_in === '' && isset($prev['db_pass'])) ? (string) $prev['db_pass'] : $pass_in;
		$out['db_name'] = isset($input['db_name']) ? sanitize_text_field((string) $input['db_name']) : '';
		$out['results_path'] = isset($input['results_path']) ? sanitize_text_field((string) $input['results_path']) : '';
		$out['results_url'] = isset($input['results_url']) ? esc_url_raw((string) $input['results_url']) : '';
		$out['home_url_custom'] = isset($input['home_url_custom']) ? esc_url_raw((string) $input['home_url_custom']) : '';
		$out['rewrite_enabled'] = empty($input['rewrite_enabled']) ? 0 : 1;
		$slug = isset($input['rewrite_slug']) ? sanitize_title((string) $input['rewrite_slug']) : 'kreismeisterschaften';
		$out['rewrite_slug'] = $slug !== '' ? $slug : 'kreismeisterschaften';
		return $out;
	}

	public function render_page(): void {
		if (!current_user_can('manage_options')) {
			return;
		}
		$s = srd_km_get_settings();
		$pages = get_pages(array('sort_column' => 'post_title'));
		$this->render_upload_admin_notices();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__('SRD Kreismeisterschaften', 'srd-kreismeisterschaften'); ?></h1>
			<p><?php echo esc_html__('Legen Sie die Seite mit dem Shortcode [srd_km] fest und den Pfad zu Ihrem results-Ordner (wie im bisherigen SRD-Projekt).', 'srd-kreismeisterschaften'); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields('srd_km_settings_group'); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="srd_km_page_id"><?php esc_html_e('KM-Seite (mit [srd_km])', 'srd-kreismeisterschaften'); ?></label></th>
						<td>
							<select name="srd_km_settings[page_id]" id="srd_km_page_id">
								<option value="0"><?php esc_html_e('— bitte wählen —', 'srd-kreismeisterschaften'); ?></option>
								<?php foreach ($pages as $p) : ?>
									<option value="<?php echo esc_attr((string) $p->ID); ?>" <?php selected((int) $s['page_id'], (int) $p->ID); ?>>
										<?php echo esc_html($p->post_title); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Pretty-Permalinks', 'srd-kreismeisterschaften'); ?></th>
						<td>
							<input type="hidden" name="srd_km_settings[rewrite_enabled]" value="0" />
							<label>
								<input type="checkbox" name="srd_km_settings[rewrite_enabled]" value="1" <?php checked(!empty($s['rewrite_enabled'])); ?> />
								<?php esc_html_e('Schöne URLs unter einem eigenen Slug (z. B. /kreismeisterschaften/2025/)', 'srd-kreismeisterschaften'); ?>
							</label>
							<p class="description"><?php esc_html_e('Erfordert eine gewählte KM-Seite und WordPress-Permalinks ≠ „Einfach“. Nach Änderungen werden die Rewrite-Regeln automatisch neu geschrieben.', 'srd-kreismeisterschaften'); ?></p>
							<p>
								<label for="srd_km_rewrite_slug"><?php esc_html_e('URL-Slug', 'srd-kreismeisterschaften'); ?><br />
									<input type="text" class="regular-text" name="srd_km_settings[rewrite_slug]" id="srd_km_rewrite_slug" value="<?php echo esc_attr((string) ($s['rewrite_slug'] ?? 'kreismeisterschaften')); ?>" /></label>
							</p>
							<p class="description"><?php esc_html_e('Der Slug darf nicht mit einer anderen öffentlichen Route kollidieren (z. B. gleichlautende Seite).', 'srd-kreismeisterschaften'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Datenbank (SRD-Tabellen)', 'srd-kreismeisterschaften'); ?></th>
						<td>
							<input type="hidden" name="srd_km_settings[db_use_wp]" value="0" />
							<label>
								<input type="checkbox" name="srd_km_settings[db_use_wp]" value="1" <?php checked(!empty($s['db_use_wp'])); ?> />
								<?php esc_html_e('WordPress-Datenbank verwenden (DB_HOST / DB_NAME aus wp-config.php)', 'srd-kreismeisterschaften'); ?>
							</label>
							<p class="description"><?php esc_html_e('Deaktivieren, falls die SRD-Tabellen auf einem anderen Server liegen.', 'srd-kreismeisterschaften'); ?></p>
							<p>
								<label><?php esc_html_e('Host', 'srd-kreismeisterschaften'); ?><br />
									<input type="text" class="regular-text" name="srd_km_settings[db_host]" value="<?php echo esc_attr((string) $s['db_host']); ?>" /></label>
							</p>
							<p>
								<label><?php esc_html_e('Benutzer', 'srd-kreismeisterschaften'); ?><br />
									<input type="text" class="regular-text" name="srd_km_settings[db_user]" value="<?php echo esc_attr((string) $s['db_user']); ?>" autocomplete="off" /></label>
							</p>
							<p>
								<label><?php esc_html_e('Passwort', 'srd-kreismeisterschaften'); ?><br />
									<input type="password" class="regular-text" name="srd_km_settings[db_pass]" value="" autocomplete="new-password" placeholder="<?php esc_attr_e('leer lassen = unverändert', 'srd-kreismeisterschaften'); ?>" /></label>
							</p>
							<p>
								<label><?php esc_html_e('Datenbankname', 'srd-kreismeisterschaften'); ?><br />
									<input type="text" class="regular-text" name="srd_km_settings[db_name]" value="<?php echo esc_attr((string) $s['db_name']); ?>" /></label>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="srd_km_results_path"><?php esc_html_e('Pfad zum Ordner „results“', 'srd-kreismeisterschaften'); ?></label></th>
						<td>
							<input type="text" class="large-text code" name="srd_km_settings[results_path]" id="srd_km_results_path"
								value="<?php echo esc_attr((string) $s['results_path']); ?>"
								placeholder="<?php echo esc_attr(trailingslashit(WP_CONTENT_DIR) . 'uploads/srd-results'); ?>" />
							<p class="description"><?php esc_html_e('Absoluter Serverpfad, in dem u. a. km_2025/, km-licht/ liegen (Inhalt des bisherigen results-Verzeichnisses).', 'srd-kreismeisterschaften'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="srd_km_results_url"><?php esc_html_e('URL zum Ordner „results“', 'srd-kreismeisterschaften'); ?></label></th>
						<td>
							<input type="url" class="large-text code" name="srd_km_settings[results_url]" id="srd_km_results_url"
								value="<?php echo esc_attr((string) $s['results_url']); ?>"
								placeholder="<?php echo esc_attr(content_url('/uploads/srd-results')); ?>" />
							<p class="description"><?php esc_html_e('Öffentliche Basis-URL (ohne abschließenden Slash wird einer ergänzt). Für PDF- und Lichtschießen-Links.', 'srd-kreismeisterschaften'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="srd_km_home_url"><?php esc_html_e('Link „Ergebnishistorie“ (optional)', 'srd-kreismeisterschaften'); ?></label></th>
						<td>
							<input type="url" class="large-text" name="srd_km_settings[home_url_custom]" id="srd_km_home_url"
								value="<?php echo esc_attr((string) $s['home_url_custom']); ?>" />
							<p class="description"><?php esc_html_e('Leer = Startseite der Website (home_url()).', 'srd-kreismeisterschaften'); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr class="wp-header-end" />
			<h2><?php esc_html_e('Ergebnisse hochladen', 'srd-kreismeisterschaften'); ?></h2>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: maximale Upload-Größe */
						__('Dateien landen im konfigurierten results-Ordner. Erlaubt: PDF und HTML. ZIP: nur diese Endungen, Pfade relativ zum Ordner (z. B. km_2025/…). Serverlimit pro Anfrage: %s.', 'srd-kreismeisterschaften'),
						size_format(wp_max_upload_size())
					)
				);
				?>
			</p>
			<?php
			$cy = (int) wp_date('Y');
			$cm = (int) wp_date('n');
			$default_upload_year = ($cm >= 10) ? $cy + 1 : $cy;
			?>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="srd-km-upload-form">
				<?php wp_nonce_field('srd_km_upload_results', 'srd_km_upload_nonce'); ?>
				<input type="hidden" name="action" value="srd_km_upload_results" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e('Upload-Typ', 'srd-kreismeisterschaften'); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="srd_km_upload_kind" value="file" checked="checked" /> <?php esc_html_e('Einzeldatei → Unterordner km_JJJJ/', 'srd-kreismeisterschaften'); ?></label><br />
								<label><input type="radio" name="srd_km_upload_kind" value="zip" /> <?php esc_html_e('ZIP-Archiv (Struktur wie im results-Ordner)', 'srd-kreismeisterschaften'); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="srd_km_upload_year"><?php esc_html_e('Sportjahr (nur Einzeldatei)', 'srd-kreismeisterschaften'); ?></label></th>
						<td>
							<select name="srd_km_upload_year" id="srd_km_upload_year">
								<?php
								$max_select = $cy + 3;
								for ($opt = $max_select; $opt >= 1990; $opt--) :
									?>
									<option value="<?php echo esc_attr((string) $opt); ?>" <?php selected($opt, $default_upload_year); ?>><?php echo esc_html((string) $opt); ?></option>
								<?php endfor; ?>
							</select>
							<p class="description"><?php esc_html_e('Bei ZIP wird dieses Feld ignoriert.', 'srd-kreismeisterschaften'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="srd_km_upload_file"><?php esc_html_e('Datei', 'srd-kreismeisterschaften'); ?></label></th>
						<td>
							<input type="file" name="srd_km_upload_file" id="srd_km_upload_file" required="required" accept=".pdf,.html,.htm,.zip,application/pdf,text/html,application/zip" />
						</td>
					</tr>
				</table>
				<?php submit_button(__('Datei hochladen', 'srd-kreismeisterschaften'), 'secondary', 'submit', false); ?>
			</form>
		</div>
		<?php
	}

	private function render_upload_admin_notices(): void {
		if (!isset($_GET['srd_km_upload'])) {
			return;
		}
		$status = sanitize_key((string) wp_unslash($_GET['srd_km_upload']));
		$code = isset($_GET['srd_km_upload_c']) ? sanitize_text_field((string) wp_unslash($_GET['srd_km_upload_c'])) : '';
		if ($status === 'ok') {
			$msg = __('Upload abgeschlossen.', 'srd-kreismeisterschaften');
			if (strpos($code, 'zip_ok:') === 0) {
				$n = absint(substr($code, strlen('zip_ok:')));
				$msg = sprintf(
					/* translators: %d: Anzahl entpackter Dateien */
					_n('ZIP: eine Datei wurde übernommen.', 'ZIP: %d Dateien wurden übernommen.', $n, 'srd-kreismeisterschaften'),
					$n
				);
			} elseif ($code === 'file_ok') {
				$msg = __('Die Datei wurde gespeichert.', 'srd-kreismeisterschaften');
			}
			printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($msg));
			return;
		}
		if ($status !== 'err') {
			return;
		}
		$map = array(
			'bad_kind'       => __('Ungültige Anfrage.', 'srd-kreismeisterschaften'),
			'bad_year'       => __('Ungültiges Sportjahr.', 'srd-kreismeisterschaften'),
			'bad_ext'        => __('Nur PDF- oder HTML-Dateien sind erlaubt.', 'srd-kreismeisterschaften'),
			'bad_name'       => __('Ungültiger Dateiname.', 'srd-kreismeisterschaften'),
			'mkdir'          => __('Zielordner konnte nicht angelegt werden.', 'srd-kreismeisterschaften'),
			'move'           => __('Die Datei konnte nicht gespeichert werden.', 'srd-kreismeisterschaften'),
			'path'           => __('Zielpfad liegt außerhalb des results-Ordners.', 'srd-kreismeisterschaften'),
			'no_ziparchive'  => __('PHP ZipArchive ist nicht verfügbar.', 'srd-kreismeisterschaften'),
			'zip_open'       => __('ZIP-Archiv konnte nicht geöffnet werden.', 'srd-kreismeisterschaften'),
			'zip_empty'      => __('Im ZIP waren keine gültigen PDF-/HTML-Dateien enthalten.', 'srd-kreismeisterschaften'),
			'no_dir'         => __('Der results-Ordner existiert nicht oder ist nicht beschreibbar.', 'srd-kreismeisterschaften'),
			'no_file'        => __('Keine Datei empfangen.', 'srd-kreismeisterschaften'),
		);
		$text = $map[ $code ] ?? __('Upload fehlgeschlagen.', 'srd-kreismeisterschaften');
		printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html($text));
	}
}
