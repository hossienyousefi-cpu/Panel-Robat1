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
$ibsIsps   = ibsng_getIsps();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_reseller') {
        $un  = sanitize($_POST['username']  ?? '');
        $pw  = $_POST['password']  ?? '';
        $fn  = sanitize($_POST['full_name'] ?? '');
        $ph  = sanitize($_POST['phone']     ?? '');
        $isp = sanitize($_POST['isp_name']  ?? '');
        if (!$un||!$pw) { $error='نام کاربری و رمز عبور الزامی است'; }
        else {
            $x=$pdo->prepare("SELECT id FROM resellers WHERE username=?"); $x->execute([$un]);
            if ($x->fetch()) { $error='این نام کاربری قبلاً ثبت شده'; }
            else {
                $pdo->prepare("INSERT INTO resellers (username,password,full_name,phone,isp_name,can_delete_users,can_renew_users,created_by) VALUES (?,?,?,?,?,0,1,?)")
                    ->execute([$un,password_hash($pw,PASSWORD_BCRYPT),$fn,$ph,$isp,$_SESSION['admin_id']]);
                logActivity('admin',$_SESSION['admin_id'],'create_reseller',"ریسلر $un ایجاد شد");
                $success = "ریسلر $un ایجاد شد";
            }
        }
    }

    if ($action === 'update_settings') {
        $rid = (int)($_POST['reseller_id']??0);
        $isp = sanitize($_POST['isp_name']??'');
        $del = isset($_POST['can_delete'])?1:0;
        $ren = isset($_POST['can_renew'])?1:0;
        $pdo->prepare("UPDATE resellers SET isp_name=?,can_delete_users=?,can_renew_users=? WHERE id=?")
            ->execute([$isp,$del,$ren,$rid]);
        $pdo->prepare("DELETE FROM reseller_groups WHERE reseller_id=?")->execute([$rid]);
        $groups=$_POST['groups']??[]; $prices=$_POST['group_prices']??[]; $saved=0;
        foreach ($groups as $g) {
            $g=trim($g); if(!$g) continue;
            $price=max(0,(float)str_replace(',','',$prices[$g]??'0'));
            $pdo->prepare("INSERT INTO reseller_groups (reseller_id,group_name,price) VALUES (?,?,?)")->execute([$rid,$g,$price]);
            $saved++;
        }
        logActivity('admin',$_SESSION['admin_id'],'update_reseller',"ریسلر #$rid isp=$isp, $saved گروه");
        $success="تنظیمات ذخیره شد ($saved گروه)";
    }

    if ($action === 'toggle_status') {
        $rid=(int)($_POST['reseller_id']??0);
        $pdo->prepare("UPDATE resellers SET status=IF(status='active','inactive','active') WHERE id=?")->execute([$rid]);
        $success='وضعیت تغییر کرد';
    }

    if ($action === 'delete_reseller') {
        $rid=(int)($_POST['reseller_id']??0);
        $n=$pdo->prepare("SELECT username FROM resellers WHERE id=?"); $n->execute([$rid]); $nm=$n->fetchColumn();
        $pdo->prepare("DELETE FROM resellers WHERE id=?")->execute([$rid]);
        $success="ریسلر $nm حذف شد";
    }

    if ($action === 'set_isp_id') {
        $ispN = sanitize($_POST['isp_name'] ?? '');
        $ispIdVal = (int)($_POST['isp_id'] ?? 0);
        if ($ispN === '' || $ispIdVal <= 0) { $error = 'اسم ISP و شناسه‌ی عددی معتبر لازمه'; }
        else {
            ibsng_setIspIdManual($ispN, $ispIdVal);
            logActivity('admin',$_SESSION['admin_id'],'set_isp_id',"ISP $ispN => isp_id $ispIdVal");
            $success = "شناسه‌ی ISP «$ispN» روی $ispIdVal ثبت شد";
        }
    }

    if ($action === 'clear_isp_id') {
        $ispN = sanitize($_POST['isp_name'] ?? '');
        if ($ispN !== '') {
            ibsng_clearIspIdManual($ispN);
            logActivity('admin',$_SESSION['admin_id'],'clear_isp_id',"ISP $ispN نگاشت دستی پاک شد");
            $success = "نگاشت دستی ISP «$ispN» پاک شد (دوباره به‌صورت خودکار تشخیص داده می‌شه)";
        }
    }
}

// AJAX: اطلاعات ریسلر
if (isset($_GET['ajax']) && $_GET['ajax']==='get') {
    header('Content-Type: application/json');
    $rid=(int)($_GET['rid']??0);
    $r=$pdo->prepare("SELECT * FROM resellers WHERE id=?"); $r->execute([$rid]); $rD=$r->fetch(PDO::FETCH_ASSOC);
    if(!$rD){echo json_encode(['ok'=>false,'msg'=>'یافت نشد']);exit;}
    $g=$pdo->prepare("SELECT group_name,CAST(price AS DECIMAL(10,2)) as price FROM reseller_groups WHERE reseller_id=? ORDER BY group_name");
    $g->execute([$rid]);
    echo json_encode(['ok'=>true,'r'=>$rD,'groups'=>$g->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// AJAX: گزارش ریسلر
if (isset($_GET['ajax']) && $_GET['ajax']==='report') {
    header('Content-Type: application/json');
    $rid  = (int)($_GET['rid']??0);
    $from = sanitize($_GET['from']??date('Y-m-01'));
    $to   = sanitize($_GET['to']  ??date('Y-m-d'));
    $toEnd = $to.' 23:59:59';
    // آمار
    $created = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE reseller_id=? AND type='user_create' AND created_at BETWEEN ? AND ?");
    $created->execute([$rid,$from.' 00:00:00',$toEnd]); $created=$created->fetchColumn();
    $renewed = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE reseller_id=? AND type='user_renew' AND created_at BETWEEN ? AND ?");
    $renewed->execute([$rid,$from.' 00:00:00',$toEnd]); $renewed=$renewed->fetchColumn();
    $totalSpent = $pdo->prepare("SELECT SUM(amount) FROM transactions WHERE reseller_id=? AND type IN('user_create','user_renew') AND created_at BETWEEN ? AND ?");
    $totalSpent->execute([$rid,$from.' 00:00:00',$toEnd]); $totalSpent=$totalSpent->fetchColumn()??0;
    $totalCharged = $pdo->prepare("SELECT SUM(amount) FROM transactions WHERE reseller_id=? AND type='charge' AND created_at BETWEEN ? AND ?");
    $totalCharged->execute([$rid,$from.' 00:00:00',$toEnd]); $totalCharged=$totalCharged->fetchColumn()??0;
    $r=$pdo->prepare("SELECT balance,debt FROM resellers WHERE id=?"); $r->execute([$rid]); $rd=$r->fetch();
    echo json_encode(['ok'=>true,'created'=>$created,'renewed'=>$renewed,'spent'=>$totalSpent,'charged'=>$totalCharged,'balance'=>$rd['balance']??0,'debt'=>$rd['debt']??0]);
    exit;
}

// AJAX: کاربران ISP
if (isset($_GET['ajax']) && $_GET['ajax']==='isp_users') {
    header('Content-Type: application/json');
    $isp=sanitize($_GET['isp']??'');
    if(!$isp){echo json_encode(['total'=>0,'rows'=>[]]);exit;}
    $r=ibsng_call('user.searchUser',['conds'=>ibsng_ispCond($isp),'from'=>0,'to'=>300,'order_by'=>'user_id','desc'=>true]);
    $total=$r['result'][0]??0; $uids=$r['result'][2]??[];
    $rows=[];
    if(!empty($uids)){
        $inf=ibsng_call('user.getUserInfo',['user_id'=>implode(',',$uids)]);
        $infos=$inf['result']??[];
        foreach($uids as $uid){
            $u=$infos[$uid]??[]; $basic=$u['basic_info']??[]; $attrs=$u['attrs']??[];
            $exp=$basic['nearest_exp_date']??'';
            $rows[]=['id'=>$uid,'username'=>$attrs['normal_username']??'—','status'=>$basic['status']??'—','group'=>$basic['group_name']??'—','online'=>$u['online_status']??false,'exp'=>$exp?substr($exp,0,10):'—'];
        }
    }
    echo json_encode(['total'=>$total,'rows'=>$rows]);
    exit;
}

$resellers=$pdo->query("SELECT r.*,(SELECT COUNT(*) FROM users u WHERE u.reseller_id=r.id) uc,(SELECT COUNT(*) FROM reseller_groups rg WHERE rg.reseller_id=r.id) gc FROM resellers r ORDER BY r.created_at DESC")->fetchAll();
$pendingCount=$pdo->query("SELECT COUNT(*) FROM payment_requests WHERE status='pending'")->fetchColumn();

// وضعیت فعلی شناسه‌ی عددی هر ISP - برای نمایش توی مودال «مدیریت شناسه ISP».
// اگه اتوماتیک/دستی پیدا شده، عددشو نشون می‌ده؛ اگه نه، فیلد خالی برای ثبت دستی.
$ispIdManualMap = ibsng_getIspIdManualMap();
$ispIdStatus = [];
foreach ($ibsIsps as $ispEach) {
    $ispIdStatus[$ispEach] = [
        'id'       => ibsng_getIspId($ispEach),
        'isManual' => isset($ispIdManualMap[$ispEach]),
    ];
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>مدیریت ریسلرها</title>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#080c18;--sb:#0d1424;--card:#131e30;--surf:#0d1a2a;--bor:#1e2d45;--acc:#3b82f6;--acc2:#06b6d4;--pur:#8b5cf6;--txt:#e2e8f0;--txt2:#94a3b8;--muted:#475569;--red:#ef4444;--grn:#10b981;--yel:#f59e0b;--sw:260px}
body{font-family:'Vazirmatn',sans-serif;background:var(--bg);color:var(--txt);display:flex;min-height:100vh}
aside{width:var(--sw);background:var(--sb);border-left:1px solid var(--bor);position:fixed;right:0;top:0;bottom:0;display:flex;flex-direction:column;z-index:100}
@media(max-width:768px){aside{transform:translateX(100%);transition:.3s} aside.open{transform:none} .overlay{display:block!important}}
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99}
.hamburger{display:none;position:fixed;top:14px;right:14px;z-index:101;background:var(--sb);border:1px solid var(--bor);border-radius:9px;padding:8px 10px;cursor:pointer;color:var(--txt);font-size:18px}
@media(max-width:768px){.hamburger{display:block} main{margin-right:0!important}}
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
.bp:hover{transform:translateY(-1px)}
.bpu{background:rgba(139,92,246,.15);color:#a78bfa;border:1px solid rgba(139,92,246,.3)}
.bc{background:rgba(6,182,212,.15);color:#22d3ee;border:1px solid rgba(6,182,212,.3)}
.bg{background:rgba(16,185,129,.15);color:var(--grn);border:1px solid rgba(16,185,129,.3)}
.bd{background:rgba(239,68,68,.1);color:var(--red);border:1px solid rgba(239,68,68,.3)}
.by{background:rgba(245,158,11,.1);color:var(--yel);border:1px solid rgba(245,158,11,.3)}
.bsm{padding:5px 9px;font-size:12px;border-radius:7px}
.toolbar{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;align-items:center}
.si{flex:1;min-width:180px;padding:9px 13px;background:var(--card);border:1px solid var(--bor);border-radius:9px;color:var(--txt);font-family:'Vazirmatn';font-size:13px;outline:none}
.si:focus{border-color:var(--acc)}
.card{background:var(--card);border:1px solid var(--bor);border-radius:12px;overflow:hidden}
.tw{overflow-x:auto}
table.t{width:100%;border-collapse:collapse;min-width:700px}
table.t th{text-align:right;font-size:11px;font-weight:600;color:var(--muted);padding:10px 12px;border-bottom:1px solid var(--bor);background:rgba(0,0,0,.2);white-space:nowrap}
table.t td{padding:10px 12px;font-size:13px;border-bottom:1px solid rgba(30,45,69,.5);color:var(--txt2);vertical-align:middle}
table.t tr:last-child td{border-bottom:none}
.badge{display:inline-block;padding:3px 8px;border-radius:20px;font-size:11px;font-weight:600}
.bok{background:rgba(16,185,129,.1);color:var(--grn);border:1px solid rgba(16,185,129,.2)}
.ber{background:rgba(239,68,68,.1);color:var(--red);border:1px solid rgba(239,68,68,.2)}
.bbl{background:rgba(59,130,246,.1);color:var(--acc);border:1px solid rgba(59,130,246,.2)}
.bpu2{background:rgba(139,92,246,.1);color:#a78bfa;border:1px solid rgba(139,92,246,.2)}
.bwa{background:rgba(245,158,11,.1);color:var(--yel);border:1px solid rgba(245,158,11,.2)}
.acts{display:flex;gap:4px;flex-wrap:wrap}
/* Modal */
.mbg{position:fixed;inset:0;background:rgba(0,0,0,.78);backdrop-filter:blur(4px);z-index:200;display:none;align-items:center;justify-content:center;padding:12px;overflow-y:auto}
.mbg.open{display:flex}
.modal{background:var(--card);border:1px solid var(--bor);border-radius:16px;width:100%;overflow:hidden;max-height:96vh;overflow-y:auto;position:relative}
.msm{max-width:480px}
.mlg{max-width:780px}
.mh{padding:16px 20px;border-bottom:1px solid var(--bor);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:var(--card);z-index:1}
.mt{font-size:15px;font-weight:700}
.mc{background:none;border:none;color:var(--muted);font-size:20px;cursor:pointer;line-height:1;padding:0}
.mb{padding:20px}
.mf{padding:12px 20px;border-top:1px solid var(--bor);display:flex;gap:8px;justify-content:flex-end;position:sticky;bottom:0;background:var(--card)}
.fg{margin-bottom:12px}
.lbl{display:block;font-size:12px;font-weight:600;color:var(--txt2);margin-bottom:5px}
.lh{font-size:11px;color:var(--muted);font-weight:400;margin-top:3px;display:block}
.fr{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media(max-width:480px){.fr{grid-template-columns:1fr}}
input[type=text],input[type=password],input[type=number],input[type=date],select{width:100%;padding:9px 12px;background:var(--surf);border:1px solid var(--bor);border-radius:8px;color:var(--txt);font-family:'Vazirmatn';font-size:13px;outline:none;transition:border .2s}
input:focus,select:focus{border-color:var(--acc)}
.trow{display:flex;align-items:center;justify-content:space-between;padding:11px 13px;background:rgba(255,255,255,.02);border:1px solid var(--bor);border-radius:8px;margin-bottom:7px}
.tlbl{font-size:13px;font-weight:600}
.tdsc{font-size:11px;color:var(--muted);margin-top:2px}
.sw{position:relative;width:42px;height:22px;flex-shrink:0}
.sw input{opacity:0;width:0;height:0}
.sl{position:absolute;cursor:pointer;inset:0;background:rgba(255,255,255,.12);border-radius:22px;transition:.3s}
.sl:before{content:'';position:absolute;width:16px;height:16px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.3s}
.sw input:checked+.sl{background:var(--grn)}
.sw input:checked+.sl:before{transform:translateX(20px)}
.sec{font-size:12px;font-weight:700;color:var(--txt);margin:16px 0 8px;padding-bottom:7px;border-bottom:1px solid var(--bor)}
.gtw{max-height:320px;overflow-y:auto;border:1px solid var(--bor);border-radius:8px}
table.gt{width:100%;border-collapse:collapse}
table.gt th{text-align:right;font-size:11px;color:var(--muted);padding:8px 11px;border-bottom:1px solid var(--bor);background:rgba(0,0,0,.2);font-weight:600;position:sticky;top:0}
table.gt td{padding:8px 11px;border-bottom:1px solid rgba(30,45,69,.3);vertical-align:middle}
table.gt tr:last-child td{border-bottom:none}
table.gt tr:hover td{background:rgba(59,130,246,.03)}
.gcb{width:15px;height:15px;cursor:pointer;accent-color:var(--acc)}
.pi{width:130px!important;padding:6px 9px!important;font-size:12px!important}
.gact{margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.gcnt{font-size:12px;color:var(--muted)}
/* isp table */
.it{width:100%;border-collapse:collapse;font-size:12px}
.it th{text-align:right;padding:7px 11px;background:rgba(0,0,0,.2);border-bottom:1px solid var(--bor);color:var(--muted);font-weight:600}
.it td{padding:8px 11px;border-bottom:1px solid rgba(30,45,69,.4)}
.od{display:inline-block;width:6px;height:6px;background:var(--grn);border-radius:50%;margin-left:4px;animation:bk 2s infinite}
@keyframes bk{0%,100%{opacity:1}50%{opacity:.3}}
/* report */
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:14px}
.stat-card{background:rgba(255,255,255,.03);border:1px solid var(--bor);border-radius:10px;padding:12px;text-align:center}
.stat-val{font-size:22px;font-weight:900;margin-bottom:2px}
.stat-lbl{font-size:11px;color:var(--muted)}
</style>
</head>
<body>
<div class="overlay" id="overlay" onclick="closeSidebar()"></div>
<button class="hamburger" onclick="toggleSidebar()" title="منو">☰</button>

<aside id="sidebar">
    <div class="logo">
    <?php $siteLogo=getSetting('site_logo',''); if($siteLogo&&file_exists(dirname(__DIR__).'/'.$siteLogo)): ?>
    <img src="../<?=sanitize($siteLogo)?>" alt="لوگو" style="max-height:52px;max-width:180px;object-fit:contain;margin-bottom:4px;display:block">
    <?php else: ?>
    <div class="logo-t">⚡ پنل مدیریت</div>
    <?php endif; ?>
    <div class="logo-b">سیستم مدیریت اینترنت</div>
  </div>
  <nav>
    <div class="ns">اصلی</div>
    <a href="dashboard.php" class="ni">📊 داشبورد</a>
    <div class="ns">مدیریت</div>
    <a href="resellers.php" class="ni active">👥 ریسلرها</a>
    <a href="users.php" class="ni">🧑‍💻 کاربران</a>
    <a href="online.php" class="ni">🟢 کاربران آنلاین</a>
    <div class="ns">مالی</div>
    <a href="transactions.php" class="ni">💳 تراکنش‌ها</a>
    <a href="debts.php" class="ni">💰 مدیریت موجودی</a>
    <a href="payments.php" class="ni">🧾 فیش پرداخت <?php if($pendingCount>0):?><span class="pb"><?=$pendingCount?></span><?php endif;?></a>
    <div class="ns">فروش مستقیم تلگرام</div>
    <a href="direct_packages.php" class="ni">📦 بسته‌های فروش مستقیم</a>
    <a href="direct_orders.php" class="ni">🛒 سفارش‌های مستقیم</a>
    <a href="telegram.php" class="ni">🤖 ربات تلگرام</a>
    <div class="ns">سیستم</div>
    <a href="settings.php" class="ni">⚙️ تنظیمات</a>
    <a href="logs.php" class="ni">📋 لاگ‌ها</a>
  </nav>
  <div class="sf">
    <div class="ai">
      <div class="av">🛡️</div>
      <div>
        <div style="font-size:13px;font-weight:600"><?=$_SESSION['admin_username']?></div>
        <div style="font-size:11px;color:var(--muted)">مدیر اصلی</div>
      </div>
    </div>
    <a href="logout.php" class="ni logout">🚪 خروج</a>
  </div>
</aside>

<main>
  <div class="topbar">
    <div class="pg-title">👥 مدیریت ریسلرها</div>
    <div style="display:flex;gap:7px;flex-wrap:wrap">
      <button class="btn bc" onclick="openM('ispM')">🌐 کاربران ISP</button>
      <button class="btn bc" onclick="openM('ispIdM')">🔧 شناسه ISP</button>
      <button class="btn bp" onclick="openM('addM')">➕ ریسلر جدید</button>
    </div>
  </div>
  <div class="content">
    <?php if($success):?><div class="alert a-ok">✅ <?=$success?></div><?php endif;?>
    <?php if($error):?><div class="alert a-err">❌ <?=$error?></div><?php endif;?>
    <div class="toolbar">
      <input type="text" class="si" placeholder="🔍 جستجو نام کاربری..." oninput="ft(this.value)">
      <span style="font-size:12px;color:var(--muted)"><?=count($resellers)?> ریسلر</span>
    </div>
    <div class="card">
      <div class="tw">
        <table class="t" id="rtbl">
          <thead><tr>
            <th>#</th><th>ریسلر</th><th>ISP</th><th>گروه‌ها</th>
            <th>کاربران</th><th>موجودی</th><th>حذف</th><th>تمدید</th>
            <th>وضعیت</th><th>عملیات</th>
          </tr></thead>
          <tbody>
          <?php foreach($resellers as $i=>$r):?>
          <tr>
            <td style="color:var(--muted)"><?=$i+1?></td>
            <td>
              <div style="font-weight:700;color:var(--txt)"><?=sanitize($r['username'])?></div>
              <?php if($r['full_name']):?><div style="font-size:11px;color:var(--muted)"><?=sanitize($r['full_name'])?></div><?php endif;?>
              <?php if($r['phone']):?><div style="font-size:11px;color:var(--muted)"><?=sanitize($r['phone'])?></div><?php endif;?>
            </td>
            <td><?=$r['isp_name']?'<span class="badge bbl">'.sanitize($r['isp_name']).'</span>':'<span class="badge bwa">⚠️ نداره</span>'?></td>
            <td><?=$r['gc']>0?'<span class="badge bpu2">'.$r['gc'].' گروه</span>':'<span class="badge bwa">⚠️ ندارد</span>'?></td>
            <td style="font-weight:700;color:var(--txt)"><?=$r['uc']?></td>
            <td style="font-weight:700;color:#34d399"><?=number_format($r['balance']??0)?> ت</td>
            <td><?=$r['can_delete_users']?'<span class="badge bok">✅</span>':'<span class="badge ber">❌</span>'?></td>
            <td><?=($r['can_renew_users']??1)?'<span class="badge bok">✅</span>':'<span class="badge ber">❌</span>'?></td>
            <td><span class="badge <?=$r['status']==='active'?'bok':'ber'?>"><?=$r['status']==='active'?'فعال':'غیرفعال'?></span></td>
            <td>
              <div class="acts">
                <button class="btn bpu bsm" onclick="openSet(<?=$r['id']?>)" title="تنظیمات ریسلر">⚙️</button>
                <button class="btn bc bsm" onclick="openReport(<?=$r['id']?>,'<?=sanitize($r['username'])?>')" title="گزارش فروش">📊</button>
                <form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
                  <input type="hidden" name="action" value="toggle_status">
                  <input type="hidden" name="reseller_id" value="<?=$r['id']?>">
                  <button type="submit" class="btn <?=$r['status']==='active'?'bd':'bg'?> bsm" title="<?=$r['status']==='active'?'غیرفعال کردن':'فعال کردن'?>"><?=$r['status']==='active'?'⏸':'▶'?></button>
                </form>
                <button class="btn bd bsm" onclick="cDel(<?=$r['id']?>,'<?=sanitize($r['username'])?>')" title="حذف ریسلر">🗑</button>
              </div>
            </td>
          </tr>
          <?php endforeach;?>
          <?php if(empty($resellers)):?>
          <tr><td colspan="10" style="text-align:center;padding:40px;color:var(--muted)">هیچ ریسلری ثبت نشده</td></tr>
          <?php endif;?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>

<!-- افزودن ریسلر -->
<div class="mbg" id="addM">
  <div class="modal msm">
    <div class="mh"><div class="mt">➕ ریسلر جدید</div><button class="mc" onclick="closeM('addM')" title="بستن">✕</button></div>
    <form method="POST"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
      <input type="hidden" name="action" value="add_reseller">
      <div class="mb">
        <div class="fr">
          <div class="fg"><label class="lbl">نام کاربری *</label><input type="text" name="username" required placeholder="milad1"></div>
          <div class="fg"><label class="lbl">رمز عبور *</label><input type="password" name="password" required placeholder="••••••••"></div>
        </div>
        <div class="fr">
          <div class="fg"><label class="lbl">نام کامل</label><input type="text" name="full_name" placeholder="میلاد احمدی"></div>
          <div class="fg"><label class="lbl">تلفن</label><input type="text" name="phone" placeholder="09xxxxxxxxx"></div>
        </div>
        <div class="fg">
          <label class="lbl">ISP اختصاصی در  <span class="lh">ریسلر فقط کاربران این ISP را می‌بیند</span></label>
          <select name="isp_name">
            <option value="">— بدون محدودیت —</option>
            <?php foreach($ibsIsps as $isp):?><option value="<?=sanitize($isp)?>"><?=sanitize($isp)?></option><?php endforeach;?>
          </select>
        </div>
      </div>
      <div class="mf">
        <button type="button" class="btn bd" onclick="closeM('addM')">انصراف</button>
        <button type="submit" class="btn bp">✅ ایجاد</button>
      </div>
    </form>
  </div>
</div>

<!-- تنظیمات ریسلر -->
<div class="mbg" id="setM">
  <div class="modal mlg">
    <div class="mh">
      <div class="mt">⚙️ تنظیمات: <span id="setName" style="color:var(--acc)">...</span></div>
      <button class="mc" onclick="closeM('setM')" title="بستن">✕</button>
    </div>
    <form method="POST" id="setForm"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
      <input type="hidden" name="action" value="update_settings">
      <input type="hidden" name="reseller_id" id="setRid">
      <div class="mb">
        <div class="sec">🌐 ISP اختصاصی</div>
        <div class="fg">
          <select name="isp_name" id="setIsp">
            <option value="">— بدون محدودیت —</option>
            <?php foreach($ibsIsps as $isp):?><option value="<?=sanitize($isp)?>"><?=sanitize($isp)?></option><?php endforeach;?>
          </select>
        </div>
        <div class="sec">🔐 دسترسی‌ها</div>
        <div class="trow">
          <div><div class="tlbl">🗑 حذف کاربر</div><div class="tdsc">ریسلر می‌تواند کاربران خود را حذف کند</div></div>
          <label class="sw"><input type="checkbox" name="can_delete" id="setDel"><span class="sl"></span></label>
        </div>
        <div class="trow">
          <div><div class="tlbl">🔄 تمدید کاربر</div><div class="tdsc">ریسلر می‌تواند سرویس کاربران را تمدید کند</div></div>
          <label class="sw"><input type="checkbox" name="can_renew" id="setRenew"><span class="sl"></span></label>
        </div>
        <div class="sec">📦 گروه‌های مجاز و قیمت</div>
        <p style="font-size:11px;color:var(--muted);margin-bottom:10px">گروه‌ها را تیک بزنید و قیمت هر کاربر (تومان) را وارد کنید. قیمت ۰ = قیمت پیش‌فرض.</p>
        <div class="gtw">
          <table class="gt">
            <thead><tr><th style="width:36px">✓</th><th>نام گروه</th><th>قیمت هر کاربر (تومان)</th></tr></thead>
            <tbody>
              <?php foreach($ibsGroups as $g): $gs=sanitize($g);?>
              <tr>
                <td><input type="checkbox" class="gcb" name="groups[]" value="<?=$gs?>" id="g_<?=$gs?>"></td>
                <td><label for="g_<?=$gs?>" style="cursor:pointer;font-weight:600;color:var(--txt)"><?=$gs?></label></td>
                <td><input type="number" name="group_prices[<?=$gs?>]" id="p_<?=$gs?>" class="pi" placeholder="0" min="0" step="1000" value="0"></td>
              </tr>
              <?php endforeach;?>
            </tbody>
          </table>
        </div>
        <div class="gact">
          <button type="button" class="btn bc bsm" onclick="allGrp(true)">✓ همه</button>
          <button type="button" class="btn bd bsm" onclick="allGrp(false)">✕ هیچ</button>
          <span class="gcnt" id="gcnt"></span>
        </div>
      </div>
      <div class="mf">
        <button type="button" class="btn bd" onclick="closeM('setM')">انصراف</button>
        <button type="submit" class="btn bp">💾 ذخیره</button>
      </div>
    </form>
  </div>
</div>

<!-- گزارش ریسلر -->
<div class="mbg" id="repM">
  <div class="modal msm">
    <div class="mh"><div class="mt">📊 گزارش: <span id="repName" style="color:var(--acc)"></span></div><button class="mc" onclick="closeM('repM')" title="بستن">✕</button></div>
    <div class="mb">
      <div class="fr" style="margin-bottom:12px">
        <div class="fg"><label class="lbl">از تاریخ</label><input type="date" id="repFrom" value="<?=date('Y-m-01')?>"></div>
        <div class="fg"><label class="lbl">تا تاریخ</label><input type="date" id="repTo" value="<?=date('Y-m-d')?>"></div>
      </div>
      <button class="btn bp" style="width:100%;justify-content:center" onclick="loadReport()">🔍 نمایش گزارش</button>
      <div id="repRes" style="margin-top:14px"></div>
    </div>
  </div>
</div>

<!-- کاربران ISP -->
<div class="mbg" id="ispM">
  <div class="modal mlg">
    <div class="mh"><div class="mt">🌐 کاربران ISP</div><button class="mc" onclick="closeM('ispM')" title="بستن">✕</button></div>
    <div class="mb">
      <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">
        <select id="ispSel" style="flex:1;min-width:160px;padding:9px 12px;background:var(--surf);border:1px solid var(--bor);border-radius:8px;color:var(--txt);font-family:'Vazirmatn';font-size:13px;outline:none">
          <option value="">انتخاب ISP...</option>
          <?php foreach($ibsIsps as $isp):?><option value="<?=sanitize($isp)?>"><?=sanitize($isp)?></option><?php endforeach;?>
        </select>
        <button class="btn bp" onclick="loadIsp()">🔍 نمایش</button>
      </div>
      <div id="ispRes"></div>
    </div>
  </div>
</div>

<!-- مدیریت شناسه عددی ISP (isp_id) -->
<div class="mbg" id="ispIdM">
  <div class="modal mlg">
    <div class="mh"><div class="mt">🔧 مدیریت شناسه‌ی عددی ISP</div><button class="mc" onclick="closeM('ispIdM')" title="بستن">✕</button></div>
    <div class="mb">
      <p style="font-size:12px;color:var(--muted);margin-bottom:14px">
        فیلتر کردن کاربرهای هر ISP توی IBSng از روی یک شناسه‌ی عددی (isp_id) انجام می‌شه، نه اسمش.
        این عدد معمولاً به‌صورت خودکار پیدا می‌شه؛ ولی اگه برای یک ISP «ثبت‌نشده» بود، می‌تونی
        عدد Admin ID اونو از پنل اصلی IBSng (بخش Admin Information همون ادمین/ISP) پیدا کنی و
        اینجا دستی ثبت کنی تا فیلترش همیشه درست کار کنه.
      </p>
      <div class="tw">
        <table class="t">
          <thead><tr><th>ISP</th><th>isp_id فعلی</th><th>منبع</th><th>ثبت/ویرایش دستی</th><th></th></tr></thead>
          <tbody>
          <?php foreach($ispIdStatus as $ispEach=>$st):?>
          <tr>
            <td><span class="badge bbl"><?=sanitize($ispEach)?></span></td>
            <td><?=$st['id']!==null?'<b>'.(int)$st['id'].'</b>':'<span class="badge bwa">⚠️ ثبت‌نشده</span>'?></td>
            <td style="font-size:11px;color:var(--muted)"><?=$st['id']===null?'—':($st['isManual']?'دستی':'خودکار')?></td>
            <td>
              <form method="POST" style="display:flex;gap:6px" onsubmit="return true;">
                <input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
                <input type="hidden" name="action" value="set_isp_id">
                <input type="hidden" name="isp_name" value="<?=sanitize($ispEach)?>">
                <input type="number" name="isp_id" min="1" placeholder="مثلاً 6" value="<?=$st['isManual']?(int)$st['id']:''?>" style="width:90px;padding:6px 8px;background:var(--surf);border:1px solid var(--bor);border-radius:6px;color:var(--txt);font-size:12px">
                <button type="submit" class="btn bp" style="padding:6px 10px;font-size:12px">ذخیره</button>
              </form>
            </td>
            <td>
              <?php if($st['isManual']):?>
              <form method="POST" onsubmit="return confirm('نگاشت دستی این ISP پاک بشه؟');">
                <input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
                <input type="hidden" name="action" value="clear_isp_id">
                <input type="hidden" name="isp_name" value="<?=sanitize($ispEach)?>">
                <button type="submit" class="btn bd" style="padding:6px 10px;font-size:12px">حذف نگاشت</button>
              </form>
              <?php endif;?>
            </td>
          </tr>
          <?php endforeach;?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<form method="POST" id="delf"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
  <input type="hidden" name="action" value="delete_reseller">
  <input type="hidden" name="reseller_id" id="dId">
</form>

<script>
let curRepRid=0;
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');document.getElementById('overlay').style.display='block'}
function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('overlay').style.display='none'}
function openM(id){document.getElementById(id).classList.add('open')}
function closeM(id){document.getElementById(id).classList.remove('open')}
document.querySelectorAll('.mbg').forEach(b=>b.addEventListener('click',e=>{if(e.target===b)b.classList.remove('open')}));
function ft(q){q=q.toLowerCase();document.querySelectorAll('#rtbl tbody tr').forEach(r=>r.style.display=r.textContent.toLowerCase().includes(q)?'':'none')}
function cDel(id,n){if(confirm('حذف ریسلر "'+n+'"؟')){document.getElementById('dId').value=id;document.getElementById('delf').submit();}}
function allGrp(v){document.querySelectorAll('.gcb').forEach(c=>c.checked=v);updCnt()}
function updCnt(){document.getElementById('gcnt').textContent=document.querySelectorAll('.gcb:checked').length+' گروه انتخاب شده'}
document.querySelectorAll('.gcb').forEach(c=>c.addEventListener('change',updCnt));

function openSet(rid){
  document.querySelectorAll('.gcb').forEach(c=>{c.checked=false;const p=document.getElementById('p_'+c.value);if(p)p.value=0;});
  document.getElementById('setDel').checked=false;
  document.getElementById('setRenew').checked=true;
  document.getElementById('setName').textContent='...';
  document.getElementById('setRid').value=rid;
  openM('setM');
  fetch('resellers.php?ajax=get&rid='+rid)
    .then(r=>{if(!r.ok)throw new Error('HTTP '+r.status);return r.json();})
    .then(d=>{
      if(!d.ok){alert('خطا: '+d.msg);closeM('setM');return;}
      document.getElementById('setName').textContent=d.r.username;
      document.getElementById('setIsp').value=d.r.isp_name||'';
      document.getElementById('setDel').checked=(d.r.can_delete_users==1);
      document.getElementById('setRenew').checked=(d.r.can_renew_users!=0);
      d.groups.forEach(g=>{
        const cb=document.getElementById('g_'+g.group_name);
        if(cb){cb.checked=true;const pi=document.getElementById('p_'+g.group_name);if(pi)pi.value=parseFloat(g.price)||0;}
      });
      updCnt();
    })
    .catch(e=>{alert('خطا: '+e.message);closeM('setM');});
}

function openReport(rid,name){
  curRepRid=rid;
  document.getElementById('repName').textContent=name;
  document.getElementById('repRes').innerHTML='';
  openM('repM');
}
function loadReport(){
  const from=document.getElementById('repFrom').value;
  const to=document.getElementById('repTo').value;
  document.getElementById('repRes').innerHTML='<div style="text-align:center;padding:20px;color:var(--muted)">⏳ در حال بارگذاری...</div>';
  fetch(`resellers.php?ajax=report&rid=${curRepRid}&from=${from}&to=${to}`)
    .then(r=>r.json()).then(d=>{
      document.getElementById('repRes').innerHTML=`
        <div class="stat-grid">
          <div class="stat-card"><div class="stat-val" style="color:var(--acc)">${d.created}</div><div class="stat-lbl">کاربر ساخته شده</div></div>
          <div class="stat-card"><div class="stat-val" style="color:var(--grn)">${d.renewed}</div><div class="stat-lbl">تمدید انجام شده</div></div>
          <div class="stat-card"><div class="stat-val" style="color:var(--yel)">${parseInt(d.spent).toLocaleString()}</div><div class="stat-lbl">هزینه (تومان)</div></div>
          <div class="stat-card"><div class="stat-val" style="color:#34d399">${parseInt(d.charged).toLocaleString()}</div><div class="stat-lbl">شارژ دریافتی</div></div>
          <div class="stat-card"><div class="stat-val" style="color:var(--grn)">${parseInt(d.balance).toLocaleString()}</div><div class="stat-lbl">موجودی فعلی</div></div>
          <div class="stat-card"><div class="stat-val" style="color:var(--red)">${parseInt(d.debt).toLocaleString()}</div><div class="stat-lbl">بدهی</div></div>
        </div>`;
    });
}

function loadIsp(){
  const isp=document.getElementById('ispSel').value;
  if(!isp){alert('یک ISP انتخاب کنید');return;}
  document.getElementById('ispRes').innerHTML='<div style="text-align:center;padding:20px;color:var(--muted)">⏳ در حال بارگذاری...</div>';
  fetch('resellers.php?ajax=isp_users&isp='+encodeURIComponent(isp))
    .then(r=>r.json()).then(d=>{
      if(!d.rows.length){document.getElementById('ispRes').innerHTML='<div style="text-align:center;padding:20px;color:var(--muted)">هیچ کاربری یافت نشد</div>';return;}
      const ol=d.rows.filter(u=>u.online).length;
      document.getElementById('ispRes').innerHTML=`
        <div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap">
          <span class="badge bbl">👥 ${d.total}</span>
          <span class="badge bok">🟢 ${ol}</span>
        </div>
        <div style="max-height:400px;overflow-y:auto;border:1px solid var(--bor);border-radius:8px">
        <table class="it">
          <thead><tr><th>نام کاربری</th><th>وضعیت</th><th>گروه</th><th>انقضا</th></tr></thead>
          <tbody>${d.rows.map(u=>`<tr>
            <td>${u.online?'<span class="od"></span>':''}<strong style="color:var(--txt)">${u.username}</strong></td>
            <td><span style="font-size:11px;color:${u.status==='Active'?'var(--grn)':'var(--red)'}">${u.status}</span></td>
            <td style="font-size:11px">${u.group}</td>
            <td style="font-size:11px">${u.exp}</td>
          </tr>`).join('')}</tbody>
        </table></div>`;
    });
}
</script>
</body>
</html>
