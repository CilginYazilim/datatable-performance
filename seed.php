<?php
/**
 * =====================================================================
 *  ÖRNEK VERİ ÜRETİCİ (yalnızca komut satırından çalışır)
 *  cilginyazilim.com – Yüksek Hacimli Veride Sunucu Taraflı DataTables
 * ---------------------------------------------------------------------
 *  Kullanım:
 *      php seed.php              → 50.000 sipariş üretir (varsayılan)
 *      php seed.php 200000       → 200.000 sipariş üretir
 *
 *  NEDEN TARAYICIDAN DEĞİL, KOMUT SATIRINDAN?
 *  1) Yüz binlerce satır üretmek dakikalar sürebilir; PHP'nin web
 *     istek zaman aşımı (genelde 30 sn) bu işi yarıda keserdi.
 *  2) Bu, "veritabanını doldur" gibi YIKICI bir işlemdir (mevcut
 *     tabloyu boşaltır). Bir URL'ye tıklayarak tetiklenebilir olması
 *     tehlikelidir; php_sapi_name() kontrolü bunu web'den ERİŞİLEMEZ
 *     kılar.
 *
 *  NEDEN TOPLU (BATCH) INSERT?
 *  50.000 kez ayrı ayrı INSERT çalıştırmak, her biri için ayrı bir
 *  ağ gidiş-dönüşü (round-trip) ve ayrı bir transaction demektir —
 *  bu, toplam süreyi KAT KAT uzatır. Bunun yerine 1.000 satırlık
 *  gruplar hâlinde, TEK INSERT cümlesiyle (çoklu VALUES) ve TEK
 *  transaction içinde yazıyoruz. Fark, 50.000 satırda saniyeler
 *  ile dakikalar arasındadır.
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Bu betik yalnızca komut satırından çalıştırılabilir: php seed.php [satır_sayısı]');
}

define('CY_APP', true);

require __DIR__ . '/system/config.php';
require __DIR__ . '/system/function.php';

set_time_limit(0); // CLI'da varsayılan zaman aşımı yoktur ama açıkça belirtmek niyeti netleştirir.

$totalRows = isset($argv[1]) ? max(1, (int) $argv[1]) : 50000;
$batchSize = 1000;

/* --- Örnek veri havuzları --------------------------------------- */
$firstNames = ['Evren', 'Taha', 'Zeynep', 'Mustafa', 'Elif', 'Ahmet', 'Ayşe', 'Mehmet', 'Fatma', 'Emre',
               'Selin', 'Burak', 'Merve', 'Onur', 'Ceren', 'Kaan', 'Büşra', 'Serkan', 'Gizem', 'Barış'];
$lastNames  = ['ÇILGIN', 'BAYAR', 'TURAN', 'YILMAZ', 'KAYA', 'DEMİR', 'ŞAHİN', 'ÇELİK', 'YILDIZ', 'YILDIRIM',
               'ÖZTÜRK', 'AYDIN', 'ÖZDEMİR', 'ARSLAN', 'DOĞAN', 'KILIÇ', 'ASLAN', 'ÇETİN', 'KARA', 'KOÇ'];

$categories = [
    'Elektronik' => ['Kablosuz Kulaklık', 'Akıllı Saat', 'Powerbank', 'Bluetooth Hoparlör', 'USB-C Kablo'],
    'Giyim'      => ['Pamuklu Tişört', 'Kot Pantolon', 'Spor Ayakkabı', 'Yağmurluk', 'Yün Kazak'],
    'Ev & Yaşam' => ['Kahve Makinesi', 'Blender', 'Yastık Seti', 'Aromaterapi Difüzör', 'Mutfak Bıçak Seti'],
    'Kitap'      => ['Roman', 'Kişisel Gelişim Kitabı', 'Çocuk Kitabı', 'Ansiklopedi', 'Şiir Kitabı'],
    'Spor'       => ['Yoga Matı', 'Dambıl Seti', 'Koşu Bandı', 'Spor Çantası', 'Fitness Eldiveni'],
];

$statuses = ['beklemede', 'hazirlaniyor', 'kargoda', 'teslim_edildi', 'iptal'];
// Ağırlıklı dağılım: gerçek bir e-ticaret sisteminde "teslim edildi"
// durumundaki siparişler her zaman çoğunluktadır. Basit bir rastgele
// seçim yerine ağırlıklı seçim, filtreleme testlerini daha anlamlı
// (ve gerçekçi ölçüde dengesiz) kılar.
$statusWeights = [5, 10, 15, 65, 5]; // toplam 100

/**
 * Ağırlıklı rastgele bir dizi elemanı seçer.
 *
 * @param array<int,mixed> $items
 * @param array<int,int>   $weights Aynı uzunlukta ağırlıklar
 */
function weightedRandom(array $items, array $weights): mixed
{
    $total = array_sum($weights);
    $pick  = random_int(1, $total);
    $sum   = 0;

    foreach ($items as $index => $item) {
        $sum += $weights[$index];

        if ($pick <= $sum) {
            return $item;
        }
    }

    return $items[array_key_last($items)];
}

/** Son 2 yıl içinde rastgele bir tarih üretir ('Y-m-d'). */
function randomDate(): string
{
    $daysAgo = random_int(0, 730);

    return (new DateTimeImmutable())->modify("-{$daysAgo} days")->format('Y-m-d');
}

echo "Tablo boşaltılıyor…\n";
$db->exec('TRUNCATE TABLE orders');

echo "{$totalRows} satır, {$batchSize}'lik gruplar hâlinde üretiliyor…\n";

$startTime  = microtime(true);
$categoryKeys = array_keys($categories);
$orderNumberCounter = 1;

for ($offset = 0; $offset < $totalRows; $offset += $batchSize) {
    $rowsInBatch = min($batchSize, $totalRows - $offset);

    /* --- Çoklu VALUES INSERT cümlesini elle kur ---------------------
     * "(?, ?, ?, ?, ?, ?, ?)" parçasını satır sayısı kadar tekrarlayıp
     * virgülle birleştiriyoruz. Böylece 1.000 satır TEK SQL cümlesi
     * ve TEK ağ gidiş-dönüşüyle yazılır. */
    $placeholders = implode(',', array_fill(0, $rowsInBatch, '(?, ?, ?, ?, ?, ?, ?)'));
    $sql = "INSERT INTO orders (order_number, customer_name, product, category, amount, status, order_date)
            VALUES $placeholders";

    $values = [];

    for ($i = 0; $i < $rowsInBatch; $i++) {
        $category = $categoryKeys[array_rand($categoryKeys)];
        $product  = $categories[$category][array_rand($categories[$category])];

        $values[] = 'SP' . str_pad((string) $orderNumberCounter, 10, '0', STR_PAD_LEFT);
        $values[] = $firstNames[array_rand($firstNames)] . ' ' . $lastNames[array_rand($lastNames)];
        $values[] = $product;
        $values[] = $category;
        $values[] = number_format(random_int(2500, 999900) / 100, 2, '.', '');
        $values[] = weightedRandom($statuses, $statusWeights);
        $values[] = randomDate();

        $orderNumberCounter++;
    }

    // TEK TRANSACTION İÇİNDE: InnoDB, her INSERT'ten sonra diski
    // senkronize etmek yerine tüm grup bittiğinde bir kez yazar.
    // Bu, otomatik commit (autocommit) açıkken KAT KAT yavaş olurdu.
    $db->beginTransaction();
    $db->prepare($sql)->execute($values);
    $db->commit();

    $done = $offset + $rowsInBatch;
    $percent = round($done / $totalRows * 100);
    echo "\r  {$done} / {$totalRows} (%{$percent})   ";
}

$elapsed = round(microtime(true) - $startTime, 2);

/* SAYIM ÖNBELLEĞİNİ TEMİZLE — bu betik tabloyu TRUNCATE edip yeniden
 * dolduruyor, yani önbellekteki sayı artık yanlış. Yazma yapan her
 * işlemden sonra bu çağrı gerekir; unutulursa sayfalama COUNT_CACHE_TTL
 * (60 sn) boyunca ESKİ sayıya göre çalışır. Önbelleğin ödünleşmesi
 * tam olarak budur ve gizlenmemesi gerekir (bkz. count_cached()). */
count_cache_forget();

echo "\n\nTamamlandı: {$totalRows} satır, {$elapsed} saniyede yazıldı ";
echo '(' . round($totalRows / max($elapsed, 0.01)) . " satır/saniye).\n";
echo "Sayım önbelleği temizlendi.\n";
echo "Şimdi http://localhost/datatable-performance/ adresini açabilirsiniz.\n";
echo "Şema/indeksleri henüz kurmadıysanız: mysql -u root -p < cy_datatable.sql\n";
