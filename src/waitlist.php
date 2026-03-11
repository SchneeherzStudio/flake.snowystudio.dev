<?php
/**
 * FlakeSecure Waitlist Handler
 * ─────────────────────────────
 * Speichert Email-Adressen in waitlist.csv und
 * schickt eine Bestätigungsmail via IONOS SMTP.
 *
 * SETUP:
 *   1. Lege ../config/.env (eine Ebene ÜBER httpdocs/) an
 *   2. Lade waitlist.php + PHPMailer/ in httpdocs/
 *   3. Passe $allowed_origin unten an
 */

// ── .ENV LADEN (außerhalb des Webroots) ────────────────────────────────────
$env_path = dirname(__DIR__) . '/config/.env';

if (!file_exists($env_path)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server configuration missing.']);
    error_log('FlakeSecure: .env file not found at ' . $env_path);
    exit;
}

// Einfacher .env Parser (KEY=VALUE, # Kommentare erlaubt)
foreach (file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$key, $val] = explode('=', $line, 2);
    $_ENV[trim($key)] = trim($val);
}

function env(string $key, string $default = ''): string {
    return $_ENV[$key] ?? $default;
}

// ── CORS (nur eigene Domain erlauben) ──────────────────────────────────────
$allowed_origin = 'https://snowystudio.dev'; // ← anpassen
if (isset($_SERVER['HTTP_ORIGIN'])) {
    if ($_SERVER['HTTP_ORIGIN'] === $allowed_origin) {
        header("Access-Control-Allow-Origin: $allowed_origin");
    }
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// ── KONFIGURATION (aus .env) ───────────────────────────────────────────────
define('SMTP_HOST',     'smtp.ionos.de');
define('SMTP_PORT',     587);
define('SMTP_USER',     env('MAIL_USER'));
define('SMTP_PASS',     env('MAIL_PASS'));
define('FROM_NAME',     'FlakeSecure by SnowyStudio');
define('FROM_EMAIL',    env('MAIL_FROM'));
define('NOTIFY_EMAIL',  env('MAIL_NOTIFY'));
// CSV liegt AUCH außerhalb des Webroots — nicht öffentlich erreichbar
define('CSV_FILE',      dirname(__DIR__) . '/config/waitlist.csv');

// ── INPUT VALIDIERUNG ──────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
$email = isset($body['email']) ? trim(strtolower($body['email'])) : '';

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

// Max 255 Zeichen
if (strlen($email) > 255) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email address too long.']);
    exit;
}

// ── DUPLIKAT-CHECK ─────────────────────────────────────────────────────────
if (file_exists(CSV_FILE)) {
    $existing = array_map('str_getcsv', file(CSV_FILE));
    foreach ($existing as $row) {
        if (isset($row[0]) && strtolower(trim($row[0])) === $email) {
            echo json_encode(['success' => true, 'message' => 'You\'re already on the list! We\'ll be in touch.']);
            exit;
        }
    }
}

// ── IN CSV SPEICHERN ───────────────────────────────────────────────────────
$timestamp = date('Y-m-d H:i:s');
$ip_hash   = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''); // gehashte IP (DSGVO)
$csv_line  = implode(',', [
    $email,
    $timestamp,
    $ip_hash
]) . PHP_EOL;

if (file_put_contents(CSV_FILE, $csv_line, FILE_APPEND | LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save your email. Please try again.']);
    exit;
}

// ── PHPMAILER LADEN ────────────────────────────────────────────────────────
// PHPMailer via Composer oder manuell:
// https://github.com/PHPMailer/PHPMailer/releases
// Dateien in ./PHPMailer/ ablegen
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ── BESTÄTIGUNGSMAIL AN USER ───────────────────────────────────────────────
function sendConfirmation(string $to): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        $mail->addAddress($to);
        $mail->addReplyTo(FROM_EMAIL, FROM_NAME);

        $mail->isHTML(true);
        $mail->Subject = "You're on the FlakeSecure waitlist ❄️";
        $mail->Body    = getConfirmationHtml($to);
        $mail->AltBody = getConfirmationText($to);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('FlakeSecure Waitlist - Confirmation mail failed: ' . $mail->ErrorInfo);
        return false;
    }
}

// ── BENACHRICHTIGUNG AN DICH ───────────────────────────────────────────────
function sendNotification(string $newEmail, string $timestamp): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        $mail->addAddress(NOTIFY_EMAIL);

        $mail->isHTML(false);
        $mail->Subject = "[FlakeSecure] New waitlist signup: $newEmail";
        $mail->Body    = "New waitlist signup\n\nEmail: $newEmail\nTime:  $timestamp\n\nLog in to check the full list.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('FlakeSecure Waitlist - Notification mail failed: ' . $mail->ErrorInfo);
        return false;
    }
}

sendConfirmation($email);
sendNotification($email, $timestamp);

echo json_encode([
    'success' => true,
    'message' => "You're on the list! Check your inbox for a confirmation email."
]);

// ── EMAIL TEMPLATES ────────────────────────────────────────────────────────
function getConfirmationHtml(string $email): string {
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>You're on the FlakeSecure waitlist</title>
</head>
<body style="margin:0;padding:0;background:#0a0a0b;font-family:'Helvetica Neue',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0b;padding:40px 20px;">
    <tr><td align="center">
      <table width="560" cellpadding="0" cellspacing="0" style="background:#131315;border:1px solid rgba(255,255,255,0.08);border-radius:16px;overflow:hidden;max-width:560px;width:100%;">

        <!-- Header -->
        <tr>
          <td style="background:linear-gradient(135deg,rgba(212,168,67,0.12),rgba(10,10,11,0));padding:36px 40px 28px;border-bottom:1px solid rgba(255,255,255,0.07);">
            <table cellpadding="0" cellspacing="0">
              <tr>
                <td style="background:#e03535;border-radius:8px;width:36px;height:36px;text-align:center;vertical-align:middle;font-size:16px;">❄</td>
                <td style="padding-left:10px;font-size:18px;font-weight:700;color:#ffffff;letter-spacing:-0.3px;">Flake <span style="color:rgba(255,255,255,0.35);font-weight:300;font-size:13px;">by SnowyStudio</span></td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:36px 40px;">
            <p style="margin:0 0 8px;font-size:26px;font-weight:700;color:#ffffff;letter-spacing:-0.8px;">You're on the list. 🛡</p>
            <p style="margin:0 0 24px;font-size:15px;color:#7a7a85;line-height:1.6;font-weight:300;">
              Thanks for joining the <strong style="color:#d4a843">FlakeSecure</strong> early access waitlist.
              We'll reach out as soon as private beta spots open up.
            </p>

            <!-- What to expect box -->
            <table width="100%" cellpadding="0" cellspacing="0" style="background:rgba(212,168,67,0.07);border:1px solid rgba(212,168,67,0.2);border-radius:12px;margin-bottom:28px;">
              <tr><td style="padding:20px 24px;">
                <p style="margin:0 0 12px;font-size:11px;font-weight:600;letter-spacing:0.1em;text-transform:uppercase;color:#4a4a55;">What's coming</p>
                <table cellpadding="0" cellspacing="0" width="100%">
                  <tr><td style="padding:5px 0;font-size:13px;color:#7a7a85;font-weight:300;"><span style="color:#d4a843;margin-right:8px;">✦</span>Password-free login for any website</td></tr>
                  <tr><td style="padding:5px 0;font-size:13px;color:#7a7a85;font-weight:300;"><span style="color:#d4a843;margin-right:8px;">✦</span>One-tap mobile approval</td></tr>
                  <tr><td style="padding:5px 0;font-size:13px;color:#7a7a85;font-weight:300;"><span style="color:#d4a843;margin-right:8px;">✦</span>Browser extension for Chrome, Firefox & Edge</td></tr>
                  <tr><td style="padding:5px 0;font-size:13px;color:#7a7a85;font-weight:300;"><span style="color:#d4a843;margin-right:8px;">✦</span>Zero-knowledge security architecture</td></tr>
                </table>
              </td></tr>
            </table>

            <p style="margin:0 0 6px;font-size:13px;color:#4a4a55;font-weight:300;">
              You signed up with: <span style="color:#7a7a85;">{$email}</span>
            </p>
            <p style="margin:0;font-size:13px;color:#4a4a55;font-weight:300;">
              If this wasn't you, you can safely ignore this email.
            </p>
          </td>
        </tr>

        <!-- Also check out FlakeTrader -->
        <tr>
          <td style="padding:0 40px 36px;">
            <table width="100%" cellpadding="0" cellspacing="0" style="background:rgba(34,197,94,0.06);border:1px solid rgba(34,197,94,0.15);border-radius:12px;">
              <tr><td style="padding:18px 22px;">
                <p style="margin:0 0 4px;font-size:13px;font-weight:600;color:#22c55e;">While you wait — try FlakeTrader</p>
                <p style="margin:0;font-size:12px;color:#7a7a85;font-weight:300;">Trade on real markets with fictional currency, right inside Discord. Free to use.</p>
              </td></tr>
            </table>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background:#0e0e10;border-top:1px solid rgba(255,255,255,0.06);padding:20px 40px;">
            <p style="margin:0;font-size:12px;color:#4a4a55;font-weight:300;">
              © 2026 SnowyStudio · All rights reserved<br/>
              <a href="https://flake.snowystudio.dev" style="color:#4a4a55;text-decoration:none;">flake.snowystudio.dev</a>
              &nbsp;·&nbsp;
              <a href="https://flake.snowystudio.dev/privacy" style="color:#4a4a55;text-decoration:none;">Privacy Policy</a>
            </p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

function getConfirmationText(string $email): string {
    return <<<TEXT
You're on the FlakeSecure waitlist ❄️
======================================

Thanks for signing up! We'll reach out as soon as private beta spots open up.

What's coming:
- Password-free login for any website
- One-tap mobile approval
- Browser extension for Chrome, Firefox & Edge
- Zero-knowledge security architecture

You signed up with: {$email}

While you wait, check out FlakeTrader — trade on real markets with fictional
currency inside Discord: https://flake.snowystudio.dev

────────────────────────────────────
© 2026 SnowyStudio · flake.snowystudio.dev
If this wasn't you, ignore this email.
TEXT;
}
