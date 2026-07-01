<?php
declare(strict_types=1);

/**
 * PoyrazTech root API endpoint
 *
 * Kullanım örnekleri:
 * - /api.php?action=tsoft_product_search&q=hp
 * - /api.php?action=tsoft_ip_check
 * - /api.php?action=tsoft_connection_test&q=hp
 * - /api.php?action=tsoft_raw_test
 *
 * Bu dosya hem proje kökünde hem de domain kökünde çalışacak şekilde yazıldı.
 * Eğer dosya domain köküne (/poyraztech.com/api.php) koyulursa, alttaki bilinen
 * proje klasörlerini otomatik bulur.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function pw_api_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function pw_mask_secret(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $len = strlen($value);
    if ($len <= 8) {
        return str_repeat('*', $len);
    }
    return substr($value, 0, 4) . str_repeat('*', max(4, $len - 8)) . substr($value, -4);
}

function pw_find_project_root(): string
{
    $base = __DIR__;

    $candidates = [
        $base,
        $base . '/akilli-fiyat-sihirbazi-php-v78',
        $base . '/akilli-fiyat-sihirbazi-php-v77',
        $base . '/akilli-fiyat-sihirbazi-php-v76',
        $base . '/akilli-fiyat-sihirbazi-php-v75',
        $base . '/akilli-fiyat-sihirbazi-php-v74',
        $base . '/akilli-fiyat-sihirbazi-php-v73',
        $base . '/akilli-fiyat-sihirbazi-php-v72',
        $base . '/akilli-fiyat-sihirbazi-php-v71',
        $base . '/akilli-fiyat-sihirbazi-php-v70',
        $base . '/akilli-fiyat-sihirbazi',
    ];

    foreach ($candidates as $dir) {
        if (
            is_file($dir . '/config/tsoft.php') &&
            is_file($dir . '/includes/TsoftRestClient.php')
        ) {
            return realpath($dir) ?: $dir;
        }
    }

    $matches = glob($base . '/akilli-fiyat-sihirbazi-php-v*/config/tsoft.php') ?: [];
    rsort($matches, SORT_NATURAL);
    foreach ($matches as $configPath) {
        $dir = dirname(dirname($configPath));
        if (is_file($dir . '/includes/TsoftRestClient.php')) {
            return realpath($dir) ?: $dir;
        }
    }

    pw_api_response([
        'ok' => false,
        'message' => 'Proje kökü bulunamadı. api.php dosyasını proje köküne koy veya proje klasörünü poyraztech.com altında tut.',
        'checked' => array_map(static fn($p) => str_replace($base, '.', $p), $candidates),
    ], 500);
}

function pw_load_tsoft_client(string $projectRoot): array
{
    $appConfig = $projectRoot . '/config/app.php';
    if (is_file($appConfig)) {
        require_once $appConfig;
        if (function_exists('require_panel_auth')) {
            require_panel_auth();
        }
    }

    require_once $projectRoot . '/includes/TsoftRestClient.php';
    $config = require $projectRoot . '/config/tsoft.php';

    return [$config, new TsoftRestClient($config)];
}

function pw_get_outgoing_ip(): string
{
    $urls = [
        'https://api.ipify.org',
        'https://ifconfig.me/ip',
        'https://icanhazip.com',
    ];

    foreach ($urls as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['User-Agent: PoyrazTonerPriceWizard/1.0'],
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        $ip = trim((string)$raw);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return '';
}

function pw_tsoft_product_search(string $projectRoot): void
{
    [$config, $client] = pw_load_tsoft_client($projectRoot);

    $query = trim((string)($_GET['q'] ?? ''));
    $limit = (int)($_GET['limit'] ?? ($config['default_limit'] ?? 20));

    if (mb_strlen($query, 'UTF-8') < 2) {
        pw_api_response([
            'ok' => false,
            'message' => 'Arama için en az 2 karakter yaz.',
            'data' => [],
        ], 422);
    }

    if (mb_strlen($query, 'UTF-8') > 120) {
        pw_api_response([
            'ok' => false,
            'message' => 'Arama metni çok uzun.',
            'data' => [],
        ], 422);
    }

    $products = $client->searchProducts($query, $limit ?: 20);

    pw_api_response([
        'ok' => true,
        'endpoint' => 'https://poyraztech.com/api.php',
        'action' => 'tsoft_product_search',
        'message' => count($products) ? 'Ürün bulundu.' : 'Ürün bulunamadı.',
        'data' => $products,
    ]);
}

function pw_tsoft_connection_test(string $projectRoot): void
{
    [$config, $client] = pw_load_tsoft_client($projectRoot);

    $query = trim((string)($_GET['q'] ?? 'hp'));
    if (mb_strlen($query, 'UTF-8') < 2) {
        $query = 'hp';
    }

    $products = $client->searchProducts($query, 5);

    pw_api_response([
        'ok' => true,
        'endpoint' => 'https://poyraztech.com/api.php',
        'action' => 'tsoft_connection_test',
        'message' => 'T-Soft bağlantısı başarılı.',
        'project_root' => basename($projectRoot),
        'outgoing_ip' => pw_get_outgoing_ip(),
        'tsoft_base_url' => (string)($config['base_url'] ?? ''),
        'token_masked' => pw_mask_secret((string)($config['token'] ?? '')),
        'sample_count' => count($products),
        'data' => $products,
    ]);
}

function pw_tsoft_raw_test(string $projectRoot): void
{
    [$config] = pw_load_tsoft_client($projectRoot);

    $baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');
    $token = trim((string)($config['token'] ?? ''));
    $columns = (string)($config['product_columns'] ?? 'ProductId,ProductCode,ProductName,Barcode,Stock,Currency,CurrencyId,BuyingPrice,SellingPrice,Width,Height,Depth,CBM');

    if ($baseUrl === '' || $token === '' || $token === 'BURAYA_TSOFT_REST1_TOKEN_GELECEK') {
        pw_api_response([
            'ok' => false,
            'message' => 'T-Soft token ayarı eksik. config/tsoft.php dosyasını doldur.',
        ], 500);
    }

    $fields = [
        'token' => $token,
        'limit' => '10',
        'start' => '0',
        'columns' => $columns,
        'orderby' => 'ProductName ASC',
        'FetchDiscountedPrice' => 'false',
        'FetchAllCategories' => 'false',
        'FetchPriceWithComma' => 'false',
        'FetchMultipleDiscount' => 'false',
    ];

    $url = $baseUrl . '/product/getProducts';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string)$raw, true);

    pw_api_response([
        'ok' => $httpCode >= 200 && $httpCode < 300 && is_array($decoded) && (($decoded['success'] ?? false) === true),
        'endpoint' => 'https://poyraztech.com/api.php',
        'action' => 'tsoft_raw_test',
        'project_root' => basename($projectRoot),
        'outgoing_ip' => pw_get_outgoing_ip(),
        'tsoft_url' => $url,
        'http_code' => $httpCode,
        'curl_error' => $error,
        'token_masked' => pw_mask_secret($token),
        'decoded' => $decoded,
        'raw_preview' => is_string($raw) ? mb_substr($raw, 0, 1200, 'UTF-8') : '',
    ], ($httpCode >= 200 && $httpCode < 300) ? 200 : 500);
}

try {
    $action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));
    if ($action === '') {
        $action = 'health';
    }

    $projectRoot = pw_find_project_root();

    switch ($action) {
        case 'health':
            pw_api_response([
                'ok' => true,
                'endpoint' => 'https://poyraztech.com/api.php',
                'message' => 'PoyrazTech API endpoint aktif.',
                'project_root' => basename($projectRoot),
                'actions' => [
                    'tsoft_product_search',
                    'tsoft_ip_check',
                    'tsoft_connection_test',
                    'tsoft_raw_test',
                ],
            ]);
            break;

        case 'tsoft_product_search':
        case 'product_search':
            pw_tsoft_product_search($projectRoot);
            break;

        case 'tsoft_ip_check':
        case 'ip_check':
            pw_api_response([
                'ok' => true,
                'endpoint' => 'https://poyraztech.com/api.php',
                'action' => 'tsoft_ip_check',
                'project_root' => basename($projectRoot),
                'outgoing_ip' => pw_get_outgoing_ip(),
                'message' => 'Bu IP T-Soft web servis kullanıcısındaki IP Adresleri alanında yazmalı.',
            ]);
            break;

        case 'tsoft_connection_test':
        case 'connection_test':
            pw_tsoft_connection_test($projectRoot);
            break;

        case 'tsoft_raw_test':
        case 'raw_test':
            pw_tsoft_raw_test($projectRoot);
            break;

        default:
            pw_api_response([
                'ok' => false,
                'message' => 'Geçersiz action.',
                'allowed_actions' => [
                    'tsoft_product_search',
                    'tsoft_ip_check',
                    'tsoft_connection_test',
                    'tsoft_raw_test',
                ],
            ], 404);
    }
} catch (Throwable $e) {
    error_log('[root_api] ' . $e->getMessage());
    pw_api_response([
        'ok' => false,
        'endpoint' => 'https://poyraztech.com/api.php',
        'message' => $e->getMessage() ?: 'API hatası.',
    ], 500);
}
