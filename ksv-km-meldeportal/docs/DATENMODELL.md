# Datenmodell

Alle Tabellen tragen das WordPress-Präfix plus `kmm_` (z. B. `wp_kmm_klasse`). Sie werden
über `dbDelta()` angelegt und migriert; die Schema-Version steht in
`KSV\KMM\Infrastructure\Database\Schema::VERSION` und in der Option `kmm_schema_version`.
Zeitstempel sind UTC. Status- und Typfelder sind `varchar` mit Konstanten im PHP-Code
(keine ENUMs, weil dbDelta damit schlecht umgeht). Fremdschlüssel werden nicht als
Constraint angelegt (dbDelta kann das nicht), sondern in den Repositories geprüft.

Legende: **fett** = Pflicht/Schlüssel, *kursiv* = für Phase 2 vorgesehen (Feld vorhanden, keine Logik in Phase 1).

## Stammdaten (versioniert pro Sportjahr)

### `kmm_sportjahr`

| Spalte | Bedeutung |
|---|---|
| **jahr** | Sportjahr, eindeutig (Alter = Sportjahr − Geburtsjahr) |
| bezeichnung | z. B. „KM 2027" |
| ist_aktiv | genau ein Sportjahr ist aktiv (Meldephase, Vereinsoberfläche) |
| meldung_beginn, meldeschluss | Meldephase; Magic Links gelten bis zum Meldeschluss |
| erinnerung_am, erinnerung_gesendet_am | Zeitpunkt der Erinnerungsmail und Versandvermerk |
| regeln_geaendert_am | letzte Regeländerung (Auslöser der Revalidierung) |
| *abgeschlossen_am, anonymisiert_am* | Abschluss des Sportjahres und Anonymisierung |

### `kmm_wettbewerbsgruppe`

Gruppe mit eigenem Klassensatz (Freihand, Auflage, FITASC, Lichtschießen, Blasrohr, Bogen, Para).

| Spalte | Bedeutung |
|---|---|
| **sportjahr_id, code** | eindeutig je Sportjahr (`freihand`, `auflage`, …) |
| hoehermeldung_bereich | `uebrige` / `auflage` / `bogen` / NULL (Para): welcher Höhermeldungsbereich gilt |
| ist_para | Klassen werden gewählt statt berechnet |

### `kmm_klasse`

| Spalte | Bedeutung |
|---|---|
| **gruppe_id, nummer, geschlecht** | Klassennummer, nur innerhalb der Gruppe eindeutig (22 = Schüler II beim Lichtschießen, Schüler B beim Bogen). MixTeam-Teamklassen tragen dieselbe Nummer wie Junioren I (40) bzw. Herren I (10), aber Geschlecht `x`; deshalb gehört das Geschlecht zum Schlüssel. |
| bezeichnung | „Herren II", „Schüler w", „Team Junioren" |
| geschlecht | `m`, `w` oder `x` (beide; gemischte Team- und Para-Klassen) |
| alter_von, alter_bis | Alter im Sportjahr, NULL = offen; Para-Klassen ohne Alter |
| tarifstufe | `schueler` / `jugend` / `erwachsene` (Konzept 11.3) |
| stufe | gruppenübergreifende Klassenstufe für Höhermeldungen, z. B. `hd1` für Herren/Damen I in Freihand **und** Blasrohr, `sen1` für Auflage Senioren I. Eine Höhermeldung speichert die Zielstufe; die Engine sucht in der Gruppe der gemeldeten Disziplin die Klasse mit passender Stufe und passendem Geschlecht. |
| ist_para | Para-Klasse (wählbar) |
| ist_teamklasse | MixTeam-Teamklasse („Team Junioren", „Team Damen/Herren"), Ziel von Mannschaftsverweisen |
| festgeschrieben | Schüler- und Jugendklassen: keine Höhermeldung möglich |
| hinweis | z. B. „nur bis LV" |

### `kmm_disziplin`

| Spalte | Bedeutung |
|---|---|
| **sportjahr_id, kennzahl** | z. B. `1.10`, `1.56S`, `12.10` (Blasrohr) |
| gruppe_id | Wettbewerbsgruppe |
| typ | `normal` / `mixteam` / `bogen` |
| angeboten | bei der KM angeboten |
| mannschaft_groesse | Standard 3, MixTeam 2, Bogen 0 (keine Mannschaften) |
| ergebnis_format | `ganz` / `zehntel` |
| tarif_override | abweichender Personen-Tarif (z. B. Lichtschießen 2,50), NULL = Tarifstufe der Startklasse |
| mannschaft_startgeld | pro Mannschaft; MixTeams nur hierüber |
| mixteam_kennzahl_modus | `team` / `geschlecht`: welche Klasse in die DAVID-Kennzahl kommt (offener Punkt, Einstellung je Disziplin) |
| hinweis | Freitext „Sonstiges" aus dem Plan, nur Anzeige |

### `kmm_regel`

Eine Zeile je Disziplin × Klasse. Fehlt die Zeile, gilt „kein Startrecht".

| Spalte | Bedeutung |
|---|---|
| **disziplin_id, klasse_id** | eindeutig |
| einzel_modus, einzel_ziel_klasse_id | `keine` / `eigen` / `verweis` (+ Zielklasse) |
| mannschaft_modus, mannschaft_ziel_klasse_id | `keine` / `eigen` / `verweis` (+ Zielklasse). Gemischte Schüler-/Jugendmannschaften: die w-Klasse verweist auf die m-Klasse (oder umgekehrt), dadurch entsteht ein gemeinsamer Pool. |
| mindestalter | z. B. 18 („ab 18 Jahre" bei Vorderlader Junioren II), geprüft mit vollem Geburtsdatum |
| hinweis | Anzeige-Text („bei Junioren", „E **") |
| quelle | Herkunft: `import`, `seed`, `manuell` |

Geschlechtsabhängige Verweise (`m40/w11`) brauchen kein eigenes Feld: Die Quellklasse ist
bereits geschlechtsspezifisch (42 → 40, 43 → 11).

### `kmm_startgeld_tarif`

`sportjahr_id` + `tarifstufe` → `betrag` (Standard 3,00 / 5,00 / 7,00 €).

## Vereine und Zugang

| Tabelle | Inhalt |
|---|---|
| `kmm_verein` | name, **vn_nummer** (5-stellig, eindeutig), ist_aktiv, notiz |
| `kmm_verein_email` | mehrere Adressen je Verein, eindeutig je Verein |
| `kmm_magic_link` | verein_id, sportjahr_id, **token_hash** (SHA-256 des 32-Byte-Tokens, Vergleich mit `hash_equals`), gueltig_bis (= Meldeschluss), widerrufen_am („Neu senden"), verwendungen, anlass (`admin`, `alle`, `anfrage`) |
| `kmm_sitzung` | Sitzung nach dem Tausch Token → Cookie: verein_id, magic_link_id, **token_hash**, gueltig_bis, letzte_aktivitaet_am, beendet_am. Wird der Magic Link widerrufen, enden auch seine Sitzungen. |

Rate-Limits für „Link anfordern" laufen über Transients (`kmm_rl_*`), nicht über Tabellen.

## Schützen und Meldungen

### `kmm_schuetze` (jahresübergreifend)

nachname, vorname, geburtsdatum (volles Datum), geschlecht (`m`/`w`), mitgliedsnummer
(9-stellig, Warnung wenn nicht mit VN-Nummer beginnend), zuletzt_gemeldet_jahr (Löschregel).
Keine Para-Klasse hier (Gesundheitsdatum, nur an der Meldung).

### `kmm_hoehermeldung`

schuetze_id, sportjahr_id, bereich (`uebrige` / `auflage` / `bogen`), ziel_stufe. Eindeutig
je Schütze, Jahr und Bereich; gilt nur im jeweiligen Sportjahr.

### `kmm_meldung` (Vereinsmeldung je Sportjahr)

| Spalte | Bedeutung |
|---|---|
| **verein_id, sportjahr_id** | eindeutig |
| status | `offen` / `entwurf` / `eingereicht` (Phase 2: `verarbeitet` ergibt sich aus den Einzelmeldungen) |
| ansprechpartner_name/-email/-telefon | Pflicht beim Einreichen |
| eingereicht_am, wieder_geoeffnet_am | Statuswechsel |
| startgeld_summe | Vorschau zum Zeitpunkt des Einreichens |
| *nachmeldung_bis* | Nachmeldungs-Freischaltung durch den Admin |
| *letzte_sammelmail_am* | tägliche Sammelmail |

### `kmm_einzelmeldung` (Schütze × Disziplin)

| Spalte | Bedeutung |
|---|---|
| **meldung_id, schuetze_id, disziplin_id** | eindeutig; `schuetze_id` wird bei der Anonymisierung auf NULL gesetzt |
| sportjahr_id, verein_id | redundant für Auswertungen und Statistik nach Anonymisierung |
| geschlecht, geburtsjahr | Kopie aus der Schützenliste; `geburtsjahr` wird bei der Anonymisierung gelöscht, `geschlecht` bleibt |
| klasse_id | eigentliche (berechnete) Klasse |
| startklasse_id | Einzel-Startklasse nach Auflösen der Verweise, NULL bei MixTeam oder ohne Startrecht |
| mannschaft_klasse_id | Mannschaftspool nach Auflösen der Verweise, NULL wenn keine Mannschaft |
| para_klasse_id | vom Verein gewählte Para-Klasse; nach der KM gelöscht |
| hoehermeldung_angewendet | Kennzeichen für Anzeige und Protokoll |
| startrecht | 0, wenn die Regeltabelle (nach Änderung) kein Startrecht mehr ergibt |
| meldeergebnis | decimal(6,1); ganze Ringe als x,0 |
| nicht_meldung | Checkbox „Nicht-Meldung" (Bedeutung offen, per Einstellung ausblendbar) |
| mannschaft_id | Zuordnung zur Mannschaft |
| startgeld | berechneter Betrag (Snapshot, bei Revalidierung neu) |
| konflikt, konflikt_text | Markierung aus der Revalidierung |
| *startgeld_berechnen* | Schalter bei Abmeldungen |
| *verarbeitungsstatus, verarbeitungsgrund, verarbeitet_am* | `ungeprueft` / `verarbeitet` / `nicht_startberechtigt` |
| *abgemeldet_am* | Abmeldung nach Meldeschluss |
| *ergebnis_ref* | Verknüpfung zum Ergebnis-Plugin (z. B. Datei-ID in `srd_kreis_v3`) |

### `kmm_mannschaft`

meldung_id, sportjahr_id, verein_id, disziplin_id, klasse_id (Pool), **nummer** (je Verein und
Disziplin fortlaufend, eindeutig über alle Mannschaftsklassen), startgeld, *unvollstaendig*.
Mitglieder über `kmm_einzelmeldung.mannschaft_id`.

## Protokoll, Exporte, Mails

| Tabelle | Inhalt |
|---|---|
| `kmm_protokoll` | wer (akteur_typ `verein`/`admin`/`system`, akteur_id, akteur_name), wann, was (aktion, objekt_typ, objekt_id, zusammenfassung, details als JSON). Personenbezogene Details werden bei der Anonymisierung entfernt. Keine IP-Adressen. |
| `kmm_export` | jeder Export mit Zeitstempel, Typ (`david_csv`, `pdf_liste`, *`beleg`*), Umfang (disziplin_id/gruppe_id/parameter), Zeilenzahl, Ersteller. Grundlage für „Änderungen seit Export". |
| `kmm_mail_log` | Typ, Betreff, Empfängeranzahl, Erfolg, Fehler. Keine Adressen (kein PII in Logs). |

## Phase 2 (Schema-Version 3, rein additiv)

Alle Ergänzungen haben Standardwerte; bestehende Daten werden nicht umgeschrieben.
Neue Spalten: `einzelmeldung.abmeldegrund`, `einzelmeldung.nachgemeldet`, `sportjahr.abschluss_backup`.

| Tabelle | Inhalt |
|---|---|
| `kmm_aenderung` | Änderungen nach Meldeschluss je Verein: typ (`status`, `abmeldung`, `nachmeldung`, `korrektur`, `mannschaft`, `startplan`), Text, Details (JSON), erstellt_am, versendet_am (Sammelmail). Grundlage der täglichen Sammelmail (idempotent über `versendet_am`) und der Liste „Änderungen seit Export“. |
| `kmm_beleg` | Buchhaltungsbelege je Verein: Summe, Positionen (JSON-Snapshot), Dateiname, Hinweis „ungeprüfte Meldungen“, Ersteller, Zeitpunkt. |
| `kmm_referent` | WordPress-Benutzer (user_id) mit Einzelrechten darf_status, darf_meldungen, darf_startplan. |
| `kmm_referent_zustaendigkeit` | Zuständigkeit: typ `gruppe` (Code) oder `disziplin` (Kennzahl); sportjahrübergreifend über den Schlüssel. |
| `kmm_wettkampftag` | Datum, Bezeichnung, Ort, Buchungsfrist, Status (`entwurf` / `freigegeben` / `veroeffentlicht`), Zeitpunkte, ausgeblendet_am (Abschluss), beitrag_id (Kalenderbeitrag), ergebnis_url (Ergebnis-Plugin), Erinnerung. |
| `kmm_einheit` | Stand/Scheibe/Rotte je Wettkampftag: Bezeichnung, Kapazität (Positionen), optional `disziplin_ids` (leer = alle). |
| `kmm_durchgang` | Nummer, Bezeichnung, Beginn, Ende je Wettkampftag. |
| `kmm_durchgang_zulassung` | zugelassene Kombination Disziplin × Startklasse (NULL = alle Startklassen der Disziplin). |
| `kmm_buchung` | Platz = Durchgang × Einheit × Position ↔ Einzelmeldung. **UNIQUE (durchgang_id, einheit_id, position)** und **UNIQUE (einzelmeldung_id)**: die Datenbank erzwingt, dass ein Platz nur einmal und eine Meldung nur einmal gebucht ist. gebucht_von_typ `verein` / `admin` / `system`. |

Rolle `kmm_referent` (Capabilities `kmm_view`, `kmm_referent`) wird bei Aktivierung und
Migration angelegt.

## Schema-Version 4 (rein additiv)

Neue Spalten mit Standardwert NULL: `wettkampftag.schiessstand_id`, `einheit.standgruppe_id`,
`einheit.nummer`.

| Tabelle | Inhalt |
|---|---|
| `kmm_schiessstand` | Schießstand als sportjahrübergreifende Stammdaten: Bezeichnung, Ort, Notiz, Reihenfolge. |
| `kmm_standgruppe` | Standgruppe eines Schießstands: Bezeichnung („10 m Stände“), Name je Einheit (`praefix`, z. B. „Stand“), Anzahl, erste Nummer, Kapazität je Einheit, optional Disziplin-Kennzahlen (z. B. Auflagetische). Beim Wettkampftag werden angekreuzte Stände zu Einheiten (`einheit.standgruppe_id` + `nummer`). |

## Optionen

| Option | Inhalt |
|---|---|
| `kmm_schema_version` | Schema-Version |
| `kmm_settings` | Einstellungen (siehe `KSV\KMM\Support\Settings::defaults()`), inkl. offener Punkte aus Konzept 13 |
