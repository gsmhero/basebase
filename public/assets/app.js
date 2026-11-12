(() => {
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];

  // mobile nav
  $('#burger')?.addEventListener('click', () => $('#nav').classList.toggle('open'));

  // product gallery
  $$('.gal-th button').forEach(b => b.addEventListener('click', () => {
    $('.gal-main').innerHTML = '<img src="' + b.dataset.src + '" alt="">';
    $$('.gal-th button').forEach(x => x.classList.toggle('on', x === b));
  }));

  // quantity steppers
  $$('.qty').forEach(q => {
    const i = $('input', q);
    $$('button', q).forEach(b => b.addEventListener('click', () => {
      i.value = Math.max(1, Math.min(+i.max || 999, (+i.value || 1) + (b.dataset.d === '+' ? 1 : -1)));
      i.dispatchEvent(new Event('change', { bubbles: true }));
    }));
  });
  $$('form[data-autosubmit] .qty input').forEach(i => i.addEventListener('change', () => i.form.submit()));

  // copy to clipboard
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-copy]');
    if (!b) return;
    const v = b.dataset.copy;
    (navigator.clipboard ? navigator.clipboard.writeText(v) : Promise.reject()).catch(() => {
      const t = document.createElement('textarea'); t.value = v; document.body.append(t); t.select(); document.execCommand('copy'); t.remove();
    });
    const old = b.innerHTML; b.textContent = 'Copied ✓'; setTimeout(() => b.innerHTML = old, 1400);
  });

  // repeaters
  $$('[data-repeat]').forEach(box => {
    const tpl = $('template', box), list = $('.ed-rep', box);
    $('[data-add]', box).addEventListener('click', () => list.append(tpl.content.cloneNode(true)));
    list.addEventListener('click', e => { if (e.target.closest('[data-del]')) e.target.closest('.rep-row').remove(); });
  });

  // image preview
  $$('input[type=file][data-preview]').forEach(f => f.addEventListener('change', () => {
    const box = $(f.dataset.preview); if (!box) return; box.innerHTML = '';
    [...f.files].slice(0, 12).forEach(file => { const im = new Image(); im.src = URL.createObjectURL(file); box.append(im); });
  }));

  // tabs (client side)
  $$('[data-tabs]').forEach(w => {
    const btns = $$('[data-tab]', w), panes = $$('[data-pane]', w);
    const show = n => { btns.forEach(b => b.classList.toggle('on', b.dataset.tab === n)); panes.forEach(p => p.hidden = p.dataset.pane !== n); const h = $('input[data-tabval]', w); if (h) h.value = n; };
    btns.forEach(b => b.addEventListener('click', () => show(b.dataset.tab)));
    show(w.dataset.tabs || btns[0]?.dataset.tab);
  });

  // confirm
  document.addEventListener('submit', e => { const c = e.target.dataset.confirm; if (c && !confirm(c)) e.preventDefault(); });

  // set price = sum of ticked products
  const st = $('#set-total');
  if (st) {
    const boxes = $$('input[data-price]', st.closest('form'));
    const calc = () => { st.textContent = boxes.reduce((s, b) => s + (b.checked ? +b.dataset.price : 0), 0).toLocaleString('ru-RU') + ' ₽'; };
    boxes.forEach(b => b.addEventListener('change', calc)); calc();
  }

  // product form: show how the margin is split
  const sp = $('#split');
  if (sp) {
    const pr = $('#f-price'), pm = $('#f-promo'), out = $('#split-out'), pct = +sp.dataset.pct || 0;
    const f = n => Math.round(n).toLocaleString('ru-RU') + ' ₽';
    const calc = () => {
      const a = +pr.value || 0, b = +pm.value || 0;
      if (!a || !b || b >= a) { out.textContent = a ? 'Without a promo price the whole price goes to you.' : ''; return; }
      const m = a - b, fee = m * pct / 100;
      out.textContent = 'Direct sale: you receive ' + f(a - fee) + ' · platform ' + f(fee) + '. Via a blogger: you receive ' + f(b) + ' · blogger ' + f(m - fee) + ' · platform ' + f(fee) + '.';
    };
    pr.addEventListener('input', calc); pm.addEventListener('input', calc); calc();
  }

  // toasts + in-place toggles (Watch later, Collect): no page jump
  const toast = (msg, type) => {
    let box = $('#toasts');
    if (!box) { box = document.createElement('div'); box.id = 'toasts'; box.setAttribute('aria-live', 'polite'); document.body.append(box); }
    const t = document.createElement('div'); t.className = 'toast ' + (type || ''); t.textContent = msg; box.append(t);
    requestAnimationFrame(() => t.classList.add('in'));
    setTimeout(() => { t.classList.remove('in'); setTimeout(() => t.remove(), 300); }, 2600);
  };
  document.addEventListener('submit', async e => {
    const f = e.target;
    if (!/\/do\/(wish|collect)$/.test(f.getAttribute('action') || '')) return;
    e.preventDefault();
    const btn = $('button', f); if (btn) btn.disabled = true;
    try {
      const r = await fetch(f.action, { method: 'POST', body: new FormData(f), headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' });
      if (r.status === 401) { location.href = '/login'; return; }
      if (!r.ok) { toast('Something went wrong. Please try again.', 'bad'); return; }
      const j = await r.json();
      (j.msgs || []).forEach(([type, msg]) => toast(msg, type));
      const on = j.state && j.state.on;
      if (btn && j.state && typeof on === 'boolean') {
        btn.classList.toggle('on', on);
        if (f.action.endsWith('/collect')) btn.classList.toggle('dark', !on);
        const lbl = $('.lbl', btn); if (lbl && btn.dataset.on) lbl.textContent = on ? btn.dataset.on : btn.dataset.off;
      }
      if (j.state && typeof j.state.count === 'number') {
        const tool = $('.tool[href="/watch"]'); const old = tool && $('i', tool);
        if (tool) { if (j.state.count) { if (old) old.textContent = j.state.count; else tool.insertAdjacentHTML('beforeend', '<i>' + j.state.count + '</i>'); } else if (old) old.remove(); }
      }
    } catch (err) { f.submit(); }
    finally { if (btn) btn.disabled = false; }
  });

  // chat scroll
  const m = $('.msgs'); if (m) m.scrollTop = m.scrollHeight;
})();
