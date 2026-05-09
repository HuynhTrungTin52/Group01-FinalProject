<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once 'db.php';

$user_id = $_SESSION['user_id'];
$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old_pw     = $_POST['old_password']     ?? '';
    $new_pw     = $_POST['new_password']     ?? '';
    $confirm_pw = $_POST['confirm_password'] ?? '';

    $stmt = $pdo->prepare("SELECT password FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { session_destroy(); header('Location: login.php'); exit; }

    $stored = $row['password'];
    $old_ok = password_verify($old_pw, $stored) || ($stored !== '' && hash_equals($stored, $old_pw));

    if (!$old_ok)                                        $errors[] = 'Old password is incorrect.';
    if (strlen($new_pw) < 6)                             $errors[] = 'New password must be at least 6 characters.';
    if (!preg_match('/[A-Z]/', $new_pw))                 $errors[] = 'New password must include at least one uppercase letter.';
    if (!preg_match('/[a-z]/', $new_pw))                 $errors[] = 'New password must include at least one lowercase letter.';
    if (!preg_match('/\d/', $new_pw))                    $errors[] = 'New password must include at least one number.';
    if (!preg_match('/[^A-Za-z0-9]/', $new_pw))          $errors[] = 'New password must include at least one special character.';
    if ($new_pw !== $confirm_pw)                         $errors[] = 'New password and confirmation do not match.';
    if ($old_ok && $new_pw !== '' && $new_pw === $old_pw) $errors[] = 'New password must be different from old password.';

    $weak = ['password','12345678','qwerty','abc123','admin123','letmein','111111','123456789'];
    if (in_array(strtolower($new_pw), $weak, true))      $errors[] = 'Please do not use easy-to-guess passwords.';

    if (empty($errors)) {
        $hash = password_hash($new_pw, PASSWORD_BCRYPT);
        $upd = $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $upd->execute([$hash, $user_id]);
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Change Password - E-Wallet</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Mulish:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
    --bg-page:#E8F4F8;
    --primary:#9B95D6;
    --primary-hover:#857FCB;
    --primary-dark:#3D3D8C;
    --info-bg:#DCDFF7;
    --text-dark:#1F1F2E;
    --text-muted:#6B7280;
    --border:#E5E7EB;
    --danger:#DC2626;
    --success:#16A34A;
}
*{font-family:'Mulish',sans-serif;}
html,body{background:var(--bg-page);color:var(--text-dark);margin:0;}
.app-container{max-width:430px;margin:0 auto;min-height:100vh;background:var(--bg-page);padding:28px 18px 40px;}
.page-title{font-weight:900;font-size:32px;letter-spacing:-1px;margin-bottom:2px;}
.page-sub{color:var(--text-muted);font-size:13px;margin-bottom:20px;}
.form-card{background:#fff;border-radius:22px;padding:22px 20px;box-shadow:0 4px 20px rgba(0,0,0,.05);}
.form-label{font-weight:600;font-size:13px;color:#1F1F2E;margin-bottom:6px;}
.input-wrap{position:relative;margin-bottom:14px;}
.input-wrap .form-control{border:1.5px solid var(--border);border-radius:12px;padding:12px 44px 12px 44px;font-size:14px;font-weight:500;height:48px;}
.input-wrap .form-control:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(155,149,214,.2);}
.input-wrap .icon-left{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#9CA3AF;font-size:16px;pointer-events:none;}
.input-wrap .eye-toggle{position:absolute;right:14px;top:50%;transform:translateY(-50%);color:#9CA3AF;font-size:16px;cursor:pointer;background:transparent;border:none;padding:0;}
.req-box{background:var(--info-bg);border-radius:12px;padding:14px 16px;color:var(--primary-dark);font-size:12.5px;margin-top:8px;}
.req-box .rb-title{display:flex;align-items:center;gap:6px;font-weight:700;margin-bottom:6px;font-size:13px;}
.req-box ol{padding-left:18px;margin:0;}
.req-box li{margin-bottom:2px;}
.req-box li.met{color:var(--success);}
.btn-row{display:flex;gap:10px;margin-top:18px;}
.btn-row .btn{flex:1;height:48px;border-radius:14px;font-weight:700;font-size:15px;}
.btn-cancel{background:#fff;border:1.5px solid var(--border);color:#1F1F2E;}
.btn-primary-c{background:var(--primary);color:#fff;border:none;}
.btn-primary-c:hover{background:var(--primary-hover);color:#fff;}
.alert-list{margin-bottom:14px;border-radius:12px;font-size:13px;}
</style>
</head>
<body>
<div class="app-container" data-testid="change-password-page">
    <h1 class="page-title" data-testid="change-password-title">Change Password</h1>
    <div class="page-sub">Update password to secure your account</div>

    <?php if ($success): ?>
    <div class="alert alert-success alert-list" data-testid="success-alert">
        <i class="bi bi-check-circle-fill me-1"></i> Password changed successfully.
    </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-list" data-testid="error-alert">
        <ul class="mb-0 ps-3">
            <?php foreach($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form class="form-card" method="POST" action="change_password.php" id="pwForm" data-testid="password-form" autocomplete="off">
        <label class="form-label">Old password *</label>
        <div class="input-wrap">
            <i class="bi bi-lock-fill icon-left"></i>
            <input type="password" name="old_password" class="form-control" required data-testid="input-old-password" id="oldPw">
            <button type="button" class="eye-toggle" data-target="oldPw" data-testid="toggle-old"><i class="bi bi-eye-slash"></i></button>
        </div>

        <label class="form-label">New password *</label>
        <div class="input-wrap">
            <i class="bi bi-lock-fill icon-left"></i>
            <input type="password" name="new_password" class="form-control" required data-testid="input-new-password" id="newPw">
            <button type="button" class="eye-toggle" data-target="newPw" data-testid="toggle-new"><i class="bi bi-eye-slash"></i></button>
        </div>

        <label class="form-label">Confirm new password *</label>
        <div class="input-wrap">
            <i class="bi bi-lock-fill icon-left"></i>
            <input type="password" name="confirm_password" class="form-control" required data-testid="input-confirm-password" id="confirmPw">
            <button type="button" class="eye-toggle" data-target="confirmPw" data-testid="toggle-confirm"><i class="bi bi-eye-slash"></i></button>
        </div>

        <div class="req-box" data-testid="password-requirements">
            <div class="rb-title"><i class="bi bi-clipboard-check"></i> Password requirement:</div>
            <ol>
                <li id="req-length">At least 6 characters</li>
                <li id="req-mix">Should include uppercase, lowercase, numbers and special characters</li>
                <li id="req-weak">Do not use easy-to-guess passwords</li>
            </ol>
        </div>

        <div class="btn-row">
            <a href="profile.php" class="btn btn-cancel" data-testid="btn-cancel">Cancel</a>
            <button type="submit" class="btn btn-primary-c" data-testid="btn-submit">Change</button>
        </div>
    </form>
</div>

<script>
document.querySelectorAll('.eye-toggle').forEach(btn=>{
    btn.addEventListener('click', ()=>{
        const input = document.getElementById(btn.dataset.target);
        const ic = btn.querySelector('i');
        if (input.type === 'password') { input.type = 'text'; ic.className = 'bi bi-eye'; }
        else { input.type = 'password'; ic.className = 'bi bi-eye-slash'; }
    });
});

const newPw = document.getElementById('newPw');
const weak = ['password','12345678','qwerty','abc123','admin123','letmein','111111','123456789'];
newPw.addEventListener('input', ()=>{
    const v = newPw.value;
    const hasLen = v.length >= 6;
    const hasMix = /[A-Z]/.test(v) && /[a-z]/.test(v) && /\d/.test(v) && /[^A-Za-z0-9]/.test(v);
    const notWeak = v.length > 0 && !weak.includes(v.toLowerCase());
    document.getElementById('req-length').classList.toggle('met', hasLen);
    document.getElementById('req-mix').classList.toggle('met', hasMix);
    document.getElementById('req-weak').classList.toggle('met', notWeak);
});
</script>
</body>
</html>