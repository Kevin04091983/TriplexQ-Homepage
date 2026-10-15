"""Erzeugt die komplette statische TRIPLEX-Q-Website in ./website.

Eingaben:  data/quelle/*  (tools/crawl_triplexq.py)
           data/bilder_index.json + website/assets/img (tools/bilder_aufbereiten.py)
Ausgabe:   website/*.html, website/produkte/*.html, website/assets/js/katalog.js,
           website/assets/datenblaetter/*.pdf, sitemap.xml, robots.txt

Aufruf:  python tools/build_site.py
"""
import datetime as dt
import hashlib
import html
import json
import re
import shutil
import sys
import unicodedata
from pathlib import Path

WURZEL = Path(__file__).resolve().parent.parent
QUELLE = WURZEL / "data" / "quelle"
WEB = WURZEL / "website"
# Aufruf mit "--test": Testfassung für triplex-q.de, ohne Eintrag bei Suchmaschinen
TEST = "--test" in sys.argv
DOMAIN = "https://triplex-q.de" if TEST else "https://www.triplex-q.com"

FIRMA = {
    "name": "Präzisionswerkzeuge Schmidt e.K.",
    "inhaber": "Alexandra Simon",
    "strasse": "Ackerstraße 12",
    "ort": "98544 Zella-Mehlis",
    "tel": "+49 (0) 3682 / 483662",
    "tel_link": "+493682483662",
    "mobil": "+49 160 8592745",
    "mobil_link": "+491608592745",
    "whatsapp": "+49 160 8592745",          # wie im alten Shop (Kopfleiste + Chat "Vertriebsteam")
    "whatsapp_link": "491608592745",
    "mail": "kontakt@triplex-q.de",
    "order": "order@wkz-schmidt.de",
    "instagram": "https://www.instagram.com/triplex_q_tools/",
    "instagram_name": "@triplex_q_tools",
    "facebook": "https://www.facebook.com/praezisionswerkzeugeschmidt/",
    "shopvote": "https://www.shopvote.de/bewertung_triplex_q_com_23060.html",
}

e = html.escape

LAENDER = ["Deutschland", "Belgien", "Bulgarien", "Kroatien", "Tschechien", "Zypern", "Dänemark", "Estland", "Spanien",
           "Frankreich", "Irland", "Italien", "Lettland", "Litauen", "Luxemburg", "Ungarn", "Malta", "Niederlande",
           "Österreich", "Polen", "Portugal", "Rumänien", "Slowenien", "Slowakei", "Finnland", "Schweden",
           "Vereinigtes Königreich", "Griechenland", "Schweiz"]
LAENDER_OPTIONEN = "".join(f"<option>{l}</option>" for l in LAENDER)


def slug(text):
    for a, b in (("ä", "ae"), ("ö", "oe"), ("ü", "ue"), ("Ä", "Ae"), ("Ö", "Oe"), ("Ü", "Ue"), ("ß", "ss")):
        text = text.replace(a, b)
    text = unicodedata.normalize("NFKD", text).encode("ascii", "ignore").decode()
    return re.sub(r"[^a-z0-9]+", "-", text.lower()).strip("-")


def lies(pfad):
    return json.loads((QUELLE / pfad).read_text(encoding="utf-8"))


def euro(betrag):
    return f"{betrag:,.2f} €".replace(",", "X").replace(".", ",").replace("X", ".")


def zahl(text):
    m = re.search(r"\d+(?:[.,]\d+)?", text or "")
    return float(m.group(0).replace(",", ".")) if m else 0.0


# =====================================================================
# Katalog
# =====================================================================
LINIEN = [
    {"id": "hpc", "name": "HPC-Fräser", "bild": "hero-fraeser-farben", "text":
     "Spezialisierte Werkzeuge für die Hochleistungszerspanung. Aus hochwertigem Vollhartmetall (VHM) und dafür "
     "ausgelegt, große Mengen Material in kürzester Zeit zu entfernen – für hochfeste Stähle und anspruchsvolle "
     "Werkstoffe im Werkzeug- und Formenbau."},
    {"id": "tpc", "name": "TPC-Fräser", "bild": "kat-tpc", "text":
     "Hochspezialisierte VHM-Werkzeuge für das trochoidale Fräsen. Spezielle Geometrien und optimierte Fräsbahnen "
     "ermöglichen eine hohe Zerspanungsleistung bei gleichzeitig geringerem Verschleiß."},
    {"id": "schrupp", "name": "Schruppfräser", "bild": "kat-schrupp", "text":
     "Fräswerkzeuge für das grobe Zerspanen – ideal für zähe und harte Werkstoffe in Metallbearbeitung und Maschinenbau."},
    {"id": "mehrzahn", "name": "Mehrzahn- & Schlichtfräser", "bild": "kat-mehrzahn", "text":
     "Präzise Werkzeuge für die Feinbearbeitung – wenn hohe Maßgenauigkeit und glatte Oberflächen gefordert sind."},
    {"id": "alu", "name": "Aluminiumfräser", "bild": None, "text":
     "HPC- und TPC-Fräser für Aluminium und Nichteisenmetalle. VHM mit DLC-Beschichtung (Diamond-Like Carbon) "
     "für außergewöhnliche Härte, Verschleißfestigkeit und polierte Oberflächen."},
    {"id": "vollradius", "name": "Vollradiusfräser", "bild": "kat-vollradius", "text":
     "Fräswerkzeuge für die präzise Bearbeitung mit Vollradius. Zwei Schneiden sorgen für effiziente Materialabtragung "
     "und eine gleichmäßige Belastung des Werkzeugs."},
    {"id": "viertelkreis", "name": "Viertelkreisfräser", "bild": "kat-viertelkreis", "text":
     "VHM-Fräser für das präzise Verrunden von Werkstückkanten – gleichmäßige Radien in vielen Größen."},
    {"id": "standard", "name": "Schaftfräser Z2 / Z3", "bild": None, "text":
     "VHM-Schaftfräser mit zwei oder drei Schneiden für universelle Fräsarbeiten – von Ø 1 bis 20 mm."},
    {"id": "bohrer", "name": "VHM-Hochleistungsbohrer", "bild": "kat-bohrer", "text":
     "Bohrer aus Ultrafeinstkorn-VHM mit TiAlN-Beschichtung – 3xD bis 12xD, mit Innenkühlung direkt an der "
     "Bohrerspitze für mehr Bohrleistung und Standzeit."},
    {"id": "entgrater", "name": "NC-Entgratwerkzeuge", "bild": "kat-entgrater", "anfrage": True, "text":
     "Hochpräzise Werkzeuge zum Entgraten von Bohrungen, Kanten und Konturen in der CNC-Bearbeitung – "
     "in verschiedenen Ausführungen auf Anfrage."},
]


def linien_fuer(name):
    n = name.lower()
    if "hochleistungsbohrer" in n:
        return ["bohrer"]
    if "alu-tpc" in n:
        return ["alu", "tpc"]
    if "alu" in n:
        return ["alu"]
    if "tpc" in n:
        return ["tpc"]
    if "*sr" in n or "sr black" in n:
        return ["schrupp"]
    if " mz " in n + " ":
        return ["mehrzahn"]
    if "bnc2" in n:
        return ["vollradius"]
    if "viertelkreis" in n:
        return ["viertelkreis"]
    if "schaftfräser z" in n:
        return ["standard"]
    return ["hpc"]


def familie(name):
    """Mehrere Shop-Artikel, die in Wahrheit ein Produkt sind, zusammenführen."""
    m = re.search(r"Hochleistungsbohrer (\d+xD)( mit IK)?", name)
    if m:
        return f"TRIPLEX-Q VHM-Hochleistungsbohrer {m.group(1)}{m.group(2) or ''}"
    if re.search(r"steel blue MZ D\d+", name):
        return "TRIPLEX-Q steel blue MZ"
    return None


def grundname(name):
    name = re.sub(r"\s+", " ", name).strip()
    name = re.sub(r"\s*Ø.*$", "", name) if "Set" not in name else name
    name = re.sub(r"\s+\d+,\d+ mm - \d+,\d+ mm$", "", name)
    return name.strip()


def beschreibung_saeubern(h):
    if not h:
        return ""
    h = re.sub(r"<(/?)(h\d)[^>]*>", r"<\1p>", h)
    h = re.sub(r"\s(style|class|data-[\w-]+)=\"[^\"]*\"", "", h)
    h = re.sub(r"<span>|</span>|<div>|</div>", "", h)
    h = h.replace("&nbsp;", " ")
    h = re.sub(r"(<p>\s*</p>)+", "", h)
    h = re.sub(r"\s{2,}", " ", h)
    h = re.sub(r"Holen Sie sich jetzt[^.!]*[.!]\s*", "", h)
    h = re.sub(r"Gönnen Sie sich jetzt[^.!]*[.!]\s*", "", h)
    return h.strip()


def wert_normal(wert):
    w = wert.replace("Ø", "").strip()
    w = re.sub(r"\s*/\s*", "/", w)
    w = re.sub(r"(E?R)\s+", r"\1", w)
    w = re.sub(r"(\d)\.(\d)", r"\1,\2", w)
    w = re.sub(r"(\d)(E?R\d)", r"\1/\2", w)
    w = re.sub(r"^(\d+),(\d)$", r"\1,\g<2>0", w)
    return w


def durchmesser_label(variante, attr, wert, aus_name):
    if not attr.lower().startswith("schneidendurchmesser") or not aus_name:
        return wert_normal(wert)
    m = re.search(r"Ø\s*([^Ø]+?)\s*(?:mm)?\s*$", variante["name"])
    return wert_normal(m.group(1)) if m else wert_normal(wert)


def katalog_bauen(bilder):
    roh = lies("produkte.json")
    gruppen = {}
    for p in roh:
        schluessel = familie(p["name"]) or p["productId"]
        gruppen.setdefault(schluessel, []).append(p)

    datenblatt_ziel = WEB / "assets" / "datenblaetter"
    datenblatt_ziel.mkdir(parents=True, exist_ok=True)

    produkte = []
    for schluessel, teile in gruppen.items():
        erster = teile[0]
        name = schluessel if not re.fullmatch(r"[0-9A-F-]{36}", schluessel) else grundname(erster["name"])
        name = re.sub(r"TRIPLEX-Q\s+", "TRIPLEX-Q ", name)
        varianten, attribute = [], {}
        for teil in teile:
            if teil.get("varianten"):
                # doppelte Attributwerte (z. B. gleicher Ø mit anderem Radius) -> Bezeichnung aus dem Artikelnamen
                kombis = [tuple(sorted(v["auswahl"].items())) for v in teil["varianten"]]
                aus_name = len(set(kombis)) != len(kombis)
                for v in teil["varianten"]:
                    if v.get("ausgeblendet"):
                        continue  # im alten Shop ausgeblendet / nicht bestellbar
                    auswahl = {}
                    for attr, wert in v["auswahl"].items():
                        a = "Schneidendurchmesser" if attr.lower().startswith("schneidendurchmesser") else attr
                        auswahl[a] = durchmesser_label(v, attr, wert, aus_name)
                    varianten.append({"sku": v["sku"], "preis": v["preis_netto"], "auswahl": auswahl,
                                      "lager": v["lagerbestand"], "name": re.sub(r"\s+", " ", v["name"])})
            else:
                aw = {}
                m = re.search(r"MZ D(\d+)", teil["name"])
                if m:
                    aw["Schneidendurchmesser"] = f"{int(m.group(1))},00"
                varianten.append({"sku": teil["sku"], "preis": teil["preis_netto"], "auswahl": aw,
                                  "lager": teil["lagerbestand"], "name": re.sub(r"\s+", " ", teil["name"])})
        # Attribute sammeln, Reihenfolge: Schaftform, Durchmesser, Rest
        for v in varianten:
            for a, w in v["auswahl"].items():
                attribute.setdefault(a, [])
                if w not in attribute[a]:
                    attribute[a].append(w)
        for a in attribute:
            attribute[a].sort(key=lambda w: (zahl(w), w))
        fest = {a: w[0] for a, w in attribute.items() if len(w) == 1}
        waehlbar = [a for a in sorted(attribute, key=lambda a: {"Schaftform": 0, "Schneidendurchmesser": 1}.get(a, 2))
                    if len(attribute[a]) > 1]
        for v in varianten:
            v["auswahl"] = {a: v["auswahl"][a] for a in waehlbar if a in v["auswahl"]}
        varianten.sort(key=lambda v: tuple((zahl(v["auswahl"].get(a, "")), v["auswahl"].get(a, "")) for a in
                                           sorted(waehlbar, key=lambda a: a != "Schneidendurchmesser")))
        schluessel_set = [tuple(v["auswahl"].items()) for v in varianten]
        if len(set(schluessel_set)) != len(schluessel_set):
            print("  ! doppelte Variantenauswahl:", name)

        preise = [v["preis"] for v in varianten if v["preis"] is not None]
        durchm = [zahl(v["auswahl"].get("Schneidendurchmesser", "")) for v in varianten
                  if v["auswahl"].get("Schneidendurchmesser")]
        if not durchm:
            durchm = [zahl(x) for x in re.findall(r"Ø\s*(\d+(?:,\d+)?)", erster["name"])]

        beschreibung = ""
        for t in teile:
            b = beschreibung_saeubern(t.get("beschreibung_html"))
            if len(re.sub("<[^>]+>", "", b)) > len(re.sub("<[^>]+>", "", beschreibung)):
                beschreibung = b
        datenblaetter = []
        for t in teile:
            quelle_url = t.get("datenblatt")
            if not quelle_url:
                continue
            dateiname = quelle_url.rsplit("/", 1)[-1]
            src = QUELLE / "datenblaetter" / t["productId"] / dateiname
            if src.exists():
                ziel_name = slug(Path(dateiname).stem) + ".pdf"
                shutil.copyfile(src, datenblatt_ziel / ziel_name)
                if ziel_name not in datenblaetter:
                    datenblaetter.append(ziel_name)
        lieferzeit = next((t["lieferzeit"] for t in teile if t.get("lieferzeit")), None)
        bild = bilder.get(erster["productId"], {})
        kurzname = re.sub(r"^TRIPLEX-Q\s*", "", name).replace("*", "").strip()
        produkt_slug = slug(kurzname)
        skus = [v["sku"] for v in varianten if v["sku"]]
        produkte.append({
            "id": produkt_slug,
            "name": name.replace(" *", " "),
            "kurz": kurzname,
            "linien": linien_fuer(name),
            "sku": erster["sku"] if len(teile) == 1 else f"{skus[0]} … {skus[-1]}",
            "beschreibung": beschreibung,
            "datenblaetter": datenblaetter,
            "lieferzeit": lieferzeit,
            "bild": bild.get("bild"), "klein": bild.get("klein"), "original": bild.get("original"),
            "bildart": bild.get("art"),
            "fest": fest,
            "attribute": {a: attribute[a] for a in waehlbar},
            "varianten": varianten,
            "preis_ab": min(preise) if preise else None,
            "preis_bis": max(preise) if preise else None,
            "d_min": min(durchm) if durchm else None,
            "d_max": max(durchm) if durchm else None,
            "set": "Set" in name,
            "alte_urls": [t["href"] for t in teile if t.get("href")],
        })

    reihenfolge = {l["id"]: i for i, l in enumerate(LINIEN)}
    produkte.sort(key=lambda p: (reihenfolge[p["linien"][0]], p["set"], p["d_min"] or 0, p["name"]))
    return produkte


# =====================================================================
# Layout
# =====================================================================
ICONS = {
    "cart": '<path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6.2"/><circle cx="10" cy="20.5" r="1.3"/><circle cx="17" cy="20.5" r="1.3"/>',
    "search": '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    "phone": '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/>',
    "mail": '<rect x="2.5" y="4.5" width="19" height="15" rx="2"/><path d="m3 6 9 7 9-7"/>',
    "pin": '<path d="M12 22s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/>',
    "arrow": '<path d="M5 12h14M13 6l6 6-6 6"/>',
    "check": '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
    "truck": '<path d="M2 6h11v10H2zM13 10h4.5l3.5 3.5V16h-8z"/><circle cx="6" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/>',
    "file": '<path d="M14 2.5H6.5a2 2 0 0 0-2 2v15a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V8z"/><path d="M14 2.5V8h5.5M9 13h6M9 17h6"/>',
    "play": '<path d="M8 5.5v13l11-6.5z"/>',
    "menu": '<path d="M3 6h18M3 12h18M3 18h18"/>',
    "close": '<path d="M6 6l12 12M18 6 6 18"/>',
    "plus": '<path d="M12 5v14M5 12h14"/>',
    "minus": '<path d="M5 12h14"/>',
    "star": '<path d="m12 2.8 2.8 5.8 6.3.9-4.6 4.4 1.1 6.3L12 17.2l-5.6 3 1.1-6.3L2.9 9.5l6.3-.9z" fill="currentColor" stroke="none"/>',
    "clock": '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    "shield": '<path d="M12 2.5 4 5.5v6c0 5 3.4 9.3 8 10.5 4.6-1.2 8-5.5 8-10.5v-6z"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
    "tool": '<path d="M14.7 6.3a4 4 0 0 0 5 5L21 12.6a6 6 0 0 1-7.6 1.4L6 21.4a2 2 0 0 1-2.8-2.8L10.6 11A6 6 0 0 1 12 3.4l1.3 1.3a4 4 0 0 0 1.4 1.6z"/>',
    "layers": '<path d="m12 2.5 9.5 5-9.5 5-9.5-5z"/><path d="m2.5 12.5 9.5 5 9.5-5M2.5 17l9.5 5 9.5-5"/>',
    "refresh": '<path d="M20 11a8 8 0 0 0-14.8-4M4 4v4h4M4 13a8 8 0 0 0 14.8 4M20 20v-4h-4"/>',
    "invoice": '<path d="M6 2.5h12v19l-3-2-3 2-3-2-3 2z"/><path d="M9 7.5h6M9 11.5h6M9 15.5h3"/>',
    "facebook": '<path d="M14 8.5h2.5V5H14a4 4 0 0 0-4 4v2.5H7.5V15H10v6h3.5v-6H16l.5-3.5h-3V9a.5.5 0 0 1 .5-.5z"/>',
    "insta": '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.3" cy="6.7" r=".6" fill="currentColor"/>',
    "download": '<path d="M12 3v12M7 10l5 5 5-5M4 20h16"/>',
    "chevron": '<path d="m9 6 6 6-6 6"/>',
    "person": '<circle cx="12" cy="8" r="3.6"/><path d="M4.5 20.5a7.5 7.5 0 0 1 15 0"/>',
    "logout": '<path d="M15 4.5H6.5a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2H15M18 12H10m5-3.5 3.5 3.5-3.5 3.5"/>',
    "prozent": '<path d="M19 5 5 19"/><circle cx="7.5" cy="7.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/>',
    "warnung": '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    "hand": '<path d="m11 17 2 2a1 1 0 1 0 3-3"/><path d="m14 14 2.5 2.5a1 1 0 1 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4"/><path d="m21 3 1 11h-2"/><path d="M3 3 2 14l6.5 6.5a1 1 0 1 0 3-3"/><path d="M3 4h8"/>',
}


# WhatsApp-Zeichen (gefüllt, Simple Icons, CC0)
WA_PFAD = ("M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075"
           "-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198"
           "-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0"
           "-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694"
           ".625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403"
           "h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884"
           " 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0"
           "C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89"
           "-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z")


def wa_icon(klasse="ic"):
    return f'<svg class="{klasse}" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="{WA_PFAD}"/></svg>'


def whatsapp_chat(b):
    """Chat-Fenster "Vertriebsteam" wie im alten Shop. Lädt nichts von WhatsApp/Meta –
    erst der Klick auf "Chat starten" öffnet WhatsApp mit der Nachricht."""
    return f"""
<div class="wa" data-wa data-wa-nummer="{FIRMA['whatsapp_link']}">
  <div class="wa-fenster" id="wa-fenster" role="dialog" aria-labelledby="wa-titel" hidden>
    <div class="wa-kopf">
      <span class="wa-bild"><img src="{b}assets/img/logo/triplex-q-q-weiss.webp" alt="" width="26" height="28"></span>
      <div><strong id="wa-titel">Vertriebsteam</strong><small>TRIPLEX-Q · antwortet per WhatsApp</small></div>
      <button class="wa-zu" type="button" data-wa-zu aria-label="Chat schließen">{icon("close")}</button>
    </div>
    <div class="wa-verlauf">
      <p class="wa-blase">Hey! Beschreiben Sie Ihre Frage, wir antworten schnellstmöglich.<time data-wa-zeit></time></p>
    </div>
    <form class="wa-eingabe" data-wa-form>
      <label for="wa-text" class="sr">Ihre Frage</label>
      <textarea id="wa-text" rows="2" maxlength="1000" placeholder="Ihre Frage …"></textarea>
      <button class="wa-start" type="submit">{wa_icon()} Chat starten</button>
    </form>
    <p class="wa-info">Öffnet WhatsApp mit {FIRMA['whatsapp']}. Dort gelten die <a href="https://www.whatsapp.com/legal/privacy-policy-eea" rel="noopener" target="_blank">Datenschutzhinweise von WhatsApp</a>.</p>
  </div>
  <button class="wa-knopf" type="button" data-wa-knopf aria-expanded="false" aria-controls="wa-fenster" aria-label="WhatsApp-Chat mit dem Vertriebsteam">{wa_icon()}{icon("close")}</button>
</div>"""


def icon(name, klasse="ic"):
    return (f'<svg class="{klasse}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" '
            f'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{ICONS[name]}</svg>')


def kopf(b, aktiv, linien_mit_produkten):
    def nav(href, text, key):
        cur = ' aria-current="page"' if aktiv == key else ""
        return f'<a href="{b}{href}"{cur}>{text}</a>'

    mega = "".join(
        f'<a class="mega-link" href="{b}shop.html#{l["id"]}"><span>{e(l["name"])}</span>'
        f'<small>{n} {"Artikel" if n != 1 else "Artikel"}</small></a>'
        for l, n in linien_mit_produkten if n)
    return f"""
<a class="skip" href="#inhalt">Zum Inhalt springen</a>
<div class="topbar">
  <div class="wrap topbar-in">
    <span>{icon("truck")} Versicherter Versand · Kauf auf Rechnung</span>
    <span class="topbar-r"><a class="topbar-ig" href="{FIRMA['instagram']}" rel="noopener" target="_blank" aria-label="TRIPLEX-Q auf Instagram">{icon("insta")} {FIRMA['instagram_name']}</a>
    <a class="topbar-wa" href="https://wa.me/{FIRMA['whatsapp_link']}" rel="noopener" target="_blank" aria-label="WhatsApp {FIRMA['whatsapp']}">{wa_icon()} {FIRMA['whatsapp']}</a>
    <a href="tel:{FIRMA['tel_link']}">{icon("phone")} {FIRMA['tel']}</a>
    <a href="mailto:{FIRMA['mail']}">{icon("mail")} {FIRMA['mail']}</a></span>
  </div>
</div>
<header class="kopf" data-kopf>
  <div class="wrap kopf-in">
    <a class="marke" href="{b}index.html" aria-label="TRIPLEX-Q Startseite">
      <img class="marke-q" src="{b}assets/img/logo/triplex-q-q-weiss.webp" alt="" width="50" height="53">
      <img class="marke-schrift" src="{b}assets/img/logo/triplex-q-schrift-weiss.webp" alt="TRIPLEX-Q High Performance Cutting" width="150" height="35">
    </a>
    <nav class="hauptnav" data-nav aria-label="Hauptnavigation">
      <div class="nav-mega">
        {nav("shop.html", "Werkzeug-Shop", "shop")}
        <div class="mega"><div class="mega-in">{mega}
          <a class="mega-link mega-alle" href="{b}shop.html"><span>Alle Werkzeuge</span>{icon("arrow")}</a></div></div>
      </div>
      {nav("sonderwerkzeuge.html", "Sonderwerkzeuge", "sonder")}
      {nav("anwendungen.html", "Anwendungen", "anwendungen")}
      {nav("referenzen.html", "Referenzen", "referenzen")}
      {nav("kontakt.html", "Kontakt", "kontakt")}
    </nav>
    <div class="kopf-aktionen">
      <a class="knopf-rund" href="{b}shop.html#suche" aria-label="Werkzeug suchen">{icon("search")}</a>
      <a class="knopf-rund konto-link" href="{b}konto.html" aria-label="Mein Konto" data-konto-link>{icon("person")}<span class="punkt-an" data-konto-an hidden></span></a>
      <a class="knopf-rund warenkorb-link" href="{b}warenkorb.html" aria-label="Warenkorb">{icon("cart")}<span class="badge" data-cart-count hidden>0</span></a>
      <button class="knopf-rund menue-knopf" type="button" data-menue aria-label="Menü öffnen" aria-expanded="false">{icon("menu")}</button>
    </div>
  </div>
</header>"""


def fuss(b):
    return f"""
<footer class="fuss">
  <div class="fuss-swoosh" aria-hidden="true"></div>
  <div class="wrap fuss-grid">
    <div class="fuss-marke">
      <img src="{b}assets/img/logo/triplex-q-logo-weiss.webp" alt="TRIPLEX-Q High Performance Cutting" width="200" height="164" loading="lazy">
      <p>Präzisionswerkzeuge aus Vollhartmetall – Standardprogramm ab Lager, Sonderwerkzeuge nach Zeichnung.</p>
      <div class="sozial">
        <a href="{FIRMA['facebook']}" rel="noopener" target="_blank" aria-label="Präzisionswerkzeuge Schmidt auf Facebook">{icon("facebook")}</a>
        <a href="{FIRMA['instagram']}" rel="noopener" target="_blank" aria-label="TRIPLEX-Q auf Instagram">{icon("insta")}</a>
        <a class="shopvote" href="{FIRMA['shopvote']}" rel="noopener" target="_blank">{icon("star")} ShopVote-Bewertungen</a>
      </div>
    </div>
    <div>
      <h3>Shop</h3>
      <ul>
        <li><a href="{b}shop.html#hpc">HPC-Fräser</a></li>
        <li><a href="{b}shop.html#tpc">TPC-Fräser</a></li>
        <li><a href="{b}shop.html#alu">Aluminiumfräser</a></li>
        <li><a href="{b}shop.html#schrupp">Schruppfräser</a></li>
        <li><a href="{b}shop.html#bohrer">VHM-Hochleistungsbohrer</a></li>
        <li><a href="{b}shop.html">Alle Werkzeuge</a></li>
      </ul>
    </div>
    <div>
      <h3>Unternehmen</h3>
      <ul>
        <li><a href="{b}sonderwerkzeuge.html">Sonderwerkzeuge &amp; special</a></li>
        <li><a href="{b}anwendungen.html">Anwendungen &amp; Videos</a></li>
        <li><a href="{b}referenzen.html">Referenzkunden</a></li>
        <li><a href="{b}kontakt.html">Kontakt</a></li>
        <li><a href="{b}versand.html">Zahlung &amp; Versand</a></li>
      </ul>
    </div>
    <div>
      <h3>Kontakt</h3>
      <address>
        <strong>{FIRMA['name']}</strong><br>
        Inh. {FIRMA['inhaber']}<br>{FIRMA['strasse']}<br>{FIRMA['ort']}
      </address>
      <p class="fuss-kontakt">
        <a href="tel:{FIRMA['tel_link']}">{icon("phone")} {FIRMA['tel']}</a>
        <a href="https://wa.me/{FIRMA['whatsapp_link']}" rel="noopener" target="_blank">{wa_icon()} WhatsApp {FIRMA['whatsapp']}</a>
        <a href="mailto:{FIRMA['mail']}">{icon("mail")} {FIRMA['mail']}</a>
        <a href="mailto:{FIRMA['order']}">{icon("mail")} {FIRMA['order']}</a>
      </p>
      <div class="bezahl">
        <span class="bezahl-logo" title="Zahlung auf Rechnung">{icon("invoice")} Rechnung</span>
        <span class="bezahl-logo ups" title="Versand mit UPS">UPS</span>
      </div>
    </div>
  </div>
  <div class="wrap fuss-unten">
    <span>© <span data-jahr>{dt.date.today().year}</span> {FIRMA['name']} · Home of TRIPLEX-Q</span>
    <nav aria-label="Rechtliches">
      <a href="{b}impressum.html">Impressum</a>
      <a href="{b}datenschutz.html">Datenschutzerklärung</a>
      <a href="{b}cookie-richtlinie.html">Cookie-Richtlinie</a>
      <a href="{b}versand.html">Zahlung &amp; Versand</a>
      <a href="{b}agb.html">Allgemeine Geschäftsbedingungen</a>
      <a href="{b}widerruf.html">Widerrufsrecht</a>
    </nav>
  </div>
</footer>
<div class="toast" data-toast role="status" aria-live="polite"></div>""" + whatsapp_chat(b)


def version(pfad):
    """Kurzer Prüfwert des Dateiinhalts: ändert sich die Datei, lädt jeder Browser sie neu."""
    return hashlib.md5((WEB / pfad).read_bytes()).hexdigest()[:8]


def seite(datei, titel, beschreibung, inhalt, aktiv="", tiefe=0, skripte=(), klasse="", og_bild=None, kopfzusatz=""):
    b = "../" * tiefe
    voll = f"{titel} | TRIPLEX-Q High Performance Cutting" if titel else "TRIPLEX-Q – High Performance Cutting | VHM-Präzisionswerkzeuge"
    js = "".join(f'\n<script src="{b}assets/js/{s}?v={version("assets/js/" + s)}" defer></script>' for s in skripte)
    og = og_bild or "assets/img/logo/triplex-q-logo.png"
    doc = f"""<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{e(voll)}</title>
<meta name="description" content="{e(beschreibung)}">
<link rel="canonical" href="{DOMAIN}/{datei}">
<meta property="og:type" content="website">
<meta property="og:title" content="{e(voll)}">
<meta property="og:description" content="{e(beschreibung)}">
<meta property="og:image" content="{DOMAIN}/{og}">
<meta name="theme-color" content="#06163f">{'<meta name="robots" content="noindex, nofollow">' if TEST else ''}
<link rel="icon" type="image/png" sizes="32x32" href="{b}assets/img/logo/favicon-32.png">
<link rel="apple-touch-icon" href="{b}assets/img/logo/apple-touch-icon.png">
<link rel="preload" href="{b}assets/fonts/barlow-condensed-800-italic.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="{b}assets/fonts/inter-400-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{b}assets/css/style.css?v={version("assets/css/style.css")}">{kopfzusatz}
</head>
<body class="{klasse}" data-basis="{b}">
{kopf(b, aktiv, LINIEN_ZAHLEN)}
<main id="inhalt">
{inhalt}
</main>
{fuss(b)}
<script src="{b}assets/js/app.js?v={version("assets/js/app.js")}" defer></script>{js}
</body>
</html>
"""
    # "TRIPLEX-Q" in Überschriften nicht am Bindestrich umbrechen
    doc = re.sub(r"(<h[123][^>]*>)(.*?)(</h[123]>)",
                 lambda m: m.group(1) + m.group(2).replace("TRIPLEX-Q", '<span class="nw">TRIPLEX-Q</span>') + m.group(3),
                 doc, flags=re.S)
    ziel = WEB / datei
    ziel.parent.mkdir(parents=True, exist_ok=True)
    ziel.write_text(doc, encoding="utf-8")
    SITEMAP.append(datei)


SITEMAP = []
LINIEN_ZAHLEN = []


def seitenkopf(titel, unter, krumen, b="", bild=None):
    pfad = "".join(f'<a href="{b}{h}">{e(t)}</a><span>/</span>' for t, h in krumen)
    deko = f'<img class="seitenkopf-bild" src="{b}assets/img/fotos/{bild}.webp" alt="" loading="eager">' if bild else ""
    return f"""
<section class="seitenkopf">
  <div class="wrap seitenkopf-in">
    <nav class="krumen" aria-label="Brotkrumen"><a href="{b}index.html">Start</a><span>/</span>{pfad}</nav>
    <h1>{titel}</h1>
    {f'<p class="seitenkopf-text">{unter}</p>' if unter else ""}
  </div>
  {deko}
</section>"""


def preis_text(p):
    if p["preis_ab"] is None:
        return "Preis auf Anfrage"
    if p["preis_ab"] == p["preis_bis"]:
        return euro(p["preis_ab"])
    return f"ab {euro(p['preis_ab'])}"


def durchmesser_text(p):
    if p["d_min"] is None:
        return ""
    f = lambda x: f"{x:g}".replace(".", ",")
    if p["d_min"] == p["d_max"]:
        return f"Ø {f(p['d_min'])} mm"
    return f"Ø {f(p['d_min'])} – {f(p['d_max'])} mm"


def produktkarte(p, b=""):
    art = p.get("bildart") or "foto"
    lz = f'<span class="chip chip-gruen">{icon("clock")} {e(p["lieferzeit"])} Tage</span>' if p.get("lieferzeit") else ""
    n = len(p["varianten"])
    return f"""
<article class="pkarte" data-linien="{' '.join(p['linien'])}">
  <a class="pkarte-link" href="{b}produkte/{p['id']}.html">
    <div class="pkarte-buehne pkarte-{art}">
      <img src="{b}assets/img/produkte/{p['klein']}" alt="{e(p['name'])}" loading="lazy" decoding="async">
    </div>
    <div class="pkarte-text">
      <span class="pkarte-linie">{e(next(l['name'] for l in LINIEN if l['id'] == p['linien'][0]))}</span>
      <h3>{e(p['kurz'])}</h3>
      <p class="pkarte-meta">{e(durchmesser_text(p))}{f' · {n} Ausführungen' if n > 1 else ''}</p>
      <div class="pkarte-fuss"><strong data-karten-preis="{p['id']}">{preis_text(p)}</strong><span class="pfeil">{icon("arrow")}</span></div>
      <small class="netto">zzgl. MwSt.</small>
    </div>
  </a>
</article>"""


# =====================================================================
# Seiten
# =====================================================================
REFERENZEN = [
    ("T. Zimmer Maschinenbau & CNC-Technik", "Hünxe", "zimmer"),
    ("Fritzler Zerspanungstechnik GmbH", "Freiburg", "fritzler"),
    ("Zanutta GmbH", "Grünkraut-Gullen", "zanutta"),
    ("SAW Technologie GmbH", "Troisdorf", "saw-technologie"),
    ("E-MILL CNC Technik GmbH", "Wittenwil (CH)", "e-mill"),
    ("MHSmotion GmbH", "Meschede-Enste", "mhs-motion"),
    ("ROTATEC Zerspanungstechnik GmbH", "Bodnegg", "rotatec"),
    ("Limmer Frästechnik", "Finsing", "limmer"),
    ("Bonsack Präzisionstechnik GmbH", "Brotterode-Trusetal", "bonsack"),
    ("Nötzold Maschinenbau GmbH & Co. KG", "Limbach-Oberfrohna", "noetzold"),
    ("CUTMETALL Recycling Tools Germany GmbH", "Eisfeld", "cutmetall"),
    ("Windelband GmbH", "Fredelsloh", "windelband"),
    ("NC-Tronic", "Neustadt", "nc-tronic"),
    ("SPG Metallverarbeitung GmbH", "Föritztal", "spg"),
    ("Wild CNC Technik", "Obersulm", "wild"),
]

BEWERTUNGEN = [
    ("Vielen Dank für die optimale Beratung. Die Fräser sind besser als die vom Wettbewerber. Vielen Dank und weiter so.", "de"),
    ("Very good quality tools and super customer approach, I recommend!", "en"),
    ("Sehr gute Qualität der Fräser / Werkzeuge. Schnelle Hilfe und Beratung.", "de"),
    ("Zeer betrouwbare partner in de snijdende gereedschappen", "nl"),
    ("Sehr netter Kontakt und schnelle Umsetzung in allen Details", "de"),
    ("personal support and great tools!", "en"),
    ("Grandes profesional y sus trabajos tienen un acabado de muchísima calidad. Rápidos en las ofertas y plazos. "
     "Es un placer trabajar con verdaderos profesionales.", "es"),
]

VIDEOS = [
    ("CAjL0qteVgo", "steel blue TPC 3xD – Ø 16 mm", "1.2738 (40CrMnNiMo 8-6-4)",
     [("Vc", "161 m/min"), ("Vf", "3500 mm/min"), ("ap", "48 mm"), ("ae", "0,8 mm")]),
    ("RNCa_3UyBUs", "steel blue TPC 3xD – Ø 12 mm", "1.2316 (X38CrMo16)",
     [("Vc", "205 m/min"), ("fz", "0,11 mm"), ("ap", "34 mm"), ("ae", "0,5 mm")]),
    ("StSrYQWxSkI", "steel blue TPC 3xD – Ø 16 mm", "1.2316 (X38CrMo16)",
     [("Vc", "205 m/min"), ("fz", "0,15 mm"), ("ap", "45 mm"), ("ae", "0,8 mm")]),
    ("nQzHfyyHnFA", "steel blue TPC 3xD – Ø 16 mm", "1.4057 (X17CrNi16-2)",
     [("Vc", "191 m/min"), ("fz", "0,21 mm"), ("ap", "47 mm"), ("ae", "0,7 mm")]),
]

SPECIAL = ["Anzahl der Schneiden (Z)", "Schneidenlänge", "Halsfreischliff", "Eckfase / Eckradius",
           "Schaftausführung HA / HB / HE", "Drallwinkel", "Chipbreaker", "Beschichtung"]


def reels_html():
    """Instagram-Reels (Vorschaubilder liegen auf dem eigenen Server, siehe tools/instagram_reels.py)."""
    datei = WURZEL / "data" / "instagram.json"
    if not datei.exists():
        return ""
    monate = ["Januar", "Februar", "März", "April", "Mai", "Juni", "Juli", "August", "September", "Oktober", "November", "Dezember"]
    teile = []
    for r in json.loads(datei.read_text(encoding="utf-8")):
        j, m, t = (int(x) for x in r["datum"].split("-"))
        datum = f"{t}. {monate[m - 1]} {j}"
        text = r["text"] or "Video auf Instagram"
        kurz = text if len(text) <= 70 else text[:68].rsplit(" ", 1)[0] + " …"
        teile.append(f"""
      <li><button class="reel" type="button" data-reel="{e(r['code'])}" aria-label="Instagram-Video vom {datum} abspielen: {e(kurz)}">
        <img src="assets/img/instagram/{e(r['code'])}.webp" alt="" loading="lazy" width="{r['breite']}" height="{r['hoehe']}">
        <span class="reel-play">{icon("play")}</span>
        <span class="reel-text"><small>{datum}</small>{e(kurz)}</span>
      </button></li>""")
    return "".join(teile)


def videokarte(v, b=""):
    vid, titel, material, daten = v
    werte = "".join(f"<div><dt>{k}</dt><dd>{w}</dd></div>" for k, w in daten)
    return f"""
<article class="video">
  <button class="video-buehne" type="button" data-video="{vid}" aria-label="Video abspielen: {e(titel)}">
    <img src="{b}assets/img/videos/{vid}.webp" alt="" loading="lazy">
    <span class="video-play">{icon("play")}</span>
    <span class="video-hinweis">Mit Klick wird das Video von YouTube geladen.</span>
  </button>
  <div class="video-text">
    <h3>TRIPLEX-Q {e(titel)}</h3>
    <p class="video-material">Werkstoff: <strong>{e(material)}</strong></p>
    <dl class="schnittdaten">{werte}</dl>
  </div>
</article>"""


def startseite(produkte):
    anzahl_var = sum(len(p["varianten"]) for p in produkte)
    linien_html = ""
    for l in LINIEN:
        ps = [p for p in produkte if l["id"] in p["linien"]]
        if l.get("anfrage"):
            ziel, fuss_text = "kontakt.html?betreff=NC-Entgratwerkzeuge", "Auf Anfrage"
        else:
            preise = [p["preis_ab"] for p in ps if p["preis_ab"]]
            ziel = f"shop.html#{l['id']}"
            fuss_text = (f"{len(ps)} {'Produkte' if len(ps) != 1 else 'Produkt'} · ab <span data-ab>{euro(min(preise))}</span>"
                         if preise else "")
        bild = (f"assets/img/fotos/{l['bild']}.webp" if l["bild"]
                else f"assets/img/produkte/{ps[0]['klein']}")
        linien_html += f"""
    <a class="linie" href="{ziel}" data-reveal data-produkte="{' '.join(f"{p['id']}:{p['preis_ab'] if p['preis_ab'] is not None else ''}" for p in ps)}">
      <div class="linie-bild"><img src="{bild}" alt="" loading="lazy"></div>
      <div class="linie-text">
        <h3>{e(l['name'])}</h3>
        <p>{e(l['text'])}</p>
        <span class="linie-fuss">{fuss_text}<span class="pfeil">{icon("arrow")}</span></span>
      </div>
    </a>"""

    highlight_ids = ["steel-blue-hpc", "inox-red-hpc", "hyperplus-hpc", "alu-x", "steel-blue-sr", "steel-blue-tpc-saw-3xd", "steel-blue-bnc2", "vhm-hochleistungsbohrer-5xd-mit-ik"]
    highlights = "".join(produktkarte(p) for i in highlight_ids for p in produkte if p["id"] == i)

    galerie = ["incollage-20220218-143456770", "incollage-20220218-143625530", "incollage-20220206-172751431",
               "incollage-20220218-152811648", "incollage-20220218-154617866", "incollage-20220206-185437294"]
    galerie_html = "".join(f'<img src="assets/img/galerie/{g}-klein.webp" alt="VHM-Sonderwerkzeug von TRIPLEX-Q" loading="lazy">' for g in galerie)

    logos = "".join(f'<li><img src="assets/img/kunden/{k}.webp" alt="{e(n)}" loading="lazy"></li>' for n, _, k in REFERENZEN)
    bewertungen = "".join(f"""
      <figure class="bewertung" lang="{lang}">
        <div class="sterne" aria-label="5 von 5 Sternen">{icon("star") * 5}</div>
        <blockquote>„{e(t)}“</blockquote>
        <figcaption>Kundenbewertung auf ShopVote</figcaption>
      </figure>""" for t, lang in BEWERTUNGEN)

    inhalt = f"""
<section class="hero">
  <div class="hero-grid" aria-hidden="true"></div>
  <div class="hero-swoosh" aria-hidden="true"></div>
  <div class="wrap hero-in">
    <div class="hero-text">
      <p class="eyebrow"><span class="punkt"></span> Home of TRIPLEX-Q · Zella-Mehlis</p>
      <h1>High Performance <em>Cutting.</em></h1>
      <p class="hero-lead">VHM-Präzisionswerkzeuge für die Hochleistungszerspanung: HPC- und TPC-Fräser, Aluminiumfräser
      und Hochleistungsbohrer – direkt bestellen, auf Rechnung, schnell geliefert.</p>
      <div class="hero-cta">
        <a class="btn btn-rot" href="shop.html">Zum Werkzeug-Shop {icon("arrow")}</a>
        <a class="btn btn-glas" href="sonderwerkzeuge.html">Sonderwerkzeug anfragen</a>
      </div>
      <dl class="hero-zahlen">
        <div><dt data-zaehler="{anzahl_var}">{anzahl_var}</dt><dd>Abmessungen im Shop</dd></div>
        <div><dt>Ø 1–20</dt><dd>Millimeter Durchmesser</dd></div>
        <div><dt>5,0 <span class="stern">{icon("star")}</span></dt><dd>Kundenbewertung</dd></div>
      </dl>
    </div>
    <div class="hero-bild">
      <div class="hero-glow" aria-hidden="true"></div>
      <img src="assets/img/fotos/hero-fraeser-farben.webp" alt="TRIPLEX-Q VHM-Fräser mit nACo blue, TiXCo und DLC-Beschichtung" width="1400" height="930" fetchpriority="high">
      <span class="hero-tag tag-1"><i style="--c:#2f6bff"></i>nACo blue</span>
      <span class="hero-tag tag-2"><i style="--c:#d9a431"></i>TiXCo</span>
      <span class="hero-tag tag-3"><i style="--c:#7b4a3a"></i>multipurpose</span>
    </div>
  </div>
  <div class="laufband" aria-hidden="true"><div class="laufband-in">
    {"".join(f"<span>{t}</span>" for t in ["steel blue HPC", "inox red HPC", "HyperPlus HPC", "ALU-X DLC", "TPC-SAW-3xD", "SR Black IK", "BNC2 Vollradius", "VHM-Bohrer 12xD", "Sonderwerkzeuge", "Nachschleifservice", "Beschichtungsservice"] * 2)}
  </div></div>
</section>

<section class="preishinweis" aria-labelledby="preishinweis-titel">
  <div class="wrap">
    <div class="preishinweis-karte">
      <div class="preishinweis-kopf">
        <span class="preishinweis-symbol">{icon("warnung")}</span>
        <h2 id="preishinweis-titel">Wichtiger Hinweis <span>zu unseren Preisen</span></h2>
      </div>
      <div class="preishinweis-text">
        <p>Aufgrund aktueller Rohstoff- und Materialpreisentwicklungen befinden sich unsere Preisangaben derzeit in Überarbeitung.</p>
        <p><strong>Die auf dieser Website angezeigten Preise sind daher aktuell nicht verbindlich.</strong></p>
        <p>Bei einer Bestellung kann der tatsächliche Preis abweichen. Selbstverständlich informieren wir Sie vor der Auftragsbestätigung über den gültigen Preis.</p>
        <p class="preishinweis-dank">{icon("hand")} Vielen Dank für Ihr Verständnis.</p>
      </div>
    </div>
  </div>
</section>

<section class="vorteile">
  <div class="wrap vorteile-grid">
    <div class="vorteil" data-reveal>{icon("layers")}<h3>Vollhartmetall &amp; Hightech-Beschichtung</h3><p>nACo blue, TiXCo, TiAlN und DLC – abgestimmt auf Stahl, Edelstahl, Aluminium und harte Werkstoffe.</p></div>
    <div class="vorteil" data-reveal>{icon("clock")}<h3>Schnell verfügbar</h3><p>Standardwerkzeuge ab Lager, Lieferzeit meist 1–3 Werktage – versicherter Versand.</p></div>
    <div class="vorteil" data-reveal>{icon("invoice")}<h3>Einfach auf Rechnung</h3><p>Bestellen ohne Kundenkonto. Zahlung bequem per Rechnung, Versandpauschale 7,90 € in Deutschland.</p></div>
    <div class="vorteil" data-reveal>{icon("tool")}<h3>Sonderwerkzeuge &amp; Service</h3><p>Konstruktion und Fertigung nach Zeichnung – dazu Nachschleif- und Beschichtungsservice.</p></div>
  </div>
</section>

<section class="abschnitt">
  <div class="wrap">
    <div class="abschnitt-kopf" data-reveal>
      <p class="eyebrow dunkel">Produktlinien</p>
      <h2>Das richtige Werkzeug für jede Zerspanungsaufgabe</h2>
      <p>Vom Allrounder bis zum Spezialisten: Wählen Sie Ihre Werkzeuglinie und finden Sie die passende Abmessung.</p>
    </div>
    <div class="linien">{linien_html}
    </div>
  </div>
</section>

<section class="abschnitt abschnitt-grau">
  <div class="wrap">
    <div class="abschnitt-kopf zeile" data-reveal>
      <div><p class="eyebrow dunkel">Beliebt im Shop</p><h2>Bewährte Werkzeuge</h2></div>
      <a class="btn btn-linie" href="shop.html">Alle Werkzeuge {icon("arrow")}</a>
    </div>
    <div class="pkarten">{highlights}</div>
  </div>
</section>

<section class="special">
  <div class="wrap special-in">
    <div class="special-bild" data-reveal>
      <img src="assets/img/fotos/sonder-reihe-profile.webp" alt="TRIPLEX-Q Semi-Standard- und Sonderwerkzeuge" loading="lazy">
    </div>
    <div class="special-text" data-reveal>
      <p class="eyebrow">TRIPLEX-Q special</p>
      <h2>Ihr personalisierter TRIPLEX-Q</h2>
      <p>Sie benötigen ein Semi-Standardwerkzeug? Wir modifizieren unsere TRIPLEX-Q Linien nach Ihren Wünschen – schnell, flexibel, unkompliziert.</p>
      <ul class="haken">{"".join(f"<li>{icon('check')}{s}</li>" for s in SPECIAL)}</ul>
      <div class="hero-cta">
        <a class="btn btn-rot" href="kontakt.html?betreff=TRIPLEX-Q%20special">Anfrage stellen {icon("arrow")}</a>
        <a class="btn btn-glas" href="sonderwerkzeuge.html">Mehr erfahren</a>
      </div>
    </div>
  </div>
</section>

<section class="abschnitt">
  <div class="wrap sonder-teaser">
    <div class="abschnitt-kopf" data-reveal>
      <p class="eyebrow dunkel">VHM-Sonderwerkzeuge</p>
      <h2>Wir konstruieren und fertigen Ihr Sonderwerkzeug</h2>
      <p>Profilfräser, Stufenbohrer, T-Nutenfräser, Senker, Gewindefräser: Nach Ihrer Zeichnung oder Ihrem Werkstück entsteht das passende Werkzeug aus Vollhartmetall.</p>
      <a class="btn btn-blau" href="sonderwerkzeuge.html">Galerie ansehen {icon("arrow")}</a>
    </div>
    <div class="mosaik" data-reveal>{galerie_html}</div>
  </div>
</section>

<section class="abschnitt abschnitt-navy">
  <div class="wrap">
    <div class="abschnitt-kopf zeile" data-reveal>
      <div><p class="eyebrow">Anwendungsbeispiele</p><h2>TRIPLEX-Q im Einsatz</h2>
      <p>Echte Bearbeitungen bei unseren Kunden – mit den verwendeten Schnittdaten.</p></div>
      <a class="btn btn-glas" href="anwendungen.html">Alle Videos {icon("arrow")}</a>
    </div>
    <div class="videos">{videokarte(VIDEOS[0])}{videokarte(VIDEOS[3])}</div>
  </div>
</section>

<section class="abschnitt referenzen-teaser">
  <div class="wrap">
    <div class="abschnitt-kopf mitte" data-reveal>
      <p class="eyebrow dunkel">Referenzkunden</p>
      <h2>Zerspaner vertrauen auf TRIPLEX-Q</h2>
    </div>
  </div>
  <div class="logoband"><ul class="logoband-in">{logos}{logos.replace('<li>', '<li aria-hidden="true">')}</ul></div>
  <div class="wrap">
    <div class="bewertungen" data-karussell>{bewertungen}</div>
    <p class="mitte"><a class="textlink" href="{FIRMA['shopvote']}" target="_blank" rel="noopener">Alle Bewertungen auf ShopVote {icon("arrow")}</a></p>
  </div>
</section>

<section class="abschnitt instagram-band" id="instagram">
  <div class="wrap">
    <div class="abschnitt-kopf zeile" data-reveal>
      <div><p class="eyebrow">Instagram · {FIRMA['instagram_name']}</p>
      <h2>TRIPLEX-Q in Aktion</h2>
      <p>Videos aus Fertigung und Bearbeitung – direkt von unserem Instagram-Kanal.</p></div>
      <a class="btn btn-insta" href="{FIRMA['instagram']}" target="_blank" rel="noopener">{icon("insta")} Auf Instagram folgen</a>
    </div>
  </div>
  <div class="reels-rahmen">
    <button class="reels-pfeil links" type="button" data-reels-pfeil="-1" aria-label="Zurück blättern">{icon("chevron")}</button>
    <ul class="reels" data-reels>{reels_html()}
    </ul>
    <button class="reels-pfeil rechts" type="button" data-reels-pfeil="1" aria-label="Weiter blättern">{icon("chevron")}</button>
  </div>
  <p class="wrap reels-hinweis">Beim Abspielen wird das Video von Instagram (Meta) geladen. Es gelten die
  <a href="https://privacycenter.instagram.com/policy" target="_blank" rel="noopener">Datenschutzhinweise von Instagram</a>.</p>
</section>

{kontakt_block()}
"""
    seite("index.html", "", "TRIPLEX-Q High Performance Cutting: VHM-Fräser, HPC- und TPC-Fräser, Aluminiumfräser und "
          "Hochleistungsbohrer online kaufen – auf Rechnung. Sonderwerkzeuge von Präzisionswerkzeuge Schmidt, Zella-Mehlis.",
          inhalt, "start", klasse="start")


def kontakt_block(b="", betreff=""):
    return f"""
<section class="kontakt-band" id="kontakt">
  <div class="wrap kontakt-in">
    <div class="kontakt-info" data-reveal>
      <p class="eyebrow">Kontakt</p>
      <h2>Wir beraten Sie gerne.</h2>
      <p>Fragen zu Schnittdaten, Werkzeugauswahl oder einem Sonderwerkzeug? Rufen Sie an oder schreiben Sie uns – wir melden uns schnell.</p>
      <ul class="kontakt-liste">
        <li>{icon("phone")}<div><small>Telefon</small><a href="tel:{FIRMA['tel_link']}">{FIRMA['tel']}</a></div></li>
        <li>{icon("phone")}<div><small>Mobil</small><a href="tel:{FIRMA['mobil_link']}">{FIRMA['mobil']}</a></div></li>
        <li>{icon("mail")}<div><small>E-Mail</small><a href="mailto:{FIRMA['mail']}">{FIRMA['mail']}</a></div></li>
        <li>{icon("pin")}<div><small>Adresse</small><span>{FIRMA['name']}<br>{FIRMA['strasse']}, {FIRMA['ort']}</span></div></li>
      </ul>
    </div>
    <form class="formular karte" data-formular="kontakt" novalidate data-reveal>
      <h3>Nachricht senden</h3>
      <div class="feld-zeile">
        <label class="feld"><span>Name *</span><input name="name" autocomplete="name" required></label>
        <label class="feld"><span>Firma</span><input name="firma" autocomplete="organization"></label>
      </div>
      <div class="feld-zeile">
        <label class="feld"><span>E-Mail *</span><input name="email" type="email" autocomplete="email" required></label>
        <label class="feld"><span>Telefon</span><input name="telefon" type="tel" autocomplete="tel"></label>
      </div>
      <label class="feld"><span>Betreff</span><input name="betreff" value="{e(betreff)}" data-betreff></label>
      <label class="feld"><span>Nachricht *</span><textarea name="nachricht" rows="5" maxlength="2000" required></textarea></label>
      <label class="feld-hp" aria-hidden="true">Bitte leer lassen<input name="website" tabindex="-1" autocomplete="off"></label>
      <label class="check"><input type="checkbox" name="datenschutz" required><span>Ich habe die <a href="{b}datenschutz.html">Datenschutzerklärung</a> zur Kenntnis genommen. *</span></label>
      <button class="btn btn-rot breit" type="submit">Nachricht senden {icon("arrow")}</button>
      <p class="formular-status" data-status></p>
    </form>
  </div>
</section>"""


def shopseite(produkte):
    filter_html = "".join(
        f'<button class="filter-chip" type="button" data-filter="{l["id"]}">{e(l["name"])} <small>{n}</small></button>'
        for l, n in LINIEN_ZAHLEN if n)
    karten = "".join(produktkarte(p) for p in produkte)
    inhalt = f"""
{seitenkopf("Werkzeug-Shop", "Alle TRIPLEX-Q Werkzeuge mit Preisen, Abmessungen und Datenblättern. Bestellung bequem auf Rechnung.", [("Shop", "shop.html")], bild="kat-schrupp")}
<section class="shop">
  <div class="wrap">
    <div class="shop-leiste" id="suche">
      <label class="suche">{icon("search")}<input type="search" placeholder="Werkzeug, Artikelnummer oder Beschichtung suchen …" data-suche aria-label="Suche"></label>
      <label class="durchmesser">Ø <input type="number" min="0.5" max="20" step="0.05" inputmode="decimal" placeholder="mm" data-durchmesser aria-label="Durchmesser in mm"></label>
      <label class="sortierung"><span class="sr">Sortierung</span>
        <select data-sortierung>
          <option value="empfehlung">Empfehlung</option>
          <option value="preis-auf">Preis aufsteigend</option>
          <option value="preis-ab">Preis absteigend</option>
          <option value="name">Name A–Z</option>
        </select>
      </label>
    </div>
    <div class="filter" role="group" aria-label="Produktlinien">
      <button class="filter-chip aktiv" type="button" data-filter="alle">Alle <small>{len(produkte)}</small></button>{filter_html}
    </div>
    <p class="treffer" data-treffer aria-live="polite"></p>
    <div class="pkarten pkarten-shop" data-produkte>{karten}</div>
    <div class="leer" data-leer hidden>
      <h3>Kein passendes Werkzeug gefunden</h3>
      <p>Wir fertigen auch Sonderabmessungen. Fragen Sie uns einfach an.</p>
      <a class="btn btn-rot" href="kontakt.html?betreff=Werkzeuganfrage">Anfrage senden {icon("arrow")}</a>
    </div>
    <aside class="entgrater-hinweis karte">
      <img src="assets/img/fotos/kat-entgrater.webp" alt="" loading="lazy">
      <div><h3>NC-Entgratwerkzeuge &amp; Sonderabmessungen</h3>
      <p>Nicht im Shop gelistet? Entgratwerkzeuge, Semi-Standard und Sonderwerkzeuge liefern wir auf Anfrage.</p></div>
      <a class="btn btn-blau" href="kontakt.html?betreff=Werkzeuganfrage">Anfragen {icon("arrow")}</a>
    </aside>
  </div>
</section>"""
    seite("shop.html", "Werkzeug-Shop", "TRIPLEX-Q Shop: HPC-Fräser, TPC-Fräser, Schruppfräser, Mehrzahnfräser, Aluminiumfräser, "
          "Vollradius- und Viertelkreisfräser sowie VHM-Hochleistungsbohrer online bestellen.",
          inhalt, "shop", skripte=["katalog.js", "shop.js"])


def produktseite(p, produkte):
    b = "../"
    linie = next(l for l in LINIEN if l["id"] == p["linien"][0])
    art = p.get("bildart") or "foto"
    fest = "".join(f"<div><dt>{e(k)}</dt><dd>{e(v)}</dd></div>" for k, v in p["fest"].items())
    specs = f"""
      <dl class="specs">
        <div><dt>Artikelnummer</dt><dd data-sku>{e(p['sku'] or '–')}</dd></div>
        {f'<div><dt>Durchmesser</dt><dd>{e(durchmesser_text(p))}</dd></div>' if p['d_min'] else ''}
        {fest}
        <div><dt>Werkstoff</dt><dd>Vollhartmetall (VHM)</dd></div>
        {f'<div><dt>Lieferzeit</dt><dd>{e(p["lieferzeit"])} Werktage</dd></div>' if p.get('lieferzeit') else ''}
      </dl>"""
    auswahl = ""
    for attr, werte in p["attribute"].items():
        viele = len(werte) > 24
        knoepfe = "".join(f'<button type="button" class="wahl" data-wert="{e(w)}">{e(w)}</button>' for w in werte)
        label = "Schneiden-Ø (mm)" if attr == "Schneidendurchmesser" else attr
        filter_feld = (f'<input class="wahl-filter" type="search" inputmode="decimal" placeholder="Ø eingeben, z. B. 8,5" '
                       f'data-wahl-filter aria-label="Durchmesser filtern">') if viele else ""
        auswahl += f"""
      <fieldset class="auswahl" data-attr="{e(attr)}">
        <legend>{e(label)} <span data-gewaehlt></span></legend>
        {filter_feld}
        <div class="wahl-gitter{' wahl-scroll' if viele else ''}">{knoepfe}</div>
      </fieldset>"""
    datenblaetter = "".join(
        f'<a class="datenblatt" href="{b}assets/datenblaetter/{d}" target="_blank" rel="noopener">{icon("file")}'
        f'<span><strong>Produktdatenblatt</strong><small>Schnittdaten · PDF</small></span>{icon("download")}</a>'
        for d in p["datenblaetter"])
    tabelle = ""
    if len(p["varianten"]) > 1:
        attrs = list(p["attribute"].keys())
        kopfzeile = "".join(f"<th>{'Ø' if a == 'Schneidendurchmesser' else e(a)}</th>" for a in attrs)
        zeilen = "".join(
            f'<tr data-sku="{e(v["sku"] or "")}"><td>{e(v["sku"] or "")}</td>' + "".join(f'<td>{e(v["auswahl"].get(a, ""))}</td>' for a in attrs) +
            f'<td class="r" data-preis-zelle>{euro(v["preis"]) if v["preis"] is not None else "auf Anfrage"}</td>'
            f'<td class="r"><button class="knopf-mini" type="button" data-schnell="{e(v["sku"])}" aria-label="{e(v["sku"])} in den Warenkorb"'
            f'{" hidden" if v["preis"] is None else ""}>{icon("plus")}</button>' +
            "</td></tr>" for v in p["varianten"])
        tabelle = f"""
<section class="abschnitt abschnitt-grau" id="alle-ausfuehrungen">
  <div class="wrap">
    <div class="abschnitt-kopf zeile"><div><p class="eyebrow dunkel">Schnellbestellung</p><h2>Alle {len(p['varianten'])} Ausführungen</h2></div>
    <label class="suche klein">{icon("search")}<input type="search" placeholder="Artikelnummer oder Ø" data-tabellen-suche aria-label="Ausführungen filtern"></label></div>
    <div class="tabelle-rahmen"><table class="tabelle" data-tabelle>
      <thead><tr><th>Artikel-Nr.</th>{kopfzeile}<th class="r">Preis netto</th><th class="r"><span class="sr">Warenkorb</span></th></tr></thead>
      <tbody>{zeilen}</tbody>
    </table></div>
  </div>
</section>"""
    verwandt = [x for x in produkte if x["id"] != p["id"] and set(x["linien"]) & set(p["linien"])][:4]
    if len(verwandt) < 4:
        verwandt += [x for x in produkte if x["id"] != p["id"] and x not in verwandt][: 4 - len(verwandt)]
    daten = {"id": p["id"], "name": p["name"], "bild": p["klein"], "attribute": list(p["attribute"].keys()),
             "varianten": [{"sku": v["sku"], "preis": v["preis"], "auswahl": v["auswahl"]} for v in p["varianten"]]}
    galerie = f"""
        <button class="thumb aktiv" type="button" data-bild="{b}assets/img/produkte/{p['bild']}" data-art="{art}"><img src="{b}assets/img/produkte/{p['klein']}" alt=""></button>
        <button class="thumb" type="button" data-bild="{b}assets/img/produkte/{p['original']}" data-art="foto"><img src="{b}assets/img/produkte/{p['original']}" alt=""></button>"""
    beschreibung = p["beschreibung"] or f"<p>{e(linie['text'])}</p>"
    json_ld = json.dumps({
        "@context": "https://schema.org", "@type": "Product", "name": p["name"], "sku": p["sku"],
        "brand": {"@type": "Brand", "name": "TRIPLEX-Q"}, "image": f"{DOMAIN}/assets/img/produkte/{p['bild']}",
        "description": re.sub("<[^>]+>", " ", beschreibung).strip()[:400],
        "offers": {"@type": "AggregateOffer", "priceCurrency": "EUR", "lowPrice": p["preis_ab"], "highPrice": p["preis_bis"],
                   "offerCount": len(p["varianten"])} if p["preis_ab"] else None,
    }, ensure_ascii=False)
    inhalt = f"""
<section class="produkt">
  <div class="wrap">
    <nav class="krumen dunkel" aria-label="Brotkrumen"><a href="{b}index.html">Start</a><span>/</span><a href="{b}shop.html">Shop</a><span>/</span><a href="{b}shop.html#{linie['id']}">{e(linie['name'])}</a><span>/</span><span>{e(p['kurz'])}</span></nav>
    <div class="produkt-grid">
      <div class="produkt-medien">
        <div class="produkt-buehne buehne-{art}" data-buehne>
          <img src="{b}assets/img/produkte/{p['bild']}" alt="{e(p['name'])}" data-hauptbild>
        </div>
        <div class="thumbs">{galerie}</div>
      </div>
      <div class="produkt-info" data-produkt>
        <p class="eyebrow dunkel">{e(linie['name'])}</p>
        <h1>{e(p['name'])}</h1>
        <p class="produkt-unter">{e(durchmesser_text(p))}{f' · {len(p["varianten"])} Ausführungen' if len(p['varianten']) > 1 else ''}</p>
        <div class="preisbox">
          <div class="preis" data-preis>{preis_text(p)}</div>
          <small>zzgl. MwSt., zzgl. <a href="{b}versand.html">Versand</a></small>
        </div>
        {auswahl}
        <p class="auswahl-hinweis" data-hinweis></p>
        <div class="kaufen">
          <div class="menge"><button type="button" data-menge="-1" aria-label="Menge verringern">{icon("minus")}</button><input type="number" value="1" min="1" max="999" data-menge-feld aria-label="Menge"><button type="button" data-menge="1" aria-label="Menge erhöhen">{icon("plus")}</button></div>
          <button class="btn btn-rot breit" type="button" data-kaufen {'disabled' if p['preis_ab'] is None else ''}>{icon("cart")} In den Warenkorb</button>
        </div>
        <ul class="produkt-usps">
          <li>{icon("truck")} Versicherter Versand</li>
          <li>{icon("invoice")} Kauf auf Rechnung</li>
          <li>{icon("phone")} Beratung: <a href="tel:{FIRMA['tel_link']}">{FIRMA['tel']}</a></li>
        </ul>
        {datenblaetter}
        {specs}
      </div>
    </div>
    <div class="produkt-beschreibung">
      <h2>Beschreibung</h2>
      {beschreibung}
    </div>
  </div>
</section>
{tabelle}
<section class="abschnitt">
  <div class="wrap">
    <div class="abschnitt-kopf"><p class="eyebrow dunkel">Passend dazu</p><h2>Weitere Werkzeuge</h2></div>
    <div class="pkarten">{"".join(produktkarte(x, b) for x in verwandt)}</div>
  </div>
</section>
<script type="application/json" id="produkt-daten">{json.dumps(daten, ensure_ascii=False)}</script>"""
    beschr = re.sub(r"\s+", " ", re.sub("<[^>]+>", " ", beschreibung)).strip()
    seite(f"produkte/{p['id']}.html", p["name"], (beschr[:150] + "…") if len(beschr) > 150 else beschr,
          inhalt, "shop", tiefe=1, skripte=["produkt.js"], og_bild=f"assets/img/produkte/{p['bild']}",
          kopfzusatz=f'\n<script type="application/ld+json">{json_ld}</script>')


def sonderseite():
    galerie = sorted((WEB / "assets" / "img" / "galerie").glob("*-klein.webp"))
    kacheln = "".join(
        f'<a class="galerie-bild" href="assets/img/galerie/{g.name.replace("-klein", "")}" data-lightbox>'
        f'<img src="assets/img/galerie/{g.name}" alt="VHM-Sonderwerkzeug" loading="lazy"></a>' for g in galerie)
    inhalt = f"""
{seitenkopf("Sonderwerkzeuge &amp; TRIPLEX-Q special", "Vom modifizierten Standardfräser bis zum komplexen VHM-Profilwerkzeug nach Zeichnung.", [("Sonderwerkzeuge", "sonderwerkzeuge.html")], bild="sonder-stufe-gold")}
<section class="abschnitt">
  <div class="wrap zwei-spalten">
    <div data-reveal>
      <p class="eyebrow dunkel">TRIPLEX-Q special</p>
      <h2>Ihr personalisierter TRIPLEX-Q</h2>
      <p class="gross">Sie benötigen ein Semi-Standardwerkzeug? Wir modifizieren unsere TRIPLEX-Q Linien nach Ihren Wünschen.</p>
      <ul class="haken zwei">{"".join(f"<li>{icon('check')}{s}</li>" for s in SPECIAL)}</ul>
      <p class="motto">Schnell · Flexibel · Unkompliziert</p>
      <a class="btn btn-rot" href="kontakt.html?betreff=TRIPLEX-Q%20special">special anfragen {icon("arrow")}</a>
    </div>
    <div class="bild-karte" data-reveal><img src="assets/img/fotos/img-4762.webp" alt="Semi-Standard-Werkzeuge TRIPLEX-Q special" loading="lazy"></div>
  </div>
</section>
<section class="abschnitt abschnitt-navy">
  <div class="wrap zwei-spalten umgekehrt">
    <div data-reveal>
      <p class="eyebrow">VHM-Sonderwerkzeuge</p>
      <h2>Wir konstruieren und fertigen Ihr VHM-Sonderwerkzeug</h2>
      <p class="gross">Senden Sie uns Ihre Zeichnung, ein Muster oder beschreiben Sie Ihre Bearbeitungsaufgabe. Wir entwickeln das passende Werkzeug – von Stufenbohrern und Profilfräsern bis zu T-Nuten- und Formfräsern.</p>
      <ul class="schritte">
        <li><b>1</b><div><h3>Anfrage</h3><p>Zeichnung, Werkstück oder Skizze an {FIRMA['mail']}</p></div></li>
        <li><b>2</b><div><h3>Konstruktion &amp; Angebot</h3><p>Werkzeugauslegung mit Geometrie und Beschichtung</p></div></li>
        <li><b>3</b><div><h3>Fertigung</h3><p>Präzise geschliffen aus Vollhartmetall</p></div></li>
      </ul>
      <a class="btn btn-rot" href="kontakt.html?betreff=VHM-Sonderwerkzeug">Sonderwerkzeug anfragen {icon("arrow")}</a>
    </div>
    <div class="bild-karte hell" data-reveal><img src="assets/img/fotos/sonderwerkzeug-zeichnung.webp" alt="Sonderwerkzeug mit technischer Zeichnung" loading="lazy"></div>
  </div>
</section>
<section class="abschnitt">
  <div class="wrap">
    <div class="abschnitt-kopf" data-reveal><p class="eyebrow dunkel">Galerie</p><h2>Gefertigte Sonderwerkzeuge</h2>
    <p>Eine Auswahl aus unserer Fertigung. Klicken Sie auf ein Bild für die Großansicht.</p></div>
    <div class="galerie">{kacheln}</div>
  </div>
</section>
<section class="abschnitt abschnitt-grau">
  <div class="wrap service-grid">
    <div class="service karte" data-reveal>{icon("refresh")}<h3>Nachschleifservice</h3><p>Stumpfe VHM-Werkzeuge werden nachgeschliffen und sind wieder einsatzbereit – wirtschaftlich und nachhaltig.</p></div>
    <div class="service karte" data-reveal>{icon("layers")}<h3>Beschichtungsservice</h3><p>Neue Beschichtung nach dem Nachschleifen für volle Standzeit.</p></div>
    <div class="service karte" data-reveal>{icon("tool")}<h3>Beratung</h3><p>Wir helfen bei Werkzeugauswahl und Schnittdaten – telefonisch oder direkt an der Maschine.</p></div>
  </div>
</section>
{kontakt_block(betreff="Sonderwerkzeug")}
<div class="lightbox" data-lightbox-box hidden><button type="button" class="knopf-rund" data-lightbox-zu aria-label="Schließen">{icon("close")}</button><img alt=""></div>"""
    seite("sonderwerkzeuge.html", "Sonderwerkzeuge & TRIPLEX-Q special",
          "VHM-Sonderwerkzeuge nach Zeichnung und personalisierte TRIPLEX-Q Fräser: Schneidenanzahl, Schneidenlänge, "
          "Eckradius, Schaft, Drallwinkel und Beschichtung nach Wunsch. Dazu Nachschleif- und Beschichtungsservice.",
          inhalt, "sonder", klasse="mit-lightbox")


def anwendungsseite():
    inhalt = f"""
{seitenkopf("Anwendungsbeispiele &amp; Kundenvideos", "TRIPLEX-Q Werkzeuge bei der Arbeit – mit Werkstoff und Schnittdaten aus der Praxis.", [("Anwendungen", "anwendungen.html")], bild="kat-tpc")}
<section class="abschnitt">
  <div class="wrap">
    <p class="hinweis-box">{icon("shield")} Zum Schutz Ihrer Daten werden die Videos erst nach einem Klick von YouTube geladen. Dabei gelten die <a href="datenschutz.html">Datenschutzhinweise</a>.</p>
    <div class="videos videos-hell">{"".join(videokarte(v) for v in VIDEOS)}</div>
  </div>
</section>
<section class="abschnitt abschnitt-grau">
  <div class="wrap zwei-spalten">
    <div><p class="eyebrow dunkel">Im Video verwendet</p><h2>TRIPLEX-Q steel blue TPC</h2>
    <p class="gross">Trochoidalfräser mit Spanbrecher und nanocomposite Beschichtung nACo blue – für hohe Zustellungen bei geringem Verschleiß.</p>
    <a class="btn btn-rot" href="produkte/steel-blue-tpc-saw-3xd.html">Zum Produkt {icon("arrow")}</a></div>
    <div class="bild-karte transparent"><img src="assets/img/produkte/steel-blue-tpc-saw-3xd-6-00-mm-16-00-mm.webp" alt="TRIPLEX-Q steel blue TPC-SAW-3xD" loading="lazy"></div>
  </div>
</section>"""
    seite("anwendungen.html", "Anwendungsbeispiele & Kundenvideos",
          "Videos von TRIPLEX-Q Fräsern im Einsatz bei Kunden – mit Werkstoff, Vc, fz, ap und ae.", inhalt, "anwendungen")


def referenzseite():
    karten = "".join(f"""
    <li class="referenz karte" data-reveal>
      <div class="referenz-logo"><img src="assets/img/kunden/{k}.webp" alt="Logo {e(n)}" loading="lazy"></div>
      <h3>{e(n)}</h3><p>{icon("pin")} {e(o)}</p>
    </li>""" for n, o, k in REFERENZEN)
    bewertungen = "".join(f"""
      <figure class="bewertung" lang="{lang}">
        <div class="sterne" aria-label="5 von 5 Sternen">{icon("star") * 5}</div>
        <blockquote>„{e(t)}“</blockquote>
        <figcaption>Kundenbewertung auf ShopVote</figcaption>
      </figure>""" for t, lang in BEWERTUNGEN)
    inhalt = f"""
{seitenkopf("Referenzkunden", "Zerspanungsbetriebe, Maschinenbauer und CNC-Fertiger aus Deutschland und der Schweiz arbeiten mit TRIPLEX-Q.", [("Referenzen", "referenzen.html")], bild="sonder-reihe-gold")}
<section class="abschnitt">
  <div class="wrap"><ul class="referenzen">{karten}</ul></div>
</section>
<section class="abschnitt abschnitt-grau">
  <div class="wrap">
    <div class="abschnitt-kopf mitte"><p class="eyebrow dunkel">Kundenstimmen</p><h2>5,0 von 5 Sternen</h2></div>
    <div class="bewertungen raster">{bewertungen}</div>
    <p class="mitte"><a class="textlink" href="{FIRMA['shopvote']}" target="_blank" rel="noopener">Alle Bewertungen auf ShopVote {icon("arrow")}</a></p>
  </div>
</section>"""
    seite("referenzen.html", "Referenzkunden", "Referenzkunden von TRIPLEX-Q und Präzisionswerkzeuge Schmidt sowie "
          "Kundenbewertungen auf ShopVote.", inhalt, "referenzen")


def kontaktseite():
    inhalt = f"""
{seitenkopf("Kontakt", "Beratung, Angebote, Sonderwerkzeuge – wir sind für Sie da.", [("Kontakt", "kontakt.html")])}
{kontakt_block()}
<section class="abschnitt abschnitt-grau">
  <div class="wrap service-grid">
    <div class="service karte">{icon("pin")}<h3>Selbstabholung</h3><p>Bestellungen können zu unseren Geschäftszeiten in {FIRMA['strasse']}, {FIRMA['ort']} abgeholt werden. Sie erhalten eine Nachricht, sobald die Ware bereitliegt.</p></div>
    <div class="service karte">{icon("mail")}<h3>Bestellungen</h3><p>Fragen zu einer laufenden Bestellung: <a href="mailto:{FIRMA['order']}">{FIRMA['order']}</a></p></div>
    <div class="service karte">{icon("tool")}<h3>Sonderwerkzeuge</h3><p>Zeichnungen und Anfragen an <a href="mailto:{FIRMA['mail']}">{FIRMA['mail']}</a></p></div>
  </div>
</section>"""
    seite("kontakt.html", "Kontakt", f"Kontakt zu TRIPLEX-Q / {FIRMA['name']}, {FIRMA['strasse']}, {FIRMA['ort']}. "
          f"Telefon {FIRMA['tel']}, E-Mail {FIRMA['mail']}.", inhalt, "kontakt")


def kontoseite():
    adressfelder = """
      <div class="feld-zeile">
        <label class="feld"><span>Firma</span><input name="firma" autocomplete="organization"></label>
        <label class="feld"><span>USt-IdNr.</span><input name="ustid" placeholder="optional"></label>
      </div>
      <div class="feld-zeile">
        <label class="feld"><span>Vorname *</span><input name="vorname" autocomplete="given-name" required></label>
        <label class="feld"><span>Nachname *</span><input name="nachname" autocomplete="family-name" required></label>
      </div>
      <label class="feld"><span>Straße und Hausnummer</span><input name="strasse" autocomplete="street-address"></label>
      <div class="feld-zeile drittel">
        <label class="feld"><span>PLZ</span><input name="plz" autocomplete="postal-code"></label>
        <label class="feld"><span>Ort</span><input name="ort" autocomplete="address-level2"></label>
        <label class="feld"><span>Land</span><select name="land" autocomplete="country-name">
          """ + LAENDER_OPTIONEN + """</select></label>
      </div>
      <label class="feld"><span>Telefon</span><input name="telefon" type="tel" autocomplete="tel"></label>"""

    inhalt = f"""
{seitenkopf("Mein Konto", "Adressen einmal hinterlegen, Bestellungen im Blick behalten und schneller nachbestellen.", [("Mein Konto", "konto.html")])}
<section class="abschnitt konto" data-konto>
  <div class="wrap">
    <p class="hinweis-box" data-kein-server hidden>{icon("shield")} Vorschau-Modus: Sie können Anmelden und Registrieren hier ausprobieren, die Daten bleiben aber nur in diesem Browser. Echte Kundenkonten laufen auf dem Webserver.</p>
    <p class="formular-status gross" data-melde></p>

    <!-- ------------------------------------------------ nicht angemeldet -->
    <div class="konto-start" data-abgemeldet hidden>
      <form class="formular karte" data-form-anmelden novalidate>
        <h2 class="karte-titel">Anmelden</h2>
        <p class="klein-text">Willkommen zurück. Melden Sie sich mit Ihrer E-Mail-Adresse an.</p>
        <label class="feld"><span>E-Mail *</span><input name="email" type="email" autocomplete="email" required></label>
        <label class="feld"><span>Passwort *</span><input name="passwort" type="password" autocomplete="current-password" required></label>
        <button class="btn btn-rot breit" type="submit">Anmelden {icon("arrow")}</button>
        <p class="formular-status" data-status></p>
        <button class="textlink knopf-flach" type="button" data-vergessen>Passwort vergessen?</button>
      </form>

      <form class="formular karte" data-form-registrieren novalidate>
        <h2 class="karte-titel">Neues Kundenkonto</h2>
        <p class="klein-text">Einmal anlegen, danach mit gespeicherter Adresse bestellen. Kauf auf Rechnung wie gewohnt.</p>
        <div class="feld-zeile">
          <label class="feld"><span>E-Mail *</span><input name="email" type="email" autocomplete="email" required></label>
          <label class="feld"><span>Passwort * <small>(mind. 8 Zeichen)</small></span><input name="passwort" type="password" autocomplete="new-password" minlength="8" required></label>
        </div>
        {adressfelder}
        <label class="feld-hp" aria-hidden="true">Bitte leer lassen<input name="website" tabindex="-1" autocomplete="off"></label>
        <label class="check"><input type="checkbox" name="datenschutz" required><span>Ich habe die <a href="datenschutz.html" target="_blank">Datenschutzerklärung</a> zur Kenntnis genommen. *</span></label>
        <button class="btn btn-blau breit" type="submit">Konto anlegen {icon("arrow")}</button>
        <p class="formular-status" data-status></p>
      </form>
    </div>

    <!-- ------------------------------------------------ Passwort vergessen / neu -->
    <form class="formular karte schmal" data-form-vergessen novalidate hidden>
      <h2 class="karte-titel">Passwort zurücksetzen</h2>
      <p class="klein-text">Wir senden Ihnen einen Link, mit dem Sie ein neues Passwort vergeben können.</p>
      <label class="feld"><span>E-Mail *</span><input name="email" type="email" autocomplete="email" required></label>
      <button class="btn btn-rot breit" type="submit">Link anfordern {icon("arrow")}</button>
      <p class="formular-status" data-status></p>
      <button class="textlink knopf-flach" type="button" data-zurueck>Zurück zur Anmeldung</button>
    </form>

    <form class="formular karte schmal" data-form-neues-passwort novalidate hidden>
      <h2 class="karte-titel">Passwort festlegen</h2>
      <p class="klein-text">Ihr Kundenkonto ist eingerichtet. Nach dem Speichern sind Sie direkt angemeldet und sehen Ihre Daten, Bestellungen und Preise.</p>
      <label class="feld"><span>Neues Passwort * <small>(mind. 8 Zeichen)</small></span><input name="passwort" type="password" autocomplete="new-password" minlength="8" required></label>
      <button class="btn btn-rot breit" type="submit">Passwort speichern {icon("arrow")}</button>
      <p class="formular-status" data-status></p>
    </form>

    <!-- ------------------------------------------------ angemeldet -->
    <div data-angemeldet hidden>
      <div class="konto-kopf karte">
        <div>
          <p class="eyebrow dunkel">Angemeldet</p>
          <h2 data-name>Mein Konto</h2>
          <p class="klein-text" data-email></p>
        </div>
        <div class="konto-rabatt" data-rabatt-box hidden>{icon("prozent")}<div><strong data-rabatt></strong><small>Ihr Kundenrabatt – bereits in allen Preisen enthalten</small></div></div>
        <div class="konto-aktionen">
          <a class="btn btn-rot" href="shop.html">Zum Shop {icon("arrow")}</a>
          <button class="btn btn-linie" type="button" data-abmelden>{icon("logout")} Abmelden</button>
        </div>
      </div>
      <div class="admin-box karte" data-admin-box hidden>
        <span class="admin-marke">Admin</span>
        <div><strong>Sie sind als Administrator angemeldet.</strong>
          <small>Preise ändern Sie direkt auf jeder Produktseite (Feld „Preise bearbeiten“ unter dem Produkt) oder gesammelt in der Verwaltung.</small></div>
        <a class="btn btn-blau" href="admin/?seite=preise">Preise verwalten {icon("arrow")}</a>
        <a class="btn btn-linie" href="admin/">Bestellungen &amp; Kunden</a>
      </div>
      <p class="hinweis-box" data-unbestaetigt hidden>{icon("mail")} Bitte bestätigen Sie noch Ihre E-Mail-Adresse. Den Link haben wir Ihnen nach der Registrierung geschickt.</p>

      <div class="konto-reiter" role="tablist">
        <button class="reiter aktiv" type="button" data-reiter="bestellungen" role="tab">Meine Bestellungen</button>
        <button class="reiter" type="button" data-reiter="daten" role="tab">Meine Daten</button>
        <button class="reiter" type="button" data-reiter="passwort" role="tab">Passwort</button>
      </div>

      <div data-tafel="bestellungen">
        <div class="karte" data-bestellungen></div>
      </div>

      <div data-tafel="daten" hidden>
        <form class="formular karte" data-form-daten novalidate>
          <h2 class="karte-titel">Meine Daten</h2>
          {adressfelder}
          <label class="feld"><span>Abweichende Lieferadresse</span><textarea name="lieferadresse" rows="3" placeholder="optional"></textarea></label>
          <button class="btn btn-rot" type="submit">Speichern {icon("check")}</button>
          <p class="formular-status" data-status></p>
        </form>
      </div>

      <div data-tafel="passwort" hidden>
        <form class="formular karte schmal" data-form-passwort novalidate>
          <h2 class="karte-titel">Passwort ändern</h2>
          <label class="feld"><span>Bisheriges Passwort *</span><input name="alt" type="password" autocomplete="current-password" required></label>
          <label class="feld"><span>Neues Passwort * <small>(mind. 8 Zeichen)</small></span><input name="neu" type="password" autocomplete="new-password" minlength="8" required></label>
          <button class="btn btn-rot" type="submit">Passwort ändern {icon("check")}</button>
          <p class="formular-status" data-status></p>
        </form>
      </div>
    </div>
  </div>
</section>"""
    seite("konto.html", "Mein Konto", "Kundenkonto bei TRIPLEX-Q: Adressen speichern, Bestellungen einsehen und "
          "schneller nachbestellen. Kauf auf Rechnung.", inhalt, "konto", skripte=["katalog.js", "konto.js"])


def warenkorbseite():
    inhalt = f"""
{seitenkopf("Warenkorb &amp; Bestellung", "Prüfen Sie Ihre Auswahl und senden Sie die Bestellung ab – Zahlung bequem auf Rechnung.", [("Warenkorb", "warenkorb.html")])}
<section class="abschnitt warenkorb">
  <div class="wrap warenkorb-grid">
    <div>
      <div class="karte" data-positionen-box>
        <h2 class="karte-titel">Ihre Artikel</h2>
        <div data-positionen></div>
        <div class="leer" data-leer hidden>
          <h3>Ihr Warenkorb ist leer</h3>
          <p>Stöbern Sie im Shop und finden Sie das passende Werkzeug.</p>
          <a class="btn btn-rot" href="shop.html">Zum Shop {icon("arrow")}</a>
        </div>
      </div>
      <form class="formular karte" data-formular="bestellung" novalidate hidden>
        <h2 class="karte-titel">Rechnungs- und Lieferdaten</h2>
        <p class="konto-hinweis" data-konto-hinweis hidden>{icon("person")} Angemeldet als <strong data-konto-name></strong> – Ihre Daten sind vorausgefüllt.</p>
        <p class="klein-text" data-gast-hinweis>Sie haben ein Kundenkonto? <a href="konto.html">Jetzt anmelden</a>, dann sind Adresse und Rabatt hinterlegt. Bestellen ohne Konto ist ebenso möglich.</p>
        <div class="feld-zeile">
          <label class="feld"><span>Firma</span><input name="firma" autocomplete="organization"></label>
          <label class="feld"><span>USt-IdNr.</span><input name="ustid" placeholder="optional"></label>
        </div>
        <div class="feld-zeile">
          <label class="feld"><span>Vorname *</span><input name="vorname" autocomplete="given-name" required></label>
          <label class="feld"><span>Nachname *</span><input name="nachname" autocomplete="family-name" required></label>
        </div>
        <label class="feld"><span>Straße und Hausnummer *</span><input name="strasse" autocomplete="street-address" required></label>
        <div class="feld-zeile drittel">
          <label class="feld"><span>PLZ *</span><input name="plz" autocomplete="postal-code" required></label>
          <label class="feld"><span>Ort *</span><input name="ort" autocomplete="address-level2" required></label>
          <label class="feld"><span>Land *</span><select name="land" autocomplete="country-name" required data-land>
            {LAENDER_OPTIONEN}</select></label>
        </div>
        <div class="feld-zeile">
          <label class="feld"><span>E-Mail *</span><input name="email" type="email" autocomplete="email" required></label>
          <label class="feld"><span>Telefon</span><input name="telefon" type="tel" autocomplete="tel"></label>
        </div>
        <label class="check"><input type="checkbox" data-lieferadresse><span>Abweichende Lieferadresse</span></label>
        <label class="feld" data-lieferadresse-feld hidden><span>Lieferadresse</span><textarea name="lieferadresse" rows="3"></textarea></label>
        <label class="feld"><span>Ihre Bestellnummer / Bemerkung</span><textarea name="bemerkung" rows="3"></textarea></label>
        <fieldset class="versandwahl">
          <legend>Versand</legend>
          <label class="radio"><input type="radio" name="versand" value="dhl" checked><span><strong>Versand</strong><small>versichertes Paket, Deutschland 7,90 € – Ausland auf Anfrage</small></span></label>
          <label class="radio"><input type="radio" name="versand" value="abholung"><span><strong>Selbstabholung</strong><small>{FIRMA['strasse']}, {FIRMA['ort']} – kostenlos</small></span></label>
        </fieldset>
        <fieldset class="versandwahl">
          <legend>Zahlungsart</legend>
          <label class="radio"><input type="radio" name="zahlung" value="rechnung" checked><span><strong>Rechnung</strong><small>Zahlung nach Erhalt der Rechnung</small></span></label>
        </fieldset>
        <label class="feld-hp" aria-hidden="true">Bitte leer lassen<input name="website" tabindex="-1" autocomplete="off"></label>
        <label class="check"><input type="checkbox" name="agb" required><span>Ich akzeptiere die <a href="agb.html" target="_blank">AGB</a> und habe die <a href="widerruf.html" target="_blank">Widerrufsbelehrung</a> sowie die <a href="datenschutz.html" target="_blank">Datenschutzerklärung</a> zur Kenntnis genommen. *</span></label>
      </form>
    </div>
    <aside class="summe karte" data-summe hidden>
      <h2 class="karte-titel">Übersicht</h2>
      <dl>
        <div><dt>Zwischensumme netto</dt><dd data-zwischensumme>0,00 €</dd></div>
        <div><dt>Versand netto</dt><dd data-versandkosten>7,90 €</dd></div>
        <div><dt>zzgl. 19 % MwSt.</dt><dd data-mwst>0,00 €</dd></div>
        <div class="gesamt"><dt>Gesamtbetrag</dt><dd data-gesamt>0,00 €</dd></div>
      </dl>
      <p class="klein-text" data-ausland-hinweis hidden>Versand ins Ausland auf Anfrage – wir nennen Ihnen die Versandkosten mit der Bestellbestätigung.</p>
      <div class="preishinweis-kurz" role="note">
        {icon("warnung")}
        <p><strong>Wichtiger Hinweis zu unseren Preisen:</strong> Die angezeigten Preise sind aktuell nicht verbindlich.
        Der tatsächliche Preis kann abweichen – wir informieren Sie vor der Auftragsbestätigung über den gültigen Preis.</p>
      </div>
      <button class="btn btn-rot breit" type="button" data-bestellen>Zahlungspflichtig bestellen {icon("arrow")}</button>
      <p class="formular-status" data-status></p>
      <ul class="produkt-usps">
        <li>{icon("invoice")} Kauf auf Rechnung</li>
        <li>{icon("truck")} Versicherter Versand</li>
        <li>{icon("shield")} Ihre Daten werden verschlüsselt übertragen</li>
      </ul>
    </aside>
  </div>
</section>
<div class="dialog" data-danke hidden>
  <div class="dialog-in karte">
    <div class="danke-icon">{icon("check")}</div>
    <h2>Vielen Dank für Ihre Bestellung!</h2>
    <p data-danke-text>Wir haben Ihre Bestellung erhalten und senden Ihnen in Kürze eine Bestätigung per E-Mail.</p>
    <a class="btn btn-blau" href="index.html">Zur Startseite</a>
  </div>
</div>"""
    seite("warenkorb.html", "Warenkorb", "Ihr Warenkorb bei TRIPLEX-Q – Bestellung auf Rechnung.", inhalt, "",
          skripte=["katalog.js", "warenkorb.js"], klasse="seite-warenkorb")


def rechtsseiten():
    zuordnung = [("impressum.html", "contact", "Impressum"), ("datenschutz.html", "privacy", "Datenschutzerklärung"),
                 ("agb.html", "tac", "Allgemeine Geschäftsbedingungen"), ("widerruf.html", "withdrawal", "Widerrufsrecht"),
                 ("versand.html", "shipping", "Zahlung & Versand")]
    for datei, schluessel, titel in zuordnung:
        d = lies(f"seiten/legal_{schluessel}.json")
        teile = []
        for el in (d["legal"] or {}).get(schluessel, {}).get("content", {}).get("elements", []):
            teile.append(el.get("data", {}).get("content", ""))
        text = "\n".join(teile)
        text = re.sub(r"\s(style|class|data-[\w-]+)=\"[^\"]*\"", "", text)
        text = re.sub(r"<h1[^>]*>.*?</h1>", "", text, flags=re.S)
        text = re.sub(r"^\s*<h2>\s*" + re.escape(titel.split(" ")[0]) + r"[^<]*</h2>", "", text)
        text = text.replace("<h2>", "<h2 class=\"recht-h\">")
        if schluessel == "contact":
            # Original-Text; nur die im Original fehlenden Zeilenumbrüche in der Anschrift ergänzt
            text = text.replace("</strong>Inh. Alexandra SimonAckerstraße 12D-98544 Zella-Mehlis",
                                "</strong><br>Inh. Alexandra Simon<br>Ackerstraße 12<br>D-98544 Zella-Mehlis")
            text = re.sub(r"(<p>\s*</p>)+\s*$", "", text)
        inhalt = f"""
{seitenkopf(e(titel), "", [(titel, datei)])}
<section class="abschnitt">
  <div class="wrap recht karte">{text}</div>
</section>"""
        seite(datei, titel, f"{titel} – TRIPLEX-Q / {FIRMA['name']}", inhalt, "")


def cookieseite():
    inhalt = f"""
{seitenkopf("Cookie-Richtlinie", "", [("Cookie-Richtlinie", "cookie-richtlinie.html")])}
<section class="abschnitt">
  <div class="wrap recht karte" data-cookie>
    <h2 class="recht-h">Cookie - Richtlinie</h2>
    <h3>Wir respektieren Ihre Privatsphäre: Cookie-Einstellungen</h3>
    <p>Diese Website verwendet Cookies, um Ihnen das bestmögliche Einkaufserlebnis zu bieten. Nutzen Sie die untenstehenden
    Buttons, um zu entscheiden, ob Sie alle Cookies zur Optimierung der Website akzeptieren oder ablehnen möchten. Sie können
    Ihre Auswahl jederzeit auf der Seite „Cookie-Richtlinie“ ändern.</p>
    <div class="cookie-knoepfe">
      <button class="btn btn-linie" type="button" data-cookie-wahl="abgelehnt">Ablehnen</button>
      <button class="btn btn-blau" type="button" data-cookie-wahl="akzeptiert">Akzeptieren</button>
    </div>
    <p class="formular-status" data-cookie-status></p>
  </div>
</section>"""
    seite("cookie-richtlinie.html", "Cookie-Richtlinie", f"Cookie-Richtlinie – TRIPLEX-Q / {FIRMA['name']}", inhalt, "")


def fehlerseite():
    inhalt = f"""
<section class="fehler">
  <div class="wrap fehler-in">
    <p class="fehler-code">404</p>
    <h1>Diese Seite gibt es nicht (mehr).</h1>
    <p>Vielleicht finden Sie Ihr Werkzeug im Shop – oder Sie fragen uns direkt.</p>
    <div class="hero-cta mitte"><a class="btn btn-rot" href="/shop.html">Zum Shop {icon("arrow")}</a><a class="btn btn-glas" href="/kontakt.html">Kontakt</a></div>
  </div>
</section>"""
    seite("404.html", "Seite nicht gefunden", "Die angeforderte Seite wurde nicht gefunden.", inhalt, "", klasse="seite-404")


def katalog_js(produkte):
    liste = [{
        "id": p["id"], "name": p["name"], "kurz": p["kurz"], "linien": p["linien"], "bild": p["klein"],
        "art": p["bildart"], "preis_ab": p["preis_ab"], "preis_bis": p["preis_bis"],
        "d_min": p["d_min"], "d_max": p["d_max"], "anzahl": len(p["varianten"]),
        "suche": " ".join([p["name"], p["sku"] or "", " ".join(p["fest"].values()),
                           re.sub("<[^>]+>", " ", p["beschreibung"])[:400]]).lower(),
        "d": sorted({zahl(v["auswahl"].get("Schneidendurchmesser", "")) for v in p["varianten"]
                     if v["auswahl"].get("Schneidendurchmesser")}),
    } for p in produkte]
    preise = {v["sku"]: [v["preis"], p["id"], p["name"], " · ".join(f"{('Ø ' + w) if a == 'Schneidendurchmesser' else w}" for a, w in v["auswahl"].items()), p["klein"]]
              for p in produkte for v in p["varianten"] if v["sku"]}
    linien = [{"id": l["id"], "name": l["name"]} for l in LINIEN]
    js = ("/* automatisch erzeugt von tools/build_site.py – nicht von Hand bearbeiten */\n"
          f"window.TQ_KATALOG = {json.dumps({'linien': linien, 'produkte': liste}, ensure_ascii=False, separators=(',', ':'))};\n"
          f"window.TQ_PREISE = {json.dumps(preise, ensure_ascii=False, separators=(',', ':'))};\n"
          f"window.TQ_SHOP = {json.dumps({'versand': 7.90, 'mwst': 0.19, 'order': FIRMA['order'], 'stand': dt.date.today().isoformat()})};\n")
    (WEB / "assets" / "js" / "katalog.js").write_text(js, encoding="utf-8")
    (WEB / "api").mkdir(exist_ok=True)
    (WEB / "api" / "preise.json").write_text(
        json.dumps({s: [w[0], w[2], w[3], w[1]] for s, w in preise.items()}, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    (WEB / ".htaccess").write_text(
        "ErrorDocument 404 /404.html\nAddType image/webp .webp\nOptions -Indexes\n"
        "# immer verschlüsselt ausliefern (Kundenkonto!)\n"
        "RewriteEngine On\nRewriteCond %{HTTPS} !=on\nRewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n"
        + ("" if TEST else "# eine Adresse für Google: triplex-q.com und die Testadresse triplex-q.de -> www.triplex-q.com\n"
                           "RewriteCond %{HTTP_HOST} ^(www\\.)?triplex-q\\.de$ [NC,OR]\n"
                           "RewriteCond %{HTTP_HOST} ^triplex-q\\.com$ [NC]\n"
                           "RewriteRule ^ https://www.triplex-q.com%{REQUEST_URI} [L,R=301]\n")
        + weiterleitungen(produkte) +
        "# Datenbankschema nicht öffentlich abrufbar\n<FilesMatch \"\\.(sql|md|txt)$\">\n  Require all denied\n</FilesMatch>\n"
        "<Files \"robots.txt\">\n  Require all granted\n</Files>\n"
        "# HTML immer frisch prüfen; CSS/JS tragen eine Versionskennung (?v=...) und dürfen lange bleiben\n"
        "<IfModule mod_headers.c>\n  <FilesMatch \"\\.html$\">\n    Header set Cache-Control \"no-cache\"\n  </FilesMatch>\n</IfModule>\n"
        "<IfModule mod_expires.c>\n  ExpiresActive On\n  ExpiresByType image/webp \"access plus 1 year\"\n"
        "  ExpiresByType font/woff2 \"access plus 1 year\"\n  ExpiresByType text/css \"access plus 1 week\"\n"
        "  ExpiresByType application/javascript \"access plus 1 day\"\n</IfModule>\n", encoding="utf-8")


# Adressen des alten ePages-Shops -> neue Seiten (Google-Treffer, Lesezeichen, Links in Mails)
ALTE_SEITEN = {
    "c/triplex-q-hpc-fraeser": "shop.html#hpc",
    "c/triplex-q-tpc-fraeser": "shop.html#tpc",
    "c/triplex-q-schrupp-fraeser": "shop.html#schrupp",
    "c/triplex-q-vhm-mehrzahnfraeser-schlichtfraeser": "shop.html#mehrzahn",
    "c/triplex-q-aluminiumfraeser-hpc-tpc": "shop.html#alu",
    "c/triplex-q-bnc2-blue": "shop.html#vollradius",
    "c/triplex-q-vhm-viertelkreisfraeser": "shop.html#viertelkreis",
    "c/triplex-q-special": "shop.html",
    "c/vhm-sonderwerkzeuge": "sonderwerkzeuge.html",
    "c/triplex-q-vhm-hochleistungsbohrer": "shop.html#bohrer",
    "i/referenzkunden": "referenzen.html",
    "i/kontakt": "kontakt.html",
    "i/anwendungsbeispiele-und-kundenvideos": "anwendungen.html",
    "l/privacy": "datenschutz.html",
    "l/contact": "impressum.html",
    "l/cookiepolicy": "cookie-richtlinie.html",
    "l/shipping": "versand.html",
    "l/tac": "agb.html",
    "l/withdrawal": "widerruf.html",
    "cart": "warenkorb.html",
    "checkout": "warenkorb.html",
    "account": "konto.html",
    "login": "konto.html",
    "register": "konto.html",
    "search": "shop.html",
}


def weiterleitungen(produkte):
    """RewriteRules: alte Shop-Adressen dauerhaft (301) auf die neuen Seiten."""
    ziele = dict(ALTE_SEITEN)
    for p in produkte:
        for href in p.get("alte_urls", []):
            ziele[href.strip("/")] = f"produkte/{p['id']}.html"
    zeilen = ["# Alte Adressen des bisherigen Shops (ePages) auf die neuen Seiten umleiten"]
    zeilen += [f"RewriteRule ^{re.escape(alt)}/?$ /{neu} [R=301,L,NE]" for alt, neu in ziele.items()]
    zeilen += [
        "# alles Übrige aus dem alten Shop: Produkte und Kategorien -> Shop, Infoseiten -> Startseite",
        "RewriteRule ^(p|c)/ /shop.html [R=301,L]",
        "RewriteRule ^(i|l)/ / [R=301,L]",
    ]
    return "\n".join(zeilen) + "\n"


def sitemap():
    heute = dt.date.today().isoformat()
    urls = "".join(f"<url><loc>{DOMAIN}/{'' if s == 'index.html' else s}</loc><lastmod>{heute}</lastmod></url>"
                   for s in SITEMAP if s != "404.html")
    (WEB / "sitemap.xml").write_text(f'<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">{urls}</urlset>\n', encoding="utf-8")
    if TEST:
        (WEB / "robots.txt").write_text("User-agent: *\nDisallow: /\n", encoding="utf-8")
    else:
        (WEB / "robots.txt").write_text(f"User-agent: *\nAllow: /\nDisallow: /warenkorb.html\nSitemap: {DOMAIN}/sitemap.xml\n", encoding="utf-8")


def main():
    bilder = json.loads((WURZEL / "data" / "bilder_index.json").read_text(encoding="utf-8"))
    produkte = katalog_bauen(bilder)
    (WURZEL / "data" / "katalog.json").write_text(json.dumps(produkte, ensure_ascii=False, indent=1), encoding="utf-8")
    LINIEN_ZAHLEN.extend((l, sum(1 for p in produkte if l["id"] in p["linien"])) for l in LINIEN)
    for alt in (WEB / "produkte").glob("*.html"):
        alt.unlink()
    katalog_js(produkte)
    startseite(produkte)
    shopseite(produkte)
    for p in produkte:
        produktseite(p, produkte)
    sonderseite()
    anwendungsseite()
    referenzseite()
    kontaktseite()
    kontoseite()
    warenkorbseite()
    rechtsseiten()
    cookieseite()
    fehlerseite()
    sitemap()
    print(f"fertig: {len(produkte)} Produkte, {sum(len(p['varianten']) for p in produkte)} Varianten, {len(SITEMAP)} Seiten")


if __name__ == "__main__":
    main()
