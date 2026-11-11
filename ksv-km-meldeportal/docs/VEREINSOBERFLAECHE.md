# Vereinsoberfläche

Route `/km-meldung/` (ohne Theme, nie gecacht), Zugang per Magic Link (`docs/ZUGANG.md`).
Das Seitengerüst wird serverseitig gerendert (`templates/frontend/app.php`), der Inhalt
von `assets/frontend/app.js` (Vanilla JS, kein Build-Schritt) aus dem eingebetteten
Anfangszustand und den REST-Endpunkten. Mobil zuerst; ab 700 px Breite Tabellenansicht.

## Schritte

| Schritt | Inhalt |
|---|---|
| 1 Schützen | Schützenliste des Vereins (bleibt über Jahre): Name, Vorname, Geburtsdatum, Geschlecht, Mitgliedsnummer (Pflicht, 9 Ziffern; Warnung ohne Sperre, wenn sie nicht mit der VN-Nummer beginnt), Höhermeldungen je Bereich (nur angebotene Stufen, nur dieses Sportjahr). Löschen nur ohne Meldungen. |
| 2 Meldung | Schütze wählen → Disziplinen mit Startrecht, gruppiert nach Gewehr/Pistole/Flinte/Vorderlader/Auflage/…; angezeigt werden Klasse, Startklasse, Kennzahl, Mannschaftspool, Startgeld, Hinweise; Para-Klasse wählbar, wenn die Disziplin Para-Regeln hat. „Starter melden“ steht über der Liste, damit das Angebot nach „Melden“ ohne Scrollen sichtbar ist. Darunter die Tabelle der gemeldeten Starter mit Startklasse (Altersklasse nur, wenn sie abweicht), Meldeergebnis (Format je Disziplin, fehlende Ergebnisse gelb; das Feld bestätigt das Speichern selbst, ohne Toast), optional Checkbox „Nicht-Meldung“, Mannschaftsnummer, Konflikte mit Bestätigen/Entfernen. Unter 700 px wird jede Zeile zu einer kompakten Karte: Name, Startklasse und Mannschaft, darunter Ergebnis, Startgeld und Entfernen in einer Zeile. |
| 3 Mannschaften | Je Disziplin mit Mannschaftswertung: Mannschaften anlegen/bearbeiten/auflösen. Wählbar sind nur Schützen derselben Mannschaftsklasse ohne andere Mannschaft; MixTeam genau 1 m + 1 w. Nummern je Verein und Disziplin fortlaufend. Im Auswahldialog sind die drei Zustände auseinanderzuhalten: **gewählt** (grüner Rahmen), **wählbar** und **gesperrt** (gestrichelt, blass, mit Grund – „andere Mannschaftsklasse“, „Mannschaft ist voll“, „MixTeam: eine Schützin ist schon dabei“). Eine Statuszeile über der Liste nennt die festgelegte Mannschaftsklasse und wie viele Plätze noch frei sind. |
| 4 Prüfen & Einreichen | Ansprechpartner (Pflicht), Startgeldvorschau (vorläufig), Prüfliste (Fehler blockieren, Warnungen nicht), Einreichen mit Bestätigungsmail an Vereinsadressen und Ansprechpartner, Wieder öffnen bis Meldeschluss, PDF. |

## Gestaltung

Die Oberfläche folgt der Website des KSV (ksv-fallingbostel.de), damit Vereine sie als
Teil derselben Seite erkennen:

- **Farben:** KSV-Grün `#2f6806` (Knöpfe, aktiver Tab, Titelbalken, obere Leiste),
  Dunkelgrün `#1b3a0a` (Fuß), heller Grund `#f4f6f2`. Alle Werte stehen als
  `--kmm-*`-Variablen am Anfang von `assets/frontend/app.css`; der Dunkelmodus setzt
  dieselben Variablen neu.
- **Schrift:** Roboto wie auf der Website, als eigene Datei
  `assets/fonts/roboto-latin.woff2` (31 KB, Latin und Latin Extended-A, Stärken 400–700).
  Es wird nichts von fremden Servern geladen. Lizenz: SIL Open Font License,
  `assets/fonts/OFL.txt`.
- **Kopf und Fuß:** grüne Leiste, weißer Kopf mit dem Website-Icon der WordPress-Seite
  als Logo (fehlt es, steht ein neutrales Zeichen), dunkelgrüner Fuß mit Links auf
  Website, Impressum (Seite mit dem Slug `impressum`) und Datenschutzerklärung
  (WordPress-Einstellung) – die beiden letzten nur, wenn es die Seiten gibt.
- **Bewegung:** Knöpfe geben beim Drücken nach (`scale(.97)`, 140 ms), Dialog und Toast
  blenden in höchstens 200 ms ein. Tabwechsel und alles, was viele Male hintereinander
  passiert, bleibt ohne Animation. `prefers-reduced-motion` lässt Farbwechsel stehen und
  nimmt nur die Bewegung weg.
- **Handy:** Hover-Zustände gelten nur mit Maus (`hover: hover` und `pointer: fine`),
  Eingabefelder haben mindestens 16 px Schrift (sonst zoomt iOS beim Antippen), Knöpfe
  reagieren ohne Tippverzögerung, Abstände berücksichtigen Notch und Home-Leiste.

### Eigene Seite oder im Rahmen der Website

Standard ist die eigene Seite (`templates/frontend/layout.php`): Kopf und Fuß stammen vom
Plugin, auf der Meldeseite läuft nur Plugin-Code. Mit der Einstellung **„Portal im Rahmen
der Website anzeigen“** (`portal_im_theme`, Standard aus) kommen Kopf, Menü und Fuß vom
Theme (`templates/frontend/layout-theme.php`, `get_header()`/`get_footer()`).

- Adresse, Zugangslinks, Sitzung und die Cache-Sperre des Routers bleiben gleich; Titel
  und `noindex` laufen im Rahmen über `pre_get_document_title` und `wp_robots`.
- Im Rahmen beendet der Router die Anfrage nicht selbst. Der Inhalt wird gemerkt und
  `layout-theme.php` über den Filter `template_include` (Priorität 1000) als Vorlage
  eingehängt, sodass die Seite den normalen Weg von WordPress durchläuft. Das ist nötig:
  Divi hängt den Theme-Builder-Kopf und -Fuß erst in `template_include` (Priorität 99)
  ein, und WordPress setzt seine Block-Styles erst beim Laden der Vorlage zusammen. Ein
  direkter Aufruf von `get_header()` davor liefert Divis Standard-Kopf ohne Styles.
- Das Stylesheet hängt vollständig an `.kmm` – als eigene Seite am `<body>`, im Rahmen am
  Hüllelement. Elementregeln stehen als `.kmm :where(…)`: Sie setzen sich gegen die
  Grundregeln eines Themes durch, berühren aber weder Kopf noch Fuß der Website.
- Der Dunkelmodus gilt nur für die eigene Seite; im Rahmen einer hellen Website bliebe
  das Portal sonst ein dunkler Block.
- **Nachteil:** Im Rahmen laufen auch die Skripte des Themes und anderer Plugins auf der
  Seite mit Vereinssitzung und Schützendaten, und die Seite lädt deren Stylesheets mit.
  Ein Theme-Update kann die Darstellung verändern. Deshalb abschaltbar.
- Geprüft wurde gegen ein klassisches Theme mit den Stylesheets von ksv-fallingbostel.de
  (Divi, Bootstrap, Download-Manager), das seinen Kopf wie Divi erst in `template_include`
  einhängt: Die berechneten Stile aller Portal-Elemente stimmen mit der eigenen Seite
  überein. Die Skripte von Divi lassen sich nur auf der echten Website prüfen – siehe
  `docs/INSTALLATION.md`.

## Status und Schreibrecht

- Status `offen` (nichts erfasst) → `entwurf` (erste Änderung) → `eingereicht`; Wieder öffnen → `entwurf`.
- Schreibbar nur in der Meldephase (Meldebeginn bis Meldeschluss des aktiven Sportjahres)
  oder mit Nachmeldungs-Freischaltung (Phase 2). Nach dem Meldeschluss ist alles schreibgeschützt
  (`Application\Meldephase`). Eingereichte Meldungen sind bis zum Wieder-Öffnen schreibgeschützt.
- Jede Änderung wird im Änderungsprotokoll festgehalten (Akteur `verein`).

## REST-Endpunkte `kmm/v1`

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/status`, `/meldung` | vollständiger Zustand (Zusammenfassung) |
| GET/POST/PUT/DELETE | `/schuetzen`, `/schuetzen/{id}` | Schützenliste |
| GET | `/schuetzen/{id}/angebot` | Disziplinen mit Startrecht und Para-Optionen |
| POST/PUT/DELETE | `/meldung/einzel`, `/meldung/einzel/{id}` | Einzelmeldung anlegen, Ergebnis/Nicht-Meldung/Para ändern, entfernen |
| POST | `/meldung/einzel/{id}/bestaetigen` | Konflikt nach Regeländerung bestätigen |
| GET | `/meldung/mannschaften/kandidaten?disziplin_id=&mannschaft_id=` | passende Schützen |
| POST/PUT/DELETE | `/meldung/mannschaften`, `/meldung/mannschaften/{id}` | Mannschaften |
| PUT | `/meldung/ansprechpartner` | Ansprechpartner |
| POST | `/meldung/einreichen`, `/meldung/oeffnen` | Status |

Jeder Endpunkt prüft die Sitzung (Cookie) im `permission_callback`; schreibende Methoden
zusätzlich den Header `X-KMM-Token` (HMAC aus Sitzungs-ID und WordPress-Salt). Alle
Services laden Datensätze nur über den Verein der Sitzung; fremde IDs ergeben „nicht gefunden“.

## Tests

- `tests/Integration/MeldungServiceTest.php`: Validierung, Meldeablauf, Mannschaften, Einreichen,
  Wieder öffnen, Schreibschutz nach Meldeschluss, MixTeam, Para, Fremdzugriff.
- Browser-Test (Playwright, nicht im Repo): Anmeldung, Schützen, Melden, Ergebnis, Mannschaft,
  Einreichen, PDF – wurde für Meilenstein 6 in Chromium (400 px und 1200 px) durchgespielt.

## Admin-Modus (Phase 2)

Backend-Benutzer mit dem Recht „Meldungen bearbeiten“ öffnen dieselbe Oberfläche unter
`/km-meldung/admin/<Verein-ID>/` (Link „Bearbeiten“ in der Übersicht) – auch nach
Meldeschluss. Details: `docs/AENDERUNGEN-NACH-MELDESCHLUSS.md`.
