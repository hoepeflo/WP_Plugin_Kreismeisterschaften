<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Unit\Sicherung;

use KSV\KMM\Application\Sicherung\Verschluesselung;
use PHPUnit\Framework\TestCase;

final class VerschluesselungTest extends TestCase {

	public function test_hin_und_zurueck(): void {
		$paar = Verschluesselung::schluesselpaar();
		$this->assertStringStartsWith('KMM-OEFFENTLICH-', $paar['oeffentlich']);
		$this->assertStringStartsWith('KMM-PRIVAT-', $paar['privat']);
		$klartext = (string) json_encode(['name' => 'Müller', 'zeilen' => range(1, 500)]);
		$datei = Verschluesselung::verschluesseln($klartext, $paar['oeffentlich']);
		$this->assertTrue(Verschluesselung::ist_sicherung($datei));
		$this->assertStringNotContainsString('Müller', $datei);
		$this->assertSame($klartext, Verschluesselung::entschluesseln($datei, $paar['privat']));
	}

	public function test_schluessel_mit_zeilenumbruch_aus_dem_ausdruck(): void {
		$paar = Verschluesselung::schluesselpaar();
		$datei = Verschluesselung::verschluesseln('x', $paar['oeffentlich']);
		$this->assertSame('x', Verschluesselung::entschluesseln($datei, ' ' . chunk_split($paar['privat'], 20, "\n")));
	}

	public function test_falscher_schluessel_scheitert(): void {
		$a = Verschluesselung::schluesselpaar();
		$b = Verschluesselung::schluesselpaar();
		$datei = Verschluesselung::verschluesseln('geheim', $a['oeffentlich']);
		$this->expectException(\RuntimeException::class);
		Verschluesselung::entschluesseln($datei, $b['privat']);
	}

	public function test_oeffentlicher_schluessel_entschluesselt_nicht(): void {
		$a = Verschluesselung::schluesselpaar();
		$datei = Verschluesselung::verschluesseln('geheim', $a['oeffentlich']);
		$this->expectException(\InvalidArgumentException::class);
		Verschluesselung::entschluesseln($datei, $a['oeffentlich']);
	}

	public function test_ungueltiger_oeffentlicher_schluessel(): void {
		$this->assertSame('', Verschluesselung::oeffentlich_roh('KMM-OEFFENTLICH-abc'));
		$this->expectException(\InvalidArgumentException::class);
		Verschluesselung::verschluesseln('x', 'irgendwas');
	}

	public function test_fremde_datei(): void {
		$a = Verschluesselung::schluesselpaar();
		$this->expectException(\InvalidArgumentException::class);
		Verschluesselung::entschluesseln('{"json":true}', $a['privat']);
	}
}
