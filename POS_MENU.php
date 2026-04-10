<?php
// ============================================================
//  POS_MENU.php  — Sidebar + Nav Buttons
//  include ใน <body>:  <?php $MENU_ACTIVE='home'; require_once 'POS_MENU.php'; ? >
//  ต้อง include POS_AUTH.php ก่อนในบรรทัดบนสุดของไฟล์
// ============================================================
if ((isset($_GET['ajax'])&&$_GET['ajax']==='1')||(isset($_GET['ping_ajax'])&&$_GET['ping_ajax']==='1')||isset($_GET['stat'])){return;}
if(!isset($MENU_ACTIVE))    $MENU_ACTIVE    ='';
if(!isset($pos_priority))   $pos_priority   ='U';
if(!isset($pos_logged_user))$pos_logged_user='';
if(!isset($pos_menu_perms)) $pos_menu_perms =[];
if(!isset($pos_menu_tree))  $pos_menu_tree  =[];
$_mbc=$pos_priority==='A'?'badge-admin':($pos_priority==='M'?'badge-member':'badge-user');
$_mbt=$pos_priority==='A'?'ADMIN':($pos_priority==='M'?'MEMBER':'USER');
$_sa=in_array($MENU_ACTIVE,['all','all_items','all_members','all_sales']);
$_sp=$_sa?' active sub-open':'';
$_sm=$_sa?' sub-open':'';

// ── helper: check permission (with fallback to priority) ─────
if(!function_exists('_perm_view')){
    function _perm_view($code){
        global $pos_menu_perms,$pos_priority;
        if(!empty($pos_menu_perms)) return !empty($pos_menu_perms[$code]['can_view']);
        // fallback
        if($code==='usermgmt') return $pos_priority==='A';
        if(in_array($code,['home','members','all_members'])) return true;
        return $pos_priority!=='M';
    }
}
if(!function_exists('_perm_use')){
    function _perm_use($code){
        global $pos_menu_perms,$pos_priority;
        if(!empty($pos_menu_perms)) return !empty($pos_menu_perms[$code]['can_use']);
        if($code==='usermgmt') return $pos_priority==='A';
        if(in_array($code,['home','members','all_members'])) return true;
        return $pos_priority!=='M';
    }
}
if(!function_exists('_pos_mi')){
    function _pos_mi($href,$ic,$fa,$label,$key,$active){
        $cls=$key===$active?' active':'';
        return '<a href="'.$href.'" class="sidebar-menu-item'.$cls.'"><span class="menu-icon '.$ic.'"><i class="'.$fa.'"></i></span><span>'.htmlspecialchars($label).'</span></a>';
    }
}
// ── ดึง FULL_NAME ของ User ที่ login อยู่ ──────────────────
$_pos_full_name = $_SESSION['pos_full_name'] ?? '';
if ($_pos_full_name === '' && function_exists('_pos_auth_sql') && !empty($pos_logged_user)) {
    $_safe_uid = str_replace("'", "''", strtoupper(trim($pos_logged_user)));
    $_res_fn = _pos_auth_sql(
        "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 200 TRIMSPOOL ON\n"
       ."ALTER SESSION SET NLS_LANGUAGE = American;\n"
       ."SELECT NVL(TRIM(FULL_NAME),'-') FROM POS.SK_WEB_USER WHERE UPPER(TRIM(USER_ID))=UPPER('{$_safe_uid}') AND ROWNUM=1;\nEXIT;\n",
        $instant_client_path,$oracle_user,$oracle_pass,$oracle_tns);
    foreach (preg_split('/\r?\n/', $_res_fn) as $_fnl) {
        $_fnl = trim($_fnl);
        if ($_fnl !== '' && !preg_match('/^(ORA-|SP2-)/', $_fnl)) { $_pos_full_name = $_fnl; break; }
    }
    if ($_pos_full_name !== '' && $_pos_full_name !== '-') $_SESSION['pos_full_name'] = $_pos_full_name;
}
// ── นับ User ที่มี VALID_DAYS แต่ยังไม่มี START_DATE/END_DATE (file-level) ──
$_pos_menu_pending = 0;
if($pos_priority === 'A' && function_exists('_pos_auth_sql')) {
    $_res_pc = _pos_auth_sql(
        "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 50 TRIMSPOOL ON\n"
       ."ALTER SESSION SET NLS_LANGUAGE = American;\n"
       ."SELECT COUNT(*) FROM POS.SK_WEB_USER\n"
       ."WHERE (START_DATE IS NULL OR END_DATE IS NULL) AND VALID_DAYS IS NOT NULL AND VALID_DAYS > 0;\nEXIT;\n",
        $instant_client_path,$oracle_user,$oracle_pass,$oracle_tns);
    foreach(preg_split('/\r?\n/',$_res_pc) as $_pcl){
        $_pcl=trim($_pcl);
        if($_pcl!==''&&is_numeric($_pcl)&&!preg_match('/^(ORA-|SP2-)/',$_pcl)){$_pos_menu_pending=(int)$_pcl;break;}
    }
}

// ── นับจำนวนข้อความเตือนรวม (ใช้แสดง badge ที่ปุ่มเมนู) ────
$_menu_warn_count = 0;
// 1. account expiry
$_mw_exp_days   = $pos_expiry_days_left ?? null;
$_mw_exp_status = $pos_expiry_status    ?? 'ok';
if ($_mw_exp_days !== null && $_mw_exp_days <= 30) $_menu_warn_count++;
// 2. password age
$_mw_pwd_days   = $pos_pwd_days_left ?? null;
$_mw_pwd_status = $pos_pwd_status    ?? 'ok';
if ($_mw_pwd_days !== null && $_mw_pwd_status !== 'ok') $_menu_warn_count++;
// 3. pending users (admin only) — นับเป็น warn ถ้ามี
if ($pos_priority === 'A' && !empty($_pos_menu_pending)) $_menu_warn_count += (int)$_pos_menu_pending;

if(!function_exists('pos_nav_buttons')){
    function pos_nav_buttons($priority,$active){
        global $_pos_menu_pending;
        $is_search=in_array($active,['all','all_items','all_members','all_sales']);
        $pending=$priority==='A'?(int)$_pos_menu_pending:0;

        echo '<div style="text-align:left;margin-bottom:20px;"><div class="stats-grid">';
        if($is_search){
            $list=[];
            if(_perm_view('home')||_perm_view('detail')||_perm_view('members')) $list[]=['POS_HOME.php','Main','','o',0];
            foreach([['POS_ALL.php','รายการขาย','all','',0],['POS_ALL_ITEMS.php','สินค้า','all_items','',0],
                ['POS_ALL_MEMBERS.php','สมาชิก','all_members','',0],['POS_ALL_SALES.php','รายงาน','all_sales','',0]] as $_sb){
                if(_perm_view($_sb[2])) $list[]=$_sb;
            }
        } else {
            $list=[];
            if(_perm_view('detail')||_perm_view('items')) $list[]=['index.php','Main','','o',0];
            foreach([['POS_DETAIL.php','รายการขาย','detail','',0],
                ['POS_ITEMS.php','สินค้า','items','',0],
                ['POS_MEMBERS.php','สมาชิก','members','',0],
                ['POS_SALES.php','รายงาน','sales','',0],
                ['POS_USER.php','User Management','usermgmt','p',$pending]] as $b){
                if(_perm_view($b[2])) $list[]=$b;
            }
            $_search_targets=[['all','POS_ALL.php'],['all_items','POS_ALL_ITEMS.php'],
                ['all_members','POS_ALL_MEMBERS.php'],['all_sales','POS_ALL_SALES.php']];
            foreach($_search_targets as $_st){
                if(_perm_view($_st[0])){$list[]=[$_st[1],'ค้นหา','search','',0];break;}
            }
        }
        foreach($list as $b){
            $cur=$b[2]!==''&&$b[2]===$active;
            $cnt=isset($b[4])?(int)$b[4]:0;
            if($b[3]==='o')     {$bg='background:#ff6b35;';$icon='';}
            elseif($b[3]==='p') {$bg='background:linear-gradient(135deg,#9c27b0,#6a1b9a);box-shadow:0 0 14px rgba(156,39,176,0.5);';$icon='<i class="fas fa-shield-alt" style="margin-right:6px;"></i>';}
            elseif($cur)        {$bg='background:#00bcd4;border:2px solid #0ff;box-shadow:0 0 12px rgba(0,255,255,0.5);';$icon='';}
            else                {$bg='background:#4caf50;';$icon='';}
            $badge=$cnt>0
                ? '<span style="position:absolute;top:-8px;right:-8px;background:#ff4444;color:#fff;border-radius:50%;min-width:22px;height:22px;font-size:12px;font-weight:bold;display:flex;align-items:center;justify-content:center;padding:0 4px;box-shadow:0 0 8px rgba(255,68,68,0.7);pointer-events:none;line-height:1;">'.$cnt.'</span>'
                : '';
            echo '<div class="stat-card"><div style="position:relative;display:inline-block;"><button type="button" onclick="window.location.href=\''.$b[0].'\'" style="'.$bg.'color:#fff;border:none;padding:10px 24px;border-radius:6px;cursor:pointer;font-size:16px;font-weight:bold;">'.$icon.$b[1].'</button>'.$badge.'</div></div>';
        }
        echo '</div></div>';
    }
}

// ── CSS ──────────────────────────────────────────────────────
$_o='<style>';
$_o.='#menu-toggle-btn{position:fixed;top:20px;left:20px;z-index:9999;width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#0d2233,#0a1a28);border:2px solid #0ff;color:#0ff;font-size:20px;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 0 18px rgba(0,255,255,0.35);transition:all 0.25s;padding:0;margin:0}';


$_o.='#menu-toggle-btn:hover{background:linear-gradient(135deg,#0ff 0%,#00bcd4 100%);color:#001a1a;transform:none;box-shadow:0 0 28px rgba(0,255,255,0.7)}';
$_o.='#sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:10000;backdrop-filter:blur(3px);opacity:0;transition:opacity 0.3s}#sidebar-overlay.open{display:block;opacity:1}';
$_o.='#sidebar-panel{position:fixed;top:0;left:-320px;width:300px;height:100%;background:linear-gradient(180deg,#0a1628 0%,#071020 100%);border-right:2px solid #0ff;box-shadow:6px 0 40px rgba(0,255,255,0.2);z-index:10001;transition:left 0.35s cubic-bezier(0.4,0,0.2,1);display:flex;flex-direction:column;overflow:hidden}#sidebar-panel.open{left:0}';
$_o.='.sidebar-header{padding:22px 20px 16px;border-bottom:1px solid rgba(0,255,255,0.2);display:flex;align-items:center;gap:12px;background:rgba(0,255,255,0.05)}.sidebar-header-icon{font-size:28px;color:#0f0}.sidebar-header-title{font-size:20px;font-weight:bold;color:#0ff;letter-spacing:2px;flex:1}';
$_o.='.sidebar-close-btn{width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,0.05);border:1px solid rgba(0,255,255,0.3);color:#0ff;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all 0.2s;padding:0;margin:0}.sidebar-close-btn:hover{background:rgba(0,255,255,0.15);transform:none}';
$_o.='.sidebar-user-strip{padding:12px 20px;background:rgba(0,255,255,0.04);border-bottom:1px solid rgba(0,255,255,0.12);font-size:13px;color:#0f0;display:flex;align-items:center;gap:8px}.sidebar-priority-badge{font-size:10px;font-weight:bold;padding:2px 8px;border-radius:20px;margin-left:auto}';
$_o.='.badge-admin{background:rgba(156,39,176,0.3);border:1px solid #ce93d8;color:#ce93d8}.badge-user{background:rgba(0,188,212,0.2);border:1px solid #0ff;color:#0ff}.badge-member{background:rgba(76,175,80,0.2);border:1px solid #4caf50;color:#4caf50}';
$_o.='.sidebar-section-label{font-size:10px;font-weight:bold;color:rgba(0,255,255,0.45);letter-spacing:2px;text-transform:uppercase;padding:14px 20px 6px}';
$_o.='.sidebar-nav{flex:1;overflow-y:auto;padding:6px 12px 12px;scrollbar-width:thin;scrollbar-color:rgba(0,255,255,0.2) transparent}.sidebar-nav::-webkit-scrollbar{width:4px}.sidebar-nav::-webkit-scrollbar-thumb{background:rgba(0,255,255,0.2);border-radius:4px}';
$_o.='.sidebar-menu-item{display:flex;align-items:center;gap:14px;padding:13px 16px;border-radius:10px;margin-bottom:4px;cursor:pointer;text-decoration:none;color:#ccc;font-size:15px;font-weight:500;transition:all 0.2s;border:1px solid transparent;position:relative;overflow:hidden;user-select:none}';
$_o.='.sidebar-menu-item::before{content:"";position:absolute;left:-100%;top:0;width:100%;height:100%;background:linear-gradient(90deg,transparent,rgba(0,255,255,0.08),transparent);transition:left 0.4s}.sidebar-menu-item:hover::before{left:100%}';
$_o.='.sidebar-menu-item:hover{background:rgba(0,255,255,0.1);color:#0ff;border-color:rgba(0,255,255,0.25);transform:translateX(4px)}.sidebar-menu-item.active{background:rgba(0,255,255,0.12);color:#0ff;border-color:rgba(0,255,255,0.4);font-weight:bold}';
$_o.='.sidebar-menu-item .menu-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}';
$_o.='.icon-home{background:rgba(0,188,212,0.2);color:#0ff}.icon-main{background:rgba(255,107,53,0.2);color:#ff6b35}.icon-sale{background:rgba(76,175,80,0.2);color:#4caf50}.icon-product{background:rgba(0,188,212,0.2);color:#0ff}.icon-member{background:rgba(255,152,0,0.2);color:#ff9800}.icon-report{background:rgba(33,150,243,0.2);color:#64b5f6}.icon-search{background:rgba(255,193,7,0.2);color:#ffca28}.icon-user{background:rgba(156,39,176,0.2);color:#ce93d8}.icon-logout{background:rgba(244,67,54,0.2);color:#ef5350}';
$_o.='.sidebar-menu-item.has-sub .sub-arrow{margin-left:auto;font-size:11px;color:rgba(0,255,255,0.5);transition:transform 0.3s;flex-shrink:0}.sidebar-menu-item.has-sub.sub-open .sub-arrow{transform:rotate(90deg);color:#0ff}';
$_o.='.sidebar-submenu{overflow:hidden;max-height:0;opacity:0;transition:max-height 0.35s cubic-bezier(0.4,0,0.2,1),opacity 0.25s ease}.sidebar-submenu.sub-open{max-height:300px;opacity:1}';
$_o.='.sidebar-sub-item{display:flex;align-items:center;gap:10px;padding:10px 16px 10px 30px;border-radius:8px;margin-bottom:2px;margin-left:12px;cursor:pointer;text-decoration:none;color:#aaa;font-size:13px;transition:all 0.2s;border-left:2px solid transparent}.sidebar-sub-item:hover{color:#0ff;background:rgba(0,255,255,0.07);border-left-color:rgba(0,255,255,0.4);padding-left:36px}.sidebar-sub-item.active{color:#0ff;background:rgba(0,255,255,0.1);border-left-color:#0ff;font-weight:bold}.sidebar-sub-item .sub-dot{width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0;opacity:0.6}.sidebar-sub-item.active .sub-dot{opacity:1;box-shadow:0 0 6px currentColor}';
$_o.='.sidebar-divider{height:1px;background:linear-gradient(90deg,transparent,rgba(0,255,255,0.15),transparent);margin:8px}.sidebar-footer{padding:14px 20px;border-top:1px solid rgba(0,255,255,0.15);font-size:11px;color:rgba(0,255,255,0.3);text-align:center;letter-spacing:1px}';
$_o.='.sidebar-quickbtns{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;padding:4px 4px 8px}.sqb{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;padding:10px 4px;border-radius:10px;text-decoration:none;font-size:11px;font-weight:600;color:#ccc;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);transition:all 0.2s;text-align:center}.sqb i{font-size:18px}.sqb:hover{transform:translateY(-2px);border-color:currentColor;color:#fff}.sqb.sqb-active{border-color:currentColor!important;font-weight:bold;color:#fff;background:rgba(255,255,255,0.1)}';
$_o.='.sqb-orange{color:#ff6b35}.sqb-orange:hover,.sqb-orange.sqb-active{background:rgba(255,107,53,0.15)}.sqb-green{color:#4caf50}.sqb-green:hover,.sqb-green.sqb-active{background:rgba(76,175,80,0.15)}.sqb-cyan{color:#00bcd4}.sqb-cyan:hover,.sqb-cyan.sqb-active{background:rgba(0,188,212,0.15)}.sqb-amber{color:#ff9800}.sqb-amber:hover,.sqb-amber.sqb-active{background:rgba(255,152,0,0.15)}.sqb-blue{color:#64b5f6}.sqb-blue:hover,.sqb-blue.sqb-active{background:rgba(100,181,246,0.15)}.sqb-yellow{color:#ffca28}.sqb-yellow:hover,.sqb-yellow.sqb-active{background:rgba(255,202,40,0.15)}';
$_o.='@keyframes _neon_sc{0%{transform:translateY(-100%)}100%{transform:translateY(100vh)}}.sidebar-neon-line{position:absolute;top:0;right:0;width:2px;height:80px;background:linear-gradient(180deg,transparent,#0ff,transparent);opacity:0.5;animation:_neon_sc 3s linear infinite;pointer-events:none}@media(max-width:480px){#sidebar-panel{width:280px}}';
$_o.='.menu-warn-badge{position:absolute;top:-6px;right:-6px;min-width:18px;height:18px;background:#ff1744;color:#fff;font-size:10px;font-weight:bold;border-radius:20px;display:flex;align-items:center;justify-content:center;padding:0 4px;box-shadow:0 0 8px rgba(255,23,68,0.7);pointer-events:none;line-height:1;animation:"";}';
// animation:badge-blink 10s ease-in-out infinite
$_o.='@keyframes badge-blink{0%,100%{opacity:1}50%{opacity:0.5}}';
$_o.='</style>';

// ── HTML ─────────────────────────────────────────────────────
$_menu_warn_badge = $_menu_warn_count > 0
    ? '<span class="menu-warn-badge" id="menu-warn-badge">' . $_menu_warn_count . '</span>'
    : '<span class="menu-warn-badge" id="menu-warn-badge" style="display:none;">0</span>';
$_o.='<button id="menu-toggle-btn" title="เมนู" onclick="toggleSidebar()" style="position:"";">'
   .'<i class="fas fa-bars" id="menu-icon"></i>'
   .$_menu_warn_badge
   .'</button>';
$_o.='<div id="sidebar-overlay" onclick="closeSidebar()"></div>';
$_o.='<div id="sidebar-panel"><div class="sidebar-neon-line"></div>';
$_o.='<div class="sidebar-header"><i class="fas fa-cash-register sidebar-header-icon"></i><span class="sidebar-header-title">POS MENU</span><button class="sidebar-close-btn" onclick="closeSidebar()"><i class="fas fa-times"></i></button></div>';
$_o.='<div class="sidebar-user-strip"><i class="fas fa-user-circle" style="color:#0ff;font-size:16px;"></i><span style="flex:1;min-width:0;"><span style="color:#0ff;">'.htmlspecialchars($pos_logged_user).'</span>'.($_pos_full_name !== '' && $_pos_full_name !== '-' ? '<br><span style="color:#aaa;font-size:11px;">'.htmlspecialchars($_pos_full_name).'</span>' : '').'</span><span class="sidebar-priority-badge '.$_mbc.'">'.$_mbt.'</span></div>';
$_o.='<div class="sidebar-nav">';

// Quick buttons
$_quick=[['POS_HOME.php','Dashboard','home','sqb-orange','fas fa-tachometer-alt'],['POS_DETAIL.php','รายการขาย','detail','sqb-green','fas fa-receipt'],['POS_ITEMS.php','สินค้า','items','sqb-cyan','fas fa-boxes'],['POS_MEMBERS.php','สมาชิก','members','sqb-amber','fas fa-users'],['POS_SALES.php','รายงาน','sales','sqb-blue','fas fa-chart-bar'],['POS_ALL.php','ค้นหา','search','sqb-yellow','fas fa-search']];
$_o.='<div class="sidebar-section-label">เมนูด่วน</div><div class="sidebar-quickbtns">';
foreach($_quick as $_q){
    $code=$_q[2];
    $show=$code==='search'
        ? (_perm_view('all')||_perm_view('all_items')||_perm_view('all_members')||_perm_view('all_sales'))
        : _perm_view($code);
    if(!$show) continue;
    $act=($MENU_ACTIVE===$code||($code==='search'&&$_sa))?' sqb-active':'';
    $_o.='<a href="'.$_q[0].'" class="sqb '.$_q[3].$act.'"><i class="'.$_q[4].'"></i><span>'.$_q[1].'</span></a>';
}
$_o.='</div><div class="sidebar-divider"></div>';

// Menu items
$_o.='<div class="sidebar-section-label">หน้าหลัก</div>';
if(_perm_view('home')) $_o.=_pos_mi('POS_HOME.php','icon-home','fas fa-tachometer-alt','Dashboard','home',$MENU_ACTIVE);
$_has_main=_perm_view('detail')||_perm_view('items')||_perm_view('members')||_perm_view('sales');
if($_has_main){
    $_o.='<div class="sidebar-section-label">เมนูหลัก</div>';
    if(_perm_view('detail'))  $_o.=_pos_mi('POS_DETAIL.php', 'icon-sale',   'fas fa-receipt',  'รายการขาย','detail', $MENU_ACTIVE);
    if(_perm_view('items'))   $_o.=_pos_mi('POS_ITEMS.php',  'icon-product','fas fa-boxes',    'สินค้า',   'items',  $MENU_ACTIVE);
    if(_perm_view('members')) $_o.=_pos_mi('POS_MEMBERS.php','icon-member', 'fas fa-users',    'สมาชิก',  'members',$MENU_ACTIVE);
    if(_perm_view('sales'))   $_o.=_pos_mi('POS_SALES.php',  'icon-report', 'fas fa-chart-bar','รายงาน',  'sales',  $MENU_ACTIVE);
}
$_has_search=_perm_view('all')||_perm_view('all_items')||_perm_view('all_members')||_perm_view('all_sales');
if($_has_search){
    $_o.='<div class="sidebar-menu-item has-sub'.$_sp.'" onclick="toggleSubMenu(this)"><span class="menu-icon icon-search"><i class="fas fa-search"></i></span><span>ค้นหา</span><i class="fas fa-chevron-right sub-arrow"></i></div>';
    $_o.='<div class="sidebar-submenu'.$_sm.'">';
    $subs=['all'=>['POS_ALL.php','รายการขาย'],'all_items'=>['POS_ALL_ITEMS.php','สินค้า'],'all_members'=>['POS_ALL_MEMBERS.php','สมาชิก'],'all_sales'=>['POS_ALL_SALES.php','รายงาน']];
    foreach($subs as $sk=>$sv){
        if(_perm_view($sk)){$cls=$sk===$MENU_ACTIVE?' active':'';$_o.='<a href="'.$sv[0].'" class="sidebar-sub-item'.$cls.'"><span class="sub-dot"></span><span>'.$sv[1].'</span></a>';}
    }
    $_o.='</div>';
}
if(_perm_view('usermgmt')){
    $_o.='<div class="sidebar-divider"></div><div class="sidebar-section-label">ผู้ดูแลระบบ</div>';
    $_pb=$_pos_menu_pending>0
        ? '<span style="margin-left:auto;background:#ff1744;color:#fff;font-size:10px;font-weight:bold;padding:2px 7px;border-radius:20px;box-shadow:0 0 8px rgba(255,23,68,0.7);">'.$_pos_menu_pending.'</span>'
        : '';
    $_uc='usermgmt'===$MENU_ACTIVE?' active':'';
    $_o.='<a href="POS_USER.php" class="sidebar-menu-item'.$_uc.'">'
       .'<span class="menu-icon icon-user"><i class="fas fa-shield-alt"></i></span>'
       .'<span>User Management</span>'.$_pb.'</a>';
}
$_o.='<div class="sidebar-divider"></div><div class="sidebar-section-label">บัญชี</div>';
// โปรไฟล์ — แสดง badge ถ้ามี warning ส่วนตัว (expiry / password)
$_profile_warn = 0;
if ($_mw_exp_days !== null && $_mw_exp_days <= 30) $_profile_warn++;
if ($_mw_pwd_days !== null && $_mw_pwd_status !== 'ok') $_profile_warn++;
$_pb_profile = $_profile_warn > 0
    ? '<span style="margin-left:auto;background:#ff1744;color:#fff;font-size:10px;font-weight:bold;padding:2px 7px;border-radius:20px;box-shadow:0 0 8px rgba(255,23,68,0.7);">'.$_profile_warn.'</span>'
    : '';
$_uc_profile = 'profile' === $MENU_ACTIVE ? ' active' : '';
$_o.='<a href="POS_USER.php" class="sidebar-menu-item'.$_uc_profile.'">'
   .'<span class="menu-icon icon-product"><i class="fas fa-user-cog"></i></span>'
   .'<span>โปรไฟล์</span>'.$_pb_profile.'</a>';
$_o.='<a href="logout.php" class="sidebar-menu-item" onclick="return confirm(\'ออกจากระบบ?\')">'
   .'<span class="menu-icon icon-logout"><i class="fas fa-sign-out-alt"></i></span><span>ออกจากระบบ</span></a>';
$_o.='</div><div class="sidebar-footer">POS SYSTEM &copy; '.date('Y').'</div></div>';

// ── JavaScript ───────────────────────────────────────────────
$_o.='<script>';
$_o.='function toggleSidebar(){var p=document.getElementById("sidebar-panel"),o=document.getElementById("sidebar-overlay"),i=document.getElementById("menu-icon");if(p.classList.contains("open")){closeSidebar();return;}p.classList.add("open");o.classList.add("open");i.className="fas fa-times";document.body.style.overflow="hidden";}';
$_o.='function closeSidebar(){document.getElementById("sidebar-panel").classList.remove("open");setTimeout(function(){document.getElementById("sidebar-overlay").classList.remove("open");},10);document.getElementById("menu-icon").className="fas fa-bars";document.body.style.overflow="";}';
$_o.='function toggleSubMenu(el){var isOpen=el.classList.contains("sub-open");document.querySelectorAll(".sidebar-menu-item.has-sub.sub-open").forEach(function(e){e.classList.remove("sub-open");var s=e.nextElementSibling;if(s&&s.classList.contains("sidebar-submenu"))s.classList.remove("sub-open");});if(!isOpen){el.classList.add("sub-open");var sub=el.nextElementSibling;if(sub&&sub.classList.contains("sidebar-submenu"))sub.classList.add("sub-open");}}';
$_o.='document.addEventListener("keydown",function(e){if(e.key==="Escape")closeSidebar();});';
// อัพเดท menu badge เมื่อ session เริ่ม warn (เชื่อมกับ TOPRIGHT)
$_o.='(function(){';
$_o.='  var _mwb=document.getElementById("menu-warn-badge");';
$_o.='  if(!_mwb) return;';
$_o.='  var _baseWarn=' . (int)min($_menu_warn_count, 99) . ';';  // warn จาก PHP (ไม่นับ session)
$_o.='  var _sesAdded=false;';
$_o.='  window._posMenuAddSesWarn=function(){';
$_o.='    if(_sesAdded) return; _sesAdded=true;';
$_o.='    var n=_baseWarn+1; _mwb.textContent=n>99?"99+":n;';
$_o.='    _mwb.style.display="";';
$_o.='  };';
$_o.='})();';
$_o.='</script>';
echo $_o;
