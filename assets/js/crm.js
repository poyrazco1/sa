/**
 * CRM ön yüz motoru (Firmalar + Kişiler) — app.js'ten bağımsız.
 * Tek jenerik motor iki varlığı da yönetir. DOM, textContent/createElement ile
 * kurulur (XSS güvenli). Yazma istekleri csrf.js ile CSRF başlığı taşır.
 */
(function () {
  'use strict';

  var root = document.getElementById('crmRoot');
  if (!root) return;
  var view = root.getAttribute('data-crm-view') || 'companies';

  var CONFIGS = {
    companies: {
      endpoint: 'api/crm_companies.php',
      title: 'Firma',
      plural: 'Firmalar',
      hint: 'Müşteri firmalarını yönet; kişileri ve geçmişi tek kartta gör.',
      columns: [
        { key: 'name', label: 'Firma', strong: true, detail: true },
        { key: 'phone', label: 'Telefon' },
        { key: 'city', label: 'Şehir' },
        { key: 'contact_count', label: 'Kişi', num: true },
        { key: 'owner_name', label: 'Sahip' }
      ],
      fields: [
        { k: 'name', label: 'Firma adı *', req: true },
        { k: 'tax_office', label: 'Vergi dairesi' },
        { k: 'tax_no', label: 'Vergi no' },
        { k: 'phone', label: 'Telefon' },
        { k: 'email', label: 'E-posta' },
        { k: 'city', label: 'İl' },
        { k: 'county', label: 'İlçe' },
        { k: 'source', label: 'Kaynak (nereden geldi)' },
        { k: 'address', label: 'Adres', textarea: true }
      ],
      hasDetail: true
    },
    contacts: {
      endpoint: 'api/crm_contacts.php',
      title: 'Kişi',
      plural: 'Kişiler',
      hint: 'Firma yetkililerini ve bireysel kişileri yönet.',
      columns: [
        { key: 'full_name', label: 'Ad Soyad', strong: true },
        { key: 'title', label: 'Ünvan' },
        { key: 'company_name', label: 'Firma' },
        { key: 'phone', label: 'Telefon' },
        { key: 'email', label: 'E-posta' }
      ],
      fields: [
        { k: 'full_name', label: 'Ad Soyad *', req: true },
        { k: 'title', label: 'Ünvan' },
        { k: 'company_id', label: 'Firma', type: 'company' },
        { k: 'phone', label: 'Telefon' },
        { k: 'email', label: 'E-posta' },
        { k: 'note', label: 'Kısa not' }
      ],
      hasDetail: false
    },
    leads: {
      endpoint: 'api/crm_leads.php',
      title: 'Lead',
      plural: 'Lead\'ler',
      hint: 'Potansiyel müşteri adaylarını takip et; nitelikli olanları fırsata çevir.',
      filter: { param: 'status', options: [['', 'Tüm durumlar'], ['new', 'Yeni'], ['contacted', 'İletişim kuruldu'], ['qualified', 'Nitelikli'], ['won', 'Kazanıldı'], ['lost', 'Kaybedildi']] },
      columns: [
        { key: 'title', label: 'Başlık', strong: true },
        { key: 'company_name', label: 'Firma' },
        { key: 'contact_name', label: 'Kişi' },
        { key: 'status', label: 'Durum', badge: 'lead' },
        { key: 'est_value', label: 'Değer', money: true },
        { key: 'owner_name', label: 'Sahip' }
      ],
      fields: [
        { k: 'title', label: 'Başlık *', req: true },
        { k: 'company_id', label: 'Firma', type: 'company' },
        { k: 'contact_name', label: 'Kişi adı' },
        { k: 'phone', label: 'Telefon' },
        { k: 'email', label: 'E-posta' },
        { k: 'status', label: 'Durum', type: 'select', options: [['new', 'Yeni'], ['contacted', 'İletişim kuruldu'], ['qualified', 'Nitelikli'], ['won', 'Kazanıldı'], ['lost', 'Kaybedildi']] },
        { k: 'source', label: 'Kaynak' },
        { k: 'est_value', label: 'Tahmini değer (TL)', type: 'number' },
        { k: 'note', label: 'Not', textarea: true }
      ],
      rowActions: [{ label: 'Fırsata çevir', action: 'convert', cls: 'ghost', confirm: 'Bu lead bir fırsata çevrilsin mi?', done: 'Fırsata çevrildi', when: function (r) { return r.status !== 'won' && r.status !== 'lost'; } }],
      hasDetail: false
    },
    opportunities: {
      endpoint: 'api/crm_opportunities.php',
      title: 'Fırsat',
      plural: 'Fırsatlar',
      hint: 'Satış hunisindeki fırsatları aşamalarıyla ve tutarlarıyla yönet.',
      filter: { param: 'stage', options: [['', 'Tüm aşamalar'], ['new', 'Yeni'], ['qualified', 'Nitelikli'], ['proposal', 'Teklif'], ['won', 'Kazanıldı'], ['lost', 'Kaybedildi']] },
      columns: [
        { key: 'title', label: 'Başlık', strong: true },
        { key: 'company_name', label: 'Firma' },
        { key: 'stage', label: 'Aşama', badge: 'stage' },
        { key: 'amount', label: 'Tutar', money: true },
        { key: 'probability', label: 'Olasılık', pct: true },
        { key: 'owner_name', label: 'Sahip' }
      ],
      fields: [
        { k: 'title', label: 'Başlık *', req: true },
        { k: 'company_id', label: 'Firma', type: 'company' },
        { k: 'stage', label: 'Aşama', type: 'select', options: [['new', 'Yeni'], ['qualified', 'Nitelikli'], ['proposal', 'Teklif'], ['won', 'Kazanıldı'], ['lost', 'Kaybedildi']] },
        { k: 'amount', label: 'Tutar (TL)', type: 'number' },
        { k: 'probability', label: 'Olasılık (%)', type: 'number' },
        { k: 'expected_close_date', label: 'Beklenen kapanış', type: 'date' },
        { k: 'note', label: 'Not', textarea: true }
      ],
      hasDetail: false
    },
    tasks: {
      endpoint: 'api/crm_tasks.php',
      title: 'Görev',
      plural: 'Görevler',
      hint: 'Yapılacakları ve hatırlatmaları takip et; tamamlandıkça işaretle.',
      filter: { param: 'status', options: [['', 'Tümü'], ['open', 'Açık'], ['done', 'Tamamlanan']] },
      columns: [
        { key: 'title', label: 'Görev', strong: true },
        { key: 'due_at', label: 'Termin', datetime: true },
        { key: 'priority', label: 'Öncelik', badge: 'priority' },
        { key: 'status', label: 'Durum', badge: 'task' },
        { key: 'assigned_name', label: 'Atanan' }
      ],
      fields: [
        { k: 'title', label: 'Başlık *', req: true },
        { k: 'assigned_user_id', label: 'Atanan kişi', type: 'user' },
        { k: 'priority', label: 'Öncelik', type: 'select', options: [['low', 'Düşük'], ['normal', 'Normal'], ['high', 'Yüksek']] },
        { k: 'status', label: 'Durum', type: 'select', options: [['open', 'Açık'], ['done', 'Tamamlandı']] },
        { k: 'due_at', label: 'Termin (tarih-saat)', type: 'datetime' },
        { k: 'remind_at', label: 'Hatırlatma (tarih-saat)', type: 'datetime' },
        { k: 'related_company_id', label: 'İlgili firma', type: 'company' },
        { k: 'description', label: 'Açıklama', textarea: true }
      ],
      rowActions: [{ label: 'Tamamla', action: 'complete', cls: 'ghost', done: 'Görev tamamlandı', when: function (r) { return r.status === 'open'; } }],
      hasDetail: false
    }
  };

  var cfg = CONFIGS[view] || CONFIGS.companies;
  var companyOptions = null; // firma seçenekleri (lazy)
  var userOptions = null;    // kullanıcı seçenekleri (lazy)

  var BADGES = {
    lead: { new: ['Yeni', 'muted'], contacted: ['İletişim', 'accent'], qualified: ['Nitelikli', 'accent'], won: ['Kazanıldı', 'ok'], lost: ['Kaybedildi', 'bad'] },
    stage: { new: ['Yeni', 'muted'], qualified: ['Nitelikli', 'accent'], proposal: ['Teklif', 'warn'], won: ['Kazanıldı', 'ok'], lost: ['Kaybedildi', 'bad'] },
    task: { open: ['Açık', 'accent'], done: ['Tamamlandı', 'ok'] },
    priority: { low: ['Düşük', 'muted'], normal: ['Normal', 'accent'], high: ['Yüksek', 'bad'] }
  };
  function fmtDateTime(v) { return v ? String(v).replace('T', ' ').substring(0, 16) : ''; }
  function ensureUsers(cb) {
    if (userOptions) { cb(); return; }
    j('api/crm_tasks.php?action=users').then(function (r) {
      userOptions = (r && r.ok && r.data) ? r.data : [];
      cb();
    }).catch(function () { userOptions = []; cb(); });
  }
  function fillUserOptions(sel) {
    (userOptions || []).forEach(function (u) {
      var o = document.createElement('option');
      o.value = u.id;
      o.textContent = u.full_name || u.username || ('#' + u.id);
      sel.appendChild(o);
    });
  }
  function badge(kind, val) {
    var m = (BADGES[kind] || {})[val] || [val || '—', 'muted'];
    var s = document.createElement('span');
    s.className = 'crmBadge crmBadge-' + m[1];
    s.textContent = m[0];
    return s;
  }
  function money(v) {
    if (v == null || v === '') return '—';
    var num = Number(v);
    if (isNaN(num)) return '—';
    return '₺' + num.toLocaleString('tr-TR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
  }

  /* ---- küçük DOM yardımcıları ---- */
  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  function clear(n) { while (n.firstChild) n.removeChild(n.firstChild); }
  function j(url, opts) {
    return fetch(url, opts).then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Geçersiz yanıt.' }; }); });
  }
  function toast(msg) {
    var t = document.getElementById('copyToast');
    if (!t) { return; }
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(function () { t.classList.remove('show'); }, 1800);
  }

  /* ---- iskelet ---- */
  var head = el('div', 'pageHead crmHead');
  var htxt = el('div');
  htxt.appendChild(el('h1', null, cfg.plural));
  htxt.appendChild(el('p', null, cfg.hint));
  var newBtn = el('button', 'primary', '+ Yeni ' + cfg.title.toLowerCase());
  newBtn.type = 'button';
  head.appendChild(htxt);
  head.appendChild(newBtn);

  var toolbar = el('div', 'crmToolbar');
  var searchWrap = el('div', 'searchLine');
  var search = el('input');
  search.type = 'text';
  search.placeholder = cfg.plural + ' içinde ara…';
  var searchBtn = el('button', 'ghost', 'Ara');
  searchBtn.type = 'button';
  searchWrap.appendChild(search);
  searchWrap.appendChild(searchBtn);
  toolbar.appendChild(searchWrap);

  var filterSel = null;
  if (cfg.filter) {
    filterSel = el('select', 'crmFilter');
    cfg.filter.options.forEach(function (o) {
      var op = el('option', null, o[1]);
      op.value = o[0];
      filterSel.appendChild(op);
    });
    filterSel.onchange = function () { loadList(); };
    searchWrap.appendChild(filterSel);
  }

  var formBox = el('div', 'customerBox hide');
  var errBox = el('div', 'error');
  var listWrap = el('div', 'tableWrap');
  var table = el('table');
  var thead = el('thead');
  var htr = el('tr');
  cfg.columns.forEach(function (c) { htr.appendChild(el('th', null, c.label)); });
  htr.appendChild(el('th', null, ''));
  thead.appendChild(htr);
  var tbody = el('tbody');
  table.appendChild(thead);
  table.appendChild(tbody);
  listWrap.appendChild(table);

  var detail = el('div', 'crmDetail hide');

  clear(root);
  root.appendChild(head);
  root.appendChild(toolbar);
  root.appendChild(formBox);
  root.appendChild(errBox);
  root.appendChild(listWrap);
  root.appendChild(detail);

  /* ---- form kurulumu ---- */
  var inputs = {};
  var editingId = 0;

  function buildForm() {
    clear(formBox);
    inputs = {};
    var mode = el('div', 'customerFormMode');
    formBox.appendChild(mode);
    formBox._mode = mode;

    var grid = el('div', 'grid');
    cfg.fields.forEach(function (f) {
      var cell = el('div');
      cell.appendChild(el('label', null, f.label));
      var inp;
      if (f.textarea) {
        inp = el('textarea');
      } else if (f.type === 'company') {
        inp = el('select');
        var opt0 = el('option', null, '— Firma seçilmedi —');
        opt0.value = '';
        inp.appendChild(opt0);
        if (companyOptions) fillCompanyOptions(inp);
      } else if (f.type === 'select') {
        inp = el('select');
        (f.options || []).forEach(function (o) {
          var op = el('option', null, o[1]);
          op.value = o[0];
          inp.appendChild(op);
        });
      } else if (f.type === 'user') {
        inp = el('select');
        var uo0 = el('option', null, '— Atanmadı —');
        uo0.value = '';
        inp.appendChild(uo0);
        if (userOptions) fillUserOptions(inp);
      } else if (f.type === 'number') {
        inp = el('input');
        inp.type = 'text';
        inp.inputMode = 'decimal';
      } else if (f.type === 'date') {
        inp = el('input');
        inp.type = 'date';
      } else if (f.type === 'datetime') {
        inp = el('input');
        inp.type = 'datetime-local';
      } else {
        inp = el('input');
        inp.type = 'text';
      }
      inp.autocomplete = 'off';
      inputs[f.k] = inp;
      cell.appendChild(inp);
      if (f.textarea) { cell.style.gridColumn = '1 / -1'; }
      grid.appendChild(cell);
    });
    formBox.appendChild(grid);

    var acts = el('div', 'actions');
    var cancel = el('button', 'ghost', 'Vazgeç');
    cancel.type = 'button';
    cancel.onclick = closeForm;
    var save = el('button', 'primary', 'Kaydet');
    save.type = 'button';
    save.onclick = submitForm;
    acts.appendChild(cancel);
    acts.appendChild(save);
    formBox.appendChild(acts);
  }

  function fillCompanyOptions(sel) {
    (companyOptions || []).forEach(function (c) {
      var o = el('option', null, c.name);
      o.value = c.id;
      sel.appendChild(o);
    });
  }

  function ensureCompanyOptions(cb) {
    if (companyOptions) { cb(); return; }
    j('api/crm_companies.php?action=list').then(function (r) {
      companyOptions = (r && r.ok && r.data) ? r.data : [];
      cb();
    }).catch(function () { companyOptions = []; cb(); });
  }

  function openForm(row) {
    editingId = row ? (row.id | 0) : 0;
    var render = function () {
      buildForm();
      formBox._mode.textContent = editingId ? (cfg.title + ' düzenleniyor') : ('Yeni ' + cfg.title.toLowerCase());
      cfg.fields.forEach(function (f) {
        var v = row ? (row[f.k] != null ? row[f.k] : '') : '';
        if (f.type === 'datetime' && v) { v = fmtDateTime(v).replace(' ', 'T'); }
        inputs[f.k].value = v;
      });
      formBox.classList.remove('hide');
      detail.classList.add('hide');
      formBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      var first = cfg.fields[0];
      if (first && inputs[first.k]) inputs[first.k].focus();
    };
    var chain = render;
    if (cfg.fields.some(function (f) { return f.type === 'company'; })) {
      var afterCompany = chain;
      chain = function () { ensureCompanyOptions(afterCompany); };
    }
    if (cfg.fields.some(function (f) { return f.type === 'user'; })) {
      var afterUser = chain;
      chain = function () { ensureUsers(afterUser); };
    }
    chain();
  }

  function closeForm() {
    formBox.classList.add('hide');
    errBox.textContent = '';
    editingId = 0;
  }

  function submitForm() {
    errBox.textContent = '';
    var payload = { action: 'save', id: editingId };
    cfg.fields.forEach(function (f) { payload[f.k] = inputs[f.k] ? inputs[f.k].value : ''; });
    var reqField = cfg.fields.find(function (f) { return f.req; });
    if (reqField && !String(payload[reqField.k] || '').trim()) {
      errBox.textContent = reqField.label.replace(' *', '') + ' zorunlu.';
      return;
    }
    j(cfg.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function (r) {
      if (r && r.ok) {
        toast(r.message || 'Kaydedildi');
        closeForm();
        companyOptions = null; // firma listesi değişmiş olabilir
        loadList();
      } else {
        errBox.textContent = (r && r.message) || 'Kaydedilemedi.';
      }
    }).catch(function () { errBox.textContent = 'Bağlantı hatası.'; });
  }

  /* ---- liste ---- */
  function loadList() {
    var q = encodeURIComponent(search.value.trim());
    var url = cfg.endpoint + '?action=list&q=' + q;
    if (cfg.filter && filterSel && filterSel.value) {
      url += '&' + encodeURIComponent(cfg.filter.param) + '=' + encodeURIComponent(filterSel.value);
    }
    clear(tbody);
    tbody.appendChild(rowMsg('Yükleniyor…'));
    j(url).then(function (r) {
      clear(tbody);
      if (!r || !r.ok) { tbody.appendChild(rowMsg((r && r.message) || 'Liste alınamadı.')); return; }
      if (!r.data.length) { tbody.appendChild(rowMsg('Kayıt yok. “+ Yeni ' + cfg.title.toLowerCase() + '” ile ekle.')); return; }
      r.data.forEach(function (row) { tbody.appendChild(buildRow(row)); });
    }).catch(function () { clear(tbody); tbody.appendChild(rowMsg('Bağlantı hatası.')); });
  }

  function rowMsg(msg) {
    var tr = el('tr');
    var td = el('td', null, msg);
    td.colSpan = cfg.columns.length + 1;
    td.style.color = 'var(--muted)';
    tr.appendChild(td);
    return tr;
  }

  function buildRow(row) {
    var tr = el('tr');
    cfg.columns.forEach(function (c) {
      var td = el('td');
      var val = row[c.key];
      if (c.badge) { td.appendChild(badge(c.badge, val || '')); }
      else if (c.datetime) {
        td.textContent = fmtDateTime(val) || '—';
        if (row.overdue == 1 || row.overdue === true) { td.style.color = 'var(--bad)'; td.style.fontWeight = '600'; }
      }
      else if (c.money) { td.textContent = money(val); td.style.fontVariantNumeric = 'tabular-nums'; }
      else if (c.pct) { td.textContent = (val == null || val === '') ? '—' : (val + '%'); td.style.fontVariantNumeric = 'tabular-nums'; }
      else if (c.num) { td.textContent = val != null ? val : '0'; }
      else if (c.detail && cfg.hasDetail) {
        var a = el('a', null, val || '—');
        a.href = '#';
        a.style.color = 'var(--accent-ink)';
        a.style.fontWeight = '600';
        a.style.textDecoration = 'none';
        a.onclick = function (e) { e.preventDefault(); openDetail(row.id); };
        td.appendChild(a);
      } else if (c.strong) {
        var b = el('b', null, val || '—');
        td.appendChild(b);
      } else {
        td.textContent = val != null && val !== '' ? val : '—';
      }
      tr.appendChild(td);
    });
    var actTd = el('td');
    actTd.style.whiteSpace = 'nowrap';
    (cfg.rowActions || []).forEach(function (ra) {
      if (ra.when && !ra.when(row)) return;
      var rb = el('button', ra.cls || 'ghost', ra.label);
      rb.type = 'button';
      rb.style.padding = '7px 12px';
      rb.style.marginRight = '6px';
      rb.onclick = function () { doRowAction(ra, row); };
      actTd.appendChild(rb);
    });
    var edit = el('button', 'ghost', 'Düzenle');
    edit.type = 'button';
    edit.style.padding = '7px 12px';
    edit.onclick = function () { openForm(row); };
    var del = el('button', 'ghost dangerBtn', 'Sil');
    del.type = 'button';
    del.style.padding = '7px 12px';
    del.style.marginLeft = '6px';
    del.onclick = function () { removeRow(row); };
    actTd.appendChild(edit);
    actTd.appendChild(del);
    tr.appendChild(actTd);
    return tr;
  }

  function doRowAction(ra, row) {
    if (ra.confirm && !window.confirm(ra.confirm)) return;
    j(cfg.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: ra.action, id: row.id })
    }).then(function (r) {
      if (r && r.ok) { toast(r.message || ra.done || 'Tamam'); loadList(); }
      else { alert((r && r.message) || 'İşlem başarısız.'); }
    }).catch(function () { alert('Bağlantı hatası.'); });
  }

  function removeRow(row) {
    var name = row.name || row.full_name || 'kayıt';
    if (!window.confirm('“' + name + '” silinsin mi? Bu işlem geri alınamaz.')) return;
    j(cfg.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'delete', id: row.id })
    }).then(function (r) {
      if (r && r.ok) { toast(r.message || 'Silindi'); companyOptions = null; loadList(); }
      else { alert((r && r.message) || 'Silinemedi.'); }
    }).catch(function () { alert('Bağlantı hatası.'); });
  }

  /* ---- firma detayı ---- */
  function openDetail(id) {
    detail.classList.remove('hide');
    clear(detail);
    detail.appendChild(el('div', 'labelHint', 'Yükleniyor…'));
    j('api/crm_companies.php?action=get&id=' + encodeURIComponent(id)).then(function (r) {
      clear(detail);
      if (!r || !r.ok) { detail.appendChild(el('div', 'error', (r && r.message) || 'Detay alınamadı.')); return; }
      var d = r.data, co = d.company;
      var card = el('div', 'crmDetailCard');

      var top = el('div', 'crmDetailHead');
      var ti = el('div');
      ti.appendChild(el('h3', null, co.name));
      var meta = [];
      if (co.phone) meta.push('Tel: ' + co.phone);
      if (co.email) meta.push(co.email);
      if (co.city) meta.push(co.city + (co.county ? ' / ' + co.county : ''));
      if (co.tax_no) meta.push('VN: ' + co.tax_no);
      ti.appendChild(el('p', null, meta.join('  ·  ') || '—'));
      var headActs = el('div', 'crmDetailActs');
      var addCust = el('button', 'ghost', '+ Müşteri listesine ekle');
      addCust.type = 'button';
      addCust.onclick = function () {
        j('api/crm_companies.php', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'add_to_customers', id: co.id })
        }).then(function (r) { if (r && r.ok) toast(r.message || 'Eklendi'); else alert((r && r.message) || 'Eklenemedi.'); });
      };
      var close = el('button', 'ghost', 'Kapat');
      close.type = 'button';
      close.onclick = function () { detail.classList.add('hide'); };
      headActs.appendChild(addCust);
      headActs.appendChild(close);
      top.appendChild(ti);
      top.appendChild(headActs);
      card.appendChild(top);

      if (co.address) {
        var addr = el('div', 'crmDetailAddr', co.address);
        card.appendChild(addr);
      }

      // kişiler
      card.appendChild(el('div', 'crmDetailLabel', 'Kişiler (' + d.contacts.length + ')'));
      if (!d.contacts.length) {
        card.appendChild(el('div', 'labelHint', 'Bu firmaya bağlı kişi yok.'));
      } else {
        var cl = el('div', 'crmContactList');
        d.contacts.forEach(function (ct) {
          var ci = el('div', 'crmContactItem');
          ci.appendChild(el('b', null, ct.full_name));
          var sub = [ct.title, ct.phone, ct.email].filter(Boolean).join(' · ');
          ci.appendChild(el('span', null, sub || '—'));
          cl.appendChild(ci);
        });
        card.appendChild(cl);
      }

      // aktivite
      card.appendChild(el('div', 'crmDetailLabel', 'Geçmiş'));
      if (!d.activities.length) {
        card.appendChild(el('div', 'labelHint', 'Henüz kayıt yok.'));
      } else {
        var al = el('div', 'actFeed');
        d.activities.forEach(function (a) {
          var it = el('div', 'actItem');
          var dot = el('div', 'actDot');
          dot.setAttribute('data-type', a.type || '');
          var bd = el('div', 'actBody');
          bd.appendChild(el('b', null, a.subject || a.type || 'Kayıt'));
          if (a.body) bd.appendChild(el('span', null, a.body));
          bd.appendChild(el('small', null, [a.user_name, a.occurred_at].filter(Boolean).join(' · ')));
          it.appendChild(dot);
          it.appendChild(bd);
          al.appendChild(it);
        });
        card.appendChild(al);
      }

      // Kargo gönderileri
      card.appendChild(el('div', 'crmDetailLabel', 'Kargo gönderileri (' + ((d.shipments && d.shipments.length) || 0) + ')'));
      if (!d.shipments || !d.shipments.length) {
        card.appendChild(el('div', 'labelHint', 'Bu firmaya bağlı ya da adı/telefonu eşleşen kargo gönderisi yok.'));
      } else {
        var sl = el('div', 'crmContactList');
        d.shipments.forEach(function (sh) {
          var si = el('div', 'crmContactItem');
          si.appendChild(el('b', null, (sh.recipient || sh.company_name || '—') + ' · ' + (sh.invoice_ref || '')));
          var meta = [sh.carrier_label || sh.carrier, (sh.city || ''), (sh.paper || ''), (sh.created_at || '').substring(0, 10)].filter(Boolean).join(' · ');
          si.appendChild(el('span', null, meta));
          sl.appendChild(si);
        });
        card.appendChild(sl);
      }

      // Not ekle
      card.appendChild(el('div', 'crmDetailLabel', 'Not ekle'));
      var noteWrap = el('div', 'crmNoteBox');
      var ta = el('textarea');
      ta.placeholder = 'Bu firmayla ilgili bir not yaz…';
      var nb = el('button', 'primary', 'Not ekle');
      nb.type = 'button';
      nb.onclick = function () {
        var body = ta.value.trim();
        if (!body) return;
        j('api/crm_notes.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'add', related_type: 'company', related_id: co.id, body: body })
        }).then(function (r) {
          if (r && r.ok) { toast('Not eklendi'); openDetail(co.id); }
          else { alert((r && r.message) || 'Not eklenemedi.'); }
        }).catch(function () { alert('Bağlantı hatası.'); });
      };
      noteWrap.appendChild(ta);
      noteWrap.appendChild(nb);
      card.appendChild(noteWrap);

      detail.appendChild(card);
      detail.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }).catch(function () { clear(detail); detail.appendChild(el('div', 'error', 'Bağlantı hatası.')); });
  }

  /* ---- olaylar ---- */
  newBtn.onclick = function () { openForm(null); };
  searchBtn.onclick = loadList;
  search.addEventListener('keydown', function (e) { if (e.key === 'Enter') loadList(); });

  loadList();
})();
