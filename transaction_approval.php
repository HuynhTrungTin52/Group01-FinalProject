<?php
// transaction_approval.php
require_once '_auth.php';
require_once 'db.php';

$pageTitle = 'Transaction Approval';
$activeNav = 'transactions';

$THRESHOLD = 5000000; // VND

// Counts for big-value flows
$countPending = (int)$pdo->query(
    "SELECT COUNT(*) FROM transactions WHERE status='pending' AND amount > {$THRESHOLD}"
)->fetchColumn();
$countWithdraw = (int)$pdo->query(
    "SELECT COUNT(*) FROM transactions WHERE status='pending' AND type='withdraw' AND amount > {$THRESHOLD}"
)->fetchColumn();
$countTransfer = (int)$pdo->query(
    "SELECT COUNT(*) FROM transactions WHERE status='pending' AND type='transfer' AND amount > {$THRESHOLD}"
)->fetchColumn();

$validFilters = ['all','withdraw','transfer'];
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, $validFilters, true)) $filter = 'all';

$q = trim($_GET['q'] ?? '');

$where = "t.status='pending' AND t.amount > :threshold";
$params = [':threshold' => $THRESHOLD];
if ($filter !== 'all') {
    $where .= " AND t.type = :ttype";
    $params[':ttype'] = $filter;
}
if ($q !== '') {
    $where .= " AND (u.full_name LIKE :q OR u.email LIKE :q OR u.phone LIKE :q OR t.transaction_id = :qid)";
    $params[':q'] = "%{$q}%";
    $params[':qid'] = ctype_digit($q) ? (int)$q : 0;
}

$sql = "SELECT t.*, 
               u1.full_name AS sender_name, u1.phone AS sender_phone,
               u2.full_name AS receiver_name, u2.phone AS receiver_phone
        FROM transactions t
        LEFT JOIN users u1 ON u1.user_id = t.user_id          --  info sender
        LEFT JOIN users u2 ON u2.user_id = t.recipient_id    -- info receiver 
        WHERE $where
        ORDER BY t.transaction_id DESC
        LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$txs = $stmt->fetchAll();

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

<div class="card panel-card p-3 p-md-4" data-testid="tx-approval-panel">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
      <h4 class="fw-bold mb-1">Transaction Approval</h4>
      <div class="text-muted small">High-value pending transactions (amount &gt; <?= number_format($THRESHOLD) ?> VND)</div>
    </div>
  </div>

  <div class="pill-tabs mb-3" data-testid="tx-tabs">
    <a href="?filter=all" class="pill-tab <?= $filter==='all'?'active':'' ?>" data-testid="tab-all">
      <div><span class="dot" style="background:#eab308"></span><span class="count"><?= $countPending ?></span></div>
      <div class="label">Pending</div>
    </a>
    <a href="?filter=withdraw" class="pill-tab <?= $filter==='withdraw'?'active':'' ?>" data-testid="tab-withdraw">
      <div><span class="dot" style="background:#16a34a"></span><span class="count"><?= $countWithdraw ?></span></div>
      <div class="label">Withdraw</div>
    </a>
    <a href="?filter=transfer" class="pill-tab <?= $filter==='transfer'?'active':'' ?>" data-testid="tab-transfer">
      <div><span class="dot" style="background:#6f42c1"></span><span class="count"><?= $countTransfer ?></span></div>
      <div class="label">Transfer</div>
    </a>
  </div>

  <form method="get" class="mb-3" data-testid="tx-search-form">
    <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
    <div class="search-wrap">
      <i class="bi bi-search"></i>
      <input name="q" value="<?= htmlspecialchars($q) ?>" class="form-control search-input"
             placeholder="Search by name, email, phone, or transaction ID..." data-testid="tx-search-input">
    </div>
  </form>

  <div class="table-responsive">
    <table class="table admin-table align-middle" data-testid="tx-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Type</th>
          <th>User</th>
          <th>Phone</th>
          <th class="text-end">Amount</th>
          <th class="text-end">Fee</th>
          <th>Status</th>
          <th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$txs): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No pending high-value transactions.</td></tr>
        <?php else: foreach ($txs as $t): ?>
          <tr data-testid="tx-row-<?= (int)$t['transaction_id'] ?>">
            <td>#<?= (int)$t['transaction_id'] ?></td>
            <td class="text-capitalize"><?= htmlspecialchars($t['type']) ?></td>
            <td><?= htmlspecialchars($t['full_name'] ?? '-') ?><div class="text-muted small"><?= htmlspecialchars($t['email'] ?? '') ?></div></td>
            <td><?= htmlspecialchars($t['phone'] ?? '-') ?></td>
            <td class="text-end fw-semibold"><?= number_format((float)$t['amount']) ?></td>
            <td class="text-end"><?= number_format((float)$t['fee']) ?></td>
            <td><span class="badge-status bs-pending">Pending</span></td>
            <td class="text-end">
              <a href="transaction_details.php?id=<?= (int)$t['transaction_id'] ?>"
                 class="btn btn-sm btn-outline-primary"
                 style="border-color:#6f42c1;color:#6f42c1"
                 data-testid="view-tx-<?= (int)$t['transaction_id'] ?>">View</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include '_confirm_modal.php'; ?>
<?php include '_layout_footer.php'; ?>
