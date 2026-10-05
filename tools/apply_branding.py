#!/usr/bin/env python3
"""Apply the current Thousand Li logo and official email to every built HTML page."""
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
EMAIL = "school@thousandli.ru"
MARKER = "<!-- branding: logo-email -->"

HEADER_LOGO_OLD = '<img src="img/logo.svg" width="210" height="62" alt="Онлайн-школа Тысяча ли">'
BRAND_COPY = """<span class="brand-lockup__copy" aria-hidden="true"><span class="brand-lockup__eyebrow">Онлайн школа</span><span class="brand-lockup__name">ТЫСЯЧА ЛИ</span></span>"""
HEADER_LOGO_NEW = f'''<span class="brand-lockup brand-lockup--header">
          <img src="https://thousandli.ru/img/logo2.svg" width="64" height="64" alt="">
          {BRAND_COPY}
        </span>'''
DRAWER_LOGO_OLD = '<img src="img/logo-menu.svg" width="127" height="37" alt="Онлайн-школа Тысяча ли">'
DRAWER_LOGO_NEW = f'''<span class="brand-lockup brand-lockup--drawer">
        <img src="https://thousandli.ru/img/logo2.svg" width="64" height="64" alt="">
        {BRAND_COPY}
      </span>'''
FOOTER_LOGO_OLD = '''<picture>
          <source media="(max-width: 1023px)" srcset="img/logo-footer-h.svg" width="210" height="62">
          <img class="footer__logo" src="img/logo-footer.svg" width="208" height="138" alt="Онлайн-школа Тысяча ли" loading="lazy">
        </picture>'''
FOOTER_LOGO_NEW = f'''<span class="brand-lockup brand-lockup--footer">
          <img src="https://thousandli.ru/img/logo2.svg" width="64" height="64" alt="" loading="lazy">
          {BRAND_COPY}
        </span>'''

MAIL_HEADER = f'''        <li><a class="header__social" href="mailto:{EMAIL}" aria-label="Написать на {EMAIL}"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2.5" y="5" width="19" height="14" rx="3"/><path d="m4 7 8 6 8-6"/></svg></a></li>'''
MAIL_DRAWER = f'''        <li><a href="mailto:{EMAIL}" aria-label="Написать на {EMAIL}"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2.5" y="5" width="19" height="14" rx="3"/><path d="m4 7 8 6 8-6"/></svg></a></li>'''
MAIL_LEAD = f'''            <li><a href="mailto:{EMAIL}" aria-label="Написать на {EMAIL}"><svg viewBox="0 0 44 44" aria-hidden="true"><circle cx="22" cy="22" r="22"/><rect x="11" y="14" width="22" height="16" rx="3"/><path d="m12.5 16 9.5 7 9.5-7"/></svg></a></li>'''

STYLE = f'''{MARKER}
  <style>
    .brand-lockup {{ display: inline-flex; align-items: center; gap: 10px; color: #004e34; }}
    .brand-lockup img {{ display: block; flex: 0 0 auto; width: 64px !important; height: 64px !important; object-fit: contain; }}
    .brand-lockup__copy {{ display: flex; flex-direction: column; align-items: flex-start; gap: 3px; font-family: var(--ff-display); line-height: 1; white-space: nowrap; }}
    .brand-lockup__eyebrow {{ font-size: 10px; font-weight: 400; letter-spacing: .01em; }}
    .brand-lockup__name {{ font-size: 16px; font-weight: 600; letter-spacing: -.02em; }}
    .brand-lockup--header {{ gap: 6px; }}
    .brand-lockup--header img {{ width: 48px !important; height: 48px !important; }}
    .brand-lockup--header .brand-lockup__copy {{ gap: 2px; }}
    .brand-lockup--header .brand-lockup__eyebrow {{ font-size: 7px; }}
    .brand-lockup--header .brand-lockup__name {{ font-size: 11px; }}
    .header__social svg {{ width: 25px; height: 25px; fill: none; stroke: #004e34; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }}
    .drawer__socials svg {{ width: 25px; height: 25px; fill: none; stroke: #fff; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }}
    .drawer__email {{ display: flex; align-items: center; gap: 13px; margin-top: 12px; font-size: 16px; }}
    .drawer__email svg {{ width: 25px; height: 25px; fill: none; stroke: #004e34; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }}
    .lead__socials svg {{ display: block; width: 43.8px; height: 43.9px; }}
    .lead__socials svg circle {{ fill: #004e34; }}
    .lead__socials svg rect, .lead__socials svg path {{ fill: none; stroke: #fff; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }}
    .brand-lockup--footer {{ color: #fff; }}
    @media (min-width: 1024px) {{
      .header__bar {{ align-items: center; }}
      .header__logo {{ display: flex; align-items: center; align-self: center; flex: 0 0 auto; margin-top: 0; margin-right: 24px; }}
      .header__nav {{ align-items: center; flex: 0 1 auto; gap: 18px; min-width: 0; margin-left: 0; padding-top: 0; }}
      .lead__tel {{ top: 82px; left: 95px; }}
    }}
    @media (max-width: 1023.98px) {{
      .header__bar {{ position: relative; justify-content: flex-end; }}
      .header__logo {{ position: absolute; top: 50%; left: 50%; display: flex; align-items: center; justify-content: center; margin: 0; transform: translate(-50%, -50%); }}
      .brand-lockup--header .brand-lockup__copy {{ display: none; }}
    }}
  </style>'''


def replace_once(text, old, new, label, page):
    count = text.count(old)
    if count != 1:
        raise RuntimeError(f"{page}: expected one {label}, found {count}")
    return text.replace(old, new, 1)


def patch(page):
    text = page.read_text(encoding="utf-8")
    if MARKER in text:
        return False

    text = replace_once(text, HEADER_LOGO_OLD, HEADER_LOGO_NEW, "header logo", page)
    text = replace_once(text, DRAWER_LOGO_OLD, DRAWER_LOGO_NEW, "drawer logo", page)
    text = replace_once(text, FOOTER_LOGO_OLD, FOOTER_LOGO_NEW, "footer logo", page)

    max_header = '        <li><a class="header__social" href="#" aria-label="MAX"><img src="img/icon-max.svg" width="25" height="25" alt=""></a></li>'
    text = replace_once(text, max_header, max_header + "\n" + MAIL_HEADER, "header MAX", page)

    max_drawer = '        <li><a href="#" aria-label="MAX"><img src="img/icon-max.svg" width="25" height="25" alt=""></a></li>'
    text = replace_once(text, max_drawer, max_drawer + "\n" + MAIL_DRAWER, "drawer MAX", page)

    drawer_phone = '      <a class="drawer__tel" href="tel:+79531560685"><img src="img/cta/phone.svg" width="25" height="25" alt="">8 (953) 156-06-85</a>'
    drawer_email = f'''      <a class="drawer__email" href="mailto:{EMAIL}"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2.5" y="5" width="19" height="14" rx="3"/><path d="m4 7 8 6 8-6"/></svg>{EMAIL}</a>'''
    text = replace_once(text, drawer_phone, drawer_phone + "\n" + drawer_email, "drawer phone", page)

    lead_title = '          <p class="lead__contacts-title">или напишите нам в мессенджер</p>'
    if lead_title not in text:
        raise RuntimeError(f"{page}: lead title not found")
    text = text.replace(lead_title, '          <p class="lead__contacts-title">или напишите нам</p>')

    max_lead = '            <li><a href="#" aria-label="MAX"><img loading="lazy" decoding="async" src="img/cta/max.svg" width="44" height="44" alt=""></a></li>'
    if max_lead not in text:
        raise RuntimeError(f"{page}: lead MAX not found")
    text = text.replace(max_lead, max_lead + "\n" + MAIL_LEAD)

    max_footer = '          <li><a class="header__social" href="#" aria-label="MAX"><img loading="lazy" decoding="async" src="img/icon-max.svg" width="25" height="25" alt=""></a></li>'
    text = replace_once(text, max_footer, max_footer + "\n" + MAIL_HEADER.replace("        ", "          ", 1), "footer MAX", page)

    footer_phone = '        <a href="tel:+79531560685">8 (953) 156-06-85</a>'
    footer_email = f'        <a href="mailto:{EMAIL}">{EMAIL}</a>'
    text = replace_once(text, footer_phone, footer_email + "\n" + footer_phone, "footer phone", page)

    if '"telephone": "+7 953 156-06-85",' in text:
        text = text.replace('"telephone": "+7 953 156-06-85",', f'"telephone": "+7 953 156-06-85",\n   "email": "{EMAIL}",', 1)

    text = replace_once(text, "</head>", STYLE + "\n</head>", "head close", page)
    page.write_text(text, encoding="utf-8")
    return True


if __name__ == "__main__":
    pages = sorted(ROOT.glob("*.html"))
    changed = sum(patch(page) for page in pages)
    print(f"Branding applied: {changed}/{len(pages)} pages changed")
