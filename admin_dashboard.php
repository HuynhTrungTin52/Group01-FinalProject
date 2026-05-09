<?php
require_once '_auth.php';
require_once 'db.php';

$pageTitle = 'Admin Dashboard';
$activeNav = 'dashboard';

// ---------- Summary metrics ----------
$totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalTransactions = (int)$pdo->query("SELECT COUNT(*) FROM transactions")->fetchColumn();
$totalRevenue = (float)$pdo->query("SELECT COALESCE(SUM(fee),0) FROM transactions WHERE status='completed'")->fetchColumn();
$pendingTransactions = (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE status='pending'")->fetchColumn();

// ---------- Tab counts ----------
$countPending   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='pending' AND (is_permanently_locked=0 OR is_permanently_locked IS NULL)")->fetchColumn();
$countVerified  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='verified' AND (is_permanently_locked=0 OR is_permanently_locked IS NULL)")->fetchColumn();
$countDisabled  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='disabled' AND (is_permanently_locked=0 OR is_permanently_locked IS NULL)")->fetchColumn();
$countLocked    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_permanently_locked=1")->fetchColumn();

// ---------- Current tab + search ----------
$validTabs = ['pending','verified','disabled','locked'];
$tab = $_GET['tab'] ?? 'pending';
if (!in_array($tab, $validTabs, true)) $tab = 'pending';
$q = trim($_GET['q'] ?? '');

$params = [];
$where = '';
if ($tab === 'locked') {
    $where = "WHERE is_permanently_locked = 1";
} else {
    $where = "WHERE status = :status AND (is_permanently_locked = 0 OR is_permanently_locked IS NULL)";
    $params[':status'] = $tab;
}

if ($q !== '') {
    $where .= ($where ? " AND " : " WHERE ") . "(full_name LIKE :q OR email LIKE :q OR phone LIKE :q)";
    $params[':q'] = "%{$q}%";
}

$sql = "SELECT user_id, full_name, email, phone, status, is_permanently_locked, balance
        FROM users $where
        ORDER BY user_id DESC
        LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// ---------- Flash message ----------
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

<!-- Summary cards -->
<div class="row g-3 mb-4" data-testid="summary-cards">
  <div class="col-6 col-lg-3">
    <div class="card stat-card p-3 h-100" data-testid="card-total-users">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-label">Total Users</div>
          <div class="stat-value mt-2"><?= number_format($totalUsers) ?></div>
        </div>
        <i class="bi bi-people-fill stat-icon"></i>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card p-3 h-100" data-testid="card-total-transactions">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-label">Total Transactions</div>
          <div class="stat-value mt-2"><?= number_format($totalTransactions) ?></div>
        </div>
        <i class="bi bi-arrow-left-right stat-icon"></i>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card p-3 h-100" data-testid="card-total-revenue">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-label">Total Revenue (fees)</div>
          <div class="stat-value mt-2"><?= number_format($totalRevenue) ?></div>
          <div class="small text-muted">VND</div>
        </div>
        <i class="bi bi-cash-stack stat-icon"></i>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card p-3 h-100" data-testid="card-pending-transactions">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-label">Pending Transactions</div>
          <div class="stat-value mt-2"><?= number_format($pendingTransactions) ?></div>
        </div>
        <i class="bi bi-hourglass-split stat-icon"></i>
      </div>
    </div>
  </div>
</div>

<!-- User Account Management -->
<div class="card panel-card p-3 p-md-4 mb-4" id="users" data-testid="user-management-panel">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
      <h4 class="fw-bold mb-1">User Account Management</h4>
      <div class="text-muted small">Manage accounts by status</div>
    </div>
  </div>

  <!-- Tabs -->
  <div class="pill-tabs mb-3" data-testid="user-tabs">
    <a href="?tab=pending"  class="pill-tab <?= $tab==='pending'?'active':'' ?>"  data-testid="tab-pending">
      <div><span class="dot" style="background:#eab308"></span><span class="count"><?= $countPending ?></span></div>
      <div class="label">Pending</div>
    </a>
    <a href="?tab=verified" class="pill-tab <?= $tab==='verified'?'active':'' ?>" data-testid="tab-verified">
      <div><span class="dot" style="background:#16a34a"></span><span class="count"><?= $countVerified ?></span></div>
      <div class="label">Verified</div>
    </a>
    <a href="?tab=disabled" class="pill-tab <?= $tab==='disabled'?'active':'' ?>" data-testid="tab-disabled">
      <div><span class="dot" style="background:#dc2626"></span><span class="count"><?= $countDisabled ?></span></div>
      <div class="label">Disabled</div>
    </a>
    <a href="?tab=locked"   class="pill-tab <?= $tab==='locked'?'active':'' ?>"   data-testid="tab-locked">
      <div><span class="dot" style="background:#6f42c1"></span><span class="count"><?= $countLocked ?></span></div>
      <div class="label">Permanent Lock</div>
    </a>
  </div>

  <!-- Search -->
  <form method="get" class="mb-3" data-testid="user-search-form">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
    <div class="search-wrap">
      <i class="bi bi-search"></i>
      <input name="q" value="<?= htmlspecialchars($q) ?>" class="form-control search-input"
             placeholder="Search by name, email or phone number..." data-testid="user-search-input">
    </div>
  </form>

  <!-- Table -->
  <div class="table-responsive">
    <table class="table admin-table align-middle" data-testid="users-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Name</th>
          <th>Email</th>
          <th>Phone</th>
          <th class="text-end">Balance (VND)</th>
          <th>Status</th>
          <th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$users): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No users found.</td></tr>
        <?php else: foreach ($users as $u): ?>
          <tr data-testid="user-row-<?= (int)$u['user_id'] ?>">
            <td>#<?= (int)$u['user_id'] ?></td>
            <td><?= htmlspecialchars($u['full_name'] ?? '') ?></td>
            <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
            <td><?= htmlspecialchars($u['phone'] ?? '') ?></td>
            <td class="text-end"><?= number_format((float)$u['balance']) ?></td>
            <td>
              <?php
                if ((int)$u['is_permanently_locked'] === 1) {
                  echo '<span class="badge-status bs-locked">Permanent Lock</span>';
                } else {
                  $st = $u['status'];
                  $cls = 'bs-' . ($st ?: 'pending');
                  echo '<span class="badge-status '.$cls.'">'.htmlspecialchars(ucfirst($st)).'</span>';
                }
              ?>
            </td>
            <td class="text-end">
              <a href="user_details.php?id=<?= (int)$u['user_id'] ?>"
                 class="btn btn-sm btn-outline-primary"
                 style="border-color:#6f42c1;color:#6f42c1"
                 data-testid="view-user-<?= (int)$u['user_id'] ?>">View</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include '_confirm_modal.php'; ?>
<?php include '_layout_footer.php'; ?>
