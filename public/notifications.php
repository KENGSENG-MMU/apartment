<?php
require_once '../core/security.php';
require_login(['visitor', 'resident']);

$pdo = db();

$userId = (int)($_SESSION['uid'] ?? 0);
$userRole = $_SESSION['role'] ?? '';
$userEmail = $_SESSION['email'] ?? '';

$message = '';
$error = '';

function table_exists_notify(PDO $pdo, string $table): bool {
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

function has_column_notify(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_notifications_table(PDO $pdo): void {
    if (!table_exists_notify($pdo, 'notifications')) {
        try {
            $pdo->exec("
                CREATE TABLE notifications (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    title VARCHAR(150) NOT NULL,
                    message TEXT NOT NULL,
                    type VARCHAR(50) DEFAULT 'general',
                    is_read TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_notifications_user (user_id),
                    INDEX idx_notifications_read (is_read),
                    INDEX idx_notifications_type (type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            // Keep page usable if the database user cannot create tables.
        }
    }
}

function ensure_column_notify(PDO $pdo, string $table, string $column, string $definition): void {
    if (!table_exists_notify($pdo, $table)) {
        return;
    }

    if (!has_column_notify($pdo, $table, $column)) {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (Throwable $e) {
            // Ignore if ALTER is not allowed.
        }
    }
}

function safe_count_notify(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function safe_text_notify($value): string {
    return $value !== null && $value !== '' ? (string)$value : '-';
}

function notification_icon(string $type): string {
    return match (strtolower($type)) {
        'booking' => 'fa-calendar-check',
        'security' => 'fa-shield-halved',
        'parking' => 'fa-square-parking',
        'system' => 'fa-gear',
        default => 'fa-bell'
    };
}

function notification_badge_class(string $type): string {
    return match (strtolower($type)) {
        'booking' => 'type-booking',
        'security' => 'type-security',
        'parking' => 'type-parking',
        'system' => 'type-system',
        default => 'type-general'
    };
}

function smartvms_extract_receipt_link(string $message): ?string {
    if (preg_match('/(https?:\/\/[^\s<>"\']*visitor_pass\.php\?id=\d+)/i', $message, $matches)) {
        return $matches[1];
    }

    if (preg_match('/(visitor_pass\.php\?id=\d+)/i', $message, $matches)) {
        return $matches[1];
    }

    return null;
}

function smartvms_remove_receipt_link_from_message(string $message): string {
    $message = preg_replace('/Receipt\s*\/\s*QR pass:\s*/i', '', $message);
    $message = preg_replace('/https?:\/\/[^\s<>"\']*visitor_pass\.php\?id=\d+/i', '', $message);
    $message = preg_replace('/visitor_pass\.php\?id=\d+/i', '', $message);
    return trim($message);
}


function smartvms_extract_plate_from_message(string $message): string {
    if (preg_match('/Plate\s*:\s*([A-Za-z0-9\-]+)/i', $message, $matches)) {
        return strtoupper(trim($matches[1]));
    }
    return '';
}

function smartvms_extract_visitor_name_from_message(string $message): string {
    if (preg_match('/^\s*(.+?)\s+submitted\s+a\s+visit\s+request/i', $message, $matches)) {
        return trim($matches[1]);
    }
    return '';
}

function smartvms_find_related_booking(PDO $pdo, array $notification, int $residentId, string $userRole): ?array {
    if ($userRole !== 'resident') {
        return null;
    }

    $title = strtolower((string)($notification['title'] ?? ''));
    $type = strtolower((string)($notification['type'] ?? ''));
    $rawMessage = (string)($notification['message'] ?? '');

    if ($type !== 'booking' && !str_contains($title, 'visitor request')) {
        return null;
    }

    $plate = smartvms_extract_plate_from_message($rawMessage);
    $visitorName = smartvms_extract_visitor_name_from_message($rawMessage);

    try {
        if ($plate !== '') {
            $stmt = $pdo->prepare("
                SELECT id, status
                FROM bookings
                WHERE resident_id = ?
                AND UPPER(REPLACE(plate_no, ' ', '')) = UPPER(REPLACE(?, ' ', ''))
                ORDER BY created_at DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([$residentId, $plate]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($booking) {
                return $booking;
            }
        }

        if ($visitorName !== '') {
            $stmt = $pdo->prepare("
                SELECT id, status
                FROM bookings
                WHERE resident_id = ?
                AND visitor_name = ?
                ORDER BY created_at DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([$residentId, $visitorName]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($booking) {
                return $booking;
            }
        }
    } catch (Throwable $e) {
        return null;
    }

    return null;
}

function smartvms_booking_decision_status(string $bookingStatus): array {
    $status = strtolower(trim($bookingStatus));

    if ($status === 'pending' || $status === 'waiting') {
        return [
            'label' => 'Pending',
            'class' => 'status-pending',
            'clickable' => $status === 'pending'
        ];
    }

    if ($status === 'rejected' || $status === 'cancelled') {
        return [
            'label' => 'Rejected',
            'class' => 'status-rejected',
            'clickable' => false
        ];
    }

    return [
        'label' => 'Approved',
        'class' => 'status-approved',
        'clickable' => false
    ];
}

function smartvms_request_status_info(PDO $pdo, array $notification, int $residentId, string $userRole): ?array {
    $booking = smartvms_find_related_booking($pdo, $notification, $residentId, $userRole);

    if (!$booking) {
        return null;
    }

    $statusInfo = smartvms_booking_decision_status((string)($booking['status'] ?? ''));
    $bookingId = (int)($booking['id'] ?? 0);

    $statusInfo['booking_id'] = $bookingId;
    $statusInfo['link'] = ($statusInfo['clickable'] && $bookingId > 0)
        ? 'resident_requests.php?booking_id=' . $bookingId
        : null;

    return $statusInfo;
}

function smartvms_pending_request_link(PDO $pdo, array $notification, int $residentId, string $userRole): ?string {
    $statusInfo = smartvms_request_status_info($pdo, $notification, $residentId, $userRole);
    return $statusInfo['link'] ?? null;
}

ensure_notifications_table($pdo);
ensure_column_notify($pdo, 'notifications', 'type', "VARCHAR(50) DEFAULT 'general'");
ensure_column_notify($pdo, 'notifications', 'is_read', "TINYINT(1) NOT NULL DEFAULT 0");
ensure_column_notify($pdo, 'notifications', 'created_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        try {
            $action = $_POST['action'] ?? '';
            $notificationId = (int)($_POST['notification_id'] ?? 0);

            if ($action === 'mark_read') {
                if ($notificationId <= 0) {
                    throw new Exception('Invalid notification.');
                }

                $stmt = $pdo->prepare("
                    UPDATE notifications
                    SET is_read = 1
                    WHERE id = ?
                    AND user_id = ?
                ");
                $stmt->execute([$notificationId, $userId]);

                $message = 'Notification marked as read.';
            } elseif ($action === 'mark_all_read') {
                $stmt = $pdo->prepare("
                    UPDATE notifications
                    SET is_read = 1
                    WHERE user_id = ?
                    AND is_read = 0
                ");
                $stmt->execute([$userId]);

                $message = 'All notifications marked as read.';
            } elseif ($action === 'delete') {
                if ($notificationId <= 0) {
                    throw new Exception('Invalid notification.');
                }

                $stmt = $pdo->prepare("
                    DELETE FROM notifications
                    WHERE id = ?
                    AND user_id = ?
                ");
                $stmt->execute([$notificationId, $userId]);

                $message = 'Notification deleted.';
            } else {
                throw new Exception('Invalid action.');
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    $_SESSION['notification_flash'] = [
        'message' => $message,
        'error' => $error
    ];

    header('Location: notifications.php');
    exit;
}

if (isset($_SESSION['notification_flash']) && is_array($_SESSION['notification_flash'])) {
    $message = $_SESSION['notification_flash']['message'] ?? '';
    $error = $_SESSION['notification_flash']['error'] ?? '';
    unset($_SESSION['notification_flash']);
}

$searchKeyword = trim((string)($_GET['search'] ?? ''));
$searchDate = trim((string)($_GET['date'] ?? ''));

$notificationWhere = ["user_id = ?"];
$notificationParams = [$userId];

if ($searchKeyword !== '') {
    $notificationWhere[] = "(title LIKE ? OR message LIKE ? OR type LIKE ?)";
    $keywordLike = '%' . $searchKeyword . '%';
    $notificationParams[] = $keywordLike;
    $notificationParams[] = $keywordLike;
    $notificationParams[] = $keywordLike;
}

if ($searchDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $searchDate)) {
    $notificationWhere[] = "DATE(created_at) = ?";
    $notificationParams[] = $searchDate;
}

$notificationWhereSql = implode(' AND ', $notificationWhere);

$stmt = $pdo->prepare("
    SELECT
        id,
        user_id,
        title,
        message,
        type,
        is_read,
        created_at
    FROM notifications
    WHERE {$notificationWhereSql}
    ORDER BY created_at DESC, id DESC
");
$stmt->execute($notificationParams);
$notifications = $stmt->fetchAll();

$filteredTotal = count($notifications);
$hasNotificationFilter = ($searchKeyword !== '' || $searchDate !== '');

$stmt = $pdo->prepare("
    SELECT DISTINCT DATE(created_at) AS notification_date
    FROM notifications
    WHERE user_id = ?
    ORDER BY notification_date ASC
");
$stmt->execute([$userId]);
$notificationAvailableDates = array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));

$selectedDateDisplay = 'dd/mm/yyyy';
if ($searchDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $searchDate)) {
    $selectedDateDisplay = date('d/m/Y', strtotime($searchDate));
}

$totalNotifications = safe_count_notify($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id = ?", [$userId]);
$unreadNotifications = safe_count_notify($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0", [$userId]);
$readNotifications = safe_count_notify($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 1", [$userId]);
$todayNotifications = safe_count_notify($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND DATE(created_at) = CURDATE()", [$userId]);
$bookingNotifications = safe_count_notify($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'booking'", [$userId]);
$securityNotifications = safe_count_notify($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'security'", [$userId]);

ensure_column_notify($pdo, 'users', 'full_name', "VARCHAR(150) NULL");
ensure_column_notify($pdo, 'users', 'profile_photo', "VARCHAR(255) NULL");

$hasUserFullName = has_column_notify($pdo, 'users', 'full_name');
$hasUserProfilePhoto = has_column_notify($pdo, 'users', 'profile_photo');

$userNameSql = $hasUserFullName ? "full_name" : "NULL AS full_name";
$userPhotoSql = $hasUserProfilePhoto ? "profile_photo" : "NULL AS profile_photo";

$stmt = $pdo->prepare("
    SELECT
        email,
        role,
        {$userNameSql},
        {$userPhotoSql}
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$displayName = $currentUser['full_name'] ?: explode('@', $userEmail ?: 'User')[0];
$displayInitial = strtoupper(substr(trim($displayName) ?: 'U', 0, 1));
$profilePhotoUrl = '';

if (!empty($currentUser['profile_photo'])) {
    $photoPath = ltrim((string)$currentUser['profile_photo'], '/');

    if (preg_match('/^https?:\/\//i', $photoPath)) {
        $profilePhotoUrl = $photoPath;
    } elseif (is_file(__DIR__ . '/' . $photoPath)) {
        $profilePhotoUrl = $photoPath;
    }
}

$isResident = $userRole === 'resident';
$isVisitor = $userRole === 'visitor';

$homeLink = $isResident ? 'resident.php' : 'visitor_book.php';
$homeLabel = $isResident ? 'Dashboard' : 'Book Visit';
$homeIcon = $isResident ? 'fa-home' : 'fa-calendar-plus';

$profileLink = $isResident ? 'resident_profile.php' : 'visitor_profile.php';
$requestsLink = 'resident_requests.php';

$headerClass = $isResident ? 'dark-navbar' : 'white-navbar';
$pageClass = $isResident ? 'resident-page' : 'visitor-page';

$heroTitle = 'Notifications';
$heroSubtitle = 'View system messages about booking approval, parking slot assignment, visitor pass status, security alerts, and system updates.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Notifications - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
:root {
    --surface: rgba(255, 255, 255, 0.94);
    --line: #e2e8f0;
    --navy: #0f172a;
    --text: #334155;
    --muted: #64748b;
    --blue: #2563eb;
    --blue-dark: #1e40af;
    --blue-soft: #eff6ff;
    --red: #ef4444;
    --red-soft: #fff1f2;
    --green: #16a34a;
    --green-soft: #dcfce7;
    --yellow: #f59e0b;
    --yellow-soft: #fffbeb;
    --shadow-sm: 0 8px 20px rgba(15, 23, 42, 0.045);
    --shadow-md: 0 18px 45px rgba(15, 23, 42, 0.09);
}

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
    font-family: 'Plus Jakarta Sans', sans-serif;
}

body {
    min-height: 100vh;
    color: var(--text);
    background:
        radial-gradient(circle at 10% 18%, rgba(147, 197, 253, 0.13) 0 72px, transparent 74px),
        radial-gradient(circle at 90% 30%, rgba(191, 219, 254, 0.18) 0 95px, transparent 97px),
        radial-gradient(circle at 16% 82%, rgba(186, 230, 253, 0.14) 0 62px, transparent 64px),
        radial-gradient(circle at 86% 87%, rgba(219, 234, 254, 0.24) 0 86px, transparent 88px),
        linear-gradient(180deg, #ffffff 0%, #f3f6fb 100%);
    overflow-x: hidden;
}

a {
    color: inherit;
    text-decoration: none;
}

.navbar {
    height: 76px;
    padding: 0 5%;
    background: rgba(255, 255, 255, 0.92);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border-bottom: 1px solid var(--line);
    position: sticky;
    top: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.brand {
    font-size: 1.55rem;
    font-weight: 900;
    color: var(--navy);
    letter-spacing: -0.8px;
    white-space: nowrap;
}

.brand span {
    color: var(--blue);
}

.nav-links {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
}

.nav-btn {
    padding: 10px 14px;
    border-radius: 999px;
    color: #344054;
    background: transparent;
    border: 0;
    text-decoration: none;
    font-size: 0.82rem;
    font-weight: 800;
    line-height: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: 0.22s ease;
    white-space: nowrap;
}

.nav-btn:hover,
.nav-btn.active {
    color: var(--blue);
    background: var(--blue-soft);
}

.nav-btn.active {
    border: 1px solid #bfdbfe;
}

.nav-btn.logout {
    color: #dc2626;
    background: var(--red-soft);
}

.nav-btn.logout:hover {
    color: #b91c1c;
    background: #ffe4e6;
}

.notification-nav-btn {
    position: relative;
}

.nav-notification-badge {
    position: absolute;
    top: -5px;
    right: -4px;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 999px;
    background: #dc2626;
    color: #ffffff;
    font-size: 0.65rem;
    font-weight: 900;
    line-height: 20px;
    text-align: center;
    border: 2px solid #ffffff;
    box-shadow: 0 8px 18px rgba(220, 38, 38, 0.25);
}

.page {
    width: min(1120px, calc(100% - 48px));
    margin: 0 auto;
    padding: 42px 0 68px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 24px;
    margin-bottom: 24px;
    padding-bottom: 22px;
    border-bottom: 1px solid var(--line);
}

.header-kicker {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--blue);
    background: rgba(239, 246, 255, 0.9);
    border: 1px solid #dbeafe;
    padding: 7px 12px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 900;
    letter-spacing: 0.35px;
    margin-bottom: 14px;
}

.header-info h1 {
    font-size: 2.5rem;
    font-weight: 900;
    color: var(--navy);
    letter-spacing: -1.5px;
    line-height: 1.08;
    margin-bottom: 10px;
}

.header-info p {
    color: var(--muted);
    font-size: 1rem;
    font-weight: 650;
    max-width: 700px;
    line-height: 1.55;
}

.user-badge {
    min-width: 260px;
    padding: 14px 20px;
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.82);
    border: 1px solid var(--line);
    box-shadow: var(--shadow-sm);
    display: flex;
    align-items: center;
    gap: 12px;
}

.user-icon {
    width: 38px;
    height: 38px;
    border-radius: 12px;
    background: var(--blue-soft);
    color: var(--blue);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.user-badge small {
    display: block;
    color: var(--muted);
    font-size: 0.68rem;
    font-weight: 900;
    letter-spacing: 0.7px;
    text-transform: uppercase;
    margin-bottom: 2px;
}

.user-badge strong {
    display: block;
    color: var(--navy);
    font-size: 0.92rem;
    font-weight: 900;
}

.user-badge .sub {
    color: var(--muted);
    font-size: 0.78rem;
    font-weight: 700;
    margin-top: 2px;
}

.alert {
    border-radius: 16px;
    padding: 14px 16px;
    margin-bottom: 18px;
    font-weight: 800;
    line-height: 1.5;
    border: 1px solid transparent;
}

.alert.success {
    color: #166534;
    background: #dcfce7;
    border-color: #bbf7d0;
}

.alert.error {
    color: #991b1b;
    background: #fee2e2;
    border-color: #fecaca;
}

.summary-row {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 14px;
    margin-bottom: 24px;
}

.summary-card {
    min-height: 92px;
    padding: 20px 12px;
    border-radius: 18px;
    background: var(--surface);
    border: 1px solid var(--line);
    box-shadow: var(--shadow-sm);
    text-align: center;
    position: relative;
    overflow: hidden;
}

.summary-card::before {
    content: "";
    position: absolute;
    top: 0;
    left: 18px;
    right: 18px;
    height: 3px;
    border-radius: 0 0 999px 999px;
    background: var(--blue);
    opacity: 0.85;
}

.summary-num {
    color: var(--navy);
    font-size: 1.75rem;
    font-weight: 900;
    line-height: 1;
    margin-bottom: 10px;
}

.summary-label {
    color: var(--muted);
    font-size: 0.68rem;
    font-weight: 900;
    letter-spacing: 0.7px;
    text-transform: uppercase;
}

.panel {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 24px;
    box-shadow: var(--shadow-md);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    overflow: hidden;
}

.panel-head {
    padding: 22px 26px;
    border-bottom: 1px solid var(--line);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.panel-title {
    color: var(--navy);
    font-size: 1.1rem;
    font-weight: 900;
    display: inline-flex;
    align-items: center;
    gap: 10px;
}

.panel-title i {
    color: var(--blue);
}

.panel-body {
    padding: 20px 22px 24px;
}

.empty {
    min-height: 150px;
    border: 1px dashed #cbd5e1;
    border-radius: 20px;
    background: #f8fafc;
    color: var(--muted);
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
}

.notification-list {
    display: grid;
    gap: 14px;
}

.notification-card {
    position: relative;
    display: grid;
    grid-template-columns: 54px 1fr auto;
    gap: 16px;
    align-items: flex-start;
    padding: 18px;
    border-radius: 18px;
    background: #ffffff;
    border: 1px solid var(--line);
    box-shadow: var(--shadow-sm);
    transition: 0.22s ease;
}

.notification-card:hover {
    border-color: #bfdbfe;
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.notification-card.unread {
    background: linear-gradient(135deg, rgba(239, 246, 255, 0.88), #ffffff 72%);
    border-color: #bfdbfe;
}

.notification-card.is-clickable {
    cursor: pointer;
}

.notif-icon {
    width: 54px;
    height: 54px;
    border-radius: 16px;
    background: var(--blue-soft);
    color: var(--blue);
    border: 1px solid #dbeafe;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.18rem;
}

.notif-title {
    color: var(--navy);
    font-size: 1rem;
    font-weight: 900;
    margin-bottom: 6px;
}

.notif-time {
    color: var(--muted);
    font-size: 0.76rem;
    font-weight: 800;
    margin-bottom: 10px;
}

.notif-message {
    color: var(--text);
    font-size: 0.9rem;
    font-weight: 650;
    line-height: 1.55;
}

.notif-actions {
    display: flex;
    align-items: center;
    gap: 9px;
    flex-wrap: wrap;
    margin-top: 14px;
}

.btn {
    border: 0;
    cursor: pointer;
    text-decoration: none;
    min-height: 40px;
    padding: 0 16px;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 900;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: 0.22s ease;
    white-space: nowrap;
}

.btn-primary,
.btn-view {
    background: var(--blue);
    color: #ffffff;
    box-shadow: 0 10px 20px rgba(37, 99, 235, 0.14);
}

.btn-primary:hover,
.btn-view:hover {
    background: var(--blue-dark);
    transform: translateY(-2px);
}

.btn-danger {
    background: var(--red-soft);
    color: #dc2626;
    border: 1px solid #fecaca;
}

.btn-danger:hover {
    background: #ffe4e6;
}

.status-pill {
    border-radius: 999px;
    padding: 9px 12px;
    font-size: 0.72rem;
    font-weight: 900;
    white-space: nowrap;
    border: 1px solid transparent;
}

.status-pill.pending {
    background: var(--yellow-soft);
    color: #b45309;
    border-color: #fde68a;
}

.status-pill.approved,
.status-pill.completed {
    background: var(--green-soft);
    color: #15803d;
    border-color: #bbf7d0;
}

.status-pill.rejected,
.status-pill.cancelled,
.status-pill.expired {
    background: var(--red-soft);
    color: #b91c1c;
    border-color: #fecaca;
}

.status-pill.default {
    background: #f1f5f9;
    color: #475569;
    border-color: #e2e8f0;
}

.more-wrap {
    margin-top: 18px;
    display: flex;
    justify-content: center;
}

.btn-more {
    min-width: 130px;
}

.swal2-popup {
    border-radius: 22px !important;
    background: #ffffff !important;
    color: var(--navy) !important;
    border: 1px solid var(--line) !important;
}

.swal2-title {
    color: var(--navy) !important;
}

.swal2-html-container {
    color: var(--muted) !important;
}

@media (max-width: 1180px) {
    .navbar {
        height: auto;
        padding: 16px 24px;
        align-items: flex-start;
        flex-direction: column;
        gap: 12px;
    }

    .nav-links {
        width: 100%;
        flex-wrap: wrap;
        justify-content: flex-start;
    }

    .summary-row {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 820px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .user-badge {
        width: 100%;
    }

    .summary-row {
        grid-template-columns: repeat(2, 1fr);
    }

    .notification-card {
        grid-template-columns: 48px 1fr;
    }

    .status-pill {
        grid-column: 2;
        width: fit-content;
    }

    .panel-head {
        flex-direction: column;
        align-items: flex-start;
    }
}

@media (max-width: 620px) {
    .page {
        width: min(100% - 28px, 1120px);
        padding-top: 26px;
    }

    .header-info h1 {
        font-size: 2rem;
    }

    .summary-row {
        grid-template-columns: 1fr;
    }

    .panel-head,
    .panel-body {
        padding-left: 18px;
        padding-right: 18px;
    }

    .nav-btn {
        padding: 9px 11px;
        font-size: 0.76rem;
    }
}
    </style>

<style id="role-based-notification-navbar-fix">
/* Profile dropdown shared style */
.profile-menu {
    position: relative;
    display: inline-flex;
    align-items: center;
}

.profile-trigger {
    border: 1px solid #bfdbfe;
    background: #eff6ff;
    color: #2563eb;
    min-height: 42px;
    padding: 6px 11px 6px 7px;
    border-radius: 16px;
    display: inline-flex;
    align-items: center;
    gap: 9px;
    cursor: pointer;
    font-size: .78rem;
    font-weight: 900;
    transition: .18s ease;
}

.profile-trigger:hover,
.profile-menu:focus-within .profile-trigger,
.profile-menu:hover .profile-trigger {
    background: #dbeafe;
    transform: translateY(-1px);
}

.profile-avatar-mini,
.dropdown-avatar {
    border-radius: 50%;
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    color: #2563eb;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 900;
    overflow: hidden;
    flex-shrink: 0;
}

.profile-avatar-mini {
    width: 30px;
    height: 30px;
    font-size: .84rem;
    border: 2px solid rgba(255,255,255,.45);
}

.profile-avatar-mini img,
.dropdown-avatar img,
.user-icon img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-trigger-name {
    max-width: 120px;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.profile-dropdown {
    position: absolute;
    right: 0;
    top: calc(100% + 12px);
    width: 292px;
    padding: 10px;
    border-radius: 22px;
    background: #ffffff;
    border: 1px solid #dbe5f0;
    box-shadow: 0 24px 55px rgba(15, 23, 42, .20);
    z-index: 3000;
    display: none;
}

.profile-dropdown::before {
    content: "";
    position: absolute;
    right: 22px;
    top: -8px;
    width: 16px;
    height: 16px;
    background: #ffffff;
    border-left: 1px solid #dbe5f0;
    border-top: 1px solid #dbe5f0;
    transform: rotate(45deg);
}

.profile-menu:hover .profile-dropdown,
.profile-menu:focus-within .profile-dropdown {
    display: block;
}

.dropdown-head {
    padding: 14px;
    border-radius: 18px;
    background: linear-gradient(135deg, #eff6ff, #dbeafe);
    border: 1px solid #bfdbfe;
    display: flex;
    align-items: center;
    gap: 13px;
    margin-bottom: 8px;
}

.dropdown-avatar {
    width: 52px;
    height: 52px;
    font-size: 1.15rem;
}

.dropdown-name {
    color: #0f172a;
    font-size: .95rem;
    font-weight: 900;
    line-height: 1.2;
}

.dropdown-sub {
    color: #64748b;
    font-size: .76rem;
    font-weight: 800;
    margin-top: 3px;
}

.dropdown-link {
    min-height: 52px;
    padding: 12px 13px;
    border-radius: 16px;
    color: #0f172a !important;
    background: #ffffff !important;
    border: 1px solid transparent !important;
    box-shadow: none !important;
    display: flex !important;
    align-items: center;
    gap: 12px !important;
    font-size: .88rem !important;
    font-weight: 900 !important;
    text-decoration: none;
}

.dropdown-link:hover {
    background: #f8fafc !important;
    border-color: #e2e8f0 !important;
    transform: none !important;
}

.dropdown-link i {
    width: 36px;
    height: 36px;
    border-radius: 12px;
    background: #eff6ff;
    color: #2563eb;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.dropdown-footer {
    margin-top: 6px;
    padding-top: 8px;
    border-top: 1px solid #e2e8f0;
}

.dropdown-logout,
.dropdown-logout strong {
    color: #dc2626 !important;
}

.dropdown-logout i {
    background: #fff1f2;
    color: #dc2626;
}

.user-icon {
    overflow: hidden;
    font-weight: 900;
}

/* Visitor notification page = visitor theme */
body.visitor-theme {
    background:
        radial-gradient(circle at 10% 18%, rgba(191, 219, 254, .42), transparent 10%),
        radial-gradient(circle at 88% 30%, rgba(219, 234, 254, .40), transparent 11%),
        radial-gradient(circle at 16% 84%, rgba(203, 213, 225, .30), transparent 9%),
        radial-gradient(circle at 84% 88%, rgba(191, 219, 254, .25), transparent 9%),
        linear-gradient(180deg, #f8fbff 0%, #eef3f8 100%) !important;
}

body.visitor-theme .navbar {
    height: 64px !important;
    background: #1e293b !important;
    color: #e5e7eb !important;
    border-bottom: 1px solid rgba(255,255,255,.08) !important;
    box-shadow: 0 10px 28px rgba(15,23,42,.16) !important;
}

body.visitor-theme .brand {
    color: #ffffff !important;
    font-size: 1.3rem !important;
}

body.visitor-theme .brand span {
    color: #3b82f6 !important;
}

body.visitor-theme .nav-btn {
    color: #e5e7eb !important;
    background: rgba(255,255,255,.03) !important;
    border: 1px solid rgba(255,255,255,.08) !important;
    border-radius: 14px !important;
    padding: 8px 13px !important;
}

body.visitor-theme .nav-btn:hover,
body.visitor-theme .nav-btn.active {
    color: #ffffff !important;
    background: rgba(59,130,246,.18) !important;
    border-color: rgba(96,165,250,.45) !important;
}

body.visitor-theme .profile-trigger {
    border: 1px solid rgba(96,165,250,.45) !important;
    background: rgba(59,130,246,.14) !important;
    color: #ffffff !important;
}

body.visitor-theme .profile-trigger:hover,
body.visitor-theme .profile-menu:hover .profile-trigger,
body.visitor-theme .profile-menu:focus-within .profile-trigger {
    background: rgba(59,130,246,.22) !important;
}

/* Resident notification page = resident theme */
body.resident-theme .navbar {
    background: rgba(255, 255, 255, 0.92) !important;
    border-bottom: 1px solid #e2e8f0 !important;
    box-shadow: none !important;
}

body.resident-theme .nav-links {
    gap: 10px !important;
}

@media (max-width: 1180px) {
    .profile-menu {
        width: auto;
    }
}

@media (max-width: 620px) {
    .profile-menu {
        width: 100%;
    }

    .profile-trigger {
        width: 100%;
        justify-content: center;
    }

    .profile-dropdown {
        right: auto;
        left: 0;
        width: min(292px, 100%);
    }
}
</style>


<style id="notifications-dashboard-style-v2">
    body.resident-theme,
    body.visitor-theme {
        min-height: 100vh !important;
        color: #0f172a !important;
        background: #eef6ff !important;
        overflow-x: hidden !important;
    }

    body.resident-theme::before,
    body.visitor-theme::before {
        content: "" !important;
        position: fixed !important;
        inset: 76px 0 0 0 !important;
        z-index: -5 !important;
        background:
            linear-gradient(105deg,
                rgba(255,255,255,.82) 0%,
                rgba(248,252,255,.66) 45%,
                rgba(218,239,255,.52) 100%
            ),
            url("lou.jpg") center/cover no-repeat !important;
    }

    body.resident-theme::after,
    body.visitor-theme::after {
        content: "" !important;
        position: fixed !important;
        inset: 76px 0 0 0 !important;
        z-index: -4 !important;
        pointer-events: none !important;
        backdrop-filter: blur(2px) !important;
        background:
            radial-gradient(circle at 12% 18%, rgba(37,99,235,.07), transparent 24%),
            radial-gradient(circle at 87% 23%, rgba(56,189,248,.16), transparent 25%),
            radial-gradient(circle at 84% 88%, rgba(37,99,235,.07), transparent 24%),
            linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.18)) !important;
    }

    .navbar {
        height: 76px !important;
        min-height: 76px !important;
        padding: 0 5.5% !important;
        background: rgba(255, 255, 255, .94) !important;
        border-bottom: 1px solid #dbe5f0 !important;
        box-shadow: none !important;
        align-items: center !important;
    }

    .brand {
        font-size: 1.55rem !important;
        line-height: 1 !important;
        font-weight: 900 !important;
        letter-spacing: -.045em !important;
    }

    .nav-links {
        gap: 12px !important;
        align-items: center !important;
    }

    .nav-btn {
        height: 44px !important;
        min-height: 44px !important;
        padding: 0 16px !important;
        border-radius: 999px !important;
        font-size: .84rem !important;
        font-weight: 900 !important;
        line-height: 1 !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 8px !important;
    }

    .nav-btn.active {
        background: #eff6ff !important;
        color: #2563eb !important;
        border: 1px solid #bfdbfe !important;
    }

    .profile-trigger {
        height: 44px !important;
        min-height: 44px !important;
        padding: 5px 13px 5px 7px !important;
        border-radius: 999px !important;
        font-size: .84rem !important;
        font-weight: 900 !important;
        gap: 9px !important;
    }

    .profile-avatar-mini {
        width: 34px !important;
        height: 34px !important;
        flex: 0 0 34px !important;
    }

    .notification-dashboard {
        width: min(1340px, calc(100% - 80px)) !important;
        min-height: calc(100vh - 76px) !important;
        margin: 0 auto !important;
        padding: 42px 0 34px !important;
    }

    .notification-screen {
        display: grid !important;
        grid-template-columns: minmax(0, 880px) 360px !important;
        justify-content: center !important;
        gap: 36px !important;
        align-items: start !important;
    }

    .left-panel,
    .right-panel {
        min-width: 0 !important;
        display: grid !important;
        align-content: start !important;
    }

    .left-panel {
        gap: 24px !important;
    }

    .right-panel {
        gap: 24px !important;
    }

    .welcome-panel {
        position: relative !important;
        overflow: hidden !important;
        min-height: 158px !important;
        padding: 30px 36px !important;
        border-radius: 30px !important;
        background: rgba(255, 255, 255, .88) !important;
        border: 1px solid #dbe5f0 !important;
        box-shadow: 0 22px 60px rgba(15, 23, 42, .08) !important;
        display: flex !important;
        align-items: center !important;
    }

    .welcome-panel::before {
        content: "" !important;
        position: absolute !important;
        inset: 0 !important;
        background:
            linear-gradient(90deg, rgba(255,255,255,.94), rgba(255,255,255,.62)),
            radial-gradient(circle at 92% 8%, rgba(59,130,246,.16), transparent 28%) !important;
        pointer-events: none !important;
    }

    .welcome-copy {
        position: relative !important;
        z-index: 1 !important;
    }

    .welcome-small {
        color: #2563eb !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 8px !important;
        font-size: .9rem !important;
        font-weight: 900 !important;
        margin-bottom: 6px !important;
    }

    .welcome-panel h1 {
        margin: 0 0 9px !important;
        color: #0b1220 !important;
        font-size: clamp(2.75rem, 3.6vw, 4rem) !important;
        line-height: .96 !important;
        letter-spacing: -.06em !important;
        font-weight: 900 !important;
    }

    .welcome-panel p {
        max-width: 700px !important;
        margin: 0 0 14px !important;
        color: #64748b !important;
        font-size: 1rem !important;
        font-weight: 720 !important;
        line-height: 1.45 !important;
    }

    .pill-row {
        display: flex !important;
        flex-wrap: wrap !important;
        gap: 10px !important;
    }

    .info-pill {
        height: 34px !important;
        padding: 0 14px !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 8px !important;
        border-radius: 999px !important;
        background: #eff6ff !important;
        color: #2563eb !important;
        border: 1px solid #bfdbfe !important;
        font-size: .8rem !important;
        font-weight: 900 !important;
    }

    .info-pill.green {
        background: #ecfdf3 !important;
        color: #16a34a !important;
        border-color: #bbf7d0 !important;
    }

    .info-pill.orange {
        background: #fff7ed !important;
        color: #f97316 !important;
        border-color: #fed7aa !important;
    }

    .stats-strip {
        height: 104px !important;
        min-height: 104px !important;
        padding: 14px 18px !important;
        border-radius: 26px !important;
        background: rgba(255, 255, 255, .92) !important;
        border: 1px solid #dbe5f0 !important;
        box-shadow: 0 22px 60px rgba(15, 23, 42, .08) !important;
        display: grid !important;
        grid-template-columns: repeat(4, 1fr) !important;
        align-items: center !important;
        overflow: hidden !important;
    }

    .mini-stat {
        height: 100% !important;
        min-width: 0 !important;
        display: flex !important;
        align-items: center !important;
        gap: 13px !important;
        padding: 0 16px !important;
        border-right: 1px solid #e5edf7 !important;
    }

    .mini-stat:last-child {
        border-right: 0 !important;
    }

    .stat-icon {
        width: 46px !important;
        height: 46px !important;
        flex: 0 0 46px !important;
        border-radius: 17px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        font-size: 1rem !important;
        border: 0 !important;
    }

    .stat-icon.blue {
        background: #eff6ff !important;
        color: #2563eb !important;
    }

    .stat-icon.orange {
        background: #fff7ed !important;
        color: #f97316 !important;
    }

    .stat-icon.green {
        background: #ecfdf3 !important;
        color: #16a34a !important;
    }

    .stat-icon.purple {
        background: #f3e8ff !important;
        color: #7c3aed !important;
    }

    .mini-stat .num {
        display: block !important;
        color: #0f172a !important;
        font-size: 1.65rem !important;
        line-height: 1 !important;
        font-weight: 900 !important;
        margin-bottom: 4px !important;
    }

    .mini-stat .label {
        display: block !important;
        color: #0f172a !important;
        font-size: .8rem !important;
        line-height: 1.13 !important;
        font-weight: 900 !important;
    }

    .mini-stat .sub {
        display: block !important;
        margin-top: 4px !important;
        color: #64748b !important;
        font-size: .7rem !important;
        line-height: 1.15 !important;
        font-weight: 800 !important;
    }

    .notification-panel {
        border-radius: 30px !important;
        background: rgba(255, 255, 255, .93) !important;
        border: 1px solid #dbe5f0 !important;
        box-shadow: 0 22px 60px rgba(15, 23, 42, .08) !important;
        overflow: hidden !important;
    }

    .panel-head {
        padding: 20px 24px !important;
        border-bottom: 1px solid #e5edf7 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 16px !important;
    }

    .panel-title {
        color: #0f172a !important;
        font-size: 1.15rem !important;
        font-weight: 900 !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 10px !important;
    }

    .panel-title i {
        color: #2563eb !important;
    }

    .panel-body {
        padding: 20px 24px 24px !important;
    }

    .empty {
        min-height: 170px !important;
        border-radius: 24px !important;
        border: 1px dashed #cbd5e1 !important;
        background: rgba(248, 250, 252, .80) !important;
        color: #64748b !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 8px !important;
        text-align: center !important;
        font-weight: 800 !important;
    }

    .empty-icon {
        width: 52px !important;
        height: 52px !important;
        border-radius: 18px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        background: #eff6ff !important;
        color: #2563eb !important;
        font-size: 1.1rem !important;
    }

    .empty strong {
        color: #64748b !important;
        font-weight: 900 !important;
    }

    .empty span {
        color: #94a3b8 !important;
        font-size: .86rem !important;
        font-weight: 750 !important;
    }

    .notification-list {
        display: grid !important;
        gap: 14px !important;
    }

    .notification-card {
        border-radius: 22px !important;
        background: rgba(255, 255, 255, .86) !important;
        border: 1px solid #dbe5f0 !important;
        box-shadow: 0 12px 30px rgba(15, 23, 42, .045) !important;
        padding: 18px !important;
        grid-template-columns: 54px 1fr auto !important;
    }

    .notification-card.unread {
        background: linear-gradient(135deg, rgba(239, 246, 255, .92), #ffffff 72%) !important;
        border-color: #bfdbfe !important;
    }

    .notif-icon {
        width: 54px !important;
        height: 54px !important;
        border-radius: 18px !important;
    }

    .resident-summary-card,
    .message-overview-card {
        background: rgba(255, 255, 255, .92) !important;
        border: 1px solid #dbe5f0 !important;
        box-shadow: 0 22px 60px rgba(15, 23, 42, .08) !important;
    }

    .resident-summary-card {
        min-height: 110px !important;
        padding: 17px !important;
        border-radius: 26px !important;
        display: flex !important;
        align-items: center !important;
        gap: 14px !important;
    }

    .summary-avatar {
        width: 64px !important;
        height: 64px !important;
        flex: 0 0 64px !important;
        border-radius: 21px !important;
        background: linear-gradient(135deg, #dbeafe, #bfdbfe) !important;
        color: #2563eb !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        font-size: 1.15rem !important;
        font-weight: 900 !important;
        overflow: hidden !important;
    }

    .summary-avatar img {
        width: 100% !important;
        height: 100% !important;
        object-fit: cover !important;
    }

    .resident-summary-card small {
        display: block !important;
        color: #64748b !important;
        font-size: .68rem !important;
        font-weight: 900 !important;
        text-transform: uppercase !important;
        letter-spacing: .08em !important;
        margin-bottom: 4px !important;
    }

    .resident-summary-card strong {
        display: block !important;
        color: #0f172a !important;
        font-size: .96rem !important;
        line-height: 1.18 !important;
        font-weight: 900 !important;
    }

    .resident-summary-card span {
        display: block !important;
        margin-top: 4px !important;
        color: #64748b !important;
        font-size: .76rem !important;
        font-weight: 800 !important;
    }

    .message-overview-card {
        padding: 24px !important;
        border-radius: 30px !important;
        display: flex !important;
        flex-direction: column !important;
        gap: 18px !important;
    }

    .overview-title {
        display: flex !important;
        align-items: center !important;
        gap: 14px !important;
    }

    .overview-icon {
        width: 52px !important;
        height: 52px !important;
        flex: 0 0 52px !important;
        border-radius: 18px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        background: linear-gradient(135deg, #38bdf8, #2563eb) !important;
        color: #fff !important;
        font-size: 1.05rem !important;
    }

    .overview-title h2 {
        margin: 0 !important;
        color: #0f172a !important;
        font-size: 1.15rem !important;
        font-weight: 900 !important;
        line-height: 1.2 !important;
    }

    .overview-title span {
        display: block !important;
        margin-top: 4px !important;
        color: #64748b !important;
        font-size: .76rem !important;
        font-weight: 800 !important;
    }

    .overview-list {
        display: grid !important;
        gap: 10px !important;
    }

    .overview-row {
        min-height: 62px !important;
        padding: 12px 13px !important;
        border-radius: 18px !important;
        background: rgba(255,255,255,.72) !important;
        border: 1px solid #e5edf7 !important;
        display: flex !important;
        align-items: center !important;
        gap: 12px !important;
    }

    .overview-row i {
        width: 36px !important;
        height: 36px !important;
        border-radius: 13px !important;
        background: #eff6ff !important;
        color: #2563eb !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
    }

    .overview-row small {
        display: block !important;
        color: #64748b !important;
        font-size: .68rem !important;
        font-weight: 900 !important;
        text-transform: uppercase !important;
        margin-bottom: 3px !important;
    }

    .overview-row strong {
        color: #0f172a !important;
        font-size: .84rem !important;
        font-weight: 900 !important;
        line-height: 1.18 !important;
    }

    .overview-form {
        margin-top: 4px !important;
    }

    .overview-btn,
    .btn-primary {
        background: linear-gradient(135deg, #38bdf8, #2563eb) !important;
        color: #ffffff !important;
        box-shadow: 0 16px 30px rgba(37, 99, 235, .22) !important;
    }

    .overview-btn {
        width: 100% !important;
        min-height: 52px !important;
        border: 0 !important;
        border-radius: 18px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 10px !important;
        cursor: pointer !important;
        font-size: .87rem !important;
        font-weight: 900 !important;
    }

    .summary-row,
    .summary-card,
    .page,
    .page-header,
    .user-badge {
        display: none !important;
    }

    @media (max-width: 1150px) {
        .notification-dashboard {
            width: calc(100% - 36px) !important;
        }

        .notification-screen {
            grid-template-columns: 1fr !important;
        }
    }

    @media (max-width: 760px) {
        .navbar {
            height: auto !important;
            padding: 16px 20px !important;
            align-items: flex-start !important;
            flex-direction: column !important;
        }

        body.resident-theme::before,
        body.visitor-theme::before,
        body.resident-theme::after,
        body.visitor-theme::after {
            inset: 0 !important;
        }

        .nav-links {
            width: 100% !important;
            flex-wrap: wrap !important;
            justify-content: flex-start !important;
        }

        .notification-dashboard {
            width: calc(100% - 24px) !important;
            padding-top: 24px !important;
        }

        .stats-strip {
            grid-template-columns: 1fr !important;
            height: auto !important;
        }

        .mini-stat {
            border-right: 0 !important;
            border-bottom: 1px solid #e5edf7 !important;
            padding: 12px 8px !important;
        }

        .mini-stat:last-child {
            border-bottom: 0 !important;
        }

        .panel-head {
            flex-direction: column !important;
            align-items: flex-start !important;
        }

        .notification-card {
            grid-template-columns: 54px 1fr !important;
        }

        .status-pill {
            grid-column: 2 !important;
            width: fit-content !important;
        }
    }
</style>


<style id="notifications-lou-final-polish">
    body.resident-theme::before,
    body.visitor-theme::before {
        background:
            linear-gradient(105deg,
                rgba(255,255,255,.84) 0%,
                rgba(248,252,255,.68) 43%,
                rgba(218,238,255,.54) 100%
            ),
            url("lou.jpg") center/cover no-repeat !important;
    }

    body.resident-theme::after,
    body.visitor-theme::after {
        backdrop-filter: blur(1.4px) !important;
        background:
            radial-gradient(circle at 12% 18%, rgba(37,99,235,.07), transparent 24%),
            radial-gradient(circle at 88% 22%, rgba(56,189,248,.14), transparent 25%),
            radial-gradient(circle at 82% 86%, rgba(37,99,235,.06), transparent 24%),
            linear-gradient(180deg, rgba(255,255,255,.02), rgba(255,255,255,.14)) !important;
    }

    .notification-dashboard {
        width: min(1340px, calc(100% - 80px)) !important;
        padding: 42px 0 32px !important;
    }

    .notification-screen {
        grid-template-columns: minmax(0, 880px) 360px !important;
        gap: 36px !important;
        align-items: start !important;
    }

    .left-panel {
        gap: 24px !important;
    }

    .right-panel {
        gap: 24px !important;
    }

    .welcome-panel {
        min-height: 158px !important;
        padding: 30px 36px !important;
        border-radius: 30px !important;
        background: rgba(255,255,255,.90) !important;
    }

    .welcome-panel h1 {
        font-size: clamp(2.75rem, 3.6vw, 4rem) !important;
        letter-spacing: -.06em !important;
    }

    .welcome-panel p {
        max-width: 720px !important;
    }

    .stats-strip {
        height: 104px !important;
        min-height: 104px !important;
        padding: 14px 18px !important;
        border-radius: 26px !important;
    }

    .notification-panel {
        border-radius: 30px !important;
    }

    .panel-body {
        padding: 20px 24px 24px !important;
    }

    .empty {
        min-height: 150px !important;
    }

    .resident-summary-card {
        min-height: 110px !important;
        border-radius: 26px !important;
    }

    .message-overview-card {
        min-height: 330px !important;
        border-radius: 30px !important;
    }

    .overview-list {
        gap: 10px !important;
    }

    .overview-row {
        min-height: 61px !important;
    }

    .overview-btn {
        min-height: 52px !important;
    }

    @media (max-width: 1150px) {
        .notification-dashboard {
            width: calc(100% - 36px) !important;
        }

        .notification-screen {
            grid-template-columns: 1fr !important;
        }
    }
</style>


<style id="profile-trigger-neutral-fix">
    .profile-trigger {
        background: rgba(255, 255, 255, .78) !important;
        color: #0f172a !important;
        border: 1px solid #dbe5f0 !important;
        box-shadow: 0 8px 22px rgba(15, 23, 42, .04) !important;
    }

    .profile-trigger:hover {
        background: #ffffff !important;
        color: #2563eb !important;
        border-color: #bfdbfe !important;
    }

    .profile-trigger[aria-expanded="true"],
    .profile-trigger.open,
    .profile-dropdown.open + .profile-trigger {
        background: rgba(255, 255, 255, .92) !important;
        color: #0f172a !important;
        border-color: #dbe5f0 !important;
    }

    .profile-avatar-mini {
        border: 2px solid #e5edf7 !important;
        background: #eff6ff !important;
        box-shadow: none !important;
    }

    .nav-btn.active {
        background: #eff6ff !important;
        color: #2563eb !important;
        border: 1px solid #bfdbfe !important;
        box-shadow: none !important;
    }
</style>



<style id="notifications-dashboard-match-v2">
    /* Match resident dashboard spacing and prevent the notification hero from being clipped */
    .notification-dashboard {
        width: min(1240px, calc(100% - 92px)) !important;
        min-height: calc(100vh - 76px) !important;
        margin: 0 auto !important;
        padding: 54px 0 44px !important;
    }

    .notification-screen {
        display: grid !important;
        grid-template-columns: minmax(0, 820px) 340px !important;
        justify-content: center !important;
        gap: 34px !important;
        align-items: start !important;
    }

    .left-panel,
    .right-panel {
        min-width: 0 !important;
        display: grid !important;
        align-content: start !important;
    }

    .left-panel {
        gap: 22px !important;
    }

    .right-panel {
        gap: 22px !important;
    }

    .welcome-panel {
        width: 100% !important;
        height: auto !important;
        min-height: 198px !important;
        padding: 34px 38px 32px !important;
        border-radius: 30px !important;
        overflow: hidden !important;
        display: flex !important;
        align-items: center !important;
        background: rgba(255, 255, 255, .91) !important;
        border: 1px solid #dbe5f0 !important;
        box-shadow: 0 22px 60px rgba(15, 23, 42, .08) !important;
    }

    .welcome-panel::before {
        background:
            linear-gradient(90deg, rgba(255,255,255,.96), rgba(255,255,255,.66)),
            radial-gradient(circle at 92% 8%, rgba(59,130,246,.15), transparent 30%) !important;
    }

    .welcome-copy {
        width: 100% !important;
        max-width: 760px !important;
    }

    .welcome-small {
        min-height: 30px !important;
        padding: 0 13px !important;
        margin: 0 0 10px !important;
        border-radius: 999px !important;
        background: #eff6ff !important;
        border: 1px solid #bfdbfe !important;
        color: #2563eb !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 8px !important;
        font-size: .78rem !important;
        font-weight: 900 !important;
        line-height: 1 !important;
    }

    .welcome-panel h1 {
        margin: 0 0 9px !important;
        color: #0b1220 !important;
        font-size: clamp(3rem, 3.9vw, 4.15rem) !important;
        line-height: .95 !important;
        letter-spacing: -.065em !important;
        font-weight: 950 !important;
    }

    .welcome-panel p {
        max-width: 720px !important;
        margin: 0 0 14px !important;
        color: #64748b !important;
        font-size: 1rem !important;
        font-weight: 760 !important;
        line-height: 1.42 !important;
    }

    .pill-row {
        display: flex !important;
        flex-wrap: wrap !important;
        gap: 10px !important;
        margin-top: 2px !important;
    }

    .info-pill {
        height: 34px !important;
        min-height: 34px !important;
        padding: 0 14px !important;
        border-radius: 999px !important;
        font-size: .78rem !important;
        font-weight: 900 !important;
    }

    .stats-strip {
        height: 96px !important;
        min-height: 96px !important;
        padding: 12px 16px !important;
        border-radius: 26px !important;
        background: rgba(255, 255, 255, .92) !important;
        border: 1px solid #dbe5f0 !important;
        box-shadow: 0 22px 60px rgba(15, 23, 42, .08) !important;
        display: grid !important;
        grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
        overflow: hidden !important;
    }

    .mini-stat {
        gap: 12px !important;
        padding: 0 14px !important;
        align-items: center !important;
    }

    .stat-icon {
        width: 44px !important;
        height: 44px !important;
        flex: 0 0 44px !important;
        border-radius: 16px !important;
    }

    .mini-stat .num {
        font-size: 1.58rem !important;
        line-height: 1 !important;
        margin-bottom: 4px !important;
    }

    .mini-stat .label {
        font-size: .78rem !important;
        line-height: 1.05 !important;
    }

    .mini-stat .sub {
        font-size: .7rem !important;
        line-height: 1.05 !important;
        margin-top: 4px !important;
    }

    .notification-panel {
        border-radius: 30px !important;
        background: rgba(255, 255, 255, .92) !important;
        border: 1px solid #dbe5f0 !important;
        box-shadow: 0 22px 60px rgba(15, 23, 42, .08) !important;
    }

    .notification-panel .panel-head {
        padding: 22px 28px !important;
    }

    .notification-panel .panel-body {
        padding: 20px 24px 24px !important;
    }

    .notification-list {
        gap: 16px !important;
    }

    .notification-card {
        border-radius: 22px !important;
        padding: 18px 18px !important;
        grid-template-columns: 54px minmax(0, 1fr) auto !important;
    }

    .resident-summary-card {
        min-height: 102px !important;
        border-radius: 26px !important;
        background: rgba(255, 255, 255, .90) !important;
        box-shadow: 0 18px 48px rgba(15, 23, 42, .08) !important;
    }

    .message-overview-card {
        min-height: 310px !important;
        border-radius: 30px !important;
        background: rgba(255, 255, 255, .91) !important;
        box-shadow: 0 22px 60px rgba(15, 23, 42, .08) !important;
    }

    .overview-row {
        min-height: 57px !important;
        border-radius: 17px !important;
    }

    .overview-btn {
        min-height: 52px !important;
        border-radius: 18px !important;
    }

    .nav-btn,
    .profile-trigger {
        height: 44px !important;
        min-height: 44px !important;
        border-radius: 999px !important;
        font-size: .84rem !important;
        font-weight: 900 !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
    }

    .profile-trigger {
        background: rgba(255, 255, 255, .78) !important;
        color: #0f172a !important;
        border: 1px solid #dbe5f0 !important;
        box-shadow: 0 8px 22px rgba(15, 23, 42, .04) !important;
    }

    .profile-trigger:hover {
        background: #ffffff !important;
        color: #2563eb !important;
        border-color: #bfdbfe !important;
    }

    .profile-avatar-mini {
        width: 34px !important;
        height: 34px !important;
        flex: 0 0 34px !important;
    }

    .nav-notification-badge {
        top: -7px !important;
        right: -5px !important;
        z-index: 4 !important;
    }

    @media (max-width: 1150px) {
        .notification-dashboard {
            width: calc(100% - 36px) !important;
            padding-top: 38px !important;
        }

        .notification-screen {
            grid-template-columns: 1fr !important;
        }

        .welcome-panel {
            min-height: 190px !important;
        }
    }

    @media (max-width: 760px) {
        .notification-dashboard {
            width: calc(100% - 24px) !important;
        }

        .stats-strip {
            height: auto !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        }

        .mini-stat {
            min-height: 78px !important;
            border-right: 0 !important;
        }

        .welcome-panel {
            padding: 28px 24px !important;
        }

        .notification-card {
            grid-template-columns: 48px 1fr !important;
        }

        .notification-actions {
            grid-column: 2 / -1 !important;
        }
    }
</style>


<style id="notifications-one-page-scroll-fix">
    /* Keep the page fixed to one screen. Only the notification records area scrolls. */
    html,
    body {
        height: 100% !important;
        overflow: hidden !important;
    }

    body {
        min-height: 100vh !important;
    }

    .navbar {
        height: 76px !important;
        flex: 0 0 76px !important;
    }

    .notification-dashboard {
        height: calc(100vh - 76px) !important;
        min-height: 0 !important;
        padding: 24px 0 24px !important;
        overflow: hidden !important;
    }

    .notification-screen {
        height: 100% !important;
        min-height: 0 !important;
        align-items: stretch !important;
    }

    .left-panel,
    .right-panel {
        height: 100% !important;
        min-height: 0 !important;
    }

    .left-panel {
        display: flex !important;
        flex-direction: column !important;
        gap: 18px !important;
        overflow: hidden !important;
    }

    .right-panel {
        overflow: hidden !important;
    }

    .welcome-panel {
        flex: 0 0 auto !important;
        min-height: 170px !important;
        padding-top: 28px !important;
        padding-bottom: 28px !important;
    }

    .stats-strip {
        flex: 0 0 86px !important;
        height: 86px !important;
        min-height: 86px !important;
    }

    .alert {
        flex: 0 0 auto !important;
        margin-bottom: 0 !important;
    }

    .notification-panel {
        flex: 1 1 auto !important;
        min-height: 0 !important;
        display: flex !important;
        flex-direction: column !important;
        overflow: hidden !important;
    }

    .notification-panel .panel-head {
        flex: 0 0 auto !important;
    }

    .notification-panel .panel-body {
        flex: 1 1 auto !important;
        min-height: 0 !important;
        overflow: hidden !important;
        padding-bottom: 18px !important;
    }

    .notification-list {
        height: 100% !important;
        min-height: 0 !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
        padding-right: 8px !important;
        overscroll-behavior: contain !important;
    }

    .notification-list::-webkit-scrollbar {
        width: 8px;
    }

    .notification-list::-webkit-scrollbar-track {
        background: #eff6ff;
        border-radius: 999px;
    }

    .notification-list::-webkit-scrollbar-thumb {
        background: #93c5fd;
        border-radius: 999px;
    }

    .notification-list::-webkit-scrollbar-thumb:hover {
        background: #60a5fa;
    }

    .empty {
        height: 100% !important;
        min-height: 180px !important;
    }

    @media (max-width: 1150px) {
        html,
        body {
            height: auto !important;
            overflow: auto !important;
        }

        .notification-dashboard,
        .notification-screen,
        .left-panel,
        .right-panel {
            height: auto !important;
            overflow: visible !important;
        }

        .notification-panel {
            max-height: none !important;
        }

        .notification-list {
            max-height: 520px !important;
        }
    }
</style>


<style id="notifications-remove-right-panel">
    /* Remove the right Message Overview / Resident card panel */
    .right-panel {
        display: none !important;
    }

    .notification-screen {
        grid-template-columns: minmax(0, 1fr) !important;
        max-width: 920px !important;
    }

    .left-panel {
        width: 100% !important;
    }

    @media (max-width: 1150px) {
        .notification-screen {
            max-width: 920px !important;
        }
    }
</style>


<style id="notifications-compact-header-and-auto-alert">
    /* Smaller top banner */
    .welcome-panel {
        min-height: 135px !important;
        padding: 24px 34px !important;
        border-radius: 26px !important;
    }

    .welcome-panel h1,
    .welcome-panel .page-title,
    .welcome-panel .hero-title {
        font-size: 3rem !important;
        line-height: 1.02 !important;
        margin-bottom: 10px !important;
    }

    /* Hide the explanation text under Notifications */
    .welcome-panel p,
    .welcome-panel .hero-subtitle,
    .welcome-panel .page-desc {
        display: none !important;
    }

    .welcome-panel .hero-meta,
    .welcome-panel .message-pills,
    .welcome-panel .summary-pills,
    .welcome-panel .pill-row {
        margin-top: 8px !important;
    }

    /* Green success alert compact */
    .alert,
    .success-alert,
    .flash-message,
    .notice-success {
        transition: opacity .35s ease, transform .35s ease, max-height .35s ease, margin .35s ease, padding .35s ease !important;
    }

    .alert.auto-hide-done,
    .success-alert.auto-hide-done,
    .flash-message.auto-hide-done,
    .notice-success.auto-hide-done {
        opacity: 0 !important;
        transform: translateY(-8px) !important;
        max-height: 0 !important;
        margin-top: 0 !important;
        margin-bottom: 0 !important;
        padding-top: 0 !important;
        padding-bottom: 0 !important;
        overflow: hidden !important;
        pointer-events: none !important;
    }
</style>
<script id="notifications-auto-hide-success-alert">
document.addEventListener('DOMContentLoaded', function () {
    const alerts = document.querySelectorAll('.alert, .success-alert, .flash-message, .notice-success');

    alerts.forEach(function (alertBox) {
        const text = (alertBox.textContent || '').toLowerCase();

        if (
            text.includes('marked as read') ||
            text.includes('success') ||
            text.includes('successfully')
        ) {
            setTimeout(function () {
                alertBox.classList.add('auto-hide-done');

                setTimeout(function () {
                    if (alertBox && alertBox.parentNode) {
                        alertBox.parentNode.removeChild(alertBox);
                    }
                }, 450);
            }, 3000);
        }
    });
});
</script>


<style id="notifications-hide-top-pills">
    /* Hide total messages / unread pills under Notifications banner */
    .welcome-panel .hero-meta,
    .welcome-panel .message-pills,
    .welcome-panel .summary-pills,
    .welcome-panel .pill-row,
    .welcome-panel .meta-pills,
    .welcome-panel .stat-pills,
    .welcome-panel .hero-badges {
        display: none !important;
    }
</style>


<style id="notifications-search-filter-style">
    .notification-filter-bar {
        flex: 0 0 auto;
        padding: 16px 22px;
        border-bottom: 1px solid var(--line);
        background: rgba(255, 255, 255, .92);
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .notification-filter-form {
        flex: 1;
        display: grid;
        grid-template-columns: minmax(0, 1fr) 190px 48px auto;
        gap: 10px;
        align-items: center;
    }

    .filter-input-wrap,
    .filter-date-wrap {
        height: 46px;
        border: 1px solid #dbeafe;
        border-radius: 16px;
        background: #ffffff;
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 0 14px;
        box-shadow: 0 8px 18px rgba(37, 99, 235, .05);
    }

    .filter-input-wrap i,
    .filter-date-wrap i {
        color: #2563eb;
        font-size: .9rem;
        flex: 0 0 auto;
    }

    .filter-input-wrap input,
    .filter-date-wrap input {
        width: 100%;
        height: 100%;
        border: 0;
        outline: 0;
        background: transparent;
        color: #0f172a;
        font-family: inherit;
        font-size: .84rem;
        font-weight: 850;
    }

    .filter-input-wrap input::placeholder {
        color: #94a3b8;
    }

    .filter-search-btn,
    .filter-reset-btn {
        height: 46px;
        width: 48px;
        border: 0;
        border-radius: 16px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        text-decoration: none;
        font-family: inherit;
        font-weight: 950;
    }

    .filter-search-btn {
        color: #ffffff;
        background: linear-gradient(135deg, #38bdf8, #2563eb);
        box-shadow: 0 12px 24px rgba(37, 99, 235, .18);
    }

    .filter-reset-btn {
        color: #2563eb;
        background: #eff6ff;
        border: 1px solid #dbeafe;
    }

    .filter-result-text {
        flex: 0 0 auto;
        color: #2563eb;
        background: #eff6ff;
        border: 1px solid #dbeafe;
        border-radius: 999px;
        padding: 9px 12px;
        font-size: .72rem;
        font-weight: 950;
        white-space: nowrap;
    }

    .notification-panel {
        display: flex;
        flex-direction: column;
    }

    .notification-panel .panel-body {
        min-height: 0;
    }

    @media (max-width: 900px) {
        .notification-filter-bar {
            align-items: stretch;
            flex-direction: column;
        }

        .notification-filter-form {
            grid-template-columns: 1fr;
        }

        .filter-search-btn,
        .filter-reset-btn {
            width: 100%;
        }
    }
</style>


<style id="notifications-message-date-calendar-style">
    .notification-date-picker-wrap {
        position: relative;
        padding: 0 12px !important;
    }

    .notification-date-trigger {
        width: 100%;
        height: 100%;
        border: 0;
        outline: 0;
        background: transparent;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        color: #0f172a;
        font-family: inherit;
        font-size: .84rem;
        font-weight: 900;
        cursor: pointer;
        text-align: left;
    }

    .notification-date-trigger i {
        color: #64748b;
        font-size: .82rem;
    }

    .notification-calendar-popover {
        position: absolute;
        top: calc(100% + 8px);
        right: 0;
        width: 292px;
        z-index: 10000;
        border: 1px solid #dbeafe;
        border-radius: 18px;
        background: rgba(255, 255, 255, .98);
        box-shadow: 0 22px 54px rgba(15, 23, 42, .18);
        padding: 14px;
        opacity: 0;
        transform: translateY(-6px) scale(.98);
        pointer-events: none;
        transition: .16s ease;
    }

    .notification-calendar-popover.show {
        opacity: 1;
        transform: translateY(0) scale(1);
        pointer-events: auto;
    }

    .notification-calendar-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 12px;
    }

    .notification-calendar-head strong {
        color: #0f172a;
        font-size: .86rem;
        font-weight: 950;
    }

    .notification-calendar-head button,
    .notification-calendar-footer button {
        border: 0;
        border-radius: 10px;
        background: #eff6ff;
        color: #2563eb;
        font-family: inherit;
        font-weight: 950;
        cursor: pointer;
    }

    .notification-calendar-head button {
        width: 34px;
        height: 34px;
        display: grid;
        place-items: center;
    }

    .notification-calendar-week,
    .notification-calendar-days {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 6px;
    }

    .notification-calendar-week {
        margin-bottom: 7px;
    }

    .notification-calendar-week span {
        text-align: center;
        color: #64748b;
        font-size: .68rem;
        font-weight: 950;
    }

    .notification-calendar-day {
        height: 32px;
        border: 0;
        border-radius: 10px;
        background: transparent;
        font-family: inherit;
        font-size: .76rem;
        font-weight: 900;
        display: grid;
        place-items: center;
        cursor: default;
    }

    .notification-calendar-day.has-record {
        color: #0f172a;
        background: #ffffff;
        cursor: pointer;
    }

    .notification-calendar-day.has-record:hover {
        color: #2563eb;
        background: #eff6ff;
    }

    .notification-calendar-day.no-record,
    .notification-calendar-day.other-month {
        color: #cbd5e1;
    }

    .notification-calendar-day.selected {
        color: #ffffff !important;
        background: #2563eb !important;
        box-shadow: 0 8px 18px rgba(37, 99, 235, .24);
    }

    .notification-calendar-day.today:not(.selected) {
        outline: 2px solid #bfdbfe;
        outline-offset: -2px;
    }

    .notification-calendar-footer {
        margin-top: 12px;
        padding-top: 10px;
        border-top: 1px solid #e5e7eb;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }

    .notification-calendar-footer button {
        height: 30px;
        padding: 0 11px;
        font-size: .72rem;
    }

    .notification-calendar-footer span {
        color: #94a3b8;
        font-size: .66rem;
        font-weight: 850;
        text-align: right;
    }
</style>


<style id="notifications-hide-filter-result">
    /* Hide "0 record(s) found" / filter result badge */
    .filter-result-text {
        display: none !important;
    }
</style>

</head>
<body class="<?= $isVisitor ? 'visitor-theme' : 'resident-theme' ?>">

<nav class="navbar">
    <?php if ($isResident): ?>
        <a href="resident.php" class="brand">Smart<span>VMS</span></a>
    <?php else: ?>
        <a href="visitor_book.php" class="brand">Smart<span>VMS</span></a>
    <?php endif; ?>

    <div class="nav-links">
        <?php if ($isResident): ?>
            <a href="resident.php" class="nav-btn">
                <i class="fas fa-th-large"></i>
                Dashboard
            </a>

            <a href="notifications.php" class="nav-btn active notification-nav-btn">
                <i class="fas fa-bell"></i>
                Notifications
                <?php if ($unreadNotifications > 0): ?>
                    <span class="nav-notification-badge">
                        <?= $unreadNotifications > 99 ? '99+' : (int)$unreadNotifications ?>
                    </span>
                <?php endif; ?>
            </a>
        <?php else: ?>
            <a href="visitor_book.php" class="nav-btn">
                <i class="fas fa-calendar-plus"></i>
                Book Visit
            </a>

            <a href="notifications.php" class="nav-btn active notification-nav-btn">
                <i class="fas fa-bell"></i>
                Notifications
                <?php if ($unreadNotifications > 0): ?>
                    <span class="nav-notification-badge">
                        <?= $unreadNotifications > 99 ? '99+' : (int)$unreadNotifications ?>
                    </span>
                <?php endif; ?>
            </a>

            <a href="visitor_history.php" class="nav-btn">
                <i class="fas fa-clock-rotate-left"></i>
                History
            </a>
        <?php endif; ?>

        <div class="profile-menu">
            <button type="button" class="profile-trigger" aria-label="Open profile menu">
                <span class="profile-avatar-mini">
                    <?php if ($profilePhotoUrl): ?>
                        <img src="<?= e($profilePhotoUrl) ?>" alt="Profile photo">
                    <?php else: ?>
                        <?= e($displayInitial) ?>
                    <?php endif; ?>
                </span>

                <span class="profile-trigger-name"><?= e($displayName) ?></span>
                <i class="fas fa-chevron-down"></i>
            </button>

            <div class="profile-dropdown">
                <div class="dropdown-head">
                    <div class="dropdown-avatar">
                        <?php if ($profilePhotoUrl): ?>
                            <img src="<?= e($profilePhotoUrl) ?>" alt="Profile photo">
                        <?php else: ?>
                            <?= e($displayInitial) ?>
                        <?php endif; ?>
                    </div>

                    <div>
                        <div class="dropdown-name"><?= e($displayName) ?></div>
                        <div class="dropdown-sub"><?= $isResident ? 'Resident Account' : 'Visitor Account' ?></div>
                    </div>
                </div>

                <div class="dropdown-links">
                    <a href="<?= $isResident ? 'resident_profile.php' : 'visitor_profile.php' ?>" class="dropdown-link">
                        <i class="fas fa-user"></i>
                        <strong>My Profile</strong>
                    </a>

                    <a href="<?= $isResident ? 'resident_settings.php' : 'visitor_settings.php' ?>" class="dropdown-link">
                        <i class="fas fa-lock"></i>
                        <strong>Change Password</strong>
                    </a>
                </div>

                <div class="dropdown-footer">
                    <a href="../core/logout.php" class="dropdown-link dropdown-logout">
                        <i class="fas fa-power-off"></i>
                        <strong>Logout</strong>
                    </a>
                </div>
            </div>
        </div>
    </div>
</nav>

<main class="notification-dashboard">
    <section class="notification-screen">
        <div class="left-panel">
            <section class="welcome-panel">
                <div class="welcome-copy">
                    <div class="welcome-small">
                        <i class="fas fa-bell"></i>
                        Message Center
                    </div>

                    <h1>Notifications</h1>
                    <p><?= e($heroSubtitle) ?></p>

                    <div class="pill-row">
                        <div class="info-pill">
                            <i class="fas fa-envelope-open-text"></i>
                            <?= (int)$totalNotifications ?> total messages
                        </div>

                        <div class="info-pill <?= $unreadNotifications > 0 ? 'orange' : 'green' ?>">
                            <i class="fas fa-circle"></i>
                            <?= (int)$unreadNotifications ?> unread
                        </div>
                    </div>
                </div>
            </section>

            <?php if ($message): ?>
                <div class="alert success"><?= e($message) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert error"><?= e($error) ?></div>
            <?php endif; ?>

            <section class="stats-strip">
                <div class="mini-stat">
                    <div class="stat-icon blue"><i class="fas fa-layer-group"></i></div>
                    <div>
                        <span class="num"><?= (int)$totalNotifications ?></span>
                        <span class="label">Total</span>
                        <span class="sub">All messages</span>
                    </div>
                </div>

                <div class="mini-stat">
                    <div class="stat-icon orange"><i class="fas fa-bell"></i></div>
                    <div>
                        <span class="num"><?= (int)$unreadNotifications ?></span>
                        <span class="label">Unread</span>
                        <span class="sub">Need checking</span>
                    </div>
                </div>

                <div class="mini-stat">
                    <div class="stat-icon green"><i class="fas fa-calendar-day"></i></div>
                    <div>
                        <span class="num"><?= (int)$todayNotifications ?></span>
                        <span class="label">Today</span>
                        <span class="sub">New today</span>
                    </div>
                </div>

                <div class="mini-stat">
                    <div class="stat-icon purple"><i class="fas fa-calendar-check"></i></div>
                    <div>
                        <span class="num"><?= (int)$bookingNotifications ?></span>
                        <span class="label">Booking</span>
                        <span class="sub">Visit updates</span>
                    </div>
                </div>
            </section>

            <section class="panel notification-panel">
                <div class="panel-head">
                    <div class="panel-title">
                        <i class="fas fa-inbox"></i>
                        All Notification Records
                    </div>

                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="mark_all_read">

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-check-double"></i>
                            Mark All Read
                        </button>
                    </form>
                </div>

                <div class="notification-filter-bar">
                    <form method="GET" class="notification-filter-form">
                        <div class="filter-input-wrap">
                            <i class="fas fa-magnifying-glass"></i>
                            <input
                                type="text"
                                name="search"
                                value="<?= e($searchKeyword) ?>"
                                placeholder="Search title, message, type..."
                                autocomplete="off"
                            >
                        </div>

                        <div class="filter-date-wrap notification-date-picker-wrap">
                            <i class="fas fa-calendar-days"></i>

                            <input
                                type="hidden"
                                name="date"
                                id="notificationDateValue"
                                value="<?= e($searchDate) ?>"
                            >

                            <button type="button" class="notification-date-trigger" id="notificationDateTrigger">
                                <span id="notificationDateText"><?= e($selectedDateDisplay) ?></span>
                                <i class="fas fa-calendar-day"></i>
                            </button>

                            <div class="notification-calendar-popover" id="notificationCalendarPopover" aria-hidden="true">
                                <div class="notification-calendar-head">
                                    <button type="button" id="notificationCalendarPrev" aria-label="Previous month">
                                        <i class="fas fa-chevron-left"></i>
                                    </button>

                                    <strong id="notificationCalendarMonth">Month YYYY</strong>

                                    <button type="button" id="notificationCalendarNext" aria-label="Next month">
                                        <i class="fas fa-chevron-right"></i>
                                    </button>
                                </div>

                                <div class="notification-calendar-week">
                                    <span>S</span>
                                    <span>M</span>
                                    <span>T</span>
                                    <span>W</span>
                                    <span>T</span>
                                    <span>F</span>
                                    <span>S</span>
                                </div>

                                <div class="notification-calendar-days" id="notificationCalendarDays"></div>

                                <div class="notification-calendar-footer">
                                    <button type="button" id="notificationCalendarClear">Clear</button>
                                    <span>Black dates have messages</span>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="filter-search-btn">
                            <i class="fas fa-search"></i>
                        </button>

                        <?php if ($hasNotificationFilter): ?>
                            <a href="notifications.php" class="filter-reset-btn" title="Reset filter">
                                <i class="fas fa-rotate-left"></i>
                            </a>
                        <?php endif; ?>
                    </form>

                    <?php if ($hasNotificationFilter): ?>
                        <div class="filter-result-text">
                            <?= (int)$filteredTotal ?> record(s) found
                        </div>
                    <?php endif; ?>
                </div>

                <div class="panel-body">
                    <?php if (empty($notifications)): ?>
                        <div class="empty">
                            <div class="empty-icon">
                                <i class="fas fa-check"></i>
                            </div>
                            <strong>No notification found.</strong>
                            <span>All notification records will appear here.</span>
                        </div>
                    <?php else: ?>
                        <div class="notification-list">
                            <?php foreach ($notifications as $notification): ?>
                                <?php
                                    $type = $notification['type'] ?: 'general';
                                    $isRead = (int)$notification['is_read'] === 1;
                                    $receiptLink = smartvms_extract_receipt_link((string)$notification['message']);
                                    $displayMessage = smartvms_remove_receipt_link_from_message((string)$notification['message']);
                                    $requestStatus = smartvms_request_status_info($pdo, $notification, $userId, $userRole);
                                    $pendingRequestLink = $requestStatus['link'] ?? null;
                                ?>

                                <article
                                    class="notification-card <?= $isRead ? '' : 'unread' ?> <?= $pendingRequestLink ? 'is-clickable' : 'is-locked' ?>"
                                    <?= $pendingRequestLink ? 'onclick="goToVisitorRequests(event, ' . e(json_encode($pendingRequestLink)) . ')" title="Click to review this pending visitor request"' : 'title="Only pending requests can be opened"' ?>
                                >
                                    <div class="notif-icon">
                                        <i class="fas <?= e(notification_icon($type)) ?>"></i>
                                    </div>

                                    <div>
                                        <div class="notif-title">
                                            <?= e(safe_text_notify($notification['title'])) ?>
                                        </div>

                                        <div class="notif-time">
                                            <?= e(date('d M Y, g:i A', strtotime($notification['created_at'] ?? 'now'))) ?>
                                        </div>

                                        <div class="notif-message">
                                            <?= e(safe_text_notify($displayMessage)) ?>
                                        </div>

                                        <div class="notif-actions">
                                            <?php if ($receiptLink): ?>
                                                <a href="<?= e($receiptLink) ?>" class="btn btn-view">
                                                    <i class="fas fa-qrcode"></i>
                                                    View Receipt
                                                </a>
                                            <?php endif; ?>

                                            <?php if (!$isRead): ?>
                                                <form method="POST">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="mark_read">
                                                    <input type="hidden" name="notification_id" value="<?= (int)$notification['id'] ?>">

                                                    <button type="submit" class="btn btn-primary">
                                                        Mark Read
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <form method="POST" onsubmit="return confirmDeleteNotification(event);">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="notification_id" value="<?= (int)$notification['id'] ?>">

                                                <button type="submit" class="btn btn-danger">
                                                    Delete
                                                </button>
                                            </form>
                                        </div>
                                    </div>

                                    <?php if ($requestStatus): ?>
                                        <div class="status-pill <?= e($requestStatus['class']) ?>">
                                            <?= e($requestStatus['label']) ?>
                                        </div>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($totalNotifications > 3): ?>
                            <div class="more-wrap">
                                <a href="notifications_record.php" class="btn btn-primary btn-more">
                                    More
                                    <i class="fas fa-arrow-right"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <aside class="right-panel">
            <div class="resident-summary-card">
                <div class="summary-avatar">
                    <?php if ($profilePhotoUrl): ?>
                        <img src="<?= e($profilePhotoUrl) ?>" alt="Profile photo">
                    <?php else: ?>
                        <?= e($displayInitial) ?>
                    <?php endif; ?>
                </div>

                <div>
                    <small><?= $isResident ? 'Resident' : 'Visitor' ?></small>
                    <strong><?= e($displayName) ?></strong>
                    <span><?= e($userEmail) ?></span>
                </div>
            </div>

            <div class="message-overview-card">
                <div class="overview-title">
                    <div class="overview-icon"><i class="fas fa-chart-simple"></i></div>
                    <div>
                        <h2>Message Overview</h2>
                        <span><?= $isResident ? 'Resident notifications' : 'Visitor notifications' ?></span>
                    </div>
                </div>

                <div class="overview-list">
                    <div class="overview-row">
                        <i class="fas fa-envelope-open"></i>
                        <div>
                            <small>Read</small>
                            <strong><?= (int)$readNotifications ?> message(s)</strong>
                        </div>
                    </div>

                    <div class="overview-row">
                        <i class="fas fa-shield-halved"></i>
                        <div>
                            <small>Security</small>
                            <strong><?= (int)$securityNotifications ?> alert(s)</strong>
                        </div>
                    </div>

                    <div class="overview-row">
                        <i class="fas fa-calendar-check"></i>
                        <div>
                            <small>Booking</small>
                            <strong><?= (int)$bookingNotifications ?> update(s)</strong>
                        </div>
                    </div>
                </div>

                <form method="POST" class="overview-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mark_all_read">

                    <button type="submit" class="overview-btn">
                        Mark All Read
                        <i class="fas fa-arrow-right"></i>
                    </button>
                </form>
            </div>
        </aside>
    </section>
</main>

<?php if ($message): ?>
<script>
Swal.fire({
    icon: 'success',
    title: 'Success',
    text: <?= json_encode($message) ?>,
    confirmButtonColor: '#2563eb',
    background: '#ffffff',
    color: '#0f172a'
});
</script>
<?php endif; ?>

<?php if ($error): ?>
<script>
Swal.fire({
    icon: 'error',
    title: 'Error',
    text: <?= json_encode($error) ?>,
    confirmButtonColor: '#2563eb',
    background: '#ffffff',
    color: '#0f172a'
});
</script>
<?php endif; ?>

<script>
function goToVisitorRequests(event, requestUrl) {
    const blocked = event.target.closest('a, button, form, input, select, textarea, label');

    if (blocked) {
        return;
    }

    if (requestUrl) {
        window.location.href = requestUrl;
    }
}

function confirmDeleteNotification(event) {
    event.preventDefault();

    Swal.fire({
        icon: 'warning',
        title: 'Delete notification?',
        text: 'This notification will be removed.',
        showCancelButton: true,
        confirmButtonText: 'Delete',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#94a3b8',
        background: '#ffffff',
        color: '#0f172a'
    }).then((result) => {
        if (result.isConfirmed) {
            event.target.submit();
        }
    });

    return false;
}
</script>


<script id="notifications-message-date-calendar-script">
document.addEventListener('DOMContentLoaded', function () {
    const availableDates = new Set(<?= json_encode($notificationAvailableDates, JSON_UNESCAPED_SLASHES) ?>);

    const hiddenInput = document.getElementById('notificationDateValue');
    const dateText = document.getElementById('notificationDateText');
    const trigger = document.getElementById('notificationDateTrigger');
    const popover = document.getElementById('notificationCalendarPopover');
    const monthLabel = document.getElementById('notificationCalendarMonth');
    const daysWrap = document.getElementById('notificationCalendarDays');
    const prevBtn = document.getElementById('notificationCalendarPrev');
    const nextBtn = document.getElementById('notificationCalendarNext');
    const clearBtn = document.getElementById('notificationCalendarClear');

    if (!hiddenInput || !dateText || !trigger || !popover || !monthLabel || !daysWrap) {
        return;
    }

    function pad(num) {
        return String(num).padStart(2, '0');
    }

    function toDateKey(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    }

    function toDisplay(dateKey) {
        if (!dateKey) return 'dd/mm/yyyy';

        const parts = dateKey.split('-');
        if (parts.length !== 3) return 'dd/mm/yyyy';

        return parts[2] + '/' + parts[1] + '/' + parts[0];
    }

    function parseDateKey(dateKey) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(dateKey || '')) {
            return null;
        }

        const parts = dateKey.split('-').map(Number);
        return new Date(parts[0], parts[1] - 1, parts[2]);
    }

    const selectedDate = parseDateKey(hiddenInput.value);
    const firstAvailable = Array.from(availableDates).sort()[0];
    const firstAvailableDate = parseDateKey(firstAvailable);
    let currentMonth = selectedDate || firstAvailableDate || new Date();

    function renderCalendar() {
        daysWrap.innerHTML = '';

        const year = currentMonth.getFullYear();
        const month = currentMonth.getMonth();
        const firstDay = new Date(year, month, 1);
        const startDay = firstDay.getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const previousMonthDays = new Date(year, month, 0).getDate();
        const todayKey = toDateKey(new Date());
        const selectedKey = hiddenInput.value;

        monthLabel.textContent = firstDay.toLocaleDateString('en-US', {
            month: 'long',
            year: 'numeric'
        });

        for (let i = 0; i < 42; i++) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'notification-calendar-day';

            let dayNumber;
            let date;

            if (i < startDay) {
                dayNumber = previousMonthDays - startDay + i + 1;
                date = new Date(year, month - 1, dayNumber);
                btn.classList.add('other-month');
            } else if (i >= startDay + daysInMonth) {
                dayNumber = i - startDay - daysInMonth + 1;
                date = new Date(year, month + 1, dayNumber);
                btn.classList.add('other-month');
            } else {
                dayNumber = i - startDay + 1;
                date = new Date(year, month, dayNumber);
            }

            const key = toDateKey(date);
            const isCurrentMonth = date.getMonth() === month;
            const hasRecord = availableDates.has(key);

            btn.textContent = dayNumber;
            btn.dataset.date = key;

            if (key === todayKey) {
                btn.classList.add('today');
            }

            if (key === selectedKey) {
                btn.classList.add('selected');
            }

            if (isCurrentMonth && hasRecord) {
                btn.classList.add('has-record');
                btn.addEventListener('click', function () {
                    hiddenInput.value = key;
                    dateText.textContent = toDisplay(key);
                    popover.classList.remove('show');
                });
            } else {
                btn.classList.add('no-record');
                btn.disabled = true;
                btn.title = 'No notification records on this date';
            }

            daysWrap.appendChild(btn);
        }
    }

    trigger.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();

        renderCalendar();
        popover.classList.toggle('show');
        popover.setAttribute('aria-hidden', popover.classList.contains('show') ? 'false' : 'true');
    });

    if (prevBtn) {
        prevBtn.addEventListener('click', function () {
            currentMonth = new Date(currentMonth.getFullYear(), currentMonth.getMonth() - 1, 1);
            renderCalendar();
        });
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', function () {
            currentMonth = new Date(currentMonth.getFullYear(), currentMonth.getMonth() + 1, 1);
            renderCalendar();
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            hiddenInput.value = '';
            dateText.textContent = 'dd/mm/yyyy';
            popover.classList.remove('show');
        });
    }

    document.addEventListener('click', function (event) {
        if (!event.target.closest('.notification-date-picker-wrap')) {
            popover.classList.remove('show');
            popover.setAttribute('aria-hidden', 'true');
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            popover.classList.remove('show');
            popover.setAttribute('aria-hidden', 'true');
        }
    });

    dateText.textContent = toDisplay(hiddenInput.value);
});
</script>

</body>
</html>
