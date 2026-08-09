<?php
require_once '../includes/config.php';
require_once '../includes/ibsng_api.php';
requireAdmin();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die('درخواست نامعتبر است (احتمالاً صفحه قدیمی شده). لطفاً صفحه را رفرش کرده و دوباره امتحان کنید.');
}

function generatePassword($type,$len){
    $l='abcdefghijkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ';
    $d='23456789';
    $pool=match($type){'c','letters'=>$l,'n','digits'=>$d,default=>$l.$d};
    $p='';for($i=0;$i<$len;$i++)$p.=$pool[random_int(0,strlen($pool)-1)];return $p;
}

// تبدیل الگوی «PREFIX{شروع-پایان}SUFFIX» به لیست یوزرنیم؛ مثلاً TRR{01-20} => TRR01..TRR20
// عرض صفر-پرشدن از تعداد رقم‌های شروع/پایان نوشته‌شده در الگو گرفته می‌شود.
function expandBulkPattern($pattern){
    if(!preg_match('/^(.*)\{(\d+)-(\d+)\}(.*)$/',trim($pattern),$m)) return null;
    [, $pre, $sStr, $eStr, $post]=$m;
    $start=(int)$sStr; $end=(int)$eStr;
    if($end<$start){$t=$start;$start=$end;$end=$t;}
    $width=max(strlen($sStr),strlen($eStr));
    $count=min(50,$end-$start+1);
    $out=[];
    for($i=0;$i<$count;$i++) $out[]=$pre.str_pad((string)($start+$i),$width,'0',STR_PAD_LEFT).$post;
    return $out;
}

$error=$_GET['error']??'';$success=$_GET['success']??'';
$action=$_GET['action']??'';$user_id=$_GET['uid']??'';
$bulkResult=$_SESSION['bulk_result']??[];unset($_SESSION['bulk_result']);

// AJAX: لیست کاربران
if(isset($_GET['ajax'])&&$_GET['ajax']==='list'){
    header('Content-Type: application/json');
    $search =trim($_GET['search']??'');
    $grpF   =trim($_GET['group'] ??'');
    $ispF   =trim($_GET['isp']   ??'');
    $rasF   =trim($_GET['ras']   ??'');
    $sortBy =trim($_GET['sort']  ??'');
    $sortDir=($_GET['dir']??'desc')==='asc'?'asc':'desc';
    $page   =max(0,(int)($_GET['page']??0));
    $perPage=50;
    // اگه جدول کش محلی (ibsng_users_cache) تازه‌ست (کرون sync_ibsng_users.php هر
    // چند دقیقه اجرا می‌شه)، همه‌ی حالت‌های فیلتر/سرچ/ RAS رو با یک کوئری SQL سریع
    // و بدون هیچ تماسی با IBSng جواب می‌دیم؛ فقط وقتی کش خالی/قدیمی باشه (مثلاً
    // کرون هنوز اجرا نشده) به منطق قدیمیِ زیر (زنده از IBSng) برمی‌گردیم.
    if (ibsng_dbCacheFresh($pdo) && ibsng_dbCacheHasIsp($pdo, $ispF)) {
        echo json_encode(ibsng_dbCacheSearch($pdo, $ispF, $grpF, $search, $rasF, $sortBy, $sortDir, $page, $perPage));
        exit;
    }

    // وضعیت آنلاین واقعی: online_status توی getUserInfo وقتی صدها کاربر یک‌جا
    // (bulk) خونده می‌شه همیشه false برمی‌گرده، پس از لیست واقعیِ آنلاین‌ها می‌گیریم.
    $onlineSetGlobal = ibsng_getOnlineUsernameSet('');

    // اگر ISP یا search داریم → از کش استفاده کن یا pagination بزن
    if($ispF!==''||$search!==''){
        $conds=[];
        if($grpF!=='') $conds['group_name']=$grpF;
        if($ispF!=='') $conds=array_merge($conds,ibsng_ispCond($ispF));

        // اگر فقط ISP (یا ISP+گروه) فیلتر است، بدون RAS/search/آنلاین: مستقیم همون
        // صفحه‌ی درخواستی رو از  می‌گیریم (دقیقاً مثل حالت بدون فیلتر پایین این فایل) -
        // قبلاً اینجا کل کاربرهای آن ISP (تا 15000+) طی ده‌ها request fetch می‌شد که
        // هم خیلی کند بود و هم می‌توانست منابع هاست را برای بقیه‌ی کاربران هم‌زمان
        // اشغال کند.
        if($ispF!==''&&$search===''&&$rasF===''){
            $r=ibsng_call('user.searchUser',['conds'=>$conds,'from'=>$page*$perPage,'to'=>($page+1)*$perPage,'order_by'=>'user_id','desc'=>true]);
            $total=(int)($r['result'][0]??0);
            $uids=$r['result'][2]??[];
            // isp_id این ISP گاهی (مخصوصاً برای ISPهای تازه‌ساز) درست resolve نمی‌شه
            // و نتیجه‌اش صفر کاربر کاذبه. وقتی صفره، یک‌بار مستقیم با اسم ISP (نه
            // شماره‌ی حدسی) از طریق نشست HTML امتحان می‌کنیم.
            if($total===0){
                $nativeUids=ibsng_getIspUidsNative($ispF);
                if(is_array($nativeUids)&&!empty($nativeUids)){
                    $total=count($nativeUids);
                    $uids=array_slice($nativeUids,$page*$perPage,$perPage);
                }
            }
            $rows=[];
            if(!empty($uids)){
                $inf=ibsng_call('user.getUserInfo',['user_id'=>implode(',',$uids)]);
                $infos=$inf['result']??[];
                foreach($uids as $uid){
                    $u=$infos[$uid]??($infos[(string)$uid]??null);if(!$u)continue;
                    $basic=$u['basic_info']??[];$attrs=$u['attrs']??[];
                    $exp=$basic['nearest_exp_date']??'';
                    $dL=null;if($exp&&$exp!==''){$et=strtotime($exp);if($et)$dL=(int)(($et-time())/86400);}
                    $ras=$basic['ras_ip_addr']??($attrs['ras_ip_addr']??'—');
                    $rows[]=['id'=>$uid,'username'=>$attrs['normal_username']??'—','password'=>$attrs['normal_password']??'—','status'=>$basic['status']??'—','group'=>$basic['group_name']??'—','isp'=>$basic['isp_name']??'—','ras'=>$ras,'exp'=>$exp?substr($exp,0,10):'∞','exp_ts'=>$exp?(strtotime($exp)?:0):0,'days_left'=>$dL,'online'=>isset($onlineSetGlobal[$attrs['normal_username']??'']),'credit'=>$basic['credit']??0];
                }
            }
            if(!empty($rows)&&in_array($sortBy,['username','group','isp','ras','exp','status'])){
                usort($rows,function($a,$b)use($sortBy,$sortDir){
                    $va=$sortBy==='exp'?($a['exp_ts']??0):strtolower($a[$sortBy]??'');
                    $vb=$sortBy==='exp'?($b['exp_ts']??0):strtolower($b[$sortBy]??'');
                    $cmp=is_numeric($va)?($va<=>$vb):strcmp($va,$vb);
                    return $sortDir==='asc'?$cmp:-$cmp;
                });
            }
            echo json_encode(['total'=>$total,'rows'=>$rows]);exit;
        }

        // جستجو: اول یک تلاش سریع با تطبیق مستقیم username روی سرور  (یک request).
        // فقط اگر جواب نداد (یعنی جستجوی جزئی/partial است یا فقط RAS فیلتر شده) کل
        // کاربرهای ISP/گروه fetch و در PHP فیلتر می‌شوند - این حالت کند است و فقط
        // وقتی واقعاً لازم باشد اجرا می‌شود.
        $filtered=[];
        $fastTried=false;
        if($search!==''&&$rasF===''){
            $fastTried=true;
            $fastConds=$conds;
            $fastConds['normal_username']=$search;
            $fastConds['normal_username_op']='like';
            $fr=ibsng_call('user.searchUser',['conds'=>$fastConds,'from'=>0,'to'=>200,'order_by'=>'user_id','desc'=>true]);
            $fastUids=$fr['result'][2]??[];
            if(!empty($fastUids)){
                $infosF=ibsng_getUserInfoBulk($fastUids);
                foreach($fastUids as $uid){
                    $u=$infosF[(string)$uid]??($infosF[$uid]??null);if(!$u)continue;
                    $basic=$u['basic_info']??[];$attrs=$u['attrs']??[];
                    $un=$attrs['normal_username']??$attrs['username']??'';
                    $uIsp=$basic['isp_name']??'';
                    if($ispF!==''&&$uIsp!==$ispF)continue;
                    $exp=$basic['nearest_exp_date']??'';
                    $dL=null;if($exp&&$exp!==''){$et=strtotime($exp);if($et)$dL=(int)(($et-time())/86400);}
                    $filtered[]=['id'=>$uid,'username'=>$un,'password'=>$attrs['normal_password']??'—','status'=>$basic['status']??'—','group'=>$basic['group_name']??'—','isp'=>$uIsp,'ras'=>$basic['ras_ip_addr']??($attrs['ras_ip_addr']??'—'),'exp'=>$exp?substr($exp,0,10):'∞','exp_ts'=>$exp?(strtotime($exp)?:0):0,'days_left'=>$dL,'online'=>isset($onlineSetGlobal[$un]),'credit'=>$basic['credit']??0];
                }
            }
        }

        // اگر جستجوی سریع (exact/prefix) چیزی پیدا نکرد و هیچ ISP انتخاب نشده، قبل از
        // اسکن کامل همه‌ی ~15000 کاربر (کند)، یک جستجوی هدفمند و سریع روی هر ISP
        // امتحان کن - همه‌ی ISPها رو هم‌زمان (نه پشت‌سرهم) صدا می‌زنیم تا زمانش
        // تقریباً فقط طول یک تماس بشه، نه جمع ۸ تماس پشت‌سرهم.
        if(empty($filtered)&&$search!==''&&$ispF===''&&$rasF===''){
            $isps=ibsng_getIsps();
            $searchCalls=[];
            foreach($isps as $i=>$ispTry){
                $tryConds=$conds;
                $tryConds=array_merge($tryConds,ibsng_ispCond($ispTry));
                $tryConds['normal_username']=$search;
                $tryConds['normal_username_op']='like';
                $searchCalls[$i]=['user.searchUser',['conds'=>$tryConds,'from'=>0,'to'=>200,'order_by'=>'user_id','desc'=>true]];
            }
            $searchResults=ibsng_callParallel($searchCalls);
            $uidIsp=[];$allTUids=[];
            foreach($isps as $i=>$ispTry){
                foreach($searchResults[$i]['result'][2]??[] as $uid){
                    $uidIsp[$uid]=$ispTry;$allTUids[]=$uid;
                }
            }
            if(!empty($allTUids)){
                $infT=ibsng_getUserInfoBulk($allTUids);
                foreach($allTUids as $uid){
                    $u=$infT[(string)$uid]??($infT[$uid]??null);if(!$u)continue;
                    $basic=$u['basic_info']??[];$attrs=$u['attrs']??[];
                    $un=$attrs['normal_username']??$attrs['username']??'';
                    $exp=$basic['nearest_exp_date']??'';
                    $dL=null;if($exp&&$exp!==''){$et=strtotime($exp);if($et)$dL=(int)(($et-time())/86400);}
                    $ispTry=$uidIsp[$uid]??'';
                    $filtered[]=['id'=>$uid,'username'=>$un,'password'=>$attrs['normal_password']??'—','status'=>$basic['status']??'—','group'=>$basic['group_name']??'—','isp'=>$basic['isp_name']??$ispTry,'ras'=>$basic['ras_ip_addr']??($attrs['ras_ip_addr']??'—'),'exp'=>$exp?substr($exp,0,10):'∞','exp_ts'=>$exp?(strtotime($exp)?:0):0,'days_left'=>$dL,'online'=>isset($onlineSetGlobal[$un]),'credit'=>$basic['credit']??0];
                }
            }
        }

        if(empty($filtered)){
            $allUIDs=ibsng_getAllUidsForIsp($conds);

            if(empty($allUIDs)){echo json_encode(['total'=>0,'rows'=>[]]);exit;}

            $infos2=ibsng_getUserInfoBulk($allUIDs);

            foreach($allUIDs as $uid){
                $u=$infos2[(string)$uid]??($infos2[$uid]??null);if(!$u)continue;
                $basic=$u['basic_info']??[];$attrs=$u['attrs']??[];
                $un=$attrs['normal_username']??$attrs['username']??'';
                if($un===''&&!empty($attrs)) foreach($attrs as $k=>$v) if(stripos($k,'username')!==false&&is_string($v)&&$v!==''){$un=$v;break;}
                $uIsp=$basic['isp_name']??'';
                if($ispF!==''&&$uIsp!==$ispF)continue;
                if($search!==''&&stripos($un,$search)===false)continue;
                $ras=$basic['ras_ip_addr']??($attrs['ras_ip_addr']??'—');
                if($rasF!==''&&stripos($ras,$rasF)===false)continue;
                $exp=$basic['nearest_exp_date']??'';
                $dL=null;if($exp&&$exp!==''){$et=strtotime($exp);if($et)$dL=(int)(($et-time())/86400);}
                $filtered[]=['id'=>$uid,'username'=>$un,'password'=>$attrs['normal_password']??'—','status'=>$basic['status']??'—','group'=>$basic['group_name']??'—','isp'=>$uIsp,'ras'=>$ras,'exp'=>$exp?substr($exp,0,10):'∞','exp_ts'=>$exp?(strtotime($exp)?:0):0,'days_left'=>$dL,'online'=>isset($onlineSetGlobal[$un]),'credit'=>$basic['credit']??0];
            }
        }

        $total=count($filtered);
        if(!empty($filtered)&&in_array($sortBy,['username','group','isp','ras','exp','status'])){
            usort($filtered,function($a,$b)use($sortBy,$sortDir){
                $va=$sortBy==='exp'?($a['exp_ts']??0):strtolower($a[$sortBy]??'');
                $vb=$sortBy==='exp'?($b['exp_ts']??0):strtolower($b[$sortBy]??'');
                $cmp=is_numeric($va)?($va<=>$vb):strcmp($va,$vb);
                return $sortDir==='asc'?$cmp:-$cmp;
            });
        }
        $rows=array_slice($filtered,$page*$perPage,$perPage);
        echo json_encode(['total'=>$total,'rows'=>$rows]);exit;
    }

    // بدون ISP و search → جستجوی معمولی با pagination در سمت API
    $conds=[];
    if($grpF!=='') $conds['group_name']=$grpF;
    $r=ibsng_call('user.searchUser',['conds'=>$conds,'from'=>$page*$perPage,'to'=>($page+1)*$perPage,'order_by'=>'user_id','desc'=>true]);
    $total=$r['result'][0]??0;$uids=$r['result'][2]??[];
    $rows=[];
    if(!empty($uids)){
        $inf=ibsng_call('user.getUserInfo',['user_id'=>implode(',',$uids)]);
        $infos=$inf['result']??[];
        foreach($uids as $uid){
            $u=$infos[$uid]??null;if(!$u)continue;
            $basic=$u['basic_info']??[];$attrs=$u['attrs']??[];
            $exp=$basic['nearest_exp_date']??'';
            $dL=null;if($exp&&$exp!==''){$et=strtotime($exp);if($et)$dL=(int)(($et-time())/86400);}
            $ras=$basic['ras_ip_addr']??($attrs['ras_ip_addr']??'—');
            if($rasF!==''&&stripos($ras,$rasF)===false)continue;
            $rows[]=['id'=>$uid,'username'=>$attrs['normal_username']??'—','password'=>$attrs['normal_password']??'—','status'=>$basic['status']??'—','group'=>$basic['group_name']??'—','isp'=>$basic['isp_name']??'—','ras'=>$ras,'exp'=>$exp?substr($exp,0,10):'∞','exp_ts'=>$exp?(strtotime($exp)?:0):0,'days_left'=>$dL,'online'=>isset($onlineSetGlobal[$attrs['normal_username']??'']),'credit'=>$basic['credit']??0];
        }
    }
    // sort در PHP
    if(!empty($rows)&&in_array($sortBy,['username','group','isp','ras','exp','status'])){
        usort($rows,function($a,$b)use($sortBy,$sortDir){
            $va=$sortBy==='exp'?($a['exp_ts']??0):strtolower($a[$sortBy]??'');
            $vb=$sortBy==='exp'?($b['exp_ts']??0):strtolower($b[$sortBy]??'');
            $cmp=is_numeric($va)?($va<=>$vb):strcmp($va,$vb);
            return $sortDir==='asc'?$cmp:-$cmp;
        });
    }
    echo json_encode(['total'=>$total,'rows'=>$rows]);exit;
}

// AJAX: کاربران رو به اتمام
if(isset($_GET['ajax'])&&$_GET['ajax']==='expiring'){
    header('Content-Type: application/json');
    $days=(int)($_GET['days']??3);
    $onlineSetGlobal = ibsng_getOnlineUsernameSet('');
    $now=date('Y/m/d');$future=date('Y/m/d',strtotime("+{$days} days"));
    $r=ibsng_call('user.searchExpiredUsersExtended',['conds'=>['exp_date_from'=>$now,'exp_date_from_unit'=>'gregorian','exp_date_to'=>$future,'exp_date_to_unit'=>'gregorian'],'from'=>0,'to'=>200,'order_by'=>'user_id','desc'=>false]);
    $users=$r['result'][2]??[];
    if(empty($users)){echo json_encode(['total'=>0,'rows'=>[]]);exit;}
    $inf=ibsng_call('user.getUserInfo',['user_id'=>implode(',',array_keys($users))]);
    $infos=$inf['result']??[];$rows=[];
    foreach(array_keys($users) as $uid){
        $u=$infos[$uid]??null;if(!$u)continue;
        $basic=$u['basic_info']??[];$attrs=$u['attrs']??[];
        $exp=$basic['nearest_exp_date']??'';
        $dL=null;if($exp){$et=strtotime($exp);if($et)$dL=(int)(($et-time())/86400);}
        $rows[]=['id'=>$uid,'username'=>$attrs['normal_username']??'—','status'=>$basic['status']??'—','group'=>$basic['group_name']??'—','isp'=>$basic['isp_name']??'—','exp'=>$exp?substr($exp,0,10):'—','days_left'=>$dL,'online'=>isset($onlineSetGlobal[$attrs['normal_username']??''])];
    }
    echo json_encode(['total'=>count($rows),'rows'=>$rows]);exit;
}

// POST actions
if($_SERVER['REQUEST_METHOD']==='POST'){
    $act=$_POST['action']??'';
    if($act==='create_user'){
        // بررسی وجود username
        $chk=ibsng_call('user.doesUserExists',['normal_username'=>$_POST['username']]);
        if($chk['result']??false){$error='این نام کاربری قبلاً وجود دارد';}
        else{
            $credit_val=(float)($_POST['credit']??100);
            $r=ibsng_call('user.addNewUsers',['count'=>1,'credit'=>['1'=>$credit_val],'isp_name'=>$_POST['isp_name'],'group_name'=>$_POST['group_name'],'credit_comment'=>'ایجاد توسط ادمین']);
            if($r['error']??null){$error='خطا: '.$r['error'];}
            else{
                $newUID=$r['result'][0];
                $r2=ibsng_call('user.updateUserAttrs',['user_id'=>(string)$newUID,'attrs'=>['normal_user_spec'=>['normal_username'=>$_POST['username'],'normal_password'=>$_POST['password']]],'to_del_attrs'=>[]]);
                if($r2['error']??null){
                    ibsng_call('user.delUser',['user_id'=>(string)$newUID,'delete_comment'=>'rollback','del_connection_logs'=>false,'del_audit_logs'=>false]);
                    $error='خطا در تنظیم مشخصات: '.$r2['error'];
                }else{
                    logActivity('admin',$_SESSION['admin_id'],'create_user','کاربر '.$_POST['username'].' ایجاد شد');
                    ibsng_cacheUpsertUser($pdo,$newUID,$_POST['isp_name']);
                    header('Location: users.php?success='.urlencode('کاربر '.$_POST['username'].' ساخته شد'));exit;
                }
            }
        }
    }
    if($act==='renew_user'){
        $uid=$_POST['user_id'];
        $inf=ibsng_call('user.getUserInfo',['user_id'=>$uid]);
        $basic=$inf['result'][$uid]['basic_info']??[];
        $gn=$basic['group_name']??'';
        $gi=ibsng_call('group.getGroupInfo',['group_name'=>$gn]);
        $gc=$gi['result']['attrs']['group_credit']??($basic['credit']??100);
        $ga=$gi['result']['raw_attrs']??[];
        $rCredit=ibsng_call('user.changeCredit',['user_id'=>$uid,'credit'=>(float)$gc,'is_absolute_change'=>true,'credit_comment'=>'تمدید توسط ادمین']);
        $rExp=null;
        if(!empty($ga['rel_exp_date'])){
            // انقضا باید بر اساس Relative Expiration Date گروه از اولین اتصال بعدی
            // کاربر شمرده بشه، نه از همین لحظه‌ی تمدید. پس دیگه abs_exp_date رو ست
            // نمی‌کنیم (و اگه از قبل روی کاربر مونده باشه پاکش می‌کنیم)، فقط
            // first_login/real_first_login رو ریست می‌کنیم تا شمارش از اولین لاگین
            // بعدی از نو شروع بشه (نه از اولین لاگین قدیمی کاربر).
            $rExp=ibsng_call('user.updateUserAttrs',['user_id'=>$uid,'attrs'=>(object)[],'to_del_attrs'=>['abs_exp_date','abs_exp_date_unit','first_login','real_first_login']]);
        }
        $rStatus=ibsng_call('user.changeStatus',['user_id'=>$uid,'status'=>'Recharged']);
        if($rCredit['error']??null){$error='خطا در شارژ اعتبار: '.$rCredit['error'];}
        elseif($rExp&&($rExp['error']??null)){$error='خطا در تمدید تاریخ انقضا: '.$rExp['error'];}
        elseif($rStatus['error']??null){$error='خطا در تغییر وضعیت به «Recharged»: '.$rStatus['error'];}
        else{
            $unL=$inf['result'][$uid]['attrs']['normal_username']??$inf['result'][$uid]['attrs']['username']??'';
            $pdo->prepare("INSERT INTO renewal_logs (ibs_username,isp_name,group_name,price,renewed_by,admin_id) VALUES (?,?,?,?,?,?)")
                ->execute([$unL,$basic['isp_name']??'',$gn,0,'admin',$_SESSION['admin_id']]);
            ibsng_cacheUpsertUser($pdo,$uid,$basic['isp_name']??'');
            header('Location: users.php?success=تمدید+شد');exit;
        }
    }
    if($act==='delete_user'){
        $uid=$_POST['user_id'];
        ibsng_call('user.delUser',['user_id'=>$uid,'delete_comment'=>'حذف توسط ادمین','del_connection_logs'=>false,'del_audit_logs'=>false]);
        $pdo->prepare("DELETE FROM users WHERE ibs_uid=?")->execute([$uid]);
        ibsng_cacheDeleteUser($pdo,$uid);
        header('Location: users.php?success=حذف+شد');exit;
    }
    if($act==='change_password'){
        $uid=$_POST['user_id'];$np=$_POST['new_password'];
        // username فعلی را بگیر تا تغییر نکنه
        $infCur=ibsng_call('user.getUserInfo',['user_id'=>$uid]);
        $curUN=$infCur['result'][$uid]['attrs']['normal_username']??$infCur['result'][$uid]['attrs']['username']??'';
        $updAttrs=['normal_password'=>$np];
        if($curUN!=='') $updAttrs['normal_username']=$curUN;
        ibsng_call('user.updateUserAttrs',['user_id'=>$uid,'attrs'=>['normal_user_spec'=>$updAttrs],'to_del_attrs'=>[]]);
        $pdo->prepare("UPDATE users SET password=? WHERE ibs_uid=?")->execute([$np,$uid]);
        ibsng_cacheUpsertUser($pdo,$uid);
        header('Location: users.php?success=رمز+تغییر+کرد');exit;
    }
    if($act==='toggle_lock'){
        // «لاک/آنلاک» توی  یک فیلد کاملاً جدا از status هست (چک‌باکس "User is
        // Locked" توی خود پنل ) - نه یک مقدار status. با updateUserAttrs تنظیم می‌شه.
        $uid=$_POST['user_id'];$st=$_POST['new_status']??'Disable';
        $lock=($st==='Disable');
        // چک‌باکس‌های HTML وقتی تیک نمی‌خورن اصلاً submit نمی‌شن، پس  برای "رفع قفل"
        // انتظار حذف کامل attr رو داره (to_del_attrs) نه ست کردن مقدار false
        // آرایه‌ی خالی PHP همیشه به صورت [] در JSON نوشته می‌شه نه {} - و اگر  انتظار
        // یک آبجکت برای attrs داشته باشه، [] می‌تونه باعث رفتار عجیب/خراب بشه (دیده
        // شد که "false" توی فیلد Reason نوشته می‌شد به‌جای حذف واقعی قفل). با کست
        // به object مطمئن می‌شیم همیشه {} فرستاده می‌شه.
        if($lock) $rLock=ibsng_call('user.updateUserAttrs',['user_id'=>$uid,'attrs'=>['lock'=>true],'to_del_attrs'=>[]]);
        else $rLock=ibsng_call('user.updateUserAttrs',['user_id'=>$uid,'attrs'=>(object)[],'to_del_attrs'=>['lock']]);
        if($rLock['error']??null){$error='خطا در '.($lock?'قفل کردن':'رفع قفل').': '.$rLock['error'];}
        else{header('Location: users.php?success='.($lock?'کاربر+قفل+شد':'قفل+برداشته+شد'));exit;}
    }
    // ساخت گروهی
    if($act==='bulk_create'){
        $pfx   =sanitize($_POST['bulk_prefix']??'user');
        $cnt   =min(50,max(1,(int)($_POST['bulk_count']??5)));
        $grp   =sanitize($_POST['group_name']??'');
        $isp   =sanitize($_POST['isp_name']??'');
        $ptype =$_POST['pass_type']??'m';
        $plen  =max(4,min(20,(int)($_POST['pass_len']??4)));
        $credit_val=(float)($_POST['credit']??100);
        $created=[];$failed=[];
        $gi=ibsng_call('group.getGroupInfo',['group_name'=>$grp]);
        $ga=$gi['result']['raw_attrs']??[];
        // اگه پیشوند شامل الگوی {شروع-پایان} باشه (مثلا TRR{01-20})، یوزرنیم‌ها از
        // روی همون بازه ساخته می‌شن و فیلد «تعداد» نادیده گرفته می‌شه؛ وگرنه مثل قبل
        // پیشوند+رندوم.
        $patternNames=expandBulkPattern($pfx);
        $usernames=$patternNames!==null?$patternNames:array_map(fn($i)=>$pfx.'_'.bin2hex(random_bytes(3)),range(1,$cnt));
        foreach($usernames as $uname){
            $thispw=generatePassword($ptype,$plen);
            $chk=ibsng_call('user.doesUserExists',['normal_username'=>$uname]);
            if($chk['result']??false){$failed[]=$uname;continue;}
            $r=ibsng_call('user.addNewUsers',['count'=>1,'credit'=>['1'=>$credit_val],'isp_name'=>$isp,'group_name'=>$grp,'credit_comment'=>'ساخت گروهی ادمین']);
            if($r['error']??null){$failed[]=$uname;continue;}
            $newUID=$r['result'][0];
            $r2=ibsng_call('user.updateUserAttrs',['user_id'=>(string)$newUID,'attrs'=>['normal_user_spec'=>['normal_username'=>$uname,'normal_password'=>$thispw]],'to_del_attrs'=>[]]);
            if($r2['error']??null){
                ibsng_call('user.delUser',['user_id'=>(string)$newUID,'delete_comment'=>'rollback','del_connection_logs'=>false,'del_audit_logs'=>false]);
                $failed[]=$uname;
            }else{
                $created[]=['u'=>$uname,'p'=>$thispw];
                ibsng_cacheUpsertUser($pdo,$newUID,$isp);
            }
        }
        session_start();
        $_SESSION['bulk_result']=$created;
        session_write_close();
        $msg=count($created).' کاربر ساخته شد'.(count($failed)?' | '.count($failed).' خطا':'');
        header('Location: users.php?success='.urlencode($msg));exit;
    }
}

$groups=ibsng_getGroups();
$isps=ibsng_getIsps();
$editUser=null;
if($action==='edit'&&$user_id){$r=ibsng_call('user.getUserInfo',['user_id'=>$user_id]);$editUser=$r['result'][$user_id]??null;}
$pendingCount=$pdo->query("SELECT COUNT(*) FROM payment_requests WHERE status='pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>مدیریت کاربران</title>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
<link href="../assets/css/theme.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#080c18;--sb:#0d1424;--card:#131e30;--surf:#0d1a2a;--bor:#1e2d45;--acc:#3b82f6;--acc2:#06b6d4;--pur:#8b5cf6;--txt:#e2e8f0;--txt2:#94a3b8;--muted:#475569;--red:#ef4444;--grn:#10b981;--yel:#f59e0b;--sw:260px}
html,body{overflow-x:hidden}
body{font-family:'Vazirmatn',sans-serif;background:var(--bg);color:var(--txt);min-height:100vh}
aside{width:var(--sw);background:var(--sb);border-left:1px solid var(--bor);position:fixed;right:0;top:0;bottom:0;display:flex;flex-direction:column;z-index:100}
@media(max-width:768px){aside{transform:translateX(100%);transition:.3s} aside.open{transform:none}}
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
.pg-t{font-size:17px;font-weight:700}
.content{padding:20px}
.alert{padding:12px 16px;border-radius:9px;margin-bottom:14px;font-size:13px}
.a-ok{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);color:#6ee7b7}
.a-err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
/* tabs */
.tabs{display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap}
.tab{padding:7px 14px;border-radius:9px;font-family:'Vazirmatn';font-size:12px;font-weight:600;cursor:pointer;border:1px solid var(--bor);background:transparent;color:var(--txt2);transition:all .2s}
.tab.active{background:rgba(59,130,246,.15);color:var(--acc);border-color:rgba(59,130,246,.3)}
/* search box */
.sbox{background:var(--card);border:1px solid var(--bor);border-radius:12px;padding:14px;margin-bottom:14px}
.sbox-title{font-size:12px;font-weight:700;margin-bottom:10px;color:var(--txt2)}
.srow{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.si{padding:9px 12px;background:var(--surf);border:1px solid var(--bor);border-radius:8px;color:var(--txt);font-family:'Vazirmatn';font-size:13px;outline:none;transition:border .2s}
.si:focus{border-color:var(--acc)}
.si-wide{flex:1;min-width:150px}
.si-med{min-width:130px}
/* buttons */
.btn{padding:8px 14px;border-radius:9px;font-family:'Vazirmatn';font-size:13px;font-weight:700;cursor:pointer;border:none;transition:all .2s;display:inline-flex;align-items:center;gap:6px;text-decoration:none;white-space:nowrap}
.btn:disabled{opacity:.35;cursor:not-allowed;filter:grayscale(.6)}
.bp{background:linear-gradient(135deg,var(--acc),var(--acc2));color:#fff}
.bpu{background:rgba(139,92,246,.15);color:#a78bfa;border:1px solid rgba(139,92,246,.3)}
.bc{background:rgba(6,182,212,.15);color:#22d3ee;border:1px solid rgba(6,182,212,.3)}
.bg{background:rgba(16,185,129,.15);color:var(--grn);border:1px solid rgba(16,185,129,.3)}
.bd{background:rgba(239,68,68,.1);color:var(--red);border:1px solid rgba(239,68,68,.3)}
.by{background:rgba(245,158,11,.1);color:var(--yel);border:1px solid rgba(245,158,11,.3)}
.bsm{padding:5px 8px;font-size:11px;border-radius:7px}
/* table */
.tinfo{font-size:12px;color:var(--txt2);margin-bottom:8px}
.card{background:var(--card);border:1px solid var(--bor);border-radius:12px;overflow:hidden}
.tw{overflow-x:auto}
table.t{width:100%;border-collapse:collapse;min-width:750px}
table.t th{text-align:right;font-size:11px;font-weight:600;color:var(--muted);padding:10px 11px;border-bottom:1px solid var(--bor);background:rgba(0,0,0,.2);white-space:nowrap}
table.t td{padding:9px 11px;font-size:12px;border-bottom:1px solid rgba(30,45,69,.5);color:var(--txt2);vertical-align:middle}
table.t tr:last-child td{border-bottom:none}
table.t tr:hover td{background:rgba(59,130,246,.02)}
.cb-col{width:32px}
.badge{display:inline-block;padding:3px 7px;border-radius:20px;font-size:10px;font-weight:600}
.bok{background:rgba(16,185,129,.1);color:var(--grn);border:1px solid rgba(16,185,129,.2)}
.ber{background:rgba(239,68,68,.1);color:var(--red);border:1px solid rgba(239,68,68,.2)}
.bbl{background:rgba(59,130,246,.1);color:var(--acc);border:1px solid rgba(59,130,246,.2)}
.bpu2{background:rgba(139,92,246,.1);color:#a78bfa;border:1px solid rgba(139,92,246,.2)}
.bwa{background:rgba(245,158,11,.1);color:var(--yel);border:1px solid rgba(245,158,11,.2)}
.pass-box{font-family:monospace;background:rgba(16,185,129,.07);border:1px solid rgba(16,185,129,.2);padding:2px 7px;border-radius:5px;font-size:12px;color:#34d399;letter-spacing:.5px;cursor:pointer}
.bulk-tbl{width:100%;border-collapse:collapse}
.bulk-tbl th{font-size:11px;padding:8px 10px;background:rgba(0,0,0,.2);color:var(--muted);font-weight:600;text-align:right}
.bulk-tbl td{padding:7px 10px;font-size:12px;border-bottom:1px solid rgba(30,45,69,.3)}
.acts{display:flex;gap:3px;flex-wrap:wrap}
.od{display:inline-block;width:6px;height:6px;background:var(--grn);border-radius:50%;margin-left:3px;animation:bk 2s infinite}
@keyframes bk{0%,100%{opacity:1}50%{opacity:.3}}
.th-sort{cursor:pointer;user-select:none}.th-sort:hover{color:var(--acc)!important}
.loading{text-align:center;padding:30px;color:var(--muted)}
/* pagination */
.pag{display:flex;gap:4px;justify-content:center;margin-top:10px;flex-wrap:wrap}
.pag button{padding:5px 10px;border-radius:7px;font-size:12px;background:var(--card);border:1px solid var(--bor);color:var(--txt2);cursor:pointer;font-family:'Vazirmatn'}
.pag button.active{background:var(--acc);color:#fff;border-color:var(--acc)}
/* modal */
.mbg{position:fixed;inset:0;background:rgba(0,0,0,.78);backdrop-filter:blur(4px);z-index:200;display:none;align-items:center;justify-content:center;padding:12px;overflow-y:auto}
.mbg.open{display:flex}
.modal{background:var(--card);border:1px solid var(--bor);border-radius:16px;width:100%;overflow:hidden;max-height:96vh;overflow-y:auto}
.msm{max-width:460px}.mlg{max-width:600px}
.mh{padding:15px 18px;border-bottom:1px solid var(--bor);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:var(--card);z-index:1}
.mt{font-size:14px;font-weight:700}
.mc{background:none;border:none;color:var(--muted);font-size:18px;cursor:pointer}
.mb{padding:18px}
.mf{padding:12px 18px;border-top:1px solid var(--bor);display:flex;gap:8px;justify-content:flex-end;position:sticky;bottom:0;background:var(--card)}
.fg{margin-bottom:11px}
.lbl{display:block;font-size:12px;font-weight:600;color:var(--txt2);margin-bottom:5px}
.fr{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media(max-width:480px){.fr{grid-template-columns:1fr}}
input,select{width:100%;padding:9px 12px;background:var(--surf);border:1px solid var(--bor);border-radius:8px;color:var(--txt);font-family:'Vazirmatn';font-size:13px;outline:none;transition:border .2s}
input:focus,select:focus{border-color:var(--acc)}
.pg{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:5px}
.pt{padding:4px 10px;border-radius:7px;font-size:11px;font-weight:600;cursor:pointer;border:1px solid var(--bor);background:transparent;color:var(--txt2);font-family:'Vazirmatn';transition:all .2s}
.pt.on{background:rgba(59,130,246,.15);border-color:var(--acc);color:var(--acc)}
.gb{padding:5px 12px;border-radius:7px;font-size:11px;font-weight:700;cursor:pointer;border:none;background:rgba(16,185,129,.15);color:var(--grn);font-family:'Vazirmatn'}
.li{width:50px!important;padding:7px 8px!important;text-align:center}
</style>
</head>
<body class="theme-admin">
<div class="overlay" id="overlay" onclick="closeSB()"></div>
<button class="hamburger" onclick="toggleSB()" title="منو">☰</button>

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
    <a href="resellers.php" class="ni">👥 ریسلرها</a>
    <a href="users.php" class="ni active">🧑‍💻 کاربران</a>
    <a href="online.php" class="ni">🟢 کاربران آنلاین</a>
    <div class="ns">مالی</div>
    <a href="transactions.php" class="ni">💳 تراکنش‌ها</a>
    <a href="renewals.php" class="ni">🔄 کاربران تمدیدشده</a>
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
    <div class="pg-t">🧑‍💻 مدیریت کاربران</div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <button class="btn bp" onclick="openM('addM')">➕ کاربر جدید</button>
      <button class="btn bpu" onclick="openM('bulkM')">📦 ساخت گروهی</button>
    </div>
  </div>
  <div class="content">
    <?php if($success):?><div class="alert a-ok">✅ <?=sanitize($success)?></div><?php endif;?>
    <?php if($error):?><div class="alert a-err">❌ <?=sanitize($error)?></div><?php endif;?>

    <?php if(!empty($bulkResult)):?>
    <div class="card" style="margin-bottom:14px">
      <div style="padding:10px 14px;border-bottom:1px solid var(--bor);font-weight:700;font-size:13px;display:flex;align-items:center;gap:10px">
        📋 <?=count($bulkResult)?> کاربر ساخته شد
        <button onclick="copyBulk()" class="btn bg bsm" title="کپی نتایج ساخت گروهی">📋 کپی همه</button>
        <button onclick="downloadBulk()" class="btn bp bsm" title="دانلود تصویر JPEG">⬇️ دانلود</button>
      </div>
      <div style="overflow-x:auto;max-height:260px;overflow-y:auto">
        <table class="bulk-tbl">
          <thead><tr><th>#</th><th>نام کاربری</th><th>رمز</th></tr></thead>
          <tbody id="bulkTB"><?php foreach($bulkResult as $i=>$b):?>
          <tr><td style="color:var(--muted)"><?=$i+1?></td>
          <td style="font-weight:700;color:var(--txt)"><?=sanitize($b['u'])?></td>
          <td><span class="pass-box" onclick="cp(this)"><?=sanitize($b['p'])?></span></td></tr>
          <?php endforeach;?></tbody>
        </table>
      </div>
    </div>
    <?php endif;?>

    <div class="tabs">
      <button class="tab active" onclick="setTab('all',this)">📋 همه کاربران</button>
      <button class="tab" onclick="setTab('exp3',this)">⚠️ رو به اتمام (۳ روز)</button>
    </div>

    <!-- جعبه سرچ پیشرفته -->
    <div class="sbox">
      <div class="sbox-title">🔍 جستجوی پیشرفته</div>
      <div class="srow">
        <input type="text" class="si si-wide" id="fSrch" placeholder="👤 نام کاربری...">
        <select class="si si-med" id="fGrp">
          <option value="">📦 همه گروه‌ها</option>
          <?php foreach($groups as $g):?><option value="<?=sanitize($g)?>"><?=sanitize($g)?></option><?php endforeach;?>
        </select>
        <select class="si si-med" id="fIsp">
          <option value="">🌐 همه ISP ها</option>
          <?php foreach($isps as $isp):?><option value="<?=sanitize($isp)?>"><?=sanitize($isp)?></option><?php endforeach;?>
        </select>
        <input type="text" class="si si-med" id="fRas" placeholder="🔌 RAS...">
        <button class="btn bp" onclick="curP=0;load()">🔍 جستجو</button>
        <button class="btn bc" onclick="clrSrch()">✕ پاک</button>
        <button class="btn bg" onclick="load()" style="padding:8px 10px" title="بروزرسانی">🔄</button>
      </div>
    </div>

    <div class="tinfo" id="tinfo">در حال بارگذاری...</div>
    <div class="card">
      <div class="tw">
        <table class="t">
          <thead><tr>
            <th class="th-sort" onclick="setSort('username')">کاربر <span id="s_username">↕</span></th><th>رمز</th><th>وضعیت</th>
            <th class="th-sort" onclick="setSort('group')">گروه <span id="s_group">↕</span></th>
            <th class="th-sort" onclick="setSort('isp')">ISP <span id="s_isp">↕</span></th>
            <th class="th-sort" onclick="setSort('exp')">انقضا <span id="s_exp">↕</span></th><th>عملیات</th>
          </tr></thead>
          <tbody id="tbody"><tr><td colspan="7" class="loading">⏳ در حال بارگذاری...</td></tr></tbody>
        </table>
      </div>
    </div>
    <div class="pag" id="pag"></div>
  </div>
</main>

<!-- ایجاد کاربر -->
<div class="mbg" id="addM">
  <div class="modal msm">
    <div class="mh"><div class="mt">➕ کاربر جدید</div><button class="mc" onclick="closeM('addM')" title="بستن">✕</button></div>
    <form method="POST"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
      <input type="hidden" name="action" value="create_user">
      <div class="mb">
        <div class="fr">
          <div class="fg"><label class="lbl">نام کاربری *</label><input type="text" name="username" required></div>
          <div class="fg">
            <label class="lbl">رمز عبور *</label>
            <input type="text" name="password" id="newPw" required>
            <div class="pg" style="margin-top:6px">
              <button type="button" class="pt on" id="pt_m" onclick="sPT('m')">حروف+عدد</button>
              <button type="button" class="pt" id="pt_c" onclick="sPT('c')">حروف</button>
              <button type="button" class="pt" id="pt_n" onclick="sPT('n')">عدد</button>
              <input type="number" id="pLen" value="4" min="4" max="20" class="li">
              <button type="button" class="gb" onclick="genPW('newPw','pLen','m_')" title="تولید رمز تصادفی">🎲</button>
            </div>
          </div>
        </div>
        <div class="fr">
          <div class="fg"><label class="lbl">گروه</label>
            <select name="group_name">
              <option value="">انتخاب...</option>
              <?php foreach($groups as $g):?><option><?=sanitize($g)?></option><?php endforeach;?>
            </select>
          </div>
          <div class="fg"><label class="lbl">ISP</label>
            <select name="isp_name">
              <?php foreach($isps as $isp):?><option><?=sanitize($isp)?></option><?php endforeach;?>
            </select>
          </div>
        </div>
        <div class="fg"><label class="lbl">📱 موبایل (اختیاری)</label><input type="text" name="mobile" placeholder="09xxxxxxxxx"></div>
      </div>
      <div class="mf">
        <button type="button" class="btn bd" onclick="closeM('addM')">انصراف</button>
        <button type="submit" class="btn bp">✅ ایجاد</button>
      </div>
    </form>
  </div>
</div>

<!-- تغییر رمز -->
<div class="mbg" id="passM">
  <div class="modal msm">
    <div class="mh"><div class="mt">🔑 تغییر رمز</div><button class="mc" onclick="closeM('passM')" title="بستن">✕</button></div>
    <form method="POST"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
      <input type="hidden" name="action" value="change_password">
      <input type="hidden" name="user_id" id="pUid">
      <div class="mb">
        <p style="margin-bottom:12px;color:var(--txt2)">کاربر: <strong id="pUname" style="color:var(--acc)"></strong></p>
        <div class="fg">
          <label class="lbl">رمز جدید</label>
          <input type="text" name="new_password" id="chPw" required>
          <div class="pg" style="margin-top:6px">
            <button type="button" class="pt on" id="pp_m" onclick="sPT2('m')">حروف+عدد</button>
            <button type="button" class="pt" id="pp_c" onclick="sPT2('c')">حروف</button>
            <button type="button" class="pt" id="pp_n" onclick="sPT2('n')">عدد</button>
            <input type="number" id="pLen2" value="4" min="4" max="20" class="li">
            <button type="button" class="gb" onclick="genPW('chPw','pLen2','p_')" title="تولید رمز تصادفی">🎲</button>
          </div>
        </div>
      </div>
      <div class="mf">
        <button type="button" class="btn bd" onclick="closeM('passM')">انصراف</button>
        <button type="submit" class="btn bp">✅ تغییر</button>
      </div>
    </form>
  </div>
</div>

<!-- تمدید -->
<div class="mbg" id="rnM">
  <div class="modal msm">
    <div class="mh"><div class="mt">🔄 تمدید کاربر</div><button class="mc" onclick="closeM('rnM')" title="بستن">✕</button></div>
    <form method="POST"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
      <input type="hidden" name="action" value="renew_user">
      <input type="hidden" name="user_id" id="rnUid">
      <div class="mb">
        <p style="color:var(--txt2)">تمدید سرویس: <strong id="rnUname" style="color:var(--grn)"></strong></p>
        <p style="font-size:12px;color:var(--muted);margin-top:8px">اعتبار و انقضا بر اساس گروه ریست می‌شود.</p>
      </div>
      <div class="mf">
        <button type="button" class="btn bd" onclick="closeM('rnM')">انصراف</button>
        <button type="submit" class="btn bg">✅ تمدید</button>
      </div>
    </form>
  </div>
</div>

<!-- حذف -->
<div class="mbg" id="delM">
  <div class="modal msm">
    <div class="mh"><div class="mt">🗑 حذف کاربر</div><button class="mc" onclick="closeM('delM')" title="بستن">✕</button></div>
    <form method="POST"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
      <input type="hidden" name="action" value="delete_user">
      <input type="hidden" name="user_id" id="dUid">
      <div class="mb"><p style="color:var(--txt2)">کاربر <strong id="dUname" style="color:var(--red)"></strong> از  حذف می‌شود. قابل بازگشت نیست.</p></div>
      <div class="mf">
        <button type="button" class="btn bg" onclick="closeM('delM')">انصراف</button>
        <button type="submit" class="btn bd">🗑 حذف</button>
      </div>
    </form>
  </div>
</div>

<!-- Lock/Unlock -->
<div class="mbg" id="lockM">
  <div class="modal msm">
    <div class="mh"><div class="mt" id="lockTitle">🔒 قفل کاربر</div><button class="mc" onclick="closeM('lockM')" title="بستن">✕</button></div>
    <form method="POST"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
      <input type="hidden" name="action" value="toggle_lock">
      <input type="hidden" name="user_id" id="lkUid">
      <input type="hidden" name="new_status" id="lkSt">
      <div class="mb"><p style="color:var(--txt2)" id="lockMsg"></p></div>
      <div class="mf">
        <button type="button" class="btn bg" onclick="closeM('lockM')">انصراف</button>
        <button type="submit" class="btn by" id="lockBtn">تأیید</button>
      </div>
    </form>
  </div>
</div>


<!-- ساخت گروهی -->
<div class="mbg" id="bulkM">
  <div class="modal msm">
    <div class="mh"><div class="mt">📦 ساخت گروهی</div><button class="mc" onclick="closeM('bulkM')" title="بستن">✕</button></div>
    <form method="POST" action="users.php" id="bulkForm"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
      <input type="hidden" name="action" value="bulk_create">
      <div class="mb">
        <div class="fr">
          <div class="fg"><label class="lbl">تعداد (max 50)</label><input type="number" name="bulk_count" value="5" min="1" max="50"></div>
          <div class="fg"><label class="lbl">پیشوند</label><input type="text" name="bulk_prefix" value="user" placeholder="user یا TRR{01-20}"></div>
        </div>
        <div style="font-size:11px;color:var(--muted);margin:-6px 0 10px">برای شماره‌گذاری دلخواه از الگوی <b>TRR{01-20}</b> در پیشوند استفاده کن (یوزرنیم‌ها TRR01 تا TRR20 ساخته می‌شن و فیلد تعداد نادیده گرفته می‌شه).</div>
        <div class="fr">
          <div class="fg"><label class="lbl">گروه</label>
            <select name="group_name" required>
              <option value="">انتخاب...</option>
              <?php foreach($groups as $g):?><option><?=sanitize($g)?></option><?php endforeach;?>
            </select>
          </div>
          <div class="fg"><label class="lbl">ISP</label>
            <select name="isp_name">
              <?php foreach($isps as $isp):?><option><?=sanitize($isp)?></option><?php endforeach;?>
            </select>
          </div>
        </div>
        <div class="fg">
          <label class="lbl">نوع رمز</label>
          <div class="pg">
            <button type="button" class="pt on" id="bpt_m" onclick="sPT3('m')">حروف+عدد</button>
            <button type="button" class="pt" id="bpt_c" onclick="sPT3('c')">حروف</button>
            <button type="button" class="pt" id="bpt_n" onclick="sPT3('n')">عدد</button>
            <input type="hidden" name="pass_type" id="bPT" value="m">
            طول: <input type="number" name="pass_len" value="4" min="4" max="20" class="li">
          </div>
        </div>
      </div>
      <div class="mf">
        <button type="button" class="btn bd" onclick="closeM('bulkM')">انصراف</button>
        <button type="submit" class="btn bpu">📦 ایجاد</button>
      </div>
    </form>
  </div>
</div>

<script>
let curP=0,curSort='',curDir='desc',curTab='all',ptM='m',ptP='m',ptB='m';
function toggleSB(){document.getElementById('sidebar').classList.toggle('open');document.getElementById('overlay').style.display='block'}
function closeSB(){document.getElementById('sidebar').classList.remove('open');document.getElementById('overlay').style.display='none'}
function openM(id){document.getElementById(id).classList.add('open')}
function closeM(id){document.getElementById(id).classList.remove('open')}
document.querySelectorAll('.mbg').forEach(b=>b.addEventListener('click',e=>{if(e.target===b)b.classList.remove('open')}));

function cp(el){
  navigator.clipboard.writeText(el.textContent).then(function(){
    el.style.background='rgba(16,185,129,.25)';setTimeout(function(){el.style.background='';},700);
  });
}
function copyBulk(){
  var rows=[].slice.call(document.querySelectorAll('#bulkTB tr'));
  var txt=rows.map(function(r){var c=[].slice.call(r.querySelectorAll('td'));return (c[1]?c[1].textContent.trim():'')+'\t'+(c[2]?c[2].textContent.trim():'');}).join('\n');
  navigator.clipboard.writeText(txt).then(function(){alert('کپی شد!');});
}
function renderTableAsJpeg(rows, filename){
  var padding=12, rowH=34;
  var canvas=document.createElement('canvas');
  var ctx=canvas.getContext('2d');
  ctx.font='14px Tahoma, Arial, sans-serif';
  var colCount=rows[0].length, colWidths=[];
  for(var c=0;c<colCount;c++){
    var maxW=0;
    rows.forEach(function(r){ var w=ctx.measureText(String(r[c])).width; if(w>maxW) maxW=w; });
    colWidths.push(maxW+padding*2);
  }
  var totalW=colWidths.reduce(function(a,b){return a+b;},0);
  var totalH=rowH*rows.length;
  canvas.width=totalW; canvas.height=totalH;
  ctx.fillStyle='#ffffff'; ctx.fillRect(0,0,totalW,totalH);
  var y=0;
  rows.forEach(function(r,ri){
    var x=0;
    for(var c=0;c<colCount;c++){
      var w=colWidths[c];
      ctx.fillStyle = ri===0 ? '#eaf1fb' : (ri%2===0 ? '#fafafa' : '#ffffff');
      ctx.fillRect(x,y,w,rowH);
      ctx.strokeStyle='#cccccc'; ctx.strokeRect(x,y,w,rowH);
      ctx.fillStyle = ri===0 ? '#1a4fa0' : '#111111';
      ctx.font = ri===0 ? 'bold 14px Tahoma, Arial, sans-serif' : '14px Tahoma, Arial, sans-serif';
      ctx.textBaseline='middle';
      ctx.fillText(String(r[c]), x+padding, y+rowH/2);
      x+=w;
    }
    y+=rowH;
  });
  var a=document.createElement('a');
  a.href=canvas.toDataURL('image/jpeg',0.95);
  a.download=filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
}
function downloadBulk(){
  var rows=[].slice.call(document.querySelectorAll('#bulkTB tr'));
  var data=[['Internet Username','Internet Password']];
  rows.forEach(function(r){
    var c=[].slice.call(r.querySelectorAll('td'));
    data.push([(c[1]?c[1].textContent.trim():''), (c[2]?c[2].textContent.trim():'')]);
  });
  renderTableAsJpeg(data, 'users_'+(new Date().toISOString().slice(0,10))+'.jpg');
}

function setTab(t,el){curTab=t;curP=0;document.querySelectorAll('.tab').forEach(x=>x.classList.remove('active'));el.classList.add('active');load();}
function clrSrch(){document.getElementById('fSrch').value='';document.getElementById('fGrp').value='';document.getElementById('fIsp').value='';document.getElementById('fRas').value='';curP=0;load();}

function sPT(t){ptM=t;['m','c','n'].forEach(x=>document.getElementById('pt_'+x).classList.toggle('on',x===t));}
function sPT2(t){ptP=t;['m','c','n'].forEach(x=>document.getElementById('pp_'+x).classList.toggle('on',x===t));}
function sPT3(t){ptB=t;document.getElementById('bPT').value=t;['m','c','n'].forEach(x=>document.getElementById('bpt_'+x).classList.toggle('on',x===t));}
function genPW(fid,lid,pfx){
  const len=parseInt(document.getElementById(lid).value)||4;
  const t=pfx==='m_'?ptM:(pfx==='p_'?ptP:ptB);
  let p=''; if(t==='m'||t==='c')p+='abcdefghijkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ'; if(t==='m'||t==='n')p+='23456789';
  let r='';const a=new Uint32Array(len);crypto.getRandomValues(a);a.forEach(v=>r+=p[v%p.length]);
  document.getElementById(fid).value=r;
}

let srchT=null;
document.getElementById('fSrch').addEventListener('input',()=>{clearTimeout(srchT);srchT=setTimeout(()=>{curP=0;load();},500)});
['fGrp','fIsp'].forEach(id=>document.getElementById(id).addEventListener('change',()=>{curP=0;load();}));

function setSort(col){
  if(curSort===col) curDir=curDir==='asc'?'desc':'asc';
  else { curSort=col; curDir='asc'; }
  ['username','group','isp','ras','exp'].forEach(function(c){
    var el=document.getElementById('s_'+c);
    if(!el) return;
    if(c===curSort) el.textContent=curDir==='asc'?'▲':'▼';
    else el.textContent='↕';
  });
  curP=0; load();
}
// چون عملیات‌هایی مثل تمدید/قفل/حذف/Kick با یک POST معمولی و redirect کامل صفحه
// انجام می‌شن، هر بار صفحه از نو لود می‌شه و صفحه/فیلترها به حالت پیش‌فرض
// برمی‌گردن. برای جلوگیری از این، وضعیت فعلی رو قبل از هر load توی sessionStorage
// ذخیره می‌کنیم و موقع لود شدن صفحه (بعد از redirect) دوباره برش می‌گردونیم.
function saveUsersState(){
  try{
    sessionStorage.setItem('adminUsersState', JSON.stringify({
      curP, curSort, curDir, curTab,
      search: document.getElementById('fSrch').value,
      group: document.getElementById('fGrp').value,
      isp: document.getElementById('fIsp').value,
      ras: document.getElementById('fRas').value,
    }));
  }catch(e){}
}
function restoreUsersState(){
  try{
    const raw = sessionStorage.getItem('adminUsersState');
    if(!raw) return false;
    const st = JSON.parse(raw);
    curP = st.curP||0; curSort = st.curSort||''; curDir = st.curDir||'desc'; curTab = st.curTab||'all';
    document.getElementById('fSrch').value = st.search||'';
    document.getElementById('fGrp').value = st.group||'';
    document.getElementById('fIsp').value = st.isp||'';
    document.getElementById('fRas').value = st.ras||'';
    const tabs = document.querySelectorAll('.tab');
    tabs.forEach(t=>t.classList.remove('active'));
    if(curTab==='exp3' && tabs[1]) tabs[1].classList.add('active');
    else if(tabs[0]) tabs[0].classList.add('active');
    return true;
  }catch(e){return false;}
}

function load(){
  saveUsersState();
  document.getElementById('tbody').innerHTML='<tr><td colspan="7" class="loading">⏳ در حال بارگذاری...</td></tr>';
  if(curTab==='exp3'){loadExp();return;}
  const s=document.getElementById('fSrch').value.trim();
  const g=document.getElementById('fGrp').value;
  const isp=document.getElementById('fIsp').value;
  const ras=document.getElementById('fRas').value.trim();
  fetch(`users.php?ajax=list&page=${curP}&search=${encodeURIComponent(s)}&group=${encodeURIComponent(g)}&isp=${encodeURIComponent(isp)}&ras=${encodeURIComponent(ras)}&sort=${encodeURIComponent(curSort)}&dir=${encodeURIComponent(curDir)}`)
    .then(r=>r.json()).then(d=>renderTable(d,50))
    .catch(()=>{document.getElementById('tbody').innerHTML='<tr><td colspan="7" class="loading">❌ خطا</td></tr>';});
}

function loadExp(){
  fetch('users.php?ajax=expiring&days=3')
    .then(r=>r.json()).then(d=>renderTable(d,200))
    .catch(()=>{document.getElementById('tbody').innerHTML='<tr><td colspan="7" class="loading">❌ خطا</td></tr>';});
}

function renderTable(d,pp){
  const total=d.total,rows=d.rows||[];
  document.getElementById('tinfo').textContent=rows.length?`نمایش ${curP*pp+1}–${Math.min((curP+1)*pp,total)} از ${total.toLocaleString('de-DE')} کاربر`:'هیچ کاربری یافت نشد';
  if(!rows.length){document.getElementById('tbody').innerHTML='<tr><td colspan="7" class="loading">هیچ کاربری یافت نشد</td></tr>';document.getElementById('pag').innerHTML='';return;}
  document.getElementById('tbody').innerHTML=rows.map(u=>{
    const st=u.status==='Active'||u.status==='Recharged'?`<span class="badge bok">${u.status}</span>`:`<span class="badge ber">${u.status}</span>`;
    const ec=u.days_left===null?'bbl':u.days_left<0?'ber':u.days_left<=7?'bwa':'bok';
    const expT=u.exp+(u.days_left!==null?`<br><span class="badge ${ec}" style="margin-top:2px">${u.days_left<0?'منقضی':u.days_left+'روز'}</span>`:'');
    const pw=`<span class="pass-box">${u.password}</span>`;
    // «وضعیت» و «لاک بودن» توی  دو مفهوم کاملاً جدا هستند (status هیچ‌وقت مقدار
    // لاک/آنلاک رو نشون نمی‌ده)، برای همین نمی‌تونیم مطمئن حدس بزنیم کاربر الان
    // لاکه یا نه - هر دو دکمه رو همیشه نشون می‌دیم.
    return `<tr>
      <td><strong style="color:var(--txt);font-size:13px">${u.username}</strong><br><small style="color:var(--muted)">#${u.id}</small></td>
      <td>${pw}</td><td>${st}</td>
      <td><span class="badge bpu2">${u.group}</span></td>
      <td style="font-size:11px">${u.isp}</td>
      <td style="font-size:11px">${expT}</td>
      <td><div class="acts">
        <button class="btn by bsm" onclick="openPM('${u.id}','${u.username}')" title="تغییر رمز">🔑</button>
        <button class="btn bg bsm" onclick="openRn('${u.id}','${u.username}')" title="تمدید">🔄</button>
        <button class="btn bwa bsm" onclick="openLk('${u.id}','${u.username}','Disable')" title="قفل کردن">🔒</button>
        <button class="btn bc bsm" onclick="openLk('${u.id}','${u.username}','Recharged')" title="رفع قفل">🔓</button>
        <button class="btn bd bsm" onclick="openDel('${u.id}','${u.username}')" title="حذف کاربر">🗑</button>
      </div></td>
    </tr>`;
  }).join('');
  // pagination
  const tp=Math.ceil(total/pp);
  let pg='';
  if(curP>0)pg+=`<button onclick="goP(${curP-1})" title="صفحه قبل">«</button>`;
  const s=Math.max(0,curP-2),e=Math.min(tp-1,curP+2);
  for(let i=s;i<=e;i++)pg+=`<button class="${i===curP?'active':''}" onclick="goP(${i})" title="صفحه ${i+1}">${i+1}</button>`;
  if(curP<tp-1)pg+=`<button onclick="goP(${curP+1})" title="صفحه بعد">»</button>`;
  document.getElementById('pag').innerHTML=pg;
}

function goP(p){curP=p;load();}
function openPM(uid,un){document.getElementById('pUid').value=uid;document.getElementById('pUname').textContent=un;openM('passM')}
function openRn(uid,un){document.getElementById('rnUid').value=uid;document.getElementById('rnUname').textContent=un;openM('rnM')}
function openDel(uid,un){document.getElementById('dUid').value=uid;document.getElementById('dUname').textContent=un;openM('delM')}
function openLk(uid,un,st){
  document.getElementById('lkUid').value=uid;
  document.getElementById('lkSt').value=st;
  const lock=st==='Disable';
  document.getElementById('lockTitle').textContent=lock?'🔒 قفل کاربر':'🔓 رفع قفل';
  document.getElementById('lockMsg').textContent=(lock?'کاربر ':'رفع قفل ')+un+(lock?' قفل می‌شود':' می‌شود');
  openM('lockM');
}

restoreUsersState();
load();
</script>
</body>
</html>
