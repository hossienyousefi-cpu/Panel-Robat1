<?php use App\Core\View; ?>
<h1>یوزرهای سیستم</h1>
<p class="muted">این لیست فقط شامل یوزرهایی است که از زمان راه‌اندازی این سیستم از طریق پنل Reseller یا ربات تلگرام ساخته شده‌اند؛ یوزرهای قدیمی IBSng در اینجا نمایش داده نمی‌شوند.</p>

<div class="card">
  <table>
    <tr><th>یوزرنیم</th><th>منبع</th><th>گروه</th><th>وضعیت</th><th>انقضا</th><th>تاریخ ساخت</th></tr>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= View::e($u['ibsng_username']) ?></td>
        <td><?= $u['owner_type'] === 'reseller' ? ('Reseller: ' . View::e($resellerNames[$u['reseller_id']] ?? ('#' . $u['reseller_id']))) : 'مستقیم (تلگرام)' ?></td>
        <td><?= View::e($u['group_name']) ?></td>
        <td><?php if ($u['is_locked']): ?><span class="badge badge-danger">قفل</span><?php else: ?><span class="badge badge-success">فعال</span><?php endif; ?></td>
        <td><?= View::e($u['expires_at']) ?></td>
        <td><?= View::e($u['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
