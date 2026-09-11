# KM-Meldeportal KSV Fallingbostel – Konzept

Stand: 11.09.2026 · Status: abgestimmter Entwurf, offene Punkte siehe Abschnitt 12

## 1. Ziel und Rahmen

Die Vereine des KSV Fallingbostel melden ihre Starter zur Kreisverbandsmeisterschaft künftig über eine Webanwendung statt über ausfüllbare PDFs, Excel-Tabellen oder handschriftliche Zettel. Die Anwendung prüft Startrechte und berechnet Klassen automatisch, lässt die Vereine Mannschaften bilden und erzeugt einen Import für DAVID21 Sport sowie PDF-Meldelisten. In einer zweiten Phase nach dem Meldeschluss unterstützt sie die Startrechtsprüfung und die Startplanung: Die Vereine buchen Startplätze selbst, der fertige Startplan wird auf der KSV-Webseite veröffentlicht.

| Eckdaten | |
|---|---|
| Veranstaltung | Kreisverbandsmeisterschaft, Februar 2027 (Sportjahr 2027) |
| Meldephase | ab Mitte/Ende Oktober 2026 |
| Meldeschluss | 10.01.2027 |
| Nutzer | ausschließlich KSV Fallingbostel, keine Mandantenfähigkeit |
| Lebensdauer Export | DAVID21 wird voraussichtlich in 4–5 Jahren abgelöst |

## 2. Technische Basis

Umsetzung als WordPress-Plugin in der bestehenden Installation von ksv-fallingbostel.de. Performance ist bei der erwarteten Last (einige Dutzend Vereine, einige hundert Starter) kein Thema. Die Anwendung wird konsequent von WordPress abgegrenzt:

| Regel | Begründung |
|---|---|
| Eigene Datenbanktabellen, keine Posts/Meta | sauberes Datenmodell, einfache Löschung |
| Vereine sind keine WP-Benutzer | Magic Link führt auf eigene Route, nie ins wp-admin |
| Meldeoberfläche ohne Divi, über REST-Endpunkte des Plugins | unabhängig von Theme-Updates |
| Meldeseiten vom Seitencache ausgeschlossen | sonst Gefahr, dass Verein A die Seite von Verein B sieht |
| Mailversand über Domain mit SPF/DKIM | Magic Links dürfen nicht im Spam landen |

Der DAVID-Export ist ein austauschbarer Baustein. Regeltabelle, Meldungen und Mannschaften kennen DAVID nicht; nur der Exporter übersetzt ins CSV-Format.

## 3. Umfang

Enthalten sind Luftdruck- und Feuerwaffendisziplinen Gewehr und Pistole, Flinte einschließlich FITASC, Vorderlader, Auflage, Lichtschießen, Blasrohr, Para, MixTeams sowie Bogen. Bogen wird gemeldet und geprüft wie alle anderen Bereiche, erhält aber keinen DAVID-Export und keine Mannschaftsbildung, sondern nur PDF-Meldelisten für die Referentin.

Nicht enthalten sind Target Sprint, Sommerbiathlon, Laufende Scheibe und Armbrust (einschließlich Armbrust 10m Auflage 5.11).

Quellen: NSSV 01A1 Disziplinplan 2026 (xlsx, Stand 17.02.2026), NSSV 01A2 Jahrgangsklassen nach DSB, NSSV 06A1 Disziplinplan Bogen 2026, DSB-Sportordnung.

## 4. Klassen und Klassenberechnung

### 4.1 Wettbewerbsgruppen

Jede Disziplin gehört zu genau einer Wettbewerbsgruppe, jede Gruppe hat einen eigenen Klassensatz. Klassennummern sind nur innerhalb einer Gruppe eindeutig (22/23 ist beim Lichtschießen Schüler II, beim Bogen Schüler B). Eine Klasse ist daher immer Gruppe + Nummer, mit den Attributen Bezeichnung, Geschlecht (m / w / beide) und Alter von–bis.

| Gruppe | Klassen (Nummer, Alter im Sportjahr) |
|---|---|
| Freihand | Schüler 20/21 (≤ 14), Jugend 30/31 (15–16), Junioren II 42/43 (17–18), Junioren I 40/41 (19–20), Herren/Damen I 10/11 (21–40), II 12/13 (41–50), III 14/15 (51–60), IV 16/17 (61–70), V 18/19 (≥ 71) |
| Auflage | Senioren 0 50/51 (41–50, nur bis LV), I 70/71 (51–60), II 72/73 (61–65), III 74/75 (66–70), IV 76/77 (71–75), V 78/79 (76–80), VI 80/81 (≥ 81) |
| FITASC | Junioren 68 (15–20), Herren 60 (21–55), Damen 61 (≥ 21), Senioren 62 (56–65), Veteranen 64 (66–72), Master 66 (≥ 73) |
| Lichtschießen | Schüler IV 26/27 (≤ 8 lt. SpO), III 24/25 (9–10), II 22/23 (11–12), I 20/21 (13–14); Mindestalter 6 |
| Blasrohr | Schüler III 24/25 (≤ 10 lt. SpO), II 22/23 (11–12), I 20/21 (13–14), Jugend, Junioren II/I und Herren/Damen I–V wie Freihand |
| Bogen | Schüler C 24/25 (≤ 10), B 22/23 (11–12), A 20/21 (13–14), Jugend 30/31 (15–17), Junioren 40/41 (18–20), Herren/Damen 10/11 (21–49), Master 12/13 (50–65), Senioren 14/15 (≥ 66) |
| Para | 90 SH2/AB2 m/w mit HM, 92 SH1/AB1 m ohne HM, 93 SH1/AB1 w ohne HM, 94 AB3 m/w mit HM, 96 SH3 m/w ohne HM |

Abweichungen zwischen NSSV-Plan und Sportordnung werden zugunsten der Sportordnung aufgelöst (Lichtschießen Schüler IV: Plan „< 8“, SpO „≤ 8“; Blasrohr Schüler III: Plan „7–10“, SpO „≤ 10“).

### 4.2 Berechnung

Alter = Sportjahr − Geburtsjahr. Die Klasse ergibt sich aus Alter und Geschlecht innerhalb der Gruppe der gemeldeten Disziplin. Die Klasse gehört damit zur einzelnen Meldung, nicht zum Schützen: Ein 45-Jähriger ist freihand Herren II, in der Auflage Senioren 0.

Ausnahme Para: Die Klasse wird nicht berechnet, sondern bei der Meldung vom Verein gewählt. AB1/SH1-Schützen mit Wechselerklärung werden regulär in der Freihandklasse gemeldet; dafür ist keine eigene Abbildung nötig.

### 4.3 Höhermeldung

Laut SpO 0.7.1.1 wird eine Höhermeldung zu Beginn des Sportjahres erklärt und gilt das ganze Jahr, getrennt für Bogen, Auflage und die übrigen Wettbewerbe. Schüler- und Jugendklassen sind festgeschrieben. Pro Schütze gibt es daher höchstens eine Höhermeldung je dieser drei Bereiche; sie übersteuert die berechnete Klasse. Höhermeldungen gelten nur für das jeweilige Sportjahr: Beim Anlegen eines neuen Sportjahres werden sie zurückgesetzt und müssen vom Verein bei Bedarf neu gesetzt werden.

## 5. Regeltabelle

### 5.1 Aufbau

Pro Sportjahr eine Version. Für jede Kombination aus Disziplin und Klasse:

| Feld | Werte |
|---|---|
| Einzel | kein Startrecht · eigene Wertung · startet in Klasse X |
| Mannschaft | keine · eigene Wertung · startet in Klasse X |
| Zusatz | z. B. „ab 18 Jahre“, Hinweistext zur Anzeige |

Verweise können geschlechtsabhängig sein (im Plan z. B. „m40/w11“). In Schüler- und Jugendklassen gibt es je Disziplin nur eine gemeinsame Mannschaftsspalte für m und w, die Mannschaften sind dort gemischt.

Pro Disziplin zusätzlich: Wettbewerbsgruppe, Kennzahl, Schalter „bei der KM angeboten“, Mannschaftsgröße (Standard 3, MixTeam 2), Typ (normal / MixTeam / Bogen), Ergebnisformat (ganze Ringe / Zehntelringe).

Freitexte aus der Spalte „Sonstiges“ (Visierung, Anschlag, „keine DM“ usw.) werden nur als Hinweis angezeigt, nicht geprüft.

### 5.2 Befüllung und Pflege

Kein automatischer xlsx-Import in der Anwendung. Die xlsx speichert „kein Startrecht“ nur als Zellfarbe (zwei verschiedene Rottöne), schreibt Verweise uneinheitlich („b.10“, „b 10“, „b. 10“, „b10“), enthält Beschriftungsfehler und legt mehrere Tabellen neben- und untereinander auf ein Blatt.

Stattdessen erzeugt ein einmaliges Konvertierungsskript die Version 2026 aus der xlsx; das Ergebnis wird von Hand gegengeprüft. Für 2027 wird die Version kopiert und angepasst, sobald der NSSV-Plan erscheint (der NSSV markiert Änderungen gegenüber dem Vorjahr blau). Im Backend ist die Regeltabelle direkt bearbeitbar.

Bekannte Korrekturen: Blasrohr hat die Kennzahl 12.10 (im Plan fälschlich 1.12, kollidiert dort mit LG MixTeam).

### 5.3 Regeländerung während der Meldephase

Die Klassen für 2027 stehen durch die Jahrgangstabelle bereits fest; ändern kann sich nur die Matrix aus Disziplin und Klasse. Die Meldephase startet deshalb mit der Matrix 2026. Wird die Regeltabelle geändert, prüft die Anwendung alle bestehenden Meldungen neu und markiert Konflikte für Admin und betroffenen Verein.

## 6. Mannschaften

Die Vereine erfassen zuerst alle Einzelmeldungen und fassen danach Schützen zu Mannschaften zusammen. In der Auswahl erscheinen nur Schützen, die in der Disziplin gemeldet sind, deren aufgelöste Mannschaftsklasse übereinstimmt und die noch in keiner anderen Mannschaft dieser Disziplin stehen. Unpassende Kombinationen sind damit gar nicht erst wählbar.

Beispiel 1.10 Luftgewehr: Herren I (M) und Herren II (b.10) bilden gemeinsam eine Mannschaft in Klasse 10; Herren III hat eine eigene Mannschaftswertung.

MixTeams (1.12 LG, 2.12 LP, 3.12 Trap Mix, 3.22 Skeet Mix) sind ein eigener Typ: genau zwei Schützen, ein Mann und eine Frau, beide in derselben Teamklasse. Es gibt nur „Team Junioren“ und „Team Damen/Herren“. Bei LG, LP und Trap Mix starten Jugend und Junioren II bei Team Junioren, Herren/Damen II–V bei Team Damen/Herren. Bei Skeet Mix gibt es nur Team Damen/Herren. Schüler haben kein Startrecht. MixTeam-Disziplinen haben keine Einzelwertung.

Mannschaftsnummern werden pro Verein und Disziplin fortlaufend und eindeutig vergeben, auch über verschiedene Mannschaftsklassen hinweg.

Vorschlag: Einreichen ist nur mit vollständigen Mannschaften möglich.

## 7. Daten und Datenschutz

### 7.1 Vereine

Name, VN-Nummer (5-stellig), eine oder mehrere E-Mail-Adressen. Angelegt durch den Admin.

### 7.2 Schützenliste pro Verein

Jeder Verein pflegt seine eigene Schützenliste, die über die Jahre erhalten bleibt. Im Folgejahr werden Starter nur noch ausgewählt. Zentral werden keine Mitgliederdaten importiert.

| Feld | Hinweis |
|---|---|
| Name, Vorname | Pflicht |
| Geburtsdatum | volles Datum, wegen „ab 18 Jahre“ (Vorderlader Junioren II) und gesetzlicher Mindestalter |
| Geschlecht | m / w, nur intern für die Klassenberechnung |
| Mitgliedsnummer | 9-stellig; Warnung (keine Sperre), wenn sie nicht mit der VN-Nummer des Vereins beginnt, da Schützen mit Zweitverein vermutlich die Nummer ihres Erstvereins tragen |
| Höhermeldungen | optional, je Bogen / Auflage / übrige; gilt nur für das laufende Sportjahr |

Löschregel (Vorschlag): Schützen, die zwei Sportjahre nicht gemeldet wurden, werden gelöscht.

### 7.3 Meldung

Pro Schütze und Disziplin: berechnete Start- und eigentliche Klasse, Meldeergebnis, ggf. Mannschaft, ggf. Nicht-Meldung (siehe offene Punkte), Verarbeitungsstatus (siehe 12.1).

Pro Vereinsmeldung zusätzlich ein Ansprechpartner (Name, E-Mail, optional Telefon), in der Regel der Sportleiter, der die Meldung erstellt hat. Die Angabe ist Pflicht beim Einreichen.

Das Meldeergebnis (Ergebnis der Vereinsmeisterschaft) ist optional, wird aber deutlich eingefordert: Fehlende Ergebnisse werden farbig markiert, vor dem Einreichen erscheint ein Hinweis mit der Anzahl, im Backend ist pro Verein sichtbar, wo Ergebnisse fehlen.

Das Eingabefeld folgt dem Ergebnisformat der Disziplin. Bei ganzen Ringen sind nur ganze Zahlen möglich. Bei Zehntelringen ist genau eine Nachkommastelle erlaubt; Komma und Punkt werden akzeptiert, eine ganze Zahl wird zu „,0“ ergänzt (für Vereine, deren Vereinsmeisterschaft ohne Zehntelwertung geschossen wurde). Das Feld zeigt das erwartete Format als Platzhalter an, z. B. „375“ oder „389,4“.

Die Para-Klasse ist faktisch ein Gesundheitsdatum. Sie wird nicht in der Schützenliste gespeichert, sondern nur an der Meldung, und nach der KM gelöscht. Ein kurzer Datenschutzhinweis in der Anwendung erläutert Zweck, Speicherdauer und die Para-Angabe.

### 7.4 Aufbewahrung und Anonymisierung

Meldungen werden dauerhaft für die Statistik aufbewahrt, aber anonymisiert. Nach Abschluss eines Sportjahres (Button „Sportjahr abschließen“ im Backend, mit Hinweis, falls noch Belege fehlen) werden aus allen Meldungen und dem Änderungsprotokoll dieses Jahres Name, Vorname, Geburtsdatum, Mitgliedsnummer und Ansprechpartner entfernt. Erhalten bleiben Verein, Disziplin, Start- und eigentliche Klasse, Geschlecht, Mannschaftszugehörigkeit und Startgeld. Damit sind Teilnehmerzahlen je Verein, Disziplin, Klasse und Jahr dauerhaft auswertbar. Veröffentlichte Startpläne dieses Jahres werden ausgeblendet. Die Schützenliste der Vereine ist davon unabhängig (Löschregel siehe 7.2).

## 8. Zugang und Status

### 8.1 Magic Link

Jeder Verein erhält per E-Mail einen persönlichen Link. Der Link ist bis zum Meldeschluss gültig. „Neu senden“ im Backend macht den alten Link ungültig. Versand pro Verein oder an alle zum Start der Meldephase. Zusätzlich gibt es eine Seite „Link anfordern“: Wer eine hinterlegte Adresse eingibt, erhält einen neuen Link.

### 8.2 Status pro Verein

| Status | Bedeutung |
|---|---|
| Offen | noch nichts erfasst |
| Entwurf | Meldung begonnen |
| Eingereicht | vom Verein abgeschickt; automatische Bestätigungsmail mit Zusammenfassung |
| Verarbeitet | ergibt sich automatisch, sobald alle Einzelmeldungen des Vereins geprüft sind |

Bis zum Meldeschluss kann der Verein eine eingereichte Meldung wieder öffnen; sie geht dann zurück in den Entwurf und muss erneut eingereicht werden. Nach dem Meldeschluss ist alles für die Vereine schreibgeschützt. Nachmeldungen schaltet der Admin gezielt für einzelne Vereine frei; eine Nachmeldegebühr gibt es nicht. Der Admin hat nach dem Meldeschluss volle Bearbeitungsrechte (siehe 12.2).

Einige Tage vor dem Meldeschluss erhalten Vereine mit Status Offen oder Entwurf automatisch eine Erinnerungsmail.

Alle Mails zu einer Meldung (Bestätigung, Status, Startrecht, Startplan) gehen an die Vereinsadresse(n) und an den Ansprechpartner.

## 9. Vereinsoberfläche

Der Meldeablauf gliedert sich in fünf Schritte: Schützenliste pflegen, Starter und Disziplinen wählen, Meldeergebnisse eintragen, Mannschaften bilden, prüfen und einreichen. Vor dem Einreichen zeigt die Anwendung eine vorläufige Startgeldvorschau (siehe 11.3); sie steht auch in der Bestätigungsmail. Nach dem Meldeschluss sieht der Verein pro Schütze den Verarbeitungsstatus. Pro Schütze werden nur Disziplinen angeboten, für die ein Startrecht besteht; die Klasse wird angezeigt, aber nie vom Verein gewählt (Ausnahme Para). Der Verein kann seine eigene Meldung jederzeit als PDF herunterladen. Die Oberfläche muss auf dem Smartphone bedienbar sein.

## 10. Backend

Das Backend umfasst die Verwaltung von Sportjahr und Meldephase (Beginn, Meldeschluss), die Pflege der Regeltabelle einschließlich Kopie ins Folgejahr, die Startgeldtarife, die Vereinsverwaltung mit Linkversand, eine Statusübersicht aller Vereine mit fehlenden Meldeergebnissen und Konflikten, den DAVID-Export sowie die PDF-Listen. Alle Änderungen durch Vereine und Admin werden in einem Änderungsprotokoll festgehalten (wer, wann, was). In Phase 2 kommen Verarbeitungsstatus mit Sammelaktionen, Änderungen und Abmeldungen nach Meldeschluss, Buchhaltungsbelege, Wettkampftage mit Durchgängen, Restverteilung und Veröffentlichung hinzu. Zugang über den normalen WordPress-Login.

Neben dem Admin gibt es Referenten über eine eigene WordPress-Rolle. Jedem Referenten werden Disziplinen oder Wettbewerbsgruppen zugewiesen; er sieht nur diese. Lesen und PDF-Listen sind immer erlaubt, weitere Rechte schaltet der Admin pro Person frei: Verarbeitungsstatus setzen, Meldungen bearbeiten, Startplan bearbeiten. Regeltabelle, Startgelder, Vereinsverwaltung, DAVID-Export, Belege und der Abschluss des Sportjahres bleiben dem Admin vorbehalten.

## 11. Ausgaben

### 11.1 DAVID21-Export

Eine Zeile pro Schütze und Disziplin, Spalten in dieser Reihenfolge:

| Spalte | Inhalt |
|---|---|
| Kennzahl | Disziplin + aufgelöste Einzel-Startklasse, z. B. 1.22.10 für Herren II in 1.22 |
| Name, Vorname | aus der Schützenliste |
| Verband | laut Muster identisch mit VN-Nummer (zu bestätigen) |
| VN-Nummer, VN-Name | aus den Vereinsdaten |
| Meldeergebnis | im Ergebnisformat der Disziplin, Zehntel mit Dezimalkomma (z. B. 389,4); leer, wenn nicht angegeben |
| Geburtsdatum | TT.MM.JJJJ |
| Mitgliedsnummer | als Text |
| Nicht-Meldung | offen |
| DAS | immer 0 |
| Mannschaft | Mannschaftsnummer oder leer |

Die Mannschaftsklasse wird nicht exportiert; DAVID ordnet Mannschaften anhand seines hinterlegten Disziplinplans selbst zu. Bei MixTeams steuert eine Einstellung pro Disziplin, ob die Kennzahl die Teamklasse oder die Geschlechterklasse erhält. Bogen wird nicht nach DAVID exportiert. Export gesamt oder je Disziplin.

### 11.2 PDF-Meldelisten

Für alle Disziplinen, einschließlich Bogen. Im Backend wählbar sind der Umfang (eine Disziplin, eine Wettbewerbsgruppe oder alles; jede Disziplin beginnt auf einer neuen Seite) und die Gruppierung (nach Verein oder nach Klasse, innerhalb alphabetisch nach Name). Jede Zeile zeigt die Startklasse und klein dahinter die eigentliche Klasse; bei MixTeams ist die Startklasse die Teamklasse. Wo es Mannschaften gibt, steht die Mannschaftsnummer mit auf der Liste.

### 11.3 Startgelder und Buchhaltungsbelege

Startgelder werden pro Sportjahr als Tarif je Tarifstufe hinterlegt. Jede Klasse ist einer Tarifstufe zugeordnet:

| Tarifstufe | Klassen (Beispiele) | Standard |
|---|---|---|
| Schüler | Schüler, Schüler A–C, Lichtschießen Schüler I–IV, Blasrohr Schüler I–III | 3,00 € |
| Jugend/Junioren | Jugend, Junioren I/II, Bogen und FITASC Junioren | 5,00 € |
| Erwachsene/Senioren | Herren/Damen I–V, Auflage Senioren 0–VI, Bogen Master/Senioren, FITASC ab Herren/Damen, Para-Klassen 90–96 | 7,00 € |

Pro Disziplin kann der Tarif abweichend festgelegt werden (derzeit Lichtschießen 2,50 €; eine Vereinheitlichung ist per Einstellung möglich). Maßgeblich ist die **Startklasse**: Ein Junior II, der in 1.30 Zimmerstutzen bei Herren I startet, zahlt den Erwachsenentarif; eine Höhermeldung wirkt ebenso. Zusätzlich gibt es pro Disziplin ein Mannschaftsstartgeld, derzeit überall 0,00 €, jederzeit änderbar. MixTeams werden ausschließlich über das Mannschaftsstartgeld abgerechnet, nicht pro Person.

Die Startgeldvorschau für den Verein ist als vorläufig gekennzeichnet. Für nicht startberechtigte Meldungen entfällt das Startgeld. Bei abgemeldeten Meldungen entscheidet der Schalter „Startgeld berechnen“ (siehe 12.2).

Der Buchhaltungsbeleg ist keine Rechnung, sondern die Grundlage, auf der die Buchhaltung abrechnet. Er wird als PDF pro Verein erzeugt, einzeln oder für alle Vereine gesammelt, und listet nach Disziplin und Klasse jeweils Anzahl × Betrag, danach die Mannschaften (auch mit 0,00 €) und am Ende die Gesamtsumme. Sind beim Erzeugen noch ungeprüfte Meldungen vorhanden, weist das Backend darauf hin.

## 12. Phase 2: Startrechtsprüfung und Startplan

### 12.1 Startrechtsprüfung und Verarbeitungsstatus

Nach dem Meldeschluss erstellt der Admin den DAVID-Export und prüft die Startrechte in der Sportdatenbank (hat der Schütze für diesen Verein in dieser Disziplin ein Startrecht?). Jede Einzelmeldung hat einen Verarbeitungsstatus: ungeprüft, verarbeitet oder nicht startberechtigt, dazu optional ein Grund.

Der Status wird einzeln oder gesammelt gesetzt. Für Sammelaktionen filtert der Admin nach Verein, Disziplin oder Wettbewerbsgruppe (kombinierbar) und markiert „alle ungeprüften als verarbeitet“. Bereits als fehlerhaft markierte Meldungen bleiben dabei unberührt. Typischer Ablauf: in einer Disziplin mit 20 Schützen die drei Fehler einzeln markieren, den Rest mit einem Klick als verarbeitet.

Nicht startberechtigte Meldungen fallen aus Startplan, PDF-Listen und Beleg heraus. Wird dadurch eine Mannschaft unvollständig, wird sie für den Admin markiert.

Die Vereine werden nicht bei jedem Klick benachrichtigt. Einmal täglich zu einer festen Uhrzeit erhält jeder Verein, bei dem sich seit der letzten Mail etwas geändert hat, eine Sammelmail mit allen Änderungen und Gründen sowie seinem Link. Damit der tägliche Versand zuverlässig läuft, wird WP-Cron über einen echten Server-Cronjob angestoßen, statt auf Seitenaufrufe zu warten.

### 12.2 Änderungen und Abmeldungen nach Meldeschluss

Nach dem Meldeschluss kann der Admin alle Meldungen bearbeiten: Nachmeldungen, Korrekturen (z. B. Tippfehler, falsche Disziplin) und Änderungen an Mannschaften (z. B. Ersatzschützen). Da der DAVID-Export zu diesem Zeitpunkt bereits erfolgt ist, zeigt das Backend eine Liste „Änderungen seit dem letzten Export“ mit allen Nachmeldungen, Korrekturen und Abmeldungen, damit DAVID nachgepflegt werden kann.

Abmeldungen nach dem Meldeschluss trägt ausschließlich der Admin im Backend ein; der Verein meldet sie formlos. Die Einzelmeldung wird als „abgemeldet“ markiert, der Zeitpunkt wird gespeichert. Zusätzlich hat jede Meldung einen Schalter „Startgeld berechnen“.

Standard: Liegt die Abmeldung vor der Veröffentlichung des Startplans für den betreffenden Wettkampftag, entfällt das Startgeld; liegt sie danach, wird es berechnet. Der Admin kann den Schalter bei jeder Meldung in beide Richtungen übersteuern.

Ein gebuchter Startplatz wird durch die Abmeldung wieder frei. Wird eine Mannschaft dadurch unvollständig, wird sie für den Admin markiert. Auf dem Beleg erscheint die Meldung mit dem Vermerk „abgemeldet“ und dem jeweiligen Betrag (ggf. 0,00 €). Die Abmeldung erscheint in der Liste „Änderungen seit dem letzten Export“ und ist Teil der täglichen Sammelmail an den Verein.

### 12.3 Aufbau eines Wettkampftags

Der Startplan gilt für alle Disziplinen einschließlich Bogen und Flinte. Kein Import in DAVID; Ausgabe nur im Web und als PDF.

| Ebene | Inhalt |
|---|---|
| Wettkampftag | Datum, Schießstand/Ort, Buchungsfrist, optional verknüpfter Kalenderbeitrag |
| Einheiten | Stände, Scheiben oder Rotten mit frei wählbarer Bezeichnung und Kapazität (Stand: 1, Bogenscheibe: z. B. 4 Positionen, Flinte-Rotte: z. B. 6) |
| Durchgang | Beginn, Ende, zugelassene Kombinationen aus Disziplin und Startklassen; mehrere Disziplinen pro Durchgang möglich |
| Platz | Durchgang × Einheit × Position |

Optional lassen sich einzelne Einheiten auf bestimmte Disziplinen beschränken, etwa Stände mit Auflagetischen.

### 12.4 Freigabe und Buchung

Mit der Freigabe eines Wettkampftags erhalten alle Vereine eine Mail mit ihrem Link, die startberechtigte Starter für die dort zugelassenen Disziplinen und Klassen gemeldet haben.

Der Verein sieht ein Raster aus Durchgängen und Einheiten, wählt einen Schützen und tippt auf einen freien Platz. Angeboten werden nur Schützen, deren Meldung zum Durchgang passt. Jede Meldung (Schütze × Disziplin) erhält genau einen Platz. Ein Schütze kann nicht in zwei Durchgängen stehen, die sich zeitlich überschneiden, auch nicht über Disziplinen hinweg. Bis zur Frist kann der Verein umbuchen und Plätze wieder freigeben.

Doppelbuchungen verhindert die Sofortbuchung: Jeder Klick wird einzeln gespeichert, die Datenbank lässt pro Platz und pro Meldung nur eine Buchung zu, und das Raster aktualisiert sich alle paar Sekunden. Ist ein anderer Verein schneller, scheitert nur dieser eine Klick; alle bisherigen Buchungen bleiben erhalten. Zeitsperren gibt es nicht, damit keine Plätze durch abgebrochene Sitzungen blockiert werden.

Vorschlag: Einige Tage vor Fristende geht eine Erinnerungsmail an Vereine, die noch Starter ohne Platz haben.

### 12.5 Nach der Frist

Die Buchung wird gesperrt. „Rest verteilen“ setzt alle startberechtigten Meldungen ohne Platz automatisch auf freie, passende Plätze. Danach kann der Admin Starter verschieben und tauschen und den Startplan veröffentlichen. Änderungen nach der Veröffentlichung sind möglich und werden sofort sichtbar.

### 12.6 Veröffentlichung

Ein Shortcode pro Wettkampftag (optional gefiltert nach Disziplin) lässt sich in jeden Beitrag setzen, auch in die bestehenden Kalendertermine, unabhängig vom Kalender-Plugin. Er zeigt erst nach der Veröffentlichung Inhalte. Öffentlich sind nur Name, Vorname, Verein, Startklasse, Einheit/Position und Uhrzeit. Zusätzlich gibt es einen PDF-Startplan, sortiert nach Durchgang und Einheit, etwa für Aushang und Standaufsicht. Der Datenschutzhinweis erwähnt die Veröffentlichung.

Die Verknüpfung mit dem bestehenden Ergebnis-Plugin (Link vom Startplan zu den Ergebnissen und umgekehrt) folgt später.

## 13. Offene Punkte

| Punkt | Klärung durch |
|---|---|
| Kennzahl bei MixTeams in DAVID: Team- oder Geschlechterklasse | Florian |
| Bedeutung der Spalte „Nicht-Meldung“ (vermutlich: nicht zur LM weitermelden → Checkbox pro Einzelmeldung) | Florian |
| FITASC: startet eine Schützin ab 56 in Damen (61) oder Senioren (62)? | FITASC-Tabelle der SpO |
| SpO 2027: Änderungen in Teil 6, 9 und 11 auf Klassen und Startrechte prüfen | Florian |
| Kennzahlen mit Buchstaben (1.56S, 1.58 O/G, 2.03 F) im DAVID-Format | Testimport |
| CSV-Format: Trennzeichen, Zeichensatz (Umlaute), Feld „Verband“ | Testimport |
| Ergebnisformat je Disziplin (ganze Ringe / Zehntelringe) | Florian |
| Export ganzer Ringe: „375“ oder „375,0“? | Testimport |
| Löschfrist Schützenliste (Vorschlag: zwei Sportjahre ohne Meldung) | Florian |

## 14. Zeitplan

| Zeitraum | Meilenstein |
|---|---|
| bis Ende September 2026 | Regeltabelle 2026 per Skript aufbereiten und gegenprüfen, Plugin-Grundgerüst |
| bis Mitte Oktober 2026 | Meldung, Mannschaften, Startgeldvorschau, Export, PDF-Listen |
| Mitte Oktober 2026 | Testlauf mit SV Vorwalsrode inklusive Import in DAVID |
| Ende Oktober 2026 | Links an alle Vereine, Start der Meldephase |
| November/Dezember 2026 | NSSV-Plan 2027 einpflegen, sobald veröffentlicht; Revalidierung; Entwicklung Phase 2 (Verarbeitungsstatus, Belege, Startplan, Veröffentlichung) |
| 10.01.2027 | Meldeschluss |
| ab 11.01.2027 | DAVID-Export, Startrechtsprüfung, Belege, Wettkampftage anlegen |
| ca. 20.01.2027 | Freigabe der Startpläne, Buchung durch die Vereine |
| ca. 31.01.2027 | Buchungsfrist; danach Restverteilung und Veröffentlichung |
| Februar 2027 | Kreisverbandsmeisterschaft |
