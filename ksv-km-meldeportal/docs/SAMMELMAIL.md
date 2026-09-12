# Tägliche Sammelmail (Phase 2, Meilenstein 4)

Konzept 12.1: Vereine werden nicht bei jedem Klick benachrichtigt. Einmal täglich zur
festen Uhrzeit erhält jeder Verein, bei dem sich seit der letzten Mail etwas geändert
hat, **eine** Mail mit allen Änderungen samt Gründen und seinem Zugangslink.

## Quelle: Warteschlange `kmm_aenderung`

Alle Änderungen nach Meldeschluss landen über `Application\Aenderungen::erfassen` in
der Tabelle (Typen `Domain\AenderungTyp`: Startrechtsprüfung, Abmeldung, Nachmeldung,
Korrektur, Mannschaft, Startplan). `versendet_am = NULL` heißt „noch nicht gemailt“.
Dieselbe Tabelle speist „Änderungen seit dem letzten DAVID21-Export“ (unabhängig vom
Versand).

## Ablauf (`Application\Sammelmail`)

1. **Cron.** Tägliches WP-Cron-Ereignis `kmm_sammelmail` zur eingestellten Uhrzeit
   (Einstellung `sammelmail_uhrzeit`, Ortszeit der WordPress-Zeitzone; `Cron::
   naechster_taeglicher_lauf`, Neuplanung bei Änderung der Einstellung). Zusätzlich
   hängt `Sammelmail::cron` am stündlichen Ereignis `kmm_hourly` als Fallback.
2. **Fälligkeit** (`Sammelmail::faellig`): Schalter `sammelmail_aktiv` an, Uhrzeit
   heute erreicht, heute noch kein Lauf (Option `kmm_sammelmail_lauf` mit Datum).
3. **Versand** (`Sammelmail::senden`): je Verein mit offenen Änderungen
   - Empfänger: Ansprechpartner der Meldung + alle Vereinsadressen;
   - die offenen Zeilen werden **atomar beansprucht**:
     `UPDATE kmm_aenderung SET versendet_am = … WHERE versendet_am IS NULL AND id IN (…)`
     – liefert die Zahl der wirklich beanspruchten Zeilen. Ein zweiter, gleichzeitiger
     Lauf bekommt 0 und sendet nichts;
   - neuer Zugangslink ohne Widerruf (`Zugang::link_erzeugen`, Anlass `sammelmail`);
   - Mail aus `templates/mail/sammelmail.php`, Eintrag im Mail-Protokoll
     (`Mailer::TYP_SAMMELMAIL`);
   - schlägt der Versand fehl, werden die Zeilen wieder freigegeben (nächster Lauf);
     Vereine ohne Adresse bleiben offen und erscheinen als Fehler im Protokoll und in
     der Rückmeldung des manuellen Versands.
4. **Protokoll**: Systemeintrag `sammelmail.senden` (nur wenn etwas gesendet wurde oder
   Fehler auftraten), bei manuellem Versand Admin-Eintrag.

## Idempotenz

- Zweimal Cron am selben Tag → der zweite Lauf ist nicht fällig (Datumsmarker).
- Zwei parallele Läufe → die Beanspruchung entscheidet; nur einer sendet.
- Nach dem Versand ist nichts mehr offen; ein erneuter Aufruf sendet nichts.
  (`tests/Integration/SammelmailTest.php`)

## Backend

- Übersicht: Zeile „Sammelmail: täglich um … Uhr · nächster Lauf · letzter Lauf ·
  n offene Änderungen bei m Vereinen“ und Button **„Sammelmail jetzt senden“**
  (Administrator; unabhängig von Uhrzeit und Schalter).
- Einstellungen → Mail: „Tägliche Sammelmail“ (an/aus) und „Uhrzeit“.
- System: „Nächste Sammelmail (kmm_sammelmail)“.

Abgeschaltet werden Änderungen weiter gesammelt, aber nicht automatisch versendet.

## Server-Cronjob

WP-Cron läuft nur bei Seitenaufrufen. Damit die Mail zuverlässig zur eingestellten
Uhrzeit rausgeht, muss auf dem Server ein echter Cronjob `wp-cron.php` anstoßen
(alle 5 Minuten reicht), siehe `docs/INSTALLATION.md`.
