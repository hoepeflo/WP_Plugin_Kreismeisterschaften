<?php
/**
 * Austauschbarer Exporter: übersetzt neutrale Meldezeilen in ein Zielformat.
 * Nur Implementierungen dieses Interfaces kennen das Zielsystem (z. B. DAVID21).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain\Export;

interface ExporterInterface {

	/** Technischer Name (für Protokoll und Einstellungen), z. B. "david21". */
	public function name(): string;

	/**
	 * @param list<Meldezeile> $zeilen
	 * @return string Dateiinhalt (Bytes im Zielzeichensatz)
	 */
	public function exportieren(array $zeilen): string;

	public function content_type(): string;

	public function dateiendung(): string;
}
