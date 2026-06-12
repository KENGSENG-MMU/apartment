<?php
/* NEW FILE: admin_parking_(V)manage.php - red sidebar version, no blue navbar */
require_once '../core/security.php';
require_login(['admin', 'superadmin']);

$pdo = db();

$message = $_SESSION['flash_success'] ?? '';
$error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

function slot_e_text($value): string {
    return ($value !== null && $value !== '') ? (string)$value : '-';
}

function has_column_slots(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.COLUMNS\n            WHERE TABLE_SCHEMA = DATABASE()\n            AND TABLE_NAME = ?\n            AND COLUMN_NAME = ?\n        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function safe_count_slots(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function safe_rows_slots(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function clean_slot_text($value): string {
    $value = strtoupper(trim((string)$value));
    return preg_replace('/[^A-Z0-9\- ]/', '', $value);
}

function slot_status_class($status): string {
    return match ($status) {
        'available' => 'status-active',
        'reserved' => 'status-reserved',
        'occupied' => 'status-occupied',
        'overstay' => 'status-overstay',
        'maintenance' => 'status-maintenance',
        default => 'status-inactive'
    };
}

function slot_status_label($status): string {
    return match ($status) {
        'available' => 'Active',
        'occupied' => 'Occupied',
        'maintenance' => 'Maintenance',
        default => ucfirst((string)$status)
    };
}

function slot_status_full_label($status): string {
    return match ($status) {
        'available' => 'Available',
        'reserved' => 'Reserved',
        'occupied' => 'Occupied',
        'overstay' => 'Overstay',
        'maintenance' => 'Maintenance',
        default => ucfirst((string)$status)
    };
}

function slot_is_overstay(array $slot): bool {
    $status = (string)($slot['status'] ?? 'available');
    $bookingStatus = (string)($slot['booking_status'] ?? '');
    $endTime = $slot['end_time'] ?? null;

    if ($status === 'maintenance') {
        return false;
    }

    if ($bookingStatus !== 'checked_in' || !$endTime) {
        return false;
    }

    $endTimestamp = strtotime((string)$endTime);
    return $endTimestamp !== false && $endTimestamp < time();
}

function slot_overstay_text($endTime): string {
    if (!$endTime) {
        return '-';
    }

    $endTimestamp = strtotime((string)$endTime);
    if ($endTimestamp === false || $endTimestamp >= time()) {
        return '-';
    }

    $seconds = time() - $endTimestamp;
    $days = intdiv($seconds, 86400);
    $seconds %= 86400;
    $hours = intdiv($seconds, 3600);
    $seconds %= 3600;
    $minutes = max(1, intdiv($seconds, 60));

    if ($days > 0) {
        return $days . 'd ' . $hours . 'h overdue';
    }

    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm overdue';
    }

    return $minutes . 'm overdue';
}

function slot_display_status(array $slot): string {
    $status = (string)($slot['status'] ?? 'available');
    $bookingStatus = (string)($slot['booking_status'] ?? '');

    if ($status === 'maintenance') {
        return 'maintenance';
    }

    if (slot_is_overstay($slot)) {
        return 'overstay';
    }

    if ($status === 'occupied' || $bookingStatus === 'checked_in') {
        return 'occupied';
    }

    return 'available';
}

function slot_display_label(string $displayStatus): string {
    return match ($displayStatus) {
        'available' => 'Available',
        'reserved' => 'Reserved',
        'occupied' => 'Car Inside',
        'overstay' => 'Overstay',
        'maintenance' => 'Maintenance',
        default => ucfirst($displayStatus)
    };
}

function fmt_slot_dt($value): string {
    if (!$value) {
        return '-';
    }

    try {
        return date('d M Y, g:i A', strtotime((string)$value));
    } catch (Throwable $e) {
        return (string)$value;
    }
}

$hasUpdatedAt = has_column_slots($pdo, 'parking_slots', 'updated_at');
$hasSlotApartmentId = has_column_slots($pdo, 'parking_slots', 'apartment_id');
$hasUserApartmentId = has_column_slots($pdo, 'users', 'apartment_id');

$currentUserId = (int)($_SESSION['uid'] ?? 0);
$currentRole = $_SESSION['role'] ?? 'admin';
$currentEmail = $_SESSION['email'] ?? 'admin@apt.com';
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

function slot_scope_sql(string $alias, string $currentRole, $currentApartmentId, bool $hasSlotApartmentId): array {
    if ($currentRole === 'superadmin' || !$hasSlotApartmentId) {
        return ['', []];
    }

    if (empty($currentApartmentId)) {
        return [' AND 1 = 0 ', []];
    }

    return [" AND {$alias}.apartment_id = ? ", [(int)$currentApartmentId]];
}

function fetch_slot_for_action(PDO $pdo, int $slotId, string $currentRole, $currentApartmentId, bool $hasSlotApartmentId): array {
    [$scopeSql, $scopeParams] = slot_scope_sql('ps', $currentRole, $currentApartmentId, $hasSlotApartmentId);

    $stmt = $pdo->prepare("\n        SELECT ps.*\n        FROM parking_slots ps\n        WHERE ps.id = ?\n        AND ps.slot_type = 'Visitor'\n        {$scopeSql}\n        LIMIT 1\n    ");
    $stmt->execute(array_merge([$slotId], $scopeParams));
    $slot = $stmt->fetch();

    if (!$slot) {
        throw new Exception('Visitor parking slot not found or not under your apartment.');
    }

    return $slot;
}

function visitor_slot_load_phpmailer(): bool {
    if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        return true;
    }

    $autoloadFiles = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php'
    ];

    foreach ($autoloadFiles as $file) {
        if (file_exists($file)) {
            require_once $file;
            break;
        }
    }

    if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        return true;
    }

    $manualFiles = [
        __DIR__ . '/../vendor/PHPMailer/PHPMailer/src/Exception.php',
        __DIR__ . '/../vendor/PHPMailer/PHPMailer/src/PHPMailer.php',
        __DIR__ . '/../vendor/PHPMailer/PHPMailer/src/SMTP.php',
        __DIR__ . '/../PHPMailer/src/Exception.php',
        __DIR__ . '/../PHPMailer/src/PHPMailer.php',
        __DIR__ . '/../PHPMailer/src/SMTP.php'
    ];

    for ($i = 0; $i < count($manualFiles); $i += 3) {
        if (
            file_exists($manualFiles[$i]) &&
            file_exists($manualFiles[$i + 1]) &&
            file_exists($manualFiles[$i + 2])
        ) {
            require_once $manualFiles[$i];
            require_once $manualFiles[$i + 1];
            require_once $manualFiles[$i + 2];
            break;
        }
    }

    return class_exists('\\PHPMailer\\PHPMailer\\PHPMailer');
}

function visitor_slot_send_overstay_email(array $details, string $apartmentName, ?string &$mailError = null): bool {
    $mailError = null;

    $toEmail = trim((string)($details['visitor_email'] ?? ''));
    $toName = trim((string)($details['visitor_name'] ?? 'Visitor'));

    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $mailError = 'Visitor email is empty or invalid.';
        return false;
    }

    $visitorName = $toName !== '' ? $toName : 'Visitor';
    $plate = slot_e_text($details['plate_no'] ?? '-');
    $slot = trim((string)(($details['block_name'] ?? '') . ' / ' . ($details['slot_no'] ?? '')));
    if ($slot === '/' || $slot === '') {
        $slot = '-';
    }

    $expectedExit = fmt_slot_dt($details['end_time'] ?? null);
    $overstayText = slot_overstay_text($details['end_time'] ?? null);
    $apartmentName = trim($apartmentName) !== '' ? $apartmentName : 'the apartment';

    $subject = 'SmartVMS Overstay Parking Reminder - ' . $plate;

    $plainText =
        "SmartVMS Overstay Parking Reminder\n\n" .
        "Hello {$visitorName},\n\n" .
        "Our record shows that your visitor vehicle is still inside {$apartmentName}.\n" .
        "Plate Number: {$plate}\n" .
        "Visitor Parking Slot: {$slot}\n" .
        "Expected Exit Time: {$expectedExit}\n" .
        "Overstay Duration: {$overstayText}\n\n" .
        "Please proceed to the guard post or exit the visitor parking area as soon as possible.\n\n" .
        "Thank you.\nSmartVMS";

    $safeVisitor = htmlspecialchars($visitorName, ENT_QUOTES, 'UTF-8');
    $safeApartment = htmlspecialchars($apartmentName, ENT_QUOTES, 'UTF-8');
    $safePlate = htmlspecialchars($plate, ENT_QUOTES, 'UTF-8');
    $safeSlot = htmlspecialchars($slot, ENT_QUOTES, 'UTF-8');
    $safeExpectedExit = htmlspecialchars($expectedExit, ENT_QUOTES, 'UTF-8');
    $safeOverstay = htmlspecialchars($overstayText, ENT_QUOTES, 'UTF-8');

    $html = "
        <div style='margin:0;padding:0;background:#f4f6fb;font-family:Arial,sans-serif;'>
            <div style='max-width:620px;margin:0 auto;padding:26px 16px;'>
                <div style='background:#ffffff;border-radius:18px;border:1px solid #e5e7eb;overflow:hidden;'>
                    <div style='padding:18px 22px;background:#fff1f2;border-bottom:1px solid #fecaca;'>
                        <div style='color:#dc2626;font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;'>SmartVMS Reminder</div>
                        <h2 style='margin:6px 0 0;color:#111827;font-size:22px;'>Visitor Parking Overstay</h2>
                    </div>
                    <div style='padding:22px;'>
                        <p style='margin:0 0 14px;color:#111827;font-size:15px;'>Hello <strong>{$safeVisitor}</strong>,</p>
                        <p style='margin:0 0 18px;color:#334155;font-size:14px;line-height:1.6;'>
                            Our record shows that your visitor vehicle is still inside <strong>{$safeApartment}</strong>.
                            Please proceed to the guard post or exit the visitor parking area as soon as possible.
                        </p>
                        <table style='width:100%;border-collapse:collapse;font-size:14px;'>
                            <tr><td style='padding:10px;border-top:1px solid #e5e7eb;color:#64748b;'>Plate Number</td><td style='padding:10px;border-top:1px solid #e5e7eb;font-weight:700;color:#111827;'>{$safePlate}</td></tr>
                            <tr><td style='padding:10px;border-top:1px solid #e5e7eb;color:#64748b;'>Parking Slot</td><td style='padding:10px;border-top:1px solid #e5e7eb;font-weight:700;color:#111827;'>{$safeSlot}</td></tr>
                            <tr><td style='padding:10px;border-top:1px solid #e5e7eb;color:#64748b;'>Expected Exit Time</td><td style='padding:10px;border-top:1px solid #e5e7eb;font-weight:700;color:#111827;'>{$safeExpectedExit}</td></tr>
                            <tr><td style='padding:10px;border-top:1px solid #e5e7eb;color:#64748b;'>Overstay Duration</td><td style='padding:10px;border-top:1px solid #e5e7eb;font-weight:800;color:#dc2626;'>{$safeOverstay}</td></tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    ";

    $mailConfig = __DIR__ . '/../core/mail_config.php';
    if (file_exists($mailConfig)) {
        require_once $mailConfig;
    }

    if (
        defined('SVMS_SMTP_HOST') &&
        defined('SVMS_SMTP_USERNAME') &&
        defined('SVMS_SMTP_PASSWORD') &&
        defined('SVMS_SMTP_FROM_EMAIL') &&
        visitor_slot_load_phpmailer()
    ) {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = SVMS_SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SVMS_SMTP_USERNAME;
            $mail->Password = SVMS_SMTP_PASSWORD;
            $mail->Port = defined('SVMS_SMTP_PORT') ? (int)SVMS_SMTP_PORT : 587;

            $secure = defined('SVMS_SMTP_SECURE') ? strtolower((string)SVMS_SMTP_SECURE) : 'tls';
            if ($secure === 'ssl') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }

            $fromName = defined('SVMS_SMTP_FROM_NAME') ? SVMS_SMTP_FROM_NAME : 'SmartVMS';
            $mail->setFrom(SVMS_SMTP_FROM_EMAIL, $fromName);
            $mail->addAddress($toEmail, $visitorName);
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = $plainText;
            $mail->send();

            return true;
        } catch (Throwable $e) {
            $mailError = $e->getMessage();
            return false;
        }
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: SmartVMS <no-reply@smartvms.local>'
    ];

    $sent = @mail($toEmail, $subject, $plainText, implode("\r\n", $headers));
    if (!$sent) {
        $mailError = 'SMTP is not configured and PHP mail() failed. Please check core/mail_config.php.';
        return false;
    }

    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'generate_visitor_slots') {
                $blockName = clean_slot_text($_POST['block_name'] ?? 'VISITOR ZONE');
                $prefix = clean_slot_text($_POST['slot_prefix'] ?? 'V');
                $startNo = (int)($_POST['start_no'] ?? 1);
                $endNo = (int)($_POST['end_no'] ?? 20);

                if ($blockName === '') {
                    throw new Exception('Please enter parking block name.');
                }
                if ($prefix === '') {
                    throw new Exception('Please enter slot prefix.');
                }
                if ($startNo <= 0 || $endNo < $startNo || $endNo > 999) {
                    throw new Exception('Invalid slot number range.');
                }
                if ($currentRole !== 'superadmin' && $hasSlotApartmentId && empty($currentApartmentId)) {
                    throw new Exception('This admin account is not assigned to any apartment.');
                }

                $created = 0;
                $skipped = 0;

                for ($i = $startNo; $i <= $endNo; $i++) {
                    $slotNo = $prefix . str_pad((string)$i, 3, '0', STR_PAD_LEFT);

                    $checkSql = "SELECT id FROM parking_slots WHERE block_name = ? AND slot_no = ? AND slot_type = 'Visitor'";
                    $checkParams = [$blockName, $slotNo];

                    if ($hasSlotApartmentId) {
                        if ($currentRole === 'superadmin' && empty($currentApartmentId)) {
                            $checkSql .= " AND apartment_id IS NULL";
                        } else {
                            $checkSql .= " AND apartment_id = ?";
                            $checkParams[] = (int)$currentApartmentId;
                        }
                    }

                    $checkSql .= " LIMIT 1";
                    $check = $pdo->prepare($checkSql);
                    $check->execute($checkParams);

                    if ($check->fetch()) {
                        $skipped++;
                        continue;
                    }

                    if ($hasSlotApartmentId) {
                        $stmt = $pdo->prepare("INSERT INTO parking_slots (apartment_id, block_name, slot_no, slot_type, status, created_at) VALUES (?, ?, ?, 'Visitor', 'available', NOW())");
                        $stmt->execute([$currentApartmentId ?: null, $blockName, $slotNo]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO parking_slots (block_name, slot_no, slot_type, status, created_at) VALUES (?, ?, 'Visitor', 'available', NOW())");
                        $stmt->execute([$blockName, $slotNo]);
                    }

                    $created++;
                }

                if (function_exists('log_audit')) {
                    log_audit('VISITOR_SLOTS_GENERATED', 'Admin generated visitor parking slots. Block: ' . $blockName . ', Created: ' . $created . ', Skipped: ' . $skipped);
                }

                $message = 'Visitor slots generated. Created: ' . $created . ', skipped existing: ' . $skipped . '.';
            } elseif ($action === 'add_single_slot') {
                $blockName = clean_slot_text($_POST['block_name'] ?? '');
                $slotNo = clean_slot_text($_POST['slot_no'] ?? '');
                $status = $_POST['status'] ?? 'available';

                if ($blockName === '' || $slotNo === '') {
                    throw new Exception('Please enter block name and slot number.');
                }
                if (!in_array($status, ['available', 'occupied', 'maintenance'], true)) {
                    throw new Exception('Invalid slot status.');
                }
                if ($currentRole !== 'superadmin' && $hasSlotApartmentId && empty($currentApartmentId)) {
                    throw new Exception('This admin account is not assigned to any apartment.');
                }

                $checkSql = "SELECT id FROM parking_slots WHERE block_name = ? AND slot_no = ? AND slot_type = 'Visitor'";
                $checkParams = [$blockName, $slotNo];

                if ($hasSlotApartmentId) {
                    if ($currentRole === 'superadmin' && empty($currentApartmentId)) {
                        $checkSql .= " AND apartment_id IS NULL";
                    } else {
                        $checkSql .= " AND apartment_id = ?";
                        $checkParams[] = (int)$currentApartmentId;
                    }
                }

                $checkSql .= " LIMIT 1";
                $check = $pdo->prepare($checkSql);
                $check->execute($checkParams);

                if ($check->fetch()) {
                    throw new Exception('This visitor parking slot already exists.');
                }

                if ($hasSlotApartmentId) {
                    $stmt = $pdo->prepare("INSERT INTO parking_slots (apartment_id, block_name, slot_no, slot_type, status, created_at) VALUES (?, ?, ?, 'Visitor', ?, NOW())");
                    $stmt->execute([$currentApartmentId ?: null, $blockName, $slotNo, $status]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO parking_slots (block_name, slot_no, slot_type, status, created_at) VALUES (?, ?, 'Visitor', ?, NOW())");
                    $stmt->execute([$blockName, $slotNo, $status]);
                }

                if (function_exists('log_audit')) {
                    log_audit('VISITOR_SLOT_CREATED', 'Admin created visitor parking slot: ' . $blockName . ' ' . $slotNo);
                }

                $message = 'Visitor parking slot added successfully.';
            } elseif ($action === 'send_overstay_reminder') {
                $slotId = (int)($_POST['slot_id'] ?? 0);

                if ($slotId <= 0) {
                    throw new Exception('Invalid slot selected.');
                }

                $slot = fetch_slot_for_action($pdo, $slotId, $currentRole, $currentApartmentId, $hasSlotApartmentId);
                [$reminderScopeSql, $reminderScopeParams] = slot_scope_sql('ps', $currentRole, $currentApartmentId, $hasSlotApartmentId);

                $stmt = $pdo->prepare("
                    SELECT
                        b.id AS booking_id,
                        b.visitor_name,
                        b.visitor_email,
                        b.plate_no,
                        b.status AS booking_status,
                        b.start_time,
                        b.end_time,
                        ps.block_name,
                        ps.slot_no,
                        ps.status AS slot_status,
                        res.full_name AS resident_name,
                        res.email AS resident_email
                    FROM bookings b
                    INNER JOIN parking_slots ps
                        ON ps.id = b.slot_id
                        AND ps.slot_type = 'Visitor'
                    LEFT JOIN users res
                        ON res.id = b.resident_id
                    WHERE b.slot_id = ?
                    AND b.status = 'checked_in'
                    AND b.end_time IS NOT NULL
                    AND b.end_time < NOW()
                    {$reminderScopeSql}
                    ORDER BY b.id DESC
                    LIMIT 1
                ");
                $stmt->execute(array_merge([$slotId], $reminderScopeParams));
                $reminderBooking = $stmt->fetch();

                if (!$reminderBooking) {
                    throw new Exception('This slot is not currently overstay, so reminder email cannot be sent.');
                }

                $mailError = null;
                $sent = visitor_slot_send_overstay_email($reminderBooking, $currentApartmentName, $mailError);

                if (!$sent) {
                    throw new Exception('Reminder email failed: ' . ($mailError ?: 'Unknown mail error.'));
                }

                if (function_exists('log_audit')) {
                    log_audit('VISITOR_OVERSTAY_REMINDER_SENT', 'Admin sent overstay reminder email for visitor plate ' . ($reminderBooking['plate_no'] ?? '-') . ' at slot ' . ($slot['block_name'] ?? '-') . ' ' . ($slot['slot_no'] ?? '-'));
                }

                $message = 'Overstay reminder email sent to ' . ($reminderBooking['visitor_email'] ?? 'visitor') . '.';
            } elseif ($action === 'update_status') {
                $slotId = (int)($_POST['slot_id'] ?? 0);
                $newStatus = $_POST['new_status'] ?? '';

                if ($slotId <= 0) {
                    throw new Exception('Invalid slot selected.');
                }
                if (!in_array($newStatus, ['available', 'reserved', 'occupied', 'maintenance'], true)) {
                    throw new Exception('Invalid slot status.');
                }

                $slot = fetch_slot_for_action($pdo, $slotId, $currentRole, $currentApartmentId, $hasSlotApartmentId);

                $activeBookingCount = safe_count_slots($pdo, "SELECT COUNT(*) FROM bookings WHERE slot_id = ? AND status IN ('allocated', 'approved', 'waiting', 'checked_in')", [$slotId]);

                if ($activeBookingCount > 0 && $newStatus === 'available') {
                    throw new Exception('Cannot set this slot to available because it is used by an active booking.');
                }

                $sql = "UPDATE parking_slots SET status = ?";
                $params = [$newStatus];

                if ($hasUpdatedAt) {
                    $sql .= ", updated_at = NOW()";
                }

                $sql .= " WHERE id = ?";
                $params[] = $slotId;

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                if (function_exists('log_audit')) {
                    log_audit('VISITOR_SLOT_STATUS_UPDATED', 'Admin changed visitor slot ' . $slot['block_name'] . ' ' . $slot['slot_no'] . ' to ' . $newStatus);
                }

                $message = 'Visitor parking slot status updated successfully.';
            } elseif ($action === 'update_slot_details') {
                $slotId = (int)($_POST['slot_id'] ?? 0);
                $newBlockName = clean_slot_text($_POST['block_name'] ?? '');
                $newSlotNo = clean_slot_text($_POST['slot_no'] ?? '');
                $newStatus = $_POST['new_status'] ?? 'available';

                if ($slotId <= 0) {
                    throw new Exception('Invalid slot selected.');
                }
                if ($newBlockName === '' || $newSlotNo === '') {
                    throw new Exception('Please enter parking block and parking name.');
                }
                if (!in_array($newStatus, ['available', 'occupied', 'maintenance'], true)) {
                    throw new Exception('Invalid slot status.');
                }

                $slot = fetch_slot_for_action($pdo, $slotId, $currentRole, $currentApartmentId, $hasSlotApartmentId);

                $checkSql = "SELECT id FROM parking_slots WHERE block_name = ? AND slot_no = ? AND slot_type = 'Visitor' AND id <> ?";
                $checkParams = [$newBlockName, $newSlotNo, $slotId];

                if ($hasSlotApartmentId) {
                    if ($currentRole === 'superadmin' && empty($currentApartmentId)) {
                        $checkSql .= " AND apartment_id IS NULL";
                    } else {
                        $checkSql .= " AND apartment_id = ?";
                        $checkParams[] = (int)$currentApartmentId;
                    }
                }

                $checkSql .= " LIMIT 1";
                $check = $pdo->prepare($checkSql);
                $check->execute($checkParams);

                if ($check->fetch()) {
                    throw new Exception('This parking name already exists in this apartment.');
                }

                $sql = "UPDATE parking_slots SET block_name = ?, slot_no = ?, status = ?";
                $params = [$newBlockName, $newSlotNo, $newStatus];

                if ($hasUpdatedAt) {
                    $sql .= ", updated_at = NOW()";
                }

                $sql .= " WHERE id = ?";
                $params[] = $slotId;

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                if (function_exists('log_audit')) {
                    log_audit('VISITOR_SLOT_RENAMED', 'Admin updated visitor slot from ' . $slot['block_name'] . ' ' . $slot['slot_no'] . ' to ' . $newBlockName . ' ' . $newSlotNo);
                }

                $message = 'Visitor parking slot updated successfully.';
            } elseif ($action === 'bulk_rename_slots') {
                $targetBlockName = clean_slot_text($_POST['block_name'] ?? '');
                $prefix = clean_slot_text($_POST['slot_prefix'] ?? '');

                if ($targetBlockName === '') {
                    throw new Exception('Please select one parking block first.');
                }
                if ($prefix === '') {
                    throw new Exception('Please enter the new parking prefix.');
                }

                if ($currentRole !== 'superadmin' && $hasSlotApartmentId && empty($currentApartmentId)) {
                    throw new Exception('This admin account is not assigned to any apartment.');
                }

                [$scopeSql, $scopeParams] = slot_scope_sql('ps', $currentRole, $currentApartmentId, $hasSlotApartmentId);

                $slotListSql = "
                    SELECT ps.id
                    FROM parking_slots ps
                    WHERE ps.slot_type = 'Visitor'
                    AND ps.block_name = ?
                    {$scopeSql}
                    ORDER BY ps.id ASC
                ";
                $slotListParams = array_merge([$targetBlockName], $scopeParams);
                $stmt = $pdo->prepare($slotListSql);
                $stmt->execute($slotListParams);
                $renameSlots = $stmt->fetchAll();

                if (!$renameSlots) {
                    throw new Exception('No visitor parking slots found in this block.');
                }

                $pdo->beginTransaction();

                $updateSql = "UPDATE parking_slots SET slot_no = ?";
                if ($hasUpdatedAt) {
                    $updateSql .= ", updated_at = NOW()";
                }
                $updateSql .= " WHERE id = ?";

                $updateStmt = $pdo->prepare($updateSql);
                $counter = 1;

                foreach ($renameSlots as $renameSlot) {
                    $newSlotNo = $prefix . $counter;
                    $updateStmt->execute([$newSlotNo, (int)$renameSlot['id']]);
                    $counter++;
                }

                $pdo->commit();

                if (function_exists('log_audit')) {
                    log_audit('VISITOR_SLOTS_BULK_RENAMED', 'Admin bulk renamed visitor slots in ' . $targetBlockName . ' using prefix ' . $prefix);
                }

                $message = 'Parking names updated successfully. ' . count($renameSlots) . ' slots renamed to ' . $prefix . '1, ' . $prefix . '2, ...';
            } elseif ($action === 'delete_slot') {
                $slotId = (int)($_POST['slot_id'] ?? 0);

                if ($slotId <= 0) {
                    throw new Exception('Invalid slot selected.');
                }

                $slot = fetch_slot_for_action($pdo, $slotId, $currentRole, $currentApartmentId, $hasSlotApartmentId);

                $linkedBookingCount = safe_count_slots($pdo, "SELECT COUNT(*) FROM bookings WHERE slot_id = ?", [$slotId]);

                if ($linkedBookingCount > 0) {
                    throw new Exception('Cannot delete this slot because it has booking records. Set it to maintenance instead.');
                }

                $pdo->prepare("DELETE FROM parking_slots WHERE id = ?")->execute([$slotId]);

                if (function_exists('log_audit')) {
                    log_audit('VISITOR_SLOT_DELETED', 'Admin deleted visitor parking slot: ' . $slot['block_name'] . ' ' . $slot['slot_no']);
                }

                $message = 'Visitor parking slot deleted successfully.';
            } else {
                throw new Exception('Invalid action.');
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    if ($message !== '') {
        $_SESSION['flash_success'] = $message;
    }
    if ($error !== '') {
        $_SESSION['flash_error'] = $error;
    }

    header('Location: ' . basename($_SERVER['PHP_SELF']));
    exit;
}

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$fromPage = strtolower(trim((string)($_GET['from'] ?? '')));
$showGateLogsBackButton = in_array($fromPage, ['gate_logs', 'guard_logs', 'gate_log'], true);
$gateLogsBackUrl = 'guard_logs.php';

if (!in_array($statusFilter, ['', 'available', 'occupied', 'overstay', 'maintenance'], true)) {
    $statusFilter = '';
}

[$scopeSql, $scopeParams] = slot_scope_sql('ps', $currentRole, $currentApartmentId, $hasSlotApartmentId);
$where = "WHERE ps.slot_type = 'Visitor' {$scopeSql}";
$params = $scopeParams;

if ($search !== '') {
    $where .= " AND (ps.block_name LIKE ? OR ps.slot_no LIKE ? OR b.visitor_name LIKE ? OR b.plate_no LIKE ?)";
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term);
}

if ($statusFilter !== '') {
    if ($statusFilter === 'available') {
        $where .= " AND ps.status = 'available' AND b.id IS NULL";
    } elseif ($statusFilter === 'reserved') {
        $where .= " AND (ps.status = 'reserved' OR b.status IN ('allocated', 'approved', 'waiting'))";
    } elseif ($statusFilter === 'occupied') {
        $where .= " AND (ps.status = 'occupied' OR b.status = 'checked_in')";
    } elseif ($statusFilter === 'overstay') {
        $where .= " AND b.status = 'checked_in' AND b.end_time IS NOT NULL AND b.end_time < NOW()";
    } elseif ($statusFilter === 'maintenance') {
        $where .= " AND ps.status = 'maintenance'";
    }
}

$slots = safe_rows_slots($pdo, "
    SELECT
        ps.*,
        b.id AS booking_id,
        b.visitor_name,
        b.visitor_email,
        b.plate_no,
        b.status AS booking_status,
        b.start_time,
        b.end_time,
        res.email AS resident_email,
        res.full_name AS resident_name
    FROM parking_slots ps
    LEFT JOIN (
        SELECT b1.*
        FROM bookings b1
        INNER JOIN (
            SELECT slot_id, MAX(id) AS latest_booking_id
            FROM bookings
            WHERE status IN ('allocated', 'approved', 'waiting', 'checked_in')
            GROUP BY slot_id
        ) latest
            ON latest.latest_booking_id = b1.id
    ) b
        ON b.slot_id = ps.id
    LEFT JOIN users res
        ON res.id = b.resident_id
    {$where}
    ORDER BY ps.block_name ASC, ps.id ASC
    LIMIT 600
", $params);

/*
Natural parking sort:
MySQL string sorting shows R1, R10, R11 before R2.
This keeps custom names like R1, R2, R3 ... R10 in human-friendly order.
*/
usort($slots, function ($a, $b) {
    $blockCompare = strnatcasecmp((string)($a['block_name'] ?? ''), (string)($b['block_name'] ?? ''));
    if ($blockCompare !== 0) {
        return $blockCompare;
    }

    $slotCompare = strnatcasecmp((string)($a['slot_no'] ?? ''), (string)($b['slot_no'] ?? ''));
    if ($slotCompare !== 0) {
        return $slotCompare;
    }

    return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
});

function count_visitor_slot(PDO $pdo, string $currentRole, $currentApartmentId, bool $hasSlotApartmentId, string $extra = '', array $extraParams = []): int {
    [$scopeSql, $scopeParams] = slot_scope_sql('ps', $currentRole, $currentApartmentId, $hasSlotApartmentId);
    return safe_count_slots($pdo, "SELECT COUNT(*) FROM parking_slots ps WHERE ps.slot_type = 'Visitor' {$scopeSql} {$extra}", array_merge($scopeParams, $extraParams));
}

$totalVisitorSlots = count_visitor_slot($pdo, $currentRole, $currentApartmentId, $hasSlotApartmentId);
$availableVisitorSlots = count_visitor_slot($pdo, $currentRole, $currentApartmentId, $hasSlotApartmentId, "AND ps.status = 'available'");
$reservedVisitorSlots = count_visitor_slot($pdo, $currentRole, $currentApartmentId, $hasSlotApartmentId, "AND ps.status = 'reserved'");
$occupiedVisitorSlots = count_visitor_slot($pdo, $currentRole, $currentApartmentId, $hasSlotApartmentId, "AND ps.status = 'occupied'");
$maintenanceVisitorSlots = count_visitor_slot($pdo, $currentRole, $currentApartmentId, $hasSlotApartmentId, "AND ps.status = 'maintenance'");

$activeBookingSql = "\n    SELECT COUNT(DISTINCT b.id)\n    FROM bookings b\n    INNER JOIN parking_slots ps\n        ON ps.id = b.slot_id\n        AND ps.slot_type = 'Visitor'\n    WHERE b.status IN ('allocated', 'approved', 'waiting', 'checked_in')\n";
$activeBookingParams = [];

if ($currentRole !== 'superadmin' && $hasSlotApartmentId) {
    if (empty($currentApartmentId)) {
        $activeBookingSql .= " AND 1 = 0";
    } else {
        $activeBookingSql .= " AND ps.apartment_id = ?";
        $activeBookingParams[] = (int)$currentApartmentId;
    }
}

$activeBookings = safe_count_slots($pdo, $activeBookingSql, $activeBookingParams);

$profileInitial = strtoupper(substr(trim($currentEmail ?: 'A'), 0, 1));
if ($profileInitial === '') {
    $profileInitial = 'A';
}

$slotJsRows = array_map(function ($slot) {
    $displayStatus = slot_display_status($slot);

    return [
        'id' => (int)($slot['id'] ?? 0),
        'block_name' => (string)($slot['block_name'] ?? ''),
        'slot_no' => (string)($slot['slot_no'] ?? ''),
        'slot_type' => (string)($slot['slot_type'] ?? 'Visitor'),
        'status' => (string)($slot['status'] ?? 'available'),
        'status_label' => slot_status_full_label($slot['status'] ?? 'available'),
        'display_status' => $displayStatus,
        'display_label' => slot_display_label($displayStatus),
        'is_overstay' => $displayStatus === 'overstay',
        'overstay_text' => $displayStatus === 'overstay' ? slot_overstay_text($slot['end_time'] ?? null) : '-',
        'booking_id' => $slot['booking_id'] ? (int)$slot['booking_id'] : null,
        'visitor_name' => (string)slot_e_text($slot['visitor_name'] ?? null),
        'visitor_email' => (string)slot_e_text($slot['visitor_email'] ?? null),
        'plate_no' => (string)slot_e_text($slot['plate_no'] ?? null),
        'booking_status' => (string)slot_e_text($slot['booking_status'] ?? null),
        'resident_name' => (string)slot_e_text($slot['resident_name'] ?? null),
        'resident_email' => (string)slot_e_text($slot['resident_email'] ?? null),
        'start_time' => fmt_slot_dt($slot['start_time'] ?? null),
        'end_time' => fmt_slot_dt($slot['end_time'] ?? null),
        'created_at' => fmt_slot_dt($slot['created_at'] ?? null),
        'updated_at' => fmt_slot_dt($slot['updated_at'] ?? null),
    ];
}, $slots);

$displayTotalSlots = count($slotJsRows);
$displayAvailableSlots = count(array_filter($slotJsRows, fn($slot) => ($slot['display_status'] ?? '') === 'available'));
$displayReservedSlots = count(array_filter($slotJsRows, fn($slot) => ($slot['display_status'] ?? '') === 'reserved'));
$displayOccupiedSlots = count(array_filter($slotJsRows, fn($slot) => ($slot['display_status'] ?? '') === 'occupied' || ($slot['display_status'] ?? '') === 'overstay'));
$displayOverstaySlots = count(array_filter($slotJsRows, fn($slot) => ($slot['display_status'] ?? '') === 'overstay'));
$displayMaintenanceSlots = count(array_filter($slotJsRows, fn($slot) => ($slot['display_status'] ?? '') === 'maintenance'));

$firstSlot = $slotJsRows[0] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Visitor Parking Slots - <?= e(APP_NAME) ?></title>
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
            --orange: #c2410c;
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
            transition: opacity .35s ease, transform .35s ease, max-height .35s ease, margin .35s ease, padding .35s ease;
            overflow: hidden;
        }

        .alert.hide-alert {
            opacity: 0;
            transform: translateY(-6px);
            max-height: 0;
            margin: 0;
            padding-top: 0;
            padding-bottom: 0;
            border-width: 0;
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

        .users-panel {
            height: 100%;
            min-height: 0;
            display: flex;
            flex-direction: column;
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

        .icon-btn.setting {
            background: #e8fbf8;
            color: #0f766e;
            border-color: #b9ece6;
        }

        .icon-btn.setting:hover {
            background: #2ec4b6;
            color: #ffffff;
            border-color: #2ec4b6;
        }

        .top-setting-menu {
            position: relative;
            display: inline-flex;
        }

        .setting-dropdown {
            position: absolute;
            right: 0;
            top: 46px;
            width: 245px;
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: 0 18px 45px rgba(15, 23, 42, .16);
            padding: 8px;
            display: none;
            z-index: 50;
        }

        .top-setting-menu.open .setting-dropdown {
            display: grid;
            gap: 4px;
        }

        .setting-menu-item {
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
            text-align: left;
        }

        .setting-menu-item:hover {
            background: #f8fafc;
            color: var(--primary);
        }

        .setting-menu-item.danger {
            color: #b91c1c;
        }

        .setting-menu-item.danger:hover {
            background: #fee2e2;
            color: #991b1b;
        }

        .hidden-delete-form {
            display: none;
        }

        .users-table-wrap {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .users-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            min-width: 580px;
        }

        .users-table th:nth-child(1),
        .users-table td:nth-child(1) {
            width: 31%;
        }

        .users-table th:nth-child(2),
        .users-table td:nth-child(2) {
            width: 25%;
        }

        .users-table th:nth-child(3),
        .users-table td:nth-child(3) {
            width: 24%;
        }

        .users-table th:nth-child(4),
        .users-table td:nth-child(4) {
            width: 20%;
        }

        .users-table th {
            position: sticky;
            top: 0;
            background: #f8fafc;
            z-index: 3;
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
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .slot-row {
            cursor: pointer;
            transition: .18s ease;
        }

        .slot-row:hover,
        .slot-row.selected {
            background: #fff1f2;
        }

        .slot-row.selected {
            box-shadow: inset 4px 0 0 var(--primary);
        }

        .name-cell {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
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
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .name-sub {
            margin-top: 2px;
            color: var(--muted);
            font-size: .7rem;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .status-pill {
            border-radius: 999px;
            padding: 6px 9px;
            font-size: .65rem;
            font-weight: 950;
            text-transform: uppercase;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: fit-content;
        }

        .status-active {
            background: #dcfce7;
            color: #166534;
        }

        .status-reserved {
            background: #ffedd5;
            color: #c2410c;
        }

        .status-occupied,
        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-overstay {
            background: #dc2626;
            color: #ffffff;
            box-shadow: 0 10px 20px rgba(220, 38, 38, .22);
        }

        .status-maintenance {
            background: #f1f5f9;
            color: #475569;
        }

        .info-panel {
            display: flex;
            flex-direction: column;
        }

        .panel-head-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: auto;
        }


        .slot-summary-card {
            border: 1px solid #edf2f7;
            border-radius: 16px;
            padding: 14px 14px;
            margin-bottom: 14px;
            background: #ffffff;
        }

        .slot-summary-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .slot-summary-name {
            font-size: 1.35rem;
            font-weight: 950;
            color: #0f172a;
            letter-spacing: -0.04em;
            line-height: 1.05;
        }

        .slot-summary-block {
            margin-top: 4px;
            color: #64748b;
            font-size: .82rem;
            font-weight: 850;
        }

        .slot-summary-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            border-radius: 999px;
            background: #e8fbf8;
            color: #0f766e;
            font-size: .68rem;
            font-weight: 950;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .slot-summary-badge i {
            font-size: .7rem;
        }

        .slot-summary-badge.overstay {
            background: #fee2e2;
            color: #991b1b;
            box-shadow: 0 10px 20px rgba(220, 38, 38, .12);
        }

        .info-line.overstay-info-line {
            background: #fff1f2;
            border: 1px solid #fecaca;
            border-radius: 14px;
            padding: 12px 10px;
            margin: 4px 0 10px;
        }

        .info-line.overstay-info-line i,
        .info-line.overstay-info-line strong,
        .info-line.overstay-info-line span {
            color: #991b1b;
        }

        .info-line.overstay-info-line.hidden {
            display: none;
        }

        .overstay-reminder-form {
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid #f1f5f9;
        }

        .overstay-reminder-form.hidden {
            display: none;
        }

        .overstay-reminder-btn {
            width: 100%;
            height: 46px;
            border: 0;
            border-radius: 16px;
            background: linear-gradient(135deg, #dc2626, #991b1b);
            color: #ffffff;
            font-size: .86rem;
            font-weight: 950;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            box-shadow: 0 14px 28px rgba(220, 38, 38, .20);
        }

        .overstay-reminder-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 18px 34px rgba(220, 38, 38, .26);
        }

        .overstay-reminder-help {
            margin-top: 8px;
            color: #64748b;
            font-size: .72rem;
            font-weight: 750;
            line-height: 1.35;
            text-align: center;
        }

        .info-body {
            padding: 22px 18px;
            height: calc(100% - 54px);
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

        .more-dropdown {
            position: absolute;
            right: 0;
            top: 42px;
            width: 240px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: 0 18px 45px rgba(15,23,42,.16);
            padding: 8px;
            display: none;
            text-align: left;
            z-index: 20;
        }

        .header-more-menu.open .more-dropdown {
            display: grid;
            gap: 3px;
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
            font-size: 2.75rem;
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

        .info-content-area {
            flex: 1;
            min-height: 0;
            display: flex;
            flex-direction: column;
        }

        .info-view-lines {
            flex: 1;
            overflow-y: auto;
            padding-right: 4px;
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
            overflow-y: auto;
        }

        .info-panel.mode-edit .info-view-lines,
        .info-panel.mode-add .info-view-lines,
        .info-panel.mode-generate .info-view-lines,
        .info-panel.mode-bulk-rename .info-view-lines {
            display: none;
        }

        .info-panel.mode-edit .edit-section,
        .info-panel.mode-add .add-section,
        .info-panel.mode-generate .generate-section,
        .info-panel.mode-bulk-rename .bulk-rename-section {
            display: block;
        }






        .form-help {
            display: block;
            margin: -2px 0 10px;
            color: #64748b;
            font-size: .72rem;
            font-weight: 750;
            line-height: 1.35;
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
        .right-form-section select {
            width: 100%;
            min-width: 0;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 10px 11px;
            font-size: .82rem;
            font-weight: 850;
            margin-bottom: 9px;
            outline: none;
            background: white;
        }

        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
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
            margin-top: 8px;
        }

        .empty-state {
            padding: 45px 20px;
            text-align: center;
            color: var(--muted);
            font-weight: 850;
        }

        .quick-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin: 0 0 14px;
        }

        .quick-stat {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 10px;
        }

        .quick-stat .num {
            font-size: 1rem;
            font-weight: 950;
            color: #111827;
        }

        .quick-stat .lbl {
            margin-top: 2px;
            color: var(--muted);
            font-size: .58rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .05em;
        }


        .slot-map-wrap {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 14px;
        }

        .slot-map-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }

        .slot-count-note {
            color: #64748b;
            font-size: .75rem;
            font-weight: 850;
        }

        .slot-legend {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #64748b;
            font-size: .66rem;
            font-weight: 900;
            text-transform: uppercase;
        }

        .legend-dot {
            width: 11px;
            height: 11px;
            border-radius: 999px;
            background: #22c55e;
        }


        .legend-dot.occupied {
            background: #dc2626;
        }

        .legend-dot.overstay {
            background: #f97316;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, .16);
        }

        .legend-dot.maintenance {
            background: #94a3b8;
        }

        .slot-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(138px, 1fr));
            gap: 12px;
        }

        .slot-card {
            min-height: 122px;
            border: 1px solid #dcfce7;
            background: linear-gradient(145deg, #f0fdf4, #ffffff);
            border-radius: 18px;
            padding: 13px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: .18s ease;
            box-shadow: 0 10px 22px rgba(15, 23, 42, .05);
            text-align: left;
            color: #111827;
        }

        .slot-card::after {
            content: '';
            position: absolute;
            right: -22px;
            top: -22px;
            width: 64px;
            height: 64px;
            border-radius: 999px;
            background: rgba(22, 163, 74, .12);
        }

        .slot-card:hover,
        .slot-card.selected {
            transform: translateY(-2px);
            box-shadow: 0 16px 32px rgba(15, 23, 42, .1);
        }

        .slot-card.selected {
            outline: 3px solid rgba(220, 38, 38, .18);
            border-color: var(--primary);
        }


        .slot-card.state-reserved::after {
            background: rgba(249, 115, 22, .15);
        }

        .slot-card.state-occupied {
            border-color: #dc2626;
            background:
                radial-gradient(circle at 22% 18%, rgba(255,255,255,.72), transparent 24%),
                linear-gradient(145deg, #ef4444, #991b1b);
            color: white;
            box-shadow: 0 18px 34px rgba(220, 38, 38, .22);
        }

        .slot-card.state-occupied::after {
            background: rgba(255,255,255,.14);
        }

        .slot-card.state-maintenance {
            border-color: #cbd5e1;
            background: linear-gradient(145deg, #f8fafc, #ffffff);
        }

        .slot-card.state-overstay {
            border-color: #f97316;
            background:
                radial-gradient(circle at 22% 18%, rgba(255,255,255,.72), transparent 24%),
                linear-gradient(145deg, #fb923c, #dc2626);
            color: #ffffff;
            box-shadow: 0 18px 36px rgba(249, 115, 22, .26);
            animation: overstayPulse 1.8s ease-in-out infinite;
        }

        .slot-card.state-overstay::after {
            background: rgba(255,255,255,.18);
        }

        .slot-card.state-overstay.selected {
            outline: 4px solid rgba(249, 115, 22, .28);
            border-color: #dc2626;
        }

        .slot-overstay-clock {
            position: absolute;
            top: 9px;
            right: 9px;
            width: 28px;
            height: 28px;
            border-radius: 999px;
            background: #ffffff;
            color: #dc2626;
            display: grid;
            place-items: center;
            font-size: .82rem;
            z-index: 2;
            box-shadow: 0 10px 20px rgba(127, 29, 29, .20);
        }

        @keyframes overstayPulse {
            0%, 100% {
                box-shadow: 0 18px 36px rgba(249, 115, 22, .24);
            }
            50% {
                box-shadow: 0 20px 44px rgba(220, 38, 38, .38);
            }
        }

        .slot-card.state-maintenance::after {
            background: rgba(100, 116, 139, .12);
        }

        .slot-card-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
            position: relative;
            z-index: 1;
        }

        .slot-no {
            font-size: 1rem;
            font-weight: 950;
            letter-spacing: -.03em;
        }

        .slot-block {
            margin-top: 2px;
            color: #64748b;
            font-size: .68rem;
            font-weight: 850;
        }

        .slot-card.state-occupied .slot-block,
        .slot-card.state-occupied .slot-detail,
        .slot-card.state-occupied .slot-card-status,
        .slot-card.state-overstay .slot-block,
        .slot-card.state-overstay .slot-detail,
        .slot-card.state-overstay .slot-card-status {
            color: rgba(255,255,255,.86);
        }

        .slot-car-icon {
            width: 32px;
            height: 32px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            background: rgba(22, 163, 74, .12);
            color: #16a34a;
            flex: 0 0 auto;
        }


        .slot-card.state-occupied .slot-car-icon,
        .slot-card.state-overstay .slot-car-icon {
            background: rgba(255,255,255,.18);
            color: white;
        }

        .slot-card.state-maintenance .slot-car-icon {
            background: rgba(100, 116, 139, .12);
            color: #64748b;
        }

        .slot-card-body {
            position: relative;
            z-index: 1;
        }

        .slot-card-status {
            font-size: .66rem;
            font-weight: 950;
            text-transform: uppercase;
            color: #166534;
            letter-spacing: .05em;
            margin-bottom: 5px;
        }


        .slot-card.state-maintenance .slot-card-status {
            color: #64748b;
        }

        .slot-detail {
            color: #475569;
            font-size: .68rem;
            font-weight: 850;
            line-height: 1.35;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .slot-card-footer {
            position: relative;
            z-index: 1;
            margin-top: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .slot-mini-pill {
            border-radius: 999px;
            padding: 5px 8px;
            background: rgba(22, 163, 74, .1);
            color: #166534;
            font-size: .6rem;
            font-weight: 950;
            text-transform: uppercase;
        }


        .slot-card.state-occupied .slot-mini-pill,
        .slot-card.state-overstay .slot-mini-pill {
            background: rgba(255,255,255,.18);
            color: white;
        }

        .slot-card.state-maintenance .slot-mini-pill {
            background: rgba(100, 116, 139, .12);
            color: #64748b;
        }



        /* Visitor parking boxes - slim vertical parking-slot style with teal theme */
        .block-pager {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 0 14px;
            border-bottom: 1px solid #eef2f7;
            margin-bottom: 14px;
        }

        .block-title-wrap {
            text-align: center;
            min-width: 120px;
        }

        .block-page-title {
            color: #0f172a;
            font-size: .96rem;
            font-weight: 950;
            line-height: 1.1;
            white-space: nowrap;
        }

        .block-page-count {
            margin-top: 4px;
            color: #64748b;
            font-size: .72rem;
            font-weight: 850;
        }

        .block-nav-btn {
            height: 34px;
            border: 1px solid #b9ece6;
            background: #e8fbf8;
            color: #0f766e;
            border-radius: 999px;
            padding: 0 12px;
            font-size: .72rem;
            font-weight: 950;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: .18s ease;
        }

        .block-nav-btn:hover {
            background: #2ec4b6;
            color: white;
            border-color: #2ec4b6;
        }

        .block-nav-btn:disabled {
            opacity: .38;
            cursor: not-allowed;
            background: #f8fafc;
            color: #94a3b8;
            border-color: #e5e7eb;
        }

        .slot-grid {
            display: grid;
            grid-template-columns: repeat(10, 92px);
            gap: 12px;
            justify-content: start;
        }

        .slot-card {
            width: 92px;
            min-height: 150px;
            padding: 10px 6px;
            border: 2px solid #b9ece6;
            background: #dff8f4;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: all .18s ease;
            box-shadow: none;
            color: #0f172a;
        }

        .slot-card::after {
            display: none !important;
            content: none !important;
        }

        .slot-card:hover {
            border-color: #66d9cf;
            background: #ccf4ee;
            transform: translateY(-1px);
        }

        .slot-card.selected {
            border: 2px solid #2ec4b6;
            background: #c8f2ec;
            box-shadow: 0 0 0 2px rgba(46, 196, 182, 0.14);
            outline: none;
            transform: none;
        }

        .slot-card.state-available {
            border-color: #b9ece6;
            background: #dff8f4;
            color: #0f172a;
        }

        .slot-card.state-available:hover {
            border-color: #66d9cf;
            background: #ccf4ee;
        }



        .slot-card.state-occupied {
            border-color: #dc2626;
            background: #ef4444;
            color: #ffffff;
            box-shadow: none;
        }

        .slot-card.state-occupied.selected {
            border-color: #b91c1c;
            box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.16);
        }

        .slot-card.state-maintenance {
            border-color: #cbd5e1;
            background: #e5edf5;
            color: #64748b;
        }

        .slot-card.state-maintenance.selected {
            border-color: #94a3b8;
            box-shadow: 0 0 0 2px rgba(148, 163, 184, 0.14);
        }

        .slot-card[hidden] {
            display: none !important;
        }

        .slot-code-only {
            position: relative;
            z-index: 2;
            font-size: 1.02rem;
            line-height: 1.15;
            font-weight: 900;
            letter-spacing: 0;
            text-transform: none;
        }

        .slot-card.state-occupied .slot-code-only {
            color: #ffffff;
        }

        /* Final override: make overstay slot fully orange/red, not green. */
        .slot-card.state-overstay {
            border: 3px solid #dc2626 !important;
            background:
                radial-gradient(circle at 25% 18%, rgba(255,255,255,.35), transparent 24%),
                linear-gradient(145deg, #fb923c 0%, #ef4444 48%, #b91c1c 100%) !important;
            color: #ffffff !important;
            box-shadow: 0 16px 36px rgba(220, 38, 38, .28) !important;
            animation: overstayPulse 1.8s ease-in-out infinite;
        }

        .slot-card.state-overstay:hover,
        .slot-card.state-overstay.selected {
            border-color: #991b1b !important;
            background:
                radial-gradient(circle at 25% 18%, rgba(255,255,255,.40), transparent 24%),
                linear-gradient(145deg, #f97316 0%, #dc2626 48%, #991b1b 100%) !important;
            box-shadow: 0 0 0 4px rgba(220, 38, 38, .18), 0 18px 38px rgba(220, 38, 38, .30) !important;
        }

        .slot-card.state-overstay .slot-code-only {
            color: #ffffff !important;
            text-shadow: 0 1px 2px rgba(127, 29, 29, .35);
        }

        .slot-card.state-overstay .slot-overstay-clock {
            background: #ffffff !important;
            color: #dc2626 !important;
            border: 2px solid #fecaca;
        }

        @media (max-width: 760px) {
            .block-pager {
                flex-direction: column;
                align-items: stretch;
            }

            .block-tabs {
                justify-content: flex-start;
            }

            .slot-grid {
                grid-template-columns: repeat(5, 76px);
            }

            .slot-card {
                width: 76px;
            }
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

            .side-nav,
            .form-grid-2,
            .quick-stats {
                grid-template-columns: 1fr;
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
                <div class="page-kicker">Parking Management</div>
                <h1 class="page-title">Visitor Parking Slots</h1>
                <p class="page-sub">
                    Visitor slots are shown as parking boxes. Green means empty, red means a car has entered, and grey means maintenance.
                </p>
            </div>

            <div class="top-actions">
                <?php if ($showGateLogsBackButton): ?>
                    <a href="<?= e($gateLogsBackUrl) ?>" class="top-btn">
                        <i class="fas fa-arrow-left"></i>
                        Gate Logs
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

        <section class="manage-layout" id="manageLayout">
            <div class="users-panel">
                <div class="panel-head">
                    <div class="panel-title">
                        <i class="fas fa-square-parking"></i>
                        Visitor Slot Map
                    </div>

                    <form method="GET" class="filter-form">
                        <input
                            type="text"
                            name="search"
                            placeholder="Search slot..."
                            value="<?= e($search) ?>"
                        >

                        <select name="status" title="Status">
                            <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All</option>
                            <option value="available" <?= $statusFilter === 'available' ? 'selected' : '' ?>>Available</option>
                            <option value="occupied" <?= $statusFilter === 'occupied' ? 'selected' : '' ?>>Occupied</option>
                            <option value="overstay" <?= $statusFilter === 'overstay' ? 'selected' : '' ?>>Overstay</option>
                            <option value="maintenance" <?= $statusFilter === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                        </select>

                        <button type="submit" class="icon-btn primary" title="Search">
                            <i class="fas fa-magnifying-glass"></i>
                        </button>

                        <a href="<?= e(basename($_SERVER['PHP_SELF'])) ?>" class="icon-btn" title="Reset">
                            <i class="fas fa-rotate-left"></i>
                        </a>

                        <div class="top-setting-menu" id="settingMenu">
                            <button type="button" class="icon-btn setting" id="settingBtn" title="Parking Settings">
                                <i class="fas fa-gear"></i>
                            </button>

                            <div class="setting-dropdown">
                                <button type="button" class="setting-menu-item" data-mode="add">
                                    <i class="fas fa-plus"></i>
                                    Add Visitor Slot
                                </button>
                                <button type="button" class="setting-menu-item" data-mode="bulkRename">
                                    <i class="fas fa-pen-to-square"></i>
                                    Rename Current Block
                                </button>
                                <button type="button" class="setting-menu-item danger" id="topDeleteSlotBtn">
                                    <i class="fas fa-trash"></i>
                                    Delete Selected Slot
                                </button>
                            </div>
                        </div>
                    </form>

                    <form method="POST" id="deleteSlotForm" class="hidden-delete-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_slot">
                        <input type="hidden" name="slot_id" class="selected-slot-id" value="<?= $firstSlot ? (int)$firstSlot['id'] : 0 ?>">
                    </form>
                </div>

                <?php if (!$slots): ?>
                    <div class="empty-state">
                        <i class="fas fa-square-parking" style="font-size:2rem;color:#cbd5e1;margin-bottom:10px;"></i>
                        <div>No visitor slot found.</div>
                    </div>
                <?php else: ?>
                    <div class="slot-map-wrap">
                        <div class="slot-map-top">
                            <div class="slot-count-note">
                                Showing <?= (int)$displayTotalSlots ?> visitor parking boxes.
                            </div>
                            <div class="slot-legend">
                                <span class="legend-item"><span class="legend-dot"></span>Available</span>
                                <span class="legend-item"><span class="legend-dot occupied"></span>Car Inside</span>
                                <span class="legend-item"><span class="legend-dot overstay"></span>Overstay</span>
                                <span class="legend-item"><span class="legend-dot maintenance"></span>Maintenance</span>
                            </div>
                        </div>

                        <div class="block-pager" id="blockPager">
                            <button type="button" class="block-nav-btn" id="prevBlockBtn">
                                <i class="fas fa-chevron-left"></i>
                                Previous
                            </button>

                            <div class="block-title-wrap">
                                <div class="block-page-title" id="blockPageTitle">Block A</div>
                                <div class="block-page-count" id="blockPageCount">0 slots</div>
                            </div>

                            <button type="button" class="block-nav-btn" id="nextBlockBtn">
                                Next
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>

                        <div class="slot-grid">
                            <?php foreach ($slots as $index => $slot): ?>
                                <?php
                                    $slotId = (int)($slot['id'] ?? 0);
                                    $slotNo = (string)($slot['slot_no'] ?? '-');
                                    $blockName = (string)($slot['block_name'] ?? '');
                                    $displayStatus = slot_display_status($slot);
                                    $displayLabel = slot_display_label($displayStatus);
                                    $visitorName = slot_e_text($slot['visitor_name'] ?? null);
                                    $plateNo = slot_e_text($slot['plate_no'] ?? null);
                                    $bookingStatusText = slot_e_text($slot['booking_status'] ?? null);
                                    $iconClass = $displayStatus === 'occupied' ? 'fa-car-side' : ($displayStatus === 'maintenance' ? 'fa-screwdriver-wrench' : 'fa-square-parking');
                                ?>
                                <?php $boxNo = trim($slotNo) !== '' ? trim($slotNo) : '-'; ?>
                                <button type="button" class="slot-card slot-row state-<?= e($displayStatus) ?> <?= $index === 0 ? 'selected' : '' ?>" data-slot-id="<?= $slotId ?>" data-block="<?= e($blockName) ?>" title="<?= e($slotNo) ?> - <?= e($displayLabel) ?>">
                                    <?php if ($displayStatus === 'overstay'): ?>
                                        <span class="slot-overstay-clock" title="Overstay visitor">
                                            <i class="fas fa-clock"></i>
                                        </span>
                                    <?php endif; ?>
                                    <span class="slot-code-only"><?= e($boxNo) ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <aside class="info-panel" id="infoPanel">
                <div class="panel-head">
                    <div class="panel-title">
                        <i class="fas fa-square-parking" id="rightPanelIcon"></i>
                        <span id="rightPanelTitle">Slot Information</span>
                    </div>
                    <div class="panel-head-actions">
                        <span class="status-pill <?= $firstSlot ? e(slot_status_class($firstSlot['display_status'])) : 'status-maintenance' ?>" id="infoStatusPill">
                            <?= $firstSlot ? e($firstSlot['display_label']) : 'No Slot' ?>
                        </span>
                    </div>
                </div>

                <div class="info-body">
                    <div class="quick-stats">
                        <div class="quick-stat">
                            <div class="num"><?= (int)$displayTotalSlots ?></div>
                            <div class="lbl">Total</div>
                        </div>
                        <div class="quick-stat">
                            <div class="num"><?= (int)$displayAvailableSlots ?></div>
                            <div class="lbl">Available</div>
                        </div>
                        <div class="quick-stat">
                            <div class="num"><?= (int)$displayOccupiedSlots ?></div>
                            <div class="lbl">Car In</div>
                        </div>
                    </div>

                                        <div class="header-action-area">
                        <div class="header-action-row">
                            <div class="header-more-menu" id="moreMenu">
                                <button type="button" class="header-mini-btn" id="moreBtn" title="Quick status">
                                    <i class="fas fa-ellipsis"></i>
                                </button>

                                <div class="more-dropdown">
                                    <form method="POST" class="quick-status-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="slot_id" class="selected-slot-id" value="<?= $firstSlot ? (int)$firstSlot['id'] : 0 ?>">
                                        <input type="hidden" name="new_status" value="available">
                                        <button type="submit" class="more-item">
                                            <i class="fas fa-circle-check"></i>
                                            Set Available
                                        </button>
                                    </form>
                                    <form method="POST" class="quick-status-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="slot_id" class="selected-slot-id" value="<?= $firstSlot ? (int)$firstSlot['id'] : 0 ?>">
                                        <input type="hidden" name="new_status" value="maintenance">
                                        <button type="submit" class="more-item warning">
                                            <i class="fas fa-screwdriver-wrench"></i>
                                            Set Maintenance
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

<div class="slot-summary-card">
                        <div class="slot-summary-top">
                            <div>
                                <div class="slot-summary-name" id="infoSlotNo"><?= $firstSlot ? e($firstSlot['slot_no']) : 'No Slot Selected' ?></div>
                                <div class="slot-summary-block" id="infoBlockName"><?= $firstSlot ? e($firstSlot['block_name']) : 'Please select a visitor slot.' ?></div>
                            </div>
                            <div class="slot-summary-badge <?= ($firstSlot && (($firstSlot['display_status'] ?? '') === 'overstay')) ? 'overstay' : '' ?>" id="infoStatusBadge">
                                <i class="fas <?= ($firstSlot && (($firstSlot['display_status'] ?? '') === 'overstay')) ? 'fa-clock' : 'fa-circle' ?>"></i>
                                <span id="infoCurrentStatus"><?= $firstSlot ? e($firstSlot['display_label']) : '-' ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="info-content-area">
                        <div class="info-view-lines">
                            <div class="info-line">
                                <i class="fas fa-user"></i>
                                <div>
                                    <strong>Visitor</strong><br>
                                    <span id="infoVisitor"><?= $firstSlot ? e($firstSlot['visitor_name']) : '-' ?></span>
                                </div>
                            </div>

                            <div class="info-line">
                                <i class="fas fa-house-user"></i>
                                <div>
                                    <strong>Resident / Unit Owner</strong><br>
                                    <span id="infoResident"><?= $firstSlot ? e($firstSlot['resident_name']) : '-' ?></span>
                                </div>
                            </div>

                            <div class="info-line">
                                <i class="fas fa-car"></i>
                                <div>
                                    <strong>Plate Number</strong><br>
                                    <span id="infoPlate"><?= $firstSlot ? e($firstSlot['plate_no']) : '-' ?></span>
                                </div>
                            </div>

                            <div class="info-line">
                                <i class="fas fa-hourglass-half"></i>
                                <div>
                                    <strong>Booking Time</strong><br>
                                    <span id="infoTime"><?= $firstSlot ? e($firstSlot['start_time'] . ' - ' . $firstSlot['end_time']) : '-' ?></span>
                                </div>
                            </div>

                            <div class="info-line">
                                <i class="fas fa-clock"></i>
                                <div>
                                    <strong>Expected Exit Time</strong><br>
                                    <span id="infoExitTime"><?= $firstSlot ? e($firstSlot['end_time']) : '-' ?></span>
                                </div>
                            </div>

                            <div class="info-line overstay-info-line <?= ($firstSlot && (($firstSlot['display_status'] ?? '') === 'overstay')) ? '' : 'hidden' ?>" id="infoOverstayLine">
                                <i class="fas fa-clock"></i>
                                <div>
                                    <strong>Overstay Duration</strong><br>
                                    <span id="infoOverstayTime"><?= ($firstSlot && (($firstSlot['display_status'] ?? '') === 'overstay')) ? e($firstSlot['overstay_text'] ?? '-') : '-' ?></span>
                                </div>
                            </div>
                        </div>

                        <form
                            method="POST"
                            id="overstayReminderForm"
                            class="overstay-reminder-form <?= ($firstSlot && (($firstSlot['display_status'] ?? '') === 'overstay')) ? '' : 'hidden' ?>"
                        >
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="send_overstay_reminder">
                            <input type="hidden" name="slot_id" class="selected-slot-id" value="<?= $firstSlot ? (int)$firstSlot['id'] : 0 ?>">
                            <button type="submit" class="overstay-reminder-btn">
                                <i class="fas fa-envelope"></i>
                                Send Email Reminder
                            </button>
                            <div class="overstay-reminder-help" id="overstayReminderHelp">
                                Email will be sent to the visitor email address.
                            </div>
                        </form>


                        <div class="right-form-section edit-section">
                            <div class="section-label">Edit Selected Slot</div>
                            <form method="POST" id="editSlotForm">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_slot_details">
                                <input type="hidden" name="slot_id" class="selected-slot-id" value="<?= $firstSlot ? (int)$firstSlot['id'] : 0 ?>">

                                <input type="text" name="block_name" id="editBlockName" placeholder="Block name, example: Block A or Zone 1" required>
                                <input type="text" name="slot_no" id="editSlotNo" placeholder="Parking name, example: HI1, HI2, VIP01" required>

                                <select name="new_status" id="editStatusSelect" required>
                                    <option value="available">Available</option>
                                    <option value="occupied">Car Inside</option>
                                    <option value="maintenance">Maintenance</option>
                                </select>

                                <div class="button-grid">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-floppy-disk"></i>
                                        Save Changes
                                    </button>
                                    <button type="button" class="btn btn-light" data-mode="view">
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>

                        <div class="right-form-section add-section">
                            <div class="section-label">Add One Visitor Slot</div>
                            <form method="POST">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="add_single_slot">
                                <input type="text" name="block_name" placeholder="Parking Block, example: Visitor Zone" required>
                                <input type="text" name="slot_no" placeholder="Slot Number, example: V021" required>
                                <select name="status" required>
                                    <option value="available">Available</option>
                                    <option value="occupied">Occupied</option>
                                    <option value="maintenance">Maintenance</option>
                                </select>
                                <div class="button-grid">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-plus"></i>
                                        Add Visitor Slot
                                    </button>
                                    <button type="button" class="btn btn-light" data-mode="view">
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>

                        <div class="right-form-section bulk-rename-section">
                            <div class="section-label">Rename Current Block</div>
                            <form method="POST" id="bulkRenameForm">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="bulk_rename_slots">
                                <input type="hidden" name="block_name" id="bulkBlockName" value="">

                                <input type="text" name="slot_prefix" id="bulkSlotPrefix" placeholder="New prefix, example: AV or HI" required>
                                <span class="form-help">
                                    Current block: <strong id="bulkBlockLabel">-</strong><br>
                                    Example: type <strong>HI</strong>, then this block becomes <strong>HI1, HI2, HI3...</strong>
                                </span>

                                <div class="button-grid">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-pen-to-square"></i>
                                        Rename This Block
                                    </button>
                                    <button type="button" class="btn btn-light" data-mode="view">
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>

                        <div class="right-form-section generate-section">
                            <div class="section-label">Generate Visitor Slots</div>
                            <form method="POST">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="generate_visitor_slots">
                                <input type="text" name="block_name" value="Visitor Zone" placeholder="Parking Block" required>
                                <input type="text" name="slot_prefix" value="V" placeholder="Slot Prefix" required>
                                <div class="form-grid-2">
                                    <input type="number" name="start_no" value="1" min="1" max="999" placeholder="Start" required>
                                    <input type="number" name="end_no" value="20" min="1" max="999" placeholder="End" required>
                                </div>
                                <div class="button-grid">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-wand-magic-sparkles"></i>
                                        Generate Slots
                                    </button>
                                    <button type="button" class="btn btn-light" data-mode="view">
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </aside>
        </section>
    </main>
</div>

<script>
const slotData = <?= json_encode($slotJsRows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
let selectedSlotId = slotData.length ? slotData[0].id : 0;

const profileMenu = document.getElementById('profileMenu');
const profileTrigger = document.getElementById('profileTrigger');
const moreMenu = document.getElementById('moreMenu');
const moreBtn = document.getElementById('moreBtn');
const settingMenu = document.getElementById('settingMenu');
const settingBtn = document.getElementById('settingBtn');
const topDeleteSlotBtn = document.getElementById('topDeleteSlotBtn');
const infoPanel = document.getElementById('infoPanel');
const manageLayout = document.getElementById('manageLayout');
const rightPanelTitle = document.getElementById('rightPanelTitle');
const rightPanelIcon = document.getElementById('rightPanelIcon');
const statusClasses = ['status-active', 'status-occupied', 'status-overstay', 'status-maintenance', 'status-inactive'];

function setText(id, value) {
    const el = document.getElementById(id);
    if (el) {
        el.textContent = value || '-';
    }
}

function statusClass(status) {
    if (status === 'available') return 'status-active';
    if (status === 'occupied') return 'status-occupied';
    if (status === 'overstay') return 'status-overstay';
    if (status === 'maintenance') return 'status-maintenance';
    return 'status-inactive';
}

function openInfoPanel() {
    return true;
}

function isInfoPanelOpen() {
    return true;
}

function selectSlot(slotId) {
    const slot = slotData.find(item => Number(item.id) === Number(slotId));
    if (!slot) return;

    openInfoPanel();
    selectedSlotId = slot.id;

    document.querySelectorAll('.slot-row').forEach(row => {
        row.classList.toggle('selected', Number(row.dataset.slotId) === Number(slot.id));
    });

    document.querySelectorAll('.selected-slot-id').forEach(input => {
        input.value = slot.id;
    });

    setText('infoSlotNo', slot.slot_no);
    setText('infoBlockName', slot.block_name);
    setText('infoCurrentStatus', slot.display_label);
    setText('infoVisitor', slot.visitor_name);
    setText('infoPlate', slot.plate_no);
    setText('infoResident', slot.resident_name);
    const timeText = (slot.start_time && slot.start_time !== '-') ? `${slot.start_time} - ${slot.end_time}` : '-';
    const exitText = (slot.end_time && slot.end_time !== '-') ? slot.end_time : '-';
    setText('infoTime', timeText);
    setText('infoExitTime', exitText);
    setText('infoOverstayTime', slot.overstay_text || '-');

    const overstayLine = document.getElementById('infoOverstayLine');
    if (overstayLine) {
        overstayLine.classList.toggle('hidden', slot.display_status !== 'overstay');
    }

    const overstayReminderForm = document.getElementById('overstayReminderForm');
    if (overstayReminderForm) {
        overstayReminderForm.classList.toggle('hidden', slot.display_status !== 'overstay');
    }

    const overstayReminderHelp = document.getElementById('overstayReminderHelp');
    if (overstayReminderHelp) {
        overstayReminderHelp.textContent = slot.visitor_email && slot.visitor_email !== '-'
            ? 'Email will be sent to: ' + slot.visitor_email
            : 'Visitor email is empty. Please update visitor email first.';
    }

    const infoStatusBadge = document.getElementById('infoStatusBadge');
    if (infoStatusBadge) {
        infoStatusBadge.classList.toggle('overstay', slot.display_status === 'overstay');

        const badgeIcon = infoStatusBadge.querySelector('i');
        if (badgeIcon) {
            badgeIcon.className = slot.display_status === 'overstay' ? 'fas fa-clock' : 'fas fa-circle';
        }
    }

    const statusText = document.querySelector('#infoStatusBadge span');
    if (statusText) {
        statusText.textContent = slot.display_label || '-';
    }

    const pill = document.getElementById('infoStatusPill');
    if (pill) {
        pill.classList.remove(...statusClasses);
        pill.classList.add(statusClass(slot.display_status));
        pill.textContent = slot.display_label;
    }

    const editBlockName = document.getElementById('editBlockName');
    if (editBlockName) {
        editBlockName.value = slot.block_name || '';
    }

    const editSlotNo = document.getElementById('editSlotNo');
    if (editSlotNo) {
        editSlotNo.value = slot.slot_no || '';
    }

    const editStatusSelect = document.getElementById('editStatusSelect');
    if (editStatusSelect) {
        editStatusSelect.value = slot.status;
    }

    setPanelMode('view');
    updateRightPanelHeader('view');
}

function updateRightPanelHeader(mode) {
    if (!rightPanelTitle || !rightPanelIcon) return;

    if (mode === 'add' || mode === 'bulkRename') {
        rightPanelTitle.textContent = 'Parking Settings';
        rightPanelIcon.className = 'fas fa-gear';
        return;
    }

    rightPanelTitle.textContent = 'Slot Information';
    rightPanelIcon.className = 'fas fa-square-parking';
}

function setPanelMode(mode) {
    if (!infoPanel) return;

    openInfoPanel();
    updateRightPanelHeader(mode);
    infoPanel.classList.remove('mode-edit', 'mode-add', 'mode-generate', 'mode-bulk-rename');

    if (mode === 'edit') {
        infoPanel.classList.add('mode-edit');
    }
    if (mode === 'add') {
        infoPanel.classList.add('mode-add');
    }
    if (mode === 'generate') {
        infoPanel.classList.add('mode-generate');
    }
    if (mode === 'bulkRename') {
        infoPanel.classList.add('mode-bulk-rename');
    }

    if (moreMenu) {
        moreMenu.classList.remove('open');
    }
    if (settingMenu) {
        settingMenu.classList.remove('open');
    }
}

document.querySelectorAll('.slot-row').forEach(row => {
    row.addEventListener('click', () => selectSlot(row.dataset.slotId));
});

document.querySelectorAll('.side-link.parent').forEach(button => {
    button.addEventListener('click', () => {
        const parent = button.closest('.side-parent');
        if (parent) parent.classList.toggle('open');
    });
});

if (profileTrigger && profileMenu) {
    profileTrigger.addEventListener('click', (event) => {
        event.stopPropagation();
        profileMenu.classList.toggle('open');
    });
}


if (moreBtn && moreMenu) {
    moreBtn.addEventListener('click', (event) => {
        event.stopPropagation();
        moreMenu.classList.toggle('open');
        if (settingMenu) {
            settingMenu.classList.remove('open');
        }
    });
}

if (settingBtn && settingMenu) {
    settingBtn.addEventListener('click', (event) => {
        event.stopPropagation();
        settingMenu.classList.toggle('open');
        if (moreMenu) {
            moreMenu.classList.remove('open');
        }
    });
}

document.querySelectorAll('[data-mode]').forEach(button => {
    button.addEventListener('click', () => setPanelMode(button.dataset.mode));
});


const overstayReminderForm = document.getElementById('overstayReminderForm');
if (overstayReminderForm) {
    overstayReminderForm.addEventListener('submit', function(event) {
        const slot = slotData.find(item => Number(item.id) === Number(selectedSlotId));
        const visitor = slot ? (slot.visitor_name || 'this visitor') : 'this visitor';
        const plate = slot ? (slot.plate_no || '-') : '-';

        if (!confirm('Send overstay reminder email to ' + visitor + ' (' + plate + ')?')) {
            event.preventDefault();
        }
    });
}

const bulkRenameForm = document.getElementById('bulkRenameForm');
if (bulkRenameForm) {
    bulkRenameForm.addEventListener('submit', function(event) {
        const prefixInput = document.getElementById('bulkSlotPrefix');
        const blockInput = document.getElementById('bulkBlockName');
        const prefix = prefixInput ? prefixInput.value.trim() : '';
        const blockName = blockInput ? blockInput.value.trim() : '';

        if (!prefix || !blockName) {
            event.preventDefault();
            Swal.fire('Missing information', 'Please enter the new parking prefix.', 'info');
            return;
        }

        event.preventDefault();
        Swal.fire({
            title: 'Rename parking names?',
            text: blockName + ' will become ' + prefix + '1, ' + prefix + '2, ' + prefix + '3...',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, rename',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#2ec4b6'
        }).then((result) => {
            if (result.isConfirmed) {
                bulkRenameForm.submit();
            }
        });
    });
}

const deleteSlotForm = document.getElementById('deleteSlotForm');
if (topDeleteSlotBtn && deleteSlotForm) {
    topDeleteSlotBtn.addEventListener('click', (event) => {
        event.preventDefault();
        if (settingMenu) {
            settingMenu.classList.remove('open');
        }
        if (typeof deleteSlotForm.requestSubmit === 'function') {
            deleteSlotForm.requestSubmit();
        } else {
            deleteSlotForm.submit();
        }
    });
}

if (deleteSlotForm) {
    deleteSlotForm.addEventListener('submit', function(event) {
        event.preventDefault();

        if (!selectedSlotId) {
            Swal.fire('No slot selected', 'Please select one visitor slot first.', 'info');
            return;
        }

        Swal.fire({
            title: 'Delete this visitor slot?',
            text: 'This action is only allowed when the slot has no booking records.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, delete',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc2626'
        }).then((result) => {
            if (result.isConfirmed) {
                deleteSlotForm.submit();
            }
        });
    });
}

document.addEventListener('click', () => {
    if (profileMenu) profileMenu.classList.remove('open');
    if (moreMenu) moreMenu.classList.remove('open');
    if (settingMenu) settingMenu.classList.remove('open');
});

const blockPageTitle = document.getElementById('blockPageTitle');
const blockPageCount = document.getElementById('blockPageCount');
const prevBlockBtn = document.getElementById('prevBlockBtn');
const nextBlockBtn = document.getElementById('nextBlockBtn');
const bulkBlockName = document.getElementById('bulkBlockName');
const bulkBlockLabel = document.getElementById('bulkBlockLabel');

const blocks = [];
slotData.forEach(slot => {
    const blockName = slot.block_name || 'Other';
    if (!blocks.includes(blockName)) {
        blocks.push(blockName);
    }
});

let currentBlockIndex = 0;

function showBlock(index) {
    if (!blocks.length) return;

    currentBlockIndex = Math.max(0, Math.min(index, blocks.length - 1));
    const activeBlock = blocks[currentBlockIndex];

    let firstVisibleSlotId = null;
    let visibleCount = 0;

    document.querySelectorAll('.slot-row').forEach(row => {
        const show = row.dataset.block === activeBlock;
        row.hidden = !show;

        if (show) {
            visibleCount++;
            if (firstVisibleSlotId === null) {
                firstVisibleSlotId = row.dataset.slotId;
            }
        }
    });

    if (blockPageTitle) {
        blockPageTitle.textContent = activeBlock;
    }

    if (blockPageCount) {
        blockPageCount.textContent = visibleCount + ' slots';
    }

    if (bulkBlockName) {
        bulkBlockName.value = activeBlock;
    }

    if (bulkBlockLabel) {
        bulkBlockLabel.textContent = activeBlock + ' (' + visibleCount + ' slots)';
    }

    if (prevBlockBtn) {
        prevBlockBtn.disabled = currentBlockIndex === 0;
    }

    if (nextBlockBtn) {
        nextBlockBtn.disabled = currentBlockIndex === blocks.length - 1;
    }

    const selectedRow = document.querySelector('.slot-row.selected');

    if (!selectedRow || selectedRow.dataset.block !== activeBlock) {
        selectSlot(firstVisibleSlotId);
    }
}

if (prevBlockBtn) {
    prevBlockBtn.addEventListener('click', () => showBlock(currentBlockIndex - 1));
}

if (nextBlockBtn) {
    nextBlockBtn.addEventListener('click', () => showBlock(currentBlockIndex + 1));
}

if (slotData.length) {
    showBlock(0);
}

setTimeout(() => {
    document.querySelectorAll('.alert').forEach(alertBox => {
        alertBox.classList.add('hide-alert');

        setTimeout(() => {
            alertBox.remove();
        }, 400);
    });
}, 3000);
</script>

</body>
</html>
