<?php
/**
 * Vorschau beim Hochladen mehrerer Dateien: Welcher Platz wurde am Dateinamen erkannt,
 * und würde dabei ein vorhandenes Ergebnis ersetzt?
 *
 *   gruen  eindeutig erkannt, Platz noch frei
 *   gelb   erkannt, für den Platz gibt es schon ein Ergebnis (wird ersetzt)
 *   rot    nicht erkannt oder kein Platz im Plan – Platz von Hand wählen
 *
 * Zwei Dateien für denselben Platz: die zweite wird rot markiert, damit nichts
 * unbemerkt überschrieben wird. Reines PHP, unit-testbar.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain\Ergebnis;

final class UploadZuordnung {

	public const GRUEN = 'gruen';
	public const GELB  = 'gelb';
	public const ROT   = 'rot';

	/**
	 * @param list<string>        $dateinamen  Originalnamen in Upload-Reihenfolge
	 * @param array<string, bool> $vorhanden   Platz-ID (e:1_10_10) => es gibt schon ein Ergebnis
	 * @return list<array{datei: string, status: string, platz: ?Platz, hinweis: string}>
	 */
	public static function vorschau(array $dateinamen, Platzliste $plaetze, array $vorhanden = []): array {
		$out = [];
		$vergeben = [];
		foreach ($dateinamen as $name) {
			$info = Schluessel::aus_dateiname($name);
			if ($info === null) {
				$out[] = ['datei' => $name, 'status' => self::ROT, 'platz' => null, 'hinweis' => 'Name nicht erkannt – bitte Platz wählen.'];
				continue;
			}
			$platz = $plaetze->finde($info['art'], $info['schluessel']);
			if ($platz === null) {
				$out[] = ['datei' => $name, 'status' => self::ROT, 'platz' => null, 'hinweis' => sprintf('%s%s steht nicht im Disziplinplan – bitte Platz wählen.', strtoupper($info['art']), $info['schluessel'])];
				continue;
			}
			if (isset($vergeben[ $platz->id() ])) {
				$out[] = ['datei' => $name, 'status' => self::ROT, 'platz' => null, 'hinweis' => sprintf('Gleicher Platz wie „%s“ – bitte prüfen.', $vergeben[ $platz->id() ])];
				continue;
			}
			$vergeben[ $platz->id() ] = $name;
			if (!empty($vorhanden[ $platz->id() ])) {
				$out[] = ['datei' => $name, 'status' => self::GELB, 'platz' => $platz, 'hinweis' => 'Ersetzt das vorhandene Ergebnis.'];
				continue;
			}
			$out[] = ['datei' => $name, 'status' => self::GRUEN, 'platz' => $platz, 'hinweis' => ''];
		}
		return $out;
	}
}
