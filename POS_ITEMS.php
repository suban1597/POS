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
$MENU_ACTIVE = 'items';
require_once __DIR__ . '/POS_AUTH.php';
require_once __DIR__ . '/POS_SETTINGS.php';
pos_check_expiry(); // ล็อกถ้าบัญชีหมดอายุ
pos_guard('items');

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
// เปิด error สำหรับ debug
ini_set('display_errors', 1);
error_reporting(E_ALL);
// ---------------------------
// CONFIG
// ---------------------------
$instant_client_path = "/opt/oracle/instantclient_21_4";
$oracle_user = "system";
$oracle_pass = "system";
$oracle_tns = "CUBACKUP";
// ---------------------------
// BRANCH MAP — populated dynamically in AJAX handler
// ---------------------------
$display_branches = [];
// ---------------------------
// INPUT FILTERS
// ---------------------------
$mode = ($_GET['mode'] ?? 'today') === 'history' ? 'history' : 'today';
$search_mode_raw = $_GET['search_mode'] ?? 'sales';
$search_mode = in_array($search_mode_raw, ['sales', 'product', 'bestseller']) ? $search_mode_raw : 'sales';
$_yesterday = date('d/m/Y', strtotime('-1 day'));
$start_date = $_GET['start'] ?? ($mode === 'history' ? $_yesterday : date('d/m/Y'));
$end_date   = $_GET['end']   ?? ($mode === 'history' ? $_yesterday : date('d/m/Y'));
$branch_filter = trim($_GET['branch'] ?? '');
$limit = max(1, min(1000000, (int)($_GET['limit'] ?? 100)));
$sort_by = $_GET['sort'] ?? 'amount';
$search = trim($_GET['search'] ?? '');
$item_group = trim($_GET['item_group'] ?? ''); // กลุ่มสินค้า (ITMGRP_CODE)
$start_ts = DateTime::createFromFormat('d/m/Y', $start_date);
$end_ts = DateTime::createFromFormat('d/m/Y', $end_date);
$errors = [];
if (!$start_ts || !$end_ts || $start_ts->format('d/m/Y') !== $start_date || $end_ts->format('d/m/Y') !== $end_date) {
    $errors[] = "รูปแบบวันที่ไม่ถูกต้อง (ใช้ วว/ดด/ปปปป เช่น 14/11/2025)";
} elseif ($start_ts > $end_ts) {
    $errors[] = "วันที่เริ่มต้องไม่เกินวันที่สิ้นสุด";
}
// ajax + errors → ตอบ JSON
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && !empty($errors)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => implode(', ', $errors)], JSON_UNESCAPED_UNICODE);
    exit;
}
// ---------------------------
// AJAX ENDPOINT — ดึงรายการกลุ่มสินค้า (INV_ITEM_GROUP)
// ---------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && ($_GET['stat'] ?? '') === 'itemgroup') {
    while (ob_get_level() > 0) ob_end_clean();
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');
    $instant_client_path = rtrim($instant_client_path, '/');
    $sqlplus_path = "{$instant_client_path}/sqlplus";
    if (!is_executable($sqlplus_path)) { echo json_encode(['error'=>'SQL*Plus Not Found']); exit; }
    $ig_sql = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 500 TRIMSPOOL ON\n"
            . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
            . "SELECT TRIM(g.ITMGRP_CODE)||'|'||NVL(TRIM(g.ITMGRP_DESC),TRIM(g.ITMGRP_CODE))\n"
            . "FROM POS.INV_ITEM_GROUP g\n"
            . "WHERE g.ITMGRP_CODE IS NOT NULL AND TRIM(g.ITMGRP_CODE) IS NOT NULL\n"
            . "ORDER BY g.ITMGRP_CODE;\nEXIT;\n";
    $ig_file = sys_get_temp_dir() . '/POS_IG_' . uniqid() . '.sql';
    file_put_contents($ig_file, $ig_sql);
    $ig_out = (string)shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s " . escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}") . " @{$ig_file} 2>&1");
    @unlink($ig_file);
    $groups = [];
    foreach (preg_split('/\r?\n/', $ig_out) as $gl) {
        $gl = trim($gl);
        if ($gl === '' || preg_match('/^(ORA-|SP2-)/', $gl)) continue;
        $gp = explode('|', $gl, 2);
        if (count($gp) === 2 && $gp[0] !== '') {
            $groups[] = ['code' => trim($gp[0]), 'desc' => trim($gp[1]) ?: trim($gp[0])];
        }
    }
    echo json_encode(['groups' => $groups], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------
// AJAX ENDPOINT — โหลด stat สินค้าทั้งหมด (เรียกแยกตั้งแต่ page load)
// ---------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && isset($_GET['stat']) && $_GET['stat'] === 'product') {
    header('Content-Type: application/json; charset=utf-8');
    $instant_client_path = rtrim($instant_client_path, '/');
    $sqlplus_path = "{$instant_client_path}/sqlplus";
    if (!is_executable($sqlplus_path)) {
        echo json_encode(['error' => "SQL*Plus Not Found"]);
        exit;
    }
    $all_product_count = 0;
    $sql_cnt =
        "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 300 TRIMSPOOL ON\n" .
        "ALTER SESSION SET NLS_LANGUAGE = American;\n" .
        "SELECT TRIM(TO_CHAR(COUNT(*))) FROM POS.POS_PRODUCT WHERE BARCODE IS NOT NULL AND TRIM(BARCODE) IS NOT NULL;\n" .
        "EXIT;\n";
    $tmp_cnt = sys_get_temp_dir() . '/POS_CNT_' . uniqid() . '.sql';
    file_put_contents($tmp_cnt, $sql_cnt);
    $up_s = escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}");
    $out_cnt = shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s {$up_s} @{$tmp_cnt} 2>&1");
    @unlink($tmp_cnt);
    foreach (explode("\n", (string)$out_cnt) as $cl) {
        $cl = trim($cl);
        if ($cl !== '' && is_numeric($cl)) { $all_product_count = (int)$cl; break; }
    }
    $last_product_date = '-';
    $last_product_barcode = '-';
    $last_product_name = '-';
    $sql_lp =
        "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 500 TRIMSPOOL ON\n" .
        "ALTER SESSION SET NLS_LANGUAGE = American;\n" .
        "SELECT TO_CHAR(t.dt,'DD/MM/YYYY HH24:MI')||'|'||t.BARCODE||'|'||t.PRODUCT_DESC FROM (\n" .
        "  SELECT NVL(MODIFIED_DATE,CREATE_DATE) dt, BARCODE,\n" .
        "         SUBSTR(NVL(TRIM(PRODUCT_DESC),BARCODE),1,80) PRODUCT_DESC\n" .
        "  FROM POS.POS_PRODUCT\n" .
        "  WHERE NVL(MODIFIED_DATE,CREATE_DATE) IS NOT NULL\n" .
        "  ORDER BY NVL(MODIFIED_DATE,CREATE_DATE) DESC, BARCODE DESC\n" .
        ") t WHERE ROWNUM = 1;\n" .
        "EXIT;\n";
    $tmp_lp = sys_get_temp_dir() . '/POS_LP_' . uniqid() . '.sql';
    file_put_contents($tmp_lp, $sql_lp);
    $out_lp = shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s {$up_s} @{$tmp_lp} 2>&1");
    @unlink($tmp_lp);
    foreach (explode("\n", (string)$out_lp) as $ll) {
        $ll = trim($ll);
        if ($ll === '' || preg_match('/^(ORA-|SP2-)/', $ll)) continue;
        $lp = explode('|', $ll, 3);
        if (count($lp) >= 2 && $lp[0] !== '') {
            $last_product_date    = trim($lp[0]);
            $last_product_barcode = trim($lp[1]);
            $last_product_name    = isset($lp[2]) ? trim($lp[2]) : '-';
            break;
        }
    }
    echo json_encode([
        'all_product_count'    => $all_product_count,
        'last_product_date'    => $last_product_date,
        'last_product_barcode' => $last_product_barcode,
        'last_product_name'    => $last_product_name,
        'debug_cnt_raw'        => mb_substr((string)$out_cnt, 0, 500),
        'debug_lp_raw'         => mb_substr((string)$out_lp, 0, 500),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------
// AJAX PROCESS — โหมดค้นหาสินค้าใน POS_PRODUCT
// ---------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && $search_mode === 'product') {
    header('Content-Type: application/json; charset=utf-8');
    $instant_client_path = rtrim($instant_client_path, '/');
    $sqlplus_path = "{$instant_client_path}/sqlplus";
    if (!is_executable($sqlplus_path)) {
        echo json_encode(['error' => "SQL*Plus Not Found: {$sqlplus_path}"]);
        exit;
    }
    $esc_search = str_replace("'", "''", strtoupper($search));
    $limit_sql  = (int)$limit;
    // search ว่าง = แสดงทั้งหมด, มีคำค้น = กรองตามคำค้น
    if ($esc_search !== '') {
        $search_cond = "WHERE (UPPER(BARCODE) LIKE '%{$esc_search}%' OR UPPER(NVL(TRIM(PRODUCT_DESC),BARCODE)) LIKE '%{$esc_search}%')";
    } else {
        $search_cond = "WHERE BARCODE IS NOT NULL AND TRIM(BARCODE) IS NOT NULL AND BARCODE <> '-'";
    }
    $sql_prod_search =
        "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 1000 TRIMSPOOL ON\n" .
        "ALTER SESSION SET NLS_LANGUAGE = American;\n" .
        "SELECT BARCODE\n" .
        "  ||'|'|| NVL(TRIM(PRODUCT_DESC),'-')\n" .
        "  ||'|'|| NVL(TRIM(TYPE_ID),'-')\n" .
        "  ||'|'|| NVL(TRIM(CATEGORY_ID),'-')\n" .
        "  ||'|'|| NVL(TO_CHAR(UNIT_PRICE),'0')\n" .
        "  ||'|'|| NVL(TO_CHAR(FULL_PRICE),'0')\n" .
        "  ||'|'|| NVL(VAT_FLAG,'-')\n" .
        "  ||'|'|| NVL(TRIM(SUBSTR(AUTHOR_NAME,1,80)),'-')\n" .
        "  ||'|'|| NVL(TRIM(PUBLISHER_CODE),'-')\n" .
        "  ||'|'|| NVL(LANGUAGE_FLAG,'-')\n" .
        "  ||'|'|| NVL(STATUS_FLAG,'-')\n" .
        "  ||'|'|| NVL(TO_CHAR(MODIFIED_DATE,'DD/MM/YYYY'),'')\n" .
        "FROM (\n" .
        "  SELECT BARCODE,PRODUCT_DESC,TYPE_ID,CATEGORY_ID,UNIT_PRICE,FULL_PRICE,\n" .
        "         VAT_FLAG,AUTHOR_NAME,PUBLISHER_CODE,LANGUAGE_FLAG,STATUS_FLAG,MODIFIED_DATE\n" .
        "  FROM POS.POS_PRODUCT\n" .
        "  {$search_cond}\n" .
        "  ORDER BY BARCODE\n" .
        ") WHERE ROWNUM <= {$limit_sql};\n" .
        "SELECT '@@COUNT@@'||COUNT(*) FROM POS.POS_PRODUCT {$search_cond};\n" .
        "EXIT;\n";
    $tmp_ps = sys_get_temp_dir() . '/POS_PSEARCH_' . uniqid() . '.sql';
    file_put_contents($tmp_ps, $sql_prod_search);
    $up = escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}");
    $out_ps = shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s {$up} @{$tmp_ps} 2>&1");
    @unlink($tmp_ps);
    foreach (explode("\n", (string)$out_ps) as $chk) {
        $chk = trim($chk);
        if ($chk === '') continue;
        if (preg_match('/^(ORA-|SP2-)/', $chk)) {
            echo json_encode(['error' => 'Oracle Error: ' . htmlspecialchars($out_ps)]);
            exit;
        }
    }
    $products = [];
    $product_total = 0;
    foreach (explode("\n", (string)$out_ps) as $pl) {
        $pl = trim($pl);
        if ($pl === '' || preg_match('/^(ORA-|SP2-)/', $pl)) continue;
        if (strpos($pl, '@@COUNT@@') === 0) { $product_total = (int)substr($pl, 9); continue; }
        $pp = explode('|', $pl, 12);
        if (count($pp) >= 5) {
            $products[] = [
                'barcode'    => trim($pp[0]),
                'name'       => trim($pp[1]),
                'type_id'    => trim($pp[2]),
                'category'   => trim($pp[3]),
                'unit_price' => (float)trim($pp[4]),
                'full_price' => isset($pp[5])  ? (float)trim($pp[5])  : 0,
                'vat'        => isset($pp[6])  ? trim($pp[6])  : '-',
                'author'     => isset($pp[7])  ? trim($pp[7])  : '-',
                'publisher'  => isset($pp[8])  ? trim($pp[8])  : '-',
                'language'   => isset($pp[9])  ? trim($pp[9])  : '-',
                'status'     => isset($pp[10]) ? trim($pp[10]) : '-',
                'modified'   => isset($pp[11]) ? trim($pp[11]) : '',
            ];
        }
    }
    echo json_encode([
        'search_mode'   => 'product',
        'search'        => $search,
        'products'      => $products,
        'product_total' => $product_total ?: count($products),
        'limit'         => $limit,
        'refresh_time'  => date('d/m/Y H:i:s'),
        'debug_raw'     => mb_substr((string)$out_ps, 0, 1000),
        'debug_cond'    => $search_cond,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------
// AJAX PROCESS — โหมดยอดขายย้อนหลัง (ดึงจาก POS_SALE_DT + POS_SALE_HD)
// ---------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && $mode === 'history' && $search_mode === 'sales' && empty($errors)) {
    // ล้าง output buffer ทั้งหมดก่อนส่ง JSON (ป้องกัน warning/notice ปะปน)
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    // ป้องกัน HTTP 504 Gateway Timeout
    set_time_limit(0);
    ini_set('max_execution_time', '0');
    // บอก nginx/proxy ไม่ต้อง buffer response (ป้องกัน 504)
    header('X-Accel-Buffering: no');
    ignore_user_abort(true);

    $instant_client_path = rtrim($instant_client_path, '/');
    $sqlplus_path = "{$instant_client_path}/sqlplus";
    if (!is_executable($sqlplus_path)) {
        echo json_encode(['error' => "SQL*Plus Not Found: {$sqlplus_path}"]);
        exit;
    }

    $branch_access_clause = function_exists('pos_branch_sql') ? pos_branch_sql('h.SALE_OFFICE') : '1=1';
    $esc_branch = str_replace("'", "''", $branch_filter);
    $branch_condition = "AND ({$branch_access_clause})"
        . ($branch_filter !== '' ? " AND TRIM(h.SALE_OFFICE) = '{$esc_branch}'" : '');

    $sql_file = sys_get_temp_dir() . "/POS_HIST_" . uniqid() . ".sql";

    // ── build search / sort / limit safely ─────────────────────────────────
    $esc_search_hist  = str_replace("'", "''", strtoupper(trim($search)));
    // search condition — ต้องขึ้นต้นด้วย AND เสมอ (ใส่ใน WHERE clause ได้ทันที)
    $search_sql_hist  = ($esc_search_hist !== '')
        ? "AND (UPPER(d.BARCODE) LIKE '%{$esc_search_hist}%' OR UPPER(NVL(TRIM(p.PRODUCT_DESC),d.BARCODE)) LIKE '%{$esc_search_hist}%')"
        : "AND 1=1";   // placeholder — ป้องกัน GROUP BY ขึ้นต้นเป็น SP2-0734

    $sort_col_hist  = ($sort_by === 'qty') ? 'SUM(d.QTY)' : 'SUM(d.TOTAL_AMOUNT)';
    $limit_int      = (int)$limit;

    // ── esc dates สำหรับ PHP string concat (ใช้นอก heredoc) ───────────────
    $sd = str_replace("'", "''", $start_date);
    $ed = str_replace("'", "''", $end_date);

    // ── สร้าง SQL ด้วย PHP string — ป้องกัน variable interpolation ที่ไม่ตั้งใจ ──
    $sql_content  = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 3000 TRIMSPOOL ON\n";
    $sql_content .= "ALTER SESSION SET NLS_TERRITORY = America;\n";
    $sql_content .= "ALTER SESSION SET NLS_LANGUAGE = American;\n";

    // ── Query 1: ยอดรวมต่อ barcode ──────────────────────────────────────────
    $sql_content .= "SELECT 'ITEM'\n";
    $sql_content .= "    ||'|'|| d.BARCODE\n";
    $sql_content .= "    ||'|'|| SUBSTR(MAX(NVL(TRIM(d.PRODUCT_DESC),d.BARCODE)),1,200)\n";
    $sql_content .= "    ||'|'|| TO_CHAR(SUM(d.QTY))\n";
    $sql_content .= "    ||'|'|| TO_CHAR(SUM(d.TOTAL_AMOUNT),'FM999999999990.00')\n";
    $sql_content .= "FROM (\n";
    $sql_content .= "    SELECT /*+ INDEX(h IDX_SALE_HD_CDATE_OFF) INDEX(d IDX_SALE_DT_DATE_BC) */\n";
    $sql_content .= "           d.BARCODE,\n";
    $sql_content .= "           d.QTY,\n";
    $sql_content .= "           d.TOTAL_AMOUNT,\n";
    $sql_content .= "           h.SALE_OFFICE,\n";
    $sql_content .= "           NVL(TRIM(p.PRODUCT_DESC), d.BARCODE) AS PRODUCT_DESC\n";
    $sql_content .= "    FROM POS.POS_SALE_DT d\n";
    $sql_content .= "    JOIN POS.POS_SALE_HD h\n";
    $sql_content .= "      ON h.SLIP_NO = d.SLIP_NO\n";
    $sql_content .= "     AND h.CREATE_DATE >= TO_DATE('{$sd}','DD/MM/YYYY')\n";
    $sql_content .= "     AND h.CREATE_DATE <  TO_DATE('{$ed}','DD/MM/YYYY') + 1\n";
    $sql_content .= "    LEFT JOIN POS.POS_PRODUCT p ON p.BARCODE = d.BARCODE\n";
    $sql_content .= "    WHERE d.SALE_DATE  >= TO_DATE('{$sd}','DD/MM/YYYY')\n";
    $sql_content .= "      AND d.SALE_DATE  <  TO_DATE('{$ed}','DD/MM/YYYY') + 1\n";
    $sql_content .= "      AND d.BARCODE IS NOT NULL\n";
    $sql_content .= "      AND d.QTY <> 0\n";
    $sql_content .= "      {$branch_condition}\n";
    $sql_content .= "      {$search_sql_hist}\n";
    $sql_content .= ") d\n";
    $sql_content .= "GROUP BY d.BARCODE\n";
    $sql_content .= "HAVING SUM(d.QTY) > 0\n";
    $sql_content .= "ORDER BY {$sort_col_hist} DESC, d.BARCODE;\n\n";
    // หมายเหตุ: ใช้ ROWNUM กรองใน PHP parser แทน FETCH FIRST (compatible ทุก Oracle version)

    // ── Query 2: ยอดแยกต่อสาขา ─────────────────────────────────────────────
    $sql_content .= "SELECT 'BRANCH'\n";
    $sql_content .= "    ||'|'|| d.BARCODE\n";
    $sql_content .= "    ||'|'|| TRIM(d.SALE_OFFICE)\n";
    $sql_content .= "    ||'|'|| TO_CHAR(SUM(d.QTY))\n";
    $sql_content .= "    ||'|'|| TO_CHAR(SUM(d.TOTAL_AMOUNT),'FM999999999990.00')\n";
    $sql_content .= "FROM (\n";
    $sql_content .= "    SELECT /*+ INDEX(h IDX_SALE_HD_CDATE_OFF) INDEX(d IDX_SALE_DT_DATE_BC) */\n";
    $sql_content .= "           d.BARCODE, d.QTY, d.TOTAL_AMOUNT, h.SALE_OFFICE\n";
    $sql_content .= "    FROM POS.POS_SALE_DT d\n";
    $sql_content .= "    JOIN POS.POS_SALE_HD h\n";
    $sql_content .= "      ON h.SLIP_NO = d.SLIP_NO\n";
    $sql_content .= "     AND h.CREATE_DATE >= TO_DATE('{$sd}','DD/MM/YYYY')\n";
    $sql_content .= "     AND h.CREATE_DATE <  TO_DATE('{$ed}','DD/MM/YYYY') + 1\n";
    $sql_content .= "    LEFT JOIN POS.POS_PRODUCT p ON p.BARCODE = d.BARCODE\n";
    $sql_content .= "    WHERE d.SALE_DATE  >= TO_DATE('{$sd}','DD/MM/YYYY')\n";
    $sql_content .= "      AND d.SALE_DATE  <  TO_DATE('{$ed}','DD/MM/YYYY') + 1\n";
    $sql_content .= "      AND d.BARCODE IS NOT NULL\n";
    $sql_content .= "      AND d.QTY <> 0\n";
    $sql_content .= "      {$branch_condition}\n";
    $sql_content .= "      {$search_sql_hist}\n";
    $sql_content .= ") d\n";
    $sql_content .= "GROUP BY d.BARCODE, TRIM(d.SALE_OFFICE)\n";
    $sql_content .= "HAVING SUM(d.QTY) > 0;\n\n";

    // ── Query 3: TOTAL_ITEMS ────────────────────────────────────────────────
    $sql_content .= "SELECT 'COUNT|'||TO_CHAR(COUNT(*)) FROM (\n";
    $sql_content .= "    SELECT d.BARCODE\n";
    $sql_content .= "    FROM POS.POS_SALE_DT d\n";
    $sql_content .= "    JOIN POS.POS_SALE_HD h\n";
    $sql_content .= "      ON h.SLIP_NO = d.SLIP_NO\n";
    $sql_content .= "     AND h.CREATE_DATE >= TO_DATE('{$sd}','DD/MM/YYYY')\n";
    $sql_content .= "     AND h.CREATE_DATE <  TO_DATE('{$ed}','DD/MM/YYYY') + 1\n";
    $sql_content .= "    LEFT JOIN POS.POS_PRODUCT p ON p.BARCODE = d.BARCODE\n";
    $sql_content .= "    WHERE d.SALE_DATE  >= TO_DATE('{$sd}','DD/MM/YYYY')\n";
    $sql_content .= "      AND d.SALE_DATE  <  TO_DATE('{$ed}','DD/MM/YYYY') + 1\n";
    $sql_content .= "      AND d.BARCODE IS NOT NULL\n";
    $sql_content .= "      AND d.QTY <> 0\n";
    $sql_content .= "      {$branch_condition}\n";
    $sql_content .= "      {$search_sql_hist}\n";
    $sql_content .= "    GROUP BY d.BARCODE HAVING SUM(d.QTY) > 0\n";
    $sql_content .= ");\n\n";
    $sql_content .= "EXIT;\n";

    // โหลด office_name_map จาก POS_SALE_HD + POS_SALE_OFFICE
    $office_name_map  = [];
    $display_branches = [];
    $esc_start = str_replace("'","''",$start_date);
    $esc_end   = str_replace("'","''",$end_date);

    if ($branch_filter !== '') {
        $esc_bf = str_replace("'","''",$branch_filter);
        $sb_sql = sys_get_temp_dir() . "/POS_HSBR_" . uniqid() . ".sql";
        file_put_contents($sb_sql,
            "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON LINESIZE 300\n" .
            "ALTER SESSION SET NLS_LANGUAGE = American;\n" .
            "SELECT NVL(TRIM(OFFICE_NAME),'{$esc_bf}')\n" .
            "FROM POS.POS_SALE_OFFICE\n" .
            "WHERE TRIM(SALE_OFFICE) = '{$esc_bf}' AND ROWNUM=1;\nEXIT;\n"
        );
        $sb_out = trim((string)shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s " . escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}") . " @$sb_sql 2>&1"));
        @unlink($sb_sql);
        $oname_val = ($sb_out !== '' && !preg_match('/^(ORA-|SP2-)/', $sb_out)) ? $sb_out : $branch_filter;
        $display_branches = [$branch_filter];
        $office_name_map  = [$branch_filter => $oname_val];
    } else {
        $obr_sql = sys_get_temp_dir() . "/POS_HOBR_" . uniqid() . ".sql";
        file_put_contents($obr_sql,
            "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON LINESIZE 300\n" .
            "ALTER SESSION SET NLS_LANGUAGE = American;\n" .
            "SELECT DISTINCT TRIM(h.SALE_OFFICE)||'|'||NVL(TRIM(o.OFFICE_NAME),TRIM(h.SALE_OFFICE))\n" .
            "FROM POS.POS_SALE_HD h\n" .
            "LEFT JOIN POS.POS_SALE_OFFICE o ON o.SALE_OFFICE = h.SALE_OFFICE\n" .
            "WHERE h.SALE_OFFICE IS NOT NULL AND TRIM(h.SALE_OFFICE) IS NOT NULL\n" .
            "  AND h.CREATE_DATE >= TO_DATE('{$esc_start}','DD/MM/YYYY')\n" .
            "  AND h.CREATE_DATE <  TO_DATE('{$esc_end}','DD/MM/YYYY') + 1\n" .
            "ORDER BY 1;\nEXIT;\n"
        );
        $obr_out = shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s " . escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}") . " @$obr_sql 2>&1");
        @unlink($obr_sql);
        foreach (preg_split('/\r?\n/', (string)$obr_out) as $ol) {
            $ol = trim($ol);
            if ($ol === '' || preg_match('/^(ORA-|SP2-)/', $ol)) continue;
            $op = explode('|', $ol, 2);
            $code = trim($op[0]); $oname = isset($op[1]) ? trim($op[1]) : $code;
            // กรองสาขาตามสิทธิ์
            if ($code !== '' && (!function_exists('pos_can_see_branch') || pos_can_see_branch($code))) {
                $display_branches[] = $code;
                $office_name_map[$code] = $oname ?: $code;
            }
        }
    }

    file_put_contents($sql_file, $sql_content);
    $user_pass = escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}");
    $output = shell_exec("env NLS_LANG=THAI_THAILAND.AL32UTF8 LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} {$sqlplus_path} -s $user_pass @$sql_file 2>&1");
    @unlink($sql_file);
    $output = mb_convert_encoding((string)$output, 'UTF-8', 'UTF-8');

    // ตรวจ Oracle error
    $errors_from_oracle = [];
    foreach (explode("\n", $output) as $chk) {
        $chk = trim($chk);
        if ($chk === '' || strpos($chk, '|') !== false) continue;
        if (preg_match('/^(ORA-|SP2-)/', $chk)) {
            echo json_encode(['error' => 'Oracle Error: ' . htmlspecialchars($chk), 'raw' => mb_substr($output,0,2000)], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // ── Parse output: ITEM|... และ BRANCH|... และ COUNT|... ──────
    $items_map    = []; // barcode → [barcode, name, qty, amount, branch_details[]]
    $branch_map   = []; // barcode → [branch → [qty, amount]]
    $total_items  = 0;

    foreach (explode("\n", $output) as $raw_line) {
        $line = trim($raw_line);
        if ($line === '') continue;

        if (strpos($line, 'ITEM|') === 0) {
            // ITEM|barcode|name|qty|amount
            $p = explode('|', $line, 6);
            if (count($p) < 5) continue;
            $bc  = trim($p[1]);
            if ($bc === '') continue;
            $items_map[$bc] = [
                'barcode' => $bc,
                'name'    => trim($p[2]) ?: $bc,
                'qty'     => (int)trim($p[3]),
                'amount'  => (float)trim($p[4]),
            ];
            continue;
        }

        if (strpos($line, 'BRANCH|') === 0) {
            // BRANCH|barcode|office|qty|amount
            $p = explode('|', $line, 6);
            if (count($p) < 5) continue;
            $bc  = trim($p[1]);
            $off = trim($p[2]);
            if ($bc === '' || $off === '') continue;
            if (!isset($branch_map[$bc])) $branch_map[$bc] = [];
            $branch_map[$bc][$off] = ['qty' => (int)trim($p[3]), 'amount' => (float)trim($p[4])];
            continue;
        }

        if (strpos($line, 'COUNT|') === 0) {
            $total_items = (int)substr($line, 6);
            continue;
        }
    }

    // รวม branch_details เข้า items + limit ตาม $limit (แทน FETCH FIRST)
    $items      = [];
    $item_count = 0;
    foreach ($items_map as $bc => $item) {
        $bd = [];
        if (isset($branch_map[$bc])) {
            foreach ($branch_map[$bc] as $off => $bv) {
                $bd[] = ['branch' => $off, 'qty' => $bv['qty'], 'amount' => $bv['amount']];
            }
        }
        $item['branch_details'] = $bd;
        $items[] = $item;
        $item_count++;
        if ($item_count >= $limit) break; // จำกัดจำนวนแทน FETCH FIRST
    }

    // คำนวณ summary
    $items         = array_values(array_filter($items, fn($i) => $i['qty'] > 0));
    $shown_items   = count($items);
    $total_items   = $total_items ?: $shown_items;
    $total_qty_all = (int)array_sum(array_column($items, 'qty'));
    $all_qty_total = $total_qty_all;

    if ($sort_by === 'qty') {
        usort($items, function($a,$b){ return $b['qty']!==$a['qty'] ? $b['qty']-$a['qty'] : $b['amount']<=>$a['amount']; });
    } else {
        usort($items, function($a,$b){ return $b['amount']!=$a['amount'] ? $b['amount']<=>$a['amount'] : $b['qty']-$a['qty']; });
    }

    $pivot = []; $grand_total_qty = 0; $grand_total_amt = 0;
    foreach ($items as $item) {
        $row = ['barcode'=>$item['barcode'],'name'=>$item['name'],'qty'=>$item['qty'],'amount'=>$item['amount']];
        foreach ($display_branches as $br) { $row["qty_$br"] = 0; $row["amt_$br"] = 0.0; }
        foreach ($item['branch_details'] as $bd) {
            $br = $bd['branch'];
            if (in_array($br, $display_branches)) { $row["qty_$br"] = $bd['qty']; $row["amt_$br"] = $bd['amount']; }
        }
        $pivot[] = $row; $grand_total_qty += $item['qty']; $grand_total_amt += $item['amount'];
    }
    $chart_labels = array_map(fn($i)=>$i['barcode'], $items);
    $chart_qty    = array_map(fn($i)=>$i['qty'], $items);
    $chart_amt    = array_map(fn($i)=>round($i['amount'],2), $items);

    echo json_encode([
        'search_mode'     => 'sales',
        'data_mode'       => 'history',
        'refresh_time'    => date('d/m/Y H:i:s'),
        'start_date'      => $start_date,
        'end_date'        => $end_date,
        'branch_filter'   => $branch_filter,
        'limit'           => $limit,
        'sort_by'         => $sort_by,
        'search'          => $search,
        'total_items'     => $total_items,
        'shown_items'     => $shown_items,
        'chart_labels'    => $chart_labels,
        'chart_data'      => ($sort_by==='qty') ? $chart_qty : $chart_amt,
        'chart_qty'       => $chart_qty,
        'chart_amt'       => $chart_amt,
        'pivot'           => $pivot,
        'all_branches'    => $display_branches,
        'office_name_map' => $office_name_map,
        'grand_total_qty' => $grand_total_qty,
        'total_qty_all'   => $total_qty_all,
        'all_qty_total'   => $all_qty_total,
        'grand_total_amt' => $grand_total_amt,
        'oracle_errors'   => $errors_from_oracle,
        'raw_output'      => mb_substr($output, 0, 3000),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------
// AJAX PROCESS — โหมดสินค้าขายดี (เปรียบเทียบย้อนหลัง 5 ปี)
// ---------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && $search_mode === 'bestseller') {
    ini_set('display_errors', 0);
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(600);
    $instant_client_path = rtrim($instant_client_path, '/');
    $sqlplus_path = "{$instant_client_path}/sqlplus";
    if (!is_executable($sqlplus_path)) {
        echo json_encode(['error' => "SQL*Plus Not Found: {$sqlplus_path}"]); exit;
    }

    $branch_access_clause = function_exists('pos_branch_sql') ? pos_branch_sql('h.SALE_OFFICE') : '1=1';
    $esc_branch  = str_replace("'", "''", $branch_filter);
    $branch_cond_dt = "AND ({$branch_access_clause})"
        . ($branch_filter !== '' ? " AND TRIM(h.SALE_OFFICE) = '{$esc_branch}'" : '');
    $esc_search  = str_replace("'", "''", strtoupper($search));
    $limit_sql   = (int)$limit;

    // ── Parse วันที่ที่เลือก ─────────────────────────────────
    $sd_bs = DateTime::createFromFormat('d/m/Y', $start_date);
    $ed_bs = DateTime::createFromFormat('d/m/Y', $end_date);
    if (!$sd_bs || !$ed_bs) {
        echo json_encode(['error' => 'วันที่ไม่ถูกต้อง']); exit;
    }

    // ── สร้าง date range สำหรับแต่ละปี (MM-DD เดิม ต่างแค่ปี) ─
    // ปีปัจจุบัน = ปีของ start_date ที่เลือก (ไม่บังคับให้เป็นปีนี้)
    $base_year = (int)$sd_bs->format('Y');
    $years     = [$base_year, $base_year-1, $base_year-2, $base_year-3, $base_year-4];

    // MD (month-day) ของช่วงที่เลือก — ใช้ซ้ำในทุกปี
    $md_start = $sd_bs->format('d/m'); // เช่น 01/06
    $md_end   = $ed_bs->format('d/m'); // เช่น 30/06

    // สร้าง date string สำหรับแต่ละปี
    $date_ranges = [];
    foreach ($years as $yr) {
        $date_ranges[$yr] = [
            'start' => $md_start . '/' . $yr,
            'end'   => $md_end   . '/' . $yr,
        ];
    }

    // ── สร้าง CASE WHEN pivot ─────────────────────────────────
    $yr_qty_cols = '';
    $yr_amt_cols = '';
    foreach ($years as $i => $yr) {
        $n     = $i + 1;
        $ds    = str_replace("'", "''", $date_ranges[$yr]['start']);
        $de    = str_replace("'", "''", $date_ranges[$yr]['end']);
        $yr_qty_cols .= "            SUM(CASE WHEN h.CREATE_DATE >= TO_DATE('{$ds}','DD/MM/YYYY')"
            . " AND h.CREATE_DATE < TO_DATE('{$de}','DD/MM/YYYY') + 1"
            . " THEN d.QTY ELSE 0 END) AS QTY_Y{$n},\n";
        $yr_amt_cols .= "            SUM(CASE WHEN h.CREATE_DATE >= TO_DATE('{$ds}','DD/MM/YYYY')"
            . " AND h.CREATE_DATE < TO_DATE('{$de}','DD/MM/YYYY') + 1"
            . " THEN d.TOTAL_AMOUNT ELSE 0 END) AS AMT_Y{$n}" . ($i < 4 ? ',' : '') . "\n";
    }

    // ── IN list ของทุกปีที่ต้องดึง ───────────────────────────
    $yr_list = implode(',', $years);

    // ── date range รวม (ต้องดึง rows ที่อยู่ใน ANY ปี) ──────
    // ใช้ EXTRACT(YEAR) IN (...) กรองปีก่อน แล้วค่อย CASE กรองวัน
    $ds0 = str_replace("'", "''", $date_ranges[$base_year]['start']);
    $de0 = str_replace("'", "''", $date_ranges[$base_year]['end']);

    $search_where_dt = $esc_search !== ''
        ? "AND (UPPER(d.BARCODE) LIKE '%{$esc_search}%' OR UPPER(NVL(TRIM(p.PRODUCT_DESC),d.BARCODE)) LIKE '%{$esc_search}%')"
        : '';

    // กลุ่มสินค้า — JOIN INV_ITEM → INV_ITEM_GROUP
    $esc_item_group = str_replace("'", "''", $item_group);
    $item_group_join  = "LEFT JOIN POS.INV_ITEM ii ON ii.BARCODE = d.BARCODE\n"
                      . "        LEFT JOIN POS.INV_ITEM_GROUP ig ON ig.ITMGRP_CODE = ii.ITMGRP_CODE";
    $item_group_cond  = $esc_item_group !== ''
        ? "AND TRIM(ii.ITMGRP_CODE) = '{$esc_item_group}'"
        : '';
    // ดึง ITMGRP_DESC ใน SELECT เพื่อส่งกลับ
    $item_group_col   = "MAX(NVL(TRIM(ig.ITMGRP_DESC), TRIM(ii.ITMGRP_CODE))) AS ITMGRP_DESC,";

    // ORDER BY ตาม sort_by (qty หรือ amount) — ปีปัจจุบันช่วงที่เลือกเป็นหลัก
    if ($sort_by === 'qty') {
        $bs_order_by = "SUM(CASE WHEN h.CREATE_DATE >= TO_DATE('{$ds0}','DD/MM/YYYY') AND h.CREATE_DATE < TO_DATE('{$de0}','DD/MM/YYYY') + 1 THEN d.QTY ELSE 0 END) DESC,\n"
                     . "            SUM(CASE WHEN h.CREATE_DATE >= TO_DATE('{$ds0}','DD/MM/YYYY') AND h.CREATE_DATE < TO_DATE('{$de0}','DD/MM/YYYY') + 1 THEN d.TOTAL_AMOUNT ELSE 0 END) DESC";
    } else {
        $bs_order_by = "SUM(CASE WHEN h.CREATE_DATE >= TO_DATE('{$ds0}','DD/MM/YYYY') AND h.CREATE_DATE < TO_DATE('{$de0}','DD/MM/YYYY') + 1 THEN d.TOTAL_AMOUNT ELSE 0 END) DESC,\n"
                     . "            SUM(CASE WHEN h.CREATE_DATE >= TO_DATE('{$ds0}','DD/MM/YYYY') AND h.CREATE_DATE < TO_DATE('{$de0}','DD/MM/YYYY') + 1 THEN d.QTY ELSE 0 END) DESC";
    }

    $sql_file = sys_get_temp_dir() . "/POS_BEST_" . uniqid() . ".sql";
    $sql_content = <<<SQL
SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 3000 TRIMSPOOL ON
SET SERVEROUTPUT ON SIZE UNLIMITED
ALTER SESSION SET NLS_TERRITORY = America;
ALTER SESSION SET NLS_LANGUAGE = American;
DECLARE
    TYPE t_bc_tab  IS TABLE OF VARCHAR2(50);
    TYPE t_nm_tab  IS TABLE OF VARCHAR2(255);
    TYPE t_num_tab IS TABLE OF NUMBER;
    v_bc  t_bc_tab; v_nm  t_nm_tab; v_grp t_nm_tab;
    v_tq t_num_tab; v_ta t_num_tab;
    v_q1 t_num_tab; v_q2 t_num_tab; v_q3 t_num_tab; v_q4 t_num_tab; v_q5 t_num_tab;
    v_a1 t_num_tab; v_a2 t_num_tab; v_a3 t_num_tab; v_a4 t_num_tab; v_a5 t_num_tab;
    v_total NUMBER := 0;
    -- branch detail
    TYPE t_br_bc_tab  IS TABLE OF VARCHAR2(50);
    TYPE t_br_off_tab IS TABLE OF VARCHAR2(20);
    TYPE t_br_qty_tab IS TABLE OF NUMBER;
    v_br_bc  t_br_bc_tab;
    v_br_off t_br_off_tab;
    v_br_qty t_br_qty_tab;
BEGIN
    SELECT BARCODE, PRODUCT_DESC, ITMGRP_DESC, TOT_QTY, TOT_AMT,
           QTY_Y1, QTY_Y2, QTY_Y3, QTY_Y4, QTY_Y5,
           AMT_Y1, AMT_Y2, AMT_Y3, AMT_Y4, AMT_Y5
    BULK COLLECT INTO
           v_bc, v_nm, v_grp, v_tq, v_ta,
           v_q1, v_q2, v_q3, v_q4, v_q5,
           v_a1, v_a2, v_a3, v_a4, v_a5
    FROM (
        SELECT /*+ PARALLEL(d,4) PARALLEL(h,4) INDEX(h IDX_SALE_HD_CDATE_OFF) */
            d.BARCODE,
            MAX(SUBSTR(NVL(TRIM(p.PRODUCT_DESC), d.BARCODE), 1, 200)) AS PRODUCT_DESC,
            {$item_group_col}
            -- TOT = ผลรวมทุกปี (เฉพาะช่วงวันที่เดิม)
            SUM(CASE WHEN EXTRACT(YEAR FROM h.CREATE_DATE) IN ({$yr_list}) THEN d.QTY         ELSE 0 END) AS TOT_QTY,
            SUM(CASE WHEN EXTRACT(YEAR FROM h.CREATE_DATE) IN ({$yr_list}) THEN d.TOTAL_AMOUNT ELSE 0 END) AS TOT_AMT,
{$yr_qty_cols}
{$yr_amt_cols}
        FROM POS.POS_SALE_DT d
        JOIN POS.POS_SALE_HD h ON h.SLIP_NO = d.SLIP_NO
            AND EXTRACT(YEAR FROM h.CREATE_DATE) IN ({$yr_list})
        LEFT JOIN POS.POS_PRODUCT p ON p.BARCODE = d.BARCODE
        {$item_group_join}
        WHERE d.BARCODE IS NOT NULL AND d.QTY <> 0
          {$branch_cond_dt}
          {$search_where_dt}
          {$item_group_cond}
        GROUP BY d.BARCODE
        HAVING SUM(CASE WHEN h.CREATE_DATE >= TO_DATE('{$ds0}','DD/MM/YYYY')
                             AND h.CREATE_DATE < TO_DATE('{$de0}','DD/MM/YYYY') + 1
                        THEN d.QTY ELSE 0 END) > 0
        ORDER BY
            {$bs_order_by}
    );
    v_total := v_bc.COUNT;
    DBMS_OUTPUT.PUT_LINE('TOTAL_ITEMS:'||v_total);
    FOR i IN 1..v_bc.COUNT LOOP
        EXIT WHEN i > {$limit_sql};
        DBMS_OUTPUT.PUT_LINE(
            'BEST|'||NVL(v_bc(i),'-')||'|'||NVL(v_nm(i),v_bc(i))||'|'||NVL(v_grp(i),'-')||'|'||
            TO_CHAR(NVL(v_tq(i),0))||'|'||TO_CHAR(NVL(v_ta(i),0),'FM999999999990.00')||'|'||
            TO_CHAR(NVL(v_q1(i),0))||'|'||TO_CHAR(NVL(v_q2(i),0))||'|'||TO_CHAR(NVL(v_q3(i),0))||'|'||TO_CHAR(NVL(v_q4(i),0))||'|'||TO_CHAR(NVL(v_q5(i),0))||'|'||
            TO_CHAR(NVL(v_a1(i),0),'FM999999999990.00')||'|'||TO_CHAR(NVL(v_a2(i),0),'FM999999999990.00')||'|'||
            TO_CHAR(NVL(v_a3(i),0),'FM999999999990.00')||'|'||TO_CHAR(NVL(v_a4(i),0),'FM999999999990.00')||'|'||
            TO_CHAR(NVL(v_a5(i),0),'FM999999999990.00')
        );
    END LOOP;
    -- ── ดึง qty แยกต่อสาขา สำหรับปีปัจจุบัน (Y1) ──────────────
    SELECT d.BARCODE, h.SALE_OFFICE, SUM(d.QTY)
    BULK COLLECT INTO v_br_bc, v_br_off, v_br_qty
    FROM POS.POS_SALE_DT d
    JOIN POS.POS_SALE_HD h ON h.SLIP_NO = d.SLIP_NO
        AND h.CREATE_DATE >= TO_DATE('{$ds0}','DD/MM/YYYY')
        AND h.CREATE_DATE <  TO_DATE('{$de0}','DD/MM/YYYY') + 1
    WHERE d.BARCODE IS NOT NULL AND d.QTY <> 0
      {$branch_cond_dt}
      {$item_group_cond}
    GROUP BY d.BARCODE, h.SALE_OFFICE
    HAVING SUM(d.QTY) > 0
    ORDER BY d.BARCODE, SUM(d.QTY) DESC;
    FOR i IN 1..v_br_bc.COUNT LOOP
        DBMS_OUTPUT.PUT_LINE('BEST_BR|'||NVL(v_br_bc(i),'-')||'|'||NVL(v_br_off(i),'-')||'|'||TO_CHAR(NVL(v_br_qty(i),0))||'|1');
    END LOOP;
EXCEPTION WHEN OTHERS THEN
    DBMS_OUTPUT.PUT_LINE('FATAL|'||SQLERRM);
END;
/
EXIT;
SQL;
    file_put_contents($sql_file, $sql_content);
    $up   = escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}");
    $cmd  = "env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s {$up} @{$sql_file} 2>&1";
    $output = (string)shell_exec($cmd);
    @unlink($sql_file);

    // เพิ่ม branch query สำหรับปี Y2–Y5 (append เข้า $output)
    foreach ($years as $yi => $yr) {
        if ($yi === 0) continue; // Y1 ทำไปแล้วใน main query
        $yn   = $yi + 1;
        $ds_y = str_replace("'", "''", $date_ranges[$yr]['start']);
        $de_y = str_replace("'", "''", $date_ranges[$yr]['end']);
        $br_sql = "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 3000 TRIMSPOOL ON\n"
                . "SET SERVEROUTPUT ON SIZE UNLIMITED\n"
                . "ALTER SESSION SET NLS_LANGUAGE = American;\n"
                . "DECLARE\n"
                . "    TYPE t_br_bc_tab  IS TABLE OF VARCHAR2(50);\n"
                . "    TYPE t_br_off_tab IS TABLE OF VARCHAR2(20);\n"
                . "    TYPE t_br_qty_tab IS TABLE OF NUMBER;\n"
                . "    v_br_bc  t_br_bc_tab; v_br_off t_br_off_tab; v_br_qty t_br_qty_tab;\n"
                . "BEGIN\n"
                . "    SELECT d.BARCODE, h.SALE_OFFICE, SUM(d.QTY)\n"
                . "    BULK COLLECT INTO v_br_bc, v_br_off, v_br_qty\n"
                . "    FROM POS.POS_SALE_DT d\n"
                . "    JOIN POS.POS_SALE_HD h ON h.SLIP_NO = d.SLIP_NO\n"
                . "        AND h.CREATE_DATE >= TO_DATE('{$ds_y}','DD/MM/YYYY')\n"
                . "        AND h.CREATE_DATE <  TO_DATE('{$de_y}','DD/MM/YYYY') + 1\n"
                . "    WHERE d.BARCODE IS NOT NULL AND d.QTY <> 0\n"
                . "      {$branch_cond_dt}\n"
                . "      {$item_group_cond}\n"
                . "    GROUP BY d.BARCODE, h.SALE_OFFICE\n"
                . "    HAVING SUM(d.QTY) > 0\n"
                . "    ORDER BY d.BARCODE, SUM(d.QTY) DESC;\n"
                . "    FOR i IN 1..v_br_bc.COUNT LOOP\n"
                . "        DBMS_OUTPUT.PUT_LINE('BEST_BR|'||NVL(v_br_bc(i),'-')||'|'||NVL(v_br_off(i),'-')||'|'||TO_CHAR(NVL(v_br_qty(i),0))||'|{$yn}');\n"
                . "    END LOOP;\n"
                . "EXCEPTION WHEN OTHERS THEN NULL;\n"
                . "END;\n/\nEXIT;\n";
        $br_tmp = sys_get_temp_dir() . "/POS_BEST_BR_{$yn}_" . uniqid() . ".sql";
        file_put_contents($br_tmp, $br_sql);
        $br_out = (string)shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s {$up} @{$br_tmp} 2>&1");
        @unlink($br_tmp);
        $output .= "\n" . $br_out;
    }
    // ตรวจ ORA- / SP2- error
    foreach (preg_split('/\r?\n/', $output) as $ln) {
        $ln = trim($ln);
        if ($ln === '' || strpos($ln, '|') !== false) continue;
        if (preg_match('/^(ORA-|SP2-)/', $ln)) {
            echo json_encode(['error' => $ln, 'raw' => mb_substr($output, 0, 1000)], JSON_UNESCAPED_UNICODE); exit;
        }
    }
    // ตรวจ FATAL
    foreach (preg_split('/\r?\n/', $output) as $ln) {
        $ln = trim($ln);
        if (strpos($ln, 'FATAL|') === 0) {
            echo json_encode(['error' => substr($ln, 6), 'raw' => mb_substr($output, 0, 1000)], JSON_UNESCAPED_UNICODE); exit;
        }
    }
    $items = []; $total_items = 0;
    $branch_qty_map = []; // barcode → [yr_no → [office → qty]]
    foreach (preg_split('/\r?\n/', $output) as $ln) {
        $ln = trim($ln);
        if (strpos($ln, 'TOTAL_ITEMS:') === 0) { $total_items = (int)substr($ln, 12); continue; }
        if (strpos($ln, 'BEST_BR|') === 0) {
            $p = explode('|', $ln, 5); // BEST_BR|barcode|office|qty|yr_no
            if (count($p) >= 4 && $p[1] !== '-' && $p[2] !== '-') {
                $yn = isset($p[4]) ? (int)$p[4] : 1;
                $branch_qty_map[trim($p[1])][$yn][trim($p[2])] = (int)$p[3];
            }
            continue;
        }
        if (strpos($ln, 'BEST|') !== 0) continue;
        $p = explode('|', $ln, 17);  // เพิ่ม field itmgrp_desc → 17 fields
        if (count($p) < 16) continue;
        // BEST|barcode|name|itmgrp_desc|tot_qty|tot_amt|q1..q5|a1..a5
        $items[] = [
            'barcode'    => trim($p[1]),
            'name'       => trim($p[2]) ?: trim($p[1]),
            'itmgrp_desc'=> trim($p[3]) !== '-' ? trim($p[3]) : '',
            'qty'        => (int)$p[6],      // QTY_Y1
            'amount'     => (float)$p[11],   // AMT_Y1
            'qty_total'  => (int)$p[4],
            'amt_total'  => (float)$p[5],
            'qty_yr'     => [(int)$p[6],(int)$p[7],(int)$p[8],(int)$p[9],(int)$p[10]],
            'amt_yr'     => [(float)$p[11],(float)$p[12],(float)$p[13],(float)$p[14],(float)$p[15]],
        ];
    }
    // แนบ branch_qty_yr เข้า items (ทุกปี Y1–Y5)
    foreach ($items as &$item) {
        $bc  = $item['barcode'];
        $bqm = isset($branch_qty_map[$bc]) ? $branch_qty_map[$bc] : [];
        $item['branch_qty_yr'] = [];
        foreach ($years as $yi => $yr) {
            $yn = $yi + 1;
            $item['branch_qty_yr'][] = isset($bqm[$yn]) ? $bqm[$yn] : (object)[];
        }
        $item['branch_qty'] = isset($bqm[1]) ? $bqm[1] : (object)[]; // compat Y1
    }
    unset($item);

    // สร้าง label ปีพร้อมช่วงวันที่ เช่น "2025 (01/06–30/06)"
    $year_labels = [];
    foreach ($years as $yr) {
        $year_labels[] = $yr . ' (' . $date_ranges[$yr]['start'] . '–' . $date_ranges[$yr]['end'] . ')';
    }

    echo json_encode([
        'mode'         => 'bestseller',
        'years'        => $years,
        'year_labels'  => $year_labels,
        'date_ranges'  => $date_ranges,
        'items'        => $items,
        'total_items'  => $total_items ?: count($items),
        'shown_items'  => count($items),
        'sort_by'      => $sort_by,
        'branch_filter'=> $branch_filter,
        'item_group'   => $item_group,
        'search'       => $search,
        'start_date'   => $date_ranges[$base_year]['start'],
        'end_date'     => $date_ranges[$base_year]['end'],
        'refresh_time' => date('d/m/Y H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------
// AJAX PROCESS
// ---------------------------
// DEBUG: ?debug_branch=1 → แสดง SQL + raw output ของ html_branches
if (isset($_GET['debug_branch']) && $_GET['debug_branch'] === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    $instant_client_path = rtrim($instant_client_path, '/');
    $sqlplus_path = "{$instant_client_path}/sqlplus";
    $dbg_sql_file = sys_get_temp_dir() . "/POS_DBG_" . uniqid() . ".sql";
    $dbg_content = <<<'DBGSQL'
SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON LINESIZE 300
SET SERVEROUTPUT ON
DECLARE
    CURSOR c IS SELECT table_name FROM all_tables
        WHERE owner='POS' AND table_name LIKE 'POS_SALETODAY_HD_%'
          AND REPLACE(table_name,'POS_SALETODAY_HD_','') NOT IN ('_TMP','TEST99')
        ORDER BY table_name;
    v_branch VARCHAR2(100);
    v_oname  VARCHAR2(100);
    v_code   VARCHAR2(10);
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
            SELECT NVL(TRIM(OFFICE_NAME), v_branch) INTO v_oname
            FROM POS.POS_SALE_OFFICE
            WHERE TRIM(SALE_OFFICE) = v_code AND ROWNUM = 1;
            IF v_oname IS NULL OR TRIM(v_oname) = '' THEN v_oname := v_branch; END IF;
        EXCEPTION WHEN OTHERS THEN v_oname := v_branch; END;
        DBMS_OUTPUT.PUT_LINE(v_branch||'|'||v_oname);
    END LOOP;
END;
/
EXIT;
DBGSQL;
    file_put_contents($dbg_sql_file, $dbg_content);
    echo "=== SQL FILE CONTENT ===
";
    echo $dbg_content;
    echo "
=== SQLPLUS OUTPUT ===
";
    $dbg_cmd = "env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s " . escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}") . " @$dbg_sql_file 2>&1";
    echo shell_exec($dbg_cmd);
    @unlink($dbg_sql_file);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && $mode === 'today' && $search_mode === 'sales' && empty($errors)) {
    header('Content-Type: application/json; charset=utf-8');
  
    $instant_client_path = rtrim($instant_client_path, '/');
    $sqlplus_path = "{$instant_client_path}/sqlplus";
  
    if (!is_executable($sqlplus_path)) {
        echo json_encode(['error' => "SQL*Plus Not Found: {$sqlplus_path}"]);
        exit;
    }
    // โหลดชื่อแคชเชียร์จาก POS.SK_USER
    $cashier_map = load_cashier_map($sqlplus_path, $oracle_user, $oracle_pass, $oracle_tns, $instant_client_path);
    // today: branch_filter ส่งเป็น SALE_OFFICE code — กรองใน SQL ด้วย v_branch_filter เหมือน POS_DETAIL
    $branch_condition    = '';
    $esc_branch_today    = str_replace("'", "''", $branch_filter);
    $branch_filter_today = ($branch_filter !== '') ? $branch_filter : null;
    // สร้าง PL/SQL branch access condition เหมือน POS_DETAIL
    $allowed_branches = function_exists('pos_get_branches') ? pos_get_branches() : null;
    if ($allowed_branches === null) {
        $plsql_branch_access = 'TRUE';
    } elseif (empty($allowed_branches)) {
        $plsql_branch_access = 'FALSE';
    } else {
        $parts = array_map(fn($b) => "v_sc2='" . str_replace("'","''",$b) . "'", $allowed_branches);
        $plsql_branch_access = '(' . implode(' OR ', $parts) . ')';
    }
    $sql_file = sys_get_temp_dir() . "/POS_PIVOT_" . uniqid() . ".sql";
    $sql_content = <<<SQL
SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 LINESIZE 2000 COLSEP '|' TRIMSPOOL ON
SET SERVEROUTPUT ON SIZE UNLIMITED
ALTER SESSION SET NLS_TERRITORY = America;
ALTER SESSION SET NLS_LANGUAGE = American;
DECLARE
    TYPE t_str_tab  IS TABLE OF VARCHAR2(50);
    TYPE t_desc_tab IS TABLE OF VARCHAR2(255);
    TYPE t_num_tab  IS TABLE OF NUMBER;

    -- item aggregation map (barcode → index)
    TYPE t_item_rec IS RECORD (
        barcode      VARCHAR2(50),
        product_desc VARCHAR2(255),
        qty          NUMBER,
        total_amt    NUMBER,
        branches_q   VARCHAR2(4000),
        branches_a   VARCHAR2(4000)
    );
    TYPE t_item_tab IS TABLE OF t_item_rec;
    TYPE t_bc_idx   IS TABLE OF PLS_INTEGER INDEX BY VARCHAR2(50);
    v_items   t_item_tab := t_item_tab();
    v_bc_map  t_bc_idx;

    v_start          DATE := TO_DATE('$start_date', 'DD/MM/YYYY');
    v_end            DATE := TO_DATE('$end_date', 'DD/MM/YYYY') + 1 - 1/86400;
    v_search         VARCHAR2(100) := UPPER('$search');
    v_branch_filter  VARCHAR2(100) := TRIM('$esc_branch_today');

    v_barcodes  t_str_tab;
    v_descs     t_desc_tab;
    v_qtys      t_num_tab;
    v_amts      t_num_tab;
    v_branch    VARCHAR2(30);
    v_sc2       VARCHAR2(20);
    v_oname2    VARCHAR2(100);
    v_idx       PLS_INTEGER;
BEGIN
    DBMS_OUTPUT.PUT_LINE('START_DATA');

    FOR rec IN (
        SELECT table_name
        FROM all_tables
        WHERE owner = 'POS'
          AND table_name LIKE 'POS_SALETODAY_HD_%'
          AND REPLACE(table_name, 'POS_SALETODAY_HD_', '') NOT IN ('_TMP','TEST99')
          $branch_condition
        ORDER BY table_name
    ) LOOP
        v_branch := REPLACE(rec.table_name, 'POS_SALETODAY_HD_', '');
        v_sc2    := v_branch;
        BEGIN
            EXECUTE IMMEDIATE
                'SELECT TRIM(SALE_OFFICE) FROM POS.' || rec.table_name ||
                ' WHERE SALE_OFFICE IS NOT NULL AND TRIM(SALE_OFFICE) IS NOT NULL AND ROWNUM=1'
            INTO v_sc2;
        EXCEPTION WHEN OTHERS THEN v_sc2 := v_branch; END;

        -- กรองตาม branch_filter และสิทธิ์สาขา เหมือน POS_DETAIL
        IF (v_branch_filter IS NULL OR v_branch_filter = ''
            OR TRIM(v_sc2) = v_branch_filter OR v_branch = v_branch_filter)
           AND ({$plsql_branch_access}) THEN
        BEGIN
            EXECUTE IMMEDIATE
                'SELECT d.BARCODE,
                        MAX(SUBSTR(NVL(TRIM(p.PRODUCT_DESC), d.BARCODE), 1, 200)),
                        SUM(d.QTY),
                        SUM(d.TOTAL_AMOUNT)
                 FROM POS.' || REPLACE(rec.table_name,'HD','DT') || ' d
                 JOIN POS.' || rec.table_name || ' h ON h.SLIP_NO = d.SLIP_NO
                 LEFT JOIN POS.POS_PRODUCT p ON p.BARCODE = d.BARCODE
                 WHERE h.CREATE_DATE >= :1 AND h.CREATE_DATE < :2
                   AND d.BARCODE IS NOT NULL
                   AND d.QTY <> 0
                 GROUP BY d.BARCODE
                 HAVING SUM(d.QTY) > 0'
            BULK COLLECT INTO v_barcodes, v_descs, v_qtys, v_amts
            USING v_start, v_end;

            FOR i IN 1..v_barcodes.COUNT LOOP
                IF v_barcodes(i) IS NULL OR TRIM(v_barcodes(i)) IS NULL THEN NULL;
                ELSIF LENGTH(v_search) > 0
                      AND UPPER(v_barcodes(i)) NOT LIKE '%'||v_search||'%'
                      AND UPPER(NVL(v_descs(i), '')) NOT LIKE '%'||v_search||'%' THEN NULL;
                ELSE
                DECLARE v_bc VARCHAR2(50) := TRIM(v_barcodes(i));
                        v_sc VARCHAR2(20)  := v_sc2; BEGIN
                    IF v_bc_map.EXISTS(v_bc) THEN
                        v_idx := v_bc_map(v_bc);
                        v_items(v_idx).qty       := v_items(v_idx).qty       + NVL(v_qtys(i),0);
                        v_items(v_idx).total_amt := v_items(v_idx).total_amt + NVL(v_amts(i),0);
                        IF v_items(v_idx).product_desc IS NULL OR v_items(v_idx).product_desc = v_items(v_idx).barcode THEN
                            v_items(v_idx).product_desc := NVL(TRIM(v_descs(i)), v_items(v_idx).barcode);
                        END IF;
                        v_items(v_idx).branches_q := v_items(v_idx).branches_q || v_sc || ':' || TO_CHAR(NVL(v_qtys(i),0)) || ',';
                        v_items(v_idx).branches_a := v_items(v_idx).branches_a || v_sc || ':' || TO_CHAR(NVL(v_amts(i),0),'FM999999999990.00') || ',';
                    ELSE
                        v_items.EXTEND;
                        v_idx := v_items.LAST;
                        v_bc_map(v_bc) := v_idx;
                        v_items(v_idx).barcode      := v_bc;
                        v_items(v_idx).product_desc := NVL(TRIM(v_descs(i)), v_bc);
                        v_items(v_idx).qty          := NVL(v_qtys(i),0);
                        v_items(v_idx).total_amt    := NVL(v_amts(i),0);
                        v_items(v_idx).branches_q   := v_sc || ':' || TO_CHAR(NVL(v_qtys(i),0)) || ',';
                        v_items(v_idx).branches_a   := v_sc || ':' || TO_CHAR(NVL(v_amts(i),0),'FM999999999990.00') || ',';
                    END IF;
                END;
                END IF; -- filter
            END LOOP;
        EXCEPTION WHEN OTHERS THEN
            DBMS_OUTPUT.PUT_LINE('ERROR|' || v_branch || '|' || SQLERRM);
        END; -- BEGIN EXECUTE IMMEDIATE
        END IF; -- v_branch_filter
    END LOOP;

    -- เรียงลำดับ bubble sort
    FOR i IN 1..v_items.COUNT-1 LOOP
        FOR j IN i+1..v_items.COUNT LOOP
            DECLARE v_tmp t_item_rec; v_swap BOOLEAN := FALSE; BEGIN
                IF '$sort_by' = 'qty' THEN
                    IF NVL(v_items(i).qty,0) < NVL(v_items(j).qty,0) THEN v_swap := TRUE; END IF;
                ELSE
                    IF NVL(v_items(i).total_amt,0) < NVL(v_items(j).total_amt,0) THEN v_swap := TRUE; END IF;
                END IF;
                IF v_swap THEN v_tmp := v_items(i); v_items(i) := v_items(j); v_items(j) := v_tmp; END IF;
            END;
        END LOOP;
    END LOOP;

    -- Output
    FOR i IN 1..LEAST($limit, v_items.COUNT) LOOP
        DBMS_OUTPUT.PUT_LINE(
            'ITEM|' || v_items(i).barcode || '|' ||
            NVL(v_items(i).product_desc, v_items(i).barcode) || '|' ||
            TO_CHAR(NVL(v_items(i).qty,0)) || '|' ||
            TO_CHAR(NVL(v_items(i).total_amt,0),'FM999999999990.00') || '|' ||
            RTRIM(v_items(i).branches_q,',') || '|' ||
            RTRIM(v_items(i).branches_a,',')
        );
    END LOOP;

    DECLARE v_total_qty_all NUMBER := 0; BEGIN
        FOR i IN 1..v_items.COUNT LOOP
            v_total_qty_all := v_total_qty_all + NVL(v_items(i).qty, 0);
        END LOOP;
        DBMS_OUTPUT.PUT_LINE('TOTAL_QTY:' || TO_CHAR(v_total_qty_all));
    END;
    DBMS_OUTPUT.PUT_LINE('TOTAL_ITEMS:' || v_items.COUNT);
    DBMS_OUTPUT.PUT_LINE('SHOWN_ITEMS:' || TO_CHAR(LEAST($limit, v_items.COUNT)));
    DBMS_OUTPUT.PUT_LINE('END_DATA');
EXCEPTION WHEN OTHERS THEN
    DBMS_OUTPUT.PUT_LINE('FATAL_ERROR|' || SQLERRM);
END;
/
EXIT;
SQL;
    // โหลด office_name_map + display_branches แบบ dynamic
    // ถ้าเลือกสาขาเดียว → display_branches = [branch นั้น] เท่านั้น
    if ($branch_filter !== '') {
        // lookup office_name จาก POS_SALE_OFFICE โดยตรง โดยใช้ SALE_OFFICE code
        $esc_bf = str_replace("'", "''", $branch_filter);
        $single_sql = sys_get_temp_dir() . "/POS_SBR_" . uniqid() . ".sql";
        file_put_contents($single_sql,
            "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON LINESIZE 300\n" .
            "ALTER SESSION SET NLS_LANGUAGE = American;\n" .
            "SELECT NVL(TRIM(OFFICE_NAME),'{$esc_bf}') FROM POS.POS_SALE_OFFICE\n" .
            "WHERE TRIM(SALE_OFFICE)='{$esc_bf}' AND ROWNUM=1;\nEXIT;\n"
        );
        $single_out = trim((string)shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s " . escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}") . " @$single_sql 2>&1"));
        @unlink($single_sql);
        $oname_val = ($single_out !== '' && !preg_match('/^(ORA-|SP2-)/', $single_out)) ? $single_out : $branch_filter;
        $display_branches = [$branch_filter];
        $office_name_map  = [$branch_filter => $oname_val];
    } else {
    // ดึง branch code ที่มีข้อมูลจริงในช่วงวันที่ + OFFICE_NAME
    $sd = explode('/', $start_date);
    $ed = explode('/', $end_date);
    $start_iso = count($sd)===3 ? "{$sd[2]}-{$sd[1]}-{$sd[0]}" : $start_date;
    $end_iso   = count($ed)===3 ? "{$ed[2]}-{$ed[1]}-{$ed[0]}" : $end_date;
    $oname_sql_file = sys_get_temp_dir() . "/POS_ONAME_" . uniqid() . ".sql";
    $oname_sql_tpl = <<<'ONAMESQL'
SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON LINESIZE 300
SET SERVEROUTPUT ON
DECLARE
    CURSOR c IS
        SELECT table_name FROM all_tables
        WHERE owner='POS' AND table_name LIKE 'POS_SALETODAY_HD_%'
          AND REPLACE(table_name,'POS_SALETODAY_HD_','') NOT IN ('_TMP','TEST99')
        ORDER BY table_name;
    v_branch           VARCHAR2(100);
    v_oname            VARCHAR2(100);
    v_sale_office_code VARCHAR2(10);
BEGIN
    FOR rec IN c LOOP
        v_branch := REPLACE(rec.table_name,'POS_SALETODAY_HD_','');
        v_oname  := v_branch;
        v_sale_office_code := NULL;
        BEGIN
            EXECUTE IMMEDIATE
                'SELECT TRIM(SALE_OFFICE) FROM POS.' || rec.table_name ||
                ' WHERE SALE_OFFICE IS NOT NULL AND TRIM(SALE_OFFICE) IS NOT NULL AND ROWNUM=1'
            INTO v_sale_office_code;
        EXCEPTION WHEN OTHERS THEN v_sale_office_code := NULL; END;
        IF v_sale_office_code IS NOT NULL THEN
            BEGIN
                SELECT NVL(TRIM(OFFICE_NAME), v_branch) INTO v_oname
                FROM POS.POS_SALE_OFFICE
                WHERE TRIM(SALE_OFFICE) = v_sale_office_code AND ROWNUM = 1;
                IF v_oname IS NULL OR TRIM(v_oname) = '' THEN v_oname := v_branch; END IF;
            EXCEPTION WHEN OTHERS THEN v_oname := v_branch; END;
            DBMS_OUTPUT.PUT_LINE(v_sale_office_code||'|'||v_oname);
        END IF;
    END LOOP;
END;
/
EXIT;
ONAMESQL;
    $oname_sql = $oname_sql_tpl;
    file_put_contents($oname_sql_file, $oname_sql);
    $oname_cmd = "env -i LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path} -s " . escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}") . " @$oname_sql_file 2>&1";
    $oname_out = shell_exec($oname_cmd);
    @unlink($oname_sql_file);
    $office_name_map = [];
    $display_branches = [];
    foreach (preg_split('/\r?\n/', (string)$oname_out) as $ol) {
        $ol = trim($ol);
        if ($ol === '' || preg_match('/^(ORA-|SP2-)/', $ol)) continue;
        $op = explode('|', $ol, 2);
        $code = trim($op[0]);
        $oname = isset($op[1]) ? trim($op[1]) : $code;
        // กรองสาขาตามสิทธิ์ USER_BRANCH_ACCESS
        if ($code !== '' && (!function_exists('pos_can_see_branch') || pos_can_see_branch($code))) {
            $display_branches[] = $code;
            $office_name_map[$code] = ($oname !== '') ? $oname : $code;
        }
    }
    } // end else (ALL branches)

    if (!file_put_contents($sql_file, $sql_content)) {
        echo json_encode(['error' => 'ไม่สามารถเขียนไฟล์ SQL ได้']);
        exit;
    }
    $env = "NLS_LANG=THAI_THAILAND.AL32UTF8";
    $user_pass = escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}");
    $cmd = "env {$env} LD_LIBRARY_PATH={$instant_client_path} TNS_ADMIN={$instant_client_path} {$sqlplus_path} -s $user_pass @$sql_file 2>&1";
    $output = shell_exec($cmd);
    @unlink($sql_file);
    $output = mb_convert_encoding($output, 'UTF-8', 'UTF-8');

    if (empty(trim($output))) {
        echo json_encode(['error' => 'SQL*Plus ไม่มี output', 'output' => '']);
        exit;
    }
    // ตรวจ ORA-/SP2- ที่ไม่มี pipe (error จริง — เหมือน POS_DETAIL)
    $has_error = false;
    foreach (explode("\n", $output) as $chk) {
        $chk = trim($chk);
        if ($chk === '' || strpos($chk, '|') !== false) continue;
        if (preg_match('/^(ORA-|SP2-)/', $chk)) { $has_error = true; break; }
    }
    if ($has_error) {
        echo json_encode(['error' => 'Oracle Error: ' . nl2br(htmlspecialchars($output))]);
        exit;
    }
    $lines = explode("\n", $output);
    $items = [];
    $total_items = 0;
    $shown_items = 0;
    $total_qty_all = 0;
    $errors_from_oracle = [];
    $in_data = false;

    foreach ($lines as $raw_line) {
        $line = trim($raw_line);
        if ($line === '') continue;
        if ($line === 'START_DATA')  { $in_data = true;  continue; }
        if ($line === 'END_DATA')    { $in_data = false; break; }
        if (strpos($line, 'TOTAL_ITEMS:') === 0) {
            $total_items = (int)substr($line, 12); continue;
        }
        if (strpos($line, 'SHOWN_ITEMS:') === 0) {
            $shown_items = (int)substr($line, 12); continue;
        }
        if (strpos($line, 'TOTAL_QTY:') === 0) {
            $total_qty_all = (int)substr($line, 10); continue;
        }
        if (strpos($line, 'ERROR|') === 0 || strpos($line, 'FATAL_ERROR|') === 0) {
            $errors_from_oracle[] = htmlspecialchars($line); continue;
        }
        if (!$in_data) continue;

        if (strpos($line, 'ITEM|') === 0) {
            // format: ITEM|barcode|name|qty|amt|branches_q|branches_a
            $parts = explode('|', $line, 7);
            if (count($parts) < 5) continue;
            $barcode    = trim($parts[1]);
            $name       = trim($parts[2]) ?: trim($parts[1]);
            $qty        = (int)   trim($parts[3]);
            $amount     = (float) trim($parts[4]);
            $branches_q_str = isset($parts[5]) ? trim($parts[5]) : '';
            $branches_a_str = isset($parts[6]) ? trim($parts[6]) : '';

            // parse branches_q: "EDU:5,SB:3"
            $branch_details = [];
            $bq = array_filter(explode(',', $branches_q_str));
            $ba = array_filter(explode(',', $branches_a_str));
            $ba_map = [];
            foreach ($ba as $pair) {
                if (strpos($pair, ':') !== false) {
                    [$br, $av] = explode(':', $pair, 2);
                    $ba_map[trim($br)] = (float)trim($av);
                }
            }
            foreach ($bq as $pair) {
                if (strpos($pair, ':') !== false) {
                    [$br, $qv] = explode(':', $pair, 2);
                    $br = trim($br);
                    $branch_details[] = [
                        'branch' => $br,
                        'qty'    => (int)trim($qv),
                        'amount' => $ba_map[$br] ?? 0.0
                    ];
                }
            }
            $items[] = [
                'barcode'        => $barcode,
                'name'           => $name,
                'qty'            => $qty,
                'amount'         => $amount,
                'branch_details' => $branch_details
            ];
        }
    }

    // SQL กรอง branch_filter และสิทธิ์สาขาแล้วใน Oracle — ใช้ qty/amount จาก SQL โดยตรง
    $oracle_total_items = $total_items; // เก็บจาก Oracle ก่อน (TOTAL_ITEMS = ทั้งหมดก่อน limit)
    $oracle_total_qty   = $total_qty_all; // เก็บ TOTAL_QTY จาก Oracle (qty ทั้งหมดก่อน limit)
    $items = array_values(array_filter($items, fn($i) => $i['qty'] > 0));
    $shown_items   = count($items);                          // จำนวนที่ส่งไป JS จริง
    $total_items   = $oracle_total_items ?: $shown_items;   // ใช้ค่า Oracle ถ้ามี
    $total_qty_all = (int)array_sum(array_column($items, 'qty')); // qty เฉพาะที่แสดง
    $all_qty_total = $oracle_total_qty ?: $total_qty_all;   // qty ทั้งหมด (ก่อน limit)

    // สร้าง Pivot Table
    $pivot = [];
    $grand_total_qty = 0;
    $grand_total_amt = 0;
  
    foreach ($items as $item) {
        $row = [
            'barcode' => $item['barcode'],
            'name' => $item['name'],
            'qty' => $item['qty'],
            'amount' => $item['amount']
        ];
      
        foreach ($display_branches as $br) {
            $row["qty_$br"] = 0;
            $row["amt_$br"] = 0.0;
        }
      
        foreach ($item['branch_details'] as $bd) {
            $br = $bd['branch'];
            if (in_array($br, $display_branches)) {
                $row["qty_$br"] = $bd['qty'];
                $row["amt_$br"] = $bd['amount'];
            }
        }
      
        $pivot[] = $row;
        $grand_total_qty += $item['qty'];
        $grand_total_amt += $item['amount'];
    }
    $chart_labels = array_map(function($i) { return $i['barcode']; }, $items);
    $chart_qty    = array_map(function($i) { return $i['qty']; }, $items);
    $chart_amt    = array_map(function($i) { return round($i['amount'], 2); }, $items);
    $chart_data   = ($sort_by === 'qty') ? $chart_qty : $chart_amt;
    echo json_encode([
        'search_mode'  => 'sales',
        'data_mode'    => 'today',
        'refresh_time' => date('d/m/Y H:i:s'),
        'start_date' => $start_date,
        'end_date' => $end_date,
        'branch_filter' => $branch_filter,
        'limit' => $limit,
        'sort_by' => $sort_by,
        'search' => $search,
        'total_items' => $total_items,
        'shown_items'  => $shown_items,
        'chart_labels' => $chart_labels,
        'chart_data' => $chart_data,
        'chart_qty'   => $chart_qty,
        'chart_amt'   => $chart_amt,
        'pivot' => $pivot,
        'all_branches' => $display_branches,
        'office_name_map' => $office_name_map,
        'grand_total_qty' => $grand_total_qty,
        'total_qty_all'   => $total_qty_all,
        'all_qty_total'   => $all_qty_total,
        'grand_total_amt' => $grand_total_amt,
        'oracle_errors' => $errors_from_oracle,
        'raw_output' => mb_substr($output, 0, 3000)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ดึง branch list สำหรับ HTML dropdown (ทุก branch ไม่ filter ตามวันที่)
// โหลดสาขา TODAY (จาก POS_SALETODAY_HD_*)
$html_branches_today = [];
$html_oname_today = [];
// โหลดสาขา HISTORY (จาก POS_SALE_OFFICE)
$html_branches_history = [];
$html_oname_history = [];

if (empty($errors)) {
    $instant_client_path_t = rtrim($instant_client_path, '/');
    $sqlplus_path_t = "{$instant_client_path_t}/sqlplus";
    if (is_executable($sqlplus_path_t)) {
        $up_t = escapeshellarg("{$oracle_user}/{$oracle_pass}@{$oracle_tns}");

        // --- TODAY branches ---
        $br_today_sql = sys_get_temp_dir() . "/POS_HBRL_TODAY_" . uniqid() . ".sql";
        $br_today_content = <<<'HTMLBRSQL'
SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON LINESIZE 300
SET SERVEROUTPUT ON
DECLARE
    CURSOR c IS SELECT table_name FROM all_tables
        WHERE owner='POS' AND table_name LIKE 'POS_SALETODAY_HD_%'
          AND REPLACE(table_name,'POS_SALETODAY_HD_','') NOT IN ('_TMP','TEST99')
        ORDER BY table_name;
    v_branch VARCHAR2(100);
    v_oname  VARCHAR2(100);
    v_code   VARCHAR2(10);
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
        IF v_code IS NOT NULL THEN
            BEGIN
                SELECT NVL(TRIM(OFFICE_NAME), v_branch) INTO v_oname
                FROM POS.POS_SALE_OFFICE
                WHERE TRIM(SALE_OFFICE) = v_code AND ROWNUM = 1;
                IF v_oname IS NULL OR TRIM(v_oname) = '' THEN v_oname := v_branch; END IF;
            EXCEPTION WHEN OTHERS THEN v_oname := v_branch; END;
            DBMS_OUTPUT.PUT_LINE(v_code||'|'||v_oname);
        END IF;
    END LOOP;
END;
/
EXIT;
HTMLBRSQL;
        file_put_contents($br_today_sql, $br_today_content);
        $br_today_out = shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path_t} TNS_ADMIN={$instant_client_path_t} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path_t} -s {$up_t} @$br_today_sql 2>&1");
        @unlink($br_today_sql);
        foreach (preg_split('/\r?\n/', (string)$br_today_out) as $ol) {
            $ol = trim($ol);
            if ($ol === '' || preg_match('/^(ORA-|SP2-)/', $ol)) continue;
            $op = explode('|', $ol, 2);
            $code = trim($op[0]); $oname = isset($op[1]) ? trim($op[1]) : $code;
            // กรองตามสิทธิ์สาขา
            if ($code !== '' && (!function_exists('pos_can_see_branch') || pos_can_see_branch($code))) {
                $html_branches_today[] = $code;
                $html_oname_today[$code] = $oname ?: $code;
            }
        }

        // --- HISTORY branches (จาก POS_SALE_OFFICE) ---
        $br_hist_sql = sys_get_temp_dir() . "/POS_HBRL_HIST_" . uniqid() . ".sql";
        file_put_contents($br_hist_sql,
            "SET ECHO OFF FEEDBACK OFF HEADING OFF PAGESIZE 0 TRIMSPOOL ON LINESIZE 300\n" .
            "ALTER SESSION SET NLS_LANGUAGE = American;\n" .
            "SELECT TRIM(SALE_OFFICE)||'|'||NVL(TRIM(OFFICE_NAME),TRIM(SALE_OFFICE))\n" .
            "FROM POS.POS_SALE_OFFICE\n" .
            "WHERE SALE_OFFICE IS NOT NULL AND TRIM(SALE_OFFICE) IS NOT NULL\n" .
            "ORDER BY SALE_OFFICE;\nEXIT;\n");
        $br_hist_out = shell_exec("env -i LD_LIBRARY_PATH={$instant_client_path_t} TNS_ADMIN={$instant_client_path_t} NLS_LANG=THAI_THAILAND.AL32UTF8 {$sqlplus_path_t} -s {$up_t} @$br_hist_sql 2>&1");
        @unlink($br_hist_sql);
        foreach (preg_split('/\r?\n/', (string)$br_hist_out) as $ol) {
            $ol = trim($ol);
            if ($ol === '' || preg_match('/^(ORA-|SP2-)/', $ol)) continue;
            $op = explode('|', $ol, 2);
            $code = trim($op[0]); $oname = isset($op[1]) ? trim($op[1]) : $code;
            // กรองตามสิทธิ์สาขา
            if ($code !== '' && (!function_exists('pos_can_see_branch') || pos_can_see_branch($code))) {
                $html_branches_history[] = $code;
                $html_oname_history[$code] = $oname ?: $code;
            }
        }
    }
}
// ชุด active ตาม mode ปัจจุบัน
$html_branches = ($mode === 'history') ? $html_branches_history : $html_branches_today;
$html_oname_map = ($mode === 'history') ? $html_oname_history : $html_oname_today;
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>รายการสินค้า<?= $mode === 'history' ? ' (ย้อนหลัง)' : '' ?></title>
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
input[type=number], select {
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
.rank-cell {
    font-weight: bold;
    text-align: center;
    font-size: 18px;
}
.rank-badge {
    display: inline-block;
    width: 28px;
    height: 28px;
    line-height: 28px;
    border-radius: 50%;
    font-weight: bold;
    font-size: 14px;
    text-align: center;
    margin-right: 8px;
    box-shadow: 0 0 15px rgba(255,255,255,0.5);
    animation: pulse 2s infinite;
}
.rank-1 .rank-badge {
    background: linear-gradient(135deg, #ffd700, #ffb800);
    color: #8B4513;
    text-shadow: 0 0 8px rgba(255,215,0,0.8);
}
.rank-2 .rank-badge {
    background: linear-gradient(135deg, #c0c0c0, #a8a8a8);
    color: #4a4a4a;
    text-shadow: 0 0 8px rgba(192,192,192,0.8);
}
.rank-3 .rank-badge {
    background: linear-gradient(135deg, #cd7f32, #b87333);
    color: #fff;
    text-shadow: 0 0 8px rgba(205,127,50,0.8);
}
@keyframes pulse {
    0% { box-shadow: 0 0 15px rgba(255,255,255,0.5); }
    50% { box-shadow: 0 0 25px rgba(255,255,255,0.8); }
    100% { box-shadow: 0 0 15px rgba(255,255,255,0.5); }
}
.percentage {
    font-size: 12px;
    color: #ffcc00;
    margin-left: 8px;
    font-weight: normal;
}
.summary-row {
    background: linear-gradient(135deg, rgba(0,255,255,0.25) 0%, rgba(0,188,212,0.25) 100%) !important;
    font-weight: bold;
    color: #0ff !important;
    font-size: 16px;
}
.summary-row td {
    border-top: 3px solid #0ff !important;
}
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
.ui-datepicker {
    z-index: 9999 !important;
}
.loading {
    text-align: center;
    padding: 50px;
    font-size: 18px;
    color: #0ff;
}
.loading i {
    font-size: 48px;
    animation: spin 1s linear infinite;
}
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    table {
        font-size: 12px;
    }
    th, td {
        padding: 10px;
    }
}
.machine-delay-10 { background:#444400; color:#ffff66; font-weight:bold; }
.machine-delay-30 { background:#663300; color:#ffcc66; font-weight:bold; }
.machine-delay-60 { background:#550000; color:#ff6666; font-weight:bold; }
.item-row td { background:#222; color:#0ff; }
.member-badge { background:#ff6b35; color:#fff; padding:2px 8px; border-radius:4px; font-size:12px; margin-left:8px; font-weight:bold; }
tr.branch { background-color:#222; }
tr.branch-new { background-color:#222; color:#0f0; font-weight:bold; }
tr.machine { background-color:#111; }
tr.machine-zero { background-color:#550000; color:#ff6666; font-weight:bold; }
tr.no-data { background-color:#330000; color:#ff8888; font-weight:bold; text-align:center; }
.sort-label { color: #ffcc00; font-size: 14px; margin-left: 10px; }
.mode-history-active { box-shadow: 0 0 20px rgba(255,152,0,0.5); }
.year-col-current { background: rgba(0,255,255,0.08) !important; }
.year-col-prev    { background: rgba(255,152,0,0.05) !important; }
.yr-up   { color: #00e676; }
.yr-down { color: #ff5252; }
.yr-same { color: #aaa; }
.bestseller-year-header { background: linear-gradient(135deg,#4a0080,#2d0060) !important; color:#c678dd !important; text-align:center; }
.bestseller-total-header { background: linear-gradient(135deg,#004d4d,#003333) !important; color:#0ff !important; }
/* branch qty tooltip */
.bs-qty-cell { cursor:pointer; }
.bs-branch-tip {
    display:none; position:fixed; z-index:99999;
    background:#2d0000;
    border:1px solid #ff4444;
    border-radius:6px; padding:8px 12px; min-width:180px;
    box-shadow:0 6px 24px rgba(0,0,0,0.9);
    font-size:12px; white-space:nowrap; pointer-events:none;
}
.bs-branch-tip table { border-collapse:collapse; width:100%; }
.bs-branch-tip td { padding:2px 6px; color:#ffaaaa; }
.bs-branch-tip td:last-child { text-align:right; color:#ff4444; font-weight:bold; }
.bs-branch-tip .tip-office { color:#ff6b6b; }
/* Bestseller placeholder year columns */
#pivot-table thead th.bs-placeholder-yr { min-width:140px; background:linear-gradient(135deg,#3a0060,#210040) !important; color:#c678dd; text-align:center; }
#pivot-table .bs-yr-num { font-size:26px; font-weight:bold; color:#c678dd; text-shadow:0 0 12px rgba(198,120,221,0.7); line-height:1.1; }
#pivot-table .bs-yr-sub { font-size:11px; color:#aaa; margin-top:4px; font-weight:normal; }
</style>
</head>
<body>
<?php pos_expiry_banner(); ?>
<?php $MENU_ACTIVE = 'items'; require_once 'POS_MENU.php'; ?>
<?php $pos_topright_show_online = false; require_once __DIR__ . '/POS_TOPRIGHT.php'; ?>
<h1><i class="fas fa-<?= $mode === 'history' ? 'history' : 'book' ?>"></i> รายการสินค้า<?= $mode === 'history' ? ' (ย้อนหลัง)' : '' ?> (Top <?= $limit ?>)</h1>
<?php pos_nav_buttons($pos_priority, $MENU_ACTIVE); ?>

</div>
<div class="filter-section">
    <form method="GET" id="filter-form" style="text-align:center;">
        <input type="hidden" name="search_mode" id="search_mode" value="<?= htmlspecialchars($search_mode) ?>">
        <input type="hidden" name="mode" id="mode-input" value="<?= htmlspecialchars($mode) ?>">

        <!-- แถวที่ 1: วันนี้ / ย้อนหลัง -->
        <div style="margin-bottom:10px;">
            <label style="color:#ffcc00;font-size:14px;margin-right:8px;"><i class="fas fa-history"></i> โหมดเวลา:</label>
            <button type="button" id="btn-mode-today" onclick="setPurchaseMode('today')"
                style="padding:7px 18px;border-radius:6px;border:2px solid #0ff;cursor:pointer;font-weight:bold;font-size:13px;
                       background:<?= $mode==='today'?'#0ff':'transparent' ?>;color:<?= $mode==='today'?'#000':'#0ff' ?>;">
                <i class="fas fa-chart-bar"></i> วันนี้
            </button>
            <button type="button" id="btn-mode-history" onclick="setPurchaseMode('history')"
                style="padding:7px 18px;border-radius:6px;border:2px solid #ff9800;cursor:pointer;font-weight:bold;font-size:13px;margin-left:6px;
                       background:<?= $mode==='history'?'#ff9800':'transparent' ?>;color:<?= $mode==='history'?'#fff':'#ff9800' ?>;">
                <i class="fas fa-history"></i> ย้อนหลัง
            </button>
        </div>

        <!-- แถวที่ 2: ยอดขาย / ค้นหาสินค้า / สินค้าขายดี -->
        <div style="margin-bottom:14px;">
            <label style="color:#ffcc00; font-size:15px; margin-right:12px;"><i class="fas fa-toggle-on"></i> โหมดข้อมูล:</label>
            <button type="button" id="mode-sales-btn" onclick="setSearchMode('sales')"
                style="padding:9px 22px;border-radius:6px;border:2px solid #0ff;cursor:pointer;font-weight:bold;font-size:13px;
                       background:<?= $search_mode==='sales'?'#0ff':'transparent' ?>;
                       color:<?= $search_mode==='sales'?'#000':'#0ff' ?>;">
                <i class="fas fa-chart-bar"></i> ยอดขาย
            </button>
            <button type="button" id="mode-product-btn" onclick="setSearchMode('product')"
                style="padding:9px 22px;border-radius:6px;border:2px solid #ff6b35;cursor:pointer;font-weight:bold;font-size:13px;margin-left:8px;
                       background:<?= $search_mode==='product'?'#ff6b35':'transparent' ?>;
                       color:<?= $search_mode==='product'?'#fff':'#ff6b35' ?>;">
                <i class="fas fa-boxes"></i> ค้นหาสินค้า
            </button>
            <button type="button" id="mode-bestseller-btn" onclick="setSearchMode('bestseller')"
                style="padding:9px 22px;border-radius:6px;border:2px solid #c678dd;cursor:pointer;font-weight:bold;font-size:13px;margin-left:8px;
                       background:<?= $search_mode==='bestseller'?'#c678dd':'transparent' ?>;
                       color:<?= $search_mode==='bestseller'?'#fff':'#c678dd' ?>;">
                <i class="fas fa-trophy"></i> สินค้าขายดี
            </button>
        </div>

        <!-- แถวที่ 2: field ตาม mode + ปุ่ม -->
        <div style="display:flex; flex-wrap:wrap; justify-content:center; align-items:center; gap:8px;">
            <div class="form-group" id="search-group" style="margin:0;">
                <label>ค้นหา:</label>
                <input type="text" name="search" value="<?=htmlspecialchars($search)?>" placeholder="บาร์โค้ด/ชื่อสินค้า" style="width:200px;">
            </div>
            <div class="form-group" id="date-group-start" style="margin:0;">
                <label id="date-label-start"><?= $mode==='today' ? 'วันที่:' : 'เริ่มต้น:' ?></label>
                <input type="text" name="start" id="start_date" value="<?=htmlspecialchars($start_date)?>" required readonly
                       style="<?= $mode==='today' ? 'cursor:default;background:rgba(0,255,255,0.04);color:#aaa;border-color:#333;' : 'cursor:pointer;' ?>">
                <i class="fas fa-calendar-alt date-icon" id="start-icon" style="<?= $mode==='today' ? 'display:none;' : '' ?>"></i>
            </div>
            <div class="form-group" id="date-group-end" style="margin:0;<?= $mode==='today' ? 'display:none;' : '' ?>">
                <label>สิ้นสุด:</label>
                <input type="text" name="end" id="end_date" value="<?=htmlspecialchars($end_date)?>" required readonly style="cursor:pointer;">
                <i class="fas fa-calendar-alt date-icon" id="end-icon"></i>
            </div>
            <input type="hidden" id="end_date_hidden" name="" value="<?=htmlspecialchars($end_date)?>"><?php // ใช้เฉพาะ today mode ?>
            <div class="form-group" id="branch-group" style="margin:0;">
                <label>สาขา:</label>
                <select name="branch" id="branch">
                    <option value="" <?= $branch_filter===''?'selected':'' ?>>— ทุกสาขา —</option>
                    <?php foreach ($html_branches as $code): ?>
                        <option value="<?= htmlspecialchars($code) ?>" <?= $branch_filter===$code?'selected':'' ?>>
                            <?= htmlspecialchars(isset($html_oname_map[$code]) && $html_oname_map[$code] !== $code ? $html_oname_map[$code].' ('.$code.')' : $code) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="limit-group" style="margin:0;">
                <label>แสดง:</label>
                <input type="number" name="limit" value="<?= $limit ?>" min="1" max="1000000" style="width:70px;">
            </div>
            <div class="form-group" id="sort-group" style="margin:0;">
                <label>เรียงตาม:</label>
                <select name="sort">
                    <option value="amount" <?= $sort_by==='amount'?'selected':'' ?>>ยอดขาย</option>
                    <option value="qty" <?= $sort_by==='qty'?'selected':'' ?>>จำนวน</option>
                </select>
            </div>
            <div class="form-group" id="itemgroup-group" style="margin:0;display:none;">
                <label>กลุ่มสินค้า:</label>
                <select name="item_group" id="item-group-select" style="min-width:160px;cursor:pointer;">
                    <option value="">— ทุกกลุ่ม —</option>
                </select>
            </div>
            <div style="display:inline-flex; gap:6px; align-items:center;">
                <button type="button" onclick="updateData()"><i class="fas fa-search"></i> ค้นหา</button>
                <button type="button" id="refresh-btn" style="display:none;"><i class="fas fa-sync"></i> รีเฟรช</button>
            </div>
        </div>

    </form>
</div>
<?php if (!empty($errors)): ?>
<div class="error">
<h2>วันที่ไม่ถูกต้อง</h2>
<?= implode('<br>', $errors) ?></div>
<?php endif; ?>
<!-- Stat: สินค้าทั้งหมด + ล่าสุด -->
<div style="display:flex; justify-content:center; gap:20px; flex-wrap:wrap; margin:20px 0;">
    <div id="card-all-product" style="background:linear-gradient(135deg,rgba(0,188,212,0.2),rgba(0,150,167,0.2)); border:2px solid #0ff; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(0,255,255,0.2);">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-boxes" style="margin-right:6px;"></i>สินค้าทั้งหมด (ระบบ)</div>
        <div style="font-size:42px; font-weight:bold; color:#00ffff; text-shadow:0 0 16px rgba(0,255,255,0.6);" id="all-product-count">-</div>
        <div style="color:#aaa; font-size:12px; margin-top:4px;">รายการ</div>
    </div>
    <div id="card-filtered-product" style="background:linear-gradient(135deg,rgba(255,107,53,0.2),rgba(200,80,30,0.2)); border:2px solid #ff6b35; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(255,107,53,0.2);">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-filter" style="margin-right:6px;"></i>สินค้า (ช่วงวันที่/ค้นหา)</div>
        <div style="font-size:42px; font-weight:bold; color:#ff6b35; text-shadow:0 0 16px rgba(255,107,53,0.5);" id="filtered-product-count">0</div>
        <div style="color:#aaa; font-size:12px; margin-top:4px;">รายการ <span id="filtered-product-count-from" style="color:#777;"></span></div>
        <div style="color:#555; font-size:11px; margin-top:3px;">ทั้งหมด: <span id="all-product-count-filter" style="color:#c96030;">-</span> รายการ</div>
    </div>
    <div id="card-total-qty" style="background:linear-gradient(135deg,rgba(76,175,80,0.2),rgba(56,142,60,0.2)); border:2px solid #4caf50; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(76,175,80,0.2);">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-boxes" style="margin-right:6px;"></i>จำนวนสินค้ารวม (ชิ้น)</div>
        <div style="font-size:42px; font-weight:bold; color:#4caf50; text-shadow:0 0 16px rgba(76,175,80,0.6);" id="total-qty-sum">0</div>
        <div style="color:#aaa; font-size:12px; margin-top:4px;">ชิ้น</div>
        <div style="color:#555; font-size:11px; margin-top:3px;">ทั้งหมด: <span id="all-qty-sum" style="color:#2a6f30;">-</span> ชิ้น</div>
    </div>
    <div id="card-last-product" style="background:linear-gradient(135deg,rgba(255,215,0,0.15),rgba(200,160,0,0.15)); border:2px solid #ffd700; border-radius:14px; padding:18px 36px; text-align:center; box-shadow:0 0 24px rgba(255,215,0,0.2); min-width:220px;">
        <div style="color:#aaa; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;"><i class="fas fa-clock" style="margin-right:6px;"></i>สินค้าล่าสุด</div>
        <div style="font-size:15px; font-weight:bold; color:#ffd700; text-shadow:0 0 12px rgba(255,215,0,0.6);" id="last-product-barcode">-</div>
        <div style="font-size:12px; color:#ffee88; margin-top:4px; max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" id="last-product-name">-</div>
        <div style="font-size:12px; color:#aaa; margin-top:4px;" id="last-product-date">-</div>
    </div>
</div>
<div style="text-align:right;color:#aaa;font-size:12px;margin-bottom:6px;">
    รีเฟรชล่าสุด: <span id="items-refresh-time">-</span>
</div>
<canvas id="itemChart" height="80"></canvas>
<div style="text-align:center; margin-top:20px; font-size:28px; font-weight:bold; color:#0ff;">
    แสดง: <span id="total-items">0</span> รายการ <span id="total-items-from" style="font-size:20px;color:#aaa;font-weight:normal;"></span>
    <span class="sort-label">สาขา: <span id="branch-label"><?= $branch_filter === '' ? 'ทั้งหมด' : $branch_filter ?></span></span>
    <span class="sort-label">เรียงตาม: <span id="sort-label">-</span></span>
</div>
<div style="overflow-x:auto;">
    <table id="pivot-table">
        <thead id="pivot-head"></thead>
        <tbody id="pivot-body"></tbody>
    </table>
</div>
<script>
// branch data สำหรับ 2 โหมด — สร้างจาก PHP ตอน page load
const BRANCHES_TODAY   = <?= json_encode(array_map(fn($c) => ['code'=>$c,'label'=>(isset($html_oname_today[$c])&&$html_oname_today[$c]!==$c?$html_oname_today[$c].' ('.$c.')':$c)], $html_branches_today), JSON_UNESCAPED_UNICODE) ?>;
const BRANCHES_HISTORY = <?= json_encode(array_map(fn($c) => ['code'=>$c,'label'=>(isset($html_oname_history[$c])&&$html_oname_history[$c]!==$c?$html_oname_history[$c].' ('.$c.')':$c)], $html_branches_history), JSON_UNESCAPED_UNICODE) ?>;

function rebuildBranchDropdown(mode) {
    const sel = document.getElementById('branch');
    const prev = sel.value;
    const list = mode === 'history' ? BRANCHES_HISTORY : BRANCHES_TODAY;
    sel.innerHTML = '<option value="">— ทุกสาขา —</option>';
    list.forEach(b => {
        const opt = document.createElement('option');
        opt.value = b.code;
        opt.textContent = b.label;
        if (b.code === prev) opt.selected = true;
        sel.appendChild(opt);
    });
    // ถ้าค่าเดิมไม่มีใน list ใหม่ → reset เป็นทุกสาขา
    if (sel.value !== prev) sel.value = '';
}

let chart = null;
let all_branches = [];
window.currentData = null; // เพิ่มตัวแปรเก็บข้อมูล

function clearDisplay(placeholderHtml) {
    document.getElementById('pivot-head').innerHTML = '';
    document.getElementById('pivot-body').innerHTML = placeholderHtml;
    document.getElementById('itemChart').style.display = 'none';
    document.getElementById('total-items').innerText = '0';
    document.getElementById('total-items-from').innerText = '';
    document.getElementById('filtered-product-count').innerText = '0';
    document.getElementById('filtered-product-count-from').innerText = '';
    document.getElementById('total-qty-sum').innerText = '0';
}

function setPurchaseMode(mode) {
    document.getElementById('mode-input').value = mode;
    const isHistory    = mode === 'history';
    const isToday      = mode === 'today';
    const tb = document.getElementById('btn-mode-today');
    const hb = document.getElementById('btn-mode-history');
    tb.style.background = isToday   ? '#0ff'    : 'transparent';
    tb.style.color      = isToday   ? '#000'    : '#0ff';
    hb.style.background = isHistory ? '#ff9800' : 'transparent';
    hb.style.color      = isHistory ? '#fff'    : '#ff9800';
    // สลับตัวเลือกสาขา
    rebuildBranchDropdown(isToday ? 'today' : 'history');
    // ปรับวันที่ default
    const now = new Date();
    const todayStr = ("0"+now.getDate()).slice(-2)+'/'+("0"+(now.getMonth()+1)).slice(-2)+'/'+now.getFullYear();
    const yest = new Date(now); yest.setDate(yest.getDate()-1);
    const yesterdayStr = ("0"+yest.getDate()).slice(-2)+'/'+("0"+(yest.getMonth()+1)).slice(-2)+'/'+yest.getFullYear();
    const targetDate = isToday ? todayStr : yesterdayStr;

    const sdEl = document.getElementById('start_date');
    const edEl = document.getElementById('end_date');
    const siEl = document.getElementById('start-icon');
    const lbEl = document.getElementById('date-label-start');
    const dgEnd = document.getElementById('date-group-end');

    if (isToday) {
        // ── today: วันเดียว ล็อคไม่ให้เปลี่ยน ──
        if (sdEl) { sdEl.value = todayStr; sdEl.style.cursor = 'default'; sdEl.style.background = 'rgba(0,255,255,0.04)'; sdEl.style.color = '#aaa'; sdEl.style.borderColor = '#333'; }
        if (edEl) { edEl.value = todayStr; }
        if (siEl) siEl.style.display = 'none';
        if (lbEl) lbEl.textContent = 'วันที่:';
        if (dgEnd) dgEnd.style.display = 'none';
    } else {
        // ── history: 2 ช่อง เปิดใช้งานได้ ──
        if (sdEl) { sdEl.value = yesterdayStr; sdEl.style.cursor = 'pointer'; sdEl.style.background = ''; sdEl.style.color = ''; sdEl.style.borderColor = ''; }
        if (edEl) { edEl.value = yesterdayStr; }
        if (siEl) siEl.style.display = '';
        if (lbEl) lbEl.textContent = 'เริ่มต้น:';
        // date-group-end จะถูกควบคุมโดย setSearchMode
        if (typeof $ !== 'undefined' && $.datepicker) {
            $('#start_date,#end_date').datepicker('option','minDate', new Date(2000,0,1));
            $('#start_date,#end_date').datepicker('option','maxDate','today');
            $('#start_date').datepicker('setDate', yesterdayStr);
            $('#end_date').datepicker('setDate', yesterdayStr);
        }
    }
    // timer control: ปิด auto-refresh เสมอก่อน จากนั้นเปิดเฉพาะ today + sales
    _stopAutoRefresh();
    const curSMode = document.getElementById('search_mode').value;
    if (curSMode === 'bestseller') {
        // สลับ today/history ขณะอยู่ใน bestseller: rebuild placeholder header ปี 1..5
        setSearchMode('bestseller');
    } else if (isHistory) {
        clearDisplay(`<tr><td colspan="20" style="text-align:center;padding:60px 20px;color:#ff9800;font-size:20px;">
            <i class="fas fa-history" style="font-size:40px;margin-bottom:15px;display:block;opacity:0.6;"></i>
            กรุณาเลือกช่วงวันที่แล้วกด <strong>ค้นหา</strong> เพื่อโหลดข้อมูล</td></tr>`);
        // history mode: ไม่ start timer
    } else {
        clearDisplay(`<tr><td colspan="20" style="text-align:center;padding:60px 20px;color:#0ff;font-size:20px;">
            <i class="fas fa-spinner fa-spin" style="font-size:40px;margin-bottom:15px;display:block;"></i>
            กำลังโหลดข้อมูล...</td></tr>`);
        updateData();
        // today + sales: start auto-refresh
        if (curSMode === 'sales') _startAutoRefresh();
    }
}

function setSearchMode(mode) {
    document.getElementById('search_mode').value = mode;
    const isSales      = mode === 'sales';
    const isProduct    = mode === 'product';
    const isBestseller = mode === 'bestseller';
    const sb = document.getElementById('mode-sales-btn');
    const pb = document.getElementById('mode-product-btn');
    const bb = document.getElementById('mode-bestseller-btn');
    sb.style.background = isSales      ? '#0ff'     : 'transparent';
    sb.style.color       = isSales      ? '#000'     : '#0ff';
    pb.style.background  = isProduct    ? '#ff6b35'  : 'transparent';
    pb.style.color       = isProduct    ? '#fff'     : '#ff6b35';
    if (bb) {
        bb.style.background = isBestseller ? '#c678dd' : 'transparent';
        bb.style.color      = isBestseller ? '#fff'    : '#c678dd';
    }
    // ซ่อน/แสดง filter และ stat cards ตาม mode:
    //   sales      → ค้นหา, วันที่, สาขา, แสดง, เรียงตาม / ซ่อน กลุ่มสินค้า
    //   product    → ค้นหา เท่านั้น / ซ่อนทุก filter อื่น
    //   bestseller → วันที่, สาขา, กลุ่มสินค้า / ซ่อน ค้นหา, แสดง, เรียงตาม
    const _show = (id, show) => { const e = document.getElementById(id); if (e) e.style.display = show ? '' : 'none'; };
    _show('search-group',          true);         // ทุก mode: ค้นหา ✅✅✅
    _show('date-group-start',      !isProduct);   // sales✅ product❌ bestseller✅
    // date-group-end: แสดงเฉพาะ history mode เท่านั้น (today ใช้วันเดียว)
    const _isPurchaseToday = document.getElementById('mode-input').value !== 'history';
    _show('date-group-end',        !isProduct && !_isPurchaseToday);
    _show('branch-group',          !isProduct);   // sales✅ product❌ bestseller✅
    _show('limit-group',           true);         // ทุก mode: แสดง(limit) ✅✅✅
    _show('sort-group',            !isProduct);   // sales✅ product❌ bestseller✅
    _show('itemgroup-group',       !isProduct);   // sales✅ product❌ bestseller✅
    // stat cards
    _show('card-all-product',      true);
    _show('card-filtered-product', true);
    _show('card-total-qty',        true);
    _show('card-last-product',     true);
    if (!isProduct) loadItemGroups(); // โหลด group options เมื่อต้องการ
    // timer control: auto-refresh เฉพาะ today + sales เท่านั้น
    const curPMode = document.getElementById('mode-input').value;
    if (isSales && curPMode !== 'history') {
        _startAutoRefresh();
    } else {
        _stopAutoRefresh();
    }
    // เคลียร์หน้าจอทุกครั้ง
    if (isBestseller) {
        // แสดง placeholder header ปี 1..5 แบบ POS_MEMBERS.php
        const now = new Date();
        const baseYearBE = now.getFullYear() + 543; // แปลงเป็น พ.ศ.
        let phHead = `<tr>
            <th rowspan="2" style="min-width:50px;background:#003333;">ลำดับ</th>
            <th rowspan="2" style="min-width:100px;background:#003333;">บาร์โค้ด</th>
            <th rowspan="2" style="min-width:180px;background:#003333;">ชื่อสินค้า</th>
            <th rowspan="2" style="min-width:140px;background:#003333;color:#c678dd;vertical-align:middle;padding:5px 4px;">กลุ่มสินค้า<br><span style="font-size:10px;font-weight:normal;opacity:0.7;">(เลือกหลังค้นหา)</span></th>`;
        for (let i = 0; i < 5; i++) {
            const yr = baseYearBE - i;
            const isFirst = i === 0;
            phHead += `<th colspan="2" class="bs-placeholder-yr" style="${isFirst ? 'border-bottom:3px solid #c678dd;' : ''}">
                <div class="bs-yr-num" style="${isFirst ? 'color:#e090ff;' : ''}">${yr}</div>
                <div class="bs-yr-sub">ปี ${i+1}</div>
            </th>`;
        }
        phHead += `<th colspan="2" style="background:linear-gradient(135deg,#004d4d,#003333);color:#0ff;min-width:120px;text-align:center;">รวมทั้งหมด</th></tr>
        <tr>`;
        for (let i = 0; i < 5; i++) {
            phHead += `<th class="bs-placeholder-yr" style="min-width:60px;">จำนวน</th>
                       <th class="bs-placeholder-yr" style="min-width:70px;">ยอด(บาท)</th>`;
        }
        phHead += `<th style="background:linear-gradient(135deg,#004d4d,#003333);color:#0ff;">จำนวน</th>
                   <th style="background:linear-gradient(135deg,#004d4d,#003333);color:#0ff;">ยอด(บาท)</th></tr>`;
        document.getElementById('pivot-head').innerHTML = phHead;
        document.getElementById('pivot-body').innerHTML = `<tr><td colspan="16" style="text-align:center;padding:60px 20px;color:#c678dd;font-size:20px;">
            <i class="fas fa-trophy" style="font-size:40px;margin-bottom:15px;display:block;opacity:0.7;"></i>
            กรุณากด <strong>ค้นหา</strong> เพื่อโหลดข้อมูลสินค้าขายดี เปรียบเทียบ 5 ปี</td></tr>`;
        document.getElementById('itemChart').style.display = 'none';
    } else {
        clearDisplay(`<tr><td colspan="20" style="text-align:center;padding:60px 20px;color:#0ff;font-size:20px;">
        <i class="fas fa-search" style="font-size:40px;margin-bottom:15px;display:block;opacity:0.5;"></i>
        กรุณากดค้นหาเพื่อโหลดข้อมูล</td></tr>`);
    }
}
// setSearchMode / setPurchaseMode ถูกเรียกใน $(function(){}) หลัง datepicker init แล้ว

function renderProductTable(d) {
    document.getElementById('itemChart').style.display = 'none';
    document.getElementById('pivot-head').innerHTML = `<tr>
        <th style="width:50px;text-align:center;">ลำดับ</th>
        <th>บาร์โค้ด</th>
        <th>ชื่อสินค้า</th>
        <th>ประเภท</th>
        <th>หมวดหมู่</th>
        <th style="text-align:right;">ราคาขาย</th>
        <th style="text-align:right;">ราคาเต็ม</th>
        <th style="text-align:center;">VAT</th>
        <th>ผู้แต่ง</th>
        <th>สำนักพิมพ์</th>
        <th style="text-align:center;">ภาษา</th>
        <th style="text-align:center;">สถานะ</th>
        <th>แก้ไขล่าสุด</th>
    </tr>`;
    const tbody = document.getElementById('pivot-body');
    tbody.innerHTML = '';
    if (!d.products || d.products.length === 0) {
        tbody.innerHTML = `<tr><td colspan="13" style="text-align:center;padding:60px;color:#ff6b6b;font-size:20px;">
            <i class="fas fa-ban" style="font-size:36px;display:block;margin-bottom:12px;"></i>
            ไม่พบสินค้าที่ตรงกับคำค้นหา</td></tr>`;
        document.getElementById('total-items').innerText = '0';
        document.getElementById('total-items-from').innerText = '';
        document.getElementById('filtered-product-count').innerText = '0';
        document.getElementById('filtered-product-count-from').innerText = '';
        if (document.getElementById('total-qty-sum')) document.getElementById('total-qty-sum').innerText = '0';
        return;
    }
    const frag = document.createDocumentFragment();
    d.products.forEach((p, i) => {
        const tr = document.createElement('tr');
        const fmt = v => v > 0 ? Number(v).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}) : '-';
        const statusColor = p.status === '1' ? '#0f0' : p.status === '0' ? '#f66' : '#aaa';
        const statusLabel = p.status === '1' ? 'Active' : p.status === '0' ? 'Inactive' : p.status;
        tr.innerHTML = `
            <td style="text-align:center;color:#aaa;">${i+1}</td>
            <td style="font-family:monospace;color:#0ff;">${p.barcode}</td>
            <td style="color:#eee;">${p.name !== '-' ? p.name : ''}</td>
            <td style="color:#ffcc00;text-align:center;">${p.type_id !== '-' ? p.type_id : ''}</td>
            <td style="color:#aef;text-align:center;">${p.category !== '-' ? p.category : ''}</td>
            <td style="text-align:right;color:#ffd700;">${fmt(p.unit_price)}</td>
            <td style="text-align:right;color:#ffb300;">${fmt(p.full_price)}</td>
            <td style="text-align:center;color:#aaa;">${p.vat !== '-' ? p.vat : ''}</td>
            <td style="color:#ccc;font-size:12px;">${p.author !== '-' ? p.author : ''}</td>
            <td style="color:#ccc;font-size:12px;">${p.publisher !== '-' ? p.publisher : ''}</td>
            <td style="text-align:center;color:#aaa;">${p.language !== '-' ? p.language : ''}</td>
            <td style="text-align:center;color:${statusColor};font-weight:bold;">${statusLabel}</td>
            <td style="color:#aaa;font-size:12px;">${p.modified || ''}</td>`;
        frag.appendChild(tr);
    });
    tbody.appendChild(frag);
    const shown = d.products.length;
    const total = d.product_total || shown;
    document.getElementById('total-items').innerText = shown.toLocaleString();
    document.getElementById('total-items-from').innerText = total > shown ? 'จาก ' + total.toLocaleString() : '';
    // อัปเดต card "สินค้า (ช่วงวันที่/ค้นหา)"
    document.getElementById('filtered-product-count').innerText = shown.toLocaleString();
    document.getElementById('filtered-product-count-from').innerText = total > shown ? 'จาก ' + total.toLocaleString() : '';
    if (document.getElementById('total-qty-sum')) document.getElementById('total-qty-sum').innerText = '-';
}

function updateData(isAutoRefresh) {
    const params = new URLSearchParams();
    params.set('ajax',        '1');
    params.set('search_mode', document.getElementById('search_mode').value);
    params.set('mode',        document.getElementById('mode-input').value);
    params.set('search',      document.querySelector('[name="search"]').value.trim());
    params.set('start',       document.getElementById('start_date').value.trim());
    params.set('end',         document.getElementById('end_date').value.trim());
    params.set('branch',      document.getElementById('branch').value);
    params.set('limit',       document.querySelector('[name="limit"]').value);
    params.set('sort',        document.querySelector('[name="sort"]').value);
    const curMode  = params.get('mode');
    const curSMode = params.get('search_mode');

    // bestseller mode
    if (curSMode === 'bestseller') {
        const igSel = document.getElementById('item-group-select');
        if (igSel) params.set('item_group', igSel.value);
        if (!isAutoRefresh) {
            document.getElementById('pivot-body').innerHTML =
                `<tr><td colspan="15" style="text-align:center;padding:60px 20px;color:#c678dd;font-size:20px;">
                <i class="fas fa-spinner fa-spin" style="font-size:40px;margin-bottom:15px;display:block;"></i>
                กำลังโหลดข้อมูลสินค้าขายดี...</td></tr>`;
            document.getElementById('itemChart').style.display = 'none';
        }
        fetch('?' + params.toString())
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const ct = r.headers.get('content-type') || '';
                if (!ct.includes('json')) return r.text().then(t => { throw new Error('Server error:\n' + t.substring(0, 300)); });
                return r.json();
            })
            .then(d => {
                window.currentData = d;
                if (d.error || d.ok === false) {
                    document.getElementById('pivot-body').innerHTML =
                        `<tr><td colspan="20" style="color:#ff6b6b;padding:20px;text-align:center;">${d.error || 'เกิดข้อผิดพลาด'}</td></tr>`;
                    return;
                }
                renderBestsellerTable(d);
            })
            .catch(err => {
                document.getElementById('pivot-body').innerHTML =
                    `<tr><td colspan="20" style="color:#ff6b6b;padding:20px;text-align:center;white-space:pre-wrap;">ไม่สามารถเชื่อมต่อได้: ${err.message}</td></tr>`;
            });
        return;
    }

    if (!isAutoRefresh) {
        if (curMode === 'history' && curSMode === 'sales') {
            document.getElementById('pivot-body').innerHTML =
                `<tr><td colspan="20" style="text-align:center;padding:60px 20px;color:#ff9800;font-size:20px;">
                <i class="fas fa-spinner fa-spin" style="font-size:40px;margin-bottom:15px;display:block;"></i>
                กำลังโหลดข้อมูลย้อนหลัง...</td></tr>`;
            document.getElementById('itemChart').style.display = 'none';
            document.getElementById('pivot-head').innerHTML = '';
        } else {
            document.getElementById('pivot-body').innerHTML = `<tr><td colspan="20" style="text-align:center;padding:60px 20px;color:#0ff;font-size:20px;">
            <i class="fas fa-spinner fa-spin" style="font-size:40px;margin-bottom:15px;display:block;"></i>
            กำลังโหลดข้อมูล...</td></tr>`;
        }
    }
    fetch('?' + params.toString())
        .then(r => r.json())
        .then(d => {
            window.currentData = d;
            if (d.error) {
                document.querySelector('#pivot-body').innerHTML =
                    `<tr><td colspan="100" style="color:#ff6b6b; padding:20px; text-align:center;">${d.error}</td></tr>`;
                document.getElementById('pivot-head').innerHTML = '';
                document.getElementById('refresh-time').innerText = 'ERROR';
                const _irt1 = document.getElementById('items-refresh-time'); if(_irt1) _irt1.innerText = 'ERROR';
                return;
            }
            document.getElementById('refresh-time').innerText = d.refresh_time;
            const _irt2 = document.getElementById('items-refresh-time'); if(_irt2) _irt2.innerText = d.refresh_time;
            if (d.search_mode === 'product') {
                console.log('[Product] total:', d.product_total, '| shown:', d.products ? d.products.length : 0, '| cond:', d.debug_cond);
                if (d.debug_raw) console.log('[Product RAW]', d.debug_raw);
                renderProductTable(d);
                return;
            }
            // sales (today + history) ใช้ render เดียวกัน
            if (d.data_mode === 'history') {
                console.log('[History] total_items:', d.total_items, '| branches:', d.all_branches);
            }
            // Debug: ดู oracle errors ใน Console (F12)
            if (d.oracle_errors && d.oracle_errors.length > 0) {
                console.warn('[Oracle Errors]', d.oracle_errors);
            }
            if (d.raw_output) console.log('[RAW Oracle Output]\n', d.raw_output);
          
            document.getElementById('refresh-time').innerText = d.refresh_time;
            const _irt3 = document.getElementById('items-refresh-time'); if(_irt3) _irt3.innerText = d.refresh_time;
            const modeLabel = d.data_mode === 'history' ? ' [ย้อนหลัง]' : '';
            document.getElementById('date-range').innerText = d.start_date + ' - ' + d.end_date + modeLabel;
            const shownCount = d.shown_items || d.pivot.length;
            const totalCount = d.total_items;
            document.getElementById('total-items').innerText = shownCount.toLocaleString();
            document.getElementById('total-items-from').innerText = totalCount > shownCount ? 'จาก ' + totalCount.toLocaleString() : '';
            const officeNameMap = d.office_name_map || {};
            const sortText = { amount: 'ยอดขาย', qty: 'จำนวน'}[d.sort_by] || 'ยอดเงิน';
            document.getElementById('sort-label').innerText = sortText;

            // อัปเดต card "สินค้า (ช่วงวันที่/ค้นหา)" ในโหมดยอดขาย
            document.getElementById('filtered-product-count').innerText = shownCount.toLocaleString();
            document.getElementById('filtered-product-count-from').innerText = totalCount > shownCount ? 'จาก ' + totalCount.toLocaleString() : '';
            if (document.getElementById('all-product-count-filter'))
                document.getElementById('all-product-count-filter').innerText = totalCount.toLocaleString();
            if (document.getElementById('total-qty-sum'))
                document.getElementById('total-qty-sum').innerText = Number(d.total_qty_all || d.grand_total_qty || 0).toLocaleString();
            if (document.getElementById('all-qty-sum'))
                document.getElementById('all-qty-sum').innerText = Number(d.all_qty_total || d.total_qty_all || d.grand_total_qty || 0).toLocaleString();

            // --- ไม่มีข้อมูล: ล้างกราฟ + banner เหมือน POS_HOME.php ---
            if (d.pivot.length === 0) {
                document.getElementById('pivot-head').innerHTML = '';
                document.getElementById('pivot-body').innerHTML = `<tr><td colspan="100" style="text-align:center; padding:80px 20px; background:rgba(139,0,0,0.2); border:2px dashed #ff6b6b; color:#ff6b6b; font-size:32px; font-weight:bold;"><i class="fas fa-ban" style="margin-right:20px; font-size:40px;"></i>ไม่มีข้อมูลในช่วงวันที่ที่ระบุ</td></tr>`;
                document.getElementById('itemChart').style.display = 'none';
                document.getElementById('total-items').innerText = '0';
                document.getElementById('total-items-from').innerText = '';
                document.getElementById('filtered-product-count').innerText = '0';
                document.getElementById('filtered-product-count-from').innerText = '';
                if (chart) { chart.data.labels = []; chart.data.datasets[0].data = []; chart.update(); }
                return;
            }
            document.getElementById('itemChart').style.display = '';

            // --- สร้าง Header (skip ถ้า auto-refresh และ branch list ไม่เปลี่ยน) ---
            const prevBranches = all_branches ? all_branches.join(',') : '';
            all_branches = d.all_branches;
            const newBranches = all_branches.join(',');
            const needRebuildHeader = !isAutoRefresh || prevBranches !== newBranches || !document.getElementById('pivot-head').hasChildNodes();
            if (needRebuildHeader) {
                const thead = document.getElementById('pivot-head');
                thead.innerHTML = '';
                const tr1 = document.createElement('tr');
               
                const thRank = document.createElement('th');
                thRank.rowSpan = 2;
                thRank.textContent = 'ลำดับ';
                thRank.style.minWidth = '60px';
                thRank.style.background = '#003333';
                tr1.appendChild(thRank);
                const thBarcode = document.createElement('th');
                thBarcode.rowSpan = 2;
                thBarcode.textContent = 'บาร์โค้ด';
                thBarcode.style.minWidth = '90px';
                tr1.appendChild(thBarcode);
                const thName = document.createElement('th');
                thName.rowSpan = 2;
                thName.textContent = 'ชื่อสินค้า';
                thName.style.minWidth = '150px';
                tr1.appendChild(thName);
                all_branches.forEach(br => {
                    const th = document.createElement('th');
                    th.colSpan = 2;
                    const oname = officeNameMap[br];
                    th.textContent = (oname && oname !== br) ? `${oname} (${br})` : br;
                    th.style.minWidth = '80px';
                    th.style.background = '#005555';
                    tr1.appendChild(th);
                });
                const thTotal = document.createElement('th');
                thTotal.colSpan = 2;
                thTotal.textContent = 'รวม';
                thTotal.style.minWidth = '100px';
                thTotal.style.background = '#006666';
                tr1.appendChild(thTotal);
                thead.appendChild(tr1);
                const tr2 = document.createElement('tr');
                all_branches.forEach(() => {
                    const thQty = document.createElement('th');
                    thQty.textContent = 'จำนวน';
                    thQty.style.minWidth = '40px';
                    tr2.appendChild(thQty);
                    const thAmt = document.createElement('th');
                    thAmt.textContent = 'ยอด';
                    thAmt.style.minWidth = '40px';
                    tr2.appendChild(thAmt);
                });
                const thTotalQty = document.createElement('th');
                thTotalQty.textContent = 'จำนวน';
                thTotalQty.style.minWidth = '50px';
                thTotalQty.style.background = '#004444';
                tr2.appendChild(thTotalQty);
                const thTotalAmt = document.createElement('th');
                thTotalAmt.textContent = 'ยอด';
                thTotalAmt.style.minWidth = '50px';
                thTotalAmt.style.background = '#004444';
                tr2.appendChild(thTotalAmt);
                thead.appendChild(tr2);
            }

            // --- สร้าง Body ---
            const tbody = document.getElementById('pivot-body');
            tbody.innerHTML = '';
            if (false) { // replaced by early return above
            } else {
                const fragment = document.createDocumentFragment();
                d.pivot.forEach((row, index) => {
                    const tr = document.createElement('tr');
                    if (index < 3) tr.classList.add(`rank-${index + 1}`);
                    const tdRank = document.createElement('td');
                    tdRank.style.textAlign = 'center';
                    tdRank.style.fontWeight = 'bold';
                    tdRank.style.fontSize = '16px';
                    const badge = document.createElement('span');
                    badge.className = 'rank-badge';
                    badge.textContent = index + 1;
                    tdRank.appendChild(badge);
                    tr.appendChild(tdRank);
                    const tdBarcode = document.createElement('td');
                    tdBarcode.textContent = row.barcode;
                    tdBarcode.style.fontFamily = 'monospace';
                    tdBarcode.style.fontSize = '11px';
                    tdBarcode.style.fontWeight = 'bold';
                    tr.appendChild(tdBarcode);
                    const tdName = document.createElement('td');
                    tdName.className = 'product-name';
                    tdName.textContent = row.name;
                    tdName.title = row.name;
                    tr.appendChild(tdName);
                    all_branches.forEach(br => {
                        const qty = row['qty_' + br] || 0;
                        const amt = row['amt_' + br] || 0;
                        const tdQty = document.createElement('td');
                        tdQty.textContent = qty > 0 ? qty.toLocaleString() : '-';
                        tdQty.style.color = qty > 0 ? '#0f0' : '#555';
                        tdQty.style.fontWeight = qty > 0 ? 'bold' : 'normal';
                        tr.appendChild(tdQty);
                        const tdAmt = document.createElement('td');
                        tdAmt.textContent = amt > 0 ? amt.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '-';
                        tdAmt.style.color = amt > 0 ? '#0ff' : '#555';
                        tdAmt.style.fontWeight = amt > 0 ? 'bold' : 'normal';
                        tr.appendChild(tdAmt);
                    });
                    const tdTotalQty = document.createElement('td');
                    tdTotalQty.textContent = row.qty.toLocaleString();
                    tdTotalQty.style.background = '#003333';
                    tdTotalQty.style.color = '#0f0';
                    tdTotalQty.style.fontWeight = 'bold';
                    tr.appendChild(tdTotalQty);
                    const tdTotalAmt = document.createElement('td');
                    tdTotalAmt.textContent = row.amount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                    tdTotalAmt.style.background = '#003333';
                    tdTotalAmt.style.color = '#0ff';
                    tdTotalAmt.style.fontWeight = 'bold';
                    tr.appendChild(tdTotalAmt);
                    fragment.appendChild(tr);
                });
                const branch_totals = {};
                all_branches.forEach(br => {
                    branch_totals[br] = {qty: 0, amt: 0};
                });
                d.pivot.forEach(row => {
                    all_branches.forEach(br => {
                        branch_totals[br].qty += row['qty_' + br] || 0;
                        branch_totals[br].amt += row['amt_' + br] || 0;
                    });
                });
                const trFooter = document.createElement('tr');
                trFooter.className = 'summary-row';
                const tdLabel = document.createElement('td');
                tdLabel.colSpan = 3;
                tdLabel.textContent = 'รวมต่อสาขา';
                tdLabel.style.fontWeight = 'bold';
                tdLabel.style.textAlign = 'center';
                trFooter.appendChild(tdLabel);
                all_branches.forEach(br => {
                    const tdQty = document.createElement('td');
                    tdQty.textContent = branch_totals[br].qty.toLocaleString();
                    tdQty.style.fontWeight = 'bold';
                    tdQty.style.color = '#00ff00';
                    trFooter.appendChild(tdQty);
                    const tdAmt = document.createElement('td');
                    tdAmt.textContent = branch_totals[br].amt.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                    tdAmt.style.fontWeight = 'bold';
                    tdAmt.style.color = '#00ffff';
                    trFooter.appendChild(tdAmt);
                });
                const tdGrandQty = document.createElement('td');
                tdGrandQty.textContent = d.grand_total_qty.toLocaleString();
                tdGrandQty.style.fontWeight = 'bold';
                tdGrandQty.style.background = '#004444';
                tdGrandQty.style.color = '#00ff00';
                trFooter.appendChild(tdGrandQty);
                const tdGrandAmt = document.createElement('td');
                tdGrandAmt.textContent = d.grand_total_amt.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                tdGrandAmt.style.fontWeight = 'bold';
                tdGrandAmt.style.background = '#004444';
                tdGrandAmt.style.color = '#00ffff';
                trFooter.appendChild(tdGrandAmt);
                fragment.appendChild(trFooter);
                tbody.appendChild(fragment);
            }

            // --- กราฟ ---
            const bg = d.chart_data.map((_, i) => {
                if (i === 0) return 'rgba(255, 215, 0, 0.9)';
                if (i === 1) return 'rgba(192, 192, 192, 0.8)';
                if (i === 2) return 'rgba(205, 127, 50, 0.8)';
                return 'rgba(0, 188, 212, 0.7)';
            });
            const bo = bg.map(c => c.replace(/0\.\d+/, '1'));
            const label = d.sort_by === 'qty' ? 'จำนวน' : 'ยอดขาย';

            const bgQty = d.chart_qty.map((_, i) => {
                if (i === 0) return 'rgba(255, 215, 0, 0.5)';
                if (i === 1) return 'rgba(192, 192, 192, 0.5)';
                if (i === 2) return 'rgba(205, 127, 50, 0.5)';
                return 'rgba(0, 188, 212, 0.4)';
            });
            const boQty = bgQty.map(c => c.replace(/0\.\d+/, '1'));

            if (!chart) {
                const ctx = document.getElementById('itemChart').getContext('2d');
                chart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: d.chart_labels,
                        datasets: [
                            {
                                label: 'ยอดขาย (บาท)',
                                data: d.chart_amt,
                                backgroundColor: bg,
                                borderColor: bo,
                                borderWidth: 2
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    title: function(context) {
                                        const idx = context[0].dataIndex;
                                        const item = window.currentData.pivot[idx];
                                        return item ? item.name : window.currentData.chart_labels[idx];
                                    },
                                    label: function(context) {
                                        const idx = context.dataIndex;
                                        const amt = Number(window.currentData.chart_amt[idx]).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                                        const qty = Number(window.currentData.chart_qty[idx]).toLocaleString();
                                        return ['ยอดขาย: ' + amt + ' บาท', 'จำนวน: ' + qty + ' ชิ้น'];
                                    }
                                },
                                backgroundColor: 'rgba(0,0,0,0.95)',
                                titleColor: '#0ff',
                                bodyColor: '#fff',
                                borderColor: '#0ff',
                                borderWidth: 2,
                                cornerRadius: 10,
                                padding: 14,
                                titleFont: { size: 14, weight: 'bold' },
                                bodyFont: { size: 13 }
                            },
                            datalabels: {
                                color: '#fff',
                                anchor: 'end',
                                align: 'top',
                                formatter: (v, ctx) => {
                                    const rank = ctx.dataIndex + 1;
                                    const amt = Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                                    const qty = Number(window.currentData.chart_qty[ctx.dataIndex]).toLocaleString() + ' ชิ้น';
                                    const prefix = rank <= 3 ? `อันดับ ${rank}\n` : '';
                                    return prefix + amt + '\n' + qty;
                                },
                                font: { size: 11, weight: 'bold' }
                            }
                        },
                        scales: {
                            x: { ticks: { color: '#0ff', font: { size: 10 } } },
                            y: {
                                ticks: { color: '#0ff', callback: v => Number(v).toLocaleString(undefined, { minimumFractionDigits: 0 }) }
                            }
                        }
                    },
                    plugins: [ChartDataLabels]
                });
            } else {
                chart.data.labels = d.chart_labels;
                chart.data.datasets[0].data = d.chart_amt;
                chart.data.datasets[0].backgroundColor = bg;
                chart.data.datasets[0].borderColor = bo;
                chart.update();
            }
        })
        .catch(err => {
            console.error(err);
            document.querySelector('#pivot-body').innerHTML =
                `<tr><td colspan="100" style="color:#ff6b6b; text-align:center; padding:20px;">
                    ไม่สามารถเชื่อมต่อได้<br>
                    <small>${err.message}</small>
                </td></tr>`;
        });
}

// ── BESTSELLER RENDER ──────────────────────────────────────────
// หัวตาราง "กลุ่มสินค้า" (bs-th-grp-select) กรอง client-side จาก currentData
// แยกจาก item-group-select ในเงื่อนไขค้นหา (ส่ง SQL) — ทำงานอิสระจากกัน
// Bestseller branch tooltip — singleton fixed element เพื่อหนีปัญหา overflow:hidden ของ table
const _bsTip = (function() {
    const el = document.createElement('div');
    el.className = 'bs-branch-tip';
    el.style.display = 'none';
    document.body.appendChild(el);
    return el;
})();
function _positionBsTip(e) {
    const gap = 12;
    const tw = _bsTip.offsetWidth || 200;
    const th = _bsTip.offsetHeight || 120;
    let x = e.clientX + gap;
    let y = e.clientY + gap;
    if (x + tw > window.innerWidth)  x = e.clientX - tw - gap;
    if (y + th > window.innerHeight) y = e.clientY - th - gap;
    _bsTip.style.left = x + 'px';
    _bsTip.style.top  = y + 'px';
}

function renderBestsellerTable(d) {
    const years    = d.years;
    const allItems = d.items;
    const total    = d.total_items || allItems.length;

    const _sl = document.getElementById('sort-label');
    if (_sl) _sl.innerText = d.sort_by === 'qty' ? 'จำนวน' : 'ยอดขาย';

    if (!allItems || allItems.length === 0) {
        document.getElementById('pivot-head').innerHTML = '';
        document.getElementById('pivot-body').innerHTML =
            `<tr><td colspan="20" style="text-align:center;padding:80px 20px;background:rgba(139,0,0,0.2);
            border:2px dashed #ff6b6b;color:#ff6b6b;font-size:28px;font-weight:bold;">
            <i class="fas fa-ban" style="font-size:36px;display:block;margin-bottom:12px;"></i>
            ไม่พบข้อมูลสินค้าขายดี</td></tr>`;
        document.getElementById('total-items').innerText = '0';
        document.getElementById('total-items-from').innerText = '';
        document.getElementById('filtered-product-count').innerText = '0';
        document.getElementById('filtered-product-count-from').innerText = '';
        return;
    }

    // ── รวบรวม unique itmgrp_desc จาก items ที่ได้มา สร้าง dropdown ในหัวตาราง ──
    // (แยกจาก item-group-select ในเงื่อนไขค้นหาโดยสิ้นเชิง — ไม่ sync กัน)
    const grpMap = {};
    allItems.forEach(row => {
        const g = row.itmgrp_desc || '';
        if (g && g !== '-') grpMap[g] = g;
    });
    const grpKeys = Object.keys(grpMap).sort();

    // รักษา value ที่เลือกไว้ใน bs-th-grp-select ถ้ามีอยู่แล้ว
    let thGrpSel = document.getElementById('bs-th-grp-select');
    const prevGrpVal = thGrpSel ? thGrpSel.value : '';

    // กรอง items ตามกลุ่มที่เลือกใน bs-th-grp-select (client-side เท่านั้น ไม่ fetch ใหม่)
    const activeGrp = (prevGrpVal && grpMap[prevGrpVal]) ? prevGrpVal : '';
    const items     = activeGrp
        ? allItems.filter(r => (r.itmgrp_desc || '') === activeGrp)
        : allItems;
    const shown     = items.length;

    document.getElementById('total-items').innerText = shown.toLocaleString();
    document.getElementById('total-items-from').innerText =
        total > allItems.length ? 'จาก ' + total.toLocaleString()
        : (allItems.length > shown ? 'จาก ' + allItems.length.toLocaleString() : '');
    document.getElementById('filtered-product-count').innerText = shown.toLocaleString();
    document.getElementById('filtered-product-count-from').innerText =
        allItems.length > shown ? 'จาก ' + allItems.length.toLocaleString() : '';
    document.getElementById('itemChart').style.display = 'none';

    // --- Header: 2 แถว ---
    const thead = document.getElementById('pivot-head');
    thead.innerHTML = '';
    const tr1 = document.createElement('tr');

    // คอลัมน์ fixed: ลำดับ, บาร์โค้ด, ชื่อสินค้า
    [['ลำดับ','50px',2],['บาร์โค้ด','100px',2],['ชื่อสินค้า','180px',2]].forEach(([txt,w,rs]) => {
        const th = document.createElement('th');
        th.rowSpan = rs; th.textContent = txt;
        th.style.minWidth = w; th.style.background = '#003333';
        tr1.appendChild(th);
    });

    // คอลัมน์ "กลุ่มสินค้า" — dropdown กรอง client-side จากข้อมูลที่มีอยู่ ไม่ fetch ใหม่
    const thGrp = document.createElement('th');
    thGrp.rowSpan = 2;
    thGrp.style.cssText = 'min-width:140px;background:#003333;vertical-align:middle;padding:5px 4px;';

    const thGrpLabel = document.createElement('div');
    thGrpLabel.textContent = 'กลุ่มสินค้า';
    thGrpLabel.style.cssText = 'color:#c678dd;font-size:11px;font-weight:bold;margin-bottom:3px;letter-spacing:0.5px;';
    thGrp.appendChild(thGrpLabel);

    thGrpSel = document.createElement('select');
    thGrpSel.id = 'bs-th-grp-select';
    thGrpSel.title = 'กรองผลลัพธ์ที่แสดง — ไม่ต้องดึงข้อมูลใหม่ (แยกจากเงื่อนไขค้นหา)';
    thGrpSel.style.cssText = 'width:100%;background:#001a1a;color:#c678dd;border:1px solid #c678dd;' +
        'border-radius:4px;padding:3px 4px;font-size:12px;cursor:pointer;font-weight:bold;';

    const optAll = document.createElement('option');
    optAll.value = ''; optAll.textContent = '— ทุกกลุ่ม —';
    thGrpSel.appendChild(optAll);

    grpKeys.forEach(g => {
        const o = document.createElement('option');
        o.value = g; o.textContent = g;
        if (g === activeGrp) o.selected = true;
        thGrpSel.appendChild(o);
    });

    // เปลี่ยนกลุ่ม → re-render จาก currentData เท่านั้น (ไม่ fetch ใหม่)
    thGrpSel.addEventListener('change', function() {
        renderBestsellerTable(window.currentData);
    });

    thGrp.appendChild(thGrpSel);
    tr1.appendChild(thGrp);

    // คอลัมน์ปี — แสดงปี พ.ศ. + ช่วงวันที่
    years.forEach((yr, yi) => {
        const th = document.createElement('th');
        th.colSpan = 2;
        const yrBE = yr + 543;
        const dateRng = d.date_ranges && d.date_ranges[yr]
            ? d.date_ranges[yr].start + ' – ' + d.date_ranges[yr].end : '';
        th.innerHTML = `<span style="font-size:15px;font-weight:bold;">${yrBE}</span>`
            + (dateRng ? `<br><span style="font-size:10px;font-weight:normal;opacity:0.8;">${dateRng}</span>` : '');
        th.className = 'bestseller-year-header';
        th.style.minWidth = '110px'; th.style.textAlign = 'center';
        if (yi === 0) th.style.borderBottom = '3px solid #c678dd';
        tr1.appendChild(th);
    });

    const thTot = document.createElement('th');
    thTot.colSpan = 2; thTot.textContent = 'รวมทั้งหมด';
    thTot.className = 'bestseller-total-header';
    tr1.appendChild(thTot);
    thead.appendChild(tr1);

    const tr2 = document.createElement('tr');
    years.forEach(() => {
        ['จำนวน','ยอด(บาท)'].forEach(lbl => {
            const th = document.createElement('th');
            th.textContent = lbl; th.style.minWidth = '60px';
            th.className = 'bestseller-year-header';
            tr2.appendChild(th);
        });
    });
    ['จำนวน','ยอด(บาท)'].forEach(lbl => {
        const th = document.createElement('th');
        th.textContent = lbl; th.className = 'bestseller-total-header';
        tr2.appendChild(th);
    });
    thead.appendChild(tr2);

    // --- Body ---
    const tbody = document.getElementById('pivot-body');
    tbody.innerHTML = '';

    if (items.length === 0) {
        tbody.innerHTML = `<tr><td colspan="20" style="text-align:center;padding:60px 20px;
            background:rgba(139,0,0,0.15);border:2px dashed #ff6b6b;color:#ff6b6b;font-size:22px;font-weight:bold;">
            <i class="fas fa-filter" style="font-size:32px;display:block;margin-bottom:10px;"></i>
            ไม่มีสินค้าในกลุ่ม "${activeGrp}"</td></tr>`;
        if (document.getElementById('total-qty-sum'))
            document.getElementById('total-qty-sum').innerText = '0';
        if (document.getElementById('all-qty-sum'))
            document.getElementById('all-qty-sum').innerText = '0';
        return;
    }

    const fmt2 = v => Number(v).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
    const fmtN = v => Number(v).toLocaleString();

    // ── accumulators สำหรับผลรวม — คำนวณเฉพาะแถวที่แสดงเท่านั้น ──
    let gQty = 0, gAmt = 0;
    const gQtyYr = years.map(() => 0);
    const gAmtYr = years.map(() => 0);

    const frag = document.createDocumentFragment();
    items.forEach((row, idx) => {
        const tr = document.createElement('tr');
        if (idx < 3) tr.classList.add(`rank-${idx+1}`);

        // ลำดับ
        const tdRank = document.createElement('td');
        tdRank.style.textAlign = 'center';
        const badge = document.createElement('span');
        badge.className = 'rank-badge'; badge.textContent = idx+1;
        tdRank.appendChild(badge); tr.appendChild(tdRank);

        // barcode
        const tdB = document.createElement('td');
        tdB.textContent = row.barcode;
        tdB.style.fontFamily = 'monospace'; tdB.style.fontSize = '11px'; tdB.style.fontWeight = 'bold';
        tr.appendChild(tdB);

        // ชื่อ
        const tdN = document.createElement('td');
        tdN.textContent = row.name; tdN.title = row.name;
        tdN.className = 'product-name'; tr.appendChild(tdN);

        // กลุ่มสินค้า — คลิกชื่อกลุ่มเพื่อกรองผลลัพธ์ client-side (ไม่ fetch ใหม่)
        const tdG = document.createElement('td');
        const grpDesc = row.itmgrp_desc && row.itmgrp_desc !== '-' ? row.itmgrp_desc : '';
        if (grpDesc) {
            const grpSpan = document.createElement('span');
            grpSpan.textContent = grpDesc;
            grpSpan.title = 'คลิกเพื่อกรองผลลัพธ์เฉพาะกลุ่ม: ' + grpDesc + ' (ไม่ต้องดึงข้อมูลใหม่)';
            grpSpan.style.cssText = 'color:#c678dd;cursor:pointer;text-decoration:underline dotted #c678dd;' +
                'font-size:12px;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:140px;';
            grpSpan.addEventListener('click', function() {
                const sel = document.getElementById('bs-th-grp-select');
                if (sel) { sel.value = grpDesc; renderBestsellerTable(window.currentData); }
            });
            tdG.appendChild(grpSpan);
        } else {
            tdG.textContent = '-';
            tdG.style.color = '#444'; tdG.style.fontSize = '12px';
        }
        tr.appendChild(tdG);

        // ข้อมูลแต่ละปี — สะสมผลรวมเฉพาะแถวที่แสดง
        years.forEach((yr, yi) => {
            const qty = row.qty_yr[yi] || 0;
            const amt = row.amt_yr[yi] || 0;
            gQtyYr[yi] += qty;
            gAmtYr[yi] += amt;

            let trendCls = '';
            if (yi < years.length - 1) {
                const prev = row.qty_yr[yi+1] || 0;
                trendCls = qty > prev ? 'yr-up' : qty < prev ? 'yr-down' : 'yr-same';
            }

            const tdQ = document.createElement('td');
            tdQ.style.textAlign = 'right';
            tdQ.style.color = qty > 0 ? (yi === 0 ? '#00e676' : '#aef') : '#555';
            if (yi === 0) tdQ.style.fontWeight = 'bold';
            if (trendCls) tdQ.classList.add(trendCls);

            // ทุกปี — แสดง tooltip รายละเอียดสาขา (ถ้ามีข้อมูล)
            const brQtyMap = row.branch_qty_yr ? row.branch_qty_yr[yi] : (yi === 0 ? row.branch_qty : null);
            const brEntries = brQtyMap ? Object.entries(brQtyMap).filter(([,q]) => q > 0).sort((a,b) => b[1]-a[1]) : [];
            if (qty > 0 && brEntries.length > 0) {
                tdQ.classList.add('bs-qty-cell');
                tdQ.innerHTML = `${fmtN(qty)} <span style="font-size:10px;opacity:0.6;vertical-align:middle;">▼</span>`;
                const yrBE = yr + 543;
                let tipRows = `<table>`;
                brEntries.forEach(([off, q]) => {
                    tipRows += `<tr><td class="tip-office">${off}</td><td>${fmtN(q)}</td></tr>`;
                });
                tipRows += `</table>`;
                const tipHTML = `<div style="color:#ff9999;font-size:11px;margin-bottom:4px;border-bottom:1px solid #660000;padding-bottom:3px;">📦 จำนวนต่อสาขา (${yrBE})</div>${tipRows}`;
                tdQ.addEventListener('mouseenter', function(e) {
                    _bsTip.innerHTML = tipHTML;
                    _bsTip.style.display = 'block';
                    _positionBsTip(e);
                });
                tdQ.addEventListener('mousemove', _positionBsTip);
                tdQ.addEventListener('mouseleave', function() {
                    _bsTip.style.display = 'none';
                });
            } else {
                tdQ.textContent = qty > 0 ? fmtN(qty) : '-';
            }

            tr.appendChild(tdQ);

            const tdA = document.createElement('td');
            tdA.textContent = amt > 0 ? fmt2(amt) : '-';
            tdA.style.textAlign = 'right';
            tdA.style.color = amt > 0 ? (yi === 0 ? '#0ff' : '#aaa') : '#555';
            if (yi === 0) tdA.style.fontWeight = 'bold';
            tr.appendChild(tdA);
        });

        // คอลัมน์รวม — คำนวณจาก qty_yr ของแถวที่แสดง (ไม่ใช้ qty_total จาก server)
        const rowQtyTotal = row.qty_yr.reduce((s, v) => s + (v || 0), 0);
        const rowAmtTotal = row.amt_yr.reduce((s, v) => s + (v || 0), 0);
        gQty += rowQtyTotal;
        gAmt += rowAmtTotal;

        const tdTQ = document.createElement('td');
        tdTQ.textContent = fmtN(rowQtyTotal);
        tdTQ.style.textAlign = 'right'; tdTQ.style.color = '#ffd700'; tdTQ.style.fontWeight = 'bold';
        tr.appendChild(tdTQ);
        const tdTA = document.createElement('td');
        tdTA.textContent = fmt2(rowAmtTotal);
        tdTA.style.textAlign = 'right'; tdTA.style.color = '#ffd700'; tdTA.style.fontWeight = 'bold';
        tr.appendChild(tdTA);

        frag.appendChild(tr);
    });

    // summary row — รวมเฉพาะแถวที่แสดง
    const sr = document.createElement('tr');
    sr.className = 'summary-row';
    const tdSLabel = document.createElement('td');
    tdSLabel.colSpan = 4;
    tdSLabel.textContent = `รวม${activeGrp ? ' กลุ่ม "' + activeGrp + '"' : 'ทั้งหมด'} (${fmtN(shown)} รายการ${allItems.length > shown ? ' / ทั้งหมด ' + fmtN(allItems.length) : ''})`;
    tdSLabel.style.textAlign = 'center'; sr.appendChild(tdSLabel);
    years.forEach((yr, yi) => {
        const tdQ = document.createElement('td');
        tdQ.textContent = fmtN(gQtyYr[yi]); tdQ.style.textAlign = 'right'; sr.appendChild(tdQ);
        const tdA = document.createElement('td');
        tdA.textContent = fmt2(gAmtYr[yi]); tdA.style.textAlign = 'right'; sr.appendChild(tdA);
    });
    const tdGQ = document.createElement('td');
    tdGQ.textContent = fmtN(gQty); tdGQ.style.textAlign = 'right'; sr.appendChild(tdGQ);
    const tdGA = document.createElement('td');
    tdGA.textContent = fmt2(gAmt); tdGA.style.textAlign = 'right'; sr.appendChild(tdGA);
    frag.appendChild(sr);

    tbody.appendChild(frag);

    if (document.getElementById('total-qty-sum'))
        document.getElementById('total-qty-sum').innerText = fmtN(gQty);
    if (document.getElementById('all-qty-sum'))
        document.getElementById('all-qty-sum').innerText = fmtN(gQty);
}

// Initial load
let _autoRefreshTimer = null;

function _startAutoRefresh() {
    if (_autoRefreshTimer) { clearInterval(_autoRefreshTimer); _autoRefreshTimer = null; }
    _autoRefreshTimer = setInterval(function() {
        if (document.getElementById('mode-input').value !== 'history' &&
            document.getElementById('search_mode').value === 'sales') {
            updateData(true); // isAutoRefresh=true → ไม่แสดง spinner, ไม่ rebuild header
        }
    }, <?= (int)$pos_refresh_interval * 1000 ?>);
}

function _stopAutoRefresh() {
    if (_autoRefreshTimer) { clearInterval(_autoRefreshTimer); _autoRefreshTimer = null; }
}

<?php if (empty($errors)): ?>
const _initMode = document.getElementById('mode-input').value;
const _initSMode = document.getElementById('search_mode').value;
if (_initSMode === 'bestseller') {
    setSearchMode('bestseller'); // ให้ setSearchMode สร้าง placeholder header ปี 1..5 + โหลด group list
} else if (_initMode === 'history' && _initSMode === 'sales') {
    // history mode: รอกดค้นหา ไม่โหลดอัตโนมัติ ปิด auto-refresh
    document.getElementById('pivot-body').innerHTML =
        `<tr><td colspan="20" style="text-align:center;padding:60px 20px;color:#ff9800;font-size:20px;">
        <i class="fas fa-history" style="font-size:40px;margin-bottom:15px;display:block;opacity:0.6;"></i>
        กรุณาเลือกช่วงวันที่แล้วกด <strong>ค้นหา</strong> เพื่อโหลดข้อมูล</td></tr>`;
    document.getElementById('itemChart').style.display = 'none';
    document.getElementById('pivot-head').innerHTML = '';
    _stopAutoRefresh();
} else {
    // today mode: โหลดทันที + เริ่ม auto-refresh
    updateData();
    _startAutoRefresh();
}
<?php endif; ?>

// โหลด stat สินค้าทั้งหมดทันทีเมื่อ page load (ไม่รอกดค้นหา)
function loadProductStat() {
    fetch('?ajax=1&stat=product')
        .then(r => r.json())
        .then(d => {
            console.log('[ProductStat]', d);
            if (d.all_product_count !== undefined) {
                document.getElementById('all-product-count').innerText =
                    Number(d.all_product_count).toLocaleString();
            }
            if (d.last_product_barcode && d.last_product_barcode !== '-') {
                document.getElementById('last-product-barcode').innerText = d.last_product_barcode;
                document.getElementById('last-product-name').innerText    = d.last_product_name || '-';
                document.getElementById('last-product-date').innerText    = d.last_product_date  || '-';
            }
        })
        .catch(err => console.error('[ProductStat error]', err));
}
loadProductStat(); // เรียกทันทีที่ page โหลด

// โหลดรายการกลุ่มสินค้า (AJAX จาก INV_ITEM_GROUP)
let _itemGroupsLoaded = false;
function loadItemGroups() {
    if (_itemGroupsLoaded) return;
    _itemGroupsLoaded = true;
    fetch('?ajax=1&stat=itemgroup')
        .then(r => r.json())
        .then(d => {
            if (!d.groups || d.groups.length === 0) return;
            const sel = document.getElementById('item-group-select');
            if (!sel) return;
            // เก็บค่าที่เลือกไว้
            const prev = sel.value;
            // ล้าง option เดิม (เหลือแค่ "ทุกกลุ่ม")
            while (sel.options.length > 1) sel.remove(1);
            d.groups.forEach(g => {
                const opt = document.createElement('option');
                opt.value = g.code;
                opt.textContent = g.desc + (g.desc !== g.code ? ' (' + g.code + ')' : '');
                if (g.code === prev) opt.selected = true;
                sel.appendChild(opt);
            });
            // sync bs-th-grp-select ด้วย (ถ้ามีอยู่ในตาราง)
            const thSel = document.getElementById('bs-th-grp-select');
            if (thSel) {
                const thPrev = thSel.value;
                while (thSel.options.length > 1) thSel.remove(1);
                d.groups.forEach(g => {
                    const o = document.createElement('option');
                    o.value = g.code;
                    o.textContent = g.desc + (g.desc !== g.code ? ' (' + g.code + ')' : '');
                    if (g.code === thPrev) o.selected = true;
                    thSel.appendChild(o);
                });
            }
        })
        .catch(err => console.error('[ItemGroup error]', err));
}

// เมื่อ item-group-select ใน filter bar เปลี่ยน → กรอง client-side ใน bestseller, fetch ใหม่ใน mode อื่น
(function() {
    const igSel = document.getElementById('item-group-select');
    if (igSel) {
        igSel.addEventListener('change', function() {
            const thSel = document.getElementById('bs-th-grp-select');
            if (thSel) thSel.value = this.value;
            // bestseller: ไม่ fetch ใหม่ — กรอง client-side เท่านั้น
            // (กดปุ่มค้นหาเองเพื่อส่ง item_group ไปยัง SQL)
        });
    }
})();

// Event handlers
document.getElementById('refresh-btn').onclick = function() {
    this.disabled = true;
    this.innerHTML = ' รอสักครู่...';
    updateData();
    setTimeout(() => {
        this.disabled = false;
        this.innerHTML = '<i class="fas fa-sync"></i> รีเฟรช';
    }, 1000);
};

// jQuery datepicker
$(function() {
    const isHist = document.getElementById('mode-input').value === 'history';
    const opts = {
        dateFormat: 'dd/mm/yy',
        changeMonth: true,
        changeYear: true,
        maxDate: "today",
        minDate: isHist ? new Date(2000, 0, 1) : 'today'
    };
  
    $("#start_date, #end_date").datepicker(opts);

    // ── icon click และ datepicker: เปิดเฉพาะ history mode ──
    if (isHist) {
        $("#start-icon").click(() => $("#start_date").datepicker("show"));
        $("#end-icon").click(() => $("#end_date").datepicker("show"));
        $("#start_date").change(function() {
            $("#end_date").datepicker("option", "minDate", $(this).val());
        });
        $("#end_date").change(function() {
            $("#start_date").datepicker("option", "maxDate", $(this).val());
        });
        const today = new Date();
        const todayStr = ("0"+today.getDate()).slice(-2) + '/' + ("0"+(today.getMonth()+1)).slice(-2) + '/' + today.getFullYear();
        ["#start_date", "#end_date"].forEach(sel => {
            $(sel).on("dblclick", function() {
                $(this).val(todayStr);
                $(this).datepicker("setDate", todayStr);
                if (document.getElementById('search_mode').value !== 'bestseller') {
                    setTimeout(() => updateData(), 100);
                }
            });
        });
    }
  
    $("select").on("keydown", e => {
        if (e.key === "Enter") {
            e.preventDefault();
            if (document.getElementById('search_mode').value !== 'bestseller') {
                updateData();
            }
        }
    });

    // sync datepicker internal state กับค่าที่ PHP render ไว้ใน input
    (function() {
        const pMode  = document.getElementById('mode-input').value;
        const isHist2 = pMode === 'history';
        if (isHist2) {
            const minD = new Date(2000,0,1);
            $('#start_date,#end_date').datepicker('option', 'minDate', minD);
            $('#start_date,#end_date').datepicker('option', 'maxDate', 'today');
            const startVal = document.getElementById('start_date').value;
            const endVal   = document.getElementById('end_date').value;
            if (startVal) $('#start_date').datepicker('setDate', startVal);
            if (endVal)   $('#end_date').datepicker('setDate', endVal);
        } else {
            // today mode: ล็อควันที่เป็นวันนี้ ไม่ต้อง sync datepicker
            const now = new Date();
            const todayStr = ("0"+now.getDate()).slice(-2) + '/' + ("0"+(now.getMonth()+1)).slice(-2) + '/' + now.getFullYear();
            document.getElementById('start_date').value = todayStr;
            if (document.getElementById('end_date')) document.getElementById('end_date').value = todayStr;
        }
    })();
});

// top-right expand/collapse: handled by POS_TOPRIGHT.php

</script>
</body>
</html>