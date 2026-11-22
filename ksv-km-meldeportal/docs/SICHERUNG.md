# Externe Sicherung der Portal-Daten

Das Plugin sichert alle Daten des KM-Portals (alle `kmm_*`-Tabellen) verschlüsselt auf einen
zweiten Webspace bei einem anderen Anbieter (All-Inkl). Fällt die Datenbank bei IONOS aus oder
wird die Website gehackt, liegen die Meldungen dort weiter.

Entscheidungen vom 22.11.2026 (Konzept mit Florian):

| Thema | Festlegung |
| --- | --- |
| Zeitraum | Fester Zeitraum, Von/Bis auf der Seite „Sicherung“; von Hand sichern geht immer |
| Auslöser | Täglich ab Uhrzeit (Standard 03:00), nach `meldung.einreichen`, `wettkampftag.freigeben`, `wettkampftag.veroeffentlichen`, `sportjahr.abschliessen` (gesammelt 10 Minuten), vor jeder Wiederherstellung |
| Ziel | Briefkasten-Skript auf dem All-Inkl-Webspace (`tools/briefkasten/`) |
| Aufbewahrung | 90 Tage im Briefkasten, 14 Tage als Kopie auf dem IONOS-Webspace |
| Verschlüsselung | libsodium Sealed Box; auf dem Server nur der öffentliche Schlüssel |
| Privater Schlüssel | Florian (Passwort-Manager) und ein Vorstandsmitglied (Notfall-Umschlag) |

## So funktioniert es

```
KM-Portal (IONOS)                              Briefkasten (All-Inkl)
  Sicherung erstellen
  → JSON → gzip → verschlüsseln
  → uploads/kmm-archiv/sicherung/  (14 Tage)
  → POST ?aktion=einwurf  ───────────────────→  speichert nur neue Dateien,
     (Zugangsschlüssel: darf nur einwerfen)      überschreibt und löscht nie
  → POST ?aktion=puls (stündlich) ───────────→  merkt sich Lebenszeichen
                                                Cronjob ?aktion=wartung:
                                                  löscht > 90 Tage,
                                                  warnt per Mail
                                                ?aktion=liste: Download-Seite
                                                  mit Passwort, nur lesen
```

- **Ein Hack des Portals erreicht die Sicherungen nicht.** Auf IONOS liegen nur der
  Einwurf-Schlüssel (kann nichts lesen oder löschen) und der öffentliche Schlüssel (kann nur
  verschlüsseln).
- **Schlägt die Übertragung fehl**, bleibt die Datei auf IONOS als „offen“ und wird stündlich
  erneut geschickt. Der Administrator (`admin_email`) bekommt höchstens einmal am Tag eine Mail.
- **Der Briefkasten warnt selbst** (Mail an `warn_mail`), wenn sich das Portal 36 Stunden nicht
  meldet oder im Zeitraum 36 Stunden keine Sicherung kommt. Das deckt auch einen ausgefallenen
  Cronjob ab.

## Einrichtung

### 1. Briefkasten auf All-Inkl

1. Im All-Inkl-Webspace einen Ordner anlegen, z. B. `kmm-briefkasten/` unter einer Domain mit
   SSL. Hineinladen: `index.php`, `Briefkasten.php`, `.htaccess` aus `tools/briefkasten/`.
2. `config-beispiel.php` als `config.php` hochladen und ausfüllen:
   - `einwurf_token`: Zufallswert, mindestens 24 Zeichen
   - `wartung_token`: anderer Zufallswert
   - `download_passwort`: `password_hash()` des Passworts für die Download-Seite
   - `ordner`: am besten außerhalb des öffentlichen Webordners; sonst schützt `daten/.htaccess`
   - `warn_mail`, `absender`
3. Im KAS unter „Tools → Cronjobs“ einen Cronjob anlegen, der stündlich
   `https://…/kmm-briefkasten/index.php?aktion=wartung&schluessel=<wartung_token>` aufruft.
   (Der vorhandene Cronjob für `wp-cron.php` des Portals bleibt, wie er ist.)
4. `https://…/kmm-briefkasten/?aktion=liste` im Browser öffnen und mit dem Passwort anmelden.

Zufallswerte: `php -r 'echo bin2hex(random_bytes(24)), "\n";'`

### 2. KM-Portal

1. **KM-Portal → Sicherung → Schlüsselpaar erzeugen.** Der private Schlüssel wird genau einmal
   angezeigt: in den Passwort-Manager kopieren und für den Notfall-Umschlag ausdrucken.
2. Briefkasten-Adresse (`https://…/kmm-briefkasten/index.php`) und Zugangsschlüssel
   (`einwurf_token`) eintragen, Zeitraum und Uhrzeit setzen, „Automatisch sichern“ anhaken.
3. „Verbindung testen“, dann „Jetzt sichern“. Die Datei muss auf der Download-Seite erscheinen.

### 3. Notfall-Umschlag

Für das Vorstandsmitglied ausdrucken und verschlossen hinterlegen:

- den privaten Schlüssel (`KMM-PRIVAT-…`)
- die Adresse der Download-Seite und ihr Passwort
- den Hinweis auf diesen Abschnitt („Wiederherstellung“)

## Wiederherstellung

1. Sicherung herunterladen: Download-Seite des Briefkastens oder KM-Portal → Sicherung.
2. KM-Portal → Sicherung → „Sicherung einspielen“: Datei und privaten Schlüssel angeben, ohne
   Haken absenden. Die Vorschau zeigt Stand, Vereine, Meldungen und Schützen.
3. Datei und Schlüssel erneut angeben, Haken setzen, absenden. Das Plugin sichert zuerst den
   aktuellen Stand (`…-vor-wiederherstellung`), leert dann alle in der Datei enthaltenen
   Tabellen und füllt sie in einer Transaktion neu. Datei und Schlüssel werden nicht gespeichert.

Auf einer frischen WordPress-Seite: Plugin installieren und aktivieren (legt die Tabellen an),
dann wie oben einspielen. Ohne WordPress lässt sich eine Sicherung am eigenen Rechner lesen:

```
php tools/sicherung-entschluesseln.php kmm-sicherung-….kmmsic > sicherung.json
```

Die JSON-Datei enthält Personendaten und gehört nach Gebrauch gelöscht.

**Probelauf:** Vor dem ersten Einsatz einmal auf einer Testseite komplett wiederherstellen, danach
einmal pro Saison.

## Datenschutz

- Zweck: Verfügbarkeit der Meldedaten (Art. 32 DSGVO). Speicherort Deutschland (All-Inkl),
  AV-Vertrag mit All-Inkl abschließen.
- Aufbewahrung 90 Tage. Nach Abschluss und Anonymisierung eines Sportjahres liegen Klarnamen
  daher noch bis zu 90 Tage in den Sicherungen. Diese Frist ins Verarbeitungsverzeichnis des KSV
  aufnehmen („Sicherung der Meldedaten“).
- Die Sicherungen sind verschlüsselt; ohne privaten Schlüssel sind sie nicht lesbar. Geht der
  Schlüssel verloren, sind sie auch für den KSV wertlos.

## Format

`kmm-sicherung-JJJJMMTT-HHMMSS-<anlass>.kmmsic` (Zeit in UTC). Inhalt: `KMMSIC1\n` +
`sodium_crypto_box_seal(gzencode(json))`. Das JSON hat `format: "kmm-sicherung"`, `version: 1`,
`anlass`, `erstellt_am`, `website`, `plugin`, `schema` und `tabellen` (Kurzname → Zeilen).
Beim Einspielen werden nur Spalten übernommen, die es im aktuellen Schema gibt.

Code: `src/Application/Sicherung/`, `src/Admin/SicherungPage.php`, `tools/briefkasten/`.
