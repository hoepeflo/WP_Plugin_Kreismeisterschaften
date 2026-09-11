# Austauschformat der Regeltabelle (JSON)

Format `kmm-regeltabelle`, Version 1. Erzeugt vom Konvertierungsskript (`tools/`), gelesen
vom Backend-Import („KM-Meldeportal → Import / Export“), geschrieben vom Export. Der
Parser liegt in `KSV\KMM\Domain\Regeltabelle\Dokument` (reines PHP, unit-getestet).

```json
{
  "format": "kmm-regeltabelle",
  "version": 1,
  "sportjahr": 2026,
  "stand": "NSSV 01A1 vom 04.12.2025, konvertiert am 2026-09-20",
  "gruppen": [
    {
      "code": "freihand",
      "bezeichnung": "Gewehr, Pistole, Flinte, Vorderlader (Freihand)",
      "hoehermeldung_bereich": "uebrige",
      "ist_para": false,
      "sortierung": 10,
      "klassen": [
        { "nummer": 10, "geschlecht": "m", "bezeichnung": "Herren I", "alter_von": 21, "alter_bis": 40,
          "tarifstufe": "erwachsene", "stufe": "hd1", "ist_para": false, "ist_teamklasse": false,
          "festgeschrieben": false, "hinweis": "", "sortierung": 20 },
        { "nummer": 40, "geschlecht": "x", "bezeichnung": "Team Junioren", "alter_von": null, "alter_bis": null,
          "tarifstufe": "jugend", "stufe": null, "ist_teamklasse": true, "festgeschrieben": false }
      ]
    }
  ],
  "tarife": { "schueler": 3.0, "jugend": 5.0, "erwachsene": 7.0 },
  "disziplinen": [
    {
      "kennzahl": "1.10",
      "bezeichnung": "Luftgewehr",
      "gruppe": "freihand",
      "typ": "normal",
      "angeboten": true,
      "mannschaft_groesse": 3,
      "ergebnis_format": "ganz",
      "tarif_override": null,
      "mannschaft_startgeld": 0.0,
      "mixteam_kennzahl_modus": null,
      "hinweis": "",
      "sortierung": 10,
      "regeln": [
        { "klasse": "10m", "einzel": "eigen", "mannschaft": "eigen" },
        { "klasse": "12m", "einzel": "eigen", "mannschaft": "verweis", "mannschaft_ziel": "10m" },
        { "klasse": "21w", "einzel": "eigen", "mannschaft": "verweis", "mannschaft_ziel": "20m" },
        { "klasse": "42m", "einzel": "eigen", "mannschaft": "verweis", "mannschaft_ziel": "40m",
          "mindestalter": 18, "hinweis": "ab 18 Jahre" },
        { "klasse": "para:92m", "einzel": "eigen", "mannschaft": "keine" }
      ]
    },
    {
      "kennzahl": "1.12", "bezeichnung": "10m Luftgewehr MixTeam", "gruppe": "freihand", "typ": "mixteam",
      "regeln": [
        { "klasse": "40x", "mannschaft": "eigen" },
        { "klasse": "10x", "mannschaft": "eigen" },
        { "klasse": "30m", "mannschaft": "verweis", "mannschaft_ziel": "40x" },
        { "klasse": "12m", "mannschaft": "verweis", "mannschaft_ziel": "10x" }
      ]
    }
  ]
}
```

## Regeln

| Feld | Werte | Bedeutung |
|---|---|---|
| `klasse` | `10m`, `21w`, `40x`, `para:92m` | Nummer + Geschlecht; ohne Präfix gilt die Gruppe der Disziplin |
| `einzel`, `mannschaft` | `keine` (Standard), `eigen`, `verweis` | kein Startrecht / eigene Wertung / startet in Klasse |
| `einzel_ziel`, `mannschaft_ziel` | Klassenreferenz | Pflicht bei `verweis` |
| `mindestalter` | Zahl oder null | wird mit dem vollen Geburtsdatum geprüft |
| `hinweis` | Text | Anzeige, z. B. „bei Junioren“, „E **“ |

- Fehlende Regeln bedeuten „kein Startrecht“; Regeln mit `keine`/`keine` werden nicht gespeichert.
- Gemischte Schüler-/Jugendmannschaften: w-Klasse verweist im Mannschaftsteil auf die m-Klasse.
- MixTeams: nur `mannschaft`; Ziel ist eine Teamklasse (`40x` Team Junioren, `10x` Team Damen/Herren).
- Para: Regeln hängen an der normalen Disziplin (z. B. 1.10) mit Klassenreferenz `para:…`.

## Weitere Felder

| Feld | Werte |
|---|---|
| `typ` | `normal`, `mixteam`, `bogen` |
| `ergebnis_format` | `ganz`, `zehntel` |
| `mannschaft_groesse` | Standard 3; MixTeam 2; Bogen 0 |
| `tarif_override` | Betrag oder null (z. B. 2.5 für Lichtschießen) |
| `mixteam_kennzahl_modus` | `team`, `geschlecht` (nur MixTeam; Standard `team`) |
| `hoehermeldung_bereich` | `uebrige`, `auflage`, `bogen`, null (Para) |
| `tarifstufe` | `schueler`, `jugend`, `erwachsene` |
| `stufe` | frei, z. B. `schueler`, `jugend`, `jun2`, `jun1`, `hd1`–`hd5`, `sen0`–`sen6`, `bogen_master`, `bogen_sen` |

## Importverhalten

- Gruppen und Klassen: anlegen oder aktualisieren (Schlüssel `code` bzw. `nummer`+`geschlecht`), nie löschen.
- Disziplinen: anlegen oder aktualisieren (Schlüssel `kennzahl`); Regeln der importierten Disziplin werden ersetzt.
- Modus „ersetzen“: Disziplinen, die im Dokument fehlen, werden gelöscht, sofern keine Meldung sie referenziert.
- Nicht auflösbare Klassenreferenzen brechen den Import ab, bevor etwas geschrieben wird.
- Jeder Import setzt `regeln_geaendert_am` am Sportjahr und löst die Revalidierung der Meldungen aus (Meilenstein 4).
