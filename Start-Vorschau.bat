@echo off
title TRIPLEX-Q Vorschau
cd /d "%~dp0"
start "" http://localhost:8095
python tools\vorschau_server.py 8095
