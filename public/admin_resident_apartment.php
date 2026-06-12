<?php
require_once '../core/security.php';
require_login(['admin', 'superadmin']);

$pdo = db();

function has_column_uh(PDO $pdo, string $table, string $column): bool {
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

function safe_count_uh(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function safe_rows_uh(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function safe_text_uh($value): string {
    return ($value !== null && $value !== '') ? (string)$value : '-';
}

function first_apartment_id_uh(PDO $pdo): ?int {
    try {
        $stmt = $pdo->query("
            SELECT id
            FROM apartments
            ORDER BY id ASC
            LIMIT 1
        ");
        $row = $stmt->fetch();
        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

$hasUserApartmentId = has_column_uh($pdo, 'users', 'apartment_id');
$hasFullName = has_column_uh($pdo, 'users', 'full_name');
$hasContact = has_column_uh($pdo, 'users', 'contact_number');
$hasResidentType = has_column_uh($pdo, 'users', 'resident_type');

$hasProfilePhoto = has_column_uh($pdo, 'users', 'profile_photo');
$hasProfileImage = has_column_uh($pdo, 'users', 'profile_image');
$hasAvatar = has_column_uh($pdo, 'users', 'avatar');

$residentNameExpr = $hasFullName ? "COALESCE(NULLIF(u.full_name, ''), u.email, CONCAT('Resident #', ru.resident_id))" : "COALESCE(u.email, CONCAT('Resident #', ru.resident_id))";
$residentOrderExpr = $hasFullName ? "u.full_name ASC, u.email ASC" : "u.email ASC";
$residentContactExpr = $hasContact ? "COALESCE(NULLIF(u.contact_number, ''), '-')" : "'-'";
$residentTypeExpr = $hasResidentType ? "COALESCE(NULLIF(u.resident_type, ''), '-')" : "'-'";

if ($hasProfilePhoto) {
    $residentPhotoExpr = "COALESCE(NULLIF(u.profile_photo, ''), '')";
} elseif ($hasProfileImage) {
    $residentPhotoExpr = "COALESCE(NULLIF(u.profile_image, ''), '')";
} elseif ($hasAvatar) {
    $residentPhotoExpr = "COALESCE(NULLIF(u.avatar, ''), '')";
} else {
    $residentPhotoExpr = "''";
}

$currentUserId = (int)($_SESSION['uid'] ?? $_SESSION['user_id'] ?? 0);
$currentRole = $_SESSION['role'] ?? 'admin';
$currentEmail = $_SESSION['email'] ?? 'admin@apt.com';
$currentApartmentId = $_SESSION['apartment_id'] ?? null;

if (($currentApartmentId === null || $currentApartmentId === '') && $currentUserId > 0 && $hasUserApartmentId) {
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

if (($currentApartmentId === null || $currentApartmentId === '') && $currentRole !== 'superadmin') {
    $currentApartmentId = first_apartment_id_uh($pdo);
}

if ($currentRole === 'superadmin' && isset($_GET['apartment_id']) && $_GET['apartment_id'] !== '') {
    $currentApartmentId = (int)$_GET['apartment_id'];
}

if (($currentApartmentId === null || $currentApartmentId === '') && $currentRole === 'superadmin') {
    $currentApartmentId = first_apartment_id_uh($pdo);
}

$currentApartmentName = 'No Apartment Assigned';

if (!empty($currentApartmentId)) {
    try {
        $stmt = $pdo->prepare("SELECT apartment_name FROM apartments WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$currentApartmentId]);
        $apartment = $stmt->fetch();

        if ($apartment) {
            $currentApartmentName = $apartment['apartment_name'];
        } else {
            $currentApartmentName = 'Apartment ID ' . (int)$currentApartmentId;
        }
    } catch (Throwable $e) {
        $currentApartmentName = 'Apartment ID ' . (int)$currentApartmentId;
    }
}

$error = '';

if (empty($currentApartmentId)) {
    $error = 'This admin account is not assigned to any apartment.';
}

$search = trim($_GET['search'] ?? '');
$blockFilter = trim($_GET['block_no'] ?? '');
$floorFilter = trim($_GET['floor_no'] ?? '');
$unitStatusFilter = trim($_GET['unit_status'] ?? '');

$unitWhere = "WHERE un.apartment_id = ?";
$params = [(int)$currentApartmentId];

if ($search !== '') {
    $unitWhere .= "
        AND (
            un.block_no LIKE ?
            OR un.unit_no LIKE ?
            OR u.email LIKE ?
    ";

    $term = '%' . $search . '%';
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;

    if ($hasFullName) {
        $unitWhere .= " OR u.full_name LIKE ?";
        $params[] = $term;
    }

    if ($hasContact) {
        $unitWhere .= " OR u.contact_number LIKE ?";
        $params[] = $term;
    }

    $unitWhere .= ")";
}

if ($blockFilter !== '') {
    $unitWhere .= " AND un.block_no = ?";
    $params[] = $blockFilter;
}

if ($floorFilter !== '') {
    $unitWhere .= " AND un.floor_no = ?";
    $params[] = $floorFilter;
}

/*
    Logical unit list:
    The system may contain duplicate rows because older generate buttons were used before.
    This page groups by block/floor/unit so admin sees each real unit only once.
*/
$units = [];

if (!empty($currentApartmentId)) {
    $units = safe_rows_uh($pdo, "
        SELECT
            un.id,
            un.apartment_id,
            un.block_no,
            un.floor_no,
            un.unit_no,
            COUNT(DISTINCT u.id) AS resident_count,
            GROUP_CONCAT(
                DISTINCT CONCAT(
                    CAST(u.id AS CHAR),
                    '||',
                    {$residentNameExpr},
                    '||',
                    COALESCE(u.email, '-'),
                    '||',
                    {$residentContactExpr},
                    '||',
                    {$residentTypeExpr},
                    '||',
                    {$residentPhotoExpr}
                )
                ORDER BY {$residentOrderExpr}
                SEPARATOR '~~'
            ) AS resident_list
        FROM (
            SELECT
                MIN(id) AS id,
                apartment_id,
                block_no,
                floor_no,
                unit_no
            FROM units
            WHERE apartment_id = ?
            GROUP BY apartment_id, block_no, floor_no, unit_no
        ) un

        LEFT JOIN units ux
            ON ux.apartment_id = un.apartment_id
            AND ux.block_no = un.block_no
            AND ux.floor_no = un.floor_no
            AND ux.unit_no = un.unit_no

        LEFT JOIN resident_units ru
            ON ru.unit_id = ux.id
            AND ru.status = 'active'

        LEFT JOIN users u
            ON u.id = ru.resident_id
            AND u.role = 'resident'

        {$unitWhere}

        GROUP BY
            un.id,
            un.apartment_id,
            un.block_no,
            un.floor_no,
            un.unit_no

        ORDER BY
            un.block_no ASC,
            un.floor_no ASC,
            un.unit_no ASC

        LIMIT 1000
    ", array_merge([(int)$currentApartmentId], $params));

    if (in_array($unitStatusFilter, ['available', 'occupied'], true)) {
        $units = array_values(array_filter($units, function ($unit) use ($unitStatusFilter) {
            $residentCount = (int)($unit['resident_count'] ?? 0);

            if ($unitStatusFilter === 'available') {
                return $residentCount === 0;
            }

            return $residentCount > 0;
        }));
    }
}

$totalBlocks = 0;
$totalUnits = 0;
$assignedUnits = 0;
$emptyUnits = 0;
$totalResidents = 0;

if (!empty($currentApartmentId)) {
    $totalBlocks = safe_count_uh($pdo, "
        SELECT COUNT(DISTINCT block_no)
        FROM units
        WHERE apartment_id = ?
    ", [(int)$currentApartmentId]);

    $totalUnits = safe_count_uh($pdo, "
        SELECT COUNT(*)
        FROM (
            SELECT block_no, floor_no, unit_no
            FROM units
            WHERE apartment_id = ?
            GROUP BY block_no, floor_no, unit_no
        ) x
    ", [(int)$currentApartmentId]);

    $assignedUnits = safe_count_uh($pdo, "
        SELECT COUNT(*)
        FROM (
            SELECT ux.block_no, ux.floor_no, ux.unit_no
            FROM resident_units ru
            JOIN units ux ON ux.id = ru.unit_id
            WHERE ux.apartment_id = ?
            AND ru.status = 'active'
            GROUP BY ux.block_no, ux.floor_no, ux.unit_no
        ) x
    ", [(int)$currentApartmentId]);

    $emptyUnits = max(0, $totalUnits - $assignedUnits);

    if ($hasUserApartmentId) {
        $totalResidents = safe_count_uh($pdo, "
            SELECT COUNT(*)
            FROM users
            WHERE role = 'resident'
            AND status = 'active'
            AND apartment_id = ?
        ", [(int)$currentApartmentId]);
    } else {
        $totalResidents = safe_count_uh($pdo, "
            SELECT COUNT(DISTINCT u.id)
            FROM users u
            JOIN resident_units ru ON ru.resident_id = u.id AND ru.status = 'active'
            JOIN units un ON un.id = ru.unit_id
            WHERE u.role = 'resident'
            AND u.status = 'active'
            AND un.apartment_id = ?
        ", [(int)$currentApartmentId]);
    }
}

$blockOptions = !empty($currentApartmentId)
    ? safe_rows_uh($pdo, "
        SELECT DISTINCT block_no
        FROM units
        WHERE apartment_id = ?
        ORDER BY block_no ASC
    ", [(int)$currentApartmentId])
    : [];

$floorOptions = !empty($currentApartmentId)
    ? safe_rows_uh($pdo, "
        SELECT DISTINCT floor_no
        FROM units
        WHERE apartment_id = ?
        " . ($blockFilter !== '' ? "AND block_no = ?" : "") . "
        ORDER BY floor_no ASC
    ", $blockFilter !== '' ? [(int)$currentApartmentId, $blockFilter] : [(int)$currentApartmentId])
    : [];

$profileInitial = strtoupper(substr(trim($currentEmail ?: 'A'), 0, 1));
if ($profileInitial === '') {
    $profileInitial = 'A';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Unit / Household - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        :root {
            --primary: #dc2626;
            --primary-dark: #991b1b;
            --primary-soft: #fee2e2;
            --primary-soft-2: #fff1f2;
            --green: #16a34a;
            --blue: #2563eb;
            --text: #111827;
            --muted: #64748b;
            --border: #e5e7eb;
            --shadow: 0 18px 45px rgba(15, 23, 42, 0.08);
            --shadow-soft: 0 10px 25px rgba(15, 23, 42, 0.06);
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
                radial-gradient(circle at 85% 5%, rgba(220, 38, 38, 0.12), transparent 28%),
                linear-gradient(135deg, #fff7f7 0%, #f4f6fb 45%, #eef2f7 100%);
            color: var(--text);
        }

        a {
            color: inherit;
        }

        .dashboard-shell {
            display: grid;
            grid-template-columns: 260px 1fr;
            height: 100vh;
            min-height: 100vh;
            overflow: hidden;
        }

        .sidebar {
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(20px);
            border-right: 1px solid rgba(229, 231, 235, 0.9);
            padding: 22px 18px;
            height: 100vh;
            overflow: hidden;
            z-index: 20;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 22px;
            padding: 6px 8px;
        }

        .brand-icon {
            width: 44px;
            height: 44px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            display: grid;
            place-items: center;
            color: white;
            box-shadow: 0 14px 30px rgba(220, 38, 38, 0.28);
        }

        .brand-title {
            font-weight: 900;
            letter-spacing: -0.04em;
            font-size: 1.08rem;
            line-height: 1.1;
        }

        .brand-title span {
            color: var(--primary);
        }

        .brand-sub {
            font-size: .7rem;
            color: var(--muted);
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .08em;
            margin-top: 3px;
        }

        .tenant-card {
            background: #fff7f7;
            border: 1px solid #fecaca;
            border-radius: 20px;
            padding: 13px 14px;
            margin-bottom: 20px;
            display: flex;
            gap: 11px;
            align-items: center;
        }

        .tenant-icon {
            width: 38px;
            height: 38px;
            border-radius: 14px;
            background: var(--primary-soft);
            color: var(--primary);
            display: grid;
            place-items: center;
            flex: 0 0 auto;
        }

        .tenant-label {
            color: var(--muted);
            font-size: .64rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .07em;
            margin-bottom: 3px;
        }

        .tenant-name {
            font-size: .8rem;
            font-weight: 950;
            line-height: 1.28;
            color: #111827;
            word-break: break-word;
        }

        .side-section {
            margin: 20px 0 10px;
            color: #9ca3af;
            font-size: .68rem;
            text-transform: uppercase;
            letter-spacing: .1em;
            font-weight: 900;
            padding: 0 10px;
        }

        .side-nav {
            display: grid;
            gap: 6px;
        }

        .side-link {
            width: 100%;
            border: 0;
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 11px 12px;
            border-radius: 15px;
            text-decoration: none;
            color: #475569;
            font-size: .82rem;
            font-weight: 850;
            transition: .2s ease;
            background: transparent;
            cursor: pointer;
            text-align: left;
        }

        .side-link i {
            width: 18px;
            text-align: center;
            color: #94a3b8;
            transition: .2s ease;
        }

        .side-link:hover,
        .side-link.current {
            background: var(--primary-soft-2);
            color: var(--primary);
        }

        .side-link:hover i,
        .side-link.current i {
            color: var(--primary);
        }

        .side-link.logout {
            color: #991b1b;
            background: #fff1f2;
        }

        .side-parent {
            margin-top: 4px;
        }

        .side-link.parent {
            justify-content: space-between;
        }

        .side-link.parent .left {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .side-link.parent .chevron {
            font-size: .65rem;
            color: inherit;
            opacity: .72;
            transition: transform .2s ease;
        }

        .side-parent.open .side-link.parent {
            background: var(--primary-soft-2);
            color: var(--primary);
        }

        .side-parent.open .side-link.parent i {
            color: var(--primary);
        }

        .side-parent.open .side-link.parent .chevron {
            transform: rotate(180deg);
        }

        .submenu {
            margin: 0 0 0 30px;
            padding-left: 12px;
            border-left: 2px solid #fee2e2;
            display: grid;
            gap: 4px;
            max-height: 0;
            overflow: hidden;
            opacity: 0;
            transform: translateY(-4px);
            transition: max-height .25s ease, opacity .2s ease, transform .2s ease, margin .2s ease;
        }

        .side-parent.open .submenu {
            max-height: 260px;
            opacity: 1;
            transform: translateY(0);
            margin: 5px 0 8px 30px;
        }

        .submenu a {
            text-decoration: none;
            color: #64748b;
            font-size: .76rem;
            font-weight: 850;
            padding: 7px 8px;
            border-radius: 11px;
            transition: .2s ease;
        }

        .submenu a:hover,
        .submenu a.sub-active {
            background: #fff1f2;
            color: var(--primary);
        }

        .main {
            min-width: 0;
            height: 100vh;
            overflow: hidden;
            padding: 18px 28px 18px;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            min-height: 56px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 12px;
            flex: 0 0 auto;
        }

        .page-kicker {
            color: var(--primary);
            font-size: .72rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .1em;
            margin-bottom: 5px;
        }

        .page-title {
            font-size: 1.7rem;
            line-height: 1.08;
            font-weight: 950;
            letter-spacing: -0.06em;
        }

        .top-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .top-btn {
            height: 44px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 0 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            color: #475569;
            font-weight: 900;
            font-size: .8rem;
            box-shadow: var(--shadow-soft);
        }

        .top-btn.primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border-color: transparent;
        }

        .alert {
            padding: 11px 14px;
            border-radius: 16px;
            margin-bottom: 12px;
            font-weight: 850;
            line-height: 1.35;
            box-shadow: var(--shadow-soft);
            flex: 0 0 auto;
        }

        .alert.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 14px;
            flex: 0 0 auto;
        }

        .stat-card {
            background: rgba(255,255,255,.96);
            border: 1px solid rgba(229,231,235,.95);
            border-radius: 22px;
            padding: 16px 18px;
            min-height: 92px;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(220,38,38,.08), transparent 45%);
            opacity: .75;
        }

        .stat-value,
        .stat-label {
            position: relative;
            z-index: 2;
        }

        .stat-value {
            font-size: 1.55rem;
            line-height: 1;
            font-weight: 950;
            letter-spacing: -0.07em;
            margin-bottom: 10px;
        }

        .stat-label {
            font-size: .66rem;
            font-weight: 950;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .06em;
            line-height: 1.25;
        }

        .layout {
            flex: 1 1 auto;
            min-height: 0;
            overflow: hidden;
            display: grid;
            grid-template-columns: 1fr;
        }

        .panel {
            background: rgba(255,255,255,.96);
            border: 1px solid rgba(229,231,235,.95);
            border-radius: 24px;
            box-shadow: var(--shadow);
            overflow: hidden;
            min-height: 0;
        }

        .unit-list-panel {
            height: 100%;
            min-height: 0;
            display: flex;
            flex-direction: column;
        }

        .panel-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex: 0 0 auto;
        }

        .panel-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 950;
            letter-spacing: -0.03em;
            font-size: .98rem;
        }

        .panel-title i {
            color: var(--primary);
        }

        .panel-note {
            color: var(--muted);
            font-size: .78rem;
            line-height: 1.4;
            font-weight: 800;
            text-align: right;
        }

        .panel-body {
            padding: 18px 20px 14px;
            flex: 1 1 auto;
            min-height: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        label {
            display: block;
            font-size: .7rem;
            font-weight: 950;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 7px;
        }

        input, select {
            width: 100%;
            padding: 12px 13px;
            border: 1px solid var(--border);
            border-radius: 14px;
            font-weight: 850;
            outline: none;
            background: white;
        }

        input:focus, select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(220,38,38,.10);
        }

        .filter-form {
            display: grid;
            grid-template-columns: 1fr 130px 130px 140px auto auto;
            gap: 10px;
            margin-bottom: 16px;
            align-items: end;
            flex: 0 0 auto;
        }

        .btn {
            border: none;
            cursor: pointer;
            padding: 12px 15px;
            border-radius: 14px;
            font-weight: 950;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            font-size: .82rem;
            transition: .2s ease;
            white-space: nowrap;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 14px 25px rgba(220,38,38,.22);
        }

        .btn-light {
            background: white;
            color: #111827;
            border: 1px solid var(--border);
        }

        .unit-grid-wrap {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
            padding-right: 6px;
        }

        .unit-grid-wrap::-webkit-scrollbar {
            width: 8px;
        }

        .unit-grid-wrap::-webkit-scrollbar-thumb {
            background: #fecaca;
            border-radius: 999px;
        }

        .unit-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            padding-bottom: 4px;
        }

        .unit-card {
            background: #fbfdff;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 15px;
            min-height: 104px;
            transition: .2s ease;
            cursor: pointer;
        }

        .unit-card:hover {
            transform: translateY(-2px);
            border-color: rgba(220,38,38,.22);
            box-shadow: var(--shadow-soft);
        }

        .unit-card:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(220,38,38,.10);
        }

        .household-popup {
            text-align: left;
            display: grid;
            gap: 14px;
        }

        .household-swal-popup {
            border-radius: 26px !important;
            padding: 22px !important;
        }

        .household-swal-title {
            font-size: 1.55rem !important;
            font-weight: 950 !important;
            letter-spacing: -0.04em !important;
            color: #111827 !important;
        }

        .household-summary {
            background:
                radial-gradient(circle at 95% 0%, rgba(220,38,38,.10), transparent 34%),
                #fff7f7;
            border: 1px solid #fecaca;
            border-radius: 20px;
            padding: 16px 18px;
            display: grid;
            gap: 6px;
        }

        .household-summary strong {
            color: #111827;
            font-size: 1.02rem;
            font-weight: 950;
        }

        .household-summary span {
            color: #64748b;
            font-size: .82rem;
            font-weight: 850;
        }

        .household-resident {
            border: 1px solid #e5e7eb;
            border-radius: 22px;
            padding: 16px;
            background: #ffffff;
            display: grid;
            grid-template-columns: 76px 1fr auto;
            gap: 16px;
            align-items: center;
            text-decoration: none;
            color: inherit;
            transition: .2s ease;
            box-shadow: 0 10px 25px rgba(15,23,42,.05);
        }

        .household-resident:hover {
            border-color: #fecaca;
            background: #fff7f7;
            transform: translateY(-2px);
            box-shadow: 0 18px 35px rgba(220,38,38,.10);
        }

        .resident-popup-avatar {
            width: 76px;
            height: 76px;
            border-radius: 24px;
            background:
                radial-gradient(circle at 30% 25%, rgba(255,255,255,.72), transparent 24%),
                linear-gradient(135deg, #dc2626, #991b1b);
            color: white;
            display: grid;
            place-items: center;
            font-weight: 950;
            font-size: 1.8rem;
            overflow: hidden;
            box-shadow: 0 16px 32px rgba(220,38,38,.18);
        }

        .resident-popup-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .household-resident-name {
            font-size: 1.02rem;
            font-weight: 950;
            color: #111827;
            margin-bottom: 6px;
            letter-spacing: -0.02em;
        }

        .household-resident-line {
            color: #64748b;
            font-size: .8rem;
            font-weight: 850;
            line-height: 1.55;
        }

        .household-open {
            color: white;
            background: linear-gradient(135deg, #dc2626, #991b1b);
            font-weight: 950;
            font-size: .76rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            white-space: nowrap;
            padding: 10px 12px;
            border-radius: 14px;
            box-shadow: 0 12px 22px rgba(220,38,38,.18);
        }

        .household-empty {
            color: #64748b;
            font-weight: 850;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 18px;
            padding: 16px;
            line-height: 1.45;
        }

        @media (max-width: 640px) {
            .household-resident {
                grid-template-columns: 64px 1fr;
            }

            .resident-popup-avatar {
                width: 64px;
                height: 64px;
                border-radius: 20px;
            }

            .household-open {
                grid-column: 1 / -1;
                width: 100%;
            }
        }

        .household-empty {
            color: #64748b;
            font-weight: 850;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 16px;
            padding: 14px;
        }

        .unit-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 10px;
        }

        .unit-no {
            display: inline-flex;
            background: #111827;
            color: white;
            border: 2px solid #334155;
            padding: 7px 11px;
            border-radius: 11px;
            font-family: monospace;
            font-weight: 950;
            letter-spacing: .06em;
        }

        .badge {
            padding: 6px 10px;
            border-radius: 999px;
            font-size: .66rem;
            font-weight: 950;
            display: inline-flex;
            text-transform: uppercase;
            letter-spacing: .04em;
            white-space: nowrap;
        }

        .badge-available {
            background: #dcfce7;
            color: #166534;
        }

        .badge-assigned {
            background: #fee2e2;
            color: #991b1b;
        }

        .small {
            color: var(--muted);
            font-size: .78rem;
            margin-top: 5px;
            line-height: 1.45;
            font-weight: 750;
        }

        .resident-list {
            display: grid;
            gap: 8px;
            margin-top: 10px;
        }

        .resident-chip {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 9px 10px;
            font-size: .78rem;
            font-weight: 850;
            color: #111827;
        }

        .resident-chip span {
            display: block;
            color: var(--muted);
            font-size: .72rem;
            margin-top: 2px;
            font-weight: 800;
        }

        .empty {
            padding: 44px 22px;
            text-align: center;
            color: var(--muted);
            font-weight: 800;
        }

        .footer-note {
            flex: 0 0 auto;
            padding-top: 10px;
            color: var(--muted);
            font-size: .76rem;
            font-weight: 800;
        }

        @media (max-width: 1220px) {
            html,
            body {
                height: auto;
                overflow: auto;
            }

            .dashboard-shell {
                grid-template-columns: 1fr;
                height: auto;
                min-height: 100vh;
                overflow: visible;
            }

            .sidebar {
                height: auto;
                overflow: visible;
                border-right: 0;
                border-bottom: 1px solid var(--border);
            }

            .side-nav {
                grid-template-columns: repeat(2, 1fr);
            }

            .main {
                height: auto;
                min-height: 100vh;
                overflow: visible;
                padding: 22px 18px 50px;
            }

            .layout,
            .unit-list-panel,
            .panel-body {
                overflow: visible;
            }

            .unit-grid-wrap {
                overflow: visible;
            }
        }

        @media (max-width: 1080px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .unit-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 720px) {
            .topbar {
                flex-direction: column;
                align-items: flex-start;
            }

            .top-actions,
            .top-btn,
            .btn {
                width: 100%;
            }

            .top-btn {
                justify-content: center;
            }

            .stats-grid,
            .filter-form,
            .side-nav {
                grid-template-columns: 1fr;
            }

            .panel-header,
            .unit-top {
                flex-direction: column;
                align-items: flex-start;
            }

            .panel-note {
                text-align: left;
            }
        }
    </style>
</head>
<body>

<div class="dashboard-shell">
    <?php require_once __DIR__ . '/admin_sidebar.php'; ?>

    <main class="main">
        <div class="topbar">
            <div>
                <div class="page-kicker">Resident Management</div>
                <h1 class="page-title">Unit / Household</h1>
            </div>

            <div class="top-actions">
                <a href="admin_dashboard.php" class="top-btn primary">
                    <i class="fas fa-arrow-left"></i>
                    Dashboard
                </a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>

        <section class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= (int)$totalBlocks ?></div>
                <div class="stat-label">Blocks</div>
            </div>

            <div class="stat-card">
                <div class="stat-value"><?= (int)$totalUnits ?></div>
                <div class="stat-label">Total Units</div>
            </div>

            <div class="stat-card">
                <div class="stat-value" style="color:var(--green);"><?= (int)$assignedUnits ?></div>
                <div class="stat-label">Assigned Units</div>
            </div>

            <div class="stat-card">
                <div class="stat-value" style="color:var(--blue);"><?= (int)$emptyUnits ?></div>
                <div class="stat-label">Empty Units</div>
            </div>

            <div class="stat-card">
                <div class="stat-value" style="color:var(--green);"><?= (int)$totalResidents ?></div>
                <div class="stat-label">Active Residents</div>
            </div>
        </section>

        <section class="layout">
            <div class="panel unit-list-panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="fas fa-door-open"></i>
                        Unit List
                    </div>

                    <div class="panel-note">
                        View unit availability and household resident count.
                    </div>
                </div>

                <div class="panel-body">
                    <form method="GET" class="filter-form">
                        <div>
                            <label>Search</label>
                            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Unit, resident email, name">
                        </div>

                        <div>
                            <label>Block</label>
                            <select name="block_no" id="blockSelect">
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
                                    <option value="<?= e($f['floor_no']) ?>" <?= (string)$floorFilter === (string)$f['floor_no'] ? 'selected' : '' ?>>
                                        <?= e($f['floor_no']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label>Status</label>
                            <select name="unit_status">
                                <option value="">All</option>
                                <option value="available" <?= $unitStatusFilter === 'available' ? 'selected' : '' ?>>Available</option>
                                <option value="occupied" <?= $unitStatusFilter === 'occupied' ? 'selected' : '' ?>>Occupied</option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i>
                            Filter
                        </button>

                        <a href="admin_resident_apartment.php" class="btn btn-light">
                            Reset
                        </a>
                    </form>

                    <?php if (empty($units)): ?>
                        <div class="empty">No unit found.</div>
                    <?php else: ?>
                        <div class="unit-grid-wrap">
                            <div class="unit-grid">
                                <?php foreach ($units as $unit): ?>
                                    <?php
                                        $residentCount = (int)($unit['resident_count'] ?? 0);
                                        $assigned = $residentCount > 0;
                                        $residentItems = [];
                                        $residentPopupRows = [];

                                        if (!empty($unit['resident_list'])) {
                                            $residentItems = explode('~~', $unit['resident_list']);

                                            foreach ($residentItems as $residentInfo) {
                                                $parts = explode('||', $residentInfo);

                                                $residentPopupRows[] = [
                                                    'id' => (int)($parts[0] ?? 0),
                                                    'name' => $parts[1] ?? '-',
                                                    'email' => $parts[2] ?? '-',
                                                    'contact' => $parts[3] ?? '-',
                                                    'type' => $parts[4] ?? '-',
                                                    'photo' => $parts[5] ?? '',
                                                ];
                                            }
                                        }

                                        $popupUnitText = 'Block ' . $unit['block_no'] . ' / Floor ' . $unit['floor_no'] . ' / Unit ' . $unit['unit_no'];
                                        $residentJson = htmlspecialchars(
                                            json_encode($residentPopupRows, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        );
                                    ?>

                                    <div
                                        class="unit-card"
                                        tabindex="0"
                                        role="button"
                                        data-unit="<?= e($popupUnitText) ?>"
                                        data-status="<?= $assigned ? 'Occupied' : 'Available' ?>"
                                        data-resident-count="<?= (int)$residentCount ?>"
                                        data-residents="<?= $residentJson ?>"
                                    >
                                        <div class="unit-top">
                                            <div>
                                                <span class="unit-no"><?= e($unit['unit_no']) ?></span>
                                                <div class="small">
                                                    Block <?= e($unit['block_no']) ?> · Floor <?= e($unit['floor_no']) ?>
                                                </div>
                                            </div>

                                            <span class="badge <?= $assigned ? 'badge-assigned' : 'badge-available' ?>">
                                                <?= $assigned ? $residentCount . ' Resident(s)' : 'Available' ?>
                                            </span>
                                        </div>

                                        <?php if ($assigned): ?>
                                            <div class="small">
                                                Click to view residents and open profile.
                                            </div>
                                        <?php else: ?>
                                            <div class="small">
                                                This unit is not assigned to any resident yet.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="footer-note">
                            Showing maximum 1000 units. Use filters to narrow result. Click any unit to view household details.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const parents = document.querySelectorAll('.side-parent .side-link.parent');

    parents.forEach(function (button) {
        button.addEventListener('click', function () {
            const currentParent = button.closest('.side-parent');
            const isOpen = currentParent.classList.contains('open');

            document.querySelectorAll('.side-parent.open').forEach(function (item) {
                item.classList.remove('open');
            });

            if (!isOpen) {
                currentParent.classList.add('open');
            }
        });
    });

    document.querySelectorAll('.unit-card').forEach(function (card) {
        function openHouseholdPopup() {
            const unitText = card.dataset.unit || 'Unit';
            const status = card.dataset.status || '-';
            const residentCount = card.dataset.residentCount || '0';
            let residents = [];

            try {
                residents = JSON.parse(card.dataset.residents || '[]');
            } catch (error) {
                residents = [];
            }

            let residentHtml = '';

            if (residents.length > 0) {
                residentHtml = residents.map(function (resident, index) {
                    const residentId = resident.id || '';
                    if (!residentId || residentId === '0') {
                        return '';
                    }
                    const rawName = resident.name || '';
                    const rawEmail = resident.email || '';
                    const rawContact = resident.contact || '';
                    const rawType = resident.type || '';

                    const residentName = (rawName && !rawName.startsWith('Resident #'))
                        ? rawName
                        : (residentId ? `Resident Account #${residentId}` : 'Resident Account');
                    const residentEmail = (rawEmail && rawEmail !== '-')
                        ? rawEmail
                        : 'Open profile to view full details';
                    const residentContact = (rawContact && rawContact !== '-') ? rawContact : '—';
                    const residentType = (rawType && rawType !== '-') ? rawType : '—';

                    const initial = getInitial(residentName || rawEmail || 'R');
                    const profileUrl = residentId
                        ? `admin_residents_manage.php?resident_id=${encodeURIComponent(residentId)}&from=unit`
                        : 'admin_residents_manage.php?from=unit';
                    const avatarHtml = resident.photo
                        ? `<img src="${escapeAttribute(resident.photo)}" alt="">`
                        : escapeHtml(initial);

                    return `
                        <a class="household-resident" href="${profileUrl}">
                            <div class="resident-popup-avatar">
                                ${avatarHtml}
                            </div>

                            <div>
                                <div class="household-resident-name">
                                    ${index + 1}. ${escapeHtml(residentName)}
                                </div>
                                <div class="household-resident-line">
                                    Email: ${escapeHtml(residentEmail)}
                                </div>
                                <div class="household-resident-line">
                                    Contact: ${escapeHtml(residentContact)}
                                </div>
                                <div class="household-resident-line">
                                    Type: ${escapeHtml(residentType)}
                                </div>
                            </div>

                            <div class="household-open">
                                View Profile
                                <i class="fas fa-arrow-right"></i>
                            </div>
                        </a>
                    `;
                }).join('');
            } else {
                residentHtml = `
                    <div class="household-empty">
                        This unit is currently available. No resident is assigned to this unit yet.
                    </div>
                `;
            }

            Swal.fire({
                title: 'Household Details',
                html: `
                    <div class="household-popup">
                        <div class="household-summary">
                            <strong>${escapeHtml(unitText)}</strong>
                            <span>Status: ${escapeHtml(status)}</span>
                            <span>Resident count: ${escapeHtml(residentCount)}</span>
                        </div>
                        ${residentHtml}
                    </div>
                `,
                customClass: {
                    popup: 'household-swal-popup',
                    title: 'household-swal-title'
                },
                confirmButtonText: 'Close',
                confirmButtonColor: '#dc2626',
                width: 680
            });
        }

        card.addEventListener('click', openHouseholdPopup);

        card.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openHouseholdPopup();
            }
        });
    });

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function escapeAttribute(value) {
        return escapeHtml(value);
    }

    function getInitial(value) {
        const text = String(value || 'R').trim();
        return text ? text.charAt(0).toUpperCase() : 'R';
    }

    const blockSelect = document.getElementById('blockSelect');

    if (blockSelect) {
        blockSelect.addEventListener('change', function () {
            const floorSelect = document.querySelector('select[name="floor_no"]');

            if (floorSelect) {
                floorSelect.value = '';
            }
        });
    }
});
</script>

</body>
</html>
