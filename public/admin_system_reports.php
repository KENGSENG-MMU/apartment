<?php
require_once '../core/security.php';
require_login(['admin', 'superadmin']);

$pdo = db();

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function valid_date($date): ?string {
    $date = trim((string)$date);
    if ($date === '') return null;
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return ($dt && $dt->format('Y-m-d') === $date) ? $date : null;
}

function current_apartment_id(PDO $pdo): ?int {
    if (!empty($_SESSION['apartment_id'])) return (int)$_SESSION['apartment_id'];

    $uid = (int)($_SESSION['uid'] ?? 0);
    if ($uid > 0 && table_exists($pdo, 'users') && column_exists($pdo, 'users', 'apartment_id')) {
        try {
            $stmt = $pdo->prepare("SELECT apartment_id FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$uid]);
            $id = $stmt->fetchColumn();
            if ($id) {
                $_SESSION['apartment_id'] = (int)$id;
                return (int)$id;
            }
        } catch (Throwable $e) {}
    }

    return null;
}

function current_apartment_name(PDO $pdo, ?int $apartmentId): string {
    if ($apartmentId && table_exists($pdo, 'apartments')) {
        try {
            $stmt = $pdo->prepare("SELECT apartment_name FROM apartments WHERE id = ? LIMIT 1");
            $stmt->execute([$apartmentId]);
            $name = $stmt->fetchColumn();
            if ($name) return (string)$name;
        } catch (Throwable $e) {}
    }

    return 'Ixoro Apartment';
}

function date_filter_sql(string $alias, string $column, ?string $from, ?string $to, array &$params): string {
    $sql = '';
    if ($from) {
        $sql .= " AND DATE({$alias}.{$column}) >= ? ";
        $params[] = $from;
    }
    if ($to) {
        $sql .= " AND DATE({$alias}.{$column}) <= ? ";
        $params[] = $to;
    }
    return $sql;
}

function report_options(): array {
    return [
        'visitor_bookings' => [
            'label' => 'Visitor Bookings',
            'desc' => 'Booking request report',
            'icon' => 'fa-calendar-check',
            'table' => 'bookings'
        ],
        'visitor_records' => [
            'label' => 'Visitor Records',
            'desc' => 'Completed visitor records',
            'icon' => 'fa-clipboard-list',
            'table' => 'bookings'
        ],
        'gate_logs' => [
            'label' => 'Gate Logs',
            'desc' => 'Gate entry and exit logs',
            'icon' => 'fa-shield-halved',
            'table' => 'gate_logs'
        ],
        'parking_payments' => [
            'label' => 'Parking Payments',
            'desc' => 'Resident parking payment history',
            'icon' => 'fa-money-bill-wave',
            'table' => 'parking_payments'
        ],
        'resident_parking' => [
            'label' => 'Resident Parking',
            'desc' => 'Resident parking slot assignments',
            'icon' => 'fa-square-parking',
            'table' => 'resident_parking_assignments'
        ],
        'blacklisted_plates' => [
            'label' => 'Blacklisted Plates',
            'desc' => 'Plate blacklist report',
            'icon' => 'fa-ban',
            'table' => 'blacklisted_plates'
        ],
        'resident_accounts' => [
            'label' => 'Resident Accounts',
            'desc' => 'Resident user account list',
            'icon' => 'fa-users',
            'table' => 'users'
        ],
        'visitor_accounts' => [
            'label' => 'Visitor Accounts',
            'desc' => 'Visitor user account list',
            'icon' => 'fa-user-tag',
            'table' => 'users'
        ],
    ];
}

function get_report_data(PDO $pdo, string $type, ?string $from, ?string $to, ?int $apartmentId): array {
    $params = [];
    $scope = '';
    $role = $_SESSION['role'] ?? 'admin';

    switch ($type) {
        case 'visitor_bookings':
            if (!table_exists($pdo, 'bookings')) throw new Exception('bookings table not found.');
            if ($role !== 'superadmin' && $apartmentId && column_exists($pdo, 'bookings', 'apartment_id')) {
                $scope = " AND b.apartment_id = ? ";
                $params[] = $apartmentId;
            }
            $dateCol = column_exists($pdo, 'bookings', 'created_at') ? 'created_at' : 'start_time';
            $dateSql = date_filter_sql('b', $dateCol, $from, $to, $params);

            $sql = "
                SELECT
                    b.id AS booking_id,
                    b.visitor_name,
                    b.visitor_email,
                    b.visitor_phone,
                    b.plate_no,
                    b.visitor_type,
                    b.visit_type,
                    b.purpose,
                    b.status,
                    b.start_time,
                    b.end_time,
                    resident.full_name AS resident_name,
                    ps.block_name AS parking_block,
                    ps.slot_no AS parking_slot,
                    b.created_at
                FROM bookings b
                LEFT JOIN users resident ON resident.id = b.resident_id
                LEFT JOIN parking_slots ps ON ps.id = b.slot_id
                WHERE 1=1 {$dateSql} {$scope}
                ORDER BY b.id DESC
            ";
            break;

        case 'visitor_records':
            if (!table_exists($pdo, 'bookings')) throw new Exception('bookings table not found.');
            if ($role !== 'superadmin' && $apartmentId && column_exists($pdo, 'bookings', 'apartment_id')) {
                $scope = " AND b.apartment_id = ? ";
                $params[] = $apartmentId;
            }
            $dateCol = column_exists($pdo, 'bookings', 'end_time') ? 'end_time' : 'created_at';
            $dateSql = date_filter_sql('b', $dateCol, $from, $to, $params);

            $sql = "
                SELECT
                    b.id AS record_id,
                    b.visitor_name,
                    b.visitor_email,
                    b.visitor_phone,
                    b.plate_no,
                    b.visitor_type,
                    b.purpose,
                    b.status,
                    b.start_time,
                    b.end_time,
                    resident.full_name AS resident_name,
                    ps.block_name AS parking_block,
                    ps.slot_no AS parking_slot
                FROM bookings b
                LEFT JOIN users resident ON resident.id = b.resident_id
                LEFT JOIN parking_slots ps ON ps.id = b.slot_id
                WHERE LOWER(COALESCE(b.status, '')) IN ('completed', 'checked_out') {$dateSql} {$scope}
                ORDER BY b.end_time DESC, b.id DESC
            ";
            break;

        case 'gate_logs':
            if (!table_exists($pdo, 'gate_logs')) throw new Exception('gate_logs table not found.');
            if ($role !== 'superadmin' && $apartmentId && column_exists($pdo, 'gate_logs', 'apartment_id')) {
                $scope = " AND gl.apartment_id = ? ";
                $params[] = $apartmentId;
            }
            $dateCol = column_exists($pdo, 'gate_logs', 'created_at') ? 'created_at' : 'action_time';
            $dateSql = date_filter_sql('gl', $dateCol, $from, $to, $params);

            $sql = "
                SELECT
                    gl.id AS log_id,
                    gl.plate_no,
                    gl.input_value,
                    gl.vehicle_type,
                    gl.gate_action,
                    gl.decision,
                    gl.reason,
                    guard.full_name AS guard_name,
                    b.visitor_name,
                    gl.created_at,
                    gl.action_time
                FROM gate_logs gl
                LEFT JOIN users guard ON guard.id = gl.guard_id
                LEFT JOIN bookings b ON b.id = gl.booking_id
                WHERE 1=1 {$dateSql} {$scope}
                ORDER BY gl.id DESC
            ";
            break;

        case 'parking_payments':
            if (!table_exists($pdo, 'parking_payments')) throw new Exception('parking_payments table not found.');
            $dateCol = column_exists($pdo, 'parking_payments', 'paid_at') ? 'paid_at' : 'created_at';
            $dateSql = date_filter_sql('pp', $dateCol, $from, $to, $params);

            $sql = "
                SELECT
                    pp.id AS payment_id,
                    resident.full_name AS resident_name,
                    resident.email AS resident_email,
                    rv.plate_no,
                    ps.block_name AS parking_block,
                    ps.slot_no AS parking_slot,
                    pp.billing_month,
                    pp.amount,
                    pp.payment_status,
                    pp.payment_method,
                    pp.paid_at,
                    pp.created_at
                FROM parking_payments pp
                LEFT JOIN resident_parking_assignments a ON a.id = pp.assignment_id
                LEFT JOIN users resident ON resident.id = COALESCE(pp.resident_id, a.resident_id)
                LEFT JOIN resident_vehicles rv ON rv.id = COALESCE(pp.vehicle_id, a.vehicle_id)
                LEFT JOIN parking_slots ps ON ps.id = a.slot_id
                WHERE 1=1 {$dateSql}
                ORDER BY pp.id DESC
            ";
            break;

        case 'resident_parking':
            if (!table_exists($pdo, 'resident_parking_assignments')) throw new Exception('resident_parking_assignments table not found.');
            $dateCol = column_exists($pdo, 'resident_parking_assignments', 'created_at') ? 'created_at' : 'start_date';
            $dateSql = date_filter_sql('a', $dateCol, $from, $to, $params);

            $sql = "
                SELECT
                    a.id AS assignment_id,
                    resident.full_name AS resident_name,
                    resident.email AS resident_email,
                    rv.plate_no,
                    rv.vehicle_model,
                    rv.vehicle_color,
                    ps.block_name AS parking_block,
                    ps.slot_no AS parking_slot,
                    a.subscription_status,
                    a.payment_status,
                    a.monthly_fee,
                    a.start_date,
                    a.end_date
                FROM resident_parking_assignments a
                LEFT JOIN users resident ON resident.id = a.resident_id
                LEFT JOIN resident_vehicles rv ON rv.id = a.vehicle_id
                LEFT JOIN parking_slots ps ON ps.id = a.slot_id
                WHERE 1=1 {$dateSql}
                ORDER BY a.id DESC
            ";
            break;

        case 'blacklisted_plates':
            if (!table_exists($pdo, 'blacklisted_plates')) throw new Exception('blacklisted_plates table not found.');
            $dateCol = column_exists($pdo, 'blacklisted_plates', 'created_at') ? 'created_at' : 'id';
            $dateSql = $dateCol === 'id' ? '' : date_filter_sql('bp', $dateCol, $from, $to, $params);

            $sql = "
                SELECT
                    bp.id AS blacklist_id,
                    bp.plate_no,
                    bp.reason,
                    bp.status,
                    bp.created_at
                FROM blacklisted_plates bp
                WHERE 1=1 {$dateSql}
                ORDER BY bp.id DESC
            ";
            break;

        case 'resident_accounts':
            if (!table_exists($pdo, 'users')) throw new Exception('users table not found.');
            $dateCol = column_exists($pdo, 'users', 'created_at') ? 'created_at' : 'id';
            $dateSql = $dateCol === 'id' ? '' : date_filter_sql('u', $dateCol, $from, $to, $params);

            $sql = "
                SELECT
                    u.id AS user_id,
                    u.full_name,
                    u.email,
                    u.contact_number,
                    u.role,
                    u.status,
                    u.created_at
                FROM users u
                WHERE u.role = 'resident' {$dateSql}
                ORDER BY u.id DESC
            ";
            break;

        case 'visitor_accounts':
            if (!table_exists($pdo, 'users')) throw new Exception('users table not found.');
            $dateCol = column_exists($pdo, 'users', 'created_at') ? 'created_at' : 'id';
            $dateSql = $dateCol === 'id' ? '' : date_filter_sql('u', $dateCol, $from, $to, $params);

            $sql = "
                SELECT
                    u.id AS user_id,
                    u.full_name,
                    u.email,
                    u.contact_number,
                    u.role,
                    u.status,
                    u.created_at
                FROM users u
                WHERE u.role = 'visitor' {$dateSql}
                ORDER BY u.id DESC
            ";
            break;

        default:
            throw new Exception('Invalid report type.');
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function export_csv(string $filename, array $rows, string $reportLabel, ?string $from, ?string $to): void {
    if (ob_get_length()) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, ['SmartVMS Report']);
    fputcsv($out, ['Report', $reportLabel]);
    fputcsv($out, ['Date From', $from ?: 'All']);
    fputcsv($out, ['Date To', $to ?: 'All']);
    fputcsv($out, ['Generated At', date('Y-m-d H:i:s')]);
    fputcsv($out, []);

    if (!$rows) {
        fputcsv($out, ['No data found']);
        fclose($out);
        exit;
    }

    fputcsv($out, array_keys($rows[0]));
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }

    fclose($out);
    exit;
}

$options = report_options();
$reportType = $_GET['report_type'] ?? 'visitor_bookings';
if (!isset($options[$reportType])) {
    $reportType = 'visitor_bookings';
}

$dateFrom = valid_date($_GET['date_from'] ?? '') ?: date('Y-m-01');
$dateTo = valid_date($_GET['date_to'] ?? '') ?: date('Y-m-t');
$apartmentId = current_apartment_id($pdo);
$apartmentName = current_apartment_name($pdo, $apartmentId);

$error = '';
$rows = [];
$previewRows = [];

if (isset($_GET['download']) && $_GET['download'] === '1') {
    try {
        $rows = get_report_data($pdo, $reportType, $dateFrom, $dateTo, $apartmentId);
        $filename = 'SmartVMS_' . $reportType . '_' . date('Ymd_His') . '.csv';
        export_csv($filename, $rows, $options[$reportType]['label'], $dateFrom, $dateTo);
    } catch (Throwable $e) {
        export_csv('SmartVMS_report_error_' . date('Ymd_His') . '.csv', [[
            'error' => $e->getMessage(),
            'report_type' => $reportType
        ]], 'Error', $dateFrom, $dateTo);
    }
}

try {
    $rows = get_report_data($pdo, $reportType, $dateFrom, $dateTo, $apartmentId);
    $previewRows = array_slice($rows, 0, 5);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$initial = strtoupper(substr(trim((string)($_SESSION['email'] ?? 'A')), 0, 1)) ?: 'A';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reports Export | SmartVMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #dc2626;
            --primary-dark: #b91c1c;
            --soft: #fff1f2;
            --line: #e5e7eb;
            --text: #0f172a;
            --muted: #64748b;
            --shadow: 0 20px 45px rgba(15, 23, 42, .08);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top right, rgba(220,38,38,.12), transparent 32%),
                linear-gradient(180deg, #ffffff 0%, #f4f6fb 45%, #eef2f7 100%);
        }

        .dashboard-shell {
            display: grid;
            grid-template-columns: 260px minmax(0, 1fr);
            min-height: 100vh;
        }

        .sidebar {
            background: rgba(255,255,255,.94);
            backdrop-filter: blur(20px);
            border-right: 1px solid rgba(229, 231, 235, .9);
            padding: 20px 18px;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow: hidden;
            z-index: 20;
        }

        .main-content {
            padding: 30px 32px 34px;
            min-width: 0;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 18px;
            margin-bottom: 22px;
        }

        .eyebrow {
            color: var(--primary);
            text-transform: uppercase;
            font-size: .74rem;
            font-weight: 950;
            letter-spacing: .12em;
            margin-bottom: 5px;
        }

        h1 {
            margin: 0;
            font-size: 2rem;
            line-height: 1.05;
            letter-spacing: -.07em;
            font-weight: 950;
        }

        .page-sub {
            margin: 8px 0 0;
            color: #475569;
            font-size: .9rem;
            font-weight: 780;
            line-height: 1.5;
        }

        .top-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .top-btn,
        .profile-dot {
            height: 44px;
            border-radius: 999px;
            border: 0;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 950;
            color: white;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            box-shadow: 0 14px 28px rgba(220,38,38,.22);
        }

        .top-btn {
            padding: 0 18px;
            gap: 8px;
        }

        .profile-dot {
            width: 44px;
        }

        .export-card {
            border: 1px solid var(--line);
            border-radius: 26px;
            background:
                radial-gradient(circle at 5% 0%, rgba(248,113,113,.20), transparent 22%),
                linear-gradient(120deg, #ffffff 0%, #ffffff 46%, #fff1f2 100%);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 20px;
        }

        .export-head {
            padding: 24px 28px;
            border-bottom: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
        }

        .export-title {
            font-size: 1.35rem;
            font-weight: 950;
            letter-spacing: -.05em;
        }

        .export-desc {
            color: var(--muted);
            font-weight: 800;
            margin-top: 5px;
        }

        .csv-icon {
            width: 82px;
            height: 82px;
            border-radius: 24px;
            display: grid;
            place-items: center;
            color: white;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            box-shadow: 0 18px 35px rgba(220,38,38,.22);
            font-size: 1.7rem;
            flex-shrink: 0;
        }

        .form-area {
            padding: 22px 28px 28px;
        }

        .export-form {
            display: grid;
            grid-template-columns: minmax(260px, 1.3fr) minmax(170px, .65fr) minmax(170px, .65fr) auto;
            gap: 12px;
            align-items: end;
        }

        label {
            display: block;
            color: #64748b;
            text-transform: uppercase;
            font-size: .68rem;
            font-weight: 950;
            letter-spacing: .08em;
            margin-bottom: 8px;
        }

        .input,
        .select {
            width: 100%;
            height: 48px;
            border-radius: 15px;
            border: 1px solid var(--line);
            background: white;
            padding: 0 14px;
            font-family: inherit;
            font-weight: 850;
            outline: none;
        }

        .input:focus,
        .select:focus {
            border-color: #fca5a5;
            box-shadow: 0 0 0 4px #fee2e2;
        }

        .download-btn {
            height: 48px;
            border: 0;
            border-radius: 15px;
            padding: 0 22px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            font-family: inherit;
            font-weight: 950;
            color: white;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            box-shadow: 0 16px 30px rgba(220,38,38,.22);
            cursor: pointer;
            white-space: nowrap;
        }

        .report-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }

        .report-option {
            border: 1px solid var(--line);
            border-radius: 20px;
            background: white;
            box-shadow: 0 12px 28px rgba(15,23,42,.05);
            padding: 16px;
            text-decoration: none;
            color: inherit;
            min-height: 112px;
            transition: .18s ease;
        }

        .report-option:hover,
        .report-option.active {
            border-color: #fca5a5;
            background: #fff7f7;
            transform: translateY(-1px);
            box-shadow: 0 18px 34px rgba(220,38,38,.10);
        }

        .report-icon {
            width: 38px;
            height: 38px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            color: var(--primary);
            background: #fee2e2;
            margin-bottom: 12px;
        }

        .report-name {
            font-weight: 950;
            letter-spacing: -.03em;
            font-size: .94rem;
        }

        .report-desc {
            margin-top: 5px;
            color: var(--muted);
            font-size: .75rem;
            font-weight: 750;
            line-height: 1.35;
        }

        .preview-card {
            border: 1px solid var(--line);
            border-radius: 24px;
            background: white;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .preview-head {
            padding: 16px 18px;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .preview-title {
            font-size: 1rem;
            font-weight: 950;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .preview-title i {
            color: var(--primary);
        }

        .preview-count {
            border-radius: 999px;
            padding: 7px 12px;
            background: #fff1f2;
            color: #991b1b;
            font-size: .72rem;
            font-weight: 950;
            text-transform: uppercase;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        th {
            text-align: left;
            padding: 12px 14px;
            background: #f8fafc;
            color: #64748b;
            text-transform: uppercase;
            font-size: .67rem;
            letter-spacing: .06em;
            font-weight: 950;
            border-bottom: 1px solid var(--line);
            white-space: nowrap;
        }

        td {
            padding: 13px 14px;
            border-bottom: 1px solid #edf2f7;
            font-size: .8rem;
            font-weight: 800;
            max-width: 230px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .empty,
        .error-box {
            padding: 42px 20px;
            text-align: center;
            color: #64748b;
            font-weight: 850;
        }

        .error-box {
            color: #991b1b;
            background: #fff1f2;
            border: 1px solid #fecaca;
            border-radius: 18px;
            padding: 14px 16px;
            margin-bottom: 18px;
            text-align: left;
        }

        .empty i {
            display: block;
            font-size: 2rem;
            color: #cbd5e1;
            margin-bottom: 10px;
        }

        @media (max-width: 1180px) {
            .dashboard-shell { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .report-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .export-form { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 760px) {
            .main-content { padding: 20px; }
            .topbar, .export-head { flex-direction: column; align-items: flex-start; }
            .report-grid, .export-form { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="dashboard-shell">
    <?php if (file_exists(__DIR__ . '/admin_sidebar.php')): ?>
        <?php require_once __DIR__ . '/admin_sidebar.php'; ?>
    <?php else: ?>
        <aside class="sidebar"></aside>
    <?php endif; ?>

    <main class="main-content">
        <div class="topbar">
            <div>
                <div class="eyebrow">System</div>
                <h1>Reports Export</h1>
                <p class="page-sub">Choose the report file you want and download it as a CSV file.</p>
            </div>

            <div class="top-actions">
                <a href="admin_dashboard.php" class="top-btn">
                    <i class="fas fa-arrow-left"></i>
                    Dashboard
                </a>
                <div class="profile-dot"><?= e($initial) ?></div>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="error-box">
                <i class="fas fa-triangle-exclamation"></i>
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <section class="export-card">
            <div class="export-head">
                <div>
                    <div class="eyebrow">CSV Download Centre</div>
                    <div class="export-title">Select a report and download</div>
                    <div class="export-desc">No charts, no complicated summary. Just choose a file and export.</div>
                </div>
                <div class="csv-icon">
                    <i class="fas fa-file-csv"></i>
                </div>
            </div>

            <div class="form-area">
                <form method="GET" class="export-form">
                    <div>
                        <label for="report_type">Report File</label>
                        <select name="report_type" id="report_type" class="select">
                            <?php foreach ($options as $key => $option): ?>
                                <option value="<?= e($key) ?>" <?= $reportType === $key ? 'selected' : '' ?>>
                                    <?= e($option['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="date_from">Date From</label>
                        <input type="date" name="date_from" id="date_from" class="input" value="<?= e($dateFrom) ?>">
                    </div>

                    <div>
                        <label for="date_to">Date To</label>
                        <input type="date" name="date_to" id="date_to" class="input" value="<?= e($dateTo) ?>">
                    </div>

                    <button type="submit" name="download" value="1" class="download-btn">
                        <i class="fas fa-download"></i>
                        Download CSV
                    </button>
                </form>
            </div>
        </section>

        <section class="report-grid">
            <?php foreach ($options as $key => $option): ?>
                <a
                    class="report-option <?= $reportType === $key ? 'active' : '' ?>"
                    href="?report_type=<?= e($key) ?>&date_from=<?= e($dateFrom) ?>&date_to=<?= e($dateTo) ?>"
                >
                    <div class="report-icon">
                        <i class="fas <?= e($option['icon']) ?>"></i>
                    </div>
                    <div class="report-name"><?= e($option['label']) ?></div>
                    <div class="report-desc"><?= e($option['desc']) ?></div>
                </a>
            <?php endforeach; ?>
        </section>

        <section class="preview-card">
            <div class="preview-head">
                <div class="preview-title">
                    <i class="fas <?= e($options[$reportType]['icon']) ?>"></i>
                    <?= e($options[$reportType]['label']) ?> Preview
                </div>
                <div class="preview-count"><?= count($rows) ?> records found</div>
            </div>

            <?php if (!$previewRows): ?>
                <div class="empty">
                    <i class="fas fa-file-csv"></i>
                    No record found for this date range.
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <?php foreach (array_keys($previewRows[0]) as $heading): ?>
                                <th><?= e(str_replace('_', ' ', $heading)) ?></th>
                            <?php endforeach; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($previewRows as $row): ?>
                            <tr>
                                <?php foreach ($row as $cell): ?>
                                    <td title="<?= e($cell) ?>"><?= e($cell) ?></td>
                                <?php endforeach; ?>
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
const reportSelect = document.getElementById('report_type');
if (reportSelect) {
    reportSelect.addEventListener('change', function () {
        const params = new URLSearchParams(window.location.search);
        params.set('report_type', this.value);

        const dateFrom = document.getElementById('date_from');
        const dateTo = document.getElementById('date_to');

        if (dateFrom && dateFrom.value) params.set('date_from', dateFrom.value);
        if (dateTo && dateTo.value) params.set('date_to', dateTo.value);

        window.location.href = '?' + params.toString();
    });
}

document.querySelectorAll('.side-parent .side-link.parent').forEach(function (button) {
    button.addEventListener('click', function () {
        const parent = button.closest('.side-parent');
        const isOpen = parent.classList.contains('open');

        document.querySelectorAll('.side-parent.open').forEach(function (item) {
            item.classList.remove('open');
        });

        if (!isOpen) parent.classList.add('open');
    });
});
</script>
</body>
</html>
