<?php
/**
 * Админка thousandli.ru: заявки с сайта и правка текстов страниц.
 *
 * Тексты правятся прямо в готовых .html (меняется только текст между тегами). Список правок хранится
 * в data/content.php; после каждого деплоя workflow вызывает admin.php?reapply=1 и правки возвращаются.
 * Данные (заявки, пароль, правки) лежат в data/*.php — снаружи эти файлы отдают 404, в git их нет.
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
    'business' => 'Бизнес',
    'politics' => 'Политика',
    'tech'     => 'Техника',
    'other'    => 'Другое',
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

const DATA_DIR = __DIR__ . '/data';
const GUARD    = "<?php http_response_code(404); exit; ?>\n";

// ---------- хранилище ----------
function data_path(string $name): string { return DATA_DIR . '/' . $name . '.php'; }

function data_raw(string $name): string {
    $f = data_path($name);
    if (!is_file($f)) return '';
    $s = (string)file_get_contents($f);
    return strncmp($s, GUARD, strlen(GUARD)) === 0 ? substr($s, strlen(GUARD)) : $s;
}

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

// ---------- правка текстов в .html ----------
// Разбор на «служебные» куски (script, style, svg, head, комментарии — не трогаем) и обычную разметку.
function html_parts(string $html): array {
    return preg_split(
        '~(<script\b.*?</script>|<style\b.*?</style>|<svg\b.*?</svg>|<head\b.*?</head>|<noscript\b.*?</noscript>|<!--.*?-->)~is',
        $html, -1, PREG_SPLIT_DELIM_CAPTURE
    ) ?: [$html];
}

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
        $t = $slug;
        if (preg_match('~<h1[^>]*>(.*?)</h1>~is', $head, $m) || preg_match('~<title>(.*?)</title>~is', $head, $m)) {
            $t = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: $slug;
        }
        $list[$slug] = mb_substr($t, 0, 80);
    }
    return $list;
}

/** Видимые текстовые фрагменты страницы (как в файле, без обрамляющих пробелов), без повторов. */
function fragments(string $html): array {
    $out = [];
    foreach (html_parts($html) as $i => $part) {
        if ($i % 2) continue;
        if (!preg_match_all('~>([^<>]+)<~u', '>' . $part . '<', $m)) continue;
        foreach ($m[1] as $raw) {
            $raw = trim($raw);
            if ($raw === '' || !preg_match('/\p{L}/u', $raw)) continue;
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
        $parts[$i] = preg_replace_callback($re, static fn($m) => $m[1] . $to . $m[2], $part, -1, $c) ?? $part;
        $count += (int)$c;
    }
    return [implode('', $parts), $count];
}

function show_text(string $raw): string {
    $t = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string)preg_replace('/\s+/u', ' ', $t));
}

function to_raw(string $plain): string {
    return htmlspecialchars(trim((string)preg_replace('/\s+/u', ' ', $plain)), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Возвращает правки, которые снова нужно внести в файлы (после деплоя страница снова «исходная»). */
function reapply_all(): int {
    $changed = 0;
    $content = data_read('content');
    $known   = pages();
    $stale   = [];
    foreach ($content as $slug => $pairs) {
        $file = page_file((string)$slug);
        if (!isset($known[$slug]) || !is_file($file) || !is_array($pairs)) continue;
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
                $arch[$slug] = array_values(array_filter($arch[$slug] ?? [], static fn($a) => ($a['n'] ?? '') !== $p['n']));
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
    $f = array_filter(data_read('fails'), static fn($t) => is_int($t) && $t > time() - 900);
    return count($f) >= 10;
}

function add_fail(): void {
    $f = array_values(array_filter(data_read('fails'), static fn($t) => is_int($t) && $t > time() - 900));
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
if (!in_array($tab, ['leads', 'texts', 'password'], true)) $tab = 'leads';

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
        $tab  = 'texts';
        $slug = (string)($_POST['page'] ?? '');
        if (!isset(pages()[$slug]) || !is_file(page_file($slug))) {
            $err = 'Страница не найдена.';
        } else {
            $content    = data_read('content');
            $everywhere = !empty($_POST['everywhere']);
            $revert     = (string)($_POST['revert'] ?? '');
            $posted     = is_array($_POST['t'] ?? null) ? $_POST['t'] : [];
            $html       = (string)file_get_contents(page_file($slug));
            $byKey      = [];
            foreach (fragments($html) as $raw) $byKey[md5($raw)] = $raw;

            // список (текущий фрагмент → новый) для этой страницы
            $jobs = [];
            if ($revert !== '' && isset($byKey[$revert])) {
                $cur = $byKey[$revert];
                foreach ($content[$slug] ?? [] as $p) {
                    if ($p['n'] === $cur) { $jobs[] = [$cur, $p['o']]; break; }
                }
            } else {
                foreach ($posted as $key => $plain) {
                    $key = (string)$key;
                    if (!isset($byKey[$key]) || !is_string($plain)) continue;
                    $cur = $byKey[$key];
                    $plain = trim((string)preg_replace('/\s+/u', ' ', $plain));
                    if ($plain === '' || $plain === show_text($cur)) continue;
                    $jobs[] = [$cur, to_raw($plain)];
                }
            }

            $done = 0;
            $slugs = $everywhere ? array_map('strval', array_keys(pages())) : [$slug];
            foreach ($jobs as [$cur, $new]) {
                if ($cur === $new) continue;
                // исходный текст: если фрагмент уже правили — берём оригинал из списка правок
                $orig = $cur;
                foreach ($content[$slug] ?? [] as $p) {
                    if ($p['n'] === $cur) { $orig = $p['o']; break; }
                }
                foreach ($slugs as $s) {
                    $file = page_file($s);
                    if (!is_file($file)) continue;
                    $src = (string)file_get_contents($file);
                    [$out, $n] = replace_fragment($src, $cur, $new);
                    if ($n === 0) continue;
                    file_put_contents($file, $out, LOCK_EX);
                    $list = array_values(array_filter(
                        $content[$s] ?? [],
                        static fn($p) => $p['n'] !== $cur && $p['o'] !== $orig
                    ));
                    if ($new !== $orig) $list[] = ['o' => $orig, 'n' => $new];
                    if ($list) $content[$s] = $list; else unset($content[$s]);
                    if ($s === $slug) $done++;
                }
            }
            data_write_json('content', $content);
            $msg = $done ? ($revert !== '' ? 'Текст возвращён как был.' : "Сохранено изменений: {$done}.") : 'Изменений нет.';
        }
    }
}

// ---------- вывод ----------
function layout_start(string $title, bool $authed, string $tab, string $csrf): void {
    ?><!doctype html>
<html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> — админка «Тысяча ли»</title>
<style>
*{box-sizing:border-box}body{margin:0;font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f4f1ec;color:#1f1b16}
header{background:#1f1b16;color:#fff;padding:12px 20px;display:flex;flex-wrap:wrap;gap:8px 20px;align-items:center}
header b{font-size:18px}nav{display:flex;gap:6px;flex:1;flex-wrap:wrap}
nav a{color:#fff;text-decoration:none;padding:6px 12px;border-radius:8px;opacity:.8}nav a.on,nav a:hover{background:#ffffff26;opacity:1}
main{max-width:1100px;margin:0 auto;padding:20px}
.card{background:#fff;border-radius:14px;padding:18px 20px;margin-bottom:16px;box-shadow:0 1px 3px #0001}
h1{font-size:22px;margin:0 0 14px}h2{font-size:18px;margin:0 0 10px}
input[type=text],input[type=email],input[type=password],select,textarea{width:100%;font:inherit;padding:10px 12px;border:1px solid #cfc8bd;border-radius:10px;background:#fff}
textarea{resize:vertical;min-height:44px}
button,.btn{font:inherit;cursor:pointer;border:0;border-radius:10px;padding:10px 18px;background:#b3261e;color:#fff;text-decoration:none;display:inline-block}
button.light,.btn.light{background:#ece7df;color:#1f1b16}
label{display:block;margin:10px 0 4px;font-weight:600}
.msg{background:#e3f4e3;border:1px solid #9fd09f;padding:10px 14px;border-radius:10px;margin-bottom:14px}
.err{background:#fde6e4;border:1px solid #f0a8a2;padding:10px 14px;border-radius:10px;margin-bottom:14px}
table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:8px 10px;border-bottom:1px solid #eee;vertical-align:top;font-size:15px}
th{font-size:13px;color:#6b6358;text-transform:uppercase;letter-spacing:.03em}
.muted{color:#6b6358;font-size:14px}.tag{display:inline-block;background:#fff1c9;border-radius:6px;padding:1px 8px;font-size:13px}
.frag{border-bottom:1px solid #eee;padding:10px 0}.frag small{display:block;margin-top:4px}
.bar{position:sticky;bottom:0;background:#f4f1ecee;padding:12px 0;display:flex;gap:12px;align-items:center;flex-wrap:wrap}
@media(max-width:700px){table,thead,tbody,tr,td{display:block}thead{display:none}tr{border-bottom:2px solid #ddd;padding:8px 0}}
</style></head><body>
<header><b>Тысяча ли · админка</b>
<?php if ($authed): ?>
<nav>
<a href="?tab=leads" class="<?= $tab === 'leads' ? 'on' : '' ?>">Заявки</a>
<a href="?tab=texts" class="<?= $tab === 'texts' ? 'on' : '' ?>">Тексты сайта</a>
<a href="?tab=password" class="<?= $tab === 'password' ? 'on' : '' ?>">Пароль</a>
</nav>
<form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
<button class="light" name="action" value="logout">Выйти</button></form>
<?php endif; ?>
</header><main>
<?php
}

function layout_end(): void { echo "</main></body></html>"; }

function notices(string $msg, string $err): void {
    if ($msg !== '') echo '<div class="msg">' . h($msg) . '</div>';
    if ($err !== '') echo '<div class="err">' . h($err) . '</div>';
}

// --- не вошли ---
if (!$authed) {
    layout_start('Вход', false, '', $csrf);
    notices($msg, $err);
    if ($auth === []) {
        ?>
<div class="card" style="max-width:480px;margin:30px auto">
<h1>Первая настройка</h1>
<p class="muted">Введите код настройки (его выдал разработчик) и задайте логин и пароль для входа.</p>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="setup">
<label>Код настройки</label><input type="password" name="code" required>
<label>Логин (почта)</label><input type="email" name="login" required>
<label>Пароль (не короче 8 символов)</label><input type="password" name="pass1" required minlength="8">
<label>Пароль ещё раз</label><input type="password" name="pass2" required minlength="8">
<p><button>Сохранить</button></p>
</form></div>
<?php
    } else {
        ?>
<div class="card" style="max-width:420px;margin:30px auto">
<h1>Вход</h1>
<form method="post">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="login">
<label>Логин</label><input type="email" name="login" required autofocus autocomplete="username">
<label>Пароль</label><input type="password" name="password" required autocomplete="current-password">
<p><button>Войти</button></p>
</form></div>
<?php
    }
    layout_end();
    exit;
}

// --- вошли ---
// после деплоя исходные страницы возвращаются — молча вносим правки заново
if ($tab === 'texts' && !$isPost) reapply_all();

if ($tab === 'leads') {
    // CSV для Excel
    $leads = [];
    foreach (explode("\n", data_raw('leads')) as $line) {
        $j = json_decode(trim($line), true);
        if (is_array($j)) $leads[] = $j;
    }
    $leads = array_reverse($leads);
    $kindName = static fn(array $l): string => ($l['kind'] ?? '') === 'quiz'
        ? 'Квиз' . (($l['program'] ?? '') !== '' ? ' · ' . $l['program'] : '')
        : 'Пробный урок';
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

    if (isset($_GET['csv'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="zayavki-thousandli.csv"');
        echo "\xEF\xBB\xBF";
        $o = fopen('php://output', 'w');
        fputcsv($o, ['Дата', 'Имя', 'Телефон', 'Откуда', 'Ответы квиза', 'Страница'], ';');
        foreach ($leads as $l) {
            fputcsv($o, [$fmt((string)($l['ts'] ?? '')), $l['name'] ?? '', $l['phone'] ?? '', $kindName($l), $quizText($l), $l['page'] ?? ''], ';');
        }
        exit;
    }

    layout_start('Заявки', true, 'leads', $csrf);
    notices($msg, $err);
    ?>
<div class="card">
<h1>Заявки с сайта</h1>
<p class="muted">Всего: <?= count($leads) ?>. Новые — сверху. Те же заявки приходят на почту school@thousandli.ru.
<?php if ($leads): ?> <a href="?tab=leads&amp;csv=1">Скачать таблицу (CSV)</a><?php endif; ?></p>
<?php if (!$leads): ?>
<p>Пока заявок нет. Здесь появятся заявки, отправленные после запуска админки.</p>
<?php else: ?>
<table><thead><tr><th>Дата</th><th>Имя</th><th>Телефон</th><th>Откуда</th><th>Ответы квиза</th></tr></thead><tbody>
<?php foreach (array_slice($leads, 0, 300) as $l):
    $digits = preg_replace('/\D/', '', (string)($l['phone'] ?? '')); ?>
<tr>
<td><?= h($fmt((string)($l['ts'] ?? ''))) ?></td>
<td><b><?= h((string)($l['name'] ?? '')) ?></b></td>
<td><a href="tel:+<?= h((string)$digits) ?>"><?= h((string)($l['phone'] ?? '')) ?></a></td>
<td><?= h($kindName($l)) ?><?php if (($l['page'] ?? '') !== ''): ?><br><span class="muted"><?= h((string)$l['page']) ?></span><?php endif; ?></td>
<td class="muted"><?= h($quizText($l)) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php if (count($leads) > 300): ?><p class="muted">Показаны последние 300, остальные — в CSV.</p><?php endif; ?>
<?php endif; ?>
</div>
<?php
    layout_end();
    exit;
}

if ($tab === 'password') {
    layout_start('Пароль', true, 'password', $csrf);
    notices($msg, $err);
    ?>
<div class="card" style="max-width:480px">
<h1>Сменить пароль</h1>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="password">
<label>Текущий пароль</label><input type="password" name="cur" required>
<label>Новый пароль (не короче 8 символов)</label><input type="password" name="pass1" required minlength="8">
<label>Новый пароль ещё раз</label><input type="password" name="pass2" required minlength="8">
<p><button>Сменить</button></p>
</form></div>
<?php
    layout_end();
    exit;
}

// --- тексты ---
$slug = (string)($_POST['page'] ?? $_GET['p'] ?? 'index');
if (!isset(pages()[$slug])) $slug = 'index';
$file    = page_file($slug);
$html    = is_file($file) ? (string)file_get_contents($file) : '';
$frags   = fragments($html);
$changed = [];
foreach (data_read('content')[$slug] ?? [] as $p) $changed[$p['n']] = $p['o'];

layout_start('Тексты', true, 'texts', $csrf);
notices($msg, $err);
?>
<div class="card">
<h1>Тексты сайта</h1>
<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
<input type="hidden" name="tab" value="texts">
<div style="flex:1;min-width:220px"><label style="margin-top:0">Страница</label>
<select name="p" onchange="this.form.submit()">
<?php foreach (pages() as $k => $title): ?>
<option value="<?= h((string)$k) ?>"<?= (string)$k === $slug ? ' selected' : '' ?>><?= h($title) ?> (<?= h((string)$k) ?>.html)</option>
<?php endforeach; ?>
</select></div>
<a class="btn light" href="/<?= $slug === 'index' ? '' : h($slug) . '.html' ?>" target="_blank" rel="noopener">Открыть страницу</a>
</form>
<p class="muted">Поменяйте текст в нужном поле и нажмите «Сохранить» — страница обновится сразу. Менять можно только слова:
картинки, ссылки и вёрстка остаются как есть.</p>
<?php $lost = data_read('content_stale')[$slug] ?? []; if ($lost): ?>
<p class="muted"><b>Не применились после обновления сайта</b> (исходный текст на странице изменили, ваша правка к нему уже не подходит). Найдите нужное место ниже и внесите правку заново:</p>
<ul class="muted">
<?php foreach ($lost as $p): ?><li>«<?= h(show_text((string)$p['n'])) ?>» <small>(было: «<?= h(show_text((string)$p['o'])) ?>»)</small></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<input type="text" id="q" placeholder="Найти текст на странице…" oninput="
var v=this.value.toLowerCase();document.querySelectorAll('.frag').forEach(function(e){e.style.display=e.textContent.toLowerCase().indexOf(v)<0&&!(e.querySelector('textarea')&&e.querySelector('textarea').value.toLowerCase().indexOf(v)>=0)?'none':''})">
</div>

<form method="post">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="texts">
<input type="hidden" name="page" value="<?= h($slug) ?>">
<div class="card">
<?php if (!$frags): ?><p>На этой странице текстов не найдено.</p><?php endif; ?>
<?php foreach ($frags as $raw):
    $key = md5($raw);
    $txt = show_text($raw);
    $rows = max(1, (int)ceil(mb_strlen($txt) / 90)); ?>
<div class="frag">
<textarea name="t[<?= h($key) ?>]" rows="<?= $rows ?>"><?= h($txt) ?></textarea>
<?php if (isset($changed[$raw])): ?>
<small><span class="tag">изменено</span> было: «<?= h(show_text($changed[$raw])) ?>»
<button class="light" style="padding:3px 10px;font-size:13px" name="revert" value="<?= h($key) ?>" formnovalidate>Вернуть как было</button></small>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<div class="bar">
<button>Сохранить</button>
<label style="margin:0;font-weight:400"><input type="checkbox" name="everywhere" value="1" checked>
Заменить такой же текст и на других страницах (меню, подвал)</label>
</div>
</form>
<?php
layout_end();
