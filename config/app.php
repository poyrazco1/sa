<?php
declare(strict_types=1);

define('APP_BOOTSTRAPPED', true);
define('APP_NAME', 'Akıllı Fiyat Sihirbazı');

/**
 * Versiyon kontrolüne girmeyen yerel sırlar (opsiyonel).
 * İçinde putenv('PANEL_ACCESS_CODE=...') gibi tanımlar olabilir.
 */
$__secretsLocal = __DIR__ . '/secrets.local.php';
if (is_file($__secretsLocal)) {
    require_once $__secretsLocal;
}

/**
 * Panel erişim kodu (parola).
 * Ortam değişkeni PANEL_ACCESS_CODE tanımlıysa panel bu kodla korunur.
 * Tanımlı DEĞİLSE panel açık kalır (geliştirme modu) — mevcut kurulumu bozmamak için.
 * Üretimde PANEL_ACCESS_CODE tanımlayarak paneli kilitlemen önerilir.
 */
function panel_access_code(): string
{
    $code = getenv('PANEL_ACCESS_CODE');
    return ($code !== false) ? (string)$code : '';
}

function panel_auth_enabled(): bool
{
    return panel_access_code() !== '';
}

function panel_start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ]);
        session_start();
    }
}

function panel_is_authenticated(): bool
{
    if (!panel_auth_enabled()) {
        return true; // Kod tanımlı değilse serbest (geliştirme).
    }
    panel_start_session();
    return !empty($_SESSION['panel_ok']);
}

/**
 * ESKİ (legacy) sayfa koruması — tek paylaşımlı PANEL_ACCESS_CODE.
 * Artık yalnızca DB tabanlı auth sistemi kullanılamıyorsa (DB yok/kurulmamış)
 * geriye dönük emniyet için devreye girer. Yeni akış: includes/auth.php.
 */
function legacy_require_panel_auth(): void
{
    if (!panel_auth_enabled()) {
        return;
    }

    panel_start_session();

    // Çıkış
    if (isset($_GET['logout'])) {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '/', '?'));
        exit;
    }

    // Giriş denemesi
    $error = '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['panel_code'])) {
        if (hash_equals(panel_access_code(), (string)$_POST['panel_code'])) {
            session_regenerate_id(true);
            $_SESSION['panel_ok'] = true;
            header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '/', '?'));
            exit;
        }
        $error = 'Kod hatalı. Tekrar dene.';
    }

    if (!empty($_SESSION['panel_ok'])) {
        return; // Giriş yapılmış.
    }

    // Giriş ekranı
    $appName = htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8');
    $err = $error !== '' ? '<div class="loginErr">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>' : '';
    http_response_code(401);
    echo <<<HTML
<!doctype html>
<html lang="tr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$appName} — Giriş</title>
<style>
  :root{--bg:#F4F3EF;--surface:#fff;--ink:#17181C;--muted:#71757F;--line:#E5E3DC;--accent:#2C43F0}
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
    background:radial-gradient(1000px 400px at 85% -5%,rgba(44,67,240,.06),transparent 60%),var(--bg);
    font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink);padding:24px}
  .box{width:100%;max-width:360px;background:var(--surface);border:1px solid var(--line);
    border-radius:20px;box-shadow:0 24px 60px -28px rgba(23,24,28,.28);padding:32px 28px}
  .eyebrow{font-size:11px;letter-spacing:.22em;font-weight:700;color:var(--accent);margin-bottom:12px}
  h1{font-size:22px;margin:0 0 6px;letter-spacing:-.02em}
  p{margin:0 0 20px;color:var(--muted);font-size:14px}
  label{display:block;font-size:13px;font-weight:600;color:var(--ink);margin-bottom:8px}
  input{width:100%;border:1px solid var(--line);border-radius:10px;padding:12px 14px;font-size:15px;outline:none}
  input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(44,67,240,.16)}
  button{width:100%;margin-top:16px;border:0;border-radius:10px;background:var(--accent);color:#fff;
    padding:12px;font-size:15px;font-weight:600;cursor:pointer}
  button:hover{background:#2436d6}
  .loginErr{margin-top:12px;color:#DB2A45;font-size:13px;font-weight:600;
    background:#FCE7EB;border:1px solid #F3C9D1;border-radius:10px;padding:10px 12px}
</style></head>
<body>
  <form class="box" method="post" autocomplete="off">
    <div class="eyebrow">{$appName}</div>
    <h1>Panel girişi</h1>
    <p>Devam etmek için erişim kodunu gir.</p>
    <label for="panel_code">Erişim kodu</label>
    <input id="panel_code" name="panel_code" type="password" autofocus required>
    <button type="submit">Giriş yap</button>
    {$err}
  </form>
</body></html>
HTML;
    exit;
}

/**
 * ESKİ (legacy) API koruması — tek paylaşımlı PANEL_ACCESS_CODE.
 * Yalnızca DB auth kullanılamıyorsa devreye girer.
 */
function legacy_require_api_auth(): void
{
    if (!panel_auth_enabled()) {
        return;
    }
    if (panel_is_authenticated()) {
        return;
    }
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'Yetkisiz. Panele giriş yapman gerekiyor.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * DB tabanlı auth/RBAC sistemi. includes/auth.php içindeki koruyuculara devreder.
 * DB hazır değilse auth koruyucuları otomatik olarak legacy_* fonksiyonlarına düşer.
 */
require_once __DIR__ . '/../includes/auth.php';

/** Sayfa koruması (index.php ve diğer sayfalar buradan çağırır). */
function require_panel_auth(): void
{
    auth_require_login_page();
}

/** API (JSON) koruması (api/bootstrap.php ve tsoft uçları buradan çağırır). */
function require_api_auth(): void
{
    auth_require_api();
}
