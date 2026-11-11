# Geliştirme Günlüğü

## Sprint 1: Proje Temeli (2026-11-11)

- Laravel 12 iskeletinden Tailwind ve Vite kaldırıldı. Arayüz Blade ve tek bir `public/css/app.css` ile yazıldı.
- PostgreSQL 16 ve Docker Compose eklendi (ayrı dev ve prod dosyaları), `finans_test` veritabanı otomatik oluşturuluyor.
- `/health` endpoint'i eklendi: veritabanı kontrolü yapıyor, hata durumunda 503 dönüyor ve hata ayrıntısını yanıta koymuyor.
- Türkçe ana şablon ve menü eklendi. Henüz geliştirilmemiş ekranlar menüde pasif görünüyor.
- `brick/math` eklendi. Testler PostgreSQL üzerinde koşuyor.
- **Düzeltme:** Container içindeki env değişkenleri `phpunit.xml`'deki `<env>` ayarlarını eziyordu ve testler geliştirme veritabanında koşuyordu. Ayarlar `<server force="true">` ile zorlandı. Ayrıca `tests/TestCase.php` içine bir kilit eklendi: veritabanı adı `_test` ile bitmiyorsa testler hiç başlamıyor.
- Doğrulama: 9/9 test geçiyor (container ve host), Pint geçiyor, `/health` 200 dönüyor, arayüz masaüstü ve mobilde kontrol edildi.

## Sprint 2: Kimlik Doğrulama ve Kullanıcı Yönetimi (2026-11-11)

- `users` tablosuna `username` (küçük harf, büyük/küçük harf duyarsız tekil), `role` (admin/finance/viewer, DB CHECK kısıtı), `is_active` ve `last_login_at` alanları eklendi.
- Giriş kullanıcı adı ve parola ile, Laravel session üzerinden yapılıyor. Girişte session yenileniyor. Aynı kullanıcı adı + IP için dakikada 5 hatalı denemeden sonra giriş kilitleniyor; route'ta ayrıca 20 istek/dakika limiti var. Hata mesajı her durumda aynı, kullanıcının var olup olmadığı veya pasif olduğu anlaşılmıyor.
- Yetkiler `Gate` ile tanımlandı (`view-finance`, `write-finance`, `manage-users`, `view-audit`) ve route'larda `can:` middleware'i ile uygulanıyor. Menü de aynı yetkilere göre filtreleniyor.
- Oturum açıkken pasife alınan kullanıcı bir sonraki istekte sistemden çıkarılıyor.
- Kullanıcı yönetimi: listeleme (arama, rol ve durum filtresi, sıralama, sayfalama), oluşturma, düzenleme, pasife alma. Kullanıcı silme yok. Admin kendi yetkisini düşüremiyor, sistemde en az bir aktif Admin kalıyor.
- `audit_logs` tablosu ve `AuditService` eklendi. Kayıt altına alınanlar: giriş, başarısız giriş (sebebiyle), çıkış, oluşturma, güncelleme, rol değişikliği, aktif/pasif yapma, parola değişikliği. Parola ve token alanları kayda hiç yazılmıyor, IBAN maskeleniyor. Kayıtlar model seviyesinde değiştirilemiyor ve silinemiyor.
- İlk Admin `php artisan finans:create-admin <kullanici>` komutuyla oluşturuluyor (parola gizli olarak soruluyor). Geliştirme ortamı için `db:seed` örnek kullanıcıları `.env` içindeki `DEV_SEED_PASSWORD` ile oluşturuyor.
- Türkçe doğrulama mesajları ve hata sayfaları (403, 404, 419, 429, 500, 503) eklendi.
- Doğrulama: 46/46 test geçiyor, Pint geçiyor, viewer ve admin akışları tarayıcıda denendi.

## Sprint 3: Hesap ve Finansal Hareket Altyapısı (2026-11-12)

- Yeni tablolar: `companies` (tek şirket, çoklu şirkete hazır), `categories` (PRD'deki 50 standart kategori migration ile yükleniyor), `accounts`, `transactions`.
- Kasa, banka ve POS hesapları eklendi. IBAN şifreli saklanıyor, mod-97 ile doğrulanıyor, ekranda ve denetim kaydında maskeleniyor. Bakiyesi sıfır olmayan hesap pasife alınamıyor.
- Tek hareket defteri: açılış, giriş, çıkış, transfer. Bakiye saklanmıyor, hareketlerden hesaplanıyor. Kurallar `TransactionService` ve veritabanı CHECK kısıtları ile uygulanıyor.
- POS: valör tarihi zorunlu; tek tek veya toplu ("vadesi gelenleri aktar") bankaya transfer ediliyor; aynı tahsilat iki kez aktarılamıyor.
- Ters kayıtla iptal, sınırlı düzenleme, eksi bakiye koruması (geriye tarihli kayıtlar dahil), ileri tarih engeli.
- Ekranlar: Hesaplar, Banka ve Kasa (likidite kartları), hesap ekstresi (yürüyen bakiye), Nakit Girişleri ve Çıkışları (filtre, sıralama, sayfalama, filtreye göre toplam), hareket detayı (iptal ve değişiklik geçmişi), POS / Blokeli Hesaplar.
- Para: `App\Support\Money` ile Türkçe tutar girişi ve gösterimi; tüm hesaplar BigDecimal veya PostgreSQL NUMERIC ile.
- **Düzeltme:** Bekleyen POS sorgusunda, kendine referanslı ilişkide iç içe `whereDoesntHave` yanlış tablo takma adına bağlanıyordu; aktarım transferi iptal edilince tahsilat bekleyene dönmüyordu. Test yakaladı, sorgu açık SQL ile yeniden yazıldı.
- Doğrulama: 110/110 test, Pint temiz. Tarayıcıda hesap açma (açılış bakiyesiyle), ekstre, bakiyeyi aşan çıkışın reddedilmesi, Banka ve Kasa ile POS ekranları denendi.
