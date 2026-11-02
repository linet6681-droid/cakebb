// Small UI helpers (no libraries)
// Back/forward button: never show a page from the browser's memory (it may be a protected page from before logout)
window.addEventListener('pageshow', e => { if (e.persisted) location.reload(); });
document.addEventListener('DOMContentLoaded', () => {
  // live delivery fee: the fee comes from the chosen delivery area (the server re-checks it from the database)
  const zone = document.getElementById('zone'), loc = document.getElementById('location'), box = document.querySelector('.summary');
  if (zone && box) {
    const sub = +box.dataset.subtotal, fmt = n => 'Ksh ' + Math.round(n).toLocaleString('en-US');
    const upd = () => {
      const f = +(zone.selectedOptions[0]?.dataset.fee || 0);
      document.getElementById('fee').textContent = f > 0 ? fmt(f) : (zone.value ? 'Free' : 'Ksh 0'); document.getElementById('total').textContent = fmt(sub + f);
      if (loc) { loc.required = zone.value !== ''; loc.placeholder = zone.value ? 'Street, estate or landmark (required)' : 'Not needed for pickup'; }
    };
    zone.addEventListener('change', upd); upd();
    // show the M-Pesa phone box only when M-Pesa is chosen; stop double submits
    const mb = document.getElementById('mpesabox'), radios = document.querySelectorAll('input[name=method]');
    const sw = () => { const m = document.querySelector('input[name=method]:checked'); if (mb) mb.style.display = (!m || m.value === 'mpesa') ? '' : 'none'; };
    radios.forEach(r => r.addEventListener('change', sw)); sw();
    const co = document.getElementById('checkout'), pb = document.getElementById('placebtn');
    if (co && pb) co.addEventListener('submit', () => { pb.disabled = true; pb.textContent = 'Placing order…'; });
  }
  // admin dropdown
  document.querySelectorAll('[data-dropdown]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); b.nextElementSibling.classList.toggle('open'); }));
  document.addEventListener('click', () => document.querySelectorAll('.dropdown-menu.open').forEach(m => m.classList.remove('open')));
  // file name on product upload
  const fi = document.querySelector('.upload input'); if (fi) fi.addEventListener('change', () => document.getElementById('fname').textContent = fi.files[0]?.name || 'Upload image');
  // order table: click a row to select, then use the action buttons
  const rows = document.querySelectorAll('#orders [data-row]');
  rows.forEach(r => r.addEventListener('click', () => { rows.forEach(x => x.classList.remove('sel')); r.classList.add('sel'); }));
  document.querySelectorAll('[data-open]').forEach(b => b.addEventListener('click', () => {
    const sel = document.querySelector('#orders tr.sel'); if (!sel) { alert('Click an order row first.'); return; }
    if (b.dataset.need && sel.dataset.status !== b.dataset.need) { alert('Only delivered orders can be deleted. Pick a delivered order, or use Cancel order.'); return; }
    const d = document.getElementById(b.dataset.open);
    d.querySelector('.sel-id').value = sel.dataset.id; d.querySelector('.sel-label').textContent = '#' + sel.dataset.id;
    const s = d.querySelector('select'); if (s) s.value = sel.dataset.status;
    d.showModal();
  }));
  document.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', () => b.closest('dialog').close()));
});
