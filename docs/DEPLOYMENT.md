> ⚠️ **منسوخ:** این سند مربوط به نسخه قدیمی (اتصال از طریق SSH Tunnel + IBSng Agent) است. برای مراحل نصب
> فعلی (فقط cPanel، بدون Terminal) به `README.md` در ریشه پروژه مراجعه کنید.

# راهنمای دیپلوی (قدیمی - SSH Tunnel)

## پیش‌نیازها

- Host vaset (Panel-vip.ir): VPS با WHM/cPanel، دسترسی root، PHP >= 8.1 با پسوندهای `pdo_mysql`, `curl`,
  `mbstring`، MySQL/MariaDB، امکان ساخت یک سرویس systemd (برای autossh) و یک Cron.
- سرور IBSng: دسترسی SSH/root، PHP CLI >= 8.1 (برای اجرای Agent؛ همان PHP که IBSng استفاده می‌کند کافی است)،
  دسترسی به همان MySQL که FreeRADIUS/IBSng از آن استفاده می‌کند (برای خواندن `radacct`).

## مرحله ۱ — نصب IBSng Agent روی سرور IBSng

```bash
# روی سرور IBSng
sudo mkdir -p /opt/ibsng-agent
sudo rsync -a ibsng-agent/ /opt/ibsng-agent/
cd /opt/ibsng-agent
cp config.php.example config.php
$EDITOR config.php   # مقادیر VERIFY_ME و رمزها را طبق docs/IBSNG_INTEGRATION.md پر کنید
sudo cp install/ibsng-agent.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ibsng-agent
sudo systemctl status ibsng-agent
```

Agent فقط روی `127.0.0.1:9090` گوش می‌دهد (قابل تغییر در `config.php`). هیچ فایروالی نیاز به باز کردن پورت
جدید ندارد؛ فقط باید SSH سرور (که احتمالاً همین حالا هم برای مدیریت استفاده می‌کنید) از IP هاست vaset قابل
دسترسی باشد.

## مرحله ۲ — برقراری تونل SSH دائمی از Host vaset به سرور IBSng

روی Host vaset یک کاربر سیستمی محدود (مثلاً `ibsngtunnel`) با کلید SSH بسازید که فقط اجازهٔ port-forward دارد
(بدون shell)، و روی سرور IBSng همان کلید عمومی را در `authorized_keys` یک کاربر محدود (نه root) با
`command="/bin/false"` و `PermitOpen` محدود به `127.0.0.1:9090` اضافه کنید تا حتی اگر کلید لو برود، آن کاربر
هیچ کاری جز forward پورت agent نتواند بکند.

```bash
# روی Host vaset
sudo apt-get install -y autossh   # یا yum/dnf بسته به توزیع
sudo cp ibsng-agent/install/autossh-tunnel.service /etc/systemd/system/ibsng-tunnel.service
sudo $EDITOR /etc/systemd/system/ibsng-tunnel.service   # IP سرور IBSng و کاربر تونل را ست کنید
sudo systemctl daemon-reload
sudo systemctl enable --now ibsng-tunnel
sudo systemctl status ibsng-tunnel
```

بعد از بالا آمدن این سرویس، روی Host vaset پورت `127.0.0.1:9091` باید مستقیم به Agent روی سرور IBSng وصل
باشد. تست:

```bash
curl -H "X-Api-Key: <همان کلیدی که در config.php هر دو سمت گذاشتید>" http://127.0.0.1:9091/health
```

## مرحله ۳ — دیتابیس اختصاصی روی Host vaset

```bash
mysql -u root -p -e "CREATE DATABASE panel_vaset CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p -e "CREATE USER 'panel_vaset'@'localhost' IDENTIFIED BY 'CHANGE_ME';"
mysql -u root -p -e "GRANT ALL PRIVILEGES ON panel_vaset.* TO 'panel_vaset'@'localhost';"
mysql -u panel_vaset -p panel_vaset < vaset-host/database/migrations/001_init.sql
mysql -u panel_vaset -p panel_vaset < vaset-host/database/migrations/002_admin_bot_and_settings.sql
```

## مرحله ۴ — کد اپلیکیشن روی Host vaset

```bash
rsync -a vaset-host/ /home/<cpanel-user>/panel-vaset/
cd /home/<cpanel-user>/panel-vaset
cp .env.example .env
$EDITOR .env   # DB، آدرس Agent (http://127.0.0.1:9091)، API Key، توکن ربات تلگرام، دامنه
composer install --no-dev   # فقط اگر بعداً وابستگی خارجی اضافه شد؛ فعلاً پروژه بدون وابستگی خارجی کار می‌کند
```

در cPanel یک ساب‌دامین یا Application بسازید که Document Root آن روی `panel-vaset/public` باشد (نه ریشهٔ
پروژه) تا فایل‌های `src/`, `database/`, `cron/`, `.env` هرگز مستقیم از وب قابل دسترس نباشند.

## مرحله ۵ — تنظیم Webhook ربات تلگرام

```bash
curl -F "url=https://panel.your-domain.ir/bot/webhook.php" \
     -F "secret_token=<TELEGRAM_WEBHOOK_SECRET از .env>" \
     "https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/setWebhook"
```

## مرحله ۶ — Cron Jobها (روی Host vaset)

محتوای `vaset-host/crontab.example` را با `crontab -e` اضافه کنید (مسیرها را با مسیر واقعی دیپلوی جایگزین
کنید):

```
*/15 * * * * php /home/<cpanel-user>/panel-vaset/cron/sync_online_sessions.php >> /home/<cpanel-user>/panel-vaset/storage/logs/cron.log 2>&1
0 * * * *     php /home/<cpanel-user>/panel-vaset/cron/expiry_and_overdue_watch.php >> /home/<cpanel-user>/panel-vaset/storage/logs/cron.log 2>&1
0 3 * * *     php /home/<cpanel-user>/panel-vaset/cron/sync_groups.php >> /home/<cpanel-user>/panel-vaset/storage/logs/cron.log 2>&1
```

## مرحله ۷ — ساخت اولین ادمین

چون پنل هنوز هیچ کاربری ندارد، یک اسکریپت CLI برای ساخت اولین ادمین در نظر گرفته شده:

```bash
php /home/<cpanel-user>/panel-vaset/bin/create_admin.php --username=admin --password='StrongPass123!' --name="مدیر"
```

بعد از این می‌توانید از `/admin/login.php` وارد شوید و Resellerها، قیمت‌گذاری و ... را از همان‌جا مدیریت کنید.

## مرحله ۸ — فعال‌سازی پنل مدیریتی ربات (تسک ۴: Export/Import و تنظیمات اتصال)

1. مطمئن شوید `mysqldump` و `mysql` روی Host vaset نصب‌اند (`which mysqldump mysql`)؛ در غیر این صورت مسیر
   کامل را در `.env` به `MYSQLDUMP_PATH`/`MYSQL_CLI_PATH` بدهید.
2. با اکانت تلگرام خودتان به ربات پیام بدهید تا Chat ID را بگیرید (مثلاً از @userinfobot)، سپس در
   `/admin/settings.php` همان عدد را در بخش «دریافت هشدار و دسترسی به پنل مدیریتی ربات» ذخیره کنید.
3. از همان اکانت تلگرام دوباره به ربات `/start` بزنید — چون Chat ID شما حالا با یک ادمین مطابقت دارد، به‌جای
   منوی مشتری، منوی مدیریتی (Export/Import دیتابیس، تغییر آدرس/کلید IBSng Agent، تست اتصال) نمایش داده
   می‌شود. جزئیات کامل در `docs/ARCHITECTURE.md` بخش «تسک ۴».
