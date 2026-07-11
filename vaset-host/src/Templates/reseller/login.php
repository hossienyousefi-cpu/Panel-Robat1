<?php use App\Core\Csrf; ?>
<h1>ورود نمایندگی (Reseller)</h1>
<form method="post">
  <?= Csrf::field() ?>
  <label>نام کاربری</label>
  <input type="text" name="username" required autofocus>
  <label>رمز عبور</label>
  <input type="password" name="password" required>
  <button class="btn mt" type="submit" style="width:100%">ورود</button>
</form>
