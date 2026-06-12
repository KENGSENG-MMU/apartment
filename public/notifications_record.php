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

    header('Location: notifications_record.php');
    exit;
}

if (isset($_SESSION['notification_flash']) && is_array($_SESSION['notification_flash'])) {
    $message = $_SESSION['notification_flash']['message'] ?? '';
    $error = $_SESSION['notification_flash']['error'] ?? '';
    unset($_SESSION['notification_flash']);
}

$filterName = trim((string)($_GET['name'] ?? ''));
$filterDate = trim((string)($_GET['date'] ?? ''));

if ($filterDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $filterDate = '';
}

$whereSql = "WHERE user_id = ?";
$queryParams = [$userId];

if ($filterName !== '') {
    $whereSql .= " AND (title LIKE ? OR message LIKE ?)";
    $nameLike = '%' . $filterName . '%';
    $queryParams[] = $nameLike;
    $queryParams[] = $nameLike;
}

if ($filterDate !== '') {
    $whereSql .= " AND DATE(created_at) = ?";
    $queryParams[] = $filterDate;
}

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
    {$whereSql}
    ORDER BY created_at DESC
    LIMIT 120
");
$stmt->execute($queryParams);
$notifications = $stmt->fetchAll();

$filteredCount = safe_count_notify($pdo, "SELECT COUNT(*) FROM notifications {$whereSql}", $queryParams);
$filterActive = $filterName !== '' || $filterDate !== '';

$totalNotifications = safe_count_notify($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id = ?", [$userId]);
$unreadNotifications = safe_count_notify($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0", [$userId]);
$readNotifications = safe_count_notify($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 1", [$userId]);
$todayNotifications = safe_count_notify($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND DATE(created_at) = CURDATE()", [$userId]);
$bookingNotifications = safe_count_notify($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'booking'", [$userId]);
$securityNotifications = safe_count_notify($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'security'", [$userId]);

$isResident = $userRole === 'resident';
$isVisitor = $userRole === 'visitor';

$homeLink = $isResident ? 'resident.php' : 'visitor_book.php';
$homeLabel = $isResident ? 'Dashboard' : 'Book Visit';
$homeIcon = $isResident ? 'fa-home' : 'fa-calendar-plus';

$profileLink = $isResident ? 'resident_profile.php' : 'visitor_profile.php';
$requestsLink = 'resident_requests.php';

$headerClass = $isResident ? 'dark-navbar' : 'white-navbar';
$pageClass = $isResident ? 'resident-page' : 'visitor-page';

$heroTitle = 'Notification Record';
$heroSubtitle = 'View all previous notification records in SmartVMS.';
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
            --bg: #eef3f8;
            --card: #ffffff;
            --text: #111827;
            --muted: #667085;
            --border: #dfe8f3;
            --line: #edf0f3;
            --blue: #2563eb;
            --blue-soft: #eff6ff;
            --green: #16a34a;
            --green-soft: #ecfdf3;
            --amber: #d97706;
            --amber-soft: #fffbeb;
            --red: #dc2626;
            --red-soft: #fef3f2;
            --shadow: 0 20px 52px rgba(15, 23, 42, .08);
        }

        * {
            box-sizing: border-box;
            font-family: 'Plus Jakarta Sans', sans-serif;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            color: var(--text);
            background:
                radial-gradient(circle at 18% 18%, rgba(203, 213, 225, .28), transparent 28%),
                radial-gradient(circle at 85% 20%, rgba(219, 234, 254, .55), transparent 30%),
                linear-gradient(180deg, #fbfdff 0%, var(--bg) 100%);
        }

        a {
            text-decoration: none;
        }

        .navbar {
            width: 100%;
            padding: 14px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .brand {
            font-size: 1.15rem;
            font-weight: 900;
            letter-spacing: -.045em;
            white-space: nowrap;
        }

        .brand span {
            color: var(--blue);
        }

        .nav-links {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 9px;
            flex-wrap: wrap;
        }

        .nav-links a,
        .nav-links button {
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: .78rem;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: .18s ease;
            cursor: pointer;
            border: 1px solid transparent;
        }

        .nav-links a:hover,
        .nav-links button:hover {
            transform: translateY(-1px);
        }

        .white-navbar {
            background: rgba(255, 255, 255, .95);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border);
            box-shadow: 0 10px 30px rgba(15, 23, 42, .05);
        }

        .white-navbar .brand {
            color: var(--text);
        }

        .white-navbar .nav-links a,
        .white-navbar .nav-links button {
            color: #334155;
            background: #ffffff;
            border-color: var(--border);
            box-shadow: 0 6px 16px rgba(15, 23, 42, .04);
        }

        .white-navbar .nav-links a.active,
        .white-navbar .nav-links button.active {
            border-color: #bfdbfe;
            background: var(--blue-soft);
            color: #1d4ed8;
        }

        .dark-navbar {
            background: #111111;
            border-bottom: 1px solid rgba(255, 255, 255, .08);
            box-shadow: 0 10px 28px rgba(0, 0, 0, .18);
        }

        .dark-navbar .brand {
            color: #ffffff;
        }

        .dark-navbar .nav-links a,
        .dark-navbar .nav-links button {
            color: #ffffff;
            background: rgba(255, 255, 255, .08);
            border-color: rgba(255, 255, 255, .14);
        }

        .dark-navbar .nav-links a.active,
        .dark-navbar .nav-links button.active {
            background: rgba(37, 99, 235, .24);
            border-color: rgba(37, 99, 235, .55);
        }

        .nav-links a.logout {
            color: #ef4444;
        }


        /* ===== Your custom role headers ===== */
        .resident-topbar {
            width: 100%;
            height: 76px;
            background: linear-gradient(90deg, #151924, #2e3444);
            padding: 0 5%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 10px 28px rgba(0, 0, 0, .18);
        }

        .resident-brand {
            color: #ffffff;
            font-size: 1.55rem;
            font-weight: 900;
            letter-spacing: -.055em;
            white-space: nowrap;
        }

        .resident-brand span {
            color: #2f80ff;
        }

        .resident-nav {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 28px;
            flex-wrap: wrap;
        }

        .resident-nav a {
            color: #e5edf8;
            text-decoration: none;
            font-size: .88rem;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: .18s ease;
        }

        .resident-nav a:hover,
        .resident-nav a.active {
            color: #ffffff;
        }

        .resident-nav a.logout {
            color: #ff5b5b;
        }

        .visitor-topbar {
            width: 100%;
            min-height: 64px;
            background: rgba(255,255,255,.96);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid #dfe8f3;
            padding: 12px 5%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 10px 30px rgba(15,23,42,.05);
        }

        .visitor-brand {
            color: #111827;
            font-size: 1.15rem;
            font-weight: 900;
            letter-spacing: -.045em;
            white-space: nowrap;
        }

        .visitor-brand span {
            color: #2563eb;
        }

        .visitor-nav {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 9px;
            flex-wrap: wrap;
        }

        .visitor-nav a {
            position: relative;
            color: #334155;
            text-decoration: none;
            background: #ffffff;
            border: 1px solid #dfe8f3;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: .78rem;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            box-shadow: 0 6px 16px rgba(15,23,42,.04);
            transition: .18s ease;
        }

        .visitor-nav a:hover {
            transform: translateY(-1px);
            background: #f8fafc;
        }

        .visitor-nav a.active {
            background: #eff6ff;
            color: #1d4ed8;
            border-color: #bfdbfe;
        }

        .visitor-nav a.logout {
            color: #ef4444;
        }

        .visitor-badge {
            position: absolute;
            top: -8px;
            right: -5px;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            border-radius: 999px;
            background: #ef4444;
            color: #ffffff;
            font-size: .65rem;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #ffffff;
            line-height: 1;
        }

        @media (max-width: 760px) {
            .resident-topbar,
            .visitor-topbar {
                height: auto;
                min-height: auto;
                flex-direction: column;
                align-items: flex-start;
                padding: 14px 5%;
            }

            .resident-nav,
            .visitor-nav {
                width: 100%;
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 9px;
            }

            .resident-nav a,
            .visitor-nav a {
                justify-content: center;
                text-align: center;
            }
        }


        .page {
            width: min(1120px, calc(100% - 36px));
            margin: 28px auto 70px;
        }

        .hero {
            background: linear-gradient(135deg, #111827 0%, #17324d 52%, #1d4ed8 100%);
            color: white;
            border-radius: 30px;
            padding: 28px;
            box-shadow: var(--shadow);
            margin-bottom: 18px;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 22px;
            align-items: stretch;
            position: relative;
            overflow: hidden;
        }

        .hero::after {
            content: "";
            position: absolute;
            width: 240px;
            height: 240px;
            border-radius: 999px;
            right: -80px;
            top: -90px;
            background: rgba(59, 130, 246, .22);
        }

        .hero-content,
        .login-card {
            position: relative;
            z-index: 1;
        }

        .hero-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.16);
            border-radius: 999px;
            padding: 7px 11px;
            font-size: .72rem;
            font-weight: 900;
            letter-spacing: .07em;
            text-transform: uppercase;
            margin-bottom: 14px;
            color: #dbeafe;
        }

        .hero h1 {
            font-size: clamp(2rem, 4vw, 3rem);
            line-height: 1.03;
            letter-spacing: -.07em;
            font-weight: 900;
            margin-bottom: 10px;
        }

        .hero p {
            color: rgba(255,255,255,.82);
            font-size: .96rem;
            font-weight: 700;
            line-height: 1.65;
            max-width: 700px;
        }

        .login-card {
            min-width: 280px;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.18);
            border-radius: 22px;
            padding: 18px;
            align-self: stretch;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-label {
            font-size: .7rem;
            font-weight: 900;
            color: rgba(255,255,255,.68);
            text-transform: uppercase;
            letter-spacing: .08em;
            margin-bottom: 7px;
        }

        .login-value {
            font-weight: 900;
            line-height: 1.45;
        }

        .login-role {
            color: rgba(255,255,255,.74);
            font-size: .82rem;
            font-weight: 750;
            margin-top: 5px;
        }

        .summary-row {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }

        .summary-card {
            background: rgba(255,255,255,.97);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 15px;
            box-shadow: 0 12px 28px rgba(15,23,42,.05);
        }

        .summary-num {
            font-size: 1.5rem;
            font-weight: 900;
            letter-spacing: -.05em;
            margin-bottom: 5px;
        }

        .summary-label {
            color: var(--muted);
            font-size: .64rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .alert {
            padding: 14px 15px;
            border-radius: 16px;
            margin-bottom: 16px;
            font-weight: 850;
            line-height: 1.45;
        }

        .alert.success {
            background: var(--green-soft);
            color: #027a48;
            border: 1px solid #abefc6;
        }

        .alert.error {
            background: var(--red-soft);
            color: #b42318;
            border: 1px solid #fecdca;
        }

        .panel {
            background: rgba(255,255,255,.97);
            border: 1px solid var(--border);
            border-radius: 26px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .panel-head {
            padding: 18px 20px;
            border-bottom: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
        }

        .panel-title {
            font-size: 1rem;
            font-weight: 900;
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .panel-title i {
            color: var(--blue);
        }

        .panel-body {
            padding: 20px;
        }

        .head-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .btn {
            border: none;
            cursor: pointer;
            padding: 12px 15px;
            border-radius: 14px;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: .83rem;
            transition: .18s ease;
            white-space: nowrap;
            text-decoration: none;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            background: linear-gradient(135deg, #38bdf8, #2563eb);
            color: #ffffff;
            box-shadow: 0 14px 26px rgba(37, 99, 235, .18);
        }

        .btn-light {
            background: #ffffff;
            border: 1px solid var(--border);
            color: #111827;
        }

        .btn-danger {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
        }

        .btn-view {
            background: #ecfdf3;
            border: 1px solid #86efac;
            color: #166534;
        }

        .record-filter {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 16px;
            margin-bottom: 16px;
            display: grid;
            grid-template-columns: 1fr 220px auto auto;
            gap: 12px;
            align-items: end;
        }

        .filter-field label {
            display: block;
            color: var(--muted);
            font-size: .67rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .07em;
            margin-bottom: 7px;
        }

        .filter-field input {
            width: 100%;
            height: 46px;
            border: 1px solid #d7e2ee;
            border-radius: 14px;
            background: #ffffff;
            padding: 0 14px;
            color: #111827;
            font-size: .86rem;
            font-weight: 800;
            outline: none;
            transition: .18s ease;
        }

        .filter-field input:focus {
            border-color: #60a5fa;
            box-shadow: 0 0 0 4px rgba(96, 165, 250, .16);
        }

        .filter-note {
            margin: 0 0 16px;
            padding: 12px 14px;
            border-radius: 16px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1d4ed8;
            font-size: .82rem;
            font-weight: 900;
        }

        .notification-list {
            display: grid;
            gap: 12px;
        }

        .notification-card {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 16px;
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 14px;
            align-items: flex-start;
            position: relative;
        }

        .notification-card.unread {
            background: #f3f8ff;
            border-color: #bfdbfe;
            box-shadow: inset 4px 0 0 #2563eb;
        }

        .notif-icon {
            width: 44px;
            height: 44px;
            border-radius: 15px;
            background: #e0ecff;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex: 0 0 auto;
        }

        .notif-title {
            font-size: .98rem;
            font-weight: 900;
            margin-bottom: 4px;
            line-height: 1.35;
        }

        .notif-time {
            color: var(--muted);
            font-size: .75rem;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .notif-message {
            color: #334155;
            font-size: .88rem;
            font-weight: 700;
            line-height: 1.55;
            margin-bottom: 13px;
        }

        .notif-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .type-badge {
            padding: 6px 10px;
            border-radius: 999px;
            font-size: .65rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .04em;
            white-space: nowrap;
        }

        .type-booking {
            background: #dcfce7;
            color: #166534;
        }

        .type-security {
            background: #fee2e2;
            color: #991b1b;
        }

        .type-parking {
            background: #e0f2fe;
            color: #075985;
        }

        .type-system {
            background: #f3e8ff;
            color: #6b21a8;
        }

        .type-general {
            background: #f1f5f9;
            color: #475569;
        }

        .empty {
            padding: 48px 22px;
            text-align: center;
            color: var(--muted);
            font-weight: 850;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 20px;
        }

        @media (max-width: 1080px) {
            .hero,
            .summary-row {
                grid-template-columns: 1fr;
            }

            .login-card {
                min-width: 0;
            }
        }

        @media (max-width: 720px) {
            .navbar {
                flex-direction: column;
                align-items: flex-start;
            }

            .nav-links {
                width: 100%;
                display: grid;
                grid-template-columns: 1fr 1fr;
            }

            .nav-links a,
            .nav-links button {
                justify-content: center;
                text-align: center;
            }

            .panel-head {
                flex-direction: column;
                align-items: stretch;
            }

            .record-filter {
                grid-template-columns: 1fr;
            }

            .notification-card {
                grid-template-columns: 1fr;
            }

            .btn {
                width: 100%;
            }
        }
    
        .notification-card {
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease, opacity .18s ease;
        }

        .notification-card.is-clickable {
            cursor: pointer;
        }

        .notification-card.is-clickable:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 45px rgba(37,99,235,.12);
            border-color: rgba(37,99,235,.35);
        }

        .notification-card.is-locked {
            cursor: default;
        }

        .status-pill {
            min-width: 92px;
            padding: 7px 12px;
            border-radius: 999px;
            border: 1px solid transparent;
            font-size: .7rem;
            font-weight: 900;
            text-align: center;
            line-height: 1;
            align-self: flex-start;
            box-shadow: 0 8px 18px rgba(15, 23, 42, .05);
        }

        .status-pending {
            background: #fff7ed;
            border-color: #fed7aa;
            color: #c2410c;
        }

        .status-approved {
            background: #ecfdf3;
            border-color: #bbf7d0;
            color: #15803d;
        }

        .status-rejected {
            background: #fef2f2;
            border-color: #fecaca;
            color: #dc2626;
        }


    </style>

<style id="smartvms-unified-header-style">

/* =========================================================
   SmartVMS Unified Header Style
   Purpose: Keep all page headers / navbars same size and color
   ========================================================= */
:root {
    --svms-header-bg: #1f2937;
    --svms-header-border: rgba(255, 255, 255, 0.08);
    --svms-header-text: #e5e7eb;
    --svms-header-muted: #cbd5e1;
    --svms-header-hover: rgba(59, 130, 246, 0.12);
    --svms-blue: #3b82f6;
    --svms-blue-dark: #2563eb;
    --svms-red: #ef4444;
}

body {
    font-family: 'Plus Jakarta Sans', sans-serif;
}

/* Top navbar: resident / visitor / admin common */
.navbar,
.visitor-navbar,
.resident-topbar,
.admin-top-nav,
.topbar,
header.navbar,
header.visitor-navbar {
    height: 64px !important;
    min-height: 64px !important;
    width: 100% !important;
    padding: 0 5% !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    background: var(--svms-header-bg) !important;
    color: var(--svms-header-text) !important;
    border-bottom: 1px solid var(--svms-header-border) !important;
    box-shadow: 0 10px 28px rgba(15, 23, 42, 0.12) !important;
    position: sticky !important;
    top: 0 !important;
    z-index: 1000 !important;
}

/* Logo / brand */
.brand,
.logo,
.resident-brand,
.admin-brand,
.navbar .brand,
.visitor-navbar .logo,
.resident-topbar .resident-brand {
    font-size: 1.35rem !important;
    line-height: 1 !important;
    font-weight: 900 !important;
    letter-spacing: -0.04em !important;
    color: #ffffff !important;
    white-space: nowrap !important;
}

.brand span,
.logo span,
.resident-brand span,
.admin-brand span {
    color: var(--svms-blue) !important;
}

/* Nav group */
.nav-links,
.resident-nav,
.admin-nav-links,
.navbar .nav-links,
.visitor-navbar .nav-links,
.resident-topbar .resident-nav {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 10px !important;
    flex-wrap: nowrap !important;
}

/* Nav links / buttons */
.nav-btn,
.nav-links a,
.resident-nav a,
.admin-top-nav a,
.admin-nav-links a,
.visitor-navbar .nav-links a,
.navbar .nav-links a,
.resident-topbar .resident-nav a {
    min-height: 38px !important;
    padding: 9px 15px !important;
    border-radius: 12px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    font-size: 0.86rem !important;
    line-height: 1 !important;
    font-weight: 800 !important;
    text-decoration: none !important;
    color: var(--svms-header-muted) !important;
    background: transparent !important;
    border: 1px solid transparent !important;
    transition: 0.2s ease !important;
    white-space: nowrap !important;
}

.nav-btn i,
.nav-links a i,
.resident-nav a i,
.admin-top-nav a i,
.admin-nav-links a i,
.visitor-navbar .nav-links a i,
.navbar .nav-links a i,
.resident-topbar .resident-nav a i {
    font-size: 0.9rem !important;
    line-height: 1 !important;
}

.nav-btn:hover,
.nav-links a:hover,
.resident-nav a:hover,
.admin-top-nav a:hover,
.admin-nav-links a:hover,
.visitor-navbar .nav-links a:hover,
.navbar .nav-links a:hover,
.resident-topbar .resident-nav a:hover,
.nav-btn.active,
.nav-links a.active,
.resident-nav a.active,
.admin-nav-links a.active,
.visitor-navbar .nav-links a.active,
.navbar .nav-links a.active,
.resident-topbar .resident-nav a.active {
    color: #ffffff !important;
    background: var(--svms-header-hover) !important;
    border-color: rgba(96, 165, 250, 0.38) !important;
}

.nav-btn.logout,
.nav-links a.logout,
.resident-nav a.logout,
.admin-nav-links a.logout,
.navbar .nav-links a[href*="logout"],
.visitor-navbar .nav-links a[href*="logout"],
.resident-topbar .resident-nav a[href*="logout"] {
    color: var(--svms-red) !important;
}

/* Notification badge remains same size everywhere */
.nav-notification-badge,
.notification-badge,
.badge-notification {
    min-width: 20px !important;
    height: 20px !important;
    padding: 0 6px !important;
    border-radius: 999px !important;
    background: var(--svms-red) !important;
    color: #ffffff !important;
    font-size: 0.68rem !important;
    font-weight: 900 !important;
    line-height: 20px !important;
    text-align: center !important;
}

/* Main page hero title/subtitle standard */
.banner-info h1,
.hero-title,
.page-title,
.page-hero h1,
.hero h1,
.banner h1,
.request-hero h1,
.profile-hero h1,
.top-hero h1 {
    font-size: clamp(2rem, 3vw, 2.8rem) !important;
    line-height: 1.08 !important;
    font-weight: 900 !important;
    letter-spacing: -0.06em !important;
    color: #ffffff !important;
}

.banner-info p,
.hero-subtitle,
.page-subtitle,
.page-hero p,
.hero p,
.banner p,
.request-hero p,
.profile-hero p,
.top-hero p {
    font-size: 1rem !important;
    line-height: 1.65 !important;
    font-weight: 600 !important;
    color: rgba(255, 255, 255, 0.82) !important;
}

/* Responsive header */
@media (max-width: 760px) {
    .navbar,
    .visitor-navbar,
    .resident-topbar,
    .admin-top-nav,
    .topbar {
        height: auto !important;
        min-height: 64px !important;
        padding: 12px 18px !important;
        gap: 12px !important;
        flex-wrap: wrap !important;
    }

    .nav-links,
    .resident-nav,
    .admin-nav-links {
        width: 100% !important;
        justify-content: flex-start !important;
        overflow-x: auto !important;
        padding-bottom: 3px !important;
    }

    .brand,
    .logo,
    .resident-brand,
    .admin-brand {
        font-size: 1.22rem !important;
    }

    .nav-btn,
    .nav-links a,
    .resident-nav a,
    .admin-nav-links a {
        font-size: 0.78rem !important;
        padding: 8px 12px !important;
    }
}

</style>
</head>
<body class="<?= e($pageClass) ?>">

<?php if ($isResident): ?>
<nav class="resident-topbar">
    <div class="resident-brand">Smart<span>VMS</span></div>

    <div class="resident-nav">
        <a href="resident.php">
            <i class="fas fa-th-large"></i>
            Dashboard
        </a>

        <a href="notifications.php" class="active">
            <i class="fas fa-bell"></i>
            Notifications<?= $unreadNotifications > 0 ? ' (' . (int)$unreadNotifications . ')' : '' ?>
        </a>

        <a href="../core/logout.php" class="logout">
            <i class="fas fa-power-off"></i>
            Logout
        </a>
    </div>
</nav>
<?php else: ?>
<nav class="visitor-topbar">
    <div class="visitor-brand">Smart<span>VMS</span></div>

    <div class="visitor-nav">
        <a href="visitor_book.php">
            <i class="fas fa-calendar-plus"></i>
            Book Visit
        </a>

        <a href="notifications.php" class="active">
            <i class="fas fa-bell"></i>
            Notifications
            <?php if ($unreadNotifications > 0): ?>
                <span class="visitor-badge"><?= (int)$unreadNotifications ?></span>
            <?php endif; ?>
        </a>

        <a href="visitor_history.php">
            <i class="fas fa-clock-rotate-left"></i>
            History
        </a>

        <a href="visitor_profile.php">
            <i class="fas fa-user"></i>
            Profile
        </a>

        <a href="../core/logout.php" class="logout">
            <i class="fas fa-sign-out-alt"></i>
            Logout
        </a>
    </div>
</nav>
<?php endif; ?>

<main class="page">
    <section class="hero">
        <div class="hero-content">
            <div class="hero-kicker">
                <i class="fas fa-bell"></i>
                Message Center
            </div>

            <h1><?= e($heroTitle) ?></h1>

            <p><?= e($heroSubtitle) ?></p>
        </div>

        <div class="login-card">
            <div class="login-label">Logged in as</div>
            <div class="login-value"><?= e($userEmail) ?></div>
            <div class="login-role">Role: <?= e($userRole) ?></div>
        </div>
    </section>

    <?php if ($message): ?>
        <div class="alert success"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="summary-row">
        <div class="summary-card">
            <div class="summary-num"><?= (int)$totalNotifications ?></div>
            <div class="summary-label">Total Notifications</div>
        </div>

        <div class="summary-card">
            <div class="summary-num" style="color:var(--blue);"><?= (int)$unreadNotifications ?></div>
            <div class="summary-label">Unread</div>
        </div>

        <div class="summary-card">
            <div class="summary-num"><?= (int)$readNotifications ?></div>
            <div class="summary-label">Read</div>
        </div>

        <div class="summary-card">
            <div class="summary-num" style="color:var(--green);"><?= (int)$todayNotifications ?></div>
            <div class="summary-label">Today</div>
        </div>

        <div class="summary-card">
            <div class="summary-num" style="color:var(--green);"><?= (int)$bookingNotifications ?></div>
            <div class="summary-label">Booking</div>
        </div>

        <div class="summary-card">
            <div class="summary-num" style="color:var(--red);"><?= (int)$securityNotifications ?></div>
            <div class="summary-label">Security</div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div class="panel-title">
                <i class="fas fa-inbox"></i>
                All Notification Records
            </div>

            <div class="head-actions">
                <a href="notifications.php" class="btn btn-light">
                    <i class="fas fa-arrow-left"></i>
                    Back
                </a>

                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mark_all_read">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check-double"></i>
                        Mark All Read
                    </button>
                </form>
            </div>
        </div>

        <div class="panel-body">
            <form method="GET" class="record-filter">
                <div class="filter-field">
                    <label>Search name / message</label>
                    <input
                        type="text"
                        name="name"
                        value="<?= e($filterName) ?>"
                        placeholder="Example: visitor name, plate number"
                    >
                </div>

                <div class="filter-field">
                    <label>Search date</label>
                    <input
                        type="date"
                        name="date"
                        value="<?= e($filterDate) ?>"
                    >
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-magnifying-glass"></i>
                    Search
                </button>

                <a href="notifications_record.php" class="btn btn-light">
                    Reset
                </a>
            </form>

            <?php if ($filterActive): ?>
                <div class="filter-note">
                    Showing <?= (int)$filteredCount ?> record(s) for your search.
                </div>
            <?php endif; ?>

            <?php if (empty($notifications)): ?>
                <div class="empty">
                    No notification found.
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
            <?php endif; ?>
        </div>
    </section>
</main>

<?php if ($message): ?>
<script>
Swal.fire({
    icon: 'success',
    title: 'Success',
    text: <?= json_encode($message) ?>,
    confirmButtonColor: '#2563eb'
});
</script>
<?php endif; ?>

<?php if ($error): ?>
<script>
Swal.fire({
    icon: 'error',
    title: 'Error',
    text: <?= json_encode($error) ?>,
    confirmButtonColor: '#2563eb'
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
        confirmButtonColor: '#dc2626'
    }).then((result) => {
        if (result.isConfirmed) {
            event.target.submit();
        }
    });

    return false;
}
</script>

</body>
</html>
