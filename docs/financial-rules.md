# Finansal İş Kuralları

Bu doküman sprintler ilerledikçe güncellenir. Kurallar kodda servis katmanında uygulanır ve testlerle doğrulanır.

## Para

- Veritabanında para alanları `NUMERIC(18,2)` tipindedir. PHP'de `brick/math` BigDecimal kullanılır, **float kullanılmaz**.
- Eloquent'te para alanları string olarak okunur, hesaplama BigDecimal ile yapılır.
- Varsayılan para birimi `TRY`'dir. Her harekette `currency` alanı bulunur.

## Bakiye

- Hesap bakiyesi tabloda tutulmaz, hareketlerden hesaplanır: açılış + girişler − çıkışlar ± transferler. Bu sayede bakiye ile hareketler birbiriyle çelişemez.
- Transfer (örneğin POS'tan bankaya) nakit girişi veya çıkışı sayılmaz. Tek bir kayıt hem kaynaktan düşer hem hedefe ekler, aynı tutar iki kez yazılamaz.
- Blokeli POS bakiyesi kullanılabilir nakde dahil edilmez, ayrı gösterilir.

## Silme

- Finansal kayıtlar fiziksel olarak silinmez. İptal edilen kaydın yerine ters kayıt (`reversal_of_id`) oluşturulur.

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
