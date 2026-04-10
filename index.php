<?php
// ============================================================
//  index.php — Entry Point
//  ถ้า Login แล้ว  → POS_HOME.php
//  ยังไม่ Login    → login.php
// ============================================================
session_start();

if (!empty($_SESSION['pos_user'])) {
    header('Location: POS_HOME.php');
    exit;
}

header('Location: login.php');
exit;
