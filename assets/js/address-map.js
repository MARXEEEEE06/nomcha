(() => {
  const element = document.getElementById('address-map');
  if (!element || !window.L) return;
  const latInput = document.getElementById('latitude');
  const lngInput = document.getElementById('longitude');
  const philippinesBounds = L.latLngBounds([[4.5, 116.9], [21.2, 126.7]]);
  let map;
  let marker;
  const initialize = () => {
    if (map || element.clientWidth === 0 || element.clientHeight === 0) return;
    const lat = Number(latInput?.value) || 14.5995;
    const lng = Number(lngInput?.value) || 120.9842;
    map = L.map(element, { maxBounds: philippinesBounds, maxBoundsViscosity: 1.0, minZoom: 6 }).setView([lat, lng], latInput?.value && lngInput?.value ? 16 : 6);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
    const placePin = point => {
      if (!philippinesBounds.contains(point)) return;
      if (marker) marker.setLatLng(point); else marker = L.marker(point, { draggable: true }).addTo(map);
      marker.off('dragend').on('dragend', event => placePin(event.target.getLatLng()));
      latInput.value = Number(point.lat).toFixed(7);
      lngInput.value = Number(point.lng).toFixed(7);
    };
    if (latInput.value && lngInput.value) placePin({ lat, lng });
    map.on('click', event => placePin(event.latlng));
    window.setTimeout(() => map.invalidateSize({ pan: false }), 100);
  };
  initialize();
  document.getElementById('new-address')?.addEventListener('shown.bs.collapse', () => {
    initialize();
    window.setTimeout(() => map?.invalidateSize({ pan: false }), 100);
  });
  element.addEventListener('addressform:shown', () => {
    if (map) {
      map.eachLayer(layer => { if (layer instanceof L.Marker) map.removeLayer(layer); });
      marker = null;
    }
    initialize();
    window.setTimeout(() => map?.invalidateSize({ pan: false }), 100);
  });
  window.addEventListener('resize', () => map?.invalidateSize({ pan: false }));
})();
