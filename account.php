<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php'; // auth.php'yi de yükler

// Girişi zorunlu kıl (must_change durumunda bu sayfa serbesttir).
auth_require_login_page();

$base = auth_base_path();
$user = auth_user();
$forced = isset($_GET['force']) || !empty($user['must_change']);

$error = '';
$success = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Oturum süresi doldu. Sayfayı yenileyip tekrar deneyin.';
    } else {
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if ($new !== $confirm) {
            $error = 'Yeni parola ve tekrarı eşleşmiyor.';
        } else {
            $result = auth_change_password((int)$user['id'], $current, $new);
            if ($result['ok']) {
                $success = 'Parola güncellendi.';
                if ($forced) {
                    header('Location: ' . $base . '/index.php');
                    exit;
                }
            } else {
                $error = $result['message'] ?? 'Parola değiştirilemedi.';
            }
        }
    }
}

$appName = htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'Panel', ENT_QUOTES, 'UTF-8');
$csrf = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
$fullName = htmlspecialchars((string)($user['full_name'] ?? $user['username'] ?? ''), ENT_QUOTES, 'UTF-8');
$roleCode = htmlspecialchars((string)($user['role_code'] ?? ''), ENT_QUOTES, 'UTF-8');
$errHtml = $error !== '' ? '<div class="error">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>' : '';
$okHtml = $success !== '' ? '<div class="labelHint" style="color:var(--ok);font-weight:600;margin-top:12px">' . htmlspecialchars($success, ENT_QUOTES, 'UTF-8') . '</div>' : '';
$forceHtml = $forced
    ? '<div class="splitNotice" style="margin-bottom:16px"><b>Parola değişikliği gerekli</b><span>İlk girişte güvenlik için yeni bir parola belirlemelisin.</span></div>'
    : '';
?><!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= $appName ?> — Hesap</title>
  <link rel="stylesheet" href="assets/css/app.css?v=80" />
</head>
<body>
  <div class="wrap">
    <div class="userGate" style="max-width:460px">
      <div class="userPanel">
        <h2>Parola değiştir</h2>
        <p style="color:var(--muted);margin:0 0 18px"><?= $fullName ?> · <?= $roleCode ?></p>
        <?= $forceHtml ?>
        <form method="post" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <div style="margin-bottom:14px">
            <label for="current_password">Mevcut parola</label>
            <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
          </div>
          <div style="margin-bottom:14px">
            <label for="new_password">Yeni parola (en az 8 karakter)</label>
            <input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="8" required>
          </div>
          <div>
            <label for="confirm_password">Yeni parola (tekrar)</label>
            <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" required>
          </div>
          <?= $errHtml ?>
          <?= $okHtml ?>
          <div class="actions" style="margin-top:20px">
            <?php if (!$forced): ?><a class="ghost" href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/index.php" style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center;padding:12px 20px;border-radius:var(--radius-sm)">Panele dön</a><?php else: ?><span></span><?php endif; ?>
            <button class="primary" type="submit">Parolayı güncelle</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
