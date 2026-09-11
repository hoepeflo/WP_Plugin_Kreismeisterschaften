<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Unit\Domain;

use KSV\KMM\Domain\KlassenRef;
use KSV\KMM\Domain\Regeltabelle\Dokument;
use KSV\KMM\Domain\Regeltabelle\DokumentFehler;
use KSV\KMM\Domain\Seed\Klassensatz;
use PHPUnit\Framework\TestCase;

final class DokumentTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function minimal(): array {
		return [
			'format'      => 'kmm-regeltabelle',
			'version'     => 1,
			'sportjahr'   => 2026,
			'gruppen'     => Klassensatz::gruppen(),
			'tarife'      => Klassensatz::tarife(),
			'disziplinen' => [
				[
					'kennzahl'    => '1.10',
					'bezeichnung' => 'Luftgewehr',
					'gruppe'      => 'freihand',
					'regeln'      => [
						['klasse' => '10m', 'einzel' => 'eigen', 'mannschaft' => 'eigen'],
						['klasse' => '12m', 'einzel' => 'eigen', 'mannschaft' => 'verweis', 'mannschaft_ziel' => '10m'],
						['klasse' => '21w', 'einzel' => 'eigen', 'mannschaft' => 'verweis', 'mannschaft_ziel' => '20m'],
						['klasse' => 'para:92m', 'einzel' => 'eigen'],
					],
				],
				[
					'kennzahl' => '1.12',
					'bezeichnung' => 'LG MixTeam',
					'gruppe' => 'freihand',
					'typ' => 'mixteam',
					'regeln' => [
						['klasse' => '40x', 'mannschaft' => 'eigen'],
						['klasse' => '30m', 'mannschaft' => 'verweis', 'mannschaft_ziel' => '40x'],
					],
				],
			],
		];
	}

	public function test_parses_valid_document_and_round_trips(): void {
		$doc = Dokument::fromArray($this->minimal());
		$this->assertSame(2026, $doc->sportjahr);
		$this->assertCount(7, $doc->gruppen);
		$this->assertCount(2, $doc->disziplinen);

		$lg = $doc->disziplinen[0];
		$this->assertSame('normal', $lg['typ']);
		$this->assertSame(3, $lg['mannschaft_groesse']);
		$this->assertSame('ganz', $lg['ergebnis_format']);
		$this->assertInstanceOf(KlassenRef::class, $lg['regeln'][3]['klasse']);
		$this->assertSame('para', $lg['regeln'][3]['klasse']->gruppe);
		$this->assertSame('keine', $lg['regeln'][3]['mannschaft']);

		$mix = $doc->disziplinen[1];
		$this->assertSame(2, $mix['mannschaft_groesse']);
		$this->assertSame('team', $mix['mixteam_kennzahl_modus']);
		$this->assertSame('keine', $mix['regeln'][0]['einzel']);

		$again = Dokument::fromJson($doc->toJson());
		$this->assertSame($doc->toArray(), $again->toArray());
		$this->assertSame('para:92m', $doc->toArray()['disziplinen'][0]['regeln'][3]['klasse']);
		$this->assertSame('10m', $doc->toArray()['disziplinen'][0]['regeln'][1]['mannschaft_ziel']);
	}

	public function test_collects_all_errors(): void {
		$data = $this->minimal();
		$data['version'] = 2;
		$data['disziplinen'][0]['regeln'][] = ['klasse' => '99m', 'einzel' => 'eigen'];
		$data['disziplinen'][0]['regeln'][] = ['klasse' => 'b.10', 'einzel' => 'eigen'];
		$data['disziplinen'][0]['regeln'][] = ['klasse' => '14m', 'einzel' => 'verweis'];
		$data['disziplinen'][0]['regeln'][] = ['klasse' => '10m', 'einzel' => 'eigen'];
		$data['disziplinen'][1]['typ'] = 'unbekannt';
		$data['disziplinen'][] = ['kennzahl' => 'abc', 'gruppe' => 'freihand'];

		try {
			Dokument::fromArray($data);
			$this->fail('Erwartet DokumentFehler');
		} catch (DokumentFehler $e) {
			$text = implode("\n", $e->fehler);
			$this->assertStringContainsString('"version" muss 1 sein', $text);
			$this->assertStringContainsString('Klasse 99m ist im Dokument nicht definiert', $text);
			$this->assertStringContainsString('Ungültige Klassenreferenz "b.10"', $text);
			$this->assertStringContainsString('Ungültige Klassenreferenz ""', $text);
			$this->assertStringContainsString('Regel für Klasse 10m doppelt', $text);
			$this->assertStringContainsString('ungültiger typ "unbekannt"', $text);
			$this->assertStringContainsString('ungültige kennzahl "abc"', $text);
			$this->assertGreaterThanOrEqual(7, count($e->fehler));
		}
	}

	public function test_document_without_groups_accepts_any_class_reference(): void {
		$data = $this->minimal();
		unset($data['gruppen'], $data['tarife']);
		$doc = Dokument::fromArray($data);
		$this->assertSame([], $doc->gruppen);
		$this->assertSame([], $doc->tarife);
	}

	public function test_kennzahl_with_letters_allowed(): void {
		foreach (['1.56S', '1.58 O', '2.03 F', '12.10', '11.11'] as $kz) {
			$data = $this->minimal();
			$data['disziplinen'] = [['kennzahl' => $kz, 'gruppe' => 'freihand']];
			$this->assertSame($kz, Dokument::fromArray($data)->disziplinen[0]['kennzahl']);
		}
	}

	public function test_invalid_json(): void {
		$this->expectException(DokumentFehler::class);
		Dokument::fromJson('{nope');
	}
}
