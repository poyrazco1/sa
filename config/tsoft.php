<?php
declare(strict_types=1);

/**
 * T-Soft REST1 ürün okuma ayarları.
 *
 * GÜVENLİK: Token artık öncelikli olarak ortam değişkeninden (TSOFT_REST1_TOKEN)
 * okunur. Ortam değişkeni yoksa, versiyon kontrolüne girmeyen
 * config/secrets.local.php içindeki putenv('TSOFT_REST1_TOKEN=...') değeri kullanılır.
 * Son çare olarak alttaki varsayılan token devreye girer; üretimde bunu
 * ortam değişkenine taşıyıp buradaki düz metin değeri kaldırman önerilir.
 * Bu dosya frontend'e asla yüklenmez; token JavaScript tarafına basılmaz.
 */

$__secretsLocal = __DIR__ . '/secrets.local.php';
if (is_file($__secretsLocal)) {
    require $__secretsLocal;
}

// GÜVENLİK: Token artık kaynak koda gömülmez. Gerçek değer ortam değişkeninden
// (TSOFT_REST1_TOKEN) veya versiyon kontrolüne girmeyen config/secrets.local.php'den gelir.
$tsoftToken = getenv('TSOFT_REST1_TOKEN');
if ($tsoftToken === false) {
    $tsoftToken = '';
}

return [
    'base_url' => getenv('TSOFT_REST1_BASE_URL') ?: 'http://www.poyraztoner.com/rest1',
    'token' => $tsoftToken,
    'timeout' => 20,
    'connect_timeout' => 10,
    // T-Soft REST1 Console örnek kodu POSTFIELDS alanını dizi olarak gönderiyor.
    // Bu yüzden varsayılanı exact_console yaptık.
    'post_style' => 'exact_console',
    'product_columns' => 'ProductId,ProductCode,ProductName,Barcode,Stock,Currency,CurrencyId,BuyingPrice,SellingPrice,Width,Height,Depth,CBM',
    'default_limit' => 20,
    'max_limit' => 50,
];
