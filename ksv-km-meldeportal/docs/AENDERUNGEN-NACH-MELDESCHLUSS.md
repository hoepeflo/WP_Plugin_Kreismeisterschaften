# Änderungen und Abmeldungen nach Meldeschluss (Phase 2, Meilenstein 3)

Nach dem Meldeschluss ist die Vereinsoberfläche für die Vereine schreibgeschützt
(`Application\Meldephase`). Änderungen laufen dann über den KSV – in **derselben
Vereinsoberfläche im Admin-Modus** – oder über eine zeitlich begrenzte
**Nachmeldungs-Freischaltung** je Verein.

## Admin-Modus der Vereinsoberfläche

- Route: `/km-meldung/admin/<Verein-ID>/?sportjahr=<ID>` (Link „Bearbeiten“ in der
  Backend-Übersicht). PDF: `/km-meldung/admin/<Verein-ID>/pdf/`.
- Zugang: WordPress-Login und Recht `darf_meldungen` (`Auth\Rechte::RECHT_MELDUNGEN`,
  Administratoren immer). Ohne Login → Weiterleitung zum WP-Login; ohne Recht → 403.
  Kein Magic Link, keine Vereinssitzung.
- REST: dieselben Endpunkte unter `kmm/v1`; der Browser sendet `X-WP-Nonce` (WordPress-
  REST-Nonce = CSRF-Schutz) sowie `X-KMM-Verein` und `X-KMM-Sportjahr`. Der
  `permission_callback` (`Http\RestApi::permission`) prüft Login, Recht und Existenz von
  Verein/Sportjahr; alle Handler arbeiten dann mit diesem Verein (`Http\AdminModus`).
- `Application\MeldungService` im Admin-Modus (`admin = true`): keine Phasen- und
  Statusprüfung (nur „Sportjahr abgeschlossen“ sperrt), Einreichen/Wieder öffnen
  bleiben Vereinssache. Protokolleinträge laufen als Admin-Aktion mit Vereinsvermerk.

Sichtbar im Admin-Modus (für Vereine nicht vorhanden): Abmelden / Abmeldung aufheben je
Zeile, Schalter „Startgeld berechnen“ bei abgemeldeten Meldungen, Tab 4 „Ansprechpartner &
Nachmeldung“ mit der Freischaltung.

## Nachmeldung und Korrektur

- Neue Einzelmeldungen nach Meldeschluss erhalten `nachgemeldet = 1` (Anzeige
  „Nachmeldung“ in Portal, Backend-Details, Listen) – egal ob durch den KSV oder durch
  den Verein während einer Freischaltung.
- Jede Änderung nach Meldeschluss wird in `kmm_aenderung` erfasst
  (`Application\Aenderungen::erfassen`, Typen `Domain\AenderungTyp`): Nachmeldung,
  Korrektur (Ergebnis, Nicht-Meldung, Para-Klasse, Löschen), Mannschaft, Abmeldung,
  Status (Meilenstein 2). Die Warteschlange speist die tägliche Sammelmail
  (Meilenstein 4, `versendet_am`) und die Liste „Änderungen seit dem letzten
  DAVID21-Export“ auf der Export-Seite (`ExportRepository::letzter` +
  `AenderungRepository::seit`).
- Während der Meldephase erfasste Admin-Bearbeitungen sind keine „Änderungen nach
  Meldeschluss“ und landen nicht in der Warteschlange.

## Abmeldung (Konzept 12.2)

- `MeldungService::abmelden(id, grund, startgeld_berechnen|null)`: setzt
  `abgemeldet_am`, `abmeldegrund`, `startgeld_berechnen`; gibt einen gebuchten
  Startplatz frei (`kmm_buchung` gelöscht); prüft die Mannschaft neu
  (`VerarbeitungService::mannschaft_pruefen` → `unvollstaendig`).
- Standard des Schalters (`Application\Abmeldung::startgeld_standard`): **vor
  Veröffentlichung** des Startplans des betreffenden Wettkampftags → kein Startgeld,
  **danach** → Startgeld. „Betreffend“ ist der Wettkampftag des gebuchten Platzes, sonst
  ein veröffentlichter Wettkampftag mit passender Durchgangs-Zulassung (Disziplin +
  Startklasse). Der Schalter ist in beide Richtungen übersteuerbar
  (`startgeld_schalter`).
- Abgemeldete Meldungen bleiben sichtbar (durchgestrichen, Grund, Zeitpunkt), fallen
  aus Export und Meldelisten heraus und zählen in der Startgeldsumme nur, wenn
  „Startgeld berechnen“ gesetzt ist. `abmeldung_aufheben` macht die Meldung wieder
  aktiv (ein freigegebener Platz wird nicht automatisch neu gebucht).

## Nachmeldungs-Freischaltung

`MeldungService::nachmeldung_freischalten(bis)` setzt `kmm_meldung.nachmeldung_bis`.
Bis zu diesem Zeitpunkt ist die Meldung für den Verein wieder schreibbar
(Phase `nachmeldung`, Hinweis im Portal und in der Backend-Übersicht). Eine
eingereichte Meldung muss der Verein wie gewohnt „wieder öffnen“ und erneut
einreichen. Leerer Wert beendet die Freischaltung.

## Tests

`tests/Integration/AbmeldungTest.php`: Verein nach Meldeschluss gesperrt, Admin-
Nachmeldung wird markiert und erfasst; Abmeldung vor Veröffentlichung ohne Startgeld,
Platz frei, Mannschaft unvollständig, Aufheben; Abmeldung nach Veröffentlichung mit
Startgeld und Übersteuern; Abgemeldete fehlen im Export; „Änderungen seit Export“;
Nachmeldungs-Freischaltung öffnet den Verein zeitweise.
