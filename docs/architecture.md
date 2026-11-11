# Mimari

## Teknoloji

| Katman | Seçim | Neden |
|---|---|---|
| Backend | Laravel 12 (PHP 8.3, container içinde) | Auth, validation, ORM, migration ve test hazır geliyor |
| Arayüz | Blade, `public/css/app.css` | Build adımı yok, JS framework yok |
| Grafik | Chart.js (CDN, Sprint 8) | Sadece dashboard için |
| Veritabanı | PostgreSQL 16 | Para alanları için `NUMERIC(18,2)` |
| Para hesabı | `brick/math` BigDecimal | PHP'de float kullanılmaz |
| Kimlik doğrulama | Laravel session (HttpOnly cookie ve CSRF) | Blade uygulamasında JWT'ye gerek yok |
| Excel / PDF | phpspreadsheet / dompdf (Sprint 9) | Türkçe karakter için DejaVu Sans |

## Klasör yapısı

```text
app/
  Http/Controllers/   Blade ekran controller'ları ve HealthController
  Models/             Eloquent modelleri
  Services/           Finansal hesaplamalar (Cashflow, Aging, Balance, Audit)
  Policies/           Rol bazlı yetki (Sprint 2)
config/menu.php       Ana menü tanımı
resources/views/      Blade şablonları (layouts/app.blade.php)
public/css/app.css    Tek stil dosyası
docker/               entrypoint ve PostgreSQL init script'leri
tests/                PHPUnit Feature ve Unit testleri (PostgreSQL üzerinde)
docs/                 Dokümanlar
```

## Ortamlar

- **Geliştirme:** `docker-compose.yml`. Kod mount edilir, `APP_DEBUG=true`, DB portu `127.0.0.1` üzerinden açılır.
- **Production:** `docker-compose.yml` + `docker-compose.prod.yml`. Kod imajın içindedir, debug kapalıdır, DB portu kapalıdır, config önbelleğe alınır.
- Gizli bilgiler (APP_KEY, DB_PASSWORD) yalnızca `.env` dosyasında durur. `.env` git'e ve Docker imajına girmez.

## Yetki modeli (Sprint 2)

Admin, Finance ve Viewer rolleri vardır. Menünün gizlenmesi yalnızca kullanıcı deneyimi içindir. Asıl kontrol her route'ta middleware ve Policy ile backend'de yapılır.
