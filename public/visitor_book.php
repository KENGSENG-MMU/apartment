<?php
require_once '../core/security.php';
require_login(['visitor']);

if (file_exists('../core/feedback_auto.php')) {
    require_once '../core/feedback_auto.php';
}

$pdo = db();

$visitorId = (int)($_SESSION['uid'] ?? 0);
$visitorEmail = $_SESSION['email'] ?? '';

$message = '';
$error = '';
$autoFeedbackTrigger = null;

if (isset($_SESSION['visitor_book_flash']) && is_array($_SESSION['visitor_book_flash'])) {
    $message = $_SESSION['visitor_book_flash']['message'] ?? '';
    $error = $_SESSION['visitor_book_flash']['error'] ?? '';
    $autoFeedbackTrigger = $_SESSION['visitor_book_flash']['auto_feedback_trigger'] ?? null;
    unset($_SESSION['visitor_book_flash']);
}

function safe_text($value) {
    return $value !== null && $value !== '' ? $value : '-';
}

function table_exists_visitor_book(PDO $pdo, string $table): bool {
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

function has_column_visitor_book(PDO $pdo, string $table, string $column): bool {
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

function ensure_column_visitor_book(PDO $pdo, string $table, string $column, string $definition): void {
    if (!table_exists_visitor_book($pdo, $table)) {
        return;
    }

    if (!has_column_visitor_book($pdo, $table, $column)) {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (Throwable $e) {
            // Ignore if the database does not allow ALTER or the column already exists.
        }
    }
}

function safe_count_visitor_book(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function clean_plate_visitor_book($plate): string {
    $plate = strtoupper(trim((string)$plate));
    return preg_replace('/[^A-Z0-9]/', '', $plate);
}

function booking_status_class($status) {
    return match ($status) {
        'pending' => 'badge-pending',
        'approved', 'allocated' => 'badge-approved',
        'waiting' => 'badge-waiting',
        'checked_in' => 'badge-checkedin',
        'completed', 'checked_out', 'closed' => 'badge-completed',
        'rejected', 'expired', 'cancelled' => 'badge-rejected',
        default => 'badge-default'
    };
}

function generate_qr_token_visitor_book(): string {
    return bin2hex(random_bytes(24));
}

function visitor_book_time_slots(): array {
    return [
        '08:00' => '08:00 AM - 10:00 AM',
        '10:00' => '10:00 AM - 12:00 PM',
        '12:00' => '12:00 PM - 02:00 PM',
        '14:00' => '02:00 PM - 04:00 PM',
        '16:00' => '04:00 PM - 06:00 PM',
        '18:00' => '06:00 PM - 08:00 PM',
        '20:00' => '08:00 PM - 10:00 PM',
        '22:00' => '10:00 PM - 12:00 AM'
    ];
}

function default_visitor_book_slot(array $slots): string {
    $currentHour = (int)date('H');
    $currentMinute = (int)date('i');

    foreach (array_keys($slots) as $slotStart) {
        [$hour, $minute] = array_map('intval', explode(':', $slotStart));

        if ($hour > $currentHour || ($hour === $currentHour && $minute > $currentMinute)) {
            return $slotStart;
        }
    }

    return array_key_first($slots);
}


function visitor_book_public_base_url(): string {
    // Optional manual setting in core/config.php:
    // define('SVMS_PUBLIC_BASE_URL', 'http://192.168.1.20/apartment/public');
    if (defined('SVMS_PUBLIC_BASE_URL') && trim((string)SVMS_PUBLIC_BASE_URL) !== '') {
        return rtrim((string)SVMS_PUBLIC_BASE_URL, '/');
    }

    $isHttps =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // If request is made using localhost, the email link will not work on phone.
    // Try to convert localhost to the computer LAN IP automatically.
    if (preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/i', $host, $matches)) {
        $port = $matches[2] ?? '';
        $computerIp = gethostbyname(gethostname());

        if (filter_var($computerIp, FILTER_VALIDATE_IP) && !in_array($computerIp, ['127.0.0.1', '::1'], true)) {
            $host = $computerIp . $port;
        }
    }

    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/apartment/public/visitor_book.php';
    $publicDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    return $scheme . '://' . $host . $publicDir;
}

function visitor_book_resident_request_url(int $bookingId): string {
    return visitor_book_public_base_url() . '/resident_requests.php?booking_id=' . $bookingId;
}

function visitor_book_load_phpmailer(): bool {
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

function visitor_book_send_resident_request_email(
    string $toEmail,
    string $residentName,
    string $visitorName,
    string $visitorEmail,
    string $visitorContact,
    string $visitorIc,
    string $plateNo,
    string $purpose,
    string $visitType,
    string $unitLabel,
    string $startTime,
    string $endTime,
    string $requestUrl,
    ?string &$mailError = null
): bool {
    $mailError = null;

    if (trim($toEmail) === '') {
        $mailError = 'Resident email is empty.';
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
        $mailError = 'SMTP is not configured. Please check core/mail_config.php.';
        return false;
    }

    if (!visitor_book_load_phpmailer()) {
        $mailError = 'PHPMailer is not installed.';
        return false;
    }

    $safeResidentName = htmlspecialchars($residentName !== '' ? $residentName : $toEmail, ENT_QUOTES, 'UTF-8');
    $safeVisitorName = htmlspecialchars($visitorName, ENT_QUOTES, 'UTF-8');
    $safeVisitorEmail = htmlspecialchars($visitorEmail, ENT_QUOTES, 'UTF-8');
    $safeVisitorContact = htmlspecialchars($visitorContact, ENT_QUOTES, 'UTF-8');
    $safeVisitorIc = htmlspecialchars($visitorIc, ENT_QUOTES, 'UTF-8');
    $safePlate = htmlspecialchars($plateNo, ENT_QUOTES, 'UTF-8');
    $safePurpose = nl2br(htmlspecialchars($purpose, ENT_QUOTES, 'UTF-8'));
    $safeVisitType = htmlspecialchars($visitType, ENT_QUOTES, 'UTF-8');
    $safeUnitLabel = htmlspecialchars($unitLabel, ENT_QUOTES, 'UTF-8');
    $startText = date('d M Y, g:i A', strtotime($startTime));
    $endText = date('d M Y, g:i A', strtotime($endTime));

    $html = "
        <div style='margin:0;padding:0;background:#f3f6fb;font-family:Arial,sans-serif;color:#111827;'>
            <div style='max-width:650px;margin:0 auto;padding:28px 16px;'>
                <div style='background:#ffffff;border:1px solid #e5e7eb;border-radius:20px;overflow:hidden;box-shadow:0 18px 40px rgba(15,23,42,.10);'>
                    <div style='background:linear-gradient(135deg,#0f172a,#2563eb);padding:26px;color:#ffffff;'>
                        <h1 style='margin:0;font-size:26px;line-height:1.25;'>New Visitor Request</h1>
                        <p style='margin:9px 0 0;color:#dbeafe;font-size:14px;line-height:1.5;'>A visitor has submitted a request to visit your unit.</p>
                    </div>

                    <div style='padding:24px;'>
                        <p style='margin:0 0 16px;font-size:15px;line-height:1.6;'>Hello <strong>{$safeResidentName}</strong>,</p>
                        <p style='margin:0 0 18px;font-size:15px;line-height:1.6;'>
                            Please review this visitor request in SmartVMS. You can approve or reject it from the Visitor Requests page.
                        </p>

                        <table style='width:100%;border-collapse:collapse;margin:18px 0;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;'>
                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;width:38%;'>Visitor Name</td><td style='padding:12px;'>{$safeVisitorName}</td></tr>
                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Visitor Email</td><td style='padding:12px;'>{$safeVisitorEmail}</td></tr>
                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Phone Number</td><td style='padding:12px;'>{$safeVisitorContact}</td></tr>
                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>IC / Passport</td><td style='padding:12px;'>{$safeVisitorIc}</td></tr>
                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Vehicle Plate</td><td style='padding:12px;font-weight:bold;'>{$safePlate}</td></tr>
                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Unit</td><td style='padding:12px;'>{$safeUnitLabel}</td></tr>
                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Visit Type</td><td style='padding:12px;'>{$safeVisitType}</td></tr>
                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Arrival</td><td style='padding:12px;'>{$startText}</td></tr>
                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Valid Until</td><td style='padding:12px;'>{$endText}</td></tr>
                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Purpose</td><td style='padding:12px;line-height:1.5;'>{$safePurpose}</td></tr>
                        </table>

                        <div style='margin:22px 0 0;padding:16px 18px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:14px;color:#1e3a8a;font-size:14px;line-height:1.6;'>
                            Please log in to SmartVMS and go to <strong>Resident Dashboard &gt; Visitor Requests</strong> to review this request.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    ";

    $plainText = "SmartVMS New Visitor Request\n\n" .
        "Hello {$residentName},\n\n" .
        "A visitor has submitted a request to visit your unit.\n\n" .
        "Visitor Name: {$visitorName}\n" .
        "Visitor Email: {$visitorEmail}\n" .
        "Phone Number: {$visitorContact}\n" .
        "IC / Passport: {$visitorIc}\n" .
        "Vehicle Plate: {$plateNo}\n" .
        "Unit: {$unitLabel}\n" .
        "Visit Type: {$visitType}\n" .
        "Arrival: {$startText}\n" .
        "Valid Until: {$endText}\n" .
        "Purpose: {$purpose}\n\n" .
        "Please log in to SmartVMS and go to Resident Dashboard > Visitor Requests to review this request.\n";

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
        $mail->addAddress($toEmail, $residentName !== '' ? $residentName : $toEmail);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = 'SmartVMS New Visitor Request - ' . $visitorName;
        $mail->Body = $html;
        $mail->AltBody = $plainText;
        $mail->send();

        return true;
    } catch (Throwable $e) {
        $mailError = $e->getMessage();
        return false;
    }
}

/*
|--------------------------------------------------------------------------
| Database compatibility
|--------------------------------------------------------------------------
| IC / Passport and phone are stored with each booking. If the columns are
| missing in an older database, this page will try to add them automatically.
|--------------------------------------------------------------------------
*/
ensure_column_visitor_book($pdo, 'bookings', 'visitor_ic', 'VARCHAR(50) NULL AFTER visitor_name');
ensure_column_visitor_book($pdo, 'bookings', 'visitor_contact', 'VARCHAR(50) NULL AFTER visitor_ic');
ensure_column_visitor_book($pdo, 'users', 'profile_photo', 'VARCHAR(255) NULL AFTER contact_number');

$hasFullName = has_column_visitor_book($pdo, 'users', 'full_name');
$hasContact = has_column_visitor_book($pdo, 'users', 'contact_number');
$hasProfilePhoto = has_column_visitor_book($pdo, 'users', 'profile_photo');

$hasPurpose = has_column_visitor_book($pdo, 'bookings', 'purpose');
$hasQrToken = has_column_visitor_book($pdo, 'bookings', 'qr_token');
$hasSlotId = has_column_visitor_book($pdo, 'bookings', 'slot_id');
$hasVisitorType = has_column_visitor_book($pdo, 'bookings', 'visitor_type');
$hasVisitType = has_column_visitor_book($pdo, 'bookings', 'visit_type');
$hasUpdatedAt = has_column_visitor_book($pdo, 'bookings', 'updated_at');
$hasVisitorIc = has_column_visitor_book($pdo, 'bookings', 'visitor_ic');
$hasVisitorContact = has_column_visitor_book($pdo, 'bookings', 'visitor_contact');
$hasApartmentId = has_column_visitor_book($pdo, 'bookings', 'apartment_id');
$hasVisitDate = has_column_visitor_book($pdo, 'bookings', 'visit_date');
$hasVisitorEmail = has_column_visitor_book($pdo, 'bookings', 'visitor_email');
$hasVisitorPhone = has_column_visitor_book($pdo, 'bookings', 'visitor_phone');

$visitorNameSql = $hasFullName ? "u.full_name AS visitor_account_name" : "NULL AS visitor_account_name";
$visitorPhotoSql = $hasProfilePhoto ? "u.profile_photo AS profile_photo" : "NULL AS profile_photo";
$residentNameSql = $hasFullName ? "res.full_name AS resident_name" : "NULL AS resident_name";
$residentContactSql = $hasContact ? "res.contact_number AS resident_contact" : "NULL AS resident_contact";

$stmt = $pdo->prepare("
    SELECT
        u.id,
        u.email,
        {$visitorNameSql},
        {$visitorPhotoSql}
    FROM users u
    WHERE u.id = ?
    LIMIT 1
");
$stmt->execute([$visitorId]);
$visitorAccount = $stmt->fetch();

$defaultVisitorName = $visitorAccount['visitor_account_name'] ?: explode('@', $visitorEmail)[0];
$visitorInitial = strtoupper(substr($defaultVisitorName ?: 'V', 0, 1));

$visitorProfilePhoto = '';
if (!empty($visitorAccount['profile_photo'])) {
    $photoPath = (string)$visitorAccount['profile_photo'];

    if (preg_match('/^https?:\/\//i', $photoPath)) {
        $visitorProfilePhoto = $photoPath;
    } else {
        $photoPath = ltrim($photoPath, '/');
        if (file_exists(__DIR__ . '/' . $photoPath)) {
            $visitorProfilePhoto = $photoPath;
        }
    }
}


/*
|--------------------------------------------------------------------------
| Unit selection list
|--------------------------------------------------------------------------
| Visitor can see ALL units, including units with no active resident.
| If a selected unit has no active resident, the UI will show it clearly and
| submission will be blocked because there is no resident to approve the request.
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT
        un.id AS unit_id,
        a.id AS apartment_id,
        a.apartment_name,
        un.block_no,
        un.floor_no,
        un.unit_no,

        res.id AS resident_id,
        res.email AS resident_email,
        {$residentNameSql},
        {$residentContactSql}

    FROM units un

    LEFT JOIN apartments a ON a.id = un.apartment_id

    LEFT JOIN resident_units ru
        ON ru.unit_id = un.id
        AND ru.status = 'active'

    LEFT JOIN users res
        ON res.id = ru.resident_id
        AND res.role = 'resident'
        AND res.status = 'active'

    ORDER BY
        a.apartment_name ASC,
        un.block_no ASC,
        CAST(un.floor_no AS UNSIGNED) ASC,
        un.floor_no ASC,
        un.unit_no ASC,
        res.email ASC
");
$stmt->execute();
$unitRows = $stmt->fetchAll();

$unitOptionsByUnit = [];

foreach ($unitRows as $row) {
    $unitId = (int)$row['unit_id'];

    if (isset($unitOptionsByUnit[$unitId]) && !empty($unitOptionsByUnit[$unitId]['resident_id'])) {
        continue;
    }

    $residentDisplay = $row['resident_name'] ?: $row['resident_email'];
    $unitText =
        'Block ' . $row['block_no'] .
        ' / Floor ' . $row['floor_no'] .
        ' / Unit ' . $row['unit_no'];

    $unitOptionsByUnit[$unitId] = [
        'unit_id' => $unitId,
        'resident_id' => !empty($row['resident_id']) ? (int)$row['resident_id'] : 0,
        'resident_name' => $residentDisplay ?: '',
        'resident_email' => $row['resident_email'] ?: '',
        'has_resident' => !empty($row['resident_id']) ? 1 : 0,
        'apartment_id' => (string)($row['apartment_id'] ?? ''),
        'apartment_name' => $row['apartment_name'] ?? '',
        'block_no' => (string)$row['block_no'],
        'floor_no' => (string)$row['floor_no'],
        'unit_no' => (string)$row['unit_no'],
        'unit_text' => $unitText
    ];
}

$unitOptions = array_values($unitOptionsByUnit);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        try {
            $visitorName = trim($_POST['visitor_name'] ?? '');
            $visitorIc = strtoupper(trim($_POST['visitor_ic'] ?? ''));
            $visitorContact = trim($_POST['visitor_contact'] ?? '');
            $plateNo = clean_plate_visitor_book($_POST['plate_no'] ?? '');
            $unitId = (int)($_POST['unit_id'] ?? 0);
            $purpose = trim($_POST['purpose'] ?? '');
            $visitDate = trim($_POST['visit_date'] ?? '');
            $visitSlot = trim($_POST['visit_slot'] ?? '');
            $visitorType = 'Single Visitor';
            $visitType = $_POST['visit_type'] ?? 'One Time';

            if ($visitorName === '') {
                throw new Exception('Please enter your full name.');
            }

            if ($visitorIc === '') {
                throw new Exception('Please enter IC / Passport number.');
            }

            if ($visitorContact === '') {
                throw new Exception('Please enter phone number.');
            }

            if ($plateNo === '') {
                throw new Exception('Please enter vehicle plate number.');
            }

            if (strlen($plateNo) < 3) {
                throw new Exception('Vehicle plate number is too short.');
            }

            if ($unitId <= 0) {
                throw new Exception('Please select apartment, block, floor, and unit.');
            }

            if ($purpose === '') {
                throw new Exception('Please enter purpose of visit.');
            }

            if (!in_array($visitType, ['One Time', 'Multiple In-Out'], true)) {
                $visitType = 'One Time';
            }

            $timeSlots = visitor_book_time_slots();

            if ($visitDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $visitDate)) {
                throw new Exception('Please select a valid visit date.');
            }

            if ($visitSlot === '' || !array_key_exists($visitSlot, $timeSlots)) {
                throw new Exception('Please select a valid time slot.');
            }

            $startTimestamp = strtotime($visitDate . ' ' . $visitSlot . ':00');

            if ($startTimestamp === false) {
                throw new Exception('Please select a valid arrival date and time.');
            }

            if ($startTimestamp < time() - 300) {
                throw new Exception('Arrival time cannot be in the past.');
            }

            $durationHours = $visitType === 'Multiple In-Out' ? 8 : 2;
            $startTime = date('Y-m-d H:i:s', $startTimestamp);
            $endTime = date('Y-m-d H:i:s', strtotime('+' . $durationHours . ' hours', $startTimestamp));

            $unitCheck = $pdo->prepare("
                SELECT
                    id,
                    apartment_id,
                    block_no,
                    floor_no,
                    unit_no
                FROM units
                WHERE id = ?
                LIMIT 1
            ");
            $unitCheck->execute([$unitId]);
            $selectedUnit = $unitCheck->fetch();

            if (!$selectedUnit) {
                throw new Exception('Selected unit does not exist.');
            }

            $stmt = $pdo->prepare("
                SELECT
                    res.id,
                    res.email,
                    {$residentNameSql},
                    ru.unit_id,
                    un.block_no,
                    un.floor_no,
                    un.unit_no
                FROM users res
                JOIN resident_units ru
                    ON ru.resident_id = res.id
                    AND ru.status = 'active'
                JOIN units un ON un.id = ru.unit_id
                WHERE ru.unit_id = ?
                AND res.role = 'resident'
                AND res.status = 'active'
                ORDER BY res.id ASC
                LIMIT 1
            ");
            $stmt->execute([$unitId]);
            $resident = $stmt->fetch();

            if (!$resident) {
                throw new Exception(
                    'This unit has no active resident assigned yet. Please select another unit or contact management.'
                );
            }

            $residentId = (int)$resident['id'];

            if (table_exists_visitor_book($pdo, 'blacklisted_plates')) {
                $blacklistCheck = $pdo->prepare("
                    SELECT id
                    FROM blacklisted_plates
                    WHERE plate_no = ?
                    AND status = 'active'
                    LIMIT 1
                ");
                $blacklistCheck->execute([$plateNo]);

                if ($blacklistCheck->fetch()) {
                    throw new Exception('This vehicle plate is blacklisted and cannot submit visit request.');
                }
            }

            $overlapCheck = $pdo->prepare("
                SELECT id
                FROM bookings
                WHERE plate_no = ?
                AND status IN ('pending', 'approved', 'allocated', 'waiting', 'checked_in')
                AND (
                    start_time < ?
                    AND end_time > ?
                )
                LIMIT 1
            ");
            $overlapCheck->execute([
                $plateNo,
                $endTime,
                $startTime
            ]);

            if ($overlapCheck->fetch()) {
                throw new Exception('This plate already has an active booking during the selected time.');
            }

            $columns = [
                'visitor_user_id',
                'resident_id',
                'visitor_name',
                'plate_no',
                'start_time',
                'end_time',
                'status',
                'created_at'
            ];

            $marks = [
                '?',
                '?',
                '?',
                '?',
                '?',
                '?',
                '?',
                'NOW()'
            ];

            $values = [
                $visitorId,
                $residentId,
                $visitorName,
                $plateNo,
                $startTime,
                $endTime,
                'pending'
            ];

            if ($hasApartmentId) {
                $columns[] = 'apartment_id';
                $marks[] = '?';
                $values[] = !empty($selectedUnit['apartment_id']) ? (int)$selectedUnit['apartment_id'] : null;
            }

            if ($hasVisitDate) {
                $columns[] = 'visit_date';
                $marks[] = '?';
                $values[] = $visitDate;
            }

            if ($hasVisitorEmail) {
                $columns[] = 'visitor_email';
                $marks[] = '?';
                $values[] = $visitorEmail;
            }

            if ($hasVisitorPhone) {
                $columns[] = 'visitor_phone';
                $marks[] = '?';
                $values[] = $visitorContact;
            }

            if ($hasVisitorIc) {
                $columns[] = 'visitor_ic';
                $marks[] = '?';
                $values[] = $visitorIc;
            }

            if ($hasVisitorContact) {
                $columns[] = 'visitor_contact';
                $marks[] = '?';
                $values[] = $visitorContact;
            }

            if ($hasPurpose) {
                $columns[] = 'purpose';
                $marks[] = '?';
                $values[] = $purpose;
            }

            if ($hasVisitorType) {
                $columns[] = 'visitor_type';
                $marks[] = '?';
                $values[] = $visitorType;
            }

            if ($hasVisitType) {
                $columns[] = 'visit_type';
                $marks[] = '?';
                $values[] = $visitType;
            }

            if ($hasQrToken) {
                $columns[] = 'qr_token';
                $marks[] = '?';
                $values[] = generate_qr_token_visitor_book();
            }

            if ($hasSlotId) {
                $columns[] = 'slot_id';
                $marks[] = 'NULL';
            }

            if ($hasUpdatedAt) {
                $columns[] = 'updated_at';
                $marks[] = 'NULL';
            }

            $stmt = $pdo->prepare("
                INSERT INTO bookings
                (" . implode(', ', $columns) . ")
                VALUES
                (" . implode(', ', $marks) . ")
            ");
            $stmt->execute($values);

            $bookingId = (int)$pdo->lastInsertId();

            if (function_exists('create_notification')) {
                create_notification(
                    $pdo,
                    $residentId,
                    'New Visitor Request',
                    $visitorName . ' submitted a visit request. Plate: ' . $plateNo,
                    'booking'
                );
            }

            $requestUrl = visitor_book_resident_request_url($bookingId);
            $unitInfo = $unitOptionsByUnit[$unitId] ?? [];
            $unitLabel = trim(
                ($unitInfo['apartment_name'] ?? '') .
                (($unitInfo['apartment_name'] ?? '') !== '' ? ' - ' : '') .
                ($unitInfo['unit_text'] ?? ('Block ' . $resident['block_no'] . ' / Floor ' . $resident['floor_no'] . ' / Unit ' . $resident['unit_no']))
            );
            $residentName = trim((string)($resident['resident_name'] ?? ''));
            $mailError = null;
            $residentMailSent = visitor_book_send_resident_request_email(
                (string)($resident['email'] ?? ''),
                $residentName,
                $visitorName,
                $visitorEmail,
                $visitorContact,
                $visitorIc,
                $plateNo,
                $purpose,
                $visitType,
                $unitLabel,
                $startTime,
                $endTime,
                $requestUrl,
                $mailError
            );

            if (!$residentMailSent && function_exists('log_audit')) {
                log_audit(
                    'RESIDENT_REQUEST_EMAIL_FAILED',
                    'Booking #' . $bookingId . ' resident email failed: ' . ($mailError ?: 'Unknown error')
                );
            }

            if (function_exists('log_audit')) {
                log_audit(
                    'VISITOR_BOOKING_CREATED',
                    'Visitor submitted booking #' . $bookingId . ' to resident ' . $resident['email'] . '. Plate: ' . $plateNo
                );
            }

            if ($residentMailSent) {
                $message = 'Visit request submitted successfully. An email notification has been sent to the resident.';
            } else {
                $message = 'Visit request submitted successfully. Website notification was created, but resident email could not be sent. Please check SMTP setting if testing email.';
            }

        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    $_SESSION['visitor_book_flash'] = [
        'message' => $message,
        'error' => $error,
        'auto_feedback_trigger' => ($message !== '' && $error === '') ? [
            'function_key' => 'visitor_book',
            'function_name' => 'Visitor Booking'
        ] : null
    ];

    header('Location: visitor_book.php');
    exit;
}

$purposeSelectSql = $hasPurpose ? "b.purpose" : "NULL AS purpose";
$visitorTypeSelectSql = $hasVisitorType ? "b.visitor_type" : "NULL AS visitor_type";
$visitTypeSelectSql = $hasVisitType ? "b.visit_type" : "NULL AS visit_type";
$qrTokenSelectSql = $hasQrToken ? "b.qr_token" : "NULL AS qr_token";
$visitorIcSelectSql = $hasVisitorIc ? "b.visitor_ic" : "NULL AS visitor_ic";
$visitorContactSelectSql = $hasVisitorContact ? "b.visitor_contact" : "NULL AS visitor_contact";
$slotSelectJoin = $hasSlotId ? "LEFT JOIN parking_slots ps ON ps.id = b.slot_id" : "LEFT JOIN parking_slots ps ON 1 = 0";

$stmt = $pdo->prepare("
    SELECT
        b.id,
        b.visitor_name,
        b.plate_no,
        b.start_time,
        b.end_time,
        b.status,
        b.created_at,
        {$purposeSelectSql},
        {$visitorTypeSelectSql},
        {$visitTypeSelectSql},
        {$qrTokenSelectSql},
        {$visitorIcSelectSql},
        {$visitorContactSelectSql},
        res.email AS resident_email,
        {$residentNameSql},
        a.apartment_name,
        un.block_no,
        un.floor_no,
        un.unit_no,
        ps.block_name AS parking_block,
        ps.slot_no AS parking_slot_no
    FROM bookings b
    LEFT JOIN users res ON res.id = b.resident_id
    LEFT JOIN resident_units ru
        ON ru.resident_id = b.resident_id
        AND ru.status = 'active'
    LEFT JOIN units un ON un.id = ru.unit_id
    LEFT JOIN apartments a ON a.id = un.apartment_id
    {$slotSelectJoin}
    WHERE b.visitor_user_id = ?
    ORDER BY b.created_at DESC
    LIMIT 30
");
$stmt->execute([$visitorId]);
$myBookings = $stmt->fetchAll();

$totalMyBookings = safe_count_visitor_book($pdo, "SELECT COUNT(*) FROM bookings WHERE visitor_user_id = ?", [$visitorId]);

/*
|--------------------------------------------------------------------------
| Visitor quick fill history
|--------------------------------------------------------------------------
| Use previous bookings as saved visit details and plate suggestions.
| No extra table is needed: once a visitor submits a booking, the details
| become available here for one-click fill next time.
|--------------------------------------------------------------------------
*/
$quickFillTemplates = [];
$savedPlateOptions = [];

try {
    $quickVisitorIcSql = $hasVisitorIc ? "b.visitor_ic" : "NULL AS visitor_ic";
    $quickContactSql = $hasVisitorContact
        ? "b.visitor_contact"
        : ($hasVisitorPhone ? "b.visitor_phone AS visitor_contact" : "NULL AS visitor_contact");
    $quickPurposeSql = $hasPurpose ? "b.purpose" : "NULL AS purpose";
    $quickVisitTypeSql = $hasVisitType ? "b.visit_type" : "NULL AS visit_type";

    $stmt = $pdo->prepare("
        SELECT
            b.id,
            b.visitor_name,
            {$quickVisitorIcSql},
            {$quickContactSql},
            b.plate_no,
            {$quickPurposeSql},
            {$quickVisitTypeSql},
            b.created_at,

            ru.unit_id,
            a.id AS apartment_id,
            a.apartment_name,
            un.block_no,
            un.floor_no,
            un.unit_no
        FROM bookings b
        LEFT JOIN resident_units ru
            ON ru.resident_id = b.resident_id
            AND ru.status = 'active'
        LEFT JOIN units un ON un.id = ru.unit_id
        LEFT JOIN apartments a ON a.id = un.apartment_id
        WHERE b.visitor_user_id = ?
        ORDER BY b.created_at DESC
        LIMIT 12
    ");
    $stmt->execute([$visitorId]);
    $historyRows = $stmt->fetchAll() ?: [];

    $seenTemplates = [];
    $seenPlates = [];

    foreach ($historyRows as $row) {
        $plate = clean_plate_visitor_book($row['plate_no'] ?? '');

        if ($plate !== '' && !isset($seenPlates[$plate])) {
            $savedPlateOptions[] = [
                'plate_no' => $plate,
                'label' => $plate,
                'last_used' => !empty($row['created_at']) ? date('d M Y', strtotime($row['created_at'])) : ''
            ];
            $seenPlates[$plate] = true;
        }

        $templateKey = strtoupper(trim((string)($row['visitor_name'] ?? ''))) . '|' .
            strtoupper(trim((string)($row['visitor_ic'] ?? ''))) . '|' .
            strtoupper($plate) . '|' .
            (int)($row['unit_id'] ?? 0);

        if (isset($seenTemplates[$templateKey])) {
            continue;
        }

        $unitLabelParts = [];
        if (!empty($row['apartment_name'])) {
            $unitLabelParts[] = $row['apartment_name'];
        }
        if (!empty($row['block_no']) || !empty($row['floor_no']) || !empty($row['unit_no'])) {
            $unitLabelParts[] = trim(
                'Block ' . ($row['block_no'] ?? '-') .
                ' / Floor ' . ($row['floor_no'] ?? '-') .
                ' / Unit ' . ($row['unit_no'] ?? '-')
            );
        }

        $quickFillTemplates[] = [
            'visitor_name' => (string)($row['visitor_name'] ?? ''),
            'visitor_ic' => (string)($row['visitor_ic'] ?? ''),
            'visitor_contact' => (string)($row['visitor_contact'] ?? ''),
            'plate_no' => $plate,
            'purpose' => (string)($row['purpose'] ?? ''),
            'visit_type' => (string)($row['visit_type'] ?? 'One Time'),
            'unit_id' => !empty($row['unit_id']) ? (int)$row['unit_id'] : 0,
            'apartment_id' => !empty($row['apartment_id']) ? (string)$row['apartment_id'] : '',
            'block_no' => (string)($row['block_no'] ?? ''),
            'floor_no' => (string)($row['floor_no'] ?? ''),
            'unit_no' => (string)($row['unit_no'] ?? ''),
            'unit_label' => implode(' · ', array_filter($unitLabelParts)),
            'last_used' => !empty($row['created_at']) ? date('d M Y, g:i A', strtotime($row['created_at'])) : ''
        ];

        $seenTemplates[$templateKey] = true;

        if (count($quickFillTemplates) >= 5) {
            break;
        }
    }
} catch (Throwable $e) {
    $quickFillTemplates = [];
    $savedPlateOptions = [];
}


$pendingBookings = safe_count_visitor_book($pdo, "SELECT COUNT(*) FROM bookings WHERE visitor_user_id = ? AND status = 'pending'", [$visitorId]);
$approvedBookings = safe_count_visitor_book($pdo, "SELECT COUNT(*) FROM bookings WHERE visitor_user_id = ? AND status IN ('approved','allocated')", [$visitorId]);
$checkedInBookings = safe_count_visitor_book($pdo, "SELECT COUNT(*) FROM bookings WHERE visitor_user_id = ? AND status = 'checked_in'", [$visitorId]);
$completedBookings = safe_count_visitor_book($pdo, "SELECT COUNT(*) FROM bookings WHERE visitor_user_id = ? AND status IN ('completed','checked_out','closed')", [$visitorId]);
$rejectedBookings = safe_count_visitor_book($pdo, "SELECT COUNT(*) FROM bookings WHERE visitor_user_id = ? AND status IN ('rejected','cancelled','expired')", [$visitorId]);

$todayDate = date('Y-m-d');
$timeSlots = visitor_book_time_slots();
$defaultVisitDate = $todayDate;
$defaultVisitSlot = default_visitor_book_slot($timeSlots);

if ($defaultVisitSlot === array_key_first($timeSlots) && strtotime($todayDate . ' ' . $defaultVisitSlot . ':00') < time()) {
    $defaultVisitDate = date('Y-m-d', strtotime('+1 day'));
}

$maxVisitDate = date('Y-m-d', strtotime('+30 days'));
$unitOptionsJson = json_encode($unitOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$quickFillJson = json_encode([
    'templates' => $quickFillTemplates,
    'plates' => $savedPlateOptions
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Book Visit - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>

        :root {
            --bg:#eef3f8; --card:#fff; --text:#111827; --muted:#667085; --border:#dfe8f3;
            --blue:#2563eb; --blue-soft:#eff6ff; --pink:#fb9db7; --yellow:#f9c96b;
            --green:#60b96f; --purple:#b487f5; --red:#dc2626;
            --shadow:0 20px 52px rgba(15,23,42,.08);
        }
        *{box-sizing:border-box;font-family:'Plus Jakarta Sans',sans-serif;margin:0;padding:0}
        body{min-height:100vh;color:var(--text);overflow-x:hidden;position:relative;background:radial-gradient(circle at 18% 18%,rgba(203,213,225,.28),transparent 28%),radial-gradient(circle at 85% 20%,rgba(219,234,254,.55),transparent 30%),linear-gradient(180deg,#fbfdff 0%,var(--bg) 100%)}
        a{text-decoration:none}.cute-bg{position:fixed;inset:0;pointer-events:none;z-index:0;overflow:hidden}
        .cloud{position:absolute;width:110px;height:36px;border:2px solid #dce7f3;border-radius:999px;background:rgba(255,255,255,.65);box-shadow:0 10px 25px rgba(148,163,184,.10)}
        .cloud:before,.cloud:after{content:"";position:absolute;background:rgba(255,255,255,.85);border:2px solid #dce7f3;border-bottom:none;border-radius:999px 999px 0 0}.cloud:before{width:42px;height:32px;left:18px;top:-20px}.cloud:after{width:54px;height:42px;right:18px;top:-29px}.cloud-left{left:6%;top:19%}.cloud-right{right:15%;top:16%;transform:scale(.85)}
        .sparkle{position:absolute;color:#f6c765;font-size:1.4rem;opacity:.75;animation:floatSparkle 4s ease-in-out infinite}.s1{left:19%;top:22%}.s2{left:13%;top:62%;color:#9cc8ff;animation-delay:.6s}.s3{right:12%;top:42%;color:#f6c765;animation-delay:1.1s}.s4{right:19%;top:67%;color:#c6d8ee;animation-delay:1.7s}@keyframes floatSparkle{0%,100%{transform:translateY(0) scale(1);opacity:.55}50%{transform:translateY(-9px) scale(1.08);opacity:.95}}
        .cute-plant{position:absolute;left:8%;bottom:8%;width:120px;height:150px;opacity:.95}.pot{position:absolute;bottom:0;left:25px;width:72px;height:62px;background:#fff4e6;border:2px solid #e7c8a5;border-radius:28px 28px 30px 30px;box-shadow:0 12px 22px rgba(148,163,184,.16)}.leaf{position:absolute;background:#b9e2ae;border:2px solid #8abd87;width:45px;height:28px;border-radius:50% 0 50% 0}.leaf-one{left:37px;top:45px;transform:rotate(-42deg)}.leaf-two{left:61px;top:48px;transform:rotate(30deg)}.leaf-three{left:48px;top:20px;width:34px;height:52px;transform:rotate(16deg)}.pot span{position:absolute;width:7px;height:7px;background:#5f6368;border-radius:50%;top:31px}.pot span:first-child{left:23px}.pot span:nth-child(2){right:23px}.pot em{position:absolute;left:32px;top:41px;width:12px;height:7px;border-bottom:2px solid #5f6368;border-radius:0 0 999px 999px}
        .cute-bird{position:absolute;right:15%;top:46%;width:145px;height:80px;opacity:.92}.cute-bird:before{content:"";width:48px;height:38px;background:#fff;border:2px solid #9ac7e6;border-radius:50% 55% 45% 50%;position:absolute;left:0;top:8px;box-shadow:0 10px 20px rgba(148,163,184,.12)}.cute-bird:after{content:"";position:absolute;left:48px;top:34px;width:92px;height:32px;border-bottom:2px dashed #d6e0ea;border-radius:50%;transform:rotate(8deg)}.cute-bird span{position:absolute;left:7px;top:24px;width:22px;height:16px;background:#dff0ff;border:1px solid #9ac7e6;border-radius:50%;transform:rotate(-18deg);z-index:1}
        .cute-bush{position:absolute;right:8%;bottom:4%;width:170px;height:85px;opacity:.88}.cute-bush span{position:absolute;bottom:0;background:#cfecc8;border:2px solid #a9d3a0;border-radius:999px 999px 20px 20px}.cute-bush span:nth-child(1){width:72px;height:56px;left:0}.cute-bush span:nth-child(2){width:96px;height:78px;left:42px}.cute-bush span:nth-child(3){width:60px;height:50px;right:0}
        .visitor-navbar{width:100%;background:rgba(255,255,255,.95);backdrop-filter:blur(16px);border-bottom:1px solid var(--border);padding:14px 5%;display:flex;justify-content:space-between;align-items:center;gap:18px;position:sticky;top:0;z-index:80;box-shadow:0 10px 30px rgba(15,23,42,.06)}.logo{color:var(--text);font-size:1.15rem;font-weight:900;letter-spacing:-.045em;white-space:nowrap}.logo span{color:var(--blue)}.nav-links{display:flex;align-items:center;justify-content:flex-end;gap:9px;flex-wrap:wrap}.nav-links a{color:#334155;background:#fff;border:1px solid var(--border);padding:8px 12px;border-radius:999px;font-size:.78rem;font-weight:900;display:inline-flex;align-items:center;gap:7px;transition:.18s ease;box-shadow:0 6px 16px rgba(15,23,42,.04)}.nav-links a:hover{background:#f8fafc;transform:translateY(-1px)}.nav-links a.active{border-color:#bfdbfe;background:var(--blue-soft);color:#1d4ed8}.nav-links a.logout{color:var(--red)}
        .page{width:min(820px,calc(100% - 36px));margin:34px auto 70px;position:relative;z-index:1}.cute-title-box{display:flex;align-items:center;gap:18px;margin-bottom:20px}.title-sticker{width:72px;height:72px;background:#fff5ea;border:2px solid #f5d2b1;border-radius:22px;display:flex;align-items:center;justify-content:center;color:#7b8794;font-size:1.65rem;position:relative;transform:rotate(-8deg);box-shadow:0 14px 28px rgba(148,163,184,.16)}.title-sticker b{position:absolute;right:-10px;bottom:-8px;width:30px;height:30px;border-radius:50%;background:#ffd5df;border:2px solid #f7a0b5;color:#fb8ca8;font-size:1rem;display:flex;align-items:center;justify-content:center}.page-title{font-size:clamp(2rem,3.4vw,2.75rem);font-weight:900;letter-spacing:-.07em;line-height:1.05;margin-bottom:8px}.page-sub{color:#697586;font-size:.98rem;font-weight:750;line-height:1.55}.tiny-heart{color:#fb8ca8;margin-left:5px}
        .panel{background:rgba(255,255,255,.97);border:1px solid var(--border);border-radius:28px;box-shadow:var(--shadow);overflow:hidden}.panel-header{padding:18px 22px;border-bottom:1px solid #edf0f3;display:flex;justify-content:space-between;align-items:center;gap:14px}.panel-title{font-size:1rem;font-weight:900;display:inline-flex;align-items:center;gap:10px}.panel-sticker{width:31px;height:31px;border-radius:10px;background:#fff6ed;border:1px solid #f5d2b1;color:#e79b56;display:inline-flex;align-items:center;justify-content:center}.account-wrap{display:flex;align-items:center;gap:11px}.account-mini{text-align:right;color:#536172;font-size:.76rem;font-weight:850;line-height:1.35}.mini-mascot{width:38px;height:38px;border-radius:50%;background:#fff1c7;border:2px solid #f7c86b;color:#b7791f;font-size:.72rem;font-weight:900;display:flex;align-items:center;justify-content:center;box-shadow:0 10px 18px rgba(247,200,107,.16)}.panel-body{padding:21px 22px 24px}.alert{padding:14px 15px;border-radius:16px;margin-bottom:16px;font-weight:850;line-height:1.45}.alert.success{background:#ecfdf3;color:#027a48;border:1px solid #abefc6}.alert.error{background:#fef3f2;color:#b42318;border:1px solid #fecdca}.note-box{background:linear-gradient(135deg,#f8fbff,#eff6ff);color:#475569;border:1px solid #cfe1ff;padding:13px 15px;border-radius:16px;font-size:.83rem;font-weight:800;line-height:1.55;margin-bottom:16px}.note-box:before{content:"i";width:21px;height:21px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:#e0efff;color:var(--blue);border:1px solid #93c5fd;margin-right:9px;font-weight:900}
        .field{margin-bottom:15px}label{display:block;font-size:.7rem;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:7px}input,select,textarea{width:100%;padding:12px 13px;border:1px solid var(--border);border-radius:15px;font-weight:800;outline:none;background:#fff;color:var(--text)}.input-icon-wrap{position:relative}.input-icon-wrap input{padding-right:43px}.input-icon{position:absolute;right:14px;top:50%;transform:translateY(-50%);font-size:.95rem;opacity:.78}.input-icon.blue{color:#60a5fa}.input-icon.purple{color:#b487f5}.input-icon.green{color:#60b96f}.input-icon.slate{color:#94a3b8}.textarea-wrap{position:relative}textarea{min-height:86px;resize:vertical;line-height:1.5;padding-right:44px}.textarea-heart{position:absolute;right:14px;bottom:13px;color:#fb9db7;font-size:1.6rem;font-weight:900;pointer-events:none}input:focus,select:focus,textarea:focus{border-color:#93c5fd;box-shadow:0 0 0 4px rgba(37,99,235,.10)}input:-webkit-autofill,input:-webkit-autofill:hover,input:-webkit-autofill:focus,input:-webkit-autofill:active{-webkit-box-shadow:0 0 0 1000px #fff inset!important;-webkit-text-fill-color:var(--text)!important;caret-color:var(--text)!important;transition:background-color 9999s ease-in-out 0s!important}input::selection{background:rgba(96,165,250,.25);color:var(--text)}input[readonly]{background:#f8fafc;color:#475569}.plate-input{text-transform:uppercase;font-family:monospace;letter-spacing:.06em}.unit-grid{display:grid;grid-template-columns:1fr;gap:10px}.apartment-row{width:100%}.unit-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.type-grid,.visitor-grid,.time-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.radio-card{border:1px solid var(--border);background:#f8fafc;border-radius:15px;padding:12px;cursor:pointer;font-weight:900;font-size:.82rem;color:#475569;display:flex;align-items:center;gap:8px}.radio-card input{width:auto;margin:0;accent-color:var(--blue)}.unit-preview{display:none;margin-top:10px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;border-radius:14px;padding:11px 12px;font-size:.82rem;font-weight:850;line-height:1.45}.unit-preview.show{display:block}.btn{border:none;cursor:pointer;padding:12px 15px;border-radius:999px;font-weight:900;display:inline-flex;align-items:center;justify-content:center;gap:8px;font-size:.84rem;transition:.18s ease;white-space:nowrap}.btn:hover{transform:translateY(-1px)}.btn-primary{width:100%;background:linear-gradient(135deg,#38bdf8,#2563eb);color:#fff;box-shadow:0 14px 26px rgba(37,99,235,.18)}
        
        .quick-fill-box{
            margin:14px 0 18px;
            border:1px solid #dbeafe;
            background:linear-gradient(135deg,rgba(239,246,255,.96),rgba(255,255,255,.96));
            border-radius:22px;
            padding:14px;
            box-shadow:0 16px 34px rgba(37,99,235,.07);
        }
        .quick-fill-head{
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:12px;
            margin-bottom:12px;
        }
        .quick-fill-title{
            display:flex;
            align-items:center;
            gap:10px;
            font-weight:950;
            color:#0f172a;
            letter-spacing:-.02em;
        }
        .quick-fill-title span{
            width:34px;
            height:34px;
            border-radius:14px;
            display:grid;
            place-items:center;
            background:#dbeafe;
            color:#2563eb;
        }
        .quick-fill-sub{
            margin-top:4px;
            color:#64748b;
            font-size:.78rem;
            font-weight:750;
            line-height:1.35;
        }
        .quick-fill-actions{
            display:flex;
            flex-wrap:wrap;
            gap:10px;
        }
        .quick-fill-main-btn,
        .plate-chip{
            border:1px solid #bfdbfe;
            background:#fff;
            color:#1d4ed8;
            min-height:38px;
            padding:0 13px;
            border-radius:999px;
            font-size:.78rem;
            font-weight:900;
            cursor:pointer;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            box-shadow:0 8px 18px rgba(37,99,235,.06);
            transition:.18s ease;
        }
        .quick-fill-main-btn:hover,
        .plate-chip:hover{
            transform:translateY(-1px);
            background:#eff6ff;
        }
        .quick-fill-main-btn{
            background:linear-gradient(135deg,#38bdf8,#2563eb);
            color:#fff;
            border-color:transparent;
            min-height:42px;
            padding:0 16px;
        }
        .quick-fill-templates{
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:10px;
            margin:10px 0 12px;
        }
        .quick-template-card{
            border:1px solid #dbeafe;
            background:rgba(255,255,255,.86);
            border-radius:18px;
            padding:11px;
            display:grid;
            gap:8px;
            cursor:pointer;
            text-align:left;
            transition:.18s ease;
        }
        .quick-template-card:hover{
            transform:translateY(-1px);
            border-color:#93c5fd;
            box-shadow:0 12px 24px rgba(37,99,235,.09);
        }
        .quick-template-top{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:8px;
        }
        .quick-template-name{
            font-size:.86rem;
            font-weight:950;
            color:#0f172a;
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .quick-template-plate{
            background:#0f172a;
            color:#fff;
            padding:6px 9px;
            border-radius:10px;
            font-size:.75rem;
            font-weight:950;
            letter-spacing:.05em;
            font-family:monospace;
            white-space:nowrap;
        }
        .quick-template-meta{
            color:#64748b;
            font-size:.73rem;
            font-weight:750;
            line-height:1.35;
        }
        .quick-empty{
            padding:12px;
            border:1px dashed #bfdbfe;
            border-radius:16px;
            color:#64748b;
            font-size:.78rem;
            font-weight:750;
            background:rgba(255,255,255,.7);
        }
        @media(max-width:720px){
            .quick-fill-head{flex-direction:column}
            .quick-fill-templates{grid-template-columns:1fr}
        }


        @media(max-width:900px){.cute-plant,.cute-bird,.cute-bush,.cloud{display:none}.page{width:min(820px,calc(100% - 28px))}}@media(max-width:720px){.visitor-navbar{flex-direction:column;align-items:flex-start}.nav-links{width:100%;display:grid;grid-template-columns:1fr 1fr}.nav-links a{justify-content:center;text-align:center}.panel-header,.cute-title-box{flex-direction:column;align-items:flex-start}.account-wrap{width:100%;justify-content:space-between}.account-mini{text-align:left}.unit-grid,.unit-row,.type-grid,.visitor-grid,.time-grid{grid-template-columns:1fr}}


        .date-slot-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}.calendar-picker-field{width:100%;min-height:58px;padding:10px 42px 10px 13px;border:1px solid var(--border);border-radius:15px;background:#fff;color:var(--text);text-align:left;position:relative;cursor:pointer;transition:.18s ease}.calendar-picker-field:hover{border-color:#93c5fd;box-shadow:0 0 0 4px rgba(37,99,235,.08)}.calendar-picker-main{display:block;font-size:.86rem;font-weight:900}.calendar-picker-sub{display:block;margin-top:5px;color:#667085;font-size:.67rem;font-weight:850}.calendar-picker-field i{position:absolute;right:14px;top:50%;transform:translateY(-50%);color:#60a5fa}.slot-select-wrap select{padding-right:42px;appearance:none;-webkit-appearance:none}.calendar-modal{position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;padding:24px}.calendar-modal.show{display:flex}.calendar-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.35);backdrop-filter:blur(7px)}.calendar-panel{position:relative;width:min(560px,94vw);max-height:88vh;overflow-y:auto;background:#fff;color:#111827;border-radius:32px;padding:34px 34px 28px;box-shadow:0 30px 90px rgba(15,23,42,.25);animation:calendarPop .2s ease}@keyframes calendarPop{from{transform:translateY(16px) scale(.96);opacity:0}to{transform:translateY(0) scale(1);opacity:1}}.calendar-top{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}.calendar-month{font-size:clamp(3.3rem,8vw,5rem);line-height:.92;font-weight:950;letter-spacing:-.08em;color:#050505}.calendar-year{margin-top:16px;font-size:1.65rem;color:#111827;font-weight:500}.calendar-close{border:none;width:44px;height:44px;border-radius:50%;background:#f3f4f6;color:#374151;cursor:pointer;display:inline-flex;align-items:center;justify-content:center}.calendar-close:hover{background:#e5e7eb}.calendar-nav{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px}.calendar-nav button{border:none;height:42px;min-width:42px;padding:0 16px;border-radius:999px;background:#f3f4f6;color:#111827;font-weight:900;cursor:pointer}.calendar-nav button:hover:not(:disabled){background:#e5e7eb}.calendar-nav button:disabled{opacity:.35;cursor:not-allowed}.calendar-weekdays{display:grid;grid-template-columns:repeat(7,1fr);text-align:center;margin:8px 0 18px;color:#4b5563;font-size:1.18rem;font-weight:600}.calendar-weekdays span:first-child,.calendar-weekdays span:last-child{color:#f97316}.calendar-days{display:grid;grid-template-columns:repeat(7,1fr);gap:14px 8px}.calendar-day{position:relative;border:none;background:transparent;min-height:54px;border-radius:22px;color:#050505;font-size:1.55rem;font-weight:500;cursor:pointer;display:flex;align-items:center;justify-content:center}.calendar-day:hover:not(:disabled){background:#f3f4f6}.calendar-day.other-month{color:#9ca3af}.calendar-day:disabled{color:#d1d5db;cursor:not-allowed;background:transparent}.calendar-day.selected{color:#050505;font-weight:950}.calendar-day.selected::before{content:"";position:absolute;width:48px;height:48px;border-radius:50%;background:rgba(251,146,60,.35);z-index:0;transform:translate(6px,5px)}.calendar-day span{position:relative;z-index:1}.calendar-day.today::after{content:"";position:absolute;bottom:4px;width:7px;height:7px;border-radius:50%;background:#b7b7b7}.calendar-selected-text{margin-top:28px;color:#9ca3af;font-size:1.35rem;font-weight:500}
        @media(max-width:720px){.date-slot-grid{grid-template-columns:1fr}}
    </style>

<style id="smartvms-unified-header-style">

/* =========================================================
   SmartVMS Unified Header Style
   Purpose: Keep all page headers / navbars same size and color
   ========================================================= */
:root {
    --svms-header-bg: #1f2937;
    --svms-header-border: rgba(255, 255, 255, 0.08);
    --svms-header-text: #e5e7eb;
    --svms-header-muted: #cbd5e1;
    --svms-header-hover: rgba(59, 130, 246, 0.12);
    --svms-blue: #3b82f6;
    --svms-blue-dark: #2563eb;
    --svms-red: #ef4444;
}

body {
    font-family: 'Plus Jakarta Sans', sans-serif;
}

/* Top navbar: resident / visitor / admin common */
.navbar,
.visitor-navbar,
.resident-topbar,
.admin-top-nav,
.topbar,
header.navbar,
header.visitor-navbar {
    height: 64px !important;
    min-height: 64px !important;
    width: 100% !important;
    padding: 0 5% !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    background: var(--svms-header-bg) !important;
    color: var(--svms-header-text) !important;
    border-bottom: 1px solid var(--svms-header-border) !important;
    box-shadow: 0 10px 28px rgba(15, 23, 42, 0.12) !important;
    position: sticky !important;
    top: 0 !important;
    z-index: 1000 !important;
}

/* Logo / brand */
.brand,
.logo,
.resident-brand,
.admin-brand,
.navbar .brand,
.visitor-navbar .logo,
.resident-topbar .resident-brand {
    font-size: 1.35rem !important;
    line-height: 1 !important;
    font-weight: 900 !important;
    letter-spacing: -0.04em !important;
    color: #ffffff !important;
    white-space: nowrap !important;
}

.brand span,
.logo span,
.resident-brand span,
.admin-brand span {
    color: var(--svms-blue) !important;
}

/* Nav group */
.nav-links,
.resident-nav,
.admin-nav-links,
.navbar .nav-links,
.visitor-navbar .nav-links,
.resident-topbar .resident-nav {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 10px !important;
    flex-wrap: nowrap !important;
}

/* Nav links / buttons */
.nav-btn,
.nav-links a,
.resident-nav a,
.admin-top-nav a,
.admin-nav-links a,
.visitor-navbar .nav-links a,
.navbar .nav-links a,
.resident-topbar .resident-nav a {
    min-height: 38px !important;
    padding: 9px 15px !important;
    border-radius: 12px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    font-size: 0.86rem !important;
    line-height: 1 !important;
    font-weight: 800 !important;
    text-decoration: none !important;
    color: var(--svms-header-muted) !important;
    background: transparent !important;
    border: 1px solid transparent !important;
    transition: 0.2s ease !important;
    white-space: nowrap !important;
}

.nav-btn i,
.nav-links a i,
.resident-nav a i,
.admin-top-nav a i,
.admin-nav-links a i,
.visitor-navbar .nav-links a i,
.navbar .nav-links a i,
.resident-topbar .resident-nav a i {
    font-size: 0.9rem !important;
    line-height: 1 !important;
}

.nav-btn:hover,
.nav-links a:hover,
.resident-nav a:hover,
.admin-top-nav a:hover,
.admin-nav-links a:hover,
.visitor-navbar .nav-links a:hover,
.navbar .nav-links a:hover,
.resident-topbar .resident-nav a:hover,
.nav-btn.active,
.nav-links a.active,
.resident-nav a.active,
.admin-nav-links a.active,
.visitor-navbar .nav-links a.active,
.navbar .nav-links a.active,
.resident-topbar .resident-nav a.active {
    color: #ffffff !important;
    background: var(--svms-header-hover) !important;
    border-color: rgba(96, 165, 250, 0.38) !important;
}

.nav-btn.logout,
.nav-links a.logout,
.resident-nav a.logout,
.admin-nav-links a.logout,
.navbar .nav-links a[href*="logout"],
.visitor-navbar .nav-links a[href*="logout"],
.resident-topbar .resident-nav a[href*="logout"] {
    color: var(--svms-red) !important;
}

/* Notification badge remains same size everywhere */
.nav-notification-badge,
.notification-badge,
.badge-notification {
    min-width: 20px !important;
    height: 20px !important;
    padding: 0 6px !important;
    border-radius: 999px !important;
    background: var(--svms-red) !important;
    color: #ffffff !important;
    font-size: 0.68rem !important;
    font-weight: 900 !important;
    line-height: 20px !important;
    text-align: center !important;
}

/* Main page hero title/subtitle standard */
.banner-info h1,
.hero-title,
.page-title,
.page-hero h1,
.hero h1,
.banner h1,
.request-hero h1,
.profile-hero h1,
.top-hero h1 {
    font-size: clamp(2rem, 3vw, 2.8rem) !important;
    line-height: 1.08 !important;
    font-weight: 900 !important;
    letter-spacing: -0.06em !important;
    color: #ffffff !important;
}

.banner-info p,
.hero-subtitle,
.page-subtitle,
.page-hero p,
.hero p,
.banner p,
.request-hero p,
.profile-hero p,
.top-hero p {
    font-size: 1rem !important;
    line-height: 1.65 !important;
    font-weight: 600 !important;
    color: rgba(255, 255, 255, 0.82) !important;
}

/* Responsive header */
@media (max-width: 760px) {
    .navbar,
    .visitor-navbar,
    .resident-topbar,
    .admin-top-nav,
    .topbar {
        height: auto !important;
        min-height: 64px !important;
        padding: 12px 18px !important;
        gap: 12px !important;
        flex-wrap: wrap !important;
    }

    .nav-links,
    .resident-nav,
    .admin-nav-links {
        width: 100% !important;
        justify-content: flex-start !important;
        overflow-x: auto !important;
        padding-bottom: 3px !important;
    }

    .brand,
    .logo,
    .resident-brand,
    .admin-brand {
        font-size: 1.22rem !important;
    }

    .nav-btn,
    .nav-links a,
    .resident-nav a,
    .admin-nav-links a {
        font-size: 0.78rem !important;
        padding: 8px 12px !important;
    }
}

</style>

<style id="visitor-profile-dropdown-style">
.visitor-profile-menu {
    position: relative;
    display: inline-flex;
    align-items: center;
}

.profile-trigger {
    border: 1px solid rgba(96,165,250,.45);
    background: rgba(59,130,246,.14);
    color: #ffffff;
    min-height: 42px;
    padding: 6px 11px 6px 7px;
    border-radius: 16px;
    display: inline-flex;
    align-items: center;
    gap: 9px;
    cursor: pointer;
    font-size: .78rem;
    font-weight: 900;
    transition: .18s ease;
}

.profile-trigger:hover,
.profile-trigger.active,
.visitor-profile-menu:focus-within .profile-trigger,
.visitor-profile-menu:hover .profile-trigger {
    background: rgba(59,130,246,.22);
    transform: translateY(-1px);
}

.profile-avatar-mini,
.dropdown-avatar {
    border-radius: 50%;
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    color: #2563eb;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 900;
    overflow: hidden;
    flex-shrink: 0;
}

.profile-avatar-mini {
    width: 30px;
    height: 30px;
    font-size: .84rem;
    border: 2px solid rgba(255,255,255,.22);
}

.profile-avatar-mini img,
.dropdown-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-trigger-name {
    max-width: 120px;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.visitor-profile-dropdown {
    position: absolute;
    right: 0;
    top: calc(100% + 12px);
    width: 292px;
    padding: 10px;
    border-radius: 22px;
    background: rgba(255,255,255,.98);
    border: 1px solid #dbe5f0;
    box-shadow: 0 22px 50px rgba(15,23,42,.18);
    z-index: 3000;
    display: none;
}

.visitor-profile-dropdown::before {
    content: "";
    position: absolute;
    right: 22px;
    top: -8px;
    width: 16px;
    height: 16px;
    background: rgba(255,255,255,.98);
    border-left: 1px solid #dbe5f0;
    border-top: 1px solid #dbe5f0;
    transform: rotate(45deg);
}

.visitor-profile-menu:hover .visitor-profile-dropdown,
.visitor-profile-menu:focus-within .visitor-profile-dropdown {
    display: block;
}

.dropdown-head {
    padding: 14px;
    border-radius: 18px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    display: flex;
    align-items: center;
    gap: 13px;
    margin-bottom: 8px;
}

.dropdown-avatar {
    width: 52px;
    height: 52px;
    font-size: 1.15rem;
}

.dropdown-name {
    color: #0f172a;
    font-size: .95rem;
    font-weight: 900;
    line-height: 1.2;
}

.dropdown-sub {
    color: #64748b;
    font-size: .76rem;
    font-weight: 800;
    margin-top: 3px;
}

.dropdown-links {
    padding: 4px 0;
}

.dropdown-link {
    min-height: 52px;
    padding: 12px 13px;
    border-radius: 16px;
    color: #0f172a !important;
    background: transparent !important;
    border: 0 !important;
    box-shadow: none !important;
    display: flex !important;
    align-items: center;
    gap: 12px !important;
    font-size: .88rem !important;
    font-weight: 900 !important;
    text-decoration: none;
}

.dropdown-link:hover {
    background: #f8fafc !important;
    transform: none !important;
}

.dropdown-link i {
    width: 36px;
    height: 36px;
    border-radius: 12px;
    background: #eff6ff;
    color: #2563eb;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.dropdown-footer {
    margin-top: 6px;
    padding-top: 8px;
    border-top: 1px solid #e2e8f0;
}

.dropdown-logout {
    color: #dc2626 !important;
}

.dropdown-logout i {
    background: #fff1f2;
    color: #dc2626;
}

@media (max-width: 720px) {
    .visitor-profile-menu {
        width: 100%;
    }
    .profile-trigger {
        width: 100%;
        justify-content: center;
    }
    .visitor-profile-dropdown {
        right: auto;
        left: 0;
        width: min(292px, 100%);
    }
}
</style>

<style id="visitor-book-profile-fix">
.visitor-photo-dot {
    overflow: hidden !important;
    color: #2563eb !important;
    font-size: .95rem !important;
    background: #eff6ff !important;
    border-color: #bfdbfe !important;
}
.visitor-photo-dot img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.account-mini span {
    color: #64748b;
    font-size: .72rem;
}
.nav-links > a.logout {
    display: none !important;
}
</style>


<style id="visitor-dropdown-polish-v2">
.visitor-profile-dropdown {
    background: #ffffff !important;
    border: 1px solid #dbe5f0 !important;
    box-shadow: 0 24px 55px rgba(15, 23, 42, .20) !important;
}

.visitor-profile-dropdown .dropdown-head {
    background: linear-gradient(135deg, #eff6ff, #dbeafe) !important;
    border: 1px solid #bfdbfe !important;
}

.visitor-profile-dropdown .dropdown-name,
.visitor-profile-dropdown .dropdown-sub,
.visitor-profile-dropdown .dropdown-link,
.visitor-profile-dropdown .dropdown-link strong {
    color: #0f172a !important;
}

.visitor-profile-dropdown .dropdown-sub {
    color: #64748b !important;
}

.visitor-profile-dropdown .dropdown-link {
    background: #ffffff !important;
    border: 1px solid transparent !important;
    box-shadow: none !important;
    opacity: 1 !important;
}

.visitor-profile-dropdown .dropdown-link:hover {
    background: #f8fafc !important;
    border-color: #e2e8f0 !important;
}

.visitor-profile-dropdown .dropdown-link i {
    background: #eff6ff !important;
    color: #2563eb !important;
}

.visitor-profile-dropdown .dropdown-logout,
.visitor-profile-dropdown .dropdown-logout strong {
    color: #dc2626 !important;
}

.visitor-profile-dropdown .dropdown-logout i {
    background: #fff1f2 !important;
    color: #dc2626 !important;
}
</style>



<style id="visitor-dropdown-left-style-final">
.visitor-profile-dropdown .dropdown-links {
    display: grid !important;
    gap: 8px !important;
    padding: 6px 0 !important;
}

.nav-links .visitor-profile-dropdown a.dropdown-link,
.visitor-profile-dropdown a.dropdown-link,
.visitor-profile-dropdown a.dropdown-link:visited,
.visitor-profile-dropdown a.dropdown-link:focus,
.visitor-profile-dropdown a.dropdown-link:focus-visible,
.visitor-profile-dropdown a.dropdown-link:active {
    width: 100% !important;
    min-height: 56px !important;
    padding: 0 14px !important;
    border-radius: 16px !important;
    background: #ffffff !important;
    border: 1px solid transparent !important;
    color: #0f172a !important;
    box-shadow: none !important;
    outline: none !important;

    display: flex !important;
    align-items: center !important;
    justify-content: flex-start !important;
    text-align: left !important;
    gap: 12px !important;
    transform: none !important;
}

.nav-links .visitor-profile-dropdown a.dropdown-link:hover,
.visitor-profile-dropdown a.dropdown-link:hover {
    background: #f8fafc !important;
    border-color: #e2e8f0 !important;
    color: #0f172a !important;
    justify-content: flex-start !important;
    transform: none !important;
}

.visitor-profile-dropdown a.dropdown-link i {
    width: 36px !important;
    height: 36px !important;
    min-width: 36px !important;
    border-radius: 12px !important;
    background: #eff6ff !important;
    color: #2563eb !important;

    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    flex-shrink: 0 !important;
}

.visitor-profile-dropdown a.dropdown-link strong {
    color: #0f172a !important;
    font-size: .88rem !important;
    font-weight: 900 !important;
    line-height: 1 !important;
    text-align: left !important;
    margin: 0 !important;
}

.visitor-profile-dropdown .dropdown-footer {
    margin-top: 8px !important;
    padding-top: 10px !important;
    border-top: 1px solid #e2e8f0 !important;
}

.visitor-profile-dropdown .dropdown-footer a.dropdown-link,
.visitor-profile-dropdown .dropdown-footer a.dropdown-link:visited,
.visitor-profile-dropdown .dropdown-footer a.dropdown-link:focus,
.visitor-profile-dropdown .dropdown-footer a.dropdown-link:focus-visible,
.visitor-profile-dropdown .dropdown-footer a.dropdown-link:active {
    color: #dc2626 !important;
    background: #ffffff !important;
    border-color: transparent !important;
}

.visitor-profile-dropdown .dropdown-footer a.dropdown-link:hover {
    background: #fff7f7 !important;
    border-color: #fecaca !important;
}

.visitor-profile-dropdown .dropdown-footer a.dropdown-link strong {
    color: #dc2626 !important;
}

.visitor-profile-dropdown .dropdown-footer a.dropdown-link i {
    background: #fff1f2 !important;
    color: #dc2626 !important;
}
</style>

</head>
<body>

<div class="cute-bg" aria-hidden="true">
    <div class="cloud cloud-left"></div>
    <div class="cloud cloud-right"></div>
    <div class="sparkle s1">✦</div>
    <div class="sparkle s2">✧</div>
    <div class="sparkle s3">✦</div>
    <div class="sparkle s4">✧</div>
    <div class="cute-plant">
        <div class="leaf leaf-one"></div>
        <div class="leaf leaf-two"></div>
        <div class="leaf leaf-three"></div>
        <div class="pot"><span></span><span></span><em></em></div>
    </div>
    <div class="cute-bird"><span></span></div>
    <div class="cute-bush"><span></span><span></span><span></span></div>
</div>


<nav class="visitor-navbar">
    <div class="logo">Smart<span>VMS</span></div>

    <div class="nav-links">
        <a href="visitor_book.php" class="active">
            <i class="fas fa-calendar-plus"></i>
            Book Visit
        </a>

        <?php
        if (file_exists('notification_badge.php')) {
            include 'notification_badge.php';
        }
        ?>

        <a href="visitor_history.php" class="">
            <i class="fas fa-clock-rotate-left"></i>
            History
        </a>

        <div class="visitor-profile-menu">
            <button type="button" class="profile-trigger" aria-label="Visitor profile menu">
                <span class="profile-avatar-mini">
                    <?php if (!empty($visitorProfilePhoto)): ?>
                        <img src="<?= e($visitorProfilePhoto) ?>" alt="Visitor photo">
                    <?php else: ?>
                        <?= e($visitorInitial) ?>
                    <?php endif; ?>
                </span>
                <span class="profile-trigger-name"><?= e($defaultVisitorName ?? $currentName ?? $visitorName ?? 'Visitor') ?></span>
                <i class="fas fa-chevron-down"></i>
            </button>

            <div class="visitor-profile-dropdown">
                <div class="dropdown-head">
                    <div class="dropdown-avatar">
                        <?php if (!empty($visitorProfilePhoto)): ?>
                            <img src="<?= e($visitorProfilePhoto) ?>" alt="Visitor photo">
                        <?php else: ?>
                            <?= e($visitorInitial) ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="dropdown-name"><?= e($defaultVisitorName ?? $currentName ?? $visitorName ?? 'Visitor') ?></div>
                        <div class="dropdown-sub">Visitor Account</div>
                    </div>
                </div>

                <div class="dropdown-links">
                    <a href="visitor_profile.php" class="dropdown-link">
                        <i class="fas fa-user"></i>
                        <strong>My Profile</strong>
                    </a>

                    <a href="visitor_settings.php" class="dropdown-link">
                        <i class="fas fa-lock"></i>
                        <strong>Change Password</strong>
                    </a>
                </div>

                <div class="dropdown-footer">
                    <a href="../core/logout.php" class="dropdown-link dropdown-logout">
                        <i class="fas fa-power-off"></i>
                        <strong>Logout</strong>
                    </a>
                </div>
            </div>
        </div>
    </div>
</nav>

<main class="page">
    <div class="page-title-box cute-title-box">
        <div class="title-sticker">
            <i class="fas fa-clipboard-list"></i>
            <b>♥</b>
        </div>
        <div>
            <h1 class="page-title">Book a Visit</h1>
            <p class="page-sub">
                Fill in your visit details and submit the request to the resident for approval. <span class="tiny-heart">♥</span>
            </p>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert success"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="panel">
        <div class="panel-header">
            <div class="panel-title">
                <span class="panel-sticker"><i class="fas fa-clipboard-list"></i></span>
                Visit Request Form
            </div>

            <div class="account-wrap">
                <div class="account-mini">
                    <?= e($defaultVisitorName) ?>
                    <br>
                    <span>Visitor Account</span>
                </div>
                <div class="mini-mascot visitor-photo-dot">
                    <?php if (!empty($visitorProfilePhoto)): ?>
                        <img src="<?= e($visitorProfilePhoto) ?>" alt="Visitor photo">
                    <?php else: ?>
                        <?= e($visitorInitial) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="panel-body">
            <div class="note-box">
                Select the apartment, block, floor and unit you want to visit. Units without active residents are shown, but they cannot receive requests until a resident is assigned.
            </div>

            <form method="POST" id="visitForm" novalidate autocomplete="off">
                <?= csrf_field() ?>

                <input type="hidden" name="unit_id" id="unit_id" value="">

                <div class="quick-fill-box">
                    <div class="quick-fill-head">
                        <div>
                            <div class="quick-fill-title">
                                <span><i class="fas fa-wand-magic-sparkles"></i></span>
                                Quick Fill
                            </div>
                            <div class="quick-fill-sub">
                                Use your previous visit details and saved vehicle plates. Useful if you visit the same resident often.
                            </div>
                        </div>

                        <?php if (!empty($quickFillTemplates)): ?>
                            <button type="button" class="quick-fill-main-btn" onclick="applyQuickFill(0)">
                                <i class="fas fa-bolt"></i> Use Last Visit
                            </button>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($quickFillTemplates)): ?>
                        <div class="quick-fill-templates">
                            <?php foreach (array_slice($quickFillTemplates, 0, 2) as $index => $template): ?>
                                <button type="button" class="quick-template-card" onclick="applyQuickFill(<?= (int)$index ?>)">
                                    <div class="quick-template-top">
                                        <div class="quick-template-name"><?= e($template['visitor_name'] ?: 'Saved visitor') ?></div>
                                        <div class="quick-template-plate"><?= e($template['plate_no'] ?: 'NO PLATE') ?></div>
                                    </div>
                                    <div class="quick-template-meta">
                                        <?= e($template['unit_label'] ?: 'Unit can be selected manually') ?><br>
                                        Last used: <?= e($template['last_used'] ?: '-') ?>
                                    </div>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="quick-empty">
                            No saved visit yet. After your first booking, this page can remember your details and vehicle plates.
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($savedPlateOptions)): ?>
                        <div class="quick-fill-actions">
                            <?php foreach ($savedPlateOptions as $plate): ?>
                                <button type="button" class="plate-chip" onclick="selectSavedPlate(<?= json_encode($plate['plate_no']) ?>)">
                                    <i class="fas fa-car-side"></i> <?= e($plate['plate_no']) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label>Select Resident Unit</label>
                    <div class="unit-grid">
                        <div class="apartment-row">
                            <select id="apartmentSelect" required>
                                <option value="">Apartment</option>
                            </select>
                        </div>

                        <div class="unit-row">
                            <select id="blockSelect" required disabled>
                                <option value="">Block</option>
                            </select>

                            <select id="floorSelect" required disabled>
                                <option value="">Floor</option>
                            </select>

                            <select id="unitSelect" required disabled>
                                <option value="">Unit</option>
                            </select>
                        </div>
                    </div>

                    <div class="unit-preview" id="unitPreview"></div>

                    <?php if (empty($unitOptions)): ?>
                        <div class="small" style="color:#dc2626;">
                            No units found. Please ask admin to create units first.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label>Visit Type</label>
                    <div class="type-grid">
                        <label class="radio-card">
                            <input type="radio" name="visit_type" value="One Time" checked onchange="updateDurationDisplay()">
                            One Time
                        </label>

                        <label class="radio-card">
                            <input type="radio" name="visit_type" value="Multiple In-Out" onchange="updateDurationDisplay()">
                            Multiple In-Out
                        </label>
                    </div>
                </div>

                <div class="visitor-grid">
                    <div class="field">
                        <label>Your Full Name</label>
                        <div class="input-icon-wrap">
                            <input type="text" name="visitor_name" autocomplete="off" value="<?= e($defaultVisitorName) ?>" placeholder="Example: Ali Bin Abu" required>
                            <i class="fas fa-user input-icon blue"></i>
                        </div>
                    </div>

                    <div class="field">
                        <label>IC / Passport No</label>
                        <div class="input-icon-wrap">
                            <input type="text" name="visitor_ic" autocomplete="off" placeholder="Example: 990101-01-1234" required>
                            <i class="fas fa-id-card input-icon slate"></i>
                        </div>
                    </div>

                    <div class="field">
                        <label>Phone Number</label>
                        <div class="input-icon-wrap">
                            <input type="text" name="visitor_contact" autocomplete="off" inputmode="numeric" maxlength="12" placeholder="Example: 012-3456789" required>
                            <i class="fas fa-phone input-icon purple"></i>
                        </div>
                    </div>

                    <div class="field">
                        <label>Vehicle Plate Number</label>
                        <div class="input-icon-wrap">
                            <input type="text" name="plate_no" class="plate-input" autocomplete="off" placeholder="Example: VIP5678" required>
                            <i class="fas fa-car input-icon green"></i>
                        </div>
                    </div>
                </div>

                <div class="date-slot-grid">
                    <div class="field">
                        <label>Visit Date</label>
                        <input type="hidden" name="visit_date" id="visit_date" value="<?= e($defaultVisitDate) ?>" required>
                        <button type="button" class="calendar-picker-field" id="visitDateBox" onclick="openCalendarPicker()">
                            <span class="calendar-picker-main" id="visit_date_display"><?= e(date('d/m/Y (D)', strtotime($defaultVisitDate))) ?></span>
                            <span class="calendar-picker-sub">Tap to choose booking date</span>
                            <i class="fas fa-calendar-days"></i>
                        </button>
                    </div>

                    <div class="field">
                        <label id="visitSlotLabel">Time Slot</label>
                        <div class="input-icon-wrap slot-select-wrap">
                            <select name="visit_slot" id="visit_slot" required>
                                <?php foreach ($timeSlots as $slotValue => $slotLabel): ?>
                                    <option value="<?= e($slotValue) ?>" <?= $slotValue === $defaultVisitSlot ? 'selected' : '' ?>>
                                        <?= e($slotLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <i class="fas fa-chevron-down input-icon slate"></i>
                        </div>
                    </div>

                    <div class="field">
                        <label>Valid Duration</label>
                        <div class="input-icon-wrap">
                            <input type="text" id="validDuration" value="Valid 2 hours" readonly>
                            <i class="fas fa-clock input-icon slate"></i>
                        </div>
                    </div>
                </div>

                <div class="field">
                    <label>Purpose of Visit</label>
                    <div class="textarea-wrap">
                        <textarea name="purpose" placeholder="Example: Family visit, friend visit, maintenance, etc." required></textarea>
                        <span class="textarea-heart">♡</span>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-paper-plane"></i>
                    Submit Visit Request
                </button>
            </form>
        </div>
    </section>
</main>

<div class="calendar-modal" id="calendarModal" aria-hidden="true">
    <div class="calendar-backdrop" onclick="closeCalendarPicker()"></div>

    <div class="calendar-panel" role="dialog" aria-modal="true" aria-label="Choose visit date">
        <div class="calendar-top">
            <div>
                <div class="calendar-month" id="calendarMonthLabel">May</div>
                <div class="calendar-year" id="calendarYearLabel">2026</div>
            </div>

            <button type="button" class="calendar-close" onclick="closeCalendarPicker()" aria-label="Close calendar">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="calendar-nav">
            <button type="button" id="calendarPrevBtn" onclick="changeCalendarMonth(-1)" aria-label="Previous month">
                <i class="fas fa-chevron-left"></i>
            </button>

            <button type="button" id="calendarTodayBtn" onclick="chooseCalendarDate(minBookingDateValue)">Today</button>

            <button type="button" id="calendarNextBtn" onclick="changeCalendarMonth(1)" aria-label="Next month">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>

        <div class="calendar-weekdays">
            <span>Sun</span>
            <span>Mon</span>
            <span>Tue</span>
            <span>Wed</span>
            <span>Thu</span>
            <span>Fri</span>
            <span>Sat</span>
        </div>

        <div class="calendar-days" id="calendarDays"></div>
        <div class="calendar-selected-text" id="calendarSelectedText">Select a date</div>
    </div>
</div>


<?php if (function_exists('smartvms_render_auto_feedback')): ?>
    <?php smartvms_render_auto_feedback('visitor_book', 'Visitor Booking', 3); ?>
<?php endif; ?>

<?php if (!empty($autoFeedbackTrigger) && is_array($autoFeedbackTrigger)): ?>
<script>
if (typeof window.smartvmsRecordFeatureUse === 'function') {
    window.smartvmsRecordFeatureUse(
        <?= json_encode($autoFeedbackTrigger['function_key'] ?? 'visitor_book') ?>,
        <?= json_encode($autoFeedbackTrigger['function_name'] ?? 'Visitor Booking') ?>
    );
}
</script>
<?php endif; ?>

<?php if ($message): ?>
<script>
Swal.fire({
    icon: 'success',
    title: 'Success',
    text: <?= json_encode($message) ?>,
    confirmButtonColor: '#2563eb'
});
</script>
<?php endif; ?>

<?php if ($error): ?>
<script>
Swal.fire({
    icon: 'error',
    title: 'Error',
    text: <?= json_encode($error) ?>,
    confirmButtonColor: '#2563eb'
});
</script>
<?php endif; ?>

<script>
const unitOptions = <?= $unitOptionsJson ?: '[]' ?>;
const visitorQuickFillData = <?= $quickFillJson ?: '{"templates":[],"plates":[]}' ?>;

const apartmentSelect = document.getElementById('apartmentSelect');
const blockSelect = document.getElementById('blockSelect');
const floorSelect = document.getElementById('floorSelect');
const unitSelect = document.getElementById('unitSelect');
const unitIdInput = document.getElementById('unit_id');
const unitPreview = document.getElementById('unitPreview');

function uniqueValues(values) {
    return [...new Set(values.filter(value => value !== null && value !== ''))];
}

function resetSelect(select, placeholder) {
    select.innerHTML = '<option value="">' + placeholder + '</option>';
}

function fillSelect(select, values, placeholder) {
    resetSelect(select, placeholder);

    values.forEach(value => {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = value;
        select.appendChild(option);
    });
}

function fillSelectPairs(select, values, placeholder) {
    resetSelect(select, placeholder);

    values.forEach(item => {
        const option = document.createElement('option');
        option.value = item.value;
        option.textContent = item.label;
        select.appendChild(option);
    });
}

function getApartments() {
    const seen = new Map();

    unitOptions.forEach(item => {
        const id = String(item.apartment_id || '');
        if (!id || seen.has(id)) {
            return;
        }
        seen.set(id, item.apartment_name || ('Apartment ' + id));
    });

    return Array.from(seen, ([value, label]) => ({ value, label }));
}

function clearUnitSelection() {
    unitIdInput.value = '';
    unitPreview.classList.remove('show');
    unitPreview.textContent = '';
}

function initUnitDropdowns() {
    fillSelectPairs(apartmentSelect, getApartments(), 'Apartment');

    blockSelect.disabled = true;
    floorSelect.disabled = true;
    unitSelect.disabled = true;
}

function handleApartmentChange() {
    const apartmentId = apartmentSelect.value;

    resetSelect(blockSelect, 'Block');
    resetSelect(floorSelect, 'Floor');
    resetSelect(unitSelect, 'Unit');

    blockSelect.disabled = true;
    floorSelect.disabled = true;
    unitSelect.disabled = true;
    clearUnitSelection();

    if (!apartmentId) {
        return;
    }

    const blocks = uniqueValues(
        unitOptions
            .filter(item => String(item.apartment_id) === String(apartmentId))
            .map(item => item.block_no)
    );

    fillSelect(blockSelect, blocks, 'Block');
    blockSelect.disabled = false;
}

function handleBlockChange() {
    const apartmentId = apartmentSelect.value;
    const block = blockSelect.value;

    resetSelect(floorSelect, 'Floor');
    resetSelect(unitSelect, 'Unit');

    floorSelect.disabled = true;
    unitSelect.disabled = true;
    clearUnitSelection();

    if (!apartmentId || !block) {
        return;
    }

    const floors = uniqueValues(
        unitOptions
            .filter(item => String(item.apartment_id) === String(apartmentId) && item.block_no === block)
            .map(item => item.floor_no)
    );

    fillSelect(floorSelect, floors, 'Floor');
    floorSelect.disabled = false;
}

function handleFloorChange() {
    const apartmentId = apartmentSelect.value;
    const block = blockSelect.value;
    const floor = floorSelect.value;

    resetSelect(unitSelect, 'Unit');
    unitSelect.disabled = true;
    clearUnitSelection();

    if (!apartmentId || !block || !floor) {
        return;
    }

    const units = unitOptions
        .filter(item =>
            String(item.apartment_id) === String(apartmentId) &&
            item.block_no === block &&
            item.floor_no === floor
        )
        .map(item => item.unit_no);

    fillSelect(unitSelect, uniqueValues(units), 'Unit');
    unitSelect.disabled = false;
}

function handleUnitChange() {
    const selected = unitOptions.find(item =>
        String(item.apartment_id) === String(apartmentSelect.value) &&
        item.block_no === blockSelect.value &&
        item.floor_no === floorSelect.value &&
        item.unit_no === unitSelect.value
    );

    if (!selected) {
        unitIdInput.value = '';
        unitPreview.classList.remove('show');
        unitPreview.textContent = '';
        return;
    }

    unitIdInput.value = selected.unit_id;

    if (Number(selected.has_resident) === 1 && Number(selected.resident_id) > 0) {
        unitPreview.innerHTML =
            '<strong>Apartment:</strong> ' + selected.apartment_name +
            '<br><strong>Selected:</strong> ' + selected.unit_text +
            '<br><strong>Resident:</strong> ' + selected.resident_name +
            ' (' + selected.resident_email + ')';
    } else {
        unitPreview.innerHTML =
            '<strong>Apartment:</strong> ' + selected.apartment_name +
            '<br><strong>Selected:</strong> ' + selected.unit_text +
            '<br><strong>Status:</strong> No active resident assigned. This unit is shown, but booking cannot be submitted yet.';
    }

    unitPreview.classList.add('show');
}

function selectResidentUnitById(unitId) {
    if (!unitId) return false;

    const selected = unitOptions.find(item => String(item.unit_id) === String(unitId));

    if (!selected) {
        return false;
    }

    apartmentSelect.value = String(selected.apartment_id || '');
    handleApartmentChange();

    blockSelect.value = selected.block_no || '';
    handleBlockChange();

    floorSelect.value = selected.floor_no || '';
    handleFloorChange();

    unitSelect.value = selected.unit_no || '';
    handleUnitChange();

    return true;
}

function setVisitTypeValue(value) {
    const normalized = value === 'Multiple In-Out' ? 'Multiple In-Out' : 'One Time';
    const target = document.querySelector('input[name="visit_type"][value="' + normalized + '"]');

    if (target) {
        target.checked = true;
        updateDurationDisplay();
    }
}

function applyQuickFill(index) {
    const templates = visitorQuickFillData && Array.isArray(visitorQuickFillData.templates)
        ? visitorQuickFillData.templates
        : [];

    const item = templates[index];

    if (!item) {
        return;
    }

    const nameField = document.querySelector('input[name="visitor_name"]');
    const icField = document.querySelector('input[name="visitor_ic"]');
    const phoneField = document.querySelector('input[name="visitor_contact"]');
    const plateField = document.querySelector('input[name="plate_no"]');
    const purposeField = document.querySelector('textarea[name="purpose"]');

    if (nameField && item.visitor_name) nameField.value = item.visitor_name;
    if (icField && item.visitor_ic) icField.value = item.visitor_ic;
    if (phoneField && item.visitor_contact) phoneField.value = formatMalaysianPhone(item.visitor_contact);
    if (plateField && item.plate_no) plateField.value = String(item.plate_no).toUpperCase().replace(/[^A-Z0-9]/g, '');
    if (purposeField && item.purpose) purposeField.value = item.purpose;

    if (item.visit_type) {
        setVisitTypeValue(item.visit_type);
    }

    if (item.unit_id) {
        selectResidentUnitById(item.unit_id);
    }

    Swal.fire({
        icon: 'success',
        title: 'Filled',
        text: 'Previous visit details have been filled. Please check the date and time before submitting.',
        timer: 1800,
        showConfirmButton: false
    });
}

function selectSavedPlate(plateNo) {
    const plateField = document.querySelector('input[name="plate_no"]');

    if (!plateField) {
        return;
    }

    plateField.value = String(plateNo || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    plateField.classList.remove('js-invalid-field');
    plateField.style.borderColor = '';
    plateField.style.boxShadow = '';

    Swal.fire({
        icon: 'success',
        title: 'Plate Selected',
        text: 'Vehicle plate ' + plateField.value + ' has been selected.',
        timer: 1200,
        showConfirmButton: false
    });
}


const minBookingDateValue = <?= json_encode($todayDate) ?>;
const maxBookingDateValue = <?= json_encode($maxVisitDate) ?>;
const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const shortDayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
let calendarViewDate = new Date((document.getElementById('visit_date')?.value || minBookingDateValue) + 'T00:00:00');

function toDateValue(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return year + '-' + month + '-' + day;
}

function formatDisplayDate(value) {
    const date = new Date(value + 'T00:00:00');
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    return day + '/' + month + '/' + year + ' (' + shortDayNames[date.getDay()] + ')';
}

function formatFullSelectedDate(value) {
    const date = new Date(value + 'T00:00:00');
    const dayName = shortDayNames[date.getDay()];
    const monthName = monthNames[date.getMonth()];
    const day = date.getDate();

    if (value === minBookingDateValue) {
        return dayName + ', ' + monthName + ' ' + day + ', Today';
    }

    return dayName + ', ' + monthName + ' ' + day;
}

function openCalendarPicker() {
    const modal = document.getElementById('calendarModal');
    const selectedValue = document.getElementById('visit_date')?.value || minBookingDateValue;

    calendarViewDate = new Date(selectedValue + 'T00:00:00');
    renderCalendar();

    if (modal) {
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }
}

function closeCalendarPicker() {
    const modal = document.getElementById('calendarModal');

    if (modal) {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }
}

function changeCalendarMonth(step) {
    calendarViewDate.setMonth(calendarViewDate.getMonth() + step);
    renderCalendar();
}

function renderCalendar() {
    const daysWrap = document.getElementById('calendarDays');
    const monthLabel = document.getElementById('calendarMonthLabel');
    const yearLabel = document.getElementById('calendarYearLabel');
    const selectedText = document.getElementById('calendarSelectedText');
    const prevBtn = document.getElementById('calendarPrevBtn');
    const nextBtn = document.getElementById('calendarNextBtn');
    const selectedValue = document.getElementById('visit_date')?.value || minBookingDateValue;

    if (!daysWrap || !monthLabel || !yearLabel) {
        return;
    }

    const year = calendarViewDate.getFullYear();
    const month = calendarViewDate.getMonth();
    const firstDay = new Date(year, month, 1);
    const startDay = firstDay.getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();

    monthLabel.textContent = monthNames[month];
    yearLabel.textContent = year;
    daysWrap.innerHTML = '';

    const currentMonthValue = year + '-' + String(month + 1).padStart(2, '0');
    const minMonthValue = minBookingDateValue.slice(0, 7);
    const maxMonthValue = maxBookingDateValue.slice(0, 7);

    if (prevBtn) prevBtn.disabled = currentMonthValue <= minMonthValue;
    if (nextBtn) nextBtn.disabled = currentMonthValue >= maxMonthValue;

    const totalCells = Math.ceil((startDay + daysInMonth) / 7) * 7;

    for (let cell = 0; cell < totalCells; cell++) {
        const dayNumber = cell - startDay + 1;
        const buttonDate = new Date(year, month, dayNumber);
        const displayNumber = buttonDate.getDate();
        const value = toDateValue(buttonDate);
        const isOtherMonth = dayNumber < 1 || dayNumber > daysInMonth;
        const isDisabled = value < minBookingDateValue || value > maxBookingDateValue || isOtherMonth;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'calendar-day';

        if (isOtherMonth) btn.classList.add('other-month');
        if (value === minBookingDateValue) btn.classList.add('today');
        if (value === selectedValue) btn.classList.add('selected');

        btn.disabled = isDisabled;
        btn.innerHTML = '<span>' + displayNumber + '</span>';

        if (!isDisabled) {
            btn.addEventListener('click', function() {
                chooseCalendarDate(value);
            });
        }

        daysWrap.appendChild(btn);
    }

    if (selectedText) selectedText.textContent = formatFullSelectedDate(selectedValue);
}

function chooseCalendarDate(value) {
    const hiddenInput = document.getElementById('visit_date');
    const displayInput = document.getElementById('visit_date_display');

    if (!hiddenInput || !displayInput) return;
    if (value < minBookingDateValue || value > maxBookingDateValue) return;

    hiddenInput.value = value;
    displayInput.textContent = formatDisplayDate(value);
    calendarViewDate = new Date(value + 'T00:00:00');

    updateSlotAvailability();
    renderCalendar();
    closeCalendarPicker();
}

function parseSlotStartTime(value) {
    if (!value || !value.includes(':')) return null;
    const parts = value.split(':');
    const hour = parseInt(parts[0], 10);
    const minute = parseInt(parts[1], 10);
    if (Number.isNaN(hour) || Number.isNaN(minute)) return null;
    return { hour, minute };
}

function formatDurationTime(date) {
    return date.toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: true
    });
}

function getSlotRangeText(slotValue, hours) {
    const slot = parseSlotStartTime(slotValue);
    if (!slot) return slotValue;

    const start = new Date();
    start.setHours(slot.hour, slot.minute, 0, 0);
    const end = new Date(start.getTime() + hours * 60 * 60 * 1000);
    const nextDayText = start.toDateString() !== end.toDateString() ? ' (next day)' : '';

    return formatDurationTime(start) + ' - ' + formatDurationTime(end) + nextDayText;
}

function getDurationRangeText(hours) {
    const slotSelect = document.getElementById('visit_slot');
    return slotSelect ? getSlotRangeText(slotSelect.value, hours) : hours + ' hours';
}

function refreshVisitSlotUI(isMultipleInOut) {
    const slotLabel = document.getElementById('visitSlotLabel');
    const slotSelect = document.getElementById('visit_slot');

    if (slotLabel) {
        slotLabel.textContent = isMultipleInOut ? 'Start Time' : 'Time Slot';
    }

    if (slotSelect) {
        Array.from(slotSelect.options).forEach(function(option) {
            option.textContent = isMultipleInOut
                ? 'Start ' + getSlotRangeText(option.value, 0).split(' - ')[0]
                : getSlotRangeText(option.value, 2);
        });
    }
}

function updateDurationDisplay() {
    const visitType = document.querySelector('input[name="visit_type"]:checked')?.value || 'One Time';
    const display = document.getElementById('validDuration');
    const isMultipleInOut = visitType === 'Multiple In-Out';

    refreshVisitSlotUI(isMultipleInOut);

    if (!display) return;

    if (isMultipleInOut) {
        display.value = 'Valid 8 hours: ' + getDurationRangeText(8);
    } else {
        display.value = 'Valid 2 hours: ' + getDurationRangeText(2);
    }
}

function updateSlotAvailability() {
    const dateInput = document.getElementById('visit_date');
    const slotSelect = document.getElementById('visit_slot');

    if (!dateInput || !slotSelect) return;

    const now = new Date();
    const today = new Date();
    today.setMinutes(today.getMinutes() - today.getTimezoneOffset());
    const todayValue = today.toISOString().slice(0, 10);

    if (dateInput.value < todayValue) {
        dateInput.value = todayValue;
    }

    let firstAvailable = '';

    Array.from(slotSelect.options).forEach(function(option) {
        const slot = parseSlotStartTime(option.value);
        if (!slot) return;

        const isPastToday = dateInput.value === todayValue &&
            (slot.hour < now.getHours() || (slot.hour === now.getHours() && slot.minute <= now.getMinutes()));

        option.disabled = isPastToday;

        if (!isPastToday && firstAvailable === '') {
            firstAvailable = option.value;
        }
    });

    if (slotSelect.selectedOptions[0] && slotSelect.selectedOptions[0].disabled && firstAvailable !== '') {
        slotSelect.value = firstAvailable;
    }

    if (firstAvailable === '' && dateInput.value === todayValue) {
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        tomorrow.setMinutes(tomorrow.getMinutes() - tomorrow.getTimezoneOffset());
        dateInput.value = tomorrow.toISOString().slice(0, 10);

        Array.from(slotSelect.options).forEach(function(option) {
            option.disabled = false;
        });
        slotSelect.value = slotSelect.options[0].value;
    }

    const displayInput = document.getElementById('visit_date_display');
    if (displayInput && dateInput.value) {
        displayInput.textContent = formatDisplayDate(dateInput.value);
        calendarViewDate = new Date(dateInput.value + 'T00:00:00');
    }

    updateDurationDisplay();
}

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeCalendarPicker();
    }
});

apartmentSelect.addEventListener('change', handleApartmentChange);
blockSelect.addEventListener('change', handleBlockChange);
floorSelect.addEventListener('change', handleFloorChange);
unitSelect.addEventListener('change', handleUnitChange);

document.querySelectorAll('input[name="visit_type"]').forEach(input => {
    input.addEventListener('change', updateDurationDisplay);
});

const visitSlotSelect = document.getElementById('visit_slot');
if (visitSlotSelect) {
    visitSlotSelect.addEventListener('change', updateDurationDisplay);
}

document.querySelectorAll('.plate-input').forEach(input => {
    input.addEventListener('input', function() {
        this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    });
});

function formatMalaysianPhone(value) {
    let digits = String(value || '').replace(/\D/g, '');

    if (digits.startsWith('60')) {
        digits = '0' + digits.slice(2);
    }

    digits = digits.slice(0, 11);

    if (digits.length <= 3) {
        return digits;
    }

    return digits.slice(0, 3) + '-' + digits.slice(3);
}

const visitorPhoneInput = document.querySelector('input[name="visitor_contact"]');
if (visitorPhoneInput) {
    visitorPhoneInput.addEventListener('input', function() {
        this.value = formatMalaysianPhone(this.value);
    });

    visitorPhoneInput.addEventListener('blur', function() {
        this.value = formatMalaysianPhone(this.value);
    });
}


function normaliseText(value) {
    return (value || '').trim();
}

function isValidIcOrPassport(value) {
    const raw = normaliseText(value).toUpperCase();
    const compact = raw.replace(/[\s-]/g, '');

    const malaysianIc = /^\d{12}$/.test(compact);
    const passport = /^[A-Z0-9]{5,20}$/.test(compact);

    return malaysianIc || passport;
}

function isValidPhoneNumber(value) {
    const phone = normaliseText(value);
    return /^01\d-\d{7,8}$/.test(phone);
}

function isValidPlateNumber(value) {
    const compact = normaliseText(value).toUpperCase().replace(/\s/g, '');
    return /^[A-Z0-9]{2,12}$/.test(compact);
}

function clearValidationMarks(form) {
    form.querySelectorAll('.js-invalid-field').forEach(function(field) {
        field.classList.remove('js-invalid-field');
        field.style.borderColor = '';
        field.style.boxShadow = '';
    });
}

function showVisitValidationError(field, title, message) {
    if (field) {
        field.classList.add('js-invalid-field');
        field.style.borderColor = '#fb7185';
        field.style.boxShadow = '0 0 0 3px rgba(251, 113, 133, 0.22)';
    }

    Swal.fire({
        icon: 'warning',
        title: title,
        text: message,
        confirmButtonColor: '#2563eb'
    }).then(function() {
        if (field) {
            field.scrollIntoView({ behavior: 'smooth', block: 'center' });
            field.focus();
        }
    });
}

function validateVisitorBookForm(form) {
    clearValidationMarks(form);

    if (!unitIdInput.value) {
        showVisitValidationError(unitSelect, 'Select Unit', 'Please select apartment, block, floor, and unit before submitting.');
        return false;
    }

    const selected = unitOptions.find(item =>
        String(item.unit_id) === String(unitIdInput.value)
    );

    if (!selected || Number(selected.has_resident) !== 1 || Number(selected.resident_id) <= 0) {
        showVisitValidationError(unitSelect, 'No Resident Assigned', 'This unit has no active resident assigned, so the visit request cannot be sent yet.');
        return false;
    }

    const nameField = form.querySelector('input[name="visitor_name"]');
    const icField = form.querySelector('input[name="visitor_ic"]');
    const phoneField = form.querySelector('input[name="visitor_contact"]');
    const plateField = form.querySelector('input[name="plate_no"]');
    const dateField = document.getElementById('visit_date');
    const slotField = document.getElementById('visit_slot');
    const purposeField = form.querySelector('textarea[name="purpose"]');

    if (!nameField || normaliseText(nameField.value) === '') {
        showVisitValidationError(nameField, 'Missing Name', 'Please enter your full name.');
        return false;
    }

    if (!icField || normaliseText(icField.value) === '') {
        showVisitValidationError(icField, 'Missing IC / Passport', 'Please enter your IC or passport number.');
        return false;
    }

    if (!isValidIcOrPassport(icField.value)) {
        showVisitValidationError(icField, 'Invalid IC / Passport', 'IC must be like 990101-01-1234 or passport must be 5 to 20 letters/numbers.');
        return false;
    }

    if (!phoneField || normaliseText(phoneField.value) === '') {
        showVisitValidationError(phoneField, 'Missing Phone Number', 'Please enter your phone number.');
        return false;
    }

    if (!isValidPhoneNumber(phoneField.value)) {
        showVisitValidationError(phoneField, 'Invalid Phone Number', 'Phone number must include a dash, for example 012-3456789 or 011-12345678.');
        return false;
    }

    if (!plateField || normaliseText(plateField.value) === '') {
        showVisitValidationError(plateField, 'Missing Plate Number', 'Please enter your vehicle plate number.');
        return false;
    }

    if (!isValidPlateNumber(plateField.value)) {
        showVisitValidationError(plateField, 'Invalid Plate Number', 'Plate number must use 2 to 12 letters/numbers only, for example VIP5678.');
        return false;
    }

    if (!dateField || normaliseText(dateField.value) === '') {
        showVisitValidationError(document.getElementById('visitDateBox'), 'Missing Visit Date', 'Please choose a visit date.');
        return false;
    }

    if (!slotField || normaliseText(slotField.value) === '') {
        showVisitValidationError(slotField, 'Missing Time Slot', 'Please choose a time slot.');
        return false;
    }

    if (!purposeField || normaliseText(purposeField.value) === '') {
        showVisitValidationError(purposeField, 'Missing Purpose', 'Please enter the purpose of visit.');
        return false;
    }

    if (normaliseText(purposeField.value).length < 3) {
        showVisitValidationError(purposeField, 'Purpose Too Short', 'Please write a clearer purpose of visit.');
        return false;
    }

    return true;
}

const visitForm = document.getElementById('visitForm');

if (visitForm) {
    visitForm.querySelectorAll('input, select, textarea, button.calendar-picker-field').forEach(function(field) {
        field.addEventListener('input', function() {
            field.classList.remove('js-invalid-field');
            field.style.borderColor = '';
            field.style.boxShadow = '';
        });

        field.addEventListener('change', function() {
            field.classList.remove('js-invalid-field');
            field.style.borderColor = '';
            field.style.boxShadow = '';
        });
    });

    visitForm.addEventListener('submit', function(event) {
        if (!validateVisitorBookForm(visitForm)) {
            event.preventDefault();
            return false;
        }
    });
}

initUnitDropdowns();
updateSlotAvailability();
</script>

</body>
</html>
