<?php
// 文件路径: core/config.php

define('DB_HOST', 'localhost');
define('DB_NAME', 'apartment');
define('DB_USER', 'root');
define('DB_PASS', '');
define('APP_NAME', 'SmartVMS Enterprise');
date_default_timezone_set('Asia/Kuala_Lumpur');

// 安全的 PDO 数据库连接
function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        die("Database Connection Failed: " . $e->getMessage());
    }
}

// 启动安全 Session 与 CSRF
function start_secure_session() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

// PDPA 亮点：电话号码 Masking 函数 (例如将 0123456789 变成 012-****789)
function mask_phone($phone) {
    if (!$phone || strlen($phone) < 8) return 'N/A';
    return substr($phone, 0, 3) . '-****' . substr($phone, -3);
}

// XSS 安全输出
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}
?>