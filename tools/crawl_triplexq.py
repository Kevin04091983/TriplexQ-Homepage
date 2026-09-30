"""Liest den alten TRIPLEX-Q-Shop (Strato/ePages Now) komplett aus.

Ergebnis:
  data/quelle/produkte.json    alle Artikel mit Varianten, Preisen, Beschreibungen
  data/quelle/kategorien.json  Kategoriebaum mit Produktzuordnung
  data/quelle/seiten/*.json    Inhalts- und Rechtsseiten (Text, Bilder, Videos)
  data/quelle/bilder/...       Originalbilder in der größten verfügbaren Auflösung
  data/quelle/datenblaetter/   Produktdatenblätter (PDF)

Aufruf:  python tools/crawl_triplexq.py
"""
import json
import re
import time
import urllib.parse
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import requests

BASIS = "https://www.triplex-q.com"
SHOP = "87065853"
API = f"{BASIS}/rs/shops/{SHOP}"
ZIEL = Path(__file__).resolve().parent.parent / "data" / "quelle"

S = requests.Session()
S.headers["User-Agent"] = "Mozilla/5.0 (TRIPLEX-Q Relaunch-Import)"
S.headers["Accept-Language"] = "de-DE,de;q=0.9"


def hole(url, **kw):
    for versuch in range(4):
        try:
            r = S.get(url, timeout=40, **kw)
            if r.status_code == 200:
                return r
            if r.status_code == 404:
                return None
        except requests.RequestException:
            pass
        time.sleep(1.5 * (versuch + 1))
    print("  !! nicht erreichbar:", url)
    return None


def api(pfad):
    r = hole(pfad if pfad.startswith("http") else API + pfad,
             headers={"Accept": "application/vnd.epages.v1+json"})
    return r.json() if r else None


def zustand(html):
    """window.__EP.storeInitialState aus einer Storefront-Seite."""
    m = re.search(r'storeInitialState:\s*"((?:[^"\\]|\\.)*)"', html)
    if not m:
        return {}
    return json.loads(json.loads('"' + m.group(1) + '"'))


def speichere(pfad, obj):
    pfad.parent.mkdir(parents=True, exist_ok=True)
    pfad.write_text(json.dumps(obj, ensure_ascii=False, indent=1), encoding="utf-8")


def dateiname(url):
    teil = urllib.parse.urlparse(url)
    if "remote=" in teil.query:
        url = urllib.parse.parse_qs(teil.query)["remote"][0]
        teil = urllib.parse.urlparse(url)
    return urllib.parse.unquote(teil.path.rsplit("/", 1)[-1])


def bilder_aus_html(html):
    """Alle Shop-Bilder (storage/images) und externe Bilder aus einer Seite."""
    html = html.replace("\\u002F", "/").replace("&amp;", "&")
    funde = set()
    for m in re.finditer(r'/storage/images/([^"\'?\s\\]+)\?hash=([0-9a-f]+)', html):
        funde.add(f"{BASIS}/storage/images/{m.group(1)}?hash={m.group(2)}&shop={SHOP}")
    for m in re.finditer(r'https?://triplex-schmidt\.de/wp-content/uploads/[^"\'\s\\)]+\.(?:png|jpe?g|webp)', html):
        funde.add(m.group(0))
    return sorted(funde)


def videos_aus_html(html):
    html = html.replace("\\u002F", "/")
    return sorted(set(re.findall(r'https?://(?:www\.)?(?:youtube(?:-nocookie)?\.com/(?:embed/|watch\?v=)|youtu\.be/|player\.vimeo\.com/video/)[\w\-]+', html)))


def main_text(html):
    m = re.search(r"<main[^>]*>(.*?)</main>", html, re.S)
    t = m.group(1) if m else html
    t = re.sub(r"<(script|style)[^>]*>.*?</\1>", " ", t, flags=re.S)
    t = re.sub(r"<br\s*/?>|</p>|</h\d>|</li>|</div>", "\n", t)
    t = re.sub(r"<[^>]+>", " ", t)
    import html as h
    t = h.unescape(t)
    t = re.sub(r"[ \t\xa0]+", " ", t)
    return re.sub(r"\n\s*\n+", "\n", t).strip()


# ---------------------------------------------------------------- Seiten
def seite(pfad, name):
    r = hole(BASIS + pfad)
    if not r:
        return None
    html = r.text
    st = zustand(html)
    daten = {
        "pfad": pfad,
        "titel": re.search(r"<title>(.*?)</title>", html, re.S).group(1).strip(),
        "text": main_text(html),
        "bilder": bilder_aus_html(html),
        "videos": videos_aus_html(html),
        "pages": st.get("pages"),
        "legal": st.get("legalPagesContents"),
    }
    speichere(ZIEL / "seiten" / f"{name}.json", daten)
    (ZIEL / "seiten" / f"{name}.html").write_text(html, encoding="utf-8")
    return daten, st


# ---------------------------------------------------------------- Produkte
def variante(link):
    d = api(link)
    if not d:
        return None
    pi = d.get("priceInfo") or {}
    preis = (pi.get("price") or {}).get("amount")
    return {
        "productId": d["productId"],
        "sku": d.get("productNumber"),
        "name": d.get("name"),
        "preis_netto": preis,
        "lagerbestand": d.get("stocklevel"),
        "verfuegbar": d.get("availabilityText"),
        "lieferzeit": d.get("deliveryPeriod"),
        "staffelpreise": d.get("bulkPriceInfo"),
        "bilder": [b["url"] for b in d.get("images", []) if b.get("classifier") == "Large"],
    }


def produkt(p):
    pid = p["productId"]
    print("  Produkt:", p["name"])
    detail = {
        "productId": pid,
        "name": re.sub(r"\s+", " ", p["name"]).strip(),
        "sku": p.get("productNumber"),
        "typ": p.get("productVariationType"),
        "lieferzeit": p.get("deliveryPeriod"),
        "verfuegbar": p.get("availabilityText"),
        "lagerbestand": p.get("stocklevel"),
        "preis_netto": ((p.get("priceInfo") or {}).get("price") or {}).get("amount"),
        "preis_ab": ((p.get("priceInfo") or {}).get("lowestPrice") or {}).get("amount"),
        "preis_bis": ((p.get("priceInfo") or {}).get("highestPrice") or {}).get("amount"),
        "staffelpreise": p.get("bulkPriceInfo"),
        "bilder_api": [b["url"] for b in p.get("images", []) if b.get("classifier") == "Large"],
    }
    # Storefront-Seite: Beschreibung, Datenblatt, Slug, Bildergalerie
    r = hole(f"{BASIS}/p/{pid}")
    if r:
        st = zustand(r.text)
        sp = next(iter((st.get("products") or {}).values()), {})
        detail.update({
            "slug": sp.get("slug"),
            "href": sp.get("href"),
            "beschreibung_html": sp.get("description"),
            "zusatz_beschreibungen": sp.get("additionalDescriptions"),
            "meta_beschreibung": sp.get("metaDescription"),
            "datenblatt": (sp.get("productDataSheet") or {}).get("dataUri"),
            "gewicht": sp.get("weight"),
            "hauptkategorie": sp.get("mainCategoryId"),
            "bild_storefront": (sp.get("image") or {}).get("url"),
            "slideshow": sp.get("slideshow"),
            "labels": sp.get("labels"),
            "custom_attributes": sp.get("customAttributes"),
            "bilder_html": bilder_aus_html(r.text),
            "videos": videos_aus_html(r.text),
        })
    # Varianten
    if p.get("productVariationType") == "master":
        v = api(f"/products/{pid}/variations?resultsPerPage=100")
        if v:
            detail["variantenattribute"] = v.get("variationAttributes")
            liste = []
            with ThreadPoolExecutor(4) as ex:
                ergebnisse = list(ex.map(lambda it: variante(it["link"]["href"]), v.get("items", [])))
            for it, erg in zip(v.get("items", []), ergebnisse):
                if erg:
                    erg["auswahl"] = {a["displayName"]: a["displayValue"] for a in it["attributeSelection"]}
                    liste.append(erg)
            detail["varianten"] = liste
    # Querverkauf
    xs = api(f"/products/{pid}/cross-selling")
    if xs and isinstance(xs, dict):
        detail["zubehoer"] = [i.get("productId") for i in xs.get("items", [])]
    return detail


def kategorien():
    wurzel = api("/categories")[0]
    alle = []

    def ab(kat_href, eltern=None):
        k = api(kat_href)
        if not k:
            return
        prods = api(f"/products?categoryId={k['categoryId']}&resultsPerPage=100") or {}
        eintrag = {
            "id": k["categoryId"],
            "alias": k.get("alias"),
            "name": k.get("name"),
            "sichtbar": k.get("visible"),
            "in_navigation": k.get("visibleInNavigation"),
            "eltern": eltern,
            "produkte": [p["productId"] for p in prods.get("items", [])],
            "unterkategorien": [s["href"].rsplit("/", 1)[-1] for s in k.get("subCategories", [])],
        }
        alle.append(eintrag)
        for s in k.get("subCategories", []):
            ab(s["href"], k["categoryId"])

    for s in wurzel.get("subCategories", []):
        ab(s["href"], wurzel["categoryId"])
    return wurzel, alle


def lade_bild(url, ordner):
    """Bild in größter Auflösung laden (storage/images mit width=2560)."""
    ordner.mkdir(parents=True, exist_ok=True)
    ziel = ordner / dateiname(url)
    if ziel.exists() and ziel.stat().st_size > 0:
        return ziel.name
    gross = url
    if "/storage/images/" in url and "width=" not in url:
        gross = url + "&width=2560&height=2560"
    r = hole(gross)
    if not r:
        return None
    ziel.write_bytes(r.content)
    return ziel.name


def main():
    ZIEL.mkdir(parents=True, exist_ok=True)

    print("Seiten …")
    home, st = seite("/", "home")
    nav = (st.get("navigation") or {}).get("storefront") or []
    speichere(ZIEL / "navigation.json", nav)
    seitenpfade = {"home": "/"}

    def sammle(knoten):
        for n in knoten:
            if n.get("href") and n["href"] != "/":
                seitenpfade[n["slug"].replace("/", "__")] = n["href"]
            sammle(n.get("children") or n.get("_children") or [])
    sammle(nav)
    for rechts in ["privacy", "contact", "cookiepolicy", "shipping", "tac", "withdrawal"]:
        seitenpfade["legal_" + rechts] = f"/l/{rechts}"
    for name, pfad in seitenpfade.items():
        if name != "home":
            print("  Seite:", pfad)
            seite(pfad, name)

    print("Kategorien …")
    wurzel, kats = kategorien()
    speichere(ZIEL / "kategorien.json", {"wurzel": wurzel["categoryId"], "kategorien": kats})

    print("Produkte …")
    liste = api("/products?resultsPerPage=100")["items"]
    produkte = [produkt(p) for p in liste]
    speichere(ZIEL / "produkte.json", produkte)

    print("Bilder und Datenblätter …")
    aufgaben = []
    for p in produkte:
        urls = list(p.get("bilder_api", []))
        for v in p.get("varianten", []):
            urls += v.get("bilder", [])
        for u in dict.fromkeys(urls):
            aufgaben.append((u, ZIEL / "bilder" / "produkte" / p["productId"]))
        if p.get("datenblatt"):
            aufgaben.append((p["datenblatt"], ZIEL / "datenblaetter" / p["productId"]))
    for js in (ZIEL / "seiten").glob("*.json"):
        d = json.loads(js.read_text(encoding="utf-8"))
        for u in d["bilder"]:
            aufgaben.append((u, ZIEL / "bilder" / "seiten"))
    with ThreadPoolExecutor(6) as ex:
        list(ex.map(lambda a: lade_bild(*a), aufgaben))
    print("fertig:", len(produkte), "Produkte,", len(kats), "Kategorien,", len(aufgaben), "Dateien")


if __name__ == "__main__":
    main()
