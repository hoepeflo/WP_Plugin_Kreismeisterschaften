# Checkliste Testlauf mit dem SV Vorwalsrode (Mitte Oktober 2026)

## Vorbereitung (Admin)

- [ ] Plugin-ZIP mit `bash tools/build-zip.sh` bauen (enthält `vendor/` mit mPDF) und installieren; Systemseite: alle Tabellen vorhanden, Schema-Version 2.
- [ ] WP Fastest Cache: Ausschlussregeln für `km-meldung` und Cookie `kmm_sitzung` gesetzt, Cache geleert (`docs/INSTALLATION.md`).
- [ ] WP Mail SMTP aktiv; Einstellungen → Absendername/-adresse des KM-Portals prüfen. Eine Testmail über „Link senden“ an die eigene Adresse.
- [ ] Server-Cronjob für `wp-cron.php` eingerichtet oder bewusst WP-Cron per Seitenaufruf akzeptiert.
- [ ] Sportjahr 2027 angelegt (Klassensatz), aktiviert, Meldebeginn/Meldeschluss (10.01.2027) und Erinnerungszeitpunkt gesetzt.
- [ ] Regeltabelle 2026 importiert (`docs/regeltabelle-2026.json`), Prüfbericht abgearbeitet (7.15 korrigiert), Bogen-CSV eingemischt oder Bogen-Regeln von Hand gepflegt.
- [ ] Stammdaten geprüft: Lichtschießen-Tarif 2,50 €, Zehntel-Disziplinen, MixTeam-Kennzahlmodus, ggf. Altersuntergrenze Schüler.
- [ ] Einstellungen: Nicht-Meldung sichtbar?, FITASC-Regel, CSV-Format (Trennzeichen, Zeichensatz, Kopfzeile, Verband, ganze Ringe).
- [ ] Verein SV Vorwalsrode mit VN-Nummer und Adressen angelegt, Link gesendet.

## Verein (Sportleiter, am Smartphone und am PC)

- [ ] Link aus der Mail öffnen; Vereinsname erscheint; Link ein zweites Mal öffnen.
- [ ] Schützen anlegen: mindestens ein Schüler, ein Jugendlicher, Junioren, Herren I–III, eine Dame, ein Auflage-Senior; Mitgliedsnummer mit fremder VN-Nummer → Warnung; Höhermeldung bei einem Junior setzen.
- [ ] Meldungen: Luftgewehr 1.10 für alle, 1.11 Auflage für den Senior, 1.12 MixTeam für Jugend/Junioren m+w, 1.22 für Herren II (Kennzahl 1.22.10), Blasrohr 12.10, Lichtschießen für ein Kind; ein Para-Fall.
- [ ] Meldeergebnisse: Zehntel bei 1.10, ganze Ringe bei 1.22, Fehleingaben (389,45 / 375,4) werden abgewiesen.
- [ ] Mannschaften: 1.10 Herren I + II gemeinsam, Herren III nicht wählbar; Schüler m+w gemischt; MixTeam m+w; unvollständige Mannschaft blockiert das Einreichen.
- [ ] Ansprechpartner, Startgeldvorschau plausibel (Jugend 5 €, Erwachsene 7 €, Lichtschießen 2,50 €, MixTeam 0 €), Einreichen, Bestätigungsmail an Vereinsadressen und Ansprechpartner.
- [ ] Meldung wieder öffnen, etwas ändern, erneut einreichen. PDF der eigenen Meldung herunterladen.
- [ ] „Link anfordern“ mit hinterlegter und mit fremder Adresse (gleiche Antwort).

## Admin nach der Vereinsmeldung

- [ ] Übersicht: Status Eingereicht, Zahlen stimmen, Details je Verein, Mail-/Linkstatus.
- [ ] Regeländerung (z. B. Mannschaftsregel 1.10 Herren II) → Konflikt in Übersicht und Portal; Verein bestätigt oder entfernt.
- [ ] Erinnerung an einen Testverein mit Status Entwurf manuell senden.
- [ ] Änderungsprotokoll: Vereins- und Admin-Aktionen nachvollziehbar.
- [ ] DAVID-Export gesamt und je Disziplin; **Testimport in DAVID21**: Zeichensatz/Umlaute, Trennzeichen, Kennzahlen mit Buchstaben (1.56S), MixTeam-Kennzahl, Mitgliedsnummer als Text, Ergebnisformat, Feld „Verband“. Einstellungen entsprechend anpassen und erneut exportieren.
- [ ] PDF-Meldelisten nach Verein und nach Klasse, eine Gruppe, eine Disziplin; Bogen enthalten; Seitenumbruch je Disziplin.
- [ ] Sportjahr 2027 → „Kopieren nach 2028“ als Probe, Kopie danach löschen.

## Abschluss des Testlaufs

- [ ] Testdaten löschen (Meldungen des Testvereins entfernen oder Sportjahr neu anlegen), Erinnerungs-Versandvermerk zurücksetzen.
- [ ] Offene Punkte aus dem Testimport in den Einstellungen festhalten; Entscheidung „Nicht-Meldung“.
- [ ] Links an alle Vereine senden → Start der Meldephase.
