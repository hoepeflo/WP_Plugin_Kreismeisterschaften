<?php
/**
 * App-Schnittstelle: öffentlicher Endpunkt GET ksv-app/v1/km für die App „KSV Fallingbostel“.
 *
 * Verbindlich ist der API-Vertrag (Repo APP_KSV-Fallingbostel, docs/API-VERTRAG.md,
 * Abschnitte 1 und 3.3). Nur öffentliche Daten, keine Personendaten; ETag über den
 * Body, Cache-Control public, max-age=300, bei passendem If-None-Match 304.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Http;

use KSV\KMM\Application\App\KmAbbild;
use KSV\KMM\Application\Meldephase;
use KSV\KMM\Infrastructure\Repository\SchiessstandRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\WettkampftagRepository;
use KSV\KMM\Support\Clock;

final class AppApi {

	public const NAMESPACE = 'ksv-app/v1';

	public static function register(): void {
		add_action('rest_api_init', [self::class, 'routes']);
	}

	public static function routes(): void {
		register_rest_route(self::NAMESPACE, '/km', [
			'methods'             => 'GET',
			'callback'            => [self::class, 'km'],
			'permission_callback' => '__return_true', // öffentlich (Vertrag 3.3)
		]);
	}

	/**
	 * @param \WP_REST_Request<array<string, mixed>> $request
	 */
	public static function km(\WP_REST_Request $request): \WP_REST_Response {
		$daten = self::daten();
		$etag = KmAbbild::etag($daten);
		$headers = [
			'ETag'          => $etag,
			'Cache-Control' => 'public, max-age=300',
		];
		if (KmAbbild::etag_passt((string) $request->get_header('if_none_match'), $etag)) {
			// 304 ohne Body: WordPress würde sonst „null“ ausgeben.
			add_filter('rest_pre_serve_request', [self::class, 'ohne_body'], 10, 2);
			$antwort = new \WP_REST_Response(null, 304);
			$antwort->set_headers($headers);
			return $antwort;
		}
		$antwort = new \WP_REST_Response($daten, 200);
		$antwort->set_headers($headers);
		return $antwort;
	}

	/**
	 * Unterdrückt die Ausgabe bei 304 (nur für diese Antwort).
	 *
	 * @param bool  $served
	 * @param mixed $result
	 */
	public static function ohne_body($served, $result): bool {
		if ($result instanceof \WP_HTTP_Response && $result->get_status() === 304) {
			return true;
		}
		return (bool) $served;
	}

	/**
	 * Inhalt der Antwort „Kreismeisterschaft“.
	 *
	 * @return array<string, mixed>
	 */
	public static function daten(): array {
		$sportjahr = (new SportjahrRepository())->aktiv();
		$tage = [];
		$staende = [];
		$urls = [];
		$phase = 'geschlossen';
		if ($sportjahr !== null) {
			$phase = Meldephase::pruefen($sportjahr, null, Clock::now_utc())['status'];
			$tage = array_values((new WettkampftagRepository())->by_sportjahr((int) $sportjahr['id']));
			$stand_repo = new SchiessstandRepository();
			foreach ($tage as $tag) {
				$sid = (int) ($tag['schiessstand_id'] ?? 0);
				if ($sid > 0 && !isset($staende[ $sid ])) {
					$stand = $stand_repo->find($sid);
					if ($stand !== null) {
						$staende[ $sid ] = $stand;
					}
				}
				$urls[ (int) $tag['id'] ] = self::beitrag_url($tag['beitrag_id'] ?? null);
			}
		}
		return KmAbbild::bauen(
			$sportjahr,
			$phase,
			$tage,
			$staende,
			$urls,
			['meldeportal' => Router::url(), 'startplaene' => self::startplaene_url()],
			wp_timezone()
		);
	}

	/** Link zum Beitrag mit dem Startplan (nur veröffentlichte, öffentliche Beiträge/Seiten). */
	private static function beitrag_url(mixed $beitrag_id): ?string {
		$id = (int) $beitrag_id;
		$post = $id > 0 ? get_post($id) : null;
		if (!$post instanceof \WP_Post || $post->post_status !== 'publish' || $post->post_password !== '') {
			return null;
		}
		$url = get_permalink($id);
		return is_string($url) && $url !== '' ? $url : null;
	}

	/**
	 * Seite mit allen veröffentlichten Startplänen: die erste veröffentlichte Seite, die den
	 * Shortcode [kmm_startplan] ohne tag-Attribut enthält. Über den Filter
	 * kmm_app_startplaene_url lässt sich eine andere Adresse setzen (null = keine).
	 */
	private static function startplaene_url(): ?string {
		global $wpdb;
		$url = null;
		$zeilen = $wpdb->get_results($wpdb->prepare(
			"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_password = '' AND post_content LIKE %s ORDER BY menu_order ASC, ID ASC LIMIT 20",
			'%' . $wpdb->esc_like('[' . Shortcode::TAG) . '%'
		), ARRAY_A);
		foreach (is_array($zeilen) ? $zeilen : [] as $zeile) {
			if (preg_match_all('/\[' . preg_quote(Shortcode::TAG, '/') . '(\s[^\]]*)?\]/', (string) $zeile['post_content'], $m) < 1) {
				continue;
			}
			foreach ($m[1] as $attribute) {
				if (preg_match('/\btag\s*=/', (string) $attribute) !== 1) {
					$link = get_permalink((int) $zeile['ID']);
					$url = is_string($link) && $link !== '' ? $link : null;
					break 2;
				}
			}
		}
		$url = apply_filters('kmm_app_startplaene_url', $url);
		return is_string($url) && $url !== '' ? $url : null;
	}
}
