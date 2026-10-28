/* DIARRASSOUBA — améliorations progressives. L'application reste utilisable sans JavaScript. */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const csrf = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /* ---------- Confirmation avant action sensible ---------- */
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-confirm]');
    if (b && !window.confirm(b.dataset.confirm)) { e.preventDefault(); e.stopPropagation(); }
  }, true);

  /* ---------- Un seul envoi par formulaire (évite les doubles enregistrements) ---------- */
  document.addEventListener('submit', (e) => {
    const f = e.target;
    if (e.defaultPrevented) return;
    if (f.dataset.sending === '1') { e.preventDefault(); return; }
    if ((f.getAttribute('method') || 'get').toLowerCase() === 'post') {
      if (!navigator.onLine) {
        e.preventDefault();
        window.alert('Pas de connexion : rien n’a été envoyé. Réessayez quand la connexion sera revenue.');
        return;
      }
      f.dataset.sending = '1';
      $$('button:not([type="button"])', f).forEach((b) => { b.setAttribute('aria-disabled', 'true'); });
      setTimeout(() => { f.dataset.sending = ''; $$('button', f).forEach((b) => b.removeAttribute('aria-disabled')); }, 8000);
    }
  });

  /* ---------- Impression ---------- */
  $$('[data-print]').forEach((b) => b.addEventListener('click', () => window.print()));

  /* ---------- Tout sélectionner ---------- */
  $$('[data-select-all]').forEach((all) => {
    const scope = all.closest('[data-select-all-scope]') || document;
    all.addEventListener('change', () => {
      // Seulement les lignes visibles (le filtre peut en masquer)
      $$('input[type="checkbox"][name$="[]"]', scope).forEach((c) => { if (!c.closest('[hidden]')) c.checked = all.checked; });
      scope.dispatchEvent(new Event('change'));
    });
  });

  /* ---------- Liste de chargement : filtre instantané et total sélectionné ---------- */
  $$('[data-pick-list]').forEach((list) => {
    const items = $$('label.pick', list);
    const counter = $('[data-pick-count]', list);
    const filter = $('[data-pick-filter]', list);
    const kgFmt = (g) => (g / 1000).toLocaleString('fr-FR', { maximumFractionDigits: 2 }) + ' kg';
    const update = () => {
      const picked = items.filter((l) => l.querySelector('input').checked);
      const grams = picked.reduce((t, l) => t + (parseInt(l.dataset.weight, 10) || 0), 0);
      if (counter) counter.textContent = picked.length ? `${picked.length} colis sélectionné${picked.length > 1 ? 's' : ''} · ${kgFmt(grams)}` : 'Aucun colis sélectionné';
    };
    list.addEventListener('change', update);
    if (filter) {
      const norm = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[\s-]/g, '');
      filter.addEventListener('input', () => {
        const q = norm(filter.value);
        items.forEach((l) => { l.hidden = q !== '' && !norm(l.textContent).includes(q); });
      });
      filter.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
    }
    update();
  });

  /* ---------- Liste déroulante qui navigue ---------- */
  $$('[data-nav-select]').forEach((s) => s.addEventListener('change', () => {
    window.location.href = s.dataset.navSelect.replace(/&amp;/g, '&') + encodeURIComponent(s.value);
  }));

  /* ---------- Sélecteur de client ---------- */
  $$('[data-picker]').forEach((root) => {
    const hidden = $('input[type="hidden"]', root);
    const chosen = $('.picker-chosen', root);
    const box = $('[data-search-box]', root);
    const input = $('[data-search-input]', root);
    const results = $('.picker-results', root);
    const nav = root.dataset.navigate;
    let timer = null; let ctrl = null;
    const choose = (c) => {
      if (nav) { window.location.href = nav + c.id; return; }
      hidden.value = c.id;
      $('[data-who-name]', chosen).textContent = c.name;
      $('[data-who-meta]', chosen).textContent = [c.code, c.phone, c.city].filter(Boolean).join('  ');
      chosen.hidden = false; box.hidden = true; results.hidden = true;
    };
    const clear = $('[data-clear]', root);
    if (clear) clear.addEventListener('click', () => { hidden.value = ''; chosen.hidden = true; box.hidden = false; input.value = ''; input.focus(); });
    if (!input) return;
    input.addEventListener('input', () => {
      clearTimeout(timer);
      const q = input.value.trim();
      if (q.length < 2) { results.hidden = true; return; }
      timer = setTimeout(async () => {
        try {
          if (ctrl) ctrl.abort();
          ctrl = new AbortController();
          const r = await fetch(root.dataset.search + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, signal: ctrl.signal, credentials: 'same-origin' });
          if (!r.ok) throw new Error(r.status);
          const list = await r.json();
          results.innerHTML = list.length
            ? list.map((c, i) => `<button type="button" role="option" data-i="${i}"><strong>${esc(c.name)}</strong><br><span class="small muted">${esc([c.code, c.phone, c.city].filter(Boolean).join('  '))}</span></button>`).join('')
            : '<p class="small muted" style="padding:10px 12px;margin:0">Aucun client trouvé. Saisissez ses coordonnées ci-dessous.</p>';
          results.hidden = false;
          $$('button', results).forEach((b) => b.addEventListener('click', () => choose(list[+b.dataset.i])));
        } catch (err) {
          if (err.name !== 'AbortError') { results.innerHTML = '<p class="small" style="padding:10px 12px;margin:0">Recherche impossible pour le moment.</p>'; results.hidden = false; }
        }
      }, 220);
    });
    input.addEventListener('keydown', (e) => { if (e.key === 'Escape') results.hidden = true; });
    document.addEventListener('click', (e) => { if (!root.contains(e.target)) results.hidden = true; });
  });

  /* ---------- Calcul indicatif du montant d'un colis ---------- */
  const pf = $('[data-parcel-form]');
  if (pf) {
    const w = $('#weight_kg', pf), rate = $('#rate', pf), cur = $('#currency', pf), tariff = $('#tariff_id', pf), calc = $('[data-calc]', pf);
    const parseNum = (s) => { s = String(s || '').replace(/[\s  ]/g, '').replace(',', '.'); return s === '' || isNaN(+s) ? null : +s; };
    const fmt = (n, exp) => n.toLocaleString('fr-FR', { minimumFractionDigits: exp, maximumFractionDigits: exp });
    const update = () => {
      const opt = tariff && tariff.selectedOptions[0];
      const minG = opt && opt.dataset.min ? +opt.dataset.min : 0;
      const step = +calc.dataset.step || 100;
      const kg = parseNum(w.value), r = parseNum(rate.value);
      const exp = +(cur.selectedOptions[0] || {}).dataset.exp || 0;
      if (kg === null || kg <= 0) { $('[data-calc-weight]', calc).textContent = '—'; $('[data-calc-amount]', calc).textContent = '—'; return; }
      const g = Math.max(Math.ceil(Math.round(kg * 1000) / step) * step, minG);
      $('[data-calc-weight]', calc).textContent = fmt(g / 1000, g % 1000 ? (g % 100 ? 2 : 1) : 0) + ' kg';
      if (r === null) { $('[data-calc-amount]', calc).textContent = '—'; return; }
      const minor = Math.round(r * Math.pow(10, exp));
      const amount = Math.floor((g * minor + 500) / 1000) / Math.pow(10, exp);
      $('[data-calc-amount]', calc).textContent = fmt(amount, exp) + ' ' + cur.value;
    };
    if (tariff) tariff.addEventListener('change', () => {
      const o = tariff.selectedOptions[0];
      if (o && o.dataset.rate) { rate.value = o.dataset.rate; cur.value = o.dataset.currency; }
      update();
    });
    [w, rate, cur].forEach((el) => el && el.addEventListener('input', update));
    if (cur) cur.addEventListener('change', update);
    update();
  }

  /* ---------- Échéancier visible seulement pour acompte / échéances ---------- */
  const terms = $('[data-terms]');
  const inst = $('[data-installments]');
  if (terms && inst) terms.addEventListener('change', () => { inst.hidden = !['installments', 'deposit'].includes(terms.value); });

  /* ---------- Champs propres aux billets d'avion ---------- */
  const ef = $('[data-expense-form]');
  if (ef) {
    const cat = $('[data-category]', ef), tf = $('[data-ticket-fields]', ef), sf = $('[data-supplier-field]', ef);
    const sync = () => { const t = cat.value === 'air_ticket'; tf.hidden = !t; sf.hidden = t; };
    cat.addEventListener('change', sync); sync();
  }

  /* ---------- Partage (Web Share API, sinon copie du lien) ---------- */
  const share = async (url, title) => {
    if (navigator.share) {
      try { await navigator.share({ title, url }); return; } catch (e) { if (e.name === 'AbortError') return; }
    }
    try { await navigator.clipboard.writeText(url); window.alert('Lien copié :\n' + url); }
    catch (e) { window.prompt('Copiez ce lien :', url); }
  };
  document.addEventListener('click', async (e) => {
    const direct = e.target.closest('[data-share-url]');
    if (direct) { e.preventDefault(); share(direct.dataset.shareUrl, direct.dataset.shareTitle || document.title); return; }
    const b = e.target.closest('[data-share-endpoint]');
    if (!b) return;
    e.preventDefault();
    b.setAttribute('aria-disabled', 'true');
    try {
      const r = await fetch(b.dataset.shareEndpoint, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-Token': csrf() }, credentials: 'same-origin' });
      const data = await r.json();
      if (!r.ok) throw new Error(data.error || 'Partage impossible');
      await share(data.url, data.title + ' (lien valable ' + data.hours + ' h)');
    } catch (err) { window.alert(err.message || 'Partage impossible.'); }
    finally { b.removeAttribute('aria-disabled'); }
  });

  /* ---------- Scanner / saisie de code ---------- */
  const codeForm = $('#code-form');
  if (codeForm) {
    const errBox = $('#code-error');
    const open = async (code) => {
      errBox.hidden = true;
      try {
        const r = await fetch(codeForm.dataset.lookup + '?code=' + encodeURIComponent(code), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const data = await r.json();
        if (data.found) { window.location.href = data.url; return true; }
        errBox.textContent = data.message || 'Code inconnu.'; errBox.hidden = false;
      } catch (e) { errBox.textContent = 'Recherche impossible : vérifiez la connexion.'; errBox.hidden = false; }
      return false;
    };
    codeForm.addEventListener('submit', (e) => { e.preventDefault(); const v = $('#code').value.trim(); if (v) open(v); });

    const startBtn = $('#scan-start');
    const status = $('#scan-status');
    const supported = 'BarcodeDetector' in window && navigator.mediaDevices && navigator.mediaDevices.getUserMedia;
    if (!supported) { $('#scan-unsupported').hidden = false; startBtn.hidden = true; }
    else startBtn.addEventListener('click', async () => {
      let stream;
      try {
        const formats = await window.BarcodeDetector.getSupportedFormats();
        const detector = new window.BarcodeDetector({ formats: formats.filter((f) => ['code_128', 'qr_code'].includes(f)) });
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
        const video = $('#scan-video');
        video.srcObject = stream; await video.play();
        $('#scanner').hidden = false; startBtn.hidden = true;
        status.textContent = 'Visez le code-barres…';
        let busy = false;
        const tick = async () => {
          if (!stream.active) return;
          if (!busy) {
            busy = true;
            try {
              const codes = await detector.detect(video);
              if (codes.length) {
                const raw = codes[0].rawValue;
                status.textContent = 'Code lu : ' + raw;
                if (navigator.vibrate) navigator.vibrate(60);
                const ok = await open(raw.replace(/^.*\/suivi\//, ''));
                if (ok) { stream.getTracks().forEach((t) => t.stop()); return; }
              }
            } catch (e) { /* image suivante */ }
            busy = false;
          }
          requestAnimationFrame(tick);
        };
        tick();
      } catch (e) {
        status.textContent = e.name === 'NotAllowedError' ? 'Accès à la caméra refusé. Autorisez-le dans les réglages du navigateur, ou saisissez le code.' : 'Caméra indisponible : saisissez le code.';
        if (stream) stream.getTracks().forEach((t) => t.stop());
      }
    });
  }

  /* ---------- Pays -> ville par défaut (Égypte -> Le Caire, Sénégal -> Dakar…) ---------- */
  // La ville suit le pays, sauf si l'agent a saisi une autre ville lui-même.
  $$('select[data-city-target]').forEach((sel) => {
    const city = document.getElementById(sel.dataset.cityTarget);
    if (!city) return;
    let previous = sel.selectedOptions[0] ? sel.selectedOptions[0].dataset.city || '' : '';
    sel.addEventListener('change', () => {
      const next = sel.selectedOptions[0] ? sel.selectedOptions[0].dataset.city || '' : '';
      if (city.value.trim() === '' || city.value.trim() === previous) city.value = next;
      previous = next;
    });
  });

  /* ---------- Formulaire colis : vols proposés selon la destination ---------- */
  $$('[data-flight-pick]').forEach((box) => {
    const dest = document.getElementById('destination_country');
    const options = $$('.flight-option[data-destination]', box);
    const empty = $('[data-flight-empty]', box);
    const none = $('input[value=""]', box);
    const sync = () => {
      let visible = 0;
      options.forEach((o) => {
        const show = !dest || o.dataset.destination === dest.value;
        o.hidden = !show;
        if (show) visible++;
        else if (o.querySelector('input').checked && none) none.checked = true; // vol d'une autre destination : on décoche
      });
      if (empty) empty.hidden = visible > 0;
    };
    if (dest) dest.addEventListener('change', sync);
    sync();
  });

  /* ---------- Aperçu en direct (paramètres) ---------- */
  $$('[data-preview]').forEach((out) => {
    const field = document.querySelector(`[name="${out.dataset.preview}"]`);
    if (!field) return;
    field.addEventListener('input', () => {
      out.textContent = '';
      field.value.split('\n').forEach((line, i) => { if (i) out.appendChild(document.createElement('br')); out.appendChild(document.createTextNode(line)); });
    });
  });

  /* ---------- Installation de l'application (PWA) ---------- */
  const standalone = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
  const installBtns = $$('[data-install]');
  const installZone = $('[data-install-zone]');
  const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const iosHint = isIOS && !standalone; // iOS : pas d'invite automatique, on explique le geste
  let deferredPrompt = null;
  const showInstall = (on) => {
    installBtns.forEach((b) => { b.hidden = !on; });
    if (installZone) installZone.hidden = !on && !iosHint;
  };
  if (iosHint) {
    const hint = $('[data-install-ios]');
    if (hint) hint.hidden = false;
    if (installZone) installZone.hidden = false;
  }
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault(); // le bouton de l'application remplace la mini-barre du navigateur
    deferredPrompt = e;
    if (!standalone) showInstall(true);
  });
  installBtns.forEach((b) => b.addEventListener('click', async () => {
    if (!deferredPrompt) return;
    deferredPrompt.prompt();
    try { await deferredPrompt.userChoice; } catch (err) { /* fenêtre fermée */ }
    deferredPrompt = null;
    showInstall(false);
  }));
  window.addEventListener('appinstalled', () => { deferredPrompt = null; showInstall(false); });

  /* ---------- Horloges des escales (barre supérieure) ---------- */
  const clocks = $$('[data-clock]');
  if (clocks.length && window.Intl) {
    const tick = () => clocks.forEach((c) => {
      try { c.textContent = new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit', timeZone: c.dataset.clock }).format(new Date()); } catch (e) { /* fuseau inconnu */ }
    });
    tick(); setInterval(tick, 20000);
  }

  /* ---------- Hors connexion ---------- */
  const banner = $('#offline-banner');
  const setOnline = () => { if (banner) banner.classList.toggle('show', !navigator.onLine); };
  window.addEventListener('online', setOnline); window.addEventListener('offline', setOnline); setOnline();

  /* ---------- Service worker : mise à jour contrôlée ---------- */
  if ('serviceWorker' in navigator && document.body.dataset.sw) {
    navigator.serviceWorker.register(document.body.dataset.sw, { scope: (document.body.dataset.base || '') + '/' }).then((reg) => {
      const showUpdate = (worker) => {
        const ub = $('#update-banner');
        if (!ub) return;
        ub.classList.add('show');
        $('#update-btn').addEventListener('click', () => worker.postMessage('skipWaiting'), { once: true });
      };
      if (reg.waiting && navigator.serviceWorker.controller) showUpdate(reg.waiting);
      reg.addEventListener('updatefound', () => {
        const nw = reg.installing;
        nw.addEventListener('statechange', () => { if (nw.state === 'installed' && navigator.serviceWorker.controller) showUpdate(nw); });
      });
    }).catch(() => {});
    let reloaded = false;
    navigator.serviceWorker.addEventListener('controllerchange', () => { if (!reloaded) { reloaded = true; window.location.reload(); } });
  }
})();
