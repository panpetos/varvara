<?php
/**
 * Админка thousandli.ru: заявки с сайта и правка текстов страниц на трёх языках (русский, английский, китайский).
 *
 * Тексты правятся прямо в готовых .html (меняется только текст между тегами). Список правок хранится
 * в data/content.php (ключ — страница: index, en/index, zh/index…); после каждого деплоя workflow вызывает
 * admin.php?reapply=1 и правки возвращаются.
 * Какой русский текст какому переводу соответствует и в каком блоке страницы он стоит — admin-texts.php
 * (собирает tools/i18n.py при сборке сайта). Без него админка всё равно работает: показывает тексты списком.
 * Данные (заявки, пароль, правки) лежат в data/*.php — снаружи эти файлы отдают 404, в git их нет.
 * Совместимо с PHP 7.4+.
 */
declare(strict_types=1);

// Одноразовый код первой настройки (хранится только хэш). Нужен, пока не задан пароль админки.
const SETUP_CODE_SHA256 = '1fdc6cdf702690de17ddc51be878a29651718528db354e9d53fabefcf371c1ae';

// Названия известных страниц. Список страниц в админке строится по факту — по файлам *.html в корне сайта
// (см. pages()), поэтому новая страница появляется в «Текстах» сама, без правки этого файла.
const PAGE_TITLES = [
    'index'    => 'Главная',
    'kids'     => 'Дети',
    'adults'   => 'Взрослые',
    'business' => 'Деловой китайский',
    'politics' => 'Политический китайский',
    'tech'     => 'Технический китайский',
    'other'    => 'Другое направление',
    'hsk'      => 'HSK',
    'hskk'     => 'HSKK',
    'yct'      => 'YCT',
    'bct'      => 'BCT',
    'csca'     => 'CSCA',
    'founder'  => 'Об основателе',
    'privacy'  => 'Политика конфиденциальности',
    'consent'  => 'Согласие на обработку ПДн',
    'cookies'  => 'Политика cookie',
    '404'      => 'Страница 404',
];

// Разделы меню слева (страницы, которых тут нет, попадают в «Другие страницы»)
const PAGE_GROUPS = [
    'Главная'    => ['index'],
    'Программы'  => ['kids', 'adults', 'business', 'politics', 'tech', 'other'],
    'Экзамены'   => ['hsk', 'hskk', 'yct', 'bct', 'csca'],
    'О школе'    => ['founder'],
    'Документы'  => ['privacy', 'consent', 'cookies', '404'],
];

const PAGE_ICONS = [
    'index' => '🏠', 'kids' => '🧒', 'adults' => '🧑', 'business' => '💼', 'politics' => '🏛️', 'tech' => '⚙️',
    'other' => '🧭', 'hsk' => '📝', 'hskk' => '🎙️', 'yct' => '🎒', 'bct' => '📊', 'csca' => '🎓', 'founder' => '👩‍🏫',
    'privacy' => '📄', 'consent' => '📄', 'cookies' => '🍪', '404' => '🚧',
];

// Языки сайта: код => [короткая метка, название, папка на сайте]
const LANGS = [
    'ru' => ['RU', 'Русский', ''],
    'en' => ['EN', 'Английский', 'en/'],
    'zh' => ['中文', 'Китайский', 'zh/'],
];

const DATA_DIR = __DIR__ . '/data';
const GUARD    = "<?php http_response_code(404); exit; ?>\n";

// ---------- хранилище ----------
function data_path(string $name): string { return DATA_DIR . '/' . $name . '.php'; }

function guarded_raw(string $file): string {
    if (!is_file($file)) return '';
    $s = (string)file_get_contents($file);
    $g = rtrim(GUARD);  // первая строка-заглушка; после неё может быть \n или \r\n
    return strncmp($s, $g, strlen($g)) === 0 ? ltrim(substr($s, strlen($g)), "\r\n") : $s;
}

function data_raw(string $name): string { return guarded_raw(data_path($name)); }

function data_read(string $name): array {
    $j = json_decode(data_raw($name), true);
    return is_array($j) ? $j : [];
}

function data_write(string $name, string $body): void {
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0755, true);
    file_put_contents(data_path($name), GUARD . $body, LOCK_EX);
}

function data_write_json(string $name, array $v): void {
    data_write($name, json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// ---------- заявки ----------
// Сами заявки send.php дописывает строками JSON в data/leads.php. Статус, заметка и «в корзине» —
// отдельно, в data/lead_marks.php (ключ — id заявки), чтобы не переписывать файл, в который пишет сайт.
const LEAD_STATUSES = [  // код => [значок, название, цвет метки]
    'new'    => ['🆕', 'Новая', '#d3e5ef'],
    'work'   => ['📞', 'В работе', '#fdecc8'],
    'client' => ['✅', 'Записался', '#dbeddb'],
    'off'    => ['💤', 'Не актуальна', '#eeeeec'],
    'spam'   => ['🚫', 'Спам', '#ffe2dd'],
    'test'   => ['🧪', 'Тест', '#e8deee'],
];

function lead_id(string $line): string { return substr(md5(trim($line)), 0, 12); }

/** Все заявки (старые первыми) + служебные поля: _id, _s (статус), _note, _del (в корзине). */
function leads_all(): array {
    $marks = data_read('lead_marks');
    $out = [];
    foreach (explode("\n", data_raw('leads')) as $line) {
        $j = json_decode(trim($line), true);
        if (!is_array($j)) continue;
        $id = lead_id($line);
        $m = is_array($marks[$id] ?? null) ? $marks[$id] : [];
        $s = (string)($m['s'] ?? 'new');
        $j['_id']   = $id;
        $j['_s']    = isset(LEAD_STATUSES[$s]) ? $s : 'new';
        $j['_note'] = (string)($m['note'] ?? '');
        $j['_del']  = !empty($m['del']);
        $out[] = $j;
    }
    return $out;
}

/** Удалить заявки навсегда (из data/leads.php). Файл держим под замком: сайт в это время может дописать новую. */
function leads_purge(array $ids): int {
    $f = data_path('leads');
    if (!$ids || !is_file($f) || !($fh = fopen($f, 'c+'))) return 0;
    flock($fh, LOCK_EX);
    $keep = [];
    $n = 0;
    foreach (explode("\n", (string)stream_get_contents($fh)) as $line) {
        if (is_array(json_decode(trim($line), true)) && in_array(lead_id($line), $ids, true)) { $n++; continue; }
        $keep[] = $line;
    }
    if ($n) { ftruncate($fh, 0); rewind($fh); fwrite($fh, implode("\n", $keep)); fflush($fh); }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $n;
}

// ---------- правка текстов в .html ----------
// Разбор на «служебные» куски (script, style, svg, head, комментарии — не трогаем) и обычную разметку.
function html_parts(string $html): array {
    return preg_split(
        '~(<script\b.*?</script>|<style\b.*?</style>|<svg\b.*?</svg>|<head\b.*?</head>|<noscript\b.*?</noscript>|<!--.*?-->)~is',
        $html, -1, PREG_SPLIT_DELIM_CAPTURE
    ) ?: [$html];
}

/** Файл страницы: «index» → index.html, «en/index» → en/index.html. */
function page_file(string $slug): string { return __DIR__ . '/' . $slug . '.html'; }

/** Страницы сайта: slug => название. Все *.html в корне; известные — по PAGE_TITLES, новые — по <h1>/<title>. */
function pages(): array {
    static $list = null;
    if ($list !== null) return $list;
    $found = [];
    foreach (glob(__DIR__ . '/*.html') ?: [] as $f) {
        $slug = basename($f, '.html');
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $slug)) continue;
        $found[$slug] = $f;
    }
    $list = [];
    foreach (PAGE_TITLES as $slug => $title) {
        if (isset($found[$slug])) { $list[$slug] = $title; unset($found[$slug]); }
    }
    ksort($found);
    foreach ($found as $slug => $f) {
        $head = (string)file_get_contents($f);
        $t = (string)$slug;
        if (preg_match('~<h1[^>]*>(.*?)</h1>~is', $head, $m) || preg_match('~<title>(.*?)</title>~is', $head, $m)) {
            $t = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: (string)$slug;
        }
        $list[$slug] = mb_substr($t, 0, 80);
    }
    return $list;
}

/** Страница на языке: («en», «index») → «en/index»; для русского — просто «index». */
function lslug(string $lang, string $slug): string { return $lang === 'ru' ? $slug : $lang . '/' . $slug; }

/** «en/index» → [«en», «index»]; «index» → [«ru», «index»]. */
function split_lslug(string $ls): array {
    return preg_match('~^(en|zh)/(.+)$~', $ls, $m) ? [$m[1], $m[2]] : ['ru', $ls];
}

function valid_lslug(string $ls): bool {
    [, $slug] = split_lslug($ls);
    return isset(pages()[$slug]) && is_file(page_file($ls));
}

/** Языки, на которых есть эта страница. */
function page_langs(string $slug): array {
    $out = [];
    foreach (LANGS as $lang => $_) if (is_file(page_file(lslug($lang, $slug)))) $out[] = $lang;
    return $out;
}

/** Все страницы одного языка (для «заменить на всех страницах»). */
function lang_slugs(string $lang): array {
    $out = [];
    foreach (array_keys(pages()) as $slug) {
        $ls = lslug($lang, (string)$slug);
        if (is_file(page_file($ls))) $out[] = $ls;
    }
    return $out;
}

/** Ссылка на страницу на сайте. */
function page_url(string $lang, string $slug): string {
    return '/' . LANGS[$lang][2] . ($slug === 'index' ? '' : $slug . '.html');
}

/** Видимые текстовые фрагменты страницы (как в файле, без обрамляющих пробелов), без повторов. */
function fragments(string $html): array {
    $out = [];
    foreach (html_parts($html) as $i => $part) {
        if ($i % 2) continue;
        if (!preg_match_all('~>([^<>]+)<~u', '>' . $part . '<', $m)) continue;
        foreach ($m[1] as $raw) {
            $raw = trim($raw);
            // буквы или цифры (цены тоже можно менять)
            if ($raw === '' || !preg_match('/[\p{L}\p{N}]/u', $raw)) continue;
            if (mb_strlen(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')) < 2) continue;
            $out[$raw] = true;
        }
    }
    return array_map('strval', array_keys($out));
}

/** Заменяет фрагмент $from на $to (только целые текстовые узлы). Возвращает [html, число замен]. */
function replace_fragment(string $html, string $from, string $to): array {
    $re = '~(>\s*)' . preg_quote($from, '~') . '(\s*<)~u';
    $count = 0;
    $parts = html_parts($html);
    foreach ($parts as $i => $part) {
        if ($i % 2) continue;
        $parts[$i] = preg_replace_callback($re, static function ($m) use ($to) { return $m[1] . $to . $m[2]; }, $part, -1, $c) ?? $part;
        $count += (int)$c;
    }
    return [implode('', $parts), $count];
}

function show_text(string $raw): string {
    $t = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string)preg_replace('/\s+/u', ' ', $t));
}

function to_raw(string $plain): string {
    $raw = htmlspecialchars(trim((string)preg_replace('/\s+/u', ' ', $plain)), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    // цены не переносим посередине: «5 000 ₽» → неразрывные пробелы между разрядами и перед ₽
    return (string)preg_replace('/(?<=\d) (?=\d{3}(?!\d))|(?<=\d) (?=₽)/u', '&nbsp;', $raw);
}

/** Карта текстов из admin-texts.php: [slug => ['secs' => [...], 'rows' => [...]]]. */
function text_map(): array {
    static $map = null;
    if ($map === null) {
        $j = json_decode(guarded_raw(__DIR__ . '/admin-texts.php'), true);
        $map = is_array($j) ? $j : [];
    }
    return $map;
}

/**
 * Записать одну правку: на странице $ls текст $cur → $new (если $everywhere — на всех страницах того же языка).
 * Возвращает 1, если на самой странице $ls текст поменялся.
 */
function save_edit(array &$content, string $ls, string $cur, string $new, bool $everywhere): int {
    if ($cur === $new) return 0;
    [$lang] = split_lslug($ls);
    // исходный текст: если фрагмент уже правили — берём оригинал из списка правок
    $orig = $cur;
    foreach ($content[$ls] ?? [] as $p) {
        if (($p['n'] ?? null) === $cur) { $orig = (string)$p['o']; break; }
    }
    $done = 0;
    foreach ($everywhere ? lang_slugs($lang) : [$ls] as $s) {
        $file = page_file($s);
        if (!is_file($file)) continue;
        [$out, $n] = replace_fragment((string)file_get_contents($file), $cur, $new);
        if ($n === 0) continue;
        file_put_contents($file, $out, LOCK_EX);
        $list = array_values(array_filter(
            $content[$s] ?? [],
            static function ($p) use ($cur, $orig) { return $p['n'] !== $cur && $p['o'] !== $orig; }
        ));
        if ($new !== $orig) $list[] = ['o' => $orig, 'n' => $new];
        if ($list) $content[$s] = $list; else unset($content[$s]);
        if ($s === $ls) $done = 1;
    }
    return $done;
}

/** Возвращает правки, которые снова нужно внести в файлы (после деплоя страница снова «исходная»). */
function reapply_all(): int {
    $changed = 0;
    $content = data_read('content');
    $stale   = [];
    foreach ($content as $slug => $pairs) {
        $slug = (string)$slug;
        if (!valid_lslug($slug) || !is_array($pairs)) continue;
        $file = page_file($slug);
        $html = $orig = (string)file_get_contents($file);
        $keep = [];
        $frags = null;
        foreach ($pairs as $p) {
            if (!isset($p['o'], $p['n'])) continue;
            [$html, $n] = replace_fragment($html, (string)$p['o'], (string)$p['n']);
            if ($n > 0) $frags = null;
            // ни исходного, ни правленого текста на странице нет — текст в исходниках поменяли, правка устарела
            if ($n === 0 && !in_array((string)$p['n'], $frags ??= fragments($html), true)) {
                $stale[$slug][] = $p;
                continue;
            }
            $keep[] = $p;
        }
        if ($html !== $orig && file_put_contents($file, $html, LOCK_EX) !== false) $changed++;
        if (count($keep) !== count($pairs)) {
            if ($keep) $content[$slug] = $keep; else unset($content[$slug]);
        }
    }
    if ($stale) {
        // устаревшие правки не теряем, а переносим в архив: админка покажет их на странице, чтобы внести заново
        $arch = data_read('content_stale');
        foreach ($stale as $slug => $list) {
            foreach ($list as $p) {
                $arch[$slug] = array_values(array_filter($arch[$slug] ?? [], static function ($a) use ($p) { return ($a['n'] ?? '') !== $p['n']; }));
                $arch[$slug][] = ['o' => $p['o'], 'n' => $p['n'], 'at' => date('Y-m-d H:i')];
            }
        }
        data_write_json('content_stale', $arch);
        data_write_json('content', $content);
    }
    return $changed;
}

// ---------- сессия и защита ----------
function is_https(): bool {
    return (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function too_many_fails(): bool {
    $f = array_filter(data_read('fails'), static function ($t) { return is_int($t) && $t > time() - 900; });
    return count($f) >= 10;
}

function add_fail(): void {
    $f = array_values(array_filter(data_read('fails'), static function ($t) { return is_int($t) && $t > time() - 900; }));
    $f[] = time();
    data_write_json('fails', $f);
}

// Публичный вызов после деплоя: только возвращает уже сохранённые правки, ничего нового не принимает
if (isset($_GET['reapply'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ok ' . reapply_all();
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

session_name('vtadm');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']);
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['csrf'];

$auth    = data_read('auth');
$isPost  = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$msg     = '';
$err     = '';
$authed  = !empty($_SESSION['ok']) && $auth !== [];

// сообщение после сохранения (страница перезагружается, чтобы «Обновить» не отправляло форму ещё раз)
if (!empty($_SESSION['flash']) && is_array($_SESSION['flash'])) {
    [$msg, $err] = $_SESSION['flash'] + ['', ''];
    unset($_SESSION['flash']);
}

if ($isPost && !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    $isPost = false;
    $err = 'Сессия устарела — обновите страницу и повторите.';
}

$action = $isPost ? (string)($_POST['action'] ?? '') : '';

// --- первая настройка ---
if ($auth === [] && $action === 'setup') {
    $code  = (string)($_POST['code'] ?? '');
    $login = trim((string)($_POST['login'] ?? ''));
    $p1    = (string)($_POST['pass1'] ?? '');
    $p2    = (string)($_POST['pass2'] ?? '');
    if (too_many_fails()) {
        $err = 'Слишком много попыток. Подождите 15 минут.';
    } elseif (!hash_equals(SETUP_CODE_SHA256, hash('sha256', $code))) {
        add_fail();
        $err = 'Неверный код настройки.';
    } elseif ($login === '' || strlen($p1) < 8 || $p1 !== $p2) {
        $err = 'Нужны логин и пароль не короче 8 символов (два раза одинаково).';
    } else {
        data_write_json('auth', ['login' => mb_strtolower($login), 'hash' => password_hash($p1, PASSWORD_DEFAULT)]);
        $auth = data_read('auth');
        $_SESSION['ok'] = true;
        session_regenerate_id(true);
        $authed = true;
        $msg = 'Пароль задан. Вы вошли в админку.';
    }
}

// --- вход / выход ---
if ($auth !== [] && $action === 'login') {
    $login = mb_strtolower(trim((string)($_POST['login'] ?? '')));
    $pass  = (string)($_POST['password'] ?? '');
    if (too_many_fails()) {
        $err = 'Слишком много неудачных попыток. Подождите 15 минут.';
    } elseif (hash_equals((string)$auth['login'], $login) && password_verify($pass, (string)$auth['hash'])) {
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
        $authed = true;
    } else {
        add_fail();
        $err = 'Неверный логин или пароль.';
    }
}
if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

$tab = (string)($_GET['tab'] ?? 'leads');
if (!in_array($tab, ['leads', 'texts', 'password', 'help'], true)) $tab = 'leads';

// ---------- действия внутри админки ----------
if ($authed && $isPost) {
    if ($action === 'password') {
        $cur = (string)($_POST['cur'] ?? '');
        $p1  = (string)($_POST['pass1'] ?? '');
        $p2  = (string)($_POST['pass2'] ?? '');
        $tab = 'password';
        if (!password_verify($cur, (string)$auth['hash'])) {
            $err = 'Текущий пароль указан неверно.';
        } elseif (strlen($p1) < 8 || $p1 !== $p2) {
            $err = 'Новый пароль — не короче 8 символов, два раза одинаково.';
        } else {
            data_write_json('auth', ['login' => $auth['login'], 'hash' => password_hash($p1, PASSWORD_DEFAULT)]);
            $msg = 'Пароль изменён.';
        }
    }

    if ($action === 'texts') {
        $slug = (string)($_POST['page'] ?? '');
        if (!isset(pages()[$slug])) {
            $_SESSION['flash'] = ['', 'Страница не найдена.'];
        } else {
            $content    = data_read('content');
            $everywhere = !empty($_POST['everywhere']);
            $revert     = (string)($_POST['revert'] ?? '');
            $langs      = page_langs($slug);
            // md5 текста → текст, как он сейчас в файле страницы (по языкам)
            $keys = [];
            $byKey = static function (string $lang) use (&$keys, $slug): array {
                if (!isset($keys[$lang])) {
                    $keys[$lang] = [];
                    foreach (fragments((string)file_get_contents(page_file(lslug($lang, $slug)))) as $raw) $keys[$lang][md5($raw)] = $raw;
                }
                return $keys[$lang];
            };
            $done = 0;

            if ($revert !== '' && strpos($revert, ':') !== false) {
                // «Вернуть как было»: ru:ключ, en:ключ, zh:ключ или all:ключ (текст, общий для всех языков)
                [$rl, $key] = explode(':', $revert, 2);
                foreach ($rl === 'all' ? $langs : array_intersect([$rl], $langs) as $lang) {
                    $ls  = lslug($lang, $slug);
                    $cur = $byKey($lang)[$key] ?? null;
                    if ($cur === null) continue;
                    foreach ($content[$ls] ?? [] as $p) {
                        if ($p['n'] === $cur) { $done += save_edit($content, $ls, $cur, (string)$p['o'], false); break; }
                    }
                }
                $msgOk = 'Текст возвращён как был.';
            } else {
                $jobs = [];  // [язык, текущий текст, новый текст]
                foreach (is_array($_POST['t'] ?? null) ? $_POST['t'] : [] as $lang => $fields) {
                    $lang = (string)$lang;
                    if (!in_array($lang, $langs, true) || !is_array($fields)) continue;
                    foreach ($fields as $key => $plain) {
                        $cur = $byKey($lang)[(string)$key] ?? null;
                        if ($cur === null || !is_string($plain)) continue;
                        $jobs[] = [$lang, $cur, $plain, 't' . $lang . $key];
                    }
                }
                // текст без перевода (цифры, названия) — один на все языки
                foreach (is_array($_POST['s'] ?? null) ? $_POST['s'] : [] as $key => $plain) {
                    if (!is_string($plain)) continue;
                    foreach ($langs as $lang) {
                        $cur = $byKey($lang)[(string)$key] ?? null;
                        if ($cur !== null) $jobs[] = [$lang, $cur, $plain, 's' . $key];
                    }
                }
                $empty = 0;
                $fields = [];  // поле формы → сохранено (текст «на всех языках» — одно поле, хоть и три страницы)
                foreach ($jobs as [$lang, $cur, $plain, $field]) {
                    $plain = trim((string)preg_replace('/\s+/u', ' ', $plain));
                    if ($plain === '') { $empty++; continue; }
                    if ($plain === show_text($cur)) continue;
                    if (save_edit($content, lslug($lang, $slug), $cur, to_raw($plain), $everywhere)) $fields[$field] = true;
                }
                $done = count($fields);
                $msgOk = "Сохранено правок: {$done}. Изменения уже на сайте.";
                if ($empty) $msgOk .= ' Пустые поля пропущены: текст нельзя удалить целиком — напишите хотя бы одно слово.';
            }
            data_write_json('content', $content);
            $_SESSION['flash'] = [$done ? $msgOk : 'Изменений нет.', ''];
        }
        header('Location: admin.php?tab=texts&p=' . rawurlencode($slug));
        exit;
    }

    if ($action === 'lead') {
        // op: status (value — код статуса), note (value — текст), del (в корзину), restore, purge (навсегда), purge_trash
        $op    = (string)($_POST['op'] ?? '');
        $id    = (string)($_POST['id'] ?? '');
        $value = (string)($_POST['value'] ?? '');
        $back  = (string)($_POST['f'] ?? '');
        $marks = data_read('lead_marks');
        $known = [];
        foreach (leads_all() as $l) $known[$l['_id']] = $l;
        $flash = '';
        if ($op === 'purge_trash') {
            $ids = [];
            foreach ($known as $k => $l) if ($l['_del']) $ids[] = (string)$k;
            $n = leads_purge($ids);
            foreach ($ids as $k) unset($marks[$k]);
            $flash = 'Корзина очищена, удалено заявок: ' . $n . '.';
        } elseif (isset($known[$id])) {
            $m = is_array($marks[$id] ?? null) ? $marks[$id] : [];
            if ($op === 'status' && isset(LEAD_STATUSES[$value])) {
                $m['s'] = $value;
                $flash = 'Статус: ' . LEAD_STATUSES[$value][1] . '.';
            } elseif ($op === 'note') {
                $m['note'] = mb_substr(trim((string)preg_replace('/\s+/u', ' ', $value)), 0, 500);
                $flash = 'Заметка сохранена.';
            } elseif ($op === 'del') {
                $m['del'] = date('c');
                $flash = 'Заявка в корзине. Вернуть — раздел «Корзина».';
            } elseif ($op === 'restore') {
                unset($m['del']);
                $flash = 'Заявка возвращена.';
            } elseif ($op === 'purge' && !empty($m['del'])) {
                leads_purge([$id]);
                $m = [];
                $flash = 'Заявка удалена навсегда.';
            }
            if ($m) $marks[$id] = $m; else unset($marks[$id]);
        } else {
            $flash = '';
            $_SESSION['flash'] = ['', 'Заявка не найдена — обновите страницу.'];
        }
        data_write_json('lead_marks', $marks);
        if ($flash !== '') $_SESSION['flash'] = [$flash, ''];
        header('Location: admin.php?tab=leads' . (isset(LEAD_STATUSES[$back]) || $back === 'trash' ? '&f=' . $back : ''));
        exit;
    }
}

// ---------- вывод ----------
function nav_link(string $href, string $icon, string $label, bool $on, string $extra = ''): string {
    return '<a class="nav__item' . ($on ? ' is-on' : '') . '" href="' . h($href) . '"><span class="nav__icon">' . $icon
        . '</span><span class="nav__label">' . h($label) . '</span>' . $extra . '</a>';
}

function layout_start(string $title, bool $authed, string $tab, string $csrf, string $slug = ''): void {
    ?><!doctype html>
<html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> — админка «Тысяча ли»</title>
<link rel="icon" href="/img/logo2.svg?v=2" type="image/svg+xml">
<link rel="icon" href="/favicon.ico?v=2" sizes="any">
<link rel="icon" href="/img/icons/favicon-32.png?v=2" type="image/png" sizes="32x32">
<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=2">
<style>
:root{--bg:#fff;--side:#f7f7f5;--text:#37352f;--muted:#787774;--line:#e9e9e7;--hover:rgba(55,53,47,.06);--accent:#004e34;--gold:#ce9351;
--yellow:#fbf3db;--yellow-line:#e9c46a;--blue:#2383e2;--red:#e03e3e;--radius:6px;
--font:ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,"Apple Color Emoji",Arial,sans-serif,"Segoe UI Emoji","Segoe UI Symbol"}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;font:15px/1.5 var(--font);color:var(--text);background:var(--bg)}
a{color:inherit}
button,input,select,textarea{font:inherit;color:inherit}
.app{display:flex;min-height:100vh}
/* ----- меню слева ----- */
.side{position:sticky;top:0;align-self:flex-start;width:260px;flex:none;height:100vh;overflow:auto;background:var(--side);border-right:1px solid var(--line);padding:10px 8px 20px}
.brand{display:flex;align-items:center;gap:10px;padding:8px 10px 14px;font-weight:600}
.brand__logo{width:26px;height:26px;border-radius:6px;background:var(--accent);color:#fff;display:grid;place-items:center;font-size:14px}
.brand small{display:block;font-weight:400;color:var(--muted);font-size:12px;line-height:1.2}
.nav__item{display:flex;align-items:center;gap:8px;min-height:30px;padding:4px 10px;border-radius:var(--radius);text-decoration:none;color:#5f5e5b;font-size:14px}
.nav__item:hover{background:var(--hover)}
.nav__item.is-on{background:rgba(55,53,47,.09);color:var(--text);font-weight:600}
.nav__icon{width:20px;text-align:center;flex:none}
.nav__label{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.nav__badge{font-size:11px;color:var(--muted);background:rgba(55,53,47,.08);border-radius:10px;padding:0 7px}
.nav__dot{width:7px;height:7px;border-radius:50%;background:var(--yellow-line);flex:none}
.nav__group{margin:14px 10px 4px;font-size:12px;color:var(--muted);font-weight:500}
.nav__sep{height:1px;background:var(--line);margin:10px 6px}
.side form{margin:0}
.side .nav__item--btn{width:100%;border:0;background:none;cursor:pointer;text-align:left}
/* ----- основная часть ----- */
.main{flex:1;min-width:0;padding:0 56px 140px}
.page{max-width:1180px;margin:0 auto}
.page--narrow{max-width:720px}
.top{position:sticky;top:0;z-index:5;background:#fff;display:flex;align-items:center;gap:10px;min-height:46px;margin:0 -56px;padding:6px 56px;border-bottom:1px solid transparent}
.top.is-stuck{border-bottom-color:var(--line)}
.crumbs{flex:1;min-width:0;color:var(--muted);font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.crumbs b{color:var(--text);font-weight:500}
.burger{display:none}
h1.title{font-size:36px;line-height:1.2;margin:34px 0 6px;font-weight:700;letter-spacing:-.01em}
.title__icon{display:block;font-size:56px;line-height:1;margin-bottom:10px}
.props{display:flex;flex-wrap:wrap;gap:6px 18px;color:var(--muted);font-size:14px;margin:12px 0 18px}
.props a{color:var(--muted);text-decoration:none;border-bottom:1px solid rgba(120,119,116,.4)}
.props a:hover{color:var(--text)}
.callout{display:flex;gap:12px;background:#f1f1ef;border-radius:var(--radius);padding:14px 16px;margin:14px 0;font-size:14.5px}
.callout--yellow{background:var(--yellow)}
.callout--green{background:#edf3ec}
.callout--red{background:#fdebec}
.callout__icon{font-size:20px;line-height:1.2}
.callout p{margin:0 0 6px}.callout p:last-child{margin:0}
.callout ul{margin:4px 0 0;padding-left:18px}
.btn{display:inline-flex;align-items:center;gap:6px;height:32px;padding:0 12px;border-radius:var(--radius);border:1px solid var(--line);background:#fff;cursor:pointer;text-decoration:none;font-size:14px;white-space:nowrap}
.btn:hover{background:#f7f7f5}
.btn--primary{background:var(--accent);border-color:var(--accent);color:#fff;font-weight:500}
.btn--primary:hover{background:#003826}
.btn--ghost{border-color:transparent;background:none;color:var(--muted)}
.btn--ghost:hover{background:var(--hover);color:var(--text)}
.btn--sm{height:26px;padding:0 8px;font-size:13px}
.input{width:100%;height:36px;padding:0 10px;border:1px solid var(--line);border-radius:var(--radius);background:#fff;outline:none}
.input:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(35,131,226,.15)}
.toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:50;background:#2f2f2f;color:#fff;padding:10px 16px;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.2);font-size:14px;max-width:min(560px,92vw);animation:toast 6s forwards}
.toast--err{background:var(--red)}
@keyframes toast{0%{opacity:0;transform:translate(-50%,8px)}6%,85%{opacity:1;transform:translate(-50%,0)}100%{opacity:0;visibility:hidden}}
/* ----- тексты ----- */
.tools{position:sticky;top:46px;z-index:4;background:#fff;display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:8px 0;margin-bottom:6px;border-bottom:1px solid var(--line)}
.tools .search{flex:1 1 240px;position:relative}
.tools .search .input{padding-left:32px}
.tools .search::before{content:"🔍";position:absolute;left:10px;top:8px;font-size:13px;opacity:.6}
.chips{display:flex;gap:4px;background:#f1f1ef;border-radius:8px;padding:3px}
.chip{border:0;background:none;border-radius:6px;padding:3px 10px;cursor:pointer;font-size:13px;color:var(--muted)}
.chip[aria-pressed="true"]{background:#fff;color:var(--text);box-shadow:0 1px 2px rgba(0,0,0,.12);font-weight:600}
.toggle{display:inline-flex;align-items:center;gap:6px;font-size:13.5px;color:var(--muted);cursor:pointer;user-select:none}
.toggle input{accent-color:var(--accent)}
.heads{display:flex;gap:12px;padding:10px 0 4px 26px;color:var(--muted);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.heads span,.cell{flex:1 1 0;min-width:0}
.sec{margin:6px 0 2px}
.sec>summary{list-style:none;display:flex;align-items:center;gap:6px;cursor:pointer;padding:8px 4px;border-radius:var(--radius);font-size:19px;font-weight:600;line-height:1.3}
.sec>summary::-webkit-details-marker{display:none}
.sec>summary:hover{background:var(--hover)}
.sec>summary::before{content:"";width:0;height:0;border-left:7px solid #9b9a97;border-top:5px solid transparent;border-bottom:5px solid transparent;transition:transform .15s;flex:none;margin:0 6px 0 4px}
.sec[open]>summary::before{transform:rotate(90deg)}
.sec__count{font-size:12px;font-weight:500;color:var(--muted);background:#f1f1ef;border-radius:10px;padding:1px 8px}
.sec__body{padding-left:22px}
.row{display:flex;gap:12px;padding:5px 4px;border-radius:var(--radius);position:relative}
.row+.row{border-top:1px solid #f1f1ef}
.row:hover{background:#fbfbfa}
.cell__lang{display:none;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin:2px 0 2px 8px}
.fld{position:relative;border-radius:var(--radius)}
.fld+.fld{margin-top:2px;border-top:1px dashed #ebebea;padding-top:2px}
.fld textarea{display:block;width:100%;min-height:34px;padding:6px 8px;border:1px solid transparent;border-radius:var(--radius);background:transparent;resize:none;overflow:hidden;line-height:1.45;field-sizing:content;outline:none;transition:background .12s,border-color .12s}
.fld textarea:hover{background:rgba(55,53,47,.04)}
.fld textarea:focus{background:#fff;border-color:var(--blue);box-shadow:0 0 0 3px rgba(35,131,226,.14)}
.fld.is-changed textarea{background:var(--yellow);box-shadow:inset 3px 0 0 var(--yellow-line)}
.fld.is-dirty textarea{background:#e7f3f8;box-shadow:inset 3px 0 0 var(--blue)}
.was{display:flex;flex-wrap:wrap;align-items:center;gap:4px 8px;margin:2px 0 4px 8px;font-size:12.5px;color:var(--muted)}
.was s{color:#9b9a97}
.row__hint{flex-basis:100%;margin:0 0 2px 8px;font-size:12.5px;color:#9f6b00}
.row--shared .cell--all .cell__lang{display:block}
.cell--empty{color:#b9b8b5;font-size:13px;padding:7px 9px}
.cell{position:relative}
.cell[data-lang="en"] textarea,.cell[data-lang="zh"] textarea{padding-right:30px}
.tr-hint{position:absolute;top:5px;right:4px;width:24px;height:24px;display:grid;place-items:center;border-radius:5px;font-size:13px;text-decoration:none;opacity:0;transition:opacity .15s}
.row:hover .tr-hint,.row:focus-within .tr-hint{opacity:.75}
.tr-hint:hover{opacity:1!important;background:var(--hover)}
.empty-note{padding:30px;text-align:center;color:var(--muted)}
body.hide-ru [data-lang="ru"],body.hide-en [data-lang="en"],body.hide-zh [data-lang="zh"]{display:none}
.savebar{position:fixed;left:50%;bottom:18px;transform:translate(-50%,140%);z-index:40;display:flex;align-items:center;flex-wrap:wrap;gap:10px 14px;background:#fff;border:1px solid var(--line);border-radius:10px;box-shadow:0 10px 38px rgba(15,15,15,.18);padding:10px 12px 10px 16px;max-width:calc(100vw - 24px);transition:transform .2s}
.savebar.is-on{transform:translate(-50%,0)}
.savebar__count{font-weight:600}
.kbd{font-size:11px;color:var(--muted);border:1px solid var(--line);border-radius:4px;padding:0 4px;margin-left:4px}
.btn--primary .kbd{color:#cfe3da;border-color:#3d7a63}
/* ----- заявки ----- */
.table-wrap{overflow:auto;border-top:1px solid var(--line)}
table.db{width:100%;border-collapse:collapse;font-size:14px}
table.db th{position:sticky;top:0;background:#fff;text-align:left;font-weight:500;color:var(--muted);font-size:13px;padding:8px 10px;border-bottom:1px solid var(--line);white-space:nowrap}
table.db td{padding:9px 10px;border-bottom:1px solid var(--line);vertical-align:top}
table.db tr:hover td{background:#fbfbfa}
.tag{display:inline-block;border-radius:4px;padding:0 6px;font-size:12.5px;line-height:20px;white-space:nowrap}
.tag--quiz{background:#e8deee}.tag--lead{background:#dbeddb}.tag--en{background:#d3e5ef}.tag--zh{background:#ffe2dd}.tag--ru{background:#eeeeec}
.lchips{display:inline-flex;flex-wrap:wrap;margin:0 0 10px}
.lchips .chip{text-decoration:none;white-space:nowrap}
.lsel{height:28px;border:0;border-radius:4px;padding:0 6px;font-size:13px;cursor:pointer}
.lnote__in{height:30px;min-width:150px;font-size:13px;border-color:transparent;background:transparent}
.lnote__in:hover{background:rgba(55,53,47,.04)}
tr.lead--off td,tr.lead--spam td,tr.lead--test td{color:#9b9a97}
tr.lead--off td b,tr.lead--spam td b,tr.lead--test td b{font-weight:500}
.muted{color:var(--muted)}
.small{font-size:13px}
/* ----- формы входа ----- */
.auth{max-width:380px;margin:12vh auto;padding:0 20px}
.auth h1{font-size:28px;margin:12px 0 18px}
.field{margin:0 0 12px}
.field label{display:block;font-size:13px;color:var(--muted);margin-bottom:4px}
.msg{background:#edf3ec;border-radius:var(--radius);padding:10px 14px;margin:14px 0}
.err{background:#fdebec;border-radius:var(--radius);padding:10px 14px;margin:14px 0}
/* ----- справка ----- */
.doc h2{font-size:22px;margin:30px 0 8px}
.doc h3{font-size:17px;margin:20px 0 6px}
.doc ol,.doc ul{padding-left:22px}
.doc li{margin:4px 0}
.doc code{background:rgba(135,131,120,.15);color:#eb5757;border-radius:4px;padding:1px 5px;font-size:90%}
/* ----- телефон и планшет ----- */
@media (max-width: 900px){
  .side{position:fixed;left:0;top:0;z-index:60;transform:translateX(-102%);transition:transform .2s;box-shadow:0 0 40px rgba(0,0,0,.2)}
  body.menu-open .side{transform:none}
  body.menu-open::after{content:"";position:fixed;inset:0;background:rgba(15,15,15,.35);z-index:55}
  .burger{display:inline-flex}
  .main{padding:0 16px 140px}
  .top{margin:0 -16px;padding:6px 16px}
  h1.title{font-size:28px;margin-top:20px}
  .title__icon{font-size:44px}
  .heads{display:none}
  .row{flex-direction:column;gap:2px;padding:8px 0}
  .cell__lang{display:block}
  .sec__body{padding-left:0}
  .sec>summary{font-size:17px}
  .tools{position:static}
  .kbd{display:none}
  .fld textarea{padding:5px 6px;min-height:30px}
  .cell__lang{margin-left:6px}
  .tr-hint{opacity:1}
  .savebar{left:12px;right:12px;transform:translateY(140%);bottom:12px}
  .savebar.is-on{transform:none}
  table.db thead{display:none}
  table.db,table.db tbody,table.db tr,table.db td{display:block;width:100%}
  table.db tr{border-bottom:1px solid var(--line);padding:8px 0}
  table.db td{border:0;padding:2px 0}
}
</style></head><body>
<?php if (!$authed): ?><main class="auth"><?php return; endif; ?>
<div class="app">
<aside class="side" id="side">
<div class="brand"><span class="brand__logo">千</span><span>Тысяча ли<small>админка сайта</small></span></div>
<?php
    $leadsCount = 0;  // новые, ещё не разобранные
    foreach (leads_all() as $l) if ($l['_s'] === 'new' && !$l['_del']) $leadsCount++;
    echo nav_link('?tab=leads', '📥', 'Заявки', $tab === 'leads', $leadsCount ? '<span class="nav__badge" title="Новые">' . $leadsCount . '</span>' : '');
    echo nav_link('?tab=help', '💡', 'Как пользоваться', $tab === 'help');
    echo nav_link('?tab=password', '🔑', 'Пароль', $tab === 'password');
    echo '<div class="nav__sep"></div><div class="nav__group" style="margin-top:4px">✏️ Тексты сайта</div>';
    $content = data_read('content');
    $all = pages();
    $groups = PAGE_GROUPS;
    $rest = array_diff(array_map('strval', array_keys($all)), array_merge(...array_values(PAGE_GROUPS)));
    if ($rest) $groups['Другие страницы'] = $rest;
    foreach ($groups as $gname => $slugs) {
        $slugs = array_values(array_filter($slugs, static function ($s) use ($all) { return isset($all[$s]); }));
        if (!$slugs) continue;
        echo '<div class="nav__group">' . h($gname) . '</div>';
        foreach ($slugs as $s) {
            $edited = false;
            foreach (LANGS as $l => $_) if (!empty($content[lslug($l, $s)])) $edited = true;
            echo nav_link('?tab=texts&p=' . rawurlencode($s), PAGE_ICONS[$s] ?? '📄', (string)$all[$s],
                $tab === 'texts' && $slug === $s, $edited ? '<span class="nav__dot" title="Есть ваши правки"></span>' : '');
        }
    }
    ?>
<div class="nav__sep"></div>
<form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
<button class="nav__item nav__item--btn" name="action" value="logout"><span class="nav__icon">🚪</span><span class="nav__label">Выйти</span></button></form>
</aside>
<div class="main">
<?php
}

function topbar(string $crumbs, string $right = ''): void {
    echo '<div class="top" id="top"><button class="btn btn--ghost burger" type="button" onclick="document.body.classList.toggle(\'menu-open\')" aria-label="Меню">☰</button>'
        . '<div class="crumbs">' . $crumbs . '</div>' . $right . '</div>';
}

function layout_end(string $msg = '', string $err = ''): void {
    if ($msg !== '') echo '<div class="toast" role="status">' . h($msg) . '</div>';
    if ($err !== '') echo '<div class="toast toast--err" role="alert">' . h($err) . '</div>';
    ?>
</div></div>
<script>
document.addEventListener('click', function (e) { if (document.body.classList.contains('menu-open') && !e.target.closest('.side') && !e.target.closest('.burger')) document.body.classList.remove('menu-open'); });
(function () { var t = document.getElementById('top'); if (!t) return; var f = function () { t.classList.toggle('is-stuck', window.scrollY > 4); }; window.addEventListener('scroll', f, {passive: true}); f(); })();
</script>
</body></html><?php
}

// --- не вошли ---
if (!$authed) {
    layout_start('Вход', false, '', $csrf);
    if ($msg !== '') echo '<div class="msg">' . h($msg) . '</div>';
    if ($err !== '') echo '<div class="err">' . h($err) . '</div>';
    if ($auth === []) {
        ?>
<div class="title__icon">🔐</div>
<h1>Первая настройка</h1>
<p class="muted">Введите код настройки (его выдал разработчик) и задайте логин и пароль для входа.</p>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="setup">
<div class="field"><label>Код настройки</label><input class="input" type="password" name="code" required></div>
<div class="field"><label>Логин (почта)</label><input class="input" type="email" name="login" required></div>
<div class="field"><label>Пароль (не короче 8 символов)</label><input class="input" type="password" name="pass1" required minlength="8"></div>
<div class="field"><label>Пароль ещё раз</label><input class="input" type="password" name="pass2" required minlength="8"></div>
<button class="btn btn--primary">Сохранить</button>
</form>
<?php
    } else {
        ?>
<div class="title__icon">千</div>
<h1>Админка «Тысяча ли»</h1>
<form method="post">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="login">
<div class="field"><label>Логин (почта)</label><input class="input" type="email" name="login" required autofocus autocomplete="username"></div>
<div class="field"><label>Пароль</label><input class="input" type="password" name="password" required autocomplete="current-password"></div>
<button class="btn btn--primary">Войти</button>
</form>
<?php
    }
    echo '</main></body></html>';
    exit;
}

// --- вошли ---
// после деплоя исходные страницы возвращаются — молча вносим правки заново
if ($tab === 'texts' && !$isPost) reapply_all();

if ($tab === 'leads') {
    $all = array_reverse(leads_all());
    $f = (string)($_GET['f'] ?? '');
    if (!isset(LEAD_STATUSES[$f]) && $f !== 'trash') $f = '';
    $counts = array_fill_keys(array_keys(LEAD_STATUSES), 0) + ['trash' => 0, '' => 0];
    foreach ($all as $l) {
        if ($l['_del']) { $counts['trash']++; continue; }
        $counts['']++;
        $counts[$l['_s']]++;
    }
    $leads = array_values(array_filter($all, static function (array $l) use ($f): bool {
        if ($f === 'trash') return $l['_del'];
        return !$l['_del'] && ($f === '' || $l['_s'] === $f);
    }));
    $kindName = static function (array $l): string {
        return ($l['kind'] ?? '') === 'quiz' ? 'Квиз' . (($l['program'] ?? '') !== '' ? ' · ' . $l['program'] : '') : 'Пробный урок';
    };
    $quizText = static function (array $l): string {
        $parts = [];
        foreach (($l['quiz'] ?? []) as $k => $v) $parts[] = $k . ': ' . $v;
        if (($l['result'] ?? '') !== '') $parts[] = 'Рекомендация: ' . $l['result'];
        return implode('; ', $parts);
    };
    $fmt = static function (string $iso): string {
        $t = strtotime($iso);
        return $t ? date('d.m.Y H:i', $t) : $iso;
    };
    $langOf = static function (array $l): string {
        $p = (string)($l['page'] ?? '');
        return strpos($p, '/en/') !== false ? 'en' : (strpos($p, '/zh/') !== false ? 'zh' : 'ru');
    };

    if (isset($_GET['csv'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="zayavki-thousandli.csv"');
        echo "\xEF\xBB\xBF";
        $o = fopen('php://output', 'w');
        fputcsv($o, ['Дата', 'Имя', 'Телефон', 'Статус', 'Заметка', 'Откуда', 'Ответы квиза', 'Страница'], ';');
        foreach ($leads as $l) {
            fputcsv($o, [$fmt((string)($l['ts'] ?? '')), $l['name'] ?? '', $l['phone'] ?? '', LEAD_STATUSES[$l['_s']][1],
                $l['_note'], $kindName($l), $quizText($l), $l['page'] ?? ''], ';');
        }
        exit;
    }

    layout_start('Заявки', true, 'leads', $csrf);
    $csvHref = '?tab=leads&amp;csv=1' . ($f !== '' ? '&amp;f=' . $f : '');
    topbar('<b>📥 Заявки</b>', $leads ? '<a class="btn btn--sm" href="' . $csvHref . '">⬇ Скачать таблицу (Excel)</a>' : '');
    $hidden = '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="lead"><input type="hidden" name="f" value="' . h($f) . '">';
    ?>
<div class="page">
<div class="title__icon" style="margin-top:34px">📥</div>
<h1 class="title" style="margin-top:0">Заявки с сайта</h1>
<div class="props"><span>Всего: <b><?= $counts[''] ?></b></span><span>Новые — сверху</span><span>Те же заявки приходят на почту school@thousandli.ru</span></div>
<div class="chips lchips">
<a class="chip" href="?tab=leads" aria-pressed="<?= $f === '' ? 'true' : 'false' ?>">Все <?= $counts[''] ?></a>
<?php foreach (LEAD_STATUSES as $code => [$ic, $nm]): if (!$counts[$code] && $f !== $code) continue; ?>
<a class="chip" href="?tab=leads&amp;f=<?= $code ?>" aria-pressed="<?= $f === $code ? 'true' : 'false' ?>"><?= $ic ?> <?= h($nm) ?> <?= $counts[$code] ?></a>
<?php endforeach; ?>
<?php if ($counts['trash'] || $f === 'trash'): ?><a class="chip" href="?tab=leads&amp;f=trash" aria-pressed="<?= $f === 'trash' ? 'true' : 'false' ?>">🗑 Корзина <?= $counts['trash'] ?></a><?php endif; ?>
</div>
<?php if ($f === 'trash' && $leads): ?>
<div class="callout"><span class="callout__icon">🗑</span><div><p>Здесь удалённые заявки. «↩ Вернуть» — заявка снова появится в списке. «Удалить навсегда» — восстановить уже нельзя.</p>
<form method="post" onsubmit="return confirm('Удалить все заявки из корзины навсегда?')"><?= $hidden ?><input type="hidden" name="op" value="purge_trash"><button class="btn btn--sm">Очистить корзину</button></form></div></div>
<?php endif; ?>
<?php if (!$leads): ?>
<div class="callout"><span class="callout__icon">📭</span><div><?= $counts[''] || $counts['trash'] ? 'Здесь пусто.' : 'Пока заявок нет. Здесь появятся заявки с формы и квиза — с русской, английской и китайской версий сайта.' ?></div></div>
<?php else: ?>
<div class="tools" style="top:46px"><div class="search"><input class="input" type="search" id="lq" placeholder="Найти по имени, телефону, ответам, заметке…"></div></div>
<div class="table-wrap"><table class="db"><thead><tr><th>Статус</th><th>Дата</th><th>Имя</th><th>Телефон</th><th>Откуда</th><th>Язык</th><th>Ответы квиза</th><th>Заметка</th><th></th></tr></thead><tbody id="lt">
<?php foreach (array_slice($leads, 0, 300) as $l):
    $digits = preg_replace('/\D/', '', (string)($l['phone'] ?? ''));
    $lang = $langOf($l);
    $id = h((string)$l['_id']); ?>
<tr class="lead lead--<?= $l['_s'] ?>" data-q="<?= h(mb_strtolower(implode(' ', [(string)($l['name'] ?? ''), (string)($l['phone'] ?? ''), $kindName($l), $quizText($l), $l['_note'], (string)($l['page'] ?? '')]))) ?>">
<td><form method="post" class="lst"><?= $hidden ?><input type="hidden" name="op" value="status"><input type="hidden" name="id" value="<?= $id ?>">
<select class="lsel" name="value" onchange="this.form.submit()" style="background:<?= LEAD_STATUSES[$l['_s']][2] ?>" aria-label="Статус заявки">
<?php foreach (LEAD_STATUSES as $code => [$ic, $nm]): ?><option value="<?= $code ?>"<?= $l['_s'] === $code ? ' selected' : '' ?>><?= $ic ?> <?= h($nm) ?></option><?php endforeach; ?>
</select><noscript><button class="btn btn--sm">OK</button></noscript></form></td>
<td class="muted small" style="white-space:nowrap"><?= h($fmt((string)($l['ts'] ?? ''))) ?></td>
<td><b><?= h((string)($l['name'] ?? '')) ?></b></td>
<td style="white-space:nowrap"><a href="tel:+<?= h((string)$digits) ?>"><?= h((string)($l['phone'] ?? '')) ?></a></td>
<td><span class="tag <?= ($l['kind'] ?? '') === 'quiz' ? 'tag--quiz' : 'tag--lead' ?>"><?= h($kindName($l)) ?></span><?php if (($l['page'] ?? '') !== ''): ?><div class="muted small"><?= h((string)$l['page']) ?></div><?php endif; ?></td>
<td><span class="tag tag--<?= $lang ?>"><?= h(LANGS[$lang][0]) ?></span></td>
<td class="muted small"><?= h($quizText($l)) ?></td>
<td><form method="post" class="lnote"><?= $hidden ?><input type="hidden" name="op" value="note"><input type="hidden" name="id" value="<?= $id ?>">
<input class="input lnote__in" name="value" value="<?= h($l['_note']) ?>" placeholder="Заметка…" maxlength="500" onchange="this.form.submit()"></form></td>
<td style="white-space:nowrap">
<?php if ($l['_del']): ?>
<form method="post" style="display:inline"><?= $hidden ?><input type="hidden" name="op" value="restore"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--sm">↩ Вернуть</button></form>
<form method="post" style="display:inline" onsubmit="return confirm('Удалить заявку навсегда? Вернуть её будет нельзя.')"><?= $hidden ?><input type="hidden" name="op" value="purge"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--sm btn--ghost" style="color:var(--red)">Удалить навсегда</button></form>
<?php else: ?>
<form method="post" onsubmit="return confirm('Удалить заявку? Она попадёт в корзину, оттуда её можно вернуть.')"><?= $hidden ?><input type="hidden" name="op" value="del"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--sm btn--ghost" title="Удалить (в корзину)" aria-label="Удалить">🗑</button></form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if (count($leads) > 300): ?><p class="muted">Показаны последние 300, остальные — в таблице Excel.</p><?php endif; ?>
<script>
document.getElementById('lq').addEventListener('input', function () {
  var v = this.value.toLowerCase().trim();
  document.querySelectorAll('#lt tr').forEach(function (tr) { tr.hidden = v !== '' && (tr.getAttribute('data-q') || '').indexOf(v) < 0; });
});
</script>
<?php endif; ?>
</div>
<?php
    layout_end($msg, $err);
    exit;
}

if ($tab === 'password') {
    layout_start('Пароль', true, 'password', $csrf);
    topbar('<b>🔑 Пароль</b>');
    ?>
<div class="page page--narrow">
<div class="title__icon" style="margin-top:34px">🔑</div>
<h1 class="title" style="margin-top:0">Сменить пароль</h1>
<?php if ($err !== ''): ?><div class="err"><?= h($err) ?></div><?php $err = ''; endif; ?>
<form method="post" autocomplete="off" style="max-width:380px;margin-top:18px">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="password">
<div class="field"><label>Текущий пароль</label><input class="input" type="password" name="cur" required></div>
<div class="field"><label>Новый пароль (не короче 8 символов)</label><input class="input" type="password" name="pass1" required minlength="8"></div>
<div class="field"><label>Новый пароль ещё раз</label><input class="input" type="password" name="pass2" required minlength="8"></div>
<button class="btn btn--primary">Сменить пароль</button>
</form>
</div>
<?php
    layout_end($msg, $err);
    exit;
}

if ($tab === 'help') {
    layout_start('Как пользоваться', true, 'help', $csrf);
    topbar('<b>💡 Как пользоваться</b>');
    ?>
<div class="page page--narrow doc">
<div class="title__icon" style="margin-top:34px">💡</div>
<h1 class="title" style="margin-top:0">Как пользоваться админкой</h1>
<div class="callout callout--green"><span class="callout__icon">🌐</span><div>
<p><b>Сайт на трёх языках: русский, английский, китайский.</b> Каждый язык — своя колонка. Поменяли русский текст — английский и китайский <b>сами не меняются</b>: поправьте их в соседних колонках той же строки.</p>
</div></div>

<h2>✏️ Как поменять текст</h2>
<ol>
<li>Слева выберите страницу: «Главная», программу или экзамен.</li>
<li>Тексты разложены по блокам — так же, как они идут на сайте: шапка, первый экран, стоимость, вопросы-ответы, подвал… Нажмите на название блока, чтобы свернуть или развернуть его.</li>
<li>Найдите нужную строку — или впишите пару слов в поиск сверху.</li>
<li>Нажмите на текст и исправьте его прямо в поле. Поле, которое вы поменяли, подсветится голубым, а внизу появится кнопка <b>«Сохранить»</b>.</li>
<li>Нажмите «Сохранить» (или <code>Ctrl + S</code>). Изменения сразу появятся на сайте.</li>
</ol>

<h2>🌐 Языки</h2>
<ul>
<li>В строке три колонки: <b>RU</b>, <b>EN</b>, <b>中文</b>. Это один и тот же текст на трёх версиях сайта.</li>
<li>Если поменяли русский, а перевод нет, под строкой появится подсказка «Русский текст изменён — проверьте перевод».</li>
<li>Наведите на английское или китайское поле — справа появится кнопка <b>🌐</b> «Подсказать перевод»: откроется Яндекс Переводчик с русским текстом этой строки. Проверьте перевод и вставьте его в поле.</li>
<li>Кнопки <b>RU / EN / 中文</b> сверху прячут и показывают колонки. Например, оставьте только RU и EN, если правите английскую версию.</li>
<li>Строка с пометкой <b>«Все языки»</b> — текст без перевода (цены, цифры, названия экзаменов, почта). Меняется сразу на всех трёх версиях.</li>
</ul>

<h2>↩ Если ошиблись</h2>
<ul>
<li>Пока не нажали «Сохранить» — кнопка <b>«Отменить»</b> внизу вернёт всё как было.</li>
<li>Уже сохранили — у изменённого текста жёлтый фон и надпись «было: …». Кнопка <b>«Вернуть как было»</b> вернёт исходный текст.</li>
<li>Галочка <b>«Только изменённые»</b> покажет все ваши правки на странице.</li>
</ul>

<h2>💡 Полезно знать</h2>
<ul>
<li>Меняются только слова. Картинки, ссылки, кнопки и оформление остаются на месте.</li>
<li>Галочка «Заменить и на других страницах» (внизу, рядом с «Сохранить») меняет такой же текст на всех страницах этого языка — удобно для меню и подвала.</li>
<li>Текст нельзя удалить целиком — оставьте хотя бы одно слово.</li>
<li>Жирный шрифт и переносы строк задаются оформлением. Поэтому одна фраза иногда разбита на несколько полей подряд: каждое поле — свой кусочек.</li>
<li>После обновления сайта разработчиком ваши правки сохраняются. Если какую-то правку не удалось вернуть (исходный текст сильно поменяли), она появится вверху страницы в блоке «Не применились» — внесите её заново.</li>
<li>Заявки (вкладка «Заявки») можно скачать таблицей для Excel. Колонка «Язык» показывает, с какой версии сайта пришла заявка.</li>
</ul>
<h2>📥 Заявки: статусы и удаление</h2>
<ul>
<li>У каждой заявки слева — <b>статус</b>: 🆕 Новая, 📞 В работе, ✅ Записался, 💤 Не актуальна, 🚫 Спам, 🧪 Тест. Нажмите и выберите — сохраняется сразу.</li>
<li>Цифра у «Заявки» в меню — сколько <b>новых</b>, ещё не разобранных заявок.</li>
<li>Кнопки над таблицей — фильтр: показать только новые, только «в работе» и т. д.</li>
<li>Поле <b>«Заметка»</b> — для себя: «перезвонить в пятницу», «хочет HSK 3». Написали и нажали Enter (или кликнули мимо) — сохранено.</li>
<li><b>🗑</b> справа — удалить заявку. Она попадает в <b>Корзину</b>, оттуда её можно вернуть. «Удалить навсегда» и «Очистить корзину» — уже без возврата.</li>
</ul>
</div>
<?php
    layout_end($msg, $err);
    exit;
}

// --- тексты ---
$slug = (string)($_GET['p'] ?? 'index');
if (!isset(pages()[$slug])) $slug = 'index';
$title   = (string)pages()[$slug];
$langs   = page_langs($slug);
$content = data_read('content');
$map     = text_map()[$slug] ?? null;

// по каждому языку: текущий текст страницы, правки (исходный → новый)
$fragSet = [];
$edits   = [];  // язык => [исходный => текущий]
$origOf  = [];  // язык => [текущий => исходный]
foreach ($langs as $lang) {
    $ls = lslug($lang, $slug);
    $fragSet[$lang] = array_flip(fragments((string)file_get_contents(page_file($ls))));
    $edits[$lang] = $origOf[$lang] = [];
    foreach ($content[$ls] ?? [] as $p) {
        if (!isset($p['o'], $p['n'])) continue;
        $edits[$lang][(string)$p['o']] = (string)$p['n'];
        $origOf[$lang][(string)$p['n']] = (string)$p['o'];
    }
}

/** Одно поле правки. $name — t[язык] или s (все языки); $cur — текст в файле сейчас. */
function field_html(string $name, string $cur, ?string $was, string $revertLang): string {
    $key = md5($cur);
    $txt = show_text($cur);
    $out = '<div class="fld' . ($was !== null ? ' is-changed' : '') . '"><textarea name="' . h($name) . '[' . $key . ']" rows="1" spellcheck="true">' . h($txt) . '</textarea>';
    if ($was !== null) {
        $out .= '<div class="was"><span>было: <s>' . h(show_text($was)) . '</s></span>'
            . '<button class="btn btn--sm btn--ghost" name="revert" value="' . h($revertLang . ':' . $key) . '" formnovalidate data-revert>↩ Вернуть как было</button></div>';
    }
    return $out . '</div>';
}

$secs     = $map['secs'] ?? [];
$sections = [];  // номер блока => [html строк]
$covered  = [];  // язык => [текст => true]
$counts   = ['rows' => 0, 'changed' => 0];
$shownTr  = count($langs) > 1;

foreach (($map['rows'] ?? []) as $r) {
    $sec = (int)$r[0];
    $cells = [];  // язык => [[исходный, текущий], …]
    if (count($r) === 2) {
        // текст без перевода: один и тот же на всех языках
        $raw = (string)$r[1];
        foreach ($langs as $lang) {
            $cur = $edits[$lang][$raw] ?? $raw;
            if (isset($fragSet[$lang][$cur])) $cells[$lang] = [[$raw, $cur]];
        }
        $curs = array_unique(array_map(static function ($c) { return $c[0][1]; }, $cells));
        if ($shownTr && count($cells) === count($langs) && count($curs) === 1) {
            $cur = reset($curs);
            foreach ($langs as $lang) $covered[$lang][$cur] = true;
            $was = $cur !== $raw ? $raw : null;
            $counts['rows']++;
            if ($was !== null) $counts['changed']++;
            $sections[$sec][] = '<div class="row row--shared' . ($was !== null ? ' is-changed' : '') . '"><div class="cell cell--all"><div class="cell__lang">🌐 Все языки</div>'
                . field_html('s', $cur, $was, 'all') . '</div></div>';
            continue;
        }
    } else {
        foreach ($langs as $i => $lang) {
            $unit = $lang === 'ru' ? $r[1] : ($r[$lang === 'en' ? 2 : 3] ?? null);
            if ($unit === null) continue;
            foreach (fragments('<p>' . $unit . '</p>') as $orig) {
                $cur = $edits[$lang][$orig] ?? $orig;
                if (isset($fragSet[$lang][$cur])) $cells[$lang][] = [$orig, $cur];
            }
        }
    }
    if (!$cells) continue;
    $rowChanged = false;
    $html = '';
    foreach ($langs as $lang) {
        $html .= '<div class="cell" data-lang="' . $lang . '"><div class="cell__lang">' . h(LANGS[$lang][0]) . '</div>';
        if (empty($cells[$lang])) {
            $html .= '<div class="cell--empty">—</div>';
        } else {
            foreach ($cells[$lang] as [$orig, $cur]) {
                $covered[$lang][$cur] = true;
                $was = $cur !== $orig ? $orig : null;
                if ($was !== null) $rowChanged = true;
                $html .= field_html('t[' . $lang . ']', $cur, $was, $lang);
            }
            if ($lang !== 'ru' && isset($cells['ru'])) {
                $html .= '<a class="tr-hint" href="#" data-tl="' . ($lang === 'zh' ? 'zh' : 'en') . '" title="Подсказать перевод (откроется Яндекс Переводчик)" aria-label="Подсказать перевод">🌐</a>';
            }
        }
        $html .= '</div>';
    }
    // русский поменяли, а перевод нет — подсказываем
    $ruChanged = false;
    $trChanged = false;
    foreach ($cells as $lang => $list) {
        foreach ($list as [$orig, $cur]) {
            if ($orig === $cur) continue;
            if ($lang === 'ru') $ruChanged = true; else $trChanged = true;
        }
    }
    if ($ruChanged && !$trChanged && count($cells) > 1) {
        $html .= '<div class="row__hint">⚠️ Русский текст изменён — проверьте перевод в соседних колонках</div>';
    }
    $counts['rows']++;
    if ($rowChanged) $counts['changed']++;
    $sections[$sec][] = '<div class="row' . ($rowChanged ? ' is-changed' : '') . '" style="flex-wrap:wrap">' . $html . '</div>';
}

// тексты, которых нет в карте (карту не пересобрали, новый текст) — показываем в конце, чтобы ничего не терялось
$restSec = count($secs);
foreach ($langs as $lang) {
    if ($lang !== 'ru' && $map) continue;
    $ls = lslug($lang, $slug);
    foreach (array_keys($fragSet[$lang]) as $cur) {
        $cur = (string)$cur;
        if (isset($covered[$lang][$cur]) || in_array($cur, ['RU', 'EN', '中文'], true)) continue;
        $was = $origOf[$lang][$cur] ?? null;
        $counts['rows']++;
        if ($was !== null) $counts['changed']++;
        $sections[$restSec][] = '<div class="row' . ($was !== null ? ' is-changed' : '') . '"><div class="cell" data-lang="' . $lang . '"><div class="cell__lang" style="display:block">' . h(LANGS[$lang][0]) . '</div>'
            . field_html('t[' . $lang . ']', $cur, $was, $lang) . '</div></div>';
    }
}
$secs[$restSec] = $map ? 'Прочие тексты страницы' : 'Тексты страницы';

$stale = data_read('content_stale');

layout_start($title, true, 'texts', $csrf, $slug);
topbar('Тексты сайта / <b>' . h($title) . '</b>');
?>
<div class="page">
<div class="title__icon" style="margin-top:34px"><?= PAGE_ICONS[$slug] ?? '📄' ?></div>
<h1 class="title" style="margin-top:0"><?= h($title) ?></h1>
<div class="props">
<span>Открыть на сайте:
<?php foreach ($langs as $i => $lang): ?><?= $i ? ' · ' : ' ' ?><a href="<?= h(page_url($lang, $slug)) ?>" target="_blank" rel="noopener"><?= h(LANGS[$lang][0]) ?> ↗</a><?php endforeach; ?>
</span>
<span>Текстов: <?= $counts['rows'] ?></span>
<?php if ($counts['changed']): ?><span>Изменено вами: <?= $counts['changed'] ?></span><?php endif; ?>
</div>

<?php if (count($langs) > 1): ?>
<div class="callout"><span class="callout__icon">🌐</span><div>Каждый язык — своя колонка. <b>Переводы сами не меняются</b>: поменяли русский текст — поправьте английский и китайский в той же строке. Кнопка 🌐 в поле перевода откроет Яндекс Переводчик. <a href="?tab=help">Подробнее</a></div></div>
<?php else: ?>
<div class="callout"><span class="callout__icon">📄</span><div>Эта страница есть только на русском языке.</div></div>
<?php endif; ?>

<?php
$lostAll = [];
foreach ($langs as $lang) foreach ($stale[lslug($lang, $slug)] ?? [] as $p) $lostAll[] = [$lang, $p];
if ($lostAll): ?>
<div class="callout callout--yellow"><span class="callout__icon">⚠️</span><div>
<p><b>Не применились после обновления сайта.</b> Исходный текст на странице изменили, и ваша правка к нему уже не подходит. Найдите нужное место ниже и внесите правку заново:</p>
<ul><?php foreach ($lostAll as [$lang, $p]): ?><li><span class="tag tag--<?= $lang ?>"><?= h(LANGS[$lang][0]) ?></span> «<?= h(show_text((string)$p['n'])) ?>» <span class="muted small">(было: «<?= h(show_text((string)$p['o'])) ?>»)</span></li><?php endforeach; ?></ul>
</div></div>
<?php endif; ?>

<form method="post" id="tf">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="texts">
<input type="hidden" name="page" value="<?= h($slug) ?>">

<div class="tools">
<div class="search"><input class="input" type="search" id="q" placeholder="Найти текст на странице…" autocomplete="off"></div>
<?php if (count($langs) > 1): ?>
<div class="chips" role="group" aria-label="Какие языки показывать">
<?php foreach ($langs as $lang): ?><button type="button" class="chip" data-lang-toggle="<?= $lang ?>" aria-pressed="true"><?= h(LANGS[$lang][0]) ?></button><?php endforeach; ?>
</div>
<?php endif; ?>
<label class="toggle"><input type="checkbox" id="onlyChanged"> Только изменённые</label>
<button type="button" class="btn btn--ghost btn--sm" id="foldAll">Свернуть все</button>
</div>

<?php if (count($langs) > 1): ?>
<div class="heads"><?php foreach ($langs as $lang): ?><span data-lang="<?= $lang ?>"><?= h(LANGS[$lang][0] . ' · ' . LANGS[$lang][1]) ?></span><?php endforeach; ?></div>
<?php endif; ?>

<?php foreach ($secs as $i => $name): if (empty($sections[$i])) continue; ?>
<details class="sec" open>
<summary><?= h((string)$name) ?> <span class="sec__count"><?= count($sections[$i]) ?></span></summary>
<div class="sec__body"><?= implode("\n", $sections[$i]) ?></div>
</details>
<?php endforeach; ?>
<div class="empty-note" id="nothing" hidden>Ничего не найдено. Попробуйте другое слово.</div>

<div class="savebar" id="savebar">
<span><span class="savebar__count" id="dirtyCount">0</span> <span id="dirtyWord">изменений</span> не сохранено</span>
<label class="toggle" title="Например, пункт меню или текст в подвале — он есть на всех страницах"><input type="checkbox" name="everywhere" value="1" checked> Заменить и на других страницах</label>
<button type="button" class="btn" id="undo">Отменить</button>
<button class="btn btn--primary" id="save">Сохранить <span class="kbd">Ctrl S</span></button>
</div>
</form>
</div>

<script>
(function () {
  var form = document.getElementById('tf');
  var areas = Array.prototype.slice.call(form.querySelectorAll('textarea'));
  var bar = document.getElementById('savebar');
  var page = <?= json_encode($slug) ?>;
  var sizing = window.CSS && CSS.supports && CSS.supports('field-sizing', 'content');
  function fit(t) { if (sizing) return; t.style.height = 'auto'; t.style.height = t.scrollHeight + 2 + 'px'; }
  function word(n) { var a = n % 10, b = n % 100; return a === 1 && b !== 11 ? 'изменение' : (a >= 2 && a <= 4 && (b < 10 || b >= 20) ? 'изменения' : 'изменений'); }
  function dirtyCount() {
    var names = {};
    areas.forEach(function (t) { if (t.value !== t.defaultValue) names[t.name] = 1; });
    return Object.keys(names).length;
  }
  function refresh() {
    var n = dirtyCount();
    document.getElementById('dirtyCount').textContent = n;
    document.getElementById('dirtyWord').textContent = word(n);
    bar.classList.toggle('is-on', n > 0);
  }
  if (!sizing) {
    // без field-sizing подгоняем высоту полей по тексту, сначала видимые
    var i = 0;
    (function step() { var end = Math.min(i + 150, areas.length); for (; i < end; i++) fit(areas[i]); if (i < areas.length) requestAnimationFrame(step); })();
  }
  form.addEventListener('input', function (e) {
    var t = e.target;
    if (t.tagName !== 'TEXTAREA') return;
    fit(t);
    // один и тот же текст может стоять в нескольких строках — держим их одинаковыми
    areas.forEach(function (o) { if (o !== t && o.name === t.name) { o.value = t.value; fit(o); o.parentNode.classList.toggle('is-dirty', o.value !== o.defaultValue); } });
    t.parentNode.classList.toggle('is-dirty', t.value !== t.defaultValue);
    refresh();
  });
  document.getElementById('undo').addEventListener('click', function () {
    areas.forEach(function (t) { t.value = t.defaultValue; t.parentNode.classList.remove('is-dirty'); fit(t); });
    refresh();
  });
  var submitting = false;
  form.addEventListener('submit', function (e) {
    var revert = e.submitter && e.submitter.hasAttribute('data-revert');
    if (revert && dirtyCount() && !confirm('Несохранённые правки на этой странице пропадут. Вернуть текст?')) { e.preventDefault(); return; }
    // отправляем только изменённые поля
    var seen = {};
    areas.forEach(function (t) { if (revert || t.value === t.defaultValue || seen[t.name]) t.disabled = true; else seen[t.name] = 1; });
    sessionStorage.setItem('adm-scroll-' + page, String(window.scrollY));
    submitting = true;
  });
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'ы' || e.code === 'KeyS')) { e.preventDefault(); if (dirtyCount()) form.requestSubmit(document.getElementById('save')); }
  });
  window.addEventListener('beforeunload', function (e) { if (!submitting && dirtyCount()) { e.preventDefault(); e.returnValue = ''; } });
  var y = sessionStorage.getItem('adm-scroll-' + page);
  if (y !== null) { sessionStorage.removeItem('adm-scroll-' + page); window.scrollTo(0, +y); }

  // поиск и «только изменённые»
  var q = document.getElementById('q'), only = document.getElementById('onlyChanged');
  function filter() {
    var v = q.value.toLowerCase().trim(), any = false;
    document.querySelectorAll('.sec').forEach(function (sec) {
      var shown = 0;
      sec.querySelectorAll('.row').forEach(function (row) {
        var ok = true;
        if (only.checked) ok = row.classList.contains('is-changed') || !!row.querySelector('.is-dirty');
        if (ok && v) {
          ok = false;
          row.querySelectorAll('textarea').forEach(function (t) { if (t.value.toLowerCase().indexOf(v) >= 0) ok = true; });
          if (!ok) row.querySelectorAll('.was s').forEach(function (s) { if (s.textContent.toLowerCase().indexOf(v) >= 0) ok = true; });
        }
        row.hidden = !ok;
        if (ok) shown++;
      });
      sec.hidden = shown === 0;
      if ((v || only.checked) && shown) sec.open = true;
      if (shown) any = true;
    });
    document.getElementById('nothing').hidden = any;
  }
  q.addEventListener('input', filter);
  only.addEventListener('change', filter);
  q.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });

  // свернуть / развернуть все блоки
  var fold = document.getElementById('foldAll');
  fold.addEventListener('click', function () {
    var open = fold.textContent === 'Развернуть все';
    document.querySelectorAll('.sec').forEach(function (s) { s.open = open; });
    fold.textContent = open ? 'Свернуть все' : 'Развернуть все';
  });

  // какие языки показывать (запоминаем)
  var hidden = [];
  try { hidden = JSON.parse(localStorage.getItem('adm-hide-langs') || '[]'); } catch (e) {}
  function applyLangs() {
    ['ru', 'en', 'zh'].forEach(function (l) { document.body.classList.toggle('hide-' + l, hidden.indexOf(l) >= 0); });
    document.querySelectorAll('[data-lang-toggle]').forEach(function (b) { b.setAttribute('aria-pressed', hidden.indexOf(b.dataset.langToggle) < 0 ? 'true' : 'false'); });
  }
  document.querySelectorAll('[data-lang-toggle]').forEach(function (b) {
    b.addEventListener('click', function () {
      var l = b.dataset.langToggle, i = hidden.indexOf(l);
      if (i >= 0) hidden.splice(i, 1);
      else if (document.querySelectorAll('[data-lang-toggle]').length - hidden.length > 1) hidden.push(l);
      localStorage.setItem('adm-hide-langs', JSON.stringify(hidden));
      applyLangs();
      areas.forEach(fit);
    });
  });
  applyLangs();

  // «Подсказать перевод»: Яндекс Переводчик с русским текстом строки
  form.addEventListener('click', function (e) {
    var a = e.target.closest('.tr-hint');
    if (!a) return;
    e.preventDefault();
    var ru = a.closest('.row').querySelector('[data-lang="ru"]');
    var text = Array.prototype.map.call(ru.querySelectorAll('textarea'), function (t) { return t.value; }).join(' ');
    window.open('https://translate.yandex.ru/?source_lang=ru&target_lang=' + a.dataset.tl + '&text=' + encodeURIComponent(text), '_blank', 'noopener');
  });
})();
</script>
<?php
layout_end($msg, $err);
