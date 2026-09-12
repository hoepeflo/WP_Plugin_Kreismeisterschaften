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

## Meilenstein 8: Freigabe und Buchung (Konzept 12.4)

### Freigabe (`StartplanService::freigeben`)

Voraussetzung: mindestens eine Einheit und ein Durchgang, jeder Durchgang mit Zulassung;
Referenten nur, wenn alle Durchgänge in ihrem Bereich liegen. Status → `freigegeben`,
`freigegeben_am`; alle Vereine mit passenden Startern erhalten eine Mail mit ihrem
Zugangslink (`templates/mail/freigabe.php`, `Mailer::TYP_FREIGABE`). Zurücknehmen ist nur
ohne Buchungen möglich. Erinnerung: `erinnerung_am` = Buchungsfrist − Einstellung
`buchung_erinnerung_tage`; der stündliche Cron (`StartplanService::cron_erinnerung`) mailt
Vereine mit Startern ohne Platz einmal (`erinnerung_gesendet_am` wird vor dem Versand
gesetzt).

### Sichtbarkeit für Vereine

Tab **„5 Startplätze“** erscheint erst, wenn mindestens ein freigegebener (oder
veröffentlichter) Wettkampftag passende Starter des Vereins hat (`BuchungService::tage`).
Buchbar ist ein Tag im Status `freigegeben` bis zur Buchungsfrist; danach und nach der
Veröffentlichung nur noch über den KSV (Admin-Modus).

### Eindeutigkeit und gleichzeitige Zugriffe (Datenbankebene)

- `kmm_buchung` hat zwei UNIQUE-Schlüssel: `(durchgang_id, einheit_id, position)` – ein
  Platz, eine Buchung – und `(einzelmeldung_id)` – eine Meldung, ein Platz.
- **Neubuchung = ein INSERT** (`BuchungRepository::platz_buchen`), ohne Sperre, ohne
  vorheriges Lesen. Gleichzeitige Klicks entscheidet die Datenbank: der Verlierer erhält
  den Duplikatfehler 1062, es wird nichts geschrieben, der Server antwortet „Der Platz
  wurde gerade von einem anderen Verein gebucht“, das Raster lädt neu.
- **Umbuchung = ein UPDATE** der bestehenden Zeile (`BuchungRepository::umbuchen`). Ist
  der Zielplatz belegt, scheitert das UPDATE an UNIQUE(platz) und die alte Buchung bleibt
  unverändert – der Verein verliert nie seinen Platz. (Ein „erst neu einfügen, dann alt
  löschen“ wäre wegen UNIQUE(einzelmeldung_id) nicht möglich.)
- Fachliche Prüfungen vor dem Schreiben (`BuchungService::buchen`): Tag freigegeben und
  Frist offen, Meldung gehört dem Verein und ist buchbar, Durchgang lässt sie zu, Einheit
  erlaubt die Disziplin, Position ≤ Kapazität, Schütze steht in keinem zeitlich
  überschneidenden Durchgang (über alle Disziplinen und Tage, `BuchungRepository::
  by_schuetze`). Die Überschneidung wird nach dem Schreiben erneut geprüft; im
  Konfliktfall (zwei gleichzeitige eigene Klicks) wird die eigene Buchung zurückgenommen.
- Keine Zeitsperren; das Raster fragt alle `buchung_raster_intervall` Sekunden den Stand ab.
- Fremde Buchungen erscheinen vor der Veröffentlichung nur als „belegt“ ohne Namen.

### REST (`kmm/v1`, Vereinssitzung oder Admin-Modus)

| Endpunkt | Zweck |
|---|---|
| `GET /startplan` | sichtbare Tage mit Zahl der Meldungen mit/ohne Platz |
| `GET /startplan/{tag}` | Raster: Einheiten, Durchgänge (Zeit, Zulassungen, Belegung), eigene Meldungen |
| `POST /startplan/{tag}/buchen` | `{durchgang_id, einheit_id, position, einzelmeldung_id}` – buchen oder umbuchen |
| `DELETE /startplan/buchung/{id}?tag=` | eigenen Platz freigeben |

Im Admin-Modus (`BuchungService` mit `admin = true`) gelten Frist und Status nicht;
Zuteilungen durch den KSV werden als Änderung „Startplan“ erfasst (Sammelmail).

Tests: `tests/Integration/BuchungTest.php` (Freigabe und Mails, Buchen/Umbuchen/Freigeben,
fremde Plätze ohne Namen, Überschneidung über Disziplinen, gleichzeitige Buchung desselben
Platzes – nur einer gewinnt, Umbuchen auf belegten Platz lässt die alte Buchung, Frist
sperrt Vereine, Admin darf weiter, Erinnerung ohne Doppelversand).
