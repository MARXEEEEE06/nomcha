(() => {
  const banner = document.getElementById('delivery-tracking-banner');
  if (!banner) return;

  const averageSpeedKmh = 25;
  let animationRun = 0;
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
          <img class="delivery-rider" src="assets/images/delivery-motorcycle.svg" alt="">
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
      rider.style.left = '0px';
      progress.style.width = '0%';
      const progressEndX = Math.min(track.clientWidth, endX + rider.offsetWidth * 0.55);
      rider.animate([{ left: '0px' }, { left: `${endX}px` }], {
        duration: travelDurationMs, easing: 'linear', fill: 'forwards',
      });
      progress.animate([{ width: '0px' }, { width: `${progressEndX}px` }], {
        duration: travelDurationMs, easing: 'linear', fill: 'forwards',
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
