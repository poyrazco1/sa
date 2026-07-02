<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php'; // auth.php'yi de yükler

auth_session();

$base = auth_base_path();

/** next parametresini yalnızca güvenli, aynı-uygulama yolu ise kabul et. */
function login_safe_next(string $base): string
{
    $next = (string)($_GET['next'] ?? $_POST['next'] ?? '');
    $next = trim($next);
    if ($next === '') {
        return $base . '/index.php';
    }
    // Şema/host içeren veya protokol-görece (//) değerleri reddet.
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $next) || str_starts_with($next, '//')) {
        return $base . '/index.php';
    }
    if ($next[0] !== '/') {
        $next = $base . '/' . $next;
    }
    // Yalnızca uygulama kökü altındaki yollar.
    if ($base !== '' && !str_starts_with($next, $base)) {
        return $base . '/index.php';
    }
    return $next;
}

// Zaten girişliyse panele dön.
if (auth_check()) {
    header('Location: ' . login_safe_next($base));
    exit;
}

$error = '';
$dbReady = auth_system_available();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$dbReady) {
        $error = 'Veritabanı hazır değil. Lütfen kurulumu (database/install.sql) tamamlayın.';
    } elseif (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Oturum süresi doldu. Sayfayı yenileyip tekrar deneyin.';
    } else {
        $result = auth_login((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
        if ($result['ok']) {
            header('Location: ' . login_safe_next($base));
            exit;
        }
        $error = $result['message'] ?? 'Giriş başarısız.';
    }
}

$appName = htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'Panel', ENT_QUOTES, 'UTF-8');
$csrf = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
$nextVal = htmlspecialchars((string)($_GET['next'] ?? ''), ENT_QUOTES, 'UTF-8');
$errHtml = $error !== '' ? '<div class="error">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>' : '';
$notice = !$dbReady
    ? '<div class="labelHint" style="margin-top:14px">Not: Veritabanı bağlantısı/kurulumu tamamlanınca giriş etkinleşir.</div>'
    : '';
?><!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= $appName ?> — Giriş</title>
  <link rel="stylesheet" href="assets/css/app.css?v=80" />
</head>
<body>
  <div class="wrap">
    <div class="userGate" style="max-width:420px">
      <div class="userPanel">
        <h2>Panele giriş</h2>
        <p style="color:var(--muted);margin:0 0 4px">Devam etmek için kullanıcı bilgilerinle giriş yap.</p>
        <form method="post" autocomplete="off" style="margin-top:22px">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="next" value="<?= $nextVal ?>">
          <div style="margin-bottom:14px">
            <label for="username">Kullanıcı adı</label>
            <input id="username" name="username" type="text" autocomplete="username" autofocus required>
          </div>
          <div>
            <label for="password">Parola</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
          </div>
          <?= $errHtml ?>
          <button class="primary" type="submit" style="width:100%;margin-top:18px;min-height:46px">Giriş yap</button>
        </form>
        <?= $notice ?>
      </div>
    </div>
  </div>
</body>
</html>
