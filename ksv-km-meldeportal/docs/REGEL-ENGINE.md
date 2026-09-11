# Regel-Engine

Namespace `KSV\KMM\Domain\Engine`, reines PHP ohne WordPress, Tests in `tests/Unit/Engine`.
Die Tests laufen gegen die konvertierte Regeltabelle 2026 (`docs/regeltabelle-2026.json`)
und den Klassensatz, Sportjahr 2027, Stichtag 10.01.2027.

## Ablauf `Engine::bewerte(Disziplin, Schuetze)`

1. **Alter** = Sportjahr − Geburtsjahr.
2. **Eigentliche Klasse**
   - Para-Klasse an der Meldung gewählt → diese (Geschlecht muss passen).
   - Sonst Altersklasse der Wettbewerbsgruppe der Disziplin nach Alter und Geschlecht.
     Mehrdeutigkeit (FITASC Damen 61 / Senioren 62 ab 56) entscheidet die Einstellung
     „FITASC: Schützinnen ab 56“.
   - **Höhermeldung** des Schützen für den Bereich der Gruppe (`uebrige`, `auflage`, `bogen`):
     Zielstufe (z. B. `hd1`) wird in der Gruppe der Disziplin auf eine Klasse gleichen
     Geschlechts abgebildet und übernommen, wenn sie „höher“ ist. Festgeschriebene Klassen
     (Schüler, Jugend) werden nie übersteuert.
3. **Regel** Disziplin × Klasse. Keine Regel = kein Startrecht. Mindestalter wird mit dem
   vollen Geburtsdatum zum Stichtag (Meldeschluss) geprüft.
4. **Einzel-Startklasse**: Verweise werden als Kette bis zu einer Klasse mit eigener
   Wertung verfolgt (1.30: Junioren II → Junioren I → Herren I). Endet eine Kette ohne
   eigene Wertung, gilt die zuletzt genannte Klasse mit Hinweis; Zyklen werden abgefangen.
5. **Mannschaftspool**: genauso über den Mannschaftsteil. MixTeam: kein Einzel, Pool ist
   die Teamklasse (`40x` Team Junioren, `10x` Team Damen/Herren). Bogen und Disziplinen mit
   Mannschaftsgröße 0: kein Pool.
6. **Startgeld** nach Tarifstufe der **Startklasse**; Tarif-Überschreibung der Disziplin
   (Lichtschießen 2,50 €) sofern nicht per Einstellung ignoriert; MixTeam 0,00 € pro Person.
7. **Kennzahl** = Disziplin-Kennzahl + „.“ + Nummer der Startklasse; MixTeam je nach
   Einstellung der Disziplin Team- oder Geschlechterklasse.

## „Höher“ bei der Höhermeldung (SpO 0.7.1.1)

- Junioren (und andere nicht festgeschriebene Klassen bis 20) nur in die Basisklasse der
  Erwachsenen der Gruppe (Herren/Damen I, Bogen Herren/Damen, FITASC Herren/Damen).
- Erwachsene in jede jüngere Erwachsenenklasse (Herren III → II oder I, Senioren II → I oder 0).
- Die wählbaren Stufen je Bereich liefert `Engine::hoehermeldung_angebot()`; Leitgruppen:
  übrige → Freihand, Auflage → Auflage, Bogen → Bogen.

## Mannschaften (`MannschaftPruefung`)

Alle Mitglieder mit Startrecht und demselben Pool, höchstens Mannschaftsgröße, beim
Einreichen vollständig; MixTeam genau 1 m + 1 w. `passt()` filtert die Auswahl in der
Vereinsoberfläche vor.

## Revalidierung (`Application\Revalidierung`)

Action `kmm_regeln_geaendert` (nach Import, Regel-, Klassen-, Disziplin- und Tarifänderung):
alle Einzelmeldungen des Sportjahres werden neu bewertet. Abweichungen in Klasse,
Startklasse, Pool, Startrecht, Höhermeldung oder Startgeld werden übernommen und mit
`konflikt = 1` und einem Klartext (`konflikt_text`) markiert; betroffene Mannschaften
erhalten `unvollstaendig = 1`. Einträge im Änderungsprotokoll.

## Hinweise zu den Daten 2026

- Freihand-Schüler haben im NSSV-Plan keine Altersuntergrenze („… - 14 nach gesetzl.
  Vorgaben“). Soll das Portal z. B. erst ab 12 anbieten, im Backend an den Klassen
  Schüler m/w `Alter von` setzen.
- 1.30 Zimmerstutzen: Junioren II verweisen bei der Mannschaft auf 40, Junioren I weiter auf 10.
  Durch die Kettenauflösung landen beide im Pool 10.
