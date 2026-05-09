<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once 'db.php';

$stmt_user = $pdo->prepare("SELECT * FROM users WHERE user_id = :id");
$stmt_user->execute([':id' => $_SESSION['user_id']]);
$user = $stmt_user->fetch(); 

$user_id = $_SESSION['user_id'];

// Fetch current user
$stmt = $pdo->prepare("SELECT user_id, full_name, avatar, email, phone, balance, status, is_permanently_locked FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Fetch recent transactions (sender or recipient view)
$stmt = $pdo->prepare("
    SELECT t.*,
           CASE WHEN t.user_id = :uid THEN 'out' ELSE 'in' END AS direction
    FROM transactions t
    WHERE t.user_id = :uid OR t.recipient_id = :uid
    ORDER BY t.created_at DESC
    LIMIT 5
");
$stmt->execute(['uid' => $user_id]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$success_msg = isset($_GET['success']) ? trim($_GET['success']) : '';
$error_msg   = isset($_GET['error'])   ? trim($_GET['error'])   : '';

function fmt_vnd($amount) {
    return number_format((float)$amount, 0, ',', '.') . ' VND';
}

function tx_label($type) {
    switch ($type) {
        case 'deposit':  return 'Deposit';
        case 'withdraw': return 'Withdraw';
        case 'transfer': return 'Transfer';
        case 'buy_card': return 'Buy Card';
        default: return ucfirst($type);
    }
}

function tx_icon($type) {
    switch ($type) {
        case 'deposit':  return 'bi-box-arrow-in-down';
        case 'withdraw': return 'bi-box-arrow-down';
        case 'transfer': return 'bi-arrow-left-right';
        case 'buy_card': return 'bi-phone';
        default: return 'bi-receipt';
    }
}

function status_label($status) {
    switch ($status) {
        case 'completed': return ['Successful', 'success'];
        case 'pending':   return ['Pending', 'pending'];
        case 'failed':    return ['Failed', 'failed'];
        default: return [ucfirst($status), 'pending'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard - E-Wallet</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Mulish:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
    --bg-page:#E8F4F8;
    --card-white:#FFFFFF;
    --primary:#9B95D6;
    --primary-hover:#857FCB;
    --primary-dark:#3D3D8C;
    --lavender-card:#B8C5E8;
    --lavender-card-2:#7B8AC4;
    --success:#16A34A;
    --success-bg:#D4F5DD;
    --danger:#DC2626;
    --warning:#E69100;
    --warning-bg:#FFF1C9;
    --info-bg:#DCDFF7;
    --text-dark:#1F1F2E;
    --text-muted:#6B7280;
    --border:#E5E7EB;
}
*{font-family:'Mulish',sans-serif;}
html,body{background:var(--bg-page);color:var(--text-dark);margin:0;padding:0;}
.app-container{max-width:430px;margin:0 auto;min-height:100vh;background:var(--bg-page);padding:20px 18px 110px;}
.top-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;}
.page-title{font-weight:900;font-size:30px;letter-spacing:-0.5px;}
.btn-logout{width:48px;height:48px;border-radius:50%;background:var(--primary);border:none;color:#fff;display:flex;align-items:center;justify-content:center;font-size:20px;box-shadow:0 4px 12px rgba(155,149,214,.4);transition:transform .15s ease;text-decoration:none;}
.btn-logout:hover{transform:scale(1.05);background:var(--primary-hover);color:#fff;}
.balance-card{background:var(--lavender-card);border-radius:18px;padding:18px 20px 0;color:#fff;box-shadow:0 4px 14px rgba(123,138,196,.25);overflow:hidden;}
.balance-top{display:flex;align-items:center;justify-content:space-between;}
.user-row{display:flex;align-items:center;gap:12px;}
.user-avatar{width:42px;height:42px;border-radius:50%;background:#E5EBF7;display:flex;align-items:center;justify-content:center;color:var(--primary-dark);font-size:20px;}
.user-name{font-weight:800;font-size:15px;color:#1F1F2E;line-height:1.1;}
.verified{display:inline-flex;align-items:center;gap:4px;font-size:11px;color:#16A34A;font-weight:600;margin-top:2px;}
.balance-actions{display:flex;gap:10px;color:#1F1F2E;font-size:18px;}
.balance-actions i{cursor:pointer;}
.account-row{display:flex;justify-content:space-between;align-items:center;color:#1F1F2E;margin-top:14px;font-size:13px;font-weight:600;}
.balance-row{display:flex;justify-content:space-between;align-items:center;color:#1F1F2E;margin-top:6px;margin-bottom:14px;font-size:14px;font-weight:700;}
.balance-row .label{font-weight:600;}
.balance-amount{font-weight:800;font-size:15px;}
.balance-footer{margin:0 -20px;background:var(--lavender-card-2);padding:10px 20px;display:flex;justify-content:space-between;color:#fff;font-size:12px;font-weight:600;}
.balance-footer a{color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
.balance-footer a:hover{opacity:.85;}
.section-title{font-weight:800;font-size:18px;margin:22px 0 14px;}
.menu-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:24px;}
.menu-item{display:flex;flex-direction:column;align-items:center;gap:8px;text-decoration:none;color:var(--text-dark);}
.menu-icon{width:56px;height:56px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-size:24px;box-shadow:0 4px 10px rgba(155,149,214,.35);transition:transform .15s ease;}
.menu-item:hover .menu-icon{transform:translateY(-2px);background:var(--primary-hover);}
.menu-label{font-size:12px;font-weight:600;color:#1F1F2E;}
.tx-card{background:#fff;border-radius:16px;padding:16px;box-shadow:0 2px 8px rgba(0,0,0,.04);margin-bottom:18px;}
.tx-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;}
.tx-title{font-weight:800;font-size:15px;}
.tx-link{color:var(--primary-dark);text-decoration:underline;font-size:13px;font-weight:600;}
.tx-item{display:flex;align-items:center;gap:12px;background:#EBF2F7;border-radius:14px;padding:10px 12px;margin-bottom:8px;}
.tx-item:last-child{margin-bottom:0;}
.tx-ic{width:38px;height:38px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.tx-info{flex:1;min-width:0;}
.tx-type{font-weight:700;font-size:14px;}
.tx-date{font-size:11px;color:var(--text-muted);}
.tx-right{text-align:right;}
.tx-amount{font-weight:800;font-size:13px;}
.tx-amount.in{color:var(--success);}
.tx-amount.out{color:var(--danger);}
.tx-status{font-size:11px;font-weight:600;display:inline-flex;align-items:center;gap:3px;margin-top:2px;}
.tx-status.success{color:var(--success);}
.tx-status.pending{color:var(--warning);}
.tx-status.failed{color:var(--danger);}
.tx-empty{text-align:center;color:var(--text-muted);padding:18px 0;font-size:13px;}
.security-note{background:#fff;border-radius:14px;padding:14px 16px;font-size:12px;color:var(--text-muted);box-shadow:0 2px 8px rgba(0,0,0,.04);}
.security-note .sn-title{display:flex;align-items:center;gap:6px;color:#1F1F2E;font-weight:700;margin-bottom:6px;font-size:13px;}
.bottom-nav{position:fixed;bottom:14px;left:50%;transform:translateX(-50%);width:calc(100% - 36px);max-width:394px;background:#fff;border-radius:20px;display:flex;justify-content:space-around;align-items:center;padding:10px 18px;box-shadow:0 6px 20px rgba(0,0,0,.08);}
.nav-item{display:flex;flex-direction:column;align-items:center;font-size:11px;color:#6B7280;text-decoration:none;font-weight:600;gap:2px;}
.nav-item i{font-size:20px;}
.nav-item.center{width:54px;height:54px;border-radius:50%;background:#22C55E;color:#fff;justify-content:center;margin-top:-26px;box-shadow:0 4px 12px rgba(34,197,94,.4);}
.nav-item.center i{font-size:26px;}
.alert-floating{position:fixed;top:18px;left:50%;transform:translateX(-50%);z-index:1080;min-width:280px;max-width:90%;}
</style>
</head>
<body>
<div class="app-container" data-testid="dashboard-page">

    <?php if($success_msg): ?>
    <div class="alert alert-success alert-floating shadow-sm" data-testid="success-alert">
        <i class="bi bi-check-circle-fill me-1"></i><?= htmlspecialchars($success_msg) ?>
    </div>
    <?php endif; ?>
    <?php if($error_msg): ?>
    <div class="alert alert-danger alert-floating shadow-sm" data-testid="error-alert">
        <i class="bi bi-exclamation-circle-fill me-1"></i><?= htmlspecialchars($error_msg) ?>
    </div>
    <?php endif; ?>

    <div class="top-header">
        <h1 class="page-title" data-testid="dashboard-title">Dashboard</h1>
        <a href="logout.php" class="btn-logout" data-testid="logout-btn" title="Logout">
            <i class="bi bi-power"></i>
        </a>
    </div>

<?php
$default_avatar = "data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23a0aec0'><path d='M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z'/></svg>";
$avatar_src = $default_avatar;
if (!empty($user['avatar']) && file_exists('uploads/avatars/' . $user['avatar'])) {
    $avatar_src = 'uploads/avatars/' . $user['avatar'];
}
?>


    <div class="balance-card" data-testid="balance-card">
        <div class="balance-top">
            <div class="user-row">
                <label for="avatarInput" style="cursor: pointer;" title="click to change avatar">
                <img id="userAvatar" src="<?= $avatar_src ?>" alt="Avatar" 
                style="width: 45px; height: 45px; border-radius: 100%; object-fit: cover; border: 2px solid #e2e8f0; vertical-align: middle;">
                </label>
                <input type="file" id="avatarInput" class="d-none" accept="image/*">
    <div class="user-name" data-testid="user-name"><?= htmlspecialchars($user['full_name']) ?></div>
<?php
    $isLocked = isset($user['is_permanently_locked']) && (int)$user['is_permanently_locked'] === 1;
    $status = !empty($user['status']) ? $user['status'] : 'unverified';

    if ($isLocked) {
        $stClass = 'locked';
        $stIcon = 'bi-lock-fill';
        $stText = 'Locked';
    } elseif ($status === 'verified') {
        $stClass = 'verified';
        $stIcon = 'bi-check-circle-fill';
        $stText = 'Verified';
    } elseif ($status === 'pending' || $status === 'updating') {
        $stClass = 'pending';
        $stIcon = 'bi-hourglass-split';
        $stText = 'Pending';
    } else {
        $stClass = 'unverified';
        $stIcon = 'bi-exclamation-circle-fill';
        $stText = 'Unverified';
    }
?>
<div class="<?= $stClass ?>"><i class="bi <?= $stIcon ?>"></i> <?= $stText ?></div>
            </div>
            <div class="balance-actions">
                <i class="bi bi-search" title="Search"></i>
                <i class="bi bi-bell" title="Notifications"></i>
            </div>
        </div>
        <div class="account-row">
            <span class="label">Account number</span>
            <span data-testid="account-number"><?= htmlspecialchars($user['phone']) ?> <i class="bi bi-copy ms-1" style="cursor:pointer" onclick="copyAcc('<?= htmlspecialchars($user['phone']) ?>')"></i></span>
        </div>
        <div class="balance-row">
            <span class="label">Balance</span>
            <span><span id="balanceText" data-testid="balance-amount" class="balance-amount"><?= fmt_vnd($user['balance']) ?></span> <i id="balanceToggle" class="bi bi-eye-slash ms-1" style="cursor:pointer" onclick="toggleBalance()"></i></span>
        </div>
        <div class="balance-footer">
            <a href="transaction_history.php" data-testid="link-history"><i class="bi bi-clock-history"></i> Transaction history</a>
            <a href="#" data-testid="link-account-card"><i class="bi bi-credit-card"></i> Account &amp; Card</a>
        </div>
    </div>

    <h2 class="section-title">Menu</h2>
    <div class="menu-grid">
        <a href="deposit.php" class="menu-item" data-testid="menu-deposit">
            <div class="menu-icon"><i class="bi bi-box-arrow-in-down"></i></div>
            <span class="menu-label">Deposit</span>
        </a>
        <a href="withdraw.php" class="menu-item" data-testid="menu-withdraw">
            <div class="menu-icon"><i class="bi bi-box-arrow-down"></i></div>
            <span class="menu-label">Withdraw</span>
        </a>
        <a href="transfer.php" class="menu-item" data-testid="menu-transfer">
            <div class="menu-icon"><i class="bi bi-arrow-left-right"></i></div>
            <span class="menu-label">Transfer</span>
        </a>
        <a href="buy_card.php" class="menu-item" data-testid="menu-buycard">
            <div class="menu-icon"><i class="bi bi-phone"></i></div>
            <span class="menu-label">Mobile Top-Up</span>
        </a>
    </div>

    <div class="tx-card" data-testid="transactions-card">
        <div class="tx-header">
            <span class="tx-title">Transaction History</span>
            <a href="transaction_history.php" class="tx-link" data-testid="see-all-link">See all</a>
        </div>
        <?php if(empty($transactions)): ?>
            <div class="tx-empty" data-testid="tx-empty">No transactions yet.</div>
        <?php else: foreach($transactions as $t):
            [$slabel, $sclass] = status_label($t['status']);
            $is_in = ($t['direction'] === 'in') || ($t['type']==='deposit');
            $sign = $is_in ? '+' : '-';
            $sign_class = $is_in ? 'in' : 'out';
            $amount_display = $sign . ' ' . fmt_vnd($t['amount']);
            $date_display = date('Y-m-d H:i', strtotime($t['created_at']));
        ?>
        <div class="tx-item" data-testid="tx-item-<?= (int)$t['user_id'] ?>">
            <div class="tx-ic"><i class="bi <?= tx_icon($t['type']) ?>"></i></div>
            <div class="tx-info">
                <div class="tx-type"><?= tx_label($t['type']) ?></div>
                <div class="tx-date"><?= $date_display ?></div>
            </div>
            <div class="tx-right">
                <div class="tx-amount <?= $sign_class ?>"><?= $amount_display ?></div>
                <div class="tx-status <?= $sclass ?>">
                    <i class="bi bi-<?= $sclass==='success'?'check-circle-fill':($sclass==='pending'?'hourglass-split':'x-circle-fill') ?>"></i>
                    <?= $slabel ?>
                </div>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <div class="security-note" data-testid="security-note">
        <div class="sn-title"><i class="bi bi-shield-exclamation"></i> Security note</div>
        Do not share passwords and personal information with anyone.<br>
        The system will never ask you to provide a password via email or phone.
    </div>
</div>

<nav class="bottom-nav">
    <a href="profile.php" class="nav-item" data-testid="nav-profile">
        <i class="bi bi-person"></i>
        <span>Profile</span>
    </a>
    <a href="" class="nav-item center" data-testid="nav-qr">
        <i class="bi bi-qr-code"></i>
    </a>
    <a href="dashboard_user.php" class="nav-item" data-testid="nav-settings">
        <i class="bi bi-gear"></i>
        <span>Settings</span>
    </a>
</nav>

<script>
const realBalance = <?= json_encode(fmt_vnd($user['balance'])) ?>;
const hiddenBalance = '••••••• VND';
let balanceVisible = true;
function toggleBalance(){
    const t = document.getElementById('balanceText');
    const ic = document.getElementById('balanceToggle');
    balanceVisible = !balanceVisible;
    t.textContent = balanceVisible ? realBalance : hiddenBalance;
    ic.className = balanceVisible ? 'bi bi-eye-slash ms-1' : 'bi bi-eye ms-1';
    ic.style.cursor = 'pointer';
}
function copyAcc(num){ navigator.clipboard && navigator.clipboard.writeText(num); }
setTimeout(()=>{document.querySelectorAll('.alert-floating').forEach(a=>a.remove());},3500);


document.getElementById('avatarInput').addEventListener('change', function() {
    const file = this.files[0];
    if (file) {
        const formData = new FormData();
        formData.append('avatar', file);
        document.getElementById('userAvatar').style.opacity = '0.5';

        fetch('process_avatar.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.getElementById('userAvatar').src = data.new_path + '?t=' + new Date().getTime();
                document.getElementById('userAvatar').style.opacity = '1';
            } else {
                alert('Lỗi: ' + data.error);
                document.getElementById('userAvatar').style.opacity = '1';
            }
        })
        .catch(err => {
            console.error(err);
            alert('connection error!');
            document.getElementById('userAvatar').style.opacity = '1';
        });
    }
});
</script>
</body>
</html>
