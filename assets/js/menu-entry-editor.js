document.addEventListener('DOMContentLoaded', function () {
  const optionData = JSON.parse(document.getElementById('menu-option-data')?.textContent || '[]');
  const groupTypes = JSON.parse(document.getElementById('menu-option-group-types')?.textContent || '[]');
  const initial = JSON.parse(document.getElementById('menu-customization-initial')?.textContent || '[]');
  const host = document.getElementById('customization-groups');
  const hidden = document.getElementById('option-groups-data');
  const form = document.getElementById('menuEntryForm');
  const saveModal = new bootstrap.Modal('#menuSaveConfirm');
  const escape = value => String(value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));

  function addGroup(seed) {
    const group = document.createElement('section');
    group.className = 'customization-group-card p-3 mb-3 border rounded';
    const selectedChoices = new Map((seed?.choices || []).map(choice => [String(typeof choice === 'object' ? choice.option_id : choice), choice]));
    const groupType = groupTypes.includes(seed?.type) ? seed.type : (groupTypes[0] || '');
    const typeOptions = groupTypes.map(type => `<option value="${escape(type)}">${escape(type)}</option>`).join('');
    const optionsForType = type => optionData.filter(option => option.type === type).map(option => {
      const id=String(option.id), saved=selectedChoices.get(id);
      const price=Number(saved?.price_adjustment || 0);
      return `<div class="customization-choice"><label><input class="form-check-input" type="checkbox" data-choice value="${escape(id)}" ${saved ? 'checked' : ''}><span>${escape(option.name)}</span></label><label class="small">Price adjustment ₱ <input class="form-control form-control-sm d-inline-block" style="width:110px" type="number" min="0" step="0.01" value="${price.toFixed(2)}" data-choice-price aria-label="Price adjustment for ${escape(option.name)}"></label></div>`;
    }).join('');
    const options = optionsForType(groupType);
    group.innerHTML = `<div class="d-flex justify-content-between align-items-center mb-3"><strong>Customization group</strong><button type="button" class="btn btn-sm btn-outline-danger" data-remove-group>Remove</button></div>
      <label class="form-label">Label shown to customers</label><input class="form-control mb-3" maxlength="100" data-group-label required value="${escape(seed?.label || '')}" placeholder="e.g. Choose a flavor">
      <label class="form-label">Option group</label><select class="form-select mb-3" data-group-type required>${typeOptions}</select>
      <label class="form-label">Available choices</label><div class="customization-choice-list mb-3" data-group-options>${options || '<span class="text-secondary">No active choices in this group.</span>'}</div>
      <div class="row g-3"><div class="col-sm-6"><div class="form-check"><input class="form-check-input" type="checkbox" data-required ${seed?.required ? 'checked' : ''}><label class="form-check-label">Require a selection</label></div></div><div class="col-sm-6"><div class="form-check"><input class="form-check-input" type="checkbox" data-multiple ${seed?.multiple ? 'checked' : ''}><label class="form-check-label">Allow multiple choices</label></div></div><div class="col-sm-6" data-max-wrap ${seed?.multiple ? '' : 'hidden'}><label class="form-label">Maximum selections</label><input class="form-control" type="number" min="1" max="99" value="${Number(seed?.maxCount || 9)}" data-max-count></div></div>`;
    const typeSelect = group.querySelector('[data-group-type]');
    typeSelect.value = groupType;
    typeSelect.addEventListener('change', () => {
      const choiceHost = group.querySelector('[data-group-options]');
      choiceHost.innerHTML = optionsForType(typeSelect.value) || '<span class="text-secondary">No active choices in this group.</span>';
      sync();
    });
    group.querySelector('[data-remove-group]').addEventListener('click', () => { group.remove(); sync(); });
    group.querySelector('[data-multiple]').addEventListener('change', event => { group.querySelector('[data-max-wrap]').hidden = !event.target.checked; sync(); });
    group.addEventListener('input', sync);
    group.addEventListener('change', sync);
    host.appendChild(group);
    sync();
  }

  function sync() {
    const groups = [...host.querySelectorAll('.customization-group-card')].map((group,index) => {
      const multiple=group.querySelector('[data-multiple]').checked;
      return {
        label:group.querySelector('[data-group-label]').value.trim(),
        type:group.querySelector('[data-group-type]').value,
        choices:[...group.querySelectorAll('[data-choice]:checked')].map(input=>({option_id:Number(input.value),price_adjustment:input.closest('.customization-choice').querySelector('[data-choice-price]').value || '0'})),
        required:group.querySelector('[data-required]').checked?'1':'0',
        multiple:multiple?'1':'0',
        maxCount:multiple?group.querySelector('[data-max-count]').value:'',
        sortOrder:index
      };
    });
    hidden.value=groups.length?JSON.stringify(groups):'';
  }

  document.getElementById('add-customization-group')?.addEventListener('click', () => addGroup(null));
  initial.forEach(group => addGroup(group));
  document.getElementById('open-menu-save-confirm')?.addEventListener('click', () => { if (form.reportValidity()) saveModal.show(); });

  const file=document.getElementById('image_file'), url=document.getElementById('image_url'), preview=document.getElementById('menu-photo-preview'), zone=document.getElementById('image-drop-zone');
  url.addEventListener('input',()=>{ if (!file.files.length && url.value) { try { preview.src=new URL(url.value).href; } catch (_) {} } });
  ['dragenter','dragover'].forEach(name=>zone.addEventListener(name,event=>{event.preventDefault();zone.classList.add('drag-over');}));
  ['dragleave','drop'].forEach(name=>zone.addEventListener(name,event=>{event.preventDefault();zone.classList.remove('drag-over');}));
  zone.addEventListener('drop',event=>{const dropped=event.dataTransfer.files[0];if(dropped){const transfer=new DataTransfer();transfer.items.add(dropped);file.files=transfer.files;file.dispatchEvent(new Event('change',{bubbles:true}));}});
});
