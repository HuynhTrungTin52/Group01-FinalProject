<?php
session_start();
header('Content-Type: application/json');

// Auth
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once 'db.php';

// ---- Gmail SMTP credentials (CHANGE THESE) ----
const GMAIL_USER         = 'justintraneg@gmail.com';
const GMAIL_APP_PASSWORD = 'whel tikj tqkn hkme';
const GMAIL_FROM_NAME    = 'E-Wallet Security';

// ---- OTP policy ----
const OTP_TTL_SECONDS       = 300;  // 5 min
const OTP_RESEND_COOLDOWN   = 30;   // 30s between resends
const OTP_MAX_ATTEMPTS      = 5;

// ---- Load PHPMailer (Composer first, manual fallback) ----
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    $base = __DIR__ . '/PHPMailer/src';
    if (!file_exists("$base/PHPMailer.php")) {
        echo json_encode(['ok' => false, 'error' => 'PHPMailer not installed. Run "composer require phpmailer/phpmailer" or drop PHPMailer/src into the project root.']);
        exit;
    }
    require_once "$base/Exception.php";
    require_once "$base/PHPMailer.php";
    require_once "$base/SMTP.php";
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\SMTP;

// ---- Rate limit: cooldown between resends ----
$now = time();
if (isset($_SESSION['transfer_otp_last_sent']) &&
    ($now - (int)$_SESSION['transfer_otp_last_sent']) < OTP_RESEND_COOLDOWN) {
    $wait = OTP_RESEND_COOLDOWN - ($now - (int)$_SESSION['transfer_otp_last_sent']);
    echo json_encode(['ok' => false, 'error' => "Please wait {$wait}s before requesting another OTP."]);
    exit;
}

// ---- Lookup user email ----
try {
    $stmt = $pdo->prepare("SELECT full_name, email FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Database error']);
    exit;
}
if (!$user || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'User email not found']);
    exit;
}

// ---- Generate OTP ----
$otp = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);

// ---- Send email via Gmail SMTP ----
$mail = new PHPMailer(true);
try {
    // SMTP
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = GMAIL_USER;
    $mail->Password   = GMAIL_APP_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // SSL on 465 (use STARTTLS + 587 if your network blocks 465)
    $mail->Port       = 465;
    $mail->CharSet    = 'UTF-8';

    // Headers
    $mail->setFrom(GMAIL_USER, GMAIL_FROM_NAME);
    $mail->addAddress($user['email'], $user['full_name']);
    $mail->addReplyTo(GMAIL_USER, GMAIL_FROM_NAME);

    // Body
    $mail->isHTML(true);
    $mail->Subject = "Your E-Wallet Transfer OTP: {$otp}";
    $mail->Body    = build_otp_html($user['full_name'], $otp, OTP_TTL_SECONDS);
    $mail->AltBody = "Your E-Wallet transfer OTP is: {$otp}\nThis code expires in 5 minutes.\nIf you did not request this, please ignore.";

    $mail->send();
} catch (Exception $e) {
    error_log('[send_transfer_otp] ' . $mail->ErrorInfo);
    echo json_encode(['ok' => false, 'error' => 'Failed to send OTP email. Please try again.']);
    exit;
}

// ---- Store in session AFTER successful send ----
$_SESSION['transfer_otp']           = $otp;
$_SESSION['transfer_otp_expires']   = $now + OTP_TTL_SECONDS;
$_SESSION['transfer_otp_attempts']  = 0;
$_SESSION['transfer_otp_last_sent'] = $now;

$mask_email = mask_email($user['email']);

echo json_encode([
    'ok'         => true,
    'expires_in' => OTP_TTL_SECONDS,
    'sent_to'    => $mask_email,
]);
exit;

// ---------------- helpers ----------------
function mask_email(string $email): string {
    [$local, $domain] = explode('@', $email, 2);
    $len = strlen($local);
    if ($len <= 2) return $local[0] . '***@' . $domain;
    return $local[0] . str_repeat('*', max(1, $len - 2)) . substr($local, -1) . '@' . $domain;
}

function build_otp_html(string $full_name, string $otp, int $ttl): string {
    $minutes = (int) round($ttl / 60);
    $name_safe = htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<!DOCTYPE html>
<html><body style="margin:0;padding:0;background:#E8F4F8;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#E8F4F8;padding:30px 0;">
    <tr><td align="center">
      <table width="480" cellpadding="0" cellspacing="0"
             style="background:#fff;border-radius:18px;padding:32px;box-shadow:0 4px 20px rgba(0,0,0,.06);">
        <tr><td>
          <h1 style="font-size:22px;color:#1F1F2E;margin:0 0 8px;">Confirm Your Transfer</h1>
          <p style="color:#6B7280;font-size:14px;margin:0 0 24px;">
            Hi <strong>{$name_safe}</strong>, use the code below to confirm your money transfer.
          </p>

          <div style="background:#DCDFF7;border-radius:14px;padding:24px;text-align:center;margin-bottom:20px;">
            <div style="color:#3D3D8C;font-size:13px;font-weight:600;margin-bottom:8px;letter-spacing:1px;">YOUR OTP CODE</div>
            <div style="font-size:42px;font-weight:900;color:#2D2D7C;letter-spacing:14px;">{$otp}</div>
          </div>

          <p style="color:#6B7280;font-size:13px;line-height:1.55;margin:0 0 8px;">
            This code expires in <strong>{$minutes} minutes</strong>.
          </p>
          <p style="color:#DC2626;font-size:13px;line-height:1.55;margin:0;">
            If you did not request this, please ignore this email and review your account security.
          </p>

          <hr style="border:none;border-top:1px solid #E5E7EB;margin:24px 0;">
          <p style="color:#9CA3AF;font-size:11px;margin:0;">
            E-Wallet Security — automated message, please do not reply.
          </p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;
}
