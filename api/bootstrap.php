<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

/**
 * Panel/API kimlik doğrulaması.
 * PANEL_ACCESS_CODE ortam değişkeni tanımlıysa, oturum açılmadan
 * bu API'lere erişim 401 ile reddedilir. Tanımlı değilse serbest kalır.
 */
require_once __DIR__ . '/../config/app.php';
if (function_exists('require_api_auth')) {
    require_api_auth();
}

function json_response(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Basit CSRF koruması (aynı-köken kontrolü).
 * Durum değiştiren isteklerde (POST/PUT/PATCH/DELETE) tarayıcı Origin/Referer
 * gönderir. Bu değer sunucunun host'uyla eşleşmiyorsa istek reddedilir.
 * Panel kendi fetch çağrılarını aynı kökene attığı için mevcut akış bozulmaz;
 * yalnızca dış sitelerden gelen sahte POST'lar engellenir. Origin/Referer hiç
 * yoksa (ör. sunucu-sunucu araçları) engellenmez.
 */
function require_same_origin(): void
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    // HTTP_HOST port içerebilir; parse_url host'u içermez. Karşılaştırma için portu at.
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    if ($host === '') {
        return;
    }

    $source = (string)($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
    if ($source === '') {
        return; // Başlık yoksa (tarayıcı dışı istemci) engelleme.
    }

    $sourceHost = strtolower((string)parse_url($source, PHP_URL_HOST));
    if ($sourceHost === '' || $sourceHost !== $host) {
        json_response(['ok' => false, 'message' => 'Güvenlik: geçersiz istek kaynağı.'], 403);
    }
}

require_same_origin();

function request_json(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}
