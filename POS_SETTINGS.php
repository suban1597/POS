<?php
// ============================================================
//  POS_SETTINGS.php  —  Shared User-Settings Handler
//  Include ไฟล์นี้หลัง session_start() ในทุกหน้า
// ============================================================

// ── AJAX: บันทึก Settings ลง Session ──────────────────────
if (isset($_GET['save_settings'])) {
    header('Content-Type: application/json');
    $ri = max(5, min(300, (int)($_POST['refresh_interval'] ?? 15)));
    $cd = max(3, min(60,  (int)($_POST['collapse_delay']   ?? 10)));
    $note = substr(strip_tags(trim($_POST['note'] ?? '')), 0, 200);
    $tc = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['theme_color'] ?? '')
          ? $_POST['theme_color'] : '#00ffff';
    $_SESSION['pos_refresh_interval'] = $ri;
    $_SESSION['pos_collapse_delay']   = $cd;
    $_SESSION['pos_note']             = $note;
    $_SESSION['pos_theme_color']      = $tc;
    echo json_encode(['ok' => true,
        'refresh_interval' => $ri,
        'collapse_delay'   => $cd,
        'note'             => $note,
        'theme_color'      => $tc]);
    exit;
}

// ── อ่านค่า Settings จาก Session (พร้อม default) ──────────
$pos_refresh_interval = (int)($_SESSION['pos_refresh_interval'] ?? 15);
$pos_collapse_delay   = (int)($_SESSION['pos_collapse_delay']   ?? 10);
$pos_note             = $_SESSION['pos_note']        ?? '';
$pos_theme_color      = $_SESSION['pos_theme_color'] ?? '#00ffff';

// ── คำนวณวันหมดอายุ (expire) ──────────────────────────────
// ตั้งค่า $_SESSION['pos_expire_date'] = 'YYYY-MM-DD' ตอน login
// ค่า $pos_days_remaining: null = ไม่มีข้อมูล, ≥0 = จำนวนวันที่เหลือ
$pos_days_remaining = null;
if (!empty($_SESSION['pos_expire_date'])) {
    $expireDate = date_create($_SESSION['pos_expire_date']);
    if ($expireDate) {
        $today = date_create(date('Y-m-d'));
        $pos_days_remaining = (int)date_diff($today, $expireDate)->format('%r%a');
    }
}
