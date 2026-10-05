#!/usr/bin/env python3
"""Apply the hosted logo2.svg favicon and social-channel links to every built HTML page."""
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
FAVICON_OLD = '''  <link rel="icon" href="favicon.ico" sizes="32x32">
  <link rel="icon" href="favicon.svg" type="image/svg+xml">'''
FAVICON_NEW = '''  <link rel="icon" href="https://thousandli.ru/img/logo2.svg?v=2" type="image/svg+xml">'''
MAX_OLD = 'href="#" aria-label="MAX"'
MAX_NEW = 'href="https://max.ru/channel_thousandli" target="_blank" rel="noopener noreferrer" aria-label="MAX — открыть канал в новой вкладке"'
TG_OLD = 'href="#" aria-label="Telegram"'
TG_NEW = 'href="https://t.me/schoolthousandli" target="_blank" rel="noopener noreferrer" aria-label="Telegram — открыть канал в новой вкладке"'

pages = sorted(ROOT.glob("*.html"))
max_links = 0
tg_links = 0
for page in pages:
    text = page.read_text(encoding="utf-8")

    if FAVICON_NEW not in text:
        if text.count(FAVICON_OLD) != 1:
            raise RuntimeError(f"{page}: expected one favicon block, found {text.count(FAVICON_OLD)}")
        text = text.replace(FAVICON_OLD, FAVICON_NEW, 1)

    max_count = text.count(MAX_OLD)
    tg_count = text.count(TG_OLD)
    if max_count == 0 and MAX_NEW not in text:
        raise RuntimeError(f"{page}: MAX icons not found")
    if tg_count == 0 and TG_NEW not in text:
        raise RuntimeError(f"{page}: Telegram icons not found")

    text = text.replace(MAX_OLD, MAX_NEW)
    text = text.replace(TG_OLD, TG_NEW)
    max_links += text.count(MAX_NEW)
    tg_links += text.count(TG_NEW)
    page.write_text(text, encoding="utf-8")

print(f"Updated {len(pages)} pages: MAX links={max_links}, Telegram links={tg_links}")
