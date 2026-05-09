<?php
// admin_actions.php - All admin POST actions (user KYC & transaction approvals).
require_once '_auth.php';
require_once 'db.php';

function redirectBack(string $url, string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectBack('admin_dashboard.php', 'danger', 'Invalid request method.');
}

$action = $_POST['action'] ?? '';

try {
    switch ($action) {

        // ---------- USER ACTIONS ----------
        case 'approve_user': {
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0) redirectBack('admin_dashboard.php', 'danger', 'Invalid user.');
            $stmt = $pdo->prepare("UPDATE users SET status='verified' WHERE user_id = :id");
            $stmt = $pdo->prepare("UPDATE users SET status='verified', id_status='verified' WHERE user_id = :id");
            $stmt->execute([':id' => $userId]);
            redirectBack("user_details.php?id={$userId}", 'success', 'Account approved and set to verified.');
        }

        case 'disable_user': {
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0) redirectBack('admin_dashboard.php', 'danger', 'Invalid user.');
            $stmt = $pdo->prepare("UPDATE users SET status='disabled' WHERE user_id = :id");
            $stmt->execute([':id' => $userId]);
            redirectBack("user_details.php?id={$userId}", 'success', 'Account has been disabled.');
        }

        case 'request_docs': {
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0) redirectBack('admin_dashboard.php', 'danger', 'Invalid user.');
            $stmt = $pdo->prepare("UPDATE users SET status='updating' WHERE user_id = :id");
            $stmt = $pdo->prepare("UPDATE users SET status='updating', id_status='unverified' WHERE user_id = :id");
            $stmt->execute([':id' => $userId]);
            redirectBack("user_details.php?id={$userId}", 'success', 'User has been asked to upload new documents.');
        }

        case 'unlock_user': {
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0) redirectBack('admin_dashboard.php', 'danger', 'Invalid user.');
            // Reset permanent lock + failed_attempts. Tolerate schemas without failed_attempts.
            try {
                $stmt = $pdo->prepare("UPDATE users SET is_permanently_locked = 0, failed_attempts = 0 WHERE user_id = :id");
                $stmt->execute([':id' => $userId]);
            } catch (PDOException $e) {
                $stmt = $pdo->prepare("UPDATE users SET is_permanently_locked = 0 WHERE user_id = :id");
                $stmt->execute([':id' => $userId]);
            }
            redirectBack("user_details.php?id={$userId}", 'success', 'Account unlocked.');
        }

        case 'permanent_lock': {
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0) redirectBack('admin_dashboard.php', 'danger', 'Invalid user.');
            
            $stmt = $pdo->prepare("UPDATE users SET is_permanently_locked = 1 WHERE user_id = :id");
            $stmt->execute([':id' => $userId]);
            
            redirectBack("user_details.php?id={$userId}", 'success', 'User account has been permanently locked.');
        }
        // ---------- TRANSACTION ACTIONS ----------
        case 'approve_withdraw': {
            $txId = (int)($_POST['tx_id'] ?? 0);
            if ($txId <= 0) redirectBack('transaction_approval.php', 'danger', 'Invalid transaction.');

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT t.*, u.balance AS user_balance
                                   FROM transactions t
                                   JOIN users u ON u.user_id = t.user_id
                                   WHERE t.transaction_id = :transaction_id
                                   FOR UPDATE");
            $stmt->execute([':transaction_id' => $txId]);
            $tx = $stmt->fetch();
            if (!$tx)                           { $pdo->rollBack(); redirectBack('transaction_approval.php','danger','Transaction not found.'); }
            if ($tx['status'] !== 'pending')    { $pdo->rollBack(); redirectBack('transaction_approval.php','warning','Transaction is not pending anymore.'); }
            if ($tx['type'] !== 'withdraw')     { $pdo->rollBack(); redirectBack('transaction_approval.php','danger','Transaction type mismatch.'); }

            $total = (float)$tx['amount'] + (float)$tx['fee'];
            if ((float)$tx['user_balance'] < $total) {
                $pdo->rollBack();
                redirectBack("transaction_details.php?id={$txId}", 'danger', 'Insufficient balance. Withdraw cannot be approved.');
            }

            $u = $pdo->prepare("UPDATE users SET balance = balance - :t WHERE user_id = :uid");
            $u->execute([':t' => $total, ':uid' => $tx['user_id']]);

            $up = $pdo->prepare("UPDATE transactions SET status='completed' WHERE transaction_id = :id");
            $up->execute([':id' => $txId]);

            $pdo->commit();
            redirectBack("transaction_details.php?id={$txId}", 'success', 'Withdrawal approved and balance deducted.');
        }

        case 'approve_transfer': {
            $txId = (int)($_POST['tx_id'] ?? 0);
            if ($txId <= 0) redirectBack('transaction_approval.php', 'danger', 'Invalid transaction.');

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM transactions WHERE transaction_id = :id FOR UPDATE");
            $stmt->execute([':id' => $txId]);
            $tx = $stmt->fetch();
            if (!$tx)                         { $pdo->rollBack(); redirectBack('transaction_approval.php','danger','Transaction not found.'); }
            if ($tx['status'] !== 'pending')  { $pdo->rollBack(); redirectBack('transaction_approval.php','warning','Transaction is not pending anymore.'); }
            if ($tx['type'] !== 'transfer')   { $pdo->rollBack(); redirectBack('transaction_approval.php','danger','Transaction type mismatch.'); }

            $receiverId = $tx['recipient_id'] ?? null;
            if (!$receiverId) { $pdo->rollBack(); redirectBack("transaction_details.php?id={$txId}", 'danger', 'Transfer is missing receiver.'); }

            // Lock sender
            $s = $pdo->prepare("SELECT balance FROM users WHERE user_id = :id FOR UPDATE");
            $s->execute([':id' => $tx['user_id']]);
            $sender = $s->fetch();
            if (!$sender) { $pdo->rollBack(); redirectBack("transaction_details.php?id={$txId}", 'danger', 'Sender not found.'); }

            $total = (float)$tx['amount'] + (float)$tx['fee'];
            if ((float)$sender['balance'] < $total) {
                $pdo->rollBack();
                redirectBack("transaction_details.php?id={$txId}", 'danger', 'Insufficient sender balance. Transfer cannot be approved.');
            }

            // Lock receiver
            $r = $pdo->prepare("SELECT user_id FROM users WHERE user_id = :id FOR UPDATE");
            $r->execute([':id' => $receiverId]);
            if (!$r->fetch()) { $pdo->rollBack(); redirectBack("transaction_details.php?id={$txId}", 'danger', 'Receiver not found.'); }

            // Debit sender (amount + fee)
            $d = $pdo->prepare("UPDATE users SET balance = balance - :t WHERE user_id = :uid");
            $d->execute([':t' => $total, ':uid' => $tx['user_id']]);

            // Credit receiver (amount only; fee is platform revenue)
            $c = $pdo->prepare("UPDATE users SET balance = balance + :a WHERE user_id = :uid");
            $c->execute([':a' => (float)$tx['amount'], ':uid' => $receiverId]);

            // Mark transaction completed
            $up = $pdo->prepare("UPDATE transactions SET status='completed' WHERE transaction_id = :id");
            $up->execute([':id' => $txId]);

            $pdo->commit();
            redirectBack("transaction_details.php?id={$txId}", 'success', 'Transfer approved. Balances updated.');
        }

        case 'reject_transaction': {
            $txId = (int)($_POST['tx_id'] ?? 0);
            if ($txId <= 0) redirectBack('transaction_approval.php', 'danger', 'Invalid transaction.');

            $stmt = $pdo->prepare("SELECT status FROM transactions WHERE transaction_id = :id");
            $stmt->execute([':id' => $txId]);
            $row = $stmt->fetch();
            if (!$row) redirectBack('transaction_approval.php', 'danger', 'Transaction not found.');
            if ($row['status'] !== 'pending') {
                redirectBack("transaction_details.php?id={$txId}", 'warning', 'Transaction is not pending anymore.');
            }

            $up = $pdo->prepare("UPDATE transactions SET status='cancelled' WHERE transaction_id = :id");
            $up->execute([':id' => $txId]);
            redirectBack("transaction_details.php?id={$txId}", 'success', 'Transaction rejected and cancelled. No balances were changed.');
        }

        default:
            redirectBack('admin_dashboard.php', 'danger', 'Unknown action.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    redirectBack('admin_dashboard.php', 'danger', 'Operation failed: ' . $e->getMessage());
}
