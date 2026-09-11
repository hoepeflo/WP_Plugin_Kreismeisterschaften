<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Unit\Domain;

use KSV\KMM\Domain\Geschlecht;
use KSV\KMM\Domain\Seed\Klassensatz;
use KSV\KMM\Domain\Tarifstufe;
use PHPUnit\Framework\TestCase;

final class KlassensatzTest extends TestCase {

	/** @var array<string, array<int, array<string, mixed>>> */
	private array $gruppen = [];

	protected function setUp(): void {
		foreach (Klassensatz::gruppen() as $g) {
			$this->gruppen[ $g['code'] ] = $g['klassen'];
		}
	}

	public function test_all_groups_from_concept_exist(): void {
		$this->assertSame(['freihand', 'auflage', 'fitasc', 'lichtschiessen', 'blasrohr', 'bogen', 'para'], array_keys($this->gruppen));
	}

	public function test_class_keys_are_unique_per_group_and_fields_valid(): void {
		foreach ($this->gruppen as $code => $klassen) {
			$keys = [];
			foreach ($klassen as $k) {
				$this->assertTrue(Geschlecht::is_valid($k['geschlecht']), "$code {$k['nummer']}");
				$this->assertTrue(Tarifstufe::is_valid($k['tarifstufe']), "$code {$k['nummer']}");
				$this->assertNotSame('', $k['bezeichnung']);
				$keys[] = $k['nummer'] . $k['geschlecht'];
			}
			$this->assertSame(array_unique($keys), $keys, "$code: doppelter Klassenschlüssel");
		}
	}

	/**
	 * Fälle aus dem Auftrag (Sportjahr 2027) und Konzept 4.1.
	 *
	 * @return array<string, array{string, int, string, int, string}>
	 */
	public static function altersfaelle(): array {
		return [
			'm 2007 Freihand → Junioren I 40'     => ['freihand', 2027 - 2007, 'm', 40, 'Junioren I m'],
			'm 1985 Freihand → Herren II 12'      => ['freihand', 2027 - 1985, 'm', 12, 'Herren II'],
			'm 2011 Freihand → Jugend 30'         => ['freihand', 2027 - 2011, 'm', 30, 'Jugend m'],
			'm 2010 Freihand → Junioren II 42'    => ['freihand', 2027 - 2010, 'm', 42, 'Junioren II m'],
			'm 1982 Auflage → Senioren 0 50'      => ['auflage', 2027 - 1982, 'm', 50, 'Senioren 0 m'],
			'w 2019 Lichtschießen → Schüler IV 27' => ['lichtschiessen', 2027 - 2019, 'w', 27, 'Schüler IV w'],
			'w 2014 Freihand → Schüler 21'        => ['freihand', 2027 - 2014, 'w', 21, 'Schüler w'],
			'm 1990 Blasrohr → Herren I 10'       => ['blasrohr', 2027 - 1990, 'm', 10, 'Herren I'],
			'm 2020 Blasrohr → Schüler III 24'    => ['blasrohr', 7, 'm', 24, 'Schüler III m'],
			'w 1950 Auflage → Senioren V 79'      => ['auflage', 77, 'w', 79, 'Senioren V w'],
			'w 1940 Auflage → Senioren VI 81'     => ['auflage', 87, 'w', 81, 'Senioren VI w'],
			'm 1955 Freihand → Herren V 18'       => ['freihand', 72, 'm', 18, 'Herren V'],
			'w 1970 Bogen → Master 13'            => ['bogen', 57, 'w', 13, 'Master w'],
			'm 2009 Bogen → Junioren 40'          => ['bogen', 18, 'm', 40, 'Junioren m'],
			'w 2010 Bogen → Jugend 31'            => ['bogen', 17, 'w', 31, 'Jugend w'],
			'm 2000 FITASC → Herren 60'           => ['fitasc', 27, 'm', 60, 'Herren'],
			'm 1960 FITASC → Veteranen 64'        => ['fitasc', 67, 'm', 64, 'Veteranen'],
			'w 2008 FITASC → Junioren 68'         => ['fitasc', 19, 'w', 68, 'Junioren'],
		];
	}

	/**
	 * @dataProvider altersfaelle
	 */
	public function test_class_by_age_and_gender(string $gruppe, int $alter, string $geschlecht, int $nummer, string $bezeichnung): void {
		$k = Klassensatz::finde_klasse($this->gruppen[ $gruppe ], $alter, $geschlecht);
		$this->assertNotNull($k, "$gruppe $alter $geschlecht");
		$this->assertSame($nummer, $k['nummer']);
		$this->assertSame($bezeichnung, $k['bezeichnung']);
	}

	public function test_fitasc_women_from_56_depends_on_preference(): void {
		$damen = Klassensatz::finde_klasse($this->gruppen['fitasc'], 60, 'w', true);
		$senioren = Klassensatz::finde_klasse($this->gruppen['fitasc'], 60, 'w', false);
		$this->assertSame(61, $damen['nummer']);
		$this->assertSame(62, $senioren['nummer']);
	}

	public function test_lichtschiessen_minimum_age_and_no_gaps(): void {
		$this->assertNull(Klassensatz::finde_klasse($this->gruppen['lichtschiessen'], 5, 'm'));
		$this->assertSame(26, Klassensatz::finde_klasse($this->gruppen['lichtschiessen'], 6, 'm')['nummer']);
		$this->assertSame(26, Klassensatz::finde_klasse($this->gruppen['lichtschiessen'], 8, 'm')['nummer']);
		$this->assertSame(24, Klassensatz::finde_klasse($this->gruppen['lichtschiessen'], 9, 'm')['nummer']);
		$this->assertNull(Klassensatz::finde_klasse($this->gruppen['lichtschiessen'], 15, 'm'));
	}

	public function test_every_age_maps_to_exactly_one_class_in_age_groups(): void {
		foreach (['freihand', 'blasrohr', 'bogen'] as $code) {
			for ($alter = 11; $alter <= 100; $alter++) {
				foreach (['m', 'w'] as $g) {
					$this->assertNotNull(Klassensatz::finde_klasse($this->gruppen[ $code ], $alter, $g), "$code $alter $g");
				}
			}
		}
		for ($alter = 41; $alter <= 100; $alter++) {
			$this->assertNotNull(Klassensatz::finde_klasse($this->gruppen['auflage'], $alter, 'm'), "auflage $alter");
		}
		$this->assertNull(Klassensatz::finde_klasse($this->gruppen['auflage'], 40, 'm'));
	}

	public function test_team_and_para_classes(): void {
		$teams = array_values(array_filter($this->gruppen['freihand'], static fn(array $k): bool => (bool) $k['ist_teamklasse']));
		$this->assertCount(2, $teams);
		$this->assertSame([40, 10], array_column($teams, 'nummer'));
		$this->assertSame(['x', 'x'], array_column($teams, 'geschlecht'));

		$para = $this->gruppen['para'];
		$this->assertSame([90, 92, 93, 94, 96], array_column($para, 'nummer'));
		foreach ($para as $k) {
			$this->assertTrue($k['ist_para']);
			$this->assertNull($k['alter_von']);
			$this->assertNull($k['alter_bis']);
			$this->assertSame(Tarifstufe::ERWACHSENE, $k['tarifstufe']);
		}
	}

	public function test_fixed_classes_cannot_be_overridden(): void {
		foreach ($this->gruppen as $code => $klassen) {
			foreach ($klassen as $k) {
				$is_young = str_starts_with($k['bezeichnung'], 'Schüler') || str_starts_with($k['bezeichnung'], 'Jugend');
				if ($code === 'fitasc') {
					continue;
				}
				$this->assertSame($is_young, (bool) $k['festgeschrieben'], "$code {$k['bezeichnung']}");
			}
		}
	}

	public function test_tarife_defaults(): void {
		$this->assertSame(['schueler' => 3.0, 'jugend' => 5.0, 'erwachsene' => 7.0], Klassensatz::tarife());
	}
}
