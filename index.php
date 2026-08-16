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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="author" content="Çılgın Yazılım - cilginyazilim.com">
    <meta name="description" content="Yüz binlerce satırlık veride sunucu taraflı DataTables performans örneği: doğru indeksleme, ertelenmiş join ile derin sayfalama, önbelleğe alınmış gerçek sayım, sorgu süresi ölçümü.">

    <meta name="csrf-token" content="<?= e($csrfToken) ?>">

    <title>Yüksek Hacimli DataTables Performansı | Çılgın Yazılım</title>

    <link rel="icon" type="image/png" href="assets/images/logo.png">

    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="assets/css/cilginyazilim.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>

<body class="cy-app">

    <div class="cy-topbar"></div>

    <div class="container-fluid cy-page py-4 py-lg-5">
        <div class="cy-card">

            <div class="cy-card__header">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <a class="cy-brand" href="https://cilginyazilim.com" target="_blank" rel="noopener">
                        <span class="cy-brand__mark">
                            <img src="assets/images/logo.png" alt="Çılgın Yazılım logosu">
                        </span>
                        <div>
                            <h1 class="cy-brand__title">Yüksek Hacimli DataTables</h1>
                            <p class="cy-brand__subtitle">
                                İndeksleme &middot; Ertelenmiş join &middot; Ölçülebilir performans &middot; cilginyazilim.com
                            </p>
                        </div>
                    </a>

                    <span class="cy-badge cy-badge--glass">
                        Tabloda <strong id="total_records"><?= number_format($totalRows, 0, ',', '.') ?></strong> sipariş
                    </span>
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
                     updatePerfBar()). Performansı "hızlıdır" diye
                     anlatmak yerine SAYIYI GÖSTERMEK, iddiayı
                     ölçülebilir kılar. -->
                <div id="perf_bar" class="cy-perf-bar mb-3">
                    <span class="cy-perf-bar__item">
                        Toplam sayım: <strong id="perf_count_total">-</strong> ms
                        <small class="text-muted">(gerçek COUNT, önbellekli)</small>
                    </span>
                    <span class="cy-perf-bar__item">
                        Filtreli sayım: <strong id="perf_count_filtered">-</strong> ms
                    </span>
                    <span class="cy-perf-bar__item">
                        Veri sorgusu: <strong id="perf_data">-</strong> ms
                    </span>
                    <span class="cy-perf-bar__item cy-perf-bar__item--total">
                        Toplam: <strong id="perf_total">-</strong> ms
                    </span>
                    <span class="cy-perf-bar__item" id="perf_search_wrap" hidden>
                        Arama yolu: <strong id="perf_search">-</strong>
                    </span>
                </div>

                <!-- ---------- Araç çubuğu ---------- -->
                <div class="cy-toolbar">
                    <input type="search" id="search_input" class="form-control cy-toolbar__search"
                           placeholder="Sipariş no, müşteri, ürün ara…" aria-label="Kayıtlarda ara">

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
                </div>

                <!-- Hata/uyarı kutusu. alert() yerine sayfa içi kutu:
                     alert() tarayıcıyı kilitler ve art arda gelen
                     hatalarda (hız sınırı senaryosu) kullanıcı üst üste
                     pencere kapatmak zorunda kalır. -->
                <div id="cy_notice" class="alert" role="alert" hidden></div>

                <div class="table-responsive">
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
                <span>Sunucu taraflı DataTables &middot; İndeksli sorgular &middot; CSRF korumalı AJAX</span>
                <span>PHP <?= e(PHP_VERSION) ?></span>
            </div>
        </div>

        <div class="cy-footer-note mt-4">
            <p class="mb-1">
                Bu açık kaynak örnek, <a href="https://cilginyazilim.com" target="_blank" rel="noopener">cilginyazilim.com</a>
                tarafından geliştirilmiştir. MIT lisanslıdır.
            </p>
            <p class="mb-0">
                Kaynak kod:
                <a href="https://github.com/CilginYazilim/datatable-performance"
                   target="_blank" rel="noopener">github.com/CilginYazilim/datatable-performance</a>
            </p>
        </div>
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
