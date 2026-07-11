# IBSng Agent

این پوشه باید **روی خودِ سرور IBSng** نصب شود (نیاز به SSH/root دارد). این agent تنها پلی است که به Host
vaset اجازه می‌دهد از طریق یک تونل SSH (بدون باز کردن هیچ پورت جدیدی رو به اینترنت) عملیات ساخت/حذف/لاک/تمدید
یوزر و خواندن تعداد آنلاین‌ها را روی IBSng انجام دهد. جزئیات کامل معماری در `../docs/ARCHITECTURE.md` و
`../docs/IBSNG_INTEGRATION.md` است.

## نصب سریع

```bash
sudo mkdir -p /opt/ibsng-agent
sudo rsync -a . /opt/ibsng-agent/
cd /opt/ibsng-agent
cp config.php.example config.php
$EDITOR config.php   # همه‌جا که VERIFY_ME نوشته را طبق ../docs/IBSNG_INTEGRATION.md پر کنید
sudo cp install/ibsng-agent.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ibsng-agent
curl http://127.0.0.1:9090/health   # باید {"ok":true} برگرداند
```

## قبل از نصب چک‌لیست تأیید

پر کردن `config.php` بدون تأیید مقادیر واقعی نصب شما (نام فیلدهای فرم، آدرس صفحات) باعث می‌شود عملیات
نوشتنی (ساخت/حذف/لاک/تمدید) به‌صورت بی‌صدا شکست بخورند یا نتیجهٔ اشتباه بدهند. حتماً قبل از استفادهٔ واقعی:

1. `docs/IBSNG_INTEGRATION.md` (در ریشهٔ ریپازیتوری) را کامل دنبال کنید و همهٔ `VERIFY_ME`های `config.php` را
   با مقادیر واقعی جایگزین کنید.
2. با یک یوزر تستی (که بلافاصله حذفش می‌کنید) هر endpoint را جدا تست کنید:

```bash
API_KEY=$(php -r 'echo (require "config.php")["api_key"];')

curl -s -H "X-Api-Key: $API_KEY" http://127.0.0.1:9090/groups | jq
curl -s -H "X-Api-Key: $API_KEY" http://127.0.0.1:9090/isps | jq

curl -s -X POST -H "X-Api-Key: $API_KEY" -H "Content-Type: application/json" \
  -d '{"items":[{"username":"agenttest01","password":"1234"}],"group":"<یک گروه واقعی>","isp":"<یک ISP واقعی>","credit1":100,"credit2":0}' \
  http://127.0.0.1:9090/users/create | jq

curl -s -H "X-Api-Key: $API_KEY" "http://127.0.0.1:9090/users/search?username=agenttest01" | jq

curl -s -X POST -H "X-Api-Key: $API_KEY" -H "Content-Type: application/json" \
  -d '{"username":"agenttest01"}' http://127.0.0.1:9090/users/lock | jq

curl -s -X POST -H "X-Api-Key: $API_KEY" -H "Content-Type: application/json" \
  -d '{"username":"agenttest01"}' http://127.0.0.1:9090/users/delete | jq
```

بعد از هر مرحله، در پنل ادمین IBSng هم چک کنید که نتیجه واقعاً همانی است که انتظار داشتید (یوزر ساخته/قفل/حذف
شده)، نه فقط این‌که curl پاسخ 200 داده.

3. `curl -s -H "X-Api-Key: $API_KEY" http://127.0.0.1:9090/sessions/online | jq` را هم تست کنید؛ این یکی به
   `radacct` (دیتابیس FreeRADIUS) وصل می‌شود، نه به پنل ادمین، پس فقط `config.php` بخش `radacct` (اطلاعات
   اتصال دیتابیس) باید درست باشد.

## ساختار

- `config.php.example` — همهٔ تنظیمات (اعتبارنامه‌ها، آدرس‌ها، نام فیلدهای فرم، Regexهای استخراج اطلاعات).
- `src/IBSngAdminClient.php` — کلاینت cURL که فرم‌های `admin/*.php` را شبیه‌سازی می‌کند؛ کاملاً بر اساس
  `config.php` کار می‌کند و هیچ نام فیلدی داخل کد hardcode نشده.
- `src/RadAcctReader.php` — خواندن مستقیم (فقط خواندن) از جدول استاندارد `radacct` برای تعداد آنلاین‌ها.
- `public/index.php` — روتر HTTP API که Host vaset با آن صحبت می‌کند (نیازمند هدر `X-Api-Key`).
- `install/ibsng-agent.service` — systemd unit برای اجرای دائمی Agent (فقط روی `127.0.0.1`).
- `install/autossh-tunnel.service` — systemd unit که **روی Host vaset** نصب می‌شود تا تونل SSH دائمی به این
  Agent برقرار کند.
