<?php
/**
 * =====================================================================
 *  AJAX UÇ NOKTASI (Endpoint)
 *  cilginyazilim.com – Yüksek Hacimli Veride Sunucu Taraflı DataTables
 * ---------------------------------------------------------------------
 *  Bu proje salt okunurdur (CRUD yok) — konu VERİ GÖSTERİMİNİN
 *  PERFORMANSIDIR, ekleme/silme değil. Tek uç nokta: action=list.
 * =====================================================================
 */

declare(strict_types=1);

define('CY_APP', true);

require __DIR__ . '/config.php';
require __DIR__ . '/function.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Yalnızca POST istekleri kabul edilir.', 405);
}

try {
    handle_list($db);
} catch (PDOException $e) {
    error_log('[PERF] Veritabani hatasi: ' . $e->getMessage());
    json_error(APP_DEBUG ? 'Veritabanı hatası: ' . $e->getMessage() : 'Beklenmeyen bir veritabanı hatası oluştu.', 500);
} catch (Throwable $e) {
    error_log('[PERF] Hata: ' . $e->getMessage());
    json_error(APP_DEBUG ? 'Hata: ' . $e->getMessage() : 'Beklenmeyen bir hata oluştu.', 500);
}


/* =====================================================================
 *  LİSTELEME — bu projenin ASIL konusu
 * =====================================================================
 *  DÖRT PERFORMANS TEKNİĞİ BİR ARADA:
 *
 *    1. SAYIM: GERÇEK COUNT(*) — ama dosyada önbelleğe alınmış
 *       (47 ms → 0,12 ms). Sayfalama artık TAHMİNE değil, kesin bir
 *       sayıya dayanıyor; tahmin yalnızca karşılaştırma amacıyla
 *       yanıta ekleniyor (bkz. count_cached(), function.php).
 *    2. ARAMA YÖNLENDİRME: sipariş numarası tam/önek eşleşmesi
 *       indeksli hızlı yola gider (429 ms → 1 ms); genel metin
 *       araması bilinçli olarak tam tarama kalır ve öyle etiketlenir
 *       (bkz. classify_search(), function.php).
 *    3. ERTELENMİŞ JOIN: derin sayfalamada önce yalnızca id'ler
 *       kapsayıcı indeksten okunur (253 ms → 61 ms; bkz.
 *       build_page_sql(), function.php).
 *    4. VERİ SORGUSU: SELECT * DEĞİL, yalnızca gösterilecek 8 sütun;
 *       ORDER BY yalnızca İNDEKSLİ bir beyaz listeden seçilir ve her
 *       zaman id ile kararlı hâle getirilir.
 *
 *  Her adımın süresi microtime() ile ölçülür ve yanıta eklenir;
 *  arayüzdeki performans rozeti bu değerleri gösterir — performans
 *  burada SOYUT bir iddia değil, GÖRÜLEBİLİR bir sayıdır.
 * ------------------------------------------------------------------ */
function handle_list(PDO $db): void
{
    require_csrf();

    /* Hız sınırı CSRF'den SONRA: sayaç yalnızca gerçekten bu
     * uygulamadan gelen istekleri saysın. CSRF'den önce olsaydı,
     * token'ı olmayan bir saldırgan sayacı doldurup MEŞRU
     * kullanıcıyı kilitleyebilirdi (sınırın kendisi bir saldırı
     * aracına dönerdi). */
    rate_limit('list', RATE_LIMIT_LIST, RATE_LIMIT_WINDOW);

    /* Sütun sırası: 0=# 1=Sipariş No 2=Müşteri 3=Ürün 4=Kategori
     * 5=Tutar 6=Durum 7=Tarih. Hepsi VERİTABANI SÜTUNUDUR ve
     * hepsinin bir indeksi VARDIR (bkz. cy_datatable.sql) — indekssiz
     * bir sütunu sıralanabilir yapmak, her tıklamada 100.000 satırlık
     * bir filesort demektir (ölçüldü: product sütunu, 253,61 ms). */
    $sortableColumns = [
        0 => 'id', 1 => 'order_number', 2 => 'customer_name', 3 => 'product',
        4 => 'category', 5 => 'amount', 6 => 'status', 7 => 'order_date',
    ];

    /* draw: DataTables'ın YARIŞ KORUMASIDIR, süs değil. İstemci her
     * çizimde bu sayacı bir artırır; gelen yanıtın draw'ı mevcut
     * sayaçtan KÜÇÜKSE yanıt SESSİZCE ATILIR (DataTables 1.13.6
     * kaynağında: "if (+r < t.iDraw) return"). Kullanıcı hızlıca
     * sayfa değiştirdiğinde geç gelen eski yanıtın yenisinin üstüne
     * binmesini bu engeller. Sunucunun tek görevi değeri OLDUĞU GİBİ
     * geri yansıtmaktır — bu yüzden burada doğrulanmaz, yalnızca tam
     * sayıya çevrilir (metin enjeksiyonuna karşı). */
    $draw   = (int) ($_POST['draw'] ?? 1);
    $start  = max(0, (int) ($_POST['start'] ?? 0));
    $length = (int) ($_POST['length'] ?? 25);
    $search = trim((string) ($_POST['search']['value'] ?? ''));
    $category = trim((string) ($_POST['category_filter'] ?? ''));
    $status   = trim((string) ($_POST['status_filter'] ?? ''));

    $orderColumn    = (int) ($_POST['order'][0]['column'] ?? 7);
    $orderDirection = strtolower((string) ($_POST['order'][0]['dir'] ?? 'desc'));

    // BEYAZ LİSTE: sütun adı prepared statement ile bind edilemez;
    // kullanıcıdan gelen sayıyı BİZ bir sütun adına çeviririz.
    // Bilinmeyen bir sayı gelirse güvenli varsayılana düşer.
    $orderBy  = $sortableColumns[$orderColumn] ?? 'order_date';
    $orderDir = ($orderDirection === 'asc') ? 'ASC' : 'DESC';

    $length = $length > 0 ? min($length, MAX_PAGE_LENGTH) : MAX_PAGE_LENGTH;

    /* --- ORTAK WHERE KOŞULUNU KUR ----------------------------------
     * Hem sayım hem veri sorgusu AYNI koşulu kullanır; iki ayrı
     * yerde yazılsaydı biri güncellenip diğeri unutulabilirdi. */
    $where  = [];
    $params = [];
    $searchMode  = 'none';
    $searchLabel = 'arama yok';

    if ($search !== '') {
        $route = classify_search($search);

        $where[]     = $route['where'];
        $params      = array_merge($params, $route['params']);
        $searchMode  = $route['mode'];
        $searchLabel = $route['label'];

        /* İKİNCİ, DAR HIZ SINIRI: yalnızca TAM TARAMA gerektiren
         * arama için. Pahalı olan iş bu (~175 ms); indeksli hızlı
         * yollar (exact/prefix, ~1 ms) bu sınıra takılmaz. Sınırı
         * "arama" değil "pahalı arama" üzerine koymak, meşru
         * kullanıcının sipariş no aramasını serbest bırakır. */
        if ($searchMode === 'scan') {
            rate_limit('search', RATE_LIMIT_SEARCH, RATE_LIMIT_WINDOW);
        }
    }

    if ($category !== '') {
        $where[] = 'category = :category';
        $params[':category'] = $category;
    }

    if ($status !== '' && array_key_exists($status, ORDER_STATUSES)) {
        $where[] = 'status = :status';
        $params[':status'] = $status;
    }

    $whereSql  = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
    $hasFilter = $where !== [];

    /* --- 1) TOPLAM (GERÇEK sayı, önbellekten) -----------------------
     * ESKİDEN: information_schema'dan TAHMİN okunuyordu ve filtre
     * yokken bu tahmin doğrudan recordsFiltered'a yazılıyordu —
     * yani sayfalamayı sürüyordu. Ölçülen sonuç: 675 satır
     * ERİŞİLEMEZ durumdaydı (bkz. function.php, BÖLÜM 4).
     * ŞİMDİ: sayfalama her zaman GERÇEK bir COUNT(*) sonucuna
     * dayanır; maliyeti önbellek karşılar. */
    $timings = [];
    $t0 = microtime(true);
    $total = count_cached($db, 'orders|all', 'SELECT COUNT(*) FROM orders', [], COUNT_CACHE_TTL);
    $recordsTotal = $total['count'];
    $timings['count_total_ms'] = round((microtime(true) - $t0) * 1000, 2);

    /* Tahmini sayım — SAYFALAMADA KULLANILMAZ, yalnızca rozette
     * gerçek sayının yanında gösterilir. Süresi ayrıca ölçülür ki
     * "taramasız tahmin gerçekten ucuz mu?" sorusu ekranda
     * cevaplansın (ölçüldü: ~0,5 ms). Öğretici amaçla eklenmiş bu
     * sorguyu üretimde çıkarmak isterseniz burası ve rozetteki
     * karşılığı silinir; başka hiçbir yeri etkilemez. */
    $t0 = microtime(true);
    $estimatedTotal = estimate_total_rows($db);
    $timings['estimate_ms'] = round((microtime(true) - $t0) * 1000, 2);

    /* --- 2) FİLTRELİ SAYIM ------------------------------------------
     * Filtre yoksa "filtrelenmiş" sayı zaten toplamla AYNIDIR;
     * ikinci bir COUNT(*) çalıştırmak tamamen gereksizdir. Bu
     * optimizasyon DOĞRUYDU ve KORUNDU — hatalı olan, o sayının
     * TAHMİNİ olmasıydı; artık gerçek sayı.
     *
     * Filtre VARSA sayım yine önbelleğe alınır. Neden önemli:
     * bir arama sonucunda 40 sayfa geziyorsanız, eski kodda her
     * sayfa 175 ms'lik bir COUNT(*) daha ödüyordu — aynı sayıyı
     * 40 kez yeniden hesaplamak için. İmza, filtrenin kendisinden
     * türetilir; farklı filtre farklı kutu kullanır. */
    $t0 = microtime(true);
    $countCached = false;

    if ($hasFilter) {
        $signature = 'orders|' . $whereSql . '|' . json_encode($params, JSON_UNESCAPED_UNICODE);
        $filtered = count_cached($db, $signature, "SELECT COUNT(*) FROM orders$whereSql", $params, COUNT_CACHE_TTL);
        $recordsFiltered = $filtered['count'];
        $countCached = $filtered['cached'];
    } else {
        $recordsFiltered = $recordsTotal;
        $countCached = $total['cached'];
    }

    $timings['count_filtered_ms'] = round((microtime(true) - $t0) * 1000, 2);

    /* --- 3) ASIL VERİ (ertelenmiş join) ------------------------------
     * SELECT * KULLANILMAZ: yalnızca ekranda gösterilecek 8 sütun
     * çekilir. Sorgunun kendisi ve gerekçesi build_page_sql()
     * içinde ayrıntılı açıklanmıştır. */
    $t0 = microtime(true);

    $stmt = $db->prepare(build_page_sql($whereSql, $orderBy, $orderDir, $length, $start));
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $timings['data_query_ms'] = round((microtime(true) - $t0) * 1000, 2);
    $timings['total_ms'] = round(array_sum($timings), 2);

    /* --- SATIRLARI TABLO BİÇİMİNE ÇEVİR -----------------------------
     * e() SUNUCUDA uygulanır: veritabanından gelen her metin, HTML'e
     * girmeden önce kaçışlanır. Böylece <script> içeren bir müşteri
     * adı istemciye ham HTML olarak HİÇ ULAŞMAZ (ölçüldü: yanıtta
     * &lt;script&gt; dönüyor). */
    $data = [];

    foreach ($rows as $row) {
        $meta = ORDER_STATUSES[$row['status']] ?? ['label' => $row['status'], 'css' => 'secondary'];

        $data[] = [
            (int) $row['id'],
            e($row['order_number']),
            e($row['customer_name']),
            e($row['product']),
            e($row['category']),
            '<span class="cy-nowrap">' . e(format_money($row['amount'])) . '</span>',
            '<span class="badge text-bg-' . e($meta['css']) . '">' . e($meta['label']) . '</span>',
            '<span class="cy-nowrap">' . e(format_day($row['order_date'])) . '</span>',
        ];
    }

    json_response([
        'draw'            => $draw,
        'recordsTotal'    => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data'            => $data,
        'timings'         => $timings,

        /* Öğretici ek alanlar — DataTables bunları kullanmaz, ekrandaki
         * rozet kullanır. Amaç: "hızlı ama yaklaşık" ile "kesin ama
         * önbellekli" arasındaki farkı ve hangi arama yolunun
         * seçildiğini GÖRÜNÜR kılmak. Bu deponun konusu tam olarak bu. */
        'meta' => [
            'estimated_total' => $estimatedTotal,
            'estimate_drift'  => $recordsTotal - $estimatedTotal,
            'count_cached'    => $countCached,
            'search_mode'     => $searchMode,
            'search_label'    => $searchLabel,
        ],
    ]);
}
