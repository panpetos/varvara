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

### Автоматический (основной) — GitHub Actions по FTP

При каждом пуше в ветку `main` или `claude/**` workflow
`.github/workflows/deploy.yml` сам заливает сайт на хостинг по FTP(S).
Ничего вручную делать не нужно.

**Одноразовая настройка секретов** в репозитории
(`Settings → Secrets and variables → Actions`):

| Secret          | Значение                     |
| --------------- | ---------------------------- |
| `FTP_SERVER`    | `31.31.198.27`               |
| `FTP_USERNAME`  | `u3661467`                   |
| `FTP_PASSWORD`  | *(FTP-пароль хостинга)*      |

Переменная (вкладка **Variables**, необязательно):

| Variable          | Значение по умолчанию   |
| ----------------- | ----------------------- |
| `FTP_SERVER_DIR`  | `www/thousandli.ru/`    |

Если Рег.ру не примет FTPS — в `deploy.yml` поменять `protocol: ftps` на `ftp`.
Если файлы зальются не в ту папку — поправить переменную `FTP_SERVER_DIR`.

### Резервный — по SSH с локальной машины (rsync)

```bash
cp deploy/.env.example deploy/.env   # вписать SSH_PASSWORD и REMOTE_DIR
bash deploy/deploy.sh
```

Или вручную через файловый менеджер ISPmanager / FTP.

## Разработка

Вёрстка макета делается из Figma по скиллу `figma-to-static-regru`
(десктоп + мобилка, адаптив, БЭМ, токены в `:root`).
