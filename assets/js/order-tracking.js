(() => {
  const banner = document.getElementById('delivery-tracking-banner');
  if (!banner) return;

  const averageSpeedKmh = 25;
  let animationRun = 0;
  const pageLoadedAt = Date.now();
  const orderTabs = [...document.querySelectorAll('[data-order-tab]')];
  const orderRows = [...document.querySelectorAll('[data-order-group]')];
  orderTabs.forEach(tab => tab.addEventListener('click', () => {
    const group = tab.dataset.orderTab;
    orderTabs.forEach(item => { const active = item === tab; item.classList.toggle('active', active); item.setAttribute('aria-selected', String(active)); });
    orderRows.forEach(row => { row.hidden = row.dataset.orderGroup !== group; });
  }));
  document.querySelectorAll('[data-received-button][disabled]').forEach(button => {
    const remainingMs = Math.max(0, Number(button.dataset.remainingSeconds) || 0) * 1000;
    window.setTimeout(() => {
      button.disabled = false;
      button.removeAttribute('title');
    }, remainingMs);
  });
  const escapeHtml = value => String(value).replace(/[&<>"']/g, character => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[character]));

  const renderTracking = button => {
    const savedDistanceKm = Number(button.dataset.distanceKm);
    const knownDistance = button.dataset.distanceKnown === 'yes' && Number.isFinite(savedDistanceKm) && savedDistanceKm > 0;
    const safeDistanceKm = knownDistance ? savedDistanceKm : 5;
    const etaMinutes = Math.max(1, Math.ceil((safeDistanceKm / averageSpeedKmh) * 60));
    const travelDurationMs = (safeDistanceKm / averageSpeedKmh) * 60 * 60 * 1000;
    const orderNumber = escapeHtml(button.dataset.orderNumber || 'your order');
    const elapsedAtRender = Math.max(0, Number(button.dataset.deliveryElapsed) || 0) * 1000;
    const elapsed = Math.min(travelDurationMs, elapsedAtRender + (Date.now() - pageLoadedAt));
    const initialRatio = elapsed / travelDurationMs;

    banner.innerHTML = `
      <div class="delivery-tracking-heading">
        <div><p class="order-eyebrow mb-1">Simulated delivery</p><h2 class="h5 mb-0">Rider route · ${orderNumber}</h2></div>
      </div>
      <p class="delivery-tracking-copy">${knownDistance
        ? `About ${safeDistanceKm.toFixed(1)} km · estimated ${etaMinutes} min at ${averageSpeedKmh} km/h average speed.`
        : `Route distance is unavailable for this older order. Showing a 5 km demo route: about ${etaMinutes} min at ${averageSpeedKmh} km/h.`}</p>
      <div class="delivery-route" role="img" aria-label="Motorcycle delivery simulation from branch to home">
        <span class="delivery-route-label">Branch</span>
        <div class="delivery-route-track">
          <span class="delivery-route-progress"></span>
          <img class="delivery-rider" src="assets/images/scooter.svg" alt="Scooter">
        </div>
        <span class="delivery-route-label">Home</span>
      </div>`;
    banner.hidden = false;
    const currentRun = ++animationRun;
    document.querySelectorAll('[data-track-delivery]').forEach(control => {
      const selected = control === button;
      control.setAttribute('aria-expanded', String(selected));
      control.textContent = selected ? 'Hide delivery' : 'Track delivery';
      control.closest('.order-history-row')?.classList.toggle('is-tracking', selected);
    });
    requestAnimationFrame(() => requestAnimationFrame(() => {
      if (currentRun !== animationRun || banner.hidden) return;
      const track = banner.querySelector('.delivery-route-track');
      const rider = banner.querySelector('.delivery-rider');
      const progress = banner.querySelector('.delivery-route-progress');
      if (!track || !rider || !progress) return;

      const endX = Math.max(0, track.clientWidth - rider.offsetWidth);
      const progressEndX = track.clientWidth;
      rider.style.left = `${endX * initialRatio}px`;
      progress.style.width = `${progressEndX * initialRatio}px`;
      rider.animate([{ left: `${endX * initialRatio}px` }, { left: `${endX}px` }], {
        duration: travelDurationMs - elapsed, easing: 'linear', fill: 'forwards',
      });
      progress.animate([{ width: `${progressEndX * initialRatio}px` }, { width: `${progressEndX}px` }], {
        duration: travelDurationMs - elapsed, easing: 'linear', fill: 'forwards',
      });
    }));
    banner.scrollIntoView({ behavior: 'smooth', block: 'center' });
  };

  document.addEventListener('click', event => {
    const button = event.target.closest('[data-track-delivery]');
    if (button) {
      if (button.getAttribute('aria-expanded') === 'true') {
        animationRun++;
        banner.hidden = true;
        document.querySelectorAll('[data-track-delivery]').forEach(control => {
          control.setAttribute('aria-expanded', 'false');
          control.textContent = 'Track delivery';
          control.closest('.order-history-row')?.classList.remove('is-tracking');
        });
        return;
      }
      renderTracking(button);
      return;
    }
  });
})();
