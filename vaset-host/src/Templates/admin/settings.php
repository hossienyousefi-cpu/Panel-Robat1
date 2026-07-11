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
  <h2>دریافت هشدار در تلگرام</h2>
  <p class="muted">با ثبت شناسهٔ چت تلگرام خودتان (مثلاً از ربات @userinfobot بگیرید)، هشدارهای «پرداخت‌نشده بیش از ۲۴ ساعت» و «فیش جدید ثبت شد» برای شما ارسال می‌شود.</p>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="set_chat_id">
    <label>Telegram Chat ID فعلی: <?= View::e((string) ($admin['telegram_chat_id'] ?? 'ثبت نشده')) ?></label>
    <input type="text" name="telegram_chat_id" placeholder="مثلاً 123456789">
    <button class="btn mt" type="submit">ذخیره</button>
  </form>
</div>
