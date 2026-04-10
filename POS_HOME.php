<?php
// ============================================================
//  SESSION GUARD – ต้อง Login ผ่าน index.php ก่อน
// ============================================================
session_start();
if (empty($_SESSION['pos_user'])) {
    header('Location: index.php');
    exit;
}
$pos_logged_user = $_SESSION['pos_user'];
$pos_priority    = $_SESSION['pos_priority'] ?? 'U'; // A=Admin, U=User, M=Member

// M → redirect ตรงไป POS_MEMBERS.php
if ($pos_priority === 'M') {
    header('Location: POS_MEMBERS.php');
    exit;
}

// Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

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
// โหลด Permission จาก DB (ต้องอยู่หลัง config vars)
require_once __DIR__ . '/POS_AUTH.php';
pos_check_expiry(); // ล็อกถ้าบัญชีหมดอายุ
require_once __DIR__ . '/POS_SETTINGS.php';
// ไฟล์ชั่วคราว
$sql_file = sys_get_temp_dir() . "/POS_" . uniqid() . ".sql";
// ---------------------------
// HANDLE DATE FILTER
// ---------------------------
$start_date = $_GET['start'] ?? date('d/m/Y');
$end_date = $_GET['end'] ?? date('d/m/Y');
$branch_filter = trim($_GET['branch'] ?? '');
$start_ts = DateTime::createFromFormat('d/m/Y', $start_date);
$end_ts = DateTime::createFromFormat('d/m/Y', $end_date);
$errors = [];
if (!$start_ts || !$end_ts || $start_ts->format('d/m/Y') !== $start_date || $end_ts->format('d/m/Y') !== $end_date) {
    $errors[] = "รูปแบบวันที่ไม่ถูกต้อง (ใช้ วว/ดด/ปปปป เช่น 12/11/2025)";
} elseif ($start_ts > $end_ts) {
    $errors[] = "วันที่เริ่มต้องไม่เกินวันที่สิ้นสุด";
}
// ---------------------------
// AJAX REQUEST
// ---------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && empty($errors)) {
    header('Content-Type: application/json');
    $instant_client_path = rtrim($instant_client_path, '/');
    $sqlplus_path = "{$instant_client_path}/sqlplus";
    if (!is_executable($sqlplus_path)) {
        echo json_encode(['error' => "SQL*Plus Executable Not Found: {$sqlplus_path}", 'output' => '']);
        exit;
    }
    $sql_content = <<<SQL
SET ECHO OFF
SET FEEDBACK OFF
SET HEADING OFF
SET VERIFY OFF
SET LINESIZE 500
SET PAGESIZE 0
SET TRIMSPOOL ON
SET SERVEROUTPUT ON
ALTER SESSION SET NLS_TERRITORY = America;
ALTER SESSION SET NLS_LANGUAGE = American;
VARIABLE start_date VARCHAR2(10);
VARIABLE end_date VARCHAR2(10);
EXEC :start_date := '$start_date';
EXEC :end_date := '$end_date';
DECLARE
    TYPE t_branch_rec IS RECORD (
        branch VARCHAR2(100), office_name VARCHAR2(100), sale_office_code VARCHAR2(20),
        slip_cnt NUMBER, grand_amt NUMBER, last_date DATE,
        item_cnt NUMBER, member_cnt NUMBER
    );
    TYPE t_branch_tab IS TABLE OF t_branch_rec;
    TYPE t_str_tab      IS TABLE OF VARCHAR2(50);
    TYPE t_num_tab      IS TABLE OF NUMBER;
    TYPE t_bc_dedup_tab IS TABLE OF VARCHAR2(1) INDEX BY VARCHAR2(50);
    v_data t_branch_tab := t_branch_tab();
    v_bc_dedup t_bc_dedup_tab;
    v_total_amt NUMBER := 0; v_total_slip NUMBER := 0; v_total_item NUMBER := 0; v_total_member NUMBER := 0;
    v_total_line NUMBER := 0;
    v_max_date DATE;
    -- v_start/v_end ระดับ outer ใช้ใน total_line block
    v_start_outer DATE;
    v_end_outer   DATE;
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
    -- กำหนดค่า v_start_outer / v_end_outer สำหรับใช้ใน total_line block
    v_start_outer := TO_DATE(:start_date, 'DD/MM/YYYY');
    v_end_outer   := TO_DATE(:end_date,   'DD/MM/YYYY') + 1 - 1/86400;

    FOR rec IN c_tables LOOP
        DECLARE
            v_branch           VARCHAR2(100) := REPLACE(rec.table_name,'POS_SALETODAY_HD_','');
            v_oname            VARCHAR2(100) := v_branch;
            v_sale_office_code VARCHAR2(20)  := v_branch;
            v_sum_amt NUMBER; v_sum_slip NUMBER; v_sum_item NUMBER; v_last DATE; v_member_cnt NUMBER := 0;
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
                        SELECT /*+ INDEX(t IDX_SALE_HD_CDATE_OFF) */ NVL(SUM(GRAND_AMOUNT),0), COUNT(SLIP_NO), MAX(CREATE_DATE)
                        FROM POS.' || rec.table_name || ' t
                        WHERE CREATE_DATE >= :1 AND CREATE_DATE < :2'
                        INTO v_sum_amt, v_sum_slip, v_last USING v_start, v_end;
                EXCEPTION WHEN OTHERS THEN
                    v_sum_amt := 0; v_sum_slip := 0; v_last := NULL;
                END;
                BEGIN
                    EXECUTE IMMEDIATE '
                        SELECT NVL(SUM(QTY),0)
                        FROM POS.' || REPLACE(rec.table_name,'HD','DT') || ' dt
                        WHERE EXISTS (
                            SELECT /*+ INDEX(h IDX_SALE_HD_CDATE_OFF) */ 1 FROM POS.' || rec.table_name || ' h
                            WHERE h.SLIP_NO = dt.SLIP_NO
                              AND h.CREATE_DATE >= :1 AND h.CREATE_DATE < :2
                        )'
                        INTO v_sum_item USING v_start, v_end;
                EXCEPTION WHEN OTHERS THEN v_sum_item := 0; END;
                BEGIN
                    EXECUTE IMMEDIATE '
                        SELECT /*+ INDEX(t IDX_SALE_HD_CDATE_OFF) */ COUNT(DISTINCT MEMBER_ID)
                        FROM POS.' || rec.table_name || ' t
                        WHERE MEMBER_ID IS NOT NULL AND MEMBER_ID != ''-''
                          AND CREATE_DATE >= :1 AND CREATE_DATE < :2'
                        INTO v_member_cnt USING v_start, v_end;
                EXCEPTION WHEN OTHERS THEN v_member_cnt := 0; END;
                v_data.EXTEND;
                v_data(v_data.LAST).branch := v_branch;
                v_data(v_data.LAST).office_name := v_oname;
                v_data(v_data.LAST).sale_office_code := NVL(v_sale_office_code, v_branch);
                v_data(v_data.LAST).slip_cnt := v_sum_slip;
                v_data(v_data.LAST).grand_amt := v_sum_amt;
                v_data(v_data.LAST).last_date := v_last;
                v_data(v_data.LAST).item_cnt := v_sum_item;
                v_data(v_data.LAST).member_cnt := v_member_cnt;
                v_total_amt := v_total_amt + NVL(v_sum_amt,0);
                v_total_slip := v_total_slip + NVL(v_sum_slip,0);
                v_total_item := v_total_item + NVL(v_sum_item,0);

            END IF;
        EXCEPTION WHEN OTHERS THEN NULL;
        END;
    END LOOP;
    FOR i IN 1 .. v_data.COUNT LOOP
        IF v_data(i).last_date IS NOT NULL AND (v_max_date IS NULL OR v_data(i).last_date > v_max_date) THEN
            v_max_date := v_data(i).last_date;
        END IF;
        v_total_member := v_total_member + NVL(v_data(i).member_cnt,0);
    END LOOP;
    FOR i IN 1 .. v_data.COUNT - 1 LOOP
        FOR j IN i + 1 .. v_data.COUNT LOOP
            IF NVL(v_data(i).grand_amt,0) < NVL(v_data(j).grand_amt,0)
                OR (NVL(v_data(i).grand_amt,0) = NVL(v_data(j).grand_amt,0)
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
    -- Output pipe-delimited (เหมือน POS_DETAIL.php)
    -- BRANCH|code|office_name|slip|item|amount|lastsale|diff_sec|is_new
    FOR i IN 1..v_data.COUNT LOOP
        DECLARE
            v_diff NUMBER := CASE WHEN v_data(i).last_date IS NOT NULL
                                  THEN ROUND((SYSDATE-v_data(i).last_date)*86400) ELSE -1 END;
            v_mark VARCHAR2(1) := CASE WHEN v_data(i).last_date IS NOT NULL
                                        AND v_data(i).last_date=v_max_date THEN 'Y' ELSE 'N' END;
        BEGIN
            DBMS_OUTPUT.PUT_LINE(
                'BRANCH|'||v_data(i).branch||'|'||v_data(i).office_name||'|'||
                TO_CHAR(v_data(i).slip_cnt)||'|'||TO_CHAR(v_data(i).item_cnt)||'|'||
                TO_CHAR(v_data(i).grand_amt,'FM999999999990.00')||'|'||
                NVL(TO_CHAR(v_data(i).last_date,'DD/MM/YYYY HH24:MI:SS'),'-')||'|'||
                TO_CHAR(v_diff)||'|'||v_mark||'|'||NVL(v_data(i).sale_office_code,v_data(i).branch)||'|'||
                TO_CHAR(NVL(v_data(i).member_cnt,0))
            );

        END;
    END LOOP;
    -- นับ distinct barcode จากทุกสาขา
    BEGIN
        FOR i IN 1..v_data.COUNT LOOP
            DECLARE
                v_bcs t_str_tab;
                v_qts t_num_tab;
            BEGIN
                EXECUTE IMMEDIATE
                    'SELECT dt.BARCODE, SUM(dt.QTY) FROM POS.POS_SALETODAY_DT_'||v_data(i).branch||' dt'||
                    ' WHERE dt.BARCODE IS NOT NULL AND dt.QTY<>0'||
                    ' AND EXISTS(SELECT /*+ INDEX(h IDX_SALE_HD_CDATE_OFF) */ 1 FROM POS.POS_SALETODAY_HD_'||v_data(i).branch||
                    ' h WHERE h.SLIP_NO=dt.SLIP_NO AND h.CREATE_DATE>=:1 AND h.CREATE_DATE<:2)'||
                    ' GROUP BY dt.BARCODE HAVING SUM(dt.QTY)>0'
                BULK COLLECT INTO v_bcs, v_qts USING v_start_outer, v_end_outer;
                FOR k IN 1..v_bcs.COUNT LOOP
                    v_bc_dedup(TRIM(v_bcs(k))) := '1';
                END LOOP;
            EXCEPTION WHEN OTHERS THEN NULL;
            END;
        END LOOP;
        v_total_line := v_bc_dedup.COUNT;
    EXCEPTION WHEN OTHERS THEN v_total_line := 0;
    END;
    DBMS_OUTPUT.PUT_LINE('TOTAL|'||TO_CHAR(v_total_slip)||'|'||TO_CHAR(v_total_item)||'|'||
        TO_CHAR(v_total_amt,'FM999999999990.00')||'|'||TO_CHAR(v_total_member)||'|'||TO_CHAR(v_total_line));
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
    // === Parse pipe-delimited (เหมือน POS_DETAIL.php) ===
    // BRANCH|code|office_name|slip|item|amount|lastsale|diff_sec|is_new  (9 fields)
    // TOTAL|slip|item|amount|members|line
    $lines = explode("\n", $output);
    $data = []; $current_branch = null;
    $total_slip = 0; $total_item = 0; $total_amount = 0; $total_members = 0; $total_line = 0;
    foreach ($lines as $raw) {
        $line = trim($raw);
        if ($line === '') continue;
        if (strpos($line, 'BRANCH|') === 0) {
            $p = explode('|', $line, 11);
            if (count($p) < 9) continue;
            $branch = trim($p[1]); $office_name = trim($p[2]);
            $slip = (int)$p[3]; $item = (int)$p[4]; $amount = (float)$p[5];
            $lastsale = trim($p[6]); $diff_sec = (int)$p[7]; $is_new = trim($p[8]) === 'Y';
            $sale_office = isset($p[9]) ? trim($p[9]) : $branch;
            $member_cnt  = isset($p[10]) ? (int)trim($p[10]) : 0;
            $diff_text = $diff_sec >= 0
                ? sprintf('+%02d:%02d:%02d', intdiv($diff_sec,3600), intdiv($diff_sec%3600,60), $diff_sec%60) : '';
            $ls_ts = 0;
            if ($lastsale !== '-' && preg_match('/(\d{2})\/(\d{2})\/(\d{4}) (\d{2}):(\d{2}):(\d{2})/', $lastsale, $tm))
                $ls_ts = mktime((int)$tm[4],(int)$tm[5],(int)$tm[6],(int)$tm[2],(int)$tm[1],(int)$tm[3]);
            $data[$branch] = [
                'branch' => $branch, 'office_name' => $office_name,
                'sale_office' => $sale_office,
                'slip' => $slip, 'item' => $item, 'amount' => $amount,
                'lastsale' => $lastsale, 'lastsale_ts' => $ls_ts,
                'diff' => $diff_text, 'is_new' => $is_new,
                'member_cnt' => $member_cnt
            ];
            $current_branch = $branch;
            $total_slip += $slip; $total_item += $item; $total_amount += $amount;
            $total_members += $member_cnt;
        } elseif (strpos($line, 'TOTAL|') === 0) {
            $p = explode('|', $line, 6);
            if (count($p) >= 5) {
                $total_slip = (int)$p[1]; $total_item = (int)$p[2];
                $total_amount = (float)$p[3];
                // ไม่ override total_members — ใช้ค่าที่ sum จากแต่ละ BRANCH| แทน
                if (isset($p[5])) $total_line = (int)$p[5];
            }
        }
    }

    // === กรองสาขาตามสิทธิ์ USER_BRANCH_ACCESS ===
    if (function_exists('pos_get_branches') && pos_get_branches() !== null) {
        $data = array_filter($data, fn($b) => pos_can_see_branch($b['sale_office']));
    }
    // === กรองตาม branch_filter ที่ user เลือก ===
    if ($branch_filter !== '') {
        $data = array_filter($data, fn($b) => $b['sale_office'] === $branch_filter);
    }
    // === คำนวณยอดรวมใหม่ (ทุกตัวรวมถึง total_line) ===
    $need_recalc = $branch_filter !== ''
        || (function_exists('pos_get_branches') && pos_get_branches() !== null);

    if ($need_recalc) {
        $total_slip = 0; $total_item = 0; $total_amount = 0.0;
        $total_members = 0; $total_line = 0;
        foreach ($data as $b) {
            $total_slip    += $b['slip'];
            $total_item    += $b['item'];
            $total_amount  += $b['amount'];
            $total_members += $b['member_cnt'];
        }

        // --- คำนวณ total_line (distinct barcode) ใหม่เฉพาะสาขาที่ผ่านการกรอง ---
        if (!empty($data)) {
            // สร้าง UNION ของ barcode จากทุกสาขาที่เหลือ เพื่อ dedup ข้ามสาขา
            $union_parts = [];
            foreach ($data as $b) {
                $safe_br = preg_replace('/[^A-Z0-9_]/', '', strtoupper($b['branch']));
                if ($safe_br === '') continue;
                $union_parts[] =
                    "SELECT TRIM(dt.BARCODE) AS BC\n"
                  . "  FROM POS.POS_SALETODAY_DT_{$safe_br} dt\n"
                  . " WHERE dt.BARCODE IS NOT NULL AND dt.QTY > 0\n"
                  . "   AND EXISTS (\n"
                  . "       SELECT /*+ INDEX(h IDX_SALE_HD_CDATE_OFF) */ 1 FROM POS.POS_SALETODAY_HD_{$safe_br} h\n"
                  . "        WHERE h.SLIP_NO = dt.SLIP_NO\n"
                  . "          AND h.CREATE_DATE >= TO_DATE('{$start_date}','DD/MM/YYYY')\n"
                  . "          AND h.CREATE_DATE <  TO_DATE('{$end_date}','DD/MM/YYYY') + 1 - 1/86400)";
            }
            if (!empty($union_parts)) {
                $safe_sp   = rtrim($instant_client_path, '/') . '/sqlplus';
                $safe_up   = escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}");
                $tmp_lf    = sys_get_temp_dir() . '/POS_LINE_' . uniqid() . '.sql';
                $union_sql = implode("\nUNION\n", $union_parts);
                $line_sql  = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON\n"
                           . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
                           . "SELECT COUNT(DISTINCT BC) FROM (\n{$union_sql}\n);\nEXIT;\n";
                file_put_contents($tmp_lf, $line_sql);
                $lr = shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path}"
                               . " NLS_LANG=THAI_THAILAND.AL32UTF8 {$safe_sp} -s {$safe_up} @{$tmp_lf} 2>&1");
                @unlink($tmp_lf);
                foreach (explode("\n", (string)$lr) as $ll) {
                    $ll = trim($ll);
                    if (is_numeric($ll)) { $total_line = (int)$ll; break; }
                }
            }
        }
    }

    // === ตรวจสอบว่า "ไม่มีข้อมูลเลย" หรือไม่ ===
    $has_any_data = false;
    foreach ($data as $b) {
        if ($b['slip'] > 0) {
            $has_any_data = true;
            break;
        }
    }
    if (!$has_any_data) {
        echo json_encode([
            'no_data_global' => true,
            'message' => 'ไม่มีข้อมูลรายการขายวันนี้',
            'refresh_time' => date('d/m/Y H:i:s'),
            'start_date' => $start_date,
            'end_date' => $end_date,
            'online_machines' => 0,
            'no_data_count' => 0,
            'total_amount' => 0,
            'total_members' => 0,
            'chart_labels' => [],
            'chart_data' => []
        ]);
        exit;
    }
    // === เรียงลำดับ ===
    usort($data, function($a, $b) {
        return $b['amount'] <=> $a['amount'] ?: ($b['lastsale_ts'] <=> $a['lastsale_ts']);
    });
    // === เพิ่มลำดับ ===
    foreach ($data as $idx => &$branch) {
        $branch['rank'] = $idx + 1;
    }
    unset($branch);

    $online = 0; $no_data = 0;
    foreach ($data as $b) {
        if ($b['slip'] > 0) $online++; else $no_data++;
    }

    $chart_labels = array_map(fn($b) => ($b['office_name'] && $b['office_name'] !== $b['branch'])
        ? $b['office_name'].' ('.$b['branch'].')' : $b['branch'], $data);
    $chart_data = array_column($data, 'amount');
    $refresh_time = date('d/m/Y H:i:s');
    echo json_encode([
        'refresh_time' => $refresh_time,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'online_machines' => $online,
        'no_data_count' => $no_data,
        'total_amount' => $total_amount,
        'total_members' => $total_members,
        'total_slip' => $total_slip,
        'total_item' => $total_item,
        'total_line' => $total_line,
        'chart_labels' => $chart_labels,
        'chart_data' => array_map('round', $chart_data, array_fill(0, count($chart_data), 2)),
        'branches' => array_values($data)
    ]);
    exit;
}




// ---------------------------
// PING AJAX REQUEST
// ---------------------------
if (isset($_GET['ping_ajax']) && $_GET['ping_ajax'] === '1') {
    header('Content-Type: application/json');
    $instant_client_path = rtrim($instant_client_path, '/');
    $sqlplus_path = "{$instant_client_path}/sqlplus";

    // ดึง SALE_OFFICE → IP_ADDRESS จาก POS.POS_SERVER
    $sql_ping = <<<SQLPING
SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON
ALTER SESSION SET NLS_TERRITORY = America;
ALTER SESSION SET NLS_LANGUAGE = American;
SELECT 'SERVER|' || TRIM(SALE_OFFICE) || '|' || TRIM(IP_ADDRESS)
FROM POS.POS_SERVER
WHERE IP_ADDRESS IS NOT NULL AND TRIM(IP_ADDRESS) IS NOT NULL
ORDER BY SALE_OFFICE;
EXIT;
SQLPING;

    $ping_sql_file = sys_get_temp_dir() . "/POS_ping_" . uniqid() . ".sql";
    file_put_contents($ping_sql_file, $sql_ping);
    $up  = escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}");
    $raw = shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s $up @$ping_sql_file 2>&1");
    @unlink($ping_sql_file);

    $server_map = []; // sale_office → ip
    foreach (preg_split('/\r?\n/', (string)$raw) as $ln) {
        $ln = trim($ln);
        if (strpos($ln, 'SERVER|') === 0) {
            $parts = explode('|', $ln, 3);
            if (count($parts) === 3) {
                $server_map[trim($parts[1])] = trim($parts[2]);
            }
        }
    }

    // ping แต่ละ IP (timeout 1 วิ, 1 packet)
    $results = [];
    foreach ($server_map as $office => $ip) {
        // ตรวจ IP format เบื้องต้นก่อน ping
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $results[$office] = ['ip' => $ip, 'online' => false];
            continue;
        }
        exec("ping -c 1 -W 1 " . escapeshellarg($ip) . " > /dev/null 2>&1", $out, $ret);
        $results[$office] = ['ip' => $ip, 'online' => ($ret === 0)];
    }

    echo json_encode(['ok' => true, 'servers' => $results, 'ts' => date('H:i:s')]);
    exit;
}

// ── โหลด office_list สำหรับ dropdown สาขา (เหมือน POS_ITEMS) ────────────
$office_list = [];
if (empty($errors)) {
    try {
        $sp_ol = rtrim($instant_client_path, '/') . '/sqlplus';
        $up_ol = escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}");
        $tmp_ol = sys_get_temp_dir() . '/POS_HOME_OL_' . uniqid() . '.sql';
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
            $ln = trim($ln);
            if ($ln === '' || preg_match('/^(ORA-|SP2-)/', $ln)) continue;
            $p = explode('|', $ln, 2);
            if (count($p) === 2 && $p[0] !== '') {
                $code = trim($p[0]);
                if (!function_exists('pos_can_see_branch') || pos_can_see_branch($code))
                    $office_list[$code] = trim($p[1]);
            }
        }
    } catch (Throwable $e) { $office_list = []; }
}

// ── นับ Users ที่มี VALID_DAYS แต่ยังไม่มี START_DATE / END_DATE ──
$pos_valid_days_pending = 0;
if ($pos_priority === 'A') {
    try {
        $_lib_vd  = rtrim($instant_client_path, '/');
        $_sp_vd   = "{$_lib_vd}/sqlplus";
        $_up_vd   = escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}");
        $_tmp_vd  = tempnam(sys_get_temp_dir(), 'POS_VD_') . '.sql';
        file_put_contents($_tmp_vd,
            "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 100 TRIMSPOOL ON\n"
          . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
          . "SELECT COUNT(*) FROM POS.SK_WEB_USER\n"
          . "WHERE (START_DATE IS NULL OR END_DATE IS NULL)\n"
          . "  AND VALID_DAYS IS NOT NULL AND VALID_DAYS > 0;\n"
          . "EXIT;\n"
        );
        $_out_vd = (string)shell_exec("env -i LD_LIBRARY_PATH={$_lib_vd} TNS_ADMIN={$_lib_vd} NLS_LANG=THAI_THAILAND.AL32UTF8 {$_sp_vd} -s {$_up_vd} @{$_tmp_vd} 2>&1");
        @unlink($_tmp_vd);
        foreach (preg_split('/\r?\n/', $_out_vd) as $_vl) {
            $_vl = trim($_vl);
            if ($_vl === '' || preg_match('/^(ORA-|SP2-)/', $_vl)) continue;
            if (is_numeric($_vl)) { $pos_valid_days_pending = (int)$_vl; break; }
        }
    } catch (Throwable $_e) { $pos_valid_days_pending = 0; }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>POS</title>
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
h1 i {
    color: #0f0;
    font-size: 32px;
    margin-right: 10px;
    text-shadow: 0 0 15px rgba(0,255,0,0.6);
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
/* === หมายเลขลำดับสาขา แบบ Badge วงกลม === */
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
.rank-1 .rank-badge {
    background: linear-gradient(135deg, #ffd700, #ffb800);
    color: #8B4513;
    text-shadow: 0 0 10px rgba(255,215,0,0.9);
}
.rank-2 .rank-badge {
    background: linear-gradient(135deg, #c0c0c0, #a8a8a8);
    color: #4a4a4a;
    text-shadow: 0 0 10px rgba(192,192,192,0.9);
}
.rank-3 .rank-badge {
    background: linear-gradient(135deg, #cd7f32, #b87333);
    color: #fff;
    text-shadow: 0 0 10px rgba(205,127,50,0.9);
}
@keyframes pulse {
    0% { box-shadow: 0 0 18px rgba(255,255,255,0.6); }
    50% { box-shadow: 0 0 28px rgba(255,255,255,0.9); }
    100% { box-shadow: 0 0 18px rgba(255,255,255,0.6); }
}
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
tr.branch { background: #003333 !important; color: #0ff !important; font-weight: bold; }
tr.branch-delay-10 { background: #444400 !important; color: #ffff66 !important; font-weight: bold; }
tr.branch-delay-30 { background: #663300 !important; color: #ffcc66 !important; font-weight: bold; }
tr.branch-delay-60 { background: #550000 !important; color: #ff6666 !important; font-weight: bold; }
tr.branch-new { background: #002200 !important; color: #0f0 !important; font-weight: bold; }
tr.no-data { background: #330000 !important; color: #ff8888 !important; font-weight: bold; text-align: center; }
tr.machine { background-color: #111; }
tr.machine-zero { background-color: #550000 !important; color: #ff6666 !important; font-weight: bold; }
tr.machine-delay-10 { background: #444400 !important; color: #ffff66 !important; font-weight: bold; }
tr.machine-delay-30 { background: #663300 !important; color: #ffcc66 !important; font-weight: bold; }
tr.machine-delay-60 { background: #550000 !important; color: #ff6666 !important; font-weight: bold; }
.item-row td { background:#222; color:#0ff; }
.member-badge { background:#ff6b35; color:#fff; padding:2px 8px; border-radius:4px; font-size:12px; margin-left:8px; font-weight:bold; }

/* === Ping status dot === */
.ping-dot {
    display: inline-block;
    width: 14px; height: 14px;
    border-radius: 50%;
    vertical-align: middle;
    margin-right: 6px;
    box-shadow: 0 0 8px rgba(0,0,0,0.6);
}
.ping-online  { background: #00e676; box-shadow: 0 0 10px rgba(0,230,118,0.8); animation: ping-pulse 2s infinite; }
.ping-offline { background: #ff1744; box-shadow: 0 0 10px rgba(255,23,68,0.8); }
.ping-unknown { background: #555; }
@keyframes ping-pulse {
    0%,100% { box-shadow: 0 0 6px rgba(0,230,118,0.6); }
    50%      { box-shadow: 0 0 16px rgba(0,230,118,1); }
}
.ping-cell { min-width: 90px; text-align: center; }
.ping-label { font-size: 11px; font-weight: bold; vertical-align: middle; }
.ping-label.online  { color: #00e676; }
.ping-label.offline { color: #ff1744; }
.ping-label.unknown { color: #888; }

/* ไอคอน POS หลัก */
.pos-icon {
    color: #0f0;
    font-size: 32px;
    text-shadow: 0 0 15px rgba(0,255,0,0.6);
    margin-right: 8px;
}
</style>
</head>
<body>
<?php pos_expiry_banner(); ?>
<?php $MENU_ACTIVE = 'home'; require_once 'POS_MENU.php'; ?>
<?php $pos_topright_show_online = true; require_once __DIR__ . '/POS_TOPRIGHT.php'; ?>

<!-- หัวข้อหลัก: ใช้ไอคอน POS -->
<h1><i class="fas fa-cash-register pos-icon" title="Point of Sale System"></i> POS</h1>

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
<div style="position:relative;display:inline-block;margin:0 10px;">
  <button type="button" onclick="window.location.href='POS_USER.php'" style="background:linear-gradient(135deg,#9c27b0,#6a1b9a); color:#fff; border:none; padding:10px 24px; border-radius:6px; cursor:pointer; font-size:16px; font-weight:bold; box-shadow:0 0 14px rgba(156,39,176,0.5);"><i class="fas fa-shield-alt" style="margin-right:6px;"></i>User Management</button>
  <?php if ($pos_priority === 'A' && $pos_valid_days_pending > 0): ?>
  <span title="ผู้ใช้ที่มี VALID_DAYS แต่ยังไม่ได้กำหนด START_DATE / END_DATE" style="position:absolute;top:-8px;right:-8px;background:#ff4444;color:#fff;border-radius:50%;min-width:22px;height:22px;font-size:12px;font-weight:bold;display:flex;align-items:center;justify-content:center;padding:0 4px;box-shadow:0 0 8px rgba(255,68,68,0.7);pointer-events:none;line-height:1;"><?= $pos_valid_days_pending ?></span>
  <?php endif; ?>
</div>
</div>
<?php endif; ?>
</div>
</div>

<div class="filter-section">
<form method="GET" id="filter-form" style="text-align:center;">
    <div class="form-group">
        <label>เริ่มต้น:</label>
        <input type="text" name="start" id="start_date" value="<?=htmlspecialchars($start_date)?>" placeholder="วว/ดด/ปปปป" autocomplete="off" required readonly style="cursor:pointer;">
        <i class="fas fa-calendar-alt date-icon" id="start-icon"></i>
    </div>
    <div class="form-group">
        <label>สิ้นสุด:</label>
        <input type="text" name="end" id="end_date" value="<?=htmlspecialchars($end_date)?>" placeholder="วว/ดด/ปปปป" autocomplete="off" required readonly style="cursor:pointer;">
        <i class="fas fa-calendar-alt date-icon" id="end-icon"></i>
    </div>
    <div class="form-group">
        <label>สาขา:</label>
        <select name="branch" id="branch-select">
            <option value="">— ทุกสาขา —</option>
            <?php foreach ($office_list as $code => $name): ?>
            <option value="<?=htmlspecialchars($code)?>" <?=$branch_filter===$code?'selected':''?>>
                <?=htmlspecialchars($name)?> (<?=htmlspecialchars($code)?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit"><i class="fas fa-search"></i> ค้นหา</button>
    <button type="button" id="refresh-btn" style="display:none;"><i class="fas fa-sync"></i> รีเฟรช</button>
</div>
</form>

<?php if (!empty($errors)): ?>
<div class="error">
    <h2>วันที่ไม่ถูกต้อง</h2>
    <?=implode('<br>', $errors)?>
</div>
<?php endif; ?>

<!-- การ์ดสรุปยอด -->
<div style="display:flex; justify-content:center; gap:20px; flex-wrap:wrap; margin:20px 0;">
    <div style="background:linear-gradient(135deg,rgba(0,188,212,0.2),rgba(0,150,167,0.2)); border:2px solid #0ff; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(0,255,255,0.2); min-width:200px;">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-chart-line" style="margin-right:6px;"></i>ยอดขาย (ช่วงวันที่)</div>
        <div style="font-size:36px; font-weight:bold; color:#00ffff; text-shadow:0 0 16px rgba(0,255,255,0.6);" id="total-amount">0.00</div>
        <div style="color:#aaa; font-size:12px; margin-top:4px;">บาท</div>
    </div>
    <div style="background:linear-gradient(135deg,rgba(255,107,53,0.2),rgba(200,80,30,0.2)); border:2px solid #ff6b35; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(255,107,53,0.2); min-width:200px;">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-user-check" style="margin-right:6px;"></i>สมาชิก (ช่วงวันที่)</div>
        <div style="font-size:42px; font-weight:bold; color:#ff6b35; text-shadow:0 0 16px rgba(255,107,53,0.5);" id="total-members">0</div>
        <div style="color:#aaa; font-size:12px; margin-top:4px;">คน</div>
    </div>
    <div style="background:linear-gradient(135deg,rgba(76,175,80,0.2),rgba(56,142,60,0.2)); border:2px solid #4caf50; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(76,175,80,0.2); min-width:200px;">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-receipt" style="margin-right:6px;"></i>สลิปรวม (ช่วงวันที่)</div>
        <div style="font-size:42px; font-weight:bold; color:#4caf50; text-shadow:0 0 16px rgba(76,175,80,0.6);" id="total-slip">0</div>
        <div style="color:#aaa; font-size:12px; margin-top:4px;">ใบ</div>
    </div>
    <div style="background:linear-gradient(135deg,rgba(255,193,7,0.2),rgba(255,160,0,0.2)); border:2px solid #ffc107; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(255,193,7,0.2); min-width:200px;">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-boxes" style="margin-right:6px;"></i>จำนวนสินค้ารวม (ช่วงวันที่)</div>
        <div style="font-size:42px; font-weight:bold; color:#ffc107; text-shadow:0 0 16px rgba(255,193,7,0.6);" id="total-item">0</div>
        <div style="color:#aaa; font-size:12px; margin-top:4px;">ชิ้น</div>
    </div>
    <div style="background:linear-gradient(135deg,rgba(255,152,0,0.2),rgba(230,120,0,0.2)); border:2px solid #ff9800; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(255,152,0,0.2); min-width:200px;">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-list-ul" style="margin-right:6px;"></i>รายการสินค้ารวม</div>
        <div style="font-size:42px; font-weight:bold; color:#ff9800; text-shadow:0 0 16px rgba(255,152,0,0.6);" id="total-line">0</div>
        <div style="color:#aaa; font-size:12px; margin-top:4px;">รายการ</div>
    </div>
    <div style="background:linear-gradient(135deg,rgba(156,39,176,0.2),rgba(106,27,154,0.2)); border:2px solid #ce93d8; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(156,39,176,0.25); min-width:200px;">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-store" style="margin-right:6px;"></i>สาขา</div>
        <div style="font-size:42px; font-weight:bold; color:#ce93d8; text-shadow:0 0 16px rgba(206,147,216,0.6);" id="total-branch">0</div>
        <div style="color:#aaa; font-size:12px; margin-top:4px;">สาขา</div>
    </div>
</div>

<canvas id="salesChart" height="100"></canvas>

<table id="branch-table">
<thead><tr><th style="width:300px;">สาขา</th><th class="ping-cell">สถานะ</th><th>สลิป</th><th>จำนวนสินค้า</th><th>ยอดรวม (บาท)</th><th>ซื้อล่าสุด</th><th>Diff</th></tr></thead>
<tbody></tbody>
</table>

<script>
function parseDateDMY(d) {
    const p = d.match(/(\d+)\/(\d+)\/(\d+) (\d+):(\d+):(\d+)/);
    if (p) return new Date(p[3], p[2]-1, p[1], p[4], p[5], p[6]);
    return null;
}
let chart = null;

// ── Ping status ──────────────────────────────────────────────
function fetchPingStatus() {
    fetch('?ping_ajax=1')
    .then(r => r.json())
    .then(d => {
        if (!d.ok || !d.servers) return;
        Object.keys(d.servers).forEach(office => {
            const info = d.servers[office];
            const cell = document.getElementById('ping-' + office);
            if (!cell) return;
            const dot   = cell.querySelector('.ping-dot');
            const label = cell.querySelector('.ping-label');
            if (info.online) {
                dot.className   = 'ping-dot ping-online';
                label.className = 'ping-label online';
                label.textContent = 'Online';
                cell.title = info.ip + ' — Online';
            } else {
                dot.className   = 'ping-dot ping-offline';
                label.className = 'ping-label offline';
                label.textContent = 'Offline';
                cell.title = info.ip + ' — Offline';
            }
        });
    })
    .catch(() => {});
}

function updateDashboard() {
    const p = new URLSearchParams(window.location.search);
    p.set('ajax', '1');
    const branchSel = document.getElementById('branch-select');
    const urlBranch = new URLSearchParams(window.location.search).get('branch') || '';
    const selBranch = branchSel ? branchSel.value : '';
    p.set('branch', selBranch || urlBranch);
    const startEl = document.getElementById('start_date');
    const endEl   = document.getElementById('end_date');
    if (startEl) p.set('start', startEl.value);
    if (endEl)   p.set('end',   endEl.value);
    fetch('?' + p.toString())
    .then(r => r.ok ? r.json() : Promise.reject(`HTTP ${r.status}`))
    .then(d => {
        if (d.no_data_global) {
            const tbody = document.querySelector('#branch-table tbody');
            tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:80px 20px; background:rgba(139,0,0,0.2); border:2px dashed #ff6b6b; color:#ff6b6b; font-size:32px; font-weight:bold;"><i class="fas fa-ban" style="margin-right:20px; font-size:40px;"></i>${d.message}</td></tr>`;
            document.getElementById('refresh-time').innerText = d.refresh_time;
            document.getElementById('date-range').innerText = d.start_date + ' - ' + d.end_date;
            document.getElementById('online-machines').innerText = '0';
            document.getElementById('offline-machines').innerText = '0';
            document.getElementById('total-amount').innerText = '0.00';
            document.getElementById('total-members').innerText = '0';
            document.getElementById('total-slip').innerText = '0';
            document.getElementById('total-item').innerText = '0';
            if (document.getElementById('total-line')) document.getElementById('total-line').innerText = '0';
            document.getElementById('total-branch').innerText = '0';
            // การ์ด stat ยังแสดงค่าระบบอยู่ (ไม่ reset)
            document.getElementById('salesChart').style.display = 'none';
            if (chart) { chart.data.labels = []; chart.data.datasets[0].data = []; chart.update(); }
            return;
        }
        if (d.error) {
            document.querySelector('#branch-table tbody').innerHTML = `<tr><td colspan="7" class="error"><h2>Oracle Error</h2>${d.error}</td></tr>`;
            return;
        }
        document.getElementById('refresh-time').innerText = d.refresh_time;
        document.getElementById('date-range').innerText = d.start_date + ' - ' + d.end_date;
        document.getElementById('online-machines').innerText = d.online_machines;
        document.getElementById('offline-machines').innerText = d.no_data_count;
        document.getElementById('total-amount').innerText = Number(d.total_amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('total-members').innerText = Number(d.total_members || 0).toLocaleString();
        document.getElementById('total-slip').innerText   = Number(d.total_slip   || 0).toLocaleString();
        document.getElementById('total-item').innerText   = Number(d.total_item   || 0).toLocaleString();
        if (document.getElementById('total-line')) document.getElementById('total-line').innerText = Number(d.total_line || 0).toLocaleString();
        document.getElementById('total-branch').innerText = Number(d.online_machines || 0).toLocaleString();
        document.getElementById('salesChart').style.display = '';
        const bg = d.branches.map(b => b.is_new ? 'rgba(0,255,0,0.7)' : b.slip === 0 ? 'rgba(255,0,0,0.7)' : 'rgba(0,188,212,0.7)');
        const bo = bg.map(c => c.replace('0.7', '1'));
        if (!chart) {
            const ctx = document.getElementById('salesChart').getContext('2d');
            chart = new Chart(ctx, {
                type: 'bar',
                data: { labels: d.chart_labels, datasets: [{ label: 'ยอดขาย', data: d.chart_data, backgroundColor: bg, borderColor: bo, borderWidth: 1 }] },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: c => Number(c.raw).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) } },
                        datalabels: { display: true, color: '#0ff', anchor: 'end', align: 'top', formatter: v => Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
                    },
                    scales: { x: { ticks: { color: '#0ff' } }, y: { ticks: { color: '#0ff', callback: v => Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) } } }
                },
                plugins: [ChartDataLabels]
            });
        } else {
            chart.data.labels = d.chart_labels;
            chart.data.datasets[0].data = d.chart_data;
            chart.data.datasets[0].backgroundColor = bg;
            chart.data.datasets[0].borderColor = bo;
            chart.update();
        }
        const tbody = document.querySelector('#branch-table tbody');
        tbody.innerHTML = '';
        let ts = 0, ti = 0, ta = 0;
        d.branches.forEach((b, i) => {
            const rank = i + 1;
            const isTop3 = rank <= 3;
            const rankBadge = `<span class="rank-badge">${rank}</span>`;
            // แสดงชื่อสาขา: ถ้ามี office_name ต่างจาก code แสดง "ชื่อ (code)" ไม่งั้นแสดงแค่ code
            const displayName = (b.office_name && b.office_name !== b.branch)
                ? `<span style="color:#0ff;">${b.office_name}</span><span style="color:#aaa;font-size:13px;"> (${b.branch})</span>`
                : `<span style="color:#0ff;">${b.branch}</span>`;
            const wrapCls = isTop3 ? `branch-rank-wrapper rank-${rank}` : 'branch-rank-wrapper';
            const branchHTML = `<span class="${wrapCls}">${rankBadge}</span>${displayName}`;
            let branch_cls = 'branch';
            let branch_diff_min = 999;
            if (b.lastsale && b.lastsale !== '-') {
                const last_ts = parseDateDMY(b.lastsale);
                if (last_ts) {
                    const now = new Date();
                    branch_diff_min = Math.round((now - last_ts) / 60000);
                }
            }
            if (b.slip === 0) {
                branch_cls = 'no-data';
            } else if (b.is_new) {
                branch_cls = 'branch-new';
            } else if (branch_diff_min <= 10) {
                branch_cls = 'branch-delay-10';
            } else if (branch_diff_min <= 30) {
                branch_cls = 'branch-delay-30';
            } else {
                branch_cls = 'branch-delay-60';
            }
            let diff_text = b.diff;
            if (branch_diff_min < 999) diff_text += ` (${branch_diff_min} นาที)`;
            const tr = document.createElement('tr');
            tr.className = branch_cls;
            const saleOfficeKey = b.sale_office || b.branch;
            tr.setAttribute('data-sale-office', saleOfficeKey);
            tr.innerHTML = `
                <td class="branch-name-cell">${branchHTML}</td>
                <td class="ping-cell" id="ping-${saleOfficeKey}">
                    <span class="ping-dot ping-unknown"></span>
                    <span class="ping-label unknown">-</span>
                </td>
                <td align="right">${b.slip.toLocaleString()}</td>
                <td align="right">${b.item.toLocaleString()}</td>
                <td align="right">${Number(b.amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                <td>${b.lastsale}</td>
                <td>${diff_text}</td>`;
            tbody.appendChild(tr);
            ts += b.slip; ti += b.item; ta += Number(b.amount);

        });
        const s = document.createElement('tr');
        s.className = 'summary-row';
        s.innerHTML = `<td align="center">รวมทั้งหมด (${d.online_machines} สาขา)</td>
            <td></td>
            <td align="right">${ts.toLocaleString()}</td>
            <td align="right">${ti.toLocaleString()}</td>
            <td align="right">${ta.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
            <td colspan="2"></td>`;
        tbody.appendChild(s);
    })
    .catch(e => {
        console.error("Fetch Error:", e);
        document.querySelector('#branch-table tbody').innerHTML = `<tr><td colspan="7" class="error"><h2>AJAX Error</h2>ไม่สามารถเชื่อมต่อได้</td></tr>`;
        document.getElementById('refresh-time').innerText = 'ERROR';
    });
}
<?php if (empty($errors)): ?>
updateDashboard();
fetchPingStatus();
setInterval(updateDashboard, <?= (int)$pos_refresh_interval * 1000 ?>);
setInterval(fetchPingStatus, 2000);
<?php endif; ?>
document.getElementById('refresh-btn').addEventListener('click', updateDashboard);

// jQuery datepicker — วันนี้เท่านั้น (minDate = maxDate = today)
$(function() {
    const opts = {
        dateFormat: 'dd/mm/yy',
        changeMonth: true,
        changeYear: true,
        maxDate: 'today',
        minDate: 'today'
    };
    $("#start_date, #end_date").datepicker(opts);
    $("#start-icon").click(() => $("#start_date").datepicker("show"));
    $("#end-icon").click(() => $("#end_date").datepicker("show"));
    $("#start_date").change(function() { $("#end_date").datepicker("option", "minDate", $(this).val()); });
    $("#end_date").change(function() { $("#start_date").datepicker("option", "maxDate", $(this).val()); });
    $("select").on("keydown", e => { if (e.key === "Enter") { e.preventDefault(); updateDashboard(); } });

    // คืนค่าสาขาที่เลือกจาก URL (ป้องกัน selected ไม่ติดเมื่อ code ไม่ตรง)
    const urlBranch = new URLSearchParams(window.location.search).get('branch') || '';
    const branchSel = document.getElementById('branch-select');
    if (branchSel && urlBranch) {
        branchSel.value = urlBranch;
        // ถ้า option นั้นไม่มีใน dropdown (code ไม่ตรง) ให้ reset เป็นทั้งหมด
        if (branchSel.value !== urlBranch) branchSel.value = '';
    }
});
// expand/collapse handled by POS_TOPRIGHT.php

</script>
</body>
</html>