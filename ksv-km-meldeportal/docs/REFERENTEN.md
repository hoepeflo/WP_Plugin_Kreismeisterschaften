# Referenten (Phase 2, Meilenstein 6)

Konzept 10: Neben dem Admin gibt es Referenten über eine eigene WordPress-Rolle
(„KM-Referent“, `kmm_referent`). Jedem Referenten werden Disziplinen oder
Wettbewerbsgruppen zugewiesen; er sieht nur diese. Lesen und PDF-Listen sind immer
erlaubt, weitere Rechte schaltet der Admin je Person frei.

## Einrichtung

1. WordPress → Benutzer → Neu: Benutzer mit Rolle **KM-Referent** anlegen (Rolle wird bei
   Aktivierung/Migration angelegt, `Auth\Capabilities::add_to_roles`).
2. KM-Portal → **Referenten** (nur Admin): Benutzer wählen, Wettbewerbsgruppen
   (Checkboxen, Zuordnung über den Gruppencode) und/oder einzelne Disziplinen
   (Kennzahlen) zuweisen, Rechte setzen:
   - **Verarbeitungsstatus setzen** (`darf_status`): Startrechtsprüfung, Sammelaktionen
     – nur im eigenen Bereich.
   - **Meldungen bearbeiten** (`darf_meldungen`): Admin-Modus der Vereinsoberfläche
     (Nachmeldung, Abmeldung, Korrektur, Mannschaften) – nur Disziplinen des eigenen
     Bereichs; fremde Zeilen sind sichtbar, aber schreibgeschützt. Die
     Nachmeldungs-Freischaltung eines Vereins bleibt Administratoren vorbehalten.
   - **Startplan bearbeiten** (`darf_startplan`): Meilensteine 7–9.
   Zuständigkeiten gelten sportjahrübergreifend (Schlüssel = Code bzw. Kennzahl).
3. „Entfernen“ löscht nur den Referenten-Eintrag; der WordPress-Benutzer bleibt.

## Was ein Referent sieht

| Seite | Referent |
|---|---|
| Übersicht | Zahlen und Details nur aus dem eigenen Bereich; Linkversand, Erinnerung, Sammelmail nur Admin |
| Verarbeitung | nur eigene Disziplinen; Status setzen nur mit Recht |
| Export | nur „PDF-Meldelisten“ (Umfang auf den eigenen Bereich begrenzt); DAVID-Export, Änderungsliste, Exportprotokoll nur Admin |
| Protokoll | lesend |
| Sportjahre, Vereine, Referenten, Stammdaten, Import/Export, Belege, Einstellungen, System | nicht sichtbar (`kmm_manage`) |

## Serverseitige Prüfung (`Auth\Rechte`)

Die Navigation ist nur Komfort; jede Aktion prüft selbst:

- `Rechte::darf_lesen()` – Backend-Zugang (Admin oder eingetragener Referent mit `kmm_view`).
- `Rechte::hat_recht(RECHT_…)` – Einzelrecht (Admin immer true).
- `Rechte::zustaendig(Disziplin)` / `zustaendige([...])` – Zuständigkeitsbereich.
- `VerarbeitungService` (Status), `MeldungService` im Admin-Modus
  (`zustaendig_pruefen` in allen schreibenden Methoden, `angebot` gefiltert,
  Flag `zustaendig` je Zeile), `PdfMeldelisten::erzeugen` (Zeilen gefiltert),
  `Http\AdminModus::erlaubt()` (Admin-Modus nur mit `darf_meldungen`),
  `ExportPage` (DAVID nur `Rechte::nur_admin()`).

## Tests

`tests/Integration/ReferentTest.php` (Verwaltung: Rolle, Doppel-Eintrag, Zuständigkeit
Pflicht; Referent ohne Schreibrecht, fremde Disziplin serverseitig abgelehnt,
Freischaltung nur Admin, Angebot gefiltert; PDF-Listen nur eigener Bereich) und
`tests/Integration/VerarbeitungTest.php` (Status ohne Recht / fremde Disziplin).
