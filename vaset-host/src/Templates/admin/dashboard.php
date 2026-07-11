<?php
use App\Core\View;
?>
<h1>داشبورد</h1>

<div class="grid">
  <div class="card stat"><div class="num"><?= count($resellers) ?></div><div class="label">تعداد Reseller</div></div>
  <div class="card stat"><div class="num"><?= $totalManagedUsers ?></div><div class="label">کل یوزرهای ساخته‌شده توسط سیستم</div></div>
  <div class="card stat"><div class="num"><?= $totalOnline ?></div><div class="label">کل آنلاین‌ها (همه Resellerها)</div></div>
  <div class="card stat"><div class="num"><?= $pendingReceipts ?></div><div class="label">فیش‌های در انتظار بررسی</div></div>
</div>

<div class="card mt">
  <h2>وضعیت هر Reseller</h2>
  <table>
    <tr><th>نام کاربری</th><th>تعداد یوزر ساخته‌شده</th><th>آنلاین الان</th><th>موجودی</th></tr>
    <?php foreach ($resellers as $r): ?>
      <tr>
        <td><a href="/admin/reseller_edit.php?id=<?= (int) $r['id'] ?>"><?= View::e($r['username']) ?></a></td>
        <td><?= $userCounts[$r['id']] ?? 0 ?></td>
        <td><?= $onlineCounts[$r['id']] ?? 0 ?></td>
        <td><?= View::money((float) $r['balance']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="card mt">
  <h2>رو به اتمام (کمتر از ۳ روز تا انقضا)</h2>
  <?php if ($expiringSoon === []): ?>
    <p class="muted">موردی یافت نشد.</p>
  <?php else: ?>
    <table>
      <tr><th>یوزرنیم</th><th>گروه</th><th>Reseller</th><th>تاریخ انقضا</th></tr>
      <?php foreach ($expiringSoon as $u): ?>
        <tr>
          <td><?= View::e($u['ibsng_username']) ?></td>
          <td><?= View::e($u['group_name']) ?></td>
          <td><?= $u['reseller_id'] ? "#{$u['reseller_id']}" : 'مستقیم (تلگرام)' ?></td>
          <td><?= View::e($u['expires_at']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
