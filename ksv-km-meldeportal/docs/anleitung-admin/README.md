# Anleitung für Administratoren und Referenten

`../KM-Portal-Anleitung-Administratoren.pdf` ist die fertige Anleitung für den KSV
(19 Seiten). Quelle ist `anleitung-admin.html` in diesem Ordner, die Screenshots liegen
daneben. Alle abgebildeten Vereine, Namen und Ergebnisse sind Musterdaten.

Das Gegenstück für die Vereine liegt unter `../anleitung/`.

## Neu erzeugen

1. Testinstallation mit Musterdaten aufsetzen: Sportjahr mit laufender Meldephase,
   Regeltabelle importiert, mehrere Vereine mit eingereichten Meldungen, ein Schießstand
   mit 12 Ständen, ein freigegebener Wettkampftag mit abgelaufener Buchungsfrist und
   einigen Startern ohne Platz, ein zweiter Wettkampftag im Entwurf, ein Referent.
2. Screenshots in diesen Ordner legen (Dateinamen wie in der HTML-Datei, Breite 1360 px,
   doppelte Pixeldichte). Wichtig: Adminleiste und WordPress-Fußzeile ausblenden und die
   Ausschnitte auf das Wesentliche begrenzen, sonst werden die Bilder im PDF zu klein.
3. Die Bilder zum Ablauf nach der Buchungsfrist entstehen der Reihe nach: erst der Block
   mit den Startern ohne Platz, dann „Rest verteilen“ klicken, dann die Matrix (einmal
   normal, einmal mit gewähltem Starter), dann veröffentlichen.
4. Die Matrix ist mit zwölf Ständen breiter als eine A4-Seite. Für die Anleitung wird
   deshalb nur ein Ausschnitt von etwa 900 px Breite aufgenommen; die Bildunterschrift
   sagt das dazu.
5. Version und Stand in `anleitung-admin.html` anpassen.
6. PDF drucken, zum Beispiel über den Druckdialog des Browsers (A4, Hintergrundgrafiken
   aktivieren, Ränder 18/16 mm) oder per Kommandozeile mit Chromium/Playwright.

Das ZIP des Plugins enthält `docs/` nicht; die Anleitungen werden separat verteilt.
