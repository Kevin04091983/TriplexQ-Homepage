"""NC-Entgrater für den Shop aus dem Produkt-Export des alten Shops übernehmen.

Im alten Shop waren die Entgrater ausgeblendet und sind deshalb über die Schnittstelle nicht abrufbar.
Dieses Skript liest sie aus dem CSV-Export ("Produkte.csv", Einstellungen → Export und Import) und
schreibt sie als zusätzliche Produkte nach data/quelle/zusatzprodukte.json (liest tools/build_site.py mit ein).

Preise: Kevin (27.10.2026) – steel blue DEB 90° mit festen Netto-Preisen je Ø (PRODUKTE unten); spätere Änderungen
in der Verwaltung (Preise) – die bleiben bei jedem Neubau erhalten, sie liegen in der Datenbank.

Bilder: Für den steel blue DEB 90° gibt es ein Foto (freigestellt), für die übrigen ein Platzhalter
"Foto folgt" – echte Fotos später einfach in tools/entgrater_aus_export.py bei FOTOS eintragen.

Aufruf:  python tools/entgrater_aus_export.py [Pfad zu Produkte.csv]
Danach:  python tools/build_site.py --test   (bzw. tools/hochladen.py --live)
"""
import csv
import html
import json
import re
import sys
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

sys.path.insert(0, str(Path(__file__).resolve().parent))
import freisteller  # noqa: E402

WURZEL = Path(__file__).resolve().parent.parent
EXPORT = Path(sys.argv[1]) if len(sys.argv) > 1 else WURZEL / "data" / "altshop-export" / "2026-10-15" / "Produkte.csv"
ZIEL = WURZEL / "data" / "quelle" / "zusatzprodukte.json"
BILDER_INDEX = WURZEL / "data" / "bilder_zusatz.json"
BILDORDNER = WURZEL / "website" / "assets" / "img" / "produkte"
PREIS = 50.00

# Hauptartikel im Export -> Name im neuen Shop, Einleitungstext, Foto (None = Platzhalter)
# Kevin 27.10.2026: vorerst NUR der steel blue DEB 90° (der mit Foto), in Ø 1–20 mit festen Netto-Preisen.
# Vor-/Rückwärtsentgrater, DEB 60° und DEB 120° bleiben vorerst draußen (bei Bedarf hier wieder ergänzen).
# Hauptartikel -> (Name, Einleitung, Foto, {Ø mm: Preis netto} oder None = alle Abmessungen aus dem Export zu PREIS)
PRODUKTE = {
    "9101-9120": ("TRIPLEX-Q steel blue *DEB 90°",
                  "Der TRIPLEX-Q steel blue DEB 90° ist ein hochwertiger VHM-NC-Entgrater, der speziell für das Fasen und "
                  "Entgraten entwickelt wurde. Mit seinem 90-Grad-Spitzenwinkel und der nanocomposite Beschichtung nACo blue "
                  "ist er vielseitig einsetzbar und bietet eine ausgezeichnete Leistung – die ideale Wahl für präzise und "
                  "effiziente Entgratungsarbeiten.", WURZEL / "website" / "assets" / "img" / "fotos" / "nc-entgrater.webp",
                  {1: 21.50, 2: 21.50, 3: 21.50, 4: 21.50, 6: 22.50, 8: 31.00, 10: 41.30, 12: 66.75, 16: 122.20, 20: 204.00}),
}
# Artikelnummer je Ø nach dem Schema der Linie (9106 = Ø 6, 9116 = Ø 16)
ARTIKEL = {"9101-9120": lambda d: f"91{d:02d}"}
# bekannte Daten der Linie für Größen, die im alten Shop nicht angelegt waren
LINIE_TECHNIK = {"9101-9120": {"Spitzenwinkel": "90°", "Beschichtung": "steel blue", "Werkstoff": "VHM"}}

# technische Daten aus der Artikelbeschreibung des alten Shops (Reihenfolge = Spalten der Tabelle)
TECHNIK = ["Schneidendurchmesser", "Schaftdurchmesser", "Schneidenlänge", "Gesamtlänge", "Anzahl Schneiden",
           "Spitzenwinkel", "Schaftform", "Beschichtung", "DIN"]
KOEPFE = {"Schneidendurchmesser": "Ø", "Schaftdurchmesser": "Schaft-Ø", "Schneidenlänge": "Schneidenl.",
          "Gesamtlänge": "Gesamtl.", "Anzahl Schneiden": "Z", "Spitzenwinkel": "Winkel", "Schaftform": "Schaft",
          "Beschichtung": "Beschichtung", "DIN": "Norm"}


def slug(text):
    t = text.lower().replace("ä", "ae").replace("ö", "oe").replace("ü", "ue").replace("ß", "ss").replace("°", "")
    return re.sub(r"[^a-z0-9]+", "-", t).strip("-")


def technik_aus(beschreibung):
    text = re.sub(r"\s+", " ", html.unescape(re.sub(r"<[^>]+>", " ", beschreibung or "")))
    werte = {}
    for feld in TECHNIK + ["Werkstoff"]:
        m = re.search(re.escape(feld) + r":\s*(.+?)(?=\s+(?:" + "|".join(map(re.escape, TECHNIK + ["Werkstoff", "Innenkühlung",
                      "Freischliffdurchmesser", "Freischlifflänge", "Schneidenausführung"])) + r"):|$)", text)
        if m:
            werte[feld] = m.group(1).strip()
    return werte


def mm(wert):
    w = wert.replace(".", ",")
    return w if "," in w else w + ",00"


def platzhalter(name, pfad):
    bild = Image.new("RGB", (1400, 900), (237, 241, 248))
    d = ImageDraw.Draw(bild)
    try:
        gross = ImageFont.truetype("arialbd.ttf", 64)
        klein = ImageFont.truetype("arial.ttf", 40)
    except OSError:
        gross = klein = ImageFont.load_default()
    q = WURZEL / "website" / "assets" / "img" / "logo" / "triplex-q-logo.png"
    if q.exists():
        logo = Image.open(q).convert("RGBA")
        logo.thumbnail((360, 300))
        bild.paste(logo, ((1400 - logo.width) // 2, 160), logo)
    for text, schrift, y, farbe in [(name.replace("*", ""), gross, 520, (6, 22, 63)), ("Foto folgt", klein, 620, (74, 88, 118))]:
        breite = d.textlength(text, font=schrift)
        d.text(((1400 - breite) / 2, y), text, font=schrift, fill=farbe)
    bild.save(pfad, "WEBP", quality=86)


def bilder(name, foto):
    datei = slug(name.replace("*", ""))
    BILDORDNER.mkdir(parents=True, exist_ok=True)
    if foto and foto.exists():
        frei = freisteller.freistellen(Image.open(foto).convert("RGB"), saum=1, angeschnitten=False)
        box = frei.getchannel("A").point(lambda v: 255 if v > 12 else 0).getbbox()
        frei = frei.crop(box) if box else frei
        for zusatz, breite in [("", 1600), ("-klein", 820)]:
            b = frei.copy()
            b.thumbnail((breite, breite))
            b.save(BILDORDNER / f"{datei}{zusatz}.webp", "WEBP", quality=88)
        Image.open(foto).convert("RGB").save(BILDORDNER / f"{datei}-original.webp", "WEBP", quality=86)
        art = "freigestellt"
    else:
        platzhalter(name, BILDORDNER / f"{datei}.webp")
        for zusatz in ["-klein", "-original"]:
            platzhalter(name, BILDORDNER / f"{datei}{zusatz}.webp")
        art = "foto"
    return {"bild": f"{datei}.webp", "klein": f"{datei}-klein.webp", "original": f"{datei}-original.webp", "art": art}


def main():
    zeilen = list(csv.reader(EXPORT.open(encoding="utf-8-sig"), delimiter=";"))
    kopf = {n.split("[")[-1].rstrip("]"): i for i, n in enumerate(zeilen[0])}
    nach_alias = {z[kopf["UnityAlias"]]: z for z in zeilen[1:]}
    produkte, bilder_index = [], {}
    for haupt, (name, einleitung, foto, groessen) in PRODUKTE.items():
        von, bis = haupt.split("-")
        varianten = []
        if groessen:
            for d, preis in groessen.items():
                alias = ARTIKEL[haupt](d)
                technik = dict(LINIE_TECHNIK.get(haupt, {}))
                if alias in nach_alias:
                    technik.update(technik_aus(nach_alias[alias][kopf["Description/de"]]))
                technik["Schneidendurchmesser"] = str(d)
                durchmesser = mm(str(d))
                varianten.append({
                    "productId": f"ZUSATZ-{alias}", "sku": alias, "name": f"{name} Ø {durchmesser} mm",
                    "preis_netto": preis, "lagerbestand": None, "verfuegbar": None, "lieferzeit": None,
                    "auswahl": {"Schneidendurchmesser": durchmesser}, "technik": technik,
                })
        for alias, z in ([] if groessen else nach_alias.items()):
            if not re.fullmatch(r"\d{4}", alias) or not (von <= alias <= bis):
                continue
            technik = technik_aus(z[kopf["Description/de"]])
            d = technik.get("Schneidendurchmesser") or re.search(r"Ø\s*([\d,]+)", z[kopf["Name/de"]]).group(1)
            durchmesser = mm(d)
            varianten.append({
                "productId": f"ZUSATZ-{alias}", "sku": alias, "name": f"{name} Ø {durchmesser} mm",
                "preis_netto": PREIS, "lagerbestand": None, "verfuegbar": None, "lieferzeit": None,
                "auswahl": {"Schneidendurchmesser": durchmesser}, "technik": technik,
            })
        varianten.sort(key=lambda v: float(v["auswahl"]["Schneidendurchmesser"].replace(",", ".")))
        spalten = [f for f in TECHNIK if any(f in v["technik"] for v in varianten)]
        tabelle = ("<table class=\"technik\"><tr><th>Art.-Nr.</th>" + "".join(f"<th>{KOEPFE[f]}</th>" for f in spalten) + "</tr>"
                   + "".join("<tr><td>" + v["sku"] + "</td>" + "".join(f"<td>{html.escape(v['technik'].get(f, '–'))}</td>" for f in spalten) + "</tr>"
                             for v in varianten) + "</table>")
        werkstoff = next((v["technik"]["Werkstoff"] for v in varianten if v["technik"].get("Werkstoff")), "VHM")
        beschreibung = (f"<p>{html.escape(einleitung)}</p><p><strong>Werkstoff:</strong> {html.escape(werkstoff)}</p>"
                        f"<p><strong>Technische Daten</strong> (Maße in mm)</p>{tabelle}"
                        "<p>Sonderabmessungen und Sonderwinkel fertigen wir auf Anfrage.</p>")
        produkt_id = f"ZUSATZ-{haupt}"
        produkte.append({
            "productId": produkt_id, "name": name, "sku": haupt, "typ": "master", "lieferzeit": None,
            "verfuegbar": None, "lagerbestand": None, "preis_netto": None,
            "preis_ab": min(v["preis_netto"] for v in varianten), "preis_bis": max(v["preis_netto"] for v in varianten),
            "staffelpreise": None, "bilder_api": [], "beschreibung_html": beschreibung, "datenblatt": None,
            "zusatz": True, "varianten": [{k: w for k, w in v.items() if k != "technik"} for v in varianten],
        })
        bilder_index[produkt_id] = bilder(name, foto)
        print(f"  {name}: {len(varianten)} Abmessungen ({varianten[0]['auswahl']['Schneidendurchmesser']} – "
              f"{varianten[-1]['auswahl']['Schneidendurchmesser']} mm), Bild: {bilder_index[produkt_id]['art']}")
    ZIEL.write_text(json.dumps(produkte, ensure_ascii=False, indent=1), encoding="utf-8")
    BILDER_INDEX.write_text(json.dumps(bilder_index, ensure_ascii=False, indent=1), encoding="utf-8")
    print(f"{len(produkte)} Entgrater-Produkte -> {ZIEL.relative_to(WURZEL)}")


if __name__ == "__main__":
    main()
