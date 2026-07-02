# Akıllı Fiyat Sihirbazı — PHP v79

Plain PHP 8.2+ / MySQL (MariaDB 10.6+) / Plesk uyumlu fiyatlama ve kargo etiketi paneli.
Framework, Composer, Node veya build sistemi **gerektirmez**. ZIP'i sunucuya atıp çalışır.

> Sürüm geçmişi için bkz. [CHANGELOG.md](CHANGELOG.md).

## Arayüz (v79 — yeniden tasarım)

Ekran arayüzü modern bir **B2B SaaS operasyon paneli** olarak yeniden tasarlandı. Tasarım
tamamen `assets/css/app.css` içindeki bölümlere ayrılmış bir tasarım sistemi + CSS
değişkenleri (tokens) üzerinden yürür; renk/typografi/spacing/shadow tek yerden yönetilir.

- **Palet:** cool light-gray zemin, tek mavi vurgu (#2563EB) + emerald başarı (#059669);
  abartılı gradient/neon yok.
- **Topbar:** başlık + alt açıklama, kompakt kur çipleri ve mini kur çevirici.
- **Modül geçişi:** segmented tab bar (aktif sekme net).
- **Fiyat sonucu:** kritik metrikler büyük KPI kartları olarak (satış/net kâr/toplam/kargo).
- **Toplu Fiyat:** zebra + sticky başlıklı, sağa hizalı tabular sayı içeren data table.
- **Kargo Etiketi:** form/adım/kayıt ekranları ve T-Soft modalı tek dilde.
- **Responsive:** 1440 / 1180 / 820 / 520 / 375 kırılımları; mobilde tek kolon, yatay
  kaydırılabilir tablolar, min 44px dokunmatik hedefler, görünür focus halkaları.
- **Print/etiket:** A4 / A5 / 10×10 / 15×10 çıktıları, QR ve yüksek kontrast siyah
  logo/kargo fallback'i içeren `@media print` sistemi **byte düzeyinde AYNEN korundu**.
  Baskıda arka plan/gölge/gradient kullanılmaz; alıcı adı en baskın alandır.

Bu refactor yalnızca `assets/css/app.css` + `includes/topbar.php` + `includes/header.php`
dosyalarına dokundu; `assets/js/app.js`, tüm API/PHP mantığı ve DB şeması değişmedi.
Tüm `id`, `onclick` ve JS bağlantıları korundu.

---

## 1. Modüller

### Akıllı Fiyat (`modules/price-wizard.php`)
- Maliyet TL / USD / EUR girilebilir.
- Her ürüne **gizli 0,10 USD sarf gideri** eklenir (kullanıcıya ayrıca gösterilmez).
- Net kâr oranı **minimum %5**; altına düşerse hesap yapılmaz, uyarı verilir.
- KDV hariç / KDV dahil satış, net kâr, kargo maliyeti, müşteriye yansıtılacak kargo,
  müşteriden alınacak KDV hariç toplam hesaplanır.
- Ödeme etkileri: kredi kartı, havale/EFT, 30 gün vade, 60 gün vade, 2 taksit.
- Satıldı / Satılmadı kaydı (`api/price_log_save.php` → `price_calculation_logs`).
- Üstte kur çevirici ve TL/USD/EUR karşılıkları.

### Toplu Fiyat (`modules/bulk-price.php`)
- Birden fazla ürün aynı anda hesaplanır. Format: `Ürün; Maliyet; Para; Kâr; Desi; Kargo; Ödeme`.
- Eksik kolonlar üstteki varsayılanlardan alınır; sadece maliyet yazılan satır da hesaplanır.
- Sonuçları **kopyala** ve **CSV indir**.
- **T-Soft ürün arama modalı** ile ürün aranıp satıra otomatik eklenir.

### Kargo Etiketi (`modules/shipping-label.php`)
- Müşteri listesi ile kayıtlı kargo etiketleri ayrı sekmelerde tutulur.
- Müşteri CSV içe/dışa aktar (`api/customer_csv.php`).
- Kayıtlı müşteriler düzenlenebilir/silinebilir; eski gönderi kayıtlarından türeyen
  satırlar gerçek müşteri kaydı değilse düzenlenemez/silinemez.
- DHL, Aras, Hepsijet; A4 / A5 / 10×10 / 15×10 çıktı türleri; her etikette QR.
- Baskıda renkli logo silik çıkarsa yüksek kontrast siyah yazı/logo fallback devreye girer.
- Kayıtlı etiketlerde tekil yazdırma, düzenleme, silme, toplu seçim ve toplu yazdırma
  (her etiket için ayrı kağıt türü) desteklenir.

### T-Soft entegrasyonu
- Token **frontend'e asla basılmaz**; tüm istekler PHP üzerinden yapılır.
- Root endpoint (`api.php`) korunur — bkz. bölüm 6.

---

## 2. Klasör yapısı

```
index.php                  Ana giriş (panel auth + layout)
api.php                    Root API endpoint (T-Soft testleri)
config/
  app.php                  Panel ayarları + opsiyonel giriş kodu (auth)
  db.php                   DB bağlantısı (PDO, prepared statements)
  tsoft.php                T-Soft REST1 ayarları
  secrets.local.example.php  Yerel sırlar ŞABLONU (kopyalanacak)
  secrets.local.php        Gerçek sırlar — .gitignore'da, commit EDİLMEZ
includes/                  header, footer, topbar, module-switch, user-gate
  TsoftRestClient.php      T-Soft REST istemcisi
modules/                   price-wizard, bulk-price, shipping-label
api/                       rates, customer_api, customer_csv,
                           price_log_save, shipping_label_save,
                           shipping_label_records, tsoft_* testleri, bootstrap
assets/css/app.css         Tasarım
assets/js/app.js           Ekran akışı ve hesaplama
database/install.sql       Tek kurulum SQL (Plesk import uyumlu)
```

---

## 3. Kurulum (Plesk)

1. ZIP içeriğini sitenin köküne (veya alt klasöre) yükle. Giriş dosyası `index.php`.
2. Plesk'te veritabanını oluştur:
   - DB: `sgdqdtnu_poyraztech_eticaret`
   - User: `sgdqdtnu_poyraztech_eticaret_user`
3. **DB import:** phpMyAdmin > seçili veritabanı > Import > `database/install.sql`.
   Dosyada `CREATE DATABASE` / `USE` **yoktur**; mevcut veriyi silen DROP/TRUNCATE **yoktur**.
   Tekrar çalıştırılabilir (idempotent): tablolar `IF NOT EXISTS`, seed satırları
   `ON DUPLICATE KEY UPDATE` ile gelir.
4. **Sırları ayarla** (bkz. bölüm 4). ZIP içindeki `config/secrets.local.php` gerçek
   değerlerle gelir; canlıda çalışması için yeterlidir.
5. (Opsiyonel) Paneli kilitlemek için `PANEL_ACCESS_CODE` ayarla.

---

## 4. Güvenlik / sırlar

DB şifresi ve T-Soft token'ı **kaynak koda gömülü değildir**. Öncelik sırası:

1. Ortam değişkeni (Plesk > Hosting Ayarları > Ortam Değişkenleri)
2. `config/secrets.local.php` (versiyon kontrolüne girmez, `.gitignore` + `.htaccess` korur)
3. Boş varsayılan (bağlantı başarısız olur, açık uyarı verir)

`config/secrets.local.php` örneği (`config/secrets.local.example.php` dosyasını kopyala):

```php
<?php
declare(strict_types=1);

// Veritabanı
putenv('DB_HOST=localhost');
putenv('DB_PORT=3306');
putenv('DB_NAME=veritabani_adi');
putenv('DB_USER=veritabani_kullanicisi');
putenv('DB_PASS=GERCEK_SIFRE');

// T-Soft REST1
putenv('TSOFT_REST1_TOKEN=GERCEK_TOKEN');
putenv('TSOFT_REST1_BASE_URL=http://www.poyraztoner.com/rest1');

// Panel giriş kodu (boşsa panel açık; kod yazarsan panel kilitlenir)
putenv('PANEL_ACCESS_CODE=');
```

Ek güvenlik önlemleri:
- Tüm DB erişimi **prepared statement** ile yapılır.
- HTML çıktılarında `htmlspecialchars` ile escape uygulanır.
- Yazma yapan API'lerde (POST) **aynı-köken (CSRF) kontrolü** vardır: dış sitelerden
  gelen sahte POST istekleri 403 ile reddedilir (`api/bootstrap.php` → `require_same_origin()`).
- `PANEL_ACCESS_CODE` tanımlıysa hem sayfa hem API oturum ister.
- `.htaccess` config klasörünü ve `.sql/.md/.txt/.log` uzantılarını web'e kapatır.
- Hiçbir gizli bilgi JavaScript'e, HTML'e veya API cevabına basılmaz (token maskeli gösterilir).

---

## 5. Veritabanı

`database/install.sql` şu tabloları kurar/seed eder:
`app_settings`, `payment_methods`, `cargo_carriers`, `cargo_tariffs`,
`currency_rates`, `price_calculation_logs`, `label_customers`,
`shipping_label_logs`, `shipping_label_pieces`, `print_output_logs`.

Geriye dönük uyumluluk: yazma API'leri gerektiğinde eksik tabloları `CREATE TABLE IF NOT
EXISTS` ile ve `shipping_label_logs` üzerindeki `customer_name` / `company_name`
kolonlarını güvenli `ALTER` kontrolüyle otomatik ekler. Mevcut veri bozulmaz.

Bu sürümde `install.sql` şeması **değişmedi**.

---

## 6. T-Soft test endpointleri

Root endpoint (`api.php`) korunur:

```
api.php                                     Sağlık kontrolü
api.php?action=tsoft_ip_check               Sunucunun dışarı çıkan IP'si
api.php?action=tsoft_raw_test               Ham cURL testi
api.php?action=tsoft_connection_test&q=hp   Bağlantı testi
api.php?action=tsoft_product_search&q=hp    Ürün arama (panelin kullandığı uç)
```

**"Yetkisiz IP adresi!" hatası** alırsan: bu bir kod hatası değildir.
`api.php?action=tsoft_ip_check` çıktısındaki **dış çıkış IP'sini** T-Soft > Web Servis
Kullanıcıları > IP Adresleri alanına eklemen gerekir. Kod tarafında IP uydurulmaz.

---

## 7. Test checklist

**PHP / mantık**
- [x] Tüm PHP dosyaları `php -l` (25/25 temiz, PHP 8.4 ile doğrulandı).
- [x] Ana sayfa açılıyor (HTTP 200), kullanıcı seçimi ve modül geçişleri çalışıyor.
- [x] Fiyat: TL maliyet, %5 kâr, %20 kâr, kredi kartı, havale/EFT — doğru.
- [x] %5 altı kâr engelleniyor: "Net kâr minimum %5 olmalı."
- [x] CSRF: dış kökenli POST 403; aynı-köken POST geçer.
- [ ] USD/EUR maliyet, 30/60 gün vade, 2 taksit: canlı kur API'si gerektirir
      (üretimde `api/rates.php` üzerinden çalışır).
- [ ] Toplu fiyat, kargo etiketi CSV/kayıt ve T-Soft canlı testleri: MySQL + canlı
      T-Soft erişimi gerektirir (kod yolları değişmedi).

**Arayüz / responsive (v79)**
- [x] Fiyat / Toplu Fiyat / Kargo Etiketi ekranları yeni tasarımla render.
- [x] Fiyat sonuç KPI kartları, ödeme notu ayıraçları, seçili tablo satırı doğru.
- [x] T-Soft ürün arama modalı (arama, boş durum) doğru.
- [x] Kargo etiketi önizlemesi (ALICI bloğu, meta kutuları, QR, barkod, gönderici) korunuyor.
- [x] Responsive: 1440 / 1180 / 820 / 520 / 375 — topbar, nav, formlar, tablo taşması OK.
- [~] Print A4/A5/10×10/15×10: print CSS byte-identical korundu (fiziksel baskı canlıda test edilmeli).

---

## 8. Bu sürümde değişen dosyalar

**v79 (arayüz)**

| Dosya | Değişiklik |
|-------|-----------|
| `assets/css/app.css` | Ekran arayüzü yeniden yazıldı (tasarım sistemi); print/label bölümü AYNEN korundu |
| `includes/topbar.php` | Başlık altına dashboard açıklama satırı eklendi |
| `includes/header.php` | CSS sürümü `?v=79` |

`assets/js/app.js` ve tüm API/PHP/DB mantığı v79'da **değişmedi**.

**v78 (güvenlik)**

| Dosya | Değişiklik |
|-------|-----------|
| `config/db.php` | DB şifresi kaynak koddan çıkarıldı; env/secrets.local'e taşındı |
| `config/tsoft.php` | T-Soft token kaynak koddan çıkarıldı; env/secrets.local'e taşındı |
| `config/secrets.local.php` | Gerçek sırlar (ZIP'te var, `.gitignore`'da — commit edilmez) |
| `api/bootstrap.php` | Yazma API'lerine aynı-köken (CSRF) kontrolü eklendi |
| `api.php` | Root endpoint proje klasörü aday listesi güncellendi |

Hesaplama mantığı, DB şeması ve mevcut özellikler **korunmuştur**.
