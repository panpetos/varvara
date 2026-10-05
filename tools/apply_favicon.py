#!/usr/bin/env python3
"""Use the hosted logo2.svg as the favicon on every built HTML page."""
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OLD = '''  <link rel="icon" href="favicon.ico" sizes="32x32">
  <link rel="icon" href="favicon.svg" type="image/svg+xml">'''
NEW = '''  <link rel="icon" href="https://thousandli.ru/img/logo2.svg?v=2" type="image/svg+xml">'''

pages = sorted(ROOT.glob("*.html"))
for page in pages:
    text = page.read_text(encoding="utf-8")
    if NEW in text:
        continue
    if text.count(OLD) != 1:
        raise RuntimeError(f"{page}: expected one favicon block, found {text.count(OLD)}")
    page.write_text(text.replace(OLD, NEW, 1), encoding="utf-8")

print(f"Favicon logo2.svg applied to {len(pages)} pages")
