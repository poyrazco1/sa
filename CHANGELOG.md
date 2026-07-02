# Değişiklik Günlüğü (CHANGELOG)

Bu proje "Akıllı Fiyat Sihirbazı" PHP panelidir. Sürüm adları paket klasör adıyla eşleşir.

## v79 — Arayüz yeniden tasarımı (SaaS operasyon paneli)

### Tasarım
- `assets/css/app.css` ekran arayüzü baştan, bölümlere ayrılmış bir **tasarım sistemi**
  olarak yeniden yazıldı (tokens, base, layout, topbar, gate, nav, forms, buttons,
  cards, bulk, label, records, modals, tables, utilities, responsive).
- Yeni palet: warm cream yerine **cool light-gray** zemin, tek **mavi** vurgu (#2563EB)
  + **emerald** başarı (#059669); abartılı gradient ve neon kaldırıldı.
- **Topbar** dashboard başlığı + alt açıklama satırı kazandı; kur çipleri ve mini kur
  çevirici kompaktlaştı.
- **Modül geçişi** segmented tab bar oldu; aktif sekme net.
- **Fiyat Sihirbazı sonuç** ekranı: kritik metrikler büyük KPI kartları (KDV hariç satış
  ve müşteri toplamı koyu lacivert, net kâr emerald, kargo kehribar); ödeme notu satır
  ayıraçları görünür hale getirildi.
- **Toplu Fiyat** sonuç tablosu zebra + sticky başlık, sağa hizalı tabular sayılar.
- **Kargo Etiketi** form/adım/kayıt ekranları ve T-Soft modalı tek tasarım diline çekildi.
- Erişilebilirlik: görünür focus-visible halkaları, min 44px dokunmatik hedefler,
  12px altı font yok, tutarlı input/select/textarea yükseklikleri.
- **Responsive** 1440 / 1180 / 820 / 520 / 375 kırılımlarında test edildi.

### Korunanlar (bilinçli olarak değiştirilmedi)
- Kargo etiketi **fiziksel çıktı ölçüleri ve tüm `@media print` blokları byte düzeyinde
  AYNEN** korundu (A4/A5/10×10/15×10, QR, yüksek kontrast siyah logo/kargo fallback).
- `assets/js/app.js` ve tüm PHP/API/DB mantığı **değişmedi** (yalnızca CSS + 2 HTML include).
- Tüm `id`, `onclick`, `data-*` ve JS bağlantıları korundu.

### Değişen dosyalar
- `assets/css/app.css` (ekran arayüzü yeniden yazıldı; print/label bölümü korundu)
- `includes/topbar.php` (başlık altına açıklama satırı)
- `includes/header.php` (CSS sürümü `?v=79`)

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
