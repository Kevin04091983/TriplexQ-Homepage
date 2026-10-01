#!/bin/bash
# TRIPLEX-Q Vorschau auf dem Mac: Doppelklick im Finder (oder ./Start-Vorschau.command im Terminal)
cd "$(dirname "$0")"
( sleep 1; open "http://localhost:8095" ) &
python3 tools/vorschau_server.py 8095
