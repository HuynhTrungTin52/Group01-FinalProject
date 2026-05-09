<?php
// user_details.php
require_once '_auth.php';
require_once 'db.php';

$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($userId <= 0) { header('Location: admin_dashboard.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = :id LIMIT 1");
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();
if (!$user) {
    $_SESSION['flash'] = ['type'=>'danger','message'=>'User not found.'];
    header('Location: admin_dashboard.php');
    exit;
}

// Recent transactions for this user
$tStmt = $pdo->prepare("SELECT transaction_id, type, amount, fee, status
                        FROM transactions
                        WHERE user_id = :uid
                        ORDER BY transaction_id DESC
                        LIMIT 20");
$tStmt->execute([':uid' => $userId]);
$userTx = $tStmt->fetchAll();

// Candidate ID images (simple convention: uploads/{user_id}_front.* and _back.*)
$uploadsDir = __DIR__ . '/uploads';
$idFront = null; $idBack = null;
$exts = ['jpg','jpeg','png','webp'];
foreach ($exts as $ext) {
    $fFront = $uploadsDir . "/{$userId}_front.{$ext}";
    if (!$idFront && is_file($fFront)) $idFront = "uploads/{$userId}_front.{$ext}";
    $fBack = $uploadsDir . "/{$userId}_back.{$ext}";
    if (!$idBack && is_file($fBack)) $idBack = "uploads/{$userId}_back.{$ext}";
}

$pageTitle = 'Account Details';
$activeNav = 'users';

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

<a href="admin_dashboard.php" class="text-decoration-none text-muted mb-3 d-inline-block" data-testid="back-link">
  <i class="bi bi-chevron-left"></i> Back
</a>

<div class="card panel-card p-3 p-md-4 mb-4" data-testid="user-details-card">
  <div class="row g-4">
    <div class="col-lg-6">
      <div class="d-flex align-items-center gap-3 mb-3">
        <div style="width:64px;height:64px;border-radius:50%;background:#ded6fb;display:flex;align-items:center;justify-content:center;color:#6f42c1;font-weight:700;font-size:1.3rem">
          <?= strtoupper(substr($user['full_name'] ?? 'U',0,1)) ?>
        </div>
        <div>
          <h4 class="mb-0 fw-bold" data-testid="user-name"><?= htmlspecialchars($user['full_name'] ?? '') ?></h4>
          <div class="text-muted small">User ID: #<?= (int)$user['user_id'] ?></div>
          <div class="mt-1">
           <?php
               $isLocked = isset($user['is_permanently_locked']) && (int)$user['is_permanently_locked'] === 1;

                 if ($isLocked) {
                  echo '<span class="badge rounded-pill bg-dark text-white px-3 py-1">Permanent Lock</span>';
               } else {
                  $st = !empty($user['status']) ? $user['status'] : 'pending';
                  $cls = 'bs-' . $st;
                  $display = htmlspecialchars(ucfirst(str_replace('_', ' ', $st)));
                  echo '<span class="badge-status '.$cls.'">'.$display.'</span>';
               }
            ?>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-sm-6">
          <div class="text-muted small">Email</div>
          <div class="fw-semibold" data-testid="user-email"><?= htmlspecialchars($user['email'] ?? '-') ?></div>
        </div>
        <div class="col-sm-6">
          <div class="text-muted small">Phone</div>
          <div class="fw-semibold" data-testid="user-phone"><?= htmlspecialchars($user['phone'] ?? '-') ?></div>
        </div>
        <div class="col-sm-6">
          <div class="text-muted small">Balance</div>
          <div class="fw-semibold" data-testid="user-balance"><?= number_format((float)$user['balance']) ?> VND</div>
        </div>
        <div class="col-sm-6">
          <div class="text-muted small">Status</div>
          <div class="fw-semibold text-capitalize"><?= htmlspecialchars($user['status']) ?></div>
        </div>
      </div>
    </div>

    <?php
      $uploadPath = 'uploads/'; 
      $idFront = !empty($user['id_front_image']) ? $uploadPath . $user['id_front_image'] : null;
      $idBack  = !empty($user['id_back_image'])  ? $uploadPath . $user['id_back_image']  : null;
    ?>

<div class="col-lg-6">
  <div class="text-muted small mb-2">ID Images</div>
  <div class="row g-3">
    <div class="col-6">
      <div class="id-image" data-testid="id-front" style="border: 1px solid #eee; border-radius: 8px; height: 160px; display: flex; align-items: center; justify-content: center; overflow: hidden; background: #fdfdfd;">
        <?php if ($idFront && file_exists($idFront)): ?>
          <img src="<?= htmlspecialchars($idFront) ?>" alt="ID Front" style="width: 100%; height: 100%; object-fit: contain;">
        <?php else: ?>
          <div class="text-center small text-muted">Front<br>(not uploaded)</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-6">
      <div class="id-image" data-testid="id-back" style="border: 1px solid #eee; border-radius: 8px; height: 160px; display: flex; align-items: center; justify-content: center; overflow: hidden; background: #fdfdfd;">
        <?php if ($idBack && file_exists($idBack)): ?>
          <img src="<?= htmlspecialchars($idBack) ?>" alt="ID Back" style="width: 100%; height: 100%; object-fit: contain;">
        <?php else: ?>
          <div class="text-center small text-muted">Back<br>(not uploaded)</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

  <hr class="my-4">

  <!-- Actions -->
  <div class="d-flex flex-wrap gap-2" data-testid="user-actions">
    <?php if ((int)$user['is_permanently_locked'] === 1): ?>
      <button type="button" class="btn btn-unlock px-4"
              data-bs-toggle="modal" data-bs-target="#confirmActionModal"
              data-confirm-title="Confirm Action"
              data-confirm-sub="Are you sure you want to perform this action?"
              data-confirm-body="Unlock Account"
              data-confirm-desc="The account will be unlocked and failed login attempts reset to zero."
              data-confirm-btn="Confirm"
              data-confirm-variant="unlock"
              data-confirm-action="admin_actions.php"
              data-confirm-inputs='<?= json_encode(["action"=>"unlock_user","user_id"=>$user['user_id']]) ?>'
              data-testid="btn-unlock">
        <i class="bi bi-unlock-fill"></i> Unlock
      </button>
    <?php else: ?>
      <button type="button" class="btn btn-approve px-4"
              data-bs-toggle="modal" data-bs-target="#confirmActionModal"
              data-confirm-title="Confirm Action"
              data-confirm-sub="Are you sure you want to perform this action?"
              data-confirm-body="Approve Account"
              data-confirm-desc="This account will be activated and the user will gain full access to all features."
              data-confirm-btn="Confirm"
              data-confirm-variant="success"
              data-confirm-action="admin_actions.php"
              data-confirm-inputs='<?= json_encode(["action"=>"approve_user","user_id"=>$user['user_id']]) ?>'
              data-testid="btn-approve">
        <i class="bi bi-check-circle"></i> Approve
      </button>

      <button type="button" class="btn btn-reject px-4"
              data-bs-toggle="modal" data-bs-target="#confirmActionModal"
              data-confirm-title="Confirm Action"
              data-confirm-sub="Are you sure you want to perform this action?"
              data-confirm-body="Disable Account"
              data-confirm-desc="This account will be disabled and the user will no longer be able to log in."
              data-confirm-btn="Confirm"
              data-confirm-variant="danger"
              data-confirm-action="admin_actions.php"
              data-confirm-inputs='<?= json_encode(["action"=>"disable_user","user_id"=>$user['user_id']]) ?>'
              data-testid="btn-disable">
        <i class="bi bi-x-circle"></i> Reject / Disable
      </button>

      <button type="button" class="btn btn-docs px-4"
              data-bs-toggle="modal" data-bs-target="#confirmActionModal"
              data-confirm-title="Confirm Action"
              data-confirm-sub="Are you sure you want to perform this action?"
              data-confirm-body="Request Additional Documents"
              data-confirm-desc="The user will be asked to re-upload their ID card / KYC document images."
              data-confirm-btn="Confirm"
              data-confirm-variant="warning"
              data-confirm-action="admin_actions.php"
              data-confirm-inputs='<?= json_encode(["action"=>"request_docs","user_id"=>$user['user_id']]) ?>'
              data-testid="btn-request-docs">
        <i class="bi bi-file-earmark-plus"></i> Request Additional Documents
      </button>

      <button type="button" class="btn btn-dark px-4 ms-2"
      data-bs-toggle="modal" data-bs-target="#confirmActionModal"
      data-confirm-title="Confirm Permanent Lock"
      data-confirm-sub="Are you absolutely sure?"
      data-confirm-body="Permanent Lock Account"
      data-confirm-desc="This user will be permanently banned. All transactions will be frozen, and they cannot log in again."
      data-confirm-btn="Lock Account"
      data-confirm-variant="dark"
      data-confirm-action="admin_actions.php"
      data-confirm-inputs='<?= json_encode(["action"=>"permanent_lock","user_id"=>$user['user_id']]) ?>'
      data-testid="btn-permanent-lock">
        <i class="bi bi-lock-fill"></i> Permanent Lock
      </button>
    <?php endif; ?>
  </div>
</div>

<!-- Recent Transactions -->
<div class="card panel-card p-3 p-md-4 mb-4">
  <h5 class="fw-bold mb-3">Recent Transactions</h5>
  <div class="table-responsive">
    <table class="table admin-table align-middle" data-testid="user-tx-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Type</th>
          <th class="text-end">Amount</th>
          <th class="text-end">Fee</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$userTx): ?>
        <tr><td colspan="5" class="text-center text-muted py-3">No transactions yet.</td></tr>
      <?php else: foreach ($userTx as $t): ?>
        <tr>
          <td>#<?= (int)$t['transaction_id'] ?></td>
          <td class="text-capitalize"><?= htmlspecialchars($t['type']) ?></td>
          <td class="text-end"><?= number_format((float)$t['amount']) ?></td>
          <td class="text-end"><?= number_format((float)$t['fee']) ?></td>
          <td>
            <?php
              $cls = 'bs-' . $t['status'];
              echo '<span class="badge-status '.$cls.'">'.htmlspecialchars(ucfirst($t['status'])).'</span>';
            ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include '_confirm_modal.php'; ?>
<?php include '_layout_footer.php'; ?>
