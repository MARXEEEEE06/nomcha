(() => {
  const menuData = JSON.parse(document.getElementById('order-menu-data')?.textContent || '[]');
  const menuItems = new Map(menuData.map(item => [String(item.id), item]));
  const cartKey = 'nomcha-order-cart-v1';
  const cartItems = document.getElementById('cart-items');
  const cartJson = document.getElementById('cart-json');
  const placeOrder = document.getElementById('place-order');
  const optionModalElement = document.getElementById('item-options-modal');
  const optionModal = optionModalElement ? bootstrap.Modal.getOrCreateInstance(optionModalElement) : null;
  const scheduleModalElement = document.getElementById('schedule-time-modal');
  const scheduleModal = scheduleModalElement ? bootstrap.Modal.getOrCreateInstance(scheduleModalElement) : null;
  const removeModalElement = document.getElementById('remove-item-modal');
  const removeModal = removeModalElement ? bootstrap.Modal.getOrCreateInstance(removeModalElement) : null;
  let pendingRemoveIndex = null;
  const formatter = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
  let cart = [];
  let selectedItem = null;

  try {
    const savedCart = JSON.parse(localStorage.getItem(cartKey) || '[]');
    if (Array.isArray(savedCart)) {
      cart = savedCart
        .filter(entry => menuItems.has(String(entry.id)) && Number.isInteger(Number(entry.quantity)) && Number(entry.quantity) > 0)
        .map(entry => ({
          id: String(entry.id),
          quantity: Math.min(99, Number(entry.quantity)),
          options: entry.options && typeof entry.options === 'object' ? entry.options : {},
        }))
        .filter(entry => {
          const item = menuItems.get(entry.id);
          if (item.priceCents === null) return false;
          if (item.customOptions?.length) return item.customOptions.filter(group => group.minCount > 0).every(group => {
            const value=(entry.options.choiceSelections || entry.options)[group.key];
            const count=group.multiple&&value&&typeof value==='object'?Object.values(value).reduce((sum,amount)=>sum+(Number(amount)||0),0):(value?1:0);
            return count>=group.minCount;
          });
          return item.options.every(group => Boolean(entry.options[group.key]));
        });
    }
  } catch (error) {
    cart = [];
  }

  if (document.querySelector('[data-order-confirmed="yes"]')) {
    cart = [];
    localStorage.removeItem(cartKey);
  }

  const escapeHtml = value => String(value).replace(/[&<>"']/g, character => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[character]));

  const toastContainer = document.querySelector('.order-toast-container');
  const voucherClaimSelect = document.getElementById('voucher-claim-id');
  const promoCodeInput = document.getElementById('promo-code');
  voucherClaimSelect?.addEventListener('change', () => { if (voucherClaimSelect.value && promoCodeInput) promoCodeInput.value = ''; });
  promoCodeInput?.addEventListener('input', () => { if (promoCodeInput.value.trim() && voucherClaimSelect) voucherClaimSelect.value = ''; });
  const showToast = (message, tone = 'danger') => {
    if (!toastContainer) return;
    const toast = document.createElement('div');
    toast.className = `order-toast order-toast-${tone}`;
    toast.style.setProperty('--toast-life', '7s');
    toast.setAttribute('role', tone === 'danger' ? 'alert' : 'status');
    toast.innerHTML = `<i class="bi ${tone === 'success' ? 'bi-check-circle-fill' : tone === 'warning' ? 'bi-exclamation-triangle-fill' : 'bi-exclamation-circle-fill'}" aria-hidden="true"></i><div>${escapeHtml(message)}</div><button type="button" class="order-toast-close" aria-label="Dismiss">&times;</button>`;
    toastContainer.append(toast);
    window.setTimeout(() => toast.remove(), 7000);
  };

  toastContainer?.addEventListener('click', event => {
    if (event.target.closest('.order-toast-close')) event.target.closest('.order-toast')?.remove();
  });

  const formatMoney = cents => formatter.format(cents / 100);

  const optionGroupCounts = group => Object.fromEntries(
    [...document.querySelectorAll(`[data-option-group-choice="${group}"]`)]
       .map(row => [row.dataset.optionId, Number(row.querySelector('[data-option-count]').textContent) || 0])
      .filter(([, count]) => count > 0)
  );

  const optionAddOnCents = (item, options) => {
    if (item?.customOptions?.length) return item.customOptions.reduce((total, group) => {
      const selected = (options?.choiceSelections || options)?.[group.key];
      if (group.multiple && selected && typeof selected === 'object') return total + Object.entries(selected).reduce((sum, [id, count]) => sum + (group.choices.find(choice => String(choice.id ?? choice.name) === String(id))?.priceCents || 0) * Number(count), 0);
      const choice = group.choices.find(option => String(option.id ?? option.name) === String(selected));
      return total + (choice?.priceCents || 0);
    }, 0);
    return 0;
  };

  const formatOptionSummary = (item, options) => {
    const selections=options?.choiceSelections || options || {};
    const labels=[];
    for (const group of item.customOptions || item.options || []) {
      const selected=selections[group.key];
      const values=group.multiple&&selected&&typeof selected==='object'?Object.entries(selected):selected?[[selected,1]]:[];
      for (const [id,count] of values) {
        const choice=group.choices.find(option=>String(option.id ?? option.name)===String(id));
        if (choice) labels.push(`${count>1?`${count} × `:''}${choice.name}`);
      }
    }
    if (options?.specialRequest) labels.push(options.specialRequest);
    return labels.join(' · ');
  };

  const optionUnitPrice = (item, options) => (item?.priceCents || 0) + optionAddOnCents(item, options);

  const updateOptionTotal = () => {
    if (!selectedItem) return;
    const quantity = Math.min(99, Math.max(1, Number(document.getElementById('option-quantity').value) || 1));
    const customSelections = Object.fromEntries(selectedItem.customOptions?.map(group => [group.key, group.multiple ? optionGroupCounts(group.key) : document.querySelector(`input[name="option-${group.key}"]:checked`)?.value || '']) || []);
    const addOn = optionAddOnCents(selectedItem, { choiceSelections: customSelections });
    document.getElementById('item-options-total').textContent = formatMoney((selectedItem.priceCents + addOn) * quantity);
  };

  const formatClockTime = value => {
    const [hour, minute] = value.split(':').map(Number);
    return new Date(2000, 0, 1, hour, minute).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
  };

  const syncScheduleSummary = () => {
    const fulfillmentInput = document.getElementById('fulfillment');
    if (!fulfillmentInput) return;
    const fulfillment = fulfillmentInput.value;
    const label = document.getElementById('order-schedule-label');
    const confirmButton = document.getElementById('confirm-schedule-time');
    const value = document.getElementById('scheduled-for').value;
    const slot = value ? value.slice(11, 16) : '';
    const mode = fulfillment === 'delivery' ? 'Delivery' : 'Pickup';
    label.textContent = slot ? `${mode} · ${formatClockTime(slot)}` : `${mode} ASAP`;
    confirmButton.textContent = `Confirm ${mode} Time`;
    document.querySelectorAll('[data-schedule-fulfillment]').forEach(button => {
      const active = button.dataset.scheduleFulfillment === fulfillment;
      button.classList.toggle('active', active);
      button.setAttribute('aria-pressed', String(active));
    });
  };

  const renderScheduleOptions = () => {
    const main = document.querySelector('.order-page');
    const nowValue = main.dataset.phNow;
    const [today, currentTime] = nowValue.split('T');
    const dateForDisplay = new Date(`${today}T12:00:00`);
    document.getElementById('schedule-day-label').textContent = dateForDisplay.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
    const options = document.getElementById('schedule-time-options');
    const currentScheduled = document.getElementById('scheduled-for').value;
    const requestedTime = currentScheduled.startsWith(today) ? currentScheduled.slice(11, 16) : '';
    const selectedTime = requestedTime > currentTime ? requestedTime : '';
    const rows = [{ value: 'asap', label: `${document.getElementById('fulfillment').value === 'delivery' ? 'Delivery' : 'Pickup'} ASAP`, icon: 'bi-lightning-charge-fill' }];

    for (let minutes = 9 * 60 + 30; minutes <= 23 * 60; minutes += 30) {
      const start = `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
      const endMinutes = minutes + 30;
      const end = `${String(Math.floor(endMinutes / 60)).padStart(2, '0')}:${String(endMinutes % 60).padStart(2, '0')}`;
      rows.push({ value: start, label: `${formatClockTime(start)} - ${formatClockTime(end)}`, unavailable: start <= currentTime });
    }

    options.innerHTML = rows.map((row, index) => {
      const checked = row.value === 'asap' ? !selectedTime : row.value === selectedTime;
      return `<label class="order-schedule-option${checked ? ' selected' : ''}${row.unavailable ? ' disabled' : ''}">
        <input type="radio" name="schedule-choice" value="${row.value}" data-time-value="${row.value === 'asap' ? '' : row.value}" ${checked ? 'checked' : ''} ${row.unavailable ? 'disabled' : ''}>
        <span>${row.icon ? `<i class="bi ${row.icon}" aria-hidden="true"></i>` : ''}${row.label}</span>
        <i class="bi bi-check-circle-fill order-schedule-check" aria-hidden="true"></i>
      </label>`;
    }).join('');
    syncScheduleSummary();
  };

  const orderPage = document.querySelector('.order-page');
  let deliveryUnavailable = false;
  if (orderPage) {
    const [, clock = '00:00'] = orderPage.dataset.phNow.split('T');
    if (clock > '23:30') {
      deliveryUnavailable = true;
      const deliveryButton = document.querySelector('[data-fulfillment-option="delivery"]');
      deliveryButton?.setAttribute('aria-disabled', 'true');
      deliveryButton?.classList.add('disabled');
      const note = document.createElement('p');
      note.className = 'alert alert-warning mt-2 mb-0';
      note.textContent = 'Online delivery is unavailable right now. Choose pickup or come back tomorrow.';
      document.querySelector('[data-delivery-fields]')?.prepend(note);
      deliveryButton?.addEventListener('click', event => { event.stopImmediatePropagation(); showToast(note.textContent, 'warning'); }, true);
    }
  }

  const persistCart = () => {
    try {
      localStorage.setItem(cartKey, JSON.stringify(cart));
    } catch (error) {
      // The cart remains usable for this page view if storage is unavailable.
    }
  };

  const renderCart = () => {
    if (!cartItems) return;
    const count = cart.reduce((total, entry) => total + entry.quantity, 0);
    const subtotal = cart.reduce((total, entry) => {
      const item = menuItems.get(entry.id);
      return total + optionUnitPrice(item, entry.options) * entry.quantity;
    }, 0);

    document.getElementById('cart-count').textContent = count;
    document.getElementById('cart-subtotal').textContent = formatMoney(subtotal);
    document.getElementById('mobile-cart-count').textContent = count;
    document.getElementById('mobile-cart-total').textContent = formatMoney(subtotal);
    placeOrder.disabled = count === 0;

    if (!cart.length) {
      cartItems.innerHTML = '<div class="order-cart-empty"><i class="bi bi-bag" aria-hidden="true"></i><p>Your basket is empty.</p><span>Add a priced item to get started.</span></div>';
    } else {
      cartItems.innerHTML = cart.map((entry, index) => {
        const item = menuItems.get(entry.id);
        const optionSummary = formatOptionSummary(item,entry.options);
        const lineTotal = optionUnitPrice(item, entry.options) * entry.quantity;
        return `<div class="order-cart-line">
          <div class="order-cart-line-main">
            <div><strong>${escapeHtml(item.name)}</strong>${optionSummary ? `<small>${escapeHtml(optionSummary)}</small>` : ''}</div>
            <strong>${formatMoney(lineTotal)}</strong>
          </div>
          <div class="order-cart-line-actions">
            <div class="order-quantity" aria-label="Quantity for ${escapeHtml(item.name)}">
              <button type="button" data-cart-action="decrease" data-cart-index="${index}" aria-label="Decrease quantity">−</button>
              <span>${entry.quantity}</span>
              <button type="button" data-cart-action="increase" data-cart-index="${index}" aria-label="Increase quantity">+</button>
            </div>
            <button type="button" class="order-remove" data-cart-action="remove" data-cart-index="${index}">Remove</button>
          </div>
        </div>`;
      }).join('');
    }

    cartJson.value = JSON.stringify(cart.map(entry => ({
      id: Number(entry.id), quantity: entry.quantity, options: entry.options,
    })));
    persistCart();
  };

  const addItem = (item, quantity, options) => {
    const key = JSON.stringify(options);
    const match = cart.find(entry => entry.id === String(item.id) && JSON.stringify(entry.options) === key);
    if (match) match.quantity = Math.min(99, match.quantity + quantity);
    else cart.push({ id: String(item.id), quantity, options });
    renderCart();
  };

  const openOptions = item => {
    selectedItem = item;
    document.getElementById('item-options-title').textContent = item.name;
    const image = document.getElementById('item-options-image');
    image.src = item.imageUrl || '';
    image.alt = item.name;
    image.hidden = !item.imageUrl;
    document.getElementById('item-options-description').textContent = item.description || '';
    document.getElementById('item-options-base-price').textContent = formatMoney(item.priceCents);
    const fields = document.getElementById('item-options-fields');
    fields.replaceChildren();
    const validation = document.getElementById('item-options-error');
    validation.textContent = '';
    const specialRequest = document.querySelector('.order-special-request');
    specialRequest.hidden = false;
    document.getElementById('option-special-request').value = '';

    (item.customOptions || item.options || []).forEach(group => {
        const wrapper = document.createElement('section');
        wrapper.className = 'order-option-group';
        const choices = group.choices || [];
        if (group.multiple) {
          const rows = choices.map(choice => `<div class="order-option-choice" data-option-group-choice="${escapeHtml(group.key)}" data-option-id="${escapeHtml(choice.id)}"><div class="order-option-choice-name">${escapeHtml(choice.name)}${choice.priceCents ? `<small>+${formatMoney(choice.priceCents)}</small>` : ''}</div><div class="order-option-stepper"><button type="button" data-option-step="down" data-option-group="${escapeHtml(group.key)}" aria-label="Remove ${escapeHtml(choice.name)}">−</button><span data-option-count aria-live="polite">0</span><button type="button" data-option-step="up" data-option-group="${escapeHtml(group.key)}" aria-label="Add ${escapeHtml(choice.name)}">+</button></div></div>`).join('');
          wrapper.innerHTML = `<div class="order-option-group-heading"><div><h3>${escapeHtml(group.label)}</h3><p>${group.minCount ? `Select at least ${group.minCount} · ` : ''}Select up to ${group.maxCount} option(s)</p></div>${group.required ? '<span class="order-required">Required</span>' : ''}</div>${rows || '<p class="small text-danger">No choices are available. Please contact the restaurant.</p>'}`;
          fields.append(wrapper);
          return;
        }
        const rows = choices.map((choice, index) => {
          const id = `option-${group.key}-${index}`;
          const optionId = choice.id ?? choice.name;
          return `<label class="order-option-choice order-option-radio-choice" for="${id}">
            <span class="order-option-choice-name">${escapeHtml(choice.name)}${choice.priceCents ? `<small>+${formatMoney(choice.priceCents)}</small>` : ''}</span>
            <input id="${id}" type="radio" name="option-${group.key}" value="${escapeHtml(optionId)}" data-option-key="${escapeHtml(group.key)}" ${group.required && index === 0 ? 'required' : ''}>
          </label>`;
        }).join('');
        wrapper.innerHTML = `<div class="order-option-group-heading"><div><h3>${escapeHtml(group.label)}</h3><p>${group.required ? 'Select one option' : 'Select one or skip'}</p></div>${group.required ? '<span class="order-required">Required</span>' : ''}</div>${rows || '<p class="small text-danger">No choices are available. Please contact the restaurant.</p>'}`;
        fields.append(wrapper);
      });

    document.getElementById('option-quantity').value = '1';
    document.getElementById('option-quantity').max = '99';
    document.getElementById('item-options-total').textContent = formatMoney(item.priceCents);
    optionModal.show();
  };

  optionModalElement?.addEventListener('click', event => {
    const stepButton = event.target.closest('[data-option-step]');
    if (stepButton) {
      const row = stepButton.closest('[data-option-group-choice]');
      const count = row.querySelector('[data-option-count]');
      const current = Number(count.textContent) || 0;
      const direction = stepButton.dataset.optionStep === 'up' ? 1 : -1;
      const group = row.dataset.optionGroupChoice;
      let next = Math.max(0, current + direction);
      const customGroup = selectedItem.customOptions?.find(option => option.key === group && option.multiple);
      if (customGroup && direction > 0) {
        const total = Object.values(optionGroupCounts(group)).reduce((sum, value) => sum + value, 0);
        if (total >= customGroup.maxCount) return;
        next = Math.min(customGroup.maxCount, next);
      }
      count.textContent = next;
      document.getElementById('item-options-error').textContent = '';
      updateOptionTotal();
      return;
    }

    const quantityButton = event.target.closest('[data-order-quantity]');
    if (quantityButton) {
      const input = document.getElementById('option-quantity');
      const amount = Number(input.value) || 1;
      input.value = Math.max(1, Math.min(99, amount + (quantityButton.dataset.orderQuantity === 'increase' ? 1 : -1)));
      updateOptionTotal();
    }
  });

  document.getElementById('option-quantity')?.addEventListener('input', updateOptionTotal);
  document.getElementById('item-options-fields')?.addEventListener('change', event => {
    if (event.target.matches('input[type="radio"][data-option-key]')) updateOptionTotal();
  });

  document.addEventListener('click', event => {
    const addButton = event.target.closest('[data-add-item]');
    if (addButton) {
      const item = menuItems.get(addButton.dataset.addItem);
      if (!item || item.priceCents === null) return;
      openOptions(item);
      return;
    }

    const cartButton = event.target.closest('[data-cart-action]');
    if (cartButton) {
      const index = Number(cartButton.dataset.cartIndex);
      const entry = cart[index];
      if (!entry) return;
      if (cartButton.dataset.cartAction === 'increase') entry.quantity = Math.min(99, entry.quantity + 1);
      if (cartButton.dataset.cartAction === 'decrease') entry.quantity -= 1;
      if (cartButton.dataset.cartAction === 'remove') {
        const title = menuItems.get(entry.id)?.name || 'this item';
        pendingRemoveIndex = index;
        document.getElementById('remove-item-name').textContent = title;
        removeModal?.show();
        return;
      } else if (entry.quantity <= 0) cart.splice(index, 1);
      renderCart();
    }
  });

  document.getElementById('confirm-remove-item')?.addEventListener('click', () => {
    if (pendingRemoveIndex !== null && cart[pendingRemoveIndex]) {
      cart.splice(pendingRemoveIndex, 1);
      renderCart();
    }
    pendingRemoveIndex = null;
    removeModal?.hide();
  });

  document.getElementById('item-options-form')?.addEventListener('submit', event => {
    event.preventDefault();
    if (!selectedItem || !event.currentTarget.reportValidity()) return;
    const groups = selectedItem.customOptions || selectedItem.options || [];
    const selections = {};
    for (const group of groups) {
      const value=group.multiple?optionGroupCounts(group.key):document.querySelector(`input[name="option-${group.key}"]:checked`)?.value || '';
      const selectedCount=group.multiple?Object.values(value).reduce((sum,count)=>sum+(Number(count)||0),0):(value?1:0);
      if (selectedCount < group.minCount) {
        const message=`Choose at least ${group.minCount} option(s) for ${group.label}.`;
        document.getElementById('item-options-error').textContent=message;
        showToast(message,'warning');
        return;
      }
      selections[group.key]=value;
    }
    const options = { choiceSelections: selections };
    const specialRequest = document.getElementById('option-special-request').value.trim();
    if (specialRequest) options.specialRequest = specialRequest;
    const quantity = Math.min(99, Math.max(1, Number(document.getElementById('option-quantity').value) || 1));
    addItem(selectedItem, quantity, options);
    optionModal.hide();
    selectedItem = null;
  });

  document.querySelectorAll('[data-fulfillment-option]').forEach(button => {
    button.addEventListener('click', () => {
      const fulfillment = button.dataset.fulfillmentOption;
      document.getElementById('fulfillment').value = fulfillment;
      document.querySelectorAll('[data-fulfillment-option]').forEach(option => {
        const selected = option === button;
        option.classList.toggle('active', selected);
        option.setAttribute('aria-pressed', String(selected));
      });
      const deliveryFields = document.querySelector('[data-delivery-fields]');
      const pickupFields = document.querySelector('[data-pickup-fields]');
      const delivery = fulfillment === 'delivery';
      deliveryFields.hidden = !delivery;
      pickupFields.hidden = delivery;
      document.querySelectorAll('input[name="address_id"]').forEach(input => { input.required = true; });
      document.querySelector('[data-card-payment]').hidden = delivery;
      const cardPayment = document.querySelector('input[value="card_pickup"]');
      cardPayment.disabled = delivery;
      if (delivery && cardPayment.checked) document.querySelector('input[value="cash"]').checked = true;
      if (!delivery) document.getElementById('delivery-fee-label').textContent = '₱0.00';
      document.dispatchEvent(new CustomEvent('order:fulfillment-changed', { detail: { fulfillment } }));
      syncScheduleSummary();
    });
  });
  if (deliveryUnavailable) {
    document.querySelector('[data-fulfillment-option="pickup"]')?.click();
    showToast('Online delivery is unavailable right now. Pickup is available, or come back tomorrow.', 'warning');
  }

  document.getElementById('schedule-picker-open')?.addEventListener('click', () => {
    renderScheduleOptions();
    scheduleModal.show();
  });

  document.querySelectorAll('[data-schedule-fulfillment]').forEach(button => {
    button.addEventListener('click', () => {
      document.querySelector(`[data-fulfillment-option="${button.dataset.scheduleFulfillment}"]`)?.click();
      renderScheduleOptions();
    });
  });

  document.getElementById('schedule-time-options')?.addEventListener('change', event => {
    const radio = event.target.closest('input[name="schedule-choice"]');
    if (!radio) return;
    document.querySelectorAll('.order-schedule-option').forEach(option => option.classList.toggle('selected', option.contains(radio)));
  });

  document.getElementById('confirm-schedule-time')?.addEventListener('click', () => {
    const selected = document.querySelector('input[name="schedule-choice"]:checked');
    if (!selected) {
      showToast('Choose ASAP or an available time slot.', 'warning');
      return;
    }
    const today = document.querySelector('.order-page').dataset.phNow.slice(0, 10);
    document.getElementById('scheduled-for').value = selected.dataset.timeValue ? `${today}T${selected.dataset.timeValue}` : '';
    syncScheduleSummary();
    scheduleModal.hide();
  });

  document.querySelectorAll('[data-order-toast-close]').forEach(button => {
    button.addEventListener('click', () => button.closest('.order-toast')?.remove());
  });

  document.getElementById('order-search')?.addEventListener('input', event => {
    const query = event.target.value.trim().toLowerCase();
    let visibleItems = 0;
    document.querySelectorAll('[data-order-card]').forEach(card => {
      const visible = card.dataset.searchText.includes(query);
      card.hidden = !visible;
      if (visible) visibleItems += 1;
    });
    document.querySelectorAll('.order-menu-section').forEach(section => {
      section.hidden = Boolean(query) && !section.querySelector('[data-order-card]:not([hidden])');
    });
    document.getElementById('order-no-results').classList.toggle('d-none', visibleItems > 0);
  });

  document.getElementById('checkout-form')?.addEventListener('submit', event => {
    renderCart();
    if (!cart.length) {
      event.preventDefault();
      showToast('Add a priced menu item before placing your order.', 'warning');
    }
  });

  document.getElementById('mobile-cart-button')?.addEventListener('click', () => {
    document.getElementById('checkout-panel').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  renderCart();
  const initialFulfillment = document.getElementById('fulfillment');
  if (initialFulfillment) {
    document.querySelector(`[data-fulfillment-option="${initialFulfillment.value}"]`)?.click();
    syncScheduleSummary();
  }
})();
