<?php
session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['ok' => false, 'error' => 'Session expired. Please log in again.']);
        exit;
    }

    require_once 'db.php';
    $user_id = $_SESSION['user_id'];

    //Allowed withdrawal card (only one) 
    $TARGET_CARD = ['number' => '111111', 'expiry' => '10/10/2022', 'cvv' => '411'];

    //Constants
    define('MULTIPLE_OF', 50000);
    define('MAX_PER_DAY', 2);
    define('FEE_PERCENT', 0.05);
    define('AUTO_APPROVE_LIMIT', 5000000);
    //Sanitise input
    $card   = preg_replace('/\D/', '', $_POST['card_number'] ?? '');
    $expiry = trim($_POST['expiry'] ?? '');
    $cvv    = preg_replace('/\D/', '', $_POST['cvv'] ?? '');
    $amount = (int) preg_replace('/\D/', '', $_POST['amount'] ?? '0');
    $note   = trim($_POST['note'] ?? '');
    if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255);

    //Format validation
    if (strlen($card) < 6) {
        echo json_encode(['ok' => false, 'error' => 'Card number must be at least 6 digits.']);
        exit;
    }
    if (!preg_match('#^(0[1-9]|1[0-2])/(0[1-9]|[12]\d|3[01])/\d{4}$#', $expiry)) {
        echo json_encode(['ok' => false, 'error' => 'Expiry date must be in MM/DD/YYYY format.']);
        exit;
    }
    if (strlen($cvv) !== 3) {
        echo json_encode(['ok' => false, 'error' => 'CVV must be exactly 3 digits.']);
        exit;
    }

    //Card whitelist check
    if ($card !== $TARGET_CARD['number']) {
        echo json_encode(['ok' => false, 'error' => 'This card is not supported for withdrawal']);
        exit;
    }
    if ($expiry !== $TARGET_CARD['expiry'] || $cvv !== $TARGET_CARD['cvv']) {
        echo json_encode(['ok' => false, 'error' => 'Invalid card information']);
        exit;
    }

    //Amount rules
    if ($amount <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Withdrawal amount is required.']);
        exit;
    }
    if ($amount % MULTIPLE_OF !== 0) {
        echo json_encode(['ok' => false, 'error' => 'Amount must be a multiple of 50,000 VND.']);
        exit;
    }

    //Daily limit (max 2 per user per day)
    try {
        $cnt = $pdo->prepare("
            SELECT COUNT(*) FROM transactions
            WHERE user_id = ?
              AND type = 'withdraw'
              AND DATE(created_at) = CURDATE()
              AND status IN ('completed','pending')
        ");
        $cnt->execute([$user_id]);
        $today_count = (int) $cnt->fetchColumn();
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => 'Database error. Please try again.']);
        exit;
    }
    if ($today_count >= MAX_PER_DAY) {
        echo json_encode(['ok' => false, 'error' => 'You have reached the maximum of 2 withdrawals per day.']);
        exit;
    }

    //Balance check (locked row)
    $fee   = (int) round($amount * FEE_PERCENT);
    $total = $amount + $fee;

    try {
        $pdo->beginTransaction();

        $bal_stmt = $pdo->prepare("SELECT balance FROM users WHERE user_id = ? FOR UPDATE");
        $bal_stmt->execute([$user_id]);
        $balance = $bal_stmt->fetchColumn();
        if ($balance === false) throw new Exception('User not found.');
        $balance = (float) $balance;

        if ($balance < $total) {
            $pdo->rollBack();
            echo json_encode([
                'ok'    => false,
                'error' => 'Insufficient balance. Required ' . number_format($total,0,',','.') . ' VND (incl. 5% fee).'
            ]);
            exit;
        }

        $note_final = $note !== '' ? $note : ('Withdraw to card ****' . substr($card, -2));

        //Branch by approval threshold
        if ($amount > AUTO_APPROVE_LIMIT) {
            // PENDING — do NOT deduct balance
            $ins = $pdo->prepare("
                INSERT INTO transactions (user_id, type, amount, fee, status, note, created_at)
                VALUES (?, 'withdraw', ?, ?, 'pending', ?, NOW())
            ");
            $ins->execute([$user_id, $amount, $fee, $note_final]);
            $tx_id = (int) $pdo->lastInsertId();
            $new_balance = $balance; // unchanged
            $status = 'pending';
        } else {
            // COMPLETED — deduct (amount + fee)
            $upd = $pdo->prepare("
                UPDATE users SET balance = balance - ?
                WHERE user_id = ? AND balance >= ?
            ");
            $upd->execute([$total, $user_id, $total]);
            if ($upd->rowCount() === 0) throw new Exception('Insufficient balance');

            $ins = $pdo->prepare("
                INSERT INTO transactions (user_id, type, amount, fee, status, note, created_at)
                VALUES (?, 'withdraw', ?, ?, 'completed', ?, NOW())
            ");
            $ins->execute([$user_id, $amount, $fee, $note_final]);
            $tx_id = (int) $pdo->lastInsertId();
            $new_balance = $balance - $total;
            $status = 'completed';
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => 'Transaction failed. Please try again.']);
        exit;
    }

    // ---- 10. JSON response --------------------------------------------
    $f = fn($n) => number_format((float)$n, 0, ',', '.') . ' VND';
    echo json_encode([
        'ok'             => true,
        'status'         => $status,                  // 'completed' | 'pending'
        'amount'         => $amount,
        'amount_fmt'     => $f($amount),
        'fee'            => $fee,
        'fee_fmt'        => $f($fee),
        'total'          => $total,
        'total_fmt'      => $f($total),
        'new_balance'    => $new_balance,
        'new_balance_fmt'=> $f($new_balance),
        'card_masked'    => '****' . substr($card, -2),
        'transaction_id' => $tx_id,
        'note'           => $note_final,
        'today_count'    => $today_count + 1,
        'completed_at'   => date('Y-m-d H:i:s'),
    ]);
    exit;
}

// GET = render page

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once 'db.php';

$stmt = $pdo->prepare("SELECT balance FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$balance = (float) $stmt->fetchColumn();

// today's withdraw count for the helper
$cnt = $pdo->prepare("
    SELECT COUNT(*) FROM transactions
    WHERE user_id = ? AND type='withdraw'
      AND DATE(created_at)=CURDATE()
      AND status IN ('completed','pending')
");
$cnt->execute([$_SESSION['user_id']]);
$today_count = (int) $cnt->fetchColumn();
$remaining = max(0, 2 - $today_count);

function fmt_vnd($amount) { return number_format((float)$amount, 0, ',', '.') . ' VND'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Withdraw - E-Wallet</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Mulish:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
    --bg-page:#E8F4F8;
    --primary:#9B95D6;
    --primary-hover:#857FCB;
    --primary-darker:#2D2D7C;
    --info-bg:#DCDFF7;
    --balance-pill:#C1CBED;
    --balance-pill-text:#1B2A6B;
    --warning-bg:#FFE9B0;
    --warning-text:#A05A00;
    --pending-bg:#FFF6D6;
    --pending-stroke:#E69100;
    --success-bg:#D8F3DD;
    --success:#16A34A;
    --text-dark:#1F1F2E;
    --text-muted:#6B7280;
    --border:#E5E7EB;
    --danger:#DC2626;
}
*{font-family:'Mulish',sans-serif;}
html,body{background:var(--bg-page);color:var(--text-dark);margin:0;}
.app-container{max-width:430px;margin:0 auto;min-height:100vh;background:var(--bg-page);padding:24px 18px 40px;}
.page-title{font-weight:900;font-size:34px;letter-spacing:-1px;margin-bottom:2px;}
.page-sub{color:var(--text-muted);font-size:14px;margin-bottom:18px;}

.balance-pill{background:var(--balance-pill);border-radius:14px;padding:14px 18px;color:var(--balance-pill-text);margin-bottom:14px;}
.balance-pill .label{display:flex;align-items:center;gap:6px;font-weight:700;font-size:14px;}
.balance-pill .amt{font-weight:900;font-size:22px;margin-top:4px;}
.balance-pill .quota{font-size:11px;font-weight:600;margin-top:6px;opacity:.85;}

/* Form */
.form-card{background:#fff;border-radius:22px;padding:22px 20px;box-shadow:0 4px 20px rgba(0,0,0,.05);}
.form-label{font-weight:600;font-size:13px;color:#1F1F2E;margin-bottom:6px;}
.input-wrap{position:relative;margin-bottom:14px;}
.input-wrap .form-control{border:1.5px solid var(--border);border-radius:12px;padding:12px 14px 12px 44px;font-size:14px;font-weight:500;height:48px;}
.input-wrap textarea.form-control{height:80px;padding:10px 14px 10px 14px;resize:none;}
.input-wrap .form-control:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(155,149,214,.2);outline:none;}
.input-wrap.invalid .form-control{border-color:var(--danger);}
.input-wrap .icon-left{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#9CA3AF;font-size:16px;pointer-events:none;}
.input-amount{padding-left:60px !important;}
.amount-label{position:absolute;left:14px;top:50%;transform:translateY(-50%);font-weight:800;color:#1F1F2E;font-size:14px;}
.helper{font-size:12px;color:var(--text-muted);margin-top:6px;}
.field-error{font-size:12px;color:var(--danger);margin-top:4px;font-weight:600;display:none;}
.input-wrap.invalid .field-error{display:block;}

/* Fee box (live) */
.fee-box{background:var(--warning-bg);border-radius:14px;padding:14px 16px;color:var(--warning-text);margin-top:8px;}
.fee-box .fb-title{font-weight:800;margin-bottom:8px;font-size:14px;}
.fee-row{display:flex;justify-content:space-between;font-weight:700;font-size:14px;}
.fee-divider{border-top:1px solid #E0B36A;margin:8px 0;}

.note-box{background:var(--info-bg);border-radius:12px;padding:14px 16px;color:var(--primary-darker);font-size:12.5px;margin-top:16px;}
.note-box .nb-title{display:flex;align-items:center;gap:6px;font-weight:700;margin-bottom:6px;font-size:13px;}
.note-box ol{padding-left:18px;margin:0;}

.btn-row{display:flex;gap:10px;margin-top:18px;}
.btn-row .btn{flex:1;height:48px;border-radius:14px;font-weight:700;font-size:15px;}
.btn-cancel{background:#fff;border:1.5px solid var(--border);color:#1F1F2E;}
.btn-primary-c{background:var(--primary);color:#fff;border:none;display:flex;align-items:center;justify-content:center;gap:8px;}
.btn-primary-c:hover{background:var(--primary-hover);color:#fff;}
.btn-primary-c:disabled{opacity:.65;}

.global-error{background:#FEE2E2;color:var(--danger);border-radius:12px;padding:12px 14px;font-size:13px;font-weight:600;margin-bottom:14px;display:none;}
.global-error.show{display:flex;align-items:center;gap:8px;}

/* Result UI */
.result-card{border-radius:22px;padding:32px 22px 24px;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.06);}
.result-card.completed{background:var(--success-bg);}
.result-card.pending  {background:var(--pending-bg);}
.result-icon{width:84px;height:84px;margin:0 auto 14px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:42px;animation:pop .35s ease-out;}
.result-card.completed .result-icon{background:#B6E5C0;color:var(--success);}
.result-card.pending   .result-icon{background:#FFE2A1;color:var(--pending-stroke);}
@keyframes pop {
    0%   { transform: scale(0); opacity: 0; }
    70%  { transform: scale(1.15); opacity: 1; }
    100% { transform: scale(1); }
}
.result-title{font-weight:900;font-size:24px;color:#1F1F2E;margin-bottom:4px;line-height:1.15;}
.result-sub{color:#1F1F2E;font-size:13px;margin-bottom:18px;}

.amount-pill{background:#fff;border-radius:14px;padding:14px;margin-bottom:14px;}
.amount-pill .lbl{font-size:12px;color:var(--text-muted);font-weight:600;}
.amount-pill .val{font-size:30px;font-weight:900;letter-spacing:-1px;line-height:1.1;margin-top:2px;}
.result-card.completed .amount-pill .val{color:var(--danger);}
.result-card.pending   .amount-pill .val{color:var(--pending-stroke);}

.details-card{background:#fff;border-radius:14px;padding:14px 16px;font-size:13px;color:#1F1F2E;text-align:left;margin-bottom:14px;}
.details-card .row-line{display:flex;justify-content:space-between;padding:5px 0;font-weight:600;gap:12px;}
.details-card .row-line .lbl{color:var(--text-muted);font-weight:600;flex-shrink:0;}
.details-card .row-line .v{text-align:right;word-break:break-word;}
.details-card hr{margin:6px 0;}

.status-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:800;}
.status-badge.completed{background:var(--success-bg);color:var(--success);}
.status-badge.pending  {background:var(--pending-bg);color:var(--pending-stroke);}

.btn-back-dashboard{background:var(--primary-darker);color:#fff;border:none;height:48px;border-radius:14px;font-weight:800;font-size:15px;width:100%;text-decoration:none;display:flex;align-items:center;justify-content:center;}
.btn-back-dashboard:hover{background:#22227A;color:#fff;}
.btn-link-secondary{background:transparent;border:none;color:var(--primary-darker);font-weight:700;font-size:13px;text-decoration:underline;margin-top:10px;cursor:pointer;}

.spinner-border-sm{width:14px;height:14px;border-width:2px;}
</style>
</head>
<body>
<div class="app-container" data-testid="withdraw-page">

    <h1 class="page-title" data-testid="withdraw-title">Withdraw</h1>
    <div class="page-sub">Withdraw money from e-wallet to bank account</div>

    <div class="balance-pill" data-testid="balance-pill">
        <div class="label"><i class="bi bi-info-circle"></i> Current balance</div>
        <div class="amt" id="balanceDisplay" data-testid="current-balance"><?= fmt_vnd($balance) ?></div>
        <div class="quota" data-testid="daily-quota">
            <i class="bi bi-clock-history"></i> Today: <?= $today_count ?>/2 used &middot; <?= $remaining ?> remaining
        </div>
    </div>

    <!-- ============ STEP 1: FORM ============ -->
    <section id="step1" data-testid="step-1">
        <div id="globalError" class="global-error" data-testid="global-error">
            <i class="bi bi-exclamation-circle-fill"></i>
            <span id="globalErrorText"></span>
        </div>

        <form class="form-card" id="withdrawForm" novalidate data-testid="withdraw-form">
            <div class="input-wrap" id="wrapCard">
                <label class="form-label">Default card number *</label>
                <div style="position:relative">
                    <i class="bi bi-credit-card-2-front icon-left"></i>
                    <input type="text" name="card_number" id="cardNumber" maxlength="20" inputmode="numeric"
                           class="form-control" placeholder="111111" required data-testid="input-card-number">
                </div>
                <div class="helper">Withdrawals are only allowed to your registered card.</div>
                <div class="field-error">Invalid card number</div>
            </div>

            <div class="input-wrap" id="wrapExpiry">
                <label class="form-label">Expiry date * <span style="color:var(--text-muted);font-weight:500">(MM/DD/YYYY)</span></label>
                <div style="position:relative">
                    <i class="bi bi-calendar3 icon-left"></i>
                    <input type="text" name="expiry" id="expiry" maxlength="10" placeholder="MM/DD/YYYY"
                           class="form-control" required data-testid="input-expiry">
                </div>
                <div class="field-error">Use MM/DD/YYYY format</div>
            </div>

            <div class="input-wrap" id="wrapCvv">
                <label class="form-label">CVV *</label>
                <div style="position:relative">
                    <i class="bi bi-lock icon-left"></i>
                    <input type="password" name="cvv" id="cvv" maxlength="3" inputmode="numeric"
                           class="form-control" placeholder="123" required data-testid="input-cvv">
                </div>
                <div class="field-error">CVV must be 3 digits</div>
            </div>

            <div class="input-wrap" id="wrapAmount">
                <label class="form-label">Withdrawal amount *</label>
                <div style="position:relative">
                    <span class="amount-label">VND</span>
                    <input type="text" name="amount" id="amountInput"
                           class="form-control input-amount" placeholder="0" required data-testid="input-amount">
                </div>
                <div class="helper">Must be a multiple of 50,000 VND</div>
                <div class="field-error">Amount must be a multiple of 50,000 VND</div>
            </div>

            <div class="fee-box" data-testid="fee-box">
                <div class="fb-title">Transaction fee:</div>
                <div class="fee-row"><span>Withdraw fee (5%):</span><span id="feeDisplay" data-testid="fee-amount">0</span></div>
                <div class="fee-divider"></div>
                <div class="fee-row"><span>Total amount deducted:</span><span id="totalDisplay" data-testid="total-deducted">0</span></div>
            </div>

            <div class="input-wrap mt-3" id="wrapNote">
                <label class="form-label">Note (optional)</label>
                <textarea name="note" id="noteInput" class="form-control" maxlength="255"
                          placeholder="Add a memo for this withdrawal..." data-testid="input-note"></textarea>
            </div>

            <div class="note-box">
                <div class="nb-title"><i class="bi bi-clipboard-check"></i> Note:</div>
                <ol>
                    <li>Transactions under 5 million VND will be processed automatically</li>
                    <li>Transactions from 5 million VND or more need to be approved by the administrator</li>
                    <li>Withdrawal fee: 5% of the total amount</li>
                    <li>Maximum 2 withdrawals per day</li>
                </ol>
            </div>

            <div class="btn-row">
                <a href="dashboard_user.php" class="btn btn-cancel" data-testid="btn-cancel">Cancel</a>
                <button type="submit" class="btn btn-primary-c" id="btnSubmit" data-testid="btn-submit-withdraw">
                    <span class="btn-label">Withdraw</span>
                </button>
            </div>
        </form>
    </section>

    <!-- ============ STEP 2: RESULT ============ -->
    <section id="step2" style="display:none" data-testid="step-2">
        <div class="result-card" id="resultCard">
            <div class="result-icon" id="resultIcon"><i class="bi bi-patch-check-fill"></i></div>
            <div class="result-title" id="resultTitle">Withdrawal Successful</div>
            <div class="result-sub" id="resultSub">Your withdrawal has been processed.</div>

            <div class="amount-pill">
                <div class="lbl" id="resultAmountLabel">Withdrawn Amount</div>
                <div class="val" id="sxAmount" data-testid="result-amount">- 0 VND</div>
            </div>

            <div class="details-card">
                <div class="row-line"><span class="lbl">Status:</span>
                    <span class="v"><span id="sxStatus" class="status-badge completed" data-testid="result-status">Completed</span></span>
                </div>
                <hr>
                <div class="row-line"><span class="lbl">Transaction ID:</span><span class="v" id="sxId" data-testid="result-id">—</span></div>
                <div class="row-line"><span class="lbl">Card:</span><span class="v" id="sxCard">****</span></div>
                <div class="row-line"><span class="lbl">Amount:</span><span class="v" id="sxBaseAmt">—</span></div>
                <div class="row-line"><span class="lbl">Fee (5%):</span><span class="v" id="sxFee">—</span></div>
                <div class="row-line"><span class="lbl">Total Deducted:</span><span class="v" id="sxTotal" style="font-weight:800">—</span></div>
                <div class="row-line"><span class="lbl">Note:</span><span class="v" id="sxNote">—</span></div>
                <div class="row-line"><span class="lbl">Time:</span><span class="v" id="sxTime">—</span></div>
                <div class="row-line"><span class="lbl">New Balance:</span><span class="v" id="sxBalance" style="font-weight:800;color:var(--primary-darker)">—</span></div>
            </div>

            <a href="dashboard_user.php" class="btn-back-dashboard" data-testid="btn-back-dashboard">Back to dashboard</a>
            <button type="button" class="btn-link-secondary" id="btnWithdrawAgain" data-testid="btn-withdraw-again">+ Make another withdrawal</button>
        </div>
    </section>

</div>

<script>
const $ = (sel) => document.querySelector(sel);
const fmtNum  = (n) => Number(n||0).toLocaleString('vi-VN');
const fmtVND  = (n) => fmtNum(n) + ' VND';
const showWrap  = (id, msg) => { const w=document.getElementById(id); w.classList.add('invalid'); if(msg) w.querySelector('.field-error').textContent=msg; };
const clearAll  = () => document.querySelectorAll('.input-wrap.invalid').forEach(w=>w.classList.remove('invalid'));
const showGlobal= (m) => { const g=$('#globalError'); $('#globalErrorText').textContent=m; g.classList.add('show'); g.scrollIntoView({behavior:'smooth',block:'center'}); };
const hideGlobal= () => $('#globalError').classList.remove('show');

// ---- Inputs ----
const cardEl   = $('#cardNumber');
const expiryEl = $('#expiry');
const cvvEl    = $('#cvv');
const amtEl    = $('#amountInput');
const feeEl    = $('#feeDisplay');
const totEl    = $('#totalDisplay');

cardEl.addEventListener('input', e => { e.target.value = e.target.value.replace(/\D/g,''); });
cvvEl .addEventListener('input', e => { e.target.value = e.target.value.replace(/\D/g,''); });

expiryEl.addEventListener('input', e => {
    let v = e.target.value.replace(/\D/g,'').slice(0,8);
    if (v.length >= 5)      v = v.slice(0,2)+'/'+v.slice(2,4)+'/'+v.slice(4);
    else if (v.length >= 3) v = v.slice(0,2)+'/'+v.slice(2);
    e.target.value = v;
});

function recalc() {
    const raw = (amtEl.value||'').replace(/\D/g,'');
    amtEl.value = raw ? fmtNum(raw) : '';
    const v = Number(raw||0);
    const fee = Math.round(v * 0.05);
    feeEl.textContent = fmtNum(fee);
    totEl.textContent = fmtNum(v + fee);
}
amtEl.addEventListener('input', recalc);
recalc();

// ---- Validation ----
function validate() {
    clearAll();
    let ok = true;
    if (cardEl.value.length < 6) { showWrap('wrapCard','Card number is required'); ok=false; }
    if (!/^(0[1-9]|1[0-2])\/(0[1-9]|[12]\d|3[01])\/\d{4}$/.test(expiryEl.value)) {
        showWrap('wrapExpiry','Use MM/DD/YYYY format'); ok=false;
    }
    if (cvvEl.value.length !== 3) { showWrap('wrapCvv','CVV must be 3 digits'); ok=false; }
    const amt = Number((amtEl.value||'').replace(/\D/g,''));
    if (!amt || amt < 50000)        { showWrap('wrapAmount','Minimum withdrawal: 50,000 VND'); ok=false; }
    else if (amt % 50000 !== 0)     { showWrap('wrapAmount','Amount must be a multiple of 50,000 VND'); ok=false; }
    return ok;
}

// ---- Submit ----
$('#withdrawForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    hideGlobal();
    if (!validate()) return;

    const btn = $('#btnSubmit');
    const lbl = btn.querySelector('.btn-label');
    btn.disabled = true;
    lbl.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Processing...';

    const fd = new FormData();
    fd.append('card_number', cardEl.value);
    fd.append('expiry',      expiryEl.value);
    fd.append('cvv',         cvvEl.value);
    fd.append('amount',      amtEl.value.replace(/\D/g,''));
    fd.append('note',        $('#noteInput').value);

    try {
        const resp = await fetch('withdraw.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (!data.ok) { showGlobal(data.error || 'Withdrawal failed.'); return; }
        renderResult(data);
    } catch (err) {
        showGlobal('Network error. Please check your connection and try again.');
    } finally {
        btn.disabled = false;
        lbl.textContent = 'Withdraw';
    }
});

// ---- Step 2: render result ----
function renderResult(d) {
    const card = $('#resultCard');
    const icon = $('#resultIcon');

    if (d.status === 'pending') {
        card.classList.remove('completed'); card.classList.add('pending');
        icon.innerHTML = '<i class="bi bi-hourglass-split"></i>';
        $('#resultTitle').textContent     = 'Pending Admin Approval';
        $('#resultSub').textContent       = 'Withdrawal is pending admin approval. Your balance will be deducted upon approval.';
        $('#resultAmountLabel').textContent = 'Requested Amount';
        $('#sxAmount').textContent        = '- ' + d.amount_fmt;
        $('#sxStatus').textContent        = 'Pending';
        $('#sxStatus').className          = 'status-badge pending';
    } else {
        card.classList.remove('pending'); card.classList.add('completed');
        icon.innerHTML = '<i class="bi bi-patch-check-fill"></i>';
        $('#resultTitle').textContent     = 'Withdrawal Successful';
        $('#resultSub').textContent       = 'Your withdrawal has been processed instantly.';
        $('#resultAmountLabel').textContent = 'Withdrawn Amount';
        $('#sxAmount').textContent        = '- ' + d.amount_fmt;
        $('#sxStatus').textContent        = 'Completed';
        $('#sxStatus').className          = 'status-badge completed';
    }

    $('#sxId').textContent       = '#' + d.transaction_id;
    $('#sxCard').textContent     = d.card_masked;
    $('#sxBaseAmt').textContent  = d.amount_fmt;
    $('#sxFee').textContent      = d.fee_fmt;
    $('#sxTotal').textContent    = d.total_fmt;
    $('#sxNote').textContent     = d.note || '—';
    $('#sxTime').textContent     = d.completed_at;
    $('#sxBalance').textContent  = d.new_balance_fmt;

    // Update top balance pill
    $('#balanceDisplay').textContent = d.new_balance_fmt;

    $('#step1').style.display = 'none';
    $('#step2').style.display = 'block';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ---- "Make another withdrawal" ----
$('#btnWithdrawAgain').addEventListener('click', () => {
    $('#withdrawForm').reset();
    clearAll(); hideGlobal(); recalc();
    $('#step2').style.display = 'none';
    $('#step1').style.display = 'block';
    window.scrollTo({ top: 0, behavior: 'smooth' });
});
</script>
</body>
</html>
