# Ausgaben: DAVID21-Export und PDF-Meldelisten

Backend „KM-Portal → Export“. Grundlage sind die neutralen Meldezeilen aus
`Application\ExportService::zeilen()`: Meldungen mit Startrecht, ohne offenen Konflikt,
nicht abgemeldet, standardmäßig nur aus **eingereichten** Vereinsmeldungen (Entwürfe
lassen sich zuschalten). Jeder Export wird in `kmm_export` mit Zeitstempel, Umfang,
Zeilenzahl und Benutzer protokolliert (Grundlage für „Änderungen seit Export“ in Phase 2)
und erscheint im Änderungsprotokoll.

## DAVID21-CSV

Exporter `Infrastructure\Export\David21Exporter` hinter `Domain\Export\ExporterInterface`;
kein anderer Teil des Codes kennt DAVID. Spalten wie im Muster:

| Spalte | Inhalt |
|---|---|
| Kennzahl | Disziplin + Nummer der Einzel-Startklasse (1.22.10); MixTeam je nach Einstellung der Disziplin Team- oder Geschlechterklasse |
| Name, Vorname | aus der Schützenliste |
| Verband | = VN-Nummer (Einstellung: VN-Nummer / leer / fester Wert) |
| VN-Nummer, VN-Name | Verein |
| Meldeergebnis | Zehntel mit Dezimalkomma (389,4); ganze Ringe als 375 oder 375,0 (Einstellung); leer wenn nicht angegeben |
| Geburtsdatum | TT.MM.JJJJ |
| Mitgliedsnummer | 9 Ziffern als Text |
| Nicht-Meldung | 0/1 (Checkbox der Einzelmeldung) |
| DAS | 0 |
| Mannschaft | Mannschaftsnummer je Verein und Disziplin oder leer |

Einstellungen (bis der Testimport sie klärt): Trennzeichen (`;`, `,`, Tab), Zeichensatz
(Windows-1252, UTF-8, UTF-8 mit BOM), Kopfzeile, ganze Ringe, Feld „Verband“. Zeilenende
CRLF, Felder mit Trennzeichen oder Anführungszeichen werden in Anführungszeichen gesetzt.
Bogen wird nicht exportiert. Umfang: gesamt oder eine Disziplin. Dateiname
`david21-km<Jahr>-<alle|Kennzahl>.csv`. Sortierung: Kennzahl, Startklasse, Verein, Name.

Kennzahlen mit Buchstaben (1.56S, 1.58 O, 2.03 F) werden unverändert übernommen
(1.56S.10); ob DAVID sie so erwartet, klärt der Testimport.

## PDF-Meldelisten

`Application\PdfMeldelisten` (mPDF, A4 quer, `templates/pdf/meldelisten.php`), auch für Bogen.

- Umfang: alle Disziplinen, eine Wettbewerbsgruppe oder eine Disziplin; jede Disziplin
  beginnt auf einer neuen Seite.
- Gruppierung nach Verein oder nach Startklasse; innerhalb alphabetisch nach Name.
- Spalten: Kennzahl, Name, Startklasse (bzw. Verein) mit eigentlicher Klasse in Klammern,
  Jahrgang, Mitgliedsnummer, Ergebnis, Mannschaftsnummer (bei Klassengruppierung mit
  VN-Nummer), Hinweis (Höhermeldung, Para-Klasse, Entwurf).
- Dateiname `meldeliste-km<Jahr>-<alle|Gruppe|Kennzahl>-nach-<verein|klasse>.pdf`.
