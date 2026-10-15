#!/usr/bin/env python3
"""Английская и китайская версии сайта: en/*.html и zh/*.html из собранных русских страниц.

Запускается сам в конце tools/build.py (отдельно: python3 tools/i18n.py).
  - Переводы — tools/i18n/en.json и zh.json: {"русский фрагмент": "перевод"}.
    Фрагмент — содержимое элемента с текстом вместе со строчными тегами (<b>, <br>, <a>…),
    пробелы схлопнуты. Нет перевода — на странице остаётся русский текст, скрипт пишет, сколько таких.
  - python3 tools/i18n.py --todo   → tools/i18n/todo-<язык>.json: фрагменты без перевода (вписать в словарь).
  - Логотип, favicon и ссылки соцсетей (apply_branding / apply_favicon) на en/zh накладываются здесь же:
    deploy.yml их применяет только к страницам в корне.
  - Документы (политика, согласие, cookie) и 404 — только на русском: ссылки ведут на русские страницы.
  - На всех страницах: переключатель языка (<!-- lang-switch --> в шапке и меню) и hreflang в <head>.
"""
import html, json, re, sys, tempfile
from html.parser import HTMLParser
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DICT_DIR = ROOT / 'tools' / 'i18n'
SITE = 'https://thousandli.ru/'
sys.path.insert(0, str(ROOT / 'tools'))

LANGS = {
    'en': {'html': 'en', 'og': 'en_US', 'label': 'EN', 'title': 'English'},
    'zh': {'html': 'zh-CN', 'og': 'zh_CN', 'label': '中文', 'title': '中文'},
}
# переводимые страницы (остальные — только русские)
PAGES = ['index.html', 'kids.html', 'adults.html', 'business.html', 'politics.html', 'tech.html', 'other.html',
         'hsk.html', 'hskk.html', 'yct.html', 'bct.html', 'csca.html', 'founder.html']

COMPARE_LOGO = {
    lang: ('<span class="brand-lockup brand-lockup--footer"><img src="https://thousandli.ru/img/logo2.svg" width="64" height="64" '
           f'alt="" loading="lazy" decoding="async"><span class="brand-lockup__copy"><span class="brand-lockup__eyebrow">{eyebrow}</span>'
           f'<span class="brand-lockup__name">{name}</span></span></span>')
    for lang, eyebrow, name in [('en', 'Online school', 'THOUSAND LI'), ('zh', '汉语网校', '千里')]
}
CYR = re.compile('[А-Яа-яЁё]')
VOID = {'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'}
# строчные теги: остаются внутри переводимого фрагмента
INLINE = {'a', 'b', 'br', 'em', 'i', 'mark', 'nobr', 'small', 'span', 'strong', 'sub', 'sup', 'u', 's', 'wbr', 'q'}
SKIP = {'script', 'style', 'svg', 'template'}
# data-program не переводим: название программы уходит в заявку — Варвара получает его по-русски
TEXT_ATTRS = ('alt', 'title', 'aria-label', 'placeholder', 'data-label', 'data-caption', 'data-alt')
META_TEXT = {'description', 'og:title', 'og:description', 'og:image:alt', 'og:site_name', 'twitter:title',
             'twitter:description'}
URL_ATTRS = ('href', 'src', 'srcset', 'action', 'poster', 'data-src', 'data-poster')


# ---------- разбор HTML в дерево с позициями в исходнике ----------
class Node:
    def __init__(self, tag, attrs, s0, s1):
        self.tag, self.attrs, self.s0, self.s1 = tag, dict(attrs), s0, s1
        self.e0 = self.e1 = s1
        self.children = []


class Tree(HTMLParser):
    def __init__(self, text):
        super().__init__(convert_charrefs=False)
        self.text = text
        self.lines = [0]
        for m in re.finditer('\n', text):
            self.lines.append(m.end())
        self.root = Node('#root', [], 0, 0)
        self.stack = [self.root]
        self.feed(text)
        self.close()
        self.root.e0 = self.root.e1 = len(text)

    def pos(self):
        line, col = self.getpos()
        return self.lines[line - 1] + col

    def handle_starttag(self, tag, attrs):
        s0 = self.pos()
        node = Node(tag, attrs, s0, s0 + len(self.get_starttag_text()))
        self.stack[-1].children.append(node)
        if tag not in VOID:
            self.stack.append(node)

    def handle_startendtag(self, tag, attrs):
        s0 = self.pos()
        self.stack[-1].children.append(Node(tag, attrs, s0, s0 + len(self.get_starttag_text())))

    def handle_endtag(self, tag):
        e0 = self.pos()
        e1 = self.text.index('>', e0) + 1
        if not any(n.tag == tag for n in self.stack[1:]):
            return
        while True:
            node = self.stack.pop()
            node.e0, node.e1 = (e0, e1) if node.tag == tag else (e0, e0)
            if node.tag == tag:
                break


def norm(s):
    return re.sub(r'\s+', ' ', s).strip()


def all_inline(node):
    return all(c.tag in INLINE and all_inline(c) for c in node.children)


def text_units(text, node, out):
    """Переводимые фрагменты: (начало, конец) в исходнике."""
    if node.tag in SKIP:
        return
    # куски текста прямо в этом элементе (между детьми)
    gaps, prev = [], node.s1
    for c in node.children:
        gaps.append((prev, c.s0))
        prev = c.e1
    gaps.append((prev, node.e0))
    direct = any(CYR.search(re.sub(r'<!--.*?-->', '', text[a:b], flags=re.S)) for a, b in gaps)
    if node.tag != '#root' and direct and all_inline(node):
        out.append((node.s1, node.e0))
        return
    for a, b in gaps:
        chunk = re.sub(r'<!--.*?-->', '', text[a:b], flags=re.S)
        if CYR.search(chunk):
            # комментарии внутри куска не переводим: берём текст между ними
            for m in re.finditer(r'(?:(?!<!--).)+', text[a:b], re.S):
                if CYR.search(m.group()) and not m.group().lstrip().startswith('-->'):
                    seg = m.group()
                    if '-->' in seg:
                        off = seg.index('-->') + 3
                        out.append((a + m.start() + off, a + m.end()))
                    else:
                        out.append((a + m.start(), a + m.end()))
    for c in node.children:
        text_units(text, c, out)


def walk(node):
    yield node
    for c in node.children:
        yield from walk(c)


# ---------- перевод одной страницы ----------
class Translator:
    def __init__(self, lang):
        self.lang = lang
        path = DICT_DIR / f'{lang}.json'
        self.d = json.loads(path.read_text(encoding='utf-8')) if path.exists() else {}
        self.missing = {}

    def t(self, s, where):
        key = norm(s)
        if not CYR.search(key):
            return s
        if key in self.d and self.d[key]:
            return self.d[key]
        self.missing.setdefault(key, where)
        return s


def rewrite_url(v, lang):
    """URL на странице en/xxx.html: ресурсы и русские документы — из корня, переводимые страницы — рядом."""
    if not v or re.match(r'^([a-z][a-z0-9+.-]*:|//|/|#|\?)', v, re.I):
        if v.startswith(SITE):
            rest = v[len(SITE):]
            path = re.split(r'[?#]', rest)[0]
            if path in PAGES or path == '':
                return SITE + lang + '/' + rest
        return v
    path = re.split(r'[?#]', v)[0]
    if path in ('', './') or path in PAGES:
        return v
    if path.endswith('.html'):
        return '../' + v
    return '../' + v


def rewrite_srcset(v, lang):
    return ', '.join(' '.join([rewrite_url(p.split()[0], lang)] + p.split()[1:]) for p in v.split(',') if p.strip())


def patch_tag(raw, node, tr, lang, where):
    """Атрибуты открывающего тега: тексты и ссылки."""
    def attr(m):
        name, q, val = m.group(1), m.group(2), m.group(3)
        low = name.lower()
        new = val
        if tr and (low in TEXT_ATTRS or (low == 'content' and node.tag == 'meta'
                   and (node.attrs.get('name') in META_TEXT or node.attrs.get('property') in META_TEXT))):
            if CYR.search(val):
                new = html.escape(tr.t(html.unescape(val), where), quote=True)
        if lang and low in URL_ATTRS:
            new = rewrite_srcset(val, lang) if low == 'srcset' else rewrite_url(val, lang)
        if lang and low == 'content' and node.tag == 'meta' and node.attrs.get('property') == 'og:url':
            new = rewrite_url(val, lang)
        if lang and low == 'content' and node.tag == 'meta' and node.attrs.get('property') == 'og:locale':
            new = LANGS[lang]['og']
        if lang and low == 'style' and 'url(' in val:
            new = re.sub(r'url\((["\']?)([^)"\']+)\1\)', lambda u: f'url({u.group(1)}{rewrite_url(u.group(2), lang)}{u.group(1)})', val)
        return f'{name}={q}{new}{q}' if new != val else m.group(0)
    return re.sub(r'([\w:-]+)=(["\'])(.*?)\2', attr, raw, flags=re.S)


def translate_jsonld(block, tr, page_html, where):
    data = json.loads(block)
    import build
    for item in data.get('@graph', []):
        if item.get('@type') == 'FAQPage':
            item['mainEntity'] = build.faq_entities(page_html)
        if 'inLanguage' in item:
            item['inLanguage'] = LANGS[tr.lang]['html']

    def tx(o):
        if isinstance(o, dict):
            return {k: (tx(v) if k not in ('@id', 'url', 'item', 'logo', 'image', '@type') else
                        (rewrite_url(v, tr.lang) if k in ('url', 'item') and isinstance(v, str) else v))
                    for k, v in o.items()}
        if isinstance(o, list):
            return [tx(v) for v in o]
        if isinstance(o, str) and CYR.search(o):
            return html.unescape(re.sub(r'<[^>]+>', ' ', tr.t(o, where))).strip()
        return o
    graph = []
    for item in data.get('@graph', []):
        graph.append(item if item.get('@type') == 'FAQPage' else tx(item))
    data['@graph'] = graph
    return json.dumps(data, ensure_ascii=False, indent=1)


def translate_html(text, tr, lang, where):
    tree = Tree(text)
    edits = []
    units = []
    text_units(text, tree.root, units)
    for a, b in units:
        seg = text[a:b]
        lead = seg[:len(seg) - len(seg.lstrip())]
        trail = seg[len(seg.rstrip()):]
        new = tr.t(seg, where)
        # ссылки внутри фрагмента (<a href> в тексте) правим здесь: теги фрагмента целиком в переводе
        fixed = re.sub(r'\b(href|src)="([^"]*)"', lambda m: f'{m.group(1)}="{rewrite_url(m.group(2), lang)}"', new)
        if new is not seg or fixed != new:
            edits.append((a, b, lead + fixed.strip() + trail if new is not seg else fixed))
    inside = lambda pos: any(a <= pos < b for a, b in units)
    for node in walk(tree.root):
        if node.tag == '#root' or inside(node.s0):
            continue
        raw = text[node.s0:node.s1]
        new = patch_tag(raw, node, tr, lang, where)
        if new != raw:
            edits.append((node.s0, node.s1, new))
        if node.tag == 'html':
            edits.append((node.s0, node.s1, re.sub(r'lang="ru"', f'lang="{LANGS[lang]["html"]}"', new)))
    # правки не пересекаются: атрибуты — внутри открывающих тегов, тексты — между тегами
    edits.sort(key=lambda e: e[0])
    out, prev = [], 0
    seen = set()
    for a, b, new in edits:
        if (a, b) in seen:
            out[-1] = new
            continue
        seen.add((a, b))
        out.append(text[prev:a])
        out.append(new)
        prev = b
    out.append(text[prev:])
    res = ''.join(out)
    # JSON-LD — после текста, чтобы FAQ собрался из переведённых вопросов
    m = re.search(r'(<script type="application/ld\+json">\s*)(.*?)(\s*</script>)', res, re.S)
    if m:
        res = res[:m.start(2)] + translate_jsonld(m.group(2), tr, res, where) + res[m.end(2):]
    return res


# ---------- переключатель языка и hreflang ----------
def lang_url(page, lang):
    name = '' if page == 'index.html' else page
    if page not in PAGES:
        name = ''
    return SITE + (lang + '/' if lang != 'ru' else '') + name


def switcher(page, cur, cls):
    items = [('ru', 'RU', 'Русский')] + [(k, v['label'], v['title']) for k, v in LANGS.items()]
    links = []
    for code, label, title in items:
        target = page if page in PAGES else 'index.html'
        name = '' if target == 'index.html' else target
        if cur == 'ru':
            href = (code + '/' + name) if code != 'ru' else (name or './')
        elif code == cur:
            href = name or './'
        else:
            href = '../' + ((code + '/') if code != 'ru' else '') + name
            if href == '../':
                href = '../'
        if code == cur:  # текущий язык — сама страница (на документах тоже, а не главная)
            href = ('' if page == 'index.html' else page) or './'
        hreflang = 'ru' if code == 'ru' else LANGS[code]['html']
        attr = ' aria-current="true"' if code == cur else ''
        links.append(f'<a class="lang__link" href="{href}" hreflang="{hreflang}" lang="{hreflang}" title="{title}"{attr}>{label}</a>')
    aria = {'ru': 'Язык сайта', 'en': 'Site language', 'zh': '网站语言'}[cur]
    return f'<nav class="lang {cls}" aria-label="{aria}">' + ''.join(links) + '</nav>'


def unswitch(text):
    """Убрать уже вставленные переключатель и hreflang (повторный запуск, перевод из русской страницы)."""
    text = re.sub(r'^([ \t]*)<nav class="lang (\S+)"[^\n]*?</nav>', r'\1<!-- lang-switch \2 -->', text, flags=re.M)
    return re.sub(r'\n[ \t]*<link rel="alternate" hreflang="[^"]*" href="[^"]*">', '', text)


def add_switch_and_hreflang(text, page, cur):
    def sw(m):
        return m.group(1) + switcher(page, cur, m.group(2))
    text = re.sub(r'^([ \t]*)<!-- lang-switch (\S+) -->', sw, text, flags=re.M)
    if page in PAGES and 'hreflang="x-default"' not in text:
        links = [f'<link rel="alternate" hreflang="ru" href="{lang_url(page, "ru")}">']
        links += [f'<link rel="alternate" hreflang="{v["html"]}" href="{lang_url(page, k)}">' for k, v in LANGS.items()]
        links.append(f'<link rel="alternate" hreflang="x-default" href="{lang_url(page, "ru")}">')
        text = re.sub(r'(\n[ \t]*)(<link rel="canonical"[^>]*>)', lambda m: m.group(1) + m.group(2) + ''.join(m.group(1) + l for l in links), text, count=1)
    return text


def branded(page_path):
    """Русская страница с логотипом/favicon/ссылками соцсетей — как её выкладывает deploy.yml."""
    import apply_branding, apply_favicon
    with tempfile.TemporaryDirectory() as tmp:
        p = Path(tmp) / page_path.name
        p.write_text(unswitch(page_path.read_text(encoding='utf-8')), encoding='utf-8')
        apply_branding.patch(p)
        text = apply_favicon.patch_text(p.read_text(encoding='utf-8'), p)
    return text


def update_sitemap():
    """sitemap.xml: к каждой переводимой странице — её en/ и zh/ версии (дата и приоритет — как у русской)."""
    path = ROOT / 'sitemap.xml'
    text = path.read_text(encoding='utf-8')
    text = re.sub(r'\s*<url>\s*<loc>' + re.escape(SITE) + r'(?:' + '|'.join(LANGS) + r')/.*?</url>', '', text, flags=re.S)

    def add(m):
        loc = m.group(2)
        page = loc[len(SITE):] or 'index.html'
        if page not in PAGES:
            return m.group(0)
        extra = ''.join('\n' + m.group(1) + m.group(0).strip().replace(SITE, SITE + lang + '/', 1) for lang in LANGS)
        return m.group(0) + extra
    text = re.sub(r'([ \t]*)<url>\s*<loc>([^<]+)</loc>.*?</url>', add, text, flags=re.S)
    path.write_text(text, encoding='utf-8')


def main(todo=False):
    total = {}
    update_sitemap()
    # русские страницы в корне: переключатель и hreflang
    for page in sorted(ROOT.glob('*.html')):
        text = page.read_text(encoding='utf-8')
        new = add_switch_and_hreflang(unswitch(text), page.name, 'ru')
        if new != text:
            page.write_text(new, encoding='utf-8')
    for lang in LANGS:
        tr = Translator(lang)
        out_dir = ROOT / lang
        out_dir.mkdir(exist_ok=True)
        for name in PAGES:
            src = branded(ROOT / name)
            res = translate_html(src, tr, lang, name)
            res = add_switch_and_hreflang(res, name, lang)
            res = res.replace('<!-- Собрано из src/pages/', '<!-- Перевод (tools/i18n.py) страницы, собранной из src/pages/', 1)
            # телефон для иностранцев — в международном виде
            res = res.replace('>8 (953) 156-06-85<', '>+7 (953) 156-06-85<')
            # логотип в таблице сравнения — картинка с русской надписью: ставим логотип с переведённым названием
            res = re.sub(r'<img[^>]*src="\.\./img/compare/logo\.svg"[^>]*>', COMPARE_LOGO[lang], res)
            # запрет индексации тестовой копии deploy.yml снимает только в корне — переводам он не нужен
            res = re.sub(r'^[^\n]*data-env="test"[^\n]*\n', '', res, flags=re.M)
            (out_dir / name).write_text(res, encoding='utf-8')
        total[lang] = len(tr.missing)
        todo_path = DICT_DIR / f'todo-{lang}.json'
        if todo:
            todo_path.write_text(json.dumps({k: '' for k in tr.missing}, ensure_ascii=False, indent=1), encoding='utf-8')
        print(f'i18n {lang}: {len(PAGES)} страниц, без перевода {len(tr.missing)} фрагментов'
              + (f' → {todo_path.name}' if todo and tr.missing else ''))
    return total


if __name__ == '__main__':
    main(todo='--todo' in sys.argv)
