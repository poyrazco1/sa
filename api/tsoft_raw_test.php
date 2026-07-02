<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_api_auth();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function mask_token(string $token): string
{
    $token = trim($token);
    return strlen($token) > 10 ? substr($token, 0, 4) . str_repeat('*', strlen($token) - 8) . substr($token, -4) : str_repeat('*', strlen($token));
}

$config = require __DIR__ . '/../config/tsoft.php';
$baseUrl = rtrim((string)($config['base_url'] ?? 'http://www.poyraztoner.com/rest1'), '/');
$token = trim((string)($config['token'] ?? ''));
$columns = (string)($config['product_columns'] ?? 'ProductId,ProductCode,ProductName,Barcode,Stock,Currency,CurrencyId,BuyingPrice,SellingPrice,Width,Height,Depth,CBM');

if ($token === '' || $token === 'BURAYA_TSOFT_REST1_TOKEN_GELECEK') {
    out(['ok' => false, 'message' => 'T-Soft token ayarı eksik.'], 500);
}

$url = $baseUrl . '/product/getProducts';
$fields = [
    'token' => $token,
    'limit' => '10',
    'start' => '0',
    'columns' => $columns,
    'f' => (string)($_GET['f'] ?? ''),
    'f2' => '',
    'orderby' => 'ProductName ASC',
    'FetchDiscountedPrice' => 'false',
    'FetchAllCategories' => 'false',
    'FetchPriceWithComma' => 'false',
    'FetchMultipleDiscount' => 'false',
    'FlexiblePrices' => '',
    'StockFields' => '',
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $fields); // T-Soft Console örnek kodundaki gibi dizi gönderim.
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
if (str_starts_with(strtolower($url), 'https://')) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
}

$response = curl_exec($ch);
$error = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$decoded = is_string($response) ? json_decode($response, true) : null;

out([
    'ok' => is_array($decoded) && (($decoded['success'] ?? false) === true),
    'http_code' => $httpCode,
    'url' => $url,
    'token_masked' => mask_token($token),
    'curl_error' => $error,
    'request_note' => 'Bu endpoint, T-Soft Console örnek koduyla aynı ham POSTFIELDS dizi mantığını kullanır.',
    'decoded' => $decoded,
    'raw_preview' => is_string($response) ? mb_substr($response, 0, 1000, 'UTF-8') : null,
], ($httpCode >= 200 && $httpCode < 300) ? 200 : 500);
