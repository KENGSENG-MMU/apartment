<?php
require_once '../core/security.php';
require_login(['resident']);

$pdo = db();

if (file_exists('../core/parking_auto.php')) {
    require_once '../core/parking_auto.php';

    if (function_exists('run_parking_automation')) {
        run_parking_automation($pdo);
    }
}

$residentId = (int)($_SESSION['uid'] ?? 0);
$residentEmail = $_SESSION['email'] ?? '';

function safe_count_resident_dashboard(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function safe_rows_resident_dashboard(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

function has_column_resident_dashboard(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.COLUMNS\n            WHERE TABLE_SCHEMA = DATABASE()\n            AND TABLE_NAME = ?\n            AND COLUMN_NAME = ?\n        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function table_exists_resident_dashboard(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.TABLES\n            WHERE TABLE_SCHEMA = DATABASE()\n            AND TABLE_NAME = ?\n        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function money_resident_dashboard($amount): string {
    return 'RM' . number_format((float)$amount, 2);
}

function payment_label_resident_dashboard(array $paymentRows, int $activeParkingSlots): string {
    if ($activeParkingSlots <= 0) {
        return 'No Slot';
    }

    if (!$paymentRows) {
        return 'Unpaid';
    }

    $statuses = array_map(function ($row) {
        return strtolower((string)($row['payment_status'] ?? ''));
    }, $paymentRows);

    $statuses = array_values(array_filter($statuses));

    if (!$statuses) {
        return 'Unpaid';
    }

    $total = count($statuses);
    $paidCount = count(array_filter($statuses, fn($s) => $s === 'paid'));

    if ($paidCount === $total) {
        return 'Paid';
    }

    if (in_array('pending_verification', $statuses, true)) {
        return 'Pending Verification';
    }

    if (in_array('overdue', $statuses, true)) {
        return 'Overdue';
    }

    if (in_array('rejected', $statuses, true)) {
        return 'Rejected';
    }

    return 'Unpaid';
}

function payment_class_resident_dashboard(string $status): string {
    $key = strtolower($status);

    if ($key === 'paid') {
        return 'paid';
    }

    if ($key === 'pending verification') {
        return 'pending';
    }

    if ($key === 'overdue' || $key === 'rejected' || $key === 'unpaid') {
        return 'danger';
    }

    return 'neutral';
}

$hasFullName = has_column_resident_dashboard($pdo, 'users', 'full_name');
$hasContact = has_column_resident_dashboard($pdo, 'users', 'contact_number');
$hasPhoto = has_column_resident_dashboard($pdo, 'users', 'profile_photo');

$residentNameSql = $hasFullName
    ? "u.full_name AS resident_name"
    : "NULL AS resident_name";

$residentContactSql = $hasContact
    ? "u.contact_number AS resident_contact"
    : "NULL AS resident_contact";

$residentPhotoSql = $hasPhoto
    ? "u.profile_photo AS profile_photo"
    : "NULL AS profile_photo";

$stmt = $pdo->prepare("\n    SELECT\n        u.id,\n        u.email,\n        {$residentNameSql},\n        {$residentContactSql},\n        {$residentPhotoSql},\n\n        ru.unit_id,\n        a.apartment_name,\n        a.address,\n        un.block_no,\n        un.floor_no,\n        un.unit_no\n\n    FROM users u\n\n    LEFT JOIN resident_units ru\n        ON ru.resident_id = u.id\n        AND ru.status = 'active'\n\n    LEFT JOIN units un ON un.id = ru.unit_id\n    LEFT JOIN apartments a ON a.id = un.apartment_id\n\n    WHERE u.id = ?\n    LIMIT 1\n");
$stmt->execute([$residentId]);
$resident = $stmt->fetch();

$residentName = $resident['resident_name'] ?? '';

if (!$residentName) {
    $residentName = explode('@', $residentEmail)[0];
}

$residentContact = trim((string)($resident['resident_contact'] ?? ''));
$profilePhoto = trim((string)($resident['profile_photo'] ?? ''));
$profilePhotoUrl = '';

if ($hasPhoto && $profilePhoto !== '') {
    $profilePhoto = str_replace('\\\\', '/', $profilePhoto);
    $profilePhoto = str_replace('\\', '/', $profilePhoto);

    if (preg_match('/^https?:\/\//i', $profilePhoto)) {
        $profilePhotoUrl = $profilePhoto;
    } else {
        $cleanPhoto = ltrim($profilePhoto, '/');

        $photoCandidates = [
            [__DIR__ . '/' . $cleanPhoto, $cleanPhoto],
            [__DIR__ . '/../' . $cleanPhoto, '../' . $cleanPhoto],
            [__DIR__ . '/uploads/profiles/' . basename($cleanPhoto), 'uploads/profiles/' . basename($cleanPhoto)]
        ];

        foreach ($photoCandidates as $candidate) {
            if (is_file($candidate[0])) {
                $profilePhotoUrl = $candidate[1];
                break;
            }
        }
    }
}

$residentInitial = strtoupper(substr(trim($residentName), 0, 1));

if ($residentInitial === '') {
    $residentInitial = 'R';
}

$unitText = 'No Unit Assigned';

if (!empty($resident['unit_no'])) {
    $unitText =
        'Unit ' .
        $resident['block_no'] . '-' .
        $resident['floor_no'] . '-' .
        $resident['unit_no'];
}

$totalBookings = safe_count_resident_dashboard(
    $pdo,
    "SELECT COUNT(*) FROM bookings WHERE resident_id = ?",
    [$residentId]
);

$pendingBookings = safe_count_resident_dashboard(
    $pdo,
    "SELECT COUNT(*) FROM bookings WHERE resident_id = ? AND status = 'pending'",
    [$residentId]
);

$approvedBookings = safe_count_resident_dashboard(
    $pdo,
    "SELECT COUNT(*) FROM bookings WHERE resident_id = ? AND status IN ('approved','allocated')",
    [$residentId]
);

$checkedInBookings = safe_count_resident_dashboard(
    $pdo,
    "SELECT COUNT(*) FROM bookings WHERE resident_id = ? AND status = 'checked_in'",
    [$residentId]
);

$completedBookings = safe_count_resident_dashboard(
    $pdo,
    "SELECT COUNT(*) FROM bookings WHERE resident_id = ? AND status IN ('completed','checked_out','closed')",
    [$residentId]
);

$myVehicles = safe_count_resident_dashboard(
    $pdo,
    "SELECT COUNT(*) FROM resident_vehicles WHERE resident_id = ? AND status = 'active'",
    [$residentId]
);

$parkingModuleReady = table_exists_resident_dashboard($pdo, 'parking_slots')
    && table_exists_resident_dashboard($pdo, 'resident_parking_requests')
    && table_exists_resident_dashboard($pdo, 'resident_parking_assignments')
    && table_exists_resident_dashboard($pdo, 'parking_payments');

$currentMonth = date('Y-m');
$currentMonthLabel = date('F Y');
$activeParkingSlots = 0;
$pendingParkingRequests = 0;
$parkingPaymentRows = [];
$slotRows = [];
$slotCodes = [];
$slotText = 'No parking slot yet';
$monthlyFee = 0.00;
$paymentStatus = 'No Slot';
$paymentClass = 'neutral';
$paymentActionText = 'Request Parking';
$parkingBadgeCount = 0;

if ($parkingModuleReady) {
    $slotRows = safe_rows_resident_dashboard($pdo, "\n        SELECT\n            rpa.id AS assignment_id,\n            rpa.monthly_fee,\n            ps.block_name,\n            ps.slot_no\n        FROM resident_parking_assignments rpa\n        LEFT JOIN parking_slots ps ON ps.id = rpa.slot_id\n        WHERE rpa.resident_id = ?\n        AND rpa.status = 'active'\n        ORDER BY ps.slot_no ASC, rpa.id ASC\n    ", [$residentId]);

    $activeParkingSlots = count($slotRows);

    foreach ($slotRows as $row) {
        $slotCode = trim((string)($row['slot_no'] ?? ''));
        if ($slotCode !== '') {
            $slotCodes[] = $slotCode;
        }

        $monthlyFee += (float)($row['monthly_fee'] ?? 0);
    }

    if ($slotCodes) {
        $slotText = implode(', ', $slotCodes);
    }

    $pendingParkingRequests = safe_count_resident_dashboard(
        $pdo,
        "SELECT COUNT(*) FROM resident_parking_requests WHERE resident_id = ? AND status = 'pending'",
        [$residentId]
    );

    $parkingPaymentRows = safe_rows_resident_dashboard($pdo, "\n        SELECT payment_status, amount\n        FROM parking_payments\n        WHERE resident_id = ?\n        AND billing_month = ?\n        ORDER BY id ASC\n    ", [$residentId, $currentMonth]);

    $paymentStatus = payment_label_resident_dashboard($parkingPaymentRows, $activeParkingSlots);
    $paymentClass = payment_class_resident_dashboard($paymentStatus);

    $unpaidPaymentCount = safe_count_resident_dashboard(
        $pdo,
        "SELECT COUNT(*) FROM parking_payments WHERE resident_id = ? AND billing_month = ? AND payment_status IN ('unpaid','pending_verification','overdue','rejected')",
        [$residentId, $currentMonth]
    );

    $parkingBadgeCount = $pendingParkingRequests + $unpaidPaymentCount;

    if ($activeParkingSlots > 0) {
        $paymentActionText = ($paymentStatus === 'Paid') ? 'View Parking' : 'Go to Payment';
    } elseif ($pendingParkingRequests > 0) {
        $paymentActionText = 'View Request';
    }
}

$notificationCount = safe_count_resident_dashboard(
    $pdo,
    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0",
    [$residentId]
);


$todayVisitors = safe_count_resident_dashboard(
    $pdo,
    "SELECT COUNT(*) FROM bookings WHERE resident_id = ? AND DATE(start_time) = CURDATE() AND status NOT IN ('rejected','cancelled','expired')",
    [$residentId]
);

$recentActivities = safe_rows_resident_dashboard(
    $pdo,
    "SELECT title, message, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 3",
    [$residentId]
);

$paymentAmountDue = 0.00;
if ($parkingPaymentRows) {
    foreach ($parkingPaymentRows as $paymentRow) {
        $statusKey = strtolower((string)($paymentRow['payment_status'] ?? ''));
        if ($statusKey !== 'paid') {
            $paymentAmountDue += (float)($paymentRow['amount'] ?? 0);
        }
    }
}
if ($paymentAmountDue <= 0 && $paymentStatus !== 'Paid' && $activeParkingSlots > 0) {
    $paymentAmountDue = $monthlyFee;
}

$parkingDueDate = date('d M Y', strtotime('last day of this month'));
$parkingStatusText = $paymentStatus;
$parkingStatusClass = payment_class_resident_dashboard($paymentStatus);

$greetingName = trim($residentName);
if (mb_strlen($greetingName) > 22) {
    $greetingName = mb_substr($greetingName, 0, 22) . '...';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Resident Dashboard - SmartVMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        :root {
            --navy: #0f172a;
            --text: #334155;
            --muted: #64748b;
            --line: #dbe5f0;
            --soft-line: #eef3f8;
            --blue: #2563eb;
            --blue-2: #38bdf8;
            --blue-soft: #eff6ff;
            --green: #16a34a;
            --green-soft: #ecfdf3;
            --orange: #f97316;
            --orange-soft: #fff7ed;
            --purple: #7c3aed;
            --purple-soft: #f5f3ff;
            --red: #dc2626;
            --red-soft: #fff1f2;
            --surface: rgba(255, 255, 255, 0.90);
            --shadow: 0 18px 55px rgba(15, 23, 42, .08);
            --shadow-hover: 0 24px 70px rgba(15, 23, 42, .12);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        body {
            min-height: 100vh;
            color: var(--navy);
            background:
                linear-gradient(115deg, rgba(255,255,255,.96) 0%, rgba(246,250,255,.90) 52%, rgba(225,240,255,.76) 100%),
                url('lou.jpg') center/cover fixed no-repeat;
            overflow-x: hidden;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 72px 0 0 0;
            z-index: 0;
            pointer-events: none;
            background:
                radial-gradient(circle at 10% 22%, rgba(37, 99, 235, .08), transparent 22%),
                radial-gradient(circle at 92% 20%, rgba(56, 189, 248, .16), transparent 25%),
                radial-gradient(circle at 88% 90%, rgba(37, 99, 235, .06), transparent 24%);
            backdrop-filter: blur(2px);
        }

        a { text-decoration: none; }

        .navbar {
            height: 76px;
            padding: 0 5.5%;
            background: rgba(255, 255, 255, .92);
            backdrop-filter: blur(18px);
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .brand {
            color: var(--navy);
            font-size: 1.65rem;
            font-weight: 900;
            letter-spacing: -1px;
        }

        .brand span { color: var(--blue); }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .nav-btn {
            min-height: 40px;
            padding: 0 14px;
            border-radius: 999px;
            color: var(--text);
            font-size: .86rem;
            font-weight: 850;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: .22s ease;
            position: relative;
        }

        .nav-btn:hover,
        .nav-btn.active {
            color: var(--blue);
            background: var(--blue-soft);
        }

        .nav-notification-badge {
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            border-radius: 999px;
            background: var(--blue);
            color: #fff;
            font-size: .66rem;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .profile-menu { position: relative; }

        .profile-trigger {
            border: 1px solid var(--line);
            background: #ffffff;
            min-height: 46px;
            padding: 5px 12px 5px 5px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 9px;
            cursor: pointer;
            color: var(--navy);
            font-size: .84rem;
            font-weight: 900;
            box-shadow: 0 10px 24px rgba(15, 23, 42, .05);
            transition: .22s ease;
        }

        .profile-trigger:hover,
        .profile-menu:focus-within .profile-trigger {
            border-color: #bfdbfe;
            background: var(--blue-soft);
            color: var(--blue);
        }

        .avatar-sm,
        .avatar-md,
        .avatar-lg {
            border-radius: 50%;
            overflow: hidden;
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            color: var(--blue);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            flex-shrink: 0;
        }

        .avatar-sm { width: 36px; height: 36px; font-size: .9rem; }
        .avatar-md { width: 54px; height: 54px; font-size: 1.25rem; }
        .avatar-lg { width: 66px; height: 66px; font-size: 1.45rem; }

        .avatar-sm img,
        .avatar-md img,
        .avatar-lg img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-dropdown {
            position: absolute;
            top: calc(100% + 12px);
            right: 0;
            width: 285px;
            padding: 10px;
            border-radius: 22px;
            background: rgba(255, 255, 255, .98);
            border: 1px solid var(--line);
            box-shadow: 0 28px 70px rgba(15, 23, 42, .18);
            opacity: 0;
            visibility: hidden;
            transform: translateY(8px);
            transition: .2s ease;
            z-index: 3000;
        }

        .profile-menu:hover .profile-dropdown,
        .profile-menu:focus-within .profile-dropdown {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .dropdown-head {
            padding: 14px;
            border-radius: 18px;
            background: var(--blue-soft);
            border: 1px solid #dbeafe;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 8px;
        }

        .dropdown-name {
            color: var(--navy);
            font-size: .94rem;
            font-weight: 900;
            line-height: 1.2;
        }

        .dropdown-unit {
            color: var(--muted);
            font-size: .76rem;
            font-weight: 700;
            margin-top: 4px;
        }

        .dropdown-link {
            min-height: 52px;
            padding: 12px 13px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--navy);
            font-weight: 900;
            transition: .18s ease;
        }

        .dropdown-link:hover { background: #f8fafc; color: var(--blue); }

        .dropdown-link i {
            width: 36px;
            height: 36px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--blue);
            background: var(--blue-soft);
        }

        .dropdown-footer {
            border-top: 1px solid var(--line);
            padding-top: 8px;
            margin-top: 8px;
        }

        .dropdown-logout { color: var(--red); }
        .dropdown-logout:hover { background: var(--red-soft); color: var(--red); }
        .dropdown-logout i { color: var(--red); background: var(--red-soft); }

        .dashboard {
            width: min(1240px, calc(100% - 48px));
            margin: 0 auto;
            padding: 54px 0 58px;
            position: relative;
            z-index: 1;
        }

        .hero-section {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 330px;
            gap: 28px;
            align-items: center;
            margin-bottom: 26px;
        }

        .hero-copy {
            min-height: 170px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .welcome-small {
            color: var(--blue);
            font-size: .95rem;
            font-weight: 900;
            margin-bottom: 10px;
        }

        .hero-title {
            color: var(--navy);
            font-size: clamp(2.4rem, 4.2vw, 4.4rem);
            line-height: 1.02;
            font-weight: 900;
            letter-spacing: -2.5px;
            margin-bottom: 14px;
            text-shadow: 0 1px 18px rgba(255, 255, 255, .72);
        }

        .hero-copy p {
            color: var(--muted);
            font-size: 1.03rem;
            line-height: 1.65;
            font-weight: 650;
            max-width: 610px;
            margin-bottom: 18px;
            text-shadow: 0 1px 18px rgba(255, 255, 255, .72);
        }

        .pill-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .info-pill {
            height: 38px;
            padding: 0 14px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--blue);
            background: rgba(239, 246, 255, .86);
            border: 1px solid #dbeafe;
            font-size: .83rem;
            font-weight: 900;
        }

        .info-pill.green {
            color: var(--green);
            background: var(--green-soft);
            border-color: #bbf7d0;
        }

        .resident-card {
            padding: 18px;
            border-radius: 24px;
            background: rgba(255, 255, 255, .90);
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .resident-card small {
            display: block;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .08em;
            font-size: .68rem;
            font-weight: 900;
            margin-bottom: 4px;
        }

        .resident-card strong {
            display: block;
            color: var(--navy);
            font-size: 1rem;
            font-weight: 900;
            line-height: 1.25;
        }

        .resident-card span {
            display: block;
            margin-top: 4px;
            color: var(--muted);
            font-size: .8rem;
            font-weight: 750;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 340px;
            gap: 28px;
            align-items: start;
        }

        .main-stack,
        .side-stack { display: grid; gap: 22px; }

        .stats-strip {
            min-height: 112px;
            padding: 18px 20px;
            border-radius: 24px;
            background: rgba(255, 255, 255, .90);
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0;
        }

        .mini-stat {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 0 16px;
            border-right: 1px solid var(--soft-line);
        }

        .mini-stat:last-child { border-right: 0; }

        .stat-icon {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 1.2rem;
        }

        .stat-icon.blue { color: var(--blue); background: var(--blue-soft); }
        .stat-icon.orange { color: var(--orange); background: var(--orange-soft); }
        .stat-icon.green { color: var(--green); background: var(--green-soft); }
        .stat-icon.purple { color: var(--purple); background: var(--purple-soft); }

        .mini-stat .num {
            display: block;
            color: var(--navy);
            font-size: 1.55rem;
            font-weight: 900;
            line-height: 1;
            margin-bottom: 5px;
        }

        .mini-stat .label {
            display: block;
            color: var(--text);
            font-size: .86rem;
            font-weight: 850;
            margin-bottom: 3px;
        }

        .mini-stat .sub {
            color: var(--muted);
            font-size: .76rem;
            font-weight: 700;
        }

        .section-card {
            padding: 24px;
            border-radius: 24px;
            background: rgba(255, 255, 255, .90);
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
        }

        .section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
        }

        .section-head h2 {
            color: var(--navy);
            font-size: 1.1rem;
            font-weight: 900;
            letter-spacing: -.3px;
        }

        .section-head a {
            color: var(--blue);
            font-size: .82rem;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .actions-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }

        .action-card {
            min-height: 198px;
            padding: 22px;
            border-radius: 22px;
            background: rgba(255, 255, 255, .88);
            border: 1px solid var(--line);
            box-shadow: 0 10px 28px rgba(15, 23, 42, .045);
            color: var(--text);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
            transition: .22s ease;
        }

        .action-card::after {
            content: "";
            position: absolute;
            width: 110px;
            height: 110px;
            right: -56px;
            bottom: -56px;
            border-radius: 50%;
            background: rgba(37, 99, 235, .07);
            transition: .22s ease;
        }

        .action-card:hover {
            transform: translateY(-5px);
            border-color: #bfdbfe;
            box-shadow: var(--shadow-hover);
        }

        .action-card:hover::after { transform: scale(1.25); }

        .action-icon {
            width: 58px;
            height: 58px;
            border-radius: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--blue);
            background: var(--blue-soft);
            font-size: 1.45rem;
            margin-bottom: 18px;
        }

        .action-icon.purple { color: var(--purple); background: var(--purple-soft); }
        .action-icon.green { color: var(--green); background: var(--green-soft); }

        .action-card h3 {
            color: var(--navy);
            font-size: 1.1rem;
            font-weight: 900;
            margin-bottom: 8px;
        }

        .action-card p {
            color: var(--muted);
            font-size: .86rem;
            line-height: 1.55;
            font-weight: 650;
            margin-bottom: 20px;
        }

        .action-btn {
            width: fit-content;
            padding: 10px 18px;
            border-radius: 999px;
            border: 1px solid #bfdbfe;
            color: var(--blue);
            background: #fff;
            font-size: .82rem;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            position: relative;
            z-index: 2;
        }

        .action-btn.green {
            color: var(--green);
            border-color: #bbf7d0;
        }

        .activity-empty {
            min-height: 128px;
            display: flex;
            align-items: center;
            gap: 20px;
            color: var(--muted);
        }

        .empty-icon {
            width: 76px;
            height: 76px;
            border-radius: 26px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--blue);
            background: var(--blue-soft);
            font-size: 1.6rem;
            flex-shrink: 0;
        }

        .activity-empty strong {
            display: block;
            color: var(--navy);
            font-size: 1rem;
            font-weight: 900;
            margin-bottom: 6px;
        }

        .activity-list { display: grid; gap: 12px; }

        .activity-row {
            padding: 14px 0;
            border-bottom: 1px solid var(--soft-line);
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }

        .activity-row:last-child { border-bottom: 0; }

        .activity-dot {
            width: 34px;
            height: 34px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--blue);
            background: var(--blue-soft);
            flex-shrink: 0;
        }

        .activity-title {
            color: var(--navy);
            font-size: .9rem;
            font-weight: 900;
            margin-bottom: 3px;
        }

        .activity-msg {
            color: var(--muted);
            font-size: .8rem;
            line-height: 1.45;
            font-weight: 650;
        }

        .parking-card {
            padding: 26px;
            border-radius: 26px;
            background:
                radial-gradient(circle at top right, rgba(219, 234, 254, .90), transparent 30%),
                rgba(255, 255, 255, .92);
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
            overflow: hidden;
            position: relative;
        }

        .parking-card::after {
            content: "";
            position: absolute;
            right: -34px;
            bottom: -36px;
            width: 130px;
            height: 130px;
            border-radius: 50%;
            background: rgba(37, 99, 235, .07);
            pointer-events: none;
        }

        .parking-title {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 22px;
        }

        .parking-icon {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            color: #fff;
            background: linear-gradient(135deg, var(--blue-2), var(--blue));
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            box-shadow: 0 16px 34px rgba(37,99,235,.22);
        }

        .parking-title h2 {
            color: var(--navy);
            font-size: 1.2rem;
            font-weight: 900;
        }

        .parking-payment {
            margin-bottom: 18px;
        }

        .payment-label {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 10px;
            border-radius: 999px;
            font-size: .76rem;
            font-weight: 900;
            margin-bottom: 12px;
        }

        .payment-label.paid { color: var(--green); background: var(--green-soft); }
        .payment-label.pending { color: #b45309; background: #fffbeb; }
        .payment-label.danger { color: var(--red); background: var(--red-soft); }
        .payment-label.neutral { color: var(--muted); background: #f1f5f9; }

        .payment-amount {
            color: var(--navy);
            font-size: 2rem;
            font-weight: 900;
            letter-spacing: -1px;
            margin-bottom: 6px;
        }

        .payment-sub {
            color: var(--muted);
            font-size: .86rem;
            font-weight: 700;
            line-height: 1.5;
        }

        .parking-details {
            margin: 20px 0;
            display: grid;
            gap: 12px;
        }

        .detail-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-radius: 16px;
            background: rgba(255,255,255,.74);
            border: 1px solid var(--soft-line);
        }

        .detail-row i {
            width: 36px;
            height: 36px;
            border-radius: 12px;
            color: var(--blue);
            background: var(--blue-soft);
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .detail-row small {
            display: block;
            color: var(--muted);
            font-size: .72rem;
            font-weight: 850;
            margin-bottom: 3px;
        }

        .detail-row strong {
            color: var(--navy);
            font-size: .92rem;
            font-weight: 900;
        }

        .pay-btn {
            width: 100%;
            min-height: 52px;
            border-radius: 999px;
            border: 0;
            color: #fff;
            background: linear-gradient(135deg, var(--blue-2), var(--blue));
            box-shadow: 0 18px 34px rgba(37,99,235,.22);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: .9rem;
            font-weight: 900;
            position: relative;
            z-index: 2;
            transition: .22s ease;
        }

        .pay-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 24px 46px rgba(37,99,235,.28);
        }

        @media (max-width: 1120px) {
            .dashboard-grid,
            .hero-section { grid-template-columns: 1fr; }
            .side-stack { grid-template-columns: 1fr; }
            .resident-card { max-width: 360px; }
            .stats-strip { grid-template-columns: repeat(2, 1fr); }
            .mini-stat:nth-child(2) { border-right: 0; }
            .mini-stat:nth-child(-n+2) { padding-bottom: 18px; border-bottom: 1px solid var(--soft-line); }
            .mini-stat:nth-child(n+3) { padding-top: 18px; }
        }

        @media (max-width: 860px) {
            .actions-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 680px) {
            .navbar {
                height: auto;
                padding: 16px 20px;
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            .nav-links { width: 100%; flex-wrap: wrap; }
            .profile-dropdown { left: 0; right: auto; width: min(285px, calc(100vw - 40px)); }
            .dashboard { width: calc(100% - 28px); padding-top: 32px; }
            .hero-title { font-size: 2.35rem; letter-spacing: -1.5px; }
            .stats-strip { grid-template-columns: 1fr; }
            .mini-stat { border-right: 0; border-bottom: 1px solid var(--soft-line); padding: 16px 4px; }
            .mini-stat:last-child { border-bottom: 0; }
        }
    </style>



<style id="resident-spacious-left-right-page">
    html, body {
        min-height: 100%;
    }

    body {
        overflow: hidden !important;
    }

    nav,
    .navbar {
        min-height: 78px !important;
    }

    .dashboard {
        width: min(1340px, calc(100% - 80px)) !important;
        height: calc(100vh - 78px) !important;
        margin: 0 auto !important;
        padding: 42px 0 32px !important;
        display: block !important;
        overflow: hidden !important;
    }

    .resident-screen {
        width: 100% !important;
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
        border: 1px solid var(--line) !important;
        box-shadow: var(--shadow) !important;
        display: flex !important;
        align-items: center !important;
    }

    .welcome-panel::before {
        content: "" !important;
        position: absolute !important;
        inset: 0 !important;
        background:
            linear-gradient(90deg, rgba(255,255,255,.94), rgba(255,255,255,.64)),
            radial-gradient(circle at 88% 12%, rgba(59,130,246,.15), transparent 28%) !important;
        pointer-events: none !important;
    }

    .welcome-copy {
        position: relative !important;
        z-index: 1 !important;
    }

    .welcome-small {
        color: #2563eb !important;
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
        max-width: 680px !important;
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

    .stats-strip {
        height: 104px !important;
        min-height: 104px !important;
        padding: 14px 18px !important;
        border-radius: 26px !important;
        background: rgba(255, 255, 255, .92) !important;
        border: 1px solid var(--line) !important;
        box-shadow: var(--shadow) !important;
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
        font-size: 1rem !important;
    }

    .mini-stat .num {
        display: block !important;
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

    .section-card {
        padding: 20px 22px 22px !important;
        border-radius: 30px !important;
        background: rgba(255, 255, 255, .93) !important;
        border: 1px solid var(--line) !important;
        box-shadow: var(--shadow) !important;
        overflow: hidden !important;
    }

    .section-head {
        display: flex !important;
        align-items: flex-end !important;
        justify-content: space-between !important;
        gap: 12px !important;
        margin-bottom: 18px !important;
    }

    .section-head h2 {
        font-size: 1.18rem !important;
        font-weight: 900 !important;
        margin: 0 !important;
    }

    .section-head span {
        color: #64748b !important;
        font-size: .82rem !important;
        font-weight: 750 !important;
    }

    .actions-grid {
        display: grid !important;
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        gap: 18px !important;
    }

    .action-card {
        min-height: 170px !important;
        height: auto !important;
        padding: 18px !important;
        border-radius: 24px !important;
        background: rgba(255, 255, 255, .74) !important;
        border: 1px solid #d9e4f2 !important;
        display: flex !important;
        flex-direction: column !important;
        justify-content: space-between !important;
        overflow: hidden !important;
        text-decoration: none !important;
        transition: .22s ease !important;
    }

    .action-card:hover {
        transform: translateY(-2px) !important;
        box-shadow: 0 18px 42px rgba(15, 23, 42, .09) !important;
    }

    .action-icon {
        width: 50px !important;
        height: 50px !important;
        margin-bottom: 13px !important;
        border-radius: 17px !important;
    }

    .action-card h3 {
        margin: 0 0 7px !important;
        color: #0f172a !important;
        font-size: 1.04rem !important;
        font-weight: 900 !important;
    }

    .action-card p {
        margin: 0 !important;
        color: #64748b !important;
        font-size: .83rem !important;
        line-height: 1.42 !important;
        font-weight: 750 !important;
    }

    .action-btn {
        width: max-content !important;
        min-height: 34px !important;
        margin-top: 14px !important;
        padding: 0 14px !important;
        border-radius: 999px !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 8px !important;
        font-size: .8rem !important;
        font-weight: 900 !important;
    }

    .resident-summary-card {
        min-height: 110px !important;
        padding: 17px !important;
        border-radius: 26px !important;
        background: rgba(255, 255, 255, .92) !important;
        border: 1px solid var(--line) !important;
        box-shadow: var(--shadow) !important;
        display: flex !important;
        align-items: center !important;
        gap: 14px !important;
    }

    .resident-summary-card .avatar-lg {
        width: 64px !important;
        height: 64px !important;
        flex: 0 0 64px !important;
        border-radius: 21px !important;
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

    .parking-card {
        min-height: 400px !important;
        padding: 24px !important;
        border-radius: 30px !important;
        background: rgba(255, 255, 255, .92) !important;
        border: 1px solid var(--line) !important;
        box-shadow: var(--shadow) !important;
        display: flex !important;
        flex-direction: column !important;
        overflow: hidden !important;
    }

    .parking-title {
        display: flex !important;
        align-items: center !important;
        gap: 14px !important;
        margin-bottom: 18px !important;
    }

    .parking-title h2 {
        margin: 0 !important;
        color: #0f172a !important;
        font-size: 1.18rem !important;
        font-weight: 900 !important;
        line-height: 1.2 !important;
    }

    .parking-title span {
        display: block !important;
        margin-top: 4px !important;
        color: #64748b !important;
        font-size: .76rem !important;
        font-weight: 800 !important;
    }

    .parking-icon {
        width: 52px !important;
        height: 52px !important;
        flex: 0 0 52px !important;
        border-radius: 18px !important;
    }

    .parking-payment {
        margin: 0 0 18px !important;
    }

    .payment-label {
        min-height: 29px !important;
        padding: 0 11px !important;
        border-radius: 999px !important;
        font-size: .74rem !important;
        font-weight: 900 !important;
    }

    .payment-amount {
        margin: 11px 0 6px !important;
        color: #0f172a !important;
        font-size: 2.45rem !important;
        line-height: 1 !important;
        font-weight: 900 !important;
        letter-spacing: -.05em !important;
    }

    .payment-sub {
        color: #64748b !important;
        font-size: .83rem !important;
        font-weight: 800 !important;
    }

    .parking-details {
        display: grid !important;
        gap: 10px !important;
        margin-bottom: 18px !important;
    }

    .detail-row {
        min-height: 61px !important;
        padding: 11px 13px !important;
        border-radius: 18px !important;
    }

    .detail-row i {
        width: 36px !important;
        height: 36px !important;
        border-radius: 13px !important;
    }

    .detail-row small {
        font-size: .67rem !important;
        font-weight: 900 !important;
    }

    .detail-row strong {
        font-size: .84rem !important;
        line-height: 1.18 !important;
        font-weight: 900 !important;
    }

    .pay-btn {
        width: 100% !important;
        min-height: 52px !important;
        margin-top: auto !important;
        border-radius: 18px !important;
        font-size: .87rem !important;
        font-weight: 900 !important;
    }

    .announcements-card,
    .announcement-card,
    .activity-card,
    .dashboard-grid,
    .main-stack,
    .side-stack,
    .hero-section {
        display: none !important;
    }

    @media (max-width: 1150px) {
        body {
            overflow-y: auto !important;
        }

        .dashboard {
            width: calc(100% - 36px) !important;
            height: auto !important;
            overflow: visible !important;
        }

        .resident-screen {
            grid-template-columns: 1fr !important;
        }
    }

    @media (max-width: 760px) {
        .dashboard {
            width: calc(100% - 24px) !important;
        }

        .actions-grid,
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
    }
</style>


<style id="resident-lou-background-final">
    body::before {
        background:
            linear-gradient(105deg,
                rgba(255,255,255,.82) 0%,
                rgba(248,252,255,.66) 42%,
                rgba(218,238,255,.52) 100%
            ),
            url("lou.jpg") center/cover no-repeat !important;
    }

    body::after {
        backdrop-filter: blur(1.5px) !important;
        background:
            radial-gradient(circle at 12% 18%, rgba(37, 99, 235, .07), transparent 24%),
            radial-gradient(circle at 88% 20%, rgba(56, 189, 248, .14), transparent 25%),
            radial-gradient(circle at 82% 86%, rgba(37, 99, 235, .06), transparent 24%),
            linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.18)) !important;
    }
</style>

</head>
<body>

<nav class="navbar">
    <a href="resident.php" class="brand">Smart<span>VMS</span></a>

    <div class="nav-links">
        <a href="resident.php" class="nav-btn active">
            <i class="fas fa-th-large"></i>
            Dashboard
        </a>

        <a href="notifications.php" class="nav-btn notification-nav-btn">
            <i class="fas fa-bell"></i>
            Notifications
            <?php if ($notificationCount > 0): ?>
                <span class="nav-notification-badge"><?= $notificationCount > 99 ? '99+' : (int)$notificationCount ?></span>
            <?php endif; ?>
        </a>

        <div class="profile-menu">
            <button type="button" class="profile-trigger" aria-label="Open profile menu">
                <span class="avatar-sm">
                    <?php if ($profilePhotoUrl): ?>
                        <img src="<?= e($profilePhotoUrl) ?>" alt="Resident photo">
                    <?php else: ?>
                        <?= e($residentInitial) ?>
                    <?php endif; ?>
                </span>
                <span><?= e($greetingName) ?></span>
                <i class="fas fa-chevron-down"></i>
            </button>

            <div class="profile-dropdown">
                <div class="dropdown-head">
                    <div class="avatar-md">
                        <?php if ($profilePhotoUrl): ?>
                            <img src="<?= e($profilePhotoUrl) ?>" alt="Resident photo">
                        <?php else: ?>
                            <?= e($residentInitial) ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="dropdown-name"><?= e($residentName) ?></div>
                        <div class="dropdown-unit"><?= e($unitText) ?></div>
                    </div>
                </div>

                <a href="resident_profile.php" class="dropdown-link">
                    <i class="fas fa-user"></i>
                    My Profile
                </a>

                <a href="resident_settings.php" class="dropdown-link">
                    <i class="fas fa-lock"></i>
                    Change Password
                </a>

                <div class="dropdown-footer">
                    <a href="../core/logout.php" class="dropdown-link dropdown-logout">
                        <i class="fas fa-right-from-bracket"></i>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </div>
</nav>

<main class="dashboard">
    <section class="resident-screen">
        <div class="left-panel">
            <section class="welcome-panel">
                <div class="welcome-copy">
                    <div class="welcome-small">Welcome back,</div>
                    <h1>Resident Dashboard</h1>
                    <p>Manage visitor requests, invitations and parking access from one clear workspace.</p>

                    <div class="pill-row">
                        <div class="info-pill"><i class="fas fa-door-closed"></i><?= e($unitText) ?></div>
                        <div class="info-pill green"><i class="fas fa-user-check"></i>Resident</div>
                    </div>
                </div>
            </section>

            <div class="stats-strip">
                <div class="mini-stat">
                    <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                    <div>
                        <span class="num"><?= (int)$todayVisitors ?></span>
                        <span class="label">Today Visitors</span>
                        <span class="sub"><?= (int)$checkedInBookings ?> checked in</span>
                    </div>
                </div>

                <div class="mini-stat">
                    <div class="stat-icon orange"><i class="fas fa-clipboard-list"></i></div>
                    <div>
                        <span class="num"><?= (int)$pendingBookings ?></span>
                        <span class="label">Pending</span>
                        <span class="sub">Need action</span>
                    </div>
                </div>

                <div class="mini-stat">
                    <div class="stat-icon green"><i class="fas fa-circle-check"></i></div>
                    <div>
                        <span class="num"><?= (int)$approvedBookings ?></span>
                        <span class="label">Approved</span>
                        <span class="sub">This week</span>
                    </div>
                </div>

                <div class="mini-stat">
                    <div class="stat-icon purple"><i class="fas fa-car-side"></i></div>
                    <div>
                        <span class="num"><?= (int)$activeParkingSlots ?></span>
                        <span class="label">Parking</span>
                        <span class="sub"><?= e($paymentStatus) ?></span>
                    </div>
                </div>
            </div>

            <section class="section-card quick-card">
                <div class="section-head">
                    <h2>Quick Actions</h2>
                    <span>Choose what you want to manage.</span>
                </div>

                <div class="actions-grid">
                    <a href="resident_invite.php" class="action-card">
                        <div class="action-icon"><i class="fas fa-user-plus"></i></div>
                        <div class="action-copy">
                            <h3>Invite Visitor</h3>
                            <p>Send invitation to a guest.</p>
                        </div>
                        <div class="action-btn">Send Invite <i class="fas fa-arrow-right"></i></div>
                    </a>

                    <a href="resident_requests.php" class="action-card">
                        <div class="action-icon purple"><i class="fas fa-clipboard-list"></i></div>
                        <div class="action-copy">
                            <h3>Visitor Requests</h3>
                            <p>Review pending requests.</p>
                        </div>
                        <div class="action-btn">Review Now <i class="fas fa-arrow-right"></i></div>
                    </a>

                    <a href="resident_vehicles.php" class="action-card">
                        <div class="action-icon green"><i class="fas fa-square-parking"></i></div>
                        <div class="action-copy">
                            <h3>My Parking</h3>
                            <p>Vehicle, slot and payment.</p>
                        </div>
                        <div class="action-btn green">Manage <i class="fas fa-arrow-right"></i></div>
                    </a>
                </div>
            </section>
        </div>

        <aside class="right-panel">
            <div class="resident-summary-card">
                <div class="avatar-lg">
                    <?php if ($profilePhotoUrl): ?>
                        <img src="<?= e($profilePhotoUrl) ?>" alt="Resident photo">
                    <?php else: ?>
                        <?= e($residentInitial) ?>
                    <?php endif; ?>
                </div>
                <div>
                    <small>Resident</small>
                    <strong><?= e($residentName) ?></strong>
                    <span><i class="fas fa-building"></i> <?= e($unitText) ?></span>
                </div>
            </div>

            <div class="parking-card">
                <div class="parking-title">
                    <div class="parking-icon"><i class="fas fa-square-parking"></i></div>
                    <div>
                        <h2>Parking Payment</h2>
                        <span><?= e($currentMonthLabel) ?></span>
                    </div>
                </div>

                <div class="parking-payment">
                    <div class="payment-label <?= e($parkingStatusClass) ?>">
                        <i class="fas fa-circle"></i>
                        <?= e($parkingStatusText) ?>
                    </div>

                    <div class="payment-amount">
                        <?= $paymentAmountDue > 0 ? e(money_resident_dashboard($paymentAmountDue)) : e(money_resident_dashboard($monthlyFee)) ?>
                    </div>
                    <div class="payment-sub">Due Date: <?= e($parkingDueDate) ?></div>
                </div>

                <div class="parking-details">
                    <div class="detail-row">
                        <i class="fas fa-car-side"></i>
                        <div>
                            <small>Vehicle</small>
                            <strong><?= (int)$myVehicles > 0 ? (int)$myVehicles . ' active vehicle(s)' : 'No vehicle yet' ?></strong>
                        </div>
                    </div>

                    <div class="detail-row">
                        <i class="fas fa-location-dot"></i>
                        <div>
                            <small>Parking Slot</small>
                            <strong><?= e($slotText) ?></strong>
                        </div>
                    </div>

                    <div class="detail-row">
                        <i class="fas fa-calendar-check"></i>
                        <div>
                            <small>Billing Month</small>
                            <strong><?= e($currentMonthLabel) ?></strong>
                        </div>
                    </div>
                </div>

                <a href="resident_vehicles.php" class="pay-btn">
                    <?= e($paymentActionText) ?>
                    <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        </aside>
    </section>
</main>

<?php require_once __DIR__ . '/resident_notification_popup.php'; ?>
</body>
</html>
