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
$MENU_ACTIVE = 'sales';
require_once __DIR__ . '/POS_AUTH.php';
require_once __DIR__ . '/POS_SETTINGS.php';
pos_check_expiry(); // ล็อกถ้าบัญชีหมดอายุ
pos_guard('sales');

// เปิดการแสดงผล error ทั้งหมดสำหรับการ debug
ini_set('display_errors', 1);
error_reporting(E_ALL);
// ---------------------------
// CONFIG (โปรดตรวจสอบค่าเหล่านี้)
// ---------------------------
$instant_client_path = "/opt/oracle/instantclient_21_4";
$oracle_user = "system";
$oracle_pass = "system";
$oracle_tns = "CUBACKUP";
// ไฟล์ชั่วคราว (ใช้ uniqid เพื่อป้องกันการทับซ้อน)
$sql_file = sys_get_temp_dir() . "/POS_SALES_" . uniqid() . ".sql";
// ---------------------------
// CASHIER NAMES
// ---------------------------
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
// ---------------------------
// BRANCH IP
// ---------------------------
$ip_map = [
    'EDU'=>'161.200.156.106','SB'=>'161.200.172.135','SB2'=>'161.200.172.135','CB'=>'161.200.173.4',
    'JJ'=>'161.200.173.60','RB'=>'161.200.173.68','NU'=>'161.200.173.100','SUT'=>'161.200.173.132',
    'BUU'=>'161.200.173.164','RM'=>'161.200.173.196','UP'=>'161.200.173.228'
];
// ---------------------------
// HANDLE DATE FILTER
// ---------------------------
$mode          = ($_GET['mode'] ?? 'today') === 'history' ? 'history' : 'today';
$_yesterday    = date('d/m/Y', strtotime('-1 day'));
$start_date    = $_GET['start'] ?? ($mode === 'history' ? $_yesterday : date('d/m/Y'));
$end_date      = $_GET['end']   ?? ($mode === 'history' ? $_yesterday : date('d/m/Y'));
$branch_filter = trim($_GET['branch']     ?? '');
$time_start    = trim($_GET['time_start'] ?? '');
$time_end      = trim($_GET['time_end']   ?? '');
// ตรวจสอบรูปแบบวันที่
$start_ts = DateTime::createFromFormat('d/m/Y', $start_date);
$end_ts = DateTime::createFromFormat('d/m/Y', $end_date);
$errors = [];
if (!$start_ts || !$end_ts || $start_ts->format('d/m/Y') !== $start_date || $end_ts->format('d/m/Y') !== $end_date) {
    $errors[] = "รูปแบบวันที่ไม่ถูกต้อง (ใช้ วว/ดด/ปปปป เช่น 12/11/2025)";
} elseif ($start_ts > $end_ts) {
    $errors[] = "วันที่เริ่มต้องไม่เกินวันที่สิ้นสุด";
}
// ---------------------------
// AJAX REQUEST — โหมดรายงานย้อนหลัง (POS_SALE_HD)
// ---------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && $mode === 'history' && empty($errors)) {
    header('Content-Type: application/json');
    $instant_client_path = rtrim($instant_client_path, '/');
    $sqlplus_path = "{$instant_client_path}/sqlplus";
    if (!is_executable($sqlplus_path)) {
        echo json_encode(['error' => "SQL*Plus Not Found: {$sqlplus_path}"]);
        exit;
    }
    $cashier_map  = load_cashier_map($sqlplus_path, $oracle_user, $oracle_pass, $oracle_tns, $instant_client_path);
    $esc_branch   = str_replace("'", "''", $branch_filter);
    $esc_tstart   = preg_match('/^\d{2}:\d{2}$/', $time_start) ? $time_start : '';
    $esc_tend     = preg_match('/^\d{2}:\d{2}$/', $time_end)   ? $time_end   : '';

    // กรองสาขาตามสิทธิ์ USER_BRANCH_ACCESS
    $branch_access_clause = function_exists('pos_branch_sql') ? pos_branch_sql('h.SALE_OFFICE') : '1=1';

    $sql_content  = <<<SQL
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
DECLARE
    TYPE t_branch_rec IS RECORD (
        sale_office    VARCHAR2(20), office_name  VARCHAR2(100),
        member_slip    NUMBER,       member_amt   NUMBER,
        member_count   NUMBER,
        nonmember_slip NUMBER,       nonmember_amt NUMBER,
        last_date      DATE
    );
    TYPE t_branch_tab IS TABLE OF t_branch_rec;
    v_data t_branch_tab := t_branch_tab();
    v_total_member_slip    NUMBER := 0; v_total_member_amt     NUMBER := 0;
    v_total_member_count   NUMBER := 0;
    v_total_nonmember_slip NUMBER := 0; v_total_nonmember_amt  NUMBER := 0;
    v_max_date DATE;
    v_start  DATE := TO_DATE('$start_date', 'DD/MM/YYYY');
    v_end    DATE := TO_DATE('$end_date',   'DD/MM/YYYY') + 1;
    v_branch_filter VARCHAR2(20) := TRIM('$esc_branch');
    v_time_start    VARCHAR2(5)  := '$esc_tstart';
    v_time_end      VARCHAR2(5)  := '$esc_tend';
    TYPE t_off_tab  IS TABLE OF VARCHAR2(20);
    TYPE t_nm_tab   IS TABLE OF VARCHAR2(100);
    TYPE t_num_tab  IS TABLE OF NUMBER;
    TYPE t_date_tab IS TABLE OF DATE;
    v_offices t_off_tab; v_onames t_nm_tab;
    v_mslip   t_num_tab; v_mamt   t_num_tab; v_mcnt t_num_tab;
    v_nmslip  t_num_tab; v_nmamt  t_num_tab;
    v_lastdts t_date_tab;
BEGIN
    SELECT /*+ INDEX(h IDX_SALE_HD_CDATE_OFF) */
        TRIM(h.SALE_OFFICE),
        NVL(TRIM(o.OFFICE_NAME), TRIM(h.SALE_OFFICE)),
        COUNT(CASE WHEN h.MEMBER_ID IS NOT NULL AND h.MEMBER_ID != '-' AND h.MEMBER_ID != '0000000000000' THEN 1 END),
        NVL(SUM(CASE WHEN h.MEMBER_ID IS NOT NULL AND h.MEMBER_ID != '-' AND h.MEMBER_ID != '0000000000000' THEN h.GRAND_AMOUNT ELSE 0 END),0),
        COUNT(DISTINCT CASE WHEN h.MEMBER_ID IS NOT NULL AND h.MEMBER_ID != '-' AND h.MEMBER_ID != '0000000000000' THEN h.MEMBER_ID END),
        COUNT(CASE WHEN h.MEMBER_ID IS NULL OR h.MEMBER_ID = '-' OR h.MEMBER_ID = '0000000000000' THEN 1 END),
        NVL(SUM(CASE WHEN h.MEMBER_ID IS NULL OR h.MEMBER_ID = '-' OR h.MEMBER_ID = '0000000000000' THEN h.GRAND_AMOUNT ELSE 0 END),0),
        MAX(h.CREATE_DATE)
    BULK COLLECT INTO v_offices, v_onames, v_mslip, v_mamt, v_mcnt, v_nmslip, v_nmamt, v_lastdts
    FROM POS.POS_SALE_HD h
    LEFT JOIN POS.POS_SALE_OFFICE o ON TRIM(o.SALE_OFFICE) = TRIM(h.SALE_OFFICE)
    WHERE h.CREATE_DATE >= v_start AND h.CREATE_DATE < v_end
      AND h.SALE_OFFICE IS NOT NULL
      AND ({$branch_access_clause})
      AND (v_branch_filter IS NULL OR v_branch_filter = '' OR TRIM(h.SALE_OFFICE) = v_branch_filter)
      AND (v_time_start IS NULL OR v_time_start = '' OR TO_CHAR(h.CREATE_DATE,'HH24:MI') >= v_time_start)
      AND (v_time_end   IS NULL OR v_time_end   = '' OR TO_CHAR(h.CREATE_DATE,'HH24:MI') <= v_time_end)
    GROUP BY TRIM(h.SALE_OFFICE), TRIM(o.OFFICE_NAME)
    ORDER BY NVL(SUM(h.GRAND_AMOUNT),0) DESC;

    FOR i IN 1..v_offices.COUNT LOOP
        v_data.EXTEND;
        v_data(v_data.LAST).sale_office    := v_offices(i);
        v_data(v_data.LAST).office_name    := NVL(v_onames(i), v_offices(i));
        v_data(v_data.LAST).member_slip    := NVL(v_mslip(i),0);
        v_data(v_data.LAST).member_amt     := NVL(v_mamt(i),0);
        v_data(v_data.LAST).member_count   := NVL(v_mcnt(i),0);
        v_data(v_data.LAST).nonmember_slip := NVL(v_nmslip(i),0);
        v_data(v_data.LAST).nonmember_amt  := NVL(v_nmamt(i),0);
        v_data(v_data.LAST).last_date      := v_lastdts(i);
        v_total_member_slip    := v_total_member_slip    + NVL(v_mslip(i),0);
        v_total_member_amt     := v_total_member_amt     + NVL(v_mamt(i),0);
        v_total_member_count   := v_total_member_count   + NVL(v_mcnt(i),0);
        v_total_nonmember_slip := v_total_nonmember_slip + NVL(v_nmslip(i),0);
        v_total_nonmember_amt  := v_total_nonmember_amt  + NVL(v_nmamt(i),0);
        IF v_lastdts(i) IS NOT NULL THEN
            IF v_max_date IS NULL OR v_lastdts(i) > v_max_date THEN v_max_date := v_lastdts(i); END IF;
        END IF;
    END LOOP;
    FOR i IN 1..v_data.COUNT LOOP
        DECLARE
            v_diff_sec  NUMBER := ROUND((SYSDATE - NVL(v_data(i).last_date,SYSDATE))*86400);
            v_diff_text VARCHAR2(20) := '+'||LPAD(TRUNC(v_diff_sec/3600),2,'0')||':'||LPAD(TRUNC(MOD(v_diff_sec,3600)/60),2,'0')||':'||LPAD(MOD(v_diff_sec,60),2,'0');
            v_is_new VARCHAR2(1) := CASE WHEN v_data(i).last_date IS NOT NULL AND v_data(i).last_date=v_max_date THEN 'Y' ELSE 'N' END;
        BEGIN
            DBMS_OUTPUT.PUT_LINE('DATA|'||v_data(i).sale_office||'|'||v_data(i).office_name||'|N/A|'||
                TO_CHAR(v_data(i).member_slip)||'|'||TO_CHAR(v_data(i).member_amt,'FM999999999990.00')||'|'||
                TO_CHAR(v_data(i).nonmember_slip)||'|'||TO_CHAR(v_data(i).nonmember_amt,'FM999999999990.00')||'|'||
                NVL(TO_CHAR(v_data(i).last_date,'DD/MM/YYYY HH24:MI:SS'),'-')||'|'||v_diff_text||'|'||v_is_new||'|'||
                TO_CHAR(v_data(i).member_count));
        END;
    END LOOP;
    DBMS_OUTPUT.PUT_LINE('TOTAL|'||v_total_member_slip||'|'||TO_CHAR(v_total_member_amt,'FM999999999990.00')||'|'||v_total_nonmember_slip||'|'||TO_CHAR(v_total_nonmember_amt,'FM999999999990.00')||'|'||TO_CHAR(v_total_member_count));
EXCEPTION WHEN OTHERS THEN DBMS_OUTPUT.PUT_LINE('FATAL|'||SQLERRM);
END;
/
EXIT;
SQL;
    if (!file_put_contents($sql_file, $sql_content)) { echo json_encode(['error'=>'ไม่สามารถเขียนไฟล์ SQL']); exit; }
    $user_pass = escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}");
    $cmd = "env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s $user_pass @$sql_file 2>&1";
    $output = shell_exec($cmd);
    if (file_exists($sql_file)) @unlink($sql_file);
    if (empty(trim($output))) { echo json_encode(['error'=>'SQL*Plus ไม่มี output']); exit; }
    foreach (explode("\n", $output) as $_ln) {
        $_ln = trim($_ln);
        if ($_ln==='' || strpos($_ln,'|')!==false) continue;
        if (preg_match('/^(ORA-|SP2-)/', $_ln)) { echo json_encode(['error'=>$_ln]); exit; }
    }
    $lines = explode("\n", $output);
    $data = []; $total_member_slip=0; $total_member_amt=0; $total_member_count=0; $total_nonmember_slip=0; $total_nonmember_amt=0;
    foreach ($lines as $raw_line) {
        $line = trim($raw_line);
        if ($line==='') continue;
        if (strpos($line,'TOTAL|')===0) {
            $parts = explode('|',$line);
            if (count($parts)>=5) { $total_member_slip=(int)trim($parts[1]); $total_member_amt=(float)trim($parts[2]); $total_nonmember_slip=(int)trim($parts[3]); $total_nonmember_amt=(float)trim($parts[4]); }
            if (count($parts)>=6) { $total_member_count=(int)trim($parts[5]); }
            continue;
        }
        if (strpos($line,'DATA|')!==0) continue;
        $parts = explode('|',$line);
        if (count($parts)<11) continue;
        $branch=$parts[1]; $office_name=$parts[2]; $ip=$parts[3];
        $data[trim($branch)] = ['branch'=>trim($branch),'office_name'=>trim($office_name),'ip'=>trim($ip),
            'member_slip'=>(int)trim($parts[4]),'member_amt'=>(float)trim($parts[5]),
            'nonmember_slip'=>(int)trim($parts[6]),'nonmember_amt'=>(float)trim($parts[7]),
            'lastsale'=>trim($parts[8]),'lastsale_ts'=>strtotime(str_replace('/','-',trim($parts[8]))),
            'diff'=>trim($parts[9]),'is_new'=>trim($parts[10])==='Y',
            'member_count'=>isset($parts[11]) ? (int)trim($parts[11]) : 0];
    }
    // กรองสาขาตามสิทธิ์ USER_BRANCH_ACCESS
    if (function_exists('pos_get_branches') && pos_get_branches() !== null) {
        $data = array_filter($data, fn($b) => pos_can_see_branch($b['branch']));
    }
    // กรองตาม branch_filter ที่เลือก
    if ($branch_filter !== '') {
        $data = array_filter($data, fn($b) => $b['branch'] === $branch_filter);
    }
    // คำนวณยอดรวมใหม่เสมอ
    $total_member_slip = $total_member_amt = $total_member_count = $total_nonmember_slip = $total_nonmember_amt = 0;
    foreach ($data as $b) {
        $total_member_slip    += $b['member_slip'];
        $total_member_amt     += $b['member_amt'];
        $total_member_count   += $b['member_count'];
        $total_nonmember_slip += $b['nonmember_slip'];
        $total_nonmember_amt  += $b['nonmember_amt'];
    }
    usort($data, function($a,$b){ $ta=$a['member_amt']+$a['nonmember_amt']; $tb=$b['member_amt']+$b['nonmember_amt']; return $tb<=>$ta?:($b['lastsale_ts']<=>$a['lastsale_ts']); });
    foreach ($data as $idx => &$br) { $br['rank']=$idx+1; } unset($br);
    $online=0; $no_data=0;
    foreach ($data as $b) { if($b['member_slip']+$b['nonmember_slip']>0) $online++; else $no_data++; }
    $chart_labels=[]; $chart_member=[]; $chart_nonmember=[];
    foreach ($data as $b) {
        $chart_labels[] = ($b['office_name']&&$b['office_name']!==$b['branch']) ? $b['office_name'].' ('.$b['branch'].')' : $b['branch'];
        $chart_member[]    = round($b['member_amt'],2);
        $chart_nonmember[] = round($b['nonmember_amt'],2);
    }
    echo json_encode(['refresh_time'=>date('d/m/Y H:i:s'),'mode'=>'history','start_date'=>$start_date,'end_date'=>$end_date,
        'branch_filter'=>$branch_filter,'time_start'=>$time_start,'time_end'=>$time_end,
        'online_machines'=>$online,'no_data_count'=>$no_data,
        'total_member_slip'=>$total_member_slip,'total_member_amt'=>$total_member_amt,
        'total_member_count'=>$total_member_count,
        'total_nonmember_slip'=>$total_nonmember_slip,'total_nonmember_amt'=>$total_nonmember_amt,
        'chart_labels'=>$chart_labels,'chart_member'=>$chart_member,'chart_nonmember'=>$chart_nonmember,
        'branches'=>array_values($data)]);
    exit;
}

// ---------------------------
// AJAX REQUEST — โหมดวันนี้ (POS_SALETODAY_HD_*)
// ---------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && empty($errors)) {
    header('Content-Type: application/json');
    $instant_client_path = rtrim($instant_client_path, '/');
    $sqlplus_path = "{$instant_client_path}/sqlplus";
    if (!is_executable($sqlplus_path)) {
        echo json_encode(['error' => "SQL*Plus Not Found: {$sqlplus_path}", 'output' => '']);
        exit;
    }
    // โหลดชื่อแคชเชียร์จาก POS.SK_USER
    $cashier_map = load_cashier_map($sqlplus_path, $oracle_user, $oracle_pass, $oracle_tns, $instant_client_path);
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
EXEC :start_date := '$start_date';
EXEC :end_date := '$end_date';
DECLARE
    TYPE t_branch_rec IS RECORD (
        branch VARCHAR2(100), office_name VARCHAR2(100), member_slip NUMBER, member_amt NUMBER,
        member_count NUMBER,
        nonmember_slip NUMBER, nonmember_amt NUMBER,
        last_date DATE, ip VARCHAR2(50), sale_office VARCHAR2(20)
    );
    TYPE t_branch_tab IS TABLE OF t_branch_rec;
    TYPE t_ip_map IS TABLE OF VARCHAR2(50) INDEX BY VARCHAR2(100);
    v_ip_map t_ip_map;
    v_data t_branch_tab := t_branch_tab();
    v_total_member_slip NUMBER := 0; v_total_member_amt NUMBER := 0;
    v_total_member_count NUMBER := 0;
    v_total_nonmember_slip NUMBER := 0; v_total_nonmember_amt NUMBER := 0;
    v_max_date DATE;
    CURSOR c_tables IS
        SELECT table_name
        FROM all_tables
        WHERE owner = 'POS'
          AND table_name LIKE 'POS_SALETODAY_HD_%'
        ORDER BY table_name;
    FUNCTION to_date_str(p_date_str VARCHAR2) RETURN DATE IS
    BEGIN
        RETURN TO_DATE(p_date_str, 'DD/MM/YYYY');
    END;
BEGIN
    v_ip_map('EDU') := '161.200.156.106'; v_ip_map('SB') := '161.200.172.135'; v_ip_map('SB2') := '161.200.172.135';
    v_ip_map('CB') := '161.200.173.4'; v_ip_map('JJ') := '161.200.173.60'; v_ip_map('RB') := '161.200.173.68';
    v_ip_map('NU') := '161.200.173.100'; v_ip_map('SUT') := '161.200.173.132'; v_ip_map('BUU') := '161.200.173.164';
    v_ip_map('RM') := '161.200.173.196'; v_ip_map('UP') := '161.200.173.228';
    DBMS_OUTPUT.PUT_LINE('Date Range: ' || :start_date || ' to ' || :end_date);
    DBMS_OUTPUT.PUT_LINE('Time ' || TO_CHAR(SYSDATE, 'HH24:MI:SS'));
    DBMS_OUTPUT.PUT_LINE('=== Refresh count: 15 sec ===');
    DBMS_OUTPUT.PUT_LINE(RPAD('-',140,'-'));
    DBMS_OUTPUT.PUT_LINE('Branch(IP)          Member Slip  Member Amt     NonMember Slip  NonMember Amt  Last Sale Date Diff');
    DBMS_OUTPUT.PUT_LINE(RPAD('-',140,'-'));
    FOR rec IN c_tables LOOP
        DECLARE
            v_branch           VARCHAR2(100) := REPLACE(rec.table_name,'POS_SALETODAY_HD_','');
            v_oname            VARCHAR2(100) := v_branch;
            v_sale_office_code VARCHAR2(10);
            v_member_slip NUMBER; v_member_amt NUMBER; v_nonmember_slip NUMBER; v_nonmember_amt NUMBER;
            v_member_count NUMBER;
            v_last DATE;
            v_start DATE := to_date_str(:start_date);
            v_end DATE := to_date_str(:end_date) + 1 - 1/86400;
        BEGIN
            IF v_branch NOT IN ('_TMP','TEST99') THEN
                -- ดึง SALE_OFFICE code จากข้อมูลใน HD table แล้วนำไป JOIN กับ POS_SALE_OFFICE
                BEGIN
                    EXECUTE IMMEDIATE
                        'SELECT TRIM(SALE_OFFICE) FROM POS.' || rec.table_name ||
                        ' WHERE SALE_OFFICE IS NOT NULL AND TRIM(SALE_OFFICE) IS NOT NULL AND ROWNUM=1'
                    INTO v_sale_office_code;

                    SELECT NVL(TRIM(OFFICE_NAME), v_branch) INTO v_oname
                    FROM POS.POS_SALE_OFFICE
                    WHERE TRIM(SALE_OFFICE) = v_sale_office_code
                      AND ROWNUM = 1;

                    IF v_oname IS NULL OR TRIM(v_oname) = '' THEN
                        v_oname := v_branch;
                    END IF;
                EXCEPTION
                    WHEN NO_DATA_FOUND THEN v_oname := v_branch;
                    WHEN OTHERS        THEN v_oname := v_branch;
                END;
                BEGIN
                    EXECUTE IMMEDIATE '
                        SELECT COUNT(CASE WHEN MEMBER_ID IS NOT NULL AND MEMBER_ID != ''-'' THEN 1 END),
                               SUM(CASE WHEN MEMBER_ID IS NOT NULL AND MEMBER_ID != ''-'' THEN GRAND_AMOUNT ELSE 0 END),
                               COUNT(DISTINCT CASE WHEN MEMBER_ID IS NOT NULL AND MEMBER_ID != ''-'' THEN MEMBER_ID END),
                               COUNT(CASE WHEN MEMBER_ID IS NULL OR MEMBER_ID = ''-'' THEN 1 END),
                               SUM(CASE WHEN MEMBER_ID IS NULL OR MEMBER_ID = ''-'' THEN GRAND_AMOUNT ELSE 0 END),
                               MAX(CREATE_DATE)
                        FROM POS.' || rec.table_name || '
                        WHERE CREATE_DATE >= :1 AND CREATE_DATE < :2'
                        INTO v_member_slip, v_member_amt, v_member_count, v_nonmember_slip, v_nonmember_amt, v_last
                        USING v_start, v_end;
                EXCEPTION WHEN OTHERS THEN
                    v_member_slip := 0; v_member_amt := 0; v_member_count := 0; v_nonmember_slip := 0; v_nonmember_amt := 0; v_last := NULL;
                END;
                v_data.EXTEND;
                v_data(v_data.LAST).branch := v_branch;
                v_data(v_data.LAST).office_name := v_oname;
                v_data(v_data.LAST).sale_office := NVL(v_sale_office_code, v_branch);
                v_data(v_data.LAST).member_slip := v_member_slip;
                v_data(v_data.LAST).member_amt := v_member_amt;
                v_data(v_data.LAST).member_count := NVL(v_member_count, 0);
                v_data(v_data.LAST).nonmember_slip := v_nonmember_slip;
                v_data(v_data.LAST).nonmember_amt := v_nonmember_amt;
                v_data(v_data.LAST).last_date := v_last;
                v_data(v_data.LAST).ip := NVL(v_ip_map(v_branch),'N/A');
                v_total_member_slip := v_total_member_slip + NVL(v_member_slip,0);
                v_total_member_amt := v_total_member_amt + NVL(v_member_amt,0);
                v_total_member_count := v_total_member_count + NVL(v_member_count,0);
                v_total_nonmember_slip := v_total_nonmember_slip + NVL(v_nonmember_slip,0);
                v_total_nonmember_amt := v_total_nonmember_amt + NVL(v_nonmember_amt,0);
            END IF;
        EXCEPTION WHEN OTHERS THEN NULL;
        END;
    END LOOP;
    FOR i IN 1 .. v_data.COUNT LOOP
        IF v_data(i).last_date IS NOT NULL AND (v_max_date IS NULL OR v_data(i).last_date > v_max_date) THEN
            v_max_date := v_data(i).last_date;
        END IF;
    END LOOP;
    FOR i IN 1 .. v_data.COUNT - 1 LOOP
        FOR j IN i + 1 .. v_data.COUNT LOOP
            IF NVL(v_data(i).member_amt + v_data(i).nonmember_amt,0) < NVL(v_data(j).member_amt + v_data(j).nonmember_amt,0)
                OR (NVL(v_data(i).member_amt + v_data(i).nonmember_amt,0) = NVL(v_data(j).member_amt + v_data(j).nonmember_amt,0)
                    AND NVL(v_data(i).last_date,DATE '1900-01-01') < NVL(v_data(j).last_date,DATE '1900-01-01'))
            THEN
                DECLARE v_tmp t_branch_rec; BEGIN
                    v_tmp := v_data(i);
                    v_data(i) := v_data(j);
                    v_data(j) := v_tmp;
                END;
            END IF;
        END LOOP;
    END LOOP;
    FOR i IN 1 .. v_data.COUNT LOOP
        DECLARE
            v_diff_sec NUMBER := ROUND((SYSDATE - NVL(v_data(i).last_date, SYSDATE)) * 86400);
            v_diff_text VARCHAR2(20) := ' +' || LPAD(TRUNC(v_diff_sec/3600),2,'0') || ':' ||
                                         LPAD(TRUNC(MOD(v_diff_sec,3600)/60),2,'0') || ':' ||
                                         LPAD(MOD(v_diff_sec,60),2,'0');
            v_mark VARCHAR2(5) := CASE WHEN v_data(i).last_date = v_max_date THEN ' New' ELSE '' END;
            v_total_slip NUMBER := v_data(i).member_slip + v_data(i).nonmember_slip;
        BEGIN
            IF v_total_slip = 0 THEN
                DBMS_OUTPUT.PUT_LINE('DATA|'||v_data(i).branch||'|'||v_data(i).office_name||'|'||v_data(i).ip||'|0|0|0|0|-|'||v_diff_text||'|N|'||NVL(v_data(i).sale_office,v_data(i).branch)||'|0');
            ELSE
                DBMS_OUTPUT.PUT_LINE(
                    'DATA|'||
                    v_data(i).branch||'|'||
                    v_data(i).office_name||'|'||
                    v_data(i).ip||'|'||
                    TO_CHAR(v_data(i).member_slip)||'|'||
                    TO_CHAR(v_data(i).member_amt,'FM999999999990.00')||'|'||
                    TO_CHAR(v_data(i).nonmember_slip)||'|'||
                    TO_CHAR(v_data(i).nonmember_amt,'FM999999999990.00')||'|'||
                    NVL(TO_CHAR(v_data(i).last_date,'DD/MM/YYYY HH24:MI:SS'),'-')||'|'||
                    v_diff_text||'|'||
                    CASE WHEN v_mark LIKE '% New%' THEN 'Y' ELSE 'N' END||'|'||
                    NVL(v_data(i).sale_office,v_data(i).branch)||'|'||
                    TO_CHAR(v_data(i).member_count)
                );
            END IF;
        END;
    END LOOP;
    DBMS_OUTPUT.PUT_LINE(RPAD('-',140,'-'));
    DBMS_OUTPUT.PUT_LINE(
        'TOTAL|'||
        TO_CHAR(v_total_member_slip)||'|'||
        TO_CHAR(v_total_member_amt,'FM999999999990.00')||'|'||
        TO_CHAR(v_total_nonmember_slip)||'|'||
        TO_CHAR(v_total_nonmember_amt,'FM999999999990.00')||'|'||
        TO_CHAR(v_total_member_count)
    );
    DBMS_OUTPUT.PUT_LINE(RPAD('-',140,'-'));
    DBMS_OUTPUT.PUT_LINE('Report generated at ' || TO_CHAR(SYSDATE,'DD/MM/YYYY HH24:MI:SS'));
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
    if (file_exists($sql_file)) @unlink($sql_file);
    if (empty(trim($output))) {
        echo json_encode(['error' => 'SQL*Plus ไม่มี output', 'output' => '']);
        exit;
    }
    foreach (explode("\n", $output) as $_ln) {
        $_ln = trim($_ln);
        if ($_ln === '' || strpos($_ln, '|') !== false) continue;
        if (preg_match('/^(ORA-|SP2-)/', $_ln)) {
            echo json_encode(['error' => $_ln, 'output' => $output]);
            exit;
        }
    }
    // Parse output — รูปแบบ: DATA|branch|office_name|ip|member_slip|member_amt|nonmember_slip|nonmember_amt|lastsale|diff|is_new|sale_office|member_count
    $lines = explode("\n", $output);
    $data = []; $total_member_slip = 0; $total_member_amt = 0; $total_member_count = 0; $total_nonmember_slip = 0; $total_nonmember_amt = 0;
    foreach ($lines as $raw_line) {
        $line = trim($raw_line);
        if ($line === '') continue;
        if (strpos($line, 'TOTAL|') === 0) {
            $parts = explode('|', $line);
            if (count($parts) >= 5) {
                $total_member_slip    = (int)   trim($parts[1]);
                $total_member_amt     = (float) trim($parts[2]);
                $total_nonmember_slip = (int)   trim($parts[3]);
                $total_nonmember_amt  = (float) trim($parts[4]);
            }
            if (count($parts) >= 6) { $total_member_count = (int)trim($parts[5]); }
            continue;
        }
        if (strpos($line, 'DATA|') !== 0) continue;
        $parts = explode('|', $line);
        if (count($parts) < 11) continue;
        $branch         = trim($parts[1]);
        $office_name    = trim($parts[2]);
        $ip             = trim($parts[3]);
        $member_slip    = (int)   trim($parts[4]);
        $member_amt     = (float) trim($parts[5]);
        $nonmember_slip = (int)   trim($parts[6]);
        $nonmember_amt  = (float) trim($parts[7]);
        $lastsale       = trim($parts[8]);
        $diff           = trim($parts[9]);
        $is_new         = trim($parts[10]) === 'Y';
        $sale_office    = isset($parts[11]) ? trim($parts[11]) : $branch;
        $member_count   = isset($parts[12]) ? (int)trim($parts[12]) : 0;
        $data[$branch] = [
            'branch' => $sale_office,
            'office_name' => $office_name, 'ip' => $ip,
            'member_slip' => $member_slip, 'member_amt' => $member_amt,
            'member_count' => $member_count,
            'nonmember_slip' => $nonmember_slip, 'nonmember_amt' => $nonmember_amt,
            'lastsale' => $lastsale, 'lastsale_ts' => strtotime(str_replace('/', '-', $lastsale)),
            'diff' => $diff, 'is_new' => $is_new
        ];
    }
    foreach ($ip_map as $branch => $ip) {
        if (!isset($data[$branch])) {
            $data[$branch] = [
                'branch' => $branch, 'office_name' => $branch, 'ip' => $ip,
                'member_slip' => 0, 'member_amt' => 0, 'member_count' => 0,
                'nonmember_slip' => 0, 'nonmember_amt' => 0,
                'lastsale' => '-', 'lastsale_ts' => 0, 'diff' => '', 'is_new' => false
            ];
        }
    }
    // กรองสาขาตามสิทธิ์ USER_BRANCH_ACCESS (SALETODAY dynamic tables)
    if (function_exists('pos_get_branches') && pos_get_branches() !== null) {
        $data = array_filter($data, fn($b) => pos_can_see_branch($b['branch']));
        $total_member_slip = $total_member_amt = $total_member_count = $total_nonmember_slip = $total_nonmember_amt = 0;
        foreach ($data as $b) {
            $total_member_slip    += $b['member_slip'];
            $total_member_amt     += $b['member_amt'];
            $total_member_count   += $b['member_count'];
            $total_nonmember_slip += $b['nonmember_slip'];
            $total_nonmember_amt  += $b['nonmember_amt'];
        }
    }
    usort($data, function($a, $b) {
        $ta = $a['member_amt'] + $a['nonmember_amt'];
        $tb = $b['member_amt'] + $b['nonmember_amt'];
        return $tb <=> $ta ?: ($b['lastsale_ts'] <=> $a['lastsale_ts']);
    });
    foreach ($data as $idx => &$branch) { $branch['rank'] = $idx + 1; }
    unset($branch);
    $online = 0; $no_data = 0;
    foreach ($data as $b) {
        if ($b['member_slip'] + $b['nonmember_slip'] > 0) $online++; else $no_data++;
    }
    $chart_labels = []; $chart_member = []; $chart_nonmember = [];
    foreach ($data as $b) {
        $label = ($b['office_name'] && $b['office_name'] !== $b['branch'])
                 ? $b['office_name'] . ' (' . $b['branch'] . ')'
                 : $b['branch'];
        $chart_labels[] = $label;
        $chart_member[] = round($b['member_amt'], 2);
        $chart_nonmember[] = round($b['nonmember_amt'], 2);
    }
    $refresh_time = date('d/m/Y H:i:s');
    echo json_encode([
        'refresh_time' => $refresh_time,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'online_machines' => $online,
        'no_data_count' => $no_data,
        'total_member_slip' => $total_member_slip,
        'total_member_amt' => $total_member_amt,
        'total_member_count' => $total_member_count,
        'total_nonmember_slip' => $total_nonmember_slip,
        'total_nonmember_amt' => $total_nonmember_amt,
        'chart_labels' => $chart_labels,
        'chart_member' => $chart_member,
        'chart_nonmember' => $chart_nonmember,
        'branches' => array_values($data)
    ]);
    exit;
}
?>
<?php
// โหลดรายชื่อสาขาสำหรับ dropdown ย้อนหลัง
$office_list_today   = [];
$office_list_history = [];
try {
    $sp_ol = rtrim($instant_client_path, '/') . '/sqlplus';
    $up_ol = escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}");
    // TODAY: PL/SQL loop จาก POS_SALETODAY_HD_* (เหมือน POS_ITEMS.php — แสดงเฉพาะสาขาที่มี SALE_OFFICE code จริง)
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
            EXECUTE IMMEDIATE
                'SELECT TRIM(SALE_OFFICE) FROM POS.' || rec.table_name ||
                ' WHERE SALE_OFFICE IS NOT NULL AND TRIM(SALE_OFFICE) IS NOT NULL AND ROWNUM=1'
            INTO v_code;
        EXCEPTION WHEN OTHERS THEN v_code := NULL; END;
        -- แสดงเฉพาะสาขาที่มี SALE_OFFICE code จริง (เหมือน POS_ITEMS.php)
        IF v_code IS NOT NULL THEN
            BEGIN
                SELECT NVL(TRIM(OFFICE_NAME), v_branch) INTO v_oname
                FROM POS.POS_SALE_OFFICE
                WHERE TRIM(SALE_OFFICE) = v_code AND ROWNUM = 1;
                IF v_oname IS NULL OR TRIM(v_oname) = '' THEN v_oname := v_branch; END IF;
            EXCEPTION WHEN OTHERS THEN v_oname := v_branch; END;
            DBMS_OUTPUT.PUT_LINE(v_code || '|' || v_oname);
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
        $p=explode('|',$ln,2); if(count($p)===2&&$p[0]!=='') {
            $c=trim($p[0]);
            if(!function_exists('pos_can_see_branch')||pos_can_see_branch($c))
                $office_list_today[$c]=trim($p[1]); }
    }
    // HISTORY: จาก POS_SALE_OFFICE
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
        $p=explode('|',$ln,2); if(count($p)===2&&$p[0]!=='') {
            $c=trim($p[0]);
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
<title>การซื้อ: สมาชิก vs ไม่เป็นสมาชิก</title>
<link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: "Consolas", "Tahoma", sans-serif;
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
.container { max-width: 1400px; margin: 0 auto; }
.filter-section {
    background: rgba(0,0,0,0.4);
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 30px;
    border: 2px solid #0ff;
    box-shadow: 0 0 30px rgba(0,255,255,0.2);
}
.form-group {
    margin: 10px 5px;
    display: inline-block;
    position: relative;
}
label {
    color: #0ff;
    margin-right: 8px;
    font-weight: bold;
    font-size: 14px;
}
input[type=text], select {
    padding: 10px 12px;
    border-radius: 6px;
    border: 2px solid #0ff;
    background: #0a0a0a;
    color: #0ff;
    font-family: Consolas, monospace;
    font-size: 14px;
    transition: all 0.3s;
}
input[type=text]:focus, select:focus {
    outline: none;
    box-shadow: 0 0 15px rgba(0,255,255,0.5);
    border-color: #00ffff;
}
.date-icon {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #0ff;
    cursor: pointer;
    font-size: 16px;
}
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
.error {
    color: #ff6b6b;
    background: rgba(255,0,0,0.1);
    padding: 15px;
    border-radius: 8px;
    margin: 15px 0;
    border: 2px solid #ff6b6b;
}
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
.stat-card:hover::before {
    left: 100%;
}
.stat-icon {
    font-size: 36px;
    color: #0ff;
    margin-bottom: 15px;
    text-shadow: 0 0 20px rgba(0,255,255,0.6);
}
.stat-label {
    color: #0ff;
    font-size: 14px;
    margin-bottom: 12px;
    text-transform: uppercase;
    letter-spacing: 1px;
}
.stat-value {
    color: #fff;
    font-size: 36px;
    font-weight: bold;
    text-shadow: 0 0 15px rgba(255,255,255,0.5);
}
canvas {
    background: rgba(0,0,0,0.4);
    border-radius: 12px;
    margin: 30px 0;
    border: 2px solid #0ff;
    box-shadow: 0 0 30px rgba(0,255,255,0.2);
    padding: 10px;
}
table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 30px;
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
tbody tr {
    transition: all 0.3s;
}
tbody tr:nth-child(odd) {
    background: rgba(255,255,255,0.03);
}
tbody tr:hover {
    background: rgba(0,255,255,0.15);
    transform: scale(1.01);
    cursor: pointer;
}
.rank-badge {
    display: inline-block;
    width: 32px;
    height: 32px;
    line-height: 32px;
    border-radius: 50%;
    font-weight: bold;
    font-size: 15px;
    text-align: center;
    margin-right: 8px;
    box-shadow: 0 0 18px rgba(255,255,255,0.6);
    animation: pulse 2s infinite;
}
.rank-1 .rank-badge { background: linear-gradient(135deg, #ffd700, #ffb800); color: #8B4513; text-shadow: 0 0 10px rgba(255,215,0,0.9); }
.rank-2 .rank-badge { background: linear-gradient(135deg, #c0c0c0, #a8a8a8); color: #4a4a4a; text-shadow: 0 0 10px rgba(192,192,192,0.9); }
.rank-3 .rank-badge { background: linear-gradient(135deg, #cd7f32, #b87333); color: #fff; text-shadow: 0 0 10px rgba(205,127,50,0.9); }
@keyframes pulse { 0% { box-shadow: 0 0 18px rgba(255,255,255,0.6); } 50% { box-shadow: 0 0 28px rgba(255,255,255,0.9); } 100% { box-shadow: 0 0 18px rgba(255,255,255,0.6); } }
.percentage { font-size: 12px; color: #ffcc00; margin-left: 8px; font-weight: normal; }
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
.ui-datepicker { z-index: 9999 !important; }
.loading { text-align: center; padding: 50px; font-size: 18px; color: #0ff; }
.loading i { font-size: 48px; animation: spin 1s linear infinite; }
@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
@media (max-width: 768px) { .stats-grid { grid-template-columns: 1fr; } table { font-size: 12px; } th, td { padding: 10px; } }
.member-bg { background-color: rgba(0,150,0,0.3); }
.nonmember-bg { background-color: rgba(150,0,0,0.3); }
.no-data { background-color:#330000; color:#ff8888; font-weight:bold; text-align:center; }
</style>
</head>
<body>
<?php pos_expiry_banner(); ?>
<?php $MENU_ACTIVE = 'sales'; require_once 'POS_MENU.php'; ?>
<?php $pos_topright_show_online = true; require_once __DIR__ . '/POS_TOPRIGHT.php'; ?>
<h1><i class="fas fa-users"></i> การซื้อ: สมาชิก vs ไม่เป็นสมาชิก</h1>
<?php pos_nav_buttons($pos_priority, $MENU_ACTIVE); ?>

</div>
<div class="filter-section">
<form method="GET" id="filter-form" style="text-align:center;">
<input type="hidden" name="mode" id="mode-input" value="<?= htmlspecialchars($mode) ?>">
    <!-- ปุ่มโหมด -->
    <div style="margin-bottom:12px;">
        <label style="color:#ffcc00;font-size:15px;margin-right:12px;"><i class="fas fa-toggle-on"></i> โหมดข้อมูล:</label>
        <button type="button" id="mode-today-btn" onclick="setMode('today')"
            style="padding:9px 22px;border-radius:6px;border:2px solid #0ff;cursor:pointer;font-weight:bold;font-size:13px;
                   background:<?= $mode==='today'?'#0ff':'transparent' ?>;color:<?= $mode==='today'?'#000':'#0ff' ?>;">
            <i class="fas fa-chart-bar"></i> วันนี้
        </button>
        <button type="button" id="mode-history-btn" onclick="setMode('history')"
            style="padding:9px 22px;border-radius:6px;border:2px solid #ff9800;cursor:pointer;font-weight:bold;font-size:13px;margin-left:8px;
                   background:<?= $mode==='history'?'#ff9800':'transparent' ?>;color:<?= $mode==='history'?'#fff':'#ff9800' ?>;">
            <i class="fas fa-history"></i> รายงานย้อนหลัง
        </button>
    </div>
    <!-- เงื่อนไขทั้งหมดบรรทัดเดียว -->
    <div style="display:flex;flex-wrap:wrap;justify-content:center;align-items:center;gap:8px;">
        <div class="form-group" style="margin:0;">
            <label>เริ่มต้น:</label>
            <input type="text" name="start" id="start_date" value="<?=htmlspecialchars($start_date)?>" placeholder="วว/ดด/ปปปป" autocomplete="off" required readonly style="cursor:pointer;">
            <i class="fas fa-calendar-alt date-icon" id="start-icon"></i>
        </div>
        <div class="form-group" style="margin:0;">
            <label>สิ้นสุด:</label>
            <input type="text" name="end" id="end_date" value="<?=htmlspecialchars($end_date)?>" placeholder="วว/ดด/ปปปป" autocomplete="off" required readonly style="cursor:pointer;">
            <i class="fas fa-calendar-alt date-icon" id="end-icon"></i>
        </div>
        <!-- history-only filters (inline, ใช้ contents เพื่ออยู่บรรทัดเดียวกัน) -->
        <div class="form-group" style="margin:0;">
            <label>สาขา:</label>
            <select name="branch" id="branch-select" style="min-width:160px;cursor:pointer;">
                <option value="">— ทุกสาขา —</option>
                <?php foreach ($office_list as $code => $name): ?>
                <option value="<?=htmlspecialchars($code)?>" <?=$branch_filter===$code?'selected':''?>>
                    <?=htmlspecialchars($name)?> (<?=htmlspecialchars($code)?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div id="hist-filters" style="display:<?= $mode==='history'?'contents':'none' ?>;">
            <div class="form-group" style="margin:0;">
                <label>ช่วงเวลา:</label>
                <input type="text" name="time_start" id="time-start" value="<?=htmlspecialchars($time_start)?>" placeholder="00:00" maxlength="5" style="width:75px;text-align:center;" oninput="fmtTime(this)" autocomplete="off">
                <span style="color:#0ff;margin:0 4px;">—</span>
                <input type="text" name="time_end" id="time-end" value="<?=htmlspecialchars($time_end)?>" placeholder="23:59" maxlength="5" style="width:75px;text-align:center;" oninput="fmtTime(this)" autocomplete="off">
            </div>
            <?php if ($branch_filter!==''||$time_start!==''||$time_end!==''): ?>
            <button type="button" onclick="clearFilters()" style="background:rgba(255,107,53,0.2);color:#ff6b35;border:1px solid #ff6b35;padding:8px 16px;border-radius:8px;cursor:pointer;font-size:13px;">
                <i class="fas fa-times"></i> ล้างตัวกรอง
            </button>
            <?php endif; ?>
        </div>
        <button type="button" onclick="updateDashboard()"><i class="fas fa-search"></i> ค้นหา</button>
        <button type="button" id="refresh-btn" style="display:none;"><i class="fas fa-sync"></i> รีเฟรช</button>
    </div>
</div>
</form>
<?php if (!empty($errors)): ?>
<div class="error">
    <h2>วันที่ไม่ถูกต้อง</h2>
    <?=implode('<br>', $errors)?>
</div>
<?php endif; ?>
<div id="stat-cards-hist" style="display:<?= $mode==='history'?'flex':'none' ?>;justify-content:center;gap:20px;flex-wrap:wrap;margin:20px 0;">
    <div class="stat-card" style="border-color:#0f0;box-shadow:0 0 24px rgba(0,255,0,0.2);">
        <div class="stat-label"><i class="fas fa-user-check" style="margin-right:6px;"></i>ยอดขาย สมาชิก</div>
        <div class="stat-value" style="color:#0f0;" id="card-member-amt">0.00</div>
        <div class="stat-unit">บาท &nbsp;|&nbsp; <span id="card-member-slip">0</span> สลิป &nbsp;|&nbsp; <span id="card-member-count">0</span> คน</div>
        <div style="font-size:11px;color:#aaa;margin-top:4px;" id="card-member-branch"></div>
    </div>
    <div class="stat-card" style="border-color:#f44;box-shadow:0 0 24px rgba(255,68,68,0.2);">
        <div class="stat-label"><i class="fas fa-user-times" style="margin-right:6px;"></i>ยอดขาย ไม่สมาชิก</div>
        <div class="stat-value" style="color:#f66;" id="card-nonmember-amt">0.00</div>
        <div class="stat-unit">บาท &nbsp;|&nbsp; <span id="card-nonmember-slip">0</span> สลิป</div>
    </div>
    <div class="stat-card" style="border-color:#0ff;box-shadow:0 0 24px rgba(0,255,255,0.2);">
        <div class="stat-label"><i class="fas fa-chart-line" style="margin-right:6px;"></i>ยอดขายรวม</div>
        <div class="stat-value" style="color:#0ff;" id="card-total-amt">0.00</div>
        <div class="stat-unit">บาท &nbsp;|&nbsp; <span id="card-total-slip">0</span> สลิป</div>
    </div>
</div>
<div id="stat-cards-today" style="display:<?= $mode==='history'?'none':'flex' ?>;justify-content:center;gap:20px;flex-wrap:wrap;margin:20px 0;">
    <div class="stat-card" style="border-color:#0f0;box-shadow:0 0 24px rgba(0,255,0,0.2);">
        <div class="stat-label"><i class="fas fa-user-check" style="margin-right:6px;"></i>ยอดขาย สมาชิก</div>
        <div class="stat-value" style="color:#0f0;" id="card-today-member-amt">0.00</div>
        <div class="stat-unit">บาท &nbsp;|&nbsp; <span id="card-today-member-slip">0</span> สลิป &nbsp;|&nbsp; <span id="card-today-member-count">0</span> คน</div>
        <div style="font-size:11px;color:#aaa;margin-top:4px;" id="card-today-member-branch"></div>
    </div>
    <div class="stat-card" style="border-color:#f44;box-shadow:0 0 24px rgba(255,68,68,0.2);">
        <div class="stat-label"><i class="fas fa-user-times" style="margin-right:6px;"></i>ยอดขาย ไม่สมาชิก</div>
        <div class="stat-value" style="color:#f66;" id="card-today-nonmember-amt">0.00</div>
        <div class="stat-unit">บาท &nbsp;|&nbsp; <span id="card-today-nonmember-slip">0</span> สลิป</div>
    </div>
    <div class="stat-card" style="border-color:#0ff;box-shadow:0 0 24px rgba(0,255,255,0.2);">
        <div class="stat-label"><i class="fas fa-chart-line" style="margin-right:6px;"></i>ยอดขายรวม</div>
        <div class="stat-value" style="color:#0ff;" id="card-today-total-amt">0.00</div>
        <div class="stat-unit">บาท &nbsp;|&nbsp; <span id="card-today-total-slip">0</span> สลิป</div>
    </div>
</div>
<canvas id="salesChart" height="100"></canvas>
<div style="text-align:center; margin-top:20px; font-size:24px; font-weight:bold; color:#0ff;">
    <span style="color:#0f0;">สมาชิก: <span id="member-slip">0</span> สลิป | <span id="member-amt">0.00</span> บาท</span>
    <span style="margin-left:40px; color:#f66;">ไม่เป็นสมาชิก: <span id="nonmember-slip">0</span> สลิป | <span id="nonmember-amt">0.00</span> บาท</span>
</div>
<table id="branch-table">
<thead>
    <tr>
        <th style="width:300px;">สาขา</th>
        <th colspan="2" style="background:#060;">สมาชิก</th>
        <th colspan="2" style="background:#600;">ไม่เป็นสมาชิก</th>
        <th colspan="2" style="background:#004466;">ยอดรวม</th>
        <th>ซื้อล่าสุด</th>
        <th>Diff</th>
    </tr>
    <tr>
        <th></th>
        <th>สลิป</th>
        <th>ยอดรวม (บาท)</th>
        <th>สลิป</th>
        <th>ยอดรวม (บาท)</th>
        <th>สลิป</th>
        <th>ยอดรวม (บาท)</th>
        <th></th>
        <th></th>
    </tr>
</thead>
<tbody></tbody>
</table>
<script>
function parseDateDMY(d){
    const p=d.match(/(\d+)\/(\d+)\/(\d+) (\d+):(\d+):(\d+)/);
    if(p) return new Date(p[3],p[2]-1,p[1],p[4],p[5],p[6]);
    return null;
}
let chart = null;
let _autoRefreshTimer = null;
const BRANCHES_TODAY   = <?= json_encode(array_map(fn($c,$n)=>['code'=>$c,'label'=>($n!==$c?"$n ($c)":$c)],array_keys($office_list_today),array_values($office_list_today)),JSON_UNESCAPED_UNICODE) ?>;
const BRANCHES_HISTORY = <?= json_encode(array_map(fn($c,$n)=>['code'=>$c,'label'=>($n!==$c?"$n ($c)":$c)],array_keys($office_list_history),array_values($office_list_history)),JSON_UNESCAPED_UNICODE) ?>;

function fmtTime(el) {
    let v = el.value.replace(/[^0-9]/g,'');
    if (v.length>=3) v = v.slice(0,2)+':'+v.slice(2,4);
    el.value = v;
    el.style.borderColor = /^\d{2}:\d{2}$/.test(el.value)||el.value==='' ? '#0a4a4a' : '#ff6b35';
}
function clearFilters() {
    document.getElementById('branch-select').value='';
    document.getElementById('time-start').value='';
    document.getElementById('time-end').value='';
    updateDashboard();
}
function setMode(mode) {
    document.getElementById('mode-input').value = mode;
    const isHistory = mode === 'history';
    const tb = document.getElementById('mode-today-btn');
    const hb = document.getElementById('mode-history-btn');
    tb.style.background = isHistory ? 'transparent' : '#0ff';
    tb.style.color      = isHistory ? '#0ff' : '#000';
    hb.style.background = isHistory ? '#ff9800' : 'transparent';
    hb.style.color      = isHistory ? '#fff' : '#ff9800';
    document.getElementById('hist-filters').style.display = isHistory ? 'contents' : 'none';
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
    document.getElementById('stat-cards-hist').style.display = isHistory ? 'flex' : 'none';
    if (document.getElementById('stat-cards-today'))
        document.getElementById('stat-cards-today').style.display = isHistory ? 'none' : 'flex';
    // timer control
    if (_autoRefreshTimer) { clearInterval(_autoRefreshTimer); _autoRefreshTimer = null; }
    if (!isHistory) {
        // ✅ โหมด today: auto-refresh เหมือน POS_HOME.php — ส่ง autoRefresh=true เพื่อให้ guard รู้ว่าเรียกจาก timer
        _autoRefreshTimer = setInterval(() => updateDashboard(true), <?= (int)$pos_refresh_interval * 1000 ?>);
    }
    // ปรับวันที่ default: history = เมื่อวาน, today = วันนี้
    const now = new Date();
    const todayStr = ("0"+now.getDate()).slice(-2)+'/'+("0"+(now.getMonth()+1)).slice(-2)+'/'+now.getFullYear();
    const yest = new Date(now); yest.setDate(yest.getDate()-1);
    const yesterdayStr = ("0"+yest.getDate()).slice(-2)+'/'+("0"+(yest.getMonth()+1)).slice(-2)+'/'+yest.getFullYear();
    const targetDate = isHistory ? yesterdayStr : todayStr;
    if (typeof $ !== 'undefined' && $.datepicker) {
        const minD = isHistory ? new Date(2000,0,1) : 'today';
        $('#start_date,#end_date').datepicker('option','minDate', minD);
        $('#start_date,#end_date').datepicker('option','maxDate','today');
        $('#start_date').datepicker('setDate', targetDate);
        $('#end_date').datepicker('setDate', targetDate);
    } else {
        document.getElementById('start_date').value = targetDate;
        document.getElementById('end_date').value = targetDate;
    }
    // เคลียร์หน้าจอทุกครั้งที่สลับโหมด
    document.querySelector('#branch-table tbody').innerHTML =
        isHistory
        ? `<tr><td colspan="9" style="text-align:center;padding:60px 20px;color:#ff9800;font-size:20px;">
            <i class="fas fa-history" style="font-size:40px;margin-bottom:15px;display:block;opacity:0.6;"></i>
            กรุณาเลือกช่วงวันที่แล้วกด <strong>ค้นหา</strong> เพื่อโหลดข้อมูล</td></tr>`
        : `<tr><td colspan="9" style="text-align:center;padding:60px 20px;color:#0ff;font-size:20px;">
            <i class="fas fa-spinner fa-spin" style="font-size:40px;margin-bottom:15px;display:block;"></i>
            กำลังโหลดข้อมูล...</td></tr>`;
    document.getElementById('salesChart').style.display = 'none';
    // reset cards history
    ['card-member-amt','card-nonmember-amt','card-total-amt'].forEach(id => { const e=document.getElementById(id); if(e) e.innerText='0.00'; });
    ['card-member-slip','card-member-count','card-nonmember-slip','card-total-slip'].forEach(id => { const e=document.getElementById(id); if(e) e.innerText='0'; });
    // reset cards today
    ['card-today-member-amt','card-today-nonmember-amt','card-today-total-amt'].forEach(id => { const e=document.getElementById(id); if(e) e.innerText='0.00'; });
    ['card-today-member-slip','card-today-member-count','card-today-nonmember-slip','card-today-total-slip'].forEach(id => { const e=document.getElementById(id); if(e) e.innerText='0'; });
    // reset summary line
    ['member-slip','nonmember-slip'].forEach(id => { const e=document.getElementById(id); if(e) e.innerText='0'; });
    ['member-amt','nonmember-amt'].forEach(id => { const e=document.getElementById(id); if(e) e.innerText='0.00'; });
    if (!isHistory) updateDashboard();
}
function updateDashboard(autoRefresh = false){
    const curMode = document.getElementById('mode-input').value;
    const isHistory = curMode === 'history';
    // ✅ โหมด history: ปิด auto-refresh — รันได้เฉพาะเมื่อ user กดค้นหา/refresh เท่านั้น
    if (isHistory && autoRefresh) return;
    if (isHistory) {
        document.querySelector('#branch-table tbody').innerHTML=
            `<tr><td colspan="9" style="text-align:center;padding:60px 20px;color:#ff9800;font-size:20px;">
            <i class="fas fa-spinner fa-spin" style="font-size:40px;margin-bottom:15px;display:block;"></i>
            กำลังโหลดข้อมูล...</td></tr>`;
        document.getElementById('salesChart').style.display='none';
    }
    const p = new URLSearchParams();
    p.set('ajax','1');
    p.set('mode',  curMode);
    p.set('start', document.getElementById('start_date').value.trim());
    p.set('end',   document.getElementById('end_date').value.trim());
    p.set('branch', (document.getElementById('branch-select')||{value:''}).value.trim());
    if (isHistory) {
        p.set('time_start', (document.getElementById('time-start')||{value:''}).value.trim());
        p.set('time_end',   (document.getElementById('time-end')||{value:''}).value.trim());
    }
    fetch('?'+p.toString())
    .then(r=>{ if(!r.ok) throw new Error(`Network error: ${r.status}`); return r.json(); })
    .then(d=>{
        if(d.error){
            document.querySelector('#branch-table tbody').innerHTML=`<tr><td colspan="9" class="error"><h2>Oracle Error</h2>${d.error}</td></tr>`;
            document.getElementById('refresh-time').innerText='FAILED'; return;
        }
        document.getElementById('refresh-time').innerText=d.refresh_time;
        document.getElementById('date-range').innerText=d.start_date+' - '+d.end_date;
        document.getElementById('online-machines').innerText=d.online_machines;
        document.getElementById('offline-machines').innerText=d.no_data_count;
        // update summary text (today mode)
        if (document.getElementById('member-slip'))     document.getElementById('member-slip').innerText=d.total_member_slip.toLocaleString();
        if (document.getElementById('member-amt'))      document.getElementById('member-amt').innerText=Number(d.total_member_amt).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
        if (document.getElementById('nonmember-slip'))  document.getElementById('nonmember-slip').innerText=d.total_nonmember_slip.toLocaleString();
        if (document.getElementById('nonmember-amt'))   document.getElementById('nonmember-amt').innerText=Number(d.total_nonmember_amt).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
        // update stat cards (history mode)
        if (document.getElementById('card-member-amt')) {
            document.getElementById('card-member-amt').innerText=Number(d.total_member_amt).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
            document.getElementById('card-member-slip').innerText=d.total_member_slip.toLocaleString();
            document.getElementById('card-member-count').innerText=(d.total_member_count||0).toLocaleString();
            document.getElementById('card-nonmember-amt').innerText=Number(d.total_nonmember_amt).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
            document.getElementById('card-nonmember-slip').innerText=d.total_nonmember_slip.toLocaleString();
            document.getElementById('card-total-amt').innerText=Number(d.total_member_amt+d.total_nonmember_amt).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
            document.getElementById('card-total-slip').innerText=(d.total_member_slip+d.total_nonmember_slip).toLocaleString();
            const brLabelH = document.getElementById('card-member-branch');
            if (brLabelH) {
                const brVal = (document.getElementById('branch-select')||{value:''}).value.trim();
                brLabelH.innerText = brVal ? 'สาขา: ' + brVal : '';
            }
        }
        if (document.getElementById('card-today-member-amt')) {
            document.getElementById('card-today-member-amt').innerText=Number(d.total_member_amt).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
            document.getElementById('card-today-member-slip').innerText=d.total_member_slip.toLocaleString();
            document.getElementById('card-today-member-count').innerText=(d.total_member_count||0).toLocaleString();
            document.getElementById('card-today-nonmember-amt').innerText=Number(d.total_nonmember_amt).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
            document.getElementById('card-today-nonmember-slip').innerText=d.total_nonmember_slip.toLocaleString();
            document.getElementById('card-today-total-amt').innerText=Number(d.total_member_amt+d.total_nonmember_amt).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
            document.getElementById('card-today-total-slip').innerText=(d.total_member_slip+d.total_nonmember_slip).toLocaleString();
            const brLabelT = document.getElementById('card-today-member-branch');
            if (brLabelT) {
                const brVal = (document.getElementById('branch-select')||{value:''}).value.trim();
                brLabelT.innerText = brVal ? 'สาขา: ' + brVal : '';
            }
        }
        if (d.online_machines===0) {
            document.querySelector('#branch-table tbody').innerHTML=`<tr><td colspan="9" style="text-align:center;padding:80px 20px;background:rgba(139,0,0,0.2);border:2px dashed #ff6b6b;color:#ff6b6b;font-size:32px;font-weight:bold;"><i class="fas fa-ban" style="margin-right:20px;font-size:40px;"></i>ไม่มีข้อมูลในช่วงวันที่ที่ระบุ</td></tr>`;
            document.getElementById('salesChart').style.display='none';
            if(chart){chart.data.labels=[];chart.data.datasets[0].data=[];chart.data.datasets[1].data=[];chart.update();}
            return;
        }
        document.getElementById('salesChart').style.display='';
        const bg_member=d.branches.map(b=>b.member_slip>0?'rgba(0,180,0,0.7)':'rgba(0,100,0,0.3)');
        const bg_nonmember=d.branches.map(b=>b.nonmember_slip>0?'rgba(180,0,0,0.7)':'rgba(100,0,0,0.3)');
        if(!chart){
            const ctx=document.getElementById('salesChart').getContext('2d');
            chart=new Chart(ctx,{type:'bar',data:{labels:d.chart_labels,datasets:[
                {label:'สมาชิก',data:d.chart_member,backgroundColor:bg_member,borderColor:bg_member.map(c=>c.replace('0.7','1')),borderWidth:1},
                {label:'ไม่เป็นสมาชิก',data:d.chart_nonmember,backgroundColor:bg_nonmember,borderColor:bg_nonmember.map(c=>c.replace('0.7','1')),borderWidth:1}
            ]},options:{responsive:true,plugins:{legend:{position:'top',labels:{color:'#0ff'}},tooltip:{callbacks:{label:c=>c.dataset.label+': '+Number(c.raw).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}},datalabels:{color:'#0ff',anchor:'end',align:'top',formatter:v=>Number(v).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}},scales:{x:{stacked:true,ticks:{color:'#0ff'}},y:{stacked:true,ticks:{color:'#0ff',callback:v=>Number(v).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}}}},plugins:[ChartDataLabels]});
        }else{
            chart.data.labels=d.chart_labels;
            chart.data.datasets[0].data=d.chart_member;chart.data.datasets[0].backgroundColor=bg_member;
            chart.data.datasets[1].data=d.chart_nonmember;chart.data.datasets[1].backgroundColor=bg_nonmember;
            chart.update();
        }
        const tbody=document.querySelector('#branch-table tbody');tbody.innerHTML='';
        let ts_member=0,ta_member=0,ts_non=0,ta_non=0;
        d.branches.forEach((b,i)=>{
            const rank=i+1;
            const rankBadge=`<span class="rank-badge">${rank}</span>`;
            const displayName=(b.office_name&&b.office_name!==b.branch)
                ?`<span style="color:#0ff;">${b.office_name}</span><span style="color:#aaa;font-size:13px;"> (${b.branch})</span>`
                :`<span style="color:#0ff;">${b.branch}</span>`;
            const wrapCls=rank<=3?`branch-rank-wrapper rank-${rank}`:'branch-rank-wrapper';
            let branch_cls=b.is_new?'branch-new':'';
            if(b.member_slip+b.nonmember_slip===0) branch_cls='no-data';
            let diff_text=b.diff;
            if(b.lastsale&&b.lastsale!=='-'){
                const last_ts=parseDateDMY(b.lastsale);
                if(last_ts){const now=new Date();diff_text+=` (${Math.round((now-last_ts)/60000)} นาที)`;}
            }
            const total_slip = b.member_slip + b.nonmember_slip;
            const total_amt  = b.member_amt  + b.nonmember_amt;
            const tr=document.createElement('tr');tr.className=branch_cls;
            tr.innerHTML=`<td class="branch-name-cell"><span class="${wrapCls}">${rankBadge}</span>${displayName}</td>
                <td align="right" class="member-bg">${b.member_slip.toLocaleString()}</td>
                <td align="right" class="member-bg">${Number(b.member_amt).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
                <td align="right" class="nonmember-bg">${b.nonmember_slip.toLocaleString()}</td>
                <td align="right" class="nonmember-bg">${Number(b.nonmember_amt).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
                <td align="right" style="background:rgba(0,68,102,0.4);color:#0ff;font-weight:bold;">${total_slip.toLocaleString()}</td>
                <td align="right" style="background:rgba(0,68,102,0.4);color:#0ff;font-weight:bold;">${Number(total_amt).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
                <td>${b.lastsale}</td><td>${diff_text}</td>`;
            tbody.appendChild(tr);
            ts_member+=b.member_slip;ta_member+=b.member_amt;ts_non+=b.nonmember_slip;ta_non+=b.nonmember_amt;
        });
        const s=document.createElement('tr');s.className='summary-row';
        s.innerHTML=`<td align="center">รวมทั้งหมด (${d.online_machines} สาขา)</td>
            <td align="right" class="member-bg">${ts_member.toLocaleString()}</td>
            <td align="right" class="member-bg">${ta_member.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
            <td align="right" class="nonmember-bg">${ts_non.toLocaleString()}</td>
            <td align="right" class="nonmember-bg">${ta_non.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
            <td align="right" style="background:rgba(0,68,102,0.6);color:#0ff;font-weight:bold;">${(ts_member+ts_non).toLocaleString()}</td>
            <td align="right" style="background:rgba(0,68,102,0.6);color:#0ff;font-weight:bold;">${(ta_member+ta_non).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
            <td colspan="2"></td>`;
        tbody.appendChild(s);
    })
    .catch(e=>{
        console.error("Fetch Error:",e);
        document.querySelector('#branch-table tbody').innerHTML=`<tr><td colspan="9" class="error"><h2>AJAX Error</h2>ไม่สามารถเชื่อมต่อได้</td></tr>`;
        document.getElementById('refresh-time').innerText='ERROR';
    });
}
<?php if (empty($errors)): ?>
if (document.getElementById('mode-input').value === 'history') {
    // ✅ โหมด history: แสดงข้อความรอ — ไม่มี auto-refresh
    document.querySelector('#branch-table tbody').innerHTML =
        `<tr><td colspan="9" style="text-align:center;padding:60px 20px;color:#ff9800;font-size:20px;">
        <i class="fas fa-history" style="font-size:40px;margin-bottom:15px;display:block;opacity:0.6;"></i>
        กรุณาเลือกช่วงวันที่แล้วกด <strong>ค้นหา</strong> เพื่อโหลดข้อมูล</td></tr>`;
    document.getElementById('salesChart').style.display='none';
} else {
    // ✅ โหมด today: โหลดทันที + auto-refresh เหมือน POS_HOME.php
    updateDashboard();
    _autoRefreshTimer = setInterval(() => updateDashboard(true), <?= (int)$pos_refresh_interval * 1000 ?>);
}
<?php endif; ?>
document.getElementById('refresh-btn').addEventListener('click', updateDashboard);
// jQuery datepicker
$(function() {
    const isHist = document.getElementById('mode-input').value === 'history';
    const opts = {
        dateFormat: 'dd/mm/yy', changeMonth: true, changeYear: true,
        maxDate: "today",
        minDate: isHist ? new Date(2000,0,1) : 'today'
    };
    $("#start_date, #end_date").datepicker(opts);
    $("#start-icon").click(()=>$("#start_date").datepicker("show"));
    $("#end-icon").click(()=>$("#end_date").datepicker("show"));
    $("#start_date").change(function(){ $("#end_date").datepicker("option","minDate",$(this).val()); });
    $("#end_date").change(function(){ $("#start_date").datepicker("option","maxDate",$(this).val()); });
    const today=new Date();
    const todayStr=("0"+today.getDate()).slice(-2)+'/'+ ("0"+(today.getMonth()+1)).slice(-2)+'/'+today.getFullYear();
    ["#start_date","#end_date"].forEach(sel=>{
        $(sel).on("dblclick",function(){$(this).val(todayStr);$(this).datepicker("setDate",todayStr);setTimeout(()=>updateDashboard(),100);});
    });
    $("select").on("keydown",e=>{if(e.key==="Enter"){e.preventDefault();updateDashboard();}});
});
// top-right expand/collapse: handled by POS_TOPRIGHT.php

</script>
</body>
</html>