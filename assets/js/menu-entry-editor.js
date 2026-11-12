document.addEventListener('DOMContentLoaded', function () {
  const optionData = JSON.parse(document.getElementById('menu-option-data')?.textContent || '[]');
  const initial = JSON.parse(document.getElementById('menu-customization-initial')?.textContent || '[]');
  const host = document.getElementById('customization-groups');
  const hidden = document.getElementById('customizations-json');
  const itemKind = document.getElementById('item_kind');
  const scope = document.getElementById('availability_scope');
  const section = document.getElementById('customization-section');
  const noOptions = document.getElementById('no-customization-options');
  const form = document.getElementById('menuEntryForm');
  const saveModal = new bootstrap.Modal('#menuSaveConfirm');
  const escape = value => String(value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));

  function updateKind() {
    [...scope.options].forEach(option => {
      option.hidden = itemKind.value === 'item' ? option.value === 'option_only' : option.value === 'item_only';
      option.disabled = option.hidden;
    });
    if (scope.selectedOptions[0]?.disabled) scope.value = itemKind.value === 'item' ? 'item_only' : 'option_only';
    if (itemKind.value === 'option') host.replaceChildren();
    section.hidden = itemKind.value === 'option';
    document.getElementById('price').required = itemKind.value === 'item';
    noOptions.hidden = optionData.length > 0 || itemKind.value === 'option';
    sync();
  }

  function addGroup(seed) {
    const group = document.createElement('section');
    group.className = 'customization-group-card p-3 mb-3';
    const selectedNames = (seed?.choices || []).map(choice => typeof choice === 'string' ? choice : choice.name);
    const options = optionData.map(option => `<label class="customization-choice"><input class="form-check-input" type="checkbox" data-choice value="${escape(option.name)}" ${selectedNames.includes(option.name) ? 'checked' : ''}><span>${escape(option.name)}${option.priceCents ? ' · +₱' + (option.priceCents/100).toFixed(2) : ''}</span></label>`).join('');
    group.innerHTML = `<div class="d-flex justify-content-between align-items-center mb-3"><strong>Customization group</strong><button type="button" class="btn btn-sm btn-outline-danger" data-remove-group>Remove</button></div>
      <label class="form-label">Group name</label><input class="form-control mb-3" maxlength="100" data-group-label required value="${escape(seed?.label || '')}" placeholder="e.g. Choose a flavor">
      <label class="form-label">Available options</label><div class="customization-choice-list mb-3" data-group-options>${options || '<span class="text-secondary">No available choices</span>'}</div><div class="form-text mb-3">Toggle each active item customers may choose. Drinks, upgrades, and add-ons marked “item and option” appear here.</div>
      <div class="row g-3"><div class="col-sm-6"><div class="form-check"><input class="form-check-input" type="checkbox" data-required ${seed?.required ? 'checked' : ''}><label class="form-check-label">Require a selection</label></div></div><div class="col-sm-6"><div class="form-check"><input class="form-check-input" type="checkbox" data-multiple ${seed?.multiple ? 'checked' : ''}><label class="form-check-label">Allow multiple options</label></div></div><div class="col-sm-6" data-max-wrap ${seed?.multiple ? '' : 'hidden'}><label class="form-label">Maximum selections</label><input class="form-control" type="number" min="1" max="99" value="${Number(seed?.maxCount || 9)}" data-max-count></div></div>`;
    group.querySelector('[data-remove-group]').addEventListener('click', () => { group.remove(); sync(); });
    group.querySelector('[data-multiple]').addEventListener('change', event => { group.querySelector('[data-max-wrap]').hidden = !event.target.checked; sync(); });
    group.addEventListener('input', sync); group.addEventListener('change', sync);
    host.appendChild(group); sync();
  }

  function sync() {
    const groups = [...host.querySelectorAll('.customization-group-card')].map((group,index) => {
      const label=group.querySelector('[data-group-label]').value.trim();
      const choices=[...group.querySelectorAll('[data-choice]:checked')].map(option=>option.value);
      const multiple=group.querySelector('[data-multiple]').checked;
      return {label,choices,required:group.querySelector('[data-required]').checked?'1':'0',multiple:multiple?'1':'0',maxCount:multiple?group.querySelector('[data-max-count]').value:'',key:'group-'+(index+1)};
    });
    hidden.value=groups.length?JSON.stringify(groups):'';
  }

  document.getElementById('add-customization-group').addEventListener('click', () => addGroup(null));
  initial.forEach(group => addGroup(group));
  itemKind.addEventListener('change', updateKind); updateKind();
  document.getElementById('open-menu-save-confirm').addEventListener('click', () => { if (form.reportValidity()) saveModal.show(); });

  const file=document.getElementById('image_file'), url=document.getElementById('image_url'), preview=document.getElementById('menu-photo-preview'), zone=document.getElementById('image-drop-zone');
  url.addEventListener('input',()=>{ if (!file.files.length && url.value) { try { preview.src=new URL(url.value).href; } catch (_) {} } });
  ['dragenter','dragover'].forEach(name=>zone.addEventListener(name,event=>{event.preventDefault();zone.classList.add('drag-over');}));
  ['dragleave','drop'].forEach(name=>zone.addEventListener(name,event=>{event.preventDefault();zone.classList.remove('drag-over');}));
  zone.addEventListener('drop',event=>{const dropped=event.dataTransfer.files[0];if(dropped){const transfer=new DataTransfer();transfer.items.add(dropped);file.files=transfer.files;file.dispatchEvent(new Event('change',{bubbles:true}));}});
});
