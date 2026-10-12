document.querySelectorAll('section.b-tabs').forEach(function (root) {
  const nav = root.querySelector('.js-tabs-nav');
  if (!nav) return;

  const tabs = Array.from(nav.querySelectorAll('.tab_btn'));
  if (!tabs.length) return;

  let active = tabs.findIndex(t => t.classList.contains('active'));
  if (active < 0) active = 0;
  tabs.forEach((btn, i) => btn.classList.toggle('active', i === active));

  const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ---------- WSPÓLNA MAPA ----------
  const mapEl = root.querySelector('.js-osm-shared');
  let sharedMap = null;
  const markers = [];

  const initSharedMap = () => {
    if (sharedMap || !mapEl || typeof L === 'undefined') return;

    let locations = [];
    try { locations = JSON.parse(mapEl.dataset.locations || '[]'); } catch (e) {}
    if (!locations.length) return;

    const zoom = parseInt(mapEl.dataset.zoom || '12', 10);

    sharedMap = L.map(mapEl, { scrollWheelZoom: false });
    L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://cartodb.com/attributions">CARTO</a>'
    }).addTo(sharedMap);

    const latlngs = [];
    locations.forEach((loc) => {
      const lat = parseFloat(String(loc.lat).trim());
      const lng = parseFloat(String(loc.lng).trim());
      if (isNaN(lat) || isNaN(lng)) return;

      const marker = L.marker([lat, lng]).addTo(sharedMap);
      const popupHtml = (loc.address || loc.label || '').replace(/\n/g, '<br>');
      if (popupHtml) marker.bindPopup(popupHtml);
      markers.push({ lat, lng, marker });
      latlngs.push([lat, lng]);
    });

    if (latlngs.length === 1) {
      sharedMap.setView(latlngs[0], zoom);
    } else if (latlngs.length > 1) {
      sharedMap.fitBounds(L.latLngBounds(latlngs), { padding: [30, 30] });
    }
  };

  const focusFromBtn = (btn) => {
    if (!sharedMap || !btn) return;
    const lat = parseFloat(btn.dataset.mapLat);
    const lng = parseFloat(btn.dataset.mapLng);
    if (isNaN(lat) || isNaN(lng)) return;

    const found = markers.find(m =>
      Math.abs(m.lat - lat) < 1e-5 && Math.abs(m.lng - lng) < 1e-5
    );

    if (prefersReduced) {
      sharedMap.setView([lat, lng], sharedMap.getZoom());
    } else {
      sharedMap.flyTo([lat, lng], Math.max(sharedMap.getZoom(), 14), { duration: 0.7 });
    }
    if (found) found.marker.openPopup();
  };

  initSharedMap();
  if (sharedMap) setTimeout(() => sharedMap.invalidateSize(), 50);
  focusFromBtn(tabs[active]);

  tabs.forEach((btn, i) => {
    btn.addEventListener('click', () => {
      if (i === active) return;
      tabs[active].classList.remove('active');
      btn.classList.add('active');
      active = i;
      focusFromBtn(btn);
    });
  });
});