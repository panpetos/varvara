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

## Вёрстка
- Десктоп 1440 из Figma (файл `7x4TqNo1O1ycA3zA1S0kCO`, фрейм `287:15951`) — попиксельно.
  Мобильная и планшетная версии — по макетам, когда появятся.
- Анимации спокойные, с учётом `prefers-reduced-motion`.
