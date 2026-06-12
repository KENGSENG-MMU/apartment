<?php
require_once '../core/security.php';
require_login(['resident']);

$pdo = db();

if (file_exists('../core/parking_auto.php')) {
    require_once '../core/parking_auto.php';

    if (function_exists('run_parking_automation')) {
        run_parking_automation($pdo);
    }
}

$receiptHelperPath = __DIR__ . '/../core/receipt_helper.php';
if (file_exists($receiptHelperPath)) {
    require_once $receiptHelperPath;
}

$residentId = (int)($_SESSION['uid'] ?? 0);
$residentEmail = $_SESSION['email'] ?? '';

$message = '';
$error = '';

function safe_text($value) {
    return $value !== null && $value !== '' ? $value : '-';
}

function has_column_resident_page(PDO $pdo, string $table, string $column): bool {
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

function safe_count_resident_page(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function generate_qr_token_resident_page(): string {
    return bin2hex(random_bytes(24));
}

function smartvms_public_base_url(): string {
    $isHttps =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/apartment333/public/resident_requests.php';
    $publicDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    return $scheme . '://' . $host . $publicDir;
}

function smartvms_receipt_url(int $bookingId): string {
    return smartvms_public_base_url() . '/visitor_pass.php?id=' . $bookingId;
}

function smartvms_load_phpmailer(): bool {
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

function smartvms_send_receipt_email(
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
    ?string $pdfPath = null
): bool {
    $mailError = null;

    if (trim($toEmail) === '') {
        $mailError = 'Visitor email is empty.';
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
        $mailError = 'SMTP is not configured. Please create core/mail_config.php.';
        return false;
    }

    if (!smartvms_load_phpmailer()) {
        $mailError = 'PHPMailer is not installed. Run: composer require phpmailer/phpmailer';
        return false;
    }

    $safeName = htmlspecialchars($visitorName, ENT_QUOTES, 'UTF-8');
    $safePlate = htmlspecialchars($plateNo, ENT_QUOTES, 'UTF-8');
    $safeVisitType = htmlspecialchars($visitType, ENT_QUOTES, 'UTF-8');
    $safeStatus = htmlspecialchars($status, ENT_QUOTES, 'UTF-8');
    $safeSlotText = htmlspecialchars($slotText, ENT_QUOTES, 'UTF-8');
    $safeReceiptUrl = htmlspecialchars($receiptUrl, ENT_QUOTES, 'UTF-8');

    $startText = date('d M Y, g:i A', strtotime($startTime));
    $endText = date('d M Y, g:i A', strtotime($endTime));

    $html = "
        <div style='margin:0;padding:0;background:#f3f6fb;font-family:Arial,sans-serif;color:#111827;'>
            <div style='max-width:640px;margin:0 auto;padding:28px 16px;'>
                <div style='background:#ffffff;border:1px solid #e5e7eb;border-radius:18px;overflow:hidden;box-shadow:0 18px 40px rgba(15,23,42,.10);'>
                    <div style='background:linear-gradient(135deg,#111827,#1d4ed8);padding:24px;color:white;'>
                        <h1 style='margin:0;font-size:26px;line-height:1.2;'>SmartVMS Visitor Receipt</h1>
                        <p style='margin:8px 0 0;color:#dbeafe;font-size:14px;'>Your visit request has been approved.</p>
                    </div>

                    <div style='padding:24px;'>
                        <p style='margin:0 0 14px;font-size:15px;'>Hello <strong>{$safeName}</strong>,</p>
                        <p style='margin:0 0 18px;font-size:15px;line-height:1.6;'>
                            Your visitor pass is ready. The PDF receipt with QR code is attached. Please show it at the guard house.
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

                        <p style='margin:18px 0 0;color:#64748b;font-size:13px;line-height:1.6;'>
                            This email includes an attached PDF receipt. Open the attachment on your phone to view the QR code.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    ";

    $plainText = "SmartVMS Visitor Receipt\n\n" .
        "Hello {$visitorName},\n\n" .
        "Your visit request has been approved.\n\n" .
        "Vehicle Plate: {$plateNo}\n" .
        "Visit Type: {$visitType}\n" .
        "Status: {$status}\n" .
        "Parking Slot: {$slotText}\n" .
        "Valid From: {$startText}\n" .
        "Valid Until: {$endText}\n\n" .
        "Open the attached PDF receipt: {$receiptUrl}\n";

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
        $mail->Subject = 'SmartVMS Visitor Pass Approved - Receipt Attached';
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

function release_booking_slot(PDO $pdo, array $booking, bool $hasSlotId): void {
    if (!$hasSlotId) {
        return;
    }

    if (!empty($booking['slot_id'])) {
        $stmt = $pdo->prepare("
            UPDATE parking_slots
            SET status = 'available'
            WHERE id = ?
            AND slot_type = 'Visitor'
        ");
        $stmt->execute([(int)$booking['slot_id']]);
    }
}

function allocate_visitor_slot(PDO $pdo): ?array {
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
}

$hasFullName = has_column_resident_page($pdo, 'users', 'full_name');
$hasContact = has_column_resident_page($pdo, 'users', 'contact_number');

$hasPurpose = has_column_resident_page($pdo, 'bookings', 'purpose');
$hasQrToken = has_column_resident_page($pdo, 'bookings', 'qr_token');
$hasSlotId = has_column_resident_page($pdo, 'bookings', 'slot_id');
$hasVisitorType = has_column_resident_page($pdo, 'bookings', 'visitor_type');
$hasVisitType = has_column_resident_page($pdo, 'bookings', 'visit_type');
$hasUpdatedAt = has_column_resident_page($pdo, 'bookings', 'updated_at');

$residentNameSql = $hasFullName ? "u.full_name AS resident_name" : "NULL AS resident_name";
$residentContactSql = $hasContact ? "u.contact_number AS resident_contact" : "NULL AS resident_contact";
$hasProfilePhoto = has_column_resident_page($pdo, 'users', 'profile_photo');
$residentPhotoSql = $hasProfilePhoto ? "u.profile_photo AS profile_photo" : "NULL AS profile_photo";

$stmt = $pdo->prepare("
    SELECT
        u.id,
        u.email,
        {$residentNameSql},
        {$residentContactSql},
        {$residentPhotoSql},

        ru.unit_id,
        a.apartment_name,
        a.address,
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

$residentName = ($resident['resident_name'] ?? '') ?: explode('@', $residentEmail)[0];

$unitText = 'No active unit assigned';

if (!empty($resident['unit_no'])) {
    $unitText =
        'Block ' . $resident['block_no'] .
        ' / Floor ' . $resident['floor_no'] .
        ' / Unit ' . $resident['unit_no'];
}

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

$notificationCount = safe_count_resident_page(
    $pdo,
    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND COALESCE(is_read, 0) = 0",
    [$residentId]
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'approve_booking') {
                $bookingId = (int)($_POST['booking_id'] ?? 0);

                if ($bookingId <= 0) {
                    throw new Exception('Invalid booking selected.');
                }

                $stmt = $pdo->prepare("
                    SELECT *
                    FROM bookings
                    WHERE id = ?
                    AND resident_id = ?
                    LIMIT 1
                ");
                $stmt->execute([
                    $bookingId,
                    $residentId
                ]);
                $booking = $stmt->fetch();

                if (!$booking) {
                    throw new Exception('Booking not found.');
                }

                if ($booking['status'] !== 'pending') {
                    throw new Exception('Only pending booking can be approved.');
                }

                $visitorInfoSql = $hasFullName
                    ? "SELECT email, full_name FROM users WHERE id = ? LIMIT 1"
                    : "SELECT email, NULL AS full_name FROM users WHERE id = ? LIMIT 1";

                $visitorStmt = $pdo->prepare($visitorInfoSql);
                $visitorStmt->execute([(int)$booking['visitor_user_id']]);
                $visitorInfo = $visitorStmt->fetch();

                $visitorEmailForReceipt = $visitorInfo['email'] ?? '';
                $visitorNameForReceipt = $booking['visitor_name']
                    ?: (($visitorInfo['full_name'] ?? '') ?: explode('@', $visitorEmailForReceipt)[0]);

                $receiptUrl = smartvms_receipt_url($bookingId);

                $pdo->beginTransaction();

                $slot = null;
                $newStatus = 'approved';

                if ($hasSlotId) {
                    $slot = allocate_visitor_slot($pdo);

                    if ($slot) {
                        $newStatus = 'allocated';
                    } else {
                        $newStatus = 'waiting';
                    }
                }

                $generatedQrToken = null;

                $sets = [
                    "status = ?"
                ];
                $params = [
                    $newStatus
                ];

                if ($hasSlotId) {
                    $sets[] = "slot_id = ?";
                    $params[] = $slot ? (int)$slot['id'] : null;
                }

                if ($hasQrToken && empty($booking['qr_token'])) {
                    $generatedQrToken = generate_qr_token_resident_page();
                    $sets[] = "qr_token = ?";
                    $params[] = $generatedQrToken;
                }

                if ($hasUpdatedAt) {
                    $sets[] = "updated_at = NOW()";
                }

                $params[] = $bookingId;
                $params[] = $residentId;

                $stmt = $pdo->prepare("
                    UPDATE bookings
                    SET " . implode(', ', $sets) . "
                    WHERE id = ?
                    AND resident_id = ?
                ");
                $stmt->execute($params);

                $slotTextForReceipt = 'Not assigned';

                if ($newStatus === 'allocated' && $slot) {
                    $slotTextForReceipt = $slot['block_name'] . ' ' . $slot['slot_no'];
                } elseif ($newStatus === 'waiting') {
                    $slotTextForReceipt = 'Waiting for visitor parking slot';
                }

                if (function_exists('create_notification')) {
                    $notifyMsg = 'Your visit request has been approved.';

                    if ($newStatus === 'allocated' && $slot) {
                        $notifyMsg .= ' Visitor parking slot: ' . $slotTextForReceipt . '.';
                    }

                    if ($newStatus === 'waiting') {
                        $notifyMsg .= ' Visitor parking is currently full, so your booking is in waiting status.';
                    }

                    $notifyMsg .= ' Receipt / QR pass: ' . $receiptUrl;

                    create_notification(
                        $pdo,
                        (int)$booking['visitor_user_id'],
                        'Visit Request Approved - Receipt Ready',
                        $notifyMsg,
                        'booking'
                    );
                }

                if (function_exists('log_audit')) {
                    log_audit(
                        'VISITOR_BOOKING_APPROVED',
                        'Resident approved booking #' . $bookingId . '. Status: ' . $newStatus . '. Plate: ' . $booking['plate_no']
                    );
                }

                $pdo->commit();

                if ($newStatus === 'waiting') {
                    $message = 'Booking approved, but visitor parking is full. Booking is now in waiting status.';
                } else {
                    $message = 'Booking approved successfully.';
                }

                $receiptPdfPath = null;
                $receiptQrPath = null;
                $receiptGenerateError = null;

                if (function_exists('svms_generate_receipt_pdf')) {
                    try {
                        $receiptFiles = svms_generate_receipt_pdf([
                            'booking_id' => $bookingId,
                            'visitor_name' => $visitorNameForReceipt,
                            'visitor_email' => $visitorEmailForReceipt,
                            'visitor_phone' => (string)($booking['visitor_contact'] ?? '-'),
                            'visitor_ic' => (string)($booking['visitor_ic'] ?? '-'),
                            'plate_no' => (string)($booking['plate_no'] ?? '-'),
                            'purpose' => (string)($booking['purpose'] ?? '-'),
                            'visit_type' => $hasVisitType ? (string)($booking['visit_type'] ?? '-') : '-',
                            'arrival' => (string)($booking['start_time'] ?? '-'),
                            'valid_until' => (string)($booking['end_time'] ?? '-'),
                            'resident_unit' => $unitText,
                            'parking_slot' => $slotTextForReceipt,
                            'approved_at' => date('Y-m-d H:i:s'),
                            'qr_token' => $generatedQrToken ?: (string)($booking['qr_token'] ?? '')
                        ]);

                        $receiptPdfPath = $receiptFiles['pdf_path'] ?? null;
                        $receiptQrPath = $receiptFiles['qr_path'] ?? null;
                    } catch (Throwable $e) {
                        $receiptGenerateError = $e->getMessage();
                    }
                } else {
                    $receiptGenerateError = 'receipt_helper.php is not loaded.';
                }

                $mailError = null;
                $emailSent = smartvms_send_receipt_email(
                    $visitorEmailForReceipt,
                    $visitorNameForReceipt,
                    $booking['plate_no'],
                    $booking['start_time'],
                    $booking['end_time'],
                    $hasVisitType ? (string)($booking['visit_type'] ?? '-') : '-',
                    $newStatus,
                    $slotTextForReceipt,
                    $receiptUrl,
                    $mailError,
                    $receiptPdfPath
                );

                if ($emailSent) {
                    if ($receiptPdfPath && file_exists($receiptPdfPath)) {
                        $message .= ' Receipt PDF email has been sent to ' . $visitorEmailForReceipt . '.';
                    } else {
                        $message .= ' Receipt email has been sent to ' . $visitorEmailForReceipt . ', but PDF was not attached. ' . $receiptGenerateError;
                    }
                } else {
                    $message .= ' However, receipt email was not sent. ' . $mailError;

                    if ($receiptGenerateError) {
                        $message .= ' PDF error: ' . $receiptGenerateError;
                    }
                }
            }

            if ($action === 'reject_booking') {
                $bookingId = (int)($_POST['booking_id'] ?? 0);
                $rejectReason = trim($_POST['reject_reason'] ?? '');

                if ($bookingId <= 0) {
                    throw new Exception('Invalid booking selected.');
                }

                $stmt = $pdo->prepare("
                    SELECT *
                    FROM bookings
                    WHERE id = ?
                    AND resident_id = ?
                    LIMIT 1
                ");
                $stmt->execute([
                    $bookingId,
                    $residentId
                ]);
                $booking = $stmt->fetch();

                if (!$booking) {
                    throw new Exception('Booking not found.');
                }

                if (!in_array($booking['status'], ['pending', 'approved', 'allocated', 'waiting'], true)) {
                    throw new Exception('This booking cannot be rejected now.');
                }

                $pdo->beginTransaction();

                release_booking_slot($pdo, $booking, $hasSlotId);

                $sets = [
                    "status = 'rejected'"
                ];

                if ($hasSlotId) {
                    $sets[] = "slot_id = NULL";
                }

                if ($hasUpdatedAt) {
                    $sets[] = "updated_at = NOW()";
                }

                $stmt = $pdo->prepare("
                    UPDATE bookings
                    SET " . implode(', ', $sets) . "
                    WHERE id = ?
                    AND resident_id = ?
                ");
                $stmt->execute([
                    $bookingId,
                    $residentId
                ]);

                if (function_exists('create_notification')) {
                    $notifyMsg = 'Your visit request has been rejected.';

                    if ($rejectReason !== '') {
                        $notifyMsg .= ' Reason: ' . $rejectReason;
                    }

                    create_notification(
                        $pdo,
                        (int)$booking['visitor_user_id'],
                        'Visit Request Rejected',
                        $notifyMsg,
                        'booking'
                    );
                }

                if (function_exists('log_audit')) {
                    log_audit(
                        'VISITOR_BOOKING_REJECTED',
                        'Resident rejected booking #' . $bookingId . '. Plate: ' . $booking['plate_no']
                    );
                }

                $pdo->commit();

                $message = 'Booking rejected successfully.';
            }

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e->getMessage();
        }
    }

    $_SESSION['resident_requests_flash'] = [
        'message' => $message,
        'error' => $error
    ];

    header('Location: resident_requests.php');
    exit;
}

if (isset($_SESSION['resident_requests_flash']) && is_array($_SESSION['resident_requests_flash'])) {
    $message = $_SESSION['resident_requests_flash']['message'] ?? '';
    $error = $_SESSION['resident_requests_flash']['error'] ?? '';
    unset($_SESSION['resident_requests_flash']);
}

$purposeSelectSql = $hasPurpose ? "b.purpose" : "NULL AS purpose";
$visitorTypeSelectSql = $hasVisitorType ? "b.visitor_type" : "NULL AS visitor_type";
$visitTypeSelectSql = $hasVisitType ? "b.visit_type" : "NULL AS visit_type";
$qrTokenSelectSql = $hasQrToken ? "b.qr_token" : "NULL AS qr_token";
$slotJoinSql = $hasSlotId ? "LEFT JOIN parking_slots ps ON ps.id = b.slot_id" : "LEFT JOIN parking_slots ps ON 1 = 0";

$visitorNameSql = $hasFullName ? "vu.full_name AS visitor_account_name" : "NULL AS visitor_account_name";
$visitorContactSql = $hasContact ? "vu.contact_number AS visitor_contact" : "NULL AS visitor_contact";

$stmt = $pdo->prepare("
    SELECT
        b.id,
        b.visitor_user_id,
        b.resident_id,
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

        vu.email AS visitor_email,
        {$visitorNameSql},
        {$visitorContactSql},

        ps.block_name AS parking_block,
        ps.slot_no AS parking_slot_no,
        ps.status AS parking_status

    FROM bookings b

    LEFT JOIN users vu ON vu.id = b.visitor_user_id

    {$slotJoinSql}

    WHERE b.resident_id = ?
    AND b.status = 'pending'

    ORDER BY b.created_at ASC
    LIMIT 100
");
$stmt->execute([$residentId]);
$bookings = $stmt->fetchAll();

$pendingBookings = safe_count_resident_page(
    $pdo,
    "SELECT COUNT(*) FROM bookings WHERE resident_id = ? AND status = 'pending'",
    [$residentId]
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Visitor Requests - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
:root {
    --page-bg: #f8fafc;
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
    --blue-soft-2: #dbeafe;
    --red: #ef4444;
    --red-soft: #fff1f2;
    --green: #16a34a;
    --green-soft: #dcfce7;
    --yellow: #f59e0b;
    --yellow-soft: #fef3c7;
    --shadow-sm: 0 8px 20px rgba(15, 23, 42, 0.045);
    --shadow-md: 0 18px 45px rgba(15, 23, 42, 0.09);
}

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
    font-family: 'Plus Jakarta Sans', sans-serif;
}

body {
    min-height: 100vh;
    color: var(--text);
    background:
        radial-gradient(circle at top right, rgba(219, 234, 254, 0.45), transparent 25%),
        linear-gradient(180deg, #ffffff 0%, #f3f6fb 100%);
    overflow-x: hidden;
}

body::before {
    content: "";
    position: fixed;
    inset: 0;
    pointer-events: none;
    z-index: 0;
    background:
        radial-gradient(circle at 8% 19%, rgba(147, 197, 253, 0.13) 0 70px, transparent 72px),
        radial-gradient(circle at 91% 30%, rgba(191, 219, 254, 0.18) 0 95px, transparent 97px),
        radial-gradient(circle at 15% 82%, rgba(186, 230, 253, 0.14) 0 62px, transparent 64px),
        radial-gradient(circle at 87% 88%, rgba(219, 234, 254, 0.24) 0 86px, transparent 88px),
        radial-gradient(circle at 52% 68%, rgba(147, 197, 253, 0.10) 0 46px, transparent 48px);
}

a {
    color: inherit;
    text-decoration: none;
}

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
}

.brand {
    font-size: 1.55rem;
    font-weight: 900;
    color: var(--navy);
    letter-spacing: -0.8px;
}

.brand span {
    color: var(--blue);
}

.nav-links {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
}

.nav-btn {
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

.page {
    width: min(1120px, calc(100% - 48px));
    margin: 0 auto;
    padding: 38px 0 64px;
    position: relative;
    z-index: 1;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 24px;
    margin-bottom: 26px;
    padding: 6px 4px 24px;
    border-bottom: 1px solid var(--line);
}

.header-kicker {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--blue);
    background: rgba(239, 246, 255, 0.9);
    border: 1px solid #dbeafe;
    padding: 7px 12px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 900;
    letter-spacing: 0.35px;
    margin-bottom: 14px;
}

.header-info h1 {
    font-size: 2.55rem;
    font-weight: 900;
    color: var(--navy);
    letter-spacing: -1.5px;
    line-height: 1.08;
    margin-bottom: 10px;
}

.header-info p {
    color: var(--muted);
    font-size: 1rem;
    font-weight: 600;
    max-width: 700px;
}

.header-side {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.summary-pill {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    border-radius: 16px;
    background: var(--blue-soft);
    border: 1px solid #bfdbfe;
    color: var(--blue);
    font-size: 0.9rem;
    font-weight: 900;
}

.summary-pill strong {
    min-width: 30px;
    height: 30px;
    border-radius: 999px;
    background: #ffffff;
    color: var(--blue);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.92rem;
    font-weight: 900;
}

.unit-badge {
    min-width: 220px;
    padding: 14px 20px;
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.82);
    border: 1px solid var(--line);
    box-shadow: var(--shadow-sm);
    display: flex;
    align-items: center;
    gap: 12px;
}

.unit-icon {
    width: 38px;
    height: 38px;
    border-radius: 12px;
    background: var(--blue-soft);
    color: var(--blue);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.unit-badge small {
    display: block;
    color: var(--muted);
    font-size: 0.68rem;
    font-weight: 900;
    letter-spacing: 0.7px;
    text-transform: uppercase;
    margin-bottom: 2px;
}

.unit-badge strong {
    display: block;
    color: var(--navy);
    font-size: 0.92rem;
    font-weight: 900;
}

.unit-badge .sub {
    color: var(--muted);
    font-size: 0.78rem;
    font-weight: 700;
    margin-top: 2px;
}

.alert {
    border-radius: 16px;
    padding: 14px 16px;
    margin-bottom: 18px;
    font-weight: 800;
    line-height: 1.5;
}

.alert.success {
    color: #166534;
    background: #dcfce7;
    border: 1px solid #bbf7d0;
}

.alert.error {
    color: #991b1b;
    background: #fee2e2;
    border: 1px solid #fecaca;
}

.empty-card,
.request-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 22px;
    box-shadow: var(--shadow-sm);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
}

.empty-card {
    padding: 48px 20px;
    text-align: center;
}

.empty-icon {
    width: 64px;
    height: 64px;
    margin: 0 auto 14px;
    border-radius: 20px;
    background: var(--blue-soft);
    color: var(--blue);
    border: 1px solid #dbeafe;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.empty-card h3 {
    color: var(--navy);
    font-size: 1.2rem;
    font-weight: 900;
    margin-bottom: 8px;
}

.empty-card p {
    color: var(--muted);
    font-size: 0.95rem;
    font-weight: 650;
}

.requests-grid {
    display: grid;
    gap: 20px;
}

.request-card {
    padding: 24px;
    transition: 0.22s ease;
}

.request-card:hover {
    box-shadow: var(--shadow-md);
    border-color: #bfdbfe;
}

.request-head {
    display: flex;
    justify-content: space-between;
    gap: 20px;
    align-items: flex-start;
    margin-bottom: 20px;
}

.visitor-main {
    display: flex;
    align-items: flex-start;
    gap: 14px;
}

.visitor-icon {
    width: 54px;
    height: 54px;
    border-radius: 16px;
    background: var(--blue-soft);
    color: var(--blue);
    border: 1px solid #dbeafe;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}

.visitor-name {
    color: var(--navy);
    font-size: 1.22rem;
    font-weight: 900;
    margin-bottom: 5px;
}

.visitor-sub {
    color: var(--muted);
    font-size: 0.9rem;
    font-weight: 700;
    line-height: 1.5;
}

.plate-tag {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-top: 8px;
    padding: 7px 12px;
    border-radius: 999px;
    background: #f8fafc;
    border: 1px solid var(--line);
    color: var(--navy);
    font-size: 0.8rem;
    font-weight: 800;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    border-radius: 999px;
    background: var(--yellow-soft);
    color: #b45309;
    border: 1px solid #fde68a;
    font-size: 0.82rem;
    font-weight: 900;
    white-space: nowrap;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 14px;
    margin-bottom: 20px;
}

.detail-box {
    min-height: 88px;
    border-radius: 16px;
    border: 1px solid var(--line);
    background: #ffffff;
    padding: 14px 15px;
}

.detail-label {
    color: var(--muted);
    font-size: 0.7rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.55px;
    margin-bottom: 8px;
}

.detail-value {
    color: var(--navy);
    font-size: 0.92rem;
    font-weight: 800;
    line-height: 1.5;
    word-break: break-word;
}

.action-grid {
    display: grid;
    grid-template-columns: 170px 1fr;
    gap: 16px;
    align-items: stretch;
}

.approve-panel,
.reject-panel {
    border-radius: 18px;
    border: 1px solid var(--line);
    background: #ffffff;
    padding: 16px;
}

.approve-panel {
    display: flex;
    align-items: center;
    justify-content: center;
}

.reject-panel {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.reject-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.reject-head h4 {
    color: var(--navy);
    font-size: 0.95rem;
    font-weight: 900;
}

.reject-head p {
    color: var(--muted);
    font-size: 0.8rem;
    font-weight: 650;
}

.inline-note {
    color: var(--muted);
    font-size: 0.82rem;
    font-weight: 650;
    line-height: 1.45;
}

textarea {
    width: 100%;
    min-height: 94px;
    resize: vertical;
    border-radius: 16px;
    border: 1px solid var(--line);
    background: #ffffff;
    color: var(--navy);
    padding: 14px 15px;
    font-size: 0.92rem;
    font-weight: 700;
    outline: none;
}

textarea:focus {
    border-color: #bfdbfe;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.08);
}

textarea::placeholder {
    color: #94a3b8;
}

.btn {
    border: 0;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    min-height: 44px;
    padding: 0 22px;
    border-radius: 999px;
    font-size: 0.84rem;
    font-weight: 900;
    transition: 0.22s ease;
}

.btn-approve {
    width: 100%;
    color: #ffffff;
    background: var(--green);
    box-shadow: 0 12px 22px rgba(22, 163, 74, 0.18);
}

.btn-approve:hover {
    background: #15803d;
    transform: translateY(-2px);
}

.btn-reject {
    color: #dc2626;
    background: var(--red-soft);
    border: 1px solid #fecaca;
}

.btn-reject:hover {
    background: #ffe4e6;
}

.btn-toggle {
    color: var(--blue);
    background: var(--blue-soft);
    border: 1px solid #bfdbfe;
}

.btn-toggle:hover {
    background: #dbeafe;
}

.reject-form {
    display: none;
}

.reject-form.open {
    display: block;
}

.floating-history-btn {
    position: fixed;
    right: 28px;
    bottom: 28px;
    z-index: 140;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    min-height: 50px;
    padding: 0 18px;
    border-radius: 999px;
    background: var(--blue);
    color: #ffffff;
    font-size: 0.86rem;
    font-weight: 900;
    box-shadow: 0 16px 26px rgba(37, 99, 235, 0.22);
}

.floating-history-btn:hover {
    background: var(--blue-dark);
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
        flex-wrap: wrap;
        justify-content: flex-start;
    }

    .detail-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .action-grid {
        grid-template-columns: 1fr;
    }

    .header-side {
        justify-content: flex-start;
    }
}

@media (max-width: 900px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .unit-badge {
        width: 100%;
    }

    .request-head {
        flex-direction: column;
    }
}

@media (max-width: 620px) {
    .page {
        width: min(100% - 28px, 1120px);
        padding-top: 26px;
    }

    .header-info h1 {
        font-size: 2rem;
    }

    .detail-grid {
        grid-template-columns: 1fr;
    }

    .nav-btn {
        padding: 9px 11px;
        font-size: 0.76rem;
    }

    .floating-history-btn {
        right: 18px;
        bottom: 18px;
    }
}
    </style>

<style id="resident-requests-dashboard-nav-lou-final">
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

    .page {
        width: min(1120px, calc(100% - 48px)) !important;
        margin: 0 auto !important;
        padding-top: 42px !important;
        position: relative !important;
        z-index: 1 !important;
    }

    .page-header,
    .requests-panel,
    .empty-state,
    .request-card {
        background: rgba(255,255,255,.92) !important;
    }

    .floating-history-btn {
        background: linear-gradient(135deg, #38bdf8, #2563eb) !important;
        box-shadow: 0 18px 38px rgba(37, 99, 235, .24) !important;
    }

    .nav-btn.logout,
    .nav-btn[href="resident_feedback.php"] {
        display: none !important;
    }
</style>


<style id="resident-requests-soft-rounded-final">
    .page {
        width: min(1120px, calc(100% - 56px)) !important;
        padding-top: 48px !important;
    }

    .page-header {
        position: relative !important;
        overflow: hidden !important;
        min-height: 176px !important;
        padding: 32px 38px !important;
        margin-bottom: 28px !important;
        border-radius: 34px !important;
        background: rgba(255, 255, 255, .88) !important;
        border: 1px solid rgba(219, 229, 240, .95) !important;
        box-shadow: 0 24px 70px rgba(15, 23, 42, .10) !important;
        backdrop-filter: blur(18px) !important;
        -webkit-backdrop-filter: blur(18px) !important;
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) 330px !important;
        gap: 26px !important;
        align-items: center !important;
    }

    .page-header::before {
        content: "" !important;
        position: absolute !important;
        inset: 0 !important;
        background:
            linear-gradient(90deg, rgba(255,255,255,.94), rgba(255,255,255,.64)),
            radial-gradient(circle at 93% 12%, rgba(59,130,246,.16), transparent 28%),
            radial-gradient(circle at 6% 100%, rgba(56,189,248,.10), transparent 25%) !important;
        pointer-events: none !important;
    }

    .page-header > * {
        position: relative !important;
        z-index: 1 !important;
    }

    .header-info {
        min-width: 0 !important;
    }

    .header-kicker {
        width: fit-content !important;
        min-height: 34px !important;
        padding: 0 14px !important;
        border-radius: 999px !important;
        background: #eff6ff !important;
        color: #2563eb !important;
        border: 1px solid #bfdbfe !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 8px !important;
        font-size: .82rem !important;
        font-weight: 900 !important;
        margin-bottom: 12px !important;
    }

    .header-info h1 {
        margin: 0 0 10px !important;
        color: #0b1220 !important;
        font-size: clamp(2.55rem, 3.4vw, 3.75rem) !important;
        line-height: .96 !important;
        letter-spacing: -.06em !important;
        font-weight: 900 !important;
    }

    .header-info p {
        max-width: 620px !important;
        margin: 0 !important;
        color: #64748b !important;
        font-size: 1rem !important;
        line-height: 1.45 !important;
        font-weight: 730 !important;
    }

    .header-side {
        display: grid !important;
        gap: 12px !important;
        align-content: center !important;
        justify-items: stretch !important;
    }

    .summary-pill,
    .unit-badge {
        width: 100% !important;
        min-height: 58px !important;
        border-radius: 22px !important;
        background: rgba(255, 255, 255, .74) !important;
        border: 1px solid #dbeafe !important;
        box-shadow: 0 12px 30px rgba(15, 23, 42, .055) !important;
    }

    .summary-pill {
        justify-content: flex-start !important;
        padding: 0 18px !important;
        gap: 12px !important;
        color: #2563eb !important;
        font-weight: 900 !important;
    }

    .summary-pill strong {
        width: 34px !important;
        height: 34px !important;
        border-radius: 14px !important;
        background: #eff6ff !important;
        color: #2563eb !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        font-weight: 900 !important;
    }

    .unit-badge {
        padding: 12px 16px !important;
        display: flex !important;
        align-items: center !important;
        gap: 12px !important;
    }

    .unit-icon {
        width: 44px !important;
        height: 44px !important;
        border-radius: 16px !important;
        background: #eff6ff !important;
        color: #2563eb !important;
    }

    .requests-panel,
    .empty-state {
        border-radius: 34px !important;
        background: rgba(255, 255, 255, .90) !important;
        border: 1px solid rgba(219, 229, 240, .95) !important;
        box-shadow: 0 24px 70px rgba(15, 23, 42, .10) !important;
        backdrop-filter: blur(18px) !important;
        -webkit-backdrop-filter: blur(18px) !important;
        overflow: hidden !important;
    }

    .empty-state {
        position: relative !important;
        min-height: 235px !important;
        padding: 42px 34px !important;
    }

    .empty-state::before {
        content: "" !important;
        position: absolute !important;
        width: 160px !important;
        height: 160px !important;
        right: -58px !important;
        bottom: -58px !important;
        border-radius: 50% !important;
        background: rgba(37, 99, 235, .07) !important;
        pointer-events: none !important;
    }

    .empty-state::after {
        content: "" !important;
        position: absolute !important;
        width: 95px !important;
        height: 95px !important;
        left: -34px !important;
        top: -34px !important;
        border-radius: 50% !important;
        background: rgba(56, 189, 248, .09) !important;
        pointer-events: none !important;
    }

    .empty-state > * {
        position: relative !important;
        z-index: 1 !important;
    }

    .empty-icon {
        width: 62px !important;
        height: 62px !important;
        border-radius: 22px !important;
        background: #eff6ff !important;
        color: #2563eb !important;
        border: 1px solid #bfdbfe !important;
        box-shadow: 0 14px 32px rgba(37, 99, 235, .10) !important;
    }

    .empty-state h3,
    .empty-state strong {
        color: #0f172a !important;
        font-size: 1.08rem !important;
        font-weight: 900 !important;
        margin-top: 14px !important;
    }

    .empty-state p {
        color: #64748b !important;
        font-size: .95rem !important;
        font-weight: 750 !important;
        margin-top: 8px !important;
    }

    .request-card {
        border-radius: 28px !important;
        background: rgba(255, 255, 255, .88) !important;
        border: 1px solid #dbe5f0 !important;
        box-shadow: 0 18px 46px rgba(15, 23, 42, .075) !important;
    }

    .floating-history-btn {
        border-radius: 18px !important;
    }

    @media (max-width: 900px) {
        .page-header {
            grid-template-columns: 1fr !important;
            padding: 28px 24px !important;
        }

        .header-side {
            grid-template-columns: 1fr !important;
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

<div class="page">
    <section class="page-header">
        <div class="header-info">
            <div class="header-kicker">
                <i class="fas fa-clipboard-check"></i>
                Resident Approval
            </div>

            <h1>Visitor Requests</h1>
            <p>Review pending visitor requests for your unit and decide whether to approve or reject them.</p>
        </div>

        <div class="header-side">
            <div class="summary-pill">
                <strong><?= (int)$pendingBookings ?></strong>
                pending request<?= $pendingBookings === 1 ? '' : 's' ?>
            </div>

            <div class="unit-badge">
                <div class="unit-icon">
                    <i class="fas fa-building"></i>
                </div>
                <div>
                    <small>Current Unit</small>
                    <strong><?= e($unitText) ?></strong>
                    <div class="sub"><?= e($residentEmail) ?></div>
                </div>
            </div>
        </div>
    </section>

    <?php if (!empty($success)): ?>
        <div class="alert success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (empty($bookings)): ?>
        <div class="empty-card">
            <div class="empty-icon"><i class="fas fa-check"></i></div>
            <h3>No pending visitor requests</h3>
            <p>Everything is clear for now. New resident approval requests will appear here.</p>
        </div>
    <?php else: ?>
        <div class="requests-grid">
            <?php foreach ($bookings as $booking): ?>
                <?php
                    $slotText = 'Will be assigned after approval';

                    if (!empty($booking['parking_block']) && !empty($booking['parking_slot_no'])) {
                        $slotText = $booking['parking_block'] . ' ' . $booking['parking_slot_no'];
                    }

                    $visitorAccountName = $booking['visitor_account_name'] ?: $booking['visitor_email'];
                    $purposeText = safe_text($booking['purpose']);
                    $visitTypeText = safe_text($booking['visit_type']);
                    $visitorTypeText = safe_text($booking['visitor_type']);
                    $rejectId = 'rejectForm' . (int)$booking['id'];
                ?>

                <div class="request-card">
                    <div class="request-head">
                        <div class="visitor-main">
                            <div class="visitor-icon">
                                <i class="fas fa-user"></i>
                            </div>

                            <div>
                                <div class="visitor-name"><?= e(safe_text($booking['visitor_name'])) ?></div>

                                <div class="visitor-sub">
                                    Visitor account: <?= e(safe_text($visitorAccountName)) ?>
                                </div>

                                <div class="plate-tag">
                                    <i class="fas fa-car"></i>
                                    <?= e(safe_text($booking['plate_no'])) ?>
                                </div>
                            </div>
                        </div>

                        <div class="status-badge">
                            <i class="fas fa-clock"></i>
                            Pending Approval
                        </div>
                    </div>

                    <div class="detail-grid">
                        <div class="detail-box">
                            <div class="detail-label">Visit Schedule</div>
                            <div class="detail-value">
                                <?= e(date('d M Y', strtotime($booking['start_time'] ?? 'now'))) ?>
                                <br>
                                <?= e(date('g:i A', strtotime($booking['start_time'] ?? 'now'))) ?>
                                -
                                <?= e(date('g:i A', strtotime($booking['end_time'] ?? 'now'))) ?>
                            </div>
                        </div>

                        <div class="detail-box">
                            <div class="detail-label">Purpose</div>
                            <div class="detail-value"><?= e($purposeText) ?></div>
                        </div>

                        <div class="detail-box">
                            <div class="detail-label">Visitor Type</div>
                            <div class="detail-value"><?= e($visitorTypeText) ?></div>
                        </div>

                        <div class="detail-box">
                            <div class="detail-label">Visit Type</div>
                            <div class="detail-value"><?= e($visitTypeText) ?></div>
                        </div>

                        <div class="detail-box">
                            <div class="detail-label">Parking Slot</div>
                            <div class="detail-value"><?= e($slotText) ?></div>
                        </div>
                    </div>

                    <div class="action-grid">
                        <div class="approve-panel">
                            <form method="POST" onsubmit="return confirmApprove(this);">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="approve_booking">
                                <input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>">

                                <button type="submit" class="btn btn-approve">
                                    <i class="fas fa-check"></i>
                                    Approve Request
                                </button>
                            </form>
                        </div>

                        <div class="reject-panel">
                            <div class="reject-head">
                                <div>
                                    <h4>Reject Request</h4>
                                    <p>Add a short reason before rejecting this booking.</p>
                                </div>

                                <button type="button" class="btn btn-toggle" onclick="toggleRejectForm('<?= $rejectId ?>')">
                                    <i class="fas fa-pen"></i>
                                    Reason
                                </button>
                            </div>

                            <div class="inline-note">
                                Approval will create the visitor pass. Rejection will notify the visitor and keep a record.
                            </div>

                            <form id="<?= $rejectId ?>" class="reject-form" method="POST" onsubmit="return confirmReject(this);">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reject_booking">
                                <input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>">

                                <textarea
                                    name="reject_reason"
                                    placeholder="Optional reason, for example: not available at this time"
                                ></textarea>

                                <button type="submit" class="btn btn-reject" style="margin-top:12px;">
                                    <i class="fas fa-xmark"></i>
                                    Reject Request
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<a href="notifications_record.php" class="floating-history-btn">
    <i class="fas fa-clock-rotate-left"></i>
    Record
</a>

<?php if (!empty($success)): ?>
<script>
Swal.fire({
    icon: 'success',
    title: 'Success',
    text: <?= json_encode($success) ?>,
    confirmButtonColor: '#2563eb',
    background: '#ffffff',
    color: '#0f172a'
});
</script>
<?php endif; ?>

<?php if (!empty($error)): ?>
<script>
Swal.fire({
    icon: 'error',
    title: 'Error',
    text: <?= json_encode($error) ?>,
    confirmButtonColor: '#2563eb',
    background: '#ffffff',
    color: '#0f172a'
});
</script>
<?php endif; ?>

<script>
function toggleRejectForm(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.toggle('open');
}

function confirmApprove(form) {
    Swal.fire({
        icon: 'question',
        title: 'Approve this request?',
        text: 'A visitor pass will be created for this visitor.',
        showCancelButton: true,
        confirmButtonText: 'Approve',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#16a34a',
        cancelButtonColor: '#94a3b8',
        background: '#ffffff',
        color: '#0f172a'
    }).then((result) => {
        if (result.isConfirmed) {
            form.submit();
        }
    });
    return false;
}

function confirmReject(form) {
    const reasonField = form.querySelector('textarea[name="reject_reason"]');
    if (reasonField && reasonField.value.trim().length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Reason needed',
            text: 'Please enter a short reject reason before submitting.',
            confirmButtonColor: '#2563eb',
            background: '#ffffff',
            color: '#0f172a'
        });
        return false;
    }

    Swal.fire({
        icon: 'warning',
        title: 'Reject this request?',
        text: 'The resident request will be rejected and the visitor will be notified.',
        showCancelButton: true,
        confirmButtonText: 'Reject',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#94a3b8',
        background: '#ffffff',
        color: '#0f172a'
    }).then((result) => {
        if (result.isConfirmed) {
            form.submit();
        }
    });
    return false;
}
</script>

<?php require_once __DIR__ . '/resident_notification_popup.php'; ?>
</body>
</html>
