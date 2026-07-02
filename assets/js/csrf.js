/**
 * CSRF köprüsü — mevcut app.js'e dokunmadan çalışır.
 * window.fetch'i sarmalayıp aynı-köken, durum değiştiren (POST/PUT/PATCH/DELETE)
 * isteklere oturum CSRF token'ını "X-CSRF-Token" başlığı olarak ekler.
 * Token, sayfadaki <meta name="csrf-token"> etiketinden okunur (gizli sır değildir).
 */
(function () {
  var meta = document.querySelector('meta[name="csrf-token"]');
  var token = meta ? meta.getAttribute('content') : '';
  if (!token || typeof window.fetch !== 'function') {
    return;
  }

  var WRITE = { POST: 1, PUT: 1, PATCH: 1, DELETE: 1 };
  var orig = window.fetch;

  window.fetch = function (input, init) {
    init = init || {};
    var method = (
      init.method ||
      (input && typeof input === 'object' && input.method) ||
      'GET'
    ).toUpperCase();

    var url = (typeof input === 'string') ? input : (input && input.url) || '';
    var sameOrigin = true;
    try {
      sameOrigin = new URL(url, window.location.href).origin === window.location.origin;
    } catch (e) {
      sameOrigin = true;
    }

    if (sameOrigin && WRITE[method]) {
      var headers = new Headers(
        init.headers || (input && typeof input === 'object' && input.headers) || undefined
      );
      if (!headers.has('X-CSRF-Token')) {
        headers.set('X-CSRF-Token', token);
      }
      var merged = {};
      for (var k in init) { if (Object.prototype.hasOwnProperty.call(init, k)) merged[k] = init[k]; }
      merged.headers = headers;
      init = merged;
    }

    return orig.call(this, input, init);
  };
})();
