<?php
require_once '../core/security.php';
require_login(['resident']);

$pdo = db();

$residentId = (int)($_SESSION['uid'] ?? 0);
$residentEmail = $_SESSION['email'] ?? '';
$message = '';
$error = '';
$currentBillingMonth = date('Y-m');

function rv_pay_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function rv_pay_has_column(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function rv_pay_count(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function rv_pay_sum(PDO $pdo, string $sql, array $params = []): float {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (float)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.00;
    }
}

function rv_pay_clean_plate($plate): string {
    $plate = strtoupper(trim((string)$plate));
    return preg_replace('/[^A-Z0-9]/', '', $plate);
}

function rv_pay_money($amount): string {
    return 'RM ' . number_format((float)$amount, 2);
}

function rv_pay_text($value): string {
    return ($value !== null && $value !== '') ? (string)$value : '-';
}

function rv_pay_public_file_url($path): string {
    $path = trim((string)$path);
    if ($path === '') {
        return '';
    }

    $path = str_replace('\\\\', '/', $path);
    $path = str_replace('\\', '/', $path);

    if (preg_match('/^https?:\/\//i', $path) || str_starts_with($path, 'data:image/')) {
        return $path;
    }

    $clean = ltrim($path, '/');
    $clean = preg_replace('#^(\.\./)+#', '', $clean);
    $clean = preg_replace('#^\./+#', '', $clean);
    $clean = preg_replace('#^public/#', '', $clean);
    $clean = preg_replace('#^apartment/public/#', '', $clean);
    $clean = preg_replace('#^htdocs/apartment/public/#', '', $clean);

    foreach (['vehicle_photos/', 'profile_photos/', 'uploads/', 'payment_receipts/'] as $folder) {
        $pos = strpos($clean, $folder);
        if ($pos !== false) {
            $clean = substr($clean, $pos);
            break;
        }
    }

    return $clean;
}


function rv_pay_ensure_vehicle_photo_column(PDO $pdo): void {
    if (!rv_pay_table_exists($pdo, 'resident_vehicles')) {
        return;
    }

    if (rv_pay_has_column($pdo, 'resident_vehicles', 'vehicle_photo')) {
        return;
    }

    try {
        $pdo->exec("ALTER TABLE resident_vehicles ADD COLUMN vehicle_photo VARCHAR(255) NULL AFTER plate_no");
    } catch (Throwable $e) {
        // If ALTER TABLE is not allowed, the page will still work without photo saving.
    }
}

function rv_pay_upload_vehicle_photo(int $residentId, string $fieldName = 'vehicle_photo'): ?string {
    if (empty($_FILES[$fieldName]['name'])) {
        return null;
    }

    $upload = $_FILES[$fieldName];

    if ($upload['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Vehicle photo upload failed. Please try again.');
    }

    if ($upload['size'] > 5 * 1024 * 1024) {
        throw new Exception('Vehicle photo is too large. Maximum size is 5MB.');
    }

    $ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];

    if (!in_array($ext, $allowed, true)) {
        throw new Exception('Vehicle photo must be JPG, PNG, or WEBP.');
    }

    $uploadDir = __DIR__ . '/vehicle_photos';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $safeName = 'vehicle_' . $residentId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    $target = $uploadDir . '/' . $safeName;

    if (!move_uploaded_file($upload['tmp_name'], $target)) {
        throw new Exception('Cannot save vehicle photo. Please check folder permission.');
    }

    return 'vehicle_photos/' . $safeName;
}

function rv_pay_month_expiry(?string $billingMonth): string {
    $billingMonth = $billingMonth ?: date('Y-m');
    $time = strtotime($billingMonth . '-01 +1 month -1 day');
    return $time ? date('d M Y', $time) : '-';
}

function rv_pay_unit_text(array $row): string {
    if (empty($row['unit_no'])) {
        return 'No active unit assigned';
    }

    return 'Block ' . $row['block_no'] . ' / Floor ' . $row['floor_no'] . ' / Unit ' . $row['unit_no'];
}

function rv_pay_block_name($blockNo): string {
    $blockNo = strtoupper(trim((string)$blockNo));

    if ($blockNo === '') {
        return '';
    }

    if (str_starts_with($blockNo, 'BLOCK')) {
        return $blockNo;
    }

    return 'Block ' . $blockNo;
}

function rv_pay_status_class($status): string {
    return match ($status) {
        'active', 'approved', 'paid' => 'badge-green',
        'pending', 'pending_verification' => 'badge-yellow',
        'rejected', 'overdue', 'cancelled' => 'badge-red',
        'unpaid' => 'badge-red',
        'inactive' => 'badge-gray',
        default => 'badge-gray'
    };
}

function rv_pay_ensure_invoice(PDO $pdo, array $assignment, string $billingMonth): void {
    if (!rv_pay_table_exists($pdo, 'parking_payments')) {
        return;
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM parking_payments WHERE assignment_id = ? AND billing_month = ? LIMIT 1");
        $stmt->execute([(int)$assignment['id'], $billingMonth]);

        if ($stmt->fetch()) {
            return;
        }

        $stmt = $pdo->prepare("\n            INSERT INTO parking_payments\n            (assignment_id, resident_id, billing_month, amount, payment_status, created_at)\n            VALUES\n            (?, ?, ?, ?, 'unpaid', NOW())\n        ");
        $stmt->execute([
            (int)$assignment['id'],
            (int)$assignment['resident_id'],
            $billingMonth,
            (float)$assignment['monthly_fee']
        ]);
    } catch (Throwable $e) {
        // ignore invoice creation error to avoid breaking page view
    }
}

function rv_pay_notification_type(PDO $pdo, string $desiredType = 'payment'): string {
    if (!rv_pay_has_column($pdo, 'notifications', 'type')) {
        return $desiredType;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COLUMN_TYPE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'notifications'
            AND COLUMN_NAME = 'type'
            LIMIT 1
        ");
        $stmt->execute();
        $columnType = strtolower((string)$stmt->fetchColumn());

        if (!str_starts_with($columnType, 'enum')) {
            return $desiredType;
        }

        if (str_contains($columnType, "'" . strtolower($desiredType) . "'")) {
            return $desiredType;
        }

        foreach (['payment', 'warning', 'info', 'general'] as $fallbackType) {
            if (str_contains($columnType, "'" . strtolower($fallbackType) . "'")) {
                return $fallbackType;
            }
        }
    } catch (Throwable $e) {
        // fallback below
    }

    return 'general';
}

function rv_pay_insert_notification_once(PDO $pdo, int $userId, string $title, string $message, string $type = 'payment', string $linkUrl = 'resident_vehicles.php'): void {
    if ($userId <= 0 || !rv_pay_table_exists($pdo, 'notifications')) {
        return;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id
            FROM notifications
            WHERE user_id = ?
            AND title = ?
            AND message = ?
            LIMIT 1
        ");
        $stmt->execute([$userId, $title, $message]);

        if ($stmt->fetch()) {
            return;
        }

        $columns = ['user_id', 'title', 'message'];
        $placeholders = ['?', '?', '?'];
        $params = [$userId, $title, $message];

        if (rv_pay_has_column($pdo, 'notifications', 'type')) {
            $columns[] = 'type';
            $placeholders[] = '?';
            $params[] = rv_pay_notification_type($pdo, $type);
        } elseif (rv_pay_has_column($pdo, 'notifications', 'category')) {
            $columns[] = 'category';
            $placeholders[] = '?';
            $params[] = $type;
        }

        if (rv_pay_has_column($pdo, 'notifications', 'link_url')) {
            $columns[] = 'link_url';
            $placeholders[] = '?';
            $params[] = $linkUrl;
        }

        if (rv_pay_has_column($pdo, 'notifications', 'is_read')) {
            $columns[] = 'is_read';
            $placeholders[] = '0';
        }

        if (rv_pay_has_column($pdo, 'notifications', 'created_at')) {
            $columns[] = 'created_at';
            $placeholders[] = 'NOW()';
        }

        $sql = "INSERT INTO notifications (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } catch (Throwable $e) {
        // Do not break the parking page if notification insert fails.
    }
}

function rv_pay_notify_unpaid_once(PDO $pdo, array $assignment, string $billingMonth): void {
    $paymentStatus = strtolower((string)($assignment['payment_status'] ?? ''));

    if (!in_array($paymentStatus, ['unpaid', 'overdue', 'rejected'], true)) {
        return;
    }

    $residentId = (int)($assignment['resident_id'] ?? 0);
    $plateNo = rv_pay_text($assignment['plate_no'] ?? '-');
    $slotNo = trim((($assignment['block_name'] ?? '') ? ($assignment['block_name'] . ' / ') : '') . ($assignment['slot_no'] ?? '-'));
    $amount = (float)($assignment['payment_amount'] ?? $assignment['monthly_fee'] ?? 80.00);
    $monthText = date('F Y', strtotime($billingMonth . '-01'));

    $title = 'Parking Payment Reminder';
    $message = 'Your resident parking payment for ' . $monthText .
        ' is still unpaid. Plate: ' . $plateNo .
        '. Slot: ' . $slotNo .
        '. Amount: RM ' . number_format($amount, 2) .
        '. Please complete payment to continue guard access.';

    rv_pay_insert_notification_once($pdo, $residentId, $title, $message, 'payment', 'resident_vehicles.php');
}

function rv_pay_ensure_request_selected_slot_column(PDO $pdo): void {
    if (!rv_pay_table_exists($pdo, 'resident_parking_requests')) {
        return;
    }

    if (rv_pay_has_column($pdo, 'resident_parking_requests', 'selected_slot_id')) {
        return;
    }

    try {
        $pdo->exec("
            ALTER TABLE resident_parking_requests
            ADD COLUMN selected_slot_id INT NULL AFTER preferred_block
        ");
    } catch (Throwable $e) {
        // Ignore alter error to avoid breaking the page. The insert will still fallback to preferred_block.
    }
}

function rv_pay_request_selected_slot_column(PDO $pdo): ?string {
    foreach (['selected_slot_id', 'slot_id', 'parking_slot_id', 'requested_slot_id', 'preferred_slot_id', 'resident_selected_slot_id'] as $column) {
        if (rv_pay_has_column($pdo, 'resident_parking_requests', $column)) {
            return $column;
        }
    }

    return null;
}


$parkingModuleReady = rv_pay_table_exists($pdo, 'resident_parking_requests')
    && rv_pay_table_exists($pdo, 'resident_parking_assignments')
    && rv_pay_table_exists($pdo, 'parking_payments')
    && rv_pay_table_exists($pdo, 'parking_slots');

rv_pay_ensure_vehicle_photo_column($pdo);

if ($parkingModuleReady) {
    rv_pay_ensure_request_selected_slot_column($pdo);
}

$requestSelectedSlotColumn = $parkingModuleReady ? rv_pay_request_selected_slot_column($pdo) : null;

$hasFullName = rv_pay_has_column($pdo, 'users', 'full_name');
$hasContact = rv_pay_has_column($pdo, 'users', 'contact_number');
$hasProfilePhoto = rv_pay_has_column($pdo, 'users', 'profile_photo');
$hasVehicleModel = rv_pay_has_column($pdo, 'resident_vehicles', 'vehicle_model');
$hasVehicleColor = rv_pay_has_column($pdo, 'resident_vehicles', 'vehicle_color');
$hasIsPrimary = rv_pay_has_column($pdo, 'resident_vehicles', 'is_primary');
$hasVehiclePhoto = rv_pay_has_column($pdo, 'resident_vehicles', 'vehicle_photo');
$hasVehicleUpdatedAt = rv_pay_has_column($pdo, 'resident_vehicles', 'updated_at');
$hasPaymentResidentRemark = rv_pay_has_column($pdo, 'parking_payments', 'resident_remark');
$hasPaymentUpdatedAt = rv_pay_has_column($pdo, 'parking_payments', 'updated_at');

$residentNameSql = $hasFullName ? "u.full_name AS resident_name" : "NULL AS resident_name";
$residentContactSql = $hasContact ? "u.contact_number AS resident_contact" : "NULL AS resident_contact";
$residentPhotoSql = $hasProfilePhoto ? "u.profile_photo AS profile_photo" : "NULL AS profile_photo";

$stmt = $pdo->prepare("\n    SELECT\n        u.id,\n        u.email,\n        {$residentNameSql},\n        {$residentContactSql},\n        {$residentPhotoSql},\n        ru.unit_id,\n        a.apartment_name,\n        un.block_no,\n        un.floor_no,\n        un.unit_no\n    FROM users u\n    LEFT JOIN resident_units ru ON ru.resident_id = u.id AND ru.status = 'active'\n    LEFT JOIN units un ON un.id = ru.unit_id\n    LEFT JOIN apartments a ON a.id = un.apartment_id\n    WHERE u.id = ?\n    LIMIT 1\n");
$stmt->execute([$residentId]);
$resident = $stmt->fetch();

if (!$resident) {
    $resident = [
        'resident_name' => $residentEmail,
        'resident_contact' => '',
        'profile_photo' => '',
        'apartment_id' => null,
        'apartment_name' => '',
        'block_no' => '',
        'floor_no' => '',
        'unit_no' => ''
    ];
}

$residentName = $resident['resident_name'] ?: explode('@', $residentEmail)[0];
$unitText = rv_pay_unit_text($resident);
$preferredBlock = rv_pay_block_name($resident['block_no'] ?? '');
$residentApartmentId = (int)($resident['apartment_id'] ?? 0);

$availableResidentSlots = [];

if ($parkingModuleReady) {
    try {
        $slotWhere = "ps.slot_type = 'Resident' AND ps.status = 'available'";
        $slotParams = [];

        if ($residentApartmentId > 0) {
            $slotWhere .= " AND ps.apartment_id = ?";
            $slotParams[] = $residentApartmentId;
        }

        if ($requestSelectedSlotColumn) {
            $slotWhere .= " AND ps.id NOT IN (
                SELECT COALESCE({$requestSelectedSlotColumn}, 0)
                FROM resident_parking_requests
                WHERE status = 'pending'
                AND {$requestSelectedSlotColumn} IS NOT NULL
            )";
        }

        $stmt = $pdo->prepare("
            SELECT ps.id, ps.block_name, ps.slot_no, ps.status
            FROM parking_slots ps
            WHERE {$slotWhere}
            ORDER BY ps.block_name ASC, ps.slot_no ASC
        ");
        $stmt->execute($slotParams);
        $availableResidentSlots = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        $availableResidentSlots = [];
    }
}

$profilePhoto = trim((string)($resident['profile_photo'] ?? ''));
$profilePhotoUrl = ($hasProfilePhoto && $profilePhoto !== '') ? rv_pay_public_file_url($profilePhoto) : '';

$residentInitial = strtoupper(substr(trim($residentName), 0, 1));
if ($residentInitial === '') {
    $residentInitial = 'R';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'add_vehicle_and_request') {
                if (!$parkingModuleReady) {
                    throw new Exception('Parking module database is not installed yet. Please run the SQL file first.');
                }

                $plateNo = rv_pay_clean_plate($_POST['plate_no'] ?? '');
                $vehicleModel = trim($_POST['vehicle_model'] ?? '');
                $vehicleColor = trim($_POST['vehicle_color'] ?? '');
                $reason = trim($_POST['reason'] ?? '');
                $selectedSlotId = (int)($_POST['selected_slot_id'] ?? 0);

                if ($selectedSlotId <= 0) {
                    throw new Exception('Please choose and confirm your preferred parking slot.');
                }

                if (!$requestSelectedSlotColumn) {
                    throw new Exception('Resident selected parking slot column is missing. Please refresh this page or contact admin.');
                }

                $slotWhere = "id = ? AND slot_type = 'Resident' AND status = 'available'";
                $slotParams = [$selectedSlotId];

                if ($residentApartmentId > 0) {
                    $slotWhere .= " AND apartment_id = ?";
                    $slotParams[] = $residentApartmentId;
                }

                $stmt = $pdo->prepare("
                    SELECT id, block_name, slot_no, status
                    FROM parking_slots
                    WHERE {$slotWhere}
                    LIMIT 1
                ");
                $stmt->execute($slotParams);
                $selectedResidentSlot = $stmt->fetch();

                if (!$selectedResidentSlot) {
                    throw new Exception('The selected parking slot is not available anymore. Please choose another slot.');
                }

                $samePendingSlot = rv_pay_count($pdo, "
                    SELECT COUNT(*)
                    FROM resident_parking_requests
                    WHERE {$requestSelectedSlotColumn} = ?
                    AND status = 'pending'
                ", [$selectedSlotId]);

                if ($samePendingSlot > 0) {
                    throw new Exception('This parking slot is currently selected in another pending request. Please choose another slot.');
                }

                $selectedSlotText = trim((string)(($selectedResidentSlot['block_name'] ?? '') . ' / ' . ($selectedResidentSlot['slot_no'] ?? '')));

                if ($plateNo === '') {
                    throw new Exception('Please enter your vehicle plate number.');
                }

                if (strlen($plateNo) < 3) {
                    throw new Exception('Vehicle plate number is too short.');
                }

                if (strlen($plateNo) > 20) {
                    throw new Exception('Vehicle plate number is too long.');
                }

                if (rv_pay_table_exists($pdo, 'blacklisted_plates')) {
                    $stmt = $pdo->prepare("SELECT id FROM blacklisted_plates WHERE plate_no = ? AND status = 'active' LIMIT 1");
                    $stmt->execute([$plateNo]);

                    if ($stmt->fetch()) {
                        throw new Exception('This plate number is blacklisted. Please contact management.');
                    }
                }

                $activeAssignmentCount = rv_pay_count($pdo, "\n                    SELECT COUNT(*)\n                    FROM resident_parking_assignments\n                    WHERE resident_id = ?\n                    AND status = 'active'\n                ", [$residentId]);

                if ($activeAssignmentCount >= 2) {
                    throw new Exception('You already have 2 resident parking slots. Please contact admin if you need more.');
                }

                $requestType = $activeAssignmentCount > 0 ? 'additional_slot' : 'first_slot';

                $pendingResident = rv_pay_count($pdo, "\n                    SELECT COUNT(*)\n                    FROM resident_parking_requests\n                    WHERE resident_id = ?\n                    AND status = 'pending'\n                ", [$residentId]);

                if ($pendingResident > 0) {
                    throw new Exception('You already have a pending parking request. Please wait for admin approval first.');
                }

                $stmt = $pdo->prepare("SELECT * FROM resident_vehicles WHERE plate_no = ? LIMIT 1");
                $stmt->execute([$plateNo]);
                $existingVehicle = $stmt->fetch();

                $vehiclePhoto = $hasVehiclePhoto ? rv_pay_upload_vehicle_photo($residentId, 'vehicle_photo') : null;

                if ($hasVehiclePhoto && $vehiclePhoto === null && (!$existingVehicle || empty($existingVehicle['vehicle_photo'] ?? ''))) {
                    throw new Exception('Please upload your vehicle photo.');
                }

                if ($existingVehicle && (int)$existingVehicle['resident_id'] !== $residentId) {
                    throw new Exception('This vehicle plate is already registered under another resident.');
                }

                if ($existingVehicle) {
                    $vehicleId = (int)$existingVehicle['id'];

                    $alreadyAssigned = rv_pay_count($pdo, "\n                        SELECT COUNT(*)\n                        FROM resident_parking_assignments\n                        WHERE vehicle_id = ?\n                        AND status = 'active'\n                    ", [$vehicleId]);

                    if ($alreadyAssigned > 0) {
                        throw new Exception('This vehicle already has an active resident parking slot.');
                    }

                    $sets = ["status = 'active'"];
                    $params = [];

                    if ($hasVehicleModel) {
                        $sets[] = 'vehicle_model = ?';
                        $params[] = $vehicleModel !== '' ? $vehicleModel : ($existingVehicle['vehicle_model'] ?? null);
                    }

                    if ($hasVehicleColor) {
                        $sets[] = 'vehicle_color = ?';
                        $params[] = $vehicleColor !== '' ? $vehicleColor : ($existingVehicle['vehicle_color'] ?? null);
                    }

                    if ($hasVehiclePhoto && $vehiclePhoto !== null) {
                        $sets[] = 'vehicle_photo = ?';
                        $params[] = $vehiclePhoto;
                    }

                    if ($hasVehicleUpdatedAt) {
                        $sets[] = 'updated_at = NOW()';
                    }

                    $params[] = $vehicleId;
                    $params[] = $residentId;

                    $stmt = $pdo->prepare("UPDATE resident_vehicles SET " . implode(', ', $sets) . " WHERE id = ? AND resident_id = ?");
                    $stmt->execute($params);
                } else {
                    if (rv_pay_table_exists($pdo, 'bookings')) {
                        $activeVisitorBooking = rv_pay_count($pdo, "\n                            SELECT COUNT(*)\n                            FROM bookings\n                            WHERE plate_no = ?\n                            AND status IN ('pending', 'approved', 'allocated', 'waiting', 'checked_in')\n                        ", [$plateNo]);

                        if ($activeVisitorBooking > 0) {
                            throw new Exception('This plate is currently used in an active visitor booking.');
                        }
                    }

                    $columns = ['resident_id', 'plate_no', 'status', 'created_at'];
                    $marks = ['?', '?', "'active'", 'NOW()'];
                    $values = [$residentId, $plateNo];

                    if ($hasVehicleModel) {
                        $columns[] = 'vehicle_model';
                        $marks[] = '?';
                        $values[] = $vehicleModel !== '' ? $vehicleModel : null;
                    }

                    if ($hasVehicleColor) {
                        $columns[] = 'vehicle_color';
                        $marks[] = '?';
                        $values[] = $vehicleColor !== '' ? $vehicleColor : null;
                    }

                    if ($hasVehiclePhoto) {
                        $columns[] = 'vehicle_photo';
                        $marks[] = '?';
                        $values[] = $vehiclePhoto;
                    }

                    if ($hasIsPrimary) {
                        $activeVehicleCount = rv_pay_count($pdo, "SELECT COUNT(*) FROM resident_vehicles WHERE resident_id = ? AND status = 'active'", [$residentId]);
                        $columns[] = 'is_primary';
                        $marks[] = '?';
                        $values[] = $activeVehicleCount === 0 ? 1 : 0;
                    }

                    $stmt = $pdo->prepare("INSERT INTO resident_vehicles (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $marks) . ")");
                    $stmt->execute($values);
                    $vehicleId = (int)$pdo->lastInsertId();
                }

                $pendingSameVehicle = rv_pay_count($pdo, "\n                    SELECT COUNT(*)\n                    FROM resident_parking_requests\n                    WHERE resident_id = ?\n                    AND vehicle_id = ?\n                    AND status = 'pending'\n                ", [$residentId, $vehicleId]);

                if ($pendingSameVehicle > 0) {
                    throw new Exception('This vehicle already has a pending parking request.');
                }

                $stmt = $pdo->prepare("\n                    INSERT INTO resident_parking_requests\n                    (resident_id, vehicle_id, request_type, preferred_block, {$requestSelectedSlotColumn}, reason, status, requested_at)\n                    VALUES\n                    (?, ?, ?, ?, ?, ?, 'pending', NOW())\n                ");
                $stmt->execute([
                    $residentId,
                    $vehicleId,
                    $requestType,
                    $selectedSlotText !== '' ? $selectedSlotText : ($preferredBlock ?: null),
                    $selectedSlotId,
                    $reason !== '' ? $reason : null
                ]);

                if (function_exists('log_audit')) {
                    log_audit('RESIDENT_PARKING_REQUEST_CREATED', 'Resident added/requested parking for plate: ' . $plateNo . ', Type: ' . $requestType);
                }

                $message = 'Vehicle added and parking request sent to admin successfully.';
            }

            if ($action === 'submit_payment') {
                if (!$parkingModuleReady) {
                    throw new Exception('Parking module database is not installed yet.');
                }

                $paymentId = (int)($_POST['payment_id'] ?? 0);
                $paymentMethod = trim($_POST['payment_method'] ?? 'Online Transfer');
                $residentRemark = trim($_POST['resident_remark'] ?? '');

                if ($paymentId <= 0) {
                    throw new Exception('Invalid payment selected.');
                }

                $stmt = $pdo->prepare("\n                    SELECT pp.*, rpa.status AS assignment_status\n                    FROM parking_payments pp\n                    JOIN resident_parking_assignments rpa ON rpa.id = pp.assignment_id\n                    WHERE pp.id = ?\n                    AND pp.resident_id = ?\n                    LIMIT 1\n                ");
                $stmt->execute([$paymentId, $residentId]);
                $payment = $stmt->fetch();

                if (!$payment) {
                    throw new Exception('Payment record not found.');
                }

                if ($payment['payment_status'] === 'paid') {
                    throw new Exception('This parking fee is already paid.');
                }

                if ($payment['assignment_status'] !== 'active') {
                    throw new Exception('This parking assignment is not active.');
                }

                $receiptFile = $payment['receipt_file'] ?? null;
                $receiptRequired = (strcasecmp($paymentMethod, 'DuitNow QR') === 0 || stripos($paymentMethod, 'TNG') !== false);

                if ($receiptRequired && empty($_FILES['receipt_file']['name']) && empty($receiptFile)) {
                    throw new Exception('TNG payment must upload a receipt image before submitting.');
                }

                if (!empty($_FILES['receipt_file']['name'])) {
                    $upload = $_FILES['receipt_file'];

                    if ($upload['error'] !== UPLOAD_ERR_OK) {
                        throw new Exception('Receipt upload failed. Please try again.');
                    }

                    if ($upload['size'] > 5 * 1024 * 1024) {
                        throw new Exception('Receipt file is too large. Maximum size is 5MB.');
                    }

                    $ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
                    $allowed = ['jpg', 'jpeg', 'png', 'webp'];

                    if (!in_array($ext, $allowed, true)) {
                        throw new Exception('Receipt must be an image file: JPG, PNG, or WEBP.');
                    }

                    $receiptDir = __DIR__ . '/payment_receipts';
                    if (!is_dir($receiptDir)) {
                        mkdir($receiptDir, 0775, true);
                    }

                    $safeName = 'parking_' . $residentId . '_' . $paymentId . '_' . date('YmdHis') . '.' . $ext;
                    $target = $receiptDir . '/' . $safeName;

                    if (!move_uploaded_file($upload['tmp_name'], $target)) {
                        throw new Exception('Cannot save receipt file. Please check folder permission.');
                    }

                    $receiptFile = 'payment_receipts/' . $safeName;
                }

                $sets = [
                    "payment_status = 'pending_verification'",
                    "payment_method = ?",
                    "receipt_file = ?"
                ];
                $params = [$paymentMethod, $receiptFile];

                if ($hasPaymentResidentRemark) {
                    $sets[] = 'resident_remark = ?';
                    $params[] = $residentRemark !== '' ? $residentRemark : null;
                }

                if ($hasPaymentUpdatedAt) {
                    $sets[] = 'updated_at = NOW()';
                }

                $params[] = $paymentId;
                $params[] = $residentId;

                $stmt = $pdo->prepare("UPDATE parking_payments SET " . implode(', ', $sets) . " WHERE id = ? AND resident_id = ?");
                $stmt->execute($params);

                if (function_exists('log_audit')) {
                    log_audit('RESIDENT_PARKING_PAYMENT_SUBMITTED', 'Resident submitted parking payment ID: ' . $paymentId);
                }

                $message = 'Payment submitted. Please wait for admin verification before gate access is allowed.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$totalVehicles = rv_pay_count($pdo, "SELECT COUNT(*) FROM resident_vehicles WHERE resident_id = ?", [$residentId]);
$activeVehicles = rv_pay_count($pdo, "SELECT COUNT(*) FROM resident_vehicles WHERE resident_id = ? AND status = 'active'", [$residentId]);
$activeSlots = $parkingModuleReady ? rv_pay_count($pdo, "SELECT COUNT(*) FROM resident_parking_assignments WHERE resident_id = ? AND status = 'active'", [$residentId]) : 0;
$pendingRequests = $parkingModuleReady ? rv_pay_count($pdo, "SELECT COUNT(*) FROM resident_parking_requests WHERE resident_id = ? AND status = 'pending'", [$residentId]) : 0;
$defaultFeeText = 'RM80/month resident parking slot';
$maxSlotsReached = $activeSlots >= 2;

$assignments = [];
$requests = [];
$featuredVehicle = null;

if ($parkingModuleReady) {
    try {
        $stmt = $pdo->prepare("\n            SELECT *\n            FROM resident_parking_assignments\n            WHERE resident_id = ?\n            AND status = 'active'\n        ");
        $stmt->execute([$residentId]);
        $rawAssignments = $stmt->fetchAll();
        foreach ($rawAssignments as $assignment) {
            rv_pay_ensure_invoice($pdo, $assignment, $currentBillingMonth);
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $vehicleModelSql = $hasVehicleModel ? 'rv.vehicle_model' : 'NULL AS vehicle_model';
        $vehicleColorSql = $hasVehicleColor ? 'rv.vehicle_color' : 'NULL AS vehicle_color';
        $vehiclePhotoSql = $hasVehiclePhoto ? 'rv.vehicle_photo' : 'NULL AS vehicle_photo';

        $stmt = $pdo->prepare("\n            SELECT\n                rpa.*,\n                rv.plate_no,\n                {$vehicleModelSql},\n                {$vehicleColorSql},\n                {$vehiclePhotoSql},\n                ps.block_name,\n                ps.slot_no,\n                pp.id AS payment_id,\n                pp.billing_month,\n                pp.amount AS payment_amount,\n                pp.payment_status,\n                pp.payment_method,\n                pp.receipt_file,\n                pp.paid_at,\n                pp.verified_at,\n                pp.admin_remark\n            FROM resident_parking_assignments rpa\n            JOIN resident_vehicles rv ON rv.id = rpa.vehicle_id\n            JOIN parking_slots ps ON ps.id = rpa.slot_id\n            LEFT JOIN parking_payments pp\n                ON pp.assignment_id = rpa.id\n                AND pp.billing_month = ?\n            WHERE rpa.resident_id = ?\n            AND rpa.status = 'active'\n            AND rv.status = 'active'\n            ORDER BY rpa.created_at DESC\n        ");
        $stmt->execute([$currentBillingMonth, $residentId]);
        $assignments = $stmt->fetchAll();

        foreach ($assignments as $assignmentRow) {
            rv_pay_notify_unpaid_once($pdo, $assignmentRow, $currentBillingMonth);
        }
    } catch (Throwable $e) {
        $assignments = [];
    }

    try {
        $vehicleModelSql = $hasVehicleModel ? 'rv.vehicle_model' : 'NULL AS vehicle_model';
        $stmt = $pdo->prepare("\n            SELECT rpr.*, rv.plate_no, {$vehicleModelSql}\n            FROM resident_parking_requests rpr\n            JOIN resident_vehicles rv ON rv.id = rpr.vehicle_id\n            WHERE rpr.resident_id = ?\n            ORDER BY rpr.requested_at DESC\n            LIMIT 8\n        ");
        $stmt->execute([$residentId]);
        $requests = $stmt->fetchAll();
    } catch (Throwable $e) {
        $requests = [];
    }
}

if (!empty($assignments)) {
    $featuredVehicle = $assignments[0];
} else {
    try {
        $vehicleModelSql = $hasVehicleModel ? 'rv.vehicle_model' : 'NULL AS vehicle_model';
        $vehicleColorSql = $hasVehicleColor ? 'rv.vehicle_color' : 'NULL AS vehicle_color';
        $vehiclePhotoSql = $hasVehiclePhoto ? 'rv.vehicle_photo' : 'NULL AS vehicle_photo';
        $updatedOrder = $hasVehicleUpdatedAt ? 'rv.updated_at DESC, ' : '';

        $stmt = $pdo->prepare("
            SELECT
                rv.id,
                rv.plate_no,
                {$vehicleModelSql},
                {$vehicleColorSql},
                {$vehiclePhotoSql},
                rv.status
            FROM resident_vehicles rv
            WHERE rv.resident_id = ?
            AND rv.status = 'active'
            ORDER BY {$updatedOrder} rv.id DESC
            LIMIT 1
        ");
        $stmt->execute([$residentId]);
        $featuredVehicle = $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        $featuredVehicle = null;
    }
}

$featuredVehiclePhotoUrl = '';
if (!empty($featuredVehicle['vehicle_photo'])) {
    $featuredVehiclePhotoUrl = rv_pay_public_file_url($featuredVehicle['vehicle_photo']);
}


$latestRequestByVehicleId = [];
if (!empty($requests)) {
    foreach ($requests as $requestRow) {
        $requestVehicleId = (int)($requestRow['vehicle_id'] ?? 0);
        if ($requestVehicleId > 0 && !isset($latestRequestByVehicleId[$requestVehicleId])) {
            $latestRequestByVehicleId[$requestVehicleId] = $requestRow;
        }
    }
}

$vehicleShowcaseCards = [];
if ($parkingModuleReady) {
    try {
        $vehicleModelSql = $hasVehicleModel ? 'rv.vehicle_model' : 'NULL AS vehicle_model';
        $vehicleColorSql = $hasVehicleColor ? 'rv.vehicle_color' : 'NULL AS vehicle_color';
        $vehiclePhotoSql = $hasVehiclePhoto ? 'rv.vehicle_photo' : 'NULL AS vehicle_photo';
        $stmt = $pdo->prepare("
            SELECT
                rv.id,
                rv.plate_no,
                {$vehicleModelSql},
                {$vehicleColorSql},
                {$vehiclePhotoSql},
                rv.status,
                rpa.id AS assignment_id,
                rpa.monthly_fee,
                ps.block_name,
                ps.slot_no,
                pp.payment_status,
                pp.amount AS payment_amount,
                pp.billing_month
            FROM resident_vehicles rv
            LEFT JOIN resident_parking_assignments rpa
                ON rpa.vehicle_id = rv.id
                AND rpa.resident_id = ?
                AND rpa.status = 'active'
            LEFT JOIN parking_slots ps ON ps.id = rpa.slot_id
            LEFT JOIN parking_payments pp
                ON pp.assignment_id = rpa.id
                AND pp.billing_month = ?
            WHERE rv.resident_id = ?
            AND rv.status = 'active'
            ORDER BY CASE WHEN rv.id = ? THEN 0 ELSE 1 END, rv.id DESC
        ");
        $stmt->execute([$residentId, $currentBillingMonth, $residentId, (int)($featuredVehicle['id'] ?? 0)]);
        $vehicleShowcaseCards = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        $vehicleShowcaseCards = [];
    }
}

foreach ($vehicleShowcaseCards as &$vehicleCard) {
    $vehicleCard['photo_url'] = !empty($vehicleCard['vehicle_photo']) ? rv_pay_public_file_url($vehicleCard['vehicle_photo']) : '';
    $vehicleCard['slot_text'] = !empty($vehicleCard['slot_no'])
        ? trim((($vehicleCard['block_name'] ?? '') ? ($vehicleCard['block_name'] . ' / ') : '') . $vehicleCard['slot_no'])
        : 'No slot assigned yet';
    $vehicleCard['slot_sub'] = !empty($vehicleCard['assignment_id']) ? '' : 'No slot assigned yet';
    $vehicleCard['expiry_text'] = !empty($vehicleCard['assignment_id'])
        ? rv_pay_month_expiry($vehicleCard['billing_month'] ?: $currentBillingMonth)
        : '-';
    $vehicleCard['billing_text'] = !empty($vehicleCard['assignment_id'])
        ? 'Billing month: ' . ($vehicleCard['billing_month'] ?: $currentBillingMonth)
        : 'No billing period yet';
    $vehicleCard['monthly_fee_display'] = !empty($vehicleCard['assignment_id'])
        ? rv_pay_money((float)($vehicleCard['payment_amount'] ?? $vehicleCard['monthly_fee'] ?? 80))
        : '-';

    $vehicleRequest = $latestRequestByVehicleId[(int)($vehicleCard['id'] ?? 0)] ?? null;
    if ($vehicleRequest) {
        $vehicleCard['request_title'] = 'Latest Request: ' . ($vehicleRequest['plate_no'] ?? $vehicleCard['plate_no']);
        $vehicleCard['request_sub'] = !empty($vehicleRequest['requested_at'])
            ? date('d M Y, g:i A', strtotime($vehicleRequest['requested_at']))
            : '';
        $vehicleCard['request_status_text'] = (string)($vehicleRequest['status'] ?? 'pending');
        $vehicleCard['request_status_class'] = rv_pay_status_class((string)($vehicleRequest['status'] ?? 'pending'));
    } else {
        $vehicleCard['request_title'] = '';
        $vehicleCard['request_sub'] = '';
        $vehicleCard['request_status_text'] = '';
        $vehicleCard['request_status_class'] = 'badge-gray';
    }
}
unset($vehicleCard);

if (empty($vehicleShowcaseCards) && !empty($featuredVehicle)) {
    $vehicleShowcaseCards[] = [
        'id' => $featuredVehicle['id'] ?? 0,
        'plate_no' => $featuredVehicle['plate_no'] ?? 'RES1234',
        'vehicle_model' => $featuredVehicle['vehicle_model'] ?? 'Resident Demo Car',
        'vehicle_color' => $featuredVehicle['vehicle_color'] ?? 'Black',
        'photo_url' => $featuredVehiclePhotoUrl,
        'slot_text' => $mainSlotText,
        'slot_sub' => empty($mainAssignment) ? 'No slot assigned yet' : '',
        'expiry_text' => !empty($mainAssignment) ? $mainExpiry : '-',
        'billing_text' => !empty($mainAssignment) ? ('Billing month: ' . ($mainAssignment['billing_month'] ?: $currentBillingMonth)) : 'No billing period yet',
        'monthly_fee_display' => !empty($mainAssignment) ? rv_pay_money((float)($mainAssignment['payment_amount'] ?? $mainAssignment['monthly_fee'] ?? 80)) : '-',
        'request_title' => !empty($requests[0]['plate_no']) ? ('Latest Request: ' . $requests[0]['plate_no']) : '',
        'request_sub' => !empty($requests[0]['requested_at']) ? date('d M Y, g:i A', strtotime($requests[0]['requested_at'])) : '',
        'request_status_text' => !empty($requests[0]['status']) ? (string)$requests[0]['status'] : '',
        'request_status_class' => !empty($requests[0]['status']) ? rv_pay_status_class((string)$requests[0]['status']) : 'badge-gray',
    ];
}

$initialShowcaseVehicle = $vehicleShowcaseCards[0] ?? [];
$initialShowcasePhotoUrl = $initialShowcaseVehicle['photo_url'] ?? $featuredVehiclePhotoUrl;
$vehicleShowcaseJson = json_encode($vehicleShowcaseCards, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$currentMonthDue = $parkingModuleReady ? rv_pay_sum($pdo, "
    SELECT COALESCE(SUM(amount),0)
    FROM parking_payments
    WHERE resident_id = ?
    AND billing_month = ?
    AND payment_status IN ('unpaid','overdue','rejected')
", [$residentId, $currentBillingMonth]) : 0.00;

$paidThisMonth = $parkingModuleReady ? rv_pay_count($pdo, "\n    SELECT COUNT(*)\n    FROM parking_payments\n    WHERE resident_id = ?\n    AND billing_month = ?\n    AND payment_status = 'paid'\n", [$residentId, $currentBillingMonth]) : 0;

$pendingPayment = $parkingModuleReady ? rv_pay_count($pdo, "\n    SELECT COUNT(*)\n    FROM parking_payments\n    WHERE resident_id = ?\n    AND billing_month = ?\n    AND payment_status = 'pending_verification'\n", [$residentId, $currentBillingMonth]) : 0;

$notificationCount = rv_pay_table_exists($pdo, 'notifications')
    ? rv_pay_count($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0", [$residentId])
    : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Parking - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        :root {
            --bg: #f5f8fc;
            --card: rgba(255,255,255,.88);
            --card-solid: #ffffff;
            --ink: #07111f;
            --text: #334155;
            --muted: #64748b;
            --line: #dbe5f0;
            --line2: #e6edf5;
            --blue: #2563eb;
            --blue2: #38bdf8;
            --blue-soft: #eff6ff;
            --green: #16a34a;
            --green-soft: #ecfdf3;
            --red: #ef4444;
            --red-soft: #fff1f2;
            --yellow: #f59e0b;
            --yellow-soft: #fffbeb;
            --purple: #7c3aed;
            --shadow: 0 22px 58px rgba(15, 23, 42, .08);
            --shadow-soft: 0 12px 32px rgba(15, 23, 42, .055);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', sans-serif; }
        html { scroll-behavior: smooth; }
        body {
            min-height: 100vh;
            color: var(--ink);
            background:
                radial-gradient(circle at 8% 26%, rgba(37,99,235,.06), transparent 23%),
                radial-gradient(circle at 88% 18%, rgba(56,189,248,.10), transparent 22%),
                radial-gradient(circle at 94% 78%, rgba(37,99,235,.045), transparent 24%),
                linear-gradient(180deg, #ffffff 0%, var(--bg) 100%);
            overflow-x: hidden;
        }

        a { color: inherit; text-decoration: none; }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(255,255,255,.92);
            backdrop-filter: blur(18px);
            border-bottom: 1px solid var(--line2);
            min-height: 72px;
            padding: 0 5%;
            display: flex;
            align-items: center;
        }

        .topbar-inner {
            width: min(1240px, 100%);
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 22px;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 0;
            font-size: 1.55rem;
            line-height: 1;
            font-weight: 900;
            letter-spacing: -.06em;
            color: #0f172a;
        }
        .brand span { color: var(--blue); }

        .nav-links { display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
        .nav-links a {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 38px;
            padding: 0 13px;
            border-radius: 999px;
            border: 1px solid transparent;
            color: #334155;
            font-size: .82rem;
            font-weight: 850;
            transition: .2s ease;
        }
        .nav-links a:hover { color: var(--blue); background: var(--blue-soft); }
        .nav-links a.active { color: var(--blue); background: var(--blue-soft); border-color: #bfdbfe; }
        .nav-links a.logout { color: var(--red); background: #fff7f7; }
        .nav-links a.logout:hover { background: var(--red-soft); }
        .nav-count { min-width: 20px; height: 20px; padding: 0 6px; margin-left: -3px; border-radius: 999px; background: var(--red); color: #fff; display: inline-flex; align-items:center; justify-content:center; font-size:.68rem; font-weight:900; }

        .container { width: min(1120px, calc(100% - 42px)); margin: 0 auto; padding: 30px 0 64px; }

        .hero {
            position: relative;
            overflow: hidden;
            min-height: 220px;
            margin-bottom: 22px;
            border-radius: 30px;
            border: 1px solid var(--line);
            background:
                linear-gradient(110deg, rgba(255,255,255,.96) 0%, rgba(255,255,255,.86) 56%, rgba(239,246,255,.78) 100%);
            box-shadow: var(--shadow-soft);
            display: grid;
            grid-template-columns: 1fr 360px;
            align-items: center;
            gap: 24px;
            padding: 34px 38px;
        }

        .hero::before {
            content: "";
            position: absolute;
            right: -64px;
            top: -86px;
            width: 240px;
            height: 240px;
            border-radius: 50%;
            background: rgba(219,234,254,.75);
        }

        .hero-left { position: relative; z-index: 2; }
        .kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 30px;
            padding: 0 12px;
            margin-bottom: 14px;
            border-radius: 999px;
            border: 1px solid #bfdbfe;
            background: #eff6ff;
            color: var(--blue);
            font-size: .74rem;
            font-weight: 900;
        }
        h1 { font-size: clamp(2.4rem, 4.5vw, 3.35rem); letter-spacing: -.07em; line-height: .98; font-weight: 900; margin-bottom: 10px; }
        .hero-sub { color: #516179; font-size: 1rem; font-weight: 690; line-height: 1.58; max-width: 560px; }
        .hero-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 24px; max-width: 680px; }
        .hero-stat {
            min-height: 74px;
            border: 1px solid var(--line);
            background: rgba(255,255,255,.86);
            border-radius: 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 15px;
        }
        .hero-stat .sicon { width: 40px; height: 40px; border-radius: 14px; display:grid; place-items:center; background:#eff6ff; color:var(--blue); flex-shrink:0; }
        .hero-stat .slabel { color: var(--muted); font-size: .72rem; font-weight: 850; margin-bottom: 3px; }
        .hero-stat .svalue { color: var(--ink); font-size: .94rem; font-weight: 900; }

        .car-art { position: relative; z-index: 2; min-height: 150px; display:flex; align-items:center; justify-content:center; }
        .car-blob { position:absolute; right:0; bottom:0; width: 330px; height: 118px; border-radius: 999px 999px 30px 30px; background: linear-gradient(90deg, rgba(239,246,255,.18), rgba(219,234,254,.92)); }
        .city-line { position:absolute; right: 18px; top: 14px; width: 270px; height: 78px; opacity:.45; background:
            linear-gradient(to top, #bfdbfe 0 36px, transparent 36px) 0 42px/24px 36px no-repeat,
            linear-gradient(to top, #bfdbfe 0 58px, transparent 58px) 32px 20px/26px 60px no-repeat,
            linear-gradient(to top, #bfdbfe 0 44px, transparent 44px) 70px 34px/22px 46px no-repeat,
            linear-gradient(to top, #bfdbfe 0 68px, transparent 68px) 108px 10px/30px 70px no-repeat,
            linear-gradient(to top, #bfdbfe 0 48px, transparent 48px) 156px 30px/26px 50px no-repeat,
            linear-gradient(to top, #bfdbfe 0 62px, transparent 62px) 198px 18px/30px 64px no-repeat;
        }
        .car-svg { position: relative; z-index: 3; width: 290px; filter: drop-shadow(0 22px 28px rgba(37,99,235,.16)); }
        .parking-sign { position:absolute; right: 18px; top: 18px; z-index: 4; width:48px; height:48px; border-radius:14px; display:grid; place-items:center; background: linear-gradient(135deg, #60a5fa, #2563eb); color:#fff; font-weight:900; box-shadow:0 14px 28px rgba(37,99,235,.20); }

        .alert { padding: 14px 17px; border-radius: 18px; margin: 0 0 18px; font-weight: 830; line-height: 1.5; }
        .alert.success { color:#166534; background:#dcfce7; border:1px solid #86efac; }
        .alert.error { color:#991b1b; background:#fee2e2; border:1px solid #fecaca; }

        .grid-main { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; align-items: start; margin-bottom: 22px; }
        .panel {
            position: relative;
            overflow: hidden;
            border: 1px solid var(--line);
            background: var(--card);
            border-radius: 24px;
            box-shadow: var(--shadow-soft);
            backdrop-filter: blur(18px);
        }
        .panel-head {
            min-height: 64px;
            padding: 0 22px;
            border-bottom: 1px solid var(--line2);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            background: rgba(255,255,255,.72);
        }
        .panel-title { display:inline-flex; align-items:center; gap:10px; font-size: 1.05rem; font-weight: 900; color: var(--ink); }
        .panel-title i { color: var(--blue); }
        .panel-body { padding: 22px; }

        .badge { display:inline-flex; align-items:center; gap:6px; padding: 7px 10px; border-radius:999px; font-size:.68rem; font-weight:900; text-transform:uppercase; letter-spacing:.045em; white-space: nowrap; }
        .badge-green { background:#dcfce7; color:#166534; border:1px solid #86efac; }
        .badge-yellow { background:#fef3c7; color:#92400e; border:1px solid #fcd34d; }
        .badge-red { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
        .badge-gray { background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; }

        .vehicle-layout { display:grid; grid-template-columns: 250px 1fr; gap: 20px; align-items: stretch; }
        .vehicle-photo-card { border: 1px solid var(--line); background: #f8fbff; border-radius: 22px; padding: 18px; display:flex; flex-direction:column; align-items:center; text-align:center; justify-content: space-between; min-height: 330px; }
        .vehicle-photo-wrap { width:100%; min-height: 170px; display:flex; align-items:center; justify-content:center; }
        .vehicle-photo-wrap img { width:100%; height: 170px; object-fit: cover; border-radius: 18px; border: 1px solid var(--line); }
        .car-placeholder { width: 180px; height: 112px; border-radius: 999px; background: linear-gradient(180deg,#eaf3ff,#f8fbff); display:flex; flex-direction:column; align-items:center; justify-content:center; color:#8da0ba; }
        .car-placeholder i { font-size: 54px; margin-bottom: 10px; }
        .plate-tag { display:inline-flex; align-items:center; margin-top: 12px; border:1px solid var(--line); border-radius: 12px; overflow:hidden; background: #fff; box-shadow:0 8px 18px rgba(15,23,42,.06); }
        .plate-tag .country { background: linear-gradient(135deg, #3b82f6,#1d4ed8); color:#fff; padding: 12px 11px; font-size:.68rem; font-weight:900; }
        .plate-tag .plate-text { padding: 10px 15px; font-size: 1.2rem; font-weight: 900; letter-spacing: .04em; color: var(--ink); }
        .photo-note { margin-top: 12px; border:1px dashed #bfdbfe; background:#fff; border-radius: 16px; padding: 14px; width:100%; color:#64748b; font-size:.82rem; font-weight:700; line-height:1.45; }
        .photo-note i { display:block; color:var(--blue); font-size:1.2rem; margin-bottom:6px; }

        .vehicle-info-list { display:grid; gap: 14px; }
        .vehicle-row { display:grid; grid-template-columns: 40px 1fr; gap: 12px; align-items:start; padding-bottom: 14px; border-bottom: 1px solid var(--line2); }
        .vehicle-row:last-child { border-bottom:none; padding-bottom:0; }
        .row-icon { width: 40px; height: 40px; border-radius: 14px; background: var(--blue-soft); color: var(--blue); display:grid; place-items:center; }
        .row-label { color:#64748b; font-size:.74rem; font-weight:850; margin-bottom:4px; }
        .row-value { color:var(--ink); font-size:.94rem; font-weight:900; line-height:1.35; }
        .row-sub { color:#64748b; font-size:.78rem; font-weight:650; margin-top:3px; }

        .btn { border:none; cursor:pointer; border-radius: 15px; min-height: 44px; padding: 0 16px; font-weight:900; display:inline-flex; align-items:center; justify-content:center; gap:9px; font-size:.84rem; transition:.2s ease; }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { background: linear-gradient(135deg, #38bdf8, var(--blue) 62%, #1d4ed8); color:#fff; box-shadow:0 16px 32px rgba(37,99,235,.22); }
        .btn-light { background:#eff6ff; color:var(--blue); border:1px solid #bfdbfe; }
        .btn-green { background:#dcfce7; color:#166534; border:1px solid #86efac; }

        .pay-status-card { display:grid; grid-template-columns: 1fr auto; gap: 16px; align-items:center; border:1px solid var(--line); background:#f8fbff; border-radius: 20px; padding: 18px; margin-bottom: 14px; }
        .pay-small { color:#64748b; font-size:.78rem; font-weight:800; margin-bottom:6px; }
        .pay-title { font-size:1.08rem; font-weight:900; color:var(--ink); margin-bottom:6px; }
        .pay-desc { color:#64748b; font-size:.82rem; line-height:1.5; font-weight:690; }
        .info-note { display:flex; align-items:flex-start; gap:12px; background: var(--blue-soft); color:#1e3a8a; border:1px solid #bfdbfe; border-radius:18px; padding:14px 15px; font-size:.82rem; font-weight:800; line-height:1.5; margin-bottom: 14px; }
        .info-note i { margin-top: 2px; }
        .empty-box { border:1px dashed #cbd5e1; border-radius:20px; min-height:150px; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; color:#64748b; padding: 22px; }
        .empty-box i { font-size:2.2rem; color:#94a3b8; margin-bottom: 12px; }
        .empty-box strong { color: var(--ink); font-size:1rem; margin-bottom:6px; }

        .vehicle-showcase-shell {
            position: relative;
        }
        .vehicle-showcase-shell.has-multiple {
            padding-right: 26px;
        }
        .vehicle-showcase-shell.has-multiple::before,
        .vehicle-showcase-shell.has-multiple::after {
            content: "";
            position: absolute;
            border-radius: 28px;
            background: rgba(255,255,255,.9);
            border: 1px solid #dbeafe;
            box-shadow: 0 18px 38px rgba(37,99,235,.08);
            pointer-events: none;
        }
        .vehicle-showcase-shell.has-multiple::before {
            top: 20px;
            right: 16px;
            bottom: 22px;
            width: 100%;
            transform: translateX(12px);
            z-index: 0;
        }
        .vehicle-showcase-shell.has-multiple::after {
            top: 38px;
            right: 4px;
            bottom: 40px;
            width: 100%;
            transform: translateX(24px);
            opacity: .72;
            z-index: 0;
        }
        .vehicle-showcase-shell .vehicle-layout,
        .vehicle-showcase-shell .showcase-page-btn {
            position: relative;
            z-index: 1;
        }
        .vehicle-photo-card-flip {
            position: relative;
            overflow: visible;
        }
        .showcase-page-indicator {
            position: absolute;
            top: -8px;
            right: -8px;
            border-radius: 999px;
            background: #eff6ff;
            color: var(--blue);
            border: 1px solid #bfdbfe;
            padding: 7px 12px;
            font-size: .74rem;
            font-weight: 900;
            box-shadow: 0 10px 22px rgba(37,99,235,.12);
        }
        .showcase-page-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            right: -12px;
            width: 52px;
            height: 52px;
            border: none;
            border-radius: 999px;
            background: linear-gradient(135deg, #38bdf8, var(--blue) 70%);
            color: #fff;
            font-size: 1rem;
            cursor: pointer;
            box-shadow: 0 18px 34px rgba(37,99,235,.22);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .showcase-page-btn:hover {
            transform: translateY(-50%) translateX(2px);
            box-shadow: 0 22px 40px rgba(37,99,235,.28);
        }
        .showcase-next-copy {
            margin-top: 12px;
            font-size: .78rem;
            font-weight: 800;
            color: #64748b;
        }
        .vehicle-showcase-shell.flip-animate .vehicle-photo-card,
        .vehicle-showcase-shell.flip-animate .vehicle-info-list {
            animation: vehiclePageFlip .32s ease;
        }
        @keyframes vehiclePageFlip {
            0% { opacity: .55; transform: translateX(22px) scale(.985); }
            100% { opacity: 1; transform: translateX(0) scale(1); }
        }
        .assignment-card { border:1px solid var(--line); background:#fff; border-radius: 20px; padding: 17px; margin-top: 16px; }
        .assignment-top { display:flex; align-items:flex-start; justify-content:space-between; gap: 12px; margin-bottom: 14px; }
        .slot-title { font-size:1rem; font-weight:900; display:flex; align-items:center; gap:9px; }
        .slot-title i { color: var(--blue); }
        .assignment-grid { display:grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 14px; }
        .mini-info { background:#f8fafc; border:1px solid var(--line2); border-radius:16px; padding:12px; }
        .mini-info .label { color:#64748b; font-size:.68rem; font-weight:900; text-transform:uppercase; letter-spacing:.05em; margin-bottom:5px; }
        .mini-info .value { font-weight:900; color:var(--ink); font-size:.92rem; }
        .pay-form { border-top:1px solid var(--line2); padding-top: 14px; margin-top: 14px; }

        .wide-panel { margin-top: 0; }
        .form-intro { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom: 18px; }
        .form-intro-text { color:#64748b; font-size:.86rem; font-weight:750; line-height:1.55; }
        .vehicle-form { display:grid; grid-template-columns: 250px 1fr; gap: 22px; }
        .upload-box { border:1px dashed #b7c9dd; border-radius: 20px; min-height: 220px; background:#f8fbff; display:flex; flex-direction:column; justify-content:center; align-items:center; text-align:center; gap: 12px; padding: 18px; }
        .upload-box .upload-icon { width:62px; height:62px; border-radius:50%; background:#eff6ff; color:#8da0ba; display:grid; place-items:center; font-size:1.8rem; }
        .upload-box strong { color:var(--text); font-size:.95rem; }
        .upload-box small { color:#64748b; font-weight:700; }
        .photo-preview { width:100%; height: 142px; object-fit:cover; border-radius:16px; border:1px solid var(--line); display:none; }
        input[type="file"] { font-size:.8rem; color:#64748b; }

        .fields-grid { display:grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .field { margin-bottom: 14px; }
        .field.full { grid-column: 1 / -1; }
        label { display:block; font-size:.72rem; font-weight:900; color:#64748b; letter-spacing:.06em; text-transform:uppercase; margin-bottom: 7px; }
        input, select, textarea { width:100%; border:1px solid var(--line); background:#fff; color:var(--ink); border-radius: 14px; padding: 13px 14px; outline:none; font-weight:760; font-size:.9rem; transition:.2s ease; }
        textarea { min-height: 92px; resize: vertical; line-height:1.5; }
        input::placeholder, textarea::placeholder { color:#94a3b8; }
        input:focus, select:focus, textarea:focus { border-color:#93c5fd; box-shadow:0 0 0 5px rgba(37,99,235,.09); }
        .plate-input { text-transform:uppercase; letter-spacing:.05em; }
        .submit-wide { width:100%; margin-top: 2px; }

        .request-list { display:grid; gap: 12px; margin-top: 16px; }
        .request-item { display:flex; align-items:center; justify-content:space-between; gap:14px; padding:13px 14px; border-radius:16px; background:#fff; border:1px solid var(--line2); }
        .request-main { display:flex; align-items:center; gap:12px; }
        .request-icon { width:38px; height:38px; border-radius:14px; background:#eff6ff; color:var(--blue); display:grid; place-items:center; }
        .request-title { font-weight:900; font-size:.9rem; margin-bottom:3px; }
        .request-sub { color:#64748b; font-size:.76rem; font-weight:680; }

        @media (max-width: 1000px) {
            .hero, .grid-main { grid-template-columns: 1fr; }
            .car-art { display: none; }
            .vehicle-layout, .vehicle-form { grid-template-columns: 1fr; }
            .hero-stats, .assignment-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 760px) {
            .topbar { padding: 12px 18px; }
            .topbar-inner { flex-direction: column; align-items: flex-start; }
            .nav-links { width: 100%; display: grid; grid-template-columns: 1fr 1fr; }
            .nav-links a { justify-content: center; }
            .container { width: min(100% - 26px, 1120px); padding-top: 20px; }
            .hero { padding: 26px 22px; border-radius: 24px; }
            h1 { font-size: 2.35rem; }
            .fields-grid { grid-template-columns: 1fr; }
            .pay-status-card { grid-template-columns: 1fr; }
        }
    </style>

<style id="resident-vehicles-dashboard-nav-lou-final">
    html,
    body {
        min-height: 100% !important;
    }

    body {
        background: #eef6ff !important;
        color: #0f172a !important;
        overflow-x: hidden !important;
    }

    body::before {
        content: "" !important;
        position: fixed !important;
        inset: 76px 0 0 0 !important;
        z-index: -5 !important;
        pointer-events: none !important;
        background:
            linear-gradient(105deg,
                rgba(255,255,255,.84) 0%,
                rgba(248,252,255,.68) 43%,
                rgba(218,238,255,.54) 100%
            ),
            url("lou.jpg") center/cover no-repeat !important;
    }

    body::after {
        content: "" !important;
        position: fixed !important;
        inset: 76px 0 0 0 !important;
        z-index: -4 !important;
        pointer-events: none !important;
        backdrop-filter: blur(1.4px) !important;
        background:
            radial-gradient(circle at 12% 18%, rgba(37,99,235,.07), transparent 24%),
            radial-gradient(circle at 88% 22%, rgba(56,189,248,.14), transparent 25%),
            radial-gradient(circle at 82% 86%, rgba(37,99,235,.06), transparent 24%),
            linear-gradient(180deg, rgba(255,255,255,.02), rgba(255,255,255,.14)) !important;
    }

    .navbar {
        height: 76px !important;
        min-height: 76px !important;
        padding: 0 5.5% !important;
        background: rgba(255,255,255,.94) !important;
        border-bottom: 1px solid #dbe5f0 !important;
        box-shadow: none !important;
        position: sticky !important;
        top: 0 !important;
        z-index: 1000 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
    }

    .navbar .brand {
        font-size: 1.55rem !important;
        line-height: 1 !important;
        font-weight: 900 !important;
        letter-spacing: -.045em !important;
        color: #0f172a !important;
        text-decoration: none !important;
    }

    .navbar .brand span {
        color: #2563eb !important;
    }

    .navbar .nav-links {
        display: flex !important;
        align-items: center !important;
        justify-content: flex-end !important;
        gap: 12px !important;
        flex-wrap: nowrap !important;
    }

    .navbar .nav-btn {
        height: 44px !important;
        min-height: 44px !important;
        padding: 0 16px !important;
        border-radius: 999px !important;
        color: #334155 !important;
        background: transparent !important;
        border: 0 !important;
        box-shadow: none !important;
        font-size: .84rem !important;
        font-weight: 900 !important;
        line-height: 1 !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 8px !important;
        white-space: nowrap !important;
    }

    .navbar .nav-btn:hover {
        color: #2563eb !important;
        background: #eff6ff !important;
    }

    .nav-notification-badge {
        min-width: 18px !important;
        height: 18px !important;
        padding: 0 5px !important;
        border-radius: 999px !important;
        background: #2563eb !important;
        color: #fff !important;
        font-size: .66rem !important;
        font-weight: 900 !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
    }

    .profile-menu {
        position: relative !important;
    }

    .profile-trigger {
        height: 44px !important;
        min-height: 44px !important;
        padding: 5px 13px 5px 7px !important;
        border-radius: 999px !important;
        border: 1px solid #dbe5f0 !important;
        background: rgba(255, 255, 255, .78) !important;
        color: #0f172a !important;
        box-shadow: 0 8px 22px rgba(15, 23, 42, .04) !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 9px !important;
        cursor: pointer !important;
        font-size: .84rem !important;
        font-weight: 900 !important;
        max-width: 230px !important;
    }

    .profile-trigger span:nth-child(2) {
        max-width: 130px !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
    }

    .profile-trigger:hover,
    .profile-menu:focus-within .profile-trigger {
        background: #fff !important;
        color: #2563eb !important;
        border-color: #bfdbfe !important;
    }

    .avatar-sm,
    .avatar-md {
        border-radius: 50% !important;
        overflow: hidden !important;
        background: linear-gradient(135deg, #dbeafe, #bfdbfe) !important;
        color: #2563eb !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        font-weight: 900 !important;
        flex-shrink: 0 !important;
    }

    .avatar-sm {
        width: 34px !important;
        height: 34px !important;
        font-size: .9rem !important;
        border: 2px solid #e5edf7 !important;
    }

    .avatar-md {
        width: 56px !important;
        height: 56px !important;
        font-size: 1.1rem !important;
    }

    .avatar-sm img,
    .avatar-md img {
        width: 100% !important;
        height: 100% !important;
        object-fit: cover !important;
    }

    .profile-dropdown {
        position: absolute !important;
        top: calc(100% + 12px) !important;
        right: 0 !important;
        width: 300px !important;
        padding: 12px !important;
        border-radius: 24px !important;
        background: rgba(255, 255, 255, .96) !important;
        border: 1px solid #dbe5f0 !important;
        box-shadow: 0 26px 70px rgba(15, 23, 42, .16) !important;
        opacity: 0 !important;
        transform: translateY(8px) scale(.98) !important;
        visibility: hidden !important;
        pointer-events: none !important;
        transition: .18s ease !important;
        z-index: 2000 !important;
    }

    .profile-dropdown::before {
        content: "" !important;
        position: absolute !important;
        top: -8px !important;
        right: 34px !important;
        width: 18px !important;
        height: 18px !important;
        background: rgba(255, 255, 255, .96) !important;
        border-left: 1px solid #dbe5f0 !important;
        border-top: 1px solid #dbe5f0 !important;
        transform: rotate(45deg) !important;
    }

    .profile-menu:hover .profile-dropdown,
    .profile-menu:focus-within .profile-dropdown {
        opacity: 1 !important;
        transform: translateY(0) scale(1) !important;
        visibility: visible !important;
        pointer-events: auto !important;
    }

    .dropdown-head {
        padding: 14px !important;
        border-radius: 18px !important;
        background: #eff6ff !important;
        border: 1px solid #bfdbfe !important;
        display: flex !important;
        align-items: center !important;
        gap: 12px !important;
        margin-bottom: 10px !important;
    }

    .dropdown-name {
        color: #0f172a !important;
        font-size: .95rem !important;
        font-weight: 900 !important;
        line-height: 1.2 !important;
    }

    .dropdown-unit {
        margin-top: 4px !important;
        color: #64748b !important;
        font-size: .78rem !important;
        font-weight: 800 !important;
    }

    .dropdown-link {
        min-height: 48px !important;
        padding: 0 13px !important;
        border-radius: 16px !important;
        color: #0f172a !important;
        font-size: .9rem !important;
        font-weight: 900 !important;
        display: flex !important;
        align-items: center !important;
        gap: 12px !important;
        text-decoration: none !important;
    }

    .dropdown-link:hover {
        background: #f8fafc !important;
        color: #2563eb !important;
    }

    .dropdown-link i {
        width: 34px !important;
        height: 34px !important;
        border-radius: 12px !important;
        background: #eff6ff !important;
        color: #2563eb !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
    }

    .dropdown-footer {
        border-top: 1px solid #e5edf7 !important;
        margin-top: 10px !important;
        padding-top: 10px !important;
    }

    .dropdown-logout {
        color: #ef4444 !important;
    }

    .dropdown-logout i {
        background: #fff1f2 !important;
        color: #ef4444 !important;
    }

    .topbar,
    .topbar-inner {
        display: contents !important;
    }

    .container {
        width: min(1120px, calc(100% - 56px)) !important;
        padding: 34px 0 72px !important;
        position: relative !important;
        z-index: 1 !important;
    }

    .hero,
    .panel,
    .showcase-left,
    .payment-box,
    .wide-panel {
        background: rgba(255, 255, 255, .90) !important;
        border: 1px solid rgba(219, 229, 240, .95) !important;
        box-shadow: 0 24px 70px rgba(15, 23, 42, .10) !important;
        backdrop-filter: blur(18px) !important;
        -webkit-backdrop-filter: blur(18px) !important;
    }

    .hero {
        min-height: 205px !important;
        border-radius: 34px !important;
        margin-bottom: 24px !important;
    }

    .panel,
    .wide-panel {
        border-radius: 30px !important;
    }

    .dashboard-grid {
        gap: 24px !important;
    }

    .wide-panel {
        margin-top: 26px !important;
    }

    .btn-primary {
        background: linear-gradient(135deg, #38bdf8, #2563eb) !important;
        box-shadow: 0 16px 30px rgba(37, 99, 235, .22) !important;
    }

    @media (max-width: 980px) {
        .container {
            width: min(100% - 30px, 1120px) !important;
            padding-top: 24px !important;
        }

        .navbar {
            height: auto !important;
            padding: 16px 20px !important;
            flex-direction: column !important;
            align-items: flex-start !important;
            gap: 12px !important;
        }

        body::before,
        body::after {
            inset: 0 !important;
        }

        .hero {
            grid-template-columns: 1fr !important;
        }

        .profile-dropdown {
            right: auto !important;
            left: 0 !important;
        }
    }
</style>


<style id="resident-vehicles-modal-polish-final">
    body.modal-open {
        overflow: hidden !important;
    }

    .panel-actions {
        display: inline-flex !important;
        align-items: center !important;
        gap: 10px !important;
    }

    .mini-add-btn {
        height: 38px !important;
        padding: 0 14px !important;
        border-radius: 999px !important;
        border: 1px solid #bfdbfe !important;
        background: #eff6ff !important;
        color: #2563eb !important;
        font-size: .78rem !important;
        font-weight: 900 !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 8px !important;
        cursor: pointer !important;
        transition: .18s ease !important;
    }

    .mini-add-btn:hover {
        transform: translateY(-1px) !important;
        background: #dbeafe !important;
        border-color: #93c5fd !important;
    }

    .vehicle-modal-backdrop {
        position: fixed !important;
        inset: 0 !important;
        z-index: 3000 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        padding: 28px !important;
        background: rgba(15, 23, 42, .38) !important;
        backdrop-filter: blur(8px) !important;
        -webkit-backdrop-filter: blur(8px) !important;
        opacity: 0 !important;
        visibility: hidden !important;
        pointer-events: none !important;
        transition: opacity .2s ease, visibility .2s ease !important;
    }

    .vehicle-modal-backdrop.show {
        opacity: 1 !important;
        visibility: visible !important;
        pointer-events: auto !important;
    }

    .vehicle-modal-card {
        width: min(980px, calc(100vw - 44px)) !important;
        max-height: min(88vh, 760px) !important;
        position: relative !important;
        transform: translateY(18px) scale(.97) !important;
        transition: transform .22s ease !important;
    }

    .vehicle-modal-backdrop.show .vehicle-modal-card {
        transform: translateY(0) scale(1) !important;
    }

    .vehicle-modal-panel {
        margin: 0 !important;
        max-height: min(88vh, 760px) !important;
        overflow-y: auto !important;
        border-radius: 32px !important;
        background: rgba(255, 255, 255, .96) !important;
        box-shadow: 0 34px 90px rgba(15, 23, 42, .25) !important;
    }

    .vehicle-modal-panel .panel-head {
        padding-right: 72px !important;
        position: sticky !important;
        top: 0 !important;
        background: rgba(255, 255, 255, .97) !important;
        z-index: 2 !important;
    }

    .vehicle-modal-close {
        position: absolute !important;
        top: 18px !important;
        right: 18px !important;
        width: 42px !important;
        height: 42px !important;
        border: 1px solid #dbe5f0 !important;
        border-radius: 16px !important;
        background: #ffffff !important;
        color: #64748b !important;
        display: grid !important;
        place-items: center !important;
        cursor: pointer !important;
        z-index: 5 !important;
        box-shadow: 0 14px 30px rgba(15, 23, 42, .10) !important;
        transition: .18s ease !important;
    }

    .vehicle-modal-close:hover {
        background: #fff1f2 !important;
        color: #ef4444 !important;
        border-color: #fecaca !important;
        transform: translateY(-1px) !important;
    }

    .open-vehicle-modal {
        cursor: pointer !important;
        border: 0 !important;
    }

    .avatar-sm img,
    .avatar-md img {
        display: block !important;
    }

    @media (max-width: 760px) {
        .vehicle-modal-backdrop {
            padding: 14px !important;
            align-items: flex-start !important;
        }

        .vehicle-modal-card,
        .vehicle-modal-panel {
            width: 100% !important;
            max-height: calc(100vh - 28px) !important;
        }

        .vehicle-form {
            grid-template-columns: 1fr !important;
        }

        .panel-actions {
            flex-wrap: wrap !important;
            justify-content: flex-end !important;
        }
    }
</style>




<style id="resident-vehicles-gateway-payment-modal-final">
    .gateway-payment-card {
        width: min(1040px, calc(100% - 36px)) !important;
    }

    .gateway-payment-panel {
        border-radius: 34px !important;
        overflow: hidden !important;
        background: rgba(255,255,255,.97) !important;
    }

    .payment-close {
        top: 18px !important;
        right: 18px !important;
    }

    .gateway-topbar {
        padding: 26px 32px 22px !important;
        min-height: 148px !important;
        border-bottom: 1px solid #e5edf7 !important;
        background:
            radial-gradient(circle at 94% -6%, rgba(37,99,235,.14), transparent 27%),
            linear-gradient(135deg, #f8fbff, #eef6ff) !important;
        display: flex !important;
        align-items: flex-start !important;
        justify-content: space-between !important;
        gap: 20px !important;
    }

    .payment-kicker {
        width: fit-content !important;
        min-height: 30px !important;
        padding: 0 13px !important;
        margin-bottom: 11px !important;
        border-radius: 999px !important;
        background: #eff6ff !important;
        color: #2563eb !important;
        border: 1px solid #bfdbfe !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 8px !important;
        font-size: .78rem !important;
        font-weight: 900 !important;
    }

    .gateway-topbar h2 {
        margin: 0 0 8px !important;
        color: #0f172a !important;
        font-size: 2rem !important;
        line-height: 1 !important;
        letter-spacing: -.052em !important;
        font-weight: 950 !important;
    }

    .gateway-topbar p {
        margin: 0 !important;
        color: #64748b !important;
        font-size: .95rem !important;
        line-height: 1.38 !important;
        font-weight: 760 !important;
        max-width: 600px !important;
    }

    .payment-month-pill {
        margin-right: 50px !important;
        min-height: 40px !important;
        padding: 0 16px !important;
        border-radius: 999px !important;
        background: #fff7ed !important;
        border: 1px solid #f59e0b !important;
        color: #b45309 !important;
        font-size: .82rem !important;
        font-weight: 900 !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        white-space: nowrap !important;
    }

    .gateway-body {
        padding: 24px 32px 32px !important;
        display: grid !important;
        grid-template-columns: 350px minmax(0, 1fr) !important;
        gap: 24px !important;
    }

    .payment-summary-column,
    .payment-action-column {
        min-width: 0 !important;
    }

    .receipt-preview-card {
        border-radius: 28px !important;
        padding: 22px !important;
        background:
            radial-gradient(circle at 88% 0%, rgba(59,130,246,.12), transparent 28%),
            linear-gradient(135deg, #eff6ff, rgba(255,255,255,.90)) !important;
        border: 1px solid #bfdbfe !important;
        box-shadow: 0 20px 48px rgba(37,99,235,.10) !important;
    }

    .receipt-logo {
        width: 58px !important;
        height: 58px !important;
        border-radius: 20px !important;
        background: linear-gradient(135deg, #38bdf8, #2563eb) !important;
        color: #fff !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        font-size: 1.28rem !important;
        box-shadow: 0 15px 34px rgba(37,99,235,.22) !important;
        margin-bottom: 18px !important;
    }

    .receipt-label {
        color: #64748b !important;
        font-size: .72rem !important;
        font-weight: 900 !important;
        text-transform: uppercase !important;
        letter-spacing: .06em !important;
    }

    .receipt-amount {
        margin-top: 4px !important;
        color: #0f172a !important;
        font-size: 2.55rem !important;
        line-height: 1 !important;
        letter-spacing: -.058em !important;
        font-weight: 950 !important;
    }

    .receipt-subtitle {
        margin-top: 8px !important;
        color: #64748b !important;
        font-size: .86rem !important;
        font-weight: 760 !important;
    }

    .receipt-lines {
        margin-top: 20px !important;
        display: grid !important;
        gap: 9px !important;
    }

    .receipt-lines > div,
    .bank-row {
        min-height: 44px !important;
        padding: 9px 12px !important;
        border-radius: 16px !important;
        background: rgba(255,255,255,.84) !important;
        border: 1px solid rgba(191,219,254,.90) !important;
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 10px !important;
    }

    .receipt-lines span,
    .bank-row span {
        color: #64748b !important;
        font-size: .69rem !important;
        font-weight: 900 !important;
        text-transform: uppercase !important;
        letter-spacing: .055em !important;
    }

    .receipt-lines strong,
    .bank-row strong {
        color: #0f172a !important;
        font-size: .84rem !important;
        font-weight: 900 !important;
        text-align: right !important;
    }

    .gateway-instruction-card,
    .tng-qr-card {
        margin-top: 14px !important;
        border-radius: 24px !important;
        padding: 16px !important;
        background: rgba(255,255,255,.86) !important;
        border: 1px solid #dbe5f0 !important;
    }

    .tng-qr-card {
        display: none !important;
    }

    .bank-title {
        margin-bottom: 12px !important;
        color: #0f172a !important;
        font-size: .92rem !important;
        font-weight: 900 !important;
        display: flex !important;
        align-items: center !important;
        gap: 9px !important;
    }

    .bank-title i {
        color: #2563eb !important;
    }

    .bank-row {
        margin-top: 7px !important;
    }

    .tng-qr-frame {
        width: 100% !important;
        aspect-ratio: 1 / 1 !important;
        max-height: 260px !important;
        border-radius: 24px !important;
        background: #f8fafc !important;
        border: 1px dashed #93c5fd !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        overflow: hidden !important;
    }

    .tng-qr-frame img {
        width: 100% !important;
        height: 100% !important;
        object-fit: contain !important;
        padding: 12px !important;
        display: block;
    }

    .qr-placeholder {
        display: none;
        width: 100% !important;
        height: 100% !important;
        align-items: center !important;
        justify-content: center !important;
        flex-direction: column !important;
        gap: 8px !important;
        color: #64748b !important;
        text-align: center !important;
        padding: 24px !important;
    }

    .qr-placeholder i {
        color: #2563eb !important;
        font-size: 2.2rem !important;
    }

    .qr-placeholder span {
        font-size: .8rem !important;
        font-weight: 800 !important;
    }

    .qr-placeholder strong {
        color: #0f172a !important;
        font-size: .9rem !important;
        font-weight: 900 !important;
    }

    .tng-qr-card p {
        margin: 10px 0 0 !important;
        color: #64748b !important;
        font-size: .8rem !important;
        font-weight: 760 !important;
        line-height: 1.35 !important;
    }

    .payment-process-strip {
        min-height: 64px !important;
        padding: 12px 14px !important;
        border-radius: 22px !important;
        background: #eff6ff !important;
        border: 1px solid #bfdbfe !important;
        display: flex !important;
        align-items: center !important;
        gap: 12px !important;
        margin-bottom: 18px !important;
    }

    .process-step {
        display: flex !important;
        align-items: center !important;
        gap: 8px !important;
        color: #64748b !important;
        font-size: .78rem !important;
        font-weight: 900 !important;
        white-space: nowrap !important;
    }

    .process-step span {
        width: 28px !important;
        height: 28px !important;
        border-radius: 50% !important;
        background: #dbeafe !important;
        color: #2563eb !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        font-size: .76rem !important;
        font-weight: 900 !important;
    }

    .process-step.active {
        color: #1d4ed8 !important;
    }

    .process-step.active span {
        background: #2563eb !important;
        color: #fff !important;
    }

    .process-line {
        flex: 1 !important;
        height: 1px !important;
        background: #bfdbfe !important;
        min-width: 18px !important;
    }

    .payment-method-title {
        margin-bottom: 10px !important;
        color: #64748b !important;
        font-size: .72rem !important;
        font-weight: 900 !important;
        text-transform: uppercase !important;
        letter-spacing: .06em !important;
    }

    .real-method-grid {
        display: grid !important;
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        gap: 10px !important;
        margin-bottom: 16px !important;
    }

    .real-method {
        min-height: 78px !important;
        padding: 13px 10px !important;
        border-radius: 20px !important;
        background: rgba(255,255,255,.88) !important;
        border: 1px solid #dbe5f0 !important;
        color: #334155 !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 5px !important;
        cursor: pointer !important;
        transition: .18s ease !important;
        font-family: inherit !important;
    }

    .real-method i {
        color: #2563eb !important;
        font-size: 1.1rem !important;
    }

    .real-method span {
        font-size: .8rem !important;
        font-weight: 900 !important;
    }

    .real-method small {
        color: #64748b !important;
        font-size: .68rem !important;
        font-weight: 800 !important;
    }

    .real-method.active {
        background: #eff6ff !important;
        border-color: #93c5fd !important;
        color: #1d4ed8 !important;
        box-shadow: 0 14px 30px rgba(37,99,235,.10) !important;
        transform: translateY(-1px) !important;
    }

    .channel-panel {
        display: none !important;
        border-radius: 22px !important;
        padding: 15px !important;
        background: rgba(255,255,255,.86) !important;
        border: 1px solid #dbe5f0 !important;
        margin-bottom: 16px !important;
    }

    .channel-panel.active {
        display: block !important;
    }

    .channel-title {
        color: #0f172a !important;
        font-size: .92rem !important;
        font-weight: 900 !important;
        display: flex !important;
        align-items: center !important;
        gap: 9px !important;
        margin-bottom: 7px !important;
    }

    .channel-title i {
        color: #2563eb !important;
    }

    .channel-panel p {
        margin: 0 0 12px !important;
        color: #64748b !important;
        font-size: .82rem !important;
        font-weight: 760 !important;
        line-height: 1.35 !important;
    }

    .bank-link-row {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        gap: 10px !important;
    }

    .bank-link {
        min-height: 50px !important;
        padding: 0 12px !important;
        border-radius: 17px !important;
        background: #fff !important;
        border: 1px solid #dbe5f0 !important;
        color: #0f172a !important;
        display: flex !important;
        align-items: center !important;
        gap: 9px !important;
        text-decoration: none !important;
        font-size: .82rem !important;
        font-weight: 900 !important;
    }

    .bank-link span {
        width: 30px !important;
        height: 30px !important;
        border-radius: 11px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        color: #fff !important;
        font-weight: 900 !important;
        flex: 0 0 auto !important;
    }

    .bank-link.maybank span {
        background: #f59e0b !important;
    }

    .bank-link.cimb span {
        background: #ef4444 !important;
    }

    .bank-link i {
        margin-left: auto !important;
        color: #2563eb !important;
        font-size: .82rem !important;
    }

    .bank-link:hover {
        border-color: #93c5fd !important;
        background: #eff6ff !important;
    }

    .payment-field {
        margin-bottom: 14px !important;
    }

    .payment-field label {
        margin-bottom: 7px !important;
        color: #64748b !important;
        font-size: .72rem !important;
        font-weight: 900 !important;
        letter-spacing: .055em !important;
        text-transform: uppercase !important;
    }

    .payment-field input[type="text"] {
        height: 50px !important;
        border-radius: 17px !important;
        border: 1px solid #dbe5f0 !important;
        background: rgba(255,255,255,.90) !important;
        padding: 0 15px !important;
        font-weight: 820 !important;
    }

    .receipt-upload-box {
        min-height: 88px !important;
        padding: 14px !important;
        border-radius: 20px !important;
        border: 1.5px dashed #93c5fd !important;
        background: rgba(239,246,255,.55) !important;
        display: flex !important;
        align-items: center !important;
        gap: 14px !important;
        cursor: pointer !important;
        transition: .18s ease !important;
    }

    .receipt-upload-box:hover {
        background: #eff6ff !important;
        border-color: #2563eb !important;
    }

    .receipt-upload-box input {
        display: none !important;
    }

    .receipt-upload-icon {
        width: 48px !important;
        height: 48px !important;
        border-radius: 17px !important;
        background: #dbeafe !important;
        color: #2563eb !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        font-size: 1.2rem !important;
        flex: 0 0 auto !important;
    }

    .receipt-upload-text {
        display: grid !important;
        gap: 4px !important;
        min-width: 0 !important;
    }

    .receipt-upload-text strong {
        color: #0f172a !important;
        font-size: .9rem !important;
        font-weight: 900 !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
    }

    .receipt-upload-text small {
        color: #64748b !important;
        font-size: .78rem !important;
        font-weight: 750 !important;
    }

    .verification-note {
        border-radius: 18px !important;
        background: #eff6ff !important;
        border: 1px solid #bfdbfe !important;
        color: #1d4ed8 !important;
        padding: 12px 14px !important;
        margin: 16px 0 !important;
        display: flex !important;
        align-items: flex-start !important;
        gap: 10px !important;
        font-size: .82rem !important;
        font-weight: 820 !important;
        line-height: 1.35 !important;
    }

    .realistic-pay-btn {
        min-height: 52px !important;
        border-radius: 18px !important;
    }

    .payment-modal-backdrop.tng-selected #bankInstructionBox {
        display: none !important;
    }

    .payment-modal-backdrop.tng-selected #tngQrBox {
        display: block !important;
    }

    .payment-modal-backdrop.cash-selected #bankInstructionBox,
    .payment-modal-backdrop.cash-selected #tngQrBox {
        display: none !important;
    }

    @media (max-width: 980px) {
        .gateway-body {
            grid-template-columns: 1fr !important;
        }

        .gateway-topbar {
            flex-direction: column !important;
        }

        .payment-month-pill {
            margin-right: 50px !important;
        }
    }

    @media (max-width: 680px) {
        .real-method-grid,
        .bank-link-row {
            grid-template-columns: 1fr !important;
        }

        .payment-process-strip {
            align-items: flex-start !important;
            flex-direction: column !important;
        }

        .process-line {
            display: none !important;
        }
    }
</style>


<style id="resident-vehicles-modal-click-safety-final">
    .vehicle-modal-backdrop:not(.show) {
        opacity: 0 !important;
        visibility: hidden !important;
        pointer-events: none !important;
    }

    .vehicle-modal-backdrop.show {
        pointer-events: auto !important;
    }

    .open-payment-modal,
    .open-vehicle-modal,
    .mini-add-btn,
    .btn,
    .profile-trigger,
    .nav-btn,
    a {
        pointer-events: auto;
    }
</style>


<style id="resident-vehicles-payment-modal-fit-screen-final">
    /* Make the payment popup fit inside the screen */
    .payment-modal-backdrop.show {
        align-items: center !important;
        justify-content: center !important;
        padding: 18px !important;
        overflow-y: auto !important;
    }

    .gateway-payment-card {
        width: min(980px, calc(100vw - 36px)) !important;
        max-height: calc(100vh - 36px) !important;
        height: auto !important;
        overflow: hidden !important;
        display: flex !important;
        flex-direction: column !important;
    }

    .gateway-payment-panel {
        max-height: calc(100vh - 36px) !important;
        height: auto !important;
        display: flex !important;
        flex-direction: column !important;
        overflow: hidden !important;
    }

    .gateway-topbar {
        min-height: auto !important;
        padding: 18px 28px 16px !important;
        flex: 0 0 auto !important;
    }

    .gateway-topbar h2 {
        font-size: 1.72rem !important;
        margin-bottom: 6px !important;
    }

    .gateway-topbar p {
        font-size: .88rem !important;
        line-height: 1.3 !important;
        max-width: 620px !important;
    }

    .payment-kicker {
        min-height: 28px !important;
        margin-bottom: 8px !important;
        font-size: .74rem !important;
    }

    .payment-month-pill {
        min-height: 36px !important;
        margin-right: 48px !important;
        padding: 0 14px !important;
        font-size: .78rem !important;
    }

    .gateway-body {
        flex: 1 1 auto !important;
        min-height: 0 !important;
        overflow-y: auto !important;
        padding: 18px 28px 22px !important;
        grid-template-columns: 330px minmax(0, 1fr) !important;
        gap: 20px !important;
    }

    .receipt-preview-card {
        padding: 18px !important;
        border-radius: 24px !important;
    }

    .receipt-logo {
        width: 48px !important;
        height: 48px !important;
        border-radius: 17px !important;
        margin-bottom: 14px !important;
    }

    .receipt-amount {
        font-size: 2.18rem !important;
    }

    .receipt-subtitle {
        font-size: .8rem !important;
        margin-top: 6px !important;
    }

    .receipt-lines {
        margin-top: 14px !important;
        gap: 7px !important;
    }

    .receipt-lines > div,
    .bank-row {
        min-height: 38px !important;
        padding: 7px 10px !important;
        border-radius: 14px !important;
    }

    .gateway-instruction-card,
    .tng-qr-card {
        margin-top: 10px !important;
        padding: 13px !important;
        border-radius: 20px !important;
    }

    .bank-title {
        margin-bottom: 8px !important;
        font-size: .86rem !important;
    }

    .payment-process-strip {
        min-height: 50px !important;
        padding: 9px 12px !important;
        margin-bottom: 12px !important;
        border-radius: 18px !important;
    }

    .process-step {
        font-size: .72rem !important;
    }

    .process-step span {
        width: 25px !important;
        height: 25px !important;
    }

    .real-method-grid {
        gap: 8px !important;
        margin-bottom: 12px !important;
    }

    .real-method {
        min-height: 62px !important;
        padding: 10px 8px !important;
        border-radius: 16px !important;
    }

    .real-method i {
        font-size: 1rem !important;
    }

    .real-method span {
        font-size: .74rem !important;
    }

    .real-method small {
        font-size: .64rem !important;
    }

    .channel-panel {
        padding: 12px !important;
        border-radius: 18px !important;
        margin-bottom: 12px !important;
    }

    .channel-title {
        font-size: .86rem !important;
        margin-bottom: 5px !important;
    }

    .channel-panel p {
        font-size: .76rem !important;
        margin-bottom: 10px !important;
    }

    .bank-link {
        min-height: 43px !important;
        border-radius: 14px !important;
        font-size: .78rem !important;
    }

    .bank-link span {
        width: 27px !important;
        height: 27px !important;
        border-radius: 10px !important;
    }

    .payment-field {
        margin-bottom: 10px !important;
    }

    .payment-field label {
        margin-bottom: 5px !important;
        font-size: .68rem !important;
    }

    .payment-field input[type="text"] {
        height: 44px !important;
        border-radius: 15px !important;
    }

    .receipt-upload-box {
        min-height: 68px !important;
        padding: 11px 13px !important;
        border-radius: 17px !important;
    }

    .receipt-upload-icon {
        width: 42px !important;
        height: 42px !important;
        border-radius: 15px !important;
    }

    .receipt-upload-text strong {
        font-size: .84rem !important;
    }

    .receipt-upload-text small {
        font-size: .72rem !important;
    }

    .verification-note {
        margin: 10px 0 12px !important;
        padding: 10px 12px !important;
        border-radius: 16px !important;
        font-size: .76rem !important;
    }

    .realistic-pay-btn {
        min-height: 46px !important;
        border-radius: 16px !important;
    }

    .tng-qr-frame {
        max-height: 210px !important;
    }

    @media (max-height: 760px) {
        .payment-modal-backdrop.show {
            align-items: flex-start !important;
            padding-top: 12px !important;
            padding-bottom: 12px !important;
        }

        .gateway-payment-card,
        .gateway-payment-panel {
            max-height: calc(100vh - 24px) !important;
        }

        .gateway-topbar {
            padding: 14px 24px 12px !important;
        }

        .gateway-topbar h2 {
            font-size: 1.45rem !important;
        }

        .gateway-topbar p {
            font-size: .78rem !important;
        }

        .gateway-body {
            padding: 14px 24px 18px !important;
            grid-template-columns: 300px minmax(0, 1fr) !important;
        }

        .receipt-preview-card {
            padding: 15px !important;
        }

        .receipt-amount {
            font-size: 1.9rem !important;
        }

        .gateway-instruction-card {
            display: none !important;
        }
    }
</style>


<style id="resident-vehicles-card-channel-final">
    .real-method-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
    }

    .card-pay-link {
        width: 100% !important;
        margin-top: 10px !important;
    }

    .card-pay-link span {
        background: #2563eb !important;
    }

    .payment-modal-backdrop.card-selected #bankInstructionBox,
    .payment-modal-backdrop.card-selected #tngQrBox {
        display: none !important;
    }

    @media (max-width: 820px) {
        .real-method-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        }
    }

    @media (max-width: 520px) {
        .real-method-grid {
            grid-template-columns: 1fr !important;
        }
    }
</style>


<style id="resident-vehicles-receipt-validation-final">
    .receipt-upload-box.receipt-error,
    .payment-field input.payment-error,
    .payment-field textarea.payment-error,
    .payment-field select.payment-error {
        border-color: #ef4444 !important;
        box-shadow: 0 0 0 4px rgba(239,68,68,.13) !important;
        background: #fff5f5 !important;
    }

    .receipt-upload-box.receipt-error .receipt-upload-icon {
        background: #fee2e2 !important;
        color: #dc2626 !important;
    }

    .receipt-upload-box.receipt-valid {
        border-color: #22c55e !important;
        background: #f0fdf4 !important;
    }

    .receipt-upload-box.receipt-valid .receipt-upload-icon {
        background: #dcfce7 !important;
        color: #16a34a !important;
    }

    .receipt-error-text {
        display: none;
        margin-top: 7px;
        color: #dc2626;
        font-size: .76rem;
        font-weight: 850;
    }

    .receipt-error-text.show {
        display: block;
    }
</style>



<style id="resident-slot-picker-popup-style">
    .slot-picker-field {
        display: grid;
        gap: 10px;
    }

    .slot-picker-trigger {
        width: 100%;
        min-height: 54px;
        border: 1px solid #bfdbfe;
        border-radius: 16px;
        background: #ffffff;
        color: #0f172a;
        font-family: inherit;
        font-weight: 950;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        padding: 0 16px;
        cursor: pointer;
        box-shadow: 0 10px 24px rgba(37, 99, 235, .06);
        transition: .18s ease;
    }

    .slot-picker-trigger:hover {
        transform: translateY(-1px);
        border-color: #60a5fa;
        box-shadow: 0 14px 28px rgba(37, 99, 235, .12);
    }

    .slot-picker-trigger .left {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
    }

    .slot-picker-trigger .icon {
        width: 34px;
        height: 34px;
        border-radius: 12px;
        display: grid;
        place-items: center;
        color: #2563eb;
        background: #dbeafe;
        flex: 0 0 auto;
    }

    .slot-picker-trigger .slot-main {
        display: block;
        font-size: .88rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .slot-picker-trigger .slot-sub {
        display: block;
        color: #64748b;
        font-size: .68rem;
        font-weight: 850;
        margin-top: 2px;
        text-align: left;
    }

    .slot-picker-note {
        color: #64748b;
        font-size: .72rem;
        font-weight: 800;
        line-height: 1.45;
    }

    .slot-map-backdrop {
        position: fixed;
        inset: 0;
        z-index: 4200;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 28px;
        background: rgba(15, 23, 42, .45);
        backdrop-filter: blur(9px);
        -webkit-backdrop-filter: blur(9px);
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transition: .2s ease;
    }

    .slot-map-backdrop.show {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
    }

    .slot-map-card {
        width: min(980px, calc(100vw - 44px));
        max-height: min(86vh, 760px);
        border: 1px solid #dbeafe;
        border-radius: 30px;
        background: rgba(255, 255, 255, .97);
        box-shadow: 0 34px 90px rgba(15, 23, 42, .28);
        overflow: hidden;
        display: flex;
        flex-direction: column;
        transform: translateY(16px) scale(.97);
        transition: .22s ease;
    }

    .slot-map-backdrop.show .slot-map-card {
        transform: translateY(0) scale(1);
    }

    .slot-map-head {
        padding: 18px 22px;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        background:
            radial-gradient(circle at top left, rgba(59, 130, 246, .10), transparent 34%),
            linear-gradient(135deg, #ffffff, #f8fbff);
    }

    .slot-map-title {
        display: flex;
        align-items: center;
        gap: 11px;
        color: #0f172a;
        font-weight: 950;
        font-size: 1rem;
    }

    .slot-map-title i {
        width: 36px;
        height: 36px;
        display: grid;
        place-items: center;
        border-radius: 13px;
        color: #2563eb;
        background: #dbeafe;
    }

    .slot-map-close {
        width: 40px;
        height: 40px;
        border: 1px solid #dbe5f0;
        border-radius: 15px;
        background: #ffffff;
        color: #64748b;
        cursor: pointer;
        display: grid;
        place-items: center;
        transition: .18s ease;
    }

    .slot-map-close:hover {
        color: #ef4444;
        background: #fff1f2;
        border-color: #fecaca;
    }

    .slot-map-toolbar {
        padding: 14px 22px;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
    }

    .slot-map-toolbar .hint {
        color: #64748b;
        font-size: .78rem;
        font-weight: 850;
    }

    .slot-map-toolbar .legend {
        display: inline-flex;
        align-items: center;
        gap: 12px;
        color: #64748b;
        font-size: .72rem;
        font-weight: 900;
    }

    .slot-map-toolbar .dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        display: inline-block;
        margin-right: 5px;
    }

    .slot-map-toolbar .dot.available {
        background: #22c55e;
    }

    .slot-map-toolbar .dot.selected {
        background: #2563eb;
    }

    .slot-map-body {
        padding: 22px;
        overflow: auto;
    }

    .slot-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(112px, 1fr));
        gap: 14px;
    }

    .slot-option {
        min-height: 100px;
        border: 2px solid #99f6e4;
        border-radius: 18px;
        background: #dcfdf7;
        color: #0f172a;
        font-family: inherit;
        font-weight: 950;
        font-size: .96rem;
        cursor: pointer;
        display: grid;
        place-items: center;
        padding: 12px;
        position: relative;
        transition: .18s ease;
    }

    .slot-option small {
        display: block;
        color: #64748b;
        font-size: .66rem;
        font-weight: 850;
        margin-top: 4px;
    }

    .slot-option:hover {
        transform: translateY(-2px);
        border-color: #2563eb;
        background: #eff6ff;
        box-shadow: 0 16px 32px rgba(37, 99, 235, .15);
    }

    .slot-option.is-selected {
        color: #ffffff;
        border-color: #2563eb;
        background: linear-gradient(135deg, #38bdf8, #2563eb);
        box-shadow: 0 20px 38px rgba(37, 99, 235, .24);
    }

    .slot-option.is-selected small {
        color: #dbeafe;
    }

    .slot-empty {
        border: 1px dashed #bfdbfe;
        border-radius: 20px;
        background: #f8fbff;
        padding: 32px 18px;
        text-align: center;
        color: #64748b;
        font-weight: 850;
    }

    .slot-empty i {
        width: 52px;
        height: 52px;
        display: grid;
        place-items: center;
        border-radius: 18px;
        color: #2563eb;
        background: #dbeafe;
        margin: 0 auto 12px;
    }

    @media (max-width: 760px) {
        .slot-map-backdrop {
            padding: 14px;
        }

        .slot-map-card {
            width: 100%;
            max-height: calc(100vh - 28px);
        }

        .slot-grid {
            grid-template-columns: repeat(auto-fill, minmax(92px, 1fr));
        }
    }
</style>

</head>
<body>

<nav class="navbar">
    <a href="resident.php" class="brand">Smart<span>VMS</span></a>

    <div class="nav-links">
        <a href="resident.php" class="nav-btn">
            <i class="fas fa-th-large"></i>
            Dashboard
        </a>

        <a href="notifications.php" class="nav-btn notification-nav-btn">
            <i class="fas fa-bell"></i>
            Notifications
            <?php if ($notificationCount > 0): ?>
                <span class="nav-notification-badge">
                    <?= $notificationCount > 99 ? '99+' : (int)$notificationCount ?>
                </span>
            <?php endif; ?>
        </a>

        <div class="profile-menu">
            <button type="button" class="profile-trigger" aria-label="Open profile menu">
                <span class="avatar-sm">
                    <?php if ($profilePhotoUrl): ?>
                        <img src="<?= e($profilePhotoUrl) ?>" alt="Resident photo">
                    <?php else: ?>
                        <?= e($residentInitial) ?>
                    <?php endif; ?>
                </span>
                <span><?= e($residentName) ?></span>
                <i class="fas fa-chevron-down"></i>
            </button>

            <div class="profile-dropdown">
                <div class="dropdown-head">
                    <div class="avatar-md">
                        <?php if ($profilePhotoUrl): ?>
                            <img src="<?= e($profilePhotoUrl) ?>" alt="Resident photo">
                        <?php else: ?>
                            <?= e($residentInitial) ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="dropdown-name"><?= e($residentName) ?></div>
                        <div class="dropdown-unit"><?= e($unitText) ?></div>
                    </div>
                </div>

                <a href="resident_profile.php" class="dropdown-link">
                    <i class="fas fa-user"></i>
                    My Profile
                </a>

                <a href="resident_settings.php" class="dropdown-link">
                    <i class="fas fa-lock"></i>
                    Change Password
                </a>

                <div class="dropdown-footer">
                    <a href="../core/logout.php" class="dropdown-link dropdown-logout">
                        <i class="fas fa-right-from-bracket"></i>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </div>
</nav>

<?php
    $mainAssignment = !empty($assignments) ? $assignments[0] : null;
    $mainPaymentStatus = $mainAssignment['payment_status'] ?? null;
    if (!$mainPaymentStatus && !empty($mainAssignment)) {
        $mainPaymentStatus = 'unpaid';
    }
    $mainExpiry = !empty($mainAssignment) ? rv_pay_month_expiry($mainAssignment['billing_month'] ?: $currentBillingMonth) : '-';
    $mainSlotText = !empty($mainAssignment) ? (($mainAssignment['block_name'] ? $mainAssignment['block_name'] . ' / ' : '') . $mainAssignment['slot_no']) : '-';
    $mainPaymentText = $paidThisMonth > 0 ? 'Paid' : ($pendingPayment > 0 ? 'Pending verify' : 'No payment made');
    $mainSlotStatusText = $activeSlots > 0 ? $activeSlots . ' active slot' . ($activeSlots > 1 ? 's' : '') : ($pendingRequests > 0 ? 'Request pending' : 'No slot yet');
?>

<div class="container">
    <section class="hero">
        <div class="hero-left">
            <div class="kicker"><i class="fas fa-car-side"></i> Resident Parking Subscription</div>
            <h1>My Parking</h1>
            <p class="hero-sub">Manage your vehicle, parking slot and monthly payment. Stay organized and enjoy a smoother resident parking experience.</p>

            <div class="hero-stats">
                <div class="hero-stat">
                    <div class="sicon"><i class="fas fa-square-parking"></i></div>
                    <div>
                        <div class="slabel">Slot Status</div>
                        <div class="svalue"><?= e($mainSlotStatusText) ?></div>
                    </div>
                </div>
                <div class="hero-stat">
                    <div class="sicon"><i class="fas fa-calendar-days"></i></div>
                    <div>
                        <div class="slabel">Billing Month</div>
                        <div class="svalue"><?= e(date('F Y', strtotime($currentBillingMonth . '-01'))) ?></div>
                    </div>
                </div>
                <div class="hero-stat">
                    <div class="sicon" style="background:#ecfdf3;color:#16a34a;"><i class="fas fa-check"></i></div>
                    <div>
                        <div class="slabel">Payment Status</div>
                        <div class="svalue"><?= e($mainPaymentText) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="car-art" aria-hidden="true">
            <div class="city-line"></div>
            <div class="car-blob"></div>
            <div class="parking-sign">P</div>
            <svg class="car-svg" viewBox="0 0 420 210" fill="none" xmlns="http://www.w3.org/2000/svg">
                <ellipse cx="210" cy="175" rx="170" ry="18" fill="#BFDBFE" opacity="0.55"/>
                <path d="M91 126C104 92 129 68 171 64H259C297 65 319 91 339 125L365 132C382 136 394 151 394 168V170C394 180 386 188 376 188H68C58 188 50 180 50 170V164C50 145 65 130 84 128L91 126Z" fill="#EFF6FF" stroke="#BFDBFE" stroke-width="4"/>
                <path d="M130 70H178L164 118H95C105 93 115 78 130 70Z" fill="#DBEAFE"/>
                <path d="M190 70H254C279 72 297 92 310 118H178L190 70Z" fill="#DBEAFE"/>
                <path d="M80 136H382C389 144 394 154 394 168V170C394 180 386 188 376 188H68C58 188 50 180 50 170V164C50 152 56 142 80 136Z" fill="#2563EB" opacity="0.88"/>
                <circle cx="126" cy="184" r="27" fill="#0F172A"/>
                <circle cx="126" cy="184" r="13" fill="#E2E8F0"/>
                <circle cx="318" cy="184" r="27" fill="#0F172A"/>
                <circle cx="318" cy="184" r="13" fill="#E2E8F0"/>
                <rect x="345" y="148" width="32" height="10" rx="5" fill="#BFDBFE"/>
                <rect x="70" y="148" width="32" height="10" rx="5" fill="#BFDBFE"/>
            </svg>
        </div>
    </section>

    <?php if (!$parkingModuleReady): ?>
        <div class="alert error">Parking module database is not installed yet. Please run <b>smartvms_parking_payment_step2.sql</b> in phpMyAdmin first.</div>
    <?php endif; ?>
    <?php if ($message): ?><div class="alert success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

    <section class="grid-main">
        <div class="panel">
            <div class="panel-head">
                <div class="panel-title"><i class="fas fa-car-side"></i> My Vehicle Showcase</div>
                <div class="panel-actions">
                    <span class="badge <?= $activeSlots > 0 ? 'badge-green' : ($pendingRequests > 0 ? 'badge-yellow' : 'badge-gray') ?>">
                        <?= $activeSlots > 0 ? 'Parking active' : ($pendingRequests > 0 ? 'Request pending' : 'No slot yet') ?>
                    </span>
                    <button type="button" class="mini-add-btn open-vehicle-modal" aria-haspopup="dialog">
                        <i class="fas fa-plus"></i>
                        Add Vehicle
                    </button>
                </div>
            </div>
            <div class="panel-body">
                <?php $showcaseCount = count($vehicleShowcaseCards); ?>
                <div class="vehicle-showcase-shell<?= $showcaseCount > 1 ? ' has-multiple' : '' ?>" <?= $showcaseCount > 1 ? 'data-multiple="1"' : '' ?>>
                    <?php if ($showcaseCount > 1): ?>
                        <button type="button" class="showcase-page-btn showcase-next-btn" id="showcaseNextBtn" aria-label="Next vehicle" title="Next vehicle">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    <?php endif; ?>

                    <div class="vehicle-layout">
                        <div class="vehicle-photo-card vehicle-photo-card-flip" id="showcasePhotoCard">
                            <?php if ($showcaseCount > 1): ?>
                                <div class="showcase-page-indicator"><span id="showcaseCurrentIndex">1</span> / <span><?= e((string)$showcaseCount) ?></span></div>
                            <?php endif; ?>

                            <div class="vehicle-photo-wrap" id="showcasePhotoWrap">
                                <?php if (!empty($initialShowcasePhotoUrl)): ?>
                                    <img src="<?= e($initialShowcasePhotoUrl) ?>" alt="My vehicle photo">
                                <?php else: ?>
                                    <div class="car-placeholder">
                                        <i class="fas fa-car-side"></i>
                                        <strong>No car photo yet</strong>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="plate-tag">
                                <div class="country">MY</div>
                                <div class="plate-text" id="showcasePlateText"><?= e($initialShowcaseVehicle['plate_no'] ?? ($featuredVehicle['plate_no'] ?? 'RES1234')) ?></div>
                            </div>

                            <div class="photo-note" id="showcasePhotoNote" style="<?= !empty($initialShowcasePhotoUrl) ? 'display:none;' : '' ?>">
                                <i class="fas fa-camera"></i>
                                <strong>No car photo yet</strong><br>
                                Upload your vehicle photo when adding a new car.
                            </div>

                            <?php if ($showcaseCount > 1): ?>
                                <div class="showcase-next-copy">Tap the arrow to view the next vehicle.</div>
                            <?php endif; ?>

                            <button type="button" class="btn btn-primary open-vehicle-modal" style="width:100%; margin-top:16px;">
                                <i class="fas fa-plus"></i> Add New Car
                            </button>
                        </div>

                        <div class="vehicle-info-list" id="showcaseInfoList">
                            <div class="vehicle-row">
                                <div class="row-icon"><i class="fas fa-car"></i></div>
                                <div>
                                    <div class="row-label">Vehicle Model</div>
                                    <div class="row-value" id="showcaseModelValue"><?= e($initialShowcaseVehicle['vehicle_model'] ?? ($featuredVehicle['vehicle_model'] ?? 'Resident Demo Car')) ?></div>
                                </div>
                            </div>
                            <div class="vehicle-row">
                                <div class="row-icon"><i class="fas fa-palette"></i></div>
                                <div>
                                    <div class="row-label">Vehicle Color</div>
                                    <div class="row-value" id="showcaseColorValue"><?= e($initialShowcaseVehicle['vehicle_color'] ?? ($featuredVehicle['vehicle_color'] ?? 'Black')) ?></div>
                                </div>
                            </div>
                            <div class="vehicle-row">
                                <div class="row-icon"><i class="fas fa-square-parking"></i></div>
                                <div>
                                    <div class="row-label">Parking Slot</div>
                                    <div class="row-value" id="showcaseSlotValue"><?= e($initialShowcaseVehicle['slot_text'] ?? $mainSlotText) ?></div>
                                    <div class="row-sub" id="showcaseSlotSub" style="<?= empty($initialShowcaseVehicle['slot_sub']) ? 'display:none;' : '' ?>"><?= e($initialShowcaseVehicle['slot_sub'] ?? '') ?></div>
                                </div>
                            </div>
                            <div class="vehicle-row">
                                <div class="row-icon"><i class="fas fa-calendar-check"></i></div>
                                <div>
                                    <div class="row-label">Expiry / Billing</div>
                                    <div class="row-value" id="showcaseExpiryValue"><?= e($initialShowcaseVehicle['expiry_text'] ?? (!empty($mainAssignment) ? $mainExpiry : '-')) ?></div>
                                    <div class="row-sub" id="showcaseBillingSub"><?= e($initialShowcaseVehicle['billing_text'] ?? (!empty($mainAssignment) ? ('Billing month: ' . ($mainAssignment['billing_month'] ?: $currentBillingMonth)) : 'No billing period yet')) ?></div>
                                </div>
                            </div>

                            <div class="request-item" id="showcaseRequestItem" style="<?= empty($initialShowcaseVehicle['request_title']) ? 'display:none;' : '' ?>">
                                <div class="request-main">
                                    <div class="request-icon"><i class="fas fa-clock-rotate-left"></i></div>
                                    <div>
                                        <div class="request-title" id="showcaseRequestTitle"><?= e($initialShowcaseVehicle['request_title'] ?? '') ?></div>
                                        <div class="request-sub" id="showcaseRequestSub"><?= e($initialShowcaseVehicle['request_sub'] ?? '') ?></div>
                                    </div>
                                </div>
                                <span class="badge <?= e($initialShowcaseVehicle['request_status_class'] ?? 'badge-gray') ?>" id="showcaseRequestBadge"><?= e($initialShowcaseVehicle['request_status_text'] ?? '') ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="panel" id="paymentCenter">
            <div class="panel-head">
                <div class="panel-title"><i class="fas fa-money-check-dollar"></i> Monthly Parking Payment</div>
                <span class="badge badge-gray"><i class="fas fa-calendar-days"></i> <?= e(date('F Y', strtotime($currentBillingMonth . '-01'))) ?></span>
            </div>
            <div class="panel-body">
                <?php if (!$parkingModuleReady): ?>
                    <div class="empty-box"><i class="fas fa-database"></i><strong>Parking module not ready</strong><span>Please run the parking SQL file first.</span></div>
                <?php elseif (empty($assignments)): ?>
                    <div class="pay-status-card">
                        <div>
                            <div class="pay-small">Current Payment Status</div>
                            <div class="pay-title">No payment made yet</div>
                            <div class="pay-desc">Make your monthly payment to activate or continue your parking slot.</div>
                        </div>
                        <button type="button" class="btn btn-primary open-vehicle-modal">Add Vehicle <i class="fas fa-arrow-right"></i></button>
                    </div>
                    <div class="info-note"><i class="fas fa-circle-info"></i>Payment is monthly. Once admin verifies your payment, guard access will be allowed for the whole billing month.</div>
                    <div class="empty-box"><i class="fas fa-file-invoice"></i><strong>No payment to make yet</strong><span>After admin approves your request and assigns a parking slot, your monthly payment form will appear here.</span></div>
                <?php else: ?>
                    <?php foreach ($assignments as $assignment): ?>
                        <?php
                            $paymentStatus = $assignment['payment_status'] ?? 'unpaid';
                            $paymentAmount = (float)($assignment['payment_amount'] ?? $assignment['monthly_fee'] ?? 0);
                            $slotText = ($assignment['block_name'] ? $assignment['block_name'] . ' / ' : '') . $assignment['slot_no'];
                            $expiryText = rv_pay_month_expiry($assignment['billing_month'] ?: $currentBillingMonth);
                        ?>
                        <div class="assignment-card">
                            <div class="assignment-top">
                                <div class="slot-title"><i class="fas fa-square-parking"></i><?= e($slotText) ?></div>
                                <span class="badge <?= e(rv_pay_status_class($paymentStatus)) ?>"><?= e(str_replace('_', ' ', $paymentStatus)) ?></span>
                            </div>

                            <div class="assignment-grid">
                                <div class="mini-info">
                                    <div class="label">Vehicle</div>
                                    <div class="value"><?= e($assignment['plate_no']) ?></div>
                                </div>
                                <div class="mini-info">
                                    <div class="label">Amount</div>
                                    <div class="value"><?= e(rv_pay_money($paymentAmount)) ?></div>
                                </div>
                                <div class="mini-info">
                                    <div class="label">Valid Until</div>
                                    <div class="value"><?= e($expiryText) ?></div>
                                </div>
                            </div>

                            <?php if (!empty($assignment['admin_remark'])): ?>
                                <div class="info-note"><i class="fas fa-message"></i>Admin remark: <?= e($assignment['admin_remark']) ?></div>
                            <?php endif; ?>

                            <?php if ($paymentStatus === 'paid'): ?>
                                <div class="info-note" style="background:#ecfdf3;color:#166534;border-color:#bbf7d0;"><i class="fas fa-check-circle"></i>Payment verified. Guard access is allowed for this billing month.</div>
                                <?php if (!empty($assignment['payment_id'])): ?>
                                    <a href="resident_parking_receipt.php?id=<?= (int)$assignment['payment_id'] ?>" target="_blank" class="btn btn-green" style="width:100%;">
                                        <i class="fas fa-file-invoice"></i> Official Receipt
                                    </a>
                                <?php endif; ?>
                            <?php elseif ($paymentStatus === 'pending_verification'): ?>
                                <div class="info-note" style="background:#fffbeb;color:#92400e;border-color:#fcd34d;"><i class="fas fa-clock"></i>Payment submitted. Please wait for admin verification.</div>
                            <?php else: ?>
                                <button
                                    type="button"
                                    class="btn btn-primary open-payment-modal"
                                    style="width:100%;"
                                    data-payment-id="<?= (int)$assignment['payment_id'] ?>"
                                    data-amount="<?= e(rv_pay_money($paymentAmount)) ?>"
                                    data-plate="<?= e($assignment['plate_no']) ?>"
                                    data-slot="<?= e($slotText) ?>"
                                    data-month="<?= e(date('F Y', strtotime(($assignment['billing_month'] ?: $currentBillingMonth) . '-01'))) ?>"
                                >
                                    <i class="fas fa-wallet"></i> Go to Payment
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="vehicle-modal-backdrop" id="vehicleRequestModal" aria-hidden="true">
    <div class="vehicle-modal-card" role="dialog" aria-modal="true" aria-labelledby="vehicleModalTitle">
        <button type="button" class="vehicle-modal-close" id="closeVehicleModal" aria-label="Close add vehicle form">
            <i class="fas fa-xmark"></i>
        </button>

        <section class="panel wide-panel vehicle-modal-panel" id="requestFormPanel">
        <div class="panel-head">
            <div class="panel-title" id="vehicleModalTitle"><i class="fas fa-car-side"></i> Add Vehicle & Request Parking Slot</div>
            <span class="badge <?= $maxSlotsReached ? 'badge-red' : 'badge-yellow' ?>"><?= e($defaultFeeText) ?></span>
        </div>
        <div class="panel-body">
            <div class="form-intro">
                <div>
                    <div class="pay-title">Add your vehicle details and request a parking slot.</div>
                    <div class="form-intro-text">Our admin will review your request and assign a resident parking slot after approval.</div>
                </div>
            </div>

            <?php if (!$parkingModuleReady): ?>
                <div class="empty-box"><i class="fas fa-database"></i><strong>Please run the SQL file first.</strong></div>
            <?php elseif ($maxSlotsReached): ?>
                <div class="empty-box"><i class="fas fa-circle-exclamation"></i><strong>You already have 2 active resident parking slots.</strong><span>Please contact admin if you need more.</span></div>
            <?php elseif ($pendingRequests > 0): ?>
                <div class="empty-box"><i class="fas fa-clock"></i><strong>You already have a pending parking request.</strong><span>Please wait for admin approval first.</span></div>
            <?php else: ?>
                <form method="POST" enctype="multipart/form-data" id="addVehicleRequestForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_vehicle_and_request">

                    <div class="vehicle-form">
                        <div class="upload-box">
                            <img id="vehiclePhotoPreview" class="photo-preview" alt="Vehicle photo preview">
                            <div id="vehiclePhotoPlaceholder" class="upload-icon"><i class="fas fa-cloud-arrow-up"></i></div>
                            <strong>Upload your vehicle photo</strong>
                            <small>Required for TNG only · Required for TNG only · JPG, PNG or WEBP up to 5MB</small>
                            <input type="file" name="vehicle_photo" id="vehiclePhotoInput" accept="image/*" capture="environment" required>
                        </div>

                        <div>
                            <div class="fields-grid">
                                <div class="field full">
                                    <label>Vehicle Plate Number</label>
                                    <input type="text" name="plate_no" class="plate-input" placeholder="e.g. ABC1234" required>
                                </div>

                                <div class="field">
                                    <label>Vehicle Model</label>
                                    <input type="text" name="vehicle_model" placeholder="e.g. Honda City">
                                </div>

                                <div class="field">
                                    <label>Vehicle Color</label>
                                    <input type="text" name="vehicle_color" placeholder="e.g. Black">
                                </div>

                                <div class="field full slot-picker-field">
                                    <label>Parking Slot</label>
                                    <input type="hidden" name="selected_slot_id" id="selectedResidentSlotId" required>

                                    <button type="button" class="slot-picker-trigger" id="openResidentSlotPicker">
                                        <span class="left">
                                            <span class="icon"><i class="fas fa-square-parking"></i></span>
                                            <span>
                                                <span class="slot-main" id="selectedResidentSlotText">Choose your preferred parking slot</span>
                                                <span class="slot-sub">Only available slots in your apartment are shown.</span>
                                            </span>
                                        </span>
                                        <i class="fas fa-chevron-right"></i>
                                    </button>

                                    <div class="slot-picker-note">
                                        You can select a preferred slot first. Admin will review and approve it before it becomes active.
                                    </div>
                                </div>

                                <div class="field full">
                                    <label>Reason / Remark</label>
                                    <textarea name="reason" placeholder="e.g. I need this parking slot for my daily resident vehicle."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary submit-wide">
                        <i class="fas fa-paper-plane"></i> Add Vehicle & Send Request to Admin
                    </button>
                </form>
            <?php endif; ?>

            <?php if ($parkingModuleReady && !empty($requests)): ?>
                <div class="request-list">
                    <?php foreach ($requests as $request): ?>
                        <div class="request-item">
                            <div class="request-main">
                                <div class="request-icon"><i class="fas fa-file-lines"></i></div>
                                <div>
                                    <div class="request-title"><?= e($request['plate_no']) ?> · <?= e(ucwords(str_replace('_', ' ', $request['request_type']))) ?></div>
                                    <div class="request-sub"><?= e(date('d M Y, g:i A', strtotime($request['requested_at']))) ?><?= !empty($request['admin_remark']) ? ' · Admin: ' . e($request['admin_remark']) : '' ?></div>
                                </div>
                            </div>
                            <span class="badge <?= e(rv_pay_status_class($request['status'])) ?>"><?= e($request['status']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
            </section>
    </div>
</div>
</div>

    
    

    <div class="slot-map-backdrop" id="residentSlotPickerModal" aria-hidden="true">
        <div class="slot-map-card" role="dialog" aria-modal="true" aria-labelledby="residentSlotPickerTitle">
            <div class="slot-map-head">
                <div class="slot-map-title" id="residentSlotPickerTitle">
                    <i class="fas fa-square-parking"></i>
                    Select Resident Parking Slot
                </div>

                <button type="button" class="slot-map-close" id="closeResidentSlotPicker" aria-label="Close slot selector">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>

            <div class="slot-map-toolbar">
                <div class="hint">
                    Choose one available resident parking slot for your vehicle request.
                </div>

                <div class="legend">
                    <span><i class="dot available"></i>Available</span>
                    <span><i class="dot selected"></i>Selected</span>
                </div>
            </div>

            <div class="slot-map-body">
                <?php if (empty($availableResidentSlots)): ?>
                    <div class="slot-empty">
                        <i class="fas fa-circle-exclamation"></i>
                        <strong>No available resident parking slot found.</strong>
                        <div>Please contact admin or try again later.</div>
                    </div>
                <?php else: ?>
                    <div class="slot-grid">
                        <?php foreach ($availableResidentSlots as $slot): ?>
                            <?php $slotLabel = trim((string)(($slot['block_name'] ?? '-') . ' / ' . ($slot['slot_no'] ?? '-'))); ?>
                            <button
                                type="button"
                                class="slot-option"
                                data-slot-id="<?= (int)$slot['id'] ?>"
                                data-slot-label="<?= e($slotLabel) ?>"
                            >
                                <span>
                                    <?= e($slot['slot_no']) ?>
                                    <small><?= e($slot['block_name']) ?></small>
                                </span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="vehicle-modal-backdrop payment-modal-backdrop" id="parkingPaymentModal" aria-hidden="true">
        <div class="vehicle-modal-card payment-modal-card realistic-payment-card gateway-payment-card" role="dialog" aria-modal="true" aria-labelledby="paymentModalTitle">
            <button type="button" class="vehicle-modal-close payment-close" id="closePaymentModal" aria-label="Close payment form">
                <i class="fas fa-xmark"></i>
            </button>

            <section class="panel wide-panel vehicle-modal-panel payment-modal-panel gateway-payment-panel">
                <div class="gateway-topbar">
                    <div>
                        <div class="payment-kicker">
                            <i class="fas fa-lock"></i>
                            Secure Parking Payment
                        </div>
                        <h2 id="paymentModalTitle">Monthly Parking Fee</h2>
                        <p>Choose your preferred payment channel, complete payment, then upload your receipt for admin verification.</p>
                    </div>

                    <span class="payment-month-pill" id="paymentModalMonth">June 2026</span>
                </div>

                <div class="gateway-body">
                    <aside class="payment-summary-column">
                        <div class="receipt-preview-card">
                            <div class="receipt-logo">
                                <i class="fas fa-square-parking"></i>
                            </div>
                            <div class="receipt-label">Amount Due</div>
                            <div class="receipt-amount" id="paymentModalAmount">RM0.00</div>
                            <div class="receipt-subtitle">Resident monthly parking access</div>

                            <div class="receipt-lines">
                                <div>
                                    <span>Vehicle</span>
                                    <strong id="paymentModalPlate">-</strong>
                                </div>
                                <div>
                                    <span>Parking Slot</span>
                                    <strong id="paymentModalSlot">-</strong>
                                </div>
                                <div>
                                    <span>Billing Month</span>
                                    <strong id="paymentModalBilling">-</strong>
                                </div>
                            </div>
                        </div>

                        <div class="gateway-instruction-card" id="bankInstructionBox">
                            <div class="bank-title">
                                <i class="fas fa-building-columns"></i>
                                Bank Transfer Details
                            </div>
                            <div class="bank-row">
                                <span>Pay To</span>
                                <strong>Ixora Apartment Management</strong>
                            </div>
                            <div class="bank-row">
                                <span>Bank</span>
                                <strong>Maybank</strong>
                            </div>
                            <div class="bank-row">
                                <span>Account No</span>
                                <strong>5142 8890 2231</strong>
                            </div>
                            <div class="bank-row">
                                <span>Reference</span>
                                <strong id="paymentReferenceText">Vehicle / Slot</strong>
                            </div>
                        </div>

                        <div class="tng-qr-card" id="tngQrBox">
                            <div class="bank-title">
                                <i class="fas fa-qrcode"></i>
                                Touch 'n Go eWallet QR
                            </div>
                            <div class="tng-qr-frame">
                                <img src="qr.jpeg" alt="Touch 'n Go QR" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div class="qr-placeholder">
                                    <i class="fas fa-qrcode"></i>
                                    <span>Place your TNG QR image as</span>
                                    <strong>public/qr.jpeg</strong>
                                </div>
                            </div>
                            <p>After scanning and paying, upload the receipt below.</p>
                        </div>
                    </aside>

                    <section class="payment-action-column">
                        <div class="payment-process-strip">
                            <div class="process-step active">
                                <span>1</span>
                                <strong>Pay</strong>
                            </div>
                            <div class="process-line"></div>
                            <div class="process-step">
                                <span>2</span>
                                <strong>Upload receipt</strong>
                            </div>
                            <div class="process-line"></div>
                            <div class="process-step">
                                <span>3</span>
                                <strong>Admin verify</strong>
                            </div>
                        </div>

                        <form method="POST" enctype="multipart/form-data" class="payment-modal-form gateway-payment-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="submit_payment">
                            <input type="hidden" name="payment_id" id="paymentModalPaymentId" value="">
                            <input type="hidden" name="payment_method" id="paymentMethodInput" value="Online Transfer">

                            <div class="payment-method-title">Payment channel</div>
                            <div class="real-method-grid">
                                <button type="button" class="real-method active" data-method="Online Transfer" data-panel="bank">
                                    <i class="fas fa-building-columns"></i>
                                    <span>Bank Transfer</span>
                                    <small>Maybank / CIMB</small>
                                </button>

                                <button type="button" class="real-method" data-method="Credit / Debit Card" data-panel="card">
                                    <i class="fas fa-credit-card"></i>
                                    <span>Card Payment</span>
                                    <small>Visa / Mastercard</small>
                                </button>

                                <button type="button" class="real-method" data-method="DuitNow QR" data-panel="tng">
                                    <i class="fas fa-wallet"></i>
                                    <span>TNG eWallet</span>
                                    <small>Scan QR</small>
                                </button>

                                <button type="button" class="real-method" data-method="Cash at Management Office" data-panel="cash">
                                    <i class="fas fa-money-bill-wave"></i>
                                    <span>Cash Office</span>
                                    <small>Pay at counter</small>
                                </button>
                            </div>

                            <div class="channel-panel active" id="bankChannelPanel">
                                <div class="channel-title">
                                    <i class="fas fa-arrow-up-right-from-square"></i>
                                    Open online banking
                                </div>
                                <p>Choose Maybank2u or CIMB Clicks. You will see a bank-style login, OTP verification and payment loading screen.</p>

                                <div class="bank-link-row">
                                    <a class="bank-link maybank" id="maybankPayLink" href="resident_bank_payment.php?bank=maybank">
                                        <span>M</span>
                                        Maybank2u
                                        <i class="fas fa-arrow-up-right-from-square"></i>
                                    </a>
                                    <a class="bank-link cimb" id="cimbPayLink" href="resident_bank_payment.php?bank=cimb">
                                        <span>C</span>
                                        CIMB Clicks
                                        <i class="fas fa-arrow-up-right-from-square"></i>
                                    </a>
                                </div>
                            </div>


                            <div class="channel-panel" id="cardChannelPanel">
                                <div class="channel-title">
                                    <i class="fas fa-credit-card"></i>
                                    Pay by credit / debit card
                                </div>
                                <p>Continue to a card checkout screen and enter card details for this demo payment.</p>

                                <a class="bank-link card-pay-link" id="cardPayLink" href="resident_bank_payment.php?bank=card">
                                    <span><i class="fas fa-credit-card"></i></span>
                                    Card Checkout
                                    <i class="fas fa-arrow-up-right-from-square"></i>
                                </a>
                            </div>

                            <div class="channel-panel" id="tngChannelPanel">
                                <div class="channel-title">
                                    <i class="fas fa-qrcode"></i>
                                    Pay with TNG QR
                                </div>
                                <p>Scan the QR shown on the left using Touch 'n Go eWallet. Upload the receipt after payment.</p>
                            </div>

                            <div class="channel-panel" id="cashChannelPanel">
                                <div class="channel-title">
                                    <i class="fas fa-building"></i>
                                    Pay at management office
                                </div>
                                <p>Pay at the management counter and upload the official receipt photo here.</p>
                            </div>

                            <div class="field payment-field">
                                <label>Transaction / Reference No</label>
                                <input type="text" name="resident_remark" id="paymentReferenceInput" placeholder="Example: FPX123456789 / TNG Ref / Receipt No" required>
                            </div>

                            <div class="field payment-field">
                                <label>Upload Receipt</label>
                                <label class="receipt-upload-box" id="paymentReceiptBox">
                                    <input type="file" name="receipt_file" id="paymentReceiptInput" accept="image/jpeg,image/png,image/webp" required>
                                    <span class="receipt-upload-icon"><i class="fas fa-cloud-arrow-up"></i></span>
                                    <span class="receipt-upload-text">
                                        <strong id="receiptFileName">Choose receipt file</strong>
                                        <small>Required for TNG only · Required for TNG only · JPG, PNG or WEBP up to 5MB</small>
                                    </span>
                                </label>
                                <div class="receipt-error-text" id="receiptErrorText">Please upload a receipt image before submitting.</div>
                            </div>

                            <div class="verification-note">
                                <i class="fas fa-circle-info"></i>
                                <span>After submission, your payment status will become <strong>Pending Verification</strong>. Admin will approve it after checking your receipt.</span>
                            </div>

                            <button type="submit" class="btn btn-primary submit-wide realistic-pay-btn">
                                <i class="fas fa-paper-plane"></i>
                                Submit Payment for Verification
                            </button>
                        </form>
                    </section>
                </div>
            </section>
        </div>
    </div>

<script>
const vehicleModal = document.getElementById('vehicleRequestModal');
const openVehicleModalButtons = document.querySelectorAll('.open-vehicle-modal');
const closeVehicleModalButton = document.getElementById('closeVehicleModal');

function openVehicleModal() {
    if (!vehicleModal) return;
    vehicleModal.classList.add('show');
    vehicleModal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
}

function closeVehicleModal() {
    if (!vehicleModal) return;
    vehicleModal.classList.remove('show');
    vehicleModal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
}

openVehicleModalButtons.forEach((button) => {
    button.addEventListener('click', openVehicleModal);
});

if (closeVehicleModalButton) {
    closeVehicleModalButton.addEventListener('click', closeVehicleModal);
}

if (vehicleModal) {
    vehicleModal.addEventListener('click', function (event) {
        if (event.target === vehicleModal) {
            closeVehicleModal();
        }
    });
}

const residentSlotPickerModal = document.getElementById('residentSlotPickerModal');
const openResidentSlotPickerButton = document.getElementById('openResidentSlotPicker');
const closeResidentSlotPickerButton = document.getElementById('closeResidentSlotPicker');
const selectedResidentSlotId = document.getElementById('selectedResidentSlotId');
const selectedResidentSlotText = document.getElementById('selectedResidentSlotText');
const addVehicleRequestForm = document.getElementById('addVehicleRequestForm');
const residentSlotButtons = document.querySelectorAll('.slot-option');

function openResidentSlotPicker() {
    if (!residentSlotPickerModal) return;
    residentSlotPickerModal.classList.add('show');
    residentSlotPickerModal.setAttribute('aria-hidden', 'false');
}

function closeResidentSlotPicker() {
    if (!residentSlotPickerModal) return;
    residentSlotPickerModal.classList.remove('show');
    residentSlotPickerModal.setAttribute('aria-hidden', 'true');
}

if (openResidentSlotPickerButton) {
    openResidentSlotPickerButton.addEventListener('click', openResidentSlotPicker);
}

if (closeResidentSlotPickerButton) {
    closeResidentSlotPickerButton.addEventListener('click', closeResidentSlotPicker);
}

if (residentSlotPickerModal) {
    residentSlotPickerModal.addEventListener('click', function (event) {
        if (event.target === residentSlotPickerModal) {
            closeResidentSlotPicker();
        }
    });
}

residentSlotButtons.forEach(function (button) {
    button.addEventListener('click', function () {
        const slotId = button.dataset.slotId || '';
        const slotLabel = button.dataset.slotLabel || '';

        const applySelection = function () {
            if (selectedResidentSlotId) {
                selectedResidentSlotId.value = slotId;
            }

            if (selectedResidentSlotText) {
                selectedResidentSlotText.textContent = slotLabel;
            }

            residentSlotButtons.forEach(function (btn) {
                btn.classList.remove('is-selected');
            });

            button.classList.add('is-selected');
            closeResidentSlotPicker();
        };

        if (window.Swal) {
            Swal.fire({
                title: 'Confirm this parking slot?',
                text: slotLabel,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, select it',
                confirmButtonColor: '#2563eb'
            }).then(function (result) {
                if (result.isConfirmed) {
                    applySelection();
                }
            });
        } else if (confirm('Confirm this parking slot?\n' + slotLabel)) {
            applySelection();
        }
    });
});

if (addVehicleRequestForm) {
    addVehicleRequestForm.addEventListener('submit', function (event) {
        if (!selectedResidentSlotId || selectedResidentSlotId.value === '') {
            event.preventDefault();

            if (window.Swal) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Please choose a parking slot',
                    text: 'Select one available resident parking slot before sending the request.',
                    confirmButtonColor: '#2563eb'
                }).then(function () {
                    openResidentSlotPicker();
                });
            } else {
                alert('Please choose a parking slot first.');
                openResidentSlotPicker();
            }
        }
    });
}


const paymentModal = document.getElementById('parkingPaymentModal');
const closePaymentModalButton = document.getElementById('closePaymentModal');
const openPaymentModalButtons = document.querySelectorAll('.open-payment-modal');

const paymentModalPaymentId = document.getElementById('paymentModalPaymentId');
const paymentModalAmount = document.getElementById('paymentModalAmount');
const paymentModalPlate = document.getElementById('paymentModalPlate');
const paymentModalSlot = document.getElementById('paymentModalSlot');
const paymentModalBilling = document.getElementById('paymentModalBilling');
const paymentModalMonth = document.getElementById('paymentModalMonth');
const maybankPayLink = document.getElementById('maybankPayLink');
const cimbPayLink = document.getElementById('cimbPayLink');
const cardPayLink = document.getElementById('cardPayLink');
const paymentReferenceInput = document.getElementById('paymentReferenceInput');

const paymentReceiptInput = document.getElementById('paymentReceiptInput');
const paymentReceiptBox = document.getElementById('paymentReceiptBox');
const receiptErrorText = document.getElementById('receiptErrorText');
const paymentForm = document.querySelector('.payment-modal-form');
const receiptFileName = document.getElementById('receiptFileName');
const paymentReferenceText = document.getElementById('paymentReferenceText');
const paymentMethodInput = document.getElementById('paymentMethodInput');
const realMethodButtons = document.querySelectorAll('.real-method');
const channelPanels = {
    bank: document.getElementById('bankChannelPanel'),
    card: document.getElementById('cardChannelPanel'),
    tng: document.getElementById('tngChannelPanel'),
    cash: document.getElementById('cashChannelPanel')
};

function setPaymentChannel(button) {
    if (!button || !paymentModal) return;

    const panel = button.dataset.panel || 'bank';
    const method = button.dataset.method || 'Online Transfer';

    realMethodButtons.forEach((item) => item.classList.remove('active'));
    button.classList.add('active');

    Object.values(channelPanels).forEach((item) => {
        if (item) item.classList.remove('active');
    });

    if (channelPanels[panel]) {
        channelPanels[panel].classList.add('active');
    }

    if (paymentMethodInput) {
        paymentMethodInput.value = method;
    }

    paymentModal.classList.toggle('tng-selected', panel === 'tng');
    paymentModal.classList.toggle('cash-selected', panel === 'cash');
    paymentModal.classList.toggle('card-selected', panel === 'card');

    if (typeof updateReceiptRequirementUI === 'function') {
        updateReceiptRequirementUI();
    }
}

realMethodButtons.forEach((button) => {
    button.addEventListener('click', function () {
        setPaymentChannel(button);
    });
});

function isValidReceiptImage(file) {
    if (!file) return false;

    const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    const allowedExt = /\.(jpg|jpeg|png|webp)$/i;

    return allowedTypes.includes(file.type) || allowedExt.test(file.name || '');
}

function setReceiptError(message) {
    if (paymentReceiptBox) {
        paymentReceiptBox.classList.add('receipt-error');
        paymentReceiptBox.classList.remove('receipt-valid');
    }

    if (receiptErrorText) {
        receiptErrorText.textContent = message;
        receiptErrorText.classList.add('show');
    }
}

function clearReceiptError() {
    if (paymentReceiptBox) {
        paymentReceiptBox.classList.remove('receipt-error');
    }

    if (receiptErrorText) {
        receiptErrorText.classList.remove('show');
    }
}

if (paymentReceiptInput && receiptFileName) {
    paymentReceiptInput.addEventListener('change', function () {
        const file = this.files && this.files[0] ? this.files[0] : null;

        receiptFileName.textContent = file ? file.name : 'Choose receipt file';

        if (!file) {
            setReceiptError('Please upload a receipt image before submitting.');
            return;
        }

        if (!isValidReceiptImage(file)) {
            setReceiptError('Receipt must be an image file: JPG, PNG, or WEBP.');
            this.value = '';
            receiptFileName.textContent = 'Choose receipt file';
            return;
        }

        if (file.size > 5 * 1024 * 1024) {
            setReceiptError('Receipt image must not be larger than 5MB.');
            this.value = '';
            receiptFileName.textContent = 'Choose receipt file';
            return;
        }

        clearReceiptError();

        if (paymentReceiptBox) {
            paymentReceiptBox.classList.add('receipt-valid');
        }
    });
}

function isTngPaymentSelected() {
    const method = paymentMethodInput ? paymentMethodInput.value : '';
    return method.toLowerCase().includes('duitnow') || method.toLowerCase().includes('tng');
}

function updateReceiptRequirementUI() {
    const tngRequired = isTngPaymentSelected();

    if (receiptErrorText && !tngRequired) {
        receiptErrorText.classList.remove('show');
    }

    if (paymentReceiptBox && !tngRequired && !(paymentReceiptInput && paymentReceiptInput.files && paymentReceiptInput.files[0])) {
        paymentReceiptBox.classList.remove('receipt-error', 'receipt-valid');
    }

    const receiptSmall = paymentReceiptBox ? paymentReceiptBox.querySelector('small') : null;
    if (receiptSmall) {
        receiptSmall.textContent = tngRequired
            ? 'Required for TNG only · JPG, PNG or WEBP up to 5MB'
            : 'Optional for Bank/Card · JPG, PNG or WEBP up to 5MB';
    }
}

if (paymentForm) {
    paymentForm.addEventListener('submit', function (event) {
        const file = paymentReceiptInput && paymentReceiptInput.files && paymentReceiptInput.files[0]
            ? paymentReceiptInput.files[0]
            : null;

        const tngRequired = isTngPaymentSelected();

        if (tngRequired && !file) {
            event.preventDefault();
            setReceiptError('TNG payment must upload a receipt image before submitting.');

            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'warning',
                    title: 'TNG receipt required',
                    text: 'Please upload your TNG payment receipt image before submitting to admin.',
                    confirmButtonColor: '#2563eb'
                });
            }

            if (paymentReceiptBox) {
                paymentReceiptBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return;
        }

        if (file && !isValidReceiptImage(file)) {
            event.preventDefault();
            setReceiptError('Receipt must be an image file: JPG, PNG, or WEBP.');

            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'warning',
                    title: 'Invalid receipt file',
                    text: 'Please upload JPG, PNG, or WEBP receipt image only.',
                    confirmButtonColor: '#2563eb'
                });
            }
        }
    });
}
function openPaymentModal(button) {
    if (!paymentModal || !button) return;

    if (paymentModalPaymentId) paymentModalPaymentId.value = button.dataset.paymentId || '';
    if (paymentModalAmount) paymentModalAmount.textContent = button.dataset.amount || 'RM0.00';
    if (paymentModalPlate) paymentModalPlate.textContent = button.dataset.plate || '-';
    if (paymentModalSlot) paymentModalSlot.textContent = button.dataset.slot || '-';
    if (paymentModalBilling) paymentModalBilling.textContent = button.dataset.month || '-';
    if (paymentModalMonth) paymentModalMonth.textContent = button.dataset.month || 'Monthly Fee';

    const gatewayPaymentId = encodeURIComponent(button.dataset.paymentId || '');
    if (maybankPayLink) {
        maybankPayLink.href = 'resident_bank_payment.php?bank=maybank&payment_id=' + gatewayPaymentId;
    }
    if (cimbPayLink) {
        cimbPayLink.href = 'resident_bank_payment.php?bank=cimb&payment_id=' + gatewayPaymentId;
    }
    if (cardPayLink) {
        cardPayLink.href = 'resident_bank_payment.php?bank=card&payment_id=' + gatewayPaymentId;
    }
    if (paymentReferenceText) paymentReferenceText.textContent = (button.dataset.plate || '-') + ' / ' + (button.dataset.slot || '-');
    if (receiptFileName) receiptFileName.textContent = 'Choose receipt file';
    if (paymentReceiptInput) paymentReceiptInput.value = '';
    clearReceiptError();
    if (paymentReceiptBox) {
        paymentReceiptBox.classList.remove('receipt-valid');
    }
    if (typeof updateReceiptRequirementUI === 'function') {
        updateReceiptRequirementUI();
    }

    const defaultMethod = document.querySelector('.real-method[data-panel="bank"]');
    if (defaultMethod) {
        setPaymentChannel(defaultMethod);
    }

    paymentModal.classList.add('show');
    paymentModal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
}

function closePaymentModal() {
    if (!paymentModal) return;
    paymentModal.classList.remove('show');
    paymentModal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
}

openPaymentModalButtons.forEach((button) => {
    button.addEventListener('click', function () {
        openPaymentModal(button);
    });
});

if (closePaymentModalButton) {
    closePaymentModalButton.addEventListener('click', closePaymentModal);
}

if (paymentModal) {
    paymentModal.addEventListener('click', function (event) {
        if (event.target === paymentModal) {
            closePaymentModal();
        }
    });
}

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        closeVehicleModal();
        closePaymentModal();
        closeResidentSlotPicker();
    }
});

if (window.location.hash === '#requestFormPanel') {
    openVehicleModal();
}

const gatewayParams = new URLSearchParams(window.location.search);
const gatewayRef = gatewayParams.get('payment_ref');
const gatewayPaymentId = gatewayParams.get('payment_id');
const gatewayBank = gatewayParams.get('bank');

if (gatewayRef && gatewayPaymentId) {
    const targetButton = document.querySelector('.open-payment-modal[data-payment-id="' + CSS.escape(gatewayPaymentId) + '"]');

    if (targetButton) {
        openPaymentModal(targetButton);

        if (paymentReferenceInput) {
            paymentReferenceInput.value = gatewayRef;
            paymentReferenceInput.focus();
        }

        const bankButton = gatewayBank === 'cimb'
            ? document.querySelector('.real-method[data-panel="bank"]')
            : document.querySelector('.real-method[data-panel="bank"]');

        if (typeof setPaymentChannel === 'function' && bankButton) {
            setPaymentChannel(bankButton);
        }

        Swal.fire({
            icon: 'success',
            title: 'Payment reference generated',
            text: 'Reference ' + gatewayRef + ' has been filled in. Please upload your receipt and submit to admin.',
            confirmButtonColor: '#2563eb'
        });

        if (window.history && window.history.replaceState) {
            window.history.replaceState({}, document.title, 'resident_vehicles.php');
        }
    }
}

const vehiclePhotoInput = document.getElementById('vehiclePhotoInput');
const vehiclePhotoPreview = document.getElementById('vehiclePhotoPreview');
const vehiclePhotoPlaceholder = document.getElementById('vehiclePhotoPlaceholder');

if (vehiclePhotoInput && vehiclePhotoPreview && vehiclePhotoPlaceholder) {
    vehiclePhotoInput.addEventListener('change', function () {
        const file = this.files && this.files[0];
        if (!file) {
            vehiclePhotoPreview.style.display = 'none';
            vehiclePhotoPlaceholder.style.display = 'grid';
            return;
        }
        vehiclePhotoPreview.src = URL.createObjectURL(file);
        vehiclePhotoPreview.style.display = 'block';
        vehiclePhotoPlaceholder.style.display = 'none';
    });
}
</script>

<?php if ($message): ?>
<script>
Swal.fire({icon:'success', title:'Success', text:<?= json_encode($message) ?>, confirmButtonColor:'#2563eb'});
</script>
<?php endif; ?>

<?php if ($error): ?>
<script>
Swal.fire({icon:'error', title:'Error', text:<?= json_encode($error) ?>, confirmButtonColor:'#2563eb'});
</script>
<?php endif; ?>


<script id="resident-vehicle-photo-fallback-final">
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.vehicle-photo-wrap img').forEach(function (img) {
        img.addEventListener('error', function () {
            const box = img.closest('.vehicle-photo-wrap');
            if (!box) return;
            box.innerHTML = '<div class="car-placeholder"><i class="fas fa-car-side"></i><strong>Car photo not found</strong></div>';
        });
    });
});
</script>

<script>
    window.showcaseVehicles = <?= $vehicleShowcaseJson ?: '[]' ?>;

    document.addEventListener('DOMContentLoaded', function () {
        const vehicles = Array.isArray(window.showcaseVehicles) ? window.showcaseVehicles : [];
        const shell = document.querySelector('.vehicle-showcase-shell');
        const nextBtn = document.getElementById('showcaseNextBtn');
        if (!shell || !vehicles.length) return;

        let currentIndex = 0;
        const photoWrap = document.getElementById('showcasePhotoWrap');
        const photoNote = document.getElementById('showcasePhotoNote');
        const plateText = document.getElementById('showcasePlateText');
        const modelValue = document.getElementById('showcaseModelValue');
        const colorValue = document.getElementById('showcaseColorValue');
        const slotValue = document.getElementById('showcaseSlotValue');
        const slotSub = document.getElementById('showcaseSlotSub');
        const expiryValue = document.getElementById('showcaseExpiryValue');
        const billingSub = document.getElementById('showcaseBillingSub');
        const requestItem = document.getElementById('showcaseRequestItem');
        const requestTitle = document.getElementById('showcaseRequestTitle');
        const requestSub = document.getElementById('showcaseRequestSub');
        const requestBadge = document.getElementById('showcaseRequestBadge');
        const currentIndexEl = document.getElementById('showcaseCurrentIndex');

        const escapeHtml = (value) => {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        };

        const setText = (el, value, fallback = '') => {
            if (!el) return;
            el.textContent = (value ?? '') !== '' ? value : fallback;
        };

        const setVisibility = (el, visible) => {
            if (!el) return;
            el.style.display = visible ? '' : 'none';
        };

        const renderVehicle = (index) => {
            const vehicle = vehicles[index] || {};
            shell.classList.remove('flip-animate');
            void shell.offsetWidth;
            shell.classList.add('flip-animate');

            if (photoWrap) {
                if (vehicle.photo_url) {
                    photoWrap.innerHTML = '<img src="' + escapeHtml(vehicle.photo_url) + '" alt="My vehicle photo">';
                    setVisibility(photoNote, false);
                } else {
                    photoWrap.innerHTML = '<div class="car-placeholder"><i class="fas fa-car-side"></i><strong>No car photo yet</strong></div>';
                    setVisibility(photoNote, true);
                }
            }

            setText(plateText, vehicle.plate_no, 'RES1234');
            setText(modelValue, vehicle.vehicle_model, 'Resident Demo Car');
            setText(colorValue, vehicle.vehicle_color, 'Black');
            setText(slotValue, vehicle.slot_text, 'No slot assigned yet');
            setText(slotSub, vehicle.slot_sub, '');
            setVisibility(slotSub, !!vehicle.slot_sub);
            setText(expiryValue, vehicle.expiry_text, '-');
            setText(billingSub, vehicle.billing_text, 'No billing period yet');

            if (requestItem) {
                const hasRequest = !!vehicle.request_title;
                setVisibility(requestItem, hasRequest);
                if (hasRequest) {
                    setText(requestTitle, vehicle.request_title, '');
                    setText(requestSub, vehicle.request_sub, '');
                    requestBadge.className = 'badge ' + (vehicle.request_status_class || 'badge-gray');
                    setText(requestBadge, vehicle.request_status_text, 'pending');
                }
            }

            if (currentIndexEl) {
                currentIndexEl.textContent = String(index + 1);
            }
        };

        if (nextBtn && vehicles.length > 1) {
            nextBtn.addEventListener('click', function () {
                currentIndex = (currentIndex + 1) % vehicles.length;
                renderVehicle(currentIndex);
            });
        }

        renderVehicle(0);
    });
</script>


<style id="resident-vehicle-arrow-right-pretty-final">
    .vehicle-showcase-shell.has-multiple {
        position: relative !important;
        padding-right: 34px !important;
        padding-bottom: 0 !important;
    }

    .vehicle-showcase-shell.has-multiple::before {
        top: 18px !important;
        right: 18px !important;
        bottom: 18px !important;
        width: 88% !important;
        transform: translateX(12px) scale(.98) !important;
        opacity: .85 !important;
    }

    .vehicle-showcase-shell.has-multiple::after {
        top: 34px !important;
        right: 4px !important;
        bottom: 34px !important;
        width: 84% !important;
        transform: translateX(24px) scale(.96) !important;
        opacity: .55 !important;
    }

    .showcase-page-btn.showcase-next-btn,
    #showcaseNextBtn {
        position: absolute !important;
        left: auto !important;
        top: 50% !important;
        right: -24px !important;
        bottom: auto !important;
        transform: translateY(-50%) !important;
        width: 54px !important;
        height: 54px !important;
        min-width: 54px !important;
        border: 3px solid rgba(255,255,255,.95) !important;
        border-radius: 999px !important;
        background: linear-gradient(135deg, #38bdf8 0%, #2563eb 70%, #1d4ed8 100%) !important;
        color: #ffffff !important;
        box-shadow:
            0 20px 42px rgba(37,99,235,.30),
            0 0 0 8px rgba(239,246,255,.72) !important;
        z-index: 30 !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        cursor: pointer !important;
        transition: transform .18s ease, box-shadow .18s ease, filter .18s ease !important;
    }

    .showcase-page-btn.showcase-next-btn:hover,
    #showcaseNextBtn:hover {
        transform: translateY(-50%) translateX(3px) !important;
        filter: brightness(1.04) !important;
        box-shadow:
            0 24px 48px rgba(37,99,235,.38),
            0 0 0 8px rgba(239,246,255,.82) !important;
    }

    .showcase-page-btn.showcase-next-btn i,
    #showcaseNextBtn i {
        font-size: 1.05rem !important;
        line-height: 1 !important;
    }

    .showcase-page-indicator {
        top: 14px !important;
        right: 16px !important;
        z-index: 12 !important;
        background: rgba(255,255,255,.96) !important;
        box-shadow: 0 10px 22px rgba(37,99,235,.12) !important;
    }

    .showcase-next-copy {
        display: none !important;
    }

    @media (max-width: 1100px) {
        .vehicle-showcase-shell.has-multiple {
            padding-right: 28px !important;
        }

        .showcase-page-btn.showcase-next-btn,
        #showcaseNextBtn {
            right: -20px !important;
            top: 50% !important;
            bottom: auto !important;
            transform: translateY(-50%) !important;
            width: 50px !important;
            height: 50px !important;
            min-width: 50px !important;
        }

        .showcase-page-btn.showcase-next-btn:hover,
        #showcaseNextBtn:hover {
            transform: translateY(-50%) translateX(2px) !important;
        }
    }

    @media (max-width: 640px) {
        .vehicle-showcase-shell.has-multiple {
            padding-right: 0 !important;
            padding-bottom: 62px !important;
        }

        .showcase-page-btn.showcase-next-btn,
        #showcaseNextBtn {
            right: 18px !important;
            top: auto !important;
            bottom: 18px !important;
            transform: none !important;
        }

        .showcase-page-btn.showcase-next-btn:hover,
        #showcaseNextBtn:hover {
            transform: translateX(2px) !important;
        }
    }
</style>

<?php require_once __DIR__ . '/resident_notification_popup.php'; ?>
</body>
</html>
