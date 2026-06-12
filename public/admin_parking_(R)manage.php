<?php
/* NEW FILE: admin_parking_(R)manage.php - resident parking slots, red sidebar version */
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

function has_table_slots(PDO $pdo, string $table): bool {
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
        'assigned' => 'status-assigned',
        'unpaid' => 'status-unpaid',
        'occupied' => 'status-occupied',
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
        'assigned' => 'Assigned',
        'unpaid' => 'Unpaid',
        'occupied' => 'Occupied',
        'maintenance' => 'Maintenance',
        default => ucfirst((string)$status)
    };
}

function slot_is_unpaid(array $slot): bool {
    $assignmentStatus = strtolower((string)($slot['assignment_status'] ?? ''));
    if (empty($slot['assignment_id']) || $assignmentStatus !== 'active') {
        return false;
    }

    $paymentStatus = strtolower(trim((string)($slot['payment_status'] ?? '')));
    if ($paymentStatus === 'unpaid' || $paymentStatus === 'pending' || $paymentStatus === '') {
        return true;
    }

    $endDate = $slot['end_date'] ?? null;
    if ($endDate) {
        $endTs = strtotime((string)$endDate);
        if ($endTs !== false && $endTs < strtotime(date('Y-m-d'))) {
            return true;
        }
    }

    return $paymentStatus !== 'paid';
}

function slot_display_status(array $slot): string {
    $status = (string)($slot['status'] ?? 'available');
    $assignmentStatus = (string)($slot['assignment_status'] ?? '');

    if ($status === 'maintenance') {
        return 'maintenance';
    }

    if (slot_is_unpaid($slot)) {
        return 'unpaid';
    }

    if ($status === 'occupied') {
        return 'occupied';
    }

    if (!empty($slot['assignment_id']) && $assignmentStatus === 'active') {
        return 'assigned';
    }

    return 'available';
}

function slot_display_label(string $displayStatus): string {
    return match ($displayStatus) {
        'available' => 'Available',
        'assigned' => 'Assigned',
        'unpaid' => 'Unpaid',
        'occupied' => 'Car Inside',
        'maintenance' => 'Maintenance',
        default => ucfirst($displayStatus)
    };
}

function fmt_slot_date($value): string {
    if (!$value) {
        return '-';
    }

    try {
        return date('d M Y', strtotime((string)$value));
    } catch (Throwable $e) {
        return (string)$value;
    }
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


function resident_slot_payment_portal_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/apartment/public/admin_parking_(R)manage.php';
    $publicDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    return $scheme . '://' . $host . $publicDir . '/resident.php';
}

function resident_slot_load_phpmailer(): bool {
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

    $manualGroups = [
        [
            __DIR__ . '/../PHPMailer/src/Exception.php',
            __DIR__ . '/../PHPMailer/src/PHPMailer.php',
            __DIR__ . '/../PHPMailer/src/SMTP.php'
        ],
        [
            __DIR__ . '/../vendor/PHPMailer/PHPMailer/src/Exception.php',
            __DIR__ . '/../vendor/PHPMailer/PHPMailer/src/PHPMailer.php',
            __DIR__ . '/../vendor/PHPMailer/PHPMailer/src/SMTP.php'
        ]
    ];

    foreach ($manualGroups as $group) {
        if (file_exists($group[0]) && file_exists($group[1]) && file_exists($group[2])) {
            require_once $group[0];
            require_once $group[1];
            require_once $group[2];
            break;
        }
    }

    return class_exists('\\PHPMailer\\PHPMailer\\PHPMailer');
}

function fetch_resident_slot_reminder_details(PDO $pdo, int $slotId, string $currentRole, $currentApartmentId, bool $hasSlotApartmentId): array {
    [$scopeSql, $scopeParams] = slot_scope_sql('ps', $currentRole, $currentApartmentId, $hasSlotApartmentId);

    $stmt = $pdo->prepare("\n        SELECT\n            ps.id AS slot_id,\n            ps.block_name,\n            ps.slot_no,\n            ps.apartment_id AS slot_apartment_id,\n            assign.id AS assignment_id,\n            assign.status AS assignment_status,\n            assign.start_date,\n            assign.end_date,\n            assign.monthly_fee,\n            resident.id AS resident_id,\n            resident.full_name AS resident_name,\n            resident.email AS resident_email,\n            COALESCE(CONCAT('Block ', un.block_no, ' / Floor ', un.floor_no, ' / Unit ', un.unit_no), '-') AS unit_text,\n            rv.plate_no,\n            rv.vehicle_model,\n            rv.vehicle_color,\n            pay.payment_status,\n            pay.billing_month\n        FROM parking_slots ps\n        LEFT JOIN (\n            SELECT a1.*\n            FROM resident_parking_assignments a1\n            INNER JOIN (\n                SELECT slot_id, MAX(id) AS latest_assignment_id\n                FROM resident_parking_assignments\n                WHERE status = 'active'\n                GROUP BY slot_id\n            ) latest_assign\n                ON latest_assign.latest_assignment_id = a1.id\n        ) assign\n            ON assign.slot_id = ps.id\n        LEFT JOIN users resident\n            ON resident.id = assign.resident_id\n        LEFT JOIN resident_vehicles rv\n            ON rv.id = assign.vehicle_id\n        LEFT JOIN (\n            SELECT resident_id, MIN(unit_id) AS unit_id\n            FROM resident_units\n            WHERE status = 'active' OR status IS NULL\n            GROUP BY resident_id\n        ) ru\n            ON ru.resident_id = resident.id\n        LEFT JOIN units un\n            ON un.id = ru.unit_id\n        LEFT JOIN (\n            SELECT p1.*\n            FROM parking_payments p1\n            INNER JOIN (\n                SELECT assignment_id, MAX(id) AS latest_payment_id\n                FROM parking_payments\n                GROUP BY assignment_id\n            ) latest_pay\n                ON latest_pay.latest_payment_id = p1.id\n        ) pay\n            ON pay.assignment_id = assign.id\n        WHERE ps.id = ?\n        AND ps.slot_type = 'Resident'\n        {$scopeSql}\n        LIMIT 1\n    ");
    $stmt->execute(array_merge([$slotId], $scopeParams));
    $details = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$details) {
        throw new Exception('Resident parking slot not found or not under your apartment.');
    }

    if (empty($details['assignment_id']) || strtolower((string)($details['assignment_status'] ?? '')) !== 'active') {
        throw new Exception('This slot does not have an active resident parking subscription.');
    }

    if (empty($details['resident_id'])) {
        throw new Exception('No resident is assigned to this slot.');
    }

    return $details;
}

function resident_slot_is_reminder_needed(array $details): bool {
    $paymentStatus = strtolower(trim((string)($details['payment_status'] ?? '')));

    if ($paymentStatus === 'unpaid' || $paymentStatus === 'pending' || $paymentStatus === 'pending_verification' || $paymentStatus === 'overdue' || $paymentStatus === '') {
        return true;
    }

    $endDate = $details['end_date'] ?? null;
    if ($endDate) {
        $endTs = strtotime((string)$endDate);
        if ($endTs !== false && $endTs < strtotime(date('Y-m-d'))) {
            return true;
        }
    }

    return $paymentStatus !== 'paid';
}

function resident_slot_insert_payment_notification(PDO $pdo, array $details, string $billingMonth): void {
    if (!has_table_slots($pdo, 'notifications')) {
        return;
    }

    $residentId = (int)($details['resident_id'] ?? 0);
    if ($residentId <= 0) {
        return;
    }

    $amount = (float)($details['monthly_fee'] ?? 80.00);
    $monthText = date('F Y', strtotime($billingMonth . '-01'));
    $plate = slot_e_text($details['plate_no'] ?? '-');
    $slotText = trim((string)(($details['block_name'] ?? '-') . ' / ' . ($details['slot_no'] ?? '-')));
    $notificationMessage = 'Your resident parking payment for ' . $monthText . ' is still unpaid. Plate: ' . $plate . '. Slot: ' . $slotText . '. Amount: RM ' . number_format($amount, 2) . '.';

    try {
        if (has_column_slots($pdo, 'notifications', 'link_url')) {
            $stmt = $pdo->prepare("\n                INSERT INTO notifications\n                (user_id, title, message, type, link_url, is_read, created_at)\n                VALUES (?, ?, ?, 'parking', ?, 0, NOW())\n            ");
            $stmt->execute([
                $residentId,
                'Resident Parking Payment Reminder',
                $notificationMessage,
                'resident.php'
            ]);
        } else {
            $stmt = $pdo->prepare("\n                INSERT INTO notifications\n                (user_id, title, message, type, is_read, created_at)\n                VALUES (?, ?, ?, 'parking', 0, NOW())\n            ");
            $stmt->execute([
                $residentId,
                'Resident Parking Payment Reminder',
                $notificationMessage
            ]);
        }
    } catch (Throwable $e) {
        // Do not fail the reminder just because the notification insert failed.
    }
}

function resident_slot_send_payment_reminder_email(array $details, string $billingMonth, string $apartmentName, ?string &$mailError = null): bool {
    $mailError = null;

    $toEmail = trim((string)($details['resident_email'] ?? ''));
    $residentName = trim((string)($details['resident_name'] ?? 'Resident'));

    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $mailError = 'Resident email is empty or invalid.';
        return false;
    }

    $mailConfig = __DIR__ . '/../core/mail_config.php';
    if (file_exists($mailConfig)) {
        require_once $mailConfig;
    }

    if (
        !defined('SVMS_SMTP_HOST') ||
        !defined('SVMS_SMTP_USERNAME') ||
        !defined('SVMS_SMTP_PASSWORD') ||
        !defined('SVMS_SMTP_FROM_EMAIL')
    ) {
        $mailError = 'SMTP is not configured in core/mail_config.php.';
        return false;
    }

    if (!resident_slot_load_phpmailer()) {
        $mailError = 'PHPMailer is not installed.';
        return false;
    }

    $amount = (float)($details['monthly_fee'] ?? 80.00);
    $monthText = date('F Y', strtotime($billingMonth . '-01'));
    $portalUrl = resident_slot_payment_portal_url();

    $safeResidentName = htmlspecialchars($residentName ?: 'Resident', ENT_QUOTES, 'UTF-8');
    $safeApartment = htmlspecialchars($apartmentName, ENT_QUOTES, 'UTF-8');
    $safeMonth = htmlspecialchars($monthText, ENT_QUOTES, 'UTF-8');
    $safeAmount = htmlspecialchars('RM ' . number_format($amount, 2), ENT_QUOTES, 'UTF-8');
    $safePlate = htmlspecialchars(slot_e_text($details['plate_no'] ?? '-'), ENT_QUOTES, 'UTF-8');
    $safeVehicle = htmlspecialchars(trim((string)(($details['vehicle_model'] ?? '') . ' ' . ($details['vehicle_color'] ?? ''))) ?: '-', ENT_QUOTES, 'UTF-8');
    $safeSlot = htmlspecialchars(trim((string)(($details['block_name'] ?? '-') . ' / ' . ($details['slot_no'] ?? '-'))), ENT_QUOTES, 'UTF-8');
    $safeUnit = htmlspecialchars(slot_e_text($details['unit_text'] ?? '-'), ENT_QUOTES, 'UTF-8');
    $safePortalUrl = htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8');

    $subject = 'SmartVMS Parking Payment Reminder - ' . $monthText;

    $html = "\n        <div style='margin:0;padding:0;background:#f4f6fb;font-family:Arial,sans-serif;color:#111827;'>\n            <div style='max-width:640px;margin:0 auto;padding:28px 16px;'>\n                <div style='background:#ffffff;border:1px solid #e5e7eb;border-radius:18px;overflow:hidden;box-shadow:0 18px 40px rgba(15,23,42,.10);'>\n                    <div style='background:linear-gradient(135deg,#dc2626,#991b1b);padding:24px;color:white;'>\n                        <h1 style='margin:0;font-size:24px;line-height:1.2;'>Resident Parking Payment Reminder</h1>\n                        <p style='margin:8px 0 0;color:#fee2e2;font-size:14px;'>SmartVMS Resident Parking</p>\n                    </div>\n\n                    <div style='padding:24px;'>\n                        <p style='margin:0 0 14px;font-size:15px;'>Hello <strong>{$safeResidentName}</strong>,</p>\n                        <p style='margin:0 0 18px;font-size:15px;line-height:1.6;'>\n                            This is a reminder that your resident parking payment for <strong>{$safeMonth}</strong> is still unpaid.\n                            Please make the payment as soon as possible to keep your resident parking access active.\n                        </p>\n\n                        <table style='width:100%;border-collapse:collapse;margin:18px 0;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;'>\n                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;width:38%;'>Apartment</td><td style='padding:12px;'>{$safeApartment}</td></tr>\n                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Unit</td><td style='padding:12px;'>{$safeUnit}</td></tr>\n                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Vehicle</td><td style='padding:12px;'>{$safeVehicle}</td></tr>\n                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Plate Number</td><td style='padding:12px;'>{$safePlate}</td></tr>\n                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Parking Slot</td><td style='padding:12px;'>{$safeSlot}</td></tr>\n                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Amount Due</td><td style='padding:12px;color:#dc2626;font-weight:bold;'>{$safeAmount}</td></tr>\n                        </table>\n\n                        <div style='margin:20px 0;padding:14px;border-radius:12px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;line-height:1.55;'>\n                            If payment is not made, resident parking gate access may be denied until the monthly payment is verified.\n                        </div>\n\n                        <p style='margin:18px 0 0;color:#64748b;font-size:13px;line-height:1.6;'>\n                            You may login to SmartVMS here:<br>\n                            <a href='{$safePortalUrl}' style='color:#dc2626;'>{$safePortalUrl}</a>\n                        </p>\n                    </div>\n                </div>\n            </div>\n        </div>\n    ";

    $plainText = "SmartVMS Resident Parking Payment Reminder\n\n" .
        "Hello " . ($residentName ?: 'Resident') . ",\n\n" .
        "Your resident parking payment for {$monthText} is still unpaid.\n" .
        "Amount Due: RM " . number_format($amount, 2) . "\n" .
        "Plate Number: " . slot_e_text($details['plate_no'] ?? '-') . "\n" .
        "Parking Slot: " . slot_e_text(($details['block_name'] ?? '-') . ' / ' . ($details['slot_no'] ?? '-')) . "\n\n" .
        "Please make payment as soon as possible to keep your resident parking access active.\n" .
        "SmartVMS Login: {$portalUrl}\n";

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
        $mail->addAddress($toEmail, $residentName ?: $toEmail);
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

    $stmt = $pdo->prepare("\n        SELECT ps.*\n        FROM parking_slots ps\n        WHERE ps.id = ?\n        AND ps.slot_type = 'Resident'\n        {$scopeSql}\n        LIMIT 1\n    ");
    $stmt->execute(array_merge([$slotId], $scopeParams));
    $slot = $stmt->fetch();

    if (!$slot) {
        throw new Exception('Resident parking slot not found or not under your apartment.');
    }

    return $slot;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'generate_resident_slots') {
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

                    $checkSql = "SELECT id FROM parking_slots WHERE block_name = ? AND slot_no = ? AND slot_type = 'Resident'";
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
                        $stmt = $pdo->prepare("INSERT INTO parking_slots (apartment_id, block_name, slot_no, slot_type, status, created_at) VALUES (?, ?, ?, 'Resident', 'available', NOW())");
                        $stmt->execute([$currentApartmentId ?: null, $blockName, $slotNo]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO parking_slots (block_name, slot_no, slot_type, status, created_at) VALUES (?, ?, 'Resident', 'available', NOW())");
                        $stmt->execute([$blockName, $slotNo]);
                    }

                    $created++;
                }

                if (function_exists('log_audit')) {
                    log_audit('RESIDENT_SLOTS_GENERATED', 'Admin generated resident parking slots. Block: ' . $blockName . ', Created: ' . $created . ', Skipped: ' . $skipped);
                }

                $message = 'Resident slots generated. Created: ' . $created . ', skipped existing: ' . $skipped . '.';
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

                $checkSql = "SELECT id FROM parking_slots WHERE block_name = ? AND slot_no = ? AND slot_type = 'Resident'";
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
                    $stmt = $pdo->prepare("INSERT INTO parking_slots (apartment_id, block_name, slot_no, slot_type, status, created_at) VALUES (?, ?, ?, 'Resident', ?, NOW())");
                    $stmt->execute([$currentApartmentId ?: null, $blockName, $slotNo, $status]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO parking_slots (block_name, slot_no, slot_type, status, created_at) VALUES (?, ?, 'Resident', ?, NOW())");
                    $stmt->execute([$blockName, $slotNo, $status]);
                }

                if (function_exists('log_audit')) {
                    log_audit('RESIDENT_SLOT_CREATED', 'Admin created resident parking slot: ' . $blockName . ' ' . $slotNo);
                }

                $message = 'Resident parking slot added successfully.';
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

                $activeAssignmentCount = safe_count_slots($pdo, "SELECT COUNT(*) FROM resident_parking_assignments WHERE slot_id = ? AND status = 'active'", [$slotId]);

                if ($activeAssignmentCount > 0 && $newStatus === 'available') {
                    throw new Exception('Cannot set this slot to available because it is assigned to a resident.');
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
                    log_audit('RESIDENT_SLOT_STATUS_UPDATED', 'Admin changed resident slot ' . $slot['block_name'] . ' ' . $slot['slot_no'] . ' to ' . $newStatus);
                }

                $message = 'Resident parking slot status updated successfully.';
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

                $checkSql = "SELECT id FROM parking_slots WHERE block_name = ? AND slot_no = ? AND slot_type = 'Resident' AND id <> ?";
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
                    log_audit('RESIDENT_SLOT_RENAMED', 'Admin updated resident slot from ' . $slot['block_name'] . ' ' . $slot['slot_no'] . ' to ' . $newBlockName . ' ' . $newSlotNo);
                }

                $message = 'Resident parking slot updated successfully.';
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
                    WHERE ps.slot_type = 'Resident'
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
                    log_audit('RESIDENT_SLOTS_BULK_RENAMED', 'Admin bulk renamed resident slots in ' . $targetBlockName . ' using prefix ' . $prefix);
                }

                $message = 'Parking names updated successfully. ' . count($renameSlots) . ' slots renamed to ' . $prefix . '1, ' . $prefix . '2, ...';
            } elseif ($action === 'send_slot_payment_reminder') {
                $slotId = (int)($_POST['slot_id'] ?? 0);
                $reminderMonth = trim((string)($_POST['billing_month'] ?? date('Y-m')));

                if ($slotId <= 0) {
                    throw new Exception('Invalid slot selected.');
                }

                if (!preg_match('/^\d{4}-\d{2}$/', $reminderMonth)) {
                    $reminderMonth = date('Y-m');
                }

                $details = fetch_resident_slot_reminder_details($pdo, $slotId, $currentRole, $currentApartmentId, $hasSlotApartmentId);

                if (!resident_slot_is_reminder_needed($details)) {
                    throw new Exception('This resident parking payment is already paid. Reminder is not needed.');
                }

                $mailError = null;
                $emailSent = resident_slot_send_payment_reminder_email($details, $reminderMonth, $currentApartmentName, $mailError);

                if (!$emailSent) {
                    throw new Exception('Reminder email could not be sent. ' . ($mailError ?: 'Please check SMTP settings.'));
                }

                resident_slot_insert_payment_notification($pdo, $details, $reminderMonth);

                if (function_exists('log_audit')) {
                    log_audit('RESIDENT_PARKING_PAYMENT_REMINDER_SENT', 'Admin sent resident parking payment reminder from slot map for slot #' . $slotId . '.');
                }

                $message = 'Payment reminder email and notification sent to ' . slot_e_text($details['resident_email'] ?? '') . '.';
            } elseif ($action === 'delete_slot') {
                $slotId = (int)($_POST['slot_id'] ?? 0);

                if ($slotId <= 0) {
                    throw new Exception('Invalid slot selected.');
                }

                $slot = fetch_slot_for_action($pdo, $slotId, $currentRole, $currentApartmentId, $hasSlotApartmentId);

                $linkedAssignmentCount = safe_count_slots($pdo, "SELECT COUNT(*) FROM resident_parking_assignments WHERE slot_id = ?", [$slotId]);

                if ($linkedAssignmentCount > 0) {
                    throw new Exception('Cannot delete this slot because it has resident parking assignment records. Set it to maintenance instead.');
                }

                $pdo->prepare("DELETE FROM parking_slots WHERE id = ?")->execute([$slotId]);

                if (function_exists('log_audit')) {
                    log_audit('RESIDENT_SLOT_DELETED', 'Admin deleted resident parking slot: ' . $slot['block_name'] . ' ' . $slot['slot_no']);
                }

                $message = 'Resident parking slot deleted successfully.';
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

    $redirectUrl = basename($_SERVER['PHP_SELF']);
    $postedSlotId = (int)($_POST['slot_id'] ?? 0);

    if ($postedSlotId > 0) {
        $redirectUrl .= '?slot_id=' . $postedSlotId;
    }

    header('Location: ' . $redirectUrl);
    exit;
}

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$targetSlotId = (int)($_GET['slot_id'] ?? $_GET['target_slot_id'] ?? 0);
$targetAssignmentId = (int)($_GET['assignment_id'] ?? 0);
$targetResidentId = (int)($_GET['resident_id'] ?? 0);
$targetVehicleId = (int)($_GET['vehicle_id'] ?? 0);
$targetPlate = clean_slot_text($_GET['plate'] ?? '');

$fromPage = strtolower(trim((string)($_GET['from'] ?? '')));
$showPaymentBackButton = in_array($fromPage, ['payment_verification', 'payment', 'parking_payment'], true);
$paymentBackUrl = 'admin_parking_payment.php';

if ($search === '' && $targetPlate !== '') {
    $search = $targetPlate;
}

if (!in_array($statusFilter, ['', 'available', 'assigned', 'unpaid', 'occupied', 'maintenance'], true)) {
    $statusFilter = '';
}

[$scopeSql, $scopeParams] = slot_scope_sql('ps', $currentRole, $currentApartmentId, $hasSlotApartmentId);
$where = "WHERE ps.slot_type = 'Resident' {$scopeSql}";
$params = $scopeParams;

if ($search !== '') {
    $where .= " AND (ps.block_name LIKE ? OR ps.slot_no LIKE ? OR rv.plate_no LIKE ? OR resident.full_name LIKE ? OR resident.email LIKE ?)";
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term, $term);
}

if ($statusFilter !== '') {
    if ($statusFilter === 'available') {
        $where .= " AND ps.status = 'available' AND assign.id IS NULL";
    } elseif ($statusFilter === 'assigned') {
        $where .= " AND assign.id IS NOT NULL AND assign.status = 'active' AND ps.status NOT IN ('occupied', 'maintenance')";
    } elseif ($statusFilter === 'unpaid') {
        $where .= " AND assign.id IS NOT NULL AND assign.status = 'active' AND ps.status NOT IN ('occupied', 'maintenance') AND (LOWER(COALESCE(pay.payment_status, 'unpaid')) <> 'paid' OR assign.end_date < CURDATE())";
    } elseif ($statusFilter === 'occupied') {
        $where .= " AND ps.status = 'occupied'";
    } elseif ($statusFilter === 'maintenance') {
        $where .= " AND ps.status = 'maintenance'";
    }
}

$slots = safe_rows_slots($pdo, "
    SELECT
        ps.*,
        assign.id AS assignment_id,
        assign.status AS assignment_status,
        assign.start_date,
        assign.end_date,
        assign.monthly_fee,
        assign.resident_id,
        assign.vehicle_id,
        resident.full_name AS resident_name,
        resident.email AS resident_email,
        resident.contact_number AS resident_phone,
        rv.plate_no,
        rv.vehicle_model,
        rv.vehicle_color,
        pay.payment_status,
        pay.billing_month
    FROM parking_slots ps
    LEFT JOIN (
        SELECT a1.*
        FROM resident_parking_assignments a1
        INNER JOIN (
            SELECT slot_id, MAX(id) AS latest_assignment_id
            FROM resident_parking_assignments
            WHERE status = 'active'
            GROUP BY slot_id
        ) latest_assign
            ON latest_assign.latest_assignment_id = a1.id
    ) assign
        ON assign.slot_id = ps.id
    LEFT JOIN resident_vehicles rv
        ON rv.id = assign.vehicle_id
    LEFT JOIN users resident
        ON resident.id = assign.resident_id
    LEFT JOIN (
        SELECT p1.*
        FROM parking_payments p1
        INNER JOIN (
            SELECT assignment_id, MAX(id) AS latest_payment_id
            FROM parking_payments
            GROUP BY assignment_id
        ) latest_pay
            ON latest_pay.latest_payment_id = p1.id
    ) pay
        ON pay.assignment_id = assign.id
    {$where}
    ORDER BY ps.block_name ASC, ps.id ASC
    LIMIT 600
", $params);

/* Natural parking sort: keeps A1, A2 ... A10 in the correct order. */
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

function count_resident_slot(PDO $pdo, string $currentRole, $currentApartmentId, bool $hasSlotApartmentId, string $extra = '', array $extraParams = []): int {
    [$scopeSql, $scopeParams] = slot_scope_sql('ps', $currentRole, $currentApartmentId, $hasSlotApartmentId);
    return safe_count_slots($pdo, "SELECT COUNT(*) FROM parking_slots ps WHERE ps.slot_type = 'Resident' {$scopeSql} {$extra}", array_merge($scopeParams, $extraParams));
}

$totalResidentSlots = count_resident_slot($pdo, $currentRole, $currentApartmentId, $hasSlotApartmentId);
$availableResidentSlots = count_resident_slot($pdo, $currentRole, $currentApartmentId, $hasSlotApartmentId, "AND ps.status = 'available'");
$occupiedResidentSlots = count_resident_slot($pdo, $currentRole, $currentApartmentId, $hasSlotApartmentId, "AND ps.status = 'occupied'");
$maintenanceResidentSlots = count_resident_slot($pdo, $currentRole, $currentApartmentId, $hasSlotApartmentId, "AND ps.status = 'maintenance'");

$activeAssignmentsSql = "
    SELECT COUNT(DISTINCT assign.id)
    FROM resident_parking_assignments assign
    INNER JOIN parking_slots ps
        ON ps.id = assign.slot_id
        AND ps.slot_type = 'Resident'
    WHERE assign.status = 'active'
";
$activeAssignmentParams = [];

if ($currentRole !== 'superadmin' && $hasSlotApartmentId) {
    if (empty($currentApartmentId)) {
        $activeAssignmentsSql .= " AND 1 = 0";
    } else {
        $activeAssignmentsSql .= " AND ps.apartment_id = ?";
        $activeAssignmentParams[] = (int)$currentApartmentId;
    }
}

$activeAssignments = safe_count_slots($pdo, $activeAssignmentsSql, $activeAssignmentParams);

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
        'slot_type' => (string)($slot['slot_type'] ?? 'Resident'),
        'status' => (string)($slot['status'] ?? 'available'),
        'status_label' => slot_status_full_label($slot['status'] ?? 'available'),
        'display_status' => $displayStatus,
        'display_label' => slot_display_label($displayStatus),
        'assignment_id' => $slot['assignment_id'] ? (int)$slot['assignment_id'] : null,
        'vehicle_id' => isset($slot['vehicle_id']) && $slot['vehicle_id'] !== null ? (int)$slot['vehicle_id'] : null,
        'resident_id' => isset($slot['resident_id']) && $slot['resident_id'] !== null ? (int)$slot['resident_id'] : null,
        'assignment_status' => (string)slot_e_text($slot['assignment_status'] ?? null),
        'resident_name' => (string)slot_e_text($slot['resident_name'] ?? null),
        'resident_email' => (string)slot_e_text($slot['resident_email'] ?? null),
        'resident_phone' => (string)slot_e_text($slot['resident_phone'] ?? null),
        'plate_no' => (string)slot_e_text($slot['plate_no'] ?? null),
        'vehicle_model' => (string)slot_e_text($slot['vehicle_model'] ?? null),
        'vehicle_color' => (string)slot_e_text($slot['vehicle_color'] ?? null),
        'vehicle_text' => trim((string)slot_e_text(($slot['vehicle_model'] ?? '') . ' ' . ($slot['vehicle_color'] ?? ''))) ?: '-',
        'payment_status' => (string)slot_e_text($slot['payment_status'] ?? null),
        'is_unpaid' => $displayStatus === 'unpaid',
        'billing_month' => (string)slot_e_text($slot['billing_month'] ?? null),
        'start_date' => fmt_slot_date($slot['start_date'] ?? null),
        'end_date' => fmt_slot_date($slot['end_date'] ?? null),
        'created_at' => fmt_slot_dt($slot['created_at'] ?? null),
        'updated_at' => fmt_slot_dt($slot['updated_at'] ?? null),
    ];
}, $slots);

$displayTotalSlots = count($slotJsRows);
$displayAvailableSlots = count(array_filter($slotJsRows, fn($slot) => ($slot['display_status'] ?? '') === 'available'));
$displayAssignedSlots = count(array_filter($slotJsRows, fn($slot) => in_array(($slot['display_status'] ?? ''), ['assigned', 'unpaid'], true)));
$displayUnpaidSlots = count(array_filter($slotJsRows, fn($slot) => ($slot['display_status'] ?? '') === 'unpaid'));
$displayOccupiedSlots = count(array_filter($slotJsRows, fn($slot) => ($slot['display_status'] ?? '') === 'occupied'));
$displayMaintenanceSlots = count(array_filter($slotJsRows, fn($slot) => ($slot['display_status'] ?? '') === 'maintenance'));

$selectedSlotId = 0;

foreach ($slotJsRows as $slotRow) {
    if (
        ($targetSlotId > 0 && (int)$slotRow['id'] === $targetSlotId) ||
        ($targetAssignmentId > 0 && (int)($slotRow['assignment_id'] ?? 0) === $targetAssignmentId) ||
        ($targetResidentId > 0 && (int)($slotRow['resident_id'] ?? 0) === $targetResidentId) ||
        ($targetVehicleId > 0 && (int)($slotRow['vehicle_id'] ?? 0) === $targetVehicleId) ||
        ($targetPlate !== '' && strtoupper((string)($slotRow['plate_no'] ?? '')) === $targetPlate)
    ) {
        $selectedSlotId = (int)$slotRow['id'];
        break;
    }
}

if ($selectedSlotId === 0 && !empty($slotJsRows)) {
    $selectedSlotId = (int)$slotJsRows[0]['id'];
}

$firstSlot = null;
foreach ($slotJsRows as $slotRow) {
    if ((int)$slotRow['id'] === $selectedSlotId) {
        $firstSlot = $slotRow;
        break;
    }
}
if ($firstSlot === null) {
    $firstSlot = $slotJsRows[0] ?? null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Resident Parking Slots - <?= e(APP_NAME) ?></title>
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

        .slot-card.located-slot {
            outline: 4px solid rgba(220, 38, 38, .18);
            box-shadow: 0 18px 36px rgba(220, 38, 38, .18);
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

        .status-assigned {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-unpaid {
            background: #fee2e2;
            color: #b91c1c;
            box-shadow: 0 10px 18px rgba(220, 38, 38, .12);
        }

        .status-reserved {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-occupied,
        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
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

        .slot-summary-badge.unpaid {
            background: #fee2e2;
            color: #b91c1c;
            box-shadow: 0 10px 18px rgba(220, 38, 38, .12);
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


        .legend-dot.assigned {
            background: #3b82f6;
        }

        .legend-dot.unpaid {
            background: #f97316;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, .16);
        }

        .legend-dot.occupied {
            background: #dc2626;
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

        .slot-card.state-assigned {
            border-color: #bfdbfe;
            background: #eff6ff;
            color: #1e3a8a;
        }

        .slot-card.state-assigned.selected {
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, .14);
        }

        .slot-card.state-unpaid {
            border-color: #fb923c;
            background: linear-gradient(145deg, #fff7ed 0%, #ffedd5 50%, #fee2e2 100%);
            color: #9a3412;
            box-shadow: 0 14px 30px rgba(249, 115, 22, .16);
        }

        .slot-card.state-unpaid::after {
            background: rgba(249, 115, 22, .16);
        }

        .slot-card.state-unpaid.selected {
            border-color: #dc2626;
            outline: 3px solid rgba(220, 38, 38, .16);
            box-shadow: 0 0 0 2px rgba(220, 38, 38, .12), 0 16px 34px rgba(249, 115, 22, .18);
        }

        .slot-unpaid-badge {
            position: absolute;
            top: 8px;
            right: 8px;
            z-index: 3;
            padding: 5px 8px;
            border-radius: 999px;
            background: #dc2626;
            color: #ffffff;
            font-size: .58rem;
            font-weight: 950;
            letter-spacing: .04em;
            text-transform: uppercase;
            box-shadow: 0 8px 16px rgba(220, 38, 38, .24);
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
        .slot-card.state-occupied .slot-card-status {
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


        .slot-card.state-occupied .slot-car-icon {
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


        .slot-card.state-occupied .slot-mini-pill {
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



        .slot-card.state-assigned {
            border-color: #bfdbfe;
            background: #eff6ff;
            color: #1e3a8a;
        }

        .slot-card.state-assigned.selected {
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, .14);
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

        .slot-card.state-unpaid .slot-code-only {
            color: #9a3412;
        }



        /* Darker hover color by slot status */
        .slot-card.state-available:hover {
            border-color: #0f766e;
            background: #99f6e4;
            color: #0f172a;
            box-shadow: 0 10px 20px rgba(15, 118, 110, .16);
        }

        .slot-card.state-assigned:hover {
            border-color: #2563eb;
            background: #dbeafe;
            color: #1e3a8a;
            box-shadow: 0 10px 20px rgba(37, 99, 235, .16);
        }

        .slot-card.state-unpaid:hover {
            border-color: #dc2626;
            background: linear-gradient(145deg, #fed7aa 0%, #fdba74 48%, #fecaca 100%);
            color: #7c2d12;
            box-shadow: 0 12px 24px rgba(220, 38, 38, .18);
        }

        .slot-card.state-occupied:hover {
            border-color: #7f1d1d;
            background: #b91c1c;
            color: #ffffff;
            box-shadow: 0 12px 24px rgba(185, 28, 28, .22);
        }

        .slot-card.state-maintenance:hover {
            border-color: #64748b;
            background: #cbd5e1;
            color: #334155;
            box-shadow: 0 10px 20px rgba(100, 116, 139, .16);
        }

        .slot-card.state-unpaid:hover .slot-code-only {
            color: #7c2d12;
        }

        .slot-card.state-occupied:hover .slot-code-only {
            color: #ffffff;
        }


        /* Keep unpaid slots red/orange even when selected from Payment Verification page */
        .slot-card.state-unpaid,
        .slot-card.state-unpaid.selected {
            border-color: #dc2626 !important;
            background: linear-gradient(145deg, #fee2e2 0%, #fecaca 58%, #fed7aa 100%) !important;
            color: #991b1b !important;
            box-shadow: 0 14px 30px rgba(220, 38, 38, .16) !important;
            outline: none !important;
        }

        .slot-card.state-unpaid:hover,
        .slot-card.state-unpaid.selected:hover {
            border-color: #991b1b !important;
            background: linear-gradient(145deg, #fecaca 0%, #fca5a5 58%, #fdba74 100%) !important;
            color: #7f1d1d !important;
            box-shadow: 0 16px 32px rgba(153, 27, 27, .20) !important;
        }

        .slot-card.state-unpaid .slot-code-only,
        .slot-card.state-unpaid.selected .slot-code-only,
        .slot-card.state-unpaid:hover .slot-code-only {
            color: #b91c1c !important;
        }

        .info-view-lines {
            display: flex;
            flex-direction: column;
        }

        .slot-reminder-wrap {
            margin-top: auto;
            padding-top: 20px;
            border-top: 0;
        }

        .slot-reminder-form {
            width: 100%;
        }

        .slot-reminder-btn {
            width: 100%;
            min-height: 54px;
            border-radius: 16px;
            background: linear-gradient(135deg, #dc2626, #991b1b);
            color: #ffffff;
            border: 0;
            font-size: .86rem;
            font-weight: 950;
            box-shadow: 0 18px 34px rgba(220, 38, 38, .26);
        }

        .slot-reminder-btn:hover {
            background: linear-gradient(135deg, #b91c1c, #7f1d1d);
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 20px 38px rgba(153, 27, 27, .30);
        }

        .slot-reminder-note {
            margin-top: 10px;
            color: #64748b;
            font-size: .72rem;
            font-weight: 850;
            line-height: 1.35;
            text-align: center;
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
                <h1 class="page-title">Resident Parking Slots</h1>
                <p class="page-sub">
                    Resident slots are shown as parking boxes. Green means available, blue means assigned, red means a car is inside, and grey means maintenance.
                </p>
            </div>

            <div class="top-actions">
                <?php if ($showPaymentBackButton): ?>
                    <a href="<?= e($paymentBackUrl) ?>" class="top-btn">
                        <i class="fas fa-arrow-left"></i>
                        Payment Verification
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
                        Resident Slot Map
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
                            <option value="assigned" <?= $statusFilter === 'assigned' ? 'selected' : '' ?>>Assigned</option>
                            <option value="unpaid" <?= $statusFilter === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                            <option value="occupied" <?= $statusFilter === 'occupied' ? 'selected' : '' ?>>Occupied</option>
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
                                    Add Resident Slot
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
                        <div>No resident parking slot found.</div>
                    </div>
                <?php else: ?>
                    <div class="slot-map-wrap">
                        <div class="slot-map-top">
                            <div class="slot-count-note">
                                Showing <?= (int)$displayTotalSlots ?> resident parking boxes.
                            </div>
                            <div class="slot-legend">
                                <span class="legend-item"><span class="legend-dot"></span>Available</span>
                                <span class="legend-item"><span class="legend-dot assigned"></span>Assigned</span>
                                <span class="legend-item"><span class="legend-dot unpaid"></span>Unpaid</span>
                                <span class="legend-item"><span class="legend-dot occupied"></span>Car Inside</span>
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
                                <button type="button" class="slot-card slot-row state-<?= e($displayStatus) ?> <?= $slotId === (int)$selectedSlotId ? 'selected located-slot' : '' ?>" data-slot-id="<?= $slotId ?>" data-block="<?= e($blockName) ?>" title="<?= e($slotNo) ?> - <?= e($displayLabel) ?>">
                                    <?php if ($displayStatus === 'unpaid'): ?>
                                        <span class="slot-unpaid-badge">Unpaid</span>
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
                            <div class="num"><?= (int)$displayAssignedSlots ?></div>
                            <div class="lbl">Assigned</div>
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
                                <div class="slot-summary-block" id="infoBlockName"><?= $firstSlot ? e($firstSlot['block_name']) : 'Please select a resident slot.' ?></div>
                            </div>
                            <div class="slot-summary-badge <?= ($firstSlot && (($firstSlot['display_status'] ?? '') === 'unpaid')) ? 'unpaid' : '' ?>" id="infoStatusBadge">
                                <i class="fas <?= ($firstSlot && (($firstSlot['display_status'] ?? '') === 'unpaid')) ? 'fa-circle-exclamation' : 'fa-circle' ?>"></i>
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
                                    <span id="infoVisitor"><?= $firstSlot ? e($firstSlot['vehicle_text'] ?? $firstSlot['vehicle_model']) : '-' ?></span>
                                </div>
                            </div>

                            <div class="info-line">
                                <i class="fas fa-house-user"></i>
                                <div>
                                    <strong>Resident / Unit Owner</strong><br>
                                    <span id="infoOwner"><?= $firstSlot ? e($firstSlot['resident_name']) : '-' ?></span>
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
                                    <span id="infoTime"><?= $firstSlot ? e($firstSlot['start_date'] . ' - ' . $firstSlot['end_date']) : '-' ?></span>
                                </div>
                            </div>

                            <div class="info-line">
                                <i class="fas fa-clock"></i>
                                <div>
                                    <strong>Expected Exit Time</strong><br>
                                    <span id="infoExitTime"><?= $firstSlot ? e($firstSlot['end_date']) : '-' ?></span>
                                </div>
                            </div>

                            <div class="slot-reminder-wrap" id="slotReminderWrap" style="<?= ($firstSlot && (($firstSlot['display_status'] ?? '') === 'unpaid')) ? '' : 'display:none;' ?>">
                                <form method="POST" class="slot-reminder-form" id="slotReminderForm">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="send_slot_payment_reminder">
                                    <input type="hidden" name="slot_id" class="selected-slot-id" value="<?= $firstSlot ? (int)$firstSlot['id'] : 0 ?>">
                                    <input type="hidden" name="billing_month" value="<?= e(date('Y-m')) ?>">
                                    <button type="submit" class="btn slot-reminder-btn">
                                        <i class="fas fa-envelope"></i>
                                        Send Payment Reminder
                                    </button>
                                </form>
                                <div class="slot-reminder-note">
                                    Sends email and notification to the assigned resident.
                                </div>
                            </div>
                        </div>


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
                            <div class="section-label">Add One Resident Slot</div>
                            <form method="POST">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="add_single_slot">
                                <input type="text" name="block_name" placeholder="Parking Block, example: Resident Zone" required>
                                <input type="text" name="slot_no" placeholder="Slot Number, example: RA1" required>
                                <select name="status" required>
                                    <option value="available">Available</option>
                                    <option value="occupied">Occupied</option>
                                    <option value="maintenance">Maintenance</option>
                                </select>
                                <div class="button-grid">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-plus"></i>
                                        Add Resident Slot
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
                                <input type="hidden" name="action" value="generate_resident_slots">
                                <input type="text" name="block_name" value="Resident Zone" placeholder="Parking Block" required>
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
const initialSelectedSlotId = <?= (int)$selectedSlotId ?>;
let selectedSlotId = initialSelectedSlotId || (slotData.length ? slotData[0].id : 0);

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
const statusClasses = ['status-active', 'status-assigned', 'status-unpaid', 'status-occupied', 'status-maintenance', 'status-inactive'];

function setText(id, value) {
    const el = document.getElementById(id);
    if (el) {
        el.textContent = value || '-';
    }
}

function statusClass(status) {
    if (status === 'available') return 'status-active';
    if (status === 'assigned') return 'status-assigned';
    if (status === 'unpaid') return 'status-unpaid';
    if (status === 'occupied') return 'status-occupied';
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
        const isSelected = Number(row.dataset.slotId) === Number(slot.id);
        row.classList.toggle('selected', isSelected);
        row.classList.toggle('located-slot', isSelected && Number(slot.id) === Number(initialSelectedSlotId));
    });

    document.querySelectorAll('.selected-slot-id').forEach(input => {
        input.value = slot.id;
    });

    setText('infoSlotNo', slot.slot_no);
    setText('infoBlockName', slot.block_name);
    setText('infoCurrentStatus', slot.display_label);
    setText('infoVisitor', slot.vehicle_text || slot.vehicle_model || '-');
    setText('infoOwner', slot.resident_name);
    setText('infoPlate', slot.plate_no);
    const timeText = (slot.start_date && slot.start_date !== '-') ? `${slot.start_date} - ${slot.end_date}` : '-';
    const exitText = (slot.end_date && slot.end_date !== '-') ? slot.end_date : '-';
    setText('infoTime', timeText);
    setText('infoExitTime', exitText);

    const statusText = document.querySelector('#infoStatusBadge span');
    if (statusText) {
        statusText.textContent = slot.display_label || '-';
    }

    const summaryBadge = document.getElementById('infoStatusBadge');
    if (summaryBadge) {
        summaryBadge.classList.toggle('unpaid', slot.display_status === 'unpaid');

        const badgeIcon = summaryBadge.querySelector('i');
        if (badgeIcon) {
            badgeIcon.className = slot.display_status === 'unpaid' ? 'fas fa-circle-exclamation' : 'fas fa-circle';
        }
    }

    const pill = document.getElementById('infoStatusPill');
    if (pill) {
        pill.classList.remove(...statusClasses);
        pill.classList.add(statusClass(slot.display_status));
        pill.textContent = slot.display_label;
    }

    const slotReminderWrap = document.getElementById('slotReminderWrap');
    if (slotReminderWrap) {
        slotReminderWrap.style.display = (slot.display_status === 'unpaid' && slot.assignment_id) ? '' : 'none';
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
            Swal.fire('No slot selected', 'Please select one resident slot first.', 'info');
            return;
        }

        Swal.fire({
            title: 'Delete this resident slot?',
            text: 'This action is only allowed when the slot has no resident assignment records.',
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
    const initialSlot = slotData.find(slot => Number(slot.id) === Number(selectedSlotId)) || slotData[0];
    const initialBlock = initialSlot ? (initialSlot.block_name || 'Other') : blocks[0];
    const initialBlockIndex = Math.max(0, blocks.indexOf(initialBlock));

    showBlock(initialBlockIndex);
    selectSlot(selectedSlotId);

    setTimeout(() => {
        const selectedRow = document.querySelector('.slot-row.selected');
        if (selectedRow) {
            selectedRow.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    }, 250);
}

setTimeout(() => {
    document.querySelectorAll('.alert').forEach(alertBox => {
        alertBox.classList.add('hide-alert');

        setTimeout(() => {
            alertBox.remove();
        }, 400);
    });
}, 3000);

const slotReminderForm = document.getElementById('slotReminderForm');
if (slotReminderForm) {
    slotReminderForm.addEventListener('submit', function (event) {
        event.preventDefault();

        Swal.fire({
            title: 'Send payment reminder?',
            text: 'An email and notification will be sent to the assigned resident.',
            icon: 'info',
            showCancelButton: true,
            confirmButtonText: 'Yes, send reminder',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#f97316'
        }).then((result) => {
            if (result.isConfirmed) {
                slotReminderForm.submit();
            }
        });
    });
}

</script>

</body>
</html>
