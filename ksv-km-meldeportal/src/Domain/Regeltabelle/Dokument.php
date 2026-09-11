<?php
/**
 * Austauschformat der Regeltabelle (JSON), Version 1.
 *
 * Reines PHP: parst und validiert ein dekodiertes JSON-Array und liefert eine
 * normalisierte Struktur. Das Konvertierungsskript (tools/) erzeugt dieses Format,
 * der Backend-Import liest es, der Export schreibt es.
 *
 * Aufbau (alle Blöcke außer "disziplinen" optional):
 *   format, version, sportjahr, stand,
 *   gruppen[]  { code, bezeichnung, hoehermeldung_bereich, ist_para, sortierung, klassen[] }
 *   klassen[]  { nummer, geschlecht, bezeichnung, alter_von, alter_bis, tarifstufe, stufe,
 *                ist_para, ist_teamklasse, festgeschrieben, hinweis, sortierung }
 *   tarife     { schueler, jugend, erwachsene }
 *   disziplinen[] { kennzahl, bezeichnung, gruppe, typ, angeboten, mannschaft_groesse,
 *                ergebnis_format, tarif_override, mannschaft_startgeld, mixteam_kennzahl_modus,
 *                hinweis, sortierung, regeln[] }
 *   regeln[]   { klasse, einzel, einzel_ziel, mannschaft, mannschaft_ziel, mindestalter, hinweis }
 *
 * Klassenreferenzen: "10m" (Gruppe der Disziplin) oder "para:92m" (andere Gruppe).
 * Fehlende Regeln bedeuten "kein Startrecht".
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain\Regeltabelle;

use KSV\KMM\Domain\DisziplinTyp;
use KSV\KMM\Domain\ErgebnisFormat;
use KSV\KMM\Domain\Geschlecht;
use KSV\KMM\Domain\HoehermeldungBereich;
use KSV\KMM\Domain\KlassenRef;
use KSV\KMM\Domain\MixteamKennzahlModus;
use KSV\KMM\Domain\RegelModus;
use KSV\KMM\Domain\Tarifstufe;

final class Dokument {

	public const FORMAT  = 'kmm-regeltabelle';
	public const VERSION = 1;

	/**
	 * @param array<int, array<string, mixed>> $gruppen
	 * @param array<int, array<string, mixed>> $disziplinen
	 * @param array<string, float>             $tarife
	 */
	private function __construct(
		public readonly ?int $sportjahr,
		public readonly string $stand,
		public readonly array $gruppen,
		public readonly array $disziplinen,
		public readonly array $tarife,
	) {
	}

	/**
	 * @param array<string, mixed> $data Dekodiertes JSON.
	 * @throws DokumentFehler wenn das Dokument ungültig ist (alle Fehler gesammelt).
	 */
	public static function fromArray(array $data): self {
		$fehler = [];

		if (($data['format'] ?? null) !== self::FORMAT) {
			$fehler[] = sprintf('Feld "format" muss "%s" sein.', self::FORMAT);
		}
		if ((int) ($data['version'] ?? 0) !== self::VERSION) {
			$fehler[] = sprintf('Feld "version" muss %d sein.', self::VERSION);
		}
		$sportjahr = isset($data['sportjahr']) ? (int) $data['sportjahr'] : null;
		if ($sportjahr !== null && ($sportjahr < 2000 || $sportjahr > 2100)) {
			$fehler[] = 'Feld "sportjahr" ist unplausibel.';
		}
		$stand = isset($data['stand']) ? trim((string) $data['stand']) : '';

		$gruppen = [];
		$klassen_index = []; // key "gruppe:nummer+geschlecht" => true
		foreach (self::liste($data, 'gruppen', $fehler) as $i => $g) {
			$pfad = "gruppen[$i]";
			if (!is_array($g)) {
				$fehler[] = "$pfad: kein Objekt.";
				continue;
			}
			$code = self::code((string) ($g['code'] ?? ''));
			if ($code === '') {
				$fehler[] = "$pfad: \"code\" fehlt oder ist ungültig (a–z, 0–9, _).";
				continue;
			}
			$bereich = isset($g['hoehermeldung_bereich']) && $g['hoehermeldung_bereich'] !== '' ? (string) $g['hoehermeldung_bereich'] : null;
			if ($bereich !== null && !HoehermeldungBereich::is_valid($bereich)) {
				$fehler[] = "$pfad: ungültiger hoehermeldung_bereich \"$bereich\".";
			}
			$klassen = [];
			foreach (self::liste($g, 'klassen', $fehler, $pfad) as $j => $k) {
				$kp = "$pfad.klassen[$j]";
				if (!is_array($k)) {
					$fehler[] = "$kp: kein Objekt.";
					continue;
				}
				$nummer = (int) ($k['nummer'] ?? 0);
				$geschlecht = (string) ($k['geschlecht'] ?? '');
				if ($nummer <= 0 || $nummer > 999) {
					$fehler[] = "$kp: ungültige nummer.";
				}
				if (!Geschlecht::is_valid($geschlecht)) {
					$fehler[] = "$kp: ungültiges geschlecht \"$geschlecht\" (m/w/x).";
				}
				$tarif = (string) ($k['tarifstufe'] ?? Tarifstufe::ERWACHSENE);
				if (!Tarifstufe::is_valid($tarif)) {
					$fehler[] = "$kp: ungültige tarifstufe \"$tarif\".";
				}
				$key = KlassenRef::make($code, $nummer, $geschlecht)->key();
				if (isset($klassen_index[ $key ])) {
					$fehler[] = "$kp: Klasse $nummer$geschlecht in Gruppe $code doppelt.";
				}
				$klassen_index[ $key ] = true;
				$klassen[] = [
					'nummer'          => $nummer,
					'geschlecht'      => $geschlecht,
					'bezeichnung'     => trim((string) ($k['bezeichnung'] ?? '')),
					'alter_von'       => self::int_or_null($k['alter_von'] ?? null),
					'alter_bis'       => self::int_or_null($k['alter_bis'] ?? null),
					'tarifstufe'      => $tarif,
					'stufe'           => isset($k['stufe']) && $k['stufe'] !== '' ? (string) $k['stufe'] : null,
					'ist_para'        => (bool) ($k['ist_para'] ?? false),
					'ist_teamklasse'  => (bool) ($k['ist_teamklasse'] ?? false),
					'festgeschrieben' => (bool) ($k['festgeschrieben'] ?? false),
					'hinweis'         => trim((string) ($k['hinweis'] ?? '')),
					'sortierung'      => (int) ($k['sortierung'] ?? 0),
				];
			}
			$gruppen[] = [
				'code'                  => $code,
				'bezeichnung'           => trim((string) ($g['bezeichnung'] ?? $code)),
				'hoehermeldung_bereich' => $bereich,
				'ist_para'              => (bool) ($g['ist_para'] ?? false),
				'sortierung'            => (int) ($g['sortierung'] ?? 0),
				'klassen'               => $klassen,
			];
		}

		$tarife = [];
		if (isset($data['tarife'])) {
			if (!is_array($data['tarife'])) {
				$fehler[] = '"tarife" muss ein Objekt sein.';
			} else {
				foreach ($data['tarife'] as $stufe => $betrag) {
					if (!Tarifstufe::is_valid((string) $stufe)) {
						$fehler[] = "tarife: unbekannte Tarifstufe \"$stufe\".";
						continue;
					}
					if (!is_numeric($betrag) || (float) $betrag < 0) {
						$fehler[] = "tarife.$stufe: kein gültiger Betrag.";
						continue;
					}
					$tarife[ (string) $stufe ] = round((float) $betrag, 2);
				}
			}
		}

		$disziplinen = [];
		$kennzahlen = [];
		foreach (self::liste($data, 'disziplinen', $fehler) as $i => $d) {
			$pfad = "disziplinen[$i]";
			if (!is_array($d)) {
				$fehler[] = "$pfad: kein Objekt.";
				continue;
			}
			$kennzahl = trim((string) ($d['kennzahl'] ?? ''));
			if ($kennzahl === '' || !preg_match('/^\d{1,2}\.\d{2}(?:\s?[A-Za-z]{1,2})?$/', $kennzahl)) {
				$fehler[] = "$pfad: ungültige kennzahl \"$kennzahl\".";
			}
			if (isset($kennzahlen[ $kennzahl ])) {
				$fehler[] = "$pfad: kennzahl \"$kennzahl\" doppelt.";
			}
			$kennzahlen[ $kennzahl ] = true;
			$gruppe = self::code((string) ($d['gruppe'] ?? ''));
			if ($gruppe === '') {
				$fehler[] = "$pfad: \"gruppe\" fehlt.";
			}
			$typ = (string) ($d['typ'] ?? DisziplinTyp::NORMAL);
			if (!DisziplinTyp::is_valid($typ)) {
				$fehler[] = "$pfad: ungültiger typ \"$typ\".";
			}
			$format = (string) ($d['ergebnis_format'] ?? ErgebnisFormat::GANZ);
			if (!ErgebnisFormat::is_valid($format)) {
				$fehler[] = "$pfad: ungültiges ergebnis_format \"$format\".";
			}
			$mix = isset($d['mixteam_kennzahl_modus']) && $d['mixteam_kennzahl_modus'] !== '' ? (string) $d['mixteam_kennzahl_modus'] : null;
			if ($mix !== null && !MixteamKennzahlModus::is_valid($mix)) {
				$fehler[] = "$pfad: ungültiger mixteam_kennzahl_modus \"$mix\".";
			}
			if ($typ === DisziplinTyp::MIXTEAM && $mix === null) {
				$mix = MixteamKennzahlModus::TEAM;
			}
			$groesse = isset($d['mannschaft_groesse']) ? (int) $d['mannschaft_groesse'] : ($typ === DisziplinTyp::MIXTEAM ? 2 : ($typ === DisziplinTyp::BOGEN ? 0 : 3));

			$regeln = [];
			$regel_keys = [];
			foreach (self::liste($d, 'regeln', $fehler, $pfad) as $j => $r) {
				$rp = "$pfad.regeln[$j]";
				if (!is_array($r)) {
					$fehler[] = "$rp: kein Objekt.";
					continue;
				}
				try {
					$klasse = KlassenRef::parse((string) ($r['klasse'] ?? ''), $gruppe);
				} catch (\InvalidArgumentException $e) {
					$fehler[] = "$rp: " . $e->getMessage();
					continue;
				}
				if (isset($regel_keys[ $klasse->key() ])) {
					$fehler[] = "$rp: Regel für Klasse {$klasse->toString($gruppe)} doppelt.";
				}
				$regel_keys[ $klasse->key() ] = true;

				$einzel = (string) ($r['einzel'] ?? RegelModus::KEINE);
				$mannschaft = (string) ($r['mannschaft'] ?? RegelModus::KEINE);
				if (!RegelModus::is_valid($einzel)) {
					$fehler[] = "$rp: ungültiger einzel-Modus \"$einzel\".";
				}
				if (!RegelModus::is_valid($mannschaft)) {
					$fehler[] = "$rp: ungültiger mannschaft-Modus \"$mannschaft\".";
				}
				$einzel_ziel = null;
				$mannschaft_ziel = null;
				try {
					if ($einzel === RegelModus::VERWEIS) {
						$einzel_ziel = KlassenRef::parse((string) ($r['einzel_ziel'] ?? ''), $gruppe);
					}
					if ($mannschaft === RegelModus::VERWEIS) {
						$mannschaft_ziel = KlassenRef::parse((string) ($r['mannschaft_ziel'] ?? ''), $gruppe);
					}
				} catch (\InvalidArgumentException $e) {
					$fehler[] = "$rp: " . $e->getMessage();
				}
				if ($gruppen !== []) {
					foreach ([$klasse, $einzel_ziel, $mannschaft_ziel] as $ref) {
						if ($ref !== null && !isset($klassen_index[ $ref->key() ])) {
							$fehler[] = "$rp: Klasse {$ref->toString($gruppe)} ist im Dokument nicht definiert.";
						}
					}
				}
				$regeln[] = [
					'klasse'          => $klasse,
					'einzel'          => $einzel,
					'einzel_ziel'     => $einzel_ziel,
					'mannschaft'      => $mannschaft,
					'mannschaft_ziel' => $mannschaft_ziel,
					'mindestalter'    => self::int_or_null($r['mindestalter'] ?? null),
					'hinweis'         => trim((string) ($r['hinweis'] ?? '')),
				];
			}

			$disziplinen[] = [
				'kennzahl'               => $kennzahl,
				'bezeichnung'            => trim((string) ($d['bezeichnung'] ?? '')),
				'gruppe'                 => $gruppe,
				'typ'                    => $typ,
				'angeboten'              => (bool) ($d['angeboten'] ?? true),
				'mannschaft_groesse'     => $groesse,
				'ergebnis_format'        => $format,
				'tarif_override'         => isset($d['tarif_override']) && $d['tarif_override'] !== '' && $d['tarif_override'] !== null ? round((float) $d['tarif_override'], 2) : null,
				'mannschaft_startgeld'   => round((float) ($d['mannschaft_startgeld'] ?? 0), 2),
				'mixteam_kennzahl_modus' => $mix,
				'hinweis'                => trim((string) ($d['hinweis'] ?? '')),
				'sortierung'             => (int) ($d['sortierung'] ?? 0),
				'regeln'                 => $regeln,
			];
		}

		if ($fehler !== []) {
			throw new DokumentFehler($fehler);
		}

		return new self($sportjahr, $stand, $gruppen, $disziplinen, $tarife);
	}

	/**
	 * @throws DokumentFehler
	 */
	public static function fromJson(string $json): self {
		$data = json_decode($json, true);
		if (!is_array($data)) {
			throw new DokumentFehler(['Kein gültiges JSON: ' . json_last_error_msg()]);
		}
		return self::fromArray($data);
	}

	/**
	 * Serialisierbare Form (Klassenreferenzen als Strings).
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$disziplinen = [];
		foreach ($this->disziplinen as $d) {
			$regeln = [];
			foreach ($d['regeln'] as $r) {
				$regeln[] = [
					'klasse'          => $r['klasse']->toString($d['gruppe']),
					'einzel'          => $r['einzel'],
					'einzel_ziel'     => $r['einzel_ziel']?->toString($d['gruppe']),
					'mannschaft'      => $r['mannschaft'],
					'mannschaft_ziel' => $r['mannschaft_ziel']?->toString($d['gruppe']),
					'mindestalter'    => $r['mindestalter'],
					'hinweis'         => $r['hinweis'],
				];
			}
			$d['regeln'] = $regeln;
			$disziplinen[] = $d;
		}
		return [
			'format'      => self::FORMAT,
			'version'     => self::VERSION,
			'sportjahr'   => $this->sportjahr,
			'stand'       => $this->stand,
			'gruppen'     => $this->gruppen,
			'tarife'      => $this->tarife,
			'disziplinen' => $disziplinen,
		];
	}

	public function toJson(): string {
		return (string) json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * @param array<string, mixed> $data
	 * @param string[]             $fehler
	 * @return array<int, mixed>
	 */
	private static function liste(array $data, string $key, array &$fehler, string $pfad = ''): array {
		if (!isset($data[ $key ])) {
			return [];
		}
		if (!is_array($data[ $key ])) {
			$fehler[] = ($pfad !== '' ? "$pfad." : '') . "$key muss eine Liste sein.";
			return [];
		}
		return array_values($data[ $key ]);
	}

	private static function code(string $value): string {
		$value = strtolower(trim($value));
		return preg_match('/^[a-z][a-z0-9_]*$/', $value) === 1 ? $value : '';
	}

	private static function int_or_null(mixed $value): ?int {
		if ($value === null || $value === '') {
			return null;
		}
		return (int) $value;
	}
}
