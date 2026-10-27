# TRIPLEX-Q Homepage – Hinweise für die Arbeit an diesem Projekt

Neue Website mit Werkzeug-Shop für **TRIPLEX-Q / Präzisionswerkzeuge Schmidt e.K.** (Zella-Mehlis).
Ersetzt den alten Strato-Webshop (ePages, triplex-q.com). Eigenständiges Projekt, kein Bezug zu SAWTec.
Kommunikation mit Kevin auf Deutsch; nach jeder fertigen Änderung einen anklickbaren Link zur Vorschau mitschicken.

## Aufbau
- `website/` – die fertige Seite (HTML/CSS/JS + PHP unter `api/` und `admin/`). Wird hochgeladen.
- `tools/build_site.py` – erzeugt **alle** HTML-Seiten. HTML-Dateien nie von Hand ändern, sondern hier.
  `--test` = Testfassung für triplex-q.de (noindex), ohne = Livefassung für www.triplex-q.com.
- `tools/crawl_triplexq.py` – liest den alten Shop über die öffentliche ePages-API aus (`data/quelle/`).
- `tools/bilder_aufbereiten.py` + `tools/freisteller.py` – Freisteller (OpenCV/GrabCut), WebP.
- `tools/preise_pruefen.py` – Preisabgleich mit dem alten Shop (live). `--uebernehmen` schreibt die aktuellen
  Preise in `data/quelle/produkte.json` (im alten Shop ausgeblendete Abmessungen werden ausgeblendet);
  danach `build_site.py --test` und erneut prüfen. **Vor dem Livegang immer laufen lassen.**
- `tools/hochladen.py` – baut, packt (`upload/`, nie `api/einstellungen.php`) und lädt per SFTP mit Schlüssel
  `~/.ssh/triplexq_strato` hoch; Ziel in `tools/strato.json`. `--test`/`--live`, `--erstmals` (mit Assistent), `--nur-paket`.
- `website/einrichtung/` – Einrichtungs-Assistent: Kevin trägt DB-Zugang + Admin-Passwort selbst ein; sperrt sich danach.
- `tools/instagram_reels.py` – Instagram-Reels (@triplex_q_tools) für die Startseite: Vorschaubilder auf den eigenen Server, Liste REELS im Skript ergänzen.
- `tools/entgrater_aus_export.py` – NC-Entgrater (im alten Shop ausgeblendet) aus dem Produkt-Export → `data/quelle/zusatzprodukte.json`
  + Bilder (`data/bilder_zusatz.json`); Preis vorerst 50 €, echte Preise pflegt TRIPLEX-Q in der Verwaltung. Fotos fehlen noch (DEB 60/120, Vor-/Rückwärts).
- S-TEC-Kooperation (Schwarzwald, Rheinebene, Schweiz, Ansprechpartner Maxim Semenov): `PARTNER` in build_site.py; Logo als
  `website/assets/img/partner/s-tec.webp` ablegen → erscheint automatisch.
- `tools/vorschau_server.py` – lokale Vorschau ohne Zwischenspeicher (Port 8095), ohne PHP.
- Design: `website/assets/css/style.css`. Farben aus dem Logo: #003B9F, #E40001, Navy #06163F.

## Server (Ziel: Strato „Hosting Plus“, PHP 8.4, MySQL)
- `website/api/einstellungen.php` enthält echte Zugangsdaten und ist **nicht im Git**.
  Vorlage: `website/api/einstellungen.beispiel.php`. `MAIL_AUS = true` beim Testen mit echten Kundendaten.
- Datenbank: `website/api/schema.sql`.
- Kundenkonto, Admin über Kundenkonto (`ADMIN_EMAILS`), Preise in DB (`preise`), Kundengruppen
  (`preisgruppen`, `preisgruppen_preise`), Einzelpreise (`kundenpreise`).
  Preisrang: Einzelpreis > Gruppenpreis > Listenpreis − Rabatt % > Listenpreis.
- Übernahme aus dem alten Shop: `/admin/uebernahme.php` (Strato-CSV-Export).

## Regeln
- **Kundendaten nie ins Git**: `data/altshop-export/` (Strato-Exporte mit Kundendaten) bleibt lokal.
- Keine Passwörter oder Tokens annehmen/eintippen; Strato-Einstellungen macht Kevin selbst.
- Vor Änderungen an PHP: `php -l` für alle Dateien; Ablauf möglichst gegen PHP + MariaDB testen.
- Rechtstexte (Impressum, Datenschutz, AGB, Widerruf, Cookie) wörtlich vom alten Shop – nicht umformulieren.

## Offene Punkte (Stand 15.10.2026)
- Zuordnung Kunde → Kundengruppe fehlt im Strato-Export; Gruppe für Neukunden klären.
- Kein Bestell-Export vom alten Shop vorhanden.
- Preise am 15.10.2026 an den alten Shop angeglichen (1355 Artikel identisch, keine Staffelpreise mehr).
  Kundengruppen-Preise stammen aus dem Export vom 30.09. – kurz vor dem Livegang neu exportieren.
- DHL oder UPS? Datenschutzerklärung juristisch anpassen.
- Livegang: Ablauf im README („Livegang bei Strato“); zuerst Test auf triplex-q.de.

## Auf dem Mac starten
```
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
./Start-Vorschau.command        # Vorschau auf http://localhost:8095
.venv/bin/python tools/build_site.py --test
```
