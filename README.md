# thousandli.ru — проект Варвары

Статический сайт (чистый HTML/CSS/JS) под shared-хостинг **Рег.ру** (Host-0, ISPmanager, Apache).

## Стек и структура

```
index.html
css/style.css
js/main.js
img/            — ассеты (svg, png/jpg/webp)
fonts/          — woff2 локально
.htaccess       — HTTPS, www→без www, gzip, кэш
deploy/         — деплой на хостинг
```

- Только чистый HTML5/CSS3/ES6+. Без фреймворков, сборщиков и внешних CDN.
- Все пути относительные. Шрифты локально (`@font-face`, `font-display: swap`).

## Хостинг (Рег.ру, Host-0)

- Панель: **ISPmanager** — `https://server237.hosting.reg.ru:1500/`
- Сервер (IP): `31.31.198.27`
- SSH/SFTP/панель логин: `u3661467`
- Домен: **thousandli.ru**
- Корень сайта на сервере: обычно `~/www/thousandli.ru/` (уточнить в файловом менеджере ISPmanager).
- MySQL: база `u3661467_default`, host `localhost`.

> Пароли в репозиторий **не коммитятся**. Держим их в `deploy/.env` (файл в `.gitignore`).

## Деплой

Вариант 1 — по SSH с локальной машины (rsync):

```bash
cp deploy/.env.example deploy/.env   # вписать SSH_PASSWORD и REMOTE_DIR
bash deploy/deploy.sh
```

Вариант 2 — вручную: загрузить содержимое проекта (кроме `.git`, `deploy/`, `README.md`)
в корневую папку домена через файловый менеджер ISPmanager или по FTP.

## Разработка

Вёрстка макета делается из Figma по скиллу `figma-to-static-regru`
(десктоп + мобилка, адаптив, БЭМ, токены в `:root`).
