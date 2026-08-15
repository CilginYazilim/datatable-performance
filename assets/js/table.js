/* =====================================================================
 *  YÜKSEK HACİMLİ TABLO MOTORU
 *  cilginyazilim.com – Yüksek Hacimli Veride Sunucu Taraflı DataTables
 * ---------------------------------------------------------------------
 *  Bu dosya CRUD içermez (proje salt okunurdur). İki işi var:
 *    1) arama/filtre değerlerini sunucuya iletmek,
 *    2) her yanıttaki "timings" ve "meta" nesnelerini ekrandaki
 *       rozetlere yazmak — performans burada SOYUT bir iddia değil,
 *       HER İSTEKTE yeniden ölçülen bir sayıdır.
 * ================================================================== */

/* global jQuery */

var CyPerfTable = (function ($) {
    'use strict';

    var config = { endpoint: 'system/ajax.php', csrfToken: '' };
    var dataTable = null;
    var searchTimer = null;

    /* ------------------------------------------------------------------
     *  ARAMA GECİKTİRME (debounce) SÜRESİ
     * ------------------------------------------------------------------
     *  ÖLÇÜM: geciktirme OLMASAYDI "Zeynep" yazmak 6 tuş vuruşu = 6
     *  istek demekti ve her biri sunucuda TAM TABLO TARAMASI tetikliyordu.
     *  350 ms, yazma DURAKLAMASI başına 1 istek anlamına gelir; gerçek
     *  tarayıcıda ölçüldü: "Zeynep" yazmak 6 tuş vuruşuna karşılık
     *  1 istek üretiyor.
     *
     *  Neden 350 ve daha kısa değil? Ortalama yazma hızında ardışık iki
     *  tuş arası ~120-200 ms'dir; 200 ms'lik bir eşik kelimenin ortasında
     *  tetiklenirdi. Neden daha uzun değil? 350 ms'nin üstü kullanıcıya
     *  "takıldı" hissi verir.
     * --------------------------------------------------------------- */
    var SEARCH_DEBOUNCE_MS = 350;

    function setText(id, value) {
        var el = document.getElementById(id);

        if (el) { el.textContent = value; }
    }

    function formatTr(n) {
        return Number(n).toLocaleString('tr-TR');
    }

    /** Sunucudan gelen ölçümleri üstteki iki rozete yazar. */
    function updateBadges(json) {
        if (!json) { return; }

        var t = json.timings || {};
        var m = json.meta || {};

        setText('perf_count_total', t.count_total_ms);
        setText('perf_count_filtered', t.count_filtered_ms);
        setText('perf_data', t.data_query_ms);
        setText('perf_total', t.total_ms);

        /* Öğretici rozet: sayfalamayı süren GERÇEK sayı ile
         * information_schema TAHMİNİ yan yana. Aradaki fark, "tahmin
         * neden sayfalamaya giremez"in canlı kanıtıdır — filtresiz
         * görünümde bu kadar satır ERİŞİLEMEZ olurdu. */
        if (m.estimated_total !== undefined) {
            setText('truth_real', formatTr(json.recordsTotal));
            setText('truth_estimate', formatTr(m.estimated_total));
            setText('truth_estimate_ms', t.estimate_ms);
            setText('truth_drift', formatTr(m.estimate_drift));
        }

        // Hangi arama yolunun seçildiği: maliyet gizlenmesin.
        var wrap = document.getElementById('truth_search_wrap');

        if (wrap) {
            if (m.search_mode && m.search_mode !== 'none') {
                setText('truth_search', m.search_label);
                wrap.hidden = false;
            } else {
                wrap.hidden = true;
            }
        }

        setText('total_records', formatTr(json.recordsTotal));
    }

    function initTable() {
        dataTable = $('#orders_table').DataTable({
            processing: true,
            serverSide: true,
            order: [[7, 'desc']],
            pageLength: 25,
            lengthMenu: [[25, 50, 100, 250, 500], [25, 50, 100, 250, 500]],

            // 'f' yok: kendi arama kutumuz var. l/i/p tek alt çubukta
            // toplanır — excel-import-export / bulk-actions-table
            // projelerindeki aynı desenin tekrarı (bkz. oradaki
            // ".dataTables_length" satır bölünme hatası notu).
            dom: 'rt<"cy-bottom-bar"<"cy-bottom-bar__length"l><"cy-bottom-bar__info"i><"cy-bottom-bar__pagination"p>>',

            ajax: {
                url: config.endpoint,
                type: 'POST',
                data: function (d) {
                    d.csrf_token      = config.csrfToken;
                    d.category_filter = $('#category_filter').val() || '';
                    d.status_filter   = $('#status_filter').val() || '';
                },

                /* Hata dallanması artık DURUM KODUNA göre yapılıyor.
                 * Eskiden tek bir alert vardı ve mesajı "sorgu çok uzun
                 * sürmüş olabilir" diye TAHMİN ediyordu. Artık sunucu
                 * doğru kodu döndürdüğü için (403 CSRF, 429 hız sınırı;
                 * 419 Apache tarafından sessizce 500'e çevriliyordu,
                 * ölçüldü) gerçek sebebi söyleyebiliyoruz. */
                error: function (xhr) {
                    var body = {};

                    try { body = JSON.parse(xhr.responseText) || {}; } catch (err) { body = {}; }

                    var message = body.description || 'Kayıtlar yüklenirken bir hata oluştu.';

                    if (xhr.status === 429) {
                        message = body.description
                            || 'Çok fazla istek gönderdiniz. Lütfen biraz bekleyin.';
                    } else if (xhr.status === 403) {
                        message = 'Oturumunuz geçersiz. Sayfayı yenileyin.';
                    }

                    showNotice(message, xhr.status === 429 ? 'warning' : 'danger');
                }
            },

            columnDefs: [
                { targets: 0, className: 'cy-id', width: '70px' },
                { targets: 5, className: 'text-end' },
                { targets: [4, 6], className: 'text-center' }
            ],

            drawCallback: function (settings) {
                updateBadges(settings.json);
            },

            language: {
                emptyTable: 'Kayıt bulunamadı. Örnek veri üretmek için "php seed.php 100000" çalıştırın.',
                info: '_TOTAL_ kayıttan _START_ – _END_ arası gösteriliyor',
                infoEmpty: 'Gösterilecek kayıt yok',
                infoFiltered: '(toplam _MAX_ kayıt içinden filtrelendi)',
                lengthMenu: 'Sayfada _MENU_ kayıt göster',
                loadingRecords: 'Yükleniyor…',
                processing: 'İşleniyor…',
                zeroRecords: 'Aramanızla eşleşen kayıt bulunamadı.',
                paginate: { first: 'İlk', last: 'Son', next: 'Sonraki', previous: 'Önceki' },
                aria: {
                    sortAscending: ': artan sırada sıralamak için etkinleştir',
                    sortDescending: ': azalan sırada sıralamak için etkinleştir'
                }
            }
        });
    }

    /* alert() yerine sayfa içi uyarı: alert() tarayıcıyı KİLİTLER ve
     * arka arkaya gelen birkaç hatada kullanıcı üst üste pencere
     * kapatmak zorunda kalır. Hız sınırına takılmak tam olarak bu
     * senaryodur (art arda birkaç istek). */
    function showNotice(message, kind) {
        var box = document.getElementById('cy_notice');

        if (!box) { return; }

        box.className = 'alert alert-' + (kind || 'danger');
        box.textContent = message;
        box.hidden = false;

        clearTimeout(box._timer);
        box._timer = setTimeout(function () { box.hidden = true; }, 6000);
    }

    function bindEvents() {
        $('#search_input').on('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                /* .search() DataTables'ın kendi arama alanını doldurur;
                 * sunucuya search[value] olarak gider. .draw() çağrısı
                 * draw sayacını artırır — geç gelen eski yanıtların
                 * atılmasını sağlayan yarış koruması budur. */
                dataTable.search($('#search_input').val()).draw();
            }, SEARCH_DEBOUNCE_MS);
        });

        $('#category_filter, #status_filter').on('change', function () {
            dataTable.draw();
        });
    }

    function init(options) {
        $.extend(config, options || {});

        $(function () {
            initTable();
            bindEvents();
        });
    }

    return { init: init };

})(jQuery);
