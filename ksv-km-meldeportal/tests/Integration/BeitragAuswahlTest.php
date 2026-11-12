<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Integration;

use KSV\KMM\Admin\BeitragAuswahl;

/**
 * Auswahl des Beitrags mit dem Startplan-Shortcode: Kalendertermine, Seiten, Beiträge.
 * Der Test bringt einen eigenen Termin-Typ mit und hängt nicht am Kalender-Plugin.
 */
final class BeitragAuswahlTest extends IntegrationTestCase {

	private const TYP = 'kmm_test_termin';
	private const META = '_kmm_test_beginn';

	/** @var list<int> */
	private array $posts = [];

	protected function setUp(): void {
		parent::setUp();
		register_post_type(self::TYP, ['public' => true, 'labels' => ['name' => 'Testtermine']]);
		add_filter('kmm_beitrag_post_types', [self::class, 'typen']);
	}

	protected function tearDown(): void {
		remove_filter('kmm_beitrag_post_types', [self::class, 'typen']);
		foreach ($this->posts as $id) {
			wp_delete_post($id, true);
		}
		unregister_post_type(self::TYP);
	}

	/**
	 * Nur der Test-Typ, Seiten und Beiträge – unabhängig davon, welche Plugins installiert sind.
	 *
	 * @return array<string, string|null>
	 */
	public static function typen(): array {
		return [self::TYP => self::META, 'page' => null, 'post' => null];
	}

	private function termin(string $titel, string $beginn, string $status = 'publish'): int {
		$id = (int) wp_insert_post(['post_type' => self::TYP, 'post_title' => $titel, 'post_status' => $status]);
		update_post_meta($id, self::META, $beginn);
		$this->posts[] = $id;
		return $id;
	}

	public function test_termine_am_wettkampftag_zuerst_und_alte_termine_fehlen(): void {
		$heute = time();
		$tag = gmdate('Y-m-d', $heute + 30 * DAY_IN_SECONDS);
		$am_tag = $this->termin('KM Luftpistole', $tag . ' 09:00:00');
		$auch = $this->termin('Königsschießen', $tag . ' 14:00:00', 'draft');
		$frueher = $this->termin('Vorstandssitzung', gmdate('Y-m-d', $heute + 5 * DAY_IN_SECONDS) . ' 19:00:00');
		$alt = $this->termin('Sommerfest', gmdate('Y-m-d', $heute - 200 * DAY_IN_SECONDS) . ' 10:00:00');
		$seite = (int) wp_insert_post(['post_type' => 'page', 'post_title' => 'Startpläne <b>KM</b>', 'post_status' => 'publish']);
		$this->posts[] = $seite;

		$g = BeitragAuswahl::gruppen($tag, null);
		$namen = array_keys($g);
		$this->assertSame('Testtermine am Wettkampftag', $namen[0]);
		$this->assertSame('Testtermine', $namen[1]);
		$datum = (new \DateTimeImmutable($tag))->format('d.m.Y');
		$this->assertSame([$am_tag => $datum . ' · KM Luftpistole', $auch => $datum . ' · Königsschießen – Entwurf'], $g['Testtermine am Wettkampftag']);
		$this->assertArrayHasKey($frueher, $g['Testtermine']);
		$this->assertArrayNotHasKey($alt, $g['Testtermine'], 'Termine von vor mehr als zwei Monaten stehen nicht zur Wahl');
		$seiten = $g[ get_post_type_object('page')->labels->name ];
		$this->assertSame('Startpläne KM', $seiten[ $seite ], 'Titel ohne HTML');

		// Ohne Datum (neuer Wettkampftag) gibt es keine eigene Gruppe für den Tag.
		$ohne = BeitragAuswahl::gruppen(null, null);
		$this->assertArrayNotHasKey('Testtermine am Wettkampftag', $ohne);
		$this->assertArrayHasKey($am_tag, $ohne['Testtermine']);

		// Eine früher eingetragene ID bleibt wählbar, auch wenn sie in keiner Liste steht …
		$mit = BeitragAuswahl::gruppen($tag, $alt);
		$this->assertSame('Bisher gewählt', array_key_first($mit));
		$this->assertSame([$alt => 'Sommerfest (ID ' . $alt . ')'], $mit['Bisher gewählt']);
		// … und steht nicht doppelt da, wenn sie ohnehin in einer Liste ist.
		$this->assertArrayNotHasKey('Bisher gewählt', BeitragAuswahl::gruppen($tag, $am_tag));
		// Gibt es den Beitrag nicht mehr, sagt die Auswahl das.
		$weg = BeitragAuswahl::gruppen($tag, 99999999);
		$this->assertSame([99999999 => 'nicht mehr vorhanden (ID 99999999)'], $weg['Bisher gewählt']);
	}
}
