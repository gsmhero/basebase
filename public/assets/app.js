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

  // chat scroll
  const m = $('.msgs'); if (m) m.scrollTop = m.scrollHeight;
})();
