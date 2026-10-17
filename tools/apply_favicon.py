#!/usr/bin/env python3
"""Apply the hosted logo2.svg favicon and social-channel links to every built HTML page."""
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
FAVICON_OLD = '''  <link rel="icon" href="favicon.ico" sizes="32x32">
  <link rel="icon" href="favicon.svg" type="image/svg+xml">'''
FAVICON_NEW = '''  <link rel="icon" href="https://thousandli.ru/img/logo2.svg?v=2" type="image/svg+xml">
  <link rel="icon" href="https://thousandli.ru/favicon.ico?v=2" sizes="any">
  <link rel="icon" href="https://thousandli.ru/img/icons/favicon-32.png?v=2" type="image/png" sizes="32x32">'''
FAVICON_LEGACY = '''  <link rel="icon" href="https://thousandli.ru/img/logo2.svg?v=2" type="image/svg+xml">'''
TOUCH_OLD = 'href="apple-touch-icon.png"'
TOUCH_NEW = 'href="apple-touch-icon.png?v=2"'
MAX_OLD = 'href="#" aria-label="MAX"'
MAX_NEW = 'href="https://max.ru/channel_thousandli" target="_blank" rel="noopener noreferrer" aria-label="MAX — открыть канал в новой вкладке"'
TG_OLD = 'href="#" aria-label="Telegram"'
TG_NEW = 'href="https://t.me/schoolthousandli" target="_blank" rel="noopener noreferrer" aria-label="Telegram — открыть канал в новой вкладке"'


def patch_text(text, page):
    """Текст страницы с favicon и ссылками на каналы (tools/i18n.py вызывает для en/zh)."""
    if FAVICON_NEW not in text:
        if text.count(FAVICON_OLD) == 1:
            text = text.replace(FAVICON_OLD, FAVICON_NEW, 1)
        elif text.count(FAVICON_LEGACY) == 1:
            text = text.replace(FAVICON_LEGACY, FAVICON_NEW, 1)
        else:
            raise RuntimeError(f"{page}: expected one favicon block, found {text.count(FAVICON_OLD)}")
    text = text.replace(TOUCH_OLD, TOUCH_NEW)

    if text.count(MAX_OLD) == 0 and MAX_NEW not in text:
        raise RuntimeError(f"{page}: MAX icons not found")
    if text.count(TG_OLD) == 0 and TG_NEW not in text:
        raise RuntimeError(f"{page}: Telegram icons not found")

    return text.replace(MAX_OLD, MAX_NEW).replace(TG_OLD, TG_NEW)


if __name__ == "__main__":
    pages = sorted(ROOT.glob("*.html"))
    max_links = 0
    tg_links = 0
    for page in pages:
        text = patch_text(page.read_text(encoding="utf-8"), page)
        max_links += text.count(MAX_NEW)
        tg_links += text.count(TG_NEW)
        page.write_text(text, encoding="utf-8")

    print(f"Updated {len(pages)} pages: MAX links={max_links}, Telegram links={tg_links}")
