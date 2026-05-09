<?php // _layout_footer.php - closes the shared layout ?>
      </div>
    </main>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('confirmActionModal');
    if (!modalEl) return;
    const titleEl = modalEl.querySelector('[data-role="ca-title"]');
    const subEl   = modalEl.querySelector('[data-role="ca-sub"]');
    const bodyEl  = modalEl.querySelector('[data-role="ca-body"]');
    const descEl  = modalEl.querySelector('[data-role="ca-desc"]');
    const confirmBtn = modalEl.querySelector('[data-role="ca-confirm"]');
    const formEl  = modalEl.querySelector('form');

    modalEl.addEventListener('show.bs.modal', function (event) {
      const trigger = event.relatedTarget;
      if (!trigger) return;
      const title  = trigger.getAttribute('data-confirm-title')  || 'Confirm Action';
      const sub    = trigger.getAttribute('data-confirm-sub')    || 'Are you sure you want to perform this action?';
      const body   = trigger.getAttribute('data-confirm-body')   || '';
      const desc   = trigger.getAttribute('data-confirm-desc')   || '';
      const action = trigger.getAttribute('data-confirm-action') || '';
      const variant= trigger.getAttribute('data-confirm-variant')|| 'primary';
      const btnText= trigger.getAttribute('data-confirm-btn')    || 'Confirm';
      const extraInputs = trigger.getAttribute('data-confirm-inputs') || '';

      titleEl.textContent = title;
      subEl.textContent = sub;
      bodyEl.textContent = body;
      descEl.textContent = desc;

      confirmBtn.className = 'btn';
      confirmBtn.classList.add('btn-' + (variant === 'success' ? 'approve'
                               : variant === 'danger'  ? 'reject'
                               : variant === 'warning' ? 'docs'
                               : variant === 'unlock'  ? 'unlock'
                               : 'approve'));
      confirmBtn.textContent = btnText;

      // Build hidden inputs from JSON string like: {"action":"approve_user","id":"5"}
      const hiddenWrap = modalEl.querySelector('[data-role="ca-inputs"]');
      hiddenWrap.innerHTML = '';
      if (extraInputs) {
        try {
          const obj = JSON.parse(extraInputs);
          Object.keys(obj).forEach(k => {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = k; inp.value = obj[k];
            hiddenWrap.appendChild(inp);
          });
        } catch (e) { console.warn('Invalid confirm inputs json', extraInputs); }
      }
      formEl.action = action || 'admin_actions.php';
    });
  });
</script>
</body>
</html>
