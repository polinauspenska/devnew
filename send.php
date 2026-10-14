<?php
/**
 * Lead form handler.
 *
 * Every enquiry form on the site posts here and the result is emailed to
 * info@afmdevelopments.com. This replaces the previous mailto: links, which
 * depended on the visitor having a desktop mail client configured and lost
 * the lead entirely if they did not.
 *
 * Mail is sent with PHP mail(), which on this cPanel host is local delivery to
 * a mailbox on the same domain -- no SMTP auth needed and no SPF/DMARC risk.
 * The From address is deliberately the site's own mailbox rather than the
 * visitor's: putting a visitor's address in From is what makes this kind of
 * form fail SPF and land in spam. The visitor goes in Reply-To instead, so
 * hitting reply in the mail client still answers them directly.
 */

declare(strict_types=1);

const MAIL_TO      = 'info@afmdevelopments.com';
const MAIL_FROM    = 'info@afmdevelopments.com';
const MAX_FIELDS   = 40;
const MAX_VALUE_LEN = 5000;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function fail(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail('Method not allowed.', 405);
}

$raw = file_get_contents('php://input') ?: '';
if ($raw === '' || strlen($raw) > 100000) {
    fail('Empty or oversized request.');
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    fail('Malformed request.');
}

// Honeypot: a field human visitors never see. Anything filling it is a bot.
// Answer 200 so the bot cannot tell it was rejected.
if (!empty($payload['_trap'])) {
    echo json_encode(['ok' => true]);
    exit;
}

$fields = $payload['fields'] ?? null;
if (!is_array($fields) || count($fields) === 0 || count($fields) > MAX_FIELDS) {
    fail('No form data received.');
}

/**
 * Strip anything that could break out of a mail header into a new one.
 * Without this, a crafted "email" value could inject Bcc/To headers and turn
 * the form into an open relay.
 */
function headerSafe(string $v): string {
    return trim(str_replace(["\r", "\n", "\0", '%0a', '%0d'], '', $v));
}

$name = '';
$replyTo = '';
$lines = [];

foreach ($fields as $f) {
    if (!is_array($f)) continue;
    $label = headerSafe((string)($f['label'] ?? ''));
    $value = (string)($f['value'] ?? '');
    if (strlen($value) > MAX_VALUE_LEN) {
        $value = substr($value, 0, MAX_VALUE_LEN) . ' […truncated]';
    }
    $key = strtolower(preg_replace('/[^a-z]/i', '', $label));
    if ($key === 'fullname' || $key === 'name') {
        $name = headerSafe($value);
    }
    if ($key === 'email' && filter_var(trim($value), FILTER_VALIDATE_EMAIL)) {
        $replyTo = headerSafe(trim($value));
    }
    $lines[] = ($label !== '' ? $label : 'Field') . ': ' . ($value !== '' ? $value : '-');
}

if ($replyTo === '') {
    fail('A valid email address is required.');
}

$subjectPrefix = headerSafe((string)($payload['subject'] ?? 'Website Enquiry'));
if ($subjectPrefix === '') $subjectPrefix = 'Website Enquiry';
$subject = $subjectPrefix . ($name !== '' ? ' — ' . $name : '');

$pageUrl = headerSafe((string)($payload['page'] ?? ''));

$body  = "New enquiry from the AFM Developments website.\n";
$body .= str_repeat('-', 52) . "\n\n";
$body .= implode("\n", $lines) . "\n\n";
$body .= str_repeat('-', 52) . "\n";
if ($pageUrl !== '') $body .= "Submitted from: $pageUrl\n";
$body .= 'Received: ' . date('Y-m-d H:i:s') . "\n";
$body .= 'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";

$headers = [
    'From: AFM Website <' . MAIL_FROM . '>',
    'Reply-To: ' . ($name !== '' ? '"' . $name . '" <' . $replyTo . '>' : $replyTo),
    'Content-Type: text/plain; charset=utf-8',
    'MIME-Version: 1.0',
    'X-Mailer: PHP/' . phpversion(),
];

$sent = @mail(
    MAIL_TO,
    '=?UTF-8?B?' . base64_encode($subject) . '?=',
    $body,
    implode("\r\n", $headers),
    '-f' . MAIL_FROM
);

if (!$sent) {
    // Don't lose the lead just because mail() failed -- keep a copy on disk so
    // it can still be recovered, then tell the visitor to phone/email instead.
    @file_put_contents(
        __DIR__ . '/_leads_fallback.log',
        date('c') . "\n" . $body . "\n====\n",
        FILE_APPEND | LOCK_EX
    );
    fail('Could not send your enquiry. Please email info@afmdevelopments.com directly.', 500);
}

echo json_encode(['ok' => true]);
