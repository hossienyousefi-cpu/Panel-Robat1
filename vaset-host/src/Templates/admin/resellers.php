<?php
use App\Core\Csrf;
use App\Core\View;
?>
<h1>Resellerها</h1>

<div class="card">
  <h2>ساخت Reseller جدید</h2>
  <form method="post">
    <?= Csrf::field() ?>
    <div class="grid">
      <div><label>نام کاربری</label><input type="text" name="username" required></div>
      <div><label>رمز عبور</label><input type="text" name="password" required></div>
      <div><label>نام کامل</label><input type="text" name="full_name"></div>
      <div><label>موبایل</label><input type="text" name="phone"></div>
      <div>
        <label>ISP (بایند IBSng)</label>
        <input type="text" name="isp" list="isp-list" placeholder="نام دقیق ISP در IBSng" required>
        <datalist id="isp-list">
          <?php foreach ($isps as $isp): ?>
            <option value="<?= View::e($isp['isp_name']) ?>">
          <?php endforeach; ?>
        </datalist>
      </div>
    </div>
    <button class="btn mt" type="submit">ساخت Reseller</button>
  </form>
  <p class="muted mt">اگر لیست ISPها خالی است، از صفحه <a href="/admin/settings.php">تنظیمات</a> دکمهٔ «همگام‌سازی با IBSng» را بزنید.</p>
</div>

<div class="card">
  <h2>لیست Resellerها</h2>
  <table>
    <tr><th>نام کاربری</th><th>نام</th><th>ISP</th><th>موجودی</th><th>یوزر ساخته‌شده</th><th>آنلاین</th><th>وضعیت</th><th></th></tr>
    <?php foreach ($resellers as $r): ?>
      <tr>
        <td><?= View::e($r['username']) ?></td>
        <td><?= View::e($r['full_name']) ?></td>
        <td><?= View::e($r['ibsng_isp']) ?></td>
        <td><?= View::money((float) $r['balance']) ?></td>
        <td><?= $userCounts[$r['id']] ?? 0 ?></td>
        <td><?= $onlineCounts[$r['id']] ?? 0 ?></td>
        <td><?php if ($r['is_active']): ?><span class="badge badge-success">فعال</span><?php else: ?><span class="badge badge-danger">غیرفعال</span><?php endif; ?></td>
        <td><a class="btn btn-small btn-secondary" href="/admin/reseller_edit.php?id=<?= (int) $r['id'] ?>">مدیریت</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
