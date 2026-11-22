<?php
/**
 * Backend: Ergebnisse und Ausschreibungen (übernommen aus dem bisherigen Ergebnis-Plugin).
 *
 * Reiter:
 *   - Hochladen: Plätze aus dem Disziplinplan des Jahres, je Platz hochladen, oder viele
 *     Dateien (auch ZIP) auf einmal ablegen und nach Dateinamen zuordnen lassen.
 *   - Archiv & Klärung: alte Jahre einlesen (Probelauf zuerst), offene Dateien benennen.
 *   - Ausschreibungen: Unterlagen je Jahr.
 *   - Einstellungen (nur Admin): Ablageort, Seite, Adresse, Schalter „Anzeige übernehmen“.
 *   - Testjahr (nur Admin, nur solange es eins gibt).
 *
 * Hochladen und Verwalten darf, wer das Recht „Ergebnisse hochladen“ hat (Admin immer).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Admin;

use KSV\KMM\Application\Ergebnisse\Dokumente;
use KSV\KMM\Application\Ergebnisse\ErgebnisArchiv;
use KSV\KMM\Application\Ergebnisse\ErgebnisService;
use KSV\KMM\Application\Ergebnisse\Testjahr;
use KSV\KMM\Auth\Rechte;
use KSV\KMM\Domain\Ergebnis\Kategorie;
use KSV\KMM\Domain\Ergebnis\Platz;
use KSV\KMM\Domain\Ergebnis\UploadZuordnung;
use KSV\KMM\Infrastructure\Repository\ErgebnisRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Support\ErgebnisEinstellungen;

final class ErgebnissePage extends AdminPage {

	public const SLUG = 'kmm-ergebnisse';

	private const BERICHT_KEY = 'kmm_erg_bericht_';

	/** Aktionen, die nur Administratoren ausführen. */
	private const NUR_ADMIN = ['archiv_probelauf', 'archiv_einlesen', 'einstellungen', 'testjahr_entfernen'];

	protected static function verify(): void {
		$admin = in_array(self::action(), self::NUR_ADMIN, true);
		if ($admin ? !Rechte::ist_admin() : !ErgebnisService::darf_hochladen()) {
			wp_die(esc_html__('Keine Berechtigung.', 'ksv-km-meldeportal'));
		}
		check_admin_referer('kmm_' . self::SLUG);
	}

	public static function handle_post(): void {
		if (!self::is_own_post()) {
			return;
		}
		self::verify();
		$jahr = self::post_int('jahr');
		$tab = self::post_str('tab', 20);
		$zurueck = array_filter(['jahr' => $jahr, 'tab' => $tab]);
		$service = new ErgebnisService();
		try {
			switch (self::action()) {
				case 'platz':
					$datei = self::datei('datei');
					$platz = self::post_str('platz', 40);
					$r = $service->hochladen($jahr, $platz, $datei, self::post_bool('entwurf'));
					self::redirect(sprintf(__('Gespeichert: %s', 'ksv-km-meldeportal'), (string) $r['datei']), 'success', $zurueck, 'p-' . str_replace(':', '-', $platz));
				case 'eingang':
					if (!isset($_FILES['dateien']) || !is_array($_FILES['dateien'])) {
						throw new \InvalidArgumentException(__('Keine Datei ausgewählt.', 'ksv-km-meldeportal'));
					}
					$r = $service->eingang($jahr, $_FILES['dateien']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					$hinweis = $r['abgelehnt'] !== [] ? __('Nicht übernommen:', 'ksv-km-meldeportal') . "\n" . implode("\n", $r['abgelehnt']) : __('Bitte die Zuordnung prüfen und übernehmen.', 'ksv-km-meldeportal');
					self::redirect($hinweis, $r['abgelehnt'] !== [] ? 'warning' : 'info', $zurueck + ['vorschau' => $r['token']], 'vorschau');
				case 'uebernehmen':
					$zuordnung = isset($_POST['zuordnung']) && is_array($_POST['zuordnung']) ? array_map(static fn($v): string => sanitize_text_field((string) $v), wp_unslash($_POST['zuordnung'])) : [];
					$r = $service->uebernehmen(self::post_str('token', 40), $zuordnung, self::post_bool('entwurf'));
					$text = sprintf(__('%1$d gespeichert (davon %2$d ersetzt), %3$d ausgelassen.', 'ksv-km-meldeportal'), $r['gespeichert'], $r['ersetzt'], $r['ausgelassen']);
					self::redirect($text . ($r['fehler'] !== [] ? "\n" . implode("\n", $r['fehler']) : ''), $r['fehler'] !== [] ? 'warning' : 'success', $zurueck);
				case 'verwerfen':
					$service->eingang_verwerfen(self::post_str('token', 40));
					self::redirect(__('Verworfen.', 'ksv-km-meldeportal'), 'info', $zurueck);
				case 'status':
					$service->status_setzen(self::post_int('id'), self::post_str('status', 20));
					self::redirect(__('Status geändert.', 'ksv-km-meldeportal'), 'success', $zurueck, self::post_str('anker', 60));
				case 'entfernen':
					$service->entfernen(self::post_int('id'));
					self::redirect(__('Entfernt. Die Datei liegt in der Sicherung.', 'ksv-km-meldeportal'), 'success', $zurueck, self::post_str('anker', 60));
				case 'klaeren':
					$service->klaeren(self::post_int('id'), self::post_str('kennzahl', 20), self::post_str('disziplin_text', 150), self::post_str('klasse_text', 150));
					self::redirect(__('Bezeichnung gespeichert; das Ergebnis ist sichtbar.', 'ksv-km-meldeportal'), 'success', ['tab' => 'archiv'], 'klaerung');
				case 'archiv_probelauf':
				case 'archiv_einlesen':
					$probe = self::action() === 'archiv_probelauf';
					if (!$probe && self::post_str('bestaetigung', 20) !== 'EINLESEN') {
						throw new \InvalidArgumentException(__('Zur Bestätigung bitte EINLESEN eintippen.', 'ksv-km-meldeportal'));
					}
					$bericht = (new ErgebnisArchiv())->einlesen($probe);
					set_transient(self::BERICHT_KEY . get_current_user_id(), $bericht, HOUR_IN_SECONDS);
					self::redirect($probe ? __('Probelauf fertig – es wurde nichts verändert.', 'ksv-km-meldeportal') : __('Archiv eingelesen.', 'ksv-km-meldeportal'), 'success', ['tab' => 'archiv'], 'bericht');
				case 'dokument':
					$dok = new Dokumente();
					$quelle = self::post_str('quelle', 10);
					$typ = self::post_str('typ', 20);
					$kategorie = self::post_int_or_null('kategorie');
					$titel = self::post_str('titel', 190);
					if ($quelle === Dokumente::QUELLE_PDF) {
						$dok->hochladen($jahr, $typ, $kategorie, $titel, self::datei('pdf'));
					} else {
						$dok->anlegen($jahr, $typ, $kategorie, $titel, $quelle, $quelle === Dokumente::QUELLE_SEITE ? (string) self::post_int('page_id') : self::post_str('url', 500));
					}
					self::redirect(__('Dokument hinzugefügt.', 'ksv-km-meldeportal'), 'success', $zurueck);
				case 'dokument_loeschen':
					(new Dokumente())->loeschen(self::post_int('id'));
					self::redirect(__('Dokument entfernt (die Datei bleibt in der Mediathek).', 'ksv-km-meldeportal'), 'success', $zurueck);
				case 'dokument_schieben':
					(new Dokumente())->verschieben(self::post_int('id'), self::post_int('richtung'));
					self::redirect(__('Reihenfolge geändert.', 'ksv-km-meldeportal'), 'success', $zurueck);
				case 'einstellungen':
					ErgebnisEinstellungen::speichern([
						'pfad'                => self::post_str('pfad', 500),
						'url'                 => esc_url_raw(self::post_str('url', 500)),
						'seite_id'            => self::post_int('seite_id'),
						'slug'                => self::post_str('slug', 60),
						'anzeige_uebernehmen' => self::post_bool('anzeige_uebernehmen'),
					]);
					update_option('kmm_ergebnisse_rewrite_flush', 1, false);
					self::redirect(__('Einstellungen gespeichert.', 'ksv-km-meldeportal'), 'success', ['tab' => 'einstellungen']);
				case 'testjahr_entfernen':
					$r = (new Testjahr())->entfernen(self::post_int('sportjahr_id'), self::post_str('bestaetigung', 10));
					self::redirect(sprintf(__('Testjahr entfernt. Sicherung: %s', 'ksv-km-meldeportal'), $r['sicherung']), 'success', ['tab' => 'archiv']);
			}
		} catch (\RuntimeException | \InvalidArgumentException $e) {
			self::redirect($e->getMessage(), 'error', $zurueck);
		}
	}

	/**
	 * @return array{name: string, tmp_name: string, error: int}
	 */
	private static function datei(string $feld): array {
		$f = $_FILES[ $feld ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if (!is_array($f) || is_array($f['name'] ?? null)) {
			throw new \InvalidArgumentException(__('Keine Datei ausgewählt.', 'ksv-km-meldeportal'));
		}
		return ['name' => (string) $f['name'], 'tmp_name' => (string) $f['tmp_name'], 'error' => (int) $f['error']];
	}

	// ----- Ausgabe ----------------------------------------------------------------------------

	/**
	 * Wählbare Jahre: Ordner im Ergebnisordner und Sportjahre ab 2027, neuestes zuerst.
	 *
	 * @return list<int>
	 */
	private static function jahre(ErgebnisService $service): array {
		$jahre = $service->ablage()->jahre();
		foreach ((new SportjahrRepository())->all() as $sj) {
			if ((int) $sj['jahr'] >= ErgebnisService::ERSTES_PLANJAHR) {
				$jahre[] = (int) $sj['jahr'];
			}
		}
		$jahre = array_values(array_unique($jahre));
		rsort($jahre);
		return $jahre;
	}

	/**
	 * @param list<int> $jahre
	 */
	private static function standardjahr(array $jahre): int {
		$aktiv = (new SportjahrRepository())->aktiv();
		if ($aktiv !== null && in_array((int) $aktiv['jahr'], $jahre, true)) {
			return (int) $aktiv['jahr'];
		}
		return $jahre[0] ?? (int) gmdate('Y');
	}

	public static function render(): void {
		if (!Rechte::darf_lesen()) {
			wp_die(esc_html__('Keine Berechtigung.', 'ksv-km-meldeportal'));
		}
		$service = new ErgebnisService();
		$jahre = self::jahre($service);
		$jahr = isset($_GET['jahr']) ? (int) $_GET['jahr'] : self::standardjahr($jahre); // phpcs:ignore WordPress.Security.NonceVerification
		if (!in_array($jahr, $jahre, true) && $jahr >= 1990 && $jahr <= 2100) {
			$jahre[] = $jahr;
			rsort($jahre);
		}
		$tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'hochladen'; // phpcs:ignore WordPress.Security.NonceVerification
		$tabs = ['hochladen' => __('Hochladen', 'ksv-km-meldeportal'), 'archiv' => __('Archiv & Klärung', 'ksv-km-meldeportal'), 'dokumente' => __('Ausschreibungen', 'ksv-km-meldeportal')];
		if (Rechte::ist_admin()) {
			$tabs['einstellungen'] = __('Einstellungen', 'ksv-km-meldeportal');
		}
		$tab = isset($tabs[ $tab ]) ? $tab : 'hochladen';

		echo '<div class="wrap kmm-admin kmm-erg">';
		Menu::page_header(__('Ergebnisse', 'ksv-km-meldeportal'));
		self::show_notices();
		self::hinweis_umstellung();
		echo '<nav class="nav-tab-wrapper">';
		foreach ($tabs as $k => $label) {
			echo '<a class="nav-tab' . ($k === $tab ? ' nav-tab-active' : '') . '" href="' . esc_url(self::url(['tab' => $k, 'jahr' => $jahr])) . '">' . esc_html($label) . '</a>';
		}
		echo '</nav>';
		match ($tab) {
			'archiv'        => self::tab_archiv($service),
			'dokumente'     => self::tab_dokumente($jahr, $jahre),
			'einstellungen' => self::tab_einstellungen(),
			default         => self::tab_hochladen($service, $jahr, $jahre),
		};
		echo '</div>';
	}

	private static function hinweis_umstellung(): void {
		if (ErgebnisEinstellungen::uebernommen()) {
			return;
		}
		echo '<div class="notice notice-info inline"><p>';
		if (ErgebnisEinstellungen::srd_aktiv()) {
			esc_html_e('Die öffentliche Anzeige läuft noch über das bisherige Ergebnis-Plugin. Hochgeladene Dateien liegen im selben Ordner und sind dort sofort sichtbar, sobald sie im alten Schema benannt sind. Umschalten unter „Einstellungen“.', 'ksv-km-meldeportal');
		} else {
			esc_html_e('Die Anzeige über [srd_km] und die alten Adressen ist noch aus. Der Shortcode [kmm_ergebnisse] funktioniert bereits. Umschalten unter „Einstellungen“.', 'ksv-km-meldeportal');
		}
		echo '</p></div>';
	}

	/**
	 * @param list<int> $jahre
	 */
	private static function jahr_auswahl(int $jahr, array $jahre, string $tab): void {
		echo '<form method="get" class="kmm-erg-jahr"><input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '">';
		echo '<label>' . esc_html__('Jahr', 'ksv-km-meldeportal') . ' ';
		echo self::select('jahr', array_combine($jahre, array_map('strval', $jahre)) ?: [], $jahr, 'onchange="this.form.submit()"'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</label> <noscript><button class="button">' . esc_html__('Anzeigen', 'ksv-km-meldeportal') . '</button></noscript></form>';
	}

	// ----- Reiter Hochladen -------------------------------------------------------------------

	/**
	 * @param list<int> $jahre
	 */
	private static function tab_hochladen(ErgebnisService $service, int $jahr, array $jahre): void {
		self::jahr_auswahl($jahr, $jahre, 'hochladen');
		$darf = ErgebnisService::darf_hochladen();
		$plaetze = $service->plaetze($jahr);
		$vorhanden = $service->vorhandene($jahr);
		$planjahr = ErgebnisService::sportjahr_fuer($jahr) !== null;

		if ($plaetze->alle() === []) {
			echo '<p>' . esc_html($jahr >= ErgebnisService::ERSTES_PLANJAHR
				? __('Für dieses Jahr gibt es noch kein Sportjahr mit Disziplinplan.', 'ksv-km-meldeportal')
				: __('Für dieses Archivjahr gibt es noch keine Plätze. Bitte zuerst unter „Archiv & Klärung“ das Archiv einlesen.', 'ksv-km-meldeportal')) . '</p>';
			return;
		}
		foreach ($plaetze->warnungen() as $w) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html($w) . '</p></div>';
		}

		$token = isset($_GET['vorschau']) ? sanitize_text_field((string) $_GET['vorschau']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ($darf && $token !== '') {
			self::vorschau($service, $token);
		} elseif ($darf) {
			self::ablageflaeche($jahr);
		}

		$gesamt = $plaetze->anzahl();
		$belegt = 0;
		foreach ($plaetze->alle() as $p) {
			if (!$p->ist_sammel() && isset($vorhanden[ $p->id() ])) {
				$belegt++;
			}
		}
		echo '<h2>' . esc_html(sprintf(__('Plätze %d', 'ksv-km-meldeportal'), $jahr)) . '</h2>';
		echo '<p class="kmm-erg-stand"><progress max="' . (int) $gesamt . '" value="' . (int) $belegt . '"></progress> ';
		echo esc_html(sprintf(__('%1$d von %2$d Ergebnissen vorhanden', 'ksv-km-meldeportal'), $belegt, $gesamt));
		echo $planjahr ? '' : ' · <span class="description">' . esc_html__('Archivjahr: Plätze aus dem alten Katalog', 'ksv-km-meldeportal') . '</span>';
		echo '</p>';
		echo '<p class="kmm-erg-filter"><input type="search" class="kmm-erg-suche" placeholder="' . esc_attr__('Suchen, z. B. 1.10 oder Compound', 'ksv-km-meldeportal') . '"> ';
		echo '<label><input type="checkbox" class="kmm-erg-nur-offen"> ' . esc_html__('nur fehlende', 'ksv-km-meldeportal') . '</label></p>';

		$sparte = null;
		foreach ($plaetze->nach_disziplin() as $gruppe) {
			$erster = $gruppe[0];
			if ($sparte !== $erster->kategorie()) {
				$sparte = $erster->kategorie();
				echo '<h3 class="kmm-erg-sparte">' . esc_html(Kategorie::label($sparte)) . '</h3>';
			}
			$offen = 0;
			$anzahl = 0;
			foreach ($gruppe as $p) {
				if (!$p->ist_sammel()) {
					$anzahl++;
					$offen += isset($vorhanden[ $p->id() ]) ? 0 : 1;
				}
			}
			$suche = mb_strtolower($erster->kennzahl . ' ' . $erster->disziplin_text);
			echo '<details class="kmm-erg-disziplin" data-suche="' . esc_attr($suche) . '"' . ($offen > 0 && $offen < $anzahl ? ' open' : '') . '>';
			echo '<summary><span class="kmm-erg-kz">' . esc_html($erster->kennzahl) . '</span> ' . esc_html($erster->disziplin_text);
			echo ' <span class="kmm-erg-zahl' . ($offen === 0 ? ' is-voll' : '') . '">' . esc_html(sprintf(__('%1$d/%2$d', 'ksv-km-meldeportal'), $anzahl - $offen, $anzahl)) . '</span></summary>';
			echo '<table class="widefat striped kmm-erg-plaetze"><tbody>';
			foreach ($gruppe as $p) {
				self::platz_zeile($jahr, $p, $vorhanden[ $p->id() ] ?? null, $darf, $service);
			}
			echo '</tbody></table></details>';
		}
		self::script();
	}

	private static function ablageflaeche(int $jahr): void {
		echo '<div class="kmm-erg-ablage" id="ablage">';
		echo '<h2>' . esc_html__('Viele Dateien auf einmal', 'ksv-km-meldeportal') . '</h2>';
		echo '<p class="description">' . esc_html__('PDFs mit Namen wie e1_10_10.pdf, E1-10-10.pdf oder m1_10_10.pdf werden ihrem Platz zugeordnet; Sammel-PDFs ohne Klasse heißen z. B. e6_20.pdf. Auch eine ZIP-Datei geht. Vor dem Speichern kommt eine Vorschau.', 'ksv-km-meldeportal') . '</p>';
		self::form_open('eingang', 'enctype="multipart/form-data" class="kmm-erg-drop"');
		echo '<input type="hidden" name="jahr" value="' . (int) $jahr . '"><input type="hidden" name="tab" value="hochladen">';
		echo '<label class="kmm-erg-dropzone"><input type="file" name="dateien[]" multiple accept=".pdf,.zip' . (in_array('html', ErgebnisService::erlaubte_endungen($jahr), true) ? ',.html,.htm' : '') . '" required> <span>' . esc_html__('Dateien hierher ziehen oder auswählen', 'ksv-km-meldeportal') . '</span></label> ';
		submit_button(__('Weiter zur Vorschau', 'ksv-km-meldeportal'), 'primary', '', false);
		echo '</form></div>';
	}

	private static function vorschau(ErgebnisService $service, string $token): void {
		$v = $service->vorschau($token);
		echo '<div class="kmm-erg-ablage" id="vorschau">';
		if ($v === null) {
			echo '<p>' . esc_html__('Die Vorschau ist abgelaufen. Bitte die Dateien noch einmal hochladen.', 'ksv-km-meldeportal') . '</p></div>';
			return;
		}
		$jahr = $v['jahr'];
		echo '<h2>' . esc_html(sprintf(__('Vorschau: %d Dateien für %d', 'ksv-km-meldeportal'), count($v['zeilen']), $jahr)) . '</h2>';
		echo '<p class="description">' . esc_html__('Grün: Platz erkannt und noch frei. Gelb: ersetzt ein vorhandenes Ergebnis (das alte wird gesichert). Rot: nicht erkannt – bitte Platz wählen oder auslassen.', 'ksv-km-meldeportal') . '</p>';
		$optionen = ['' => __('— auslassen —', 'ksv-km-meldeportal')];
		foreach ($service->plaetze($jahr)->alle() as $p) {
			$optionen[ $p->id() ] = $p->beschriftung();
		}
		self::form_open('uebernehmen');
		echo '<input type="hidden" name="jahr" value="' . (int) $jahr . '"><input type="hidden" name="tab" value="hochladen"><input type="hidden" name="token" value="' . esc_attr($token) . '">';
		echo '<table class="widefat kmm-erg-vorschau"><thead><tr><th>' . esc_html__('Datei', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Platz', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Hinweis', 'ksv-km-meldeportal') . '</th></tr></thead><tbody>';
		foreach ($v['zeilen'] as $i => $z) {
			$gewaehlt = $z['platz'] !== null && $z['status'] !== UploadZuordnung::ROT ? $z['platz']->id() : '';
			echo '<tr class="kmm-ampel-' . esc_attr($z['status']) . '"><td><span class="kmm-ampel" aria-hidden="true"></span> ' . esc_html($z['datei']) . '</td>';
			echo '<td>' . self::select('zuordnung[' . (int) $i . ']', $optionen, $gewaehlt) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . esc_html($z['hinweis']) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p>' . self::checkbox('entwurf', false, __('als Entwurf speichern (noch nicht öffentlich)', 'ksv-km-meldeportal')) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		submit_button(__('Übernehmen', 'ksv-km-meldeportal'), 'primary', '', false);
		echo '</form>';
		self::form_open('verwerfen', 'class="kmm-inline"');
		echo '<input type="hidden" name="jahr" value="' . (int) $jahr . '"><input type="hidden" name="tab" value="hochladen"><input type="hidden" name="token" value="' . esc_attr($token) . '">';
		submit_button(__('Verwerfen', 'ksv-km-meldeportal'), 'secondary', '', false);
		echo '</form></div>';
	}

	/**
	 * @param array<string, mixed>|null $r
	 */
	private static function platz_zeile(int $jahr, Platz $p, ?array $r, bool $darf, ErgebnisService $service): void {
		$anker = 'p-' . $p->art . '-' . $p->schluessel;
		$status = $r !== null ? (string) $r['status'] : '';
		echo '<tr id="' . esc_attr($anker) . '" class="kmm-erg-platz' . ($r === null ? ' is-offen' : '') . ($p->ist_sammel() ? ' is-sammel' : '') . '">';
		echo '<td class="kmm-erg-klasse">' . esc_html($p->klasse_text) . '</td>';
		echo '<td>' . esc_html($p->art_text()) . '</td>';
		echo '<td class="kmm-erg-datei">';
		if ($r !== null) {
			$url = $service->ablage()->url((string) $r['datei']);
			echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html(basename((string) $r['datei'])) . '</a>';
			if ($status !== ErgebnisRepository::STATUS_SICHTBAR) {
				echo ' <span class="kmm-erg-status is-' . esc_attr($status) . '">' . esc_html(self::status_text($status)) . '</span>';
			}
		} else {
			echo '<span class="description">' . esc_html(sprintf(__('fehlt (%s)', 'ksv-km-meldeportal'), $p->dateiname())) . '</span>';
		}
		echo '</td><td class="kmm-erg-aktion">';
		if ($darf) {
			self::form_open('platz', 'enctype="multipart/form-data" class="kmm-inline"');
			echo '<input type="hidden" name="jahr" value="' . (int) $jahr . '"><input type="hidden" name="tab" value="hochladen"><input type="hidden" name="platz" value="' . esc_attr($p->id()) . '">';
			echo '<input type="file" name="datei" accept=".pdf' . (in_array('html', ErgebnisService::erlaubte_endungen($jahr), true) ? ',.html,.htm' : '') . '" required aria-label="' . esc_attr($p->beschriftung()) . '"> ';
			echo '<button class="button button-small">' . esc_html($r === null ? __('Hochladen', 'ksv-km-meldeportal') : __('Ersetzen', 'ksv-km-meldeportal')) . '</button>';
			echo '</form>';
			if ($r !== null) {
				self::zeilen_aktionen($jahr, $r, $anker, 'hochladen');
			}
		}
		echo '</td></tr>';
	}

	/**
	 * @param array<string, mixed> $r
	 */
	private static function zeilen_aktionen(int $jahr, array $r, string $anker, string $tab): void {
		$status = (string) $r['status'];
		$ziel = $status === ErgebnisRepository::STATUS_SICHTBAR ? ErgebnisRepository::STATUS_AUSGEBLENDET : ErgebnisRepository::STATUS_SICHTBAR;
		if ($status !== ErgebnisRepository::STATUS_UNGEKLAERT) {
			self::form_open('status', 'class="kmm-inline"');
			echo '<input type="hidden" name="jahr" value="' . (int) $jahr . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '"><input type="hidden" name="anker" value="' . esc_attr($anker) . '"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><input type="hidden" name="status" value="' . esc_attr($ziel) . '">';
			echo '<button class="button-link">' . esc_html($ziel === ErgebnisRepository::STATUS_SICHTBAR ? __('veröffentlichen', 'ksv-km-meldeportal') : __('ausblenden', 'ksv-km-meldeportal')) . '</button></form> · ';
		}
		self::form_open('entfernen', 'class="kmm-inline" data-kmm-gefahr="1" data-kmm-confirm="' . esc_attr__('Ergebnis entfernen? Die Datei wird gesichert, nicht gelöscht.', 'ksv-km-meldeportal') . '"');
		echo '<input type="hidden" name="jahr" value="' . (int) $jahr . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '"><input type="hidden" name="anker" value="' . esc_attr($anker) . '"><input type="hidden" name="id" value="' . (int) $r['id'] . '">';
		echo '<button class="button-link kmm-link-danger">' . esc_html__('entfernen', 'ksv-km-meldeportal') . '</button></form>';
	}

	private static function status_text(string $status): string {
		return match ($status) {
			ErgebnisRepository::STATUS_ENTWURF      => __('Entwurf', 'ksv-km-meldeportal'),
			ErgebnisRepository::STATUS_AUSGEBLENDET => __('ausgeblendet', 'ksv-km-meldeportal'),
			ErgebnisRepository::STATUS_UNGEKLAERT   => __('ungeklärt', 'ksv-km-meldeportal'),
			default                                 => __('sichtbar', 'ksv-km-meldeportal'),
		};
	}

	// ----- Reiter Archiv & Klärung ------------------------------------------------------------

	private static function tab_archiv(ErgebnisService $service): void {
		$repo = new ErgebnisRepository();
		$zaehlung = $repo->zaehlung();
		echo '<h2>' . esc_html__('Bestand', 'ksv-km-meldeportal') . '</h2>';
		echo '<p class="description">' . esc_html(sprintf(__('Ergebnisordner: %s', 'ksv-km-meldeportal'), $service->ablage()->basis())) . ($service->ablage()->vorhanden() ? '' : ' – <strong>' . esc_html__('nicht gefunden', 'ksv-km-meldeportal') . '</strong>') . '</p>';
		if ($zaehlung === []) {
			echo '<p>' . esc_html__('Noch keine Ergebnisse eingetragen.', 'ksv-km-meldeportal') . '</p>';
		} else {
			echo '<table class="widefat striped kmm-erg-bestand"><thead><tr><th>' . esc_html__('Jahr', 'ksv-km-meldeportal') . '</th>';
			foreach (ErgebnisRepository::STATUS as $s) {
				echo '<th>' . esc_html(self::status_text($s)) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ($zaehlung as $jahr => $je) {
				echo '<tr><td><a href="' . esc_url(self::url(['tab' => 'hochladen', 'jahr' => $jahr])) . '">' . (int) $jahr . '</a></td>';
				foreach (ErgebnisRepository::STATUS as $s) {
					echo '<td>' . (int) ($je[ $s ] ?? 0) . '</td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		if (Rechte::ist_admin()) {
			self::archiv_einlesen();
		}
		self::klaerung($repo);
		if (Rechte::ist_admin()) {
			self::testjahr();
		}
	}

	private static function archiv_einlesen(): void {
		echo '<h2 id="bericht">' . esc_html__('Archiv einlesen', 'ksv-km-meldeportal') . '</h2>';
		echo '<p class="description">' . esc_html__('Liest die Jahresordner bis 2026, den Katalog des bisherigen Plugins, Lichtschießen-Dateien und die Ausschreibungen ein. Dateien werden nicht verschoben. Mehrfaches Einlesen ist unschädlich; hochgeladene Ergebnisse bleiben unberührt. Vorher bitte eine Sicherung (Duplicator) anlegen.', 'ksv-km-meldeportal') . '</p>';
		if (!ErgebnisArchiv::srd_tabelle_vorhanden()) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__('Die Tabelle des bisherigen Plugins fehlt; nur Dateien mit Katalogeintrag werden erkannt.', 'ksv-km-meldeportal') . '</p></div>';
		}
		$bericht = get_transient(self::BERICHT_KEY . get_current_user_id());
		if (is_array($bericht)) {
			self::bericht($bericht);
		}
		self::form_open('archiv_probelauf', 'class="kmm-inline"');
		echo '<input type="hidden" name="tab" value="archiv">';
		submit_button(__('Probelauf (ändert nichts)', 'ksv-km-meldeportal'), 'secondary', '', false);
		echo '</form> ';
		self::form_open('archiv_einlesen', 'class="kmm-inline"');
		echo '<input type="hidden" name="tab" value="archiv">';
		echo '<label>' . esc_html__('Zur Bestätigung EINLESEN eintippen:', 'ksv-km-meldeportal') . ' <input type="text" name="bestaetigung" size="10" autocomplete="off" required></label> ';
		submit_button(__('Jetzt einlesen', 'ksv-km-meldeportal'), 'primary', '', false);
		echo '</form>';
	}

	/**
	 * @param array<string, mixed> $b
	 */
	private static function bericht(array $b): void {
		echo '<div class="kmm-erg-bericht"><h3>' . esc_html(!empty($b['probelauf']) ? __('Ergebnis des Probelaufs', 'ksv-km-meldeportal') : __('Ergebnis des Einlesens', 'ksv-km-meldeportal')) . '</h3>';
		$k = (array) ($b['katalog'] ?? []);
		echo '<p>' . esc_html(sprintf(__('Katalog: %1$d Zeilen gelesen, %2$d neu. Lichtschießen: %3$d Dateien neu. Ausschreibungen: %4$d neu.', 'ksv-km-meldeportal'), (int) ($k['gelesen'] ?? 0), (int) ($k['neu'] ?? 0), (int) ($b['licht'] ?? 0), (int) ($b['dokumente'] ?? 0))) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ([__('Jahr', 'ksv-km-meldeportal'), __('Einzel', 'ksv-km-meldeportal'), __('Mannschaft', 'ksv-km-meldeportal'), __('neu', 'ksv-km-meldeportal'), __('Gruppen', 'ksv-km-meldeportal'), __('ungeklärt', 'ksv-km-meldeportal'), __('sonstige Dateien', 'ksv-km-meldeportal'), __('doppelt (HTML/PDF)', 'ksv-km-meldeportal')] as $h) {
			echo '<th>' . esc_html($h) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ((array) ($b['jahre'] ?? []) as $jahr => $j) {
			echo '<tr><td>' . (int) $jahr . '</td>';
			foreach (['e', 'm', 'neu', 'gruppen', 'ungeklaert', 'sonstige', 'doppelt'] as $f) {
				$wert = (int) ($j[ $f ] ?? 0);
				$liste = $f === 'ungeklaert' ? (array) ($j['ungeklaert_liste'] ?? []) : ($f === 'sonstige' ? (array) ($j['sonstige_liste'] ?? []) : []);
				echo '<td>' . ($liste !== [] ? '<details><summary>' . $wert . '</summary><small>' . esc_html(implode(', ', array_map('strval', $liste))) . '</small></details>' : (string) $wert) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function klaerung(ErgebnisRepository $repo): void {
		$offen = $repo->by_status(ErgebnisRepository::STATUS_UNGEKLAERT);
		echo '<h2 id="klaerung">' . esc_html(sprintf(__('Klärungsliste (%d)', 'ksv-km-meldeportal'), count($offen))) . '</h2>';
		if ($offen === []) {
			echo '<p>' . esc_html__('Keine offenen Dateien.', 'ksv-km-meldeportal') . '</p>';
			return;
		}
		echo '<p class="description">' . esc_html__('Dateien, deren Schlüssel im Katalog fehlt. Die Bezeichnung gilt für diesen Schlüssel in allen Archivjahren.', 'ksv-km-meldeportal') . '</p>';
		$darf = ErgebnisService::darf_hochladen();
		$ablage = (new ErgebnisService())->ablage();
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Jahr', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Datei', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Bezeichnung', 'ksv-km-meldeportal') . '</th></tr></thead><tbody>';
		foreach ($offen as $r) {
			$anker = 'k-' . (int) $r['id'];
			echo '<tr id="' . esc_attr($anker) . '"><td>' . (int) $r['jahr'] . '</td><td><a href="' . esc_url($ablage->url((string) $r['datei'])) . '" target="_blank" rel="noopener">' . esc_html(basename((string) $r['datei'])) . '</a></td><td>';
			if ($darf) {
				self::form_open('klaeren', 'class="kmm-erg-klaeren"');
				echo '<input type="hidden" name="id" value="' . (int) $r['id'] . '">';
				echo self::input('kennzahl', str_replace('_', '.', (string) $r['schluessel']), 'text', 'size="10" aria-label="' . esc_attr__('SpO-Nummer', 'ksv-km-meldeportal') . '"'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::input('disziplin_text', '', 'text', 'required placeholder="' . esc_attr__('Disziplin, z. B. Luftgewehr', 'ksv-km-meldeportal') . '"'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::input('klasse_text', '', 'text', 'placeholder="' . esc_attr__('Klasse, z. B. Herren I', 'ksv-km-meldeportal') . '"'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo ' <button class="button button-small">' . esc_html__('Speichern', 'ksv-km-meldeportal') . '</button></form>';
				self::zeilen_aktionen((int) $r['jahr'], $r, 'klaerung', 'archiv');
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function testjahr(): void {
		$kandidaten = Testjahr::kandidaten();
		if ($kandidaten === []) {
			return;
		}
		$t = new Testjahr();
		foreach ($kandidaten as $sj) {
			$n = $t->zaehlen((int) $sj['id']);
			echo '<div class="kmm-erg-testjahr"><h2>' . esc_html(sprintf(__('Testjahr entfernen: %s', 'ksv-km-meldeportal'), (string) $sj['bezeichnung'])) . '</h2>';
			echo '<p>' . esc_html(sprintf(__('Entfernt das Sportjahr mit allen Meldungen, Wettkampftagen, Buchungen, Belegen, Zugängen und dem Protokoll dieses Jahres sowie Schützen, die nur hier vorkommen. Die Ergebnis-Dateien im Ordner km_%d bleiben unberührt. Vorher wird alles als JSON in den geschützten Archivordner gesichert.', 'ksv-km-meldeportal'), (int) $sj['jahr'])) . '</p>';
			$teile = [];
			foreach ($n as $tabelle => $anzahl) {
				if ($anzahl > 0) {
					$teile[] = $tabelle . ': ' . $anzahl;
				}
			}
			echo '<p class="description">' . esc_html(implode(' · ', $teile)) . '</p>';
			self::form_open('testjahr_entfernen');
			echo '<input type="hidden" name="tab" value="archiv"><input type="hidden" name="sportjahr_id" value="' . (int) $sj['id'] . '">';
			echo '<label>' . esc_html(sprintf(__('Zur Bestätigung das Jahr %d eintippen:', 'ksv-km-meldeportal'), (int) $sj['jahr'])) . ' <input type="text" name="bestaetigung" size="6" autocomplete="off" required></label> ';
			submit_button(__('Testjahr endgültig entfernen', 'ksv-km-meldeportal'), 'delete', '', false);
			echo '</form></div>';
		}
	}

	// ----- Reiter Ausschreibungen -------------------------------------------------------------

	/**
	 * @param list<int> $jahre
	 */
	private static function tab_dokumente(int $jahr, array $jahre): void {
		$dok = new Dokumente();
		$jahre = array_values(array_unique(array_merge($jahre, $dok->jahre(), [(int) gmdate('Y') + 1])));
		rsort($jahre);
		self::jahr_auswahl($jahr, $jahre, 'dokumente');
		$darf = ErgebnisService::darf_hochladen();
		$liste = array_values($dok->by_jahr($jahr));
		echo '<h2>' . esc_html(sprintf(__('Unterlagen %d', 'ksv-km-meldeportal'), $jahr)) . '</h2>';
		if ($liste === []) {
			echo '<p>' . esc_html__('Noch keine Unterlagen für dieses Jahr.', 'ksv-km-meldeportal') . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Titel', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Art', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Ziel', 'ksv-km-meldeportal') . '</th><th></th></tr></thead><tbody>';
			foreach ($liste as $i => $d) {
				$url = Dokumente::aufloesen($d);
				echo '<tr><td>' . esc_html(Dokumente::titel($d)) . '</td><td>' . esc_html(Dokumente::typen()[ $d['typ'] ] ?? (string) $d['typ']) . '</td>';
				echo '<td>' . ($url !== '' ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html__('öffnen', 'ksv-km-meldeportal') . '</a>' : '<span class="kmm-erg-status is-ungeklaert">' . esc_html__('Ziel fehlt', 'ksv-km-meldeportal') . '</span>') . '</td><td class="kmm-erg-aktion">';
				if ($darf) {
					foreach ([-1 => '↑', 1 => '↓'] as $richtung => $pfeil) {
						if (($richtung < 0 && $i === 0) || ($richtung > 0 && $i === count($liste) - 1)) {
							continue;
						}
						self::form_open('dokument_schieben', 'class="kmm-inline"');
						echo '<input type="hidden" name="jahr" value="' . (int) $jahr . '"><input type="hidden" name="tab" value="dokumente"><input type="hidden" name="id" value="' . (int) $d['id'] . '"><input type="hidden" name="richtung" value="' . (int) $richtung . '">';
						echo '<button class="button button-small" aria-label="' . esc_attr($richtung < 0 ? __('nach oben', 'ksv-km-meldeportal') : __('nach unten', 'ksv-km-meldeportal')) . '">' . esc_html($pfeil) . '</button></form> ';
					}
					self::form_open('dokument_loeschen', 'class="kmm-inline" data-kmm-gefahr="1" data-kmm-confirm="' . esc_attr__('Verweis entfernen? Das PDF bleibt in der Mediathek.', 'ksv-km-meldeportal') . '"');
					echo '<input type="hidden" name="jahr" value="' . (int) $jahr . '"><input type="hidden" name="tab" value="dokumente"><input type="hidden" name="id" value="' . (int) $d['id'] . '">';
					echo '<button class="button-link kmm-link-danger">' . esc_html__('entfernen', 'ksv-km-meldeportal') . '</button></form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		if (!$darf) {
			return;
		}
		$typen = Dokumente::typen();
		unset($typen[ Dokumente::TYP_LICHT ]);
		$sparten = ['' => '—'];
		foreach (Kategorie::ALLE as $nr => $label) {
			$sparten[ $nr ] = $nr . ' ' . $label;
		}
		echo '<h3>' . esc_html__('Unterlage hinzufügen', 'ksv-km-meldeportal') . '</h3>';
		self::form_open('dokument', 'enctype="multipart/form-data" class="kmm-erg-dokform"');
		echo '<input type="hidden" name="jahr" value="' . (int) $jahr . '"><input type="hidden" name="tab" value="dokumente">';
		echo '<p><label>' . esc_html__('Art', 'ksv-km-meldeportal') . ' ' . self::select('typ', $typen, Dokumente::TYP_AUSSCHREIBUNG) . '</label> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<label>' . esc_html__('Sparte (bei Art „Sparte“)', 'ksv-km-meldeportal') . ' ' . self::select('kategorie', $sparten, '') . '</label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p><label>' . esc_html__('Titel (optional)', 'ksv-km-meldeportal') . ' ' . self::input('titel', '', 'text', 'class="regular-text"') . '</label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<fieldset><legend>' . esc_html__('Quelle', 'ksv-km-meldeportal') . '</legend>';
		echo '<p><label><input type="radio" name="quelle" value="pdf" checked> ' . esc_html__('PDF hochladen', 'ksv-km-meldeportal') . '</label> <input type="file" name="pdf" accept=".pdf"></p>';
		echo '<p><label><input type="radio" name="quelle" value="page"> ' . esc_html__('Seite', 'ksv-km-meldeportal') . '</label> ';
		wp_dropdown_pages(['name' => 'page_id', 'show_option_none' => '—', 'option_none_value' => '0']);
		echo '</p><p><label><input type="radio" name="quelle" value="url"> ' . esc_html__('Adresse', 'ksv-km-meldeportal') . '</label> ' . self::input('url', '', 'url', 'class="regular-text" placeholder="https://"') . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</fieldset>';
		submit_button(__('Hinzufügen', 'ksv-km-meldeportal'), 'primary', '', false);
		echo '</form>';
	}

	// ----- Reiter Einstellungen ---------------------------------------------------------------

	private static function tab_einstellungen(): void {
		$e = ErgebnisEinstellungen::all();
		self::form_open('einstellungen');
		echo '<input type="hidden" name="tab" value="einstellungen">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row"><label for="kmm-erg-pfad">' . esc_html__('Ergebnisordner', 'ksv-km-meldeportal') . '</label></th><td>' . self::input('pfad', $e['pfad'], 'text', 'id="kmm-erg-pfad" class="large-text code"'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p class="description">' . esc_html(sprintf(__('Leer = %s. Der eine Ablageort für alle Ergebnisse, alte wie neue.', 'ksv-km-meldeportal'), ErgebnisEinstellungen::pfad())) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="kmm-erg-url">' . esc_html__('Adresse des Ordners', 'ksv-km-meldeportal') . '</label></th><td>' . self::input('url', $e['url'], 'url', 'id="kmm-erg-url" class="large-text code"'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p class="description">' . esc_html(sprintf(__('Leer = %s', 'ksv-km-meldeportal'), ErgebnisEinstellungen::url())) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="seite_id">' . esc_html__('Ergebnisseite', 'ksv-km-meldeportal') . '</label></th><td>';
		wp_dropdown_pages(['name' => 'seite_id', 'id' => 'seite_id', 'selected' => (int) $e['seite_id'], 'show_option_none' => '—', 'option_none_value' => '0']);
		echo '<p class="description">' . esc_html__('Seite mit [srd_km] oder [kmm_ergebnisse]; dorthin zeigen die Jahres- und Ergebnisadressen.', 'ksv-km-meldeportal') . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="kmm-erg-slug">' . esc_html__('Adresspräfix', 'ksv-km-meldeportal') . '</label></th><td>' . self::input('slug', $e['slug'], 'text', 'id="kmm-erg-slug" class="regular-text code"'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p class="description">' . esc_html(sprintf(__('z. B. %s/2025/e/1_10_10/', 'ksv-km-meldeportal'), home_url('/' . (string) $e['slug']))) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__('Anzeige übernehmen', 'ksv-km-meldeportal') . '</th><td>' . self::checkbox('anzeige_uebernehmen', (bool) $e['anzeige_uebernehmen'], __('Das KM-Portal bedient [srd_km] und die alten Adressen', 'ksv-km-meldeportal')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p class="description">';
		if (ErgebnisEinstellungen::srd_aktiv()) {
			echo '<strong>' . esc_html__('Das bisherige Ergebnis-Plugin ist noch aktiv.', 'ksv-km-meldeportal') . '</strong> ';
			esc_html_e('Solange es läuft, bleibt die Anzeige bei ihm, auch wenn der Schalter an ist. Erst nach dem Deaktivieren übernimmt das KM-Portal.', 'ksv-km-meldeportal');
		} else {
			esc_html_e('Das bisherige Ergebnis-Plugin ist nicht aktiv.', 'ksv-km-meldeportal');
		}
		echo '</p></td></tr>';
		echo '</tbody></table>';
		submit_button(__('Speichern', 'ksv-km-meldeportal'));
		echo '</form>';
	}

	private static function script(): void {
		echo '<script>(function(){
var suche=document.querySelector(".kmm-erg-suche"),offen=document.querySelector(".kmm-erg-nur-offen");
function filtern(){
	var q=(suche&&suche.value||"").toLowerCase().trim(),nur=offen&&offen.checked;
	document.querySelectorAll(".kmm-erg-disziplin").forEach(function(d){
		var treffer=!q||d.dataset.suche.indexOf(q)!==-1,sichtbar=0;
		d.querySelectorAll(".kmm-erg-platz").forEach(function(r){
			var an=treffer||r.textContent.toLowerCase().indexOf(q)!==-1;
			if(nur&&!r.classList.contains("is-offen")){an=false;}
			r.hidden=!an;if(an){sichtbar++;}
		});
		d.hidden=sichtbar===0;if(q||nur){d.open=sichtbar>0;}
	});
}
if(suche){suche.addEventListener("input",filtern);}
if(offen){offen.addEventListener("change",filtern);}
if(location.hash){var z=document.getElementById(location.hash.slice(1));if(z){var d=z.closest("details");if(d){d.open=true;}z.classList.add("is-ziel");z.scrollIntoView({block:"center"});}}
})();</script>';
	}
}
