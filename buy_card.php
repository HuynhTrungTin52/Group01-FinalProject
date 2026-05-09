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

    // ---- 1. Carrier prefixes & valid denominations ---------------------
    $CARRIER_PREFIX = [
        'Viettel'   => '11111',
        'Mobifone'  => '22222',
        'Vinaphone' => '33333',
    ];
    $VALID_DENOMS = [10000, 20000, 50000, 100000];
    $MAX_QUANTITY = 5;
    $FEE          = 0;

    // ---- 2. Sanitise input --------------------------------------------
    $carrier      = trim($_POST['carrier'] ?? '');
    $denomination = (int) ($_POST['denomination'] ?? 0);
    $quantity     = (int) ($_POST['quantity'] ?? 0);

    // ---- 3. Validation -------------------------------------------------
    if (!isset($CARRIER_PREFIX[$carrier])) {
        echo json_encode(['ok' => false, 'error' => 'Please select a valid carrier (Viettel, Mobifone, or Vinaphone).']);
        exit;
    }
    if (!in_array($denomination, $VALID_DENOMS, true)) {
        echo json_encode(['ok' => false, 'error' => 'Please select a valid denomination (10,000 / 20,000 / 50,000 / 100,000 VND).']);
        exit;
    }
    if ($quantity < 1 || $quantity > $MAX_QUANTITY) {
        echo json_encode(['ok' => false, 'error' => "Quantity must be between 1 and {$MAX_QUANTITY}."]);
        exit;
    }

    $total = $denomination * $quantity;

    // ---- 4. Persist (balance check + deduct + insert) ------------------
    try {
        $pdo->beginTransaction();

        // Lock user balance
        $bal_stmt = $pdo->prepare("SELECT balance FROM users WHERE user_id = ? FOR UPDATE");
        $bal_stmt->execute([$user_id]);
        $balance = $bal_stmt->fetchColumn();
        if ($balance === false) throw new Exception('User not found.');
        $balance = (float) $balance;

        if ($balance < $total) {
            $pdo->rollBack();
            echo json_encode([
                'ok'    => false,
                'error' => 'Insufficient balance. Required ' . number_format($total, 0, ',', '.') . ' VND.'
            ]);
            exit;
        }

        // ---- 5. Generate card codes (prefix + 5 random digits) ---------
        $prefix = $CARRIER_PREFIX[$carrier];
        $codes  = [];
        for ($i = 0; $i < $quantity; $i++) {
            $codes[] = $prefix . str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        }
        $note = 'Codes: ' . implode(', ', $codes);

        // ---- 6. Deduct balance ----------------------------------------
        $upd = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE user_id = ? AND balance >= ?");
        $upd->execute([$total, $user_id, $total]);
        if ($upd->rowCount() === 0) throw new Exception('Insufficient balance');

        // ---- 7. Insert transaction (fee stored even if 0) -------------
        $ins = $pdo->prepare("
            INSERT INTO transactions (user_id, type, amount, fee, status, note, card_code, created_at)
            VALUES (?, 'buy_card', ?, ?, 'completed', ?, ?, NOW())
        ");
        $ins->execute([$user_id, $total, $FEE, $note, implode(', ', $codes)]);
        $tx_id = (int) $pdo->lastInsertId();

        $new_balance = $balance - $total;

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => 'Transaction failed. Please try again.']);
        exit;
    }

    // ---- 8. JSON response ---------------------------------------------
    $f = fn($n) => number_format((float)$n, 0, ',', '.') . ' VND';
    echo json_encode([
        'ok'              => true,
        'carrier'         => $carrier,
        'denomination'    => $denomination,
        'denomination_fmt'=> $f($denomination),
        'quantity'        => $quantity,
        'total'           => $total,
        'total_fmt'       => $f($total),
        'fee'             => $FEE,
        'fee_fmt'         => $f($FEE),
        'codes'           => $codes,
        'new_balance'     => $new_balance,
        'new_balance_fmt' => $f($new_balance),
        'transaction_id'  => $tx_id,
        'completed_at'    => date('Y-m-d H:i:s'),
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

$VALID_DENOMS = [10000, 20000, 50000, 100000];
function fmt_vnd($amount) { return number_format((float)$amount, 0, ',', '.') . ' VND'; }
function fmt_amt_label($n){ return number_format((float)$n, 0, ',', '.'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mobile Top-Up - E-Wallet</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Mulish:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
    --bg-page:#E8F4F8;
    --primary:#9B95D6;
    --primary-hover:#857FCB;
    --primary-dark:#3D3D8C;
    --primary-darker:#2D2D7C;
    --info-bg:#DCDFF7;
    --balance-pill:#C1CBED;
    --balance-pill-text:#1B2A6B;
    --warning-bg:#FFF1C9;
    --warning-text:#A05A00;
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
.page-title{font-weight:900;font-size:32px;letter-spacing:-1px;margin-bottom:2px;}
.page-sub{color:var(--text-muted);font-size:13px;margin-bottom:18px;}

.balance-pill{background:var(--balance-pill);border-radius:14px;padding:14px 18px;color:var(--balance-pill-text);margin-bottom:14px;}
.balance-pill .label{display:flex;align-items:center;gap:6px;font-weight:700;font-size:14px;}
.balance-pill .amt{font-weight:900;font-size:22px;margin-top:4px;}

.form-card{background:#fff;border-radius:22px;padding:20px;box-shadow:0 4px 20px rgba(0,0,0,.05);}
.section-label{font-weight:800;font-size:14px;margin-bottom:10px;}

.carriers{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:18px;}
.carrier-tile{background:#fff;border:1.5px solid var(--border);border-radius:16px;padding:12px;display:flex;flex-direction:column;align-items:center;gap:8px;cursor:pointer;transition:all .15s ease;}
.carrier-tile:hover{border-color:var(--primary);}
.carrier-tile.active{border-color:var(--primary);box-shadow:0 0 0 3px rgba(155,149,214,.25);}
.carrier-circle{width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:13px;color:#fff;}
.c-viettel{background:#F5C2C2;color:#D32A2A;}
.c-mobifone{background:#E5E5E5;color:#1F1F2E;}
.c-vinaphone{background:#BEE2F2;color:#0084C7;}
.carrier-name{font-size:12.5px;font-weight:700;}

.amounts{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:18px;}
.amt-tile{border:1.5px solid var(--border);background:#fff;border-radius:12px;padding:12px;text-align:center;cursor:pointer;font-weight:700;font-size:14px;transition:all .15s ease;}
.amt-tile:hover{border-color:var(--primary);}
.amt-tile.active{border-color:var(--primary);background:#EEEDFB;color:var(--primary-darker);box-shadow:0 0 0 3px rgba(155,149,214,.18);}

.qty-row{display:flex;align-items:center;justify-content:space-between;border:1.5px solid var(--border);border-radius:12px;padding:10px 14px;margin-bottom:18px;}
.qty-row .lbl{font-weight:600;font-size:13px;}
.qty-controls{display:flex;align-items:center;gap:8px;}
.qty-controls button{width:32px;height:32px;border:1px solid var(--border);background:#F9FAFB;border-radius:8px;font-weight:700;font-size:16px;cursor:pointer;}
.qty-controls button:disabled{opacity:.4;cursor:not-allowed;}
.qty-controls input{width:46px;text-align:center;border:1.5px solid var(--border);border-radius:8px;height:32px;font-weight:700;}

.total-pill{background:#EFEFFA;border-radius:12px;padding:14px 16px;display:flex;justify-content:space-between;align-items:center;}
.total-pill .lbl{font-weight:800;font-size:14px;}
.total-pill .val{font-weight:900;font-size:18px;color:#0F2BA8;}

.note-box{background:var(--info-bg);border-radius:12px;padding:14px 16px;color:var(--primary-dark);font-size:12.5px;margin-top:16px;}
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

/* Step 2: success */
.success-card{background:var(--success-bg);border-radius:22px;padding:30px 22px 24px;text-align:center;box-shadow:0 4px 20px rgba(22,163,74,.12);}
.success-icon{width:78px;height:78px;margin:0 auto 12px;border-radius:50%;background:#B6E5C0;display:flex;align-items:center;justify-content:center;color:var(--success);font-size:38px;animation:pop .35s ease-out;}
@keyframes pop {
    0% { transform: scale(0); opacity: 0; }
    70% { transform: scale(1.15); opacity: 1; }
    100% { transform: scale(1); }
}
.success-title{font-weight:900;font-size:26px;color:#1F1F2E;margin-bottom:4px;}
.success-sub{color:#1F1F2E;font-size:13px;margin-bottom:18px;}

.details-card{background:#fff;border-radius:14px;padding:14px 16px;font-size:13px;color:#1F1F2E;text-align:left;margin-bottom:12px;}
.details-card .row-line{display:flex;justify-content:space-between;padding:5px 0;font-weight:600;}
.details-card .row-line .lbl{color:var(--text-muted);font-weight:600;}
.details-card hr{margin:6px 0;}

.codes-list{margin-bottom:12px;}
.code-pill{background:#fff;border-radius:12px;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;border:1px dashed #B6E5C0;}
.code-pill .idx{font-size:11px;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;}
.code-pill .code{font-weight:900;font-size:18px;color:#0F2BA8;letter-spacing:2px;font-variant-numeric:tabular-nums;}
.code-pill .copy-btn{background:transparent;border:none;color:var(--primary-darker);font-size:18px;cursor:pointer;padding:4px 8px;border-radius:6px;transition:background .15s ease;}
.code-pill .copy-btn:hover{background:#EFEFFA;}
.code-pill .copy-btn.copied{color:var(--success);}

.copy-all-btn{background:#fff;border:1.5px solid var(--success);color:var(--success);border-radius:12px;padding:10px 14px;font-weight:700;font-size:13px;width:100%;margin-bottom:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;}
.copy-all-btn:hover{background:#F0FAF3;}
.copy-all-btn.copied{background:var(--success);color:#fff;}

.save-warn{background:var(--warning-bg);color:var(--warning-text);border-radius:10px;padding:8px 12px;font-size:12px;margin-bottom:12px;font-weight:600;display:flex;align-items:flex-start;gap:6px;text-align:left;}

.btn-back-dashboard{background:var(--primary-darker);color:#fff;border:none;height:48px;border-radius:14px;font-weight:800;font-size:15px;width:100%;text-decoration:none;display:flex;align-items:center;justify-content:center;}
.btn-back-dashboard:hover{background:#22227A;color:#fff;}
.btn-link-secondary{background:transparent;border:none;color:var(--primary-darker);font-weight:700;font-size:13px;text-decoration:underline;margin-top:10px;cursor:pointer;}

.spinner-border-sm{width:14px;height:14px;border-width:2px;}
</style>
</head>
<body>
<div class="app-container" data-testid="buycard-page">

    <h1 class="page-title" data-testid="buycard-title">Mobile Top-Up</h1>
    <div class="page-sub">Buy mobile prepaid cards quickly and easily</div>

    <div class="balance-pill" data-testid="balance-pill">
        <div class="label"><i class="bi bi-info-circle"></i> Current balance</div>
        <div class="amt" id="balanceDisplay" data-testid="current-balance"><?= fmt_vnd($balance) ?></div>
    </div>

    <!-- ============ STEP 1: FORM ============ -->
    <section id="step1" data-testid="step-1">
        <div id="globalError" class="global-error" data-testid="global-error">
            <i class="bi bi-exclamation-circle-fill"></i>
            <span id="globalErrorText"></span>
        </div>

        <form class="form-card" id="buyForm" data-testid="buy-form" novalidate>
            <input type="hidden" name="carrier"      id="carrierField"      value="">
            <input type="hidden" name="denomination" id="denominationField" value="">

            <div class="section-label">Select Carrier *</div>
            <div class="carriers">
                <div class="carrier-tile" data-carrier="Viettel" data-testid="carrier-viettel">
                    <div class="carrier-circle c-viettel">viettel</div>
                    <div class="carrier-name">Viettel</div>
                </div>
                <div class="carrier-tile" data-carrier="Mobifone" data-testid="carrier-mobifone">
                    <div class="carrier-circle c-mobifone">mobifone</div>
                    <div class="carrier-name">Mobifone</div>
                </div>
                <div class="carrier-tile" data-carrier="Vinaphone" data-testid="carrier-vinaphone">
                    <div class="carrier-circle c-vinaphone">vinaphone</div>
                    <div class="carrier-name">Vinaphone</div>
                </div>
            </div>

            <div class="section-label">Select Amount *</div>
            <div class="amounts">
                <?php foreach ($VALID_DENOMS as $d): ?>
                <div class="amt-tile" data-amount="<?= $d ?>" data-testid="amount-<?= $d ?>"><?= fmt_amt_label($d) ?></div>
                <?php endforeach; ?>
            </div>

            <div class="qty-row">
                <span class="lbl">Quantity (max 5) *:</span>
                <div class="qty-controls">
                    <button type="button" id="qtyMinus" data-testid="qty-minus">−</button>
                    <input type="text" name="quantity" id="qtyInput" value="1" readonly data-testid="input-quantity">
                    <button type="button" id="qtyPlus" data-testid="qty-plus">+</button>
                </div>
            </div>

            <div class="total-pill">
                <span class="lbl">Total Amount:</span>
                <span class="val" id="totalDisplay" data-testid="total-amount">0 VND</span>
            </div>

            <div class="note-box">
                <div class="nb-title"><i class="bi bi-clipboard-check"></i> Note:</div>
                <ol>
                    <li>The card code will be displayed immediately after payment</li>
                    <li>You can purchase up to 5 cards per transaction</li>
                    <li>Please save your card codes after purchase</li>
                </ol>
            </div>

            <div class="btn-row">
                <a href="dashboard_user.php" class="btn btn-cancel" data-testid="btn-cancel">Cancel</a>
                <button type="submit" class="btn btn-primary-c" id="btnSubmit" data-testid="btn-buy-card">
                    <span class="btn-label">Buy Card</span>
                </button>
            </div>
        </form>
    </section>

    <!-- ============ STEP 2: SUCCESS ============ -->
    <section id="step2" style="display:none" data-testid="step-2">
        <div class="success-card">
            <div class="success-icon"><i class="bi bi-patch-check-fill"></i></div>
            <div class="success-title">Purchase Successful!</div>
            <div class="success-sub">Here are your card details</div>

            <div class="details-card">
                <div class="row-line"><span class="lbl">Details:</span><span></span></div>
                <hr>
                <div class="row-line"><span class="lbl">Carrier:</span><span id="sxCarrier" data-testid="success-carrier">—</span></div>
                <div class="row-line"><span class="lbl">Quantity:</span><span id="sxQty" data-testid="success-quantity">—</span></div>
                <div class="row-line"><span class="lbl">Denomination:</span><span id="sxDenom" data-testid="success-denomination">—</span></div>
                <div class="row-line"><span class="lbl">Total Amount:</span><span id="sxTotal" data-testid="success-total" style="font-weight:800;color:var(--primary-darker)">—</span></div>
                <div class="row-line"><span class="lbl">Transaction ID:</span><span id="sxId">—</span></div>
                <div class="row-line"><span class="lbl">Time:</span><span id="sxTime">—</span></div>
                <div class="row-line"><span class="lbl">New Balance:</span><span id="sxBalance" style="font-weight:800;color:var(--primary-darker)">—</span></div>
            </div>

            <button type="button" class="copy-all-btn" id="copyAllBtn" data-testid="btn-copy-all">
                <i class="bi bi-clipboard-check"></i> <span class="ca-label">Copy all codes</span>
            </button>

            <div class="codes-list" id="codesList" data-testid="codes-list">
                <!-- generated codes inserted by JS -->
            </div>

            <div class="save-warn">
                <i class="bi bi-exclamation-triangle-fill mt-1"></i>
                <span>Please save your card codes. You can also view them later in <a href="transaction_history.php" style="color:inherit;font-weight:800">Transaction History</a>.</span>
            </div>

            <a href="dashboard_user.php" class="btn-back-dashboard" data-testid="btn-back-dashboard">Back to dashboard</a>
            <button type="button" class="btn-link-secondary" id="btnBuyAgain" data-testid="btn-buy-again">+ Buy more cards</button>
        </div>
    </section>

</div>

<script>
const $ = (sel) => document.querySelector(sel);
const fmtNum = (n) => Number(n||0).toLocaleString('vi-VN');
const fmtVND = (n) => fmtNum(n) + ' VND';
const showGlobal = (m) => { const g=$('#globalError'); $('#globalErrorText').textContent=m; g.classList.add('show'); g.scrollIntoView({behavior:'smooth',block:'center'}); };
const hideGlobal = () => $('#globalError').classList.remove('show');

const carrierField = $('#carrierField');
const denomField   = $('#denominationField');
const qtyInput     = $('#qtyInput');
const totalDisplay = $('#totalDisplay');

// ---- Tile selection ----
document.querySelectorAll('.carrier-tile').forEach(tile => {
    tile.addEventListener('click', () => {
        document.querySelectorAll('.carrier-tile').forEach(t => t.classList.remove('active'));
        tile.classList.add('active');
        carrierField.value = tile.dataset.carrier;
        hideGlobal();
    });
});
document.querySelectorAll('.amt-tile').forEach(tile => {
    tile.addEventListener('click', () => {
        document.querySelectorAll('.amt-tile').forEach(t => t.classList.remove('active'));
        tile.classList.add('active');
        denomField.value = tile.dataset.amount;
        recalc();
        hideGlobal();
    });
});

// ---- Quantity controls ----
function updateQtyButtons() {
    const q = Number(qtyInput.value || 1);
    $('#qtyMinus').disabled = q <= 1;
    $('#qtyPlus').disabled  = q >= 5;
}
function recalc() {
    const d = Number(denomField.value || 0);
    const q = Number(qtyInput.value || 0);
    totalDisplay.textContent = fmtVND(d * q);
    updateQtyButtons();
}
$('#qtyMinus').addEventListener('click', () => {
    let q = Math.max(1, Number(qtyInput.value || 1) - 1);
    qtyInput.value = q; recalc();
});
$('#qtyPlus').addEventListener('click', () => {
    let q = Math.min(5, Number(qtyInput.value || 1) + 1);
    qtyInput.value = q; recalc();
});
recalc();

// ---- Submit ----
$('#buyForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    hideGlobal();

    if (!carrierField.value) { showGlobal('Please select a carrier.'); return; }
    if (!denomField.value)   { showGlobal('Please select an amount.'); return; }

    const btn = $('#btnSubmit');
    const lbl = btn.querySelector('.btn-label');
    btn.disabled = true;
    lbl.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Processing...';

    const fd = new FormData();
    fd.append('carrier',      carrierField.value);
    fd.append('denomination', denomField.value);
    fd.append('quantity',     qtyInput.value);

    try {
        const resp = await fetch('buy_card.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (!data.ok) { showGlobal(data.error || 'Purchase failed.'); return; }
        renderSuccess(data);
    } catch (err) {
        showGlobal('Network error. Please check your connection and try again.');
    } finally {
        btn.disabled = false;
        lbl.textContent = 'Buy Card';
    }
});

// ---- Step 2: render success ----
function renderSuccess(d) {
    $('#sxCarrier').textContent = d.carrier;
    $('#sxQty').textContent     = d.quantity + ' card' + (d.quantity > 1 ? 's' : '');
    $('#sxDenom').textContent   = d.denomination_fmt;
    $('#sxTotal').textContent   = d.total_fmt;
    $('#sxId').textContent      = '#' + d.transaction_id;
    $('#sxTime').textContent    = d.completed_at;
    $('#sxBalance').textContent = d.new_balance_fmt;

    // Render code pills
    const list = $('#codesList');
    list.innerHTML = '';
    d.codes.forEach((code, idx) => {
        const pill = document.createElement('div');
        pill.className = 'code-pill';
        pill.dataset.testid = 'code-pill-' + idx;
        pill.innerHTML = `
            <div>
                <div class="idx">Card #${idx + 1}</div>
                <div class="code" data-code="${code}">${code}</div>
            </div>
            <button type="button" class="copy-btn" title="Copy" data-testid="copy-${idx}">
                <i class="bi bi-clipboard"></i>
            </button>
        `;
        pill.querySelector('.copy-btn').addEventListener('click', (e) => copyCode(code, e.currentTarget));
        list.appendChild(pill);
    });

    // Top balance pill update
    $('#balanceDisplay').textContent = d.new_balance_fmt;

    $('#step1').style.display = 'none';
    $('#step2').style.display = 'block';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ---- Copy single code ----
async function copyCode(code, btn) {
    try {
        await navigator.clipboard.writeText(code);
        const ic = btn.querySelector('i');
        const old = ic.className;
        ic.className = 'bi bi-check2';
        btn.classList.add('copied');
        setTimeout(() => { ic.className = old; btn.classList.remove('copied'); }, 1500);
    } catch (e) { /* clipboard might be unavailable; silent */ }
}

// ---- Copy all ----
$('#copyAllBtn').addEventListener('click', async (e) => {
    const codes = Array.from(document.querySelectorAll('.code-pill .code')).map(el => el.dataset.code);
    if (!codes.length) return;
    const text = codes.join('\n');
    try {
        await navigator.clipboard.writeText(text);
        const btn = e.currentTarget;
        const lbl = btn.querySelector('.ca-label');
        const old = lbl.textContent;
        lbl.textContent = 'Copied!';
        btn.classList.add('copied');
        setTimeout(() => { lbl.textContent = old; btn.classList.remove('copied'); }, 1500);
    } catch (err) { /* silent */ }
});

// ---- "+ Buy more cards" ----
$('#btnBuyAgain').addEventListener('click', () => {
    $('#buyForm').reset();
    document.querySelectorAll('.carrier-tile.active, .amt-tile.active').forEach(el => el.classList.remove('active'));
    carrierField.value = ''; denomField.value = ''; qtyInput.value = 1;
    recalc(); hideGlobal();
    $('#step2').style.display = 'none';
    $('#step1').style.display = 'block';
    window.scrollTo({ top: 0, behavior: 'smooth' });
});
</script>
</body>
</html>
