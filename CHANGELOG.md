# Değişiklik Günlüğü (CHANGELOG)

Bu proje "Akıllı Fiyat Sihirbazı" PHP panelidir. Sürüm adları paket klasör adıyla eşleşir.

## v78 — Güvenlik sertleştirme + dokümantasyon eşitleme

### Güvenlik
- `config/db.php`: DB şifresi kaynak koddan **kaldırıldı**. Değer artık `DB_PASS`
  ortam değişkeninden veya `config/secrets.local.php` içinden gelir; varsayılan boştur.
- `config/tsoft.php`: T-Soft REST1 token'ı kaynak koddan **kaldırıldı**. Değer artık
  `TSOFT_REST1_TOKEN` ortam değişkeninden veya `config/secrets.local.php` içinden gelir.
- `config/secrets.local.php`: gerçek sırları tutan yerel dosya. `.gitignore` ve
  `.htaccess` ile korunur; versiyon kontrolüne **girmez**, ZIP paketinde bulunur.
- `api/bootstrap.php`: yazma yapan tüm API'lere (POST/PUT/PATCH/DELETE) **aynı-köken
  (CSRF) kontrolü** eklendi. Dış sitelerden gelen sahte istekler 403 ile reddedilir;
  panelin kendi aynı-köken çağrıları etkilenmez.

### Dokümantasyon
- `README.md` v70'ten v78'e güncellendi ve baştan yapılandırıldı (kurulum, Plesk,
  DB import, secrets örneği, T-Soft endpointleri, modül açıklamaları, test checklist,
  değişen dosyalar).
- `CHANGELOG.md` eklendi.

### Diğer
- `api.php`: root endpoint'in proje klasörünü otomatik bulma aday listesi v71–v78
  sürümlerini kapsayacak şekilde güncellendi (glob fallback zaten mevcuttu).

### Korunanlar (bilinçli olarak değiştirilmedi)
- Fiyat hesaplama mantığı (gizli 0,10 USD sarf gideri, minimum %5 net kâr, KDV,
  kargo, ödeme etkileri, satıldı/satılmadı kaydı).
- Toplu Fiyat ve Kargo Etiketi iş akışları, CSV içe/dışa aktarma, T-Soft ürün arama.
- `database/install.sql` şeması ve mevcut veri.
- Mevcut tasarım ve HTML `onclick` yapısı.

## v70 ve öncesi

Root API endpoint'i (`api.php`), T-Soft REST1 entegrasyonu, Toplu Fiyat modülü,
kargo etiketi kayıt/düzenleme/silme ve toplu yazdırma, müşteri CSV, adres ayrıştırma
ve baskı iyileştirmeleri. Ayrıntılar için eski README sürüm notlarına bakınız.
