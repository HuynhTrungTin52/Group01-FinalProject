<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once 'db.php';

$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT user_id, full_name, phone, balance FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$me) { session_destroy(); header('Location: login.php'); exit; }

$response = ['ok' => false];

// AJAX endpoints
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'lookup_phone') {
            $phone = preg_replace('/\D/', '', $_POST['phone'] ?? '');
            if (strlen($phone) < 9) throw new Exception('Invalid phone number');
            if ($phone === preg_replace('/\D/', '', $me['phone'])) throw new Exception('Cannot transfer to your own account');
            $s = $pdo->prepare("SELECT user_id, full_name, phone FROM users WHERE phone = ?");
            $s->execute([$phone]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if (!$r) throw new Exception('Recipient not found');
            echo json_encode(['ok' => true, 'recipient' => $r]);
            exit;
        }

        if ($action === 'verify_otp') {
            $otp = preg_replace('/\D/', '', $_POST['otp'] ?? '');
            verify_session_otp($otp); // throws on mismatch / expired / exceeded
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($action === 'submit') {
            $phone     = preg_replace('/\D/', '', $_POST['phone'] ?? '');
            $amount    = (int) preg_replace('/\D/', '', $_POST['amount'] ?? '0');
            $note      = trim($_POST['note'] ?? '');
            $fee_payer = $_POST['fee_payer'] ?? '';
            $otp       = preg_replace('/\D/', '', $_POST['otp'] ?? '');

            if (!in_array($fee_payer, ['sender','recipient'], true)) throw new Exception('Invalid fee payer');
            if ($amount <= 0) throw new Exception('Invalid amount');

            // ---- OTP verification against session ----
            verify_session_otp($otp);

            $s = $pdo->prepare("SELECT user_id, full_name FROM users WHERE phone = ?");
            $s->execute([$phone]);
            $rec = $s->fetch(PDO::FETCH_ASSOC);
            if (!$rec) throw new Exception('Recipient not found');
            if ((int)$rec['user_id'] === (int)$user_id) throw new Exception('Cannot transfer to yourself');

            $fee = (int) round($amount * 0.05);
            $sender_deduct = ($fee_payer === 'sender') ? ($amount + $fee) : $amount;
            $recipient_add = ($fee_payer === 'sender') ? $amount : ($amount - $fee);

            $pdo->beginTransaction();

            // Re-fetch sender balance
            $s2 = $pdo->prepare("SELECT balance FROM users WHERE user_id = ? FOR UPDATE");
            $s2->execute([$user_id]);
            $bal = (float) $s2->fetchColumn();

            if ($amount > 5000000) {
                // Pending - no deduction
                $ins = $pdo->prepare("INSERT INTO transactions
                    (user_id, recipient_id, type, amount, fee, fee_payer, status, note, created_at)
                    VALUES (?, ?, 'transfer', ?, ?, ?, 'pending', ?, NOW())");
                $ins->execute([$user_id, $rec['user_id'], $amount, $fee, $fee_payer, $note]);
                $pdo->commit();
                echo json_encode(['ok' => true, 'status' => 'pending', 'recipient_name' => $rec['full_name'], 'amount' => $amount, 'fee' => $fee, 'fee_payer' => $fee_payer, 'note' => $note]);
                exit;
            }

            if ($bal < $sender_deduct) throw new Exception('Insufficient balance');

            $u1 = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE user_id = ? AND balance >= ?");
            $u1->execute([$sender_deduct, $user_id, $sender_deduct]);
            if ($u1->rowCount() === 0) throw new Exception('Insufficient balance');

            $u2 = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE user_id = ?");
            $u2->execute([$recipient_add, $rec['user_id']]);

            $ins = $pdo->prepare("INSERT INTO transactions
                (user_id, recipient_id, type, amount, fee, fee_payer, status, note, created_at)
                VALUES (?, ?, 'transfer', ?, ?, ?, 'completed', ?, NOW())");
            $ins->execute([$user_id, $rec['user_id'], $amount, $fee, $fee_payer, $note]);

            $pdo->commit();

            // OTP single-use: invalidate after successful transfer
            unset($_SESSION['transfer_otp'], $_SESSION['transfer_otp_expires'],
                  $_SESSION['transfer_otp_attempts'], $_SESSION['transfer_otp_last_sent']);

            echo json_encode([
                'ok' => true, 'status' => 'completed',
                'recipient_name' => $rec['full_name'], 'amount' => $amount,
                'fee' => $fee, 'fee_payer' => $fee_payer, 'note' => $note
            ]);
            exit;
        }

        throw new Exception('Unknown action');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/**
 * Verify the submitted OTP against the value stored in $_SESSION
 * by send_transfer_otp.php. Throws Exception on failure.
 */
function verify_session_otp(string $submitted): void {
    if (strlen($submitted) !== 4 || !ctype_digit($submitted)) {
        throw new Exception('OTP must be 4 digits');
    }
    if (empty($_SESSION['transfer_otp']) || empty($_SESSION['transfer_otp_expires'])) {
        throw new Exception('No OTP found. Please request a new one.');
    }
    if (time() > (int) $_SESSION['transfer_otp_expires']) {
        unset($_SESSION['transfer_otp']);
        throw new Exception('OTP has expired. Please request a new one.');
    }

    $_SESSION['transfer_otp_attempts'] = (int)($_SESSION['transfer_otp_attempts'] ?? 0) + 1;
    if ($_SESSION['transfer_otp_attempts'] > 5) {
        unset($_SESSION['transfer_otp']);
        throw new Exception('Too many incorrect attempts. Please request a new OTP.');
    }
    if (!hash_equals((string)$_SESSION['transfer_otp'], $submitted)) {
        $left = 5 - (int)$_SESSION['transfer_otp_attempts'];
        throw new Exception("Incorrect OTP. {$left} attempt(s) left.");
    }
}

function fmt_vnd($amount) { return number_format((float)$amount, 0, ',', '.') . ' VND'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Money Transfer - E-Wallet</title>
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
    --step-active:#5C6BC0;
    --step-inactive:#C8D0E8;
    --info-bg:#DCDFF7;
    --warning-bg:#FFF1C9;
    --warning-border:#F4CB6A;
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
.app-container{max-width:430px;margin:0 auto;min-height:100vh;background:var(--bg-page);padding:24px 18px 40px;position:relative;}
.back-row{display:flex;align-items:center;gap:8px;margin-bottom:6px;}
.back-row a{color:#1F1F2E;text-decoration:none;font-size:22px;}
.page-title{font-weight:900;font-size:30px;letter-spacing:-1px;margin:0;}
.page-sub{color:var(--text-muted);font-size:13px;text-align:center;margin-bottom:14px;}

.title-center{text-align:center;font-weight:900;font-size:32px;letter-spacing:-1px;margin-top:18px;margin-bottom:4px;}
.subtitle-center{text-align:center;color:var(--text-muted);font-size:13px;margin-bottom:18px;}

.steps{display:flex;align-items:center;justify-content:center;gap:0;margin:18px 0 22px;}
.step-circle{width:46px;height:46px;border-radius:50%;background:var(--step-inactive);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:18px;}
.step-circle.active{background:var(--step-active);}
.step-line{width:36px;height:3px;background:var(--step-inactive);}
.step-line.active{background:var(--step-active);}

.card-form{background:#fff;border-radius:22px;padding:22px 20px;box-shadow:0 4px 20px rgba(0,0,0,.05);}

.form-label{font-weight:600;font-size:13px;color:#1F1F2E;margin-bottom:6px;}
.input-wrap{position:relative;}
.input-wrap .form-control{border:1.5px solid var(--border);border-radius:12px;padding:12px 14px 12px 44px;font-size:14px;font-weight:500;height:48px;}
.input-wrap .form-control:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(155,149,214,.2);}
.input-wrap .icon-left{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#9CA3AF;font-size:16px;}
.input-amount{padding-left:60px !important;}
.amount-label{position:absolute;left:14px;top:50%;transform:translateY(-50%);font-weight:800;color:#1F1F2E;font-size:14px;}
.helper{font-size:12px;color:var(--text-muted);margin-top:6px;}

.btn-purple{background:var(--primary);color:#fff;border:none;height:48px;border-radius:14px;font-weight:700;font-size:15px;width:100%;}
.btn-purple:hover{background:var(--primary-hover);color:#fff;}
.btn-cancel{background:#fff;border:1.5px solid var(--border);color:#1F1F2E;height:48px;border-radius:14px;font-weight:700;font-size:15px;}
.btn-row{display:flex;gap:10px;margin-top:14px;}
.btn-row .btn{flex:1;}

.recipient-pill{background:#DCDFF7;border-radius:14px;padding:10px 14px;display:flex;align-items:center;gap:10px;margin-bottom:14px;}
.recipient-pill .ava{width:34px;height:34px;border-radius:50%;background:#fff;color:var(--primary-darker);display:flex;align-items:center;justify-content:center;font-size:18px;}
.recipient-pill .name{font-weight:800;font-size:14px;}
.recipient-pill .phone{font-size:12px;color:var(--text-muted);}

textarea.form-control{height:90px !important;padding:12px 14px !important;}

.fee-applies-text{color:var(--warning-text);font-weight:800;font-size:13px;margin-top:14px;margin-bottom:8px;}

.fee-option{background:var(--warning-bg);border:1.5px solid var(--warning-border);border-radius:12px;padding:10px 14px;display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:8px;}
.fee-option input{accent-color:var(--warning-text);}
.fee-option .fo-title{font-weight:800;color:var(--warning-text);font-size:13px;}
.fee-option .fo-desc{color:var(--warning-text);font-size:11.5px;}

.confirm-box{background:#E8E9F2;border-radius:12px;padding:14px;font-size:13px;color:#1F1F2E;}
.confirm-box .row-line{display:flex;justify-content:space-between;padding:5px 0;font-weight:600;}
.confirm-box .row-line .lbl{color:var(--text-muted);font-weight:600;}
.confirm-box hr{border-top:1px solid #C7CDE0;margin:6px 0;}

.otp-input{display:flex;justify-content:center;gap:14px;margin:14px 0 6px;}
.otp-input input{width:48px;height:54px;border:1.5px solid var(--border);border-radius:10px;text-align:center;font-size:22px;font-weight:800;}
.otp-input input:focus{border-color:var(--primary);outline:none;box-shadow:0 0 0 3px rgba(155,149,214,.2);}
.otp-helper{text-align:center;font-size:12px;color:var(--text-muted);margin-bottom:12px;}

.success-card{background:var(--success-bg);border-radius:22px;padding:28px 24px;text-align:center;}
.success-icon{width:70px;height:70px;margin:0 auto 12px;border-radius:50%;background:#B6E5C0;display:flex;align-items:center;justify-content:center;color:var(--success);font-size:34px;}
.success-title{font-weight:900;font-size:26px;color:#1F1F2E;}
.success-sub{color:#1F1F2E;font-size:13px;margin-bottom:18px;}
.btn-back-dashboard{background:var(--primary-darker);color:#fff;border:none;height:48px;border-radius:14px;font-weight:800;font-size:15px;width:100%;margin-top:18px;}
.btn-back-dashboard:hover{background:#22227A;color:#fff;}

.alert-floating{margin-bottom:10px;border-radius:12px;font-size:13px;}

.home-fab{position:fixed;bottom:22px;right:50%;transform:translateX(220px);width:48px;height:48px;border-radius:50%;background:#A8C5E5;color:#fff;display:flex;align-items:center;justify-content:center;font-size:20px;text-decoration:none;box-shadow:0 4px 12px rgba(0,0,0,.15);}
@media (max-width: 460px){.home-fab{right:20px;transform:none;}}
</style>
</head>
<body>
<div class="app-container" data-testid="transfer-page">

    <!-- STEP 1: Phone -->
    <div id="step1" class="step-section" data-testid="step-1">
        <h1 class="title-center">Money Transfer</h1>
        <div class="subtitle-center">Enter the recipient's phone number</div>
        <div class="steps">
            <div class="step-circle active">1</div>
            <div class="step-line"></div>
            <div class="step-circle">2</div>
            <div class="step-line"></div>
            <div class="step-circle">3</div>
        </div>

        <div id="step1Error" class="alert alert-danger alert-floating d-none" data-testid="step1-error"></div>

        <div class="card-form">
            <label class="form-label">Recipient's Phone Number *</label>
            <div class="input-wrap">
                <i class="bi bi-telephone icon-left"></i>
                <input type="text" id="phoneInput" class="form-control" placeholder="098765432" maxlength="15" data-testid="input-phone">
            </div>
            <button class="btn btn-purple mt-3" id="btnContinue1" data-testid="btn-continue-step1">Continue</button>
        </div>

        <a href="dashboard_user.php" class="home-fab" data-testid="home-fab"><i class="bi bi-house-fill"></i></a>
    </div>

    <!-- STEP 2: Info -->
    <div id="step2" class="step-section d-none" data-testid="step-2">
        <div class="back-row">
            <a href="#" id="backToStep1" data-testid="back-to-step1"><i class="bi bi-arrow-left"></i></a>
            <h2 class="page-title">Money Transfer</h2>
        </div>
        <div class="page-sub">Enter the amount and transfer information</div>
        <div class="steps">
            <div class="step-circle active">1</div>
            <div class="step-line active"></div>
            <div class="step-circle active">2</div>
            <div class="step-line"></div>
            <div class="step-circle">3</div>
        </div>

        <div id="step2Error" class="alert alert-danger alert-floating d-none" data-testid="step2-error"></div>

        <div class="card-form">
            <div class="recipient-pill" data-testid="recipient-pill">
                <div class="ava"><i class="bi bi-person-fill"></i></div>
                <div>
                    <div class="name" id="recName">-</div>
                    <div class="phone" id="recPhone">-</div>
                </div>
            </div>

            <label class="form-label">Amount *</label>
            <div class="input-wrap">
                <span class="amount-label">VND</span>
                <input type="text" id="amountInput" class="form-control input-amount" placeholder="0" data-testid="input-amount">
            </div>
            <div class="helper">Balance: <?= fmt_vnd($me['balance']) ?></div>

            <label class="form-label mt-3">Note</label>
            <textarea id="noteInput" class="form-control" placeholder="Transfer content..." data-testid="input-note"></textarea>

            <div class="fee-applies-text">A 5% Transaction Fee Applies</div>

            <label class="fee-option" data-testid="fee-sender">
                <input type="radio" name="fee_payer" value="sender" checked>
                <div>
                    <div class="fo-title">Sender Pays</div>
                    <div class="fo-desc" id="senderDesc">You will pay 0 VND fee</div>
                </div>
            </label>
            <label class="fee-option" data-testid="fee-recipient">
                <input type="radio" name="fee_payer" value="recipient">
                <div>
                    <div class="fo-title">Recipient Pays</div>
                    <div class="fo-desc" id="recipientDesc">The recipient will pay 0 VND fee</div>
                </div>
            </label>

            <div class="btn-row">
                <button class="btn btn-cancel" id="btnCancel2" data-testid="btn-cancel-step2">Cancel</button>
                <button class="btn btn-purple" id="btnContinue2" data-testid="btn-continue-step2">Continue</button>
            </div>
        </div>
    </div>

    <!-- STEP 3: OTP -->
    <div id="step3" class="step-section d-none" data-testid="step-3">
        <div class="back-row">
            <a href="#" id="backToStep2" data-testid="back-to-step2"><i class="bi bi-arrow-left"></i></a>
            <h2 class="page-title">Money Transfer</h2>
        </div>
        <div class="page-sub">Confirm transaction with OTP code</div>
        <div class="steps">
            <div class="step-circle active">1</div>
            <div class="step-line active"></div>
            <div class="step-circle active">2</div>
            <div class="step-line active"></div>
            <div class="step-circle active">3</div>
        </div>

        <div id="step3Error" class="alert alert-danger alert-floating d-none" data-testid="step3-error"></div>

        <div class="card-form">
            <div class="confirm-box" data-testid="confirm-box">
                <div class="row-line"><span class="lbl">ConFirm Information:</span><span></span></div>
                <hr>
                <div class="row-line"><span class="lbl">Recipient:</span><span id="cfRec">-</span></div>
                <div class="row-line"><span class="lbl">Amount:</span><span id="cfAmt">0 VND</span></div>
                <div class="row-line"><span class="lbl">Transaction Fee (5%):</span><span id="cfFee">0 VND</span></div>
                <div class="row-line"><span class="lbl">Fee Payer:</span><span id="cfPayer">Sender</span></div>
            </div>

            <label class="form-label mt-3">Enter OTP code *</label>
            <div class="otp-input" data-testid="otp-input-group">
                <input type="text" maxlength="1" class="otp-cell" data-testid="otp-1">
                <input type="text" maxlength="1" class="otp-cell" data-testid="otp-2">
                <input type="text" maxlength="1" class="otp-cell" data-testid="otp-3">
                <input type="text" maxlength="1" class="otp-cell" data-testid="otp-4">
            </div>
            <div class="otp-helper">The OTP has been sent to your email/ phone number</div>

            <button class="btn btn-purple" id="btnConfirmTransfer" data-testid="btn-confirm-transfer">Confirm Transfer</button>
            <button class="btn btn-cancel mt-2 w-100" id="btnResendOtp" data-testid="btn-resend-otp">Resent OTP</button>
        </div>
    </div>

    <!-- STEP 4: Success -->
    <div id="step4" class="step-section d-none" data-testid="step-4">
        <div class="success-card">
            <div class="success-icon"><i class="bi bi-patch-check-fill"></i></div>
            <div class="success-title" id="successTitle">Transfer Successful!</div>
            <div class="success-sub" id="successSub">Your transaction has been processed successfully.</div>

            <div class="confirm-box text-start" style="background:#fff">
                <div class="row-line"><span class="lbl">Confirm Information:</span><span></span></div>
                <hr>
                <div class="row-line"><span class="lbl">Recipient:</span><span id="sxRec">-</span></div>
                <div class="row-line"><span class="lbl">Amount:</span><span id="sxAmt">0 VND</span></div>
                <div class="row-line"><span class="lbl">Transaction Fee (5%):</span><span id="sxFee">0 VND</span></div>
                <div class="row-line"><span class="lbl">Fee Payer:</span><span id="sxPayer">Sender</span></div>
                <div class="row-line"><span class="lbl">Note:</span><span id="sxNote">-</span></div>
            </div>

            <a href="dashboard_user.php" class="btn btn-back-dashboard d-block" data-testid="btn-back-dashboard">Back to dashboard</a>
        </div>
    </div>

</div>

<script>
const myBalance = <?= (int)$me['balance'] ?>;
const state = { phone:'', recipient:null, amount:0, note:'', fee_payer:'sender' };

function fmtNum(n){ return Number(n||0).toLocaleString('vi-VN'); }
function fmtVND(n){ return fmtNum(n) + ' VND'; }
function show(id){
    ['step1','step2','step3','step4'].forEach(s=>{
        document.getElementById(s).classList.toggle('d-none', s!==id);
    });
    window.scrollTo({top:0, behavior:'smooth'});
}
function showErr(id, msg){
    const el = document.getElementById(id);
    el.textContent = msg;
    el.classList.remove('d-none');
}
function clearErr(id){ document.getElementById(id).classList.add('d-none'); }

// Phone digit only
const phoneInput = document.getElementById('phoneInput');
phoneInput.addEventListener('input', e=>{ e.target.value = e.target.value.replace(/\D/g,''); });

// Step 1 -> 2
document.getElementById('btnContinue1').addEventListener('click', async ()=>{
    clearErr('step1Error');
    const phone = phoneInput.value.trim();
    if (phone.length < 9) { showErr('step1Error','Please enter a valid phone number'); return; }
    const fd = new FormData();
    fd.append('action','lookup_phone'); fd.append('phone', phone);
    const r = await fetch('transfer.php', {method:'POST', body:fd});
    const j = await r.json();
    if (!j.ok) { showErr('step1Error', j.error || 'Recipient not found'); return; }
    state.phone = phone;
    state.recipient = j.recipient;
    document.getElementById('recName').textContent = j.recipient.name;
    document.getElementById('recPhone').textContent = j.recipient.phone;
    show('step2');
});

// Amount input
const amountInput = document.getElementById('amountInput');
amountInput.addEventListener('input', e=>{
    let raw = e.target.value.replace(/\D/g,'');
    e.target.value = raw ? fmtNum(raw) : '';
    updateFeeDescriptions();
});
function updateFeeDescriptions(){
    const v = Number((amountInput.value||'').replace(/\D/g,''));
    const fee = Math.round(v * 0.05);
    document.getElementById('senderDesc').textContent = 'You will pay '+fmtVND(fee)+' fee';
    document.getElementById('recipientDesc').textContent = 'The recipient will pay '+fmtVND(fee)+' fee';
}
updateFeeDescriptions();

// Step 2 back / cancel
document.getElementById('backToStep1').addEventListener('click', e=>{e.preventDefault();show('step1');});
document.getElementById('btnCancel2').addEventListener('click', ()=>{ window.location.href='dashboard.php'; });

// Step 2 -> 3 (sends OTP via email, then advances on success)
document.getElementById('btnContinue2').addEventListener('click', async ()=>{
    clearErr('step2Error');
    const amt = Number((amountInput.value||'').replace(/\D/g,''));
    if (!amt || amt <= 0) { showErr('step2Error','Please enter a valid amount'); return; }
    const fp = document.querySelector('input[name="fee_payer"]:checked').value;
    const fee = Math.round(amt * 0.05);
    const senderDeduct = (fp==='sender') ? (amt+fee) : amt;
    if (amt <= 5000000 && senderDeduct > myBalance) {
        showErr('step2Error','Insufficient balance for this transfer');
        return;
    }
    state.amount    = amt;
    state.note      = document.getElementById('noteInput').value.trim();
    state.fee_payer = fp;

    // ---- Request OTP email BEFORE showing Step 3 ----
    const btn = document.getElementById('btnContinue2');
    const origText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending OTP...';
    try {
        const resp = await fetch('send_transfer_otp.php', { method: 'POST' });
        const j = await resp.json();
        if (!j.ok) {
            showErr('step2Error', j.error || 'Failed to send OTP. Please try again.');
            return;
        }
        // Populate confirm box & advance
        document.getElementById('cfRec').textContent   = state.recipient.name;
        document.getElementById('cfAmt').textContent   = fmtVND(amt);
        document.getElementById('cfFee').textContent   = fmtVND(fee);
        document.getElementById('cfPayer').textContent = fp==='sender' ? 'Sender' : 'Recipient';

        document.querySelectorAll('.otp-cell').forEach(i=>i.value='');
        const helper = document.querySelector('.otp-helper');
        if (helper && j.sent_to) helper.textContent = 'The OTP has been sent to ' + j.sent_to;
        show('step3');
        setTimeout(()=>document.querySelector('.otp-cell').focus(), 100);
    } catch (err) {
        showErr('step2Error', 'Network error. Please check your connection.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = origText;
    }
});

// Step 3 back
document.getElementById('backToStep2').addEventListener('click', e=>{e.preventDefault();show('step2');});

// OTP cell auto-advance
const cells = document.querySelectorAll('.otp-cell');
cells.forEach((c, i)=>{
    c.addEventListener('input', e=>{
        e.target.value = e.target.value.replace(/\D/g,'').slice(0,1);
        if (e.target.value && i < cells.length-1) cells[i+1].focus();
    });
    c.addEventListener('keydown', e=>{
        if (e.key === 'Backspace' && !c.value && i>0) cells[i-1].focus();
    });
});

document.getElementById('btnResendOtp').addEventListener('click', async ()=>{
    clearErr('step3Error');
    const btn = document.getElementById('btnResendOtp');
    const orig = btn.textContent;
    btn.disabled = true; btn.textContent = 'Sending...';
    try {
        const r = await fetch('send_transfer_otp.php', {method:'POST'});
        const j = await r.json();
        if (!j.ok) { showErr('step3Error', j.error || 'Failed to resend OTP'); return; }
        const helper = document.querySelector('.otp-helper');
        if (helper && j.sent_to) helper.textContent = 'A new OTP has been sent to ' + j.sent_to;
        cells.forEach(c=>c.value=''); cells[0].focus();
    } finally {
        btn.disabled = false; btn.textContent = orig;
    }
});

// Step 3 confirm -> submit
document.getElementById('btnConfirmTransfer').addEventListener('click', async ()=>{
    clearErr('step3Error');
    const otp = Array.from(cells).map(c=>c.value).join('');
    if (otp.length !== 4) { showErr('step3Error','Please enter the 4-digit OTP'); return; }

    const fd = new FormData();
    fd.append('action','submit');
    fd.append('phone', state.phone);
    fd.append('amount', state.amount);
    fd.append('note', state.note);
    fd.append('fee_payer', state.fee_payer);
    fd.append('otp', otp);

    const r = await fetch('transfer.php', {method:'POST', body:fd});
    const j = await r.json();
    if (!j.ok) { showErr('step3Error', j.error || 'Transfer failed'); return; }

    document.getElementById('sxRec').textContent = j.recipient_name;
    document.getElementById('sxAmt').textContent = fmtVND(j.amount);
    document.getElementById('sxFee').textContent = fmtVND(j.fee);
    document.getElementById('sxPayer').textContent = j.fee_payer==='sender' ? 'Sender' : 'Recipient';
    document.getElementById('sxNote').textContent = j.note || '-';
    if (j.status === 'pending') {
        document.getElementById('successTitle').textContent = 'Transfer Pending';
        document.getElementById('successSub').textContent = 'Transactions over 5,000,000 VND require admin approval.';
    }
    show('step4');
});
</script>
</body>
</html>
