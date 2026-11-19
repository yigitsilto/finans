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

## Sprint 4: Giderler, Ortaklar ve Krediler (2026-11-13)

- Yeni tablolar: `partners`, `partner_adjustments`, `loans`, `loan_installments` (`total` veritabanında hesaplanan sütun), `recurring_expenses`. `transactions` tablosuna `partner_id`, `loan_id`, `loan_installment_id`, `recurring_expense_id` ve `expense_period` eklendi. Kategorilere `link_type` (partner / loan) alanı ve "Ortaktan alınan borç" ile "Ortağa borç geri ödemesi" kategorileri eklendi.
- `LoanScheduleCalculator` (annüite, BigDecimal) ve `LoanService` eklendi: kullandırım, sıralı taksit ödemesi (anapara ve faiz ayrı), ödeme iptali, plan düzeltme, kredi özeti.
- `PartnerService` eklendi: sermaye cariden ayrı, cari bakiye, yürüyen cari ekstresi, nakitsiz düzeltmeler ve iptalleri.
- `ExpenseService` eklendi: aylık tekrarlayan gider durumu (ödendi / bekliyor / gecikti), ayda bir ödeme, gider analizi (sabit ve değişken oranları).
- Ekranlar: Krediler (liste, oluşturma, taksit planı ve ödeme), Ortaklar Cari (liste, ekstre, düzeltme), Giderler (aylık durum, analiz, tekrarlayan gider formu). Genel hareket formuna ortak seçimi eklendi.
- Tablo içindeki küçük işlem formları JS'siz modal pencerelere dönüştürüldü; önceki hali tablonun kaydırma alanında kırpılıyordu (tarayıcı testinde bulundu).
- Düzeltme: yeni oluşturulan modellerde `is_active` kaydedilmeden önce boş kalıyordu; modellere varsayılan değer eklendi (test yakaladı).
- Doğrulama: 134/134 test, Pint temiz. Annüite sonucu Python Decimal ile bağımsız olarak doğrulandı. Tarayıcıda kredi oluşturma, taksit ödeme ve gider ekranı denendi.

## Sprint 5: Müşteri Alacakları ve Tahsilat (2026-11-14)

- Yeni tablolar: `customers` (TCKN/VKN şifreli) ve `receivables` (tahsil edilen/kalan tutar saklanmıyor). `transactions` tablosuna `receivable_id` eklendi. PostgreSQL trigger'ları: `check_receivable_collection` (fazla tahsilat, yalnızca nakit girişi, iptal edilmiş alacak) ve `check_receivable_update` (tutarın tahsilatın altına düşmemesi, tahsilatlı alacağın iptal edilememesi).
- `ReceivableService` eklendi: alacak oluşturma ve düzenleme, kısmi/tam tahsilat (kasa, banka, POS), iptal.
- Ekranlar: Müşteri Alacakları (durum ve vade filtreleri, sıralama, sayfalama, filtreye göre toplamlar), alacak detayı (tahsilat formu, tahsilat geçmişi, iptal), Müşteriler (açık alacak toplamı, müşteri bazlı alacak listesi). Müşteri ekledikten sonra alacak formuna dönme akışı eklendi.
- Doğrulama: 151/151 test, Pint temiz. Trigger'ın eşzamanlı iki tahsilatı engellediği test veritabanında iki gerçek oturumla denendi. Tarayıcıda müşteri, alacak, fazla tahsilat hatası ve kısmi tahsilat akışı denendi.

## Sprint 6: Alacak Yaşlandırma (2026-11-15)

- `AgingService` eklendi: tek SQL sorgusuyla rapor tarihindeki kalan tutar (`LATERAL` alt sorgu), gecikme günü ve dilim. Dilim toplamları, yüzdeler, müşteri bazlı dağılım ve vadesi geçmiş toplamı hesaplanıyor. Tüm toplamlar BigDecimal ile; sıralamada bile float yok.
- Yaşlandırma ekranı: rapor tarihi seçimi (geçmiş tarih uyarısıyla), müşteri filtresi, müşteri bazlı veya alacak detaylı görünüm. 5 dilim kartı risk renkleriyle gösteriliyor ve tıklanınca o dilime filtreliyor. Kritik risk uyarısı eklendi.
- PRD'deki 0. gün çelişkisi belgelendi. Ürün sahibi "vadesi bugün olan alacak Vadesi Gelmemiş dilimindedir" kararını onayladı (bkz. financial-rules.md).
- Doğrulama: 178/178 test, Pint temiz. Tarayıcıda bugünkü ve geçmiş tarihli rapor kontrol edildi.

## Sprint 7: Nakit Akış Motoru (2026-11-16)

- `CashflowService` eklendi: A–E yapısında dönem raporu, faaliyet ve kategori kırılımı, kredi anapara/faiz ayrımı, POS bloke köprüsü, hesap bazlı mutabakat, 6 mutabakat kontrolü. Günlük, haftalık ve aylık kırılımda her dönemin açılışı defterden bağımsız hesaplanıp önceki kapanışla karşılaştırılıyor.
- Nakit Akış Raporu ekranı: günlük, haftalık, aylık ve özel dönem; önceki/sonraki dönem gezinmesi; kırılım tablosu; mutabakat tabloları; negatif net akış ve mutabakat hatası uyarıları.
- Doğrulama: 188/188 test, Pint temiz. Ana test senaryosunun beklenen değerleri Python Decimal ile bağımsız olarak hesaplandı. Mutabakat hatasının yakalandığı, defter bakiyesini 1 kuruş bozan sahte servisle test edildi. Tarayıcıda geliştirme verisiyle (3 ay, aylık kırılım) tüm kontroller eşleşti.
- Düzeltmeler: `match(...)->metod()` söz dizimi hatası (test yakaladı); kırılım tablosuna dönem içi açılış kaydı sütunu eklendi (tarayıcı kontrolünde satırın elle toplanamadığı görüldü).

## Sprint 8: Dashboard ve Finansal Analiz (2026-11-17)

- `ObligationService` (yaklaşan zorunlu ödemeler), `FinancialAnalysisService` (11 uyarı kuralı, 3 seviye, likidite karşılama oranı) ve `DashboardService` (10 kart, 4 grafik) eklendi.
- Dashboard: kartlar ilgili ekranlara bağlı, uyarı paneli, Chart.js 4.4.1 (cdnjs, sürüm sabit) ile 4 grafik. Grafikler `GET /api/dashboard` adresinden besleniyor. Mutabakat hatası dashboard'da kırmızı bant olarak gösteriliyor.
- Finansal Analiz ekranı: tüm uyarılar, seçilebilir ufukla likidite tablosu, bu ayın oranları.
- Eşikler `config/finance.php` dosyasında, `.env` ile değiştirilebilir.
- Doğrulama: 202/202 test, Pint temiz. Saat sabit bir tarihe ayarlanarak test edildi. Tarayıcıda dashboard (konsol hatası yok, 4 grafik çiziliyor) ve analiz ekranının mobil görünümü kontrol edildi.
- Düzeltme: mobilde filtre etiketlerindeki select kutularının dikey uzaması giderildi.

## Sprint 9: Denetim ve Raporlama (2026-11-18)

- Denetim kayıtları ekranı (yalnızca Admin): filtreler, sayfalama, kayıt detayı.
- `ReportTable`, `ReportBuilder` (5 rapor), `ExcelRenderer` (PhpSpreadsheet) ve `PdfRenderer` (dompdf + DejaVu Sans) eklendi. "Excel / PDF" ekranı ve rapor ekranlarına indirme butonları eklendi. Her indirme denetim kaydına yazılıyor.
- Hareket listesi filtresi `TransactionController::filteredQuery` olarak ayrıldı; ekran ve dışa aktarma aynı filtreyi kullanıyor.
- `docker/php.ini` eklendi (memory 256M, expose_php Off, display_errors Off). PHP gereksinimi `composer.json` dosyasında açıkça ^8.3 yapıldı; yeni paketler PHP 8.3 istiyor ve uygulamanın çalışma ortamı zaten PHP 8.3'lü Docker.
- Testlerin bulduğu sorunlar:
  - **Bellek:** Excel'de hücre başına stil uygulandığı için 5.000 satır 128 MB'ı aşıyordu. Biçimler sütun aralığına tek seferde uygulanacak şekilde düzeltildi; 5.000 satır artık 77 MB.
  - **Bellek:** dompdf 2.000 satırlık PDF'te belleği aşıyordu. Ölçüldü (≈0,4 MB/satır); PDF sınırı 500 satıra indirildi ve PDF üretimi sırasında bellek geçici olarak yükseltiliyor.
  - **Kurulum:** `masterminds/html5` paketi mount edilmiş klasöre eksik kurulmuştu; yeniden kuruldu.
  - Test hataları: PhpSpreadsheet'in hücre nesnesini yeniden kullanması ve jsonb'nin anahtar sırasını korumaması.
- PDF iyileştirmeleri: yalnızca kullanılan karakterlerin gömülmesi (1,2 MB → 37 KB) ve başlıkların tablolarından ayrılmaması.
- Doğrulama: 217/217 test, Pint temiz. Örnek nakit akış PDF'i görsel olarak kontrol edildi (Türkçe karakterler ve ₺ doğru); denetim ve dışa aktarma ekranları tarayıcıda denendi.

## Ara iş: cPanel'e yayın hazırlığı (2026-11-19)

- Production ayarları: `FORCE_HTTPS`, `TRUSTED_PROXIES` ve `APP_DEMO` (demo bandı, `noindex`). Güvenlik başlıkları middleware'i eklendi: CSP, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy ve HTTPS'te HSTS.
- `php artisan finans:demo-data [--with-users]` eklendi: boş sisteme son 3 ayın örnek verisini gerçek servisler üzerinden yükler; yaşlandırmanın her dilimi dolu, tüm mutabakat kontrolleri eşleşiyor.
- `scripts/build-cpanel.sh` ve `.env.cpanel.example` eklendi; paket `build/zafir-finans-cpanel.zip` olarak üretiliyor. Paket boş bir klasöre açılıp production `.env` ile test edildi: migrate, admin oluşturma, demo verisi, giriş, tüm ekranlar, PDF/Excel indirme, güvenlik başlıkları ve Türkçe 404 sayfası.
- Rehber: [deploy-cpanel.md](deploy-cpanel.md).
- Doğrulama: 221/221 test.

## Ara iş: MariaDB 10.11 desteği (2026-11-19)

- Hızhosting sunucusunda PostgreSQL yok, MariaDB 10.11 var. Uygulama iki veritabanında da çalışacak hale getirildi; PostgreSQL desteği korundu.
- `App\Support\Sql` yardımcısı eklendi. `FILTER (WHERE)` ifadeleri `SUM(CASE WHEN)` ile, `::numeric::text` dönüşümleri `CAST(... AS DECIMAL(18,2))` ile, yaşlandırmadaki `LATERAL` gruplanmış alt sorguyla değiştirildi; tarih farkı ve ay biçimi sürücüye göre üretiliyor.
- Migration'lar: kullanıcı adı indeksi ve `setval` sürücüye göre. Fazla tahsilat trigger'ları MariaDB'de `SIGNAL SQLSTATE '45000'` ile yazıldı (INSERT, UPDATE ve alacak güncellemesi için 3 trigger; mesajlar aynı).
- Docker'a `mariadb:10.11` servisi (profile: mariadb), Dockerfile'a `pdo_mysql` ve `phpunit.mariadb.xml` eklendi.
- Doğrulama:
  - **221/221 test PostgreSQL'de, 221/221 test MariaDB 10.11'de** geçiyor.
  - Demo verisi iki veritabanında aynı rakamları veriyor (kullanılabilir nakit 444.182,06 ₺).
  - phpMyAdmin için üretilen SQL dosyaları yalnızca kendi veritabanında yetkili bir kullanıcıyla içe aktarıldı; giriş, mutabakat ve eşzamanlı fazla tahsilat engeli denendi.
- Bulunan ve düzeltilen hatalar: trigger'da `TIMESTAMP NULL` değişken tanımı (MariaDB `DEFAULT NULL` istiyor), phpunit XML yorumunda `--`, testte sabit `pgsql` bağlantı adı.
