<?php use App\Core\Csrf; use App\Core\View; ?>
<h1>تنظیمات</h1>

<div class="card">
  <h2>وضعیت اتصال به IBSng Agent</h2>
  <p>
    <?php if ($agentHealthy): ?>
      <span class="badge badge-success">متصل</span>
    <?php else: ?>
      <span class="badge badge-danger">در دسترس نیست</span> — تونل SSH و سرویس ibsng-agent را بررسی کنید (docs/DEPLOYMENT.md).
    <?php endif; ?>
  </p>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="sync_ibsng">
    <button class="btn" type="submit">همگام‌سازی گروه‌ها و ISPها از IBSng</button>
  </form>
</div>

<div class="card">
  <h2>آدرس و کلید اتصال به IBSng Agent</h2>
  <p class="muted">این مقادیر در دیتابیس ذخیره می‌شوند (نه فقط .env) و از همینجا یا از داخل ربات تلگرام (پیام «⚙️ آدرس IBSng Agent» / «🔑 کلید API Agent» به ربات، بعد از ثبت Chat ID در پایین این صفحه) قابل تغییرند — یعنی اگر اتصال قطع شد، بدون نیاز به SSH می‌توانید آن را اصلاح کنید.</p>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="set_agent_url">
    <label>آدرس فعلی IBSng Agent</label>
    <input type="text" name="agent_url" value="<?= View::e($agentUrl) ?>" placeholder="http://127.0.0.1:9091">
    <button class="btn mt" type="submit">ذخیره آدرس</button>
  </form>
  <form method="post" class="mt">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="set_agent_key">
    <label>کلید API (<?= $agentKeySet ? 'در حال حاضر تنظیم شده' : 'تنظیم نشده' ?>)</label>
    <input type="text" name="agent_key" placeholder="کلید جدید را وارد کنید">
    <button class="btn mt" type="submit">ذخیره کلید</button>
  </form>
</div>

<div class="card">
  <h2>Export دیتابیس سیستم</h2>
  <p class="muted">این فقط دیتابیس اختصاصی همین سیستم (Resellerها، قیمت‌گذاری، فیش‌ها، لجر مالی) را خروجی می‌گیرد، نه دیتابیس IBSng. برای دریافت مستقیم فایل، از داخل ربات تلگرام (پیام «📤 Export دیتابیس») استفاده کنید؛ این دکمه فقط فایل را روی سرور در <code>storage/backups/</code> می‌سازد.</p>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="export_db">
    <button class="btn" type="submit">ساخت فایل خروجی روی سرور</button>
  </form>
</div>

<div class="card">
  <h2>دریافت هشدار و دسترسی به پنل مدیریتی ربات</h2>
  <p class="muted">با ثبت شناسهٔ چت تلگرام خودتان (مثلاً از ربات @userinfobot بگیرید)، هم هشدارهای «پرداخت‌نشده بیش از ۲۴ ساعت» و «فیش جدید ثبت شد» برای شما ارسال می‌شود، و هم با پیام دادن به ربات از همان اکانت، منوی مدیریتی (Export/Import دیتابیس، تغییر تنظیمات اتصال) به‌جای منوی مشتری برایتان باز می‌شود.</p>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="set_chat_id">
    <label>Telegram Chat ID فعلی: <?= View::e((string) ($admin['telegram_chat_id'] ?? 'ثبت نشده')) ?></label>
    <input type="text" name="telegram_chat_id" placeholder="مثلاً 123456789">
    <button class="btn mt" type="submit">ذخیره</button>
  </form>
</div>
