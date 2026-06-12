<?php
require_once '../core/security.php';
require_login(['admin', 'superadmin']);

$pdo = db();
$adminId = (int)($_SESSION['uid'] ?? $_SESSION['user_id'] ?? 0);
$message = $_SESSION['flash_success'] ?? '';
$error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

function pm_has_table(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

function pm_has_col(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

function pm_rows(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

function pm_one(PDO $pdo, string $sql, array $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
}

function pm_count(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

function pm_money($amount): string {
    return 'RM ' . number_format((float)$amount, 2);
}

function pm_date($value, string $format = 'd M Y'): string {
    if (!$value) return '-';
    $ts = strtotime((string)$value);
    return $ts ? date($format, $ts) : '-';
}

function pm_text($value): string {
    return ($value !== null && $value !== '') ? (string)$value : '-';
}

function pm_unit_text(array $row): string {
    $block = trim((string)($row['block_no'] ?? ''));
    $floor = trim((string)($row['floor_no'] ?? ''));
    $unit = trim((string)($row['unit_no'] ?? ''));
    if ($block === '' && $floor === '' && $unit === '') return '-';
    return 'Block ' . ($block ?: '-') . ' / Floor ' . ($floor ?: '-') . ' / Unit ' . ($unit ?: '-');
}

function pm_status_badge(string $status): string {
    $s = strtolower(trim($status));
    return match ($s) {
        'active', 'available', 'approved', 'paid' => 'badge-green',
        'reserved', 'occupied', 'pending', 'pending_verification', 'unpaid' => 'badge-yellow',
        'rejected', 'cancelled', 'overdue', 'maintenance', 'inactive' => 'badge-red',
        default => 'badge-gray'
    };
}

function pm_set_flash(string $type, string $msg): void {
    if ($type === 'success') $_SESSION['flash_success'] = $msg;
    else $_SESSION['flash_error'] = $msg;
}

function pm_redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function pm_require_post(): void {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        throw new Exception('Invalid security token. Please refresh the page.');
    }
}

function pm_current_apartment_name(PDO $pdo): string {
    $apartmentId = $_SESSION['apartment_id'] ?? null;
    if (!$apartmentId && pm_has_col($pdo, 'users', 'apartment_id')) {
        $uid = (int)($_SESSION['uid'] ?? $_SESSION['user_id'] ?? 0);
        if ($uid > 0) {
            $r = pm_one($pdo, "SELECT apartment_id FROM users WHERE id = ? LIMIT 1", [$uid]);
            $apartmentId = $r['apartment_id'] ?? null;
            if ($apartmentId) $_SESSION['apartment_id'] = $apartmentId;
        }
    }
    if (!$apartmentId && pm_has_table($pdo, 'apartments')) {
        $r = pm_one($pdo, "SELECT id FROM apartments ORDER BY id ASC LIMIT 1");
        $apartmentId = $r['id'] ?? null;
    }
    if ($apartmentId && pm_has_table($pdo, 'apartments')) {
        $r = pm_one($pdo, "SELECT apartment_name FROM apartments WHERE id = ? LIMIT 1", [(int)$apartmentId]);
        if ($r && !empty($r['apartment_name'])) return (string)$r['apartment_name'];
    }
    return 'Ixoro Apartment';
}

function pm_normalize_plate(string $plate): string {
    $plate = strtoupper(trim($plate));
    $plate = preg_replace('/[^A-Z0-9]/', '', $plate);
    return $plate ?: '';
}

function pm_first_col(PDO $pdo, string $table, array $columns): ?string {
    foreach ($columns as $column) {
        if (pm_has_col($pdo, $table, $column)) {
            return $column;
        }
    }
    return null;
}

function pm_request_slot_column(PDO $pdo): ?string {
    if (!pm_has_table($pdo, 'resident_parking_requests')) {
        return null;
    }

    return pm_first_col($pdo, 'resident_parking_requests', [
        'slot_id',
        'parking_slot_id',
        'selected_slot_id',
        'requested_slot_id',
        'preferred_slot_id',
        'resident_selected_slot_id'
    ]);
}

function pm_load_phpmailer(): bool {
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
        __DIR__ . '/../PHPMailer/src/SMTP.php',
        __DIR__ . '/../vendor/PHPMailer/PHPMailer/src/Exception.php',
        __DIR__ . '/../vendor/PHPMailer/PHPMailer/src/PHPMailer.php',
        __DIR__ . '/../vendor/PHPMailer/PHPMailer/src/SMTP.php'
    ];

    if (file_exists($manualFiles[0]) && file_exists($manualFiles[1]) && file_exists($manualFiles[2])) {
        require_once $manualFiles[0];
        require_once $manualFiles[1];
        require_once $manualFiles[2];
    } elseif (file_exists($manualFiles[3]) && file_exists($manualFiles[4]) && file_exists($manualFiles[5])) {
        require_once $manualFiles[3];
        require_once $manualFiles[4];
        require_once $manualFiles[5];
    }

    return class_exists('\PHPMailer\PHPMailer\PHPMailer');
}

function pm_resident_portal_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/apartment/public/admin_parking_requests.php';
    $publicDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    return $scheme . '://' . $host . $publicDir . '/resident_dashboard.php';
}

function pm_send_parking_approval_email(
    array $request,
    array $slot,
    float $monthlyFee,
    string $apartmentName,
    ?string &$mailError = null
): bool {
    $mailError = null;

    $toEmail = trim((string)($request['resident_email'] ?? ''));
    $residentName = trim((string)($request['resident_name'] ?? 'Resident'));

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

    if (!pm_load_phpmailer()) {
        $mailError = 'PHPMailer is not installed.';
        return false;
    }

    $slotText = trim((string)(($slot['block_name'] ?? '-') . ' / ' . ($slot['slot_no'] ?? '-')));
    $unitText = pm_unit_text($request);
    $vehicleText = trim((string)(($request['vehicle_model'] ?? '') . ($request['vehicle_color'] ? ' · ' . $request['vehicle_color'] : '')));
    if ($vehicleText === '') {
        $vehicleText = 'Resident Vehicle';
    }

    $plate = pm_text($request['plate_no'] ?? '-');
    $startDateText = date('d M Y');
    $amountText = 'RM ' . number_format($monthlyFee, 2);
    $portalUrl = pm_resident_portal_url();

    $safeResidentName = htmlspecialchars($residentName ?: 'Resident', ENT_QUOTES, 'UTF-8');
    $safeApartment = htmlspecialchars($apartmentName, ENT_QUOTES, 'UTF-8');
    $safeUnit = htmlspecialchars($unitText, ENT_QUOTES, 'UTF-8');
    $safeVehicle = htmlspecialchars($vehicleText, ENT_QUOTES, 'UTF-8');
    $safePlate = htmlspecialchars($plate, ENT_QUOTES, 'UTF-8');
    $safeSlot = htmlspecialchars($slotText, ENT_QUOTES, 'UTF-8');
    $safeStartDate = htmlspecialchars($startDateText, ENT_QUOTES, 'UTF-8');
    $safeAmount = htmlspecialchars($amountText, ENT_QUOTES, 'UTF-8');
    $safePortalUrl = htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8');

    $subject = 'SmartVMS Parking Request Approved';

    $html = "
        <div style='margin:0;padding:0;background:#f4f6fb;font-family:Arial,sans-serif;color:#111827;'>
            <div style='max-width:640px;margin:0 auto;padding:28px 16px;'>
                <div style='background:#ffffff;border:1px solid #e5e7eb;border-radius:18px;overflow:hidden;box-shadow:0 18px 40px rgba(15,23,42,.10);'>
                    <div style='background:linear-gradient(135deg,#dc2626,#991b1b);padding:24px;color:white;'>
                        <h1 style='margin:0;font-size:24px;line-height:1.2;'>Parking Request Approved</h1>
                        <p style='margin:8px 0 0;color:#fee2e2;font-size:14px;'>SmartVMS Resident Parking</p>
                    </div>

                    <div style='padding:24px;'>
                        <p style='margin:0 0 14px;font-size:15px;'>Hello <strong>{$safeResidentName}</strong>,</p>
                        <p style='margin:0 0 18px;font-size:15px;line-height:1.6;'>
                            Your resident parking request has been approved. Your selected parking slot is now confirmed and you may start using it from <strong>{$safeStartDate}</strong>.
                        </p>

                        <table style='width:100%;border-collapse:collapse;margin:18px 0;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;'>
                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;width:38%;'>Apartment</td><td style='padding:12px;'>{$safeApartment}</td></tr>
                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Unit</td><td style='padding:12px;'>{$safeUnit}</td></tr>
                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Vehicle</td><td style='padding:12px;'>{$safeVehicle}</td></tr>
                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Plate Number</td><td style='padding:12px;'>{$safePlate}</td></tr>
                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Parking Slot</td><td style='padding:12px;'>{$safeSlot}</td></tr>
                            <tr><td style='padding:12px;background:#f8fafc;font-weight:bold;'>Monthly Fee</td><td style='padding:12px;color:#dc2626;font-weight:bold;'>{$safeAmount} / month</td></tr>
                        </table>

                        <div style='margin:20px 0;padding:14px;border-radius:12px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;line-height:1.55;'>
                            Please keep your monthly parking payment up to date to continue resident parking access.
                        </div>

                        <p style='margin:18px 0 0;color:#64748b;font-size:13px;line-height:1.6;'>
                            Login to SmartVMS here:<br>
                            <a href='{$safePortalUrl}' style='color:#dc2626;'>{$safePortalUrl}</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    ";

    $plainText =
        "SmartVMS Parking Request Approved\n\n" .
        "Hello " . ($residentName ?: 'Resident') . ",\n\n" .
        "Your resident parking request has been approved. Your selected parking slot is now confirmed and you may start using it from {$startDateText}.\n\n" .
        "Apartment: {$apartmentName}\n" .
        "Unit: {$unitText}\n" .
        "Vehicle: {$vehicleText}\n" .
        "Plate Number: {$plate}\n" .
        "Parking Slot: {$slotText}\n" .
        "Monthly Fee: {$amountText} / month\n\n" .
        "Please keep your monthly parking payment up to date to continue resident parking access.\n\n" .
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

$currentApartmentName = pm_current_apartment_name($pdo);
$currentInitial = strtoupper(substr((string)($_SESSION['name'] ?? $_SESSION['email'] ?? 'A'), 0, 1));
?>

<?php
$ready = pm_has_table($pdo,'resident_parking_requests') && pm_has_table($pdo,'resident_parking_assignments') && pm_has_table($pdo,'parking_slots') && pm_has_table($pdo,'parking_payments');
$requestSlotColumn = $ready ? pm_request_slot_column($pdo) : null;
$currentBillingMonth = date('Y-m');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        pm_require_post();
        if (!$ready) throw new Exception('Parking request tables are not ready.');
        $action = $_POST['action'] ?? '';
        if ($action === 'approve_request') {
            $requestId = (int)($_POST['request_id'] ?? 0);
            $remark = trim($_POST['admin_remark'] ?? '');

            if ($requestId <= 0) {
                throw new Exception('Please select a request.');
            }

            if (!$requestSlotColumn) {
                throw new Exception('Resident-selected parking slot column was not found in resident_parking_requests.');
            }

            $request = pm_one($pdo, "SELECT rpr.*, u.full_name AS resident_name, u.email AS resident_email, u.contact_number, rv.plate_no, rv.vehicle_model, rv.vehicle_color, rv.status AS vehicle_status, un.block_no, un.floor_no, un.unit_no FROM resident_parking_requests rpr JOIN users u ON u.id = rpr.resident_id JOIN resident_vehicles rv ON rv.id = rpr.vehicle_id LEFT JOIN resident_units ru ON ru.resident_id = u.id AND ru.status = 'active' LEFT JOIN units un ON un.id = ru.unit_id WHERE rpr.id = ? AND rpr.status = 'pending' LIMIT 1", [$requestId]);
            if (!$request) throw new Exception('Pending request not found.');
            if ($request['vehicle_status'] !== 'active') throw new Exception('The selected vehicle is inactive.');

            $slotId = (int)($request[$requestSlotColumn] ?? 0);
            if ($slotId <= 0) {
                throw new Exception('This request does not have a resident-selected parking slot.');
            }

            $slot = pm_one($pdo, "SELECT * FROM parking_slots WHERE id = ? AND slot_type = 'Resident' AND status = 'available' LIMIT 1", [$slotId]);
            if (!$slot) throw new Exception('The resident-selected parking slot is not available anymore.');
            if (pm_count($pdo, "SELECT COUNT(*) FROM resident_parking_assignments WHERE vehicle_id = ? AND status='active'", [(int)$request['vehicle_id']]) > 0) throw new Exception('This vehicle already has an active parking slot.');
            $activeCount = pm_count($pdo, "SELECT COUNT(*) FROM resident_parking_assignments WHERE resident_id = ? AND status='active'", [(int)$request['resident_id']]);
            if ($activeCount >= 2) throw new Exception('This resident already has 2 active parking slots.');
            $monthlyFee = 80.00;
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO resident_parking_assignments (resident_id, vehicle_id, slot_id, request_id, monthly_fee, start_date, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, CURDATE(), 'active', ?, NOW())");
            $stmt->execute([(int)$request['resident_id'], (int)$request['vehicle_id'], $slotId, $requestId, $monthlyFee, $adminId]);
            $assignmentId = (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare("UPDATE resident_parking_requests SET status='approved', admin_id=?, admin_remark=?, reviewed_at=NOW() WHERE id=?");
            $stmt->execute([$adminId, $remark !== '' ? $remark : null, $requestId]);
            $stmt = $pdo->prepare("UPDATE parking_slots SET status='reserved', updated_at=NOW() WHERE id=?");
            $stmt->execute([$slotId]);
            $stmt = $pdo->prepare("INSERT INTO parking_payments (assignment_id, resident_id, billing_month, amount, payment_status, created_at, vehicle_id) VALUES (?, ?, ?, ?, 'unpaid', NOW(), ?) ON DUPLICATE KEY UPDATE amount=VALUES(amount), vehicle_id=VALUES(vehicle_id)");
            $stmt->execute([$assignmentId, (int)$request['resident_id'], $currentBillingMonth, $monthlyFee, (int)$request['vehicle_id']]);
            $pdo->commit();

            $emailError = null;
            $emailSent = pm_send_parking_approval_email(
                $request,
                $slot,
                (float)$monthlyFee,
                $currentApartmentName,
                $emailError
            );

            if (function_exists('create_notification')) {
                create_notification(
                    $pdo,
                    (int)$request['resident_id'],
                    'Parking Request Approved',
                    'Your parking request has been approved. Slot: '.$slot['slot_no'].'. Monthly fee: RM'.number_format($monthlyFee,2).' per month.',
                    'success'
                );
            }

            if (function_exists('log_audit')) {
                log_audit(
                    'ADMIN_PARKING_REQUEST_APPROVED',
                    'Approved request #' . $requestId . ' slot ' . $slot['slot_no'] . ($emailSent ? ' and email sent.' : ' but email not sent.')
                );
            }

            if ($emailSent) {
                pm_set_flash('success','Parking request approved and approval email sent to resident.');
            } else {
                pm_set_flash('success','Parking request approved, but email was not sent. '.($emailError ?: 'Please check SMTP settings.'));
            }

            pm_redirect('admin_parking_requests.php');
        }
        if ($action === 'reject_request') {
            $requestId = (int)($_POST['request_id'] ?? 0);
            $remark = trim($_POST['admin_remark'] ?? '');
            if ($requestId <= 0) throw new Exception('Invalid request selected.');
            $request = pm_one($pdo, "SELECT * FROM resident_parking_requests WHERE id=? AND status='pending' LIMIT 1", [$requestId]);
            if (!$request) throw new Exception('Pending request not found.');
            $stmt = $pdo->prepare("UPDATE resident_parking_requests SET status='rejected', admin_id=?, admin_remark=?, reviewed_at=NOW() WHERE id=?");
            $stmt->execute([$adminId, $remark !== '' ? $remark : 'Rejected by admin.', $requestId]);
            if (function_exists('create_notification')) create_notification($pdo, (int)$request['resident_id'], 'Parking Request Rejected', 'Your parking request was rejected. Remark: '.($remark ?: 'Rejected by admin.'), 'warning');
            pm_set_flash('success','Parking request rejected.');
            pm_redirect('admin_parking_requests.php');
        }
    } catch (Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); pm_set_flash('error',$e->getMessage()); pm_redirect('admin_parking_requests.php'); }
}
$q=trim($_GET['q'] ?? '');
$status=$_GET['status'] ?? 'pending';
$requestType=$_GET['request_type'] ?? '';
$where=[];
$params=[];

$selectedSlotSelect = ", NULL AS selected_slot_block_name, NULL AS selected_slot_no, NULL AS selected_slot_status";
$selectedSlotJoin = "";
if ($requestSlotColumn) {
    $selectedSlotSelect = ", sreq.block_name AS selected_slot_block_name, sreq.slot_no AS selected_slot_no, sreq.status AS selected_slot_status";
    $selectedSlotJoin = " LEFT JOIN parking_slots sreq ON sreq.id = rpr.`{$requestSlotColumn}`";
}

if($q!==''){
    $searchSql = "(u.full_name LIKE ? OR u.email LIKE ? OR rv.plate_no LIKE ? OR rpr.reason LIKE ? OR un.unit_no LIKE ? OR rpr.preferred_block LIKE ?";
    $searchCount = 6;

    if ($requestSlotColumn) {
        $searchSql .= " OR sreq.slot_no LIKE ? OR sreq.block_name LIKE ?";
        $searchCount += 2;
    }

    $searchSql .= ")";
    $where[] = $searchSql;

    for($i=0;$i<$searchCount;$i++) {
        $params[]='%'.$q.'%';
    }
}

if(in_array($status,['pending','approved','rejected'],true)){
    $where[]='rpr.status=?';
    $params[]=$status;
}

if($requestType==='additional_slot'){
    $where[]="rpr.request_type='additional_slot'";
} elseif($requestType==='new_slot'){
    $where[]="(rpr.request_type IS NULL OR rpr.request_type='' OR rpr.request_type<>'additional_slot')";
}

$sqlWhere=$where?'WHERE '.implode(' AND ',$where):'';
$requests=[];
$availableSlots=[];

if($ready){
    $requests=pm_rows($pdo,"
        SELECT
            rpr.*,
            u.full_name AS resident_name,
            u.email AS resident_email,
            u.contact_number,
            rv.plate_no,
            rv.vehicle_model,
            rv.vehicle_color,
            un.block_no,
            un.floor_no,
            un.unit_no,
            a.full_name AS admin_name,
            rpa.monthly_fee AS assigned_monthly_fee,
            rpa.status AS assignment_status,
            aps.block_name AS assigned_block_name,
            aps.slot_no AS assigned_slot_no
            {$selectedSlotSelect}
        FROM resident_parking_requests rpr
        JOIN users u ON u.id=rpr.resident_id
        JOIN resident_vehicles rv ON rv.id=rpr.vehicle_id
        LEFT JOIN resident_units ru ON ru.resident_id=u.id AND ru.status='active'
        LEFT JOIN units un ON un.id=ru.unit_id
        {$selectedSlotJoin}
        LEFT JOIN resident_parking_assignments rpa ON rpa.request_id=rpr.id
        LEFT JOIN parking_slots aps ON aps.id=rpa.slot_id
        LEFT JOIN users a ON a.id=rpr.admin_id
        {$sqlWhere}
        ORDER BY FIELD(rpr.status,'pending','approved','rejected'), rpr.requested_at DESC
        LIMIT 800
    ",$params);

    $availableSlots=pm_rows($pdo,"SELECT * FROM parking_slots WHERE slot_type='Resident' AND status='available' ORDER BY block_name, slot_no");
}
$pendingCount=pm_count($pdo,"SELECT COUNT(*) FROM resident_parking_requests WHERE status='pending'");
$approvedCount=pm_count($pdo,"SELECT COUNT(*) FROM resident_parking_requests WHERE status='approved'");
$rejectedCount=pm_count($pdo,"SELECT COUNT(*) FROM resident_parking_requests WHERE status='rejected'");
$availableCount=count($availableSlots);
?>
<!DOCTYPE html><html lang="en"><head><title>Parking Requests - <?= e(APP_NAME) ?></title>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --primary:#dc2626; --primary-dark:#b91c1c; --primary-soft:#fee2e2;
            --blue:#2563eb; --blue-soft:#dbeafe; --green:#16a34a; --green-soft:#dcfce7;
            --yellow:#d97706; --yellow-soft:#fef3c7; --red:#dc2626; --red-soft:#fee2e2;
            --text:#0f172a; --muted:#64748b; --soft:#94a3b8; --border:#e5e7eb;
            --bg:#f8fafc; --shadow:0 18px 45px rgba(15,23,42,.08); --shadow-soft:0 12px 26px rgba(15,23,42,.06);
        }
        *{box-sizing:border-box;margin:0;padding:0;font-family:'Plus Jakarta Sans',sans-serif}
        html,body{height:100%;overflow:hidden;background:linear-gradient(135deg,#f8fafc 0%,#f3f4f6 45%,#fff1f2 100%);color:var(--text)}
        a{text-decoration:none;color:inherit}.dashboard-shell{height:100vh;display:grid;grid-template-columns:260px 1fr}.sidebar{background:rgba(255,255,255,.94);border-right:1px solid var(--border);padding:24px 16px;overflow-y:auto}.brand{display:flex;gap:12px;align-items:center;margin-bottom:28px}.brand-icon{width:44px;height:44px;border-radius:18px;background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:white;display:grid;place-items:center;box-shadow:0 16px 30px rgba(220,38,38,.22)}.brand-title{font-size:1.08rem;font-weight:950;letter-spacing:-.04em}.brand-title span{color:var(--primary)}.brand-sub{color:var(--muted);font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.08em}.tenant-card{display:flex;gap:12px;align-items:center;border:1px solid #fecaca;background:#fff7f7;border-radius:20px;padding:14px;margin-bottom:22px}.tenant-icon{width:24px;height:24px;border-radius:14px;background:#fee2e2;color:var(--primary);display:grid;place-items:center}.tenant-label{font-size:.68rem;color:var(--muted);font-weight:950;text-transform:uppercase;letter-spacing:.08em}.tenant-name{font-size:.86rem;font-weight:950}.side-section{font-size:.66rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.08em;font-weight:950;margin:18px 8px 8px}.side-nav{display:grid;gap:6px}.side-link{width:100%;border:0;background:transparent;display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:14px;color:#475569;font-weight:900;font-size:.78rem;cursor:pointer;text-align:left}.side-link i{width:18px;text-align:center;color:#94a3b8}.side-link:hover,.side-link.current{background:#fff1f2;color:var(--primary)}.side-link:hover i,.side-link.current i{color:var(--primary)}.side-link.logout{color:#991b1b;background:#fff1f2;margin-top:6px}.side-parent .parent{justify-content:space-between}.side-parent .left{display:flex;align-items:center;gap:12px}.chevron{transition:.2s}.side-parent.open .chevron{transform:rotate(180deg)}.submenu{display:none;margin:3px 0 8px 30px;padding-left:12px;border-left:1px solid #fecaca}.side-parent.open .submenu{display:grid;gap:5px}.submenu a{font-size:.8rem;font-weight:900;color:#64748b;padding:9px 10px;border-radius:12px}.submenu a:hover,.submenu a.sub-active{background:#fff1f2;color:var(--primary)}
        .main{min-width:0;height:100vh;overflow:hidden;padding:18px 30px 18px;display:flex;flex-direction:column}.topbar{min-height:58px;display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:12px;flex:0 0 auto}.page-kicker{color:var(--primary);font-size:.72rem;font-weight:900;text-transform:uppercase;letter-spacing:.1em;margin-bottom:6px}.page-title{font-size:1.65rem;line-height:1.05;font-weight:950;letter-spacing:-.06em}.page-sub{color:var(--muted);margin-top:5px;font-size:.82rem;font-weight:750;line-height:1.35;max-width:760px}.top-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap;min-width:180px}.top-btn{height:44px;border:0;border-radius:16px;background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:white;padding:0 16px;font-weight:950;font-size:.82rem;display:inline-flex;gap:8px;align-items:center;box-shadow:0 14px 24px rgba(220,38,38,.18);cursor:pointer}.admin-bubble{width:42px;height:42px;border-radius:999px;background:var(--primary);color:white;display:grid;place-items:center;font-weight:950;font-size:.9rem;box-shadow:0 14px 24px rgba(220,38,38,.16)}.request-hero{position:relative;display:grid;grid-template-columns:minmax(0,1.05fr) minmax(280px,.8fr);gap:14px;min-height:142px;margin-bottom:12px;padding:14px 20px;border-radius:24px;border:1px solid rgba(229,231,235,.95);background:radial-gradient(circle at 18% 12%,rgba(220,38,38,.12),transparent 34%),radial-gradient(circle at 84% 48%,rgba(254,202,202,.45),transparent 30%),linear-gradient(135deg,rgba(255,255,255,.98),rgba(255,247,247,.9));box-shadow:var(--shadow);overflow:hidden;flex:0 0 auto}.request-hero::before{content:"";position:absolute;width:220px;height:220px;border-radius:50%;left:-85px;top:-90px;background:rgba(220,38,38,.09)}.request-hero::after{content:"";position:absolute;right:76px;top:48px;width:170px;height:170px;border-radius:999px;background:rgba(254,226,226,.52);z-index:0}.request-hero-info,.request-illustration{position:relative;z-index:2}.request-hero-info{display:flex;flex-direction:column;justify-content:center;min-width:0}.hero-kicker{color:var(--primary);text-transform:uppercase;letter-spacing:.13em;font-size:.66rem;font-weight:950;margin-bottom:6px}.hero-big{color:#06143a;font-size:clamp(2.6rem,4.2vw,3.9rem);line-height:.9;font-weight:950;letter-spacing:-.09em;margin-bottom:6px}.hero-subline{color:var(--primary);font-size:.82rem;font-weight:950;margin-bottom:10px}.hero-mini-stats{display:grid;grid-template-columns:repeat(3,minmax(110px,1fr));gap:8px;max-width:500px}.hero-mini-card{background:rgba(255,255,255,.86);border:1px solid #e5e7eb;border-radius:15px;padding:9px 12px;box-shadow:0 10px 22px rgba(15,23,42,.05)}.hero-mini-card strong{display:block;color:#0f172a;font-size:1.05rem;line-height:1;font-weight:950;margin-bottom:4px}.hero-mini-card strong.red{color:var(--primary)}.hero-mini-card strong.green{color:var(--green)}.hero-mini-card strong.blue{color:var(--blue)}.hero-mini-card span{display:block;color:#64748b;font-size:.58rem;text-transform:uppercase;letter-spacing:.07em;font-weight:950}.request-illustration{min-height:144px;border-radius:0;background:transparent;border:0;overflow:visible;display:flex;align-items:center;justify-content:center;padding:0 6px}.request-illustration::before{content:"";position:absolute;left:44px;right:44px;bottom:16px;height:12px;border-radius:999px;background:linear-gradient(90deg,transparent,rgba(15,23,42,.15),transparent);filter:blur(2px)}.clipboard-wrap{position:relative;width:min(420px,100%);height:136px;display:flex;align-items:center;justify-content:center}.clipboard{position:absolute;right:92px;top:7px;width:112px;height:124px;border-radius:16px;background:#fff;border:5px solid #1f2937;box-shadow:0 16px 32px rgba(15,23,42,.14)}.clip-top{position:absolute;left:50%;top:-17px;transform:translateX(-50%);width:68px;height:26px;border-radius:12px 12px 7px 7px;background:linear-gradient(145deg,#ef4444,#dc2626);box-shadow:0 10px 20px rgba(220,38,38,.18)}.clip-top::before{content:"";position:absolute;left:50%;top:6px;transform:translateX(-50%);width:11px;height:11px;border-radius:999px;background:#fff}.doc-p{position:absolute;left:12px;top:25px;width:31px;height:31px;border-radius:999px;background:linear-gradient(145deg,#ef4444,#dc2626);color:#fff;font-weight:950;font-size:1rem;display:grid;place-items:center}.doc-line{position:absolute;left:52px;right:12px;height:6px;border-radius:999px;background:#d1d5db}.doc-line.one{top:31px}.doc-line.two{top:45px;width:40px}.doc-user{position:absolute;left:12px;top:63px;width:31px;height:31px;border-radius:10px;background:#eef2f7;color:#94a3b8;display:grid;place-items:center;font-size:.95rem}.doc-line.three{top:67px}.doc-line.four{top:82px;width:48px}.doc-line.five{top:102px;left:12px;right:15px}.doc-line.six{top:117px;left:12px;right:28px}.request-ticket{position:absolute;left:88px;bottom:8px;width:132px;height:52px;border-radius:14px;background:linear-gradient(145deg,#fff,#fee2e2);border:1px solid #fecaca;box-shadow:0 14px 28px rgba(220,38,38,.16);display:grid;grid-template-columns:40px 1fr;align-items:center;padding:7px}.ticket-car{width:28px;height:28px;border-radius:11px;background:#fee2e2;color:var(--primary);display:grid;place-items:center;font-size:1rem}.ticket-title{font-size:.46rem;color:var(--primary);font-weight:950;letter-spacing:.05em;text-transform:uppercase}.ticket-line{height:5px;border-radius:999px;background:#fecaca;margin-top:5px;width:56px}.success-check{position:absolute;right:46px;bottom:5px;width:56px;height:56px;border-radius:999px;background:linear-gradient(145deg,#86efac,#22c55e);border:6px solid #fff;box-shadow:0 16px 30px rgba(34,197,94,.24);display:grid;place-items:center;color:white;font-size:1.55rem}.decor-square{position:absolute;right:28px;top:20px;width:36px;height:36px;border-radius:16px;background:rgba(254,226,226,.85);transform:rotate(10deg)}.decor-dots{position:absolute;right:92px;top:78px;width:48px;height:36px;background-image:radial-gradient(circle,#fecaca 2px,transparent 2px);background-size:16px 16px;opacity:.85}.content-card{background:rgba(255,255,255,.96);border:1px solid rgba(229,231,235,.95);border-radius:22px;box-shadow:var(--shadow);overflow:hidden;min-height:0;display:flex;flex-direction:column;flex:1}.card-head{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px}.card-title{font-size:1rem;font-weight:950;display:flex;gap:10px;align-items:center}.card-title i{color:var(--primary)}.filters{padding:13px 18px;border-bottom:1px solid var(--border);display:grid;grid-template-columns:minmax(260px,1fr) 160px 180px 38px 38px;gap:8px;align-items:end}.field label{display:block;font-size:.68rem;color:#64748b;font-weight:950;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px}.input,.select,textarea{width:100%;height:38px;border:1px solid var(--border);border-radius:12px;background:white;padding:0 12px;font-weight:850;outline:none;font-size:.8rem}.input:focus,.select:focus,textarea:focus{border-color:#fecaca;box-shadow:0 0 0 4px #fff1f2}textarea{height:88px;padding:12px;resize:vertical}.icon-btn{width:38px;height:38px;border-radius:12px;border:1px solid var(--border);background:white;color:#0f172a;display:grid;place-items:center;cursor:pointer;font-weight:950}.icon-btn.red{background:var(--primary);border-color:var(--primary);color:white;box-shadow:0 12px 22px rgba(220,38,38,.18)}.icon-btn.soft-red{background:#fee2e2;border-color:#fecaca;color:#991b1b}.icon-btn.green{background:#dcfce7;border-color:#bbf7d0;color:#166534}.body-scroll{overflow:auto;min-height:0;flex:1}.table{width:100%;border-collapse:collapse}.request-table{min-width:1250px}.table th{position:sticky;top:0;background:#f8fafc;text-align:left;padding:11px 14px;font-size:.68rem;color:#64748b;text-transform:uppercase;letter-spacing:.06em;font-weight:950;border-bottom:1px solid var(--border);z-index:2}.table td{padding:12px 14px;border-bottom:1px solid #eef2f7;font-size:.82rem;font-weight:820;vertical-align:top}.table tr:hover td{background:#fffafa}.muted{color:#64748b;font-weight:800;font-size:.76rem;margin-top:3px;line-height:1.35}.plate{display:inline-flex;background:#111827;color:white;border:2px solid #334155;border-radius:10px;padding:5px 9px;font-family:monospace;font-weight:950;letter-spacing:.07em;font-size:.76rem}.badge{display:inline-flex;width:fit-content;border-radius:999px;padding:6px 10px;font-size:.66rem;text-transform:uppercase;letter-spacing:.04em;font-weight:950}.badge-green{background:#dcfce7;color:#166534}.badge-yellow{background:#fef3c7;color:#92400e}.badge-red{background:#fee2e2;color:#991b1b}.badge-gray{background:#f1f5f9;color:#475569}.row-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.inline-form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.inline-form .select,.inline-form .input{height:38px;min-width:145px}.split-layout{display:grid;grid-template-columns:minmax(650px,1fr) 380px;gap:16px;min-height:0;flex:1}.side-card{background:rgba(255,255,255,.96);border:1px solid rgba(229,231,235,.95);border-radius:22px;box-shadow:var(--shadow);overflow:hidden;display:flex;flex-direction:column;min-height:0}.form-stack{padding:16px;display:grid;gap:12px;overflow:auto}.form-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}.notice{padding:12px 14px;border-radius:14px;font-size:.84rem;font-weight:850;margin-bottom:12px}.notice.success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}.notice.error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}.empty{padding:42px;text-align:center;color:#64748b;font-weight:900}.footer-note{padding:12px 18px;border-top:1px solid var(--border);color:#64748b;font-weight:850;font-size:.76rem}.danger-text{color:#991b1b}.nowrap{white-space:nowrap}.small-link{font-size:.75rem;color:var(--primary);font-weight:950}.modal{display:none}.modal.open{display:block}
        @media(max-width:1300px){.request-hero{grid-template-columns:1fr;padding:24px}.request-illustration{min-height:170px}.hero-mini-stats{grid-template-columns:repeat(3,1fr)}.split-layout{grid-template-columns:1fr}.filters{grid-template-columns:1fr 1fr}.sidebar{display:none}.dashboard-shell{grid-template-columns:1fr}.main{overflow:auto}}@media(max-width:760px){.request-hero{padding:20px}.hero-mini-stats{grid-template-columns:1fr}.hero-big{font-size:2.55rem}.clipboard-wrap{transform:scale(.72);transform-origin:center}}
    </style>

</head><body data-success="<?= e($message) ?>" data-error="<?= e($error) ?>">

<div class="dashboard-shell">
    <?php require_once __DIR__ . '/admin_sidebar.php'; ?>

<main class="main"><div class="topbar"><div><div class="page-kicker">Parking Management</div><h1 class="page-title">Parking Requests</h1><p class="page-sub">Review resident parking applications, assign available resident slots, approve or reject requests.</p></div><div class="top-actions"><?php if (($_GET['from'] ?? '') === 'resident_vehicles'): ?><a class="top-btn" href="admin_resident_vehicles.php"><i class="fas fa-arrow-left"></i>Back</a><?php endif; ?><a class="top-btn" href="admin_dashboard.php"><i class="fas fa-table-columns"></i>Dashboard</a><div class="admin-bubble"><?= e($currentInitial) ?></div></div></div>
<section class="request-hero">
    <div class="request-hero-info">
        <div class="hero-kicker">Request Applications</div>
        <div class="hero-big"><?= (int)$pendingCount ?></div>
        <div class="hero-subline">Pending parking requests in this apartment.</div>

        <div class="hero-mini-stats">
            <div class="hero-mini-card">
                <strong class="red"><?= (int)$pendingCount ?></strong>
                <span>Pending</span>
            </div>
            <div class="hero-mini-card">
                <strong class="green"><?= (int)$approvedCount ?></strong>
                <span>Approved</span>
            </div>
            <div class="hero-mini-card">
                <strong class="blue"><?= (int)$availableCount ?></strong>
                <span>Available Slots</span>
            </div>
        </div>
    </div>

    <div class="request-illustration" aria-hidden="true">
        <div class="clipboard-wrap">
            <div class="decor-square"></div>
            <div class="decor-dots"></div>

            <div class="clipboard">
                <div class="clip-top"></div>
                <div class="doc-p">P</div>
                <div class="doc-line one"></div>
                <div class="doc-line two"></div>
                <div class="doc-user"><i class="fas fa-user"></i></div>
                <div class="doc-line three"></div>
                <div class="doc-line four"></div>
                <div class="doc-line five"></div>
                <div class="doc-line six"></div>
            </div>

            <div class="request-ticket">
                <div class="ticket-car"><i class="fas fa-car"></i></div>
                <div>
                    <div class="ticket-title">Parking Request</div>
                    <div class="ticket-line"></div>
                    <div class="ticket-line" style="width:60px;"></div>
                </div>
            </div>

            <div class="success-check">
                <i class="fas fa-check"></i>
            </div>
        </div>
    </div>
</section>
<section class="content-card"><div class="card-head"><div class="card-title"><i class="fas fa-clipboard-list"></i>Parking Requests</div></div><form class="filters" method="GET"><?php if (($_GET['from'] ?? '') === 'resident_vehicles'): ?><input type="hidden" name="from" value="resident_vehicles"><?php endif; ?><div class="field"><label>Search</label><input class="input" name="q" value="<?= e($q) ?>" placeholder="Search resident, plate, unit or reason..."></div><div class="field"><label>Status</label><select class="select" name="status"><option value="">All Status</option><?php foreach(['pending','approved','rejected'] as $opt): ?><option value="<?= $opt ?>" <?= $status===$opt?'selected':'' ?>><?= ucfirst($opt) ?></option><?php endforeach; ?></select></div><div class="field"><label>Request Type</label><select class="select" name="request_type"><option value="" <?= $requestType===''?'selected':'' ?>>All Type</option><option value="new_slot" <?= $requestType==='new_slot'?'selected':'' ?>>New Parking Slot</option><option value="additional_slot" <?= $requestType==='additional_slot'?'selected':'' ?>>Additional Slot</option></select></div><button class="icon-btn red"><i class="fas fa-search"></i></button><a class="icon-btn" href="admin_parking_requests.php<?= (($_GET['from'] ?? '') === 'resident_vehicles') ? '?from=resident_vehicles' : '' ?>"><i class="fas fa-rotate-left"></i></a></form><div class="body-scroll"><table class="table request-table"><thead><tr><th>Resident</th><th>Unit</th><th>Vehicle Info</th><th>Parking Position</th><th>Request Type</th><th>Status</th><th>Action</th></tr></thead><tbody><?php if(!$requests): ?><tr><td colspan="7" class="empty">No parking request found.</td></tr><?php endif; ?><?php foreach($requests as $r): ?><?php
$assignedPosition=trim((string)(($r['assigned_block_name'] ?? '').' / '.($r['assigned_slot_no'] ?? '')));
if($assignedPosition==='/' || $assignedPosition===''){$assignedPosition='-';}

$selectedPosition=trim((string)(($r['selected_slot_block_name'] ?? '').' / '.($r['selected_slot_no'] ?? '')));
if($selectedPosition==='/' || $selectedPosition===''){$selectedPosition='-';}

$positionText = $assignedPosition !== '-' ? $assignedPosition : ($selectedPosition !== '-' ? $selectedPosition : 'No slot selected');
$positionSource = $assignedPosition !== '-' ? 'Approved slot' : ($selectedPosition !== '-' ? 'Resident selected slot' : 'Preferred: '.pm_text($r['preferred_block']));
$requestTypeLabel=((string)($r['request_type'] ?? '')==='additional_slot')?'Additional Slot':'New Parking Slot';
?><tr><td><strong><?= e($r['resident_name'] ?: $r['resident_email']) ?></strong><div class="muted"><?= e($r['resident_email']) ?></div><div class="muted"><?= e(pm_text($r['contact_number'])) ?></div></td><td><strong><?= e(pm_unit_text($r)) ?></strong></td><td><span class="plate"><?= e($r['plate_no']) ?></span><div class="muted"><?= e(pm_text($r['vehicle_model'])) ?><?= $r['vehicle_color'] ? ' · '.e($r['vehicle_color']) : '' ?></div></td><td><strong><?= e($positionText) ?></strong><div class="muted"><?= e($positionSource) ?></div><?php if(!empty($r['selected_slot_status']) && $assignedPosition === '-'): ?><div class="muted">Slot status: <?= e($r['selected_slot_status']) ?></div><?php endif; ?><?php if(!empty($r['assigned_monthly_fee'])): ?><div class="muted">Fee: <?= e(pm_money($r['assigned_monthly_fee'])) ?></div><?php endif; ?></td><td><strong><?= e($requestTypeLabel) ?></strong><div class="muted">Requested <?= pm_date($r['requested_at'], 'd M Y, g:i A') ?></div><div class="muted"><?= e(pm_text($r['reason'])) ?></div></td><td><span class="badge <?= pm_status_badge($r['status']) ?>"><?= e($r['status']) ?></span><?php if($r['reviewed_at']): ?><div class="muted">Reviewed <?= pm_date($r['reviewed_at'], 'd M Y, g:i A') ?></div><?php endif; ?><div class="muted"><?= e(pm_text($r['admin_remark'])) ?></div></td><td><?php if($r['status']==='pending'): ?><form method="POST" class="inline-form" data-confirm="Approve resident-selected slot?" data-text="This will approve the parking slot selected by the resident and create an unpaid invoice."><?= csrf_field() ?><input type="hidden" name="action" value="approve_request"><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>"><input class="input" name="admin_remark" placeholder="Remark optional"><button class="icon-btn green" title="Approve"><i class="fas fa-check"></i></button></form><form method="POST" class="inline-form" style="margin-top:6px" data-confirm="Reject parking request?" data-text="Resident will receive rejection notification."><?= csrf_field() ?><input type="hidden" name="action" value="reject_request"><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>"><input class="input" name="admin_remark" placeholder="Reason"><button class="icon-btn soft-red" title="Reject"><i class="fas fa-xmark"></i></button></form><?php else: ?><span class="muted">No action required</span><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><div class="footer-note">Approving a request confirms the parking slot selected by the resident and creates an unpaid invoice.</div></section></main></div>

<script>
    document.querySelectorAll('.side-link.parent').forEach(btn => {
        btn.addEventListener('click', () => btn.closest('.side-parent').classList.toggle('open'));
    });
    document.querySelectorAll('[data-confirm]').forEach(form => {
        form.addEventListener('submit', function(e){
            e.preventDefault();
            const title = this.dataset.confirm || 'Confirm action?';
            const text = this.dataset.text || 'Please confirm before continuing.';
            Swal.fire({title, text, icon:'question', showCancelButton:true, confirmButtonColor:'#dc2626', confirmButtonText:'Yes, continue'}).then(res => { if(res.isConfirmed) this.submit(); });
        });
    });
    const msg = document.body.dataset.success;
    const err = document.body.dataset.error;
    if(msg){ Swal.fire({icon:'success', title:'Success', text:msg, timer:1900, showConfirmButton:false}); }
    if(err){ Swal.fire({icon:'error', title:'Error', text:err}); }
</script>
</body>
</html>
