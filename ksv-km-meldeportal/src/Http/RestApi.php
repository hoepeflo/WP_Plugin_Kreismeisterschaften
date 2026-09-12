<?php
/**
 * REST-Endpunkte der Vereinsoberfläche (Namespace kmm/v1).
 *
 * Jeder Endpunkt hat einen permission_callback: Sitzungscookie des Vereins, für
 * schreibende Methoden zusätzlich das sitzungsgebundene CSRF-Token (Header X-KMM-Token).
 * Alle Daten werden serverseitig auf den Verein der Sitzung begrenzt.
 *
 * Admin-Modus (Header X-KMM-Verein + X-KMM-Sportjahr): WordPress-Benutzer mit dem Recht
 * „Meldungen bearbeiten“; die Anmeldung läuft über den WordPress-REST-Nonce (X-WP-Nonce),
 * der zugleich der CSRF-Schutz ist. Abmelden, Startgeld-Schalter und Nachmeldungs-
 * Freischaltung gibt es nur im Admin-Modus (der Service lehnt sie sonst ab).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Http;

use KSV\KMM\Application\BuchungService;
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
	 * Verein des aktuellen Aufrufs im Admin-Modus (vom permission_callback gesetzt).
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $admin_verein = null;

	/**
	 * @return true|\WP_Error
	 */
	public static function permission(\WP_REST_Request $request) {
		self::$admin_verein = null;
		$verein_header = (string) $request->get_header(AdminModus::HEADER_VEREIN);
		if ($verein_header !== '') {
			if (!AdminModus::erlaubt()) {
				return new \WP_Error('kmm_admin', 'Keine Berechtigung. Bitte im Backend anmelden (Recht „Meldungen bearbeiten“).', ['status' => is_user_logged_in() ? 403 : 401]);
			}
			$verein = AdminModus::verein((int) $verein_header, (int) $request->get_header(AdminModus::HEADER_SPORTJAHR));
			if ($verein === null) {
				return new \WP_Error('kmm_verein', 'Verein oder Sportjahr nicht gefunden.', ['status' => 404]);
			}
			self::$admin_verein = $verein;
			return true;
		}
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
		// Startplan / Buchung (sichtbar erst nach Freigabe eines Wettkampftags):
		$r('/startplan', 'GET', [self::class, 'startplan_tage']);
		$r('/startplan/(?P<id>\d+)', 'GET', [self::class, 'startplan_raster'], ['id' => $int]);
		$r('/startplan/(?P<id>\d+)/buchen', 'POST', [self::class, 'startplan_buchen'], ['id' => $int]);
		$r('/startplan/buchung/(?P<id>\d+)', 'DELETE', [self::class, 'startplan_freigeben'], ['id' => $int]);
		// Nur Admin-Modus (Backend, nach Meldeschluss):
		$r('/meldung/einzel/(?P<id>\d+)/abmelden', 'POST', [self::class, 'abmelden'], ['id' => $int]);
		$r('/meldung/einzel/(?P<id>\d+)/abmeldung-aufheben', 'POST', [self::class, 'abmeldung_aufheben'], ['id' => $int]);
		$r('/meldung/einzel/(?P<id>\d+)/startgeld', 'PUT', [self::class, 'startgeld'], ['id' => $int]);
		$r('/meldung/nachmeldung', 'PUT', [self::class, 'nachmeldung']);
	}

	// ----- Handler ---------------------------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	private static function verein(): array {
		if (self::$admin_verein !== null) {
			return self::$admin_verein;
		}
		$verein = Zugang::aktueller_verein();
		if ($verein === null) {
			throw new \RuntimeException('Nicht angemeldet.');
		}
		return $verein;
	}

	private static function meldung_service(): MeldungService {
		$v = self::verein();
		return new MeldungService($v, (int) $v['sportjahr_id'], self::$admin_verein !== null);
	}

	private static function buchung_service(): BuchungService {
		$v = self::verein();
		return new BuchungService($v, (int) $v['sportjahr_id'], self::$admin_verein !== null);
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

	// ----- Admin-Modus ---------------------------------------------------------------------

	public static function abmelden(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return self::run(static function () use ($request) {
			$p = $request->get_json_params();
			$p = is_array($p) ? $p : [];
			$schalter = array_key_exists('startgeld_berechnen', $p) && $p['startgeld_berechnen'] !== null && $p['startgeld_berechnen'] !== '' ? (bool) $p['startgeld_berechnen'] : null;
			$service = self::meldung_service();
			$em = $service->abmelden((int) $request->get_param('id'), (string) ($p['grund'] ?? ''), $schalter);
			return ['einzelmeldung' => $em, 'meldung' => $service->zusammenfassung()];
		});
	}

	public static function abmeldung_aufheben(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return self::run(static function () use ($request) {
			$service = self::meldung_service();
			$em = $service->abmeldung_aufheben((int) $request->get_param('id'));
			return ['einzelmeldung' => $em, 'meldung' => $service->zusammenfassung()];
		});
	}

	public static function startgeld(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return self::run(static function () use ($request) {
			$p = $request->get_json_params();
			$service = self::meldung_service();
			$em = $service->startgeld_schalter((int) $request->get_param('id'), (bool) (is_array($p) ? ($p['berechnen'] ?? false) : false));
			return ['einzelmeldung' => $em, 'meldung' => $service->zusammenfassung()];
		});
	}

	public static function nachmeldung(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return self::run(static function () use ($request) {
			$p = $request->get_json_params();
			$bis = trim((string) (is_array($p) ? ($p['bis'] ?? '') : ''));
			$utc = null;
			if ($bis !== '') {
				$utc = \KSV\KMM\Support\Clock::local_to_utc($bis);
				if ($utc === null) {
					throw new \InvalidArgumentException('Bitte einen gültigen Zeitpunkt angeben.');
				}
			}
			$service = self::meldung_service();
			$service->nachmeldung_freischalten($utc);
			return ['ok' => true, 'meldung' => $service->zusammenfassung()];
		});
	}

	// ----- Startplan / Buchung ----------------------------------------------------------------

	public static function startplan_tage(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return self::run(static fn() => self::buchung_service()->tage());
	}

	public static function startplan_raster(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return self::run(static fn() => self::buchung_service()->raster((int) $request->get_param('id')));
	}

	public static function startplan_buchen(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return self::run(static function () use ($request) {
			$p = $request->get_json_params();
			$p = is_array($p) ? $p : [];
			$service = self::buchung_service();
			$tag = (int) $request->get_param('id');
			$r = $service->buchen($tag, (int) ($p['durchgang_id'] ?? 0), (int) ($p['einheit_id'] ?? 0), (int) ($p['position'] ?? 0), (int) ($p['einzelmeldung_id'] ?? 0));
			return $r + ['raster' => $service->raster($tag)];
		});
	}

	public static function startplan_freigeben(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return self::run(static function () use ($request) {
			$service = self::buchung_service();
			$tag = (int) $request->get_param('tag');
			$service->freigeben((int) $request->get_param('id'));
			return ['ok' => true, 'raster' => $tag > 0 ? $service->raster($tag) : null];
		});
	}
}
