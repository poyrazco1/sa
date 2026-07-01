<?php
declare(strict_types=1);

final class TsoftRestClient
{
    private string $baseUrl;
    private string $token;
    private string $columns;
    private int $timeout;
    private int $connectTimeout;
    private string $postStyle;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');
        $this->token = trim((string)($config['token'] ?? ''));
        $this->columns = (string)($config['product_columns'] ?? 'ProductId,ProductCode,ProductName,Barcode,Stock,Currency,CurrencyId,BuyingPrice,SellingPrice,Width,Height,Depth,CBM');
        $this->timeout = max(5, (int)($config['timeout'] ?? 20));
        $this->connectTimeout = max(3, (int)($config['connect_timeout'] ?? 10));
        $this->postStyle = (string)($config['post_style'] ?? 'exact_console');
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== ''
            && $this->token !== ''
            && $this->token !== 'BURAYA_TSOFT_REST1_TOKEN_GELECEK';
    }

    /**
     * Tek arama kutusu için akıllı arama:
     * - 8+ haneli sayı: barkod exact
     * - PYRZ ile başlayan kod: ProductCode exact
     * - diğerleri: ProductName contain
     * İlk exact arama sonuç vermezse ürün adında da arar.
     */
    public function searchProducts(string $query, int $limit = 20, int $start = 0): array
    {
        $query = trim($query);
        $limit = max(1, min(50, $limit));
        $start = max(0, $start);

        if (mb_strlen($query, 'UTF-8') < 2) {
            throw new RuntimeException('Arama için en az 2 karakter gir.');
        }

        $filters = $this->buildFilterPlan($query);
        $lastResponse = null;

        foreach ($filters as $filter) {
            $response = $this->request('product/getProducts', [
                'limit' => (string)$limit,
                'start' => (string)$start,
                'columns' => $this->columns,
                'f' => $filter,
                'orderby' => 'ProductName ASC',
                'FetchDiscountedPrice' => 'false',
                'FetchAllCategories' => 'false',
                'FetchPriceWithComma' => 'false',
                'FetchMultipleDiscount' => 'false',
            ]);

            $lastResponse = $response;
            $rows = $response['data'] ?? [];
            if (is_array($rows) && count($rows) > 0) {
                return array_values(array_map([$this, 'normalizeProduct'], $rows));
            }
        }

        if (is_array($lastResponse) && isset($lastResponse['data']) && is_array($lastResponse['data'])) {
            return [];
        }

        return [];
    }

    public function getProductByCode(string $productCode): ?array
    {
        $productCode = trim($productCode);
        if ($productCode === '') {
            return null;
        }

        $response = $this->request('product/getProducts', [
            'limit' => '1',
            'start' => '0',
            'columns' => $this->columns,
            'f' => 'ProductCode|' . $this->sanitizeFilterValue($productCode) . '|equal',
            'orderby' => 'ProductName ASC',
            'FetchPriceWithComma' => 'false',
        ]);

        $rows = $response['data'] ?? [];
        return is_array($rows) && isset($rows[0]) ? $this->normalizeProduct($rows[0]) : null;
    }

    private function request(string $method, array $fields): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('T-Soft token ayarı eksik. config/tsoft.php dosyasını doldur.');
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL eklentisi aktif değil.');
        }

        $url = $this->baseUrl . '/' . ltrim($method, '/');
        $fields = ['token' => $this->token] + $fields;

        $postFields = $this->postStyle === 'query'
            ? http_build_query($fields, '', '&')
            : $fields;

        $curlOptions = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: PoyrazTonerPriceWizard/1.0',
            ],
        ];

        if (str_starts_with(strtolower($url), 'https://')) {
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = true;
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = 2;
        }

        if ($this->postStyle === 'query') {
            $curlOptions[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, $curlOptions);

        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            $this->logError('T-Soft cURL boş cevap/hata: ' . $curlError);
            throw new RuntimeException('T-Soft bağlantı hatası.');
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logError('T-Soft HTTP hata kodu: ' . $httpCode . ' cevap: ' . mb_substr((string)$raw, 0, 800, 'UTF-8'));
            throw new RuntimeException('T-Soft HTTP hata kodu: ' . $httpCode);
        }

        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            $this->logError('T-Soft JSON çözümlenemedi: ' . mb_substr((string)$raw, 0, 800, 'UTF-8'));
            throw new RuntimeException('T-Soft JSON cevap hatası.');
        }

        if (isset($data['success']) && $data['success'] === false) {
            $message = $this->extractMessage($data) ?: 'T-Soft işlem başarısız.';
            $this->logError('T-Soft success=false: ' . $message);

            if (stripos($message, 'Yetkisiz IP') !== false || stripos($message, 'IP') !== false) {
                throw new RuntimeException($message . ' T-Soft web servis kullanıcısına PHP panelinin dışarı çıkan Plesk/sunucu IP adresini yazmalısın. Gerçek çıkış IP kontrolü: api/tsoft_ip_check.php.');
            }

            throw new RuntimeException($message);
        }

        return $data;
    }

    private function buildFilterPlan(string $query): array
    {
        $clean = $this->sanitizeFilterValue($query);
        $compact = preg_replace('/\s+/', '', $clean) ?: $clean;
        $filters = [];

        if (preg_match('/^\d{8,}$/', $compact)) {
            $filters[] = 'Barcode|' . $compact . '|equal';
        }

        if (preg_match('/^PYRZ[0-9A-Z\-_]+$/i', $compact)) {
            $filters[] = 'ProductCode|' . strtoupper($compact) . '|equal';
        }

        if (preg_match('/^[A-Z0-9\-_\.]{3,}$/i', $compact) && !preg_match('/^\d{8,}$/', $compact)) {
            $filters[] = 'ProductCode|' . $compact . '|equal';
        }

        $filters[] = 'ProductName|' . $clean . '|contain';

        return array_values(array_unique($filters));
    }

    private function sanitizeFilterValue(string $value): string
    {
        $value = trim($value);
        $value = str_replace(['|', "
", "
", "	"], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?: '';
        return mb_substr($value, 0, 120, 'UTF-8');
    }

    public function normalizeProduct(array $product): array
    {
        $width = $this->toFloat($product['Width'] ?? 0);
        $height = $this->toFloat($product['Height'] ?? 0);
        $depth = $this->toFloat($product['Depth'] ?? 0);
        $cbm = $this->toFloat($product['CBM'] ?? 0);

        $desi = 0.0;
        if ($cbm > 0) {
            $desi = $cbm;
        } elseif ($width > 0 && $height > 0 && $depth > 0) {
            $desi = round(($width * $height * $depth) / 3000, 2);
        }

        $currency = strtoupper(trim((string)($product['Currency'] ?? '')));
        if ($currency === 'TRY') {
            $currency = 'TL';
        }
        if (!in_array($currency, ['TL', 'USD', 'EUR'], true)) {
            $currency = $currency ?: 'TL';
        }

        return [
            'tsoft_product_id' => (string)($product['ProductId'] ?? ''),
            'ws_product_code' => (string)($product['ProductCode'] ?? ''),
            'product_name' => trim((string)($product['ProductName'] ?? '')),
            'barcode' => (string)($product['Barcode'] ?? ''),
            'stock' => $this->toFloat($product['Stock'] ?? 0),
            'currency_id' => (string)($product['CurrencyId'] ?? ''),
            'currency_code' => $currency,
            'purchase_price' => $this->toFloat($product['BuyingPrice'] ?? 0),
            'sale_price' => $this->toFloat($product['SellingPrice'] ?? 0),
            'width' => $width,
            'height' => $height,
            'depth' => $depth,
            'cbm' => $cbm,
            'desi' => $desi,
        ];
    }

    private function toFloat(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }
        $value = trim((string)$value);
        if ($value === '') {
            return 0.0;
        }
        $value = str_replace(' ', '', $value);
        if (str_contains($value, ',') && !str_contains($value, '.')) {
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
        return is_numeric($value) ? (float)$value : 0.0;
    }

    private function extractMessage(array $data): string
    {
        $messages = $data['message'] ?? null;
        if (is_array($messages)) {
            foreach ($messages as $message) {
                if (is_array($message)) {
                    $text = $message['text'] ?? '';
                    if (is_array($text)) {
                        $text = implode(' ', array_filter(array_map('strval', $text)));
                    }
                    $text = trim((string)$text);
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }
        return '';
    }

    private function logError(string $message): void
    {
        $safe = str_replace($this->token, '[TSOFT_TOKEN]', $message);
        error_log('[TsoftRestClient] ' . $safe);
    }
}
