<?php
/**
 * =====================================================================
 *  YARDIMCI FONKSİYONLAR
 *  cilginyazilim.com – Yüksek Hacimli Veride Sunucu Taraflı DataTables
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('CY_APP')) {
    http_response_code(403);
    exit;
}


/* =====================================================================
 *  BÖLÜM 1 – ÇIKTI VE YANIT
 * ================================================================== */

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * HTML sayfası için güvenlik başlıkları.
 * ---------------------------------------------------------------------
 *  ÖLÇÜLEN SORUN: index.php yanıtında bu başlıkların DÖRDÜ DE yoktu.
 *  json_response() yalnızca kendi JSON yanıtına nosniff ekliyordu;
 *  asıl saldırı yüzeyi olan HTML sayfa tamamen açıktı.
 *
 *  NEDEN HEM BURADA HEM .htaccess'te?
 *  .htaccess yalnızca Apache'de ve AllowOverride açıkken okunur.
 *  Aynı kod nginx'e ya da PHP'nin dahili sunucusuna taşındığında o
 *  dosya hiçbir şey yapmaz. Başlıkları uygulamanın kendisinden de
 *  basmak, korumayı sunucu yapılandırmasından BAĞIMSIZ kılar.
 *  (Apache aynı başlığı iki kez basmaz; Header always set aynı adı
 *  ezer, çift değer oluşmaz — ölçüldü.)
 *
 *  CSP'de neden 'nonce'? Sayfada CyPerfTable.init(...) çağrısını
 *  yapan satır içi (inline) bir <script> var. 'unsafe-inline'
 *  yazmak CSP'yi büyük ölçüde anlamsızlaştırırdı; nonce ise
 *  YALNIZCA bizim bastığımız o tek bloğa izin verir, saldırganın
 *  enjekte ettiği bir bloğa vermez (nonce her istekte değişir).
 */
function security_headers(string $scriptNonce): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'nonce-" . $scriptNonce . "'; "
        . "style-src 'self' 'unsafe-inline'; "   // DataTables satır içi stil yazar (genişlik hesapları)
        . "img-src 'self' data:; "
        . "connect-src 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'self'; "
        . "base-uri 'self'; "
        . "object-src 'none'"
    );
}

function json_response(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $description, int $status = 400, array $extra = []): void
{
    json_response(array_merge(['success' => false, 'type' => 'danger', 'description' => $description], $extra), $status);
}


/* =====================================================================
 *  BÖLÜM 2 – OTURUM VE CSRF
 * ================================================================== */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        /* Boş oturum ile veri taşıyan oturum aynı kimliği paylaşmasın.
         * Asıl sabitleme savunması config.php'deki use_strict_mode'dur
         * (bkz. oradaki ölçüm notu); bu satır ucuz bir ek katmandır. */
        session_regenerate_id(true);

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * CSRF doğrulaması.
 * ---------------------------------------------------------------------
 *  ÖLÇÜLEN SORUN: Reddetme 419 ile yapılıyordu ve bu kurulumdaki
 *  Apache 419'u TANIMIYOR — istemciye sessizce HTTP 500 olarak
 *  geçiyordu. Yani gövde doğru mesajı taşırken durum kodu "sunucu
 *  çöktü" diyordu; hem istemci tarafında doğru dallanma imkânsızdı
 *  hem de gerçek bir 500 ile ayırt edilemiyordu.
 *
 *  Ölçüm: token'sız POST → HTTP 500 (gövde doğru mesajı taşıyor).
 *  403 kullanıldığında → HTTP 403, gövde aynı. 419 zaten resmî bir
 *  HTTP kodu değil (Laravel'in uydurduğu bir kod); "kimliğin var ama
 *  bu işleme yetkin yok" anlamı için doğru kod 403'tür.
 */
function require_csrf(): void
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!is_string($token) || $token === ''
        || empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $token)) {

        json_error('Oturum doğrulaması başarısız. Lütfen sayfayı yenileyin.', 403);
    }
}


/* =====================================================================
 *  BÖLÜM 3 – HIZ SINIRI
 * ---------------------------------------------------------------------
 *  NEDEN GEREKLİ: list uç noktası kimlik doğrulaması İSTEMEZ (herkese
 *  açık bir vitrindir) ama PAHALIDIR. Düzeltmelerden sonra bile
 *  LIKE '%...%' araması ~175 ms sunucu zamanı yakıyor. Kimliği
 *  doğrulanmamış bir istekle saniyede beş kez yarım saniye sunucu
 *  zamanı yaktırabilmek, ucuz bir hizmet dışı bırakma (DoS)
 *  kaldıracıdır.
 *
 *  NEDEN DOSYA TABANLI: bu kurulumda APCu YOK (ölçüldü). Redis gibi
 *  bir bağımlılık eklemek, "indirip çalıştır" vaadini bozar. flock()
 *  ile kilitlenen bir dosya, tek sunuculu kurulumlarda yeterlidir.
 *  ÇOK SUNUCULU bir kurulumda bu sayaç sunucu başına ayrı tutulur ve
 *  gerçek sınır sunucu sayısıyla çarpılır — o senaryoda merkezî bir
 *  sayaca (Redis) geçilmelidir. Bu, bilinçli bir sınırdır.
 * ================================================================== */

function rate_limit_dir(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cy_datatable_rate';

    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    return $dir;
}

/**
 * İsteği yapan tarafın kimliği. REMOTE_ADDR kullanılır; X-Forwarded-For
 * BİLEREK okunmaz — bu başlık istemci tarafından uydurulabilir ve ters
 * vekil arkasında OLMAYAN bir kurulumda ona güvenmek, hız sınırını tek
 * satırlık bir başlıkla kapatılabilir hâle getirir. Vekil arkasında
 * çalışacaksanız burayı BİLEREK değiştirin.
 */
function client_fingerprint(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'bilinmiyor');
}

/**
 * Kayan pencere (sliding window) sayacı.
 *
 * Sabit pencere (her dakikanın başında sıfırlanan sayaç) daha ucuzdur
 * ama pencere sınırında iki katı isteğe izin verir (59. saniyede 120,
 * 61. saniyede 120 daha). Kayan pencere bu boşluğu bırakmaz.
 */
function rate_limit(string $bucket, int $limit, int $windowSeconds): void
{
    $file = rate_limit_dir() . DIRECTORY_SEPARATOR
          . sha1($bucket . '|' . client_fingerprint()) . '.json';

    $handle = @fopen($file, 'c+');

    if ($handle === false) {
        /* Sayaç yazılamıyorsa (disk dolu, izin yok) isteği REDDETMİYORUZ:
         * bu bir vitrindir, koruma katmanının arızası yüzünden meşru
         * kullanıcıyı kapıda bırakmak orantısız olur. Ama sessiz de
         * kalmıyoruz — sınırın UYGULANAMADIĞI log'a düşer. */
        error_log('[PERF] Hiz siniri sayaci yazilamadi: ' . $file);

        return;
    }

    flock($handle, LOCK_EX);

    $now  = microtime(true);
    $hits = json_decode((string) stream_get_contents($handle), true);
    $hits = is_array($hits) ? $hits : [];

    // Pencerenin dışında kalan damgaları at.
    $hits = array_values(array_filter(
        $hits,
        static fn($t): bool => is_numeric($t) && ($now - (float) $t) < $windowSeconds
    ));

    if (count($hits) >= $limit) {
        $retryAfter = max(1, (int) ceil($windowSeconds - ($now - (float) $hits[0])));

        flock($handle, LOCK_UN);
        fclose($handle);

        if (!headers_sent()) {
            header('Retry-After: ' . $retryAfter);
        }

        json_error(
            'Çok fazla istek gönderdiniz. Lütfen ' . $retryAfter . ' saniye sonra tekrar deneyin.',
            429,
            ['retry_after' => $retryAfter]
        );
    }

    $hits[] = $now;

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($hits));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}


/* =====================================================================
 *  BÖLÜM 4 – SAYIM
 * ---------------------------------------------------------------------
 *  BU PROJENİN EN ÖNEMLİ DÜZELTMESİ BURADA. Ayrıntılı gerekçe için
 *  README'deki "Tahmini sayım mı, önbelleğe alınmış gerçek sayım mı?"
 *  bölümüne bakın; özeti:
 *
 *  ESKİ DAVRANIŞ (HATALIYDI): toplam satır sayısı information_schema
 *  üzerinden TAHMİNİ okunuyordu ve filtre YOKKEN bu tahmin doğrudan
 *  recordsFiltered'a yazılıyordu. recordsFiltered ise DataTables'ın
 *  KAÇ SAYFA VAR sorusunu cevapladığı alandır. Sonuç ölçüldü:
 *
 *      Gerçek COUNT(*)           : 100.000
 *      Yanıttaki recordsFiltered :  99.316
 *      Son erişilebilir satırın id'si: 99.325   (id ASC, 25'lik sayfa)
 *      → 675 satıra HİÇBİR ŞEKİLDE ulaşılamıyordu.
 *
 *  Üstelik tahmin KARARLI da değil: aynı tabloda tek bir oturum
 *  içinde 99.690 → 99.579 → 99.316 diye gezindi (ALTER TABLE ve
 *  ANALYZE TABLE istatistikleri yeniden hesaplattığı için). ANALYZE
 *  TABLE çalıştırmak sapmayı KAPATMADI, 310'dan 421'e ÇIKARDI.
 *  Tahmin doğası gereği yaklaşıktır; sayfalama ise KESİN bir sayı
 *  ister. İkisi aynı alana yazılamaz.
 *
 *  ÖNCEKİ SÜRÜMDE tahmin silinmemiş, gerçek sayının YANINDA
 *  karşılaştırma rozeti olarak bırakılmıştı (information_schema
 *  sorgusu her istekte ayrıca çalışıyordu, ~0,8 ms). O rozet arayüzden
 *  kaldırıldı; artık kimsenin okumadığı bir sorguyu her istekte
 *  çalıştırmanın gerekçesi kalmadığı için estimate_total_rows()
 *  fonksiyonu da BU SÜRÜMDE SİLİNDİ. Yukarıdaki ölçüm, o hatanın ve
 *  neden information_schema'ya güvenilemeyeceğinin kalıcı kaydıdır —
 *  ayrıntılı gerekçe README'de duruyor.
 * ================================================================== */

function count_cache_dir(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cy_datatable_count';

    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    return $dir;
}

/**
 * GERÇEK bir COUNT(*) çalıştırır ve sonucu dosyada önbelleğe alır.
 * ---------------------------------------------------------------------
 *  NEDEN ÖNBELLEK, NEDEN HER İSTEKTE COUNT DEĞİL?
 *  Ölçüldü: SELECT COUNT(*) FROM orders → 47 ms. InnoDB'de COUNT(*)
 *  hazır bir sayaçtan okunmaz (MyISAM'da okunurdu); en küçük ikincil
 *  indeksin TAMAMI taranır. Düzeltmelerden sonra filtresiz sayfanın
 *  veri sorgusu 0,95 ms'ye indi — her isteğe 47 ms'lik bir sayım
 *  eklemek, kazanılan her şeyi geri verirdi.
 *
 *  Önbellekten okuma: 0,12 ms (ölçüldü). 390 kat ucuz.
 *
 *  ÖNBELLEĞİN ÖDÜNLEŞMESİ NEDİR, TAHMİNDEN FARKI NE?
 *  İkisi de "gerçek olmayabilir" gibi görünür ama farkları esastır:
 *
 *   - TAHMİN her zaman ve KALICI olarak yanlıştır; hangi yönde ve ne
 *     kadar yanlış olduğu bilinemez, ANALYZE TABLE ile düzelmez.
 *   - ÖNBELLEK en fazla TTL kadar ESKİdir ve o sürenin sonunda
 *     KESİNLEŞİR. Yani hata sınırlıdır ve kendiliğinden kapanır.
 *
 *  Bu proje salt okunur bir vitrin olduğu için tablo istek arasında
 *  değişmez ve önbellekteki sayı her zaman doğrudur. Yazma yapan bir
 *  sistemde TTL'i düşürün (COUNT_CACHE_TTL, config.php) veya
 *  yazma işleminden sonra count_cache_forget() çağırın.
 *
 * @param string $signature Filtre imzası — aynı filtre aynı kutuyu kullanır.
 */
function count_cached(PDO $db, string $signature, string $sql, array $params, int $ttl): array
{
    $file = count_cache_dir() . DIRECTORY_SEPARATOR . sha1($signature) . '.json';
    $now  = time();

    $cached = json_decode((string) @file_get_contents($file), true);

    if (is_array($cached) && isset($cached['n'], $cached['at']) && ($now - (int) $cached['at']) < $ttl) {
        return ['count' => (int) $cached['n'], 'cached' => true];
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $count = (int) $stmt->fetchColumn();

    /* LOCK_EX: iki istek aynı anda yazarsa dosya yarım kalmasın.
     * Yazma başarısız olursa (izin yok) sessizce devam ederiz —
     * sonuç yine DOĞRUdur, sadece bir sonraki istek de sayacaktır. */
    @file_put_contents($file, json_encode(['n' => $count, 'at' => $now]), LOCK_EX);

    return ['count' => $count, 'cached' => false];
}

/** Önbelleği tamamen boşaltır. Veri yazan bir sistemde INSERT/DELETE sonrası çağrılmalıdır. */
function count_cache_forget(): void
{
    foreach (glob(count_cache_dir() . DIRECTORY_SEPARATOR . '*.json') ?: [] as $f) {
        @unlink($f);
    }
}


/* =====================================================================
 *  BÖLÜM 5 – ARAMA YÖNLENDİRME
 * ---------------------------------------------------------------------
 *  ÖLÇÜLEN SORUN: Arama kutusuna ne yazılırsa yazılsın üç sütunda
 *  LIKE '%...%' çalışıyordu — type=ALL, key=NULL, 99.316 satır.
 *  En can sıkıcı örnek: BİREBİR eşleşen bir sipariş numarası bile
 *  tam tarama yapıyordu, çünkü kod onu da %...% içine sarıyordu:
 *
 *      LIKE '%SP0000050000%'  → 415,53 ms  (uniq_orders_number KULLANILMIYOR)
 *      order_number = 'SP0000050000' → 0,49 ms  (type=const, rows=1)
 *
 *  848 kat fark. UNIQUE indeks zaten oradaydı, sadece kullanılmıyordu.
 *
 *  ÇÖZÜM: aramayı tek bir kalıba zorlamak yerine, YAZILANIN BİÇİMİNE
 *  BAKIP yolu seçmek. Üç yol var (bkz. classify_search):
 *
 *    exact   → 'SP' + 10 hane: order_number = ?          (0,49 ms)
 *    prefix  → 'SP' + 1..9 hane: order_number LIKE 'SP..%' (indeks aralığı)
 *    scan    → diğer her şey: üç sütunda LIKE '%...%'    (tam tarama)
 *
 *  NEDEN "scan" TAMAMEN KALDIRILMADI?
 *  Çünkü kullanıcı "ÇILGIN" yazdığında bunu SOYAD olarak arıyor —
 *  müşteri adının SONUNDA. Önek araması (LIKE 'ÇILGIN%') bu kaydı
 *  BULAMAZDI. Doğru sonucu vermeyen hızlı bir arama, yavaş bir
 *  aramadan daha kötüdür. Bu yüzden genel metin araması bilinçli
 *  olarak tam tarama kalır — ama artık (a) hızlı yollar onu büyük
 *  ölçüde boşaltır, (b) sayımı önbelleğe alınır, (c) ayrıca ve daha
 *  dar bir hız sınırına tabidir, (d) hangi yolun seçildiği ekranda
 *  YAZAR (rozet), yani maliyet gizlenmez.
 *
 *  NEDEN FULLTEXT DEĞİL? Denendi ve ölçüldü — bkz. README. Kısaca:
 *  idx_orders_date eklendikten sonra sık eşleşen aramalarda LIKE
 *  zaten FULLTEXT'ten hızlı (3,09 ms / 25,36 ms) ve FULLTEXT kelime
 *  ORTASINDAN eşleşemiyor ('eyne' → LIKE 5.003 satır, FULLTEXT 0).
 * ================================================================== */

function escape_like(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
}

/**
 * Arama metnine bakıp hangi sorgu yolunun kullanılacağına karar verir.
 *
 * @return array{mode:string, where:string, params:array<string,string>, label:string}
 */
function classify_search(string $search): array
{
    // 'SP' + tam 10 hane → benzersiz indekste TAM eşleşme.
    if (preg_match('/^SP(\d{10})$/i', $search, $m) === 1) {
        return [
            'mode'   => 'exact',
            'where'  => 'order_number = :s_exact',
            'params' => [':s_exact' => 'SP' . $m[1]],
            'label'  => 'tam eşleşme (benzersiz indeks)',
        ];
    }

    /* 'SP' + 1..9 hane → önek araması. Sipariş numaraları soldan
     * sıfır dolgulu olduğu için ("SP" + 10 hane) soldan yazılan her
     * parça anlamlı bir ÖNEKtir ve B-tree indekste ARALIK taramasına
     * dönüşür. Sondan joker (LIKE 'SP0000050%') indeksi KULLANABİLİR;
     * baştan joker (LIKE '%...%') kullanamaz — fark tam olarak budur. */
    if (preg_match('/^SP(\d{1,9})$/i', $search, $m) === 1) {
        return [
            'mode'   => 'prefix',
            'where'  => 'order_number LIKE :s_prefix',
            'params' => [':s_prefix' => escape_like('SP' . $m[1]) . '%'],
            'label'  => 'önek araması (indeks aralığı)',
        ];
    }

    // Genel metin araması: üç sütunda tam tarama. Maliyeti bilinçlidir.
    $pattern = '%' . escape_like($search) . '%';

    return [
        'mode'   => 'scan',
        'where'  => '(order_number LIKE :s_number OR customer_name LIKE :s_name OR product LIKE :s_product)',
        'params' => [':s_number' => $pattern, ':s_name' => $pattern, ':s_product' => $pattern],
        'label'  => 'tam tarama (LIKE %…%)',
    ];
}


/* =====================================================================
 *  BÖLÜM 6 – SAYFALAMA SORGUSU (ertelenmiş join)
 * ================================================================== */

/**
 * Derin sayfalamanın maliyetini düşüren "ertelenmiş join" (deferred
 * join / late row lookup) sorgusunu kurar.
 * ---------------------------------------------------------------------
 *  SORUN: LIMIT 25 OFFSET 99000, MySQL'e "99.025 satır üret, ilk
 *  99.000'ini AT" demektir. Atılan satırların TAM SATIR verisi de
 *  okunur — 8 sütun, hepsi boşuna. Ölçüldü (order_date DESC):
 *      OFFSET 0     →   0,65 ms
 *      OFFSET 1000  →  66,39 ms
 *      OFFSET 50000 → 165,21 ms
 *      OFFSET 99000 → 252,98 ms
 *
 *  ÇÖZÜM: önce SADECE id'leri, kapsayıcı (order_date, id) indeksinden
 *  seç — bu alt sorgu tabloya HİÇ dokunmaz ("Using index"). Sonra
 *  kalan 25 id için tam satırı birincil anahtardan çek (25 kez
 *  type=eq_ref). Atılan 99.000 satırın maliyeti, geniş bir satır
 *  okuması olmaktan çıkıp dar bir indeks girdisi okumasına iner:
 *      OFFSET 1000  →   1,36 ms   (49 kat)
 *      OFFSET 50000 →  30,71 ms   (5,4 kat)
 *      OFFSET 99000 →  61,26 ms   (4,1 kat)
 *
 *  NEDEN KEYSET (seek) SAYFALAMA DEĞİL?
 *  Keyset sayfalama ("WHERE order_date < ? LIMIT 25") OFFSET'i
 *  tamamen ortadan kaldırır ve her sayfada SABİT sürede çalışır —
 *  teknik olarak üstün çözüm budur. Ama DataTables arayüzü
 *  kullanıcıya SAYFA NUMARASI verir: "sayfa 2.847'ye git"
 *  diyebilirsiniz. Keyset sayfalamada 2.847. sayfanın başlangıç
 *  anahtarı BİLİNMEZ — oraya ancak 2.846 sayfayı sırayla geçerek
 *  ulaşılır. Yani keyset, "sonraki/önceki" düğmeleri ve sonsuz
 *  kaydırma için doğrudur; sayfa numarası veren bir arayüzle
 *  bağdaşmaz. Bu yüzden burada OFFSET korundu ama MALİYETİ
 *  ertelenmiş join ile düşürüldü. Sayfa numarasından vazgeçebilen
 *  bir arayüz yazıyorsanız keyset'e geçin — 61 ms de 0,6 ms olur.
 *
 *  Sıralama anahtarlarının nasıl seçildiği için bkz. sort_keys().
 */
function build_page_sql(string $whereSql, string $orderBy, string $orderDir, int $length, int $start): string
{
    $keys = sort_keys($orderBy);

    /* $orderBy beyaz listeden geldiği için (bkz. ajax.php) ve $orderDir
     * yalnızca 'ASC'/'DESC' olabildiği için buraya kullanıcı metni
     * ULAŞMAZ. $length ve $start %d ile biçimlendirilir — PHP değeri
     * ZORLA tam sayıya çevirir, metin geçemez.
     *
     * İç ve dış ORDER BY AYNI anahtar listesini kullanmak ZORUNDA:
     * dış sorgu sıralamayı tekrar yazmazsa join sonucu rastgele
     * sırada döner (join, alt sorgunun sırasını korumaz). */
    $innerOrder = implode(', ', array_map(
        static fn(string $k): string => sprintf('`%s` %s', $k, $orderDir),
        $keys
    ));

    $outerOrder = implode(', ', array_map(
        static fn(string $k): string => sprintf('o.`%s` %s', $k, $orderDir),
        $keys
    ));

    return sprintf(
        'SELECT o.id, o.order_number, o.customer_name, o.product, o.category, o.amount, o.status, o.order_date
           FROM orders o
           JOIN (SELECT id FROM orders%s ORDER BY %s LIMIT %d OFFSET %d) k ON k.id = o.id
          ORDER BY %s',
        $whereSql,
        $innerOrder,
        $length,
        $start,
        $outerOrder
    );
}

/**
 * Bir sütuna göre sıralarken kullanılacak TAM anahtar listesi.
 * ---------------------------------------------------------------------
 *  NEDEN HER SÜTUNA AYNI "…, id" EKLENMEZ?
 *  Sayfalamanın KARARLI olması için ORDER BY'ın satırları tekilleştiren
 *  bir anahtarla bitmesi gerekir. Aksi hâlde eşit değerli satırların
 *  sırası garanti değildir ve sayfa 2'ye geçtiğinizde sayfa 1'de
 *  gördüğünüz bir satırı TEKRAR görebilir (ya da bir satırı hiç
 *  görmeyebilirsiniz). Bu tabloda somut: aynı (status, order_date)
 *  çiftini paylaşan 3.645 grup var.
 *
 *  Ama körlemesine ", id" eklemek İNDEKSİ BOZAR. InnoDB'de her ikincil
 *  indeksin sonuna birincil anahtar ZATEN eklenir; yani
 *  idx_orders_customer aslında (customer_name, id)'dir ve
 *  "ORDER BY customer_name, id" o indeksin doğrudan bir önekidir.
 *  Fakat idx_orders_status_date (status, order_date, +id)'dir —
 *  "ORDER BY status, id" bu indeksin ÖNEKİ DEĞİLDİR, çünkü aradaki
 *  order_date atlanmıştır. ÖLÇÜLDÜ:
 *
 *      ORDER BY status, id             → 64,95 ms  (Using filesort)
 *      ORDER BY status, order_date, id →  0,93 ms  (Using index)
 *
 *  Aynı tuzak ters yönde order_number'da: sütun zaten UNIQUE olduğu
 *  için tekilleştiricidir, ", id" eklemek HİÇBİR ŞEY kazandırmaz ama
 *  optimizasyonu bozar. ÖLÇÜLDÜ:
 *
 *      ORDER BY order_number, id → 127,76 ms  (Using filesort)
 *      ORDER BY order_number     →   0,84 ms  (Using index)
 *
 *  Yani doğru kural şudur: KARARLILIK İÇİN GEREKEN EN KISA ANAHTAR
 *  LİSTESİNİ, O SÜTUNA HİZMET EDEN İNDEKSİN KENDİ SIRASINI İZLEYEREK
 *  KUR. Şemaya yeni bir sıralanabilir sütun eklerseniz buraya da bir
 *  satır eklemeniz gerekir — ve o satırı EXPLAIN ile doğrulayın.
 *
 * @return list<string>
 */
function sort_keys(string $column): array
{
    return match ($column) {
        // Birincil anahtar: kendisi tekilleştirici, ek anahtar gereksiz.
        'id' => ['id'],

        // UNIQUE indeks: kendisi tekilleştirici (ölçüldü: tabloda
        // tekrar eden order_number YOK). Ek anahtar filesort'a yol açar.
        'order_number' => ['order_number'],

        // Bileşik indeksin KENDİ sırası izlenir: (status, order_date)
        // + örtük id. Sadece "status, id" yazmak indeksi devre dışı bırakır.
        'status' => ['status', 'order_date', 'id'],

        /* Tek sütunlu indeksler: InnoDB birincil anahtarı örtük olarak
         * sona eklediği için "(sütun, id)" zaten indeksin önekidir. */
        default => [$column, 'id'],
    };
}


/* =====================================================================
 *  BÖLÜM 7 – BİÇİMLENDİRME
 * ================================================================== */

function format_money(string $value): string
{
    return number_format((float) $value, 2, ',', '.') . ' ₺';
}

function format_day(?string $value): string
{
    if (empty($value)) {
        return '-';
    }

    try {
        return (new DateTimeImmutable($value))->format('d.m.Y');
    } catch (Exception $e) {
        return (string) $value;
    }
}
