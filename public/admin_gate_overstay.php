<?php
require_once '../core/security.php';
require_login(['admin', 'superadmin']);

$pdo = db();

if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function overstay_table_exists(PDO $pdo, string $table): bool {
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

function overstay_column_exists(PDO $pdo, string $table, string $column): bool {
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

function overstay_count(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function overstay_rows(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function overstay_fmt_dt($value): string {
    if (!$value) {
        return '-';
    }

    $time = strtotime((string)$value);
    return $time ? date('d M Y, g:i A', $time) : '-';
}

function overstay_fmt_date($value): string {
    if (!$value) {
        return '-';
    }

    $time = strtotime((string)$value);
    return $time ? date('d M Y', $time) : '-';
}

function overstay_text($endTime): string {
    if (!$endTime) {
        return '-';
    }

    $end = strtotime((string)$endTime);
    if (!$end || $end >= time()) {
        return '-';
    }

    $seconds = max(60, time() - $end);
    $days = intdiv($seconds, 86400);
    $seconds %= 86400;
    $hours = intdiv($seconds, 3600);
    $seconds %= 3600;
    $minutes = max(1, intdiv($seconds, 60));

    if ($days > 0) {
        return $days . 'd ' . $hours . 'h overdue';
    }

    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm overdue';
    }

    return $minutes . 'm overdue';
}

function overstay_clean_plate($value): string {
    $plate = strtoupper(trim((string)($value ?? '')));
    $plate = preg_replace('/[^A-Z0-9]/', '', $plate);
    return $plate ?: '-';
}

function overstay_text_or_dash($value): string {
    $value = trim((string)($value ?? ''));
    return $value !== '' ? $value : '-';
}

$hasBookings = overstay_table_exists($pdo, 'bookings');
$hasUsers = overstay_table_exists($pdo, 'users');
$hasSlots = overstay_table_exists($pdo, 'parking_slots');
$hasBlacklist = overstay_table_exists($pdo, 'blacklisted_plates');
$hasGateLogs = overstay_table_exists($pdo, 'gate_logs');

$hasBookingApartment = $hasBookings && overstay_column_exists($pdo, 'bookings', 'apartment_id');
$hasBookingVisitDate = $hasBookings && overstay_column_exists($pdo, 'bookings', 'visit_date');
$hasBookingActualExit = $hasBookings && overstay_column_exists($pdo, 'bookings', 'actual_exit_at');
$hasUserApartment = $hasUsers && overstay_column_exists($pdo, 'users', 'apartment_id');
$hasUserContact = $hasUsers && overstay_column_exists($pdo, 'users', 'contact_number');
$hasSlotApartment = $hasSlots && overstay_column_exists($pdo, 'parking_slots', 'apartment_id');
$hasResidentUnits = overstay_table_exists($pdo, 'resident_units');
$hasUnits = overstay_table_exists($pdo, 'units');

$currentUserId = (int)($_SESSION['uid'] ?? 0);
$currentRole = $_SESSION['role'] ?? 'admin';
$currentEmail = $_SESSION['email'] ?? 'admin@apt.com';
$currentApartmentId = $_SESSION['apartment_id'] ?? null;

if (($currentApartmentId === null || $currentApartmentId === '') && $currentUserId > 0 && $hasUserApartment && $currentRole !== 'superadmin') {
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

$currentApartmentName = 'Ixoro Apartment';
if (!empty($currentApartmentId) && overstay_table_exists($pdo, 'apartments')) {
    try {
        $stmt = $pdo->prepare("SELECT apartment_name FROM apartments WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$currentApartmentId]);
        $apt = $stmt->fetchColumn();
        if ($apt) {
            $currentApartmentName = (string)$apt;
        }
    } catch (Throwable $e) {
        // keep fallback
    }
}

$profileInitial = strtoupper(substr(trim($currentEmail ?: 'A'), 0, 1));
if ($profileInitial === '') {
    $profileInitial = 'A';
}

$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = '';

$scopeWhereParts = [];
$scopeParams = [];

if ($currentRole !== 'superadmin' && !empty($currentApartmentId)) {
    if ($hasBookingApartment) {
        $scopeWhereParts[] = 'b.apartment_id = ?';
        $scopeParams[] = (int)$currentApartmentId;
    }

    if ($hasSlotApartment) {
        $scopeWhereParts[] = 'ps.apartment_id = ?';
        $scopeParams[] = (int)$currentApartmentId;
    }

    if ($hasUserApartment) {
        $scopeWhereParts[] = 'resident.apartment_id = ?';
        $scopeParams[] = (int)$currentApartmentId;
    }
}

$scopeSql = '';
if ($scopeWhereParts) {
    $scopeSql = ' AND (' . implode(' OR ', $scopeWhereParts) . ') ';
}

$unitJoin = '';
$unitSelect = "'-' AS unit_text";
if ($hasResidentUnits && $hasUnits) {
    $unitSelect = "COALESCE(CONCAT('Block ', u.block_no, ' / Floor ', u.floor_no, ' / Unit ', u.unit_no), '-') AS unit_text";
    $unitJoin = "
        LEFT JOIN (
            SELECT resident_id, MIN(unit_id) AS unit_id
            FROM resident_units
            WHERE status = 'active' OR status IS NULL
            GROUP BY resident_id
        ) ru ON ru.resident_id = resident.id
        LEFT JOIN units u ON u.id = ru.unit_id
    ";
}

$actualExitCondition = $hasBookingActualExit ? " AND (b.actual_exit_at IS NULL OR b.actual_exit_at = '') " : "";
$overstayBaseWhere = "
    (
        LOWER(COALESCE(b.status, '')) IN ('checked_in', 'overstay')
        AND b.end_time IS NOT NULL
        AND b.end_time < NOW()
        {$actualExitCondition}
    )
";

$searchWhere = '';
$searchParams = [];
if ($search !== '') {
    $term = '%' . $search . '%';
    $searchWhere = "
        AND (
            b.visitor_name LIKE ?
            OR b.plate_no LIKE ?
            OR COALESCE(resident.full_name, '') LIKE ?
            OR COALESCE(ps.slot_no, '') LIKE ?
            OR COALESCE(ps.block_name, '') LIKE ?
            OR {$unitSelect} LIKE ?
        )
    ";
    $searchParams = [$term, $term, $term, $term, $term, $term];
}

$blacklistJoin = '';
$blacklistSelect = "0 AS is_blacklisted";
$blacklistStatusWhere = '';

if ($hasBlacklist) {
    $blacklistPlateCol = overstay_column_exists($pdo, 'blacklisted_plates', 'plate_no') ? 'plate_no' : null;
    $blacklistStatusCol = overstay_column_exists($pdo, 'blacklisted_plates', 'status') ? 'status' : null;

    if ($blacklistPlateCol) {
        $blacklistJoin = "
            LEFT JOIN blacklisted_plates bp
                ON REPLACE(UPPER(bp.`{$blacklistPlateCol}`), ' ', '') = REPLACE(UPPER(b.plate_no), ' ', '')
        ";
        $blacklistSelect = $blacklistStatusCol
            ? "CASE WHEN bp.`{$blacklistPlateCol}` IS NOT NULL AND LOWER(COALESCE(bp.`{$blacklistStatusCol}`, 'active')) = 'active' THEN 1 ELSE 0 END AS is_blacklisted"
            : "CASE WHEN bp.`{$blacklistPlateCol}` IS NOT NULL THEN 1 ELSE 0 END AS is_blacklisted";

    }
}

$overstayRows = [];
if ($hasBookings) {
    $selectContact = $hasUserContact ? "resident.contact_number AS resident_contact" : "NULL AS resident_contact";

    $overstayRows = overstay_rows($pdo, "
        SELECT
            b.id AS booking_id,
            b.visitor_name,
            b.visitor_email,
            b.visitor_phone,
            b.plate_no,
            b.visitor_type,
            b.status AS booking_status,
            b.start_time,
            b.end_time,
            b.created_at,
            {$unitSelect},
            resident.id AS resident_id,
            resident.full_name AS resident_name,
            resident.email AS resident_email,
            {$selectContact},
            ps.id AS slot_id,
            ps.block_name,
            ps.slot_no,
            ps.status AS slot_status,
            {$blacklistSelect}
        FROM bookings b
        LEFT JOIN users resident
            ON resident.id = b.resident_id
        LEFT JOIN parking_slots ps
            ON ps.id = b.slot_id
        {$unitJoin}
        {$blacklistJoin}
        WHERE {$overstayBaseWhere}
        {$scopeSql}
        {$searchWhere}
        {$blacklistStatusWhere}
        ORDER BY b.end_time ASC, b.id DESC
        LIMIT 200
    ", array_merge($scopeParams, $searchParams));
}

$overstayCount = count($overstayRows);

$todayVisitsWhereParts = [];
$todayParams = [];
if ($hasBookings) {
    if ($hasBookingVisitDate) {
        $todayVisitsWhereParts[] = "DATE(b.visit_date) = CURDATE()";
    } else {
        $todayVisitsWhereParts[] = "DATE(b.start_time) = CURDATE()";
    }

    if ($scopeSql !== '') {
        $todayVisitsWhereParts[] = '(' . trim(str_replace(['AND (', ')'], ['(', ')'], $scopeSql)) . ')';
        $todayParams = array_merge($todayParams, $scopeParams);
    }
}

$todayVisits = 0;
if ($hasBookings) {
    $todayWhere = 'WHERE ' . implode(' AND ', $todayVisitsWhereParts);
    $todayVisits = overstay_count($pdo, "
        SELECT COUNT(*)
        FROM bookings b
        LEFT JOIN users resident ON resident.id = b.resident_id
        LEFT JOIN parking_slots ps ON ps.id = b.slot_id
        {$unitJoin}
        {$todayWhere}
    ", $todayParams);
}

$blacklistedPlates = 0;
if ($hasBlacklist) {
    $blacklistStatusCol = overstay_column_exists($pdo, 'blacklisted_plates', 'status') ? 'status' : null;
    $blacklistedPlates = $blacklistStatusCol
        ? overstay_count($pdo, "SELECT COUNT(*) FROM blacklisted_plates WHERE LOWER(COALESCE(`{$blacklistStatusCol}`, 'active')) = 'active'")
        : overstay_count($pdo, "SELECT COUNT(*) FROM blacklisted_plates");
}

$apartmentScopeText = !empty($currentApartmentId) || $currentRole === 'superadmin' ? 'OK' : 'OK';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Overstay Visitors | SmartVMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #dc2626;
            --primary-dark: #b91c1c;
            --text: #0f172a;
            --muted: #64748b;
            --line: #e5e7eb;
            --soft-red: #fff1f2;
            --soft-red-2: #fee2e2;
            --orange: #f97316;
            --green: #16a34a;
            --blue: #2563eb;
            --shadow: 0 20px 48px rgba(15, 23, 42, .08);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            height: 100vh;
            overflow: hidden;
            font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top right, rgba(220, 38, 38, .12), transparent 34%),
                linear-gradient(180deg, #ffffff, #f4f6fa);
            font-size: .88rem;
        }

        .dashboard-shell {
            display: grid;
            grid-template-columns: 260px minmax(0, 1fr);
            height: 100vh;
            overflow: hidden;
        }

        .sidebar {
            background: rgba(255,255,255,.86);
            border-right: 1px solid rgba(226,232,240,.9);
            backdrop-filter: blur(18px);
            padding: 24px 18px;
            overflow-y: auto;
        }

        .brand { display: flex; align-items: center; gap: 13px; margin-bottom: 26px; }
        .brand-icon {
            width: 44px;
            height: 44px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--primary), #b91c1c);
            color: white;
            display: grid;
            place-items: center;
            box-shadow: 0 14px 30px rgba(220,38,38,.25);
        }
        .brand-title { font-size: 1.05rem; font-weight: 950; letter-spacing: -.04em; }
        .brand-title span { color: var(--primary); }
        .brand-sub { margin-top: 2px; font-size: .68rem; text-transform: uppercase; letter-spacing: .12em; color: var(--muted); font-weight: 900; }

        .tenant-card {
            display: flex;
            gap: 12px;
            align-items: center;
            padding: 16px;
            border: 1px solid #fecaca;
            border-radius: 20px;
            background: rgba(255,241,242,.72);
            margin-bottom: 22px;
        }
        .tenant-icon {
            width: 38px;
            height: 38px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            background: #fee2e2;
            color: var(--primary);
        }
        .tenant-label { font-size: .64rem; color: var(--muted); font-weight: 950; text-transform: uppercase; letter-spacing: .08em; }
        .tenant-name { font-size: .82rem; font-weight: 900; margin-top: 2px; }

        .side-section { margin: 18px 8px 8px; color: #94a3b8; font-weight: 950; font-size: .66rem; text-transform: uppercase; letter-spacing: .12em; }
        .side-nav { display: grid; gap: 6px; }
        .side-link {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 12px;
            border-radius: 14px;
            color: #475569;
            text-decoration: none;
            font-weight: 850;
            font-size: .84rem;
            border: 0;
            background: transparent;
            cursor: pointer;
            font-family: inherit;
            text-align: left;
        }
        .side-link i { width: 18px; color: #94a3b8; }
        .side-link:hover, .side-link.current { background: #fff1f2; color: var(--primary); }
        .side-link.current i, .side-link:hover i { color: var(--primary); }
        .side-link.logout { background: #fff1f2; color: #991b1b; margin-top: 8px; }
        .side-parent .parent { justify-content: space-between; }
        .side-parent .left { display: flex; align-items: center; gap: 10px; }
        .side-parent .chevron { width: auto; font-size: .72rem; transition: .2s ease; }
        .side-parent.open .chevron { transform: rotate(180deg); }
        .submenu { display: none; margin: 2px 0 8px 27px; padding-left: 12px; border-left: 2px solid #fecaca; }
        .side-parent.open .submenu { display: grid; gap: 5px; }
        .submenu a {
            text-decoration: none;
            color: #64748b;
            font-weight: 850;
            font-size: .78rem;
            padding: 8px 10px;
            border-radius: 12px;
        }
        .submenu a:hover, .submenu a.sub-active { background: #fff1f2; color: var(--primary); }

        .main-content {
            padding: 24px 28px 26px;
            min-width: 0;
            height: 100vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 14px;
            flex: 0 0 auto;
        }

        .eyebrow {
            color: var(--primary);
            text-transform: uppercase;
            font-size: .74rem;
            font-weight: 950;
            letter-spacing: .12em;
            margin-bottom: 4px;
        }

        h1 {
            margin: 0;
            font-size: 1.8rem;
            line-height: 1.06;
            letter-spacing: -.065em;
            font-weight: 950;
        }

        .page-sub {
            margin: 7px 0 0;
            color: #475569;
            font-weight: 800;
            max-width: 720px;
            font-size: .84rem;
            line-height: 1.4;
        }

        .top-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .back-btn,
        .profile-dot {
            height: 44px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            text-decoration: none;
            font-weight: 950;
        }

        .back-btn {
            padding: 0 17px;
            gap: 8px;
            color: white;
            background: linear-gradient(145deg, var(--primary), var(--primary-dark));
            box-shadow: 0 14px 24px rgba(220, 38, 38, .20);
        }

        .profile-dot {
            width: 44px;
            color: white;
            background: linear-gradient(145deg, var(--primary), var(--primary-dark));
            box-shadow: 0 14px 24px rgba(220, 38, 38, .20);
        }

        .hero-card {
            min-height: 204px;
            height: 204px;
            border: 1px solid var(--line);
            border-radius: 22px;
            background:
                radial-gradient(circle at 9% 4%, rgba(248, 113, 113, .20), transparent 20%),
                radial-gradient(circle at 85% 28%, rgba(251, 146, 60, .12), transparent 28%),
                linear-gradient(115deg, #fff7f7 0%, #ffffff 42%, #fff1f2 100%);
            box-shadow: var(--shadow);
            padding: 20px 28px 22px;
            display: grid;
            grid-template-columns: minmax(360px, 1fr) 360px;
            gap: 20px;
            overflow: hidden;
            position: relative;
            margin-bottom: 16px;
            flex: 0 0 auto;
        }

        .hero-card::after {
            content: "";
            position: absolute;
            right: 26px;
            top: 20px;
            width: 68px;
            height: 68px;
            border-radius: 22px;
            background: rgba(220,38,38,.05);
            transform: rotate(10deg);
        }

        .hero-copy {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-width: 0;
        }

        .hero-kicker {
            color: var(--primary);
            text-transform: uppercase;
            font-weight: 950;
            letter-spacing: .12em;
            font-size: .76rem;
            margin-bottom: 5px;
        }

        .hero-number {
            font-size: 3.2rem;
            line-height: .9;
            letter-spacing: -.08em;
            font-weight: 950;
            color: #0b1b3a;
            margin-bottom: 5px;
        }

        .hero-note {
            color: var(--primary);
            font-weight: 950;
            font-size: .8rem;
            line-height: 1.32;
            max-width: 330px;
        }

        .hero-stats {
            display: flex;
            gap: 10px;
            margin-top: 12px;
            flex-wrap: wrap;
            position: relative;
            z-index: 5;
        }

        .hero-mini {
            width: 180px;
            min-height: 50px;
            border: 1px solid #e5e7eb;
            border-radius: 13px;
            background: rgba(255,255,255,.92);
            box-shadow: 0 8px 18px rgba(15,23,42,.05);
            padding: 8px 12px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .hero-mini strong {
            font-size: .98rem;
            line-height: 1;
            font-weight: 950;
        }

        .hero-mini .red { color: var(--primary); }
        .hero-mini .blue { color: #2563eb; }
        .hero-mini .green { color: var(--green); }

        .hero-mini span {
            margin-top: 5px;
            color: #64748b;
            font-size: .62rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .hero-visual {
            position: relative;
            min-height: 160px;
            z-index: 1;
        }

        .hero-clock {
            position: absolute;
            right: 300px;
            top: 2px;
            width: 104px;
            height: 104px;
            border-radius: 50%;
            border: 7px solid #ef4444;
            background: rgba(255,255,255,.92);
            box-shadow: 0 14px 24px rgba(220,38,38,.18);
        }

        .hero-clock::before {
            content: "";
            position: absolute;
            width: 5px;
            height: 32px;
            background: #0f172a;
            border-radius: 999px;
            left: 50%;
            top: 17px;
            transform: translateX(-50%);
        }

        .hero-clock::after {
            content: "";
            position: absolute;
            width: 5px;
            height: 37px;
            background: #0f172a;
            border-radius: 999px;
            left: 47px;
            top: 42px;
            transform: rotate(-45deg);
            transform-origin: top center;
        }

        .clock-dot {
            position: absolute;
            width: 10px;
            height: 10px;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            border-radius: 50%;
            background: #0f172a;
        }

        .hero-alert {
            position: absolute;
            right: 270px;
            top: 0;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: linear-gradient(145deg, #fb923c, #dc2626);
            color: white;
            display: grid;
            place-items: center;
            font-weight: 950;
            font-size: 1.25rem;
            box-shadow: 0 12px 22px rgba(220,38,38,.22);
            z-index: 3;
        }

        .hero-car {
            position: absolute;
            right: 260px;
            bottom: 12px;
            width: 300px;
            height: 86px;
            border-radius: 46px 56px 18px 18px;
            background: linear-gradient(180deg, #fb923c, #f97316 48%, #ea580c);
            box-shadow: 0 16px 26px rgba(15,23,42,.16);
        }

        .hero-car::before {
            content: "";
            position: absolute;
            left: 48px;
            top: -36px;
            width: 148px;
            height: 64px;
            border-radius: 54px 54px 14px 14px;
            background:
                linear-gradient(90deg, rgba(15,23,42,.82) 0 45%, rgba(15,23,42,.68) 45% 100%);
            border: 6px solid #f97316;
            box-shadow: inset 0 0 0 3px rgba(255,255,255,.14);
        }

        .hero-car::after {
            content: "";
            position: absolute;
            left: 26px;
            bottom: -20px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background:
                radial-gradient(circle, #cbd5e1 0 24%, #1f2937 25% 58%, #0f172a 59%);
            z-index: 2;
        }

        .wheel-right {
            position: absolute;
            right: 26px;
            bottom: -20px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background:
                radial-gradient(circle, #cbd5e1 0 24%, #1f2937 25% 58%, #0f172a 59%);
            z-index: 2;
        }

        .hero-sign {
            position: absolute;
            right: 215px;
            bottom: 50px;
            transform: rotate(5deg);
            background: #ef4444;
            color: white;
            border-radius: 8px;
            padding: 7px 10px;
            font-weight: 950;
            font-size: .82rem;
            letter-spacing: .03em;
            box-shadow: 0 12px 20px rgba(220,38,38,.16);
        }

        .hero-skyline {
            position: absolute;
            right: 28px;
            bottom: 24px;
            width: 320px;
            height: 64px;
            background:
                linear-gradient(to top, rgba(248,113,113,.10), rgba(248,113,113,.035));
            clip-path: polygon(0 100%, 0 70%, 7% 70%, 7% 46%, 12% 46%, 12% 100%, 18% 100%, 18% 40%, 24% 40%, 24% 100%, 32% 100%, 32% 58%, 37% 58%, 37% 100%, 45% 100%, 45% 35%, 52% 35%, 52% 100%, 60% 100%, 60% 63%, 66% 63%, 66% 100%, 76% 100%, 76% 50%, 84% 50%, 84% 100%, 100% 100%);
            opacity: .65;
        }


        /* Keep overstay banner compact but do not cut the stat cards. */
        .hero-card.compact-banner,
        .hero-card {
            min-height: 204px;
            height: 204px;
        }

        .list-card {
            border: 1px solid var(--line);
            border-radius: 22px;
            background: rgba(255,255,255,.98);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 0;
            flex: 1 1 auto;
            min-height: 0;
            display: flex;
            flex-direction: column;
        }

        .list-head {
            padding: 15px 18px;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
        }

        .list-title {
            font-size: 1rem;
            font-weight: 950;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .list-title i { color: var(--primary); }

        .filters {
            display: grid;
            grid-template-columns: minmax(250px, 1fr) 50px 50px;
            gap: 10px;
            padding: 14px 18px;
            border-bottom: 1px solid var(--line);
        }

        .input,
        .select {
            width: 100%;
            height: 44px;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: white;
            padding: 0 14px;
            font-family: inherit;
            font-weight: 800;
            outline: none;
        }

        .input:focus,
        .select:focus {
            border-color: #fca5a5;
            box-shadow: 0 0 0 4px #fee2e2;
        }

        .filter-btn,
        .reset-btn {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            border: 0;
            display: grid;
            place-items: center;
            cursor: pointer;
            font-weight: 950;
        }

        .filter-btn {
            background: linear-gradient(145deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 14px 24px rgba(220,38,38,.20);
        }

        .reset-btn {
            background: white;
            color: #64748b;
            border: 1px solid var(--line);
            text-decoration: none;
        }

        .table-wrap {
            overflow: auto;
            max-height: none;
            min-height: 0;
            flex: 1 1 auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 980px;
        }

        th {
            background: #f8fafc;
            color: #64748b;
            font-size: .68rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .06em;
            padding: 12px 14px;
            text-align: left;
            border-bottom: 1px solid var(--line);
            white-space: nowrap;
        }

        td {
            padding: 14px;
            border-bottom: 1px solid #edf2f7;
            vertical-align: middle;
            font-weight: 800;
        }

        tr.overstay-row {
            background: linear-gradient(90deg, #fff1f2 0%, #ffffff 48%);
        }

        tr.overstay-row td:first-child {
            border-left: 5px solid var(--primary);
        }

        .name-main {
            font-weight: 950;
            letter-spacing: -.02em;
        }

        .name-sub {
            margin-top: 3px;
            color: #64748b;
            font-size: .78rem;
            font-weight: 800;
        }

        .plate-badge {
            display: inline-flex;
            align-items: center;
            width: fit-content;
            border-radius: 9px;
            background: #0f172a;
            color: #ffffff;
            padding: 6px 10px;
            font-weight: 950;
            font-size: .78rem;
            letter-spacing: .06em;
            box-shadow: inset 0 0 0 1px rgba(255,255,255,.13);
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            width: fit-content;
            border-radius: 999px;
            padding: 8px 12px;
            font-weight: 950;
            font-size: .72rem;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .status-pill.overstay {
            background: #dc2626;
            color: #ffffff;
            box-shadow: 0 10px 20px rgba(220,38,38,.24);
        }

        .status-pill.blacklisted {
            background: #ffedd5;
            color: #9a3412;
        }

        .view-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 36px;
            border-radius: 999px;
            padding: 0 14px;
            text-decoration: none;
            background: #fff1f2;
            color: var(--primary);
            font-weight: 950;
            font-size: .78rem;
        }

        .empty {
            display: grid;
            place-items: center;
            min-height: 0;
            flex: 1 1 auto;
            color: #64748b;
            font-weight: 850;
            text-align: center;
        }

        .empty i {
            font-size: 2rem;
            color: #cbd5e1;
            margin-bottom: 10px;
        }


        /* Force the overstay list card to reach the bottom of the page. */
        .main-content {
            display: flex !important;
            flex-direction: column !important;
            overflow: hidden !important;
        }

        .topbar,
        .hero-card {
            flex: 0 0 auto !important;
        }

        .list-card {
            flex: 1 1 auto !important;
            min-height: 0 !important;
            margin-bottom: 0 !important;
            display: flex !important;
            flex-direction: column !important;
        }

        .table-wrap,
        .empty {
            flex: 1 1 auto !important;
            min-height: 0 !important;
            max-height: none !important;
        }

        @media (max-width: 1120px) {
            .dashboard-shell {
                grid-template-columns: 1fr;
            }

            .sidebar {
                display: none;
            }

            .hero-card {
                grid-template-columns: 1fr;
            }

            .hero-visual {
                display: none;
            }
        }

        @media (max-width: 760px) {
            .main-content {
                padding: 18px;
            }

            .topbar,
            .list-head {
                flex-direction: column;
                align-items: stretch;
            }

            .filters {
                grid-template-columns: 1fr;
            }

            .hero-card {
                padding: 22px;
            }

            .hero-mini {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-shell">
    <?php require_once __DIR__ . '/admin_sidebar.php'; ?>

    <main class="main-content">
        <div class="topbar">
            <div>
                <div class="eyebrow">Visitor Management</div>
                <h1>Overstay Visitors</h1>
                <p class="page-sub">Monitor visitors who may have stayed longer than their approved visit time.</p>
            </div>

            <div class="top-actions">
                <a href="admin_dashboard.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i>
                    Dashboard
                </a>
                <div class="profile-dot"><?= e($profileInitial) ?></div>
            </div>
        </div>

        <section class="hero-card">
            <div class="hero-copy">
                <div class="hero-kicker">Overstay Monitoring</div>
                <div class="hero-number"><?= (int)$overstayCount ?></div>
                <div class="hero-note">
                    Visitors currently overstaying<br>
                    in this apartment.
                </div>

                <div class="hero-stats">
                    <div class="hero-mini">
                        <strong class="red"><?= (int)$overstayCount ?></strong>
                        <span>Overstay Visitors</span>
                    </div>
                </div>
            </div>

            <div class="hero-visual" aria-hidden="true">
                <div class="hero-skyline"></div>
                <div class="hero-clock">
                    <div class="clock-dot"></div>
                </div>
                <div class="hero-alert">!</div>
                <div class="hero-car">
                    <div class="wheel-right"></div>
                </div>
                <div class="hero-sign">OVERSTAY</div>
            </div>
        </section>

        <section class="list-card">
            <div class="list-head">
                <div class="list-title">
                    <i class="fas fa-users"></i>
                    Overstay Visitors List
                </div>
                <div style="color:#64748b;font-weight:900;font-size:.78rem;">
                    <?= e($currentApartmentName) ?>
                </div>
            </div>

            <form class="filters" method="GET">
                <input
                    class="input"
                    type="text"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Search visitor, plate, resident, unit..."
                >

                <button class="filter-btn" type="submit" title="Search">
                    <i class="fas fa-magnifying-glass"></i>
                </button>

                <a class="reset-btn" href="admin_gate_overstay.php" title="Reset">
                    <i class="fas fa-rotate-left"></i>
                </a>
            </form>

            <?php if (!$hasBookings): ?>
                <div class="empty">
                    <div>
                        <i class="fas fa-database"></i>
                        <div>Bookings table not found.</div>
                    </div>
                </div>
            <?php elseif (!$overstayRows): ?>
                <div class="empty">
                    <div>
                        <i class="fas fa-car-side"></i>
                        <div>No overstaying visitors found.</div>
                        <div style="font-size:.8rem;margin-top:4px;color:#94a3b8;">All visitors are within their approved visit time.</div>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Visitor</th>
                            <th>Plate</th>
                            <th>Resident / Unit</th>
                            <th>Parking Slot</th>
                            <th>Expected Exit</th>
                            <th>Overstay</th>
                            <th>Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($overstayRows as $row): ?>
                            <?php
                            $plate = overstay_clean_plate($row['plate_no'] ?? '');
                            $visitorName = overstay_text_or_dash($row['visitor_name'] ?? 'Visitor');
                            $visitorSub = overstay_text_or_dash($row['visitor_email'] ?? $row['visitor_phone'] ?? $row['visitor_type'] ?? 'Visitor');
                            $residentText = overstay_text_or_dash($row['resident_name'] ?? '-');
                            $unitText = overstay_text_or_dash($row['unit_text'] ?? '-');
                            $slotText = trim((string)(($row['block_name'] ?? '-') . ' / ' . ($row['slot_no'] ?? '-')));
                            $parkingUrl = 'admin_parking_(V)manage.php?search=' . urlencode($plate) . '&plate=' . urlencode($plate) . '&from=overstay';
                            ?>
                            <tr class="overstay-row">
                                <td>
                                    <div class="name-main"><?= e($visitorName) ?></div>
                                    <div class="name-sub"><?= e($visitorSub) ?></div>
                                </td>
                                <td>
                                    <span class="plate-badge"><?= e($plate) ?></span>
                                </td>
                                <td>
                                    <div class="name-main"><?= e($residentText) ?></div>
                                    <div class="name-sub"><?= e($unitText) ?></div>
                                </td>
                                <td><?= e($slotText !== ' / ' ? $slotText : '-') ?></td>
                                <td><?= e(overstay_fmt_dt($row['end_time'] ?? null)) ?></td>
                                <td>
                                    <span class="status-pill overstay">
                                        <i class="fas fa-clock"></i>
                                        <?= e(overstay_text($row['end_time'] ?? null)) ?>
                                    </span>
                                </td>
                                <td>
                                    <a class="view-btn" href="<?= e($parkingUrl) ?>">
                                        <i class="fas fa-location-dot"></i>
                                        View Parking
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>

<script>
document.querySelectorAll('.side-parent .side-link.parent').forEach(function (button) {
    button.addEventListener('click', function () {
        const parent = button.closest('.side-parent');
        const isOpen = parent.classList.contains('open');

        document.querySelectorAll('.side-parent.open').forEach(function (item) {
            item.classList.remove('open');
        });

        if (!isOpen) {
            parent.classList.add('open');
        }
    });
});
</script>
</body>
</html>
