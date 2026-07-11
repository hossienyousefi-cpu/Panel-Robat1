<?php use App\Core\View; ?>
<h1>داشبورد</h1>

<div class="grid">
  <div class="card stat"><div class="num"><?= $totalUsers ?></div><div class="label">تعداد یوزرهای من</div></div>
  <div class="card stat"><div class="num"><?= $onlineCount ?></div><div class="label">آنلاین الان</div></div>
  <div class="card stat"><div class="num"><?= count($expiringSoon) ?></div><div class="label">رو به اتمام (۳ روز)</div></div>
  <div class="card stat"><div class="num"><?= View::money((float) $reseller['balance']) ?></div><div class="label"><?= ((float) $reseller['balance']) < 0 ? 'بدهکاری' : 'بستانکاری' ?></div></div>
</div>

<div class="card mt">
  <h2>یوزرهای رو به اتمام</h2>
  <?php if ($expiringSoon === []): ?>
    <p class="muted">موردی یافت نشد.</p>
  <?php else: ?>
    <table>
      <tr><th>یوزرنیم</th><th>گروه</th><th>تاریخ انقضا</th></tr>
      <?php foreach ($expiringSoon as $u): ?>
        <tr><td><?= View::e($u['ibsng_username']) ?></td><td><?= View::e($u['group_name']) ?></td><td><?= View::e($u['expires_at']) ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
