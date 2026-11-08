(() => {
  const branches = JSON.parse(document.getElementById('branch-data')?.textContent || '[]');
  const searchInput = document.getElementById('branch-search');
  const provinceSelect = document.getElementById('branch-province');
  const citySelect = document.getElementById('branch-city');
  const results = document.getElementById('branch-results');
  const resultCount = document.getElementById('branch-result-count');
  const emptyState = document.getElementById('branch-empty');
  const locationButton = document.getElementById('branch-use-location');
  const locationStatus = document.getElementById('branch-location-status');
  const rangeModal = document.getElementById('branch-range-modal');
  const provinceNames = [...new Set(branches.map(branch => branch.province))].sort();
  const RECOMMENDED_RANGE_KM = 10;
  let userLocation = null;
  let map = null;
  let markers = new Map();
  let markerLayer = null;
  let userMarker = null;
  let locationMode = false;
  let rangeWarningShown = false;

  const escapeHtml = value => String(value).replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[char]));

  const distanceKm = (from, branch) => {
    const radians = degrees => degrees * Math.PI / 180;
    const latDistance = radians(branch.lat - from.lat);
    const lngDistance = radians(branch.lng - from.lng);
    const a = Math.sin(latDistance / 2) ** 2
      + Math.cos(radians(from.lat)) * Math.cos(radians(branch.lat)) * Math.sin(lngDistance / 2) ** 2;
    return 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  };

  const directionsUrl = branch => `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(`${branch.lat},${branch.lng}`)}`;
  const takeoutUrl = branch => `order.php?fulfillment=pickup&pickup_location=${encodeURIComponent(branch.address)}`;
  const pickupUrl = branch => `order.php?fulfillment=pickup&pickup_location=${encodeURIComponent(branch.address)}`;

  const fillSelect = (select, firstLabel, options) => {
    const current = select.value;
    select.innerHTML = `<option value="">${firstLabel}</option>` + options
      .map(value => `<option value="${escapeHtml(value)}">${escapeHtml(value)}</option>`).join('');
    if (options.includes(current)) select.value = current;
  };

  provinceNames.forEach(province => provinceSelect.add(new Option(province, province)));

  const updateCityOptions = () => {
    const province = provinceSelect.value;
    const cityNames = [...new Set(branches
      .filter(branch => !province || branch.province === province)
      .map(branch => branch.city))].sort();
    fillSelect(citySelect, 'All cities', cityNames);
  };

  const filteredBranches = () => {
    const query = searchInput.value.trim().toLocaleLowerCase();
    const filtered = branches.filter(branch => {
      const matchesQuery = !query || [branch.name, branch.address, branch.province, branch.city]
        .some(value => value.toLocaleLowerCase().includes(query));
      return matchesQuery
        && (!provinceSelect.value || branch.province === provinceSelect.value)
        && (!citySelect.value || branch.city === citySelect.value);
    });
    if (!locationMode || !userLocation) return filtered;
    const byDistance = filtered.map(branch => ({ ...branch, distance: distanceKm(userLocation, branch) }))
      .sort((a, b) => a.distance - b.distance);
    const nearby = byDistance.filter(branch => branch.distance <= RECOMMENDED_RANGE_KM);
    return nearby.length ? nearby : byDistance.slice(0, 1);
  };

  const renderResults = () => {
    const visible = filteredBranches();
    resultCount.textContent = `${visible.length} ${visible.length === 1 ? 'branch' : 'branches'}`;
    emptyState.hidden = visible.length > 0;
    results.innerHTML = visible.map(branch => `
      <article class="branch-card" data-branch-card="${escapeHtml(branch.id)}" tabindex="0">
        <div class="branch-card-topline">
          <div><p class="branch-card-area">${escapeHtml(branch.city)}, ${escapeHtml(branch.province)}</p><h3>${escapeHtml(branch.name)}</h3></div>
          ${Number.isFinite(branch.distance) ? `<span class="branch-distance">${branch.distance.toFixed(1)} km</span>` : ''}
        </div>
        <p class="branch-card-detail"><i class="bi bi-geo-alt" aria-hidden="true"></i><span>${escapeHtml(branch.address)}</span></p>
        <p class="branch-card-detail"><i class="bi bi-clock" aria-hidden="true"></i><span>${escapeHtml(branch.schedule || 'Hours not listed; contact the branch to confirm.')}</span></p>
        ${branch.phone ? `<p class="branch-card-detail"><i class="bi bi-telephone" aria-hidden="true"></i><a href="tel:${escapeHtml(branch.phone.replace(/[^+\d]/g, ''))}">${escapeHtml(branch.phone)}</a></p>` : ''}
        <div class="branch-card-actions">
          ${branch.takeOut ? `<a class="btn btn-order" href="${takeoutUrl(branch)}" aria-label="Order takeout from ${escapeHtml(branch.name)}">Take Out</a>` : '<span class="btn btn-order disabled" aria-disabled="true">Take Out</span>'}
          ${branch.pickup ? `<a class="btn btn-branch-pickup" href="${pickupUrl(branch)}" aria-label="Order pickup from ${escapeHtml(branch.name)}">Pick-Up</a>` : '<span class="btn btn-branch-pickup disabled" aria-disabled="true">Pick-Up</span>'}
          <a class="branch-directions" href="${directionsUrl(branch)}" target="_blank" rel="noopener noreferrer">Get directions <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
        </div>
      </article>`).join('');

    if (map && markerLayer) {
      markerLayer.clearLayers();
      markers = new Map();
      visible.forEach((branch, index) => {
        const marker = L.marker([branch.lat, branch.lng]).bindPopup(
          `<strong>${escapeHtml(branch.name)}</strong><br>${escapeHtml(branch.address)}<br><a href="${directionsUrl(branch)}" target="_blank" rel="noopener noreferrer">Get directions</a>`
        );
        marker.addTo(markerLayer);
        markers.set(branch.id, marker);
      });
      if (visible.length) {
        const points = visible.map(branch => [branch.lat, branch.lng]);
        if (userLocation) points.push([userLocation.lat, userLocation.lng]);
        const bounds = L.latLngBounds(points);
      map.fitBounds(bounds, { padding: [24, 24], maxZoom: 8 });
      }
    }
  };

  provinceSelect.addEventListener('change', () => {
    updateCityOptions();
    renderResults();
  });
  citySelect.addEventListener('change', renderResults);
  searchInput.addEventListener('input', renderResults);

  results.addEventListener('click', event => {
    if (event.target.closest('a,button')) return;
    const card = event.target.closest('[data-branch-card]');
    const marker = card && markers.get(card.dataset.branchCard);
    if (marker && map) {
      results.querySelectorAll('.branch-card.is-active').forEach(active => active.classList.remove('is-active'));
      card.classList.add('is-active');
      markerLayer.zoomToShowLayer(marker, () => marker.openPopup());
    }
  });
  results.addEventListener('keydown', event => {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    if (event.target.closest('a,button')) return;
    const card = event.target.closest('[data-branch-card]');
    if (card) {
      event.preventDefault();
      card.click();
    }
  });

  locationButton.addEventListener('click', () => {
    if (!navigator.geolocation) {
      locationStatus.textContent = 'Location is not available in this browser.';
      return;
    }
    if (locationMode && userLocation) {
      locationMode = false;
      locationButton.setAttribute('aria-pressed', 'false');
      locationButton.classList.remove('is-active');
      locationStatus.textContent = 'Showing all branches.';
      renderResults();
      return;
    }
    locationMode = true;
    locationButton.setAttribute('aria-pressed', 'true');
    locationButton.classList.add('is-active');
    locationButton.disabled = true;
    locationStatus.textContent = 'Finding branches near you…';
    rangeWarningShown = false;
    navigator.geolocation.getCurrentPosition(position => {
      userLocation = { lat: position.coords.latitude, lng: position.coords.longitude };
      searchInput.value = '';
      provinceSelect.value = '';
      citySelect.value = '';
      updateCityOptions();
      const distances = branches.map(branch => distanceKm(userLocation, branch));
      const hasNearby = distances.some(distance => distance <= RECOMMENDED_RANGE_KM);
      locationStatus.textContent = hasNearby
        ? `Showing branches within ${RECOMMENDED_RANGE_KM} km.`
        : 'Showing the nearest branch.';
      locationButton.disabled = false;
      if (map) {
        if (userMarker) userMarker.remove();
        userMarker = L.circleMarker([userLocation.lat, userLocation.lng], {
          radius: 8, color: '#fff', weight: 2, fillColor: '#8A6144', fillOpacity: 1,
        }).addTo(map).bindPopup('Your location');
      }
      renderResults();
      if (!hasNearby && !rangeWarningShown) {
        rangeWarningShown = true;
        bootstrap.Modal.getOrCreateInstance(rangeModal).show();
      }
    }, error => {
      locationButton.disabled = false;
      locationMode = false;
      locationButton.setAttribute('aria-pressed', 'false');
      locationButton.classList.remove('is-active');
      locationStatus.textContent = error.code === error.PERMISSION_DENIED
        ? 'Location permission was not granted. You can still search by province or city.'
        : 'Could not determine your location. You can still search by province or city.';
    }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 });
  });

  if (window.L && document.getElementById('branch-map')) {
    map = L.map('branch-map', { scrollWheelZoom: true }).setView([14.5948, 121.0584], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap contributors</a>',
    }).addTo(map);
    markerLayer = L.markerClusterGroup({ showCoverageOnHover: false, spiderfyOnMaxZoom: true }).addTo(map);
  } else {
    document.getElementById('branch-map').innerHTML = '<p class="branch-map-fallback">The interactive map could not load. Use each branch’s directions link to open a map.</p>';
  }

  updateCityOptions();
  renderResults();
})();
