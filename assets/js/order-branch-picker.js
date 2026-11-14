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
  const availableBranches = () => document.getElementById('fulfillment').value === 'pickup'
    ? branches.filter(branch => branch.pickup)
    : branches;
  const kmBetween = (lat1, lng1, lat2, lng2) => {
    const rad = degrees => degrees * Math.PI / 180;
    const dLat = rad(lat2 - lat1), dLng = rad(lng2 - lng1);
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(dLng / 2) ** 2;
    return 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  };
  const updateFee = () => {
    if (document.getElementById('fulfillment').value === 'pickup') {
      deliveryFeeLabel.textContent = '₱0.00'; distanceLabel.textContent = selectedBranch ? `Pickup at ${selectedBranch.name}` : 'Choose a pickup branch.'; return;
    }
    const address = selectedAddress();
    if (!address || address.latitude === null || address.longitude === null || !selectedBranch) {
      deliveryFeeLabel.textContent = 'Choose address and branch'; distanceLabel.textContent = 'A map pin and branch are needed to estimate delivery.'; return;
    }
    const km = kmBetween(Number(address.latitude), Number(address.longitude), Number(selectedBranch.lat), Number(selectedBranch.lng));
    const fee = 30 + Math.ceil(km) * 10;
    deliveryFeeLabel.textContent = `₱${fee.toFixed(2)}`;
    distanceLabel.textContent = `About ${km.toFixed(1)} km straight-line · ₱30 base + ₱10 per started km`;
  };
  const updateAddress = () => {
    const address = selectedAddress();
    addressList?.querySelectorAll('.order-address-option').forEach(label => label.classList.toggle('selected', label.contains(addressList.querySelector('input[name="address_id"]:checked'))));
    if (!address) { addressSummary.replaceChildren(); updateFee(); return; }
    addressSummary.textContent = `Delivering to ${address.recipient_name} · ${address.mobile_no || ''}`;
    updateFee();
  };
  const chooseBranch = branch => {
    selectedBranch = branch; branchId.value = branch.id;
    branchLabel.textContent = `${branch.name} · ${branch.address}`;
    results.querySelectorAll('.order-branch-result').forEach(button => button.classList.toggle('active', button.dataset.branchId === String(branch.id)));
    updateFee();
  };
  const renderResults = () => {
    const query = search.value.trim().toLocaleLowerCase();
    const visible = availableBranches().filter(branch => [branch.name, branch.address, branch.city, branch.province].some(value => String(value).toLocaleLowerCase().includes(query)));
    results.innerHTML = visible.map(branch => `<button type="button" class="order-branch-result ${selectedBranch && String(selectedBranch.id) === String(branch.id) ? 'active':''}" data-branch-id="${esc(branch.id)}"><strong>${esc(branch.name)}</strong><br><small>${esc(branch.address)} · ${esc(branch.city)}</small></button>`).join('') || '<p class="text-secondary p-2 mb-0">No branches match your search.</p>';
    if (map) markers.forEach((marker, id) => marker.getElement()?.classList.toggle('order-branch-marker-selected', selectedBranch && String(selectedBranch.id) === String(id)));
  };
  results.addEventListener('click', event => {
    const button = event.target.closest('[data-branch-id]');
    if (!button) return;
    const branch = branches.find(item => String(item.id) === button.dataset.branchId);
    if (branch) chooseBranch(branch);
  });
  search.addEventListener('input', renderResults);
  addressList?.addEventListener('change', updateAddress);
  document.addEventListener('order:fulfillment-changed', () => {
    if (document.getElementById('fulfillment').value === 'pickup' && selectedBranch && !selectedBranch.pickup) {
      selectedBranch = null; branchId.value = ''; branchLabel.textContent = 'Choose a pickup branch';
    }
    updateFee(); renderResults();
  });
  document.getElementById('checkout-form')?.addEventListener('submit', event => {
    if (!branchId.value || !selectedAddress()) {
      event.preventDefault();
      const message = !selectedAddress() ? 'Choose a saved billing address.' : 'Choose a Nomcha branch.';
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
        marker.on('click', () => {
          if (document.getElementById('fulfillment').value === 'pickup' && !branch.pickup) return;
          chooseBranch(branch);
        });
        markers.set(String(branch.id), marker);
      });
      if (branches.length) map.fitBounds(L.latLngBounds(branches.map(branch => [branch.lat, branch.lng])), { padding: [16, 16] });
    }
    window.setTimeout(() => map?.invalidateSize({ pan: false }), 100);
    search.focus();
  });
  search.value = '';
  if (!selectedBranch && document.getElementById('fulfillment').value === 'delivery') {
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
  renderResults();
  if (selectedBranch) chooseBranch(selectedBranch);
})();
