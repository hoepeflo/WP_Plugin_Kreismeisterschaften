# KSV KM-Meldeportal (WordPress-Plugin)

Meldeportal der Vereine des Kreisschützenverbands Fallingbostel zur Kreisverbandsmeisterschaft.
Plugin von Florian Höper (https://github.com/hoepeflo).

Verbindliche Grundlage ist das Konzept unter `docs/KM-Meldeportal_Konzept.md`.

## Ordner

| Ordner | Inhalt | Im ZIP |
|---|---|---|
| `ksv-km-meldeportal.php` | Plugin-Hauptdatei (Header, Konstanten, Bootstrap) | ja |
| `src/` | PHP-Klassen, Namespace `KSV\KMM` (PSR-4) | ja |
| `templates/` | Serverseitige Templates (Vereinsoberfläche, Mails) | ja |
| `assets/` | CSS/JS ohne Build-Schritt | ja |
| `languages/` | Text-Domain `ksv-km-meldeportal` | ja |
| `vendor/` | Composer-Laufzeitabhängigkeiten (mPDF für die PDF-Ausgaben) | ja, vom Build erzeugt |
| `docs/` | Konzept, Datenmodell, Installation, Quellen | nein |
| `tools/` | Konvertierungsskript, Build-Skript | nein |
| `tests/` | PHPUnit-Tests | nein |

## Kollisionsfreiheit zum Ergebnis-Plugin

| Bereich | Präfix / Name |
|---|---|
| PHP-Namespace | `KSV\KMM` |
| Datenbanktabellen | `{wp_}kmm_*` |
| Optionen, Transients | `kmm_*` |
| Hooks, Cron-Events | `kmm_*` |
| Capabilities | `kmm_manage`, `kmm_view` |
| REST-Namespace | `kmm/v1` |
| Query-Var / Route | `kmm_route`, Standard `/km-meldung/` |
| Text-Domain | `ksv-km-meldeportal` |
| Cookie | `kmm_sitzung` |

## Entwicklung

```bash
composer install          # Dev-Abhängigkeiten (PHPUnit, PHPStan, WordPress-Stubs)
composer test             # Unit-Tests
composer stan             # statische Analyse
composer lint             # php -l über alle Dateien
composer check            # alles zusammen
bash tools/build-zip.sh   # installierbares ZIP nach build/
```

## Vereinsoberfläche (Stand Meilenstein 6)

Route `/km-meldung/` nach Anmeldung per Zugangslink: vier Schritte Schützen, Meldung, Mannschaften, Prüfen & Einreichen; PDF der eigenen Meldung unter `/km-meldung/pdf/`. Serverseitig gerendertes Gerüst (`templates/frontend/app.php`) plus Vanilla-JS (`assets/frontend/app.js`) über die REST-Endpunkte `kmm/v1` (`src/Http/RestApi.php`). Beschreibung in `docs/VEREINSOBERFLAECHE.md`.

## Backend (Stand Phase 2, Meilenstein 2)

| Seite | Inhalt |
|---|---|
| Übersicht | Statusübersicht aller Vereine (Offen/Entwurf/Eingereicht, Einzelmeldungen, Mannschaften, fehlende Ergebnisse, Konflikte, Startgeld, Zugang), Details je Verein, Erinnerung jetzt senden |
| Verarbeitung | Verarbeitungsstatus je Einzelmeldung (ungeprüft / verarbeitet / nicht startberechtigt mit Grund), Filter nach Verein, Gruppe, Disziplin, Status; Sammelaktion; Referenten nur im Zuständigkeitsbereich |
| Export | DAVID21-CSV (gesamt / je Disziplin, Format einstellbar) und PDF-Meldelisten (Umfang, Gruppierung), Export-Protokoll |
| Protokoll | Änderungsprotokoll (wer, wann, was) mit Filtern nach Sportjahr, Verein, Akteur und Text |
| System | Schema-Version, Tabellen, Cron, Migration erneut ausführen |
| Sportjahre | anlegen (mit Klassensatz nach Konzept 4.1), Meldebeginn/-schluss/Erinnerung, aktivieren, ins Folgejahr kopieren, löschen |
| Vereine | Name, VN-Nummer, mehrere Adressen, aktiv; Zugangslink senden (einzeln / alle), Linkstatus, Mailstatus |
| Stammdaten | je Sportjahr: Klassen, Disziplinen, Regelmatrix je Disziplin, Startgeldtarife |
| Import / Export | Regeltabelle als JSON (Format siehe `docs/REGELTABELLE-FORMAT.md`) |
| Einstellungen | Route, offene Punkte aus Konzept 13 (Nicht-Meldung, FITASC, CSV-Format …), Mail-Absender |

Konvertierungsskript und Bogen-Vorlage: `tools/README.md`. Ergebnis für 2026: `docs/regeltabelle-2026.json`, `docs/pruefbericht-2026.md`, `docs/regeltabelle-2026-uebersicht.md`.

Installation, Caching-Ausschluss und Server-Cronjob: `docs/INSTALLATION.md`.
Datenmodell: `docs/DATENMODELL.md`. Regel-Engine: `docs/REGEL-ENGINE.md`. Zugang der Vereine: `docs/ZUGANG.md`. Vereinsoberfläche: `docs/VEREINSOBERFLAECHE.md`. Ausgaben: `docs/EXPORT.md`. Testlauf: `docs/CHECKLISTE-TESTLAUF.md`. Austauschformat der Regeltabelle: `docs/REGELTABELLE-FORMAT.md`.
