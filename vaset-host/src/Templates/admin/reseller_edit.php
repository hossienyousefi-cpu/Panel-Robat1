<?php
use App\Core\Csrf;
use App\Core\View;
?>
<h1>مدیریت Reseller: <?= View::e($reseller['username']) ?></h1>

<div class="grid">
  <div class="card stat"><div class="num"><?= count($users) ?></div><div class="label">یوزر ساخته‌شده</div></div>
  <div class="card stat"><div class="num"><?= $onlineCount ?></div><div class="label">آنلاین الان</div></div>
  <div class="card stat"><div class="num"><?= count($expiringSoon) ?></div><div class="label">رو به اتمام (۳ روز)</div></div>
  <div class="card stat"><div class="num"><?= View::money((float) $reseller['balance']) ?></div><div class="label">موجودی (بستانکاری/بدهکاری)</div></div>
</div>

<div class="card">
  <h2>پروفایل</h2>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="update_profile">
    <div class="grid">
      <div><label>نام کامل</label><input type="text" name="full_name" value="<?= View::e($reseller['full_name']) ?>"></div>
      <div><label>موبایل</label><input type="text" name="phone" value="<?= View::e($reseller['phone']) ?>"></div>
      <div>
        <label>ISP</label>
        <input type="text" name="isp" list="isp-list" value="<?= View::e($reseller['ibsng_isp']) ?>">
        <datalist id="isp-list"><?php foreach ($isps as $isp): ?><option value="<?= View::e($isp['isp_name']) ?>"><?php endforeach; ?></datalist>
      </div>
      <div>
        <label>وضعیت</label>
        <select name="is_active">
          <option value="1" <?= $reseller['is_active'] ? 'selected' : '' ?>>فعال</option>
          <option value="0" <?= !$reseller['is_active'] ? 'selected' : '' ?>>غیرفعال</option>
        </select>
      </div>
    </div>
    <button class="btn mt" type="submit">ذخیره</button>
  </form>
</div>

<div class="card">
  <h2>تغییر رمز عبور</h2>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="reset_password">
    <label>رمز عبور جدید</label>
    <input type="text" name="new_password">
    <button class="btn mt" type="submit">تغییر رمز</button>
  </form>
</div>

<div class="card">
  <h2>قیمت‌گذاری گروه‌ها برای این Reseller</h2>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="update_pricing">
    <table>
      <tr><th>گروه</th><th>قیمت (تومان)</th><th>نمایش برای Reseller</th></tr>
      <?php foreach ($pricingRows as $i => $row): ?>
        <tr>
          <td>
            <input type="text" name="groups[<?= $i ?>]" value="<?= View::e($row['group_name']) ?>">
          </td>
          <td><input type="number" step="1" name="prices[<?= $i ?>]" value="<?= (float) $row['price'] ?>"></td>
          <td><input type="checkbox" name="visible[<?= $i ?>]" <?= $row['is_visible'] ? 'checked' : '' ?>></td>
        </tr>
      <?php endforeach; ?>
      <tr>
        <td><input type="text" name="groups[new]" placeholder="نام گروه جدید"></td>
        <td><input type="number" step="1" name="prices[new]"></td>
        <td><input type="checkbox" name="visible[new]"></td>
      </tr>
    </table>
    <button class="btn mt" type="submit">ذخیره قیمت‌گذاری</button>
  </form>
  <p class="muted mt">لیست گروه‌ها از کش IBSng می‌آید؛ برای بروزرسانی به <a href="/admin/settings.php">تنظیمات</a> بروید.</p>
</div>

<div class="card">
  <h2>یوزرهای این Reseller</h2>
  <table>
    <tr><th>یوزرنیم</th><th>گروه</th><th>وضعیت</th><th>انقضا</th><th>تاریخ ساخت</th></tr>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= View::e($u['ibsng_username']) ?></td>
        <td><?= View::e($u['group_name']) ?></td>
        <td><?php if ($u['is_locked']): ?><span class="badge badge-danger">قفل</span><?php else: ?><span class="badge badge-success">فعال</span><?php endif; ?></td>
        <td><?= View::e($u['expires_at']) ?></td>
        <td><?= View::e($u['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
