<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
@include_once __DIR__ . '/../config/db.php';

function fetch_json(string $url): ?array
{
    $raw = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: PoyrazTonerPanel/1.0',
            ],
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $raw === '' || $code < 200 || $code >= 300) {
            $raw = null;
        }
    }

    if ($raw === null) {
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: PoyrazTonerPanel/1.0\r\n",
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false || $raw === '') {
            return null;
        }
    }

    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : null;
}

function normalize_try_rate(mixed $value, string $currency): ?float
{
    if (!is_numeric($value)) {
        return null;
    }

    $rate = (float)$value;
    if ($rate <= 0) {
        return null;
    }

    // TRY/USD gibi ters oran gelirse düzelt.
    if ($rate > 0 && $rate < 1) {
        $rate = 1 / $rate;
    }

    $max = $currency === 'EUR' ? 300 : 250;
    if ($rate < 10 || $rate > $max) {
        return null;
    }

    return round($rate, 6);
}

function parse_try_rate(?array $data, string $currency): ?float
{
    if (!$data) {
        return null;
    }

    $candidates = [
        $data['rates']['TRY'] ?? null,
        $data['conversion_rates']['TRY'] ?? null,
        $data['TRY'] ?? null,
        $data['data']['TRY'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        $rate = normalize_try_rate($candidate, $currency);
        if ($rate !== null) {
            return $rate;
        }
    }

    return null;
}

function live_rate(string $currency): array
{
    $urls = [
        "https://api.frankfurter.app/latest?from={$currency}&to=TRY",
        "https://api.frankfurter.dev/v1/latest?from={$currency}&to=TRY",
        "https://api.frankfurter.dev/v1/latest?base={$currency}&symbols=TRY",
        "https://open.er-api.com/v6/latest/{$currency}",
        "https://api.exchangerate-api.com/v4/latest/{$currency}",
    ];

    foreach ($urls as $url) {
        $data = fetch_json($url);
        $rate = parse_try_rate($data, $currency);

        if ($rate !== null) {
            return [
                'rate' => $rate,
                'source' => parse_url($url, PHP_URL_HOST) ?: 'live',
                'date' => $data['date'] ?? $data['time_last_update_utc'] ?? null,
            ];
        }
    }

    throw new RuntimeException("{$currency}/TRY kuru alınamadı.");
}


function save_rate_to_db(string $currency, float $rate, string $source, mixed $date, array $payload): void
{
    if (!function_exists('db')) {
        return;
    }

    try {
        $pdo = db();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS currency_rates (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                source VARCHAR(120) NULL,
                rate_date VARCHAR(80) NULL,
                base_currency VARCHAR(10) NOT NULL,
                quote_currency VARCHAR(10) NOT NULL DEFAULT 'TRY',
                rate DECIMAL(18,6) NOT NULL,
                payload LONGTEXT NULL,
                PRIMARY KEY (id),
                KEY idx_currency_pair_created (base_currency, quote_currency, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $stmt = $pdo->prepare("
            INSERT INTO currency_rates (source, rate_date, base_currency, quote_currency, rate, payload)
            VALUES (:source, :rate_date, :base_currency, 'TRY', :rate, :payload)
        ");

        $stmt->execute([
            ':source' => $source,
            ':rate_date' => is_scalar($date) ? (string)$date : null,
            ':base_currency' => $currency,
            ':rate' => $rate,
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
        // Kur ekranını DB log hatası yüzünden bozma.
    }
}


try {
    $usd = live_rate('USD');
    $eur = live_rate('EUR');

    save_rate_to_db('USD', (float)$usd['rate'], (string)$usd['source'], $usd['date'] ?? null, $usd);
    save_rate_to_db('EUR', (float)$eur['rate'], (string)$eur['source'], $eur['date'] ?? null, $eur);

    echo json_encode([
        'ok' => true,
        'source' => $usd['source'] === $eur['source'] ? $usd['source'] : $usd['source'] . ' + ' . $eur['source'],
        'date' => $usd['date'] ?? $eur['date'] ?? null,
        'rates' => [
            'TL' => 1,
            'USD' => $usd['rate'],
            'EUR' => $eur['rate'],
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'message' => 'Canlı kur alınamadı.',
        'rates' => [
            'TL' => 1,
            'USD' => null,
            'EUR' => null,
        ],
    ], JSON_UNESCAPED_UNICODE);
}
