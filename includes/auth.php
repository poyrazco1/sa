<?php
declare(strict_types=1);

/**
 * Kimlik doğrulama + RBAC + CSRF çekirdeği (DB tabanlı).
 *
 * Tasarım:
 * - DB'ye dokunan fonksiyonlar opsiyonel bir PDO alır (test edilebilirlik; varsayılan db()).
 * - DB hazır değilse (tablo yok / bağlantı yok) auth_system_available() false döner ve
 *   çağıranlar eski PANEL_ACCESS_CODE davranışına (legacy) güvenli şekilde düşer;
 *   böylece yapılandırılmamış bir kurulum kilitlenmez ("önce koru").
 * - Gizli bilgi frontend'e basılmaz; CSRF token'ı gizli sır değildir (anti-CSRF amaçlı).
 */

require_once __DIR__ . '/../config/db.php';

function auth_session(): void
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

/* ---------------- CSRF ---------------- */

function csrf_token(): string
{
    auth_session();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_verify(?string $token): bool
{
    auth_session();
    $expected = $_SESSION['csrf_token'] ?? '';
    return is_string($token) && $token !== '' && is_string($expected) && $expected !== ''
        && hash_equals($expected, $token);
}

/** İstekten CSRF token'ını çıkar (header, POST alanı veya JSON gövde). */
function auth_extract_csrf(): string
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (is_string($header) && $header !== '') {
        return $header;
    }
    if (isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])) {
        return $_POST['csrf_token'];
    }
    $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ctype, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data) && isset($data['csrf_token']) && is_string($data['csrf_token'])) {
                return $data['csrf_token'];
            }
        }
    }
    return '';
}

/**
 * Durum değiştiren API istekleri için CSRF zorunluluğu.
 * json_response() mevcutsa onu, değilse yalın 403 JSON kullanır.
 */
function auth_require_csrf(): void
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }
    if (csrf_verify(auth_extract_csrf())) {
        return;
    }
    if (function_exists('json_response')) {
        json_response(['ok' => false, 'message' => 'Güvenlik doğrulaması başarısız (CSRF).'], 403);
    }
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'Güvenlik doğrulaması başarısız (CSRF).'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------- Oturum / kullanıcı ---------------- */

function auth_user(): ?array
{
    auth_session();
    $u = $_SESSION['auth'] ?? null;
    return is_array($u) ? $u : null;
}

function auth_check(): bool
{
    return auth_user() !== null;
}

function user_can(string $permission): bool
{
    $u = auth_user();
    if (!$u) {
        return false;
    }
    $perms = $u['perms'] ?? [];
    return is_array($perms) && in_array($permission, $perms, true);
}

function auth_load_permissions(string $roleCode, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $stmt = $pdo->prepare("SELECT permission FROM role_permissions WHERE role_code = :r");
    $stmt->execute([':r' => $roleCode]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function auth_find_user(string $username, ?PDO $pdo = null): ?array
{
    $pdo = $pdo ?? db();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :u AND is_active = 1 LIMIT 1");
    $stmt->execute([':u' => $username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/**
 * DB tabanlı auth sistemi kullanılabilir mi?
 * (bağlantı var + users tablosu var + en az bir kullanıcı). Sonuç statik cache'lenir.
 */
function auth_system_available(?PDO $pdo = null): bool
{
    static $cached = null;
    if ($cached !== null && $pdo === null) {
        return $cached;
    }
    try {
        $pdo = $pdo ?? db();
        $count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $result = $count > 0;
    } catch (Throwable $e) {
        $result = false;
    }
    if ($pdo === null) {
        $cached = $result;
    } else {
        $cached = $result;
    }
    return $result;
}

function auth_login(string $username, string $password, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $username = trim($username);
    if ($username === '' || $password === '') {
        return ['ok' => false, 'message' => 'Kullanıcı adı ve parola gerekli.'];
    }

    $user = auth_find_user($username, $pdo);
    if (!$user || !password_verify($password, (string)$user['password_hash'])) {
        return ['ok' => false, 'message' => 'Kullanıcı adı veya parola hatalı.'];
    }

    $perms = auth_load_permissions((string)$user['role_code'], $pdo);

    auth_session();
    session_regenerate_id(true);
    // CSRF token'ı oturum yenileme sonrası tazele.
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['auth'] = [
        'id' => (int)$user['id'],
        'username' => (string)$user['username'],
        'full_name' => (string)($user['full_name'] ?? $user['username']),
        'role_code' => (string)$user['role_code'],
        'perms' => $perms,
        'must_change' => ((int)($user['must_change_password'] ?? 0) === 1),
    ];

    try {
        $pdo->prepare("UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':id' => (int)$user['id']]);
    } catch (Throwable $e) {
        // last_login güncellenemese de girişi bozma.
    }

    return ['ok' => true];
}

function auth_logout(): void
{
    auth_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
}

function auth_change_password(int $userId, string $current, string $new, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (strlen($new) < 8) {
        return ['ok' => false, 'message' => 'Yeni parola en az 8 karakter olmalı.'];
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return ['ok' => false, 'message' => 'Kullanıcı bulunamadı.'];
    }
    if (!password_verify($current, (string)$user['password_hash'])) {
        return ['ok' => false, 'message' => 'Mevcut parola hatalı.'];
    }
    if (password_verify($new, (string)$user['password_hash'])) {
        return ['ok' => false, 'message' => 'Yeni parola eskisinden farklı olmalı.'];
    }

    $hash = password_hash($new, PASSWORD_BCRYPT);
    $pdo->prepare("UPDATE users SET password_hash = :h, must_change_password = 0 WHERE id = :id")
        ->execute([':h' => $hash, ':id' => $userId]);

    // Oturumdaki bayrağı güncelle.
    if (($_SESSION['auth']['id'] ?? null) === $userId) {
        $_SESSION['auth']['must_change'] = false;
    }
    return ['ok' => true];
}

/* ---------------- Sayfa / API koruyucuları ---------------- */

/** Uygulama köküne göre login/hedef yolları (alt klasör kurulumlarında da doğru). */
function auth_base_path(): string
{
    // includes/ bir seviye altında; kök = includes'ın üstü.
    $dir = str_replace('\\', '/', dirname(__DIR__));
    $docroot = str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($docroot !== '' && str_starts_with($dir, $docroot)) {
        $base = substr($dir, strlen($docroot));
        return rtrim('/' . ltrim($base, '/'), '/');
    }
    // Yedek: script dizininden türet.
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    return rtrim(str_replace('/api', '', dirname($script)), '/');
}

/** Sayfa koruyucu: giriş yoksa login.php'ye yönlendir. must_change ise account.php'ye. */
function auth_require_login_page(): void
{
    auth_session();

    if (!auth_system_available()) {
        if (function_exists('legacy_require_panel_auth')) {
            legacy_require_panel_auth();
        }
        return;
    }

    if (!auth_check()) {
        $base = auth_base_path();
        $next = rawurlencode((string)($_SERVER['REQUEST_URI'] ?? ($base . '/index.php')));
        header('Location: ' . $base . '/login.php?next=' . $next);
        exit;
    }

    $u = auth_user();
    if (!empty($u['must_change'])) {
        $self = basename(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
        if ($self !== 'account.php' && $self !== 'logout.php') {
            header('Location: ' . auth_base_path() . '/account.php?force=1');
            exit;
        }
    }
}

/** API koruyucu: giriş yoksa 401 JSON. */
function auth_require_api(): void
{
    auth_session();

    if (!auth_system_available()) {
        if (function_exists('legacy_require_api_auth')) {
            legacy_require_api_auth();
        }
        return;
    }

    if (!auth_check()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Oturum gerekli. Lütfen giriş yapın.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/** Yetki koruyucu (sayfa). */
function require_permission_page(string $permission): void
{
    auth_require_login_page();
    if (auth_system_available() && !user_can($permission)) {
        http_response_code(403);
        $name = htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'Panel', ENT_QUOTES, 'UTF-8');
        echo "<!doctype html><meta charset='utf-8'><title>Yetkisiz</title>"
            . "<div style='font-family:system-ui;max-width:520px;margin:12vh auto;padding:24px;text-align:center'>"
            . "<h1 style='font-size:20px'>Bu sayfaya erişim yetkiniz yok</h1>"
            . "<p style='color:#64748b'>{$name} — gerekli yetki: <code>" . htmlspecialchars($permission, ENT_QUOTES, 'UTF-8') . "</code></p>"
            . "<p><a href='" . htmlspecialchars(auth_base_path(), ENT_QUOTES, 'UTF-8') . "/index.php'>Panele dön</a></p></div>";
        exit;
    }
}

/** Yetki koruyucu (API). */
function require_permission_api(string $permission): void
{
    auth_require_api();
    if (auth_system_available() && !user_can($permission)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Bu işlem için yetkiniz yok.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
