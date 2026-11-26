(() => {
  const branches = JSON.parse(document.getElementById('order-branch-data')?.textContent || '[]');
  const addresses = JSON.parse(document.getElementById('order-address-data')?.textContent || '[]');
  const modalElement = document.getElementById('branch-picker-modal');
  const mapElement = document.getElementById('order-branch-map');
  const branchId = document.getElementById('branch-id');
  const branchLabel = document.getElementById('selected-branch-label');
  const addressList = document.querySelector('.order-address-list');
  const addressSummary = document.getElementById('selected-address-summary');
  const deliveryFeeLabel = document.getElementById('delivery-fee-label');
  const distanceLabel = document.getElementById('delivery-distance-label');
  const maxDistanceKm = Number(modalElement.dataset.maxDistanceKm || 25);
  const search = document.getElementById('branch-picker-search');
  const results = document.getElementById('branch-picker-results');
  if (!modalElement || !mapElement) return;

  let map;
  const markers = new Map();
  let selectedBranch = branches.find(branch => String(branch.id) === String(branchId.value)) || null;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
  const selectedAddress = () => {
    const checked = addressList?.querySelector('input[name="address_id"]:checked');
    return addresses.find(address => String(address.address_id) === String(checked?.value)) || null;
  };
  const availableBranches = () => branches;
  const updateFulfillmentOptions = () => {
    document.querySelectorAll('[data-fulfillment-option]').forEach(button => {
      const service = button.dataset.fulfillmentOption === 'pickup' ? 'supports_pickup' : 'supports_delivery';
      const disabled = !!selectedBranch && !selectedBranch[service];
      button.disabled = disabled;
      button.setAttribute('aria-disabled', String(disabled));
    });
    const current = document.getElementById('fulfillment').value;
    const currentService = current === 'pickup' ? 'supports_pickup' : 'supports_delivery';
    if (selectedBranch && !selectedBranch[currentService]) {
      const alternate = current === 'pickup' ? 'delivery' : 'pickup';
      const alternateService = alternate === 'pickup' ? 'supports_pickup' : 'supports_delivery';
      if (selectedBranch[alternateService]) document.querySelector(`[data-fulfillment-option="${alternate}"]`)?.click();
    }
  };
  const kmBetween = (lat1, lng1, lat2, lng2) => {
    const rad = degrees => degrees * Math.PI / 180;
    const dLat = rad(lat2 - lat1), dLng = rad(lng2 - lng1);
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(dLng / 2) ** 2;
    return 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  };
  const updateFee = () => {
    const address = selectedAddress();
    if (!address || address.latitude === null || address.longitude === null || !selectedBranch) {
      deliveryFeeLabel.textContent = 'Choose address and branch'; distanceLabel.textContent = 'A saved address with a map pin and a branch within 25 km are required for delivery or pickup.'; return;
    }
    const km = kmBetween(Number(address.latitude), Number(address.longitude), Number(selectedBranch.lat), Number(selectedBranch.lng));
    if (km > maxDistanceKm) {
      deliveryFeeLabel.textContent = document.getElementById('fulfillment').value === 'pickup' ? '₱0.00' : `₱${(30 + Math.ceil(km) * 10).toFixed(2)}`;
      distanceLabel.textContent = `About ${km.toFixed(1)} km away · outside the ${maxDistanceKm} km ordering range. Choose a closer branch.`;
      return;
    }
    if (document.getElementById('fulfillment').value === 'pickup') {
      deliveryFeeLabel.textContent = '₱0.00'; distanceLabel.textContent = `Pickup at ${selectedBranch.name} · About ${km.toFixed(1)} km away`; return;
    }
    const fee = 30 + Math.ceil(km) * 10;
    deliveryFeeLabel.textContent = `₱${fee.toFixed(2)}`;
    distanceLabel.textContent = `About ${km.toFixed(1)} km straight-line · ${maxDistanceKm} km maximum · ₱30 base + ₱10 per started km`;
  };
  const updateAddress = () => {
    const address = selectedAddress();
    addressList?.querySelectorAll('.order-address-option').forEach(label => label.classList.toggle('selected', label.contains(addressList.querySelector('input[name="address_id"]:checked'))));
    if (!address) { addressSummary.replaceChildren(); updateFee(); return; }
    addressSummary.textContent = `Delivering to ${address.recipient_name} · ${address.recipient_phone || ''}`;
    updateFee();
  };
  const showRangeToast = message => {
    let container = document.querySelector('.order-toast-container');
    if (!container) {
      container = document.createElement('div');
      container.className = 'order-toast-container';
      container.setAttribute('aria-live', 'assertive');
      container.setAttribute('aria-atomic', 'false');
      document.body.append(container);
    }
    const toast = document.createElement('div');
    toast.className = 'order-toast order-toast-warning';
    toast.style.setProperty('--toast-life', '7s');
    toast.setAttribute('role', 'status');
    toast.innerHTML = `<i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i><div>${esc(message)}</div><button type="button" class="order-toast-close" aria-label="Dismiss">&times;</button>`;
    container.append(toast);
    window.setTimeout(() => toast.remove(), 7000);
  };
  const chooseBranch = branch => {
    const address = selectedAddress();
    if (address && address.latitude !== null && address.longitude !== null
      && kmBetween(Number(address.latitude), Number(address.longitude), Number(branch.lat), Number(branch.lng)) > maxDistanceKm) {
      window.bootstrap?.Modal.getOrCreateInstance(modalElement).hide();
      showRangeToast(`That branch is outside the ${maxDistanceKm} km ordering range. Choose a closer branch.`);
      return;
    }
    selectedBranch = branch; branchId.value = branch.id;
    branchLabel.textContent = `${branch.name} · ${branch.address}`;
    results.querySelectorAll('.order-branch-result').forEach(button => button.classList.toggle('active', button.dataset.branchId === String(branch.id)));
    updateFulfillmentOptions();
    updateFee();
    window.bootstrap?.Modal.getOrCreateInstance(modalElement).hide();
  };
  const renderResults = () => {
    const query = search.value.trim().toLocaleLowerCase();
    const visible = availableBranches().filter(branch => [branch.name, branch.address, branch.city, branch.province].some(value => String(value).toLocaleLowerCase().includes(query)));
    const address = selectedAddress();
    results.innerHTML = visible.map(branch => {
      const km = address && address.latitude !== null && address.longitude !== null
        ? kmBetween(Number(address.latitude), Number(address.longitude), Number(branch.lat), Number(branch.lng)) : null;
      const tooFar = km !== null && km > maxDistanceKm;
      return `<button type="button" class="order-branch-result ${selectedBranch && String(selectedBranch.id) === String(branch.id) ? 'active':''}" data-branch-id="${esc(branch.id)}" ${tooFar ? 'disabled' : ''}><strong>${esc(branch.name)}</strong><br><small>${esc(branch.address)} · ${esc(branch.city)}${km === null ? '' : ` · ${km.toFixed(1)} km${tooFar ? ' · outside range' : ''}`}</small></button>`;
    }).join('') || '<p class="text-secondary p-2 mb-0">No branches match your search.</p>';
    if (map) markers.forEach((marker, id) => marker.getElement()?.classList.toggle('order-branch-marker-selected', selectedBranch && String(selectedBranch.id) === String(id)));
  };
  results.addEventListener('click', event => {
    const button = event.target.closest('[data-branch-id]');
    if (!button) return;
    const branch = branches.find(item => String(item.id) === button.dataset.branchId);
    if (branch) chooseBranch(branch);
  });
  search.addEventListener('input', renderResults);
  addressList?.addEventListener('change', () => { updateAddress(); renderResults(); });
  document.addEventListener('order:fulfillment-changed', () => {
    updateFulfillmentOptions(); updateFee(); renderResults();
  });
  document.getElementById('checkout-form')?.addEventListener('submit', event => {
    const fulfillment = document.getElementById('fulfillment').value;
    const supportsFulfillment = selectedBranch && selectedBranch[fulfillment === 'pickup' ? 'supports_pickup' : 'supports_delivery'];
    const address = selectedAddress();
    const distance = address && selectedBranch && address.latitude !== null && address.longitude !== null
      ? kmBetween(Number(address.latitude), Number(address.longitude), Number(selectedBranch.lat), Number(selectedBranch.lng)) : null;
    if (!branchId.value || !address || !supportsFulfillment || distance === null || distance > maxDistanceKm) {
      event.preventDefault();
      const message = !address ? 'Choose a saved billing address.' : distance === null ? 'Add a map pin to your saved address to check the 25 km ordering range.' : distance > maxDistanceKm ? `Choose a branch within ${maxDistanceKm} km of your saved address for delivery or pickup.` : !supportsFulfillment ? 'Choose a branch that offers the selected option.' : 'Choose a Nomcha branch.';
      const toast = document.createElement('div'); toast.className = 'order-toast order-toast-warning'; toast.style.setProperty('--toast-life', '4s'); toast.setAttribute('role', 'status'); toast.textContent = message;
      document.getElementById('order-toast-container')?.append(toast); window.setTimeout(() => toast.remove(), 4000);
    }
  });
  modalElement.addEventListener('shown.bs.modal', () => {
    if (!map && window.L) {
      map = L.map(mapElement, { maxBounds: L.latLngBounds([[4.5, 116.9], [21.2, 126.7]]), maxBoundsViscosity: 1.0, minZoom: 6 }).setView([14.5948, 121.0584], 6);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(map);
      branches.forEach(branch => {
        const marker = L.marker([branch.lat, branch.lng]).addTo(map).bindPopup(`<strong>${esc(branch.name)}</strong><br>${esc(branch.address)}`);
        marker.on('click', () => chooseBranch(branch));
        markers.set(String(branch.id), marker);
      });
      if (branches.length) map.fitBounds(L.latLngBounds(branches.map(branch => [branch.lat, branch.lng])), { padding: [16, 16] });
    }
    window.setTimeout(() => map?.invalidateSize({ pan: false }), 100);
    search.focus();
  });
  search.value = '';
  if (!selectedBranch) {
    const address = selectedAddress();
    if (address && address.latitude !== null && address.longitude !== null) {
      selectedBranch = branches.reduce((nearest, branch) => {
        if (!nearest) return branch;
        return kmBetween(Number(address.latitude), Number(address.longitude), Number(branch.lat), Number(branch.lng))
          < kmBetween(Number(address.latitude), Number(address.longitude), Number(nearest.lat), Number(nearest.lng)) ? branch : nearest;
      }, null);
    }
  }
  updateAddress();
  updateFulfillmentOptions();
  renderResults();
  if (selectedBranch) chooseBranch(selectedBranch);
})();
