/**
 * Kenar navigasyon davranışı (app.js'ten bağımsız, hafif).
 * - Mobilde sidebar aç/kapa.
 * - Araçlar görünümünde ?tool= parametresine göre ilgili modülü aktifleştirir.
 */
(function () {
  window.pwToggleNav = function (open) {
    var shell = document.getElementById('appShell');
    if (!shell) return;
    if (open === true) shell.classList.add('navOpen');
    else if (open === false) shell.classList.remove('navOpen');
    else shell.classList.toggle('navOpen');
  };

  // Sidebar linkine tıklanınca (mobil) menüyü kapat.
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('.sideNav .navItem') : null;
    if (a) window.pwToggleNav(false);
  });

  // Araçlar görünümünde derin bağlantı: ?tool=bulkPrice|label
  window.addEventListener('load', function () {
    try {
      var p = new URLSearchParams(window.location.search);
      if (p.get('view') === 'tools') {
        var t = p.get('tool');
        if (t && t !== 'price' && typeof window.showModule === 'function') {
          window.showModule(t);
        }
      }
    } catch (e) {}
  });
})();
