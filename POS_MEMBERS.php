<?php
// ============================================================
//  SESSION GUARD – ต้อง Login ผ่าน index.php ก่อน
// ============================================================
ob_start();
session_start();

// ---- Session Timeout: 60 นาที ----
$SESSION_TIMEOUT = 60 * 60; // 3600 วินาที
if (!empty($_SESSION['pos_user'])) {
    if (isset($_SESSION['pos_last_activity']) &&
        (time() - $_SESSION['pos_last_activity']) > $SESSION_TIMEOUT) {
        // หมดเวลา — ล้าง session
        session_unset();
        session_destroy();
        ob_end_clean();
        header('HTTP/1.1 302 Found');
        header('Location: /POS/index.php?timeout=1');
        header('Connection: close');
        exit;
    }
    $_SESSION['pos_last_activity'] = time(); // รีเซ็ตทุกครั้งที่มี activity
}

if (empty($_SESSION['pos_user'])) {
    ob_end_clean();
    header('HTTP/1.1 302 Found');
    header('Location: /POS/index.php');
    header('Connection: close');
    exit;
}
$pos_logged_user = $_SESSION['pos_user'];
$pos_priority    = $_SESSION['pos_priority'] ?? 'U';
$MENU_ACTIVE = 'members';
require_once __DIR__ . '/POS_AUTH.php';
require_once __DIR__ . '/POS_SETTINGS.php';
pos_check_expiry(); // ล็อกถ้าบัญชีหมดอายุ
pos_guard('members');

header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://code.jquery.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://code.jquery.com https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com; img-src 'self' data:; connect-src 'self';");
// ---------------------------
// CASHIER NAMES — โหลดจาก POS.SK_USER
// ---------------------------
function load_cashier_map(string $sqlplus, string $user, string $pass, string $tns, string $lib): array {
    $sql_file = sys_get_temp_dir() . '/POS_USERS_' . uniqid() . '.sql';
    file_put_contents($sql_file,
        "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 300 TRIMSPOOL ON\n" .
        "ALTER SESSION SET NLS_LANGUAGE = American;\n" .
        "SELECT TRIM(SK_USER_ID)||'|'||TRIM(NVL(TNAME,NVL(ENAME,SK_USER_ID)))\n" .
        "FROM POS.SK_USER\n" .
        "WHERE SK_USER_ID IS NOT NULL AND TRIM(SK_USER_ID) IS NOT NULL\n" .
        "ORDER BY SK_USER_ID;\n" .
        "EXIT;\n"
    );
    $cmd = "env -i LD_LIBRARY_PATH={$lib} TNS_ADMIN={$lib} NLS_LANG=THAI_THAILAND.AL32UTF8 " .
           "{$sqlplus} -s " . escapeshellarg("{$user}/{$pass}@{$tns}") . " @{$sql_file} 2>&1";
    $out = shell_exec($cmd);
    @unlink($sql_file);
    $map = [];
    foreach (preg_split('/\r?\n/', (string)$out) as $line) {
        $line = trim($line);
        if ($line === '' || preg_match('/^(ORA-|SP2-)/', $line)) continue;
        $p = explode('|', $line, 2);
        if (count($p) === 2 && $p[0] !== '') $map[trim($p[0])] = trim($p[1]);
    }
    return $map;
}
// เปิดการแสดงผล error ทั้งหมดสำหรับการ debug
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ---------------------------
// CONFIG
// ---------------------------
$instant_client_path = "/opt/oracle/instantclient_21_4";
$oracle_user = "system";
$oracle_pass = "system";
$oracle_tns = "CUBACKUP";
$sql_file = sys_get_temp_dir() . "/POS_MEMBER_" . uniqid() . ".sql";

// ---------------------------
// MEMBER_ID ที่ต้อง EXCLUDE (บัตรระบบ/ทดสอบ) — แก้ที่นี่ที่เดียว
// ---------------------------
$EXCLUDED_MEMBER_IDS = ['6800201000229'];
$_excl_all = array_unique(array_merge(['-', '0000000000000'], $EXCLUDED_MEMBER_IDS));
// Oracle IN-list: ไม่ใส่ '' เพราะ Oracle empty string = NULL → NOT IN(NULL) ทำให้ 0 rows เสมอ
$EXCL_SQL = implode(',', array_map(fn($id) => "'" . str_replace("'","''", $id) . "'", $_excl_all));
// ใช้งาน: AND h.MEMBER_ID IS NOT NULL AND TRIM(h.MEMBER_ID) NOT IN ({$EXCL_SQL})

// ---------------------------
// HANDLE FILTERS
// ---------------------------
$mode = ($_GET['mode'] ?? 'today') === 'history' ? 'history' : 'today';
$_yesterday = date('d/m/Y', strtotime('-1 day'));
$start_date = $_GET['start'] ?? ($mode === 'history' ? $_yesterday : date('d/m/Y'));
$end_date   = $_GET['end']   ?? ($mode === 'history' ? $_yesterday : date('d/m/Y'));
$top_n = max(1, min(1000000, (int)($_GET['top_n'] ?? 100)));
$sort_by = in_array($_GET['sort_by'] ?? '', ['amount', 'slips', 'items', 'last_purchase']) ? $_GET['sort_by'] : 'amount';
$member_id_filter = trim($_GET['member_id_filter'] ?? '');
$min_slips = max(1, (int)($_GET['min_slips'] ?? 1));
$search_type = in_array($_GET['search_type'] ?? '', ['purchase', 'point', 'yearly']) ? $_GET['search_type'] : 'purchase';
$sort_point = in_array($_GET['sort_point'] ?? '', ['balance_desc','balance_asc','member_id','last_date_desc','expiry_asc']) ? $_GET['sort_point'] : 'balance_desc';
$branch_filter = trim($_GET['branch'] ?? '');

// Escape สำหรับ Oracle (เหมือน MEMBER.php)
$escaped_member_id = $member_id_filter !== '' ? strtoupper(str_replace("'", "''", $member_id_filter)) : '';

// Validate dates — ข้ามถ้าเป็นโหมด point
$errors = [];
if ($search_type !== 'point' && $search_type !== 'yearly') {
    $start_ts = DateTime::createFromFormat('d/m/Y', $start_date);
    $end_ts   = DateTime::createFromFormat('d/m/Y', $end_date);
    if (!$start_ts || !$end_ts || $start_ts->format('d/m/Y') !== $start_date || $end_ts->format('d/m/Y') !== $end_date) {
        $errors[] = "รูปแบบวันที่ไม่ถูกต้อง (ใช้ วว/ดด/ปปปป เช่น 12/11/2025)";
    } elseif ($start_ts > $end_ts) {
        $errors[] = "วันที่เริ่มต้องไม่เกินวันที่สิ้นสุด";
    }
}

// ถ้า ajax=1 แต่มี errors → ตอบ JSON
if (isset($_GET["ajax"]) && $_GET["ajax"] === "1" && !empty($errors)) {
    while (ob_get_level() > 0) ob_end_clean();
    ini_set('display_errors', 0);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(["ok" => false, "error" => implode(", ", $errors)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------
// AJAX REQUEST — โหมด POINT: ดึงจาก POS_MEMBER_POINT โดยตรง
// ---------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && $search_type === 'point') {
    while (ob_get_level() > 0) ob_end_clean();
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');
    try {
        $instant_client_path = rtrim($instant_client_path, '/');
        $sqlplus_path = "{$instant_client_path}/sqlplus";
        if (!is_executable($sqlplus_path)) {
            echo json_encode(['error' => "SQL*Plus Not Found: {$sqlplus_path}"]);
            exit;
        }
        $point_sql_file = sys_get_temp_dir() . "/POS_POINT_" . uniqid() . ".sql";
        if ($escaped_member_id !== '') {
            $sql_where_mid = "AND UPPER(TRIM(MEMBER_ID)) LIKE '%'||UPPER('" . $escaped_member_id . "')||'%'";
            $sql_rownum    = '';
        } else {
            $sql_where_mid = '';
            $sql_rownum    = 'AND ROWNUM <= 500';
        }
        // เงื่อนไขกรองสาขา (SALEOFFICE_CODE) สำหรับโหมดแต้มสะสม
        $escaped_branch = str_replace("'", "''", $branch_filter);
        $sql_where_branch = ($branch_filter !== '')
            ? "AND TRIM(SALEOFFICE_CODE) = '" . $escaped_branch . "'"
            : '';
        $sql_order_point = match($sort_point) {
            'balance_asc'    => 'ORDER BY POINT_BALANCE ASC, MEMBER_ID',
            'member_id'      => 'ORDER BY MEMBER_ID, POINT_TYPE_CODE',
            'last_date_desc' => 'ORDER BY LAST_POINT_DATE DESC NULLS LAST, MEMBER_ID',
            'expiry_asc'     => 'ORDER BY EXPIRY_DATE ASC NULLS LAST, MEMBER_ID',
            default          => 'ORDER BY POINT_BALANCE DESC, MEMBER_ID',
        };
        $point_sql_content  = "SET ECHO OFF\n";
        $point_sql_content .= "SET FEEDBACK OFF\n";
        $point_sql_content .= "SET HEADING OFF\n";
        $point_sql_content .= "SET VERIFY OFF\n";
        $point_sql_content .= "SET PAGESIZE 0\n";
        $point_sql_content .= "SET LINESIZE 500\n";
        $point_sql_content .= "SET TRIMSPOOL ON\n";
        $point_sql_content .= "SET SERVEROUTPUT ON SIZE UNLIMITED\n";
        $point_sql_content .= "ALTER SESSION SET NLS_TERRITORY = America;\n";
        $point_sql_content .= "ALTER SESSION SET NLS_LANGUAGE = American;\n";
        $point_sql_content .= "DECLARE\n";
        $point_sql_content .= "    v_cnt    NUMBER := 0;\n";
        $point_sql_content .= "    TYPE t_cur IS REF CURSOR;\n";
        $point_sql_content .= "    c_pt t_cur;\n";
        $point_sql_content .= "    v_site    VARCHAR2(5);\n";
        $point_sql_content .= "    v_mid     VARCHAR2(17);\n";
        $point_sql_content .= "    v_ptcode  VARCHAR2(2);\n";
        $point_sql_content .= "    v_offcode VARCHAR2(5);\n";
        $point_sql_content .= "    v_balance NUMBER;\n";
        $point_sql_content .= "    v_lastval NUMBER;\n";
        $point_sql_content .= "    v_lastdt  DATE;\n";
        $point_sql_content .= "    v_expiry  DATE;\n";
        $point_sql_content .= "    v_bfval   NUMBER;\n";
        $point_sql_content .= "    v_bfdt    DATE;\n";
        $point_sql_content .= "BEGIN\n";
        $point_sql_content .= "    SELECT COUNT(*) INTO v_cnt FROM POS.POS_MEMBER_POINT\n";
        $point_sql_content .= "    WHERE 1=1 {$sql_where_mid} {$sql_where_branch};\n";
        $point_sql_content .= "    DBMS_OUTPUT.PUT_LINE('POINT_COUNT:'||v_cnt);\n";
        $point_sql_content .= "    OPEN c_pt FOR\n";
        $point_sql_content .= "        SELECT SITE, MEMBER_ID, POINT_TYPE_CODE, SALEOFFICE_CODE,\n";
        $point_sql_content .= "               POINT_BALANCE, LAST_POINT_VALUE, LAST_POINT_DATE,\n";
        $point_sql_content .= "               EXPIRY_DATE, POINT_BF_VALUE, POINT_BF_DATE\n";
        $point_sql_content .= "        FROM POS.POS_MEMBER_POINT\n";
        $point_sql_content .= "        WHERE 1=1 {$sql_where_mid} {$sql_where_branch} {$sql_rownum}\n";
        $point_sql_content .= "        {$sql_order_point};\n";
        $point_sql_content .= "    LOOP\n";
        $point_sql_content .= "        FETCH c_pt INTO v_site, v_mid, v_ptcode, v_offcode,\n";
        $point_sql_content .= "                        v_balance, v_lastval, v_lastdt,\n";
        $point_sql_content .= "                        v_expiry, v_bfval, v_bfdt;\n";
        $point_sql_content .= "        EXIT WHEN c_pt%NOTFOUND;\n";
        $point_sql_content .= "        DBMS_OUTPUT.PUT_LINE(\n";
        $point_sql_content .= "            'POINT|'||NVL(TRIM(v_site),'-')||'|'||NVL(TRIM(v_mid),'-')||'|'||\n";
        $point_sql_content .= "            NVL(TRIM(v_ptcode),'-')||'|'||NVL(TRIM(v_offcode),'-')||'|'||\n";
        $point_sql_content .= "            TO_CHAR(NVL(v_balance,0),'FM999999990.00')||'|'||\n";
        $point_sql_content .= "            TO_CHAR(NVL(v_lastval,0),'FM999999990.00')||'|'||\n";
        $point_sql_content .= "            NVL(TO_CHAR(v_lastdt,'DD/MM/YYYY HH24:MI:SS'),'-')||'|'||\n";
        $point_sql_content .= "            NVL(TO_CHAR(v_expiry,'DD/MM/YYYY'),'-')||'|'||\n";
        $point_sql_content .= "            TO_CHAR(NVL(v_bfval,0),'FM999999990.00')||'|'||\n";
        $point_sql_content .= "            NVL(TO_CHAR(v_bfdt,'DD/MM/YYYY'),'-')\n";
        $point_sql_content .= "        );\n";
        $point_sql_content .= "    END LOOP;\n";
        $point_sql_content .= "    CLOSE c_pt;\n";
        $point_sql_content .= "EXCEPTION WHEN OTHERS THEN\n";
        $point_sql_content .= "    DBMS_OUTPUT.PUT_LINE('DEBUG_ERROR:'||SQLERRM);\n";
        $point_sql_content .= "END;\n";
        $point_sql_content .= "/\n";
        $point_sql_content .= "EXIT;\n";
        if (!file_put_contents($point_sql_file, $point_sql_content)) {
            echo json_encode(['error' => 'ไม่สามารถเขียนไฟล์ SQL ชั่วคราวได้']);
            exit;
        }
        $user_pass = escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}");
        $cmd = "env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s $user_pass @$point_sql_file 2>&1";
        $point_out = shell_exec($cmd);
        @unlink($point_sql_file);
        $has_err = false;
        $debug_error = '';
        foreach (preg_split('/\r?\n/', (string)$point_out) as $chk) {
            $chk = trim($chk);
            if ($chk === '') continue;
            if (preg_match('/^(ORA-|SP2-)/', $chk)) { $has_err = true; break; }
            if (strpos($chk, 'DEBUG_ERROR:') === 0) { $debug_error = substr($chk, 12); $has_err = true; break; }
        }
        if ($has_err) {
            echo json_encode(['error' => "SQL Error: " . htmlspecialchars($debug_error ?: substr((string)$point_out, 0, 500))]);
            exit;
        }
        $points = [];
        $debug_count = 0;
        foreach (preg_split('/\r?\n/', (string)$point_out) as $raw) {
            $ln = trim($raw);
            if (strpos($ln, 'POINT_COUNT:') === 0) { $debug_count = (int)substr($ln, 12); continue; }
            if (strpos($ln, 'POINT|') !== 0) continue;
            $parts = explode('|', $ln);
            if (count($parts) < 11) continue;
            $points[] = [
                'site'             => trim($parts[1]),
                'member_id'        => trim($parts[2]),
                'point_type_code'  => trim($parts[3]),
                'saleoffice_code'  => trim($parts[4]),
                'point_balance'    => (float)trim($parts[5]),
                'last_point_value' => (float)trim($parts[6]),
                'last_point_date'  => trim($parts[7]),
                'expiry_date'      => trim($parts[8]),
                'point_bf_value'   => (float)trim($parts[9]),
                'point_bf_date'    => trim($parts[10]),
            ];
        }
        // นับสมาชิกทั้งหมดใน POS_MEMBER_POINT
        $all_member_count_p = 0;
        $up_p = escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}");
        $sql_cnt_p = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON\nALTER SESSION SET NLS_LANGUAGE = American;\nSELECT COUNT(DISTINCT MEMBER_ID) FROM POS.POS_MEMBER_POINT WHERE MEMBER_ID IS NOT NULL AND TRIM(MEMBER_ID) IS NOT NULL;\nEXIT;\n";
        $cnt_file_p = sys_get_temp_dir() . '/POS_CNTP_' . uniqid() . '.sql';
        file_put_contents($cnt_file_p, $sql_cnt_p);
        $cnt_out_p = shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s {$up_p} @{$cnt_file_p} 2>&1");
        @unlink($cnt_file_p);
        foreach (explode("\n", (string)$cnt_out_p) as $cl) {
            $cl = trim($cl); if ($cl !== '' && is_numeric($cl)) { $all_member_count_p = (int)$cl; break; }
        }
        // หาสมาชิกล่าสุด
        $last_created_date = '-'; $last_created_member = '-';
        $sql_last = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 300 TRIMSPOOL ON\nALTER SESSION SET NLS_LANGUAGE = American;\nSELECT TO_CHAR(t.CREATED_DATE,'DD/MM/YYYY HH24:MI:SS'), t.MEMBER_ID FROM (SELECT /*+ INDEX_DESC(mp IDX_MEMBER_POINT_CDATE) */ CREATED_DATE, MEMBER_ID FROM POS.POS_MEMBER_POINT mp WHERE CREATED_DATE IS NOT NULL ORDER BY CREATED_DATE DESC, MEMBER_ID DESC) t WHERE ROWNUM = 1;\nEXIT;\n";
        $tmp_last = sys_get_temp_dir() . '/POS_LAST_' . uniqid() . '.sql';
        file_put_contents($tmp_last, $sql_last);
        $out_last = shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s {$up_p} @{$tmp_last} 2>&1");
        @unlink($tmp_last);
        foreach (explode("\n", (string)$out_last) as $ll) {
            $ll = trim($ll); if ($ll === '' || preg_match('/^(ORA-|SP2-)/', $ll)) continue;
            $lp = preg_split('/\s{2,}/', $ll);
            if (count($lp) >= 2) { $last_created_date = trim($lp[0]); $last_created_member = trim($lp[1]); break; }
        }
        $result = json_encode([
            'search_type'         => 'point',
            'refresh_time'        => date('d/m/Y H:i:s'),
            'member_id_filter'    => $member_id_filter,
            'sort_point'          => $sort_point,
            'total_records'       => count($points),
            'all_member_count'    => $all_member_count_p,
            'last_created_date'   => $last_created_date,
            'last_created_member' => $last_created_member,
            'debug_count'         => $debug_count,
            'points'              => $points,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        echo $result !== false ? $result : json_encode(['error' => 'JSON encode failed']);
    } catch (Throwable $e) {
        echo json_encode(['error' => 'PHP Exception: ' . $e->getMessage()]);
    }
    exit;
}

// ---------------------------
// AJAX REQUEST — โหมดซื้อสินค้าย้อนหลัง (POS_SALE_HD)
// ---------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && $mode === 'history' && $search_type === 'purchase' && empty($errors)) {
    while (ob_get_level() > 0) ob_end_clean();
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');
    $instant_client_path = rtrim($instant_client_path, '/');
    $sqlplus_path = "{$instant_client_path}/sqlplus";
    if (!is_executable($sqlplus_path)) {
        echo json_encode(['error' => "SQL*Plus Not Found: {$sqlplus_path}"]); exit;
    }
    $cashier_map = load_cashier_map($sqlplus_path, $oracle_user, $oracle_pass, $oracle_tns, $instant_client_path);

    // กรองสาขาตามสิทธิ์ USER_BRANCH_ACCESS
    $branch_access_clause = function_exists('pos_branch_sql') ? pos_branch_sql('h.SALE_OFFICE') : '1=1';
    $esc_branch = str_replace("'", "''", $branch_filter);

    $sql_content = <<<SQL
SET ECHO OFF
SET FEEDBACK OFF
SET HEADING OFF
SET VERIFY OFF
SET LINESIZE 500
SET PAGESIZE 0
SET TRIMSPOOL ON
SET SERVEROUTPUT ON SIZE UNLIMITED
ALTER SESSION SET NLS_TERRITORY = America;
ALTER SESSION SET NLS_LANGUAGE = American;
VARIABLE start_date VARCHAR2(10);
VARIABLE end_date VARCHAR2(10);
VARIABLE top_n NUMBER;
VARIABLE sort_by VARCHAR2(15);
VARIABLE member_id_filter VARCHAR2(100);
VARIABLE min_slips NUMBER;
VARIABLE branch_filter VARCHAR2(20);
EXEC :start_date := '$start_date';
EXEC :end_date := '$end_date';
EXEC :top_n := $top_n;
EXEC :sort_by := '$sort_by';
EXEC :member_id_filter := '$escaped_member_id';
EXEC :min_slips := $min_slips;
EXEC :branch_filter := '$esc_branch';
DECLARE
    TYPE t_member_rec IS RECORD (
        member_id VARCHAR2(50), slip_count NUMBER, item_count NUMBER,
        total_amount NUMBER, last_purchase DATE, branches VARCHAR2(4000)
    );
    TYPE t_member_tab IS TABLE OF t_member_rec;
    v_members t_member_tab := t_member_tab();
    v_temp t_member_rec;
    v_start DATE := TO_DATE(:start_date, 'DD/MM/YYYY');
    v_end DATE := TO_DATE(:end_date, 'DD/MM/YYYY') + 1 - 1/86400;
    v_found BOOLEAN;
    v_member_filter VARCHAR2(100) := :member_id_filter;
    v_branch_filter VARCHAR2(20)  := TRIM(:branch_filter);
    TYPE t_mid_tab  IS TABLE OF VARCHAR2(50);
    TYPE t_off_tab  IS TABLE OF VARCHAR2(20);
    TYPE t_num_tab  IS TABLE OF NUMBER;
    TYPE t_dt_tab   IS TABLE OF DATE;
    v_mids   t_mid_tab;
    v_offs   t_off_tab;
    v_amts   t_num_tab;
    v_icnts  t_num_tab;
    v_dates  t_dt_tab;
BEGIN
    IF v_member_filter IS NOT NULL AND LENGTH(TRIM(v_member_filter)) > 0 THEN
        SELECT h.MEMBER_ID, TRIM(h.SALE_OFFICE), h.GRAND_AMOUNT,
               NVL((SELECT COUNT(*) FROM POS.POS_SALE_DT d WHERE d.SLIP_NO=h.SLIP_NO),0),
               h.CREATE_DATE
        BULK COLLECT INTO v_mids, v_offs, v_amts, v_icnts, v_dates
        FROM POS.POS_SALE_HD h
        WHERE h.MEMBER_ID IS NOT NULL AND TRIM(h.MEMBER_ID) IS NOT NULL
          AND h.MEMBER_ID IS NOT NULL AND TRIM(h.MEMBER_ID) NOT IN ({$EXCL_SQL})
          AND h.CREATE_DATE >= v_start AND h.CREATE_DATE < v_end
          AND ({$branch_access_clause})
          AND (v_branch_filter IS NULL OR v_branch_filter = '' OR TRIM(h.SALE_OFFICE) = v_branch_filter)
          AND UPPER(h.MEMBER_ID) LIKE '%'||UPPER(v_member_filter)||'%';
    ELSE
        SELECT h.MEMBER_ID, TRIM(h.SALE_OFFICE), h.GRAND_AMOUNT,
               NVL((SELECT COUNT(*) FROM POS.POS_SALE_DT d WHERE d.SLIP_NO=h.SLIP_NO),0),
               h.CREATE_DATE
        BULK COLLECT INTO v_mids, v_offs, v_amts, v_icnts, v_dates
        FROM POS.POS_SALE_HD h
        WHERE h.MEMBER_ID IS NOT NULL AND TRIM(h.MEMBER_ID) IS NOT NULL
          AND h.MEMBER_ID IS NOT NULL AND TRIM(h.MEMBER_ID) NOT IN ({$EXCL_SQL})
          AND h.CREATE_DATE >= v_start AND h.CREATE_DATE < v_end
          AND ({$branch_access_clause})
          AND (v_branch_filter IS NULL OR v_branch_filter = '' OR TRIM(h.SALE_OFFICE) = v_branch_filter);
    END IF;

    FOR i IN 1..v_mids.COUNT LOOP
        v_found := FALSE;
        FOR j IN 1..v_members.COUNT LOOP
            IF v_members(j).member_id = v_mids(i) THEN
                v_members(j).slip_count   := v_members(j).slip_count + 1;
                v_members(j).item_count   := v_members(j).item_count + v_icnts(i);
                v_members(j).total_amount := v_members(j).total_amount + v_amts(i);
                IF v_members(j).last_purchase IS NULL OR v_dates(i) > v_members(j).last_purchase THEN
                    v_members(j).last_purchase := v_dates(i);
                END IF;
                IF v_offs(i) IS NOT NULL AND INSTR(','||v_members(j).branches||',', ','||v_offs(i)||',')=0 THEN
                    v_members(j).branches := v_members(j).branches||','||v_offs(i);
                END IF;
                v_found := TRUE; EXIT;
            END IF;
        END LOOP;
        IF NOT v_found THEN
            v_temp.member_id    := v_mids(i); v_temp.slip_count   := 1;
            v_temp.item_count   := v_icnts(i); v_temp.total_amount := v_amts(i);
            v_temp.last_purchase := v_dates(i); v_temp.branches := NVL(v_offs(i),'');
            v_members.EXTEND; v_members(v_members.COUNT) := v_temp;
        END IF;
    END LOOP;

    FOR i IN 1..v_members.COUNT-1 LOOP
        FOR j IN i+1..v_members.COUNT LOOP
            DECLARE v_swap BOOLEAN := FALSE; BEGIN
                IF :sort_by='amount' THEN IF NVL(v_members(i).total_amount,0)<NVL(v_members(j).total_amount,0) THEN v_swap:=TRUE; END IF;
                ELSIF :sort_by='slips' THEN IF NVL(v_members(i).slip_count,0)<NVL(v_members(j).slip_count,0) OR (NVL(v_members(i).slip_count,0)=NVL(v_members(j).slip_count,0) AND NVL(v_members(i).total_amount,0)<NVL(v_members(j).total_amount,0)) THEN v_swap:=TRUE; END IF;
                ELSIF :sort_by='items' THEN IF NVL(v_members(i).item_count,0)<NVL(v_members(j).item_count,0) OR (NVL(v_members(i).item_count,0)=NVL(v_members(j).item_count,0) AND NVL(v_members(i).total_amount,0)<NVL(v_members(j).total_amount,0)) THEN v_swap:=TRUE; END IF;
                ELSIF :sort_by='last_purchase' THEN IF v_members(i).last_purchase IS NULL THEN v_swap:=FALSE; ELSIF v_members(j).last_purchase IS NULL THEN v_swap:=TRUE; ELSIF v_members(i).last_purchase<v_members(j).last_purchase THEN v_swap:=TRUE; END IF;
                END IF;
                IF v_swap THEN v_temp:=v_members(i); v_members(i):=v_members(j); v_members(j):=v_temp; END IF;
            END;
        END LOOP;
    END LOOP;

    DECLARE v_out_cnt PLS_INTEGER := 0; BEGIN
        FOR i IN 1..v_members.COUNT LOOP
            EXIT WHEN v_out_cnt >= :top_n;
            IF NVL(v_members(i).slip_count,0) >= :min_slips THEN
                v_out_cnt := v_out_cnt + 1;
                DBMS_OUTPUT.PUT_LINE('DATA|'||NVL(v_members(i).member_id,'UNKNOWN')||'|'||
                    TO_CHAR(v_members(i).slip_count)||'|'||TO_CHAR(v_members(i).item_count)||'|'||
                    TO_CHAR(v_members(i).total_amount,'FM999999999990.00')||'|'||
                    NVL(TO_CHAR(v_members(i).last_purchase,'DD/MM/YYYY HH24:MI:SS'),'-')||'|'||
                    LTRIM(v_members(i).branches,','));
            END IF;
        END LOOP;
        DBMS_OUTPUT.PUT_LINE('REPEAT_COUNT:'||v_out_cnt);
        DBMS_OUTPUT.PUT_LINE('TOTAL_IN_PERIOD:'||v_members.COUNT);
    END;
EXCEPTION WHEN OTHERS THEN DBMS_OUTPUT.PUT_LINE('DEBUG: '||SQLERRM);
END;
/
EXIT;
SQL;
    if (!file_put_contents($sql_file, $sql_content)) { echo json_encode(['error'=>'ไม่สามารถเขียนไฟล์ SQL']); exit; }
    $user_pass = escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}");
    $cmd = "env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s $user_pass @$sql_file 2>&1";
    $output = shell_exec($cmd);
    @unlink($sql_file);
    $has_error = false;
    foreach (explode("\n", $output) as $chk) {
        $chk = trim($chk);
        if ($chk===''||strpos($chk,'DEBUG:')===0) continue;
        if (preg_match('/^(ORA-|SP2-)/', $chk)) { $has_error=true; break; }
    }
    if ($has_error) { echo json_encode(['error'=>"SQL Error:<br>".nl2br(htmlspecialchars($output))]); exit; }
    $lines = explode("\n", $output);
    $members=[]; $total_amount=0; $total_slips=0; $total_items=0; $total_in_period=0;
    foreach ($lines as $raw_line) {
        $line=trim($raw_line);
        if (strpos($line,'TOTAL_IN_PERIOD:')===0) { $total_in_period=(int)substr($line,16); continue; }
        if (strpos($line,'DATA|')!==0) continue;
        $parts=explode('|',$line);
        if (count($parts)<7) continue;
        $slip_int=(int)trim($parts[2]); $item_int=(int)trim($parts[3]); $amt_float=(float)trim($parts[4]);
        $members[]=['member_id'=>trim($parts[1]),'slip_count'=>$slip_int,'item_count'=>$item_int,
            'total_amount'=>$amt_float,'last_purchase'=>trim($parts[5]),'branches'=>trim($parts[6])];
        $total_slips+=$slip_int; $total_items+=$item_int; $total_amount+=$amt_float;
    }
    $total_members=count($members);
    $branch_counts=[];
    foreach ($members as $m) {
        foreach (explode(',',$m['branches']) as $br) { $br=trim($br); if($br!=='') $branch_counts[$br]=($branch_counts[$br]??0)+1; }
    }
    // Branch list จาก POS_SALE_HD
    $esc_s=str_replace("'","''",$start_date); $esc_e=str_replace("'","''",$end_date);
    $br_sql_file=sys_get_temp_dir()."/POS_HBRL_".uniqid().".sql";
    file_put_contents($br_sql_file,
        "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON LINESIZE 300\n".
        "ALTER SESSION SET NLS_LANGUAGE = American;\n".
        "SELECT DISTINCT TRIM(h.SALE_OFFICE)||'|'||NVL(TRIM(o.OFFICE_NAME),TRIM(h.SALE_OFFICE))\n".
        "FROM POS.POS_SALE_HD h LEFT JOIN POS.POS_SALE_OFFICE o ON TRIM(o.SALE_OFFICE)=TRIM(h.SALE_OFFICE)\n".
        "WHERE h.SALE_OFFICE IS NOT NULL AND TRIM(h.SALE_OFFICE) IS NOT NULL\n".
        "  AND h.CREATE_DATE >= TO_DATE('{$esc_s}','DD/MM/YYYY')\n".
        "  AND h.CREATE_DATE <  TO_DATE('{$esc_e}','DD/MM/YYYY') + 1\n".
        "ORDER BY 1;\nEXIT;\n");
    $br_out=shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s ".escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}")." @$br_sql_file 2>&1");
    @unlink($br_sql_file);
    $all_branches=[]; $office_name_map=[];
    foreach (preg_split('/\r?\n/',$br_out) as $bl) {
        $bl=trim($bl); if($bl===''||preg_match('/^(ORA-|SP2-)/',$bl)) continue;
        $bparts=explode('|',$bl,2); $code=trim($bparts[0]); $oname=isset($bparts[1])?trim($bparts[1]):$code;
        // กรองตามสิทธิ์สาขา
        if($code!=='' && (!function_exists('pos_can_see_branch') || pos_can_see_branch($code))) {
            $all_branches[]=$code;
            $office_name_map[$code]=($oname!==''&&$oname!==$code)?$oname:$code;
        }
    }
    $merged=[]; foreach($all_branches as $br){$merged[$br]=$branch_counts[$br]??0;}
    foreach($branch_counts as $br=>$cnt){if(!isset($merged[$br]))$merged[$br]=$cnt;}
    arsort($merged);
    $chart_labels=array_map(function($br)use($office_name_map){$o=$office_name_map[$br]??$br;return($o&&$o!==$br)?$o.' ('.$br.')':$br;},array_keys($merged));
    $chart_counts=array_values($merged);
    // all_member_count
    $all_member_count=0;
    $cnt_file=sys_get_temp_dir().'/POS_HCNT_'.uniqid().'.sql';
    file_put_contents($cnt_file,"SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON\nALTER SESSION SET NLS_LANGUAGE = American;\nSELECT COUNT(DISTINCT MEMBER_ID) FROM POS.POS_MEMBER_POINT WHERE MEMBER_ID IS NOT NULL;\nEXIT;\n");
    $cnt_out=shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s ".escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}")." @$cnt_file 2>&1");
    @unlink($cnt_file);
    foreach(explode("\n",(string)$cnt_out)as$cl){$cl=trim($cl);if($cl!==''&&is_numeric($cl)){$all_member_count=(int)$cl;break;}}
    $last_created_date='-';$last_created_member='-';
    $tmp_last=sys_get_temp_dir().'/POS_HLAST_'.uniqid().'.sql';
    file_put_contents($tmp_last,"SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 400 TRIMSPOOL ON\nALTER SESSION SET NLS_LANGUAGE = American;\nSELECT TO_CHAR(t.CREATED_DATE,'DD/MM/YYYY HH24:MI:SS')||'|'||t.MEMBER_ID FROM (SELECT CREATED_DATE,MEMBER_ID FROM POS.POS_MEMBER_POINT WHERE CREATED_DATE IS NOT NULL ORDER BY CREATED_DATE DESC,MEMBER_ID DESC) t WHERE ROWNUM=1;\nEXIT;\n");
    $out_last=shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s ".escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}")." @$tmp_last 2>&1");
    @unlink($tmp_last);
    foreach(explode("\n",(string)$out_last)as$ll){$ll=trim($ll);if($ll===''||preg_match('/^(ORA-|SP2-)/',$ll))continue;$lp=explode('|',$ll,2);if(count($lp)>=2&&$lp[0]!==''){$last_created_date=trim($lp[0]);$last_created_member=trim($lp[1]);break;}}
    echo json_encode(['mode'=>'history','refresh_time'=>date('d/m/Y H:i:s'),'start_date'=>$start_date,'end_date'=>$end_date,
        'top_n'=>$top_n,'sort_by'=>$sort_by,'member_id_filter'=>$member_id_filter,'min_slips'=>$min_slips,
        'total_members'=>$total_members,'total_in_period'=>$total_in_period ?: $total_members,
        'total_amount'=>$total_amount,'total_slips'=>$total_slips,'total_items'=>$total_items,
        'all_member_count'=>$all_member_count,'last_created_date'=>$last_created_date,'last_created_member'=>$last_created_member,
        'members'=>$members,'chart_labels'=>$chart_labels,'chart_counts'=>$chart_counts,'debug_oname_map'=>$office_name_map]);
    exit;
}

// ---------------------------
// AJAX REQUEST — โหมดยอดซื้อ 5 ปี (POS_SALE_HD)
// ---------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && $search_type === 'yearly' && empty($errors)) {
    while (ob_get_level() > 0) ob_end_clean();
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(600);
    ini_set('max_execution_time', '600');

    $instant_client_path = rtrim($instant_client_path, '/');
    $sqlplus_path = "{$instant_client_path}/sqlplus";
    if (!is_executable($sqlplus_path)) {
        echo json_encode(['error' => "SQL*Plus Not Found: {$sqlplus_path}"]); exit;
    }

    // ── Parse วันที่ที่เลือก ─────────────────────────────────
    $s_obj = DateTime::createFromFormat('d/m/Y', $start_date);
    $e_obj = DateTime::createFromFormat('d/m/Y', $end_date);
    if (!$s_obj || !$e_obj) {
        echo json_encode(['error' => 'วันที่ไม่ถูกต้อง']); exit;
    }

    // ── สร้าง 5 ช่วงปีย้อนหลัง (ช่วงวันเดิมแต่คนละปี) ──────
    // เหมือน POS_ITEMS bestseller: base_year = ปีของ start_date ที่เลือก
    $base_year = (int)$s_obj->format('Y');
    $years     = [$base_year, $base_year-1, $base_year-2, $base_year-3, $base_year-4];
    $md_start  = $s_obj->format('d/m'); // month-day ของช่วงเริ่ม
    $md_end    = $e_obj->format('d/m'); // month-day ของช่วงสิ้นสุด

    $year_ranges = [];
    foreach ($years as $yr) {
        $year_ranges[] = [
            'start'   => $md_start . '/' . $yr,
            'end'     => $md_end   . '/' . $yr,
            'year'    => $yr,
            'year_be' => $yr + 543,
        ];
    }

    // ── Branch / Access filters ───────────────────────────────
    $branch_access_clause = function_exists('pos_branch_sql') ? pos_branch_sql('h.SALE_OFFICE') : '1=1';
    $esc_branch   = str_replace("'", "''", $branch_filter);
    $branch_cond  = ($branch_filter !== '') ? "AND TRIM(h.SALE_OFFICE) = '{$esc_branch}'" : '';
    $member_cond  = ($escaped_member_id !== '')
        ? "AND UPPER(TRIM(h.MEMBER_ID)) LIKE '%'||UPPER('{$escaped_member_id}')||'%'"
        : '';

    // ── Build CASE WHEN blocks (เหมือน POS_ITEMS bestseller) ──
    // ใช้ literal date strings ใน SQL — ไม่ใช้ PL/SQL variables ใน WHERE
    $yr_amt_cases = ''; $yr_cnt_cases = '';
    $having_parts = []; $order_ds = ''; $order_de = '';
    foreach ($years as $i => $yr) {
        $ds = str_replace("'","''",$year_ranges[$i]['start']);
        $de = str_replace("'","''",$year_ranges[$i]['end']);
        $n   = $i + 1;
        $sep_c = ($i < 4) ? ',' : ''; // comma หลัง COUNT — เว้นตัวสุดท้าย
        // amt_cases: ต้องมีเครื่องหมาย comma เสมอ เพราะ cnt_cases ตามหลังเสมอ
        $yr_amt_cases .= "            SUM(CASE WHEN h.CREATE_DATE >= TO_DATE('{$ds}','DD/MM/YYYY')"
            . " AND h.CREATE_DATE < TO_DATE('{$de}','DD/MM/YYYY') + 1"
            . " THEN h.GRAND_AMOUNT ELSE 0 END) AS A{$n},\n";
        $yr_cnt_cases .= "            COUNT(CASE WHEN h.CREATE_DATE >= TO_DATE('{$ds}','DD/MM/YYYY')"
            . " AND h.CREATE_DATE < TO_DATE('{$de}','DD/MM/YYYY') + 1"
            . " THEN 1 END) AS C{$n}{$sep_c}\n";
        $having_parts[] = "SUM(CASE WHEN h.CREATE_DATE >= TO_DATE('{$ds}','DD/MM/YYYY')"
            . " AND h.CREATE_DATE < TO_DATE('{$de}','DD/MM/YYYY') + 1"
            . " THEN h.GRAND_AMOUNT ELSE 0 END) > 0";
        if ($i === 0) { $order_ds = $ds; $order_de = $de; }
    }
    $having_clause = implode("\n           OR ", $having_parts);

    // date range รวม (earliest start → latest end ครอบทั้ง 5 ปี)
    $ds_overall = str_replace("'","''",$year_ranges[4]['start']); // ปีเก่าสุด
    $de_overall = str_replace("'","''",$year_ranges[0]['end']);   // ปีล่าสุด

    $sql_yr = <<<SQL
SET ECHO OFF FEEDBACK OFF HEADING OFF VERIFY OFF LINESIZE 700 PAGESIZE 0 TRIMSPOOL ON
SET SERVEROUTPUT ON SIZE UNLIMITED
ALTER SESSION SET NLS_TERRITORY = America;
ALTER SESSION SET NLS_LANGUAGE = American;
DECLARE
    TYPE t_mid_tab IS TABLE OF VARCHAR2(50);
    TYPE t_num_tab IS TABLE OF NUMBER;
    v_mids t_mid_tab;
    v_a1 t_num_tab; v_a2 t_num_tab; v_a3 t_num_tab; v_a4 t_num_tab; v_a5 t_num_tab;
    v_c1 t_num_tab; v_c2 t_num_tab; v_c3 t_num_tab; v_c4 t_num_tab; v_c5 t_num_tab;
    v_t1 NUMBER:=0; v_t2 NUMBER:=0; v_t3 NUMBER:=0; v_t4 NUMBER:=0; v_t5 NUMBER:=0;
BEGIN
    -- รูปแบบเหมือน POS_ITEMS bestseller: BULK COLLECT พร้อม literal dates ใน SQL
    -- ไม่ใช้ CURSOR กับ PL/SQL variables เพื่อหลีกเลี่ยงปัญหา Oracle scope
    SELECT /*+ PARALLEL(h,4) INDEX(h IDX_SALE_HD_CDATE_OFF) */
        UPPER(TRIM(h.MEMBER_ID)),
{$yr_amt_cases}
{$yr_cnt_cases}
    BULK COLLECT INTO
        v_mids,
        v_a1, v_a2, v_a3, v_a4, v_a5,
        v_c1, v_c2, v_c3, v_c4, v_c5
    FROM POS.POS_SALE_HD h
    WHERE h.MEMBER_ID IS NOT NULL
      AND TRIM(h.MEMBER_ID) IS NOT NULL
      AND TRIM(h.MEMBER_ID) NOT IN ({$EXCL_SQL})
      AND h.CREATE_DATE >= TO_DATE('{$ds_overall}','DD/MM/YYYY')
      AND h.CREATE_DATE <  TO_DATE('{$de_overall}','DD/MM/YYYY') + 1
      AND ({$branch_access_clause})
      {$branch_cond}
      {$member_cond}
    GROUP BY UPPER(TRIM(h.MEMBER_ID))
    HAVING {$having_clause}
    ORDER BY SUM(CASE WHEN h.CREATE_DATE >= TO_DATE('{$order_ds}','DD/MM/YYYY')
                           AND h.CREATE_DATE < TO_DATE('{$order_de}','DD/MM/YYYY') + 1
                      THEN h.GRAND_AMOUNT ELSE 0 END) DESC;

    DBMS_OUTPUT.PUT_LINE('TOTAL_FOUND:'||v_mids.COUNT);
    FOR i IN 1..v_mids.COUNT LOOP
        EXIT WHEN i > {$top_n};
        v_t1 := v_t1 + NVL(v_a1(i),0);
        v_t2 := v_t2 + NVL(v_a2(i),0);
        v_t3 := v_t3 + NVL(v_a3(i),0);
        v_t4 := v_t4 + NVL(v_a4(i),0);
        v_t5 := v_t5 + NVL(v_a5(i),0);
        DBMS_OUTPUT.PUT_LINE(
            'YR|'||NVL(v_mids(i),'-')||'|'||
            TO_CHAR(NVL(v_a1(i),0),'FM999999990.00')||'|'||
            TO_CHAR(NVL(v_a2(i),0),'FM999999990.00')||'|'||
            TO_CHAR(NVL(v_a3(i),0),'FM999999990.00')||'|'||
            TO_CHAR(NVL(v_a4(i),0),'FM999999990.00')||'|'||
            TO_CHAR(NVL(v_a5(i),0),'FM999999990.00')||'|'||
            TO_CHAR(NVL(v_c1(i),0))||'|'||
            TO_CHAR(NVL(v_c2(i),0))||'|'||
            TO_CHAR(NVL(v_c3(i),0))||'|'||
            TO_CHAR(NVL(v_c4(i),0))||'|'||
            TO_CHAR(NVL(v_c5(i),0))
        );
    END LOOP;
    DBMS_OUTPUT.PUT_LINE('YTOTAL|'||
        TO_CHAR(v_t1,'FM999999990.00')||'|'||
        TO_CHAR(v_t2,'FM999999990.00')||'|'||
        TO_CHAR(v_t3,'FM999999990.00')||'|'||
        TO_CHAR(v_t4,'FM999999990.00')||'|'||
        TO_CHAR(v_t5,'FM999999990.00'));
    DBMS_OUTPUT.PUT_LINE('YCOUNT:'||LEAST(v_mids.COUNT, {$top_n}));
EXCEPTION WHEN OTHERS THEN
    DBMS_OUTPUT.PUT_LINE('FATAL|'||SQLERRM);
END;
/
EXIT;
SQL;

    $yr_sql_file = sys_get_temp_dir() . "/POS_YEARLY_" . uniqid() . ".sql";
    if (!file_put_contents($yr_sql_file, $sql_yr)) {
        echo json_encode(['error' => 'ไม่สามารถเขียนไฟล์ SQL ชั่วคราวได้']); exit;
    }
    $user_pass = escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}");
    $cmd = "env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s $user_pass @$yr_sql_file 2>&1";
    $yr_out = (string)shell_exec($cmd);
    @unlink($yr_sql_file);

    // ── ตรวจ error (เหมือน POS_ITEMS bestseller) ─────────────
    foreach (preg_split('/\r?\n/', $yr_out) as $ln) {
        $ln = trim($ln);
        if ($ln === '' || strpos($ln,'|') !== false) continue;
        if (preg_match('/^(ORA-|SP2-)/', $ln)) {
            echo json_encode(['error' => $ln, 'raw' => mb_substr($yr_out,0,1000)], JSON_UNESCAPED_UNICODE); exit;
        }
    }
    foreach (preg_split('/\r?\n/', $yr_out) as $ln) {
        $ln = trim($ln);
        if (strpos($ln, 'FATAL|') === 0) {
            echo json_encode(['error' => substr($ln,6), 'raw' => mb_substr($yr_out,0,1000)], JSON_UNESCAPED_UNICODE); exit;
        }
    }

    // ── Parse output ─────────────────────────────────────────
    $yr_members = []; $yr_totals = [0,0,0,0,0]; $yr_count = 0;
    foreach (preg_split('/\r?\n/', $yr_out) as $raw) {
        $ln = trim($raw);
        if (strpos($ln, 'YR|') === 0) {
            $p = explode('|', $ln);
            if (count($p) < 12) continue;
            $yr_members[] = [
                'member_id' => trim($p[1]),
                'a' => [(float)trim($p[2]),(float)trim($p[3]),(float)trim($p[4]),(float)trim($p[5]),(float)trim($p[6])],
                'c' => [(int)trim($p[7]),  (int)trim($p[8]),  (int)trim($p[9]),  (int)trim($p[10]), (int)trim($p[11])],
            ];
        } elseif (strpos($ln, 'YTOTAL|') === 0) {
            $p = explode('|', $ln);
            if (count($p) >= 6) $yr_totals = [(float)trim($p[1]),(float)trim($p[2]),(float)trim($p[3]),(float)trim($p[4]),(float)trim($p[5])];
        } elseif (strpos($ln, 'YCOUNT:') === 0) {
            $yr_count = (int)substr($ln, 7);
        }
    }
    echo json_encode([
        'search_type'   => 'yearly',
        'refresh_time'  => date('d/m/Y H:i:s'),
        'start_date'    => $start_date,
        'end_date'      => $end_date,
        'top_n'         => $top_n,
        'year_ranges'   => $year_ranges,
        'members'       => $yr_members,
        'totals'        => $yr_totals,
        'total_members' => $yr_count,
        'debug_raw'     => mb_substr($yr_out, 0, 500),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------
// AJAX DIAGNOSTIC — ?diag=1&start=29/03/2026&end=29/03/2026
// ใช้ตรวจสอบว่าข้อมูลอยู่ตารางไหน และ CREATE_DATE format เป็นอะไร
// ---------------------------
if (isset($_GET['diag']) && $_GET['diag'] === '1') {
    while (ob_get_level() > 0) ob_end_clean();
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');
    $instant_client_path = rtrim($instant_client_path, '/');
    $sqlplus_path = "{$instant_client_path}/sqlplus";
    $up_d = escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}");
    $d_start = trim($_GET['start'] ?? date('d/m/Y'));
    $d_end   = trim($_GET['end']   ?? date('d/m/Y'));

    $diag_sql = <<<DSQL
SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 500 TRIMSPOOL ON
SET SERVEROUTPUT ON SIZE UNLIMITED
ALTER SESSION SET NLS_TERRITORY = America;
ALTER SESSION SET NLS_LANGUAGE = American;
DECLARE
    v_s DATE := TO_DATE('{$d_start}','DD/MM/YYYY');
    v_e DATE := TO_DATE('{$d_end}','DD/MM/YYYY') + 1 - 1/86400;
    v_cnt NUMBER;
    v_min VARCHAR2(30); v_max VARCHAR2(30);
BEGIN
    -- 1. นับ row ใน POS_SALE_HD ช่วงวันที่นี้
    SELECT COUNT(*), TO_CHAR(MIN(CREATE_DATE),'DD/MM/YYYY HH24:MI:SS'), TO_CHAR(MAX(CREATE_DATE),'DD/MM/YYYY HH24:MI:SS')
    INTO v_cnt, v_min, v_max
    FROM POS.POS_SALE_HD
    WHERE CREATE_DATE >= v_s AND CREATE_DATE <= v_e;
    DBMS_OUTPUT.PUT_LINE('HD_COUNT:'||v_cnt||' MIN:'||NVL(v_min,'-')||' MAX:'||NVL(v_max,'-'));

    -- 2. MIN/MAX CREATE_DATE ทั้งหมดใน POS_SALE_HD (เพื่อรู้ข้อมูลเก่าล่าสุดถึงแค่ไหน)
    SELECT TO_CHAR(MIN(CREATE_DATE),'DD/MM/YYYY'), TO_CHAR(MAX(CREATE_DATE),'DD/MM/YYYY')
    INTO v_min, v_max FROM POS.POS_SALE_HD;
    DBMS_OUTPUT.PUT_LINE('HD_RANGE:'||NVL(v_min,'-')||' to '||NVL(v_max,'-'));

    -- 3. ดึงรายชื่อตาราง POS_SALETODAY_HD_* และนับ row ที่ตรงวันที่
    FOR rec IN (
        SELECT table_name FROM all_tables
        WHERE owner='POS' AND table_name LIKE 'POS_SALETODAY_HD_%'
        AND REPLACE(table_name,'POS_SALETODAY_HD_','') NOT IN ('_TMP','TEST99')
        ORDER BY table_name
    ) LOOP
        BEGIN
            EXECUTE IMMEDIATE
                'SELECT COUNT(*) FROM POS.'||rec.table_name||
                ' WHERE CREATE_DATE >= :1 AND CREATE_DATE <= :2'
            INTO v_cnt USING v_s, v_e;
            DBMS_OUTPUT.PUT_LINE('TODAY_'||rec.table_name||':'||v_cnt);
        EXCEPTION WHEN OTHERS THEN
            DBMS_OUTPUT.PUT_LINE('TODAY_'||rec.table_name||':ERR-'||SQLERRM);
        END;
    END LOOP;

    -- 4. ตัวอย่าง CREATE_DATE จริง 3 แถวล่าสุดจาก POS_SALE_HD
    FOR r IN (SELECT TO_CHAR(CREATE_DATE,'DD/MM/YYYY HH24:MI:SS') dt, MEMBER_ID
              FROM POS.POS_SALE_HD WHERE ROWNUM<=3 ORDER BY CREATE_DATE DESC) LOOP
        DBMS_OUTPUT.PUT_LINE('HD_SAMPLE:'||r.dt||' MID:'||NVL(r.MEMBER_ID,'-'));
    END LOOP;
EXCEPTION WHEN OTHERS THEN DBMS_OUTPUT.PUT_LINE('DIAG_ERR:'||SQLERRM);
END;
/
EXIT;
DSQL;
    $tmp_d = sys_get_temp_dir() . '/POS_DIAG_' . uniqid() . '.sql';
    file_put_contents($tmp_d, $diag_sql);
    $diag_out = shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s {$up_d} @{$tmp_d} 2>&1");
    @unlink($tmp_d);
    echo json_encode(['diag_raw' => (string)$diag_out], JSON_UNESCAPED_UNICODE);
    exit;
}


if (isset($_GET['detail']) && $_GET['detail'] === '1' && $mode === 'history') {
    while (ob_get_level() > 0) ob_end_clean();
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');
    $detail_member = strtoupper(trim($_GET['member_id'] ?? ''));
    if ($detail_member === '') { echo json_encode(['error'=>'No member_id']); exit; }
    $escaped_detail = str_replace("'","''",$detail_member);
    $instant_client_path = rtrim($instant_client_path, '/');
    $sqlplus_path = "{$instant_client_path}/sqlplus";
    $sql_file2 = sys_get_temp_dir() . "/POS_HMDETAIL_" . uniqid() . ".sql";

    // กรองสาขาในใบเสร็จตามสิทธิ์
    $detail_branch_clause = function_exists('pos_branch_sql') ? pos_branch_sql('h.SALE_OFFICE') : '1=1';

    $sql_detail = <<<SQL
SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 4000 TRIMSPOOL ON
SET SERVEROUTPUT ON SIZE UNLIMITED
ALTER SESSION SET NLS_TERRITORY = America;
ALTER SESSION SET NLS_LANGUAGE = American;
DECLARE
    v_start  DATE := TO_DATE('$start_date','DD/MM/YYYY');
    v_end    DATE := TO_DATE('$end_date','DD/MM/YYYY') + 1 - 1/86400;
    v_member VARCHAR2(100) := UPPER('$escaped_detail');
    TYPE t_str_tab  IS TABLE OF VARCHAR2(200);
    TYPE t_num_tab  IS TABLE OF NUMBER;
    TYPE t_date_tab IS TABLE OF DATE;
    v_slip_nos t_str_tab; v_amounts t_num_tab; v_dates t_date_tab; v_branches t_str_tab;
    TYPE t_bc_tab   IS TABLE OF VARCHAR2(50);
    TYPE t_name_tab IS TABLE OF VARCHAR2(255);
    v_barcodes t_bc_tab; v_names t_name_tab; v_qtys t_num_tab; v_uprices t_num_tab; v_totals t_num_tab;
BEGIN
    BEGIN
        SELECT /*+ INDEX(h IDX_SALE_HD_CDATE_OFF) INDEX(h IDX_SALE_HD_MEMBER_ID) */ h.SLIP_NO, h.GRAND_AMOUNT, h.CREATE_DATE, TRIM(h.SALE_OFFICE)
        BULK COLLECT INTO v_slip_nos, v_amounts, v_dates, v_branches
        FROM POS.POS_SALE_HD h
        WHERE UPPER(h.MEMBER_ID) = v_member
          AND h.CREATE_DATE >= v_start AND h.CREATE_DATE < v_end
          AND ({$detail_branch_clause})
        ORDER BY h.CREATE_DATE DESC;
    EXCEPTION WHEN OTHERS THEN v_slip_nos:=t_str_tab(); DBMS_OUTPUT.PUT_LINE('FATAL|'||SQLERRM);
    END;
    FOR s IN 1..v_slip_nos.COUNT LOOP
        DBMS_OUTPUT.PUT_LINE('SLIP|'||NVL(v_branches(s),'-')||'|'||v_slip_nos(s)||'|'||TO_CHAR(v_amounts(s),'FM999999999990.00')||'|'||TO_CHAR(v_dates(s),'DD/MM/YYYY HH24:MI:SS'));
        BEGIN
            SELECT d.BARCODE, SUBSTR(NVL(TRIM(p.PRODUCT_DESC),d.BARCODE),1,100), d.QTY, d.UNIT_PRICE, d.TOTAL_AMOUNT
            BULK COLLECT INTO v_barcodes, v_names, v_qtys, v_uprices, v_totals
            FROM POS.POS_SALE_DT d LEFT JOIN POS.POS_PRODUCT p ON p.BARCODE=d.BARCODE
            WHERE d.SLIP_NO=v_slip_nos(s);
            FOR k IN 1..v_barcodes.COUNT LOOP
                DBMS_OUTPUT.PUT_LINE('ITEM|'||NVL(TRIM(v_barcodes(k)),'-')||'|'||NVL(TRIM(v_names(k)),'-')||'|'||TO_CHAR(NVL(v_qtys(k),0),'FM999999990')||'|'||TO_CHAR(NVL(v_uprices(k),0),'FM999999990.00')||'|'||TO_CHAR(NVL(v_totals(k),0),'FM999999990.00'));
            END LOOP;
        EXCEPTION WHEN OTHERS THEN NULL; END;
    END LOOP;
    DBMS_OUTPUT.PUT_LINE('END_DETAIL');
EXCEPTION WHEN OTHERS THEN DBMS_OUTPUT.PUT_LINE('FATAL|'||SQLERRM);
END;
/
EXIT;
SQL;
    file_put_contents($sql_file2, $sql_detail);
    $user_pass = escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}");
    $cmd = "env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s $user_pass @$sql_file2 2>&1";
    $out = shell_exec($cmd);
    @unlink($sql_file2);
    $slips=[]; $cur=null;
    foreach (preg_split('/\r?\n/',$out) as $raw) {
        $ln=trim($raw);
        if($ln===''||$ln==='END_DETAIL') continue;
        if(strpos($ln,'SLIP|')===0){
            if($cur!==null) $slips[]=$cur;
            $p=explode('|',$ln,5); if(count($p)<5) continue;
            $cur=['branch'=>trim($p[1]),'slip_no'=>trim($p[2]),'amount'=>(float)trim($p[3]),'date'=>trim($p[4]),'items'=>[]];
        } elseif(strpos($ln,'ITEM|')===0&&$cur!==null){
            $p=explode('|',$ln,6); if(count($p)<6) continue;
            $cur['items'][]=['barcode'=>trim($p[1]),'name'=>trim($p[2]),'qty'=>(int)trim($p[3]),'unit_price'=>(float)trim($p[4]),'total'=>(float)trim($p[5])];
        }
    }
    if($cur!==null) $slips[]=$cur;
    echo json_encode(['member_id'=>$detail_member,'slips'=>$slips],JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------
// AJAX REQUEST — โหมดปกติ (purchase today)
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && empty($errors)) {
    while (ob_get_level() > 0) ob_end_clean();
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');
    $instant_client_path = rtrim($instant_client_path, '/');
    $sqlplus_path = "{$instant_client_path}/sqlplus";
    if (!is_executable($sqlplus_path)) {
        echo json_encode(['error' => "SQL*Plus Not Found: {$sqlplus_path}", 'output' => '']);
        exit;
    }
    // โหลดชื่อแคชเชียร์จาก POS.SK_USER
    $cashier_map = load_cashier_map($sqlplus_path, $oracle_user, $oracle_pass, $oracle_tns, $instant_client_path);

    // สร้าง SQL (เพิ่ม member_id_filter)
    $sql_content = <<<SQL
SET ECHO OFF
SET FEEDBACK OFF
SET HEADING OFF
SET VERIFY OFF
SET LINESIZE 500
SET PAGESIZE 0
SET TRIMSPOOL ON
SET SERVEROUTPUT ON SIZE UNLIMITED
ALTER SESSION SET NLS_TERRITORY = America;
ALTER SESSION SET NLS_LANGUAGE = American;
VARIABLE start_date VARCHAR2(10);
VARIABLE end_date VARCHAR2(10);
VARIABLE top_n NUMBER;
VARIABLE sort_by VARCHAR2(15);
VARIABLE member_id_filter VARCHAR2(100);
VARIABLE min_slips NUMBER;
EXEC :start_date := '$start_date';
EXEC :end_date := '$end_date';
EXEC :top_n := $top_n;
EXEC :sort_by := '$sort_by';
EXEC :member_id_filter := '$escaped_member_id';
EXEC :min_slips := $min_slips;
DECLARE
    TYPE t_member_rec IS RECORD (
        member_id VARCHAR2(50),
        slip_count NUMBER,
        item_count NUMBER,
        total_amount NUMBER,
        last_purchase DATE,
        branches VARCHAR2(4000)
    );
    TYPE t_member_tab IS TABLE OF t_member_rec;
    v_members t_member_tab := t_member_tab();
    v_temp t_member_rec;
    v_start DATE := TO_DATE(:start_date, 'DD/MM/YYYY');
    v_end DATE := TO_DATE(:end_date, 'DD/MM/YYYY') + 1 - 1/86400;
    v_found BOOLEAN;
    TYPE t_sale_rec IS RECORD (member_id VARCHAR2(50), grand_amount NUMBER, item_count NUMBER, create_date DATE);
    TYPE t_sale_tab IS TABLE OF t_sale_rec;
    v_sales t_sale_tab;
    v_member_filter VARCHAR2(100) := :member_id_filter;
    CURSOR c_tables IS
        SELECT table_name
        FROM all_tables
        WHERE owner = 'POS'
          AND table_name LIKE 'POS_SALETODAY_HD_%'
        ORDER BY table_name;
BEGIN
    DBMS_OUTPUT.PUT_LINE('Date Range: ' || :start_date || ' to ' || :end_date);
    DBMS_OUTPUT.PUT_LINE('Top N: ' || :top_n || ' | Sort by: ' || :sort_by);
    IF :member_id_filter IS NOT NULL AND LENGTH(TRIM(:member_id_filter)) > 0 THEN
        DBMS_OUTPUT.PUT_LINE('Member Filter: ' || :member_id_filter);
    END IF;
    DBMS_OUTPUT.PUT_LINE('Time ' || TO_CHAR(SYSDATE, 'HH24:MI:SS'));
    DBMS_OUTPUT.PUT_LINE(RPAD('-',120,'-'));
    DBMS_OUTPUT.PUT_LINE('Top ' || :top_n || ' Members - Sorted by ' ||
        CASE :sort_by
            WHEN 'amount' THEN 'Total Amount'
            WHEN 'slips' THEN 'Slip Count'
            WHEN 'items' THEN 'Item Count'
            WHEN 'last_purchase' THEN 'Last Purchase (Newest First)'
        END);
    DBMS_OUTPUT.PUT_LINE(RPAD('-',120,'-'));
    FOR rec IN c_tables LOOP
        DECLARE
            v_branch    VARCHAR2(100) := REPLACE(rec.table_name, 'POS_SALETODAY_HD_', '');
            v_hd_table  VARCHAR2(100) := rec.table_name;
            v_dt_table  VARCHAR2(100) := REPLACE(rec.table_name, 'HD', 'DT');
            v_hd_q      VARCHAR2(200) := DBMS_ASSERT.ENQUOTE_NAME(v_hd_table);
            v_dt_q      VARCHAR2(200) := DBMS_ASSERT.ENQUOTE_NAME(v_dt_table);
            v_sql       VARCHAR2(4000);
            v_sc_today  VARCHAR2(20);
        BEGIN
            IF v_branch NOT IN ('_TMP', 'TEST99') THEN
                -- ดึง SALE_OFFICE code จริงจาก HD table
                BEGIN
                    EXECUTE IMMEDIATE
                        'SELECT TRIM(SALE_OFFICE) FROM POS.'||v_hd_table||
                        ' WHERE SALE_OFFICE IS NOT NULL AND TRIM(SALE_OFFICE) IS NOT NULL AND ROWNUM=1'
                    INTO v_sc_today;
                EXCEPTION WHEN OTHERS THEN v_sc_today := v_branch; END;
                -- ใช้ v_sc_today แทน v_branch ใน branches field
                v_sql := 'SELECT h.MEMBER_ID, h.GRAND_AMOUNT,
                               NVL((SELECT COUNT(*) FROM POS.' || v_dt_q || ' d WHERE d.SLIP_NO = h.SLIP_NO), 0),
                               h.CREATE_DATE
                        FROM POS.' || v_hd_q || ' h
                        WHERE h.MEMBER_ID IS NOT NULL
                          AND TRIM(h.MEMBER_ID) IS NOT NULL
                          AND TRIM(h.MEMBER_ID) NOT IN (''-'',''0000000000000'',''6800201000229'')
                          AND h.CREATE_DATE >= :1
                          AND h.CREATE_DATE < :2';

                IF v_member_filter IS NOT NULL AND LENGTH(TRIM(v_member_filter)) > 0 THEN
                    v_sql := v_sql || ' AND UPPER(h.MEMBER_ID) LIKE ''%'' || :3 || ''%''';
                    BEGIN
                        EXECUTE IMMEDIATE v_sql BULK COLLECT INTO v_sales USING v_start, v_end, v_member_filter;
                    EXCEPTION
                        WHEN OTHERS THEN
                            DBMS_OUTPUT.PUT_LINE('DEBUG: Skip branch '||v_branch||' - '||SQLERRM);
                            v_sales := t_sale_tab();
                    END;
                ELSE
                    BEGIN
                        EXECUTE IMMEDIATE v_sql BULK COLLECT INTO v_sales USING v_start, v_end;
                    EXCEPTION
                        WHEN OTHERS THEN
                            DBMS_OUTPUT.PUT_LINE('DEBUG: Skip branch '||v_branch||' - '||SQLERRM);
                            v_sales := t_sale_tab();
                    END;
                END IF;

                FOR i IN 1..v_sales.COUNT LOOP
                    v_found := FALSE;
                    FOR j IN 1..v_members.COUNT LOOP
                        IF v_members(j).member_id = v_sales(i).member_id THEN
                            v_members(j).slip_count := v_members(j).slip_count + 1;
                            v_members(j).item_count := v_members(j).item_count + v_sales(i).item_count;
                            v_members(j).total_amount := v_members(j).total_amount + v_sales(i).grand_amount;
                            IF v_members(j).last_purchase IS NULL OR v_sales(i).create_date > v_members(j).last_purchase THEN
                                v_members(j).last_purchase := v_sales(i).create_date;
                            END IF;
                            IF INSTR(','||v_members(j).branches||',', ','||v_sc_today||',') = 0 THEN
                                v_members(j).branches := v_members(j).branches || ',' || v_sc_today;
                            END IF;
                            v_found := TRUE;
                            EXIT;
                        END IF;
                    END LOOP;
                    IF NOT v_found THEN
                        v_temp.member_id := v_sales(i).member_id;
                        v_temp.slip_count := 1;
                        v_temp.item_count := v_sales(i).item_count;
                        v_temp.total_amount := v_sales(i).grand_amount;
                        v_temp.last_purchase := v_sales(i).create_date;
                        v_temp.branches := v_sc_today;
                        v_members.EXTEND;
                        v_members(v_members.COUNT) := v_temp;
                    END IF;
                END LOOP;
            END IF;
        EXCEPTION WHEN OTHERS THEN
            DBMS_OUTPUT.PUT_LINE('DEBUG: Error in branch '||v_branch||' - '||SQLERRM);
        END;
    END LOOP;

    -- Sorting (เหมือนเดิม)
    FOR i IN 1..v_members.COUNT - 1 LOOP
        FOR j IN i+1..v_members.COUNT LOOP
            DECLARE v_swap BOOLEAN := FALSE; BEGIN
                IF :sort_by='amount' THEN
                    IF NVL(v_members(i).total_amount,0) < NVL(v_members(j).total_amount,0) THEN v_swap:=TRUE; END IF;
                ELSIF :sort_by='slips' THEN
                    IF NVL(v_members(i).slip_count,0)<NVL(v_members(j).slip_count,0)
                       OR (NVL(v_members(i).slip_count,0)=NVL(v_members(j).slip_count,0)
                           AND NVL(v_members(i).total_amount,0)<NVL(v_members(j).total_amount,0)) THEN v_swap:=TRUE; END IF;
                ELSIF :sort_by='items' THEN
                    IF NVL(v_members(i).item_count,0)<NVL(v_members(j).item_count,0)
                       OR (NVL(v_members(i).item_count,0)=NVL(v_members(j).item_count,0)
                           AND NVL(v_members(i).total_amount,0)<NVL(v_members(j).total_amount,0)) THEN v_swap:=TRUE; END IF;
                ELSIF :sort_by='last_purchase' THEN
                    IF v_members(i).last_purchase IS NULL THEN v_swap:=FALSE;
                    ELSIF v_members(j).last_purchase IS NULL THEN v_swap:=TRUE;
                    ELSIF v_members(i).last_purchase < v_members(j).last_purchase THEN v_swap:=TRUE;
                    END IF;
                END IF;
                IF v_swap THEN v_temp:=v_members(i); v_members(i):=v_members(j); v_members(j):=v_temp; END IF;
            END;
        END LOOP;
    END LOOP;

    -- Output
    IF v_members.COUNT = 0 THEN
        DBMS_OUTPUT.PUT_LINE('No member data found in any branch.');
    ELSE
        DECLARE v_out_cnt PLS_INTEGER := 0; BEGIN
            FOR i IN 1..v_members.COUNT LOOP
                EXIT WHEN v_out_cnt >= :top_n;
                IF NVL(v_members(i).slip_count,0) >= :min_slips THEN
                    v_out_cnt := v_out_cnt + 1;
                    DBMS_OUTPUT.PUT_LINE(
                        'DATA|'||
                        NVL(v_members(i).member_id,'UNKNOWN')||'|'||
                        TO_CHAR(v_members(i).slip_count)||'|'||
                        TO_CHAR(v_members(i).item_count)||'|'||
                        TO_CHAR(v_members(i).total_amount,'FM999999999990.00')||'|'||
                        NVL(TO_CHAR(v_members(i).last_purchase,'DD/MM/YYYY HH24:MI:SS'),'-')||'|'||
                        LTRIM(v_members(i).branches,',')
                    );
                END IF;
            END LOOP;
            DBMS_OUTPUT.PUT_LINE('TOTAL_IN_PERIOD:'||v_members.COUNT);
        END;
    END IF;
    DBMS_OUTPUT.PUT_LINE(RPAD('-',120,'-'));
END;
/
EXIT;
SQL;

    if (!file_put_contents($sql_file, $sql_content)) {
        echo json_encode(['error' => 'ไม่สามารถเขียนไฟล์ SQL ชั่วคราวได้', 'output' => '']);
        exit;
    }

    $user_pass = escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}");
    $cmd = "env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s $user_pass @$sql_file 2>&1";
    $output = shell_exec($cmd);
    @unlink($sql_file);

    // ตรวจ error เฉพาะบรรทัดที่ไม่ใช่ DEBUG
    $has_error = false;
    foreach (explode("\n", $output) as $chk) {
        $chk = trim($chk);
        if ($chk === '' || strpos($chk, 'DEBUG:') === 0) continue;
        if (preg_match('/^(ORA-|SP2-)/', $chk)) { $has_error = true; break; }
    }
    if (empty(trim($output)) || $has_error) {
        echo json_encode(['error' => "SQL Error:<br>" . nl2br(htmlspecialchars($output)), 'output' => $output]);
        exit;
    }

    // Parse output — รูปแบบ: DATA|member_id|slip|item|amount|last_purchase|branches
    $lines = explode("\n", $output);
    $members = [];
    $total_amount = 0; $total_slips = 0; $total_items = 0; $total_in_period = 0;
    foreach ($lines as $raw_line) {
        $line = trim($raw_line);
        if (strpos($line, 'TOTAL_IN_PERIOD:') === 0) { $total_in_period = (int)substr($line, 16); continue; }
        if (strpos($line, 'DATA|') !== 0) continue;
        $parts = explode('|', $line);
        if (count($parts) < 7) continue;
        $slip_int  = (int)   trim($parts[2]);
        $item_int  = (int)   trim($parts[3]);
        $amt_float = (float) trim($parts[4]);
        $members[] = [
            'member_id'    => trim($parts[1]),
            'slip_count'   => $slip_int,
            'item_count'   => $item_int,
            'total_amount' => $amt_float,
            'last_purchase'=> trim($parts[5]),
            'branches'     => trim($parts[6])
        ];
        $total_slips  += $slip_int;
        $total_items  += $item_int;
        $total_amount += $amt_float;
    }
    $total_members = count($members);

    // กรองสาขาตามสิทธิ์ (today mode — dynamic SALETODAY tables)
    if (function_exists('pos_get_branches') && pos_get_branches() !== null) {
        $members = array_filter($members, function($m) {
            if (empty($m['branches'])) return false;
            foreach (explode(',', $m['branches']) as $br) {
                if (pos_can_see_branch(trim($br))) return true;
            }
            return false;
        });
        $members = array_values($members);
    }
    // กรองตาม branch_filter ที่เลือก
    if ($branch_filter !== '') {
        $members = array_values(array_filter($members, function($m) use ($branch_filter) {
            foreach (explode(',', $m['branches']) as $br) {
                if (trim($br) === $branch_filter) return true;
            }
            return false;
        }));
    }
    // คำนวณยอดรวมใหม่เสมอ
    $total_slips = $total_items = 0; $total_amount = 0.0;
    foreach ($members as $m) {
        $total_slips  += $m['slip_count'];
        $total_items  += $m['item_count'];
        $total_amount += $m['total_amount'];
    }
    $total_members = count($members);

    // นับจำนวน member ต่อสาขา
    $branch_counts = [];
    foreach ($members as $m) {
        foreach (explode(',', $m['branches']) as $br) {
            $br = trim($br);
            if ($br !== '') {
                $branch_counts[$br] = ($branch_counts[$br] ?? 0) + 1;
            }
        }
    }

    // ดึงรายชื่อสาขาทั้งหมดพร้อม office_name จาก POS_SALETODAY_HD_* + POS_SALE_OFFICE
    $all_branches_sql = sys_get_temp_dir() . "/POS_MBRLIST_" . uniqid() . ".sql";
    $br_sql = <<<BRSQL
SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON LINESIZE 300
SET SERVEROUTPUT ON
DECLARE
    CURSOR c IS
        SELECT table_name FROM all_tables
        WHERE owner='POS' AND table_name LIKE 'POS_SALETODAY_HD_%'
          AND REPLACE(table_name,'POS_SALETODAY_HD_','') NOT IN ('_TMP','TEST99')
        ORDER BY table_name;
BEGIN
    FOR rec IN c LOOP
        DECLARE
            v_branch VARCHAR2(100) := REPLACE(rec.table_name,'POS_SALETODAY_HD_','');
            v_oname  VARCHAR2(100) := v_branch;
            v_sale_office_code VARCHAR2(10);
        BEGIN
            EXECUTE IMMEDIATE
                'SELECT TRIM(SALE_OFFICE) FROM POS.' || rec.table_name ||
                ' WHERE SALE_OFFICE IS NOT NULL AND TRIM(SALE_OFFICE) IS NOT NULL AND ROWNUM=1'
            INTO v_sale_office_code;

            SELECT NVL(TRIM(OFFICE_NAME), v_branch) INTO v_oname
            FROM POS.POS_SALE_OFFICE
            WHERE TRIM(SALE_OFFICE) = v_sale_office_code AND ROWNUM=1;

            IF v_oname IS NULL OR TRIM(v_oname) = '' THEN v_oname := v_branch; END IF;
            DBMS_OUTPUT.PUT_LINE(NVL(v_sale_office_code,v_branch)||'|'||v_oname);
        EXCEPTION WHEN OTHERS THEN
            DBMS_OUTPUT.PUT_LINE(v_branch||'|'||v_branch);
        END;
    END LOOP;
END;
/
EXIT;
BRSQL;
    file_put_contents($all_branches_sql, $br_sql);
    $br_cmd = "env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s " . escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}") . " @$all_branches_sql 2>&1";
    $br_out = shell_exec($br_cmd);
    @unlink($all_branches_sql);
    $all_branches = [];
    $office_name_map = [];
    foreach (preg_split('/\r?\n/', $br_out) as $bl) {
        $bl = trim($bl);
        if ($bl === '' || preg_match('/^(ORA-|SP2-)/', $bl)) continue;
        $bparts = explode('|', $bl, 2);
        $code = trim($bparts[0]);
        $oname = isset($bparts[1]) ? trim($bparts[1]) : $code;
        // กรองตามสิทธิ์สาขา
        if ($code !== '' && (!function_exists('pos_can_see_branch') || pos_can_see_branch($code))) {
            $all_branches[] = $code;
            $office_name_map[$code] = ($oname !== '' && $oname !== $code) ? $oname : $code;
        }
    }

    // merge: สาขาทั้งหมด ใส่ 0 ถ้าไม่มี member แล้ว sort จากมากไปน้อย
    $merged = [];
    foreach ($all_branches as $br) { $merged[$br] = $branch_counts[$br] ?? 0; }
    // สาขาที่มี member แต่ไม่อยู่ใน all_tables ก็ใส่ด้วย
    foreach ($branch_counts as $br => $cnt) { if (!isset($merged[$br])) $merged[$br] = $cnt; }
    arsort($merged);
    $chart_labels = array_map(function($br) use ($office_name_map) {
        $oname = isset($office_name_map[$br]) ? $office_name_map[$br] : $br;
        return ($oname && $oname !== $br) ? $oname . ' (' . $br . ')' : $br;
    }, array_keys($merged));
    $chart_codes  = array_keys($merged);
    $chart_counts = array_values($merged);

    // --- นับสมาชิกทั้งหมด + ล่าสุด สำหรับ purchase mode ---
    $all_member_count = 0;
    $last_created_date = '-';
    $last_created_member = '-';
    $up_stat = escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}");
    $sql_cnt_pur = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON\n"
                 . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
                 . "SELECT TRIM(TO_CHAR(COUNT(DISTINCT MEMBER_ID))) FROM POS.POS_MEMBER_POINT\n"
                 . "WHERE MEMBER_ID IS NOT NULL AND TRIM(MEMBER_ID) IS NOT NULL;\n"
                 . "EXIT;\n";
    $tmp_cnt_pur = sys_get_temp_dir() . '/POS_MCNTP_' . uniqid() . '.sql';
    file_put_contents($tmp_cnt_pur, $sql_cnt_pur);
    $cnt_out_pur = shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s {$up_stat} @{$tmp_cnt_pur} 2>&1");
    @unlink($tmp_cnt_pur);
    foreach (explode("\n", (string)$cnt_out_pur) as $cl) {
        $cl = trim($cl);
        if ($cl !== '' && is_numeric($cl)) { $all_member_count = (int)$cl; break; }
    }
    $sql_last_pur = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 400 TRIMSPOOL ON\n"
                  . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
                  . "SELECT TO_CHAR(t.CREATED_DATE,'DD/MM/YYYY HH24:MI:SS')||'|'||t.MEMBER_ID FROM (\n"
                  . "  SELECT CREATED_DATE, MEMBER_ID FROM POS.POS_MEMBER_POINT\n"
                  . "  WHERE CREATED_DATE IS NOT NULL\n"
                  . "  ORDER BY CREATED_DATE DESC, MEMBER_ID DESC\n"
                  . ") t WHERE ROWNUM = 1;\n"
                  . "EXIT;\n";
    $tmp_last_pur = sys_get_temp_dir() . '/POS_MLASTP_' . uniqid() . '.sql';
    file_put_contents($tmp_last_pur, $sql_last_pur);
    $out_last_pur = shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s {$up_stat} @{$tmp_last_pur} 2>&1");
    @unlink($tmp_last_pur);
    foreach (explode("\n", (string)$out_last_pur) as $ll) {
        $ll = trim($ll);
        if ($ll === '' || preg_match('/^(ORA-|SP2-)/', $ll)) continue;
        $lp = explode('|', $ll, 2);
        if (count($lp) >= 2 && $lp[0] !== '') {
            $last_created_date   = trim($lp[0]);
            $last_created_member = trim($lp[1]);
            break;
        }
    }

    $refresh_time = date('d/m/Y H:i:s');
    echo json_encode([
        'refresh_time'       => $refresh_time,
        'start_date'         => $start_date,
        'end_date'           => $end_date,
        'top_n'              => $top_n,
        'sort_by'            => $sort_by,
        'member_id_filter'   => $member_id_filter,
        'total_members'      => $total_members,
        'total_in_period'    => $total_in_period ?: $total_members,
        'total_amount'       => $total_amount,
        'total_slips'        => $total_slips,
        'total_items'        => $total_items,
        'all_member_count'   => $all_member_count,
        'last_created_date'  => $last_created_date,
        'last_created_member'=> $last_created_member,
        'members'            => $members,
        'debug_oname_map'    => $office_name_map,
        'chart_labels'       => $chart_labels,
        'chart_counts'       => $chart_counts
    ]);
    exit;
}

// ---------------------------
// AJAX ENDPOINT — โหลด stat สมาชิกทั้งหมด (เรียกทันทีเมื่อ page load)
// ---------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && isset($_GET['stat']) && $_GET['stat'] === 'member') {
    while (ob_get_level() > 0) ob_end_clean();
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');
    $instant_client_path = rtrim($instant_client_path, '/');
    $sqlplus_path = "{$instant_client_path}/sqlplus";
    if (!is_executable($sqlplus_path)) {
        echo json_encode(['error' => "SQL*Plus Not Found"]);
        exit;
    }
    $up_s = escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}");
    // --- นับสมาชิกทั้งหมด ---
    $all_member_count = 0;
    $sql_cnt = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON\n"
             . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
             . "SELECT TRIM(TO_CHAR(COUNT(DISTINCT MEMBER_ID))) FROM POS.POS_MEMBER_POINT\n"
             . "WHERE MEMBER_ID IS NOT NULL AND TRIM(MEMBER_ID) IS NOT NULL;\n"
             . "EXIT;\n";
    $tmp_cnt = sys_get_temp_dir() . '/POS_MCNT_' . uniqid() . '.sql';
    file_put_contents($tmp_cnt, $sql_cnt);
    $cnt_out = shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s {$up_s} @{$tmp_cnt} 2>&1");
    @unlink($tmp_cnt);
    foreach (explode("\n", (string)$cnt_out) as $cl) {
        $cl = trim($cl);
        if ($cl !== '' && is_numeric($cl)) { $all_member_count = (int)$cl; break; }
    }
    // --- หาสมาชิกล่าสุด (ใช้ ||'|'|| เพื่อ parse ง่าย) ---
    $last_created_date = '-';
    $last_created_member = '-';
    $sql_last = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 400 TRIMSPOOL ON\n"
              . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
              . "SELECT TO_CHAR(t.CREATED_DATE,'DD/MM/YYYY HH24:MI:SS')||'|'||t.MEMBER_ID FROM (\n"
              . "  SELECT CREATED_DATE, MEMBER_ID FROM POS.POS_MEMBER_POINT\n"
              . "  WHERE CREATED_DATE IS NOT NULL\n"
              . "  ORDER BY CREATED_DATE DESC, MEMBER_ID DESC\n"
              . ") t WHERE ROWNUM = 1;\n"
              . "EXIT;\n";
    $tmp_last = sys_get_temp_dir() . '/POS_MLAST_' . uniqid() . '.sql';
    file_put_contents($tmp_last, $sql_last);
    $out_last = shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s {$up_s} @{$tmp_last} 2>&1");
    @unlink($tmp_last);
    foreach (explode("\n", (string)$out_last) as $ll) {
        $ll = trim($ll);
        if ($ll === '' || preg_match('/^(ORA-|SP2-)/', $ll)) continue;
        $lp = explode('|', $ll, 2);
        if (count($lp) >= 2 && $lp[0] !== '') {
            $last_created_date   = trim($lp[0]);
            $last_created_member = trim($lp[1]);
            break;
        }
    }
    echo json_encode([
        'all_member_count'   => $all_member_count,
        'last_created_date'  => $last_created_date,
        'last_created_member'=> $last_created_member,
        'debug_cnt_raw'      => mb_substr((string)$cnt_out, 0, 300),
        'debug_last_raw'     => mb_substr((string)$out_last, 0, 300),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------
// AJAX DETAIL: ?detail=1&member_id=xxx&start=...&end=...
// ---------------------------
if (isset($_GET['detail']) && $_GET['detail'] === '1') {
    while (ob_get_level() > 0) ob_end_clean();
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');
    $detail_member = strtoupper(trim($_GET['member_id'] ?? ''));
    if ($detail_member === '') { echo json_encode(['error'=>'No member_id']); exit; }
    $escaped_detail = str_replace("'","''",$detail_member);

    $instant_client_path = rtrim($instant_client_path, '/');
    $sqlplus_path = "{$instant_client_path}/sqlplus";
    $sql_file2 = sys_get_temp_dir() . "/POS_MDETAIL_" . uniqid() . ".sql";

    $sql_detail = <<<SQL
SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 4000 TRIMSPOOL ON
SET SERVEROUTPUT ON SIZE UNLIMITED
ALTER SESSION SET NLS_TERRITORY = America;
ALTER SESSION SET NLS_LANGUAGE = American;
DECLARE
    v_start  DATE := TO_DATE('$start_date','DD/MM/YYYY');
    v_end    DATE := TO_DATE('$end_date','DD/MM/YYYY') + 1 - 1/86400;
    v_member VARCHAR2(100) := UPPER('$escaped_detail');
    -- slip collections (declared at top level — ใช้งานได้กับ Oracle ทุกเวอร์ชัน)
    TYPE t_str_tab  IS TABLE OF VARCHAR2(200);
    TYPE t_num_tab  IS TABLE OF NUMBER;
    TYPE t_date_tab IS TABLE OF DATE;
    v_slip_nos   t_str_tab;
    v_amounts    t_num_tab;
    v_dates      t_date_tab;
    -- item collections
    TYPE t_bc_tab   IS TABLE OF VARCHAR2(50);
    TYPE t_name_tab IS TABLE OF VARCHAR2(255);
    v_barcodes   t_bc_tab;
    v_names      t_name_tab;
    v_qtys       t_num_tab;
    v_uprices    t_num_tab;
    v_totals     t_num_tab;
BEGIN
    FOR rec IN (
        SELECT table_name
        FROM all_tables
        WHERE owner='POS' AND table_name LIKE 'POS_SALETODAY_HD_%'
        ORDER BY table_name
    ) LOOP
        DECLARE
            v_branch VARCHAR2(30) := REPLACE(rec.table_name,'POS_SALETODAY_HD_','');
            v_dt     VARCHAR2(100) := REPLACE(rec.table_name,'HD','DT');
        BEGIN
            IF v_branch NOT IN ('_TMP','TEST99') THEN
                -- ดึงทุก slip ของ member นี้จากตารางนี้
                BEGIN
                    EXECUTE IMMEDIATE
                        'SELECT /*+ INDEX(t IDX_SALE_HD_UPPER_MID) */ SLIP_NO, GRAND_AMOUNT, CREATE_DATE'||
                        ' FROM POS.'||rec.table_name||' t'||
                        ' WHERE UPPER(MEMBER_ID)=:1'||
                        '   AND CREATE_DATE>=:2 AND CREATE_DATE<:3'||
                        ' ORDER BY CREATE_DATE DESC'
                    BULK COLLECT INTO v_slip_nos, v_amounts, v_dates
                    USING v_member, v_start, v_end;
                EXCEPTION WHEN OTHERS THEN
                    v_slip_nos := t_str_tab();
                END;

                FOR s IN 1..v_slip_nos.COUNT LOOP
                    DBMS_OUTPUT.PUT_LINE(
                        'SLIP|'||v_branch||'|'||
                        v_slip_nos(s)||'|'||
                        TO_CHAR(v_amounts(s),'FM999999999990.00')||'|'||
                        TO_CHAR(v_dates(s),'DD/MM/YYYY HH24:MI:SS')
                    );
                    -- ดึงรายการสินค้าในสลิปนี้
                    BEGIN
                        EXECUTE IMMEDIATE
                            'SELECT d.BARCODE,'||
                            ' SUBSTR(NVL(TRIM(p.PRODUCT_DESC),d.BARCODE),1,100),'||
                            ' d.QTY, d.UNIT_PRICE, d.TOTAL_AMOUNT'||
                            ' FROM POS.'||v_dt||' d'||
                            ' LEFT JOIN POS.POS_PRODUCT p ON p.BARCODE=d.BARCODE'||
                            ' WHERE d.SLIP_NO=:1'
                        BULK COLLECT INTO v_barcodes, v_names, v_qtys, v_uprices, v_totals
                        USING v_slip_nos(s);
                        FOR k IN 1..v_barcodes.COUNT LOOP
                            DBMS_OUTPUT.PUT_LINE(
                                'ITEM|'||
                                NVL(TRIM(v_barcodes(k)),'-')||'|'||
                                NVL(TRIM(v_names(k)),'-')||'|'||
                                TO_CHAR(NVL(v_qtys(k),0),'FM999999990')||'|'||
                                TO_CHAR(NVL(v_uprices(k),0),'FM999999990.00')||'|'||
                                TO_CHAR(NVL(v_totals(k),0),'FM999999990.00')
                            );
                        END LOOP;
                    EXCEPTION WHEN OTHERS THEN NULL; END;
                END LOOP;
            END IF;
        EXCEPTION WHEN OTHERS THEN NULL; END;
    END LOOP;
    DBMS_OUTPUT.PUT_LINE('END_DETAIL');
EXCEPTION WHEN OTHERS THEN
    DBMS_OUTPUT.PUT_LINE('FATAL|'||SQLERRM);
END;
/
EXIT;
SQL;

    file_put_contents($sql_file2, $sql_detail);
    $user_pass = escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}");
    $cmd = "env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s $user_pass @$sql_file2 2>&1";
    $out = shell_exec($cmd);
    @unlink($sql_file2);

    // parse — ใช้ preg_split รองรับ \r\n และ \n
    $slips = []; $cur = null;
    foreach (preg_split('/\r?\n/', $out) as $raw) {
        $ln = trim($raw);
        if ($ln === '' || $ln === 'END_DETAIL') continue;
        if (strpos($ln,'SLIP|') === 0) {
            if ($cur !== null) $slips[] = $cur;
            $p = explode('|', $ln, 5);
            if (count($p) < 5) continue;
            $cur = [
                'branch'  => trim($p[1]),
                'slip_no' => trim($p[2]),
                'amount'  => (float)trim($p[3]),
                'date'    => trim($p[4]),
                'items'   => []
            ];
        } elseif (strpos($ln,'ITEM|') === 0 && $cur !== null) {
            $p = explode('|', $ln, 6);
            if (count($p) < 6) continue;
            $cur['items'][] = [
                'barcode'    => trim($p[1]),
                'name'       => trim($p[2]),
                'qty'        => (int)trim($p[3]),
                'unit_price' => (float)trim($p[4]),
                'total'      => (float)trim($p[5])
            ];
        }
    }
    if ($cur !== null) $slips[] = $cur;
    echo json_encode([
        'member_id' => $detail_member,
        'slips'     => $slips,
        'raw'       => mb_substr($out, 0, 2000)   // debug: ดูใน browser console
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── โหลดรายชื่อสาขาสำหรับ dropdown ──────────────────────────────────
$office_list_today   = [];
$office_list_history = [];
try {
    $sp_ol = rtrim($instant_client_path, '/') . '/sqlplus';
    $up_ol = escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}");
    $tmp_ol = tempnam(sys_get_temp_dir(), 'POSOL_') . '.sql';
    $ol_sql = <<<'OLSQL'
SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON LINESIZE 300
SET SERVEROUTPUT ON
DECLARE
    CURSOR c IS SELECT table_name FROM all_tables
        WHERE owner='POS' AND table_name LIKE 'POS_SALETODAY_HD_%'
          AND REPLACE(table_name,'POS_SALETODAY_HD_','') NOT IN ('_TMP','TEST99')
        ORDER BY table_name;
    v_branch VARCHAR2(100);
    v_oname  VARCHAR2(100);
    v_code   VARCHAR2(20);
BEGIN
    FOR rec IN c LOOP
        v_branch := REPLACE(rec.table_name,'POS_SALETODAY_HD_','');
        v_oname  := v_branch;
        v_code   := NULL;
        BEGIN
            EXECUTE IMMEDIATE 'SELECT TRIM(SALE_OFFICE) FROM POS.'||rec.table_name||
                ' WHERE SALE_OFFICE IS NOT NULL AND TRIM(SALE_OFFICE) IS NOT NULL AND ROWNUM=1'
            INTO v_code;
        EXCEPTION WHEN OTHERS THEN v_code := NULL; END;
        -- แสดงเฉพาะสาขาที่มี SALE_OFFICE code จริง (เหมือน POS_ITEMS.php)
        IF v_code IS NOT NULL THEN
            BEGIN
                SELECT NVL(TRIM(OFFICE_NAME),v_branch) INTO v_oname FROM POS.POS_SALE_OFFICE
                WHERE TRIM(SALE_OFFICE)=v_code AND ROWNUM=1;
                IF v_oname IS NULL OR TRIM(v_oname)='' THEN v_oname:=v_branch; END IF;
            EXCEPTION WHEN OTHERS THEN v_oname:=v_branch; END;
            DBMS_OUTPUT.PUT_LINE(v_code||'|'||v_oname);
        END IF;
    END LOOP;
END;
/
EXIT;
OLSQL;
    file_put_contents($tmp_ol, $ol_sql);
    $out_ol = (string)shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sp_ol} -s {$up_ol} @{$tmp_ol} 2>&1");
    @unlink($tmp_ol);
    foreach (preg_split('/\r?\n/', $out_ol) as $ln) {
        $ln=trim($ln); if($ln===''||preg_match('/^(ORA-|SP2-)/',$ln)) continue;
        $p=explode('|',$ln,2); if(count($p)===2&&$p[0]!=='') { $c=trim($p[0]);
            if(!function_exists('pos_can_see_branch')||pos_can_see_branch($c))
                $office_list_today[$c]=trim($p[1]); }
    }
    $tmp_h = tempnam(sys_get_temp_dir(), 'POSH_') . '.sql';
    file_put_contents($tmp_h,
        "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 300 TRIMSPOOL ON\n".
        "ALTER SESSION SET NLS_LANGUAGE = American;\n".
        "SELECT TRIM(SALE_OFFICE)||'|'||TRIM(NVL(OFFICE_NAME,SALE_OFFICE)) FROM POS.POS_SALE_OFFICE\n".
        "ORDER BY SALE_OFFICE;\nEXIT;\n");
    $out_h = (string)shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sp_ol} -s {$up_ol} @{$tmp_h} 2>&1");
    @unlink($tmp_h);
    foreach (preg_split('/\r?\n/', $out_h) as $ln) {
        $ln=trim($ln); if($ln===''||preg_match('/^(ORA-|SP2-)/',$ln)) continue;
        $p=explode('|',$ln,2); if(count($p)===2&&$p[0]!=='') { $c=trim($p[0]);
            if(!function_exists('pos_can_see_branch')||pos_can_see_branch($c))
                $office_list_history[$c]=trim($p[1]); }
    }
} catch (Throwable $e) { $office_list_today=[]; $office_list_history=[]; }
$office_list = ($mode==='today') ? $office_list_today : $office_list_history;
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>สมาชิก</title>
<link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: "Consolas", "Tahoma", sans-serif; background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); color: #eee; padding: 20px; min-height: 100vh; }
h1 { color: #00ffff; margin-bottom: 20px; text-align: center; text-shadow: 0 0 20px rgba(0,255,255,0.5); font-size: 28px; }
.container { max-width: 1400px; margin: 0 auto; }
.filter-section { background: rgba(0,0,0,0.4); padding: 20px; border-radius: 10px; margin-bottom: 30px; border: 2px solid #0ff; box-shadow: 0 0 30px rgba(0,255,255,0.2); }
.form-group { margin: 10px 5px; display: inline-block; position: relative; }
label { color: #0ff; margin-right: 8px; font-weight: bold; font-size: 14px; }
input[type=text], input[type=number], select { padding: 10px 12px; border-radius: 6px; border: 2px solid #0ff; background: #0a0a0a; color: #0ff; font-family: Consolas, monospace; font-size: 14px; transition: all 0.3s; }
input[type=text]:focus, input[type=number]:focus, select:focus { outline: none; box-shadow: 0 0 15px rgba(0,255,255,0.5); border-color: #00ffff; }
.date-icon { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #0ff; cursor: pointer; font-size: 16px; }
button { background: linear-gradient(135deg, #00bcd4 0%, #0097a7 100%); color: #fff; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; margin: 5px; font-weight: bold; font-size: 14px; transition: all 0.3s; box-shadow: 0 4px 15px rgba(0,188,212,0.3); }
button:hover { background: linear-gradient(135deg, #00e5ff 0%, #00bcd4 100%); transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,188,212,0.5); }
.error { color: #ff6b6b; background: rgba(255,0,0,0.1); padding: 15px; border-radius: 8px; margin: 15px 0; border: 2px solid #ff6b6b; }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin: 30px 0; }
.stat-card { background: linear-gradient(135deg, rgba(0,188,212,0.15) 0%, rgba(0,150,167,0.15) 100%); border: 2px solid #0ff; border-radius: 12px; padding: 25px; text-align: center; transition: all 0.3s; }
.stat-card:hover { transform: translateY(-8px) scale(1.02); box-shadow: 0 15px 40px rgba(0,255,255,0.4); border-color: #00ffff; }
table { width: 100%; border-collapse: collapse; margin-top: 30px; background: rgba(0,0,0,0.4); border-radius: 12px; overflow: hidden; box-shadow: 0 0 30px rgba(0,255,255,0.2); }
th, td { border: 1px solid #333; padding: 15px 20px; text-align: left; }
th { background: linear-gradient(135deg, #004d4d 0%, #003333 100%); color: #0ff; font-weight: bold; text-transform: uppercase; font-size: 13px; letter-spacing: 0.5px; }
tbody tr:nth-child(odd) { background: rgba(255,255,255,0.03); }
tbody tr:hover { background: rgba(0,255,255,0.15); transform: scale(1.01); cursor: pointer; }
.rank-badge { display: inline-block; width: 32px; height: 32px; line-height: 32px; border-radius: 50%; font-weight: bold; font-size: 15px; text-align: center; margin-right: 8px; box-shadow: 0 0 18px rgba(255,255,255,0.6); animation: pulse 2s infinite; }
.rank-1 .rank-badge { background: linear-gradient(135deg, #ffd700, #ffb800); color: #8B4513; text-shadow: 0 0 10px rgba(255,215,0,0.9); }
.rank-2 .rank-badge { background: linear-gradient(135deg, #c0c0c0, #a8a8a8); color: #4a4a4a; text-shadow: 0 0 10px rgba(192,192,192,0.9); }
.rank-3 .rank-badge { background: linear-gradient(135deg, #cd7f32, #b87333); color: #fff; text-shadow: 0 0 10px rgba(205,127,50,0.9); }
@keyframes pulse { 0% { box-shadow: 0 0 18px rgba(255,255,255,0.6); } 50% { box-shadow: 0 0 28px rgba(255,255,255,0.9); } 100% { box-shadow: 0 0 18px rgba(255,255,255,0.6); } }
.rank-cell { text-align: center; font-weight: bold; font-size: 18px; }
.summary-row { background: linear-gradient(135deg, rgba(0,255,255,0.25) 0%, rgba(0,188,212,0.25) 100%) !important; font-weight: bold; color: #0ff !important; font-size: 16px; }
.summary-row td { border-top: 3px solid #0ff !important; }
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
/* settings gear */
.tr-settings-btn {
    background: none; border: none; color: #0ff; cursor: pointer;
    font-size: 13px; padding: 0 2px; margin: 0; line-height: 1;
    opacity: 0.55; transition: opacity 0.2s;
    box-shadow: none !important; transform: none !important;
}
.tr-settings-btn:hover { opacity: 1; background: none !important;
    transform: none !important; box-shadow: none !important; }

.ui-datepicker { z-index: 9999 !important; }
.no-data { text-align: center; color: #ff6b6b; font-size: 18px; padding: 30px; }
.sort-label { color: #ffcc00; font-size: 14px; margin-left: 10px; }
.loading {color:#ffcc00;font-size:16px;text-align:center;padding:20px;}
tr.member-row { cursor:default; }

tr.detail-row td { padding:0 !important; border-top:none !important; }
.detail-box { background:#0a1628; border-top:2px solid #ffcc00; padding:16px 24px 20px; }
.detail-box h4 { color:#ffcc00; margin-bottom:10px; font-size:14px; }
.detail-table { width:100%; border-collapse:collapse; font-size:13px; }
.detail-table th { background:#003333; color:#0ff; padding:8px 12px; text-align:left; border:1px solid #1a4a4a; white-space:nowrap; }
.detail-table td { padding:7px 12px; border:1px solid #1a3a3a; color:#ddd; }
.detail-table tr.slip-row { background:rgba(0,188,212,0.12); font-weight:bold; }
.detail-table tr.slip-row td { color:#0ff; border-top:2px solid #006666; }
.detail-table tr.item-row:hover td { background:rgba(0,255,255,0.07); }
.detail-table td.td-amt { color:#4caf50; text-align:right; }
.detail-table td.td-num { text-align:right; }
.detail-table tr.slip-summary td { background:rgba(0,80,80,0.3); color:#ffcc00; font-weight:bold; text-align:right; border-top:1px solid #006666; }
.detail-loading { color:#ffcc00; padding:16px; text-align:center; }
.detail-error { color:#ff6b6b; padding:16px; text-align:center; }
canvas { background:rgba(0,0,0,0.4); border-radius:12px; margin:30px 0; border:2px solid #0ff; box-shadow:0 0 30px rgba(0,255,255,0.2); padding:10px; }
.mode-btn { padding:9px 22px; border-radius:6px; cursor:pointer; font-size:14px; font-weight:bold; margin:3px; border:2px solid #555; background:rgba(0,0,0,0.4); color:#aaa; transition:all 0.3s; }
.mode-btn:hover { opacity:0.85; transform:translateY(-1px); }
.mode-active-purchase { background:linear-gradient(135deg,#00bcd4,#0097a7) !important; border-color:#00ffff !important; color:#fff !important; box-shadow:0 0 15px rgba(0,255,255,0.4); }
.mode-active-point { background:linear-gradient(135deg,#7b2fbe,#5e17a0) !important; border-color:#cc88ff !important; color:#fff !important; box-shadow:0 0 15px rgba(123,47,190,0.5); }
#point-table .pt-balance { color:#ffcc00; font-weight:bold; font-size:15px; }
#point-table .pt-expiry { color:#ff6b6b; }

/* ===== YEARLY MODE ===== */
.mode-active-yearly { background:linear-gradient(135deg,#e65c00,#f9d423) !important; border-color:#ffa500 !important; color:#000 !important; box-shadow:0 0 15px rgba(230,92,0,0.5); }
#yearly-table { width:100%; border-collapse:collapse; margin-top:20px; background:rgba(0,0,0,0.4); border-radius:12px; overflow:hidden; box-shadow:0 0 30px rgba(255,165,0,0.2); }
#yearly-table th, #yearly-table td { border:1px solid #333; padding:10px 14px; }
#yearly-table thead tr th { background:linear-gradient(135deg,#3a2000,#2a1800); color:#ffa500; text-align:center; white-space:nowrap; }
#yearly-table thead th.yr-col { min-width:140px; }
#yearly-table .yr-year { font-size:26px; font-weight:bold; color:#ffd700; text-shadow:0 0 12px rgba(255,215,0,0.7); line-height:1.1; }
#yearly-table .yr-date { font-size:11px; color:#aaa; margin-top:4px; font-weight:normal; }
#yearly-table tbody tr:nth-child(odd) { background:rgba(255,255,255,0.02); }
#yearly-table tbody tr:hover { background:rgba(255,165,0,0.1); }
#yearly-table .yr-amt { text-align:right; font-weight:bold; color:#4caf50; }
#yearly-table .yr-amt-zero { text-align:right; color:#444; }
#yearly-table .yr-amt-cur { color:#00e5ff; font-size:15px; }
#yearly-table .yr-slips { font-size:11px; color:#888; text-align:right; }
#yearly-table .yr-summary-row { background:linear-gradient(135deg,rgba(255,165,0,0.2),rgba(200,120,0,0.2)) !important; font-weight:bold; }
#yearly-table .yr-summary-row td { border-top:3px solid #ffa500 !important; color:#ffa500; }
.yr-stat-card { background:linear-gradient(135deg,rgba(230,92,0,0.15),rgba(180,60,0,0.15)); border:2px solid #ffa500; border-radius:14px; padding:14px 24px; text-align:center; min-width:160px; }
.yr-stat-year { font-size:22px; font-weight:bold; color:#ffd700; }
.yr-stat-daterange { font-size:11px; color:#aaa; margin-bottom:6px; }
.yr-stat-amt { font-size:28px; font-weight:bold; color:#4caf50; text-shadow:0 0 10px rgba(76,175,80,0.5); }
.yr-stat-label { font-size:11px; color:#888; margin-top:2px; }
</style>
</head>
<body>
<?php pos_expiry_banner(); ?>
<?php $MENU_ACTIVE = 'members'; require_once 'POS_MENU.php'; ?>
<?php $pos_topright_show_online = false; require_once __DIR__ . '/POS_TOPRIGHT.php'; ?>
<h1><i class="fas fa-users"></i> สมาชิก</h1>
<?php pos_nav_buttons($pos_priority, $MENU_ACTIVE); ?>

</div>
<div class="filter-section">
<form method="GET" id="filter-form" style="text-align:center;">
    <input type="hidden" name="search_type" id="search_type" value="<?=htmlspecialchars($search_type)?>">
    <input type="hidden" name="mode" id="mode-input" value="<?=htmlspecialchars($mode)?>">
    <!-- ปุ่มสลับโหมดซื้อสินค้า -->
    <div style="margin-bottom:10px;" id="purchase-mode-row">
        <label style="color:#ffcc00;font-size:14px;margin-right:8px;"><i class="fas fa-history"></i> โหมดเวลา:</label>
        <button type="button" id="btn-mode-today" onclick="setPurchaseMode('today')"
            style="padding:7px 18px;border-radius:6px;border:2px solid #0ff;cursor:pointer;font-weight:bold;font-size:13px;
                   background:<?=$mode==='today'?'#0ff':'transparent'?>;color:<?=$mode==='today'?'#000':'#0ff'?>;">
            <i class="fas fa-chart-bar"></i> วันนี้
        </button>
        <button type="button" id="btn-mode-history" onclick="setPurchaseMode('history')"
            style="padding:7px 18px;border-radius:6px;border:2px solid #ff9800;cursor:pointer;font-weight:bold;font-size:13px;margin-left:6px;
                   background:<?=$mode==='history'?'#ff9800':'transparent'?>;color:<?=$mode==='history'?'#fff':'#ff9800'?>;">
            <i class="fas fa-history"></i> ย้อนหลัง
        </button>
    </div>
    <!-- ปุ่มสลับโหมด -->
    <div style="margin-bottom:14px;">
        <label style="color:#ffcc00; font-size:15px; margin-right:12px;"><i class="fas fa-toggle-on"></i> โหมดข้อมูล:</label>
        <button type="button" id="btn-mode-purchase" onclick="setSearchType('purchase')"
            class="mode-btn <?=$search_type==='purchase'?'mode-active-purchase':''?>">
            <i class="fas fa-shopping-cart"></i> ซื้อสินค้า
        </button>
        <button type="button" id="btn-mode-point" onclick="setSearchType('point')"
            class="mode-btn <?=$search_type==='point'?'mode-active-point':''?>">
            <i class="fas fa-star"></i> แต้มสะสม
        </button>
        <button type="button" id="btn-mode-yearly" onclick="setSearchType('yearly')"
            class="mode-btn <?=$search_type==='yearly'?'mode-active-yearly':''?>">
            <i class="fas fa-chart-line"></i> ยอดซื้อ
        </button>
    </div>
    <div class="form-group">
        <label for="member_id_filter">ค้นหาสมาชิก:</label>
        <input type="text" name="member_id_filter" id="member_id_filter"
               value="<?=htmlspecialchars($member_id_filter)?>"
               placeholder="เช่น 12345 หรือ ABC" autocomplete="off">
    </div>
    <!-- ตัวกรองเฉพาะโหมด purchase -->
    <span id="purchase-filters" style="display:<?=$search_type==='point'?'none':'inline'?>;">
    <div class="form-group" id="date-group-start">
        <label id="date-label-start" for="start_date"><?= $mode==='today' ? 'วันที่:' : 'เริ่มต้น:' ?></label>
        <input type="text" name="start" id="start_date" value="<?=htmlspecialchars($start_date)?>" placeholder="วว/ดด/ปปปป" autocomplete="off" readonly
               style="<?= $mode==='today' ? 'cursor:default;background:rgba(0,255,255,0.04);color:#aaa;border-color:#333;' : 'cursor:pointer;' ?>">
        <i class="fas fa-calendar-alt date-icon" id="start-icon" style="<?= $mode==='today' ? 'display:none;' : '' ?>"></i>
    </div>
    <div class="form-group" id="date-group-end" style="<?= $mode==='today' ? 'display:none;' : '' ?>">
        <label for="end_date">สิ้นสุด:</label>
        <input type="text" name="end" id="end_date" value="<?=htmlspecialchars($end_date)?>" placeholder="วว/ดด/ปปปป" autocomplete="off" readonly style="cursor:pointer;">
        <i class="fas fa-calendar-alt date-icon" id="end-icon"></i>
    </div>
    <div class="form-group" id="branch-group" style="display:inline-block;">
        <label for="branch-select">สาขา:</label>
        <select name="branch" id="branch-select" style="min-width:160px;cursor:pointer;">
            <option value="">— ทุกสาขา —</option>
            <?php foreach ($office_list as $code => $name): ?>
            <option value="<?=htmlspecialchars($code)?>" <?=$branch_filter===$code?'selected':''?>>
                <?=htmlspecialchars($name)?> (<?=htmlspecialchars($code)?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="top_n">อันดับ:</label>
        <input type="number" name="top_n" id="top_n" value="<?=htmlspecialchars($top_n)?>" min="1" max="1000000" style="width:80px;">
    </div>
    <div class="form-group">
        <label for="sort_by">เรียงตาม:</label>
        <select name="sort_by" id="sort_by">
            <option value="amount" <?= $sort_by === 'amount' ? 'selected' : '' ?>>ยอดเงิน</option>
            <option value="slips" <?= $sort_by === 'slips' ? 'selected' : '' ?>>จำนวนสลิป</option>
            <option value="items" <?= $sort_by === 'items' ? 'selected' : '' ?>>จำนวนสินค้า</option>
            <option value="last_purchase" <?= $sort_by === 'last_purchase' ? 'selected' : '' ?>>ซื้อล่าสุด</option> 
        </select>
    </div>
    <!-- ซื้อซ้ำ filter (history only) -->
    <div class="form-group" id="min-slips-group" style="display:inline-block;">
        <label for="min_slips">ซื้อซ้ำ ≥</label>
        <input type="number" name="min_slips" id="min_slips" value="<?=htmlspecialchars($min_slips)?>" min="1" max="9999" style="width:60px;">
        <span style="color:#aaa;font-size:12px;margin-left:4px;">ครั้ง</span>
    </div>
    </span><!-- /purchase-filters -->
    <button type="button" onclick="updateDashboard()"><i class="fas fa-search"></i> ค้นหา</button>
    <button type="button" id="refresh-btn" style="display:none;"><i class="fas fa-sync"></i> รีเฟรช</button>
</form>
</div>

<?php if (!empty($errors)): ?>
<div class="error"><h2>วันที่ไม่ถูกต้อง</h2><?=implode('<br>', $errors)?></div>
<?php endif; ?>

<!-- ===== SECTION: ซื้อสินค้า ===== -->
<div id="section-purchase" style="display:<?=$search_type==='point'?'none':'block'?>;">
<!-- Stat: สมาชิกทั้งหมด (ซื้อสินค้า) -->
<div style="display:flex; justify-content:center; gap:20px; flex-wrap:wrap; margin:20px 0;">
    <div style="background:linear-gradient(135deg,rgba(0,188,212,0.2),rgba(0,150,167,0.2)); border:2px solid #0ff; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(0,255,255,0.2);">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-users" style="margin-right:6px;"></i>สมาชิกทั้งหมด (ระบบ)</div>
        <div style="font-size:42px; font-weight:bold; color:#00ffff; text-shadow:0 0 16px rgba(0,255,255,0.6);" id="all-member-count">-</div>
        <div style="color:#aaa; font-size:12px; margin-top:4px;">คน</div>
    </div>
    <div style="background:linear-gradient(135deg,rgba(255,107,53,0.2),rgba(200,80,30,0.2)); border:2px solid #ff6b35; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(255,107,53,0.2);">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-filter" style="margin-right:6px;"></i>สมาชิก (ช่วงวันที่)</div>
        <div style="font-size:42px; font-weight:bold; color:#ff6b35; text-shadow:0 0 16px rgba(255,107,53,0.5);" id="total-members-card">0</div>
        <div style="color:#aaa; font-size:12px; margin-top:4px;">คน <span id="total-members-card-from" style="color:#777;"></span></div>
    </div>
    <div style="background:linear-gradient(135deg,rgba(255,215,0,0.15),rgba(200,160,0,0.15)); border:2px solid #ffd700; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(255,215,0,0.2); min-width:220px;">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-user-clock" style="margin-right:6px;"></i>สมาชิกล่าสุด</div>
        <div style="font-size:18px; font-weight:bold; color:#ffd700; text-shadow:0 0 12px rgba(255,215,0,0.6);" id="last-created-member">-</div>
        <div style="font-size:13px; color:#ffcc00; margin-top:6px;" id="last-created-date">-</div>
    </div>
</div>
<h2 style="color:#0ff; margin-top:40px; text-align:center;">จำนวนสมาชิกต่อสาขา</h2>
<div style="text-align:right;color:#aaa;font-size:12px;margin-bottom:6px;">
    รีเฟรชล่าสุด: <span id="members-refresh-time">-</span>
</div>
<canvas id="branchChart" height="80"></canvas>
<div style="text-align:center; margin-top:20px; font-size:28px; font-weight:bold; color:#0ff;">
รวมยอดซื้อ: <span id="total-amount">0.00</span> บาท
<span style="margin-left:40px; color:#ff6b35;">สมาชิก: <span id="total-members">0</span><span id="all-member-suffix"></span> คน</span>
<span style="margin-left:40px; color:#ffcc00;">สลิป: <span id="total-slips">0</span> สลิป</span>
<span style="margin-left:40px; color:#4caf50;">สินค้า: <span id="total-items">0</span> รายการ</span>
</div>
<h2 style="color:#0ff; margin-top:40px; text-align:center;">
    อันดับสมาชิกสูงสุด <span id="top-n-display">100</span> อันดับ
    <span class="sort-label">เรียงตาม: <span id="sort-label">-</span></span>
</h2>
<table id="member-table">
<thead>
    <tr>
        <th style="width:70px;">ลำดับ</th>
        <th>รหัสสมาชิก</th>
        <th>จำนวนสลิป</th>
        <th>จำนวนสินค้า</th>
        <th>ยอดรวม (บาท)</th>
        <th>ซื้อล่าสุด</th>
        <th>สาขาที่ซื้อ</th>
    </tr>
</thead>
<tbody>
    <tr><td colspan="7" class="loading">กำลังโหลดข้อมูล...</td></tr>
</tbody>
</table>
</div><!-- /section-purchase -->

<!-- ===== SECTION: แต้มสะสม ===== -->
<div id="section-point" style="display:<?=$search_type==='point'?'block':'none'?>;">
<div style="display:flex; justify-content:center; gap:20px; flex-wrap:wrap; margin:20px 0;">
    <div style="background:linear-gradient(135deg,rgba(156,39,176,0.2),rgba(106,27,154,0.2)); border:2px solid #ce93d8; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(156,39,176,0.25);">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-users" style="margin-right:6px;"></i>สมาชิกทั้งหมด (ระบบ)</div>
        <div style="font-size:42px; font-weight:bold; color:#ce93d8; text-shadow:0 0 16px rgba(206,147,216,0.6);" id="all-member-count-point">-</div>
        <div style="color:#aaa; font-size:12px; margin-top:4px;">คน</div>
    </div>
    <div style="background:linear-gradient(135deg,rgba(123,47,190,0.2),rgba(90,30,150,0.2)); border:2px solid #cc88ff; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(123,47,190,0.25);">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-star" style="margin-right:6px;"></i>รายการแต้มสะสม</div>
        <div style="font-size:42px; font-weight:bold; color:#cc88ff; text-shadow:0 0 16px rgba(204,136,255,0.6);" id="point-total-records">0</div>
        <div style="color:#aaa; font-size:12px; margin-top:4px;">รายการ</div>
        <div style="color:#cc88ff;font-size:12px;margin-top:4px;">เรียงตาม: <span id="sort-label-point">-</span></div>
    </div>
    <div style="background:linear-gradient(135deg,rgba(255,215,0,0.15),rgba(200,160,0,0.15)); border:2px solid #ffd700; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(255,215,0,0.2);">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-user-clock" style="margin-right:6px;"></i>สมาชิกล่าสุด</div>
        <div style="font-size:18px; font-weight:bold; color:#ffd700;" id="last-created-member-point">-</div>
        <div style="font-size:13px; color:#ffcc00; margin-top:6px;" id="last-created-date-point">-</div>
    </div>
</div>
<div style="margin-bottom:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
    <label style="color:#cc88ff;font-size:14px;font-weight:600;">
        <i class="fas fa-sort-amount-down" style="margin-right:6px;"></i>เรียงตาม:
    </label>
    <select id="sort_point" name="sort_point"
            onchange="updateDashboard()"
            style="background:rgba(60,0,90,0.8);color:#cc88ff;border:2px solid #7a3ab0;
                   border-radius:8px;padding:8px 14px;font-size:14px;cursor:pointer;">
        <option value="balance_desc"  <?=($sort_point??'balance_desc')==='balance_desc'?'selected':''?>>⬇ แต้มมากสุด</option>
        <option value="balance_asc"   <?=($sort_point??'balance_desc')==='balance_asc'?'selected':''?>>⬆ แต้มน้อยสุด</option>
        <option value="member_id"     <?=($sort_point??'balance_desc')==='member_id'?'selected':''?>>รหัสสมาชิก</option>
        <option value="last_date_desc" <?=($sort_point??'balance_desc')==='last_date_desc'?'selected':''?>>วันที่รับล่าสุด</option>
        <option value="expiry_asc"    <?=($sort_point??'balance_desc')==='expiry_asc'?'selected':''?>>วันหมดอายุ (ใกล้สุด)</option>
    </select>
</div>
<table id="point-table">
<thead>
    <tr>
        <th style="width:50px;">#</th>
        <th>Site</th>
        <th>รหัสสมาชิก</th>
        <th>ประเภทแต้ม</th>
        <th>สาขา</th>
        <th>แต้มคงเหลือ</th>
        <th>แต้มรับล่าสุด</th>
        <th>วันที่รับล่าสุด</th>
        <th>วันหมดอายุ</th>
        <th>แต้มยกมา</th>
        <th>วันที่ยกมา</th>
    </tr>
</thead>
<tbody>
    <tr><td colspan="11" style="text-align:center;padding:60px 20px;color:#cc88ff;font-size:20px;">
        <i class="fas fa-search" style="font-size:40px;margin-bottom:15px;display:block;opacity:0.5;"></i>
        กรุณากรอกรหัสสมาชิกแล้วกด <strong>ค้นหา</strong></td></tr>
</tbody>
</table>
</div><!-- /section-point -->

<!-- ===== SECTION: ยอดซื้อ 5 ปี ===== -->
<div id="section-yearly" style="display:<?=$search_type==='yearly'?'block':'none'?>;">
<!-- Summary cards -->
<div id="yearly-stat-cards" style="display:flex; justify-content:center; gap:12px; flex-wrap:wrap; margin:20px 0;"></div>
<!-- Yearly table -->
<h2 style="color:#ffa500; margin-top:30px; text-align:center;">
    <i class="fas fa-chart-line"></i> เปรียบเทียบยอดซื้อย้อนหลัง 5 ปี
    <span style="font-size:14px; color:#aaa; margin-left:16px;">อันดับ <span id="yearly-top-n-display">100</span> อันดับ</span>
</h2>
<div style="text-align:center; color:#888; font-size:13px; margin-bottom:12px;" id="yearly-date-hint">
    กรุณาเลือกช่วงวันที่แล้วกด <strong>ค้นหา</strong> เพื่อโหลดข้อมูลเปรียบเทียบ
</div>
<table id="yearly-table">
<thead id="yearly-thead">
    <tr>
        <th style="width:60px;">ลำดับ</th>
        <th>รหัสสมาชิก</th>
        <th class="yr-col"><div class="yr-year">ปี 1</div><div class="yr-date">-</div></th>
        <th class="yr-col"><div class="yr-year">ปี 2</div><div class="yr-date">-</div></th>
        <th class="yr-col"><div class="yr-year">ปี 3</div><div class="yr-date">-</div></th>
        <th class="yr-col"><div class="yr-year">ปี 4</div><div class="yr-date">-</div></th>
        <th class="yr-col"><div class="yr-year">ปี 5</div><div class="yr-date">-</div></th>
    </tr>
</thead>
<tbody id="yearly-tbody">
    <tr><td colspan="7" style="text-align:center;padding:60px 20px;color:#ffa500;font-size:20px;">
        <i class="fas fa-chart-line" style="font-size:40px;margin-bottom:15px;display:block;opacity:0.5;"></i>
        กรุณาเลือกช่วงวันที่แล้วกด <strong>ค้นหา</strong></td></tr>
</tbody>
</table>
</div><!-- /section-yearly -->

<script>
const BRANCHES_TODAY   = <?= json_encode(array_map(fn($c,$n)=>['code'=>$c,'label'=>($n!==$c?"$n ($c)":$c)],array_keys($office_list_today),array_values($office_list_today)),JSON_UNESCAPED_UNICODE) ?>;
const BRANCHES_HISTORY = <?= json_encode(array_map(fn($c,$n)=>['code'=>$c,'label'=>($n!==$c?"$n ($c)":$c)],array_keys($office_list_history),array_values($office_list_history)),JSON_UNESCAPED_UNICODE) ?>;
let memberChart = null;

function adToBe(dateStr) {
    if (!dateStr || dateStr === '-') return dateStr;
    const m = dateStr.match(/^(\d{2}\/\d{2}\/)((\d{4})(.*)?)$/);
    if (!m) return dateStr;
    const year = parseInt(m[3], 10);
    if (isNaN(year) || year < 1000) return dateStr;
    return m[1] + (year + 543) + (m[4] || '');
}

function setPurchaseMode(mode) {
    document.getElementById('mode-input').value = mode;
    const isHistory = mode === 'history';
    const tb = document.getElementById('btn-mode-today');
    const hb = document.getElementById('btn-mode-history');
    tb.style.background = isHistory ? 'transparent' : '#0ff';
    tb.style.color      = isHistory ? '#0ff' : '#000';
    hb.style.background = isHistory ? '#ff9800' : 'transparent';
    hb.style.color      = isHistory ? '#fff' : '#ff9800';
    const mg = document.getElementById('min-slips-group');
    if (mg) mg.style.display = 'inline-block';
    // rebuild branch dropdown ตาม mode
    const branchSel = document.getElementById('branch-select');
    if (branchSel) {
        const prev = branchSel.value;
        const list = isHistory ? BRANCHES_HISTORY : BRANCHES_TODAY;
        branchSel.innerHTML = '<option value="">— ทุกสาขา —</option>';
        list.forEach(b => {
            const opt = document.createElement('option');
            opt.value = b.code; opt.textContent = b.label;
            if (b.code === prev) opt.selected = true;
            branchSel.appendChild(opt);
        });
        if (branchSel.value !== prev) branchSel.value = '';
    }
    // ปรับวันที่ default: history = เมื่อวาน, today = วันนี้
    const now = new Date();
    const todayStr = ("0"+now.getDate()).slice(-2)+'/'+("0"+(now.getMonth()+1)).slice(-2)+'/'+now.getFullYear();
    const yest = new Date(now); yest.setDate(yest.getDate()-1);
    const yesterdayStr = ("0"+yest.getDate()).slice(-2)+'/'+("0"+(yest.getMonth()+1)).slice(-2)+'/'+yest.getFullYear();
    const targetDate = isHistory ? yesterdayStr : todayStr;

    const sdEl   = document.getElementById('start_date');
    const edEl   = document.getElementById('end_date');
    const siEl   = document.getElementById('start-icon');
    const lbEl   = document.getElementById('date-label-start');
    const dgEnd  = document.getElementById('date-group-end');

    if (!isHistory) {
        // ── today: วันเดียว ล็อคไม่ให้เปลี่ยน ──
        if (sdEl) { sdEl.value = todayStr; sdEl.style.cursor = 'default'; sdEl.style.background = 'rgba(0,255,255,0.04)'; sdEl.style.color = '#aaa'; sdEl.style.borderColor = '#333'; }
        if (edEl) edEl.value = todayStr;
        if (siEl) siEl.style.display = 'none';
        if (lbEl) lbEl.textContent = 'วันที่:';
        if (dgEnd) dgEnd.style.display = 'none';
    } else {
        // ── history: 2 ช่อง เปิดใช้งานได้ ──
        if (sdEl) { sdEl.value = yesterdayStr; sdEl.style.cursor = 'pointer'; sdEl.style.background = ''; sdEl.style.color = ''; sdEl.style.borderColor = ''; }
        if (edEl) edEl.value = yesterdayStr;
        if (siEl) siEl.style.display = '';
        if (lbEl) lbEl.textContent = 'เริ่มต้น:';
        if (dgEnd) dgEnd.style.display = '';
        if (typeof $ !== 'undefined' && $.datepicker) {
            $('#start_date,#end_date').datepicker('option','minDate', new Date(2000,0,1));
            $('#start_date,#end_date').datepicker('option','maxDate','today');
            $('#start_date').datepicker('setDate', yesterdayStr);
            $('#end_date').datepicker('setDate', yesterdayStr);
        }
    }
    // เคลียร์หน้าจอทุกครั้งที่สลับโหมด + จัดการ timer
    _stopAutoRefresh();
    const currentType = document.getElementById('search_type').value;
    if (currentType === 'yearly') {
        // โหมดยอดซื้อ: เคลียร์ตาราง yearly และรอกดค้นหา (เหมือน bestseller ใน POS_ITEMS)
        document.getElementById('yearly-tbody').innerHTML =
            `<tr><td colspan="7" style="text-align:center;padding:60px 20px;color:#ffa500;font-size:20px;">
            <i class="fas fa-chart-line" style="font-size:40px;margin-bottom:15px;display:block;opacity:0.6;"></i>
            กรุณาเลือกช่วงวันที่แล้วกด <strong>ค้นหา</strong> เพื่อโหลดข้อมูลเปรียบเทียบ</td></tr>`;
        document.getElementById('yearly-stat-cards').innerHTML = '';
        document.getElementById('yearly-date-hint').style.display = '';
    } else {
        document.querySelector('#member-table tbody').innerHTML =
            isHistory
            ? `<tr><td colspan="7" style="text-align:center;padding:60px 20px;color:#ff9800;font-size:20px;">
                <i class="fas fa-history" style="font-size:40px;margin-bottom:15px;display:block;opacity:0.6;"></i>
                กรุณาเลือกช่วงวันที่แล้วกด <strong>ค้นหา</strong> เพื่อโหลดข้อมูล</td></tr>`
            : `<tr><td colspan="7" style="text-align:center;padding:60px 20px;color:#0ff;font-size:20px;">
                <i class="fas fa-spinner fa-spin" style="font-size:40px;margin-bottom:15px;display:block;"></i>
                กำลังโหลดข้อมูล...</td></tr>`;
        document.getElementById('branchChart').style.display = 'none';
        document.getElementById('total-members-card').innerText = '0';
        document.getElementById('total-members-card-from').innerText = '';
        document.getElementById('total-amount').innerText = '0.00';
        document.getElementById('total-members').innerText = '0';
        document.getElementById('total-slips').innerText = '0';
        document.getElementById('total-items').innerText = '0';
        document.getElementById('all-member-suffix').textContent = '';
        if (!isHistory) {
            document.querySelector('#member-table tbody').innerHTML =
                `<tr><td colspan="7" style="text-align:center;padding:60px 20px;color:#ff9800;font-size:20px;">
                <i class="fas fa-search" style="font-size:40px;margin-bottom:15px;display:block;opacity:0.6;"></i>
                กรุณากด <strong>ค้นหา</strong> เพื่อโหลดข้อมูล</td></tr>`;
            // today mode + purchase: เริ่ม auto-refresh
            _startAutoRefresh();
        }
    }
}

function setSearchType(type) {
    document.getElementById('search_type').value = type;
    const isPurchase = type === 'purchase';
    const isPoint    = type === 'point';
    const isYearly   = type === 'yearly';
    document.getElementById('purchase-filters').style.display = (isPurchase || isYearly) ? 'inline' : 'none';
    document.getElementById('section-purchase').style.display = isPurchase ? 'block' : 'none';
    document.getElementById('section-point').style.display    = isPoint    ? 'block' : 'none';
    document.getElementById('section-yearly').style.display   = isYearly   ? 'block' : 'none';
    document.getElementById('branchChart').style.display      = 'none';
    // ซ่อน today/history row เมื่อโหมด point
    const modeRow = document.getElementById('purchase-mode-row');
    if (modeRow) modeRow.style.display = '';
    const btnP  = document.getElementById('btn-mode-purchase');
    const btnPt = document.getElementById('btn-mode-point');
    const btnYr = document.getElementById('btn-mode-yearly');
    btnP.className  = 'mode-btn' + (isPurchase ? ' mode-active-purchase' : '');
    btnPt.className = 'mode-btn' + (isPoint    ? ' mode-active-point'    : '');
    btnYr.className = 'mode-btn' + (isYearly   ? ' mode-active-yearly'   : '');
    // timer control: auto-refresh เฉพาะ purchase + today เท่านั้น
    const curMode = document.getElementById('mode-input').value;
    if (isPurchase && curMode !== 'history') {
        _startAutoRefresh();
    } else {
        _stopAutoRefresh();
    }
    // เมื่อสลับมา yearly: ปลด minDate เพื่อให้เลือกย้อนหลังได้ แต่เฉพาะ history mode
    if (isYearly) {
        const curPMode = document.getElementById('mode-input').value;
        if (curPMode === 'history') {
            if (typeof $ !== 'undefined' && $.datepicker) {
                $('#start_date,#end_date').datepicker('option', 'minDate', new Date(2000,0,1));
                $('#start_date,#end_date').datepicker('option', 'maxDate', 'today');
            }
            const dgEnd2 = document.getElementById('date-group-end');
            if (dgEnd2) dgEnd2.style.display = '';
        }
        // today + yearly: คงล็อคไว้ ใช้วันนี้เป็น base year
    }
}

// ============================================================
// โหมดยอดซื้อ 5 ปี
// ============================================================
function updateYearlyDashboard() {
    const startVal = document.getElementById('start_date').value.trim();
    const endVal   = document.getElementById('end_date').value.trim();
    if (!startVal || !endVal) {
        alert('กรุณาระบุวันที่เริ่มต้นและสิ้นสุดก่อนค้นหา');
        return;
    }
    // แสดง loading
    document.getElementById('yearly-tbody').innerHTML =
        `<tr><td colspan="7" style="text-align:center;padding:60px 20px;color:#ffa500;font-size:20px;">
        <i class="fas fa-spinner fa-spin" style="font-size:40px;margin-bottom:15px;display:block;"></i>
        กำลังโหลดข้อมูลเปรียบเทียบ 5 ปี...</td></tr>`;

    const p = new URLSearchParams();
    p.set('ajax',        '1');
    p.set('search_type', 'yearly');
    p.set('start',       startVal);
    p.set('end',         endVal);
    p.set('top_n',       document.getElementById('top_n').value || '100');
    const mf = document.getElementById('member_id_filter').value.trim();
    if (mf) p.set('member_id_filter', mf);
    const br = (document.getElementById('branch-select') || {value:''}).value.trim();
    if (br) p.set('branch', br);

    fetch('?' + p.toString())
    .then(r => r.ok ? r.json() : Promise.reject(`HTTP ${r.status}`))
    .then(d => {
        if (d.error) throw new Error(d.error);

        document.getElementById('refresh-time').innerText = d.refresh_time;
        document.getElementById('yearly-top-n-display').innerText = Number(d.top_n).toLocaleString();
        document.getElementById('yearly-date-hint').style.display = 'none';

        const yr = d.year_ranges;  // [{year, year_be, start, end}, ...]

        // ---- สร้าง header ตาราง ----
        const thead = document.getElementById('yearly-thead');
        let hrow = `<tr>
            <th style="width:60px;">ลำดับ</th>
            <th>รหัสสมาชิก</th>`;
        yr.forEach((y, i) => {
            const isCurrentYear = (i === 0);
            hrow += `<th class="yr-col" style="${isCurrentYear ? 'border-bottom:3px solid #00e5ff;' : ''}">
                <div class="yr-year" style="${isCurrentYear ? 'color:#00e5ff;' : ''}">${y.year_be}</div>
                <div class="yr-date">${fmtShortDateRange(y.start, y.end)}</div>
            </th>`;
        });
        hrow += `</tr>`;
        thead.innerHTML = hrow;

        // ---- Summary stat cards ----
        const cards = document.getElementById('yearly-stat-cards');
        cards.innerHTML = '';
        yr.forEach((y, i) => {
            const tot = d.totals[i] || 0;
            const isCurrentYear = (i === 0);
            const card = document.createElement('div');
            card.className = 'yr-stat-card';
            if (isCurrentYear) card.style.borderColor = '#00e5ff';
            card.innerHTML = `
                <div class="yr-stat-year" style="${isCurrentYear ? 'color:#00e5ff;' : ''}">${y.year_be}</div>
                <div class="yr-stat-daterange">${y.start} – ${y.end}</div>
                <div class="yr-stat-amt">${Number(tot).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</div>
                <div class="yr-stat-label">บาท</div>`;
            cards.appendChild(card);
        });

        // ---- Member rows ----
        const tbody = document.getElementById('yearly-tbody');
        tbody.innerHTML = '';
        if (!d.members || d.members.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:80px 20px;color:#ff9800;font-size:24px;">
                <i class="fas fa-inbox" style="margin-right:16px;"></i>ไม่พบข้อมูลในช่วงวันที่ที่ระบุ</td></tr>`;
            return;
        }

        d.members.forEach((m, i) => {
            const rank = i + 1;
            const rankClass = rank <= 3 ? `rank-${rank}` : '';
            const tr = document.createElement('tr');
            tr.className = ('member-row ' + rankClass).trim();
            const badge = rank <= 3
                ? `<span class="rank-badge">${rank}</span>`
                : `<span style="color:#888;">${rank}</span>`;
            let cells = `<td class="rank-cell">${badge}</td><td><strong>${m.member_id}</strong></td>`;
            m.a.forEach((amt, yi) => {
                const isZero = amt === 0;
                const isCurrentYear = (yi === 0);
                const amtFmt = Number(amt).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
                const slipsFmt = m.c[yi] > 0 ? `<div class="yr-slips">${m.c[yi].toLocaleString()} สลิป</div>` : '';
                cells += `<td class="${isZero ? 'yr-amt-zero' : 'yr-amt'} ${isCurrentYear && !isZero ? 'yr-amt-cur' : ''}">
                    ${isZero ? '-' : amtFmt}${slipsFmt}
                </td>`;
            });
            tr.innerHTML = cells;
            tbody.appendChild(tr);
        });

        // Summary row
        const sumTr = document.createElement('tr');
        sumTr.className = 'yr-summary-row';
        let sumCells = `<td colspan="2" style="text-align:center;">รวมทั้งหมด (${Number(d.total_members).toLocaleString()} สมาชิก)</td>`;
        d.totals.forEach((tot, yi) => {
            sumCells += `<td style="text-align:right;">${Number(tot).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>`;
        });
        sumTr.innerHTML = sumCells;
        tbody.appendChild(sumTr);
    })
    .catch(e => {
        console.error(e);
        document.getElementById('yearly-tbody').innerHTML =
            `<tr><td colspan="7" class="error"><h2>ERROR</h2>${e.message}</td></tr>`;
    });
}

// แปลงวันที่ DD/MM/YYYY เป็น DD/MM (สั้น)
function fmtShortDateRange(start, end) {
    const s = start.substring(0,5);
    const e = end.substring(0,5);
    return s === e ? s : s + ' – ' + e;
}

function updateDashboard() {
    const searchType = document.getElementById('search_type').value;
    const curMode    = document.getElementById('mode-input').value;

    // ---- โหมด YEARLY ----
    if (searchType === 'yearly') {
        updateYearlyDashboard();
        return;
    }

    const p = new URLSearchParams();
    p.set('ajax', '1');
    p.set('search_type', searchType);
    p.set('mode', curMode);
    p.set('branch', (document.getElementById('branch-select')||{value:''}).value.trim());
    const memberFilter = document.getElementById('member_id_filter').value.trim();
    if (memberFilter) p.set('member_id_filter', memberFilter); else p.delete('member_id_filter');

    // ---- โหมด POINT ----
    if (searchType === 'point') {
        p.set('sort_point', document.getElementById('sort_point').value);
        const tbody = document.querySelector('#point-table tbody');
        tbody.innerHTML = `<tr><td colspan="11" style="text-align:center;padding:60px 20px;color:#cc88ff;font-size:20px;">
            <i class="fas fa-spinner fa-spin" style="font-size:40px;margin-bottom:15px;display:block;"></i>
            กำลังโหลดแต้มสะสม...</td></tr>`;
        fetch('?' + p.toString())
        .then(r => r.ok ? r.json() : Promise.reject(`HTTP ${r.status}`))
        .then(d => {
            if (d.error) throw new Error(d.error);
            document.getElementById('refresh-time').innerText = d.refresh_time;
            document.getElementById('date-range').innerText = 'แต้มสะสม' + (d.member_id_filter ? ' : ' + d.member_id_filter : ' : ทั้งหมด');
            const _sLabels={'balance_desc':'แต้มมากสุด','balance_asc':'แต้มน้อยสุด','member_id':'รหัสสมาชิก','last_date_desc':'วันที่รับล่าสุด','expiry_asc':'วันหมดอายุ'};
            const _sl=document.getElementById('sort-label-point');
            if(_sl) _sl.innerText=_sLabels[d.sort_point]||d.sort_point;
            document.getElementById('point-total-records').innerText = d.total_records.toLocaleString();
            if (d.all_member_count !== undefined) {
                document.getElementById('all-member-count-point').innerText = Number(d.all_member_count).toLocaleString();
            }
            if (d.last_created_member) {
                document.getElementById('last-created-member-point').innerText = d.last_created_member;
                document.getElementById('last-created-date-point').innerText   = d.last_created_date;
            }
            tbody.innerHTML = '';
            if (!d.points || d.points.length === 0) {
                const noDataMsg = d.member_id_filter
                    ? `<i class="fas fa-info-circle" style="margin-right:12px;"></i>สมาชิก <strong>${d.member_id_filter}</strong> ไม่มีข้อมูลแต้มสะสมในระบบ`
                    : `<i class="fas fa-database" style="margin-right:12px;"></i>ไม่พบข้อมูลแต้มสะสม`;
                tbody.innerHTML = `<tr><td colspan="11" style="text-align:center;padding:80px 20px;background:rgba(123,47,190,0.15);border:2px dashed #cc88ff;color:#cc88ff;font-size:24px;font-weight:bold;">${noDataMsg}</td></tr>`;
                return;
            }
            let totalBalance = 0;
            d.points.forEach((pt, i) => {
                totalBalance += pt.point_balance;
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="text-align:center;color:#888;">${i+1}</td>
                    <td style="color:#aaa;">${pt.site}</td>
                    <td><strong style="color:#cc88ff;">${pt.member_id}</strong></td>
                    <td style="text-align:center;color:#0ff;">${pt.point_type_code}</td>
                    <td style="text-align:center;color:#aaa;">${pt.saleoffice_code}</td>
                    <td class="pt-balance" style="text-align:right;">${Number(pt.point_balance).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
                    <td style="text-align:right;color:#4caf50;">${Number(pt.last_point_value).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
                    <td style="color:#ccc;font-size:12px;">${adToBe(pt.last_point_date)}</td>
                    <td class="pt-expiry">${adToBe(pt.expiry_date)}</td>
                    <td style="text-align:right;color:#aaa;">${Number(pt.point_bf_value).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
                    <td style="color:#888;font-size:12px;">${adToBe(pt.point_bf_date)}</td>`;
                tbody.appendChild(tr);
            });
            const sumTr = document.createElement('tr');
            sumTr.className = 'summary-row';
            sumTr.innerHTML = `<td colspan="5" style="text-align:center;">รวมทั้งหมด (${d.total_records.toLocaleString()} รายการ)</td>
                <td style="text-align:right;">${Number(totalBalance).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
                <td colspan="5"></td>`;
            tbody.appendChild(sumTr);
        })
        .catch(e => {
            console.error(e);
            document.querySelector('#point-table tbody').innerHTML =
                `<tr><td colspan="11" class="error"><h2>ERROR</h2>${e.message}</td></tr>`;
        });
        return;
    }

    // ---- โหมด PURCHASE ----
    p.set('start',   document.getElementById('start_date').value.trim());
    p.set('end',     document.getElementById('end_date').value.trim());
    p.set('top_n',   document.getElementById('top_n').value);
    p.set('sort_by', document.getElementById('sort_by').value);
    p.set('min_slips', document.getElementById('min_slips').value || '1');
    if (curMode === 'history') {
        // loading spinner
        document.querySelector('#member-table tbody').innerHTML =
            `<tr><td colspan="7" style="text-align:center;padding:60px 20px;color:#ff9800;font-size:20px;">
            <i class="fas fa-spinner fa-spin" style="font-size:40px;margin-bottom:15px;display:block;"></i>
            กำลังโหลดข้อมูล...</td></tr>`;
        document.getElementById('branchChart').style.display = 'none';
    }
    fetch('?' + p.toString())
    .then(r => r.ok ? r.json() : Promise.reject(`HTTP ${r.status}`))
    .then(d => {
        if (d.error) throw new Error(d.error);
        document.getElementById('refresh-time').innerText = d.refresh_time;
        const _mrt = document.getElementById('members-refresh-time'); if(_mrt) _mrt.innerText = d.refresh_time;
        document.getElementById('date-range').innerText = d.start_date + ' - ' + d.end_date;
        document.getElementById('total-amount').innerText = Number(d.total_amount).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
        document.getElementById('total-members').innerText = d.total_members;
        document.getElementById('total-slips').innerText = d.total_slips;
        document.getElementById('total-items').innerText = d.total_items;
        document.getElementById('top-n-display').innerText = d.top_n;
        const sortText = {amount:'ยอดเงิน',slips:'จำนวนสลิป',items:'จำนวนสินค้า',last_purchase:'ซื้อล่าสุด'}[d.sort_by] || 'ยอดเงิน';
        document.getElementById('sort-label').innerText = sortText;
        document.getElementById('total-members-card').innerText = Number(d.total_members || 0).toLocaleString();
        // suffix: แสดง "จาก N คน" เมื่อแสดงไม่ครบ (top_n < total ในช่วงเวลา)
        const suffix = document.getElementById('all-member-suffix');
        const cardFrom = document.getElementById('total-members-card-from');
        const totalInPeriod = d.total_in_period || 0;
        const fromText = (totalInPeriod > 0 && d.total_members < totalInPeriod)
            ? 'จาก ' + Number(totalInPeriod).toLocaleString()
            : '';
        if (suffix)   suffix.textContent   = fromText ? ' ' + fromText : '';
        if (cardFrom) cardFrom.textContent = fromText;
        if (d.all_member_count !== undefined) {
            document.getElementById('all-member-count').innerText = Number(d.all_member_count).toLocaleString();
        }
        if (d.last_created_member && d.last_created_member !== '-') {
            document.getElementById('last-created-member').innerText = d.last_created_member;
            document.getElementById('last-created-date').innerText   = d.last_created_date || '-';
        }
        const filteredLabels = d.chart_labels.filter((_, i) => d.chart_counts[i] > 0);
        const filteredCounts = d.chart_counts.filter(v => v > 0);
        if (filteredLabels && filteredLabels.length > 0) {
            const bg = filteredCounts.map((_, i) => {
                if (i === 0) return 'rgba(255,215,0,0.85)';
                if (i === 1) return 'rgba(192,192,192,0.8)';
                if (i === 2) return 'rgba(205,127,50,0.8)';
                return 'rgba(0,188,212,0.7)';
            });
            const bo = bg.map(c => c.replace(/0\.\d+\)/, '1)'));
            if (!memberChart) {
                const ctx = document.getElementById('branchChart').getContext('2d');
                memberChart = new Chart(ctx, {
                    type: 'bar',
                    data: { labels: filteredLabels, datasets: [{
                        label: 'จำนวนสมาชิก',
                        data: filteredCounts,
                        backgroundColor: bg, borderColor: bo, borderWidth: 2
                    }]},
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { display: false },
                            tooltip: { callbacks: { label: c => `สมาชิก ${c.raw.toLocaleString()} คน` } },
                            datalabels: {
                                color: '#fff', anchor: 'end', align: 'top',
                                font: { size: 12, weight: 'bold' },
                                formatter: (v, ctx) => {
                                    const rank = ctx.dataIndex + 1;
                                    const label = `สมาชิก ${v.toLocaleString()} คน`;
                                    return rank <= 3 ? `อันดับ ${rank}\n${label}` : label;
                                }
                            }
                        },
                        scales: {
                            x: { ticks: { color: '#0ff', font: { size: 11 } } },
                            y: { ticks: { color: '#0ff' }, title: { display: true, text: 'จำนวนสมาชิก (คน)', color: '#0ff' } }
                        }
                    },
                    plugins: [ChartDataLabels]
                });
            } else {
                memberChart.data.labels = filteredLabels;
                memberChart.data.datasets[0].data = filteredCounts;
                memberChart.data.datasets[0].backgroundColor = bg;
                memberChart.data.datasets[0].borderColor = bo;
                memberChart.update();
            }
        } else if (memberChart) {
            memberChart.data.labels = [];
            memberChart.data.datasets[0].data = [];
            memberChart.update();
            document.getElementById('branchChart').style.display = 'none';
        }

        const tbody = document.querySelector('#member-table tbody');
        tbody.innerHTML = '';
        if (d.members.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:80px 20px; background:rgba(139,0,0,0.2); border:2px dashed #ff6b6b; color:#ff6b6b; font-size:32px; font-weight:bold;"><i class="fas fa-inbox" style="margin-right:20px; font-size:40px;"></i>ไม่มีข้อมูลสมาชิกในช่วงวันที่ที่ระบุ</td></tr>';
            document.getElementById('branchChart').style.display = 'none';
            if (memberChart) { memberChart.data.labels = []; memberChart.data.datasets[0].data = []; memberChart.update(); }
            return;
        }
        document.getElementById('branchChart').style.display = filteredLabels && filteredLabels.length > 0 ? '' : 'none';
        d.members.forEach((m, i) => {
            const rank = i + 1;
            const rankClass = rank <= 3 ? `rank-${rank}` : '';
            const tr = document.createElement('tr');
            tr.className = ('member-row ' + rankClass).trim();
            tr.dataset.member = m.member_id;
            const badge = `<span class="rank-badge">${rank}</span>`;
            tr.innerHTML = `
                <td class="rank-cell">${badge}</td>
                <td><strong>${m.member_id}</strong></td>
                <td align="right">${m.slip_count.toLocaleString()}</td>
                <td align="right">${m.item_count.toLocaleString()}</td>
                <td align="right"><strong>${Number(m.total_amount).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</strong></td>
                <td>${m.last_purchase}</td>
                <td style="font-size:12px; max-width:250px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${m.branches}">${m.branches}</td>
            `;
            tbody.appendChild(tr);

            const detailTr = document.createElement('tr');
            detailTr.className = 'detail-row';
            detailTr.dataset.member = m.member_id;
            detailTr.innerHTML = '<td colspan="7"><div class="detail-box"><div class="detail-loading"><i class="fas fa-spinner fa-spin"></i> กำลังโหลดรายละเอียด...</div></div></td>';
            tbody.appendChild(detailTr);
            const box = detailTr.querySelector('.detail-box');

            const p2 = new URLSearchParams();
            p2.set('detail', '1');
            p2.set('mode', curMode);  // ส่ง mode เพื่อให้ PHP ใช้ SQL ที่ถูก
            p2.set('member_id', m.member_id);
            p2.set('start', document.getElementById('start_date').value.trim());
            p2.set('end',   document.getElementById('end_date').value.trim());

            fetch('?' + p2.toString())
                .then(r => r.json())
                .then(dd => {
                    if (dd.error) { box.innerHTML = `<div class="detail-error"><i class="fas fa-exclamation-triangle"></i> ${dd.error}</div>`; return; }
                    if (!dd.slips || dd.slips.length === 0) {
                        box.innerHTML = `<div style="color:#888; padding:8px 0; font-style:italic;">ไม่พบรายการในช่วงวันที่นี้</div>`;
                        return;
                    }
                    let totalAmt = 0, rows = '';
                    dd.slips.forEach((slip, si) => {
                        totalAmt += slip.amount;
                        const fmtAmt = Number(slip.amount).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
                        rows += `<tr class="slip-row">
                            <td colspan="2" style="color:#ffcc00;">สลิป ${si+1}/${dd.slips.length}: <strong>${slip.slip_no}</strong></td>
                            <td style="color:#0ff;">${slip.branch}</td>
                            <td style="color:#ccc;">${slip.date}</td>
                            <td class="td-amt">${fmtAmt}</td>
                        </tr>`;
                        if (slip.items.length === 0) {
                            rows += `<tr class="item-row"><td colspan="5" style="color:#888; text-align:center; font-style:italic;">ไม่มีรายการสินค้า</td></tr>`;
                        } else {
                            slip.items.forEach((it, ki) => {
                                const dispName = (it.name && it.name !== it.barcode && it.name !== '-') ? it.name : '';
                                rows += `<tr class="item-row">
                                    <td style="color:#888; text-align:center;">${ki+1}</td>
                                    <td style="font-size:12px;">${it.barcode}</td>
                                    <td style="color:#aef; font-size:12px;">${dispName}</td>
                                    <td class="td-num">${it.qty.toLocaleString()} ชิ้น &nbsp;×&nbsp; ${Number(it.unit_price).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
                                    <td class="td-amt">${Number(it.total).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
                                </tr>`;
                            });
                        }
                    });
                    const grandAmt = Number(totalAmt).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
                    box.innerHTML = `
                        <table class="detail-table">
                            <thead><tr>
                                <th style="width:40px;">#</th>
                                <th>บาร์โค้ด</th>
                                <th>สาขา / วันที่</th>
                                <th class="td-num">จำนวน × ราคา/ชิ้น</th>
                                <th class="td-num">ยอด (บาท)</th>
                            </tr></thead>
                            <tbody>
                                ${rows}
                                <tr class="slip-summary">
                                    <td colspan="4">รวมทั้งหมด (${dd.slips.length} สลิป)</td>
                                    <td>${grandAmt}</td>
                                </tr>
                            </tbody>
                        </table>`;
                })
                .catch(err => {
                    box.innerHTML = `<div class="detail-error"><i class="fas fa-exclamation-triangle"></i> ${err.message}</div>`;
                });
        });
        const s = document.createElement('tr');
        s.className = 'summary-row';
        s.innerHTML = `<td colspan="2" align="center">รวมทั้งหมด (${d.total_members} สมาชิก)</td>
            <td align="right">${d.total_slips.toLocaleString()}</td>
            <td align="right">${d.total_items.toLocaleString()}</td>
            <td align="right">${Number(d.total_amount).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
            <td colspan="2"></td>`;
        tbody.appendChild(s);
    })
    .catch(e => {
        console.error(e);
        document.querySelector('#member-table tbody').innerHTML = `<tr><td colspan="7" class="error"><h2>ERROR</h2>${e.message}</td></tr>`;
    });
}
let _autoRefreshTimer = null;

function _startAutoRefresh() {
    if (_autoRefreshTimer) { clearInterval(_autoRefreshTimer); _autoRefreshTimer = null; }
    _autoRefreshTimer = setInterval(function(){
        if (document.getElementById('search_type').value !== 'point' &&
            document.getElementById('search_type').value !== 'yearly' &&
            document.getElementById('mode-input').value !== 'history') {
            updateDashboard();
        }
    }, <?= (int)$pos_refresh_interval * 1000 ?>);
}

function _stopAutoRefresh() {
    if (_autoRefreshTimer) { clearInterval(_autoRefreshTimer); _autoRefreshTimer = null; }
}

<?php if (empty($errors)): ?>
const _initMode = document.getElementById('mode-input').value;
const _initType = document.getElementById('search_type').value;
if (_initType === 'yearly' || _initType === 'point') {
    document.getElementById('purchase-mode-row').style.display = 'none';
    document.getElementById('yearly-tbody').innerHTML =
        `<tr><td colspan="7" style="text-align:center;padding:60px 20px;color:#ffa500;font-size:20px;">
        <i class="fas fa-chart-line" style="font-size:40px;margin-bottom:15px;display:block;opacity:0.6;"></i>
        กรุณาเลือกช่วงวันที่แล้วกด <strong>ค้นหา</strong> เพื่อโหลดข้อมูลเปรียบเทียบ</td></tr>`;
    // point/yearly: ไม่ start auto-refresh
    _stopAutoRefresh();
} else if (_initMode === 'history' && _initType === 'purchase') {
    document.querySelector('#member-table tbody').innerHTML =
        `<tr><td colspan="7" style="text-align:center;padding:60px 20px;color:#ff9800;font-size:20px;">
        <i class="fas fa-history" style="font-size:40px;margin-bottom:15px;display:block;opacity:0.6;"></i>
        กรุณาเลือกช่วงวันที่แล้วกด <strong>ค้นหา</strong> เพื่อโหลดข้อมูล</td></tr>`;
    document.getElementById('branchChart').style.display = 'none';
    // history mode: ปิด auto-refresh
    _stopAutoRefresh();
} else {
    // today mode: โหลดทันที + เริ่ม auto-refresh
    updateDashboard();
    _startAutoRefresh();
}
<?php endif; ?>
// โหลด stat สมาชิกทั้งหมดทันทีเมื่อ page load (ไม่รอกดค้นหา)
function loadMemberStat() {
    fetch('?ajax=1&stat=member')
        .then(r => r.json())
        .then(d => {
            if (d.all_member_count !== undefined) {
                const elC = document.getElementById('all-member-count');
                if (elC) elC.innerText = Number(d.all_member_count).toLocaleString();
                const elP = document.getElementById('all-member-count-point');
                if (elP) elP.innerText = Number(d.all_member_count).toLocaleString();
            }
            if (d.last_created_member && d.last_created_member !== '-') {
                const elM  = document.getElementById('last-created-member');
                if (elM)  elM.innerText  = d.last_created_member;
                const elD  = document.getElementById('last-created-date');
                if (elD)  elD.innerText  = d.last_created_date || '-';
                const elMP = document.getElementById('last-created-member-point');
                if (elMP) elMP.innerText = d.last_created_member;
                const elDP = document.getElementById('last-created-date-point');
                if (elDP) elDP.innerText = d.last_created_date || '-';
            }
        })
        .catch(err => console.error('[MemberStat error]', err));
}
loadMemberStat();

document.getElementById('refresh-btn').addEventListener('click', updateDashboard);

$(function() {
    const isHist  = document.getElementById('mode-input').value === 'history';
    const isYearly = document.getElementById('search_type').value === 'yearly';
    const options = {
        dateFormat: 'dd/mm/yy',
        changeMonth: true,
        changeYear: true,
        maxDate: "today",
        minDate: (isHist || isYearly) ? new Date(2000,0,1) : 'today'
    };
    $("#start_date").datepicker(options);
    $("#end_date").datepicker(options);

    // ── icon click และ datepicker change: เปิดเฉพาะ history mode ──
    if (isHist) {
        $("#start-icon").on("click", () => $("#start_date").datepicker("show"));
        $("#end-icon").on("click",   () => $("#end_date").datepicker("show"));
        $("#start_date").on("change", function() { $("#end_date").datepicker("option", "minDate", this.value); });
        $("#end_date").on("change",   function() { $("#start_date").datepicker("option", "maxDate", this.value); });
        const today = new Date();
        const todayStr = ("0"+today.getDate()).slice(-2) + '/' + ("0"+(today.getMonth()+1)).slice(-2) + '/' + today.getFullYear();
        ["#start_date", "#end_date"].forEach(sel => {
            $(sel).on("dblclick", function() {
                $(this).val(todayStr);
                $(this).datepicker("setDate", todayStr);
                if (document.getElementById('search_type').value !== 'point') updateDashboard();
            });
        });
    } else {
        // today mode: ล็อควันที่เป็นวันนี้เสมอ
        const now = new Date();
        const todayStr = ("0"+now.getDate()).slice(-2) + '/' + ("0"+(now.getMonth()+1)).slice(-2) + '/' + now.getFullYear();
        if (document.getElementById('start_date')) document.getElementById('start_date').value = todayStr;
        if (document.getElementById('end_date'))   document.getElementById('end_date').value   = todayStr;
    }

    $("#sort_by").on("change", function() { updateDashboard(); });
    $("#member_id_filter").on("keypress", function(e) { if (e.which === 13) updateDashboard(); });
    $("input[name='min_slips']").on("keypress", function(e) { if (e.which === 13) updateDashboard(); });
});

// top-right expand/collapse: handled by POS_TOPRIGHT.php

</script>
</body>
</html>