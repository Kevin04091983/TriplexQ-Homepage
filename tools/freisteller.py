"""Freisteller für Werkzeugfotos auf weißem Studiohintergrund.

Ablauf:
1. Grobe Objektmaske: dunkle oder farbige Pixel
2. Kleine Flecken entfernen, Lücken schließen, Löcher füllen
   (Schäfte, die am Bildrand angeschnitten sind, zählen dabei als geschlossen)
3. Feinsegmentierung mit GrabCut entlang der Kanten
4. Optional: Bodenspiegelung unter den Werkzeugen abschneiden
5. Weiche Kante und Entfärbung des weißen Saums
6. Optional: angeschnittene Schäfte am unteren Rand sanft ausblenden
"""
import cv2
import numpy as np
from PIL import Image


def _offene_seiten(maske, anteil=0.01):
    """Seiten, an denen das Werkzeug vom Bildrand angeschnitten ist."""
    return {"oben": (maske[1] > 0).mean() > anteil, "unten": (maske[-2] > 0).mean() > anteil,
            "links": (maske[:, 1] > 0).mean() > anteil, "rechts": (maske[:, -2] > 0).mean() > anteil}


def _loecher_fuellen(maske, seiten):
    """Alles, was vom Hintergrund aus nicht erreichbar ist, wird Objekt.
    Angeschnittene Seiten gelten als geschlossen, sonst läuft die Füllung in helle Schaftflächen."""
    h, w = maske.shape
    rahmen = np.zeros((h + 2, w + 2), np.uint8)
    rahmen[1:-1, 1:-1] = maske
    if seiten["oben"]:
        rahmen[0, :] = 255
    if seiten["unten"]:
        rahmen[-1, :] = 255
    if seiten["links"]:
        rahmen[:, 0] = 255
    if seiten["rechts"]:
        rahmen[:, -1] = 255
    frei = np.argwhere(rahmen == 0)
    if not len(frei):
        return maske
    # Startpunkt: freies Pixel am Rahmen, sonst irgendein freies Pixel
    rand = [(y, x) for y, x in frei[:5000] if y in (0, h + 1) or x in (0, w + 1)]
    y0, x0 = rand[0] if rand else frei[0]
    flut = rahmen.copy()
    cv2.floodFill(flut, np.zeros((h + 4, w + 4), np.uint8), (int(x0), int(y0)), 128)
    return np.where(flut[1:-1, 1:-1] == 128, 0, 255).astype(np.uint8)


def _kleine_entfernen(maske, anteil):
    n, lab, stats, _ = cv2.connectedComponentsWithStats(maske, connectivity=8)
    if n <= 1:
        return maske
    groesste = stats[1:, cv2.CC_STAT_AREA].max()
    behalten = np.zeros(n, bool)
    behalten[1:] = stats[1:, cv2.CC_STAT_AREA] >= groesste * anteil
    return np.where(behalten[lab], 255, 0).astype(np.uint8)


def freistellen(img, boden=False, unten_ausblenden=None, schwelle=None, grabcut=True, saum=2, angeschnitten=True):
    rgb = np.asarray(img.convert("RGB"))
    h, w = rgb.shape[:2]
    kurz = min(h, w)
    minwert = rgb.min(axis=2).astype(np.int16)
    saettigung = rgb.max(axis=2).astype(np.int16) - minwert
    if schwelle is None:
        # Helligkeit des Studiohintergrunds am Bildrand messen
        rand_px = np.concatenate([minwert[:3].ravel(), minwert[-3:].ravel(), minwert[:, :3].ravel(), minwert[:, -3:].ravel()])
        hell = rand_px[rand_px > 180]
        hg = np.percentile(hell, 10) if len(hell) else 250
        schwelle = int(np.clip(hg - 9, 200, 246))

    grob = ((minwert < schwelle) | (saettigung > 38)).astype(np.uint8) * 255
    grob = cv2.morphologyEx(grob, cv2.MORPH_OPEN, np.ones((3, 3), np.uint8))
    grob = _kleine_entfernen(grob, 0.004)
    seiten = _offene_seiten(grob) if angeschnitten else dict(oben=False, unten=False, links=False, rechts=False)
    unten_offen = seiten["unten"]
    k = max(5, int(kurz * 0.012)) | 1
    grob = cv2.morphologyEx(grob, cv2.MORPH_CLOSE, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (k, k)))
    grob = _loecher_fuellen(grob, seiten)

    # GrabCut: sicherer Kern, unsicherer Rand, sicherer Hintergrund
    rand = max(4, int(kurz * 0.01))
    kern = cv2.erode(grob, np.ones((rand, rand), np.uint8))
    aussen = cv2.dilate(grob, np.ones((rand * 2, rand * 2), np.uint8))
    gc = np.full((h, w), cv2.GC_BGD, np.uint8)
    gc[aussen > 0] = cv2.GC_PR_BGD
    gc[grob > 0] = cv2.GC_PR_FGD
    gc[kern > 0] = cv2.GC_FGD
    bgr = cv2.cvtColor(rgb, cv2.COLOR_RGB2BGR)
    bm, fm = np.zeros((1, 65), np.float64), np.zeros((1, 65), np.float64)
    fein = grob
    if grabcut:
        try:
            cv2.grabCut(bgr, gc, None, bm, fm, 4, cv2.GC_INIT_WITH_MASK)
            fein = np.where((gc == cv2.GC_FGD) | (gc == cv2.GC_PR_FGD), 255, 0).astype(np.uint8)
        except cv2.error:
            fein = grob
    fein = _kleine_entfernen(fein, 0.004)
    fein = _loecher_fuellen(fein, seiten)

    if boden:
        # Unter jedem Werkzeug liegt nur Spiegelung: spaltenweise direkt unter der dunklen Unterkante abschneiden,
        # Spalten ohne Werkzeug (Horizontlinie zwischen den Werkzeugen) ganz leeren
        dunkel = (minwert < 175) & (fein > 0)
        hat = dunkel.any(axis=0)
        if hat.any():
            unterkante = np.where(hat, h - 1 - np.argmax(dunkel[::-1], axis=0), 0)
            breite = max(3, int(kurz * 0.004))
            unterkante = cv2.dilate(unterkante.astype(np.float32).reshape(1, -1), np.ones((1, breite * 2 + 1), np.uint8)).ravel()
            spalte_ok = cv2.dilate(hat.astype(np.uint8).reshape(1, -1), np.ones((1, breite * 2 + 1), np.uint8)).ravel() > 0
            zeilen = np.arange(h)[:, None]
            fein[(zeilen > unterkante[None, :] + 1) | ~spalte_ok[None, :]] = 0
            senkrecht = cv2.getStructuringElement(cv2.MORPH_RECT, (1, max(7, int(kurz * 0.012))))
            fein = cv2.morphologyEx(fein, cv2.MORPH_OPEN, senkrecht)
            fein = _kleine_entfernen(fein, 0.004)

    # weiche Kante (1 px nach innen, damit kein weißer Saum bleibt)
    fein = cv2.erode(fein, np.ones((saum, saum), np.uint8)) if saum > 1 else fein
    alpha = cv2.GaussianBlur(fein.astype(np.float32) / 255.0, (0, 0), max(0.7, kurz / 1400))

    # Entfärben: Randpixel enthalten Weiß vom Hintergrund
    farbe = rgb.astype(np.float32)
    a3 = alpha[..., None]
    halb = (a3 > 0.02) & (a3 < 0.98)
    entfaerbt = (farbe - (1 - a3) * 255.0) / np.maximum(a3, 0.02)
    farbe = np.where(halb, np.clip(entfaerbt, 0, 255), farbe)

    if unten_offen and unten_ausblenden:
        hoehe = int(h * unten_ausblenden)
        verlauf = np.ones(h, np.float32)
        verlauf[h - hoehe:] = np.linspace(1, 0, hoehe) ** 1.6
        alpha *= verlauf[:, None]

    out = np.dstack([farbe, alpha * 255]).astype(np.uint8)
    return Image.fromarray(out, "RGBA")
