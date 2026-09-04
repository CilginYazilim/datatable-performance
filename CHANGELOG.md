# Değişiklik Günlüğü

Bu dosyanın biçimi [Keep a Changelog](https://keepachangelog.com/tr/1.1.0/)
kalıbını izler ve proje [Semantic Versioning](https://semver.org/lang/tr/)
kurallarına uyar.

---

## [1.1.0] — 2026-09-04

### Eklendi

- **Veritabanı bilgileri artık `.env` dosyasından okunabiliyor.**
  Daha önce tek yol `system/config.php` dosyasını elle düzenlemekti — ve
  o dosya depoda durur: yazdığınız parola hem GitHub'a gider hem de ilk
  dağıtımda depodaki sürümle değiştirilerek kaybolur.

  Depo köküne `.env.example` eklendi; kopyalayıp `.env` yapmanız yeterli.
  `.env` zaten `.gitignore` içindeydi.

  Değer arama sırası: `.env` → sunucunun gerçek ortam değişkeni → bu
  dosyadaki varsayılan. (`config.local.php` destekleyen depolarda o hâlâ
  en önde gelir; eski kurulumlar olduğu gibi çalışır.)

  Uygulama kodu değişmedi: `cy_env()` yardımcısı bilerek `getenv()` ile
  aynı sözleşmeyi taşır (değer ya da `false`), böylece mevcut `?:` ve
  `!== false` kalıplarının hiçbirine dokunulmadı.

- **"Canlı Demo" bölümü eklendi.** Depoyu ilk açan kişinin ilk sorusu
  "bu ne yapıyor?" değil, "çalışırken görebilir miyim?" oluyor. README
  başlığının hemen altına, çalışan demoya / kaynak kütüphanesine / ZIP
  indirmeye giden üç düğme ve demoya bağlanan tıklanabilir bir önizleme
  görseli kondu — yeni depolarda (`scheduler-system`, `audit-log`)
  kullanılan kalıbın aynısı. Türkçe ve İngilizce README'lerin ikisine de
  eklendi.

### Değiştirildi

- **Zaman dilimi artık açıkça sabitleniyor.** `system/config.php` içinde
  `APP_TIMEZONE` (varsayılan `Europe/Istanbul`) tanımlanıp
  `date_default_timezone_set()` çağrılıyor; ortam değişkeniyle
  değiştirilebilir.

  **Ölçülen sorun:** XAMPP'ın `php.ini` dosyasındaki `date.timezone`,
  MySQL'in kullandığı sistem diliminden farklı olabiliyor. Test
  makinesinde PHP `Europe/Berlin`, MySQL ise `Europe/Istanbul`
  kullanıyordu ve aynı anı anlatan iki satır bir saat farklı görünüyordu:

  ```
  worker günlüğü (PHP date)  : 14:03:17
  veritabanı  (MySQL NOW())  : 15:03:17
  ```

  Zaman **aritmetiği** bu depoda bilinçli olarak SQL tarafında yapıldığı
  için (`NOW()`, `INTERVAL`, `TIMESTAMPDIFF`) hesaplar zaten doğruydu;
  kayan şey PHP'nin ekrana ve günlüğe bastığı saatti. Ama demoyu deneyen
  biri için bu, "sistem yanlış çalışıyor" gibi görünüyordu.

---

> Bu sürümden öncesi ayrı bir günlükte tutulmuyordu. Daha eski
> değişiklikler için depo geçmişine ve `README.md` içindeki sürüm
> notlarına bakabilirsiniz.
