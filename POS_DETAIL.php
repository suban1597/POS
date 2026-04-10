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
$MENU_ACTIVE = 'detail';
require_once __DIR__ . '/POS_AUTH.php';
require_once __DIR__ . '/POS_SETTINGS.php';
pos_check_expiry(); // ล็อกถ้าบัญชีหมดอายุ
pos_guard('detail');

// M → ไม่อนุญาต redirect ไป POS_MEMBERS.php
if ($pos_priority === 'M') {
    ob_end_clean();
    header('Location: /POS/POS_MEMBERS.php');
    exit;
}
ini_set('display_errors', 1);
error_reporting(E_ALL);
// ---------------------------
// CONFIG
// ---------------------------
$instant_client_path = "/opt/oracle/instantclient_21_4";
$oracle_user = "system";
$oracle_pass = "system";
$oracle_tns  = "CUBACKUP";
$sql_file    = sys_get_temp_dir() . "/POS_DETAIL_" . uniqid() . ".sql";
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
// HISTORY MODE — helpers จาก POS_ALL
// ---------------------------
if (!defined('INSTANT_CLIENT')) define('INSTANT_CLIENT', $instant_client_path);
if (!defined('ORACLE_USER'))    define('ORACLE_USER',    $oracle_user);
if (!defined('ORACLE_PASS'))    define('ORACLE_PASS',    $oracle_pass);
if (!defined('ORACLE_TNS'))     define('ORACLE_TNS',     $oracle_tns);

function get_sqlplus(): string {
    $path = rtrim(INSTANT_CLIENT, '/') . '/sqlplus';
    if (!is_executable($path)) {
        throw new RuntimeException("SQL*Plus ไม่พบ: {$path}");
    }
    return $path;
}

function run_sqlplus(string $sqlplus, string $sql): string {
    $tmp = tempnam(sys_get_temp_dir(), 'POSALL_') . '.sql';
    file_put_contents($tmp, $sql);
    $up  = escapeshellarg(ORACLE_USER . '/' . ORACLE_PASS . '@' . ORACLE_TNS);
    $cmd = "env -i LD_LIBRARY_PATH=" . INSTANT_CLIENT
         . " TNS_ADMIN=" . INSTANT_CLIENT
         . " NLS_LANG=THAI_THAILAND.AL32UTF8"
         . " {$sqlplus} -s {$up} @{$tmp} 2>&1";
    $out = (string) shell_exec($cmd);
    @unlink($tmp);
    return $out;
}



function load_office_list(string $sqlplus): array {
    $sql = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 300 TRIMSPOOL ON\n"
         . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
         . "SELECT TRIM(SALE_OFFICE)||'|'||TRIM(NVL(OFFICE_NAME,SALE_OFFICE))\n"
         . "FROM POS.POS_SALE_OFFICE\n"
         . "ORDER BY SALE_OFFICE;\nEXIT;\n";
    $out = run_sqlplus($sqlplus, $sql);
    $list = [];
    foreach (preg_split('/\r?\n/', $out) as $line) {
        $line = trim($line);
        if ($line === '' || preg_match('/^(ORA-|SP2-)/', $line)) continue;
        $p = explode('|', $line, 2);
        if (count($p) === 2 && $p[0] !== '') {
            $code = trim($p[0]);
            if (!function_exists('pos_can_see_branch') || pos_can_see_branch($code)) {
                $list[$code] = trim($p[1]);
            }
        }
    }
    return $list;
}

function parse_date(string $s): ?DateTime {
    $d = DateTime::createFromFormat('d/m/Y', $s);
    return ($d && $d->format('d/m/Y') === $s) ? $d : null;
}

// ---------------------------
// INPUT
// ---------------------------
$search_mode   = ($_GET['mode'] ?? '') === 'history' ? 'history' : 'detail';
$_yesterday    = date('d/m/Y', strtotime('-1 day'));
$start_date    = trim($_GET['start'] ?? ($search_mode === 'history' ? $_yesterday : date('d/m/Y')));
$end_date      = trim($_GET['end']   ?? ($search_mode === 'history' ? $_yesterday : date('d/m/Y')));
$slip_search   = trim($_GET['slip']  ?? '');
$branch_filter = trim($_GET['branch']     ?? '');
$time_start    = trim($_GET['time_start'] ?? '');
$time_end      = trim($_GET['time_end']   ?? '');
$limit_n       = max(1, min(10000, (int)($_GET['limit_n'] ?? 100)));
$errors = [];

if ($search_mode === 'history') {
    $start_ts = parse_date($start_date);
    $end_ts   = parse_date($end_date);
    if (!$start_ts || !$end_ts) {
        $errors[] = "รูปแบบวันที่ไม่ถูกต้อง";
    } elseif ($start_ts > $end_ts) {
        $errors[] = "วันที่เริ่มต้องไม่เกินวันที่สิ้นสุด";
    }
} else {
    $start_ts = DateTime::createFromFormat('d/m/Y', $start_date);
    $end_ts   = DateTime::createFromFormat('d/m/Y', $end_date);
    if (!$start_ts || !$end_ts || $start_ts->format('d/m/Y') !== $start_date || $end_ts->format('d/m/Y') !== $end_date) {
        $errors[] = "รูปแบบวันที่ไม่ถูกต้อง (ใช้ วว/ดด/ปปปป)";
    } elseif ($start_ts > $end_ts) {
        $errors[] = "วันที่เริ่มต้องไม่เกินวันที่สิ้นสุด";
    }
}

if (isset($_GET["ajax"]) && $_GET["ajax"] === "1" && !empty($errors)) {
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(["ok" => false, "error" => implode(", ", $errors)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── HISTORY AJAX (POS_SALE_HD) ──
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && $search_mode === 'history' && empty($errors)) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $sqlplus     = get_sqlplus();
        $cashier_map = load_cashier_map(
            rtrim($instant_client_path,'/').'/'.'sqlplus',
            $oracle_user, $oracle_pass, $oracle_tns, $instant_client_path
        );
        $office_list = load_office_list($sqlplus);

        $esc_start  = str_replace("'", "''", $start_date);
        $esc_end    = str_replace("'", "''", $end_date);
        $esc_slip   = str_replace("'", "''", $slip_search);
        $esc_branch = str_replace("'", "''", $branch_filter);
        $esc_tstart = preg_match('/^\d{2}:\d{2}$/', $time_start) ? $time_start : '';
        $esc_tend   = preg_match('/^\d{2}:\d{2}$/', $time_end)   ? $time_end   : '';

        // กรองสาขาตามสิทธิ์ USER_BRANCH_ACCESS
        $branch_access_clause    = function_exists('pos_branch_sql') ? pos_branch_sql('h.SALE_OFFICE')  : '1=1';
        $branch_access_clause_h2 = function_exists('pos_branch_sql') ? pos_branch_sql('h2.SALE_OFFICE') : '1=1';

        // ============================================================
        // PL/SQL — ไม่มี loop ตาราง, GROUP BY SALE_OFFICE โดยตรง
        // ============================================================
        $sql = <<<SQL
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
    -- ----
    TYPE t_machine_rec IS RECORD (
        machine_no   VARCHAR2(50),
        machine_desc VARCHAR2(100),
        cashier_id   VARCHAR2(50),
        slip_no      VARCHAR2(50),
        grand_amt    NUMBER,
        create_date  DATE,
        item_cnt     NUMBER,
        member_id    VARCHAR2(50)
    );
    TYPE t_machine_tab IS TABLE OF t_machine_rec;

    TYPE t_branch_rec IS RECORD (
        sale_office VARCHAR2(20),
        office_name VARCHAR2(100),
        slip_cnt    NUMBER,
        grand_amt   NUMBER,
        last_date   DATE,
        item_cnt    NUMBER,
        member_cnt  NUMBER,
        machines    t_machine_tab
    );
    TYPE t_branch_tab IS TABLE OF t_branch_rec;

    -- ----
    TYPE t_str_tab  IS TABLE OF VARCHAR2(100);
    TYPE t_num_tab  IS TABLE OF NUMBER;
    TYPE t_date_tab IS TABLE OF DATE;

    v_data         t_branch_tab := t_branch_tab();
    v_total_amt    NUMBER := 0;
    v_total_slip   NUMBER := 0;
    v_total_item   NUMBER := 0;
    v_total_line   NUMBER := 0;
    v_total_member NUMBER := 0;
    v_max_date     DATE;
    -- ยอดรวมทั้งหมดก่อน limit (Grand Total)
    v_grand_slip   NUMBER := 0;
    v_grand_item   NUMBER := 0;
    v_grand_amt    NUMBER := 0;

    v_start DATE := TO_DATE('$esc_start', 'DD/MM/YYYY');
    v_end   DATE := TO_DATE('$esc_end',   'DD/MM/YYYY') + 1 - 1/86400;
    v_slip_filter  VARCHAR2(100) := UPPER('$esc_slip');
    v_branch_filter VARCHAR2(20) := TRIM('$esc_branch');
    v_time_start   VARCHAR2(5)  := '$esc_tstart';
    v_time_end     VARCHAR2(5)  := '$esc_tend';
    v_limit        NUMBER       := $limit_n;
    v_shown        NUMBER       := 0;  -- นับรายการที่ดึงมาแล้วรวมทุกสาขา

    -- ----
    v_offices   t_str_tab;
    v_onames    t_str_tab;
    v_slip_cnts t_num_tab;
    v_amts      t_num_tab;
    v_last_dts  t_date_tab;
    v_item_cnts t_num_tab;
    v_mem_cnts  t_num_tab;

    -- ----
    v_mnos   t_str_tab;
    v_cids   t_str_tab;
    v_slips  t_str_tab;
    v_mamts  t_num_tab;
    v_mdates t_date_tab;
    v_mids   t_str_tab;

    -- ----
    TYPE t_bc_tab   IS TABLE OF VARCHAR2(50);
    TYPE t_nm_tab   IS TABLE OF VARCHAR2(255);
    v_bc   t_bc_tab;
    v_nm   t_nm_tab;
    v_qty  t_num_tab;
    v_up   t_num_tab;
    v_tot  t_num_tab;

BEGIN
    -- ----
    SELECT /*+ PARALLEL(h,4) PARALLEL(dt_cnt,4) INDEX(h IDX_SALE_HD_CDATE_OFF) */
        TRIM(h.SALE_OFFICE),
        NVL(TRIM(o.OFFICE_NAME), TRIM(h.SALE_OFFICE)),
        COUNT(CASE WHEN dt_cnt.SLIP_NO IS NOT NULL THEN h.SLIP_NO END),  -- นับเฉพาะ slip ที่มี net qty > 0
        NVL(SUM(h.GRAND_AMOUNT), 0),
        MAX(h.CREATE_DATE),
        NVL(SUM(dt_cnt.net_qty), 0),   -- รวม QTY จริง (หลัง void)
        COUNT(DISTINCT NULLIF(NULLIF(NULLIF(h.MEMBER_ID,'-'),'0000000000000'),''))
    BULK COLLECT INTO
        v_offices, v_onames, v_slip_cnts, v_amts, v_last_dts, v_item_cnts, v_mem_cnts
    FROM POS.POS_SALE_HD h
    LEFT JOIN POS.POS_SALE_OFFICE o ON o.SALE_OFFICE = h.SALE_OFFICE
    LEFT JOIN (
        -- นับเฉพาะ SLIP ที่มี BARCODE net QTY > 0 อย่างน้อย 1 รายการ (เหมือน POS_ITEMS)
        SELECT /*+ NO_MERGE */ net.SLIP_NO,
               COUNT(*)         AS item_qty,
               SUM(net.net_qty) AS net_qty
        FROM (
            SELECT d.SLIP_NO, d.BARCODE, SUM(d.QTY) AS net_qty
            FROM POS.POS_SALE_DT d
            JOIN POS.POS_SALE_HD hd ON hd.SLIP_NO = d.SLIP_NO
                                   AND hd.CREATE_DATE >= v_start
                                   AND hd.CREATE_DATE <  v_end  /*+ INDEX(hd IDX_SALE_HD_CDATE_OFF) */
            WHERE d.BARCODE IS NOT NULL
              AND d.QTY <> 0
            GROUP BY d.SLIP_NO, d.BARCODE
            HAVING SUM(d.QTY) > 0
        ) net
        GROUP BY net.SLIP_NO
    ) dt_cnt ON dt_cnt.SLIP_NO = h.SLIP_NO
    WHERE h.CREATE_DATE >= v_start
      AND h.CREATE_DATE <  v_end
      AND h.SALE_OFFICE IS NOT NULL
      AND ({$branch_access_clause})
      AND (v_branch_filter IS NULL OR v_branch_filter = '' OR TRIM(h.SALE_OFFICE) = v_branch_filter)
      AND (v_time_start IS NULL OR v_time_start = '' OR TO_CHAR(h.CREATE_DATE,'HH24:MI') >= v_time_start)
      AND (v_time_end   IS NULL OR v_time_end   = '' OR TO_CHAR(h.CREATE_DATE,'HH24:MI') <= v_time_end)
    GROUP BY TRIM(h.SALE_OFFICE), TRIM(o.OFFICE_NAME)
    ORDER BY NVL(SUM(h.GRAND_AMOUNT), 0) DESC;

    -- ----
    FOR i IN 1..v_offices.COUNT LOOP
        v_data.EXTEND;
        v_data(v_data.LAST).sale_office := v_offices(i);
        v_data(v_data.LAST).office_name := v_onames(i);
        v_data(v_data.LAST).slip_cnt    := v_slip_cnts(i);
        v_data(v_data.LAST).grand_amt   := v_amts(i);
        v_data(v_data.LAST).last_date   := v_last_dts(i);
        v_data(v_data.LAST).item_cnt    := v_item_cnts(i);
        v_data(v_data.LAST).member_cnt  := v_mem_cnts(i);
        v_data(v_data.LAST).machines    := t_machine_tab();

        v_total_slip   := v_total_slip   + NVL(v_slip_cnts(i), 0);
        v_total_amt    := v_total_amt    + NVL(v_amts(i),       0);
        v_total_item   := v_total_item   + NVL(v_item_cnts(i),  0);
        -- ----

        -- ----
        IF v_last_dts(i) IS NOT NULL THEN
            IF v_max_date IS NULL OR v_last_dts(i) > v_max_date THEN
                v_max_date := v_last_dts(i);
            END IF;
        END IF;

        -- ----
        IF v_slip_cnts(i) > 0 AND (v_slip_filter IS NOT NULL AND LENGTH(TRIM(v_slip_filter)) > 0 OR v_shown < v_limit) THEN
            BEGIN
                IF v_slip_filter IS NOT NULL AND LENGTH(TRIM(v_slip_filter)) > 0 THEN
                    -- ค้นหาเลขสลิป: ดึงทั้งหมดที่ตรง (ไม่จำกัด limit)
                    SELECT
                        MACHINE_NO, CASHIER_ID, SLIP_NO, GRAND_AMOUNT, CREATE_DATE, MEMBER_ID
                    BULK COLLECT INTO
                        v_mnos, v_cids, v_slips, v_mamts, v_mdates, v_mids
                    FROM POS.POS_SALE_HD h
                    WHERE SALE_OFFICE = v_offices(i)
                      AND CREATE_DATE >= v_start
                      AND CREATE_DATE <  v_end
                      AND (v_time_start IS NULL OR v_time_start = '' OR TO_CHAR(CREATE_DATE,'HH24:MI') >= v_time_start)
                      AND (v_time_end   IS NULL OR v_time_end   = '' OR TO_CHAR(CREATE_DATE,'HH24:MI') <= v_time_end)
                      AND UPPER(SLIP_NO) LIKE '%' || v_slip_filter || '%'
                    ORDER BY CREATE_DATE DESC;
                ELSE
                    -- ดึงรายการตามเงื่อนไข จำกัดด้วย (v_limit - v_shown) เพื่อนับรวมทุกสาขา
                    SELECT
                        MACHINE_NO, CASHIER_ID, SLIP_NO, GRAND_AMOUNT, CREATE_DATE, MEMBER_ID
                    BULK COLLECT INTO
                        v_mnos, v_cids, v_slips, v_mamts, v_mdates, v_mids
                    FROM (
                        SELECT /*+ INDEX(h IDX_SALE_HD_CDATE_OFF) */
                            MACHINE_NO, CASHIER_ID, SLIP_NO, GRAND_AMOUNT, CREATE_DATE, MEMBER_ID
                        FROM POS.POS_SALE_HD h
                        WHERE SALE_OFFICE = v_offices(i)
                          AND CREATE_DATE >= v_start
                          AND CREATE_DATE <  v_end
                          AND (v_time_start IS NULL OR v_time_start = '' OR TO_CHAR(CREATE_DATE,'HH24:MI') >= v_time_start)
                          AND (v_time_end   IS NULL OR v_time_end   = '' OR TO_CHAR(CREATE_DATE,'HH24:MI') <= v_time_end)
                        ORDER BY CREATE_DATE DESC
                    ) WHERE ROWNUM <= (v_limit - v_shown);
                END IF;
            EXCEPTION WHEN OTHERS THEN
                v_mnos := t_str_tab();
            END;

            FOR k IN 1..v_mnos.COUNT LOOP
                DECLARE
                    v_mdesc VARCHAR2(100) := TRIM(v_mnos(k));
                    v_icnt  NUMBER := 0;
                BEGIN
                    -- ----
                    BEGIN
                        SELECT MIN(TRIM(MACHINE_DESC)) INTO v_mdesc
                        FROM POS.POS_MACHINE
                        WHERE TRIM(SALE_OFFICE) = v_offices(i)
                          AND TRIM(MACHINE_NO)  = TRIM(v_mnos(k));
                        IF v_mdesc IS NULL THEN v_mdesc := TRIM(v_mnos(k)); END IF;
                    EXCEPTION WHEN OTHERS THEN v_mdesc := TRIM(v_mnos(k)); END;

                    -- ----
                    BEGIN
                        SELECT NVL(SUM(net_qty), 0) INTO v_icnt
                        FROM (
                            SELECT d.BARCODE, SUM(d.QTY) AS net_qty
                            FROM POS.POS_SALE_DT d
                            WHERE d.SLIP_NO = v_slips(k)
                              AND d.BARCODE IS NOT NULL
                              AND d.QTY <> 0
                            GROUP BY d.BARCODE
                            HAVING SUM(d.QTY) > 0
                        );
                    EXCEPTION WHEN OTHERS THEN v_icnt := 0; END;

                    v_data(v_data.LAST).machines.EXTEND;
                    DECLARE idx PLS_INTEGER := v_data(v_data.LAST).machines.LAST; BEGIN
                        v_data(v_data.LAST).machines(idx).machine_no   := TRIM(v_mnos(k));
                        v_data(v_data.LAST).machines(idx).machine_desc := v_mdesc;
                        v_data(v_data.LAST).machines(idx).cashier_id   := NVL(v_cids(k), '-');
                        v_data(v_data.LAST).machines(idx).slip_no      := v_slips(k);
                        v_data(v_data.LAST).machines(idx).grand_amt    := v_mamts(k);
                        v_data(v_data.LAST).machines(idx).create_date  := v_mdates(k);
                        v_data(v_data.LAST).machines(idx).item_cnt     := v_icnt;
                        v_data(v_data.LAST).machines(idx).member_id    := NVL(v_mids(k), '-');
                    END;
                END;
            END LOOP;
            -- สะสมจำนวนรายการที่ดึงมาแล้ว
            v_shown := v_shown + v_mnos.COUNT;
        END IF;
    END LOOP;

    -- ----
    -- ----
    FOR i IN 1..v_data.COUNT LOOP
        DECLARE
            v_diff NUMBER;
            v_mark VARCHAR2(1);
        BEGIN
            v_diff := NVL(ROUND((SYSDATE - v_data(i).last_date) * 86400), -1);
            IF v_data(i).last_date IS NOT NULL AND v_data(i).last_date = v_max_date THEN
                v_mark := 'Y';
            ELSE
                v_mark := 'N';
            END IF;
            DBMS_OUTPUT.PUT_LINE(
                'BRANCH|' || v_data(i).sale_office || '|' || v_data(i).office_name || '|' ||
                TO_CHAR(v_data(i).slip_cnt) || '|' || TO_CHAR(v_data(i).item_cnt)  || '|' ||
                TO_CHAR(v_data(i).grand_amt, 'FM999999999990.00') || '|' ||
                NVL(TO_CHAR(v_data(i).last_date, 'DD/MM/YYYY HH24:MI:SS'), '-') || '|' ||
                TO_CHAR(v_diff) || '|' || v_mark || '|' ||
                TO_CHAR(NVL(v_data(i).member_cnt, 0))
            );

            -- ----
            FOR j IN 1..v_data(i).machines.COUNT LOOP
                DBMS_OUTPUT.PUT_LINE(
                    'MACHINE|' || v_data(i).machines(j).machine_no   || '|' ||
                    v_data(i).machines(j).machine_desc || '|' ||
                    NVL(v_data(i).machines(j).cashier_id, '-') || '|' ||
                    NVL(v_data(i).machines(j).slip_no,    '-') || '|' ||
                    TO_CHAR(NVL(v_data(i).machines(j).grand_amt, 0), 'FM999999999990.00') || '|' ||
                    NVL(TO_CHAR(v_data(i).machines(j).create_date, 'DD/MM/YYYY HH24:MI:SS'), '-') || '|' ||
                    TO_CHAR(NVL(v_data(i).machines(j).item_cnt, 0)) || '|' ||
                    NVL(v_data(i).machines(j).member_id, '-')
                );

                -- ----
                BEGIN
                    SELECT
                        d.BARCODE,
                        SUBSTR(NVL(TRIM(p.PRODUCT_DESC), d.BARCODE), 1, 100),
                        d.QTY, d.UNIT_PRICE, d.TOTAL_AMOUNT
                    BULK COLLECT INTO v_bc, v_nm, v_qty, v_up, v_tot
                    FROM POS.POS_SALE_DT d
                    LEFT JOIN POS.POS_PRODUCT p ON p.BARCODE = d.BARCODE
                    WHERE d.SLIP_NO = v_data(i).machines(j).slip_no;

                    FOR k IN 1..v_bc.COUNT LOOP
                        DBMS_OUTPUT.PUT_LINE(
                            'ITEM_ROW|' || NVL(TRIM(v_bc(k)), '-') || '|' ||
                            NVL(TRIM(v_nm(k)), '-') || '|' ||
                            TO_CHAR(NVL(v_qty(k), 0), 'FM999999990') || '|' ||
                            TO_CHAR(NVL(v_up(k),  0), 'FM999999990.00') || '|' ||
                            TO_CHAR(NVL(v_tot(k), 0), 'FM999999990.00')
                        );
                    END LOOP;
                EXCEPTION WHEN OTHERS THEN NULL;
                END;
            END LOOP;
        END;
    END LOOP;

    SELECT COUNT(DISTINCT h2.MEMBER_ID) INTO v_total_member
    FROM POS.POS_SALE_HD h2
    WHERE h2.MEMBER_ID IS NOT NULL
      AND TRIM(h2.MEMBER_ID) IS NOT NULL
      AND h2.MEMBER_ID != '-'
      AND h2.MEMBER_ID != '0000000000000'
      AND h2.CREATE_DATE >= v_start
      AND h2.CREATE_DATE <  v_end
      AND (v_branch_filter IS NULL OR v_branch_filter = '' OR TRIM(h2.SALE_OFFICE) = v_branch_filter)
      AND (v_time_start IS NULL OR v_time_start = '' OR TO_CHAR(h2.CREATE_DATE,'HH24:MI') >= v_time_start)
      AND (v_time_end   IS NULL OR v_time_end   = '' OR TO_CHAR(h2.CREATE_DATE,'HH24:MI') <= v_time_end);

    SELECT COUNT(*) INTO v_total_line
    FROM (
        SELECT d2.BARCODE
        FROM POS.POS_SALE_DT d2
        JOIN POS.POS_SALE_HD h2 ON h2.SLIP_NO = d2.SLIP_NO
        WHERE d2.BARCODE IS NOT NULL
          AND d2.QTY <> 0
          AND h2.CREATE_DATE >= v_start
          AND h2.CREATE_DATE <  v_end
          AND ({$branch_access_clause_h2})
          AND (v_branch_filter IS NULL OR v_branch_filter = '' OR TRIM(h2.SALE_OFFICE) = v_branch_filter)
          AND (v_time_start IS NULL OR v_time_start = '' OR TO_CHAR(h2.CREATE_DATE,'HH24:MI') >= v_time_start)
          AND (v_time_end   IS NULL OR v_time_end   = '' OR TO_CHAR(h2.CREATE_DATE,'HH24:MI') <= v_time_end)
        GROUP BY d2.BARCODE
        HAVING SUM(d2.QTY) > 0
    );

    -- คำนวณ Grand Total (ทุกรายการตามเงื่อนไข ไม่ถูก limit)
    BEGIN
        SELECT NVL(COUNT(*),0), NVL(SUM(dt_cnt.net_qty),0), NVL(SUM(h.GRAND_AMOUNT),0)
        INTO v_grand_slip, v_grand_item, v_grand_amt
        FROM POS.POS_SALE_HD h
        LEFT JOIN (
            SELECT net.SLIP_NO, SUM(net.net_qty) AS net_qty
            FROM (
                SELECT d.SLIP_NO, d.BARCODE, SUM(d.QTY) AS net_qty
                FROM POS.POS_SALE_DT d
                JOIN POS.POS_SALE_HD hd ON hd.SLIP_NO = d.SLIP_NO
                                       AND hd.CREATE_DATE >= v_start
                                       AND hd.CREATE_DATE <  v_end
                WHERE d.BARCODE IS NOT NULL AND d.QTY <> 0
                GROUP BY d.SLIP_NO, d.BARCODE HAVING SUM(d.QTY) > 0
            ) net GROUP BY net.SLIP_NO
        ) dt_cnt ON dt_cnt.SLIP_NO = h.SLIP_NO
        WHERE h.CREATE_DATE >= v_start
          AND h.CREATE_DATE <  v_end
          AND h.SALE_OFFICE IS NOT NULL
          AND ({$branch_access_clause})
          AND (v_branch_filter IS NULL OR v_branch_filter = '' OR TRIM(h.SALE_OFFICE) = v_branch_filter)
          AND (v_time_start IS NULL OR v_time_start = '' OR TO_CHAR(h.CREATE_DATE,'HH24:MI') >= v_time_start)
          AND (v_time_end   IS NULL OR v_time_end   = '' OR TO_CHAR(h.CREATE_DATE,'HH24:MI') <= v_time_end);
    EXCEPTION WHEN OTHERS THEN v_grand_slip:=0; v_grand_item:=0; v_grand_amt:=0;
    END;

    DBMS_OUTPUT.PUT_LINE(
        'TOTAL|' || TO_CHAR(v_total_slip)  || '|' ||
        TO_CHAR(v_total_item)  || '|' ||
        TO_CHAR(v_total_line)  || '|' ||
        TO_CHAR(v_total_amt, 'FM999999999990.00') || '|' ||
        TO_CHAR(v_total_member)
    );
    DBMS_OUTPUT.PUT_LINE(
        'GRANDTOTAL|' || TO_CHAR(v_grand_slip) || '|' ||
        TO_CHAR(v_grand_item) || '|' ||
        TO_CHAR(v_grand_amt, 'FM999999999990.00')
    );

EXCEPTION WHEN OTHERS THEN
    DBMS_OUTPUT.PUT_LINE('FATAL|' || SQLERRM);
END;
/
EXIT;
SQL;

        $output = run_sqlplus($sqlplus, $sql);

        // ตรวจ FATAL
        foreach (preg_split('/\r?\n/', $output) as $ln) {
            $ln = trim($ln);
            if (strpos($ln, 'FATAL|') === 0) {
                throw new RuntimeException(substr($ln, 6));
            }
        }
        // ตรวจ ORA-/SP2- ที่ไม่มี | (session error)
        foreach (preg_split('/\r?\n/', $output) as $ln) {
            $ln = trim($ln);
            if ($ln === '' || strpos($ln, '|') !== false) continue;
            if (preg_match('/^(ORA-|SP2-)/', $ln)) {
                throw new RuntimeException($ln);
            }
        }

        // ── Parse ──────────────────────────────────────────────
        $data          = [];
        $current       = null;
        $total_slip    = $total_item = $total_line = $total_amount = $total_members = 0;
        $grand_slip = $grand_item = 0; $grand_amount = 0.0;

        foreach (preg_split('/\r?\n/', $output) as $raw) {
            $line = trim($raw);
            if ($line === '') continue;

            if (strpos($line, 'BRANCH|') === 0) {
                // BRANCH|sale_office|office_name|slip|item|amount|lastsale|diff_sec|is_new|member_cnt
                $p = explode('|', $line, 10);
                if (count($p) < 9) continue;
                $office      = trim($p[1]);
                $office_name = trim($p[2]);
                $slip        = (int)   $p[3];
                $item        = (int)   $p[4];
                $amount      = (float) $p[5];
                $lastsale    = trim($p[6]);
                $diff_sec    = (int)   $p[7];
                $is_new      = trim($p[8]) === 'Y';
                $member_cnt  = isset($p[9]) ? (int)trim($p[9]) : 0;
                $diff_text   = $diff_sec >= 0
                    ? sprintf('+%02d:%02d:%02d',
                        intdiv($diff_sec, 3600),
                        intdiv($diff_sec % 3600, 60),
                        $diff_sec % 60)
                    : '';
                $ls_ts = 0;
                if ($lastsale !== '-' &&
                    preg_match('/(\d{2})\/(\d{2})\/(\d{4}) (\d{2}):(\d{2}):(\d{2})/', $lastsale, $tm)) {
                    $ls_ts = mktime((int)$tm[4],(int)$tm[5],(int)$tm[6],(int)$tm[2],(int)$tm[1],(int)$tm[3]);
                }
                $data[$office] = [
                    'branch'      => $office,
                    'office_name' => $office_name,
                    'slip'        => $slip,
                    'item'        => $item,
                    'amount'      => $amount,
                    'lastsale'    => $lastsale,
                    'lastsale_ts' => $ls_ts,
                    'diff'        => $diff_text,
                    'is_new'      => $is_new,
                    'member_cnt'  => $member_cnt,
                    'machines'    => [],
                ];
                $current      = $office;
                $total_slip   += $slip;
                $total_item   += $item;
                $total_amount += $amount;

            } elseif (strpos($line, 'MACHINE|') === 0 && $current) {
                $p = explode('|', $line, 9);
                if (count($p) < 9) continue;
                $slip_no = trim($p[4]);
                if ($slip_search !== '' && stripos($slip_no, $slip_search) === false) continue;
                $cashier_id = trim($p[3]);
                $data[$current]['machines'][] = [
                    'machine'      => trim($p[1]),
                    'machine_desc' => trim($p[2]),
                    'cashier'      => $cashier_id,
                    'cashier_name' => $cashier_map[$cashier_id] ?? '',
                    'slip'         => $slip_no,
                    'amount'       => (float) $p[5],
                    'date'         => trim($p[6]),
                    'item'         => (int)   $p[7],
                    'member_id'    => trim($p[8]) === '-' ? '' : trim($p[8]),
                    'items'        => [],
                ];

            } elseif (strpos($line, 'ITEM_ROW|') === 0 && $current) {
                $last_idx = count($data[$current]['machines']) - 1;
                if ($last_idx >= 0) {
                    $p = explode('|', $line, 6);
                    if (count($p) >= 6) {
                        $data[$current]['machines'][$last_idx]['items'][] = [
                            'barcode'    => trim($p[1]),
                            'name'       => trim($p[2]),
                            'qty'        => (int)   $p[3],
                            'unit_price' => (float) $p[4],
                            'total_amt'  => (float) $p[5],
                        ];
                    }
                }

            } elseif (strpos($line, 'GRANDTOTAL|') === 0) {
                $p = explode('|', $line);
                if (count($p) >= 4) {
                    $grand_slip   = (int)   $p[1];
                    $grand_item   = (int)   $p[2];
                    $grand_amount = (float) $p[3];
                }
            } elseif (strpos($line, 'TOTAL|') === 0) {
                $p = explode('|', $line, 6);
                if (count($p) >= 6) {
                    $total_slip    = (int)   $p[1];
                    $total_item    = (int)   $p[2];
                    $total_line    = (int)   $p[3];
                    $total_amount  = (float) $p[4];
                    $total_members = (int)   $p[5];
                }
            }
        }

        // กรองสาขาตามสิทธิ์
        if (function_exists('pos_get_branches') && pos_get_branches() !== null) {
            $data = array_filter($data, fn($b) => pos_can_see_branch($b['branch']));
        }

        // กรองตาม branch_filter ที่เลือก
        if ($branch_filter !== '') {
            $data = array_filter($data, fn($b) => $b['branch'] === $branch_filter);
        }

        // คำนวณยอดรวมใหม่จาก $data จริงเสมอ
        $total_slip = $total_item = 0; $total_amount = 0.0;
        foreach ($data as $b) {
            $total_slip    += $b['slip'];
            $total_item    += $b['item'];
            $total_amount  += $b['amount'];
        }
        // $total_members และ $total_line คงไว้จาก SQL TOTAL line
        // (SQL กรอง branch_filter และนับ DISTINCT ถูกต้องแล้ว)

        // sort: amount DESC → lastsale DESC
        usort($data, function($a, $b) {
            $c = $b['amount'] <=> $a['amount'];
            return $c !== 0 ? $c : ($b['lastsale_ts'] <=> $a['lastsale_ts']);
        });

        // sort machines per branch
        foreach ($data as &$br) {
            if (!empty($br['machines'])) {
                usort($br['machines'], function($a, $b) {
                    $af = DateTime::createFromFormat('d/m/Y H:i:s', $a['date'], new DateTimeZone('Asia/Bangkok'));
                    $bf = DateTime::createFromFormat('d/m/Y H:i:s', $b['date'], new DateTimeZone('Asia/Bangkok'));
                    return ($bf ? $bf->getTimestamp() : 0) <=> ($af ? $af->getTimestamp() : 0);
                });
            }
        }
        unset($br);

        $online = $no_data = 0;
        foreach ($data as $b) { $b['slip'] > 0 ? $online++ : $no_data++; }
        $no_data_global = ($online === 0);

        // chart labels: "ชื่อสาขา (OFFICE_CODE)"
        $chart_labels = [];
        $chart_data   = [];
        foreach ($data as $b) {
            $label = ($b['office_name'] && $b['office_name'] !== $b['branch'])
                ? $b['office_name'] . ' (' . $b['branch'] . ')'
                : $b['branch'];
            $chart_labels[] = $label;
            $chart_data[]   = round($b['amount'], 2);
        }

        echo json_encode([
            'ok'              => true,
            'no_data_global'  => $no_data_global,
            'message'         => 'ไม่มีข้อมูลรายการขายวันนี้',
            'refresh_time'    => date('d/m/Y H:i:s'),
            'start_date'      => $start_date,
            'end_date'        => $end_date,
            'slip_search'     => $slip_search,
            'branch_filter'   => $branch_filter,
            'time_start'      => $time_start,
            'time_end'        => $time_end,
            'online_machines' => $online,
            'no_data_count'   => $no_data,
            'all_branches'    => $online + $no_data,
            'total_slip'      => $total_slip,
            'total_item'      => $total_item,
            'total_line'      => $total_line,
            'total_amount'    => $total_amount,
            'total_members'   => $total_members,
            'grand_slip'      => $grand_slip,
            'grand_item'      => $grand_item,
            'grand_amount'    => $grand_amount,
            'limit_n'         => $limit_n,
            'chart_labels'    => $chart_labels,
            'chart_data'      => $chart_data,
            'branches'        => array_values($data),
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}


// ---------------------------
// AJAX REQUEST
// ---------------------------
// ---------------------------
// DEBUG: ?debug=1  →  ดูค่า SALE_OFFICE จริงในฐานข้อมูล
// ลบออกหลัง debug เสร็จ
// ---------------------------
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    $instant_client_path = rtrim($instant_client_path, '/');
    $sqlplus_path = "{$instant_client_path}/sqlplus";
    $debug_sql = sys_get_temp_dir() . '/POS_DEBUG_' . uniqid() . '.sql';
    file_put_contents($debug_sql,
        "SET ECHO OFF FEEDBACK OFF PAGESIZE 100 LINESIZE 400 TRIMSPOOL ON\n" .
        "ALTER SESSION SET NLS_LANGUAGE = American;\n" .
        "COLUMN SALE_OFFICE  FORMAT A10\n" .
        "COLUMN OFFICE_NAME  FORMAT A40\n" .
        "COLUMN LEN          FORMAT 999\n" .
        "COLUMN DUMP_VAL     FORMAT A60\n" .
        "-- ค่าใน POS_SALE_OFFICE\n" .
        "SELECT SALE_OFFICE, OFFICE_NAME,\n" .
        "       LENGTH(SALE_OFFICE) AS LEN,\n" .
        "       DUMP(SALE_OFFICE) AS DUMP_VAL\n" .
        "FROM POS.POS_SALE_OFFICE\n" .
        "ORDER BY SALE_OFFICE;\n" .
        "-- suffix ของ HD tables\n" .
        "SELECT REPLACE(table_name,'POS_SALETODAY_HD_','') AS BRANCH_CODE,\n" .
        "       LENGTH(REPLACE(table_name,'POS_SALETODAY_HD_','')) AS LEN\n" .
        "FROM all_tables\n" .
        "WHERE owner='POS' AND table_name LIKE 'POS_SALETODAY_HD_%'\n" .
        "ORDER BY table_name;\n" .
        "EXIT;\n"
    );
    $cmd = "env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 " .
           "{$sqlplus_path} -s " . escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}") . " @{$debug_sql} 2>&1";
    echo shell_exec($cmd);
    @unlink($debug_sql);
    exit;
}

// ---------------------------
// AJAX ENDPOINT — stat การ์ด (โหลดทันทีเมื่อ page load)
// ---------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && isset($_GET['stat']) && $_GET['stat'] === 'detail') {
    header('Content-Type: application/json; charset=utf-8');
    $instant_client_path = rtrim($instant_client_path, '/');
    $sqlplus_path = "{$instant_client_path}/sqlplus";
    if (!is_executable($sqlplus_path)) {
        echo json_encode(['error' => "SQL*Plus Not Found"]); exit;
    }
    $up_s = escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}");
    // --- นับสินค้าทั้งหมด ---
    $all_product_count = 0;
    $sql_p = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 300 TRIMSPOOL ON\n"
           . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
           . "SELECT TRIM(TO_CHAR(COUNT(*))) FROM POS.POS_PRODUCT\n"
           . "WHERE BARCODE IS NOT NULL AND TRIM(BARCODE) IS NOT NULL;\n"
           . "EXIT;\n";
    $tmp_p = sys_get_temp_dir() . '/POS_DP_' . uniqid() . '.sql';
    file_put_contents($tmp_p, $sql_p);
    $out_p = shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s {$up_s} @{$tmp_p} 2>&1");
    @unlink($tmp_p);
    foreach (explode("\n", (string)$out_p) as $cl) {
        $cl = trim($cl);
        if ($cl !== '' && is_numeric($cl)) { $all_product_count = (int)$cl; break; }
    }
    // --- นับสมาชิกทั้งหมด ---
    $all_member_count = 0;
    $sql_m = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 300 TRIMSPOOL ON\n"
           . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
           . "SELECT TRIM(TO_CHAR(COUNT(DISTINCT MEMBER_ID))) FROM POS.POS_MEMBER_POINT\n"
           . "WHERE MEMBER_ID IS NOT NULL AND TRIM(MEMBER_ID) IS NOT NULL;\n"
           . "EXIT;\n";
    $tmp_m = sys_get_temp_dir() . '/POS_DM_' . uniqid() . '.sql';
    file_put_contents($tmp_m, $sql_m);
    $out_m = shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s {$up_s} @{$tmp_m} 2>&1");
    @unlink($tmp_m);
    foreach (explode("\n", (string)$out_m) as $cl) {
        $cl = trim($cl);
        if ($cl !== '' && is_numeric($cl)) { $all_member_count = (int)$cl; break; }
    }
    echo json_encode([
        'all_product_count' => $all_product_count,
        'all_member_count'  => $all_member_count,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && empty($errors)) {
    header('Content-Type: application/json');
    $instant_client_path = rtrim($instant_client_path, '/');
    $sqlplus_path = "{$instant_client_path}/sqlplus";
    if (!is_executable($sqlplus_path)) {
        echo json_encode(['error' => "SQL*Plus Not Found: {$sqlplus_path}", 'output' => '']);
        exit;
    }
    $cashier_map = load_cashier_map($sqlplus_path, $oracle_user, $oracle_pass, $oracle_tns, $instant_client_path);
    $esc_slip    = str_replace("'", "''", strtoupper($slip_search));
    $esc_branch  = str_replace("'", "''", $branch_filter);
    // สร้าง list สาขาที่มีสิทธิ์ สำหรับกรองใน PL/SQL IF condition (ไม่ใส่ใน EXECUTE IMMEDIATE string)
    $allowed_branches = function_exists('pos_get_branches') ? pos_get_branches() : null;
    // สร้าง PL/SQL condition เปรียบเทียบ sale_office กับ list ที่อนุญาต
    // null = ทุกสาขา, [] = ไม่มีสิทธิ์, ['A','B'] = เฉพาะที่ระบุ
    if ($allowed_branches === null) {
        $plsql_branch_access = 'TRUE'; // ดูได้ทุกสาขา
    } elseif (empty($allowed_branches)) {
        $plsql_branch_access = 'FALSE'; // ไม่มีสิทธิ์เลย
    } else {
        $parts = array_map(fn($b) => "NVL(v_data(i).sale_office,v_data(i).branch)='" . str_replace("'","''",$b) . "'", $allowed_branches);
        $plsql_branch_access = '(' . implode(' OR ', $parts) . ')';
    }
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
    v_start DATE;
    v_end   DATE;
    v_slip_filter   VARCHAR2(100) := UPPER('$esc_slip');
    v_branch_filter VARCHAR2(100) := TRIM('$esc_branch');
    v_total_slip   NUMBER := 0;
    v_total_item   NUMBER := 0;
    v_total_line   NUMBER := 0;
    v_total_amt    NUMBER := 0;
    v_total_member NUMBER := 0;
    v_max_date     DATE;

    TYPE t_str_tab  IS TABLE OF VARCHAR2(200);
    TYPE t_num_tab  IS TABLE OF NUMBER;
    TYPE t_date_tab IS TABLE OF DATE;

    TYPE t_machine_rec IS RECORD (
        machine_no   VARCHAR2(50),
        machine_desc VARCHAR2(100),
        cashier_id   VARCHAR2(50),
        slip_no      VARCHAR2(50),
        grand_amt    NUMBER,
        create_date  DATE,
        item_cnt     NUMBER,
        member_id    VARCHAR2(50)
    );
    TYPE t_machine_tab IS TABLE OF t_machine_rec;

    TYPE t_branch_rec IS RECORD (
        branch      VARCHAR2(100),
        sale_office VARCHAR2(20),
        office_name VARCHAR2(100),
        slip_cnt    NUMBER,
        grand_amt   NUMBER,
        last_date   DATE,
        item_cnt    NUMBER,
        member_cnt  NUMBER,
        machines    t_machine_tab
    );
    TYPE t_branch_tab IS TABLE OF t_branch_rec;

    v_data     t_branch_tab := t_branch_tab();
    v_tmp_rec  t_branch_rec;

    v_offices   t_str_tab;
    v_onames    t_str_tab;
    v_slip_cnts t_num_tab;
    v_amts      t_num_tab;
    v_last_dts  t_date_tab;
    v_item_cnts t_num_tab;
    v_mem_cnts  t_num_tab;

    v_mnos   t_str_tab;
    v_cids   t_str_tab;
    v_slips  t_str_tab;
    v_mamts  t_num_tab;
    v_mdates t_date_tab;
    v_mids   t_str_tab;

    v_bc   t_str_tab;
    v_nm   t_str_tab;
    v_qty  t_num_tab;
    v_up   t_num_tab;
    v_tot  t_num_tab;

    CURSOR c_tables IS
        SELECT table_name
        FROM all_tables
        WHERE owner = 'POS'
          AND table_name LIKE 'POS_SALETODAY_HD_%'
          AND REPLACE(table_name,'POS_SALETODAY_HD_','') NOT IN ('_TMP','TEST99')
        ORDER BY table_name;

    v_diff NUMBER;
    v_mark VARCHAR2(1);
    v_sc   VARCHAR2(10);
    v_oname VARCHAR2(100);
    TYPE t_bc_dedup_tab IS TABLE OF VARCHAR2(1) INDEX BY VARCHAR2(50);
    v_bc_dedup t_bc_dedup_tab;

BEGIN
    v_start := TO_DATE(:start_date,'DD/MM/YYYY');
    v_end   := TO_DATE(:end_date,'DD/MM/YYYY') + 1 - 1/86400;

    FOR rec IN c_tables LOOP
        DECLARE
            v_branch   VARCHAR2(100) := REPLACE(rec.table_name,'POS_SALETODAY_HD_','');
            v_dt_table VARCHAR2(100) := REPLACE(rec.table_name,'HD','DT');
            v_sum_amt  NUMBER := 0;
            v_sum_slip NUMBER := 0;
            v_sum_item NUMBER := 0;
            v_last     DATE;
            v_mcnt     NUMBER := 0;
            v_oname2   VARCHAR2(100) := v_branch;
            v_sc2      VARCHAR2(10);
        BEGIN
            IF v_branch NOT IN ('_TMP','TEST99') THEN
                BEGIN
                    EXECUTE IMMEDIATE
                        'SELECT TRIM(SALE_OFFICE) FROM POS.'||rec.table_name||
                        ' WHERE SALE_OFFICE IS NOT NULL AND ROWNUM=1'
                    INTO v_sc2;
                    SELECT NVL(TRIM(OFFICE_NAME),v_branch) INTO v_oname2
                    FROM POS.POS_SALE_OFFICE
                    WHERE TRIM(SALE_OFFICE)=v_sc2 AND ROWNUM=1;
                EXCEPTION WHEN OTHERS THEN v_oname2 := v_branch; END;

                IF (v_branch_filter IS NULL OR v_branch_filter = ''
                    OR TRIM(v_sc2) = v_branch_filter OR v_branch = v_branch_filter) THEN

                BEGIN
                    EXECUTE IMMEDIATE
                        'SELECT NVL(SUM(GRAND_AMOUNT),0), MAX(CREATE_DATE)'||
                        ' FROM POS.'||rec.table_name||
                        ' WHERE CREATE_DATE>=:1 AND CREATE_DATE<:2'
                    INTO v_sum_amt, v_last USING v_start, v_end;
                EXCEPTION WHEN OTHERS THEN v_sum_amt:=0; v_last:=NULL; END;

                BEGIN
                    EXECUTE IMMEDIATE
                        'SELECT COUNT(*), NVL(SUM(net_qty),0) FROM ('||
                        '  SELECT h.SLIP_NO, SUM(net.net_qty) AS net_qty FROM POS.'||rec.table_name||' h'||
                        '  JOIN ('||
                        '    SELECT dt.SLIP_NO, SUM(dt.QTY) AS net_qty FROM POS.'||v_dt_table||' dt'||
                        '    WHERE dt.BARCODE IS NOT NULL AND dt.QTY<>0'||
                        '    GROUP BY dt.SLIP_NO, dt.BARCODE HAVING SUM(dt.QTY)>0'||
                        '  ) net ON net.SLIP_NO = h.SLIP_NO'||
                        '  WHERE h.CREATE_DATE>=:1 AND h.CREATE_DATE<:2'||
                        '  GROUP BY h.SLIP_NO'||
                        ')'
                    INTO v_sum_slip, v_sum_item USING v_start, v_end;
                EXCEPTION WHEN OTHERS THEN v_sum_slip:=0; v_sum_item:=0; END;

                BEGIN
                    EXECUTE IMMEDIATE
                        'SELECT COUNT(DISTINCT MEMBER_ID) FROM POS.'||rec.table_name||
                        ' WHERE MEMBER_ID IS NOT NULL AND MEMBER_ID!=''-'''||
                        ' AND MEMBER_ID!=''0000000000000'''||
                        ' AND CREATE_DATE>=:1 AND CREATE_DATE<:2'
                    INTO v_mcnt USING v_start,v_end;
                EXCEPTION WHEN OTHERS THEN v_mcnt:=0; END;

                v_data.EXTEND;
                v_data(v_data.LAST).branch      := v_branch;
                v_data(v_data.LAST).sale_office := NVL(v_sc2, v_branch);
                v_data(v_data.LAST).office_name := v_oname2;
                v_data(v_data.LAST).slip_cnt    := v_sum_slip;
                v_data(v_data.LAST).grand_amt   := v_sum_amt;
                v_data(v_data.LAST).last_date   := v_last;
                v_data(v_data.LAST).item_cnt    := v_sum_item;
                v_data(v_data.LAST).member_cnt  := v_mcnt;
                v_data(v_data.LAST).machines    := t_machine_tab();
                v_total_amt    := v_total_amt    + NVL(v_sum_amt,0);
                v_total_slip   := v_total_slip   + NVL(v_sum_slip,0);
                v_total_item   := v_total_item   + NVL(v_sum_item,0);
                IF v_last IS NOT NULL AND (v_max_date IS NULL OR v_last > v_max_date) THEN
                    v_max_date := v_last;
                END IF;

                IF v_sum_slip > 0 THEN
                    BEGIN
                        IF v_slip_filter IS NOT NULL AND LENGTH(TRIM(v_slip_filter))>0 THEN
                            EXECUTE IMMEDIATE
                                'SELECT MACHINE_NO,CASHIER_ID,SLIP_NO,GRAND_AMOUNT,CREATE_DATE,MEMBER_ID'||
                                ' FROM POS.'||rec.table_name||
                                ' WHERE CREATE_DATE>=:1 AND CREATE_DATE<:2'||
                                ' AND UPPER(SLIP_NO) LIKE ''%''||:3||''%'''||
                                ' ORDER BY CREATE_DATE DESC'
                            BULK COLLECT INTO v_mnos,v_cids,v_slips,v_mamts,v_mdates,v_mids
                            USING v_start,v_end,v_slip_filter;
                        ELSE
                            EXECUTE IMMEDIATE
                                'SELECT MACHINE_NO,CASHIER_ID,SLIP_NO,GRAND_AMOUNT,CREATE_DATE,MEMBER_ID'||
                                ' FROM(SELECT MACHINE_NO,CASHIER_ID,SLIP_NO,GRAND_AMOUNT,CREATE_DATE,MEMBER_ID,'||
                                'ROW_NUMBER()OVER(PARTITION BY MACHINE_NO ORDER BY CREATE_DATE DESC) rn'||
                                ' FROM POS.'||rec.table_name||
                                ' WHERE CREATE_DATE>=:1 AND CREATE_DATE<:2'||
                                ')WHERE rn=1 ORDER BY MACHINE_NO'
                            BULK COLLECT INTO v_mnos,v_cids,v_slips,v_mamts,v_mdates,v_mids
                            USING v_start,v_end;
                        END IF;

                        FOR k IN 1..v_mnos.COUNT LOOP
                            DECLARE
                                v_mdesc VARCHAR2(100) := TRIM(v_mnos(k));
                                v_icnt  NUMBER := 0;
                            BEGIN
                                BEGIN
                                    EXECUTE IMMEDIATE
                                        'SELECT MIN(TRIM(MACHINE_DESC)) FROM POS.POS_MACHINE'||
                                        ' WHERE TRIM(SALE_OFFICE)=:1 AND TRIM(MACHINE_NO)=:2'
                                    INTO v_mdesc USING v_branch,TRIM(v_mnos(k));
                                    IF v_mdesc IS NULL THEN v_mdesc:=TRIM(v_mnos(k)); END IF;
                                EXCEPTION WHEN OTHERS THEN v_mdesc:=TRIM(v_mnos(k)); END;
                                BEGIN
                                    EXECUTE IMMEDIATE
                                        'SELECT NVL(SUM(net_qty),0) FROM ('||
                                        '  SELECT dt.BARCODE, SUM(dt.QTY) AS net_qty FROM POS.'||v_dt_table||' dt'||
                                        '  WHERE dt.SLIP_NO=:1 AND dt.BARCODE IS NOT NULL AND dt.QTY<>0'||
                                        '  GROUP BY dt.BARCODE HAVING SUM(dt.QTY)>0'||
                                        ')'
                                    INTO v_icnt USING v_slips(k);
                                EXCEPTION WHEN OTHERS THEN v_icnt:=0; END;

                                v_data(v_data.LAST).machines.EXTEND;
                                DECLARE idx PLS_INTEGER := v_data(v_data.LAST).machines.LAST; BEGIN
                                    v_data(v_data.LAST).machines(idx).machine_no   := TRIM(v_mnos(k));
                                    v_data(v_data.LAST).machines(idx).machine_desc := v_mdesc;
                                    v_data(v_data.LAST).machines(idx).cashier_id   := NVL(v_cids(k),'-');
                                    v_data(v_data.LAST).machines(idx).slip_no      := v_slips(k);
                                    v_data(v_data.LAST).machines(idx).grand_amt    := v_mamts(k);
                                    v_data(v_data.LAST).machines(idx).create_date  := v_mdates(k);
                                    v_data(v_data.LAST).machines(idx).item_cnt     := v_icnt;
                                    v_data(v_data.LAST).machines(idx).member_id    := NVL(v_mids(k),'-');
                                END;
                            END;
                        END LOOP;
                    EXCEPTION WHEN OTHERS THEN NULL;
                    END;
                END IF; -- v_sum_slip > 0
                END IF; -- v_branch_filter
            END IF; -- NOT IN _TMP/TEST99
        EXCEPTION WHEN OTHERS THEN NULL;
        END;
    END LOOP;

    FOR i IN 1..v_data.COUNT-1 LOOP
        FOR j IN i+1..v_data.COUNT LOOP
            IF NVL(v_data(i).grand_amt,0)<NVL(v_data(j).grand_amt,0)
               OR (NVL(v_data(i).grand_amt,0)=NVL(v_data(j).grand_amt,0)
                   AND NVL(v_data(i).last_date,DATE'1900-01-01')<NVL(v_data(j).last_date,DATE'1900-01-01'))
            THEN
                v_tmp_rec:=v_data(i); v_data(i):=v_data(j); v_data(j):=v_tmp_rec;
            END IF;
        END LOOP;
    END LOOP;

    FOR i IN 1..v_data.COUNT LOOP
        IF v_data(i).last_date IS NULL THEN
            v_diff := -1;
        ELSE
            v_diff := ROUND((SYSDATE - v_data(i).last_date)*86400);
        END IF;
        IF v_data(i).last_date IS NOT NULL AND v_data(i).last_date = v_max_date THEN
            v_mark := 'Y';
        ELSE
            v_mark := 'N';
        END IF;
        DBMS_OUTPUT.PUT_LINE(
            'BRANCH|'||NVL(v_data(i).sale_office,v_data(i).branch)||'|'||v_data(i).office_name||'|'||
            TO_CHAR(v_data(i).slip_cnt)||'|'||TO_CHAR(v_data(i).item_cnt)||'|'||
            TO_CHAR(v_data(i).grand_amt,'FM999999999990.00')||'|'||
            NVL(TO_CHAR(v_data(i).last_date,'DD/MM/YYYY HH24:MI:SS'),'-')||'|'||
            TO_CHAR(v_diff)||'|'||v_mark||'|'||
            TO_CHAR(NVL(v_data(i).member_cnt,0))
        );
        FOR j IN 1..v_data(i).machines.COUNT LOOP
            DBMS_OUTPUT.PUT_LINE(
                'MACHINE|'||v_data(i).machines(j).machine_no||'|'||
                v_data(i).machines(j).machine_desc||'|'||
                NVL(v_data(i).machines(j).cashier_id,'-')||'|'||
                NVL(v_data(i).machines(j).slip_no,'-')||'|'||
                TO_CHAR(NVL(v_data(i).machines(j).grand_amt,0),'FM999999999990.00')||'|'||
                NVL(TO_CHAR(v_data(i).machines(j).create_date,'DD/MM/YYYY HH24:MI:SS'),'-')||'|'||
                TO_CHAR(NVL(v_data(i).machines(j).item_cnt,0))||'|'||
                NVL(v_data(i).machines(j).member_id,'-')
            );
            BEGIN
                EXECUTE IMMEDIATE
                    'SELECT d.BARCODE,SUBSTR(NVL(TRIM(p.PRODUCT_DESC),d.BARCODE),1,100),'||
                    'd.QTY,d.UNIT_PRICE,d.TOTAL_AMOUNT'||
                    ' FROM POS.POS_SALETODAY_DT_'||v_data(i).branch||' d'||
                    ' LEFT JOIN POS.POS_PRODUCT p ON p.BARCODE=d.BARCODE'||
                    ' WHERE d.SLIP_NO=:1'
                BULK COLLECT INTO v_bc,v_nm,v_qty,v_up,v_tot
                USING v_data(i).machines(j).slip_no;
                FOR k IN 1..v_bc.COUNT LOOP
                    DBMS_OUTPUT.PUT_LINE(
                        'ITEM_ROW|'||NVL(TRIM(v_bc(k)),'-')||'|'||NVL(TRIM(v_nm(k)),'-')||'|'||
                        TO_CHAR(NVL(v_qty(k),0),'FM999999990')||'|'||
                        TO_CHAR(NVL(v_up(k),0),'FM999999990.00')||'|'||
                        TO_CHAR(NVL(v_tot(k),0),'FM999999990.00')
                    );
                END LOOP;
            EXCEPTION WHEN OTHERS THEN NULL;
            END;
        END LOOP;
    END LOOP;

    BEGIN
        FOR i IN 1..v_data.COUNT LOOP
            IF (v_branch_filter IS NULL OR v_branch_filter = ''
                OR NVL(v_data(i).sale_office, v_data(i).branch) = v_branch_filter)
               AND ({$plsql_branch_access}) THEN
                DECLARE v_mcnt2 NUMBER := 0; BEGIN
                    EXECUTE IMMEDIATE
                        'SELECT COUNT(DISTINCT MEMBER_ID) FROM POS.POS_SALETODAY_HD_'||v_data(i).branch||
                        ' WHERE MEMBER_ID IS NOT NULL AND MEMBER_ID!=''-'''||
                        ' AND MEMBER_ID!=''0000000000000'''||
                        ' AND CREATE_DATE>=:1 AND CREATE_DATE<:2'
                    INTO v_mcnt2 USING v_start, v_end;
                    v_total_member := v_total_member + v_mcnt2;
                EXCEPTION WHEN OTHERS THEN NULL;
                END;
            END IF;
        END LOOP;
    END;

    BEGIN
        FOR i IN 1..v_data.COUNT LOOP
            IF (v_branch_filter IS NULL OR v_branch_filter = ''
                OR NVL(v_data(i).sale_office, v_data(i).branch) = v_branch_filter)
               AND ({$plsql_branch_access}) THEN
                DECLARE
                    v_bcs_net t_str_tab;
                    v_qty_net t_num_tab;
                BEGIN
                    EXECUTE IMMEDIATE
                        'SELECT dt.BARCODE, SUM(dt.QTY) FROM POS.POS_SALETODAY_DT_'||v_data(i).branch||' dt'||
                        ' WHERE dt.BARCODE IS NOT NULL AND dt.QTY<>0'||
                        ' AND EXISTS(SELECT 1 FROM POS.POS_SALETODAY_HD_'||v_data(i).branch||
                        ' h WHERE h.SLIP_NO=dt.SLIP_NO AND h.CREATE_DATE>=:1 AND h.CREATE_DATE<:2)'||
                        ' GROUP BY dt.BARCODE HAVING SUM(dt.QTY)>0'
                    BULK COLLECT INTO v_bcs_net, v_qty_net USING v_start, v_end;
                    FOR k IN 1..v_bcs_net.COUNT LOOP
                        v_bc_dedup(TRIM(v_bcs_net(k))) := '1';
                    END LOOP;
                EXCEPTION WHEN OTHERS THEN NULL;
                END;
            END IF;
        END LOOP;
        v_total_line := v_bc_dedup.COUNT;
    EXCEPTION WHEN OTHERS THEN v_total_line := 0;
    END;

    DBMS_OUTPUT.PUT_LINE('TOTAL|'||TO_CHAR(v_total_slip)||'|'||TO_CHAR(v_total_item)||'|'||
        TO_CHAR(v_total_line)||'|'||
        TO_CHAR(v_total_amt,'FM999999999990.00')||'|'||TO_CHAR(v_total_member));
EXCEPTION WHEN OTHERS THEN
    DBMS_OUTPUT.PUT_LINE('FATAL|'||SQLERRM);
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
    // ตรวจ ORA-/SP2- ที่ไม่มี pipe (error จริง)
    foreach (explode("\n", $output) as $_ln) {
        $_ln = trim($_ln);
        if ($_ln === '' || strpos($_ln, '|') !== false) continue;
        if (preg_match('/^(ORA-|SP2-)/', $_ln)) {
            echo json_encode(['error' => $_ln, 'output' => $output]);
            exit;
        }
    }

    // === Parse pipe-delimited ===
    // BRANCH|code|office_name|slip|item|amount|lastsale|diff_sec|is_new  (9 fields)
    // MACHINE|machine_no|machine_desc|cashier|slip_no|amount|datetime|item_cnt|member_id  (9 fields)
    // ITEM_ROW|barcode|name|qty|uprice|total
    // TOTAL|slip|item|line|amount|members
    $lines   = explode("\n", $output);
    $data    = [];
    $current = null;
    $total_slip = $total_item = $total_line = $total_amount = $total_members = 0;

    foreach ($lines as $raw) {
        $line = trim($raw);
        if ($line === '') continue;

        if (strpos($line, 'BRANCH|') === 0) {
            $p = explode('|', $line, 10);
            if (count($p) < 9) continue;
            $branch      = trim($p[1]);
            $office_name = trim($p[2]);
            $slip        = (int)$p[3];
            $item        = (int)$p[4];
            $amount      = (float)$p[5];
            $lastsale    = trim($p[6]);
            $diff_sec    = (int)$p[7];
            $is_new      = trim($p[8]) === 'Y';
            $member_cnt  = isset($p[9]) ? (int)trim($p[9]) : 0;
            $diff_text   = $diff_sec >= 0
                ? sprintf('+%02d:%02d:%02d', intdiv($diff_sec,3600), intdiv($diff_sec%3600,60), $diff_sec%60)
                : '';
            $ls_ts = 0;
            if ($lastsale !== '-' && preg_match('/(\d{2})\/(\d{2})\/(\d{4}) (\d{2}):(\d{2}):(\d{2})/', $lastsale, $tm))
                $ls_ts = mktime((int)$tm[4],(int)$tm[5],(int)$tm[6],(int)$tm[2],(int)$tm[1],(int)$tm[3]);
            $data[$branch] = [
                'branch'      => $branch,
                'office_name' => $office_name,
                'slip'        => $slip,
                'item'        => $item,
                'amount'      => $amount,
                'lastsale'    => $lastsale,
                'lastsale_ts' => $ls_ts,
                'diff'        => $diff_text,
                'is_new'      => $is_new,
                'member_cnt'  => $member_cnt,
                'machines'    => []
            ];
            $current = $branch;
            $total_slip += $slip; $total_item += $item; $total_amount += $amount;

        } elseif (strpos($line, 'MACHINE|') === 0 && $current) {
            $p = explode('|', $line, 9);
            if (count($p) < 9) continue;
            $slip_no = trim($p[4]);
            // กรองตาม slip keyword (ถ้ามี)
            if ($slip_search !== '' && stripos($slip_no, $slip_search) === false) continue;
            $cashier = trim($p[3]);
            $data[$current]['machines'][] = [
                'machine'      => trim($p[1]),
                'machine_desc' => trim($p[2]),
                'cashier'      => $cashier,
                'cashier_name' => $cashier_map[$cashier] ?? '',
                'slip'         => $slip_no,
                'amount'       => (float)$p[5],
                'date'         => trim($p[6]),
                'item'         => (int)$p[7],
                'member_id'    => trim($p[8]) === '-' ? '' : trim($p[8]),
                'items'        => []
            ];

        } elseif (strpos($line, 'ITEM_ROW|') === 0 && $current) {
            $last_idx = count($data[$current]['machines']) - 1;
            if ($last_idx >= 0) {
                $p = explode('|', $line, 6);
                if (count($p) >= 6)
                    $data[$current]['machines'][$last_idx]['items'][] = [
                        'barcode'    => trim($p[1]),
                        'name'       => trim($p[2]),
                        'qty'        => (int)$p[3],
                        'unit_price' => (float)$p[4],
                        'total_amt'  => (float)$p[5]
                    ];
            }

        } elseif (strpos($line, 'TOTAL|') === 0) {
            $p = explode('|', $line, 6);
            if (count($p) >= 6) {
                $total_slip    = (int)$p[1];
                $total_item    = (int)$p[2];
                $total_line    = (int)$p[3];
                $total_amount  = (float)$p[4];
                $total_members = (int)$p[5];
            }
        }
    }

    // กรองตาม branch_filter ที่เลือก
    if ($branch_filter !== '') {
        $data = array_filter($data, fn($b) => $b['branch'] === $branch_filter);
    }
    // กรองสาขาตามสิทธิ์
    if (function_exists('pos_get_branches') && pos_get_branches() !== null) {
        $data = array_filter($data, fn($b) => pos_can_see_branch($b['branch']));
    }
    // คำนวณยอดรวมใหม่จาก $data จริงเสมอ
    $total_slip = $total_item = 0; $total_amount = $total_members = 0;
    foreach ($data as $b) {
        $total_slip    += $b['slip'];
        $total_item    += $b['item'];
        $total_amount  += $b['amount'];
        $total_members += $b['member_cnt'];
    }
    // $total_line คงไว้จาก SQL (unique barcode ทั้งสาขาที่เลือก — คำนวณ global ไม่ได้รวมต่อ branch)

    usort($data, fn($a,$b) => $b['amount'] <=> $a['amount'] ?: $b['lastsale_ts'] <=> $a['lastsale_ts']);
    foreach ($data as &$br) {
        if (!empty($br['machines']))
            usort($br['machines'], function($a,$b) {
                $af = DateTime::createFromFormat('d/m/Y H:i:s', $a['date'], new DateTimeZone('Asia/Bangkok'));
                $bf = DateTime::createFromFormat('d/m/Y H:i:s', $b['date'], new DateTimeZone('Asia/Bangkok'));
                return ($bf ? $bf->getTimestamp() : 0) <=> ($af ? $af->getTimestamp() : 0);
            });
    }
    unset($br);

    $online = $no_data = 0;
    foreach ($data as $b) { if ($b['slip'] > 0) $online++; else $no_data++; }
    $no_data_global = ($online === 0);   // เหมือน POS_HOME: ไม่มีสาขาไหนมียอดขายเลย

    $chart_labels = []; $chart_data = [];
    foreach ($data as $b) {
        $label = ($b['office_name'] && $b['office_name'] !== $b['branch'])
                 ? $b['office_name'] . ' (' . $b['branch'] . ')'
                 : $b['branch'];
        $chart_labels[] = $label;
        $chart_data[] = round($b['amount'], 2);
    }

    echo json_encode([
        'refresh_time'    => date('d/m/Y H:i:s'),
        'no_data_global'  => $no_data_global,
        'message'         => 'ไม่มีข้อมูลในช่วงวันที่ที่ระบุ',
        'start_date'      => $start_date,
        'end_date'        => $end_date,
        'slip_search'     => $slip_search,
        'online_machines' => $online,
        'no_data_count'   => $no_data,
        'all_branches'    => $online + $no_data,
        'total_slip'      => $total_slip,
        'total_item'      => $total_item,
        'total_line'      => $total_line,
        'total_amount'    => $total_amount,
        'total_members'   => $total_members,
        'chart_labels'    => $chart_labels,
        'chart_data'      => $chart_data,
        'branches'        => array_values($data)
    ]);
    exit;
}

// โหลดรายชื่อสาขาแยกตาม mode (เหมือน POS_ITEMS)
$office_list_today   = [];
$office_list_history = [];
try {
    $sp_ol = rtrim($instant_client_path, '/') . '/sqlplus';
    $up_ol = escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}");

    // --- TODAY: PL/SQL loop จาก POS_SALETODAY_HD_* ---
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
    foreach (preg_split('/\r?\n/', $out_ol) as $ln_ol) {
        $ln_ol = trim($ln_ol);
        if ($ln_ol === '' || preg_match('/^(ORA-|SP2-)/', $ln_ol)) continue;
        $p_ol = explode('|', $ln_ol, 2);
        if (count($p_ol) === 2 && $p_ol[0] !== '') {
            $code_ol = trim($p_ol[0]);
            if (!function_exists('pos_can_see_branch') || pos_can_see_branch($code_ol))
                $office_list_today[$code_ol] = trim($p_ol[1]);
        }
    }

    // --- HISTORY: จาก POS_SALE_OFFICE โดยตรง (เหมือน POS_ITEMS) ---
    $tmp_hist = tempnam(sys_get_temp_dir(), 'POSH_') . '.sql';
    file_put_contents($tmp_hist,
        "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON LINESIZE 300\n" .
        "ALTER SESSION SET NLS_LANGUAGE = American;\n" .
        "SELECT TRIM(SALE_OFFICE)||'|'||NVL(TRIM(OFFICE_NAME),TRIM(SALE_OFFICE))\n" .
        "FROM POS.POS_SALE_OFFICE\n" .
        "WHERE SALE_OFFICE IS NOT NULL AND TRIM(SALE_OFFICE) IS NOT NULL\n" .
        "ORDER BY SALE_OFFICE;\nEXIT;\n"
    );
    $out_hist = (string)shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sp_ol} -s {$up_ol} @{$tmp_hist} 2>&1");
    @unlink($tmp_hist);
    foreach (preg_split('/\r?\n/', $out_hist) as $ln_ol) {
        $ln_ol = trim($ln_ol);
        if ($ln_ol === '' || preg_match('/^(ORA-|SP2-)/', $ln_ol)) continue;
        $p_ol = explode('|', $ln_ol, 2);
        if (count($p_ol) === 2 && $p_ol[0] !== '') {
            $code_ol = trim($p_ol[0]);
            if (!function_exists('pos_can_see_branch') || pos_can_see_branch($code_ol))
                $office_list_history[$code_ol] = trim($p_ol[1]);
        }
    }
} catch (Throwable $e) { $office_list_today = []; $office_list_history = []; }
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>รายการขาย</title>
<link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: "Consolas", "Tahoma", sans-serif; background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); color: #eee; padding: 20px; min-height: 100vh; }
h1 { color: #00ffff; margin-bottom: 20px; text-align: center; text-shadow: 0 0 20px rgba(0,255,255,0.5); font-size: 28px; }
.container { max-width: 1400px; margin: 0 auto; }
.filter-section { background: rgba(0,0,0,0.4); padding: 20px; border-radius: 10px; margin-bottom: 30px; border: 2px solid #0ff; box-shadow: 0 0 30px rgba(0,255,255,0.2); }
.form-group { margin: 10px 5px; display: inline-block; position: relative; }
label { color: #0ff; margin-right: 8px; font-weight: bold; font-size: 14px; }
input[type=text], select { padding: 10px 12px; border-radius: 6px; border: 2px solid #0ff; background: #0a0a0a; color: #0ff; font-family: Consolas, monospace; font-size: 14px; transition: all 0.3s; }
input[type=text]:focus, select:focus { outline: none; box-shadow: 0 0 15px rgba(0,255,255,0.5); border-color: #00ffff; }
.date-icon { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #0ff; cursor: pointer; font-size: 16px; }
button { background: linear-gradient(135deg, #00bcd4 0%, #0097a7 100%); color: #fff; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; margin: 5px; font-weight: bold; font-size: 14px; transition: all 0.3s; box-shadow: 0 4px 15px rgba(0,188,212,0.3); }
button:hover { background: linear-gradient(135deg, #00e5ff 0%, #00bcd4 100%); transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,188,212,0.5); }
.error { color: #ff6b6b; background: rgba(255,0,0,0.1); padding: 15px; border-radius: 8px; margin: 15px 0; border: 2px solid #ff6b6b; }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin: 30px 0; }
.stat-card { background: linear-gradient(135deg, rgba(0,188,212,0.15) 0%, rgba(0,150,167,0.15) 100%); border: 2px solid #0ff; border-radius: 12px; padding: 25px; text-align: center; transition: all 0.3s; position: relative; overflow: hidden; }
.stat-card::before { content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: linear-gradient(45deg, transparent, rgba(0,255,255,0.1), transparent); transform: rotate(45deg); transition: all 0.5s; }
.stat-card:hover { transform: translateY(-8px) scale(1.02); box-shadow: 0 15px 40px rgba(0,255,255,0.4); border-color: #00ffff; }
.stat-card:hover::before { left: 100%; }
canvas { background: rgba(0,0,0,0.4); border-radius: 12px; margin: 30px 0; border: 2px solid #0ff; box-shadow: 0 0 30px rgba(0,255,255,0.2); padding: 10px; }
table { width: 100%; border-collapse: collapse; margin-top: 30px; background: rgba(0,0,0,0.4); border-radius: 12px; overflow: hidden; box-shadow: 0 0 30px rgba(0,255,255,0.2); }
th, td { border: 1px solid #333; padding: 15px 20px; text-align: left; }
th { background: linear-gradient(135deg, #004d4d 0%, #003333 100%); color: #0ff; font-weight: bold; text-transform: uppercase; font-size: 13px; letter-spacing: 0.5px; }
tbody tr { transition: all 0.3s; }
tbody tr:nth-child(odd) { background: rgba(255,255,255,0.03); }
tbody tr:hover { background: rgba(0,255,255,0.15); transform: scale(1.01); cursor: pointer; }
.rank-badge { display: inline-block; width: 32px; height: 32px; line-height: 32px; border-radius: 50%; font-weight: bold; font-size: 15px; text-align: center; margin-right: 8px; box-shadow: 0 0 18px rgba(255,255,255,0.6); animation: pulse 2s infinite; }
.rank-1 .rank-badge { background: linear-gradient(135deg, #ffd700, #ffb800); color: #8B4513; }
.rank-2 .rank-badge { background: linear-gradient(135deg, #c0c0c0, #a8a8a8); color: #4a4a4a; }
.rank-3 .rank-badge { background: linear-gradient(135deg, #cd7f32, #b87333); color: #fff; }
@keyframes pulse { 0%,100% { box-shadow: 0 0 18px rgba(255,255,255,0.6); } 50% { box-shadow: 0 0 28px rgba(255,255,255,0.9); } }
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
@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
@media (max-width: 768px) { .stats-grid { grid-template-columns: 1fr; } table { font-size: 12px; } th, td { padding: 10px; } }
.machine-delay-10 { background:#444400; color:#ffff66; font-weight:bold; }
.machine-delay-30 { background:#663300; color:#ffcc66; font-weight:bold; }
.machine-delay-60 { background:#550000; color:#ff6666; font-weight:bold; }
.item-row td { background:#222; color:#0ff; }
.member-badge { background:#ff6b35; color:#fff; padding:2px 8px; border-radius:4px; font-size:12px; margin-left:8px; font-weight:bold; }
tr.branch { background-color:#222; }
tr.branch-new { background-color:#222; color:#0f0; font-weight:bold; }
tr.branch-delay-10 { background:#1a1a00; color:#ffff66; }
tr.branch-delay-30 { background:#1a0d00; color:#ffcc66; }
tr.branch-delay-60 { background:#1a0000; color:#ff6666; }
tr.machine { background-color:#111; }
tr.machine-zero { background-color:#550000; color:#ff6666; font-weight:bold; }
tr.no-data { background-color:#330000; color:#ff8888; font-weight:bold; text-align:center; }
</style>
</head>
<body>
<?php pos_expiry_banner(); ?>
<?php $MENU_ACTIVE = 'detail'; require_once 'POS_MENU.php'; ?>
<?php $pos_topright_show_online = true; require_once __DIR__ . '/POS_TOPRIGHT.php'; ?>
<h1><i class="fas fa-table"></i> รายการขาย</h1>
<?php pos_nav_buttons($pos_priority, $MENU_ACTIVE); ?>


<!-- ── Mode Tabs ── -->
<div style="text-align:center;margin:16px 0;">
    <button type="button" id="tab-detail" onclick="setMode('detail')"
        style="padding:10px 28px;border-radius:8px 0 0 8px;border:2px solid #0ff;
               font-size:15px;font-weight:bold;cursor:pointer;transition:all 0.2s;
               background:<?=$search_mode==='detail'?'#0ff':'rgba(0,255,255,0.08)'?>;
               color:<?=$search_mode==='detail'?'#000':'#0ff'?>;">
        <i class="fas fa-store" style="margin-right:8px;"></i>รายการขายวันนี้
    </button><button type="button" id="tab-history" onclick="setMode('history')"
        style="padding:10px 28px;border-radius:0 8px 8px 0;border:2px solid #ff9800;
               border-left:none;font-size:15px;font-weight:bold;cursor:pointer;transition:all 0.2s;
               background:<?=$search_mode==='history'?'#ff9800':'rgba(255,152,0,0.08)'?>;
               color:<?=$search_mode==='history'?'#000':'#ff9800'?>;">
        <i class="fas fa-history" style="margin-right:8px;"></i>รายการขายย้อนหลัง
    </button>
</div>
<input type="hidden" id="mode-val" value="<?=htmlspecialchars($search_mode)?>">
<div id="section-detail" style="display:<?=$search_mode==='detail'?'block':'none'?>">
<div class="filter-section">
<form method="GET" id="filter-form" style="text-align:center;">
    <input type="hidden" name="mode" value="detail">
    <div class="form-group">
        <label>ค้นหาสลิป:</label>
        <input type="text" name="slip" id="slip-search" value="<?=htmlspecialchars($slip_search??'')?>" placeholder="เลขสลิป..." autocomplete="off" style="width:180px;">
    </div>
    <div class="form-group">
        <label for="detail-branch-select">สาขา:</label>
        <select name="branch" id="detail-branch-select" style="min-width:180px;cursor:pointer;">
            <option value="">— ทุกสาขา —</option>
            <?php foreach ($office_list_today as $code => $name): ?>
            <option value="<?=htmlspecialchars($code)?>" <?=$branch_filter===$code?'selected':''?>>
                <?=htmlspecialchars($name)?> (<?=htmlspecialchars($code)?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
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
    <button type="submit"><i class="fas fa-search"></i> ค้นหา</button>
    <button type="button" id="refresh-btn" style="display:none;"><i class="fas fa-sync"></i> รีเฟรช</button>

</form>
</div>
<?php if (!empty($errors)): ?>
<div class="error"><h2>วันที่ไม่ถูกต้อง</h2><?=implode('<br>', $errors)?></div>
<?php endif; ?>

<!-- การ์ดสรุปยอด -->
<div style="display:flex; justify-content:center; gap:20px; flex-wrap:wrap; margin:20px 0;">
    <div style="background:linear-gradient(135deg,rgba(0,188,212,0.2),rgba(0,150,167,0.2)); border:2px solid #0ff; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(0,255,255,0.2); min-width:200px;">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-chart-line" style="margin-right:6px;"></i>ยอดขาย (ตามเงื่อนไข)</div>
        <div style="font-size:36px; font-weight:bold; color:#0ff; text-shadow:0 0 16px rgba(0,255,255,0.2);" id="total-amount">0.00</div>
        <div style="color:#aaa; font-size:12px; margin-top:4px;">บาท</div>
    </div>
    <div style="background:linear-gradient(135deg,rgba(0,200,83,0.2),rgba(0,150,60,0.2)); border:2px solid #00c853; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(0,200,83,0.2); min-width:200px;">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-store" style="margin-right:6px;"></i>สาขาที่มีข้อมูล</div>
        <div style="font-size:42px; font-weight:bold; color:#00e676; text-shadow:0 0 16px rgba(0,200,83,0.6);" id="detail-online-count">0</div>
        <div style="color:#aaa; font-size:12px; margin-top:4px;">
            ไม่มีข้อมูล: <span id="detail-offline-count" style="color:#ff6b6b;">0</span> สาขา
        </div>
    </div>
    <div style="background:linear-gradient(135deg,rgba(255,107,53,0.2),rgba(200,80,30,0.2)); border:2px solid #ff6b35; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(255,107,53,0.2); min-width:200px;">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-user-check" style="margin-right:6px;"></i>สมาชิก (ตามเงื่อนไข)</div>
        <div style="font-size:42px; font-weight:bold; color:#ff6b35; text-shadow:0 0 16px rgba(255,107,53,0.2);" id="total-members">0</div>
        <div style="color:#aaa; font-size:12px; margin-top:4px;">คน</div>
    </div>
    <div style="background:linear-gradient(135deg,rgba(33,150,243,0.2),rgba(21,101,192,0.2)); border:2px solid #42a5f5; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(33,150,243,0.2); min-width:200px;">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-receipt" style="margin-right:6px;"></i>สลิปรวม</div>
        <div style="font-size:42px; font-weight:bold; color:#42a5f5; text-shadow:0 0 16px rgba(33,150,243,0.2);" id="total-slip">0</div>
        <div style="color:#aaa; font-size:12px; margin-top:4px;">สลิป</div>
    </div>
    <div style="background:linear-gradient(135deg,rgba(76,175,80,0.2),rgba(56,142,60,0.2)); border:2px solid #4caf50; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(76,175,80,0.2); min-width:200px;">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-boxes" style="margin-right:6px;"></i>จำนวนสินค้ารวม (ชิ้น)</div>
        <div style="font-size:42px; font-weight:bold; color:#4caf50; text-shadow:0 0 16px rgba(76,175,80,0.2);" id="total-item">0</div>
        <div style="color:#aaa; font-size:12px; margin-top:4px;">ชิ้น</div>
    </div>
    <div style="background:linear-gradient(135deg,rgba(255,193,7,0.2),rgba(255,160,0,0.2)); border:2px solid #ffc107; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(255,193,7,0.2); min-width:200px;">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-list-ul" style="margin-right:6px;"></i>รายการสินค้ารวม</div>
        <div style="font-size:42px; font-weight:bold; color:#ffc107; text-shadow:0 0 16px rgba(255,193,7,0.6);" id="total-line">0</div>
        <div style="color:#aaa; font-size:12px; margin-top:4px;">รายการ</div>
    </div>
</div>

<canvas id="salesChart" height="100" style="display:none;"></canvas>

<table id="branch-table">
<thead>
    <tr>
        <th style="width:40px;text-align:center;">#</th>
        <th style="width:300px;">สาขา / รายการ</th>
        <th>สลิป</th>
        <th>จำนวนสินค้า</th>
        <th>ยอดรวม (บาท)</th>
        <th>ซื้อล่าสุด</th>
        <th>Diff</th>
    </tr>
</thead>
<tbody></tbody>
</table>
</div><!-- /section-detail -->
<div id="section-history" style="display:<?=$search_mode==='history'?'block':'none'?>">
<!-- Filter -->
<div class="filter-section">
<form method="GET" id="hist-form" style="text-align:center;">
    <input type="hidden" name="mode" value="history">
    <div class="form-group">
        <label>ค้นหาสลิป:</label>
        <input type="text" name="slip" id="h-slip" value="<?=htmlspecialchars($slip_search)?>" placeholder="เลขสลิป..." autocomplete="off" style="width:180px;">
    </div>
    <div class="form-group">
        <label for="branch-select">สาขา:</label>
        <select name="branch" id="branch-select" style="min-width:180px;cursor:pointer;">
            <option value="">— ทุกสาขา —</option>
            <?php foreach ($office_list_history as $code => $name): ?>
            <option value="<?=htmlspecialchars($code)?>" <?=$branch_filter===$code?'selected':''?>>
                <?=htmlspecialchars($name)?> (<?=htmlspecialchars($code)?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="start_date">เริ่มต้น:</label>
        <input type="text" name="start" id="h-start" value="<?=htmlspecialchars($start_date)?>" placeholder="วว/ดด/ปปปป" autocomplete="off" required readonly style="cursor:pointer;">
        <i class="fas fa-calendar-alt date-icon" id="h-start-icon"></i>
    </div>
    <div class="form-group">
        <label for="end_date">สิ้นสุด:</label>
        <input type="text" name="end" id="h-end" value="<?=htmlspecialchars($end_date)?>" placeholder="วว/ดด/ปปปป" autocomplete="off" required readonly style="cursor:pointer;">
        <i class="fas fa-calendar-alt date-icon" id="h-end-icon"></i>
    </div>
    <div class="form-group">
        <label>ช่วงเวลา:</label>
        <input type="text" name="time_start" id="time-start"
               value="<?=htmlspecialchars($time_start)?>"
               placeholder="00:00" maxlength="5" autocomplete="off"
               style="width:75px;text-align:center;"
               oninput="fmtTime(this)">
        <span style="color:#0ff;margin:0 4px;">—</span>
        <input type="text" name="time_end" id="time-end"
               value="<?=htmlspecialchars($time_end)?>"
               placeholder="23:59" maxlength="5" autocomplete="off"
               style="width:75px;text-align:center;"
               oninput="fmtTime(this)">
    </div>
    <div class="form-group">
        <label>แสดง:</label>
        <input type="number" name="limit_n" id="h-limit" min="1" max="10000"
               value="<?= (int)$limit_n ?>"
               style="width:80px;padding:10px 8px;border-radius:6px;border:2px solid #0ff;background:#0a0a0a;color:#0ff;font-size:14px;text-align:center;">
        <span style="color:#aaa;font-size:12px;margin-left:4px;">รายการ</span>
    </div>
    <button type="submit"><i class="fas fa-search"></i> ค้นหา</button>
    <button type="button" id="h-refresh-btn" style="display:none;"><i class="fas fa-sync"></i> รีเฟรช</button>
    <?php if ($branch_filter !== '' || $time_start !== '' || $time_end !== ''): ?>
    <button type="button" onclick="clearFilters()" style="background:rgba(255,107,53,0.2);color:#ff6b35;border:1px solid #ff6b35;padding:8px 16px;border-radius:8px;cursor:pointer;font-size:13px;">
        <i class="fas fa-times"></i> ล้างตัวกรอง
    </button>
    <?php endif; ?>
</form>
</div>

<?php if (!empty($errors)): ?>
<div class="error">
    <h2>วันที่ไม่ถูกต้อง</h2>
    <?=implode('<br>', array_map('htmlspecialchars', $errors))?>
</div>
<?php endif; ?>

<!-- การ์ดสรุปยอด (ย้อนหลัง) -->
<div style="display:flex; justify-content:center; gap:20px; flex-wrap:wrap; margin:20px 0;">
    <div style="background:linear-gradient(135deg,rgba(0,188,212,0.2),rgba(0,150,167,0.2)); border:2px solid #0ff; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(0,255,255,0.2); min-width:200px;">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-chart-line" style="margin-right:6px;"></i>ยอดขาย (ตามเงื่อนไข)</div>
        <div style="font-size:36px; font-weight:bold; color:#00ffff; text-shadow:0 0 16px rgba(0,255,255,0.6);" id="h-total-amount">0.00</div>
        <div style="color:#aaa; font-size:12px; margin-top:4px;">บาท</div>
        <div style="color:#ffcc00; font-size:10px; margin-top:3px; opacity:0.8;" id="h-card-filter-label"></div>
    </div>
    <div style="background:linear-gradient(135deg,rgba(0,200,83,0.2),rgba(0,150,60,0.2)); border:2px solid #00c853; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(0,200,83,0.2); min-width:200px;">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-store" style="margin-right:6px;"></i>สาขา</div>
        <div style="font-size:42px; font-weight:bold; color:#00e676; text-shadow:0 0 16px rgba(0,200,83,0.6);" id="h-online-card">0</div>
        <div style="color:#aaa; font-size:12px; margin-top:4px;">สาขา / ไม่มีข้อมูล: <span id="h-offline-card" style="color:#ff6b6b;">0</span></div>
    </div>
    <div style="background:linear-gradient(135deg,rgba(255,107,53,0.2),rgba(200,80,30,0.2)); border:2px solid #ff6b35; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(255,107,53,0.2); min-width:200px;">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-user-check" style="margin-right:6px;"></i>สมาชิก (ช่วงวันที่)</div>
        <div style="font-size:42px; font-weight:bold; color:#ff6b35; text-shadow:0 0 16px rgba(255,107,53,0.5);" id="h-total-members">0</div>
        <div style="color:#aaa; font-size:12px; margin-top:4px;">คน</div>
    </div>
    <div style="background:linear-gradient(135deg,rgba(33,150,243,0.2),rgba(21,101,192,0.2)); border:2px solid #42a5f5; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(33,150,243,0.2); min-width:200px;">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-receipt" style="margin-right:6px;"></i>สลิปที่แสดง</div>
        <div style="font-size:42px; font-weight:bold; color:#42a5f5; text-shadow:0 0 16px rgba(33,150,243,0.6);" id="h-total-slip">0</div>
        <div style="color:#aaa; font-size:12px; margin-top:4px;">สลิป</div>
    </div>
    <div style="background:linear-gradient(135deg,rgba(76,175,80,0.2),rgba(56,142,60,0.2)); border:2px solid #4caf50; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(76,175,80,0.2); min-width:200px;">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-boxes" style="margin-right:6px;"></i>สินค้าที่แสดง (ชิ้น)</div>
        <div style="font-size:42px; font-weight:bold; color:#4caf50; text-shadow:0 0 16px rgba(76,175,80,0.6);" id="h-total-item">0</div>
        <div style="color:#aaa; font-size:12px; margin-top:4px;">ชิ้น</div>
    </div>
    <div style="background:linear-gradient(135deg,rgba(255,193,7,0.2),rgba(255,160,0,0.2)); border:2px solid #ffc107; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(255,193,7,0.2); min-width:200px;">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-list-ul" style="margin-right:6px;"></i>รายการสินค้ารวม</div>
        <div style="font-size:42px; font-weight:bold; color:#ffc107; text-shadow:0 0 16px rgba(255,193,7,0.6);" id="h-total-line">0</div>
        <div style="color:#aaa; font-size:12px; margin-top:4px;">รายการ</div>
    </div>
</div>

<canvas id="h-salesChart" height="100" style="display:none;"></canvas>

<table id="h-branch-table">
<thead>
    <tr>
        <th style="width:40px;text-align:center;">#</th>
        <th style="width:280px;">สาขา / รายการ</th>
        <th>สลิป</th>
        <th>จำนวนสินค้า</th>
        <th>ยอดรวม (บาท)</th>
        <th>ซื้อล่าสุด</th>
        <th>Diff</th>
    </tr>
</thead>
<tbody></tbody>
</table>

</div><!-- /section-history -->
<script>

// ────────────────────────────────────────────
// MODE SWITCHING
// ────────────────────────────────────────────
function setMode(mode) {
    document.getElementById('mode-val').value = mode;
    const isHist = mode === 'history';
    document.getElementById('section-detail').style.display  = isHist ? 'none'  : 'block';
    document.getElementById('section-history').style.display = isHist ? 'block' : 'none';
    document.getElementById('tab-detail').style.background   = isHist ? 'rgba(0,255,255,0.08)' : '#0ff';
    document.getElementById('tab-detail').style.color        = isHist ? '#0ff' : '#000';
    document.getElementById('tab-history').style.background  = isHist ? '#ff9800' : 'rgba(255,152,0,0.08)';
    document.getElementById('tab-history').style.color       = isHist ? '#000' : '#ff9800';
    // ปรับ datepicker
    const now = new Date();
    const todayStr = ('0'+now.getDate()).slice(-2)+'/'+ ('0'+(now.getMonth()+1)).slice(-2)+'/'+now.getFullYear();
    const yest = new Date(now); yest.setDate(yest.getDate()-1);
    const yesterdayStr = ('0'+yest.getDate()).slice(-2)+'/'+ ('0'+(yest.getMonth()+1)).slice(-2)+'/'+yest.getFullYear();
    if (isHist) {
        $('#start_date,#end_date').datepicker('option', {minDate:null, maxDate:'today'});
        // ตั้ง h-start/h-end เป็นเมื่อวาน (ถ้ายังไม่เคยกรอก)
        if (!$('#h-start').val() || $('#h-start').val() === todayStr) {
            $('#h-start').datepicker('setDate', yesterdayStr);
        }
        if (!$('#h-end').val() || $('#h-end').val() === todayStr) {
            $('#h-end').datepicker('setDate', yesterdayStr);
        }
    } else {
        $('#start_date,#end_date').datepicker('option', {minDate:'today', maxDate:'today'});
        $('#start_date,#end_date').val(todayStr);
        updateDashboard();
    }
}

// ─── DETAIL MODE JS (รายการขายวันนี้) ───────────────────
function getTimeDiffClass(m){if(m>60)return'machine-delay-60';if(m>30)return'machine-delay-30';if(m>10)return'machine-delay-10';return'';}
function parseDateDMY(d){
    const p=d.match(/(\d+)\/(\d+)\/(\d+) (\d+):(\d+):(\d+)/);
    if(p)return new Date(p[3],p[2]-1,p[1],p[4],p[5],p[6]);
    return null;
}
let chart=null;
function updateDashboard(){
    const p=new URLSearchParams(window.location.search);
    p.set('ajax','1');
    p.set('mode','detail');
    // อ่าน branch จาก URL ก่อน (กรณี page reload หลัง submit form)
    // fallback ไปที่ select element (กรณีกด refresh)
    const branchEl=document.getElementById('detail-branch-select');
    const urlBranch=new URLSearchParams(window.location.search).get('branch')||'';
    const selBranch=branchEl?branchEl.value:'';
    p.set('branch', selBranch||urlBranch);
    const startEl=document.getElementById('start_date');
    const endEl=document.getElementById('end_date');
    if(startEl) p.set('start', startEl.value);
    if(endEl)   p.set('end',   endEl.value);
    const slipEl=document.getElementById('slip-search');
    if(slipEl)  p.set('slip',  slipEl.value);
    fetch('?'+p.toString())
    .then(r=>{ if(!r.ok) throw new Error('Network error: '+r.status); return r.json(); })
    .then(d=>{
        if(d.error){
            document.querySelector('#branch-table tbody').innerHTML=`<tr><td colspan="7" class="error"><h2>Oracle Error</h2>${d.error}</td></tr>`;
            const _rt1=document.getElementById('refresh-time'); if(_rt1) _rt1.innerText='FAILED';
            return;
        }

        const elRT=document.getElementById('refresh-time'); if(elRT) elRT.innerText=d.refresh_time;
        const elDR=document.getElementById('date-range'); if(elDR) elDR.innerText=d.start_date+' - '+d.end_date;

        if(d.no_data_global || !d.branches || d.branches.length===0){
            document.getElementById('detail-online-count').innerText = d.online_machines || 0;
            document.getElementById('detail-offline-count').innerText = d.no_data_count || 0;
            const _om=document.getElementById('online-machines'); if(_om) _om.innerText = d.online_machines || 0;
            const _of=document.getElementById('offline-machines'); if(_of) _of.innerText = d.no_data_count || 0;
            document.getElementById('total-amount').innerText='0.00';
            document.getElementById('total-members').innerText='0';
            if(document.getElementById('total-slip'))  document.getElementById('total-slip').innerText='0';
            if(document.getElementById('total-item'))  document.getElementById('total-item').innerText='0';
            if(document.getElementById('total-line'))  document.getElementById('total-line').innerText='0';
            document.querySelector('#branch-table tbody').innerHTML=`<tr><td colspan="7" style="text-align:center;padding:80px 20px;background:rgba(139,0,0,0.2);border:2px dashed #ff6b6b;color:#ff6b6b;font-size:32px;font-weight:bold;"><i class="fas fa-ban" style="margin-right:20px;font-size:40px;"></i>${d.message||'ไม่มีข้อมูลรายการขายวันนี้'}</td></tr>`;
            document.getElementById('salesChart').style.display='none';
            if(chart){chart.data.labels=[];chart.data.datasets[0].data=[];chart.update();}
            return;
        }

        // คำนวณยอดรวมใหม่จาก branches จริง (ไม่ใช่ TOTAL| จาก SQL ที่อาจรวมทุกสาขา)
        const totalSlip   = d.branches.reduce((s,b)=>s+b.slip, 0);
        const totalItem   = d.branches.reduce((s,b)=>s+b.item, 0);
        const totalAmount = d.branches.reduce((s,b)=>s+Number(b.amount), 0);

        document.getElementById('detail-online-count').innerText = d.online_machines || 0;
        document.getElementById('detail-offline-count').innerText = d.no_data_count || 0;
        const _om2=document.getElementById('online-machines'); if(_om2) _om2.innerText = d.online_machines || 0;
        const _of2=document.getElementById('offline-machines'); if(_of2) _of2.innerText = d.no_data_count || 0;
        if(document.getElementById('all-branches')) document.getElementById('all-branches').innerText=Number(d.all_branches||d.branches.length).toLocaleString();
        document.getElementById('total-amount').innerText=totalAmount.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
        document.getElementById('total-members').innerText=Number(d.total_members||0).toLocaleString();
        if(document.getElementById('total-slip'))  document.getElementById('total-slip').innerText=totalSlip.toLocaleString();
        if(document.getElementById('total-item'))  document.getElementById('total-item').innerText=totalItem.toLocaleString();
        if(document.getElementById('total-line'))  document.getElementById('total-line').innerText=Number(d.total_line||0).toLocaleString();
        document.getElementById('salesChart').style.display='';
        const bg=d.branches.map(b=>b.is_new?'rgba(0,255,0,0.7)':b.slip===0?'rgba(255,0,0,0.7)':'rgba(0,188,212,0.7)');
        const bo=bg.map(c=>c.replace('0.7','1'));
        if(!chart){
            const ctx=document.getElementById('salesChart').getContext('2d');
            chart=new Chart(ctx,{type:'bar',data:{labels:d.chart_labels,datasets:[{label:'ยอดขาย',data:d.chart_data,backgroundColor:bg,borderColor:bo,borderWidth:1}]},
            options:{responsive:true,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>Number(c.raw).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}},datalabels:{display:true,color:'#0ff',anchor:'end',align:'top',formatter:v=>Number(v).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}},scales:{x:{ticks:{color:'#0ff'}},y:{ticks:{color:'#0ff',callback:v=>Number(v).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}}}},plugins:[ChartDataLabels]});
        }else{
            chart.data.labels=d.chart_labels; chart.data.datasets[0].data=d.chart_data;
            chart.data.datasets[0].backgroundColor=bg; chart.data.datasets[0].borderColor=bo;
            chart.update();
        }

        const tbody=document.querySelector('#branch-table tbody'); tbody.innerHTML='';
        let ts=0,ti=0,ta=0,rowSeqT=0;
        d.branches.forEach((b,i)=>{
            const rank=i+1;
            const rankBadge=`<span class="rank-badge">${rank}</span>`;
            const displayName = (b.office_name && b.office_name !== b.branch)
                ? `<span style="color:#0ff;">${b.office_name}</span><span style="color:#aaa;font-size:13px;"> (${b.branch})</span>`
                : `<span style="color:#0ff;">${b.branch}</span>`;
            const wrapCls = rank<=3 ? `branch-rank-wrapper rank-${rank}` : 'branch-rank-wrapper';
            const branchHTML = `<span class="${wrapCls}">${rankBadge}</span>${displayName}`;
            let branch_cls = b.is_new ? 'branch-new' : 'branch';
            let branch_diff_min = 999;
            if(b.lastsale && b.lastsale!=='-'){
                const lt=parseDateDMY(b.lastsale);
                if(lt) branch_diff_min=Math.round((new Date()-lt)/60000);
            }
            if(b.slip===0) branch_cls='no-data';
            else if(branch_diff_min<=10) branch_cls='branch-delay-10';
            else if(branch_diff_min<=30) branch_cls='branch-delay-30';
            else if(branch_diff_min>60)  branch_cls='branch-delay-60';
            let diff_text=b.diff;
            if(branch_diff_min<999) diff_text+=` (${branch_diff_min} นาที)`;
            const tr=document.createElement('tr');
            tr.className=branch_cls;
            tr.innerHTML=`
                <td style="text-align:center;color:#555;font-size:12px;"></td>
                <td class="branch-name-cell">${branchHTML}</td>
                <td align="right">${b.slip.toLocaleString()}</td>
                <td align="right">${b.item.toLocaleString()}</td>
                <td align="right">${Number(b.amount).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
                <td>${b.lastsale}</td>
                <td>${diff_text}</td>`;
            tbody.appendChild(tr);
            ts+=b.slip; ti+=b.item; ta+=Number(b.amount);
            if(b.machines && b.machines.length>0){
                b.machines.forEach(m=>{
                    rowSeqT++;
                    const mt=parseDateDMY(m.date);
                    const dm=mt?Math.round((new Date()-mt)/60000):999;
                    let cls=m.amount===0?'machine-zero':getTimeDiffClass(dm);
                    if(b.is_new && m.date===b.lastsale) cls='branch-new';
                    let ds=m.date||'-'; if(mt) ds+=` (${dm} นาที)`;
                    const md=m.member_id&&m.member_id!=='-'?`<span class="member-badge">สมาชิก ${m.member_id}</span>`:'';
                    const mLabel = (m.machine_desc && m.machine_desc !== m.machine)
                        ? m.machine_desc : m.machine;
                    const mr=document.createElement('tr');
                    mr.className=cls+' machine';
                    mr.innerHTML=`
                        <td style="text-align:center;color:#888;font-size:12px;font-weight:bold;">${rowSeqT}</td>
                        <td style="padding-left:30px;">${mLabel} - ${m.cashier}${m.cashier_name?' '+m.cashier_name:''}</td>
                        <td align="right">${m.slip||'ไม่มีข้อมูล'}</td>
                        <td align="right">${m.item===0?'ไม่มีข้อมูล':m.item}</td>
                        <td align="right">${m.amount===0?'ไม่มีข้อมูล':Number(m.amount).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
                        <td>${ds}</td>
                        <td>${md}</td>`;
                    tbody.appendChild(mr);
                    if(m.items && m.items.length>0){
                        let ir=`<tr style='background:#333;color:#0ff;font-weight:bold;'>
                            <th></th>
                            <th style="padding-left:40px;width:130px;">Barcode</th>
                            <th style="min-width:150px;">ชื่อสินค้า</th>
                            <th style="text-align:left;">จำนวน</th>
                            <th style="text-align:left;">ราคา/ชิ้น</th>
                            <th style="text-align:left;">รวม</th>
                            <th></th></tr>`;
                        m.items.forEach((it,ii)=>{
                            const dispName=(it.name&&it.name!==it.barcode&&it.name!=='-')?it.name:'';
                            ir+=`<tr class="item-row">
                                <td style="text-align:center;color:#555;font-size:11px;">${ii+1}</td>
                                <td style="padding-left:50px;font-size:12px;">${it.barcode}</td>
                                <td style="color:#aef;font-size:12px;">${dispName}</td>
                                <td align="right">${it.qty.toLocaleString()}</td>
                                <td align="right">${Number(it.unit_price).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
                                <td align="right">${Number(it.total_amt).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
                                <td></td></tr>`;
                        });
                        tbody.innerHTML+=`<tr><td colspan="7"><table style="width:100%;border-collapse:collapse;">${ir}</table></td></tr>`;
                    }
                });
            }
        });
        const s=document.createElement('tr');
        s.className='summary-row';
        s.innerHTML=`<td></td><td align="center">รวมทั้งหมด (${d.online_machines||0} สาขา / ${rowSeqT} รายการ)</td>
            <td align="right">${ts.toLocaleString()}</td>
            <td align="right">${ti.toLocaleString()}</td>
            <td align="right">${ta.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
            <td colspan="2"></td>`;
        tbody.appendChild(s);
    })
    .catch(e=>{
        console.error('Fetch Error:',e);
        document.querySelector('#branch-table tbody').innerHTML=`<tr><td colspan="7" class="error"><h2>AJAX Error</h2>ไม่สามารถเชื่อมต่อได้</td></tr>`;
        const _rt2=document.getElementById('refresh-time'); if(_rt2) _rt2.innerText='ERROR';
    });
}
<?php if(empty($errors)): ?>
updateDashboard();
setInterval(function(){ if(document.getElementById('mode-val').value!=='history') updateDashboard(); }, <?= (int)$pos_refresh_interval * 1000 ?>);
<?php endif; ?>
// โหลด stat ระบบทันทีเมื่อ page load (สินค้า + สมาชิกทั้งหมด)
function loadDetailStat() {
    fetch('?ajax=1&stat=detail')
        .then(r => r.json())
        .then(d => {
            console.log('[DetailStat]', d);
            if (d.all_product_count !== undefined) {
                const el = document.getElementById('all-product-count');
                if (el) el.innerText = Number(d.all_product_count).toLocaleString();
            }
            if (d.all_member_count !== undefined) {
                const el = document.getElementById('all-member-count');
                if (el) el.innerText = Number(d.all_member_count).toLocaleString();
            }
        })
        .catch(err => console.error('[DetailStat error]', err));
}
loadDetailStat(); // เรียกทันทีที่ page โหลด

document.getElementById('refresh-btn').addEventListener('click', updateDashboard);
$(function(){
    const isHistMode = document.getElementById('mode-val').value === 'history';
    const opts={
	dateFormat:'dd/mm/yy',
	changeMonth:true,
	changeYear:true,
	maxDate:'today',
	minDate:isHistMode?null:'today'
	};
    $("#start_date, #end_date").datepicker(opts);
    $("#start-icon").click(()=>$("#start_date").datepicker("show"));
    $("#end-icon").click(()=>$("#end_date").datepicker("show"));
    $("#start_date").change(function(){$("#end_date").datepicker("option","minDate",$(this).val());});
    $("#end_date").change(function(){$("#start_date").datepicker("option","maxDate",$(this).val());});
    const today=new Date();
    const todayStr=("0"+today.getDate()).slice(-2)+'/'+ ("0"+(today.getMonth()+1)).slice(-2)+'/'+today.getFullYear();
    ["#start_date","#end_date"].forEach(sel=>{
        $(sel).on("dblclick",function(){
            $(this).val(todayStr);
            $(this).datepicker("setDate",todayStr);
            setTimeout(()=>document.getElementById('filter-form').submit(),100);
        });
    });
    $("select").on("keydown",e=>{
        if(e.key==="Enter"){e.preventDefault();document.getElementById('filter-form').submit();}
    });
});


// ─── HISTORY MODE JS (รายการขายย้อนหลัง) ───────────────
const fmt2 = v => Number(v).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
const fmtN = v => Number(v).toLocaleString();



let histChart=null;

function updateHistoryDashboard(){
    const p=new URLSearchParams(window.location.search);
    p.set('ajax','1');
    p.set('mode','history');
    p.set('start',document.getElementById('h-start').value.trim());
    p.set('end',  document.getElementById('h-end').value.trim());
    p.set('slip', document.getElementById('h-slip').value.trim());
    // อ่าน branch จาก URL ก่อน fallback ไปที่ select (เหมือน today mode)
    const hBranchSel = document.getElementById('branch-select');
    const hUrlBranch = new URLSearchParams(window.location.search).get('branch') || '';
    const hSelBranch = hBranchSel ? hBranchSel.value : '';
    p.set('branch', hSelBranch || hUrlBranch);
    p.set('time_start', document.getElementById('time-start').value.trim());
    p.set('time_end',   document.getElementById('time-end').value.trim());
    p.set('limit_n',    document.getElementById('h-limit').value.trim() || '100');
    document.querySelector('#h-branch-table tbody').innerHTML=`<tr><td colspan="7" style="text-align:center;padding:60px 20px;color:#0ff;font-size:20px;">
        <i class="fas fa-spinner fa-spin" style="font-size:40px;margin-bottom:15px;display:block;"></i>
        กำลังโหลดข้อมูล...</td></tr>`;

    fetch('?'+p.toString())
    .then(r=>{ if(!r.ok) throw new Error('Network error: '+r.status); return r.json(); })
    .then(d=>{
        if(!d.ok){
            document.querySelector('#h-branch-table tbody').innerHTML=
                `<tr><td colspan="7" class="error"><i class="fas fa-exclamation-triangle"></i> ${d.error}</td></tr>`;
            const _rt3=document.getElementById('refresh-time'); if(_rt3) _rt3.innerText='FAILED';
            return;
        }
        const _rt4=document.getElementById('refresh-time'); if(_rt4) _rt4.innerText=d.refresh_time;
        let rangeText = d.start_date + ' – ' + d.end_date;
        if (d.time_start || d.time_end) {
            rangeText += '  ⏱ ' + (d.time_start||'00:00') + ' – ' + (d.time_end||'23:59');
        }
        if (d.branch_filter) {
            rangeText += '  📍 ' + d.branch_filter;
        }
        const _dr=document.getElementById('date-range'); if(_dr) _dr.innerText=rangeText;

        // คำนวณยอดรวมจาก branches จริง (เหมือน today mode)
        const hTotalSlip   = d.branches.reduce((s,b)=>s+b.slip,          0);
        const hTotalItem   = d.branches.reduce((s,b)=>s+b.item,          0);
        const hTotalAmount = d.branches.reduce((s,b)=>s+Number(b.amount), 0);
        const hOnlineCnt   = d.online_machines || 0;
        const hOfflineCnt  = d.no_data_count   || 0;

        // อัพเดท TOPRIGHT
        const _hom=document.getElementById('online-machines');  if(_hom) _hom.innerText=hOnlineCnt;
        const _hof=document.getElementById('offline-machines'); if(_hof) _hof.innerText=hOfflineCnt;

        if(d.no_data_global || !d.branches || d.branches.length===0){
            document.getElementById('h-online-card').innerText  = hOnlineCnt;
            document.getElementById('h-offline-card').innerText = hOfflineCnt;
            document.getElementById('h-total-slip').innerText   = '0';
            document.getElementById('h-total-item').innerText   = '0';
            document.getElementById('h-total-line').innerText   = '0';
            document.getElementById('h-total-amount').innerText = '0.00';
            document.getElementById('h-total-members').innerText= '0';
            document.querySelector('#h-branch-table tbody').innerHTML=
                `<tr><td colspan="7" style="text-align:center;padding:80px 20px;background:rgba(139,0,0,.2);border:2px dashed #ff6b6b;color:#ff6b6b;font-size:32px;font-weight:bold;"><i class="fas fa-ban" style="margin-right:20px;font-size:40px;"></i>${d.message||'ไม่มีข้อมูลในช่วงวันที่ที่ระบุ'}</td></tr>`;
            document.getElementById('h-salesChart').style.display='none';
            if(histChart){histChart.data.labels=[];histChart.data.datasets[0].data=[];histChart.update();}
            return;
        }

        document.getElementById('h-online-card').innerText  = hOnlineCnt;
        document.getElementById('h-offline-card').innerText = hOfflineCnt;
        document.getElementById('h-total-slip').innerText   = fmtN(hTotalSlip);
        document.getElementById('h-total-item').innerText   = fmtN(hTotalItem);
        document.getElementById('h-total-line').innerText   = fmtN(d.total_line||0);
        document.getElementById('h-total-amount').innerText = fmt2(hTotalAmount);
        document.getElementById('h-total-members').innerText= fmtN(d.total_members);
        // grand-amount ยังมี element อยู่ (การ์ดยอดขาย)
        const ga=document.getElementById('h-grand-amount');
        if(ga) ga.textContent=fmt2(d.grand_amount||0);
        // subtitle การ์ดแสดงเงื่อนไข + จำนวนที่แสดง
        const hCardSub=document.getElementById('h-card-filter-label');
        if(hCardSub){
            let sub=d.start_date+(d.start_date!==d.end_date?' – '+d.end_date:'');
            if(d.time_start||d.time_end) sub+='  ⏱ '+(d.time_start||'00:00')+'–'+(d.time_end||'23:59');
            if(d.branch_filter) sub+='  📍 '+d.branch_filter;
            if(d.limit_n) sub+='  📋 แสดง '+fmtN(d.limit_n)+' รายการ';
            hCardSub.textContent=sub;
        }
        document.getElementById('h-salesChart').style.display='';

        const bg=d.branches.map(b=>b.is_new?'rgba(0,255,0,0.7)':b.slip===0?'rgba(255,0,0,0.7)':'rgba(0,188,212,0.7)');
        const bo=bg.map(c=>c.replace('0.7)','1)'));
        if(!histChart){
            const ctx=document.getElementById('h-salesChart').getContext('2d');
            histChart=new Chart(ctx,{type:'bar',data:{
                labels:d.chart_labels,
                datasets:[{label:'ยอดขาย',data:d.chart_data,backgroundColor:bg,borderColor:bo,borderWidth:1}]
            },options:{
                responsive:true,
                plugins:{
                    legend:{display:false},
                    tooltip:{callbacks:{label:c=>fmt2(c.raw)+' บาท'}},
                    datalabels:{color:'#0ff',anchor:'end',align:'top',formatter:v=>fmt2(v)}
                },
                scales:{x:{ticks:{color:'#0ff'}},y:{ticks:{color:'#0ff',callback:v=>fmt2(v)}}}
            },plugins:[ChartDataLabels]});
        }else{
            histChart.data.labels=d.chart_labels;
            histChart.data.datasets[0].data=d.chart_data;
            histChart.data.datasets[0].backgroundColor=bg;
            histChart.data.datasets[0].borderColor=bo;
            histChart.update();
        }

        const tbody=document.querySelector('#h-branch-table tbody');
        tbody.innerHTML='';
        let ts=0,ti=0,ta=0,rowSeq=0;

        d.branches.forEach((b,i)=>{
            const rank=i+1;
            const badge=`<span class="rank-badge">${rank}</span>`;
            const displayName=(b.office_name&&b.office_name!==b.branch)
                ?`<span style="color:#0ff;">${b.office_name}</span><span style="color:#aaa;font-size:13px;"> (${b.branch})</span>`
                :`<span style="color:#0ff;">${b.branch}</span>`;
            const wrapCls=rank<=3?`rank-${rank}`:'';
            const branchHTML=`<span class="${wrapCls}">${badge}</span>${displayName}`;

            let branch_cls='branch', diff_min=9999;
            if(b.lastsale&&b.lastsale!=='-'){const lt=parseDateDMY(b.lastsale);if(lt)diff_min=Math.round((new Date()-lt)/60000);}
            if(b.slip===0)           branch_cls='no-data';
            else if(b.is_new)        branch_cls='branch-new';
            else if(diff_min<=10)    branch_cls='branch-delay-10';
            else if(diff_min<=30)    branch_cls='branch-delay-30';
            else if(diff_min>60)     branch_cls='branch-delay-60';

            let diff_text=b.diff;
            if(diff_min<9999) diff_text+=` (${diff_min} นาที)`;

            const tr=document.createElement('tr');
            tr.className=branch_cls;
            tr.innerHTML=`
                <td style="text-align:center;color:#555;font-size:12px;"></td>
                <td>${branchHTML}</td>
                <td align="right">${fmtN(b.slip)}</td>
                <td align="right">${fmtN(b.item)}</td>
                <td align="right">${fmt2(b.amount)}</td>
                <td>${b.lastsale}</td>
                <td>${diff_text}</td>`;
            tbody.appendChild(tr);
            ts+=b.slip; ti+=b.item; ta+=Number(b.amount);

            if(b.machines&&b.machines.length>0){
                b.machines.forEach(m=>{
                    rowSeq++;
                    const mt=parseDateDMY(m.date);
                    const dm=mt?Math.round((new Date()-mt)/60000):9999;
                    let cls=m.amount===0?'machine-zero':getTimeDiffClass(dm);
                    if(b.is_new&&m.date===b.lastsale) cls='branch-new';
                    let ds=m.date||'-'; if(mt) ds+=` (${dm} นาที)`;
                    const md=m.member_id&&m.member_id!=='-'?`<span class="member-badge">สมาชิก ${m.member_id}</span>`:'';
                    const mLabel=(m.machine_desc&&m.machine_desc!==m.machine)?`[${m.machine}] ${m.machine_desc}`:m.machine;
                    const mr=document.createElement('tr');
                    mr.className=cls+' machine';
                    mr.innerHTML=`
                        <td style="text-align:center;color:#888;font-size:12px;font-weight:bold;">${rowSeq}</td>
                        <td style="padding-left:30px;"><span style="color:#ffcc00;">${mLabel}</span> — ${m.cashier}${m.cashier_name?' '+m.cashier_name:''}</td>
                        <td align="right">${m.slip||'ไม่มีข้อมูล'}</td>
                        <td align="right">${m.item===0?'ไม่มีข้อมูล':fmtN(m.item)}</td>
                        <td align="right">${m.amount===0?'ไม่มีข้อมูล':fmt2(m.amount)}</td>
                        <td>${ds}</td>
                        <td>${md}</td>`;
                    tbody.appendChild(mr);
                    if(m.items&&m.items.length>0){
                        let ir=`<tr style="background:#333;color:#0ff;font-weight:bold;">
                            <th></th>
                            <th style="padding-left:40px;width:130px;">Barcode</th>
                            <th style="min-width:150px;">ชื่อสินค้า</th>
                            <th style="text-align:left;">จำนวน</th>
                            <th style="text-align:left;">ราคา/ชิ้น</th>
                            <th style="text-align:left;">รวม</th>
                            <th></th></tr>`;
                        m.items.forEach((it,ii)=>{
                            const nm=(it.name&&it.name!==it.barcode&&it.name!=='-')?it.name:'';
                            ir+=`<tr class="item-row">
                                <td style="text-align:center;color:#555;font-size:11px;">${ii+1}</td>
                                <td style="padding-left:50px;font-size:12px;">${it.barcode}</td>
                                <td style="color:#aef;font-size:12px;">${nm}</td>
                                <td align="right">${fmtN(it.qty)}</td>
                                <td align="right">${fmt2(it.unit_price)}</td>
                                <td align="right">${fmt2(it.total_amt)}</td>
                                <td></td></tr>`;
                        });
                        tbody.innerHTML+=`<tr><td colspan="7"><table style="width:100%;border-collapse:collapse;">${ir}</table></td></tr>`;
                    }
                });
            }
        });

        const sr=document.createElement('tr');
        sr.className='summary-row';
        sr.innerHTML=`<td></td><td align="center">รวมทั้งหมด (${hOnlineCnt} สาขา / ${rowSeq} รายการ)</td>
            <td align="right">${fmtN(ts)}</td>
            <td align="right">${fmtN(ti)}</td>
            <td align="right">${fmt2(ta)}</td>
            <td colspan="2"></td>`;
        tbody.appendChild(sr);
    })
    .catch(e=>{
        document.querySelector('#h-branch-table tbody').innerHTML=
            `<tr><td colspan="7" class="error"><i class="fas fa-exclamation-triangle"></i> ${e.message}</td></tr>`;
        const _rt5=document.getElementById('refresh-time'); if(_rt5) _rt5.innerText='ERROR';
    });
}

// ── auto-format HH:MM ──────────────────────────────────────
function fmtTime(el) {
    let v = el.value.replace(/[^0-9]/g,'');
    if (v.length >= 3) v = v.slice(0,2) + ':' + v.slice(2,4);
    el.value = v;
    // validate range
    el.style.borderColor = /^\d{2}:\d{2}$/.test(el.value) || el.value === '' ? '#0a4a4a' : '#ff6b35';
}

// ── ล้างตัวกรองสาขาและเวลา ───────────────────────────────
function clearFilters() {
    document.getElementById('branch-select').value = '';
    document.getElementById('time-start').value    = '';
    document.getElementById('time-end').value      = '';
    updateHistoryDashboard();
}

document.getElementById('h-refresh-btn').addEventListener('click',function(){
    this.disabled=true; this.innerHTML='<i class="fas fa-spinner fa-spin"></i> โหลด...';
    updateHistoryDashboard();
    setTimeout(()=>{this.disabled=false;this.innerHTML='<i class="fas fa-sync"></i> รีเฟรช';},2000);
});

document.getElementById('hist-form').addEventListener('submit',function(e){
    e.preventDefault();
    updateHistoryDashboard();
});

$(function(){
    // แสดง placeholder เมื่อยังไม่ได้ค้นหา
    document.querySelector('#h-branch-table tbody').innerHTML =
        `<tr><td colspan="6" style="text-align:center;padding:60px 20px;color:#0ff;font-size:20px;">
        <i class="fas fa-search" style="font-size:40px;margin-bottom:15px;display:block;opacity:0.5;"></i>
        กรุณาเลือกช่วงวันที่แล้วกด <strong>ค้นหา</strong> เพื่อโหลดข้อมูล</td></tr>`;

    const hOpts={dateFormat:'dd/mm/yy',changeMonth:true,changeYear:true,
                 maxDate:'today',minDate:null};
    $('#h-start,#h-end').datepicker(hOpts);
    $('#h-start-icon').click(()=>$('#h-start').datepicker('show'));
    $('#h-end-icon').click(()=>$('#h-end').datepicker('show'));
    $('#h-start').change(function(){$('#h-end').datepicker('option','minDate',this.value);});
    $('#h-end').change(function(){$('#h-start').datepicker('option','maxDate',this.value);});
    // ตั้งค่า default เป็นเมื่อวาน
    const hNow=new Date();
    const hYest=new Date(hNow); hYest.setDate(hYest.getDate()-1);
    const hYestStr=('0'+hYest.getDate()).slice(-2)+'/'+('0'+(hYest.getMonth()+1)).slice(-2)+'/'+hYest.getFullYear();
    const hToday=hNow;
    const hTs=('0'+hToday.getDate()).slice(-2)+'/'+('0'+(hToday.getMonth()+1)).slice(-2)+'/'+hToday.getFullYear();
    if (!$('#h-start').val()) { $('#h-start').datepicker('setDate', hYestStr); }
    if (!$('#h-end').val())   { $('#h-end').datepicker('setDate', hYestStr); }
    ['#h-start','#h-end'].forEach(sel=>{
        $(sel).on('dblclick',function(){
            $(this).val(hTs).datepicker('setDate',hTs);
            updateHistoryDashboard();
        });
    });
    $('#hist-form select').on('keydown',e=>{
        if(e.key==='Enter'){e.preventDefault();updateHistoryDashboard();}
    });
    document.getElementById('h-refresh-btn').addEventListener('click',updateHistoryDashboard);

    // คืนค่าสาขาที่เลือกจาก URL (เหมือน POS_HOME)
    const urlBranch = new URLSearchParams(window.location.search).get('branch') || '';
    if (urlBranch) {
        // today select
        const dSel = document.getElementById('detail-branch-select');
        if (dSel) { dSel.value = urlBranch; if (dSel.value !== urlBranch) dSel.value = ''; }
        // history select
        const hSel = document.getElementById('branch-select');
        if (hSel) { hSel.value = urlBranch; if (hSel.value !== urlBranch) hSel.value = ''; }
    }
});


// top-right expand/collapse: handled by POS_TOPRIGHT.php

</script>
</body>
</html>