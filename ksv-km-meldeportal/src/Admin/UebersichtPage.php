<?php
/**
 * Backend: Statusübersicht aller Vereine im Sportjahr – Status, Meldungen, fehlende
 * Ergebnisse, Konflikte, unvollständige Mannschaften, Linkversand, Erinnerung.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Admin;

use KSV\KMM\Application\Erinnerung;
use KSV\KMM\Application\Mailer;
use KSV\KMM\Application\MeldungService;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\EinzelmeldungRepository;
use KSV\KMM\Infrastructure\Repository\MagicLinkRepository;
use KSV\KMM\Infrastructure\Repository\MailLogRepository;
use KSV\KMM\Infrastructure\Repository\MannschaftRepository;
use KSV\KMM\Infrastructure\Repository\MeldungRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;
use KSV\KMM\Support\Clock;

final class UebersichtPage extends AdminPage {

	public const SLUG = 'kmm';

	public static function handle_post(): void {
		if (!self::is_own_post()) {
			return;
		}
		self::verify();
		$sid = self::post_int('sportjahr_id');
		try {
			if (self::action() === 'erinnerung_senden') {
				$r = Erinnerung::senden($sid, true);
				$text = sprintf(__('Erinnerung an %d Vereine gesendet.', 'ksv-km-meldeportal'), $r['gesendet']);
				if ($r['fehler'] !== []) {
					$text .= "\n" . implode("\n", $r['fehler']);
				}
				self::redirect($text, $r['fehler'] === [] ? 'success' : 'warning', ['sportjahr' => $sid]);
			}
			if (self::action() === 'erinnerung_zuruecksetzen') {
				(new SportjahrRepository())->update($sid, ['erinnerung_gesendet_am' => null]);
				self::redirect(__('Versandvermerk zurückgesetzt; die Erinnerung wird zum eingestellten Zeitpunkt erneut gesendet.', 'ksv-km-meldeportal'), 'success', ['sportjahr' => $sid]);
			}
		} catch (\RuntimeException $e) {
			self::redirect($e->getMessage(), 'error', ['sportjahr' => $sid]);
		}
	}

	public static function render(): void {
		if (!current_user_can(\KSV\KMM\Auth\Capabilities::VIEW)) {
			wp_die(esc_html__('Keine Berechtigung.', 'ksv-km-meldeportal'));
		}
		$sportjahr = self::current_sportjahr();
		echo '<div class="wrap kmm-admin">';
		Menu::page_header(__('Übersicht', 'ksv-km-meldeportal'));
		self::show_notices();
		if ($sportjahr === null) {
			self::no_sportjahr_notice();
			echo '</div>';
			return;
		}
		$sid = (int) $sportjahr['id'];
		self::sportjahr_selector($sportjahr);
		self::render_phase($sportjahr);

		$vereine = (new VereinRepository())->all();
		$meldungen = (new MeldungRepository())->by_sportjahr($sid);
		$einzel = (new EinzelmeldungRepository())->by_sportjahr($sid);
		$mannschaften = (new MannschaftRepository())->where(['sportjahr_id' => $sid]);
		$links = (new MagicLinkRepository())->aktuelle_je_verein($sid);
		$mails = (new MailLogRepository())->letzte_je_verein($sid, Mailer::TYP_MAGIC_LINK);
		$rw = RegelwerkLader::laden($sid);

		$je_verein = [];
		foreach ($einzel as $em) {
			$vid = (int) $em['verein_id'];
			$je_verein[ $vid ] ??= ['einzel' => 0, 'ohne_ergebnis' => 0, 'konflikte' => 0, 'ohne_startrecht' => 0, 'mannschaften' => 0, 'unvollstaendig' => 0, 'startgeld' => 0.0];
			$je_verein[ $vid ]['einzel']++;
			$d = $rw->disziplin((int) $em['disziplin_id']);
			if ($em['meldeergebnis'] === null && $d !== null && !$d->ist_mixteam()) {
				$je_verein[ $vid ]['ohne_ergebnis']++;
			}
			if ($em['konflikt']) {
				$je_verein[ $vid ]['konflikte']++;
			}
			if (!$em['startrecht']) {
				$je_verein[ $vid ]['ohne_startrecht']++;
			}
			if ($em['startrecht'] && !$em['konflikt']) {
				$je_verein[ $vid ]['startgeld'] += (float) $em['startgeld'];
			}
		}
		foreach ($mannschaften as $ma) {
			$vid = (int) $ma['verein_id'];
			$je_verein[ $vid ] ??= ['einzel' => 0, 'ohne_ergebnis' => 0, 'konflikte' => 0, 'ohne_startrecht' => 0, 'mannschaften' => 0, 'unvollstaendig' => 0, 'startgeld' => 0.0];
			$je_verein[ $vid ]['mannschaften']++;
			$je_verein[ $vid ]['startgeld'] += (float) $ma['startgeld'];
			$d = $rw->disziplin((int) $ma['disziplin_id']);
			$n = 0;
			foreach ($einzel as $em) {
				if ((int) $em['mannschaft_id'] === (int) $ma['id']) {
					$n++;
				}
			}
			if ($d !== null && $n !== $d->mannschaft_groesse) {
				$je_verein[ $vid ]['unvollstaendig']++;
			}
		}

		$summe = ['offen' => 0, 'entwurf' => 0, 'eingereicht' => 0, 'einzel' => 0, 'ohne_ergebnis' => 0, 'konflikte' => 0, 'mannschaften' => 0, 'startgeld' => 0.0];
		$zeilen = [];
		foreach ($vereine as $v) {
			$vid = (int) $v['id'];
			$m = $meldungen[ $vid ] ?? null;
			$status = $m !== null ? (string) $m['status'] : MeldungRepository::STATUS_OFFEN;
			$z = $je_verein[ $vid ] ?? ['einzel' => 0, 'ohne_ergebnis' => 0, 'konflikte' => 0, 'ohne_startrecht' => 0, 'mannschaften' => 0, 'unvollstaendig' => 0, 'startgeld' => 0.0];
			if ($v['ist_aktiv']) {
				$summe[ $status ] = ($summe[ $status ] ?? 0) + 1;
			}
			foreach (['einzel', 'ohne_ergebnis', 'konflikte', 'mannschaften', 'startgeld'] as $k) {
				$summe[ $k ] += $z[ $k ];
			}
			$zeilen[] = ['verein' => $v, 'meldung' => $m, 'status' => $status, 'zahlen' => $z, 'link' => $links[ $vid ] ?? null, 'mail' => $mails[ $vid ] ?? null];
		}

		echo '<div class="kmm-kacheln">';
		self::kachel(__('Offen', 'ksv-km-meldeportal'), (string) $summe['offen']);
		self::kachel(__('Entwurf', 'ksv-km-meldeportal'), (string) $summe['entwurf']);
		self::kachel(__('Eingereicht', 'ksv-km-meldeportal'), (string) $summe['eingereicht']);
		self::kachel(__('Einzelmeldungen', 'ksv-km-meldeportal'), (string) $summe['einzel']);
		self::kachel(__('Mannschaften', 'ksv-km-meldeportal'), (string) $summe['mannschaften']);
		self::kachel(__('Ohne Ergebnis', 'ksv-km-meldeportal'), (string) $summe['ohne_ergebnis'], $summe['ohne_ergebnis'] > 0 ? 'warn' : '');
		self::kachel(__('Konflikte', 'ksv-km-meldeportal'), (string) $summe['konflikte'], $summe['konflikte'] > 0 ? 'fail' : '');
		self::kachel(__('Startgeld (vorläufig)', 'ksv-km-meldeportal'), self::geld($summe['startgeld']));
		echo '</div>';

		$detail = isset($_GET['verein']) ? (int) $_GET['verein'] : 0;

		echo '<table class="widefat striped kmm-uebersicht"><thead><tr><th>' . esc_html__('Verein', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Status', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Ansprechpartner', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Einzel', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Mannsch.', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Ohne Erg.', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Konflikte', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Startgeld', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Zugang', 'ksv-km-meldeportal') . '</th><th></th></tr></thead><tbody>';
		foreach ($zeilen as $z) {
			$v = $z['verein'];
			$vid = (int) $v['id'];
			$n = $z['zahlen'];
			echo '<tr' . ($v['ist_aktiv'] ? '' : ' class="kmm-muted"') . '>';
			echo '<td><strong>' . esc_html((string) $v['name']) . '</strong><br><small>VN ' . esc_html((string) $v['vn_nummer']) . ($v['ist_aktiv'] ? '' : ' · ' . esc_html__('inaktiv', 'ksv-km-meldeportal')) . '</small></td>';
			echo '<td>' . self::status_badge($z['status']) . ($z['meldung'] !== null && $z['meldung']['eingereicht_am'] ? '<br><small>' . esc_html(Clock::format_local($z['meldung']['eingereicht_am'])) . '</small>' : '') . '</td>';
			echo '<td>' . ($z['meldung'] !== null && $z['meldung']['ansprechpartner_name'] !== '' ? esc_html((string) $z['meldung']['ansprechpartner_name']) . '<br><small><a href="mailto:' . esc_attr((string) $z['meldung']['ansprechpartner_email']) . '">' . esc_html((string) $z['meldung']['ansprechpartner_email']) . '</a> ' . esc_html((string) $z['meldung']['ansprechpartner_telefon']) . '</small>' : '–') . '</td>';
			echo '<td class="r">' . (int) $n['einzel'] . ($n['ohne_startrecht'] > 0 ? ' <span class="kmm-fail" title="' . esc_attr__('ohne Startrecht', 'ksv-km-meldeportal') . '">(' . (int) $n['ohne_startrecht'] . ')</span>' : '') . '</td>';
			echo '<td class="r">' . (int) $n['mannschaften'] . ($n['unvollstaendig'] > 0 ? ' <span class="kmm-fail" title="' . esc_attr__('unvollständig', 'ksv-km-meldeportal') . '">(' . (int) $n['unvollstaendig'] . ')</span>' : '') . '</td>';
			echo '<td class="r">' . ($n['ohne_ergebnis'] > 0 ? '<span class="kmm-warn">' . (int) $n['ohne_ergebnis'] . '</span>' : '0') . '</td>';
			echo '<td class="r">' . ($n['konflikte'] > 0 ? '<span class="kmm-fail">' . (int) $n['konflikte'] . '</span>' : '0') . '</td>';
			echo '<td class="r">' . esc_html(self::geld($n['startgeld'])) . '</td>';
			echo '<td><small>' . ($z['link'] !== null ? esc_html(sprintf(__('Link %s, %d× genutzt', 'ksv-km-meldeportal'), Clock::format_local($z['link']['erstellt_am'], 'd.m.'), (int) $z['link']['verwendungen'])) : esc_html__('kein Link', 'ksv-km-meldeportal')) . ($z['mail'] !== null && !$z['mail']['erfolgreich'] ? '<br><span class="kmm-fail">' . esc_html__('Mailfehler', 'ksv-km-meldeportal') . '</span>' : '') . '</small></td>';
			echo '<td>' . ($n['einzel'] > 0 ? '<a class="button button-small" href="' . esc_url(self::url(['sportjahr' => $sid, 'verein' => $vid])) . '">' . esc_html__('Details', 'ksv-km-meldeportal') . '</a>' : '') . '</td>';
			echo '</tr>';
			if ($detail === $vid) {
				echo '<tr class="kmm-detail"><td colspan="10">';
				self::render_detail($v, $sid);
				echo '</td></tr>';
			}
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__('Zahlen in Klammern: ohne Startrecht bzw. unvollständige Mannschaften. Konflikte entstehen durch Regeländerungen oder geänderte Schützendaten und müssen vom Verein bestätigt oder entfernt werden.', 'ksv-km-meldeportal') . '</p>';
		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $sportjahr
	 */
	private static function render_phase(array $sportjahr): void {
		$sid = (int) $sportjahr['id'];
		$phase = \KSV\KMM\Application\Meldephase::pruefen($sportjahr);
		echo '<div class="kmm-phase">';
		echo '<p><strong>' . esc_html__('Meldephase:', 'ksv-km-meldeportal') . '</strong> ' . esc_html(Clock::format_local($sportjahr['meldung_beginn']) ?: '–') . ' – ' . esc_html(Clock::format_local($sportjahr['meldeschluss']) ?: '–');
		echo ' <span class="kmm-badge">' . esc_html(match ($phase['status']) {
			'vor_beginn' => __('noch nicht begonnen', 'ksv-km-meldeportal'),
			'offen' => __('läuft', 'ksv-km-meldeportal'),
			'geschlossen' => __('Meldeschluss vorbei', 'ksv-km-meldeportal'),
			'abgeschlossen' => __('Sportjahr abgeschlossen', 'ksv-km-meldeportal'),
			default => $phase['status'],
		}) . '</span>';
		echo ' · <a href="' . esc_url(Menu::url(SportjahrePage::SLUG, ['edit' => $sid])) . '">' . esc_html__('bearbeiten', 'ksv-km-meldeportal') . '</a></p>';

		echo '<p><strong>' . esc_html__('Erinnerungsmail:', 'ksv-km-meldeportal') . '</strong> ';
		if ($sportjahr['erinnerung_am'] === null) {
			echo esc_html__('kein Zeitpunkt eingestellt', 'ksv-km-meldeportal');
		} else {
			echo esc_html(sprintf(__('geplant für %s', 'ksv-km-meldeportal'), Clock::format_local($sportjahr['erinnerung_am'])));
		}
		if ($sportjahr['erinnerung_gesendet_am'] !== null) {
			echo ' · ' . esc_html(sprintf(__('gesendet am %s', 'ksv-km-meldeportal'), Clock::format_local($sportjahr['erinnerung_gesendet_am'])));
		} elseif ($sportjahr['erinnerung_am'] !== null) {
			echo ' · ' . esc_html__('noch nicht gesendet (Versand über WP-Cron, stündlich)', 'ksv-km-meldeportal');
		}
		$anzahl = count(Erinnerung::empfaenger($sid));
		echo ' · ' . esc_html(sprintf(__('%d Vereine mit Status Offen/Entwurf und Adresse', 'ksv-km-meldeportal'), $anzahl));
		echo '</p>';
		if (current_user_can(\KSV\KMM\Auth\Capabilities::MANAGE)) {
			self::form_open('erinnerung_senden', 'class="kmm-inline-form" onsubmit="return confirm(\'' . esc_js(sprintf(__('Erinnerung jetzt an %d Vereine senden?', 'ksv-km-meldeportal'), $anzahl)) . '\')"');
			echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '">';
			submit_button(__('Erinnerung jetzt senden', 'ksv-km-meldeportal'), 'secondary', 'submit', false, $anzahl === 0 ? ['disabled' => 'disabled'] : []);
			echo '</form> ';
			if ($sportjahr['erinnerung_gesendet_am'] !== null) {
				self::form_open('erinnerung_zuruecksetzen', 'class="kmm-inline-form"');
				echo '<input type="hidden" name="sportjahr_id" value="' . $sid . '">';
				submit_button(__('Versandvermerk zurücksetzen', 'ksv-km-meldeportal'), 'secondary', 'submit', false);
				echo '</form>';
			}
		}
		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $verein
	 */
	private static function render_detail(array $verein, int $sid): void {
		$verein['sportjahr_id'] = $sid;
		$z = (new MeldungService($verein, $sid))->zusammenfassung();
		echo '<h3>' . esc_html((string) $verein['name']) . ' – ' . esc_html(sprintf(__('%d Einzelmeldungen, %d Mannschaften', 'ksv-km-meldeportal'), count($z['einzelmeldungen']), count($z['mannschaften']))) . '</h3>';
		echo '<table class="widefat"><thead><tr><th>Kennzahl</th><th>Disziplin</th><th>Name</th><th>Klasse</th><th>Startklasse</th><th>Ergebnis</th><th>Mannsch.</th><th class="r">Startgeld</th><th>Hinweise</th></tr></thead><tbody>';
		foreach ($z['einzelmeldungen'] as $e) {
			$klasse = $e['konflikt'] ? ' class="kmm-zeile-konflikt"' : ($e['meldeergebnis'] === '' && $e['typ'] !== 'mixteam' ? ' class="kmm-zeile-warn"' : '');
			echo '<tr' . $klasse . '><td>' . esc_html($e['kennzahl_voll']) . '</td><td>' . esc_html($e['disziplin']) . '</td><td>' . esc_html($e['nachname'] . ', ' . $e['vorname']) . ($e['para'] ? ' <small>(' . esc_html($e['para']) . ')</small>' : '') . '</td><td>' . esc_html($e['klasse']) . ($e['hoehermeldung'] ? ' <small>HM</small>' : '') . '</td><td>' . esc_html($e['startklasse']) . '</td><td>' . esc_html($e['meldeergebnis'] !== '' ? $e['meldeergebnis'] : '–') . '</td><td>' . ($e['mannschaft_nummer'] ? 'M' . (int) $e['mannschaft_nummer'] : '') . '</td><td class="r">' . esc_html($e['typ'] === 'mixteam' ? '–' : self::geld($e['startgeld'])) . '</td><td>' . esc_html(trim(($e['konflikt'] ? $e['konflikt_text'] . ' ' : '') . implode(' ', $e['hinweise']))) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ($z['mannschaften'] !== []) {
			echo '<p>';
			foreach ($z['mannschaften'] as $m) {
				echo '<strong>' . esc_html($m['kennzahl']) . ' M' . (int) $m['nummer'] . '</strong> (' . esc_html($m['klasse']) . '): ' . esc_html(implode(', ', array_map(static fn(array $x): string => $x['nachname'] . ', ' . $x['vorname'], $m['mitglieder']))) . ($m['vollstaendig'] ? '' : ' <span class="kmm-fail">' . esc_html__('unvollständig', 'ksv-km-meldeportal') . '</span>') . '<br>';
			}
			echo '</p>';
		}
		echo '<p><a href="' . esc_url(self::url(['sportjahr' => $sid])) . '">' . esc_html__('Details schließen', 'ksv-km-meldeportal') . '</a></p>';
	}

	private static function kachel(string $label, string $wert, string $art = ''): void {
		echo '<div class="kmm-kachel ' . esc_attr($art) . '"><div class="kmm-kachel-wert">' . esc_html($wert) . '</div><div class="kmm-kachel-label">' . esc_html($label) . '</div></div>';
	}

	private static function status_badge(string $status): string {
		$label = match ($status) {
			'entwurf' => __('Entwurf', 'ksv-km-meldeportal'),
			'eingereicht' => __('Eingereicht', 'ksv-km-meldeportal'),
			default => __('Offen', 'ksv-km-meldeportal'),
		};
		return '<span class="kmm-badge kmm-badge-' . esc_attr($status) . '">' . esc_html($label) . '</span>';
	}
}
