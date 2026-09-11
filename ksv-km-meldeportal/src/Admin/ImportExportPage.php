<?php
/**
 * Backend: Regeltabelle als JSON importieren (mit Prüfung) und exportieren.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Admin;

use KSV\KMM\Application\RegeltabelleExporter;
use KSV\KMM\Application\RegeltabelleImporter;
use KSV\KMM\Domain\Regeltabelle\Dokument;
use KSV\KMM\Domain\Regeltabelle\DokumentFehler;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;

final class ImportExportPage extends AdminPage {

	public const SLUG = 'kmm-import';

	public static function handle_post(): void {
		if (!self::is_own_post()) {
			return;
		}
		self::verify();
		$sportjahr_id = self::post_int('sportjahr_id');
		$args = ['sportjahr' => $sportjahr_id];

		if (self::action() === 'export') {
			$sportjahr = (new SportjahrRepository())->find($sportjahr_id);
			if ($sportjahr === null) {
				self::redirect(__('Sportjahr nicht gefunden.', 'ksv-km-meldeportal'), 'error');
			}
			$json = (new RegeltabelleExporter())->exportieren($sportjahr_id)->toJson();
			nocache_headers();
			header('Content-Type: application/json; charset=utf-8');
			header('Content-Disposition: attachment; filename="kmm-regeltabelle-' . (int) $sportjahr['jahr'] . '.json"');
			echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		if (self::action() === 'import') {
			$json = self::read_upload_or_text();
			if ($json === '') {
				self::redirect(__('Bitte eine JSON-Datei hochladen oder JSON einfügen.', 'ksv-km-meldeportal'), 'error', $args);
			}
			try {
				$doc = Dokument::fromJson($json);
			} catch (DokumentFehler $e) {
				self::redirect(__('Dokument ungültig:', 'ksv-km-meldeportal') . "\n" . implode("\n", array_slice($e->fehler, 0, 30)), 'error', $args);
			}
			$modus = self::post_str('modus') === RegeltabelleImporter::MODUS_ERSETZEN ? RegeltabelleImporter::MODUS_ERSETZEN : RegeltabelleImporter::MODUS_ERGAENZEN;
			$sportjahr = (new SportjahrRepository())->find($sportjahr_id);
			if ($sportjahr === null) {
				self::redirect(__('Sportjahr nicht gefunden.', 'ksv-km-meldeportal'), 'error');
			}
			if ($doc->sportjahr !== null && $doc->sportjahr !== (int) $sportjahr['jahr'] && !self::post_bool('jahr_ignorieren')) {
				self::redirect(sprintf(__('Das Dokument ist für Sportjahr %d, gewählt ist %d. Zum Übernehmen „Jahr im Dokument ignorieren“ ankreuzen.', 'ksv-km-meldeportal'), $doc->sportjahr, (int) $sportjahr['jahr']), 'error', $args);
			}
			try {
				$report = (new RegeltabelleImporter())->importieren($sportjahr_id, $doc, $modus);
			} catch (\RuntimeException $e) {
				self::redirect($e->getMessage(), 'error', $args);
			}
			$text = sprintf(
				__('Import abgeschlossen: %1$d Gruppen neu, %2$d Klassen neu, %3$d Klassen aktualisiert, %4$d Disziplinen neu, %5$d aktualisiert, %6$d gelöscht, %7$d Regeln.', 'ksv-km-meldeportal'),
				$report['gruppen_neu'],
				$report['klassen_neu'],
				$report['klassen_aktualisiert'],
				$report['disziplinen_neu'],
				$report['disziplinen_aktualisiert'],
				$report['disziplinen_geloescht'],
				$report['regeln']
			);
			if ($report['warnungen'] !== []) {
				$text .= "\n" . implode("\n", $report['warnungen']);
			}
			self::redirect($text, $report['warnungen'] === [] ? 'success' : 'warning', $args);
		}
	}

	private static function read_upload_or_text(): string {
		if (!empty($_FILES['datei']['tmp_name']) && is_uploaded_file((string) $_FILES['datei']['tmp_name'])) {
			$content = file_get_contents((string) $_FILES['datei']['tmp_name']);
			return is_string($content) ? $content : '';
		}
		return isset($_POST['json']) ? (string) wp_unslash($_POST['json']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- JSON wird geparst und validiert.
	}

	public static function render(): void {
		self::require_manage();
		$sportjahr = self::current_sportjahr();
		echo '<div class="wrap kmm-admin">';
		Menu::page_header(__('Regeltabelle: Import / Export', 'ksv-km-meldeportal'));
		self::show_notices();
		if ($sportjahr === null) {
			self::no_sportjahr_notice();
			echo '</div>';
			return;
		}
		$sid = (int) $sportjahr['id'];
		self::sportjahr_selector($sportjahr);

		echo '<h2>' . esc_html__('Import', 'ksv-km-meldeportal') . '</h2>';
		echo '<p class="description">' . esc_html__('Format „kmm-regeltabelle“ Version 1, wie es das Konvertierungsskript (tools/) erzeugt. Gruppen und Klassen werden angelegt oder aktualisiert, nie gelöscht. Regeln importierter Disziplinen werden ersetzt. Nach dem Import werden bestehende Meldungen neu geprüft.', 'ksv-km-meldeportal') . '</p>';
		self::form_open('import', 'enctype="multipart/form-data"');
		echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '">';
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__('JSON-Datei', 'ksv-km-meldeportal') . '</th><td><input type="file" name="datei" accept=".json,application/json"></td></tr>';
		echo '<tr><th>' . esc_html__('oder JSON einfügen', 'ksv-km-meldeportal') . '</th><td><textarea name="json" rows="8" class="large-text code"></textarea></td></tr>';
		echo '<tr><th>' . esc_html__('Modus', 'ksv-km-meldeportal') . '</th><td>';
		echo '<label><input type="radio" name="modus" value="ergaenzen" checked> ' . esc_html__('Ergänzen – Disziplinen, die im Dokument fehlen, bleiben erhalten', 'ksv-km-meldeportal') . '</label><br>';
		echo '<label><input type="radio" name="modus" value="ersetzen"> ' . esc_html__('Ersetzen – fehlende Disziplinen werden gelöscht (außer sie haben Meldungen)', 'ksv-km-meldeportal') . '</label>';
		echo '</td></tr>';
		echo '<tr><th></th><td>' . self::checkbox('jahr_ignorieren', false, __('Jahr im Dokument ignorieren (z. B. Regeln 2026 in Sportjahr 2027 übernehmen)', 'ksv-km-meldeportal')) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</table>';
		submit_button(__('Importieren', 'ksv-km-meldeportal'));
		echo '</form>';

		echo '<h2>' . esc_html__('Export', 'ksv-km-meldeportal') . '</h2>';
		echo '<p class="description">' . esc_html__('Vollständige Stammdaten des Sportjahres als JSON – zur Sicherung, zum Bearbeiten oder zum Übertragen in ein anderes Sportjahr.', 'ksv-km-meldeportal') . '</p>';
		self::form_open('export');
		echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '">';
		submit_button(sprintf(__('Regeltabelle %d herunterladen', 'ksv-km-meldeportal'), (int) $sportjahr['jahr']), 'secondary');
		echo '</form>';
		echo '</div>';
	}
}
