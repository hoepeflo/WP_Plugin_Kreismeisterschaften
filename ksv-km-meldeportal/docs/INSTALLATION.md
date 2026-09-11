# Installation und Betrieb

## Voraussetzungen

- WordPress ab 6.4, PHP 8.1 oder neuer (Ziel: 8.3)
- Permalinks nicht auf „Einfach" (die Route `/km-meldung/` braucht Rewrite-Regeln)
- Mailversand über WP Mail SMTP (das Plugin nutzt ausschließlich `wp_mail()`)

## Installation

1. ZIP mit `bash tools/build-zip.sh` erzeugen (oder aus dem Release nehmen).
2. Im Backend unter „Plugins → Installieren → Plugin hochladen" das ZIP hochladen und aktivieren.
   Alternativ den Ordner `ksv-km-meldeportal/` per FTP nach `wp-content/plugins/` legen.
3. Bei der Aktivierung werden die Tabellen `wp_kmm_*` angelegt, die Rewrite-Regeln
   aktualisiert und das Cron-Ereignis `kmm_hourly` geplant.
4. Unter „KM-Meldeportal → System" prüfen: alle Tabellen „vorhanden", Schema-Version
   gleich der Code-Version.

Updates per FTP (ohne erneute Aktivierung) werden erkannt: Weicht die gespeicherte
Schema-Version vom Code ab, läuft die Migration beim nächsten Backend-Aufruf
automatisch. Auf der Systemseite kann sie jederzeit erneut angestoßen werden.

## Caching: WP Fastest Cache

Die Meldeseiten dürfen nie aus dem Seitencache kommen, sonst könnte Verein A die Seite
von Verein B sehen. Das Plugin setzt auf allen Seiten unter `/km-meldung/`
`DONOTCACHEPAGE`, `nocache_headers()` und `Cache-Control: no-store`. Zusätzlich muss
die Route im Caching-Plugin ausgeschlossen werden:

WP Fastest Cache → Reiter „Exclude" → „Add New Rule":

| Feld | Wert |
|---|---|
| Typ | Pages |
| Bedingung | Start With |
| Wert | `km-meldung` |

Außerdem unter „Exclude" eine Cookie-Regel anlegen, damit Besucher mit Sitzung nie
gecachte Seiten erhalten:

| Feld | Wert |
|---|---|
| Typ | Cookies |
| Bedingung | Contains |
| Wert | `kmm_sitzung` |

Nach dem Anlegen der Regeln den Cache einmal komplett leeren („Delete Cache and Minified
CSS/JS"). Falls ein anderer Slug als `km-meldung` eingestellt wird (Einstellung
`route_slug`), die Regel entsprechend anpassen.

Bei Ausschluss über `.htaccess`-Regeln von WP Fastest Cache (Premium/„Preload") ist
`/km-meldung/` ebenfalls auszunehmen. Ein CDN- oder Server-Cache (z. B. beim Hoster)
muss `Cache-Control: no-store` respektieren; das ist im Testlauf zu prüfen.

## WP-Cron über echten Server-Cronjob

WP-Cron läuft standardmäßig nur bei Seitenaufrufen. Für Erinnerungsmails (und in
Phase 2 die tägliche Sammelmail) soll ein echter Cronjob den Aufruf übernehmen:

1. In `wp-config.php` eintragen:
   ```php
   define('DISABLE_WP_CRON', true);
   ```
2. Im Hosting-Panel einen Cronjob anlegen, der alle 5 Minuten läuft:
   ```
   */5 * * * * curl -s -o /dev/null https://ksv-fallingbostel.de/wp-cron.php?doing_wp_cron
   ```
   Alternativ mit PHP-CLI (Pfad zum WordPress-Root anpassen):
   ```
   */5 * * * * php /pfad/zu/wordpress/wp-cron.php > /dev/null 2>&1
   ```
3. Auf der Systemseite steht bei „WP-Cron" dann „deaktiviert (Server-Cronjob erwartet)",
   und „Nächster Cron-Lauf" liegt maximal eine Stunde in der Zukunft.

Das Plugin plant ein stündliches Ereignis `kmm_hourly`; die Erinnerungsmail prüft darin,
ob ihr eingestellter Zeitpunkt erreicht ist.

## Zeitzone

Alle Zeitstempel werden in UTC gespeichert und mit der WordPress-Zeitzone
(Einstellungen → Allgemein) angezeigt. Meldebeginn und Meldeschluss werden im Backend
in Ortszeit eingegeben.

## Deinstallation

Beim Löschen des Plugins bleiben Tabellen und Daten erhalten, sofern nicht die
Einstellung „Daten bei Deinstallation löschen" gesetzt ist.

## Tests lokal ausführen

```bash
cd ksv-km-meldeportal
composer install
composer test    # PHPUnit (reines PHP, kein WordPress nötig)
composer stan    # PHPStan mit WordPress-Stubs
```

Die Regel-Engine (ab Meilenstein 4) ist vollständig ohne WordPress testbar. Für
Tests gegen eine echte Datenbank genügt eine lokale MariaDB/MySQL; die
CREATE-TABLE-Anweisungen lassen sich mit

```bash
php -r 'require "src/Autoloader.php"; KSV\KMM\Autoloader::register("src/");
  echo implode("\n", KSV\KMM\Infrastructure\Database\Schema::definitions("wp_", "DEFAULT CHARACTER SET utf8mb4"));' \
  | mysql -u root testdb
```

direkt ausführen.
