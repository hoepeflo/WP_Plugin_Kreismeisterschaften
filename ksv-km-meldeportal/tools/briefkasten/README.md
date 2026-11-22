# Briefkasten für die Sicherungen des KM-Portals

Läuft auf dem zweiten Webspace (All-Inkl), nimmt verschlüsselte Sicherungen an und kann
nichts löschen oder überschreiben. Aufräumen nach 90 Tagen übernimmt der Cronjob
`?aktion=wartung`. Einrichtung, Ablauf und Wiederherstellung: `docs/SICHERUNG.md`.

Hochladen: `index.php`, `Briefkasten.php`, `.htaccess` und die ausgefüllte `config.php`
(Vorlage `config-beispiel.php`). PHP ab 8.1, keine weiteren Abhängigkeiten.
