<?php
require_once '../core/security.php';
require_login(['guard', 'admin', 'superadmin']);

header('Content-Type: application/json');

$pdo = db();

function json_reply(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function table_exists_gate(PDO $pdo, string $table): bool {
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

function has_column_gate(PDO $pdo, string $table, string $column): bool {
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

function clean_plate_gate($plate): string {
    $plate = strtoupper(trim((string)$plate));
    return preg_replace('/[^A-Z0-9]/', '', $plate);
}

function safe_text_gate($value): string {
    return $value !== null && $value !== '' ? (string)$value : '-';
}

function insert_gate_log(PDO $pdo, array $data): void {
    if (!table_exists_gate($pdo, 'gate_logs')) {
        return;
    }

    $columns = [];
    $marks = [];
    $values = [];

    $map = [
        'booking_id' => $data['booking_id'] ?? null,
        'plate_no' => $data['plate_no'] ?? null,
        'input_value' => $data['input_value'] ?? null,
        'vehicle_type' => $data['vehicle_type'] ?? null,
        'guard_id' => $data['guard_id'] ?? null,
        'gate_action' => $data['gate_action'] ?? null,
        'decision' => $data['decision'] ?? null,
        'reason' => $data['reason'] ?? null
    ];

    foreach ($map as $column => $value) {
        if (has_column_gate($pdo, 'gate_logs', $column)) {
            $columns[] = $column;
            $marks[] = '?';
            $values[] = $value;
        }
    }

    if (has_column_gate($pdo, 'gate_logs', 'created_at')) {
        $columns[] = 'created_at';
        $marks[] = 'NOW()';
    }

    if (empty($columns)) {
        return;
    }

    $sql = "
        INSERT INTO gate_logs
        (" . implode(', ', $columns) . ")
        VALUES
        (" . implode(', ', $marks) . ")
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
}

function find_blacklist(PDO $pdo, string $plate): ?array {
    if ($plate === '' || !table_exists_gate($pdo, 'blacklisted_plates')) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM blacklisted_plates
            WHERE plate_no = ?
            AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$plate]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function find_resident_vehicle(PDO $pdo, string $plate): ?array {
    if ($plate === '' || !table_exists_gate($pdo, 'resident_vehicles')) {
        return null;
    }

    $hasFullName = has_column_gate($pdo, 'users', 'full_name');

    $residentNameSql = $hasFullName ? "u.full_name AS resident_name" : "NULL AS resident_name";

    try {
        $stmt = $pdo->prepare("
            SELECT
                rv.*,
                u.email AS resident_email,
                {$residentNameSql},
                un.block_no,
                un.floor_no,
                un.unit_no,
                a.apartment_name
            FROM resident_vehicles rv

            JOIN users u
                ON u.id = rv.resident_id
                AND u.role = 'resident'

            LEFT JOIN resident_units ru
                ON ru.resident_id = u.id
                AND ru.status = 'active'

            LEFT JOIN units un ON un.id = ru.unit_id
            LEFT JOIN apartments a ON a.id = un.apartment_id

            WHERE rv.plate_no = ?
            AND rv.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$plate]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function current_billing_month_gate(): string {
    return date('Y-m');
}

function resident_parking_access_gate(PDO $pdo, array $residentVehicle): array {
    $result = [
        'module_ready' => false,
        'allowed' => false,
        'message' => 'Resident parking payment module is not ready.',
        'slot' => '-',
        'assignment_id' => null,
        'billing_month' => current_billing_month_gate(),
        'payment_status' => 'unavailable',
        'monthly_fee' => '0.00',
        'verified_at' => null
    ];

    if (
        !table_exists_gate($pdo, 'resident_parking_assignments') ||
        !table_exists_gate($pdo, 'parking_payments') ||
        !table_exists_gate($pdo, 'parking_slots')
    ) {
        return $result;
    }

    $result['module_ready'] = true;

    $vehicleId = (int)($residentVehicle['id'] ?? 0);
    $residentId = (int)($residentVehicle['resident_id'] ?? 0);
    $billingMonth = current_billing_month_gate();
    $result['billing_month'] = $billingMonth;

    if ($vehicleId <= 0 || $residentId <= 0) {
        $result['message'] = 'Resident vehicle record is incomplete.';
        return $result;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                rpa.id AS assignment_id,
                rpa.monthly_fee,
                rpa.start_date,
                rpa.end_date,
                ps.block_name,
                ps.slot_no,
                ps.slot_type,
                pp.id AS payment_id,
                pp.payment_status,
                pp.amount,
                pp.verified_at
            FROM resident_parking_assignments rpa
            JOIN parking_slots ps ON ps.id = rpa.slot_id
            LEFT JOIN parking_payments pp
                ON pp.assignment_id = rpa.id
                AND pp.billing_month = ?
            WHERE rpa.vehicle_id = ?
            AND rpa.resident_id = ?
            AND rpa.status = 'active'
            AND ps.slot_type = 'Resident'
            AND (rpa.end_date IS NULL OR rpa.end_date >= CURDATE())
            ORDER BY rpa.created_at DESC, rpa.id DESC
            LIMIT 1
        ");
        $stmt->execute([$billingMonth, $vehicleId, $residentId]);
        $row = $stmt->fetch();

        if (!$row) {
            $result['message'] = 'Resident vehicle found, but no active resident parking slot is assigned yet.';
            return $result;
        }

        $slotText = trim((string)($row['block_name'] ?? '') . ' ' . (string)($row['slot_no'] ?? ''));
        $result['slot'] = $slotText !== '' ? $slotText : '-';
        $result['assignment_id'] = (int)$row['assignment_id'];
        $result['monthly_fee'] = number_format((float)($row['monthly_fee'] ?? 0), 2, '.', '');
        $result['verified_at'] = $row['verified_at'] ?? null;

        $paymentStatus = (string)($row['payment_status'] ?? 'unpaid');
        $result['payment_status'] = $paymentStatus !== '' ? $paymentStatus : 'unpaid';

        if ($paymentStatus === 'paid') {
            $result['allowed'] = true;
            $result['message'] = 'Resident parking access allowed. Slot: ' . $result['slot'] . '. Monthly parking fee is paid for ' . $billingMonth . '.';
            return $result;
        }

        if ($paymentStatus === 'pending_verification') {
            $result['message'] = 'Monthly parking payment is submitted but not verified by admin yet. Access denied.';
            return $result;
        }

        if ($paymentStatus === 'rejected') {
            $result['message'] = 'Monthly parking payment was rejected. Please submit a valid payment again. Access denied.';
            return $result;
        }

        if ($paymentStatus === 'overdue') {
            $result['message'] = 'Monthly parking fee is overdue. Access denied.';
            return $result;
        }

        $result['message'] = 'Monthly parking fee is not paid for ' . $billingMonth . '. Access denied.';
        return $result;
    } catch (Throwable $e) {
        $result['message'] = 'Unable to verify resident parking payment. Access denied.';
        return $result;
    }
}

function find_booking_by_token_or_plate(PDO $pdo, string $rawInput, string $cleanPlate, string $action): ?array {
    if (!table_exists_gate($pdo, 'bookings')) {
        return null;
    }

    $hasFullName = has_column_gate($pdo, 'users', 'full_name');
    $hasContact = has_column_gate($pdo, 'users', 'contact_number');
    $hasQrToken = has_column_gate($pdo, 'bookings', 'qr_token');
    $hasSlotId = has_column_gate($pdo, 'bookings', 'slot_id');

    $visitorNameSql = $hasFullName ? "vu.full_name AS visitor_account_name" : "NULL AS visitor_account_name";
    $residentNameSql = $hasFullName ? "res.full_name AS resident_name" : "NULL AS resident_name";
    $residentContactSql = $hasContact ? "res.contact_number AS resident_contact" : "NULL AS resident_contact";

    $slotJoinSql = $hasSlotId
        ? "LEFT JOIN parking_slots ps ON ps.id = b.slot_id"
        : "LEFT JOIN parking_slots ps ON 1 = 0";

    $baseSql = "
        SELECT
            b.*,

            vu.email AS visitor_email,
            {$visitorNameSql},

            res.email AS resident_email,
            {$residentNameSql},
            {$residentContactSql},

            a.apartment_name,
            un.block_no,
            un.floor_no,
            un.unit_no,

            ps.id AS parking_slot_id,
            ps.block_name AS parking_block,
            ps.slot_no AS parking_slot_no,
            ps.status AS parking_status

        FROM bookings b

        LEFT JOIN users vu ON vu.id = b.visitor_user_id
        LEFT JOIN users res ON res.id = b.resident_id

        LEFT JOIN resident_units ru
            ON ru.resident_id = b.resident_id
            AND ru.status = 'active'

        LEFT JOIN units un ON un.id = ru.unit_id
        LEFT JOIN apartments a ON a.id = un.apartment_id

        {$slotJoinSql}
    ";

    if ($hasQrToken && $rawInput !== '') {
        $stmt = $pdo->prepare($baseSql . "
            WHERE b.qr_token = ?
            LIMIT 1
        ");
        $stmt->execute([$rawInput]);
        $row = $stmt->fetch();

        if ($row) {
            return $row;
        }
    }

    if ($cleanPlate !== '') {
        $orderStatus = $action === 'EXIT'
            ? "FIELD(b.status, 'checked_in', 'allocated', 'approved', 'waiting', 'pending', 'completed', 'rejected')"
            : "FIELD(b.status, 'allocated', 'approved', 'waiting', 'pending', 'checked_in', 'completed', 'rejected')";

        $stmt = $pdo->prepare($baseSql . "
            WHERE b.plate_no = ?
            ORDER BY
                {$orderStatus},
                b.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$cleanPlate]);
        $row = $stmt->fetch();

        if ($row) {
            return $row;
        }
    }

    return null;
}

function allocate_visitor_slot(PDO $pdo): ?array {
    if (!table_exists_gate($pdo, 'parking_slots')) {
        return null;
    }

    try {
        $stmt = $pdo->query("
            SELECT *
            FROM parking_slots
            WHERE slot_type = 'Visitor'
            AND status = 'available'
            ORDER BY id ASC
            LIMIT 1
        ");

        $slot = $stmt->fetch();

        if (!$slot) {
            return null;
        }

        $update = $pdo->prepare("
            UPDATE parking_slots
            SET status = 'reserved'
            WHERE id = ?
            AND status = 'available'
        ");
        $update->execute([(int)$slot['id']]);

        if ($update->rowCount() <= 0) {
            return null;
        }

        return $slot;
    } catch (Throwable $e) {
        return null;
    }
}

function release_slot(PDO $pdo, ?int $slotId): void {
    if (!$slotId || !table_exists_gate($pdo, 'parking_slots')) {
        return;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE parking_slots
            SET status = 'available'
            WHERE id = ?
            AND slot_type = 'Visitor'
        ");
        $stmt->execute([$slotId]);
    } catch (Throwable $e) {
        // ignore
    }
}

function set_slot_status(PDO $pdo, ?int $slotId, string $status): void {
    if (!$slotId || !table_exists_gate($pdo, 'parking_slots')) {
        return;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE parking_slots
            SET status = ?
            WHERE id = ?
            AND slot_type = 'Visitor'
        ");
        $stmt->execute([
            $status,
            $slotId
        ]);
    } catch (Throwable $e) {
        // ignore
    }
}

function assign_next_waiting(PDO $pdo, ?int $releasedSlotId): array {
    $result = [
        'assigned' => false,
        'visitor_name' => null,
        'plate_no' => null,
        'slot' => null
    ];

    if (!$releasedSlotId || !has_column_gate($pdo, 'bookings', 'slot_id')) {
        return $result;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM bookings
            WHERE status = 'waiting'
            ORDER BY created_at ASC
            LIMIT 1
        ");
        $stmt->execute();
        $waiting = $stmt->fetch();

        if (!$waiting) {
            return $result;
        }

        $slotStmt = $pdo->prepare("
            SELECT *
            FROM parking_slots
            WHERE id = ?
            AND slot_type = 'Visitor'
            LIMIT 1
        ");
        $slotStmt->execute([$releasedSlotId]);
        $slot = $slotStmt->fetch();

        if (!$slot) {
            return $result;
        }

        $pdo->prepare("
            UPDATE parking_slots
            SET status = 'reserved'
            WHERE id = ?
        ")->execute([$releasedSlotId]);

        $sets = [
            "status = 'allocated'",
            "slot_id = ?"
        ];

        $params = [
            $releasedSlotId
        ];

        if (has_column_gate($pdo, 'bookings', 'updated_at')) {
            $sets[] = "updated_at = NOW()";
        }

        $params[] = (int)$waiting['id'];

        $stmt = $pdo->prepare("
            UPDATE bookings
            SET " . implode(', ', $sets) . "
            WHERE id = ?
        ");
        $stmt->execute($params);

        if (function_exists('create_notification')) {
            create_notification(
                $pdo,
                (int)$waiting['visitor_user_id'],
                'Parking Slot Assigned',
                'A visitor parking slot has been assigned to your approved booking. Slot: ' . $slot['block_name'] . ' ' . $slot['slot_no'],
                'booking'
            );
        }

        $result['assigned'] = true;
        $result['visitor_name'] = $waiting['visitor_name'] ?? '-';
        $result['plate_no'] = $waiting['plate_no'] ?? '-';
        $result['slot'] = $slot['block_name'] . ' ' . $slot['slot_no'];

        return $result;
    } catch (Throwable $e) {
        return $result;
    }
}

function booking_unit_text(array $booking): string {
    if (empty($booking['unit_no'])) {
        return '-';
    }

    return 'Block ' . $booking['block_no'] .
        ' / Floor ' . $booking['floor_no'] .
        ' / Unit ' . $booking['unit_no'];
}

function booking_slot_text(array $booking): string {
    if (!empty($booking['parking_block']) && !empty($booking['parking_slot_no'])) {
        return $booking['parking_block'] . ' ' . $booking['parking_slot_no'];
    }

    return '-';
}

function resident_unit_text(array $row): string {
    if (empty($row['unit_no'])) {
        return '-';
    }

    return 'Block ' . $row['block_no'] .
        ' / Floor ' . $row['floor_no'] .
        ' / Unit ' . $row['unit_no'];
}

function is_multiple_in_out_gate(array $booking): bool {
    $visitType = strtolower(trim((string)($booking['visit_type'] ?? '')));

    return in_array($visitType, [
        'multiple in-out',
        'multiple in out',
        'multiple_in_out',
        'multiple'
    ], true);
}

function visit_type_text_gate(array $booking): string {
    return is_multiple_in_out_gate($booking) ? 'Multiple In-Out' : 'One Time';
}

function booking_valid_until_text(array $booking): string {
    if (empty($booking['end_time'])) {
        return '-';
    }

    return date('d M Y, g:i A', strtotime($booking['end_time']));
}

function expire_booking_gate(PDO $pdo, array $booking, bool $hasUpdatedAt, bool $hasSlotId): void {
    $sets = ["status = 'expired'"];

    if ($hasUpdatedAt) {
        $sets[] = "updated_at = NOW()";
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE bookings
            SET " . implode(', ', $sets) . "
            WHERE id = ?
        ");
        $stmt->execute([(int)$booking['id']]);
    } catch (Throwable $e) {
        // ignore
    }

    if ($hasSlotId) {
        $slotId = (int)($booking['slot_id'] ?? 0);

        if ($slotId > 0) {
            release_slot($pdo, $slotId);
            assign_next_waiting($pdo, $slotId);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_reply([
        'success' => false,
        'message' => 'Invalid request method.'
    ], 405);
}

$csrf = $_POST['csrf_token'] ?? '';

if (isset($_SESSION['csrf_token']) && !hash_equals($_SESSION['csrf_token'], $csrf)) {
    json_reply([
        'success' => false,
        'message' => 'Invalid security token. Please refresh the page.'
    ], 403);
}

$inputRaw = trim($_POST['input'] ?? '');
$gateAction = strtoupper(trim($_POST['gate_action'] ?? 'ENTRY'));
$guardId = (int)($_SESSION['uid'] ?? 0);

if ($inputRaw === '') {
    json_reply([
        'success' => false,
        'message' => 'Please scan QR code, scan plate, or enter vehicle plate number.'
    ]);
}

if (!in_array($gateAction, ['ENTRY', 'EXIT'], true)) {
    json_reply([
        'success' => false,
        'message' => 'Invalid gate action.'
    ]);
}

$cleanPlate = clean_plate_gate($inputRaw);

/*
|--------------------------------------------------------------------------
| 1. Direct blacklist check by plate input
|--------------------------------------------------------------------------
*/
$blacklist = find_blacklist($pdo, $cleanPlate);

if ($blacklist) {
    insert_gate_log($pdo, [
        'booking_id' => null,
        'plate_no' => $cleanPlate,
        'input_value' => $inputRaw,
        'vehicle_type' => 'blacklist',
        'guard_id' => $guardId,
        'gate_action' => $gateAction,
        'decision' => 'DENY',
        'reason' => 'This vehicle is blacklisted.'
    ]);

    if (function_exists('log_audit')) {
        log_audit('BLACKLIST_GATE_DENIED', 'Blacklisted plate denied at gate: ' . $cleanPlate);
    }

    json_reply([
        'success' => false,
        'message' => 'This vehicle is blacklisted.',
        'vehicle_type' => 'blacklist',
        'plate_no' => $cleanPlate,
        'visitor_name' => '-',
        'resident_name' => '-',
        'resident_email' => '-',
        'unit' => '-',
        'slot' => '-'
    ]);
}

/*
|--------------------------------------------------------------------------
| 2. Resident vehicle check
|--------------------------------------------------------------------------
*/
$residentVehicle = find_resident_vehicle($pdo, $cleanPlate);

if ($residentVehicle) {
    $residentName = $residentVehicle['resident_name'] ?: $residentVehicle['resident_email'];
    $parkingAccess = resident_parking_access_gate($pdo, $residentVehicle);
    $decision = $parkingAccess['allowed'] ? 'ALLOW' : 'DENY';

    insert_gate_log($pdo, [
        'booking_id' => null,
        'plate_no' => $cleanPlate,
        'input_value' => $inputRaw,
        'vehicle_type' => 'resident',
        'guard_id' => $guardId,
        'gate_action' => $gateAction,
        'decision' => $decision,
        'reason' => $parkingAccess['message']
    ]);

    if (function_exists('log_audit')) {
        if ($parkingAccess['allowed']) {
            log_audit(
                'RESIDENT_PARKING_GATE_ALLOWED',
                'Resident vehicle allowed at gate: ' . $cleanPlate .
                ' / Slot: ' . $parkingAccess['slot'] .
                ' / Billing: ' . $parkingAccess['billing_month'] .
                ' / Action: ' . $gateAction
            );
        } else {
            log_audit(
                'RESIDENT_PARKING_GATE_DENIED',
                'Resident vehicle denied at gate: ' . $cleanPlate .
                ' / Reason: ' . $parkingAccess['message'] .
                ' / Action: ' . $gateAction
            );
        }
    }

    json_reply([
        'success' => $parkingAccess['allowed'],
        'message' => $parkingAccess['message'],
        'vehicle_type' => 'resident',
        'plate_no' => $cleanPlate,
        'visitor_name' => '-',
        'resident_name' => $residentName,
        'resident_email' => $residentVehicle['resident_email'] ?? '-',
        'unit' => resident_unit_text($residentVehicle),
        'slot' => $parkingAccess['slot'],
        'resident_parking_slot' => $parkingAccess['slot'],
        'billing_month' => $parkingAccess['billing_month'],
        'payment_status' => $parkingAccess['payment_status'],
        'monthly_fee' => $parkingAccess['monthly_fee'],
        'payment_verified_at' => $parkingAccess['verified_at']
    ]);
}

/*
|--------------------------------------------------------------------------
| 3. Visitor booking check by QR token or plate number
|--------------------------------------------------------------------------
*/
$booking = find_booking_by_token_or_plate($pdo, $inputRaw, $cleanPlate, $gateAction);

if (!$booking) {
    insert_gate_log($pdo, [
        'booking_id' => null,
        'plate_no' => $cleanPlate,
        'input_value' => $inputRaw,
        'vehicle_type' => 'unknown',
        'guard_id' => $guardId,
        'gate_action' => $gateAction,
        'decision' => 'DENY',
        'reason' => 'No matching visitor booking or resident vehicle found.'
    ]);

    json_reply([
        'success' => false,
        'message' => 'No matching visitor booking or resident vehicle found.',
        'vehicle_type' => 'unknown',
        'plate_no' => $cleanPlate ?: '-',
        'visitor_name' => '-',
        'resident_name' => '-',
        'resident_email' => '-',
        'unit' => '-',
        'slot' => '-'
    ]);
}

$bookingPlate = clean_plate_gate($booking['plate_no'] ?? '');

$blacklistByBooking = find_blacklist($pdo, $bookingPlate);

if ($blacklistByBooking) {
    insert_gate_log($pdo, [
        'booking_id' => (int)$booking['id'],
        'plate_no' => $bookingPlate,
        'input_value' => $inputRaw,
        'vehicle_type' => 'visitor',
        'guard_id' => $guardId,
        'gate_action' => $gateAction,
        'decision' => 'DENY',
        'reason' => 'This visitor vehicle is blacklisted.'
    ]);

    if (function_exists('log_audit')) {
        log_audit('BLACKLIST_VISITOR_GATE_DENIED', 'Blacklisted visitor booking denied. Booking #' . $booking['id'] . ', Plate: ' . $bookingPlate);
    }

    json_reply([
        'success' => false,
        'message' => 'This visitor vehicle is blacklisted.',
        'vehicle_type' => 'visitor',
        'plate_no' => $bookingPlate,
        'visitor_name' => $booking['visitor_name'] ?? '-',
        'resident_name' => $booking['resident_name'] ?: ($booking['resident_email'] ?? '-'),
        'resident_email' => $booking['resident_email'] ?? '-',
        'unit' => booking_unit_text($booking),
        'slot' => booking_slot_text($booking)
    ]);
}

$status = $booking['status'] ?? '';
$hasSlotId = has_column_gate($pdo, 'bookings', 'slot_id');
$hasUpdatedAt = has_column_gate($pdo, 'bookings', 'updated_at');

$endTime = strtotime($booking['end_time'] ?? 'now');
$isExpiredByTime = $endTime !== false && $endTime < time();
$isMultipleInOut = is_multiple_in_out_gate($booking);
$visitTypeText = visit_type_text_gate($booking);

/*
|--------------------------------------------------------------------------
| Time validity rule
|--------------------------------------------------------------------------
| One Time pass: created by resident invite page as Arrival + 8 hours.
| Multiple In-Out pass: created as Arrival + 24 hours.
|
| ENTRY after the valid time is always denied.
| EXIT is still allowed if the visitor is currently checked in, so guard can
| record the visitor leaving. This is treated as overstay exit.
|--------------------------------------------------------------------------
*/
if ($isExpiredByTime && $gateAction === 'ENTRY' && !in_array($status, ['completed', 'checked_out', 'closed', 'expired'], true)) {
    expire_booking_gate($pdo, $booking, $hasUpdatedAt, $hasSlotId);

    insert_gate_log($pdo, [
        'booking_id' => (int)$booking['id'],
        'plate_no' => $bookingPlate,
        'input_value' => $inputRaw,
        'vehicle_type' => 'visitor',
        'guard_id' => $guardId,
        'gate_action' => $gateAction,
        'decision' => 'DENY',
        'reason' => $visitTypeText . ' visitor pass has expired.'
    ]);

    json_reply([
        'success' => false,
        'message' => $visitTypeText . ' visitor pass has expired. Valid until: ' . booking_valid_until_text($booking),
        'vehicle_type' => 'visitor',
        'plate_no' => $bookingPlate,
        'visitor_name' => $booking['visitor_name'] ?? '-',
        'resident_name' => $booking['resident_name'] ?: ($booking['resident_email'] ?? '-'),
        'resident_email' => $booking['resident_email'] ?? '-',
        'unit' => booking_unit_text($booking),
        'slot' => booking_slot_text($booking),
        'visit_type' => $visitTypeText
    ]);
}

/*
|--------------------------------------------------------------------------
| 4. ENTRY handling
|--------------------------------------------------------------------------
*/
if ($gateAction === 'ENTRY') {
    if ($status === 'pending') {
        insert_gate_log($pdo, [
            'booking_id' => (int)$booking['id'],
            'plate_no' => $bookingPlate,
            'input_value' => $inputRaw,
            'vehicle_type' => 'visitor',
            'guard_id' => $guardId,
            'gate_action' => 'ENTRY',
            'decision' => 'DENY',
            'reason' => 'Booking is still pending resident approval.'
        ]);

        json_reply([
            'success' => false,
            'message' => 'Booking is still pending resident approval.',
            'vehicle_type' => 'visitor',
            'plate_no' => $bookingPlate,
            'visitor_name' => $booking['visitor_name'] ?? '-',
            'resident_name' => $booking['resident_name'] ?: ($booking['resident_email'] ?? '-'),
            'resident_email' => $booking['resident_email'] ?? '-',
            'unit' => booking_unit_text($booking),
            'slot' => booking_slot_text($booking)
        ]);
    }

    if (in_array($status, ['rejected', 'cancelled', 'expired'], true)) {
        insert_gate_log($pdo, [
            'booking_id' => (int)$booking['id'],
            'plate_no' => $bookingPlate,
            'input_value' => $inputRaw,
            'vehicle_type' => 'visitor',
            'guard_id' => $guardId,
            'gate_action' => 'ENTRY',
            'decision' => 'DENY',
            'reason' => 'Booking is not valid for entry.'
        ]);

        json_reply([
            'success' => false,
            'message' => 'Booking is not valid for entry.',
            'vehicle_type' => 'visitor',
            'plate_no' => $bookingPlate,
            'visitor_name' => $booking['visitor_name'] ?? '-',
            'resident_name' => $booking['resident_name'] ?: ($booking['resident_email'] ?? '-'),
            'resident_email' => $booking['resident_email'] ?? '-',
            'unit' => booking_unit_text($booking),
            'slot' => booking_slot_text($booking)
        ]);
    }

    if (in_array($status, ['completed', 'checked_out', 'closed'], true)) {
        insert_gate_log($pdo, [
            'booking_id' => (int)$booking['id'],
            'plate_no' => $bookingPlate,
            'input_value' => $inputRaw,
            'vehicle_type' => 'visitor',
            'guard_id' => $guardId,
            'gate_action' => 'ENTRY',
            'decision' => 'DENY',
            'reason' => 'This booking has already been completed.'
        ]);

        json_reply([
            'success' => false,
            'message' => 'This One Time visitor pass has already been used and completed. Re-entry is not allowed.',
            'vehicle_type' => 'visitor',
            'plate_no' => $bookingPlate,
            'visitor_name' => $booking['visitor_name'] ?? '-',
            'resident_name' => $booking['resident_name'] ?: ($booking['resident_email'] ?? '-'),
            'resident_email' => $booking['resident_email'] ?? '-',
            'unit' => booking_unit_text($booking),
            'slot' => booking_slot_text($booking)
        ]);
    }

    if ($status === 'checked_in') {
        insert_gate_log($pdo, [
            'booking_id' => (int)$booking['id'],
            'plate_no' => $bookingPlate,
            'input_value' => $inputRaw,
            'vehicle_type' => 'visitor',
            'guard_id' => $guardId,
            'gate_action' => 'ENTRY',
            'decision' => 'DENY',
            'reason' => 'Visitor is already checked in.'
        ]);

        json_reply([
            'success' => false,
            'message' => 'Visitor is already checked in. Duplicate entry is not allowed. Please record EXIT first.',
            'vehicle_type' => 'visitor',
            'plate_no' => $bookingPlate,
            'visitor_name' => $booking['visitor_name'] ?? '-',
            'resident_name' => $booking['resident_name'] ?: ($booking['resident_email'] ?? '-'),
            'resident_email' => $booking['resident_email'] ?? '-',
            'unit' => booking_unit_text($booking),
            'slot' => booking_slot_text($booking)
        ]);
    }

    $slotId = $hasSlotId ? (int)($booking['slot_id'] ?? 0) : 0;
    $slotText = booking_slot_text($booking);

    if ($hasSlotId && $slotId <= 0) {
        $slot = allocate_visitor_slot($pdo);

        if (!$slot) {
            $sets = ["status = 'waiting'"];

            if ($hasUpdatedAt) {
                $sets[] = "updated_at = NOW()";
            }

            $stmt = $pdo->prepare("
                UPDATE bookings
                SET " . implode(', ', $sets) . "
                WHERE id = ?
            ");
            $stmt->execute([(int)$booking['id']]);

            insert_gate_log($pdo, [
                'booking_id' => (int)$booking['id'],
                'plate_no' => $bookingPlate,
                'input_value' => $inputRaw,
                'vehicle_type' => 'visitor',
                'guard_id' => $guardId,
                'gate_action' => 'ENTRY',
                'decision' => 'DENY',
                'reason' => 'Visitor parking is full. Booking moved to waiting status.'
            ]);

            json_reply([
                'success' => false,
                'message' => 'Visitor parking is full. Booking is waiting for available parking slot.',
                'vehicle_type' => 'visitor',
                'plate_no' => $bookingPlate,
                'visitor_name' => $booking['visitor_name'] ?? '-',
                'resident_name' => $booking['resident_name'] ?: ($booking['resident_email'] ?? '-'),
                'resident_email' => $booking['resident_email'] ?? '-',
                'unit' => booking_unit_text($booking),
                'slot' => '-'
            ]);
        }

        $slotId = (int)$slot['id'];
        $slotText = $slot['block_name'] . ' ' . $slot['slot_no'];

        $sets = [
            "status = 'allocated'",
            "slot_id = ?"
        ];

        $params = [$slotId];

        if ($hasUpdatedAt) {
            $sets[] = "updated_at = NOW()";
        }

        $params[] = (int)$booking['id'];

        $stmt = $pdo->prepare("
            UPDATE bookings
            SET " . implode(', ', $sets) . "
            WHERE id = ?
        ");
        $stmt->execute($params);
    }

    if ($hasSlotId && $slotId > 0) {
        set_slot_status($pdo, $slotId, 'occupied');
    }

    $sets = ["status = 'checked_in'"];

    if ($hasUpdatedAt) {
        $sets[] = "updated_at = NOW()";
    }

    $stmt = $pdo->prepare("
        UPDATE bookings
        SET " . implode(', ', $sets) . "
        WHERE id = ?
    ");
    $stmt->execute([(int)$booking['id']]);

    insert_gate_log($pdo, [
        'booking_id' => (int)$booking['id'],
        'plate_no' => $bookingPlate,
        'input_value' => $inputRaw,
        'vehicle_type' => 'visitor',
        'guard_id' => $guardId,
        'gate_action' => 'ENTRY',
        'decision' => 'ALLOW',
        'reason' => 'Visitor entry allowed.'
    ]);

    if (function_exists('log_audit')) {
        log_audit('VISITOR_ENTRY_ALLOWED', 'Visitor entry allowed. Booking #' . $booking['id'] . ', Plate: ' . $bookingPlate);
    }

    json_reply([
        'success' => true,
        'message' => $isMultipleInOut
            ? 'Visitor entry allowed. Multiple In-Out pass can be used again until ' . booking_valid_until_text($booking) . '.'
            : 'Visitor entry allowed. One Time pass can only be used once. After EXIT, it will be completed.',
        'vehicle_type' => 'visitor',
        'plate_no' => $bookingPlate,
        'visitor_name' => $booking['visitor_name'] ?? '-',
        'resident_name' => $booking['resident_name'] ?: ($booking['resident_email'] ?? '-'),
        'resident_email' => $booking['resident_email'] ?? '-',
        'unit' => booking_unit_text($booking),
        'slot' => $slotText,
        'visit_type' => $visitTypeText,
        'valid_until' => booking_valid_until_text($booking)
    ]);
}

/*
|--------------------------------------------------------------------------
| 5. EXIT handling
|--------------------------------------------------------------------------
*/
if ($gateAction === 'EXIT') {
    if (in_array($status, ['completed', 'checked_out', 'closed'], true)) {
        insert_gate_log($pdo, [
            'booking_id' => (int)$booking['id'],
            'plate_no' => $bookingPlate,
            'input_value' => $inputRaw,
            'vehicle_type' => 'visitor',
            'guard_id' => $guardId,
            'gate_action' => 'EXIT',
            'decision' => 'DENY',
            'reason' => 'This visitor pass has already been completed.'
        ]);

        json_reply([
            'success' => false,
            'message' => 'This visitor pass has already been completed. It cannot be used again.',
            'vehicle_type' => 'visitor',
            'plate_no' => $bookingPlate,
            'visitor_name' => $booking['visitor_name'] ?? '-',
            'resident_name' => $booking['resident_name'] ?: ($booking['resident_email'] ?? '-'),
            'resident_email' => $booking['resident_email'] ?? '-',
            'unit' => booking_unit_text($booking),
            'slot' => booking_slot_text($booking),
            'visit_type' => $visitTypeText
        ]);
    }

    if ($status !== 'checked_in') {
        insert_gate_log($pdo, [
            'booking_id' => (int)$booking['id'],
            'plate_no' => $bookingPlate,
            'input_value' => $inputRaw,
            'vehicle_type' => 'visitor',
            'guard_id' => $guardId,
            'gate_action' => 'EXIT',
            'decision' => 'DENY',
            'reason' => 'Visitor is not currently checked in.'
        ]);

        json_reply([
            'success' => false,
            'message' => 'Visitor is not currently checked in. EXIT cannot be recorded.',
            'vehicle_type' => 'visitor',
            'plate_no' => $bookingPlate,
            'visitor_name' => $booking['visitor_name'] ?? '-',
            'resident_name' => $booking['resident_name'] ?: ($booking['resident_email'] ?? '-'),
            'resident_email' => $booking['resident_email'] ?? '-',
            'unit' => booking_unit_text($booking),
            'slot' => booking_slot_text($booking)
        ]);
    }

    $slotId = $hasSlotId ? (int)($booking['slot_id'] ?? 0) : 0;
    $slotText = booking_slot_text($booking);
    $overstayText = $isExpiredByTime ? ' Overstay detected because the pass valid time has ended.' : '';

    if ($isMultipleInOut && !$isExpiredByTime) {
        /*
         * Multiple In-Out:
         * Visitor can leave and enter again within the 24-hour valid period.
         * Keep the same visitor parking slot reserved, so re-entry is still possible.
         */
        $sets = ["status = 'allocated'"];

        if ($hasUpdatedAt) {
            $sets[] = "updated_at = NOW()";
        }

        $stmt = $pdo->prepare("
            UPDATE bookings
            SET " . implode(', ', $sets) . "
            WHERE id = ?
        ");
        $stmt->execute([(int)$booking['id']]);

        if ($slotId > 0) {
            set_slot_status($pdo, $slotId, 'reserved');
        }

        insert_gate_log($pdo, [
            'booking_id' => (int)$booking['id'],
            'plate_no' => $bookingPlate,
            'input_value' => $inputRaw,
            'vehicle_type' => 'visitor',
            'guard_id' => $guardId,
            'gate_action' => 'EXIT',
            'decision' => 'ALLOW',
            'reason' => 'Multiple In-Out visitor exit recorded. Pass remains active until ' . booking_valid_until_text($booking) . '.'
        ]);

        if (function_exists('log_audit')) {
            log_audit('MULTIPLE_IN_OUT_VISITOR_EXIT_ALLOWED', 'Multiple In-Out visitor exit recorded. Booking #' . $booking['id'] . ', Plate: ' . $bookingPlate);
        }

        json_reply([
            'success' => true,
            'message' => 'Visitor exit recorded. Multiple In-Out pass is still active until ' . booking_valid_until_text($booking) . '. Re-entry is allowed within the valid time.',
            'vehicle_type' => 'visitor',
            'plate_no' => $bookingPlate,
            'visitor_name' => $booking['visitor_name'] ?? '-',
            'resident_name' => $booking['resident_name'] ?: ($booking['resident_email'] ?? '-'),
            'resident_email' => $booking['resident_email'] ?? '-',
            'unit' => booking_unit_text($booking),
            'slot' => $slotText,
            'visit_type' => $visitTypeText
        ]);
    }

    /*
     * One Time:
     * EXIT completes the booking. After this, the same QR / plate cannot be
     * used for another entry.
     *
     * Multiple In-Out after 24 hours:
     * EXIT is recorded, then the pass is closed because the valid time ended.
     */
    $sets = ["status = 'completed'"];

    if ($hasUpdatedAt) {
        $sets[] = "updated_at = NOW()";
    }

    $stmt = $pdo->prepare("
        UPDATE bookings
        SET " . implode(', ', $sets) . "
        WHERE id = ?
    ");
    $stmt->execute([(int)$booking['id']]);

    if ($slotId > 0) {
        release_slot($pdo, $slotId);
    }

    $nextWaiting = assign_next_waiting($pdo, $slotId);

    $exitReason = $isMultipleInOut && $isExpiredByTime
        ? 'Multiple In-Out visitor exit recorded after valid time ended. Pass completed and parking slot released.'
        : 'One Time visitor exit recorded. Pass completed and parking slot released.';

    insert_gate_log($pdo, [
        'booking_id' => (int)$booking['id'],
        'plate_no' => $bookingPlate,
        'input_value' => $inputRaw,
        'vehicle_type' => 'visitor',
        'guard_id' => $guardId,
        'gate_action' => 'EXIT',
        'decision' => 'ALLOW',
        'reason' => $exitReason . $overstayText
    ]);

    if (function_exists('log_audit')) {
        log_audit('VISITOR_EXIT_ALLOWED', 'Visitor exit recorded. Booking #' . $booking['id'] . ', Plate: ' . $bookingPlate . ', Visit type: ' . $visitTypeText);
    }

    $exitMessage = $isMultipleInOut && $isExpiredByTime
        ? 'Visitor exit recorded. Multiple In-Out valid time has ended, so the pass is now completed.'
        : 'Visitor exit recorded. One Time pass is now completed and cannot be used again.';

    if ($isExpiredByTime) {
        $exitMessage .= ' Overstay detected. Valid until: ' . booking_valid_until_text($booking);
    }

    json_reply([
        'success' => true,
        'message' => $exitMessage,
        'vehicle_type' => 'visitor',
        'plate_no' => $bookingPlate,
        'visitor_name' => $booking['visitor_name'] ?? '-',
        'resident_name' => $booking['resident_name'] ?: ($booking['resident_email'] ?? '-'),
        'resident_email' => $booking['resident_email'] ?? '-',
        'unit' => booking_unit_text($booking),
        'slot' => $slotText,
        'visit_type' => $visitTypeText,
        'next_waiting_assigned' => $nextWaiting['assigned'],
        'next_waiting_visitor' => $nextWaiting['visitor_name'],
        'next_waiting_plate' => $nextWaiting['plate_no'],
        'next_waiting_slot' => $nextWaiting['slot']
    ]);
}

json_reply([
    'success' => false,
    'message' => 'Unknown error.'
]);