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
use KSV\KMM\Application\Sammelmail;
use KSV\KMM\Auth\Rechte;
use KSV\KMM\Http\Router;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\EinzelmeldungRepository;
use KSV\KMM\Infrastructure\Repository\MagicLinkRepository;
use KSV\KMM\Infrastructure\Repository\MailLogRepository;
use KSV\KMM\Infrastructure\Repository\MannschaftRepository;
use KSV\KMM\Infrastructure\Repository\MeldungRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;
use KSV\KMM\Infrastructure\Repository\WettkampftagRepository;
use KSV\KMM\Domain\WettkampftagStatus;
use KSV\KMM\Support\Clock;
use KSV\KMM\Support\Settings;

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
			if (self::action() === 'sammelmail_senden') {
				$r = Sammelmail::senden($sid, true);
				$text = sprintf(__('Sammelmail an %1$d Vereine gesendet (%2$d Änderungen).', 'ksv-km-meldeportal'), $r['gesendet'], $r['aenderungen']);
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

		$vereine = (new VereinRepository())->all();
		$meldungen = (new MeldungRepository())->by_sportjahr($sid);
		$einzel = (new EinzelmeldungRepository())->by_sportjahr($sid);
		$mannschaften = (new MannschaftRepository())->where(['sportjahr_id' => $sid]);
		$links = (new MagicLinkRepository())->aktuelle_je_verein($sid);
		$mails = (new MailLogRepository())->letzte_je_verein($sid, Mailer::TYP_MAGIC_LINK);
		$rw = RegelwerkLader::laden($sid);
		$referent = !Rechte::ist_admin();
		if ($referent) {
			// Referenten: nur Meldungen aus dem eigenen Zuständigkeitsbereich zählen und zeigen.
			$erlaubt = [];
			foreach (Rechte::zustaendige($rw->disziplinen()) as $d) {
				$erlaubt[ $d->id ] = true;
			}
			$einzel = array_values(array_filter($einzel, static fn(array $e): bool => isset($erlaubt[ (int) $e['disziplin_id'] ])));
			$mannschaften = array_values(array_filter($mannschaften, static fn(array $m): bool => isset($erlaubt[ (int) $m['disziplin_id'] ])));
			echo '<p class="description">' . esc_html__('Als Referent sehen Sie nur Meldungen Ihres Zuständigkeitsbereichs; Vereinsstatus und Zugang sind für den Admin.', 'ksv-km-meldeportal') . '</p>';
		}

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

		$summe = ['offen' => 0, 'entwurf' => 0, 'eingereicht' => 0, 'verarbeitet' => 0, 'einzel' => 0, 'ohne_ergebnis' => 0, 'konflikte' => 0, 'mannschaften' => 0, 'startgeld' => 0.0];
		$zeilen = [];
		foreach ($vereine as $v) {
			$vid = (int) $v['id'];
			$m = $meldungen[ $vid ] ?? null;
			$status = \KSV\KMM\Application\VerarbeitungService::vereinsstatus($m !== null ? (string) $m['status'] : MeldungRepository::STATUS_OFFEN, array_values(array_filter($einzel, static fn(array $e): bool => (int) $e['verein_id'] === $vid)));
			$z = $je_verein[ $vid ] ?? ['einzel' => 0, 'ohne_ergebnis' => 0, 'konflikte' => 0, 'ohne_startrecht' => 0, 'mannschaften' => 0, 'unvollstaendig' => 0, 'startgeld' => 0.0];
			if ($v['ist_aktiv']) {
				$summe[ $status ] = ($summe[ $status ] ?? 0) + 1;
			}
			foreach (['einzel', 'ohne_ergebnis', 'konflikte', 'mannschaften', 'startgeld'] as $k) {
				$summe[ $k ] += $z[ $k ];
			}
			$zeilen[] = ['verein' => $v, 'meldung' => $m, 'status' => $status, 'zahlen' => $z, 'link' => $links[ $vid ] ?? null, 'mail' => $mails[ $vid ] ?? null];
		}

		$tage = (new WettkampftagRepository())->by_sportjahr($sid);
		$aktive = count(array_filter($vereine, static fn(array $v): bool => (bool) $v['ist_aktiv']));
		self::render_phasen($sportjahr, $tage, $summe['eingereicht'] + $summe['verarbeitet'], $summe['verarbeitet']);
		self::render_kennzahlen($summe, $aktive);
		echo '<div class="kmm-cockpit-raster">';
		self::render_naechstes($sportjahr, $summe, $zeilen, $referent);
		self::render_tage($tage, $sid);
		echo '</div>';

		$detail = isset($_GET['verein']) ? (int) $_GET['verein'] : 0;
		$darf_bearbeiten = Rechte::hat_recht(Rechte::RECHT_MELDUNGEN) && $sportjahr['abgeschlossen_am'] === null;

		echo '<h2 id="kmm-vereinsliste" class="kmm-abschnitt">' . esc_html__('Vereine', 'ksv-km-meldeportal') . '</h2>';
		echo '<table class="widefat striped kmm-uebersicht"><thead><tr><th>' . esc_html__('Verein', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Status', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Ansprechpartner', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Einzel', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Mannsch.', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Ohne Erg.', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Konflikte', 'ksv-km-meldeportal') . '</th><th class="r">' . esc_html__('Startgeld', 'ksv-km-meldeportal') . '</th><th>' . esc_html__('Zugang', 'ksv-km-meldeportal') . '</th><th></th></tr></thead><tbody>';
		foreach ($zeilen as $z) {
			$v = $z['verein'];
			$vid = (int) $v['id'];
			$n = $z['zahlen'];
			echo '<tr' . ($v['ist_aktiv'] ? '' : ' class="kmm-muted"') . '>';
			echo '<td><strong>' . esc_html((string) $v['name']) . '</strong><br><small>VN ' . esc_html((string) $v['vn_nummer']) . ($v['ist_aktiv'] ? '' : ' · ' . esc_html__('inaktiv', 'ksv-km-meldeportal')) . '</small></td>';
			echo '<td>' . self::status_badge($z['status']) . ($z['meldung'] !== null && $z['meldung']['eingereicht_am'] ? '<br><small>' . esc_html(Clock::format_local($z['meldung']['eingereicht_am'])) . '</small>' : '') . ($z['meldung'] !== null && $z['meldung']['nachmeldung_bis'] !== null && (string) $z['meldung']['nachmeldung_bis'] > Clock::now_utc() ? '<br><small class="kmm-warn">' . esc_html(sprintf(__('Nachmeldung bis %s', 'ksv-km-meldeportal'), Clock::format_local($z['meldung']['nachmeldung_bis']))) . '</small>' : '') . '</td>';
			echo '<td>' . ($z['meldung'] !== null && $z['meldung']['ansprechpartner_name'] !== '' ? esc_html((string) $z['meldung']['ansprechpartner_name']) . '<br><small><a href="mailto:' . esc_attr((string) $z['meldung']['ansprechpartner_email']) . '">' . esc_html((string) $z['meldung']['ansprechpartner_email']) . '</a> ' . esc_html((string) $z['meldung']['ansprechpartner_telefon']) . '</small>' : '–') . '</td>';
			echo '<td class="r">' . (int) $n['einzel'] . ($n['ohne_startrecht'] > 0 ? ' <span class="kmm-fail" title="' . esc_attr__('ohne Startrecht', 'ksv-km-meldeportal') . '">(' . (int) $n['ohne_startrecht'] . ')</span>' : '') . '</td>';
			echo '<td class="r">' . (int) $n['mannschaften'] . ($n['unvollstaendig'] > 0 ? ' <span class="kmm-fail" title="' . esc_attr__('unvollständig', 'ksv-km-meldeportal') . '">(' . (int) $n['unvollstaendig'] . ')</span>' : '') . '</td>';
			echo '<td class="r">' . ($n['ohne_ergebnis'] > 0 ? '<span class="kmm-warn">' . (int) $n['ohne_ergebnis'] . '</span>' : '0') . '</td>';
			echo '<td class="r">' . ($n['konflikte'] > 0 ? '<span class="kmm-fail">' . (int) $n['konflikte'] . '</span>' : '0') . '</td>';
			echo '<td class="r">' . esc_html(self::geld($n['startgeld'])) . '</td>';
			echo '<td><small>' . ($z['link'] !== null ? esc_html(sprintf(__('Link %s, %d× genutzt', 'ksv-km-meldeportal'), Clock::format_local($z['link']['erstellt_am'], 'd.m.'), (int) $z['link']['verwendungen'])) : esc_html__('kein Link', 'ksv-km-meldeportal')) . ($z['mail'] !== null && !$z['mail']['erfolgreich'] ? '<br><span class="kmm-fail">' . esc_html__('Mailfehler', 'ksv-km-meldeportal') . '</span>' : '') . '</small></td>';
			echo '<td class="kmm-nowrap">' . ($n['einzel'] > 0 ? '<a class="button button-small" href="' . esc_url(self::url(['sportjahr' => $sid, 'verein' => $vid])) . '">' . esc_html__('Details', 'ksv-km-meldeportal') . '</a> ' : '') . ($darf_bearbeiten ? '<a class="button button-small" href="' . esc_url(add_query_arg('sportjahr', $sid, Router::url('admin/' . $vid))) . '" title="' . esc_attr__('Meldung des Vereins im Admin-Modus bearbeiten (auch nach Meldeschluss: Nachmeldung, Abmeldung, Korrektur, Nachmeldungs-Freischaltung)', 'ksv-km-meldeportal') . '">' . esc_html__('Bearbeiten', 'ksv-km-meldeportal') . '</a>' : '') . '</td>';
			echo '</tr>';
			if ($detail === $vid) {
				echo '<tr class="kmm-detail"><td colspan="10">';
				self::render_detail($v, $sid);
				echo '</td></tr>';
			}
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__('Zahlen in Klammern: ohne Startrecht bzw. unvollständige Mannschaften. Konflikte entstehen durch Regeländerungen oder geänderte Schützendaten und müssen vom Verein bestätigt oder entfernt werden.', 'ksv-km-meldeportal') . ($darf_bearbeiten ? ' ' . esc_html__('„Bearbeiten“ öffnet die Meldung des Vereins in der Vereinsoberfläche im Admin-Modus; Änderungen nach Meldeschluss werden protokolliert und in die Sammelmail an den Verein aufgenommen.', 'ksv-km-meldeportal') : '') . '</p>';
		echo '</div>';
	}

	/** Phasen der Saison, in der Reihenfolge des Ablaufs. */
	public const PHASEN = ['vorbereitung', 'meldephase', 'verarbeitung', 'startplan', 'ergebnisse', 'abschluss'];

	/**
	 * Aktuelle Phase der Saison (Index in PHASEN).
	 *
	 * @param string                           $meldestatus Status aus Meldephase::pruefen()
	 * @param array<int, array<string, mixed>> $tage        Wettkampftage
	 * @param string                           $heute       lokales Datum Y-m-d
	 */
	public static function phase_index(string $meldestatus, bool $abgeschlossen, array $tage, string $heute): int {
		if ($abgeschlossen || $meldestatus === 'abgeschlossen') {
			return 5;
		}
		if ($meldestatus === 'vor_beginn') {
			return 0;
		}
		if ($meldestatus !== 'geschlossen') {
			return 1;
		}
		$freigegeben = false;
		foreach ($tage as $t) {
			if ((string) $t['datum'] !== '' && strcmp((string) $t['datum'], $heute) <= 0) {
				return 4;
			}
			if ((string) $t['status'] !== WettkampftagStatus::ENTWURF) {
				$freigegeben = true;
			}
		}
		return $freigegeben ? 3 : 2;
	}

	/**
	 * Phasenleiste: wo steht die Saison, was kommt als Nächstes.
	 *
	 * @param array<string, mixed>             $sportjahr
	 * @param array<int, array<string, mixed>> $tage
	 */
	private static function render_phasen(array $sportjahr, array $tage, int $eingereicht, int $verarbeitet): void {
		$sid = (int) $sportjahr['id'];
		$meldung = \KSV\KMM\Application\Meldephase::pruefen($sportjahr);
		$heute = Clock::format_local(Clock::now_utc(), 'Y-m-d');
		$aktuell = self::phase_index($meldung['status'], $sportjahr['abgeschlossen_am'] !== null, $tage, $heute);
		$erster_tag = $tage !== [] ? (string) array_values($tage)[0]['datum'] : '';
		$freigegeben = count(array_filter($tage, static fn(array $t): bool => (string) $t['status'] !== WettkampftagStatus::ENTWURF));

		$schluss = $sportjahr['meldeschluss'];
		$melde_info = __('Meldeschluss fehlt', 'ksv-km-meldeportal');
		if ($schluss !== null) {
			$melde_info = sprintf(__('bis %s', 'ksv-km-meldeportal'), Clock::format_local((string) $schluss, 'd.m.Y'));
			if ($aktuell === 1) {
				$rest = (int) ceil((strtotime((string) $schluss . ' UTC') - time()) / DAY_IN_SECONDS);
				$melde_info .= ' · ' . ($rest <= 1 ? __('endet heute', 'ksv-km-meldeportal') : sprintf(__('noch %d Tage', 'ksv-km-meldeportal'), $rest));
			} elseif ($aktuell > 1) {
				$melde_info = sprintf(__('beendet am %s', 'ksv-km-meldeportal'), Clock::format_local((string) $schluss, 'd.m.Y'));
			}
		}
		$phasen = [
			['Vorbereitung', $aktuell > 0 ? __('erledigt', 'ksv-km-meldeportal') : ($sportjahr['meldung_beginn'] !== null ? sprintf(__('Meldestart %s', 'ksv-km-meldeportal'), Clock::format_local((string) $sportjahr['meldung_beginn'], 'd.m.Y')) : __('Stammdaten und Termine', 'ksv-km-meldeportal')), Menu::url(StammdatenPage::SLUG, ['sportjahr' => $sid])],
			['Meldephase', $melde_info, Menu::url(SportjahrePage::SLUG, ['edit' => $sid])],
			['Verarbeitung', $aktuell >= 2 ? sprintf(__('%1$d von %2$d verarbeitet', 'ksv-km-meldeportal'), $verarbeitet, $eingereicht) : __('nach Meldeschluss', 'ksv-km-meldeportal'), Menu::url(VerarbeitungPage::SLUG, ['sportjahr' => $sid])],
			['Startplan', $tage === [] ? __('noch keine Wettkampftage', 'ksv-km-meldeportal') : ($freigegeben > 0 ? sprintf(_n('%1$d Wettkampftag, %2$d freigegeben', '%1$d Wettkampftage, %2$d freigegeben', count($tage), 'ksv-km-meldeportal'), count($tage), $freigegeben) : sprintf(_n('%d Wettkampftag', '%d Wettkampftage', count($tage), 'ksv-km-meldeportal'), count($tage))), Menu::url(WettkampftagePage::SLUG, ['sportjahr' => $sid])],
			['Ergebnisse', $erster_tag !== '' ? sprintf(__('ab %s', 'ksv-km-meldeportal'), date_i18n('d.m.Y', (int) strtotime($erster_tag))) : '–', ''],
			['Abschluss', $sportjahr['abgeschlossen_am'] !== null ? sprintf(__('am %s', 'ksv-km-meldeportal'), Clock::format_local((string) $sportjahr['abgeschlossen_am'], 'd.m.Y')) : '–', Menu::url(SportjahrePage::SLUG)],
		];
		echo '<ol class="kmm-phasen" aria-label="' . esc_attr__('Ablauf der Saison', 'ksv-km-meldeportal') . '">';
		foreach ($phasen as $i => [$titel, $info, $url]) {
			$klasse = $i < $aktuell || ($i === 5 && $aktuell === 5) ? 'is-fertig' : ($i === $aktuell ? 'is-aktiv' : '');
			$inhalt = '<span class="kmm-phase-nr" aria-hidden="true">' . ($klasse === 'is-fertig' ? '✓' : (string) ($i + 1)) . '</span><span class="kmm-phase-titel">' . esc_html(__($titel, 'ksv-km-meldeportal')) . '</span><span class="kmm-phase-info">' . esc_html($info) . '</span>'; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
			echo '<li class="' . esc_attr($klasse) . '"' . ($i === $aktuell ? ' aria-current="step"' : '') . '>';
			echo $url !== '' && current_user_can(\KSV\KMM\Auth\Capabilities::MANAGE) ? '<a href="' . esc_url($url) . '">' . $inhalt . '</a>' : '<span class="kmm-phase-box">' . $inhalt . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</li>';
		}
		echo '</ol>';
	}

	/**
	 * @param array<string, int|float> $summe
	 */
	private static function render_kennzahlen(array $summe, int $aktive): void {
		$fertig = (int) $summe['eingereicht'] + (int) $summe['verarbeitet'];
		$quote = $aktive > 0 ? (int) round(100 * $fertig / $aktive) : 0;
		echo '<div class="kmm-kacheln kmm-kennzahlen">';
		echo '<div class="kmm-kachel haupt"><div class="kmm-kachel-wert">' . (int) $fertig . ' <small>' . esc_html(sprintf(__('von %d', 'ksv-km-meldeportal'), $aktive)) . '</small></div><div class="kmm-kachel-label">' . esc_html__('Vereine eingereicht', 'ksv-km-meldeportal') . '</div>';
		echo '<div class="kmm-fortschritt" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $quote . '" aria-label="' . esc_attr__('Anteil eingereichter Vereine', 'ksv-km-meldeportal') . '"><span style="width:' . $quote . '%"></span></div></div>';
		self::kachel(__('Einzelmeldungen', 'ksv-km-meldeportal'), (string) $summe['einzel']);
		self::kachel(__('Mannschaften', 'ksv-km-meldeportal'), (string) $summe['mannschaften']);
		self::kachel(__('Startgeld (vorläufig)', 'ksv-km-meldeportal'), self::geld((float) $summe['startgeld']));
		echo '</div>';
	}

	/**
	 * „Als Nächstes“: offene Punkte mit der passenden Aktion daneben.
	 *
	 * @param array<string, mixed>             $sportjahr
	 * @param array<string, int|float>         $summe
	 * @param array<int, array<string, mixed>> $zeilen
	 */
	private static function render_naechstes(array $sportjahr, array $summe, array $zeilen, bool $referent): void {
		$sid = (int) $sportjahr['id'];
		$darf = current_user_can(\KSV\KMM\Auth\Capabilities::MANAGE);
		$meldephase = \KSV\KMM\Application\Meldephase::pruefen($sportjahr)['status'];
		$punkte = [];
		$liste = '#kmm-vereinsliste';

		$namen = static function (string $feld) use ($zeilen): string {
			$n = [];
			foreach ($zeilen as $z) {
				if ((int) ($z['zahlen'][ $feld ] ?? 0) > 0) {
					$n[] = (string) $z['verein']['name'];
				}
			}
			return count($n) > 3 ? implode(', ', array_slice($n, 0, 3)) . ' ' . sprintf(__('und %d weitere', 'ksv-km-meldeportal'), count($n) - 3) : implode(', ', $n);
		};

		if (!$referent) {
			$offen = (int) $summe['offen'] + (int) $summe['entwurf'];
			if ($offen > 0 && $meldephase !== 'abgeschlossen') {
				$anzahl = count(Erinnerung::empfaenger($sid));
				$aktion = '';
				if ($darf && $meldephase === 'offen') {
					$aktion = self::aktion_button('erinnerung_senden', $sid, __('Erinnerung senden', 'ksv-km-meldeportal'), sprintf(__('Erinnerung jetzt an %d Vereine senden?', 'ksv-km-meldeportal'), $anzahl), $anzahl === 0, true);
				}
				$erinnerung = $sportjahr['erinnerung_gesendet_am'] !== null
					? sprintf(__('Erinnerung gesendet am %s', 'ksv-km-meldeportal'), Clock::format_local((string) $sportjahr['erinnerung_gesendet_am']))
					: ($sportjahr['erinnerung_am'] !== null ? sprintf(__('automatische Erinnerung am %s', 'ksv-km-meldeportal'), Clock::format_local((string) $sportjahr['erinnerung_am'])) : __('keine automatische Erinnerung eingestellt', 'ksv-km-meldeportal'));
				if ($darf && $sportjahr['erinnerung_gesendet_am'] !== null) {
					$aktion .= self::aktion_button('erinnerung_zuruecksetzen', $sid, __('Versandvermerk zurücksetzen', 'ksv-km-meldeportal'), '', false, false);
				}
				$punkte[] = [
					'warn',
					sprintf(_n('%d Verein hat noch nicht eingereicht', '%d Vereine haben noch nicht eingereicht', $offen, 'ksv-km-meldeportal'), $offen),
					sprintf(__('%1$d im Entwurf, %2$d noch nicht angefangen', 'ksv-km-meldeportal'), (int) $summe['entwurf'], (int) $summe['offen']) . ' · ' . $erinnerung,
					$aktion,
				];
			}
		}
		if ((int) $summe['konflikte'] > 0) {
			$punkte[] = ['fail', sprintf(_n('%d Meldung mit Konflikt', '%d Meldungen mit Konflikt', (int) $summe['konflikte'], 'ksv-km-meldeportal'), (int) $summe['konflikte']), $namen('konflikte'), self::link_button($liste, __('Anzeigen', 'ksv-km-meldeportal'))];
		}
		if ((int) $summe['ohne_ergebnis'] > 0) {
			$punkte[] = ['warn', sprintf(_n('%d Meldung ohne Ergebnis', '%d Meldungen ohne Ergebnis', (int) $summe['ohne_ergebnis'], 'ksv-km-meldeportal'), (int) $summe['ohne_ergebnis']), $namen('ohne_ergebnis'), self::link_button($liste, __('Anzeigen', 'ksv-km-meldeportal'))];
		}
		if ($meldephase === 'geschlossen' && (int) $summe['eingereicht'] > 0 && !$referent) {
			$punkte[] = ['info', sprintf(_n('%d Meldung wartet auf Verarbeitung', '%d Meldungen warten auf Verarbeitung', (int) $summe['eingereicht'], 'ksv-km-meldeportal'), (int) $summe['eingereicht']), __('Prüfen und für den Startplan übernehmen', 'ksv-km-meldeportal'), self::link_button(Menu::url(VerarbeitungPage::SLUG, ['sportjahr' => $sid]), __('Zur Verarbeitung', 'ksv-km-meldeportal'), true)];
		}
		if (!$referent) {
			$offen_aend = (new \KSV\KMM\Infrastructure\Repository\AenderungRepository())->offen_je_sportjahr($sid);
			$anzahl_aend = array_sum(array_map('count', $offen_aend));
			$naechster = wp_next_scheduled(\KSV\KMM\Cron::DAILY_HOOK);
			$aktiv = (bool) Settings::get('sammelmail_aktiv');
			$text = $aktiv ? sprintf(__('Sammelmail täglich um %s Uhr', 'ksv-km-meldeportal'), (string) Settings::get('sammelmail_uhrzeit')) : __('Sammelmail ist abgeschaltet', 'ksv-km-meldeportal');
			$info = sprintf(__('%1$d Änderungen bei %2$d Vereinen', 'ksv-km-meldeportal'), $anzahl_aend, count($offen_aend));
			if ($aktiv && $naechster !== false) {
				$info .= ' · ' . sprintf(__('nächster Lauf %s', 'ksv-km-meldeportal'), Clock::format_local(gmdate(Clock::DB_FORMAT, (int) $naechster)));
			}
			$lauf = Sammelmail::letzter_lauf();
			if ($lauf !== null) {
				$info .= ' · ' . sprintf(__('letzter Lauf %s', 'ksv-km-meldeportal'), Clock::format_local($lauf['am']));
			}
			$aktion = $darf ? self::aktion_button('sammelmail_senden', $sid, __('Jetzt senden', 'ksv-km-meldeportal'), sprintf(__('Sammelmail jetzt an %d Vereine senden?', 'ksv-km-meldeportal'), count($offen_aend)), $anzahl_aend === 0, false) : '';
			$aktion .= $darf ? ' <a class="kmm-klein-link" href="' . esc_url(Menu::url(SettingsPage::SLUG)) . '">' . esc_html__('Einstellungen', 'ksv-km-meldeportal') . '</a>' : '';
			$punkte[] = [$anzahl_aend > 0 ? 'info' : 'ruhig', $text, $info, $aktion];
		}

		echo '<section class="kmm-karte kmm-naechstes"><h2>' . esc_html__('Als Nächstes', 'ksv-km-meldeportal') . '</h2>';
		$wichtig = array_filter($punkte, static fn(array $p): bool => $p[0] !== 'ruhig');
		if ($wichtig === []) {
			echo '<p class="kmm-alles-gut">' . esc_html__('Gerade ist nichts zu tun.', 'ksv-km-meldeportal') . '</p>';
		}
		echo '<ul>';
		foreach ($punkte as [$art, $titel, $info, $aktion]) {
			echo '<li class="kmm-punkt kmm-punkt-' . esc_attr($art) . '"><span class="kmm-punkt-text"><strong>' . esc_html($titel) . '</strong>' . ($info !== '' ? '<small>' . esc_html($info) . '</small>' : '') . '</span><span class="kmm-punkt-aktion">' . $aktion . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</ul></section>';
	}

	/**
	 * @param array<int, array<string, mixed>> $tage
	 */
	private static function render_tage(array $tage, int $sid): void {
		echo '<section class="kmm-karte kmm-tage"><h2>' . esc_html__('Wettkampftage', 'ksv-km-meldeportal') . '</h2>';
		if ($tage === []) {
			echo '<p class="description">' . esc_html__('Noch keine Wettkampftage angelegt.', 'ksv-km-meldeportal') . '</p>';
		} else {
			echo '<table class="kmm-tage-liste"><tbody>';
			foreach ($tage as $t) {
				$status = (string) $t['status'];
				$badge = $status === WettkampftagStatus::ENTWURF ? '' : ($status === WettkampftagStatus::VEROEFFENTLICHT ? ' kmm-badge-verarbeitet' : ' kmm-badge-eingereicht');
				echo '<tr><td class="kmm-nowrap"><strong>' . esc_html(date_i18n('D, d.m.', (int) strtotime((string) $t['datum']))) . '</strong></td><td>' . esc_html((string) $t['bezeichnung']) . ($t['ort'] ? '<br><small>' . esc_html((string) $t['ort']) . '</small>' : '') . '</td><td class="r"><span class="kmm-badge' . esc_attr($badge) . '">' . esc_html(WettkampftagStatus::label($status)) . '</span></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<p><a href="' . esc_url(Menu::url(WettkampftagePage::SLUG, ['sportjahr' => $sid])) . '">' . esc_html__('Wettkampftage verwalten', 'ksv-km-meldeportal') . ' →</a></p>';
		echo '</section>';
	}

	private static function aktion_button(string $aktion, int $sid, string $label, string $frage, bool $gesperrt, bool $primaer): string {
		ob_start();
		self::form_open($aktion, 'class="kmm-inline-form"' . ($frage !== '' ? ' data-kmm-confirm="' . esc_attr($frage) . '"' : ''));
		echo '<input type="hidden" name="sportjahr_id" value="' . (int) $sid . '">';
		echo '<button type="submit" class="button' . ($primaer ? ' button-primary' : '') . '"' . ($gesperrt ? ' disabled' : '') . '>' . esc_html($label) . '</button>';
		echo '</form>';
		return (string) ob_get_clean();
	}

	private static function link_button(string $url, string $label, bool $primaer = false): string {
		return '<a class="button' . ($primaer ? ' button-primary' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
	}

	/**
	 * @param array<string, mixed> $verein
	 */
	private static function render_detail(array $verein, int $sid): void {
		$verein['sportjahr_id'] = $sid;
		$z = (new MeldungService($verein, $sid, true))->zusammenfassung();
		$z['einzelmeldungen'] = array_values(array_filter($z['einzelmeldungen'], static fn(array $e): bool => $e['zustaendig']));
		$z['mannschaften'] = array_values(array_filter($z['mannschaften'], static fn(array $m): bool => $m['zustaendig']));
		echo '<h3>' . esc_html((string) $verein['name']) . ' – ' . esc_html(sprintf(__('%d Einzelmeldungen, %d Mannschaften', 'ksv-km-meldeportal'), count($z['einzelmeldungen']), count($z['mannschaften']))) . '</h3>';
		echo '<table class="widefat"><thead><tr><th>Kennzahl</th><th>Disziplin</th><th>Name</th><th>Klasse</th><th>Startklasse</th><th>Ergebnis</th><th>Mannsch.</th><th class="r">Startgeld</th><th>Hinweise</th></tr></thead><tbody>';
		foreach ($z['einzelmeldungen'] as $e) {
			$klasse = $e['konflikt'] ? ' class="kmm-zeile-konflikt"' : ($e['meldeergebnis'] === '' && $e['typ'] !== 'mixteam' ? ' class="kmm-zeile-warn"' : '');
			$vermerk = $e['abgemeldet_am'] !== null ? ' <small class="kmm-fail">' . esc_html(sprintf(__('abgemeldet %s%s, Startgeld %s', 'ksv-km-meldeportal'), $e['abgemeldet_am'], $e['abmeldegrund'] !== '' ? ' (' . $e['abmeldegrund'] . ')' : '', $e['startgeld_berechnen'] ? __('wird berechnet', 'ksv-km-meldeportal') : __('entfällt', 'ksv-km-meldeportal'))) . '</small>' : ($e['nachgemeldet'] ? ' <small>' . esc_html__('Nachmeldung', 'ksv-km-meldeportal') . '</small>' : '');
			echo '<tr' . $klasse . '><td>' . esc_html($e['kennzahl_voll']) . '</td><td>' . esc_html($e['disziplin']) . '</td><td>' . esc_html($e['nachname'] . ', ' . $e['vorname']) . ($e['para'] ? ' <small>(' . esc_html($e['para']) . ')</small>' : '') . $vermerk . '</td><td>' . esc_html($e['klasse']) . ($e['hoehermeldung'] ? ' <small>HM</small>' : '') . '</td><td>' . esc_html($e['startklasse']) . '</td><td>' . esc_html($e['meldeergebnis'] !== '' ? $e['meldeergebnis'] : '–') . '</td><td>' . ($e['mannschaft_nummer'] ? 'M' . (int) $e['mannschaft_nummer'] : '') . '</td><td class="r">' . esc_html($e['typ'] === 'mixteam' ? '–' : self::geld($e['startgeld'])) . '</td><td>' . esc_html(trim(($e['konflikt'] ? $e['konflikt_text'] . ' ' : '') . implode(' ', $e['hinweise']))) . '</td></tr>';
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
		// Nullen treten zurück, damit Zahlen mit Handlungsbedarf auffallen.
		if ($art === '' && ($wert === '0' || $wert === '0,00 €')) {
			$art = 'null';
		}
		echo '<div class="kmm-kachel ' . esc_attr($art) . '"><div class="kmm-kachel-wert">' . esc_html($wert) . '</div><div class="kmm-kachel-label">' . esc_html($label) . '</div></div>';
	}

	private static function status_badge(string $status): string {
		$label = match ($status) {
			'entwurf' => __('Entwurf', 'ksv-km-meldeportal'),
			'eingereicht' => __('Eingereicht', 'ksv-km-meldeportal'),
			'verarbeitet' => __('Verarbeitet', 'ksv-km-meldeportal'),
			default => __('Offen', 'ksv-km-meldeportal'),
		};
		return '<span class="kmm-badge kmm-badge-' . esc_attr($status) . '">' . esc_html($label) . '</span>';
	}
}
