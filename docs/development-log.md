# Geliştirme Günlüğü

## Sprint 1: Proje Temeli (2026-11-11)

- Laravel 12 iskeletinden Tailwind ve Vite kaldırıldı. Arayüz Blade ve tek bir `public/css/app.css` ile yazıldı.
- PostgreSQL 16 ve Docker Compose eklendi (ayrı dev ve prod dosyaları), `finans_test` veritabanı otomatik oluşturuluyor.
- `/health` endpoint'i eklendi: veritabanı kontrolü yapıyor, hata durumunda 503 dönüyor ve hata ayrıntısını yanıta koymuyor.
- Türkçe ana şablon ve menü eklendi. Henüz geliştirilmemiş ekranlar menüde pasif görünüyor.
- `brick/math` eklendi. Testler PostgreSQL üzerinde koşuyor.
- **Düzeltme:** Container içindeki env değişkenleri `phpunit.xml`'deki `<env>` ayarlarını eziyordu ve testler geliştirme veritabanında koşuyordu. Ayarlar `<server force="true">` ile zorlandı. Ayrıca `tests/TestCase.php` içine bir kilit eklendi: veritabanı adı `_test` ile bitmiyorsa testler hiç başlamıyor.
- Doğrulama: 9/9 test geçiyor (container ve host), Pint geçiyor, `/health` 200 dönüyor, arayüz masaüstü ve mobilde kontrol edildi.
