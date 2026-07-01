// ==== Gömülü çevrimdışı QR üreteci (QRLite) — internet gerektirmez ====
/* QRLite — compact, dependency-free QR Code encoder (byte mode, ECC level M).
 * Based on the public QR Code spec (ISO/IEC 18004). Supports versions 1..15
 * which is far more than enough for our label payloads.
 * Exposes: QRLite.generateSVG(text) -> SVG string ; QRLite.generateMatrix(text) -> {size, modules}
 * Author: hand-written for offline use. No external deps.
 */
(function (global) {
  'use strict';

  // ---- Galois field (GF256) tables ----
  var EXP = new Array(512), LOG = new Array(256);
  (function () {
    var x = 1;
    for (var i = 0; i < 255; i++) {
      EXP[i] = x;
      LOG[x] = i;
      x <<= 1;
      if (x & 0x100) x ^= 0x11d;
    }
    for (var j = 255; j < 512; j++) EXP[j] = EXP[j - 255];
  })();
  function gmul(a, b) { if (a === 0 || b === 0) return 0; return EXP[LOG[a] + LOG[b]]; }

  // Reed-Solomon generator polynomial for `deg` ECC codewords
  function rsGenPoly(deg) {
    var poly = [1];
    for (var i = 0; i < deg; i++) {
      var next = new Array(poly.length + 1).fill(0);
      for (var j = 0; j < poly.length; j++) {
        next[j] ^= gmul(poly[j], 1);
        next[j + 1] ^= gmul(poly[j], EXP[i]);
      }
      poly = next;
    }
    return poly;
  }
  function rsEncode(data, ecLen) {
    var gen = rsGenPoly(ecLen);
    var res = new Array(ecLen).fill(0);
    for (var i = 0; i < data.length; i++) {
      var factor = data[i] ^ res[0];
      res.shift();
      res.push(0);
      for (var j = 0; j < gen.length; j++) res[j] ^= gmul(gen[j], factor);
    }
    return res;
  }

  // ---- Version capacity & ECC (level M) ----
  // For each version: [total codewords, ec codewords per block, num blocks group1, dataCW group1, num blocks group2, dataCW group2]
  // Level M tables (ISO 18004 Annex). Versions 1..15.
  var ECC_M = {
    1: [10, 1, 16, 0, 0], 2: [16, 1, 28, 0, 0], 3: [26, 1, 44, 0, 0],
    4: [18, 2, 32, 0, 0], 5: [24, 2, 43, 0, 0], 6: [16, 4, 27, 0, 0],
    7: [18, 4, 31, 0, 0], 8: [22, 2, 38, 2, 39], 9: [22, 3, 36, 2, 37],
    10: [26, 4, 43, 1, 44], 11: [30, 1, 50, 4, 51], 12: [22, 6, 36, 2, 37],
    13: [22, 8, 37, 1, 38], 14: [24, 4, 40, 5, 41], 15: [24, 5, 41, 5, 42]
  };
  // total data codewords per version (level M)
  var DATA_CW_M = {
    1: 16, 2: 28, 3: 44, 4: 64, 5: 86, 6: 108, 7: 124, 8: 154,
    9: 182, 10: 216, 11: 254, 12: 290, 13: 334, 14: 365, 15: 415
  };

  function chooseVersion(byteLen) {
    for (var v = 1; v <= 15; v++) {
      // byte-mode overhead: mode(4) + count(8 or 16) bits -> bytes vary; compute in bits later.
      var countBits = v < 10 ? 8 : 16;
      var neededBits = 4 + countBits + byteLen * 8;
      var neededBytes = Math.ceil(neededBits / 8);
      if (neededBytes <= DATA_CW_M[v]) return v;
    }
    throw new Error('QRLite: payload too large for v15');
  }

  function buildData(bytes, version) {
    var countBits = version < 10 ? 8 : 16;
    var bits = [];
    function push(val, len) { for (var i = len - 1; i >= 0; i--) bits.push((val >> i) & 1); }
    push(0x4, 4);              // byte mode
    push(bytes.length, countBits);
    for (var i = 0; i < bytes.length; i++) push(bytes[i], 8);
    var cap = DATA_CW_M[version] * 8;
    // terminator
    var term = Math.min(4, cap - bits.length);
    for (var t = 0; t < term; t++) bits.push(0);
    // pad to byte
    while (bits.length % 8 !== 0) bits.push(0);
    // pad bytes
    var pads = [0xec, 0x11], pi = 0;
    while (bits.length < cap) { push(pads[pi], 8); pi ^= 1; }
    // to codewords
    var cw = [];
    for (var b = 0; b < bits.length; b += 8) {
      var v = 0; for (var k = 0; k < 8; k++) v = (v << 1) | bits[b + k];
      cw.push(v);
    }
    return cw;
  }

  function interleave(dataCW, version) {
    var meta = ECC_M[version];
    var ecLen = meta[0];
    var blocks = [];
    var idx = 0;
    var groups = [[meta[1], meta[2]]];
    if (meta[3]) groups.push([meta[3], meta[4]]);
    groups.forEach(function (g) {
      for (var i = 0; i < g[0]; i++) {
        var d = dataCW.slice(idx, idx + g[1]); idx += g[1];
        var ec = rsEncode(d, ecLen);
        blocks.push({ d: d, ec: ec });
      }
    });
    // interleave data
    var result = [];
    var maxData = Math.max.apply(null, blocks.map(function (b) { return b.d.length; }));
    for (var c = 0; c < maxData; c++)
      for (var bl = 0; bl < blocks.length; bl++)
        if (c < blocks[bl].d.length) result.push(blocks[bl].d[c]);
    // interleave ec
    for (var e = 0; e < ecLen; e++)
      for (var b2 = 0; b2 < blocks.length; b2++)
        result.push(blocks[b2].ec[e]);
    return result;
  }

  // ---- Matrix placement ----
  function moduleSize(version) { return version * 4 + 17; }

  function makeMatrix(version) {
    var n = moduleSize(version);
    var m = [];
    for (var i = 0; i < n; i++) { m.push(new Array(n).fill(null)); }
    return m;
  }
  function placeFinder(m, r, c) {
    for (var i = -1; i <= 7; i++) for (var j = -1; j <= 7; j++) {
      var rr = r + i, cc = c + j;
      if (rr < 0 || cc < 0 || rr >= m.length || cc >= m.length) continue;
      var inCore = i >= 0 && i <= 6 && j >= 0 && j <= 6;
      var ring = (i === 0 || i === 6 || j === 0 || j === 6);
      var inner = i >= 2 && i <= 4 && j >= 2 && j <= 4;
      m[rr][cc] = inCore && (ring || inner) ? 1 : 0;
    }
  }
  var ALIGN_POS = {
    1: [], 2: [6, 18], 3: [6, 22], 4: [6, 26], 5: [6, 30], 6: [6, 34],
    7: [6, 22, 38], 8: [6, 24, 42], 9: [6, 26, 46], 10: [6, 28, 50],
    11: [6, 30, 54], 12: [6, 32, 58], 13: [6, 34, 62], 14: [6, 26, 46, 66], 15: [6, 26, 48, 70]
  };
  function placeAlign(m, version) {
    var pos = ALIGN_POS[version];
    for (var a = 0; a < pos.length; a++) for (var b = 0; b < pos.length; b++) {
      var r = pos[a], c = pos[b];
      if (m[r][c] !== null) continue;
      for (var i = -2; i <= 2; i++) for (var j = -2; j <= 2; j++) {
        var ring = Math.max(Math.abs(i), Math.abs(j));
        m[r + i][c + j] = (ring === 1) ? 0 : 1;
      }
    }
  }
  function placeTiming(m) {
    var n = m.length;
    for (var i = 8; i < n - 8; i++) {
      if (m[6][i] === null) m[6][i] = (i % 2 === 0) ? 1 : 0;
      if (m[i][6] === null) m[i][6] = (i % 2 === 0) ? 1 : 0;
    }
  }
  function reserveFormat(m) {
    var n = m.length;
    // will be filled later; just mark reserved by setting to 0 temporarily is wrong (needs skip in data placement)
    // We'll track reserved via a separate function set.
  }

  // Format info (level M = 0b00) with mask, BCH(15,5)
  function formatBits(mask) {
    var data = (0 << 3) | mask; // EC level M = 0b00
    var g = 0x537;
    var rem = data << 10;
    for (var i = 14; i >= 10; i--) if ((rem >> i) & 1) rem ^= g << (i - 10);
    var bits = ((data << 10) | rem) ^ 0x5412;
    return bits; // 15 bits
  }

  function isReserved(version, n, r, c, alignPos) {
    // finders + separators
    if ((r <= 8 && c <= 8) || (r <= 8 && c >= n - 8) || (r >= n - 8 && c <= 8)) return true;
    // timing
    if (r === 6 || c === 6) return true;
    // dark module
    if (r === n - 8 && c === 8) return true;
    // alignment
    for (var a = 0; a < alignPos.length; a++) for (var b = 0; b < alignPos.length; b++) {
      var ar = alignPos[a], ac = alignPos[b];
      // skip overlap with finders
      if ((ar <= 8 && ac <= 8) || (ar <= 8 && ac >= n - 8) || (ar >= n - 8 && ac <= 8)) continue;
      if (Math.abs(r - ar) <= 2 && Math.abs(c - ac) <= 2) return true;
    }
    return false;
  }

  function placeData(m, version, cw) {
    var n = m.length;
    var alignPos = ALIGN_POS[version];
    var bitIdx = 0;
    var totalBits = cw.length * 8;
    function bitAt(i) { return (cw[i >> 3] >> (7 - (i & 7))) & 1; }
    var col = n - 1;
    var upward = true;
    while (col > 0) {
      if (col === 6) col--; // skip timing column
      for (var i = 0; i < n; i++) {
        var row = upward ? (n - 1 - i) : i;
        for (var s = 0; s < 2; s++) {
          var cc = col - s;
          if (m[row][cc] !== null) continue;
          if (isReserved(version, n, row, cc, alignPos)) { continue; }
          var bit = bitIdx < totalBits ? bitAt(bitIdx) : 0;
          m[row][cc] = bit;
          bitIdx++;
        }
      }
      col -= 2;
      upward = !upward;
    }
  }

  function applyMaskAndFormat(m, version, mask) {
    var n = m.length;
    var alignPos = ALIGN_POS[version];
    // copy + mask data modules
    var out = m.map(function (row) { return row.slice(); });
    for (var r = 0; r < n; r++) for (var c = 0; c < n; c++) {
      if (isReserved(version, n, r, c, alignPos)) continue;
      if (out[r][c] === null) { out[r][c] = 0; }
      var mk = false;
      switch (mask) {
        case 0: mk = ((r + c) % 2 === 0); break;
        case 1: mk = (r % 2 === 0); break;
        case 2: mk = (c % 3 === 0); break;
        case 3: mk = ((r + c) % 3 === 0); break;
        case 4: mk = ((Math.floor(r / 2) + Math.floor(c / 3)) % 2 === 0); break;
        case 5: mk = (((r * c) % 2) + ((r * c) % 3) === 0); break;
        case 6: mk = ((((r * c) % 2) + ((r * c) % 3)) % 2 === 0); break;
        case 7: mk = ((((r + c) % 2) + ((r * c) % 3)) % 2 === 0); break;
      }
      if (mk) out[r][c] ^= 1;
    }
    // place format bits
    var fb = formatBits(mask);
    var fbits = [];
    for (var i = 14; i >= 0; i--) fbits.push((fb >> i) & 1);
    // around top-left
    var coords1 = [[0,8],[1,8],[2,8],[3,8],[4,8],[5,8],[7,8],[8,8],[8,7],[8,5],[8,4],[8,3],[8,2],[8,1],[8,0]];
    for (var k = 0; k < 15; k++) { out[coords1[k][0]][coords1[k][1]] = fbits[k]; }
    // top-right + bottom-left
    var coords2 = [[8,n-1],[8,n-2],[8,n-3],[8,n-4],[8,n-5],[8,n-6],[8,n-7],[8,n-8],[n-7,8],[n-6,8],[n-5,8],[n-4,8],[n-3,8],[n-2,8],[n-1,8]];
    for (var k2 = 0; k2 < 15; k2++) { out[coords2[k2][0]][coords2[k2][1]] = fbits[k2]; }
    // dark module
    out[n - 8][8] = 1;
    return out;
  }

  function penalty(m) {
    var n = m.length, p = 0;
    // rule 1: runs of >=5
    for (var r = 0; r < n; r++) {
      var run = 1;
      for (var c = 1; c < n; c++) {
        if (m[r][c] === m[r][c-1]) { run++; if (run === 5) p += 3; else if (run > 5) p++; }
        else run = 1;
      }
    }
    for (var c2 = 0; c2 < n; c2++) {
      var run2 = 1;
      for (var r2 = 1; r2 < n; r2++) {
        if (m[r2][c2] === m[r2-1][c2]) { run2++; if (run2 === 5) p += 3; else if (run2 > 5) p++; }
        else run2 = 1;
      }
    }
    // rule 3 (finder-like) approximate + rule 4 (balance)
    var dark = 0;
    for (var r3 = 0; r3 < n; r3++) for (var c3 = 0; c3 < n; c3++) if (m[r3][c3]) dark++;
    var ratio = dark / (n * n) * 100;
    p += Math.floor(Math.abs(ratio - 50) / 5) * 10;
    return p;
  }

  function generateMatrix(text) {
    var bytes = [];
    // UTF-8 encode
    for (var i = 0; i < text.length; i++) {
      var cp = text.codePointAt(i);
      if (cp > 0xffff) i++;
      if (cp < 0x80) bytes.push(cp);
      else if (cp < 0x800) { bytes.push(0xc0 | (cp >> 6), 0x80 | (cp & 0x3f)); }
      else if (cp < 0x10000) { bytes.push(0xe0 | (cp >> 12), 0x80 | ((cp >> 6) & 0x3f), 0x80 | (cp & 0x3f)); }
      else { bytes.push(0xf0 | (cp >> 18), 0x80 | ((cp >> 12) & 0x3f), 0x80 | ((cp >> 6) & 0x3f), 0x80 | (cp & 0x3f)); }
    }
    var version = chooseVersion(bytes.length);
    var dataCW = buildData(bytes, version);
    var finalCW = interleave(dataCW, version);
    var base = makeMatrix(version);
    placeFinder(base, 0, 0);
    placeFinder(base, 0, base.length - 7);
    placeFinder(base, base.length - 7, 0);
    placeAlign(base, version);
    placeTiming(base);
    placeData(base, version, finalCW);
    // try all masks, pick lowest penalty
    var best = null, bestPen = Infinity, bestMask = 0;
    for (var mask = 0; mask < 8; mask++) {
      var cand = applyMaskAndFormat(base, version, mask);
      var pen = penalty(cand);
      if (pen < bestPen) { bestPen = pen; best = cand; bestMask = mask; }
    }
    return { size: best.length, modules: best, version: version, mask: bestMask };
  }

  function generateSVG(text, opts) {
    opts = opts || {};
    var quiet = opts.margin == null ? 4 : opts.margin;
    var mat = generateMatrix(text);
    var n = mat.size;
    var total = n + quiet * 2;
    var dark = opts.dark || '#000000';
    var light = opts.light || '#ffffff';
    var path = '';
    for (var r = 0; r < n; r++) for (var c = 0; c < n; c++) {
      if (mat.modules[r][c]) path += 'M' + (c + quiet) + ' ' + (r + quiet) + 'h1v1h-1z';
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + total + ' ' + total +
      '" shape-rendering="crispEdges" width="' + (opts.size || total) + '" height="' + (opts.size || total) + '">' +
      '<rect width="' + total + '" height="' + total + '" fill="' + light + '"/>' +
      '<path d="' + path + '" fill="' + dark + '"/></svg>';
  }

  function generateDataUri(text, opts) {
    var svg = generateSVG(text, opts);
    return 'data:image/svg+xml;base64,' + (typeof btoa === 'function'
      ? btoa(unescape(encodeURIComponent(svg)))
      : Buffer.from(svg, 'utf8').toString('base64'));
  }

  var QRLite = { generateMatrix: generateMatrix, generateSVG: generateSVG, generateDataUri: generateDataUri };
  if (typeof module !== 'undefined' && module.exports) module.exports = QRLite;
  global.QRLite = QRLite;
})(typeof window !== 'undefined' ? window : globalThis);

// ==== Uygulama kodu ====
const SETTINGS = {
    rates: { TL: 1, USD: 40, EUR: 44 },
    symbols: { TL: '₺', USD: '$', EUR: '€' },
    vat: 0.20,
    cardCommission: 0.032,
    eftDiscount: 0.03,
    term30Cost: 0.035,
    term60Cost: 0.07,
    installment2Commission: 0.055,
    sarfExpenseUsd: 0.10,
    freeCargoThresholdTry: 15000,
    underThresholdCargoTry: 0.10,
    carriers: {
      hepsijet: {
        label: 'Hepsijet',
        multiplier: 1.25,
        maxFallback: 390.07,
        table: [
          { min: 0, max: 1, price: 113.69 },
          { min: 2, max: 4, price: 113.69 },
          { min: 5, max: 10, price: 117.27 },
          { min: 11, max: 20, price: 186.21 },
          { min: 21, max: 30, price: 311.66 },
          { min: 31, max: 40, price: 390.07 }
        ]
      },
      dhl: {
        label: 'DHL',
        multiplier: 1,
        maxFallback: null,
        table: [
          { min: 0, max: 5, price: 178.31 },
          { min: 6, max: 10, price: 197.29 },
          { min: 11, max: 15, price: 216.28 },
          { min: 16, max: 20, price: 241.58 },
          { min: 21, max: 25, price: 286.44 },
          { min: 26, max: 30, price: 342.84 },
          { min: 31, max: 40, price: 406.12 },
          { min: 41, max: 50, price: 650.00 }
        ]
      },
      aras: {
        label: 'Aras',
        multiplier: 1,
        maxFallback: null,
        extraAfter: 30,
        extraPerDesi: 11.55,
        table: [
          { min: 0, max: 5, price: 201.00 },
          { min: 6, max: 10, price: 220.00 },
          { min: 11, max: 15, price: 261.00 },
          { min: 16, max: 20, price: 340.00 },
          { min: 21, max: 25, price: 397.00 },
          { min: 26, max: 30, price: 453.00 }
        ]
      }
    }
  };

  const state = {
    userName: '',
    currency: 'TL',
    payment: 'card',
    carrier: 'hepsijet',
    desiMode: 'known',
    needsDesi: false,
    lastScreenBeforeResult: 2,
    ratesLoaded: false,
    ratesSource: 'Yedek kur'
  };


  document.querySelectorAll('#userChoice button').forEach(btn => {
    btn.addEventListener('click', () => chooseUser(btn.dataset.user));
  });

  function chooseUser(name){
    state.userName = name;
    try{ localStorage.setItem('akilliFiyatSihirbaziUser', name); }catch(e){}
    document.getElementById('userGate').classList.add('hide');
    document.getElementById('appWrap').classList.remove('appHidden');
    document.getElementById('topRight').classList.remove('hide');
    document.getElementById('welcomeName').textContent = name;
    showToast('Hoş geldin ' + name);
  }

  function changeUser(){
    state.userName = '';
    try{ localStorage.removeItem('akilliFiyatSihirbaziUser'); }catch(e){}
    document.getElementById('topRight').classList.add('hide');
    document.getElementById('appWrap').classList.add('appHidden');
    document.getElementById('userGate').classList.remove('hide');
  }

  function initUserGate(){
    let saved = '';
    try{ saved = localStorage.getItem('akilliFiyatSihirbaziUser') || ''; }catch(e){}
    if(saved) chooseUser(saved);
  }

  document.querySelectorAll('#currencyChoice button').forEach(btn => {
    btn.addEventListener('click', () => {
      state.currency = btn.dataset.currency;
      document.querySelectorAll('#currencyChoice button').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      updateDesiPreview();
    });
  });

  document.querySelectorAll('#paymentChoice button').forEach(btn => {
    btn.addEventListener('click', () => {
      state.payment = btn.dataset.pay;
      document.querySelectorAll('#paymentChoice button').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
    });
  });

  document.querySelectorAll('#cargoChoice button').forEach(btn => {
    btn.addEventListener('click', () => {
      state.carrier = btn.dataset.carrier;
      document.querySelectorAll('#cargoChoice button').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      updateDesiPreview();
    });
  });

  function splitLocaleNumber(value){
    const raw = String(value || '').trim();
    const clean = raw.replace(/[^0-9,.-]/g, '');
    if(!clean) return { intDigits:'', decimalDigits:'', hasDecimal:false };

    const lastComma = clean.lastIndexOf(',');
    const lastDot = clean.lastIndexOf('.');
    let decimalSep = null;

    if(lastComma !== -1 && lastDot !== -1){
      decimalSep = lastComma > lastDot ? ',' : '.';
    } else if(lastComma !== -1){
      const parts = clean.split(',');
      if(parts.length === 2){
        const after = (parts[1] || '').replace(/[^0-9]/g, '');
        // Türkçe kullanım: virgül sadece kısa ondalık için kabul edilir.
        // 100,000 veya 1,0000 gibi değerler fiyat alanında binlik devamıdır.
        decimalSep = after.length <= 2 ? ',' : null;
      }
    } else if(lastDot !== -1){
      const parts = clean.split('.');
      if(parts.length === 2){
        const after = (parts[1] || '').replace(/[^0-9]/g, '');
        // Nokta binlik ayırıcıdır. 10.000, 100.000, 1.000.000 bozulmaz.
        // Sadece 1.5 / 1.50 gibi kısa ondalıkları kabul et.
        decimalSep = after.length > 0 && after.length <= 2 ? '.' : null;
      }
    }

    if(decimalSep){
      const idx = clean.lastIndexOf(decimalSep);
      return {
        intDigits: clean.slice(0, idx).replace(/[^0-9]/g, ''),
        decimalDigits: clean.slice(idx + 1).replace(/[^0-9]/g, '').slice(0, 2),
        hasDecimal: true,
        endsWithDecimal: clean.endsWith(decimalSep)
      };
    }

    return {
      intDigits: clean.replace(/[^0-9]/g, ''),
      decimalDigits: '',
      hasDecimal: false,
      endsWithDecimal: false
    };
  }

  function parseLocaleNumber(value){
    const parts = splitLocaleNumber(value);
    if(!parts.intDigits && !parts.decimalDigits) return 0;
    const intPart = parts.intDigits || '0';
    const normalized = parts.hasDecimal ? `${intPart}.${parts.decimalDigits || '0'}` : intPart;
    const parsed = Number(normalized);
    return Number.isFinite(parsed) ? parsed : 0;
  }

  function detectDecimalSeparator(clean){
    const parts = splitLocaleNumber(clean);
    return parts.hasDecimal ? ',' : null;
  }

  function formatNumberInput(el){
    const raw = String(el.value || '');
    if(!raw.trim()){
      el.dataset.raw = '';
      return;
    }

    const parts = splitLocaleNumber(raw);
    let intDigits = (parts.intDigits || '').replace(/^0+(?=\d)/, '');
    const formattedInt = intDigits ? Number(intDigits).toLocaleString('tr-TR') : '0';
    let formatted = formattedInt;

    if(parts.hasDecimal && (parts.endsWithDecimal || parts.decimalDigits.length)){
      formatted += ',' + parts.decimalDigits;
    }

    el.value = formatted;
    el.dataset.raw = parseLocaleNumber(formatted).toString();
    try{ el.setSelectionRange(el.value.length, el.value.length); }catch(e){}
  }

  document.querySelectorAll('[data-format-number]').forEach(el => {
    el.addEventListener('input', () => formatNumberInput(el));
    el.addEventListener('blur', () => formatNumberInput(el));
  });

  ['miniFxAmount','miniFxFrom','miniFxTo'].forEach(id => {
    const el = document.getElementById(id);
    if(!el) return;
    el.addEventListener('input', updateMiniFx);
    el.addEventListener('change', updateMiniFx);
  });

  const miniFxSwap = document.getElementById('miniFxSwap');
  if(miniFxSwap){
    miniFxSwap.addEventListener('click', () => {
      const fromEl = document.getElementById('miniFxFrom');
      const toEl = document.getElementById('miniFxTo');
      const oldFrom = fromEl.value;
      fromEl.value = toEl.value;
      toEl.value = oldFrom;
      updateMiniFx();
    });
  }

  function n(id){
    const el = document.getElementById(id);
    if(!el) return 0;
    return Number(el.dataset.raw || parseLocaleNumber(el.value) || 0);
  }

  function plainDecimal(id){
    const el = document.getElementById(id);
    if(!el) return 0;
    let raw = String(el.value || '').trim().replace(/\s/g, '');
    if(!raw) return 0;

    const hasComma = raw.includes(',');
    const hasDot = raw.includes('.');

    if(hasComma && hasDot){
      const lastComma = raw.lastIndexOf(',');
      const lastDot = raw.lastIndexOf('.');
      if(lastComma > lastDot){
        raw = raw.replace(/\./g, '').replace(',', '.');
      } else {
        raw = raw.replace(/,/g, '');
      }
    } else if(hasComma){
      raw = raw.replace(',', '.');
    }

    raw = raw.replace(/[^0-9.\-]/g, '');
    const parsed = Number(raw);
    return Number.isFinite(parsed) ? parsed : 0;
  }
  function val(id){ return id === 'currency' ? state.currency : document.getElementById(id).value; }
  function rate(){ return SETTINGS.rates[val('currency')]; }
  function toTry(amount){ return amount * rate(); }
  function sarfTry(){ return SETTINGS.sarfExpenseUsd * SETTINGS.rates.USD; }
  function productCostTry(){ return toTry(n('cost')) + sarfTry(); }
  function smallCargoTry(){ return SETTINGS.underThresholdCargoTry * rate(); }
  function fromTry(amountTry){ return amountTry / rate(); }
  function moneyTryToCurrency(amountTry, currency){
    const rateValue = currency === 'TL' ? 1 : Number(SETTINGS.rates[currency] || 1);
    const amount = Number(amountTry || 0) / rateValue;
    return SETTINGS.symbols[currency] + amount.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function moneyTryToSelected(amountTry){
    return moneyTryToCurrency(amountTry, val('currency'));
  }
  function paymentLabel(){
    if(state.payment === 'card') return 'Kredi kartı';
    if(state.payment === 'eft') return 'Havale / EFT';
    if(state.payment === 'term30') return '30 gün vadeli';
    if(state.payment === 'term60') return '60 gün vadeli';
    if(state.payment === 'installment2') return '2 taksit';
    return state.payment;
  }

  function pct(){ return Math.max(5, Math.min(100, Math.round(n('profit')))); }
  function rawPct(){ return Number(document.getElementById('profit').value); }

  function setStepBackground(num){
    document.body.classList.remove('bg-step-1','bg-step-2','bg-step-3','bg-step-4');
    document.body.classList.add(`bg-step-${num}`);
  }

  function showScreen(num){
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
    document.querySelector(`[data-screen="${num}"]`).classList.add('active');
    document.querySelectorAll('.stepTab').forEach(t => t.classList.remove('active'));
    document.querySelector(`[data-tab="${num}"]`).classList.add('active');
    setStepBackground(num);
  }

  // "Yeni hesap": tüm girişleri ve sonuçları sıfırla, 1. adıma dön.
  function resetWizard(){
    ['cost','desiKnown','widthCm','lengthCm','heightCm'].forEach(id => {
      const el = document.getElementById(id); if(el) el.value = '';
    });
    const profit = document.getElementById('profit'); if(profit) profit.value = '20';

    // Para birimi -> TL, ödeme -> kart, kargo -> ilk seçenek (aktif sınıfları sıfırla)
    const resetChoice = (wrapId, defaultSelector) => {
      const wrap = document.getElementById(wrapId);
      if(!wrap) return;
      wrap.querySelectorAll('button').forEach(b => b.classList.remove('active'));
      const def = wrap.querySelector(defaultSelector) || wrap.querySelector('button');
      if(def) def.classList.add('active');
    };
    resetChoice('currencyChoice', '[data-currency="TL"]');
    resetChoice('paymentChoice', '[data-pay="card"]');
    resetChoice('cargoChoice', '[data-carrier="hepsijet"]');
    if(typeof state === 'object' && state){
      state.currency = 'TL'; state.payment = 'card'; state.carrier = 'hepsijet';
    }

    // Desi modunu "biliyorum"a al
    if(typeof setDesiMode === 'function') setDesiMode('known');
    updateDesiPreview && updateDesiPreview();

    // Sonuç alanlarını temizle
    ['saleEx','saleInc','netProfit','cargoCost','customerCargo','customerTotalEx','profitLoss',
     'desiOut','cargoOut','cargoNote','customerCargoNote','profitLossNote','logInfo'].forEach(id => {
      const el = document.getElementById(id); if(el) el.textContent = (id.endsWith('Note')||id==='logInfo') ? '' : '-';
    });
    ['altCurrencyResults','paymentImpactRows','rows'].forEach(id => {
      const el = document.getElementById(id); if(el) el.innerHTML = '';
    });
    clearStepErrors && clearStepErrors();

    showScreen(1);
    const cost = document.getElementById('cost'); if(cost) cost.focus();
  }


  function clearStepErrors(){
    ['err1','err2','err3'].forEach(id => {
      const el = document.getElementById(id);
      if(el) el.textContent = '';
    });
  }

  function navigateStep(num){
    if(num === 1){
      clearStepErrors();
      showScreen(1);
      return;
    }

    clearStepErrors();

    if(n('cost') <= 0){
      showScreen(1);
      document.getElementById('err1').textContent = 'Maliyet gir.';
      return;
    }

    if(num === 2){
      showScreen(2);
      return;
    }

    const enteredPct = rawPct();
    if(!enteredPct || enteredPct < 5){
      showScreen(2);
      document.getElementById('err2').textContent = 'Net kâr minimum %5 olmalı.';
      return;
    }
    if(enteredPct > 100){
      showScreen(2);
      document.getElementById('err2').textContent = 'En fazla %100 gir.';
      return;
    }

    state.needsDesi = true;
    state.lastScreenBeforeResult = 3;
    updateDesiPreview();

    if(num === 3){
      showScreen(3);
      return;
    }

    if(getDesi() <= 0){
      showScreen(3);
      document.getElementById('err3').textContent = 'Desi gir.';
      return;
    }
    if(cargoTryByDesi(getDesi()) === null){
      showScreen(3);
      document.getElementById('err3').textContent = 'Bu desi için fiyat yok.';
      return;
    }

    renderResult();
    showScreen(4);
  }

  document.querySelectorAll('.stepTab').forEach(tab => {
    tab.addEventListener('click', () => navigateStep(Number(tab.dataset.tab)));
  });

  function goProfit(){
    document.getElementById('err1').textContent = '';
    if(n('cost') <= 0){ document.getElementById('err1').textContent = 'Maliyet gir.'; return; }
    showScreen(2);
  }

  function goDesiOrResult(){
    document.getElementById('err2').textContent = '';
    const enteredPct = rawPct();
    if(!enteredPct || enteredPct < 5){ document.getElementById('err2').textContent = 'Net kâr minimum %5 olmalı.'; return; }
    if(enteredPct > 100){ document.getElementById('err2').textContent = 'En fazla %100 gir.'; return; }

    state.needsDesi = true;
    state.lastScreenBeforeResult = 3;
    updateDesiPreview();
    showScreen(3);
  }

  function setDesiMode(mode){
    state.desiMode = mode;
    document.getElementById('modeKnown').classList.toggle('active', mode === 'known');
    document.getElementById('modeMeasure').classList.toggle('active', mode === 'measure');
    document.getElementById('knownBox').classList.toggle('hide', mode !== 'known');
    document.getElementById('measureBox').classList.toggle('hide', mode !== 'measure');
    updateDesiPreview();
  }

  function getDesi(){
    if(state.desiMode === 'known') return plainDecimal('desiKnown');
    const w = plainDecimal('widthCm'), l = plainDecimal('lengthCm'), h = plainDecimal('heightCm');
    if(!w || !l || !h) return 0;
    return (w * l * h) / 3000;
  }

  function cargoTryByDesi(desi){
    if(!desi || desi <= 0) return 0;
    const carrier = SETTINGS.carriers[state.carrier];
    const ds = Math.ceil(desi);
    const row = carrier.table.find(x => ds >= x.min && ds <= x.max);
    if(row) return row.price * carrier.multiplier;
    if(carrier.extraAfter && carrier.extraPerDesi && ds > carrier.extraAfter){
      const baseRow = carrier.table.find(x => carrier.extraAfter >= x.min && carrier.extraAfter <= x.max);
      if(baseRow) return (baseRow.price + ((ds - carrier.extraAfter) * carrier.extraPerDesi)) * carrier.multiplier;
    }
    if(carrier.maxFallback !== null) return carrier.maxFallback * carrier.multiplier;
    return null;
  }

  function updateDesiPreview(){
    const desi = getDesi();
    const cargo = cargoTryByDesi(desi);
    document.getElementById('desiOut').textContent = desi > 0 ? desi.toLocaleString('tr-TR', { maximumFractionDigits: 2 }) : '-';
    document.getElementById('cargoOut').textContent = cargo === null ? 'Fiyat yok' : (cargo > 0 ? moneyTryToSelected(cargo) : '-');
  }

  function goResult(){
    document.getElementById('err3').textContent = '';
    if(getDesi() <= 0){ document.getElementById('err3').textContent = 'Desi gir.'; return; }
    if(cargoTryByDesi(getDesi()) === null){ document.getElementById('err3').textContent = 'Bu desi için fiyat yok.'; return; }
    renderResult();
    showScreen(4);
  }

  function backFromResult(){
    showScreen(state.lastScreenBeforeResult || 2);
  }

  function solveSale(baseTry, marginPct){
    const targetNetProfitTry = baseTry * (marginPct / 100);
    const targetBeforeDeductionsTry = baseTry + targetNetProfitTry;
    let saleExTry, saleIncTry, collectedExTry, collectedIncTry, commissionTry;
    let termCostTry = 0;
    let installmentExtraLossTry = 0;

    if(state.payment === 'card' || state.payment === 'installment2'){
      const commissionRate = state.payment === 'installment2' ? SETTINGS.installment2Commission : SETTINGS.cardCommission;
      saleExTry = targetBeforeDeductionsTry / (1 - (commissionRate * (1 + SETTINGS.vat)));
      saleIncTry = saleExTry * (1 + SETTINGS.vat);
      collectedExTry = saleExTry;
      collectedIncTry = saleIncTry;
      commissionTry = saleIncTry * commissionRate;
      installmentExtraLossTry = state.payment === 'installment2'
        ? saleIncTry * Math.max(0, SETTINGS.installment2Commission - SETTINGS.cardCommission)
        : 0;
    } else if(state.payment === 'eft'){
      saleExTry = targetBeforeDeductionsTry / (1 - SETTINGS.eftDiscount);
      saleIncTry = saleExTry * (1 + SETTINGS.vat);
      collectedExTry = saleExTry * (1 - SETTINGS.eftDiscount);
      collectedIncTry = saleIncTry * (1 - SETTINGS.eftDiscount);
      commissionTry = 0;
    } else {
      const termRate = state.payment === 'term60' ? SETTINGS.term60Cost : SETTINGS.term30Cost;
      saleExTry = targetBeforeDeductionsTry / (1 - termRate);
      saleIncTry = saleExTry * (1 + SETTINGS.vat);
      collectedExTry = saleExTry;
      collectedIncTry = saleIncTry;
      commissionTry = 0;
      termCostTry = saleExTry * termRate;
    }

    const netProfitTry = collectedExTry - baseTry - commissionTry - termCostTry;
    return { saleExTry, saleIncTry, collectedExTry, collectedIncTry, commissionTry, termCostTry, installmentExtraLossTry, netProfitTry };
  }

  function compute(costTry, marginPct, desi, forceSmallCargo = false){
    const smallBaseTry = costTry + smallCargoTry();
    const preliminary = solveSale(smallBaseTry, marginPct);
    const overThreshold = preliminary.saleIncTry >= SETTINGS.freeCargoThresholdTry;
    const useRealCargo = !forceSmallCargo && overThreshold && desi && desi > 0;
    const realCargoTry = useRealCargo ? cargoTryByDesi(desi) : smallCargoTry();
    const cargoTry = realCargoTry === null ? 0 : realCargoTry;
    const baseTry = costTry + cargoTry;
    const solved = solveSale(baseTry, marginPct);

    return {
      ...solved,
      cargoTry,
      invalidCargo: realCargoTry === null,
      baseTry,
      overThreshold: solved.saleIncTry >= SETTINGS.freeCargoThresholdTry,
      usedRealCargo: useRealCargo,
      needsDesi: overThreshold && (!desi || desi <= 0)
    };
  }

  function customerCargoTryFor(row, desi){
    if(row.saleIncTry >= SETTINGS.freeCargoThresholdTry) return 0;
    const cargo = cargoTryByDesi(desi);
    return cargo === null ? null : cargo;
  }

  function paymentImpactInfo(row){
    if(state.payment === 'card'){
      return {
        title: 'Kredi kartı komisyonu',
        rule: 'Satıştan %3,20 kesildi',
        amountTry: row.commissionTry,
        rateText: '%3,20'
      };
    }

    if(state.payment === 'eft'){
      const discountTry = Math.max(0, row.saleIncTry - row.collectedIncTry);
      return {
        title: 'Havale / EFT indirimi',
        rule: 'Satıştan %3 indirim uygulandı',
        amountTry: discountTry,
        rateText: '%3'
      };
    }

    if(state.payment === 'term30'){
      return {
        title: '30 gün vade farkı',
        rule: '30 gün vadeli: %3,50 vade farkı',
        amountTry: row.termCostTry,
        rateText: '%3,50'
      };
    }

    if(state.payment === 'term60'){
      return {
        title: '60 gün vade farkı',
        rule: '60 gün vadeli: %7 vade farkı',
        amountTry: row.termCostTry,
        rateText: '%7'
      };
    }

    if(state.payment === 'installment2'){
      return {
        title: '2 taksit komisyonu',
        rule: 'Satıştan %5,50 komisyon kesildi',
        amountTry: row.commissionTry,
        rateText: '%5,50'
      };
    }

    return {
      title: 'Ödeme etkisi',
      rule: '-',
      amountTry: 0,
      rateText: '-'
    };
  }

  function renderAltCurrencyResults(row, customerTotalExTry){
    const holder = document.getElementById('altCurrencyResults');
    if(!holder) return;

    const currencies = ['TL', 'USD', 'EUR'];
    holder.innerHTML = currencies.map(cur => {
      const ex = moneyTryToCurrency(row.saleExTry, cur);
      const inc = moneyTryToCurrency(row.saleIncTry, cur);
      const total = moneyTryToCurrency(customerTotalExTry || row.collectedExTry, cur);
      return `
        <div class="currencyResult">
          <div class="crHead">${cur}</div>
          <div class="crLine"><span>KDV hariç</span><b class="copyable" data-copy="${ex}" title="Kopyala">${ex}</b></div>
          <div class="crLine"><span>KDV dahil</span><b class="copyable" data-copy="${inc}" title="Kopyala">${inc}</b></div>
          <div class="crLine"><span>Alınacak KDV hariç</span><b class="copyable" data-copy="${total}" title="Kopyala">${total}</b></div>
        </div>
      `;
    }).join('');
  }

  function renderPaymentImpact(row){
    const holder = document.getElementById('paymentImpactRows');
    if(!holder) return;

    const info = paymentImpactInfo(row);
    const selectedAmount = moneyTryToSelected(info.amountTry);
    const tryAmount = moneyTryToCurrency(info.amountTry, 'TL');

    holder.innerHTML = `
      <div class="noteLine"><span>${info.title}</span><b>${selectedAmount}</b></div>
      <div class="noteLine"><span>Kural</span><b>${info.rule}</b></div>
      <div class="noteLine"><span>TL karşılığı</span><b>${tryAmount}</b></div>
    `;
  }

  function renderResult(){
    const costTry = productCostTry();
    const chosenPct = pct();
    const desi = getDesi();
    const selected = compute(costTry, chosenPct, desi);

    const saleExText = moneyTryToSelected(selected.saleExTry);
    const saleIncText = moneyTryToSelected(selected.saleIncTry);
    document.getElementById('saleEx').textContent = saleExText;
    document.getElementById('saleEx').dataset.copy = saleExText;
    document.getElementById('saleInc').textContent = saleIncText;
    document.getElementById('saleInc').dataset.copy = saleIncText;
    document.getElementById('netProfit').textContent = moneyTryToSelected(selected.netProfitTry);
    document.getElementById('cargoCost').textContent = moneyTryToSelected(selected.cargoTry);
    document.getElementById('cargoNote').textContent = selected.usedRealCargo ? SETTINGS.carriers[state.carrier].label : '0,1';
    const customerCargoTry = customerCargoTryFor(selected, desi);
    document.getElementById('customerCargo').textContent = customerCargoTry === null ? 'Fiyat yok' : moneyTryToSelected(customerCargoTry);
    document.getElementById('customerCargoNote').textContent = customerCargoTry === 0 ? 'Bedava' : SETTINGS.carriers[state.carrier].label;

    const customerTotalExTry = selected.collectedExTry + (customerCargoTry && customerCargoTry > 0 ? customerCargoTry : 0);
    const customerTotalExText = customerCargoTry === null ? 'Fiyat yok' : moneyTryToSelected(customerTotalExTry);
    document.getElementById('customerTotalEx').textContent = customerTotalExText;
    document.getElementById('customerTotalEx').dataset.copy = customerTotalExText;

    // Döviz seçiliyse (USD/EUR), kartların altına TL karşılığını yaz.
    const showTL = state.currency !== 'TL';
    const setTL = (id, amountTry) => {
      const el = document.getElementById(id);
      if(!el) return;
      if(showTL && amountTry !== null && amountTry !== undefined){
        el.textContent = '≈ ' + moneyTryToCurrency(amountTry, 'TL');
      } else {
        el.textContent = '';
      }
    };
    setTL('saleExTL', selected.saleExTry);
    setTL('saleIncTL', selected.saleIncTry);
    setTL('netProfitTL', selected.netProfitTry);
    setTL('customerTotalExTL', customerCargoTry === null ? null : customerTotalExTry);

    const impact = paymentImpactInfo(selected);
    const profitLossEl = document.getElementById('profitLoss');
    const profitLossNoteEl = document.getElementById('profitLossNote');
    profitLossEl.textContent = moneyTryToSelected(impact.amountTry);
    profitLossNoteEl.textContent = impact.rule;
    renderAltCurrencyResults(selected, customerTotalExTry);
    renderPaymentImpact(selected);

    state.lastResult = {
      createdAt: new Date().toISOString(),
      userName: state.userName,
      currency: state.currency,
      payment: state.payment,
      paymentLabel: paymentLabel(),
      cost: n('cost'),
      profitPercent: chosenPct,
      carrier: state.carrier,
      carrierLabel: SETTINGS.carriers[state.carrier].label,
      desi,
      saleExTry: selected.saleExTry,
      saleIncTry: selected.saleIncTry,
      collectedExTry: selected.collectedExTry,
      collectedIncTry: selected.collectedIncTry,
      netProfitTry: selected.netProfitTry,
      cargoTry: selected.cargoTry,
      customerCargoTry,
      customerTotalExTry,
      installmentExtraLossTry: selected.installmentExtraLossTry,
      paymentImpactTitle: impact.title,
      paymentImpactRule: impact.rule,
      paymentImpactTry: impact.amountTry,
      usdTryRate: SETTINGS.rates.USD,
      eurTryRate: SETTINGS.rates.EUR,
      ratesSource: state.ratesSource,
      saleExText,
      saleIncText,
      customerTotalExText
    };
    document.getElementById('logInfo').textContent = '';

    const tbody = document.getElementById('rows');
    tbody.innerHTML = '';

    const addRow = (i) => {
      const r = compute(costTry, i, desi);
      const tr = document.createElement('tr');
      if(i === chosenPct) tr.classList.add('selected');
      const exText = moneyTryToSelected(r.saleExTry);
      const incText = moneyTryToSelected(r.saleIncTry);
      const status = i === chosenPct ? 'Sen bunu seçtin' : (r.invalidCargo ? 'Fiyat yok' : (r.saleIncTry >= SETTINGS.freeCargoThresholdTry ? SETTINGS.carriers[state.carrier].label : 'Müşteriye yansır'));
      tr.innerHTML = `
        <td>%${i}</td>
        <td class="copyable" title="Kopyala" data-copy="${exText}">${exText}</td>
        <td class="copyable" title="Kopyala" data-copy="${incText}">${incText}</td>
        <td>${moneyTryToSelected(r.netProfitTry)}</td>
        <td>${status}</td>
      `;
      tbody.appendChild(tr);
    };

    addRow(chosenPct);
    for(let i=5;i<=100;i++){
      if(i !== chosenPct) addRow(i);
    }
  }

  async function copyText(text){
    if(!text || text === '-') return;
    try{
      if(navigator.clipboard && window.isSecureContext){
        await navigator.clipboard.writeText(text);
      } else {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();
        document.execCommand('copy');
        textarea.remove();
      }
      showToast();
    }catch(e){}
  }

  let toastTimer;
  function showToast(message = 'Kopyalandı'){
    const toast = document.getElementById('copyToast');
    toast.textContent = message;
    toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('show'), 900);
  }


  async function saveSaleLog(sold){
    if(!state.userName){
      changeUser();
      return;
    }
    if(!state.lastResult){
      renderResult();
    }

    const logs = JSON.parse(localStorage.getItem('akilliFiyatSihirbaziLogs') || '[]');
    const record = {
      ...state.lastResult,
      sold,
      soldLabel: sold ? 'Satıldı' : 'Satılmadı',
      loggedAt: new Date().toISOString()
    };

    logs.unshift(record);
    localStorage.setItem('akilliFiyatSihirbaziLogs', JSON.stringify(logs.slice(0, 250)));

    const info = document.getElementById('logInfo');
    info.textContent = 'DB kaydı hazırlanıyor...';

    try{
      const res = await fetch('api/price_log_save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(record)
      });

      const data = await res.json().catch(() => ({}));

      if(!res.ok || !data.ok){
        throw new Error(data.message || 'DB kayıt hatası');
      }

      info.textContent = (sold ? 'Satıldı' : 'Satılmadı') + ' olarak DB’ye kaydedildi. ID: ' + data.id;
      showToast('DB’ye kaydedildi');
    }catch(e){
      info.textContent = 'DB kaydı yapılamadı; tarayıcı yerel loguna alındı.';
      showToast('Yerel loga kaydedildi');
    }
  }

  function getSaleLogs(){
    return JSON.parse(localStorage.getItem('akilliFiyatSihirbaziLogs') || '[]');
  }

  function formatRateTry(value){
    return '₺' + Number(value || 0).toLocaleString('tr-TR', { minimumFractionDigits: 4, maximumFractionDigits: 4 });
  }

  function currencyToTryRate(currency){
    if(currency === 'TL') return 1;
    return Number(SETTINGS.rates[currency] || 1);
  }

  function formatMiniCurrency(amount, currency){
    const symbol = SETTINGS.symbols[currency] || '';
    return symbol + Number(amount || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function updateMiniFx(){
    const amountEl = document.getElementById('miniFxAmount');
    const fromEl = document.getElementById('miniFxFrom');
    const toEl = document.getElementById('miniFxTo');
    const resultEl = document.getElementById('miniFxResult');
    if(!amountEl || !fromEl || !toEl || !resultEl) return;

    const amount = Number(amountEl.dataset.raw || parseLocaleNumber(amountEl.value) || 0);
    const from = fromEl.value;
    const to = toEl.value;
    const amountTry = amount * currencyToTryRate(from);
    const converted = amountTry / currencyToTryRate(to);
    resultEl.textContent = formatMiniCurrency(converted, to);
    resultEl.dataset.copy = Number(converted || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    resultEl.title = 'Kopyala';
  }

  function updateRateUi(){
    document.getElementById('usdTryRate').textContent = formatRateTry(SETTINGS.rates.USD);
    document.getElementById('eurTryRate').textContent = formatRateTry(SETTINGS.rates.EUR);
    updateMiniFx();
  }

  async function loadFrankfurterRates(){
    updateRateUi();
    const fallback = { USD: SETTINGS.rates.USD, EUR: SETTINGS.rates.EUR };

    try{
      const res = await fetch('api/rates.php?t=' + Date.now(), { cache: 'no-store' });
      const data = await res.json();

      if(!res.ok || !data.ok || !data.rates || !data.rates.USD || !data.rates.EUR){
        throw new Error('kur alınamadı');
      }

      SETTINGS.rates.USD = Number(data.rates.USD);
      SETTINGS.rates.EUR = Number(data.rates.EUR);
      state.ratesLoaded = true;
      state.ratesSource = data.source || 'Frankfurter';

      updateRateUi();
      updateDesiPreview();

      if(document.querySelector('[data-screen="4"]').classList.contains('active')){
        renderResult();
      }
    }catch(e){
      state.ratesLoaded = false;
      state.ratesSource = 'Kur alınamadı';
      const usdEl = document.getElementById('usdTryRate');
      const eurEl = document.getElementById('eurTryRate');
      if(usdEl) usdEl.textContent = 'Kur alınamadı';
      if(eurEl) eurEl.textContent = 'Kur alınamadı';
      updateMiniFx();
    }
  }


  const PANEL_LOGO = 'https://www.poyraztoner.com/Data/EditorFiles/Mail/mail-logo.png';
  const CARGO_LOGOS = {
    dhl: {
      label: 'DHL',
      color: 'https://www.poyraztoner.com/Data/EditorFiles/Kargo%20Logo/dhl-logo.png',
      bw: 'https://www.poyraztoner.com/Data/EditorFiles/Kargo%20Logo/dhl-logo-siyah.png'
    },
    aras: {
      label: 'Aras',
      color: 'https://www.poyraztoner.com/Data/EditorFiles/Kargo%20Logo/aras-logo.png',
      bw: 'https://www.poyraztoner.com/Data/EditorFiles/Kargo%20Logo/aras-logo-siyah.png'
    }
  };

  const SENDER_INFO = {
    name: 'Poyraz Toner',
    address: 'Hürriyet, Akgün Sk. No:17-a, 34212 Bağcılar/İstanbul',
    phone: '(0212) 550 09 09'
  };

  const CARGO_AGREEMENT_CODES = {
    dhl: '695383532',
    aras: ''
  };

  const labelState = {
    carrier: 'dhl',
    logoMode: 'color',
    payType: 'seller',
    paper: '15x10',
    selectedCustomer: null,
    selectedAddress: null,
    addressMode: 'manual',
    editingCustomerId: null,
    editingLabelId: null,
    lastPieceRefs: [],
    recordsCache: []
  };

  const PAPER_SIZES = {
    a4: { cls:'paper-a4', w:'210mm', h:'297mm', page:'A4', label:'A4', pad:'13mm' },
    a5: { cls:'paper-a5', w:'148mm', h:'210mm', page:'A5', label:'A5', pad:'10mm' },
    '10x10': { cls:'paper-10x10', w:'100mm', h:'100mm', page:'100mm 100mm', label:'10×10', pad:'4mm' },
    '15x10': { cls:'paper-15x10', w:'150mm', h:'100mm', page:'150mm 100mm', label:'15×10', pad:'5mm' }
  };

  function showModule(module){
    const price = document.getElementById('priceModule');
    const bulk = document.getElementById('bulkPriceModule');
    const label = document.getElementById('labelModule');
    const priceBtn = document.getElementById('priceModuleBtn');
    const bulkBtn = document.getElementById('bulkPriceModuleBtn');
    const labelBtn = document.getElementById('labelModuleBtn');

    [price, bulk, label].forEach(panel => panel && panel.classList.add('hide'));
    [priceBtn, bulkBtn, labelBtn].forEach(btn => btn && btn.classList.remove('active'));

    if(module === 'label'){
      label.classList.remove('hide');
      labelBtn.classList.add('active');
      setTimeout(() => searchCustomers({ keepSelection: false }), 60);
      buildLabelPreview();
      return;
    }

    if(module === 'bulkPrice'){
      bulk.classList.remove('hide');
      bulkBtn.classList.add('active');
      return;
    }

    price.classList.remove('hide');
    priceBtn.classList.add('active');
  }

  function openLabelWizard(){
    showModule('label');
    showLabelScreen(1);
    setTimeout(() => searchCustomers({ keepSelection: false }), 60);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function showLabelScreen(num){
    document.querySelectorAll('.labelScreen').forEach(s => s.classList.remove('active'));
    document.querySelector(`[data-label-screen="${num}"]`).classList.add('active');
    document.querySelectorAll('.labelTab').forEach(t => t.classList.remove('active'));
    document.querySelector(`[data-label-tab="${num}"]`).classList.add('active');
    if(num === 4) buildLabelPreview();
    if(num === 5) loadShippingLabelRecords();
  }

  function setField(id, value, force = false){
    const el = document.getElementById(id);
    if(el && (force || !el.value.trim()) && value !== undefined && value !== null) el.value = String(value).trim();
  }

  function getLocalCustomers(){
    try{ return JSON.parse(localStorage.getItem('labelCustomers') || '[]'); }catch(e){ return []; }
  }

  function setLocalCustomers(list){
    try{ localStorage.setItem('labelCustomers', JSON.stringify(list.slice(-500))); }catch(e){}
  }

  function customerDisplay(c){
    const name = [c.company_name, c.customer_name].filter(Boolean).join(' / ');
    return name || c.recipient || c.phone || 'Müşteri';
  }

  async function apiCustomers(action, payload = {}){
    try{
      const res = await fetch('api/customer_api.php?action=' + encodeURIComponent(action), {
        method: action === 'search' ? 'POST' : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const data = await res.json().catch(() => ({}));
      if(!res.ok || !data.ok) throw new Error(data.message || 'API hatası');
      return data;
    }catch(e){
      return null;
    }
  }

  function exportCustomersCsv(){
    window.location.href = 'api/customer_csv.php?action=export';
  }

  async function importCustomersCsv(input){
    const file = input.files && input.files[0];
    const info = document.getElementById('customerCsvInfo');

    if(!file){
      return;
    }

    if(info) info.textContent = 'CSV içe aktarılıyor...';

    try{
      const form = new FormData();
      form.append('csv', file);

      const res = await fetch('api/customer_csv.php?action=import', {
        method: 'POST',
        body: form
      });

      const data = await res.json().catch(() => ({}));

      if(!res.ok || !data.ok){
        throw new Error(data.message || 'CSV aktarım hatası');
      }

      if(info) info.textContent = `${data.imported || 0} müşteri aktarıldı, ${data.updated || 0} kayıt güncellendi.`;
      input.value = '';
      searchCustomers({ keepSelection: false });
      showToast('CSV aktarıldı');
    }catch(e){
      if(info) info.textContent = 'CSV aktarılamadı. Başlıkları ve dosya formatını kontrol et.';
      input.value = '';
      showToast('CSV aktarım hatası');
    }
  }

  function customerSelectionKey(c){
    if(!c) return '';
    return String(c.id || '') + '|' + String(c.phone || '') + '|' + String(c.company_name || '') + '|' + String(c.customer_name || '') + '|' + String(c.street || '');
  }

  function isCustomerSelected(c){
    return labelState.selectedCustomer && customerSelectionKey(c) === customerSelectionKey(labelState.selectedCustomer);
  }

  function updateCustomerResultSelection(){
    document.querySelectorAll('.customerResult').forEach(card => {
      const index = Number(card.dataset.customerIndex);
      const c = (window.__lastCustomerResults || [])[index];
      const selected = isCustomerSelected(c);
      card.classList.toggle('selected', selected);
      const btn = card.querySelector('button');
      if(btn){
        btn.textContent = selected ? '✓ Seçildi' : 'Seç';
      }
    });
  }

  function clearCustomerSelection(){
    labelState.selectedCustomer = null;
    labelState.selectedAddress = null;
    labelState.addressMode = 'manual';
    const box = document.getElementById('selectedCustomerBox');
    if(box) box.classList.add('hide');
    updateCustomerResultSelection();
  }

  // Kullanıcı "Seçimi kaldır" dediğinde: seçimi bırak + alıcı/adres alanlarını temizle.
  function clearSelectedCustomer(){
    clearCustomerSelection();
    ['shipRecipient','shipPhone','rawAddress','shipMahalle','shipStreet','shipNo',
     'shipPostcode','shipCounty','shipCity','shipExtra'].forEach(id => {
      const el = document.getElementById(id);
      if(el) el.value = '';
    });
    showToast('Müşteri seçimi kaldırıldı');
  }

  async function searchCustomers(options = {}){
    const q = document.getElementById('customerSearch').value.trim();
    const holder = document.getElementById('customerResults');

    if(!options.keepSelection){
      clearCustomerSelection();
    }

    holder.innerHTML = '<div class="labelHint">Aranıyor...</div>';

    let results = [];
    const remote = await apiCustomers('search', { q });
    if(remote && Array.isArray(remote.customers)){
      results = remote.customers;
    }else{
      const needle = q.toLocaleLowerCase('tr-TR');
      results = getLocalCustomers().filter(c => {
        return !needle || JSON.stringify(c).toLocaleLowerCase('tr-TR').includes(needle);
      });
    }

    if(!results.length){
      holder.innerHTML = '<div class="labelHint">Kayıt bulunamadı. Manuel yeni alıcı ya da müşteri ekle kullan.</div>';
      return;
    }

    window.__lastCustomerResults = results;

    holder.innerHTML = results.map((c, i) => {
      const selected = options.keepSelection && isCustomerSelected(c);
      const editable = isEditableCustomer(c);
      return `
        <div class="customerResult ${selected ? 'selected' : ''}" data-customer-index="${i}">
          <div>
            <b>${escapeHtml(customerDisplay(c))}</b>
            <span>${escapeHtml(c.phone || '')} · ${escapeHtml([c.mahalle, c.street, c.county, c.city].filter(Boolean).join(' '))}</span>
            <span>${escapeHtml(c.source_label || c.source || '')}</span>
          </div>
          <div class="customerResultActions">
            <button type="button" class="primary" onclick="selectCustomerByIndex(${i})">${selected ? '✓ Seçildi' : 'Seç'}</button>
            ${editable ? `<button type="button" class="ghost" onclick="editCustomerByIndex(${i})">Düzenle</button>` : ''}
            ${editable ? `<button type="button" class="dangerBtn" onclick="deleteCustomerByIndex(${i})">Sil</button>` : ''}
          </div>
        </div>
      `;
    }).join('');

    if(options.keepSelection){
      updateCustomerResultSelection();
    }
  }

  function clearNewCustomerForm(){
    [
      'newCustomerName',
      'newCustomerCompany',
      'newCustomerPhone',
      'newCustomerNote',
      'newCustomerRawAddress',
      'newCustomerMahalle',
      'newCustomerStreet',
      'newCustomerNo',
      'newCustomerPostcode',
      'newCustomerCounty',
      'newCustomerCity',
      'newCustomerExtra'
    ].forEach(id => {
      const el = document.getElementById(id);
      if(el) el.value = '';
    });

    labelState.editingCustomerId = null;
    const err = document.getElementById('labelErr0');
    if(err) err.textContent = '';
    const mode = document.getElementById('customerFormMode');
    if(mode) mode.textContent = '';
    const btn = document.getElementById('customerSaveBtn');
    if(btn) btn.textContent = 'Müşteriyi oluştur';
  }

  function showNewCustomerBox(){
    clearNewCustomerForm();
    clearCustomerSelection();
    document.getElementById('newCustomerBox').classList.remove('hide');
    document.getElementById('newCustomerName')?.focus();
  }

  function startManualCustomer(){
    labelState.selectedCustomer = null;
    labelState.selectedAddress = null;
    labelState.addressMode = 'manual';
    document.getElementById('selectedCustomerBox').classList.add('hide');
    ['shipRecipient','shipPhone','rawAddress','shipMahalle','shipStreet','shipNo','shipPostcode','shipCounty','shipCity','shipExtra'].forEach(id => setField(id, '', true));
    showLabelScreen(2);
  }

  function resetShippingLabelForm(){
    labelState.selectedCustomer = null;
    labelState.selectedAddress = null;
    labelState.addressMode = 'manual';
    labelState.editingLabelId = null;
    labelState.carrier = 'dhl';
    labelState.logoMode = 'color';
    labelState.payType = 'seller';
    labelState.paper = '15x10';

    ['shipRecipient','shipPhone','rawAddress','shipMahalle','shipStreet','shipNo','shipPostcode','shipCounty','shipCity','shipExtra','shipRef'].forEach(id => setField(id, '', true));
    setField('labelPieces', '1', true);
    document.getElementById('selectedCustomerBox')?.classList.add('hide');
    document.getElementById('labelDbInfo').textContent = '';
    syncLabelChoiceButtons();
    buildLabelPreview();
    showLabelScreen(1);
    showToast('Yeni kayıt açıldı');
  }

  function syncLabelChoiceButtons(){
    document.querySelectorAll('#labelCarrierChoice button').forEach(b => b.classList.toggle('active', b.dataset.labelCarrier === labelState.carrier));
    document.querySelectorAll('#labelLogoMode button').forEach(b => b.classList.toggle('active', b.dataset.logoMode === labelState.logoMode));
    document.querySelectorAll('#labelPayChoice button').forEach(b => b.classList.toggle('active', b.dataset.labelPay === labelState.payType));
    document.querySelectorAll('#paperChoice button').forEach(b => b.classList.toggle('active', b.dataset.paper === labelState.paper));
  }

  async function createCustomerFromBox(){
    const parsed = parseNewCustomerAddress(true);

    const customer = {
      id: labelState.editingCustomerId || ('local-' + Date.now()),
      customer_name: document.getElementById('newCustomerName').value.trim(),
      company_name: document.getElementById('newCustomerCompany').value.trim(),
      phone: document.getElementById('newCustomerPhone').value.trim() || parsed.phone,
      note: document.getElementById('newCustomerNote').value.trim(),
      raw_address: document.getElementById('newCustomerRawAddress').value.trim(),
      mahalle: document.getElementById('newCustomerMahalle').value.trim() || parsed.mahalle,
      street: document.getElementById('newCustomerStreet').value.trim() || parsed.street,
      door_no: document.getElementById('newCustomerNo').value.trim() || parsed.door_no,
      postcode: document.getElementById('newCustomerPostcode').value.trim() || parsed.postcode,
      county: document.getElementById('newCustomerCounty').value.trim() || parsed.county,
      city: document.getElementById('newCustomerCity').value.trim() || parsed.city,
      extra: document.getElementById('newCustomerExtra').value.trim() || parsed.extra
    };

    if(!customer.customer_name && !customer.company_name){
      document.getElementById('labelErr0').textContent = 'Alıcı adı veya firma adı gir.';
      return;
    }

    if(!customer.phone || !customer.mahalle || !customer.street || !customer.county || !customer.city){
      document.getElementById('labelErr0').textContent = 'Müşteri adresi eksik. Telefon, mahalle, sokak, ilçe ve il gerekli.';
      return;
    }

    const remote = await apiCustomers('save', customer);
    if(remote && remote.customer){
      labelState.selectedCustomer = remote.customer;
    }else{
      const list = getLocalCustomers();
      const existingIndex = list.findIndex(c => String(c.id) === String(customer.id));
      if(existingIndex >= 0) list[existingIndex] = { ...list[existingIndex], ...customer };
      else list.push(customer);
      setLocalCustomers(list);
      labelState.selectedCustomer = customer;
    }

    const wasEditingCustomer = !!labelState.editingCustomerId;
    labelState.selectedAddress = labelState.selectedCustomer;
    labelState.addressMode = 'same';
    fillCustomerAddress(labelState.selectedCustomer);
    renderSelectedCustomer();
    updateCustomerResultSelection();
    document.getElementById('newCustomerBox').classList.add('hide');
    clearNewCustomerForm();
    searchCustomers({ keepSelection: true });
    showToast(wasEditingCustomer ? 'Müşteri güncellendi' : 'Müşteri ve adres kaydedildi');
  }

  function isEditableCustomer(c){
    return !!(c && c.id && /^[0-9]+$/.test(String(c.id)));
  }

  function fillNewCustomerForm(c){
    if(!c) return;
    setField('newCustomerName', c.customer_name || c.recipient || '', true);
    setField('newCustomerCompany', c.company_name || '', true);
    setField('newCustomerPhone', c.phone || '', true);
    setField('newCustomerNote', c.note || '', true);
    setField('newCustomerRawAddress', c.raw_address || '', true);
    setField('newCustomerMahalle', c.mahalle || '', true);
    setField('newCustomerStreet', c.street || '', true);
    setField('newCustomerNo', c.door_no || c.no || '', true);
    setField('newCustomerPostcode', c.postcode || '', true);
    setField('newCustomerCounty', c.county || '', true);
    setField('newCustomerCity', c.city || '', true);
    setField('newCustomerExtra', c.extra || '', true);
  }

  function editCustomerByIndex(index){
    const list = window.__lastCustomerResults || [];
    const c = list[index];
    if(!isEditableCustomer(c)){
      showToast('Bu kayıt düzenlenemez');
      return;
    }

    labelState.editingCustomerId = c.id;
    fillNewCustomerForm(c);
    document.getElementById('newCustomerBox').classList.remove('hide');

    const mode = document.getElementById('customerFormMode');
    if(mode) mode.textContent = 'Düzenleme modu: ' + customerDisplay(c);

    const btn = document.getElementById('customerSaveBtn');
    if(btn) btn.textContent = 'Müşteriyi güncelle';

    document.getElementById('newCustomerName')?.focus();
    showToast('Müşteri düzenlemeye açıldı');
  }

  async function deleteCustomerByIndex(index){
    const list = window.__lastCustomerResults || [];
    const c = list[index];
    if(!isEditableCustomer(c)){
      showToast('Bu kayıt silinemez');
      return;
    }

    if(!confirm(customerDisplay(c) + ' müşterisi silinsin mi?')) return;

    const remote = await apiCustomers('delete', { id: c.id });
    if(remote && remote.ok){
      if(labelState.selectedCustomer && String(labelState.selectedCustomer.id) === String(c.id)){
        clearCustomerSelection();
      }
      showToast('Müşteri silindi');
      searchCustomers({ keepSelection: false });
      return;
    }

    const local = getLocalCustomers().filter(item => String(item.id) !== String(c.id));
    setLocalCustomers(local);
    showToast('Müşteri yerelden silindi');
    searchCustomers({ keepSelection: false });
  }

  function selectCustomerByIndex(index){
    const list = window.__lastCustomerResults || [];
    const c = list[index];
    if(!c) return;
    labelState.selectedCustomer = c;
    labelState.selectedAddress = c;
    labelState.addressMode = 'same';
    fillCustomerAddress(c);
    renderSelectedCustomer();
    updateCustomerResultSelection();
    showToast('Müşteri seçildi');
  }

  function renderSelectedCustomer(){
    const box = document.getElementById('selectedCustomerBox');
    const txt = document.getElementById('selectedCustomerText');
    if(!labelState.selectedCustomer){
      box.classList.add('hide');
      return;
    }
    txt.textContent = customerDisplay(labelState.selectedCustomer);
    box.classList.remove('hide');
  }

  function fillCustomerIdentity(c){
    if(!c) return;
    const displayName = c.company_name || c.customer_name || c.recipient || '';
    setField('shipRecipient', displayName, true);
    setField('shipPhone', c.phone || '', true);
  }

  function fillCustomerAddress(c){
    if(!c) return;
    fillCustomerIdentity(c);
    setField('rawAddress', c.raw_address || '', true);
    setField('shipMahalle', c.mahalle || '', true);
    setField('shipStreet', c.street || '', true);
    setField('shipNo', c.door_no || c.no || '', true);
    setField('shipPostcode', c.postcode || '', true);
    setField('shipCounty', c.county || '', true);
    setField('shipCity', c.city || '', true);
    setField('shipExtra', c.extra || '', true);
  }

  function useSameCustomerAddress(){
    if(!labelState.selectedCustomer) return;
    labelState.addressMode = 'same';
    labelState.selectedAddress = labelState.selectedCustomer;
    fillCustomerAddress(labelState.selectedCustomer);
    showToast('Aynı adres seçildi');
  }

  function useDifferentCustomerAddress(){
    if(!labelState.selectedCustomer) return;
    labelState.addressMode = 'different';
    fillCustomerIdentity(labelState.selectedCustomer);
    ['rawAddress','shipMahalle','shipStreet','shipNo','shipPostcode','shipCounty','shipCity','shipExtra'].forEach(id => setField(id, '', true));
    showToast('Yeni adres gir');
    showLabelScreen(2);
  }

  function goLabelAddress(){
    document.getElementById('labelErr0').textContent = '';
    if(labelState.selectedCustomer && labelState.addressMode === 'same'){
      fillCustomerAddress(labelState.selectedCustomer);
      showLabelScreen(2);
      return;
    }
    if(labelState.selectedCustomer && labelState.addressMode === 'different'){
      showLabelScreen(2);
      return;
    }
    startManualCustomer();
  }

  function cleanAddressText(text){
    return String(text || '')
      .replace(/\r/g, '\n')
      .replace(/[ \t]+/g, ' ')
      .replace(/\n+/g, ', ')
      .replace(/\s+,/g, ',')
      .replace(/,\s+/g, ', ')
      .trim();
  }

  function normalizeCityName(value){
    const raw = String(value || '').trim().replace(/[.,;:]+$/g, '');
    const lower = raw.toLocaleLowerCase('tr-TR')
      .replace(/ı̇/g, 'i')
      .replace(/\s+/g, ' ');

    const map = {
      'ist': 'İstanbul',
      'ist.': 'İstanbul',
      'istanbul': 'İstanbul',
      'i̇stanbul': 'İstanbul',
      'ank': 'Ankara',
      'ank.': 'Ankara',
      'izm': 'İzmir',
      'izm.': 'İzmir',
      'izmir': 'İzmir'
    };

    return map[lower] || raw;
  }

  function cleanAddressPart(value){
    return String(value || '')
      .replace(/^[,.\s-]+|[,.\s-]+$/g, '')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function normalizeStreetSuffix(value){
    const lower = String(value || '').toLocaleLowerCase('tr-TR').replace(/\./g, '').trim();

    if(['sk','sok','sokak','sokağı','sokagi'].includes(lower)) return 'Sk.';
    if(['cd','cad','cadde','caddesi'].includes(lower)) return 'Cad.';
    if(['blv','bulvar','bulvarı','bulvari'].includes(lower)) return 'Blv.';
    if(['yol','yolu'].includes(lower)) return 'Yolu';

    return cleanAddressPart(value);
  }

  function parseAddressParts(rawText){
    let raw = cleanAddressText(rawText);
    const result = {
      raw_address: raw,
      phone: '',
      mahalle: '',
      street: '',
      door_no: '',
      postcode: '',
      county: '',
      city: '',
      extra: ''
    };

    if(!raw) return result;

    let working = raw
      .replace(/\bmahallesi\b/ig, ' Mah. ')
      .replace(/\bmah\.?\b/ig, ' Mah. ')
      .replace(/\bmh\.?\b/ig, ' Mah. ')
      .replace(/\bmhl\.?\b/ig, ' Mah. ')
      .replace(/\bsokağı\b/ig, ' Sk. ')
      .replace(/\bsokagi\b/ig, ' Sk. ')
      .replace(/\bsokak\b/ig, ' Sk. ')
      .replace(/\bsok\.?\b/ig, ' Sk. ')
      .replace(/\bsk\.?\b/ig, ' Sk. ')
      .replace(/\bcaddesi\b/ig, ' Cad. ')
      .replace(/\bcadde\b/ig, ' Cad. ')
      .replace(/\bcad\.?\b/ig, ' Cad. ')
      .replace(/\bcd\.?\b/ig, ' Cad. ')
      .replace(/\bbulvarı\b/ig, ' Blv. ')
      .replace(/\bbulvari\b/ig, ' Blv. ')
      .replace(/\bbulvar\b/ig, ' Blv. ')
      .replace(/\bblv\.?\b/ig, ' Blv. ')
      .replace(/\s+/g, ' ')
      .trim();

    const phoneMatch = working.match(/(?:telefon|tel|gsm|cep)?\s*:?\s*((?:\+?90\s*)?(?:\(?0?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{2}[\s.-]?\d{2}))/i);
    if(phoneMatch){
      result.phone = phoneMatch[1].replace(/\s+/g, ' ').trim();
      working = cleanAddressText(working.replace(phoneMatch[0], ' '));
    }

    const postcodeMatch = working.match(/\b(\d{5})\b/);
    if(postcodeMatch) result.postcode = postcodeMatch[1];

    const slashAfterPost = working.match(/\b\d{5}\b\s*([A-Za-zÇĞİÖŞÜçğıöşü\s.'-]+?)\s*\/\s*([A-Za-zÇĞİÖŞÜçğıöşü\s.'-]+)\s*$/);
    const slashEnd = working.match(/([A-Za-zÇĞİÖŞÜçğıöşü\s.'-]+?)\s*\/\s*([A-Za-zÇĞİÖŞÜçğıöşü\s.'-]+)\s*$/);

    if(slashAfterPost){
      result.county = cleanAddressPart(slashAfterPost[1]);
      result.city = normalizeCityName(slashAfterPost[2]);
    }else if(slashEnd){
      result.county = cleanAddressPart(slashEnd[1]);
      result.city = normalizeCityName(slashEnd[2]);
    }else{
      const cityWords = '(?:ist\\.?|istanbul|i̇stanbul|ankara|ank\\.?|izmir|izm\\.?)';
      const afterPostNoSlash = working.match(new RegExp("\\\\b\\\\d{5}\\\\b\\\\s*([A-Za-zÇĞİÖŞÜçğıöşü\\\\s.'-]+?)\\\\s+(" + cityWords + ")\\\\s*$", 'i'));
      if(afterPostNoSlash){
        result.county = cleanAddressPart(afterPostNoSlash[1]);
        result.city = normalizeCityName(afterPostNoSlash[2]);
      }else{
        const cityOnly = working.match(new RegExp('\\\\b(' + cityWords + ')\\\\s*$', 'i'));
        if(cityOnly) result.city = normalizeCityName(cityOnly[1]);
      }
    }

    const noMatch = working.match(/(?:\bNo\b|No\.|Numara|Kapı No|Dış Kapı)\s*:?\s*([0-9]+[A-Za-zÇĞİÖŞÜçğıöşü\-\/]*)/i);
    if(noMatch) result.door_no = cleanAddressPart(noMatch[1]);

    const mahalleMatch = working.match(/(?:^|[,;\s])([A-Za-zÇĞİÖŞÜçğıöşü0-9\s.'-]{2,70}?)\s+Mah\.\b/i);
    if(mahalleMatch){
      result.mahalle = cleanAddressPart(mahalleMatch[1].split(',').pop());
    }

    const streetMatch = working.match(/(?:^|[,;\s])([A-Za-zÇĞİÖŞÜçğıöşü0-9\s.'-]{2,90}?)\s+(Sk\.|Cad\.|Blv\.|Yolu)\b/i);
    if(streetMatch){
      const streetName = cleanAddressPart(streetMatch[1].split(',').pop().replace(/\bMah\.\b/i, ''));
      const suffix = normalizeStreetSuffix(streetMatch[2]);
      result.street = cleanAddressPart(`${streetName} ${suffix}`);
    }

    let cleaned = working
      .replace(/\b\d{5}\b/g, ' ')
      .replace(/(?:\bNo\b|No\.|Numara|Kapı No|Dış Kapı)\s*:?\s*([0-9]+[A-Za-zÇĞİÖŞÜçğıöşü\-\/]*)/ig, ' ')
      .replace(/telefon|tel|gsm|cep/ig, ' ')
      .replace(/([A-Za-zÇĞİÖŞÜçğıöşü\s.'-]+?)\s*\/\s*([A-Za-zÇĞİÖŞÜçğıöşü\s.'-]+)\s*$/g, ' ')
      .replace(/\b(ist\.?|istanbul|i̇stanbul|ankara|ank\.?|izmir|izm\.?)\b\s*$/ig, ' ')
      .replace(/\s+/g, ' ')
      .replace(/,+/g, ',')
      .replace(/,\s*,/g, ',')
      .replace(/(^,|,$)/g, '')
      .trim();

    const parts = cleaned.split(',').map(p => p.trim()).filter(Boolean);

    if(!result.mahalle){
      const mahallePart = parts.find(p => /\bMah\.\b/i.test(p)) || parts[0] || '';
      result.mahalle = cleanAddressPart(mahallePart.replace(/\bMah\.\b/i, ''));
    }

    if(!result.street){
      const streetPart = parts.find(p => /\b(Sk\.|Cad\.|Blv\.|Yolu)\b/i.test(p)) || parts[1] || '';
      const m = streetPart.match(/(.+?)\s+(Sk\.|Cad\.|Blv\.|Yolu)\b/i);
      result.street = m
        ? cleanAddressPart(`${cleanAddressPart(m[1])} ${normalizeStreetSuffix(m[2])}`)
        : cleanAddressPart(streetPart);
    }

    if(!result.county && result.postcode){
      const afterPostCounty = working.match(/\b\d{5}\b\s*([A-Za-zÇĞİÖŞÜçğıöşü\s.'-]{2,60})/);
      if(afterPostCounty){
        result.county = cleanAddressPart(afterPostCounty[1]
          .replace(/\b(ist\.?|istanbul|i̇stanbul|ankara|ank\.?|izmir|izm\.?)\b/ig, '')
        );
      }
    }

    if(result.city) result.city = normalizeCityName(result.city);

    const knownParts = [result.mahalle, result.street, result.door_no, result.postcode, result.county, result.city]
      .filter(Boolean)
      .map(x => String(x).toLocaleLowerCase('tr-TR'));

    const extras = parts.filter(p => {
      const lower = p.toLocaleLowerCase('tr-TR');
      return !knownParts.some(k => k && lower.includes(k.replace(/\s+(sk\.|cad\.|blv\.)$/i, '')));
    });

    if(extras.length > 0){
      result.extra = cleanAddressPart(extras.slice(2).join(', '));
    }

    return result;
  }

  function fillFieldsFromAddressParts(parts, map, force = false){
    Object.keys(map).forEach(key => {
      if(parts[key] !== undefined) setField(map[key], parts[key], force);
    });
  }

  function parseNewCustomerAddress(silent){
    const raw = document.getElementById('newCustomerRawAddress')?.value || '';
    const parts = parseAddressParts(raw);

    fillFieldsFromAddressParts(parts, {
      phone: 'newCustomerPhone',
      mahalle: 'newCustomerMahalle',
      street: 'newCustomerStreet',
      door_no: 'newCustomerNo',
      postcode: 'newCustomerPostcode',
      county: 'newCustomerCounty',
      city: 'newCustomerCity',
      extra: 'newCustomerExtra'
    }, false);

    if(!silent) showToast('Müşteri adresi ayrıldı');
    return parts;
  }

  function parseShippingAddress(silent){
    const rawEl = document.getElementById('rawAddress');
    const err = document.getElementById('labelErr1');
    if(err) err.textContent = '';

    const raw = cleanAddressText(rawEl.value);
    if(!raw){
      if(!silent && err) err.textContent = 'Adres yapıştır.';
      return false;
    }

    const parts = parseAddressParts(raw);

    fillFieldsFromAddressParts(parts, {
      phone: 'shipPhone',
      mahalle: 'shipMahalle',
      street: 'shipStreet',
      door_no: 'shipNo',
      postcode: 'shipPostcode',
      county: 'shipCounty',
      city: 'shipCity',
      extra: 'shipExtra'
    }, false);

    buildLabelPreview();
    if(!silent) showToast('Adres ayrıldı');
    return true;
  }

  function validateLabelAddress(){
    const err = document.getElementById('labelErr1');
    if(err) err.textContent = '';

    const required = [
      ['shipRecipient', 'Alıcı adı / firma adı eksik.'],
      ['shipPhone', 'Telefon eksik.'],
      ['shipMahalle', 'Mahalle eksik.'],
      ['shipStreet', 'Sokak / cadde eksik.'],
      ['shipCounty', 'İlçe eksik.'],
      ['shipCity', 'İl eksik.']
    ];

    for(const [id, msg] of required){
      if(!document.getElementById(id).value.trim()){
        if(err) err.textContent = msg;
        return false;
      }
    }

    return true;
  }

  function goLabelCargo(){
    parseShippingAddress(true);
    if(!validateLabelAddress()) return;
    showLabelScreen(3);
  }

  function pieceCount(){
    const el = document.getElementById('labelPieces');
    return Math.max(1, Math.min(20, Number(el && el.value ? el.value : 1)));
  }

  function ensureInvoiceRef(){
    const refEl = document.getElementById('shipRef');
    if(!refEl.value.trim()){
      refEl.value = 'PT-' + new Date().toISOString().slice(0,10).replace(/-/g,'') + '-' + Math.floor(1000 + Math.random() * 9000);
    }
    return refEl.value.trim();
  }

  function makePieceRefs(baseRef, total){
    const clean = String(baseRef || '').trim() || ensureInvoiceRef();
    const refs = [];
    for(let i = 1; i <= total; i++){
      refs.push(total > 1 ? `${clean}-${String(i).padStart(2,'0')}` : clean);
    }
    return refs;
  }

  function goLabelPreview(){
    const baseRef = ensureInvoiceRef();
    labelState.lastPieceRefs = makePieceRefs(baseRef, pieceCount());
    buildLabelPreview();
    showLabelScreen(4);
  }

  function labelAddressLines(){
    const mahalle = document.getElementById('shipMahalle').value.trim();
    const street = document.getElementById('shipStreet').value.trim();
    const no = document.getElementById('shipNo').value.trim();
    const extra = document.getElementById('shipExtra').value.trim();

    const first = [mahalle ? (/\bmah\b\.?/i.test(mahalle) ? mahalle : mahalle + ' Mah.') : '', street, no ? 'No: ' + no : ''].filter(Boolean).join(' ');
    return [first, extra].filter(Boolean).join('\n');
  }

  function paymentTypeText(){
    return labelState.payType === 'buyer' ? 'Alıcı ödemeli' : 'Satıcı ödemeli';
  }

  function qrPayload(pieceIndex, pieceTotal, pieceRef){
    const cargo = CARGO_LOGOS[labelState.carrier];
    const names = labelCustomerNameParts();
    return [
      'KARGO_ETIKET',
      'CARRIER=' + cargo.label,
      'AGREEMENT=' + (CARGO_AGREEMENT_CODES[labelState.carrier] || ''),
      'REF=' + (document.getElementById('shipRef').value.trim() || ''),
      'PIECE_REF=' + pieceRef,
      'PIECE=' + pieceIndex + '/' + pieceTotal,
      'PAYMENT=' + paymentTypeText(),
      'COMPANY=' + (names.company || ''),
      'CUSTOMER=' + (names.customer || ''),
      'TO=' + document.getElementById('shipRecipient').value.trim(),
      'PHONE=' + document.getElementById('shipPhone').value.trim(),
      'ADDRESS=' + labelAddressLines().replace(/\n/g, ' '),
      'DISTRICT=' + document.getElementById('shipCounty').value.trim(),
      'CITY=' + document.getElementById('shipCity').value.trim(),
      'POSTCODE=' + document.getElementById('shipPostcode').value.trim(),
      'SENDER_PHONE=' + SENDER_INFO.phone
    ].join('|');
  }

  function qrUrl(data){
    // Çevrimdışı QR üretimi (internet/harici servis gerektirmez, baskıda net çıkar).
    try {
      if (typeof QRLite !== 'undefined') {
        return QRLite.generateDataUri(data, { margin: 2, dark: '#000000', light: '#ffffff' });
      }
    } catch (e) { /* aşağıdaki yedeğe düş */ }
    // Yedek: harici servis (yalnızca QRLite yüklenmezse)
    return 'https://quickchart.io/qr?size=220&margin=1&text=' + encodeURIComponent(data);
  }

  function labelCustomerNameParts(){
    const selected = labelState.selectedCustomer || {};
    const company = String(selected.company_name || '').trim();
    const customer = String(selected.customer_name || selected.recipient || '').trim();
    const recipientField = document.getElementById('shipRecipient').value.trim();

    if(company && customer && company.toLocaleLowerCase('tr-TR') !== customer.toLocaleLowerCase('tr-TR')){
      return { company, customer };
    }

    if(company){
      return { company, customer: '' };
    }

    if(customer){
      return { company: '', customer };
    }

    return { company: '', customer: recipientField || 'ALICI' };
  }

  function buildLabelHtml(pieceIndex = 1, pieceTotal = pieceCount(), pieceRef = ''){
    const recipient = document.getElementById('shipRecipient').value.trim() || 'ALICI';
    const nameParts = labelCustomerNameParts();
    const companyName = nameParts.company;
    const customerName = nameParts.customer || (!companyName ? recipient : '');
    const phone = document.getElementById('shipPhone').value.trim();
    const city = document.getElementById('shipCity').value.trim();
    const county = document.getElementById('shipCounty').value.trim();
    const postcode = document.getElementById('shipPostcode').value.trim();
    const baseRef = document.getElementById('shipRef').value.trim() || '-';
    const ref = pieceRef || (pieceTotal > 1 ? `${baseRef}-${String(pieceIndex).padStart(2,'0')}` : baseRef);
    const cargo = CARGO_LOGOS[labelState.carrier];
    const logo = cargo[labelState.logoMode] || cargo.color;
    const agreementCode = CARGO_AGREEMENT_CODES[labelState.carrier] || '-';
    const qr = qrUrl(qrPayload(pieceIndex, pieceTotal, ref));
    const barcodeSvg = buildBarcodeSvg(ref);

    return `
      <div class="labelHead">
        <div class="labelBrand">
          <div class="brandLine">
            <img src="${PANEL_LOGO}" alt="Poyraz Toner">
            <span class="printLogoText brandPrintText">POYRAZ TONER</span>
          </div>
          <div class="dispatchText">İrsaliye yerine geçer</div>
        </div>
        <div class="cargoLogo">
          <img src="${logo}" alt="${cargo.label}">
          <span class="printLogoText cargoPrintText">${escapeHtml(cargo.label)}</span>
        </div>
      </div>

      <div class="senderBox">
        <span>Gönderici</span>
        <img class="senderLogo" src="${PANEL_LOGO}" alt="Poyraz Toner">
        <span class="printLogoText senderPrintText">POYRAZ TONER</span>
        <small>${escapeHtml(SENDER_INFO.address)} · ${escapeHtml(SENDER_INFO.phone)}</small>
      </div>

      <div class="labelBlock">
        <div class="labelSmallTitle">Alıcı</div>
        ${companyName ? `<div class="labelCompanyName">${escapeHtml(companyName)}</div>` : ''}
        ${customerName ? `<div class="labelCustomerName">${escapeHtml(customerName)}</div>` : ''}
        <div class="labelPhone">${escapeHtml(phone)}</div>
        <div class="labelAddress">${escapeHtml(labelAddressLines())}</div>
      </div>

      <div class="labelTopMeta">
        <div><span>İlçe</span><b>${escapeHtml(county || '-')}</b></div>
        <div><span>İl</span><b>${escapeHtml(city || '-')}</b></div>
        <div><span>Posta kodu</span><b>${escapeHtml(postcode || '-')}</b></div>
        <div><span>Ödeme</span><b>${escapeHtml(paymentTypeText())}</b></div>
        <div><span>Parça</span><b>${pieceIndex}/${pieceTotal}</b></div>
      </div>

      <div class="labelBottom">
        <div class="labelRefWrap">
          <div class="labelRef">
            <span>Fatura / Ref</span><strong>${escapeHtml(baseRef)}</strong>
            <span>Parça ref</span><strong>${escapeHtml(ref)} · ${escapeHtml(cargo.label)}</strong>
            <span class="contractInline">Anlaşma kodu: ${escapeHtml(agreementCode)}</span>
          </div>
          <div class="barcodeWrap">${barcodeSvg}<em>${escapeHtml(ref)}</em></div>
        </div>
        <div class="qrBox">
          <img class="qrImg" src="${qr}" alt="QR" crossorigin="anonymous">
          <span>${escapeHtml(cargo.label)}<br>QR</span>
        </div>
        <div class="labelPieceBadge">${pieceIndex}/${pieceTotal}</div>
      </div>
    `;
  }

  // Görsel amaçlı, ref stringinden üretilen deterministik çubuk barkod.
  // (Kargo firması gerçek veriyi QR'dan okur; bu satır barkod okunabilir bir görsel öğedir.)
  function buildBarcodeSvg(text){
    const s = String(text || '-');
    let bars = '';
    let x = 0;
    const widths = [1, 2, 3, 1, 2, 1, 3, 2];
    for (let i = 0; i < Math.max(24, s.length * 3); i++){
      const code = s.charCodeAt(i % s.length) + i * 7;
      const w = widths[(code) % widths.length];
      const isBar = ((code >> 1) & 1) === 0;
      if (isBar){ bars += `<rect x="${x}" y="0" width="${w}" height="100" fill="#111"/>`; }
      x += w + 1;
    }
    return `<svg class="barcodeSvg" viewBox="0 0 ${x} 100" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">${bars}</svg>`;
  }

  function escapeHtml(value){
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function buildAllLabelPages(){
    const paper = PAPER_SIZES[labelState.paper] || PAPER_SIZES['15x10'];
    const total = pieceCount();
    const baseRef = document.getElementById('shipRef').value.trim() || '-';
    const refs = makePieceRefs(baseRef, total);
    labelState.lastPieceRefs = refs;

    let html = '';
    for(let i = 1; i <= total; i++){
      html += `<div class="labelPaper ${paper.cls}" data-piece="${i}">${buildLabelHtml(i, total, refs[i - 1])}</div>`;
    }
    return html;
  }

  function buildLabelPreview(){
    const stack = document.getElementById('labelPreviewStack');
    if(!stack) return;
    stack.innerHTML = buildAllLabelPages();

    const summary = document.getElementById('labelPrintSummary');
    if(summary){
      const paper = PAPER_SIZES[labelState.paper] || PAPER_SIZES['15x10'];
      summary.innerHTML = `
        <span>${paper.label}</span>
        <span>${pieceCount()} parça</span>
        <span>${CARGO_LOGOS[labelState.carrier].label}</span>
        <span>${paymentTypeText()}</span>
      `;
    }
  }

  function labelPayload(){
    const total = pieceCount();
    const baseRef = ensureInvoiceRef();
    const refs = makePieceRefs(baseRef, total);
    labelState.lastPieceRefs = refs;

    return {
      created_by: state.userName || '',
      customer_id: labelState.selectedCustomer ? (labelState.selectedCustomer.id || null) : null,
      customer_name: labelCustomerNameParts().customer || '',
      company_name: labelCustomerNameParts().company || '',
      address_mode: labelState.addressMode,
      carrier: labelState.carrier,
      carrier_label: CARGO_LOGOS[labelState.carrier].label,
      cargo_agreement_code: CARGO_AGREEMENT_CODES[labelState.carrier] || '',
      sender_name: SENDER_INFO.name,
      sender_address: SENDER_INFO.address,
      sender_phone: SENDER_INFO.phone,
      logo_mode: labelState.logoMode,
      payment_type: labelState.payType,
      payment_label: paymentTypeText(),
      paper: labelState.paper,
      paper_label: (PAPER_SIZES[labelState.paper] || PAPER_SIZES['15x10']).label,
      invoice_ref: baseRef,
      piece_total: total,
      piece_refs: refs,
      recipient: document.getElementById('shipRecipient').value.trim(),
      phone: document.getElementById('shipPhone').value.trim(),
      raw_address: document.getElementById('rawAddress').value.trim(),
      mahalle: document.getElementById('shipMahalle').value.trim(),
      street: document.getElementById('shipStreet').value.trim(),
      door_no: document.getElementById('shipNo').value.trim(),
      postcode: document.getElementById('shipPostcode').value.trim(),
      county: document.getElementById('shipCounty').value.trim(),
      city: document.getElementById('shipCity').value.trim(),
      extra: document.getElementById('shipExtra').value.trim(),
      qr_payloads: refs.map((ref, idx) => qrPayload(idx + 1, total, ref))
    };
  }

  async function saveCustomerFromLabel(payload){
    const customerData = {
      id: labelState.selectedCustomer ? labelState.selectedCustomer.id : null,
      customer_name: payload.customer_name || payload.recipient,
      company_name: payload.company_name || '',
      phone: payload.phone,
      raw_address: payload.raw_address,
      mahalle: payload.mahalle,
      street: payload.street,
      door_no: payload.door_no,
      postcode: payload.postcode,
      county: payload.county,
      city: payload.city,
      extra: payload.extra
    };

    const remote = await apiCustomers('save', customerData);
    if(remote && remote.customer){
      labelState.selectedCustomer = remote.customer;
      return remote.customer;
    }

    const list = getLocalCustomers();
    const existingIndex = list.findIndex(c => String(c.id) === String(customerData.id) || (c.phone && c.phone === customerData.phone && customerData.phone));
    const local = { ...customerData, id: customerData.id || 'local-' + Date.now() };
    if(existingIndex >= 0) list[existingIndex] = { ...list[existingIndex], ...local };
    else list.push(local);
    setLocalCustomers(list);
    return local;
  }

  async function saveShippingLabelLog(payload){
    const info = document.getElementById('labelDbInfo');
    if(info) info.textContent = labelState.editingLabelId ? 'Kayıt güncelleniyor...' : 'Kayıt hazırlanıyor...';

    await saveCustomerFromLabel(payload);

    try{
      const isUpdate = !!labelState.editingLabelId;
      const url = isUpdate ? 'api/shipping_label_records.php?action=update' : 'api/shipping_label_save.php';
      const body = isUpdate ? { id: labelState.editingLabelId, payload } : payload;

      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      });

      const data = await res.json().catch(() => ({}));

      if(!res.ok || !data.ok){
        throw new Error(data.message || 'DB kaydı başarısız');
      }

      if(data.id) labelState.editingLabelId = data.id;
      if(info) {
        info.innerHTML = `<span class="editingBadge">✓ ${isUpdate ? 'Kayıt güncellendi' : 'DB kaydı tamamlandı'} — ID: ${escapeHtml(data.id || labelState.editingLabelId || '')}</span>`;
      }

      if(document.querySelector('[data-label-screen="5"]')?.classList.contains('active')) loadShippingLabelRecords();
      return true;
    }catch(e){
      try{
        const list = JSON.parse(localStorage.getItem('shippingLabelLogs') || '[]');
        list.push({ saved_offline: true, payload, created_at: new Date().toISOString() });
        localStorage.setItem('shippingLabelLogs', JSON.stringify(list.slice(-100)));
      }catch(localError){}

      if(info) info.textContent = 'DB kaydı yapılamadı; yerel loga alındı.';
      return false;
    }
  }

  async function saveShippingLabelOnly(){
    parseShippingAddress(true);
    if(!validateLabelAddress()) {
      showLabelScreen(2);
      return;
    }

    const payload = labelPayload();
    const ok = await saveShippingLabelLog(payload);
    if(ok) showToast('Kargo etiketi kaydedildi');
  }

  function waitForPrintAssets(container, timeoutMs = 4500){
    const images = Array.from(container.querySelectorAll('img')).filter(img => img.src);

    if(!images.length){
      return Promise.resolve();
    }

    const waits = images.map(img => new Promise(resolve => {
      if(img.complete && img.naturalWidth > 0){
        resolve(true);
        return;
      }

      const timer = setTimeout(() => resolve(false), timeoutMs);
      img.onload = () => {
        clearTimeout(timer);
        resolve(true);
      };
      img.onerror = () => {
        clearTimeout(timer);
        img.classList.add('printImageFailed');
        resolve(false);
      };

      // Bazı tarayıcılarda print öncesi lazy/cache kırılması için src tekrar tetiklenir.
      const currentSrc = img.src;
      img.src = currentSrc;
    }));

    return Promise.all(waits);
  }

  async function printCurrentLabelWithoutSaving(){
    const paper = PAPER_SIZES[labelState.paper] || PAPER_SIZES['15x10'];
    const printArea = document.getElementById('printArea');
    printArea.innerHTML = buildAllLabelPages();

    document.documentElement.style.setProperty('--print-w', paper.w);
    document.documentElement.style.setProperty('--print-h', paper.h);
    document.documentElement.style.setProperty('--print-pad', paper.pad);

    let printStyle = document.getElementById('dynamicPrintPage');
    if(!printStyle){
      printStyle = document.createElement('style');
      printStyle.id = 'dynamicPrintPage';
      document.head.appendChild(printStyle);
    }
    printStyle.textContent = `@page{size:${paper.page};margin:0}`;

    await waitForPrintAssets(printArea, 4500);

    document.body.classList.add('printLabel');
    setTimeout(() => window.print(), 220);
    setTimeout(() => document.body.classList.remove('printLabel'), 1200);
  }

  async function printShippingLabel(){
    parseShippingAddress(true);
    if(!validateLabelAddress()) {
      showLabelScreen(2);
      return;
    }

    const payload = labelPayload();
    await saveShippingLabelLog(payload);
    await printCurrentLabelWithoutSaving();
  }

  function fillLabelFromPayload(payload){
    if(!payload) return;

    labelState.carrier = payload.carrier || 'dhl';
    labelState.logoMode = payload.logo_mode || 'color';
    labelState.payType = payload.payment_type || 'seller';
    labelState.paper = payload.paper || '15x10';
    labelState.addressMode = payload.address_mode || 'same';
    labelState.selectedCustomer = {
      id: payload.customer_id || null,
      customer_name: payload.customer_name || '',
      company_name: payload.company_name || '',
      recipient: payload.recipient || '',
      phone: payload.phone || '',
      raw_address: payload.raw_address || '',
      mahalle: payload.mahalle || '',
      street: payload.street || '',
      door_no: payload.door_no || '',
      postcode: payload.postcode || '',
      county: payload.county || '',
      city: payload.city || '',
      extra: payload.extra || ''
    };

    setField('shipRecipient', payload.recipient || payload.customer_name || payload.company_name || '', true);
    setField('shipPhone', payload.phone || '', true);
    setField('rawAddress', payload.raw_address || '', true);
    setField('shipMahalle', payload.mahalle || '', true);
    setField('shipStreet', payload.street || '', true);
    setField('shipNo', payload.door_no || '', true);
    setField('shipPostcode', payload.postcode || '', true);
    setField('shipCounty', payload.county || '', true);
    setField('shipCity', payload.city || '', true);
    setField('shipExtra', payload.extra || '', true);
    setField('shipRef', payload.invoice_ref || '', true);
    setField('labelPieces', String(payload.piece_total || 1), true);

    syncLabelChoiceButtons();
    renderSelectedCustomer();
    buildLabelPreview();
  }

  async function fetchShippingRecord(id){
    const res = await fetch('api/shipping_label_records.php?action=get&id=' + encodeURIComponent(id), { cache: 'no-store' });
    const data = await res.json().catch(() => ({}));
    if(!res.ok || !data.ok) throw new Error(data.message || 'Kayıt alınamadı');
    return data.record;
  }

  async function editSavedLabel(id){
    try{
      const record = await fetchShippingRecord(id);
      labelState.editingLabelId = record.id;
      fillLabelFromPayload(record.payload);
      showModule('label');
      showLabelScreen(2);
      document.getElementById('labelDbInfo').innerHTML = `<span class="editingBadge">Düzenleme modu — ID: ${escapeHtml(record.id)}</span>`;
      showToast('Kayıt düzenlemeye açıldı');
    }catch(e){
      showToast('Kayıt açılamadı');
    }
  }

  async function printSavedLabel(id){
    try{
      const currentEditing = labelState.editingLabelId;
      const record = await fetchShippingRecord(id);
      labelState.editingLabelId = record.id;
      const selectedPaper = selectedPaperForRecord(id);
      fillLabelFromPayload({ ...record.payload, paper: selectedPaper || record.payload.paper });
      showLabelScreen(4);
      await printCurrentLabelWithoutSaving();
      labelState.editingLabelId = currentEditing;
    }catch(e){
      showToast('Yazdırma kaydı alınamadı');
    }
  }

  async function deleteSavedLabel(id){
    if(!confirm('Bu kargo etiketi kaydı silinsin mi?')) return;

    try{
      const res = await fetch('api/shipping_label_records.php?action=delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id })
      });
      const data = await res.json().catch(() => ({}));
      if(!res.ok || !data.ok) throw new Error(data.message || 'Silinemedi');
      if(String(labelState.editingLabelId || '') === String(id)) labelState.editingLabelId = null;
      showToast('Kayıt silindi');
      loadShippingLabelRecords();
    }catch(e){
      showToast('Kayıt silinemedi');
    }
  }

  function paperSelectOptions(selectedPaper){
    return Object.entries(PAPER_SIZES).map(([key, p]) => {
      const selected = key === selectedPaper ? 'selected' : '';
      return `<option value="${escapeHtml(key)}" ${selected}>${escapeHtml(p.label)}</option>`;
    }).join('');
  }

  function renderShippingRecords(records){
    const holder = document.getElementById('shippingRecordsList');
    if(!holder) return;

    labelState.recordsCache = records || [];
    document.getElementById('bulkSelectAllLabels') && (document.getElementById('bulkSelectAllLabels').checked = false);
    updateBulkPrintSelectedInfo();

    if(!records || !records.length){
      holder.innerHTML = '<div class="labelHint">Henüz kayıt yok.</div>';
      return;
    }

    holder.innerHTML = records.map(r => {
      const title = [r.company_name, r.customer_name || r.recipient].filter(Boolean).join(' / ') || r.recipient || 'Alıcı';
      const address = [r.mahalle, r.street, r.door_no ? 'No: ' + r.door_no : '', r.county, r.city].filter(Boolean).join(' ');
      const paper = r.paper || '15x10';
      return `
        <div class="shippingRecord" data-record-id="${Number(r.id)}">
          <label class="recordCheck" title="Toplu yazdırma için seç">
            <input type="checkbox" class="recordSelect" value="${Number(r.id)}" onchange="updateBulkPrintSelectedInfo()" />
          </label>

          <div class="shippingRecordMain">
            <b>${escapeHtml(title)}</b>
            <span>Ref: ${escapeHtml(r.invoice_ref || '-')} · ${escapeHtml(r.carrier_label || r.carrier || '-')} · ${escapeHtml(r.paper_label || r.paper || '-')} · ${escapeHtml(r.piece_total || 1)} parça</span>
            <span>${escapeHtml(r.phone || '')} · ${escapeHtml(address)}</span>
            <span>${escapeHtml(r.created_at || '')}</span>
            <div class="recordPrintType">
              <span>Yazdırma türü:</span>
              <select class="recordPaperSelect" data-record-id="${Number(r.id)}" onchange="updateBulkPrintSelectedInfo()">
                ${paperSelectOptions(paper)}
              </select>
            </div>
          </div>

          <div class="shippingRecordActions">
            <button type="button" class="primary" onclick="printSavedLabel(${Number(r.id)})">Yazdır</button>
            <button type="button" class="ghost" onclick="editSavedLabel(${Number(r.id)})">Düzenle</button>
            <button type="button" class="dangerBtn" onclick="deleteSavedLabel(${Number(r.id)})">Sil</button>
          </div>
        </div>
      `;
    }).join('');
  }

  function selectedShippingRecordIds(){
    return Array.from(document.querySelectorAll('.recordSelect:checked')).map(el => Number(el.value)).filter(Boolean);
  }

  function selectedPaperForRecord(id){
    return document.querySelector(`.recordPaperSelect[data-record-id="${Number(id)}"]`)?.value || '15x10';
  }

  function updateBulkPrintSelectedInfo(){
    const info = document.getElementById('bulkPrintSelectedInfo');
    if(!info) return;
    const ids = selectedShippingRecordIds();
    if(!ids.length){
      info.textContent = 'Seçili etiket yok.';
      return;
    }

    const paperCounts = ids.reduce((acc, id) => {
      const paper = selectedPaperForRecord(id);
      const label = (PAPER_SIZES[paper] || PAPER_SIZES['15x10']).label;
      acc[label] = (acc[label] || 0) + 1;
      return acc;
    }, {});

    info.textContent = `${ids.length} etiket seçildi · ` + Object.entries(paperCounts).map(([k,v]) => `${v} adet ${k}`).join(', ');
  }

  function toggleAllShippingRecords(checked){
    document.querySelectorAll('.recordSelect').forEach(el => el.checked = !!checked);
    updateBulkPrintSelectedInfo();
  }

  function buildLabelPagesForPayload(payload, paperKey){
    const originalPaper = labelState.paper;
    const originalCustomer = labelState.selectedCustomer;
    const originalAddressMode = labelState.addressMode;
    const originalCarrier = labelState.carrier;
    const originalLogoMode = labelState.logoMode;
    const originalPayType = labelState.payType;

    fillLabelFromPayload({ ...payload, paper: paperKey || payload.paper || '15x10' });
    const html = buildAllLabelPages();

    labelState.paper = originalPaper;
    labelState.selectedCustomer = originalCustomer;
    labelState.addressMode = originalAddressMode;
    labelState.carrier = originalCarrier;
    labelState.logoMode = originalLogoMode;
    labelState.payType = originalPayType;
    syncLabelChoiceButtons();

    return html;
  }

  async function bulkPrintSelectedLabels(){
    const ids = selectedShippingRecordIds();

    if(!ids.length){
      showToast('Önce etiket seç');
      return;
    }

    const groups = {};

    try{
      for(const id of ids){
        const paper = selectedPaperForRecord(id);
        const record = await fetchShippingRecord(id);
        if(!groups[paper]) groups[paper] = [];
        groups[paper].push(record.payload);
      }

      const paperKeys = Object.keys(groups);

      if(paperKeys.length > 1){
        alert('Farklı yazdırma türleri seçildi. Tarayıcı her ölçü için ayrı yazdırma penceresi açabilir. Sırayla onayla.');
      }

      for(let i = 0; i < paperKeys.length; i++){
        const paperKey = paperKeys[i];
        const paper = PAPER_SIZES[paperKey] || PAPER_SIZES['15x10'];
        const printArea = document.getElementById('printArea');
        printArea.innerHTML = groups[paperKey].map(payload => buildLabelPagesForPayload(payload, paperKey)).join('');

        document.documentElement.style.setProperty('--print-w', paper.w);
        document.documentElement.style.setProperty('--print-h', paper.h);
        document.documentElement.style.setProperty('--print-pad', paper.pad);

        let printStyle = document.getElementById('dynamicPrintPage');
        if(!printStyle){
          printStyle = document.createElement('style');
          printStyle.id = 'dynamicPrintPage';
          document.head.appendChild(printStyle);
        }
        printStyle.textContent = `@page{size:${paper.page};margin:0}`;

        await waitForPrintAssets(printArea, 4500);

        document.body.classList.add('printLabel');
        await new Promise(resolve => setTimeout(resolve, 220));
        window.print();
        await new Promise(resolve => setTimeout(resolve, 1200));
        document.body.classList.remove('printLabel');
      }
    }catch(e){
      document.body.classList.remove('printLabel');
      showToast('Toplu yazdırma hatası');
    }
  }


  async function loadShippingLabelRecords(){
    const holder = document.getElementById('shippingRecordsList');
    if(!holder) return;

    const q = document.getElementById('labelRecordSearch')?.value.trim() || '';
    holder.innerHTML = '<div class="labelHint">Kayıtlar yükleniyor...</div>';

    try{
      const res = await fetch('api/shipping_label_records.php?action=list&q=' + encodeURIComponent(q), { cache: 'no-store' });
      const data = await res.json().catch(() => ({}));
      if(!res.ok || !data.ok) throw new Error(data.message || 'Kayıtlar alınamadı');
      renderShippingRecords(data.records || []);
    }catch(e){
      holder.innerHTML = '<div class="labelHint">Kayıtlar alınamadı. API veya DB bağlantısını kontrol et.</div>';
    }
  }


  document.querySelectorAll('#labelCarrierChoice button').forEach(btn => {
    btn.addEventListener('click', () => {
      labelState.carrier = btn.dataset.labelCarrier;
      document.querySelectorAll('#labelCarrierChoice button').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      buildLabelPreview();
    });
  });

  document.querySelectorAll('#labelLogoMode button').forEach(btn => {
    btn.addEventListener('click', () => {
      labelState.logoMode = btn.dataset.logoMode;
      document.querySelectorAll('#labelLogoMode button').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      buildLabelPreview();
    });
  });

  document.querySelectorAll('#labelPayChoice button').forEach(btn => {
    btn.addEventListener('click', () => {
      labelState.payType = btn.dataset.labelPay;
      document.querySelectorAll('#labelPayChoice button').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      buildLabelPreview();
    });
  });

  document.querySelectorAll('#paperChoice button').forEach(btn => {
    btn.addEventListener('click', () => {
      labelState.paper = btn.dataset.paper;
      document.querySelectorAll('#paperChoice button').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      buildLabelPreview();
    });
  });

  ['rawAddress','shipRecipient','shipPhone','shipMahalle','shipStreet','shipNo','shipPostcode','shipCounty','shipCity','shipExtra','shipRef','labelPieces','newCustomerRawAddress','newCustomerName','newCustomerCompany','newCustomerPhone','newCustomerNote','newCustomerMahalle','newCustomerStreet','newCustomerNo','newCustomerPostcode','newCustomerCounty','newCustomerCity','newCustomerExtra'].forEach(id => {
    const el = document.getElementById(id);
    if(!el) return;
    el.addEventListener('input', () => {
      if(id === 'rawAddress') parseShippingAddress(true);
      if(id === 'newCustomerRawAddress') parseNewCustomerAddress(true);
      buildLabelPreview();
    });
    el.addEventListener('paste', () => {
      setTimeout(() => {
        if(id === 'rawAddress') parseShippingAddress(true);
      if(id === 'newCustomerRawAddress') parseNewCustomerAddress(true);
        buildLabelPreview();
      }, 20);
    });
  });

  let customerSearchTimer = null;
  document.getElementById('customerSearch')?.addEventListener('keydown', e => {
    if(e.key === 'Enter') searchCustomers({ keepSelection: false });
  });

  document.getElementById('customerSearch')?.addEventListener('input', e => {
    clearTimeout(customerSearchTimer);
    customerSearchTimer = setTimeout(() => {
      const value = e.target.value.trim();

      // Arama kutusu boşaltılınca sadece müşteri listesi gelsin, hiçbir müşteri seçili olmasın.
      if(value === ''){
        searchCustomers({ keepSelection: false });
      }
    }, 180);
  });

  let labelRecordTimer = null;
  document.getElementById('labelRecordSearch')?.addEventListener('input', () => {
    clearTimeout(labelRecordTimer);
    labelRecordTimer = setTimeout(loadShippingLabelRecords, 250);
  });


  initUserGate();
  

  function bulkRate(currency){
    return currency === 'TL' ? 1 : Number(SETTINGS.rates[currency] || 1);
  }

  function bulkSarfTry(){
    return SETTINGS.sarfExpenseUsd * Number(SETTINGS.rates.USD || 0);
  }

  function normalizeBulkCurrency(value){
    const v = String(value || '').trim().toUpperCase();
    if(['TL','TRY','₺'].includes(v)) return 'TL';
    if(['USD','$','DOLAR','DOLLAR'].includes(v)) return 'USD';
    if(['EUR','€','EURO'].includes(v)) return 'EUR';
    return document.getElementById('bulkDefaultCurrency')?.value || 'TL';
  }

  function normalizeBulkCarrier(value){
    const v = String(value || '').trim().toLocaleLowerCase('tr-TR');
    if(['dhl'].includes(v)) return 'dhl';
    if(['aras','aras kargo'].includes(v)) return 'aras';
    if(['hepsijet','hepsi jet','hj'].includes(v)) return 'hepsijet';
    return document.getElementById('bulkDefaultCarrier')?.value || 'hepsijet';
  }

  function normalizeBulkPayment(value){
    const v = String(value || '').trim().toLocaleLowerCase('tr-TR').replace(/\s+/g, '');
    const map = {
      'card':'card',
      'kart':'card',
      'kredikartı':'card',
      'kredikarti':'card',
      'eft':'eft',
      'havale':'eft',
      'havale/eft':'eft',
      'term30':'term30',
      '30':'term30',
      '30gun':'term30',
      '30gün':'term30',
      'term60':'term60',
      '60':'term60',
      '60gun':'term60',
      '60gün':'term60',
      'installment2':'installment2',
      '2taksit':'installment2',
      'taksit2':'installment2'
    };
    return map[v] || document.getElementById('bulkDefaultPayment')?.value || 'card';
  }

  function bulkPaymentLabel(code){
    const prev = state.payment;
    state.payment = code;
    const label = paymentLabel();
    state.payment = prev;
    return label;
  }

  function bulkCargoTryByDesi(carrierCode, desi){
    if(!desi || desi <= 0) return 0;
    const carrier = SETTINGS.carriers[carrierCode] || SETTINGS.carriers.hepsijet;
    const ds = Math.ceil(desi);
    const row = carrier.table.find(x => ds >= x.min && ds <= x.max);
    if(row) return row.price * carrier.multiplier;
    if(carrier.extraAfter && carrier.extraPerDesi && ds > carrier.extraAfter){
      const baseRow = carrier.table.find(x => carrier.extraAfter >= x.min && carrier.extraAfter <= x.max);
      if(baseRow) return (baseRow.price + ((ds - carrier.extraAfter) * carrier.extraPerDesi)) * carrier.multiplier;
    }
    if(carrier.maxFallback !== null) return carrier.maxFallback * carrier.multiplier;
    return null;
  }

  function solveBulkSale(baseTry, marginPct, paymentCode){
    const targetNetProfitTry = baseTry * (marginPct / 100);
    const targetBeforeDeductionsTry = baseTry + targetNetProfitTry;
    let saleExTry, saleIncTry, collectedExTry, collectedIncTry, commissionTry;
    let termCostTry = 0;
    let installmentExtraLossTry = 0;

    if(paymentCode === 'card' || paymentCode === 'installment2'){
      const commissionRate = paymentCode === 'installment2' ? SETTINGS.installment2Commission : SETTINGS.cardCommission;
      saleExTry = targetBeforeDeductionsTry / (1 - (commissionRate * (1 + SETTINGS.vat)));
      saleIncTry = saleExTry * (1 + SETTINGS.vat);
      collectedExTry = saleExTry;
      collectedIncTry = saleIncTry;
      commissionTry = saleIncTry * commissionRate;
      installmentExtraLossTry = paymentCode === 'installment2'
        ? saleIncTry * Math.max(0, SETTINGS.installment2Commission - SETTINGS.cardCommission)
        : 0;
    }else if(paymentCode === 'eft'){
      saleExTry = targetBeforeDeductionsTry / (1 - SETTINGS.eftDiscount);
      saleIncTry = saleExTry * (1 + SETTINGS.vat);
      collectedExTry = saleExTry * (1 - SETTINGS.eftDiscount);
      collectedIncTry = saleIncTry * (1 - SETTINGS.eftDiscount);
      commissionTry = 0;
    }else{
      const termRate = paymentCode === 'term60' ? SETTINGS.term60Cost : SETTINGS.term30Cost;
      saleExTry = targetBeforeDeductionsTry / (1 - termRate);
      saleIncTry = saleExTry * (1 + SETTINGS.vat);
      collectedExTry = saleExTry;
      collectedIncTry = saleIncTry;
      commissionTry = 0;
      termCostTry = saleExTry * termRate;
    }

    const netProfitTry = collectedExTry - baseTry - commissionTry - termCostTry;
    return { saleExTry, saleIncTry, collectedExTry, collectedIncTry, commissionTry, termCostTry, installmentExtraLossTry, netProfitTry };
  }

  // ====== YENİ TOPLU FİYAT: T-Soft kart listesi (v78) ======
  let bulkCards = [];

  function bulkDefaults(){
    return {
      profit: Number(document.getElementById('bulkDefaultProfit')?.value || 20),
      carrier: document.getElementById('bulkDefaultCarrier')?.value || 'hepsijet',
      payment: document.getElementById('bulkDefaultPayment')?.value || 'card'
    };
  }

  function addBulkCard(product){
    const currency = normalizeBulkCurrency(product.currency_code || product.currency || 'TL');
    const desi = Number(product.desi || 0) > 0 ? Number(product.desi) : 1;
    bulkCards.push({
      name: product.product_name || product.name || 'T-Soft Ürün',
      code: product.ws_product_code || '',
      barcode: product.barcode || '',
      cost: Number(product.purchase_price || 0),
      tsoftSale: Number(product.sale_price || 0),
      currency,
      desi,
      stock: Number(product.stock || 0)
    });
    recalcBulkCards();
  }

  function removeBulkCard(index){
    bulkCards.splice(index, 1);
    recalcBulkCards();
    showToast('Ürün listeden çıkarıldı');
  }

  function clearBulkCards(){
    if(bulkCards.length && !confirm('Tüm ürünler listeden silinsin mi?')) return;
    bulkCards = [];
    recalcBulkCards();
  }

  function recalcBulkCards(){
    const d = bulkDefaults();
    window.__bulkPriceResults = bulkCards.map(card => {
      const row = {
        name: card.name, cost: card.cost, currency: card.currency,
        profit: d.profit, desi: card.desi, carrier: d.carrier, payment: d.payment,
        tsoftSale: card.tsoftSale, wsProductCode: card.code, barcode: card.barcode, stock: card.stock
      };
      if(!card.cost || card.cost <= 0) return { row, error: 'Alış fiyatı yok' };
      try { return { row, result: computeBulkPrice(row) }; }
      catch(e){ return { row, error: 'Hesaplanamadı' }; }
    });
    renderBulkCards();
  }

  function renderBulkCards(){
    const list = document.getElementById('bulkCardList');
    const empty = document.getElementById('bulkEmptyState');
    const head = document.getElementById('bulkCardsHead');
    const count = document.getElementById('bulkCardsCount');
    if(!list) return;

    if(!bulkCards.length){
      list.innerHTML = '';
      if(empty){ list.appendChild(empty); empty.style.display = ''; }
      else list.innerHTML = '<div class="bulkEmptyState" id="bulkEmptyState"><div class="bulkEmptyIcon">📦</div><b>Henüz ürün eklenmedi</b><span>“T-Soft\'tan Ürün Ekle” ile ürün seç.</span></div>';
      if(head) head.style.display = 'none';
      return;
    }

    if(empty) empty.style.display = 'none';
    if(head) head.style.display = '';
    if(count) count.textContent = bulkCards.length + ' ürün';

    const results = window.__bulkPriceResults || [];
    const sym = SETTINGS.symbols;

    list.innerHTML = results.map((item, i) => {
      const c = bulkCards[i];
      const cur = c.currency;
      const money = (v) => (sym[cur] || '') + Number(v || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      const moneyTL = (vTry) => '₺' + Number(vTry || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

      const meta = `
        <span class="bpMeta">${escapeHtml(c.code || '-')}</span>
        ${c.barcode ? `<span class="bpMeta">${escapeHtml(c.barcode)}</span>` : ''}
        <span class="bpMeta">Desi: ${Number(c.desi).toLocaleString('tr-TR',{maximumFractionDigits:2})}</span>
        <span class="bpMeta">Stok: ${Number(c.stock).toLocaleString('tr-TR',{maximumFractionDigits:2})}</span>`;

      if(item.error){
        return `
        <div class="bulkCard bulkCardError">
          <div class="bpLeft">
            <div class="bpName">${escapeHtml(c.name)}</div>
            <div class="bpMetaRow">${meta}</div>
          </div>
          <div class="bpErr">${escapeHtml(item.error)}</div>
          <button type="button" class="bpRemove" onclick="removeBulkCard(${i})" aria-label="Kaldır">×</button>
        </div>`;
      }

      const out = item.result;
      const showTL = cur !== 'TL';
      return `
        <div class="bulkCard">
          <div class="bpLeft">
            <div class="bpName">${escapeHtml(c.name)}</div>
            <div class="bpMetaRow">${meta}</div>
          </div>
          <div class="bpPrices">
            <div class="bpCol">
              <span>Alış (${escapeHtml(cur)})</span>
              <b>${money(c.cost)}</b>
              ${showTL ? `<em>${moneyTL(out.costTry)}</em>` : ''}
            </div>
            <div class="bpCol bpCol-tsoft">
              <span>T-Soft satış</span>
              <b>${c.tsoftSale ? money(c.tsoftSale) : '-'}</b>
            </div>
            <div class="bpCol bpCol-main">
              <span>KDV hariç satış</span>
              <b>${moneyTL(out.saleExTry)}</b>
            </div>
            <div class="bpCol bpCol-main">
              <span>KDV dahil satış</span>
              <b>${moneyTL(out.saleIncTry)}</b>
            </div>
            <div class="bpCol bpCol-profit">
              <span>Net kâr</span>
              <b>${moneyTL(out.netProfitTry)}</b>
            </div>
          </div>
          <button type="button" class="bpRemove" onclick="removeBulkCard(${i})" aria-label="Kaldır">×</button>
        </div>`;
    }).join('');
  }

  function computeBulkPrice(row){
    const rateValue = bulkRate(row.currency);
    const costTry = (Number(row.cost || 0) * rateValue) + bulkSarfTry();

    const smallCargoTry = SETTINGS.underThresholdCargoTry * rateValue;
    const preliminary = solveBulkSale(costTry + smallCargoTry, row.profit, row.payment);
    const overThreshold = preliminary.saleIncTry >= SETTINGS.freeCargoThresholdTry;
    const realCargoTry = overThreshold ? bulkCargoTryByDesi(row.carrier, row.desi) : smallCargoTry;
    const cargoTry = realCargoTry === null ? 0 : realCargoTry;
    const solved = solveBulkSale(costTry + cargoTry, row.profit, row.payment);
    const customerCargoTry = solved.saleIncTry >= SETTINGS.freeCargoThresholdTry ? 0 : bulkCargoTryByDesi(row.carrier, row.desi);
    const customerTotalExTry = customerCargoTry === null ? null : solved.collectedExTry + (customerCargoTry > 0 ? customerCargoTry : 0);

    return {
      ...solved,
      costTry,
      cargoTry,
      customerCargoTry,
      customerTotalExTry,
      invalidCargo: realCargoTry === null || customerCargoTry === null,
      overThreshold: solved.saleIncTry >= SETTINGS.freeCargoThresholdTry
    };
  }

  function splitBulkLine(line){
    if(line.includes(';')) return line.split(';');
    if(line.includes('\t')) return line.split('\t');
    return line.split(',');
  }

  function parseBulkPriceRows(){
    const text = document.getElementById('bulkPriceRows')?.value || '';
    const defaultCurrency = document.getElementById('bulkDefaultCurrency')?.value || 'TL';
    const defaultProfit = Math.max(5, Math.min(100, Number(document.getElementById('bulkDefaultProfit')?.value || 20)));
    const defaultDesi = Number(document.getElementById('bulkDefaultDesi')?.value || 0);
    const defaultCarrier = document.getElementById('bulkDefaultCarrier')?.value || 'hepsijet';
    const defaultPayment = document.getElementById('bulkDefaultPayment')?.value || 'card';

    return text.split(/\n+/)
      .map((line, index) => ({ line: line.trim(), index: index + 1 }))
      .filter(item => item.line && !item.line.startsWith('#'))
      .map(item => {
        const parts = splitBulkLine(item.line).map(p => p.trim());

        let name = parts[0] || '';
        let costText = parts[1] || '';

        // Sadece maliyet girildiyse
        if(parts.length === 1 && parseLocaleNumber(parts[0]) > 0){
          name = 'Ürün ' + item.index;
          costText = parts[0];
        }

        const cost = parseLocaleNumber(costText);
        const currency = normalizeBulkCurrency(parts[2] || defaultCurrency);
        const profit = Math.max(5, Math.min(100, Number(parseLocaleNumber(parts[3] || defaultProfit) || defaultProfit)));
        const desi = Number(parseLocaleNumber(parts[4] || defaultDesi) || 0);
        const carrier = normalizeBulkCarrier(parts[5] || defaultCarrier);
        const payment = normalizeBulkPayment(parts[6] || defaultPayment);
        const salePrice = parseLocaleNumber(parts[7] || 0) || 0;
        const wsProductCode = parts[8] || '';
        const barcode = parts[9] || '';
        const stock = parseLocaleNumber(parts[10] || 0) || 0;
        const isTsoft = Boolean(salePrice || wsProductCode || barcode);

        return {
          lineNo: item.index,
          name: name || ('Ürün ' + item.index),
          cost,
          currency,
          profit,
          desi,
          carrier,
          payment,
          salePrice,
          wsProductCode,
          barcode,
          stock,
          isTsoft,
          raw: item.line
        };
      });
  }

  function calculateBulkPrices(){
    const rows = parseBulkPriceRows();
    const holder = document.getElementById('bulkPriceResults');
    const info = document.getElementById('bulkPriceInfo');

    if(!rows.length){
      holder.innerHTML = '<div class="labelHint">Hesaplanacak satır yok.</div>';
      if(info) info.textContent = 'Satır gir.';
      window.__bulkPriceResults = [];
      return;
    }

    const results = rows.map(row => {
      if(!row.cost || row.cost <= 0){
        return { row, error: 'Maliyet eksik' };
      }

      try{
        return { row, result: computeBulkPrice(row) };
      }catch(e){
        return { row, error: 'Hesaplanamadı' };
      }
    });

    window.__bulkPriceResults = results;

    holder.innerHTML = `
      <div class="bulkTableScroller">
        <table class="bulkResultTable">
          <thead>
            <tr>
              <th>#</th>
              <th>Ürün</th>
              <th>Maliyet</th>
              <th>Para</th>
              <th>Kâr</th>
              <th>Desi</th>
              <th>Kargo</th>
              <th>Ödeme</th>
              <th>T-Soft Satış</th>
              <th>Kod / Barkod</th>
              <th>Stok</th>
              <th>KDV Hariç</th>
              <th>KDV Dahil</th>
              <th>Alınacak KDV Hariç</th>
              <th>Net Kâr</th>
              <th>Not</th>
            </tr>
          </thead>
          <tbody>
            ${results.map((item, idx) => {
              const r = item.row;
              if(item.error){
                return `<tr class="bulkError"><td>${idx + 1}</td><td>${escapeHtml(r.name)}</td><td colspan="14">${escapeHtml(item.error)}</td></tr>`;
              }

              const out = item.result;
              const customerTotal = out.customerTotalExTry === null ? 'Fiyat yok' : moneyTryToCurrency(out.customerTotalExTry, r.currency);
              const note = out.invalidCargo ? 'Kargo fiyatı yok' : (out.customerCargoTry === 0 ? '15.000 TL üstü kargo bedava' : '');
              return `
                <tr>
                  <td>${idx + 1}</td>
                  <td><b>${escapeHtml(r.name)}</b></td>
                  <td>${Number(r.cost).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                  <td>${escapeHtml(r.currency)}</td>
                  <td>%${escapeHtml(r.profit)}</td>
                  <td>${Number(r.desi || 0).toLocaleString('tr-TR', { maximumFractionDigits: 2 })}</td>
                  <td>${escapeHtml(SETTINGS.carriers[r.carrier]?.label || r.carrier)}</td>
                  <td>${escapeHtml(bulkPaymentLabel(r.payment))}</td>
                  <td>${r.salePrice ? formatDirectCurrency(r.salePrice, r.currency) : '-'}</td>
                  <td>${r.wsProductCode || r.barcode ? `<div class="bulkCodeCell"><b>${escapeHtml(r.wsProductCode || '-')}</b><span>${escapeHtml(r.barcode || '-')}</span></div>` : '-'}</td>
                  <td>${r.isTsoft ? Number(r.stock || 0).toLocaleString('tr-TR', { maximumFractionDigits: 2 }) : '-'}</td>
                  <td class="copyable" data-copy="${moneyTryToCurrency(out.saleExTry, r.currency)}">${moneyTryToCurrency(out.saleExTry, r.currency)}</td>
                  <td class="copyable" data-copy="${moneyTryToCurrency(out.saleIncTry, r.currency)}">${moneyTryToCurrency(out.saleIncTry, r.currency)}</td>
                  <td class="copyable" data-copy="${customerTotal}">${customerTotal}</td>
                  <td>${moneyTryToCurrency(out.netProfitTry, r.currency)}</td>
                  <td>${escapeHtml(note)}</td>
                </tr>
              `;
            }).join('')}
          </tbody>
        </table>
      </div>
    `;

    if(info){
      const ok = results.filter(x => !x.error).length;
      const bad = results.length - ok;
      info.textContent = `${ok} satır hesaplandı${bad ? ', ' + bad + ' satır hatalı' : ''}.`;
    }
  }

  function fillBulkPriceExample(){
    if(document.getElementById('bulkPriceRows')) document.getElementById('bulkPriceRows').value = [
      'HP 59A Toner; 850; TL; 20; 3; dhl; card',
      'Canon Drum; 12,5; USD; 25; 2; aras; eft',
      'Brother Muadil Toner; 420; TL; 20; 1; dhl; term30',
      '1250'
    ].join('\n');
    calculateBulkPrices();
  }

  function clearBulkPrices(){
    if(document.getElementById('bulkPriceRows')) document.getElementById('bulkPriceRows').value = '';
    document.getElementById('bulkPriceResults').innerHTML = '<div class="labelHint">Henüz hesaplama yapılmadı.</div>';
    document.getElementById('bulkPriceInfo').textContent = '';
    window.__bulkPriceResults = [];
  }

  function bulkResultsToRows(){
    const results = window.__bulkPriceResults || [];
    return results.filter(item => !item.error).map(item => {
      const r = item.row;
      const out = item.result;
      return [
        r.name,
        r.cost,
        r.currency,
        r.profit,
        r.desi,
        SETTINGS.carriers[r.carrier]?.label || r.carrier,
        bulkPaymentLabel(r.payment),
        r.salePrice ? formatDirectCurrency(r.salePrice, r.currency) : '',
        r.wsProductCode || '',
        r.barcode || '',
        r.isTsoft ? r.stock : '',
        moneyTryToCurrency(out.saleExTry, r.currency),
        moneyTryToCurrency(out.saleIncTry, r.currency),
        out.customerTotalExTry === null ? 'Fiyat yok' : moneyTryToCurrency(out.customerTotalExTry, r.currency),
        moneyTryToCurrency(out.netProfitTry, r.currency)
      ];
    });
  }

  function copyBulkResults(){
    const rows = bulkResultsToRows();
    if(!rows.length){
      showToast('Kopyalanacak sonuç yok');
      return;
    }

    const header = ['Ürün','Maliyet','Para','Kâr','Desi','Kargo','Ödeme','T-Soft Satış','WS Kod','Barkod','Stok','KDV Hariç','KDV Dahil','Alınacak KDV Hariç','Net Kâr'];
    const text = [header, ...rows].map(r => r.join('\t')).join('\n');
    copyText(text);
  }

  function downloadBulkResultsCsv(){
    const rows = bulkResultsToRows();
    if(!rows.length){
      showToast('İndirilecek sonuç yok');
      return;
    }

    const header = ['Ürün','Maliyet','Para','Kâr','Desi','Kargo','Ödeme','T-Soft Satış','WS Kod','Barkod','Stok','KDV Hariç','KDV Dahil','Alınacak KDV Hariç','Net Kâr'];
    const csv = [header, ...rows].map(row => row.map(cell => `"${String(cell ?? '').replace(/"/g,'""')}"`).join(';')).join('\n');
    const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'toplu-fiyat-sonuclari-' + new Date().toISOString().slice(0,10) + '.csv';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(a.href);
  }


  function escapeBulkCell(value){
    return String(value ?? '').replace(/[;\t\n\r]+/g, ' ').replace(/\s+/g, ' ').trim();
  }

  function formatBulkNumber(value){
    const num = Number(value || 0);
    if(!Number.isFinite(num) || num === 0) return '0';
    return String(Number(num.toFixed(6))).replace('.', ',');
  }

  function formatDirectCurrency(amount, currency){
    const cur = normalizeBulkCurrency(currency || 'TL');
    const num = Number(amount || 0);
    return (SETTINGS.symbols[cur] || (cur + ' ')) + num.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function getBulkTextareaLineBounds(textarea){
    const value = textarea.value || '';
    const start = textarea.selectionStart ?? value.length;
    const lineStart = value.lastIndexOf('\n', Math.max(0, start - 1)) + 1;
    const nextBreak = value.indexOf('\n', start);
    const lineEnd = nextBreak === -1 ? value.length : nextBreak;
    return { lineStart, lineEnd };
  }

  function makeBulkLineFromTsoftProduct(product){
    const profit = document.getElementById('bulkDefaultProfit')?.value || '20';
    const carrier = document.getElementById('bulkDefaultCarrier')?.value || 'hepsijet';
    const payment = document.getElementById('bulkDefaultPayment')?.value || 'card';
    const currency = normalizeBulkCurrency(product.currency_code || product.currency || 'TL');
    const desi = Number(product.desi || 0) > 0 ? product.desi : (document.getElementById('bulkDefaultDesi')?.value || 0);

    return [
      escapeBulkCell(product.product_name || product.name || 'T-Soft Ürün'),
      formatBulkNumber(product.purchase_price || 0),
      currency,
      profit,
      formatBulkNumber(desi),
      carrier,
      payment,
      formatBulkNumber(product.sale_price || 0),
      escapeBulkCell(product.ws_product_code || ''),
      escapeBulkCell(product.barcode || ''),
      formatBulkNumber(product.stock || 0)
    ].join('; ');
  }

  function insertBulkLineFromTsoftProduct(product){
    addBulkCard(product);
  }

  function openTsoftProductPicker(){
    const modal = document.getElementById('tsoftProductModal');
    const input = document.getElementById('tsoftProductSearchInput');
    const results = document.getElementById('tsoftProductResults');
    const status = document.getElementById('tsoftProductSearchStatus');
    if(!modal) return;
    modal.classList.remove('hide');
    modal.setAttribute('aria-hidden', 'false');
    if(results) results.innerHTML = '';
    if(status) status.textContent = 'Aramak için en az 2 karakter yaz.';
    setTimeout(() => input && input.focus(), 30);
  }

  function closeTsoftProductPicker(){
    const modal = document.getElementById('tsoftProductModal');
    if(!modal) return;
    modal.classList.add('hide');
    modal.setAttribute('aria-hidden', 'true');
  }

  let tsoftSearchTimer = null;
  let tsoftSearchAbort = null;

  async function searchTsoftProductsNow(){
    const input = document.getElementById('tsoftProductSearchInput');
    const results = document.getElementById('tsoftProductResults');
    const status = document.getElementById('tsoftProductSearchStatus');
    const q = (input?.value || '').trim();

    if(!results || !status) return;
    if(q.length < 2){
      status.textContent = 'Arama için en az 2 karakter yaz.';
      results.innerHTML = '';
      return;
    }

    if(tsoftSearchAbort) tsoftSearchAbort.abort();
    tsoftSearchAbort = new AbortController();

    status.textContent = 'T-Soft ürünleri aranıyor...';
    results.innerHTML = '<div class="tsoftEmpty">Aranıyor...</div>';

    try{
      const res = await fetch('api.php?action=tsoft_product_search&q=' + encodeURIComponent(q) + '&limit=20', {
        headers: { 'Accept': 'application/json' },
        signal: tsoftSearchAbort.signal
      });
      const data = await res.json().catch(() => ({}));
      if(!res.ok || !data.ok) throw new Error(data.message || 'T-Soft ürün arama hatası');

      const list = Array.isArray(data.data) ? data.data : [];
      status.textContent = list.length ? `${list.length} ürün bulundu.` : 'Ürün bulunamadı.';
      renderTsoftProductResults(list);
    }catch(e){
      if(e.name === 'AbortError') return;
      status.textContent = e.message || 'T-Soft ürün arama hatası.';
      results.innerHTML = '<div class="tsoftEmpty error">' + escapeHtml(status.textContent) + '</div>';
    }
  }

  function renderTsoftProductResults(list){
    const results = document.getElementById('tsoftProductResults');
    if(!results) return;
    if(!list.length){
      results.innerHTML = '<div class="tsoftEmpty">Sonuç yok.</div>';
      return;
    }

    results.innerHTML = list.map((p, index) => `
      <button type="button" class="tsoftProductCard" data-tsoft-index="${index}">
        <span class="tsoftProductMain">
          <b>${escapeHtml(p.product_name || '-')}</b>
          <small>${escapeHtml(p.ws_product_code || '-')} · ${escapeHtml(p.barcode || '-')}</small>
        </span>
        <span class="tsoftProductMeta">
          <em>Alış: ${formatBulkNumber(p.purchase_price)} ${escapeHtml(p.currency_code || '')}</em>
          <em>Satış: ${formatBulkNumber(p.sale_price)} ${escapeHtml(p.currency_code || '')}</em>
          <em>Stok: ${Number(p.stock || 0).toLocaleString('tr-TR', { maximumFractionDigits: 2 })}</em>
          <em>Desi: ${formatBulkNumber(p.desi || 0)}</em>
        </span>
      </button>
    `).join('');

    results.querySelectorAll('[data-tsoft-index]').forEach(btn => {
      btn.addEventListener('click', () => {
        const p = list[Number(btn.dataset.tsoftIndex || 0)];
        if(!p) return;
        insertBulkLineFromTsoftProduct(p);
        closeTsoftProductPicker();
        showToast('T-Soft ürünü satıra eklendi');
      });
    });
  }

  document.getElementById('tsoftProductSearchInput')?.addEventListener('input', () => {
    clearTimeout(tsoftSearchTimer);
    tsoftSearchTimer = setTimeout(searchTsoftProductsNow, 350);
  });

  document.getElementById('tsoftProductSearchInput')?.addEventListener('keydown', e => {
    if(e.key === 'Enter'){
      e.preventDefault();
      searchTsoftProductsNow();
    }
    if(e.key === 'Escape') closeTsoftProductPicker();
  });

  document.getElementById('tsoftProductModal')?.addEventListener('click', e => {
    if(e.target && e.target.id === 'tsoftProductModal') closeTsoftProductPicker();
  });

  document.addEventListener('keydown', e => {
    if(e.key === 'Escape') closeTsoftProductPicker();
  });


  document.getElementById('miniFxResult')?.addEventListener('click', () => {
    const resultEl = document.getElementById('miniFxResult');
    const value = resultEl?.dataset.copy || resultEl?.textContent || '';
    copyText(value.replace(/^₺|^\$|^€/g, '').trim());
  });

loadFrankfurterRates();

  document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-copy], #saleEx, #saleInc');
    if(!el) return;
    const text = el.dataset.copy || el.textContent.trim();
    copyText(text);
  });
