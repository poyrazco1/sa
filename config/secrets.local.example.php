<?php
declare(strict_types=1);

/**
 * ÖRNEK yerel sırlar dosyası.
 *
 * Kullanım:
 *   1) Bu dosyayı kopyala:  config/secrets.local.php
 *   2) Değerleri gerçek bilgilerle doldur.
 *   3) config/secrets.local.php dosyasını ASLA versiyon kontrolüne ekleme
 *      (.gitignore zaten hariç tutuyor) ve web'e açma (.htaccess engelliyor).
 *
 * Alternatif: Bu değerleri Plesk > Ortam Değişkenleri altında da tanımlayabilirsin;
 * o zaman bu dosyaya gerek kalmaz.
 */

// Veritabanı
putenv('DB_HOST=localhost');
putenv('DB_PORT=3306');
putenv('DB_NAME=veritabani_adi');
putenv('DB_USER=veritabani_kullanicisi');
putenv('DB_PASS=BURAYA_GERCEK_SIFRE');

// T-Soft REST1
putenv('TSOFT_REST1_TOKEN=BURAYA_GERCEK_TOKEN');
putenv('TSOFT_REST1_BASE_URL=http://www.poyraztoner.com/rest1');

// Panel giriş kodu (tanımlanırsa panel bu kodla kilitlenir; boş bırakırsan panel açık kalır)
putenv('PANEL_ACCESS_CODE=');
