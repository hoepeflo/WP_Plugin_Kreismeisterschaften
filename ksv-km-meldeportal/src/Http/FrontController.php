<?php
/**
 * Verteilt Aufrufe der Vereinsoberfläche auf die Seiten.
 *
 * Routen: start | zugang/<token> | link-anfordern | abmelden
 * (Meldeseiten und REST folgen in Meilenstein 6.)
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Http;

use KSV\KMM\Application\LinkAnfordern;
use KSV\KMM\Application\MeldungService;
use KSV\KMM\Application\PdfMeldung;
use KSV\KMM\Application\SchuetzeService;
use KSV\KMM\Application\Zugang;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;

final class FrontController {

	/**
	 * @param string[] $segments Pfadsegmente unterhalb der Route.
	 */
	public function handle(array $segments): void {
		$page = $segments[0] ?? 'start';

		switch ($page) {
			case 'zugang':
				$this->zugang((string) ($segments[1] ?? ''));
				return;
			case 'link-anfordern':
				$this->link_anfordern();
				return;
			case 'pdf':
				$this->pdf();
				return;
			case 'abmelden':
				Zugang::abmelden();
				wp_safe_redirect(Router::url('link-anfordern'));
				return;
			case 'start':
			default:
				$this->start();
				return;
		}
	}

	private function zugang(string $token): void {
		$verein = Zugang::link_einloesen($token);
		if ($verein === null) {
			status_header(403);
			View::render('frontend/zugang-fehler', ['title' => __('Link ungültig', 'ksv-km-meldeportal')]);
			return;
		}
		// Token aus der URL entfernen: Weiterleitung auf die Startseite ohne Token.
		wp_safe_redirect(Router::url());
	}

	private function link_anfordern(): void {
		$gesendet = false;
		$limit = false;
		if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
			$nonce = isset($_POST['_kmm_nonce']) ? (string) wp_unslash($_POST['_kmm_nonce']) : '';
			if (wp_verify_nonce($nonce, 'kmm_link_anfordern') !== false) {
				$email = isset($_POST['email']) ? sanitize_email((string) wp_unslash($_POST['email'])) : '';
				$ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
				$limit = !LinkAnfordern::verarbeiten($email, $ip);
				$gesendet = !$limit;
			}
		}
		status_header(200);
		View::render('frontend/link-anfordern', [
			'title'    => __('Zugangslink anfordern', 'ksv-km-meldeportal'),
			'gesendet' => $gesendet,
			'limit'    => $limit,
			'nonce'    => wp_create_nonce('kmm_link_anfordern'),
		]);
	}

	private function start(): void {
		$verein = Zugang::aktueller_verein();
		status_header(200);
		if ($verein === null) {
			View::render('frontend/willkommen', ['title' => __('KM-Meldeportal', 'ksv-km-meldeportal')]);
			return;
		}
		$sportjahr = (new SportjahrRepository())->find((int) $verein['sportjahr_id']);
		$service = new MeldungService($verein, (int) $verein['sportjahr_id']);
		View::render('frontend/app', [
			'title'      => sprintf(__('Meldung %s', 'ksv-km-meldeportal'), (string) $verein['name']),
			'verein'     => $verein,
			'sportjahr'  => $sportjahr,
			'state'      => $service->zusammenfassung(),
			'schuetzen'  => (new SchuetzeService($verein, (int) $verein['sportjahr_id']))->liste(),
			'csrf'       => RestApi::csrf_token((int) $verein['sitzung_id']),
			'api'        => esc_url_raw(rest_url(RestApi::NAMESPACE . '/')),
			'pdf_url'    => Router::url('pdf'),
			'abmelden'   => Router::url('abmelden'),
		]);
	}

	private function pdf(): void {
		$verein = Zugang::aktueller_verein();
		if ($verein === null) {
			wp_safe_redirect(Router::url('link-anfordern'));
			return;
		}
		$service = new MeldungService($verein, (int) $verein['sportjahr_id']);
		try {
			$pdf = (new PdfMeldung())->erzeugen($service->zusammenfassung());
		} catch (\Throwable $e) {
			status_header(500);
			View::render('frontend/fehler', ['title' => __('PDF nicht verfügbar', 'ksv-km-meldeportal'), 'text' => $e->getMessage()]);
			return;
		}
		nocache_headers();
		header('Content-Type: application/pdf');
		header('Content-Disposition: attachment; filename="KM-Meldung-' . sanitize_file_name((string) $verein['vn_nummer']) . '.pdf"');
		header('Content-Length: ' . strlen($pdf));
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
