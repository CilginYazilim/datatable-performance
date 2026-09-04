<?php
/**
 * =====================================================================
 *  YAPILANDIRMA DOSYASI
 *  cilginyazilim.com – Yüksek Hacimli Veride Sunucu Taraflı DataTables
 * =====================================================================
 */

declare(strict_types=1);

/* ---------------------------------------------------------------------
 *  .env DESTEĞİ
 * ---------------------------------------------------------------------
 *  Veritabanı bilgileri bu dosyanın İÇİNDE durmak zorunda değil.
 *  Depo kökündeki ".env" dosyasına yazarsanız buradaki varsayılanlar
 *  devreye girmez — ve ".env" .gitignore içinde olduğu için parolanız
 *  depoya hiç girmez.
 *
 *  NEDEN AYRI BİR DOSYA?
 *  config.php DEPODA durur ve her dağıtımda depodaki sürümle
 *  DEĞİŞTİRİLİR; içine elle yazdığınız parola bir sonraki deploy'da
 *  silinir. .env ise deploy'un dokunmadığı bir dosyadır: bir kez
 *  oluşturursunuz, kalıcıdır.
 *
 *  DEĞER ARAMA SIRASI
 *      1. config.local.php içinde define() edilmişse o kazanır
 *         (bu dosyada varsa; aşağıdaki "! defined()" kontrolleri)
 *      2. .env dosyası
 *      3. Sunucunun gerçek ortam değişkeni (Apache SetEnv, systemd…)
 *      4. Bu dosyadaki varsayılan
 *
 *  cy_env() bilerek getenv() ile AYNI şeyi döndürür (değer ya da
 *  false). Böylece aşağıdaki satırlar olduğu gibi çalışmaya devam
 *  eder; "?:" ve "!== false" kalıplarının hiçbiri değişmedi.
 * ------------------------------------------------------------------ */
if (! function_exists('cy_env')) {
    /**
     * .env dosyasından (yoksa ortamdan) bir değer okur.
     *
     * @return string|false Değer yoksa false — getenv() ile aynı sözleşme.
     */
    function cy_env(string $key): string|false
    {
        static $env = null;

        if ($env === null) {
            $env  = [];
            $file = dirname(__DIR__) . '/.env';

            if (is_file($file) && is_readable($file)) {
                /* IGNORE_NEW_LINES + SKIP_EMPTY_LINES: satır sonlarını ve
                 * boş satırları baştan eler; ayrıştırma sadeleşir. */
                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

                foreach ($lines as $line) {
                    $line = trim($line);

                    // Yorum satırı ya da "=" içermeyen satır atlanır.
                    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                        continue;
                    }

                    [$name, $value] = explode('=', $line, 2);

                    $name  = trim($name);
                    $value = trim($value);

                    /* Tırnak içindeki değerlerden tırnakları at:
                     * DB_PASS="a b c" → a b c
                     * Tırnak zorunlu değildir; yalnızca boşluk içeren
                     * parolalar için gerekir. */
                    if (strlen($value) >= 2
                        && ($value[0] === '"' || $value[0] === "'")
                        && $value[strlen($value) - 1] === $value[0]
                    ) {
                        $value = substr($value, 1, -1);
                    }

                    if ($name !== '') {
                        $env[$name] = $value;
                    }
                }
            }
        }

        // .env'de varsa o; yoksa sunucunun gerçek ortam değişkeni.
        return $env[$key] ?? getenv($key);
    }
}

/* ---------------------------------------------------------------------
 *  DOĞRUDAN ÇAĞRILMAYA KARŞI İKİNCİ KATMAN
 * ---------------------------------------------------------------------
 *  ÖLÇÜLEN SORUN: /system/config.php isteği HTTP 200 dönüyordu (gövde
 *  0 bayt olduğu için "zararsız" görünüyordu). Zararsız değildi: bu
 *  dosya her çağrıldığında bir VERİTABANI BAĞLANTISI açıyor. Kimlik
 *  doğrulaması olmadan tetiklenebilen ve iş yapan her adres, ucuz bir
 *  hizmet dışı bırakma (DoS) kaldıracıdır.
 *
 *  Birinci katman system/.htaccess'tir. Bu PHP kontrolü NEDEN AYRICA
 *  gerekli: .htaccess yalnızca Apache'de ve yalnızca AllowOverride
 *  açıkken okunur. Aynı kod nginx'e taşındığında o dosya hiçbir şey
 *  yapmaz; buradaki kontrol her yerde çalışır.
 * ------------------------------------------------------------------ */
if (!defined('CY_APP')) {
    http_response_code(403);
    exit;
}


/* =====================================================================
 *  OTURUM GÜVENLİĞİ  (session_start'tan ÖNCE ayarlanmak ZORUNDA)
 * ---------------------------------------------------------------------
 *  ÖLÇÜLEN SORUN — iki ayrı eksik vardı:
 *
 *  1) Çerez bayrakları YOKTU. Yanıt aynen şuydu:
 *        Set-Cookie: PHPSESSID=omgj86j9vsrhniide1g6cgvi7b; path=/
 *     HttpOnly yok → sayfada bir XSS açığı çıksa oturum çerezi
 *     JavaScript'ten okunabilirdi. SameSite yok → çerez, başka bir
 *     sitenin tetiklediği isteklere de eklenirdi; CSRF'ye karşı
 *     tarayıcı seviyesindeki ilk savunma budur (token ikincidir).
 *
 *  2) OTURUM SABİTLEME (session fixation) GERÇEKTEN ÇALIŞIYORDU.
 *     Denendi (uydurma bir kimlikle):
 *        Cookie: PHPSESSID=cytest26664ffa2245a4e620ee
 *     Sunucu bu kimliği KABUL ETTİ (yeni bir Set-Cookie basmadı),
 *     o kimlikle oturum açtı, içinde CSRF token üretti ve
 *     /system/ajax.php isteği HTTP 200 döndü. Yani saldırgan,
 *     kurbana kendi seçtiği bir oturum kimliğini benimsetebilirdi.
 *     use_strict_mode=1, PHP'ye "sunucunun ÜRETMEDİĞİ bir kimliği
 *     kabul etme, yenisini üret" der ve bu vektörü kapatır.
 *
 *  NEDEN session_regenerate_id() TEK BAŞINA YETMEZDİ:
 *  Klasik tavsiye "girişten sonra kimliği yenile"dir; asıl risk,
 *  saldırganın önceden bildiği bir oturumun SONRADAN yetki
 *  kazanmasıdır. Bu projede giriş yoktur, dolayısıyla yenilenecek
 *  bir "yetki anı" da yoktur. Buradaki gerçek savunma
 *  use_strict_mode'dur.
 * ================================================================== */
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,   // JavaScript çerezi okuyamasın
        'samesite' => 'Lax',  // Başka sitelerin tetiklediği POST'lara çerez eklenmesin
        // HTTPS altındaysa çerez yalnızca HTTPS ile gitsin. Sabit 'true'
        // yazsaydık localhost'ta (http) oturum HİÇ kurulamazdı; bu yüzden
        // değer isteğe göre belirlenir.
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
    ]);

    session_start();
}

define('DB_HOST', cy_env('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', cy_env('DB_NAME') ?: 'cy_datatable');
define('DB_USER', cy_env('DB_USER') ?: 'root');
define('DB_PASS', cy_env('DB_PASS') !== false ? (string) cy_env('DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

/* ---------------------------------------------------------------------
 *  ZAMAN DİLİMİ
 * ---------------------------------------------------------------------
 *  ÖLÇÜLEN SORUN: php.ini'de date.timezone çoğu XAMPP kurulumunda
 *  sunucunun coğrafi diliminden farklıdır. Bu makinede PHP
 *  "Europe/Berlin", MySQL ise sistem dilimi (Europe/Istanbul)
 *  kullanıyordu; aynı anı anlatan iki satır BİR SAAT farklı görünüyordu:
 *
 *      worker günlüğü (PHP date)  : 14:03:17
 *      veritabanı  (MySQL NOW())  : 15:03:17
 *
 *  Bu depodaki zaman ARİTMETİĞİ bilinçli olarak SQL tarafında yapılır
 *  (NOW(), INTERVAL, TIMESTAMPDIFF), bu yüzden hesaplar zaten doğrudur.
 *  Kayan şey, PHP'nin ekrana/günlüğe bastığı saatti — ve demoyu
 *  deneyen biri için bu, "sistem yanlış çalışıyor" gibi görünür.
 *
 *  Çözüm: dilimi ORTAMA bırakmak yerine açıkça sabitliyoruz. Kendi
 *  sunucunuzda farklı bir dilim istiyorsanız APP_TIMEZONE ortam
 *  değişkenini tanımlamanız yeterlidir; kod değiştirmenize gerek yok.
 * ------------------------------------------------------------------ */
define('APP_TIMEZONE', cy_env('APP_TIMEZONE') ?: 'Europe/Istanbul');

// @ kullanmıyoruz: geçersiz bir dilim adı sessizce yutulmamalı.
if (in_array(APP_TIMEZONE, timezone_identifiers_list(), true)) {
    date_default_timezone_set(APP_TIMEZONE);
}

define('APP_DEBUG', true); // Canlıya alırken MUTLAKA false yapın.

error_reporting(APP_DEBUG ? E_ALL : 0);
ini_set('display_errors', APP_DEBUG ? '1' : '0');

define('ORDER_STATUSES', [
    'beklemede'      => ['label' => 'Beklemede',      'css' => 'secondary'],
    'hazirlaniyor'   => ['label' => 'Hazırlanıyor',   'css' => 'info'],
    'kargoda'        => ['label' => 'Kargoda',        'css' => 'primary'],
    'teslim_edildi'  => ['label' => 'Teslim Edildi',  'css' => 'success'],
    'iptal'          => ['label' => 'İptal',          'css' => 'danger'],
]);

/* ---------------------------------------------------------------------
 *  SAYFA UZUNLUĞU ÜST SINIRI
 * ---------------------------------------------------------------------
 *  length=999999 gibi bir istekle sunucuyu yormaya çalışan isteği
 *  keser. 500, arayüzdeki lengthMenu'nün en büyük değeridir — yani
 *  meşru kullanımın tavanı. Bunun üstünü kabul etmenin bir karşılığı
 *  yok, maliyeti ise doğrusal olarak artan bir sorgu.
 * ------------------------------------------------------------------ */
define('MAX_PAGE_LENGTH', 500);

/* ---------------------------------------------------------------------
 *  SAYIM ÖNBELLEĞİ ÖMRÜ (saniye)
 * ---------------------------------------------------------------------
 *  Gerçek COUNT(*) 47 ms sürüyor; her istekte çalıştırmak varsayılan
 *  ekranın toplam süresini iki katına çıkarırdı. Dosya önbelleğinden
 *  okumak 0,12 ms. TTL'in anlamı: "sayı en fazla bu kadar eski
 *  olabilir". Bu salt okunur bir vitrin olduğu için 60 sn cömert bir
 *  seçim; veri sık değişen bir sistemde düşürün (bkz. README'deki
 *  "tahmini sayım mı, önbelleğe alınmış gerçek sayım mı?" bölümü).
 * ------------------------------------------------------------------ */
define('COUNT_CACHE_TTL', 60);

/* ---------------------------------------------------------------------
 *  HIZ SINIRI — ölçülerek belirlendi, tahminle değil
 * ---------------------------------------------------------------------
 *  ÖLÇÜM (gerçek Chrome'da, gerçek arayüz sürülerek, XHR sayılarak):
 *  9,1 saniyelik KESİNTİSİZ yoğun kullanımda toplam 11 istek —
 *  1 sayfa açılışı, 3 sayfalama, 3 sıralama, 2 filtre değişimi ve
 *  2 arama. Kritik ayrıntı: o 2 arama isteği 14 TUŞ VURUŞUNDAN
 *  doğdu ("Zeynep" + "Kablosuz"), çünkü arama 350 ms geciktirmeli
 *  (bkz. SEARCH_DEBOUNCE_MS, assets/js/table.js). Geciktirme
 *  olmasaydı aynı yazma 14 istek ve 14 TAM TABLO TARAMASI
 *  üretecekti.
 *
 *  Bu tempo dakikaya vurulduğunda ~72 istek/dk eder — ama bu,
 *  bir insanın değil, 400 ms'de bir tıklayan bir betiğin temposudur;
 *  gerçek kullanımda sayı bunun çok altındadır.
 *
 *  Sınır 120/dakika: ölçülen MAKİNE temposunun ~1,7 katı, gerçekçi
 *  insan kullanımının birkaç katı. Bu pay bilinçlidir — sınırın
 *  amacı meşru kullanıcıyı yakalamak değil, kimliği doğrulanmamış
 *  bir istemcinin sunucu zamanını sınırsız yakmasını engellemektir.
 *
 *  İKİNCİ, DAR SINIR (arama): filtresiz sayfa artık 1 ms, ama
 *  LIKE '%...%' araması hâlâ ~175 ms sunucu zamanı yakıyor (tam
 *  tarama; bkz. README). Pahalı olan iş bu, o yüzden ayrıca ve
 *  daha dar sınırlanıyor: 30/dakika. Meşru kullanımda arama
 *  geciktirmeli olduğu için bu sınıra dakikada 30 kez ARAMA
 *  DURAKLAMASI yapmadan ulaşılamaz.
 * ------------------------------------------------------------------ */
define('RATE_LIMIT_LIST',   120); // dakikada toplam liste isteği
define('RATE_LIMIT_SEARCH',  30); // dakikada tam tarama gerektiren arama
define('RATE_LIMIT_WINDOW',  60);

try {
    $db = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET),
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');

    echo APP_DEBUG
        ? 'Veritabanı bağlantı hatası: ' . $e->getMessage()
        : 'Veritabanına bağlanılamadı. Lütfen daha sonra tekrar deneyin.';

    exit;
}
