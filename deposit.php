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

    //Mock cards 
    $mock_cards = [
        ['number' => '111111', 'expiry' => '10/10/2022', 'cvv' => '411', 'limit' => null,    'always_fail' => false],
        ['number' => '222222', 'expiry' => '11/11/2022', 'cvv' => '443', 'limit' => 1000000, 'always_fail' => false],
        ['number' => '333333', 'expiry' => '12/12/2022', 'cvv' => '577', 'limit' => null,    'always_fail' => true],
    ];

    //Collect & sanitise input
    $card   = preg_replace('/\D/', '', $_POST['card_number'] ?? '');
    $expiry = trim($_POST['expiry']  ?? '');
    $cvv    = preg_replace('/\D/', '', $_POST['cvv'] ?? '');
    $amount = (int) preg_replace('/\D/', '', $_POST['amount'] ?? '0');

    //Format validation
    if (strlen($card) !== 6) {
        echo json_encode(['ok' => false, 'error' => 'Credit card number must be exactly 6 digits.']);
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
    if ($amount < 10000) {
        echo json_encode(['ok' => false, 'error' => 'Minimum deposit amount is 10,000 VND.']);
        exit;
    }

    //Look up card by number first
    $matched = null;
    foreach ($mock_cards as $c) {
        if ($c['number'] === $card) { $matched = $c; break; }
    }
    if (!$matched) {
        echo json_encode(['ok' => false, 'error' => 'this card is not supported']);
        exit;
    }

    //Verify expiry + CVV match the registered card
    if ($matched['expiry'] !== $expiry || $matched['cvv'] !== $cvv) {
        echo json_encode(['ok' => false, 'error' => 'Invalid expiry date or CVV for this card.']);
        exit;
    }

    //Business rules
    if ($matched['always_fail']) {
        echo json_encode(['ok' => false, 'error' => 'card is out of money']);
        exit;
    }
    if ($matched['limit'] !== null && $amount > $matched['limit']) {
        $limit_fmt = number_format($matched['limit'], 0, ',', '.');
        echo json_encode(['ok' => false, 'error' => "This card allows max {$limit_fmt} VND per transaction."]);
        exit;
    }

    //Persist: update balance + insert transaction
    try {
        $pdo->beginTransaction();

        $upd = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE user_id = ?");
        $upd->execute([$amount, $user_id]);
        if ($upd->rowCount() === 0) throw new Exception('User not found.');

        $note = 'Deposited from card ' . $card;
        $ins = $pdo->prepare("
            INSERT INTO transactions (user_id, type, amount, fee, status, note, created_at)
            VALUES (?, 'deposit', ?, 0, 'completed', ?, NOW())
        ");
        $ins->execute([$user_id, $amount, $note]);
        $tx_id = (int) $pdo->lastInsertId();

        // New balance for the response
        $bal = $pdo->prepare("SELECT balance FROM users WHERE user_id = ?");
        $bal->execute([$user_id]);
        $new_balance = (float) $bal->fetchColumn();

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => 'Transaction failed. Please try again.']);
        exit;
    }

    echo json_encode([
        'ok'             => true,
        'amount'         => $amount,
        'amount_fmt'     => number_format($amount, 0, ',', '.') . ' VND',
        'new_balance'    => $new_balance,
        'new_balance_fmt'=> number_format($new_balance, 0, ',', '.') . ' VND',
        'card_masked'    => '****' . substr($card, -2),
        'transaction_id' => $tx_id,
        'note'           => $note,
        'completed_at'   => date('Y-m-d H:i:s'),
    ]);
    exit;
}

// GET = render the page

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Deposit - E-Wallet</title>
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
    --text-dark:#1F1F2E;
    --text-muted:#6B7280;
    --border:#E5E7EB;
    --danger:#DC2626;
    --success:#16A34A;
    --success-bg:#D8F3DD;
}
*{font-family:'Mulish',sans-serif;}
html,body{background:var(--bg-page);color:var(--text-dark);margin:0;}
.app-container{max-width:430px;margin:0 auto;min-height:100vh;background:var(--bg-page);padding:24px 18px 40px;}
.page-title{font-weight:900;font-size:34px;letter-spacing:-1px;margin-bottom:2px;}
.page-sub{color:var(--text-muted);font-size:14px;margin-bottom:22px;}

/* Step 1 form */
.form-card{background:#fff;border-radius:22px;padding:22px 20px;box-shadow:0 4px 20px rgba(0,0,0,.05);}
.form-label{font-weight:600;font-size:13px;color:#1F1F2E;margin-bottom:6px;}
.input-wrap{position:relative;margin-bottom:14px;}
.input-wrap .form-control{border:1.5px solid var(--border);border-radius:12px;padding:12px 14px 12px 44px;font-size:14px;font-weight:500;height:48px;}
.input-wrap .form-control:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(155,149,214,.2);outline:none;}
.input-wrap.invalid .form-control{border-color:var(--danger);}
.input-wrap .icon-left{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#9CA3AF;font-size:16px;pointer-events:none;}
.input-amount{padding-left:60px !important;}
.amount-label{position:absolute;left:14px;top:50%;transform:translateY(-50%);font-weight:800;color:#1F1F2E;font-size:14px;}
.helper{font-size:12px;color:var(--text-muted);margin-top:6px;}
.field-error{font-size:12px;color:var(--danger);margin-top:4px;font-weight:600;display:none;}
.input-wrap.invalid .field-error{display:block;}

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

/* Step 2 success */
.success-card{background:var(--success-bg);border-radius:22px;padding:32px 22px 24px;text-align:center;box-shadow:0 4px 20px rgba(22,163,74,.12);}
.success-icon{width:84px;height:84px;margin:0 auto 14px;border-radius:50%;background:#B6E5C0;display:flex;align-items:center;justify-content:center;color:var(--success);font-size:42px;animation:pop .35s ease-out;}
@keyframes pop {
    0%   { transform: scale(0); opacity: 0; }
    70%  { transform: scale(1.15); opacity: 1; }
    100% { transform: scale(1); }
}
.success-title{font-weight:900;font-size:26px;color:#1F1F2E;margin-bottom:4px;}
.success-sub{color:#1F1F2E;font-size:13px;margin-bottom:18px;}

.amount-pill{background:#fff;border-radius:14px;padding:14px;margin-bottom:14px;}
.amount-pill .lbl{font-size:12px;color:var(--text-muted);font-weight:600;}
.amount-pill .val{font-size:30px;font-weight:900;color:var(--success);letter-spacing:-1px;line-height:1.1;margin-top:2px;}

.details-card{background:#fff;border-radius:14px;padding:14px 16px;font-size:13px;color:#1F1F2E;text-align:left;margin-bottom:14px;}
.details-card .row-line{display:flex;justify-content:space-between;padding:5px 0;font-weight:600;}
.details-card .row-line .lbl{color:var(--text-muted);font-weight:600;}
.details-card hr{margin:6px 0;}

.btn-back-dashboard{background:var(--primary-darker);color:#fff;border:none;height:48px;border-radius:14px;font-weight:800;font-size:15px;width:100%;text-decoration:none;display:flex;align-items:center;justify-content:center;}
.btn-back-dashboard:hover{background:#22227A;color:#fff;}
.btn-deposit-again{background:transparent;border:none;color:var(--primary-darker);font-weight:700;font-size:13px;text-decoration:underline;margin-top:10px;cursor:pointer;}

.spinner-border-sm{width:14px;height:14px;border-width:2px;}
</style>
</head>
<body>
<div class="app-container" data-testid="deposit-page">

    <h1 class="page-title" data-testid="deposit-title">Deposit</h1>
    <div class="page-sub">Top up your E-Wallet</div>

    <!-- =================== STEP 1: FORM =================== -->
    <section id="step1" data-testid="step-1">
        <div id="globalError" class="global-error" data-testid="global-error">
            <i class="bi bi-exclamation-circle-fill"></i>
            <span id="globalErrorText"></span>
        </div>

        <form class="form-card" id="depositForm" novalidate data-testid="deposit-form">
            <div class="input-wrap" id="wrapCard">
                <label class="form-label">Credit card number (6 number)*</label>
                <div style="position:relative">
                    <i class="bi bi-credit-card-2-front icon-left"></i>
                    <input type="text" name="card_number" id="cardNumber" maxlength="6" inputmode="numeric"
                           class="form-control" placeholder="123456" required
                           data-testid="input-card-number">
                </div>
                <div class="field-error">Card number must be 6 digits</div>
            </div>

            <div class="input-wrap" id="wrapExpiry">
                <label class="form-label">Expiry date * <span style="color:var(--text-muted);font-weight:500">(MM/DD/YYYY)</span></label>
                <div style="position:relative">
                    <i class="bi bi-calendar3 icon-left"></i>
                    <input type="text" name="expiry" id="expiry" maxlength="10" placeholder="MM/DD/YYYY"
                           class="form-control" required
                           data-testid="input-expiry">
                </div>
                <div class="field-error">Use MM/DD/YYYY format</div>
            </div>

            <div class="input-wrap" id="wrapCvv">
                <label class="form-label">CVV *</label>
                <div style="position:relative">
                    <i class="bi bi-lock icon-left"></i>
                    <input type="password" name="cvv" id="cvv" maxlength="3" inputmode="numeric"
                           class="form-control" placeholder="123" required
                           data-testid="input-cvv">
                </div>
                <div class="field-error">CVV must be 3 digits</div>
            </div>

            <div class="input-wrap" id="wrapAmount">
                <label class="form-label">Deposit amount *</label>
                <div style="position:relative">
                    <span class="amount-label">VND</span>
                    <input type="text" name="amount" id="amountInput"
                           class="form-control input-amount" placeholder="0" required
                           data-testid="input-amount">
                </div>
                <div class="helper">Minimum: 10,000 VND</div>
                <div class="field-error">Amount is required</div>
            </div>

            <div class="note-box">
                <div class="nb-title"><i class="bi bi-clipboard-check"></i> Note:</div>
                <ol>
                    <li>The transaction will be processed immediately</li>
                    <li>No fees are applied to deposit transactions</li>
                    <li>Please check the information carefully before confirming</li>
                </ol>
            </div>

            <div class="btn-row">
                <a href="dashboard_user.php" class="btn btn-cancel" data-testid="btn-cancel">Cancel</a>
                <button type="submit" class="btn btn-primary-c" id="btnSubmit" data-testid="btn-submit-deposit">
                    <span class="btn-label">Top up</span>
                </button>
            </div>
        </form>
    </section>

    <!-- =================== STEP 2: SUCCESS =================== -->
    <section id="step2" style="display:none" data-testid="step-2">
        <div class="success-card">
            <div class="success-icon"><i class="bi bi-patch-check-fill"></i></div>
            <div class="success-title">Transaction Successful</div>
            <div class="success-sub">Your deposit has been processed instantly.</div>

            <div class="amount-pill">
                <div class="lbl">Deposited Amount</div>
                <div class="val" id="sxAmount" data-testid="success-amount">+0 VND</div>
            </div>

            <div class="details-card">
                <div class="row-line"><span class="lbl">Transaction:</span><span></span></div>
                <hr>
                <div class="row-line"><span class="lbl">Status:</span><span style="color:var(--success);font-weight:800;">Completed</span></div>
                <div class="row-line"><span class="lbl">Card:</span><span id="sxCard" data-testid="success-card">****</span></div>
                <div class="row-line"><span class="lbl">Transaction ID:</span><span id="sxId" data-testid="success-id">—</span></div>
                <div class="row-line"><span class="lbl">Time:</span><span id="sxTime" data-testid="success-time">—</span></div>
                <div class="row-line"><span class="lbl">New Balance:</span><span id="sxBalance" data-testid="success-balance" style="font-weight:800;color:var(--primary-darker)">—</span></div>
            </div>

            <a href="dashboard_user.php" class="btn-back-dashboard" data-testid="btn-back-dashboard">Back to dashboard</a>
            <button type="button" class="btn-deposit-again" id="btnDepositAgain" data-testid="btn-deposit-again">+ Make another deposit</button>
        </div>
    </section>

</div>

<script>
// ============ Helpers ============
const $ = (sel) => document.querySelector(sel);
const fmtNum  = (n) => Number(n||0).toLocaleString('vi-VN');
const fmtVND  = (n) => fmtNum(n) + ' VND';
const showWrap = (wrapId, msg) => {
    const w = document.getElementById(wrapId);
    w.classList.add('invalid');
    if (msg) w.querySelector('.field-error').textContent = msg;
};
const clearWrap = (wrapId) => document.getElementById(wrapId).classList.remove('invalid');
const clearAllInvalid = () => document.querySelectorAll('.input-wrap.invalid').forEach(w => w.classList.remove('invalid'));
const showGlobal = (msg) => {
    const g = $('#globalError');
    $('#globalErrorText').textContent = msg;
    g.classList.add('show');
    g.scrollIntoView({behavior:'smooth', block:'center'});
};
const hideGlobal = () => $('#globalError').classList.remove('show');

// ============ Input formatting ============
const cardEl   = $('#cardNumber');
const expiryEl = $('#expiry');
const cvvEl    = $('#cvv');
const amtEl    = $('#amountInput');

cardEl.addEventListener('input', e => { e.target.value = e.target.value.replace(/\D/g,''); });
cvvEl .addEventListener('input', e => { e.target.value = e.target.value.replace(/\D/g,''); });

// MM/DD/YYYY auto-mask
expiryEl.addEventListener('input', e => {
    let v = e.target.value.replace(/\D/g,'').slice(0,8);
    if (v.length >= 5)      v = v.slice(0,2) + '/' + v.slice(2,4) + '/' + v.slice(4);
    else if (v.length >= 3) v = v.slice(0,2) + '/' + v.slice(2);
    e.target.value = v;
});

amtEl.addEventListener('input', e => {
    const raw = e.target.value.replace(/\D/g,'');
    e.target.value = raw ? fmtNum(raw) : '';
});

// ============ Client-side validation ============
function validate() {
    clearAllInvalid();
    let ok = true;

    if (cardEl.value.length !== 6) {
        showWrap('wrapCard', 'Card number must be exactly 6 digits'); ok = false;
    }
    if (!/^(0[1-9]|1[0-2])\/(0[1-9]|[12]\d|3[01])\/\d{4}$/.test(expiryEl.value)) {
        showWrap('wrapExpiry', 'Use MM/DD/YYYY format'); ok = false;
    }
    if (cvvEl.value.length !== 3) {
        showWrap('wrapCvv', 'CVV must be 3 digits'); ok = false;
    }
    const amt = Number((amtEl.value||'').replace(/\D/g,''));
    if (!amt || amt < 10000) {
        showWrap('wrapAmount', 'Minimum deposit: 10,000 VND'); ok = false;
    }
    return ok;
}

// ============ Submit (Fetch API) ============
$('#depositForm').addEventListener('submit', async (e) => {
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

    try {
        const resp = await fetch('deposit.php', { method: 'POST', body: fd });
        const data = await resp.json();

        if (!data.ok) {
            showGlobal(data.error || 'Deposit failed. Please try again.');
            return;
        }

        // ----- Step 2: success UI -----
        $('#sxAmount').textContent  = '+ ' + data.amount_fmt;
        $('#sxCard').textContent    = data.card_masked;
        $('#sxId').textContent      = '#' + data.transaction_id;
        $('#sxTime').textContent    = data.completed_at;
        $('#sxBalance').textContent = data.new_balance_fmt;

        $('#step1').style.display = 'none';
        $('#step2').style.display = 'block';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (err) {
        showGlobal('Network error. Please check your connection and try again.');
    } finally {
        btn.disabled = false;
        lbl.textContent = 'Top up';
    }
});

// ============ "Make another deposit" -> reset to step 1 ============
$('#btnDepositAgain').addEventListener('click', () => {
    $('#depositForm').reset();
    clearAllInvalid();
    hideGlobal();
    $('#step2').style.display = 'none';
    $('#step1').style.display = 'block';
    window.scrollTo({ top: 0, behavior: 'smooth' });
});
</script>
</body>
</html>
