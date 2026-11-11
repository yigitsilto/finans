# Zafir Sigorta Finans Yönetim Sistemi

Kasa, banka, POS, alacak, kredi ve nakit akışı yönetimi. Laravel 12, Blade, düz CSS ve PostgreSQL 16 kullanır.

## Gereksinimler

- Docker ve Docker Compose v2.24 veya üstü (Docker Desktop ya da OrbStack)
- İsteğe bağlı, Docker dışında çalıştırmak için: PHP 8.2+ (`pdo_pgsql`, `bcmath`, `intl`, `zip`, `gd`) ve Composer

## Kurulum (geliştirme)

```bash
cp .env.example .env          # DB_PASSWORD değerini değiştir
docker compose up -d --build
docker compose exec app php artisan key:generate
```

- Uygulama: http://localhost:8080
- Sağlık kontrolü: http://localhost:8080/health
- PostgreSQL: yalnızca `127.0.0.1:5432` üzerinden erişilebilir

Container açılırken migration'lar otomatik çalışır (`RUN_MIGRATIONS=false` ile kapatılabilir).

## Testler

Testler SQLite'ta değil, gerçek PostgreSQL'de, `finans_test` veritabanında koşar. Bu veritabanı container ilk açıldığında otomatik oluşturulur.

```bash
docker compose exec app php artisan test
docker compose exec app ./vendor/bin/pint --test
```

## Production

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

Production'da kod imajın içinde gelir. Ayrıca `APP_DEBUG=false` olur, veritabanı portu dışarı açılmaz, config ve route önbelleğe alınır. HTTPS bir reverse proxy üzerinden sağlanmalı ve `SESSION_SECURE_COOKIE=true` yapılmalıdır.

## Dokümanlar

- [docs/architecture.md](docs/architecture.md): mimari ve klasör yapısı
- [docs/financial-rules.md](docs/financial-rules.md): finansal iş kuralları
- [docs/development-log.md](docs/development-log.md): sprint raporları
