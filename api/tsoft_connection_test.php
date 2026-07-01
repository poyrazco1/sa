<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_panel_auth();
require_once __DIR__ . '/../includes/TsoftRestClient.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function json_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function mask_token(string $token): string
{
    $token = trim($token);
    if ($token === '') {
        return '';
    }
    if (strlen($token) <= 10) {
        return str_repeat('*', strlen($token));
    }
    return substr($token, 0, 4) . str_repeat('*', max(4, strlen($token) - 8)) . substr($token, -4);
}

function outgoing_ip(): string
{
    if (!function_exists('curl_init')) {
        return 'cURL yok';
    }
    $ch = curl_init('https://api.ipify.org');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'PoyrazTonerPriceWizard-Test/1.0',
    ]);
    $ip = curl_exec($ch);
    curl_close($ch);
    return is_string($ip) ? trim($ip) : '';
}

try {
    $config = require __DIR__ . '/../config/tsoft.php';
    $client = new TsoftRestClient($config);
    $q = trim((string)($_GET['q'] ?? 'hp'));
    if (mb_strlen($q, 'UTF-8') < 2) {
        $q = 'hp';
    }

    $products = $client->searchProducts($q, 3);

    json_out([
        'ok' => true,
        'message' => 'T-Soft bağlantısı çalışıyor.',
        'server_outgoing_ip' => outgoing_ip(),
        'base_url' => (string)($config['base_url'] ?? ''),
        'token_masked' => mask_token((string)($config['token'] ?? '')),
        'query' => $q,
        'found_count' => count($products),
        'sample_products' => $products,
    ]);
} catch (Throwable $e) {
    $config = is_file(__DIR__ . '/../config/tsoft.php') ? (require __DIR__ . '/../config/tsoft.php') : [];
    json_out([
        'ok' => false,
        'message' => $e->getMessage(),
        'server_outgoing_ip' => outgoing_ip(),
        'base_url' => (string)($config['base_url'] ?? ''),
        'token_masked' => mask_token((string)($config['token'] ?? '')),
    ], 500);
}
