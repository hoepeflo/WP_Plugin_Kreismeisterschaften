<?php
/**
 * Verschlüsselung der Sicherungen mit einer "Sealed Box" von libsodium (in PHP eingebaut).
 *
 * Auf dem Server liegt nur der öffentliche Schlüssel: Er kann verschlüsseln, aber nicht
 * entschlüsseln. Den privaten Schlüssel haben nur die verantwortlichen Personen offline
 * (docs/SICHERUNG.md). Eine gestohlene Sicherung gibt so keine Personendaten preis.
 *
 * Dateiformat: Kennung "KMMSIC1\n", danach die Sealed Box über die gzip-komprimierten
 * JSON-Daten.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application\Sicherung;

final class Verschluesselung {

	public const KENNUNG = "KMMSIC1\n";

	public const PRAEFIX_OEFFENTLICH = 'KMM-OEFFENTLICH-';
	public const PRAEFIX_PRIVAT      = 'KMM-PRIVAT-';

	/**
	 * Neues Schlüsselpaar.
	 *
	 * @return array{oeffentlich: string, privat: string}
	 */
	public static function schluesselpaar(): array {
		$paar = sodium_crypto_box_keypair();
		$ergebnis = [
			'oeffentlich' => self::PRAEFIX_OEFFENTLICH . base64_encode(sodium_crypto_box_publickey($paar)),
			'privat'      => self::PRAEFIX_PRIVAT . base64_encode(sodium_crypto_box_secretkey($paar)),
		];
		sodium_memzero($paar);
		return $ergebnis;
	}

	/** Prüft einen öffentlichen Schlüssel im Format KMM-OEFFENTLICH-…; leer bei Fehler. */
	public static function oeffentlich_roh(string $schluessel): string {
		return self::roh($schluessel, self::PRAEFIX_OEFFENTLICH);
	}

	public static function privat_roh(string $schluessel): string {
		return self::roh($schluessel, self::PRAEFIX_PRIVAT);
	}

	private static function roh(string $schluessel, string $praefix): string {
		$schluessel = preg_replace('/\s+/', '', $schluessel) ?? '';
		if (!str_starts_with($schluessel, $praefix)) {
			return '';
		}
		$roh = base64_decode(substr($schluessel, strlen($praefix)), true);
		return is_string($roh) && strlen($roh) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES ? $roh : '';
	}

	/** Klartext komprimieren und verschlüsseln. */
	public static function verschluesseln(string $klartext, string $oeffentlich): string {
		$schluessel = self::oeffentlich_roh($oeffentlich);
		if ($schluessel === '') {
			throw new \InvalidArgumentException('Ungültiger öffentlicher Schlüssel.');
		}
		$gz = gzencode($klartext, 9);
		if ($gz === false) {
			throw new \RuntimeException('Komprimieren fehlgeschlagen.');
		}
		return self::KENNUNG . sodium_crypto_box_seal($gz, $schluessel);
	}

	/** Entschlüsseln und entpacken; wirft bei falschem Schlüssel oder beschädigter Datei. */
	public static function entschluesseln(string $datei, string $privat): string {
		if (!self::ist_sicherung($datei)) {
			throw new \InvalidArgumentException('Das ist keine Sicherungsdatei des KM-Portals.');
		}
		$geheim = self::privat_roh($privat);
		if ($geheim === '') {
			throw new \InvalidArgumentException('Ungültiger privater Schlüssel (er beginnt mit KMM-PRIVAT-).');
		}
		$paar = sodium_crypto_box_keypair_from_secretkey_and_publickey($geheim, sodium_crypto_box_publickey_from_secretkey($geheim));
		$gz = sodium_crypto_box_seal_open(substr($datei, strlen(self::KENNUNG)), $paar);
		sodium_memzero($paar);
		if ($gz === false) {
			throw new \RuntimeException('Entschlüsseln fehlgeschlagen: falscher Schlüssel oder beschädigte Datei.');
		}
		$klartext = gzdecode($gz);
		if ($klartext === false) {
			throw new \RuntimeException('Entpacken fehlgeschlagen: Datei beschädigt.');
		}
		return $klartext;
	}

	public static function ist_sicherung(string $datei): bool {
		return str_starts_with($datei, self::KENNUNG);
	}
}
