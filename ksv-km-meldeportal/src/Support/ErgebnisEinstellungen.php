<?php
/**
 * Einstellungen des Ergebnis-Moduls (Option kmm_ergebnisse).
 *
 * Bewusst getrennt von kmm_settings: Ablageort, Seite und Adresse werden beim ersten
 * Aufruf aus dem bisherigen Ergebnis-Plugin (Option srd_km_settings) übernommen, damit
 * dieselben Dateien und dieselben Adressen weitergelten. Es gibt genau einen
 * Ablageort für alle Ergebnisse (Entscheidung vom 22.11.2026).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Support;

final class ErgebnisEinstellungen {

	public const OPTION = 'kmm_ergebnisse';

	/** Option des bisherigen Ergebnis-Plugins (nur gelesen). */
	public const SRD_OPTION = 'srd_km_settings';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		$srd = get_option(self::SRD_OPTION, []);
		$srd = is_array($srd) ? $srd : [];
		return [
			// Absoluter Pfad und öffentliche Adresse des Ergebnisordners (leer = uploads/results).
			'pfad'                => trim((string) ($srd['results_path'] ?? '')),
			'url'                 => trim((string) ($srd['results_url'] ?? '')),
			// Seite mit [srd_km] bzw. [kmm_ergebnisse] und Adresspräfix für Jahr- und Ergebnisadressen.
			'seite_id'            => (int) ($srd['page_id'] ?? 0),
			'slug'                => (string) ($srd['rewrite_slug'] ?? 'kreismeisterschaften'),
			// Schalter „Ergebnisanzeige übernehmen“: [srd_km] und die alten Adressen bedient das KM-Portal.
			'anzeige_uebernehmen' => false,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option(self::OPTION, []);
		return array_merge(self::defaults(), is_array($stored) ? $stored : []);
	}

	public static function get(string $key): mixed {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * @param array<string, mixed> $werte
	 */
	public static function speichern(array $werte): void {
		$neu = array_intersect_key($werte, self::defaults());
		if (isset($neu['slug'])) {
			$neu['slug'] = trim((string) preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $neu['slug'])), '-');
			if ($neu['slug'] === '') {
				$neu['slug'] = 'kreismeisterschaften';
			}
		}
		if (isset($neu['seite_id'])) {
			$neu['seite_id'] = max(0, (int) $neu['seite_id']);
		}
		if (isset($neu['anzeige_uebernehmen'])) {
			$neu['anzeige_uebernehmen'] = (bool) $neu['anzeige_uebernehmen'];
		}
		update_option(self::OPTION, array_merge(self::all(), $neu), false);
	}

	/** Absoluter Pfad des Ergebnisordners, ohne abschließenden Schrägstrich. */
	public static function pfad(): string {
		$pfad = (string) self::get('pfad');
		if ($pfad === '') {
			$up = wp_upload_dir(null, false);
			$pfad = trailingslashit((string) $up['basedir']) . 'results';
		}
		return untrailingslashit($pfad);
	}

	/** Öffentliche Adresse des Ergebnisordners, ohne abschließenden Schrägstrich. */
	public static function url(): string {
		$url = (string) self::get('url');
		if ($url === '') {
			$up = wp_upload_dir(null, false);
			$url = trailingslashit((string) $up['baseurl']) . 'results';
		}
		return untrailingslashit($url);
	}

	public static function uebernommen(): bool {
		return (bool) self::get('anzeige_uebernehmen');
	}

	/** Läuft das bisherige Ergebnis-Plugin noch? */
	public static function srd_aktiv(): bool {
		return function_exists('srd_km_bootstrap') || class_exists('SRD_KM_Frontend', false);
	}
}
