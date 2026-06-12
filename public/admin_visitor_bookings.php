<?php
require_once '../core/security.php';
require_login(['admin', 'superadmin']);

$pdo = db();

$visitorMode = 'visits';
$pageTitle = 'Visitor Visits';
$pageSubtitle = 'Combined visitor booking and visitor record page. This page shows waiting entry, checked in, overstay, completed and cancelled visits in one place.';

function vm_has_table(PDO $pdo, string $table): bool {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $s->execute([$table]);
        return (int)$s->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function vm_has_col(PDO $pdo, string $table, string $col): bool {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $s->execute([$table, $col]);
        return (int)$s->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function vm_first_col(PDO $pdo, string $table, array $cols): ?string {
    foreach ($cols as $c) {
        if (vm_has_col($pdo, $table, $c)) {
            return $c;
        }
    }
    return null;
}

function vm_first_table(PDO $pdo, array $tables): ?string {
    foreach ($tables as $t) {
        if (vm_has_table($pdo, $t)) {
            return $t;
        }
    }
    return null;
}

function vm_rows(PDO $pdo, string $sql, array $params = []): array {
    try {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        return $s->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function vm_count(PDO $pdo, string $sql, array $params = []): int {
    try {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        return (int)$s->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function vm_text($v): string {
    return ($v !== null && $v !== '') ? (string)$v : '-';
}

function vm_plate($v): string {
    return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)$v)));
}

function vm_visit_category($purpose, $visitorType = ''): array {
    $text = strtolower(trim((string)$purpose . ' ' . (string)$visitorType));

    if (preg_match('/delivery|grab|foodpanda|lalamove|courier|parcel|package|rider|driver/', $text)) {
        return ['label' => 'Delivery', 'class' => 'type-delivery', 'icon' => 'fas fa-motorcycle'];
    }

    if (preg_match('/family|friend|relative|guest/', $text)) {
        return ['label' => 'Family / Friend', 'class' => 'type-family', 'icon' => 'fas fa-user-group'];
    }

    if (preg_match('/maintenance|repair|service|contractor|technician|cleaner|renovation/', $text)) {
        return ['label' => 'Service', 'class' => 'type-service', 'icon' => 'fas fa-screwdriver-wrench'];
    }

    return ['label' => 'Visitor', 'class' => 'type-visitor', 'icon' => 'fas fa-id-badge'];
}

function vm_is_generated_plate($plate, $purpose = '', $visitorType = ''): bool {
    $p = vm_plate($plate);

    if ($p === '') {
        return false;
    }

    // Delivery bookings without a real plate may store an internal code such as DEL26060501234365.
    // That is not a real car plate, so do not show it as a plate number.
    if (preg_match('/^(DEL|DELIVERY|GRAB|FOODPANDA|FP|PARCEL|COURIER)[0-9]{6,}$/', $p)) {
        return true;
    }

    return false;
}

function vm_display_plate($plate, $purpose = '', $visitorType = ''): string {
    $p = vm_plate($plate);

    if ($p === '' || vm_is_generated_plate($p, $purpose, $visitorType)) {
        return '';
    }

    return $p;
}

function vm_status_class($s): string {
    $s = strtolower((string)$s);
    return match ($s) {
        'completed', 'checked_out', 'checkout', 'closed', 'done' => 'badge-green',
        'approved', 'accepted', 'active', 'confirmed', 'valid', 'allocated' => 'badge-green',
        'checked_in', 'pending', 'waiting' => 'badge-orange',
        'overstay' => 'badge-red',
        'rejected', 'cancelled', 'canceled', 'expired', 'denied' => 'badge-red',
        default => 'badge-gray'
    };
}

function vm_first_apartment(PDO $pdo): ?int {
    try {
        $s = $pdo->query("SELECT id FROM apartments ORDER BY id ASC LIMIT 1");
        $r = $s->fetch();
        return $r ? (int)$r['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function vm_date($v): string {
    if (!$v || $v === '-') return '-';
    $t = strtotime((string)$v);
    return $t ? date('d M Y', $t) : (string)$v;
}

function vm_time($v): string {
    if (!$v || $v === '-') return '-';
    $t = strtotime((string)$v);
    return $t ? date('g:i A', $t) : (string)$v;
}

function vm_datetime($v): string {
    if (!$v || $v === '-') return '-';
    $t = strtotime((string)$v);
    return $t ? date('d M Y, g:i A', $t) : (string)$v;
}

function vm_schedule_period($r): string {
    $start = $r['start_datetime'] ?: (!empty($r['visit_date']) && !empty($r['start_time']) ? $r['visit_date'] . ' ' . $r['start_time'] : null);
    $end = $r['end_datetime'] ?: (!empty($r['visit_date']) && !empty($r['end_time']) ? $r['visit_date'] . ' ' . $r['end_time'] : null);

    if ($start) {
        return vm_date($start) . ' · ' . vm_time($start) . ' - ' . vm_time($end);
    }

    return '-';
}

function vm_scheduled_end_ts(array $r): ?int {
    $visitDate = trim((string)($r['visit_date'] ?? ''));
    $endTime = trim((string)($r['end_time'] ?? ''));

    if ($visitDate !== '' && $endTime !== '') {
        $t = strtotime($visitDate . ' ' . $endTime);
        if ($t) {
            return $t;
        }
    }

    $endDateTime = trim((string)($r['end_datetime'] ?? ''));

    if ($endDateTime !== '') {
        if (preg_match('/\d{4}[-\/]\d{1,2}[-\/]\d{1,2}|\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4}/', $endDateTime) || $visitDate === '') {
            $t = strtotime($endDateTime);
            if ($t) {
                return $t;
            }
        }

        if ($visitDate !== '') {
            $t = strtotime($visitDate . ' ' . $endDateTime);
            if ($t) {
                return $t;
            }
        }
    }

    return null;
}

function vm_is_overstay(array $r): bool {
    if (empty($r['actual_entry_time']) || !empty($r['actual_exit_time'])) {
        return false;
    }

    $endTs = vm_scheduled_end_ts($r);
    return $endTs !== null && time() > $endTs;
}

function vm_unit($r): string {
    if (empty($r['unit_no'])) {
        return 'No unit assigned';
    }
    return 'Block ' . $r['block_no'] . ' / Floor ' . $r['floor_no'] . ' / Unit ' . $r['unit_no'];
}

function vm_period($r): string {
    if (!empty($r['start_datetime']) || !empty($r['end_datetime'])) {
        return vm_date($r['start_datetime'] ?: $r['visit_date']) . ' · ' . vm_time($r['start_datetime']) . ' - ' . vm_time($r['end_datetime']);
    }
    return vm_date($r['visit_date']) . ' · ' . vm_time($r['start_time']) . ' - ' . vm_time($r['end_time']);
}

function vm_visit_day($r): string {
    if (!empty($r['start_datetime'])) {
        return vm_date($r['start_datetime']);
    }
    return vm_date($r['visit_date']);
}

function vm_expected_in($r): string {
    if (!empty($r['start_datetime'])) {
        return vm_time($r['start_datetime']);
    }
    return vm_time($r['start_time']);
}

function vm_expected_out($r): string {
    if (!empty($r['end_datetime'])) {
        return vm_time($r['end_datetime']);
    }
    return vm_time($r['end_time']);
}

function vm_pass_state($endValue, string $status): array {
    $s = strtolower($status);
    $approved = ['approved', 'accepted', 'active', 'confirmed', 'checked_in', 'valid'];
    if (!in_array($s, $approved, true)) {
        return ['label' => ucfirst($s ?: 'Record'), 'class' => vm_status_class($s)];
    }
    if (!$endValue || $endValue === '-') {
        return ['label' => 'Valid', 'class' => 'badge-green'];
    }
    $t = strtotime($endValue);
    if ($t && $t < time()) {
        return ['label' => 'Expired', 'class' => 'badge-red'];
    }
    return ['label' => 'Valid', 'class' => 'badge-green'];
}

function vm_blacklist_plate(PDO $pdo, string $plate, string $reason, int $apartmentId, int $adminId): array {
    $table = vm_first_table($pdo, ['blacklist', 'blacklisted_plates', 'vehicle_blacklist', 'plate_blacklist']);
    if (!$table) {
        return ['ok' => false, 'message' => 'Blacklist table not found.'];
    }

    $plateCol = vm_first_col($pdo, $table, ['plate_no', 'plate_number', 'vehicle_plate', 'car_plate']);
    if (!$plateCol) {
        return ['ok' => false, 'message' => 'Blacklist table plate column not found.'];
    }

    $reasonCol = vm_first_col($pdo, $table, ['reason', 'blacklist_reason', 'remarks', 'note']);
    $statusCol = vm_first_col($pdo, $table, ['status', 'blacklist_status']);
    $aptCol = vm_first_col($pdo, $table, ['apartment_id']);
    $createdAtCol = vm_first_col($pdo, $table, ['created_at', 'date_added']);
    $createdByCol = vm_first_col($pdo, $table, ['created_by', 'admin_id', 'added_by']);

    $where = ["`$plateCol` = ?"];
    $params = [$plate];
    if ($aptCol) {
        $where[] = "`$aptCol` = ?";
        $params[] = $apartmentId;
    }

    $exists = vm_count($pdo, "SELECT COUNT(*) FROM `$table` WHERE " . implode(' AND ', $where), $params);
    if ($exists > 0) {
        return ['ok' => false, 'message' => 'This plate is already blacklisted.'];
    }

    $cols = [$plateCol];
    $vals = ['?'];
    $insert = [$plate];

    if ($reasonCol) {
        $cols[] = $reasonCol;
        $vals[] = '?';
        $insert[] = $reason;
    }
    if ($statusCol) {
        $cols[] = $statusCol;
        $vals[] = '?';
        $insert[] = 'active';
    }
    if ($aptCol) {
        $cols[] = $aptCol;
        $vals[] = '?';
        $insert[] = $apartmentId;
    }
    if ($createdByCol) {
        $cols[] = $createdByCol;
        $vals[] = '?';
        $insert[] = $adminId;
    }
    if ($createdAtCol) {
        $cols[] = $createdAtCol;
        $vals[] = 'NOW()';
    }

    $colSql = implode(', ', array_map(fn($c) => "`$c`", $cols));
    $valSql = implode(', ', $vals);

    $s = $pdo->prepare("INSERT INTO `$table` ($colSql) VALUES ($valSql)");
    $s->execute($insert);

    if (function_exists('log_audit')) {
        log_audit('VISITOR_PLATE_BLACKLISTED', 'Admin blacklisted visitor plate ' . $plate);
    }

    return ['ok' => true, 'message' => 'Visitor plate blacklisted successfully.'];
}

$hasUserApartmentId = vm_has_col($pdo, 'users', 'apartment_id');
$hasFullName = vm_has_col($pdo, 'users', 'full_name');

$currentRole = $_SESSION['role'] ?? 'admin';
$currentEmail = $_SESSION['email'] ?? 'admin@apt.com';
$currentUserId = (int)($_SESSION['uid'] ?? $_SESSION['user_id'] ?? 0);
$currentApartmentId = $_SESSION['apartment_id'] ?? null;

if ($currentUserId <= 0 && $currentEmail !== '') {
    $r = vm_rows($pdo, "SELECT id FROM users WHERE email = ? LIMIT 1", [$currentEmail]);
    if ($r) {
        $currentUserId = (int)$r[0]['id'];
        $_SESSION['user_id'] = $currentUserId;
    }
}

if (($currentApartmentId === null || $currentApartmentId === '') && $currentUserId > 0 && $hasUserApartmentId) {
    $r = vm_rows($pdo, "SELECT apartment_id FROM users WHERE id = ? LIMIT 1", [$currentUserId]);
    if ($r && $r[0]['apartment_id'] !== null && $r[0]['apartment_id'] !== '') {
        $currentApartmentId = (int)$r[0]['apartment_id'];
        $_SESSION['apartment_id'] = $currentApartmentId;
    }
}

if ($currentRole === 'superadmin' && isset($_GET['apartment_id']) && $_GET['apartment_id'] !== '') {
    $currentApartmentId = (int)$_GET['apartment_id'];
}

if (($currentApartmentId === null || $currentApartmentId === '')) {
    $currentApartmentId = vm_first_apartment($pdo);
}

$currentApartmentName = 'No Apartment Assigned';
if (!empty($currentApartmentId)) {
    $r = vm_rows($pdo, "SELECT apartment_name FROM apartments WHERE id = ? LIMIT 1", [(int)$currentApartmentId]);
    $currentApartmentName = $r ? $r[0]['apartment_name'] : 'Apartment ID ' . (int)$currentApartmentId;
}

$message = $_SESSION['flash_success'] ?? '';
$error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

if (empty($currentApartmentId)) {
    $error = 'This admin account is not assigned to any apartment.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        try {
            if (($_POST['action'] ?? '') === 'blacklist_plate') {
                $plate = vm_plate($_POST['plate_no'] ?? '');
                if ($plate === '') {
                    throw new Exception('Plate number is empty.');
                }
                $result = vm_blacklist_plate($pdo, $plate, trim($_POST['reason'] ?? 'Blacklisted from Visitor Management'), (int)$currentApartmentId, (int)$currentUserId);
                if (!$result['ok']) {
                    throw new Exception($result['message']);
                }
                $message = $result['message'];
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    if ($message !== '') $_SESSION['flash_success'] = $message;
    if ($error !== '') $_SESSION['flash_error'] = $error;

    header('Location: ' . basename($_SERVER['PHP_SELF']));
    exit;
}

$bookingExists = vm_has_table($pdo, 'bookings');
$gateLogsExists = vm_has_table($pdo, 'gate_logs');
if (!$bookingExists) {
    $error = 'Bookings table not found.';
}

$visitorNameCol = $bookingExists ? vm_first_col($pdo, 'bookings', ['visitor_name', 'guest_name', 'name', 'full_name']) : null;
$visitorEmailCol = $bookingExists ? vm_first_col($pdo, 'bookings', ['visitor_email', 'guest_email', 'email']) : null;
$visitorPhoneCol = $bookingExists ? vm_first_col($pdo, 'bookings', ['visitor_phone', 'guest_phone', 'phone', 'contact_number']) : null;
$plateCol = $bookingExists ? vm_first_col($pdo, 'bookings', ['plate_no', 'plate_number', 'vehicle_plate', 'car_plate']) : null;
$statusCol = $bookingExists ? vm_first_col($pdo, 'bookings', ['status', 'booking_status', 'approval_status']) : null;
$residentIdCol = $bookingExists ? vm_first_col($pdo, 'bookings', ['resident_id', 'host_resident_id', 'user_id']) : null;
$visitorUserIdCol = $bookingExists ? vm_first_col($pdo, 'bookings', ['visitor_user_id', 'visitor_id', 'guest_user_id']) : null;
$unitIdCol = $bookingExists ? vm_first_col($pdo, 'bookings', ['unit_id']) : null;
$apartmentIdCol = $bookingExists ? vm_first_col($pdo, 'bookings', ['apartment_id']) : null;
$purposeCol = $bookingExists ? vm_first_col($pdo, 'bookings', ['purpose', 'visit_purpose', 'reason']) : null;
$visitorTypeCol = $bookingExists ? vm_first_col($pdo, 'bookings', ['visitor_type', 'guest_type', 'visit_category', 'category']) : null;
$createdAtCol = $bookingExists ? vm_first_col($pdo, 'bookings', ['created_at', 'submitted_at', 'booking_created_at']) : null;
$visitDateCol = $bookingExists ? vm_first_col($pdo, 'bookings', ['visit_date', 'booking_date', 'date']) : null;
$startTimeCol = $bookingExists ? vm_first_col($pdo, 'bookings', ['start_time', 'visit_start_time', 'time_in', 'arrival_time']) : null;
$endTimeCol = $bookingExists ? vm_first_col($pdo, 'bookings', ['end_time', 'visit_end_time', 'time_out', 'departure_time']) : null;
$startDateTimeCol = $bookingExists ? vm_first_col($pdo, 'bookings', ['visit_start', 'start_datetime', 'start_time', 'valid_from']) : null;
$endDateTimeCol = $bookingExists ? vm_first_col($pdo, 'bookings', ['visit_end', 'end_datetime', 'end_time', 'valid_until', 'expired_at']) : null;
$exitCol = $bookingExists ? vm_first_col($pdo, 'bookings', ['actual_exit_at', 'exit_time', 'checked_out_at', 'out_time']) : null;
$entryCol = $bookingExists ? vm_first_col($pdo, 'bookings', ['actual_entry_at', 'entry_time', 'checked_in_at', 'checkin_time', 'in_time', 'actual_checkin_at']) : null;
$updatedAtCol = $bookingExists ? vm_first_col($pdo, 'bookings', ['updated_at', 'modified_at']) : null;
$passCol = $bookingExists ? vm_first_col($pdo, 'bookings', ['qr_token', 'pass_code', 'visitor_pass_code', 'pass_token', 'qr_code']) : null;
$glBookingCol = $gateLogsExists ? vm_first_col($pdo, 'gate_logs', ['booking_id']) : null;
$glActionCol = $gateLogsExists ? vm_first_col($pdo, 'gate_logs', ['gate_action', 'action']) : null;
$glDecisionCol = $gateLogsExists ? vm_first_col($pdo, 'gate_logs', ['decision', 'status']) : null;
$glTimeCol = $gateLogsExists ? vm_first_col($pdo, 'gate_logs', ['action_time', 'created_at']) : null;

/*
|--------------------------------------------------------------------------
| Auto-cancel no-show bookings
|--------------------------------------------------------------------------
| If the visitor does not check in within 1 hour after Expected In time,
| the booking is automatically cancelled and removed from active bookings.
*/
$autoCancelledNoShowCount = 0;

if ($bookingExists && $statusCol) {
    $noShowExpr = null;

    if ($startDateTimeCol) {
        $noShowExpr = "DATE_ADD(b.`$startDateTimeCol`, INTERVAL 1 HOUR) < NOW()";
    } elseif ($visitDateCol && $startTimeCol) {
        $noShowExpr = "DATE_ADD(CONCAT(b.`$visitDateCol`, ' ', b.`$startTimeCol`), INTERVAL 1 HOUR) < NOW()";
    } elseif ($visitDateCol) {
        $noShowExpr = "b.`$visitDateCol` < CURDATE()";
    }

    if ($noShowExpr) {
        $entryWhere = "1=1";

        if ($entryCol) {
            $entryWhere = "(b.`$entryCol` IS NULL OR b.`$entryCol` = '')";
        }

        $cancelStatus = 'cancelled';

        try {
            $statusTypeStmt = $pdo->prepare("
                SELECT COLUMN_TYPE
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'bookings'
                AND COLUMN_NAME = ?
                LIMIT 1
            ");
            $statusTypeStmt->execute([$statusCol]);
            $statusType = (string)$statusTypeStmt->fetchColumn();

            if ($statusType !== '') {
                if (stripos($statusType, "'cancelled'") !== false) {
                    $cancelStatus = 'cancelled';
                } elseif (stripos($statusType, "'canceled'") !== false) {
                    $cancelStatus = 'canceled';
                } elseif (stripos($statusType, "'expired'") !== false) {
                    $cancelStatus = 'expired';
                } elseif (stripos($statusType, "'rejected'") !== false) {
                    $cancelStatus = 'rejected';
                }
            }
        } catch (Throwable $e) {
            $cancelStatus = 'cancelled';
        }

        $updateSql = "
            UPDATE bookings b
            SET b.`$statusCol` = " . $pdo->quote($cancelStatus) . "
            " . ($updatedAtCol ? ", b.`$updatedAtCol` = NOW()" : "") . "
            WHERE LOWER(CAST(b.`$statusCol` AS CHAR)) IN ('pending','approved','accepted','active','confirmed','valid','allocated','waiting')
            AND $noShowExpr
            AND $entryWhere
        ";

        try {
            $pdo->exec($updateSql);
            $autoCancelledNoShowCount = $pdo->rowCount();
        } catch (Throwable $e) {
            $autoCancelledNoShowCount = 0;
        }
    }
}

$search = trim($_GET['search'] ?? '');
$blockFilter = trim($_GET['block'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$visitorIdFilter = (int)($_GET['visitor_id'] ?? 0);
$selectedDate = trim($_GET['visit_date'] ?? '');

if ($selectedDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = '';
}

$blockOptions = [];
if (!empty($currentApartmentId) && vm_has_table($pdo, 'units') && vm_has_col($pdo, 'units', 'block_no')) {
    $blockRows = vm_rows($pdo, "
        SELECT DISTINCT block_no
        FROM units
        WHERE apartment_id = ?
        AND block_no IS NOT NULL
        AND block_no <> ''
        ORDER BY block_no ASC
    ", [(int)$currentApartmentId]);

    foreach ($blockRows as $blockRow) {
        $blockOptions[] = (string)($blockRow['block_no'] ?? '');
    }
}

if ($blockFilter !== '' && !in_array($blockFilter, $blockOptions, true)) {
    $blockFilter = '';
}

$allowedStatusFilters = ['', 'upcoming', 'waiting_entry', 'inside', 'overstay', 'completed', 'cancelled', 'pending_approval'];
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = '';
}

/*
 * Expected In / Out expressions for filtering.
 * In this project, start_time and end_time are usually DATETIME columns.
 */
$expectedInExpr = null;
$expectedOutExpr = null;

if ($startDateTimeCol) {
    $expectedInExpr = "b.`$startDateTimeCol`";
} elseif ($visitDateCol && $startTimeCol) {
    $expectedInExpr = "CONCAT(b.`$visitDateCol`, ' ', b.`$startTimeCol`)";
} elseif ($visitDateCol) {
    $expectedInExpr = "b.`$visitDateCol`";
}

if ($endDateTimeCol) {
    $expectedOutExpr = "b.`$endDateTimeCol`";
} elseif ($visitDateCol && $endTimeCol) {
    $expectedOutExpr = "CONCAT(b.`$visitDateCol`, ' ', b.`$endTimeCol`)";
}

$activeStatusList = "'completed','checked_out','checkout','done','closed','expired','rejected','cancelled','canceled'";
$entryReadyStatusList = "'approved','accepted','active','confirmed','valid','allocated','waiting'";

$select = [
    "b.id AS booking_id",
    $visitorNameCol ? "COALESCE(NULLIF(b.`$visitorNameCol`, ''), CONCAT('Visitor #', b.id)) AS visitor_name" : "CONCAT('Visitor #', b.id) AS visitor_name",
    $visitorEmailCol ? "b.`$visitorEmailCol` AS visitor_email" : "NULL AS visitor_email",
    $visitorPhoneCol ? "b.`$visitorPhoneCol` AS visitor_phone" : "NULL AS visitor_phone",
    $plateCol ? "b.`$plateCol` AS plate_no" : "NULL AS plate_no",
    $statusCol ? "b.`$statusCol` AS booking_status" : "'record' AS booking_status",
    $purposeCol ? "b.`$purposeCol` AS purpose" : "NULL AS purpose",
    $visitorTypeCol ? "b.`$visitorTypeCol` AS visitor_type" : "NULL AS visitor_type",
    $createdAtCol ? "b.`$createdAtCol` AS created_at" : "NULL AS created_at",
    $visitDateCol ? "b.`$visitDateCol` AS visit_date" : "NULL AS visit_date",
    $startTimeCol ? "b.`$startTimeCol` AS start_time" : "NULL AS start_time",
    $endTimeCol ? "b.`$endTimeCol` AS end_time" : "NULL AS end_time",
    $startDateTimeCol ? "b.`$startDateTimeCol` AS start_datetime" : "NULL AS start_datetime",
    $endDateTimeCol ? "b.`$endDateTimeCol` AS end_datetime" : "NULL AS end_datetime",
    $exitCol ? "b.`$exitCol` AS exit_time" : "NULL AS exit_time",
    $passCol ? "b.`$passCol` AS pass_code" : "NULL AS pass_code",
    $residentIdCol ? "b.`$residentIdCol` AS resident_id" : "NULL AS resident_id",
    $visitorUserIdCol ? "b.`$visitorUserIdCol` AS visitor_user_id" : "NULL AS visitor_user_id",
    $hasFullName ? "COALESCE(NULLIF(r.full_name, ''), r.email, '-') AS resident_name" : "COALESCE(r.email, '-') AS resident_name",
    "r.email AS resident_email",
    "un.block_no",
    "un.floor_no",
    "un.unit_no"
];

$joins = [];
$joins[] = $residentIdCol ? "LEFT JOIN users r ON r.id = b.`$residentIdCol`" : "LEFT JOIN users r ON 1 = 0";

if ($unitIdCol) {
    $joins[] = "LEFT JOIN units un ON un.id = b.`$unitIdCol`";
} elseif ($residentIdCol) {
    $joins[] = "LEFT JOIN (SELECT resident_id, MIN(unit_id) AS unit_id FROM resident_units WHERE status = 'active' GROUP BY resident_id) ru ON ru.resident_id = b.`$residentIdCol`";
    $joins[] = "LEFT JOIN units un ON un.id = ru.unit_id";
} else {
    $joins[] = "LEFT JOIN units un ON 1 = 0";
}

if ($gateLogsExists && $glBookingCol && $glActionCol && $glTimeCol) {
    $entryDecisionWhere = $glDecisionCol ? "AND UPPER(CAST(`$glDecisionCol` AS CHAR)) = 'ALLOW'" : '';
    $exitDecisionWhere = $glDecisionCol ? "AND UPPER(CAST(`$glDecisionCol` AS CHAR)) = 'ALLOW'" : '';

    $joins[] = "LEFT JOIN (
        SELECT `$glBookingCol` AS booking_id, MIN(`$glTimeCol`) AS entry_time
        FROM gate_logs
        WHERE UPPER(CAST(`$glActionCol` AS CHAR)) = 'ENTRY' $entryDecisionWhere
        GROUP BY `$glBookingCol`
    ) gle ON gle.booking_id = b.id";

    $joins[] = "LEFT JOIN (
        SELECT `$glBookingCol` AS booking_id, MAX(`$glTimeCol`) AS exit_time
        FROM gate_logs
        WHERE UPPER(CAST(`$glActionCol` AS CHAR)) = 'EXIT' $exitDecisionWhere
        GROUP BY `$glBookingCol`
    ) glx ON glx.booking_id = b.id";

    $actualEntryExpr = $entryCol ? "COALESCE(gle.entry_time, b.`$entryCol`)" : "gle.entry_time";
    $actualExitExpr = $exitCol ? "COALESCE(glx.exit_time, b.`$exitCol`)" : "glx.exit_time";
} else {
    $actualEntryExpr = $entryCol ? "b.`$entryCol`" : 'NULL';
    $actualExitExpr = $exitCol ? "b.`$exitCol`" : 'NULL';
}

$select[] = "$actualEntryExpr AS actual_entry_time";
$select[] = "$actualExitExpr AS actual_exit_time";

$where = [];
$params = [];

if ($apartmentIdCol) {
    /*
     * Some older visitor/resident booking insert pages saved apartment_id as NULL.
     * Still show those bookings by checking the resident's apartment or the linked unit.
     */
    if ($hasUserApartmentId && $residentIdCol) {
        $where[] = "(b.`$apartmentIdCol` = ? OR (b.`$apartmentIdCol` IS NULL AND (r.apartment_id = ? OR un.apartment_id = ?)))";
        $params[] = (int)$currentApartmentId;
        $params[] = (int)$currentApartmentId;
        $params[] = (int)$currentApartmentId;
    } else {
        $where[] = "(b.`$apartmentIdCol` = ? OR (b.`$apartmentIdCol` IS NULL AND un.apartment_id = ?))";
        $params[] = (int)$currentApartmentId;
        $params[] = (int)$currentApartmentId;
    }
} elseif ($hasUserApartmentId && $residentIdCol) {
    $where[] = "(r.apartment_id = ? OR un.apartment_id = ?)";
    $params[] = (int)$currentApartmentId;
    $params[] = (int)$currentApartmentId;
} else {
    $where[] = "un.apartment_id = ?";
    $params[] = (int)$currentApartmentId;
}

if ($visitorMode === 'passes' && $statusCol) {
    $where[] = "LOWER(CAST(b.`$statusCol` AS CHAR)) IN ('approved','accepted','active','confirmed','checked_in','valid')";
}

if ($visitorMode === 'overstay') {
    if ($statusCol) {
        $where[] = "LOWER(CAST(b.`$statusCol` AS CHAR)) IN ('approved','accepted','active','confirmed','checked_in','valid')";
    }
    if ($endDateTimeCol) {
        $where[] = "b.`$endDateTimeCol` < NOW()";
    } elseif ($visitDateCol && $endTimeCol) {
        $where[] = "CONCAT(b.`$visitDateCol`, ' ', b.`$endTimeCol`) < NOW()";
    } else {
        $where[] = "1 = 0";
    }
    if ($exitCol) {
        $where[] = "(b.`$exitCol` IS NULL OR b.`$exitCol` = '')";
    }
}

if ($visitorIdFilter > 0 && $visitorUserIdCol) {
    $where[] = "b.`$visitorUserIdCol` = ?";
    $params[] = $visitorIdFilter;
}

$dateExpr = null;

if ($startDateTimeCol) {
    $dateExpr = "DATE(b.`$startDateTimeCol`)";
} elseif ($visitDateCol) {
    $dateExpr = "DATE(b.`$visitDateCol`)";
} elseif ($createdAtCol) {
    $dateExpr = "DATE(b.`$createdAtCol`)";
}

if ($dateExpr && $selectedDate !== '') {
    $where[] = "$dateExpr = ?";
    $params[] = $selectedDate;
}

if ($blockFilter !== '') {
    $where[] = "un.block_no = ?";
    $params[] = $blockFilter;
}

if ($search !== '') {
    $term = '%' . $search . '%';
    $parts = [];

    foreach ([$visitorNameCol, $visitorEmailCol, $visitorPhoneCol, $plateCol, $purposeCol, $visitorTypeCol] as $col) {
        if ($col) {
            $parts[] = "b.`$col` LIKE ?";
            $params[] = $term;
        }
    }

    if ($residentIdCol) {
        if ($hasFullName) {
            $parts[] = "r.full_name LIKE ?";
            $params[] = $term;
        }
        $parts[] = "r.email LIKE ?";
        $params[] = $term;
    }

    $parts[] = "un.unit_no LIKE ?";
    $params[] = $term;

    $where[] = '(' . implode(' OR ', $parts) . ')';
}

if ($statusFilter !== '' && $statusCol) {
    $statusExpr = "LOWER(CAST(b.`$statusCol` AS CHAR))";

    if ($statusFilter === 'upcoming') {
        if ($expectedInExpr) {
            $where[] = "$expectedInExpr > NOW()";
        } elseif ($dateExpr) {
            $where[] = "$dateExpr > CURDATE()";
        }
        $where[] = "$statusExpr IN ($entryReadyStatusList)";
        $where[] = "($actualEntryExpr IS NULL OR $actualEntryExpr = '')";
    } elseif ($statusFilter === 'waiting_entry') {
        $where[] = "$statusExpr IN ($entryReadyStatusList)";
        $where[] = "($actualEntryExpr IS NULL OR $actualEntryExpr = '')";
    } elseif ($statusFilter === 'inside') {
        $where[] = "($statusExpr = 'checked_in' OR ($actualEntryExpr IS NOT NULL AND $actualEntryExpr <> ''))";
        $where[] = "($actualExitExpr IS NULL OR $actualExitExpr = '')";
        if ($expectedOutExpr) {
            $where[] = "$expectedOutExpr >= NOW()";
        }
    } elseif ($statusFilter === 'overstay') {
        $where[] = "($actualEntryExpr IS NOT NULL AND $actualEntryExpr <> '')";
        $where[] = "($actualExitExpr IS NULL OR $actualExitExpr = '')";
        if ($expectedOutExpr) {
            $where[] = "$expectedOutExpr < NOW()";
        } else {
            $where[] = "1 = 0";
        }
    } elseif ($statusFilter === 'completed') {
        $where[] = "($statusExpr IN ('completed','checked_out','checkout','done','closed') OR ($actualExitExpr IS NOT NULL AND $actualExitExpr <> ''))";
    } elseif ($statusFilter === 'cancelled') {
        $where[] = "$statusExpr IN ('cancelled','canceled','expired','rejected','denied')";
    } elseif ($statusFilter === 'pending_approval') {
        $where[] = "$statusExpr = 'pending'";
    }
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$overstayOrderExpr = $expectedOutExpr
    ? "CASE WHEN ($actualEntryExpr IS NOT NULL AND $actualEntryExpr <> '') AND ($actualExitExpr IS NULL OR $actualExitExpr = '') AND $expectedOutExpr < NOW() THEN 0 ELSE 1 END"
    : "1";
$insideOrderExpr = "CASE WHEN ($actualEntryExpr IS NOT NULL AND $actualEntryExpr <> '') AND ($actualExitExpr IS NULL OR $actualExitExpr = '') THEN 0 ELSE 1 END";
$completedOrderExpr = "CASE WHEN ($actualExitExpr IS NOT NULL AND $actualExitExpr <> '') THEN 1 ELSE 0 END";

if ($dateExpr) {
    $datePriority = "CASE WHEN $dateExpr = CURDATE() THEN 0 WHEN $dateExpr > CURDATE() THEN 1 ELSE 2 END";
    if ($startDateTimeCol) {
        $orderSql = "$overstayOrderExpr, $insideOrderExpr, $completedOrderExpr, $datePriority, b.`$startDateTimeCol` DESC, b.id DESC";
    } elseif ($visitDateCol && $startTimeCol) {
        $orderSql = "$overstayOrderExpr, $insideOrderExpr, $completedOrderExpr, $datePriority, b.`$visitDateCol` DESC, b.`$startTimeCol` DESC, b.id DESC";
    } elseif ($visitDateCol) {
        $orderSql = "$overstayOrderExpr, $insideOrderExpr, $completedOrderExpr, $datePriority, b.`$visitDateCol` DESC, b.id DESC";
    } else {
        $orderSql = "$overstayOrderExpr, $insideOrderExpr, b.id DESC";
    }
} else {
    $orderSql = "$overstayOrderExpr, $insideOrderExpr, b.id DESC";
}


$records = [];
if ($bookingExists) {
    $records = vm_rows($pdo, "
        SELECT " . implode(",\n", $select) . "
        FROM bookings b
        " . implode("\n", $joins) . "
        $whereSql
        ORDER BY $orderSql
        LIMIT 800
    ", $params);
}

$totalShown = count($records);

$totalVisitsCount = 0;
$todayCount = 0;
$waitingEntryCount = 0;
$checkedInCount = 0;

if ($bookingExists) {
    if ($apartmentIdCol) {
        if ($hasUserApartmentId && $residentIdCol) {
            $bookingScopeWhere = "(b.`$apartmentIdCol` = ? OR (b.`$apartmentIdCol` IS NULL AND (r.apartment_id = ? OR un.apartment_id = ?)))";
            $bookingScopeParams = [(int)$currentApartmentId, (int)$currentApartmentId, (int)$currentApartmentId];
        } else {
            $bookingScopeWhere = "(b.`$apartmentIdCol` = ? OR (b.`$apartmentIdCol` IS NULL AND un.apartment_id = ?))";
            $bookingScopeParams = [(int)$currentApartmentId, (int)$currentApartmentId];
        }
    } elseif ($hasUserApartmentId && $residentIdCol) {
        $bookingScopeWhere = "(r.apartment_id = ? OR un.apartment_id = ?)";
        $bookingScopeParams = [(int)$currentApartmentId, (int)$currentApartmentId];
    } else {
        $bookingScopeWhere = "un.apartment_id = ?";
        $bookingScopeParams = [(int)$currentApartmentId];
    }

    $joinSqlForCount = implode("\n", $joins);
    $statusExprForCount = $statusCol ? "LOWER(CAST(b.`$statusCol` AS CHAR))" : "''";

    $totalVisitsCount = vm_count(
        $pdo,
        "SELECT COUNT(DISTINCT b.id) FROM bookings b $joinSqlForCount WHERE $bookingScopeWhere",
        $bookingScopeParams
    );

    if ($dateExpr) {
        $todayCount = vm_count(
            $pdo,
            "SELECT COUNT(DISTINCT b.id) FROM bookings b $joinSqlForCount WHERE $bookingScopeWhere AND $dateExpr = CURDATE()",
            $bookingScopeParams
        );
    }

    $waitingEntryCount = vm_count(
        $pdo,
        "SELECT COUNT(DISTINCT b.id) FROM bookings b $joinSqlForCount WHERE $bookingScopeWhere AND $statusExprForCount IN ($entryReadyStatusList) AND ($actualEntryExpr IS NULL OR $actualEntryExpr = '')",
        $bookingScopeParams
    );

    $insideWhere = "($actualEntryExpr IS NOT NULL AND $actualEntryExpr <> '') AND ($actualExitExpr IS NULL OR $actualExitExpr = '')";
    if ($expectedOutExpr) {
        $insideWhere .= " AND $expectedOutExpr >= NOW()";
    }

    $checkedInCount = vm_count(
        $pdo,
        "SELECT COUNT(DISTINCT b.id) FROM bookings b $joinSqlForCount WHERE $bookingScopeWhere AND $insideWhere",
        $bookingScopeParams
    );
}


$blacklistTable = vm_first_table($pdo, ['blacklist', 'blacklisted_plates', 'vehicle_blacklist', 'plate_blacklist']);
$blacklistCount = 0;

if ($blacklistTable) {
    $aptCol = vm_first_col($pdo, $blacklistTable, ['apartment_id']);
    $blacklistCount = $aptCol
        ? vm_count($pdo, "SELECT COUNT(*) FROM `$blacklistTable` WHERE `$aptCol` = ?", [(int)$currentApartmentId])
        : vm_count($pdo, "SELECT COUNT(*) FROM `$blacklistTable`");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= e($pageTitle) ?> - <?= e(APP_NAME) ?></title>
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
            --orange: #f97316;
            --green: #16a34a;
            --blue: #2563eb;
            --text: #111827;
            --muted: #64748b;
            --border: #e5e7eb;
            --shadow: 0 18px 45px rgba(15, 23, 42, 0.08);
            --shadow-soft: 0 10px 25px rgba(15, 23, 42, 0.06);
        }

        * { box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; margin: 0; padding: 0; }
        html, body { height: 100%; overflow: hidden; }
        body {
            min-height: 100vh;
            background: radial-gradient(circle at 85% 5%, rgba(220, 38, 38, 0.12), transparent 28%), linear-gradient(135deg, #fff7f7 0%, #f4f6fb 45%, #eef2f7 100%);
            color: var(--text);
        }
        a { color: inherit; }
        .dashboard-shell { display: grid; grid-template-columns: 260px 1fr; height: 100vh; min-height: 100vh; overflow: hidden; }
        .sidebar { background: rgba(255, 255, 255, 0.94); backdrop-filter: blur(20px); border-right: 1px solid rgba(229, 231, 235, 0.9); padding: 22px 18px; height: 100vh; overflow: hidden; z-index: 20; }
        .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 22px; padding: 6px 8px; }
        .brand-icon { width: 44px; height: 44px; border-radius: 16px; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); display: grid; place-items: center; color: white; box-shadow: 0 14px 30px rgba(220, 38, 38, 0.28); }
        .brand-title { font-weight: 900; letter-spacing: -0.04em; font-size: 1.08rem; line-height: 1.1; }
        .brand-title span { color: var(--primary); }
        .brand-sub { font-size: .7rem; color: var(--muted); font-weight: 800; text-transform: uppercase; letter-spacing: .08em; margin-top: 3px; }
        .tenant-card { background: #fff7f7; border: 1px solid #fecaca; border-radius: 20px; padding: 13px 14px; margin-bottom: 20px; display: flex; gap: 11px; align-items: center; }
        .tenant-icon { width: 38px; height: 38px; border-radius: 14px; background: var(--primary-soft); color: var(--primary); display: grid; place-items: center; flex: 0 0 auto; }
        .tenant-label { color: var(--muted); font-size: .64rem; font-weight: 950; text-transform: uppercase; letter-spacing: .07em; margin-bottom: 3px; }
        .tenant-name { font-size: .8rem; font-weight: 950; line-height: 1.28; color: #111827; word-break: break-word; }
        .side-section { margin: 20px 0 10px; color: #9ca3af; font-size: .68rem; text-transform: uppercase; letter-spacing: .1em; font-weight: 900; padding: 0 10px; }
        .side-nav { display: grid; gap: 6px; }
        .side-link { width: 100%; border: 0; display: flex; align-items: center; gap: 11px; padding: 11px 12px; border-radius: 15px; text-decoration: none; color: #475569; font-size: .82rem; font-weight: 850; transition: .2s ease; background: transparent; cursor: pointer; text-align: left; }
        .side-link i { width: 18px; text-align: center; color: #94a3b8; transition: .2s ease; }
        .side-link:hover, .side-link.current { background: var(--primary-soft-2); color: var(--primary); }
        .side-link:hover i, .side-link.current i { color: var(--primary); }
        .side-link.logout { color: #991b1b; background: #fff1f2; }
        .side-parent { margin-top: 4px; }
        .side-link.parent { justify-content: space-between; }
        .side-link.parent .left { display: flex; align-items: center; gap: 11px; }
        .side-link.parent .chevron { font-size: .65rem; color: inherit; opacity: .72; transition: transform .2s ease; }
        .side-parent.open .side-link.parent { background: var(--primary-soft-2); color: var(--primary); }
        .side-parent.open .side-link.parent i { color: var(--primary); }
        .side-parent.open .side-link.parent .chevron { transform: rotate(180deg); }
        .submenu { margin: 0 0 0 30px; padding-left: 12px; border-left: 2px solid #fee2e2; display: grid; gap: 4px; max-height: 0; overflow: hidden; opacity: 0; transform: translateY(-4px); transition: max-height .25s ease, opacity .2s ease, transform .2s ease, margin .2s ease; }
        .side-parent.open .submenu { max-height: 260px; opacity: 1; transform: translateY(0); margin: 5px 0 8px 30px; }
        .submenu a { text-decoration: none; color: #64748b; font-size: .76rem; font-weight: 850; padding: 7px 8px; border-radius: 11px; transition: .2s ease; }
        .submenu a:hover, .submenu a.sub-active { background: #fff1f2; color: var(--primary); }
        .main { min-width: 0; height: 100vh; overflow: hidden; padding: 18px 28px 18px; display: flex; flex-direction: column; }
        .topbar { min-height: 56px; display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 12px; flex: 0 0 auto; }
        .page-kicker { color: var(--primary); font-size: .72rem; font-weight: 900; text-transform: uppercase; letter-spacing: .1em; margin-bottom: 5px; }
        .page-title { font-size: 1.7rem; line-height: 1.08; font-weight: 950; letter-spacing: -0.06em; }
        .page-sub { color: var(--muted); margin-top: 6px; font-size: .84rem; font-weight: 750; line-height: 1.38; max-width: 760px; }
        .top-btn { height: 44px; background: white; border: 1px solid var(--border); border-radius: 16px; padding: 0 14px; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; color: #475569; font-weight: 900; font-size: .8rem; box-shadow: var(--shadow-soft); }
        .top-btn.primary { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; border-color: transparent; }
        .alert { padding: 11px 14px; border-radius: 16px; margin-bottom: 12px; font-weight: 850; line-height: 1.35; box-shadow: var(--shadow-soft); flex: 0 0 auto; }
        .alert.success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .alert.error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .alert { transition: opacity .35s ease, transform .35s ease, margin .35s ease, padding .35s ease, max-height .35s ease; max-height:90px; overflow:hidden; }
        .alert.hide { opacity:0; transform:translateY(-8px); margin-top:0; margin-bottom:0; padding-top:0; padding-bottom:0; max-height:0; pointer-events:none; }
        .stats-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; flex: 0 0 auto; }
        .stat-card { background: rgba(255,255,255,.96); border: 1px solid rgba(229,231,235,.95); border-radius: 22px; padding: 16px 18px; min-height: 92px; box-shadow: var(--shadow); position: relative; overflow: hidden; display:flex; align-items:center; justify-content:space-between; gap:14px; }
        .stat-card::before { content: ""; position: absolute; inset: 0; background: linear-gradient(135deg, rgba(220,38,38,.08), transparent 45%); opacity: .75; }
        .stat-value, .stat-label { position: relative; z-index: 2; }
        .stat-value { font-size: 1.55rem; line-height: 1; font-weight: 950; letter-spacing: -0.07em; margin-bottom: 10px; }
        .stat-label { font-size: .66rem; font-weight: 950; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; line-height: 1.25; }
        .stat-icon { position:relative; z-index:2; width:42px; height:42px; border-radius:16px; display:grid; place-items:center; background:#fee2e2; color:var(--primary); flex:0 0 auto; }
        .stat-icon.blue { background:#dbeafe; color:var(--blue); }
        .stat-icon.green { background:#dcfce7; color:var(--green); }
        .stat-icon.orange { background:#ffedd5; color:var(--orange); }
        .panel { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; background: rgba(255,255,255,.96); border: 1px solid rgba(229,231,235,.95); border-radius: 24px; box-shadow: var(--shadow); overflow: hidden; }
        .panel-header { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 14px; flex: 0 0 auto; }
        .panel-title { display: flex; align-items: center; gap: 10px; font-weight: 950; letter-spacing: -0.03em; font-size: .98rem; }
        .panel-title i { color: var(--primary); }
        .panel-body { padding: 18px 20px 14px; min-height: 0; display: flex; flex-direction: column; overflow: hidden; }
        .filter-form { display: grid; grid-template-columns: 1fr 150px 160px 160px 52px 52px; gap: 10px; margin-bottom: 16px; align-items: end; flex: 0 0 auto; }
        label { display: block; font-size: .7rem; font-weight: 950; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 7px; }
        input, select { width: 100%; padding: 12px 13px; border: 1px solid var(--border); border-radius: 14px; font-weight: 850; outline: none; background: white; }
        input:focus, select:focus { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(220,38,38,.10); }
        .btn { border: none; cursor: pointer; padding: 12px 15px; border-radius: 14px; font-weight: 950; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; font-size: .82rem; transition: .2s ease; white-space: nowrap; }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; box-shadow: 0 14px 25px rgba(220,38,38,.22); }
        .btn-light { background: white; color: #111827; border: 1px solid var(--border); }
        .btn-icon { width: 52px; height: 48px; padding: 0; border-radius: 15px; font-size: .95rem; }
        .btn-icon i { margin: 0; }
        .btn-reset-icon { background: white; color: #0f172a; border: 1px solid var(--border); box-shadow: none; }
        .btn-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .records-wrap { flex: 1 1 auto; min-height: 0; overflow-y: auto; overflow-x: hidden; padding-right: 6px; }
        .records-wrap::-webkit-scrollbar { width: 8px; }
        .records-wrap::-webkit-scrollbar-thumb { background: #fecaca; border-radius: 999px; }
        .records-list { display: grid; gap: 9px; padding-bottom: 4px; }
        .record-card { border: 1px solid var(--border); background: #fbfdff; border-radius: 18px; padding: 12px 14px; transition: .2s ease; }
        .record-card:hover { transform: translateY(-1px); border-color: rgba(220,38,38,.22); box-shadow: var(--shadow-soft); }
        .record-card.overstay { border-color: #fecaca; background: linear-gradient(180deg, #fff, #fff7f7); }
        .record-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 9px; }
        .visitor-main { min-width: 0; }
        .visitor-line { display: flex; align-items: center; gap: 9px; flex-wrap: wrap; }
        .visitor-name { font-size: .95rem; font-weight: 950; letter-spacing: -0.03em; }
        .visitor-contact { color: var(--muted); font-size: .73rem; font-weight: 800; margin-top: 3px; word-break: break-word; }
        .plate { display: inline-flex; background: #111827; color: white; border: 1px solid #334155; padding: 5px 9px; border-radius: 10px; font-family: monospace; font-weight: 950; letter-spacing: .08em; font-size: .78rem; }
        .plate-empty { background: #f8fafc; color: #64748b; border-color: #e2e8f0; font-family: inherit; letter-spacing: .02em; }
        .type-badge { padding: 5px 9px; border-radius: 999px; font-size: .62rem; font-weight: 950; display: inline-flex; align-items: center; gap: 5px; text-transform: uppercase; letter-spacing: .04em; white-space: nowrap; }
        .type-delivery { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }
        .type-family { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .type-service { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
        .type-visitor { background: #f8fafc; color: #475569; border: 1px solid #e2e8f0; }
        .badge { padding: 5px 9px; border-radius: 999px; font-size: .62rem; font-weight: 950; display: inline-flex; text-transform: uppercase; letter-spacing: .04em; white-space: nowrap; }
        .badge-green { background: #dcfce7; color: #166534; }
        .badge-red { background: #fee2e2; color: #991b1b; }
        .badge-orange { background: #ffedd5; color: #9a3412; }
        .badge-gray { background: #f1f5f9; color: #475569; }
        .compact-grid { display: grid; grid-template-columns: 1.2fr 1fr 1fr 1.25fr 1fr auto; gap: 8px; align-items: stretch; }
        .compact-cell { background: white; border: 1px solid var(--border); border-radius: 14px; padding: 9px 10px; min-width: 0; }
        .compact-label { display: block; font-size: .58rem; font-weight: 950; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 4px; line-height: 1.2; }
        .compact-value { display: block; font-size: .82rem; font-weight: 950; color: #111827; line-height: 1.3; word-break: break-word; }
        .compact-sub { display: block; color: var(--muted); font-size: .69rem; margin-top: 3px; line-height: 1.35; font-weight: 780; word-break: break-word; }
        .compact-actions { display: flex; align-items: center; justify-content: flex-end; gap: 7px; padding-left: 2px; }
        .action-btn { width: 38px; height: 38px; border-radius: 13px; padding: 0; font-size: .86rem; }
        .action-btn form { margin: 0; }
        .warning-text { color: #991b1b; background: #fff1f2; border: 1px solid #fecaca; border-radius: 12px; padding: 8px 10px; font-weight: 900; margin-top: 8px; font-size: .72rem; }
        .small { color: var(--muted); font-size: .72rem; margin-top: 4px; line-height: 1.4; font-weight: 750; }
        .empty { padding: 44px 22px; text-align: center; color: var(--muted); font-weight: 800; }
        .footer-note { flex: 0 0 auto; padding-top: 10px; color: var(--muted); font-size: .76rem; font-weight: 800; }
        @media (max-width: 1220px) {
            html, body { height: auto; overflow: auto; }
            .dashboard-shell { grid-template-columns: 1fr; height: auto; min-height: 100vh; overflow: visible; }
            .sidebar { height: auto; overflow: visible; border-right: 0; border-bottom: 1px solid var(--border); }
            .side-nav { grid-template-columns: repeat(2, 1fr); }
            .main { height: auto; min-height: 100vh; overflow: visible; padding: 22px 18px 50px; }
            .records-wrap, .panel-body, .panel { overflow: visible; }
        }
        @media (max-width: 1080px) {
            .stats-grid, .compact-grid { grid-template-columns: repeat(2, 1fr); }
            .compact-actions { justify-content: flex-start; }
        }
        @media (max-width: 720px) {
            .topbar { flex-direction: column; align-items: flex-start; }
            .top-btn, .btn { width: 100%; }
            .top-btn { justify-content: center; }
            .stats-grid, .filter-form, .side-nav, .compact-grid { grid-template-columns: 1fr; }
            .record-top { flex-direction: column; }
            .actions { width: 100%; flex-direction: column; }
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
                <h1 class="page-title"><?= e($pageTitle) ?></h1>
                <p class="page-sub"><?= e($pageSubtitle) ?></p>
            </div>

            <div class="top-actions">
                <a href="admin_dashboard.php" class="top-btn primary">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert success"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($autoCancelledNoShowCount)): ?>
            <div class="alert success">
                <?= (int)$autoCancelledNoShowCount ?> no-show booking<?= (int)$autoCancelledNoShowCount === 1 ? '' : 's' ?> auto-cancelled because the visitor did not arrive within 1 hour after Expected In.
            </div>
        <?php endif; ?>

        <section class="stats-grid">
            <div class="stat-card">
                <div>
                    <div class="stat-value" style="color:var(--blue);"><?= (int)$totalVisitsCount ?></div>
                    <div class="stat-label">Total Visits</div>
                </div>
                <div class="stat-icon blue"><i class="fas fa-calendar-day"></i></div>
            </div>

            <div class="stat-card">
                <div>
                    <div class="stat-value" style="color:var(--green);"><?= (int)$todayCount ?></div>
                    <div class="stat-label">Today Visits</div>
                </div>
                <div class="stat-icon green"><i class="fas fa-calendar-check"></i></div>
            </div>

            <div class="stat-card">
                <div>
                    <div class="stat-value" style="color:var(--orange);"><?= (int)$waitingEntryCount ?></div>
                    <div class="stat-label">Waiting Entry</div>
                </div>
                <div class="stat-icon orange"><i class="fas fa-door-open"></i></div>
            </div>

            <div class="stat-card">
                <div>
                    <div class="stat-value" style="color:var(--primary);"><?= (int)$checkedInCount ?></div>
                    <div class="stat-label">Currently Inside</div>
                </div>
                <div class="stat-icon"><i class="fas fa-person-walking-arrow-right"></i></div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div class="panel-title">
                    <i class="fas fa-id-card-clip"></i><?= e($pageTitle) ?>
                </div>
            </div>

            <div class="panel-body">
                <form method="GET" class="filter-form">
                    <?php if ($visitorIdFilter > 0): ?>
                        <input type="hidden" name="visitor_id" value="<?= (int)$visitorIdFilter ?>">
                    <?php endif; ?>

                    <div>
                        <label>Search</label>
                        <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search visitor, plate, resident or unit">
                    </div>

                    <div>
                        <label>Block</label>
                        <select name="block" onchange="this.form.submit()">
                            <option value="">All Blocks</option>
                            <?php foreach ($blockOptions as $blockOption): ?>
                                <option value="<?= e($blockOption) ?>" <?= $blockFilter === $blockOption ? 'selected' : '' ?>>
                                    Block <?= e($blockOption) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Visit Date</label>
                        <input type="date" name="visit_date" value="<?= e($selectedDate) ?>">
                    </div>

                    <div>
                        <label>Status</label>
                        <select name="status">
                            <option value="">All Visits</option>
                            <option value="upcoming" <?= $statusFilter === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                            <option value="waiting_entry" <?= $statusFilter === 'waiting_entry' ? 'selected' : '' ?>>Waiting Entry</option>
                            <option value="inside" <?= $statusFilter === 'inside' ? 'selected' : '' ?>>Currently Inside</option>
                            <option value="overstay" <?= $statusFilter === 'overstay' ? 'selected' : '' ?>>Overstay</option>
                            <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled / Expired</option>
                            <option value="pending_approval" <?= $statusFilter === 'pending_approval' ? 'selected' : '' ?>>Pending Approval</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary btn-icon" title="Search" aria-label="Search"><i class="fas fa-search"></i></button>

                    <?php if ($visitorIdFilter > 0): ?>
                        <a href="<?= e(basename($_SERVER['PHP_SELF'])) ?>?visitor_id=<?= (int)$visitorIdFilter ?>" class="btn btn-reset-icon btn-icon" title="Reset" aria-label="Reset"><i class="fas fa-rotate-left"></i></a>
                    <?php else: ?>
                        <a href="<?= e(basename($_SERVER['PHP_SELF'])) ?>" class="btn btn-reset-icon btn-icon" title="Reset" aria-label="Reset"><i class="fas fa-rotate-left"></i></a>
                    <?php endif; ?>
                </form>

                <?php if (empty($records)): ?>
                    <div class="empty">No visitor visit found. Try Reset or change the date/status filter.</div>
                <?php else: ?>
                    <div class="records-wrap">
                        <div class="records-list">
                            <?php foreach ($records as $record): ?>
                                <?php
                                    $category = vm_visit_category($record['purpose'] ?? '', $record['visitor_type'] ?? '');
                                    $rawPlateNo = vm_plate($record['plate_no'] ?? '');
                                    $plateNo = vm_display_plate($record['plate_no'] ?? '', $record['purpose'] ?? '', $record['visitor_type'] ?? '');
                                    $showNoPlate = ($plateNo === '' && ($rawPlateNo !== '' || $category['label'] === 'Delivery'));
                                    $status = strtolower(trim((string)($record['booking_status'] ?? 'record')));
                                    $displayStatus = $status;

                                    if (!empty($record['actual_exit_time'])) {
                                        $displayStatus = 'completed';
                                    } elseif (vm_is_overstay($record)) {
                                        $displayStatus = 'overstay';
                                    } elseif (!empty($record['actual_entry_time'])) {
                                        $displayStatus = 'checked_in';
                                    } elseif ($displayStatus === '') {
                                        $displayStatus = 'pending';
                                    }

                                    if ($displayStatus === 'allocated' || in_array($displayStatus, ['approved', 'accepted', 'active', 'confirmed', 'valid', 'waiting'], true)) {
                                        $displayStatus = 'waiting_entry';
                                    }

                                    $cardClass = $displayStatus === 'overstay' ? 'overstay' : '';
                                ?>
                                <div class="record-card <?= e($cardClass) ?>">
                                    <div class="record-top">
                                        <div class="visitor-main">
                                            <div class="visitor-line">
                                                <div class="visitor-name"><?= e(vm_text($record['visitor_name'])) ?></div>
                                                <span class="type-badge <?= e($category['class']) ?>"><i class="<?= e($category['icon']) ?>"></i><?= e($category['label']) ?></span>
                                                <?php if ($plateNo !== ''): ?>
                                                    <span class="plate"><?= e($plateNo) ?></span>
                                                <?php elseif ($showNoPlate): ?>
                                                    <span class="plate plate-empty">NO PLATE</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="visitor-contact">
                                                <?= e(vm_text($record['visitor_email'])) ?>
                                                <?php if (!empty($record['visitor_phone'])): ?>
                                                    · <?= e($record['visitor_phone']) ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div style="display:flex;gap:7px;flex-wrap:wrap;justify-content:flex-end;">
                                            <span class="badge <?= e(vm_status_class($displayStatus)) ?>"><?= e(ucwords(str_replace('_', ' ', $displayStatus ?: 'record'))) ?></span>
                                        </div>
                                    </div>

                                    <div class="compact-grid">
                                        <div class="compact-cell">
                                            <span class="compact-label">Scheduled Visit</span>
                                            <span class="compact-value"><?= e(vm_schedule_period($record)) ?></span>
                                        </div>

                                        <div class="compact-cell">
                                            <span class="compact-label">Entry Time</span>
                                            <span class="compact-value"><?= e(vm_datetime($record['actual_entry_time'] ?? null)) ?></span>
                                            <?php if (empty($record['actual_entry_time'])): ?>
                                                <span class="compact-sub">No entry log found</span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="compact-cell">
                                            <span class="compact-label">Exit Time</span>
                                            <span class="compact-value"><?= e(vm_datetime($record['actual_exit_time'] ?? null)) ?></span>
                                            <?php if (empty($record['actual_exit_time'])): ?>
                                                <span class="compact-sub"><?= $displayStatus === 'overstay' ? 'Overstay - not checked out yet' : 'Not checked out yet' ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="compact-cell">
                                            <span class="compact-label">Resident / Unit</span>
                                            <span class="compact-value"><?= e(vm_text($record['resident_name'])) ?></span>
                                            <span class="compact-sub"><?= e(vm_unit($record)) ?></span>
                                        </div>

                                        <div class="compact-cell">
                                            <span class="compact-label">Purpose</span>
                                            <span class="compact-value"><?= e(vm_text($record['purpose'])) ?></span>
                                            <span class="compact-sub"><?= e($category['label']) ?> booking</span>
                                            <?php if (!empty($record['resident_email'])): ?>
                                                <span class="compact-sub"><?= e($record['resident_email']) ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="compact-actions">
                                            <?php if (!empty($record['resident_id'])): ?>
                                                <a href="admin_residents_manage.php?resident_id=<?= (int)$record['resident_id'] ?>" class="btn btn-light action-btn" title="Resident Profile" aria-label="Resident Profile">
                                                    <i class="fas fa-user"></i>
                                                </a>
                                            <?php endif; ?>

                                            <?php if ($plateNo !== ''): ?>
                                                <a href="guard_logs.php?search=<?= urlencode($plateNo) ?>" class="btn btn-light action-btn" title="Gate Logs" aria-label="Gate Logs">
                                                    <i class="fas fa-clock-rotate-left"></i>
                                                </a>

                                                <form method="POST" data-safe-confirm="1" data-confirm-title="Blacklist this plate?" data-confirm-text="This plate will be blocked from future visitor access.">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="blacklist_plate">
                                                    <input type="hidden" name="plate_no" value="<?= e($plateNo) ?>">
                                                    <input type="hidden" name="reason" value="Blacklisted from <?= e($pageTitle) ?>">
                                                    <button type="submit" class="btn btn-danger action-btn" title="Blacklist Plate" aria-label="Blacklist Plate">
                                                        <i class="fas fa-ban"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if ($displayStatus === 'overstay'): ?>
                                        <div class="warning-text">
                                            This visitor is overstay because the scheduled end time has passed and no exit record was found.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="footer-note">
                        Showing maximum 800 visitor visits. This page combines booking and record information. No-show bookings are auto-cancelled 1 hour after Expected In.
                        <?php if ($selectedDate !== ''): ?>
                            Date filter: <?= e($selectedDate) ?>.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
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
    document.querySelectorAll('.alert').forEach(function (box) { setTimeout(function () { box.classList.add('hide'); setTimeout(function(){ box.remove(); }, 450); }, 3000); });
    const successAlert = document.querySelector('.alert.success');

    if (successAlert) {
        setTimeout(function () {
            successAlert.style.transition = 'opacity .35s ease, transform .35s ease';
            successAlert.style.opacity = '0';
            successAlert.style.transform = 'translateY(-6px)';
            setTimeout(function () { successAlert.remove(); }, 380);
        }, 2500);
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

            Swal.fire({
                icon: 'warning',
                title: form.dataset.confirmTitle || 'Confirm action?',
                text: form.dataset.confirmText || 'Please confirm before continuing.',
                showCancelButton: true,
                confirmButtonText: 'Confirm',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#64748b'
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
