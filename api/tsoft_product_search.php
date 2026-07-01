<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_panel_auth();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function tsoft_json_response(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    require_once __DIR__ . '/../includes/TsoftRestClient.php';

    $query = trim((string)($_GET['q'] ?? ''));
    $limit = (int)($_GET['limit'] ?? 20);

    if (mb_strlen($query, 'UTF-8') < 2) {
        tsoft_json_response([
            'ok' => false,
            'message' => 'Arama için en az 2 karakter yaz.',
            'data' => [],
        ], 422);
    }

    if (mb_strlen($query, 'UTF-8') > 120) {
        tsoft_json_response([
            'ok' => false,
            'message' => 'Arama metni çok uzun.',
            'data' => [],
        ], 422);
    }

    $config = require __DIR__ . '/../config/tsoft.php';
    $client = new TsoftRestClient($config);
    $products = $client->searchProducts($query, $limit ?: 20);

    tsoft_json_response([
        'ok' => true,
        'message' => count($products) ? 'Ürün bulundu.' : 'Ürün bulunamadı.',
        'data' => $products,
    ]);
} catch (Throwable $e) {
    error_log('[tsoft_product_search] ' . $e->getMessage());
    tsoft_json_response([
        'ok' => false,
        'message' => $e->getMessage() ?: 'T-Soft ürün arama hatası.',
        'data' => [],
    ], 500);
}
