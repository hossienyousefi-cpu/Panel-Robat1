<?php use App\Core\Csrf; use App\Core\View; ?>
<h1>شارژ کیف‌پول</h1>

<div class="card">
  <h2>ثبت فیش پرداخت جدید</h2>
  <form method="post" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <label>مبلغ واریزی (تومان)</label>
    <input type="number" name="amount" required min="1">
    <label>کد پیگیری تراکنش</label>
    <input type="text" name="tracking_code">
    <label>تصویر فیش</label>
    <input type="file" name="receipt_image" accept="image/*" required>
    <button class="btn mt" type="submit">ثبت فیش</button>
  </form>
</div>

<div class="card">
  <h2>تاریخچهٔ فیش‌ها</h2>
  <table>
    <tr><th>مبلغ</th><th>کد پیگیری</th><th>وضعیت</th><th>یادداشت ادمین</th><th>تاریخ</th></tr>
    <?php foreach ($receipts as $r): ?>
      <tr>
        <td><?= View::money((float) $r['amount']) ?></td>
        <td><?= View::e($r['tracking_code'] ?: '-') ?></td>
        <td>
          <?php if ($r['status'] === 'approved'): ?><span class="badge badge-success">تأیید شده</span>
          <?php elseif ($r['status'] === 'rejected'): ?><span class="badge badge-danger">رد شده</span>
          <?php else: ?><span class="badge badge-warn">در انتظار بررسی</span><?php endif; ?>
        </td>
        <td><?= View::e($r['admin_note'] ?: '-') ?></td>
        <td><?= View::e($r['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
