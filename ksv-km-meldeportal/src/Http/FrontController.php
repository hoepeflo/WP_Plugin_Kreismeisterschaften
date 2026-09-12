<?php
/**
 * Verteilt Aufrufe der Vereinsoberfläche auf die Seiten.
 *
 * Routen: start | zugang/<token> | link-anfordern | pdf | abmelden |
 * admin/<verein_id>[/pdf] (Admin-Modus, WordPress-Login und Recht „Meldungen bearbeiten“).
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
			case 'admin':
				$this->admin((int) ($segments[1] ?? 0), (string) ($segments[2] ?? ''));
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
			View::render('frontend/willkommen', ['title' => __('KM-Portal', 'ksv-km-meldeportal')]);
			return;
		}
		$this->app($verein, false);
	}

	/**
	 * Admin-Modus: Meldung eines Vereins im Backend bearbeiten (auch nach Meldeschluss).
	 * Kein Magic Link, sondern WordPress-Login mit Recht „Meldungen bearbeiten“.
	 */
	private function admin(int $verein_id, string $unterseite): void {
		if (!AdminModus::erlaubt()) {
			if (!is_user_logged_in()) {
				wp_safe_redirect(wp_login_url(Router::url('admin/' . $verein_id)));
				return;
			}
			status_header(403);
			View::render('frontend/fehler', ['title' => __('Keine Berechtigung', 'ksv-km-meldeportal'), 'text' => __('Für die Bearbeitung von Meldungen fehlt das Recht „Meldungen bearbeiten“.', 'ksv-km-meldeportal')]);
			return;
		}
		$sportjahr_id = isset($_GET['sportjahr']) ? (int) $_GET['sportjahr'] : 0;
		if ($sportjahr_id <= 0) {
			$aktiv = (new SportjahrRepository())->aktiv();
			$sportjahr_id = $aktiv !== null ? (int) $aktiv['id'] : 0;
		}
		$verein = AdminModus::verein($verein_id, $sportjahr_id);
		if ($verein === null) {
			status_header(404);
			View::render('frontend/fehler', ['title' => __('Verein nicht gefunden', 'ksv-km-meldeportal'), 'text' => __('Verein oder Sportjahr nicht gefunden.', 'ksv-km-meldeportal')]);
			return;
		}
		if ($unterseite === 'pdf') {
			$this->pdf_ausgeben($verein, true);
			return;
		}
		status_header(200);
		$this->app($verein, true);
	}

	/**
	 * @param array<string, mixed> $verein
	 */
	private function app(array $verein, bool $admin): void {
		$sportjahr_id = (int) $verein['sportjahr_id'];
		$sportjahr = (new SportjahrRepository())->find($sportjahr_id);
		$service = new MeldungService($verein, $sportjahr_id, $admin);
		$admin_pfad = 'admin/' . (int) $verein['id'];
		View::render('frontend/app', [
			'title'      => sprintf($admin ? __('Meldung %s (Admin)', 'ksv-km-meldeportal') : __('Meldung %s', 'ksv-km-meldeportal'), (string) $verein['name']),
			'verein'     => $verein,
			'sportjahr'  => $sportjahr,
			'state'      => $service->zusammenfassung(),
			'schuetzen'  => (new SchuetzeService($verein, $sportjahr_id))->liste(),
			'startplan'  => (new \KSV\KMM\Application\BuchungService($verein, $sportjahr_id, $admin))->tage(),
			'csrf'       => $admin ? '' : RestApi::csrf_token((int) $verein['sitzung_id']),
			'api'        => esc_url_raw(rest_url(RestApi::NAMESPACE . '/')),
			'pdf_url'    => $admin ? add_query_arg('sportjahr', $sportjahr_id, Router::url($admin_pfad . '/pdf')) : Router::url('pdf'),
			'abmelden'   => Router::url('abmelden'),
			'admin'      => $admin ? [
				'verein_id'    => (int) $verein['id'],
				'sportjahr_id' => $sportjahr_id,
				'nonce'        => wp_create_nonce('wp_rest'),
				'zurueck'      => admin_url('admin.php?page=kmm&sportjahr=' . $sportjahr_id),
				'benutzer'     => wp_get_current_user()->display_name,
			] : null,
		]);
	}

	private function pdf(): void {
		$verein = Zugang::aktueller_verein();
		if ($verein === null) {
			wp_safe_redirect(Router::url('link-anfordern'));
			return;
		}
		$this->pdf_ausgeben($verein, false);
	}

	/**
	 * @param array<string, mixed> $verein
	 */
	private function pdf_ausgeben(array $verein, bool $admin): void {
		$service = new MeldungService($verein, (int) $verein['sportjahr_id'], $admin);
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
