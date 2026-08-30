/* =====================================================================
 *  YÜKSEK HACİMLİ TABLO MOTORU
 *  cilginyazilim.com – Yüksek Hacimli Veride Sunucu Taraflı DataTables
 * ---------------------------------------------------------------------
 *  Bu dosya CRUD içermez (proje salt okunurdur). Dört işi var:
 *    1) arama/filtre değerlerini sunucuya iletmek,
 *    2) her yanıttaki "timings" ve "meta" nesnelerini ekrandaki
 *       rozetlere yazmak — performans burada SOYUT bir iddia değil,
 *       HER İSTEKTE yeniden ölçülen bir sayıdır,
 *    3) etkin filtreleri çip olarak göstermek ve tek tıkla
 *       kaldırılabilir kılmak,
 *    4) dar ekranda tablo satırlarını KART görünümüne çevirecek
 *       etiketleri basmak (bkz. applyMobileLabels).
 * ================================================================== */

/* global jQuery */

var CyPerfTable = (function ($) {
    'use strict';

    var config = { endpoint: 'system/ajax.php', csrfToken: '' };
    var dataTable = null;
    var searchTimer = null;
    var columnLabels = [];

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

    /* Mobil kart görünümünün devreye girdiği eşik. style.css'teki
     * @media (max-width: 767.98px) ile AYNI olmak ZORUNDA: JS
     * etiketleri basar, CSS onları gösterir. İkisi ayrışırsa ya
     * etiketsiz kartlar ya da masaüstünde gereksiz DOM özniteliği
     * kalır. */
    var MOBILE_BREAKPOINT = 767.98;

    /* Tema döngüsü. İki durumlu bir anahtar DEĞİL, üç durumlu:
     * "sistem" gerçek bir seçenektir — kullanıcı telefonunu akşam
     * koyu temaya geçiriyorsa sayfa da geçmelidir. İki durumlu bir
     * anahtar, seçim yapıldıktan sonra bu davranışa GERİ DÖNMEYİ
     * imkânsız kılar. */
    var THEMES = [
        { key: 'system', icon: '◐', text: 'Sistem', aria: 'Tema: sistem ayarı. Değiştirmek için tıklayın.' },
        { key: 'light',  icon: '☀', text: 'Açık',   aria: 'Tema: açık. Değiştirmek için tıklayın.' },
        { key: 'dark',   icon: '☾', text: 'Koyu',   aria: 'Tema: koyu. Değiştirmek için tıklayın.' }
    ];

    var STATUS_LABELS = {};


    /* =================================================================
     *  KÜÇÜK YARDIMCILAR
     * ============================================================== */

    function setText(id, value) {
        var el = document.getElementById(id);

        if (el) { el.textContent = value; }
    }

    function formatTr(n) {
        return Number(n).toLocaleString('tr-TR');
    }

    /* Süreler sunucudan 4.96 gibi NOKTALI gelir (PHP round()).
     * Sayfanın geri kalanı Türkçe biçimde (4,96 / 100.000) yazıyor;
     * rozetteki sayıların İngilizce biçimde kalması, aynı ekranda iki
     * farklı sayı dili demekti. */
    function formatMs(n) {
        if (n === undefined || n === null || isNaN(n)) { return '-'; }

        return Number(n).toLocaleString('tr-TR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function isMobile() {
        return window.matchMedia('(max-width: ' + MOBILE_BREAKPOINT + 'px)').matches;
    }

    function val(id) {
        var el = document.getElementById(id);

        return el ? String(el.value || '').trim() : '';
    }

    /** ISO tarihi (2025-03-14) ekranda okunur biçime çevirir. */
    function formatDateTr(iso) {
        var parts = String(iso).split('-');

        return parts.length === 3 ? parts[2] + '.' + parts[1] + '.' + parts[0] : iso;
    }


    /* =================================================================
     *  PERFORMANS ROZETİ
     * ============================================================== */

    /** Sunucudan gelen ölçümleri performans rozetine yazar. */
    function updateBadges(json) {
        if (!json) { return; }

        var t = json.timings || {};
        var m = json.meta || {};

        setText('perf_count_total', formatMs(t.count_total_ms));
        setText('perf_count_filtered', formatMs(t.count_filtered_ms));
        setText('perf_data', formatMs(t.data_query_ms));
        setText('perf_total', formatMs(t.total_ms));

        // Panel kapalıyken (telefonda varsayılan) görünen tek sayı.
        setText('perf_total_mini', formatMs(t.total_ms));

        /* Sayımın önbellekten mi geldiği. Bu alan yanıtta ZATEN vardı
         * ama hiçbir yerde gösterilmiyordu — oysa "toplam sayım" 47 ms
         * mi 0,12 ms mi çıktığını AÇIKLAYAN tek bilgi budur. */
        setText('perf_cache', m.count_cached ? 'önbellek (TTL içinde)' : 'gerçek COUNT(*) çalıştı');

        var cacheWrap = document.getElementById('perf_cache_wrap');

        if (cacheWrap) {
            cacheWrap.classList.toggle('is-cached', !!m.count_cached);
        }

        // Hangi arama yolunun seçildiği: maliyet gizlenmesin (bkz.
        // classify_search(), system/function.php).
        var wrap = document.getElementById('perf_search_wrap');

        if (wrap) {
            if (m.search_mode && m.search_mode !== 'none') {
                setText('perf_search', m.search_label);
                wrap.hidden = false;
                wrap.classList.toggle('is-scan', m.search_mode === 'scan');
            } else {
                wrap.hidden = true;
                wrap.classList.remove('is-scan');
            }
        }

        setText('total_records', formatTr(json.recordsTotal));

        /* Sunucu geçersiz bir tarihi yok saymış ya da ters girilen
         * uçları takas etmiş olabilir. Kutuları sunucunun KABUL ETTİĞİ
         * değere çekiyoruz; aksi hâlde ekranda yazan filtre ile
         * uygulanan filtre farklı olurdu. */
        syncDateInputs(m);
    }

    function syncDateInputs(meta) {
        ['date_from', 'date_to'].forEach(function (id) {
            var el = document.getElementById(id);

            if (!el) { return; }

            var accepted = meta[id] || '';

            if (el.value !== accepted) { el.value = accepted; }
        });
    }


    /* =================================================================
     *  MOBİL KART GÖRÜNÜMÜ
     * -----------------------------------------------------------------
     *  ÖLÇÜLEN SORUN: 8 sütunlu tablo 360px genişliğinde bir telefonda
     *  ~980px yer istiyordu. .table-responsive bunu yatay kaydırmaya
     *  çeviriyordu ama kaydırma çubuğu dokunmatikte GÖRÜNMEZ: kullanıcı
     *  "Tutar" ve "Durum" sütunlarının var olduğunu bile bilmiyordu.
     *
     *  ÇÖZÜM: dar ekranda her <tr> bir KART, her <td> bir "etiket:
     *  değer" satırı olur. Etiketi CSS'in ::before'ı basar, ama CSS
     *  başlık metnini bilemez — bu yüzden her hücreye data-label
     *  özniteliğini BURADA yazıyoruz.
     *
     *  NEDEN SUNUCUDA DEĞİL? Aynı etiketi 500 satırın her hücresine
     *  yazmak, yanıt gövdesini gereksiz yere büyütürdü (8 sütun × 500
     *  satır = 4.000 tekrar). Etiketler zaten <thead>'de bir kez var;
     *  istemci onları oradan okur.
     * ============================================================== */

    function readColumnLabels() {
        columnLabels = $('#orders_table thead th').map(function () {
            /* "#" başlığı kart görünümünde tek başına anlamsız kalıyor;
             * kartın rozetine dönüştüğü için ona ayrı bir ad veriyoruz. */
            var text = $.trim($(this).text());

            return text === '#' ? 'Kayıt' : text;
        }).get();
    }

    function applyMobileLabels(row) {
        $('td', row).each(function (i) {
            if (columnLabels[i]) {
                this.setAttribute('data-label', columnLabels[i]);
            }
        });
    }


    /* =================================================================
     *  ETKİN FİLTRE ÇİPLERİ
     * -----------------------------------------------------------------
     *  Beş ayrı kutuya dağılmış bir filtre kümesinde "neden 12 kayıt
     *  görüyorum?" sorusunun cevabı tek bakışta görünmüyordu —
     *  özellikle telefonda, kutular ekranın altında kaldığında.
     * ============================================================== */

    function activeFilters() {
        var list = [];
        var search = val('search_input');
        var category = val('category_filter');
        var status = val('status_filter');
        var from = val('date_from');
        var to = val('date_to');

        if (search) { list.push({ id: 'search_input', label: 'Arama', value: search }); }
        if (category) { list.push({ id: 'category_filter', label: 'Kategori', value: category }); }
        if (status) { list.push({ id: 'status_filter', label: 'Durum', value: STATUS_LABELS[status] || status }); }
        if (from) { list.push({ id: 'date_from', label: 'Başlangıç', value: formatDateTr(from) }); }
        if (to) { list.push({ id: 'date_to', label: 'Bitiş', value: formatDateTr(to) }); }

        return list;
    }

    function renderChips() {
        var box = document.getElementById('filter_chips');
        var reset = document.getElementById('filters_reset');
        var list = activeFilters();

        if (reset) { reset.disabled = list.length === 0; }

        if (!box) { return; }

        if (list.length === 0) {
            box.hidden = true;
            box.textContent = '';

            return;
        }

        box.textContent = '';

        list.forEach(function (item) {
            /* textContent ile kuruluyor, innerHTML ile DEĞİL: buradaki
             * değer doğrudan kullanıcının arama kutusundan geliyor.
             * innerHTML kullanmak, kendi yazdığını kendine çalıştıran
             * bir XSS yolu açardı (self-XSS) — ve o kutuya bir bağlantı
             * ile önceden değer doldurmak mümkündür. */
            var chip = document.createElement('span');

            chip.className = 'cy-chip';

            var label = document.createElement('span');

            label.className = 'cy-chip__label';
            label.textContent = item.label + ': ';

            var value = document.createElement('span');

            value.className = 'cy-chip__value';
            value.textContent = item.value;

            var remove = document.createElement('button');

            remove.type = 'button';
            remove.className = 'cy-chip__remove';
            remove.setAttribute('data-target', item.id);
            remove.setAttribute('aria-label', item.label + ' filtresini kaldır');
            remove.textContent = '×';

            chip.appendChild(label);
            chip.appendChild(value);
            chip.appendChild(remove);
            box.appendChild(chip);
        });

        box.hidden = false;
    }


    /* =================================================================
     *  TEMA
     * ============================================================== */

    function storedTheme() {
        try {
            var t = localStorage.getItem('cy-theme');

            return (t === 'light' || t === 'dark') ? t : 'system';
        } catch (e) {
            return 'system';
        }
    }

    function applyTheme(key) {
        var root = document.documentElement;

        if (key === 'system') {
            root.removeAttribute('data-cy-theme');
        } else {
            root.setAttribute('data-cy-theme', key);
        }

        try {
            if (key === 'system') {
                localStorage.removeItem('cy-theme');
            } else {
                localStorage.setItem('cy-theme', key);
            }
        } catch (e) { /* gizli sekmede yazma engellenebilir; tema yine de uygulandı */ }

        var meta = THEMES.filter(function (t) { return t.key === key; })[0] || THEMES[0];
        var button = document.getElementById('theme_toggle');

        if (!button) { return; }

        var icon = button.querySelector('.cy-theme-toggle__icon');
        var text = button.querySelector('.cy-theme-toggle__text');

        if (icon) { icon.textContent = meta.icon; }
        if (text) { text.textContent = meta.text; }

        button.setAttribute('aria-label', meta.aria);
        button.setAttribute('title', meta.aria);
    }

    function cycleTheme() {
        var current = storedTheme();
        var index = 0;

        THEMES.forEach(function (t, i) {
            if (t.key === current) { index = i; }
        });

        applyTheme(THEMES[(index + 1) % THEMES.length].key);
    }


    /* =================================================================
     *  TABLO
     * ============================================================== */

    function initTable() {
        dataTable = $('#orders_table').DataTable({
            processing: true,
            serverSide: true,
            order: [[7, 'desc']],
            pageLength: 25,
            lengthMenu: [[25, 50, 100, 250, 500], [25, 50, 100, 250, 500]],

            /* autoWidth kapalı: DataTables her çizimde sütun
             * genişliklerini ölçüp <th>'lere satır içi stil yazıyor.
             * Kart görünümünde bu genişliklerin hiçbir karşılığı yok
             * (hücreler blok olur) ama ölçüm maliyeti ödeniyordu —
             * üstelik ekran döndürüldüğünde eski genişlikler kalıyordu. */
            autoWidth: false,

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
                    d.category_filter = val('category_filter');
                    d.status_filter   = val('status_filter');
                    d.date_from       = val('date_from');
                    d.date_to         = val('date_to');
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
                    } else if (xhr.status === 0) {
                        // Telefonda tünel/asansör senaryosu: istek hiç
                        // gitmedi. "Sunucu hatası" demek yanıltıcı olurdu.
                        message = 'Bağlantı kurulamadı. İnternet bağlantınızı kontrol edin.';
                    }

                    showNotice(message, xhr.status === 429 ? 'warning' : 'danger');
                }
            },

            columnDefs: [
                { targets: 0, className: 'cy-id', width: '70px' },
                { targets: 5, className: 'text-end' },
                { targets: [4, 6], className: 'text-center' }
            ],

            rowCallback: function (row) {
                applyMobileLabels(row);
            },

            drawCallback: function (settings) {
                updateBadges(settings.json);
                renderChips();
            },

            language: {
                emptyTable: 'Kayıt bulunamadı. Örnek veri üretmek için "php seed.php 100000" çalıştırın.',
                info: '_TOTAL_ kayıttan _START_ – _END_ arası gösteriliyor',
                infoEmpty: 'Gösterilecek kayıt yok',
                infoFiltered: '(toplam _MAX_ kayıt içinden filtrelendi)',
                lengthMenu: 'Sayfada _MENU_ kayıt göster',

                /* DataTables sayıları varsayılan olarak "100,000" diye
                 * yazıyordu — sayfanın geri kalanı "100.000" derken.
                 * Bu iki alan bilgi metnindeki ayraçları düzeltir. */
                thousands: '.',
                decimal: ',',

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

    /** Arama kutusunun temizleme düğmesini metin varlığına göre gösterir. */
    function syncSearchClear() {
        var button = document.getElementById('search_clear');

        if (button) { button.hidden = val('search_input') === ''; }
    }


    /* =================================================================
     *  OLAY BAĞLARI
     * ============================================================== */

    function bindEvents() {
        $('#search_input').on('input', function () {
            syncSearchClear();
            clearTimeout(searchTimer);

            searchTimer = setTimeout(function () {
                /* .search() DataTables'ın kendi arama alanını doldurur;
                 * sunucuya search[value] olarak gider. .draw() çağrısı
                 * draw sayacını artırır — geç gelen eski yanıtların
                 * atılmasını sağlayan yarış koruması budur. */
                dataTable.search(val('search_input')).draw();
            }, SEARCH_DEBOUNCE_MS);
        });

        /* Enter'a basıldığında geciktirmeyi BEKLETMEYİZ: kullanıcı
         * yazmayı bitirdiğini zaten söylemiştir. Mobil klavyedeki
         * "ara" tuşu da bu olayı üretir (enterkeyhint="search"). */
        $('#search_input').on('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                clearTimeout(searchTimer);
                dataTable.search(val('search_input')).draw();
            }
        });

        $('#search_clear').on('click', function () {
            $('#search_input').val('').trigger('focus');
            syncSearchClear();
            clearTimeout(searchTimer);
            dataTable.search('').draw();
        });

        $('#category_filter, #status_filter, #date_from, #date_to').on('change', function () {
            /* Filtre değişince 1. sayfaya dönülür. Aksi hâlde 40.
             * sayfadayken filtre daraltıldığında BOŞ bir sayfada
             * kalınırdı — kullanıcı bunu "sonuç yok" sanır. */
            dataTable.page(0).draw(false);
        });

        $('#filters_reset').on('click', function () {
            resetFilters();
        });

        // Çipteki × düğmesi: çipler her çizimde yeniden üretildiği
        // için olay, kalıcı olan kapsayıcıya bağlanır (delegasyon).
        $('#filter_chips').on('click', '.cy-chip__remove', function () {
            var target = this.getAttribute('data-target');

            $('#' + target).val('');

            if (target === 'search_input') {
                syncSearchClear();
                dataTable.search('');
            }

            dataTable.page(0).draw(false);
        });

        $('#theme_toggle').on('click', cycleTheme);

        /* --- MOBİL SIRALAMA -----------------------------------------
         * Kart görünümünde <thead> gizlendiği için sütun başlıklarına
         * tıklanamaz. Bu iki denetim aynı işi yapar. Çift yönlü
         * eşitleme ŞART: kullanıcı masaüstünde başlığa tıklayıp
         * pencereyi daralttığında, denetimlerin GERÇEK sıralamayı
         * göstermesi gerekir — yoksa arayüz yalan söyler. */
        $('#mobile_sort').on('change', function () {
            dataTable.order([parseInt(this.value, 10), currentSortDir()]).page(0).draw(false);
        });

        $('#mobile_sort_dir').on('click', function () {
            var next = currentSortDir() === 'asc' ? 'desc' : 'asc';

            dataTable.order([parseInt(val('mobile_sort'), 10), next]).page(0).draw(false);
        });

        $('#orders_table').on('order.dt', syncMobileSort);

        /* "/" ile arama kutusuna atla — masaüstünde yaygın bir kısayol.
         * Zaten bir kutuya yazılıyorsa devreye GİRMEZ, aksi hâlde
         * kullanıcı her eğik çizgi yazmak istediğinde odak zıplardı. */
        $(document).on('keydown', function (event) {
            if (event.key !== '/' || event.ctrlKey || event.altKey || event.metaKey) { return; }

            var tag = (event.target.tagName || '').toLowerCase();

            if (tag === 'input' || tag === 'select' || tag === 'textarea' || event.target.isContentEditable) {
                return;
            }

            event.preventDefault();
            $('#search_input').trigger('focus');
        });

        /* Sayfa değiştirildiğinde tablonun BAŞINA dön. Telefonda
         * sayfalama düğmeleri tablonun ALTINDA olduğu için, "Sonraki"ye
         * basan kullanıcı yeni sayfanın SONUNDA kalıyordu ve yukarı
         * kaydırmadan hiçbir yeni satır görmüyordu. */
        $('#orders_table').on('page.dt', function () {
            if (!isMobile()) { return; }

            var top = document.querySelector('.cy-table-wrap');

            if (top) { top.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        });
    }

    function resetFilters() {
        $('#search_input, #category_filter, #status_filter, #date_from, #date_to').val('');
        syncSearchClear();
        clearTimeout(searchTimer);
        dataTable.search('').page(0).draw(false);
    }

    /** DataTables'ın o anki sıralama yönü ('asc' | 'desc'). */
    function currentSortDir() {
        var order = dataTable ? dataTable.order() : [];

        return (order[0] && order[0][1]) ? order[0][1] : 'desc';
    }

    /** Mobil sıralama denetimlerini gerçek sıralamaya eşitler. */
    function syncMobileSort() {
        var order = dataTable ? dataTable.order() : [];

        if (!order[0]) { return; }

        var column = String(order[0][0]);
        var dir = order[0][1] === 'asc' ? 'asc' : 'desc';
        var select = document.getElementById('mobile_sort');
        var button = document.getElementById('mobile_sort_dir');

        if (select && select.value !== column) { select.value = column; }

        if (button) {
            button.firstElementChild.textContent = dir === 'asc' ? '↑' : '↓';
            button.setAttribute('aria-label', 'Sıralama yönü: ' + (dir === 'asc' ? 'artan' : 'azalan'));
        }
    }

    /** Durum kodlarının ekrandaki karşılıkları — çipler için gerekli. */
    function readStatusLabels() {
        $('#status_filter option').each(function () {
            if (this.value) { STATUS_LABELS[this.value] = $.trim($(this).text()); }
        });
    }

    /** Performans paneli telefonda kapalı, masaüstünde açık başlar. */
    function initPerfPanel() {
        var panel = document.getElementById('perf_panel');

        if (panel && isMobile()) { panel.open = false; }
    }

    function init(options) {
        $.extend(config, options || {});

        $(function () {
            applyTheme(storedTheme());
            readColumnLabels();
            readStatusLabels();
            initPerfPanel();
            initTable();
            bindEvents();
            syncSearchClear();
            syncMobileSort();
        });
    }

    return { init: init };

})(jQuery);
