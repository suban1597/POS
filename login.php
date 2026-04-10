<?php
// ============================================================
//  login.php — Login + Register Page  –  POS.SK_WEB_USER
// ============================================================
session_start();

// ============================================================
//  AJAX: lookup ชื่อพนักงานจาก POS.SK_USER
// ============================================================
if (isset($_GET['lookup'])) {
    header('Content-Type: application/json; charset=utf-8');
    $uid = strtoupper(trim($_GET['uid'] ?? ''));
    if ($uid === '') { echo json_encode(['ok'=>false]); exit; }

    $lib  = "/opt/oracle/instantclient_21_4";
    $ou   = "system";
    $op   = "system";
    $otns = "CUBACKUP";
    $safe_uid = str_replace("'", "", $uid);

    $sql = <<<SQL
SET ECHO OFF
SET FEEDBACK OFF
SET HEADING OFF
SET VERIFY OFF
SET PAGESIZE 0
SET TRIMSPOOL ON
SET SERVEROUTPUT ON SIZE 1000000
ALTER SESSION SET NLS_LANGUAGE = American;
DECLARE
    v_name VARCHAR2(200);
BEGIN
    SELECT NVL(TRIM(TNAME), NVL(TRIM(ENAME), TRIM(SK_USER_ID)))
    INTO v_name
    FROM POS.SK_USER
    WHERE UPPER(TRIM(SK_USER_ID)) = UPPER('$safe_uid')
    AND TRIM(ACTIVE) = 'Y'
    AND ROWNUM = 1;
    DBMS_OUTPUT.PUT_LINE('FOUND:'||v_name);
EXCEPTION
    WHEN NO_DATA_FOUND THEN DBMS_OUTPUT.PUT_LINE('NOTFOUND');
    WHEN OTHERS THEN DBMS_OUTPUT.PUT_LINE('ERROR:'||SQLERRM);
END;
/
EXIT;
SQL;
    $out = run_sql($sql, $lib, $ou, $op, $otns);
    if (strpos($out, 'FOUND:') !== false) {
        preg_match('/FOUND:(.+)/', $out, $m);
        echo json_encode(['ok'=>true, 'name'=>trim($m[1] ?? '')]);
    } else {
        echo json_encode(['ok'=>false]);
    }
    exit;
}

if (!empty($_SESSION['pos_user'])) {
    header('Location: POS_HOME.php');
    exit;
}

$instant_client_path = "/opt/oracle/instantclient_21_4";
$oracle_user         = "system";
$oracle_pass         = "system";
$oracle_tns          = "CUBACKUP";

$login_error    = '';
$reg_error      = '';
$reg_success    = '';
$reset_error    = '';
$reset_success  = '';
$reset_user_ctx = '';
$mode           = $_GET['mode'] ?? 'login';

if (isset($_GET['timeout'])) {
    $login_error = 'Session หมดอายุ กรุณาเข้าสู่ระบบใหม่';
}

// ============================================================
//  Helper: run sqlplus
// ============================================================
function run_sql(string $sql, string $lib, string $user, string $pass, string $tns): string {
    $sqlplus = rtrim($lib, '/') . '/sqlplus';
    if (!is_executable($sqlplus)) return 'ERROR:sqlplus not found';
    $tmp = sys_get_temp_dir() . '/POS_' . uniqid() . '.sql';
    file_put_contents($tmp, $sql);
    $up  = escapeshellarg("{$user}/{$pass}@{$tns}");
    $cmd = "env -i LD_LIBRARY_PATH={$lib} TNS_ADMIN={$lib} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus} -s {$up} @{$tmp} 2>&1";
    $out = (string) shell_exec($cmd);
    @unlink($tmp);
    return trim($out);
}

// ============================================================
//  HANDLE LOGIN
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $mode = 'login';

    if ($username === '' || $password === '') {
        $login_error = 'กรุณากรอก Username และ Password';
    } else {
        $safe_user   = str_replace("'", "", $username);
        $hashed_pass = hash('sha256', $password); // hash ที่ PHP ก่อนส่ง Oracle

        $sql = <<<SQL
SET ECHO OFF
SET FEEDBACK OFF
SET HEADING OFF
SET VERIFY OFF
SET PAGESIZE 0
SET TRIMSPOOL ON
SET SERVEROUTPUT ON SIZE 1000000
ALTER SESSION SET NLS_LANGUAGE = American;
DECLARE
    v_cnt_pwd    NUMBER := 0;
    v_cnt_active NUMBER := 0;
    v_pri        CHAR(1);
BEGIN
    -- เช็ครหัสผ่านถูกต้องก่อน (ไม่สนใจ ACTIVE)
    SELECT COUNT(*) INTO v_cnt_pwd
    FROM POS.SK_WEB_USER
    WHERE UPPER(TRIM(USER_ID)) = UPPER('$safe_user')
      AND PASSWORD = '$hashed_pass';

    IF v_cnt_pwd = 0 THEN
        -- รหัสผ่านผิด หรือ user ไม่มีในระบบ
        DBMS_OUTPUT.PUT_LINE('LOGIN_FAIL');
    ELSE
        -- รหัสผ่านถูก -> เช็ค ACTIVE
        SELECT COUNT(*) INTO v_cnt_active
        FROM POS.SK_WEB_USER
        WHERE UPPER(TRIM(USER_ID)) = UPPER('$safe_user')
          AND PASSWORD = '$hashed_pass'
          AND ACTIVE   = 'Y';

        IF v_cnt_active > 0 THEN
            SELECT PRIORITY INTO v_pri FROM POS.SK_WEB_USER
            WHERE UPPER(TRIM(USER_ID)) = UPPER('$safe_user') AND ROWNUM=1;
            DBMS_OUTPUT.PUT_LINE('LOGIN_OK');
            DBMS_OUTPUT.PUT_LINE('PRIORITY:'||v_pri);
        ELSE
            -- รหัสผ่านถูก แต่ ACTIVE=N (รออนุมัติ)
            DBMS_OUTPUT.PUT_LINE('LOGIN_INACTIVE');
        END IF;
    END IF;
EXCEPTION
    WHEN OTHERS THEN DBMS_OUTPUT.PUT_LINE('LOGIN_ERROR:'||SQLERRM);
END;
/
EXIT;
SQL;
        $result = run_sql($sql, $instant_client_path, $oracle_user, $oracle_pass, $oracle_tns);

        if (strpos($result, 'LOGIN_OK') !== false) {
            // ดึง PRIORITY
            preg_match('/PRIORITY:(\w+)/', $result, $pm);

            // ── ตรวจสอบวันหมดอายุบัญชี + รหัสผ่าน (query เดียว) ──────────
            $safe_u2    = str_replace("'", "''", $safe_user);
            $exp_sql    = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 300 TRIMSPOOL ON\n"
                        . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
                        . "SELECT NVL(TO_CHAR(END_DATE,'DD/MM/YYYY'),'')"
                        . "||'|'||NVL(TO_CHAR(PWD_END_DATE,'DD/MM/YYYY'),'')\n"
                        . "FROM POS.SK_WEB_USER\n"
                        . "WHERE UPPER(TRIM(USER_ID))=UPPER('{$safe_u2}') AND ROWNUM=1;\nEXIT;\n";
            $exp_result = run_sql($exp_sql, $instant_client_path, $oracle_user, $oracle_pass, $oracle_tns);

            $acc_end_str = '';
            $pwd_end_str = '';
            foreach (explode("\n", $exp_result) as $_el) {
                $_el = trim($_el);
                if ($_el === '' || preg_match('/^(ORA-|SP2-)/', $_el)) continue;
                $ep = explode('|', $_el, 2);
                $acc_end_str = trim($ep[0]);
                $pwd_end_str = isset($ep[1]) ? trim($ep[1]) : '';
                break;
            }
            $today = new DateTime('today');

            // 1) ตรวจวันหมดอายุบัญชี (END_DATE) — บล็อก login ถ้าหมดแล้ว
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $acc_end_str)) {
                $acc_dt   = DateTime::createFromFormat('d/m/Y', $acc_end_str);
                $acc_diff = $acc_dt ? (int)$today->diff($acc_dt)->format('%r%a') : 1;
                if ($acc_diff < 0) {
                    $login_error = 'EXPIRED_ACCOUNT:' . $acc_end_str;
                    goto end_login;
                }
            }

            // 2) ตรวจวันหมดอายุรหัสผ่าน (PWD_END_DATE) — บล็อก login ถ้าหมดแล้ว
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $pwd_end_str)) {
                $pwd_dt   = DateTime::createFromFormat('d/m/Y', $pwd_end_str);
                $pwd_diff = $pwd_dt ? (int)$today->diff($pwd_dt)->format('%r%a') : 1;
                if ($pwd_diff <= 0) {
                    $login_error = 'EXPIRED_PASSWORD:' . $pwd_end_str;
                    goto end_login;
                }
            }

            // 3) ตั้งค่า session หลังผ่านการตรวจทั้งหมด
            $_SESSION['pos_user']     = strtoupper($safe_user);
            $_SESSION['pos_priority'] = $pm[1] ?? 'U';
            $_SESSION['pos_login_ts'] = time();

            // เก็บสถานะเตือนรหัสผ่านใกล้หมดอายุ (warning เท่านั้น ไม่ใช่ expired)
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $pwd_end_str)) {
                $pwd_dt   = DateTime::createFromFormat('d/m/Y', $pwd_end_str);
                $pwd_diff = $pwd_dt ? (int)$today->diff($pwd_dt)->format('%r%a') : 999;
                if ($pwd_diff <= 15) {
                    $_SESSION['pos_pwd_days_left'] = $pwd_diff;
                    $_SESSION['pos_pwd_status']    = 'warning';
                    $_SESSION['pos_pwd_end_date']  = $pwd_end_str;
                }
            }

            header('Location: POS_HOME.php');
            exit;

            end_login:
        } elseif (strpos($result, 'LOGIN_INACTIVE') !== false) {
            $login_error = 'INACTIVE:' . $safe_user;
        } elseif (strpos($result, 'LOGIN_ERROR:') !== false || preg_match('/^(ORA-|SP2-)/', $result)) {
            $login_error = 'Oracle Error: ' . htmlspecialchars($result);
        } else {
            $login_error = 'Username หรือ Password ไม่ถูกต้อง';
        }
    }
}

// ============================================================
//  HANDLE RESET PASSWORD (รหัสผ่านหมดอายุ)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_pwd') {
    $mode           = 'login';
    $reset_user_ctx = strtoupper(trim($_POST['reset_username'] ?? ''));
    $reset_old      = trim($_POST['reset_oldpass']  ?? '');
    $reset_new      = trim($_POST['reset_newpass']  ?? '');
    $reset_cfm      = trim($_POST['reset_confirm']  ?? '');
    $reset_exp_date = trim($_POST['reset_exp_date'] ?? '');

    if ($reset_user_ctx === '' || $reset_old === '' || $reset_new === '') {
        $reset_error    = 'กรุณากรอกข้อมูลให้ครบถ้วน';
        $login_error    = 'EXPIRED_PASSWORD:' . $reset_exp_date;
    } elseif (strlen($reset_new) < 4) {
        $reset_error    = 'รหัสผ่านใหม่ต้องมีอย่างน้อย 4 ตัวอักษร';
        $login_error    = 'EXPIRED_PASSWORD:' . $reset_exp_date;
    } elseif ($reset_new !== $reset_cfm) {
        $reset_error    = 'รหัสผ่านใหม่และยืนยันไม่ตรงกัน';
        $login_error    = 'EXPIRED_PASSWORD:' . $reset_exp_date;
    } elseif ($reset_old === $reset_new) {
        $reset_error    = 'รหัสผ่านใหม่ต้องไม่ซ้ำกับรหัสผ่านเดิม';
        $login_error    = 'EXPIRED_PASSWORD:' . $reset_exp_date;
    } else {
        $safe_ru     = str_replace("'", "", $reset_user_ctx);
        $hashed_old  = hash('sha256', $reset_old);
        $hashed_new  = hash('sha256', $reset_new);

        $sql = <<<SQL
SET ECHO OFF
SET FEEDBACK OFF
SET HEADING OFF
SET VERIFY OFF
SET PAGESIZE 0
SET TRIMSPOOL ON
SET SERVEROUTPUT ON SIZE 1000000
ALTER SESSION SET NLS_LANGUAGE = American;
DECLARE
    v_cnt NUMBER := 0;
BEGIN
    -- ตรวจว่ารหัสผ่านเดิมถูกต้อง
    SELECT COUNT(*) INTO v_cnt
    FROM POS.SK_WEB_USER
    WHERE UPPER(TRIM(USER_ID)) = UPPER('$safe_ru')
      AND PASSWORD = '$hashed_old';

    IF v_cnt = 0 THEN
        DBMS_OUTPUT.PUT_LINE('RESET_WRONG_OLD');
    ELSE
        UPDATE POS.SK_WEB_USER
        SET PASSWORD      = '$hashed_new',
            PWD_START_DATE = TRUNC(SYSDATE),
            PWD_END_DATE   = TRUNC(SYSDATE) + NVL(VALID_DAYS, 90)
        WHERE UPPER(TRIM(USER_ID)) = UPPER('$safe_ru');
        COMMIT;
        DBMS_OUTPUT.PUT_LINE('RESET_OK');
    END IF;
EXCEPTION
    WHEN OTHERS THEN
        ROLLBACK;
        DBMS_OUTPUT.PUT_LINE('RESET_ERROR:'||SQLERRM);
END;
/
EXIT;
SQL;
        $result = run_sql($sql, $instant_client_path, $oracle_user, $oracle_pass, $oracle_tns);

        if (strpos($result, 'RESET_OK') !== false) {
            $reset_success  = 'เปลี่ยนรหัสผ่านสำเร็จ กรุณาเข้าสู่ระบบด้วยรหัสผ่านใหม่';
            $reset_user_ctx = '';
        } elseif (strpos($result, 'RESET_WRONG_OLD') !== false) {
            $reset_error    = 'รหัสผ่านเดิมไม่ถูกต้อง';
            $login_error    = 'EXPIRED_PASSWORD:' . $reset_exp_date;
        } elseif (strpos($result, 'RESET_ERROR:') !== false || preg_match('/^(ORA-|SP2-)/', $result)) {
            $reset_error    = 'Oracle Error: ' . htmlspecialchars($result);
            $login_error    = 'EXPIRED_PASSWORD:' . $reset_exp_date;
        } else {
            $reset_error    = 'เกิดข้อผิดพลาด กรุณาลองใหม่';
            $login_error    = 'EXPIRED_PASSWORD:' . $reset_exp_date;
        }
    }
}

// ============================================================
//  HANDLE REGISTER
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register') {
    $mode     = 'register';
    $reg_user = strtoupper(trim($_POST['reg_username'] ?? ''));
    $reg_pass = trim($_POST['reg_password'] ?? '');
    $reg_cfm  = trim($_POST['reg_confirm']  ?? '');
    $reg_name = trim($_POST['reg_fullname'] ?? '');

    if ($reg_user === '' || $reg_pass === '' || $reg_name === '') {
        $reg_error = 'กรุณากรอกข้อมูลให้ครบถ้วน';
    } elseif (strlen($reg_pass) < 4) {
        $reg_error = 'Password ต้องมีอย่างน้อย 4 ตัวอักษร';
    } elseif ($reg_pass !== $reg_cfm) {
        $reg_error = 'Password และ Confirm Password ไม่ตรงกัน';
    } elseif (!preg_match('/^[A-Z0-9_]{1,20}$/', $reg_user)) {
        $reg_error = 'Username ใช้ได้เฉพาะ A-Z, 0-9, _ และไม่เกิน 20 ตัวอักษร';
    } else {
        $safe_user = str_replace("'", "", $reg_user);
        $safe_name = str_replace("'", "", $reg_name);
        $safe_prio = in_array($_POST['reg_priority'] ?? 'U', ['A','M','U']) ? $_POST['reg_priority'] : 'U';
        $hashed_pass = hash('sha256', $reg_pass); // hash ที่ PHP ก่อนส่ง Oracle

        $sql = <<<SQL
SET ECHO OFF
SET FEEDBACK OFF
SET HEADING OFF
SET VERIFY OFF
SET PAGESIZE 0
SET TRIMSPOOL ON
SET SERVEROUTPUT ON SIZE 1000000
ALTER SESSION SET NLS_LANGUAGE = American;
DECLARE
    v_cnt NUMBER := 0;
BEGIN
    SELECT COUNT(*) INTO v_cnt
    FROM POS.SK_WEB_USER
    WHERE UPPER(TRIM(USER_ID)) = UPPER('$safe_user');

    IF v_cnt > 0 THEN
        DBMS_OUTPUT.PUT_LINE('REG_DUPLICATE');
    ELSE
        INSERT INTO POS.SK_WEB_USER (USER_ID, PASSWORD, FULL_NAME, ACTIVE, PRIORITY, VALID_DAYS, PWD_START_DATE, PWD_END_DATE)
        VALUES ('$safe_user', '$hashed_pass', '$safe_name', 'N', '$safe_prio', 90, TRUNC(SYSDATE), TRUNC(SYSDATE) + 90);
        COMMIT;
        DBMS_OUTPUT.PUT_LINE('REG_OK');
    END IF;
EXCEPTION
    WHEN OTHERS THEN
        ROLLBACK;
        DBMS_OUTPUT.PUT_LINE('REG_ERROR:'||SQLERRM);
END;
/
EXIT;
SQL;
        $result = run_sql($sql, $instant_client_path, $oracle_user, $oracle_pass, $oracle_tns);

        if (strpos($result, 'REG_OK') !== false) {
            $reg_success = "ลงทะเบียน <strong>{$reg_user}</strong> สำเร็จ กรุณาเข้าสู่ระบบ";
            $mode = 'login';
        } elseif (strpos($result, 'REG_DUPLICATE') !== false) {
            $reg_error = "Username '{$reg_user}' มีอยู่แล้ว กรุณาใช้ชื่ออื่น";
        } elseif (strpos($result, 'REG_ERROR:') !== false || preg_match('/^(ORA-|SP2-)/', $result)) {
            $reg_error = 'Oracle Error: ' . htmlspecialchars($result);
        } else {
            $reg_error = 'เกิดข้อผิดพลาด กรุณาลองใหม่';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>POS – เข้าสู่ระบบ</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: "Consolas","Tahoma",sans-serif;
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
}
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background:
        radial-gradient(ellipse at 20% 50%, rgba(0,255,255,0.05) 0%, transparent 60%),
        radial-gradient(ellipse at 80% 20%, rgba(0,150,167,0.08) 0%, transparent 50%);
    pointer-events: none;
}
.login-card {
    background: rgba(0,0,0,0.55);
    border: 2px solid #0ff;
    border-radius: 18px;
    padding: 40px 44px 36px;
    width: 100%;
    max-width: 460px;
    box-shadow: 0 0 60px rgba(0,255,255,0.2), 0 0 120px rgba(0,188,212,0.1);
    animation: fadeInUp 0.5s ease;
    position: relative;
    overflow: hidden;
}
@keyframes fadeInUp {
    from { opacity:0; transform:translateY(30px); }
    to   { opacity:1; transform:translateY(0); }
}
.login-card::after {
    content: '';
    position: absolute;
    left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, rgba(0,255,255,0.4), transparent);
    animation: scanline 3s linear infinite;
    pointer-events: none;
}
@keyframes scanline {
    0%   { top:0;    opacity:0; }
    10%  { opacity:1; }
    90%  { opacity:1; }
    100% { top:100%; opacity:0; }
}
.tabs {
    display: flex;
    margin-bottom: 24px;
    border-radius: 8px;
    overflow: hidden;
    border: 2px solid #0a4a4a;
}
.tab-btn {
    flex: 1;
    padding: 11px;
    background: transparent;
    border: none;
    color: #2a8a8a;
    font-size: 14px;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.3s;
    letter-spacing: 1px;
    font-family: Consolas, monospace;
}
.tab-btn.active {
    background: linear-gradient(135deg, #00bcd4, #0097a7);
    color: #fff;
    box-shadow: 0 0 14px rgba(0,188,212,0.4);
}
.tab-btn:hover:not(.active) {
    background: rgba(0,255,255,0.08);
    color: #0ff;
}
.login-logo {
    text-align: center;
    margin-bottom: 22px;
}
.login-logo i {
    font-size: 44px;
    color: #0f0;
    text-shadow: 0 0 24px rgba(0,255,0,0.7);
    display: block;
    margin-bottom: 8px;
}
.login-logo h1 {
    color: #00ffff;
    font-size: 24px;
    text-shadow: 0 0 16px rgba(0,255,255,0.5);
    letter-spacing: 3px;
}
.login-logo p { color:#aaa; font-size:12px; margin-top:4px; letter-spacing:1px; }
.form-group { margin-bottom: 16px; position: relative; }
.form-group label {
    display: block;
    color: #0ff;
    font-size: 12px;
    font-weight: bold;
    margin-bottom: 6px;
    letter-spacing: 1px;
    text-transform: uppercase;
}
.input-wrapper { position: relative; }
.input-wrapper i.icon {
    position: absolute;
    left: 13px;
    top: 50%;
    transform: translateY(-50%);
    color: #0ff;
    font-size: 14px;
    opacity: 0.7;
}
.form-group input {
    width: 100%;
    padding: 11px 13px 11px 40px;
    background: rgba(0,10,20,0.8);
    border: 2px solid #0a4a4a;
    border-radius: 8px;
    color: #0ff;
    font-family: Consolas, monospace;
    font-size: 14px;
    transition: all 0.3s;
    outline: none;
}
.form-group input:focus {
    border-color: #0ff;
    box-shadow: 0 0 14px rgba(0,255,255,0.3);
}
.form-group input::placeholder { color: #2a6a6a; }
.toggle-pass {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #0ff;
    cursor: pointer;
    font-size: 14px;
    opacity: 0.6;
    transition: opacity 0.2s;
    background: none;
    border: none;
    padding: 0;
}
.toggle-pass:hover { opacity: 1; }
.msg {
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 13px;
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
}
.msg-error  { background:rgba(255,0,0,0.12); border:2px solid #ff4444; color:#ff6b6b; }
.msg-pending { border-color: #ff9800; color: #ffcc00; background: rgba(255,152,0,0.08); }
.msg-success{ background:rgba(0,255,0,0.08); border:2px solid #00cc44; color:#00ff66; }
.msg i { font-size: 15px; flex-shrink: 0; margin-top: 1px; }
.btn-submit {
    width: 100%;
    padding: 12px;
    background: linear-gradient(135deg, #00bcd4 0%, #0097a7 100%);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 15px;
    font-weight: bold;
    cursor: pointer;
    letter-spacing: 1px;
    transition: all 0.3s;
    box-shadow: 0 4px 18px rgba(0,188,212,0.35);
    margin-top: 4px;
}
.btn-submit:hover {
    background: linear-gradient(135deg, #00e5ff 0%, #00bcd4 100%);
    transform: translateY(-2px);
    box-shadow: 0 8px 28px rgba(0,188,212,0.55);
}
.btn-submit:active { transform: translateY(0); }
.btn-submit i { margin-right: 8px; }
.btn-register { background: linear-gradient(135deg, #00c853, #00796b); }
.btn-register:hover { background: linear-gradient(135deg, #69f0ae, #00c853); }
.panel { display: none; }
.panel.active { display: block; }
.login-footer { text-align:center; margin-top:20px; color:#3a6a6a; font-size:12px; }
.login-footer span { color:#0a7a7a; }

/* ── Animated Key Icon ─────────────────────────────────────── */
@keyframes keyShake {
    0%,100% { transform: rotate(0deg)   scale(1);   }
    10%      { transform: rotate(-22deg) scale(1.08); }
    20%      { transform: rotate(18deg)  scale(1.08); }
    30%      { transform: rotate(-16deg) scale(1.05); }
    40%      { transform: rotate(14deg)  scale(1.05); }
    50%      { transform: rotate(-10deg) scale(1.02); }
    60%      { transform: rotate(8deg)   scale(1.02); }
    70%      { transform: rotate(-4deg)  scale(1);   }
    80%      { transform: rotate(2deg)   scale(1);   }
}
@keyframes keyGlow {
    0%,100% { text-shadow: 0 0 8px  rgba(255,111,0,0.6), 0 0 20px rgba(255,68,0,0.3); }
    50%      { text-shadow: 0 0 20px rgba(255,111,0,1),   0 0 40px rgba(255,68,0,0.7), 0 0 60px rgba(255,180,0,0.4); }
}
@keyframes keyBob {
    0%,100% { transform: translateY(0);  }
    50%      { transform: translateY(-5px); }
}
.icon-key-anim {
    display: inline-block;
    font-size: 38px;
    color: #ff6f00;
    animation: keyShake 2.4s ease-in-out infinite,
               keyGlow  2.4s ease-in-out infinite;
    transform-origin: 60% 60%;
    filter: drop-shadow(0 0 6px rgba(255,111,0,0.5));
}
.icon-key-anim-modal {
    display: inline-block;
    font-size: 42px;
    color: #ff9800;
    animation: keyBob   1.6s ease-in-out infinite,
               keyGlow  1.6s ease-in-out infinite;
    filter: drop-shadow(0 0 8px rgba(255,152,0,0.6));
}

/* ── Animated Lock Icon (บัญชีหมดอายุ) ─────────────────────── */
@keyframes lockBounce {
    0%,100% { transform: translateY(0)    scale(1);    }
    15%      { transform: translateY(-8px) scale(1.06); }
    30%      { transform: translateY(0)    scale(1);    }
    45%      { transform: translateY(-4px) scale(1.03); }
    60%      { transform: translateY(0)    scale(1);    }
}
@keyframes lockShiver {
    0%,100% { transform: rotate(0deg);   }
    20%      { transform: rotate(-8deg);  }
    40%      { transform: rotate(8deg);   }
    60%      { transform: rotate(-5deg);  }
    80%      { transform: rotate(5deg);   }
}
@keyframes lockGlow {
    0%,100% { text-shadow: 0 0 8px  rgba(255,68,68,0.6),  0 0 18px rgba(180,0,0,0.3); }
    50%      { text-shadow: 0 0 22px rgba(255,68,68,1),    0 0 44px rgba(220,0,0,0.7), 0 0 66px rgba(255,100,100,0.3); }
}
.icon-lock-anim {
    display: inline-block;
    font-size: 38px;
    color: #ff4444;
    animation: lockBounce 2.8s ease-in-out infinite,
               lockGlow   2.8s ease-in-out infinite;
    filter: drop-shadow(0 0 6px rgba(255,68,68,0.5));
}
</style>
</head>
<body>
<div class="login-card">

    <div class="login-logo">
        <i class="fas fa-cash-register"></i>
        <h1>POS SYSTEM</h1>
        <p>Point of Sale Management</p>
    </div>

    <div class="tabs">
        <button class="tab-btn <?= $mode==='login' ? 'active' : '' ?>" onclick="switchTab('login')">
            <i class="fas fa-sign-in-alt"></i> เข้าสู่ระบบ
        </button>
        <button class="tab-btn <?= $mode==='register' ? 'active' : '' ?>" onclick="switchTab('register')">
            <i class="fas fa-user-plus"></i> ลงทะเบียน
        </button>
    </div>

    <!-- ======== LOGIN PANEL ======== -->
    <div class="panel <?= $mode==='login' ? 'active' : '' ?>" id="panel-login">

        <?php if ($reg_success !== ''): ?>
        <div class="msg msg-success">
            <i class="fas fa-check-circle"></i>
            <span><?= $reg_success ?></span>
        </div>
        <?php endif; ?>

        <?php if ($reset_success !== ''): ?>
        <div class="msg msg-success">
            <i class="fas fa-check-circle"></i>
            <span><?= htmlspecialchars($reset_success) ?></span>
        </div>
        <?php endif; ?>

        <?php if ($login_error !== ''): ?>
        <?php if (strpos($login_error, 'INACTIVE:') === 0):
            $_inactive_user = substr($login_error, 9); ?>
        <div class="msg msg-pending" style="text-align:center;padding:16px 20px;">
            <div style="font-size:32px;margin-bottom:8px;">⏳</div>
            <div style="font-size:15px;font-weight:bold;color:#ffcc00;margin-bottom:4px;">รออนุมัติเข้าใช้งาน</div>
            <div style="font-size:12px;color:#aaa;">บัญชี <strong style="color:#ffcc00;"><?= htmlspecialchars($_inactive_user) ?></strong><br>
            กรุณาติดต่อผู้ดูแลระบบเพื่อขออนุมัติ</div>
        </div>
        <?php elseif (strpos($login_error, 'EXPIRED_ACCOUNT:') === 0):
            $_exp_date = substr($login_error, 16); ?>
        <div class="msg msg-error" style="flex-direction:column;align-items:center;text-align:center;padding:16px 20px;border-color:#ff4444;background:rgba(255,0,0,0.1);">
            <div style="margin-bottom:10px;">
                <i class="fas fa-lock icon-lock-anim"></i>
            </div>
            <div style="font-size:15px;font-weight:bold;color:#ff4444;margin-bottom:4px;">บัญชีหมดอายุการใช้งาน</div>
            <div style="font-size:12px;color:#aaa;">หมดอายุเมื่อ <strong style="color:#ffcc00;"><?= htmlspecialchars($_exp_date) ?></strong><br>
            กรุณาติดต่อผู้ดูแลระบบเพื่อต่ออายุ</div>
        </div>
        <?php elseif (strpos($login_error, 'EXPIRED_PASSWORD:') === 0):
            $_exp_pwd_date  = substr($login_error, 17);
            $_reset_username = htmlspecialchars($reset_user_ctx ?: strtoupper(trim($_POST['username'] ?? ''))); ?>
        <div class="msg msg-error" style="flex-direction:column;align-items:center;text-align:center;padding:16px 20px;border-color:#ff6f00;background:rgba(255,111,0,0.08);">
            <div style="margin-bottom:10px;">
                <i class="fas fa-key icon-key-anim"></i>
            </div>
            <div style="font-size:15px;font-weight:bold;color:#ff4444;margin-bottom:4px;">รหัสผ่านหมดอายุ</div>
            <div style="font-size:12px;color:#aaa;margin-bottom:14px;">รหัสผ่านหมดอายุเมื่อ <strong style="color:#ffcc00;"><?= htmlspecialchars($_exp_pwd_date) ?></strong></div>
            <button type="button" onclick="openResetModal('<?= $_reset_username ?>','<?= htmlspecialchars($_exp_pwd_date) ?>')"
                style="padding:9px 22px;background:linear-gradient(135deg,#ff6f00,#e65100);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:bold;cursor:pointer;letter-spacing:1px;box-shadow:0 4px 14px rgba(255,111,0,0.4);transition:all .3s;">
                <i class="fas fa-key" style="margin-right:6px;"></i>Reset Password
            </button>
        </div>

        <!-- ===== RESET PASSWORD MODAL ===== -->
        <div id="resetModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:9999;align-items:center;justify-content:center;">
            <div style="background:#0d1b2a;border:2px solid #ff6f00;border-radius:16px;padding:32px 36px;width:100%;max-width:400px;box-shadow:0 0 40px rgba(255,111,0,0.3);animation:fadeInUp .3s ease;position:relative;">
                <button onclick="closeResetModal()" style="position:absolute;top:12px;right:14px;background:none;border:none;color:#888;font-size:18px;cursor:pointer;">&times;</button>
                <div style="text-align:center;margin-bottom:20px;">
                    <div style="margin-bottom:8px;">
                        <i class="fas fa-key icon-key-anim-modal"></i>
                    </div>
                    <div style="color:#ff9800;font-size:16px;font-weight:bold;letter-spacing:1px;">Reset Password</div>
                    <div id="resetUserLabel" style="color:#aaa;font-size:12px;margin-top:4px;"></div>
                </div>

                <?php if ($reset_error !== ''): ?>
                <div style="background:rgba(255,0,0,0.12);border:1.5px solid #ff4444;border-radius:8px;padding:9px 12px;font-size:12px;color:#ff6b6b;margin-bottom:14px;text-align:center;">
                    <i class="fas fa-exclamation-triangle" style="margin-right:5px;"></i><?= htmlspecialchars($reset_error) ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="login.php" autocomplete="off">
                    <input type="hidden" name="action"         value="reset_pwd">
                    <input type="hidden" name="reset_username" id="reset_username_field" value="<?= $_reset_username ?>">
                    <input type="hidden" name="reset_exp_date" id="reset_exp_date_field" value="<?= htmlspecialchars($_exp_pwd_date) ?>">

                    <div style="margin-bottom:13px;">
                        <label style="display:block;color:#0ff;font-size:11px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;margin-bottom:5px;">
                            <i class="fas fa-lock" style="margin-right:4px;"></i>รหัสผ่านเดิม
                        </label>
                        <div style="position:relative;">
                            <i class="fas fa-lock" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#0ff;opacity:.6;font-size:13px;"></i>
                            <input type="password" name="reset_oldpass" id="reset_oldpass"
                                   placeholder="รหัสผ่านเดิมของคุณ"
                                   style="width:100%;padding:10px 38px 10px 38px;background:rgba(0,10,20,.8);border:2px solid #2a4a4a;border-radius:8px;color:#0ff;font-family:Consolas,monospace;font-size:13px;outline:none;" required>
                            <button type="button" onclick="togglePass('reset_oldpass','eye-old')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:#0ff;opacity:.6;cursor:pointer;">
                                <i class="fas fa-eye" id="eye-old"></i>
                            </button>
                        </div>
                    </div>

                    <div style="margin-bottom:13px;">
                        <label style="display:block;color:#ff9800;font-size:11px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;margin-bottom:5px;">
                            <i class="fas fa-key" style="margin-right:4px;"></i>รหัสผ่านใหม่
                        </label>
                        <div style="position:relative;">
                            <i class="fas fa-key" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#ff9800;opacity:.7;font-size:13px;"></i>
                            <input type="password" name="reset_newpass" id="reset_newpass"
                                   placeholder="อย่างน้อย 4 ตัวอักษร"
                                   style="width:100%;padding:10px 38px 10px 38px;background:rgba(0,10,20,.8);border:2px solid #3a2a00;border-radius:8px;color:#ff9800;font-family:Consolas,monospace;font-size:13px;outline:none;" required>
                            <button type="button" onclick="togglePass('reset_newpass','eye-new')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:#ff9800;opacity:.6;cursor:pointer;">
                                <i class="fas fa-eye" id="eye-new"></i>
                            </button>
                        </div>
                    </div>

                    <div style="margin-bottom:20px;">
                        <label style="display:block;color:#ff9800;font-size:11px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;margin-bottom:5px;">
                            <i class="fas fa-check-double" style="margin-right:4px;"></i>ยืนยันรหัสผ่านใหม่
                        </label>
                        <div style="position:relative;">
                            <i class="fas fa-check-double" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#ff9800;opacity:.7;font-size:12px;"></i>
                            <input type="password" name="reset_confirm" id="reset_confirm"
                                   placeholder="พิมพ์รหัสผ่านใหม่อีกครั้ง"
                                   style="width:100%;padding:10px 38px 10px 38px;background:rgba(0,10,20,.8);border:2px solid #3a2a00;border-radius:8px;color:#ff9800;font-family:Consolas,monospace;font-size:13px;outline:none;" required>
                            <button type="button" onclick="togglePass('reset_confirm','eye-cfm')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:#ff9800;opacity:.6;cursor:pointer;">
                                <i class="fas fa-eye" id="eye-cfm"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit"
                        style="width:100%;padding:11px;background:linear-gradient(135deg,#ff6f00,#e65100);color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:bold;cursor:pointer;letter-spacing:1px;box-shadow:0 4px 18px rgba(255,111,0,0.4);">
                        <i class="fas fa-save" style="margin-right:6px;"></i>บันทึกรหัสผ่านใหม่
                    </button>
                </form>
            </div>
        </div>
        <?php else: ?>
        <div class="msg msg-error">
            <i class="fas fa-exclamation-triangle"></i>
            <span><?= htmlspecialchars($login_error) ?></span>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <form method="POST" action="login.php" autocomplete="off">
            <input type="hidden" name="action" value="login">

            <div class="form-group">
                <label><i class="fas fa-user" style="margin-right:5px;"></i>Username</label>
                <div class="input-wrapper">
                    <i class="fas fa-user icon"></i>
                    <input type="text" name="username" id="username"
                           placeholder="กรอก Username"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                           autofocus required>
                </div>
            </div>

            <div class="form-group">
                <label><i class="fas fa-lock" style="margin-right:5px;"></i>Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock icon"></i>
                    <input type="password" name="password" id="password"
                           placeholder="กรอก Password" required>
                    <button type="button" class="toggle-pass" onclick="togglePass('password','eye-login')">
                        <i class="fas fa-eye" id="eye-login"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fas fa-sign-in-alt"></i> เข้าสู่ระบบ
            </button>
        </form>
    </div>

    <!-- ======== REGISTER PANEL ======== -->
    <div class="panel <?= $mode==='register' ? 'active' : '' ?>" id="panel-register">

        <?php if ($reg_error !== ''): ?>
        <div class="msg msg-error">
            <i class="fas fa-exclamation-triangle"></i>
            <span><?= htmlspecialchars($reg_error) ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" action="login.php?mode=register" autocomplete="off">
            <input type="hidden" name="action" value="register">

            <div class="form-group">
                <label><i class="fas fa-user" style="margin-right:5px;"></i>Username</label>
                <div class="input-wrapper">
                    <i class="fas fa-user icon"></i>
                    <input type="text" name="reg_username" id="reg_username"
                           placeholder="ลงทะเบียนด้วยรหัสพนักงานศูนย์หนังสือฯ"
                           value="<?= htmlspecialchars($_POST['reg_username'] ?? '') ?>"
                           maxlength="20" required>
                </div>
            </div>

            <div class="form-group">
                <label><i class="fas fa-id-card" style="margin-right:5px;"></i>ชื่อ-นามสกุล</label>
                <div class="input-wrapper">
                    <i class="fas fa-id-card icon"></i>
                    <input type="text" name="reg_fullname" id="reg_fullname"
                           placeholder="ชื่อ-นามสกุลจะแสดงอัตโนมัติ"
                           value="<?= htmlspecialchars($_POST['reg_fullname'] ?? '') ?>"
                           maxlength="100" readonly
                           style="background:rgba(0,30,10,0.8); cursor:not-allowed;" required>
                </div>
            </div>

            <div class="form-group">
                <label><i class="fas fa-lock" style="margin-right:5px;"></i>Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock icon"></i>
                    <input type="password" name="reg_password" id="reg_password"
                           placeholder="อย่างน้อย 4 ตัวอักษร" required>
                    <button type="button" class="toggle-pass" onclick="togglePass('reg_password','eye-reg1')">
                        <i class="fas fa-eye" id="eye-reg1"></i>
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label><i class="fas fa-lock" style="margin-right:5px;"></i>Confirm Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock icon"></i>
                    <input type="password" name="reg_confirm" id="reg_confirm"
                           placeholder="ยืนยัน Password" required>
                    <button type="button" class="toggle-pass" onclick="togglePass('reg_confirm','eye-reg2')">
                        <i class="fas fa-eye" id="eye-reg2"></i>
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label><i class="fas fa-shield-alt" style="margin-right:5px;"></i>สิทธิ์การใช้งาน</label>
                <div class="input-wrapper">
                    <i class="fas fa-shield-alt icon"></i>
                    <select name="reg_priority" id="reg_priority" style="width:100%;padding:11px 13px 11px 40px;background:rgba(0,10,20,0.8);border:2px solid #0a4a4a;border-radius:8px;color:#0ff;font-family:Consolas,monospace;font-size:14px;outline:none;appearance:none;cursor:pointer;">
                        <option value="U" style="background:#0a0a1a;">User — ดูข้อมูลทั่วไป</option>
                        <option value="M" style="background:#0a0a1a;">Member — ดูข้อมูลสมาชิก</option>
                        <option value="A" style="background:#0a0a1a;">Admin — จัดการระบบ</option>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn-submit btn-register">
                <i class="fas fa-user-plus"></i> ลงทะเบียน
            </button>
        </form>
    </div>

    <div class="login-footer">
        &copy; <?= date('Y') ?> POS &nbsp;|&nbsp; <span>Powered by Oracle DB</span>
    </div>
</div>

<script>
function openResetModal(username, expDate) {
    document.getElementById('reset_username_field').value = username;
    document.getElementById('reset_exp_date_field').value = expDate;
    document.getElementById('resetUserLabel').textContent  = 'บัญชี: ' + username;
    const modal = document.getElementById('resetModal');
    modal.style.display = 'flex';
    setTimeout(() => { const f = document.getElementById('reset_oldpass'); if(f) f.focus(); }, 100);
}
function closeResetModal() {
    document.getElementById('resetModal').style.display = 'none';
}
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeResetModal();
});
<?php if ($reset_error !== ''): ?>
// เปิด modal อัตโนมัติถ้า submit แล้วมี error
window.addEventListener('DOMContentLoaded', () => {
    openResetModal('<?= addslashes($reset_user_ctx) ?>','<?= addslashes(substr($login_error,17)) ?>');
});
<?php endif; ?>
function switchTab(tab) {
    document.getElementById('panel-login').classList.toggle('active', tab === 'login');
    document.getElementById('panel-register').classList.toggle('active', tab === 'register');
    document.querySelectorAll('.tab-btn').forEach((b,i) => {
        b.classList.toggle('active', (i===0 && tab==='login') || (i===1 && tab==='register'));
    });
}
function togglePass(inputId, iconId) {
    const pw = document.getElementById(inputId);
    const ic = document.getElementById(iconId);
    if (pw.type === 'password') {
        pw.type = 'text';
        ic.classList.replace('fa-eye','fa-eye-slash');
    } else {
        pw.type = 'password';
        ic.classList.replace('fa-eye-slash','fa-eye');
    }
}
// Enter navigation – Login
document.getElementById('username').addEventListener('keydown', e => {
    if (e.key==='Enter') { e.preventDefault(); document.getElementById('password').focus(); }
});
document.getElementById('password').addEventListener('keydown', e => {
    if (e.key==='Enter') { e.preventDefault(); e.target.form.submit(); }
});
// Enter navigation – Register
['reg_username','reg_fullname','reg_password','reg_confirm'].forEach((id,i,arr) => {
    document.getElementById(id).addEventListener('keydown', e => {
        if (e.key==='Enter') {
            e.preventDefault();
            if (i < arr.length-1) document.getElementById(arr[i+1]).focus();
            else e.target.form.submit();
        }
    });
});
// Auto uppercase + กรอง Username Register
document.getElementById('reg_username').addEventListener('input', function() {
    const pos = this.selectionStart;
    this.value = this.value.toUpperCase().replace(/[^A-Z0-9_]/g,'');
    this.setSelectionRange(pos,pos);
    // ล้างชื่อเมื่อแก้ไข
    document.getElementById('reg_fullname').value = '';
    document.getElementById('reg_fullname').placeholder = 'ชื่อ-นามสกุลจะแสดงอัตโนมัติ';
    document.getElementById('reg_fullname').style.color = '#0ff';
});

document.getElementById('reg_username').addEventListener('blur', function() {
    const uid = this.value.trim();
    if (!uid) return;
    const nameField = document.getElementById('reg_fullname');
    nameField.placeholder = 'กำลังตรวจสอบ...';
    fetch('login.php?lookup=1&uid=' + encodeURIComponent(uid))
        .then(r => r.json())
        .then(d => {
            if (d.ok && d.name) {
                nameField.value = d.name;
                nameField.style.color = '#00ff66';
                nameField.placeholder = 'ชื่อ-นามสกุลจะแสดงอัตโนมัติ';
            } else {
                nameField.value = '';
                nameField.style.color = '#ff6b6b';
                nameField.placeholder = 'ไม่พบรหัสพนักงาน หรือสถานะไม่ใช้งาน';
            }
        })
        .catch(() => {
            nameField.placeholder = 'ไม่สามารถตรวจสอบได้';
        });
});
</script>
</body>
</html>