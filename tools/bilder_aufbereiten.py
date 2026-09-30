"""Bereitet die Originalbilder aus data/quelle/bilder für die neue Website auf.

- Logo: zugeschnitten, farbig und weiß (für dunkle Flächen), Q-Zeichen als Favicon
- Produktbilder: das Werkzeug wird aus dem Shopbild (Logo + Schrift + Werkzeug)
  herausgeschnitten, vom weißen Hintergrund freigestellt, 2x hochgerechnet und geschärft
- Fotos, Galerie und Kundenlogos: zugeschnitten, geschärft, als WebP

Aufruf:  python tools/bilder_aufbereiten.py
"""
import json
import re
import unicodedata
from pathlib import Path

import numpy as np
import requests
from PIL import Image, ImageDraw, ImageEnhance, ImageFilter

import freisteller

WURZEL = Path(__file__).resolve().parent.parent
QUELLE = WURZEL / "data" / "quelle"
ZIEL = WURZEL / "website" / "assets" / "img"


def slug(text):
    text = unicodedata.normalize("NFKD", text.replace("ß", "ss")).encode("ascii", "ignore").decode()
    return re.sub(r"[^a-z0-9]+", "-", text.lower()).strip("-")


def veredeln(img, faktor=2.0, schaerfe=70):
    """Hochrechnen (Lanczos) + Unschärfemaske + leicht mehr Brillanz."""
    rgba = img.mode == "RGBA"
    alpha = img.getchannel("A") if rgba else None
    rgb = img.convert("RGB")
    if faktor != 1:
        groesse = (round(rgb.width * faktor), round(rgb.height * faktor))
        rgb = rgb.resize(groesse, Image.LANCZOS)
        if rgba:
            alpha = alpha.resize(groesse, Image.LANCZOS)
    rgb = rgb.filter(ImageFilter.UnsharpMask(radius=1.4 * max(faktor, 1), percent=schaerfe, threshold=2))
    rgb = ImageEnhance.Contrast(rgb).enhance(1.04)
    rgb = ImageEnhance.Color(rgb).enhance(1.08)
    if rgba:
        rgb.putalpha(alpha)
    return rgb


def speichere(img, pfad, qualitaet=88, breite=None):
    pfad.parent.mkdir(parents=True, exist_ok=True)
    if breite and img.width > breite:
        img = img.resize((breite, round(img.height * breite / img.width)), Image.LANCZOS)
    img.save(pfad, "WEBP", quality=qualitaet, method=6)
    return img.size


# ------------------------------------------------------------------ Freistellen
def freistellen(img, schwelle=236):
    """Weißen, vom Rand her zusammenhängenden Hintergrund transparent machen."""
    rgb = img.convert("RGB")
    a = np.asarray(rgb).astype(np.int16)
    hell = a.min(axis=2) >= schwelle
    maske = Image.fromarray(np.where(hell, 255, 0).astype(np.uint8), "L")
    maske = maske.crop((-2, -2, maske.width + 2, maske.height + 2))  # Rand = schwarz
    rand = Image.new("L", maske.size, 255)
    rand.paste(maske.crop((2, 2, maske.width - 2, maske.height - 2)), (2, 2))
    ImageDraw.floodfill(rand, (0, 0), 128, thresh=0)
    hg = np.asarray(rand)[2:-2, 2:-2] == 128
    # weiche Kante: helle Randpixel des Werkzeugs teiltransparent
    minwert = a.min(axis=2)
    alpha = np.where(hg, 0, 255).astype(np.float32)
    kante = Image.fromarray(alpha.astype(np.uint8), "L").filter(ImageFilter.MaxFilter(3))
    kante = (np.asarray(kante) > 0) & (alpha == 0)
    alpha[kante] = np.clip((255 - minwert[kante]) * 9, 0, 255)
    alpha_img = Image.fromarray(alpha.astype(np.uint8), "L").filter(ImageFilter.GaussianBlur(0.6))
    out = rgb.copy()
    out.putalpha(alpha_img)
    return out


def zuschneiden_alpha(img, rand=6):
    box = img.getchannel("A").point(lambda v: 255 if v > 12 else 0).getbbox()
    if not box:
        return img
    l, o, r, u = box
    return img.crop((max(l - rand, 0), max(o - rand, 0), min(r + rand, img.width), min(u + rand, img.height)))


def werkzeugband(img):
    """Im Shopbild liegt oben Logo+Schrift, unten das Werkzeug: unterstes Band finden."""
    rahmen = 8  # die Shopbilder haben einen feinen Rahmen
    img = img.crop((rahmen, rahmen, img.width - rahmen, img.height - rahmen))
    a = np.asarray(img.convert("RGB")).astype(np.int16)
    dunkel = a.min(axis=2) < 226
    zeilen = dunkel.sum(axis=1) > 4
    baender, start, luecke = [], None, 0
    for y, z in enumerate(zeilen):
        if z:
            if start is None:
                start = y
            luecke = 0
            ende = y
        elif start is not None:
            luecke += 1
            if luecke > 5:
                baender.append((start, ende))
                start, luecke = None, 0
    if start is not None:
        baender.append((start, ende))
    baender = [b for b in baender if b[1] - b[0] >= 6]
    if not baender:
        return img
    o, u = baender[-1]
    spalten = np.where(dunkel[o:u + 1].sum(axis=0) > 0)[0]
    l, r = spalten.min(), spalten.max()
    rand = 14
    return img.crop((max(l - rand, 0), max(o - rand, 0), min(r + rand, img.width), min(u + rand, img.height)))


def hat_logo_kopf(img):
    """Shopbilder mit eingebranntem Logo haben oben links viel Blau/Rot."""
    a = np.asarray(img.convert("RGB")).astype(np.int16)
    kopf = a[: a.shape[0] // 2, : int(a.shape[1] * 0.45)]
    r, g, b = kopf[..., 0], kopf[..., 1], kopf[..., 2]
    blau = ((b > r + 60) & (b > g + 40)).mean()
    rot = ((r > g + 100) & (r > b + 100)).mean()
    return blau > 0.02 and rot > 0.005


# ------------------------------------------------------------------ Logo
def logo():
    src = Image.open(QUELLE / "bilder" / "seiten" / "1 (2).png").convert("RGBA")
    src = src.crop(src.getchannel("A").getbbox())
    d = ZIEL / "logo"
    d.mkdir(parents=True, exist_ok=True)
    src.resize((900, round(src.height * 900 / src.width)), Image.LANCZOS).save(d / "triplex-q-logo.png", optimize=True)
    speichere(src, d / "triplex-q-logo.webp", 92, 900)

    a = np.asarray(src).astype(np.int16).copy()
    blau = (a[..., 2] > a[..., 0] + 30) & (a[..., 3] > 0)
    weiss = a.copy()
    weiss[blau, 0:3] = 255
    w = Image.fromarray(weiss.astype(np.uint8), "RGBA")
    w.resize((900, round(w.height * 900 / w.width)), Image.LANCZOS).save(d / "triplex-q-logo-weiss.png", optimize=True)
    speichere(w, d / "triplex-q-logo-weiss.webp", 92, 900)

    # Q-Zeichen und Schriftzug trennen: Schnitt an der Oberkante des "T" von TRIPLEX
    a = np.asarray(src).astype(np.int16)
    opak = a[..., 3] > 40
    links = opak[:, : int(src.width * .08)].any(axis=1)
    trenn = next(y for y in range(int(src.height * .3), src.height) if links[y])
    # Schriftzug: Teile des Q-Schweifs, die über die Schnittkante ragen, entfernen
    schrift = src.crop((0, trenn, src.width, src.height)).copy()
    maske = Image.fromarray(np.where(np.asarray(schrift.getchannel("A")) > 40, 255, 0).astype(np.uint8))
    for x in np.where(opak[trenn - 1] & opak[trenn])[0]:
        if maske.getpixel((int(x), 0)) == 255:
            ImageDraw.floodfill(maske, (int(x), 0), 100, thresh=0)
    sa = np.asarray(schrift).copy()
    sa[np.asarray(maske) == 100, 3] = 0
    schrift = Image.fromarray(sa.astype(np.uint8), "RGBA")
    schrift = schrift.crop(schrift.getchannel("A").getbbox())
    # Q-Zeichen: Ring bleibt vollständig, nur das Schweif-Ende rechts unten läuft weich aus
    q = src.crop((0, 0, src.width, trenn)).copy()
    q = q.crop(q.getchannel("A").getbbox())
    qa = np.asarray(q).astype(np.float32).copy()
    hoehe, breite = qa.shape[:2]
    ring_unten = next(y for y in range(hoehe - 1, 0, -1) if (qa[y, : int(breite * .6), 3] > 40).any())
    if ring_unten < hoehe - 2:
        spanne = hoehe - ring_unten
        for y in range(ring_unten, hoehe):
            qa[y, int(breite * .6):, 3] *= max(0.0, 1 - (y - ring_unten) / spanne) ** 1.5
    q = Image.fromarray(qa.astype(np.uint8), "RGBA")
    q = q.crop(q.getchannel("A").point(lambda v: 255 if v > 10 else 0).getbbox())
    for name, bild in (("q", q), ("schrift", schrift)):
        speichere(bild, d / f"triplex-q-{name}.webp", 92, 700)
        bw = np.asarray(bild).astype(np.int16).copy()
        m = (bw[..., 2] > bw[..., 0] + 30) & (bw[..., 3] > 0)
        bw[m, 0:3] = 255
        speichere(Image.fromarray(bw.astype(np.uint8), "RGBA"), d / f"triplex-q-{name}-weiss.webp", 92, 700)

    # Favicons: Q auf weißem, abgerundetem Quadrat
    for groesse, name in ((32, "favicon-32.png"), (180, "apple-touch-icon.png"), (512, "icon-512.png")):
        kachel = Image.new("RGBA", (groesse, groesse), (0, 0, 0, 0))
        ImageDraw.Draw(kachel).rounded_rectangle((0, 0, groesse - 1, groesse - 1), radius=groesse // 5, fill=(255, 255, 255, 255))
        innen = int(groesse * 0.82)
        qq = q.copy()
        qq.thumbnail((innen, innen), Image.LANCZOS)
        kachel.alpha_composite(qq, ((groesse - qq.width) // 2, (groesse - qq.height) // 2))
        kachel.save(d / name)
    print("Logo fertig")


# ------------------------------------------------------------------ Produkte
def produkte():
    produkte = json.loads((QUELLE / "produkte.json").read_text(encoding="utf-8"))
    ergebnis = {}
    for p in produkte:
        ordner = QUELLE / "bilder" / "produkte" / p["productId"]
        dateien = sorted(ordner.glob("*")) if ordner.exists() else []
        if not dateien:
            continue
        # Hauptbild = erstes Bild des Artikels (Reihenfolge der API)
        haupt = p["bilder_api"][0].rsplit("/", 1)[-1] if p.get("bilder_api") else dateien[0].name
        quelle = ordner / haupt if (ordner / haupt).exists() else dateien[0]
        schluessel = f"{p['productId']}/{quelle.name}"
        img = Image.open(quelle).convert("RGB")
        name = slug(p["name"])
        ziel = ZIEL / "produkte"
        if hat_logo_kopf(img):
            band = werkzeugband(img)
            frei = zuschneiden_alpha(freisteller.freistellen(band, saum=1, angeschnitten=False))
            gross = veredeln(frei, 2.0)
            speichere(gross, ziel / f"{name}.webp", 90, 1800)
            speichere(gross, ziel / f"{name}-klein.webp", 86, 820)
            art = "freigestellt"
        else:
            foto = veredeln(img, 1.6 if img.width < 1000 else 1.0, 60)
            speichere(foto, ziel / f"{name}.webp", 88, 1400)
            speichere(foto, ziel / f"{name}-klein.webp", 84, 700)
            art = "foto"
        # Original-Shopbild (mit Beschriftung) veredelt als zweite Ansicht
        speichere(veredeln(img, 1.6, 55), ziel / f"{name}-original.webp", 86, 1500)
        ergebnis[p["productId"]] = {"bild": f"{name}.webp", "klein": f"{name}-klein.webp",
                                    "original": f"{name}-original.webp", "art": art}
        print("  Produktbild:", name, art)
    (WURZEL / "data" / "bilder_index.json").write_text(json.dumps(ergebnis, indent=1), encoding="utf-8")


# ------------------------------------------------------------------ Fotos / Galerie / Logos
SEITEN = QUELLE / "bilder" / "seiten"

KUNDENLOGOS = {
    "tobi.jpg": "zimmer", "customcolor_logo_customcolor_background.png": "fritzler",
    "Zanutta logo klein.jpg": "zanutta", "saw-logo-dunkel.png": "saw-technologie",
    "Bild2.jpg": "e-mill", "LOGO MOTION NEU.png": "mhs-motion", "Schild_Halle_2000x800mm_6mmBesch.jpg": "rotatec",
    "Bild4.png": "limmer", "Bonsack.png": "bonsack", "Nötzold.jpg": "noetzold",
    "CM-Slogan-Black.png": "cutmetall", "Bild6.png": "windelband", "NC-tronic_Logo.png": "nc-tronic",
    "SPG+LOGO+FREI.png": "spg", "wild.jpg": "wild",
}


def fotos():
    z = ZIEL / "fotos"
    for f in ["IMG_4710.jpg", "IMG_4738.jpg", "IMG_4762.jpg", "IMG_4784.jpg", "IMG_4936.jpg", "IMG_4947.jpg"]:
        img = veredeln(Image.open(SEITEN / f), 1.0, 45)
        n = slug(Path(f).stem)
        speichere(img, z / f"{n}.webp", 86, 2000)
        speichere(img, z / f"{n}-klein.webp", 82, 900)
    for f, n in [("Link.jpg", "sortiment-gruppe"), ("Hintergrund.jpg", "sonderwerkzeug-zeichnung"),
                 ("20230823_105238_0000.png", "nc-entgrater")]:
        img = Image.open(SEITEN / f).convert("RGB")
        speichere(veredeln(img, 2.0 if img.width < 1000 else 1.0, 60), z / f"{n}.webp", 88, 1600)

    # Freisteller für Startseite und Kategorien
    frei = {
        "Design ohne Titel-8.png": "hero-fraeser-farben", "IMG_4936.jpg": "hero-fraeser-gold",
        "Design ohne Titel-2.jpg": "kat-bohrer", "Design ohne Titel-3.jpg": "kat-schrupp",
        "Design ohne Titel-5.jpg": "kat-mehrzahn", "Design ohne Titel-6.jpg": "kat-vollradius",
        "Design ohne Titel.jpg": "kat-alu", "Design ohne Titel-4.jpg": "kat-tpc",
        "Design ohne Titel-9.png": "kat-viertelkreis", "20230823_105238_0000.png": "kat-entgrater",
        "IMG_4710.jpg": "sonder-reihe-kupfer", "IMG_4738.jpg": "sonder-reihe-gold", "IMG_4762.jpg": "sonder-reihe-profile",
        "IMG_4784.jpg": "sonder-stufe-gold",
    }
    for f, n in frei.items():
        img = Image.open(SEITEN / f).convert("RGB")
        if img.width > 2000:
            img = img.resize((1800, round(img.height * 1800 / img.width)), Image.LANCZOS)
        if "Titel-8" in f:
            optionen = dict(unten_ausblenden=0.12, saum=3)
        elif "IMG_4936" in f:
            optionen = dict(schwelle=205)  # Bodenschatten unter dem schräg liegenden Fräser
        elif "IMG_" in f:
            optionen = dict(boden=True)
        else:
            optionen = dict()
        fr = zuschneiden_alpha(freisteller.freistellen(img, **optionen), 10)
        fakt = 2.0 if fr.width < 900 else 1.0
        speichere(veredeln(fr, fakt, 60), z / f"{n}.webp", 90, 1800)
        print("  Freisteller:", n)

    g = ZIEL / "galerie"
    for f in sorted(SEITEN.glob("*ollage_*.jpg")) + [SEITEN / "IMG_20220205_180305_908 - Kopie.jpg"]:
        img = Image.open(f).convert("RGB")
        n = slug(f.stem.replace(" - Kopie", ""))
        speichere(veredeln(img, 1.45, 60), g / f"{n}.webp", 86, 1200)
        speichere(veredeln(img, 1.0, 50), g / f"{n}-klein.webp", 82, 560)

    k = ZIEL / "kunden"
    for f, n in KUNDENLOGOS.items():
        img = Image.open(SEITEN / f).convert("RGBA")
        hg = Image.new("RGBA", img.size, (255, 255, 255, 255))
        hg.alpha_composite(img)
        rgb = hg.convert("RGB")
        a = np.asarray(rgb).astype(np.int16)
        inhalt = np.where(a.min(axis=2) < 235)
        if len(inhalt[0]):
            rand = 8
            rgb = rgb.crop((max(inhalt[1].min() - rand, 0), max(inhalt[0].min() - rand, 0),
                            min(inhalt[1].max() + rand, rgb.width), min(inhalt[0].max() + rand, rgb.height)))
        fakt = 2.0 if rgb.width < 600 else 1.0
        speichere(veredeln(rgb, fakt, 50), k / f"{n}.webp", 90, 900)
    speichere(Image.open(SEITEN / "Logo_PWS_2020.png").convert("RGBA"), ZIEL / "logo" / "praezisionswerkzeuge-schmidt.webp", 92, 600)
    print("Fotos, Galerie, Kundenlogos fertig")


def video_vorschau():
    ids = ["CAjL0qteVgo", "RNCa_3UyBUs", "StSrYQWxSkI", "nQzHfyyHnFA"]
    z = ZIEL / "videos"
    z.mkdir(parents=True, exist_ok=True)
    for vid in ids:
        for variante in ("maxresdefault", "sddefault", "hqdefault"):
            r = requests.get(f"https://i.ytimg.com/vi/{vid}/{variante}.jpg", timeout=30)
            if r.status_code == 200 and len(r.content) > 5000:
                tmp = z / f"{vid}.jpg"
                tmp.write_bytes(r.content)
                speichere(veredeln(Image.open(tmp), 1.0, 40), z / f"{vid}.webp", 84, 1280)
                tmp.unlink()
                break
    print("Video-Vorschaubilder fertig")


if __name__ == "__main__":
    logo()
    produkte()
    fotos()
    video_vorschau()
