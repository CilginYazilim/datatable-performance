<div align="center">

<img src="assets/images/logo.png" alt="Çılgın Yazılım" width="90">

# Yüksek Hacimli DataTables Performansı

**100.000 satırlık** bir tabloda sunucu taraflı DataTables.
İndeksleme · Ertelenmiş join · Önbelleğe alınmış gerçek sayım · **Ölçülebilir** performans

[cilginyazilim.com](https://cilginyazilim.com) · MIT Lisansı · 🇬🇧 [English](README.en.md)

</div>

---

<div align="center">
<img src="assets/images/screenshot.png" alt="100.000 satırlık tablo, üstünde sorgu süresi rozetiyle" width="900">
</div>

Yukarıdaki kare bu deponun tamamını özetler: tabloda **100.000 sipariş** var ve sayfa **3,09 ms**'de geldi — rozetteki "100.000" da `information_schema` tahmini değil, sayfalamayı fiilen süren **gerçek** `COUNT(*)`'tır (bkz. [Karar 1](#karar-1--tahmini-sayım-mı-önbelleğe-alınmış-gerçek-sayım-mı)).

<div align="center">
<img src="assets/images/screenshot-mobile.png" alt="Telefonda kart görünümü: her satır etiketli bir kart" width="300">
</div>

Telefonda aynı tablo **kart görünümüne** geçer. Sekiz sütunu 390 px'e yatay kaydırmayla sığdırmak, dokunmatikte kaydırma çubuğu görünmediği için sütunların **var olduğunu gizliyordu** — bkz. [Arayüz kararları](#arayüz-kararları--mobil-tema-ve-filtre-çipleri).

Bu depo hem çalışan bir örnek hem de bir performans günlüğüdür: **buradaki her sayı ölçüldü**, hiçbiri tahmin değil.

---

## İçindekiler

- [Neden sunucu taraflı işleme zorunlu?](#neden-sunucu-taraflı-işleme-zorunlu)
- [Ölçülmüş performans tablosu](#ölçülmüş-performans-tablosu)
- [Karar 1 — Tahmini sayım mı, önbelleğe alınmış gerçek sayım mı?](#karar-1--tahmini-sayım-mı-önbelleğe-alınmış-gerçek-sayım-mı)
- [Karar 2 — `LIKE '%...%'` neden indeks kullanamaz, ne yaptık?](#karar-2--like-neden-indeks-kullanamaz-ne-yaptık)
- [Karar 3 — Derin sayfalamanın maliyeti](#karar-3--derin-sayfalamanın-maliyeti)
- [Karar 4 — Tarih aralığı: şemanın en ucuz filtresi](#karar-4--tarih-aralığı-şemanın-en-ucuz-filtresi)
- [İndeks kararları](#i̇ndeks-kararları)
- [Sıralama kararlılığı — ince ama pahalı tuzak](#sıralama-kararlılığı--ince-ama-pahalı-tuzak)
- [Arayüz kararları — mobil, tema ve filtre çipleri](#arayüz-kararları--mobil-tema-ve-filtre-çipleri)
- [Güvenlik katmanları](#güvenlik-katmanları)
- [API sözleşmesi](#api-sözleşmesi)
- [HTTP durum kodları](#http-durum-kodları)
- [Veritabanı şeması](#veritabanı-şeması)
- [Dosya yapısı](#dosya-yapısı)
- [Kurulum](#kurulum)
- [Özelleştirme](#özelleştirme)
- [Örnek kullanım alanları](#örnek-kullanım-alanları)

---

## Neden sunucu taraflı işleme zorunlu?

DataTables'ı "istemci taraflı" kullanmak, **tüm satırları tarayıcıya göndermek** demektir. 100.000 satır için bunun ne anlama geldiğini ölçtük:

| | Tüm tabloyu gönder (istemci taraflı) | Tek sayfa gönder (sunucu taraflı) |
|---|---|---|
| JSON boyutu | **18,11 MB** | **4,8 KB** |
| gzip'li boyut | 2,06 MB | ~1,5 KB |
| Veritabanından çekme | 514 ms | 1,2 ms |
| PHP tarafında JSON kodlama | 167 ms | ~0 ms |
| PHP bellek tepe noktası | **122 MB** | < 1 MB |

**3.860 kat** daha az veri. Üstelik bu tablo yalnızca sunucu tarafını ölçüyor; tarayıcı o 18 MB'ı indirdikten sonra 100.000 `<tr>` düğümü de oluşturmak zorunda kalır — mobilde sekmenin çökmesi için fazlasıyla yeterli.

Sunucu taraflı işlemede tarayıcı **yalnızca gördüğü 25 satırı** alır; filtreleme, sıralama ve sayfalama veritabanında, indeksler üzerinde yapılır. Bu deponun konusu, o "veritabanında yapılan iş"in **doğru** yapılmasıdır.

---

## Ölçülmüş performans tablosu

100.000 satır, MySQL 8 / InnoDB, XAMPP, PHP 8.2. Her değer 5 çalıştırmanın **medyanı**, sunucu içi süre (`timings.total_ms`).

| Senaryo | Önce | Sonra (soğuk) | Sonra (sıcak) | Kazanç |
|---|---|---|---|---|
| Filtresiz ilk sayfa | 55,75 ms | 56,98 ms | **2,14 ms** | 26× |
| Sıralama: Ürün | 251,32 ms | 58,77 ms | **2,08 ms** | 121× |
| Sıralama: Tarih | 53,46 ms | 62,27 ms | **1,99 ms** | 27× |
| Arama: `Zeynep` (tam tarama) | 349,64 ms | 239,58 ms | **4,96 ms** | 70× |
| Arama: `SP0000050000` (tam eşleşme) | 424,55 ms | 52,65 ms | **2,11 ms** | **201×** |
| Filtre: `status` | 53,50 ms | 113,99 ms | **2,55 ms** | 21× |
| Filtre: `category` | 79,23 ms | 76,45 ms | **3,75 ms** | 21× |
| Derin sayfa (OFFSET 99.000) | 240,25 ms | 111,21 ms | **61,99 ms** | 3,9× |
| Tarih aralığı (2025 Ocak, 4.179 satır) | — | 17,46 ms | **4,07 ms** | bkz. [Karar 4](#karar-4--tarih-aralığı-şemanın-en-ucuz-filtresi) |

**"Soğuk" ve "sıcak" ne demek?** Soğuk = sayım önbelleği boş, `COUNT(*)` gerçekten çalışıyor. Sıcak = sayım önbellekten geliyor (0,12 ms). Gerçek kullanımda ilk istek soğuk, ardından gelen **tüm sayfalama/sıralama istekleri sıcaktır** — sütun başlığına tıklayan ya da sayfa değiştiren kullanıcının gördüğü süre "sıcak" sütunudur. İkisini de veriyoruz çünkü yalnızca sıcak sayıları göstermek, önbelleğin maliyetini gizlemek olurdu.

Derin sayfalama eğrisi (sıcak):

| OFFSET | 0 | 1.000 | 25.000 | 50.000 | 99.000 |
|---|---|---|---|---|---|
| Süre | 2,02 ms | 2,77 ms | 19,29 ms | 40,45 ms | 65,22 ms |

---

## Karar 1 — Tahmini sayım mı, önbelleğe alınmış gerçek sayım mı?

### Bulunan hata

Bu depo eskiden toplam satır sayısını `information_schema.TABLES.TABLE_ROWS` üzerinden **tahmini** okuyordu. Kodun kendi yorumu şunu iddia ediyordu:

> "Ama SAYFALAMA MANTIĞI için (kaç sayfa var?) kullanılmaz — o her zaman recordsFiltered'a, yani GERÇEK bir COUNT(*) sonucuna dayanır."

**Bu iddia yanlıştı.** Filtre yokken kod `$recordsFiltered = $recordsTotal` yapıyordu; yani tahmin doğrudan sayfalamayı sürüyordu. `recordsFiltered`, DataTables'ın **"kaç sayfa var?"** sorusunu cevapladığı alandır. Ölçüldü:

```
Gerçek COUNT(*)                      : 100.000
Yanıttaki recordsFiltered (tahmin)   :  99.316
id ASC son erişilebilir sayfadaki son id:  99.325
Gerçek MAX(id)                       : 100.000
→ 675 satıra HİÇBİR ŞEKİLDE ulaşılamıyordu.
```

Üstelik bu, filtre uygulanmamış **varsayılan görünümde** — yani herkesin ilk gördüğü ekranda — oluyordu. Filtre uygulanınca gerçek `COUNT(*)` çalıştığı için sorun kayboluyordu; tam olarak en çok kullanılan ekran bozuktu.

### "ANALYZE TABLE çalıştır, düzelir" neden yanlış?

Denendi. `ANALYZE TABLE orders` sonrası tahmin **99.579**'a düştü — yani fark 310'dan **421'e ÇIKTI**. Tahmin, InnoDB'nin rastgele indeks sayfası örneklemesinden gelir; "daha taze" olması "daha doğru" olması demek değildir. Aynı tabloda tek bir çalışma oturumu içinde tahminin **99.690 → 99.579 → 99.316** diye gezindiğini ölçtük; tablo hiç değişmemişti, yalnızca `ALTER TABLE` ve `ANALYZE TABLE` istatistikleri yeniden hesaplattı.

### Verilen karar

**Sayfalamayı süren sayı her zaman gerçek `COUNT(*)`'tır; maliyeti dosya önbelleği karşılar.** `information_schema` tahmini artık uygulamanın hiçbir yerinde okunmuyor.

Bir ara sürümde tahmin silinmemiş, arayüzde gerçek sayının **yanında** karşılaştırma rozeti olarak bırakılmıştı — "iki sayıyı yan yana görmek öğreticidir" gerekçesiyle. Bu, geliştirme sürecinin dürüst bir parçası olduğu için burada da kayıtlı: rozet gösterdiği andan itibaren tahmin **sabitti** (aynı tabloda değişmiyordu), yani her istekte yeniden okumanın kazandırdığı hiçbir şey yoktu — yalnızca `information_schema` sorgusunun kendi maliyetini (~0,8 ms) ödüyorduk. Rozet arayüzden kaldırıldı, sorgu da onunla birlikte gitti; bu bölümdeki ölçümler ise **kalıcı bir uyarı** olarak kalıyor, çünkü aynı hataya bir dahaki sefere düşmemek için "neden information_schema'ya güvenilemez" bilgisinin bir yerde yazılı durması gerekiyor.

### Önbelleğin ödünleşmesi (gizlemiyoruz)

`SELECT COUNT(*) FROM orders` **47 ms** sürüyor. InnoDB'de `COUNT(*)` hazır bir sayaçtan okunmaz (MyISAM'da okunurdu); en küçük ikincil indeksin tamamı taranır. Her isteğe 47 ms eklemek, 2 ms'ye indirdiğimiz sayfayı yeniden 49 ms yapardı. Dosya önbelleğinden okumak **0,12 ms** — 390 kat ucuz.

**Peki önbellek de "yanlış olabilir", tahminden farkı ne?**

| | `information_schema` tahmini | Önbelleğe alınmış `COUNT(*)` |
|---|---|---|
| Ne kadar yanlış? | **Bilinemez** (%0,3–%50) | En fazla TTL kadar **eski** |
| Yönü belli mi? | Hayır, iki yönde de sapar | Evet, yalnızca geride kalır |
| Kendiliğinden düzelir mi? | **Hayır** | Evet, TTL dolunca kesinleşir |
| `ANALYZE TABLE` düzeltir mi? | **Hayır** (ölçüldü, kötüleşti) | İlgisiz |
| Yazma sonrası anında doğrulanabilir mi? | Hayır | **Evet** (`count_cache_forget()`) |

Fark esastır: tahmin **kalıcı ve yönü bilinmeyen** bir hatadır; önbellek **sınırlı ve kendiliğinden kapanan** bir gecikmedir. Bu proje salt okunur olduğu için tablo istekler arasında değişmez ve önbellekteki sayı her zaman doğrudur. Yazma yapan bir sistemde `COUNT_CACHE_TTL`'i düşürün veya `INSERT`/`DELETE` sonrası `count_cache_forget()` çağırın — `seed.php` tam olarak bunu yapar.

**Filtreli sayımlar da önbelleğe alınır.** Bir arama sonucunda 40 sayfa geziyorsanız eski kod her sayfada 175 ms'lik bir `COUNT(*)` daha ödüyordu — aynı sayıyı 40 kez yeniden hesaplamak için.

---

## Karar 2 — `LIKE '%...%'` neden indeks kullanamaz, ne yaptık?

### Neden kullanamaz?

B-tree indeksler bir sözlük gibi **soldan sağa** sıralıdır. Sözlükte "ile **başlayan**" kelimeleri bulmak kolaydır; "**içinde** geçen" kelimeleri bulmak için sözlüğü baştan sona okumanız gerekir. MySQL de aynı durumdadır:

```sql
WHERE customer_name LIKE 'Zeynep%'   -- ✅ indeks ARALIĞI (range) — 6,15 ms
WHERE customer_name LIKE '%Zeynep%'  -- ❌ tam tarama (ALL)      — 77,79 ms
```

### Bulunan hata

Eski kod, arama kutusuna **ne yazılırsa yazılsın** üç sütunu `%...%` ile sarıyordu. En can sıkıcı sonuç:

```
LIKE '%SP0000050000%'          → 415,53 ms   type=ALL,   key=NULL, rows=99.316
order_number = 'SP0000050000'  →   0,49 ms   type=const, key=uniq_orders_number, rows=1
```

**848 kat fark.** `uniq_orders_number` benzersiz indeksi zaten oradaydı — kod onu kullanmıyordu, çünkü birebir eşleşen bir sipariş numarasını da `%...%` içine sarıyordu.

### Verilen karar: yazılanın biçimine bakıp yolu seçmek

`classify_search()` üç yol tanır:

| Yazılan | Yol | SQL | Süre |
|---|---|---|---|
| `SP0000050000` (SP + 10 hane) | **exact** | `order_number = ?` | **2,11 ms** |
| `SP000005` (SP + 1–9 hane) | **prefix** | `order_number LIKE 'SP000005%'` | **3,65 ms** |
| `Zeynep`, `ÇILGIN`, `Kablosuz` | **scan** | 3 sütunda `LIKE '%...%'` | 4,96 ms (sıcak) / 239 ms (soğuk) |

Hangi yolun seçildiği **ekranda yazar** ("Arama yolu: tam tarama (LIKE %…%)"). Maliyet gizlenmiyor.

### Neden genel aramayı da hızlandırmadık? — FULLTEXT denendi ve REDDEDİLDİ

`FULLTEXT(customer_name, product)` indeksi gerçekten eklendi ve ölçüldü. Reddedilme gerekçeleri, **kendi ölçümlerimizle**:

**1. Kelime ortasından eşleşemiyor.** FULLTEXT kelimeleri belirteç (token) olarak indeksler; `%eyne%` gibi bir arama karşılığı yoktur:

```
LIKE '%eyne%'                 → 5.003 satır   (Zeynep'in ortası)
MATCH ... AGAINST ('eyne*')   →     0 satır
```

Kullanıcı "ÇILGIN" yazdığında bunu **soyad** olarak arar — müşteri adının sonunda. Doğru sonucu vermeyen hızlı bir arama, yavaş bir aramadan **daha kötüdür**.

**2. Beklenenin aksine daha yavaş çıktı.** `idx_orders_date` eklendikten sonra optimizasyon MySQL'in lehine döndü: sık eşleşen bir aramada sıralama indeksini takip edip 25 satır bulunca **erken durabiliyor**.

```
Arama 'Zeynep' — veri sorgusu:
  LIKE '%Zeynep%'  :  3,09 ms
  FULLTEXT MATCH   : 25,36 ms
```

**3. Sipariş numaralarını kapsamıyor**, minimum belirteç uzunluğu 3 (`innodb_ft_min_token_size`), ve altıncı bir indeksin yazma maliyeti var.

**Dürüst sınır:** `scan` yolu hâlâ tam tarama yapar ve soğuk hâlde ~240 ms sürer. Bunu **çözmedik**, ehlileştirdik: hızlı yollar en sık aramaları boşalttı, sayım önbelleğe alındı (aynı aramada sayfa gezinmek 5 ms), ve bu yol **ayrıca ve daha dar** bir hız sınırına tabi. 100.000 satırın çok ötesinde gerçek bir "içinde geçen" araması gerekiyorsa doğru araç Elasticsearch/Meilisearch gibi bir arama motorudur — MySQL değil.

---

## Karar 3 — Derin sayfalamanın maliyeti

`LIMIT 25 OFFSET 99000`, MySQL'e *"99.025 satır üret, ilk 99.000'ini AT"* demektir. Atılan satırların **tam satır verisi de okunur** — 8 sütun, hepsi boşuna.

### Verilen karar: ertelenmiş join (deferred join / late row lookup)

Önce **yalnızca id'ler**, kapsayıcı `(order_date, id)` indeksinden seçilir — bu alt sorgu tabloya **hiç dokunmaz** (`Using index`). Sonra kalan 25 id için tam satır birincil anahtardan çekilir (25 kez `type=eq_ref`):

```sql
SELECT o.id, o.order_number, ...
  FROM orders o
  JOIN (SELECT id FROM orders
         ORDER BY order_date DESC, id DESC
         LIMIT 25 OFFSET 99000) k ON k.id = o.id
 ORDER BY o.order_date DESC, o.id DESC
```

| OFFSET | Düz `OFFSET` | Ertelenmiş join | Kazanç |
|---|---|---|---|
| 0 | 0,65 ms | 0,93 ms | *(0,3 ms daha yavaş)* |
| 1.000 | 66,39 ms | **1,36 ms** | 49× |
| 25.000 | 136,07 ms | **15,99 ms** | 8,5× |
| 50.000 | 165,21 ms | **30,71 ms** | 5,4× |
| 99.000 | 252,98 ms | **61,26 ms** | 4,1× |

İlk sayfada **0,3 ms daha yavaş** olduğunu da yazıyoruz — join'in kendi maliyeti var ve ilk sayfada atılacak satır yok. 25. satırdan sonraki her yerde kazandırıyor.

### Neden keyset (seek) sayfalama değil?

Keyset sayfalama (`WHERE order_date < ? LIMIT 25`) `OFFSET`'i tamamen ortadan kaldırır ve **her sayfada sabit sürede** çalışır. Teknik olarak üstün çözüm budur ve 61 ms'yi 0,6 ms yapardı.

Uygulamadık, çünkü **DataTables arayüzü kullanıcıya sayfa numarası verir**: "sayfa 2.847'ye git" diyebilirsiniz. Keyset sayfalamada 2.847. sayfanın başlangıç anahtarı **bilinmez** — oraya ancak 2.846 sayfayı sırayla geçerek ulaşılır. Yani keyset, "sonraki/önceki" düğmeleri ve sonsuz kaydırma için doğrudur; **sayfa numarası veren bir arayüzle bağdaşmaz.**

Bu bilinçli bir ödünleşmedir: sayfa numaralarından vazgeçebiliyorsanız keyset'e geçin.

---

## Karar 4 — Tarih aralığı: şemanın en ucuz filtresi

Bu turda arayüze bir **tarih aralığı filtresi** eklendi (`date_from` / `date_to`). Eklenme sebebi yalnızca kullanışlılık değil: bu şemadaki **en verimli erişim yolunu** gösteren örnek tam olarak budur.

`idx_orders_date (order_date, id)` zaten vardı ve varsayılan sıralamayı (`order_date DESC`) karşılıyordu. Tarih aralığı **aynı indekste** bir **aralık taraması** açar; yani MySQL tek bir indeksi hem **filtre** hem **sıralama** için kullanır, ayrıca bir filesort yapmaz. `EXPLAIN` (ertelenmiş join'in alt sorgusu):

```
type=range  key=idx_orders_date  rows=4179  Extra: Using where; Using index
```

`Using index` kritik: alt sorgu tabloya **hiç dokunmuyor**, yalnızca indeks girdilerini okuyor.

Aynı sorguyu `IGNORE INDEX` ile indeksi devre dışı bırakarak ölçtük (2025 Ocak aralığı, 4.179 satır eşleşiyor, 5 çalıştırmanın medyanı):

| | Süre | `EXPLAIN` |
|---|---|---|
| `idx_orders_date` **kullanılıyor** | **1,81 ms** | `type=range`, `Using index` |
| İndeks devre dışı | **287,40 ms** | `type=ALL`, `rows=91.493`, `Using filesort` |

**159 kat.** Aynı `WHERE`, aynı sonuç kümesi — tek fark indeksin kullanılıp kullanılmaması.

### Geçersiz tarihte ne oluyor?

Tarih değerleri prepared statement'a gitmeden önce **biçim ve takvim** açısından doğrulanır (`valid_date()`, `system/function.php`): regex `YYYY-MM-DD` biçimini, `checkdate()` ise `2025-02-30` gibi biçimi doğru ama **takvimde olmayan** tarihleri eler. Geçersiz bir değer **hata vermez, filtre olarak da uygulanmaz** — yok sayılır.

Neden reddetmek yerine yok saymak? Bu bir vitrin; kullanıcıya "geçersiz tarih" hatası göstermek yerine filtresiz sonucu göstermek daha az kırıcıdır. Ama sessiz kalmıyoruz: sunucunun **kabul ettiği** uçlar yanıtın `meta` alanında geri yansıtılır ve arayüz kutuları buna göre düzeltir — böylece **ekranda yazan filtre ile uygulanan filtre her zaman aynıdır.**

Aynı mantık ters girilen uçlarda da çalışır: `date_from > date_to` ise ikisi **takas edilir**. Aksi hâlde sorgu her zaman 0 satır dönerdi ve arayüz kullanıcı hatasını "veri yok" diye gösterirdi.

Ölçüldü:

| Girdi | Sonuç |
|---|---|
| `2025-03-01` → `2025-01-01` (ters) | Takas edildi, 8.125 kayıt, `meta` düzeltilmiş uçları döndü |
| `2025-02-30` (takvimde yok) | Yok sayıldı, 100.000 kayıt |
| `abc'OR1=1` | Yok sayıldı, 100.000 kayıt |

---

## İndeks kararları

**Her indeksin bir bedeli vardır.** Ölçtük: tüm ikincil indeksler açıkken 20.000 satırlık toplu `INSERT` **2.262 ms**; `idx_orders_amount` ve `idx_orders_customer` düşürüldüğünde **1.267 ms**. Yazma maliyeti neredeyse iki katı. Tabloda **11,5 MB veri** karşılığında **33,2 MB indeks** var.

Bu bedeli ödemek için her indeksin okumada ne kazandırdığını göstermek zorundayız. DataTables'ta her sütun başlığı tıklanabilir; **indekssiz bir sütuna sıralama, her tıklamada 100.000 satırlık bir filesort demektir.**

| Sütun | İndeks | Süre |
|---|---|---|
| `id` | `PRIMARY` | 0,56 ms |
| `order_number` | `uniq_orders_number` | 0,63 ms |
| `customer_name` | `idx_orders_customer` | 0,59 ms |
| `category` | `idx_orders_category` | 0,59 ms |
| `amount` | `idx_orders_amount` | 0,54 ms |
| `status` | `idx_orders_status_date` | 0,64 ms |
| `order_date` | **YOKTU** → `idx_orders_date` eklendi | 53,32 ms → **0,61 ms** |
| `product` | **YOKTU** → `idx_orders_product` eklendi | 253,61 ms → **0,70 ms** |

### `idx_orders_date (order_date, id)` — bu turun en kritik bulgusu

Arayüzün **varsayılan sıralaması** `order_date DESC`. Yani herkesin ilk gördüğü ekran. Bu indeks yokken:

```
ORDER BY order_date DESC LIMIT 25
→ type=ALL, key=NULL, Using filesort, 54,45 ms
```

MySQL 100.000 satırın **tamamını** okuyup sıralıyor, sonra 25'ini gösteriyordu. **Varsayılan ekranın 55 ms'sinin neredeyse tamamı buydu.**

**Neden `(status, order_date)` bu işi görmedi?** *En soldaki sütun kuralı*: bileşik bir indeks yalnızca **soldan başlayan öneklerıyle** kullanılabilir. `(status, order_date)` indeksi önce `status`'e göre sıralıdır; `order_date` her `status` bloğunun **içinde** sıralıdır. 5 status değeri = tarihe göre sıralı 5 ayrı blok; bunları birleştirmek sıralama yapmakla aynı şeydir.

**Neden sonuna `id`?** (1) Sıralama kararlılığı (aşağıya bakın). (2) **Kapsayıcılık**: ertelenmiş join'in alt sorgusu yalnızca `id` seçer; `(order_date, id)` bu alt sorguyu tabloya hiç dokunmadan karşılar.

### Ölçüldü ve bilerek EKLENMEDİ: `(category, order_date)`

```
idx_orders_date ile        : 1,00 ms
(category, order_date) ile : 0,72 ms
```

0,28 ms için altıncı bir ikincil indeksin yazma maliyetini ödemek mantıklı değil. **İndeks eklemek refleks değil, karardır.**

### `ENUM` (status) vs `VARCHAR` (category)

Yakın seçicilikte ölçüldü — beklenenin aksine fark **küçük**:

| | indeksli `COUNT` | indeks devre dışı (tam tarama) |
|---|---|---|
| `status = 'kargoda'` (ENUM, 14.839 satır) | 11,54 ms | 50,54 ms |
| `category = 'Kitap'` (VARCHAR, 19.953 satır) | 16,61 ms | 52,94 ms |

ENUM'un asıl kazancı **hız değil**, depolama (satır içinde 1 bayt) ve **veri bütünlüğüdür** — geçersiz bir durum değeri veritabanı seviyesinde reddedilir. `category`'nin `VARCHAR` kalması da bilinçlidir: kategoriler işletme tarafından eklenip çıkarılan **veridir**; her yeni kategori için `ALTER TABLE` gerektiren bir `ENUM`, üretimde kilitlenmeye yol açar.

---

## Sıralama kararlılığı — ince ama pahalı tuzak

Sayfalamanın **kararlı** olması için `ORDER BY`'ın satırları tekilleştiren bir anahtarla bitmesi gerekir. Aksi hâlde eşit değerli satırların sırası garanti değildir ve sayfa 2'ye geçtiğinizde sayfa 1'de gördüğünüz bir satırı **tekrar** görebilirsiniz. Bu tabloda somut: aynı `(status, order_date)` çiftini paylaşan **3.645 grup** var.

Bunu düzeltmek için her sıralamaya `, id` eklemek **cazip ama yanlıştır** — ve indeksi bozar. Kendi düzeltmemizde bu tuzağa düştük, ölçtük, geri aldık:

```
ORDER BY status, id              → 64,95 ms   (Using filesort)  ❌
ORDER BY status, order_date, id  →  0,93 ms   (Using index)     ✅

ORDER BY order_number, id        → 127,76 ms  (Using filesort)  ❌
ORDER BY order_number            →   0,84 ms  (Using index)     ✅
```

**Neden?** InnoDB'de her ikincil indeksin sonuna birincil anahtar zaten eklenir; `idx_orders_customer` aslında `(customer_name, id)`'dir, bu yüzden `ORDER BY customer_name, id` o indeksin doğrudan önekidir. Ama `idx_orders_status_date` `(status, order_date, id)`'dir — `ORDER BY status, id` bu indeksin **öneki değildir**, aradaki `order_date` atlanmıştır. Ters yönde `order_number` zaten `UNIQUE` olduğu için tekilleştiricidir; `, id` eklemek **hiçbir şey kazandırmaz** ama optimizasyonu bozar.

Doğru kural: **kararlılık için gereken en kısa anahtar listesini, o sütuna hizmet eden indeksin kendi sırasını izleyerek kur.** Kod bunu `sort_keys()` içinde sütun bazlı yapar.

---

## Arayüz kararları — mobil, tema ve filtre çipleri

Sunucu tarafı ne kadar hızlı olursa olsun, tablo telefonda okunamıyorsa performans bir şeye yaramaz. Bu turda arayüz de aynı yöntemle ele alındı: **önce ölç, sonra düzelt.** Ölçümler gerçek Chrome'da, 390×844 ve 360×740 görünüm alanlarında yapıldı.

### 1. Yatay kaydırma "duyarlı tasarım" değildir

Tablonun 8 sütunu 390 px genişliğinde **~980 px** yer istiyordu. `.table-responsive` bunu yatay kaydırmaya çeviriyordu — ama **dokunmatikte kaydırma çubuğu görünmez**: kullanıcı "Tutar" ve "Durum" sütunlarının **var olduğunu** bile bilmiyordu.

Artık dar ekranda (`≤ 767.98 px`) her satır bir **kart**, her hücre bir "etiket: değer" satırı olur. Etiketi CSS'in `::before`'ı basar; metni `table.js` her hücreye `data-label` olarak yazar (`applyMobileLabels()`).

**Neden etiketler sunucuda basılmıyor?** Aynı etiketi 500 satırın her hücresine yazmak yanıt gövdesini gereksiz büyütürdü (8 sütun × 500 satır = 4.000 tekrar). Etiketler `<thead>`'de zaten bir kez var; istemci onları oradan okur — bu depo veri boyutunu ciddiye alan bir depo.

### 2. Bulunan hata: `box-sizing` yüzünden taşan hücreler

Kart görünümü ilk hâliyle **bozuktu** ve sebebi ölçülene kadar görünmüyordu:

```
tr  genişlik: 340 px
td  genişlik: 365 px   ← 25 px DIŞARI taşıyor
td  computed box-sizing: content-box
```

DataTables'ın kendi CSS'i `table.dataTable tbody td` için `box-sizing`'i **`content-box`** yapıyor. Bu yüzden `width: 100%` (338 px) + 27,2 px yatay dolgu = **365 px** oldu; sipariş numarasının son hanesi ve tutarın kuruşu ekranda **kesiliyordu**. Kart görünümünde dolgu genişliğin **içinde** sayılmalı — `box-sizing: border-box`.

### 3. Bulunan hata: koyu temada okunmayan rozetler

"Toplam … ms" rozeti ve filtre çipleri `--cy-brand-100` zemin + `--cy-brand-700` metin kullanıyordu. Bu çift **açık temada doğru** (#e7f1fc üzerine #0a3d73, ~9:1) ama koyu temada zemin koyu laciverte (#10263f) dönüyor, **metin rengi değişmiyordu**: kontrast ≈ **1,2:1** — yazı fiilen okunmuyordu.

Çözüm, zemin gibi **metnin de bir token olması**: `--cy-on-brand-soft`. Bileşenler artık sabit bir marka tonuna değil, temayla birlikte dönen bu değişkene bakar.

### 4. Sıralama telefonda kaybolmasın

Kart görünümünde `<thead>` gizlenir — ve onunla birlikte **tıklanabilir sütun başlıkları** da gider. Aynı işi yapan bir sıralama seçici + yön düğmesi eklendi ve DataTables ile **çift yönlü** eşitlenir: kullanıcı masaüstünde başlığa tıklayıp pencereyi daralttığında denetimler gerçek sıralamayı gösterir. Arayüzün yalan söylememesi, çalışmasından ayrı bir gerekliliktir.

### 5. Sayfalama: taşan içeriği ortalamayın

100.000 satır = **4.000 sayfa**. Sayfa numaraları 390 px'e sığmaz, bu yüzden sayfalama kendi içinde kaydırılabilir. İlk denemede hizalama `center` idi ve taşan içerik ortalandığı için soldaki **"İlk / Önceki" düğmelerine kaydırarak bile ulaşılamıyordu**. `flex-start` ile düzeltildi.

Aynı bölümde bilgi metni (`100.000 kayıttan 1 – 25 arası…`) masaüstü için `nowrap` idi; 390 px'de cümlenin **iki ucu birden** kırpılıyordu. Dar ekranda sarmalanmasına izin verildi.

### 6. Ölçülen diğer düzeltmeler

| Ne | Neden |
|---|---|
| Araç çubuğu `flex + overflow-x` → **ızgara** | Filtre kutuları ekranın sağında, hiçbir işaret olmadan duruyordu |
| Performans şeridi → **`<details>`** | Telefonda dört satır kaplayıp tabloyu ekran dışına itiyordu; kapalıyken bile toplam süreyi gösterir |
| Form alanlarına `min-height: 44px`, `font-size: 1rem` | 16 px altındaki alana odaklanınca **iOS Safari sayfayı otomatik yakınlaştırıyor** |
| Sayfa değişince tablonun başına dön | Sayfalama düğmeleri tablonun **altında**; "Sonraki"ye basan kullanıcı yeni sayfanın sonunda kalıyordu |
| Filtre değişince 1. sayfaya dön | 40. sayfadayken filtre daraltılınca **boş sayfada** kalınıyor, kullanıcı bunu "sonuç yok" sanıyordu |
| `thousands: '.'`, `decimal: ','` | DataTables `100,000` yazarken sayfanın geri kalanı `100.000` diyordu — aynı ekranda iki sayı dili |
| Tema tercihi `<head>` içinde, satır içi | `table.js` `<body>` sonunda yüklendiği için sayfa önce açık boyanıp koyuya atlıyordu (FOUC). CSP delinmez: **nonce** yalnızca o bloğa izin verir |
| Etkin filtre **çipleri** | Beş ayrı kutuya dağılmış filtrede "neden 12 kayıt görüyorum?" sorusunun cevabı tek bakışta görünmüyordu |
| Çipler `textContent` ile kuruluyor | Değer doğrudan arama kutusundan geliyor; `innerHTML` self-XSS yolu açardı |
| `meta.count_cached` **ekrana bağlandı** | Alan yanıtta zaten vardı ama hiçbir yerde gösterilmiyordu — oysa sayımın 47 ms mi 0,12 ms mi olduğunu **açıklayan tek bilgi** budur |

---

## Güvenlik katmanları

Bu bir vitrin uygulaması olsa da, güvenlik açığı olan bir örnek **kopyalanır**. Bulunan ve kapatılan her şey ölçümüyle:

### 1. Dosya erişimi (`.htaccess` hiç yoktu)

| Adres | Önce | Sonra |
|---|---|---|
| `/system/config.php` | **200** (her çağrıda DB bağlantısı açıyordu) | **403** |
| `/system/function.php` | **200** | **403** |
| `/cy_datatable.sql` | **200** (şema indirilebiliyordu) | **403** |
| `/.gitignore` | **200** | **403** |
| `/assets/js/` | **200** (klasör listesi) | **403** |
| `/README.md` | **200** | **403** |

`system/.htaccess` **beyaz liste** kullanır (`Require all denied` + yalnızca `ajax.php` açık). Kara liste yazsaydık projeye yarın eklenen her dosya **varsayılan olarak açık** olurdu. Güvenlikte varsayılanın **yönü**, kuralın kendisinden önemlidir.

İkinci katman: dosyaların içindeki `CY_APP` kontrolü. `.htaccess` yalnızca Apache'de ve `AllowOverride` açıkken okunur; nginx'e taşındığında PHP kontrolü çalışır.

### 2. Güvenlik başlıkları (dördü de yoktu)

`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` ve bir **CSP** eklendi. CSP'de `'unsafe-inline'` **yok**: sayfadaki tek satır içi `<script>` bloğu her istekte değişen bir **nonce** ile imzalanır.

### 3. Oturum sabitleme (session fixation) — gerçekten çalışıyordu

Uydurma bir kimlikle denendi:

```
Cookie: PHPSESSID=cytest26664ffa2245a4e620ee
→ Sunucu bu kimliği KABUL ETTİ, yeni Set-Cookie basmadı,
  o kimlikle CSRF token üretti, /system/ajax.php HTTP 200 döndü.
```

`session.use_strict_mode=1` ile kapatıldı. Aynı testte artık sunucu yeni bir kimlik basıyor ve uydurma kimlikle yapılan istek **403** dönüyor.

Çerez bayrakları da yoktu (`PHPSESSID=...; path=/`). Şimdi: `HttpOnly; SameSite=Lax` ve HTTPS altında otomatik `Secure`.

### 4. CSRF reddi 419 → 403

419 resmî bir HTTP kodu değildir ve **bu kurulumdaki Apache onu sessizce 500'e çeviriyordu** (ölçüldü). Yani gövde doğru mesajı taşırken durum kodu "sunucu çöktü" diyordu. `403` ile değiştirildi.

### 5. Hız sınırı (hiç yoktu)

`list` uç noktası kimlik doğrulaması istemez ama pahalıdır. Kimliği doğrulanmamış bir istekle sunucu zamanı yaktırmak ucuz bir DoS kaldıracıdır.

**Sınır ölçülerek belirlendi.** Gerçek Chrome'da, gerçek arayüz sürülüp XHR'ler sayıldı: **9,1 saniyelik kesintisiz yoğun kullanımda 11 istek** (1 açılış, 3 sayfalama, 3 sıralama, 2 filtre, 2 arama). Kritik ayrıntı: o 2 arama isteği **14 tuş vuruşundan** doğdu — arama 350 ms geciktirmeli (debounce). Geciktirme olmasaydı aynı yazma **14 istek ve 14 tam tablo taraması** üretirdi.

Bu tempo dakikaya vurulduğunda ~72 istek/dk eder (bir insanın değil, 400 ms'de bir tıklayan bir betiğin temposu).

| Kova | Sınır | Doğrulama |
|---|---|---|
| `list` (tüm istekler) | **120 / dk** | 72 istek → hepsi 200. 121. istekte 429 + `Retry-After: 58` |
| `search` (yalnızca tam tarama) | **30 / dk** | 31. taramada 429; **60 tam-eşleşme araması → hepsi 200** |

İkinci, dar sınır yalnızca **pahalı** yola uygulanır: indeksli sipariş no araması bu sınıra takılmaz.

Hız sınırı **CSRF'den sonra** çalışır. Önce olsaydı, token'ı olmayan bir saldırgan sayacı doldurup **meşru kullanıcıyı kilitleyebilirdi** — sınırın kendisi bir saldırı aracına dönerdi.

### 6. Zaten sağlam olanlar (regresyon testinde doğrulandı)

- **SQL enjeksiyonu yok**: sütun adları beyaz listeden seçilir, `order[0][column]=99&dir=DROP` → 200, güvenli varsayılan.
- **XSS yok**: `e()` sunucuda uygulanır; `<script>` içeren müşteri adı yanıta `&lt;script&gt;` olarak döner.
- **`length=999999`** → 500 satırla kesilir. **`start=-5`** → `max(0, ...)`.
- **`seed.php` web'den erişilemez** (`PHP_SAPI !== 'cli'` → 403). Yıkıcı bir betiktir (`TRUNCATE`).

---

## API sözleşmesi

Tek uç nokta: `POST system/ajax.php`. Salt okunur — CRUD yoktur.

### İstek

DataTables'ın standart sunucu taraflı parametrelerine ek olarak:

| Alan | Tip | Açıklama |
|---|---|---|
| `draw` | int | DataTables sayacı — **yarış koruması**, olduğu gibi yansıtılır |
| `start` | int | Atlanacak satır (`max(0, …)`) |
| `length` | int | Sayfa uzunluğu (üst sınır **500**) |
| `search[value]` | string | Arama metni — yolu `classify_search()` seçer |
| `order[0][column]` | int | Sütun indeksi (0–7), beyaz listeden çözülür |
| `order[0][dir]` | string | `asc` \| `desc` (başka her şey `desc`) |
| `category_filter` | string | Kategori adı |
| `status_filter` | string | `ORDER_STATUSES` anahtarı |
| `date_from` | string | `YYYY-MM-DD` — geçersizse **yok sayılır** (`valid_date()`) |
| `date_to` | string | `YYYY-MM-DD` — `date_from`'dan küçükse ikisi **takas edilir** |
| `csrf_token` | string | **Zorunlu** (veya `X-CSRF-Token` başlığı) |

### Yanıt

```json
{
  "draw": 7,
  "recordsTotal": 100000,
  "recordsFiltered": 100000,
  "data": [[99695, "SP0000099695", "Taha YILDIRIM", "…"]],
  "timings": {
    "count_total_ms": 0.31,
    "count_filtered_ms": 0,
    "data_query_ms": 1.24,
    "total_ms": 1.55
  },
  "meta": {
    "count_cached": true,
    "search_mode": "scan",
    "search_label": "tam tarama (LIKE %…%)",
    "date_from": "2025-01-01",
    "date_to": "2025-01-31"
  }
}
```

`timings` ve `meta` **DataTables sözleşmesinin parçası değildir** — ekrandaki rozetleri besleyen öğretici eklerdir.

`meta.date_from` / `meta.date_to`, sunucunun **kabul ettiği** uçlardır (geçersiz değer `null` döner, ters uçlar takas edilmiş gelir). Arayüz kutuları bu değere çeker; böylece ekranda yazan filtre ile uygulanan filtre her zaman aynı olur.

### Yarış durumu (race) nasıl önleniyor?

Kullanıcı hızlıca sayfa değiştirdiğinde geç gelen eski yanıt yenisinin üstüne binebilir. Koruma **protokol seviyesindedir**: istemci her çizimde `draw` sayacını artırır, DataTables gelen yanıtın `draw`'ı mevcut sayaçtan küçükse yanıtı **sessizce atar**. DataTables 1.13.6 kaynağında doğrulandı:

```js
if (+r < t.iDraw) return;
```

Sunucunun tek görevi değeri **olduğu gibi yansıtmaktır** — kod bunu yapar (ölçüldü: `draw=7` → `"draw": 7`, tam sayı tipinde). İki ayrı oturumla eşzamanlı istek testi yapıldı; yanıtlar sıra değiştirse bile koruma çalışır.

---

## HTTP durum kodları

| Kod | Ne zaman |
|---|---|
| **200** | Başarılı listeleme |
| **403** | CSRF token geçersiz/eksik, ya da `system/` dosyalarına doğrudan erişim |
| **405** | `POST` dışında bir yöntem |
| **429** | Hız sınırı aşıldı — `Retry-After` başlığı ve `retry_after` alanı ile |
| **500** | Beklenmeyen sunucu/veritabanı hatası |

> **Not:** Bu kurulumdaki Apache **419**'u tanımıyor ve sessizce **500**'e çeviriyor (ölçüldü). CSRF reddi için doğru kod zaten `403`'tür.

---

## Veritabanı şeması

```sql
CREATE TABLE `orders` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_number`   CHAR(12)     NOT NULL,   -- 'SP' + 10 hane, sabit uzunluk
  `customer_name`  VARCHAR(120) NOT NULL,
  `product`        VARCHAR(150) NOT NULL,
  `category`       VARCHAR(60)  NOT NULL,
  `amount`         DECIMAL(10,2) NOT NULL,  -- para için DOĞRU tip (FLOAT değil)
  `status`         ENUM('beklemede','hazirlaniyor','kargoda','teslim_edildi','iptal')
                     NOT NULL DEFAULT 'beklemede',
  `order_date`     DATE         NOT NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_orders_number`   (`order_number`),
  KEY `idx_orders_date`         (`order_date`, `id`),
  KEY `idx_orders_status_date`  (`status`, `order_date`),
  KEY `idx_orders_category`     (`category`),
  KEY `idx_orders_amount`       (`amount`),
  KEY `idx_orders_customer`     (`customer_name`),
  KEY `idx_orders_product`      (`product`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`amount` için `DECIMAL`: `FLOAT`/`DOUBLE`'da yuvarlama hataları birikir; büyük veri setlerinde bu hatalar toplamda anlamlı hâle gelir.

---

## Dosya yapısı

```
datatable-performance/
├── index.php                  ← Arayüz: performans rozeti + tablo
├── cy_datatable.sql           ← Şema VE indeksler (veri YOK) + indeks gerekçeleri
├── seed.php                   ← CLI veri üretici (php seed.php [satır_sayısı])
├── .htaccess                  ← Dizin listeleme kapalı, .sql/.md engelli, güvenlik başlıkları
├── system/
│   ├── .htaccess              ← BEYAZ LİSTE: yalnızca ajax.php açık
│   ├── config.php             ← Oturum güvenliği, DB, sınır/TTL sabitleri
│   ├── function.php           ← Sayım önbelleği, arama yönlendirme, ertelenmiş join, hız sınırı
│   └── ajax.php               ← TEK uç nokta: list (salt okunur)
└── assets/
    ├── css/style.css          ← Sayfaya özel stiller + MOBİL kart görünümü (cilginyazilim.css'e dokunulmaz)
    ├── js/table.js            ← DataTables kurulumu, rozetler, filtre çipleri, tema, mobil etiketler
    └── images/                ← logo + ekran görüntüleri (masaüstü, arama, mobil)
```

### Hangi fonksiyon ne işe yarar?

| Fonksiyon | Dosya | Görevi |
|---|---|---|
| `handle_list()` | `ajax.php` | Tek uç nokta; parametreleri doğrular, `WHERE` kurar, süreleri ölçer |
| `count_cached()` | `function.php` | **Gerçek** `COUNT(*)`, dosya önbellekli (47 ms → 0,12 ms) |
| `count_cache_forget()` | `function.php` | Yazma sonrası önbelleği boşaltır (`seed.php` çağırır) |
| `classify_search()` | `function.php` | Arama metnine bakıp exact / prefix / scan yolunu seçer |
| `valid_date()` | `function.php` | Tarih uçlarını biçim **ve takvim** açısından doğrular (`checkdate()`) |
| `build_page_sql()` | `function.php` | Ertelenmiş join sorgusunu kurar |
| `sort_keys()` | `function.php` | Sütun bazlı, indeksi bozmayan kararlı sıralama anahtarları |
| `rate_limit()` | `function.php` | `flock()` kilitli, dosya tabanlı kayan pencere sayacı |
| `security_headers()` | `function.php` | CSP dahil güvenlik başlıkları (nonce ile) |
| `require_csrf()` | `function.php` | CSRF doğrulaması, reddi **403** |
| `e()` | `function.php` | Sunucu taraflı HTML kaçışlama (XSS) |

---

## Kurulum

**Gereksinimler:** PHP 8.1+, MySQL 5.7+ / MariaDB 10.3+, Apache (`mod_headers` önerilir).

```bash
cd C:/xampp/htdocs
git clone https://github.com/CilginYazilim/datatable-performance.git
cd datatable-performance

# 1) Şemayı ve indeksleri oluştur
mysql -u root -p < cy_datatable.sql

# 2) Örnek veri üret (komut satırından, tarayıcıdan DEĞİL)
php seed.php 100000
```

`http://localhost/datatable-performance/`

> **Canlıya alırken** `system/config.php` içindeki `APP_DEBUG`'ı `false` yapın.

**Daha büyük bir veri setiyle deneyin:** `php seed.php 500000` çalıştırıp aynı senaryoları tekrarlayın; rozetlerdeki sayıların nasıl değiştiğini izleyin. Özellikle derin sayfalama ve `scan` araması eğrisi öğreticidir.

---

## Özelleştirme

| İstediğiniz | Nereye bakın |
|---|---|
| Sayım önbelleği ömrü | `COUNT_CACHE_TTL` — `system/config.php` |
| Hız sınırları | `RATE_LIMIT_LIST`, `RATE_LIMIT_SEARCH` — `system/config.php` |
| Sayfa uzunluğu tavanı | `MAX_PAGE_LENGTH` — `system/config.php` |
| Arama geciktirme süresi | `SEARCH_DEBOUNCE_MS` — `assets/js/table.js` |
| Durum listesi/renkleri | `ORDER_STATUSES` — `system/config.php` |
| Mobil kart görünümü eşiği | `MOBILE_BREAKPOINT` (`table.js`) **+** `@media (max-width: 767.98px)` (`style.css`) — **ikisi aynı olmak zorunda** |
| Tema renkleri | `:root` token'ları — `assets/css/cilginyazilim.css` (koyu tema aynı dosyada) |
| Marka zemini üzerindeki metin | `--cy-on-brand-soft` — `assets/css/style.css` |
| Yeni sıralanabilir sütun | `$sortableColumns` (`ajax.php`) **+** `sort_keys()` (`function.php`) **+ indeks** |
| Arama yolları | `classify_search()` — `system/function.php` |

> **Yeni bir sıralanabilir sütun eklerken**: mutlaka bir indeks ekleyin ve `sort_keys()` içindeki karşılığını `EXPLAIN` ile doğrulayın. İndekssiz bir sütun, her tıklamada 100.000 satırlık bir filesort demektir (ölçüldü: 253 ms).

---

## Örnek kullanım alanları

- **Sipariş / fatura yönetimi** — yüz binlerce kayıt, sipariş no ile hızlı erişim
- **Log ve denetim izi (audit trail) görüntüleyicileri** — çok yazma, çok okuma, derin sayfalama
- **E-ticaret yönetim panelleri** — kategori + durum filtreleri, tarih sıralaması
- **CRM müşteri listeleri** — isimde arama, Türkçe karakterli veri
- **Finansal işlem dökümleri** — `DECIMAL` tutar, tutara göre sıralama
- **Stok / envanter tabloları** — ürün adında arama, kategori filtresi
- **Performans öğrenme materyali** — indeks kararlarının etkisini canlı ölçerek görmek

---

## Test edildi

Bu turda yapılan tüm değişiklikler **ölçülerek** doğrulandı.

**Sunucu tarafı:** her sütunda çift yönlü sıralama, kategori + durum + tarih aralığı filtreleri ve bileşimleri, Türkçe karakterli müşteri adında arama (`ÇILGIN`, `ŞAHİN`, `ÖZTÜRK`, `Ayşe`), arama yolu yönlendirmesi, sınır değerleri, geçersiz/ters tarih uçları, XSS ve SQL enjeksiyonu regresyonu, hız sınırının meşru kullanımı bozmadığı, eşzamanlı isteklerde yarış koruması.

**Arayüz (gerçek Chrome, otomatikleştirilmiş):** 390×844, 360×740 ve 1440×950 görünüm alanlarında — üç durumlu tema döngüsü ve sayfa yenilemesinden sonra korunması, mobil kart etiketlerinin sekiz sütunun tamamına basılması, tarih aralığı filtresi (4.179 kayıt), filtre çiplerinin üretilmesi ve tek tek kaldırılması, "Filtreleri temizle", mobil sıralama denetiminin DataTables ile eşitlenmesi, arama temizleme düğmesi. Her üç görünüm alanında da **yatay taşma 0 px** ve konsolda **JavaScript hatası yok**.

---

## Lisans

MIT — dilediğiniz gibi indirip kullanabilirsiniz.

### Daha fazla örnek kod

Bu projenin ayrıntılı yazısı ve diğer açık kaynak örnekler:

- 📘 **[Bu projenin yazısı](https://cilginyazilim.com/kutuphane/datatables-performans-optimizasyonu)** — DataTables performans optimizasyonu
- 📚 **[Örnek kodlar & kütüphane](https://cilginyazilim.com/kutuphane)** — tüm açık kaynak örnekler

---

**Çılgın Yazılım** · [cilginyazilim.com](https://cilginyazilim.com) · [Kütüphane](https://cilginyazilim.com/kutuphane) · [github.com/CilginYazilim/datatable-performance](https://github.com/CilginYazilim/datatable-performance)
