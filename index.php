<?php
/**
 * =====================================================================
 *  ANA SAYFA (Sunum Katmanı)
 *  cilginyazilim.com – Yüksek Hacimli Veride Sunucu Taraflı DataTables
 * =====================================================================
 */

declare(strict_types=1);

define('CY_APP', true);

require __DIR__ . '/system/config.php';
require __DIR__ . '/system/function.php';

/* Satır içi <script> bloğuna izin veren tek kullanımlık anahtar.
 * CSP'de 'unsafe-inline' yazmak korumayı büyük ölçüde anlamsız
 * kılardı; nonce YALNIZCA bizim bastığımız bloğa izin verir ve her
 * istekte değişir (bkz. security_headers(), system/function.php). */
$scriptNonce = base64_encode(random_bytes(16));
security_headers($scriptNonce);

$csrfToken = csrf_token();

/* İlk ekran için GERÇEK sayı kullanılır — sayfalamayı süren sayı da
 * budur (bkz. count_cached(), system/function.php). */
$totalRows = count_cached($db, 'orders|all', 'SELECT COUNT(*) FROM orders', [], COUNT_CACHE_TTL)['count'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <!-- viewport-fit=cover: çentikli/yuvarlak köşeli telefonlarda
         güvenli alan (safe-area) değişkenlerinin dolması için gerekli;
         alt çubuk bu değişkenleri kullanıyor (bkz. style.css). -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="author" content="Çılgın Yazılım - cilginyazilim.com">

    <!-- Tarayıcının kendi arayüzünü (adres çubuğu) temaya uydurur.
         İki değer: kullanıcı hangi temadaysa o geçerli olur. -->
    <meta name="theme-color" content="#0b5cb5" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#070f1a" media="(prefers-color-scheme: dark)">
    <meta name="description" content="Yüz binlerce satırlık veride sunucu taraflı DataTables performans örneği: doğru indeksleme, ertelenmiş join ile derin sayfalama, önbelleğe alınmış gerçek sayım, sorgu süresi ölçümü.">

    <meta name="csrf-token" content="<?= e($csrfToken) ?>">

    <title>Yüksek Hacimli DataTables Performansı | Çılgın Yazılım</title>

    <link rel="icon" type="image/png" href="assets/images/logo.png">

    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="assets/css/cilginyazilim.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">

    <!-- ---------- TEMA ÖN YÜKLEMESİ ----------
         Bu blok BİLEREK <head> içinde ve BİLEREK satır içidir.
         Tema tercihi table.js içinde uygulansaydı, dosya <body>
         sonunda yüklendiği için sayfa önce AÇIK temayla boyanır,
         sonra koyuya atlardı (FOUC — flash of unstyled content).
         Buradaki üç satır, ilk boyamadan ÖNCE çalışır.
         CSP'yi delmez: nonce yalnızca bu bloğa izin verir. -->
    <script nonce="<?= e($scriptNonce) ?>">
        (function () {
            try {
                var t = localStorage.getItem('cy-theme');

                if (t === 'dark' || t === 'light') {
                    document.documentElement.setAttribute('data-cy-theme', t);
                }
            } catch (e) { /* gizli sekmede localStorage erişimi hata verebilir */ }
        })();
    </script>
</head>

<body class="cy-app">

    <div class="cy-topbar"></div>

    <div class="container-fluid cy-page py-4 py-lg-5">
        <div class="cy-card">

            <div class="cy-card__header">
                <div class="cy-header-row">
                    <a class="cy-brand" href="https://cilginyazilim.com" target="_blank" rel="noopener">
                        <span class="cy-brand__mark">
                            <img src="assets/images/logo.png" alt="Çılgın Yazılım logosu">
                        </span>
                        <div>
                            <h1 class="cy-brand__title">Yüksek Hacimli DataTables</h1>
                            <p class="cy-brand__subtitle">
                                İndeksleme &middot; Ertelenmiş join &middot; Ölçülebilir performans
                            </p>
                        </div>
                    </a>

                    <div class="cy-header-row__side">
                        <span class="cy-badge cy-badge--glass">
                            Tabloda <strong id="total_records"><?= number_format($totalRows, 0, ',', '.') ?></strong> sipariş
                        </span>

                        <!-- Tema düğmesi. aria-pressed yok çünkü bu iki
                             durumlu bir anahtar değil; üç durumlu bir
                             döngüdür (sistem → açık → koyu). Durum
                             aria-label ile duyurulur, table.js günceller. -->
                        <button type="button" id="theme_toggle" class="cy-theme-toggle"
                                title="Temayı değiştir" aria-label="Temayı değiştir">
                            <span class="cy-theme-toggle__icon" aria-hidden="true">◐</span>
                            <span class="cy-theme-toggle__text">Sistem</span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="cy-card__body">

                <?php if ($totalRows === 0): ?>
                    <div class="alert alert-warning" role="alert">
                        Tabloda henüz veri yok. Örnek veri üretmek için terminalde:
                        <code>php seed.php 100000</code> (proje kökünde) çalıştırın.
                    </div>
                <?php endif; ?>

                <!-- ---------- Sorgu performansı rozeti ----------
                     Her istekte YENİDEN yazılır (bkz. table.js
                     updateBadges()). Performansı "hızlıdır" diye
                     anlatmak yerine SAYIYI GÖSTERMEK, iddiayı
                     ölçülebilir kılar.

                     MOBİLDE: bu şerit telefonda dört satır yer
                     kaplıyordu ve asıl içerik olan tabloyu ekranın
                     dışına itiyordu. Artık <details> içinde —
                     masaüstünde açık, telefonda kapalı başlar
                     (bkz. style.css, MOBİL bölümü). Özet satırı
                     kapalıyken bile TOPLAM SÜREYİ gösterir, yani
                     bilgi kaybolmaz. -->
                <details id="perf_panel" class="cy-perf" open>
                    <summary class="cy-perf__summary">
                        <span class="cy-perf__summary-label">Sorgu performansı</span>
                        <span class="cy-perf__summary-value"><strong id="perf_total_mini">-</strong> ms</span>
                    </summary>

                    <div id="perf_bar" class="cy-perf-bar" aria-live="polite">
                        <span class="cy-perf-bar__item">
                            <span class="cy-perf-bar__label">Toplam sayım</span>
                            <span class="cy-perf-bar__value"><strong id="perf_count_total">-</strong> ms</span>
                        </span>
                        <span class="cy-perf-bar__item">
                            <span class="cy-perf-bar__label">Filtreli sayım</span>
                            <span class="cy-perf-bar__value"><strong id="perf_count_filtered">-</strong> ms</span>
                        </span>
                        <span class="cy-perf-bar__item">
                            <span class="cy-perf-bar__label">Veri sorgusu</span>
                            <span class="cy-perf-bar__value"><strong id="perf_data">-</strong> ms</span>
                        </span>
                        <span class="cy-perf-bar__item cy-perf-bar__item--total">
                            <span class="cy-perf-bar__label">Toplam</span>
                            <span class="cy-perf-bar__value"><strong id="perf_total">-</strong> ms</span>
                        </span>

                        <!-- Sayımın önbellekten mi geldiği. Yanıtta
                             meta.count_cached olarak ZATEN geliyordu
                             ama hiçbir yerde gösterilmiyordu; sayımın
                             47 ms mi 0,12 ms mi olduğunu AÇIKLAYAN
                             tek bilgi budur. -->
                        <span class="cy-perf-bar__item cy-perf-bar__item--wide" id="perf_cache_wrap">
                            <span class="cy-perf-bar__label">Sayım kaynağı</span>
                            <span class="cy-perf-bar__value"><strong id="perf_cache">-</strong></span>
                        </span>

                        <span class="cy-perf-bar__item cy-perf-bar__item--wide" id="perf_search_wrap" hidden>
                            <span class="cy-perf-bar__label">Arama yolu</span>
                            <span class="cy-perf-bar__value"><strong id="perf_search">-</strong></span>
                        </span>
                    </div>
                </details>

                <!-- ---------- Araç çubuğu ----------
                     ESKİDEN: tek satır flex + overflow-x:auto idi.
                     Telefonda bu, kullanıcıya GÖRÜNMEYEN bir yatay
                     kaydırma bırakıyordu: kategori ve durum kutuları
                     ekranın sağında, hiçbir işaret olmadan
                     duruyordu. Artık ızgara (grid) — dar ekranda
                     alt alta, geniş ekranda yan yana dizilir. -->
                <div class="cy-toolbar">
                    <div class="cy-toolbar__field cy-toolbar__field--search">
                        <input type="search" id="search_input" class="form-control cy-toolbar__search"
                               placeholder="Sipariş no, müşteri, ürün ara…" aria-label="Kayıtlarda ara"
                               autocomplete="off" enterkeyhint="search">

                        <!-- type="search" kutusundaki yerleşik temizleme
                             çarpısı yalnızca WebKit'te var ve dokunmatikte
                             çok küçük. Bu düğme her tarayıcıda çalışır ve
                             44px'lik dokunma hedefi kuralına uyar. -->
                        <button type="button" id="search_clear" class="cy-toolbar__clear"
                                aria-label="Aramayı temizle" hidden>&times;</button>
                    </div>

                    <select id="category_filter" class="form-select cy-toolbar__select" aria-label="Kategoriye göre filtrele">
                        <option value="">Tüm kategoriler</option>
                        <option>Elektronik</option>
                        <option>Giyim</option>
                        <option>Ev &amp; Yaşam</option>
                        <option>Kitap</option>
                        <option>Spor</option>
                    </select>

                    <select id="status_filter" class="form-select cy-toolbar__select" aria-label="Duruma göre filtrele">
                        <option value="">Tüm durumlar</option>
                        <?php foreach (ORDER_STATUSES as $value => $meta): ?>
                            <option value="<?= e($value) ?>"><?= e($meta['label']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <!-- TARİH ARALIĞI: bu şemadaki en ucuz filtre.
                         idx_orders_date (order_date, id) üzerinde
                         doğrudan aralık taraması açar ve varsayılan
                         sıralama da order_date olduğu için MySQL aynı
                         indeksi hem filtre hem sıralama için kullanır. -->
                    <div class="cy-toolbar__field cy-toolbar__field--date">
                        <label class="cy-toolbar__date-label" for="date_from">Başlangıç</label>
                        <input type="date" id="date_from" class="form-control cy-toolbar__date"
                               aria-label="Başlangıç tarihi">
                    </div>

                    <div class="cy-toolbar__field cy-toolbar__field--date">
                        <label class="cy-toolbar__date-label" for="date_to">Bitiş</label>
                        <input type="date" id="date_to" class="form-control cy-toolbar__date"
                               aria-label="Bitiş tarihi">
                    </div>

                    <!-- MOBİL SIRALAMA DENETİMİ (yalnızca dar ekranda görünür)
                         Kart görünümünde <thead> gizlenir — ve onunla
                         birlikte TIKLANABİLİR SÜTUN BAŞLIKLARI da gider.
                         Sıralamayı telefonda tamamen kaybetmemek için
                         aynı işi yapan bu iki denetim eklendi; DataTables
                         ile çift yönlü eşitlenir (bkz. table.js). -->
                    <div class="cy-toolbar__field cy-toolbar__field--sort">
                        <select id="mobile_sort" class="form-select" aria-label="Sıralama sütunu">
                            <option value="0">Kayıt no</option>
                            <option value="1">Sipariş No</option>
                            <option value="2">Müşteri</option>
                            <option value="3">Ürün</option>
                            <option value="4">Kategori</option>
                            <option value="5">Tutar</option>
                            <option value="6">Durum</option>
                            <option value="7" selected>Tarih</option>
                        </select>

                        <button type="button" id="mobile_sort_dir" class="cy-sort-dir"
                                aria-label="Sıralama yönü: azalan">
                            <span aria-hidden="true">↓</span>
                        </button>
                    </div>

                    <button type="button" id="filters_reset" class="btn cy-btn cy-toolbar__reset" disabled>
                        Filtreleri temizle
                    </button>
                </div>

                <!-- Etkin filtrelerin özeti. Beş ayrı kutuya dağılmış
                     bir filtre kümesinde "neden 12 kayıt görüyorum?"
                     sorusunun cevabı tek bakışta görünmüyordu —
                     özellikle telefonda, kutular ekranın altında
                     kaldığında. Her çip tek tıkla o filtreyi kaldırır. -->
                <div id="filter_chips" class="cy-chips" hidden></div>

                <!-- Hata/uyarı kutusu. alert() yerine sayfa içi kutu:
                     alert() tarayıcıyı kilitler ve art arda gelen
                     hatalarda (hız sınırı senaryosu) kullanıcı üst üste
                     pencere kapatmak zorunda kalır. -->
                <div id="cy_notice" class="alert" role="alert" hidden></div>

                <!-- .cy-table-wrap: masaüstünde .table-responsive gibi
                     yatay kaydırma verir; telefonda kaydırma KAPANIR,
                     çünkü orada tablo satırları kart görünümüne geçer
                     (bkz. style.css "MOBİL KART GÖRÜNÜMÜ"). Sekiz
                     sütunlu bir tabloyu 360px'lik bir ekranda yatay
                     kaydırtmak, kullanıcıyı her satır için ekranı
                     sağa-sola sürüklemeye zorluyordu. -->
                <div class="table-responsive cy-table-wrap">
                    <table id="orders_table" class="table cy-table w-100">
                        <thead>
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">Sipariş No</th>
                                <th scope="col">Müşteri</th>
                                <th scope="col">Ürün</th>
                                <th scope="col">Kategori</th>
                                <th scope="col" class="text-end">Tutar</th>
                                <th scope="col">Durum</th>
                                <th scope="col">Tarih</th>
                            </tr>
                        </thead>
                    </table>
                </div>

                <div id="length_control_wrapper"></div>
            </div>

            <div class="cy-card__footer d-flex flex-wrap justify-content-between gap-2">
                <span>Sunucu taraflı DataTables &middot; İndeksli sorgular &middot; Ertelenmiş join &middot; CSRF korumalı AJAX</span>
                <span>PHP <?= e(PHP_VERSION) ?></span>
            </div>
        </div>

        <footer class="cy-footer-note mt-4">
            <p class="mb-2">
                Bu açık kaynak örnek, <a href="https://cilginyazilim.com" target="_blank" rel="noopener">cilginyazilim.com</a>
                tarafından geliştirilmiştir. MIT lisanslıdır.
            </p>

            <nav class="cy-footer-links" aria-label="Proje bağlantıları">
                <a href="https://github.com/CilginYazilim/datatable-performance" target="_blank" rel="noopener">
                    Kaynak kod (GitHub)
                </a>
                <a href="https://cilginyazilim.com/kutuphane/datatables-performans-optimizasyonu" target="_blank" rel="noopener">
                    Bu projenin yazısı
                </a>
                <a href="https://cilginyazilim.com/kutuphane" target="_blank" rel="noopener">
                    Örnek kodlar &amp; kütüphane
                </a>
            </nav>
        </footer>
    </div>

    <script src="assets/js/jquery-3.7.0.js"></script>
    <script src="assets/js/bootstrap.bundle.js"></script>
    <script src="assets/js/jquery.dataTables.min.js"></script>
    <script src="assets/js/dataTables.bootstrap5.min.js"></script>
    <script src="assets/js/table.js?v=<?= filemtime(__DIR__ . '/assets/js/table.js') ?>"></script>
    <script nonce="<?= e($scriptNonce) ?>">
        CyPerfTable.init({
            endpoint:  'system/ajax.php',
            csrfToken: <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE) ?>
        });
    </script>
</body>
</html>
