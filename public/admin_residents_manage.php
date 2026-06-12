<?php
require_once '../core/security.php';

require_login(['admin', 'superadmin']);

$pdo = db();

$message = $_SESSION['flash_success'] ?? '';
$error = $_SESSION['flash_error'] ?? '';

unset($_SESSION['flash_success'], $_SESSION['flash_error']);

function has_column_mr(PDO $pdo, string $table, string $column): bool {
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

function safe_rows_mr(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function safe_count_mr(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function display_mr($value): string {
    return ($value !== null && $value !== '') ? (string)$value : '-';
}

function unit_label_mr($row): string {
    if (empty($row['unit_id'])) {
        return 'No unit assigned';
    }

    return 'Block ' . ($row['block_no'] ?? '-') .
        ' / Floor ' . ($row['floor_no'] ?? '-') .
        ' / Unit ' . ($row['unit_no'] ?? '-');
}

$hasFullName = has_column_mr($pdo, 'users', 'full_name');
$hasContactNumber = has_column_mr($pdo, 'users', 'contact_number');
$hasPhone = has_column_mr($pdo, 'users', 'phone');
$hasIdentityNumber = has_column_mr($pdo, 'users', 'identity_number');
$hasResidentType = has_column_mr($pdo, 'users', 'resident_type');
$hasStayStartDate = has_column_mr($pdo, 'users', 'stay_start_date');
$hasStayEndDate = has_column_mr($pdo, 'users', 'stay_end_date');
$hasVerificationNote = has_column_mr($pdo, 'users', 'verification_note');
$hasUserApartmentId = has_column_mr($pdo, 'users', 'apartment_id');
$hasUserCreatedAt = has_column_mr($pdo, 'users', 'created_at');

$contactColumn = $hasContactNumber ? 'contact_number' : ($hasPhone ? 'phone' : null);

$currentUserId = (int)($_SESSION['uid'] ?? 0);
$currentRole = $_SESSION['role'] ?? 'admin';
$currentEmail = $_SESSION['email'] ?? 'admin';
$currentApartmentId = $_SESSION['apartment_id'] ?? null;

if (($currentApartmentId === null || $currentApartmentId === '') && $currentUserId > 0 && $hasUserApartmentId && $currentRole !== 'superadmin') {
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
        $apartment = $stmt->fetch();

        if ($apartment) {
            $currentApartmentName = $apartment['apartment_name'];
        }
    } catch (Throwable $e) {
        $currentApartmentName = 'Apartment ID ' . (int)$currentApartmentId;
    }
}

function resident_guard_mr(string $currentRole, ?int $currentApartmentId, bool $hasUserApartmentId): array {
    if ($currentRole === 'superadmin') {
        return ['', []];
    }

    if (!$hasUserApartmentId || empty($currentApartmentId)) {
        return [' AND 1 = 0 ', []];
    }

    return [' AND u.apartment_id = ? ', [(int)$currentApartmentId]];
}

function get_resident_mr(PDO $pdo, int $residentId, string $currentRole, ?int $currentApartmentId, bool $hasUserApartmentId): array {
    [$guardSql, $guardParams] = resident_guard_mr($currentRole, $currentApartmentId, $hasUserApartmentId);

    $stmt = $pdo->prepare("
        SELECT *
        FROM users u
        WHERE u.id = ?
        AND u.role = 'resident'
        {$guardSql}
        LIMIT 1
    ");
    $stmt->execute(array_merge([$residentId], $guardParams));
    $resident = $stmt->fetch();

    if (!$resident) {
        throw new Exception('Resident not found or not under your apartment.');
    }

    return $resident;
}

function validate_unit_mr(PDO $pdo, int $unitId, string $currentRole, ?int $currentApartmentId): array {
    $stmt = $pdo->prepare("SELECT * FROM units WHERE id = ? LIMIT 1");
    $stmt->execute([$unitId]);
    $unit = $stmt->fetch();

    if (!$unit) {
        throw new Exception('Selected unit not found.');
    }

    if ($currentRole !== 'superadmin') {
        if (empty($currentApartmentId)) {
            throw new Exception('This admin account is not assigned to any apartment.');
        }

        if ((int)($unit['apartment_id'] ?? 0) !== (int)$currentApartmentId) {
            throw new Exception('You can only assign units from your own apartment.');
        }
    }

    return $unit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';

    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid security token. Please refresh the page.';
    } elseif (($_POST['confirm_save'] ?? '') !== 'yes') {
        $error = 'Action was not confirmed. Please try again and confirm the change.';
    } else {
        try {
            $residentId = (int)($_POST['resident_id'] ?? 0);

            if ($residentId <= 0) {
                throw new Exception('Invalid resident account.');
            }

            get_resident_mr($pdo, $residentId, $currentRole, $currentApartmentId ? (int)$currentApartmentId : null, $hasUserApartmentId);

            if ($action === 'update_profile') {
                $fullName = trim($_POST['full_name'] ?? '');
                $contact = trim($_POST['contact_number'] ?? '');
                $identityNumber = strtoupper(trim($_POST['identity_number'] ?? ''));
                $residentType = trim($_POST['resident_type'] ?? '');
                $stayStartDate = trim($_POST['stay_start_date'] ?? '');
                $stayEndDate = trim($_POST['stay_end_date'] ?? '');
                $verificationNote = trim($_POST['verification_note'] ?? '');

                if ($hasFullName && $fullName === '') {
                    throw new Exception('Please enter the resident full name.');
                }

                if ($contact !== '' && !preg_match('/^01[0-9]-?[0-9]{7,8}$/', $contact)) {
                    throw new Exception('Contact number format must be like 011-58606387 or 01158606387.');
                }

                if ($hasIdentityNumber && $identityNumber !== '' && !preg_match('/^(\d{6}-?\d{2}-?\d{4}|[A-Z0-9]{6,12})$/', $identityNumber)) {
                    throw new Exception('IC / Passport format must be like 990101-01-1234 or A12345678.');
                }

                if ($hasIdentityNumber && $identityNumber !== '') {
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE identity_number = ? AND id <> ? LIMIT 1");
                    $stmt->execute([$identityNumber, $residentId]);

                    if ($stmt->fetch()) {
                        throw new Exception('This IC / Passport number is already used by another account.');
                    }
                }

                $isTenantType = ($residentType === 'Tenant');

                if (!$isTenantType) {
                    $stayStartDate = '';
                    $stayEndDate = '';
                }

                if ($isTenantType && $hasStayStartDate && $stayStartDate === '') {
                    throw new Exception('Tenant / renter must have a stay start date.');
                }

                if ($isTenantType && $hasStayEndDate && $stayEndDate === '') {
                    throw new Exception('Tenant / renter must have a contract end date.');
                }

                if ($isTenantType && $hasStayEndDate && $stayEndDate !== '' && $hasStayStartDate && $stayStartDate !== '' && $stayEndDate < $stayStartDate) {
                    throw new Exception('Stay end date cannot be earlier than stay start date.');
                }

                $sets = [];
                $params = [];

                if ($hasFullName) {
                    $sets[] = 'full_name = ?';
                    $params[] = $fullName;
                }

                if ($contactColumn !== null) {
                    $sets[] = "{$contactColumn} = ?";
                    $params[] = $contact ?: null;
                }

                if ($hasIdentityNumber) {
                    $sets[] = "identity_number = ?";
                    $params[] = $identityNumber ?: null;
                }

                if ($hasResidentType) {
                    $sets[] = "resident_type = ?";
                    $params[] = $residentType ?: null;
                }

                if ($hasStayStartDate) {
                    $sets[] = "stay_start_date = ?";
                    $params[] = $stayStartDate ?: null;
                }

                if ($hasStayEndDate) {
                    $sets[] = "stay_end_date = ?";
                    $params[] = $stayEndDate ?: null;
                }

                if ($hasVerificationNote) {
                    $sets[] = "verification_note = ?";
                    $params[] = $verificationNote ?: null;
                }

                if (!$sets) {
                    throw new Exception('No editable fields found.');
                }

                $params[] = $residentId;

                $stmt = $pdo->prepare("
                    UPDATE users
                    SET " . implode(', ', $sets) . "
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmt->execute($params);

                if (function_exists('log_audit')) {
                    log_audit('RESIDENT_PROFILE_UPDATED', 'Admin updated resident profile for user ID ' . $residentId);
                }

                $message = 'Resident profile updated successfully.';
            } elseif ($action === 'change_unit') {
                $unitId = (int)($_POST['unit_id'] ?? 0);

                if ($unitId <= 0) {
                    throw new Exception('Please select a unit.');
                }

                $unit = validate_unit_mr($pdo, $unitId, $currentRole, $currentApartmentId ? (int)$currentApartmentId : null);

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    UPDATE resident_units
                    SET status = 'inactive'
                    WHERE resident_id = ?
                    AND status = 'active'
                ");
                $stmt->execute([$residentId]);

                $stmt = $pdo->prepare("
                    INSERT INTO resident_units
                    (resident_id, unit_id, status, created_at)
                    VALUES
                    (?, ?, 'active', NOW())
                ");
                $stmt->execute([$residentId, $unitId]);

                if ($hasUserApartmentId) {
                    $stmt = $pdo->prepare("UPDATE users SET apartment_id = ? WHERE id = ? LIMIT 1");
                    $stmt->execute([(int)($unit['apartment_id'] ?? $currentApartmentId), $residentId]);
                }

                if (function_exists('log_audit')) {
                    log_audit('RESIDENT_UNIT_CHANGED', 'Admin changed resident unit for user ID ' . $residentId);
                }

                $pdo->commit();
                $message = 'Resident unit updated successfully.';
            } elseif ($action === 'remove_unit') {
                $stmt = $pdo->prepare("
                    UPDATE resident_units
                    SET status = 'inactive'
                    WHERE resident_id = ?
                    AND status = 'active'
                ");
                $stmt->execute([$residentId]);

                if (function_exists('log_audit')) {
                    log_audit('RESIDENT_UNIT_REMOVED', 'Admin removed active unit for user ID ' . $residentId);
                }

                $message = 'Resident unit removed successfully.';
            } elseif ($action === 'set_status') {
                $newStatus = $_POST['new_status'] ?? '';

                if (!in_array($newStatus, ['active', 'inactive'], true)) {
                    throw new Exception('Invalid status.');
                }

                $stmt = $pdo->prepare("
                    UPDATE users
                    SET status = ?
                    WHERE id = ?
                    AND role = 'resident'
                    LIMIT 1
                ");
                $stmt->execute([$newStatus, $residentId]);

                if (function_exists('log_audit')) {
                    log_audit('RESIDENT_STATUS_CHANGED', 'Admin changed resident status to ' . $newStatus . ' for user ID ' . $residentId);
                }

                $message = 'Resident status updated successfully.';
            } elseif ($action === 'delete_resident') {
                $bookingCount = safe_count_mr($pdo, "SELECT COUNT(*) FROM bookings WHERE resident_id = ?", [$residentId]);
                $vehicleCount = safe_count_mr($pdo, "SELECT COUNT(*) FROM resident_vehicles WHERE resident_id = ?", [$residentId]);

                if ($bookingCount > 0 || $vehicleCount > 0) {
                    throw new Exception('This resident has booking or vehicle records. Please set inactive instead of deleting.');
                }

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("DELETE FROM resident_units WHERE resident_id = ?");
                $stmt->execute([$residentId]);

                $stmt = $pdo->prepare("
                    DELETE FROM users
                    WHERE id = ?
                    AND role = 'resident'
                    LIMIT 1
                ");
                $stmt->execute([$residentId]);

                if (function_exists('log_audit')) {
                    log_audit('RESIDENT_ACCOUNT_DELETED', 'Admin deleted resident account user ID ' . $residentId);
                }

                $pdo->commit();

                $message = 'Resident account deleted successfully.';
            } else {
                throw new Exception('Invalid action.');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($message !== '') {
        $_SESSION['flash_success'] = $message;
    }

    if ($error !== '') {
        $_SESSION['flash_error'] = $error;
    }

    header('Location: admin_residents_manage.php');
    exit;
}

if ($currentRole === 'superadmin') {
    $unitRows = safe_rows_mr($pdo, "
        SELECT
            MIN(un.id) AS id,
            un.block_no,
            un.floor_no,
            un.unit_no,
            un.apartment_id,
            a.apartment_name
        FROM units un
        LEFT JOIN apartments a ON a.id = un.apartment_id
        GROUP BY un.apartment_id, a.apartment_name, un.block_no, un.floor_no, un.unit_no
        ORDER BY a.apartment_name ASC, un.block_no ASC, un.floor_no ASC, un.unit_no ASC
        LIMIT 2000
    ");
} elseif (!empty($currentApartmentId)) {
    $unitRows = safe_rows_mr($pdo, "
        SELECT
            MIN(un.id) AS id,
            un.block_no,
            un.floor_no,
            un.unit_no,
            un.apartment_id,
            NULL AS apartment_name
        FROM units un
        WHERE un.apartment_id = ?
        GROUP BY un.apartment_id, un.block_no, un.floor_no, un.unit_no
        ORDER BY un.block_no ASC, un.floor_no ASC, un.unit_no ASC
        LIMIT 2000
    ", [(int)$currentApartmentId]);
} else {
    $unitRows = [];
}

$unitOptionsForJs = array_map(function ($unit) {
    return [
        'id' => (int)($unit['id'] ?? 0),
        'block_no' => (string)($unit['block_no'] ?? ''),
        'floor_no' => (string)($unit['floor_no'] ?? ''),
        'unit_no' => (string)($unit['unit_no'] ?? ''),
        'apartment_id' => (int)($unit['apartment_id'] ?? 0),
        'apartment_name' => (string)($unit['apartment_name'] ?? '')
    ];
}, $unitRows);

$blockOptions = array_values(array_unique(array_filter(array_map(function ($unit) {
    return (string)($unit['block_no'] ?? '');
}, $unitRows))));

usort($blockOptions, function ($a, $b) {
    return strnatcasecmp($a, $b);
});

$floorOptions = array_values(array_unique(array_filter(array_map(function ($unit) {
    return (string)($unit['floor_no'] ?? '');
}, $unitRows))));

usort($floorOptions, function ($a, $b) {
    return strnatcasecmp($a, $b);
});

$search = trim($_GET['search'] ?? '');
$block = trim($_GET['block'] ?? '');
$floor = trim($_GET['floor'] ?? '');
$status = $_GET['status'] ?? '';

if ($block !== '' && !in_array($block, $blockOptions, true)) {
    $block = '';
}

if ($floor !== '' && !in_array($floor, $floorOptions, true)) {
    $floor = '';
}
$profileResidentId = (int)($_GET['resident_id'] ?? 0);
$returnFromUnit = (($_GET['from'] ?? '') === 'unit');
$returnFromVehicles = (($_GET['from'] ?? '') === 'vehicles');

if (!in_array($status, ['', 'active', 'inactive', 'rejected'], true)) {
    $status = '';
}

[$guardSql, $guardParams] = resident_guard_mr($currentRole, $currentApartmentId ? (int)$currentApartmentId : null, $hasUserApartmentId);

$where = " WHERE u.role = 'resident' " . $guardSql;
$params = $guardParams;

if ($status !== '') {
    $where .= " AND u.status = ? ";
    $params[] = $status;
}

if ($block !== '') {
    $where .= " AND un.block_no = ? ";
    $params[] = $block;
}

if ($floor !== '') {
    $where .= " AND un.floor_no = ? ";
    $params[] = $floor;
}

if ($profileResidentId > 0) {
    $where .= " AND u.id = ? ";
    $params[] = $profileResidentId;
}

if ($search !== '') {
    $searchLike = '%' . $search . '%';
    $searchParts = ["u.email LIKE ?"];
    $params[] = $searchLike;

    if ($hasFullName) {
        $searchParts[] = "u.full_name LIKE ?";
        $params[] = $searchLike;
    }

    if ($contactColumn !== null) {
        $searchParts[] = "u.{$contactColumn} LIKE ?";
        $params[] = $searchLike;
    }

    if ($hasIdentityNumber) {
        $searchParts[] = "u.identity_number LIKE ?";
        $params[] = $searchLike;
    }

    $searchParts[] = "un.block_no LIKE ?";
    $params[] = $searchLike;

    $searchParts[] = "un.unit_no LIKE ?";
    $params[] = $searchLike;

    $where .= " AND (" . implode(" OR ", $searchParts) . ") ";
}

$nameSelect = $hasFullName ? "u.full_name" : "NULL AS full_name";
$contactSelect = $contactColumn !== null ? "u.{$contactColumn} AS contact_number" : "NULL AS contact_number";
$identitySelect = $hasIdentityNumber ? "u.identity_number" : "NULL AS identity_number";
$typeSelect = $hasResidentType ? "u.resident_type" : "NULL AS resident_type";
$startSelect = $hasStayStartDate ? "u.stay_start_date" : "NULL AS stay_start_date";
$endSelect = $hasStayEndDate ? "u.stay_end_date" : "NULL AS stay_end_date";
$noteSelect = $hasVerificationNote ? "u.verification_note" : "NULL AS verification_note";
$createdSelect = $hasUserCreatedAt ? "u.created_at" : "NULL AS created_at";
$apartmentSelect = $hasUserApartmentId ? "u.apartment_id" : "NULL AS apartment_id";

$residents = safe_rows_mr($pdo, "
    SELECT
        u.id,
        {$nameSelect},
        u.email,
        {$contactSelect},
        {$identitySelect},
        {$typeSelect},
        {$startSelect},
        {$endSelect},
        {$noteSelect},
        {$createdSelect},
        {$apartmentSelect},
        u.status,
        ru.unit_id,
        un.block_no,
        un.floor_no,
        un.unit_no,
        a.apartment_name,
        COUNT(DISTINCT rv.id) AS vehicle_count,
        COUNT(DISTINCT b.id) AS booking_count
    FROM users u
    LEFT JOIN (
        SELECT
            resident_id,
            MIN(unit_id) AS unit_id
        FROM resident_units
        WHERE status = 'active'
        GROUP BY resident_id
    ) ru
        ON ru.resident_id = u.id
    LEFT JOIN units un
        ON un.id = ru.unit_id
    LEFT JOIN apartments a
        ON a.id = u.apartment_id
    LEFT JOIN resident_vehicles rv
        ON rv.resident_id = u.id
    LEFT JOIN bookings b
        ON b.resident_id = u.id
    {$where}
    GROUP BY
        u.id,
        u.email,
        u.status,
        ru.unit_id,
        un.block_no,
        un.floor_no,
        un.unit_no,
        a.apartment_name
    ORDER BY u.id DESC
    LIMIT 500
", $params);

$totalResidents = safe_count_mr($pdo, "SELECT COUNT(*) FROM users u WHERE u.role = 'resident' {$guardSql}", $guardParams);
$activeResidents = safe_count_mr($pdo, "SELECT COUNT(*) FROM users u WHERE u.role = 'resident' AND u.status = 'active' {$guardSql}", $guardParams);
$assignedResidents = safe_count_mr($pdo, "
    SELECT COUNT(DISTINCT u.id)
    FROM users u
    INNER JOIN (
        SELECT resident_id, MIN(unit_id) AS unit_id
        FROM resident_units
        WHERE status = 'active'
        GROUP BY resident_id
    ) ru
        ON ru.resident_id = u.id
    WHERE u.role = 'resident'
    {$guardSql}
", $guardParams);

$profileInitial = strtoupper(substr(trim($currentEmail ?: 'A'), 0, 1));
if ($profileInitial === '') {
    $profileInitial = 'A';
}

$firstResident = $residents[0] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Residents - <?= e(APP_NAME) ?></title>
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
            padding: 20px 18px;
            position: sticky;
            top: 0;
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
            padding: 18px 30px 18px;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            min-height: 58px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 12px;
            flex: 0 0 auto;
        }

        .page-kicker {
            color: var(--primary);
            font-size: .72rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .1em;
            margin-bottom: 6px;
        }

        .page-title {
            font-size: 1.65rem;
            line-height: 1.05;
            font-weight: 950;
            letter-spacing: -0.06em;
        }

        .page-sub {
            color: var(--muted);
            margin-top: 5px;
            font-size: .82rem;
            font-weight: 750;
            line-height: 1.35;
            max-width: 760px;
        }

        .top-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
            min-width: 180px;
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

        .top-btn.back-unit {
            background: #ffffff;
            color: var(--primary);
            border-color: #fecaca;
        }

        .top-btn.back-unit:hover {
            background: #fff1f2;
        }

        .profile-menu {
            position: relative;
        }

        .profile-trigger {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            border: 2px solid #fecaca;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            display: grid;
            place-items: center;
            font-size: .9rem;
            font-weight: 950;
            cursor: pointer;
            box-shadow: 0 12px 26px rgba(220, 38, 38, 0.22);
        }

        .profile-dropdown {
            position: absolute;
            right: 0;
            top: 54px;
            width: 270px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 22px;
            box-shadow: 0 22px 55px rgba(15, 23, 42, .16);
            padding: 14px;
            z-index: 50;
            display: none;
        }

        .profile-menu.open .profile-dropdown {
            display: block;
        }

        .profile-email {
            font-size: .82rem;
            font-weight: 950;
            color: #111827;
            word-break: break-word;
        }

        .profile-role {
            margin-top: 3px;
            color: var(--muted);
            font-size: .68rem;
            font-weight: 900;
            text-transform: uppercase;
        }

        .profile-action {
            margin-top: 10px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 11px 12px;
            border-radius: 14px;
            background: #fff1f2;
            color: #991b1b;
            font-size: .78rem;
            font-weight: 950;
        }

        .alert {
            padding: 11px 14px;
            border-radius: 16px;
            margin-bottom: 12px;
            font-weight: 850;
            line-height: 1.25;
            box-shadow: var(--shadow-soft);
            flex: 0 0 auto;
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

        .mini-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .mini-stat {
            background: rgba(255,255,255,.96);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 14px;
            box-shadow: var(--shadow-soft);
        }

        .mini-stat-label {
            color: var(--muted);
            font-size: .64rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 6px;
        }

        .mini-stat-value {
            font-size: 1.35rem;
            font-weight: 950;
        }

        .manage-layout {
            display: grid;
            grid-template-columns: minmax(540px, 1fr) 460px;
            gap: 18px;
            align-items: stretch;
            flex: 1 1 auto;
            min-height: 0;
            overflow: hidden;
        }

        .users-panel,
        .info-panel {
            background: rgba(255,255,255,.97);
            border: 1px solid rgba(229,231,235,.95);
            border-radius: 18px;
            box-shadow: 0 14px 34px rgba(15, 23, 42, 0.07);
            overflow: hidden;
            position: relative;
            height: 100%;
            max-height: 100%;
            min-width: 0;
        }

        .panel-head {
            min-height: 54px;
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex: 0 0 auto;
        }

        .panel-title {
            font-weight: 950;
            display: flex;
            gap: 9px;
            align-items: center;
        }

        .panel-title i {
            color: var(--primary);
        }

        .users-panel {
            height: 100%;
            min-height: 0;
            display: flex;
            flex-direction: column;
        }

        .users-panel .panel-head,
        .info-panel .panel-head {
            flex: 0 0 auto;
        }

        .filter-form {
            display: flex;
            gap: 8px;
            align-items: center;
            flex: 1;
            justify-content: flex-end;
        }

        .filter-form input,
        .filter-form select {
            height: 38px;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 0 12px;
            font-weight: 850;
            outline: none;
            background: white;
        }

        .filter-form input {
            width: 240px;
        }

        .filter-form select {
            width: 132px;
            appearance: none;
            background-image:
                linear-gradient(45deg, transparent 50%, #64748b 50%),
                linear-gradient(135deg, #64748b 50%, transparent 50%);
            background-position:
                calc(100% - 18px) 16px,
                calc(100% - 13px) 16px;
            background-size: 5px 5px, 5px 5px;
            background-repeat: no-repeat;
            padding-right: 34px;
        }

        .icon-btn {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: white;
            cursor: pointer;
            display: grid;
            place-items: center;
            color: #64748b;
            text-decoration: none;
        }

        .icon-btn.primary {
            background: var(--primary);
            color: white;
            border-color: transparent;
        }

        .users-table-wrap {
            flex: 1 1 auto;
            min-height: 0;
            height: auto;
            max-height: none;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .users-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            min-width: 540px;
        }

        .users-table th:nth-child(1),
        .users-table td:nth-child(1) {
            width: 28%;
        }

        .users-table th:nth-child(2),
        .users-table td:nth-child(2) {
            width: 24%;
        }

        .users-table th:nth-child(3),
        .users-table td:nth-child(3) {
            width: 34%;
        }

        .users-table th:nth-child(4),
        .users-table td:nth-child(4) {
            width: 14%;
        }

        .users-table td {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .users-table th {
            position: sticky;
            top: 0;
            background: #f8fafc;
            z-index: 1;
            color: #64748b;
            font-size: .64rem;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: .06em;
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
        }

        .users-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            font-size: .76rem;
            font-weight: 800;
        }

        .resident-row {
            cursor: pointer;
            transition: .18s ease;
        }

        .resident-row:hover,
        .resident-row.selected {
            background: #fff1f2;
        }

        .resident-row.selected {
            box-shadow: inset 4px 0 0 var(--primary);
        }

        .name-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .avatar-sm {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: var(--primary);
            display: grid;
            place-items: center;
            font-size: .8rem;
            font-weight: 950;
            flex: 0 0 auto;
        }

        .name-main {
            font-weight: 950;
            color: #111827;
        }

        .name-sub {
            margin-top: 2px;
            color: var(--muted);
            font-size: .7rem;
        }

        .status-pill {
            border-radius: 999px;
            padding: 6px 9px;
            font-size: .65rem;
            font-weight: 950;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .status-active {
            background: #dcfce7;
            color: #166534;
        }

        .status-inactive,
        .status-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .info-body {
            padding: 22px 18px;
            height: calc(100% - 54px);
            max-height: none;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
        }

        .header-action-area {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            margin: -4px 0 10px;
            position: relative;
            z-index: 8;
        }

        .header-action-row {
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .header-mini-btn {
            width: 32px;
            height: 32px;
            border-radius: 999px;
            border: 1px solid #fecaca;
            background: white;
            color: var(--primary);
            display: grid;
            place-items: center;
            cursor: pointer;
            box-shadow: 0 8px 18px rgba(15, 23, 42, .08);
        }

        .header-mini-btn:hover {
            background: #fff1f2;
        }

        .header-more-menu {
            position: relative;
        }

        .header-more-menu.open .more-dropdown {
            display: grid;
            gap: 3px;
        }

        .profile-header-card {
            position: relative;
            text-align: center;
            padding: 6px 0 18px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 18px;
        }

        .profile-avatar-wrap {
            width: 132px;
            height: 132px;
            margin: 0 auto 14px;
            position: relative;
        }
.more-dropdown {
            position: absolute;
            right: 0;
            top: 42px;
            width: 230px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: 0 18px 45px rgba(15,23,42,.16);
            padding: 8px;
            display: none;
            text-align: left;
        }
.more-item {
            width: 100%;
            border: 0;
            background: transparent;
            border-radius: 12px;
            padding: 10px 11px;
            display: flex;
            align-items: center;
            gap: 9px;
            color: #475569;
            font-size: .76rem;
            font-weight: 900;
            text-decoration: none;
            cursor: pointer;
        }

        .more-item:hover {
            background: #f8fafc;
            color: var(--primary);
        }

        .more-item.warning {
            color: #c2410c;
        }

        .more-item.danger {
            color: #991b1b;
        }

        .unit-mini-title {
            margin: 14px 0 8px;
            padding-top: 12px;
            border-top: 1px solid var(--border);
            color: #64748b;
            font-size: .68rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .06em;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .unit-mini-title i {
            color: var(--primary);
        }

        .inline-unit-form {
            margin-top: 2px;
        }

        .profile-photo {
            width: 132px;
            height: 132px;
            margin: 0;
            border-radius: 34px;
            background:
                radial-gradient(circle at 30% 25%, rgba(255,255,255,.75), transparent 22%),
                linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            display: grid;
            place-items: center;
            font-size: 3rem;
            font-weight: 950;
            box-shadow: 0 22px 42px rgba(220,38,38,.22);
        }

        .info-name {
            text-align: center;
            font-size: 1.18rem;
            font-weight: 950;
            margin-bottom: 4px;
        }

        .info-email {
            text-align: center;
            color: var(--muted);
            font-size: .84rem;
            font-weight: 800;
            margin-bottom: 18px;
            word-break: break-word;
        }

        .info-line {
            display: grid;
            grid-template-columns: 28px 1fr;
            gap: 10px;
            color: #475569;
            font-size: .86rem;
            font-weight: 850;
            line-height: 1.45;
            padding: 12px 0;
            border-top: 1px solid #f1f5f9;
        }

        .info-line i {
            color: #94a3b8;
            text-align: center;
            margin-top: 3px;
            font-size: .95rem;
        }

        .right-form-section {
            display: none;
            padding-top: 2px;
        }

        .info-panel.mode-edit .info-view-lines {
            display: none;
        }

        .info-panel.mode-edit .edit-section {
            display: block;
        }
.info-content-area {
            flex: 1;
            min-height: 0;
            display: flex;
            flex-direction: column;
        }

        .info-view-lines {
            flex: 1;
        }

        

        

        

        

        

        

        

        

        

        

        

        .section-label {
            color: #64748b;
            font-size: .66rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 8px;
        }

        .right-form-section input,
        .right-form-section select,
        .right-form-section textarea {
            width: 100%;
            min-width: 0;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 10px 11px;
            font-size: .82rem;
            font-weight: 850;
            margin-bottom: 9px;
            outline: none;
        }

        .right-form-section textarea {
            min-height: 68px;
            resize: none;
        }

        .date-grid,
        .unit-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            min-width: 0;
        }

        .tenant-date-wrap {
            display: none;
        }

        .tenant-date-wrap.show {
            display: block;
        }

        .tenant-date-label {
            font-size: .66rem;
            font-weight: 950;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .06em;
            margin: 2px 0 7px;
        }

        .unit-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .date-grid > *,
        .unit-grid > * {
            min-width: 0;
        }

        .btn {
            border: none;
            cursor: pointer;
            padding: 9px 10px;
            border-radius: 12px;
            font-weight: 950;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            text-decoration: none;
            font-size: .74rem;
            transition: .2s ease;
            white-space: nowrap;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 12px 22px rgba(220,38,38,.18);
        }

        .btn-light {
            background: white;
            color: #111827;
            border: 1px solid var(--border);
        }

        .btn-warning {
            background: #fff7ed;
            color: #c2410c;
            border: 1px solid #fed7aa;
        }

        .btn-danger {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .button-grid {
            display: grid;
            gap: 8px;
        }

        .empty-state {
            padding: 45px 20px;
            text-align: center;
            color: var(--muted);
            font-weight: 850;
        }

        @media (max-width: 1250px) {
            html,
            body {
                height: auto;
                overflow: auto;
            }

            .dashboard-shell {
                height: auto;
                min-height: 100vh;
                overflow: visible;
            }

            .manage-layout {
                grid-template-columns: 1fr;
                overflow: visible;
            }

            .users-table-wrap {
                height: auto;
                max-height: none;
            }

            .info-panel {
                position: relative;
                top: auto;
                height: auto;
                max-height: none;
            }

            .info-body {
                height: auto;
                max-height: none;
                overflow: visible;
            }
        }

        @media (max-width: 1100px) {
            .dashboard-shell {
                grid-template-columns: 1fr;
            }

            .sidebar {
                position: relative;
                height: auto;
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
        }

        @media (max-width: 760px) {
            .topbar {
                flex-direction: column;
                align-items: flex-start;
            }

            .top-actions,
            .top-btn,
            .filter-form,
            .filter-form input,
            .filter-form select,
            .btn {
                width: 100%;
            }

            .filter-form {
                display: grid;
                grid-template-columns: 1fr;
            }

            .panel-head {
                flex-direction: column;
                align-items: stretch;
            }

            .mini-stats,
            .side-nav,
            .date-grid,
            .unit-grid {
                grid-template-columns: 1fr;
            }
        }
    
        /* Keep page fixed, scroll only inside the resident table */
        html,
        body {
            height: 100%;
            overflow: hidden;
        }

        .dashboard-shell,
        .main,
        .manage-layout {
            overflow: hidden;
        }

        .users-panel {
            height: 100%;
            min-height: 0;
            display: flex;
            flex-direction: column;
        }

        .users-panel .panel-head {
            flex: 0 0 auto;
        }

        .users-table-wrap {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .users-table thead th {
            position: sticky;
            top: 0;
            z-index: 3;
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
                <h1 class="page-title">Manage Residents</h1>
                <p class="page-sub">
                    Clean resident table with quick profile view and safe management actions.
                </p>
            </div>

            <div class="top-actions">
                <?php if ($returnFromUnit): ?>
                    <a href="admin_resident_apartment.php" class="top-btn back-unit">
                        <i class="fas fa-arrow-left"></i>
                        Unit / Household
                    </a>
                <?php endif; ?>

                <?php if ($returnFromVehicles): ?>
                    <a href="admin_resident_vehicles.php" class="top-btn back-unit">
                        <i class="fas fa-arrow-left"></i>
                        Resident Vehicles
                    </a>
                <?php endif; ?>

                <a href="admin_dashboard.php" class="top-btn primary">
                    <i class="fas fa-arrow-left"></i>
                    Dashboard
                </a>

                <div class="profile-menu" id="profileMenu">
                    <button type="button" class="profile-trigger" id="profileTrigger" title="Admin Profile">
                        <?= e($profileInitial) ?>
                    </button>

                    <div class="profile-dropdown">
                        <div class="profile-email"><?= e($currentEmail) ?></div>
                        <div class="profile-role"><?= e($currentRole) ?></div>

                        <a href="admin_dashboard.php" class="profile-action">
                            <i class="fas fa-user-shield"></i>
                            View Admin Profile
                        </a>

                        <a href="../core/logout.php" class="profile-action">
                            <i class="fas fa-right-from-bracket"></i>
                            Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert success"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>
<section class="manage-layout">
            <div class="users-panel">
                <div class="panel-head">
                    <div class="panel-title">
                        <i class="fas fa-users"></i>
                        Residents
                    </div>

                    <form method="GET" class="filter-form">
                        <input
                            type="text"
                            name="search"
                            placeholder="Search resident..."
                            value="<?= e($search) ?>"
                        >

                        <select name="block" onchange="this.form.submit()">
                            <option value="">All Blocks</option>
                            <?php foreach ($blockOptions as $blockOption): ?>
                                <option value="<?= e($blockOption) ?>" <?= $block === $blockOption ? 'selected' : '' ?>>
                                    Block <?= e($blockOption) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select name="floor" onchange="this.form.submit()">
                            <option value="">All Floors</option>
                            <?php foreach ($floorOptions as $floorOption): ?>
                                <option value="<?= e($floorOption) ?>" <?= $floor === $floorOption ? 'selected' : '' ?>>
                                    Floor <?= e($floorOption) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit" class="icon-btn primary" title="Search">
                            <i class="fas fa-magnifying-glass"></i>
                        </button>

                        <a href="admin_residents_manage.php" class="icon-btn" title="Reset">
                            <i class="fas fa-rotate-left"></i>
                        </a>
                    </form>
                </div>

                <?php if (!$residents): ?>
                    <div class="empty-state">
                        <i class="fas fa-user-slash" style="font-size:2rem;color:#cbd5e1;margin-bottom:10px;"></i>
                        <div>No resident found.</div>
                    </div>
                <?php else: ?>
                    <div class="users-table-wrap">
                        <table class="users-table">
                            <thead>
                                <tr>
                                    <th>Full Name</th>
                                    <th>Email</th>
                                    <th>Unit</th>
                                    <th>Type</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($residents as $index => $resident): ?>
                                    <?php
                                        $residentName = $resident['full_name'] ?: $resident['email'];
                                        $unitLabel = unit_label_mr($resident);
                                        $residentStatus = $resident['status'] ?: 'inactive';
                                        $createdText = $resident['created_at'] ? date('d M Y, g:i A', strtotime($resident['created_at'])) : '-';
                                        $initial = strtoupper(substr(trim($residentName), 0, 1));
                                        if ($initial === '') {
                                            $initial = 'R';
                                        }

                                        $payload = [
                                            'id' => (int)$resident['id'],
                                            'name' => $residentName,
                                            'email' => $resident['email'],
                                            'contact' => display_mr($resident['contact_number']),
                                            'contactRaw' => (string)($resident['contact_number'] ?? ''),
                                            'identity' => display_mr($resident['identity_number']),
                                            'identityRaw' => (string)($resident['identity_number'] ?? ''),
                                            'residentType' => display_mr($resident['resident_type']),
                                            'residentTypeRaw' => (string)($resident['resident_type'] ?? ''),
                                            'stayStart' => display_mr($resident['stay_start_date']),
                                            'stayStartRaw' => (string)($resident['stay_start_date'] ?? ''),
                                            'stayEnd' => display_mr($resident['stay_end_date']),
                                            'stayEndRaw' => (string)($resident['stay_end_date'] ?? ''),
                                            'verificationNote' => (string)($resident['verification_note'] ?? ''),
                                            'unitId' => (int)($resident['unit_id'] ?? 0),
                                            'unitLabel' => $unitLabel,
                                            'status' => $residentStatus,
                                            'created' => $createdText,
                                            'vehicles' => (int)$resident['vehicle_count'],
                                            'bookings' => (int)$resident['booking_count'],
                                            'initial' => $initial
                                        ];
                                    ?>

                                    <tr
                                        class="resident-row <?= $index === 0 ? 'selected' : '' ?>"
                                        data-resident='<?= e(json_encode($payload, JSON_UNESCAPED_UNICODE)) ?>'
                                    >
                                        <td>
                                            <div class="name-cell">
                                                <div class="avatar-sm"><?= e($initial) ?></div>
                                                <div>
                                                    <div class="name-main"><?= e($residentName) ?></div>
                                                    <div class="name-sub"><?= e(display_mr($resident['contact_number'])) ?></div>
                                                </div>
                                            </div>
                                        </td>
<td><?= e($resident['email']) ?></td>
                                        <td><?= e($unitLabel) ?></td>
                                        <td><?= e(display_mr($resident['resident_type'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <aside class="info-panel">
                <div class="panel-head">
                    <div class="panel-title">
                        <i class="fas fa-id-card"></i>
                        User Information
                    </div>

                    <span class="status-pill status-active" id="infoStatus">Active</span>
                </div>

                <div class="info-body">
                    <?php if (!$firstResident): ?>
                        <div class="empty-state">
                            Select a resident to view details.
                        </div>
                    <?php else: ?>
                        <div class="header-action-area">
                            <div class="header-action-row">
                                <button type="button" class="header-mini-btn" data-info-edit title="Edit resident">
                                    <i class="fas fa-pen"></i>
                                </button>

                                <div class="header-more-menu" id="residentMoreMenu">
                                    <button type="button" class="header-mini-btn" id="residentMoreBtn" title="More actions">
                                        <i class="fas fa-ellipsis"></i>
                                    </button>

                                    <div class="more-dropdown">
                                        <button type="button" class="more-item" data-info-edit>
                                            <i class="fas fa-pen"></i>
                                            Edit details
                                        </button>

                                        <form method="POST" data-safe-confirm="1" data-confirm-title="Change resident status?" data-confirm-text="This works like blacklist/deactivate for the resident account.">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="set_status">
                                            <input type="hidden" name="resident_id" id="statusResidentId">
                                            <input type="hidden" name="new_status" id="statusNewValue">
                                            <button type="submit" class="more-item warning" id="statusActionBtn">
                                                <i class="fas fa-ban"></i>
                                                Set inactive
                                            </button>
                                        </form>

                                        <a href="admin_resident_vehicles.php" class="more-item">
                                            <i class="fas fa-car"></i>
                                            Manage vehicles
                                        </a>

                                        <form method="POST" data-safe-confirm="1" data-confirm-title="Delete resident account?" data-confirm-text="This action is only allowed when the resident has no booking or vehicle records.">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_resident">
                                            <input type="hidden" name="resident_id" id="deleteResidentId">
                                            <button type="submit" class="more-item danger">
                                                <i class="fas fa-trash"></i>
                                                Delete account
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="profile-header-card">
                            <div class="profile-avatar-wrap">
                                <div class="profile-photo" id="infoAvatar">R</div>

                            </div>

                            <div class="info-name" id="infoName">-</div>
                            <div class="info-email" id="infoEmail">-</div>
                        </div>

                        <div class="info-content-area">
                            <div class="info-view-lines">
                                <div class="info-line">
                                    <i class="fas fa-phone"></i>
                                    <div id="infoContact">-</div>
                                </div>

                                <div class="info-line">
                                    <i class="fas fa-house-user"></i>
                                    <div id="infoUnit">-</div>
                                </div>

                                <div class="info-line">
                                    <i class="fas fa-id-card"></i>
                                    <div id="infoIdentity">-</div>
                                </div>

                                <div class="info-line">
                                    <i class="fas fa-user-tag"></i>
                                    <div id="infoType">-</div>
                                </div>

                                <div class="info-line" id="infoStayLine">
                                    <i class="fas fa-calendar-days"></i>
                                    <div>
                                        <span id="infoStayStart">-</span>
                                        →
                                        <span id="infoStayEnd">-</span>
                                    </div>
                                </div>

                                <div class="info-line">
                                    <i class="fas fa-clock"></i>
                                    <div id="infoCreated">-</div>
                                </div>
                            </div>

                            <div class="right-form-section edit-section">
                                <div class="section-label">Edit Resident & Unit</div>

                                <form method="POST" data-safe-confirm="1" data-confirm-title="Update resident profile?" data-confirm-text="Please confirm the resident profile and unit details before saving.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_profile">
                                    <input type="hidden" name="resident_id" id="profileResidentId">

                                    <?php if ($hasFullName): ?>
                                        <input type="text" name="full_name" id="profileFullName" placeholder="Full name" required>
                                    <?php endif; ?>

                                    <?php if ($contactColumn !== null): ?>
                                        <input type="text" name="contact_number" id="profileContact" placeholder="Contact number">
                                    <?php endif; ?>

                                    <?php if ($hasIdentityNumber): ?>
                                        <input type="text" name="identity_number" id="profileIdentity" placeholder="IC / Passport">
                                    <?php endif; ?>

                                    <?php if ($hasResidentType): ?>
                                        <select name="resident_type" id="profileResidentType">
                                            <option value="">-- Type --</option>
                                            <option value="Owner">Owner / Buyer</option>
                                            <option value="Tenant">Tenant / Renter</option>
                                            <option value="Family Member">Family Member</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    <?php endif; ?>

                                    <div class="tenant-date-wrap" id="profileTenantDateWrap">
                                        <div class="tenant-date-label">Tenant contract period</div>

                                        <div class="date-grid">
                                            <?php if ($hasStayStartDate): ?>
                                                <input type="date" name="stay_start_date" id="profileStayStart">
                                            <?php endif; ?>

                                            <?php if ($hasStayEndDate): ?>
                                                <input type="date" name="stay_end_date" id="profileStayEnd">
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if ($hasVerificationNote): ?>
                                        <textarea name="verification_note" id="profileVerificationNote" placeholder="Verification note"></textarea>
                                    <?php endif; ?>

                                    <button type="submit" class="btn btn-primary" style="width:100%;">
                                        <i class="fas fa-floppy-disk"></i>
                                        Save Profile
                                    </button>
                                </form>

                                <form method="POST" class="inline-unit-form" data-safe-confirm="1" data-confirm-title="Change resident unit?" data-confirm-text="This will replace the current active unit assignment for this resident.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="change_unit">
                                    <input type="hidden" name="resident_id" id="unitResidentId">

                                    <div class="unit-mini-title">
                                        <i class="fas fa-house-user"></i>
                                        Unit Assignment
                                    </div>

                                    <div class="unit-grid">
                                        <select id="unitBlockSelect">
                                            <option value="">Block</option>
                                        </select>

                                        <select id="unitFloorSelect" disabled>
                                            <option value="">Floor</option>
                                        </select>

                                        <select id="unitUnitSelect" name="unit_id" disabled required>
                                            <option value="">Unit</option>
                                        </select>
                                    </div>

                                    <button type="submit" class="btn btn-primary" style="width:100%;margin-top:8px;">
                                        <i class="fas fa-house-user"></i>
                                        Save Unit
                                    </button>
                                </form>
                            </div>
                        </div>

                    <?php endif; ?>

                </div>
            </aside>
        </section>
    </main>
</div>

<?php if ($error): ?>
<script>
Swal.fire({
    icon: 'error',
    title: 'Error',
    text: <?= json_encode($error) ?>,
    confirmButtonColor: '#dc2626'
});
</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const successAlert = document.querySelector('.alert.success');

    if (successAlert) {
        setTimeout(function () {
            successAlert.style.transition = 'opacity .35s ease, transform .35s ease';
            successAlert.style.opacity = '0';
            successAlert.style.transform = 'translateY(-6px)';

            setTimeout(function () {
                successAlert.remove();
            }, 380);
        }, 2500);
    }

    const unitOptions = <?= json_encode($unitOptionsForJs, JSON_UNESCAPED_UNICODE) ?>;
    const rows = document.querySelectorAll('.resident-row');

    function uniqueSorted(values) {
        return [...new Set(values.filter(value => value !== null && value !== ''))].sort(function (a, b) {
            return String(a).localeCompare(String(b), undefined, {numeric: true, sensitivity: 'base'});
        });
    }

    function resetSelect(select, placeholder, disabled = true) {
        if (!select) {
            return;
        }

        select.innerHTML = '';
        const option = document.createElement('option');
        option.value = '';
        option.textContent = placeholder;
        select.appendChild(option);
        select.disabled = disabled;
    }

    function fillSelect(select, values, placeholder) {
        resetSelect(select, placeholder, false);

        values.forEach(function (value) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = value;
            select.appendChild(option);
        });
    }

    const blockSelect = document.getElementById('unitBlockSelect');
    const floorSelect = document.getElementById('unitFloorSelect');
    const unitSelect = document.getElementById('unitUnitSelect');

    function setupUnitSelector() {
        if (!blockSelect || !floorSelect || !unitSelect) {
            return;
        }

        const blocks = uniqueSorted(unitOptions.map(unit => unit.block_no));
        fillSelect(blockSelect, blocks, 'Block');
        resetSelect(floorSelect, 'Floor', true);
        resetSelect(unitSelect, 'Unit', true);

        blockSelect.addEventListener('change', function () {
            const selectedBlock = blockSelect.value;
            resetSelect(floorSelect, 'Floor', true);
            resetSelect(unitSelect, 'Unit', true);

            if (!selectedBlock) {
                return;
            }

            const floors = uniqueSorted(unitOptions
                .filter(unit => unit.block_no === selectedBlock)
                .map(unit => unit.floor_no));

            fillSelect(floorSelect, floors, 'Floor');
        });

        floorSelect.addEventListener('change', function () {
            const selectedBlock = blockSelect.value;
            const selectedFloor = floorSelect.value;
            resetSelect(unitSelect, 'Unit', true);

            if (!selectedBlock || !selectedFloor) {
                return;
            }

            resetSelect(unitSelect, 'Unit', false);

            unitOptions
                .filter(unit => unit.block_no === selectedBlock && String(unit.floor_no) === String(selectedFloor))
                .sort(function (a, b) {
                    return String(a.unit_no).localeCompare(String(b.unit_no), undefined, {numeric: true, sensitivity: 'base'});
                })
                .forEach(function (unit) {
                    const option = document.createElement('option');
                    option.value = unit.id;
                    option.textContent = unit.unit_no;
                    unitSelect.appendChild(option);
                });
        });
    }

    function setValue(id, value) {
        const element = document.getElementById(id);

        if (element) {
            element.value = value ?? '';
        }
    }

    function setText(id, value) {
        const element = document.getElementById(id);

        if (element) {
            element.textContent = value || '-';
        }
    }

    function setSelectValue(id, value) {
        const element = document.getElementById(id);

        if (element) {
            element.value = value || '';
        }
    }

    function updateTenantDateFields() {
        const typeSelect = document.getElementById('profileResidentType');
        const dateWrap = document.getElementById('profileTenantDateWrap');
        const stayStart = document.getElementById('profileStayStart');
        const stayEnd = document.getElementById('profileStayEnd');
        const isTenant = typeSelect && typeSelect.value === 'Tenant';

        if (dateWrap) {
            dateWrap.classList.toggle('show', !!isTenant);
        }

        if (stayStart) {
            stayStart.required = !!isTenant;

            if (!isTenant) {
                stayStart.value = '';
            }
        }

        if (stayEnd) {
            stayEnd.required = !!isTenant;

            if (!isTenant) {
                stayEnd.value = '';
            }
        }
    }

    function updateInfoStayLine(data) {
        const line = document.getElementById('infoStayLine');

        if (!line) {
            return;
        }

        const isTenant = data && data.residentTypeRaw === 'Tenant';
        line.style.display = isTenant ? 'flex' : 'none';
    }

    function selectUnit(unitId) {
        if (!blockSelect || !floorSelect || !unitSelect) {
            return;
        }

        const unit = unitOptions.find(item => String(item.id) === String(unitId));

        if (!unit) {
            blockSelect.value = '';
            blockSelect.dispatchEvent(new Event('change'));
            return;
        }

        blockSelect.value = unit.block_no;
        blockSelect.dispatchEvent(new Event('change'));

        floorSelect.value = String(unit.floor_no);
        floorSelect.dispatchEvent(new Event('change'));

        unitSelect.value = String(unit.id);
    }

    function renderResident(data) {
        if (!data) {
            return;
        }

        setText('infoAvatar', data.initial || 'R');
        setText('infoName', data.name);
        setText('infoEmail', data.email);
        setText('infoContact', data.contact);
        setText('infoUnit', data.unitLabel);
        setText('infoIdentity', data.identity);
        setText('infoType', data.residentType);
        setText('infoStayStart', data.stayStart);
        setText('infoStayEnd', data.stayEnd);
        setText('infoCreated', data.created);

        const infoStatus = document.getElementById('infoStatus');
        if (infoStatus) {
            infoStatus.textContent = data.status || 'inactive';
            infoStatus.className = 'status-pill status-' + (data.status || 'inactive');
        }

        setValue('profileResidentId', data.id);
        setValue('unitResidentId', data.id);
        setValue('statusResidentId', data.id);
        setValue('deleteResidentId', data.id);

        setValue('profileFullName', data.name);
        setValue('profileContact', data.contactRaw);
        setValue('profileIdentity', data.identityRaw);
        setSelectValue('profileResidentType', data.residentTypeRaw);
        setValue('profileStayStart', data.stayStartRaw);
        setValue('profileStayEnd', data.stayEndRaw);
        setValue('profileVerificationNote', data.verificationNote);
        updateTenantDateFields();
        updateInfoStayLine(data);

        selectUnit(data.unitId);

        const statusNewValue = document.getElementById('statusNewValue');
        const statusActionBtn = document.getElementById('statusActionBtn');

        if (statusNewValue && statusActionBtn) {
            if (data.status === 'active') {
                statusNewValue.value = 'inactive';
                statusActionBtn.className = 'more-item warning';
                statusActionBtn.innerHTML = '<i class="fas fa-ban"></i> Set inactive';
            } else {
                statusNewValue.value = 'active';
                statusActionBtn.className = 'more-item';
                statusActionBtn.innerHTML = '<i class="fas fa-user-check"></i> Set active';
            }
        }
    }

    setupUnitSelector();

    const profileResidentType = document.getElementById('profileResidentType');

    if (profileResidentType) {
        profileResidentType.addEventListener('change', updateTenantDateFields);
        updateTenantDateFields();
    }

    rows.forEach(function (row) {
        row.addEventListener('click', function () {
            rows.forEach(item => item.classList.remove('selected'));
            row.classList.add('selected');

            const infoPanel = document.querySelector('.info-panel');
            if (infoPanel) {
                infoPanel.classList.remove('mode-edit');
            }

            renderResident(JSON.parse(row.dataset.resident));
        });
    });

    if (rows.length) {
        renderResident(JSON.parse(rows[0].dataset.resident));
    }

    document.querySelectorAll('[data-info-edit]').forEach(function (button) {
        button.addEventListener('click', function () {
            const infoPanel = document.querySelector('.info-panel');
            const moreMenu = document.getElementById('residentMoreMenu');

            if (infoPanel) {
                infoPanel.classList.toggle('mode-edit');
            }

            if (moreMenu) {
                moreMenu.classList.remove('open');
            }
        });
    });

    const residentMoreBtn = document.getElementById('residentMoreBtn');
    const residentMoreMenu = document.getElementById('residentMoreMenu');

    if (residentMoreBtn && residentMoreMenu) {
        residentMoreBtn.addEventListener('click', function (event) {
            event.stopPropagation();
            residentMoreMenu.classList.toggle('open');
        });

        document.addEventListener('click', function (event) {
            if (!residentMoreMenu.contains(event.target)) {
                residentMoreMenu.classList.remove('open');
            }
        });
    }

    document.querySelectorAll('form[data-safe-confirm="1"]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.dataset.confirmed === 'yes') {
                return;
            }

            event.preventDefault();

            if (form.querySelector('#profileResidentType')) {
                updateTenantDateFields();
            }

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            Swal.fire({
                icon: 'question',
                title: form.dataset.confirmTitle || 'Confirm action?',
                text: form.dataset.confirmText || 'Please confirm before continuing.',
                showCancelButton: true,
                confirmButtonText: 'Yes, continue',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#64748b',
                reverseButtons: true
            }).then(function (result) {
                if (result.isConfirmed) {
                    let confirmInput = form.querySelector('input[name="confirm_save"]');

                    if (!confirmInput) {
                        confirmInput = document.createElement('input');
                        confirmInput.type = 'hidden';
                        confirmInput.name = 'confirm_save';
                        form.appendChild(confirmInput);
                    }

                    confirmInput.value = 'yes';
                    form.dataset.confirmed = 'yes';
                    form.submit();
                }
            });
        });
    });

    const profileMenu = document.getElementById('profileMenu');
    const profileTrigger = document.getElementById('profileTrigger');

    if (profileMenu && profileTrigger) {
        profileTrigger.addEventListener('click', function (event) {
            event.stopPropagation();
            profileMenu.classList.toggle('open');
        });

        document.addEventListener('click', function (event) {
            if (!profileMenu.contains(event.target)) {
                profileMenu.classList.remove('open');
            }
        });
    }

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
});
</script>

</body>
</html>
    