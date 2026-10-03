#!/usr/bin/env python3
"""Сборка страниц: src/pages/*.html + src/partials/*.html -> корень сайта.

  <!-- @include name key="value" -->   вставить src/partials/name.html
  {{key}} / {{key|по умолчанию}}        подстановка параметра в фрагменте
  {{#key}}...{{/key}}                   блок выводится, только если key задан
  {{^key}}...{{/key}}                   блок выводится, только если key НЕ задан
  <!-- @jsonld {...} -->                JSON-LD: организация + сайт + FAQ со страницы
                                        (+ поля страницы: "breadcrumbs", "course")
Запуск: python3 tools/build.py   (после любой правки в src/)."""
import html, json, re, sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / 'src'
SITE = 'https://thousandli.ru/'
INC = re.compile(r'^([ \t]*)<!-- @include (\w[\w-]*)((?:\s+\w+="[^"]*")*)\s*-->[ \t]*$', re.M)


def render(text, params, depth=0):
    if depth > 8:
        sys.exit('include depth > 8')

    def cond(m):
        return m.group(2) if params.get(m.group(1)) else ''
    text = re.sub(r'\{\{#(\w+)\}\}(.*?)\{\{/\1\}\}', cond, text, flags=re.S)
    text = re.sub(r'\{\{\^(\w+)\}\}(.*?)\{\{/\1\}\}', lambda m: '' if params.get(m.group(1)) else m.group(2), text, flags=re.S)
    text = re.sub(r'\{\{(\w+)(?:\|([^}]*))?\}\}', lambda m: params.get(m.group(1), m.group(2) or ''), text)

    def inc(m):
        indent, name, attrs = m.groups()
        sub = dict(params)
        sub.update(dict(re.findall(r'(\w+)="([^"]*)"', attrs)))
        body = (SRC / 'partials' / f'{name}.html').read_text(encoding='utf-8')
        return render(body, sub, depth + 1).rstrip('\n')
    return INC.sub(inc, text)


def faq_entities(page_html):
    out = []
    for q, a in re.findall(r'<summary class="faq__q">(.*?)<span.*?</summary>\s*<div class="faq__a">(.*?)</div>', page_html, re.S):
        clean = lambda t: re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', ' ', t)).replace('\xa0', ' ')).strip()
        out.append({'@type': 'Question', 'name': clean(q), 'acceptedAnswer': {'@type': 'Answer', 'text': clean(a)}})
    return out


def jsonld(page_html, extra):
    graph = [
        {'@type': 'EducationalOrganization', '@id': SITE + '#org', 'name': 'Онлайн-школа китайского языка «Тысяча ли»',
         'url': SITE, 'logo': SITE + 'img/icons/icon-512.png', 'image': SITE + 'img/og-image.jpg',
         'description': 'Онлайн-уроки китайского языка для детей и взрослых, подготовка к HSK, HSKK, YCT, BCT, CSCA.',
         'telephone': '+7 953 156-06-85', 'founder': {'@type': 'Person', 'name': 'Варвара Тимофеева'},
         'areaServed': 'RU', 'knowsLanguage': ['ru', 'zh']},
        {'@type': 'WebSite', '@id': SITE + '#site', 'url': SITE, 'name': '«Тысяча ли»', 'inLanguage': 'ru',
         'publisher': {'@id': SITE + '#org'}},
    ]
    if extra.get('breadcrumbs'):
        graph.append({'@type': 'BreadcrumbList', 'itemListElement': [
            {'@type': 'ListItem', 'position': i + 1, 'name': n, 'item': SITE + u}
            for i, (n, u) in enumerate(extra['breadcrumbs'])]})
    if extra.get('course'):
        c = dict(extra['course'])
        graph.append({'@type': 'Course', 'name': c['name'], 'description': c['description'], 'inLanguage': 'ru',
                      'url': SITE + c.get('url', ''), 'provider': {'@id': SITE + '#org'}})
    faq = faq_entities(page_html)
    if faq:
        graph.append({'@type': 'FAQPage', 'mainEntity': faq})
    return ('  <script type="application/ld+json">\n'
            + json.dumps({'@context': 'https://schema.org', '@graph': graph}, ensure_ascii=False, indent=1)
            + '\n  </script>')


def build(page):
    text = render(page.read_text(encoding='utf-8'), {})
    m = re.search(r'^[ \t]*<!-- @jsonld(.*?)-->[ \t]*$', text, re.M | re.S)
    if m:
        extra = json.loads(m.group(1).strip() or '{}')
        text = text[:m.start()] + jsonld(text, extra) + text[m.end():]
    out = ROOT / page.name
    banner = '<!-- Собрано из src/pages/%s — правьте исходник и запускайте tools/build.py -->\n' % page.name
    out.write_text(text.replace('<!DOCTYPE html>\n', '<!DOCTYPE html>\n' + banner, 1), encoding='utf-8')
    left = re.findall(r'\{\{[#/^]?\w+', text)
    print(f'{out.name}: ok' + (f'  (!) не подставлено: {left}' if left else ''))


if __name__ == '__main__':
    for p in sorted((SRC / 'pages').glob('*.html')):
        build(p)
