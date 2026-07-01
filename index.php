<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_panel_auth();

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/user-gate.php';
?>
<div class="wrap appHidden" id="appWrap">
  <?php require __DIR__ . '/includes/topbar.php'; ?>
  <?php require __DIR__ . '/includes/module-switch.php'; ?>
  <?php require __DIR__ . '/modules/price-wizard.php'; ?>
  <?php require __DIR__ . '/modules/bulk-price.php'; ?>
  <?php require __DIR__ . '/modules/shipping-label.php'; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
