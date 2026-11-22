<?php
/**
 * Versionsangabe für CSS/JS des Plugins: Plugin-Version plus Änderungszeit der Datei.
 *
 * Ein Deploy ohne neue Versionsnummer ändert sonst die Adresse nicht, und Caches
 * (WP Fastest Cache mit Minify/Combine, Browser) liefern weiter die alte Datei aus.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Support;

final class Asset {

	/** @param string $relativ Pfad unterhalb des Plugin-Ordners, z. B. assets/frontend/app.css */
	public static function ver(string $relativ): string {
		$datei = KMM_PLUGIN_DIR . ltrim($relativ, '/');
		$zeit = is_file($datei) ? (int) filemtime($datei) : 0;
		return $zeit > 0 ? KMM_VERSION . '.' . $zeit : KMM_VERSION;
	}
}
