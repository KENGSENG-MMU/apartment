<?php
require_once '../core/security.php';
require_login(['admin', 'superadmin']);

$pdo = db();

if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function audit_table_exists(PDO $pdo, string $table): bool {
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

function audit_columns(PDO $pdo, string $table): array {
    try {
        $stmt = $pdo->prepare("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION
        ");
        $stmt->execute([$table]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function audit_has_col(array $columns, string $column): bool {
    return in_array($column, $columns, true);
}

function audit_pick_col(array $columns, array $candidates): ?string {
    foreach ($candidates as $candidate) {
        if (audit_has_col($columns, $candidate)) {
            return $candidate;
        }
    }

    return null;
}

function audit_qcol(string $column): string {
    return '`' . str_replace('`', '``', $column) . '`';
}

function audit_valid_date($date): ?string {
    $date = trim((string)$date);
    if ($date === '') {
        return null;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return ($dt && $dt->format('Y-m-d') === $date) ? $date : null;
}

function audit_current_apartment_id(PDO $pdo): ?int {
    if (!empty($_SESSION['apartment_id'])) {
        return (int)$_SESSION['apartment_id'];
    }

    $uid = (int)($_SESSION['uid'] ?? 0);

    if ($uid > 0 && audit_table_exists($pdo, 'users')) {
        $userColumns = audit_columns($pdo, 'users');

        if (audit_has_col($userColumns, 'apartment_id')) {
            try {
                $stmt = $pdo->prepare("SELECT apartment_id FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$uid]);
                $apartmentId = $stmt->fetchColumn();

                if ($apartmentId) {
                    $_SESSION['apartment_id'] = (int)$apartmentId;
                    return (int)$apartmentId;
                }
            } catch (Throwable $e) {
                return null;
            }
        }
    }

    return null;
}

function audit_current_apartment_name(PDO $pdo, ?int $apartmentId): string {
    if ($apartmentId && audit_table_exists($pdo, 'apartments')) {
        try {
            $stmt = $pdo->prepare("SELECT apartment_name FROM apartments WHERE id = ? LIMIT 1");
            $stmt->execute([$apartmentId]);
            $name = $stmt->fetchColumn();

            if ($name) {
                return (string)$name;
            }
        } catch (Throwable $e) {
            // keep fallback
        }
    }

    return 'Ixoro Apartment';
}

function audit_dt($value): string {
    if (!$value) {
        return '-';
    }

    $time = strtotime((string)$value);
    if (!$time) {
        return (string)$value;
    }

    return date('d M Y, h:i A', $time);
}

function audit_badge_class(string $action, string $status = ''): string {
    $value = strtolower($action . ' ' . $status);

    if (str_contains($value, 'fail') || str_contains($value, 'deny') || str_contains($value, 'reject') || str_contains($value, 'delete') || str_contains($value, 'blacklist')) {
        return 'danger';
    }

    if (str_contains($value, 'login') || str_contains($value, 'create') || str_contains($value, 'approve') || str_contains($value, 'success') || str_contains($value, 'paid')) {
        return 'success';
    }

    if (str_contains($value, 'update') || str_contains($value, 'edit') || str_contains($value, 'mark')) {
        return 'warning';
    }

    return 'neutral';
}

function audit_build_base(
    PDO $pdo,
    array $auditColumns,
    ?string $userIdCol,
    ?string $dateCol,
    ?string $actionCol,
    ?string $detailCol,
    ?string $ipCol,
    ?string $statusCol,
    ?string $moduleCol,
    ?string $recordCol,
    ?int $apartmentId,
    string $search,
    string $actionFilter,
    ?string $dateFrom,
    ?string $dateTo,
    array &$params
): array {
    $role = $_SESSION['role'] ?? 'admin';
    $joinUsers = false;
    $joins = '';
    $where = ' WHERE 1=1 ';

    $userColumns = audit_table_exists($pdo, 'users') ? audit_columns($pdo, 'users') : [];
    if ($userIdCol && $userColumns && audit_has_col($userColumns, 'id')) {
        $joinUsers = true;
        $joins .= ' LEFT JOIN users u ON u.id = al.' . audit_qcol($userIdCol) . ' ';
    }

    if ($role !== 'superadmin' && $apartmentId && audit_has_col($auditColumns, 'apartment_id')) {
        $where .= ' AND al.apartment_id = ? ';
        $params[] = $apartmentId;
    }

    if ($dateCol) {
        if ($dateFrom) {
            $where .= ' AND DATE(al.' . audit_qcol($dateCol) . ') >= ? ';
            $params[] = $dateFrom;
        }

        if ($dateTo) {
            $where .= ' AND DATE(al.' . audit_qcol($dateCol) . ') <= ? ';
            $params[] = $dateTo;
        }
    }

    if ($actionFilter !== '' && $actionCol) {
        $where .= ' AND al.' . audit_qcol($actionCol) . ' = ? ';
        $params[] = $actionFilter;
    }

    if ($search !== '') {
        $searchCols = [];

        foreach ([$actionCol, $detailCol, $ipCol, $statusCol, $moduleCol, $recordCol] as $col) {
            if ($col) {
                $searchCols[] = 'al.' . audit_qcol($col) . ' LIKE ?';
            }
        }

        if ($joinUsers) {
            if (audit_has_col($userColumns, 'full_name')) {
                $searchCols[] = 'u.full_name LIKE ?';
            }
            if (audit_has_col($userColumns, 'email')) {
                $searchCols[] = 'u.email LIKE ?';
            }
            if (audit_has_col($userColumns, 'role')) {
                $searchCols[] = 'u.role LIKE ?';
            }
        }

        if ($searchCols) {
            $where .= ' AND (' . implode(' OR ', $searchCols) . ') ';
            foreach ($searchCols as $_) {
                $params[] = '%' . $search . '%';
            }
        }
    }

    return [$joins, $where, $joinUsers, $userColumns];
}

$auditTableExists = audit_table_exists($pdo, 'audit_logs');
$auditColumns = $auditTableExists ? audit_columns($pdo, 'audit_logs') : [];

$idCol = audit_pick_col($auditColumns, ['id', 'log_id', 'audit_id']);
$userIdCol = audit_pick_col($auditColumns, ['user_id', 'admin_id', 'actor_id', 'performed_by', 'created_by']);
$dateCol = audit_pick_col($auditColumns, ['created_at', 'action_time', 'logged_at', 'timestamp', 'created_on']);
$actionCol = audit_pick_col($auditColumns, ['action', 'action_type', 'event_type', 'activity', 'operation']);
$detailCol = audit_pick_col($auditColumns, ['details', 'description', 'message', 'reason', 'remarks', 'changes']);
$ipCol = audit_pick_col($auditColumns, ['ip_address', 'ip', 'client_ip']);
$statusCol = audit_pick_col($auditColumns, ['status', 'result']);
$moduleCol = audit_pick_col($auditColumns, ['module', 'page', 'source', 'table_name']);
$recordCol = audit_pick_col($auditColumns, ['record_id', 'target_id', 'entity_id']);

$search = trim((string)($_GET['search'] ?? ''));
$actionFilter = trim((string)($_GET['action'] ?? ''));
$dateFrom = audit_valid_date($_GET['date_from'] ?? '') ?: date('Y-m-01');
$dateTo = audit_valid_date($_GET['date_to'] ?? '') ?: date('Y-m-t');
$apartmentId = audit_current_apartment_id($pdo);
$currentApartmentName = audit_current_apartment_name($pdo, $apartmentId);
$currentApartmentLabel = 'Apartment';

$rows = [];
$actions = [];
$totalLogs = 0;
$todayLogs = 0;
$failedLogs = 0;
$filteredLogs = 0;
$errorMessage = '';

if ($auditTableExists) {
    try {
        if ($actionCol) {
            $actionSql = 'SELECT DISTINCT al.' . audit_qcol($actionCol) . ' AS action_name FROM audit_logs al WHERE al.' . audit_qcol($actionCol) . ' IS NOT NULL AND al.' . audit_qcol($actionCol) . " <> ''";

            $actionParams = [];
            if (($_SESSION['role'] ?? 'admin') !== 'superadmin' && $apartmentId && audit_has_col($auditColumns, 'apartment_id')) {
                $actionSql .= ' AND al.apartment_id = ? ';
                $actionParams[] = $apartmentId;
            }

            $actionSql .= ' ORDER BY action_name ASC LIMIT 80';
            $stmt = $pdo->prepare($actionSql);
            $stmt->execute($actionParams);
            $actions = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }

        $scopeSql = '';
        $scopeParams = [];
        if (($_SESSION['role'] ?? 'admin') !== 'superadmin' && $apartmentId && audit_has_col($auditColumns, 'apartment_id')) {
            $scopeSql = ' WHERE apartment_id = ? ';
            $scopeParams[] = $apartmentId;
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM audit_logs' . $scopeSql);
        $stmt->execute($scopeParams);
        $totalLogs = (int)$stmt->fetchColumn();

        if ($dateCol) {
            $todaySql = 'SELECT COUNT(*) FROM audit_logs WHERE DATE(' . audit_qcol($dateCol) . ') = CURDATE()';
            $todayParams = [];
            if (($_SESSION['role'] ?? 'admin') !== 'superadmin' && $apartmentId && audit_has_col($auditColumns, 'apartment_id')) {
                $todaySql .= ' AND apartment_id = ? ';
                $todayParams[] = $apartmentId;
            }
            $stmt = $pdo->prepare($todaySql);
            $stmt->execute($todayParams);
            $todayLogs = (int)$stmt->fetchColumn();
        }

        if ($actionCol || $statusCol) {
            $failedParts = [];
            $failedParams = [];

            if ($actionCol) {
                $failedParts[] = 'LOWER(' . audit_qcol($actionCol) . ") LIKE '%fail%'";
                $failedParts[] = 'LOWER(' . audit_qcol($actionCol) . ") LIKE '%deny%'";
                $failedParts[] = 'LOWER(' . audit_qcol($actionCol) . ") LIKE '%reject%'";
            }

            if ($statusCol) {
                $failedParts[] = 'LOWER(' . audit_qcol($statusCol) . ") LIKE '%fail%'";
                $failedParts[] = 'LOWER(' . audit_qcol($statusCol) . ") LIKE '%deny%'";
            }

            $failedSql = 'SELECT COUNT(*) FROM audit_logs WHERE (' . implode(' OR ', $failedParts) . ')';
            if (($_SESSION['role'] ?? 'admin') !== 'superadmin' && $apartmentId && audit_has_col($auditColumns, 'apartment_id')) {
                $failedSql .= ' AND apartment_id = ? ';
                $failedParams[] = $apartmentId;
            }
            $stmt = $pdo->prepare($failedSql);
            $stmt->execute($failedParams);
            $failedLogs = (int)$stmt->fetchColumn();
        }

        $params = [];
        [$joins, $where, $joinUsers, $userColumns] = audit_build_base(
            $pdo,
            $auditColumns,
            $userIdCol,
            $dateCol,
            $actionCol,
            $detailCol,
            $ipCol,
            $statusCol,
            $moduleCol,
            $recordCol,
            $apartmentId,
            $search,
            $actionFilter,
            $dateFrom,
            $dateTo,
            $params
        );

        $actorNameExpr = "'System'";
        $actorRoleExpr = "''";
        $actorEmailExpr = "''";

        if ($joinUsers) {
            if (audit_has_col($userColumns, 'full_name')) {
                $actorNameExpr = "COALESCE(NULLIF(u.full_name, ''), u.email, CONCAT('User #', al." . audit_qcol($userIdCol) . "))";
            } elseif (audit_has_col($userColumns, 'email')) {
                $actorNameExpr = "COALESCE(NULLIF(u.email, ''), CONCAT('User #', al." . audit_qcol($userIdCol) . "))";
            } else {
                $actorNameExpr = "CONCAT('User #', al." . audit_qcol($userIdCol) . ")";
            }

            if (audit_has_col($userColumns, 'role')) {
                $actorRoleExpr = 'COALESCE(u.role, \'\')';
            }

            if (audit_has_col($userColumns, 'email')) {
                $actorEmailExpr = 'COALESCE(u.email, \'\')';
            }
        } elseif ($userIdCol) {
            $actorNameExpr = "CONCAT('User #', al." . audit_qcol($userIdCol) . ")";
        }

        $selectId = $idCol ? 'al.' . audit_qcol($idCol) : 'NULL';
        $selectDate = $dateCol ? 'al.' . audit_qcol($dateCol) : 'NULL';
        $selectAction = $actionCol ? 'al.' . audit_qcol($actionCol) : "''";
        $selectDetail = $detailCol ? 'al.' . audit_qcol($detailCol) : "''";
        $selectIp = $ipCol ? 'al.' . audit_qcol($ipCol) : "''";
        $selectStatus = $statusCol ? 'al.' . audit_qcol($statusCol) : "''";
        $selectModule = $moduleCol ? 'al.' . audit_qcol($moduleCol) : "''";
        $selectRecord = $recordCol ? 'al.' . audit_qcol($recordCol) : "''";

        $countSql = 'SELECT COUNT(*) FROM audit_logs al ' . $joins . $where;
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $filteredLogs = (int)$stmt->fetchColumn();

        $orderBy = $dateCol
            ? ' ORDER BY al.' . audit_qcol($dateCol) . ' DESC'
            : ($idCol ? ' ORDER BY al.' . audit_qcol($idCol) . ' DESC' : '');

        $sql = "
            SELECT
                {$selectId} AS log_id,
                {$selectDate} AS log_time,
                {$actorNameExpr} AS actor_name,
                {$actorRoleExpr} AS actor_role,
                {$actorEmailExpr} AS actor_email,
                {$selectAction} AS action_name,
                {$selectDetail} AS details,
                {$selectIp} AS ip_address,
                {$selectStatus} AS action_status,
                {$selectModule} AS module_name,
                {$selectRecord} AS record_id
            FROM audit_logs al
            {$joins}
            {$where}
            {$orderBy}
            LIMIT 300
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

$adminInitial = strtoupper(substr(trim((string)($_SESSION['email'] ?? 'A')), 0, 1)) ?: 'A';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Audit Logs | SmartVMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #dc2626;
            --primary-dark: #b91c1c;
            --primary-soft: #fff1f2;
            --text: #0f172a;
            --muted: #64748b;
            --line: #e5e7eb;
            --bg: #f4f6fb;
            --shadow: 0 20px 45px rgba(15, 23, 42, .08);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            height: 100vh;
            overflow: hidden;
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
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

        .main-content {
            padding: 24px 28px 26px;
            min-width: 0;
            height: 100vh;
            overflow: auto;
        }

        .topbar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 18px;
        }

        .eyebrow {
            color: var(--primary);
            text-transform: uppercase;
            font-size: .72rem;
            font-weight: 950;
            letter-spacing: .12em;
            margin-bottom: 5px;
        }

        h1 {
            margin: 0;
            font-size: 1.8rem;
            line-height: 1.05;
            letter-spacing: -.07em;
            font-weight: 950;
        }

        .page-sub {
            margin: 8px 0 0;
            color: #475569;
            font-size: .88rem;
            font-weight: 800;
            line-height: 1.45;
        }

        .top-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .top-btn,
        .profile-dot {
            height: 42px;
            border-radius: 999px;
            border: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-weight: 950;
            font-family: inherit;
        }

        .top-btn {
            padding: 0 17px;
            gap: 8px;
            color: #ffffff;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            box-shadow: 0 14px 28px rgba(220, 38, 38, .22);
        }

        .profile-dot {
            width: 42px;
            color: #ffffff;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            box-shadow: 0 14px 28px rgba(220, 38, 38, .22);
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 16px;
        }

        .stat-card {
            border: 1px solid var(--line);
            border-radius: 20px;
            background: rgba(255,255,255,.94);
            box-shadow: 0 14px 30px rgba(15, 23, 42, .05);
            padding: 16px 18px;
            min-height: 82px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
        }

        .stat-value {
            font-size: 1.32rem;
            font-weight: 950;
            letter-spacing: -.04em;
            color: #0f172a;
        }

        .stat-label {
            margin-top: 5px;
            color: #64748b;
            text-transform: uppercase;
            font-size: .66rem;
            letter-spacing: .06em;
            font-weight: 950;
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 15px;
            display: grid;
            place-items: center;
            background: #fee2e2;
            color: var(--primary);
        }

        .panel {
            border: 1px solid var(--line);
            border-radius: 24px;
            background: rgba(255,255,255,.98);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .panel-head {
            padding: 16px 18px;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .panel-title {
            font-size: 1rem;
            font-weight: 950;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .panel-title i {
            color: var(--primary);
        }

        .apartment-name {
            color: #64748b;
            font-size: .8rem;
            font-weight: 900;
        }

        .filters {
            display: grid;
            grid-template-columns: minmax(240px, 1fr) 190px 165px 165px 50px 50px;
            gap: 10px;
            padding: 14px 18px;
            border-bottom: 1px solid var(--line);
        }

        .input,
        .select {
            width: 100%;
            height: 46px;
            border-radius: 15px;
            border: 1px solid var(--line);
            background: #ffffff;
            padding: 0 14px;
            font-family: inherit;
            font-weight: 850;
            outline: none;
            color: #0f172a;
        }

        .input:focus,
        .select:focus {
            border-color: #fca5a5;
            box-shadow: 0 0 0 4px #fee2e2;
        }

        .btn {
            height: 46px;
            border-radius: 15px;
            border: 1px solid var(--line);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-family: inherit;
            font-weight: 950;
            cursor: pointer;
            background: #ffffff;
            color: #64748b;
        }

        .btn-search {
            border: 0;
            color: #ffffff;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            box-shadow: 0 14px 28px rgba(220, 38, 38, .18);
        }

        .table-wrap {
            overflow: auto;
            max-height: calc(100vh - 360px);
            min-height: 360px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1100px;
        }

        th {
            text-align: left;
            padding: 12px 16px;
            background: #f8fafc;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .06em;
            font-size: .66rem;
            font-weight: 950;
            border-bottom: 1px solid var(--line);
            position: sticky;
            top: 0;
            z-index: 3;
        }

        td {
            padding: 14px 16px;
            border-bottom: 1px solid #edf2f7;
            vertical-align: top;
            font-size: .82rem;
            font-weight: 800;
        }

        tbody tr:hover {
            background: #fffafa;
        }

        .time-main {
            font-weight: 950;
            color: #0f172a;
            white-space: nowrap;
        }

        .time-sub {
            margin-top: 3px;
            color: #64748b;
            font-size: .74rem;
            font-weight: 850;
            white-space: nowrap;
        }

        .actor-main {
            font-weight: 950;
        }

        .actor-sub {
            margin-top: 3px;
            color: #64748b;
            font-size: .74rem;
            font-weight: 850;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: .7rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .02em;
            white-space: nowrap;
        }

        .badge.success {
            color: #047857;
            background: #d1fae5;
        }

        .badge.danger {
            color: #b91c1c;
            background: #fee2e2;
        }

        .badge.warning {
            color: #b45309;
            background: #ffedd5;
        }

        .badge.neutral {
            color: #334155;
            background: #e2e8f0;
        }

        .details {
            max-width: 420px;
            color: #334155;
            line-height: 1.45;
            word-break: break-word;
        }

        .muted {
            color: #64748b;
            font-size: .74rem;
            margin-top: 4px;
        }

        .ip {
            color: #0f172a;
            font-weight: 900;
            white-space: nowrap;
        }

        .empty {
            min-height: 340px;
            display: grid;
            place-items: center;
            color: #64748b;
            font-weight: 850;
            text-align: center;
            padding: 30px;
        }

        .empty i {
            display: block;
            font-size: 2rem;
            color: #cbd5e1;
            margin-bottom: 10px;
        }

        .error-box {
            margin-bottom: 16px;
            border: 1px solid #fecaca;
            background: #fff1f2;
            color: #991b1b;
            border-radius: 18px;
            padding: 14px 16px;
            font-weight: 850;
        }

        @media (max-width: 1220px) {
            body {
                height: auto;
                overflow: auto;
            }

            .dashboard-shell {
                grid-template-columns: 1fr;
                height: auto;
                overflow: visible;
            }

            .sidebar {
                display: none;
            }

            .main-content {
                height: auto;
                overflow: visible;
            }

            .stat-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .filters {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 720px) {
            .main-content {
                padding: 20px;
            }

            .topbar {
                flex-direction: column;
            }

            .stat-grid,
            .filters {
                grid-template-columns: 1fr;
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
                <div class="eyebrow">System</div>
                <h1>Audit Logs</h1>
                <p class="page-sub">
                    View important system actions such as login, approval, blacklist, payment and gate activities.
                </p>
            </div>

            <div class="top-actions">
                <a href="admin_dashboard.php" class="top-btn">
                    <i class="fas fa-arrow-left"></i>
                    Dashboard
                </a>
                <div class="profile-dot"><?= e($adminInitial) ?></div>
            </div>
        </div>

        <div class="stat-grid">
            <div class="stat-card">
                <div>
                    <div class="stat-value"><?= (int)$totalLogs ?></div>
                    <div class="stat-label">Total Logs</div>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-list-check"></i>
                </div>
            </div>

            <div class="stat-card">
                <div>
                    <div class="stat-value" style="color:#16a34a;"><?= (int)$todayLogs ?></div>
                    <div class="stat-label">Today Logs</div>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-calendar-day"></i>
                </div>
            </div>

            <div class="stat-card">
                <div>
                    <div class="stat-value" style="color:#dc2626;"><?= (int)$failedLogs ?></div>
                    <div class="stat-label">Failed / Denied</div>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-triangle-exclamation"></i>
                </div>
            </div>

            <div class="stat-card">
                <div>
                    <div class="stat-value" style="color:#2563eb;"><?= (int)$filteredLogs ?></div>
                    <div class="stat-label">Filtered Results</div>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-filter"></i>
                </div>
            </div>
        </div>

        <?php if (!$auditTableExists): ?>
            <div class="error-box">
                <i class="fas fa-circle-exclamation"></i>
                audit_logs table was not found in the database.
            </div>
        <?php elseif ($errorMessage): ?>
            <div class="error-box">
                <i class="fas fa-circle-exclamation"></i>
                <?= e($errorMessage) ?>
            </div>
        <?php endif; ?>

        <section class="panel">
            <div class="panel-head">
                <div class="panel-title">
                    <i class="fas fa-clock-rotate-left"></i>
                    System Activity Logs
                </div>

                <div class="apartment-name"><?= e($currentApartmentName) ?></div>
            </div>

            <form method="GET" class="filters">
                <input
                    class="input"
                    type="text"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Search user, action, details, IP..."
                >

                <select class="select" name="action" <?= !$actionCol ? 'disabled' : '' ?>>
                    <option value="">All Actions</option>
                    <?php foreach ($actions as $action): ?>
                        <option value="<?= e($action) ?>" <?= $actionFilter === (string)$action ? 'selected' : '' ?>>
                            <?= e($action) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <input class="input" type="date" name="date_from" value="<?= e($dateFrom) ?>">
                <input class="input" type="date" name="date_to" value="<?= e($dateTo) ?>">

                <button class="btn btn-search" type="submit" title="Search">
                    <i class="fas fa-search"></i>
                </button>

                <a class="btn" href="admin_system_audit.php" title="Reset">
                    <i class="fas fa-rotate-left"></i>
                </a>
            </form>

            <?php if (!$auditTableExists || !$rows): ?>
                <div class="empty">
                    <div>
                        <i class="fas fa-clock-rotate-left"></i>
                        No audit log found.
                    </div>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Time</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Details</th>
                            <th>Module / Record</th>
                            <th>IP Address</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                                $actionName = trim((string)($row['action_name'] ?? 'System Action'));
                                $statusName = trim((string)($row['action_status'] ?? ''));
                                $badgeClass = audit_badge_class($actionName, $statusName);
                                $logTime = $row['log_time'] ?? '';
                                $timeFormatted = audit_dt($logTime);
                                $dateOnly = $logTime && strtotime((string)$logTime) ? date('d M Y', strtotime((string)$logTime)) : '';
                                $timeOnly = $logTime && strtotime((string)$logTime) ? date('h:i A', strtotime((string)$logTime)) : $timeFormatted;
                                $actorRole = trim((string)($row['actor_role'] ?? ''));
                                $actorEmail = trim((string)($row['actor_email'] ?? ''));
                                $moduleName = trim((string)($row['module_name'] ?? ''));
                                $recordId = trim((string)($row['record_id'] ?? ''));
                            ?>
                            <tr>
                                <td>
                                    <div class="time-main"><?= e($timeOnly) ?></div>
                                    <div class="time-sub"><?= e($dateOnly ?: '-') ?></div>
                                </td>

                                <td>
                                    <div class="actor-main"><?= e($row['actor_name'] ?: 'System') ?></div>
                                    <div class="actor-sub">
                                        <?= e($actorRole ?: 'User') ?>
                                        <?= $actorEmail ? ' · ' . e($actorEmail) : '' ?>
                                    </div>
                                </td>

                                <td>
                                    <span class="badge <?= e($badgeClass) ?>">
                                        <i class="fas fa-circle-dot"></i>
                                        <?= e($actionName ?: 'Action') ?>
                                    </span>
                                    <?php if ($statusName): ?>
                                        <div class="muted">Status: <?= e($statusName) ?></div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="details">
                                        <?= e($row['details'] ?: '-') ?>
                                    </div>
                                </td>

                                <td>
                                    <div class="actor-main"><?= e($moduleName ?: '-') ?></div>
                                    <?php if ($recordId !== ''): ?>
                                        <div class="muted">Record ID: <?= e($recordId) ?></div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="ip"><?= e($row['ip_address'] ?: '-') ?></div>
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
</body>
</html>
