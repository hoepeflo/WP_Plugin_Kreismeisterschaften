# Startplan (Phase 2, Meilensteine 7–9)

## Meilenstein 7: Aufbau eines Wettkampftags (Konzept 12.3)

Backend → **Wettkampftage** (lesen: alle Backend-Benutzer; schreiben: Recht „Startplan
bearbeiten“, Admin immer).

| Ebene | Tabelle | Inhalt |
|---|---|---|
| Wettkampftag | `kmm_wettkampftag` | Datum, Bezeichnung, Schießstand/Ort, Buchungsfrist, Hinweis, optional Kalenderbeitrag (ID), Status `entwurf` → `freigegeben` → `veroeffentlicht` |
| Einheit | `kmm_einheit` | Stand / Scheibe / Rotte: Bezeichnung, Kapazität (Positionen), optional Beschränkung auf Disziplinen (z. B. Auflagetisch), Reihenfolge |
| Durchgang | `kmm_durchgang` | Nummer, Bezeichnung, Beginn, Ende (am Datum des Tages) |
| Zulassung | `kmm_durchgang_zulassung` | Disziplin × Startklasse (NULL = alle Startklassen der Disziplin); mehrere je Durchgang |
| Platz | – | Durchgang × Einheit × Position (Buchung in `kmm_buchung`, Meilenstein 8) |

`Application\StartplanService`:

- Validierung: gültiges Datum; Einheit mit Bezeichnung und Kapazität 1–200; Durchgang mit
  Ende nach Beginn und Beginn am Wettkampftag; Startklasse einer Zulassung muss in der
  Disziplin eine Klasse mit eigener Wertung sein (`startklassen()`).
- Überlappende Durchgänge sind erlaubt (parallele Einheiten); der Konflikt „ein Schütze
  nicht in zwei überlappenden Durchgängen“ wird bei der Buchung je Schütze geprüft
  (Meilenstein 8).
- Einheiten, Durchgänge und Wettkampftage mit Buchungen können nicht gelöscht werden; die
  Kapazität einer Einheit kann nicht unter eine gebuchte Position sinken.
- `uebersicht(tag)`: je Durchgang Plätze (Summe der Kapazitäten der Einheiten, die die
  zugelassenen Disziplinen erlauben), **Bedarf** (buchbare Meldungen, die zu einer
  Zulassung passen: Vereinsmeldung eingereicht, Startrecht, kein Konflikt, nicht abgemeldet,
  nicht „nicht startberechtigt“) und gebuchte Plätze. Bedarf > Plätze wird rot markiert.
- Referenten mit „Startplan bearbeiten“: Zulassungen nur für Disziplinen des eigenen
  Bereichs; Durchgänge mit fremden Zulassungen sind für sie schreibgeschützt; Wettkampftag
  löschen nur Admin.

Neue Wettkampftage stehen im Status **Entwurf**: Vereine sehen nichts, der Standard
„Startgeld bei Abmeldung“ bleibt „nein“, bis der Tag veröffentlicht ist.

Tests: `tests/Integration/StartplanTest.php`.
