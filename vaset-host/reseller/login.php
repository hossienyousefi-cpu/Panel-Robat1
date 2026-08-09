<?php
require_once '../includes/config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        if (!checkLoginRateLimit($username)) {
            $error = 'تعداد تلاش‌های ناموفق بیش از حد مجاز است. چند دقیقه دیگر دوباره امتحان کنید.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM resellers WHERE username = ? AND status = 'active'");
            $stmt->execute([$username]);
            $reseller = $stmt->fetch();

            if ($reseller && password_verify($password, $reseller['password'])) {
                $_SESSION['reseller_id'] = $reseller['id'];
                $_SESSION['reseller_username'] = $reseller['username'];
                $_SESSION['reseller_name'] = $reseller['full_name'];
                session_regenerate_id(true);
                logActivity('reseller', $reseller['id'], 'login', 'Reseller logged in');
                header('Location: dashboard.php');
                exit;
            } else {
                logActivity('login_fail', 0, 'login_fail', $username);
                $error = 'نام کاربری یا رمز عبور اشتباه است یا حساب غیرفعال است';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ورود ریسلر - پنل ریسلر</title>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;700;900&display=swap" rel="stylesheet">
<link href="../assets/css/theme.css" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg: #080c18; --card: #131e30; --border: #1e2d45; --accent: #8b5cf6; --accent2: #06b6d4;
    --text: #e2e8f0; --muted: #64748b; --danger: #ef4444; --success: #10b981; --gold: #f59e0b;
  }
  body { font-family: 'Vazirmatn', sans-serif; background: var(--bg); min-height: 100vh; display: flex; align-items: center; justify-content: center; overflow: hidden; }
  .bg-orbs { position: fixed; inset: 0; pointer-events: none; }
  .orb { position: absolute; border-radius: 50%; filter: blur(80px); opacity: 0.12; animation: float 8s ease-in-out infinite; }
  .orb-1 { width: 500px; height: 500px; background: #8b5cf6; top: -200px; left: -100px; }
  .orb-2 { width: 400px; height: 400px; background: #06b6d4; bottom: -150px; right: -100px; animation-delay: -3s; }
  @keyframes float { 0%,100% { transform: translate(0,0); } 50% { transform: translate(20px,-20px); } }
  .grid-overlay { position: fixed; inset: 0; background-image: linear-gradient(rgba(139,92,246,0.04) 1px, transparent 1px), linear-gradient(90deg, rgba(139,92,246,0.04) 1px, transparent 1px); background-size: 60px 60px; }
  .container { position: relative; z-index: 10; width: 100%; max-width: 440px; padding: 20px; }
  .logo-section { text-align: center; margin-bottom: 40px; animation: slideDown 0.6s ease; }
  @keyframes slideDown { from { opacity: 0; transform: translateY(-30px); } to { opacity: 1; transform: translateY(0); } }
  .logo-icon { width: 80px; height: 80px; background: linear-gradient(135deg, var(--accent), var(--accent2)); border-radius: 24px; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 36px; box-shadow: 0 20px 40px rgba(139,92,246,0.4); }
  .logo-title { font-size: 28px; font-weight: 900; color: var(--text); }
  .logo-subtitle { font-size: 13px; color: var(--muted); margin-top: 6px; }
  .reseller-badge { display: inline-flex; align-items: center; gap: 6px; background: rgba(139,92,246,0.1); border: 1px solid rgba(139,92,246,0.3); color: #a78bfa; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; margin-top: 10px; }
  .login-card { background: var(--card); border: 1px solid var(--border); border-radius: 24px; padding: 40px; animation: slideUp 0.6s ease 0.2s both; box-shadow: 0 40px 80px rgba(0,0,0,0.4); }
  @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
  .card-title { font-size: 20px; font-weight: 700; color: var(--text); }
  .card-desc { font-size: 13px; color: var(--muted); margin-top: 6px; margin-bottom: 28px; }
  .form-group { margin-bottom: 20px; }
  label { display: block; font-size: 13px; font-weight: 500; color: var(--muted); margin-bottom: 8px; }
  .input-wrapper { position: relative; }
  .input-icon { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: 16px; }
  input[type="text"], input[type="password"] { width: 100%; padding: 14px 44px 14px 16px; background: #111827; border: 1px solid var(--border); border-radius: 12px; color: var(--text); font-family: 'Vazirmatn'; font-size: 14px; outline: none; transition: all 0.2s; }
  input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(139,92,246,0.15); }
  .btn-login { width: 100%; padding: 15px; background: linear-gradient(135deg, var(--accent), var(--accent2)); border: none; border-radius: 12px; color: #fff; font-family: 'Vazirmatn'; font-size: 16px; font-weight: 700; cursor: pointer; transition: all 0.3s; margin-top: 8px; }
  .btn-login:hover { transform: translateY(-2px); box-shadow: 0 15px 30px rgba(139,92,246,0.4); }
  .error-msg { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 20px; }
</style>
</head>
<body class="theme-reseller">
<div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div></div>
<div class="grid-overlay"></div>
<div class="container">
  <div class="logo-section">
    <div class="logo-icon">🌐</div>
    <div class="logo-title">پنل ریسلر</div>
    <div class="logo-subtitle">پنل مدیریت ریسلر</div>
    <div class="reseller-badge">👤 پنل ریسلر</div>
  </div>
  <div class="login-card">
    <div class="card-title">ورود به پنل ریسلر</div>
    <div class="card-desc">اطلاعات حساب ریسلر خود را وارد کنید</div>

    <?php if ($error): ?>
    <div class="error-msg">❌ <?= $error ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="form-group">
        <label>نام کاربری</label>
        <div class="input-wrapper">
          <span class="input-icon">👤</span>
          <input type="text" name="username" placeholder="نام کاربری" required>
        </div>
      </div>
      <div class="form-group">
        <label>رمز عبور</label>
        <div class="input-wrapper">
          <span class="input-icon">🔒</span>
          <input type="password" name="password" placeholder="••••••••" required>
        </div>
      </div>
      <button type="submit" class="btn-login">ورود به پنل →</button>
    </form>
  </div>
</div>
</body>
</html>