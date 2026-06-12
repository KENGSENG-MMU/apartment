<?php
require_once '../core/security.php';
require_login(['admin', 'superadmin']);

$pdo = db();

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return '<input type="hidden" name="csrf_token" value="' . e($_SESSION['csrf_token']) . '">';
    }
}

function va_has_table(PDO $pdo, string $table): bool {
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

function va_has_col(PDO $pdo, string $table, string $column): bool {
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

function va_first_col(PDO $pdo, string $table, array $columns): ?string {
    foreach ($columns as $column) {
        if (va_has_col($pdo, $table, $column)) {
            return $column;
        }
    }

    return null;
}

function va_first_table(PDO $pdo, array $tables): ?string {
    foreach ($tables as $table) {
        if (va_has_table($pdo, $table)) {
            return $table;
        }
    }

    return null;
}

function va_rows(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function va_count(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function va_display($value): string {
    return ($value !== null && $value !== '') ? (string)$value : '-';
}

function va_initial($name): string {
    $name = trim((string)$name);
    return $name !== '' ? strtoupper(substr($name, 0, 1)) : 'V';
}

function va_plate($value): string {
    return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)$value)));
}

function va_datetime($value): string {
    $ts = strtotime((string)$value);
    return $ts ? date('d M Y, g:i A', $ts) : '-';
}

function va_status($value): string {
    $value = strtolower(trim((string)$value));

    if ($value === '') {
        return 'active';
    }

    return $value;
}

function va_status_class($value): string {
    $value = va_status($value);

    return match ($value) {
        'active', 'verified' => 'status-active',
        'inactive', 'blocked', 'suspended' => 'status-inactive',
        default => 'status-neutral'
    };
}

function va_blacklist_plate(PDO $pdo, string $plate, string $visitorName, string $reason, int $adminId, ?int $apartmentId): array {
    $table = va_first_table($pdo, ['blacklisted_plates', 'blacklist', 'vehicle_blacklist', 'plate_blacklist']);

    if (!$table) {
        return ['ok' => false, 'message' => 'Blacklist table was not found.'];
    }

    $plateCol = va_first_col($pdo, $table, ['plate_no', 'plate_number', 'vehicle_plate', 'car_plate']);

    if (!$plateCol) {
        return ['ok' => false, 'message' => 'Blacklist plate column was not found.'];
    }

    $where = ["`$plateCol` = ?"];
    $params = [$plate];

    $aptCol = va_first_col($pdo, $table, ['apartment_id']);
    if ($aptCol && $apartmentId) {
        $where[] = "`$aptCol` = ?";
        $params[] = $apartmentId;
    }

    $exists = va_count($pdo, "SELECT COUNT(*) FROM `$table` WHERE " . implode(' AND ', $where), $params);
    if ($exists > 0) {
        return ['ok' => false, 'message' => 'This plate is already blacklisted.'];
    }

    $cols = [$plateCol];
    $marks = ['?'];
    $values = [$plate];

    $ownerCol = va_first_col($pdo, $table, ['owner_name', 'visitor_name', 'name']);
    if ($ownerCol) {
        $cols[] = $ownerCol;
        $marks[] = '?';
        $values[] = $visitorName;
    }

    $reasonCol = va_first_col($pdo, $table, ['reason', 'blacklist_reason', 'remarks', 'note']);
    if ($reasonCol) {
        $cols[] = $reasonCol;
        $marks[] = '?';
        $values[] = $reason;
    }

    $statusCol = va_first_col($pdo, $table, ['status', 'blacklist_status']);
    if ($statusCol) {
        $cols[] = $statusCol;
        $marks[] = '?';
        $values[] = 'active';
    }

    if ($aptCol && $apartmentId) {
        $cols[] = $aptCol;
        $marks[] = '?';
        $values[] = $apartmentId;
    }

    $createdByCol = va_first_col($pdo, $table, ['created_by', 'admin_id', 'added_by']);
    if ($createdByCol) {
        $cols[] = $createdByCol;
        $marks[] = '?';
        $values[] = $adminId;
    }

    $createdAtCol = va_first_col($pdo, $table, ['created_at', 'date_added']);
    if ($createdAtCol) {
        $cols[] = $createdAtCol;
        $marks[] = 'NOW()';
    }

    $colSql = implode(', ', array_map(fn($c) => "`$c`", $cols));
    $markSql = implode(', ', $marks);

    $stmt = $pdo->prepare("INSERT INTO `$table` ($colSql) VALUES ($markSql)");
    $stmt->execute($values);

    if (function_exists('log_audit')) {
        log_audit('VISITOR_ACCOUNT_PLATE_BLACKLISTED', 'Admin blacklisted visitor plate ' . $plate);
    }

    return ['ok' => true, 'message' => 'Visitor plate blacklisted successfully.'];
}

$hasUsers = va_has_table($pdo, 'users');
$hasBookings = va_has_table($pdo, 'bookings');

$nameCol = $hasUsers ? va_first_col($pdo, 'users', ['full_name', 'name', 'username']) : null;
$contactCol = $hasUsers ? va_first_col($pdo, 'users', ['contact_number', 'phone', 'mobile']) : null;
$identityCol = $hasUsers ? va_first_col($pdo, 'users', ['identity_number', 'ic_passport', 'ic_number', 'passport_number']) : null;
$statusCol = $hasUsers ? va_first_col($pdo, 'users', ['status', 'account_status']) : null;
$createdCol = $hasUsers ? va_first_col($pdo, 'users', ['created_at', 'date_created']) : null;
$apartmentCol = $hasUsers ? va_first_col($pdo, 'users', ['apartment_id']) : null;

$currentRole = $_SESSION['role'] ?? 'admin';
$currentEmail = $_SESSION['email'] ?? 'admin@apt.com';
$currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
$currentApartmentId = $_SESSION['apartment_id'] ?? null;

if ($currentUserId <= 0 && $currentEmail !== '') {
    $r = va_rows($pdo, "SELECT id FROM users WHERE email = ? LIMIT 1", [$currentEmail]);
    if ($r) {
        $currentUserId = (int)$r[0]['id'];
        $_SESSION['user_id'] = $currentUserId;
    }
}

if (($currentApartmentId === null || $currentApartmentId === '') && $currentUserId > 0 && $apartmentCol) {
    $r = va_rows($pdo, "SELECT apartment_id FROM users WHERE id = ? LIMIT 1", [$currentUserId]);
    if ($r && $r[0]['apartment_id'] !== null && $r[0]['apartment_id'] !== '') {
        $currentApartmentId = (int)$r[0]['apartment_id'];
        $_SESSION['apartment_id'] = $currentApartmentId;
    }
}

if (($currentApartmentId === null || $currentApartmentId === '') && va_has_table($pdo, 'apartments')) {
    $r = va_rows($pdo, "SELECT id FROM apartments ORDER BY id ASC LIMIT 1");
    if ($r) {
        $currentApartmentId = (int)$r[0]['id'];
    }
}

$currentApartmentName = 'No Apartment Assigned';

if (!empty($currentApartmentId) && va_has_table($pdo, 'apartments')) {
    $r = va_rows($pdo, "SELECT apartment_name FROM apartments WHERE id = ? LIMIT 1", [(int)$currentApartmentId]);
    if ($r) {
        $currentApartmentName = $r[0]['apartment_name'];
    }
}

$message = $_SESSION['flash_success'] ?? '';
$error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrf = $_POST['csrf_token'] ?? '';
        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
            throw new Exception('Invalid security token. Please refresh the page.');
        }

        $action = $_POST['action'] ?? '';

        if ($action === 'bulk_cancel_blacklist') {
            $blacklistIds = $_POST['blacklist_ids'] ?? [];
            if (!is_array($blacklistIds)) {
                $blacklistIds = [];
            }

            $blacklistIds = array_values(array_unique(array_filter(array_map('intval', $blacklistIds))));

            if (empty($blacklistIds)) {
                throw new Exception('Please select at least one blacklist record.');
            }

            $blacklistTableAction = va_first_table($pdo, ['blacklisted_plates', 'blacklist', 'vehicle_blacklist', 'plate_blacklist']);
            if (!$blacklistTableAction) {
                throw new Exception('Blacklist table was not found.');
            }

            $blacklistIdColAction = va_first_col($pdo, $blacklistTableAction, ['id', 'blacklist_id']);
            $blacklistStatusColAction = va_first_col($pdo, $blacklistTableAction, ['status', 'blacklist_status']);
            $blacklistPlateColAction = va_first_col($pdo, $blacklistTableAction, ['plate_no', 'plate_number', 'vehicle_plate', 'car_plate']);
            $blacklistUpdatedColAction = va_first_col($pdo, $blacklistTableAction, ['updated_at', 'date_updated']);

            if (!$blacklistIdColAction || !$blacklistStatusColAction) {
                throw new Exception('Blacklist ID or status column was not found.');
            }

            $placeholders = implode(',', array_fill(0, count($blacklistIds), '?'));

            $plateRows = [];
            if ($blacklistPlateColAction) {
                $plateRows = va_rows($pdo, "
                    SELECT `$blacklistPlateColAction` AS plate_no
                    FROM `$blacklistTableAction`
                    WHERE `$blacklistIdColAction` IN ($placeholders)
                ", $blacklistIds);
            }

            $sql = "
                UPDATE `$blacklistTableAction`
                SET `$blacklistStatusColAction` = 'inactive'
            ";

            if ($blacklistUpdatedColAction) {
                $sql .= ", `$blacklistUpdatedColAction` = NOW()";
            }

            $sql .= " WHERE `$blacklistIdColAction` IN ($placeholders)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($blacklistIds);

            $cancelledCount = $stmt->rowCount();

            if (function_exists('log_audit')) {
                $plates = array_map(fn($row) => (string)($row['plate_no'] ?? ''), $plateRows);
                log_audit('VISITOR_BLACKLIST_BULK_CANCELLED', 'Admin cancelled visitor blacklist: ' . implode(', ', array_filter($plates)));
            }

            $_SESSION['flash_success'] = $cancelledCount . ' blacklist record(s) cancelled successfully.';
            header('Location: admin_visitor_passes.php');
            exit;
        }

        $visitorId = (int)($_POST['visitor_id'] ?? 0);

        if ($visitorId <= 0) {
            throw new Exception('Visitor account was not selected.');
        }

        $visitorCheck = va_rows($pdo, "SELECT id, role FROM users WHERE id = ? AND LOWER(role) = 'visitor' LIMIT 1", [$visitorId]);
        if (!$visitorCheck) {
            throw new Exception('Invalid visitor account.');
        }

        if ($action === 'update_profile') {
            $sets = [];
            $params = [];

            $fullName = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $contact = trim($_POST['contact_number'] ?? '');
            $identity = strtoupper(trim($_POST['identity_number'] ?? ''));

            if ($nameCol) {
                if ($fullName === '') {
                    throw new Exception('Please enter visitor full name.');
                }

                $sets[] = "`$nameCol` = ?";
                $params[] = $fullName;
            }

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email.');
            }

            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
            $stmt->execute([$email, $visitorId]);
            if ($stmt->fetch()) {
                throw new Exception('This email is already used by another account.');
            }

            $sets[] = "email = ?";
            $params[] = $email;

            if ($contactCol) {
                if ($contact !== '' && !preg_match('/^01[0-9]-?[0-9]{7,8}$/', $contact)) {
                    throw new Exception('Contact number format must be like 011-58606387 or 01158606387.');
                }

                $sets[] = "`$contactCol` = ?";
                $params[] = $contact ?: null;
            }

            if ($identityCol) {
                if ($identity !== '' && !preg_match('/^(\d{6}-?\d{2}-?\d{4}|[A-Z0-9]{6,12})$/', $identity)) {
                    throw new Exception('IC / Passport format must be like 990101-01-1234 or A12345678.');
                }

                $sets[] = "`$identityCol` = ?";
                $params[] = $identity ?: null;
            }

            $params[] = $visitorId;

            $stmt = $pdo->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?");
            $stmt->execute($params);

            $_SESSION['flash_success'] = 'Visitor account updated successfully.';
        }

        if ($action === 'blacklist_last_plate') {
            $plate = va_plate($_POST['plate_no'] ?? '');
            $visitorName = trim($_POST['visitor_name'] ?? 'Visitor');

            if ($plate === '') {
                throw new Exception('This visitor does not have a plate record yet.');
            }

            $result = va_blacklist_plate(
                $pdo,
                $plate,
                $visitorName,
                'Blacklisted from Visitor Accounts',
                $currentUserId,
                $currentApartmentId ? (int)$currentApartmentId : null
            );

            if (!$result['ok']) {
                throw new Exception($result['message']);
            }

            $_SESSION['flash_success'] = $result['message'];
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }

    header('Location: admin_visitor_passes.php');
    exit;
}

$search = trim($_GET['search'] ?? '');
$where = ["LOWER(u.role) = 'visitor'"];
$params = [];


if ($search !== '') {
    $term = '%' . $search . '%';
    $searchParts = ["u.email LIKE ?"];
    $params[] = $term;

    if ($nameCol) {
        $searchParts[] = "u.`$nameCol` LIKE ?";
        $params[] = $term;
    }

    if ($contactCol) {
        $searchParts[] = "u.`$contactCol` LIKE ?";
        $params[] = $term;
    }

    if ($identityCol) {
        $searchParts[] = "u.`$identityCol` LIKE ?";
        $params[] = $term;
    }

    if ($hasBookings && va_has_col($pdo, 'bookings', 'plate_no')) {
        $searchParts[] = "EXISTS (SELECT 1 FROM bookings b WHERE b.visitor_user_id = u.id AND b.plate_no LIKE ?)";
        $params[] = $term;
    }

    $where[] = '(' . implode(' OR ', $searchParts) . ')';
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$nameSelect = $nameCol ? "u.`$nameCol` AS full_name" : "u.email AS full_name";
$contactSelect = $contactCol ? "u.`$contactCol` AS contact_number" : "NULL AS contact_number";
$identitySelect = $identityCol ? "u.`$identityCol` AS identity_number" : "NULL AS identity_number";
$statusSelect = $statusCol ? "u.`$statusCol` AS account_status" : "'active' AS account_status";
$createdSelect = $createdCol ? "u.`$createdCol` AS created_at" : "NULL AS created_at";

$visitors = va_rows($pdo, "
    SELECT
        u.id,
        {$nameSelect},
        u.email,
        {$contactSelect},
        {$identitySelect},
        {$statusSelect},
        {$createdSelect},
        (
            SELECT COUNT(*)
            FROM bookings b
            WHERE b.visitor_user_id = u.id
        ) AS total_bookings,
        (
            SELECT b.plate_no
            FROM bookings b
            WHERE b.visitor_user_id = u.id
            ORDER BY b.start_time DESC, b.id DESC
            LIMIT 1
        ) AS last_plate,
        (
            SELECT b.start_time
            FROM bookings b
            WHERE b.visitor_user_id = u.id
            ORDER BY b.start_time DESC, b.id DESC
            LIMIT 1
        ) AS last_visit_time,
        (
            SELECT b.status
            FROM bookings b
            WHERE b.visitor_user_id = u.id
            ORDER BY b.start_time DESC, b.id DESC
            LIMIT 1
        ) AS last_visit_status
    FROM users u
    {$whereSql}
    ORDER BY
        CASE
            WHEN LOWER(COALESCE(u.`" . ($statusCol ?: 'role') . "`, 'active')) = 'active' THEN 0
            ELSE 1
        END,
        u.id DESC
    LIMIT 800
", $params);

$totalVisitorAccounts = va_count($pdo, "SELECT COUNT(*) FROM users WHERE LOWER(role) = 'visitor'" );

// Count only real completed visitor records.
// This must match admin_visitor_records.php: a record is counted only when the visitor has an actual exit time.
$totalVisitRecords = 0;
if ($hasBookings) {
    $completedJoins = [];
    $completedWhere = [];
    $completedParams = [];

    $bAptCol = va_first_col($pdo, 'bookings', ['apartment_id']);
    $bResidentCol = va_first_col($pdo, 'bookings', ['resident_id', 'host_resident_id', 'user_id']);
    $bExitCol = va_first_col($pdo, 'bookings', ['actual_exit_at', 'exit_time', 'checked_out_at', 'out_time']);

    if ($bResidentCol && va_has_table($pdo, 'users')) {
        $completedJoins[] = "LEFT JOIN users r ON r.id = b.`$bResidentCol`";
    } else {
        $completedJoins[] = "LEFT JOIN users r ON 1 = 0";
    }

    if ($bResidentCol && va_has_table($pdo, 'resident_units') && va_has_table($pdo, 'units')) {
        $completedJoins[] = "LEFT JOIN (SELECT resident_id, MIN(unit_id) AS unit_id FROM resident_units WHERE status = 'active' GROUP BY resident_id) ru ON ru.resident_id = b.`$bResidentCol`";
        $completedJoins[] = "LEFT JOIN units un ON un.id = ru.unit_id";
    } else {
        $completedJoins[] = "LEFT JOIN units un ON 1 = 0";
    }

    $exitExpr = $bExitCol ? "b.`$bExitCol`" : "NULL";
    if (va_has_table($pdo, 'gate_logs')) {
        $glBookingCol = va_first_col($pdo, 'gate_logs', ['booking_id']);
        $glActionCol = va_first_col($pdo, 'gate_logs', ['gate_action', 'action']);
        $glDecisionCol = va_first_col($pdo, 'gate_logs', ['decision', 'status']);
        $glTimeCol = va_first_col($pdo, 'gate_logs', ['action_time', 'created_at']);

        if ($glBookingCol && $glActionCol && $glTimeCol) {
            $allowOnly = $glDecisionCol ? "AND UPPER(CAST(`$glDecisionCol` AS CHAR)) = 'ALLOW'" : "";
            $completedJoins[] = "LEFT JOIN (
                SELECT `$glBookingCol` AS booking_id, MAX(`$glTimeCol`) AS exit_time
                FROM gate_logs
                WHERE UPPER(CAST(`$glActionCol` AS CHAR)) = 'EXIT' $allowOnly
                GROUP BY `$glBookingCol`
            ) glx ON glx.booking_id = b.id";
            $exitExpr = $bExitCol ? "COALESCE(glx.exit_time, b.`$bExitCol`)" : "glx.exit_time";
        }
    }

    if (!empty($currentApartmentId)) {
        if ($bAptCol) {
            $completedWhere[] = "(b.`$bAptCol` = ? OR (b.`$bAptCol` IS NULL AND (r.apartment_id = ? OR un.apartment_id = ?)))";
            $completedParams[] = (int)$currentApartmentId;
            $completedParams[] = (int)$currentApartmentId;
            $completedParams[] = (int)$currentApartmentId;
        } else {
            $completedWhere[] = "(r.apartment_id = ? OR un.apartment_id = ?)";
            $completedParams[] = (int)$currentApartmentId;
            $completedParams[] = (int)$currentApartmentId;
        }
    }

    $completedWhere[] = "($exitExpr IS NOT NULL AND $exitExpr <> '')";
    $completedJoinSql = implode("\n", $completedJoins);
    $completedWhereSql = $completedWhere ? ('WHERE ' . implode(' AND ', $completedWhere)) : '';

    $totalVisitRecords = va_count($pdo, "
        SELECT COUNT(DISTINCT b.id)
        FROM bookings b
        $completedJoinSql
        $completedWhereSql
    ", $completedParams);
}

$blacklistTable = va_first_table($pdo, ['blacklisted_plates', 'blacklist', 'vehicle_blacklist', 'plate_blacklist']);
$blacklistCount = $blacklistTable ? va_count($pdo, "SELECT COUNT(*) FROM `$blacklistTable`") : 0;

$blacklistModalRows = [];
$blacklistActiveCount = 0;
$blacklistInactiveCount = 0;
$latestBlacklistText = '-';

if ($blacklistTable) {
    $blIdCol = va_first_col($pdo, $blacklistTable, ['id', 'blacklist_id']);
    $blPlateCol = va_first_col($pdo, $blacklistTable, ['plate_no', 'plate_number', 'vehicle_plate', 'car_plate']);
    $blReasonCol = va_first_col($pdo, $blacklistTable, ['reason', 'blacklist_reason', 'remarks', 'note']);
    $blStatusCol = va_first_col($pdo, $blacklistTable, ['status', 'blacklist_status']);
    $blOwnerCol = va_first_col($pdo, $blacklistTable, ['owner_name', 'visitor_name', 'name']);
    $blCreatedCol = va_first_col($pdo, $blacklistTable, ['created_at', 'date_added', 'date_created']);
    $blAptCol = va_first_col($pdo, $blacklistTable, ['apartment_id']);

    if ($blPlateCol) {
        $selectParts = [
            $blIdCol ? "`$blIdCol` AS id" : "0 AS id",
            "`$blPlateCol` AS plate_no",
            $blOwnerCol ? "`$blOwnerCol` AS owner_name" : "NULL AS owner_name",
            $blReasonCol ? "`$blReasonCol` AS reason_text" : "NULL AS reason_text",
            $blStatusCol ? "`$blStatusCol` AS status_text" : "'active' AS status_text",
            $blCreatedCol ? "`$blCreatedCol` AS created_at" : "NULL AS created_at"
        ];

        $blWhere = [];
        $blParams = [];

        if (!empty($currentApartmentId) && $blAptCol) {
            $blWhere[] = "(`$blAptCol` = ? OR `$blAptCol` IS NULL)";
            $blParams[] = (int)$currentApartmentId;
        }

        $blWhereSql = $blWhere ? ("WHERE " . implode(" AND ", $blWhere)) : "";
        $blOrderSql = $blCreatedCol ? "`$blCreatedCol` DESC" : ($blIdCol ? "`$blIdCol` DESC" : "`$blPlateCol` ASC");

        $blacklistModalRows = va_rows($pdo, "
            SELECT " . implode(",\n                   ", $selectParts) . "
            FROM `$blacklistTable`
            $blWhereSql
            ORDER BY $blOrderSql
            LIMIT 500
        ", $blParams);
    }
}

$blacklistModalData = [];
foreach ($blacklistModalRows as $item) {
    $plate = va_plate($item['plate_no'] ?? '');
    $statusRaw = va_status($item['status_text'] ?? 'active');

    if ($statusRaw === 'active') {
        $blacklistActiveCount++;
    } else {
        $blacklistInactiveCount++;
    }

    if ($latestBlacklistText === '-' && !empty($item['created_at'])) {
        $latestBlacklistText = va_datetime($item['created_at']);
    }

    $blacklistModalData[] = [
        'id' => (int)($item['id'] ?? 0),
        'plate' => $plate ?: '-',
        'owner' => va_display($item['owner_name'] ?? null),
        'reason' => va_display($item['reason_text'] ?? null),
        'status' => strtoupper($statusRaw),
        'statusRaw' => $statusRaw,
        'created' => va_datetime($item['created_at'] ?? null),
        'statusClass' => va_status_class($statusRaw)
    ];
}

$visitorData = [];
foreach ($visitors as $visitor) {
    $name = va_display($visitor['full_name']);
    $status = va_status($visitor['account_status']);
    $lastPlate = va_plate($visitor['last_plate']);

    $visitorData[] = [
        'id' => (int)$visitor['id'],
        'initial' => va_initial($name),
        'name' => $name,
        'email' => va_display($visitor['email']),
        'phone' => va_display($visitor['contact_number']),
        'phoneRaw' => (string)($visitor['contact_number'] ?? ''),
        'identity' => va_display($visitor['identity_number']),
        'identityRaw' => (string)($visitor['identity_number'] ?? ''),
        'created' => va_datetime($visitor['created_at']),
        'createdRaw' => (string)($visitor['created_at'] ?? ''),
        'lastPlate' => $lastPlate ?: '-',
        'lastVisit' => va_datetime($visitor['last_visit_time']),
        'lastVisitStatus' => va_display($visitor['last_visit_status']),
        'totalBookings' => (int)($visitor['total_bookings'] ?? 0),
        'recordsUrl' => 'admin_visitor_records.php?from=visitor_accounts&visitor_id=' . (int)$visitor['id'],
    ];
}

$selected = $visitorData[0] ?? null;
$adminInitial = strtoupper(substr($currentEmail ?: 'A', 0, 1));
$appTitle = defined('APP_NAME') ? APP_NAME : 'SmartVMS';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Visitor Accounts - <?= e($appTitle) ?></title>
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
            --green-soft: #dcfce7;
            --blue: #2563eb;
            --blue-soft: #dbeafe;
            --orange: #f97316;
            --orange-soft: #ffedd5;
            --text: #0f172a;
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
                radial-gradient(circle at 84% 4%, rgba(220, 38, 38, 0.13), transparent 28%),
                linear-gradient(135deg, #fff7f7 0%, #f4f6fb 45%, #eef2f7 100%);
            color: var(--text);
        }

        a { color: inherit; }

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

        .brand-title span { color: var(--primary); }

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

        .side-parent { margin-top: 4px; }

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
            font-size: .72rem;
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
            font-size: 1.65rem;
            line-height: 1.08;
            font-weight: 950;
            letter-spacing: -0.06em;
        }

        .page-sub {
            color: var(--muted);
            margin-top: 6px;
            font-size: .84rem;
            font-weight: 750;
            line-height: 1.38;
            max-width: 780px;
        }

        .top-actions {
            display: flex;
            align-items: center;
            gap: 10px;
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
            box-shadow: 0 12px 26px rgba(220, 38, 38, 0.22);
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

        .auto-hide-alert {
            transition: opacity .35s ease, transform .35s ease, margin .35s ease, padding .35s ease, max-height .35s ease;
            max-height: 90px;
            overflow: hidden;
        }

        .auto-hide-alert.hide {
            opacity: 0;
            transform: translateY(-8px);
            margin-top: 0;
            margin-bottom: 0;
            padding-top: 0;
            padding-bottom: 0;
            max-height: 0;
            pointer-events: none;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 14px;
            flex: 0 0 auto;
        }

        .stat-card {
            background: rgba(255,255,255,.96);
            border: 1px solid rgba(229,231,235,.95);
            border-radius: 22px;
            box-shadow: var(--shadow-soft);
            padding: 16px 18px;
            min-height: 82px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            text-decoration: none;
            color: inherit;
        }

        .stat-card.clickable {
            cursor: pointer;
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
        }

        .stat-card.clickable:hover {
            transform: translateY(-2px);
            border-color: #fecaca;
            box-shadow: 0 18px 36px rgba(220, 38, 38, .13);
        }

        .stat-value {
            font-size: 1.55rem;
            line-height: 1;
            font-weight: 950;
            letter-spacing: -.05em;
            color: #0f172a;
        }

        .stat-value.red { color: var(--primary); }
        .stat-value.green { color: var(--green); }
        .stat-value.blue { color: var(--blue); }

        .stat-label {
            margin-top: 8px;
            color: var(--muted);
            font-size: .68rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 16px;
            display: grid;
            place-items: center;
            background: var(--primary-soft);
            color: var(--primary);
            flex: 0 0 auto;
        }

        .stat-icon.green {
            background: var(--green-soft);
            color: var(--green);
        }

        .stat-icon.blue {
            background: var(--blue-soft);
            color: var(--blue);
        }

        .visitor-layout {
            flex: 1 1 auto;
            min-height: 0;
            display: grid;
            grid-template-columns: minmax(720px, 1fr) 460px;
            gap: 18px;
            overflow: hidden;
        }

        .panel {
            background: rgba(255,255,255,.96);
            border: 1px solid rgba(229,231,235,.95);
            border-radius: 22px;
            box-shadow: var(--shadow);
            overflow: hidden;
            min-height: 0;
        }

        .list-panel {
            display: flex;
            flex-direction: column;
        }

        .panel-head {
            padding: 15px 18px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
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

        .panel-title i { color: var(--primary); }

        .filters {
            padding: 14px 18px 12px;
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 10px;
            align-items: end;
            border-bottom: 1px solid var(--border);
            flex: 0 0 auto;
        }

        label {
            display: block;
            font-size: .66rem;
            font-weight: 950;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 6px;
        }

        input,
        select {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 13px;
            padding: 11px 12px;
            outline: none;
            background: white;
            font-size: .82rem;
            font-weight: 850;
        }

        input:focus,
        select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(220, 38, 38, .10);
        }

        .icon-btn,
        .btn {
            border: none;
            cursor: pointer;
            border-radius: 13px;
            font-weight: 950;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            text-decoration: none;
            transition: .2s ease;
            white-space: nowrap;
        }

        .icon-btn {
            width: 43px;
            height: 43px;
            font-size: .9rem;
        }

        .btn {
            padding: 10px 13px;
            font-size: .78rem;
        }

        .btn:hover,
        .icon-btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary,
        .icon-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 14px 25px rgba(220, 38, 38, .22);
        }

        .btn-light,
        .icon-light {
            background: white;
            color: #0f172a;
            border: 1px solid var(--border);
        }

        .btn-danger {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .btn-warning {
            background: #ffedd5;
            color: #9a3412;
            border: 1px solid #fed7aa;
        }

        .table-head {
            display: grid;
            grid-template-columns: 1.15fr 1.2fr .85fr .55fr;
            padding: 11px 18px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
            color: var(--muted);
            font-size: .67rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .06em;
            flex: 0 0 auto;
        }

        .visitor-scroll {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .visitor-scroll::-webkit-scrollbar {
            width: 8px;
        }

        .visitor-scroll::-webkit-scrollbar-thumb {
            background: #fecaca;
            border-radius: 999px;
        }

        .visitor-row {
            display: grid;
            grid-template-columns: 1.15fr 1.2fr .85fr .55fr;
            align-items: center;
            gap: 12px;
            min-height: 74px;
            padding: 12px 18px;
            border-bottom: 1px solid #eef2f7;
            cursor: pointer;
            position: relative;
            transition: .18s ease;
        }

        .visitor-row::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 0;
            background: var(--primary);
            transition: .18s ease;
        }

        .visitor-row:hover {
            background: #fff7f7;
        }

        .visitor-row.selected {
            background: #fff1f2;
        }

        .visitor-row.selected::before {
            width: 4px;
        }

        .person-cell {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .mini-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #fee2e2;
            color: var(--primary);
            display: grid;
            place-items: center;
            font-weight: 950;
            flex: 0 0 auto;
        }

        .name-main {
            font-weight: 950;
            font-size: .82rem;
            color: #111827;
            line-height: 1.25;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .name-sub {
            color: #64748b;
            font-size: .76rem;
            font-weight: 800;
            margin-top: 2px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .plate-badge {
            display: inline-flex;
            width: fit-content;
            background: #111827;
            color: white;
            border: 2px solid #334155;
            padding: 6px 10px;
            border-radius: 10px;
            font-family: monospace;
            font-weight: 950;
            letter-spacing: .08em;
            font-size: .78rem;
        }

        .status-pill {
            padding: 6px 10px;
            border-radius: 999px;
            font-size: .64rem;
            font-weight: 950;
            display: inline-flex;
            width: fit-content;
            text-transform: uppercase;
            letter-spacing: .04em;
            white-space: nowrap;
        }

        .status-active {
            background: #dcfce7;
            color: #166534;
        }

        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-neutral {
            background: #f1f5f9;
            color: #475569;
        }

        .list-footer {
            padding: 12px 18px;
            color: var(--muted);
            font-size: .76rem;
            font-weight: 850;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex: 0 0 auto;
        }

        .pager {
            display: flex;
            gap: 6px;
        }

        .page-dot {
            width: 32px;
            height: 32px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: white;
            display: grid;
            place-items: center;
            font-weight: 950;
            color: #94a3b8;
        }

        .page-dot.current {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .info-panel {
            display: flex;
            flex-direction: column;
            background: rgba(255,255,255,.98);
            border-radius: 18px;
            box-shadow: 0 14px 34px rgba(15, 23, 42, 0.07);
        }

        .info-panel.mode-edit .view-section {
            display: none;
        }

        .info-panel:not(.mode-edit) .edit-section {
            display: none;
        }

        .info-profile {
            padding: 8px 0 18px;
            text-align: center;
            position: relative;
            flex: 0 0 auto;
            border-bottom: 1px solid var(--border);
            margin-bottom: 18px;
        }

        .info-actions {
            position: absolute;
            right: 16px;
            top: 10px;
            display: flex;
            gap: 7px;
        }

        .round-action {
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

        .round-action:hover {
            background: #fff1f2;
        }

        .more-menu {
            position: absolute;
            right: 16px;
            top: 58px;
            width: 245px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: 0 20px 45px rgba(15,23,42,.14);
            padding: 10px;
            display: none;
            z-index: 30;
        }

        .more-menu.open {
            display: block;
        }

        .more-item {
            width: 100%;
            border: 0;
            background: transparent;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 12px;
            border-radius: 12px;
            color: #334155;
            font-weight: 900;
            font-size: .78rem;
            text-decoration: none;
            cursor: pointer;
            text-align: left;
        }

        .more-item:hover {
            background: #f8fafc;
        }

        .more-item.danger {
            color: #991b1b;
        }

        .more-item.warning {
            color: #9a3412;
        }

        .big-avatar {
            width: 132px;
            height: 132px;
            border-radius: 34px;
            background:
                radial-gradient(circle at 30% 25%, rgba(255,255,255,.75), transparent 22%),
                linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            display: grid;
            place-items: center;
            font-size: 3rem;
            font-weight: 950;
            margin: 0 auto 14px;
            box-shadow: 0 22px 42px rgba(220,38,38,.22);
            position: relative;
            overflow: hidden;
        }

        .big-avatar::before {
            content: "";
            position: absolute;
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: rgba(255,255,255,.45);
            filter: blur(8px);
            left: 14px;
            top: 14px;
        }

        .big-avatar span {
            position: relative;
            z-index: 2;
        }

        .info-name {
            text-align: center;
            font-size: 1.18rem;
            font-weight: 950;
            letter-spacing: -.04em;
            color: #0f172a;
            line-height: 1.2;
            margin-bottom: 4px;
        }

        .info-sub {
            text-align: center;
            color: #64748b;
            font-size: .84rem;
            font-weight: 850;
            margin-top: 0;
            margin-bottom: 18px;
            word-break: break-word;
        }

        .detail-list {
            padding: 0 20px 14px;
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .detail-divider {
            border-top: 1px solid var(--border);
            margin-bottom: 12px;
        }

        .detail-line {
            display: grid;
            grid-template-columns: 22px 92px 1fr;
            gap: 9px;
            align-items: center;
            padding: 9px 0;
            border-bottom: 1px solid #eef2f7;
            min-height: 39px;
        }

        .detail-line i {
            color: #94a3b8;
            text-align: center;
        }

        .detail-label {
            color: #64748b;
            font-size: .76rem;
            font-weight: 850;
        }

        .detail-value {
            text-align: right;
            font-size: .78rem;
            font-weight: 950;
            color: #334155;
            max-width: none;
            word-break: break-word;
            line-height: 1.35;
        }

        .edit-section {
            padding: 0 18px 14px;
            flex: 1 1 auto;
            min-height: 0;
        }

        .edit-section input {
            margin-bottom: 9px;
        }

        .section-label {
            color: var(--muted);
            font-size: .68rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 9px;
        }

        .info-buttons {
            padding: 14px 20px 18px;
            border-top: 1px solid var(--border);
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 9px;
            flex: 0 0 auto;
        }

        .info-buttons .btn {
            height: 42px;
            padding: 0 9px;
        }

        .empty {
            padding: 48px 22px;
            color: var(--muted);
            text-align: center;
            font-weight: 850;
        }

        .stat-card-button {
            width: 100%;
            border: 1px solid rgba(229,231,235,.95);
            text-align: left;
        }

        .blacklist-modal {
            position: fixed;
            inset: 0;
            z-index: 10000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(15, 23, 42, .46);
            backdrop-filter: blur(6px);
        }

        .blacklist-modal.show {
            display: flex;
        }

        .blacklist-record-box {
            width: min(1320px, 97vw);
            max-height: 90vh;
            min-height: 620px;
            background: rgba(255,255,255,.98);
            border: 1px solid rgba(229,231,235,.95);
            border-radius: 26px;
            box-shadow: 0 28px 70px rgba(15,23,42,.24);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .blacklist-record-head {
            padding: 20px 24px 18px;
            border-bottom: 1px solid var(--border);
            background:
                radial-gradient(circle at 86% 15%, rgba(220,38,38,.14), transparent 28%),
                linear-gradient(135deg, #fff, #fff7f7);
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
        }

        .blacklist-record-kicker {
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: .12em;
            font-size: .68rem;
            font-weight: 950;
            margin-bottom: 6px;
        }

        .blacklist-record-title {
            font-size: 1.35rem;
            line-height: 1.05;
            font-weight: 950;
            letter-spacing: -.05em;
            color: #0f172a;
        }

        .blacklist-record-sub {
            margin-top: 6px;
            color: #64748b;
            font-size: .82rem;
            font-weight: 800;
            line-height: 1.35;
        }

        .blacklist-record-close {
            width: 42px;
            height: 42px;
            border: 0;
            border-radius: 14px;
            color: white;
            background: var(--primary);
            display: grid;
            place-items: center;
            cursor: pointer;
            box-shadow: 0 12px 24px rgba(220,38,38,.22);
            flex: 0 0 auto;
        }

        .blacklist-record-stats {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 12px;
            padding: 14px 24px;
            border-bottom: 1px solid var(--border);
            background: #fff;
        }

        .blacklist-record-stat {
            min-height: 58px;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 10px 14px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .blacklist-record-stat strong {
            font-size: 1.05rem;
            line-height: 1;
            color: #0f172a;
            font-weight: 950;
        }

        .blacklist-record-stat strong.red {
            color: var(--primary);
        }

        .blacklist-record-stat span {
            margin-top: 7px;
            color: var(--muted);
            font-size: .66rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .blacklist-record-search {
            padding: 14px 24px 10px;
            border-bottom: 1px solid var(--border);
            background: #fff;
        }

        .blacklist-record-search-grid {
            display: grid;
            grid-template-columns: minmax(260px, 1fr) 44px 44px 44px;
            gap: 10px;
            align-items: center;
        }

        .blacklist-record-search input {
            width: 100%;
            height: 44px;
            border-radius: 16px;
            border: 1px solid #fecaca;
            box-shadow: 0 0 0 4px rgba(220, 38, 38, .08);
            padding: 0 14px;
            font-size: .86rem;
            font-weight: 850;
            outline: none;
        }

        .blacklist-search-btn {
            width: 44px;
            height: 44px;
            border-radius: 15px;
            display: grid;
            place-items: center;
            cursor: pointer;
            border: 1px solid var(--border);
            font-size: .9rem;
            transition: .18s ease;
        }

        .blacklist-search-btn.red {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            border-color: transparent;
            box-shadow: 0 12px 24px rgba(220,38,38,.18);
        }

        .blacklist-search-btn.light {
            background: #fff;
            color: #475569;
        }

        .blacklist-search-btn.light:hover {
            color: var(--primary);
            border-color: #fecaca;
            background: #fffafa;
        }

        .blacklist-popup-more {
            position: relative;
            width: 44px;
            height: 44px;
        }

        .blacklist-popup-more-menu {
            position: absolute;
            right: 0;
            top: calc(100% + 8px);
            width: 210px;
            border: 1px solid var(--border);
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 20px 45px rgba(15,23,42,.16);
            padding: 8px;
            display: none;
            z-index: 30;
        }

        .blacklist-popup-more.open .blacklist-popup-more-menu {
            display: block;
        }

        .blacklist-popup-more-item {
            width: 100%;
            border: 0;
            background: #fff;
            display: flex;
            align-items: center;
            gap: 9px;
            min-height: 38px;
            padding: 0 10px;
            border-radius: 12px;
            color: #0f172a;
            text-decoration: none;
            font-size: .78rem;
            font-weight: 950;
            cursor: pointer;
            text-align: left;
        }

        .blacklist-popup-more-item:hover {
            background: #f8fafc;
            color: var(--primary);
        }

        .blacklist-cancel-bar {
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 24px;
            border-bottom: 1px solid #fecaca;
            background: linear-gradient(135deg, #fff7f7, #ffffff);
        }

        .blacklist-modal.cancel-mode .blacklist-cancel-bar {
            display: flex;
        }

        .blacklist-cancel-text {
            color: #475569;
            font-size: .82rem;
            font-weight: 850;
        }

        .blacklist-cancel-text strong {
            color: var(--primary);
            font-weight: 950;
        }

        .blacklist-cancel-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .blacklist-cancel-btn {
            min-height: 38px;
            border-radius: 13px;
            border: 1px solid var(--border);
            padding: 0 13px;
            font-weight: 950;
            font-size: .78rem;
            cursor: pointer;
        }

        .blacklist-cancel-btn.light {
            background: #fff;
            color: #0f172a;
        }

        .blacklist-cancel-btn.red {
            border-color: transparent;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            box-shadow: 0 12px 24px rgba(220,38,38,.16);
        }

        .blacklist-select-col {
            display: none;
            width: 44px;
            min-width: 44px;
            text-align: center;
        }

        .blacklist-modal.cancel-mode .blacklist-select-col {
            display: table-cell;
        }

        .blacklist-select-box {
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .blacklist-cancel-check {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
            cursor: pointer;
        }

        .blacklist-cancel-check:disabled {
            cursor: not-allowed;
            opacity: .35;
        }

        .blacklist-modal.cancel-mode .blacklist-modal-row {
            cursor: pointer;
        }

        .blacklist-modal.cancel-mode .blacklist-modal-row.selected-cancel-row {
            background: #fff1f2;
        }

        .blacklist-record-table-wrap {
            min-height: 360px;
            overflow: auto;
            flex: 1 1 auto;
        }

        .blacklist-record-table-wrap::-webkit-scrollbar {
            width: 9px;
        }

        .blacklist-record-table-wrap::-webkit-scrollbar-thumb {
            background: #fecaca;
            border-radius: 999px;
        }

        .blacklist-record-table {
            width: 100%;
            border-collapse: collapse;
        }

        .blacklist-record-table th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f8fafc;
            color: var(--muted);
            font-size: .67rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .06em;
            text-align: left;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
        }

        .blacklist-record-table td {
            padding: 13px 16px;
            border-bottom: 1px solid #eef2f7;
            font-size: .78rem;
            font-weight: 850;
            vertical-align: middle;
            color: #0f172a;
        }

        .blacklist-record-table td.muted {
            color: #64748b;
            font-weight: 800;
        }

        .blacklist-empty-row {
            text-align: center;
            padding: 48px 18px;
            color: #64748b;
            font-weight: 900;
        }

        @media (max-width: 1300px) {
            .blacklist-record-box {
                width: min(1180px, 97vw);
                min-height: 560px;
            }

            .visitor-layout {
                grid-template-columns: 1fr 390px;
            }

            .table-head,
            .visitor-row {
                grid-template-columns: 1.15fr 1.1fr .75fr .5fr;
            }
        }

        @media (max-width: 1080px) {
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

            .sidebar {
                height: auto;
                overflow: visible;
                border-right: 0;
                border-bottom: 1px solid var(--border);
            }

            .main {
                height: auto;
                overflow: visible;
                padding: 22px 18px 50px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .visitor-layout {
                grid-template-columns: 1fr;
                overflow: visible;
            }

            .visitor-scroll {
                max-height: 520px;
            }

            .info-panel {
                min-height: 640px;
            }
        }

        @media (max-width: 760px) {
            .blacklist-record-search-grid {
                grid-template-columns: 1fr 44px 44px 44px;
            }

            .topbar {
                flex-direction: column;
                align-items: flex-start;
            }

            .top-actions,
            .top-btn {
                width: 100%;
            }

            .top-btn {
                justify-content: center;
            }

            .filters {
                grid-template-columns: 1fr;
            }

            .table-head {
                display: none;
            }

            .visitor-row {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .info-buttons {
                grid-template-columns: 1fr;
            }

            .detail-line {
                grid-template-columns: 24px 1fr;
            }

            .detail-value {
                text-align: left;
                max-width: none;
            }
        }
    

        /* Compact Visitor Account Information panel - no inner vertical scroll */
        .info-panel {
            height: 100%;
            overflow: hidden;
        }

        .info-panel .panel-head {
            padding: 13px 18px;
            min-height: 50px;
        }

        .info-profile {
            padding: 4px 0 10px;
            margin-bottom: 8px;
        }

        .info-actions {
            right: 14px;
            top: 8px;
        }

        .round-action {
            width: 30px;
            height: 30px;
            font-size: .78rem;
        }

        .big-avatar {
            width: 104px;
            height: 104px;
            border-radius: 28px;
            font-size: 2.35rem;
            margin: 0 auto 10px;
            box-shadow: 0 16px 32px rgba(220,38,38,.18);
        }

        .big-avatar::before {
            width: 38px;
            height: 38px;
            left: 12px;
            top: 12px;
        }

        .info-name {
            font-size: 1.04rem;
            margin-bottom: 2px;
        }

        .info-sub {
            font-size: .78rem;
            margin-bottom: 8px;
            max-width: calc(100% - 44px);
            margin-left: auto;
            margin-right: auto;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .detail-list {
            flex: 0 0 auto;
            padding: 0 18px 10px;
            min-height: auto;
            overflow-y: visible;
            overflow-x: hidden;
        }

        .detail-list::-webkit-scrollbar {
            display: none;
        }

        .detail-divider {
            margin-bottom: 4px;
        }

        .detail-line {
            grid-template-columns: 20px 86px minmax(0, 1fr);
            gap: 8px;
            padding: 7px 0;
            min-height: 33px;
        }

        .detail-line i {
            font-size: .88rem;
        }

        .detail-label {
            font-size: .72rem;
            font-weight: 850;
        }

        .detail-value {
            font-size: .74rem;
            line-height: 1.22;
        }

        .plate-badge {
            padding: 5px 9px;
            border-radius: 9px;
            font-size: .72rem;
        }

        .edit-section {
            padding: 0 18px 12px;
            overflow: hidden;
        }

        .edit-section input {
            padding: 10px 11px;
            margin-bottom: 8px;
        }
</style>
</head>
<body>

<div class="dashboard-shell">
    <?php require_once __DIR__ . '/admin_sidebar.php'; ?>

    <main class="main">
        <div class="topbar">
            <div>
                <div class="page-kicker">Visitor Management</div>
                <h1 class="page-title">Visitor Accounts</h1>
                <p class="page-sub">
                    Manage visitor login accounts, contact details, visit history summary and blacklist actions.
                </p>
            </div>

            <div class="top-actions">
                <a href="admin_dashboard.php" class="top-btn primary">
                    <i class="fas fa-arrow-left"></i>
                    Dashboard
                </a>
                <div class="profile-trigger"><?= e($adminInitial) ?></div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert success auto-hide-alert"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert error auto-hide-alert"><?= e($error) ?></div>
        <?php endif; ?>

        <section class="stats-grid">
            <div class="stat-card">
                <div>
                    <div class="stat-value"><?= (int)$totalVisitorAccounts ?></div>
                    <div class="stat-label">Total Visitor Accounts</div>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-user-group"></i>
                </div>
            </div>

            <a href="admin_visitor_bookings.php?from=visitor_accounts" class="stat-card clickable" title="Open visitor records">
                <div>
                    <div class="stat-value blue"><?= (int)$totalVisitRecords ?></div>
                    <div class="stat-label">Total Visit Records</div>
                </div>
                <div class="stat-icon blue">
                    <i class="fas fa-clipboard-list"></i>
                </div>
            </a>

            <button type="button" class="stat-card clickable stat-card-button" id="openBlacklistModal" title="View blacklisted plates">
                <div>
                    <div class="stat-value red"><?= (int)$blacklistCount ?></div>
                    <div class="stat-label">Blacklisted Plates</div>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-ban"></i>
                </div>
            </button>
        </section>

        <section class="visitor-layout">
            <div class="panel list-panel">
                <div class="panel-head">
                    <div class="panel-title">
                        <i class="fas fa-user-group"></i>
                        Visitor Accounts
                    </div>
                </div>

                <form method="GET" class="filters">
                    <div>
                        <label>Search</label>
                        <input
                            type="text"
                            name="search"
                            value="<?= e($search) ?>"
                            placeholder="Search visitor name, email, phone, IC or plate..."
                        >
                    </div>

                    <button type="submit" class="icon-btn icon-primary" title="Search">
                        <i class="fas fa-search"></i>
                    </button>

                    <a href="admin_visitor_passes.php" class="icon-btn icon-light" title="Reset">
                        <i class="fas fa-rotate-left"></i>
                    </a>
                </form>

                <div class="table-head">
                    <div>Full Name</div>
                    <div>Email</div>
                    <div>Last Plate</div>
                    <div>Bookings</div>
                </div>

                <?php if (empty($visitorData)): ?>
                    <div class="empty">
                        No visitor account found.
                    </div>
                <?php else: ?>
                    <div class="visitor-scroll">
                        <?php foreach ($visitorData as $index => $visitor): ?>
                            <div
                                class="visitor-row <?= $index === 0 ? 'selected' : '' ?>"
                                data-visitor='<?= e(json_encode($visitor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
                            >
                                <div class="person-cell">
                                    <div class="mini-avatar"><?= e($visitor['initial']) ?></div>
                                    <div style="min-width:0;">
                                        <div class="name-main"><?= e($visitor['name']) ?></div>
                                        <div class="name-sub"><?= e($visitor['phone']) ?></div>
                                    </div>
                                </div>

                                <div>
                                    <div class="name-main"><?= e($visitor['email']) ?></div>
                                </div>

                                <div>
                                    <span class="plate-badge"><?= e($visitor['lastPlate']) ?></span>
                                </div>

                                <div>
                                    <div class="name-main"><?= (int)$visitor['totalBookings'] ?></div>
                                </div>

                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="list-footer">
                        <div>
                            Showing <?= count($visitorData) ?> visitor account<?= count($visitorData) === 1 ? '' : 's' ?>.
                            Blacklist plates: <?= (int)$blacklistCount ?>
                        </div>

                        <div class="pager">
                            <div class="page-dot"><i class="fas fa-chevron-left"></i></div>
                            <div class="page-dot current">1</div>
                            <div class="page-dot"><i class="fas fa-chevron-right"></i></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <aside class="panel info-panel">
                <div class="panel-head">
                    <div class="panel-title">
                        <i class="fas fa-id-card"></i>
                        Visitor Account Information
                    </div>
                </div>

                <div class="info-profile">
                    <div class="info-actions">
                        <a href="<?= e($selected['recordsUrl'] ?? 'admin_visitor_records.php?from=visitor_accounts') ?>" class="round-action" title="Visitor records" id="recordsBtn">
                            <i class="fas fa-clock-rotate-left"></i>
                        </a>
                        <button type="button" class="round-action" title="Edit details" id="editBtn">
                            <i class="fas fa-pen"></i>
                        </button>
                        <button type="button" class="round-action" title="More options" id="moreBtn">
                            <i class="fas fa-ellipsis"></i>
                        </button>

                        <div class="more-menu" id="moreMenu">
                            <button type="button" class="more-item" id="menuEditBtn">
                                <i class="fas fa-pen"></i>
                                Edit details
                            </button>
                            <a href="<?= e($selected['recordsUrl'] ?? 'admin_visitor_records.php?from=visitor_accounts') ?>" class="more-item" id="menuRecordsLink">
                                <i class="fas fa-clock-rotate-left"></i>
                                View visitor records
                            </a>
                            <form method="POST" data-safe-confirm="1" id="menuBlacklistForm">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="blacklist_last_plate">
                                <input type="hidden" name="visitor_id" id="blacklistVisitorId" value="<?= e($selected['id'] ?? '') ?>">
                                <input type="hidden" name="visitor_name" id="blacklistVisitorName" value="<?= e($selected['name'] ?? '') ?>">
                                <input type="hidden" name="plate_no" id="blacklistPlate" value="<?= e($selected['lastPlate'] ?? '') ?>">
                                <button type="submit" class="more-item danger">
                                    <i class="fas fa-ban"></i>
                                    Blacklist last plate
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="big-avatar">
                        <span id="infoAvatar"><?= e($selected['initial'] ?? 'V') ?></span>
                    </div>

                    <div class="info-name" id="infoName"><?= e($selected['name'] ?? '-') ?></div>
                    <div class="info-sub" id="infoEmailSub"><?= e($selected['email'] ?? '-') ?></div>
                </div>

                <div class="detail-list view-section">
                    <div class="detail-divider"></div>

                    <div class="detail-line">
                        <i class="fas fa-envelope"></i>
                        <div class="detail-label">Email</div>
                        <div class="detail-value" id="infoEmail"><?= e($selected['email'] ?? '-') ?></div>
                    </div>

                    <div class="detail-line">
                        <i class="fas fa-phone"></i>
                        <div class="detail-label">Phone</div>
                        <div class="detail-value" id="infoPhone"><?= e($selected['phone'] ?? '-') ?></div>
                    </div>

                    <div class="detail-line">
                        <i class="fas fa-id-card"></i>
                        <div class="detail-label">IC / Passport</div>
                        <div class="detail-value" id="infoIdentity"><?= e($selected['identity'] ?? '-') ?></div>
                    </div>

                    <div class="detail-line">
                        <i class="fas fa-car"></i>
                        <div class="detail-label">Last Plate</div>
                        <div class="detail-value">
                            <span class="plate-badge" id="infoLastPlate"><?= e($selected['lastPlate'] ?? '-') ?></span>
                        </div>
                    </div>

                    <div class="detail-line">
                        <i class="fas fa-calendar-days"></i>
                        <div class="detail-label">Last Visit</div>
                        <div class="detail-value" id="infoLastVisit"><?= e($selected['lastVisit'] ?? '-') ?></div>
                    </div>

                    <div class="detail-line">
                        <i class="fas fa-clipboard-list"></i>
                        <div class="detail-label">Total Bookings</div>
                        <div class="detail-value" id="infoTotalBookings"><?= e($selected['totalBookings'] ?? '0') ?></div>
                    </div>

                    <div class="detail-line">
                        <i class="fas fa-clock"></i>
                        <div class="detail-label">Created</div>
                        <div class="detail-value" id="infoCreated"><?= e($selected['created'] ?? '-') ?></div>
                    </div>
                </div>

                <div class="edit-section">
                    <div class="section-label">Edit Visitor Account</div>

                    <form method="POST" data-safe-confirm="1" id="editProfileForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_profile">
                        <input type="hidden" name="visitor_id" id="editVisitorId" value="<?= e($selected['id'] ?? '') ?>">

                        <?php if ($nameCol): ?>
                            <input type="text" name="full_name" id="editFullName" placeholder="Full name" value="<?= e($selected['name'] ?? '') ?>" required>
                        <?php endif; ?>

                        <input type="email" name="email" id="editEmail" placeholder="Email" value="<?= e($selected['email'] ?? '') ?>" required>

                        <?php if ($contactCol): ?>
                            <input type="text" name="contact_number" id="editPhone" placeholder="Contact number" value="<?= e($selected['phoneRaw'] ?? '') ?>">
                        <?php endif; ?>

                        <?php if ($identityCol): ?>
                            <input type="text" name="identity_number" id="editIdentity" placeholder="IC / Passport" value="<?= e($selected['identityRaw'] ?? '') ?>">
                        <?php endif; ?>

                        <button type="submit" class="btn btn-primary" style="width:100%;height:42px;">
                            <i class="fas fa-floppy-disk"></i>
                            Save Account
                        </button>
                    </form>
                </div>

            </aside>
        </section>
    </main>
</div>

<div class="blacklist-modal" id="blacklistRecordModal" aria-hidden="true">
    <div class="blacklist-record-box" role="dialog" aria-modal="true" aria-labelledby="blacklistRecordTitle">
        <div class="blacklist-record-head">
            <div>
                <div class="blacklist-record-kicker">Blacklist History</div>
                <div class="blacklist-record-title" id="blacklistRecordTitle">Visitor Blacklisted Plates</div>
                <div class="blacklist-record-sub">
                    Shows visitor plates that are blacklisted, their status, reason, and added time.
                </div>
            </div>

            <button type="button" class="blacklist-record-close" id="closeBlacklistModal" aria-label="Close blacklist records">
                <i class="fas fa-xmark"></i>
            </button>
        </div>

        <div class="blacklist-record-stats">
            <div class="blacklist-record-stat">
                <strong><?= (int)$blacklistCount ?></strong>
                <span>Total Records</span>
            </div>

            <div class="blacklist-record-stat">
                <strong class="red"><?= (int)$blacklistActiveCount ?></strong>
                <span>Active Blacklist</span>
            </div>

            <div class="blacklist-record-stat">
                <strong><?= e($latestBlacklistText) ?></strong>
                <span>Latest Added</span>
            </div>
        </div>

        <div class="blacklist-record-search">
            <div class="blacklist-record-search-grid">
                <input
                    type="text"
                    id="blacklistModalSearch"
                    placeholder="Search plate, owner, reason, status or date..."
                    autocomplete="off"
                >

                <button type="button" class="blacklist-search-btn red" id="blacklistModalSearchBtn" title="Search">
                    <i class="fas fa-search"></i>
                </button>

                <button type="button" class="blacklist-search-btn light" id="blacklistModalResetBtn" title="Reset">
                    <i class="fas fa-rotate-left"></i>
                </button>

                <div class="blacklist-popup-more">
                    <button type="button" class="blacklist-search-btn light" id="blacklistPopupMoreBtn" title="More actions">
                        <i class="fas fa-ellipsis"></i>
                    </button>

                    <div class="blacklist-popup-more-menu" id="blacklistPopupMoreMenu">
                        <button type="button" class="blacklist-popup-more-item" id="startCancelBlacklistMode">
                            <i class="fas fa-ban"></i>
                            Cancel Blacklist
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="blacklist-cancel-bar" id="blacklistCancelBar">
            <div class="blacklist-cancel-text">
                <strong id="selectedBlacklistCount">0</strong> blacklist record selected.
            </div>

            <div class="blacklist-cancel-actions">
                <button type="button" class="blacklist-cancel-btn light" id="cancelBlacklistMode">
                    Cancel
                </button>

                <button type="button" class="blacklist-cancel-btn red" id="confirmCancelBlacklist">
                    Confirm Cancel Blacklist
                </button>
            </div>
        </div>

        <form method="POST" id="bulkCancelBlacklistForm" style="display:none;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="bulk_cancel_blacklist">
            <div id="bulkCancelBlacklistInputs"></div>
        </form>

        <div class="blacklist-record-table-wrap">
            <?php if (empty($blacklistModalData)): ?>
                <div class="blacklist-empty-row">
                    <i class="fas fa-ban" style="font-size:2rem;color:#cbd5e1;margin-bottom:10px;"></i>
                    <div>No blacklisted plate found.</div>
                </div>
            <?php else: ?>
                <table class="blacklist-record-table">
                    <thead>
                        <tr>
                            <th class="blacklist-select-col"></th>
                            <th>Plate No</th>
                            <th>Owner / Visitor</th>
                            <th>Reason</th>
                            <th>Added Time</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($blacklistModalData as $blacklistRow): ?>
                            <tr
                                class="blacklist-modal-row"
                                data-status="<?= e($blacklistRow['statusRaw']) ?>"
                                data-plate="<?= e($blacklistRow['plate']) ?>"
                            >
                                <td class="blacklist-select-col">
                                    <label class="blacklist-select-box" title="Select blacklist record">
                                        <input
                                            type="checkbox"
                                            class="blacklist-cancel-check"
                                            value="<?= (int)$blacklistRow['id'] ?>"
                                            data-plate="<?= e($blacklistRow['plate']) ?>"
                                            <?= $blacklistRow['statusRaw'] === 'active' ? '' : 'disabled' ?>
                                        >
                                    </label>
                                </td>
                                <td>
                                    <span class="plate-badge"><?= e($blacklistRow['plate']) ?></span>
                                </td>
                                <td class="muted"><?= e($blacklistRow['owner']) ?></td>
                                <td class="muted"><?= e($blacklistRow['reason']) ?></td>
                                <td><?= e($blacklistRow['created']) ?></td>
                                <td>
                                    <span class="status-pill <?= e($blacklistRow['statusClass']) ?>">
                                        <?= e($blacklistRow['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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
    confirmButtonColor: '#dc2626'
});
</script>
<?php endif; ?>

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
    const blacklistRecordModal = document.getElementById('blacklistRecordModal');
    const openBlacklistModal = document.getElementById('openBlacklistModal');
    const closeBlacklistModal = document.getElementById('closeBlacklistModal');
    const blacklistModalSearch = document.getElementById('blacklistModalSearch');

    function openBlacklistRecords() {
        if (!blacklistRecordModal) return;
        blacklistRecordModal.classList.add('show');
        blacklistRecordModal.setAttribute('aria-hidden', 'false');
        setTimeout(() => blacklistModalSearch?.focus(), 80);
    }

    function closeBlacklistRecords() {
        blacklistRecordModal?.classList.remove('show');
        blacklistRecordModal?.classList.remove('cancel-mode');
        getBlacklistChecks?.().forEach(function (check) {
            check.checked = false;
        });
        updateBlacklistCancelUI?.();
        blacklistRecordModal?.setAttribute('aria-hidden', 'true');
    }

    openBlacklistModal?.addEventListener('click', openBlacklistRecords);
    closeBlacklistModal?.addEventListener('click', closeBlacklistRecords);

    blacklistRecordModal?.addEventListener('click', function (event) {
        if (event.target === blacklistRecordModal) {
            closeBlacklistRecords();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && blacklistRecordModal?.classList.contains('show')) {
            closeBlacklistRecords();
        }
    });

    const blacklistModalSearchBtn = document.getElementById('blacklistModalSearchBtn');
    const blacklistModalResetBtn = document.getElementById('blacklistModalResetBtn');
    const blacklistPopupMoreBtn = document.getElementById('blacklistPopupMoreBtn');
    const blacklistPopupMoreMenu = document.querySelector('.blacklist-popup-more');
    const startCancelBlacklistMode = document.getElementById('startCancelBlacklistMode');
    const cancelBlacklistMode = document.getElementById('cancelBlacklistMode');
    const confirmCancelBlacklist = document.getElementById('confirmCancelBlacklist');
    const selectedBlacklistCount = document.getElementById('selectedBlacklistCount');
    const bulkCancelBlacklistForm = document.getElementById('bulkCancelBlacklistForm');
    const bulkCancelBlacklistInputs = document.getElementById('bulkCancelBlacklistInputs');

    function filterBlacklistModalRows() {
        const keyword = (blacklistModalSearch?.value || '').trim().toLowerCase();

        document.querySelectorAll('.blacklist-modal-row').forEach(function (row) {
            row.style.display = row.textContent.toLowerCase().includes(keyword) ? '' : 'none';
        });
    }

    blacklistModalSearch?.addEventListener('input', filterBlacklistModalRows);
    blacklistModalSearchBtn?.addEventListener('click', filterBlacklistModalRows);

    blacklistModalSearch?.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            filterBlacklistModalRows();
        }
    });

    blacklistModalResetBtn?.addEventListener('click', function () {
        if (blacklistModalSearch) {
            blacklistModalSearch.value = '';
            blacklistModalSearch.focus();
        }

        filterBlacklistModalRows();
    });

    blacklistPopupMoreBtn?.addEventListener('click', function (event) {
        event.stopPropagation();
        blacklistPopupMoreMenu?.classList.toggle('open');
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('.blacklist-popup-more')) {
            blacklistPopupMoreMenu?.classList.remove('open');
        }
    });

    function getBlacklistChecks() {
        return Array.from(document.querySelectorAll('.blacklist-cancel-check')).filter(function (check) {
            return !check.disabled;
        });
    }

    function updateBlacklistCancelUI() {
        const selected = getBlacklistChecks().filter(function (check) {
            return check.checked;
        });

        if (selectedBlacklistCount) {
            selectedBlacklistCount.textContent = String(selected.length);
        }

        getBlacklistChecks().forEach(function (check) {
            const row = check.closest('.blacklist-modal-row');
            if (row) {
                row.classList.toggle('selected-cancel-row', check.checked);
            }
        });
    }

    function enableBlacklistCancelMode() {
        blacklistRecordModal?.classList.add('cancel-mode');
        blacklistPopupMoreMenu?.classList.remove('open');
        updateBlacklistCancelUI();
    }

    function disableBlacklistCancelMode() {
        blacklistRecordModal?.classList.remove('cancel-mode');

        getBlacklistChecks().forEach(function (check) {
            check.checked = false;
        });

        updateBlacklistCancelUI();
    }

    startCancelBlacklistMode?.addEventListener('click', enableBlacklistCancelMode);
    cancelBlacklistMode?.addEventListener('click', disableBlacklistCancelMode);

    document.querySelectorAll('.blacklist-cancel-check').forEach(function (check) {
        check.addEventListener('change', updateBlacklistCancelUI);
    });

    document.querySelectorAll('.blacklist-modal-row').forEach(function (row) {
        row.addEventListener('click', function (event) {
            if (!blacklistRecordModal?.classList.contains('cancel-mode')) return;
            if (event.target.closest('a, button, input, select, textarea, label')) return;

            const check = row.querySelector('.blacklist-cancel-check');
            if (check && !check.disabled) {
                check.checked = !check.checked;
                updateBlacklistCancelUI();
            }
        });
    });

    confirmCancelBlacklist?.addEventListener('click', function () {
        const selected = getBlacklistChecks().filter(function (check) {
            return check.checked;
        });

        if (!selected.length) {
            Swal.fire('No blacklist selected', 'Please tick at least one active blacklist record first.', 'info');
            return;
        }

        const plateList = selected.map(function (check) {
            return check.dataset.plate || check.value;
        }).join(', ');

        Swal.fire({
            icon: 'warning',
            title: 'Cancel selected blacklist?',
            text: plateList,
            showCancelButton: true,
            confirmButtonText: 'Yes, cancel blacklist',
            cancelButtonText: 'Back',
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            reverseButtons: true
        }).then(function (result) {
            if (!result.isConfirmed || !bulkCancelBlacklistForm || !bulkCancelBlacklistInputs) return;

            bulkCancelBlacklistInputs.innerHTML = '';

            selected.forEach(function (check) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'blacklist_ids[]';
                input.value = check.value;
                bulkCancelBlacklistInputs.appendChild(input);
            });

            bulkCancelBlacklistForm.submit();
        });
    });

    document.querySelectorAll('.auto-hide-alert').forEach(function (alertBox) {
        setTimeout(function () {
            alertBox.classList.add('hide');

            setTimeout(function () {
                alertBox.remove();
            }, 450);
        }, 3000);
    });

    const rows = document.querySelectorAll('.visitor-row');
    const infoPanel = document.querySelector('.info-panel');
    const moreBtn = document.getElementById('moreBtn');
    const moreMenu = document.getElementById('moreMenu');

    function setText(id, value) {
        const element = document.getElementById(id);

        if (element) {
            element.textContent = value || '-';
        }
    }

    function setValue(id, value) {
        const element = document.getElementById(id);

        if (element) {
            element.value = value ?? '';
        }
    }

    function setHref(id, value) {
        const element = document.getElementById(id);

        if (element) {
            element.href = value || '#';
        }
    }

    function renderVisitor(data) {
        if (!data) {
            return;
        }

        setText('infoAvatar', data.initial || 'V');
        setText('infoName', data.name);
        setText('infoEmailSub', data.email);
        setText('infoEmail', data.email);
        setText('infoPhone', data.phone);
        setText('infoIdentity', data.identity);
        setText('infoLastPlate', data.lastPlate);
        setText('infoLastVisit', data.lastVisit);
        setText('infoTotalBookings', String(data.totalBookings || 0));
        setText('infoCreated', data.created);

        setValue('editVisitorId', data.id);
        setValue('editFullName', data.name);
        setValue('editEmail', data.email);
        setValue('editPhone', data.phoneRaw);
        setValue('editIdentity', data.identityRaw);

        setValue('blacklistVisitorId', data.id);
        setValue('blacklistVisitorName', data.name);
        setValue('blacklistPlate', data.lastPlate === '-' ? '' : data.lastPlate);

        setHref('recordsBtn', data.recordsUrl);
        setHref('menuRecordsLink', data.recordsUrl);
    }

    function toggleEdit() {
        if (infoPanel) {
            infoPanel.classList.toggle('mode-edit');
        }

        if (moreMenu) {
            moreMenu.classList.remove('open');
        }
    }

    rows.forEach(function (row) {
        row.addEventListener('click', function () {
            rows.forEach(item => item.classList.remove('selected'));
            row.classList.add('selected');

            if (infoPanel) {
                infoPanel.classList.remove('mode-edit');
            }

            renderVisitor(JSON.parse(row.dataset.visitor));
        });
    });

    if (rows.length) {
        renderVisitor(JSON.parse(rows[0].dataset.visitor));
    }

    document.getElementById('editBtn')?.addEventListener('click', toggleEdit);
    document.getElementById('menuEditBtn')?.addEventListener('click', toggleEdit);

    if (moreBtn && moreMenu) {
        moreBtn.addEventListener('click', function (event) {
            event.stopPropagation();
            moreMenu.classList.toggle('open');
        });

        document.addEventListener('click', function (event) {
            if (!moreMenu.contains(event.target)) {
                moreMenu.classList.remove('open');
            }
        });
    }

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

    document.querySelectorAll('form[data-safe-confirm="1"]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            let title = 'Confirm action?';
            let text = 'Please confirm before saving changes.';
            let icon = 'question';
            let confirmText = 'Yes, continue';

            if (form.querySelector('input[name="action"][value="blacklist_last_plate"]')) {
                title = 'Blacklist this plate?';
                text = 'This will block the visitor plate from future access.';
                icon = 'warning';
                confirmText = 'Yes, blacklist';
            }

            Swal.fire({
                icon: icon,
                title: title,
                text: text,
                showCancelButton: true,
                confirmButtonText: confirmText,
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#64748b',
                reverseButtons: true
            }).then(function (result) {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });
});
</script>

</body>
</html>
