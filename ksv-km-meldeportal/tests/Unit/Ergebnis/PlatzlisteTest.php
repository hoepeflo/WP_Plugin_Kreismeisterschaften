<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Unit\Ergebnis;

use KSV\KMM\Domain\Engine\Regelwerk;
use KSV\KMM\Domain\Engine\RegelwerkFabrik;
use KSV\KMM\Domain\Ergebnis\Platzliste;
use KSV\KMM\Domain\Ergebnis\Schluessel;
use KSV\KMM\Domain\Ergebnis\UploadZuordnung;
use KSV\KMM\Domain\Regeltabelle\Dokument;
use KSV\KMM\Domain\Seed\Klassensatz;
use PHPUnit\Framework\TestCase;

/**
 * Ergebnisplätze aus dem Disziplinplan (Regeltabelle 2027, Entwurf aus docs/).
 */
final class PlatzlisteTest extends TestCase {

	private static ?Regelwerk $rw = null;

	private static function regelwerk(): Regelwerk {
		if (self::$rw === null) {
			$json = file_get_contents(dirname(__DIR__, 3) . '/docs/regeltabelle-2027-entwurf.json');
			self::$rw = RegelwerkFabrik::aus_dokument(Klassensatz::gruppen(), Dokument::fromJson((string) $json), 2027, '2027-01-10');
		}
		return self::$rw;
	}

	public function test_plaetze_aus_regelwerk(): void {
		$liste = Platzliste::aus_regelwerk(self::regelwerk());
		$this->assertGreaterThan(300, $liste->anzahl(Schluessel::EINZEL));
		$this->assertGreaterThan(80, $liste->anzahl(Schluessel::MANNSCHAFT));

		// 1.10 Herren I: eigene Einzel- und Mannschaftswertung.
		$p = $liste->finde('e', '1_10_10');
		$this->assertNotNull($p);
		$this->assertSame('1.10', $p->kennzahl);
		$this->assertSame('Herren I', $p->klasse_text);
		$this->assertSame('1.10 Luftgewehr · Herren I · Einzel', $p->beschriftung());
		$this->assertNotNull($liste->finde('m', '1_10_10'));

		// 1.10 Schüler w (21) hat in der Mannschaft einen Verweis auf 20 – kein eigener Mannschaftsplatz.
		$this->assertNotNull($liste->finde('e', '1_10_21'));
		$this->assertNull($liste->finde('m', '1_10_21'));
	}

	public function test_sammelplatz_je_disziplin(): void {
		$liste = Platzliste::aus_regelwerk(self::regelwerk());
		$sammel = $liste->finde('e', '1_10');
		$this->assertNotNull($sammel);
		$this->assertTrue($sammel->ist_sammel());
		$this->assertSame(Platzliste::ALLE_KLASSEN, $sammel->klasse_text);
		$this->assertNotNull($liste->finde('m', '1_10'));

		// Sammelergebnis steht innerhalb der Disziplin an erster Stelle.
		$gruppe = $liste->nach_disziplin()['1_10'];
		$this->assertTrue($gruppe[0]->ist_sammel());
	}

	public function test_schluessel_sind_eindeutig_und_ohne_warnung(): void {
		$liste = Platzliste::aus_regelwerk(self::regelwerk());
		$ids = array_map(static fn($p): string => $p->id(), $liste->alle());
		$this->assertSame(count($ids), count(array_unique($ids)));
		$this->assertSame([], $liste->warnungen());
	}

	public function test_nicht_angebotene_disziplin_fehlt(): void {
		$daten = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/docs/regeltabelle-2027-entwurf.json'), true);
		foreach ($daten['disziplinen'] as $i => $d) {
			if ($d['kennzahl'] === '1.10') {
				$daten['disziplinen'][ $i ]['angeboten'] = false;
			}
		}
		$rw = RegelwerkFabrik::aus_dokument(Klassensatz::gruppen(), Dokument::fromJson((string) json_encode($daten)), 2027, '2027-01-10');
		$liste = Platzliste::aus_regelwerk($rw);
		$this->assertNull($liste->finde('e', '1_10_10'));
		$this->assertNull($liste->finde('e', '1_10'));
		$this->assertNotNull($liste->finde('e', '1_11_50'));
	}

	public function test_katalog_fuer_archivjahre(): void {
		$liste = Platzliste::aus_katalog([
			['schluessel' => '1_10_10', 'spo' => '1.10.10', 'disziplin_text' => 'Luftgewehr', 'klasse_text' => 'Herren I'],
			['schluessel' => '1_10_11', 'spo' => '1.10.11', 'disziplin_text' => 'Luftgewehr', 'klasse_text' => 'Damen I'],
		]);
		$p = $liste->finde('m', '1_10_11');
		$this->assertNotNull($p);
		$this->assertSame('1.10', $p->kennzahl);
		$this->assertSame('Damen I', $p->klasse_text);
		$sammel = $liste->finde('e', '1_10');
		$this->assertNotNull($sammel);
		$this->assertSame('Luftgewehr', $sammel->disziplin_text);
		$this->assertSame(['1_10', '1_10_10', '1_10_11'], array_values(array_unique(array_map(static fn($p): string => $p->schluessel, $liste->alle()))));
	}

	public function test_upload_vorschau_ampel(): void {
		$liste = Platzliste::aus_regelwerk(self::regelwerk());
		$v = UploadZuordnung::vorschau(
			['E1-10-10.pdf', 'm1_10_10.pdf', 'Ergebnisliste.pdf', 'E1-10-10 (1).pdf', 'e1_10_10.pdf', 'e99_99_99.pdf'],
			$liste,
			['m:1_10_10' => true]
		);
		$this->assertSame(UploadZuordnung::GRUEN, $v[0]['status']);
		$this->assertSame(UploadZuordnung::GELB, $v[1]['status']);
		$this->assertSame(UploadZuordnung::ROT, $v[2]['status']);
		$this->assertSame(UploadZuordnung::ROT, $v[3]['status'], 'Kopie mit Zusatz im Namen wird nicht erkannt');
		$this->assertSame(UploadZuordnung::ROT, $v[4]['status'], 'zweite Datei für denselben Platz');
		$this->assertSame(UploadZuordnung::ROT, $v[5]['status']);
		$this->assertNotNull($v[0]['platz']);
		$this->assertSame('e:1_10_10', $v[0]['platz']->id());
	}
}
