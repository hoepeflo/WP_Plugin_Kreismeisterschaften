SRD Kreismeisterschaften (WordPress-Plugin)
===========================================

Installation
------------
1. Ordner `srd-kreismeisterschaften` nach `wp-content/plugins/` kopieren.
2. Im WordPress-Backend Plugin aktivieren.
3. Den kompletten Inhalt des bisherigen `results`-Verzeichnisses auf den Server legen, z. B. nach `wp-content/uploads/srd-results/`.
4. Unter „Einstellungen“ → „Permalinks“ eine Struktur wählen, die nicht „Einfach“ ist, und speichern (Voraussetzung für Pretty-URLs).
5. Unter „Einstellungen“ → „SRD Kreismeisterschaften“:
   - Seite wählen, die den Shortcode `[srd_km]` enthält (Klassischer Editor oder Shortcode-Block; der Text `[srd_km]` muss im Seiteninhalt vorkommen).
   - Optional: absoluten Pfad und öffentliche URL zu diesem `results`-Ordner setzen (Standard: `wp-content/uploads/srd-results`).
   - Datenbank: wenn `srd_kreis_v3` in derselben Datenbank wie WordPress liegt, „WordPress-Datenbank verwenden“ aktiv lassen.
   - Pretty-Permalinks: Option aktivieren und URL-Slug anpassen (Standard `kreismeisterschaften`). Der Slug sollte nicht mit einer anderen öffentlichen Route kollidieren.

Pretty-Permalinks (Standard-Slug `kreismeisterschaften`)
--------------------------------------------------------
Basis-URL = `https://example.org/kreismeisterschaften/` (je nach Site-URL)

- Jahresübersicht: `/kreismeisterschaften/`
- Jahr (Kugel): `/kreismeisterschaften/2025/`
- Bogen: `/kreismeisterschaften/2025/bogen/`
- Blasrohr: `/kreismeisterschaften/2025/blasrohr/`
- HTML-Ergebnis (Einzel/Mannschaft): `/kreismeisterschaften/2020/e/DATEI-ID/` bzw. `…/m/DATEI-ID/`
- Roh-HTML für iframe: `/kreismeisterschaften/2020/e/DATEI-ID/raw/`

Ohne Pretty-Permalinks (WordPress „Einfach“) oder wenn die Option deaktiviert ist, gelten weiter die Query-Parameter (siehe unten).

URL-Parameter (Fallback, an die KM-Seiten-URL angehängt)
-------------------------------------------------------
- Jahresübersicht: keine Parameter
- Jahr (Kugel-Disziplinen): `?km_year=2025`
- Bogen: `?km_year=2025&km_discipline=bogen`
- Blasrohr: `?km_year=2025&km_discipline=blasrohr`
- HTML-Einzel/Mannschaft: `?km_year=2020&km_id=…&km_art=e` bzw. `m`

Das alte `km_results.php` entfällt; HTML-Ergebnisse werden per iframe ausgeliefert (Pretty: `…/raw/`; sonst `?km_view=raw&…`).

Abschaltung des alten PHP-Projekts
----------------------------------
Nach Migration: nur noch sicherstellen, dass PDF-/Statik-URLs erreichbar sind (gleiche Ordnerstruktur unter `results`).

Hinweis RWK / KKS
-----------------
RWK bleibt beim externen Dienstleister; KKS kann später separat migriert werden.
