(() => {
  const form = document.getElementById('new-address')?.querySelector('form');
  const modalElement = document.getElementById('delete-address-modal');
  const modal = modalElement ? bootstrap.Modal.getOrCreateInstance(modalElement) : null;
  let deleteForm = null;

  document.getElementById('new-address-button')?.addEventListener('click', () => {
    const addressId = form?.querySelector('[name="address_id"]');
    const action = form?.querySelector('[name="action"]');
    if (!form || !addressId || !action) return;
    addressId.value = '';
    action.value = 'add';
    document.querySelector('#new-address h2').textContent = 'Add a billing address';
    form.querySelectorAll('input:not([type="hidden"])').forEach(input => { input.value = ''; });
    window.PsgcSelect?.reset(form);
    bootstrap.Collapse.getOrCreateInstance(document.getElementById('new-address'), { toggle: false }).show();
    document.getElementById('new-address-button')?.setAttribute('aria-expanded', 'true');
    window.setTimeout(() => document.getElementById('address-map')?.dispatchEvent(new Event('addressform:shown')), 0);
  });

  document.addEventListener('click', event => {
    const button = event.target.closest('[data-delete-address]');
    if (!button) return;
    deleteForm = button.closest('form');
    document.getElementById('delete-address-recipient').textContent = button.dataset.deleteAddress;
    modal?.show();
  });

  document.getElementById('confirm-delete-address')?.addEventListener('click', () => {
    if (deleteForm) deleteForm.requestSubmit();
  });
  modalElement?.addEventListener('hidden.bs.modal', () => { deleteForm = null; });
})();
