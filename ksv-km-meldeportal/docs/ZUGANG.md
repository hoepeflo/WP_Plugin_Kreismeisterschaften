# Zugang der Vereine (Magic Link)

Vereine sind keine WordPress-Benutzer. Der Zugang läuft über einen persönlichen Link
je Verein und Sportjahr (`KSV\KMM\Application\Zugang`).

| Schritt | Ablauf |
|---|---|
| Link erzeugen | 32 Byte Zufall (`random_bytes`), hex-codiert im Link; in `kmm_magic_link` liegt nur der SHA-256-Hash. Gültig bis 31.12. des Sportjahres und nur solange das Sportjahr nicht abgeschlossen ist. |
| Versand | An alle Adressen des Vereins über `wp_mail()` (WP Mail SMTP greift). Betreff, Text: `templates/mail/magic-link.php`. Eintrag im Mail-Protokoll ohne Adressen. |
| „Neu senden“ (Backend) | widerruft alle bisherigen Links des Vereins im Sportjahr und beendet dessen Sitzungen. |
| Aufruf `/km-meldung/zugang/<token>/` | Hash-Vergleich mit `hash_equals`, Prüfung auf Widerruf, Ablauf, abgeschlossenes Sportjahr, inaktiven Verein. Dann Sitzung anlegen (eigenes 32-Byte-Token, nur Hash in `kmm_sitzung`) und Cookie `kmm_sitzung` setzen: HttpOnly, Secure bei HTTPS, SameSite=Lax, Pfad `/km-meldung/`. Weiterleitung auf `/km-meldung/` ohne Token. |
| Sitzung | Dauer laut Einstellung (Standard 30 Tage), endet bei Abmelden, Widerruf des Links oder Abschluss des Sportjahres. Der Link darf mehrfach und auf mehreren Geräten verwendet werden. |
| „Link anfordern“ `/km-meldung/link-anfordern/` | Immer dieselbe Antwort. Ist die Adresse bei aktiven Vereinen hinterlegt, erhält jeder dieser Vereine einen neuen Link (bestehende Links bleiben gültig). Rate-Limit je IP und je Adresse (Einstellung, Standard 5 pro Stunde) über Transients `kmm_rl_*`. |

Schreibzugriff in der Vereinsoberfläche hängt nicht vom Zugang ab, sondern von der
Meldephase (Meldebeginn bis Meldeschluss) bzw. einer Nachmeldungs-Freischaltung.

Keine personenbezogenen Daten in URLs oder Logs: Der Link enthält nur das Token, das
Protokoll nur Vereins-IDs, das Mail-Protokoll nur die Anzahl der Empfänger.
