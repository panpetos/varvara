<?php
/**
 * Приём заявки с формы «Записаться на пробный урок» — thousandli.ru
 * Отправка на почту через mail(). Ответ — JSON для fetch().
 */
declare(strict_types=1);

// Куда отправлять заявки (при необходимости поменяйте / добавьте через запятую)
const LEAD_TO   = 'school@thousandli.ru';
const LEAD_FROM = 'noreply@thousandli.ru';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function reply(bool $ok, string $error = '', int $code = 200): void {
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reply(false, 'method', 405);
}

// Honeypot: боты заполняют скрытое поле — делаем вид, что всё хорошо
if (!empty($_POST['website'])) {
    reply(true);
}

$clean = static fn(string $v, int $max): string =>
    mb_substr(trim(preg_replace('/[\r\n\t]+/u', ' ', strip_tags($v))), 0, $max);

$name    = $clean((string)($_POST['name'] ?? ''), 80);
$phone   = $clean((string)($_POST['phone'] ?? ''), 30);
$consent = ($_POST['consent'] ?? '') === '1';
$digits  = preg_replace('/\D/', '', $phone);

if (mb_strlen($name) < 2)   reply(false, 'name', 422);
if (strlen($digits) !== 11) reply(false, 'phone', 422);
if (!$consent)              reply(false, 'consent', 422);

// Stitex Captcha: серверный ключ записывает деплой из GitHub Secrets в captcha-secret.php (в git его нет)
function captcha_ok(string $secret, string $token): bool {
    if ($token === '') return false;
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'timeout'       => 5,
        'ignore_errors' => true,
        'header'        => 'Content-Type: application/json',
        'content'       => json_encode(['secretKey' => $secret, 'solveToken' => $token]),
    ]]);
    $res = @file_get_contents('https://pulse.stitex.com/api/captcha/verify', false, $ctx);
    // сервис капчи недоступен — заявку не теряем
    if ($res === false || !preg_match('/\s200\s/', $http_response_header[0] ?? '')) return true;
    return (json_decode($res, true)['success'] ?? false) === true;
}
$captchaFile   = __DIR__ . '/captcha-secret.php';
$captchaSecret = is_file($captchaFile) ? (string)(require $captchaFile) : '';
if ($captchaSecret !== '' && !captcha_ok($captchaSecret, (string)($_POST['stitex-captcha-token'] ?? ''))) {
    reply(false, 'captcha', 422);
}

// Квиз: ответы на 4 вопроса + рекомендация (главная) или итог по программе (страницы программ)
$quizQuestions = [
    'q1' => 'Для кого обучение',
    'q2' => 'Главная цель',
    'q3' => 'Текущий уровень',
    'q4' => 'Формат',
];
$quiz = '';
$quizAnswers = [];
foreach ($quizQuestions as $key => $label) {
    $answer = $clean((string)($_POST[$key] ?? ''), 120);
    if ($answer !== '') {
        $quiz .= "{$label}: {$answer}\n";
        $quizAnswers[$label] = $answer;
    }
}
$result = $clean((string)($_POST['result'] ?? ''), 120);
if ($result !== '') $quiz .= "Рекомендация: {$result}\n";
$isQuiz = $quiz !== '';
$program = $clean((string)($_POST['program'] ?? ''), 120);
$quizTitle = $program !== '' ? "Новая заявка из квиза на странице программы «{$program}»" : 'Новая заявка из квиза «Подобрать программу»';

$subject = '=?UTF-8?B?' . base64_encode(($isQuiz ? 'Заявка из квиза' : 'Заявка на пробный урок') . ' — thousandli.ru') . '?=';
$body = ($isQuiz ? "{$quizTitle}\n\n" . $quiz . "\n" : "Новая заявка на бесплатный пробный урок\n\n")
      . "Имя: {$name}\n"
      . "Телефон: {$phone}\n"
      . "Согласие на обработку ПДн: да\n"
      . 'Дата: ' . date('d.m.Y H:i') . "\n"
      . 'Страница: ' . ($_SERVER['HTTP_REFERER'] ?? '—') . "\n";

$headers = implode("\r\n", [
    'From: =?UTF-8?B?' . base64_encode('Тысяча ли') . '?= <' . LEAD_FROM . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
]);

// Копия заявки для раздела «Заявки» в админке (admin.php). Файл data/leads.php снаружи отдаёт 404.
// Пишем до отправки письма: если почта сбойнула, заявка всё равно остаётся в админке.
$leadsFile = __DIR__ . '/data/leads.php';
if (is_dir(dirname($leadsFile)) || @mkdir(dirname($leadsFile), 0755, true)) {
    $line = json_encode([
        'ts'      => date('c'),
        'name'    => $name,
        'phone'   => $phone,
        'kind'    => $isQuiz ? 'quiz' : 'trial',
        'program' => $program,
        'quiz'    => $quizAnswers,
        'result'  => $result,
        'page'    => $clean((string)($_SERVER['HTTP_REFERER'] ?? ''), 200),
    ], JSON_UNESCAPED_UNICODE) . "\n";
    if (!is_file($leadsFile)) $line = "<?php http_response_code(404); exit; ?>\n" . $line;
    @file_put_contents($leadsFile, $line, FILE_APPEND | LOCK_EX);
}

$sent = mail(LEAD_TO, $subject, $body, $headers, '-f' . LEAD_FROM);
reply($sent, $sent ? '' : 'mail', $sent ? 200 : 500);
