<?php use App\Core\Csrf; use App\Core\View; ?>
<h1>قیمت‌گذاری کاتالوگ ربات تلگرام (فروش مستقیم)</h1>
<p class="muted">این قیمت‌ها همان چیزی است که مشتریان مستقیم ربات تلگرام (بدون Reseller) می‌بینند.</p>

<div class="card">
  <form method="post">
    <?= Csrf::field() ?>
    <table>
      <tr><th>نام گروه (IBSng)</th><th>نام نمایشی</th><th>قیمت (تومان)</th><th>نمایش در ربات</th></tr>
      <?php foreach ($rows as $i => $row): ?>
        <tr>
          <td><input type="text" name="groups[<?= $i ?>]" value="<?= View::e($row['group_name']) ?>"></td>
          <td><input type="text" name="names[<?= $i ?>]" value="<?= View::e($row['display_name'] ?? '') ?>"></td>
          <td><input type="number" step="1" name="prices[<?= $i ?>]" value="<?= (float) $row['price'] ?>"></td>
          <td><input type="checkbox" name="visible[<?= $i ?>]" <?= $row['is_visible'] ? 'checked' : '' ?>></td>
        </tr>
      <?php endforeach; ?>
      <tr>
        <td><input type="text" name="groups[new]" placeholder="نام گروه جدید"></td>
        <td><input type="text" name="names[new]"></td>
        <td><input type="number" step="1" name="prices[new]"></td>
        <td><input type="checkbox" name="visible[new]"></td>
      </tr>
    </table>
    <button class="btn mt" type="submit">ذخیره</button>
  </form>
</div>
