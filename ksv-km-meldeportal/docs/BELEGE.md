# Buchhaltungsbelege (Phase 2, Meilenstein 5)

Konzept 11.3: Der Beleg ist keine Rechnung, sondern die Grundlage, auf der die
Buchhaltung des KSV mit dem Verein abrechnet. Nur Administratoren (Backend → Belege).

## Inhalt eines Belegs (`Application\BelegService::positionen`)

1. **Einzelstarts** nach Disziplin und Startklasse: Anzahl × Betrag = Summe, Namen als
   Kleindruck. Maßgeblich ist das gespeicherte Startgeld der Einzelmeldung (Startklasse,
   Disziplin-Tarif).
   - Nicht startberechtigte Meldungen fehlen (Zahl im Fußtext).
   - Abgemeldete Meldungen stehen als eigene Zeile mit Vermerk „abgemeldet“ und dem
     Betrag laut Schalter „Startgeld berechnen“ (sonst 0,00 €).
   - Meldungen ohne Startrecht oder mit offenem Konflikt fehlen (Zahl im Fußtext).
   - MixTeam-Einzelzeilen fehlen; MixTeams laufen nur über das Mannschaftsstartgeld.
2. **Mannschaften** je Disziplin: Anzahl × Mannschaftsstartgeld (auch 0,00 €),
   unvollständige Mannschaften werden gezählt und vermerkt.
3. **Gesamtsumme**.

Sind beim Erzeugen noch ungeprüfte Meldungen vorhanden, warnt die Backend-Seite, und
der Beleg trägt einen Hinweiskasten (`kmm_beleg.ungeprueft_hinweis`).

## Erzeugen und Aufbewahren

- **Einzeln**: Button „Beleg erzeugen“ je Verein → PDF-Download.
- **Alle**: „Belege für alle Vereine erzeugen (ein PDF)“ – ein PDF, je Verein eine
  Seite; standardmäßig nur eingereichte/verarbeitete Meldungen, Entwürfe per Schalter.
- Jeder Beleg erhält eine Nummer `<Jahr>-<VN>-<lfd. Nr.>` (z. B. `2027-12345-02`) und wird
  mit allen Positionen als JSON-Snapshot in `kmm_beleg` gespeichert (Summe, Dateiname,
  Hinweis, Ersteller, Zeitpunkt). „PDF“ in der Liste gibt genau diesen Snapshot wieder,
  auch wenn sich die Meldung seitdem geändert hat; die Seite zeigt dann „Summe seit Beleg
  geändert“.
- Nach Abschluss des Sportjahres können Belege nur noch heruntergeladen werden.
  (Der Abschluss des Sportjahres weist auf Vereine ohne Beleg hin, siehe `docs/ABSCHLUSS.md`.)
- Protokoll: Admin-Eintrag `beleg.erzeugen` mit Nummer, Summe und Hinweis.

Rendering: `templates/pdf/beleg.php` über mPDF (`Application\Pdf`).

## Tests

`tests/Integration/BelegTest.php`: Gruppierung nach Disziplin/Startklasse, Mannschaft
mit 0,00 €, nicht startberechtigt fällt heraus und Mannschaft wird unvollständig,
Abmeldung mit 0,00 € bzw. nach Schalter mit Betrag, Snapshot mit fortlaufender Nummer
und Hinweis bleibt nach Änderungen unverändert, Sammel-PDF nur eingereichte Vereine,
abgeschlossenes Sportjahr sperrt.
