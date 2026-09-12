<?php
/**
 * PDF der eigenen Vereinsmeldung (mPDF). Der HTML-Inhalt kommt aus
 * templates/pdf/meldung.php, damit er auch ohne PDF-Bibliothek testbar bleibt.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Http\View;

final class PdfMeldung {

	/**
	 * @param array<string, mixed> $zusammenfassung
	 */
	public function html(array $zusammenfassung): string {
		return View::capture('pdf/meldung', ['z' => $zusammenfassung]);
	}

	/**
	 * @param array<string, mixed> $zusammenfassung
	 * @throws \RuntimeException wenn mPDF fehlt.
	 */
	public function erzeugen(array $zusammenfassung): string {
		return Pdf::aus_html($this->html($zusammenfassung), 'A4');
	}
}
