<?php
require_once '../core/security.php';
require_login(['guard', 'admin', 'superadmin']);

$pdo = db();

if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function table_exists_simple(PDO $pdo, string $table): bool {
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

function col_exists_simple(PDO $pdo, string $table, string $column): bool {
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

function rows_simple(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function count_simple(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function clean_plate_simple($plate): string {
    $plate = strtoupper(trim((string)($plate ?? '')));
    $plate = preg_replace('/[^A-Z0-9]/', '', $plate);
    return $plate ?: '-';
}

function show_text_simple($value, string $fallback = '-'): string {
    $value = trim((string)($value ?? ''));
    return $value !== '' ? $value : $fallback;
}

function show_time_simple($value): string {
    if (!$value) return '-';
    $time = strtotime((string)$value);
    return $time ? date('h:i A', $time) : '-';
}

function show_date_simple($value): string {
    if (!$value) return '-';
    $time = strtotime((string)$value);
    return $time ? date('d M Y', $time) : '-';
}

function show_over_duration_simple($from): string {
    if (!$from) {
        return 'Over';
    }

    $fromTime = strtotime((string)$from);
    if (!$fromTime) {
        return 'Over';
    }

    $seconds = max(0, time() - $fromTime);
    $days = intdiv($seconds, 86400);
    $seconds %= 86400;
    $hours = intdiv($seconds, 3600);
    $seconds %= 3600;
    $minutes = max(1, intdiv($seconds, 60));

    if ($days > 0) {
        return 'Over ' . $days . 'd ' . $hours . 'h';
    }

    if ($hours > 0) {
        return 'Over ' . $hours . 'h ' . $minutes . 'm';
    }

    return 'Over ' . $minutes . 'm';
}

function find_parking_manage_page(): string {
    $candidates = [
        'admin_parking_(V)manage.php',
        'admin_parking_vmanage.php',
        'admin_visitor_slots.php',
        'admin_parking_manage.php',
        'admin_parking_vehicles_combined.php',
        'admin_resident_slots.php'
    ];

    foreach ($candidates as $file) {
        if (is_file(__DIR__ . '/' . $file)) {
            return $file;
        }
    }

    return 'admin_parking_vmanage.php';
}

$parkingManagePage = find_parking_manage_page();

$currentEmail = $_SESSION['email'] ?? 'guard@apt.com';
$currentInitial = strtoupper(substr($currentEmail ?: 'G', 0, 1));

$search = trim((string)($_GET['search'] ?? ''));
$actionFilter = strtoupper(trim((string)($_GET['action'] ?? '')));
$dateFilter = trim((string)($_GET['date'] ?? ''));

if ($actionFilter !== '' && !in_array($actionFilter, ['ENTRY', 'EXIT'], true)) {
    $actionFilter = '';
}

if ($dateFilter !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
    $dateFilter = '';
}

$hasGateLogs = table_exists_simple($pdo, 'gate_logs');
$timeExpr = col_exists_simple($pdo, 'gate_logs', 'action_time') ? 'gl.action_time' : 'gl.created_at';

$where = [];
$params = [];

if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = "(
        gl.plate_no LIKE ?
        OR gl.input_value LIKE ?
        OR b.visitor_name LIKE ?
        OR b.visitor_email LIKE ?
        OR b.visitor_phone LIKE ?
        OR ru.full_name LIKE ?
        OR vu.full_name LIKE ?
    )";
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
}

if ($actionFilter !== '') {
    $where[] = "gl.gate_action = ?";
    $params[] = $actionFilter;
}

if ($dateFilter !== '') {
    $where[] = "DATE($timeExpr) = ?";
    $params[] = $dateFilter;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$logs = [];
if ($hasGateLogs) {
    $logs = rows_simple($pdo, "
        SELECT
            gl.id,
            gl.booking_id,
            gl.plate_no,
            gl.input_value,
            gl.vehicle_type,
            gl.gate_action,
            gl.decision,
            gl.reason,
            $timeExpr AS log_time,

            b.visitor_name,
            b.visitor_type,
            b.visitor_email,
            b.visitor_phone,
            b.status AS booking_status,
            b.updated_at AS booking_updated_at,
            b.actual_exit_at,
            b.slot_id,
            ps.slot_no,
            ps.status AS slot_status,

            ru.full_name AS resident_name,
            vu.full_name AS vehicle_owner_name,
            rv.vehicle_model,
            rv.vehicle_color
        FROM gate_logs gl
        LEFT JOIN bookings b
            ON b.id = gl.booking_id
        LEFT JOIN parking_slots ps
            ON ps.id = b.slot_id
        LEFT JOIN users ru
            ON ru.id = b.resident_id
        LEFT JOIN resident_vehicles rv
            ON UPPER(REPLACE(rv.plate_no, ' ', '')) = UPPER(REPLACE(gl.plate_no, ' ', ''))
        LEFT JOIN users vu
            ON vu.id = rv.resident_id
        $whereSql
        ORDER BY $timeExpr DESC, gl.id DESC
        LIMIT 120
    ", $params);
}

$totalLogs = $hasGateLogs ? count_simple($pdo, "SELECT COUNT(*) FROM gate_logs") : 0;
$entryCount = $hasGateLogs ? count_simple($pdo, "SELECT COUNT(*) FROM gate_logs WHERE gate_action = 'ENTRY'") : 0;
$exitCount = $hasGateLogs ? count_simple($pdo, "SELECT COUNT(*) FROM gate_logs WHERE gate_action = 'EXIT'") : 0;
$denyCount = $hasGateLogs ? count_simple($pdo, "SELECT COUNT(*) FROM gate_logs WHERE decision = 'DENY'") : 0;

$overstayCount = 0;

$displayLogs = [];

usort($logs, function ($a, $b) {
    $timeA = strtotime((string)($a['log_time'] ?? '')) ?: 0;
    $timeB = strtotime((string)($b['log_time'] ?? '')) ?: 0;

    if ($timeA === $timeB) {
        return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
    }

    return $timeA <=> $timeB;
});

$openSessions = [];

foreach ($logs as $row) {
    $plate = clean_plate_simple($row['plate_no'] ?: $row['input_value']);
    $action = strtoupper(show_text_simple($row['gate_action'], 'ENTRY'));
    $decision = strtoupper(show_text_simple($row['decision'], 'ALLOW'));
    $bookingId = (int)($row['booking_id'] ?? 0);
    $sessionKey = $bookingId > 0 ? 'B' . $bookingId : 'P' . $plate;
    $logTimeRaw = (string)($row['log_time'] ?? '');

    $name = show_text_simple($row['visitor_name'] ?? '');
    if ($name === '-') {
        $name = show_text_simple($row['vehicle_owner_name'] ?? '');
    }
    if ($name === '-') {
        $name = ucfirst(strtolower(show_text_simple($row['vehicle_type'] ?? $row['visitor_type'] ?? 'Visitor', 'Visitor')));
    }

    $type = strtolower(show_text_simple($row['vehicle_type'] ?? $row['visitor_type'] ?? 'visitor', 'visitor'));
    $type = ucfirst($type);

    $bookingStatus = strtolower(show_text_simple($row['booking_status'] ?? '', ''));
    $slotStatus = strtolower(show_text_simple($row['slot_status'] ?? '', ''));
    $bookingExitRaw = (string)($row['actual_exit_at'] ?? '');
    if ($bookingExitRaw === '' && in_array($bookingStatus, ['completed', 'checked_out', 'closed'], true)) {
        $bookingExitRaw = (string)($row['booking_updated_at'] ?? '');
    }

    $bookingAlreadyClosed = in_array($bookingStatus, ['completed', 'checked_out', 'closed'], true)
        || ($bookingExitRaw !== '')
        || ($slotStatus === 'available' && !empty($row['slot_id']));

    $baseSession = [
        'key' => $sessionKey,
        'plate' => $plate,
        'name' => $name,
        'type' => $type,
        'entryRaw' => null,
        'exitRaw' => null,
        'entryTime' => '-',
        'entryDate' => '-',
        'exitTime' => '-',
        'exitDate' => '-',
        'decision' => $decision,
        'status' => 'Inside',
        'statusClass' => 'inside',
    ];

    if ($decision === 'DENY') {
        $baseSession['status'] = 'Denied';
        $baseSession['statusClass'] = 'deny';

        if ($action === 'EXIT') {
            $baseSession['exitRaw'] = $logTimeRaw;
            $baseSession['exitTime'] = show_time_simple($logTimeRaw);
            $baseSession['exitDate'] = show_date_simple($logTimeRaw);
        } else {
            $baseSession['entryRaw'] = $logTimeRaw;
            $baseSession['entryTime'] = show_time_simple($logTimeRaw);
            $baseSession['entryDate'] = show_date_simple($logTimeRaw);
        }

        $displayLogs[] = $baseSession;
        continue;
    }

    if ($action === 'EXIT') {
        if (isset($openSessions[$sessionKey])) {
            $index = $openSessions[$sessionKey];
            $displayLogs[$index]['exitRaw'] = $logTimeRaw;
            $displayLogs[$index]['exitTime'] = show_time_simple($logTimeRaw);
            $displayLogs[$index]['exitDate'] = show_date_simple($logTimeRaw);
            $displayLogs[$index]['status'] = 'Completed';
            $displayLogs[$index]['statusClass'] = 'completed';
            unset($openSessions[$sessionKey]);
        } else {
            $baseSession['exitRaw'] = $logTimeRaw;
            $baseSession['exitTime'] = show_time_simple($logTimeRaw);
            $baseSession['exitDate'] = show_date_simple($logTimeRaw);
            $baseSession['status'] = 'Exit Only';
            $baseSession['statusClass'] = 'outonly';
            $displayLogs[] = $baseSession;
        }

        continue;
    }

    $baseSession['entryRaw'] = $logTimeRaw;
    $baseSession['entryTime'] = show_time_simple($logTimeRaw);
    $baseSession['entryDate'] = show_date_simple($logTimeRaw);

    if ($bookingAlreadyClosed) {
        if ($bookingExitRaw !== '') {
            $baseSession['exitRaw'] = $bookingExitRaw;
            $baseSession['exitTime'] = show_time_simple($bookingExitRaw);
            $baseSession['exitDate'] = show_date_simple($bookingExitRaw);
        }

        $baseSession['status'] = 'Completed';
        $baseSession['statusClass'] = 'completed';
        $displayLogs[] = $baseSession;
    } else {
        $baseSession['status'] = show_over_duration_simple($logTimeRaw);
        $baseSession['statusClass'] = 'over';
        $displayLogs[] = $baseSession;
        $openSessions[$sessionKey] = count($displayLogs) - 1;
    }
}

usort($displayLogs, function ($a, $b) {
    $timeA = strtotime((string)($a['exitRaw'] ?: $a['entryRaw'] ?: '')) ?: 0;
    $timeB = strtotime((string)($b['exitRaw'] ?: $b['entryRaw'] ?: '')) ?: 0;

    return $timeB <=> $timeA;
});

$overstayCount = count(array_filter($displayLogs, function ($log) {
    return ($log['statusClass'] ?? '') === 'over';
}));

foreach ($displayLogs as $index => $log) {
    $plateForUrl = (string)($log['plate'] ?? '');
    $displayLogs[$index]['parkingUrl'] = $parkingManagePage . '?search=' . urlencode($plateForUrl) . '&plate=' . urlencode($plateForUrl) . '&from=gate_logs';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Gate Logs - SmartVMS</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        :root {
            --primary: #dc2626;
            --primary-dark: #b91c1c;
            --primary-soft: #fee2e2;
            --green: #16a34a;
            --green-soft: #dcfce7;
            --blue: #2563eb;
            --blue-soft: #dbeafe;
            --orange: #f97316;
            --orange-soft: #ffedd5;
            --text: #0f172a;
            --muted: #64748b;
            --border: #e5e7eb;
            --card: #ffffff;
            --shadow: 0 18px 45px rgba(15, 23, 42, .08);
            --shadow-soft: 0 10px 25px rgba(15, 23, 42, .06);
        }

        * {
            box-sizing: border-box;
            font-family: 'Plus Jakarta Sans', sans-serif;
            margin: 0;
            padding: 0;
        }

        html,
        body {
            height: 100%;
            overflow: hidden;
        }

        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at 86% 0%, rgba(220,38,38,.12), transparent 28%),
                linear-gradient(135deg, #fff7f7 0%, #f4f6fb 45%, #eef2f7 100%);
            color: var(--text);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .dashboard-shell {
            display: grid;
            grid-template-columns: 260px 1fr;
            height: 100vh;
            min-height: 100vh;
            overflow: hidden;
        }

        .page {
            min-width: 0;
            height: 100vh;
            overflow: hidden;
            padding: 24px 28px 30px;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 14px;
            flex: 0 0 auto;
        }

        .top-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .top-btn {
            min-height: 44px;
            border-radius: 16px;
            padding: 0 16px;
            border: 1px solid transparent;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: .8rem;
            font-weight: 950;
            box-shadow: 0 14px 28px rgba(220,38,38,.22);
        }

        .avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            font-size: .86rem;
            font-weight: 950;
            box-shadow: 0 12px 24px rgba(220,38,38,.20);
        }

        .page-title-wrap {
            margin-bottom: 0;
        }

        .kicker {
            color: var(--primary);
            font-size: .72rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .12em;
            margin-bottom: 6px;
        }

        .title {
            font-size: 1.75rem;
            line-height: 1.05;
            font-weight: 950;
            letter-spacing: -.06em;
        }

        .subtitle {
            margin-top: 6px;
            color: var(--muted);
            font-size: .84rem;
            font-weight: 750;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 14px;
            flex: 0 0 auto;
        }

        .summary-card {
            min-height: 76px;
            border-radius: 22px;
            background: rgba(255,255,255,.96);
            border: 1px solid rgba(229,231,235,.95);
            box-shadow: var(--shadow-soft);
            padding: 15px 17px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .summary-card strong {
            display: block;
            font-size: 1.38rem;
            line-height: 1;
            font-weight: 950;
            letter-spacing: -.05em;
        }

        .summary-card span {
            display: block;
            margin-top: 7px;
            color: var(--muted);
            font-size: .66rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .summary-icon {
            width: 40px;
            height: 40px;
            border-radius: 15px;
            display: grid;
            place-items: center;
            background: var(--primary-soft);
            color: var(--primary);
            flex: 0 0 auto;
        }

        .summary-icon.green {
            background: var(--green-soft);
            color: var(--green);
        }

        .summary-icon.blue {
            background: var(--blue-soft);
            color: var(--blue);
        }

        .summary-icon.orange {
            background: var(--orange-soft);
            color: var(--orange);
        }

        .panel {
            background: rgba(255,255,255,.96);
            border: 1px solid rgba(229,231,235,.95);
            border-radius: 24px;
            box-shadow: var(--shadow);
            overflow: hidden;
            flex: 1 1 auto;
            min-height: 0;
            display: flex;
            flex-direction: column;
        }

        .panel-head {
            padding: 15px 18px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .panel-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 950;
            font-size: .98rem;
            letter-spacing: -.03em;
        }

        .panel-title i {
            color: var(--primary);
        }

        .filters {
            padding: 14px 18px;
            border-bottom: 1px solid var(--border);
            display: grid;
            grid-template-columns: minmax(260px, 1fr) 150px 160px 44px 44px;
            gap: 10px;
            flex: 0 0 auto;
        }

        .filters input,
        .filters select {
            width: 100%;
            height: 42px;
            border-radius: 14px;
            border: 1px solid var(--border);
            background: #fff;
            padding: 0 12px;
            font-size: .8rem;
            font-weight: 850;
            outline: none;
        }

        .filters input:focus,
        .filters select:focus {
            border-color: #fecaca;
            box-shadow: 0 0 0 4px rgba(220,38,38,.08);
        }

        .icon-btn {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            border: 1px solid var(--border);
            display: grid;
            place-items: center;
            cursor: pointer;
            background: #fff;
            color: #64748b;
            font-size: .9rem;
        }

        .icon-btn.red {
            border-color: transparent;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            box-shadow: 0 12px 24px rgba(220,38,38,.18);
        }

        .log-table-head,
        .log-row {
            display: grid;
            grid-template-columns: 1.15fr 1fr 145px 145px 130px;
            align-items: center;
            gap: 14px;
        }

        .log-table-head {
            padding: 11px 18px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
            color: var(--muted);
            font-size: .66rem;
            font-weight: 950;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .log-list {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
        }

        .log-list::-webkit-scrollbar {
            width: 8px;
        }

        .log-list::-webkit-scrollbar-thumb {
            background: #fecaca;
            border-radius: 999px;
        }

        .log-row {
            padding: 14px 18px;
            border-bottom: 1px solid #eef2f7;
            transition: .16s ease;
        }

        .log-row:hover {
            background: #fff7f7;
        }

        .log-row.clickable-row {
            cursor: pointer;
            text-decoration: none;
            color: inherit;
        }

        .log-row.clickable-row:hover .person-main {
            color: var(--primary);
        }

        .log-row.over-row {
            background: linear-gradient(90deg, #fee2e2 0%, #fff1f2 52%, #ffffff 100%);
            border-left: 6px solid #dc2626;
            box-shadow: inset 0 0 0 1px rgba(220, 38, 38, .10);
        }

        .log-row.over-row:hover {
            background: linear-gradient(90deg, #fecaca 0%, #fff1f2 58%, #ffffff 100%);
        }

        .log-row.over-row .person-main,
        .log-row.over-row .time-main {
            color: #991b1b;
        }

        .log-row.over-row .plate-badge {
            box-shadow: 0 0 0 3px rgba(220, 38, 38, .14);
        }

        .time-main {
            font-weight: 950;
            font-size: .9rem;
        }

        .time-sub {
            margin-top: 3px;
            color: var(--muted);
            font-size: .72rem;
            font-weight: 800;
        }

        .person-main {
            font-weight: 950;
            font-size: .88rem;
            letter-spacing: -.02em;
        }

        .person-sub {
            margin-top: 3px;
            color: var(--muted);
            font-size: .72rem;
            font-weight: 800;
        }

        .plate-badge {
            display: inline-flex;
            width: fit-content;
            background: #111827;
            color: #fff;
            border: 2px solid #334155;
            padding: 6px 10px;
            border-radius: 10px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-weight: 950;
            letter-spacing: .08em;
            font-size: .78rem;
        }

        .action-pill,
        .decision-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: fit-content;
            min-width: 76px;
            border-radius: 999px;
            padding: 7px 10px;
            font-size: .67rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .action-pill.entry {
            background: #dcfce7;
            color: #166534;
        }

        .action-pill.exit {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .decision-pill.allow {
            background: #dcfce7;
            color: #166534;
        }

        .decision-pill.deny {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: fit-content;
            min-width: 112px;
            border-radius: 999px;
            padding: 8px 12px;
            font-size: .67rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .status-pill.completed {
            background: #dcfce7;
            color: #166534;
        }

        .status-pill.inside,
        .status-pill.over {
            background: #dc2626;
            color: #ffffff;
            border: 1px solid #b91c1c;
            box-shadow: 0 10px 22px rgba(220, 38, 38, .28);
            font-weight: 950;
        }

        .status-pill.outonly {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-pill.deny {
            background: #fee2e2;
            color: #991b1b;
        }

        .empty {
            flex: 1 1 auto;
            min-height: 360px;
            display: grid;
            place-items: center;
            text-align: center;
            color: var(--muted);
            font-weight: 850;
            padding: 40px 20px;
        }

        .empty i {
            display: block;
            font-size: 2.4rem;
            color: #cbd5e1;
            margin-bottom: 10px;
        }

        @media (max-width: 980px) {
            html,
            body {
                height: auto;
                overflow: auto;
            }

            .dashboard-shell {
                grid-template-columns: 1fr;
                height: auto;
                overflow: visible;
            }

            .page {
                height: auto;
                overflow: visible;
            }

            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .filters {
                grid-template-columns: 1fr 1fr;
            }

            .filters .search-input {
                grid-column: 1 / -1;
            }

            .log-table-head {
                display: none;
            }

            .log-list {
                max-height: none;
            }

            .log-row {
                grid-template-columns: 1fr;
                gap: 9px;
            }
        }

        @media (max-width: 600px) {
            .topbar,
            .panel-head {
                flex-direction: column;
                align-items: flex-start;
            }

            .top-actions,
            .top-btn {
                width: 100%;
            }

            .summary-grid,
            .filters {
                grid-template-columns: 1fr;
            }

            .icon-btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-shell">
        <?php require_once __DIR__ . '/admin_sidebar.php'; ?>

        <main class="page">
            <header class="topbar">
                <section class="page-title-wrap">
                    <div class="kicker">Gate Management</div>
                    <h1 class="title">Gate In / Out Records</h1>
                    <p class="subtitle">Shows actual entry and exit time. Completed bookings will not be treated as overstay. Auto refreshes every 10 seconds.</p>
                </section>

                <div class="top-actions">
                    <a href="admin_dashboard.php" class="top-btn">
                        <i class="fas fa-arrow-left"></i>
                        Dashboard
                    </a>
                    <div class="avatar"><?= e($currentInitial) ?></div>
                </div>
            </header>

        <section class="summary-grid">
            <div class="summary-card">
                <div>
                    <strong><?= (int)$totalLogs ?></strong>
                    <span>Total Records</span>
                </div>
                <div class="summary-icon">
                    <i class="fas fa-list-check"></i>
                </div>
            </div>

            <div class="summary-card">
                <div>
                    <strong><?= (int)$entryCount ?></strong>
                    <span>Entry</span>
                </div>
                <div class="summary-icon green">
                    <i class="fas fa-right-to-bracket"></i>
                </div>
            </div>

            <div class="summary-card">
                <div>
                    <strong><?= (int)$exitCount ?></strong>
                    <span>Exit</span>
                </div>
                <div class="summary-icon blue">
                    <i class="fas fa-right-from-bracket"></i>
                </div>
            </div>

            <div class="summary-card">
                <div>
                    <strong><?= (int)$overstayCount ?></strong>
                    <span>Overstay</span>
                </div>
                <div class="summary-icon orange">
                    <i class="fas fa-user-clock"></i>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div class="panel-title">
                    <i class="fas fa-clock-rotate-left"></i>
                    In / Out Log
                </div>
            </div>

            <form method="GET" class="filters" autocomplete="off">
                <input
                    class="search-input"
                    type="text"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Search name or plate..."
                >

                <select name="action">
                    <option value="">All Action</option>
                    <option value="ENTRY" <?= $actionFilter === 'ENTRY' ? 'selected' : '' ?>>Entry</option>
                    <option value="EXIT" <?= $actionFilter === 'EXIT' ? 'selected' : '' ?>>Exit</option>
                </select>

                <input type="date" name="date" value="<?= e($dateFilter) ?>">

                <button type="submit" class="icon-btn red" title="Search">
                    <i class="fas fa-search"></i>
                </button>

                <a href="guard_logs.php" class="icon-btn" title="Reset">
                    <i class="fas fa-rotate-left"></i>
                </a>
            </form>

            <div class="log-table-head">
                <div>Person</div>
                <div>Plate</div>
                <div>Entry Time</div>
                <div>Exit Time</div>
                <div>Status</div>
            </div>

            <?php if (!$hasGateLogs): ?>
                <div class="empty">
                    <div>
                        <i class="fas fa-circle-exclamation"></i>
                        gate_logs table was not found.
                    </div>
                </div>
            <?php elseif (empty($displayLogs)): ?>
                <div class="empty">
                    <div>
                        <i class="fas fa-clock-rotate-left"></i>
                        No gate record found.
                    </div>
                </div>
            <?php else: ?>
                <div class="log-list">
                    <?php foreach ($displayLogs as $log): ?>
                        <a
                            href="<?= e($log['parkingUrl']) ?>"
                            class="log-row clickable-row <?= ($log['statusClass'] ?? '') === 'over' ? 'over-row' : '' ?>"
                            title="Open parking position for <?= e($log['plate']) ?>"
                        >
                            <div>
                                <div class="person-main"><?= e($log['name']) ?></div>
                                <div class="person-sub"><?= e($log['type']) ?></div>
                            </div>

                            <div>
                                <span class="plate-badge"><?= e($log['plate']) ?></span>
                            </div>

                            <div>
                                <div class="time-main"><?= e($log['entryTime']) ?></div>
                                <div class="time-sub"><?= e($log['entryDate']) ?></div>
                            </div>

                            <div>
                                <div class="time-main"><?= e($log['exitTime']) ?></div>
                                <div class="time-sub"><?= e($log['exitDate']) ?></div>
                            </div>

                            <div>
                                <span class="status-pill <?= e($log['statusClass']) ?>">
                                    <?= e($log['status']) ?>
                                </span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        </main>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const REFRESH_SECONDS = 10;
    let countdown = REFRESH_SECONDS;

    const autoRefreshBadge = document.createElement('div');
    autoRefreshBadge.style.position = 'fixed';
    autoRefreshBadge.style.right = '18px';
    autoRefreshBadge.style.bottom = '18px';
    autoRefreshBadge.style.zIndex = '9999';
    autoRefreshBadge.style.background = '#ffffff';
    autoRefreshBadge.style.border = '1px solid #e5e7eb';
    autoRefreshBadge.style.borderRadius = '999px';
    autoRefreshBadge.style.padding = '9px 13px';
    autoRefreshBadge.style.fontWeight = '900';
    autoRefreshBadge.style.fontSize = '12px';
    autoRefreshBadge.style.color = '#64748b';
    autoRefreshBadge.style.boxShadow = '0 12px 24px rgba(15, 23, 42, .08)';
    autoRefreshBadge.innerHTML = '<i class="fas fa-rotate" style="color:#dc2626;margin-right:6px;"></i> Refresh in ' + countdown + 's';
    document.body.appendChild(autoRefreshBadge);

    function isUserEditingFilter() {
        const active = document.activeElement;
        if (!active) return false;

        const tag = active.tagName ? active.tagName.toLowerCase() : '';
        return tag === 'input' || tag === 'select' || tag === 'textarea';
    }

    setInterval(function () {
        if (isUserEditingFilter()) {
            countdown = REFRESH_SECONDS;
            autoRefreshBadge.innerHTML = '<i class="fas fa-pause" style="color:#dc2626;margin-right:6px;"></i> Auto refresh paused';
            return;
        }

        countdown -= 1;

        if (countdown <= 0) {
            window.location.reload();
            return;
        }

        autoRefreshBadge.innerHTML = '<i class="fas fa-rotate" style="color:#dc2626;margin-right:6px;"></i> Refresh in ' + countdown + 's';
    }, 1000);
});
</script>

</body>
</html>
