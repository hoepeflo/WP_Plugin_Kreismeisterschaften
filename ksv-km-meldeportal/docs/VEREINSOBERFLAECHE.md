# Vereinsoberfläche

Route `/km-meldung/` (ohne Theme, nie gecacht), Zugang per Magic Link (`docs/ZUGANG.md`).
Das Seitengerüst wird serverseitig gerendert (`templates/frontend/app.php`), der Inhalt
von `assets/frontend/app.js` (Vanilla JS, kein Build-Schritt) aus dem eingebetteten
Anfangszustand und den REST-Endpunkten. Mobil zuerst; ab 700 px Breite Tabellenansicht.

## Schritte

| Schritt | Inhalt |
|---|---|
| 1 Schützen | Schützenliste des Vereins (bleibt über Jahre): Name, Vorname, Geburtsdatum, Geschlecht, Mitgliedsnummer (Pflicht, 9 Ziffern; Warnung ohne Sperre, wenn sie nicht mit der VN-Nummer beginnt), Höhermeldungen je Bereich (nur angebotene Stufen, nur dieses Sportjahr). Löschen nur ohne Meldungen. |
| 2 Meldung | Schütze wählen → Disziplinen mit Startrecht, gruppiert nach Gewehr/Pistole/Flinte/Vorderlader/Auflage/…; angezeigt werden Klasse, Startklasse, Kennzahl, Mannschaftspool, Startgeld, Hinweise; Para-Klasse wählbar, wenn die Disziplin Para-Regeln hat. Tabelle der gemeldeten Starter mit Meldeergebnis (Format je Disziplin, fehlende Ergebnisse gelb), optional Checkbox „Nicht-Meldung“, Mannschaftsnummer, Konflikte mit Bestätigen/Entfernen. |
| 3 Mannschaften | Je Disziplin mit Mannschaftswertung: Mannschaften anlegen/bearbeiten/auflösen. Wählbar sind nur Schützen derselben Mannschaftsklasse ohne andere Mannschaft; MixTeam genau 1 m + 1 w. Nummern je Verein und Disziplin fortlaufend. |
| 4 Prüfen & Einreichen | Ansprechpartner (Pflicht), Startgeldvorschau (vorläufig), Prüfliste (Fehler blockieren, Warnungen nicht), Einreichen mit Bestätigungsmail an Vereinsadressen und Ansprechpartner, Wieder öffnen bis Meldeschluss, PDF. |

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
