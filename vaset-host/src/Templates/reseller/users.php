<?php use App\Core\Csrf; use App\Core\View; ?>
<h1>یوزرها</h1>

<div class="card">
  <h2>ساخت یوزر جدید</h2>
  <?php if ($catalog === []): ?>
    <p class="muted">هنوز هیچ گروهی برای شما توسط ادمین فعال نشده است.</p>
  <?php else: ?>
    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="create">
      <div class="grid">
        <div>
          <label>تعداد (Count)</label>
          <input type="number" name="count" value="1" min="1" required>
        </div>
        <div>
          <label>گروه</label>
          <select name="group_name" required>
            <?php foreach ($catalog as $c): ?>
              <option value="<?= View::e($c['group_name']) ?>"><?= View::e($c['group_name']) ?> - <?= View::money((float) $c['price']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <label>الگوی یوزرنیم — برای چند یوزر از فرمت ali{01-20} استفاده کنید، برای یک یوزر فقط نام را بنویسید</label>
      <input type="text" name="username_pattern" placeholder="ali{01-20} یا ali01" required>

      <label><input type="checkbox" name="auto_password" value="1" checked onclick="document.getElementById('manual-pw').disabled=this.checked"> تولید خودکار پسورد ۴ رقمی برای هر یوزر</label>
      <label class="mt">پسورد دستی (در صورت غیرفعال بودن تیک بالا، برای همهٔ یوزرها یکسان اعمال می‌شود)</label>
      <input type="text" id="manual-pw" name="manual_password" disabled>

      <button class="btn mt" type="submit">ساخت یوزر</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>لیست یوزرهای من</h2>
  <table>
    <tr><th>یوزرنیم</th><th>گروه</th><th>وضعیت</th><th>انقضا</th><th>تاریخ ساخت</th><th>عملیات</th></tr>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= View::e($u['ibsng_username']) ?></td>
        <td><?= View::e($u['group_name']) ?></td>
        <td><?php if ($u['is_locked']): ?><span class="badge badge-danger">قفل</span><?php else: ?><span class="badge badge-success">فعال</span><?php endif; ?></td>
        <td><?= View::e($u['expires_at']) ?></td>
        <td><?= View::e($u['created_at']) ?></td>
        <td>
          <form method="post" class="inline">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="renew">
            <input type="hidden" name="username" value="<?= View::e($u['ibsng_username']) ?>">
            <button class="btn btn-small" type="submit">تمدید</button>
          </form>
          <form method="post" class="inline" onsubmit="return confirm('حذف این یوزر مبلغی برنمی‌گرداند. مطمئن هستید؟');">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="username" value="<?= View::e($u['ibsng_username']) ?>">
            <button class="btn btn-small btn-danger" type="submit">حذف</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
