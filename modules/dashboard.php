<?php
declare(strict_types=1);

/**
 * Dashboard (Part 4 — KPI'lar).
 * Gerçek veriden özet kartları, pipeline ve son aktiviteler (includes/dashboard_data.php).
 * CRM tabloları henüz boşsa ilgili metrikler 0 gösterir (placeholder değil, gerçek sıfır).
 */

require_once __DIR__ . '/../includes/dashboard_data.php';

$base = function_exists('auth_base_path') ? auth_base_path() : '';
$baseE = htmlspecialchars($base, ENT_QUOTES, 'UTF-8');
$u = function_exists('auth_user') ? auth_user() : null;
$hello = htmlspecialchars((string)($u['full_name'] ?? $u['username'] ?? ''), ENT_QUOTES, 'UTF-8');

$pdo = null;
try {
    $pdo = db();
} catch (Throwable $e) {
    $pdo = null;
}
$stats = dashboard_stats($pdo);

$n = static fn($v): string => number_format((float)$v, 0, ',', '.');
$m = static fn($v): string => '₺' . number_format((float)$v, 0, ',', '.');

$price = $stats['price'];
$crm = $stats['crm'];
$labels = $stats['labels'];

function dash_qicon(string $name): string
{
    $p = [
        'price' => '<rect x="4" y="2.5" width="16" height="19" rx="2"/><line x1="8" y1="7" x2="16" y2="7"/><line x1="8" y1="11" x2="10" y2="11"/><line x1="8" y1="15" x2="10" y2="15"/>',
        'bulk' => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="3.5" cy="6" r="1.4"/><circle cx="3.5" cy="12" r="1.4"/><circle cx="3.5" cy="18" r="1.4"/>',
        'label' => '<path d="M3 8a2 2 0 0 1 2-2h8l7 7-6 6-7-7V8z"/><circle cx="8" cy="10" r="1.4"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

$stageOrder = ['new', 'qualified', 'proposal', 'won', 'lost'];
?>
<div class="wrap">
  <div class="pageHead">
    <h1>Panel özeti<?= $hello !== '' ? ' <span style="font-weight:600;color:var(--muted);font-size:.55em;font-family:var(--body)">· Hoş geldin, ' . $hello . '</span>' : '' ?></h1>
    <p>Fiyatlama, CRM ve kargo operasyonlarının bu aya ait canlı görünümü.</p>
  </div>

  <div class="dashGrid">
    <div class="statCard">
      <div class="statLabel">Bu ay fiyat hesabı</div>
      <div class="statValue"><?= $n($price['month']) ?></div>
      <div class="statSub"><?= $n($price['today']) ?> bugün · <?= $n($price['total']) ?> toplam</div>
    </div>
    <div class="statCard">
      <div class="statLabel">Bu ay satılan</div>
      <div class="statValue"><?= $n($price['sold_month']) ?></div>
      <div class="statSub"><?= $n($price['sold_total']) ?> toplam satış kaydı</div>
    </div>
    <div class="statCard">
      <div class="statLabel">Bu ay net kâr</div>
      <div class="statValue" style="font-size:24px;color:var(--ok)"><?= $m($price['profit_month']) ?></div>
      <div class="statSub">Satılanlardan · ciro <?= $m($price['sale_month']) ?></div>
    </div>
    <div class="statCard">
      <div class="statLabel">Açık fırsat</div>
      <div class="statValue"><?= $n($crm['opps_open']) ?></div>
      <div class="statSub"><?= $m($crm['opps_open_amount']) ?> potansiyel</div>
    </div>
    <div class="statCard">
      <div class="statLabel">Açık görev</div>
      <div class="statValue"><?= $n($crm['tasks_open']) ?></div>
      <div class="statSub">
        <?php if ($crm['tasks_overdue'] > 0): ?><span style="color:var(--bad);font-weight:700"><?= $n($crm['tasks_overdue']) ?> geciken</span> · <?php endif; ?>
        <?= $n($crm['tasks_due_soon']) ?> yaklaşan
      </div>
    </div>
    <div class="statCard">
      <div class="statLabel">Güncel kur</div>
      <div class="statValue" id="dashRates" style="font-size:18px">Yükleniyor…</div>
      <div class="statSub" id="dashRatesSub">USD / EUR → TL</div>
    </div>
  </div>

  <div class="dashCols">
    <div class="statCard">
      <div class="statLabel" style="margin-bottom:14px">Satış hunisi (fırsatlar)</div>
      <?php
        $hasPipeline = false;
        foreach ($stageOrder as $st) { if (!empty($stats['pipeline'][$st]['count'])) { $hasPipeline = true; break; } }
      ?>
      <?php if (!$hasPipeline): ?>
        <div class="labelHint">Henüz fırsat yok. CRM &rsaquo; Fırsatlar bölümünden ekledikçe burada özetlenir.</div>
      <?php else: ?>
        <div class="pipeList">
          <?php foreach ($stageOrder as $st):
            $c = (int)($stats['pipeline'][$st]['count'] ?? 0);
            $a = (float)($stats['pipeline'][$st]['amount'] ?? 0); ?>
          <div class="pipeRow">
            <span class="pipeStage" data-stage="<?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ds_stage_label($st), ENT_QUOTES, 'UTF-8') ?></span>
            <span class="pipeCount"><?= $n($c) ?></span>
            <span class="pipeAmount"><?= $m($a) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="statCard">
      <div class="statLabel" style="margin-bottom:14px">Son aktiviteler</div>
      <?php if (empty($stats['recent'])): ?>
        <div class="labelHint">Henüz aktivite yok. CRM işlemleri (not, durum değişimi, görev) burada listelenecek.</div>
      <?php else: ?>
        <div class="actFeed">
          <?php foreach ($stats['recent'] as $a): ?>
          <div class="actItem">
            <div class="actDot" data-type="<?= htmlspecialchars($a['type'], ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="actBody">
              <b><?= htmlspecialchars($a['subject'] !== '' ? $a['subject'] : $a['type'], ENT_QUOTES, 'UTF-8') ?></b>
              <?php if ($a['body'] !== ''): ?><span><?= htmlspecialchars(mb_substr($a['body'], 0, 120), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
              <small><?= htmlspecialchars(trim(($a['user_name'] !== '' ? $a['user_name'] . ' · ' : '') . $a['occurred_at']), ENT_QUOTES, 'UTF-8') ?></small>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="statCard" style="margin-bottom:22px">
    <div class="statLabel" style="margin-bottom:14px">Yaklaşan görevler</div>
    <?php if (empty($stats['upcoming_tasks'])): ?>
      <div class="labelHint">Açık görev yok. CRM &rsaquo; Görevler bölümünden ekleyebilirsin.</div>
    <?php else:
      $prioMap = ['low' => ['Düşük', 'muted'], 'normal' => ['Normal', 'accent'], 'high' => ['Yüksek', 'bad']]; ?>
      <div class="dashTaskList">
        <?php foreach ($stats['upcoming_tasks'] as $t):
          $pr = $prioMap[$t['priority']] ?? ['—', 'muted'];
          $due = $t['due_at'] !== '' ? substr(str_replace('T', ' ', $t['due_at']), 0, 16) : 'Termin yok'; ?>
        <div class="dashTaskRow">
          <div>
            <div class="tTitle"><?= htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php if ($t['assigned_name'] !== ''): ?><div class="tWho"><?= htmlspecialchars($t['assigned_name'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
          </div>
          <span class="crmBadge crmBadge-<?= $pr[1] ?>"><?= htmlspecialchars($pr[0], ENT_QUOTES, 'UTF-8') ?></span>
          <span class="tDue<?= $t['overdue'] ? ' overdue' : '' ?>"><?= htmlspecialchars($due, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
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
        var f = function (v) { return v ? '₺' + Number(v).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '—'; };
        el.textContent = 'USD ' + f(d.rates.USD) + '  ·  EUR ' + f(d.rates.EUR);
      } else { el.textContent = 'Kur alınamadı'; }
    })
    .catch(function () { el.textContent = 'Kur alınamadı'; });
})();
</script>
