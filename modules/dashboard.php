<?php
declare(strict_types=1);

/**
 * Dashboard (Part 3 iskeleti).
 * Gerçek veriden beslenen özet kartları (DB varsa) + hızlı aksiyonlar + canlı kur.
 * Kapsamlı KPI/pipeline/aktivite akışı Part 4'te eklenecektir.
 */

$base = function_exists('auth_base_path') ? auth_base_path() : '';
$baseE = htmlspecialchars($base, ENT_QUOTES, 'UTF-8');
$u = function_exists('auth_user') ? auth_user() : null;
$hello = htmlspecialchars((string)($u['full_name'] ?? $u['username'] ?? ''), ENT_QUOTES, 'UTF-8');

/** Güvenli satır sayacı — tablo adları sabittir (kullanıcı girdisi değil). */
function pw_dash_count(?PDO $pdo, string $table, string $where = ''): ?int
{
    if (!$pdo instanceof PDO) {
        return null;
    }
    try {
        $sql = "SELECT COUNT(*) FROM `{$table}`" . ($where !== '' ? " WHERE {$where}" : '');
        return (int)$pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
}

$pdo = null;
try {
    $pdo = db();
} catch (Throwable $e) {
    $pdo = null;
}

$fmt = static fn(?int $n): string => $n === null ? '—' : number_format($n, 0, ',', '.');

$priceCount = pw_dash_count($pdo, 'price_calculation_logs');
$soldCount = pw_dash_count($pdo, 'price_calculation_logs', 'sold = 1');
$labelCount = pw_dash_count($pdo, 'shipping_label_logs');
$customerCount = pw_dash_count($pdo, 'label_customers');

function dash_qicon(string $name): string
{
    $p = [
        'price' => '<rect x="4" y="2.5" width="16" height="19" rx="2"/><line x1="8" y1="7" x2="16" y2="7"/><line x1="8" y1="11" x2="10" y2="11"/><line x1="8" y1="15" x2="10" y2="15"/>',
        'bulk' => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="3.5" cy="6" r="1.4"/><circle cx="3.5" cy="12" r="1.4"/><circle cx="3.5" cy="18" r="1.4"/>',
        'label' => '<path d="M3 8a2 2 0 0 1 2-2h8l7 7-6 6-7-7V8z"/><circle cx="8" cy="10" r="1.4"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}
?>
<div class="wrap">
  <div class="pageHead">
    <h1>Panel özeti<?= $hello !== '' ? ' — <span style="font-weight:600;color:var(--muted);font-size:.6em;font-family:var(--body)">Hoş geldin, ' . $hello . '</span>' : '' ?></h1>
    <p>Fiyatlama, toplu analiz ve kargo etiketi operasyonlarının hızlı görünümü.</p>
  </div>

  <div class="dashGrid">
    <div class="statCard">
      <div class="statLabel">Toplam fiyat hesabı</div>
      <div class="statValue"><?= $fmt($priceCount) ?></div>
      <div class="statSub">Kaydedilen hesap sayısı</div>
    </div>
    <div class="statCard">
      <div class="statLabel">Satıldı işaretlenen</div>
      <div class="statValue"><?= $fmt($soldCount) ?></div>
      <div class="statSub">Satıldı kaydı</div>
    </div>
    <div class="statCard">
      <div class="statLabel">Kargo etiketi</div>
      <div class="statValue"><?= $fmt($labelCount) ?></div>
      <div class="statSub">Oluşturulan etiket</div>
    </div>
    <div class="statCard">
      <div class="statLabel">Müşteri kaydı</div>
      <div class="statValue"><?= $fmt($customerCount) ?></div>
      <div class="statSub">Kayıtlı müşteri</div>
    </div>
    <div class="statCard">
      <div class="statLabel">Güncel kur</div>
      <div class="statValue" id="dashRates" style="font-size:19px">Yükleniyor…</div>
      <div class="statSub" id="dashRatesSub">USD / EUR → TL</div>
    </div>
  </div>

  <div class="pageHead" style="margin-bottom:14px"><h1 style="font-size:18px">Hızlı işlemler</h1></div>
  <div class="quickGrid">
    <a class="quickCard" href="<?= $baseE ?>/index.php?view=tools&amp;tool=price">
      <span class="qIcon"><?= dash_qicon('price') ?></span>
      <span class="qText"><b>Akıllı Fiyat</b><span>Tek ürün için satış fiyatı hesapla</span></span>
    </a>
    <a class="quickCard" href="<?= $baseE ?>/index.php?view=tools&amp;tool=bulkPrice">
      <span class="qIcon"><?= dash_qicon('bulk') ?></span>
      <span class="qText"><b>Toplu Fiyat</b><span>Birden çok ürünü aynı anda fiyatla</span></span>
    </a>
    <a class="quickCard" href="<?= $baseE ?>/index.php?view=tools&amp;tool=label">
      <span class="qIcon"><?= dash_qicon('label') ?></span>
      <span class="qText"><b>Kargo Etiketi</b><span>Etiket oluştur, yazdır ve kaydet</span></span>
    </a>
  </div>
</div>

<script>
(function () {
  var el = document.getElementById('dashRates');
  if (!el) return;
  fetch('api/rates.php?t=' + Date.now(), { cache: 'no-store' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d && d.ok && d.rates) {
        var usd = d.rates.USD, eur = d.rates.EUR;
        var f = function (v) { return v ? '₺' + Number(v).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '—'; };
        el.textContent = 'USD ' + f(usd) + '  ·  EUR ' + f(eur);
      } else {
        el.textContent = 'Kur alınamadı';
      }
    })
    .catch(function () { el.textContent = 'Kur alınamadı'; });
})();
</script>
