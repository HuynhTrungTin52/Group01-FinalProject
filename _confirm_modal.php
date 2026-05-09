<?php // _confirm_modal.php - Shared Bootstrap 5 confirm modal used across pages ?>
<!-- Confirm Action Modal (Bootstrap 5) -->
<div class="modal fade" id="confirmActionModal" tabindex="-1" aria-hidden="true" data-testid="confirm-action-modal">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:18px;border:0;">
      <form method="post" action="admin_actions.php">
        <div class="modal-body p-4">
          <h4 class="fw-bold mb-1" data-role="ca-title">Confirm Action</h4>
          <p class="text-muted small mb-3" data-role="ca-sub">Are you sure you want to perform this action?</p>

          <div class="p-3 rounded-3" style="background:#ececf2;">
            <div class="fw-semibold" data-role="ca-body">Action</div>
            <div class="text-muted small mt-1" data-role="ca-desc">Description of the action.</div>
          </div>

          <div data-role="ca-inputs"></div>

          <div class="d-flex justify-content-between align-items-center gap-2 mt-4">
            <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal" data-testid="ca-cancel">Cancel</button>
            <button type="submit" class="btn btn-approve px-4" data-role="ca-confirm" data-testid="ca-confirm">Confirm</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>
