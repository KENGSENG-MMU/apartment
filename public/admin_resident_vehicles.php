<?php
require_once '../core/security.php';
require_login(['admin', 'superadmin']);

$pdo = db();

function table_exists_admin_vehicle(PDO $pdo, string $table): bool {
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

function has_column_admin_vehicle(PDO $pdo, string $table, string $column): bool {
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

function safe_count_admin_vehicle(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function safe_rows_admin_vehicle(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function clean_plate_admin_vehicle($plate): string {
    $plate = strtoupper(trim((string)$plate));
    return preg_replace('/[^A-Z0-9]/', '', $plate);
}

function vehicle_status_class($status): string {
    return match ($status) {
        'active' => 'badge-active',
        'inactive' => 'badge-inactive',
        default => 'badge-default'
    };
}

function safe_text_admin_vehicle($value): string {
    return $value !== null && $value !== '' ? (string)$value : '-';
}

function first_existing_table_admin_vehicle(PDO $pdo, array $tables): ?string {
    foreach ($tables as $table) {
        if (table_exists_admin_vehicle($pdo, $table)) {
            return $table;
        }
    }

    return null;
}

function first_existing_column_admin_vehicle(PDO $pdo, string $table, array $columns): ?string {
    foreach ($columns as $column) {
        if (has_column_admin_vehicle($pdo, $table, $column)) {
            return $column;
        }
    }

    return null;
}

function payment_badge_class_admin_vehicle(string $paymentKey): string {
    return match ($paymentKey) {
        'paid', 'active' => 'payment-paid',
        'pending', 'expiring' => 'payment-pending',
        'unpaid', 'expired', 'no_subscription' => 'payment-unpaid',
        'none' => 'payment-none',
        default => 'payment-unknown'
    };
}

function payment_text_admin_vehicle(array $payment): string {
    $label = $payment['label'] ?? 'Unknown';
    $detail = $payment['detail'] ?? '';

    if ($detail !== '') {
        return $label . ' - ' . $detail;
    }

    return $label;
}


function pending_parking_requests_count_admin_vehicle(PDO $pdo, int $apartmentId, bool $hasUserApartmentId): int {
    $table = first_existing_table_admin_vehicle($pdo, [
        'parking_requests',
        'resident_parking_requests',
        'parking_subscriptions'
    ]);

    if (!$table) {
        return 0;
    }

    $statusColumn = first_existing_column_admin_vehicle($pdo, $table, [
        'status',
        'request_status',
        'approval_status',
        'subscription_status'
    ]);

    if (!$statusColumn) {
        return 0;
    }

    $apartmentColumn = first_existing_column_admin_vehicle($pdo, $table, [
        'apartment_id'
    ]);

    $residentColumn = first_existing_column_admin_vehicle($pdo, $table, [
        'resident_id',
        'resident_user_id',
        'user_id'
    ]);

    $pendingStatuses = ['pending', 'requested', 'waiting', 'submitted', 'processing', 'review', 'under review', 'pending approval'];
    $placeholders = implode(',', array_fill(0, count($pendingStatuses), '?'));

    $sql = "SELECT COUNT(*) FROM `{$table}` pr";
    $params = [];

    if (!$apartmentColumn && $residentColumn) {
        $sql .= " LEFT JOIN users u ON u.id = pr.`{$residentColumn}`";
        $sql .= " LEFT JOIN resident_units ru ON ru.resident_id = u.id AND ru.status = 'active'";
        $sql .= " LEFT JOIN units un ON un.id = ru.unit_id";
    }

    $sql .= " WHERE LOWER(TRIM(pr.`{$statusColumn}`)) IN ({$placeholders})";
    $params = array_merge($params, $pendingStatuses);

    if ($apartmentColumn && $apartmentId > 0) {
        $sql .= " AND pr.`{$apartmentColumn}` = ?";
        $params[] = $apartmentId;
    } elseif ($residentColumn && $apartmentId > 0) {
        if ($hasUserApartmentId) {
            $sql .= " AND (u.apartment_id = ? OR un.apartment_id = ?)";
            $params[] = $apartmentId;
            $params[] = $apartmentId;
        } else {
            $sql .= " AND un.apartment_id = ?";
            $params[] = $apartmentId;
        }
    }

    return safe_count_admin_vehicle($pdo, $sql, $params);
}

function resident_monthly_payment_admin_vehicle(PDO $pdo, int $residentId, int $vehicleId, int $apartmentId, string $currentMonth): array {
    $paymentTable = first_existing_table_admin_vehicle($pdo, [
        'parking_invoices',
        'resident_parking_invoices',
        'parking_payments',
        'resident_parking_payments'
    ]);

    if (!$paymentTable) {
        return [
            'key' => 'unknown',
            'label' => 'No payment table',
            'detail' => 'Parking payment table not found',
            'amount' => null,
            'status_raw' => null,
        ];
    }

    $residentColumn = first_existing_column_admin_vehicle($pdo, $paymentTable, [
        'resident_id',
        'user_id',
        'resident_user_id'
    ]);

    $vehicleColumn = first_existing_column_admin_vehicle($pdo, $paymentTable, [
        'vehicle_id',
        'resident_vehicle_id'
    ]);

    $apartmentColumn = first_existing_column_admin_vehicle($pdo, $paymentTable, [
        'apartment_id'
    ]);

    $statusColumn = first_existing_column_admin_vehicle($pdo, $paymentTable, [
        'payment_status',
        'paid_status',
        'invoice_status',
        'status'
    ]);

    $monthColumn = first_existing_column_admin_vehicle($pdo, $paymentTable, [
        'billing_month',
        'invoice_month',
        'month',
        'billing_period',
        'period'
    ]);

    $yearColumn = first_existing_column_admin_vehicle($pdo, $paymentTable, [
        'billing_year',
        'invoice_year',
        'year'
    ]);

    $dateColumn = first_existing_column_admin_vehicle($pdo, $paymentTable, [
        'due_date',
        'billing_date',
        'invoice_date',
        'created_at'
    ]);

    $paidAtColumn = first_existing_column_admin_vehicle($pdo, $paymentTable, [
        'paid_at',
        'payment_date',
        'paid_date',
        'verified_at'
    ]);

    $amountColumn = first_existing_column_admin_vehicle($pdo, $paymentTable, [
        'amount',
        'total_amount',
        'net_amount',
        'invoice_amount',
        'fee_amount',
        'monthly_fee'
    ]);

    if (!$residentColumn && !$vehicleColumn) {
        return [
            'key' => 'unknown',
            'label' => 'Payment not linked',
            'detail' => 'No resident_id or vehicle_id column',
            'amount' => null,
            'status_raw' => null,
        ];
    }

    $selectParts = ['id'];

    if ($statusColumn) {
        $selectParts[] = "`{$statusColumn}` AS payment_status_raw";
    } else {
        $selectParts[] = "NULL AS payment_status_raw";
    }

    if ($amountColumn) {
        $selectParts[] = "`{$amountColumn}` AS payment_amount";
    } else {
        $selectParts[] = "NULL AS payment_amount";
    }

    if ($paidAtColumn) {
        $selectParts[] = "`{$paidAtColumn}` AS payment_paid_at";
    } else {
        $selectParts[] = "NULL AS payment_paid_at";
    }

    $whereParts = [];
    $params = [];

    $ownerParts = [];

    if ($residentColumn && $residentId > 0) {
        $ownerParts[] = "`{$residentColumn}` = ?";
        $params[] = $residentId;
    }

    if ($vehicleColumn && $vehicleId > 0) {
        $ownerParts[] = "`{$vehicleColumn}` = ?";
        $params[] = $vehicleId;
    }

    $whereParts[] = '(' . implode(' OR ', $ownerParts) . ')';

    if ($apartmentColumn && $apartmentId > 0) {
        $whereParts[] = "`{$apartmentColumn}` = ?";
        $params[] = $apartmentId;
    }

    $year = (int)substr($currentMonth, 0, 4);
    $month = (int)substr($currentMonth, 5, 2);
    $month2 = str_pad((string)$month, 2, '0', STR_PAD_LEFT);

    if ($monthColumn && $yearColumn) {
        $whereParts[] = "(`{$yearColumn}` = ? AND CAST(`{$monthColumn}` AS CHAR) IN (?, ?, ?))";
        $params[] = $year;
        $params[] = (string)$month;
        $params[] = $month2;
        $params[] = $currentMonth;
    } elseif ($monthColumn) {
        $whereParts[] = "(CAST(`{$monthColumn}` AS CHAR) = ? OR CAST(`{$monthColumn}` AS CHAR) LIKE ? OR DATE_FORMAT(`{$monthColumn}`, '%Y-%m') = ?)";
        $params[] = $currentMonth;
        $params[] = $currentMonth . '%';
        $params[] = $currentMonth;
    } elseif ($dateColumn) {
        $whereParts[] = "DATE_FORMAT(`{$dateColumn}`, '%Y-%m') = ?";
        $params[] = $currentMonth;
    }

    $orderParts = [];

    if ($paidAtColumn) {
        $orderParts[] = "`{$paidAtColumn}` DESC";
    }

    if (has_column_admin_vehicle($pdo, $paymentTable, 'created_at')) {
        $orderParts[] = "created_at DESC";
    }

    $orderSql = $orderParts ? ('ORDER BY ' . implode(', ', $orderParts)) : 'ORDER BY id DESC';

    try {
        $stmt = $pdo->prepare("
            SELECT " . implode(', ', $selectParts) . "
            FROM `{$paymentTable}`
            WHERE " . implode(' AND ', $whereParts) . "
            {$orderSql}
            LIMIT 1
        ");
        $stmt->execute($params);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        return [
            'key' => 'unknown',
            'label' => 'Payment check error',
            'detail' => 'Cannot read invoice',
            'amount' => null,
            'status_raw' => null,
        ];
    }

    if (!$row) {
        return [
            'key' => 'none',
            'label' => 'No invoice',
            'detail' => date('M Y', strtotime($currentMonth . '-01')),
            'amount' => null,
            'status_raw' => null,
        ];
    }

    $rawStatus = strtolower(trim((string)($row['payment_status_raw'] ?? '')));
    $paidAt = $row['payment_paid_at'] ?? null;
    $amount = $row['payment_amount'] ?? null;

    $paidStatuses = ['paid', 'success', 'successful', 'completed', 'complete', 'verified', 'approved'];
    $pendingStatuses = ['pending', 'waiting', 'processing', 'submitted', 'review', 'under review'];
    $unpaidStatuses = ['unpaid', 'overdue', 'rejected', 'failed', 'cancelled', 'canceled'];

    if (in_array($rawStatus, $paidStatuses, true) || ($rawStatus === '' && !empty($paidAt))) {
        $key = 'paid';
        $label = 'Paid';
    } elseif (in_array($rawStatus, $pendingStatuses, true)) {
        $key = 'pending';
        $label = 'Pending';
    } elseif (in_array($rawStatus, $unpaidStatuses, true)) {
        $key = 'unpaid';
        $label = ucfirst($rawStatus);
    } elseif ($rawStatus !== '') {
        $key = 'unknown';
        $label = ucfirst($rawStatus);
    } else {
        $key = 'unpaid';
        $label = 'Unpaid';
    }

    $detailParts = [date('M Y', strtotime($currentMonth . '-01'))];

    if ($amount !== null && $amount !== '') {
        $detailParts[] = 'RM ' . number_format((float)$amount, 2);
    }

    return [
        'key' => $key,
        'label' => $label,
        'detail' => implode(' · ', $detailParts),
        'amount' => $amount,
        'status_raw' => $rawStatus,
    ];
}


function smartvms_vehicle_load_phpmailer(): bool {
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

function smartvms_vehicle_portal_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/apartment/public/admin_resident_vehicles.php';
    $publicDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    return $scheme . '://' . $host . $publicDir . '/resident_dashboard.php';
}

function fetch_vehicle_payment_reminder_details_admin_vehicle(
    PDO $pdo,
    int $vehicleId,
    int $apartmentId,
    bool $hasUserApartmentId
): array {
    if ($vehicleId <= 0 || $apartmentId <= 0) {
        throw new Exception('Invalid vehicle selected.');
    }

    $hasModel = has_column_admin_vehicle($pdo, 'resident_vehicles', 'vehicle_model');
    $hasColor = has_column_admin_vehicle($pdo, 'resident_vehicles', 'vehicle_color');
    $modelSelect = $hasModel ? "rv.vehicle_model" : "NULL AS vehicle_model";
    $colorSelect = $hasColor ? "rv.vehicle_color" : "NULL AS vehicle_color";

    $hasPaymentAssignments = table_exists_admin_vehicle($pdo, 'resident_parking_assignments');
    $assignmentJoin = '';
    $assignmentSelect = "
        NULL AS assignment_id,
        80.00 AS monthly_fee,
        NULL AS slot_block_name,
        NULL AS slot_no
    ";

    if ($hasPaymentAssignments) {
        $assignmentSelect = "
            assign.id AS assignment_id,
            assign.start_date AS assignment_start_date,
            assign.end_date AS assignment_end_date,
            COALESCE(assign.monthly_fee, 80.00) AS monthly_fee,
            ps.block_name AS slot_block_name,
            ps.slot_no AS slot_no
        ";

        $assignmentJoin = "
            LEFT JOIN resident_parking_assignments assign
                ON assign.vehicle_id = rv.id
                AND assign.status = 'active'
            LEFT JOIN parking_slots ps
                ON ps.id = assign.slot_id
        ";
    }

    $scopeSql = $hasUserApartmentId
        ? "(u.apartment_id = ? OR un.apartment_id = ?)"
        : "un.apartment_id = ?";

    $scopeParams = $hasUserApartmentId
        ? [$apartmentId, $apartmentId]
        : [$apartmentId];

    $stmt = $pdo->prepare("
        SELECT
            rv.id AS vehicle_id,
            rv.resident_id,
            rv.plate_no,
            {$modelSelect},
            {$colorSelect},
            u.full_name AS resident_name,
            u.email AS resident_email,
            CONCAT('Block ', un.block_no, ' / Floor ', un.floor_no, ' / Unit ', un.unit_no) AS unit_text,
            {$assignmentSelect}
        FROM resident_vehicles rv
        JOIN users u
            ON u.id = rv.resident_id
        LEFT JOIN resident_units ru
            ON ru.resident_id = rv.resident_id
            AND ru.status = 'active'
        LEFT JOIN units un
            ON un.id = ru.unit_id
        {$assignmentJoin}
        WHERE rv.id = ?
        AND rv.status = 'active'
        AND {$scopeSql}
        LIMIT 1
    ");

    $stmt->execute(array_merge([$vehicleId], $scopeParams));
    $details = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$details) {
        throw new Exception('Vehicle not found or not under this apartment.');
    }

    return $details;
}

function smartvms_send_vehicle_payment_reminder_email(
    array $details,
    string $billingMonth,
    string $apartmentName,
    ?string &$mailError = null
): bool {
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

    if (!smartvms_vehicle_load_phpmailer()) {
        $mailError = 'PHPMailer is not installed.';
        return false;
    }

    $amount = (float)($details['monthly_fee'] ?? 80.00);
    $monthText = date('F Y', strtotime($billingMonth . '-01'));
    $vehicleText = trim((string)(($details['vehicle_model'] ?? '') . ' ' . ($details['vehicle_color'] ?? '')));
    if ($vehicleText === '') {
        $vehicleText = 'Resident Vehicle';
    }

    $slotText = trim((string)(($details['slot_block_name'] ?? '') . ' / ' . ($details['slot_no'] ?? '')));
    if ($slotText === '/' || $slotText === '') {
        $slotText = '-';
    }

    $portalUrl = smartvms_vehicle_portal_url();

    $safeResidentName = htmlspecialchars($residentName ?: 'Resident', ENT_QUOTES, 'UTF-8');
    $safeApartment = htmlspecialchars($apartmentName, ENT_QUOTES, 'UTF-8');
    $safeMonth = htmlspecialchars($monthText, ENT_QUOTES, 'UTF-8');
    $safeAmount = htmlspecialchars('RM ' . number_format($amount, 2), ENT_QUOTES, 'UTF-8');
    $safeVehicle = htmlspecialchars($vehicleText, ENT_QUOTES, 'UTF-8');
    $safePlate = htmlspecialchars(safe_text_admin_vehicle($details['plate_no'] ?? '-'), ENT_QUOTES, 'UTF-8');
    $safeSlot = htmlspecialchars($slotText, ENT_QUOTES, 'UTF-8');
    $safeUnit = htmlspecialchars(safe_text_admin_vehicle($details['unit_text'] ?? '-'), ENT_QUOTES, 'UTF-8');
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
                            This is a reminder that your monthly resident parking payment for <strong>{$safeMonth}</strong> is still unpaid.
                            Please complete the payment to keep your resident parking access active.
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

    $plainText =
        "SmartVMS Monthly Parking Payment Reminder\n\n" .
        "Hello " . ($residentName ?: 'Resident') . ",\n\n" .
        "Your resident parking payment for {$monthText} is still unpaid.\n" .
        "Amount Due: RM " . number_format($amount, 2) . "\n" .
        "Plate Number: " . safe_text_admin_vehicle($details['plate_no'] ?? '-') . "\n" .
        "Vehicle: {$vehicleText}\n" .
        "Parking Slot: {$slotText}\n\n" .
        "Please complete payment to keep your resident parking access active.\n" .
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

function smartvms_insert_vehicle_payment_notification(PDO $pdo, array $details, string $billingMonth): void {
    if (!table_exists_admin_vehicle($pdo, 'notifications')) {
        return;
    }

    $residentId = (int)($details['resident_id'] ?? 0);
    if ($residentId <= 0) {
        return;
    }

    $monthText = date('F Y', strtotime($billingMonth . '-01'));
    $amount = (float)($details['monthly_fee'] ?? 80.00);
    $plate = safe_text_admin_vehicle($details['plate_no'] ?? '-');

    try {
        $title = 'Monthly Parking Payment Reminder';
        $message = 'Your resident parking payment for ' . $monthText . ' is still unpaid. Plate: ' . $plate . '. Amount: RM ' . number_format($amount, 2) . '.';

        $columns = ['user_id', 'title', 'message', 'created_at'];
        $placeholders = ['?', '?', '?', 'NOW()'];
        $params = [$residentId, $title, $message];

        if (has_column_admin_vehicle($pdo, 'notifications', 'type')) {
            $columns[] = 'type';
            $placeholders[] = '?';
            $params[] = 'payment';
        } elseif (has_column_admin_vehicle($pdo, 'notifications', 'category')) {
            $columns[] = 'category';
            $placeholders[] = '?';
            $params[] = 'payment';
        }

        if (has_column_admin_vehicle($pdo, 'notifications', 'link_url')) {
            $columns[] = 'link_url';
            $placeholders[] = '?';
            $params[] = null;
        }

        if (has_column_admin_vehicle($pdo, 'notifications', 'is_read')) {
            $columns[] = 'is_read';
            $placeholders[] = '?';
            $params[] = 0;
        }

        $stmt = $pdo->prepare("
            INSERT INTO notifications
            (" . implode(', ', $columns) . ")
            VALUES (" . implode(', ', $placeholders) . ")
        ");
        $stmt->execute($params);
    } catch (Throwable $e) {
        // Do not fail the email action because notification insert failed.
    }
}


function resident_rolling_subscription_admin_vehicle(PDO $pdo, int $residentId, int $vehicleId, int $apartmentId): array {
    if (!table_exists_admin_vehicle($pdo, 'resident_parking_assignments')) {
        return [
            'key' => 'no_subscription',
            'label' => 'No Subscription',
            'detail' => 'No parking subscription table found',
            'start_date' => null,
            'end_date' => null,
            'next_due_date' => null,
            'days_left' => null,
            'monthly_fee' => null,
            'assignment_id' => null,
            'slot_text' => '-',
        ];
    }

    $hasParkingSlots = table_exists_admin_vehicle($pdo, 'parking_slots');
    $hasSlotApartmentId = $hasParkingSlots && has_column_admin_vehicle($pdo, 'parking_slots', 'apartment_id');

    $slotJoin = $hasParkingSlots ? "LEFT JOIN parking_slots ps ON ps.id = assign.slot_id" : "";
    $slotSelect = $hasParkingSlots
        ? "ps.block_name AS slot_block_name, ps.slot_no AS slot_no"
        : "NULL AS slot_block_name, NULL AS slot_no";

    $where = [
        "assign.resident_id = ?",
        "assign.vehicle_id = ?",
        "assign.status = 'active'"
    ];
    $params = [$residentId, $vehicleId];

    if ($hasSlotApartmentId && $apartmentId > 0) {
        $where[] = "(ps.apartment_id = ? OR ps.apartment_id IS NULL)";
        $params[] = $apartmentId;
    }

    $startSelect = has_column_admin_vehicle($pdo, 'resident_parking_assignments', 'start_date') ? "assign.start_date" : "NULL AS start_date";
    $endSelect = has_column_admin_vehicle($pdo, 'resident_parking_assignments', 'end_date') ? "assign.end_date" : "NULL AS end_date";
    $feeSelect = has_column_admin_vehicle($pdo, 'resident_parking_assignments', 'monthly_fee') ? "assign.monthly_fee" : "80.00 AS monthly_fee";

    $rows = safe_rows_admin_vehicle($pdo, "
        SELECT
            assign.id AS assignment_id,
            {$startSelect},
            {$endSelect},
            {$feeSelect},
            {$slotSelect}
        FROM resident_parking_assignments assign
        {$slotJoin}
        WHERE " . implode(' AND ', $where) . "
        ORDER BY
            CASE WHEN assign.end_date IS NULL THEN 1 ELSE 0 END,
            assign.end_date DESC,
            assign.id DESC
        LIMIT 1
    ", $params);

    if (!$rows) {
        return [
            'key' => 'no_subscription',
            'label' => 'No Subscription',
            'detail' => 'No active parking subscription',
            'start_date' => null,
            'end_date' => null,
            'next_due_date' => null,
            'days_left' => null,
            'monthly_fee' => null,
            'assignment_id' => null,
            'slot_text' => '-',
        ];
    }

    $row = $rows[0];
    $startDate = $row['start_date'] ?? null;
    $endDate = $row['end_date'] ?? null;
    $amount = $row['monthly_fee'] ?? 80.00;

    $slotText = trim((string)(($row['slot_block_name'] ?? '') . ' / ' . ($row['slot_no'] ?? '')));
    if ($slotText === '/' || $slotText === '') {
        $slotText = '-';
    }

    if (empty($endDate)) {
        return [
            'key' => 'expired',
            'label' => 'Invalid',
            'detail' => 'Subscription end date not set',
            'start_date' => $startDate,
            'end_date' => null,
            'next_due_date' => null,
            'days_left' => null,
            'monthly_fee' => $amount,
            'assignment_id' => (int)$row['assignment_id'],
            'slot_text' => $slotText,
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
                'label' => 'Expired',
                'detail' => 'Expired on ' . $paidUntilText . ' · Payment required',
                'start_date' => $startDate,
                'end_date' => $endDate,
                'next_due_date' => $nextDue->format('Y-m-d'),
                'days_left' => $daysLeft,
                'monthly_fee' => $amount,
                'assignment_id' => (int)$row['assignment_id'],
                'slot_text' => $slotText,
            ];
        }

        if ($daysLeft <= 3) {
            return [
                'key' => 'expiring',
                'label' => 'Due Soon',
                'detail' => 'Paid until ' . $paidUntilText . ' · Next due ' . $nextDueText,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'next_due_date' => $nextDue->format('Y-m-d'),
                'days_left' => $daysLeft,
                'monthly_fee' => $amount,
                'assignment_id' => (int)$row['assignment_id'],
                'slot_text' => $slotText,
            ];
        }

        return [
            'key' => 'active',
            'label' => 'Paid',
            'detail' => 'Paid until ' . $paidUntilText . ' · Next due ' . $nextDueText,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'next_due_date' => $nextDue->format('Y-m-d'),
            'days_left' => $daysLeft,
            'monthly_fee' => $amount,
            'assignment_id' => (int)$row['assignment_id'],
            'slot_text' => $slotText,
        ];
    } catch (Throwable $e) {
        return [
            'key' => 'expired',
            'label' => 'Invalid',
            'detail' => 'Subscription date error',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'next_due_date' => null,
            'days_left' => null,
            'monthly_fee' => $amount,
            'assignment_id' => (int)$row['assignment_id'],
            'slot_text' => $slotText,
        ];
    }
}

function smartvms_send_vehicle_subscription_reminder_email(
    array $details,
    array $subscription,
    string $apartmentName,
    ?string &$mailError = null
): bool {
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

    if (!smartvms_vehicle_load_phpmailer()) {
        $mailError = 'PHPMailer is not installed.';
        return false;
    }

    $amount = (float)($subscription['monthly_fee'] ?? $details['monthly_fee'] ?? 80.00);
    $statusKey = (string)($subscription['key'] ?? 'expired');
    $statusLabel = (string)($subscription['label'] ?? 'Expired');
    $detail = (string)($subscription['detail'] ?? 'Payment required');
    $nextDue = $subscription['next_due_date'] ?? null;
    $nextDueText = $nextDue ? date('d M Y', strtotime((string)$nextDue)) : '-';

    $vehicleText = trim((string)(($details['vehicle_model'] ?? '') . ' ' . ($details['vehicle_color'] ?? '')));
    if ($vehicleText === '') {
        $vehicleText = 'Resident Vehicle';
    }

    $slotText = (string)($subscription['slot_text'] ?? '');
    if ($slotText === '' || $slotText === '-') {
        $slotText = trim((string)(($details['slot_block_name'] ?? '') . ' / ' . ($details['slot_no'] ?? '')));
        if ($slotText === '/' || $slotText === '') {
            $slotText = '-';
        }
    }

    $portalUrl = smartvms_vehicle_portal_url();

    $safeResidentName = htmlspecialchars($residentName ?: 'Resident', ENT_QUOTES, 'UTF-8');
    $safeApartment = htmlspecialchars($apartmentName, ENT_QUOTES, 'UTF-8');
    $safeStatus = htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8');
    $safeDetail = htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');
    $safeNextDue = htmlspecialchars($nextDueText, ENT_QUOTES, 'UTF-8');
    $safeAmount = htmlspecialchars('RM ' . number_format($amount, 2), ENT_QUOTES, 'UTF-8');
    $safeVehicle = htmlspecialchars($vehicleText, ENT_QUOTES, 'UTF-8');
    $safePlate = htmlspecialchars(safe_text_admin_vehicle($details['plate_no'] ?? '-'), ENT_QUOTES, 'UTF-8');
    $safeSlot = htmlspecialchars($slotText, ENT_QUOTES, 'UTF-8');
    $safeUnit = htmlspecialchars(safe_text_admin_vehicle($details['unit_text'] ?? '-'), ENT_QUOTES, 'UTF-8');
    $safePortalUrl = htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8');

    $subject = ($statusKey === 'expired')
        ? 'SmartVMS Parking Subscription Expired'
        : 'SmartVMS Parking Subscription Reminder';

    $mainMessage = ($statusKey === 'expired')
        ? 'Your resident parking subscription has expired. Please make payment to continue resident parking access.'
        : 'Your resident parking subscription will expire soon. Please make payment before the due date to avoid access interruption.';

    $safeMainMessage = htmlspecialchars($mainMessage, ENT_QUOTES, 'UTF-8');

    $html = "
        <div style='margin:0;padding:0;background:#f4f6fb;font-family:Arial,sans-serif;color:#111827;'>
            <div style='max-width:640px;margin:0 auto;padding:28px 16px;'>
                <div style='background:#ffffff;border:1px solid #e5e7eb;border-radius:18px;overflow:hidden;box-shadow:0 18px 40px rgba(15,23,42,.10);'>
                    <div style='background:linear-gradient(135deg,#dc2626,#991b1b);padding:24px;color:white;'>
                        <h1 style='margin:0;font-size:24px;line-height:1.2;'>Resident Parking Payment Reminder</h1>
                        <p style='margin:8px 0 0;color:#fee2e2;font-size:14px;'>SmartVMS Resident Parking</p>
                    </div>

                    <div style='padding:24px;'>
                        <p style='margin:0 0 14px;font-size:15px;'>Hello <strong>{$safeResidentName}</strong>,</p>
                        <p style='margin:0 0 18px;font-size:15px;line-height:1.6;'>{$safeMainMessage}</p>

                        <table style='width:100%;border-collapse:collapse;margin:18px 0;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;'>
                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;width:38%;'>Apartment</td><td style='padding:12px;'>{$safeApartment}</td></tr>
                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Unit</td><td style='padding:12px;'>{$safeUnit}</td></tr>
                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Vehicle</td><td style='padding:12px;'>{$safeVehicle}</td></tr>
                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Plate Number</td><td style='padding:12px;'>{$safePlate}</td></tr>
                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Parking Slot</td><td style='padding:12px;'>{$safeSlot}</td></tr>
                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Status</td><td style='padding:12px;color:#dc2626;font-weight:bold;'>{$safeStatus}</td></tr>
                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Details</td><td style='padding:12px;'>{$safeDetail}</td></tr>
                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Next Due Date</td><td style='padding:12px;'>{$safeNextDue}</td></tr>
                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Amount Due</td><td style='padding:12px;color:#dc2626;font-weight:bold;'>{$safeAmount}</td></tr>
                        </table>

                        <div style='margin:20px 0;padding:14px;border-radius:12px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;line-height:1.55;'>
                            If payment is not made before the subscription expires, resident parking gate access may be denied.
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

    $plainText =
        "SmartVMS Resident Parking Payment Reminder\n\n" .
        "Hello " . ($residentName ?: 'Resident') . ",\n\n" .
        $mainMessage . "\n\n" .
        "Status: {$statusLabel}\n" .
        "Details: {$detail}\n" .
        "Next Due Date: {$nextDueText}\n" .
        "Amount Due: RM " . number_format($amount, 2) . "\n" .
        "Plate Number: " . safe_text_admin_vehicle($details['plate_no'] ?? '-') . "\n" .
        "Vehicle: {$vehicleText}\n" .
        "Parking Slot: {$slotText}\n\n" .
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
        $mail->SMTPSecure = ($secure === 'ssl')
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

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

function smartvms_insert_vehicle_subscription_notification(PDO $pdo, array $details, array $subscription): void {
    if (!table_exists_admin_vehicle($pdo, 'notifications')) {
        return;
    }

    $residentId = (int)($details['resident_id'] ?? 0);
    if ($residentId <= 0) {
        return;
    }

    $amount = (float)($subscription['monthly_fee'] ?? $details['monthly_fee'] ?? 80.00);
    $plate = safe_text_admin_vehicle($details['plate_no'] ?? '-');
    $statusLabel = (string)($subscription['label'] ?? 'Payment Required');
    $detail = (string)($subscription['detail'] ?? 'Please make payment.');

    try {
        $title = 'Resident Parking Payment Reminder';
        $message = 'Your resident parking subscription status is ' . $statusLabel . '. ' . $detail . '. Plate: ' . $plate . '. Amount: RM ' . number_format($amount, 2) . '.';

        $columns = ['user_id', 'title', 'message', 'created_at'];
        $placeholders = ['?', '?', '?', 'NOW()'];
        $params = [$residentId, $title, $message];

        if (has_column_admin_vehicle($pdo, 'notifications', 'type')) {
            $columns[] = 'type';
            $placeholders[] = '?';
            $params[] = 'payment';
        } elseif (has_column_admin_vehicle($pdo, 'notifications', 'category')) {
            $columns[] = 'category';
            $placeholders[] = '?';
            $params[] = 'payment';
        }

        if (has_column_admin_vehicle($pdo, 'notifications', 'link_url')) {
            $columns[] = 'link_url';
            $placeholders[] = '?';
            $params[] = null;
        }

        if (has_column_admin_vehicle($pdo, 'notifications', 'is_read')) {
            $columns[] = 'is_read';
            $placeholders[] = '?';
            $params[] = 0;
        }

        $stmt = $pdo->prepare("
            INSERT INTO notifications
            (" . implode(', ', $columns) . ")
            VALUES (" . implode(', ', $placeholders) . ")
        ");
        $stmt->execute($params);
    } catch (Throwable $e) {
        // Do not fail the email action because notification insert failed.
    }
}

function unit_text_admin_vehicle($row): string {
    if (empty($row['unit_no'])) {
        return 'No unit assigned';
    }

    return 'Block ' . $row['block_no'] .
        ' / Floor ' . $row['floor_no'] .
        ' / Unit ' . $row['unit_no'];
}

function first_apartment_id_admin_vehicle(PDO $pdo): ?int {
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

function resident_belongs_to_apartment_admin_vehicle(PDO $pdo, int $residentId, int $apartmentId, bool $hasUserApartmentId): bool {
    if ($residentId <= 0 || $apartmentId <= 0) {
        return false;
    }

    if ($hasUserApartmentId) {
        $count = safe_count_admin_vehicle($pdo, "
            SELECT COUNT(*)
            FROM users
            WHERE id = ?
            AND role = 'resident'
            AND apartment_id = ?
        ", [$residentId, $apartmentId]);

        if ($count > 0) {
            return true;
        }
    }

    $count = safe_count_admin_vehicle($pdo, "
        SELECT COUNT(*)
        FROM resident_units ru
        JOIN units un ON un.id = ru.unit_id
        WHERE ru.resident_id = ?
        AND ru.status = 'active'
        AND un.apartment_id = ?
    ", [$residentId, $apartmentId]);

    return $count > 0;
}

function vehicle_belongs_to_apartment_admin_vehicle(PDO $pdo, int $vehicleId, int $apartmentId, bool $hasUserApartmentId): bool {
    if ($vehicleId <= 0 || $apartmentId <= 0) {
        return false;
    }

    if ($hasUserApartmentId) {
        $count = safe_count_admin_vehicle($pdo, "
            SELECT COUNT(*)
            FROM resident_vehicles rv
            JOIN users u ON u.id = rv.resident_id
            WHERE rv.id = ?
            AND u.apartment_id = ?
        ", [$vehicleId, $apartmentId]);

        if ($count > 0) {
            return true;
        }
    }

    $count = safe_count_admin_vehicle($pdo, "
        SELECT COUNT(*)
        FROM resident_vehicles rv
        JOIN resident_units ru ON ru.resident_id = rv.resident_id AND ru.status = 'active'
        JOIN units un ON un.id = ru.unit_id
        WHERE rv.id = ?
        AND un.apartment_id = ?
    ", [$vehicleId, $apartmentId]);

    return $count > 0;
}

$hasFullName = has_column_admin_vehicle($pdo, 'users', 'full_name');
$hasContact = has_column_admin_vehicle($pdo, 'users', 'contact_number');
$hasUserApartmentId = has_column_admin_vehicle($pdo, 'users', 'apartment_id');
$hasUpdatedAt = has_column_admin_vehicle($pdo, 'resident_vehicles', 'updated_at');
$hasVehicleModel = has_column_admin_vehicle($pdo, 'resident_vehicles', 'vehicle_model');
$hasVehicleColor = has_column_admin_vehicle($pdo, 'resident_vehicles', 'vehicle_color');

$residentNameSql = $hasFullName ? "u.full_name AS resident_name" : "NULL AS resident_name";
$residentContactSql = $hasContact ? "u.contact_number AS resident_contact" : "NULL AS resident_contact";
$vehicleModelSql = $hasVehicleModel ? "rv.vehicle_model AS vehicle_model" : "NULL AS vehicle_model";
$vehicleColorSql = $hasVehicleColor ? "rv.vehicle_color AS vehicle_color" : "NULL AS vehicle_color";

$currentRole = $_SESSION['role'] ?? 'admin';
$currentEmail = $_SESSION['email'] ?? 'admin@apt.com';
$currentUserId = (int)($_SESSION['uid'] ?? $_SESSION['user_id'] ?? 0);
$currentApartmentId = $_SESSION['apartment_id'] ?? null;

if ($currentUserId <= 0 && $currentEmail !== '') {
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$currentEmail]);
        $foundId = $stmt->fetchColumn();

        if ($foundId) {
            $currentUserId = (int)$foundId;
            $_SESSION['user_id'] = $currentUserId;
        }
    } catch (Throwable $e) {
        $currentUserId = 0;
    }
}

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

if ($currentRole === 'superadmin' && isset($_GET['apartment_id']) && $_GET['apartment_id'] !== '') {
    $currentApartmentId = (int)$_GET['apartment_id'];
}

if (($currentApartmentId === null || $currentApartmentId === '') && $currentRole !== 'superadmin') {
    $currentApartmentId = first_apartment_id_admin_vehicle($pdo);
}

if (($currentApartmentId === null || $currentApartmentId === '') && $currentRole === 'superadmin') {
    $currentApartmentId = first_apartment_id_admin_vehicle($pdo);
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
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'add_vehicle') {
                $residentId = (int)($_POST['resident_id'] ?? 0);
                $plateNo = clean_plate_admin_vehicle($_POST['plate_no'] ?? '');
                $status = $_POST['status'] ?? 'active';

                if ($residentId <= 0) {
                    throw new Exception('Please select resident.');
                }

                if (empty($currentApartmentId) || !resident_belongs_to_apartment_admin_vehicle($pdo, $residentId, (int)$currentApartmentId, $hasUserApartmentId)) {
                    throw new Exception('Selected resident does not belong to this apartment.');
                }

                if ($plateNo === '') {
                    throw new Exception('Please enter vehicle plate number.');
                }

                if (strlen($plateNo) < 3) {
                    throw new Exception('Vehicle plate number is too short.');
                }

                if (!in_array($status, ['active', 'inactive'], true)) {
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

                $stmt = $pdo->prepare("
                    SELECT *
                    FROM resident_vehicles
                    WHERE plate_no = ?
                    LIMIT 1
                ");
                $stmt->execute([$plateNo]);
                $existing = $stmt->fetch();

                if ($existing) {
                    throw new Exception('This plate number already exists under another resident vehicle record.');
                }

                $stmt = $pdo->prepare("
                    INSERT INTO resident_vehicles
                    (resident_id, plate_no, status, created_at)
                    VALUES
                    (?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $residentId,
                    $plateNo,
                    $status
                ]);

                if (function_exists('log_audit')) {
                    log_audit(
                        'RESIDENT_VEHICLE_CREATED',
                        'Admin added resident vehicle plate ' . $plateNo . ' for resident ' . $resident['email']
                    );
                }

                $message = 'Resident vehicle added successfully.';
            } elseif ($action === 'update_status') {
                $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
                $newStatus = $_POST['new_status'] ?? '';

                if ($vehicleId <= 0) {
                    throw new Exception('Invalid vehicle selected.');
                }

                if (!vehicle_belongs_to_apartment_admin_vehicle($pdo, $vehicleId, (int)$currentApartmentId, $hasUserApartmentId)) {
                    throw new Exception('This vehicle does not belong to your apartment.');
                }

                if (!in_array($newStatus, ['active', 'inactive'], true)) {
                    throw new Exception('Invalid status selected.');
                }

                $stmt = $pdo->prepare("
                    SELECT rv.*, u.email AS resident_email
                    FROM resident_vehicles rv
                    JOIN users u ON u.id = rv.resident_id
                    WHERE rv.id = ?
                    LIMIT 1
                ");
                $stmt->execute([$vehicleId]);
                $vehicle = $stmt->fetch();

                if (!$vehicle) {
                    throw new Exception('Resident vehicle not found.');
                }

                $sql = "
                    UPDATE resident_vehicles
                    SET status = ?
                ";

                $params = [$newStatus];

                if ($hasUpdatedAt) {
                    $sql .= ", updated_at = NOW()";
                }

                $sql .= " WHERE id = ?";
                $params[] = $vehicleId;

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                if (function_exists('log_audit')) {
                    log_audit(
                        'RESIDENT_VEHICLE_STATUS_UPDATED',
                        'Admin changed resident vehicle ' . $vehicle['plate_no'] . ' to ' . $newStatus
                    );
                }

                $message = 'Resident vehicle status updated successfully.';
            } elseif ($action === 'bulk_deactivate_vehicles') {
                $vehicleIds = $_POST['vehicle_ids'] ?? [];
                if (!is_array($vehicleIds)) {
                    $vehicleIds = [];
                }

                $vehicleIds = array_values(array_unique(array_filter(array_map('intval', $vehicleIds))));

                if (empty($vehicleIds)) {
                    throw new Exception('Please select at least one vehicle to deactivate.');
                }

                $deactivatedCount = 0;
                $deactivatedPlates = [];

                foreach ($vehicleIds as $vehicleId) {
                    if ($vehicleId <= 0) {
                        continue;
                    }

                    if (!vehicle_belongs_to_apartment_admin_vehicle($pdo, $vehicleId, (int)$currentApartmentId, $hasUserApartmentId)) {
                        continue;
                    }

                    $stmt = $pdo->prepare("
                        SELECT rv.*, u.email AS resident_email
                        FROM resident_vehicles rv
                        JOIN users u ON u.id = rv.resident_id
                        WHERE rv.id = ?
                        LIMIT 1
                    ");
                    $stmt->execute([$vehicleId]);
                    $vehicle = $stmt->fetch();

                    if (!$vehicle || (string)$vehicle['status'] === 'inactive') {
                        continue;
                    }

                    $sql = "UPDATE resident_vehicles SET status = 'inactive'";
                    $params = [];

                    if ($hasUpdatedAt) {
                        $sql .= ", updated_at = NOW()";
                    }

                    $sql .= " WHERE id = ?";
                    $params[] = $vehicleId;

                    $updateStmt = $pdo->prepare($sql);
                    $updateStmt->execute($params);

                    $deactivatedCount++;
                    $deactivatedPlates[] = (string)$vehicle['plate_no'];
                }

                if ($deactivatedCount <= 0) {
                    throw new Exception('No active vehicle was deactivated.');
                }

                if (function_exists('log_audit')) {
                    log_audit(
                        'RESIDENT_VEHICLES_BULK_DEACTIVATED',
                        'Admin bulk deactivated resident vehicles: ' . implode(', ', $deactivatedPlates)
                    );
                }

                $message = $deactivatedCount . ' resident vehicle(s) deactivated successfully.';
            } elseif ($action === 'send_payment_reminder') {
                $vehicleId = (int)($_POST['vehicle_id'] ?? 0);

                if ($vehicleId <= 0) {
                    throw new Exception('Invalid vehicle selected.');
                }

                if (!vehicle_belongs_to_apartment_admin_vehicle($pdo, $vehicleId, (int)$currentApartmentId, $hasUserApartmentId)) {
                    throw new Exception('This vehicle does not belong to your apartment.');
                }

                $details = fetch_vehicle_payment_reminder_details_admin_vehicle(
                    $pdo,
                    $vehicleId,
                    (int)$currentApartmentId,
                    $hasUserApartmentId
                );

                $subscription = resident_rolling_subscription_admin_vehicle(
                    $pdo,
                    (int)$details['resident_id'],
                    (int)$details['vehicle_id'],
                    (int)$currentApartmentId
                );

                if (($subscription['key'] ?? '') === 'active') {
                    throw new Exception('This subscription is still active. Reminder is only needed when it is due soon or expired.');
                }

                $mailError = null;
                $emailSent = smartvms_send_vehicle_subscription_reminder_email(
                    $details,
                    $subscription,
                    $currentApartmentName,
                    $mailError
                );

                if (!$emailSent) {
                    throw new Exception('Payment reminder email could not be sent. ' . ($mailError ?: 'Please check SMTP settings.'));
                }

                smartvms_insert_vehicle_subscription_notification($pdo, $details, $subscription);

                if (function_exists('log_audit')) {
                    log_audit(
                        'RESIDENT_VEHICLE_SUBSCRIPTION_REMINDER_SENT',
                        'Admin sent rolling subscription reminder to ' . ($details['resident_email'] ?? '-') . ' for plate ' . ($details['plate_no'] ?? '-') . '.'
                    );
                }

                $message = 'Subscription reminder email sent to ' . safe_text_admin_vehicle($details['resident_email'] ?? '-') . '.';
            } elseif ($action === 'delete_vehicle') {
                $vehicleId = (int)($_POST['vehicle_id'] ?? 0);

                if ($vehicleId <= 0) {
                    throw new Exception('Invalid vehicle selected.');
                }

                if (!vehicle_belongs_to_apartment_admin_vehicle($pdo, $vehicleId, (int)$currentApartmentId, $hasUserApartmentId)) {
                    throw new Exception('This vehicle does not belong to your apartment.');
                }

                $stmt = $pdo->prepare("
                    SELECT *
                    FROM resident_vehicles
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmt->execute([$vehicleId]);
                $vehicle = $stmt->fetch();

                if (!$vehicle) {
                    throw new Exception('Resident vehicle not found.');
                }

                $plateNo = $vehicle['plate_no'];

                $gateLogCount = table_exists_admin_vehicle($pdo, 'gate_logs')
                    ? safe_count_admin_vehicle($pdo, "SELECT COUNT(*) FROM gate_logs WHERE plate_no = ?", [$plateNo])
                    : 0;

                if ($gateLogCount > 0) {
                    throw new Exception('Cannot delete this vehicle because it has gate log records. Set it to inactive instead.');
                }

                $pdo->prepare("
                    DELETE FROM resident_vehicles
                    WHERE id = ?
                ")->execute([$vehicleId]);

                if (function_exists('log_audit')) {
                    log_audit(
                        'RESIDENT_VEHICLE_DELETED',
                        'Admin deleted resident vehicle plate ' . $plateNo
                    );
                }

                $message = 'Resident vehicle deleted successfully.';
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

    header('Location: admin_resident_vehicles.php');
    exit;
}

$search = trim($_GET['resident_keyword'] ?? ($_GET['search'] ?? ''));
$statusFilter = trim($_GET['status'] ?? '');
$paymentFilter = trim($_GET['payment'] ?? '');
$currentBillingMonth = date('Y-m');

$where = [];
$params = [];

if ($hasUserApartmentId) {
    $where[] = "(u.apartment_id = ? OR un.apartment_id = ?)";
    $params[] = (int)$currentApartmentId;
    $params[] = (int)$currentApartmentId;
} else {
    $where[] = "un.apartment_id = ?";
    $params[] = (int)$currentApartmentId;
}

if ($search !== '') {
    $searchWhere = "
        (
            rv.plate_no LIKE ?
            OR u.email LIKE ?
            OR un.unit_no LIKE ?
            OR un.block_no LIKE ?
    ";

    $term = '%' . $search . '%';

    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;

    if ($hasFullName) {
        $searchWhere .= " OR u.full_name LIKE ?";
        $params[] = $term;
    }

    if ($hasContact) {
        $searchWhere .= " OR u.contact_number LIKE ?";
        $params[] = $term;
    }

    $searchWhere .= ")";
    $where[] = $searchWhere;
}

// This page only shows active resident vehicles for a clean and realistic demo.
$where[] = "rv.status = 'active'";

$whereSql = 'WHERE ' . implode(' AND ', $where);

$vehicles = safe_rows_admin_vehicle($pdo, "
    SELECT
        rv.id,
        rv.resident_id,
        rv.plate_no,
        {$vehicleModelSql},
        {$vehicleColorSql},
        rv.status,
        rv.created_at,
        " . ($hasUpdatedAt ? "rv.updated_at" : "NULL AS updated_at") . ",

        u.email AS resident_email,
        u.status AS resident_status,
        {$residentNameSql},
        {$residentContactSql},

        a.apartment_name,
        un.block_no,
        un.floor_no,
        un.unit_no,

        " . (table_exists_admin_vehicle($pdo, 'gate_logs') ? "
        (
            SELECT COUNT(*)
            FROM gate_logs gl
            WHERE gl.plate_no = rv.plate_no
        ) AS gate_log_count,
        (
            SELECT COUNT(*)
            FROM gate_logs gl
            WHERE gl.plate_no = rv.plate_no
            AND DATE(gl.created_at) = CURDATE()
        ) AS today_gate_count,
        (
            SELECT MAX(gl.created_at)
            FROM gate_logs gl
            WHERE gl.plate_no = rv.plate_no
        ) AS last_gate_time
        " : "
        0 AS gate_log_count,
        0 AS today_gate_count,
        NULL AS last_gate_time
        ") . ",

        " . (table_exists_admin_vehicle($pdo, 'bookings') ? "
        (
            SELECT COUNT(*)
            FROM bookings b
            WHERE b.plate_no = rv.plate_no
        ) AS same_plate_booking_count
        " : "
        0 AS same_plate_booking_count
        ") . "

    FROM resident_vehicles rv

    JOIN users u ON u.id = rv.resident_id

    LEFT JOIN resident_units ru
        ON ru.resident_id = rv.resident_id
        AND ru.status = 'active'

    LEFT JOIN units un ON un.id = ru.unit_id
    LEFT JOIN apartments a ON a.id = COALESCE(u.apartment_id, un.apartment_id)

    {$whereSql}

    GROUP BY
        rv.id,
        rv.resident_id,
        rv.plate_no,
        vehicle_model,
        vehicle_color,
        rv.status,
        rv.created_at,
        updated_at,
        u.email,
        u.status,
        resident_name,
        resident_contact,
        a.apartment_name,
        un.block_no,
        un.floor_no,
        un.unit_no

    ORDER BY
        FIELD(rv.status, 'active', 'inactive'),
        rv.created_at DESC

    LIMIT 600
", $params);

// Filter by rolling subscription status.
// Paid = subscription is still valid (including Due Soon).
// No Paid = expired or no active subscription.
if (in_array($paymentFilter, ['paid', 'nopaid'], true)) {
    $vehicles = array_values(array_filter($vehicles, function ($vehicle) use ($pdo, $currentApartmentId, $paymentFilter) {
        $subscription = resident_rolling_subscription_admin_vehicle(
            $pdo,
            (int)$vehicle['resident_id'],
            (int)$vehicle['id'],
            (int)$currentApartmentId
        );

        $key = (string)($subscription['key'] ?? 'unknown');

        if ($paymentFilter === 'paid') {
            return in_array($key, ['active', 'expiring'], true);
        }

        return in_array($key, ['expired', 'no_subscription', 'unknown'], true);
    }));
}

$paidThisMonth = 0;
$unpaidThisMonth = 0;

if ($hasUserApartmentId) {
    $residentOptions = safe_rows_admin_vehicle($pdo, "
        SELECT
            u.id,
            u.email,
            {$residentNameSql},
            u.status,
            un.block_no,
            un.floor_no,
            un.unit_no
        FROM users u

        LEFT JOIN resident_units ru
            ON ru.resident_id = u.id
            AND ru.status = 'active'

        LEFT JOIN units un ON un.id = ru.unit_id

        WHERE u.role = 'resident'
        AND u.status = 'active'
        AND u.apartment_id = ?

        GROUP BY
            u.id,
            u.email,
            resident_name,
            u.status,
            un.block_no,
            un.floor_no,
            un.unit_no

        ORDER BY
            u.email ASC

        LIMIT 500
    ", [(int)$currentApartmentId]);
} else {
    $residentOptions = safe_rows_admin_vehicle($pdo, "
        SELECT
            u.id,
            u.email,
            {$residentNameSql},
            u.status,
            un.block_no,
            un.floor_no,
            un.unit_no
        FROM users u

        JOIN resident_units ru
            ON ru.resident_id = u.id
            AND ru.status = 'active'

        JOIN units un ON un.id = ru.unit_id

        WHERE u.role = 'resident'
        AND u.status = 'active'
        AND un.apartment_id = ?

        GROUP BY
            u.id,
            u.email,
            resident_name,
            u.status,
            un.block_no,
            un.floor_no,
            un.unit_no

        ORDER BY
            u.email ASC

        LIMIT 500
    ", [(int)$currentApartmentId]);
}

$vehicleScopeSql = "
    FROM resident_vehicles rv
    JOIN users u ON u.id = rv.resident_id
    LEFT JOIN resident_units ru ON ru.resident_id = rv.resident_id AND ru.status = 'active'
    LEFT JOIN units un ON un.id = ru.unit_id
    WHERE " . ($hasUserApartmentId ? "(u.apartment_id = ? OR un.apartment_id = ?)" : "un.apartment_id = ?");

$vehicleScopeParams = $hasUserApartmentId
    ? [(int)$currentApartmentId, (int)$currentApartmentId]
    : [(int)$currentApartmentId];

$totalVehicles = safe_count_admin_vehicle($pdo, "SELECT COUNT(DISTINCT rv.id) " . $vehicleScopeSql . " AND rv.status = 'active'", $vehicleScopeParams);
$activeVehicles = $totalVehicles;
$residentsWithVehicles = safe_count_admin_vehicle($pdo, "SELECT COUNT(DISTINCT rv.resident_id) " . $vehicleScopeSql . " AND rv.status = 'active'", $vehicleScopeParams);
$inactiveVehicles = safe_count_admin_vehicle($pdo, "SELECT COUNT(DISTINCT rv.id) " . $vehicleScopeSql . " AND rv.status = 'inactive'", $vehicleScopeParams);

$todayResidentVehicleLogs = table_exists_admin_vehicle($pdo, 'gate_logs')
    ? safe_count_admin_vehicle($pdo, "
        SELECT COUNT(*)
        FROM gate_logs gl
        JOIN resident_vehicles rv ON rv.plate_no = gl.plate_no
        JOIN users u ON u.id = rv.resident_id
        LEFT JOIN resident_units ru ON ru.resident_id = rv.resident_id AND ru.status = 'active'
        LEFT JOIN units un ON un.id = ru.unit_id
        WHERE gl.vehicle_type = 'resident'
        AND DATE(gl.created_at) = CURDATE()
        AND " . ($hasUserApartmentId ? "(u.apartment_id = ? OR un.apartment_id = ?)" : "un.apartment_id = ?") . "
    ", $vehicleScopeParams)
    : 0;

$pendingParkingRequestsCount = pending_parking_requests_count_admin_vehicle($pdo, (int)$currentApartmentId, $hasUserApartmentId);

$profileInitial = strtoupper(substr(trim($currentEmail ?: 'A'), 0, 1));
if ($profileInitial === '') {
    $profileInitial = 'A';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Resident Vehicles - <?= e(APP_NAME) ?></title>
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
            padding: 14px 28px 14px;
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
            font-size: .65rem;
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

        .page-sub {
            color: var(--muted);
            margin-top: 6px;
            font-size: .84rem;
            font-weight: 750;
            line-height: 1.38;
            max-width: 760px;
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
            position: relative;
        }

        .top-btn .request-dot {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: #ef4444;
            box-shadow: 0 0 0 2px #ffffff;
        }

        .top-btn.primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border-color: transparent;
        }

        .top-btn.request {
            background: #fff;
            color: var(--primary);
            border-color: #fecaca;
        }

        .top-btn.request:hover {
            background: #fff5f5;
            border-color: #fca5a5;
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

        .add-car-hero {
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
                linear-gradient(135deg, rgba(255,255,255,.98), rgba(248,250,252,.86));
            box-shadow: var(--shadow);
            overflow: hidden;
            flex: 0 0 auto;
        }

        .add-car-hero::before {
            content: "";
            position: absolute;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            left: -85px;
            top: -90px;
            background: rgba(220,38,38,.09);
        }

        .add-car-hero::after {
            content: "";
            position: absolute;
            right: 22px;
            top: 18px;
            width: 58px;
            height: 58px;
            border-radius: 20px;
            background: rgba(220,38,38,.06);
            transform: rotate(8deg);
        }

        .hero-info {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-width: 0;
        }

        .hero-kicker {
            color: #64748b;
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
            color: #dc2626;
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

        .hero-mini-card span {
            display: block;
            color: #64748b;
            font-size: .58rem;
            text-transform: uppercase;
            letter-spacing: .07em;
            font-weight: 950;
        }

        .hero-car-area {
            position: relative;
            z-index: 2;
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

        .hero-car-area::before {
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

        .cartoon-car-wrap {
            position: relative;
            width: min(540px, 100%);
            min-height: 136px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .cartoon-car-glow {
            position: absolute;
            width: 360px;
            height: 68px;
            left: 50%;
            bottom: 7px;
            transform: translateX(-50%);
            border-radius: 50%;
            background: radial-gradient(circle, rgba(234,88,12,.24), rgba(234,88,12,.08) 58%, transparent 74%);
            filter: blur(8px);
        }

        .cartoon-car {
            position: relative;
            width: 400px;
            height: 136px;
            transform: translateX(-6px);
        }

        .cartoon-car .rear-wing {
            position: absolute;
            right: 0;
            top: 12px;
            width: 76px;
            height: 16px;
            background: #fb923c;
            border: 4px solid #111827;
            border-radius: 8px 14px 4px 8px;
            transform: skewX(-18deg) rotate(-7deg);
            z-index: 6;
            box-shadow: inset 0 -4px 0 rgba(234,88,12,.45);
        }

        .cartoon-car .wing-post {
            position: absolute;
            right: 60px;
            top: 28px;
            width: 12px;
            height: 28px;
            background: #111827;
            transform: skewX(-14deg);
            z-index: 4;
        }

        .cartoon-car .body-main {
            position: absolute;
            left: 14px;
            right: 12px;
            top: 57px;
            height: 50px;
            background: linear-gradient(180deg, #fb923c 0%, #f97316 48%, #ea580c 100%);
            border: 5px solid #111827;
            border-radius: 76px 44px 24px 24px;
            z-index: 3;
            box-shadow:
                inset 0 10px 0 rgba(255,255,255,.30),
                inset 0 -8px 0 rgba(154,52,18,.20);
        }

        .cartoon-car .body-main::before {
            content: "";
            position: absolute;
            left: -8px;
            top: 25px;
            width: 58px;
            height: 22px;
            background: #fb923c;
            border-left: 5px solid #111827;
            border-bottom: 5px solid #111827;
            border-radius: 30px 0 0 18px;
            transform: skewX(-22deg);
        }

        .cartoon-car .body-main::after {
            content: "";
            position: absolute;
            right: -8px;
            top: 24px;
            width: 76px;
            height: 22px;
            background: #c2410c;
            border-right: 5px solid #111827;
            border-bottom: 5px solid #111827;
            border-radius: 0 16px 20px 0;
            transform: skewX(17deg);
        }

        .cartoon-car .roof {
            position: absolute;
            left: 108px;
            top: 26px;
            width: 174px;
            height: 55px;
            background: linear-gradient(180deg, #fb923c, #f97316);
            border: 5px solid #111827;
            border-bottom: 0;
            border-radius: 78px 92px 12px 12px;
            transform: skewX(-17deg);
            z-index: 2;
            box-shadow: inset 0 6px 0 rgba(255,255,255,.26);
        }

        .cartoon-car .window {
            position: absolute;
            left: 126px;
            top: 35px;
            width: 118px;
            height: 31px;
            background: linear-gradient(180deg, #dbeafe, #64748b);
            border: 4px solid #111827;
            border-radius: 52px 58px 7px 7px;
            transform: skewX(-16deg);
            z-index: 5;
            overflow: hidden;
        }

        .cartoon-car .window::after {
            content: "";
            position: absolute;
            left: 50px;
            top: -6px;
            width: 7px;
            height: 44px;
            background: rgba(17,24,39,.85);
            transform: rotate(4deg);
        }

        .cartoon-car .mirror {
            position: absolute;
            left: 112px;
            top: 59px;
            width: 22px;
            height: 14px;
            background: #fb923c;
            border: 4px solid #111827;
            border-radius: 12px 8px 10px 8px;
            transform: rotate(-8deg);
            z-index: 7;
        }

        .cartoon-car .door-line {
            position: absolute;
            left: 210px;
            top: 66px;
            width: 4px;
            height: 44px;
            background: #111827;
            border-radius: 999px;
            transform: rotate(5deg);
            z-index: 7;
        }

        .cartoon-car .door-line::after {
            content: "";
            position: absolute;
            left: 10px;
            top: 10px;
            width: 20px;
            height: 5px;
            border-radius: 999px;
            background: #111827;
            transform: rotate(-5deg);
        }

        .cartoon-car .side-vent {
            position: absolute;
            right: 120px;
            top: 68px;
            width: 38px;
            height: 40px;
            background: #111827;
            clip-path: polygon(26% 0, 100% 0, 72% 100%, 0 100%);
            z-index: 7;
        }

        .cartoon-car .highlight {
            position: absolute;
            left: 62px;
            top: 65px;
            width: 106px;
            height: 7px;
            border-radius: 999px;
            background: rgba(255,255,255,.55);
            z-index: 8;
            transform: rotate(-4deg);
        }

        .cartoon-car .headlight {
            position: absolute;
            left: 26px;
            top: 69px;
            width: 38px;
            height: 12px;
            background: #fef3c7;
            border: 4px solid #111827;
            border-radius: 20px 6px 14px 6px;
            transform: skewX(-26deg);
            z-index: 8;
        }

        .cartoon-car .taillight {
            position: absolute;
            right: 18px;
            top: 69px;
            width: 28px;
            height: 12px;
            background: #ef4444;
            border: 4px solid #111827;
            border-radius: 6px 12px 10px 4px;
            z-index: 8;
        }

        .cartoon-car .wheel {
            position: absolute;
            top: 83px;
            width: 58px;
            height: 58px;
            border-radius: 50%;
            background: #111827;
            border: 5px solid #111827;
            z-index: 10;
            box-shadow: 0 8px 0 rgba(15,23,42,.16);
        }

        .cartoon-car .wheel.front {
            left: 68px;
        }

        .cartoon-car .wheel.back {
            right: 62px;
        }

        .cartoon-car .wheel::before {
            content: "";
            position: absolute;
            inset: 10px;
            border-radius: 50%;
            background: #e5e7eb;
            border: 4px solid #475569;
        }

        .cartoon-car .wheel::after {
            content: "";
            position: absolute;
            inset: 21px;
            border-radius: 50%;
            background: #111827;
            box-shadow:
                0 -16px 0 -10px #111827,
                0 16px 0 -10px #111827,
                16px 0 0 -10px #111827,
                -16px 0 0 -10px #111827,
                11px 11px 0 -10px #111827,
                -11px -11px 0 -10px #111827;
        }

        .layout {
            flex: 1 1 auto;
            min-height: 0;
            overflow: hidden;
            display: grid;
            grid-template-columns: 1fr;
            gap: 18px;
            align-items: stretch;
        }

        .panel {
            background: rgba(255,255,255,.96);
            border: 1px solid rgba(229,231,235,.95);
            border-radius: 24px;
            box-shadow: var(--shadow);
            overflow: hidden;
            min-height: 0;
        }

        .visual-panel,
        .list-panel {
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

        .panel-body {
            padding: 18px 20px;
            min-height: 0;
        }

        .visual-panel .panel-body,
        .list-panel .panel-body {
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .visual-panel {
            background:
                radial-gradient(circle at 22% 18%, rgba(220, 38, 38, .14), transparent 34%),
                radial-gradient(circle at 82% 72%, rgba(37, 99, 235, .08), transparent 34%),
                rgba(255, 255, 255, .96);
            position: relative;
        }

        .visual-board {
            position: relative;
            flex: 1;
            min-height: 460px;
            border-radius: 26px;
            background:
                linear-gradient(145deg, rgba(255,255,255,.94), rgba(248,250,252,.86)),
                radial-gradient(circle at 25% 18%, rgba(220,38,38,.16), transparent 34%);
            border: 1px solid rgba(229,231,235,.95);
            box-shadow: inset 0 1px 0 rgba(255,255,255,.9);
            overflow: hidden;
            padding: 22px;
        }

        .visual-board::before {
            content: "";
            position: absolute;
            width: 280px;
            height: 280px;
            border-radius: 50%;
            left: -70px;
            top: -80px;
            background: rgba(220,38,38,.08);
        }

        .visual-browser {
            position: relative;
            height: 310px;
            border: 1px solid #e5e7eb;
            border-radius: 24px;
            background: rgba(255,255,255,.82);
            box-shadow: 0 24px 60px rgba(15,23,42,.10);
            overflow: hidden;
        }

        .browser-dots {
            display: flex;
            gap: 7px;
            padding: 16px 18px 10px;
        }

        .browser-dots span {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: block;
        }

        .browser-dots span:nth-child(1) { background: #ef4444; }
        .browser-dots span:nth-child(2) { background: #f59e0b; }
        .browser-dots span:nth-child(3) { background: #22c55e; }

        .visual-inner {
            margin: 0 16px 16px;
            border-radius: 22px;
            background:
                linear-gradient(135deg, #fff7f7 0%, #ffffff 70%);
            border: 1px solid #eef2f7;
            min-height: 245px;
            padding: 22px;
            position: relative;
        }

        .scan-kicker {
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .12em;
            font-size: .72rem;
            font-weight: 950;
            margin-bottom: 10px;
        }

        .scan-number {
            font-size: 3.05rem;
            font-weight: 950;
            letter-spacing: -.08em;
            color: #0f172a;
            line-height: .95;
        }

        .scan-sub {
            color: #dc2626;
            font-size: .84rem;
            font-weight: 900;
            margin-top: 8px;
        }

        .car-visual {
            position: absolute;
            right: 22px;
            top: 78px;
            width: 140px;
            height: 92px;
        }

        .car-body {
            position: absolute;
            left: 13px;
            right: 13px;
            bottom: 20px;
            height: 54px;
            border-radius: 34px 34px 14px 14px;
            background: linear-gradient(180deg, #f8fafc, #cbd5e1);
            border: 2px solid #e2e8f0;
            box-shadow: 0 14px 24px rgba(15,23,42,.12);
        }

        .car-window {
            position: absolute;
            left: 35px;
            right: 35px;
            bottom: 58px;
            height: 28px;
            border-radius: 24px 24px 8px 8px;
            background: linear-gradient(180deg, #0f172a, #334155);
        }

        .car-plate {
            position: absolute;
            left: 45px;
            bottom: 30px;
            background: #fff;
            color: #0f172a;
            border: 2px solid #ef4444;
            border-radius: 5px;
            padding: 3px 8px;
            font-size: .66rem;
            font-weight: 950;
            letter-spacing: .04em;
        }

        .scan-corner {
            position: absolute;
            width: 26px;
            height: 26px;
            border-color: #dc2626;
            border-style: solid;
        }

        .scan-corner.tl { left: 0; top: 0; border-width: 3px 0 0 3px; border-radius: 10px 0 0 0; }
        .scan-corner.tr { right: 0; top: 0; border-width: 3px 3px 0 0; border-radius: 0 10px 0 0; }
        .scan-corner.bl { left: 0; bottom: 0; border-width: 0 0 3px 3px; border-radius: 0 0 0 10px; }
        .scan-corner.br { right: 0; bottom: 0; border-width: 0 3px 3px 0; border-radius: 0 0 10px 0; }

        .scan-line {
            position: absolute;
            left: 12px;
            right: 12px;
            top: 47px;
            height: 8px;
            border-radius: 999px;
            background: rgba(220,38,38,.14);
            box-shadow: 0 0 22px rgba(220,38,38,.14);
        }

        .mini-stat-row {
            position: absolute;
            left: 22px;
            right: 22px;
            bottom: 18px;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .mini-stat {
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            background: rgba(255,255,255,.86);
            padding: 13px;
        }

        .mini-stat .label {
            color: #64748b;
            font-size: .65rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            font-weight: 950;
            margin-bottom: 7px;
        }

        .mini-stat .value {
            color: #0f172a;
            font-size: 1.35rem;
            font-weight: 950;
            line-height: 1;
        }

        .floating-chip {
            position: relative;
            margin-top: 14px;
            border-radius: 22px;
            background: rgba(255,255,255,.92);
            border: 1px solid #e5e7eb;
            box-shadow: 0 18px 40px rgba(15,23,42,.08);
            padding: 16px;
            display: flex;
            gap: 13px;
            align-items: center;
        }

        .floating-chip .chip-icon {
            width: 52px;
            height: 52px;
            border-radius: 18px;
            display: grid;
            place-items: center;
            background: #fee2e2;
            color: #dc2626;
            font-size: 1.2rem;
            flex: 0 0 auto;
        }

        .floating-chip .chip-title {
            font-size: 1rem;
            font-weight: 950;
            letter-spacing: -.04em;
            color: #0f172a;
            margin-bottom: 4px;
        }

        .floating-chip .chip-text {
            color: #64748b;
            font-size: .82rem;
            font-weight: 800;
            line-height: 1.45;
        }

        .visual-note {
            margin-top: 14px;
            background: #fff7f7;
            color: #991b1b;
            border: 1px solid #fecaca;
            padding: 14px;
            border-radius: 18px;
            font-size: .82rem;
            font-weight: 850;
            line-height: 1.5;
        }

        .note-box {
            background: #fff7f7;
            color: #991b1b;
            border: 1px solid #fecaca;
            padding: 14px;
            border-radius: 18px;
            font-size: .82rem;
            font-weight: 850;
            line-height: 1.5;
            margin-bottom: 10px;
        }

        .field {
            margin-bottom: 14px;
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

        .plate-input {
            text-transform: uppercase;
            font-family: monospace;
            letter-spacing: .06em;
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

        .btn-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .panel-search {
            margin-left: auto;
            display: grid;
            grid-template-columns: minmax(240px, 320px) 150px 44px 44px 44px;
            gap: 8px;
            align-items: center;
        }

        .panel-search input,
        .panel-search select {
            width: 100%;
            height: 44px;
            border: 1px solid var(--border);
            border-radius: 16px;
            background: #fff;
            padding: 0 16px;
            font-weight: 850;
            color: #0f172a;
            outline: none;
            box-shadow: 0 1px 0 rgba(15,23,42,.02);
        }

        .panel-search select {
            cursor: pointer;
            appearance: none;
            background-image:
                linear-gradient(45deg, transparent 50%, #64748b 50%),
                linear-gradient(135deg, #64748b 50%, transparent 50%);
            background-position:
                calc(100% - 18px) 19px,
                calc(100% - 13px) 19px;
            background-size: 5px 5px, 5px 5px;
            background-repeat: no-repeat;
            padding-right: 34px;
        }

        .panel-search input:focus,
        .panel-search select:focus {
            border-color: #fecaca;
            box-shadow: 0 0 0 4px rgba(220,38,38,.08);
        }

        .panel-search .search-btn,
        .panel-search .reset-btn {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            border: 0;
            text-decoration: none;
            cursor: pointer;
            font-weight: 950;
        }

        .panel-search .search-btn {
            color: #fff;
            background: linear-gradient(145deg, var(--primary), var(--primary-dark));
            box-shadow: 0 12px 24px rgba(220,38,38,.18);
        }

        .panel-search .reset-btn {
            color: #64748b;
            background: #fff;
            border: 1px solid var(--border);
        }

        .panel-search .reset-btn:hover {
            color: var(--primary);
            border-color: #fecaca;
            background: #fffafa;
        }

        .toolbar-more-menu {
            position: relative;
            width: 44px;
            height: 44px;
        }

        .toolbar-more-btn {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            border: 1px solid var(--border);
            background: #fff;
            color: #64748b;
            cursor: pointer;
            font-weight: 950;
        }

        .toolbar-more-btn:hover,
        .toolbar-more-menu.open .toolbar-more-btn {
            color: var(--primary);
            border-color: #fecaca;
            background: #fffafa;
        }

        .toolbar-more-panel {
            position: absolute;
            right: 0;
            top: calc(100% + 8px);
            width: 190px;
            padding: 8px;
            border: 1px solid var(--border);
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 20px 45px rgba(15,23,42,.16);
            display: none;
            z-index: 60;
        }

        .toolbar-more-menu.open .toolbar-more-panel {
            display: grid;
            gap: 6px;
        }

        .toolbar-menu-action {
            width: 100%;
            min-height: 38px;
            border: 0;
            border-radius: 12px;
            background: #fff;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 0 10px;
            cursor: pointer;
            font-size: .78rem;
            font-weight: 950;
            text-align: left;
        }

        .toolbar-menu-action:hover {
            background: #f8fafc;
            color: var(--primary);
        }

        .bulk-deactivate-bar {
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 18px;
            border-bottom: 1px solid #fed7aa;
            background: linear-gradient(135deg, #fff7ed, #ffffff);
        }

        body.vehicle-deactivate-mode .bulk-deactivate-bar {
            display: flex;
        }

        .bulk-deactivate-text {
            color: #475569;
            font-weight: 850;
            font-size: .82rem;
        }

        .bulk-deactivate-text strong {
            color: var(--primary);
            font-weight: 950;
        }

        .bulk-deactivate-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .deactivate-select-box {
            display: none;
            align-items: center;
            justify-content: center;
        }

        body.vehicle-deactivate-mode .deactivate-select-box {
            display: inline-flex;
        }

        .deactivate-check {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
            cursor: pointer;
        }

        body.vehicle-deactivate-mode .vehicle-card.selected-card {
            border-color: #fca5a5;
            background: #fff7f7;
            box-shadow: 0 12px 28px rgba(220,38,38,.10);
        }

        body.vehicle-deactivate-mode .vehicle-card.inactive {
            opacity: .55;
        }

        .vehicle-list-wrap {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
            padding-right: 6px;
        }

        .vehicle-list-wrap::-webkit-scrollbar {
            width: 8px;
        }

        .vehicle-list-wrap::-webkit-scrollbar-thumb {
            background: #fecaca;
            border-radius: 999px;
        }

        .vehicle-list {
            display: grid;
            gap: 12px;
            padding-bottom: 4px;
        }

        .vehicle-card {
            border: 1px solid var(--border);
            background: #fbfdff;
            border-radius: 22px;
            padding: 12px;
            transition: .2s ease;
            cursor: pointer;
        }

        body.vehicle-deactivate-mode .vehicle-card {
            cursor: default;
        }

        .vehicle-card:hover {
            transform: translateY(-2px);
            border-color: rgba(220,38,38,.22);
            box-shadow: var(--shadow-soft);
        }

        .vehicle-card.active {
            border-color: #fecaca;
            background: linear-gradient(180deg, #fff, #fff7f7);
        }

        .vehicle-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 10px;
        }

        .plate {
            display: inline-flex;
            background: #111827;
            color: white;
            border: 2px solid #334155;
            padding: 6px 10px;
            border-radius: 10px;
            font-family: monospace;
            font-weight: 950;
            letter-spacing: .08em;
            font-size: 1rem;
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

        .badge-active {
            background: #dcfce7;
            color: #166534;
        }

        .badge-inactive {
            background: #f1f5f9;
            color: #475569;
        }

        .badge-warning {
            background: #ffedd5;
            color: #9a3412;
        }

        .badge-default {
            background: #f3f4f6;
            color: #374151;
        }

        .payment-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            border-radius: 999px;
            font-size: .7rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 6px;
        }

        .payment-paid {
            background: #dcfce7;
            color: #166534;
        }

        .payment-pending {
            background: #ffedd5;
            color: #9a3412;
        }

        .payment-unpaid {
            background: #fee2e2;
            color: #991b1b;
        }

        .payment-none {
            background: #f1f5f9;
            color: #475569;
        }

        .payment-unknown {
            background: #e0f2fe;
            color: #075985;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 12px;
        }

        .info-box {
            background: white;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 12px;
        }

        .info-label {
            font-size: .65rem;
            font-weight: 950;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 6px;
            line-height: 1.25;
        }

        .info-value {
            font-weight: 950;
            color: #111827;
            line-height: 1.4;
            word-break: break-word;
        }

        .small {
            color: var(--muted);
            font-size: .76rem;
            margin-top: 5px;
            line-height: 1.45;
            font-weight: 750;
        }

        .info-subvalue {
            margin-top: 5px;
            color: var(--muted);
            font-size: .78rem;
            line-height: 1.35;
            font-weight: 800;
            word-break: break-word;
        }

        .payment-mini {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: .68rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 6px;
        }

        .subscription-action {
            margin-top: 10px;
        }

        .subscription-action .btn {
            width: 100%;
            justify-content: center;
            padding: 9px 12px;
            border-radius: 12px;
            font-size: .75rem;
        }

        .warning-text {
            color: #9a3412;
            background: #fffbeb;
            border: 1px solid #fed7aa;
            border-radius: 14px;
            padding: 10px 12px;
            font-weight: 900;
            margin-top: 10px;
            font-size: .78rem;
        }

        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
            margin-top: 10px;
        }

        .actions .action-right {
            margin-left: auto;
        }

        .actions .action-right .btn {
            min-width: 210px;
            justify-content: center;
            border-radius: 14px;
        }

        .footer-note {
            flex: 0 0 auto;
            padding-top: 10px;
            color: var(--muted);
            font-size: .76rem;
            font-weight: 800;
        }

        .empty {
            padding: 44px 22px;
            text-align: center;
            color: var(--muted);
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
            .visual-panel,
            .list-panel,
            .panel-body {
                overflow: visible;
            }

            .vehicle-list-wrap {
                overflow: visible;
            }
        }

        @media (max-width: 1080px) {
            .hero-mini-stats {
                grid-template-columns: 1fr;
            }

            .add-car-hero {
                grid-template-columns: 1fr;
            }

            .info-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
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

            .add-car-hero,
            .hero-mini-stats,
            .filter-form,
            .side-nav,
            .info-grid {
                grid-template-columns: 1fr;
            }

            .panel-search {
                width: 100%;
                grid-template-columns: 1fr 1fr 44px 44px;
            }

            .vehicle-top {
                flex-direction: column;
            }

            .actions {
                width: 100%;
                flex-direction: column;
            }

            .actions .action-right {
                margin-left: 0;
                width: 100%;
            }

            .actions .action-right .btn {
                width: 100%;
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
                <h1 class="page-title">Resident Vehicles</h1>
                <p class="page-sub">
                    View verified resident vehicle plates, gate activity and vehicle access status in a clean ANPR-style dashboard.
                </p>
            </div>

            <div class="top-actions">
                <a href="admin_parking_requests.php?from=resident_vehicles" class="top-btn request" title="Parking Requests<?= $pendingParkingRequestsCount > 0 ? ' - ' . (int)$pendingParkingRequestsCount . ' pending' : '' ?>">
                    <i class="fas fa-clipboard-list"></i>
                    Parking Requests
                    <?php if ($pendingParkingRequestsCount > 0): ?>
                        <span class="request-dot"></span>
                    <?php endif; ?>
                </a>

                <a href="admin_dashboard.php" class="top-btn primary">
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

        <section class="add-car-hero">
            <div class="hero-info">
                <div class="hero-kicker">Add Car Records</div>
                <div class="hero-big"><?= (int)$totalVehicles ?></div>
                <div class="hero-subline">
                    Active resident vehicles registered in this apartment.
                </div>

                <div class="hero-mini-stats">
                    <div class="hero-mini-card">
                        <strong style="color:var(--green);"><?= (int)$activeVehicles ?></strong>
                        <span>Active Vehicles</span>
                    </div>
                    <div class="hero-mini-card">
                        <strong><?= (int)$residentsWithVehicles ?></strong>
                        <span>Residents With Cars</span>
                    </div>
                    <div class="hero-mini-card">
                        <strong style="color:var(--blue);"><?= (int)$todayResidentVehicleLogs ?></strong>
                        <span>Today Gate Logs</span>
                    </div>
                </div>
            </div>

            <div class="hero-car-area">
                <div class="cartoon-car-wrap">
                    <div class="cartoon-car-glow"></div>

                    <div class="cartoon-car" aria-hidden="true">
                        <div class="rear-wing"></div>
                        <div class="wing-post"></div>
                        <div class="roof"></div>
                        <div class="window"></div>
                        <div class="body-main"></div>
                        <div class="mirror"></div>
                        <div class="door-line"></div>
                        <div class="side-vent"></div>
                        <div class="highlight"></div>
                        <div class="headlight"></div>
                        <div class="taillight"></div>
                        <div class="wheel front"></div>
                        <div class="wheel back"></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="layout">
            <div class="panel list-panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="fas fa-users"></i>
                        Resident Vehicle List
                    </div>

                    <form method="GET" class="panel-search" autocomplete="off">
                        <input
                            type="search"
                            name="resident_keyword"
                            value="<?= e($search) ?>"
                            placeholder="Search resident..."
                            autocomplete="off"
                            autocorrect="off"
                            autocapitalize="off"
                            spellcheck="false"
                        >

                        <select name="payment" title="Filter payment status">
                            <option value="" <?= $paymentFilter === '' ? 'selected' : '' ?>>All Status</option>
                            <option value="paid" <?= $paymentFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
                            <option value="nopaid" <?= $paymentFilter === 'nopaid' ? 'selected' : '' ?>>No Paid</option>
                        </select>

                        <button type="submit" class="search-btn" title="Search">
                            <i class="fas fa-magnifying-glass"></i>
                        </button>

                        <a href="admin_resident_vehicles.php" class="reset-btn" title="Reset">
                            <i class="fas fa-rotate-left"></i>
                        </a>

                        <div class="toolbar-more-menu">
                            <button type="button" class="toolbar-more-btn" title="More actions" aria-label="More actions">
                                <i class="fas fa-ellipsis"></i>
                            </button>

                            <div class="toolbar-more-panel">
                                <button type="button" class="toolbar-menu-action" id="startDeactivateMode">
                                    <i class="fas fa-ban"></i>
                                    Deactivate
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="bulk-deactivate-bar">
                    <div class="bulk-deactivate-text">
                        <strong id="selectedDeactivateCount">0</strong> vehicle selected for deactivate.
                    </div>

                    <div class="bulk-deactivate-actions">
                        <button type="button" class="btn btn-light" id="cancelBulkDeactivate">
                            Cancel
                        </button>

                        <button type="button" class="btn btn-warning" id="confirmBulkDeactivate">
                            Confirm Deactivate
                        </button>
                    </div>
                </div>

                <form method="POST" id="bulkDeactivateForm" style="display:none;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="bulk_deactivate_vehicles">
                    <div id="bulkDeactivateInputs"></div>
                </form>

                <div class="panel-body">

                    <?php if (empty($vehicles)): ?>
                        <div class="empty">
                            No resident vehicle found.
                        </div>
                    <?php else: ?>
                        <div class="vehicle-list-wrap">
                            <div class="vehicle-list">
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <?php
                                        $residentName = $vehicle['resident_name'] ?: $vehicle['resident_email'];
                                        $unitText = unit_text_admin_vehicle($vehicle);
                                        $hasSamePlateBooking = (int)$vehicle['same_plate_booking_count'] > 0;

                                        $vehicleModel = trim((string)($vehicle['vehicle_model'] ?? ''));
                                        $vehicleColor = trim((string)($vehicle['vehicle_color'] ?? ''));
                                        $vehicleInfoParts = [];

                                        if ($vehicleModel !== '') {
                                            $vehicleInfoParts[] = $vehicleModel;
                                        }

                                        if ($vehicleColor !== '') {
                                            $vehicleInfoParts[] = $vehicleColor;
                                        }

                                        $vehicleInfoText = $vehicleInfoParts
                                            ? implode(' · ', $vehicleInfoParts)
                                            : 'Vehicle details not set';

                                        $subscription = resident_rolling_subscription_admin_vehicle(
                                            $pdo,
                                            (int)$vehicle['resident_id'],
                                            (int)$vehicle['id'],
                                            (int)$currentApartmentId
                                        );

                                        $paymentKey = (string)($subscription['key'] ?? 'unknown');
                                        $paymentLabel = (string)($subscription['label'] ?? 'Unknown');
                                        $paymentDetail = (string)($subscription['detail'] ?? 'Subscription status unknown');
                                    ?>

                                    <div
                                        class="vehicle-card <?= e($vehicle['status']) ?>"
                                        data-profile-url="admin_residents_manage.php?from=vehicles&resident_id=<?= (int)$vehicle['resident_id'] ?>&search=<?= urlencode((string)($vehicle['resident_email'] ?? $residentName ?? '')) ?>"
                                        title="Click to open resident profile"
                                    >
                                        <div class="vehicle-top">
                                            <div style="display:flex;align-items:center;gap:10px;">
                                                <label class="deactivate-select-box" title="Select vehicle">
                                                    <input
                                                        type="checkbox"
                                                        class="deactivate-check"
                                                        value="<?= (int)$vehicle['id'] ?>"
                                                        data-plate="<?= e($vehicle['plate_no']) ?>"
                                                        <?= $vehicle['status'] === 'inactive' ? 'disabled' : '' ?>
                                                    >
                                                </label>

                                                <span class="plate"><?= e($vehicle['plate_no']) ?></span>

                                            </div>

                                            <div style="display:flex;gap:7px;flex-wrap:wrap;justify-content:flex-end;">
                                                <span class="badge <?= e(vehicle_status_class($vehicle['status'])) ?>">
                                                    <?= e($vehicle['status']) ?>
                                                </span>

                                                <?php if ($hasSamePlateBooking): ?>
                                                    <span class="badge badge-warning">
                                                        Same Plate Booking
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="info-grid">
                                            <div class="info-box">
                                                <div class="info-label">Resident</div>
                                                <div class="info-value"><?= e(safe_text_admin_vehicle($residentName)) ?></div>
                                                <div class="info-subvalue"><?= e(safe_text_admin_vehicle($vehicle['resident_email'])) ?></div>
                                            </div>

                                            <div class="info-box">
                                                <div class="info-label">Unit</div>
                                                <div class="info-value"><?= e($unitText) ?></div>
                                            </div>

                                            <div class="info-box">
                                                <div class="info-label">Vehicle Info</div>
                                                <div class="info-value"><?= e($vehicleInfoText) ?></div>
                                                <div class="info-subvalue">Plate: <?= e($vehicle['plate_no']) ?></div>
                                            </div>

                                            <div class="info-box">
                                                <div class="info-label">Subscription Status</div>
                                                <div class="payment-mini <?= e(payment_badge_class_admin_vehicle($paymentKey)) ?>">
                                                    <?= e($paymentLabel) ?>
                                                </div>
                                                <div class="info-subvalue"><?= e($paymentDetail) ?></div>
                                            </div>
                                        </div>

                                        <?php if ($hasSamePlateBooking): ?>
                                            <div class="warning-text">
                                                Warning: This plate also exists in visitor bookings. Guard scan will treat this plate as resident vehicle first.
                                            </div>
                                        <?php endif; ?>

                                        <div class="actions">
                                            <?php if ($vehicle['status'] !== 'active'): ?>
                                                <form method="POST" data-safe-confirm="1" data-confirm-title="Activate this vehicle?" data-confirm-text="The guard can verify this vehicle plate after activation.">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="vehicle_id" value="<?= (int)$vehicle['id'] ?>">
                                                    <input type="hidden" name="new_status" value="active">
                                                    <button type="submit" class="btn btn-success">
                                                        Activate
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <a href="guard_logs.php?search=<?= urlencode($vehicle['plate_no']) ?>" class="btn btn-light">
                                                Gate Logs
                                            </a>

                                            <?php if ((int)$vehicle['gate_log_count'] === 0): ?>
                                                <form method="POST" data-safe-confirm="1" data-confirm-title="Delete this vehicle?" data-confirm-text="This action cannot be undone. Set inactive is safer if you want to keep history.">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete_vehicle">
                                                    <input type="hidden" name="vehicle_id" value="<?= (int)$vehicle['id'] ?>">
                                                    <button type="submit" class="btn btn-danger">
                                                        Delete
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if (in_array($paymentKey, ['expiring', 'expired', 'no_subscription'], true)): ?>
                                                <form method="POST" class="send-payment-reminder-form action-right">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="send_payment_reminder">
                                                    <input type="hidden" name="vehicle_id" value="<?= (int)$vehicle['id'] ?>">
                                                    <button type="submit" class="btn btn-primary">
                                                        <i class="fas fa-envelope"></i>
                                                        Email Reminder
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="footer-note">
                            Showing active resident vehicles only.
                        </div>
                    <?php endif; ?>
                </div>
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

    document.querySelectorAll('.plate-input').forEach(function (input) {
        input.addEventListener('input', function () {
            input.value = input.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        });
    });

    const toolbarMoreMenu = document.querySelector('.toolbar-more-menu');
    const toolbarMoreBtn = document.querySelector('.toolbar-more-btn');
    const startDeactivateMode = document.getElementById('startDeactivateMode');
    const selectedDeactivateCount = document.getElementById('selectedDeactivateCount');
    const confirmBulkDeactivate = document.getElementById('confirmBulkDeactivate');
    const cancelBulkDeactivate = document.getElementById('cancelBulkDeactivate');
    const bulkDeactivateForm = document.getElementById('bulkDeactivateForm');
    const bulkDeactivateInputs = document.getElementById('bulkDeactivateInputs');

    function getDeactivateChecks() {
        return Array.from(document.querySelectorAll('.deactivate-check')).filter(function (check) {
            return !check.disabled;
        });
    }

    function updateDeactivateSelectionUI() {
        const selected = getDeactivateChecks().filter(function (check) {
            return check.checked;
        });

        if (selectedDeactivateCount) {
            selectedDeactivateCount.textContent = String(selected.length);
        }

        getDeactivateChecks().forEach(function (check) {
            const card = check.closest('.vehicle-card');
            if (card) {
                card.classList.toggle('selected-card', check.checked);
            }
        });
    }

    function enableDeactivateMode() {
        document.body.classList.add('vehicle-deactivate-mode');
        if (toolbarMoreMenu) toolbarMoreMenu.classList.remove('open');
        const listPanel = document.querySelector('.list-panel');
        if (listPanel) listPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        updateDeactivateSelectionUI();
    }

    function disableDeactivateMode() {
        document.body.classList.remove('vehicle-deactivate-mode');
        getDeactivateChecks().forEach(function (check) {
            check.checked = false;
        });
        updateDeactivateSelectionUI();
    }

    toolbarMoreBtn?.addEventListener('click', function (event) {
        event.stopPropagation();
        toolbarMoreMenu?.classList.toggle('open');
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('.toolbar-more-menu')) {
            toolbarMoreMenu?.classList.remove('open');
        }
    });

    startDeactivateMode?.addEventListener('click', enableDeactivateMode);

    getDeactivateChecks().forEach(function (check) {
        check.addEventListener('change', updateDeactivateSelectionUI);
    });

    document.querySelectorAll('.vehicle-card').forEach(function (card) {
        card.addEventListener('click', function (event) {
            if (event.target.closest('a, button, input, select, textarea, form, label')) {
                return;
            }

            if (document.body.classList.contains('vehicle-deactivate-mode')) {
                const check = card.querySelector('.deactivate-check');
                if (check && !check.disabled) {
                    check.checked = !check.checked;
                    updateDeactivateSelectionUI();
                }
                return;
            }

            const profileUrl = card.dataset.profileUrl;
            if (profileUrl) {
                window.location.href = profileUrl;
            }
        });
    });

    cancelBulkDeactivate?.addEventListener('click', disableDeactivateMode);

    confirmBulkDeactivate?.addEventListener('click', function () {
        const selected = getDeactivateChecks().filter(function (check) {
            return check.checked;
        });

        if (!selected.length) {
            Swal.fire('No vehicle selected', 'Please tick at least one vehicle first.', 'info');
            return;
        }

        const plateList = selected.map(function (check) {
            return check.dataset.plate || check.value;
        }).join(', ');

        Swal.fire({
            icon: 'warning',
            title: 'Deactivate selected vehicles?',
            text: plateList,
            showCancelButton: true,
            confirmButtonText: 'Yes, deactivate',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b'
        }).then(function (result) {
            if (!result.isConfirmed || !bulkDeactivateForm || !bulkDeactivateInputs) return;

            bulkDeactivateInputs.innerHTML = '';

            selected.forEach(function (check) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'vehicle_ids[]';
                input.value = check.value;
                bulkDeactivateInputs.appendChild(input);
            });

            bulkDeactivateForm.submit();
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

document.querySelectorAll('.send-payment-reminder-form').forEach(form => {
    form.addEventListener('submit', function (event) {
        event.preventDefault();

        Swal.fire({
            title: 'Send payment reminder?',
            text: 'An email reminder will be sent if the subscription is due soon or expired.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, send email'
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });
});

</script>

</body>
</html>
