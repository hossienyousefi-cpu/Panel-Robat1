<?php
require_once '../includes/config.php';
require_once '../includes/ibsng_api.php';
requireAdmin();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die('درخواست نامعتبر است (احتمالاً صفحه قدیمی شده). لطفاً صفحه را رفرش کرده و دوباره امتحان کنید.');
}

$error = ''; $success = '';
$ibsGroups = ibsng_getGroups();
$ibsIsps = ibsng_getIsps();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_package') {
        $id = (int)($_POST['id'] ?? 0);
        $group = sanitize($_POST['group_name'] ?? '');
        $title = sanitize($_POST['title'] ?? '');
        $price = max(0, (float)str_replace(',', '', $_POST['price'] ?? '0'));
        $isp = sanitize($_POST['isp_name'] ?? '');
        $sort = (int)($_POST['sort_order'] ?? 0);

        if (!$group || !$title || !$isp) {
            $error = 'گروه، عنوان و ISP الزامی است';
        } else {
            $chk = $pdo->prepare("SELECT id FROM direct_packages WHERE group_name=? AND id<>?");
            $chk->execute([$group, $id]);
            if ($chk->fetch()) {
                $error = 'برای این گروه قبلاً یک بسته تعریف شده';
            } elseif ($id) {
                $pdo->prepare("UPDATE direct_packages SET group_name=?, title=?, price=?, isp_name=?, sort_order=? WHERE id=?")
                    ->execute([$group, $title, $price, $isp, $sort, $id]);
                $success = 'بسته بروزرسانی شد';
            } else {
                $pdo->prepare("INSERT INTO direct_packages (group_name, title, price, isp_name, sort_order) VALUES (?,?,?,?,?)")
                    ->execute([$group, $title, $price, $isp, $sort]);
                $success = 'بسته اضافه شد';
            }
            logActivity('admin', $_SESSION['admin_id'], 'save_direct_package', $group);
        }
    }

    if ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE direct_packages SET is_active = IF(is_active=1,0,1) WHERE id=?")->execute([$id]);
        $success = 'وضعیت تغییر کرد';
    }

    if ($action === 'delete_package') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM direct_packages WHERE id=?")->execute([$id]);
        $success = 'بسته حذف شد';
    }
}

$packages = $pdo->query("SELECT * FROM direct_packages ORDER BY sort_order, id")->fetchAll();
$pendingOrders = $pdo->query("SELECT COUNT(*) FROM telegram_orders WHERE status='pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>بسته‌های فروش مستقیم - پنل مدیریت</title>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#080c18;--sb:#0d1424;--card:#131e30;--surf:#0d1a2a;--bor:#1e2d45;--acc:#3b82f6;--acc2:#06b6d4;--pur:#8b5cf6;--gold:#f59e0b;--txt:#e2e8f0;--txt2:#94a3b8;--muted:#475569;--red:#ef4444;--grn:#10b981;--yel:#f59e0b;--sw:260px}
body{font-family:'Vazirmatn',sans-serif;background:var(--bg);color:var(--txt);display:flex;min-height:100vh}
aside{width:var(--sw);background:var(--sb);border-left:1px solid var(--bor);position:fixed;right:0;top:0;bottom:0;display:flex;flex-direction:column;z-index:100}
.logo{padding:20px;border-bottom:1px solid var(--bor)}
.logo-t{font-size:17px;font-weight:900;background:linear-gradient(135deg,var(--acc),var(--acc2));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.logo-b{font-size:10px;color:var(--muted);-webkit-text-fill-color:var(--muted)}
nav{flex:1;padding:12px 10px;overflow-y:auto}
.ns{font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);padding:8px 12px 4px;font-weight:600}
.ni{display:flex;align-items:center;gap:10px;padding:10px 13px;border-radius:9px;color:var(--txt2);text-decoration:none;font-size:13px;font-weight:500;transition:all .2s;margin-bottom:2px}
.ni:hover{background:rgba(59,130,246,.08);color:var(--txt)}
.ni.active{background:rgba(59,130,246,.15);color:var(--acc)}
.pb{background:rgba(245,158,11,.2);color:var(--yel);padding:2px 7px;border-radius:10px;font-size:11px;font-weight:700;margin-right:auto}
.sf{padding:12px 10px;border-top:1px solid var(--bor)}
.ai{display:flex;align-items:center;gap:9px;padding:9px 12px;border-radius:9px;background:rgba(255,255,255,.03);margin-bottom:4px}
.av{width:32px;height:32px;background:linear-gradient(135deg,var(--acc),var(--pur));border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px}
.logout{color:var(--red)!important}
main{margin-right:var(--sw);flex:1;min-width:0}
.topbar{background:var(--sb);border-bottom:1px solid var(--bor);padding:14px 20px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;gap:10px;flex-wrap:wrap}
.pg-title{font-size:17px;font-weight:700}
.content{padding:20px}
.alert{padding:12px 16px;border-radius:9px;margin-bottom:14px;font-size:13px}
.a-ok{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);color:#6ee7b7}
.a-err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
.btn{padding:8px 14px;border-radius:9px;font-family:'Vazirmatn';font-size:13px;font-weight:700;cursor:pointer;border:none;transition:all .2s;display:inline-flex;align-items:center;gap:6px;text-decoration:none;white-space:nowrap}
.bp{background:linear-gradient(135deg,var(--acc),var(--acc2));color:#fff}
.bpu{background:rgba(139,92,246,.15);color:#a78bfa;border:1px solid rgba(139,92,246,.3)}
.bg{background:rgba(16,185,129,.15);color:var(--grn);border:1px solid rgba(16,185,129,.3)}
.bd{background:rgba(239,68,68,.1);color:var(--red);border:1px solid rgba(239,68,68,.3)}
.bsm{padding:5px 9px;font-size:12px;border-radius:7px}
.card{background:var(--card);border:1px solid var(--bor);border-radius:12px;overflow:hidden}
.tw{overflow-x:auto}
table.t{width:100%;border-collapse:collapse;min-width:650px}
table.t th{text-align:right;font-size:11px;font-weight:600;color:var(--muted);padding:10px 12px;border-bottom:1px solid var(--bor);background:rgba(0,0,0,.2);white-space:nowrap}
table.t td{padding:10px 12px;font-size:13px;border-bottom:1px solid rgba(30,45,69,.5);color:var(--txt2);vertical-align:middle}
table.t tr:last-child td{border-bottom:none}
.badge{display:inline-block;padding:3px 8px;border-radius:20px;font-size:11px;font-weight:600}
.bok{background:rgba(16,185,129,.1);color:var(--grn);border:1px solid rgba(16,185,129,.2)}
.ber{background:rgba(239,68,68,.1);color:var(--red);border:1px solid rgba(239,68,68,.2)}
.acts{display:flex;gap:4px;flex-wrap:wrap}
.mbg{position:fixed;inset:0;background:rgba(0,0,0,.78);backdrop-filter:blur(4px);z-index:200;display:none;align-items:center;justify-content:center;padding:12px}
.mbg.open{display:flex}
.modal{background:var(--card);border:1px solid var(--bor);border-radius:16px;width:100%;max-width:480px;overflow:hidden}
.mh{padding:16px 20px;border-bottom:1px solid var(--bor);display:flex;align-items:center;justify-content:space-between}
.mt{font-size:15px;font-weight:700}
.mc{background:none;border:none;color:var(--muted);font-size:20px;cursor:pointer}
.mb{padding:20px}
.mf{padding:12px 20px;border-top:1px solid var(--bor);display:flex;gap:8px;justify-content:flex-end}
.fg{margin-bottom:12px}
.lbl{display:block;font-size:12px;font-weight:600;color:var(--txt2);margin-bottom:5px}
.fr{display:grid;grid-template-columns:1fr 1fr;gap:10px}
input[type=text],input[type=number],select{width:100%;padding:9px 12px;background:var(--surf);border:1px solid var(--bor);border-radius:8px;color:var(--txt);font-family:'Vazirmatn';font-size:13px;outline:none}
input:focus,select:focus{border-color:var(--acc)}
</style>
</head>
<body>
<aside>
  <div class="logo">
    <?php $sL=getSetting('site_logo',''); if($sL&&file_exists(dirname(__DIR__).'/'.$sL)):?>
    <img src="../<?=sanitize($sL)?>" alt="" style="max-height:52px;max-width:180px;object-fit:contain;margin-bottom:4px;display:block">
    <?php else:?><div class="logo-t">⚡ پنل مدیریت</div><?php endif;?>
    <div class="logo-b">سیستم مدیریت اینترنت</div>
  </div>
  <nav>
    <div class="ns">اصلی</div>
    <a href="dashboard.php" class="ni">📊 داشبورد</a>
    <div class="ns">مدیریت</div>
    <a href="resellers.php" class="ni">👥 ریسلرها</a>
    <a href="users.php" class="ni">🧑‍💻 کاربران</a>
    <a href="online.php" class="ni">🟢 کاربران آنلاین</a>
    <div class="ns">مالی</div>
    <a href="transactions.php" class="ni">💳 تراکنش‌ها</a>
    <a href="debts.php" class="ni">💰 مدیریت موجودی</a>
    <a href="payments.php" class="ni">🧾 فیش پرداخت</a>
    <div class="ns">فروش مستقیم تلگرام</div>
    <a href="direct_packages.php" class="ni active">📦 بسته‌های فروش مستقیم</a>
    <a href="direct_orders.php" class="ni">🛒 سفارش‌های مستقیم <?php if($pendingOrders>0):?><span class="pb"><?=$pendingOrders?></span><?php endif;?></a>
    <a href="telegram.php" class="ni">🤖 ربات تلگرام</a>
    <div class="ns">سیستم</div>
    <a href="logs.php" class="ni">📋 لاگ‌ها</a>
    <a href="settings.php" class="ni">⚙️ تنظیمات</a>
  </nav>
  <div class="sf">
    <div class="ai">
      <div class="av">🛡️</div>
      <div><div style="font-size:13px;font-weight:600"><?=sanitize($_SESSION['admin_username'])?></div><div style="font-size:11px;color:var(--muted)">مدیر اصلی</div></div>
    </div>
    <a href="logout.php" class="ni logout">🚪 خروج</a>
  </div>
</aside>

<main>
  <div class="topbar">
    <div class="pg-title">📦 بسته‌های فروش مستقیم</div>
    <button class="btn bp" onclick="openAdd()">➕ بسته جدید</button>
  </div>
  <div class="content">
    <?php if($success):?><div class="alert a-ok">✅ <?=sanitize($success)?></div><?php endif;?>
    <?php if($error):?><div class="alert a-err">❌ <?=sanitize($error)?></div><?php endif;?>
    <p style="font-size:12px;color:var(--muted);margin-bottom:14px">این بسته‌ها همانی هستند که مشتریان مستقیم داخل ربات تلگرام برای «خرید سرویس جدید» و قیمت «تمدید» می‌بینند. هر گروه فقط یک بسته می‌تواند داشته باشد.</p>
    <div class="card">
      <div class="tw">
        <table class="t">
          <thead><tr><th>عنوان</th><th>گروه </th><th>ISP</th><th>قیمت (تومان)</th><th>ترتیب</th><th>وضعیت</th><th>عملیات</th></tr></thead>
          <tbody>
          <?php foreach ($packages as $p): ?>
          <tr>
            <td style="font-weight:700;color:var(--txt)"><?=sanitize($p['title'])?></td>
            <td><span class="badge bok"><?=sanitize($p['group_name'])?></span></td>
            <td><?=sanitize($p['isp_name'])?></td>
            <td style="font-weight:700;color:#34d399"><?=number_format((float)$p['price'])?></td>
            <td><?=(int)$p['sort_order']?></td>
            <td>
              <form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="id" value="<?=$p['id']?>">
                <button type="submit" class="badge <?=$p['is_active']?'bok':'ber'?>" style="border:none;cursor:pointer"><?=$p['is_active']?'✅ فعال':'❌ غیرفعال'?></button>
              </form>
            </td>
            <td>
              <div class="acts">
                <button class="btn bpu bsm" onclick='openEdit(<?=json_encode($p, JSON_UNESCAPED_UNICODE)?>)' title="ویرایش بسته">✏️</button>
                <form method="POST" style="display:inline" onsubmit="return confirm('حذف این بسته؟')"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
                  <input type="hidden" name="action" value="delete_package">
                  <input type="hidden" name="id" value="<?=$p['id']?>">
                  <button type="submit" class="btn bd bsm" title="حذف بسته">🗑</button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($packages)): ?>
          <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--muted)">هنوز بسته‌ای تعریف نشده</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>

<div class="mbg" id="pkgM">
  <div class="modal">
    <div class="mh"><div class="mt" id="pkgTitle">➕ بسته جدید</div><button class="mc" onclick="closeM()" title="بستن">✕</button></div>
    <form method="POST"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
      <input type="hidden" name="action" value="save_package">
      <input type="hidden" name="id" id="fId" value="">
      <div class="mb">
        <div class="fg"><label class="lbl">عنوان نمایشی (در ربات دیده می‌شود)</label><input type="text" name="title" id="fTitle" required placeholder="مثلاً: بسته یک‌ماهه ۳۰ گیگ"></div>
        <div class="fr">
          <div class="fg">
            <label class="lbl">گروه </label>
            <select name="group_name" id="fGroup" required>
              <option value="">انتخاب...</option>
              <?php foreach ($ibsGroups as $g): ?><option value="<?=sanitize($g)?>"><?=sanitize($g)?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="fg">
            <label class="lbl">ISP</label>
            <select name="isp_name" id="fIsp" required>
              <option value="">انتخاب...</option>
              <?php foreach ($ibsIsps as $isp): ?><option value="<?=sanitize($isp)?>"><?=sanitize($isp)?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="fr">
          <div class="fg"><label class="lbl">قیمت (تومان)</label><input type="number" name="price" id="fPrice" min="0" step="1000" required></div>
          <div class="fg"><label class="lbl">ترتیب نمایش</label><input type="number" name="sort_order" id="fSort" value="0"></div>
        </div>
      </div>
      <div class="mf">
        <button type="button" class="btn bd" onclick="closeM()">انصراف</button>
        <button type="submit" class="btn bp">💾 ذخیره</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAdd(){
  document.getElementById('pkgTitle').textContent='➕ بسته جدید';
  document.getElementById('fId').value='';
  document.getElementById('fTitle').value='';
  document.getElementById('fGroup').value='';
  document.getElementById('fIsp').value='';
  document.getElementById('fPrice').value='';
  document.getElementById('fSort').value='0';
  document.getElementById('pkgM').classList.add('open');
}
function openEdit(p){
  document.getElementById('pkgTitle').textContent='✏️ ویرایش بسته';
  document.getElementById('fId').value=p.id;
  document.getElementById('fTitle').value=p.title;
  document.getElementById('fGroup').value=p.group_name;
  document.getElementById('fIsp').value=p.isp_name;
  document.getElementById('fPrice').value=p.price;
  document.getElementById('fSort').value=p.sort_order;
  document.getElementById('pkgM').classList.add('open');
}
function closeM(){document.getElementById('pkgM').classList.remove('open');}
document.getElementById('pkgM').addEventListener('click',e=>{if(e.target.id==='pkgM')closeM();});
</script>
</body>
</html>
