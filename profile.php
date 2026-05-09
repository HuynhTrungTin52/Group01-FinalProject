<?php
$id_status = $user['id_status'] ?? 'unverified';
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once 'db.php';

$default_avatar = "data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23a0aec0'><path d='M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z'/></svg>";
$profile_avatar_src = $default_avatar;

$check_db = $pdo->prepare("SELECT avatar FROM users WHERE user_id = ?");
$check_db->execute([$_SESSION['user_id']]);
$db_user_ava = $check_db->fetch();

if ($db_user_ava && !empty($db_user_ava['avatar'])) {
    $path = 'uploads/avatars/' . $db_user_ava['avatar'];
    if (file_exists($path)) {
        $profile_avatar_src = $path;
    }
}

$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { session_destroy(); header('Location: login.php'); exit; }

function field($user, $key, $default = '—') {
    return (isset($user[$key]) && $user[$key] !== '' && $user[$key] !== null) ? $user[$key] : $default;
}

$status = field($user, 'status', 'unverified');
$is_verified = in_array($status, ['verified', 'completed'], true);

$dob = field($user, 'date_of_birth', '01/01/2000');
if ($dob !== '—' && $dob !== '01/01/2000') {
    $ts = strtotime($dob);
    if ($ts) $dob = date('d/m/Y', $ts);
}

$created = field($user, 'created_at', '');
if ($created && $created !== '—') {
    $ts = strtotime($created);
    if ($ts) $created = date('d/m/Y', $ts);
}

$flash_success = isset($_GET['success']) ? trim($_GET['success']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Profile - E-Wallet</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Mulish:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
    --bg-page:#E8F4F8;
    --primary:#9B95D6;
    --primary-hover:#857FCB;
    --primary-darker:#2D2D7C;
    --text-dark:#1F1F2E;
    --text-muted:#6B7280;
    --border:#E5E7EB;
    --success:#16A34A;
    --divider:#7B8AC4;
}
*{font-family:'Mulish',sans-serif;}
html,body{background:var(--bg-page);color:var(--text-dark);margin:0;}
.app-container{max-width:430px;margin:0 auto;min-height:100vh;background:var(--bg-page);padding:24px 18px 40px;}

.card-block{background:#fff;border-radius:20px;padding:18px 18px;box-shadow:0 2px 12px rgba(0,0,0,.04);margin-bottom:16px;}

.header-card .top{display:flex;align-items:center;gap:14px;padding-bottom:14px;border-bottom:1.5px solid var(--divider);}
.avatar{width:60px;height:60px;border-radius:50%;background:#E5EBF7;display:flex;align-items:center;justify-content:center;color:var(--primary-darker);font-size:26px;flex-shrink:0;}
.u-name{font-weight:900;font-size:17px;line-height:1.1;}
.u-email{font-size:13px;color:var(--text-muted);margin-top:2px;}
.verified-tag{display:inline-flex;align-items:center;gap:4px;color:var(--success);font-size:12px;font-weight:700;margin-top:4px;}
.unverified-tag{display:inline-flex;align-items:center;gap:4px;color:#D97706;font-size:12px;font-weight:700;margin-top:4px;}

.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 18px;padding-top:14px;}
.info-item .lbl{display:flex;align-items:center;gap:6px;color:var(--text-muted);font-size:12px;font-weight:600;margin-bottom:4px;}
.info-item .val{font-weight:700;font-size:14px;color:var(--text-dark);word-break:break-word;}
.info-item.full{grid-column:span 2;}

.section-title{font-weight:800;font-size:15px;margin:6px 0 12px;}

.link-row{display:flex;align-items:center;justify-content:space-between;background:#EEE8EA;border-radius:12px;padding:12px 14px;text-decoration:none;color:var(--text-dark);margin-bottom:10px;transition:background .15s ease;}
.link-row:last-child{margin-bottom:0;}
.link-row:hover{background:#E2DCDE;color:var(--text-dark);}
.link-row .lr-title{font-weight:700;font-size:14px;}
.link-row .lr-sub{font-size:12px;color:var(--text-muted);margin-top:2px;}
.link-row .lr-arrow{color:#1F1F2E;font-size:18px;}

.info-row{display:flex;align-items:center;justify-content:space-between;background:#EEE8EA;border-radius:12px;padding:12px 14px;margin-bottom:10px;}
.info-row:last-child{margin-bottom:0;}
.info-row .ir-key{font-weight:700;font-size:14px;}
.info-row .ir-val{font-weight:700;font-size:13px;}
.info-row .ir-val.verified{color:var(--success);}
.info-row .ir-val.unverified{color:#D97706;}

.btn-home{background:var(--primary);color:#fff;border:none;height:52px;border-radius:14px;font-weight:900;font-size:17px;width:100%;margin-top:10px;text-decoration:none;display:flex;align-items:center;justify-content:center;transition:background .15s ease;}
.btn-home:hover{background:var(--primary-hover);color:#fff;}

.alert-floating{position:fixed;top:18px;left:50%;transform:translateX(-50%);z-index:1080;min-width:280px;max-width:90%;border-radius:12px;}
</style>
</head>
<body>
<div class="app-container" data-testid="profile-page">

<?php if($flash_success): ?>
<div class="alert alert-success alert-floating shadow-sm" data-testid="success-alert">
    <i class="bi bi-check-circle-fill me-1"></i><?= htmlspecialchars($flash_success) ?>
</div>
<?php endif; ?>

<div class="card-block header-card" data-testid="profile-header">
    <div class="top">
        <img src="<?= $profile_avatar_src ?>" alt="Avatar" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 3px solid #fff; box-shadow: 0 4px 6px rgba(0,0,0,0.1); background-color: #f1f5f9;">
        <div class="flex-grow-1">
            <div class="u-name" data-testid="profile-name"><?= htmlspecialchars($user['full_name']) ?></div>
            <div class="u-email" data-testid="profile-email"><?= htmlspecialchars($user['email']) ?></div>
            <?php if ($is_verified): ?>
                <div class="verified-tag"><i class="bi bi-check-circle-fill"></i> Verified</div>
            <?php else: ?>
                <div class="unverified-tag"><i class="bi bi-exclamation-circle-fill"></i> Unverified</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="info-grid">
        <div class="info-item">
            <div class="lbl"><i class="bi bi-envelope"></i> Email</div>
            <div class="val" data-testid="info-email"><?= htmlspecialchars($user['email']) ?></div>
        </div>
        <div class="info-item">
            <div class="lbl"><i class="bi bi-telephone"></i> Phone Number</div>
            <div class="val" data-testid="info-phone"><?= htmlspecialchars(field($user, 'phone')) ?></div>
        </div>
        <div class="info-item">
            <div class="lbl"><i class="bi bi-calendar3"></i> Date of Birth</div>
            <div class="val" data-testid="info-dob"><?= htmlspecialchars($dob) ?></div>
        </div>
        <div class="info-item">
            <div class="lbl"><i class="bi bi-credit-card"></i> Account Number</div>
            <div class="val" data-testid="info-account"><?= htmlspecialchars(field($user, 'phone')) ?></div>
        </div>
        <div class="info-item full">
            <div class="lbl"><i class="bi bi-geo-alt"></i> Address</div>
            <div class="val" data-testid="info-address"><?= htmlspecialchars(field($user, 'address', 'Not provided')) ?></div>
        </div>
    </div>
</div>

<div class="card-block">
    <h3 class="section-title">Account Security</h3>
    <a href="change_password.php" class="link-row" data-testid="link-change-password">
        <div>
            <div class="lr-title">Change password</div>
            <div class="lr-sub">Update password to secure your account</div>
        </div>
        <i class="bi bi-chevron-right lr-arrow"></i>
    </a>
    <a href="<?= ($status === 'verified') ? 'javascript:void(0)' : 'update_id.php' ?>" 
        class="link-row" 
        data-testid="link-update-id"
        style="<?= ($status === 'verified') ? 'opacity: 0.5; pointer-events: none; filter: grayscale(100%);' : '' ?>">
        <div>
            <div class="lr-title">Update ID</div>
            <div class="lr-sub">Update ID to secure your account</div>
        </div>
        <i class="bi bi-chevron-right lr-arrow"></i>
    </a>
</div>

<div class="card-block">
    <h3 class="section-title">Account Information</h3>
    <div class="info-row">
        <span class="ir-key">Account creation date</span>
        <span class="ir-val" data-testid="info-created"><?= htmlspecialchars($created ?: '—') ?></span>
    </div>
    <div class="info-row">
        <span class="ir-key">State</span>
        <span class="ir-val <?= $is_verified ? 'verified' : 'unverified' ?>" data-testid="info-state">
            <?= $is_verified ? 'Verified' : 'Unverified' ?>
        </span>
    </div>
</div>

<a href="dashboard_user.php" class="btn-home" data-testid="btn-home">Home</a>

</div>

<script>
setTimeout(()=>{document.querySelectorAll('.alert-floating').forEach(a=>a.remove());},3500);
formData.append('file_upload', file);
formData.append('type', 'avatar'); 
</script>
</body>
</html>