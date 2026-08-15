-- ===============================================================
--  Yüksek Hacimli Veride Sunucu Taraflı DataTables  |  Şema Dosyası
--  cilginyazilim.com
-- ---------------------------------------------------------------
--  NEDEN ÖRNEK VERİ SQL DOSYASINDA DEĞİL?
--  Bu projenin konusu yüz binlerce satırdır. Yüz bin INSERT satırını
--  düz metin bir .sql dosyasına yazmak (onlarca MB) hem depoyu
--  şişirir hem de içe aktarmayı yavaşlatır. Bunun yerine seed.php
--  KOMUT SATIRI betiği veriyi TOPLU (batch) INSERT'lerle üretir —
--  gerçek bir "büyük veri seti oluştur" senaryosunun ta kendisidir.
--
--  KURULUM:
--    1) mysql -u root -p < cy_datatable.sql   (şema + indeksler)
--    2) php seed.php 100000                   (100.000 örnek sipariş üretir)
-- ===============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+03:00";
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `cy_datatable`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `cy_datatable`;

DROP TABLE IF EXISTS `orders`;

-- ---------------------------------------------------------------
--  orders – Örnek sipariş kayıtları
-- ---------------------------------------------------------------
--  İNDEKS KARARLARI — hepsi 100.000 satırlık tabloda ÖLÇÜLDÜ.
--
--  Bu bölümdeki her indeksin karşılığında bir SAYI vardır. İndeks
--  bedavaya gelmez: aşağıdaki ikincil indekslerin tamamı açıkken
--  20.000 satırlık toplu INSERT 2.262 ms sürüyor, idx_orders_amount
--  ve idx_orders_customer düşürüldüğünde 1.267 ms'ye iniyor. Yani
--  yazma maliyeti neredeyse iki katı. Bu bedeli ödemek için her
--  indeksin OKUMADA ne kazandırdığını göstermek zorundayız.
--
--  ---------------------------------------------------------------
--  idx_orders_date (order_date, id)  ← EN KRİTİK OLANI
--  ---------------------------------------------------------------
--  ÖLÇÜLEN SORUN: Arayüzün VARSAYILAN sıralaması "order_date DESC".
--  Yani herkesin ilk gördüğü ekran. Bu indeks yokken:
--      ORDER BY order_date DESC LIMIT 25
--      → type=ALL, key=NULL, Using filesort, 54,45 ms
--  MySQL 100.000 satırın TAMAMINI okuyup sıralıyor, sonra 25'ini
--  gösteriyordu. Bu indeksle aynı sorgu 0,61 ms — 89 kat.
--
--  NEDEN (status, order_date) BİLEŞİK İNDEKSİ BU İŞİ GÖRMEDİ?
--  "En soldaki sütun kuralı": bileşik bir indeks yalnızca SOLDAN
--  başlayan önekleriyle kullanılabilir. (status, order_date)
--  indeksi "status = ? ORDER BY order_date" sorgusuna yarar ama
--  tek başına "ORDER BY order_date" sorgusuna YARAMAZ — çünkü
--  indeks önce status'e göre sıralıdır, order_date her status
--  bloğunun İÇİNDE sıralıdır. Tabloda 5 status değeri var, yani
--  tarihe göre sıralı 5 ayrı blok; bunları birleştirmek sıralama
--  yapmakla aynı şey.
--
--  NEDEN SONUNA `id` EKLENDİ?
--  İki sebep. (1) Kararlılık: aynı order_date'e sahip binlerce
--  satır var (100.000 satır / 730 gün ≈ günde 137 satır). İkinci
--  bir sıralama anahtarı olmadan MySQL bu satırların sırasını
--  garanti etmez; sayfa 2'ye geçtiğinizde sayfa 1'de gördüğünüz
--  bir satırı TEKRAR görebilir veya bir satırı hiç görmeyebilirsiniz.
--  (2) KAPSAYICILIK: ertelenmiş join'in alt sorgusu (bkz.
--  build_page_id_subquery(), system/function.php) yalnızca id
--  seçer; (order_date, id) indeksi bu alt sorguyu tabloya HİÇ
--  dokunmadan karşılar ("Using index").
--
--  ---------------------------------------------------------------
--  idx_orders_status_date (status, order_date)
--  ---------------------------------------------------------------
--  ÖLÇÜLDÜ, İDDİA DOĞRULANDI: "duruma göre filtrele + tarihe göre
--  sırala" sorgusu TEK indeks taramasıyla karşılanıyor:
--      WHERE status='teslim_edildi' ORDER BY order_date DESC LIMIT 25
--      → type=ref, key=idx_orders_status_date, 0,75 ms
--  Ayrı iki indeks olsaydı MySQL'in "index merge" yapması gerekirdi;
--  bu her zaman bu kadar verimli değildir.
--
--  ---------------------------------------------------------------
--  idx_orders_category (category)  |  idx_orders_amount (amount)
--  idx_orders_customer (customer_name)  |  idx_orders_product (product)
--  ---------------------------------------------------------------
--  Bunlar SIRALANABİLİR SÜTUN indeksleridir. DataTables'ta her sütun
--  başlığı tıklanabilir; indekssiz bir sütuna sıralama, 100.000
--  satırlık bir filesort demektir. Ölçüm (filtresiz, ilk sayfa):
--
--      sütun           indeks var mı?   süre
--      --------------  --------------   --------
--      id              PRIMARY          0,56 ms
--      order_number    UNIQUE           0,63 ms
--      customer_name   idx_..._customer 0,59 ms
--      category        idx_..._category 0,59 ms
--      amount          idx_..._amount   0,54 ms
--      status          idx_..._status_. 0,64 ms
--      order_date      (YOKTU)        53,32 ms  → idx_orders_date eklendi
--      product         (YOKTU)       253,61 ms  → idx_orders_product eklendi
--
--  idx_orders_product bu turda EKLENDİ: "Ürün" sütunu arayüzde
--  sıralanabilir olmasına rağmen indekssizdi ve tablodaki EN YAVAŞ
--  sıralamaydı (253,61 ms). İndeksle 0,70 ms.
--
--  ---------------------------------------------------------------
--  ÖLÇÜLDÜ VE BİLEREK EKLENMEDİ: (category, order_date)
--  ---------------------------------------------------------------
--  "Kategori filtresi + tarih sıralaması" için bileşik bir indeks
--  denendi. Kazanç ölçülebilir ama önemsizdi:
--      idx_orders_date ile          : 1,00 ms
--      (category, order_date) ile   : 0,72 ms
--  0,28 ms için altıncı bir ikincil indeksin yazma maliyetini
--  ödemek mantıklı değil. İndeks eklemek refleks değil, KARARDIR;
--  kararın dayanağı da ölçümdür. Kategori sayısı artarsa (bu
--  şemada 5 tane) bu karar yeniden ölçülmelidir.
--
--  ---------------------------------------------------------------
--  ÖLÇÜLDÜ VE BİLEREK EKLENMEDİ: FULLTEXT
--  ---------------------------------------------------------------
--  Arama için FULLTEXT(customer_name, product) denendi ve REDDEDİLDİ.
--  Gerekçe README'de ayrıntılı; özeti: idx_orders_date eklendikten
--  sonra sık eşleşen aramalarda LIKE zaten FULLTEXT'ten hızlı çıktı
--  (Zeynep: LIKE 3,09 ms / FULLTEXT 25,36 ms) ve FULLTEXT kelime
--  ORTASINDAN eşleşemiyor ('eyne' → LIKE 5.003 satır, FULLTEXT 0).
-- ---------------------------------------------------------------
CREATE TABLE `orders` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- CHAR(12): sipariş numarası SABİT uzunluktadır ('SP' + 10 hane).
  -- VARCHAR'ın uzunluk baytı burada boşuna ödenirdi.
  `order_number`   CHAR(12)     NOT NULL,
  `customer_name`  VARCHAR(120) NOT NULL,
  `product`        VARCHAR(150) NOT NULL,
  `category`       VARCHAR(60)  NOT NULL,

  -- DECIMAL: Para için DOĞRU tip. FLOAT/DOUBLE'da yuvarlama hataları
  -- birikir; büyük veri setlerinde bu hatalar toplamda anlamlı hâle gelir.
  `amount`         DECIMAL(10,2) NOT NULL,

  -- ENUM vs VARCHAR — ÖLÇÜLDÜ: status ENUM (satır içinde 1 bayt),
  -- category VARCHAR(60). Yakın seçicilikte indeksli sayım neredeyse
  -- aynı (status=kargoda 11,54 ms / category=Kitap 16,61 ms), indeks
  -- devre dışı bırakılıp tam tarama zorlandığında da fark küçük
  -- (50,54 ms / 52,94 ms). Yani ENUM'un asıl kazancı HIZ değil,
  -- DEPOLAMA ve VERİ BÜTÜNLÜĞÜDÜR: geçersiz bir durum değeri
  -- veritabanı seviyesinde reddedilir. category'nin VARCHAR
  -- kalması da bilinçlidir — kategoriler işletme tarafından
  -- eklenip çıkarılan veridir; her yeni kategori için ALTER TABLE
  -- gerektiren bir ENUM, üretimde kilitlenmeye yol açar.
  `status`         ENUM('beklemede','hazirlaniyor','kargoda','teslim_edildi','iptal') NOT NULL DEFAULT 'beklemede',

  `order_date`     DATE         NOT NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  -- Sipariş numarasında TAM EŞLEŞME araması bu indeksi kullanır
  -- (bkz. classify_search(), system/function.php): 0,49 ms.
  -- Aynı arama LIKE '%...%' ile sarmalandığında 415,53 ms sürüyordu.
  UNIQUE KEY `uniq_orders_number` (`order_number`),

  KEY `idx_orders_date`         (`order_date`, `id`),
  KEY `idx_orders_status_date`  (`status`, `order_date`),
  KEY `idx_orders_category`     (`category`),
  KEY `idx_orders_amount`       (`amount`),
  KEY `idx_orders_customer`     (`customer_name`),
  KEY `idx_orders_product`      (`product`)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
