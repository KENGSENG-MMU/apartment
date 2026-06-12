<?php
require_once '../core/security.php';
require_once __DIR__ . '/../core/feedback_auto.php';
require_login(['resident']);

$pdo = db();

$receiptHelperPath = __DIR__ . '/../core/receipt_helper.php';
if (file_exists($receiptHelperPath)) {
    require_once $receiptHelperPath;
}

$residentId = (int)($_SESSION['uid'] ?? 0);
$residentEmail = $_SESSION['email'] ?? '';

$message = '';
$error = '';
$tempPasswordMessage = '';
$createdBookingId = 0;

if (isset($_SESSION['invite_flash']) && is_array($_SESSION['invite_flash'])) {
    $message = $_SESSION['invite_flash']['message'] ?? '';
    $error = $_SESSION['invite_flash']['error'] ?? '';
    $tempPasswordMessage = $_SESSION['invite_flash']['tempPasswordMessage'] ?? '';
    unset($_SESSION['invite_flash']);
}

function safe_text($value) {
    return $value !== null && $value !== '' ? $value : '-';
}

function table_exists_invite(PDO $pdo, string $table): bool {
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

function has_column_invite(PDO $pdo, string $table, string $column): bool {
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

function ensure_column_invite(PDO $pdo, string $table, string $column, string $definition): void {
    if (!table_exists_invite($pdo, $table)) {
        return;
    }

    if (!has_column_invite($pdo, $table, $column)) {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (Throwable $e) {
            // ignore if the database does not allow alter
        }
    }
}

function column_nullable_invite(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT IS_NULLABLE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
            LIMIT 1
        ");
        $stmt->execute([$table, $column]);
        $result = $stmt->fetchColumn();
        return strtoupper((string)$result) === 'YES';
    } catch (Throwable $e) {
        return false;
    }
}

function safe_count_invite(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function clean_plate_invite($plate): string {
    $plate = strtoupper(trim((string)$plate));
    return preg_replace('/[^A-Z0-9]/', '', $plate);
}

function invite_time_slots(): array {
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

function default_invite_slot(array $slots): string {
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

function generate_qr_token_invite(): string {
    return bin2hex(random_bytes(24));
}

function smartvms_invite_public_base_url(): string {
    $isHttps =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/apartment/public/resident_invite.php';
    $publicDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    return $scheme . '://' . $host . $publicDir;
}

function smartvms_invite_receipt_url(int $bookingId): string {
    return smartvms_invite_public_base_url() . '/visitor_pass.php?id=' . $bookingId;
}

function smartvms_invite_load_phpmailer(): bool {
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

    return class_exists('\\PHPMailer\\PHPMailer\\PHPMailer');
}

function smartvms_invite_send_receipt_email(
    string $toEmail,
    string $visitorName,
    string $plateNo,
    string $startTime,
    string $endTime,
    string $visitType,
    string $status,
    string $slotText,
    string $receiptUrl,
    ?string &$mailError = null,
    ?string $pdfPath = null,
    ?string $tempPassword = null
): bool {
    $mailError = null;
    $toEmail = trim($toEmail);

    if ($toEmail === '' || str_contains($toEmail, '@smartvms.local')) {
        $mailError = 'Visitor email is empty or system-generated.';
        return false;
    }

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $mailError = 'Visitor email is invalid.';
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

    if (!smartvms_invite_load_phpmailer()) {
        $mailError = 'PHPMailer is not installed.';
        return false;
    }

    $safeName = htmlspecialchars($visitorName, ENT_QUOTES, 'UTF-8');
    $safePlate = htmlspecialchars($plateNo, ENT_QUOTES, 'UTF-8');
    $safeVisitType = htmlspecialchars($visitType, ENT_QUOTES, 'UTF-8');
    $safeStatus = htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8');
    $safeSlotText = htmlspecialchars($slotText, ENT_QUOTES, 'UTF-8');
    $safeReceiptUrl = htmlspecialchars($receiptUrl, ENT_QUOTES, 'UTF-8');

    $startText = date('d M Y, g:i A', strtotime($startTime));
    $endText = date('d M Y, g:i A', strtotime($endTime));

    $loginBox = '';
    $plainLogin = '';

    if ($tempPassword) {
        $safeEmail = htmlspecialchars($toEmail, ENT_QUOTES, 'UTF-8');
        $safePassword = htmlspecialchars($tempPassword, ENT_QUOTES, 'UTF-8');
        $loginBox = "
            <div style='margin:18px 0;padding:14px;border-radius:12px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;'>
                <strong>Visitor account created</strong><br>
                Login Email: {$safeEmail}<br>
                Temporary Password: {$safePassword}<br>
                Please change your password after login.
            </div>
        ";
        $plainLogin = "\nVisitor account created\nLogin Email: {$toEmail}\nTemporary Password: {$tempPassword}\nPlease change your password after login.\n";
    }

    $html = "
        <div style='margin:0;padding:0;background:#f3f6fb;font-family:Arial,sans-serif;color:#111827;'>
            <div style='max-width:640px;margin:0 auto;padding:28px 16px;'>
                <div style='background:#ffffff;border:1px solid #e5e7eb;border-radius:18px;overflow:hidden;box-shadow:0 18px 40px rgba(15,23,42,.10);'>
                    <div style='background:linear-gradient(135deg,#111827,#2563eb);padding:24px;color:white;'>
                        <h1 style='margin:0;font-size:26px;line-height:1.2;'>SmartVMS Visitor Receipt</h1>
                        <p style='margin:8px 0 0;color:#dbeafe;font-size:14px;'>Your visitor invitation has been created successfully.</p>
                    </div>

                    <div style='padding:24px;'>
                        <p style='margin:0 0 14px;font-size:15px;'>Hello <strong>{$safeName}</strong>,</p>
                        <p style='margin:0 0 18px;font-size:15px;line-height:1.6;'>
                            A resident has invited you to visit. Your PDF receipt with QR code is attached.
                            Please show the QR pass at the guard house.
                        </p>

                        <table style='width:100%;border-collapse:collapse;margin:18px 0;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;'>
                            <tr>
                                <td style='padding:12px;background:#f8fafc;font-weight:bold;width:38%;'>Vehicle Plate</td>
                                <td style='padding:12px;'>{$safePlate}</td>
                            </tr>
                            <tr>
                                <td style='padding:12px;background:#f8fafc;font-weight:bold;'>Visit Type</td>
                                <td style='padding:12px;'>{$safeVisitType}</td>
                            </tr>
                            <tr>
                                <td style='padding:12px;background:#f8fafc;font-weight:bold;'>Status</td>
                                <td style='padding:12px;'>{$safeStatus}</td>
                            </tr>
                            <tr>
                                <td style='padding:12px;background:#f8fafc;font-weight:bold;'>Parking Slot</td>
                                <td style='padding:12px;'>{$safeSlotText}</td>
                            </tr>
                            <tr>
                                <td style='padding:12px;background:#f8fafc;font-weight:bold;'>Valid From</td>
                                <td style='padding:12px;'>{$startText}</td>
                            </tr>
                            <tr>
                                <td style='padding:12px;background:#f8fafc;font-weight:bold;'>Valid Until</td>
                                <td style='padding:12px;'>{$endText}</td>
                            </tr>
                        </table>

                        <div style='margin:20px 0;padding:14px;border-radius:12px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;font-weight:bold;text-align:center;'>
                            The PDF receipt with QR code is attached to this email.
                        </div>

                        {$loginBox}

                        <p style='margin:18px 0 0;color:#64748b;font-size:13px;line-height:1.6;'>
                            If the attachment cannot be opened, you may also view the pass here:<br>
                            <a href='{$safeReceiptUrl}' style='color:#2563eb;'>{$safeReceiptUrl}</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    ";

    $plainText = "SmartVMS Visitor Receipt\n\n" .
        "Hello {$visitorName},\n\n" .
        "A resident has invited you to visit. Your PDF receipt with QR code is attached.\n\n" .
        "Vehicle Plate: {$plateNo}\n" .
        "Visit Type: {$visitType}\n" .
        "Status: " . ucfirst($status) . "\n" .
        "Parking Slot: {$slotText}\n" .
        "Valid From: {$startText}\n" .
        "Valid Until: {$endText}\n" .
        $plainLogin .
        "\nOpen visitor pass: {$receiptUrl}\n";

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
        $mail->Subject = 'SmartVMS Visitor Pass Receipt';
        $mail->Body = $html;
        $mail->AltBody = $plainText;

        if ($pdfPath && file_exists($pdfPath)) {
            $mail->addAttachment(
                $pdfPath,
                'SmartVMS_Visitor_Receipt_' . preg_replace('/[^A-Za-z0-9_-]/', '', $plateNo) . '.pdf'
            );
        }

        $mail->send();
        return true;
    } catch (Throwable $e) {
        $mailError = $e->getMessage();
        return false;
    }
}

function ensure_favourite_contacts_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS resident_favourite_contacts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            resident_id INT NOT NULL,
            visitor_name VARCHAR(150) NOT NULL,
            visitor_email VARCHAR(150) NULL,
            visitor_contact VARCHAR(50) NULL,
            visitor_ic VARCHAR(50) NULL,
            plate_no VARCHAR(30) NOT NULL,
            purpose VARCHAR(80) NULL,
            visitor_type VARCHAR(80) NULL,
            visit_type VARCHAR(80) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL,
            INDEX idx_fav_resident (resident_id),
            INDEX idx_fav_plate (plate_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    ensure_column_invite($pdo, 'resident_favourite_contacts', 'visitor_ic', 'VARCHAR(50) NULL AFTER visitor_contact');
}

function save_favourite_contact(PDO $pdo, int $residentId, string $visitorName, string $visitorEmail, string $visitorContact, string $visitorIc, string $plateNo, string $purpose, string $visitorType, string $visitType): void {
    $stmt = $pdo->prepare("
        SELECT id
        FROM resident_favourite_contacts
        WHERE resident_id = ?
        AND plate_no = ?
        LIMIT 1
    ");
    $stmt->execute([$residentId, $plateNo]);
    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        $stmt = $pdo->prepare("
            UPDATE resident_favourite_contacts
            SET
                visitor_name = ?,
                visitor_email = ?,
                visitor_contact = ?,
                visitor_ic = ?,
                purpose = ?,
                visitor_type = ?,
                visit_type = ?,
                updated_at = NOW()
            WHERE id = ?
            AND resident_id = ?
        ");
        $stmt->execute([
            $visitorName,
            $visitorEmail,
            $visitorContact,
            $visitorIc,
            $purpose,
            $visitorType,
            $visitType,
            (int)$existingId,
            $residentId
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO resident_favourite_contacts
                (resident_id, visitor_name, visitor_email, visitor_contact, visitor_ic, plate_no, purpose, visitor_type, visit_type, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $residentId,
            $visitorName,
            $visitorEmail,
            $visitorContact,
            $visitorIc,
            $plateNo,
            $purpose,
            $visitorType,
            $visitType
        ]);
    }
}

function unit_text_invite($row): string {
    if (empty($row['unit_no'])) {
        return 'No active unit assigned';
    }

    return 'Block ' . $row['block_no'] .
        ' / Floor ' . $row['floor_no'] .
        ' / Unit ' . $row['unit_no'];
}

function allocate_invite_slot(PDO $pdo): ?array {
    if (!table_exists_invite($pdo, 'parking_slots')) {
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

function get_or_create_visitor_user(PDO $pdo, string $email, string $name, string $contact, ?string &$tempPassword): ?int {
    $email = strtolower(trim($email));
    $tempPassword = null;

    if ($email === '') {
        return null;
    }

    $hasFullName = has_column_invite($pdo, 'users', 'full_name');
    $hasContact = has_column_invite($pdo, 'users', 'contact_number');
    $hasStatus = has_column_invite($pdo, 'users', 'status');
    $hasMustChange = has_column_invite($pdo, 'users', 'must_change_password');
    $hasCreatedAt = has_column_invite($pdo, 'users', 'created_at');

    $passwordColumn = null;

    if (has_column_invite($pdo, 'users', 'password_hash')) {
        $passwordColumn = 'password_hash';
    } elseif (has_column_invite($pdo, 'users', 'password')) {
        $passwordColumn = 'password';
    }

    if (!$passwordColumn) {
        throw new Exception('Users table does not have password_hash or password column.');
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM users
        WHERE email = ?
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $existing = $stmt->fetch();

    if ($existing) {
        if (($existing['role'] ?? '') !== 'visitor') {
            throw new Exception('This email already belongs to another user role. Please use a visitor email.');
        }
        return (int)$existing['id'];
    }

    $tempPassword = 'Visitor@' . random_int(100000, 999999);

    $columns = ['email', $passwordColumn, 'role'];
    $marks = ['?', '?', '?'];
    $values = [
        $email,
        password_hash($tempPassword, PASSWORD_DEFAULT),
        'visitor'
    ];

    if ($hasFullName) {
        $columns[] = 'full_name';
        $marks[] = '?';
        $values[] = $name;
    }

    if ($hasContact) {
        $columns[] = 'contact_number';
        $marks[] = '?';
        $values[] = $contact;
    }

    if ($hasStatus) {
        $columns[] = 'status';
        $marks[] = '?';
        $values[] = 'active';
    }

    if ($hasMustChange) {
        $columns[] = 'must_change_password';
        $marks[] = '?';
        $values[] = 1;
    }

    if ($hasCreatedAt) {
        $columns[] = 'created_at';
        $marks[] = 'NOW()';
    }

    $stmt = $pdo->prepare("
        INSERT INTO users
        (" . implode(', ', $columns) . ")
        VALUES
        (" . implode(', ', $marks) . ")
    ");
    $stmt->execute($values);

    return (int)$pdo->lastInsertId();
}

$hasFullName = has_column_invite($pdo, 'users', 'full_name');
$hasContact = has_column_invite($pdo, 'users', 'contact_number');

$hasPurpose = has_column_invite($pdo, 'bookings', 'purpose');
$hasQrToken = has_column_invite($pdo, 'bookings', 'qr_token');
$hasSlotId = has_column_invite($pdo, 'bookings', 'slot_id');
$hasVisitorType = has_column_invite($pdo, 'bookings', 'visitor_type');
$hasVisitType = has_column_invite($pdo, 'bookings', 'visit_type');
$hasVisitorIc = has_column_invite($pdo, 'bookings', 'visitor_ic');
$hasVisitorContact = has_column_invite($pdo, 'bookings', 'visitor_contact');
$hasUpdatedAt = has_column_invite($pdo, 'bookings', 'updated_at');
$hasApartmentId = has_column_invite($pdo, 'bookings', 'apartment_id');
$hasVisitDate = has_column_invite($pdo, 'bookings', 'visit_date');
$hasVisitorEmail = has_column_invite($pdo, 'bookings', 'visitor_email');
$hasVisitorPhone = has_column_invite($pdo, 'bookings', 'visitor_phone');

$visitorUserNullable = column_nullable_invite($pdo, 'bookings', 'visitor_user_id');

ensure_column_invite($pdo, 'bookings', 'visitor_ic', 'VARCHAR(50) NULL AFTER visitor_name');
$hasVisitorIc = has_column_invite($pdo, 'bookings', 'visitor_ic');
ensure_column_invite($pdo, 'bookings', 'visitor_contact', 'VARCHAR(50) NULL AFTER visitor_ic');
$hasVisitorContact = has_column_invite($pdo, 'bookings', 'visitor_contact');

ensure_favourite_contacts_table($pdo);

$residentNameSql = $hasFullName ? "u.full_name AS resident_name" : "NULL AS resident_name";
$residentContactSql = $hasContact ? "u.contact_number AS resident_contact" : "NULL AS resident_contact";
$hasProfilePhoto = has_column_invite($pdo, 'users', 'profile_photo');
$residentPhotoSql = $hasProfilePhoto ? "u.profile_photo AS profile_photo" : "NULL AS profile_photo";

$stmt = $pdo->prepare("
    SELECT
        u.id,
        u.email,
        {$residentNameSql},
        {$residentContactSql},
        {$residentPhotoSql},
        ru.unit_id,
        un.apartment_id,
        a.apartment_name,
        un.block_no,
        un.floor_no,
        un.unit_no
    FROM users u
    LEFT JOIN resident_units ru
        ON ru.resident_id = u.id
        AND ru.status = 'active'
    LEFT JOIN units un ON un.id = ru.unit_id
    LEFT JOIN apartments a ON a.id = un.apartment_id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->execute([$residentId]);
$resident = $stmt->fetch();

$residentName = $resident['resident_name'] ?: explode('@', $residentEmail)[0];
$unitText = unit_text_invite($resident);

$profilePhoto = trim((string)($resident['profile_photo'] ?? ''));
$profilePhotoUrl = '';

if ($hasProfilePhoto && $profilePhoto !== '') {
    $profilePhoto = str_replace('\\\\', '/', $profilePhoto);
    $profilePhoto = str_replace('\\', '/', $profilePhoto);

    if (preg_match('/^https?:\/\//i', $profilePhoto)) {
        $profilePhotoUrl = $profilePhoto;
    } else {
        $cleanPhoto = ltrim($profilePhoto, '/');

        $photoCandidates = [
            [__DIR__ . '/' . $cleanPhoto, $cleanPhoto],
            [__DIR__ . '/../' . $cleanPhoto, '../' . $cleanPhoto],
            [__DIR__ . '/uploads/profiles/' . basename($cleanPhoto), 'uploads/profiles/' . basename($cleanPhoto)]
        ];

        foreach ($photoCandidates as $candidate) {
            if (is_file($candidate[0])) {
                $profilePhotoUrl = $candidate[1];
                break;
            }
        }
    }
}

$residentInitial = strtoupper(substr(trim($residentName), 0, 1));
if ($residentInitial === '') {
    $residentInitial = 'R';
}

$notificationCount = 0;
if (table_exists_invite($pdo, 'notifications')) {
    $notificationCount = safe_count_invite(
        $pdo,
        "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND COALESCE(is_read, 0) = 0",
        [$residentId]
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        try {
            $formAction = trim($_POST['form_action'] ?? 'invite');

            if ($formAction === 'delete_favourite') {
                $favId = (int)($_POST['favourite_id'] ?? 0);

                if ($favId <= 0) {
                    throw new Exception('Invalid favourite contact.');
                }

                $stmt = $pdo->prepare("
                    DELETE FROM resident_favourite_contacts
                    WHERE id = ?
                    AND resident_id = ?
                ");
                $stmt->execute([
                    $favId,
                    $residentId
                ]);

                $message = 'Favourite contact deleted successfully.';
            } else {
                $visitorType = trim($_POST['visitor_type'] ?? 'Single Visitor');
                $purpose = trim($_POST['purpose'] ?? 'Family / Friend');
                $visitType = trim($_POST['visit_type'] ?? 'One Time Visit');

                $isDelivery = ($purpose === 'Delivery');
                $isOthers = ($purpose === 'Others');

                /*
                 * Multiple Visitor is mainly for Family / Friend.
                 * Delivery and Others remain as one QR pass only because they do not need IC / phone / plate details.
                 */
                if ($isDelivery || $isOthers) {
                    $visitorType = 'Single Visitor';
                    $visitType = 'One Time Visit';
                }

                $rawVisitors = [];

                if (isset($_POST['visitors']) && is_array($_POST['visitors'])) {
                    $rawVisitors = $_POST['visitors'];
                } else {
                    // Backward support for the old single visitor input names.
                    $rawVisitors[] = [
                        'visitor_name' => $_POST['visitor_name'] ?? '',
                        'visitor_email' => $_POST['visitor_email'] ?? '',
                        'visitor_contact' => $_POST['visitor_contact'] ?? '',
                        'visitor_ic' => $_POST['visitor_ic'] ?? '',
                        'plate_no' => $_POST['plate_no'] ?? ''
                    ];
                }

                if ($visitorType !== 'Multiple Visitor') {
                    $rawVisitors = array_slice($rawVisitors, 0, 1);
                }

                $visitorRows = [];

                foreach ($rawVisitors as $index => $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $visitorName = trim($row['visitor_name'] ?? '');
                    $visitorEmail = strtolower(trim($row['visitor_email'] ?? ''));
                    $visitorContact = trim($row['visitor_contact'] ?? '');
                    $visitorIc = strtoupper(trim($row['visitor_ic'] ?? ''));
                    $plateNo = clean_plate_invite($row['plate_no'] ?? '');

                    $isCompletelyEmpty = $visitorName === ''
                        && $visitorEmail === ''
                        && $visitorContact === ''
                        && $visitorIc === ''
                        && $plateNo === '';

                    if ($isCompletelyEmpty && $index > 0) {
                        continue;
                    }

                    if ($isDelivery || $isOthers) {
                        $visitorIc = $visitorIc !== '' ? $visitorIc : '-';
                        $visitorContact = $visitorContact !== '' ? $visitorContact : '-';

                        if ($visitorName === '') {
                            throw new Exception($isDelivery ? 'Please enter delivery company name.' : 'Please enter reason / service name.');
                        }

                        if ($plateNo === '') {
                            $platePrefix = $isDelivery ? 'DEL' : 'OTH';
                            $plateNo = $platePrefix . date('ymdHis') . random_int(10, 99);
                        }
                    }

                    $visitorRows[] = [
                        'visitor_name' => $visitorName,
                        'visitor_email' => $visitorEmail,
                        'visitor_contact' => $visitorContact,
                        'visitor_ic' => $visitorIc,
                        'plate_no' => $plateNo
                    ];
                }

                if (empty($visitorRows)) {
                    throw new Exception('Please enter at least one visitor.');
                }

                if (count($visitorRows) > 10) {
                    throw new Exception('You can invite maximum 10 visitors at one time.');
                }

                /*
                 * Resident can pre-book a visit by choosing a date and an arrival time slot.
                 * One Time / Delivery / Others use the selected 2-hour slot.
                 * Multiple In-Out is valid for 8 hours starting from the selected slot start time.
                 */
                $availableSlots = invite_time_slots();
                $visitDate = trim($_POST['visit_date'] ?? '');
                $visitSlot = trim($_POST['visit_slot'] ?? '');

                if ($visitDate === '') {
                    throw new Exception('Please select visit date.');
                }

                $dateObj = DateTime::createFromFormat('Y-m-d', $visitDate);
                if (!$dateObj || $dateObj->format('Y-m-d') !== $visitDate) {
                    throw new Exception('Invalid visit date. Please select again.');
                }

                if (!isset($availableSlots[$visitSlot])) {
                    throw new Exception('Please select a valid visit time.');
                }

                $startDateTime = $visitDate . ' ' . $visitSlot . ':00';
                $validHours = ($visitType === 'Multiple In-Out' && !$isDelivery && !$isOthers) ? 8 : 2;
                $endDateTime = date('Y-m-d H:i:s', strtotime($startDateTime . ' +' . $validHours . ' hours'));

                if (strtotime($startDateTime) < time()) {
                    throw new Exception('Please select a future visit date and time slot.');
                }

                $submittedPlates = [];

                foreach ($visitorRows as $index => $visitor) {
                    $visitorNumber = $index + 1;
                    $visitorName = $visitor['visitor_name'];
                    $visitorEmail = $visitor['visitor_email'];
                    $visitorContact = $visitor['visitor_contact'];
                    $visitorIc = $visitor['visitor_ic'];
                    $plateNo = $visitor['plate_no'];

                    if ($visitorName === '') {
                        throw new Exception($isDelivery ? 'Please enter delivery company name.' : ($isOthers ? 'Please enter reason / service name.' : 'Please enter Visitor ' . $visitorNumber . ' name.'));
                    }

                    if (!$isDelivery && !$isOthers && $visitorEmail === '') {
                        throw new Exception('Please enter Visitor ' . $visitorNumber . ' email.');
                    }

                    if (!$isDelivery && !$isOthers && $visitorIc === '') {
                        throw new Exception('Please enter Visitor ' . $visitorNumber . ' IC / Passport number.');
                    }

                    if (!$isDelivery && !$isOthers && $visitorContact === '') {
                        throw new Exception('Please enter Visitor ' . $visitorNumber . ' phone number.');
                    }

                    if (!$isDelivery && !$isOthers && $plateNo === '') {
                        throw new Exception('Please enter Visitor ' . $visitorNumber . ' vehicle plate number.');
                    }

                    if (!$isDelivery && !$isOthers && strlen($plateNo) < 3) {
                        throw new Exception('Visitor ' . $visitorNumber . ' vehicle plate number is too short.');
                    }

                    if ($visitorEmail === '' && !$visitorUserNullable) {
                        $visitorRows[$index]['visitor_email'] = 'guest_' . strtolower($plateNo) . '_' . time() . '_' . $visitorNumber . '@smartvms.local';
                        $visitorEmail = $visitorRows[$index]['visitor_email'];
                    }

                    if ($visitorEmail !== '' && !filter_var($visitorEmail, FILTER_VALIDATE_EMAIL)) {
                        throw new Exception('Please enter a valid email for Visitor ' . $visitorNumber . '.');
                    }

                    if (!$isDelivery && !$isOthers) {
                        if (isset($submittedPlates[$plateNo])) {
                            throw new Exception('Duplicate plate number found: ' . $plateNo . '. Please check the visitor list.');
                        }
                        $submittedPlates[$plateNo] = true;
                    }

                    if (table_exists_invite($pdo, 'blacklisted_plates')) {
                        $stmt = $pdo->prepare("
                            SELECT id
                            FROM blacklisted_plates
                            WHERE plate_no = ?
                            AND status = 'active'
                            LIMIT 1
                        ");
                        $stmt->execute([$plateNo]);

                        if ($stmt->fetch()) {
                            throw new Exception('Visitor ' . $visitorNumber . ' vehicle plate is blacklisted. Please contact management.');
                        }
                    }

                    if (table_exists_invite($pdo, 'resident_vehicles')) {
                        $residentVehicleCount = safe_count_invite($pdo, "
                            SELECT COUNT(*)
                            FROM resident_vehicles
                            WHERE plate_no = ?
                            AND status = 'active'
                        ", [$plateNo]);

                        if ($residentVehicleCount > 0) {
                            throw new Exception('Visitor ' . $visitorNumber . ' plate is already registered as resident vehicle. Resident vehicle does not need visitor invitation.');
                        }
                    }

                    $conflictCount = safe_count_invite($pdo, "
                        SELECT COUNT(*)
                        FROM bookings
                        WHERE plate_no = ?
                        AND status IN ('pending', 'approved', 'allocated', 'waiting', 'checked_in')
                        AND NOT (end_time <= ? OR start_time >= ?)
                    ", [
                        $plateNo,
                        $startDateTime,
                        $endDateTime
                    ]);

                    if ($conflictCount > 0) {
                        throw new Exception('Visitor ' . $visitorNumber . ' plate already has an active booking during the selected time.');
                    }
                }

                $pdo->beginTransaction();

                $createdBookingIds = [];
                $waitingCount = 0;
                $tempPasswordMessages = [];
                $receiptEmailJobs = [];
                $receiptEmailSentCount = 0;
                $receiptEmailFailCount = 0;

                foreach ($visitorRows as $index => $visitor) {
                    $visitorNumber = $index + 1;
                    $visitorName = $visitor['visitor_name'];
                    $visitorEmail = $visitor['visitor_email'];
                    $visitorContact = $visitor['visitor_contact'];
                    $visitorIc = $visitor['visitor_ic'];
                    $plateNo = $visitor['plate_no'];

                    $tempPassword = null;
                    $visitorUserId = get_or_create_visitor_user(
                        $pdo,
                        $visitorEmail,
                        $visitorName,
                        $visitorContact,
                        $tempPassword
                    );

                    if (!$visitorUserNullable && !$visitorUserId) {
                        throw new Exception('Visitor account is required. Please enter visitor email for Visitor ' . $visitorNumber . '.');
                    }

                    $slot = null;
                    $bookingStatus = 'approved';

                    if ($hasSlotId) {
                        $slot = allocate_invite_slot($pdo);
                        $bookingStatus = $slot ? 'allocated' : 'waiting';
                    }

                    if ($bookingStatus === 'waiting') {
                        $waitingCount++;
                    }

                    $generatedQrToken = null;

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

                    $marks = ['?', '?', '?', '?', '?', '?', '?', 'NOW()'];

                    $values = [
                        $visitorUserId,
                        $residentId,
                        $visitorName,
                        $plateNo,
                        $startDateTime,
                        $endDateTime,
                        $bookingStatus
                    ];

                    if ($hasApartmentId) {
                        $columns[] = 'apartment_id';
                        $marks[] = '?';
                        $values[] = !empty($resident['apartment_id']) ? (int)$resident['apartment_id'] : null;
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

                    if ($hasPurpose) {
                        $columns[] = 'purpose';
                        $marks[] = '?';
                        $values[] = $purpose;
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

                    if ($hasQrToken) {
                        $generatedQrToken = generate_qr_token_invite();
                        $columns[] = 'qr_token';
                        $marks[] = '?';
                        $values[] = $generatedQrToken;
                    }

                    if ($hasSlotId) {
                        $columns[] = 'slot_id';
                        $marks[] = '?';
                        $values[] = $slot ? (int)$slot['id'] : null;
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

                    if ($hasUpdatedAt) {
                        $columns[] = 'updated_at';
                        $marks[] = 'NOW()';
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO bookings
                        (" . implode(', ', $columns) . ")
                        VALUES
                        (" . implode(', ', $marks) . ")
                    ");
                    $stmt->execute($values);

                    $newBookingId = (int)$pdo->lastInsertId();
                    $createdBookingIds[] = $newBookingId;

                    $slotTextForReceipt = 'Not assigned';
                    if ($bookingStatus === 'allocated' && $slot) {
                        $slotTextForReceipt = trim(($slot['block_name'] ?? '') . ' ' . ($slot['slot_no'] ?? ''));
                    } elseif ($bookingStatus === 'waiting') {
                        $slotTextForReceipt = 'Waiting for visitor parking slot';
                    }

                    $receiptEmailJobs[] = [
                        'booking_id' => $newBookingId,
                        'visitor_name' => $visitorName,
                        'visitor_email' => $visitorEmail,
                        'visitor_contact' => $visitorContact,
                        'visitor_ic' => $visitorIc,
                        'plate_no' => $plateNo,
                        'purpose' => $purpose,
                        'visit_type' => $visitType,
                        'start_time' => $startDateTime,
                        'end_time' => $endDateTime,
                        'status' => $bookingStatus,
                        'parking_slot' => $slotTextForReceipt,
                        'qr_token' => $generatedQrToken,
                        'temp_password' => $tempPassword
                    ];

                    if ($visitorUserId && function_exists('create_notification')) {
                        $notifyMsg = 'You have been invited by resident ' . $residentName . '.';

                        if ($slot) {
                            $notifyMsg .= ' Visitor parking slot: ' . $slot['block_name'] . ' ' . $slot['slot_no'] . '.';
                        }

                        if ($bookingStatus === 'waiting') {
                            $notifyMsg .= ' Visitor parking is currently full, so your visit is waiting for a slot.';
                        }

                        create_notification(
                            $pdo,
                            $visitorUserId,
                            'Visit Invitation',
                            $notifyMsg,
                            'booking'
                        );
                    }

                    if (function_exists('log_audit')) {
                        log_audit(
                            'RESIDENT_INVITED_VISITOR',
                            'Resident invited visitor ' . $visitorName . ', plate ' . $plateNo . ', booking #' . $newBookingId
                        );
                    }

                    if (!$isDelivery && !$isOthers && isset($_POST['save_favourite']) && $_POST['save_favourite'] === '1') {
                        save_favourite_contact(
                            $pdo,
                            $residentId,
                            $visitorName,
                            $visitorEmail,
                            $visitorContact,
                            $visitorIc,
                            $plateNo,
                            $purpose,
                            $visitorType,
                            $visitType
                        );
                    }

                    if ($tempPassword && !str_contains($visitorEmail, '@smartvms.local')) {
                        $tempPasswordMessages[] = 'Visitor ' . $visitorNumber . ' account created. Email: ' . $visitorEmail . ' | Temporary password: ' . $tempPassword;
                    }
                }

                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }

                foreach ($receiptEmailJobs as $job) {
                    $emailTarget = trim((string)($job['visitor_email'] ?? ''));

                    if (
                        $emailTarget === '' ||
                        str_contains($emailTarget, '@smartvms.local') ||
                        !filter_var($emailTarget, FILTER_VALIDATE_EMAIL)
                    ) {
                        continue;
                    }

                    $mailError = null;
                    $receiptPdfPath = null;
                    $receiptGenerateError = null;
                    $receiptUrl = smartvms_invite_receipt_url((int)$job['booking_id']);

                    if (function_exists('svms_generate_receipt_pdf')) {
                        try {
                            $receiptFiles = svms_generate_receipt_pdf([
                                'booking_id' => (int)$job['booking_id'],
                                'visitor_name' => (string)$job['visitor_name'],
                                'visitor_email' => (string)$job['visitor_email'],
                                'visitor_phone' => (string)$job['visitor_contact'],
                                'visitor_ic' => (string)$job['visitor_ic'],
                                'plate_no' => (string)$job['plate_no'],
                                'purpose' => (string)$job['purpose'],
                                'visit_type' => (string)$job['visit_type'],
                                'arrival' => (string)$job['start_time'],
                                'valid_until' => (string)$job['end_time'],
                                'resident_unit' => $unitText,
                                'parking_slot' => (string)$job['parking_slot'],
                                'approved_at' => date('Y-m-d H:i:s'),
                                'qr_token' => (string)($job['qr_token'] ?? '')
                            ]);

                            $receiptPdfPath = $receiptFiles['pdf_path'] ?? null;
                        } catch (Throwable $e) {
                            $receiptGenerateError = $e->getMessage();
                        }
                    } else {
                        $receiptGenerateError = 'receipt_helper.php is not loaded.';
                    }

                    $emailSent = smartvms_invite_send_receipt_email(
                        $emailTarget,
                        (string)$job['visitor_name'],
                        (string)$job['plate_no'],
                        (string)$job['start_time'],
                        (string)$job['end_time'],
                        (string)$job['visit_type'],
                        (string)$job['status'],
                        (string)$job['parking_slot'],
                        $receiptUrl,
                        $mailError,
                        $receiptPdfPath,
                        $job['temp_password'] ?: null
                    );

                    if ($emailSent) {
                        $receiptEmailSentCount++;
                    } else {
                        $receiptEmailFailCount++;

                        if ($receiptGenerateError && !$mailError) {
                            $mailError = 'PDF error: ' . $receiptGenerateError;
                        }

                        if (function_exists('log_audit')) {
                            log_audit(
                                'INVITE_RECEIPT_EMAIL_FAILED',
                                'Receipt email failed for booking #' . (int)$job['booking_id'] . ': ' . (string)$mailError
                            );
                        }
                    }
                }

                if (count($createdBookingIds) === 1) {
                    $createdBookingId = $createdBookingIds[0];

                    $message = $waitingCount > 0
                        ? 'Visitor invited successfully, but visitor parking is full. Booking is now waiting for a slot.'
                        : 'Visitor invited successfully. Visitor pass is ready.';
                } else {
                    $createdBookingId = 0;
                    $message = count($createdBookingIds) . ' visitors invited successfully. Visitor passes are ready.';

                    if ($waitingCount > 0) {
                        $message .= ' ' . $waitingCount . ' visitor booking(s) are waiting because visitor parking is full.';
                    }
                }

                if (!$isDelivery && !$isOthers && isset($_POST['save_favourite']) && $_POST['save_favourite'] === '1') {
                    $message .= ' Favourite contact saved.';
                }

                if ($receiptEmailSentCount > 0) {
                    $message .= ' Receipt email sent to ' . $receiptEmailSentCount . ' visitor' . ($receiptEmailSentCount > 1 ? 's' : '') . '.';
                }

                if ($receiptEmailFailCount > 0) {
                    $message .= ' ' . $receiptEmailFailCount . ' receipt email(s) could not be sent. Please check SMTP settings if needed.';
                }

                if (!empty($tempPasswordMessages)) {
                    $tempPasswordMessage = implode(' | ', $tempPasswordMessages);
                }
            }

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getMessage() !== '__STOP_AFTER_DELETE__') {
                $error = $e->getMessage();
            }
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /*
     * Post/Redirect/Get:
     * Prevent browser refresh from submitting the same invite again.
     * Success goes directly to QR pass receipt.
     * Error/delete favourite returns to this page as a normal GET request.
     */
    if ($createdBookingId > 0) {
        header('Location: visitor_pass.php?id=' . (int)$createdBookingId);
        exit;
    }

    $_SESSION['invite_flash'] = [
        'message' => $message,
        'error' => $error,
        'tempPasswordMessage' => $tempPasswordMessage
    ];

    header('Location: resident_invite.php');
    exit;
}

$todayDate = date('Y-m-d');
$timeSlots = invite_time_slots();
$defaultVisitDate = $todayDate;
$defaultVisitSlot = default_invite_slot($timeSlots);

if ($defaultVisitSlot === array_key_first($timeSlots) && strtotime($todayDate . ' ' . $defaultVisitSlot . ':00') < time()) {
    $defaultVisitDate = date('Y-m-d', strtotime('+1 day'));
}

/*
 * Date dropdown options.
 * Native <input type="date"> can be hard to open on some browsers / styles,
 * so the page uses a normal select dropdown for easier selection.
 */
$visitDateOptions = [];
for ($i = 0; $i <= 30; $i++) {
    $dateValue = date('Y-m-d', strtotime('+' . $i . ' day'));
    $dateLabel = date('d/m/Y (D)', strtotime($dateValue));

    if ($i === 0) {
        $dateLabel = 'Today - ' . $dateLabel;
    } elseif ($i === 1) {
        $dateLabel = 'Tomorrow - ' . $dateLabel;
    }

    $visitDateOptions[$dateValue] = $dateLabel;
}

$stmt = $pdo->prepare("
    SELECT
        id,
        visitor_name,
        visitor_email,
        visitor_contact,
        visitor_ic,
        plate_no,
        purpose,
        visitor_type,
        visit_type
    FROM resident_favourite_contacts
    WHERE resident_id = ?
    ORDER BY updated_at DESC, created_at DESC
    LIMIT 50
");
$stmt->execute([$residentId]);
$favouriteContacts = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invite Visitors - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
:root {
    --surface: rgba(255, 255, 255, 0.94);
    --surface-solid: #ffffff;
    --line: #e2e8f0;
    --line-soft: #edf2f7;
    --navy: #0f172a;
    --text: #334155;
    --muted: #64748b;
    --blue: #2563eb;
    --blue-dark: #1e40af;
    --blue-soft: #eff6ff;
    --red: #ef4444;
    --red-soft: #fff1f2;
    --green: #16a34a;
    --green-soft: #dcfce7;
    --yellow: #f59e0b;
    --yellow-soft: #fffbeb;
    --shadow-sm: 0 8px 20px rgba(15, 23, 42, 0.045);
    --shadow-md: 0 18px 45px rgba(15, 23, 42, 0.09);
}

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
    font-family: 'Plus Jakarta Sans', sans-serif;
}

html {
    min-height: 100%;
}

body {
    min-height: 100vh;
    color: var(--text);
    background:
        radial-gradient(circle at 10% 18%, rgba(147, 197, 253, 0.13) 0 72px, transparent 74px),
        radial-gradient(circle at 90% 30%, rgba(191, 219, 254, 0.18) 0 95px, transparent 97px),
        radial-gradient(circle at 16% 82%, rgba(186, 230, 253, 0.14) 0 62px, transparent 64px),
        radial-gradient(circle at 86% 87%, rgba(219, 234, 254, 0.24) 0 86px, transparent 88px),
        linear-gradient(180deg, #ffffff 0%, #f3f6fb 100%);
    overflow-x: hidden;
}

body::before,
body::after {
    content: none !important;
}

a {
    color: inherit;
    text-decoration: none;
}

/* Unified navbar */
.navbar {
    height: 76px;
    padding: 0 5%;
    background: rgba(255, 255, 255, 0.92);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border-bottom: 1px solid var(--line);
    position: sticky;
    top: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: none;
}

.brand {
    font-size: 1.55rem;
    font-weight: 900;
    color: var(--navy);
    letter-spacing: -0.8px;
    white-space: nowrap;
}

.brand span {
    color: var(--blue);
}

.nav-links {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    flex-wrap: nowrap;
}

.nav-btn {
    min-height: 0;
    padding: 10px 14px;
    border-radius: 999px;
    color: #344054;
    background: transparent;
    border: 0;
    text-decoration: none;
    font-size: 0.82rem;
    font-weight: 800;
    line-height: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: 0.22s ease;
    white-space: nowrap;
}

.nav-btn:hover,
.nav-btn.active {
    color: var(--blue);
    background: var(--blue-soft);
    transform: none;
}

.nav-btn.active {
    border: 1px solid #bfdbfe;
}

.nav-btn.logout {
    color: #dc2626;
    background: var(--red-soft);
}

.nav-btn.logout:hover {
    color: #b91c1c;
    background: #ffe4e6;
}

.notification-nav-btn {
    position: relative;
}

.nav-notification-badge {
    position: absolute;
    top: -5px;
    right: -4px;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 999px;
    background: #dc2626;
    color: #ffffff;
    font-size: 0.65rem;
    font-weight: 900;
    line-height: 20px;
    text-align: center;
    border: 2px solid #ffffff;
    box-shadow: 0 8px 18px rgba(220, 38, 38, 0.25);
}

/* Page */
.page-wrap {
    width: min(1120px, calc(100% - 48px));
    margin: 0 auto;
    padding: 38px 0 68px;
}

.alert {
    border-radius: 16px;
    padding: 14px 16px;
    margin-bottom: 18px;
    font-weight: 800;
    line-height: 1.5;
    border: 1px solid transparent;
}

.alert.success {
    color: #166534;
    background: #dcfce7;
    border-color: #bbf7d0;
}

.alert.error {
    color: #991b1b;
    background: #fee2e2;
    border-color: #fecaca;
}

.alert.info {
    color: var(--blue-dark);
    background: var(--blue-soft);
    border-color: #bfdbfe;
}

.invite-shell {
    background: transparent;
    border: 0;
    box-shadow: none;
    border-radius: 0;
    overflow: visible;
}

.invite-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 24px;
    margin-bottom: 24px;
    padding: 6px 4px 22px;
    border-bottom: 1px solid var(--line);
    background: transparent;
    box-shadow: none;
    border-radius: 0;
    color: var(--navy);
}

.invite-head-left {
    display: flex;
    align-items: center;
    gap: 16px;
}

.back-btn {
    width: 42px;
    height: 42px;
    border-radius: 14px;
    background: var(--blue-soft);
    color: var(--blue);
    border: 1px solid #dbeafe;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: 0.22s ease;
}

.back-btn:hover {
    background: var(--blue);
    color: #ffffff;
}

.head-title {
    color: var(--navy);
    font-size: 2.5rem;
    font-weight: 900;
    letter-spacing: -1.5px;
    line-height: 1.08;
}

.unit-chip {
    min-width: 250px;
    padding: 14px 20px;
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.82);
    border: 1px solid var(--line);
    box-shadow: var(--shadow-sm);
    color: var(--navy);
    font-size: 0.88rem;
    font-weight: 900;
    line-height: 1.5;
    text-align: left;
}

/* Invite form layout */
.invite-body {
    padding: 0;
    background: transparent;
}

.form-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 300px;
    gap: 24px;
    align-items: start;
}

.left-col,
.right-col {
    min-width: 0;
}

.card,
.visitor-card,
.favourite-panel,
.bottom-action-row {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 22px;
    box-shadow: var(--shadow-sm);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    color: var(--text);
}

.card {
    padding: 22px;
    margin-bottom: 18px;
}

.card.slim {
    padding: 18px;
}

.section-label,
.visitor-card-title {
    color: var(--navy);
    font-size: 1rem;
    font-weight: 900;
    margin-bottom: 14px;
}

.visitor-card-subtitle {
    color: var(--muted);
    font-size: 0.82rem;
    font-weight: 650;
    line-height: 1.45;
}

.segment {
    display: grid;
    gap: 8px;
    padding: 6px;
    border-radius: 18px;
    background: #f8fafc;
    border: 1px solid var(--line);
}

.segment.two {
    grid-template-columns: repeat(2, 1fr);
}

.segment.three {
    grid-template-columns: repeat(3, 1fr);
}

.segment button {
    min-height: 44px;
    border: 0;
    border-radius: 14px;
    background: transparent;
    color: var(--muted);
    font-size: 0.86rem;
    font-weight: 900;
    cursor: pointer;
    transition: 0.22s ease;
}

.segment button:hover {
    background: #ffffff;
    color: var(--blue);
}

.segment button.active {
    background: var(--blue);
    color: #ffffff;
    box-shadow: 0 10px 20px rgba(37, 99, 235, 0.16);
}

.segment button.locked-option,
.segment button[aria-disabled="true"] {
    opacity: 0.45;
    cursor: not-allowed;
}

.visit-lock-note,
.purpose-help {
    display: none;
    align-items: flex-start;
    gap: 10px;
    margin-top: 12px;
    padding: 12px 14px;
    border-radius: 14px;
    color: #92400e;
    background: #fffbeb;
    border: 1px solid #fde68a;
    font-size: 0.82rem;
    font-weight: 750;
    line-height: 1.45;
}

.visit-lock-note.show,
.purpose-help.show {
    display: flex;
}

.purpose-help.show {
    color: var(--blue-dark);
    background: var(--blue-soft);
    border-color: #bfdbfe;
}

/* Fields */
.field-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}

.top-time-grid {
    grid-template-columns: repeat(3, 1fr);
}

.field-box {
    min-height: 76px;
    border-radius: 16px;
    border: 1px solid var(--line);
    background: #ffffff;
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 6px;
    position: relative;
}

.field-box label {
    color: var(--muted);
    font-size: 0.72rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.45px;
}

.field-box input,
.field-box select {
    width: 100%;
    min-height: 32px;
    border: 0;
    outline: 0;
    background: transparent;
    color: var(--navy);
    font-size: 0.94rem;
    font-weight: 850;
    padding: 0;
}

.field-box input::placeholder {
    color: #94a3b8;
}

.field-box:focus-within {
    border-color: #bfdbfe;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.08);
}

.field-hint {
    color: var(--muted);
    font-size: 0.76rem;
    font-weight: 650;
}

.calendar-field {
    cursor: pointer;
}

.calendar-field-icon,
.select-box::after {
    position: absolute;
    right: 14px;
    bottom: 18px;
    color: var(--blue);
    pointer-events: none;
}

.select-box select {
    appearance: none;
    cursor: pointer;
}

.select-box::after {
    content: "107";
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
}

.hidden-purpose-field {
    display: none !important;
}

/* Visitor card */
.visitor-card-box {
    padding: 20px;
}

.visitor-card {
    padding: 20px;
    margin-top: 10px;
}

.visitor-card + .visitor-card {
    margin-top: 16px;
}

.visitor-card-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 16px;
}

.remove-visitor-btn {
    border: 1px solid #fecaca;
    background: var(--red-soft);
    color: #dc2626;
    border-radius: 999px;
    min-height: 36px;
    padding: 0 14px;
    font-size: 0.78rem;
    font-weight: 900;
    cursor: pointer;
}

/* Right side panel */
.right-col {
    position: sticky;
    top: 98px;
}

.bottom-action-row {
    padding: 20px;
}

.favourite-action-area {
    display: grid;
    gap: 12px;
    margin-bottom: 18px;
}

.star-save-btn,
.favorite-btn,
.add-visitor-btn,
.invite-btn,
.delete-fav-btn {
    border: 0;
    cursor: pointer;
    min-height: 44px;
    border-radius: 999px;
    font-size: 0.84rem;
    font-weight: 900;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    transition: 0.22s ease;
}

.star-save-btn,
.favorite-btn,
.add-visitor-btn {
    background: #ffffff;
    color: var(--blue);
    border: 1px solid #bfdbfe;
}

.star-save-btn {
    color: #b45309;
    border-color: #fde68a;
    background: #fffbeb;
}

.star-save-btn.active {
    color: #ffffff;
    background: var(--yellow);
    border-color: var(--yellow);
}

.favorite-btn:hover,
.add-visitor-btn:hover,
.star-save-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
}

.add-visitor-btn {
    display: none;
}

.add-visitor-btn.show {
    display: inline-flex;
}

.button-group {
    display: grid;
}

.invite-btn {
    width: 100%;
    min-height: 50px;
    color: #ffffff;
    background: var(--blue);
    box-shadow: 0 12px 22px rgba(37, 99, 235, 0.18);
}

.invite-btn:hover {
    background: var(--blue-dark);
    transform: translateY(-2px);
}

.favourite-panel {
    display: none;
    margin-top: 16px;
    padding: 18px;
    max-height: 360px;
    overflow: auto;
}

.favourite-panel.show {
    display: block;
}

.aside-text {
    color: var(--muted);
    font-size: 0.86rem;
    font-weight: 650;
    line-height: 1.5;
}

.favourite-list {
    display: grid;
    gap: 12px;
}

.favourite-row {
    display: grid;
    gap: 8px;
}

.favourite-item {
    width: 100%;
    text-align: left;
    border: 1px solid var(--line);
    background: #ffffff;
    border-radius: 16px;
    padding: 14px;
    cursor: pointer;
}

.fav-name {
    color: var(--navy);
    font-weight: 900;
    margin-bottom: 4px;
}

.fav-meta {
    color: var(--muted);
    font-size: 0.78rem;
    font-weight: 650;
    line-height: 1.45;
}

.delete-fav-btn {
    width: 100%;
    color: #dc2626;
    background: var(--red-soft);
    border: 1px solid #fecaca;
}

/* Calendar modal */
.calendar-modal {
    position: fixed;
    inset: 0;
    z-index: 3000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.calendar-modal.show {
    display: flex;
}

.calendar-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.35);
    backdrop-filter: blur(8px);
}

.calendar-panel {
    position: relative;
    width: min(460px, 100%);
    background: #ffffff;
    border: 1px solid var(--line);
    border-radius: 24px;
    box-shadow: 0 28px 80px rgba(15, 23, 42, 0.22);
    padding: 24px;
}

.calendar-top,
.calendar-nav {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.calendar-month {
    color: var(--navy);
    font-size: 1.5rem;
    font-weight: 900;
}

.calendar-year {
    color: var(--muted);
    font-size: 0.9rem;
    font-weight: 700;
}

.calendar-close,
.calendar-nav button {
    border: 1px solid var(--line);
    background: #ffffff;
    color: var(--blue);
    border-radius: 999px;
    min-width: 40px;
    height: 40px;
    padding: 0 14px;
    font-weight: 900;
    cursor: pointer;
}

.calendar-nav {
    margin: 18px 0;
}

.calendar-weekdays,
.calendar-days {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 8px;
    text-align: center;
}

.calendar-weekdays {
    color: var(--muted);
    font-size: 0.72rem;
    font-weight: 900;
    text-transform: uppercase;
    margin-bottom: 8px;
}

.calendar-day {
    border: 0;
    background: #f8fafc;
    color: var(--navy);
    min-height: 42px;
    border-radius: 14px;
    font-size: 0.9rem;
    font-weight: 800;
    cursor: pointer;
}

.calendar-day:hover:not(:disabled) {
    background: var(--blue-soft);
    color: var(--blue);
}

.calendar-day.other-month {
    color: #cbd5e1;
}

.calendar-day:disabled {
    color: #cbd5e1;
    cursor: not-allowed;
    background: #f8fafc;
}

.calendar-day.selected {
    color: #ffffff;
    background: var(--blue);
}

.calendar-selected-text {
    margin-top: 16px;
    color: var(--muted);
    font-size: 0.86rem;
    font-weight: 800;
    text-align: center;
}

/* SweetAlert */
.swal2-popup {
    border-radius: 22px !important;
    background: #ffffff !important;
    color: var(--navy) !important;
    border: 1px solid var(--line) !important;
}

.swal2-title {
    color: var(--navy) !important;
}

.swal2-html-container {
    color: var(--muted) !important;
}

@media (max-width: 1180px) {
    .navbar {
        height: auto;
        padding: 16px 24px;
        align-items: flex-start;
        flex-direction: column;
        gap: 12px;
    }

    .nav-links {
        width: 100%;
        justify-content: flex-start;
        flex-wrap: wrap;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .right-col {
        position: static;
    }
}

@media (max-width: 900px) {
    .invite-head {
        flex-direction: column;
        align-items: flex-start;
    }

    .unit-chip {
        width: 100%;
    }

    .top-time-grid,
    .field-grid {
        grid-template-columns: 1fr;
    }

    .segment.three {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 620px) {
    .page-wrap {
        width: min(100% - 28px, 1120px);
        padding-top: 26px;
    }

    .head-title {
        font-size: 2rem;
    }

    .card,
    .visitor-card,
    .bottom-action-row {
        padding: 18px;
    }

    .nav-btn {
        padding: 9px 11px;
        font-size: 0.76rem;
    }
}
</style>


<style id="invite-navbar-white-final-fix">
.navbar {
    height: 76px !important;
    padding: 0 5% !important;
    background: rgba(255, 255, 255, 0.92) !important;
    backdrop-filter: blur(18px) !important;
    -webkit-backdrop-filter: blur(18px) !important;
    border-bottom: 1px solid #e2e8f0 !important;
    box-shadow: none !important;
    color: #0f172a !important;
}
.navbar .brand {
    color: #0f172a !important;
}
.navbar .brand span {
    color: #2563eb !important;
}
.navbar .nav-btn {
    color: #344054 !important;
    background: transparent !important;
    border-radius: 999px !important;
    border: 0 !important;
    box-shadow: none !important;
}
.navbar .nav-btn:hover,
.navbar .nav-btn.active {
    color: #2563eb !important;
    background: #eff6ff !important;
}
.navbar .nav-btn.active {
    border: 1px solid #bfdbfe !important;
}
.navbar .nav-btn.logout {
    color: #dc2626 !important;
    background: #fff1f2 !important;
}
</style>


<style id="resident-invite-dashboard-nav-lou-final">
    body {
        min-height: 100vh !important;
        background: #eef6ff !important;
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

    .page-wrap {
        width: min(1120px, calc(100% - 48px)) !important;
        margin: 0 auto !important;
        padding: 42px 0 68px !important;
    }

    .nav-btn.logout,
    .nav-btn[href="resident_feedback.php"] {
        display: none !important;
    }

    .choice-section,
    .date-card,
    .visitor-section,
    .side-card,
    .form-card {
        background: rgba(255,255,255,.92) !important;
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
            <?php if (($notificationCount ?? 0) > 0): ?>
                <span class="nav-notification-badge">
                    <?= ($notificationCount ?? 0) > 99 ? '99+' : (int)($notificationCount ?? 0) ?>
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

<div class="page-wrap">
    <?php if ($message): ?>
        <div class="alert success"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($tempPasswordMessage): ?>
        <div class="alert info"><?= e($tempPasswordMessage) ?></div>
    <?php endif; ?>

    <?php if ($createdBookingId > 0): ?>
        <div class="alert info">
            Visitor pass created.
            <a href="visitor_pass.php?id=<?= (int)$createdBookingId ?>" style="font-weight:900;color:#93c5fd;">
                View QR pass
            </a>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="invite-shell">
        <div class="invite-head">
            <div class="invite-head-left">
                <a href="resident.php" class="back-btn" aria-label="Back">
                    <i class="fas fa-arrow-left"></i>
                </a>

                <div class="head-title">Invite Visitors</div>
            </div>

            <div class="unit-chip">
                <?= e($unitText) ?><br>
                <?= e($residentEmail) ?>
            </div>
        </div>

        <div class="invite-body">
            <form method="POST" id="inviteForm" novalidate>
                <?= csrf_field() ?>

                <input type="hidden" name="visitor_type" id="visitor_type" value="Single Visitor">
                <input type="hidden" name="purpose" id="purpose" value="Family / Friend">
                <input type="hidden" name="visit_type" id="visit_type" value="One Time Visit">

                <div class="form-grid">
                    <div class="left-col">
                        <div class="card slim">
                            <div class="segment two">
                                <button type="button" class="active" onclick="setActive(this, 'visitor_type', 'Single Visitor')">
                                    Single Visitor
                                </button>
                                <button type="button" id="multipleVisitorBtn" onclick="setActive(this, 'visitor_type', 'Multiple Visitor')">
                                    Multiple Visitor
                                </button>
                            </div>
                            <div class="visit-lock-note" id="visitorTypeLockNote">
                                <i class="fas fa-lock"></i>
                                Delivery and Others use one QR pass only, so Multiple Visitor is not available.
                            </div>
                        </div>

                        <div class="card">
                            <div class="section-label">Purpose of Visit</div>
                            <div class="segment three">
                                <button type="button" class="active" onclick="setActive(this, 'purpose', 'Family / Friend')">
                                    Family / Friend
                                </button>
                                <button type="button" id="deliveryPurposeBtn" onclick="setActive(this, 'purpose', 'Delivery')">
                                    Delivery
                                </button>
                                <button type="button" id="othersPurposeBtn" onclick="setActive(this, 'purpose', 'Others')">
                                    Others
                                </button>
                            </div>

                            <div class="purpose-help" id="purposeHelp"></div>
                            <div class="visit-lock-note" id="purposeLockNote">
                                <i class="fas fa-lock"></i>
                                Multiple Visitor is only for Family / Friend. Delivery and Others are locked in this mode.
                            </div>
                        </div>

                        <div class="card">
                            <div class="section-label">Visit Type</div>
                            <div class="segment two">
                                <button type="button" class="active" onclick="setActive(this, 'visit_type', 'One Time Visit')">
                                    One Time
                                </button>
                                <button type="button" id="multipleInOutBtn" onclick="setActive(this, 'visit_type', 'Multiple In-Out')">
                                    Multiple In-Out
                                </button>
                            </div>
                            <div class="visit-lock-note" id="visitLockNote">
                                <i class="fas fa-lock"></i>
                                Delivery and Others can only use One Time QR pass for the selected 2-hour slot.
                            </div>
                        </div>

                        <div class="card visitor-card-box" id="visitorCardsBox">
                            <div class="field-grid top-time-grid">
                                <div class="field-box calendar-field" id="visitDateBox" onclick="openCalendarPicker()">
                                    <label>Visit Date</label>
                                    <input type="hidden" name="visit_date" id="visit_date" value="<?= e($defaultVisitDate) ?>" required>
                                    <input type="text" id="visit_date_display" value="<?= e(date('d/m/Y (D)', strtotime($defaultVisitDate))) ?>" readonly>
                                    <span class="field-hint">Tap to choose booking date</span>
                                    <i class="fas fa-calendar-days calendar-field-icon"></i>
                                </div>

                                <div class="field-box select-box" data-select-box>
                                    <label id="visitSlotLabel">Time Slot</label>
                                    <select name="visit_slot" id="visit_slot" required onchange="updateDurationDisplay()">
                                        <?php foreach ($timeSlots as $slotValue => $slotLabel): ?>
                                            <option value="<?= e($slotValue) ?>" <?= $slotValue === $defaultVisitSlot ? 'selected' : '' ?>>
                                                <?= e($slotLabel) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="field-hint" id="visitSlotHint">Choose 2-hour visit slot</span>
                                </div>

                                <div class="field-box">
                                    <label>Valid Duration</label>
                                    <input type="text" id="valid_duration_display" value="Selected visit duration" readonly>
                                </div>
                            </div>

                            <div id="visitorCardsWrap">
                                <div class="visitor-card" data-index="0">
                                    <div class="visitor-card-head">
                                        <div>
                                            <div class="visitor-card-title">Visitor 1</div>
                                            <div class="visitor-card-subtitle">Fill in this visitor information.</div>
                                        </div>

                                        <button type="button" class="remove-visitor-btn" onclick="removeVisitorCard(this)" style="display:none;">
                                            <i class="fas fa-trash"></i>
                                            Remove
                                        </button>
                                    </div>

                                    <div class="field-grid">
                                        <div class="field-box">
                                            <label class="visitor-name-label">Name</label>
                                            <input type="text" name="visitors[0][visitor_name]" id="visitor_name" class="visitor-name-input" placeholder="Visitor name" required>
                                        </div>

                                        <div class="field-box visitor-detail-field">
                                            <label>Email Address</label>
                                            <input type="email" name="visitors[0][visitor_email]" id="visitor_email" placeholder="Example: visitor@email.com" required>
                                        </div>

                                        <div class="field-box visitor-detail-field">
                                            <label>IC / Passport No</label>
                                            <input type="text" name="visitors[0][visitor_ic]" id="visitor_ic" placeholder="Example: 990101-01-1234">
                                        </div>

                                        <div class="field-box visitor-detail-field">
                                            <label>Phone Number</label>
                                            <input type="text" name="visitors[0][visitor_contact]" id="visitor_contact" inputmode="numeric" maxlength="12" placeholder="Example: 012-3456789">
                                        </div>

                                        <div class="field-box visitor-detail-field">
                                            <label>Car Plate No</label>
                                            <input type="text" name="visitors[0][plate_no]" id="plate_no" class="plate-input" placeholder="ABC1234" maxlength="12">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="right-col">
                        <div class="bottom-action-row">
                            <div class="favourite-action-area">
                                <input type="hidden" name="save_favourite" id="save_favourite" value="0">

                                <button type="button" class="star-save-btn" id="starSaveBtn" onclick="toggleSaveFavourite()">
                                    <i class="fas fa-star"></i>
                                    Save visitor as Favourite Contact
                                </button>

                                <button type="button" class="favorite-btn" onclick="toggleFavouritePanel()">
                                    Select from Favourite Contact
                                </button>

                                <button type="button" class="add-visitor-btn" id="addVisitorBtn" onclick="addVisitorCard()">
                                    <i class="fas fa-plus"></i>
                                    Add Other Visitor
                                </button>
                            </div>

                            <div class="button-group">
                                <button type="submit" class="invite-btn">
                                    Invite Guest
                                </button>
                            </div>
                        </div>

                        <div class="favourite-panel" id="favouritePanel">
                            <?php if (empty($favouriteContacts)): ?>
                                <div class="aside-text">
                                    No favourite contact saved yet. Tick “Save this visitor” after filling the form to save one.
                                </div>
                            <?php else: ?>
                                <div class="favourite-list">
                                    <?php foreach ($favouriteContacts as $fav): ?>
                                        <div class="favourite-row">
                                            <button
                                                type="button"
                                                class="favourite-item"
                                                onclick='fillFavourite(<?= json_encode([
                                                    "id" => $fav["id"] ?? "",
                                                    "visitor_name" => $fav["visitor_name"] ?? "",
                                                    "visitor_email" => $fav["visitor_email"] ?? "",
                                                    "visitor_contact" => $fav["visitor_contact"] ?? "",
                                                    "visitor_ic" => $fav["visitor_ic"] ?? "",
                                                    "plate_no" => $fav["plate_no"] ?? "",
                                                    "purpose" => $fav["purpose"] ?? "Family / Friend",
                                                    "visitor_type" => $fav["visitor_type"] ?? "Single Visitor",
                                                    "visit_type" => $fav["visit_type"] ?? "One Time Visit"
                                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                            >
                                                <div class="fav-name">
                                                    <?= e(safe_text($fav['visitor_name'])) ?>
                                                </div>
                                                <div class="fav-meta">
                                                    <?= e(safe_text($fav['plate_no'])) ?>
                                                    <?php if (!empty($fav['visitor_ic'])): ?>
                                                        · IC: <?= e($fav['visitor_ic']) ?>
                                                    <?php endif; ?>
                                                    <?php if (!empty($fav['visitor_contact'])): ?>
                                                        · <?= e($fav['visitor_contact']) ?>
                                                    <?php endif; ?>
                                                    <?php if (!empty($fav['visitor_email'])): ?>
                                                        <br><?= e($fav['visitor_email']) ?>
                                                    <?php endif; ?>
                                                </div>
                                            </button>

                                            <button
                                                type="button"
                                                class="delete-fav-btn"
                                                onclick="confirmDeleteFavourite(<?= (int)$fav['id'] ?>)"
                                            >
                                                Delete
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    </div>
                </div>
            </form>
        </div>
    </section>

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


<form method="POST" id="deleteFavouriteForm" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="delete_favourite">
    <input type="hidden" name="favourite_id" id="deleteFavouriteId" value="">
</form>
</div>

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
let visitorIndexCounter = 1;
const maxVisitors = 10;

function setActive(button, inputId, value) {
    const purpose = document.getElementById('purpose')?.value || 'Family / Friend';
    const visitorType = document.getElementById('visitor_type')?.value || 'Single Visitor';
    const lockedPurpose = purpose === 'Delivery' || purpose === 'Others';

    if (inputId === 'purpose' && (value === 'Delivery' || value === 'Others') && visitorType === 'Multiple Visitor') {
        const purposeLockNote = document.getElementById('purposeLockNote');
        if (purposeLockNote) {
            purposeLockNote.classList.add('show');
        }

        Swal.fire({
            icon: 'warning',
            title: 'Not Available',
            text: 'Multiple Visitor is only for Family / Friend. Please change back to Single Visitor before choosing Delivery or Others.',
            confirmButtonColor: '#f59e0b'
        });
        return;
    }

    if (inputId === 'visitor_type' && value === 'Multiple Visitor' && lockedPurpose) {
        const visitorTypeLockNote = document.getElementById('visitorTypeLockNote');
        if (visitorTypeLockNote) {
            visitorTypeLockNote.classList.add('show');
        }

        Swal.fire({
            icon: 'warning',
            title: 'Not Available',
            text: 'Delivery and Others can only create one QR pass, so Multiple Visitor is not allowed.',
            confirmButtonColor: '#f59e0b'
        });
        return;
    }

    if (inputId === 'visit_type' && value === 'Multiple In-Out' && lockedPurpose) {
        const lockNote = document.getElementById('visitLockNote');
        if (lockNote) {
            lockNote.classList.add('show');
        }

        Swal.fire({
            icon: 'warning',
            title: 'Not Available',
            text: 'Delivery and Others can only use One Time QR pass for the selected 2-hour slot.',
            confirmButtonColor: '#f59e0b'
        });
        return;
    }

    const parent = button.parentElement;
    parent.querySelectorAll('button').forEach(function(item) {
        item.classList.remove('active');
    });
    button.classList.add('active');
    document.getElementById(inputId).value = value;

    updateDurationDisplay();
}

function formatSlotTime(hour, minute) {
    const date = new Date();
    date.setHours(hour, minute, 0, 0);
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

const monthNames = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'
];

const shortDayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const minBookingDateValue = <?= json_encode($todayDate) ?>;
const maxBookingDateValue = (() => {
    const max = new Date(minBookingDateValue + 'T00:00:00');
    max.setDate(max.getDate() + 30);
    return toDateValue(max);
})();

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
    const prevMonthDays = new Date(year, month, 0).getDate();

    monthLabel.textContent = monthNames[month];
    yearLabel.textContent = year;
    daysWrap.innerHTML = '';

    const currentMonthValue = year + '-' + String(month + 1).padStart(2, '0');
    const minMonthValue = minBookingDateValue.slice(0, 7);
    const maxMonthValue = maxBookingDateValue.slice(0, 7);

    if (prevBtn) {
        prevBtn.disabled = currentMonthValue <= minMonthValue;
    }

    if (nextBtn) {
        nextBtn.disabled = currentMonthValue >= maxMonthValue;
    }

    const totalCells = Math.ceil((startDay + daysInMonth) / 7) * 7;

    for (let cell = 0; cell < totalCells; cell++) {
        const dayNumber = cell - startDay + 1;
        let buttonDate = new Date(year, month, dayNumber);
        let displayNumber = buttonDate.getDate();
        let value = toDateValue(buttonDate);
        const isOtherMonth = dayNumber < 1 || dayNumber > daysInMonth;
        const isDisabled = value < minBookingDateValue || value > maxBookingDateValue || isOtherMonth;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'calendar-day';

        if (isOtherMonth) {
            btn.classList.add('other-month');
        }

        if (value === minBookingDateValue) {
            btn.classList.add('today');
        }

        if (value === selectedValue) {
            btn.classList.add('selected');
        }

        btn.disabled = isDisabled;
        btn.innerHTML = '<span>' + displayNumber + '</span>';

        if (!isDisabled) {
            btn.addEventListener('click', function() {
                chooseCalendarDate(value);
            });
        }

        daysWrap.appendChild(btn);
    }

    if (selectedText) {
        selectedText.textContent = formatFullSelectedDate(selectedValue);
    }
}

function chooseCalendarDate(value) {
    const hiddenInput = document.getElementById('visit_date');
    const displayInput = document.getElementById('visit_date_display');

    if (!hiddenInput || !displayInput) {
        return;
    }

    if (value < minBookingDateValue || value > maxBookingDateValue) {
        return;
    }

    hiddenInput.value = value;
    displayInput.value = formatDisplayDate(value);
    calendarViewDate = new Date(value + 'T00:00:00');

    updateSlotAvailability();
    renderCalendar();
    closeCalendarPicker();
}

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeCalendarPicker();
    }
});

function updateSlotAvailability() {
    const dateInput = document.getElementById('visit_date');
    const slotSelect = document.getElementById('visit_slot');

    if (!dateInput || !slotSelect) {
        return;
    }

    const now = new Date();
    const today = new Date();
    today.setMinutes(today.getMinutes() - today.getTimezoneOffset());
    const todayValue = today.toISOString().slice(0, 10);

    if (dateInput.value < todayValue) {
        dateInput.value = todayValue;
    }

    let firstAvailable = '';

    Array.from(slotSelect.options).forEach(function(option) {
        const parts = option.value.split(':');
        const startHour = parseInt(parts[0], 10);
        const startMinute = parseInt(parts[1], 10);
        const isPastToday = dateInput.value === todayValue &&
            (startHour < now.getHours() || (startHour === now.getHours() && startMinute <= now.getMinutes()));

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
        displayInput.value = formatDisplayDate(dateInput.value);
        calendarViewDate = new Date(dateInput.value + 'T00:00:00');
    }

    updateDurationDisplay();
}

function parseSlotStartTime(value) {
    if (!value || !value.includes(':')) {
        return null;
    }

    const parts = value.split(':');
    const hour = parseInt(parts[0], 10);
    const minute = parseInt(parts[1], 10);

    if (Number.isNaN(hour) || Number.isNaN(minute)) {
        return null;
    }

    return { hour, minute };
}

function formatDurationTime(date) {
    return date.toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: true
    });
}

function getDurationLabel(hours) {
    const dateInput = document.getElementById('visit_date');
    const slotSelect = document.getElementById('visit_slot');

    if (!dateInput || !slotSelect || !dateInput.value || !slotSelect.value) {
        return hours + ' hours';
    }

    const slot = parseSlotStartTime(slotSelect.value);
    if (!slot) {
        return hours + ' hours';
    }

    const start = new Date(dateInput.value + 'T00:00:00');
    start.setHours(slot.hour, slot.minute, 0, 0);

    const end = new Date(start.getTime() + hours * 60 * 60 * 1000);
    const nextDayText = start.toDateString() !== end.toDateString() ? ' (next day)' : '';

    return formatDurationTime(start) + ' - ' + formatDurationTime(end) + nextDayText;
}

function getSlotRangeText(slotValue, hours) {
    const slot = parseSlotStartTime(slotValue);
    if (!slot) {
        return slotValue;
    }

    const start = new Date();
    start.setHours(slot.hour, slot.minute, 0, 0);
    const end = new Date(start.getTime() + hours * 60 * 60 * 1000);
    const nextDayText = start.toDateString() !== end.toDateString() ? ' (next day)' : '';

    return formatDurationTime(start) + ' - ' + formatDurationTime(end) + nextDayText;
}

function refreshVisitSlotUI(isMultipleInOut) {
    const slotLabel = document.getElementById('visitSlotLabel');
    const slotHint = document.getElementById('visitSlotHint');
    const slotSelect = document.getElementById('visit_slot');

    if (slotLabel) {
        slotLabel.textContent = isMultipleInOut ? 'Start Time' : 'Time Slot';
    }

    if (slotHint) {
        slotHint.textContent = isMultipleInOut
            ? '8-hour access starts from this time'
            : 'Choose 2-hour visit slot';
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
    const visitTypeInput = document.getElementById('visit_type');
    const purpose = document.getElementById('purpose')?.value || 'Family / Friend';
    const durationDisplay = document.getElementById('valid_duration_display');

    if (!durationDisplay || !visitTypeInput) {
        return;
    }

    const multipleInOutBtn = document.getElementById('multipleInOutBtn');
    const lockNote = document.getElementById('visitLockNote');
    const lockedPurpose = purpose === 'Delivery' || purpose === 'Others';

    if (multipleInOutBtn) {
        multipleInOutBtn.classList.toggle('locked-option', lockedPurpose);
        multipleInOutBtn.setAttribute('aria-disabled', lockedPurpose ? 'true' : 'false');
        multipleInOutBtn.title = lockedPurpose
            ? 'Delivery and Others can only use One Time QR pass'
            : '';
    }

    if (lockNote) {
        lockNote.classList.toggle('show', lockedPurpose);
    }

    if (lockedPurpose) {
        visitTypeInput.value = 'One Time Visit';
        setButtonByValue('visit_type', 'One Time Visit', false);
    }

    const isMultipleInOut = visitTypeInput.value === 'Multiple In-Out' && !lockedPurpose;
    refreshVisitSlotUI(isMultipleInOut);

    if (isMultipleInOut) {
        durationDisplay.value = 'Valid 8 hours: ' + getDurationLabel(8);
    } else {
        durationDisplay.value = 'Valid 2 hours: ' + getDurationLabel(2);
    }

    updatePurposeFields();
}
function updatePurposeFields() {
    const purpose = document.getElementById('purpose')?.value || 'Family / Friend';
    const nameLabels = document.querySelectorAll('.visitor-name-label');
    const nameInputs = document.querySelectorAll('.visitor-name-input');
    const purposeHelp = document.getElementById('purposeHelp');
    const detailFields = document.querySelectorAll('.visitor-detail-field');
    const detailInputs = document.querySelectorAll('.visitor-detail-field input');
    const favouriteArea = document.querySelector('.favourite-action-area');
    const favouritePanel = document.getElementById('favouritePanel');
    const needsVisitorDetails = purpose === 'Family / Friend';

    detailInputs.forEach(input => {
        input.required = needsVisitorDetails;
    });

    if (purpose === 'Delivery') {
        removeExtraVisitorCards();
        nameLabels.forEach(label => label.textContent = 'Delivery Company');
        nameInputs.forEach(input => input.placeholder = 'Example: Grab, Foodpanda, J&T');
        purposeHelp.textContent = 'Delivery only needs company/service name. It will create one QR pass only and use the selected 2-hour slot.';
        purposeHelp.classList.add('show');
        detailFields.forEach(field => field.classList.add('hidden-purpose-field'));
        if (favouriteArea) favouriteArea.style.display = 'none';
        if (favouritePanel) favouritePanel.classList.remove('show');
        turnOffFavourite();
        clearVisitorDetailValues();
    } else if (purpose === 'Others') {
        removeExtraVisitorCards();
        nameLabels.forEach(label => label.textContent = 'Reason / Service Name');
        nameInputs.forEach(input => input.placeholder = 'Example: Renovation, moving service, repair work');
        purposeHelp.textContent = 'Others is for special reasons such as renovation or repair. It will create one QR pass only and use the selected 2-hour slot.';
        purposeHelp.classList.add('show');
        detailFields.forEach(field => field.classList.add('hidden-purpose-field'));
        if (favouriteArea) favouriteArea.style.display = 'none';
        if (favouritePanel) favouritePanel.classList.remove('show');
        turnOffFavourite();
        clearVisitorDetailValues();
    } else {
        nameLabels.forEach(label => label.textContent = 'Name');
        nameInputs.forEach(input => input.placeholder = 'Visitor name');
        purposeHelp.classList.remove('show');
        purposeHelp.textContent = '';
        detailFields.forEach(field => field.classList.remove('hidden-purpose-field'));
        if (favouriteArea) favouriteArea.style.display = '';
    }

    updateVisitorTypeUI();
}

function updatePurposeLockUI() {
    const visitorType = document.getElementById('visitor_type')?.value || 'Single Visitor';
    const deliveryPurposeBtn = document.getElementById('deliveryPurposeBtn');
    const othersPurposeBtn = document.getElementById('othersPurposeBtn');
    const purposeLockNote = document.getElementById('purposeLockNote');
    const lockPurposeForMultiple = visitorType === 'Multiple Visitor';

    [deliveryPurposeBtn, othersPurposeBtn].forEach(function(btn) {
        if (!btn) return;
        btn.classList.toggle('locked-option', lockPurposeForMultiple);
        btn.setAttribute('aria-disabled', lockPurposeForMultiple ? 'true' : 'false');
        btn.title = lockPurposeForMultiple
            ? 'Multiple Visitor is only for Family / Friend'
            : '';
    });

    if (purposeLockNote) {
        purposeLockNote.classList.toggle('show', lockPurposeForMultiple);
    }
}

function updateVisitorTypeUI() {
    const visitorTypeInput = document.getElementById('visitor_type');
    const purpose = document.getElementById('purpose')?.value || 'Family / Friend';

    if (!visitorTypeInput) {
        return;
    }

    const lockedPurpose = purpose === 'Delivery' || purpose === 'Others';
    const multipleVisitorBtn = document.getElementById('multipleVisitorBtn');
    const visitorTypeLockNote = document.getElementById('visitorTypeLockNote');

    updatePurposeLockUI();

    if (multipleVisitorBtn) {
        multipleVisitorBtn.classList.toggle('locked-option', lockedPurpose);
        multipleVisitorBtn.setAttribute('aria-disabled', lockedPurpose ? 'true' : 'false');
        multipleVisitorBtn.title = lockedPurpose
            ? 'Delivery and Others can only create one QR pass'
            : '';
    }

    if (visitorTypeLockNote) {
        visitorTypeLockNote.classList.toggle('show', lockedPurpose);
    }

    if (lockedPurpose && visitorTypeInput.value === 'Multiple Visitor') {
        visitorTypeInput.value = 'Single Visitor';
        activateButtonByValueOnly('visitor_type', 'Single Visitor');
    }

    const isMultiple = visitorTypeInput.value === 'Multiple Visitor' && purpose === 'Family / Friend';
    document.body.classList.toggle('multiple-mode', isMultiple);

    const addVisitorBtn = document.getElementById('addVisitorBtn');
    if (addVisitorBtn) {
        addVisitorBtn.style.display = isMultiple ? 'inline-flex' : 'none';
    }

    const starBtn = document.getElementById('starSaveBtn');
    if (starBtn) {
        starBtn.innerHTML = isMultiple
            ? '<i class="fas fa-star"></i> Save visitors as Favourite Contact'
            : '<i class="fas fa-star"></i> Save visitor as Favourite Contact';
    }

    if (!isMultiple) {
        removeExtraVisitorCards();
    }

    updatePurposeLockUI();
    updateVisitorLabels();
}

function activateButtonByValueOnly(inputId, value) {
    const allButtons = document.querySelectorAll('button[onclick*="' + inputId + '"]');

    allButtons.forEach(function(button) {
        button.classList.remove('active');
        if (button.getAttribute('onclick').includes("'" + value + "'")) {
            button.classList.add('active');
        }
    });
}

function clearVisitorDetailValues() {
    document.querySelectorAll('.plate-input').forEach(input => input.value = '');
    document.querySelectorAll('input[name*="[visitor_email]"]').forEach(input => input.value = '');
    document.querySelectorAll('input[name*="[visitor_ic]"]').forEach(input => input.value = '');
    document.querySelectorAll('input[name*="[visitor_contact]"]').forEach(input => input.value = '');
}

document.querySelectorAll('[data-select-box]').forEach(function(box) {
    const select = box.querySelector('select');
    if (!select) {
        return;
    }

    box.addEventListener('click', function(event) {
        if (event.target === select) {
            return;
        }

        select.focus();
        if (typeof select.showPicker === 'function') {
            select.showPicker();
        }
    });
});

renderCalendar();
updateSlotAvailability();
updateDurationDisplay();
updatePurposeFields();

function toggleExtraFields() {
    const extra = document.getElementById('extraFields');
    if (extra) {
        extra.classList.toggle('show');
    }
}

function toggleFavouritePanel() {
    document.getElementById('favouritePanel').classList.toggle('show');
}

function turnOffFavourite() {
    const input = document.getElementById('save_favourite');
    const button = document.getElementById('starSaveBtn');

    if (input) input.value = '0';
    if (button) button.classList.remove('active');
}

function toggleSaveFavourite() {
    const input = document.getElementById('save_favourite');
    const button = document.getElementById('starSaveBtn');

    if (!input || !button) {
        return;
    }

    const isSaving = input.value === '1';

    if (isSaving) {
        input.value = '0';
        button.classList.remove('active');
        Swal.fire({
            icon: 'info',
            title: 'Favourite Off',
            text: 'This visitor will not be saved as favourite.',
            timer: 1200,
            showConfirmButton: false
        });
        return;
    }

    const cards = Array.from(document.querySelectorAll('.visitor-card'));

    for (let cardIndex = 0; cardIndex < cards.length; cardIndex++) {
        const card = cards[cardIndex];
        const visitorNo = cardIndex + 1;
        const requiredFields = [
            {
                field: card.querySelector('.visitor-name-input'),
                label: 'Visitor ' + visitorNo + ' name'
            },
            {
                field: card.querySelector('input[name*="[visitor_ic]"]'),
                label: 'Visitor ' + visitorNo + ' IC / Passport number'
            },
            {
                field: card.querySelector('input[name*="[visitor_contact]"]'),
                label: 'Visitor ' + visitorNo + ' phone number'
            },
            {
                field: card.querySelector('.plate-input'),
                label: 'Visitor ' + visitorNo + ' car plate number'
            }
        ];

        for (const item of requiredFields) {
            if (!item.field || item.field.value.trim() === '') {
                Swal.fire({
                    icon: 'warning',
                    title: 'Cannot Save Favourite Yet',
                    text: 'Please fill in ' + item.label + ' before saving as favourite.',
                    confirmButtonColor: '#19b9e8'
                }).then(() => {
                    if (item.field) {
                        item.field.focus();
                    }
                });

                return;
            }
        }
    }

    input.value = '1';
    button.classList.add('active');

    Swal.fire({
        icon: 'success',
        title: 'Favourite Selected',
        text: 'After you press Invite Guest, the visitor information will be saved.',
        timer: 1400,
        showConfirmButton: false
    });
}

function addVisitorCard() {
    const purpose = document.getElementById('purpose')?.value || 'Family / Friend';
    const visitorType = document.getElementById('visitor_type')?.value || 'Single Visitor';

    if (purpose !== 'Family / Friend' || visitorType !== 'Multiple Visitor') {
        Swal.fire({
            icon: 'info',
            title: 'Multiple Visitor Only',
            text: 'Please choose Multiple Visitor under Family / Friend first.',
            confirmButtonColor: '#19b9e8'
        });
        return;
    }

    const currentCards = document.querySelectorAll('.visitor-card').length;

    if (currentCards >= maxVisitors) {
        Swal.fire({
            icon: 'warning',
            title: 'Maximum Reached',
            text: 'You can invite maximum 10 visitors at one time.',
            confirmButtonColor: '#19b9e8'
        });
        return;
    }

    const index = visitorIndexCounter++;
    const card = document.createElement('div');
    card.className = 'visitor-card';
    card.dataset.index = index;
    card.innerHTML = `
        <div class="visitor-card-head">
            <div>
                <div class="visitor-card-title">Visitor ${currentCards + 1}</div>
                <div class="visitor-card-subtitle">Fill in this visitor information.</div>
            </div>

            <button type="button" class="remove-visitor-btn" onclick="removeVisitorCard(this)">
                <i class="fas fa-trash"></i>
                Remove
            </button>
        </div>

        <div class="field-grid">
            <div class="field-box">
                <label class="visitor-name-label">Name</label>
                <input type="text" name="visitors[${index}][visitor_name]" class="visitor-name-input" placeholder="Visitor name" required>
            </div>

            <div class="field-box visitor-detail-field">
                <label>Email Address</label>
                <input type="email" name="visitors[${index}][visitor_email]" placeholder="Example: visitor@email.com" required>
            </div>

            <div class="field-box visitor-detail-field">
                <label>IC / Passport No</label>
                <input type="text" name="visitors[${index}][visitor_ic]" placeholder="Example: 990101-01-1234" required>
            </div>

            <div class="field-box visitor-detail-field">
                <label>Phone Number</label>
                <input type="text" name="visitors[${index}][visitor_contact]" inputmode="numeric" maxlength="12" placeholder="Example: 012-3456789">
            </div>

            <div class="field-box visitor-detail-field">
                <label>Car Plate No</label>
                <input type="text" name="visitors[${index}][plate_no]" class="plate-input" placeholder="ABC1234" maxlength="12">
            </div>
        </div>
    `;

    document.getElementById('visitorCardsWrap').appendChild(card);
    initialisePlateInput(card.querySelector('.plate-input'));
    initialisePhoneInput(card.querySelector('input[name*="[visitor_contact]"]'));
    updateVisitorLabels();
    updatePurposeFields();

    const firstInput = card.querySelector('.visitor-name-input');
    if (firstInput) {
        firstInput.focus();
    }
}

function removeVisitorCard(button) {
    const card = button.closest('.visitor-card');
    if (card) {
        card.remove();
    }

    updateVisitorLabels();
}

function removeExtraVisitorCards() {
    const cards = document.querySelectorAll('.visitor-card');
    cards.forEach(function(card, index) {
        if (index > 0) {
            card.remove();
        }
    });

    updateVisitorLabels();
}

function updateVisitorLabels() {
    const cards = document.querySelectorAll('.visitor-card');
    const isMultiple = document.body.classList.contains('multiple-mode');

    cards.forEach(function(card, index) {
        const title = card.querySelector('.visitor-card-title');
        const subtitle = card.querySelector('.visitor-card-subtitle');
        const removeBtn = card.querySelector('.remove-visitor-btn');

        if (title) {
            title.textContent = isMultiple ? 'Visitor ' + (index + 1) : 'Visitor Details';
        }

        if (subtitle) {
            subtitle.textContent = isMultiple
                ? 'Fill in visitor ' + (index + 1) + ' information.'
                : 'Fill in this visitor information.';
        }

        if (removeBtn) {
            removeBtn.style.display = isMultiple && index > 0 ? 'inline-flex' : 'none';
        }
    });
}

function confirmDeleteFavourite(favouriteId) {
    Swal.fire({
        icon: 'warning',
        title: 'Delete favourite?',
        text: 'This saved visitor contact will be removed.',
        showCancelButton: true,
        confirmButtonText: 'Delete',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('deleteFavouriteId').value = favouriteId;
            document.getElementById('deleteFavouriteForm').submit();
        }
    });

    return false;
}

function fillFavourite(data) {
    const firstCard = document.querySelector('.visitor-card');

    if (!firstCard) {
        return;
    }

    firstCard.querySelector('.visitor-name-input').value = data.visitor_name || '';
    firstCard.querySelector('.plate-input').value = data.plate_no || '';
    firstCard.querySelector('input[name*="[visitor_email]"]').value = data.visitor_email || '';
    firstCard.querySelector('input[name*="[visitor_contact]"]').value = formatMalaysianPhone(data.visitor_contact || '');
    firstCard.querySelector('input[name*="[visitor_ic]"]').value = data.visitor_ic || '';

    setButtonByValue('visitor_type', data.visitor_type || 'Single Visitor');
    setButtonByValue('purpose', data.purpose || 'Family / Friend');
    setButtonByValue('visit_type', data.visit_type || 'One Time Visit');

    document.getElementById('favouritePanel').classList.remove('show');

    Swal.fire({
        icon: 'success',
        title: 'Favourite Loaded',
        text: 'Visitor information has been filled into the form.',
        confirmButtonColor: '#19b9e8'
    });
}

function setButtonByValue(inputId, value, shouldUpdate = true) {
    const input = document.getElementById(inputId);

    if (!input) {
        return;
    }

    input.value = value;
    activateButtonByValueOnly(inputId, value);

    if (shouldUpdate) {
        updateDurationDisplay();
    }
}

function initialisePlateInput(input) {
    if (!input) {
        return;
    }

    input.addEventListener('input', function() {
        this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    });
}

document.querySelectorAll('.plate-input').forEach(initialisePlateInput);

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

function initialisePhoneInput(input) {
    if (!input) {
        return;
    }

    input.addEventListener('input', function() {
        this.value = formatMalaysianPhone(this.value);
    });

    input.addEventListener('blur', function() {
        this.value = formatMalaysianPhone(this.value);
    });
}

document.querySelectorAll('input[name*="[visitor_contact]"]').forEach(initialisePhoneInput);


function normaliseText(value) {
    return (value || '').trim();
}

function normaliseCompact(value) {
    return normaliseText(value).replace(/[\s-]/g, '');
}

function isValidEmail(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(normaliseText(value));
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

function showInviteValidationError(field, message) {
    if (field) {
        field.classList.add('js-invalid-field');
        field.style.borderColor = '#fb7185';
        field.style.boxShadow = '0 0 0 3px rgba(251, 113, 133, 0.22)';
    }

    Swal.fire({
        icon: 'warning',
        title: 'Check Visitor Form',
        text: message,
        confirmButtonColor: '#19b9e8'
    }).then(function() {
        if (field) {
            field.scrollIntoView({ behavior: 'smooth', block: 'center' });
            field.focus();
        }
    });
}

function validateResidentInviteForm(form) {
    clearValidationMarks(form);

    const visitDate = document.getElementById('visit_date');
    const visitSlot = document.getElementById('visit_slot');
    const purpose = document.getElementById('purpose')?.value || 'Family / Friend';
    const cards = Array.from(document.querySelectorAll('.visitor-card'));

    if (!visitDate || normaliseText(visitDate.value) === '') {
        showInviteValidationError(document.getElementById('visit_date_display'), 'Please choose a visit date.');
        return false;
    }

    if (!visitSlot || normaliseText(visitSlot.value) === '') {
        showInviteValidationError(visitSlot, 'Please choose a time slot.');
        return false;
    }

    if (cards.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'No Visitor Found',
            text: 'Please add at least one visitor.',
            confirmButtonColor: '#19b9e8'
        });
        return false;
    }

    for (let index = 0; index < cards.length; index++) {
        const card = cards[index];
        const visitorNo = index + 1;
        const nameField = card.querySelector('.visitor-name-input');
        const emailField = card.querySelector('input[name*="[visitor_email]"]');
        const icField = card.querySelector('input[name*="[visitor_ic]"]');
        const phoneField = card.querySelector('input[name*="[visitor_contact]"]');
        const plateField = card.querySelector('.plate-input');

        const nameLabel = purpose === 'Delivery'
            ? 'delivery company name'
            : (purpose === 'Others' ? 'reason / service name' : 'visitor name');

        if (!nameField || normaliseText(nameField.value) === '') {
            showInviteValidationError(nameField, 'Please enter ' + nameLabel + '.');
            return false;
        }

        if (purpose === 'Family / Friend') {
            if (!emailField || normaliseText(emailField.value) === '') {
                showInviteValidationError(emailField, 'Please enter Visitor ' + visitorNo + ' email address.');
                return false;
            }

            if (!isValidEmail(emailField.value)) {
                showInviteValidationError(emailField, 'Please enter a valid email address, for example visitor@gmail.com.');
                return false;
            }

            if (!icField || normaliseText(icField.value) === '') {
                showInviteValidationError(icField, 'Please enter Visitor ' + visitorNo + ' IC / Passport number.');
                return false;
            }

            if (!isValidIcOrPassport(icField.value)) {
                showInviteValidationError(icField, 'IC must be like 990101-01-1234 or passport must be 5 to 20 letters/numbers.');
                return false;
            }

            if (!phoneField || normaliseText(phoneField.value) === '') {
                showInviteValidationError(phoneField, 'Please enter Visitor ' + visitorNo + ' phone number.');
                return false;
            }

            if (!isValidPhoneNumber(phoneField.value)) {
                showInviteValidationError(phoneField, 'Phone number must include a dash, for example 012-3456789 or 011-12345678.');
                return false;
            }

            if (!plateField || normaliseText(plateField.value) === '') {
                showInviteValidationError(plateField, 'Please enter Visitor ' + visitorNo + ' car plate number.');
                return false;
            }

            if (!isValidPlateNumber(plateField.value)) {
                showInviteValidationError(plateField, 'Car plate number must use 2 to 12 letters/numbers only, for example ABC1234.');
                return false;
            }
        }
    }

    return true;
}

const inviteForm = document.getElementById('inviteForm');
if (inviteForm) {
    inviteForm.querySelectorAll('input, select, textarea').forEach(function(field) {
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

    inviteForm.addEventListener('submit', function(event) {
        if (!validateResidentInviteForm(inviteForm)) {
            event.preventDefault();
            return false;
        }

        if (typeof window.smartvmsRecordFeatureUse === 'function') {
            window.smartvmsRecordFeatureUse('resident_invite', 'Invite Visitor');
        }

        const submitBtn = inviteForm.querySelector('.invite-btn');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Creating Pass...';
        }
    });
}
</script>

<?php smartvms_render_auto_feedback('resident_invite', 'Invite Visitor', 3); ?>

<?php require_once __DIR__ . '/resident_notification_popup.php'; ?>
</body>
</html>
