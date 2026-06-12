<?php
require_once '../core/security.php';
require_login(['admin', 'superadmin']);

$pdo = db();

$message = '';
$error = '';

function safe_text($value) {
    return $value !== null && $value !== '' ? $value : '-';
}

function has_column_resident_approval(PDO $pdo, string $table, string $column): bool {
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

function safe_count_resident_approval(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function resident_status_class($status) {
    return match ($status) {
        'active' => 'badge-active',
        'pending' => 'badge-pending',
        'inactive' => 'badge-inactive',
        'rejected' => 'badge-rejected',
        default => 'badge-default'
    };
}

function unit_label($row) {
    if (empty($row['unit_no'])) {
        return 'No unit assigned';
    }

    return 'Block ' . $row['block_no'] .
        ' / Floor ' . $row['floor_no'] .
        ' / Unit ' . $row['unit_no'];
}

$hasFullName = has_column_resident_approval($pdo, 'users', 'full_name');
$hasContact = has_column_resident_approval($pdo, 'users', 'contact_number');

$residentNameSql = $hasFullName ? "u.full_name AS resident_name" : "NULL AS resident_name";
$residentContactSql = $hasContact ? "u.contact_number AS resident_contact" : "NULL AS resident_contact";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'approve_and_assign') {
                $residentId = (int)($_POST['resident_id'] ?? 0);
                $unitId = (int)($_POST['unit_id'] ?? 0);

                if ($residentId <= 0) {
                    throw new Exception('Invalid resident selected.');
                }

                if ($unitId <= 0) {
                    throw new Exception('Please select a unit before approving the resident.');
                }

                $stmt = $pdo->prepare("
                    SELECT *
                    FROM users
                    WHERE id = ?
                    AND role = 'resident'
                    LIMIT 1
                ");
                $stmt->execute([$residentId]);
                $resident = $stmt->fetch();

                if (!$resident) {
                    throw new Exception('Resident not found.');
                }

                $stmt = $pdo->prepare("
                    SELECT *
                    FROM units
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmt->execute([$unitId]);
                $unit = $stmt->fetch();

                if (!$unit) {
                    throw new Exception('Selected unit not found.');
                }

                $stmt = $pdo->prepare("
                    SELECT 
                        ru.*,
                        u.email
                    FROM resident_units ru
                    JOIN users u ON u.id = ru.resident_id
                    WHERE ru.unit_id = ?
                    AND ru.status = 'active'
                    AND ru.resident_id != ?
                    LIMIT 1
                ");
                $stmt->execute([
                    $unitId,
                    $residentId
                ]);
                $occupied = $stmt->fetch();

                if ($occupied) {
                    throw new Exception('This unit is already assigned to another resident: ' . $occupied['email']);
                }

                $pdo->beginTransaction();

                $pdo->prepare("
                    UPDATE users
                    SET status = 'active'
                    WHERE id = ?
                    AND role = 'resident'
                ")->execute([$residentId]);

                $pdo->prepare("
                    UPDATE resident_units
                    SET status = 'inactive',
                        updated_at = NOW()
                    WHERE resident_id = ?
                    AND status = 'active'
                ")->execute([$residentId]);

                $stmt = $pdo->prepare("
                    INSERT INTO resident_units
                    (resident_id, unit_id, status, created_at)
                    VALUES
                    (?, ?, 'active', NOW())
                ");
                $stmt->execute([
                    $residentId,
                    $unitId
                ]);

                if (function_exists('log_audit')) {
                    log_audit(
                        'RESIDENT_APPROVED_AND_ASSIGNED',
                        'Admin approved resident ' . $resident['email'] . ' and assigned unit ' . $unit['unit_no']
                    );
                }

                $pdo->commit();

                $message = 'Resident approved and assigned to unit successfully.';
            }

            if ($action === 'assign_unit') {
                $residentId = (int)($_POST['resident_id'] ?? 0);
                $unitId = (int)($_POST['unit_id'] ?? 0);

                if ($residentId <= 0) {
                    throw new Exception('Invalid resident selected.');
                }

                if ($unitId <= 0) {
                    throw new Exception('Please select a unit.');
                }

                $stmt = $pdo->prepare("
                    SELECT *
                    FROM users
                    WHERE id = ?
                    AND role = 'resident'
                    LIMIT 1
                ");
                $stmt->execute([$residentId]);
                $resident = $stmt->fetch();

                if (!$resident) {
                    throw new Exception('Resident not found.');
                }

                $stmt = $pdo->prepare("
                    SELECT *
                    FROM units
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmt->execute([$unitId]);
                $unit = $stmt->fetch();

                if (!$unit) {
                    throw new Exception('Selected unit not found.');
                }

                $stmt = $pdo->prepare("
                    SELECT 
                        ru.*,
                        u.email
                    FROM resident_units ru
                    JOIN users u ON u.id = ru.resident_id
                    WHERE ru.unit_id = ?
                    AND ru.status = 'active'
                    AND ru.resident_id != ?
                    LIMIT 1
                ");
                $stmt->execute([
                    $unitId,
                    $residentId
                ]);
                $occupied = $stmt->fetch();

                if ($occupied) {
                    throw new Exception('This unit is already assigned to another resident: ' . $occupied['email']);
                }

                $pdo->beginTransaction();

                $pdo->prepare("
                    UPDATE resident_units
                    SET status = 'inactive',
                        updated_at = NOW()
                    WHERE resident_id = ?
                    AND status = 'active'
                ")->execute([$residentId]);

                $stmt = $pdo->prepare("
                    INSERT INTO resident_units
                    (resident_id, unit_id, status, created_at)
                    VALUES
                    (?, ?, 'active', NOW())
                ");
                $stmt->execute([
                    $residentId,
                    $unitId
                ]);

                if ($resident['status'] !== 'active') {
                    $pdo->prepare("
                        UPDATE users
                        SET status = 'active'
                        WHERE id = ?
                    ")->execute([$residentId]);
                }

                if (function_exists('log_audit')) {
                    log_audit(
                        'RESIDENT_UNIT_ASSIGNED',
                        'Admin assigned resident ' . $resident['email'] . ' to unit ' . $unit['unit_no']
                    );
                }

                $pdo->commit();

                $message = 'Resident unit assigned successfully.';
            }

            if ($action === 'remove_unit') {
                $residentId = (int)($_POST['resident_id'] ?? 0);

                if ($residentId <= 0) {
                    throw new Exception('Invalid resident selected.');
                }

                $stmt = $pdo->prepare("
                    SELECT *
                    FROM users
                    WHERE id = ?
                    AND role = 'resident'
                    LIMIT 1
                ");
                $stmt->execute([$residentId]);
                $resident = $stmt->fetch();

                if (!$resident) {
                    throw new Exception('Resident not found.');
                }

                $pdo->prepare("
                    UPDATE resident_units
                    SET status = 'inactive',
                        updated_at = NOW()
                    WHERE resident_id = ?
                    AND status = 'active'
                ")->execute([$residentId]);

                if (function_exists('log_audit')) {
                    log_audit('RESIDENT_UNIT_REMOVED', 'Admin removed active unit for resident: ' . $resident['email']);
                }

                $message = 'Resident unit removed successfully.';
            }

            if ($action === 'update_status') {
                $residentId = (int)($_POST['resident_id'] ?? 0);
                $newStatus = $_POST['new_status'] ?? '';

                if ($residentId <= 0) {
                    throw new Exception('Invalid resident selected.');
                }

                if (!in_array($newStatus, ['active', 'pending', 'inactive', 'rejected'], true)) {
                    throw new Exception('Invalid status selected.');
                }

                $stmt = $pdo->prepare("
                    SELECT *
                    FROM users
                    WHERE id = ?
                    AND role = 'resident'
                    LIMIT 1
                ");
                $stmt->execute([$residentId]);
                $resident = $stmt->fetch();

                if (!$resident) {
                    throw new Exception('Resident not found.');
                }

                $pdo->prepare("
                    UPDATE users
                    SET status = ?
                    WHERE id = ?
                    AND role = 'resident'
                ")->execute([
                    $newStatus,
                    $residentId
                ]);

                if ($newStatus !== 'active') {
                    $pdo->prepare("
                        UPDATE resident_units
                        SET status = 'inactive',
                            updated_at = NOW()
                        WHERE resident_id = ?
                        AND status = 'active'
                    ")->execute([$residentId]);
                }

                if (function_exists('log_audit')) {
                    log_audit('RESIDENT_STATUS_UPDATED', 'Admin changed resident ' . $resident['email'] . ' to ' . $newStatus);
                }

                $message = 'Resident status updated successfully.';
            }

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e->getMessage();
        }
    }
}

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$blockFilter = trim($_GET['block_no'] ?? '');
$floorFilter = trim($_GET['floor_no'] ?? '');
$unitIdFilter = (int)($_GET['unit_id'] ?? 0);

$where = "WHERE u.role = 'resident'";
$params = [];

if ($search !== '') {
    $where .= "
        AND (
            u.email LIKE ?
    ";

    $term = '%' . $search . '%';
    $params[] = $term;

    if ($hasFullName) {
        $where .= " OR u.full_name LIKE ?";
        $params[] = $term;
    }

    if ($hasContact) {
        $where .= " OR u.contact_number LIKE ?";
        $params[] = $term;
    }

    $where .= "
            OR un.unit_no LIKE ?
            OR un.block_no LIKE ?
        )
    ";

    $params[] = $term;
    $params[] = $term;
}

if ($statusFilter !== '') {
    $where .= " AND u.status = ?";
    $params[] = $statusFilter;
}

if ($blockFilter !== '') {
    $where .= " AND un.block_no = ?";
    $params[] = $blockFilter;
}

if ($floorFilter !== '') {
    $where .= " AND un.floor_no = ?";
    $params[] = $floorFilter;
}

if ($unitIdFilter > 0) {
    $where .= " AND un.id = ?";
    $params[] = $unitIdFilter;
}

$stmt = $pdo->prepare("
    SELECT
        u.id,
        {$residentNameSql},
        u.email,
        {$residentContactSql},
        u.status,
        u.created_at,

        ru.id AS resident_unit_id,
        ru.unit_id,
        un.block_no,
        un.floor_no,
        un.unit_no,
        a.apartment_name,

        (
            SELECT COUNT(*)
            FROM bookings b
            WHERE b.resident_id = u.id
        ) AS booking_count,

        (
            SELECT COUNT(*)
            FROM resident_vehicles rv
            WHERE rv.resident_id = u.id
        ) AS vehicle_count

    FROM users u

    LEFT JOIN resident_units ru
        ON ru.resident_id = u.id
        AND ru.status = 'active'

    LEFT JOIN units un ON un.id = ru.unit_id
    LEFT JOIN apartments a ON a.id = un.apartment_id

    {$where}

    ORDER BY
        FIELD(u.status, 'pending', 'active', 'inactive', 'rejected'),
        u.created_at DESC

    LIMIT 500
");
$stmt->execute($params);
$residents = $stmt->fetchAll();

$availableUnits = [];

try {
    $stmt = $pdo->query("
        SELECT
            un.id,
            un.block_no,
            un.floor_no,
            un.unit_no,
            ru.resident_id,
            u.email AS assigned_email
        FROM units un

        LEFT JOIN resident_units ru
            ON ru.unit_id = un.id
            AND ru.status = 'active'

        LEFT JOIN users u ON u.id = ru.resident_id

        ORDER BY
            un.block_no ASC,
            un.floor_no ASC,
            un.unit_no ASC
    ");
    $availableUnits = $stmt->fetchAll();
} catch (Throwable $e) {
    $availableUnits = [];
}

$blockOptions = [];

try {
    $stmt = $pdo->query("
        SELECT DISTINCT block_no
        FROM units
        ORDER BY block_no ASC
    ");
    $blockOptions = $stmt->fetchAll();
} catch (Throwable $e) {
    $blockOptions = [];
}

$floorOptions = [];

try {
    $stmt = $pdo->query("
        SELECT DISTINCT floor_no
        FROM units
        ORDER BY floor_no ASC
    ");
    $floorOptions = $stmt->fetchAll();
} catch (Throwable $e) {
    $floorOptions = [];
}

$totalResidents = safe_count_resident_approval($pdo, "SELECT COUNT(*) FROM users WHERE role = 'resident'");
$pendingResidents = safe_count_resident_approval($pdo, "SELECT COUNT(*) FROM users WHERE role = 'resident' AND status = 'pending'");
$activeResidents = safe_count_resident_approval($pdo, "SELECT COUNT(*) FROM users WHERE role = 'resident' AND status = 'active'");
$inactiveResidents = safe_count_resident_approval($pdo, "SELECT COUNT(*) FROM users WHERE role = 'resident' AND status = 'inactive'");
$rejectedResidents = safe_count_resident_approval($pdo, "SELECT COUNT(*) FROM users WHERE role = 'resident' AND status = 'rejected'");
$assignedResidents = safe_count_resident_approval($pdo, "SELECT COUNT(DISTINCT resident_id) FROM resident_units WHERE status = 'active'");
$totalUnits = safe_count_resident_approval($pdo, "SELECT COUNT(*) FROM units");
$availableUnitCount = safe_count_resident_approval($pdo, "
    SELECT COUNT(*)
    FROM units un
    LEFT JOIN resident_units ru
        ON ru.unit_id = un.id
        AND ru.status = 'active'
    WHERE ru.id IS NULL
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Resident Approvals - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --success: #16a34a;
            --warning: #d97706;
            --danger: #dc2626;
            --info: #0284c7;
            --text: #111827;
            --muted: #6b7280;
            --border: #e5e7eb;
            --shadow: 0 18px 45px rgba(15, 23, 42, 0.08);
        }

        * {
            box-sizing: border-box;
            font-family: 'Plus Jakarta Sans', sans-serif;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(37, 99, 235, 0.14), transparent 30%),
                linear-gradient(180deg, #f8fafc 0%, #eef2f7 100%);
            color: var(--text);
        }

        .container {
            max-width: 1240px;
            margin: 35px auto;
            padding: 0 20px 60px;
        }

        .header-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 24px;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 900;
            letter-spacing: -0.05em;
        }

        .page-sub {
            color: var(--muted);
            margin-top: 6px;
            font-weight: 700;
            line-height: 1.5;
        }

        .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            border: none;
            cursor: pointer;
            padding: 11px 15px;
            border-radius: 13px;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            font-size: .84rem;
            transition: .2s;
            white-space: nowrap;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 14px 25px rgba(37,99,235,.22);
        }

        .btn-light {
            background: white;
            color: #111827;
            border: 1px solid var(--border);
        }

        .btn-danger {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .btn-warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fcd34d;
        }

        .btn-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(8, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: rgba(255,255,255,.97);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 18px;
            box-shadow: var(--shadow);
        }

        .stat-value {
            font-size: 1.65rem;
            font-weight: 900;
            letter-spacing: -0.05em;
            margin-bottom: 6px;
        }

        .stat-label {
            font-size: .67rem;
            font-weight: 900;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .06em;
            line-height: 1.25;
        }

        .panel {
            background: rgba(255,255,255,.97);
            border: 1px solid var(--border);
            border-radius: 24px;
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 22px;
        }

        .panel-header {
            padding: 20px 22px;
            border-bottom: 1px solid var(--border);
            font-weight: 900;
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .panel-body {
            padding: 22px;
        }

        .alert {
            padding: 15px;
            border-radius: 16px;
            margin-bottom: 18px;
            font-weight: 800;
            line-height: 1.45;
        }

        .alert.success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .alert.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .filter-form {
            display: grid;
            grid-template-columns: 1fr 150px 140px 140px auto auto;
            gap: 10px;
            margin-bottom: 18px;
            align-items: end;
        }

        label {
            display: block;
            font-size: .74rem;
            font-weight: 900;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 8px;
        }

        input, select {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid var(--border);
            border-radius: 14px;
            font-weight: 800;
            outline: none;
            background: white;
        }

        input:focus, select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(37,99,235,.10);
        }

        .resident-card {
            border: 1px solid var(--border);
            background: #f8fafc;
            border-radius: 20px;
            padding: 18px;
            margin-bottom: 16px;
        }

        .resident-card.pending {
            border-color: #fcd34d;
            background: #fffbeb;
        }

        .resident-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 14px;
        }

        .resident-name {
            font-size: 1.08rem;
            font-weight: 900;
            color: #111827;
            line-height: 1.35;
        }

        .small {
            color: #64748b;
            font-size: .8rem;
            margin-top: 4px;
            line-height: 1.5;
            font-weight: 700;
        }

        .badge {
            padding: 6px 10px;
            border-radius: 999px;
            font-size: .68rem;
            font-weight: 900;
            display: inline-flex;
            text-transform: uppercase;
            letter-spacing: .04em;
            white-space: nowrap;
        }

        .badge-active {
            background: #dcfce7;
            color: #166534;
        }

        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-inactive {
            background: #f1f5f9;
            color: #475569;
        }

        .badge-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-default {
            background: #f3f4f6;
            color: #374151;
        }

        .badge-unit {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .badge-no-unit {
            background: #fee2e2;
            color: #991b1b;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 14px;
        }

        .info-box {
            background: white;
            border: 1px solid var(--border);
            border-radius: 15px;
            padding: 14px;
        }

        .info-label {
            font-size: .7rem;
            font-weight: 900;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 7px;
        }

        .info-value {
            font-weight: 900;
            color: #111827;
            line-height: 1.45;
            word-break: break-word;
        }

        .assign-box {
            background: white;
            border: 1px solid var(--border);
            border-radius: 15px;
            padding: 14px;
            margin-top: 14px;
        }

        .assign-form {
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 10px;
            align-items: end;
        }

        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 14px;
        }

        .empty {
            padding: 44px 22px;
            text-align: center;
            color: var(--muted);
            font-weight: 700;
        }

        @media (max-width: 1180px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }

            .filter-form,
            .info-grid,
            .assign-form {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 620px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .header-row,
            .resident-top {
                flex-direction: column;
            }

            .header-actions,
            .actions {
                width: 100%;
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-shell">
<?php require_once __DIR__ . '/admin_sidebar.php'; ?>
<main class="main-content">

<div class="container">
    <div class="header-row">
        <div>
            <h1 class="page-title">Resident Approvals</h1>
            <p class="page-sub">
                Approve resident accounts and assign each resident to the correct block, floor, and unit.
            </p>
        </div>

        <div class="header-actions">
            <a href="admin_resident_apartment.php" class="btn btn-light">
                <i class="fas fa-building"></i>
                Blocks / Units
            </a>

            <a href="admin_residents_manage.php?role=resident" class="btn btn-light">
                <i class="fas fa-users"></i>
                Users
            </a>

            <a href="admin_dashboard.php" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i>
                Dashboard
            </a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert success"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= $totalResidents ?></div>
            <div class="stat-label">Total Residents</div>
        </div>

        <div class="stat-card">
            <div class="stat-value" style="color:var(--warning);"><?= $pendingResidents ?></div>
            <div class="stat-label">Pending</div>
        </div>

        <div class="stat-card">
            <div class="stat-value" style="color:var(--success);"><?= $activeResidents ?></div>
            <div class="stat-label">Active</div>
        </div>

        <div class="stat-card">
            <div class="stat-value" style="color:var(--muted);"><?= $inactiveResidents ?></div>
            <div class="stat-label">Inactive</div>
        </div>

        <div class="stat-card">
            <div class="stat-value" style="color:var(--danger);"><?= $rejectedResidents ?></div>
            <div class="stat-label">Rejected</div>
        </div>

        <div class="stat-card">
            <div class="stat-value" style="color:var(--primary);"><?= $assignedResidents ?></div>
            <div class="stat-label">Assigned Residents</div>
        </div>

        <div class="stat-card">
            <div class="stat-value"><?= $totalUnits ?></div>
            <div class="stat-label">Total Units</div>
        </div>

        <div class="stat-card">
            <div class="stat-value" style="color:var(--primary);"><?= $availableUnitCount ?></div>
            <div class="stat-label">Available Units</div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <i class="fas fa-user-check"></i>
            Resident List
        </div>

        <div class="panel-body">
            <form method="GET" class="filter-form">
                <div>
                    <label>Search</label>
                    <input 
                        type="text" 
                        name="search" 
                        value="<?= e($search) ?>" 
                        placeholder="Name, email, contact, unit"
                    >
                </div>

                <div>
                    <label>Status</label>
                    <select name="status">
                        <option value="">All</option>
                        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>
                </div>

                <div>
                    <label>Block</label>
                    <select name="block_no">
                        <option value="">All</option>
                        <?php foreach ($blockOptions as $b): ?>
                            <option value="<?= e($b['block_no']) ?>" <?= $blockFilter === $b['block_no'] ? 'selected' : '' ?>>
                                <?= e($b['block_no']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Floor</label>
                    <select name="floor_no">
                        <option value="">All</option>
                        <?php foreach ($floorOptions as $f): ?>
                            <option value="<?= e($f['floor_no']) ?>" <?= $floorFilter == $f['floor_no'] ? 'selected' : '' ?>>
                                <?= e($f['floor_no']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i>
                    Filter
                </button>

                <a href="admin_resident_approvals.php" class="btn btn-light">
                    Reset
                </a>
            </form>

            <?php if (empty($residents)): ?>
                <div class="empty">
                    No resident found.
                </div>
            <?php else: ?>
                <?php foreach ($residents as $resident): ?>
                    <?php
                        $hasUnit = !empty($resident['unit_id']);
                        $residentName = $resident['resident_name'] ?: $resident['email'];
                        $unitText = unit_label($resident);
                    ?>

                    <div class="resident-card <?= $resident['status'] === 'pending' ? 'pending' : '' ?>">
                        <div class="resident-top">
                            <div>
                                <div class="resident-name">
                                    <?= e($residentName) ?>
                                </div>

                                <div class="small">
                                    <?= e($resident['email']) ?>
                                    <br>
                                    Registered: <?= e(date('d M Y, g:i A', strtotime($resident['created_at'] ?? 'now'))) ?>
                                </div>
                            </div>

                            <div style="display:flex;gap:7px;flex-wrap:wrap;justify-content:flex-end;">
                                <span class="badge <?= e(resident_status_class($resident['status'])) ?>">
                                    <?= e($resident['status']) ?>
                                </span>

                                <span class="badge <?= $hasUnit ? 'badge-unit' : 'badge-no-unit' ?>">
                                    <?= $hasUnit ? 'Unit Assigned' : 'No Unit' ?>
                                </span>
                            </div>
                        </div>

                        <div class="info-grid">
                            <div class="info-box">
                                <div class="info-label">Contact</div>
                                <div class="info-value"><?= e(safe_text($resident['resident_contact'])) ?></div>
                            </div>

                            <div class="info-box">
                                <div class="info-label">Current Unit</div>
                                <div class="info-value"><?= e($unitText) ?></div>
                            </div>

                            <div class="info-box">
                                <div class="info-label">Visitor Bookings</div>
                                <div class="info-value"><?= (int)$resident['booking_count'] ?></div>
                            </div>

                            <div class="info-box">
                                <div class="info-label">Resident Vehicles</div>
                                <div class="info-value"><?= (int)$resident['vehicle_count'] ?></div>
                            </div>
                        </div>

                        <div class="assign-box">
                            <form method="POST" class="assign-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="resident_id" value="<?= (int)$resident['id'] ?>">

                                <div>
                                    <label>Select Unit</label>
                                    <select name="unit_id" required>
                                        <option value="">-- Select available unit --</option>

                                        <?php foreach ($availableUnits as $unit): ?>
                                            <?php
                                                $assignedToOther = !empty($unit['resident_id']) && (int)$unit['resident_id'] !== (int)$resident['id'];
                                                $isCurrentResidentUnit = !empty($unit['resident_id']) && (int)$unit['resident_id'] === (int)$resident['id'];

                                                if ($assignedToOther) {
                                                    continue;
                                                }

                                                $optionText = 'Block ' . $unit['block_no'] .
                                                    ' / Floor ' . $unit['floor_no'] .
                                                    ' / Unit ' . $unit['unit_no'];

                                                if ($isCurrentResidentUnit) {
                                                    $optionText .= ' (Current)';
                                                }
                                            ?>

                                            <option value="<?= (int)$unit['id'] ?>" <?= $isCurrentResidentUnit ? 'selected' : '' ?>>
                                                <?= e($optionText) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <?php if ($resident['status'] === 'pending' || $resident['status'] === 'rejected'): ?>
                                    <button type="submit" name="action" value="approve_and_assign" class="btn btn-success">
                                        <i class="fas fa-check"></i>
                                        Approve & Assign
                                    </button>
                                <?php else: ?>
                                    <button type="submit" name="action" value="assign_unit" class="btn btn-primary">
                                        <i class="fas fa-door-open"></i>
                                        Assign / Change Unit
                                    </button>
                                <?php endif; ?>

                                <?php if ($hasUnit): ?>
                                    <button type="submit" name="action" value="remove_unit" class="btn btn-warning">
                                        Remove Unit
                                    </button>
                                <?php endif; ?>
                            </form>
                        </div>

                        <div class="actions">
                            <?php if ($resident['status'] !== 'active' && $hasUnit): ?>
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="resident_id" value="<?= (int)$resident['id'] ?>">
                                    <input type="hidden" name="new_status" value="active">
                                    <button type="submit" class="btn btn-success">
                                        Activate
                                    </button>
                                </form>
                            <?php endif; ?>

                            <?php if ($resident['status'] !== 'pending'): ?>
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="resident_id" value="<?= (int)$resident['id'] ?>">
                                    <input type="hidden" name="new_status" value="pending">
                                    <button type="submit" class="btn btn-light">
                                        Set Pending
                                    </button>
                                </form>
                            <?php endif; ?>

                            <?php if ($resident['status'] !== 'inactive'): ?>
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="resident_id" value="<?= (int)$resident['id'] ?>">
                                    <input type="hidden" name="new_status" value="inactive">
                                    <button type="submit" class="btn btn-warning">
                                        Deactivate
                                    </button>
                                </form>
                            <?php endif; ?>

                            <?php if ($resident['status'] !== 'rejected'): ?>
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="resident_id" value="<?= (int)$resident['id'] ?>">
                                    <input type="hidden" name="new_status" value="rejected">
                                    <button type="submit" class="btn btn-danger">
                                        Reject
                                    </button>
                                </form>
                            <?php endif; ?>

                            <a href="admin_resident_vehicles.php?search=<?= urlencode($resident['email']) ?>" class="btn btn-light">
                                Vehicles
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="small">
                    Showing maximum 500 residents.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

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

</main>
</div>
</body>
</html>