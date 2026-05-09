<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once 'db.php';

$user_id = $_SESSION['user_id'];

// ---- Filters (GET) ----
$search = trim($_GET['search'] ?? '');
$type   = trim($_GET['type']   ?? '');
$state  = trim($_GET['state']  ?? '');

$valid_types  = ['deposit', 'withdraw', 'transfer', 'buy_card'];
// Support both 'successful' (spec) and 'completed' (legacy) as equivalent
$valid_states = ['successful', 'pending', 'failed'];

if ($type !== '' && !in_array($type, $valid_types, true))    $type  = '';
if ($state !== '' && !in_array($state, $valid_states, true)) $state = '';

// ---- Build WHERE clause ----
// The user may be sender OR recipient (in transfers)
$where  = "(t.user_id = :uid OR t.recipient_id = :uid)";
$params = [':uid' => $user_id];

if ($search !== '') {
    // Search by the displayed transaction ID (e.g. "VN001" or "1")
    $where .= " AND (CAST(t.transaction_id	 AS CHAR) LIKE :search OR CONCAT('VN', LPAD(t.id, 3, '0')) LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}
if ($type !== '') {
    $where .= " AND t.type = :type";
    $params[':type'] = $type;
}
if ($state !== '') {
    $where .= " AND (t.status = :state OR (:state = 'successful' AND t.status = 'completed'))";
    $params[':state'] = $state;
}

$sql = "SELECT t.*,
               CASE WHEN t.user_id = :uid2 THEN 'out' ELSE 'in' END AS direction
        FROM transactions t
        WHERE $where
        ORDER BY t.created_at DESC, t.transaction_id DESC";
$params[':uid2'] = $user_id;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function fmt_vnd($amount) { return number_format((float)$amount, 0, ',', '.') . ' VND'; }

function type_label($t) {
    return [
        'deposit'  => 'Deposit',
        'withdraw' => 'Withdraw',
        'transfer' => 'Transfer',
        'buy_card' => 'Mobile Topup',
    ][$t] ?? ucfirst($t);
}

function type_color($t) {
    return [
        'deposit'  => '#16A34A', // green
        'withdraw' => '#DC2626', // red
        'transfer' => '#0F2BA8', // blue
        'buy_card' => '#DC2626', // red
    ][$t] ?? '#1F1F2E';
}

function state_meta($status) {
    // Normalise 'completed' -> 'successful'
    $s = ($status === 'completed') ? 'successful' : $status;
    switch ($s) {
        case 'successful': return ['Successful', '#16A34A', 'bi-check-circle'];
        case 'pending':    return ['Pending',    '#D97706', 'bi-exclamation-circle'];
        case 'failed':     return ['Failed',     '#DC2626', 'bi-x-circle'];
        default:           return [ucfirst($s),   '#6B7280', 'bi-dash-circle'];
    }
}

function display_id($id) { return 'VN' . str_pad((string)$id, 3, '0', STR_PAD_LEFT); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Transaction History - E-Wallet</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Mulish:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
    --bg-page:#E0F2F7;
    --primary:#9B95D6;
    --primary-dark:#3D3D8C;
    --text-dark:#1F1F2E;
    --text-muted:#6B7280;
    --border:#D1D5DB;
    --header-grey:#B8BCC3;
    --row-border:#E5E7EB;
    --success:#16A34A;
    --pending:#D97706;
    --failed:#DC2626;
    --home-blue:#8BB5D9;
}
*{font-family:'Mulish',sans-serif;-webkit-font-smoothing:antialiased;}
html,body{background:var(--bg-page);color:var(--text-dark);margin:0;}
.app-container{max-width:430px;margin:0 auto;min-height:100vh;background:var(--bg-page);padding:28px 18px 100px;position:relative;}
.page-title{font-weight:900;font-size:40px;letter-spacing:-1.5px;text-align:center;line-height:1;margin-bottom:4px;}
.page-sub{color:var(--text-muted);font-size:13px;text-align:center;margin-bottom:18px;}

/* Filter bar */
.filter-bar{display:flex;gap:8px;margin-bottom:14px;align-items:center;}
.search-wrap{position:relative;flex:1;}
.search-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#9CA3AF;font-size:14px;}
.search-input{width:100%;background:#fff;border:1px solid var(--border);border-radius:999px;height:38px;padding:0 14px 0 36px;font-size:13px;outline:none;transition:border-color .15s ease;}
.search-input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(155,149,214,.2);}

.filter-btn{background:#fff;border:1px solid var(--border);border-radius:999px;height:38px;padding:0 14px;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:6px;cursor:pointer;color:var(--text-dark);position:relative;}
.filter-btn:hover{border-color:var(--primary);}
.filter-btn.active{border-color:var(--primary);background:#F1EFFB;color:var(--primary-dark);}
.filter-btn i{font-size:13px;}
.filter-btn .caret{font-size:10px;margin-left:2px;}

/* Dropdown menu */
.dropdown-menu-c{position:absolute;top:44px;right:0;background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.08);min-width:160px;z-index:20;padding:6px;display:none;}
.dropdown-menu-c.show{display:block;}
.dropdown-menu-c a{display:block;padding:8px 12px;font-size:13px;color:var(--text-dark);text-decoration:none;border-radius:8px;font-weight:600;}
.dropdown-menu-c a:hover{background:#F3F4F6;}
.dropdown-menu-c a.active{background:#EEEDFB;color:var(--primary-dark);}

/* Table */
.tx-table-wrap{background:transparent;border-radius:14px;overflow:hidden;}
.tx-table{width:100%;border-collapse:separate;border-spacing:0;font-size:12px;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.05);}
.tx-table thead th{background:var(--header-grey);color:#1F1F2E;font-weight:800;padding:12px 6px;text-align:center;font-size:13px;border-right:1px solid #9EA3AB;}
.tx-table thead th:first-child{border-top-left-radius:14px;}
.tx-table thead th:last-child{border-top-right-radius:14px;border-right:none;}
.tx-table tbody td{padding:12px 6px;text-align:center;border-bottom:1px solid var(--row-border);border-right:1px solid var(--row-border);font-weight:700;vertical-align:middle;white-space:nowrap;}
.tx-table tbody td:last-child{border-right:none;}
.tx-table tbody tr:last-child td{border-bottom:none;}
.tx-table tbody tr:hover{background:#F9FAFB;}

.col-id{font-weight:900;color:#1F1F2E;font-size:13px;}
.col-type{font-weight:700;font-size:12.5px;}
.col-amount{font-weight:900;font-size:13px;}
.col-amount.in{color:var(--success);}
.col-amount.out{color:var(--failed);}
.col-time{color:#6B7280;font-size:10.5px;font-weight:600;}

.state-badge{display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:700;white-space:nowrap;}
.state-badge.successful{color:var(--success);}
.state-badge.pending{color:var(--pending);}
.state-badge.failed{color:var(--failed);}

.empty-row td{padding:40px 14px;text-align:center;color:var(--text-muted);font-size:13px;font-weight:500;}

/* Home FAB */
.home-fab{position:fixed;bottom:26px;right:50%;transform:translateX(200px);width:54px;height:54px;border-radius:50%;background:var(--home-blue);color:#fff;display:flex;align-items:center;justify-content:center;font-size:22px;text-decoration:none;box-shadow:0 6px 16px rgba(139,181,217,.5);transition:transform .15s ease;}
.home-fab:hover{transform:translateX(200px) scale(1.05);color:#fff;}
@media (max-width: 460px){ .home-fab{right:20px;transform:none;} .home-fab:hover{transform:scale(1.05);} }

.chip-reset{background:transparent;border:none;color:var(--primary-dark);text-decoration:underline;font-size:12px;font-weight:700;cursor:pointer;padding:0;}
.filter-row-2{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;font-size:12px;color:var(--text-muted);}
</style>
</head>
<body>
<div class="app-container" data-testid="history-page">

    <h1 class="page-title" data-testid="history-title">Transaction History</h1>
    <div class="page-sub">View all your transactions</div>

    <form class="filter-bar" method="GET" action="history.php" id="filterForm">
        <div class="search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" name="search" class="search-input"
                   placeholder="Search ID transaction..."
                   value="<?= htmlspecialchars($search) ?>"
                   data-testid="input-search"
                   autocomplete="off">
        </div>

        <div style="position:relative">
            <button type="button" class="filter-btn <?= $type ? 'active' : '' ?>"
                    id="typeBtn" data-testid="filter-type">
                <i class="bi bi-funnel"></i>
                <?= $type ? type_label($type) : 'Type' ?>
                <i class="bi bi-caret-down-fill caret"></i>
            </button>
            <div class="dropdown-menu-c" id="typeMenu">
                <a href="?<?= http_build_query(array_merge($_GET, ['type' => ''])) ?>"
                   class="<?= $type === '' ? 'active' : '' ?>" data-testid="type-opt-all">All Types</a>
                <?php foreach ($valid_types as $vt): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['type' => $vt])) ?>"
                   class="<?= $type === $vt ? 'active' : '' ?>"
                   data-testid="type-opt-<?= $vt ?>"><?= type_label($vt) ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="position:relative">
            <button type="button" class="filter-btn <?= $state ? 'active' : '' ?>"
                    id="stateBtn" data-testid="filter-state">
                <?= $state ? ucfirst($state) : 'State' ?>
                <i class="bi bi-caret-down-fill caret"></i>
            </button>
            <div class="dropdown-menu-c" id="stateMenu">
                <a href="?<?= http_build_query(array_merge($_GET, ['state' => ''])) ?>"
                   class="<?= $state === '' ? 'active' : '' ?>" data-testid="state-opt-all">All States</a>
                <?php foreach ($valid_states as $vs): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['state' => $vs])) ?>"
                   class="<?= $state === $vs ? 'active' : '' ?>"
                   data-testid="state-opt-<?= $vs ?>"><?= ucfirst($vs) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </form>

    <?php if ($search || $type || $state): ?>
    <div class="filter-row-2">
        <span data-testid="result-count"><?= count($rows) ?> result<?= count($rows)===1?'':'s' ?></span>
        <a href="history.php" class="chip-reset" data-testid="reset-filters">Clear filters</a>
    </div>
    <?php endif; ?>

    <div class="tx-table-wrap">
        <table class="tx-table" data-testid="tx-table">
            <thead>
                <tr>
                    <th style="width:18%">ID</th>
                    <th style="width:20%">Type</th>
                    <th style="width:26%">Amount</th>
                    <th style="width:20%">Time</th>
                    <th style="width:16%">State</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr class="empty-row" data-testid="tx-empty">
                        <td colspan="5">
                            <i class="bi bi-inbox" style="font-size:28px;display:block;margin-bottom:6px;opacity:.4;"></i>
                            No transactions found.
                        </td>
                    </tr>
                <?php else: foreach ($rows as $r):
                    [$s_label, $s_color, $s_icon] = state_meta($r['status']);
                    // Determine direction/sign
                    $is_in = false;
                    if ($r['type'] === 'deposit') {
                        $is_in = true;
                    } elseif ($r['type'] === 'transfer') {
                        // Sender => out; Recipient => in
                        $is_in = ((int)$r['user_id'] !== (int)$user_id);
                    } else {
                        // withdraw, buy_card always outflow
                        $is_in = false;
                    }
                    $sign       = $is_in ? '+' : '-';
                    $sign_class = $is_in ? 'in' : 'out';
                    $t_color    = type_color($r['type']);
                    $time_disp  = date('Y-m-d  H:i', strtotime($r['created_at']));
                ?>
                <tr data-testid="tx-row-<?= (int)$r['user_id'] ?>">
                    <td class="col-id" data-testid="tx-user_id"><?= display_id($r['user_id']) ?></td>
                    <td class="col-type" style="color:<?= $t_color ?>"><?= type_label($r['type']) ?></td>
                    <td class="col-amount <?= $sign_class ?>"><?= $sign ?><?= fmt_vnd($r['amount']) ?></td>
                    <td class="col-time"><?= $time_disp ?></td>
                    <td>
                        <?php $state_normalised = ($r['status'] === 'completed') ? 'successful' : $r['status']; ?>
                        <span class="state-badge <?= $state_normalised ?>" data-testid="tx-state-<?= (int)$r['user_id'] ?>">
                            <i class="bi <?= $s_icon ?>"></i> <?= $s_label ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <a href="dashboard_user.php" class="home-fab" data-testid="home-fab" title="Back to dashboard">
        <i class="bi bi-house-fill"></i>
    </a>
</div>

<script>
// Auto-submit search on typing (debounced)
const searchInput = document.querySelector('input[name="search"]');
const form = document.getElementById('filterForm');
let tmr = null;
searchInput.addEventListener('input', () => {
    clearTimeout(tmr);
    tmr = setTimeout(() => form.submit(), 400);
});
searchInput.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); clearTimeout(tmr); form.submit(); }
});

// Dropdown toggles
function wireDropdown(btnId, menuId){
    const btn = document.getElementById(btnId);
    const menu = document.getElementById(menuId);
    btn.addEventListener('click', e => {
        e.stopPropagation();
        document.querySelectorAll('.dropdown-menu-c').forEach(m => { if (m !== menu) m.classList.remove('show'); });
        menu.classList.toggle('show');
    });
}
wireDropdown('typeBtn', 'typeMenu');
wireDropdown('stateBtn', 'stateMenu');

document.addEventListener('click', () => {
    document.querySelectorAll('.dropdown-menu-c').forEach(m => m.classList.remove('show'));
});
</script>
</body>
</html>
