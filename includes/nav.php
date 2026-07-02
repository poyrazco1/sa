<?php
declare(strict_types=1);

/**
 * Sol kenar navigasyonu (SaaS shell).
 * $GLOBALS['pw_view'] ve $GLOBALS['pw_tool'] aktif görünümü belirler.
 * Yetki bazlı: yalnızca kullanıcının erişebildiği bölümler gösterilir.
 * Not: CRM ve Admin grupları ilgili part'larda eklenecek (var olmayan görünüme link verilmez).
 */

$pwView = (string)($GLOBALS['pw_view'] ?? 'dashboard');
$pwTool = (string)($GLOBALS['pw_tool'] ?? 'price');
$base = function_exists('auth_base_path') ? auth_base_path() : '';
$baseE = htmlspecialchars($base, ENT_QUOTES, 'UTF-8');

$authActive = function_exists('auth_system_available') && auth_system_available();
$can = static function (string $perm) use ($authActive): bool {
    // Auth aktif değilse (legacy/DB yok) her şey görünür.
    return !$authActive || (function_exists('user_can') && user_can($perm));
};

$u = function_exists('auth_user') ? auth_user() : null;
$userName = htmlspecialchars((string)($u['full_name'] ?? $u['username'] ?? 'Kullanıcı'), ENT_QUOTES, 'UTF-8');
$userRole = htmlspecialchars((string)($u['role_code'] ?? ''), ENT_QUOTES, 'UTF-8');

function nav_icon(string $name): string
{
    $p = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'price' => '<rect x="4" y="2.5" width="16" height="19" rx="2"/><line x1="8" y1="7" x2="16" y2="7"/><line x1="8" y1="11" x2="10" y2="11"/><line x1="13" y1="11" x2="16" y2="11"/><line x1="8" y1="15" x2="10" y2="15"/><line x1="8" y1="18" x2="10" y2="18"/>',
        'bulk' => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="3.5" cy="6" r="1.4"/><circle cx="3.5" cy="12" r="1.4"/><circle cx="3.5" cy="18" r="1.4"/>',
        'label' => '<path d="M3 8a2 2 0 0 1 2-2h8l7 7-6 6-7-7V8z"/><circle cx="8" cy="10" r="1.4"/>',
        'company' => '<rect x="4" y="3" width="10" height="18" rx="1.5"/><path d="M14 8h5a1 1 0 0 1 1 1v12h-6"/><line x1="7" y1="7" x2="11" y2="7"/><line x1="7" y1="11" x2="11" y2="11"/><line x1="7" y1="15" x2="11" y2="15"/>',
        'contact' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/>',
        'lead' => '<path d="M12 2l2.4 5 5.6.6-4.2 3.8 1.2 5.6L12 19.5 6.99 22l1.2-5.6L4 12.6 9.6 12z"/>',
        'opp' => '<path d="M3 17l5-5 4 3 6-7"/><path d="M17 8h4v4"/>',
    ];
    $body = $p[$name] ?? $p['dashboard'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}

$isDash = $pwView === 'dashboard';
$isTools = $pwView === 'tools';
$activeTool = $isTools ? $pwTool : '';
?>
<div class="navScrim" onclick="pwToggleNav(false)"></div>
<aside class="sideNav" id="sideNav">
  <div class="sideBrand">
    <span class="mark"></span>
    <span class="txt">
      <b>Akıllı Fiyat</b>
      <span>Operasyon paneli</span>
    </span>
  </div>

  <?php if ($can('dashboard.view')): ?>
  <nav class="navGroup" aria-label="Genel">
    <a class="navItem<?= $isDash ? ' active' : '' ?>" href="<?= $baseE ?>/index.php?view=dashboard">
      <?= nav_icon('dashboard') ?><span>Dashboard</span>
    </a>
  </nav>
  <?php endif; ?>

  <?php if ($can('tools.use')): ?>
  <nav class="navGroup" aria-label="Araçlar">
    <div class="navLabel">Araçlar</div>
    <a class="navItem<?= ($isTools && $activeTool === 'price') ? ' active' : '' ?>" href="<?= $baseE ?>/index.php?view=tools&amp;tool=price">
      <?= nav_icon('price') ?><span>Akıllı Fiyat</span>
    </a>
    <a class="navItem<?= ($isTools && $activeTool === 'bulkPrice') ? ' active' : '' ?>" href="<?= $baseE ?>/index.php?view=tools&amp;tool=bulkPrice">
      <?= nav_icon('bulk') ?><span>Toplu Fiyat</span>
    </a>
    <a class="navItem<?= ($isTools && $activeTool === 'label') ? ' active' : '' ?>" href="<?= $baseE ?>/index.php?view=tools&amp;tool=label">
      <?= nav_icon('label') ?><span>Kargo Etiketi</span>
    </a>
  </nav>
  <?php endif; ?>

  <?php if ($can('crm.view')): ?>
  <nav class="navGroup" aria-label="CRM">
    <div class="navLabel">CRM</div>
    <a class="navItem<?= $pwView === 'companies' ? ' active' : '' ?>" href="<?= $baseE ?>/index.php?view=companies">
      <?= nav_icon('company') ?><span>Firmalar</span>
    </a>
    <a class="navItem<?= $pwView === 'contacts' ? ' active' : '' ?>" href="<?= $baseE ?>/index.php?view=contacts">
      <?= nav_icon('contact') ?><span>Kişiler</span>
    </a>
    <a class="navItem<?= $pwView === 'leads' ? ' active' : '' ?>" href="<?= $baseE ?>/index.php?view=leads">
      <?= nav_icon('lead') ?><span>Lead'ler</span>
    </a>
    <a class="navItem<?= $pwView === 'opportunities' ? ' active' : '' ?>" href="<?= $baseE ?>/index.php?view=opportunities">
      <?= nav_icon('opp') ?><span>Fırsatlar</span>
    </a>
  </nav>
  <?php endif; ?>

  <div class="sideUser">
    <div class="who">
      <b><?= $userName ?></b>
      <?php if ($userRole !== ''): ?><span class="role"><?= $userRole ?></span><?php endif; ?>
    </div>
    <div class="acts">
      <a href="<?= $baseE ?>/account.php">Parola</a>
      <a class="logout" href="<?= $baseE ?>/logout.php">Çıkış</a>
    </div>
  </div>
</aside>
