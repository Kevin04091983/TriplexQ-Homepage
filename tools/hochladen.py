"""Seite bauen, Upload-Paket schnüren und per SFTP zu Strato hochladen.

  python tools/hochladen.py --test --nur-paket     Ordner upload/ + ZIP erzeugen (zum Hochladen mit FileZilla/Cyberduck)
  python tools/hochladen.py --test --erstmals      Testfassung hochladen, inkl. Einrichtungs-Assistent
  python tools/hochladen.py --test                 Testfassung (triplex-q.de, für Google unsichtbar) hochladen
  python tools/hochladen.py --live                 Livefassung (www.triplex-q.com) hochladen – in BEIDE Pakete:
                                                   Webshop-Paket (triplex-q.com) und Hosting Plus (triplex-q.de leitet dann um)
  python tools/hochladen.py --test --nach live     Testfassung ins Webshop-Paket (Umschalten vorbereiten)

Ziele in tools/strato.json: "test" = Paket Hosting Plus (triplex-q.de), "live" = Paket Webshop Now Pro (triplex-q.com).

Hochgeladen wird mit einem SSH-Schlüssel (~/.ssh/triplexq_strato) – ohne Passwort. Server, Benutzer und
Zielordner stehen in tools/strato.json (keine Geheimnisse). Nie hochgeladen wird api/einstellungen.php:
die echte Datei mit den Zugangsdaten entsteht auf dem Server durch den Einrichtungs-Assistenten.
Dateien auf dem Server werden nur ergänzt oder ersetzt, nie gelöscht.
"""
import json
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

WURZEL = Path(__file__).resolve().parent.parent
WEB = WURZEL / "website"
UPLOAD = WURZEL / "upload"
KONFIG = Path(__file__).resolve().parent / "strato.json"
SCHLUESSEL = Path.home() / ".ssh" / "triplexq_strato"

# Zugangsdaten entstehen auf dem Server; das Passwort-Hilfsskript ersetzt der Einrichtungs-Assistent
NIE_HOCHLADEN = {Path("api/einstellungen.php"), Path("admin/passwort-hash.php")}


def paket(erstmals):
    if UPLOAD.exists():
        shutil.rmtree(UPLOAD)
    for datei in WEB.rglob("*"):
        rel = datei.relative_to(WEB)
        if datei.is_dir() or rel in NIE_HOCHLADEN:
            continue
        if rel.parts[0] == "einrichtung" and not erstmals:
            continue  # Assistent nur beim ersten Mal (danach ist er auf dem Server ohnehin gesperrt)
        ziel = UPLOAD / rel
        ziel.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(datei, ziel)
    zip_name = WURZEL / ("TriplexQ-Website-" + ("TEST" if "--test" in sys.argv else "LIVE") + "-zum-Hochladen")
    shutil.make_archive(str(zip_name), "zip", UPLOAD)
    anzahl = sum(1 for d in UPLOAD.rglob("*") if d.is_file())
    print(f"Paket: {anzahl} Dateien in {UPLOAD}  (ZIP: {zip_name.name}.zip)")
    return anzahl


def sftp_programm():
    for kandidat in [shutil.which("sftp"), r"C:\Windows\System32\OpenSSH\sftp.exe"]:
        if kandidat and Path(kandidat).exists():
            return kandidat
    sys.exit("sftp nicht gefunden (OpenSSH installieren).")


def hochladen(ziel_name):
    k = json.loads(KONFIG.read_text(encoding="utf-8"))[ziel_name]
    if not k.get("benutzer"):
        sys.exit("In tools/strato.json fehlt der SFTP-Benutzer (steht im Strato-Kundenbereich unter SFTP/SSH).")
    if not SCHLUESSEL.exists():
        sys.exit(f"SSH-Schlüssel fehlt: {SCHLUESSEL}  ->  ssh-keygen -t ed25519 -f {SCHLUESSEL} -C triplexq-upload")
    ziel = k["ordner"].rstrip("/")
    zeilen = [f"-mkdir {ziel}"]
    for d in sorted(p for p in UPLOAD.rglob("*") if p.is_dir()):
        zeilen.append(f'-mkdir "{ziel}/{d.relative_to(UPLOAD).as_posix()}"')
    for f in sorted(p for p in UPLOAD.rglob("*") if p.is_file()):
        rel = f.relative_to(UPLOAD).as_posix()
        zeilen.append(f'put "{f.as_posix()}" "{ziel}/{rel}"')
    with tempfile.NamedTemporaryFile("w", suffix=".txt", delete=False, encoding="utf-8") as b:
        b.write("\n".join(zeilen) + "\n")
    befehl = [sftp_programm(), "-i", str(SCHLUESSEL), "-o", "IdentitiesOnly=yes", "-o", "BatchMode=yes",
              "-o", "StrictHostKeyChecking=accept-new", "-b", b.name, f"{k['benutzer']}@{k['host']}"]
    print(f"Lade hoch nach {k['host']}:{ziel}  ({k.get('hinweis', ziel_name)}) …")
    erg = subprocess.run(befehl, capture_output=True, text=True)
    Path(b.name).unlink(missing_ok=True)
    if erg.returncode != 0:
        print(erg.stdout[-2000:], erg.stderr[-2000:])
        sys.exit("Hochladen fehlgeschlagen.")
    print("Hochgeladen ✔")


def main():
    if not ({"--test", "--live"} & set(sys.argv)):
        sys.exit(__doc__)
    test = "--test" in sys.argv
    bau = [sys.executable, str(WURZEL / "tools" / "build_site.py")] + (["--test"] if test else [])
    subprocess.run(bau, check=True)
    paket("--erstmals" in sys.argv)
    if "--nur-paket" in sys.argv:
        return
    if "--nach" in sys.argv:
        ziele = [sys.argv[sys.argv.index("--nach") + 1]]
    else:
        ziele = ["test"] if test else ["live", "test"]
    for z in ziele:
        hochladen(z)


if __name__ == "__main__":
    main()
