# cPanel'e Kurulum: finans.sigortacimzafir.com

Bu rehber uygulamayı Hızhosting cPanel'e, `finans.sigortacimzafir.com` alt alan adına kurar.
Kurulacak paket: `build/zafir-finans-cpanel.zip` (`sh scripts/build-cpanel.sh` ile üretilir).

Yapı şöyle olacak:

```text
/home/KULLANICI/
├── zafir-finans/          ← uygulama (paketten çıkar). Dışarıdan erişilemez.
│   ├── .env               ← sunucu ayarları (siz oluşturacaksınız)
│   ├── public/            ← alt alan adının BELGE KÖKÜ bu klasör olacak
│   └── ...
└── public_html/           ← ana site, dokunulmaz
```

> Neden `public` klasörü? `.env` dosyası, kod ve loglar internetten erişilemesin diye alt alan adı
> yalnızca `public` klasörünü gösterir.

---

## Adım 0 – Ön kontrol (5 dk)

cPanel'e girin ve şunlara bakın:

| # | Nereye bakılır | Ne olmalı | Yoksa |
|---|---|---|---|
| a | **Veritabanları** bölümünde **PostgreSQL Veritabanları** | Simge var | **Durun, bana yazın.** Uygulama PostgreSQL'e özgü özellikler kullanıyor (fazla tahsilat trigger'ı vb.). Ya Hızhosting'den açtırırız ya da başka bir yol buluruz |
| b | **Yazılım** → **MultiPHP Yöneticisi** veya **PHP Sürümünü Seç** | PHP **8.3** (veya 8.4) seçilebiliyor | Bana yazın |
| c | **Gelişmiş** → **Terminal** (veya SSH erişimi) | Simge var | Bana yazın; kurulum komutlarını çalıştırmanın başka bir yolunu hazırlarım |

---

## Adım 1 – PostgreSQL veritabanı

1. **PostgreSQL Veritabanları** → *Yeni veritabanı*: `finans` → Oluştur.
   cPanel başına kullanıcı adınızı ekler, ör. `kullanici_finans`. **Tam adı not edin.**
2. Aynı sayfada *Yeni kullanıcı*: `finans`, güçlü bir parola (Parola Üretici'yi kullanın) → Oluştur.
   Tam kullanıcı adını (`kullanici_finans`) ve parolayı not edin.
3. *Veritabanına kullanıcı ekle*: kullanıcıyı veritabanına ekleyin → **Tüm yetkiler** → Kaydet.

## Adım 2 – PHP sürümü ve eklentiler

1. **MultiPHP Yöneticisi** → `finans.sigortacimzafir.com` satırını işaretleyin → **PHP 8.3** → Uygula.
2. **PHP Sürümünü Seç** (CloudLinux) ekranı varsa, 8.3'te şu eklentilerin işaretli olduğundan emin olun:
   `pdo_pgsql`, `pgsql`, `bcmath`, `intl`, `mbstring`, `gd`, `zip`, `fileinfo`, `xml`, `dom`, `ctype`, `tokenizer`, `openssl`.
3. Aynı ekrandaki *Seçenekler* sekmesinde: `memory_limit` **256M**, `max_execution_time` **120**.

## Adım 3 – Dosyaları yükleme

1. **Dosya Yöneticisi** → ev klasörüne (`/home/KULLANICI`) gidin. `public_html`'in **içine değil**.
2. **Yükle** → `zafir-finans-cpanel.zip`.
3. Zip'e sağ tıklayın → **Ayıkla** → hedef: `/home/KULLANICI`. Bir `zafir-finans` klasörü oluşur.
4. Zip'i silebilirsiniz.

## Adım 4 – Alt alan adını `public` klasörüne yönlendirme

1. **Alan Adları** (veya **Alt Alan Adları**) → `finans.sigortacimzafir.com` → **Yönet**.
2. **Belge Kökü**: `zafir-finans/public` → Güncelle.
3. Alt alan adını oluştururken cPanel'in açtığı boş klasör (ör. `finans.sigortacimzafir.com/`) artık kullanılmıyor. İçi boşsa silebilirsiniz.

> Belge kökü değiştirilemiyorsa (bazı paketlerde kilitli olabilir) bana yazın. O durumda `public`
> içeriğini mevcut klasöre kopyalayıp `index.php` içindeki iki yolu değiştiririz.

## Adım 5 – `.env` dosyası

1. Dosya Yöneticisi → `zafir-finans/` → sağ üstte **Ayarlar** → *Gizli dosyaları göster* işaretleyin.
2. `.env.cpanel.example` dosyasını **Kopyala** → yeni adı: `.env`.
3. `.env` → **Düzenle** ve şu satırları doldurun (Adım 1'deki tam adlarla):

   ```text
   DB_DATABASE=kullanici_finans
   DB_USERNAME=kullanici_finans
   DB_PASSWORD=adım-1'deki-parola
   ```

   `APP_KEY` satırını boş bırakın, sonraki adımda otomatik dolacak.
   Gerçek kullanıma geçildiğinde `APP_DEMO=false` yapılacak.
4. `.env` → sağ tık → **İzinleri Değiştir** → `600`.

## Adım 6 – Kurulum komutları (Terminal)

**Gelişmiş → Terminal** açın ve sırayla çalıştırın:

```bash
cd ~/zafir-finans
php -v
```

`php -v` **8.3** göstermiyorsa aşağıdaki komutlarda `php` yerine tam yolu kullanın:
`/opt/cpanel/ea-php83/root/usr/bin/php` (veya `ea-php83`).

```bash
php artisan key:generate --force
php artisan migrate --force
php artisan finans:create-admin admin --name="Ad Soyad"
```

Son komut parolayı gizli olarak sorar (en az 10 karakter, harf ve rakam). Ekrana bir şey yazılmaması normaldir.

Demo verisi istiyorsanız (yalnızca boş sistemde çalışır; `--with-users` Finans ve Görüntüleyici demo kullanıcılarını oluşturup parolalarını bir kez gösterir):

```bash
php artisan finans:demo-data --with-users
```

Son olarak önbellekleri oluşturun:

```bash
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## Adım 7 – SSL (HTTPS)

1. **SSL/TLS Durumu** → `finans.sigortacimzafir.com` için **AutoSSL çalıştır** (birkaç dakika sürebilir).
2. **Alan Adları** → `finans.sigortacimzafir.com` → **HTTPS Yönlendirmesini Zorla** açın.

## Adım 8 – Kontrol

| Adres | Beklenen |
|---|---|
| `https://finans.sigortacimzafir.com/health` | `{"status":"ok","database":"ok",...}` |
| `https://finans.sigortacimzafir.com` | Giriş ekranı, üstte turuncu "Demo ortamı" bandı |
| `https://finans.sigortacimzafir.com/.env` | **404** (dosya dışarıdan görünmemeli) |

Admin ile giriş yapın, Dashboard ve raporları kontrol edin.

---

## Güncelleme (yeni sürüm)

1. Yerelde: `sh scripts/build-cpanel.sh`
2. Sunucuda Terminal: `cd ~/zafir-finans && php artisan down`
3. Dosya Yöneticisi: zip'i ev klasörüne yükleyin → ayıklayın (üzerine yazsın). `.env` ve `storage/` içeriği pakette olmadığı için korunur.
4. Terminal:

   ```bash
   cd ~/zafir-finans
   php artisan migrate --force
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   php artisan up
   ```

## Sorun giderme

| Belirti | Bakılacak yer / çözüm |
|---|---|
| 500 hatası | `zafir-finans/storage/logs/laravel-YYYY-MM-DD.log` dosyasının son satırları |
| `could not find driver` | PHP eklentilerinde `pdo_pgsql` işaretli değil (Adım 2) |
| `Composer detected issues ... PHP version` | Alt alan adı PHP 8.3'e ayarlanmamış (Adım 2) veya Terminal'de `php` farklı sürüm (Adım 6'daki tam yol) |
| `SQLSTATE[08006] ... password authentication failed` | `.env` içindeki DB adı, kullanıcı veya parola hatalı; ya da kullanıcı veritabanına eklenmemiş (Adım 1.3) |
| Sayfa stilsiz görünüyor | Belge kökü `zafir-finans/public` değil (Adım 4) |
| `.env` değişikliği etkisiz | `php artisan config:cache` komutunu tekrar çalıştırın |
| Giriş sonrası tekrar giriş ekranına dönüyor | HTTPS açık değilken `SESSION_SECURE_COOKIE=true`. Önce Adım 7'yi tamamlayın |

## Yedekleme

cPanel'in kendi yedekleri dışında veritabanı için günlük `pg_dump` yedeği ve geri yükleme testi
Sprint 10'da eklenecek (cron ile).
