<?php
/**
 * Приём заявки с формы «Записаться на пробный урок» — thousandli.ru
 * Отправка на почту через mail(). Ответ — JSON для fetch().
 */
declare(strict_types=1);

// Куда отправлять заявки (при необходимости поменяйте / добавьте через запятую)
const LEAD_TO   = 'panpetos2@gmail.com';
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

$subject = '=?UTF-8?B?' . base64_encode('Заявка на пробный урок — thousandli.ru') . '?=';
$body = "Новая заявка на бесплатный пробный урок\n\n"
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

$sent = mail(LEAD_TO, $subject, $body, $headers, '-f' . LEAD_FROM);
reply($sent, $sent ? '' : 'mail', $sent ? 200 : 500);
