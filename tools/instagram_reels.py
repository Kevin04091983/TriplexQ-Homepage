"""Instagram-Reels von @triplex_q_tools für die Startseite: Vorschaubild + Text holen.

Die Vorschaubilder werden auf den eigenen Server übernommen (assets/img/instagram/), damit die Seite
beim Aufruf nichts von Instagram/Meta lädt. Erst ein Klick auf ein Reel lädt das Video von Instagram.

Aufruf:  python tools/instagram_reels.py            (Liste REELS unten bei neuen Videos ergänzen)
Danach:  python tools/build_site.py --test
"""
import html
import io
import json
import re
from pathlib import Path

import requests
from PIL import Image

WURZEL = Path(__file__).resolve().parent.parent
ZIEL = WURZEL / "website" / "assets" / "img" / "instagram"
DATEN = WURZEL / "data" / "instagram.json"

# Reels vom Profil https://www.instagram.com/triplex_q_tools/ (neueste zuerst): Kennung, Datum
REELS = [
    ("DCHup8Yul2-", "2024-11-08"),
    ("DBOtDzjoewr", "2024-10-17"),
    ("DA3_LDRuwmZ", "2024-10-08"),
    ("DAwKSjmu4fU", "2024-10-05"),
    ("DAiea5pulfG", "2024-09-30"),
    ("DAaKipjIjio", "2024-09-26"),
    ("DATtQxnuM-x", "2024-09-24"),
    ("DALFKuvu0ix", "2024-09-21"),
    ("DABvtsoudsZ", "2024-09-17"),
    ("C_-_QIIofd2", "2024-09-16"),
    ("C_2KNydoEjQ", "2024-09-12"),
]

S = requests.Session()
S.headers["User-Agent"] = "Mozilla/5.0 (Windows NT 10.0; Win64; x64)"  # schlanke Einbettungsseite mit Vorschaubild


def text_aus(seite):
    m = re.search(r'<div class="Caption">(.*?)</div>', seite, re.S)
    if not m:
        return ""
    t = re.sub(r'<a class="CaptionUsername".*?</a>', "", m.group(1), flags=re.S)
    t = re.sub(r"<br\s*/?>", " ", t)
    t = html.unescape(re.sub(r"<[^>]+>", "", t))
    t = re.sub(r"#\S+", "", t)                       # Hashtags weglassen
    return re.sub(r"\s+", " ", t).strip()


def main():
    ZIEL.mkdir(parents=True, exist_ok=True)
    daten = []
    for code, datum in REELS:
        seite = S.get(f"https://www.instagram.com/reel/{code}/embed/captioned/", timeout=30).text
        m = re.search(r'class="EmbeddedMediaImage"[^>]*src="([^"]+)"', seite)
        if not m:
            print("  ! kein Vorschaubild:", code)
            continue
        bild = Image.open(io.BytesIO(S.get(html.unescape(m.group(1)), timeout=30).content)).convert("RGB")
        bild.thumbnail((540, 960), Image.LANCZOS)
        bild.save(ZIEL / f"{code}.webp", "WEBP", quality=82, method=6)
        daten.append({"code": code, "datum": datum, "text": text_aus(seite)[:220],
                      "breite": bild.width, "hoehe": bild.height})
        print(f"  {code}  {bild.width}×{bild.height}  {daten[-1]['text'][:60]}")
    DATEN.write_text(json.dumps(daten, ensure_ascii=False, indent=1), encoding="utf-8")
    print(f"{len(daten)} Reels gespeichert -> {DATEN}")


if __name__ == "__main__":
    main()
