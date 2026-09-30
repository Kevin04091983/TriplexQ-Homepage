# TRIPLEX-Q – neue Homepage mit Werkzeug-Shop und Kundenkonto

Kompletter Neubau von https://www.triplex-q.com (bisher Strato Webshop Now Pro) als schnelle
Website mit eigenem Shop, Kundenkonto und Verwaltung. Läuft auf dem vorhandenen Paket
**STRATO Hosting Plus** (PHP 8.4, MySQL). Bestellungen gehen per E-Mail ein und landen in der Datenbank.

Farben aus dem Logo: Royalblau `#003B9F`, Signalrot `#E40001`, dazu dunkles Navy `#06163F`.
Schriften (Barlow Condensed, Inter) liegen lokal, es gibt keine Verbindung zu Google.

## Inhalt

| Seite | Datei |
|---|---|
| Startseite | `website/index.html` |
| Werkzeug-Shop (Filter, Suche, Ø-Suche, Sortierung) | `website/shop.html` |
| 27 Produktseiten mit Variantenauswahl und Schnellbestell-Tabelle | `website/produkte/*.html` |
| Warenkorb und Bestellung auf Rechnung | `website/warenkorb.html` |
| Kundenkonto (Anmelden, Registrieren, Daten, Bestellungen) | `website/konto.html` |
| Verwaltung (Bestellungen, Kunden, Rabatte, CSV-Export) | `website/admin/` |
| Sonderwerkzeuge & TRIPLEX-Q special, Galerie | `website/sonderwerkzeuge.html` |
| Anwendungsbeispiele & Kundenvideos | `website/anwendungen.html` |
| Referenzkunden & ShopVote-Bewertungen | `website/referenzen.html` |
| Kontakt | `website/kontakt.html` |
| Impressum, Datenschutzerklärung, Cookie-Richtlinie, Zahlung & Versand, AGB, Widerrufsrecht (wie alte Seite) | `website/*.html` |

Katalog: 27 Produkte mit 1.360 bestellbaren Ausführungen, Preise netto 1:1 aus dem alten
Shop (Stand des Imports), 20 Produktdatenblätter als PDF.
Die 22 Bohrer-Teilartikel des alten Shops sind zu 5 Bohrerfamilien zusammengefasst
(3xD, 3xD IK, 5xD IK, 7xD IK, 12xD IK), die 6 MZ-Einzelartikel zu einem Artikel.

## Kundenkonto

- Registrierung ohne Freischaltung, sofort nutzbar; Bestätigungsmail für die E-Mail-Adresse
- Anmelden, Passwort vergessen (Link 2 Stunden gültig), Passwort ändern
- Gespeicherte Rechnungs- und Lieferadresse, wird im Warenkorb automatisch vorausgefüllt
- Bestellhistorie mit Status und „Nochmal bestellen“
- **Rabatt in Prozent je Kunde**, in der Verwaltung einstellbar. Er wird auf Produktseite und im
  Warenkorb angezeigt und vom Server bei jeder Bestellung selbst gerechnet
- Bestellen ohne Konto bleibt möglich
- Sicherheit: Passwörter nur als Hash, Sperre nach 10 Fehlversuchen für 15 Minuten,
  Schutz gegen fremde Formulare (CSRF), Eingaben werden in der Verwaltung entschärft

## Preise ändern (Admin)

Admin ist, wer sich auf der **normalen Kontoseite** mit einer E-Mail-Adresse aus `ADMIN_EMAILS`
(in `api/einstellungen.php`) anmeldet. Das Konto wird ganz normal registriert, die E-Mail-Adresse muss
bestätigt sein. Danach gilt:

- **Auf jeder Produktseite** erscheint unter dem Produkt das Feld „Preise bearbeiten“ mit allen
  Ausführungen. Preis eintragen, „Preise speichern“ – gilt sofort für alle Kunden. Auch „alle um x %“.
- **Verwaltung → Preise** (`/admin/?seite=preise`): alle 1.360 Artikel mit Suche, Einzeländerung,
  „alle gefundenen um x % ändern“, Export als CSV für Excel und Import der bearbeiteten Datei.
- Leeres Preisfeld = „Preis auf Anfrage“ (nicht bestellbar).
- Jede Änderung steht im Protokoll (alt, neu, wer, wann).
- Die Preise liegen in der Datenbank (Tabelle `preise`) und überschreiben die Preise aus dem Seitenbau.
  Shop-Karten, Startseite, Produktseiten, Warenkorb und die Berechnung der Bestellung verwenden
  automatisch die aktuellen Preise.

In der lokalen Vorschau ohne PHP (`Start-Vorschau.bat`) läuft die Kontoseite im **Vorschau-Modus**:
Man sieht den Ablauf, die Daten bleiben nur im Browser.

## Livegang bei Strato – Schritt für Schritt

Alles im Strato-Kundenbereich unter **STRATO Hosting Plus**:

1. **Testadresse:** `triplex-q.de` leitet heute auf den alten Shop weiter. Unter *Domains* die
   Weiterleitung entfernen und die Domain auf ein neues Verzeichnis zeigen lassen, z. B. `/triplex-q`.
2. **SSL aktivieren** für `triplex-q.de` (im Paket enthalten). Unbedingt vor dem Hochladen,
   denn die Seite leitet alle Aufrufe auf https um.
3. **Postfach anlegen**: `shop@triplex-q.de` – das ist der Absender der Bestellmails.
   In `api/einstellungen.php` bei `ADMIN_EMAILS` die E-Mail-Adresse(n) eintragen, die Admin sein sollen.
4. **Datenbank anlegen** (*Datenbanken und Webspace*), dann in phpMyAdmin die Datei
   `website/api/schema.sql` einspielen.
5. **Hochladen**: Inhalt von `TriplexQ-Website-TEST-zum-Hochladen.zip` per SFTP oder
   Datei-Manager in das Verzeichnis aus Schritt 1.
6. **`api/einstellungen.php` ausfüllen**: Datenbank-Zugang aus Schritt 4, `SEITEN_URL`, Absender.
   (Im Git liegt nur die Vorlage `api/einstellungen.beispiel.php` – die echte Datei mit Passwörtern wird nicht versioniert.)
7. **Verwaltungs-Passwort**: `https://triplex-q.de/admin/passwort-hash.php` aufrufen, Wunschpasswort
   eingeben, den Hash in `einstellungen.php` bei `ADMIN_HASH` eintragen – danach
   `admin/passwort-hash.php` vom Server **löschen**.
8. **Testen**: Konto anlegen, Testbestellung, Verwaltung unter `https://triplex-q.de/admin/`.
9. **Umschalten** auf die Hauptdomain: `python tools/build_site.py` (ohne `--test`) neu bauen,
   hochladen, `triplex-q.com` im Kundenbereich vom Webshop auf das Hosting-Verzeichnis umhängen.
10. **Alter Shop bleibt bestehen:** *Webshop Now Pro* wird nicht gekündigt (Entscheidung 30.09.2026).
    Er läuft im Hintergrund weiter, als Sicherung mit allen alten Kunden und Bestellungen.
    Die Kunden kommen per CSV-Export in die neue Seite (siehe unten).

## Bestehende Kunden aus dem alten Shop übernehmen

Ziel: Beim Livegang ist **jeder Altkunde schon da** – mit Adressen, alten Bestellungen und seinen Preisen.
Nur das Passwort muss er einmal neu festlegen (Passwörter lassen sich aus keinem Shop exportieren).

**Ablauf am Umschalttag** (Verwaltung → „Übernahme alter Shop“, `/admin/uebernahme.php`):
1. Im alten Shop-Admin exportieren: **Kunden**, **Bestellungen**, ggf. **Kundenpreise/Kundengruppen** (CSV).
2. Nacheinander hochladen: Kunden → Bestellungen → Kundenpreise. Spalten werden automatisch erkannt
   (deutsche/englische Spaltennamen, Semikolon oder Komma, Excel-Zeichensatz) und können angepasst werden.
3. Erst **Probelauf** (speichert nichts, zeigt Probleme je Zeile), dann **Übernehmen**.
   Doppeltes Einlesen legt nichts doppelt an. Bestehende neue Konten werden nur ergänzt, nie überschrieben.
4. **Einladungen senden** (Pakete zu 50): E-Mail „Unser neuer TRIPLEX-Q Shop – bitte Passwort festlegen“,
   Link 14 Tage gültig.

**Was der Kunde erlebt:** Meldet er sich mit seinem alten Passwort an, erscheint „Willkommen im neuen TRIPLEX-Q Shop …“
und er bekommt automatisch den Link per E-Mail. Registriert er sich neu, wird kein Doppelkonto angelegt, sondern
ebenfalls der Link geschickt. Nach dem Festlegen ist er direkt angemeldet und sieht alte Bestellungen und seine Preise.
Der Link per E-Mail ist Pflicht – sonst könnte jemand mit fremder Adresse ein Konto übernehmen.

**Preise je Kunde:** Reihenfolge eigener Artikelpreis → Preis seiner **Kundengruppe** → Listenpreis minus Rabatt % → Listenpreis.
Kundengruppen aus dem alten Shop: „22 Prozent“, „Händler 30%“, „Interessenten 15%“ (je ~1.300 Artikelpreise,
Datei „Kundenspezifische_Preise.csv“ = Strato-Export der Gruppenpreise). Die Zuordnung Kunde → Gruppe steht nicht im
Strato-Export und wird in der Verwaltung → Kunden gesetzt (Filter „ohne Kundengruppe“).

**Probeübernahme mit den echten Exporten (30.09.2026, lokal):** 192 Kundenzeilen → 179 Kunden (13 Dubletten
zusammengeführt, Login-Adresse hat Vorrang), alle Adressen vollständig, 9 Lieferadressen; 3.862 Gruppenpreise.
Kein Bestell-Export vorhanden. Export-Dateien liegen in `data/altshop-export/` (enthalten Kundendaten – nicht weitergeben).

## Vorschau starten

Doppelklick auf `Start-Vorschau.bat`, danach http://localhost:8095 öffnen (ohne PHP, Konto im Vorschau-Modus).

## Daten aktualisieren

```
.venv/Scripts/python tools/crawl_triplexq.py      # liest den alten Shop neu aus (data/quelle)
.venv/Scripts/python tools/bilder_aufbereiten.py  # Freisteller (OpenCV), Hochrechnen, WebP
.venv/Scripts/python tools/build_site.py --test   # Testfassung für triplex-q.de (noindex)
.venv/Scripts/python tools/build_site.py          # Livefassung für www.triplex-q.com
```

Einmalig einrichten: `python -m venv .venv` und
`.venv/Scripts/python -m pip install numpy pillow requests opencv-python-headless`.

Die HTML-Seiten werden erzeugt. Änderungen an Texten und Aufbau gehören deshalb in
`tools/build_site.py`, das Design in `website/assets/css/style.css`. Der Freisteller liegt in
`tools/freisteller.py` (GrabCut, Löcherfüllung, Bodenspiegelung, Kantenentfärbung).

## Vor dem Livegang offen

1. **Datenschutzerklärung** stammt aus dem alten Shop. Neu nötig sind Abschnitte für Hosting,
   Kundenkonto, Kontakt- und Bestellformular, YouTube (Laden erst nach Klick, youtube-nocookie) und den
   Warenkorb im localStorage. Die Seite setzt nur ein technisch nötiges Sitzungs-Cookie für die
   Anmeldung. Rechtliche Prüfung erforderlich.
2. **AGB, Widerruf, Impressum** wurden inhaltlich unverändert übernommen, bitte gegenprüfen.
3. **Staffelpreise** des alten Shops wurden nicht übernommen, weil sie widersprüchlich sind:
   ALU-X springt zwischen 12,78 € und 19,16 €, und beim Bohrer 3XD-11.30IK kosten ab 3 Stück
   70,16 € statt 59,25 € einzeln. Bitte korrekte Staffeln nennen.
4. Datenfehler im alten Shop: Artikel 3XD-16.80HBIK heißt dort „Ø 16,50“, 5XD-14.20IK heißt
   „Ø 14,10“. Neu wird der gepflegte Attributwert angezeigt (16,80 bzw. 14,20).
5. Artikel 7XD-11.50IK hat im alten Shop keinen Preis und erscheint als „auf Anfrage“.
6. NC-Entgratwerkzeuge haben im alten Shop keine Artikel und stehen deshalb als „auf Anfrage“ da.
7. Bildrechte: Die Bilder stammen aus dem bisherigen Shop von TRIPLEX-Q.
8. **Versanddienst klären:** Die alte Seite zeigt unten ein UPS-Logo, die Seite „Zahlung & Versand“ nennt DHL.
   Beides wurde wörtlich übernommen; die eigenen Texte der neuen Seite sagen neutral „versicherter Versand“.
9. Fußbereich, Rechtstexte und Bestimmungsländer (29 Länder) sind wie auf der alten Seite. Versand ins Ausland
   „auf Anfrage“ (Versandkosten 0 in der Bestellung, Hinweis an euch in der Bestellmail).
