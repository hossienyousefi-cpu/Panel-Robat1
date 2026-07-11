<?php
use App\Core\Csrf;
use App\Core\View;
?>
<h1>فیش‌های در انتظار بررسی</h1>

<?php if ($pending === []): ?>
  <p class="muted">فیشی برای بررسی وجود ندارد.</p>
<?php endif; ?>

<?php foreach ($pending as $r): ?>
  <div class="card">
    <div class="flex-between">
      <div>
        <strong>
          <?php if ($r['reseller_id']): ?>
            شارژ کیف‌پول Reseller: <?= View::e($r['reseller_username']) ?>
          <?php else: ?>
            سفارش تلگرام #<?= (int) $r['order_id'] ?> - مشتری: <?= View::e($r['tg_username'] ?: $r['first_name']) ?>
          <?php endif; ?>
        </strong>
        <div class="muted mt">مبلغ: <?= View::money((float) $r['amount']) ?> — کد پیگیری: <?= View::e($r['tracking_code'] ?: '-') ?></div>
        <div class="muted"><?= View::e($r['created_at']) ?></div>
      </div>
      <a href="/<?= View::e($r['image_path']) ?>" target="_blank" class="btn btn-secondary btn-small">مشاهده تصویر فیش</a>
    </div>
    <form method="post" class="mt">
      <?= Csrf::field() ?>
      <input type="hidden" name="receipt_id" value="<?= (int) $r['id'] ?>">
      <label>یادداشت (اختیاری)</label>
      <input type="text" name="note">
      <div class="mt">
        <button class="btn btn-success" type="submit" name="action" value="approve">تأیید</button>
        <button class="btn btn-danger" type="submit" name="action" value="reject">رد</button>
      </div>
    </form>
  </div>
<?php endforeach; ?>
