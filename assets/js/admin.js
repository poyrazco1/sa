/**
 * Admin paneli (Ayarlar + Kullanıcılar) — app.js'ten bağımsız.
 * settings_api.php ve users_api.php ile konuşur; CSRF csrf.js ile.
 * DOM textContent/createElement ile XSS güvenli.
 */
(function () {
  'use strict';
  var root = document.getElementById('adminRoot');
  if (!root) return;

  function el(t, c, x) { var e = document.createElement(t); if (c) e.className = c; if (x != null) e.textContent = x; return e; }
  function clear(n) { while (n.firstChild) n.removeChild(n.firstChild); }
  function j(u, o) { return fetch(u, o).then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Geçersiz yanıt.' }; }); }); }
  function toast(m) { var t = document.getElementById('copyToast'); if (!t) return; t.textContent = m; t.classList.add('show'); setTimeout(function () { t.classList.remove('show'); }, 1800); }
  function post(url, payload, cb) {
    j(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
      .then(function (r) { if (r && r.ok) { toast(r.message || 'Kaydedildi'); if (cb) cb(r); } else alert((r && r.message) || 'İşlem başarısız.'); })
      .catch(function () { alert('Bağlantı hatası.'); });
  }
  function field(labelText, inp) { var d = el('div'); d.appendChild(el('label', null, labelText)); d.appendChild(inp); return d; }
  function inp(val) { var i = el('input'); i.type = 'text'; i.value = (val == null ? '' : val); return i; }
  function chk(on) { var c = el('input'); c.type = 'checkbox'; c.checked = !!on; c.style.width = 'auto'; c.style.minHeight = '0'; c.style.transform = 'scale(1.2)'; return c; }

  // Sekmeler
  var tabsWrap = el('div', 'moduleSwitch');
  var panel = el('div');
  var TABS = [
    { id: 'settings', label: 'Genel ayarlar' },
    { id: 'payments', label: 'Ödeme oranları' },
    { id: 'carriers', label: 'Kargo firmaları' },
    { id: 'tariffs', label: 'Kargo tarifeleri' },
    { id: 'users', label: 'Kullanıcılar' }
  ];
  var active = 'settings';
  var DATA = null, USERS = null, ROLES = null;
  var settingsTried = false, usersTried = false;

  clear(root);
  var head = el('div', 'pageHead');
  head.appendChild(el('h1', null, 'Yönetim'));
  head.appendChild(el('p', null, 'Fiyat/kargo parametreleri ve kullanıcı-rol yönetimi.'));
  root.appendChild(head);
  root.appendChild(tabsWrap);
  root.appendChild(panel);

  TABS.forEach(function (t) {
    var b = el('button', t.id === active ? 'active' : null, t.label);
    b.type = 'button';
    b.onclick = function () { active = t.id; renderTabs(); renderPanel(); };
    t._btn = b;
    tabsWrap.appendChild(b);
  });
  function renderTabs() { TABS.forEach(function (t) { t._btn.classList.toggle('active', t.id === active); }); }

  function loadSettings(cb) {
    j('api/settings_api.php?action=get').then(function (r) { DATA = (r && r.ok) ? r.data : null; settingsTried = true; cb(); }).catch(function () { DATA = null; settingsTried = true; cb(); });
  }
  function loadUsers(cb) {
    Promise.all([
      j('api/users_api.php?action=list'),
      j('api/users_api.php?action=roles')
    ]).then(function (res) {
      USERS = (res[0] && res[0].ok) ? res[0].data : null;
      ROLES = (res[1] && res[1].ok) ? res[1].data : [];
      usersTried = true;
      cb();
    }).catch(function () { USERS = null; ROLES = []; usersTried = true; cb(); });
  }

  function renderPanel() {
    clear(panel);
    if (active === 'users') {
      if (USERS) { renderUsers(); return; }
      if (!usersTried) { panel.appendChild(el('div', 'labelHint', 'Yükleniyor…')); loadUsers(renderPanel); return; }
      panel.appendChild(el('div', 'error', 'Kullanıcılar alınamadı (yetki veya veritabanı hatası).'));
      return;
    }
    if (!DATA) {
      if (!settingsTried) { panel.appendChild(el('div', 'labelHint', 'Yükleniyor…')); loadSettings(renderPanel); return; }
      panel.appendChild(el('div', 'error', 'Ayarlar alınamadı (yetki veya veritabanı hatası).'));
      return;
    }
    if (active === 'settings') renderGeneral();
    else if (active === 'payments') renderPayments();
    else if (active === 'carriers') renderCarriers();
    else if (active === 'tariffs') renderTariffs();
  }

  function card(title) { var c = el('div', 'panel'); c.style.padding = '22px'; c.style.marginTop = '4px'; if (title) c.appendChild(el('div', 'crmDetailLabel', title)); return c; }
  function saveBar(onSave) { var a = el('div', 'actions'); a.appendChild(el('span')); var b = el('button', 'primary', 'Kaydet'); b.type = 'button'; b.onclick = onSave; a.appendChild(b); return a; }

  /* ---- Genel ayarlar ---- */
  function renderGeneral() {
    var s = DATA.settings || {};
    var c = card('Fiyat ve gönderici ayarları');
    var grid = el('div', 'grid');
    var fields = {
      vat_rate: inp(s.vat_rate), free_cargo_threshold_try: inp(s.free_cargo_threshold_try), sarf_expense_usd: inp(s.sarf_expense_usd),
      default_sender_name: inp(s.default_sender_name), default_sender_address: inp(s.default_sender_address), default_sender_phone: inp(s.default_sender_phone)
    };
    grid.appendChild(field('KDV oranı (0.20 = %20)', fields.vat_rate));
    grid.appendChild(field('Ücretsiz kargo eşiği (TL)', fields.free_cargo_threshold_try));
    grid.appendChild(field('Gizli sarf gideri (USD)', fields.sarf_expense_usd));
    grid.appendChild(field('Gönderici adı', fields.default_sender_name));
    var addrCell = field('Gönderici adresi', fields.default_sender_address); addrCell.style.gridColumn = '1 / -1'; grid.appendChild(addrCell);
    grid.appendChild(field('Gönderici telefonu', fields.default_sender_phone));
    c.appendChild(grid);
    c.appendChild(saveBar(function () {
      var items = {}; Object.keys(fields).forEach(function (k) { items[k] = fields[k].value; });
      post('api/settings_api.php', { action: 'save_settings', items: items }, function () { DATA = null; settingsTried = false; renderPanel(); });
    }));
    panel.appendChild(c);
  }

  /* ---- tablo yardımcı ---- */
  function buildTable(headers) {
    var wrap = el('div', 'tableWrap'); var t = el('table'); var th = el('thead'); var tr = el('tr');
    headers.forEach(function (h) { tr.appendChild(el('th', null, h)); }); th.appendChild(tr);
    var tb = el('tbody'); t.appendChild(th); t.appendChild(tb); wrap.appendChild(t);
    return { wrap: wrap, body: tb };
  }
  function cell(node) { var td = el('td'); if (typeof node === 'string') td.textContent = node; else td.appendChild(node); return td; }

  /* ---- Ödeme oranları ---- */
  function renderPayments() {
    var c = card('Ödeme yöntemi oranları (yüzde)');
    var tbl = buildTable(['Yöntem', 'Etki', 'Oran (%)', 'Aktif']);
    var rows = [];
    (DATA.payments || []).forEach(function (p) {
      var rate = inp((Number(p.rate) * 100).toFixed(2).replace(/\.00$/, '')); rate.style.maxWidth = '120px'; rate.style.textAlign = 'right';
      var act = chk(Number(p.is_active) === 1);
      var tr = el('tr');
      tr.appendChild(cell(p.label)); tr.appendChild(cell(el('span', 'muted', p.effect_type))); tr.appendChild(cell(rate)); tr.appendChild(cell(act));
      tbl.body.appendChild(tr);
      rows.push({ code: p.code, rate: rate, act: act });
    });
    c.appendChild(tbl.wrap);
    c.appendChild(el('div', 'labelHint', 'Not: Oran yüzde olarak girilir (örn. 3,20). Etki tipi fiyat motoruna göre sabittir.'));
    c.appendChild(saveBar(function () {
      var out = rows.map(function (r) { return { code: r.code, rate: r.rate.value, is_active: r.act.checked ? 1 : 0 }; });
      post('api/settings_api.php', { action: 'save_payments', rows: out }, function () { DATA = null; settingsTried = false; renderPanel(); });
    }));
    panel.appendChild(c);
  }

  /* ---- Kargo firmaları ---- */
  function renderCarriers() {
    var c = card('Kargo firmaları');
    var tbl = buildTable(['Firma', 'Anlaşma kodu', 'Çarpan', 'Fiyatta', 'Etikette']);
    var rows = [];
    (DATA.carriers || []).forEach(function (cr) {
      var ac = inp(cr.agreement_code); var pm = inp(cr.pricing_multiplier); pm.style.maxWidth = '100px'; pm.style.textAlign = 'right';
      var ap = chk(Number(cr.is_active_price) === 1); var al = chk(Number(cr.is_active_label) === 1);
      var tr = el('tr');
      tr.appendChild(cell(cr.label)); tr.appendChild(cell(ac)); tr.appendChild(cell(pm)); tr.appendChild(cell(ap)); tr.appendChild(cell(al));
      tbl.body.appendChild(tr);
      rows.push({ code: cr.code, ac: ac, pm: pm, ap: ap, al: al });
    });
    c.appendChild(tbl.wrap);
    c.appendChild(saveBar(function () {
      var out = rows.map(function (r) { return { code: r.code, agreement_code: r.ac.value, pricing_multiplier: r.pm.value, is_active_price: r.ap.checked ? 1 : 0, is_active_label: r.al.checked ? 1 : 0 }; });
      post('api/settings_api.php', { action: 'save_carriers', rows: out }, function () { DATA = null; settingsTried = false; renderPanel(); });
    }));
    panel.appendChild(c);
  }

  /* ---- Kargo tarifeleri ---- */
  function renderTariffs() {
    var c = card('Kargo tarifeleri (desi bazlı taban ücret)');
    var tbl = buildTable(['Firma', 'Desi aralığı', 'Taban ücret (TL)', 'Aktif']);
    var rows = [];
    (DATA.tariffs || []).forEach(function (t) {
      var bp = inp(t.base_price_try); bp.style.maxWidth = '120px'; bp.style.textAlign = 'right';
      var act = chk(Number(t.is_active) === 1);
      var tr = el('tr');
      tr.appendChild(cell(t.carrier_code)); tr.appendChild(cell((t.min_desi + ' - ' + t.max_desi))); tr.appendChild(cell(bp)); tr.appendChild(cell(act));
      tbl.body.appendChild(tr);
      rows.push({ id: t.id, bp: bp, act: act });
    });
    c.appendChild(tbl.wrap);
    c.appendChild(saveBar(function () {
      var out = rows.map(function (r) { return { id: r.id, base_price_try: r.bp.value, is_active: r.act.checked ? 1 : 0 }; });
      post('api/settings_api.php', { action: 'save_tariffs', rows: out }, function () { DATA = null; settingsTried = false; renderPanel(); });
    }));
    panel.appendChild(c);
  }

  /* ---- Kullanıcılar ---- */
  var editingUser = 0;
  function renderUsers() {
    var c = card(null);
    var top = el('div', 'crmHead');
    top.appendChild(el('div', 'crmDetailLabel', 'Kullanıcılar'));
    var nb = el('button', 'primary', '+ Yeni kullanıcı'); nb.type = 'button'; nb.onclick = function () { openUserForm(null); };
    top.appendChild(nb);
    c.appendChild(top);

    var formBox = el('div', 'customerBox hide'); c._form = formBox; c.appendChild(formBox);

    var tbl = buildTable(['Kullanıcı', 'Ad', 'Rol', 'Aktif', 'Son giriş', '']);
    (USERS || []).forEach(function (u) {
      var tr = el('tr');
      tr.appendChild(cell(el('b', null, u.username)));
      tr.appendChild(cell(u.full_name || '—'));
      tr.appendChild(cell(el('span', 'crmBadge crmBadge-accent', u.role_name || u.role_code)));
      tr.appendChild(cell(Number(u.is_active) === 1 ? 'Evet' : 'Hayır'));
      tr.appendChild(cell((u.last_login_at || '').substring(0, 16) || '—'));
      var act = el('td'); act.style.whiteSpace = 'nowrap';
      var ed = el('button', 'ghost', 'Düzenle'); ed.type = 'button'; ed.style.padding = '7px 12px'; ed.onclick = function () { openUserForm(u, c._form); };
      var del = el('button', 'ghost dangerBtn', 'Sil'); del.type = 'button'; del.style.padding = '7px 12px'; del.style.marginLeft = '6px';
      del.onclick = function () { if (window.confirm('“' + u.username + '” silinsin mi?')) post('api/users_api.php', { action: 'delete', id: u.id }, function () { USERS = null; usersTried = false; renderPanel(); }); };
      act.appendChild(ed); act.appendChild(del); tr.appendChild(act);
      tbl.body.appendChild(tr);
    });
    c.appendChild(tbl.wrap);
    panel.appendChild(c);
    c._form._host = c;
  }

  function openUserForm(u, box) {
    box = box || panel.querySelector('.customerBox');
    if (!box) return;
    editingUser = u ? (u.id | 0) : 0;
    clear(box);
    box.appendChild(el('div', 'customerFormMode', editingUser ? 'Kullanıcı düzenleniyor' : 'Yeni kullanıcı'));
    var grid = el('div', 'grid');
    var username = inp(u ? u.username : ''); var full = inp(u ? u.full_name : ''); var email = inp(u ? u.email : '');
    var role = el('select'); (ROLES || []).forEach(function (r) { var o = el('option', null, r.name); o.value = r.code; role.appendChild(o); }); if (u) role.value = u.role_code;
    var active = chk(u ? Number(u.is_active) === 1 : true);
    var pass = el('input'); pass.type = 'password'; pass.autocomplete = 'new-password'; pass.placeholder = editingUser ? 'Değiştirmek için doldur' : 'En az 8 karakter';
    grid.appendChild(field('Kullanıcı adı *', username));
    grid.appendChild(field('Ad Soyad', full));
    grid.appendChild(field('E-posta', email));
    grid.appendChild(field('Rol', role));
    grid.appendChild(field('Parola' + (editingUser ? ' (opsiyonel)' : ' *'), pass));
    var actWrap = el('div'); actWrap.appendChild(el('label', null, 'Aktif')); var al = el('div'); al.style.paddingTop = '10px'; al.appendChild(active); actWrap.appendChild(al); grid.appendChild(actWrap);
    box.appendChild(grid);
    var err = el('div', 'error'); box.appendChild(err);
    var acts = el('div', 'actions');
    var cancel = el('button', 'ghost', 'Vazgeç'); cancel.type = 'button'; cancel.onclick = function () { box.classList.add('hide'); };
    var save = el('button', 'primary', 'Kaydet'); save.type = 'button';
    save.onclick = function () {
      var payload = { action: 'save', id: editingUser, username: username.value, full_name: full.value, email: email.value, role_code: role.value, is_active: active.checked ? 1 : 0, password: pass.value };
      j('api/users_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) }).then(function (r) {
        if (r && r.ok) { toast(r.message || 'Kaydedildi'); box.classList.add('hide'); USERS = null; usersTried = false; renderPanel(); }
        else err.textContent = (r && r.message) || 'Kaydedilemedi.';
      }).catch(function () { err.textContent = 'Bağlantı hatası.'; });
    };
    acts.appendChild(cancel); acts.appendChild(save); box.appendChild(acts);
    box.classList.remove('hide');
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  renderPanel();
})();
