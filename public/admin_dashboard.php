<?php
require_once '../core/security.php';
require_login(['admin', 'superadmin']);

$pdo = db();

if (file_exists('../core/parking_auto.php')) {
    require_once '../core/parking_auto.php';

    if (function_exists('run_parking_automation')) {
        run_parking_automation($pdo);
    }
}

function has_column_dashboard_v2(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.COLUMNS\n            WHERE TABLE_SCHEMA = DATABASE()\n            AND TABLE_NAME = ?\n            AND COLUMN_NAME = ?\n        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function safe_count_dashboard_v2(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function safe_rows_dashboard_v2(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function dash_percent_v2(int $part, int $total): int {
    return $total > 0 ? (int)round(($part / $total) * 100) : 0;
}

function dash_short_date_v2($value): string {
    if (!$value) {
        return '-';
    }

    $time = strtotime($value);
    return $time ? date('d M Y, g:i A', $time) : '-';
}

$hasFullName = has_column_dashboard_v2($pdo, 'users', 'full_name');

$currentEmail = $_SESSION['email'] ?? 'admin';
$currentRole = $_SESSION['role'] ?? 'admin';
$currentUserId = (int)($_SESSION['uid'] ?? 0);
$currentApartmentId = $_SESSION['apartment_id'] ?? null;

if (($currentApartmentId === null || $currentApartmentId === '') && $currentUserId > 0 && $currentRole !== 'superadmin') {
    try {
        $stmt = $pdo->prepare("SELECT apartment_id FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$currentUserId]);
        $dbApartmentId = $stmt->fetchColumn();

        if ($dbApartmentId !== false && $dbApartmentId !== null && $dbApartmentId !== '') {
            $currentApartmentId = (int)$dbApartmentId;
            $_SESSION['apartment_id'] = $currentApartmentId;
        }
    } catch (Throwable $e) {
        $currentApartmentId = null;
    }
}

$currentApartmentName = 'No Apartment Assigned';
$currentApartmentLabel = 'Apartment';

if ($currentRole === 'superadmin') {
    $currentApartmentName = 'All Apartments';
    $currentApartmentLabel = 'Superadmin View';
} elseif (!empty($currentApartmentId)) {
    try {
        $stmt = $pdo->prepare("SELECT apartment_name FROM apartments WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$currentApartmentId]);
        $apartmentRow = $stmt->fetch();

        if ($apartmentRow) {
            $currentApartmentName = $apartmentRow['apartment_name'];
        }
    } catch (Throwable $e) {
        $currentApartmentName = 'Apartment ID ' . (int)$currentApartmentId;
    }
}

$profileInitial = strtoupper(substr(trim($currentEmail ?: 'A'), 0, 1));
if ($profileInitial === '') {
    $profileInitial = 'A';
}

$totalResidents = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM users WHERE role = 'resident'");
$activeResidents = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM users WHERE role = 'resident' AND status = 'active'");
$totalVisitors = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM users WHERE role = 'visitor'");
$activeVisitorPasses = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM bookings WHERE status IN ('approved','allocated','waiting','checked_in')");
$bookingTodayWhereParts = [];
if (has_column_dashboard_v2($pdo, 'bookings', 'start_time')) {
    $bookingTodayWhereParts[] = "DATE(start_time) = CURDATE()";
}
if (has_column_dashboard_v2($pdo, 'bookings', 'start_datetime')) {
    $bookingTodayWhereParts[] = "DATE(start_datetime) = CURDATE()";
}
if (has_column_dashboard_v2($pdo, 'bookings', 'visit_date')) {
    $bookingTodayWhereParts[] = "DATE(visit_date) = CURDATE()";
}

$bookingTodayWhere = $bookingTodayWhereParts ? '(' . implode(' OR ', $bookingTodayWhereParts) . ')' : '1 = 0';

$todayVisits = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM bookings WHERE $bookingTodayWhere");
$todayWaitingBookings = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM bookings WHERE $bookingTodayWhere AND status IN ('approved','allocated','waiting')");
$todayCheckedInBookings = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM bookings WHERE $bookingTodayWhere AND status IN ('checked_in')");
$todayCompletedBookings = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM bookings WHERE $bookingTodayWhere AND status IN ('completed','checked_out')");

$totalVisitorSlots = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM parking_slots WHERE slot_type = 'Visitor'");
$availableVisitorSlots = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM parking_slots WHERE slot_type = 'Visitor' AND status = 'available'");
$reservedVisitorSlots = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM parking_slots WHERE slot_type = 'Visitor' AND status IN ('reserved','assigned')");
$occupiedVisitorSlots = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM parking_slots WHERE slot_type = 'Visitor' AND status IN ('occupied','car_inside')");
$maintenanceVisitorSlots = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM parking_slots WHERE slot_type = 'Visitor' AND status = 'maintenance'");
$visitorSlotUsagePercent = dash_percent_v2($reservedVisitorSlots + $occupiedVisitorSlots, $totalVisitorSlots);

$todayGateLogs = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM gate_logs WHERE DATE(created_at) = CURDATE()");
$todayAllowed = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM gate_logs WHERE DATE(created_at) = CURDATE() AND decision = 'ALLOW'");
$todayDenied = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM gate_logs WHERE DATE(created_at) = CURDATE() AND decision = 'DENY'");
$totalGateLogs = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM gate_logs");
$activeBlacklist = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM blacklisted_plates WHERE status = 'active'");

$currentBillingMonth = date('Y-m');
$pendingParkingRequests = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM resident_parking_requests WHERE status = 'pending'");
$pendingParkingPayments = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM parking_payments WHERE payment_status = 'pending_verification'");
$paidParkingThisMonth = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM parking_payments WHERE billing_month = ? AND payment_status = 'paid'", [$currentBillingMonth]);
$unpaidParkingThisMonth = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM parking_payments WHERE billing_month = ? AND payment_status IN ('unpaid','overdue','rejected')", [$currentBillingMonth]);
$totalResidentSlots = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM parking_slots WHERE slot_type = 'Resident'");
$availableResidentSlots = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM parking_slots WHERE slot_type = 'Resident' AND status = 'available'");
$assignedResidentSlots = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM resident_parking_assignments WHERE status = 'active'");
$residentSlotUsagePercent = dash_percent_v2($assignedResidentSlots, $totalResidentSlots);

$pendingResidents = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM users WHERE role = 'resident' AND status = 'pending'");
$overstayVisitors = safe_count_dashboard_v2($pdo, "\n    SELECT COUNT(*)\n    FROM bookings\n    WHERE status = 'checked_in'\n    AND end_time < NOW()\n");
$unreadNotifications = safe_count_dashboard_v2($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0", [$currentUserId]);

$latestNotifications = safe_rows_dashboard_v2($pdo, "
    SELECT id, title, message, type, link_url, is_read, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 5
", [$currentUserId]);

$userNameSql = $hasFullName ? "u.full_name AS user_full_name" : "NULL AS user_full_name";
$latestActivity = safe_rows_dashboard_v2($pdo, "\n    SELECT al.action, al.details, al.created_at, {$userNameSql}, u.email AS user_email\n    FROM audit_logs al\n    LEFT JOIN users u ON u.id = al.user_id\n    ORDER BY al.created_at DESC\n    LIMIT 4\n");


$visitorAvailablePercent = dash_percent_v2($availableVisitorSlots, max(1, $totalVisitorSlots));
$visitorReservedPercent = dash_percent_v2($reservedVisitorSlots, max(1, $totalVisitorSlots));
$visitorOccupiedPercent = dash_percent_v2($occupiedVisitorSlots, max(1, $totalVisitorSlots));
$visitorMaintenancePercent = max(0, 100 - $visitorAvailablePercent - $visitorReservedPercent - $visitorOccupiedPercent);
$visitorReservedStart = $visitorAvailablePercent;
$visitorOccupiedStart = $visitorAvailablePercent + $visitorReservedPercent;
$visitorMaintenanceStart = $visitorAvailablePercent + $visitorReservedPercent + $visitorOccupiedPercent;

$paymentTotalThisMonth = max(1, (int)$paidParkingThisMonth + (int)$unpaidParkingThisMonth + (int)$pendingParkingPayments);
$paymentPaidPercent = dash_percent_v2((int)$paidParkingThisMonth, $paymentTotalThisMonth);
$paymentUnpaidPercent = dash_percent_v2((int)$unpaidParkingThisMonth, $paymentTotalThisMonth);
$paymentPendingPercent = max(0, 100 - $paymentPaidPercent - $paymentUnpaidPercent);
$paymentCollectionPercent = dash_percent_v2((int)$paidParkingThisMonth, $paymentTotalThisMonth);

$gateTrend = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime('-' . $i . ' days'));
    $gateTrend[$day] = [
        'label' => date('D', strtotime($day)),
        'allowed' => 0,
        'denied' => 0,
    ];
}

$trendRows = safe_rows_dashboard_v2($pdo, "
    SELECT DATE(created_at) AS log_day, decision, COUNT(*) AS total
    FROM gate_logs
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(created_at), decision
");

foreach ($trendRows as $row) {
    $day = (string)($row['log_day'] ?? '');
    if (!isset($gateTrend[$day])) {
        continue;
    }

    $decision = strtoupper((string)($row['decision'] ?? ''));
    if ($decision === 'ALLOW' || $decision === 'ALLOWED') {
        $gateTrend[$day]['allowed'] = (int)$row['total'];
    } elseif ($decision === 'DENY' || $decision === 'DENIED') {
        $gateTrend[$day]['denied'] = (int)$row['total'];
    }
}

$maxGateTrend = 1;
foreach ($gateTrend as $trend) {
    $maxGateTrend = max($maxGateTrend, (int)$trend['allowed'], (int)$trend['denied']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        :root {
            --primary: #dc2626;
            --primary-dark: #991b1b;
            --primary-soft: #fee2e2;
            --primary-soft-2: #fff1f2;
            --text: #091127;
            --muted: #667085;
            --soft: #94a3b8;
            --border: #e8edf5;
            --card: rgba(255, 255, 255, 0.94);
            --green: #4c9b70;
            --blue: #5a78d6;
            --orange: #d58b47;
            --shadow: 0 22px 55px rgba(15, 23, 42, 0.08);
            --shadow-soft: 0 12px 32px rgba(15, 23, 42, 0.06);
        }
        * { box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; margin: 0; padding: 0; }
        html, body { height: 100%; overflow: hidden; }
        body {
            background:
                radial-gradient(circle at 17% 7%, rgba(220, 38, 38, .07), transparent 28%),
                radial-gradient(circle at 84% 8%, rgba(220, 38, 38, .10), transparent 25%),
                linear-gradient(135deg, #ffffff 0%, #f7f9fd 48%, #eef3fa 100%);
            color: var(--text);
        }
        a { color: inherit; }
        .dashboard-shell { display: grid; grid-template-columns: 260px minmax(0,1fr); height: 100vh; overflow: hidden; }
        .main { min-width: 0; height: 100vh; overflow: hidden; padding: 22px 30px 20px; display: grid; grid-template-rows: auto auto auto 1fr; gap: 14px; }
        .topbar { display: flex; align-items: center; justify-content: space-between; gap: 18px; min-height: 48px; }
        .page-title { font-size: 1.75rem; line-height: 1; font-weight: 950; letter-spacing: -0.075em; }
        .page-subtitle { margin-top: 5px; color: #61708a; font-size: .82rem; font-weight: 800; }
        .top-actions { display: flex; align-items: center; gap: 10px; }
        .search-pill { width: 285px; height: 42px; border-radius: 999px; background: rgba(255,255,255,.92); border: 1px solid var(--border); box-shadow: var(--shadow-soft); display: flex; align-items: center; gap: 11px; padding: 0 14px; color: #64748b; font-size: .8rem; font-weight: 850; }
        .search-pill input { flex: 1; min-width: 0; border: 0; outline: none; background: transparent; color: #334155; font-size: .8rem; font-weight: 850; }
        .search-pill input::placeholder { color: #64748b; opacity: .9; }
        .search-submit { width: 26px; height: 26px; border: 0; border-radius: 50%; background: #fcf6f6; color: var(--primary); display: grid; place-items: center; cursor: pointer; transition: .18s ease; }
        .search-submit:hover { background: var(--primary); color: white; }

        .search-pill { position: relative; }
        .search-suggest {
            position: absolute;
            top: 52px;
            right: 0;
            width: 390px;
            max-height: 430px;
            overflow: hidden;
            border: 1px solid var(--border);
            background: rgba(255,255,255,.98);
            border-radius: 22px;
            box-shadow: 0 24px 60px rgba(15,23,42,.18);
            padding: 12px;
            z-index: 80;
            display: none;
        }
        .search-pill.open .search-suggest {
            display: block;
        }
        .suggest-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: #94a3b8;
            font-size: .66rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .08em;
            margin: 2px 4px 8px;
        }
        .suggest-keyword {
            max-width: 170px;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            color: var(--primary);
        }
        .suggest-list {
            display: grid;
            gap: 6px;
        }
        .suggest-item {
            width: 100%;
            border: 0;
            background: transparent;
            border-radius: 16px;
            padding: 11px 12px;
            display: grid;
            grid-template-columns: 38px 1fr;
            gap: 11px;
            align-items: center;
            text-align: left;
            cursor: pointer;
            transition: .16s ease;
            color: #0f172a;
        }
        .suggest-item:hover,
        .suggest-item.active {
            background: #fcf6f6;
            transform: translateY(-1px);
        }
        .suggest-icon {
            width: 38px;
            height: 38px;
            border-radius: 14px;
            background: #fcf6f6;
            color: var(--primary);
            display: grid;
            place-items: center;
            font-size: .95rem;
        }
        .suggest-title {
            font-size: .82rem;
            font-weight: 950;
            line-height: 1.2;
        }
        .suggest-sub { display: none; }
        .suggest-go { display: none; }
        .suggest-empty {
            padding: 24px 16px;
            text-align: center;
            color: #94a3b8;
            font-size: .78rem;
            font-weight: 850;
        }

        .top-round { width: 42px; height: 42px; border-radius: 50%; border: 1px solid var(--border); background: rgba(255,255,255,.9); box-shadow: var(--shadow-soft); color: #64748b; display: grid; place-items: center; text-decoration: none; position: relative; cursor: pointer; }
        .top-round:hover { color: var(--primary); transform: translateY(-1px); }
        .notify-dot { position: absolute; right: 9px; top: 8px; width: 8px; height: 8px; background: var(--primary); border-radius: 50%; border: 2px solid white; }
        .notify-count { position: absolute; right: -3px; top: -5px; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: var(--primary); color: white; border: 2px solid white; font-size: .62rem; font-weight: 950; display: grid; place-items: center; line-height: 1; }
        .notify-menu { position: relative; }
        .notify-dropdown { display: none; position: absolute; right: 0; top: 52px; width: 360px; max-height: 430px; overflow: hidden; border: 1px solid var(--border); background: rgba(255,255,255,.98); border-radius: 22px; box-shadow: 0 24px 60px rgba(15,23,42,.18); padding: 12px; z-index: 60; }
        .notify-menu.open .notify-dropdown { display: block; }
        .notify-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 6px 6px 10px; border-bottom: 1px solid var(--border); }
        .notify-head strong { font-size: .9rem; font-weight: 950; }
        .notify-badge { background: #fcf6f6; color: var(--primary); border-radius: 999px; padding: 5px 8px; font-size: .66rem; font-weight: 950; }
        .notify-list { max-height: 300px; overflow-y: auto; padding: 6px 0; display: grid; gap: 6px; }
        .notify-item { display: grid; grid-template-columns: 36px 1fr; gap: 10px; text-decoration: none; padding: 10px; border-radius: 16px; color: var(--text); border: 1px solid transparent; }
        .notify-item:hover { background: #f8fafc; border-color: var(--border); }
        .notify-item.unread { background: #fcf6f6; border-color: #fecaca; }
        .notify-icon { width: 36px; height: 36px; border-radius: 14px; background: #f6e7e7; color: var(--primary); display: grid; place-items: center; }
        .notify-title { font-size: .78rem; font-weight: 950; line-height: 1.25; }
        .notify-message { margin-top: 3px; color: #64748b; font-size: .7rem; font-weight: 750; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .notify-time { margin-top: 5px; color: #94a3b8; font-size: .64rem; font-weight: 900; }
        .notify-empty { padding: 22px 10px; text-align: center; color: #64748b; font-size: .78rem; font-weight: 850; }
        .notify-view-all { display: flex; align-items: center; justify-content: center; gap: 7px; margin-top: 4px; padding: 10px; border-radius: 14px; background: #fcf6f6; color: var(--primary); text-decoration: none; font-size: .76rem; font-weight: 950; }
        .profile-menu { position: relative; }
        .profile-trigger { width: 42px; height: 42px; border: 0; border-radius: 50%; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; font-weight: 950; box-shadow: 0 12px 24px rgba(220,38,38,.24); cursor: pointer; }
        .profile-dropdown { display: none; position: absolute; right: 0; top: 52px; width: 265px; border: 1px solid var(--border); background: white; border-radius: 22px; box-shadow: 0 24px 55px rgba(15,23,42,.18); padding: 14px; z-index: 50; }
        .profile-menu.open .profile-dropdown { display: block; }
        .profile-email { font-size: .82rem; font-weight: 950; word-break: break-word; }
        .profile-role { margin-top: 4px; color: #64748b; font-size: .68rem; font-weight: 900; text-transform: uppercase; }
        .profile-apartment { margin-top: 12px; padding: 11px; border-radius: 16px; background: #fcf6f6; color: #64748b; font-size: .75rem; font-weight: 800; }
        .profile-apartment strong { color: #111827; }
        .profile-action { margin-top: 10px; text-decoration: none; display: flex; align-items: center; gap: 8px; color: #991b1b; background: #fcf6f6; border-radius: 14px; padding: 10px 11px; font-size: .78rem; font-weight: 950; }
        .hero-card { min-height: 215px; border: 1px solid rgba(254,202,202,.8); background: radial-gradient(circle at 30% 12%, rgba(254, 202, 202, .55), transparent 35%), linear-gradient(135deg, rgba(255,255,255,.97), rgba(255,241,242,.88)); border-radius: 30px; box-shadow: var(--shadow); padding: 24px 28px; display: grid; grid-template-columns: 1fr 1.08fr 360px; gap: 22px; overflow: hidden; position: relative; }
        .hero-card:after { content:""; position:absolute; right: 290px; bottom: 32px; width: 300px; height: 88px; opacity:.35; background-image: radial-gradient(#fecaca 1.5px, transparent 1.5px); background-size: 17px 17px; }
        .hero-kicker { color: var(--primary); font-weight: 950; text-transform: uppercase; letter-spacing: .16em; font-size: .68rem; }
        .hero-title { margin-top: 12px; font-size: 1.8rem; line-height: 1.05; font-weight: 950; letter-spacing: -.07em; }
        .hero-copy { margin-top: 11px; color: #52637c; font-weight: 750; line-height: 1.55; max-width: 410px; font-size: .9rem; }
        .red-btn { margin-top: 18px; display: inline-flex; align-items: center; gap: 9px; padding: 13px 18px; background: linear-gradient(135deg,var(--primary),var(--primary-dark)); color: white; text-decoration: none; border-radius: 16px; font-weight: 950; font-size: .84rem; box-shadow: 0 12px 24px rgba(220,38,38,.24); }
        .hero-center { position: relative; z-index: 1; display: grid; align-content: center; }
        .plate-stat-label { color: #64748b; text-transform: uppercase; letter-spacing: .1em; font-size: .74rem; font-weight: 950; }
        .plate-stat-number { margin-top: 4px; font-size: 3.15rem; font-weight: 950; letter-spacing: -.08em; line-height: 1; }
        .plate-stat-sub { color: var(--primary); font-size: .78rem; font-weight: 950; }
        .hero-line { margin-top: 8px; width: 100%; height: 88px; }
        .hero-car-wrap { position: relative; z-index: 1; display: grid; place-items: center; }
        .car-shell { width: 340px; height: 190px; position: relative; display: grid; place-items: center; }
        .scan-corner { position:absolute; width:46px; height:46px; border-color: var(--primary); opacity:.95; }
        .scan-corner.tl{left:4px;top:20px;border-left:3px solid;border-top:3px solid;border-radius:14px 0 0 0}.scan-corner.tr{right:4px;top:20px;border-right:3px solid;border-top:3px solid;border-radius:0 14px 0 0}.scan-corner.bl{left:4px;bottom:20px;border-left:3px solid;border-bottom:3px solid;border-radius:0 0 0 14px}.scan-corner.br{right:4px;bottom:20px;border-right:3px solid;border-bottom:3px solid;border-radius:0 0 14px 0}
        .hero-car-svg { width: 300px; height: 150px; overflow: visible; filter: drop-shadow(0 18px 24px rgba(15,23,42,.12)); }
        .car-outline { stroke:#101828; stroke-width:6; stroke-linejoin:round; stroke-linecap:round; fill:none; }
        .car-body-fill { fill:#ff6a1a; }
        .car-window-fill { fill:#12a4f5; }
        .car-wheel-fill { fill:#d9e2f0; }
        .car-wheel-center { fill:#111827; }
        .scan-beam { position:absolute; left:22px; right:22px; top:95px; height:2px; background:linear-gradient(90deg,transparent,#ef4444,transparent); opacity:.45; }
        .plate-tag { position:absolute; left:50%; top:88px; transform:translateX(-50%); background:white; border:3px solid var(--primary); border-radius:6px; padding:5px 12px; font-size:.85rem; font-weight:950; letter-spacing:.08em; color:#1f2937; box-shadow:0 8px 18px rgba(15,23,42,.08); }
        .kpi-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; }
        .kpi-card { text-decoration:none; border:1px solid var(--border); background:var(--card); border-radius:24px; min-height:86px; padding:16px; box-shadow:var(--shadow-soft); display:flex; align-items:center; justify-content:space-between; gap:13px; }
        .kpi-left { display:flex; align-items:center; gap:13px; min-width:0; }
        .kpi-icon { width:48px;height:48px;border-radius:18px;display:grid;place-items:center;background:#fff1f2;color:var(--primary);font-size:1.05rem;flex:0 0 auto; }
        .kpi-name { font-size:.83rem; font-weight:950; line-height:1.2; }
        .kpi-value { margin-top:4px; font-size:1.55rem; line-height:1; color:var(--primary); font-weight:950; letter-spacing:-.06em; }
        .kpi-hint { margin-top:3px; color:#64748b; font-size:.7rem; font-weight:850; }
        .kpi-arrow { width:32px;height:32px;border-radius:50%;border:1px solid var(--border);display:grid;place-items:center;color:#94a3b8;background:white; }
        .bottom-grid { min-height:0; display:grid; grid-template-columns: 1.05fr 1.05fr .92fr; gap:14px; }
        .panel { min-height:0; overflow:hidden; border:1px solid var(--border); background:rgba(255,255,255,.94); border-radius:24px; box-shadow:var(--shadow-soft); display:flex; flex-direction:column; }
        .panel-head { height:50px; padding:0 18px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; gap:12px; flex:0 0 auto; }
        .panel-title { display:flex; align-items:center; gap:9px; font-weight:950; letter-spacing:-.04em; }
        .panel-title i { color:var(--primary); }
        .panel-link { text-decoration:none; color:var(--primary); font-size:.75rem; font-weight:950; white-space:nowrap; }
        .panel-body { flex:1; min-height:0; padding:16px; }
        .mini-label { color:#64748b; font-size:.62rem; font-weight:950; text-transform:uppercase; letter-spacing:.03em; }
        .mini-number { font-weight:950; font-size:1.05rem; line-height:1; letter-spacing:-.05em; }
        .metric-chip { border:1px solid var(--border); background:#fff; border-radius:16px; padding:11px 12px; }
        .metric-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
        .legend { display:flex; flex-wrap:wrap; gap:8px 12px; align-items:center; color:#64748b; font-size:.68rem; font-weight:900; }
        .dot { width:9px;height:9px;border-radius:50%;display:inline-block;margin-right:5px; }
        @media (max-width: 1300px) { html, body { overflow:auto; } .dashboard-shell { height:auto; min-height:100vh; } .main { height:auto; overflow:visible; } .hero-card { grid-template-columns:1fr; } .kpi-grid,.bottom-grid{ grid-template-columns:1fr 1fr; } }
        @media (max-width: 760px) { .dashboard-shell,.kpi-grid,.bottom-grid{ grid-template-columns:1fr; } .topbar,.top-actions{ flex-direction:column; align-items:stretch; } .search-pill{ width:100%; } }
    </style>



<style>
    /* 100% zoom compact fix for the 3 bottom dashboard panels */
    .main {
        padding: 18px 24px 16px !important;
        gap: 12px !important;
    }
    .hero-card {
        min-height: 195px !important;
        padding: 20px 24px !important;
        gap: 18px !important;
    }
    .hero-title { font-size: 1.65rem !important; margin-top: 10px !important; }
    .hero-copy { margin-top: 8px !important; font-size: .86rem !important; line-height: 1.45 !important; }
    .red-btn { margin-top: 14px !important; padding: 11px 16px !important; }
    .plate-stat-number { font-size: 2.8rem !important; }
    .hero-line { height: 74px !important; }
    .car-shell { transform: scale(.9); transform-origin: center center; }

    .kpi-grid { gap: 12px !important; }
    .kpi-card {
        min-height: 76px !important;
        padding: 13px 14px !important;
        border-radius: 22px !important;
    }
    .kpi-icon { width: 42px !important; height: 42px !important; border-radius: 15px !important; }
    .kpi-name { font-size: .8rem !important; }
    .kpi-value { font-size: 1.35rem !important; }
    .kpi-hint { font-size: .66rem !important; }

    .bottom-grid {
        grid-template-columns: 1fr 1fr .9fr !important;
        gap: 12px !important;
    }
    .panel { border-radius: 22px !important; }
    .panel-head {
        height: 46px !important;
        padding: 0 16px !important;
    }
    .panel-title { font-size: .95rem !important; }
    .panel-link { font-size: .72rem !important; }
    .dashboard-shell.clean .panel-body,
    .panel-body {
        padding: 14px !important;
    }

    .donut-layout {
        grid-template-columns: 152px 1fr !important;
        gap: 12px !important;
    }
    .donut {
        width: 138px !important;
        height: 138px !important;
    }
    .donut:after { inset: 25px !important; }
    .donut-center { font-size: 1.5rem !important; }
    .donut-center span { font-size: .58rem !important; }
    .visual-stat-list { gap: 8px !important; }
    .visual-stat {
        padding: 9px 11px !important;
        border-radius: 14px !important;
    }
    .visual-stat strong { font-size: .98rem !important; }

    .pay-visual { gap: 10px !important; }
    .pay-score-number { font-size: 2.4rem !important; }
    .pay-stack { height: 14px !important; }
    .pay-metrics { gap: 8px !important; }
    .metric-chip {
        padding: 9px 10px !important;
        border-radius: 14px !important;
    }
    .mini-number { font-size: .98rem !important; }
    .mini-label { font-size: .58rem !important; }
    .legend { font-size: .64rem !important; gap: 6px 10px !important; }

    .trend-card {
        gap: 8px !important;
        grid-template-rows: auto auto auto !important;
    }
    .bar-chart {
        height: 114px !important;
        gap: 8px !important;
        padding: 4px 2px 0 !important;
    }
    .bar { width: 10px !important; }
    .bar-day { gap: 5px !important; font-size: .6rem !important; }
    .security-line {
        gap: 8px !important;
    }

    @media (max-width: 1450px) {
        .bottom-grid {
            grid-template-columns: 1fr 1fr .88fr !important;
        }
        .donut-layout {
            grid-template-columns: 140px 1fr !important;
        }
        .donut {
            width: 128px !important;
            height: 128px !important;
        }
    }
</style>

<style>
    /* Stronger red accent for dashboard hero and key red UI elements */
    .red-btn {
        background: linear-gradient(135deg, #dc2626, #991b1b) !important;
        box-shadow: 0 16px 30px rgba(220,38,38,.26) !important;
    }

    .hero-line path[stroke],
    .hero-line circle {
        filter: drop-shadow(0 6px 10px rgba(220,38,38,.20));
    }

    .hero-card {
        border-color: rgba(248, 113, 113, .55) !important;
    }

    .panel-title i,
    .panel-link,
    .hero-kicker,
    .plate-stat-sub,
    .kpi-value {
        color: #dc2626 !important;
    }
</style>

<style>
    /* Fix donut center text alignment in Visitor Parking Overview */
    .donut-center {
        position: absolute !important;
        inset: 0 !important;
        z-index: 3 !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: center !important;
        text-align: center !important;
        font-weight: 950 !important;
        line-height: 1 !important;
        transform: none !important;
        padding: 0 !important;
        margin: 0 !important;
        color: #dc2626 !important;
        pointer-events: none !important;
    }

    .donut-center span {
        display: block !important;
        margin-top: 8px !important;
        color: #64748b !important;
        font-size: .62rem !important;
        font-weight: 950 !important;
        text-transform: uppercase !important;
        letter-spacing: .04em !important;
        line-height: 1 !important;
    }
</style>

<style>
    /* Dashboard banner slider: first slide = Today Booking, second slide = overview */
    .hero-slider {
        min-height: 215px;
        position: relative;
        overflow: hidden;
        border-radius: 30px;
    }

    .hero-slider .hero-card {
        position: absolute !important;
        inset: 0;
        min-height: 100% !important;
        width: 100%;
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transform: translateX(18px);
        transition: opacity .48s ease, transform .48s ease, visibility .48s ease;
    }

    .hero-slider .hero-card.active {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
        transform: translateX(0);
    }

    .today-booking-banner {
        background:
            radial-gradient(circle at 24% 16%, rgba(254, 202, 202, .62), transparent 34%),
            radial-gradient(circle at 90% 20%, rgba(219, 234, 254, .55), transparent 28%),
            linear-gradient(135deg, rgba(255,255,255,.98), rgba(255,241,242,.88)) !important;
    }

    .today-booking-center {
        align-content: center;
    }

    .today-booking-number {
        margin-top: 5px;
        font-size: 4.2rem;
        line-height: .92;
        font-weight: 950;
        letter-spacing: -.09em;
        color: #dc2626;
    }

    .today-booking-sub {
        margin-top: 8px;
        color: #64748b;
        font-size: .82rem;
        font-weight: 900;
    }

    .today-booking-metrics {
        margin-top: 18px;
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 10px;
        max-width: 430px;
    }

    .today-mini-card {
        min-height: 58px;
        border: 1px solid rgba(229,231,235,.9);
        background: rgba(255,255,255,.78);
        border-radius: 18px;
        padding: 10px 11px;
        box-shadow: 0 10px 22px rgba(15,23,42,.05);
    }

    .today-mini-card span {
        display: block;
        color: #0f172a;
        font-size: 1.15rem;
        line-height: 1;
        font-weight: 950;
    }

    .today-mini-card small {
        display: block;
        margin-top: 6px;
        color: #64748b;
        font-size: .58rem;
        text-transform: uppercase;
        letter-spacing: .04em;
        font-weight: 950;
        white-space: nowrap;
    }

    .booking-visual-wrap {
        position: relative;
        z-index: 1;
        display: grid;
        place-items: center;
    }

    .booking-calendar-card {
        width: 285px;
        min-height: 170px;
        border: 1px solid rgba(229,231,235,.9);
        border-radius: 28px;
        background: rgba(255,255,255,.84);
        box-shadow: 0 26px 50px rgba(15,23,42,.13);
        padding: 18px;
        display: grid;
        grid-template-columns: 92px 1fr;
        gap: 16px;
        align-items: center;
        position: relative;
        overflow: hidden;
    }

    .booking-calendar-card::after {
        content: "";
        position: absolute;
        right: -30px;
        bottom: -40px;
        width: 120px;
        height: 120px;
        border-radius: 50%;
        background: rgba(220,38,38,.08);
    }

    .booking-calendar-top {
        position: absolute;
        top: 14px;
        left: 18px;
        display: flex;
        gap: 6px;
    }

    .calendar-dot {
        width: 9px;
        height: 9px;
        border-radius: 50%;
        display: block;
    }

    .calendar-dot.red { background: #dc2626; }
    .calendar-dot.yellow { background: #f59e0b; }
    .calendar-dot.green { background: #22c55e; }

    .booking-date-box {
        margin-top: 14px;
        height: 106px;
        border-radius: 22px;
        background: linear-gradient(135deg, #dc2626, #991b1b);
        color: #fff;
        display: grid;
        place-items: center;
        text-align: center;
        box-shadow: 0 18px 34px rgba(220,38,38,.20);
        overflow: hidden;
    }

    .booking-month {
        align-self: end;
        font-size: .64rem;
        font-weight: 950;
        letter-spacing: .12em;
        opacity: .9;
    }

    .booking-day {
        font-size: 2.35rem;
        line-height: .9;
        font-weight: 950;
        letter-spacing: -.06em;
    }

    .booking-week {
        align-self: start;
        font-size: .68rem;
        font-weight: 900;
        opacity: .9;
    }

    .booking-check-list {
        position: relative;
        z-index: 2;
        display: grid;
        gap: 11px;
        margin-top: 18px;
    }

    .booking-check-list div {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #334155;
        font-size: .74rem;
        font-weight: 900;
        line-height: 1.25;
    }

    .booking-check-list i {
        width: 24px;
        height: 24px;
        border-radius: 9px;
        display: grid;
        place-items: center;
        background: #fff1f2;
        color: #dc2626;
        flex: 0 0 auto;
    }

    .hero-slider-dots {
        position: absolute;
        right: 24px;
        bottom: 18px;
        z-index: 10;
        display: flex;
        gap: 8px;
    }

    .hero-dot {
        width: 8px;
        height: 8px;
        border: 0;
        border-radius: 999px;
        background: rgba(220,38,38,.25);
        cursor: pointer;
        transition: .2s ease;
    }

    .hero-dot.active {
        width: 25px;
        background: #dc2626;
    }

    @media (max-width: 1450px) {
        .hero-slider { min-height: 195px; }
        .today-booking-number { font-size: 3.6rem; }
        .today-booking-metrics { margin-top: 14px; }
        .booking-calendar-card { transform: scale(.92); transform-origin: center; }
    }
</style>
</head>
<body>
<div class="dashboard-shell clean">
    <?php require_once __DIR__ . '/admin_sidebar.php'; ?>
    <main class="main">
        <div class="topbar">
            <div>
                <h1 class="page-title">Dashboard</h1>
                <div class="page-subtitle">Control Center</div>
            </div>
            <div class="top-actions">
                <form class="search-pill" id="dashboardSearchForm" autocomplete="off">
                    <i class="fas fa-magnifying-glass"></i>
                    <input type="text" id="dashboardSearchInput" placeholder="Search SmartVMS...">
                    <button type="submit" class="search-submit" title="Search">
                        <i class="fas fa-magnifying-glass"></i>
                    </button>

                    <div class="search-suggest" id="dashboardSearchSuggest">
                        <div class="suggest-head">
                            <span id="suggestHeadText">Quick Open</span>
                            <span class="suggest-keyword" id="suggestKeyword"></span>
                        </div>
                        <div class="suggest-list" id="suggestList"></div>
                    </div>
                </form>

                <div class="notify-menu" id="notifyMenu">
                    <button type="button" class="top-round" id="notifyTrigger" title="Notifications">
                        <i class="fas fa-bell"></i>
                        <?php if ($unreadNotifications > 0): ?>
                            <span class="notify-count"><?= (int)$unreadNotifications > 9 ? '9+' : (int)$unreadNotifications ?></span>
                        <?php endif; ?>
                    </button>

                    <div class="notify-dropdown">
                        <div class="notify-head">
                            <strong>Notifications</strong>
                            <span class="notify-badge"><?= (int)$unreadNotifications ?> unread</span>
                        </div>

                        <?php if (!$latestNotifications): ?>
                            <div class="notify-empty">
                                <i class="fas fa-bell-slash" style="font-size:1.4rem;margin-bottom:8px;display:block;color:#cbd5e1;"></i>
                                No notifications yet.
                            </div>
                        <?php else: ?>
                            <div class="notify-list">
                                <?php foreach ($latestNotifications as $notice): ?>
                                    <?php
                                        $noticeTitle = $notice['title'] ?: 'Notification';
                                        $noticeMessage = $notice['message'] ?: 'System notification.';
                                        $noticeLink = $notice['link_url'] ?: 'admin_dashboard.php';
                                        $noticeIcon = 'fa-bell';
                                        $noticeType = strtolower((string)($notice['type'] ?? ''));

                                        if (strpos($noticeType, 'booking') !== false) {
                                            $noticeIcon = 'fa-calendar-check';
                                            $noticeLink = $notice['link_url'] ?: 'admin_visitor_bookings.php';
                                        } elseif (strpos($noticeType, 'parking') !== false || strpos($noticeMessage, 'parking') !== false) {
                                            $noticeIcon = 'fa-square-parking';
                                            $noticeLink = $notice['link_url'] ?: 'admin_parking_payment.php';
                                        } elseif (strpos($noticeType, 'resident') !== false) {
                                            $noticeIcon = 'fa-users';
                                            $noticeLink = $notice['link_url'] ?: 'admin_residents_manage.php';
                                        }
                                    ?>
                                    <a class="notify-item <?= empty($notice['is_read']) ? 'unread' : '' ?>" href="<?= e($noticeLink) ?>">
                                        <div class="notify-icon"><i class="fas <?= e($noticeIcon) ?>"></i></div>
                                        <div>
                                            <div class="notify-title"><?= e($noticeTitle) ?></div>
                                            <div class="notify-message"><?= e($noticeMessage) ?></div>
                                            <div class="notify-time"><?= e(dash_short_date_v2($notice['created_at'] ?? null)) ?></div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <a href="admin_dashboard.php" class="notify-view-all">
                            <i class="fas fa-check-double"></i>
                            Latest notifications shown here
                        </a>
                    </div>
                </div>

                <div class="profile-menu" id="profileMenu">
                    <button type="button" class="profile-trigger" id="profileTrigger"><?= e($profileInitial) ?></button>
                    <div class="profile-dropdown">
                        <div class="profile-email"><?= e($currentEmail) ?></div>
                        <div class="profile-role"><?= e($currentRole) ?></div>
                        <div class="profile-apartment">Apartment<br><strong><?= e($currentApartmentName) ?></strong></div>
                        
                    <a href="admin_profile.php" class="profile-action">
                        <i class="fas fa-user-circle"></i>
                        Profile
                    </a>

<a href="../core/logout.php" class="profile-action"><i class="fas fa-right-from-bracket"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>

                <div class="hero-slider" id="dashboardHeroSlider">
            <section class="hero-card hero-slide active today-booking-banner" data-hero-slide="1">
                <div class="hero-left">
                    <div class="hero-kicker">Today Visitor Booking</div>
                    <h2 class="hero-title">Today’s Bookings</h2>
                    <p class="hero-copy">Quick view of visitor bookings scheduled for today, including waiting entry and checked-in visitors.</p>
                    <a href="admin_visitor_bookings.php?visit_date=<?= e(date('Y-m-d')) ?>" class="red-btn">
                        View Today Visits <i class="fas fa-calendar-check"></i>
                    </a>
                </div>

                <div class="hero-center today-booking-center">
                    <div class="plate-stat-label">Bookings Today</div>
                    <div class="today-booking-number"><?= (int)$todayVisits ?></div>
                    <div class="today-booking-sub">
                        <?= e(date('d M Y')) ?> · <?= e(date('l')) ?>
                    </div>

                    <div class="today-booking-metrics">
                        <div class="today-mini-card">
                            <span><?= (int)$todayWaitingBookings ?></span>
                            <small>Waiting Entry</small>
                        </div>
                        <div class="today-mini-card">
                            <span><?= (int)$todayCheckedInBookings ?></span>
                            <small>Checked In</small>
                        </div>
                        <div class="today-mini-card">
                            <span><?= (int)$todayCompletedBookings ?></span>
                            <small>Completed</small>
                        </div>
                    </div>
                </div>

                <div class="booking-visual-wrap">
                    <div class="booking-calendar-card">
                        <div class="booking-calendar-top">
                            <span class="calendar-dot red"></span>
                            <span class="calendar-dot yellow"></span>
                            <span class="calendar-dot green"></span>
                        </div>

                        <div class="booking-date-box">
                            <div class="booking-month"><?= e(strtoupper(date('M'))) ?></div>
                            <div class="booking-day"><?= e(date('d')) ?></div>
                            <div class="booking-week"><?= e(date('D')) ?></div>
                        </div>

                        <div class="booking-check-list">
                            <div><i class="fas fa-user-check"></i> Resident approval</div>
                            <div><i class="fas fa-qrcode"></i> QR / vehicle pass</div>
                            <div><i class="fas fa-door-open"></i> Entry monitoring</div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="hero-card hero-slide" data-hero-slide="2">
            <div class="hero-left">
                <div class="hero-kicker">Smarter Access, Safer Communities</div>
                <h2 class="hero-title">Welcome back, Admin 👋</h2>
                <p class="hero-copy">Real-time overview of your community vehicle, visitor, parking and gate management.</p>
                <a href="admin_system_reports.php" class="red-btn">View Reports <i class="fas fa-chart-line"></i></a>
            </div>
            <div class="hero-center">
                <div class="plate-stat-label">Plate Scans Today</div>
                <div class="plate-stat-number"><?= (int)$todayGateLogs ?></div>
                <div class="plate-stat-sub"><i class="fas fa-arrow-trend-up"></i> <?= (int)$todayAllowed ?> allowed / <?= (int)$todayDenied ?> denied today</div>
                <svg class="hero-line" viewBox="0 0 520 130" preserveAspectRatio="none">
                    <defs><linearGradient id="heroArea" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#dc2626" stop-opacity="0.18"/><stop offset="1" stop-color="#dc2626" stop-opacity="0"/></linearGradient></defs>
                    <path d="M0,96 C65,98 70,70 125,74 C170,78 185,36 240,47 C305,62 310,28 366,42 C420,56 423,88 520,30 L520,130 L0,130 Z" fill="url(#heroArea)"></path>
                    <path d="M0,96 C65,98 70,70 125,74 C170,78 185,36 240,47 C305,62 310,28 366,42 C420,56 423,88 520,30" fill="none" stroke="#dc2626" stroke-width="5" stroke-linecap="round"></path>
                    <circle cx="510" cy="32" r="10" fill="#dc2626" stroke="#fff" stroke-width="5"></circle>
                </svg>
            </div>
            <div class="hero-car-wrap">
                <div class="car-shell">
                    <span class="scan-corner tl"></span><span class="scan-corner tr"></span><span class="scan-corner bl"></span><span class="scan-corner br"></span>
                    <svg class="hero-car-svg" viewBox="0 0 300 150" aria-hidden="true">
                        <g transform="translate(6,16)">
                            <path class="car-body-fill" d="M32 66 L51 60 L120 60 L149 36 L213 36 L240 58 L263 58 C276 58 286 67 286 79 C286 90 278 98 266 99 L250 100 L236 112 L82 112 L67 94 L32 94 Z"></path>
                            <path class="car-outline" d="M32 66 L51 60 L120 60 L149 36 L213 36 L240 58 L263 58 C276 58 286 67 286 79 C286 90 278 98 266 99 L250 100 L236 112 L82 112 L67 94 L32 94 Z"></path>
                            <path class="car-window-fill" d="M129 58 L166 58 L190 41 L152 41 Z"></path>
                            <path class="car-outline" d="M129 58 L166 58 L190 41 L152 41 Z"></path>
                            <line class="car-outline" x1="169" y1="59" x2="197" y2="39"></line>
                            <line class="car-outline" x1="171" y1="63" x2="171" y2="94"></line>
                            <line class="car-outline" x1="111" y1="64" x2="140" y2="94"></line>
                            <line class="car-outline" x1="76" y1="85" x2="87" y2="85"></line>
                            <line class="car-outline" x1="260" y1="74" x2="275" y2="74"></line>
                            <path class="car-outline" d="M233 58 L250 58 L262 64"></path>
                            <path class="car-outline" d="M240 99 L253 99"></path>
                            <path d="M237 73 L252 73" stroke="#ffb02e" stroke-width="7" stroke-linecap="round"></path>
                            <rect x="35" y="79" width="16" height="6" rx="3" fill="#101828"></rect>
                            <circle cx="96" cy="112" r="18" class="car-wheel-fill" stroke="#101828" stroke-width="6"></circle>
                            <circle cx="96" cy="112" r="5" class="car-wheel-center"></circle>
                            <circle cx="221" cy="112" r="18" class="car-wheel-fill" stroke="#101828" stroke-width="6"></circle>
                            <circle cx="221" cy="112" r="5" class="car-wheel-center"></circle>
                        </g>
                    </svg>
                    <span class="scan-beam"></span>
                                    </div>
            </div>
        </section>

            <div class="hero-slider-dots" aria-label="Dashboard banner controls">
                <button type="button" class="hero-dot active" data-hero-target="0" aria-label="Today booking banner"></button>
                <button type="button" class="hero-dot" data-hero-target="1" aria-label="Overview banner"></button>
            </div>
        </div>

        <section class="kpi-grid">
            <a href="admin_residents_manage.php" class="kpi-card"><div class="kpi-left"><div class="kpi-icon"><i class="fas fa-users"></i></div><div><div class="kpi-name">Resident Management</div><div class="kpi-value"><?= (int)$activeResidents ?></div><div class="kpi-hint">Active residents</div></div></div><div class="kpi-arrow"><i class="fas fa-chevron-right"></i></div></a>
            <a href="admin_visitor_bookings.php" class="kpi-card"><div class="kpi-left"><div class="kpi-icon"><i class="fas fa-id-badge"></i></div><div><div class="kpi-name">Visitor Management</div><div class="kpi-value"><?= (int)$activeVisitorPasses ?></div><div class="kpi-hint">Active visitor passes</div></div></div><div class="kpi-arrow"><i class="fas fa-chevron-right"></i></div></a>
            <a href="admin_parking_(V)manage.php" class="kpi-card"><div class="kpi-left"><div class="kpi-icon" style="background:#e8eefc;color:#5a78d6;"><i class="fas fa-square-parking"></i></div><div><div class="kpi-name">Parking Management</div><div class="kpi-value" style="color:#5a78d6;"><?= (int)$availableVisitorSlots ?></div><div class="kpi-hint">Visitor slots left</div></div></div><div class="kpi-arrow"><i class="fas fa-chevron-right"></i></div></a>
            <a href="guard_logs.php" class="kpi-card"><div class="kpi-left"><div class="kpi-icon" style="background:#e7f4ec;color:#4c9b70;"><i class="fas fa-shield-halved"></i></div><div><div class="kpi-name">Gate Management</div><div class="kpi-value" style="color:#4c9b70;"><?= (int)$todayGateLogs ?></div><div class="kpi-hint">Today gate logs</div></div></div><div class="kpi-arrow"><i class="fas fa-chevron-right"></i></div></a>
        </section>

<style>
    .dashboard-shell.clean .panel-body { padding: 18px; }
    .donut-layout { height:100%; display:grid; grid-template-columns: 190px 1fr; gap:18px; align-items:center; }
    .donut { width:170px; height:170px; border-radius:50%; background: conic-gradient(#5a78d6 0 <?= (int)$visitorAvailablePercent ?>%, #d58b47 <?= (int)$visitorReservedStart ?>% <?= (int)$visitorOccupiedStart ?>%, #dc2626 <?= (int)$visitorOccupiedStart ?>% <?= (int)$visitorMaintenanceStart ?>%, #94a3b8 <?= (int)$visitorMaintenanceStart ?>% 100%); position:relative; box-shadow: inset 0 0 0 1px rgba(15,23,42,.05), 0 18px 35px rgba(15,23,42,.08); margin:auto; }
    .donut:after { content:""; position:absolute; inset:32px; background:white; border-radius:50%; box-shadow: inset 0 0 0 1px var(--border); }
    .donut-center { position:absolute; inset:0; z-index:2; display:grid; place-items:center; text-align:center; font-weight:950; color:var(--primary); }
    .donut-center span { display:block; color:#64748b; font-size:.62rem; text-transform:uppercase; margin-top:4px; }
    .visual-stat-list { display:grid; gap:10px; }
    .visual-stat { display:flex; align-items:center; justify-content:space-between; gap:12px; background:white; border:1px solid var(--border); border-radius:17px; padding:12px 13px; }
    .visual-stat strong { font-size:1.05rem; letter-spacing:-.05em; }
    .pay-visual { height:100%; display:grid; grid-template-rows:auto auto 1fr; gap:13px; }
    .pay-score { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; }
    .pay-score-number { font-size:3rem; line-height:.9; color:var(--green); font-weight:950; letter-spacing:-.08em; }
    .pay-stack { height:18px; border-radius:999px; background:#f1f5f9; overflow:hidden; display:flex; box-shadow: inset 0 0 0 1px rgba(15,23,42,.04); }
     .pay-seg-paid { width:<?= (int)$paymentPaidPercent ?>%; background:linear-gradient(90deg,#77ba92,#4c9b70); min-width:<?= $paymentPaidPercent > 0 ? '8px' : '0' ?>; }
     .pay-seg-unpaid { width:<?= (int)$paymentUnpaidPercent ?>%; background:linear-gradient(90deg,#f87171,#dc2626); min-width:<?= $paymentUnpaidPercent > 0 ? '8px' : '0' ?>; }
     .pay-seg-pending { width:<?= (int)$paymentPendingPercent ?>%; background:linear-gradient(90deg,#e7b57b,#d58b47); min-width:<?= $paymentPendingPercent > 0 ? '8px' : '0' ?>; }
    .pay-metrics { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
    .trend-card { height:100%; display:grid; grid-template-rows:auto 1fr auto; gap:12px; }
    .bar-chart { min-height:0; height:170px; display:grid; grid-template-columns:repeat(7,1fr); align-items:end; gap:10px; padding: 6px 6px 0; border-bottom:1px solid var(--border); }
    .bar-day { height:100%; display:grid; grid-template-rows:1fr auto; gap:7px; text-align:center; color:#64748b; font-size:.65rem; font-weight:900; }
    .bar-pair { height:100%; display:flex; align-items:end; justify-content:center; gap:3px; }
    .bar { width:12px; min-height:5px; border-radius:999px 999px 4px 4px; }
    .bar.allow { background:linear-gradient(180deg,#9fd3b2,#4c9b70); }
    .bar.deny { background:linear-gradient(180deg,#e7b2b2,#dc2626); }
    .security-line { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
</style>

        <section class="bottom-grid">
            <div class="panel">
                <div class="panel-head"><div class="panel-title"><i class="fas fa-car-side"></i> Visitor Parking Overview</div><a href="admin_parking_(V)manage.php" class="panel-link">Manage Slots</a></div>
                <div class="panel-body">
                    <div class="donut-layout">
                        <div class="donut"><div class="donut-center"><?= (int)$visitorSlotUsagePercent ?>%<span>Used</span></div></div>
                        <div class="visual-stat-list">
                            <div class="legend"><span><i class="dot" style="background:#5a78d6;"></i>Available</span><span><i class="dot" style="background:#d58b47;"></i>Reserved</span><span><i class="dot" style="background:#dc2626;"></i>Occupied</span><span><i class="dot" style="background:#94a3b8;"></i>Maintenance</span></div>
                            <div class="visual-stat"><div><div class="mini-label">Available</div><strong style="color:#5a78d6;"><?= (int)$availableVisitorSlots ?></strong></div><div class="mini-label"><?= (int)$visitorAvailablePercent ?>%</div></div>
                            <div class="visual-stat"><div><div class="mini-label">Reserved</div><strong style="color:#d58b47;"><?= (int)$reservedVisitorSlots ?></strong></div><div class="mini-label"><?= (int)$visitorReservedPercent ?>%</div></div>
                            <div class="visual-stat"><div><div class="mini-label">Occupied</div><strong style="color:#dc2626;"><?= (int)$occupiedVisitorSlots ?></strong></div><div class="mini-label"><?= (int)$visitorOccupiedPercent ?>%</div></div>
                            <div class="visual-stat"><div><div class="mini-label">Maintenance</div><strong style="color:#64748b;"><?= (int)$maintenanceVisitorSlots ?></strong></div><div class="mini-label"><?= (int)$visitorMaintenancePercent ?>%</div></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><div class="panel-title"><i class="fas fa-warehouse"></i> Resident Parking Subscription</div><a href="admin_parking_(R)manage.php" class="panel-link">Manage Parking</a></div>
                <div class="panel-body">
                    <div class="pay-visual">
                        <div class="pay-score">
                            <div><div class="mini-label">Payment Collection</div><div class="pay-score-number"><?= (int)$paymentCollectionPercent ?>%</div></div>
                            <div style="text-align:right"><div class="mini-label">Billing Month</div><strong><?= e($currentBillingMonth) ?></strong></div>
                        </div>
                        <div>
                            <div class="pay-stack"><span class="pay-seg-paid"></span><span class="pay-seg-unpaid"></span><span class="pay-seg-pending"></span></div>
                            <div class="legend" style="margin-top:9px;"><span><i class="dot" style="background:#16a34a;"></i>Paid</span><span><i class="dot" style="background:#dc2626;"></i>Unpaid</span><span><i class="dot" style="background:#d58b47;"></i>Pending</span></div>
                        </div>
                        <div class="pay-metrics">
                            <div class="metric-chip"><div class="mini-number" style="color:#4c9b70;"><?= (int)$paidParkingThisMonth ?></div><div class="mini-label">Paid This Month</div></div>
                            <div class="metric-chip"><div class="mini-number" style="color:#dc2626;"><?= (int)$unpaidParkingThisMonth ?></div><div class="mini-label">Unpaid / Rejected</div></div>
                            <div class="metric-chip"><div class="mini-number" style="color:#d58b47;"><?= (int)$pendingParkingPayments ?></div><div class="mini-label">Pending Payment</div></div>
                            <div class="metric-chip"><div class="mini-number" style="color:#0f172a;"><?= (int)$availableResidentSlots ?></div><div class="mini-label">Resident Slots Left</div></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><div class="panel-title"><i class="fas fa-shield-virus"></i> Security Summary</div><a href="guard_logs.php" class="panel-link">View Logs</a></div>
                <div class="panel-body">
                    <div class="trend-card">
                        <div><div class="mini-label">7-Day Gate Access Trend</div></div>
                        <div class="bar-chart">
                            <?php foreach ($gateTrend as $trend): ?>
                                <div class="bar-day">
                                    <div class="bar-pair">
                                        <span class="bar allow" style="height: <?= max(5, round(((int)$trend['allowed'] / $maxGateTrend) * 100)) ?>%;"></span>
                                        <span class="bar deny" style="height: <?= max(5, round(((int)$trend['denied'] / $maxGateTrend) * 100)) ?>%;"></span>
                                    </div>
                                    <div><?= e($trend['label']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="security-line">
                            <div class="metric-chip"><div class="mini-number" style="color:#4c9b70;"><?= (int)$todayAllowed ?></div><div class="mini-label">Allowed Today</div></div>
                            <div class="metric-chip"><div class="mini-number" style="color:#dc2626;"><?= (int)$todayDenied ?></div><div class="mini-label">Denied Today</div></div>
                            <div class="metric-chip"><div class="mini-number" style="color:#d58b47;"><?= (int)$activeBlacklist ?></div><div class="mini-label">Blacklist</div></div>
                            <div class="metric-chip"><div class="mini-number"><?= (int)$totalGateLogs ?></div><div class="mini-label">All Logs</div></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

    </main>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const profileMenu = document.getElementById('profileMenu');
    const profileTrigger = document.getElementById('profileTrigger');
    const notifyMenu = document.getElementById('notifyMenu');
    const notifyTrigger = document.getElementById('notifyTrigger');
    const searchForm = document.getElementById('dashboardSearchForm');
    const searchInput = document.getElementById('dashboardSearchInput');

    if (profileMenu && profileTrigger) {
        profileTrigger.addEventListener('click', function (event) {
            event.stopPropagation();
            profileMenu.classList.toggle('open');

            if (notifyMenu) {
                notifyMenu.classList.remove('open');
            }
        });
    }

    if (notifyMenu && notifyTrigger) {
        notifyTrigger.addEventListener('click', function (event) {
            event.stopPropagation();
            notifyMenu.classList.toggle('open');

            if (profileMenu) {
                profileMenu.classList.remove('open');
            }
        });
    }

    document.addEventListener('click', function (event) {
        if (profileMenu && !profileMenu.contains(event.target)) {
            profileMenu.classList.remove('open');
        }

        if (notifyMenu && !notifyMenu.contains(event.target)) {
            notifyMenu.classList.remove('open');
        }
    });

    if (searchForm && searchInput) {
        const suggestBox = document.getElementById('dashboardSearchSuggest');
        const suggestList = document.getElementById('suggestList');
        const suggestKeyword = document.getElementById('suggestKeyword');
        const suggestHeadText = document.getElementById('suggestHeadText');

        const searchItems = [
            {
                title: 'Resident Management',
                subtitle: 'Manage resident profiles, units and resident details',
                page: 'admin_residents_manage.php',
                icon: 'fa-users',
                keywords: 'resident residents manage management profile user unit household owner tenant renter family'
            },
            {
                title: 'Add Resident',
                subtitle: 'Create a new resident account and assign unit',
                page: 'admin_residents_add.php',
                icon: 'fa-user-plus',
                keywords: 'add resident create new owner tenant renter register'
            },
            {
                title: 'Unit / Household',
                subtitle: 'View apartment unit and household assignments',
                page: 'admin_resident_apartment.php',
                icon: 'fa-house-user',
                keywords: 'unit household apartment block floor house resident'
            },
            {
                title: 'Resident Vehicles',
                subtitle: 'Manage resident vehicle records and plates',
                page: 'admin_resident_vehicles.php',
                icon: 'fa-car-side',
                keywords: 'resident vehicle vehicles plate car'
            },
            {
                title: 'Visitor Accounts',
                subtitle: 'Visitor login accounts, contacts and visit summary',
                page: 'admin_visitor_passes.php',
                icon: 'fa-id-card',
                keywords: 'visitor account accounts pass passes contact phone plate'
            },
            {
                title: 'Visitor Visits',
                subtitle: 'Combined visitor bookings and visitor records',
                page: 'admin_visitor_bookings.php',
                icon: 'fa-calendar-check',
                keywords: 'visitor visit visits booking bookings record records checked in overstay completed waiting entry'
            },
            {
                title: 'Visitor Parking Slots',
                subtitle: 'View and manage visitor parking slot map',
                page: 'admin_parking_(V)manage.php',
                icon: 'fa-square-parking',
                keywords: 'visitor parking slot slots available reserved occupied'
            },
            {
                title: 'Resident Parking',
                subtitle: 'Resident parking subscription and slot map',
                page: 'admin_parking_(R)manage.php',
                icon: 'fa-warehouse',
                keywords: 'resident parking subscription slot assigned unpaid car inside'
            },
            {
                title: 'Parking Requests',
                subtitle: 'Review resident parking requests',
                page: 'admin_parking_requests.php',
                icon: 'fa-clipboard-list',
                keywords: 'parking request requests resident approve reject'
            },
            {
                title: 'Payment Verification',
                subtitle: 'Check parking payments, unpaid and reminders',
                page: 'admin_parking_payment.php',
                icon: 'fa-credit-card',
                keywords: 'payment payments verify verification unpaid paid invoice reminder fee'
            },
            {
                title: 'Gate Logs',
                subtitle: 'Allowed and denied gate access logs',
                page: 'guard_logs.php',
                icon: 'fa-shield-halved',
                keywords: 'gate log logs access allowed denied scan security entry exit'
            },
            {
                title: 'Reports & Logs',
                subtitle: 'System reports, audit logs and activity history',
                page: 'admin_system_reports.php',
                icon: 'fa-chart-line',
                keywords: 'report reports log logs audit activity summary'
            },
            {
                title: 'Platform Payment',
                subtitle: 'Platform billing and apartment payment',
                page: 'admin_platform_payment.php',
                icon: 'fa-wallet',
                keywords: 'platform payment billing invoice apartment subscription'
            },
            {
                title: 'Dashboard',
                subtitle: 'Return to SmartVMS control center',
                page: 'admin_dashboard.php',
                icon: 'fa-table-columns',
                keywords: 'dashboard home control center overview'
            }
        ];

        let activeIndex = -1;
        let currentMatches = [];

        function getSearchMatches(keyword) {
            const cleanKeyword = keyword.trim().toLowerCase();

            if (!cleanKeyword) {
                return searchItems.slice(0, 6);
            }

            return searchItems
                .map(function (item) {
                    const title = item.title.toLowerCase();
                    const keywords = item.keywords.toLowerCase();
                    let score = 0;

                    if (title.startsWith(cleanKeyword)) {
                        score += 100;
                    }

                    if (title.includes(cleanKeyword)) {
                        score += 60;
                    }

                    if (keywords.includes(cleanKeyword)) {
                        score += 35;
                    }

                    cleanKeyword.split(/\s+/).forEach(function (part) {
                        if (part && (title.includes(part) || keywords.includes(part))) {
                            score += 12;
                        }
                    });

                    return Object.assign({}, item, {score: score});
                })
                .filter(function (item) {
                    return item.score > 0;
                })
                .sort(function (a, b) {
                    return b.score - a.score || a.title.localeCompare(b.title);
                })
                .slice(0, 7);
        }

        function goToSearchItem(item, keyword) {
            if (!item) {
                return;
            }

            const searchPages = [
                'admin_residents_manage.php',
                'admin_visitor_passes.php',
                'admin_visitor_bookings.php',
                'admin_parking_payment.php',
                'guard_logs.php'
            ];

            let url = item.page;

            if (keyword && searchPages.includes(item.page)) {
                url += (url.includes('?') ? '&' : '?') + 'search=' + encodeURIComponent(keyword);
            }

            window.location.href = url;
        }

        function renderSearchSuggestions() {
            const keyword = searchInput.value.trim();
            currentMatches = getSearchMatches(keyword);
            activeIndex = currentMatches.length ? 0 : -1;

            if (!suggestBox || !suggestList) {
                return;
            }

            if (suggestHeadText) {
                suggestHeadText.textContent = keyword ? 'Best Match' : 'Quick Open';
            }

            if (suggestKeyword) {
                suggestKeyword.textContent = keyword ? keyword : '';
            }

            suggestList.innerHTML = '';

            if (!currentMatches.length) {
                suggestList.innerHTML = '<div class="suggest-empty"><i class="fas fa-magnifying-glass"></i><br>No matching page found.</div>';
                searchForm.classList.add('open');
                return;
            }

            currentMatches.forEach(function (item, index) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'suggest-item' + (index === activeIndex ? ' active' : '');
                button.innerHTML =
                    '<span class="suggest-icon"><i class="fas ' + item.icon + '"></i></span>' +
                    '<span class="suggest-title">' + item.title + '</span>';

                button.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    goToSearchItem(item, keyword);
                });

                suggestList.appendChild(button);
            });

            searchForm.classList.add('open');
        }

        function updateActiveSuggestion() {
            if (!suggestList) {
                return;
            }

            suggestList.querySelectorAll('.suggest-item').forEach(function (item, index) {
                item.classList.toggle('active', index === activeIndex);
            });
        }

        searchInput.addEventListener('focus', function () {
            renderSearchSuggestions();
        });

        searchInput.addEventListener('input', function () {
            renderSearchSuggestions();
        });

        searchInput.addEventListener('keydown', function (event) {
            if (!currentMatches.length) {
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                activeIndex = (activeIndex + 1) % currentMatches.length;
                updateActiveSuggestion();
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                activeIndex = (activeIndex - 1 + currentMatches.length) % currentMatches.length;
                updateActiveSuggestion();
            } else if (event.key === 'Enter') {
                event.preventDefault();
                goToSearchItem(currentMatches[activeIndex] || currentMatches[0], searchInput.value.trim());
            } else if (event.key === 'Escape') {
                searchForm.classList.remove('open');
            }
        });

        searchForm.addEventListener('submit', function (event) {
            event.preventDefault();
            const keyword = searchInput.value.trim();
            const matches = getSearchMatches(keyword);

            if (!keyword) {
                renderSearchSuggestions();
                searchInput.focus();
                return;
            }

            goToSearchItem(matches[0] || searchItems[0], keyword);
        });

        document.addEventListener('click', function (event) {
            if (!searchForm.contains(event.target)) {
                searchForm.classList.remove('open');
            }
        });
    }
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const heroSlider = document.getElementById('dashboardHeroSlider');
    if (!heroSlider) {
        return;
    }

    const slides = heroSlider.querySelectorAll('.hero-slide');
    const dots = heroSlider.querySelectorAll('.hero-dot');
    let currentSlide = 0;
    let heroTimer = null;

    function showHeroSlide(index) {
        if (!slides.length) {
            return;
        }

        currentSlide = (index + slides.length) % slides.length;

        slides.forEach(function (slide, slideIndex) {
            slide.classList.toggle('active', slideIndex === currentSlide);
        });

        dots.forEach(function (dot, dotIndex) {
            dot.classList.toggle('active', dotIndex === currentSlide);
        });
    }

    function startHeroTimer() {
        clearInterval(heroTimer);
        heroTimer = setInterval(function () {
            showHeroSlide(currentSlide + 1);
        }, 5000);
    }

    dots.forEach(function (dot) {
        dot.addEventListener('click', function () {
            showHeroSlide(Number(dot.dataset.heroTarget || 0));
            startHeroTimer();
        });
    });

    showHeroSlide(0);
    startHeroTimer();
});
</script>
</body>
</html>
