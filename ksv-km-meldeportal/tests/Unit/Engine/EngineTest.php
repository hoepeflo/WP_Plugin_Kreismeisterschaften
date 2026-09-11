<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Unit\Engine;

use KSV\KMM\Domain\Engine\Bewertung;
use KSV\KMM\Domain\Engine\Engine;
use KSV\KMM\Domain\Engine\MannschaftPruefung;
use KSV\KMM\Domain\Engine\Regelwerk;
use KSV\KMM\Domain\Engine\RegelwerkFabrik;
use KSV\KMM\Domain\Engine\Schuetze;
use KSV\KMM\Domain\Regeltabelle\Dokument;
use KSV\KMM\Domain\Seed\Klassensatz;
use PHPUnit\Framework\TestCase;

/**
 * Regel-Engine gegen die konvertierte Regeltabelle 2026 (docs/regeltabelle-2026.json)
 * und den Klassensatz, Sportjahr 2027, Meldeschluss 10.01.2027.
 */
final class EngineTest extends TestCase {

	private static ?Regelwerk $rw = null;

	private Engine $engine;

	protected function setUp(): void {
		if (self::$rw === null) {
			$json = file_get_contents(dirname(__DIR__, 3) . '/docs/regeltabelle-2026.json');
			self::$rw = RegelwerkFabrik::aus_dokument(Klassensatz::gruppen(), Dokument::fromJson((string) $json), 2027, '2027-01-10');
		}
		$this->engine = new Engine(self::$rw);
	}

	private function bewerte(string $kennzahl, string $geschlecht, int $jahrgang, array $hoeher = [], ?int $para = null, string $datum = '-06-15'): Bewertung {
		$d = self::$rw->disziplin_nach_kennzahl($kennzahl);
		$this->assertNotNull($d, "Disziplin $kennzahl fehlt");
		$s = new Schuetze($jahrgang . $datum, $geschlecht, $hoeher, $para);
		return $this->engine->bewerte($d, $s);
	}

	/**
	 * Pflicht-Testfälle aus dem Auftrag (Sportjahr 2027).
	 *
	 * @return array<string, array{string, string, int, ?int, ?string, ?int, ?float}>
	 *   kennzahl, geschlecht, jahrgang, erwartete Klasse, erwartete Kennzahl, erwarteter Pool, erwartetes Startgeld
	 */
	public static function pflichtfaelle(): array {
		return [
			'm 2007 1.10 → Junioren I 40'            => ['1.10', 'm', 2007, 40, '1.10.40', 40, 5.0],
			'm 1985 1.10 → Herren II, Pool 10'       => ['1.10', 'm', 1985, 12, '1.10.12', 10, 7.0],
			'm 1985 1.22 → Einzel 1.22.10'           => ['1.22', 'm', 1985, 12, '1.22.10', 10, 7.0],
			'm 2011 1.42 → Jugend, 1.42.40, Pool 10, 5 €' => ['1.42', 'm', 2011, 30, '1.42.40', 10, 5.0],
			'm 2010 1.30 → Jun II, 1.30.10, Pool 10 (Kette), 7 €' => ['1.30', 'm', 2010, 42, '1.30.10', 10, 7.0],
			'm 1982 1.11 → Senioren 0 (50)'          => ['1.11', 'm', 1982, 50, '1.11.50', 50, 7.0],
			'w 2019 11.11 → Schüler IV 27'           => ['11.11', 'w', 2019, 27, '11.11.27', 24, 2.5],
			'w 2014 1.10 → Schüler 21, gemischt 20'  => ['1.10', 'w', 2014, 21, '1.10.21', 20, 3.0],
			'Blasrohr Herren I → 12.10.10'           => ['12.10', 'm', 1990, 10, '12.10.10', null, 7.0],
		];
	}

	/**
	 * @dataProvider pflichtfaelle
	 */
	public function test_pflichtfaelle(string $kennzahl, string $geschlecht, int $jahrgang, ?int $klasse, ?string $kz, ?int $pool, ?float $startgeld): void {
		$b = $this->bewerte($kennzahl, $geschlecht, $jahrgang);
		$this->assertTrue($b->startrecht, $b->grund . ' ' . implode(' | ', $b->hinweise));
		$this->assertSame($klasse, $b->klasse?->nummer, 'eigentliche Klasse');
		$this->assertSame($kz, $b->kennzahl, 'Kennzahl');
		$this->assertSame($pool, $b->mannschaft_klasse?->nummer, 'Mannschaftspool');
		$this->assertSame($startgeld, $b->startgeld, 'Startgeld');
	}

	public function test_w_2019_lichtgewehr_11_10_kein_startrecht(): void {
		$b = $this->bewerte('11.10', 'w', 2019);
		$this->assertFalse($b->startrecht);
		$this->assertSame(27, $b->klasse?->nummer);
		$this->assertStringContainsString('Kein Startrecht', $b->grund);
	}

	public function test_mixteam_lg_jugend_team_junioren(): void {
		$b = $this->bewerte('1.12', 'm', 2011);
		$this->assertTrue($b->startrecht);
		$this->assertNull($b->startklasse, 'MixTeam ohne Einzelwertung');
		$this->assertSame(40, $b->mannschaft_klasse?->nummer);
		$this->assertSame('x', $b->mannschaft_klasse?->geschlecht);
		$this->assertSame('Team Junioren', $b->mannschaft_klasse?->bezeichnung);
		$this->assertSame('1.12.40', $b->kennzahl, 'Kennzahl mit Teamklasse (Modus team)');
		$this->assertSame(0.0, $b->startgeld, 'MixTeam nur über Mannschaftsstartgeld');
	}

	public function test_mixteam_skeet_jugend_team_damen_herren(): void {
		$b = $this->bewerte('3.22', 'm', 2011);
		$this->assertTrue($b->startrecht);
		$this->assertSame('Team Damen/Herren', $b->mannschaft_klasse?->bezeichnung);
		$this->assertSame('3.22.10', $b->kennzahl);
	}

	public function test_schueler_mixteam_kein_startrecht(): void {
		$b = $this->bewerte('1.12', 'm', 2014);
		$this->assertFalse($b->startrecht);
		$this->assertSame(20, $b->klasse?->nummer);
	}

	public function test_mixteam_kennzahl_modus_geschlecht(): void {
		$rw = self::$rw;
		$d = $rw->disziplin_nach_kennzahl('1.12');
		$dg = new \KSV\KMM\Domain\Engine\Disziplin($d->id, $d->kennzahl, $d->gruppe, $d->bezeichnung, $d->typ, true, 2, 'ganz', null, 0.0, 'geschlecht');
		$b = $this->engine->bewerte($dg, new Schuetze('2011-06-15', 'w'));
		$this->assertSame('1.12.31', $b->kennzahl);
	}

	public function test_hoehermeldung_junior_startet_bei_herren_i(): void {
		$b = $this->bewerte('1.10', 'm', 2007, ['uebrige' => 'hd1']);
		$this->assertTrue($b->hoehermeldung_angewendet);
		$this->assertSame(10, $b->klasse?->nummer);
		$this->assertSame('1.10.10', $b->kennzahl);
		$this->assertSame(7.0, $b->startgeld, 'Tarif der Startklasse');
		// Gilt auch für Blasrohr (gleiche Stufe hd1), nicht für Auflage (anderer Bereich).
		$this->assertSame('12.10.10', $this->bewerte('12.10', 'm', 2007, ['uebrige' => 'hd1'])->kennzahl);
		$this->assertSame(50, $this->bewerte('1.11', 'm', 1982, ['uebrige' => 'hd1'])->klasse?->nummer);
		$this->assertSame(70, $this->bewerte('1.11', 'm', 1965, ['auflage' => 'sen1'])->klasse?->nummer);
	}

	public function test_hoehermeldung_ignoriert_fuer_festgeschriebene_und_nicht_hoehere(): void {
		$b = $this->bewerte('1.10', 'm', 2011, ['uebrige' => 'hd1']);
		$this->assertFalse($b->hoehermeldung_angewendet, 'Jugend ist festgeschrieben');
		$this->assertSame(30, $b->klasse?->nummer);
		$b = $this->bewerte('1.10', 'm', 1990, ['uebrige' => 'hd3']);
		$this->assertFalse($b->hoehermeldung_angewendet, 'Herren III ist nicht höher als Herren I');
		$this->assertSame(10, $b->klasse?->nummer);
	}

	public function test_hoehermeldung_angebot(): void {
		$s = new Schuetze('1970-01-01', 'w'); // 57: Damen III
		$angebot = $this->engine->hoehermeldung_angebot('uebrige', $s);
		$this->assertSame(['hd1' => 'Damen I', 'hd2' => 'Damen II'], $angebot);
		$this->assertSame(['sen0' => 'Senioren 0 w'], $this->engine->hoehermeldung_angebot('auflage', $s));
		$this->assertSame([], $this->engine->hoehermeldung_angebot('uebrige', new Schuetze('2011-01-01', 'm')), 'Jugend festgeschrieben');
		$this->assertSame(['hd1' => 'Herren I'], $this->engine->hoehermeldung_angebot('uebrige', new Schuetze('2007-01-01', 'm')));
		$this->assertSame(['hd1' => 'Herren', 'bogen_master' => 'Master m'], $this->engine->hoehermeldung_angebot('bogen', new Schuetze('1955-01-01', 'm')));
	}

	public function test_mindestalter_vorderlader_junioren_ii(): void {
		// Junioren II (17–18): 2009 ist 18 im Sportjahr; Stichtag 10.01.2027.
		$vor = $this->bewerte('7.10', 'm', 2009, [], null, '-03-01');   // am 10.01.2027 erst 17
		$this->assertFalse($vor->startrecht);
		$this->assertStringContainsString('Mindestalter 18', $vor->grund);
		$nach = $this->bewerte('7.10', 'm', 2009, [], null, '-01-05');  // am 10.01.2027 schon 18
		$this->assertTrue($nach->startrecht);
		$this->assertSame('7.10.10', $nach->kennzahl);
		$this->assertContains('ab 18 Jahre', $nach->hinweise);
	}

	public function test_para_klasse_wird_gewaehlt(): void {
		$para = null;
		foreach (self::$rw->para_klassen() as $p) {
			if ($p->nummer === 92) {
				$para = $p;
			}
		}
		$this->assertNotNull($para);
		$b = $this->bewerte('1.10', 'm', 1985, [], $para->id);
		$this->assertTrue($b->startrecht);
		$this->assertSame(92, $b->klasse?->nummer);
		$this->assertSame('1.10.92', $b->kennzahl);
		$this->assertNull($b->mannschaft_klasse);
		$this->assertSame(7.0, $b->startgeld);

		$w = $this->bewerte('1.10', 'w', 1985, [], $para->id);
		$this->assertFalse($w->startrecht, 'Klasse 92 ist m');

		$angebot = $this->engine->para_angebot(self::$rw->disziplin_nach_kennzahl('1.10'), new Schuetze('1985-06-15', 'w'));
		$this->assertSame([90, 93, 94, 96], array_map(static fn($k) => $k->nummer, $angebot));
		$this->assertSame([], $this->engine->para_angebot(self::$rw->disziplin_nach_kennzahl('12.10'), new Schuetze('1985-06-15', 'w')));
	}

	public function test_fitasc_damen_ab_56_einstellung(): void {
		$json = file_get_contents(dirname(__DIR__, 3) . '/docs/regeltabelle-2026.json');
		$doc = Dokument::fromJson((string) $json);
		$damen = new Engine(RegelwerkFabrik::aus_dokument(Klassensatz::gruppen(), $doc, 2027, '2027-01-10', true));
		$senioren = new Engine(RegelwerkFabrik::aus_dokument(Klassensatz::gruppen(), $doc, 2027, '2027-01-10', false));
		$d = $damen->regelwerk()->disziplin_nach_kennzahl('3.30');
		$s = new Schuetze('1967-01-01', 'w'); // 60
		$this->assertSame(61, $damen->bewerte($d, $s)->klasse?->nummer);
		$this->assertSame(62, $senioren->bewerte($senioren->regelwerk()->disziplin_nach_kennzahl('3.30'), $s)->klasse?->nummer);
	}

	public function test_tarif_override_und_ignorieren(): void {
		$this->assertSame(2.5, $this->bewerte('11.10', 'm', 2015)->startgeld);
		$json = file_get_contents(dirname(__DIR__, 3) . '/docs/regeltabelle-2026.json');
		$rw = RegelwerkFabrik::aus_dokument(Klassensatz::gruppen(), Dokument::fromJson((string) $json), 2027, '2027-01-10', true, true);
		$b = (new Engine($rw))->bewerte($rw->disziplin_nach_kennzahl('11.10'), new Schuetze('2015-06-15', 'm'));
		$this->assertSame(3.0, $b->startgeld);
	}

	public function test_angebot_listet_nur_disziplinen_mit_startrecht(): void {
		$kennzahlen = array_map(static fn(Bewertung $b) => $b->disziplin->kennzahl, $this->engine->angebot(new Schuetze('2019-06-15', 'w')));
		// Lichtschießen: Schüler IV nur Auflage. Freihand-Schüler haben im Plan keine Untergrenze
		// („… - 14 nach gesetzl. Vorgaben"), daher erscheinen auch 1.10 usw.; eine Untergrenze kann
		// im Backend an der Klasse gesetzt werden.
		$this->assertContains('11.11', $kennzahlen);
		$this->assertContains('11.51', $kennzahlen);
		$this->assertNotContains('11.10', $kennzahlen);
		$this->assertNotContains('11.20', $kennzahlen);
		$kennzahlen = array_map(static fn(Bewertung $b) => $b->disziplin->kennzahl, $this->engine->angebot(new Schuetze('1985-06-15', 'm')));
		$this->assertContains('1.10', $kennzahlen);
		$this->assertContains('1.11', $kennzahlen);
		$this->assertContains('12.10', $kennzahlen);
		$this->assertNotContains('11.10', $kennzahlen);
		$this->assertNotContains('1.18', $kennzahlen, '1.18 nur Para');
	}

	public function test_kein_startrecht_ausserhalb_der_altersspanne(): void {
		$b = $this->bewerte('1.11', 'm', 1990); // 37: keine Auflage-Klasse
		$this->assertFalse($b->startrecht);
		$this->assertNull($b->klasse);
	}

	public function test_mannschaftspruefung(): void {
		$d = self::$rw->disziplin_nach_kennzahl('1.10');
		$h1 = ['bewertung' => $this->bewerte('1.10', 'm', 1990), 'geschlecht' => 'm'];
		$h2 = ['bewertung' => $this->bewerte('1.10', 'm', 1985), 'geschlecht' => 'm']; // Herren II → Pool 10
		$h3 = ['bewertung' => $this->bewerte('1.10', 'm', 1972), 'geschlecht' => 'm']; // Herren III → eigener Pool 14
		$d1 = ['bewertung' => $this->bewerte('1.10', 'w', 1990), 'geschlecht' => 'w'];
		$this->assertSame([], MannschaftPruefung::pruefe($d, [$h1, $h2, ['bewertung' => $this->bewerte('1.10', 'm', 1980), 'geschlecht' => 'm']]));
		$fehler = MannschaftPruefung::pruefe($d, [$h1, $h2, $h3]);
		$this->assertCount(1, $fehler);
		$this->assertStringContainsString('verschiedenen Mannschaftsklassen', $fehler[0]);
		$this->assertStringContainsString('unvollständig (2 von 3)', MannschaftPruefung::pruefe($d, [$h1, $h2])[0]);
		$this->assertSame([], MannschaftPruefung::pruefe($d, [$h1, $h2], false));
		$this->assertTrue(MannschaftPruefung::passt($d, $h2['bewertung'], 'm', [$h1]));
		$this->assertFalse(MannschaftPruefung::passt($d, $h3['bewertung'], 'm', [$h1]));
		$this->assertFalse(MannschaftPruefung::passt($d, $d1['bewertung'], 'w', [$h1]), 'Damen I eigener Pool 11');

		// Schüler gemischt: w verweist auf Pool 20.
		$s1 = ['bewertung' => $this->bewerte('1.10', 'm', 2014), 'geschlecht' => 'm'];
		$s2 = ['bewertung' => $this->bewerte('1.10', 'w', 2014), 'geschlecht' => 'w'];
		$this->assertTrue(MannschaftPruefung::passt($d, $s2['bewertung'], 'w', [$s1]));

		// MixTeam: genau 1 m + 1 w im selben Pool.
		$mix = self::$rw->disziplin_nach_kennzahl('1.12');
		$jm = ['bewertung' => $this->bewerte('1.12', 'm', 2011), 'geschlecht' => 'm'];
		$jw = ['bewertung' => $this->bewerte('1.12', 'w', 2009), 'geschlecht' => 'w'];
		$hw = ['bewertung' => $this->bewerte('1.12', 'w', 1990), 'geschlecht' => 'w'];
		$this->assertSame([], MannschaftPruefung::pruefe($mix, [$jm, $jw]));
		$this->assertContains('MixTeam: genau ein Mann und eine Frau.', MannschaftPruefung::pruefe($mix, [$jm, ['bewertung' => $this->bewerte('1.12', 'm', 2010), 'geschlecht' => 'm']]));
		$this->assertFalse(MannschaftPruefung::passt($mix, $hw['bewertung'], 'w', [$jm]), 'anderer Teamklassen-Pool');
		$this->assertFalse(MannschaftPruefung::passt($mix, $jm['bewertung'], 'm', [$jm]), 'zweiter Mann');
	}

	public function test_bogen_ohne_mannschaft(): void {
		$rw = RegelwerkFabrik::aus_dokument(Klassensatz::gruppen(), Dokument::fromArray([
			'format' => 'kmm-regeltabelle', 'version' => 1,
			'disziplinen' => [['kennzahl' => '6.10', 'bezeichnung' => 'Recurve', 'gruppe' => 'bogen', 'typ' => 'bogen', 'regeln' => [
				['klasse' => '10m', 'einzel' => 'eigen'], ['klasse' => '13w', 'einzel' => 'verweis', 'einzel_ziel' => '11w'], ['klasse' => '11w', 'einzel' => 'eigen'],
			]]],
		]), 2027, '2027-01-10');
		$e = new Engine($rw);
		$b = $e->bewerte($rw->disziplin_nach_kennzahl('6.10'), new Schuetze('1970-01-01', 'w')); // 57: Master w 13
		$this->assertTrue($b->startrecht);
		$this->assertSame('6.10.11', $b->kennzahl);
		$this->assertNull($b->mannschaft_klasse);
		$this->assertSame(7.0, $b->startgeld);
	}
}
