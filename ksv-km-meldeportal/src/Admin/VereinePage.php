<?php
/**
 * Backend: Vereinsverwaltung und Linkversand.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Admin;

use KSV\KMM\Application\Mailer;
use KSV\KMM\Application\VereinService;
use KSV\KMM\Application\Zugang;
use KSV\KMM\Infrastructure\Repository\MagicLinkRepository;
use KSV\KMM\Infrastructure\Repository\MailLogRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinEmailRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;
use KSV\KMM\Support\Clock;

final class VereinePage extends AdminPage {

	public const SLUG = 'kmm-vereine';

	public static function handle_post(): void {
		if (!self::is_own_post()) {
			return;
		}
		self::verify();
		$service = new VereinService();
		try {
			switch (self::action()) {
				case 'speichern':
					$adressen = preg_split('/[\s,;]+/', self::post_text('adressen')) ?: [];
					$id = $service->speichern(self::post_int('id'), self::post_str('name', 150), self::post_str('vn_nummer', 5), $adressen, self::post_bool('ist_aktiv'), self::post_text('notiz'));
					self::redirect(__('Verein gespeichert.', 'ksv-km-meldeportal'), 'success', ['edit' => $id]);
				case 'loeschen':
					$service->loeschen(self::post_int('id'));
					self::redirect(__('Verein gelöscht.', 'ksv-km-meldeportal'));
				case 'link_senden':
					$sportjahr = self::aktives_sportjahr();
					$r = Zugang::link_senden(self::post_int('id'), (int) $sportjahr['id'], Zugang::ANLASS_ADMIN, true);
					self::redirect($r['ok'] ? sprintf(__('Zugangslink an %d Adresse(n) gesendet. Der alte Link ist ungültig.', 'ksv-km-meldeportal'), $r['empfaenger']) : __('Mailversand fehlgeschlagen, siehe Mail-Protokoll.', 'ksv-km-meldeportal'), $r['ok'] ? 'success' : 'error');
				case 'link_alle':
					$sportjahr = self::aktives_sportjahr();
					$ok = 0;
					$fehler = [];
					foreach ((new VereinRepository())->all(true) as $verein) {
						try {
							$r = Zugang::link_senden((int) $verein['id'], (int) $sportjahr['id'], Zugang::ANLASS_ALLE, true);
							if ($r['ok']) {
								$ok++;
							} else {
								$fehler[] = (string) $verein['name'];
							}
						} catch (\RuntimeException $e) {
							$fehler[] = $e->getMessage();
						}
					}
					$text = sprintf(__('Zugangslinks an %d Vereine gesendet.', 'ksv-km-meldeportal'), $ok);
					if ($fehler !== []) {
						$text .= "\n" . __('Fehler:', 'ksv-km-meldeportal') . ' ' . implode(', ', $fehler);
					}
					self::redirect($text, $fehler === [] ? 'success' : 'warning');
			}
		} catch (\RuntimeException $e) {
			self::redirect($e->getMessage(), 'error');
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function aktives_sportjahr(): array {
		$sportjahr = (new SportjahrRepository())->aktiv();
		if ($sportjahr === null) {
			throw new \RuntimeException(__('Kein aktives Sportjahr. Bitte zuerst unter Sportjahre eines aktivieren.', 'ksv-km-meldeportal'));
		}
		return $sportjahr;
	}

	public static function render(): void {
		self::require_manage();
		$vereine = new VereinRepository();
		$edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
		$edit = $edit_id > 0 ? $vereine->find($edit_id) : null;
		$neu = isset($_GET['edit']) && $_GET['edit'] === 'neu';
		$sportjahr = (new SportjahrRepository())->aktiv();

		echo '<div class="wrap kmm-admin">';
		Menu::page_header(__('Vereine', 'ksv-km-meldeportal'));
		self::show_notices();

		if ($edit !== null || $neu) {
			self::render_form($edit);
		}

		$alle = $vereine->all();
		$adressen = (new VereinEmailRepository())->alle();
		$links = $sportjahr !== null ? (new MagicLinkRepository())->aktuelle_je_verein((int) $sportjahr['id']) : [];
		$mails = $sportjahr !== null ? (new MailLogRepository())->letzte_je_verein((int) $sportjahr['id'], Mailer::TYP_MAGIC_LINK) : [];

		echo '<p><a class="button button-primary" href="' . esc_url(self::url(['edit' => 'neu'])) . '">' . esc_html__('Neuer Verein', 'ksv-km-meldeportal') . '</a> ';
		if ($sportjahr !== null) {
			self::form_open('link_alle', 'class="kmm-inline-form" onsubmit="return confirm(\'' . esc_js(__('Zugangslinks an alle aktiven Vereine senden? Bestehende Links werden ungültig.', 'ksv-km-meldeportal')) . '\')"');
			submit_button(sprintf(__('Links an alle aktiven Vereine senden (Sportjahr %d)', 'ksv-km-meldeportal'), (int) $sportjahr['jahr']), 'secondary', 'submit', false);
			echo '</form>';
		} else {
			echo '<span class="description">' . esc_html__('Kein aktives Sportjahr – Linkversand nicht möglich.', 'ksv-km-meldeportal') . '</span>';
		}
		echo '</p>';
		echo '<p class="description">' . sprintf(esc_html__('Seite „Link anfordern“ für Vereine: %s', 'ksv-km-meldeportal'), '<a href="' . esc_url(\KSV\KMM\Http\Router::url('link-anfordern')) . '" target="_blank" rel="noopener">' . esc_html(\KSV\KMM\Http\Router::url('link-anfordern')) . '</a>') . '</p>';

		if ($alle === []) {
			echo '<p>' . esc_html__('Noch keine Vereine angelegt.', 'ksv-km-meldeportal') . '</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Verein', 'ksv-km-meldeportal') . '</th><th>VN</th><th>' . esc_html__('E-Mail', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Aktiv', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Zugangslink', 'ksv-km-meldeportal') . '</th><th></th></tr></thead><tbody>';
		foreach ($alle as $v) {
			$id = (int) $v['id'];
			$link = $links[ $id ] ?? null;
			$mail = $mails[ $id ] ?? null;
			echo '<tr' . ($v['ist_aktiv'] ? '' : ' class="kmm-muted"') . '>';
			echo '<td><strong>' . esc_html((string) $v['name']) . '</strong></td>';
			echo '<td>' . esc_html((string) $v['vn_nummer']) . '</td>';
			echo '<td>' . esc_html(implode(', ', $adressen[ $id ] ?? [])) . '</td>';
			echo '<td>' . ($v['ist_aktiv'] ? '✔' : '–') . '</td>';
			echo '<td>';
			if ($link !== null) {
				echo esc_html(sprintf(__('erstellt %s, %d× verwendet', 'ksv-km-meldeportal'), Clock::format_local($link['erstellt_am']), (int) $link['verwendungen']));
				if ($mail !== null) {
					echo '<br><small>' . ($mail['erfolgreich'] ? '✔ ' : '✘ ') . esc_html(sprintf(__('Mail %s', 'ksv-km-meldeportal'), Clock::format_local($mail['gesendet_am']))) . ($mail['fehler'] !== '' ? ' – ' . esc_html((string) $mail['fehler']) : '') . '</small>';
				}
			} else {
				echo '<span class="description">' . esc_html__('noch kein Link', 'ksv-km-meldeportal') . '</span>';
			}
			echo '</td>';
			echo '<td class="kmm-actions"><a class="button button-small" href="' . esc_url(self::url(['edit' => $id])) . '">' . esc_html__('Bearbeiten', 'ksv-km-meldeportal') . '</a> ';
			if ($sportjahr !== null && $v['ist_aktiv'] && ($adressen[ $id ] ?? []) !== []) {
				self::form_open('link_senden', 'class="kmm-inline-form"');
				echo '<input type="hidden" name="id" value="' . $id . '">';
				submit_button($link !== null ? __('Link neu senden', 'ksv-km-meldeportal') : __('Link senden', 'ksv-km-meldeportal'), 'small', 'submit', false);
				echo '</form> ';
			}
			self::form_open('loeschen', 'class="kmm-inline-form" onsubmit="return confirm(\'' . esc_js(__('Verein löschen?', 'ksv-km-meldeportal')) . '\')"');
			echo '<input type="hidden" name="id" value="' . $id . '">';
			submit_button(__('Löschen', 'ksv-km-meldeportal'), 'small kmm-danger', 'submit', false);
			echo '</form></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * @param array<string, mixed>|null $v
	 */
	private static function render_form(?array $v): void {
		$adressen = $v !== null ? (new VereinEmailRepository())->adressen((int) $v['id']) : [];
		echo '<h2>' . esc_html($v === null ? __('Neuer Verein', 'ksv-km-meldeportal') : sprintf(__('%s bearbeiten', 'ksv-km-meldeportal'), (string) $v['name'])) . '</h2>';
		self::form_open('speichern');
		echo '<input type="hidden" name="id" value="' . (int) ($v['id'] ?? 0) . '">';
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__('Name', 'ksv-km-meldeportal') . '</th><td>' . self::input('name', $v['name'] ?? '', 'text', 'class="regular-text" required') . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th>' . esc_html__('VN-Nummer', 'ksv-km-meldeportal') . '</th><td>' . self::input('vn_nummer', $v['vn_nummer'] ?? '', 'text', 'class="kmm-short" pattern="[0-9]{5}" required') . '<p class="description">' . esc_html__('5-stellig. Mitgliedsnummern der Schützen sollten damit beginnen.', 'ksv-km-meldeportal') . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th>' . esc_html__('E-Mail-Adressen', 'ksv-km-meldeportal') . '</th><td><textarea name="adressen" rows="3" class="regular-text">' . esc_textarea(implode("\n", $adressen)) . '</textarea><p class="description">' . esc_html__('Eine Adresse je Zeile. Alle Adressen erhalten den Zugangslink und die Bestätigungsmails.', 'ksv-km-meldeportal') . '</p></td></tr>';
		echo '<tr><th>' . esc_html__('Aktiv', 'ksv-km-meldeportal') . '</th><td>' . self::checkbox('ist_aktiv', $v === null ? true : (bool) $v['ist_aktiv'], __('nimmt an der Meldung teil', 'ksv-km-meldeportal')) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th>' . esc_html__('Notiz', 'ksv-km-meldeportal') . '</th><td><textarea name="notiz" rows="2" class="regular-text">' . esc_textarea((string) ($v['notiz'] ?? '')) . '</textarea></td></tr>';
		echo '</table>';
		submit_button(__('Speichern', 'ksv-km-meldeportal'), 'primary', 'submit', false);
		echo ' <a class="button" href="' . esc_url(self::url()) . '">' . esc_html__('Abbrechen', 'ksv-km-meldeportal') . '</a>';
		echo '</form><hr>';
	}
}
