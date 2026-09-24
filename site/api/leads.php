<?php
/**
 * Lead form: the PHP counterpart of POST /api/leads from server/routes.ts.
 *
 * cPanel shared hosting cannot run Node, so the Express route was rewritten in
 * PHP. The input and output contract is the same (JSON in, 2xx out), so the
 * front end is unchanged: .htaccess rewrites /api/leads to this file.
 *
 * On Replit each lead went to Postgres. Here it is appended to
 * ~/smb-leads.jsonl (outside public_html) BEFORE any email is attempted, so a
 * lead is never lost to a mail failure.
 *
 * The Resend key lives in ~/smb-config.php, outside the document root: a file
 * in the docroot would be overwritten by every deploy and leak into the repo.
 *   <?php return ['resend_api_key' => 're_...'];
 * Without the key, the owner notification falls back to local mail(): info@
 * is a mailbox on this same server, so local delivery works. The customer
 * auto-reply is sent only through Resend, because the domain's SPF points at
 * Amazon SES and mail() to outside addresses would land in spam.
 *
 * Syntax deliberately stays within PHP 7.4 so it does not depend on the
 * version set in MultiPHP Manager.
 */

declare(strict_types=1);

const SITE          = 'simplemadeblinds.mx';
const CONTACT_EMAIL = 'info@simplemadeblinds.mx';
const SENDER        = 'noreply@simplemadeblinds.mx';

// Same limit as the Express version: 5 submissions per IP per 10 minutes.
const RATE_LIMIT  = 5;
const RATE_WINDOW = 600;

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** The account's home directory: /home/<account>, derived from this file's path. */
function privateDir(): string
{
    if (preg_match('#^(/home/[^/]+)/#', __DIR__, $m)) {
        return $m[1];
    }
    return dirname(__DIR__, 3);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['message' => 'Method not allowed']);
}

function rateLimited(string $ip): bool
{
    $file = sys_get_temp_dir() . '/smb-leads-' . sha1($ip) . '.txt';
    $now = time();
    $hits = [];

    if (is_readable($file)) {
        foreach (explode("\n", trim((string) file_get_contents($file))) as $line) {
            $t = (int) $line;
            if ($t > $now - RATE_WINDOW) {
                $hits[] = $t;
            }
        }
    }

    if (count($hits) >= RATE_LIMIT) {
        return true;
    }

    $hits[] = $now;
    @file_put_contents($file, implode("\n", $hits), LOCK_EX);

    return false;
}

/** Trims, strips control characters (header injection) and caps length. */
function field($value, int $max): string
{
    if (!is_string($value)) {
        return '';
    }
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $value);

    return mb_substr(trim((string) $value), 0, $max);
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function sendViaResend(string $key, array $payload): array
{
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);

    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return [false, 'curl: ' . $err];
    }
    if ($code < 200 || $code >= 300) {
        return [false, 'resend ' . $code . ': ' . (string) $body];
    }

    return [true, ''];
}

function sendViaMail(string $to, string $subject, string $html, string $replyTo): bool
{
    $headers = [
        'From: SMB Mexico <' . SENDER . '>',
        'Reply-To: ' . $replyTo,
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
    ];

    return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, implode("\r\n", $headers));
}

// ── Input ──────────────────────────────────────────────────────────────────

$data = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($data)) {
    respond(400, ['message' => 'Validation error']);
}

// Honeypot filled → bot. Pretend success so it does not retry.
if (!empty($data['website'])) {
    respond(201, ['ok' => true]);
}

$lead = [
    'name'        => field($data['name'] ?? null, 200),
    'email'       => field($data['email'] ?? null, 200),
    'phone'       => field($data['phone'] ?? null, 40),
    'role'        => field($data['role'] ?? null, 40),
    'projectType' => field($data['projectType'] ?? null, 40),
    'message'     => field($data['message'] ?? null, 5000),
    'language'    => field($data['language'] ?? null, 2),
];
if (!in_array($lead['language'], ['es', 'pl', 'en'], true)) {
    $lead['language'] = 'es';
}

if ($lead['name'] === '' || $lead['role'] === '' || !filter_var($lead['email'], FILTER_VALIDATE_EMAIL)) {
    respond(400, ['message' => 'Validation error']);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (rateLimited($ip)) {
    respond(429, ['message' => 'Too many requests']);
}

// ── Record first ───────────────────────────────────────────────────────────

$home = privateDir();
$record = ['createdAt' => date('c')] + $lead + ['ip' => $ip];
@file_put_contents(
    $home . '/smb-leads.jsonl',
    json_encode($record, JSON_UNESCAPED_UNICODE) . "\n",
    FILE_APPEND | LOCK_EX
);

// ── Owner notification ─────────────────────────────────────────────────────

$roleLabels = [
    'architect'   => 'Architekt',
    'developer'   => 'Deweloper',
    'contractor'  => 'Wykonawca',
    'distributor' => 'Dystrybutor',
    'other'       => 'Inne',
    'otro'        => 'Inne',
];
$projectLabels = [
    'residential' => 'Residential',
    'commercial'  => 'Commercial',
    'hospitality' => 'Hospitality',
];

$row = static function (string $label, string $value): string {
    return '<tr><td style="padding:10px 0;border-bottom:1px solid #e2e8f0;color:#64748b;width:140px;">' . $label
        . '</td><td style="padding:10px 0;border-bottom:1px solid #e2e8f0;color:#1e293b;">' . $value . '</td></tr>';
};

$rows = $row('Imię i nazwisko', h($lead['name']))
    . $row('Email', '<a href="mailto:' . h($lead['email']) . '" style="color:#1A365D;">' . h($lead['email']) . '</a>')
    . $row('Telefon', h($lead['phone'] !== '' ? $lead['phone'] : '—'))
    . $row('Rola', h($roleLabels[$lead['role']] ?? $lead['role']))
    . ($lead['projectType'] !== '' ? $row('Typ projektu', h($projectLabels[$lead['projectType']] ?? $lead['projectType'])) : '')
    . ($lead['message'] !== '' ? $row('Wiadomość', nl2br(h($lead['message']))) : '')
    . $row('Język', strtoupper($lead['language']));

$ownerHtml = '<div style="font-family:\'Segoe UI\',Arial,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;">'
    . '<div style="background:#1A365D;padding:24px 32px;"><h1 style="color:#ffffff;margin:0;font-size:20px;font-weight:400;">Simple Made Blinds Mexico</h1></div>'
    . '<div style="padding:32px;"><h2 style="color:#1A365D;font-size:18px;margin:0 0 24px;">Nowe zapytanie z formularza</h2>'
    . '<table style="width:100%;border-collapse:collapse;">' . $rows . '</table>'
    . '<p style="margin-top:24px;font-size:13px;color:#94a3b8;">Wiadomość wysłana automatycznie z formularza na stronie ' . SITE . '</p>'
    . '</div></div>';

$ownerSubject = 'Nowe zapytanie od ' . $lead['name'];

$config = @include $home . '/smb-config.php';
$apiKey = is_array($config) && isset($config['resend_api_key']) ? (string) $config['resend_api_key'] : '';

$sent = false;
$problem = 'no resend key';
if ($apiKey !== '') {
    // Reply-To is the customer, so answering the notification writes to them.
    list($sent, $problem) = sendViaResend($apiKey, [
        'from'     => 'SMB Mexico <' . SENDER . '>',
        'to'       => [CONTACT_EMAIL],
        'reply_to' => [$lead['email']],
        'subject'  => $ownerSubject,
        'html'     => $ownerHtml,
    ]);
}
if (!$sent && sendViaMail(CONTACT_EMAIL, $ownerSubject, $ownerHtml, $lead['email'])) {
    $sent = true;
}

if (!$sent) {
    @file_put_contents(
        $home . '/smb-leads-failed.log',
        date('c') . "\t" . $problem . "\t" . json_encode($lead, JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND | LOCK_EX
    );
    // The lead is already in smb-leads.jsonl; the visitor still sees an error
    // so they use WhatsApp or email instead of assuming it went through.
    respond(500, ['message' => 'Internal server error']);
}

// ── Customer auto-reply (Resend only; its failure must not fail the lead) ──

if ($apiKey !== '') {
    $content = [
        'es' => [
            'subject'  => 'Gracias por su consulta',
            'greeting' => 'Hola %s,',
            'body'     => 'Gracias por contactarnos. Hemos recibido su solicitud y nuestro equipo se pondrá en contacto con usted a la brevedad posible.',
            'sub'      => 'Mientras tanto, si tiene alguna pregunta adicional, no dude en enviarla a:',
        ],
        'pl' => [
            'subject'  => 'Dziękujemy za zapytanie',
            'greeting' => 'Dzień dobry %s,',
            'body'     => 'Dziękujemy za kontakt. Otrzymaliśmy Twoje zapytanie i nasz zespół skontaktuje się z Tobą najszybciej jak to możliwe.',
            'sub'      => 'W międzyczasie, jeśli masz dodatkowe pytania, wyślij je na adres:',
        ],
        'en' => [
            'subject'  => 'Thank you for your inquiry',
            'greeting' => 'Hello %s,',
            'body'     => 'Thank you for contacting us. We have received your request and our team will get back to you as soon as possible.',
            'sub'      => 'In the meantime, if you have any additional questions, feel free to send them to:',
        ],
    ];
    $t = $content[$lead['language']];
    $firstName = explode(' ', $lead['name'])[0];

    $customerHtml = '<div style="font-family:\'Segoe UI\',Arial,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">'
        . '<div style="background:#1A365D;padding:22px;text-align:center;"><img src="https://' . SITE . '/logo-email.png" alt="Simple Made Blinds Mexico" style="max-width:175px;height:auto;display:block;margin:0 auto;" /></div>'
        . '<div style="padding:40px 32px;">'
        . '<h2 style="color:#1A365D;font-size:20px;margin:0 0 20px;font-weight:600;">' . h(sprintf($t['greeting'], $firstName)) . '</h2>'
        . '<p style="color:#334155;line-height:1.6;margin:0 0 20px;font-size:16px;">' . $t['body'] . '</p>'
        . '<p style="color:#334155;line-height:1.6;margin:0 0 24px;font-size:16px;">' . $t['sub']
        . ' <a href="mailto:' . CONTACT_EMAIL . '" style="color:#1A365D;text-decoration:underline;font-weight:500;">' . CONTACT_EMAIL . '</a></p>'
        . '<div style="border-top:1px solid #e2e8f0;margin-top:32px;padding-top:24px;"><p style="color:#94a3b8;font-size:14px;margin:0;line-height:1.5;">'
        . '<strong style="color:#64748b;">Simple Made Blinds Mexico</strong><br />European-engineered shading solutions<br />'
        . '<a href="https://' . SITE . '" style="color:#1A365D;text-decoration:none;">' . SITE . '</a></p></div>'
        . '</div></div>';

    // Resend allows 2 requests per second; the Express version waited 3 s.
    sleep(1);
    sendViaResend($apiKey, [
        'from'     => 'Simple Made Blinds Mexico <' . SENDER . '>',
        'to'       => [$lead['email']],
        'reply_to' => [CONTACT_EMAIL],
        'subject'  => $t['subject'],
        'html'     => $customerHtml,
    ]);
}

respond(201, ['ok' => true]);
