<?php
/* admin_parking_payment.php
   Parking Management page focused only on resident monthly parking payment/subscription.
*/
require_once '../core/security.php';
require_login(['admin', 'superadmin']);

$pdo = db();

if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$message = $_SESSION['flash_success'] ?? '';
$error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

function has_table_payment(PDO $pdo, string $table): bool {
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

function has_column_payment(PDO $pdo, string $table, string $column): bool {
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

function first_column_payment(PDO $pdo, string $table, array $columns): ?string {
    foreach ($columns as $column) {
        if (has_column_payment($pdo, $table, $column)) {
            return $column;
        }
    }
    return null;
}

function normalize_plate_payment(string $plate): string {
    $plate = strtoupper(trim($plate));
    $plate = preg_replace('/[^A-Z0-9]/', '', $plate);
    return $plate ?: '';
}

function blacklist_plate_payment(PDO $pdo, string $plateNo, string $reason, int $adminId = 0): void {
    if (!has_table_payment($pdo, 'blacklisted_plates')) {
        throw new Exception('blacklisted_plates table not found.');
    }

    $plateCol = first_column_payment($pdo, 'blacklisted_plates', ['plate_no', 'plate_number', 'vehicle_plate', 'plate']);
    if (!$plateCol) {
        throw new Exception('Plate column not found in blacklisted_plates table.');
    }

    $plateNo = normalize_plate_payment($plateNo);
    if ($plateNo === '') {
        throw new Exception('Invalid plate number.');
    }

    $reason = trim($reason);
    if ($reason === '') {
        $reason = 'Unpaid resident parking payment';
    }

    $existingId = null;
    $idCol = first_column_payment($pdo, 'blacklisted_plates', ['id', 'blacklist_id']);
    if ($idCol) {
        $stmt = $pdo->prepare("SELECT `{$idCol}` FROM blacklisted_plates WHERE `{$plateCol}` = ? LIMIT 1");
        $stmt->execute([$plateNo]);
        $existingId = $stmt->fetchColumn();
    }

    $reasonCol = first_column_payment($pdo, 'blacklisted_plates', ['reason', 'blacklist_reason', 'remarks', 'remark', 'description']);
    $statusCol = first_column_payment($pdo, 'blacklisted_plates', ['status', 'blacklist_status']);
    $createdAtCol = first_column_payment($pdo, 'blacklisted_plates', ['created_at', 'date_created']);
    $updatedAtCol = first_column_payment($pdo, 'blacklisted_plates', ['updated_at', 'date_updated']);
    $createdByCol = first_column_payment($pdo, 'blacklisted_plates', ['created_by', 'admin_id', 'created_by_user_id']);
    $updatedByCol = first_column_payment($pdo, 'blacklisted_plates', ['updated_by', 'updated_by_user_id']);

    if ($existingId !== false && $existingId !== null && $idCol) {
        $sets = [];
        $params = [];

        if ($reasonCol) {
            $sets[] = "`{$reasonCol}` = ?";
            $params[] = $reason;
        }

        if ($statusCol) {
            $sets[] = "`{$statusCol}` = ?";
            $params[] = 'active';
        }

        if ($updatedAtCol) {
            $sets[] = "`{$updatedAtCol}` = NOW()";
        }

        if ($updatedByCol && $adminId > 0) {
            $sets[] = "`{$updatedByCol}` = ?";
            $params[] = $adminId;
        }

        if (!$sets) {
            return;
        }

        $params[] = $existingId;
        $sql = "UPDATE blacklisted_plates SET " . implode(', ', $sets) . " WHERE `{$idCol}` = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return;
    }

    $cols = ["`{$plateCol}`"];
    $placeholders = ['?'];
    $params = [$plateNo];

    if ($reasonCol) {
        $cols[] = "`{$reasonCol}`";
        $placeholders[] = '?';
        $params[] = $reason;
    }

    if ($statusCol) {
        $cols[] = "`{$statusCol}`";
        $placeholders[] = '?';
        $params[] = 'active';
    }

    if ($createdAtCol) {
        $cols[] = "`{$createdAtCol}`";
        $placeholders[] = 'NOW()';
    }

    if ($createdByCol && $adminId > 0) {
        $cols[] = "`{$createdByCol}`";
        $placeholders[] = '?';
        $params[] = $adminId;
    }

    $sql = "INSERT INTO blacklisted_plates (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function safe_rows_payment(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function fmt_date_payment($value): string {
    if (!$value) {
        return '-';
    }

    try {
        return date('d M Y', strtotime((string)$value));
    } catch (Throwable $e) {
        return (string)$value;
    }
}

function fmt_dt_payment($value): string {
    if (!$value) {
        return '-';
    }

    try {
        return date('d M Y, g:i A', strtotime((string)$value));
    } catch (Throwable $e) {
        return (string)$value;
    }
}

function money_payment($value): string {
    if ($value === null || $value === '') {
        return '-';
    }

    return 'RM ' . number_format((float)$value, 2);
}

function text_or_dash_payment($value): string {
    $text = trim((string)($value ?? ''));
    return $text !== '' ? $text : '-';
}

function resident_parking_position_url_payment(array $row): string {
    $params = [];

    foreach (['slot_id', 'assignment_id', 'resident_id', 'vehicle_id'] as $key) {
        $value = (int)($row[$key] ?? 0);
        if ($value > 0) {
            $params[$key] = $value;
        }
    }

    $plate = normalize_plate_payment((string)($row['plate_no'] ?? ''));
    if ($plate !== '') {
        $params['plate'] = $plate;
        $params['search'] = $plate;
    } else {
        $fallbackSearch = trim((string)($row['slot_no'] ?? ''));
        if ($fallbackSearch === '') {
            $fallbackSearch = trim((string)($row['resident_email'] ?? $row['resident_name'] ?? ''));
        }
        if ($fallbackSearch !== '') {
            $params['search'] = $fallbackSearch;
        }
    }

    $params['from'] = 'payment_verification';

    return 'admin_parking_(R)manage.php' . ($params ? '?' . http_build_query($params) : '');
}

function badge_class_payment(string $type): string {
    return match ($type) {
        'active', 'paid', 'allowed' => 'badge green',
        'unpaid', 'overdue', 'cancelled', 'denied' => 'badge red',
        'pending' => 'badge orange',
        'inside' => 'badge red',
        'outside', 'assigned' => 'badge blue',
        'inactive', 'no-payment' => 'badge grey',
        default => 'badge grey',
    };
}


function rolling_subscription_status_payment(array $row): array {
    $isActive = strtolower((string)($row['subscription_status'] ?? '')) === 'active';
    $endDate = $row['end_date'] ?? null;
    $startDate = $row['start_date'] ?? null;

    if (!$isActive) {
        return [
            'key' => 'inactive',
            'label' => 'Inactive',
            'detail' => 'Subscription inactive',
            'access' => 'Denied',
            'days_left' => null,
            'next_due' => null,
        ];
    }

    if (empty($endDate)) {
        return [
            'key' => 'expired',
            'label' => 'Invalid',
            'detail' => 'End date not set',
            'access' => 'Denied',
            'days_left' => null,
            'next_due' => null,
        ];
    }

    try {
        $today = new DateTime(date('Y-m-d'));
        $end = new DateTime(date('Y-m-d', strtotime((string)$endDate)));
        $nextDue = (clone $end)->modify('+1 day');
        $daysLeft = (int)$today->diff($end)->format('%r%a');

        $paidUntilText = date('d M Y', strtotime((string)$endDate));
        $nextDueText = $nextDue->format('d M Y');

        if ($daysLeft < 0) {
            return [
                'key' => 'expired',
                'label' => 'Unpaid',
                'detail' => 'Expired on ' . $paidUntilText,
                'access' => 'Denied',
                'days_left' => $daysLeft,
                'next_due' => $nextDueText,
            ];
        }

        if ($daysLeft <= 3) {
            return [
                'key' => 'expiring',
                'label' => 'Paid',
                'detail' => 'Paid until ' . $paidUntilText . ' · Next due ' . $nextDueText,
                'access' => 'Allowed',
                'days_left' => $daysLeft,
                'next_due' => $nextDueText,
            ];
        }

        return [
            'key' => 'active',
            'label' => 'Paid',
            'detail' => 'Paid until ' . $paidUntilText . ' · Next due ' . $nextDueText,
            'access' => 'Allowed',
            'days_left' => $daysLeft,
            'next_due' => $nextDueText,
        ];
    } catch (Throwable $e) {
        return [
            'key' => 'expired',
            'label' => 'Unpaid',
            'detail' => 'Date error',
            'access' => 'Denied',
            'days_left' => null,
            'next_due' => null,
        ];
    }
}

function add_one_rolling_month_payment(PDO $pdo, array $assignment, bool $hasAssignmentsUpdatedAt): array {
    $assignmentId = (int)($assignment['id'] ?? 0);
    if ($assignmentId <= 0) {
        throw new Exception('Invalid subscription selected.');
    }

    $today = date('Y-m-d');
    $currentEnd = $assignment['end_date'] ?? null;

    if ($currentEnd && strtotime((string)$currentEnd) >= strtotime($today)) {
        // Early renewal: keep current paid days and add the next rolling month after the current end date.
        $periodStart = date('Y-m-d', strtotime((string)$currentEnd . ' +1 day'));
        $newEnd = date('Y-m-d', strtotime($periodStart . ' +1 month -1 day'));
        $startDateToSave = $assignment['start_date'] ?: $today;
    } else {
        // Expired or no end date: new month starts today.
        $periodStart = $today;
        $newEnd = date('Y-m-d', strtotime($today . ' +1 month -1 day'));
        $startDateToSave = $today;
    }

    $sql = "UPDATE resident_parking_assignments
            SET start_date = ?, end_date = ?, status = 'active'";
    $params = [$startDateToSave, $newEnd];

    if ($hasAssignmentsUpdatedAt) {
        $sql .= ", updated_at = NOW()";
    }

    $sql .= " WHERE id = ?";
    $params[] = $assignmentId;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return [
        'period_start' => $periodStart,
        'period_end' => $newEnd,
        'saved_start_date' => $startDateToSave,
    ];
}

function smartvms_payment_load_phpmailer(): bool {
    if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
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

    if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
        return true;
    }

    $manualFiles = [
        __DIR__ . '/../PHPMailer/src/Exception.php',
        __DIR__ . '/../PHPMailer/src/PHPMailer.php',
        __DIR__ . '/../PHPMailer/src/SMTP.php'
    ];

    if (
        file_exists($manualFiles[0]) &&
        file_exists($manualFiles[1]) &&
        file_exists($manualFiles[2])
    ) {
        require_once $manualFiles[0];
        require_once $manualFiles[1];
        require_once $manualFiles[2];
    }

    return class_exists('\PHPMailer\PHPMailer\PHPMailer');
}

function fetch_payment_reminder_details(
    PDO $pdo,
    int $assignmentId,
    string $currentRole,
    $currentApartmentId,
    bool $hasUserApartmentId,
    bool $hasSlotApartmentId,
    bool $hasUnitJoin
): array {
    [$scopeSql, $scopeParams] = assignment_scope_sql_payment($currentRole, $currentApartmentId, $hasUserApartmentId, $hasSlotApartmentId, $hasUnitJoin);

    $unitJoin = '';
    $unitSelect = "'-' AS unit_text";

    if ($hasUnitJoin) {
        $unitSelect = "COALESCE(CONCAT('Block ', u.block_no, ' / Floor ', u.floor_no, ' / Unit ', u.unit_no), '-') AS unit_text";
        $unitJoin = "
            LEFT JOIN (
                SELECT resident_id, MIN(unit_id) AS unit_id
                FROM resident_units
                WHERE status = 'active' OR status IS NULL
                GROUP BY resident_id
            ) ru ON ru.resident_id = resident.id
            LEFT JOIN units u ON u.id = ru.unit_id
        ";
    }

    $stmt = $pdo->prepare("
        SELECT
            a.id AS assignment_id,
            a.status AS subscription_status,
            a.monthly_fee,
            a.start_date,
            a.end_date,
            resident.id AS resident_id,
            resident.full_name AS resident_name,
            resident.email AS resident_email,
            {$unitSelect},
            rv.id AS vehicle_id,
            rv.plate_no,
            rv.vehicle_model,
            rv.vehicle_color,
            ps.id AS slot_id,
            ps.block_name,
            ps.slot_no,
            ps.apartment_id AS slot_apartment_id
        FROM resident_parking_assignments a
        LEFT JOIN users resident ON resident.id = a.resident_id
        LEFT JOIN resident_vehicles rv ON rv.id = a.vehicle_id
        LEFT JOIN parking_slots ps ON ps.id = a.slot_id
        {$unitJoin}
        WHERE a.id = ?
        {$scopeSql}
        LIMIT 1
    ");
    $stmt->execute(array_merge([$assignmentId], $scopeParams));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception('Subscription not found or not under your apartment.');
    }

    return $row;
}

function smartvms_payment_already_paid(PDO $pdo, int $assignmentId, string $billingMonth): bool {
    if (!has_table_payment($pdo, 'parking_payments')) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM parking_payments
        WHERE assignment_id = ?
        AND billing_month = ?
        AND LOWER(COALESCE(payment_status, '')) = 'paid'
    ");
    $stmt->execute([$assignmentId, $billingMonth]);

    return (int)$stmt->fetchColumn() > 0;
}

function smartvms_payment_reminder_portal_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/apartment/public/admin_parking_payment.php';
    $publicDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    return $scheme . '://' . $host . $publicDir . '/resident_dashboard.php';
}

function smartvms_send_payment_reminder_email(array $details, string $billingMonth, string $apartmentName, ?string &$mailError = null): bool {
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

    if (!smartvms_payment_load_phpmailer()) {
        $mailError = 'PHPMailer is not installed.';
        return false;
    }

    $amount = (float)($details['monthly_fee'] ?? 80.00);
    $monthText = date('F Y', strtotime($billingMonth . '-01'));
    $safeResidentName = htmlspecialchars($residentName ?: 'Resident', ENT_QUOTES, 'UTF-8');
    $safeApartment = htmlspecialchars($apartmentName, ENT_QUOTES, 'UTF-8');
    $safeMonth = htmlspecialchars($monthText, ENT_QUOTES, 'UTF-8');
    $safeAmount = htmlspecialchars('RM ' . number_format($amount, 2), ENT_QUOTES, 'UTF-8');
    $safePlate = htmlspecialchars(text_or_dash_payment($details['plate_no'] ?? '-'), ENT_QUOTES, 'UTF-8');
    $safeVehicle = htmlspecialchars(trim((string)(($details['vehicle_model'] ?? '') . ' ' . ($details['vehicle_color'] ?? ''))) ?: '-', ENT_QUOTES, 'UTF-8');
    $safeSlot = htmlspecialchars(trim((string)(($details['block_name'] ?? '-') . ' / ' . ($details['slot_no'] ?? '-'))), ENT_QUOTES, 'UTF-8');
    $safeUnit = htmlspecialchars(text_or_dash_payment($details['unit_text'] ?? '-'), ENT_QUOTES, 'UTF-8');
    $portalUrl = smartvms_payment_reminder_portal_url();
    $safePortalUrl = htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8');

    $subject = 'SmartVMS Parking Payment Reminder - ' . $monthText;

    $html = "
        <div style='margin:0;padding:0;background:#f4f6fb;font-family:Arial,sans-serif;color:#111827;'>
            <div style='max-width:640px;margin:0 auto;padding:28px 16px;'>
                <div style='background:#ffffff;border:1px solid #e5e7eb;border-radius:18px;overflow:hidden;box-shadow:0 18px 40px rgba(15,23,42,.10);'>
                    <div style='background:linear-gradient(135deg,#dc2626,#991b1b);padding:24px;color:white;'>
                        <h1 style='margin:0;font-size:24px;line-height:1.2;'>Monthly Parking Payment Reminder</h1>
                        <p style='margin:8px 0 0;color:#fee2e2;font-size:14px;'>SmartVMS Resident Parking</p>
                    </div>

                    <div style='padding:24px;'>
                        <p style='margin:0 0 14px;font-size:15px;'>Hello <strong>{$safeResidentName}</strong>,</p>
                        <p style='margin:0 0 18px;font-size:15px;line-height:1.6;'>
                            This is a reminder that your monthly resident parking payment for <strong>{$safeMonth}</strong> is still pending.
                            Please make the payment as soon as possible to keep your resident parking access active.
                        </p>

                        <table style='width:100%;border-collapse:collapse;margin:18px 0;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;'>
                            <tr>
                                <td style='padding:12px;background:#f8fafc;font-weight:bold;width:38%;'>Apartment</td>
                                <td style='padding:12px;'>{$safeApartment}</td>
                            </tr>
                            <tr>
                                <td style='padding:12px;background:#f8fafc;font-weight:bold;'>Unit</td>
                                <td style='padding:12px;'>{$safeUnit}</td>
                            </tr>
                            <tr>
                                <td style='padding:12px;background:#f8fafc;font-weight:bold;'>Vehicle</td>
                                <td style='padding:12px;'>{$safeVehicle}</td>
                            </tr>
                            <tr>
                                <td style='padding:12px;background:#f8fafc;font-weight:bold;'>Plate Number</td>
                                <td style='padding:12px;'>{$safePlate}</td>
                            </tr>
                            <tr>
                                <td style='padding:12px;background:#f8fafc;font-weight:bold;'>Parking Slot</td>
                                <td style='padding:12px;'>{$safeSlot}</td>
                            </tr>
                            <tr>
                                <td style='padding:12px;background:#f8fafc;font-weight:bold;'>Amount Due</td>
                                <td style='padding:12px;color:#dc2626;font-weight:bold;'>{$safeAmount}</td>
                            </tr>
                        </table>

                        <div style='margin:20px 0;padding:14px;border-radius:12px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;line-height:1.55;'>
                            If payment is not made, resident parking gate access may be denied until the monthly payment is verified.
                        </div>

                        <p style='margin:18px 0 0;color:#64748b;font-size:13px;line-height:1.6;'>
                            You may login to SmartVMS here:<br>
                            <a href='{$safePortalUrl}' style='color:#dc2626;'>{$safePortalUrl}</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    ";

    $plainText = "SmartVMS Monthly Parking Payment Reminder\n\n" .
        "Hello " . ($residentName ?: 'Resident') . ",\n\n" .
        "Your resident parking payment for {$monthText} is still pending.\n" .
        "Amount Due: RM " . number_format($amount, 2) . "\n" .
        "Plate Number: " . text_or_dash_payment($details['plate_no'] ?? '-') . "\n" .
        "Parking Slot: " . text_or_dash_payment(($details['block_name'] ?? '-') . ' / ' . ($details['slot_no'] ?? '-')) . "\n\n" .
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

function smartvms_insert_payment_reminder_notification(PDO $pdo, array $details, string $billingMonth): void {
    if (!has_table_payment($pdo, 'notifications')) {
        return;
    }

    $residentId = (int)($details['resident_id'] ?? 0);
    if ($residentId <= 0) {
        return;
    }

    $amount = (float)($details['monthly_fee'] ?? 80.00);
    $monthText = date('F Y', strtotime($billingMonth . '-01'));
    $plate = text_or_dash_payment($details['plate_no'] ?? '-');

    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications
            (user_id, title, message, type, link_url, is_read, created_at)
            VALUES (?, ?, ?, 'payment', NULL, 0, NOW())
        ");
        $stmt->execute([
            $residentId,
            'Monthly Parking Payment Reminder',
            'Your resident parking payment for ' . $monthText . ' is still unpaid. Plate: ' . $plate . '. Amount: RM ' . number_format($amount, 2) . '.'
        ]);
    } catch (Throwable $e) {
        // Email sending should not fail just because notification insert failed.
    }
}

$hasUserApartmentId = has_column_payment($pdo, 'users', 'apartment_id');
$hasUserContact = has_column_payment($pdo, 'users', 'contact_number');
$hasSlotApartmentId = has_column_payment($pdo, 'parking_slots', 'apartment_id');
$hasAssignmentsUpdatedAt = has_column_payment($pdo, 'resident_parking_assignments', 'updated_at');
$hasResidentUnits = has_table_payment($pdo, 'resident_units');
$hasUnits = has_table_payment($pdo, 'units');

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

$currentApartmentName = 'Ixoro Apartment';
$currentApartmentLabel = 'Apartment';
if ($currentApartmentId) {
    try {
        $stmt = $pdo->prepare("SELECT apartment_name FROM apartments WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$currentApartmentId]);
        $apartmentName = $stmt->fetchColumn();
        if ($apartmentName) {
            $currentApartmentName = (string)$apartmentName;
        }
    } catch (Throwable $e) {
        // keep fallback
    }
}

$hasUnitJoin = $hasResidentUnits && $hasUnits;

function assignment_scope_sql_payment(string $currentRole, $currentApartmentId, bool $hasUserApartmentId, bool $hasSlotApartmentId, bool $hasUnitJoin): array {
    if ($currentRole === 'superadmin' || empty($currentApartmentId)) {
        return ['', []];
    }

    $parts = [];
    $params = [];

    if ($hasUserApartmentId) {
        $parts[] = 'resident.apartment_id = ?';
        $params[] = (int)$currentApartmentId;
    }

    if ($hasSlotApartmentId) {
        $parts[] = 'ps.apartment_id = ?';
        $params[] = (int)$currentApartmentId;
    }

    if ($hasUnitJoin) {
        $parts[] = 'u.apartment_id = ?';
        $params[] = (int)$currentApartmentId;
    }

    if (!$parts) {
        return ['', []];
    }

    return [' AND (' . implode(' OR ', $parts) . ') ', $params];
}

function fetch_assignment_for_payment_action(PDO $pdo, int $assignmentId, string $currentRole, $currentApartmentId, bool $hasUserApartmentId, bool $hasSlotApartmentId, bool $hasUnitJoin): array {
    [$scopeSql, $scopeParams] = assignment_scope_sql_payment($currentRole, $currentApartmentId, $hasUserApartmentId, $hasSlotApartmentId, $hasUnitJoin);

    $unitJoin = '';
    if ($hasUnitJoin) {
        $unitJoin = "
            LEFT JOIN resident_units ru ON ru.resident_id = resident.id AND (ru.status = 'active' OR ru.status IS NULL)
            LEFT JOIN units u ON u.id = ru.unit_id
        ";
    }

    $stmt = $pdo->prepare("
        SELECT
            a.*,
            ps.apartment_id AS slot_apartment_id,
            ps.status AS slot_status
        FROM resident_parking_assignments a
        LEFT JOIN parking_slots ps ON ps.id = a.slot_id
        LEFT JOIN users resident ON resident.id = a.resident_id
        {$unitJoin}
        WHERE a.id = ?
        {$scopeSql}
        LIMIT 1
    ");
    $stmt->execute(array_merge([$assignmentId], $scopeParams));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception('Subscription not found or not under your apartment.');
    }

    return $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'mark_paid') {
                $assignmentId = (int)($_POST['assignment_id'] ?? 0);
                if ($assignmentId <= 0) {
                    throw new Exception('Invalid subscription selected.');
                }

                $assignment = fetch_assignment_for_payment_action($pdo, $assignmentId, $currentRole, $currentApartmentId, $hasUserApartmentId, $hasSlotApartmentId, $hasUnitJoin);
                $amount = (float)($assignment['monthly_fee'] ?? 80.00);

                $paymentMethod = trim((string)($_POST['payment_method'] ?? 'Manual Verification'));
                $allowedPaymentMethods = ['Manual Verification', 'Cash', 'Bank Transfer', 'E-Wallet', 'Card'];
                if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
                    $paymentMethod = 'Manual Verification';
                }

                // Rolling monthly logic:
                // If still active, extend from current end date.
                // If expired, start a new month from today.
                $period = add_one_rolling_month_payment($pdo, $assignment, $hasAssignmentsUpdatedAt);
                $billingMonth = date('Y-m', strtotime($period['period_start']));

                $stmt = $pdo->prepare("
                    INSERT INTO parking_payments
                    (assignment_id, resident_id, billing_month, amount, payment_status, payment_method, paid_at, created_at, vehicle_id, apartment_id)
                    VALUES (?, ?, ?, ?, 'paid', ?, NOW(), NOW(), ?, ?)
                ");
                $stmt->execute([
                    $assignmentId,
                    (int)($assignment['resident_id'] ?? 0),
                    $billingMonth,
                    $amount,
                    $paymentMethod,
                    (int)($assignment['vehicle_id'] ?? 0),
                    $assignment['slot_apartment_id'] ?? $currentApartmentId
                ]);

                if (function_exists('log_audit')) {
                    log_audit(
                        'RESIDENT_PARKING_PAYMENT_MARKED_PAID',
                        'Admin extended rolling resident parking subscription #' . $assignmentId .
                        ' until ' . $period['period_end']
                    );
                }

                $message = 'Payment verified. Access extended until ' . date('d M Y', strtotime($period['period_end'])) . '.';
            } elseif ($action === 'send_payment_reminder') {
                $assignmentId = (int)($_POST['assignment_id'] ?? 0);
                $reminderMonth = trim((string)($_POST['billing_month'] ?? date('Y-m')));

                if ($assignmentId <= 0) {
                    throw new Exception('Invalid subscription selected.');
                }

                if (!preg_match('/^\d{4}-\d{2}$/', $reminderMonth)) {
                    $reminderMonth = date('Y-m');
                }

                $details = fetch_payment_reminder_details(
                    $pdo,
                    $assignmentId,
                    $currentRole,
                    $currentApartmentId,
                    $hasUserApartmentId,
                    $hasSlotApartmentId,
                    $hasUnitJoin
                );

                if (strtolower((string)($details['subscription_status'] ?? '')) !== 'active') {
                    throw new Exception('Cannot send reminder because this subscription is not active.');
                }

                if (smartvms_payment_already_paid($pdo, $assignmentId, $reminderMonth)) {
                    throw new Exception('This resident has already paid for ' . $reminderMonth . '.');
                }

                $mailError = null;
                $emailSent = smartvms_send_payment_reminder_email($details, $reminderMonth, $currentApartmentName, $mailError);

                if (!$emailSent) {
                    throw new Exception('Reminder email could not be sent. ' . ($mailError ?: 'Please check SMTP settings.'));
                }

                smartvms_insert_payment_reminder_notification($pdo, $details, $reminderMonth);

                if (function_exists('log_audit')) {
                    log_audit('RESIDENT_PARKING_PAYMENT_REMINDER_SENT', 'Admin sent monthly parking payment reminder for assignment #' . $assignmentId . ' (' . $reminderMonth . ').');
                }

                $message = 'Payment reminder email sent to ' . text_or_dash_payment($details['resident_email'] ?? '') . '.';
            } elseif ($action === 'blacklist_plate') {
                $assignmentId = (int)($_POST['assignment_id'] ?? 0);
                $blacklistReason = trim((string)($_POST['blacklist_reason'] ?? 'Unpaid resident parking payment'));

                if ($assignmentId <= 0) {
                    throw new Exception('Invalid subscription selected.');
                }

                $details = fetch_payment_reminder_details(
                    $pdo,
                    $assignmentId,
                    $currentRole,
                    $currentApartmentId,
                    $hasUserApartmentId,
                    $hasSlotApartmentId,
                    $hasUnitJoin
                );

                $plateNo = normalize_plate_payment((string)($details['plate_no'] ?? ''));
                if ($plateNo === '') {
                    throw new Exception('This subscription does not have a valid plate number.');
                }

                blacklist_plate_payment($pdo, $plateNo, $blacklistReason, (int)$currentUserId);

                if (function_exists('log_audit')) {
                    log_audit('RESIDENT_PARKING_PLATE_BLACKLISTED', 'Admin blacklisted resident plate ' . $plateNo . ' from payment verification page.');
                }

                $message = 'Plate ' . $plateNo . ' has been blacklisted.';
            } elseif ($action === 'bulk_blacklist_plates') {
                $assignmentIds = $_POST['assignment_ids'] ?? [];
                if (!is_array($assignmentIds)) {
                    $assignmentIds = [];
                }

                $assignmentIds = array_values(array_unique(array_filter(array_map('intval', $assignmentIds))));
                if (!$assignmentIds) {
                    throw new Exception('Please select at least one resident plate to blacklist.');
                }

                $blacklistedPlates = [];
                foreach ($assignmentIds as $bulkAssignmentId) {
                    $details = fetch_payment_reminder_details(
                        $pdo,
                        $bulkAssignmentId,
                        $currentRole,
                        $currentApartmentId,
                        $hasUserApartmentId,
                        $hasSlotApartmentId,
                        $hasUnitJoin
                    );

                    $plateNo = normalize_plate_payment((string)($details['plate_no'] ?? ''));
                    if ($plateNo === '') {
                        continue;
                    }

                    blacklist_plate_payment($pdo, $plateNo, 'Unpaid resident parking payment', (int)$currentUserId);
                    $blacklistedPlates[] = $plateNo;
                }

                $blacklistedPlates = array_values(array_unique($blacklistedPlates));
                if (!$blacklistedPlates) {
                    throw new Exception('No valid plate number was selected.');
                }

                if (function_exists('log_audit')) {
                    log_audit('RESIDENT_PARKING_BULK_BLACKLISTED', 'Admin blacklisted resident plates: ' . implode(', ', $blacklistedPlates));
                }

                $message = count($blacklistedPlates) . ' plate(s) blacklisted: ' . implode(', ', $blacklistedPlates) . '.';
            } elseif ($action === 'cancel_subscription') {
                $assignmentId = (int)($_POST['assignment_id'] ?? 0);
                $cancelReason = trim((string)($_POST['cancel_reason'] ?? ''));

                if ($assignmentId <= 0) {
                    throw new Exception('Invalid subscription selected.');
                }
                if ($cancelReason === '') {
                    throw new Exception('Please enter a cancel reason.');
                }

                $assignment = fetch_assignment_for_payment_action($pdo, $assignmentId, $currentRole, $currentApartmentId, $hasUserApartmentId, $hasSlotApartmentId, $hasUnitJoin);

                $sql = "UPDATE resident_parking_assignments SET status = 'inactive'";
                if ($hasAssignmentsUpdatedAt) {
                    $sql .= ", updated_at = NOW()";
                }
                $sql .= " WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$assignmentId]);

                $stmt = $pdo->prepare("
                    UPDATE parking_slots
                    SET status = 'available'
                    WHERE id = ?
                    AND status <> 'occupied'
                ");
                $stmt->execute([(int)($assignment['slot_id'] ?? 0)]);

                if (function_exists('log_audit')) {
                    log_audit('RESIDENT_PARKING_SUBSCRIPTION_CANCELLED', 'Admin cancelled resident parking subscription #' . $assignmentId . '. Reason: ' . $cancelReason);
                }

                $message = 'Subscription cancelled successfully.';
            }
        } catch (Exception $e) {
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
$paymentFilter = trim($_GET['payment'] ?? '');
$subscriptionFilter = trim($_GET['subscription'] ?? 'active');
$billingMonth = $_GET['month'] ?? date('Y-m');

if (!preg_match('/^\d{4}-\d{2}$/', $billingMonth)) {
    $billingMonth = date('Y-m');
}
if (!in_array($paymentFilter, ['', 'paid', 'unpaid'], true)) {
    $paymentFilter = '';
}
if (!in_array($subscriptionFilter, ['', 'active', 'inactive'], true)) {
    $subscriptionFilter = 'active';
}

$unitJoinSql = '';
$unitSelectSql = "'-' AS unit_text";

if ($hasUnitJoin) {
    $unitSelectSql = "COALESCE(CONCAT('Block ', u.block_no, ' / Floor ', u.floor_no, ' / Unit ', u.unit_no), '-') AS unit_text";
    $unitJoinSql = "
        LEFT JOIN (
            SELECT resident_id, MIN(unit_id) AS unit_id
            FROM resident_units
            WHERE status = 'active' OR status IS NULL
            GROUP BY resident_id
        ) ru ON ru.resident_id = resident.id
        LEFT JOIN units u ON u.id = ru.unit_id
    ";
}

$whereParts = ["resident.role = 'resident'"];
$params = [$billingMonth];

if ($subscriptionFilter !== '') {
    $whereParts[] = "assign.status = ?";
    $params[] = $subscriptionFilter;
}

[$scopeSql, $scopeParams] = assignment_scope_sql_payment($currentRole, $currentApartmentId, $hasUserApartmentId, $hasSlotApartmentId, $hasUnitJoin);
if ($scopeSql !== '') {
    $whereParts[] = trim(str_replace(['AND (', ')'], ['(', ')'], $scopeSql));
    $params = array_merge($params, $scopeParams);
}

if ($search !== '') {
    $term = '%' . $search . '%';
    $searchParts = [
        'resident.full_name LIKE ?',
        'resident.email LIKE ?',
        'rv.plate_no LIKE ?',
        'ps.slot_no LIKE ?',
        'ps.block_name LIKE ?'
    ];
    $searchParams = [$term, $term, $term, $term, $term];

    if ($hasUserContact) {
        $searchParts[] = 'resident.contact_number LIKE ?';
        $searchParams[] = $term;
    }

    if ($hasUnitJoin) {
        $searchParts[] = "CONCAT('Block ', u.block_no, ' / Floor ', u.floor_no, ' / Unit ', u.unit_no) LIKE ?";
        $searchParams[] = $term;
    }

    $whereParts[] = '(' . implode(' OR ', $searchParts) . ')';
    $params = array_merge($params, $searchParams);
}

if ($paymentFilter === 'paid') {
    $whereParts[] = "LOWER(COALESCE(pay.payment_status, '')) = 'paid'";
} elseif ($paymentFilter === 'unpaid') {
    $whereParts[] = "LOWER(COALESCE(pay.payment_status, 'unpaid')) <> 'paid'";
}

$whereSql = 'WHERE ' . implode(' AND ', $whereParts);

$contactSelect = $hasUserContact ? "resident.contact_number" : "NULL AS contact_number";

$rows = safe_rows_payment($pdo, "
    SELECT
        assign.id AS assignment_id,
        assign.status AS subscription_status,
        assign.monthly_fee,
        assign.start_date,
        assign.end_date,
        resident.id AS resident_id,
        resident.full_name AS resident_name,
        resident.email AS resident_email,
        {$contactSelect},
        {$unitSelectSql},
        rv.id AS vehicle_id,
        rv.plate_no,
        rv.vehicle_model,
        rv.vehicle_color,
        ps.id AS slot_id,
        ps.block_name,
        ps.slot_no,
        ps.status AS slot_status,
        pay.id AS payment_id,
        pay.payment_status,
        pay.amount AS paid_amount,
        pay.billing_month,
        pay.paid_at
    FROM resident_parking_assignments assign
    INNER JOIN users resident
        ON resident.id = assign.resident_id
    LEFT JOIN resident_vehicles rv
        ON rv.id = assign.vehicle_id
    LEFT JOIN parking_slots ps
        ON ps.id = assign.slot_id
    LEFT JOIN (
        SELECT p1.*
        FROM parking_payments p1
        INNER JOIN (
            SELECT assignment_id, MAX(id) AS latest_payment_id
            FROM parking_payments
            WHERE billing_month = ?
            GROUP BY assignment_id
        ) latest_pay
            ON latest_pay.latest_payment_id = p1.id
    ) pay
        ON pay.assignment_id = assign.id
    {$unitJoinSql}
    {$whereSql}
    ORDER BY resident.full_name ASC, ps.block_name ASC, ps.slot_no ASC
    LIMIT 800
", $params);

usort($rows, function ($a, $b) {
    $payA = strtolower((string)($a['payment_status'] ?? '')) === 'paid' ? 1 : 0;
    $payB = strtolower((string)($b['payment_status'] ?? '')) === 'paid' ? 1 : 0;

    if ($payA !== $payB) {
        return $payA <=> $payB; // unpaid first
    }

    $nameCompare = strnatcasecmp((string)($a['resident_name'] ?? ''), (string)($b['resident_name'] ?? ''));
    if ($nameCompare !== 0) {
        return $nameCompare;
    }

    return strnatcasecmp((string)($a['plate_no'] ?? ''), (string)($b['plate_no'] ?? ''));
});

$totalSubscriptions = count($rows);
$activeSubscriptions = 0;
$paidThisMonth = 0;
$unpaidThisMonth = 0;
$carInside = 0;

foreach ($rows as $row) {
    $rollingStatus = rolling_subscription_status_payment($row);

    if (strtolower((string)$row['subscription_status']) === 'active') {
        $activeSubscriptions++;
    }

    if (in_array($rollingStatus['key'], ['active', 'expiring'], true)) {
        $paidThisMonth++;
    } else {
        $unpaidThisMonth++;
    }

    if (strtolower((string)$row['slot_status']) === 'occupied') {
        $carInside++;
    }
}

$profileInitial = strtoupper(substr(trim($currentEmail ?: 'A'), 0, 1));
if ($profileInitial === '') {
    $profileInitial = 'A';
}

$recordRows = [];
$recordTotalAmount = 0.0;

if (has_table_payment($pdo, 'parking_payments')) {
    $recordWhereParts = ["LOWER(COALESCE(pp.payment_status, '')) = 'paid'"];
    $recordParams = [];

    if ($currentRole !== 'superadmin' && !empty($currentApartmentId)) {
        $recordScopeParts = [];

        if (has_column_payment($pdo, 'parking_payments', 'apartment_id')) {
            $recordScopeParts[] = "pp.apartment_id = ?";
            $recordParams[] = $currentApartmentId;
        }

        if ($hasSlotApartmentId) {
            $recordScopeParts[] = "ps.apartment_id = ?";
            $recordParams[] = $currentApartmentId;
        }

        if ($hasUserApartmentId) {
            $recordScopeParts[] = "resident.apartment_id = ?";
            $recordParams[] = $currentApartmentId;
        }

        if ($recordScopeParts) {
            $recordWhereParts[] = '(' . implode(' OR ', $recordScopeParts) . ')';
        }
    }

    $recordWhereSql = 'WHERE ' . implode(' AND ', $recordWhereParts);

    $recordMethodSelect = has_column_payment($pdo, 'parking_payments', 'payment_method')
        ? "COALESCE(NULLIF(pp.payment_method, ''), 'Manual Verification') AS record_payment_method"
        : "'Manual Verification' AS record_payment_method";

    $recordPaidAtSelect = has_column_payment($pdo, 'parking_payments', 'paid_at')
        ? "pp.paid_at"
        : "pp.created_at";

    $recordBillingMonthSelect = has_column_payment($pdo, 'parking_payments', 'billing_month')
        ? "pp.billing_month"
        : "DATE_FORMAT({$recordPaidAtSelect}, '%Y-%m')";

    $recordAmountSelect = has_column_payment($pdo, 'parking_payments', 'amount')
        ? "pp.amount"
        : "0 AS amount";

    $recordVehicleJoin = has_column_payment($pdo, 'parking_payments', 'vehicle_id')
        ? "LEFT JOIN resident_vehicles rv ON rv.id = COALESCE(pp.vehicle_id, assign.vehicle_id)"
        : "LEFT JOIN resident_vehicles rv ON rv.id = assign.vehicle_id";

    $recordRows = safe_rows_payment($pdo, "
        SELECT
            pp.id AS payment_id,
            {$recordBillingMonthSelect} AS record_billing_month,
            {$recordAmountSelect},
            pp.payment_status AS record_payment_status,
            {$recordMethodSelect},
            {$recordPaidAtSelect} AS record_paid_at,
            resident.full_name AS record_resident_name,
            resident.email AS record_resident_email,
            {$contactSelect},
            {$unitSelectSql},
            rv.plate_no,
            rv.vehicle_model,
            rv.vehicle_color,
            ps.block_name,
            ps.slot_no
        FROM parking_payments pp
        LEFT JOIN resident_parking_assignments assign
            ON assign.id = pp.assignment_id
        LEFT JOIN users resident
            ON resident.id = pp.resident_id
        {$recordVehicleJoin}
        LEFT JOIN parking_slots ps
            ON ps.id = assign.slot_id
        {$unitJoinSql}
        {$recordWhereSql}
        ORDER BY {$recordPaidAtSelect} DESC, pp.id DESC
        LIMIT 120
    ", $recordParams);

    foreach ($recordRows as $recordRow) {
        $recordTotalAmount += (float)($recordRow['amount'] ?? 0);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Resident Parking Payments | SmartVMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary: #dc2626;
            --primary-dark: #b91c1c;
            --text: #0f172a;
            --muted: #64748b;
            --line: #e5e7eb;
            --shadow: 0 18px 45px rgba(15, 23, 42, .08);
            --green-bg: #dcfce7;
            --green: #166534;
            --blue-bg: #dbeafe;
            --blue: #1d4ed8;
            --red-bg: #fee2e2;
            --red: #b91c1c;
            --orange-bg: #ffedd5;
            --orange: #c2410c;
            --grey-bg: #f1f5f9;
            --grey: #475569;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            height: 100vh;
            overflow: hidden;
            font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
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

        .sidebar {
            background: rgba(255,255,255,.94);
            border-right: 1px solid var(--line);
            padding: 26px 18px;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 26px;
        }

        .brand-icon {
            width: 46px;
            height: 46px;
            border-radius: 16px;
            background: linear-gradient(145deg, var(--primary), var(--primary-dark));
            color: white;
            display: grid;
            place-items: center;
            box-shadow: 0 14px 28px rgba(220, 38, 38, .22);
        }

        .brand-title {
            font-size: 1.05rem;
            font-weight: 950;
            letter-spacing: -.04em;
        }

        .brand-title span { color: var(--primary); }

        .brand-sub {
            color: var(--muted);
            text-transform: uppercase;
            font-size: .72rem;
            font-weight: 900;
            letter-spacing: .08em;
        }

        .tenant-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px;
            border: 1px solid #fecaca;
            border-radius: 18px;
            background: #fff7f7;
            margin-bottom: 20px;
        }

        .tenant-icon {
            width: 40px;
            height: 40px;
            border-radius: 14px;
            background: #fee2e2;
            color: var(--primary);
            display: grid;
            place-items: center;
        }

        .tenant-label {
            color: #64748b;
            text-transform: uppercase;
            font-size: .66rem;
            font-weight: 950;
            letter-spacing: .08em;
        }

        .tenant-name {
            font-weight: 900;
            font-size: .86rem;
        }

        .side-section {
            margin: 18px 8px 8px;
            color: #94a3b8;
            text-transform: uppercase;
            font-size: .72rem;
            font-weight: 950;
            letter-spacing: .08em;
        }

        .side-nav {
            display: grid;
            gap: 6px;
        }

        .side-link,
        .side-link.parent,
        .submenu a {
            text-decoration: none;
            border: 0;
            width: 100%;
            background: transparent;
            color: #475569;
            border-radius: 14px;
            padding: 11px 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: .86rem;
            font-weight: 850;
            cursor: pointer;
            text-align: left;
        }

        .side-link:hover,
        .side-link.current {
            background: #fff1f2;
            color: var(--primary);
        }

        .side-link.parent {
            justify-content: space-between;
        }

        .side-link.parent .left {
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .chevron {
            font-size: .72rem;
            transition: transform .18s ease;
        }

        .side-parent.open .chevron {
            transform: rotate(180deg);
        }

        .submenu {
            display: none;
            padding-left: 22px;
            margin: 4px 0 8px 12px;
            border-left: 1px solid #fecaca;
        }

        .side-parent.open .submenu {
            display: grid;
            gap: 3px;
        }

        .submenu a {
            padding: 9px 10px;
            font-size: .8rem;
            border-radius: 11px;
        }

        .submenu a.sub-active {
            color: var(--primary);
            background: #fff1f2;
        }

        .logout-link {
            margin-top: 16px;
            background: #fff1f2;
            color: #991b1b;
        }

        .main-content {
            padding: 24px 28px 26px;
            min-width: 0;
            height: 100vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 14px;
        }

        .eyebrow {
            color: var(--primary);
            text-transform: uppercase;
            font-size: .74rem;
            font-weight: 950;
            letter-spacing: .12em;
            margin-bottom: 4px;
        }

        h1 {
            margin: 0;
            font-size: 1.7rem;
            line-height: 1.06;
            letter-spacing: -.065em;
            font-weight: 950;
        }

        .page-sub {
            margin: 6px 0 0;
            color: #475569;
            font-weight: 800;
            max-width: 760px;
            font-size: .84rem;
            line-height: 1.38;
        }

        .top-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .back-btn,
        .profile-dot {
            height: 44px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            text-decoration: none;
            font-weight: 950;
        }

        .back-btn {
            padding: 0 16px;
            gap: 8px;
            color: white;
            background: linear-gradient(145deg, var(--primary), var(--primary-dark));
            box-shadow: 0 14px 24px rgba(220, 38, 38, .18);
        }

        .record-btn {
            height: 44px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 0 16px;
            background: #fff;
            color: var(--primary);
            border: 1px solid #fecaca;
            text-decoration: none;
            font-weight: 950;
            box-shadow: 0 12px 24px rgba(15,23,42,.06);
        }

        .record-btn:hover {
            background: #fff5f5;
            border-color: #fca5a5;
        }

        .profile-dot {
            width: 44px;
            color: white;
            background: linear-gradient(145deg, var(--primary), var(--primary-dark));
        }

        .alert {
            padding: 11px 14px;
            border-radius: 16px;
            margin: 0 0 12px;
            font-weight: 850;
            line-height: 1.25;
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(150px, 1fr));
            gap: 14px;
            margin: 14px 0;
        }

        .stat-card {
            background: rgba(255,255,255,.96);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 18px;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            min-height: 84px;
        }

        .stat-num {
            font-size: 1.65rem;
            font-weight: 950;
            letter-spacing: -.05em;
        }

        .stat-label {
            color: #64748b;
            text-transform: uppercase;
            font-size: .7rem;
            font-weight: 950;
            letter-spacing: .06em;
            margin-top: 3px;
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            background: #fee2e2;
            color: var(--primary);
        }

        .stat-icon.green { background: #dcfce7; color: #16a34a; }
        .stat-icon.blue { background: #dbeafe; color: #2563eb; }
        .stat-icon.orange { background: #ffedd5; color: #f97316; }

        .payment-hero {
            position: relative;
            display: grid;
            grid-template-columns: minmax(0, 1.05fr) minmax(280px, .8fr);
            gap: 14px;
            min-height: 142px;
            margin-bottom: 12px;
            padding: 14px 20px;
            border-radius: 24px;
            border: 1px solid rgba(229,231,235,.95);
            background:
                radial-gradient(circle at 18% 12%, rgba(220,38,38,.12), transparent 34%),
                radial-gradient(circle at 84% 48%, rgba(254,202,202,.45), transparent 30%),
                linear-gradient(135deg, rgba(255,255,255,.98), rgba(255,247,247,.9));
            box-shadow: var(--shadow);
            overflow: hidden;
            flex: 0 0 auto;
        }

        .payment-hero::before {
            content: "";
            position: absolute;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            left: -85px;
            top: -90px;
            background: rgba(220,38,38,.09);
        }

        .payment-hero::after {
            content: "";
            position: absolute;
            right: 76px;
            top: 48px;
            width: 170px;
            height: 170px;
            border-radius: 999px;
            background: rgba(254,226,226,.52);
            z-index: 0;
        }

        .payment-hero-info,
        .payment-illustration {
            position: relative;
            z-index: 2;
        }

        .payment-hero-info {
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-width: 0;
        }

        .hero-kicker {
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: .13em;
            font-size: .66rem;
            font-weight: 950;
            margin-bottom: 6px;
        }

        .hero-big {
            color: #06143a;
            font-size: clamp(2.6rem, 4.2vw, 3.9rem);
            line-height: .9;
            font-weight: 950;
            letter-spacing: -.09em;
            margin-bottom: 6px;
        }

        .hero-subline {
            color: var(--primary);
            font-size: .82rem;
            font-weight: 950;
            margin-bottom: 10px;
        }

        .hero-mini-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(110px, 1fr));
            gap: 8px;
            max-width: 500px;
        }

        .hero-mini-card {
            background: rgba(255,255,255,.86);
            border: 1px solid #e5e7eb;
            border-radius: 15px;
            padding: 9px 12px;
            box-shadow: 0 10px 22px rgba(15,23,42,.05);
        }

        .hero-mini-card strong {
            display: block;
            color: #0f172a;
            font-size: 1.05rem;
            line-height: 1;
            font-weight: 950;
            margin-bottom: 4px;
        }

        .hero-mini-card strong.red { color: var(--primary); }
        .hero-mini-card strong.green { color: #16a34a; }
        .hero-mini-card strong.blue { color: #2563eb; }

        .hero-mini-card span {
            display: block;
            color: #64748b;
            font-size: .58rem;
            text-transform: uppercase;
            letter-spacing: .07em;
            font-weight: 950;
        }

        .payment-illustration {
            min-height: 144px;
            border-radius: 0;
            background: transparent;
            border: 0;
            overflow: visible;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 6px;
        }

        .payment-illustration::before {
            content: "";
            position: absolute;
            left: 44px;
            right: 44px;
            bottom: 16px;
            height: 12px;
            border-radius: 999px;
            background: linear-gradient(90deg, transparent, rgba(15,23,42,.15), transparent);
            filter: blur(2px);
        }

        .payment-art {
            position: relative;
            width: min(420px, 100%);
            height: 136px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .invoice-card {
            position: absolute;
            right: 80px;
            top: 3px;
            width: 132px;
            height: 126px;
            border-radius: 18px;
            background: #fff;
            border: 5px solid #1f2937;
            box-shadow: 0 16px 32px rgba(15,23,42,.14);
            padding: 18px 14px;
        }

        .invoice-card::before {
            content: "";
            position: absolute;
            left: 18px;
            top: -16px;
            width: 74px;
            height: 28px;
            border-radius: 12px 12px 7px 7px;
            background: linear-gradient(145deg, #ef4444, #dc2626);
            box-shadow: 0 10px 20px rgba(220,38,38,.18);
        }

        .invoice-dollar {
            position: absolute;
            left: 14px;
            top: 28px;
            width: 34px;
            height: 34px;
            border-radius: 999px;
            background: linear-gradient(145deg,#ef4444,#dc2626);
            color: white;
            font-weight: 950;
            display: grid;
            place-items: center;
            font-size: 1.15rem;
        }

        .invoice-line {
            position: absolute;
            left: 58px;
            right: 16px;
            height: 7px;
            border-radius: 999px;
            background: #d1d5db;
        }

        .invoice-line.one { top: 34px; }
        .invoice-line.two { top: 50px; width: 46px; }
        .invoice-line.three { top: 76px; left: 18px; right: 18px; }
        .invoice-line.four { top: 94px; left: 18px; right: 34px; }

        .money-ticket {
            position: absolute;
            left: 72px;
            bottom: 10px;
            width: 160px;
            height: 56px;
            border-radius: 15px;
            background: linear-gradient(145deg,#fff,#fee2e2);
            border: 1px solid #fecaca;
            box-shadow: 0 14px 28px rgba(220,38,38,.16);
            display: grid;
            grid-template-columns: 46px 1fr;
            align-items: center;
            padding: 8px;
        }

        .money-icon {
            width: 34px;
            height: 34px;
            border-radius: 12px;
            background: #fee2e2;
            color: var(--primary);
            display: grid;
            place-items: center;
            font-size: 1.12rem;
        }

        .money-title {
            font-size: .5rem;
            color: var(--primary);
            font-weight: 950;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .money-line {
            height: 5px;
            border-radius: 999px;
            background: #fecaca;
            margin-top: 5px;
            width: 74px;
        }

        .payment-check {
            position: absolute;
            right: 38px;
            bottom: 8px;
            width: 58px;
            height: 58px;
            border-radius: 999px;
            background: linear-gradient(145deg,#86efac,#22c55e);
            border: 6px solid #fff;
            box-shadow: 0 16px 30px rgba(34,197,94,.24);
            display: grid;
            place-items: center;
            color: white;
            font-size: 1.55rem;
        }

        .payment-coin {
            position: absolute;
            right: 18px;
            top: 22px;
            width: 38px;
            height: 38px;
            border-radius: 999px;
            background: linear-gradient(145deg,#fde68a,#f59e0b);
            color: #92400e;
            display: grid;
            place-items: center;
            font-weight: 950;
            box-shadow: 0 12px 24px rgba(245,158,11,.22);
        }

        .payment-dots {
            position: absolute;
            right: 82px;
            top: 74px;
            width: 48px;
            height: 36px;
            background-image: radial-gradient(circle,#fecaca 2px,transparent 2px);
            background-size: 16px 16px;
            opacity: .85;
        }

        .panel {
            background: rgba(255,255,255,.97);
            border: 1px solid rgba(229,231,235,.95);
            border-radius: 20px;
            box-shadow: var(--shadow);
            overflow: hidden;
            flex: 1 1 auto;
            min-height: 0;
            display: flex;
            flex-direction: column;
        }

        .panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 18px;
            border-bottom: 1px solid var(--line);
            gap: 12px;
        }

        .panel-title {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-weight: 950;
            font-size: 1.03rem;
        }

        .panel-title i { color: var(--primary); }

        .billing-chip {
            color: #475569;
            font-weight: 900;
            font-size: .84rem;
        }

        .filters {
            padding: 14px 18px;
            display: grid;
            grid-template-columns: minmax(220px, 1fr) 170px 170px 130px 42px 42px 42px;
            gap: 10px;
            align-items: end;
            border-bottom: 1px solid var(--line);
        }

        .field label {
            display: block;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .06em;
            font-size: .68rem;
            font-weight: 950;
            margin-bottom: 6px;
        }

        .filters input,
        .filters select,
        .cancel-input {
            width: 100%;
            min-height: 42px;
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 0 12px;
            font-weight: 850;
            outline: none;
            background: white;
        }

        .filters input:focus,
        .filters select:focus,
        .cancel-input:focus {
            border-color: #fecaca;
            box-shadow: 0 0 0 4px rgba(220, 38, 38, .08);
        }

        .icon-btn {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: white;
            color: #64748b;
            display: grid;
            place-items: center;
            text-decoration: none;
            cursor: pointer;
            font-weight: 950;
        }

        .icon-btn.primary {
            background: var(--primary);
            color: white;
            border-color: transparent;
            box-shadow: 0 12px 24px rgba(220,38,38,.18);
        }

        .table-wrap {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            overflow-x: auto;
            max-height: none;
        }

        .table-wrap::-webkit-scrollbar {
            width: 9px;
            height: 9px;
        }

        .table-wrap::-webkit-scrollbar-track {
            background: #fff7f7;
        }

        .table-wrap::-webkit-scrollbar-thumb {
            background: #fecaca;
            border-radius: 999px;
        }

        .table-wrap::-webkit-scrollbar-thumb:hover {
            background: #fca5a5;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 930px;
        }

        th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f8fafc;
            color: #64748b;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: .06em;
            font-size: .68rem;
            font-weight: 950;
            padding: 12px 14px;
            border-bottom: 1px solid var(--line);
        }

        td {
            padding: 13px 14px;
            border-bottom: 1px solid #eef2f7;
            vertical-align: top;
            font-size: .82rem;
        }

        tr:hover td {
            background: #fffafa;
        }
        tr.clickable-row {
            cursor: pointer;
        }

        tr.clickable-row:hover td {
            background: #fff7f7;
        }

        .profile-hint {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 6px;
            color: var(--primary);
            font-size: .72rem;
            font-weight: 950;
        }

        .main-text {
            font-weight: 950;
            color: #0f172a;
            margin-bottom: 4px;
            letter-spacing: -.02em;
        }

        .sub-text {
            color: #475569;
            font-weight: 800;
            line-height: 1.35;
            font-size: .8rem;
        }

        .plate-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #0f172a;
            color: white;
            border: 1px solid #334155;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.12);
            border-radius: 10px;
            padding: 6px 10px;
            font-weight: 950;
            letter-spacing: .04em;
            margin-bottom: 6px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 6px 10px;
            font-size: .7rem;
            font-weight: 950;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .badge.green { background: var(--green-bg); color: var(--green); }
        .badge.blue { background: var(--blue-bg); color: var(--blue); }
        .badge.red { background: var(--red-bg); color: var(--red); }
        .badge.orange { background: var(--orange-bg); color: var(--orange); }
        .badge.grey { background: var(--grey-bg); color: var(--grey); }

        .action-area {
            min-width: 160px;
        }

        .action-row {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 8px;
        }

        .btn {
            border: 0;
            border-radius: 12px;
            min-height: 38px;
            padding: 0 13px;
            font-weight: 950;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            font-size: .78rem;
        }

        .btn.primary {
            background: var(--primary);
            color: white;
            box-shadow: 0 12px 22px rgba(220,38,38,.16);
        }

        .btn.light {
            background: #fff1f2;
            color: #991b1b;
        }

        .btn.green {
            background: #dcfce7;
            color: #166534;
        }

        .btn.orange {
            background: #ffedd5;
            color: #9a3412;
        }

        .btn.parking {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .payment-action-stack {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
        }

        .payment-action-stack .btn {
            min-width: 142px;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .more-menu {
            position: relative;
            display: inline-flex;
            justify-content: flex-end;
        }

        .more-btn {
            width: 38px;
            height: 38px;
            border: 1px solid var(--line);
            border-radius: 13px;
            background: #fff;
            color: #475569;
            display: grid;
            place-items: center;
            cursor: pointer;
            box-shadow: 0 10px 20px rgba(15,23,42,.05);
        }

        .more-btn:hover,
        .more-menu.open .more-btn {
            color: var(--primary);
            border-color: #fecaca;
            background: #fff7f7;
        }

        .more-panel {
            position: absolute;
            right: 0;
            top: calc(100% + 8px);
            width: 190px;
            padding: 8px;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 20px 45px rgba(15,23,42,.16);
            display: none;
            z-index: 20;
        }

        .more-menu.open .more-panel {
            display: grid;
            gap: 6px;
        }

        .menu-action {
            width: 100%;
            border: 0;
            border-radius: 12px;
            min-height: 36px;
            padding: 0 10px;
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f8fafc;
            color: #0f172a;
            font-weight: 950;
            font-size: .76rem;
            cursor: pointer;
            text-align: left;
        }

        .menu-action.email {
            background: #ffedd5;
            color: #9a3412;
        }

        .menu-action.paid,
        .menu-action.blacklist {
            background: #fff;
            color: #0f172a;
            border: 1px solid var(--line);
        }

        .menu-action.paid:hover,
        .menu-action.blacklist:hover {
            background: #f8fafc;
            color: var(--primary);
        }

        .menu-action:hover {
            filter: brightness(.98);
        }

        .toolbar-more-menu {
            position: relative;
            width: 42px;
            height: 42px;
        }

        .toolbar-more-btn {
            width: 42px;
            height: 42px;
        }

        .toolbar-more-panel {
            position: absolute;
            right: 0;
            top: calc(100% + 8px);
            width: 220px;
            padding: 8px;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 20px 45px rgba(15,23,42,.16);
            display: none;
            z-index: 50;
        }

        .toolbar-more-menu.open .toolbar-more-panel {
            display: grid;
            gap: 6px;
        }

        .toolbar-menu-action {
            width: 100%;
            border: 0;
            border-radius: 12px;
            min-height: 38px;
            padding: 0 10px;
            display: flex;
            align-items: center;
            gap: 9px;
            background: #f8fafc;
            color: #0f172a;
            font-weight: 950;
            font-size: .78rem;
            cursor: pointer;
            text-align: left;
        }

        .toolbar-menu-action:hover {
            background: #fff7f7;
            color: var(--primary);
        }

        .toolbar-menu-action.paid,
        .toolbar-menu-action.blacklist {
            background: #fff;
            color: #0f172a;
        }

        .toolbar-menu-action.paid:hover,
        .toolbar-menu-action.blacklist:hover {
            background: #f8fafc;
            color: var(--primary);
        }

        .quick-action-modal {
            position: fixed;
            inset: 0;
            z-index: 10000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(15,23,42,.46);
            backdrop-filter: blur(6px);
        }

        .quick-action-modal.show {
            display: flex;
        }

        .quick-action-box {
            width: min(980px, 94vw);
            max-height: 84vh;
            background: rgba(255,255,255,.98);
            border: 1px solid rgba(229,231,235,.95);
            border-radius: 24px;
            box-shadow: 0 28px 70px rgba(15,23,42,.24);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .quick-action-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 22px;
            border-bottom: 1px solid var(--line);
            background:
                radial-gradient(circle at 86% 18%, rgba(220,38,38,.12), transparent 30%),
                linear-gradient(135deg, #fff, #fff7f7);
        }

        .quick-action-kicker {
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: .12em;
            font-size: .68rem;
            font-weight: 950;
            margin-bottom: 5px;
        }

        .quick-action-title {
            font-size: 1.35rem;
            line-height: 1.05;
            font-weight: 950;
            letter-spacing: -.05em;
        }

        .quick-action-sub {
            margin-top: 6px;
            color: #64748b;
            font-size: .8rem;
            font-weight: 800;
        }

        .quick-action-close {
            width: 42px;
            height: 42px;
            border: 0;
            border-radius: 14px;
            background: var(--primary);
            color: white;
            display: grid;
            place-items: center;
            cursor: pointer;
            box-shadow: 0 12px 24px rgba(220,38,38,.20);
        }

        .quick-action-body {
            padding: 14px 18px;
            min-height: 0;
            overflow: auto;
        }

        .quick-action-body::-webkit-scrollbar {
            width: 9px;
            height: 9px;
        }

        .quick-action-body::-webkit-scrollbar-thumb {
            background: #fecaca;
            border-radius: 999px;
        }

        .quick-action-row {
            display: grid;
            grid-template-columns: minmax(180px, 1fr) minmax(180px, 1fr) auto;
            gap: 14px;
            align-items: center;
            padding: 14px;
            border: 1px solid #fee2e2;
            border-radius: 18px;
            background: #fffafa;
            margin-bottom: 10px;
        }

        .quick-action-info strong {
            display: block;
            font-weight: 950;
            color: #0f172a;
            margin-bottom: 4px;
        }

        .quick-action-info span {
            display: block;
            color: #64748b;
            font-weight: 800;
            font-size: .76rem;
            line-height: 1.35;
        }

        .quick-action-info .plate-pill {
            display: inline-flex;
            width: fit-content;
            max-width: max-content;
            padding-left: 10px;
            padding-right: 10px;
            margin-bottom: 5px;
        }

        .quick-pay-method {
            height: 38px;
            min-width: 142px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #fff;
            color: #0f172a;
            padding: 0 10px;
            font-weight: 900;
            font-size: .78rem;
            outline: none;
        }

        .quick-pay-method:focus {
            border-color: #86efac;
            box-shadow: 0 0 0 4px rgba(22,163,74,.09);
        }

        .quick-action-controls {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .quick-action-controls form {
            display: inline-flex;
        }

        .quick-empty {
            padding: 46px 18px;
            text-align: center;
            color: #64748b;
            font-weight: 900;
        }

        .blacklist-select-col {
            display: none;
            width: 44px;
            text-align: center;
        }

        body.blacklist-select-mode .blacklist-select-col {
            display: table-cell;
        }

        .blacklist-check {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
            cursor: pointer;
        }

        body.blacklist-select-mode .clickable-row {
            cursor: default;
        }

        body.blacklist-select-mode .clickable-row.selected-row td {
            background: #fff7f7;
        }

        .bulk-blacklist-bar {
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 18px;
            border-bottom: 1px solid #fecaca;
            background: linear-gradient(135deg, #fff7f7, #ffffff);
        }

        body.blacklist-select-mode .bulk-blacklist-bar {
            display: flex;
        }

        .bulk-blacklist-text {
            color: #475569;
            font-weight: 850;
            font-size: .82rem;
        }

        .bulk-blacklist-text strong {
            color: var(--primary);
            font-weight: 950;
        }

        .bulk-blacklist-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
        }

        .bulk-btn {
            border: 0;
            min-height: 38px;
            border-radius: 13px;
            padding: 0 13px;
            font-weight: 950;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .bulk-btn.blacklist {
            background: #fee2e2;
            color: #991b1b;
        }

        .bulk-btn.cancel {
            background: #f8fafc;
            color: #475569;
            border: 1px solid var(--line);
        }

        .note {
            color: #64748b;
            font-weight: 800;
            padding: 12px 18px;
            background: #ffffff;
            font-size: .8rem;
        }

        .empty-state {
            padding: 50px 20px;
            text-align: center;
            color: #64748b;
            font-weight: 850;
        }


        .payment-record-modal {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(15, 23, 42, .46);
            backdrop-filter: blur(6px);
        }

        .payment-record-modal.show {
            display: flex;
        }

        .payment-record-box {
            width: min(1120px, 96vw);
            max-height: 86vh;
            background: rgba(255,255,255,.98);
            border: 1px solid rgba(229,231,235,.95);
            border-radius: 24px;
            box-shadow: 0 28px 70px rgba(15,23,42,.24);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .payment-record-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            padding: 18px 22px;
            border-bottom: 1px solid var(--line);
            background:
                radial-gradient(circle at 86% 18%, rgba(220,38,38,.12), transparent 30%),
                linear-gradient(135deg, #fff, #fff7f7);
        }

        .payment-record-kicker {
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: .12em;
            font-size: .68rem;
            font-weight: 950;
            margin-bottom: 5px;
        }

        .payment-record-title {
            font-size: 1.35rem;
            line-height: 1.05;
            font-weight: 950;
            letter-spacing: -.05em;
        }

        .payment-record-sub {
            margin-top: 6px;
            color: #64748b;
            font-size: .8rem;
            font-weight: 800;
        }

        .payment-record-close {
            width: 42px;
            height: 42px;
            border: 0;
            border-radius: 14px;
            background: var(--primary);
            color: white;
            display: grid;
            place-items: center;
            cursor: pointer;
            box-shadow: 0 12px 24px rgba(220,38,38,.20);
        }

        .payment-record-summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            padding: 14px 22px;
            border-bottom: 1px solid var(--line);
            background: #fff;
        }

        .payment-record-mini {
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 12px 14px;
            background: #fff;
        }

        .payment-record-mini strong {
            display: block;
            font-size: 1.05rem;
            line-height: 1;
            font-weight: 950;
            color: #0f172a;
        }

        .payment-record-mini span {
            display: block;
            margin-top: 6px;
            color: #64748b;
            font-size: .62rem;
            font-weight: 950;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .payment-record-search {
            padding: 12px 22px;
            border-bottom: 1px solid var(--line);
            background: #fff;
        }

        .payment-record-search input {
            width: 100%;
            height: 40px;
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 0 14px;
            font-weight: 850;
            outline: none;
        }

        .payment-record-search input:focus {
            border-color: #fecaca;
            box-shadow: 0 0 0 4px rgba(220,38,38,.08);
        }

        .payment-record-body {
            min-height: 0;
            overflow: auto;
        }

        .payment-record-body::-webkit-scrollbar {
            width: 9px;
            height: 9px;
        }

        .payment-record-body::-webkit-scrollbar-thumb {
            background: #fecaca;
            border-radius: 999px;
        }

        .payment-record-table {
            width: 100%;
            min-width: 940px;
            border-collapse: collapse;
        }

        .payment-record-table th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f8fafc;
            color: #64748b;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: .06em;
            font-size: .66rem;
            font-weight: 950;
            padding: 11px 14px;
            border-bottom: 1px solid var(--line);
        }

        .payment-record-table td {
            padding: 12px 14px;
            border-bottom: 1px solid #eef2f7;
            vertical-align: top;
            font-size: .8rem;
            font-weight: 820;
        }

        .record-main {
            font-weight: 950;
            color: #0f172a;
            margin-bottom: 3px;
        }

        .record-sub {
            color: #64748b;
            font-size: .74rem;
            font-weight: 800;
            line-height: 1.35;
        }

        .record-empty {
            padding: 54px 18px;
            text-align: center;
            color: #64748b;
            font-weight: 900;
        }

        @media (max-width: 1100px) {
            .dashboard-shell {
                grid-template-columns: 1fr;
            }

            .sidebar {
                position: relative;
                height: auto;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .filters {
                grid-template-columns: 1fr;
            }

            .topbar {
                flex-direction: column;
            }
        }
            @media (max-width: 1100px) {
            .dashboard-shell { grid-template-columns: 1fr; }
            .main-content {
                padding: 20px 16px;
                height: auto;
                min-height: 100vh;
                overflow: visible;
            }
            .payment-hero {
                grid-template-columns: 1fr;
                padding: 14px 20px;
            }
            .payment-illustration { min-height: 140px; }
            .hero-mini-stats { grid-template-columns: repeat(3, 1fr); }
            .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .filters { grid-template-columns: 1fr 1fr; }
            .icon-btn { width: 100%; }
            .table-wrap { max-height: none; }
        }

        @media (max-width: 760px) {
            .hero-mini-stats { grid-template-columns: 1fr; }
            .hero-big { font-size: 3rem; }
            .payment-art { transform: scale(.82); transform-origin: center; }
        }

</style>
</head>
<body>
<div class="dashboard-shell">
    <?php require_once __DIR__ . '/admin_sidebar.php'; ?>

    <main class="main-content">
        <div class="topbar">
            <div>
                <div class="eyebrow">Parking Management</div>
                <h1>Payment Verification</h1>
                <p class="page-sub">
                    Check resident parking access, payment status and rolling subscription expiry.
                </p>
            </div>
            <div class="top-actions">
                <button type="button" class="record-btn" id="openPaymentRecordModal">
                    <i class="fas fa-clock-rotate-left"></i>
                    Payment Records
                </button>

                <a href="admin_dashboard.php" class="back-btn">
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

        <section class="payment-hero">
            <div class="payment-hero-info">
                <div class="hero-kicker">Payment Verification</div>
                <div class="hero-big"><?= (int)$activeSubscriptions ?></div>
                <div class="hero-subline">Active resident parking subscriptions in this apartment.</div>

                <div class="hero-mini-stats">
                    <div class="hero-mini-card">
                        <strong class="green"><?= (int)$paidThisMonth ?></strong>
                        <span>Valid Access</span>
                    </div>
                    <div class="hero-mini-card">
                        <strong class="red"><?= (int)$unpaidThisMonth ?></strong>
                        <span>Expired / Due</span>
                    </div>
                    <div class="hero-mini-card">
                        <strong class="blue" style="font-size:.9rem;letter-spacing:-.03em;"><?= e(date('M Y', strtotime($billingMonth . '-01'))) ?></strong>
                        <span>Billing Month</span>
                    </div>
                </div>
            </div>

            <div class="payment-illustration" aria-hidden="true">
                <div class="payment-art">
                    <div class="payment-dots"></div>
                    <div class="payment-coin">RM</div>

                    <div class="invoice-card">
                        <div class="invoice-dollar">RM</div>
                        <div class="invoice-line one"></div>
                        <div class="invoice-line two"></div>
                        <div class="invoice-line three"></div>
                        <div class="invoice-line four"></div>
                    </div>

                    <div class="money-ticket">
                        <div class="money-icon"><i class="fas fa-wallet"></i></div>
                        <div>
                            <div class="money-title">Monthly Payment</div>
                            <div class="money-line"></div>
                            <div class="money-line" style="width:56px;"></div>
                        </div>
                    </div>

                    <div class="payment-check">
                        <i class="fas fa-check"></i>
                    </div>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div class="panel-title">
                    <i class="fas fa-file-invoice-dollar"></i>
                    Rolling Parking Subscriptions
                </div>
                <div class="billing-chip">Rolling monthly access</div>
            </div>

            <form method="GET" class="filters">
                <div class="field">
                    <label>Search</label>
                    <input type="text" name="search" placeholder="Search resident, plate, slot or unit..." value="<?= e($search) ?>">
                </div>
                <div class="field">
                    <label>Subscription</label>
                    <select name="subscription">
                        <option value="" <?= $subscriptionFilter === '' ? 'selected' : '' ?>>All</option>
                        <option value="active" <?= $subscriptionFilter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $subscriptionFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="field">
                    <label>Status</label>
                    <select name="payment">
                        <option value="" <?= $paymentFilter === '' ? 'selected' : '' ?>>All Payment</option>
                        <option value="paid" <?= $paymentFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
                        <option value="unpaid" <?= $paymentFilter === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                    </select>
                </div>
                <div class="field">
                    <label>Month</label>
                    <input type="month" name="month" value="<?= e($billingMonth) ?>">
                </div>
                <button class="icon-btn primary" type="submit" title="Search">
                    <i class="fas fa-magnifying-glass"></i>
                </button>
                <a href="<?= e(basename($_SERVER['PHP_SELF'])) ?>" class="icon-btn" title="Reset">
                    <i class="fas fa-rotate-left"></i>
                </a>

                <div class="toolbar-more-menu">
                    <button type="button" class="icon-btn toolbar-more-btn" title="More actions" aria-label="More actions">
                        <i class="fas fa-ellipsis"></i>
                    </button>

                    <div class="toolbar-more-panel">
                        <button type="button" class="toolbar-menu-action paid" id="openMarkPaidModal">
                            <i class="fas fa-check-circle"></i>
                            Mark Paid
                        </button>

                        <button type="button" class="toolbar-menu-action blacklist" id="openBlacklistModal">
                            <i class="fas fa-ban"></i>
                            Blacklist Plate
                        </button>
                    </div>
                </div>
            </form>

            <div class="bulk-blacklist-bar" id="bulkBlacklistBar">
                <div class="bulk-blacklist-text">
                    <strong id="selectedBlacklistCount">0</strong> selected for blacklist.
                    Tick the boxes on the left, then confirm.
                </div>

                <div class="bulk-blacklist-actions">
                    <button type="button" class="bulk-btn blacklist" id="confirmBulkBlacklist">
                        <i class="fas fa-ban"></i>
                        Confirm Blacklist
                    </button>
                    <button type="button" class="bulk-btn cancel" id="cancelBulkBlacklist">
                        Cancel
                    </button>
                </div>
            </div>

            <form method="POST" id="bulkBlacklistForm" style="display:none;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="bulk_blacklist_plates">
                <div id="bulkBlacklistInputs"></div>
            </form>

            <?php if (!$rows): ?>
                <div class="empty-state">
                    <i class="fas fa-file-invoice-dollar" style="font-size:2rem;color:#cbd5e1;margin-bottom:10px;"></i>
                    <div>No monthly parking payment record found.</div>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th class="blacklist-select-col"></th>
                                <th>Resident</th>
                                <th>Vehicle / Slot</th>
                                <th>Payment</th>
                                <th>Access</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                                $rollingStatus = rolling_subscription_status_payment($row);
                                $isInside = strtolower((string)($row['slot_status'] ?? '')) === 'occupied';
                                $isActive = strtolower((string)($row['subscription_status'] ?? '')) === 'active';

                                $statusKey = (string)$rollingStatus['key'];
                                $statusLabel = (string)$rollingStatus['label'];
                                $statusDetail = (string)$rollingStatus['detail'];
                                $accessLabel = (string)$rollingStatus['access'];
                                $accessType = $accessLabel === 'Allowed' ? 'allowed' : 'denied';
                                $needsPaymentAction = in_array($statusKey, ['expiring', 'expired'], true);
                                $paymentAmount = money_payment($row['monthly_fee']);
                                $parkingUrl = resident_parking_position_url_payment($row);
                            ?>
                            <tr class="clickable-row" data-parking-url="<?= e($parkingUrl) ?>">
                                <td class="blacklist-select-col">
                                    <input type="checkbox" class="blacklist-check" value="<?= (int)$row['assignment_id'] ?>" data-plate="<?= e(text_or_dash_payment($row['plate_no'])) ?>" aria-label="Select <?= e(text_or_dash_payment($row['plate_no'])) ?>">
                                </td>
                                <td>
                                    <div class="main-text"><?= e(text_or_dash_payment($row['resident_name'])) ?></div>
                                    <div class="sub-text"><?= e(text_or_dash_payment($row['unit_text'])) ?></div>
                                    <div class="profile-hint">
                                        <i class="fas fa-square-parking"></i>
                                        View Parking
                                    </div>
                                </td>
                                <td>
                                    <div class="plate-pill"><?= e(text_or_dash_payment($row['plate_no'])) ?></div>
                                    <div class="sub-text">
                                        <?= e(text_or_dash_payment($row['block_name'])) ?> / <?= e(text_or_dash_payment($row['slot_no'])) ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="<?= e(badge_class_payment($statusKey)) ?>">
                                        <?= e($statusLabel) ?>
                                    </span>
                                    <div class="sub-text" style="margin-top:6px;">
                                        <?= e($statusDetail) ?><br>
                                        Fee: <?= e($paymentAmount) ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="<?= e(badge_class_payment($accessType)) ?>">
                                        <?= e($accessLabel) ?>
                                    </span>
                                    <div class="sub-text" style="margin-top:6px;">
                                        <?= $isActive ? 'Active subscription' : 'Inactive subscription' ?>
                                    </div>
                                </td>
                                <td class="action-area">
                                    <?php if ($isActive && $needsPaymentAction): ?>
                                        <div class="payment-action-stack">
                                            <form method="POST" class="send-reminder-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="send_payment_reminder">
                                                <input type="hidden" name="assignment_id" value="<?= (int)$row['assignment_id'] ?>">
                                                <input type="hidden" name="billing_month" value="<?= e(date('Y-m')) ?>">
                                                <button type="submit" class="btn orange">
                                                    <i class="fas fa-envelope"></i>
                                                    Email Reminder
                                                </button>
                                            </form>
                                        </div>
                                    <?php elseif ($isActive): ?>
                                        <span class="badge green">No Action</span>
                                    <?php else: ?>
                                        <span class="badge grey">No action</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="note">
                    Click a resident row or View Parking to open the assigned resident parking slot.
                </div>
            <?php endif; ?>
        </section>

        <div class="quick-action-modal" id="quickActionModal" aria-hidden="true">
            <div class="quick-action-box" role="dialog" aria-modal="true" aria-labelledby="quickActionTitle">
                <div class="quick-action-head">
                    <div>
                        <div class="quick-action-kicker">Payment Actions</div>
                        <div class="quick-action-title" id="quickActionTitle">Payment Action</div>
                        <div class="quick-action-sub">
                            Select the resident subscription below and choose the required action.
                        </div>
                    </div>

                    <button type="button" class="quick-action-close" id="closeQuickActionModal" aria-label="Close quick actions">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>

                <div class="quick-action-body">
                    <?php $quickActionCount = 0; ?>
                    <?php foreach ($rows as $quickRow): ?>
                        <?php
                            $quickStatus = rolling_subscription_status_payment($quickRow);
                            $quickIsActive = strtolower((string)($quickRow['subscription_status'] ?? '')) === 'active';
                            if (!$quickIsActive) {
                                continue;
                            }
                            $quickNeedsPayment = in_array((string)$quickStatus['key'], ['expiring', 'expired'], true);
                            $quickActionCount++;
                        ?>
                        <div class="quick-action-row" data-needs-payment="<?= $quickNeedsPayment ? '1' : '0' ?>">
                            <div class="quick-action-info">
                                <strong><?= e(text_or_dash_payment($quickRow['resident_name'])) ?></strong>
                                <span><?= e(text_or_dash_payment($quickRow['unit_text'])) ?></span>
                                <span><?= e(text_or_dash_payment($quickRow['resident_email'] ?? '')) ?></span>
                            </div>

                            <div class="quick-action-info">
                                <span class="plate-pill"><?= e(text_or_dash_payment($quickRow['plate_no'])) ?></span>
                                <span><?= e(text_or_dash_payment($quickRow['block_name'])) ?> / <?= e(text_or_dash_payment($quickRow['slot_no'])) ?></span>
                                <span><?= e((string)$quickStatus['label']) ?> · <?= e((string)$quickStatus['detail']) ?></span>
                            </div>

                            <div class="quick-action-controls">
                                <?php if ($quickNeedsPayment): ?>
                                    <form method="POST" class="mark-paid-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="mark_paid">
                                        <input type="hidden" name="assignment_id" value="<?= (int)$quickRow['assignment_id'] ?>">
                                        <select name="payment_method" class="quick-pay-method" title="Payment method">
                                            <option value="Cash">Cash</option>
                                            <option value="Card">Card</option>
                                            <option value="Bank Transfer">Bank Transfer</option>
                                            <option value="E-Wallet">E-Wallet</option>
                                            <option value="Manual Verification">Manual</option>
                                        </select>
                                        <button type="submit" class="menu-action paid mark-paid-action">
                                            <i class="fas fa-check"></i>
                                            Mark Paid
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <form method="POST" class="blacklist-plate-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="blacklist_plate">
                                    <input type="hidden" name="assignment_id" value="<?= (int)$quickRow['assignment_id'] ?>">
                                    <input type="hidden" name="blacklist_reason" value="Unpaid resident parking payment">
                                    <button type="submit" class="menu-action blacklist blacklist-action">
                                        <i class="fas fa-ban"></i>
                                        Blacklist
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="quick-empty" id="markPaidEmptyMessage" style="display:none;">
                        <i class="fas fa-circle-check" style="font-size:2rem;color:#cbd5e1;margin-bottom:10px;"></i>
                        <div>No unpaid or due record found.</div>
                    </div>

                    <?php if ($quickActionCount === 0): ?>
                        <div class="quick-empty">
                            <i class="fas fa-circle-check" style="font-size:2rem;color:#cbd5e1;margin-bottom:10px;"></i>
                            <div>No active subscription found in the current list.</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="payment-record-modal" id="paymentRecordModal" aria-hidden="true">
            <div class="payment-record-box" role="dialog" aria-modal="true" aria-labelledby="paymentRecordTitle">
                <div class="payment-record-head">
                    <div>
                        <div class="payment-record-kicker">Payment History</div>
                        <div class="payment-record-title" id="paymentRecordTitle">Resident Parking Payment Records</div>
                        <div class="payment-record-sub">
                            Shows when residents paid, how much they paid, and which payment method was used.
                        </div>
                    </div>

                    <button type="button" class="payment-record-close" id="closePaymentRecordModal" aria-label="Close payment records">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>

                <div class="payment-record-summary">
                    <div class="payment-record-mini">
                        <strong><?= (int)count($recordRows) ?></strong>
                        <span>Records</span>
                    </div>
                    <div class="payment-record-mini">
                        <strong style="color:#16a34a"><?= e(money_payment($recordTotalAmount)) ?></strong>
                        <span>Total Paid</span>
                    </div>
                    <div class="payment-record-mini">
                        <strong><?= e(!empty($recordRows) ? fmt_dt_payment($recordRows[0]['record_paid_at'] ?? null) : '-') ?></strong>
                        <span>Latest Payment</span>
                    </div>
                </div>

                <div class="payment-record-search">
                    <input type="text" id="paymentRecordSearch" placeholder="Search resident, plate, slot, method or month...">
                </div>

                <div class="payment-record-body">
                    <?php if (empty($recordRows)): ?>
                        <div class="record-empty">
                            <i class="fas fa-receipt" style="font-size:2rem;color:#cbd5e1;margin-bottom:10px;"></i>
                            <div>No payment record found.</div>
                        </div>
                    <?php else: ?>
                        <table class="payment-record-table" id="paymentRecordTable">
                            <thead>
                                <tr>
                                    <th>Resident</th>
                                    <th>Vehicle / Slot</th>
                                    <th>Month</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Paid Time</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recordRows as $record): ?>
                                    <?php
                                        $recordMonthRaw = (string)($record['record_billing_month'] ?? '');
                                        $recordMonthText = preg_match('/^\d{4}-\d{2}$/', $recordMonthRaw)
                                            ? date('M Y', strtotime($recordMonthRaw . '-01'))
                                            : text_or_dash_payment($recordMonthRaw);
                                        $slotText = text_or_dash_payment(($record['block_name'] ?? '-') . ' / ' . ($record['slot_no'] ?? '-'));
                                        $methodText = text_or_dash_payment($record['record_payment_method'] ?? 'Manual Verification');
                                        $statusText = text_or_dash_payment($record['record_payment_status'] ?? '-');
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="record-main"><?= e(text_or_dash_payment($record['record_resident_name'] ?? $record['record_resident_email'] ?? '-')) ?></div>
                                            <div class="record-sub"><?= e(text_or_dash_payment($record['record_resident_email'] ?? '-')) ?></div>
                                            <div class="record-sub"><?= e(text_or_dash_payment($record['unit_text'] ?? '-')) ?></div>
                                        </td>
                                        <td>
                                            <span class="plate"><?= e(text_or_dash_payment($record['plate_no'] ?? '-')) ?></span>
                                            <div class="record-sub"><?= e(text_or_dash_payment($record['vehicle_model'] ?? '-')) ?><?= !empty($record['vehicle_color']) ? ' · ' . e($record['vehicle_color']) : '' ?></div>
                                            <div class="record-sub">Slot: <?= e($slotText) ?></div>
                                        </td>
                                        <td><div class="record-main"><?= e($recordMonthText) ?></div></td>
                                        <td><div class="record-main"><?= e(money_payment($record['amount'] ?? 0)) ?></div></td>
                                        <td><div class="record-main"><?= e($methodText) ?></div></td>
                                        <td><div class="record-main"><?= e(fmt_dt_payment($record['record_paid_at'] ?? null)) ?></div></td>
                                        <td><span class="badge green"><?= e($statusText) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
const paymentRecordModal = document.getElementById('paymentRecordModal');
const openPaymentRecordModal = document.getElementById('openPaymentRecordModal');
const closePaymentRecordModal = document.getElementById('closePaymentRecordModal');
const paymentRecordSearch = document.getElementById('paymentRecordSearch');

function openPaymentRecords() {
    if (!paymentRecordModal) return;
    paymentRecordModal.classList.add('show');
    paymentRecordModal.setAttribute('aria-hidden', 'false');
    setTimeout(() => paymentRecordSearch?.focus(), 80);
}

function closePaymentRecords() {
    if (!paymentRecordModal) return;
    paymentRecordModal.classList.remove('show');
    paymentRecordModal.setAttribute('aria-hidden', 'true');
}

openPaymentRecordModal?.addEventListener('click', openPaymentRecords);
closePaymentRecordModal?.addEventListener('click', closePaymentRecords);

paymentRecordModal?.addEventListener('click', (event) => {
    if (event.target === paymentRecordModal) {
        closePaymentRecords();
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && paymentRecordModal?.classList.contains('show')) {
        closePaymentRecords();
    }
});

paymentRecordSearch?.addEventListener('input', () => {
    const keyword = paymentRecordSearch.value.trim().toLowerCase();
    document.querySelectorAll('#paymentRecordTable tbody tr').forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(keyword) ? '' : 'none';
    });
});

document.querySelectorAll('.side-link.parent').forEach(button => {
    button.addEventListener('click', () => {
        const parent = button.closest('.side-parent');
        if (parent) parent.classList.toggle('open');
    });
});

const toolbarMoreMenu = document.querySelector('.toolbar-more-menu');
const toolbarMoreBtn = document.querySelector('.toolbar-more-btn');
const quickActionModal = document.getElementById('quickActionModal');
const openMarkPaidModal = document.getElementById('openMarkPaidModal');
const openBlacklistModal = document.getElementById('openBlacklistModal');
const closeQuickActionModal = document.getElementById('closeQuickActionModal');
const openPaymentRecordFromToolbar = document.getElementById('openPaymentRecordFromToolbar');
const quickActionTitle = document.getElementById('quickActionTitle');
const selectedBlacklistCount = document.getElementById('selectedBlacklistCount');
const confirmBulkBlacklist = document.getElementById('confirmBulkBlacklist');
const cancelBulkBlacklist = document.getElementById('cancelBulkBlacklist');
const bulkBlacklistForm = document.getElementById('bulkBlacklistForm');
const bulkBlacklistInputs = document.getElementById('bulkBlacklistInputs');

toolbarMoreBtn?.addEventListener('click', (event) => {
    event.stopPropagation();
    toolbarMoreMenu?.classList.toggle('open');
});

document.addEventListener('click', (event) => {
    if (!event.target.closest('.toolbar-more-menu')) {
        toolbarMoreMenu?.classList.remove('open');
    }
});

function setQuickActionMode(mode) {
    const isMarkPaid = mode === 'mark';
    quickActionTitle.textContent = isMarkPaid ? 'Mark Paid' : 'Blacklist Plate';

    let visibleRows = 0;

    document.querySelectorAll('.quick-action-row').forEach(row => {
        const needsPayment = row.dataset.needsPayment === '1';
        const shouldShow = isMarkPaid ? needsPayment : true;
        row.style.display = shouldShow ? 'grid' : 'none';
        if (shouldShow) visibleRows++;
    });

    const emptyMessage = document.getElementById('markPaidEmptyMessage');
    if (emptyMessage) {
        emptyMessage.style.display = (isMarkPaid && visibleRows === 0) ? 'block' : 'none';
    }

    document.querySelectorAll('.mark-paid-action').forEach(button => {
        button.closest('form').style.display = isMarkPaid ? 'inline-flex' : 'none';
    });

    document.querySelectorAll('.blacklist-action').forEach(button => {
        button.closest('form').style.display = isMarkPaid ? 'none' : 'inline-flex';
    });
}

function openQuickActions(mode) {
    toolbarMoreMenu?.classList.remove('open');
    setQuickActionMode(mode);
    quickActionModal?.classList.add('show');
    quickActionModal?.setAttribute('aria-hidden', 'false');
}

function closeQuickActions() {
    quickActionModal?.classList.remove('show');
    quickActionModal?.setAttribute('aria-hidden', 'true');
}

openMarkPaidModal?.addEventListener('click', () => openQuickActions('mark'));
openBlacklistModal?.addEventListener('click', () => {
    toolbarMoreMenu?.classList.remove('open');
    enableBlacklistSelectMode();
});
closeQuickActionModal?.addEventListener('click', closeQuickActions);

quickActionModal?.addEventListener('click', (event) => {
    if (event.target === quickActionModal) {
        closeQuickActions();
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && quickActionModal?.classList.contains('show')) {
        closeQuickActions();
    }
});

function getBlacklistChecks() {
    return Array.from(document.querySelectorAll('.blacklist-check'));
}

function updateBlacklistSelectionUI() {
    const checks = getBlacklistChecks();
    const selected = checks.filter(check => check.checked);

    if (selectedBlacklistCount) {
        selectedBlacklistCount.textContent = String(selected.length);
    }

    checks.forEach(check => {
        const row = check.closest('tr');
        row?.classList.toggle('selected-row', check.checked);
    });

}

function enableBlacklistSelectMode() {
    document.body.classList.add('blacklist-select-mode');
    document.querySelector('.table-wrap')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    updateBlacklistSelectionUI();
}

function disableBlacklistSelectMode() {
    document.body.classList.remove('blacklist-select-mode');
    getBlacklistChecks().forEach(check => {
        check.checked = false;
        check.closest('tr')?.classList.remove('selected-row');
    });

    updateBlacklistSelectionUI();
}

getBlacklistChecks().forEach(check => {
    check.addEventListener('change', updateBlacklistSelectionUI);
});

cancelBulkBlacklist?.addEventListener('click', disableBlacklistSelectMode);

confirmBulkBlacklist?.addEventListener('click', () => {
    const selected = getBlacklistChecks().filter(check => check.checked);

    if (!selected.length) {
        Swal.fire('No plate selected', 'Please tick at least one resident plate first.', 'info');
        return;
    }

    const plateList = selected.map(check => check.dataset.plate || check.value).join(', ');

    Swal.fire({
        title: 'Blacklist selected plates?',
        text: plateList,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, blacklist',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626'
    }).then((result) => {
        if (!result.isConfirmed || !bulkBlacklistForm || !bulkBlacklistInputs) return;

        bulkBlacklistInputs.innerHTML = '';
        selected.forEach(check => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'assignment_ids[]';
            input.value = check.value;
            bulkBlacklistInputs.appendChild(input);
        });

        bulkBlacklistForm.submit();
    });
});

document.querySelectorAll('.mark-paid-form').forEach(form => {
    form.addEventListener('submit', function (event) {
        event.preventDefault();

        const method = form.querySelector('[name="payment_method"]')?.value || 'Manual Verification';

        Swal.fire({
            title: 'Mark as paid?',
            text: 'Payment method: ' + method + '. This will extend the rolling subscription by one month.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, mark paid',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#16a34a'
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });
});

document.querySelectorAll('.blacklist-plate-form').forEach(form => {
    form.addEventListener('submit', function (event) {
        event.preventDefault();

        Swal.fire({
            title: 'Blacklist this plate?',
            text: 'Guard scan should deny this plate after it is blacklisted.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, blacklist',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc2626'
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });
});

document.querySelectorAll('.clickable-row').forEach(row => {
    row.addEventListener('click', (event) => {
        if (document.body.classList.contains('blacklist-select-mode')) {
            if (!event.target.closest('a, button, input, select, textarea, form')) {
                const check = row.querySelector('.blacklist-check');
                if (check) {
                    check.checked = !check.checked;
                    updateBlacklistSelectionUI();
                }
            }
            return;
        }

        if (event.target.closest('a, button, input, select, textarea, form')) {
            return;
        }

        const parkingUrl = row.dataset.parkingUrl;
        if (parkingUrl) {
            window.location.href = parkingUrl;
        }
    });
});

document.querySelectorAll('.cancel-form').forEach(form => {
    form.addEventListener('submit', function (event) {
        event.preventDefault();

        const reason = form.querySelector('input[name="cancel_reason"]');
        if (!reason || reason.value.trim() === '') {
            Swal.fire('Cancel reason required', 'Please enter a cancel reason first.', 'info');
            return;
        }

        Swal.fire({
            title: 'Cancel subscription?',
            text: 'This resident will lose monthly parking access.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, cancel',
            cancelButtonText: 'No',
            confirmButtonColor: '#dc2626'
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });
});

document.querySelectorAll('.send-reminder-form').forEach(form => {
    form.addEventListener('submit', function (event) {
        event.preventDefault();

        Swal.fire({
            title: 'Send payment reminder?',
            text: 'An email reminder will be sent to the resident.',
            icon: 'info',
            showCancelButton: true,
            confirmButtonText: 'Yes, send email',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#f97316'
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });
});

setTimeout(() => {
    document.querySelectorAll('.alert').forEach(alertBox => {
        alertBox.classList.add('hide-alert');
        setTimeout(() => alertBox.remove(), 400);
    });
}, 3000);
</script>
</body>
</html>
