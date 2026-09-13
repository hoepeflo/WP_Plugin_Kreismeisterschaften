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

### Schießstände als Stammdaten (Schema 4)

Backend → **Schießstände** (nur Admin): ein Ort mit Standgruppen, z. B. „10 m Stände“
12 × „Stand“ ab 1 mit Kapazität 1, „Bogen“ 6 × „Scheibe“ mit Kapazität 4, „50 m Auflage“
mit Disziplinbeschränkung. Beim Wettkampftag den Schießstand wählen (Ort wird
übernommen, wenn leer) und im Block „Stände freigeben“ ankreuzen, welche Stände an diesem
Tag zur Verfügung stehen („alle“ / „keine“ je Gruppe). `StartplanService::
einheiten_aus_schiessstand` legt fehlende Einheiten an (`standgruppe_id`, `nummer`) und
entfernt abgewählte ohne Buchungen; von Hand angelegte Einheiten bleiben unberührt.
Standgruppen mit freigegebenen Einheiten und Schießstände mit Wettkampftagen sind gegen
Löschen geschützt. Tests: `tests/Integration/SchiessstandTest.php`.

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

## Meilenstein 9: Nach der Buchungsfrist (Konzept 12.5)

Mit dem Ablauf der Buchungsfrist schließt sich die Buchung für die Vereine
(`BuchungService::buchung_offen`). Ab dann arbeitet der KSV im Backend unter
**Wettkampftage → Wettkampftag → „Nach der Buchungsfrist“**.

### Rest verteilen (`Startplatzvergabe::restverteilung`)

Setzt alle buchbaren Meldungen ohne Platz auf freie, passende Plätze. Vor dem Lauf zeigt
die Seite eine Vorschau (`restverteilung($tag, true)` schreibt nichts) mit der Zahl der
Starter, die einen Platz bekämen, und listet die Starter ohne Platz.

- **Reihenfolge:** Disziplin, dann Verein, dann Name. Dieselbe Ausgangslage liefert damit
  immer dasselbe Ergebnis, und Starter eines Vereins stehen möglichst beieinander.
- **Platzsuche:** erster Durchgang, dessen Zulassung zur Meldung passt und der sich nicht
  mit einem anderen Start dieses Schützen überschneidet; darin die erste Einheit, die die
  Disziplin erlaubt, und die erste freie Position.
- Das Verfahren ist bewusst einfach und nicht optimierend: Es füllt der Reihe nach auf und
  sucht nicht die bestmögliche Gesamtverteilung. Wer keinen Platz bekommt, steht mit Grund
  in der Liste und lässt sich von Hand setzen.
- **Schreiben:** je Zuteilung ein einzelner INSERT (`platz_buchen`), wie bei der
  Vereinsbuchung. Ein zwischenzeitlich belegter Platz führt nicht zum Abbruch, sondern zum
  nächsten freien Platz. Jede Zuteilung wird als Änderung „Startplan“ erfasst und läuft
  damit in die tägliche Sammelmail an den Verein.
- Vor der Buchungsfrist wird die Restverteilung abgelehnt.

### Der Startplan als Matrix im Backend (`Startplatzvergabe::matrix`)

Am Wettkampftag steht der ganze Plan als Raster: Zeilen sind die Durchgänge, Spalten die
Plätze. Anders als die öffentliche Ansicht zeigt die Matrix **auch die freien Plätze** –
sonst wüsste man beim Verschieben nicht, wohin. Plätze, die in einem Durchgang gar nicht
in Frage kommen (Stand auf andere Disziplinen beschränkt, Durchgang ohne Zulassung), sind
grau und nicht anklickbar.

### Verschieben und Tauschen (`Startplatzvergabe::verschieben`)

In der Matrix geht das auf zwei Wegen, die beide dieselbe Prüfung durchlaufen:

- **Ziehen und Fallenlassen** mit der Maus (HTML5-Drag-and-drop).
- **Zwei Klicks**: erst der Starter, dann der Zielplatz. Funktioniert auch am Tablet und
  mit der Tastatur (die belegten Felder sind fokussierbar, Eingabetaste wählt aus). Ist
  ein Starter gewählt, heben sich die freien Plätze hervor; Escape bricht ab.

Beides schickt einen einzelnen Aufruf an `admin-ajax.php`
(`wp_ajax_kmm_startplan_verschieben` → `WettkampftagePage::ajax_verschieben`, abgesichert
über `check_ajax_referer` und `Rechte::RECHT_STARTPLAN`). Die Antwort ist JSON; das Skript
tauscht die beiden Felder im Raster, die Seite lädt nicht neu. Schlägt die Prüfung fehl,
bleibt alles unverändert und der Grund steht unter der Matrix.

Ohne JavaScript bleibt die Matrix eine reine Ansicht. Verschoben wird dann über die
aufklappbare Liste **Alle Buchungen als Liste** darunter: je Zeile ein Auswahlfeld mit
allen Plätzen des Tages („frei“ oder mit Namen), dazu ein Formular ohne JavaScript.

Ist der Zielplatz frei, wird verschoben (ein UPDATE); ist er belegt,
tauschen beide Starter die Plätze.

Der Tausch läuft in einer Transaktion über die Parkposition 0, weil `UNIQUE(durchgang_id,
einheit_id, position)` einen direkten Ringtausch nicht zulässt: A → Position 0, B → Platz
von A, A → Platz von B. Geprüft werden für **beide** Buchungen Zulassung des Durchgangs,
Disziplinbeschränkung der Einheit, Kapazität, Zuständigkeit des Referenten und
Zeitüberschneidungen des Schützen.

### Veröffentlichen (`StartplanService::veroeffentlichen`)

Status `freigegeben` → `veroeffentlicht`, `veroeffentlicht_am` wird gesetzt. Voraussetzung:
mindestens eine Buchung und Zuständigkeit für alle Durchgänge. Auf Wunsch geht eine Mail an
alle Vereine mit Startern (`templates/mail/startplan.php`, `Mailer::TYP_STARTPLAN`).

Ab der Veröffentlichung buchen die Vereine nicht mehr selbst; Änderungen des KSV wirken
sofort. Im Buchungsraster der Vereine erscheinen jetzt auch fremde Starter mit Namen und
Verein statt nur „belegt“. Die Veröffentlichung lässt sich zurücknehmen
(`veroeffentlichung_zuruecknehmen`).

Tests: `tests/Integration/RestverteilungTest.php`.

## Meilenstein 10: Veröffentlichung (Konzept 12.6)

### Darstellung: Raster statt Liste

Der veröffentlichte Startplan sieht aus wie ein Stundenplan, so wie die Startpläne auf
Papier seit jeher aussehen – die Vereine kennen das Format:

- **Zeilen** sind die Durchgänge: Beginn groß, Ende und Durchgangsnummer klein darunter.
- **Spalten** sind die Plätze. Eine Spalte je Einheit; hat eine Einheit mehrere Positionen
  (Bogenscheibe, Flintenrotte), bekommt jede Position eine eigene Spalte („Scheibe A / 2“).
  Es erscheinen nur Plätze, die auch belegt sind – bei einem Filter nach Disziplin wird die
  Tabelle also automatisch schmaler.
- **Zelle**: Verein klein darüber, Name fett, darunter das Unterscheidende.
- **Disziplin und Klasse stehen nur dort, wo sie unterscheiden.** `StartplanAnsicht::plan`
  liefert dazu `disziplinen` und `klassen` – alles, was an diesem Tag vorkommt:
  - **Eine** Disziplin am Tag → sie steht einmal in der Kopfzeile, nicht in jeder Zelle.
  - **Mehrere** Disziplinen → die Kennzahl (z. B. `2.11`) steht an jedem Startplatz, und
    unter der Kopfzeile werden die Kennzahlen aufgeschlüsselt („Disziplinen: 1.11 LG
    Auflage · 2.11 LP Auflage“), damit sie lesbar bleiben.
  - Die **Startklasse steht immer** am Startplatz. An einem Wettkampftag unterscheiden
    sich die Klassen praktisch immer, und sei es nur männlich/weiblich.
  - Ein Filter nach Disziplin greift hier mit: bleibt nur eine übrig, wandert sie in die
    Kopfzeile und die Zellen werden schmaler.
  Eine Farblegende gibt es nicht; für die Klassen der SpO wären drei Farben ohnehin zu
  wenig, und im Feld steht die Klasse ausgeschrieben.
- **Unter jedem Plan** steht: „Startplätze können untereinander getauscht werden. Ein
  Hinweis am Wettkampftag an das Personal vor Ort genügt.“ (`Shortcode::HINWEIS`)

### Shortcode `[kmm_startplan]`

In jeden Beitrag oder jede Seite setzbar, auch in bestehende Kalendertermine, unabhängig
vom Kalender-Plugin.

| Attribut | Bedeutung |
|---|---|
| `tag` | ID des Wettkampftags; leer = alle veröffentlichten Tage |
| `disziplin` | Kennzahl als Filter, z. B. `1.10` |
| `sportjahr` | ID; ohne `tag` als Eingrenzung |
| `titel` | `nein` blendet die Überschrift aus |

Vor der Veröffentlichung erscheint nur der Satz „Der Startplan ist noch nicht
veröffentlicht.“ Öffentlich sind ausschließlich Name, Vorname, Verein, Startklasse,
Einheit/Position und Uhrzeit (`StartplanAnsicht::plan`) – kein Geburtsdatum, keine
Mitgliedsnummer, kein Meldeergebnis, kein Startgeld. Abgemeldete Starter fallen heraus.

Das Stylesheet ist bewusst klein und inline, damit der Plan sich in jedes Theme einfügt.
Ab zehn Spalten schaltet die Tabelle auf eine kompaktere Schrift. Passt sie trotzdem nicht
in die Inhaltsspalte des Themes, lässt sie sich seitlich verschieben; ein kleines Skript
blendet dann den Hinweis darauf ein (ohne JavaScript bleibt er verborgen). Unter 700 px
wird aus jeder Rasterzeile eine Karte: Durchgang als Überschrift, darunter Stand für Stand
ein Eintrag; leere Plätze entfallen.

### PDF-Startplan

`PdfStartplan` erzeugt dasselbe Raster als PDF im **Querformat** (A4 quer, schmale Ränder),
für Aushang und Standaufsicht. Knopf „Startplan als PDF“ am Wettkampftag. Ab zehn Spalten
wird die Schrift eine Stufe kleiner, damit zwölf Stände – das übliche Maximum in der
Gegend – in eine Zeile passen. Ein noch nicht veröffentlichter Tag lässt sich als Vorschau
drucken und trägt dann den Vermerk „Entwurf“.

Tests: `tests/Integration/AbschlussTest.php`.
