# Panel Robat — سیستم جامع فروش سرویس اینترنت (IBSng + Reseller Panel + Telegram Bot)

این ریپازیتوری یک سیستم فروش/مدیریت سرویس اینترنت روی IBSng را پیاده می‌کند: پنل ریسلر (با کیف پول و
فیش پرداخت)، پنل ادمین، و ربات تلگرام برای مشتریان مستقیم (بدون واسطه ریسلر) — به‌همراه یک پنل کنترلی
ادمین داخل همان ربات تلگرام برای Export/Import دیتابیس و تنظیمات اتصال.

## معماری

برخلاف نسخه‌های قبلی این ریپو، اتصال به IBSng دیگر از طریق SSH Tunnel/Agent نیست. سرور IBSng این نصب
از قبل یک API با پروتکل JSON-RPC روی مسیر `/ibs-api/` دارد (متدهایی مثل `user.addNewUsers`،
`user.searchUser`، `group.getGroupInfo`، `report.getOnlineUsers` و ...) که مستقیماً از طریق HTTPS/HTTP
از هاست Panel-vip.ir (Host vaset) صدا زده می‌شود. یعنی:

- هیچ سرویس/کدی روی سرور IBSng نصب نمی‌شود.
- هیچ تونل SSH لازم نیست.
- کل پروژه PHP خام (بدون فریم‌ورک، بدون Composer) است تا صرفاً با آپلود فایل‌ها از cPanel File Manager
  و اجرای یک فایل `install.sql` از phpMyAdmin قابل نصب باشد — بدون نیاز به Terminal/SSH.

```
[مشتری / ریسلر]  --->  اینترنت  --->  Host vaset (cPanel)  ---HTTP/JSON--->  IBSng /ibs-api/
                                        - پنل ادمین (admin/*.php)
                                        - پنل ریسلر (reseller/*.php)
                                        - ربات تلگرام (telegram/webhook.php)
                                        - دیتابیس MySQL اختصاصی همین سیستم
```

## پوشه‌ها

- `vaset-host/` → کل چیزی که باید روی Host vaset دیپلوی شود (مستقیماً محتوای این پوشه را در public_html
  یا ساب‌دامین موردنظر آپلود کنید):
  - `admin/` پنل مدیریت (ریسلرها، کاربران، تراکنش‌ها، بدهی، فیش پرداخت، بسته‌های فروش مستقیم، سفارش‌های
    مستقیم، ربات تلگرام، تنظیمات، لاگ‌ها).
  - `reseller/` پنل ریسلر (کاربران، آنلاین‌ها، تراکنش‌ها، ارسال فیش پرداخت).
  - `telegram/` نقطه ورود Webhook ربات تلگرام مشتریان مستقیم + منطق ربات.
  - `includes/` هسته مشترک: اتصال DB و توابع کمکی (`config.php`)، JSON-RPC (`ibsng_api.php`)، Telegram
    Bot API (`telegram_api.php`)، Export/Import دیتابیس بدون نیاز به mysqldump (`db_backup.php`).
  - `install.sql` schema کامل برای نصب تازه. `migrations/002_telegram_direct_sales.sql` برای دیتابیسی
    که از قبل نصب شده (فقط چیزهای جدید ربات تلگرام را اضافه می‌کند، امن برای اجرای دوباره).
  - `uploads/` فایل‌های آپلودی (لوگو، رسید پرداخت، خروجی‌های Export) — در گیت نیست.
- `ibsng-agent/` → باقی‌مانده‌ی رویکرد قدیمی (SSH Tunnel) که دیگر استفاده نمی‌شود؛ برای این نصب لازم
  نیست، صرفاً به‌عنوان مرجع نگه داشته شده.
- `docs/` → مستندات معماری/دیپلوی نسخه قبلی (SSH Tunnel)؛ **دیگر به‌روز نیست**، این README مرجع فعلی است.

## نصب (فقط با cPanel، بدون Terminal)

1. یک دیتابیس MySQL و یوزر بسازید (cPanel → MySQL Databases).
2. کل پوشه `vaset-host/` را با File Manager به مسیر دلخواه (مثلاً `public_html`) آپلود کنید.
3. `includes/config.sample.php` را به `includes/config.php` کپی/تغییرنام دهید و مقادیر `DB_*` و
   `IBS_URL`/`IBS_ADMIN`/`IBS_PASS` را با اطلاعات واقعی خودتان پر کنید.
4. محتوای `install.sql` را از phpMyAdmin روی همان دیتابیس اجرا کنید.
5. وارد `admin/login.php` شوید (یوزر پیش‌فرض `admin`، رمز `Admin@1234` — بلافاصله از `admin/settings.php`
   عوض کنید).
6. از `admin/settings.php` آدرس واقعی API (`ibs_api_url`) و یوزر/پسورد ادمین IBSng را چک/تنظیم کنید.
7. برای ربات تلگرام: از `admin/telegram.php` توکن ربات (از @BotFather) را وارد کنید، سپس دکمه «تنظیم
   Webhook» را بزنید. برای دریافت اعلان سفارش‌ها و دستورهای `/export`، `/import`، `/stats` در تلگرام،
   Chat ID خودتان را هم همان‌جا ثبت کنید.

اگر قبلاً این پنل را نصب کرده‌اید و فقط می‌خواهید ویژگی‌های ربات تلگرام را اضافه کنید، فقط کافی است
`migrations/002_telegram_direct_sales.sql` را از phpMyAdmin اجرا کنید — نیازی به نصب دوباره نیست.

## نکته امنیتی

`includes/config.php` شامل رمزهای واقعی دیتابیس و ادمین IBSng است و در `.gitignore` قرار دارد — هرگز
آن را commit نکنید. اگر فایلی با اطلاعات واقعی به‌اشتباه commit شد، صرف حذف از کامیت بعدی کافی نیست؛
باید از تاریخچه گیت هم پاک و رمزها عوض شوند.
