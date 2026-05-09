<?php
// transaction_details.php
require_once '_auth.php';
require_once 'db.php';

$txId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($txId <= 0) { header('Location: transaction_approval.php'); exit; }

$stmt = $pdo->prepare("SELECT t.*, u.full_name AS sender_name, u.email AS sender_email,
                              u.phone AS sender_phone, u.balance AS sender_balance
                       FROM transactions t
                       LEFT JOIN users u ON u.user_id = t.user_id
                       WHERE t.transaction_id = :transaction_id LIMIT 1");
$stmt->execute([':transaction_id' => $txId]);
$tx = $stmt->fetch();
if (!$tx) {
    $_SESSION['flash'] = ['type'=>'danger','message'=>'Transaction not found.'];
    header('Location: transaction_approval.php');
    exit;
}

// Optional receiver info if the transactions table has receiver_id or receiver_user_id
$receiver = null;
$receiverId = $tx['receiver_id'] ?? ($tx['receiver_user_id'] ?? null);
if ($tx['type'] === 'transfer' && $receiverId) {
    $rs = $pdo->prepare("SELECT user_id, full_name, email, phone, balance FROM users WHERE user_id = :user_id");
    $rs->execute([':user_id' => $receiverId]);
    $receiver = $rs->fetch();
}

$total = (float)$tx['amount'] + (float)$tx['fee'];
$senderBalanceOk = ((float)$tx['sender_balance'] >= $total);

$pageTitle = 'Transaction Details';
$activeNav = 'transactions';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

include '_layout.php';
?>

<?php if ($flash): ?>
  <div class="alert alert-<?= htmlspecialchars($flash['type'] ?? 'info') ?> alert-dismissible fade show" data-testid="flash-message">
    <?= htmlspecialchars($flash['message'] ?? '') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<a href="transaction_approval.php" class="text-decoration-none text-muted mb-3 d-inline-block" data-testid="back-link">
  <i class="bi bi-chevron-left"></i> Back
</a>

<div class="card panel-card p-3 p-md-4 mb-4" data-testid="tx-details-card">
  <div class="d-flex justify-content-between flex-wrap align-items-start gap-3">
    <div class="d-flex align-items-center gap-3">
      <div style="width:58px;height:58px;border-radius:50%;background:#ded6fb;display:flex;align-items:center;justify-content:center;color:#6f42c1;font-weight:700">
        <?= strtoupper(substr($tx['sender_name'] ?? 'U',0,1)) ?>
      </div>
      <div>
        <div class="fw-bold" data-testid="tx-user-name"><?= htmlspecialchars($tx['sender_name'] ?? 'Unknown') ?></div>
        <div class="text-muted small"><?= htmlspecialchars($tx['sender_email'] ?? '') ?> • <?= htmlspecialchars($tx['sender_phone'] ?? '') ?></div>
        <span class="badge-status bs-pending mt-1 d-inline-block">Pending</span>
      </div>
    </div>
    <div class="text-end">
      <div class="text-muted small text-uppercase"><?= htmlspecialchars($tx['type']) ?></div>
      <div class="fs-3 fw-bold text-danger" data-testid="tx-amount">-<?= number_format((float)$tx['amount']) ?> VND</div>
    </div>
  </div>

  <hr class="my-4">

  <div class="row g-3">
    <div class="col-md-6">
      <div class="text-muted small">Transaction ID</div>
      <div class="fw-semibold" data-testid="tx-id">#<?= (int)$tx['transaction_id'] ?></div>
    </div>
    <div class="col-md-6">
      <div class="text-muted small">Type</div>
      <div class="fw-semibold text-capitalize"><?= htmlspecialchars($tx['type']) ?></div>
    </div>
    <div class="col-md-6">
      <div class="text-muted small">Sender Balance</div>
      <div class="fw-semibold"><?= number_format((float)$tx['sender_balance']) ?> VND</div>
    </div>
    <div class="col-md-6">
      <div class="text-muted small">Fee</div>
      <div class="fw-semibold"><?= number_format((float)$tx['fee']) ?> VND</div>
    </div>
    <?php if ($receiver): ?>
    <div class="col-md-6">
      <div class="text-muted small">Receiver</div>
      <div class="fw-semibold"><?= htmlspecialchars($receiver['full_name']) ?></div>
      <div class="text-muted small"><?= htmlspecialchars($receiver['phone']) ?></div>
    </div>
    <div class="col-md-6">
      <div class="text-muted small">Receiver Balance</div>
      <div class="fw-semibold"><?= number_format((float)$receiver['balance']) ?> VND</div>
    </div>
    <?php endif; ?>
  </div>

  <hr class="my-4">

  <h6 class="fw-bold mb-2">Transaction Breakdown</h6>
  <div class="p-3 rounded-3" style="background:#ececf2;">
    <div class="d-flex justify-content-between"><span class="text-muted">Amount</span><span><?= number_format((float)$tx['amount']) ?> VND</span></div>
    <div class="d-flex justify-content-between mt-2"><span class="text-muted">Fee</span><span><?= number_format((float)$tx['fee']) ?> VND</span></div>
    <div class="d-flex justify-content-between mt-2"><span class="text-muted">Total debit</span><span class="fw-semibold text-danger"><?= number_format($total) ?> VND</span></div>
    <div class="d-flex justify-content-between mt-2"><span class="text-muted">Sender balance after</span>
      <span class="fw-semibold <?= $senderBalanceOk?'':'text-danger' ?>">
        <?= number_format((float)$tx['sender_balance'] - $total) ?> VND
      </span>
    </div>
  </div>

  <?php if (!$senderBalanceOk): ?>
    <div class="ready-banner mt-3" data-testid="insufficient-warning">
      <strong>Warning:</strong> Sender balance is insufficient to cover amount + fee.
      Approve action will be rejected by the server.
    </div>
  <?php else: ?>
    <div class="ready-banner mt-3" data-testid="ready-banner">
      <strong>Ready for approval.</strong> The sender has sufficient balance.
      Review the transaction carefully before confirming.
    </div>
  <?php endif; ?>

  <div class="d-flex justify-content-end gap-2 mt-4" data-testid="tx-actions">
    <?php if ($tx['type'] === 'withdraw'): ?>
      <button type="button" class="btn btn-approve px-4"
              data-bs-toggle="modal" data-bs-target="#confirmActionModal"
              data-confirm-title="Confirm Action"
              data-confirm-sub="Are you sure you want to perform this action?"
              data-confirm-body="Approve Withdraw"
              data-confirm-desc="The withdrawal will be processed. The user's balance will be deducted."
              data-confirm-btn="Confirm"
              data-confirm-variant="success"
              data-confirm-action="admin_actions.php"
              data-confirm-inputs='<?= json_encode(["action"=>"approve_withdraw","tx_id"=>$tx['transaction_id']]) ?>'
              data-testid="btn-approve-withdraw">
        <i class="bi bi-check-circle"></i> Approve Withdraw
      </button>
      <button type="button" class="btn btn-reject px-4"
              data-bs-toggle="modal" data-bs-target="#confirmActionModal"
              data-confirm-title="Confirm Action"
              data-confirm-sub="Are you sure you want to perform this action?"
              data-confirm-body="Reject Withdrawal"
              data-confirm-desc="This withdrawal request will be rejected and the transaction will be cancelled. No balance changes."
              data-confirm-btn="Confirm"
              data-confirm-variant="danger"
              data-confirm-action="admin_actions.php"
              data-confirm-inputs='<?= json_encode(["action"=>"reject_transaction","tx_id"=>$tx['transaction_id']]) ?>'
              data-testid="btn-reject-withdraw">
        <i class="bi bi-x-circle"></i> Reject
      </button>
    <?php else: /* transfer */ ?>
      <button type="button" class="btn btn-approve px-4"
              data-bs-toggle="modal" data-bs-target="#confirmActionModal"
              data-confirm-title="Confirm Action"
              data-confirm-sub="Are you sure you want to perform this action?"
              data-confirm-body="Approve Transfer"
              data-confirm-desc="The amount will be deducted from the sender and credited to the receiver."
              data-confirm-btn="Confirm"
              data-confirm-variant="success"
              data-confirm-action="admin_actions.php"
              data-confirm-inputs='<?= json_encode(["action"=>"approve_transfer","tx_id"=>$tx['transaction_id']]) ?>'
              data-testid="btn-approve-transfer">
        <i class="bi bi-check-circle"></i> Approve Transfer
      </button>
      <button type="button" class="btn btn-reject px-4"
              data-bs-toggle="modal" data-bs-target="#confirmActionModal"
              data-confirm-title="Confirm Action"
              data-confirm-sub="Are you sure you want to perform this action?"
              data-confirm-body="Reject Transfer"
              data-confirm-desc="This transfer request will be rejected and the transaction will be cancelled. No balance changes."
              data-confirm-btn="Confirm"
              data-confirm-variant="danger"
              data-confirm-action="admin_actions.php"
              data-confirm-inputs='<?= json_encode(["action"=>"reject_transaction","tx_id"=>$tx['transaction_id']]) ?>'
              data-testid="btn-reject-transfer">
        <i class="bi bi-x-circle"></i> Reject
      </button>
    <?php endif; ?>
  </div>
</div>

<?php include '_confirm_modal.php'; ?>
<?php include '_layout_footer.php'; ?>
