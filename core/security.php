<?php
// 文件路径: core/security.php
require_once 'config.php';
start_secure_session();

// 动态 Session 超时设置 (你要求的企业级细节)
define('SESSION_TIMEOUT_GUARD', 5 * 60);  // 保安: 5 分钟 (300秒)
define('SESSION_TIMEOUT_NORMAL', 30 * 60); // 其他角色: 30 分钟 (1800秒)

// 1. 审计日志写入函数 (记录 IP 和 操作)
function log_audit($action, $detail = '') {
    $pdo = db();
    $uid = $_SESSION['uid'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    // 获取设备信息(截取前250字符防溢出)
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN', 0, 250); 
    
    $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, detail, ip_addr) VALUES (?, ?, ?, ?)");
    $stmt->execute([$uid, $action, "$detail | Device: $ua", $ip]);
}

// 2. 检查 Session 是否超时
function check_session() {
    if (!isset($_SESSION['uid'])) return false;

    $timeout_limit = ($_SESSION['role'] === 'guard') ? SESSION_TIMEOUT_GUARD : SESSION_TIMEOUT_NORMAL;
    
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_limit) {
        log_audit('SESSION_TIMEOUT', "User logged out automatically due to inactivity.");
        session_unset();
        session_destroy();
        return false;
    }
    
    $_SESSION['last_activity'] = time(); // 更新最后活跃时间
    return true;
}

// 3. 页面权限拦截器
function require_login($allowed_roles = []) {
    if (!check_session()) {
        header("Location: ../public/login.php?msg=timeout");
        exit();
    }
    
    if (!empty($allowed_roles) && !in_array($_SESSION['role'], $allowed_roles)) {
        log_audit('UNAUTHORIZED_ACCESS', 'Attempted to access restricted page');
        die("❌ 403 Forbidden: 权限不足。");
    }
}

// 4. 生成 CSRF Token 表单隐藏域
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . e($_SESSION['csrf_token']) . '">';
}


if (!function_exists('log_audit')) {
    function log_audit($action, $details = '') {
        try {
            $pdo = db();

            $user_id = $_SESSION['uid'] ?? null;
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;

            $stmt = $pdo->prepare("
                INSERT INTO audit_logs
                (user_id, action, details, ip_address)
                VALUES (?, ?, ?, ?)
            ");

            $stmt->execute([
                $user_id,
                $action,
                $details,
                $ip_address
            ]);
        } catch (Throwable $e) {
            // Do not stop the system if audit log fails
        }
    }
}

if (!function_exists('create_notification')) {
    function create_notification(PDO $pdo, int $userId, string $title, string $message, string $type = 'system'): bool {
        if ($userId <= 0) {
            return false;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'notifications'
            ");
            $stmt->execute();

            if ((int)$stmt->fetchColumn() <= 0) {
                return false;
            }

            $stmt = $pdo->prepare("
                SELECT COLUMN_TYPE
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'notifications'
                AND COLUMN_NAME = 'type'
                LIMIT 1
            ");
            $stmt->execute();
            $columnType = (string)$stmt->fetchColumn();

            if (str_starts_with(strtolower($columnType), 'enum')) {
                $allowedTypes = ['info', 'success', 'warning', 'danger'];

                if (!in_array($type, $allowedTypes, true)) {
                    $type = 'info';
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO notifications
                (user_id, title, message, type, is_read, created_at)
                VALUES
                (?, ?, ?, ?, 0, NOW())
            ");

            return $stmt->execute([
                $userId,
                $title,
                $message,
                $type
            ]);

        } catch (Throwable $e) {
            return false;
        }
    }
}