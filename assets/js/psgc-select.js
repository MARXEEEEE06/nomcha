(() => {
  // Cascading Region -> City/Municipality -> Barangay selects.
  // Markup comes from psgc_select_field() (includes/psgc_helpers.php).
  const CHILD = { region: 'municipality', municipality: 'barangay' };
  const ENDPOINT_LEVEL = { region: 'localities', municipality: 'barangays' };
  const find = (form, level) => form.querySelector(`[data-psgc-level="${level}"]`);

  const setNote = (select, message) => {
    let note = select.parentElement.querySelector('[data-psgc-note]');
    if (!message) { note?.remove(); return; }
    if (!note) {
      note = document.createElement('div');
      note.className = 'small text-danger mt-1';
      note.setAttribute('data-psgc-note', '');
      select.after(note);
    }
    note.textContent = message;
  };

  const clear = select => {
    select.replaceChildren(new Option(select.dataset.placeholder || '', ''));
    select.value = '';
    select.disabled = true;
    select.dataset.psgcToken = '';
    setNote(select, '');
  };

  const loadChildren = async parent => {
    const form = parent.closest('form');
    const childLevel = CHILD[parent.dataset.psgcLevel];
    if (!form || !childLevel) return;
    for (let level = childLevel; level; level = CHILD[level]) {
      const select = find(form, level);
      if (select) clear(select);
    }
    const child = find(form, childLevel);
    if (!child || !parent.value) return;

    const token = `${Date.now()}-${Math.random()}`;
    child.dataset.psgcToken = token;
    child.replaceChildren(new Option('Loading…', ''));
    try {
      const api = parent.dataset.psgcApi || 'api/psgc.php';
      const url = `${api}?level=${ENDPOINT_LEVEL[parent.dataset.psgcLevel]}&parent=${encodeURIComponent(parent.value)}`;
      const response = await fetch(url, { headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const rows = await response.json();
      if (child.dataset.psgcToken !== token) return; // a newer selection replaced this request
      child.replaceChildren(new Option(child.dataset.placeholder || '', ''), ...rows.map(row => new Option(row.label, row.code)));
      child.disabled = false;
    } catch (error) {
      if (child.dataset.psgcToken !== token) return;
      clear(child);
      setNote(child, 'Could not load this list. Re-select the field above to try again.');
    }
  };

  document.addEventListener('change', event => {
    const select = event.target.closest?.('[data-psgc-level]');
    if (select && CHILD[select.dataset.psgcLevel]) loadChildren(select);
  });

  // Used by billing-address.js when the form is reset for a new address.
  window.PsgcSelect = {
    reset(root = document) {
      root.querySelectorAll('[data-psgc-level="region"]').forEach(region => {
        region.value = '';
        loadChildren(region);
      });
    },
  };
})();
