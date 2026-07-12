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
            $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
            $stmt->execute([$username]);
            $admin = $stmt->fetch();

            if ($admin && password_verify($password, $admin['password'])) {
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                session_regenerate_id(true);
                logActivity('admin', $admin['id'], 'login', 'Admin logged in');
                header('Location: dashboard.php');
                exit;
            } else {
                logActivity('login_fail', 0, 'login_fail', $username);
                $error = 'نام کاربری یا رمز عبور اشتباه است';
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
<title>ورود مدیریت - پنل مدیریت</title>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;700;900&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg: #0a0e1a;
    --surface: #111827;
    --card: #1a2235;
    --border: #2a3a55;
    --accent: #3b82f6;
    --accent2: #06b6d4;
    --gold: #f59e0b;
    --text: #e2e8f0;
    --muted: #64748b;
    --danger: #ef4444;
    --success: #10b981;
  }

  body {
    font-family: 'Vazirmatn', sans-serif;
    background: var(--bg);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    position: relative;
  }

  /* Animated background */
  .bg-orbs {
    position: fixed;
    inset: 0;
    pointer-events: none;
    z-index: 0;
  }

  .orb {
    position: absolute;
    border-radius: 50%;
    filter: blur(80px);
    opacity: 0.15;
    animation: float 8s ease-in-out infinite;
  }

  .orb-1 { width: 500px; height: 500px; background: #3b82f6; top: -200px; right: -100px; animation-delay: 0s; }
  .orb-2 { width: 400px; height: 400px; background: #06b6d4; bottom: -150px; left: -100px; animation-delay: -3s; }
  .orb-3 { width: 300px; height: 300px; background: #8b5cf6; top: 50%; left: 50%; animation-delay: -5s; }

  @keyframes float {
    0%, 100% { transform: translate(0,0) scale(1); }
    33% { transform: translate(30px, -20px) scale(1.05); }
    66% { transform: translate(-20px, 30px) scale(0.95); }
  }

  .grid-overlay {
    position: fixed;
    inset: 0;
    background-image: 
      linear-gradient(rgba(59,130,246,0.03) 1px, transparent 1px),
      linear-gradient(90deg, rgba(59,130,246,0.03) 1px, transparent 1px);
    background-size: 60px 60px;
    z-index: 0;
  }

  .login-container {
    position: relative;
    z-index: 10;
    width: 100%;
    max-width: 440px;
    padding: 20px;
  }

  .logo-section {
    text-align: center;
    margin-bottom: 40px;
    animation: slideDown 0.6s ease;
  }

  @keyframes slideDown {
    from { opacity: 0; transform: translateY(-30px); }
    to { opacity: 1; transform: translateY(0); }
  }

  .logo-icon {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    border-radius: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
    font-size: 36px;
    box-shadow: 0 20px 40px rgba(59,130,246,0.4);
    position: relative;
  }

  .logo-icon::after {
    content: '';
    position: absolute;
    inset: -2px;
    border-radius: 26px;
    background: linear-gradient(135deg, var(--accent), var(--accent2), #8b5cf6);
    z-index: -1;
    filter: blur(10px);
    opacity: 0.6;
  }

  .logo-title {
    font-size: 28px;
    font-weight: 900;
    color: var(--text);
    letter-spacing: -0.5px;
  }

  .logo-subtitle {
    font-size: 13px;
    color: var(--muted);
    margin-top: 6px;
    font-weight: 300;
  }

  .login-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 24px;
    padding: 40px;
    backdrop-filter: blur(20px);
    animation: slideUp 0.6s ease 0.2s both;
    box-shadow: 0 40px 80px rgba(0,0,0,0.4), 0 0 0 1px rgba(255,255,255,0.05);
  }

  @keyframes slideUp {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
  }

  .card-header {
    margin-bottom: 32px;
  }

  .card-title {
    font-size: 20px;
    font-weight: 700;
    color: var(--text);
  }

  .card-desc {
    font-size: 13px;
    color: var(--muted);
    margin-top: 6px;
  }

  .form-group {
    margin-bottom: 20px;
  }

  label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: var(--muted);
    margin-bottom: 8px;
  }

  .input-wrapper {
    position: relative;
  }

  .input-icon {
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--muted);
    font-size: 16px;
    pointer-events: none;
  }

  input[type="text"], input[type="password"] {
    width: 100%;
    padding: 14px 44px 14px 16px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    color: var(--text);
    font-family: 'Vazirmatn', sans-serif;
    font-size: 14px;
    transition: all 0.2s;
    outline: none;
  }

  input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
  }

  .btn-login {
    width: 100%;
    padding: 15px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    border: none;
    border-radius: 12px;
    color: #fff;
    font-family: 'Vazirmatn', sans-serif;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s;
    position: relative;
    overflow: hidden;
    margin-top: 8px;
  }

  .btn-login:hover {
    transform: translateY(-2px);
    box-shadow: 0 15px 30px rgba(59,130,246,0.4);
  }

  .btn-login:active { transform: translateY(0); }

  .btn-login::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(rgba(255,255,255,0.15), transparent);
  }

  .error-msg {
    background: rgba(239,68,68,0.1);
    border: 1px solid rgba(239,68,68,0.3);
    color: #fca5a5;
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 13px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .admin-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(245,158,11,0.1);
    border: 1px solid rgba(245,158,11,0.3);
    color: var(--gold);
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    margin-top: 10px;
  }

  .reseller-link {
    text-align: center;
    margin-top: 24px;
    font-size: 13px;
    color: var(--muted);
  }

  .reseller-link a {
    color: var(--accent);
    text-decoration: none;
    font-weight: 500;
  }

  .reseller-link a:hover { text-decoration: underline; }
</style>
</head>
<body>
<div class="bg-orbs">
  <div class="orb orb-1"></div>
  <div class="orb orb-2"></div>
  <div class="orb orb-3"></div>
</div>
<div class="grid-overlay"></div>

<div class="login-container">
  <div class="logo-section">
    <div class="logo-icon">🛡️</div>
    <div class="logo-title">پنل مدیریت</div>
    <div class="logo-subtitle">سیستم مدیریت اینترنت هوشمند</div>
    <div class="admin-badge">⭐ پنل مدیریت</div>
  </div>

  <div class="login-card">
    <div class="card-header">
      <div class="card-title">ورود به سیستم</div>
      <div class="card-desc">مشخصات ادمین خود را وارد کنید</div>
    </div>

    <?php if ($error): ?>
    <div class="error-msg">❌ <?= $error ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="form-group">
        <label>نام کاربری</label>
        <div class="input-wrapper">
          <span class="input-icon">👤</span>
          <input type="text" name="username" placeholder="admin" required autocomplete="username">
        </div>
      </div>

      <div class="form-group">
        <label>رمز عبور</label>
        <div class="input-wrapper">
          <span class="input-icon">🔒</span>
          <input type="password" name="password" placeholder="••••••••" required autocomplete="current-password">
        </div>
      </div>

      <button type="submit" class="btn-login">ورود به پنل مدیریت →</button>
    </form>

    <div class="reseller-link">
      ریسلر هستید؟ <a href="../reseller/login.php">ورود به پنل ریسلر</a>
    </div>
  </div>
</div>
</body>
</html>
