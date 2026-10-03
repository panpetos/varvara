# thousandli.ru — правила проекта

Онлайн-школа китайского языка «Тысяча ли» (заказчица — Варвара Тимофеева).
Статика: HTML + CSS (БЭМ, токены в `:root`) + ванильный JS. Без сборки.

## Деплой
- Любой пуш в `main` / `claude/**` → GitHub Actions заливает сайт по FTPS в
  `www/thousandli.ru/test/` → https://thousandli.ru/test/ (заказчик пока не видит).
- Выкладка на главную: переменная репозитория `FTP_SERVER_DIR=www/thousandli.ru/`.
- Пароли — только в GitHub Secrets и `deploy/.env` (в .gitignore). Не коммитить.

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
- **Тестовая копия закрыта** от поиска: `<meta name="robots" … data-env="test">` в index.html
  и блок `test-noindex` в `.htaccess` (X-Robots-Tag). При деплое в корень (не `/test/`)
  deploy.yml удаляет их автоматически — руками ничего делать не нужно.
- Меняешь FAQ на странице — обнови JSON-LD FAQPage в `<head>`. Меняешь содержание — обнови `lastmod` в sitemap.xml.

## Страницы и сборка
- HTML правится в `src/pages/*.html` и `src/partials/*.html`, затем `python3 tools/build.py`
  собирает страницы в корень (index.html, kids.html…). Собранные файлы руками не править.
- Шаблонная страница программы — `src/pages/kids.html`: меняются только блоки 1–2
  (крошки + первый экран, «Наш подход»), остальное — общие фрагменты.
  Новая программа: скопировать kids.html, поменять тексты/картинки (`img/pages/<имя>/`), добавить в sitemap.xml.
- Иконки «Наш подход» подбирать по смыслу текста карточки из наборов Iconify
  (npm `@iconify-json/*`), сохранять SVG в `img/icons/approach/` нужного цвета.

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
