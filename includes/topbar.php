<?php if (function_exists('auth_check') && auth_check()):
    $__u = auth_user();
    $__base = function_exists('auth_base_path') ? auth_base_path() : '';
    $__name = htmlspecialchars((string)($__u['full_name'] ?? $__u['username'] ?? ''), ENT_QUOTES, 'UTF-8');
    $__role = htmlspecialchars((string)($__u['role_code'] ?? ''), ENT_QUOTES, 'UTF-8');
    $__baseE = htmlspecialchars($__base, ENT_QUOTES, 'UTF-8');
?>
  <div class="authStrip">
    <span class="authUser"><b><?= $__name ?></b><span class="authRole"><?= $__role ?></span></span>
    <span class="authActions">
      <a href="<?= $__baseE ?>/account.php">Parola</a>
      <a href="<?= $__baseE ?>/logout.php">Çıkış</a>
    </span>
  </div>
<?php endif; ?>
<div class="top">
    <div class="brand">
      <h1>Akıllı Fiyat Sihirbazı</h1>
      <p class="topSubtitle">Fiyat hesaplama, toplu ürün analizi ve kargo etiketi yönetimi</p>
    </div>
    <div class="topRight hide" id="topRight">
      <div class="topRates">
        <div class="topRate">Dolar <b id="usdTryRate">Yükleniyor</b></div>
        <div class="topRate">Euro <b id="eurTryRate">Yükleniyor</b></div>
        <div class="topConverter" title="Para çevirici">
          <input id="miniFxAmount" type="text" inputmode="decimal" data-format-number autocomplete="off" placeholder="Tutar" value="100" />
          <select id="miniFxFrom" aria-label="Çevrilecek para birimi">
            <option value="USD">USD</option>
            <option value="EUR">EUR</option>
            <option value="TL">TL</option>
          </select>
          <button class="fxSwap" type="button" id="miniFxSwap" aria-label="Para birimlerini değiştir">↔</button>
          <select id="miniFxTo" aria-label="Sonuç para birimi">
            <option value="TL">TL</option>
            <option value="USD">USD</option>
            <option value="EUR">EUR</option>
          </select>
          <span class="fxArrow">=</span>
          <span class="fxResult" id="miniFxResult">₺0,00</span>
        </div>
      </div>
      <div class="welcomeChip" id="welcomeChip">
        <span>Hoş geldin, <b id="welcomeName"></b></span>
        <button type="button" onclick="changeUser()">Değiştir</button>
      </div>
    </div>
  </div>
