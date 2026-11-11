<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Integration;

use KSV\KMM\Http\View;
use KSV\KMM\Support\Settings;

/**
 * Vereinsoberfläche als eigene Seite (Standard) oder im Rahmen der Website (Einstellung).
 */
final class DarstellungTest extends IntegrationTestCase {

	private const HOOKS = ['pre_get_document_title', 'wp_robots', 'body_class', 'wp_enqueue_scripts'];

	/** @var array<string, \WP_Hook|null> */
	private array $hooks = [];

	protected function setUp(): void {
		parent::setUp();
		delete_option(Settings::OPTION);
		// Die Darstellung im Rahmen hängt Filter ein; nach dem Test steht wieder der Stand von vorher.
		foreach (self::HOOKS as $hook) {
			$this->hooks[ $hook ] = isset($GLOBALS['wp_filter'][ $hook ]) ? clone $GLOBALS['wp_filter'][ $hook ] : null;
		}
		// wp_head() fragt auf der Kommandozeile nach dem Servernamen.
		$_SERVER['SERVER_NAME'] = 'example.org';
	}

	protected function tearDown(): void {
		delete_option(Settings::OPTION);
		foreach ($this->hooks as $hook => $stand) {
			if ($stand === null) {
				unset($GLOBALS['wp_filter'][ $hook ]);
			} else {
				$GLOBALS['wp_filter'][ $hook ] = $stand;
			}
		}
		unset($_SERVER['SERVER_NAME']);
	}

	private function seite(): string {
		ob_start();
		View::render('frontend/willkommen', ['title' => 'KM-Portal']);
		return (string) ob_get_clean();
	}

	public function test_standard_ist_die_eigene_seite(): void {
		$this->assertFalse((bool) Settings::get('portal_im_theme'), 'ohne Zutun bleibt alles wie bisher');
		$html = $this->seite();
		$this->assertStringContainsString('<body class="kmm">', $html);
		$this->assertStringContainsString('class="kmm-leiste"', $html);
		$this->assertStringContainsString('class="kmm-footer"', $html);
		$this->assertStringContainsString('noindex, nofollow', $html);
		$this->assertStringNotContainsString('kmm-im-theme', $html);
		$this->assertFalse(has_filter('pre_get_document_title'), 'die eigene Seite hängt sich nicht in das Theme');
	}

	public function test_im_rahmen_der_website_kommen_kopf_und_fuss_vom_theme(): void {
		Settings::update(['portal_im_theme' => true]);
		$html = $this->seite();
		$this->assertStringContainsString('class="kmm kmm-im-theme"', $html);
		$this->assertStringContainsString('Zugangslink anfordern', $html, 'der Inhalt ist derselbe');
		$this->assertStringNotContainsString('class="kmm-leiste"', $html, 'kein eigener Kopf');
		$this->assertStringNotContainsString('class="kmm-footer"', $html, 'kein eigener Fuß');
		// Titel und Suchmaschinen-Sperre laufen über die Filter des Themes.
		$this->assertStringStartsWith('KM-Portal – ', (string) apply_filters('pre_get_document_title', ''));
		$robots = (array) apply_filters('wp_robots', ['index' => true, 'follow' => true]);
		$this->assertTrue($robots['noindex'] ?? false);
		$this->assertTrue($robots['nofollow'] ?? false);
		$this->assertArrayNotHasKey('index', $robots);
	}
}
