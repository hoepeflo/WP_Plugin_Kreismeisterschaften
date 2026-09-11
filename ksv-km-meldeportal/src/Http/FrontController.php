<?php
/**
 * Verteilt Aufrufe der Vereinsoberfläche auf die Seiten.
 *
 * Meilenstein 1: Platzhalterseite mit dem Seitenrahmen. Die eigentlichen Seiten
 * (Link anfordern, Login per Magic Link, Meldung) folgen in den Meilensteinen 5 und 6.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Http;

final class FrontController {

	/**
	 * @param string[] $segments Pfadsegmente unterhalb der Route.
	 */
	public function handle(array $segments): void {
		$page = $segments[0] ?? 'start';

		switch ($page) {
			case 'start':
			default:
				status_header(200);
				View::render('frontend/placeholder', [
					'title' => __('KM-Meldeportal', 'ksv-km-meldeportal'),
				]);
				return;
		}
	}
}
