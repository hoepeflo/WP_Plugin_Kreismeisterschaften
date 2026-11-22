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

## Umsetzung Schritt 1–3 (Stand 22.11.2026, Schema 6)

Entscheidungen von Florian Höper vom 22.11.2026:

- **Ein Ablageort** für alle Ergebnisse, alte wie künftige: `uploads/results/km_JJJJ/`.
  Es wird nichts verschoben. Pfad und Adresse kommen aus `srd_km_settings`.
- Ab 2027 kommen die Ergebnisplätze aus dem **Disziplinplan** des Sportjahres. Archivjahre
  (bis 2026) nutzen den Katalog aus `srd_kreis_v3` (`kmm_ergebnis_katalog`); ein Archivjahr
  hängt nie an einem Sportjahr (auch nicht am Testjahr 2026).
- Dateinamen: `e1_10_10.pdf` (Einzel), `m1_10_10.pdf` (Mannschaft), Sammel-PDF ohne Klasse
  `e6_20.pdf`. Erkannt werden auch `E1-10-10.pdf` und `E6-20.pdf`. Ab 2027 nur PDF.
- Geteilte Klassen 2008/2016 (`e1_10_10a`) erscheinen als „… Gruppe A/B/C“.
- Lichtschießen nur als PDF-Links je Jahr (`km-licht/JJJJ/`, `km_JJJJ/licht/`, `*licht*`).
- Uploads sind sofort öffentlich; „Entwurf“ ist optional.
- Recht „Ergebnisse hochladen“ (`darf_ergebnisse`) gilt für alle Disziplinen.
- Kein Staging: Test live mit ausgeschaltetem Schalter, vor jedem echten Lauf Probelauf und
  Duplicator-Sicherung.

### Bausteine

| Baustein | Datei |
|---|---|
| Schlüssel, Dateinamen, Sparten | `src/Domain/Ergebnis/Schluessel.php`, `Kategorie.php` |
| Plätze aus Disziplinplan / Katalog | `src/Domain/Ergebnis/Platz.php`, `Platzliste.php` |
| Zuordnung beim Sammel-Upload (Ampel) | `src/Domain/Ergebnis/UploadZuordnung.php` |
| Auswertung eines Archivordners | `src/Domain/Ergebnis/ArchivAuswertung.php` |
| Dateizugriff, Sicherung statt Löschen | `src/Infrastructure/Ergebnisablage.php` |
| Hochladen, Status, Klärung | `src/Application/Ergebnisse/ErgebnisService.php` |
| Archiv einlesen (Katalog, Jahre, Licht, Ausschreibungen) | `src/Application/Ergebnisse/ErgebnisArchiv.php` |
| Ausschreibungen und Unterlagen | `src/Application/Ergebnisse/Dokumente.php` |
| Testjahr entfernen | `src/Application/Ergebnisse/Testjahr.php` |
| Backend „KM-Portal → Ergebnisse“ | `src/Admin/ErgebnissePage.php`, `assets/admin/ergebnisse.css` |
| Öffentliche Anzeige | `src/Http/ErgebnisFrontend.php`, `assets/frontend/ergebnisse.css` |
| Einstellungen (Option `kmm_ergebnisse`) | `src/Support/ErgebnisEinstellungen.php` |

Tabellen (Schema 6): `kmm_ergebnis` (ein Eintrag je Jahr/Art/Schlüssel, Status
sichtbar/Entwurf/ungeklärt/ausgeblendet), `kmm_ergebnis_katalog`, `kmm_dokument`.

Eine ersetzte oder entfernte Datei wird nie gelöscht, sondern mit Zeitstempel nach
`uploads/kmm-archiv/ergebnisse/` verschoben (kein Webzugriff).

### Anzeige und alte Adressen

- `[kmm_ergebnisse]` funktioniert immer.
- Schalter **„Anzeige übernehmen“** (Ergebnisse → Einstellungen): Das KM-Portal bedient
  dann auch `[srd_km]`, `/kreismeisterschaften/JJJJ/`, `/JJJJ/e|m/ID/`, `…/raw/`,
  `/JJJJ/bogen/`, `/JJJJ/blasrohr/` und die Parameter `km_year`, `km_id`, `km_art`,
  `km_view=raw`, `km_category`, `km_discipline`.
- Solange das bisherige Plugin aktiv ist, übernimmt das KM-Portal **nichts**, auch bei
  eingeschaltetem Schalter. Beim Deaktivieren des alten Plugins werden die Adressregeln
  neu geschrieben.

### Reihenfolge auf der Live-Seite

1. Duplicator-Sicherung.
2. Plugin-Update einspielen (Schema 6 legt die neuen Tabellen an; nichts Bestehendes ändert sich).
3. Ergebnisse → Archiv & Klärung → **Probelauf**, Bericht prüfen.
4. **Einlesen** (EINLESEN eintippen). Mehrfaches Einlesen ist unschädlich.
5. Klärungsliste abarbeiten; Seite mit `[kmm_ergebnisse]` als Entwurf ansehen.
6. Testjahr 2026 entfernen (Jahr eintippen; JSON-Sicherung im Archivordner).
7. Schalter „Anzeige übernehmen“ an, altes Plugin deaktivieren, alte Adressen stichprobenartig aufrufen.
8. Das alte Plugin erst löschen, wenn alles passt. `srd_kreis_v3` und `srd_km_*` bleiben stehen.
