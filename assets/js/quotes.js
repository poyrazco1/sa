/**
 * Teklif modülü ön yüzü (başlık + kalemler + canlı toplam).
 * app.js'ten bağımsız; CSRF csrf.js ile. DOM textContent ile XSS güvenli.
 * T-Soft köprüsü: mevcut api.php?action=tsoft_product_search ucunu kullanır.
 */
(function () {
  'use strict';
  var root = document.getElementById('quotesRoot');
  if (!root) return;

  var ENDPOINT = 'api/crm_quotes.php';
  var companyOptions = null;

  var STATUS = { draft: ['Taslak', 'muted'], sent: ['Gönderildi', 'accent'], accepted: ['Kabul', 'ok'], rejected: ['Red', 'bad'] };
  var STATUS_OPTS = [['draft', 'Taslak'], ['sent', 'Gönderildi'], ['accepted', 'Kabul edildi'], ['rejected', 'Reddedildi']];

  function el(t, c, x) { var e = document.createElement(t); if (c) e.className = c; if (x != null) e.textContent = x; return e; }
  function clear(n) { while (n.firstChild) n.removeChild(n.firstChild); }
  function j(u, o) { return fetch(u, o).then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Geçersiz yanıt.' }; }); }); }
  function money(v) { if (v == null || v === '') return '₺0'; var n = Number(v); if (isNaN(n)) return '₺0'; return '₺' + n.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function num(v) { return (parseFloat(String(v).replace(',', '.')) || 0); }
  function toast(m) { var t = document.getElementById('copyToast'); if (!t) return; t.textContent = m; t.classList.add('show'); setTimeout(function () { t.classList.remove('show'); }, 1800); }
  function badge(s) { var m = STATUS[s] || [s, 'muted']; var e = el('span', 'crmBadge crmBadge-' + m[1], m[0]); return e; }

  /* ---- iskelet ---- */
  var head = el('div', 'pageHead crmHead');
  var htxt = el('div');
  htxt.appendChild(el('h1', null, 'Teklifler'));
  htxt.appendChild(el('p', null, 'Firma bazlı teklifler oluştur; kalemleri ve KDV dahil toplamı yönet.'));
  var newBtn = el('button', 'primary', '+ Yeni teklif');
  newBtn.type = 'button';
  head.appendChild(htxt); head.appendChild(newBtn);

  var toolbar = el('div', 'crmToolbar');
  var sl = el('div', 'searchLine');
  var search = el('input'); search.type = 'text'; search.placeholder = 'Teklif no veya firma ara…';
  var searchBtn = el('button', 'ghost', 'Ara'); searchBtn.type = 'button';
  var filter = el('select', 'crmFilter');
  [['', 'Tüm durumlar']].concat(STATUS_OPTS).forEach(function (o) { var op = el('option', null, o[1]); op.value = o[0]; filter.appendChild(op); });
  filter.onchange = loadList;
  sl.appendChild(search); sl.appendChild(searchBtn); sl.appendChild(filter);
  toolbar.appendChild(sl);

  var editor = el('div', 'customerBox hide');
  var listWrap = el('div', 'tableWrap');
  var table = el('table');
  var thead = el('thead');
  var htr = el('tr');
  ['Teklif No', 'Firma', 'Durum', 'Kalem', 'Tutar', 'Tarih', ''].forEach(function (h) { htr.appendChild(el('th', null, h)); });
  thead.appendChild(htr);
  var tbody = el('tbody');
  table.appendChild(thead); table.appendChild(tbody);
  listWrap.appendChild(table);

  clear(root);
  root.appendChild(head); root.appendChild(toolbar); root.appendChild(editor); root.appendChild(listWrap);

  /* ---- liste ---- */
  function rowMsg(m) { var tr = el('tr'); var td = el('td', null, m); td.colSpan = 7; td.style.color = 'var(--muted)'; tr.appendChild(td); return tr; }

  function loadList() {
    var url = ENDPOINT + '?action=list&q=' + encodeURIComponent(search.value.trim());
    if (filter.value) url += '&status=' + encodeURIComponent(filter.value);
    clear(tbody); tbody.appendChild(rowMsg('Yükleniyor…'));
    j(url).then(function (r) {
      clear(tbody);
      if (!r || !r.ok) { tbody.appendChild(rowMsg((r && r.message) || 'Liste alınamadı.')); return; }
      if (!r.data.length) { tbody.appendChild(rowMsg('Teklif yok. “+ Yeni teklif” ile oluştur.')); return; }
      r.data.forEach(function (q) { tbody.appendChild(listRow(q)); });
    }).catch(function () { clear(tbody); tbody.appendChild(rowMsg('Bağlantı hatası.')); });
  }

  function listRow(q) {
    var tr = el('tr');
    var c1 = el('td'); c1.appendChild(el('b', null, q.quote_no || '—')); tr.appendChild(c1);
    tr.appendChild(el('td', null, q.company_name || '—'));
    var c3 = el('td'); c3.appendChild(badge(q.status)); tr.appendChild(c3);
    var c4 = el('td', null, q.item_count || '0'); c4.style.fontVariantNumeric = 'tabular-nums'; tr.appendChild(c4);
    var c5 = el('td', null, money(q.grand_total)); c5.style.fontVariantNumeric = 'tabular-nums'; tr.appendChild(c5);
    tr.appendChild(el('td', null, (q.created_at || '').substring(0, 10)));
    var act = el('td'); act.style.whiteSpace = 'nowrap';
    var ed = el('button', 'ghost', 'Düzenle'); ed.type = 'button'; ed.style.padding = '7px 12px'; ed.onclick = function () { openEditor(q.id); };
    var del = el('button', 'ghost dangerBtn', 'Sil'); del.type = 'button'; del.style.padding = '7px 12px'; del.style.marginLeft = '6px';
    del.onclick = function () { if (window.confirm('“' + (q.quote_no || '') + '” silinsin mi?')) { j(ENDPOINT, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete', id: q.id }) }).then(function (r) { if (r && r.ok) { toast('Silindi'); loadList(); } else alert((r && r.message) || 'Silinemedi.'); }); } };
    act.appendChild(ed); act.appendChild(del); tr.appendChild(act);
    return tr;
  }

  /* ---- editör ---- */
  var editingId = 0;
  var itemRows = []; // {tr, name, code, qty, unit, vat, lineCell}
  var totalsEls = {};

  function ensureCompanies(cb) {
    if (companyOptions) { cb(); return; }
    j('api/crm_companies.php?action=list').then(function (r) { companyOptions = (r && r.ok && r.data) ? r.data : []; cb(); }).catch(function () { companyOptions = []; cb(); });
  }

  function fieldCell(labelText, inputEl) { var d = el('div'); d.appendChild(el('label', null, labelText)); d.appendChild(inputEl); return d; }

  var hdr = {};
  function buildEditor(quote, items) {
    clear(editor);
    editingId = quote ? (quote.id | 0) : 0;
    itemRows = [];
    editor.appendChild(el('div', 'customerFormMode', editingId ? ('Teklif düzenleniyor' + (quote.quote_no ? ' · ' + quote.quote_no : '')) : 'Yeni teklif'));

    // başlık alanları
    var grid = el('div', 'grid');
    var companySel = el('select');
    var o0 = el('option', null, '— Firma seçilmedi —'); o0.value = ''; companySel.appendChild(o0);
    (companyOptions || []).forEach(function (c) { var op = el('option', null, c.name); op.value = c.id; companySel.appendChild(op); });
    var statusSel = el('select'); STATUS_OPTS.forEach(function (o) { var op = el('option', null, o[1]); op.value = o[0]; statusSel.appendChild(op); });
    var curSel = el('select'); ['TL', 'USD', 'EUR'].forEach(function (c) { var op = el('option', null, c); op.value = c; curSel.appendChild(op); });
    var validInp = el('input'); validInp.type = 'date';
    hdr = { company: companySel, status: statusSel, currency: curSel, valid: validInp };
    grid.appendChild(fieldCell('Firma', companySel));
    grid.appendChild(fieldCell('Durum', statusSel));
    grid.appendChild(fieldCell('Para birimi', curSel));
    grid.appendChild(fieldCell('Geçerlilik tarihi', validInp));
    editor.appendChild(grid);

    if (quote) {
      companySel.value = quote.company_id || '';
      statusSel.value = quote.status || 'draft';
      curSel.value = quote.currency || 'TL';
      validInp.value = (quote.valid_until || '').substring(0, 10);
    }

    // kalemler tablosu
    editor.appendChild(el('div', 'crmDetailLabel', 'Kalemler'));
    var itScroll = el('div', 'tableWrap');
    var itTable = el('table', 'quoteItems');
    var ith = el('thead'); var ithr = el('tr');
    ['Ürün / açıklama', 'Adet', 'Birim fiyat', 'KDV %', 'Satır toplamı', ''].forEach(function (h) { ithr.appendChild(el('th', null, h)); });
    ith.appendChild(ithr);
    var itBody = el('tbody');
    itTable.appendChild(ith); itTable.appendChild(itBody);
    itScroll.appendChild(itTable);
    editor.appendChild(itScroll);
    editor._itBody = itBody;

    (items && items.length ? items : [null]).forEach(function (it) { addItemRow(it); });

    // kalem ekleme + T-Soft
    var addBar = el('div', 'quoteAddBar');
    var addBtn = el('button', 'ghost', '+ Satır ekle'); addBtn.type = 'button'; addBtn.onclick = function () { addItemRow(null); };
    addBar.appendChild(addBtn);
    var tsInp = el('input'); tsInp.type = 'search'; tsInp.placeholder = 'T-Soft ürün ara (ad / kod / barkod)…'; tsInp.className = 'quoteTsInput';
    var tsBtn = el('button', 'ghost', 'T-Soft ara'); tsBtn.type = 'button'; tsBtn.onclick = function () { tsoftSearch(tsInp.value); };
    tsInp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); tsoftSearch(tsInp.value); } });
    addBar.appendChild(tsInp); addBar.appendChild(tsBtn);
    editor.appendChild(addBar);
    var tsResults = el('div', 'quoteTsResults'); editor.appendChild(tsResults); editor._tsResults = tsResults;

    // toplamlar
    var totals = el('div', 'quoteTotals');
    totalsEls = {};
    [['Ara toplam (KDV hariç)', 'sub'], ['KDV', 'vat'], ['Genel toplam', 'grand']].forEach(function (t) {
      var r = el('div', 'quoteTotalRow' + (t[1] === 'grand' ? ' grand' : ''));
      r.appendChild(el('span', null, t[0]));
      var b = el('b', null, '₺0'); totalsEls[t[1]] = b; r.appendChild(b);
      totals.appendChild(r);
    });
    editor.appendChild(totals);

    var errBox = el('div', 'error'); editor.appendChild(errBox); editor._err = errBox;

    var acts = el('div', 'actions');
    var cancel = el('button', 'ghost', 'Vazgeç'); cancel.type = 'button'; cancel.onclick = function () { editor.classList.add('hide'); };
    var save = el('button', 'primary', 'Teklifi kaydet'); save.type = 'button'; save.onclick = submitQuote;
    acts.appendChild(cancel); acts.appendChild(save);
    editor.appendChild(acts);

    recalc();
    editor.classList.remove('hide');
    editor.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function addItemRow(it) {
    var tr = el('tr');
    var name = el('input'); name.type = 'text'; name.placeholder = 'Ürün adı'; name.value = it ? (it.product_name || '') : '';
    var code = null; // kod satır verisinde saklanır (gizli)
    var qty = el('input'); qty.type = 'text'; qty.inputMode = 'decimal'; qty.value = it ? (it.qty != null ? it.qty : 1) : 1; qty.style.textAlign = 'right';
    var unit = el('input'); unit.type = 'text'; unit.inputMode = 'decimal'; unit.value = it ? (it.unit_price != null ? it.unit_price : '') : ''; unit.style.textAlign = 'right';
    var vat = el('input'); vat.type = 'text'; vat.inputMode = 'decimal';
    vat.value = it ? String(Math.round((it.vat_rate != null ? it.vat_rate : 0.20) * 100)) : '20'; vat.style.textAlign = 'right';
    var lineCell = el('td', null, '₺0'); lineCell.style.textAlign = 'right'; lineCell.style.fontVariantNumeric = 'tabular-nums';
    var rowObj = { tr: tr, name: name, wsCode: it ? (it.ws_product_code || '') : '', qty: qty, unit: unit, vat: vat, lineCell: lineCell };
    [qty, unit, vat].forEach(function (i) { i.addEventListener('input', recalc); });

    var tdName = el('td'); tdName.appendChild(name);
    var tdQty = el('td'); tdQty.appendChild(qty);
    var tdUnit = el('td'); tdUnit.appendChild(unit);
    var tdVat = el('td'); tdVat.appendChild(vat);
    var tdDel = el('td');
    var del = el('button', 'ghost dangerBtn', '×'); del.type = 'button'; del.style.padding = '6px 10px';
    del.onclick = function () { tr.parentNode.removeChild(tr); itemRows = itemRows.filter(function (r) { return r !== rowObj; }); recalc(); };
    tdDel.appendChild(del);
    tr.appendChild(tdName); tr.appendChild(tdQty); tr.appendChild(tdUnit); tr.appendChild(tdVat); tr.appendChild(lineCell); tr.appendChild(tdDel);
    editor._itBody.appendChild(tr);
    itemRows.push(rowObj);
  }

  function recalc() {
    var sub = 0, vatT = 0;
    itemRows.forEach(function (r) {
      var line = num(r.qty.value) * num(r.unit.value);
      var vr = num(r.vat.value); if (vr > 1) vr = vr / 100;
      r.lineCell.textContent = money(line);
      sub += line; vatT += line * vr;
    });
    if (totalsEls.sub) totalsEls.sub.textContent = money(sub);
    if (totalsEls.vat) totalsEls.vat.textContent = money(vatT);
    if (totalsEls.grand) totalsEls.grand.textContent = money(sub + vatT);
  }

  function tsoftSearch(q) {
    q = (q || '').trim();
    var box = editor._tsResults; clear(box);
    if (q.length < 2) { box.appendChild(el('div', 'labelHint', 'En az 2 karakter yaz.')); return; }
    box.appendChild(el('div', 'labelHint', 'Aranıyor…'));
    j('api.php?action=tsoft_product_search&q=' + encodeURIComponent(q) + '&limit=15').then(function (r) {
      clear(box);
      var list = (r && (r.data || (r.ok && r.data))) || [];
      if (!r || (!r.ok && !list.length)) { box.appendChild(el('div', 'labelHint', (r && r.message) || 'Sonuç yok.')); return; }
      if (!list.length) { box.appendChild(el('div', 'labelHint', 'Ürün bulunamadı.')); return; }
      list.forEach(function (p) {
        var card = el('div', 'quoteTsItem');
        var info = el('div');
        info.appendChild(el('b', null, p.product_name || p.ws_product_code || '—'));
        info.appendChild(el('span', null, [p.ws_product_code, p.currency_code, (p.sale_price != null ? p.sale_price : '')].filter(Boolean).join(' · ')));
        var add = el('button', 'ghost', 'Ekle'); add.type = 'button';
        add.onclick = function () {
          addItemRow({ product_name: p.product_name || '', ws_product_code: p.ws_product_code || '', qty: 1, unit_price: (p.sale_price != null ? p.sale_price : 0), vat_rate: 0.20 });
          recalc(); toast('Ürün eklendi');
        };
        card.appendChild(info); card.appendChild(add);
        box.appendChild(card);
      });
    }).catch(function () { clear(box); box.appendChild(el('div', 'labelHint', 'T-Soft bağlantı hatası.')); });
  }

  function submitQuote() {
    var items = itemRows.map(function (r) {
      return { product_name: r.name.value, ws_product_code: r.wsCode, qty: r.qty.value, unit_price: r.unit.value, vat_rate: r.vat.value };
    }).filter(function (i) { return String(i.product_name).trim() !== ''; });
    if (!items.length) { editor._err.textContent = 'En az bir ürün satırı gerekli.'; return; }
    editor._err.textContent = '';
    var payload = {
      action: 'save', id: editingId,
      company_id: hdr.company.value || 0,
      status: hdr.status.value, currency: hdr.currency.value,
      valid_until: hdr.valid.value, items: items
    };
    j(ENDPOINT, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) }).then(function (r) {
      if (r && r.ok) { toast(r.message || 'Kaydedildi'); editor.classList.add('hide'); loadList(); }
      else { editor._err.textContent = (r && r.message) || 'Kaydedilemedi.'; }
    }).catch(function () { editor._err.textContent = 'Bağlantı hatası.'; });
  }

  function openEditor(id) {
    ensureCompanies(function () {
      if (!id) { buildEditor(null, null); return; }
      j(ENDPOINT + '?action=get&id=' + encodeURIComponent(id)).then(function (r) {
        if (r && r.ok) buildEditor(r.data.quote, r.data.items);
        else alert((r && r.message) || 'Teklif alınamadı.');
      });
    });
  }

  newBtn.onclick = function () { openEditor(0); };
  searchBtn.onclick = loadList;
  search.addEventListener('keydown', function (e) { if (e.key === 'Enter') loadList(); });
  loadList();
})();
