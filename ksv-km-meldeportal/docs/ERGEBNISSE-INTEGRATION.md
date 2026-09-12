# Ergebnisdienst: Integration statt Verknüpfung

**Entscheidung (Florian Höper):** Das bestehende Plugin `srd-kreismeisterschaften`
(„SRD Kreismeisterschaften“, Version 1.6.4) wird **nicht** mit dem KM-Portal verknüpft,
sondern langfristig **in das KM-Portal übernommen**. Ziel ist ein Plugin für den gesamten
Ablauf: Meldung → Startplan → Ergebnis → Archiv.

Diese Entscheidung ersetzt den Satz in Konzept 12.6 („Die Verknüpfung mit dem bestehenden
Ergebnis-Plugin … folgt später“). Bis zur Übernahme laufen beide Plugins unverändert
nebeneinander; das KM-Portal ruft nichts aus dem Ergebnis-Plugin auf und setzt es nicht
voraus.

## Was das Ergebnis-Plugin heute macht

| Bereich | Stand |
|---|---|
| Disziplinenliste | Tabelle `srd_kreis_v3` (ohne `wp_`-Präfix), gepflegt im Backend (CRUD) |
| Kategorien | Gewehr, Bogen, Lichtschießen, Blasrohr … aus der führenden Ziffer der Datei-ID |
| Ergebnisdateien | PDF/HTML unter `results/km_JJJJ/`, Upload im Backend |
| Dokumente | Ausschreibung, Disziplinenplan, Jahrgangstabelle, Terminplan (Option `srd_km_documents`) |
| Ausgabe | ein Shortcode `[srd_km]`, eigene Rewrite-Regel, `assets/km-embed.css` |
| Datenzugriff | eigene MySQLi-Verbindung mit den WordPress-Zugangsdaten, nicht `$wpdb` |
| Umfang | ca. 4.200 Zeilen prozedurales PHP, Präfix `srd_km_`, keine Tests |

## Warum Integration und nicht Verknüpfung

- Disziplinen sind heute **zweimal** gepflegt: im KM-Portal als Regeltabelle (mit Kennzahl,
  Klassen, Startgeld) und im Ergebnis-Plugin als `srd_kreis_v3`. Eine Verknüpfung würde
  diese Doppelpflege festschreiben statt auflösen.
- Der Startplan des KM-Portals kennt bereits Wettkampftag, Einheit, Durchgang und Starter.
  Das Ergebnis gehört fachlich an dieselbe Zeile (`kmm_einzelmeldung`), nicht in eine
  zweite Datenhaltung.
- Zwei Plugins bedeuten zwei Backend-Menüs, zwei Rechtemodelle und zwei Update-Wege für
  dieselbe Veranstaltung.

## Wie die Übernahme ablaufen soll

Die Reihenfolge ist bewusst so gewählt, dass das Ergebnis-Plugin bis zum letzten Schritt
aktiv bleiben kann und **keine bestehenden Daten umgeschrieben werden**.

1. **Ergebnisdateien und Dokumente** (kleinster Schnitt, kein Datenmodell)
   Upload und Anzeige der PDF/HTML-Dateien je Sportjahr in das KM-Portal holen, inklusive
   Ausschreibung, Disziplinenplan, Jahrgangstabelle und Terminplan. Ablage weiterhin unter
   `results/km_JJJJ/`, damit alte Links gültig bleiben.
2. **Disziplinen zusammenführen**
   `srd_kreis_v3` einmalig auf die Regeltabelle des KM-Portals abbilden (Datei-ID →
   Kennzahl). Ab dann ist die Regeltabelle die einzige Quelle; das Ergebnis-Plugin liest
   die Liste nur noch.
3. **Ausgabe ablösen**
   Einen Shortcode des KM-Portals anbieten, der dieselbe Ansicht erzeugt wie `[srd_km]`.
   `[srd_km]` bleibt als Alias bestehen, damit vorhandene Beiträge unverändert
   weiterlaufen.
4. **Ergebniserfassung im Portal**
   Ergebnisse je Einzelmeldung und Mannschaft direkt an der Meldung erfassen, aus dem
   Startplan heraus. Erst hier entsteht neuer Nutzen: Rangliste, Weitermeldung zur
   Landesmeisterschaft, Statistik über Jahre.
5. **Abschalten**
   Das alte Plugin deaktivieren, sobald Schritt 1–4 für ein volles Sportjahr im Einsatz
   waren. `srd_kreis_v3` bleibt als Archiv bestehen und wird nicht gelöscht.

Jeder Schritt ist ein eigener Meilenstein mit eigener Freigabe. Vor Schritt 2 ist zu
klären, wie die Datei-IDs des SRD-Ergebnisdienstes auf die SpO-Kennzahlen abgebildet
werden (offener Punkt, Klärung durch Florian).

## Auswirkung auf Meilenstein 10

Meilenstein 10 (Veröffentlichung und Abschluss des Sportjahres) bleibt wie geplant:
Shortcode für den Startplan, PDF-Startplan, Abschluss mit Anonymisierung. Ein Link vom
Startplan zu den Ergebnissen wird **nicht** gebaut. Das Feld `kmm_wettkampftag.ergebnis_url`
und `kmm_einzelmeldung.ergebnis_ref` bleiben im Schema erhalten (Migrationen sind
rückwärtskompatibel), werden aber bis zur Übernahme nicht befüllt.
