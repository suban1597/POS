<?php
// ============================================================
//  POS_ADMIN.php — จัดการ User ใน POS.SK_WEB_USER
//  เฉพาะ Admin เท่านั้น
// ============================================================
session_start();
if (empty($_SESSION['pos_user'])) {
    header('Location: index.php'); exit;
}
$pos_logged_user = $_SESSION['pos_user'];
$pos_priority    = $_SESSION['pos_priority'] ?? 'U';

$instant_client_path = "/opt/oracle/instantclient_21_4";
$oracle_user = "system";
$oracle_pass = "system";
$oracle_tns  = "CUBACKUP";
require_once __DIR__ . '/POS_AUTH.php';
require_once __DIR__ . '/POS_SETTINGS.php';
pos_check_expiry(); // block ถ้าบัญชีหมดอายุ

// ── ยืนยันรหัสผ่านก่อนเข้าหน้านี้ ──────────────────────────
$_user_verified_key = 'pos_user_verified_' . md5($pos_logged_user);
$_verify_error = '';
if (empty($_SESSION[$_user_verified_key])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_password'])) {
        // ตรวจรหัสผ่าน
        $lib = $instant_client_path;
        $ou  = $oracle_user; $op = $oracle_pass; $otns = $oracle_tns;
        $_safe_vuid = str_replace("'", "''", strtoupper(trim($pos_logged_user)));
        $_hashed_vp = hash('sha256', $_POST['verify_password']);
        $_vchk = run_sql(
            "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON\n"
           ."ALTER SESSION SET NLS_LANGUAGE = American;\n"
           ."SELECT COUNT(*) FROM POS.SK_WEB_USER WHERE UPPER(TRIM(USER_ID))=UPPER('{$_safe_vuid}') AND PASSWORD='{$_hashed_vp}';\nEXIT;\n",
            $lib, $ou, $op, $otns);
        $_vok = false;
        foreach (preg_split('/\r?\n/', $_vchk) as $_vl) {
            $_vl = trim($_vl);
            if (is_numeric($_vl) && (int)$_vl > 0) { $_vok = true; break; }
        }
        if ($_vok) {
            $_SESSION[$_user_verified_key] = time();
            // redirect เพื่อล้าง POST
            header('Location: ' . $_SERVER['PHP_SELF'] . ($_SERVER['QUERY_STRING'] ? '?'.$_SERVER['QUERY_STRING'] : '')); exit;
        } else {
            $_verify_error = 'รหัสผ่านไม่ถูกต้อง';
        }
    }
    // ยังไม่ยืนยัน — แสดงหน้ายืนยัน
    ?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>ยืนยันตัวตน — POS User Management</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{background:#0a0f1a;display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:Consolas,monospace;}
.verify-box{background:linear-gradient(135deg,#0d1b2a,#102030);border:2px solid #0ff;border-radius:16px;padding:40px 44px;width:380px;text-align:center;box-shadow:0 0 40px rgba(0,255,255,0.18);}
.verify-box h2{color:#0ff;font-size:20px;margin-bottom:6px;text-shadow:0 0 12px rgba(0,255,255,0.5);}
.verify-box .sub{color:#aaa;font-size:12px;margin-bottom:28px;}
.verify-box .uid{color:#ffcc00;font-size:14px;font-weight:bold;margin-bottom:24px;}
.inp-wrap{position:relative;margin-bottom:18px;}
.inp-wrap input{width:100%;padding:12px 44px 12px 16px;background:#0a0a0a;border:2px solid #0ff;border-radius:8px;color:#fff;font-size:15px;font-family:Consolas,monospace;outline:none;}
.inp-wrap input:focus{box-shadow:0 0 14px rgba(0,255,255,0.4);}
.inp-wrap .eye{position:absolute;right:14px;top:50%;transform:translateY(-50%);color:#0ff;cursor:pointer;font-size:15px;}
.err{color:#ff4444;font-size:12px;margin:-10px 0 14px;text-align:left;}
.btn-verify{width:100%;padding:13px;background:linear-gradient(135deg,#0ff,#00bcd4);color:#001a1a;font-weight:bold;font-size:15px;border:none;border-radius:8px;cursor:pointer;letter-spacing:1px;transition:opacity 0.2s;}
.btn-verify:hover{opacity:0.88;}
.back-link{display:block;margin-top:16px;color:#888;font-size:12px;text-decoration:none;}
.back-link:hover{color:#0ff;}
</style>
</head>
<body>
<div class="verify-box">
    <h2><i class="fas fa-shield-alt"></i> ยืนยันตัวตน</h2>
    <div class="sub">กรุณาใส่รหัสผ่านเพื่อเข้าหน้า User Management</div>
    <div class="uid"><i class="fas fa-user-circle"></i> <?= htmlspecialchars($pos_logged_user) ?></div>
    <form method="POST">
        <div class="inp-wrap">
            <input type="password" name="verify_password" id="vp" placeholder="รหัสผ่าน" autofocus autocomplete="current-password">
            <span class="eye" onclick="var i=document.getElementById('vp');i.type=i.type==='password'?'text':'password';this.className='eye fas '+(i.type==='password'?'fa-eye':'fa-eye-slash');"><i class="fas fa-eye"></i></span>
        </div>
        <?php if ($_verify_error !== ''): ?>
        <div class="err"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($_verify_error) ?></div>
        <?php endif; ?>
        <button type="submit" class="btn-verify"><i class="fas fa-unlock-alt"></i> ยืนยัน</button>
    </form>
    <a class="back-link" href="index.php"><i class="fas fa-arrow-left"></i> กลับหน้าหลัก</a>
</div>
</body>
</html><?php
    exit;
}
// รีเซ็ต verified session หลัง 30 นาที
if (!empty($_SESSION[$_user_verified_key]) && time() - $_SESSION[$_user_verified_key] > 1800) {
    unset($_SESSION[$_user_verified_key]);
    header('Location: ' . $_SERVER['PHP_SELF']); exit;
}
// ── /ยืนยันรหัสผ่าน ─────────────────────────────────────────

// Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php'); exit;
}

// ---------------------------
// CONFIG
// ---------------------------
$lib  = "/opt/oracle/instantclient_21_4";
$ou   = "system";
$op   = "system";
$otns = "CUBACKUP";

function run_sql(string $sql, string $lib, string $user, string $pass, string $tns): string {
    $sqlplus = rtrim($lib, '/') . '/sqlplus';
    if (!is_executable($sqlplus)) return 'ERROR:sqlplus not found';
    $tmp = sys_get_temp_dir() . '/POS_ADM_' . uniqid() . '.sql';
    file_put_contents($tmp, $sql);
    $up  = escapeshellarg("{$user}/{$pass}@{$tns}");
    $cmd = "env -i LD_LIBRARY_PATH={$lib} TNS_ADMIN={$lib} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus} -s {$up} @{$tmp} 2>&1";
    $out = (string) shell_exec($cmd);
    @unlink($tmp);
    return trim($out);
}

$msg_ok  = '';
$msg_err = '';

// ============================================================
//  HANDLE ACTIONS (POST)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action']  ?? '';
    $user_id = strtoupper(trim($_POST['user_id'] ?? ''));
    $safe_id = str_replace("'", "", $user_id);

    // ---- เปิด/ปิด ACTIVE ----
    if ($action === 'toggle_active' && $safe_id !== '') {
        $new_active = ($_POST['current_active'] ?? 'Y') === 'Y' ? 'N' : 'Y';
        $set_dates  = ($new_active === 'Y') ? "Y" : "N"; // ส่งค่า flag ลงใน SQL string แบบ literal
        $sql = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON SERVEROUTPUT ON SIZE 1000000\n"
             . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
             . "DECLARE\n"
             . "    v_days NUMBER;\n"
             . "BEGIN\n"
             . "    UPDATE POS.SK_WEB_USER SET ACTIVE='{$new_active}'\n"
             . "    WHERE UPPER(TRIM(USER_ID))=UPPER('{$safe_id}');\n"
             . ( $new_active === 'Y'
                ? "    SELECT NVL(VALID_DAYS, 90) INTO v_days\n"
                . "    FROM POS.SK_WEB_USER WHERE UPPER(TRIM(USER_ID))=UPPER('{$safe_id}');\n"
                . "    UPDATE POS.SK_WEB_USER\n"
                . "    SET START_DATE = TRUNC(SYSDATE),\n"
                . "        END_DATE   = TRUNC(SYSDATE) + v_days,\n"
                . "        VALID_DAYS = v_days\n"
                . "    WHERE UPPER(TRIM(USER_ID))=UPPER('{$safe_id}');\n"
                : "" )
             . "    COMMIT;\n"
             . "    DBMS_OUTPUT.PUT_LINE('OK');\n"
             . "EXCEPTION WHEN OTHERS THEN ROLLBACK; DBMS_OUTPUT.PUT_LINE('ERROR:'||SQLERRM);\n"
             . "END;\n/\nEXIT;\n";
        $result = run_sql($sql, $lib, $ou, $op, $otns);
        if (strpos($result, 'OK') !== false)
            $msg_ok = "อัปเดตสถานะ <strong>{$user_id}</strong> เป็น " . ($new_active === 'Y' ? '✅ Active' : '❌ Inactive') . " เรียบร้อย";
        else
            $msg_err = "เกิดข้อผิดพลาด: " . htmlspecialchars($result);
    }

    // ---- เปลี่ยน Priority ----
    if ($action === 'change_priority' && $safe_id !== '') {
        $new_prio = in_array($_POST['new_priority'] ?? 'U', ['A','M','U']) ? $_POST['new_priority'] : 'U';
        $sql = <<<SQL
SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON SERVEROUTPUT ON SIZE 1000000
ALTER SESSION SET NLS_LANGUAGE = American;
BEGIN
    UPDATE POS.SK_WEB_USER SET PRIORITY='$new_prio'
    WHERE UPPER(TRIM(USER_ID))=UPPER('$safe_id');
    COMMIT;
    DBMS_OUTPUT.PUT_LINE('OK');
EXCEPTION WHEN OTHERS THEN ROLLBACK; DBMS_OUTPUT.PUT_LINE('ERROR:'||SQLERRM);
END;
/
EXIT;
SQL;
        $result = run_sql($sql, $lib, $ou, $op, $otns);
        $labels = ['A'=>'[A] Admin','M'=>'[M] Member','U'=>'[U] User'];
        if (strpos($result, 'OK') !== false)
            $msg_ok = "อัปเดตสิทธิ์ <strong>{$user_id}</strong> เป็น " . ($labels[$new_prio] ?? $new_prio) . " เรียบร้อย";
        else
            $msg_err = "เกิดข้อผิดพลาด: " . htmlspecialchars($result);
    }

    // ---- Admin เปลี่ยนรหัสผ่านให้ User ----
    if ($action === 'admin_change_password' && $safe_id !== '') {
        $new_pass = trim($_POST['new_pass'] ?? '');
        $cfm_pass = trim($_POST['cfm_pass'] ?? '');
        if (strlen($new_pass) < 4) {
            $msg_err = "รหัสผ่านต้องมีอย่างน้อย 4 ตัวอักษร";
        } elseif ($new_pass !== $cfm_pass) {
            $msg_err = "รหัสผ่านและยืนยันไม่ตรงกัน";
        } else {
            $hashed_new = hash('sha256', $new_pass);
            $sql = <<<SQL
SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON SERVEROUTPUT ON SIZE 1000000
ALTER SESSION SET NLS_LANGUAGE = American;
DECLARE
    v_days NUMBER;
BEGIN
    SELECT NVL(PWD_VALID_DAYS, 90) INTO v_days
    FROM POS.SK_WEB_USER
    WHERE UPPER(TRIM(USER_ID))=UPPER('$safe_id');

    UPDATE POS.SK_WEB_USER
    SET PASSWORD       = '$hashed_new',
        PWD_START_DATE = TRUNC(SYSDATE),
        PWD_END_DATE   = TRUNC(SYSDATE) + v_days
    WHERE UPPER(TRIM(USER_ID))=UPPER('$safe_id');
    COMMIT;
    DBMS_OUTPUT.PUT_LINE('OK');
EXCEPTION WHEN OTHERS THEN ROLLBACK; DBMS_OUTPUT.PUT_LINE('ERROR:'||SQLERRM);
END;
/
EXIT;
SQL;
            $result = run_sql($sql, $lib, $ou, $op, $otns);
            if (strpos($result, 'OK') !== false)
                $msg_ok = "เปลี่ยนรหัสผ่าน <strong>{$user_id}</strong> เรียบร้อย";
            else
                $msg_err = "เกิดข้อผิดพลาด: " . htmlspecialchars($result);
        }
    }

    // ---- ลบ User ----
    if ($action === 'delete' && $safe_id !== '') {
        // ป้องกันลบตัวเอง
        if (strtoupper($safe_id) === strtoupper($pos_logged_user)) {
            $msg_err = "ไม่สามารถลบบัญชีของตัวเองได้";
        } else {
            $sql = <<<SQL
SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON SERVEROUTPUT ON SIZE 1000000
ALTER SESSION SET NLS_LANGUAGE = American;
BEGIN
    -- ลบ child records ก่อน (FK_UBA_USER_ID)
    DELETE FROM POS.USER_BRANCH_ACCESS WHERE UPPER(TRIM(USER_ID))=UPPER('$safe_id');
    -- ลบ parent record
    DELETE FROM POS.SK_WEB_USER WHERE UPPER(TRIM(USER_ID))=UPPER('$safe_id');
    COMMIT;
    DBMS_OUTPUT.PUT_LINE('OK');
EXCEPTION WHEN OTHERS THEN ROLLBACK; DBMS_OUTPUT.PUT_LINE('ERROR:'||SQLERRM);
END;
/
EXIT;
SQL;
            $result = run_sql($sql, $lib, $ou, $op, $otns);
            if (strpos($result, 'OK') !== false)
                $msg_ok = "ลบ User <strong>{$user_id}</strong> เรียบร้อย";
            else
                $msg_err = "เกิดข้อผิดพลาด: " . htmlspecialchars($result);
        }
    }

    // ---- บันทึกอายุรหัสผ่าน ----
    if ($action === 'save_pwd_expiry' && $safe_id !== '' && $pos_priority === 'A') {
        $pwd_vd_raw = trim($_POST['pwd_valid_days'] ?? '');
        $pwd_vd_int = ($pwd_vd_raw !== '') ? max(1, min(9999, (int)$pwd_vd_raw)) : 0;
        $pwd_vd_val = $pwd_vd_int > 0 ? (string)$pwd_vd_int : 'NULL';
        $sql_pve = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON SERVEROUTPUT ON SIZE 1000000\n"
                 . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
                 . "DECLARE v_days NUMBER; v_start DATE;\n"
                 . "BEGIN\n"
                 . "  v_days := {$pwd_vd_val};\n"
                 . "  SELECT PWD_START_DATE INTO v_start FROM POS.SK_WEB_USER WHERE UPPER(TRIM(USER_ID))=UPPER('{$safe_id}');\n"
                 . "  UPDATE POS.SK_WEB_USER\n"
                 . "  SET PWD_VALID_DAYS = {$pwd_vd_val},\n"
                 . "      PWD_END_DATE   = CASE WHEN v_start IS NOT NULL AND v_days IS NOT NULL\n"
                 . "                            THEN v_start + v_days\n"
                 . "                            ELSE NULL END\n"
                 . "  WHERE UPPER(TRIM(USER_ID))=UPPER('{$safe_id}');\n"
                 . "  COMMIT; DBMS_OUTPUT.PUT_LINE('OK');\n"
                 . "EXCEPTION WHEN OTHERS THEN ROLLBACK; DBMS_OUTPUT.PUT_LINE('ERROR:'||SQLERRM);\n"
                 . "END;\n/\nEXIT;\n";
        $result = run_sql($sql_pve, $lib, $ou, $op, $otns);
        if (strpos($result, 'OK') !== false) {
            $msg_ok = "บันทึกอายุรหัสผ่าน <strong>{$user_id}</strong> เรียบร้อย"
                    . ($pwd_vd_int > 0 ? " ({$pwd_vd_int} วัน)" : ' (ไม่จำกัด)');
        } else {
            $msg_err = "เกิดข้อผิดพลาด: " . htmlspecialchars($result);
        }
    }

    // ---- บันทึกสิทธิ์สาขา ----
    if ($action === 'save_branch_access' && $pos_priority === 'A') {
        $target_uid   = str_replace("'", "", strtoupper(trim($_POST['target_user_id'] ?? '')));
        $branch_ids   = $_POST['branch_ids'] ?? [];

        if ($target_uid !== '') {
            // ลบสิทธิ์เดิมทั้งหมดของ User นี้ก่อน
            $sql_del = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON SERVEROUTPUT ON SIZE 1000000\n"
                     . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
                     . "BEGIN\n"
                     . "    DELETE FROM POS.USER_BRANCH_ACCESS WHERE UPPER(TRIM(USER_ID))=UPPER('{$target_uid}');\n"
                     . "    COMMIT;\n"
                     . "    DBMS_OUTPUT.PUT_LINE('OK');\n"
                     . "EXCEPTION WHEN OTHERS THEN ROLLBACK; DBMS_OUTPUT.PUT_LINE('ERROR:'||SQLERRM);\n"
                     . "END;\n/\nEXIT;\n";
            run_sql($sql_del, $lib, $ou, $op, $otns);

            // Insert สิทธิ์ใหม่ที่เลือก
            $ins_count = 0;
            $ins_err   = 0;
            foreach ($branch_ids as $bid) {
                $safe_bid = str_replace("'", "", trim($bid));
                if ($safe_bid === '') continue;
                $safe_admin = str_replace("'", "", $pos_logged_user);
                $sql_ins = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON SERVEROUTPUT ON SIZE 1000000\n"
                         . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
                         . "BEGIN\n"
                         . "    INSERT INTO POS.USER_BRANCH_ACCESS (USER_ID, BRANCH_ID, GRANTED_BY)\n"
                         . "    VALUES ('{$target_uid}', '{$safe_bid}', '{$safe_admin}');\n"
                         . "    COMMIT;\n"
                         . "    DBMS_OUTPUT.PUT_LINE('OK');\n"
                         . "EXCEPTION WHEN OTHERS THEN ROLLBACK; DBMS_OUTPUT.PUT_LINE('ERROR:'||SQLERRM);\n"
                         . "END;\n/\nEXIT;\n";
                $r = run_sql($sql_ins, $lib, $ou, $op, $otns);
                if (strpos($r, 'OK') !== false) $ins_count++;
                else $ins_err++;
            }

            if ($ins_err === 0)
                $msg_ok = "บันทึกสิทธิ์สาขาสำหรับ <strong>{$target_uid}</strong> จำนวน {$ins_count} สาขา เรียบร้อย";
            else
                $msg_err = "บันทึกสำเร็จ {$ins_count} สาขา / ผิดพลาด {$ins_err} สาขา";
        }
    }

    // ---- บันทึกอายุใช้งาน ----
    if ($action === 'save_validity' && $safe_id !== '' && $pos_priority === 'A') {
        $start_raw = trim($_POST['start_date'] ?? '');
        $end_raw   = trim($_POST['end_date']   ?? '');
        $days_raw  = trim($_POST['valid_days'] ?? '');
        $days_int  = ($days_raw !== '') ? (int)$days_raw : 0;

        $valid_start = ($start_raw !== '' && preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $start_raw));
        $valid_end   = ($end_raw   !== '' && preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $end_raw));

        // ── คำนวณ END_DATE อัตโนมัติเมื่อมี VALID_DAYS ──
        // กรณี 1: มี valid_days + start_date → END_DATE = START_DATE + VALID_DAYS
        if ($days_int > 0 && $valid_start) {
            $start_obj = DateTime::createFromFormat('d/m/Y', $start_raw);
            $start_obj->modify("+{$days_int} days");
            $end_raw   = $start_obj->format('d/m/Y');
            $valid_end = true;
        }
        // กรณี 2: มี valid_days แต่ไม่มี start_date → ใช้วันนี้เป็น start_date แล้วคำนวณ end_date
        elseif ($days_int > 0 && !$valid_start) {
            $start_raw   = date('d/m/Y');
            $valid_start = true;
            $end_obj     = new DateTime();
            $end_obj->modify("+{$days_int} days");
            $end_raw     = $end_obj->format('d/m/Y');
            $valid_end   = true;
        }
        // กรณี 3: มี start_date + end_date แต่ไม่มี valid_days → คำนวณ valid_days จากช่วงวันที่
        elseif ($days_int === 0 && $valid_start && $valid_end) {
            $s_obj    = DateTime::createFromFormat('d/m/Y', $start_raw);
            $e_obj    = DateTime::createFromFormat('d/m/Y', $end_raw);
            $days_int = (int)$s_obj->diff($e_obj)->days;
        }

        $start_sql = $valid_start
            ? "TO_DATE('" . str_replace("'","''",$start_raw) . "','DD/MM/YYYY')" : 'NULL';
        $end_sql   = $valid_end
            ? "TO_DATE('" . str_replace("'","''",$end_raw)   . "','DD/MM/YYYY')" : 'NULL';
        $days_val  = $days_int > 0 ? (string)$days_int : 'NULL';

        $sql_sv = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON SERVEROUTPUT ON SIZE 1000000\n"
                . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
                . "BEGIN\n"
                . "  UPDATE POS.SK_WEB_USER\n"
                . "  SET START_DATE={$start_sql},\n"
                . "      END_DATE={$end_sql},\n"
                . "      VALID_DAYS={$days_val}\n"
                . "  WHERE UPPER(TRIM(USER_ID))=UPPER('{$safe_id}');\n"
                . "  COMMIT; DBMS_OUTPUT.PUT_LINE('OK');\n"
                . "EXCEPTION WHEN OTHERS THEN ROLLBACK; DBMS_OUTPUT.PUT_LINE('ERROR:'||SQLERRM);\n"
                . "END;\n/\nEXIT;\n";
        $result = run_sql($sql_sv, $lib, $ou, $op, $otns);
        if (strpos($result, 'OK') !== false) {
            $end_display = $valid_end ? $end_raw : '(ไม่จำกัด)';
            $msg_ok = "บันทึกอายุใช้งาน <strong>{$user_id}</strong> เรียบร้อย"
                    . ($days_int > 0 ? " — {$days_int} วัน (หมดอายุ: {$end_display})" : '');
        } else {
            $msg_err = "เกิดข้อผิดพลาด: " . htmlspecialchars($result);
        }
    }
}

// ============================================================
// ============================================================
//  LOAD + SAVE MENU PERMISSION (Admin only)
// ============================================================
$perm_roles   = [];  // ['A'=>'Admin','U'=>'User','M'=>'Member']
$perm_menus   = [];  // [['code'=>'home','name'=>'Dashboard','parent'=>null,'seq'=>1], ...]
$perm_matrix  = [];  // ['home']['A'] = ['view'=>'Y','use'=>'Y']
$perm_msg_ok  = '';
$perm_msg_err = '';

if ($pos_priority === 'A') {

    // ── Save permission ──────────────────────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'save_perm') {
        $updated = 0;
        $errors  = 0;
        // POST: perm[ROLE][MENU_CODE] = 'view'|'use'|'both'|'none'
        $posted = $_POST['perm'] ?? [];
        foreach ($posted as $role => $menus) {
            $safe_role = str_replace("'","''",trim($role));
            foreach ($menus as $menu_code => $val) {
                $safe_code = str_replace("'","''",trim($menu_code));
                $can_view = (in_array($val,['view','both'])) ? 'Y' : 'N';
                $can_use  = (in_array($val,['use','both']))  ? 'Y' : 'N';
                $sql_u = "SET ECHO OFF FEEDBACK OFF HEADING OFF\n"
                       . "ALTER SESSION SET NLS_LANGUAGE=American;\n"
                       . "MERGE INTO POS.POS_MENU_PERMISSION T\n"
                       . "USING DUAL ON (T.MENU_CODE='{$safe_code}' AND T.ROLE_CODE='{$safe_role}')\n"
                       . "WHEN MATCHED THEN UPDATE SET CAN_VIEW='{$can_view}',CAN_USE='{$can_use}',UPDATE_DATE=SYSDATE,UPDATE_BY='{$pos_logged_user}'\n"
                       . "WHEN NOT MATCHED THEN INSERT (MENU_CODE,ROLE_CODE,CAN_VIEW,CAN_USE,UPDATE_DATE,UPDATE_BY) VALUES('{$safe_code}','{$safe_role}','{$can_view}','{$can_use}',SYSDATE,'{$pos_logged_user}');\n"
                       . "COMMIT;\nEXIT;\n";
                $r = run_sql($sql_u, $lib, $ou, $op, $otns);
                if (strpos($r,'ERROR') !== false || preg_match('/ORA-|SP2-/',$r)) $errors++;
                else $updated++;
            }
        }
        if ($errors === 0) $perm_msg_ok  = "บันทึกสิทธิ์ {$updated} รายการเรียบร้อย";
        else               $perm_msg_err = "บันทึกสำเร็จ {$updated} รายการ / ผิดพลาด {$errors} รายการ";
    }

    // ── Load roles ───────────────────────────────────────────
    $sql_roles = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 200 TRIMSPOOL ON\n"
               . "ALTER SESSION SET NLS_LANGUAGE=American;\n"
               . "SELECT ROLE_CODE||'|'||ROLE_NAME FROM POS.POS_ROLE WHERE IS_ACTIVE='Y' ORDER BY ROLE_CODE;\nEXIT;\n";
    $out_roles = run_sql($sql_roles, $lib, $ou, $op, $otns);
    foreach (preg_split('/\r?\n/', $out_roles) as $line) {
        $line = trim($line);
        if ($line==='' || preg_match('/^(ORA-|SP2-)/',$line)) continue;
        $p = explode('|',$line,2);
        if (count($p)===2) $perm_roles[trim($p[0])] = trim($p[1]);
    }

    // ── Load menus ───────────────────────────────────────────
    $sql_menus = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 500 TRIMSPOOL ON\n"
               . "ALTER SESSION SET NLS_LANGUAGE=American;\n"
               . "SELECT MENU_CODE||'|'||MENU_NAME||'|'||NVL(PARENT_CODE,'-')||'|'||TO_CHAR(SEQ)||'|'||NVL(ICON_CLASS,'fas fa-circle')\n"
               . "FROM POS.POS_MENU_DEF WHERE IS_ACTIVE='Y' ORDER BY PARENT_CODE NULLS FIRST, SEQ;\nEXIT;\n";
    $out_menus = run_sql($sql_menus, $lib, $ou, $op, $otns);
    foreach (preg_split('/\r?\n/', $out_menus) as $line) {
        $line = trim($line);
        if ($line==='' || preg_match('/^(ORA-|SP2-)/',$line)) continue;
        $p = explode('|',$line,5);
        if (count($p)>=4) {
            $perm_menus[] = [
                'code'   => trim($p[0]),
                'name'   => trim($p[1]),
                'parent' => trim($p[2])==='-' ? null : trim($p[2]),
                'seq'    => (int)trim($p[3]),
                'icon'   => isset($p[4]) ? trim($p[4]) : 'fas fa-circle',
            ];
        }
    }

    // ── Load current permissions ─────────────────────────────
    $sql_perm = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 500 TRIMSPOOL ON\n"
              . "ALTER SESSION SET NLS_LANGUAGE=American;\n"
              . "SELECT MENU_CODE||'|'||ROLE_CODE||'|'||CAN_VIEW||'|'||CAN_USE\n"
              . "FROM POS.POS_MENU_PERMISSION ORDER BY MENU_CODE,ROLE_CODE;\nEXIT;\n";
    $out_perm = run_sql($sql_perm, $lib, $ou, $op, $otns);
    foreach (preg_split('/\r?\n/', $out_perm) as $line) {
        $line = trim($line);
        if ($line==='' || preg_match('/^(ORA-|SP2-)/',$line)) continue;
        $p = explode('|',$line,4);
        if (count($p)===4) {
            $perm_matrix[trim($p[0])][trim($p[1])] = [
                'view' => trim($p[2]),
                'use'  => trim($p[3]),
            ];
        }
    }
}

// ============================================================
$sk_users = [];
if ($pos_priority === 'A') {
    // Handle toggle ACTIVE ใน POS.SK_USER
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_sk_active') {
        $sk_id      = str_replace("'", "", strtoupper(trim($_POST['sk_user_id'] ?? '')));
        $cur_active = ($_POST['sk_current_active'] ?? 'Y') === 'Y' ? 'N' : 'Y';
        if ($sk_id !== '') {
            $sql_sk = <<<SQL
SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON SERVEROUTPUT ON SIZE 1000000
ALTER SESSION SET NLS_LANGUAGE = American;
BEGIN
    UPDATE POS.SK_USER SET ACTIVE='$cur_active'
    WHERE UPPER(TRIM(SK_USER_ID))=UPPER('$sk_id');
    COMMIT;
    DBMS_OUTPUT.PUT_LINE('OK');
EXCEPTION WHEN OTHERS THEN ROLLBACK; DBMS_OUTPUT.PUT_LINE('ERROR:'||SQLERRM);
END;
/
EXIT;
SQL;
            $res_sk = run_sql($sql_sk, $lib, $ou, $op, $otns);
            if (strpos($res_sk, 'OK') !== false)
                $msg_ok = "อัปเดตสถานะพนักงาน <strong>{$sk_id}</strong> เป็น " . ($cur_active === 'Y' ? '✅ Active' : '❌ Inactive') . " เรียบร้อย";
            else
                $msg_err = "เกิดข้อผิดพลาด: " . htmlspecialchars($res_sk);
        }
    }

    $sql_sk_list = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 500 TRIMSPOOL ON\n"
                 . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
                 . "SELECT TRIM(SK_USER_ID)||'|'||NVL(TRIM(TNAME),NVL(TRIM(ENAME),'-'))||'|'||NVL(TRIM(ACTIVE),'N')\n"
                 . "FROM POS.SK_USER\n"
                 . "WHERE SK_USER_ID IS NOT NULL AND TRIM(SK_USER_ID) IS NOT NULL\n"
                 . "ORDER BY ACTIVE DESC, SK_USER_ID;\n"
                 . "EXIT;\n";
    $res_sk_list = run_sql($sql_sk_list, $lib, $ou, $op, $otns);
    foreach (explode("\n", $res_sk_list) as $ln) {
        $ln = trim($ln);
        if ($ln === '' || strpos($ln, '|') === false || preg_match('/^(ORA-|SP2-)/', $ln)) continue;
        $p = explode('|', $ln, 3);
        if (count($p) < 3) continue;
        $sk_users[] = ['sk_user_id' => trim($p[0]), 'name' => trim($p[1]), 'active' => trim($p[2])];
    }
}

// ============================================================
//  LOAD USER LIST (SK_WEB_USER)
// ============================================================
// เพิ่มคอลัมน์อายุใช้งาน (idempotent — ข้ามถ้ามีแล้ว)
// เพิ่มคอลัมน์อายุใช้งาน + อายุรหัสผ่าน (idempotent)
$_vcol_sql = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON SERVEROUTPUT ON\n"
    . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
    . "DECLARE v_n NUMBER; BEGIN\n"
    . "  SELECT COUNT(*) INTO v_n FROM all_tab_columns WHERE owner='POS' AND table_name='SK_WEB_USER' AND column_name='START_DATE';\n"
    . "  IF v_n=0 THEN EXECUTE IMMEDIATE 'ALTER TABLE POS.SK_WEB_USER ADD (START_DATE DATE,END_DATE DATE,VALID_DAYS NUMBER(6))'; END IF;\n"
    . "  SELECT COUNT(*) INTO v_n FROM all_tab_columns WHERE owner='POS' AND table_name='SK_WEB_USER' AND column_name='PWD_VALID_DAYS';\n"
    . "  IF v_n=0 THEN EXECUTE IMMEDIATE 'ALTER TABLE POS.SK_WEB_USER ADD (PWD_VALID_DAYS NUMBER(6))'; END IF;\n"
    . "  SELECT COUNT(*) INTO v_n FROM all_tab_columns WHERE owner='POS' AND table_name='SK_WEB_USER' AND column_name='PWD_START_DATE';\n"
    . "  IF v_n=0 THEN EXECUTE IMMEDIATE 'ALTER TABLE POS.SK_WEB_USER ADD (PWD_START_DATE DATE, PWD_END_DATE DATE)'; END IF;\n"
    . "END;\n/\nEXIT;\n";
@run_sql($_vcol_sql, $lib, $ou, $op, $otns);

// ตรวจว่าคอลัมน์มีแล้วหรือยัง
$_chk = run_sql("SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON\nALTER SESSION SET NLS_LANGUAGE = American;\nSELECT COUNT(*) FROM all_tab_columns WHERE owner='POS' AND table_name='SK_WEB_USER' AND column_name='START_DATE';\nEXIT;\n", $lib, $ou, $op, $otns);
$_has_vcols = false;
foreach (explode("\n", $_chk) as $_vl) { if (trim($_vl) === '1') { $_has_vcols = true; break; } }
$_chk_pwd = run_sql("SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON\nALTER SESSION SET NLS_LANGUAGE = American;\nSELECT COUNT(*) FROM all_tab_columns WHERE owner='POS' AND table_name='SK_WEB_USER' AND column_name='PWD_START_DATE';\nEXIT;\n", $lib, $ou, $op, $otns);
$_has_pwd_cols = false;
foreach (explode("\n", $_chk_pwd) as $_vl) { if (trim($_vl) === '1') { $_has_pwd_cols = true; break; } }
$_vcols = $_has_vcols
    ? "||'|'||NVL(TO_CHAR(START_DATE,'DD/MM/YYYY'),'')||'|'||NVL(TO_CHAR(END_DATE,'DD/MM/YYYY'),'')||'|'||NVL(TO_CHAR(VALID_DAYS),'')"
    : "||'|'||''||'|'||''||'|'||''";
$_vcols .= $_has_pwd_cols
    ? "||'|'||NVL(TO_CHAR(PWD_START_DATE,'DD/MM/YYYY'),'')||'|'||NVL(TO_CHAR(PWD_END_DATE,'DD/MM/YYYY'),'')||'|'||NVL(TO_CHAR(PWD_VALID_DAYS),'')"
    : "||'|'||''||'|'||''||'|'||''";

$users = [];
$where_clause = ($pos_priority === 'A')
    ? ""
    : "WHERE UPPER(TRIM(USER_ID)) = UPPER('" . str_replace("'","''", $pos_logged_user) . "')";
$sql = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 1000 TRIMSPOOL ON\n"
     . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
     . "SELECT TRIM(USER_ID)||'|'||TRIM(PASSWORD)||'|'||NVL(TRIM(FULL_NAME),'-')||'|'||ACTIVE||'|'||NVL(PRIORITY,'U'){$_vcols}\n"
     . "FROM POS.SK_WEB_USER\n"
     . ($where_clause !== '' ? $where_clause . "\n" : "")
     . "ORDER BY PRIORITY, USER_ID;\n"
     . "EXIT;\n";
$result = run_sql($sql, $lib, $ou, $op, $otns);
foreach (explode("\n", $result) as $line) {
    $line = trim($line);
    if ($line === '' || strpos($line, '|') === false) continue;
    if (preg_match('/^(ORA-|SP2-)/', $line)) continue;
    $p = explode('|', $line, 11);
    if (count($p) < 5) continue;
    $users[] = [
        'user_id'         => trim($p[0]),
        'password'        => trim($p[1]),
        'full_name'       => trim($p[2]),
        'active'          => trim($p[3]),
        'priority'        => trim($p[4]),
        'start_date'      => trim($p[5] ?? ''),
        'end_date'        => trim($p[6] ?? ''),
        'valid_days'      => trim($p[7] ?? ''),
        'pwd_start_date'  => trim($p[8] ?? ''),
        'pwd_end_date'    => trim($p[9] ?? ''),
        'pwd_valid_days'  => trim($p[10] ?? ''),
    ];
}

// ============================================================
//  LOAD BRANCH LIST + CURRENT BRANCH ACCESS (Admin only)
// ============================================================
$branch_list   = [];
$branch_access = [];

if ($pos_priority === 'A') {
    $sql_br = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 300 TRIMSPOOL ON\n"
            . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
            . "SELECT TRIM(SALE_OFFICE)||'|'||NVL(TRIM(OFFICE_NAME),'-')\n"
            . "FROM POS.POS_SALE_OFFICE ORDER BY SALE_OFFICE;\nEXIT;\n";
    $out_br = run_sql($sql_br, $lib, $ou, $op, $otns);
    foreach (preg_split('/\r?\n/', $out_br) as $ln) {
        $ln = trim($ln);
        if ($ln === '' || preg_match('/^(ORA-|SP2-)/', $ln)) continue;
        $p = explode('|', $ln, 2);
        if (count($p) === 2)
            $branch_list[] = ['sale_office' => trim($p[0]), 'office_name' => trim($p[1])];
    }

    $sql_acc = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 300 TRIMSPOOL ON\n"
             . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
             . "SELECT TRIM(USER_ID)||'|'||TRIM(BRANCH_ID) FROM POS.USER_BRANCH_ACCESS ORDER BY USER_ID, BRANCH_ID;\nEXIT;\n";
    $out_acc = run_sql($sql_acc, $lib, $ou, $op, $otns);
    foreach (preg_split('/\r?\n/', $out_acc) as $ln) {
        $ln = trim($ln);
        if ($ln === '' || preg_match('/^(ORA-|SP2-)/', $ln)) continue;
        $p = explode('|', $ln, 2);
        if (count($p) === 2)
            $branch_access[trim($p[0])][trim($p[1])] = true;
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>POS — <?= $pos_priority === 'A' ? 'จัดการผู้ใช้งาน' : 'ข้อมูลบัญชีของฉัน' ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body {
    font-family: "Consolas","Tahoma",sans-serif;
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    color: #eee;
    padding: 20px;
    min-height: 100vh;
}
h1 {
    color: #00ffff;
    margin-bottom: 20px;
    text-align: center;
    text-shadow: 0 0 20px rgba(0,255,255,0.5);
    font-size: 28px;
}
h1 i {
    color: #0f0;
    font-size: 32px;
    margin-right: 10px;
    text-shadow: 0 0 15px rgba(0,255,0,0.6);
}
.container { max-width: 1400px; margin: 0 auto; }

/* Top Right */
.top-right {
    position: fixed;
    top: 20px;
    right: 20px;
    background: rgba(0,0,0,0.9);
    padding: 15px 20px;
    border: 2px solid #0ff;
    border-radius: 10px;
    color: #0ff;
    font-weight: bold;
    z-index: 1000;
    box-shadow: 0 0 30px rgba(0,255,255,0.4);
    font-size: 13px;
    transition: padding 0.3s, border-radius 0.3s, box-shadow 0.3s;
    cursor: pointer;
    overflow: hidden;
}
.top-right i { margin-right: 5px; }
/* collapsed state */
.top-right.tr-collapsed {
    padding: 10px 14px;
    border-radius: 50px;
    box-shadow: 0 0 16px rgba(0,255,255,0.3);
    cursor: pointer;
}
.top-right.tr-collapsed .tr-content { display: none; }
.top-right.tr-collapsed .tr-icon-mini { display: flex !important; }

/* Nav menu grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    margin: 30px 0;
}
.stat-card {
    background: linear-gradient(135deg, rgba(0,188,212,0.15) 0%, rgba(0,150,167,0.15) 100%);
    border: 2px solid #0ff;
    border-radius: 12px;
    padding: 25px;
    text-align: center;
    transition: all 0.3s;
    position: relative;
    overflow: hidden;
}
.stat-card::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: linear-gradient(45deg, transparent, rgba(0,255,255,0.1), transparent);
    transform: rotate(45deg);
    transition: all 0.5s;
}
.stat-card:hover {
    transform: translateY(-8px) scale(1.02);
    box-shadow: 0 15px 40px rgba(0,255,255,0.4);
    border-color: #00ffff;
}
.stat-card:hover::before { left: 100%; }


/* Global button style */
button {
    background: linear-gradient(135deg, #00bcd4 0%, #0097a7 100%);
    color: #fff;
    border: none;
    padding: 12px 24px;
    border-radius: 6px;
    cursor: pointer;
    margin: 5px;
    font-weight: bold;
    font-size: 14px;
    transition: all 0.3s;
    box-shadow: 0 4px 15px rgba(0,188,212,0.3);
}
button:hover {
    background: linear-gradient(135deg, #00e5ff 0%, #00bcd4 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,188,212,0.5);
}

/* Msg */
.msg { padding: 15px; border-radius: 8px; margin: 15px 0; font-size: 14px; }
.msg-ok  { background: rgba(0,255,0,0.08);  border: 2px solid #00cc44; color: #00ff66; }
.msg-err { background: rgba(255,0,0,0.10);  border: 2px solid #ff4444; color: #ff6b6b; }

/* Back button */
.btn-back {
    display: inline-block; margin-bottom: 20px;
    padding: 10px 22px;
    background: linear-gradient(135deg,#00bcd4,#0097a7);
    color: #fff; border: none; border-radius: 8px;
    font-size: 14px; font-weight: bold; cursor: pointer;
    text-decoration: none;
    box-shadow: 0 4px 14px rgba(0,188,212,0.35);
    transition: all 0.3s;
}
.btn-back:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,188,212,0.5); }

/* Table */
.table-wrap {
    background: rgba(0,0,0,0.4);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 0 30px rgba(0,255,255,0.2);
}
table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 0;
    background: rgba(0,0,0,0.4);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 0 30px rgba(0,255,255,0.2);
}
th, td {
    border: 1px solid #333;
    padding: 15px 20px;
    text-align: left;
}
th {
    background: linear-gradient(135deg, #004d4d 0%, #003333 100%);
    color: #0ff;
    font-weight: bold;
    text-transform: uppercase;
    font-size: 13px;
    letter-spacing: 0.5px;
}
tbody tr { transition: all 0.3s; }
tbody tr:nth-child(odd) { background: rgba(255,255,255,0.03); }
tbody tr:hover {
    background: rgba(0,255,255,0.15);
    transform: scale(1.01);
    cursor: pointer;
}

/* Badges */
.badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: bold;
    letter-spacing: 0.5px;
}
.badge-active   { background: rgba(0,200,83,0.2);   color: #00e676; border: 1px solid #00c853; }
.badge-inactive { background: rgba(255,82,82,0.2);  color: #ff6b6b; border: 1px solid #ff5252; }
.badge-admin    { background: rgba(0,188,212,0.2);  color: #80deea; border: 1px solid #00bcd4; }
.badge-user     { background: rgba(0,188,212,0.2);  color: #80deea; border: 1px solid #00bcd4; }
.badge-member   { background: rgba(0,200,83,0.2);   color: #69f0ae; border: 1px solid #00c853; }
.badge-self     { background: rgba(255,215,0,0.2);  color: #ffd700; border: 1px solid #ffb300; }

/* Action buttons */
.btn-action {
    padding: 5px 12px; border: none; border-radius: 5px;
    cursor: pointer; font-size: 12px; font-weight: bold;
    transition: all 0.2s; margin: 2px;
    box-shadow: none;
}
.btn-toggle-on  { background: #c62828; color: #fff; }
.btn-toggle-off { background: #2e7d32; color: #fff; }
.btn-toggle-on:hover  { background: #e53935; transform: translateY(-1px); box-shadow: none; }
.btn-toggle-off:hover { background: #43a047; transform: translateY(-1px); box-shadow: none; }
.btn-del  { background: #37474f; color: #ef9a9a; }
.btn-del:hover { background: #b71c1c; color: #fff; transform: translateY(-1px); box-shadow: none; }
.btn-prio-a { background: linear-gradient(135deg, #00bcd4, #0097a7); color: #fff; }
.btn-prio-u { background: linear-gradient(135deg, #00bcd4, #0097a7); color: #fff; }
.btn-prio-a:hover { background: linear-gradient(135deg, #00e5ff, #00bcd4); transform: translateY(-1px); }
.btn-prio-u:hover { background: linear-gradient(135deg, #00e5ff, #00bcd4); transform: translateY(-1px); }

/* Stats summary row */
.stats-row {
    display: flex; gap: 20px; margin: 30px 0; flex-wrap: wrap;
}
.stat-box {
    background: linear-gradient(135deg, rgba(0,188,212,0.15) 0%, rgba(0,150,167,0.15) 100%);
    border: 2px solid #0ff;
    border-radius: 12px;
    padding: 25px;
    text-align: center;
    flex: 1;
    min-width: 120px;
    transition: all 0.3s;
    position: relative;
    overflow: hidden;
}
.stat-box:hover {
    transform: translateY(-8px) scale(1.02);
    box-shadow: 0 15px 40px rgba(0,255,255,0.4);
}
.stat-box .num { font-size: 36px; font-weight: bold; color: #0ff; text-shadow: 0 0 15px rgba(255,255,255,0.5); }
.stat-box .lbl {
    color: #0ff;
    font-size: 14px;
    margin-top: 12px;
    text-transform: uppercase;
    letter-spacing: 1px;
}

@media(max-width:768px) { .stats-grid { grid-template-columns: 1fr; } table { font-size: 12px; } th, td { padding: 10px; } }

/* Tabs */
.page-tabs {
    display: flex; gap: 0; margin-bottom: 30px;
    border-radius: 10px; overflow: hidden;
    border: 2px solid #0ff;
    box-shadow: 0 0 20px rgba(0,255,255,0.2);
}
.page-tab {
    flex: 1; padding: 14px; background: transparent; border: none;
    color: #2a8a8a; font-size: 14px; font-weight: bold; cursor: pointer;
    transition: all 0.3s; letter-spacing: 1px; font-family: Consolas,monospace;
    box-shadow: none;
}
.page-tab.active {
    background: linear-gradient(135deg, #00bcd4, #0097a7);
    color: #fff;
    box-shadow: 0 0 20px rgba(0,188,212,0.4);
}
.page-tab:hover:not(.active) { background: rgba(0,255,255,0.08); color: #0ff; transform: none; }
.tab-panel { display: none; }
.tab-panel.active { display: block; }

/* Permission Matrix */
.perm-table { width: 100%; border-collapse: collapse; margin-top: 12px; }
.perm-table th { background: linear-gradient(135deg,#004d4d,#003333); color: #0ff; padding: 14px 16px; text-align: center; font-size: 13px; letter-spacing: 0.5px; border: 1px solid #333; }
.perm-table th.col-menu { text-align: left; min-width: 180px; }
.perm-table td { padding: 12px 16px; border: 1px solid #333; text-align: center; vertical-align: middle; }
.perm-table tr:nth-child(odd) { background: rgba(255,255,255,0.03); }
.perm-table tr:hover { background: rgba(0,255,255,0.15); }
.perm-table td.col-menu { text-align: left; }
.perm-table tr.sub-row td.col-menu { padding-left: 32px; color: #aaa; }
.perm-table tr.sub-row td.col-menu i { margin-right: 6px; font-size: 11px; opacity: 0.6; }
.perm-select {
    background: #0a0a0a; color: #0ff; border: 2px solid #0ff;
    border-radius: 6px; padding: 5px 10px; font-size: 13px;
    cursor: pointer; transition: all 0.3s; width: 110px;
    font-family: Consolas,monospace;
}
.perm-select:focus { outline: none; box-shadow: 0 0 15px rgba(0,255,255,0.5); }
.perm-select.val-both  { border-color: #00e676; color: #00e676; }
.perm-select.val-view  { border-color: #ffca28; color: #ffca28; }
.perm-select.val-none  { border-color: #555; color: #666; }
.perm-badge-role { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: bold; letter-spacing: 1px; }
.role-A { background: rgba(0,188,212,0.2); border: 1px solid #0ff; color: #0ff; }
.role-U { background: rgba(0,188,212,0.2); border: 1px solid #0ff; color: #0ff; }
.role-M { background: rgba(76,175,80,0.2); border: 1px solid #4caf50; color: #4caf50; }
.perm-save-btn {
    background: linear-gradient(135deg, #00bcd4 0%, #0097a7 100%);
    color: #fff; border: none; padding: 12px 32px;
    border-radius: 8px; font-size: 15px; font-weight: bold;
    cursor: pointer; margin-top: 20px;
    box-shadow: 0 4px 15px rgba(0,188,212,0.3);
    transition: all 0.3s;
}
.perm-save-btn:hover {
    background: linear-gradient(135deg, #00e5ff 0%, #00bcd4 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,188,212,0.5);
}
.perm-msg-ok  { background: rgba(0,230,118,0.1); border: 1px solid #00e676; color: #00e676; padding: 15px; border-radius: 8px; margin-bottom: 16px; }
.perm-msg-err { background: rgba(255,107,53,0.1); border: 1px solid #ff6b35; color: #ff6b35; padding: 15px; border-radius: 8px; margin-bottom: 16px; }
.sk-active-y { color: #00e676; }
.sk-active-n { color: #ff6b6b; }

/* Branch Access */
.branch-user-select {
    background: #0a0a0a; color: #0ff; border: 2px solid #0ff;
    border-radius: 8px; padding: 10px 14px; font-size: 14px;
    width: 100%; max-width: 420px; cursor: pointer; outline: none;
    font-family: Consolas,monospace;
    transition: box-shadow 0.3s;
}
.branch-user-select:focus { box-shadow: 0 0 15px rgba(0,255,255,0.4); }
.branch-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 10px;
    margin-top: 16px;
}
.branch-card {
    background: rgba(0,0,0,0.35);
    border: 1px solid #333;
    border-radius: 8px;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    transition: all 0.2s;
}
.branch-card:hover { border-color: #0ff; background: rgba(0,255,255,0.06); }
.branch-card.selected { border-color: #00e676; background: rgba(0,230,118,0.08); }
.branch-card input[type=checkbox] { accent-color: #00e676; width: 16px; height: 16px; cursor: pointer; flex-shrink: 0; }
.branch-card .bc-code { color: #0ff; font-weight: bold; font-size: 13px; min-width: 36px; }
.branch-card .bc-name { color: #ccc; font-size: 13px; }
.branch-card.selected .bc-name { color: #00e676; }
.branch-save-btn {
    background: linear-gradient(135deg, #00c853 0%, #009624 100%);
    color: #fff; border: none; padding: 12px 32px;
    border-radius: 8px; font-size: 15px; font-weight: bold;
    cursor: pointer; margin-top: 20px;
    box-shadow: 0 4px 15px rgba(0,200,83,0.3);
    transition: all 0.3s;
}
.branch-save-btn:hover {
    background: linear-gradient(135deg, #00e676 0%, #00c853 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,200,83,0.5);
}
.branch-select-all-btn {
    background: rgba(0,188,212,0.15); color: #0ff;
    border: 1px solid #0ff; border-radius: 6px;
    padding: 6px 14px; font-size: 12px; font-weight: bold;
    cursor: pointer; margin-right: 8px; box-shadow: none; transition: all 0.2s;
}
.branch-select-all-btn:hover { background: rgba(0,188,212,0.3); transform: none; box-shadow: none; }
.branch-count-badge {
    display: inline-block; background: rgba(0,230,118,0.15);
    color: #00e676; border: 1px solid #00c853;
    border-radius: 12px; padding: 2px 10px; font-size: 12px; font-weight: bold;
}

/* Modal */
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.7); z-index: 2000;
    align-items: center; justify-content: center;
}
.modal-overlay.show { display: flex; }
.modal-box {
    background: linear-gradient(135deg, #0a0a1e, #0d1a2e);
    border: 2px solid #0ff; border-radius: 14px;
    padding: 32px 36px; width: 100%; max-width: 400px;
    box-shadow: 0 0 40px rgba(0,255,255,0.4);
    animation: fadeInUp 0.3s ease;
}
.modal-box h3 { color: #0ff; font-size: 18px; margin-bottom: 20px; text-shadow: 0 0 10px rgba(0,255,255,0.5); }
.modal-box h3 i { margin-right: 8px; }
.modal-group { margin-bottom: 14px; }
.modal-group label { display: block; color: #0ff; font-size: 12px; font-weight: bold; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 1px; }
.modal-group .inp-wrap { position: relative; }
.modal-group input {
    width: 100%; padding: 10px 38px 10px 12px;
    background: #0a0a0a; border: 2px solid #0ff;
    border-radius: 7px; color: #0ff; font-family: Consolas,monospace; font-size: 14px; outline: none;
    transition: all 0.3s;
}
.modal-group input:focus { border-color: #00ffff; box-shadow: 0 0 15px rgba(0,255,255,0.5); }
.modal-group input::placeholder { color: #336; }
.modal-tgl {
    position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
    background: none; border: none; color: #0ff; cursor: pointer; font-size: 14px; opacity: 0.7;
    box-shadow: none; padding: 0; margin: 0;
}
.modal-tgl:hover { opacity: 1; transform: translateY(-50%); box-shadow: none; }
.modal-actions { display: flex; gap: 10px; margin-top: 20px; }
.btn-modal-ok {
    flex: 1; padding: 11px;
    background: linear-gradient(135deg, #00bcd4 0%, #0097a7 100%);
    color: #fff; border: none; border-radius: 7px; font-size: 14px; font-weight: bold; cursor: pointer; transition: all 0.2s;
    box-shadow: 0 4px 15px rgba(0,188,212,0.3);
}
.btn-modal-ok:hover { background: linear-gradient(135deg, #00e5ff 0%, #00bcd4 100%); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(0,188,212,0.5); }
.btn-modal-cancel {
    flex: 1; padding: 11px; background: #1a1a2e; color: #aaa;
    border: 2px solid #333; border-radius: 7px; font-size: 14px; cursor: pointer; transition: all 0.2s;
    box-shadow: none;
}
.btn-modal-cancel:hover { background: #2a2a3a; color: #eee; transform: none; box-shadow: none; }
.btn-chgpw { background: linear-gradient(135deg, #00bcd4, #0097a7); color: #fff; }
.btn-chgpw:hover { background: linear-gradient(135deg, #00e5ff, #00bcd4); }

/* Loading */
.loading { text-align: center; padding: 50px; font-size: 18px; color: #0ff; }
.loading i { font-size: 48px; animation: spin 1s linear infinite; }
@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(20px); }
    to   { opacity: 1; transform: translateY(0); }
}
</style>
</head>
<body>
<?php pos_expiry_banner(); ?>
<?php $MENU_ACTIVE = 'usermgmt'; require_once 'POS_MENU.php'; ?>
<?php $pos_topright_show_online = false; require_once __DIR__ . '/POS_TOPRIGHT.php'; ?>

<h1>
<?php if ($pos_priority === 'A'): ?>
    <i class="fas fa-shield-alt"></i> จัดการผู้ใช้งานระบบ
<?php else: ?>
    <i class="fas fa-user-circle"></i> ข้อมูลบัญชีของฉัน
<?php endif; ?>
</h1>

<div style="text-align:left; margin-bottom:20px;">
<div class="stats-grid">
<?php if (pos_can_view('detail')): ?>
<div class="stat-card">
<button type="button" onclick="window.location.href='index.php'" style="background:#ff6b35; color:#fff; border:none; padding:10px 24px; border-radius:6px; cursor:pointer; font-size:16px; margin:0 10px; font-weight:bold;">Main</button>
</div>
<div class="stat-card">
<button type="button" onclick="window.location.href='POS_DETAIL.php'" style="background:#4caf50; color:#fff; border:none; padding:10px 24px; border-radius:6px; cursor:pointer; font-size:16px; margin:0 10px; font-weight:bold;">รายการขาย</button>
</div>
<?php endif; ?>
<?php if (pos_can_view('items')): ?>
<div class="stat-card">
<button type="button" onclick="window.location.href='POS_ITEMS.php'" style="background:#4caf50; color:#fff; border:none; padding:10px 24px; border-radius:6px; cursor:pointer; font-size:16px; margin:0 10px; font-weight:bold;">สินค้า</button>
</div>
<?php endif; ?>
<?php if (pos_can_view('members')): ?>
<div class="stat-card">
<button type="button" onclick="window.location.href='POS_MEMBERS.php'" style="background:#4caf50; color:#fff; border:none; padding:10px 24px; border-radius:6px; cursor:pointer; font-size:16px; margin:0 10px; font-weight:bold;">สมาชิก</button>
</div>
<?php endif; ?>
<?php if (pos_can_view('sales')): ?>
<div class="stat-card">
<button type="button" onclick="window.location.href='POS_SALES.php'" style="background:#4caf50; color:#fff; border:none; padding:10px 24px; border-radius:6px; cursor:pointer; font-size:16px; margin:0 10px; font-weight:bold;">รายงาน</button>
</div>
<?php endif; ?>
<?php if (pos_can_view('search')): ?>
<div class="stat-card">
<button type="button" onclick="window.location.href='POS_ALL.php'" style="background:#ff6b35; color:#fff; border:none; padding:10px 24px; border-radius:6px; cursor:pointer; font-size:16px; margin:0 10px; font-weight:bold;">ค้นหา</button>
</div>
<?php endif; ?>
<?php if (pos_can_view('usermgmt')): ?>
<div class="stat-card">
<button type="button" onclick="window.location.href='POS_USER.php'" style="background:linear-gradient(135deg,#00bcd4,#0097a7); color:#fff; border:none; padding:10px 24px; border-radius:6px; cursor:pointer; font-size:16px; margin:0 10px; font-weight:bold; box-shadow:0 0 14px rgba(0,188,212,0.5);"><i class="fas fa-shield-alt" style="margin-right:6px;"></i>User Management</button>
</div>
<?php endif; ?>
</div>
</div>

<?php if ($msg_ok !== ''): ?>
<div class="msg msg-ok"><i class="fas fa-check-circle" style="margin-right:8px;"></i><?= $msg_ok ?></div>
<?php endif; ?>
<?php if ($msg_err !== ''): ?>
<div class="msg msg-err"><i class="fas fa-exclamation-triangle" style="margin-right:8px;"></i><?= $msg_err ?></div>
<?php endif; ?>

<?php
$total   = count($users);
$active  = count(array_filter($users, fn($u) => $u['active'] === 'Y'));
$admins  = count(array_filter($users, fn($u) => $u['priority'] === 'A'));
$pending = count(array_filter($users, fn($u) => $u['active'] === 'N'));
?>
<?php if ($pos_priority === 'A'): ?>
<div class="stats-row">
    <div class="stat-box"><div class="num"><?= $total ?></div><div class="lbl">ผู้ใช้ทั้งหมด</div></div>
    <div class="stat-box"><div class="num" style="color:#00e676;"><?= $active ?></div><div class="lbl">Active</div></div>
    <div class="stat-box"><div class="num" style="color:#ff6b6b;"><?= $pending ?></div><div class="lbl">รออนุมัติ</div></div>
    <div class="stat-box"><div class="num" style="color:#0ff;"><?= $admins ?></div><div class="lbl">Admin</div></div>
</div>
<?php endif; ?>

<?php if ($pos_priority === 'A'): ?>
<div class="page-tabs">
    <button class="page-tab active" onclick="switchTab('web')"><i class="fas fa-users" style="margin-right:6px;"></i>ผู้ใช้งานระบบเว็บ</button>
    <button class="page-tab" onclick="switchTab('sk')"><i class="fas fa-id-badge" style="margin-right:6px;"></i>พนักงาน (SK_USER)</button>
    <button class="page-tab" onclick="switchTab('branch')"><i class="fas fa-store-alt" style="margin-right:6px;"></i>สิทธิ์สาขา</button>
    <button class="page-tab" onclick="switchTab('perm')"><i class="fas fa-shield-alt" style="margin-right:6px;"></i>สิทธิ์เมนู</button>
</div>
<?php endif; ?>

<!-- ===== TAB: SK_WEB_USER ===== -->
<div class="tab-panel active" id="tab-web">
<div class="table-wrap">
<table>
    <thead>
        <tr>
            <th>#</th>
            <th>Username</th>
            <th>ชื่อ-นามสกุล</th>
            <th>สิทธิ์</th>
            <th>สถานะ</th>
            <th>เริ่มต้น</th>
            <th>สิ้นสุด</th>
            <th>อายุ (วัน)</th>
            <th>เริ่มต้นรหัสผ่าน</th>
            <th>หมดอายุรหัสผ่าน</th>
            <th>อายุรหัสผ่าน (วัน)</th>
            <th style="text-align:center;">จัดการ</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($users)): ?>
        <tr><td colspan="12" style="text-align:center; padding:40px; color:#888;">ไม่พบข้อมูล</td></tr>
    <?php else: ?>
    <?php foreach ($users as $i => $u):
        $is_self = strtoupper($u['user_id']) === strtoupper($pos_logged_user);
        // คำนวณสถานะอายุใช้งาน
        $vst = ''; $vcol = '#aaa';
        if (!empty($u['end_date'])) {
            $ve = DateTime::createFromFormat('d/m/Y', $u['end_date']);
            if ($ve) {
                $vd = (int)(new DateTime('today'))->diff($ve)->format('%r%a');
                if      ($vd < 0)   { $vst = '⚠ หมดอายุ';                     $vcol = '#ff4444'; }
                elseif  ($vd <= 7)  { $vst = '⚠ ใกล้หมด ('.$vd.' วัน)';       $vcol = '#ffaa00'; }
                else                { $vst = 'เหลือ '.$vd.' วัน';              $vcol = '#4caf50'; }
            }
        }
        // คำนวณสถานะอายุรหัสผ่านจาก PWD_START_DATE / PWD_END_DATE ที่เก็บใน DB โดยตรง
        $pst = ''; $pcol = '#aaa'; $pdays_left = null;
        if (!empty($u['pwd_end_date'])) {
            $pe = DateTime::createFromFormat('d/m/Y', $u['pwd_end_date']);
            if ($pe) {
                $pdays_left = (int)(new DateTime('today'))->diff($pe)->format('%r%a');
                if      ($pdays_left < 0)   { $pst = '⚠ หมดอายุ';                              $pcol = '#ff4444'; }
                elseif  ($pdays_left <= 15)  { $pst = '⚠ ใกล้หมด ('.$pdays_left.' วัน)';       $pcol = '#ffaa00'; }
                else                         { $pst = 'เหลือ '.$pdays_left.' วัน';              $pcol = '#4caf50'; }
            }
        }
    ?>
        <tr>
            <td style="color:#555;"><?= $i + 1 ?></td>
            <td style="color:#0ff; font-weight:bold;">
                <?= htmlspecialchars($u['user_id']) ?>
                <?php if ($is_self): ?>
                <span class="badge badge-self" style="margin-left:6px;">คุณ</span>
                <?php endif; ?>
            </td>
            <td style="color:#ddd;"><?= htmlspecialchars($u['full_name']) ?></td>
            <td>
                <?php if ($u['priority'] === 'A'): ?>
                <span class="badge badge-admin"><i class="fas fa-shield-alt"></i> Admin</span>
                <?php elseif ($u['priority'] === 'M'): ?>
                <span class="badge badge-member"><i class="fas fa-users"></i> Member</span>
                <?php else: ?>
                <span class="badge badge-user"><i class="fas fa-user"></i> User</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($u['active'] === 'Y'): ?>
                <span class="badge badge-active"><i class="fas fa-check-circle"></i> Active</span>
                <?php else: ?>
                <span class="badge badge-inactive"><i class="fas fa-times-circle"></i> Inactive</span>
                <?php endif; ?>
            </td>
            <td style="font-size:12px;color:#aef;text-align:center;">
                <?= $u['start_date'] !== '' ? htmlspecialchars($u['start_date']) : '<span style="color:#333">—</span>' ?>
            </td>
            <td style="font-size:12px;text-align:center;">
                <?= $u['end_date'] !== '' ? htmlspecialchars($u['end_date']) : '<span style="color:#333">—</span>' ?>
                <?php if ($vst !== ''): ?><br><span style="font-size:10px;color:<?= $vcol ?>;"><?= $vst ?></span><?php endif; ?>
            </td>
            <td style="font-size:12px;color:#ffcc44;text-align:center;">
                <?= $u['valid_days'] !== '' ? htmlspecialchars($u['valid_days']).' วัน' : '<span style="color:#333">—</span>' ?>
            </td>
            <!-- เริ่มต้นรหัสผ่าน (PWD_START_DATE) -->
            <td style="font-size:12px;color:#ce93d8;text-align:center;">
                <?= !empty($u['pwd_start_date']) ? htmlspecialchars($u['pwd_start_date']) : '<span style="color:#333">—</span>' ?>
            </td>
            <!-- หมดอายุรหัสผ่าน (PWD_END_DATE จาก DB) -->
            <td style="font-size:12px;text-align:center;">
                <?php if (!empty($u['pwd_end_date'])): ?>
                <span style="color:<?= $pcol ?>;font-weight:<?= ($pcol === '#ff4444' || $pcol === '#ffaa00') ? 'bold' : 'normal' ?>;">
                    <?= htmlspecialchars($u['pwd_end_date']) ?>
                </span>
                <?php if ($pst !== ''): ?><br><span style="font-size:10px;color:<?= $pcol ?>;font-weight:<?= ($pcol === '#ff4444' || $pcol === '#ffaa00') ? 'bold' : 'normal' ?>;"><?= $pst ?></span><?php endif; ?>
                <?php else: ?>
                <span style="color:#333">—</span>
                <?php endif; ?>
            </td>
            <!-- อายุรหัสผ่าน (วัน) จาก PWD_VALID_DAYS -->
            <td style="font-size:12px;text-align:center;">
                <?php if ($u['pwd_valid_days'] !== ''): ?>
                <span style="display:inline-block;background:rgba(171,71,188,0.18);border:1px solid #ab47bc;border-radius:10px;padding:2px 10px;color:#ce93d8;font-weight:bold;letter-spacing:0.5px;">
                    <?= (int)$u['pwd_valid_days'] ?> วัน
                </span>
                <?php else: ?>
                <span style="color:#333">—</span>
                <?php endif; ?>
            </td>
            <td style="text-align:center; white-space:nowrap;">

                <?php if (!$is_self && $pos_priority === 'A'): ?>

                <!-- Toggle Active -->
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="user_id" value="<?= htmlspecialchars($u['user_id']) ?>">
                    <input type="hidden" name="current_active" value="<?= $u['active'] ?>">
                    <?php if ($u['active'] === 'Y'): ?>
                    <button type="submit" class="btn-action btn-toggle-on"
                            onclick="return confirm('ปิดการใช้งาน <?= htmlspecialchars($u['user_id']) ?>?')">
                        <i class="fas fa-ban"></i> ปิด
                    </button>
                    <?php else: ?>
                    <button type="submit" class="btn-action btn-toggle-off"
                            onclick="return confirm('เปิดการใช้งาน <?= htmlspecialchars($u['user_id']) ?>?')">
                        <i class="fas fa-check"></i> อนุมัติ
                    </button>
                    <?php endif; ?>
                </form>

                <!-- Change Priority -->
                <form method="POST" style="display:inline;" id="form-prio-<?= htmlspecialchars($u['user_id']) ?>">
                    <input type="hidden" name="action" value="change_priority">
                    <input type="hidden" name="user_id" value="<?= htmlspecialchars($u['user_id']) ?>">
                    <select name="new_priority"
                            style="padding:5px 8px;background:#1a1a2e;border:1px solid #444;border-radius:5px;color:#eee;font-size:12px;cursor:pointer;"
                            onchange="changePriority(this,'<?= addslashes(htmlspecialchars($u['user_id'])) ?>','<?= $u['priority'] ?>')">
                        <option value="U" <?= $u['priority']==='U'?'selected':'' ?>>User</option>
                        <option value="M" <?= $u['priority']==='M'?'selected':'' ?>>Member</option>
                        <option value="A" <?= $u['priority']==='A'?'selected':'' ?>>Admin</option>
                    </select>
                </form>

                <!-- เปลี่ยนรหัสผ่าน -->
                <button type="button" class="btn-action btn-chgpw"
                        onclick="openChangePw('<?= htmlspecialchars($u['user_id']) ?>')">
                    <i class="fas fa-key"></i> รหัสผ่าน
                </button>

                <!-- Delete -->
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" value="<?= htmlspecialchars($u['user_id']) ?>">
                    <button type="submit" class="btn-action btn-del"
                            onclick="return confirm('ลบ <?= htmlspecialchars($u['user_id']) ?> ออกจากระบบ?')">
                        <i class="fas fa-trash"></i> ลบ
                    </button>
                </form>

                <!-- อายุใช้งาน -->
                <button type="button" class="btn-action"
                        style="background:rgba(255,204,0,0.15);border:1px solid #ffcc00;color:#ffcc00;"
                        onclick="openValidityModal('<?= htmlspecialchars($u['user_id']) ?>','<?= htmlspecialchars($u['start_date']) ?>','<?= htmlspecialchars($u['end_date']) ?>','<?= htmlspecialchars($u['valid_days']) ?>')">
                    <i class="fas fa-calendar-alt"></i> อายุ
                </button>
                <button type="button" class="btn-action"
                        style="background:rgba(171,71,188,0.15);border:1px solid #ab47bc;color:#ce93d8;"
                        onclick="openPwdExpiryModal('<?= htmlspecialchars($u['user_id']) ?>','<?= htmlspecialchars($u['pwd_start_date']) ?>','<?= htmlspecialchars($u['pwd_valid_days']) ?>')">
                    <i class="fas fa-key"></i> อายุรหัสผ่าน
                </button>

                <?php else: ?>
                <button type="button" class="btn-action btn-chgpw"
                        onclick="openChangePw('<?= htmlspecialchars($u['user_id']) ?>')">
                    <i class="fas fa-key"></i> รหัสผ่าน
                </button>
                <?php endif; ?>

            </td>
        </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
</div><!-- /table-wrap web -->
</div><!-- /tab-web -->

<!-- ===== TAB: BRANCH ACCESS ===== -->
<?php if ($pos_priority === 'A'): ?>
<div class="tab-panel" id="tab-branch">

<div style="margin-bottom:20px;">
    <div style="color:#aaa;font-size:13px;margin-bottom:14px;">
        <i class="fas fa-info-circle" style="color:#0ff;margin-right:6px;"></i>
        เลือก User แล้วติ๊กสาขาที่ต้องการให้มองเห็นข้อมูล — Admin เห็นทุกสาขาโดยอัตโนมัติ ไม่ต้องกำหนด
    </div>

    <label style="color:#0ff;font-size:13px;font-weight:bold;display:block;margin-bottom:8px;text-transform:uppercase;letter-spacing:1px;">
        <i class="fas fa-user" style="margin-right:6px;"></i>เลือก User
    </label>
    <select class="branch-user-select" id="branch-user-sel" onchange="loadBranchAccess(this.value)">
        <option value="">— กรุณาเลือก User —</option>
        <?php foreach ($users as $u):
            if ($u['priority'] === 'A') continue; ?>
        <option value="<?= htmlspecialchars($u['user_id']) ?>">
            <?= htmlspecialchars($u['user_id']) ?> — <?= htmlspecialchars($u['full_name']) ?>
            (<?= $u['priority'] === 'M' ? 'Member' : 'User' ?>)
        </option>
        <?php endforeach; ?>
    </select>
</div>

<!-- แสดงสาขาและ checkbox -->
<div id="branch-access-panel" style="display:none;">
    <form method="POST" id="form-branch-access">
        <input type="hidden" name="action" value="save_branch_access">
        <input type="hidden" name="target_user_id" id="branch-target-uid" value="">

        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;flex-wrap:wrap;">
            <span style="color:#eee;font-size:14px;">สาขาที่เลือก: <span class="branch-count-badge" id="branch-selected-count">0</span> / <?= count($branch_list) ?> สาขา</span>
            <button type="button" class="branch-select-all-btn" onclick="branchSelectAll(true)">
                <i class="fas fa-check-double" style="margin-right:4px;"></i>เลือกทั้งหมด
            </button>
            <button type="button" class="branch-select-all-btn" onclick="branchSelectAll(false)" style="border-color:#ff6b6b;color:#ff6b6b;">
                <i class="fas fa-times" style="margin-right:4px;"></i>ล้างทั้งหมด
            </button>
        </div>

        <div class="branch-grid">
        <?php foreach ($branch_list as $br): ?>
            <label class="branch-card" id="bc-<?= htmlspecialchars($br['sale_office']) ?>">
                <input type="checkbox"
                       name="branch_ids[]"
                       value="<?= htmlspecialchars($br['sale_office']) ?>"
                       class="branch-chk"
                       onchange="updateBranchCard(this)">
                <span class="bc-code"><?= htmlspecialchars($br['sale_office']) ?></span>
                <span class="bc-name"><?= htmlspecialchars($br['office_name']) ?></span>
            </label>
        <?php endforeach; ?>
        </div>

        <div style="text-align:center;">
            <button type="submit" class="branch-save-btn">
                <i class="fas fa-save" style="margin-right:8px;"></i>บันทึกสิทธิ์สาขา
            </button>
        </div>
    </form>
</div>

<!-- ตารางสรุปสิทธิ์ทุก User -->
<div style="margin-top:40px;">
    <div style="color:#0ff;font-size:14px;font-weight:bold;margin-bottom:12px;text-transform:uppercase;letter-spacing:1px;">
        <i class="fas fa-table" style="margin-right:8px;"></i>สรุปสิทธิ์สาขาปัจจุบัน
    </div>
    <?php
    $non_admin_users = array_filter($users, fn($u) => $u['priority'] !== 'A');
    ?>
    <?php if (empty($non_admin_users)): ?>
    <div style="text-align:center;padding:30px;color:#888;">ไม่มีข้อมูล User/Member</div>
    <?php else: ?>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th style="width:140px;">User ID</th>
                <th>ชื่อ</th>
                <th style="width:80px;">Role</th>
                <th>สาขาที่มีสิทธิ์</th>
                <th style="width:60px;text-align:center;">จำนวน</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($non_admin_users as $u):
            $uid = $u['user_id'];
            $granted = $branch_access[$uid] ?? [];
            $cnt = count($granted);
            $branch_names = [];
            foreach ($branch_list as $br) {
                if (!empty($granted[$br['sale_office']]))
                    $branch_names[] = '<span style="display:inline-block;background:rgba(0,230,118,0.12);border:1px solid #00c853;border-radius:10px;padding:1px 8px;font-size:11px;color:#00e676;margin:2px;">'
                                    . htmlspecialchars($br['sale_office']) . ' ' . htmlspecialchars($br['office_name'])
                                    . '</span>';
            }
        ?>
        <tr>
            <td style="color:#0ff;font-weight:bold;"><?= htmlspecialchars($uid) ?></td>
            <td style="color:#ddd;"><?= htmlspecialchars($u['full_name']) ?></td>
            <td>
                <?php if ($u['priority'] === 'M'): ?>
                <span class="badge badge-member">Member</span>
                <?php else: ?>
                <span class="badge badge-user">User</span>
                <?php endif; ?>
            </td>
            <td style="line-height:1.8;">
                <?php if (empty($branch_names)): ?>
                <span style="color:#666;font-size:12px;">ยังไม่ได้กำหนด</span>
                <?php else: ?>
                <?= implode(' ', $branch_names) ?>
                <?php endif; ?>
            </td>
            <td style="text-align:center;">
                <span class="branch-count-badge"><?= $cnt ?></span>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

</div><!-- /tab-branch -->
<?php endif; ?>

<!-- ===== TAB: SK_USER ===== -->
<?php if ($pos_priority === 'A'): ?>
<div class="tab-panel" id="tab-sk">

<!-- Search bar -->
<div style="margin-bottom:16px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
    <div style="position:relative; flex:1; min-width:200px;">
        <i class="fas fa-search" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#0ff; opacity:0.6;"></i>
        <input type="text" id="sk-search"
               placeholder="ค้นหา รหัสพนักงาน หรือ ชื่อ..."
               oninput="filterSkUsers()"
               style="width:100%; padding:10px 12px 10px 38px; background:rgba(0,10,20,0.8); border:2px solid #0a4a4a; border-radius:8px; color:#0ff; font-family:Consolas,monospace; font-size:14px; outline:none;"
               onfocus="this.style.borderColor='#0ff'" onblur="this.style.borderColor='#0a4a4a'">
    </div>
    <div style="color:#aaa; font-size:13px; white-space:nowrap;">
        พบ <span id="sk-count" style="color:#0ff; font-weight:bold;">0</span> รายการ
    </div>
</div>

<?php if (!empty($sk_users)): ?>
<div class="table-wrap" style="border-color:#0ff;">
<table>
    <thead>
        <tr style="background:linear-gradient(135deg,#004d4d,#003333);">
            <th>#</th>
            <th>รหัสพนักงาน</th>
            <th>ชื่อ-นามสกุล</th>
            <th>สถานะ ACTIVE</th>
            <th style="text-align:center;">จัดการ</th>
        </tr>
    </thead>
    <tbody id="sk-table-body">
    <?php foreach ($sk_users as $si => $su): ?>
        <tr class="sk-row" data-id="<?= strtolower(htmlspecialchars($su['sk_user_id'])) ?>" data-name="<?= strtolower(htmlspecialchars($su['name'])) ?>">
            <td style="color:#555;" class="sk-num"><?= $si + 1 ?></td>
            <td style="color:#0ff; font-weight:bold;"><?= htmlspecialchars($su['sk_user_id']) ?></td>
            <td style="color:#ddd;"><?= htmlspecialchars($su['name']) ?></td>
            <td>
                <?php if ($su['active'] === 'Y'): ?>
                <span class="badge badge-active"><i class="fas fa-check-circle"></i> Active</span>
                <?php else: ?>
                <span class="badge badge-inactive"><i class="fas fa-times-circle"></i> Inactive</span>
                <?php endif; ?>
            </td>
            <td style="text-align:center;">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="toggle_sk_active">
                    <input type="hidden" name="sk_user_id" value="<?= htmlspecialchars($su['sk_user_id']) ?>">
                    <input type="hidden" name="sk_current_active" value="<?= $su['active'] ?>">
                    <?php if ($su['active'] === 'Y'): ?>
                    <button type="submit" class="btn-action btn-toggle-on"
                            onclick="return confirm('ปิดสถานะพนักงาน <?= htmlspecialchars($su['sk_user_id']) ?>?')">
                        <i class="fas fa-ban"></i> ปิด
                    </button>
                    <?php else: ?>
                    <button type="submit" class="btn-action btn-toggle-off"
                            onclick="return confirm('เปิดสถานะพนักงาน <?= htmlspecialchars($su['sk_user_id']) ?>?')">
                        <i class="fas fa-check"></i> เปิด
                    </button>
                    <?php endif; ?>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php else: ?>
<div style="text-align:center; padding:40px; color:#888;">ไม่พบข้อมูลพนักงาน</div>
<?php endif; ?>
</div><!-- /tab-sk -->
<?php endif; ?>

<div style="text-align:center; margin-top:20px; color:#555; font-size:12px;">
    &copy; <?= date('Y') ?> POS Admin Panel &nbsp;|&nbsp; Logged in as <?= htmlspecialchars($pos_logged_user) ?>
</div>

<!-- ======== Modal เปลี่ยนรหัสผ่าน ======== -->
<div class="modal-overlay" id="modal-chgpw">
    <div class="modal-box">
        <h3><i class="fas fa-key"></i> เปลี่ยนรหัสผ่าน: <span id="modal-uid" style="color:#ffd700;"></span></h3>
        <form method="POST" id="form-chgpw" autocomplete="off">
            <input type="hidden" name="action" value="admin_change_password">
            <input type="hidden" name="user_id" id="modal-user_id">

            <div class="modal-group">
                <label><i class="fas fa-lock" style="margin-right:5px;"></i>รหัสผ่านใหม่</label>
                <div class="inp-wrap">
                    <input type="password" name="new_pass" id="modal-new_pass" placeholder="อย่างน้อย 4 ตัวอักษร" required>
                    <button type="button" class="modal-tgl" onclick="modalToggle('modal-new_pass','eye-m1')"><i class="fas fa-eye" id="eye-m1"></i></button>
                </div>
            </div>

            <div class="modal-group">
                <label><i class="fas fa-check-double" style="margin-right:5px;"></i>ยืนยันรหัสผ่าน</label>
                <div class="inp-wrap">
                    <input type="password" name="cfm_pass" id="modal-cfm_pass" placeholder="ยืนยันรหัสผ่านใหม่" required>
                    <button type="button" class="modal-tgl" onclick="modalToggle('modal-cfm_pass','eye-m2')"><i class="fas fa-eye" id="eye-m2"></i></button>
                </div>
            </div>

            <div class="modal-actions">
                <button type="submit" class="btn-modal-ok"><i class="fas fa-save" style="margin-right:6px;"></i>บันทึก</button>
                <button type="button" class="btn-modal-cancel" onclick="closeChangePw()"><i class="fas fa-times" style="margin-right:6px;"></i>ยกเลิก</button>
            </div>
        </form>
    </div>
</div>

<script>
function openChangePw(uid) {
    document.getElementById('modal-uid').innerText = uid;
    document.getElementById('modal-user_id').value = uid;
    document.getElementById('modal-new_pass').value = '';
    document.getElementById('modal-cfm_pass').value = '';
    document.getElementById('modal-chgpw').classList.add('show');
    setTimeout(() => document.getElementById('modal-new_pass').focus(), 100);
}
function closeChangePw() {
    document.getElementById('modal-chgpw').classList.remove('show');
}
function modalToggle(inputId, iconId) {
    const pw = document.getElementById(inputId);
    const ic = document.getElementById(iconId);
    if (pw.type === 'password') {
        pw.type = 'text'; ic.classList.replace('fa-eye','fa-eye-slash');
    } else {
        pw.type = 'password'; ic.classList.replace('fa-eye-slash','fa-eye');
    }
}
function switchTab(tab) {
    const tabs = ['web','sk','branch','perm'];
    document.querySelectorAll('.page-tab').forEach((b,i) => {
        b.classList.toggle('active', tabs[i] === tab);
    });
    tabs.forEach(t => {
        const el = document.getElementById('tab-'+t);
        if (el) el.classList.toggle('active', t === tab);
    });
}

// ── Branch Access ─────────────────────────────────────────
const branchAccessData = <?php echo json_encode($branch_access, JSON_UNESCAPED_UNICODE); ?>;

function loadBranchAccess(uid) {
    const panel = document.getElementById('branch-access-panel');
    document.getElementById('branch-target-uid').value = uid;

    if (!uid) { panel.style.display = 'none'; return; }

    // รีเซ็ต checkbox ทั้งหมด
    document.querySelectorAll('.branch-chk').forEach(chk => {
        const granted = branchAccessData[uid] && branchAccessData[uid][chk.value];
        chk.checked = !!granted;
        updateBranchCard(chk);
    });

    updateBranchCount();
    panel.style.display = 'block';
    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function updateBranchCard(chk) {
    const card = document.getElementById('bc-' + chk.value);
    if (card) card.classList.toggle('selected', chk.checked);
    updateBranchCount();
}

function updateBranchCount() {
    const count = document.querySelectorAll('.branch-chk:checked').length;
    const el = document.getElementById('branch-selected-count');
    if (el) el.textContent = count;
}

function branchSelectAll(val) {
    document.querySelectorAll('.branch-chk').forEach(chk => {
        chk.checked = val;
        updateBranchCard(chk);
    });
}

function filterSkUsers() {
    const q = document.getElementById('sk-search').value.toLowerCase().trim();
    const rows = document.querySelectorAll('#sk-table-body .sk-row');
    let count = 0;
    rows.forEach((row, i) => {
        const id   = row.dataset.id   || '';
        const name = row.dataset.name || '';
        const match = q === '' || id.includes(q) || name.includes(q);
        row.style.display = match ? '' : 'none';
        if (match) {
            count++;
            row.querySelector('.sk-num').textContent = count;
        }
    });
    const el = document.getElementById('sk-count');
    if (el) el.textContent = count;
}

// init count เมื่อโหลดหน้า
document.addEventListener('DOMContentLoaded', function() {
    const rows = document.querySelectorAll('#sk-table-body .sk-row');
    const el = document.getElementById('sk-count');
    if (el) el.textContent = rows.length;
});

function changePriority(sel, uid, oldVal) {
    if (!confirm('เปลี่ยนสิทธิ์ ' + uid + '?')) {
        sel.value = oldVal;
        return;
    }
    document.getElementById('form-prio-' + uid).submit();
}

// ปิด modal เมื่อกด Escape
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeChangePw(); });
// ปิด modal เมื่อคลิกนอก
document.getElementById('modal-chgpw').addEventListener('click', function(e) {
    if (e.target === this) closeChangePw();
});
// Enter ใน modal
document.getElementById('modal-new_pass').addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('modal-cfm_pass').focus(); }
});
document.getElementById('modal-cfm_pass').addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('form-chgpw').submit(); }
});
// top-right expand/collapse: handled by POS_TOPRIGHT.php


// ── อายุใช้งาน modal ─────────────────────────────────────────

// แปลง dd/mm/yyyy → Date object
function parseThaiDate(s) {
    if (!s || !/^\d{2}\/\d{2}\/\d{4}$/.test(s)) return null;
    const p = s.split('/');
    return new Date(+p[2], +p[1]-1, +p[0]);
}
// แปลง Date → dd/mm/yyyy
function fmtThaiDate(d) {
    return String(d.getDate()).padStart(2,'0') + '/' +
           String(d.getMonth()+1).padStart(2,'0') + '/' +
           d.getFullYear();
}

// คำนวณ END_DATE = START_DATE + VALID_DAYS และแสดงผล preview
function calcEndFromDays() {
    const sd   = document.getElementById('validity-start').value.trim();
    const days = parseInt(document.getElementById('validity-days').value, 10);
    const lbl  = document.getElementById('validity-calc-preview');
    if (!isNaN(days) && days > 0 && sd) {
        const startD = parseThaiDate(sd);
        if (startD) {
            startD.setDate(startD.getDate() + days);
            const newEnd = fmtThaiDate(startD);
            document.getElementById('validity-end').value = newEnd;
            if (typeof $ !== 'undefined' && $.datepicker)
                $('#validity-end').datepicker('setDate', newEnd);
            if (lbl) lbl.textContent = '→ หมดอายุ: ' + newEnd;
            return;
        }
    }
    if (lbl) lbl.textContent = '';
}

// คำนวณ VALID_DAYS จาก START_DATE และ END_DATE
function calcDaysFromRange() {
    const sd = parseThaiDate(document.getElementById('validity-start').value.trim());
    const ed = parseThaiDate(document.getElementById('validity-end').value.trim());
    const lbl = document.getElementById('validity-calc-preview');
    if (sd && ed && ed >= sd) {
        const diffDays = Math.round((ed - sd) / 86400000);
        document.getElementById('validity-days').value = diffDays;
        if (lbl) lbl.textContent = '→ ' + diffDays + ' วัน';
    } else {
        if (lbl) lbl.textContent = '';
    }
}

function openValidityModal(uid, sd, ed, vd) {
    document.getElementById('validity-uid').textContent = uid;
    document.getElementById('validity-user-id').value = uid;
    document.getElementById('validity-start').value = sd;
    document.getElementById('validity-end').value   = ed;
    document.getElementById('validity-days').value  = vd;
    const lbl = document.getElementById('validity-calc-preview');
    if (lbl) lbl.textContent = '';
    document.getElementById('modal-validity').classList.add('show');
    if (typeof $ !== 'undefined' && $.datepicker) {
        $('#validity-start,#validity-end').datepicker({dateFormat:'dd/mm/yy',changeMonth:true,changeYear:true});
        // เมื่อเปลี่ยน start_date → คำนวณ end_date ใหม่ถ้ามี valid_days
        $('#validity-start').off('change.v').on('change.v', function() {
            $('#validity-end').datepicker('option','minDate', this.value||null);
            const days = parseInt(document.getElementById('validity-days').value, 10);
            if (!isNaN(days) && days > 0) calcEndFromDays();
            else calcDaysFromRange();
        });
        // เมื่อเปลี่ยน end_date → คำนวณ valid_days ใหม่
        $('#validity-end').off('change.v').on('change.v', function() {
            $('#validity-start').datepicker('option','maxDate', this.value||null);
            calcDaysFromRange();
        });
    }
    // valid_days input: คำนวณ end_date ทันที
    const daysEl = document.getElementById('validity-days');
    daysEl.oninput = function() {
        const v = parseInt(this.value, 10);
        if (!isNaN(v) && v > 0) calcEndFromDays();
        else {
            const lbl2 = document.getElementById('validity-calc-preview');
            if (lbl2) lbl2.textContent = '';
        }
    };
}
function closeValidityModal() { document.getElementById('modal-validity').classList.remove('show'); }
function clearValidity() {
    ['validity-start','validity-end','validity-days'].forEach(id => document.getElementById(id).value = '');
    const lbl = document.getElementById('validity-calc-preview');
    if (lbl) lbl.textContent = '';
}
document.getElementById('modal-validity').addEventListener('click', function(e){ if(e.target===this) closeValidityModal(); });

// ── อายุรหัสผ่าน modal ────────────────────────────────────
function openPwdExpiryModal(uid, changedDate, validDays) {
    document.getElementById('pwd-expiry-uid').textContent = uid;
    document.getElementById('pwd-expiry-user-id').value = uid;
    const disp = document.getElementById('pwd-expiry-changed-display');
    disp.textContent = changedDate || 'ยังไม่เคยเปลี่ยนรหัสผ่าน';
    document.getElementById('pwd-expiry-days').value = validDays || '';
    document.getElementById('pwd-expiry-preview').textContent = '';
    // previewวันหมดอายุทันทีถ้ามี changedDate
    if (changedDate) {
        const p = changedDate.split('/');
        if (p.length === 3) {
            const cd = new Date(+p[2], +p[1]-1, +p[0]);
            const vd = parseInt(validDays) || 90;
            cd.setDate(cd.getDate() + vd);
            const exp = String(cd.getDate()).padStart(2,'0')+'/'+String(cd.getMonth()+1).padStart(2,'0')+'/'+cd.getFullYear();
            document.getElementById('pwd-expiry-preview').textContent = '→ หมดอายุ: '+exp;
        }
    }
    document.getElementById('modal-pwd-expiry').classList.add('show');
    // update preview เมื่อพิมพ์อายุใหม่
    document.getElementById('pwd-expiry-days').oninput = function() {
        if (!changedDate) return;
        const p2 = changedDate.split('/');
        const cd2 = new Date(+p2[2], +p2[1]-1, +p2[0]);
        const vd2 = parseInt(this.value);
        if (!isNaN(vd2) && vd2 > 0) {
            cd2.setDate(cd2.getDate() + vd2);
            const exp2 = String(cd2.getDate()).padStart(2,'0')+'/'+String(cd2.getMonth()+1).padStart(2,'0')+'/'+cd2.getFullYear();
            document.getElementById('pwd-expiry-preview').textContent = '→ หมดอายุ: '+exp2;
        } else {
            document.getElementById('pwd-expiry-preview').textContent = '';
        }
    };
}
function closePwdExpiryModal() { document.getElementById('modal-pwd-expiry').classList.remove('show'); }
function clearPwdExpiry() {
    document.getElementById('pwd-expiry-days').value = '';
    document.getElementById('pwd-expiry-preview').textContent = '';
}
document.getElementById('modal-pwd-expiry').addEventListener('click', function(e){ if(e.target===this) closePwdExpiryModal(); });

</script>
<!-- ===== TAB: MENU PERMISSION ===== -->
<?php if ($pos_priority === 'A'): ?>
<div class="tab-panel" id="tab-perm">

<?php if ($perm_msg_ok !== ''): ?>
<div class="perm-msg-ok"><i class="fas fa-check-circle" style="margin-right:8px;"></i><?= htmlspecialchars($perm_msg_ok) ?></div>
<?php endif; ?>
<?php if ($perm_msg_err !== ''): ?>
<div class="perm-msg-err"><i class="fas fa-exclamation-triangle" style="margin-right:8px;"></i><?= htmlspecialchars($perm_msg_err) ?></div>
<?php endif; ?>

<?php if (empty($perm_roles) || empty($perm_menus)): ?>
<div style="text-align:center;padding:60px;color:#888;">
    <i class="fas fa-exclamation-circle" style="font-size:40px;margin-bottom:16px;display:block;color:#ff6b35;opacity:0.7;"></i>
    ไม่พบข้อมูลใน POS_ROLE หรือ POS_MENU_DEF<br>
    <small style="color:#555;">กรุณารัน DDL script สร้างตารางและข้อมูลก่อน</small>
</div>
<?php else: ?>

<form method="POST">
<input type="hidden" name="action" value="save_perm">

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
    <div style="color:#aaa;font-size:13px;">
        <i class="fas fa-info-circle" style="color:#0ff;margin-right:6px;"></i>
        กำหนดสิทธิ์การเข้าถึงเมนูสำหรับแต่ละ Role
    </div>
    <div style="display:flex;gap:16px;font-size:12px;color:#aaa;align-items:center;">
        <span><span style="color:#00e676;">●</span> เข้าได้+เห็นเมนู</span>
        <span><span style="color:#ffca28;">●</span> เห็นเมนูอย่างเดียว</span>
        <span><span style="color:#555;">●</span> ปิด</span>
    </div>
</div>

<div class="table-wrap">
<table class="perm-table">
    <thead>
        <tr>
            <th class="col-menu">เมนู</th>
            <?php foreach ($perm_roles as $rcode => $rname): ?>
            <th>
                <span class="perm-badge-role role-<?= htmlspecialchars($rcode) ?>">
                    <?= htmlspecialchars($rcode) ?>
                </span><br>
                <span style="font-size:11px;font-weight:normal;color:#aaa;"><?= htmlspecialchars($rname) ?></span>
            </th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
    <?php
    // render root menus first, then children
    $root_menus = array_filter($perm_menus, fn($m) => $m['parent'] === null);
    $child_menus = [];
    foreach ($perm_menus as $m) {
        if ($m['parent'] !== null) $child_menus[$m['parent']][] = $m;
    }
    foreach ($root_menus as $menu):
        $mcode = $menu['code'];
    ?>
    <tr>
        <td class="col-menu">
            <i class="<?= htmlspecialchars($menu['icon']) ?>" style="margin-right:8px;color:#0ff;width:16px;text-align:center;"></i>
            <strong style="color:#e0e0e0;"><?= htmlspecialchars($menu['name']) ?></strong>
            <code style="font-size:11px;color:#555;margin-left:8px;"><?= htmlspecialchars($mcode) ?></code>
        </td>
        <?php foreach ($perm_roles as $rcode => $rname):
            $cv = $perm_matrix[$mcode][$rcode]['view'] ?? 'N';
            $cu = $perm_matrix[$mcode][$rcode]['use']  ?? 'N';
            $val = ($cv==='Y'&&$cu==='Y') ? 'both' : (($cv==='Y') ? 'view' : 'none');
        ?>
        <td>
            <select name="perm[<?= htmlspecialchars($rcode) ?>][<?= htmlspecialchars($mcode) ?>]"
                    class="perm-select val-<?= $val ?>"
                    onchange="this.className='perm-select val-'+this.value">
                <option value="both" <?= $val==='both' ?'selected':'' ?>>✓ เข้าได้</option>
                <option value="view" <?= $val==='view' ?'selected':'' ?>>👁 เห็นเมนู</option>
                <option value="none" <?= $val==='none' ?'selected':'' ?>>✗ ปิด</option>
            </select>
        </td>
        <?php endforeach; ?>
    </tr>
    <?php
    // render children
    if (!empty($child_menus[$mcode])):
        foreach ($child_menus[$mcode] as $child):
            $ccode = $child['code'];
    ?>
    <tr class="sub-row">
        <td class="col-menu">
            <i class="fas fa-angle-right" style="margin-right:4px;color:#555;"></i>
            <i class="<?= htmlspecialchars($child['icon']) ?>" style="margin-right:8px;color:#888;width:14px;text-align:center;"></i>
            <span style="color:#ccc;"><?= htmlspecialchars($child['name']) ?></span>
            <code style="font-size:11px;color:#555;margin-left:8px;"><?= htmlspecialchars($ccode) ?></code>
        </td>
        <?php foreach ($perm_roles as $rcode => $rname):
            $cv = $perm_matrix[$ccode][$rcode]['view'] ?? 'N';
            $cu = $perm_matrix[$ccode][$rcode]['use']  ?? 'N';
            $val = ($cv==='Y'&&$cu==='Y') ? 'both' : (($cv==='Y') ? 'view' : 'none');
        ?>
        <td>
            <select name="perm[<?= htmlspecialchars($rcode) ?>][<?= htmlspecialchars($ccode) ?>]"
                    class="perm-select val-<?= $val ?>"
                    onchange="this.className='perm-select val-'+this.value">
                <option value="both" <?= $val==='both' ?'selected':'' ?>>✓ เข้าได้</option>
                <option value="view" <?= $val==='view' ?'selected':'' ?>>👁 เห็นเมนู</option>
                <option value="none" <?= $val==='none' ?'selected':'' ?>>✗ ปิด</option>
            </select>
        </td>
        <?php endforeach; ?>
    </tr>
    <?php endforeach; endif; ?>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<div style="text-align:center;">
    <button type="submit" class="perm-save-btn">
        <i class="fas fa-save" style="margin-right:8px;"></i>บันทึกสิทธิ์ทั้งหมด
    </button>
</div>
</form>

<?php endif; ?>
</div><!-- /tab-perm -->
<?php endif; ?>

<!-- ======== Modal อายุใช้งาน ======== -->
<div class="modal-overlay" id="modal-validity">
    <div class="modal-box" style="max-width:380px;">
        <h3><i class="fas fa-calendar-alt"></i> อายุใช้งาน: <span id="validity-uid" style="color:#ffd700;"></span></h3>
        <form method="POST" id="form-validity">
            <input type="hidden" name="action" value="save_validity">
            <input type="hidden" name="user_id" id="validity-user-id">
            <div style="display:flex;flex-direction:column;gap:14px;margin:16px 0;">
                <div>
                    <label style="color:#0ff;font-size:12px;display:block;margin-bottom:5px;">
                        <i class="fas fa-calendar-plus" style="margin-right:4px;"></i>วันที่เริ่มต้น
                    </label>
                    <input type="text" name="start_date" id="validity-start" placeholder="วว/ดด/ปปปป"
                           maxlength="10" autocomplete="off" readonly
                           style="width:100%;padding:8px 12px;background:#0a1020;border:1px solid #0ff;border-radius:6px;color:#0ff;font-size:13px;cursor:pointer;">
                </div>
                <div>
                    <label style="color:#0ff;font-size:12px;display:block;margin-bottom:5px;">
                        <i class="fas fa-calendar-times" style="margin-right:4px;"></i>วันที่สิ้นสุด
                    </label>
                    <input type="text" name="end_date" id="validity-end" placeholder="วว/ดด/ปปปป"
                           maxlength="10" autocomplete="off" readonly
                           style="width:100%;padding:8px 12px;background:#0a1020;border:1px solid #0ff;border-radius:6px;color:#0ff;font-size:13px;cursor:pointer;">
                </div>
                <div>
                    <label style="color:#ffcc00;font-size:12px;display:block;margin-bottom:5px;">
                        <i class="fas fa-hourglass-half" style="margin-right:4px;"></i>อายุใช้งาน (จำนวนวัน)
                        <span style="color:#aaa;font-weight:normal;"> — กรอกแล้วระบบคำนวณวันหมดอายุให้อัตโนมัติ</span>
                    </label>
                    <input type="number" name="valid_days" id="validity-days" min="0" max="9999"
                           placeholder="เช่น 365"
                           style="width:100%;padding:8px 12px;background:#0a1020;border:1px solid #ffcc00;border-radius:6px;color:#ffcc00;font-size:13px;">
                    <div id="validity-calc-preview" style="margin-top:5px;font-size:12px;color:#00e676;min-height:16px;font-weight:bold;"></div>
                </div>
                <div style="background:rgba(0,255,255,0.04);border-radius:6px;padding:9px;font-size:11px;color:#666;">
                    <i class="fas fa-info-circle" style="color:#0ff;margin-right:4px;"></i>
                    กรอก <strong style="color:#ffcc00;">จำนวนวัน</strong> → วันหมดอายุถูกคำนวณอัตโนมัติ &nbsp;|&nbsp;
                    หรือเลือก <strong style="color:#0ff;">วันที่สิ้นสุด</strong> → จำนวนวันถูกคำนวณให้ &nbsp;|&nbsp;
                    ปล่อยว่างทั้งหมดเพื่อไม่จำกัดอายุ
                </div>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" onclick="closeValidityModal()"
                        style="background:rgba(255,255,255,0.05);color:#aaa;border:1px solid #444;padding:8px 16px;border-radius:6px;cursor:pointer;">ยกเลิก</button>
                <button type="button" onclick="clearValidity()"
                        style="background:rgba(255,68,68,0.12);color:#ff4444;border:1px solid #ff4444;padding:8px 14px;border-radius:6px;cursor:pointer;">
                    <i class="fas fa-eraser"></i> ล้าง</button>
                <button type="submit"
                        style="background:linear-gradient(135deg,#f0c000,#e6a800);color:#000;border:none;padding:8px 18px;border-radius:6px;cursor:pointer;font-weight:bold;">
                    <i class="fas fa-save"></i> บันทึก</button>
            </div>
        </form>
    </div>
</div>
<!-- ======== Modal อายุรหัสผ่าน ======== -->
<div class="modal-overlay" id="modal-pwd-expiry">
    <div class="modal-box" style="max-width:360px;">
        <h3><i class="fas fa-key" style="color:#ce93d8;"></i> อายุรหัสผ่าน: <span id="pwd-expiry-uid" style="color:#ffd700;"></span></h3>
        <form method="POST" id="form-pwd-expiry">
            <input type="hidden" name="action" value="save_pwd_expiry">
            <input type="hidden" name="user_id" id="pwd-expiry-user-id">
            <div style="display:flex;flex-direction:column;gap:14px;margin:16px 0;">
                <div>
                    <label style="color:#aaa;font-size:12px;display:block;margin-bottom:5px;">
                        <i class="fas fa-calendar-check" style="margin-right:4px;color:#ce93d8;"></i>เปลี่ยนรหัสผ่านล่าสุด
                    </label>
                    <div style="padding:8px 12px;background:#0a1020;border:1px solid #444;border-radius:6px;color:#aaa;font-size:13px;" id="pwd-expiry-changed-display">-</div>
                </div>
                <div>
                    <label style="color:#ce93d8;font-size:12px;display:block;margin-bottom:5px;">
                        <i class="fas fa-hourglass-half" style="margin-right:4px;"></i>อายุรหัสผ่าน (จำนวนวัน)
                        <span style="color:#aaa;font-weight:normal;"> — ปล่อยว่าง = default (90 วัน)</span>
                    </label>
                    <input type="number" name="pwd_valid_days" id="pwd-expiry-days" min="1" max="9999"
                           placeholder="เช่น 90, 180, 365"
                           style="width:100%;padding:8px 12px;background:#0a1020;border:1px solid #ab47bc;border-radius:6px;color:#ce93d8;font-size:13px;">
                    <div id="pwd-expiry-preview" style="margin-top:5px;font-size:12px;color:#00e676;min-height:16px;font-weight:bold;"></div>
                </div>
                <div style="background:rgba(171,71,188,0.06);border-radius:6px;padding:9px;font-size:11px;color:#888;">
                    <i class="fas fa-info-circle" style="color:#ce93d8;margin-right:4px;"></i>
                    เตือนเมื่อเหลือ &le; 15 วัน &nbsp;|&nbsp; บังคับเปลี่ยนเมื่อหมดอายุ
                </div>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" onclick="closePwdExpiryModal()"
                        style="background:rgba(255,255,255,0.05);color:#aaa;border:1px solid #444;padding:8px 16px;border-radius:6px;cursor:pointer;">ยกเลิก</button>
                <button type="button" onclick="clearPwdExpiry()"
                        style="background:rgba(255,68,68,0.12);color:#ff4444;border:1px solid #ff4444;padding:8px 14px;border-radius:6px;cursor:pointer;">
                    <i class="fas fa-eraser"></i> ล้าง</button>
                <button type="submit"
                        style="background:linear-gradient(135deg,#7b1fa2,#ab47bc);color:#fff;border:none;padding:8px 18px;border-radius:6px;cursor:pointer;font-weight:bold;">
                    <i class="fas fa-save"></i> บันทึก</button>
            </div>
        </form>
    </div>
</div>

</body>
</html>