"""Preisabgleich: alter Shop (live, www.triplex-q.com) gegen die neue Seite (website/api/preise.json).

Liest jeden Artikel und jede Abmessung frisch über die öffentliche ePages-Schnittstelle des alten Shops
und vergleicht den Nettopreis mit dem Preis, den die neue Seite anzeigt. Schreibt einen Bericht nach
data/preisabgleich.csv und gibt eine Zusammenfassung aus.

Aufruf:  python tools/preise_pruefen.py                 nur prüfen
         python tools/preise_pruefen.py --uebernehmen   aktuelle Preise des alten Shops in
                                                         data/quelle/produkte.json übernehmen, danach
                                                         tools/build_site.py ausführen und erneut prüfen.
         Abmessungen, die im alten Shop ausgeblendet sind, werden dabei als "ausgeblendet" markiert
         und erscheinen nicht mehr im neuen Shop.
"""
import csv
import json
import sys
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import requests

API = "https://www.triplex-q.com/rs/shops/87065853"
WURZEL = Path(__file__).resolve().parent.parent
NEU = WURZEL / "website" / "api" / "preise.json"
QUELLE = WURZEL / "data" / "quelle" / "produkte.json"
BERICHT = WURZEL / "data" / "preisabgleich.csv"

S = requests.Session()
S.headers.update({"Accept": "application/vnd.epages.v1+json",
                  "User-Agent": "Mozilla/5.0 (TRIPLEX-Q Preisabgleich)"})


def api(url):
    for _ in range(4):
        try:
            r = S.get(url if url.startswith("http") else API + url, timeout=40)
            if r.status_code == 200:
                return r.json()
        except requests.RequestException:
            pass
    raise RuntimeError("nicht erreichbar: " + url)


def seiten(pfad):
    """Alle Einträge einer Liste, auch wenn sie über mehrere Seiten geht."""
    seite, alle = 1, []
    while True:
        d = api(f"{pfad}{'&' if '?' in pfad else '?'}resultsPerPage=100&page={seite}")
        alle += d.get("items", [])
        if len(alle) >= d.get("results", 0) or not d.get("items"):
            return alle
        seite += 1


def preis(d):
    p = (d.get("priceInfo") or {}).get("price") or {}
    return p.get("amount"), p.get("taxType")


def artikel_des_produkts(p):
    """[(sku, preis, steuerart, produktname, auswahl, sichtbar)] für ein Produkt bzw. alle Varianten."""
    name = " ".join(p["name"].split())
    sichtbar = p.get("visible", True)
    if p.get("productVariationType") != "master":
        b, t = preis(p)
        return [(p.get("productNumber"), b, t, name, "", sichtbar)]
    zeilen = []
    items = seiten(f"/products/{p['productId']}/variations")
    with ThreadPoolExecutor(6) as ex:
        details = list(ex.map(lambda it: api(it["link"]["href"]), items))
    for it, d in zip(items, details):
        b, t = preis(d)
        auswahl = ", ".join(a["displayValue"] for a in it["attributeSelection"])
        zeilen.append((d.get("productNumber"), b, t, name, auswahl, d.get("visible", True)))
    return zeilen


def uebernehmen(alt):
    """Preise aus dem alten Shop in die Quelldaten des Seitenbaus schreiben."""
    live = {str(sku): b for sku, b, _, _, _, sichtbar in alt if sichtbar and b is not None}
    quelle = json.loads(QUELLE.read_text(encoding="utf-8"))
    geaendert, ausgeblendet, fehlt = 0, [], []
    for p in quelle:
        p["staffelpreise"] = None  # der alte Shop hat keine Staffelpreise mehr
        teile = p.get("varianten") or [p]
        for v in teile:
            sku = str(v.get("sku"))
            if sku in live:
                if v.get("preis_netto") != live[sku]:
                    geaendert += 1
                v["preis_netto"] = live[sku]
                v.pop("ausgeblendet", None)
                v["staffelpreise"] = None
            elif v is not p:
                v["ausgeblendet"] = True
                ausgeblendet.append(sku)
            else:
                fehlt.append(sku)
        if p.get("varianten"):
            preise = [v["preis_netto"] for v in p["varianten"] if not v.get("ausgeblendet") and v["preis_netto"] is not None]
            p["preis_ab"], p["preis_bis"] = (min(preise), max(preise)) if preise else (None, None)
    QUELLE.write_text(json.dumps(quelle, ensure_ascii=False, indent=1), encoding="utf-8")
    print(f"übernommen: {geaendert} Preise geändert, ausgeblendet: {', '.join(ausgeblendet) or '–'}")
    if fehlt:
        print("  ! Einzelartikel nicht mehr im alten Shop:", ", ".join(fehlt))
    print("Jetzt: python tools/build_site.py --test  und danach erneut  python tools/preise_pruefen.py")


def main():
    neu = json.loads(NEU.read_text(encoding="utf-8"))
    produkte = seiten("/products")
    alt = []
    for p in produkte:
        if p.get("productVariationType") == "variation":
            continue  # Varianten kommen über ihr Masterprodukt
        alt += artikel_des_produkts(p)
    if "--uebernehmen" in sys.argv:
        uebernehmen(alt)
        return

    gleich, abweichend, nur_alt, steuer = [], [], [], set()
    gesehen = set()
    for sku, b, t, name, auswahl, sichtbar in alt:
        sku = str(sku)
        steuer.add(t)
        if sku in gesehen:
            continue
        gesehen.add(sku)
        if sku not in neu:
            nur_alt.append((sku, b, name, auswahl, sichtbar))
        elif b is None or neu[sku][0] is None or round(float(neu[sku][0]), 2) != round(float(b), 2):
            abweichend.append((sku, b, neu[sku][0], name, auswahl))
        else:
            gleich.append(sku)
    nur_neu = [s for s in neu if s not in gesehen]

    BERICHT.parent.mkdir(exist_ok=True)
    with BERICHT.open("w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f, delimiter=";")
        w.writerow(["Ergebnis", "Art.-Nr.", "Preis alter Shop", "Preis neue Seite", "Produkt", "Abmessung"])
        for s, b, n, name, a in abweichend:
            w.writerow(["ABWEICHUNG", s, b, n, name, a])
        for s, b, name, a, sichtbar in nur_alt:
            w.writerow(["nur alter Shop" + ("" if sichtbar else " (ausgeblendet)"), s, b, "", name, a])
        for s in nur_neu:
            w.writerow(["nur neue Seite", s, "", neu[s][0], neu[s][1], neu[s][2]])
        for s in gleich:
            w.writerow(["gleich", s, neu[s][0], neu[s][0], neu[s][1], neu[s][2]])

    print(f"Produkte im alten Shop: {len(produkte)} Einträge, {len(gesehen)} Artikelnummern mit Preis")
    print(f"Steuerart der Preise im alten Shop: {', '.join(sorted(map(str, steuer)))}")
    print(f"identisch:            {len(gleich)} von {len(neu)} Artikeln der neuen Seite")
    print(f"abweichend:           {len(abweichend)}")
    print(f"nur im alten Shop:    {len(nur_alt)}")
    print(f"nur auf neuer Seite:  {len(nur_neu)}")
    for s, b, n, name, a in abweichend[:40]:
        print(f"  ABWEICHUNG {s}: alt {b} / neu {n}  {name} {a}")
    for s, b, name, a, sichtbar in nur_alt[:40]:
        print(f"  nur alt {s}: {b}  {name} {a}{'' if sichtbar else '  (ausgeblendet)'}")
    for s in nur_neu[:40]:
        print(f"  nur neu {s}: {neu[s][0]}  {neu[s][1]} {neu[s][2]}")
    print("Bericht:", BERICHT)


if __name__ == "__main__":
    main()
