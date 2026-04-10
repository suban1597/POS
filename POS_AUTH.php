<?php
// ============================================================
//  POS_AUTH.php — โหลด Permission จาก DB
// ============================================================

if (!isset($instant_client_path)) $instant_client_path = '/opt/oracle/instantclient_21_4';
if (!isset($oracle_user))         $oracle_user         = 'system';
if (!isset($oracle_pass))         $oracle_pass         = 'system';
if (!isset($oracle_tns))          $oracle_tns          = 'CUBACKUP';

// ── run_sqlplus helper ──────────────────────────────────────
if (!function_exists('_pos_auth_sql')) {
    function _pos_auth_sql(string $sql, string $lib, string $user, string $pass, string $tns): string {
        $sqlplus = rtrim($lib, '/') . '/sqlplus';
        if (!is_executable($sqlplus)) return '';
        $tmp = sys_get_temp_dir() . '/POS_AUTH_' . uniqid() . '.sql';
        file_put_contents($tmp, $sql);
        $up  = escapeshellarg("{$user}/{$pass}@{$tns}");
        $cmd = "env -i LD_LIBRARY_PATH={$lib} TNS_ADMIN={$lib}"
             . " NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus} -s {$up} @{$tmp} 2>&1";
        $out = (string)shell_exec($cmd);
        @unlink($tmp);
        return $out;
    }
}

if (!isset($pos_menu_perms)) {
    $pos_menu_perms = [];
    $pos_menu_tree  = [];

    if (!empty($pos_logged_user) && !empty($pos_priority)) {

        $safe_role = str_replace("'", "''", $pos_priority);

        // ── Query 1: ดึง POS_MENU_DEF (แยกออกมา ไม่ concatenate กับ permission) ──
        $sql_menus = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 2000 TRIMSPOOL ON\n"
                   . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
                   . "SELECT RTRIM(MENU_CODE)||'~|~'||NVL(RTRIM(PHP_FILE),'NULL')||'~|~'||NVL(RTRIM(PARENT_CODE),'NULL')"
                   . "||'~|~'||TO_CHAR(SEQ)||'~|~'||NVL(RTRIM(ICON_CLASS),'fas fa-circle')||'~|~'||RTRIM(MENU_NAME)\n"
                   . "FROM POS.POS_MENU_DEF WHERE IS_ACTIVE='Y'\n"
                   . "ORDER BY PARENT_CODE NULLS FIRST, SEQ;\nEXIT;\n";

        // ── Query 2: ดึง POS_MENU_PERMISSION สำหรับ role นี้ ──
        $sql_perms = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 200 TRIMSPOOL ON\n"
                   . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
                   . "SELECT RTRIM(MENU_CODE)||'~|~'||CAN_VIEW||'~|~'||CAN_USE\n"
                   . "FROM POS.POS_MENU_PERMISSION\n"
                   . "WHERE RTRIM(ROLE_CODE) = '{$safe_role}';\nEXIT;\n";

        $out_menus = _pos_auth_sql($sql_menus, $instant_client_path, $oracle_user, $oracle_pass, $oracle_tns);
        $out_perms = _pos_auth_sql($sql_perms, $instant_client_path, $oracle_user, $oracle_pass, $oracle_tns);

        $has_error = preg_match('/^(ORA-|SP2-)/m', $out_menus) || preg_match('/^(ORA-|SP2-)/m', $out_perms);

        if (!$has_error) {
            // parse permissions → map[menu_code] = [view, use]
            $perm_map = [];
            foreach (preg_split('/\r?\n/', $out_perms) as $line) {
                $line = trim($line);
                if ($line === '' || preg_match('/^(ORA-|SP2-)/', $line)) continue;
                $p = explode('~|~', $line);
                if (count($p) >= 3) {
                    $perm_map[trim($p[0])] = [
                        'can_view' => trim($p[1]) === 'Y',
                        'can_use'  => trim($p[2]) === 'Y',
                    ];
                }
            }

            // parse menus → combine กับ permissions
            foreach (preg_split('/\r?\n/', $out_menus) as $line) {
                $line = trim($line);
                if ($line === '' || preg_match('/^(ORA-|SP2-)/', $line)) continue;
                $p = explode('~|~', $line);
                if (count($p) < 5) continue;
                $code   = trim($p[0]);
                $file   = trim($p[1]) === 'NULL' ? null : trim($p[1]);
                $parent = trim($p[2]) === 'NULL' ? null : trim($p[2]);
                $seq    = (int)trim($p[3]);
                $icon   = trim($p[4]);
                $name   = isset($p[5]) ? trim($p[5]) : $code;
                if ($code === '') continue;

                $c_view = !empty($perm_map[$code]['can_view']);
                $c_use  = !empty($perm_map[$code]['can_use']);

                $pos_menu_perms[$code] = [
                    'name'     => $name,
                    'file'     => $file,
                    'parent'   => $parent,
                    'seq'      => $seq,
                    'icon'     => $icon,
                    'can_view' => $c_view,
                    'can_use'  => $c_use,
                ];
            }
        }

        if (empty($pos_menu_perms)) $pos_menu_perms = []; // force fallback
    }

    // ── Fallback: hardcode จาก $pos_priority ──────────────────
    if (empty($pos_menu_perms)) {
        $role = $pos_priority ?? 'U';
        $all = [
            'home'        => [null,     1, 'fas fa-tachometer-alt', 'Dashboard'],
            'detail'      => [null,     2, 'fas fa-receipt',        'รายการขาย'],
            'items'       => [null,     3, 'fas fa-boxes',          'สินค้า'],
            'members'     => [null,     4, 'fas fa-users',          'สมาชิก'],
            'sales'       => [null,     5, 'fas fa-chart-bar',      'รายงาน'],
            'search'      => [null,     6, 'fas fa-search',         'ค้นหา'],
            'usermgmt'    => [null,     7, 'fas fa-shield-alt',     'User Management'],
            'all'         => ['search', 1, 'fas fa-receipt',        'รายการขาย'],
            'all_items'   => ['search', 2, 'fas fa-boxes',          'สินค้า'],
            'all_members' => ['search', 3, 'fas fa-users',          'สมาชิก'],
            'all_sales'   => ['search', 4, 'fas fa-chart-bar',      'รายงาน'],
        ];
        $files = ['home'=>'POS_HOME.php','detail'=>'POS_DETAIL.php','items'=>'POS_ITEMS.php',
                  'members'=>'POS_MEMBERS.php','sales'=>'POS_SALES.php','search'=>null,
                  'usermgmt'=>'POS_USER.php','all'=>'POS_ALL.php','all_items'=>'POS_ALL_ITEMS.php',
                  'all_members'=>'POS_ALL_MEMBERS.php','all_sales'=>'POS_ALL_SALES.php'];
        $deny = match($role) {
            'M' => ['detail','items','sales','search','usermgmt','all','all_items','all_sales'],
            'U' => ['usermgmt'],
            default => [],
        };
        foreach ($all as $code => $v) {
            $ok = !in_array($code, $deny);
            $pos_menu_perms[$code] = [
                'name'=>$v[3], 'file'=>$files[$code], 'parent'=>$v[0],
                'seq'=>$v[1], 'icon'=>$v[2], 'can_view'=>$ok, 'can_use'=>$ok,
            ];
        }
    }

    // ── Build tree ────────────────────────────────────────────
    foreach ($pos_menu_perms as $code => $m) {
        if (empty($m['parent'])) {
            $pos_menu_tree[$code] = $m;
            $pos_menu_tree[$code]['children'] = [];
        }
    }
    foreach ($pos_menu_perms as $code => $m) {
        if (!empty($m['parent']) && isset($pos_menu_tree[$m['parent']])) {
            $pos_menu_tree[$m['parent']]['children'][$code] = $m;
        }
    }
}

// ── Branch Access ─────────────────────────────────────────────
// $pos_allowed_branches = null  → Admin (ไม่จำกัด ดูทุกสาขา)
// $pos_allowed_branches = []    → ยังไม่ได้กำหนดสิทธิ์ (ดูไม่ได้เลย)
// $pos_allowed_branches = ['110','111'] → เห็นเฉพาะสาขาที่กำหนด
if (!isset($pos_allowed_branches)) {
    $pos_allowed_branches = null; // null = เห็นทุกสาขา

    if (!empty($pos_logged_user)) {
        if (($pos_priority ?? 'U') === 'A') {
            // Admin เห็นทุกสาขา — null หมายถึง "ไม่จำกัด"
            $pos_allowed_branches = null;
        } else {
            $safe_uid = str_replace("'", "''", $pos_logged_user);
            $sql_br = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 200 TRIMSPOOL ON\n"
                    . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
                    . "SELECT TRIM(BRANCH_ID) FROM POS.USER_BRANCH_ACCESS\n"
                    . "WHERE UPPER(TRIM(USER_ID)) = UPPER('{$safe_uid}');\nEXIT;\n";
            $out_br = _pos_auth_sql($sql_br, $instant_client_path, $oracle_user, $oracle_pass, $oracle_tns);
            $branches = [];
            foreach (preg_split('/\r?\n/', $out_br) as $line) {
                $line = trim($line);
                if ($line === '' || preg_match('/^(ORA-|SP2-)/', $line)) continue;
                if ($line !== '') $branches[] = $line;
            }
            $pos_allowed_branches = $branches;
        }
    }
}

// ── Helpers ───────────────────────────────────────────────────
if (!function_exists('pos_can_view')) {
    function pos_can_view(string $code): bool {
        global $pos_menu_perms;
        return !empty($pos_menu_perms[$code]['can_view']);
    }
}
if (!function_exists('pos_can_use')) {
    function pos_can_use(string $code): bool {
        global $pos_menu_perms;
        return !empty($pos_menu_perms[$code]['can_use']);
    }
}
if (!function_exists('pos_guard')) {
    function pos_guard(string $code, string $redirect = 'POS_HOME.php'): void {
        if ($code === 'home') return;
        if (!pos_can_use($code)) {
            header('Location: ' . $redirect);
            exit;
        }
    }
}

// คืนค่า array ของสาขาที่ User มีสิทธิ์
// null = Admin (ไม่จำกัด), [] = ไม่มีสิทธิ์, ['110','111'] = เฉพาะสาขานี้
if (!function_exists('pos_get_branches')) {
    function pos_get_branches(): ?array {
        global $pos_allowed_branches;
        return $pos_allowed_branches;
    }
}

// คืน SQL fragment สำหรับใส่ใน WHERE clause
// ใช้แทนที่ทุก query ที่ต้องกรองสาขา เช่น:
//   "WHERE " . pos_branch_sql('s.SALE_OFFICE')
// ผลลัพธ์:
//   Admin    → "1=1"  (ไม่กรอง)
//   User     → "s.SALE_OFFICE IN ('110','111')"
//   ไม่มีสิทธิ์ → "1=0"  (ไม่เห็นข้อมูลใดเลย)
if (!function_exists('pos_branch_sql')) {
    function pos_branch_sql(string $col = 'SALE_OFFICE'): string {
        global $pos_allowed_branches;
        if ($pos_allowed_branches === null) return "1=1";
        if (empty($pos_allowed_branches))  return "1=1";
        $quoted = array_map(fn($b) => "'" . str_replace("'", "''", $b) . "'", $pos_allowed_branches);
        return $col . " IN (" . implode(',', $quoted) . ")";
    }
}

// ตรวจว่า User มีสิทธิ์เห็นสาขานี้หรือเปล่า
if (!function_exists('pos_can_see_branch')) {
    function pos_can_see_branch(string $branch_id): bool {
        global $pos_allowed_branches;
        if ($pos_allowed_branches === null)  return true;
        if (empty($pos_allowed_branches))    return true;
        return in_array(trim($branch_id), $pos_allowed_branches, true);
    }
}

// ══════════════════════════════════════════════════════════════
//  ตรวจสอบวันหมดอายุบัญชี (END_DATE ใน POS.SK_WEB_USER)
// ══════════════════════════════════════════════════════════════
if (!isset($pos_expiry_status)) {
    $pos_expiry_status    = 'ok';   // ok | warning | expired
    $pos_expiry_days_left = null;   // วันคงเหลือ (null = ไม่ได้กำหนด)
    $pos_expiry_date_str  = '';

    // Admin ไม่ถูกตรวจสอบ
    if (!empty($pos_logged_user) && ($pos_priority ?? 'U') !== 'A') {
        $safe_uid_exp = str_replace("'", "''", $pos_logged_user);
        $sql_expiry   = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 100 TRIMSPOOL ON\n"
                      . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
                      . "SELECT NVL(TO_CHAR(END_DATE,'DD/MM/YYYY'),'')\n"
                      . "FROM POS.SK_WEB_USER\n"
                      . "WHERE UPPER(TRIM(USER_ID))=UPPER('{$safe_uid_exp}') AND ROWNUM=1;\nEXIT;\n";
        $out_expiry = _pos_auth_sql($sql_expiry, $instant_client_path, $oracle_user, $oracle_pass, $oracle_tns);

        foreach (preg_split('/\r?\n/', $out_expiry) as $_el) {
            $_el = trim($_el);
            if ($_el === '' || preg_match('/^(ORA-|SP2-)/', $_el)) continue;
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $_el)) {
                $pos_expiry_date_str = $_el;
                $end_dt = DateTime::createFromFormat('d/m/Y', $_el);
                if ($end_dt) {
                    $today_dt  = new DateTime('today');
                    $diff_days = (int)$today_dt->diff($end_dt)->format('%r%a');
                    $pos_expiry_days_left = $diff_days;
                    if      ($diff_days < 0)   $pos_expiry_status = 'expired';
                    elseif  ($diff_days <= 7)  $pos_expiry_status = 'warning';
                }
                break;
            }
        }
    }
}

// ══════════════════════════════════════════════════════════════
//  ตรวจสอบอายุรหัสผ่าน (PWD_END_DATE)
//  เตือนเมื่อเหลือ ≤ 15 วัน / บังคับเปลี่ยนเมื่อหมดอายุ
// ══════════════════════════════════════════════════════════════
if (!isset($pos_pwd_days_left)) {
    $pos_pwd_days_left = null;   // null = ไม่มีข้อมูล
    $pos_pwd_status    = 'ok';   // ok | warning | expired

    if (!empty($pos_logged_user)) {
        $safe_uid_pwd = str_replace("'", "''", $pos_logged_user);
        // ดึง PWD_END_DATE (คำนวณแล้ว), PWD_VALID_DAYS
        $sql_pwd = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 300 TRIMSPOOL ON\n"
                 . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
                 . "SELECT NVL(TO_CHAR(PWD_END_DATE,'DD/MM/YYYY'),'')"
                 . "||'|'||NVL(TO_CHAR(PWD_VALID_DAYS),'')\n"
                 . "FROM POS.SK_WEB_USER\n"
                 . "WHERE UPPER(TRIM(USER_ID))=UPPER('{$safe_uid_pwd}') AND ROWNUM=1;\nEXIT;\n";
        $out_pwd = _pos_auth_sql($sql_pwd, $instant_client_path, $oracle_user, $oracle_pass, $oracle_tns);

        foreach (preg_split('/\r?\n/', $out_pwd) as $_pl) {
            $_pl = trim($_pl);
            if ($_pl === '' || preg_match('/^(ORA-|SP2-)/', $_pl)) continue;
            $parts_pwd    = explode('|', $_pl, 2);
            $end_date_str = trim($parts_pwd[0]);   // PWD_END_DATE (คำนวณแล้ว)

            $today = new DateTime('today');

            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $end_date_str)) {
                // ใช้ PWD_END_DATE โดยตรง
                $end_dt = DateTime::createFromFormat('d/m/Y', $end_date_str);
                if ($end_dt) {
                    $days_left = (int)$today->diff($end_dt)->format('%r%a');
                    $pos_pwd_days_left = max(0, $days_left);
                    if      ($days_left <= 0)   $pos_pwd_status = 'expired';
                    elseif  ($days_left <= 15)  $pos_pwd_status = 'warning';
                }
            }
            break;
        }
    }
}

// ══════════════════════════════════════════════════════════════
//  ติดตาม Session Timeout
//  $pos_session_remaining = วินาทีที่เหลือก่อน session หมด
// ══════════════════════════════════════════════════════════════
if (!isset($pos_session_remaining)) {
    $pos_session_lifetime = (int)ini_get('session.gc_maxlifetime') ?: 1440;
    if (!isset($_SESSION['_pos_last_activity'])) {
        $_SESSION['_pos_last_activity'] = time();
    }
    $pos_session_remaining           = max(0, $_SESSION['_pos_last_activity'] + $pos_session_lifetime - time());
    $_SESSION['_pos_last_activity']  = time(); // อัปเดตทุกครั้งที่โหลดหน้า
}

// ── ล็อกหน้าถ้าบัญชีหมดอายุ หรือรหัสผ่านหมดอายุ ──────────────
if (!function_exists('pos_check_expiry')) {
    function pos_check_expiry(): void {
        global $pos_expiry_status, $pos_expiry_date_str, $pos_logged_user,
               $pos_pwd_status, $pos_pwd_days_left;

        // 1) บัญชีหมดอายุ
        if ($pos_expiry_status === 'expired') {
            if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8">'
               . '<meta name="viewport" content="width=device-width,initial-scale=1">'
               . '<title>บัญชีหมดอายุ</title>'
               . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">'
               . '<style>'
               . '*{box-sizing:border-box;margin:0;padding:0}'
               . 'body{background:#080808;color:#eee;font-family:Consolas,monospace;display:flex;align-items:center;justify-content:center;min-height:100vh;}'
               . '.box{background:rgba(255,40,40,0.07);border:2px solid #ff4444;border-radius:16px;padding:52px 60px;text-align:center;max-width:480px;box-shadow:0 0 50px rgba(255,68,68,0.25);}'
               . '.icon{font-size:68px;color:#ff4444;margin-bottom:22px;animation:pulse 2s infinite;}'
               . '@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}'
               . 'h2{font-size:26px;color:#ff4444;margin-bottom:14px;}'
               . 'p{color:#aaa;font-size:14px;margin-bottom:8px;line-height:1.8;}'
               . '.uid{color:#ff9800;font-weight:bold;font-size:16px;}'
               . '.date{color:#ffcc00;font-size:18px;font-weight:bold;}'
               . '.btn{display:inline-block;margin-top:30px;padding:11px 30px;background:rgba(255,255,255,0.05);border:1px solid #555;border-radius:8px;color:#aaa;text-decoration:none;font-size:13px;}'
               . '.btn:hover{border-color:#0ff;color:#0ff;}'
               . '</style></head><body>'
               . '<div class="box">'
               . '<div class="icon"><i class="fas fa-user-clock"></i></div>'
               . '<h2>บัญชีหมดอายุการใช้งาน</h2>'
               . '<p>บัญชี <span class="uid">' . htmlspecialchars($pos_logged_user) . '</span></p>'
               . '<p>หมดอายุเมื่อ <span class="date"><i class="fas fa-calendar-times" style="margin-right:6px;"></i>'
               . htmlspecialchars($pos_expiry_date_str) . '</span></p>'
               . '<p style="margin-top:18px;color:#888;">กรุณาติดต่อผู้ดูแลระบบเพื่อต่ออายุการใช้งาน</p>'
               . '<a href="index.php" class="btn"><i class="fas fa-sign-out-alt" style="margin-right:8px;"></i>ออกจากระบบ</a>'
               . '</div></body></html>';
            session_destroy();
            exit;
        }

        // 2) รหัสผ่านหมดอายุ — บังคับเปลี่ยนทันที
        if (($pos_pwd_status ?? 'ok') === 'expired') {
            if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
            // อนุญาตให้เข้า POS_USER.php ได้เพื่อเปลี่ยนรหัสผ่าน
            $current_script = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
            if (in_array($current_script, ['POS_USER.php', 'logout.php'])) return;
            echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8">'
               . '<meta name="viewport" content="width=device-width,initial-scale=1">'
               . '<title>รหัสผ่านหมดอายุ</title>'
               . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">'
               . '<style>'
               . '*{box-sizing:border-box;margin:0;padding:0}'
               . 'body{background:#080808;color:#eee;font-family:Consolas,monospace;display:flex;align-items:center;justify-content:center;min-height:100vh;}'
               . '.box{background:rgba(123,31,162,0.08);border:2px solid #ab47bc;border-radius:16px;padding:52px 60px;text-align:center;max-width:480px;box-shadow:0 0 50px rgba(123,31,162,0.3);}'
               . '.icon{font-size:68px;color:#ab47bc;margin-bottom:22px;animation:pulse 2s infinite;}'
               . '@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}'
               . 'h2{font-size:26px;color:#ce93d8;margin-bottom:14px;}'
               . 'p{color:#aaa;font-size:14px;margin-bottom:8px;line-height:1.8;}'
               . '.uid{color:#ff9800;font-weight:bold;font-size:16px;}'
               . '.btn-main{display:inline-block;margin-top:22px;padding:12px 36px;background:linear-gradient(135deg,#7b1fa2,#ab47bc);border:none;border-radius:8px;color:#fff;text-decoration:none;font-size:14px;font-weight:bold;}'
               . '.btn-main:hover{opacity:0.85;}'
               . '.btn-out{display:inline-block;margin-top:12px;margin-left:14px;padding:11px 24px;background:rgba(255,255,255,0.05);border:1px solid #555;border-radius:8px;color:#aaa;text-decoration:none;font-size:13px;}'
               . '.btn-out:hover{border-color:#0ff;color:#0ff;}'
               . '</style></head><body>'
               . '<div class="box">'
               . '<div class="icon"><i class="fas fa-key"></i></div>'
               . '<h2>รหัสผ่านหมดอายุ</h2>'
               . '<p>รหัสผ่านของ <span class="uid">' . htmlspecialchars($pos_logged_user) . '</span></p>'
               . '<p>หมดอายุแล้ว กรุณาเปลี่ยนรหัสผ่านใหม่ก่อนดำเนินการต่อ</p>'
               . '<br>'
               . '<a href="POS_USER.php" class="btn-main"><i class="fas fa-key" style="margin-right:8px;"></i>เปลี่ยนรหัสผ่าน</a>'
               . '<a href="logout.php" class="btn-out"><i class="fas fa-sign-out-alt" style="margin-right:6px;"></i>ออกจากระบบ</a>'
               . '</div></body></html>';
            exit;
        }
    }
}

// ── Banner เตือนรหัสผ่านใกล้หมดอายุ ─────────────────────────
if (!function_exists('pos_pwd_expiry_banner')) {
    function pos_pwd_expiry_banner(): void {
        global $pos_pwd_status, $pos_pwd_days_left;
        if ($pos_pwd_status !== 'warning') return;
        $days = (int)$pos_pwd_days_left;
        $msg  = $days === 0
            ? 'รหัสผ่านของคุณจะ<strong>หมดอายุวันนี้</strong>'
            : 'รหัสผ่านของคุณจะหมดอายุใน <strong>' . $days . ' วัน</strong>';
        echo '<div style="'
           . 'position:fixed;top:0;left:0;right:0;z-index:999998;'
           . 'background:linear-gradient(135deg,#7b1fa2,#ab47bc);'
           . 'color:#fff;text-align:center;padding:10px 50px 10px 20px;'
           . 'font-size:14px;font-weight:bold;font-family:Consolas,monospace;'
           . 'box-shadow:0 2px 12px rgba(123,31,162,0.5);'
           . '">'
           . '<i class="fas fa-key" style="margin-right:8px;"></i>'
           . $msg . ' — กรุณาเปลี่ยนรหัสผ่านที่ <a href="POS_USER.php" style="color:#fff;text-decoration:underline;">หน้าตั้งค่าบัญชี</a>'
           . '<button onclick="this.parentElement.style.display=\'none\'" style="'
           . 'position:absolute;right:14px;top:50%;transform:translateY(-50%);'
           . 'background:rgba(0,0,0,0.2);border:none;color:#fff;'
           . 'border-radius:50%;width:26px;height:26px;cursor:pointer;font-size:16px;line-height:1;"'
           . '>×</button>'
           . '</div>'
           . '<div style="height:42px;"></div>';
    }
}

// ── Helper: อัปเดต PWD_START_DATE / PWD_END_DATE หลังเปลี่ยนรหัสผ่านสำเร็จ ──
// เรียกใช้ในทุกกรณีที่มีการเปลี่ยนรหัสผ่าน
// _pos_auth_update_pwd_date($user_id);
if (!function_exists('_pos_auth_update_pwd_date')) {
    function _pos_auth_update_pwd_date(string $user_id): bool {
        global $instant_client_path, $oracle_user, $oracle_pass, $oracle_tns;
        $safe = str_replace("'", "''", strtoupper(trim($user_id)));
        $sql  = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON SERVEROUTPUT ON SIZE 1000000\n"
              . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
              . "BEGIN\n"
              . "  UPDATE POS.SK_WEB_USER\n"
              . "  SET PWD_START_DATE = TRUNC(SYSDATE),\n"
              . "      PWD_END_DATE   = CASE WHEN PWD_VALID_DAYS IS NOT NULL AND PWD_VALID_DAYS > 0\n"
              . "                            THEN TRUNC(SYSDATE) + PWD_VALID_DAYS ELSE NULL END\n"
              . "  WHERE UPPER(TRIM(USER_ID))=UPPER('{$safe}');\n"
              . "  COMMIT; DBMS_OUTPUT.PUT_LINE('OK');\n"
              . "EXCEPTION WHEN OTHERS THEN ROLLBACK; DBMS_OUTPUT.PUT_LINE('ERROR:'||SQLERRM);\n"
              . "END;\n/\nEXIT;\n";
        $out = _pos_auth_sql($sql, $instant_client_path, $oracle_user, $oracle_pass, $oracle_tns);
        return strpos($out, 'OK') !== false;
    }
}
if (!function_exists('pos_expiry_banner')) {
    function pos_expiry_banner(): void {
        global $pos_expiry_status, $pos_expiry_date_str, $pos_expiry_days_left;
        if ($pos_expiry_status !== 'warning') return;
        $days = (int)$pos_expiry_days_left;
        $msg  = $days === 0
            ? 'บัญชีของคุณจะ<strong>หมดอายุวันนี้</strong>'
            : 'บัญชีของคุณจะหมดอายุใน <strong>' . $days . ' วัน</strong>'
              . ' (' . htmlspecialchars($pos_expiry_date_str) . ')';
        echo '<div id="pos-expiry-banner" style="'
           . 'position:fixed;top:0;left:0;right:0;z-index:999999;'
           . 'background:linear-gradient(135deg,#ff6600,#ff9900);'
           . 'color:#fff;text-align:center;padding:10px 50px 10px 20px;'
           . 'font-size:14px;font-weight:bold;font-family:Consolas,monospace;'
           . 'box-shadow:0 2px 12px rgba(255,100,0,0.5);'
           . '">'
           . '<i class="fas fa-exclamation-triangle" style="margin-right:8px;"></i>'
           . $msg . ' — กรุณาติดต่อผู้ดูแลระบบ'
           . '<button onclick="this.parentElement.style.display=\'none\'" style="'
           . 'position:absolute;right:14px;top:50%;transform:translateY(-50%);'
           . 'background:rgba(0,0,0,0.2);border:none;color:#fff;'
           . 'border-radius:50%;width:26px;height:26px;cursor:pointer;font-size:16px;line-height:1;"'
           . '>×</button>'
           . '</div>'
           . '<div style="height:42px;"></div>';
    }
}
