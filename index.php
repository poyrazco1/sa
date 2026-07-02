<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_panel_auth();

/* ---- Basit görünüm yönlendirici (router) ---- */
$view = (string)($_GET['view'] ?? 'dashboard');
$tool = (string)($_GET['tool'] ?? 'price');
if (!in_array($tool, ['price', 'bulkPrice', 'label'], true)) {
    $tool = 'price';
}

$viewPerms = [
    'dashboard' => 'dashboard.view',
    'tools'     => 'tools.use',
];
if (!isset($viewPerms[$view])) {
    $view = 'dashboard';
}

// Yetki kapısı (auth aktifse). Erişilemeyen görünümde erişilebilir bir yere düş.
if (function_exists('auth_system_available') && auth_system_available() && !user_can($viewPerms[$view])) {
    if (user_can('dashboard.view')) {
        $view = 'dashboard';
    } elseif (user_can('tools.use')) {
        $view = 'tools';
    }
}

$GLOBALS['pw_view'] = $view;
$GLOBALS['pw_tool'] = $tool;
$GLOBALS['pw_load_app_js'] = ($view === 'tools');

$__loggedInName = '';
if (function_exists('auth_user')) {
    $__u = auth_user();
    $__loggedInName = (string)($__u['full_name'] ?? $__u['username'] ?? '');
}

require __DIR__ . '/includes/header.php';
?>
<div class="appShell" id="appShell">
  <?php require __DIR__ . '/includes/nav.php'; ?>
  <div class="mainArea">
    <button type="button" class="navToggle" onclick="pwToggleNav(true)" aria-label="Menüyü aç">☰ Menü</button>

    <?php if ($view === 'tools'): ?>
      <?php require __DIR__ . '/includes/user-gate.php'; ?>
      <?php if ($__loggedInName !== ''): ?>
      <script>
        (function () {
          try {
            var n = <?= json_encode($__loggedInName, JSON_UNESCAPED_UNICODE) ?>;
            if (n && !localStorage.getItem('akilliFiyatSihirbaziUser')) {
              localStorage.setItem('akilliFiyatSihirbaziUser', n);
            }
          } catch (e) {}
        })();
      </script>
      <?php endif; ?>
      <div class="wrap appHidden" id="appWrap">
        <?php require __DIR__ . '/includes/topbar.php'; ?>
        <?php require __DIR__ . '/includes/module-switch.php'; ?>
        <?php require __DIR__ . '/modules/price-wizard.php'; ?>
        <?php require __DIR__ . '/modules/bulk-price.php'; ?>
        <?php require __DIR__ . '/modules/shipping-label.php'; ?>
      </div>
    <?php else: ?>
      <?php require __DIR__ . '/modules/dashboard.php'; ?>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
