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

## Krediler

- Kredi oluşturulurken eşit taksitli (annüite) ödeme planı üretilir: aylık oran `r = yıllık % / 1200`, taksit `A = P·r·(1+r)^n / ((1+r)^n − 1)`. Faiz her ay kalan anapara × r olarak hesaplanıp kuruşa yuvarlanır (HALF_UP). Yuvarlama farkı son taksitte kapatılır, böylece anapara toplamı kredi tutarına her zaman eşittir. Faizsiz kredide tutar eşit bölünür, küsurat son taksite eklenir.
- Ödenmemiş taksitler bankanın gerçek planına göre düzeltilebilir (vade, anapara, faiz; BSMV/KKDF faiz satırına dahil edilir). Plan anaparası kredi tutarıyla eşleşmezse kredi ekranında uyarı gösterilir.
- Kredi kullanımı **nakit girişi**dir ("Alınan krediler", finansman).
- Her taksit ödemesi **iki ayrı nakit çıkışı** olarak kaydedilir: "Kredi anapara ödemesi" ve "Kredi faiz ödemesi" (ikisi de finansman). Faizi sıfır olan taksitte yalnızca anapara satırı yazılır. İki satır tek veritabanı işleminde yazılır; bakiye yetmezse hiçbiri kaydedilmez.
- Taksitler sırayla ödenir ve her taksit bir kez ödenir. Ödeme iptali yalnızca son ödenen taksit için yapılır ve anapara ile faiz birlikte ters kayıtla iptal edilir.
- Kredi kategorileri genel hareket ekranında seçilemez. Krediye bağlı hareketler genel ekrandan iptal edilemez, yalnızca kredi ekranından.
- Kalan anapara = kredi tutarı − iptal edilmemiş anapara ödemelerinin toplamı.

## Ortaklar cari hesabı

- Sermaye girişi özkaynaktır ve cari bakiyeden **ayrı** izlenir.
- Cari bakiye = ortaktan alınan borç − ortağa borç geri ödemesi − ortaklara ödemeler + nakitsiz alacak düzeltmeleri − nakitsiz borç düzeltmeleri. Pozitifse şirket ortağa, negatifse ortak şirkete borçludur (muhasebedeki 331 ve 131 hesaplarına karşılık gelir).
- Ortak kategorilerinde (sermaye, ortaktan borç, ortağa ödeme, ortağa borç geri ödemesi) aktif bir ortak seçmek zorunludur; diğer kategorilerde ortak seçilemez. Bu hareketlerin kategorisi sonradan değiştirilemez.
- Ortak hareketleri **finansman faaliyetidir**; sabit veya değişken gider oranlarına girmez.
- Nakitsiz düzeltmeler (ör. ortağın şirket giderini kendi cebinden ödemesi) kasa ve banka bakiyelerini ve nakit akışını etkilemez. Silinmez, ters kayıtla iptal edilir.

## Giderler

- Tekrarlayan giderler (kira, aidat, maaş vb.) yalnızca sabit veya değişken gider alt kategorilerinde tanımlanabilir. Tutarlar örnek veridir, kodda sabit değildir.
- Vade günü ayın gün sayısından büyükse ayın son günü kullanılır (ör. 31 → 28 Şubat).
- Her tekrarlayan gider her ay için bir kez ödenebilir. Ödeme iptal edilirse o ay yeniden ödenebilir. Tanım değiştirildiğinde geçmiş ödemeler değişmez.
- Sabit gider oranı = sabit gider çıkışları / dönemdeki toplam nakit çıkışı. Değişken gider oranı aynı şekilde hesaplanır. Dönemde hiç çıkış yoksa oran "—" gösterilir, sıfıra bölme olmaz. İptal kayıtları negatif tutarlı olduğu için toplamlardan otomatik düşer.

## Müşteri alacakları

- Alacakta müşteri, poliçe veya referans no, sigorta şirketi, tutar, alacak tarihi, vade tarihi ve açıklama tutulur. Alacak tarihi ileri bir tarih olamaz; vade, alacak tarihinden önce olamaz.
- **Tahsil edilen ve kalan tutar saklanmaz.** Tahsil edilen = alacağa bağlı nakit girişlerinin toplamı (ters kayıtlar negatif); kalan = alacak − tahsil edilen.
- Tahsilat, "Müşteri tahsilatları" kategorisinde bir **nakit girişidir**. Kasa, banka veya POS hesabına yapılabilir; POS'a yapılırsa valör tarihi zorunludur ve tutar aktarılana kadar blokeli kalır. Alacak açısından tahsil edilmiş sayılır.
- Kısmi tahsilat serbesttir. Tahsilat tutarı kalan alacağı aşamaz; kalanın tam tutarı kabul edilir, bir kuruş fazlası reddedilir.
- Fazla tahsilat **iki katmanda** engellenir:
  1. `ReceivableService`, alacak satırını kilitleyerek (`FOR UPDATE`) kalan tutarı kontrol eder.
  2. PostgreSQL trigger'ı (`check_receivable_collection`), servis atlansa bile her insert ve update'te toplamın 0 ile alacak tutarı arasında kaldığını doğrular. Alacağa yalnızca nakit girişi bağlanabilir. Trigger da alacak satırını kilitlediği için eşzamanlı iki tahsilatta ikincisi reddedilir; bu iki gerçek eşzamanlı oturumla denendi.
- Alacak tutarı, tahsil edilen tutarın altına düşürülemez (uygulamada ve `check_receivable_update` trigger'ında).
- Tahsilat iptali, hareket ekranından ters kayıtla yapılır; kalan tutar otomatik geri gelir. Tahsilat hareketinin kategorisi değiştirilemez.
- Alacaklar silinmez. Net tahsilatı sıfır olan alacak iptal edilebilir (neden zorunlu). İptal edilen alacağa tahsilat girilemez; iptal edilen alacak toplamlara ve gecikme hesabına girmez.
- Durumlar: Açık (tahsilat yok), Kısmi tahsil, Tahsil edildi, İptal. Vadesi geçmiş = iptal değil, tamamı tahsil edilmemiş ve vadesi bugünden önce.
- Müşterinin TCKN/VKN bilgisi opsiyoneldir, şifreli saklanır ve ekranda maskelenir.

## Alacak yaşlandırma

- Gecikme günü = rapor tarihi − vade tarihi.
- Yalnızca **rapor tarihindeki kalan tutar** yaşlandırılır: kalan = alacak − tarihi rapor tarihinden önce veya o gün olan tahsilatlar (iptalleri dahil).
  - Rapor tarihinden sonra oluşturulan alacaklar ve sonraki tahsilatlar rapora girmez.
  - Rapor tarihinden sonra iptal edilen bir tahsilat, o tarih itibarıyla tahsil edilmiş sayılır.
  - İptal edilen alacak, iptal tarihinden önceki raporlarda görünür, sonrakilerde görünmez.
- Dilimler (tek kaynak `AgingService::bucket()`):

| Gecikme günü | Dilim | Risk |
|---|---|---|
| ≤ 0 (vade bugün veya ileride) | Vadesi Gelmemiş | Normal |
| 1–30 | 0–30 Gün | Hafif gecikme |
| 31–60 | 31–60 Gün | Orta risk |
| 61–90 | 61–90 Gün | Yüksek risk |
| ≥ 91 | 90+ Gün | Kritik risk |

- **Sınır kararı (ürün sahibi tarafından 2026-11-15'te onaylandı):** PRD §15'teki tablo "vade tarihi bugün → vadesi gelmemiş" diyor, kurallar listesi ise "0 gün → 0–30 dilimi" diyor. Tablo esas alındı: vadesi bugün olan alacak henüz gecikmiş sayılmaz. Bu, alacak listesindeki "vadesi geçmiş" tanımıyla da (vade < bugün) tutarlıdır. Değiştirmek için yalnızca `bucket()` içindeki `<= 0` koşulunu `< 0` yapmak ve sınır testlerini güncellemek yeterlidir.
- Dilim toplamları = müşteri toplamları = genel toplam. Bu mutabakat testle doğrulanır.
- 90+ dilimde alacak varsa "Kritik tahsilat riski bulunmaktadır" uyarısı gösterilir.

## Nakit akış raporu (PRD §16–17)

| Satır | Tanım |
|---|---|
| A. Başlangıç | Dönem başından bir gün önceki gün sonu hesap bakiyeleri. Kullanılabilir = kasa + banka. Blokeli POS ayrı gösterilir. |
| B. Girişler | Dönemdeki `inflow` hareketleri, faaliyet → grup → kategori kırılımıyla |
| C. Çıkışlar | Dönemdeki `outflow` hareketleri. Kredi taksitleri anapara ve faiz satırlarıyla ayrıca gösterilir |
| D. Net akış | B − C (işletme + finansman olarak da ayrılır) |
| E. Dönem sonu kullanılabilir nakit | A + D − POS blokesindeki artış + dönem içi açılış kayıtları (kasa/banka) |

- **Transferler** (POS → banka aktarımı dahil) nakit akışı değildir.
- **POS tahsilatı** nakit girişidir ama valör gününe kadar blokelidir. Kullanılabilir nakde geçişi "POS blokesindeki değişim" satırıyla açıkça gösterilir: POS'a girenler − bankaya aktarılanlar. Toplam likidite = kullanılabilir + blokeli POS.
- **Açılış/devir kayıtları** nakit akışı değil, bakiye tanımıdır. Dönem içinde girilmişlerse köprüde ayrı satır olarak gösterilir.
- **İptaller** ters kaydın tarihinde yansır, geçmiş dönem raporu değişmez.
- Dönem türleri: günlük, haftalık (pazartesi–pazar), aylık, özel aralık. Kırılım günlük, haftalık veya aylık olabilir; ilk ve son dönem aralığa kırpılır. En fazla 120 dönem gösterilir.

### Mutabakat kontrolleri (her raporda)

1. Formülle bulunan dönem sonu kullanılabilir nakit = kasa + banka hesaplarının defter bakiyesi.
2. Formülle bulunan blokeli POS = POS hesaplarının defter bakiyesi.
3. Toplam kasa + toplam banka = toplam kullanılabilir nakit.
4. Kategori bazlı net akış = hesap bazlı giriş − çıkış toplamı.
5. Her hesap için: açılış + açılış kayıtları + girişler − çıkışlar + gelen transfer − giden transfer = kapanış.
6. **Devir:** kırılımda her dönemin açılışı defterden bağımsız hesaplanır ve önceki dönemin formülle bulunan kapanışına eşit olmalıdır.

Herhangi bir kontrol tutmazsa raporda kırmızı "Mutabakat hatası" uyarısı çıkar. Bu uyarının dashboard'da gösterilmesi Sprint 8'e kaldı.

## Dashboard ve finansal analiz (PRD §18–19)

- Dashboard'daki 10 kartın ve 4 grafiğin hepsi gerçek kayıtlardan hesaplanır. Veri yokken yalnızca gerçek "0,00 ₺" değerleri gösterilir, örnek veya sahte rakam yoktur.
- Grafik verisi `GET /api/dashboard` adresinden gelir (oturum gerekir). Tutarlar JSON'da **string** olarak döner (`"1234.56"`). Tarayıcıda yalnızca çizim için sayıya çevrilir; hiçbir finansal hesap tarayıcıda yapılmaz.
- **Zorunlu ödemeler** (likidite için): ödenmemiş kredi taksitleri (gecikmişlerin tamamı dahil) ve ödenmemiş tekrarlayan giderler. Giderlerde en fazla 3 ay geriye bakılır. Planlanan tutarlar kullanılır; beklenen tahsilatlar hesaba katılmaz (ihtiyatlılık).
- Uyarı kuralları:

| Uyarı | Koşul | Seviye |
|---|---|---|
| Mutabakat hatası | Bu ayın nakit akış kontrollerinden biri tutmuyor | Kritik |
| Likidite riski tespit edildi | Ufuktaki zorunlu ödemeler > kullanılabilir nakit (blokeli POS hariç) | Kritik |
| Likidite sıkışıyor | Zorunlu ödemeler ≥ kullanılabilir nakdin %80'i | Dikkat |
| Gecikmiş kredi taksiti | Vadesi geçmiş ödenmemiş taksit var | Kritik |
| Gecikmiş gider ödemesi | Vadesi geçmiş ödenmemiş tekrarlayan gider var | Dikkat |
| Kritik tahsilat riski bulunmaktadır | 90+ gün diliminde alacak var | Kritik |
| Vadesi geçmiş alacak | Vadesi geçmiş kalan alacak var | Dikkat |
| Negatif nakit akışı | Bu ay çıkış > giriş | Dikkat |
| Eksi bakiyeli hesap | Herhangi bir hesap eksi bakiyede (KMH) | Dikkat |
| Gider oranları | Sabit ve değişken gider oranı / toplam nakit çıkışı; sabit oran %70'i aşarsa dikkat | Bilgi / Dikkat |
| Blokeli POS | Bankaya aktarılmayı bekleyen POS tutarı var | Bilgi |

- Eşikler `config/finance.php` içinde tanımlıdır ve `.env` ile değiştirilebilir (`FINANCE_LIQUIDITY_HORIZON_DAYS`, `FINANCE_LIQUIDITY_WARNING_PERCENT`, `FINANCE_FIXED_EXPENSE_WARNING_PERCENT`). Finansal Analiz ekranında likidite ufku 7, 15, 30, 60 veya 90 gün seçilebilir.

## Denetim kayıtları ve raporlar (PRD §20–21)

- Denetim kayıtları ekranı yalnızca **Admin** içindir. Finans ve Görüntüleyici kullanıcılar 403 alır. Ekranda kullanıcı, işlem, kaynak, tarih aralığı ve metin filtreleri var; detayda eski ve yeni değer özetleri gösterilir. Kayıtlar değiştirilemez ve silinemez.
- Denetime yazılan işlemler: giriş, başarısız giriş, çıkış, oluşturma, güncelleme, iptal/ters kayıt, kullanıcı oluşturma, pasife alma, rol değişikliği, parola değişikliği ve **her rapor indirme** (rapor, biçim, satır sayısı, filtreler).
- Dışa aktarılan raporlar: nakit akışı, alacak yaşlandırma, finansal hareket listesi, gider analizi, denetim kayıtları (yalnızca Admin). Finansal raporları Görüntüleyici dahil tüm aktif roller indirebilir.
- Excel ve PDF aynı rapor tanımından (`ReportTable`) üretilir; ekranla aynı servisleri kullanır, bu yüzden üçü de aynı rakamları gösterir.
- **Excel:** şirket başlığı, rapor adı, dönem, oluşturulma tarihi ve kullanıcı, filtreler, özet, tablolar, toplam satırları. Tutarlar sayısal hücre + `#,##0.00 "₺"` biçiminde (Türkçe Excel'de 1.234,56 ₺ görünür), tarihler gerçek tarih hücresidir. Sütun genişlikleri otomatik ayarlanır ve otomatik filtre eklenir. Not: xlsx sayıları IEEE double olarak saklar; 2 basamaklı tutarlar 15 anlamlı basamağa kadar birebir gösterilir.
- **PDF:** DejaVu Sans gömülür (Türkçe karakterler ve ₺); yalnızca kullanılan karakterler gömüldüğü için dosya küçüktür. Geniş tablolar yatay sayfaya basılır.
- **Sınırlar** (128 MB'lık varsayılan PHP belleğinde ölçüldü):
  - PDF en fazla 500 satır içerir (dompdf yaklaşık 0,4 MB/satır bellek kullanır). Daha büyük listelerde ilk 500 satır basılır ve bu durum belgede yazılır; tamamı için Excel kullanılır.
  - Excel listeleri en fazla 20.000 satır içerir (≈175 MB, 5 sn). Fazlasında kullanıcıdan tarih aralığını daraltması istenir.
  - Dışa aktarma isteklerinde bellek sınırı yalnızca o istek için 512 MB'a çıkarılır. Uygulamanın genel sınırı `docker/php.ini` dosyasında 256 MB'tır.
- İndirme adresi dakikada 30 istekle sınırlıdır.
