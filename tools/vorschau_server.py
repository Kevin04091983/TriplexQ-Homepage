"""Lokaler Vorschau-Server für ./website – ohne Zwischenspeichern im Browser.

Der eingebaute Server von Python erlaubt dem Browser, Seiten zwischenzuspeichern. Nach einer
Änderung sieht man dann oft noch die alte Fassung. Dieser Server schickt „no-cache“ mit.

Aufruf:  python tools/vorschau_server.py [Port]
"""
import functools
import os
import http.server
import sys
from pathlib import Path

WEB = Path(__file__).resolve().parent.parent / "website"


class OhneZwischenspeicher(http.server.SimpleHTTPRequestHandler):
    def end_headers(self):
        self.send_header("Cache-Control", "no-cache, no-store, must-revalidate")
        self.send_header("Expires", "0")
        if os.environ.get("TQ_CSP"):          # zum Testen der Content-Security-Policy wie auf dem Server
            self.send_header("Content-Security-Policy", os.environ["TQ_CSP"])
        super().end_headers()

    def log_message(self, format, *args):
        pass


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8095
    handler = functools.partial(OhneZwischenspeicher, directory=str(WEB))
    with http.server.ThreadingHTTPServer(("127.0.0.1", port), handler) as server:
        print(f"TRIPLEX-Q Vorschau läuft: http://localhost:{port}  (Beenden mit Strg+C)")
        server.serve_forever()
