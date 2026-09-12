<?php
/**
 * REST-Endpunkte der Vereinsoberfläche (Namespace kmm/v1).
 *
 * Jeder Endpunkt hat einen permission_callback: Sitzungscookie des Vereins, für
 * schreibende Methoden zusätzlich das sitzungsgebundene CSRF-Token (Header X-KMM-Token).
 * Alle Daten werden serverseitig auf den Verein der Sitzung begrenzt.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Http;

use KSV\KMM\Application\MeldungService;
use KSV\KMM\Application\SchuetzeService;
use KSV\KMM\Application\Zugang;

final class RestApi {

	public const NAMESPACE = 'kmm/v1';

	public static function register(): void {
		add_action('rest_api_init', [self::class, 'routes']);
	}

	public static function csrf_token(int $sitzung_id): string {
		return hash_hmac('sha256', 'kmm-csrf-' . $sitzung_id, wp_salt('auth'));
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function permission(\WP_REST_Request $request) {
		$verein = Zugang::aktueller_verein();
		if ($verein === null) {
			return new \WP_Error('kmm_nicht_angemeldet', 'Nicht angemeldet. Bitte den Zugangslink erneut aufrufen.', ['status' => 401]);
		}
		if (!in_array($request->get_method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
			$token = (string) $request->get_header('X-KMM-Token');
			if ($token === '' || !hash_equals(self::csrf_token((int) $verein['sitzung_id']), $token)) {
				return new \WP_Error('kmm_csrf', 'Sicherheits-Token ungültig. Bitte die Seite neu laden.', ['status' => 403]);
			}
		}
		return true;
	}

	public static function routes(): void {
		$r = static function (string $route, string $method, callable $callback, array $args = []): void {
			register_rest_route(self::NAMESPACE, $route, [
				'methods'             => $method,
				'callback'            => $callback,
				'permission_callback' => [self::class, 'permission'],
				'args'                => $args,
			]);
		};
		$int = ['type' => 'integer', 'required' => true, 'minimum' => 1];

		$r('/status', 'GET', [self::class, 'status']);
		$r('/schuetzen', 'GET', [self::class, 'schuetzen_liste']);
		$r('/schuetzen', 'POST', [self::class, 'schuetze_speichern']);
		$r('/schuetzen/(?P<id>\d+)', 'PUT', [self::class, 'schuetze_speichern'], ['id' => $int]);
		$r('/schuetzen/(?P<id>\d+)', 'DELETE', [self::class, 'schuetze_loeschen'], ['id' => $int]);
		$r('/schuetzen/(?P<id>\d+)/angebot', 'GET', [self::class, 'angebot'], ['id' => $int]);
		$r('/meldung', 'GET', [self::class, 'meldung']);
		$r('/meldung/einzel', 'POST', [self::class, 'einzel_anlegen']);
		$r('/meldung/einzel/(?P<id>\d+)', 'PUT', [self::class, 'einzel_aendern'], ['id' => $int]);
		$r('/meldung/einzel/(?P<id>\d+)', 'DELETE', [self::class, 'einzel_loeschen'], ['id' => $int]);
		$r('/meldung/einzel/(?P<id>\d+)/bestaetigen', 'POST', [self::class, 'konflikt_bestaetigen'], ['id' => $int]);
		$r('/meldung/mannschaften/kandidaten', 'GET', [self::class, 'kandidaten']);
		$r('/meldung/mannschaften', 'POST', [self::class, 'mannschaft_speichern']);
		$r('/meldung/mannschaften/(?P<id>\d+)', 'PUT', [self::class, 'mannschaft_speichern'], ['id' => $int]);
		$r('/meldung/mannschaften/(?P<id>\d+)', 'DELETE', [self::class, 'mannschaft_loeschen'], ['id' => $int]);
		$r('/meldung/ansprechpartner', 'PUT', [self::class, 'ansprechpartner']);
		$r('/meldung/einreichen', 'POST', [self::class, 'einreichen']);
		$r('/meldung/oeffnen', 'POST', [self::class, 'oeffnen']);
	}

	// ----- Handler ---------------------------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	private static function verein(): array {
		$verein = Zugang::aktueller_verein();
		if ($verein === null) {
			throw new \RuntimeException('Nicht angemeldet.');
		}
		return $verein;
	}

	private static function meldung_service(): MeldungService {
		$v = self::verein();
		return new MeldungService($v, (int) $v['sportjahr_id']);
	}

	private static function schuetze_service(): SchuetzeService {
		$v = self::verein();
		return new SchuetzeService($v, (int) $v['sportjahr_id']);
	}

	/**
	 * Führt einen Handler aus und übersetzt Exceptions in Fehlerantworten.
	 */
	private static function run(callable $fn): \WP_REST_Response|\WP_Error {
		try {
			$data = $fn();
			return new \WP_REST_Response($data, 200);
		} catch (\InvalidArgumentException $e) {
			return new \WP_Error('kmm_eingabe', $e->getMessage(), ['status' => 422]);
		} catch (\RuntimeException $e) {
			return new \WP_Error('kmm_fehler', $e->getMessage(), ['status' => 409]);
		}
	}

	public static function status(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return self::run(static fn() => self::meldung_service()->zusammenfassung());
	}

	public static function schuetzen_liste(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return self::run(static fn() => self::schuetze_service()->liste());
	}

	public static function schuetze_speichern(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return self::run(static function () use ($request) {
			$id = (int) ($request->get_param('id') ?? 0);
			$daten = $request->get_json_params();
			$result = self::schuetze_service()->speichern($id, is_array($daten) ? $daten : []);
			return $result + ['schuetzen' => self::schuetze_service()->liste()];
		});
	}

	public static function schuetze_loeschen(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return self::run(static function () use ($request) {
			self::schuetze_service()->loeschen((int) $request->get_param('id'));
			return ['ok' => true, 'schuetzen' => self::schuetze_service()->liste()];
		});
	}

	public static function angebot(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return self::run(static fn() => self::meldung_service()->angebot((int) $request->get_param('id')));
	}

	public static function meldung(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return self::run(static fn() => self::meldung_service()->zusammenfassung());
	}

	public static function einzel_anlegen(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return self::run(static function () use ($request) {
			$p = $request->get_json_params();
			$para = isset($p['para_klasse_id']) && $p['para_klasse_id'] !== '' && $p['para_klasse_id'] !== null ? (int) $p['para_klasse_id'] : null;
			$service = self::meldung_service();
			$neu = $service->einzel_anlegen((int) ($p['schuetze_id'] ?? 0), (int) ($p['disziplin_id'] ?? 0), $para);
			return ['einzelmeldung' => $neu, 'meldung' => $service->zusammenfassung()];
		});
	}

	public static function einzel_aendern(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return self::run(static function () use ($request) {
			$p = $request->get_json_params();
			$service = self::meldung_service();
			$em = $service->einzel_aendern((int) $request->get_param('id'), is_array($p) ? $p : []);
			return ['einzelmeldung' => $em, 'meldung' => $service->zusammenfassung()];
		});
	}

	public static function einzel_loeschen(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return self::run(static function () use ($request) {
			$service = self::meldung_service();
			$service->einzel_loeschen((int) $request->get_param('id'));
			return ['ok' => true, 'meldung' => $service->zusammenfassung()];
		});
	}

	public static function konflikt_bestaetigen(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return self::run(static function () use ($request) {
			$service = self::meldung_service();
			$service->konflikt_bestaetigen((int) $request->get_param('id'));
			return ['ok' => true, 'meldung' => $service->zusammenfassung()];
		});
	}

	public static function kandidaten(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return self::run(static function () use ($request) {
			$mid = $request->get_param('mannschaft_id');
			return self::meldung_service()->kandidaten((int) $request->get_param('disziplin_id'), $mid !== null && $mid !== '' ? (int) $mid : null);
		});
	}

	public static function mannschaft_speichern(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return self::run(static function () use ($request) {
			$p = $request->get_json_params();
			$id = $request->get_param('id');
			$service = self::meldung_service();
			$ma = $service->mannschaft_speichern($id !== null ? (int) $id : null, (int) ($p['disziplin_id'] ?? 0), is_array($p['einzelmeldung_ids'] ?? null) ? $p['einzelmeldung_ids'] : []);
			return ['mannschaft' => $ma, 'meldung' => $service->zusammenfassung()];
		});
	}

	public static function mannschaft_loeschen(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return self::run(static function () use ($request) {
			$service = self::meldung_service();
			$service->mannschaft_loeschen((int) $request->get_param('id'));
			return ['ok' => true, 'meldung' => $service->zusammenfassung()];
		});
	}

	public static function ansprechpartner(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return self::run(static function () use ($request) {
			$p = $request->get_json_params();
			$service = self::meldung_service();
			$service->ansprechpartner_speichern(is_array($p) ? $p : []);
			return ['ok' => true, 'meldung' => $service->zusammenfassung()];
		});
	}

	public static function einreichen(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return self::run(static function () {
			$service = self::meldung_service();
			$service->einreichen();
			return ['ok' => true, 'meldung' => $service->zusammenfassung()];
		});
	}

	public static function oeffnen(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return self::run(static function () {
			$service = self::meldung_service();
			$service->wieder_oeffnen();
			return ['ok' => true, 'meldung' => $service->zusammenfassung()];
		});
	}
}
