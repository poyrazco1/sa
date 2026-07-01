<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_panel_auth();

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function curl_text(string $url): string
{
    if (!function_exists('curl_init')) {
        return '';
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => str_starts_with($url, 'https://'),
        CURLOPT_SSL_VERIFYHOST => str_starts_with($url, 'https://') ? 2 : 0,
        CURLOPT_USERAGENT => 'PoyrazTonerPriceWizard-IPCheck/1.1',
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return is_string($body) ? trim($body) : '';
}

$checks = [
    'api.ipify.org' => curl_text('https://api.ipify.org'),
    'ifconfig.me' => curl_text('https://ifconfig.me/ip'),
    'icanhazip.com' => curl_text('https://icanhazip.com'),
];

echo "T-Soft'a yazılacak IP, aşağıdaki DIŞ ÇIKIŞ IP değeridir.\n";
echo "Birden fazla servis aynı IP'yi veriyorsa doğru değer odur.\n\n";

foreach ($checks as $name => $ip) {
    $ip = trim($ip);
    echo $name . ': ' . ($ip !== '' ? $ip : 'alınamadı') . "\n";
}

echo "\nSunucu bilgisi:\n";
echo 'SERVER_ADDR: ' . ($_SERVER['SERVER_ADDR'] ?? '-') . "\n";
echo 'SERVER_NAME: ' . ($_SERVER['SERVER_NAME'] ?? '-') . "\n";
