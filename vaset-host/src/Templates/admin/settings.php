<?php use App\Core\Csrf; use App\Core\View; ?>
<h1>تنظیمات</h1>

<div class="card">
  <h2>وضعیت اتصال به IBSng</h2>
  <p>
    روش فعلی: <strong><?= $connectionMode === 'agent' ? 'از طریق IBSng Agent (تونل SSH)' : 'مستقیم (بدون نصب چیزی روی سرور IBSng)' ?></strong>
    &nbsp;
    <?php if ($ibsngHealthy): ?>
      <span class="badge badge-success">متصل</span>
    <?php else: ?>
      <span class="badge badge-danger">در دسترس نیست</span> — تنظیمات زیر را بررسی کنید.
    <?php endif; ?>
  </p>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="sync_ibsng">
    <button class="btn" type="submit">همگام‌سازی گروه‌ها و ISPها از IBSng</button>
  </form>
</div>

<div class="card">
  <h2>روش اتصال به IBSng</h2>
  <p class="muted">«مستقیم» یعنی این سیستم مثل یک مرورگر واقعی به پنل مدیریت IBSng (<code>/IBSng/admin</code>) وصل می‌شود و هیچ چیزی روی سرور IBSng نصب یا اجرا نمی‌شود. «Agent» روش قدیمی‌تر است که نیاز به نصب سرویس ibsng-agent و تونل SSH روی سرور IBSng دارد.</p>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="set_connection_mode">
    <label>
      <input type="radio" name="connection_mode" value="direct" <?= $connectionMode !== 'agent' ? 'checked' : '' ?>>
      مستقیم (پیشنهادی — بدون نصب روی سرور IBSng)
    </label>
    <label>
      <input type="radio" name="connection_mode" value="agent" <?= $connectionMode === 'agent' ? 'checked' : '' ?>>
      از طریق IBSng Agent (تونل SSH)
    </label>
    <button class="btn mt" type="submit">ذخیره روش اتصال</button>
  </form>
</div>

<div class="card">
  <h2>تنظیمات اتصال مستقیم به پنل IBSng</h2>
  <p class="muted">همان اطلاعاتی که برای ورود دستی به <code>https://IP-سرور-IBSng/IBSng/admin</code> استفاده می‌کنید. این مقادیر در دیتابیس ذخیره می‌شوند، پس اگر IP یا رمز عوض شد، بدون نیاز به ویرایش فایل یا SSH از همینجا اصلاحش می‌کنید.</p>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="set_direct_connection">
    <label>آدرس پنل مدیریت IBSng</label>
    <input type="text" name="admin_base_url" value="<?= View::e($adminBaseUrl) ?>" placeholder="https://194.59.214.84/IBSng/admin">
    <label class="mt">نام کاربری ادمین IBSng</label>
    <input type="text" name="admin_username" value="<?= View::e($adminUsername) ?>" placeholder="مثلاً System">
    <label class="mt">رمز عبور ادمین IBSng (<?= $adminPasswordSet ? 'در حال حاضر تنظیم شده' : 'تنظیم نشده' ?>)</label>
    <input type="password" name="admin_password" placeholder="برای تغییر، رمز جدید را وارد کنید">
    <label class="mt">
      <input type="checkbox" name="verify_ssl" value="1" <?= $adminVerifySsl ? 'checked' : '' ?>>
      بررسی گواهی SSL (اگر آدرس بالا IP خام است و گواهی معتبر ندارد، این تیک را نزنید)
    </label>
    <button class="btn mt" type="submit">ذخیره تنظیمات اتصال مستقیم</button>
  </form>
</div>

<div class="card">
  <h2>آدرس و کلید اتصال به IBSng Agent (روش قدیمی/پشتیبان)</h2>
  <p class="muted">فقط در صورتی لازم است که روش اتصال بالا را روی «Agent» گذاشته باشید. این مقادیر در دیتابیس ذخیره می‌شوند (نه فقط .env) و از همینجا یا از داخل ربات تلگرام (پیام «⚙️ آدرس IBSng Agent» / «🔑 کلید API Agent» به ربات، بعد از ثبت Chat ID در پایین این صفحه) قابل تغییرند.</p>
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
