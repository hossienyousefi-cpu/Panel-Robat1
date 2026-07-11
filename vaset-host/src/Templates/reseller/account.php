<?php use App\Core\View; ?>
<h1>حساب من</h1>

<div class="card stat">
  <div class="num"><?= View::money((float) $reseller['balance']) ?></div>
  <div class="label"><?= ((float) $reseller['balance']) < 0 ? 'در حال حاضر بدهکار هستید' : 'موجودی بستانکاری فعلی' ?></div>
</div>

<div class="card mt">
  <h2>تاریخچهٔ تراکنش‌ها</h2>
  <?php $labels = [
    'credit_topup' => 'شارژ کیف‌پول (فیش تأییدشده)',
    'debit_purchase' => 'خرید / ساخت یوزر',
    'debit_renew' => 'تمدید یوزر',
    'credit_refund' => 'برگشت وجه',
    'adjustment' => 'اصلاح دستی',
  ]; ?>
  <table>
    <tr><th>نوع</th><th>مبلغ</th><th>موجودی پس از تراکنش</th><th>مرجع</th><th>تاریخ</th></tr>
    <?php foreach ($ledger as $l): ?>
      <tr>
        <td><?= View::e($labels[$l['entry_type']] ?? $l['entry_type']) ?></td>
        <td><?= View::money((float) $l['amount']) ?></td>
        <td><?= View::money((float) $l['balance_after']) ?></td>
        <td><?= View::e($l['reference'] ?: '-') ?></td>
        <td><?= View::e($l['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
