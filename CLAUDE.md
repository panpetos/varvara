# thousandli.ru — правила проекта

Онлайн-школа китайского языка «Тысяча ли» (заказчица — Варвара Тимофеева).
Статика: HTML + CSS (БЭМ, токены в `:root`) + ванильный JS. Без сборки.

## Деплой
- Любой пуш в `main` / `claude/**` → GitHub Actions заливает сайт по FTPS в две папки:
  - **прод** `www/thousandli.ru/` → https://thousandli.ru/ (индексация открыта);
  - **копия** `www/thousandli.ru/test/` → https://thousandli.ru/test/ (закрыта от поиска).
- Видео в git нет: на прод workflow копирует `founder-ru.mp4` и постер из `test/video/` в `video/`.
- Пароли — только в GitHub Secrets и `deploy/.env` (в .gitignore). Не коммитить.

## Формы и капча
- Все формы (заявка, квиз) → `send.php` (mail на LEAD_TO, honeypot `website`).
- Яндекс SmartCaptcha «Я не робот»: ключи в GitHub Secrets `SMARTCAPTCHA_CLIENT_KEY` и `SMARTCAPTCHA_SERVER_KEY`.
  Деплой подставляет клиентский ключ в `CAPTCHA_SITEKEY` (js/main.js) и пишет `captcha-secret.php` (в git его нет).
  Нет ключей — капча выключена. Контейнер в разметке — `<div class="captcha" data-captcha hidden>`.
- Квиз: на главной — «Подобрать программу»; на страницах программ (`@include quiz program="…"`, `steps summary="1"`) —
  итоговый «Подведём итог»: сводка ответов + подсказка, если по ответам лучше подходит другая программа.

## Картинки — всегда WebP
- Все растровые картинки (фото, текстуры) — только `.webp` (cwebp / Pillow, q≈80–85),
  с реальными `width`/`height`. Ниже первого экрана — `loading="lazy" decoding="async"`.
- Иконки и декор с контуром — SVG.
- Исключения, где формат диктует платформа: `img/og-image.jpg` (соцсети),
  `favicon.ico`, `apple-touch-icon.png`, `img/icons/*.png` (manifest).

## Видео
- Исходники лежат на хостинге в `test/video/`. Сжатие — workflow **Optimize video on hosting**
  (ручной запуск: `src` = имя исходника, `dst` = латинское имя). Результат `<dst>.mp4` + `<dst>-poster.webp`.

## SEO
- Сайт готов к индексации: title/description, canonical, OG/Twitter, JSON-LD
  (EducationalOrganization, WebSite, FAQPage), `robots.txt`, `sitemap.xml`, manifest, иконки.
- **Копия /test закрыта** от поиска: `<meta name="robots" … data-env="test">` в страницах
  и блок `test-noindex` в `.htaccess` (X-Robots-Tag). При деплое на прод deploy.yml
  удаляет их автоматически — руками ничего делать не нужно.
- Меняешь FAQ на странице — обнови JSON-LD FAQPage в `<head>`. Меняешь содержание — обнови `lastmod` в sitemap.xml.

## Страницы и сборка
- HTML правится в `src/pages/*.html` и `src/partials/*.html`, затем `python3 tools/build.py`
  собирает страницы в корень (index.html, kids.html…). Собранные файлы руками не править.
- Страницы программ: `kids.html` — вручную (`src/pages/kids.html`), остальные 10 (adults, business,
  politics, tech, other, hsk, hskk, yct, bct, csca) генерирует `tools/programs.py` из данных →
  `src/pages/<slug>.html`. Правка текстов: данные в programs.py → `python3 tools/programs.py && python3 tools/build.py`.
  Меняются только блоки 1–2 и результаты, остальное — общие фрагменты. Картинка первого экрана — `img/pages/<slug>/hero.webp`: превью из карточки программы в Figma (квадрат 720 px, рендер узла карточки ×5); у kids — своя из макета шаблона.
  Новая программа: добавить в PROGRAMS, картинку, ссылку с карточки на главной, строку в sitemap.xml.
- Страница «Об основателе» — `src/pages/founder.html`: история (текст №1 заказчицы) с выделенными словами —
  наведение увеличивает, нажатие открывает фото справа (на телефоне — окном), подписи из ТЗ.
- Иероглифы в шрифте Hanzi — подмножество Noto Sans SC: добавили новые иероглифы на любую страницу — пересобрать подмножество.
- Иконки «Наш подход» подбирать по смыслу текста карточки из наборов Iconify
  (npm `@iconify-json/*`), сохранять SVG в `img/icons/approach/` нужного цвета; список для страниц программ — `tools/icons.json`.

## Вёрстка
- Десктоп 1440 из Figma (файл `7x4TqNo1O1ycA3zA1S0kCO`, фрейм `287:15951`) — попиксельно.
- Мобильная 393 (фрейм `382:1925`): `@media (max-width: 1023.98px)`, колонка `--col` до 480 px.
  Декор внутри карточек — в единицах `--u` (1px макета от ширины карточки, `container-type: inline-size`).
  Мобильные картинки — `img/m/`, подключаются через `<picture><source media="(max-width: 1023px)">`.
- Планшет 600–1023 (макета нет, сделан по логике): слой `@media (min-width: 600px)` поверх мобильной
  версии — две колонки. Целевое устройство заказчицы — Galaxy Z Fold: внешний экран ~344 px (мобилка),
  раскрытый ~690×840 и горизонтально ~840 (планшет). Проверять 344 / 690 / 841 / 768.
- Мобильное меню — панель справа (`.drawer`, макет `593:313`).
- 1024–1439: десктоп масштабируется целиком (`zoom`, `--page-zoom` из JS).
- Скругления только токенами: `--r-xl` 40, `--r-md` 20, `--r-frame` 16, `--r-card` 13, `--r-sm` 10, `--r-pill`.
- Анимации спокойные, с учётом `prefers-reduced-motion`.

## Осталось сделать (ждём от заказчицы)
- Данные самозанятости — для реквизитов в подвале и документов.
- Баннер cookie (принять / отклонить) + Яндекс Метрика только после согласия (закон о cookie / 152-ФЗ).
- Документы: политика обработки ПДн, согласие на обработку, политика cookie, оферта — ссылки в формах и подвале.
- Реальные ссылки на соцсети.
- Финальный логотип (текущий — не окончательный).
