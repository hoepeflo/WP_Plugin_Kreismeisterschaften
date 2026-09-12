<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Integration;

use KSV\KMM\Application\ExportService;
use KSV\KMM\Application\MeldungService;
use KSV\KMM\Application\PdfMeldelisten;
use KSV\KMM\Application\RegeltabelleImporter;
use KSV\KMM\Application\SchuetzeService;
use KSV\KMM\Application\SportjahrService;
use KSV\KMM\Application\VereinService;
use KSV\KMM\Domain\Regeltabelle\Dokument;
use KSV\KMM\Infrastructure\RegelwerkLader;
use KSV\KMM\Infrastructure\Repository\DisziplinRepository;
use KSV\KMM\Infrastructure\Repository\ExportRepository;
use KSV\KMM\Infrastructure\Repository\SportjahrRepository;
use KSV\KMM\Infrastructure\Repository\VereinRepository;

final class ExportServiceTest extends IntegrationTestCase {

	public function test_david_und_pdf_listen(): void {
		$sid = (new SportjahrService())->anlegen(2027);
		(new SportjahrRepository())->update($sid, ['meldung_beginn' => '2026-01-01 00:00:00', 'meldeschluss' => '2099-01-10 22:59:00']);
		remove_all_actions('kmm_regeln_geaendert');
		(new RegeltabelleImporter())->importieren($sid, Dokument::fromJson((string) file_get_contents(dirname(__DIR__, 2) . '/docs/regeltabelle-2026.json')));
		// Bogen-Disziplin von Hand ergänzen.
		$bogen_gruppe = (new \KSV\KMM\Infrastructure\Repository\GruppeRepository())->by_code($sid, 'bogen');
		$bogen_id = (new DisziplinRepository())->insert(['sportjahr_id' => $sid, 'gruppe_id' => (int) $bogen_gruppe['id'], 'kennzahl' => '6.10', 'bezeichnung' => 'Recurve', 'typ' => 'bogen', 'mannschaft_groesse' => 0]);
		$k10 = (new \KSV\KMM\Infrastructure\Repository\KlasseRepository())->by_key((int) $bogen_gruppe['id'], 10, 'm');
		(new \KSV\KMM\Infrastructure\Repository\RegelRepository())->insert(['sportjahr_id' => $sid, 'disziplin_id' => $bogen_id, 'klasse_id' => (int) $k10['id'], 'einzel_modus' => 'eigen', 'updated_at' => '2026-01-01 00:00:00']);
		RegelwerkLader::cache_leeren();
		add_filter('pre_wp_mail', static fn() => true);
		\KSV\KMM\Support\Settings::update(['csv_trennzeichen' => ';', 'csv_zeichensatz' => 'windows-1252', 'csv_ganze_ringe_format' => 'ganz', 'csv_verband_modus' => 'vn_nummer', 'csv_kopfzeile' => true]);

		$anlegen = function (string $name, string $vn, bool $einreichen) use ($sid): void {
			$vid = (new VereinService())->speichern(0, $name, $vn, [strtolower($vn) . '@example.org'], true);
			$verein = (array) (new VereinRepository())->find($vid);
			$verein['sportjahr_id'] = $sid;
			$ss = new SchuetzeService($verein, $sid);
			$ms = new MeldungService($verein, $sid);
			$h1 = $ss->speichern(0, ['nachname' => 'Müller', 'vorname' => 'Hans', 'geburtsdatum' => '1985-06-15', 'geschlecht' => 'm', 'mitgliedsnummer' => $vn . '0001'])['id'];
			$h2 = $ss->speichern(0, ['nachname' => 'Ärger', 'vorname' => 'Ömer', 'geburtsdatum' => '1990-01-01', 'geschlecht' => 'm', 'mitgliedsnummer' => $vn . '0002'])['id'];
			$w1 = $ss->speichern(0, ['nachname' => 'Zeta', 'vorname' => 'Eva', 'geburtsdatum' => '2011-01-01', 'geschlecht' => 'w', 'mitgliedsnummer' => $vn . '0003'])['id'];
			$rw = RegelwerkLader::engine($sid)->regelwerk();
			$e1 = $ms->einzel_anlegen($h1, $rw->disziplin_nach_kennzahl('1.10')->id);
			$e2 = $ms->einzel_anlegen($h2, $rw->disziplin_nach_kennzahl('1.10')->id);
			$ms->einzel_aendern($e1['id'], ['meldeergebnis' => '389,4']);
			$ms->einzel_anlegen($h2, $rw->disziplin_nach_kennzahl('1.22')->id);
			$ms->einzel_aendern($ms->einzel_anlegen($h1, $rw->disziplin_nach_kennzahl('1.22')->id)['id'], ['meldeergebnis' => '375']);
			$ms->einzel_anlegen($w1, $rw->disziplin_nach_kennzahl('1.12')->id);
			$ms->einzel_anlegen($h1, $rw->disziplin_nach_kennzahl('6.10')->id);
			$ms->mannschaft_speichern(null, $rw->disziplin_nach_kennzahl('1.10')->id, [$e1['id'], $e2['id']]);
			$ms->ansprechpartner_speichern(['name' => 'SL', 'email' => 'sl@example.org']);
			if ($einreichen) {
				// Mannschaft vervollständigen
				$h3 = $ss->speichern(0, ['nachname' => 'Beta', 'vorname' => 'Bo', 'geburtsdatum' => '1980-01-01', 'geschlecht' => 'm', 'mitgliedsnummer' => $vn . '0004'])['id'];
				$e3 = $ms->einzel_anlegen($h3, $rw->disziplin_nach_kennzahl('1.10')->id);
				$m = $ms->zusammenfassung()['mannschaften'][0];
				$ms->mannschaft_speichern($m['id'], $rw->disziplin_nach_kennzahl('1.10')->id, [$e1['id'], $e2['id'], $e3['id']]);
				$ms->einreichen();
			}
		};
		$anlegen('SV Fertig', '11111', true);
		$anlegen('SV Entwurf', '22222', false);

		$service = new ExportService();
		$zeilen = $service->zeilen($sid, null, null, true, true);
		$this->assertSame(['SV Fertig'], array_values(array_unique(array_map(static fn($z) => $z->vn_name, $zeilen))));
		$kennzahlen = array_map(static fn($z) => $z->kennzahl, $zeilen);
		$this->assertContains('1.10.12', $kennzahlen, 'Herren II eigene Einzelklasse');
		$this->assertContains('1.22.10', $kennzahlen, 'Herren II startet in 1.22 bei Herren I');
		$this->assertContains('1.12.40', $kennzahlen, 'MixTeam Teamklasse');
		$this->assertNotContains('6.10.10', $kennzahlen, 'Bogen nicht im DAVID-Export');
		$this->assertSame(['1.10.10', '1.10.12', '1.10.12', '1.12.40', '1.22.10', '1.22.10'], $kennzahlen, 'Sortierung nach Kennzahl und Startklasse');
		$mueller = array_values(array_filter($zeilen, static fn($z) => $z->nachname === 'Müller' && $z->disziplin_kennzahl === '1.10'))[0];
		$this->assertSame(1, $mueller->mannschaft_nummer);
		$this->assertSame(389.4, $mueller->meldeergebnis);

		$alle = $service->zeilen($sid, null, null, false, false);
		$this->assertCount(13, $alle, 'mit Entwurf und Bogen: Fertig 7 (3×1.10, 2×1.22, 1.12, 6.10) + Entwurf 6');

		$csv = $service->david($sid, null, true);
		$text = iconv('Windows-1252', 'UTF-8', $csv['inhalt']);
		$this->assertStringContainsString("Kennzahl;Name;Vorname", $text);
		$this->assertStringContainsString("1.10.12;Müller;Hans;11111;11111;SV Fertig;389,4;15.06.1985;111110001;0;0;1", $text);
		$this->assertStringContainsString("1.22.10;Ärger;Ömer;11111;11111;SV Fertig;;01.01.1990;111110002;0;0;", $text);
		$this->assertSame(6, $csv['zeilen']);
		$this->assertSame('david21-km2027-alle.csv', $csv['dateiname']);

		$nur_lg = $service->david($sid, RegelwerkLader::engine($sid)->regelwerk()->disziplin_nach_kennzahl('1.10')->id, true);
		$this->assertSame(3, $nur_lg['zeilen']);
		$this->assertSame('david21-km2027-1.10.csv', $nur_lg['dateiname']);

		$exporte = (new ExportRepository())->by_sportjahr($sid);
		$this->assertCount(2, $exporte);
		$this->assertSame('david_csv', $exporte[0]['typ']);

		// PDF-Listen: Struktur nach Verein und nach Klasse, Bogen enthalten, Entwürfe optional.
		$listen = new PdfMeldelisten();
		$s = $listen->struktur($service->zeilen($sid, null, null, false, false), PdfMeldelisten::GRUPPIERUNG_KLASSE, $sid);
		$this->assertSame(['1.10', '1.12', '1.22', '6.10'], array_column($s['disziplinen'], 'kennzahl'));
		$lg = $s['disziplinen'][0];
		$this->assertSame(['Herren I', 'Herren II'], array_column($lg['gruppen'], 'titel'));
		$this->assertSame('Ärger', $lg['gruppen'][0]['zeilen'][0]->nachname, 'alphabetisch innerhalb der Klasse');
		$html = $listen->html($service->zeilen($sid, null, null, true, false), PdfMeldelisten::GRUPPIERUNG_VEREIN, $sid, 'Test');
		$this->assertStringContainsString('6.10 Recurve', $html);
		$this->assertStringContainsString('<pagebreak />', $html);
		$this->assertStringContainsString('M1', $html);
		$this->assertStringContainsString('Herren I <span class="klein">(Herren II)</span>', $html, 'Startklasse mit eigentlicher Klasse');
		if (\KSV\KMM\Application\Pdf::verfuegbar()) {
			$r = $listen->erzeugen($sid, 'gruppe', null, 'freihand', PdfMeldelisten::GRUPPIERUNG_VEREIN, true);
			$this->assertStringStartsWith('%PDF', $r['inhalt']);
			$this->assertSame('meldeliste-km2027-freihand-nach-verein.pdf', $r['dateiname']);
		}
		remove_all_filters('pre_wp_mail');
	}
}
