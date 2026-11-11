# Finansal İş Kuralları

Bu doküman sprintler ilerledikçe güncellenir. Kurallar kodda servis katmanında uygulanır ve testlerle doğrulanır.

## Para

- Veritabanında para alanları `NUMERIC(18,2)` tipindedir. PHP'de `brick/math` BigDecimal kullanılır, **float kullanılmaz**.
- Eloquent'te para alanları string olarak okunur, hesaplama BigDecimal ile yapılır.
- Varsayılan para birimi `TRY`'dir. Her harekette `currency` alanı bulunur. Farklı para birimli hesaplar arasında transfer yapılamaz.
- Tutar girişi Türkçe biçimi kabul eder: `27.000,50`, `27000,50`, `27000.50`. Yalnızca nokta içeren `1.700` binlik ayırıcı kabul edilir (1700). En fazla 2 ondalık basamak girilebilir; daha fazlası yuvarlanmaz, reddedilir.

## Hareket türleri

| Tür | Hesap | Kategori | Nakit akışına etkisi |
|---|---|---|---|
| Açılış (`opening`) | Hedef | Yok | Yok; dönem başı bakiyeyi oluşturur. Her hesapta yalnızca bir geçerli açılış olabilir |
| Giriş (`inflow`) | Hedef | Zorunlu, giriş kategorisi | Nakit girişi |
| Çıkış (`outflow`) | Kaynak | Zorunlu, çıkış kategorisi | Nakit çıkışı |
| Transfer (`transfer`) | Kaynak ve hedef | Yok | Yok; para yalnızca hesaplar arasında yer değiştirir |

Bu kurallar hem `TransactionService` içinde hem de veritabanında CHECK kısıtlarıyla uygulanır. Servis atlanıp doğrudan SQL ile yazılsa bile geçersiz satır kaydedilemez.

## Bakiye

- Hesap bakiyesi tabloda tutulmaz, hareketlerden hesaplanır: açılış + girişler − çıkışlar ± transferler. Bu sayede bakiye ile hareketler birbiriyle çelişemez.
- Kasa ve POS hesapları eksiye düşemez. Bankada eksi bakiye (KMH) yalnızca hesapta "eksi bakiyeye izin ver" açıksa mümkündür.
- Geriye tarihli bir çıkış, sonraki günlerden herhangi birinde bakiyeyi eksiye düşürecekse reddedilir; kontrol günlük yürüyen bakiye üzerinden yapılır.
- Gerçekleşmiş hareketler ileri tarihli girilemez.
- Blokeli POS bakiyesi kullanılabilir nakde dahil edilmez, ayrı gösterilir. Kullanılabilir nakit = kasa + banka; toplam likidite = kullanılabilir nakit + blokeli POS.
- Eşzamanlı yazmalarda hesap satırları kilitlenir (`SELECT … FOR UPDATE`), böylece iki işlem aynı bakiyeyi birlikte eksiye düşüremez.

## POS

- POS'a yalnızca tahsilat (giriş) yazılır ve valör tarihi zorunludur. POS'tan doğrudan ödeme yapılamaz, POS'a transfer yapılamaz.
- Valör tarihi gelen tahsilat, POS hesabına bağlı banka hesabına **transfer** ile aktarılır (`settles_transaction_id`). Tutar POS'tan düşer, bankaya eklenir. Nakit girişi bir kez sayılır, toplam likidite değişmez.
- Aynı tahsilat iki kez aktarılamaz. Aktarılmış bir tahsilatı iptal etmek için önce aktarım transferi iptal edilmelidir; transfer iptal edilince tahsilat yeniden "bekleyen" olur.

## Silme ve düzeltme

- Finansal kayıtlar fiziksel olarak silinmez; model seviyesinde silme engellenmiştir.
- İptal, aynı tür, hesap ve kategoride **negatif tutarlı** bir ters kayıtla yapılır (`reversal_of_id`). Tüm toplamlar düz `SUM(amount)` ile doğru kalır. İptal tarihi orijinal tarihten önce olamaz, böylece geçmiş dönem bakiyeleri değişmez.
- Bir kayıt yalnızca bir kez iptal edilebilir. Ters kayıt tekrar iptal edilemez.
- Sonradan yalnızca kategori (aynı yönde), ilgili kişi/kurum, referans ve açıklama düzeltilebilir. Tutar, tarih veya hesap hatalıysa kayıt iptal edilip yeniden girilir. Her düzeltme eski ve yeni değerleriyle denetim kaydına yazılır.

## Sınıflandırma

| İşlem | Nakit yönü | Faaliyet |
|---|---|---|
| Komisyon geliri, müşteri tahsilatı | Giriş | İşletme |
| Sigorta şirketine prim, giderler | Çıkış | İşletme |
| Kredi kullanımı | Giriş | Finansman |
| Kredi anapara ve faiz ödemesi | Çıkış (ayrı satırlarda) | Finansman |
| Ortak sermaye girişi | Giriş | Finansman |
| Ortaklara ödeme | Çıkış | Finansman (operasyonel gider değildir) |

## Yaşlandırma (Sprint 6)

`gecikme = rapor tarihi − vade tarihi`. Gecikme negatifse vadesi gelmemiştir. Diğer kategoriler: 0–30, 31–60, 61–90, 90+. Yaşlandırma yalnızca kalan tutar üzerinden hesaplanır.
