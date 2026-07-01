<?php
declare(strict_types=1);

/**
 * Veritabanı bağlantısı.
 *
 * GÜVENLİK: Kimlik bilgileri artık öncelikli olarak ORTAM DEĞİŞKENLERİNDEN
 * (Plesk > Hosting Ayarları > Ortam Değişkenleri, ya da .htaccess SetEnv) okunur.
 * Ortam değişkeni tanımlı değilse, geriye dönük uyumluluk için alttaki
 * varsayılan sabitler kullanılır. Üretimde ortam değişkenlerini tanımlayıp
 * bu dosyadaki düz metin şifreyi kaldırman önerilir.
 *
 * Alternatif olarak, versiyon kontrolüne girmeyen bir config/secrets.local.php
 * dosyası oluşturup içinde putenv('DB_PASS=...') tanımlayabilirsin.
 */

$__secretsLocal = __DIR__ . '/secrets.local.php';
if (is_file($__secretsLocal)) {
    require $__secretsLocal;
}

/** Ortam değişkenini oku; yoksa verilen varsayılana düş. */
function db_env(string $key, string $default): string
{
    $val = getenv($key);
    return ($val !== false && $val !== '') ? $val : $default;
}

define('DB_HOST', db_env('DB_HOST', 'localhost'));
define('DB_PORT', db_env('DB_PORT', '3306'));
define('DB_NAME', db_env('DB_NAME', 'sgdqdtnu_poyraztech_eticaret'));
define('DB_USER', db_env('DB_USER', 'sgdqdtnu_poyraztech_eticaret_user'));
// GÜVENLİK: DB şifresi artık kaynak koda gömülmez. Gerçek değer ortam değişkeninden
// (DB_PASS) veya versiyon kontrolüne girmeyen config/secrets.local.php içinden gelir.
define('DB_PASS', db_env('DB_PASS', ''));

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
