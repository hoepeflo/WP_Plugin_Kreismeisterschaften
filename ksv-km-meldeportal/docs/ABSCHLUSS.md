# Sportjahr abschließen und anonymisieren (Phase 2, Meilenstein 10)

Konzept 7.4. Backend: **Sportjahre → „Abschließen“** (nur Administratoren).

## Was der Abschluss bewirkt

1. **Sicherung.** Vor allem anderen werden alle Zeilen dieses Sportjahres als JSON in
   `wp-content/uploads/kmm-archiv/kmm-sportjahr-JJJJ-JJJJMMTT-HHMMSS.json` geschrieben
   (Ordner mit `index.php` und `.htaccess` gegen direkten Zugriff). Gesichert werden
   Sportjahr, Meldungen, Einzelmeldungen, Mannschaften, Änderungen, Belege, Protokoll,
   Wettkampftage, Buchungen, Höhermeldungen und die zugehörigen Schützen. Der Dateiname
   steht danach in `kmm_sportjahr.abschluss_backup`. Lässt sich die Datei nicht schreiben,
   meldet das Backend das deutlich – abgeschlossen wird trotzdem.
2. **Schließen.** `abgeschlossen_am` wird gesetzt. Alle schreibenden Dienste lehnen ab
   (Meldung, Startplan, Belege, Zugang); neue Magic Links werden nicht mehr ausgegeben.
3. **Startpläne ausblenden.** Alle Wettkampftage bekommen `ausgeblendet_am`; der Shortcode
   zeigt sie nicht mehr.
4. **Zugänge beenden.** Offene Magic Links des Jahres werden widerrufen.
5. **Anonymisieren** (Haken „Meldungen sofort anonymisieren“, Standard an).
6. **Schützenliste aufräumen** nach der Löschregel aus Konzept 7.2 (siehe unten).

## Was die Anonymisierung entfernt

| Tabelle | Änderung |
|---|---|
| `kmm_einzelmeldung` | `schuetze_id` → `NULL` – die Verbindung zur Person fällt weg |
| `kmm_meldung` | Ansprechpartner (Name, E-Mail, Telefon) → leer |
| `kmm_aenderung` | `text` und `details` → leer (das Änderungsprotokoll enthält Namen) |
| `kmm_protokoll` | `zusammenfassung` und `details` des Jahres → leer; Aktion, Zeitpunkt, Verein und Akteurstyp bleiben |

Das Systemprotokoll wird mitbereinigt, weil sonst genau die Namen weiter dort stünden, die
aus den Meldungen entfernt werden; die Nachvollziehbarkeit (wer hat wann welche Art von
Änderung gemacht) bleibt erhalten.

**Erhalten bleiben** an der Einzelmeldung: Verein, Disziplin, Klasse und Startklasse,
Mannschaftsklasse und -zugehörigkeit, Geschlecht, Geburtsjahr, Startrecht,
Verarbeitungsstatus und Startgeld. Teilnehmerzahlen je Verein, Disziplin, Klasse und Jahr
bleiben damit dauerhaft auswertbar.

## Löschregel der Schützenliste (Konzept 7.2)

Die Schützenliste der Vereine wird **nicht** anonymisiert – sie lebt über die Jahre weiter.
Stattdessen greift beim Abschluss die eigene Löschregel: Schützen, die seit
`schuetzen_loeschfrist_jahre` Sportjahren nicht gemeldet wurden, werden aus der Liste
entfernt (Einstellung, Standard 2, `0` schaltet die Regel ab).

Stichjahr ist das Jahr des abgeschlossenen Sportjahres minus die Frist. Beim Abschluss von
2027 mit Frist 2 fällt also heraus, wer zuletzt 2025 oder früher gemeldet wurde.

Zwei Schutzregeln:

- **Wer noch an einer Einzelmeldung hängt, bleibt.** Ein Jahr, das noch nicht abgeschlossen
  (und damit anonymisiert) ist, hält seine Schützen fest. Alte Jahre geben sie nach und
  nach frei, weil die Anonymisierung `schuetze_id` auf `NULL` setzt.
- **Wer nie gemeldet wurde** (`zuletzt_gemeldet_jahr` ist `NULL`), zählt erst mit, wenn auch
  der Eintrag selbst älter als das Stichjahr ist – ein gerade angelegter Schütze
  verschwindet nicht sofort wieder.

Die Zahl der gelöschten Schützen steht in der Rückmeldung nach dem Abschluss und im
Protokoll. Wird die Anonymisierung später nachgeholt, läuft die Löschregel danach erneut.

## Rücknahme

Solange **nicht** anonymisiert wurde, lässt sich der Abschluss zurücknehmen
(„Abschluss zurücknehmen“): `abgeschlossen_am` wird geleert und das Ausblenden der
Startpläne rückgängig gemacht. Die Anonymisierung selbst ist endgültig – nur die Sicherung
aus Schritt 1 enthält die Daten noch.

Wer erst schließen und später anonymisieren will, nimmt den Haken heraus und klickt später
„Jetzt anonymisieren“.

## Hinweise vor dem Abschluss

Die Seite prüft und warnt (ohne zu sperren):

- Meldungen, die nie eingereicht wurden
- Vereine ohne Buchhaltungsbeleg
- Änderungen, die noch nicht per Sammelmail mitgeteilt wurden
- Wettkampftage, die nicht veröffentlicht sind

Tests: `tests/Integration/AbschlussTest.php`.
