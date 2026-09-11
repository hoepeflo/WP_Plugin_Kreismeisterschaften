# Werkzeuge (nicht Teil des ausgelieferten Plugins)

## build-zip.sh

Erzeugt ein installierbares Plugin-ZIP ohne `docs/`, `tools/`, `tests/`:

```bash
bash tools/build-zip.sh      # → build/ksv-km-meldeportal-<version>.zip
```

## konvertiere-disziplinplan.py

Einmaliges Konvertierungsskript: NSSV-Disziplinplan (xlsx) → Regeltabellen-JSON
(`docs/REGELTABELLE-FORMAT.md`) plus Prüfbericht. Voraussetzung: Python 3.10+ und
`pip install openpyxl`.

```bash
python3 tools/konvertiere-disziplinplan.py \
    docs/quellen/01A1_Disziplinenplan_2026-Aktuell_17.02.xlsx \
    --sportjahr 2026 \
    --json docs/regeltabelle-2026.json \
    --bericht docs/pruefbericht-2026.md \
    --uebersicht docs/regeltabelle-2026-uebersicht.md \
    --bogen-csv docs/quellen/bogen-2026.csv       # optional, siehe unten
```

Ausgaben:

| Datei | Inhalt |
|---|---|
| `regeltabelle-2026.json` | Import-Dokument für „KM-Meldeportal → Import / Export“ (ohne Gruppen/Klassen; die kommen aus dem Klassensatz des Sportjahres) |
| `pruefbericht-2026.md` | **Unklare Zellen** (von Hand prüfen), Hinweise (Annahmen des Skripts), übersprungene Zeilen, Informationen |
| `regeltabelle-2026-uebersicht.md` | Matrix je Disziplin (Klasse → Einzel / Mannschaft) zum Abgleich mit dem PDF |

Was das Skript weiß und tut:

- Ein Blatt, mehrere Blöcke: Hauptblock (Zeilen 7–63), Para (rechts neben Target Sprint),
  Auflage, FITASC, Blasrohr (Erwachsene und Jugend), Lichtschießen. Die Blockpositionen
  sind im Skript hinterlegt und werden über Kopfzellen geprüft; weicht die Datei ab,
  bricht das Skript mit einer Meldung ab.
- Rote Füllung (`FFFF0000`, `FFD20000`) und leere Zellen = kein Startrecht.
- `E`/`M` = eigene Wertung, `E **` = eigene Wertung mit Hinweis „Meldung zum DSB anders“.
- Verweise `b.10`, `b 10`, `b. 10`, `b10` (auch mit Leerzeichen am Ende). Gleiches
  Geschlecht bevorzugt, sonst die Klasse mit dieser Nummer (z. B. Damen → Herren I 10m).
- `m40/w11`, `mb.40/wb.11` = geschlechtsabhängiger Verweis.
- Gemeinsame Mannschaftsspalte bei Schüler/Jugend: die w-Klasse verweist auf den Pool der m-Klasse.
- `ab 18 Jahre` (Vorderlader Junioren II) = Regel von Junioren I gleichen Geschlechts plus Mindestalter 18.
- `Team Junioren`, `Team Damen/Herren`, `bei Junioren`, `bei Team …` = MixTeam; Teamklassen `40x` und `10x`.
- Verbundene Zellen, Klassennummern als Text, Lichtschießen-Nummern auf zwei Zellen, Blasrohr `1.12` → `12.10`
  und vertauschte Zeilen „Alter“/„Klasse“ im Blasrohr-Jugendblock.
- Ein Verweis auf die eigene Klasse (verrutschte Zeile im Plan) wird als unklar gemeldet.
- Übersprungen: Target Sprint, Sommerbiathlon, Laufende Scheibe, Armbrust (auch 5.11).
- Ergebnisformat: Zehntelringe nur 1.10; Tarif-Überschreibung 2,50 € für 11.x (Lichtschießen).

Der Klassensatz im Skript (`KLASSEN`) muss zum Seed in `src/Domain/Seed/Klassensatz.php` passen.

## Bogen

Bogen ist nicht in der xlsx. Vorlage erzeugen, aus dem Bogen-PDF (06A1) ausfüllen und
beim nächsten Lauf mit `--bogen-csv` einmischen:

```bash
python3 tools/konvertiere-disziplinplan.py x --bogen-vorlage docs/quellen/bogen-2026-vorlage.csv
```

Die CSV (Trennzeichen `;`) hat eine Zeile je Disziplin (6.10 … 6.68) und eine Spalte je
Klasse (`10m` … `41w`), nur Einzelwertung. Zellinhalte: `E` (eigene Wertung), `b.13`
(startet in Klasse 13), leer (kein Startrecht). Ein `*` (z. B. `E*`, `b.22*`) ergibt den
Hinweis „keine DM / DSB-Ausschreibung beachten“. Bogen-Disziplinen erhalten Typ `bogen`
(keine Mannschaften, kein DAVID-Export).
