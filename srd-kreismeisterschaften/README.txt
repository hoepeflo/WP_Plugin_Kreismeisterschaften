SRD Kreismeisterschaften (WordPress-Plugin)
===========================================

Installation
------------
1. Ordner `srd-kreismeisterschaften` nach `wp-content/plugins/` kopieren.
2. Im WordPress-Backend Plugin aktivieren.
3. Den kompletten Inhalt des bisherigen `results`-Verzeichnisses auf den Server legen, z. B. nach `wp-content/uploads/srd-results/`.
4. Unter „Einstellungen“ → „Permalinks“ eine Struktur wählen, die nicht „Einfach“ ist, und speichern (Voraussetzung für Pretty-URLs).
5. Unter „Kreismeisterschaften“ → „Einstellungen“ im WordPress-Backend:
   - Seite wählen, die den Shortcode `[srd_km]` enthält (Klassischer Editor oder Shortcode-Block; der Text `[srd_km]` muss im Seiteninhalt vorkommen).
   - Optional: absoluten Pfad und öffentliche URL zu diesem `results`-Ordner setzen (Standard: `wp-content/uploads/srd-results`).
   - Disziplinen und Sportjahre werden aus den SRD-Tabellen (`srd_kreis_v2`, `srd_kreis_v3`) in der WordPress-Datenbank geladen.
   - Pretty-Permalinks: Option aktivieren und URL-Slug anpassen (Standard `kreismeisterschaften`). Der Slug sollte nicht mit einer anderen öffentlichen Route kollidieren.

Benutzer freischalten
---------------------
Unter „Kreismeisterschaften“ → „Einstellungen“ (nur für WordPress-Administratoren sichtbar):

- Mehrfachauswahl „Benutzer mit Plugin-Zugriff“: zusätzliche Benutzer (z. B. Redakteure) erhalten Zugriff auf das Plugin-Menü, Disziplinen und Uploads.
- Administratoren (`manage_options`) haben immer vollen Zugriff.

Disziplinen verwalten (Backend)
--------------------------------
Unter „Kreismeisterschaften“ → „Disziplinen“ (oder über den Button auf der Einstellungsseite):

- Liste aller Einträge aus `srd_kreis_v3`
- Anlegen, Bearbeiten und Löschen von Disziplinen (Disziplin, Altersklasse, SpO, Datei-ID, optional Sportjahr)
- Die Kategorie ergibt sich aus der führenden Ziffer der Datei-ID (1 = Gewehr, 6 = Bogen, 11 = Lichtschießen, 12 = Blasrohr usw.)
- Ergebnisdateien werden weiterhin im `results`-Ordner abgelegt; die Datei-ID verknüpft die Zeile mit `km_JJJJ/e{ID}.pdf` bzw. `m{ID}.pdf`

Frontend
--------
- Eine Disziplinentabelle mit Sportjahr-Dropdown im Tabellenkopf
- Kategoriefilter unter der Überschrift (Alle, Gewehr, Bogen, Lichtschießen, …)
- Kategorie als Badge neben dem Disziplinnamen

Pretty-Permalinks (Standard-Slug `kreismeisterschaften`)
--------------------------------------------------------
Basis-URL = `https://example.org/kreismeisterschaften/` (je nach Site-URL)

- Disziplinenliste (aktuelles Sportjahr): `/kreismeisterschaften/` oder `/kreismeisterschaften/2025/`
- Kategorie filtern: `?km_category=6` (z. B. Bogen), an die Jahr-URL angehängt
- Legacy Bogen/Blasrohr: `/kreismeisterschaften/2025/bogen/` bzw. `…/blasrohr/` (nur Sportjahr, Kategoriefilter standardmäßig „Alle“)
- HTML-Ergebnis (Einzel/Mannschaft): `/kreismeisterschaften/2020/e/DATEI-ID/` bzw. `…/m/DATEI-ID/`
- Roh-HTML für iframe: `/kreismeisterschaften/2020/e/DATEI-ID/raw/`

Ohne Pretty-Permalinks (WordPress „Einfach“) oder wenn die Option deaktiviert ist, gelten weiter die Query-Parameter (siehe unten).

URL-Parameter (Fallback, an die KM-Seiten-URL angehängt)
-------------------------------------------------------
- Disziplinenliste: `?km_year=2025` (optional `&km_category=6` für Kategoriefilter)
- Legacy Bogen/Blasrohr: `?km_year=2025&km_discipline=bogen` bzw. `blasrohr` (Kategoriefilter standardmäßig „Alle“)
- HTML-Einzel/Mannschaft: `?km_year=2020&km_id=…&km_art=e` bzw. `m`

Das alte `km_results.php` entfällt; HTML-Ergebnisse werden per iframe ausgeliefert (Pretty: `…/raw/`; sonst `?km_view=raw&…`).

Abschaltung des alten PHP-Projekts
----------------------------------
Nach Migration: nur noch sicherstellen, dass PDF-/Statik-URLs erreichbar sind (gleiche Ordnerstruktur unter `results`).

Hinweis RWK / KKS
-----------------
RWK bleibt beim externen Dienstleister; KKS kann später separat migriert werden.
