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
| `vendor/` | Composer-Laufzeitabhängigkeiten (erst ab PDF-Bibliothek nötig) | ja, vom Build erzeugt |
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

Installation, Caching-Ausschluss und Server-Cronjob: `docs/INSTALLATION.md`.
Datenmodell: `docs/DATENMODELL.md`.
