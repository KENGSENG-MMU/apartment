<?php
if (!function_exists('notification_badge_safe_count')) {
    function notification_badge_safe_count(PDO $pdo, string $sql, array $params = []): int {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('notification_badge_table_exists')) {
    function notification_badge_table_exists(PDO $pdo, string $table): bool {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
            ");
            $stmt->execute([$table]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('notification_badge_e')) {
    function notification_badge_e($value): string {
        if (function_exists('e')) {
            return e($value);
        }

        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$notifUserId = (int)($_SESSION['uid'] ?? 0);
$notifUnreadCount = 0;

if ($notifUserId > 0 && function_exists('db')) {
    $notifPdo = db();

    if (notification_badge_table_exists($notifPdo, 'notifications')) {
        $notifUnreadCount = notification_badge_safe_count(
            $notifPdo,
            "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0",
            [$notifUserId]
        );
    }
}
?>

<style>
    .notification-link-wrap {
        position: relative;
    }

    .notification-count-badge {
        position: absolute;
        top: -7px;
        right: -7px;
        min-width: 20px;
        height: 20px;
        padding: 0 6px;
        border-radius: 999px;
        background: #dc2626;
        color: white;
        font-size: 0.68rem;
        font-weight: 900;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 8px 18px rgba(220,38,38,.28);
        border: 2px solid white;
    }
</style>

<a href="notifications.php" class="notification-link-wrap <?= basename($_SERVER['PHP_SELF']) === 'notifications.php' ? 'active' : '' ?>">
    <i class="fas fa-bell"></i>
    Notifications

    <?php if ($notifUnreadCount > 0): ?>
        <span class="notification-count-badge">
            <?= $notifUnreadCount > 99 ? '99+' : (int)$notifUnreadCount ?>
        </span>
    <?php endif; ?>
</a>