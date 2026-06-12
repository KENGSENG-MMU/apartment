<?php
require_once '../core/security.php';

require_login(['admin', 'superadmin']);

$pdo = db();

$message = '';
$error = '';

function has_column_add_resident(PDO $pdo, string $table, string $column): bool {
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

function safe_count_add_resident(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function safe_rows_add_resident(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function safe_text($value) {
    return $value !== null && $value !== '' ? $value : '-';
}

function smartvms_public_url(string $path): string {
    $isHttps =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);

    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

    return $scheme . '://' . $host . $base . '/' . ltrim($path, '/');
}

function send_resident_welcome_email(
    string $toEmail,
    string $residentName,
    string $temporaryPassword,
    string $apartmentName,
    string $unitLabel,
    string $contactNumber,
    string $residentType,
    string $stayStartDate,
    string $stayEndDate
): bool {
    if (!function_exists('mail')) {
        return false;
    }

    $loginUrl = smartvms_public_url('r_landingpage.php');
    $safeName = htmlspecialchars($residentName !== '' ? $residentName : 'Resident', ENT_QUOTES, 'UTF-8');
    $safeApartment = htmlspecialchars($apartmentName, ENT_QUOTES, 'UTF-8');
    $safeUnit = htmlspecialchars($unitLabel, ENT_QUOTES, 'UTF-8');
    $safeEmail = htmlspecialchars($toEmail, ENT_QUOTES, 'UTF-8');
    $safePassword = htmlspecialchars($temporaryPassword, ENT_QUOTES, 'UTF-8');
    $safeContact = htmlspecialchars($contactNumber !== '' ? $contactNumber : '-', ENT_QUOTES, 'UTF-8');
    $safeResidentType = htmlspecialchars($residentType !== '' ? $residentType : '-', ENT_QUOTES, 'UTF-8');
    $safeStayStart = htmlspecialchars($stayStartDate !== '' ? $stayStartDate : '-', ENT_QUOTES, 'UTF-8');
    $safeStayEnd = htmlspecialchars($stayEndDate !== '' ? $stayEndDate : 'No end date / Owner', ENT_QUOTES, 'UTF-8');
    $safeLoginUrl = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');

    $subject = 'Your SmartVMS Resident Account Has Been Created';

    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>SmartVMS Resident Account</title>
    </head>
    <body style="margin:0;padding:0;background:#f8fafc;font-family:Arial,Helvetica,sans-serif;color:#111827;">
        <div style="max-width:680px;margin:0 auto;padding:28px 18px;">
            <div style="background:#ffffff;border:1px solid #e5e7eb;border-radius:18px;overflow:hidden;">
                <div style="background:linear-gradient(135deg,#dc2626,#991b1b);padding:24px;color:#ffffff;">
                    <h1 style="margin:0;font-size:24px;">Welcome to SmartVMS</h1>
                    <p style="margin:8px 0 0;font-size:14px;opacity:.9;">Your resident account has been successfully created.</p>
                </div>

                <div style="padding:24px;">
                    <p style="font-size:15px;line-height:1.6;margin:0 0 16px;">
                        Hi <strong>' . $safeName . '</strong>,
                    </p>

                    <p style="font-size:15px;line-height:1.6;margin:0 0 18px;">
                        Your apartment admin has verified your resident information and created your SmartVMS resident account.
                    </p>

                    <div style="background:#fff7f7;border:1px solid #fecaca;border-radius:14px;padding:16px;margin-bottom:20px;">
                        <h2 style="font-size:16px;margin:0 0 12px;color:#991b1b;">Account Details</h2>
                        <p style="margin:6px 0;font-size:14px;"><strong>Apartment:</strong> ' . $safeApartment . '</p>
                        <p style="margin:6px 0;font-size:14px;"><strong>Unit:</strong> ' . $safeUnit . '</p>
                        <p style="margin:6px 0;font-size:14px;"><strong>Email:</strong> ' . $safeEmail . '</p>
                        <p style="margin:6px 0;font-size:14px;"><strong>Contact:</strong> ' . $safeContact . '</p>
                        <p style="margin:6px 0;font-size:14px;"><strong>Resident Type:</strong> ' . $safeResidentType . '</p>
                        <p style="margin:6px 0;font-size:14px;"><strong>Stay Start Date:</strong> ' . $safeStayStart . '</p>
                        <p style="margin:6px 0;font-size:14px;"><strong>Stay End Date:</strong> ' . $safeStayEnd . '</p>
                        <p style="margin:6px 0;font-size:14px;"><strong>Temporary Password:</strong> ' . $safePassword . '</p>
                    </div>

                    <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:14px;padding:16px;margin-bottom:20px;">
                        <h2 style="font-size:16px;margin:0 0 12px;">Simple User Manual</h2>
                        <ol style="padding-left:20px;margin:0;font-size:14px;line-height:1.7;">
                            <li>Open the User Portal: <a href="' . $safeLoginUrl . '" style="color:#dc2626;font-weight:bold;">' . $safeLoginUrl . '</a></li>
                            <li>Login using your registered email and temporary password.</li>
                            <li>Check your resident dashboard after login.</li>
                            <li>Use the system to manage visitor requests and view visitor pass details.</li>
                            <li>Add or update your resident vehicle information if required by your apartment admin.</li>
                            <li>Keep your account details private and do not share your password with others.</li>
                        </ol>
                    </div>

                    <p style="font-size:13px;line-height:1.6;color:#64748b;margin:0;">
                        If any information is incorrect, please contact your apartment admin.
                    </p>
                </div>
            </div>

            <p style="text-align:center;color:#94a3b8;font-size:12px;margin-top:18px;">
                This is an automated email from SmartVMS.
            </p>
        </div>
    </body>
    </html>';

    $fromDomain = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $fromDomain = preg_replace('/[^a-zA-Z0-9\.\-]/', '', $fromDomain);
    $fromEmail = 'noreply@' . ($fromDomain !== '' ? $fromDomain : 'localhost');

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-type: text/html; charset=UTF-8';
    $headers[] = 'From: SmartVMS <' . $fromEmail . '>';

    try {
        return mail($toEmail, $subject, $html, implode("\r\n", $headers));
    } catch (Throwable $e) {
        return false;
    }
}

$hasFullName = has_column_add_resident($pdo, 'users', 'full_name');
$hasContactNumber = has_column_add_resident($pdo, 'users', 'contact_number');
$hasPhone = has_column_add_resident($pdo, 'users', 'phone');
$hasMustChange = has_column_add_resident($pdo, 'users', 'must_change_password');
$hasPasswordHash = has_column_add_resident($pdo, 'users', 'password_hash');
$hasPassword = has_column_add_resident($pdo, 'users', 'password');
$hasUserApartmentId = has_column_add_resident($pdo, 'users', 'apartment_id');
$hasUserCreatedAt = has_column_add_resident($pdo, 'users', 'created_at');
$hasIdentityNumber = has_column_add_resident($pdo, 'users', 'identity_number');
$hasResidentType = has_column_add_resident($pdo, 'users', 'resident_type');
$hasStayStartDate = has_column_add_resident($pdo, 'users', 'stay_start_date');
$hasStayEndDate = has_column_add_resident($pdo, 'users', 'stay_end_date');
$hasVerificationNote = has_column_add_resident($pdo, 'users', 'verification_note');

$contactColumn = $hasContactNumber ? 'contact_number' : ($hasPhone ? 'phone' : null);
$passwordColumn = $hasPasswordHash ? 'password_hash' : ($hasPassword ? 'password' : 'password_hash');

$currentUserId = (int)($_SESSION['uid'] ?? 0);
$currentRole = $_SESSION['role'] ?? 'admin';
$currentEmail = $_SESSION['email'] ?? 'admin';
$currentApartmentId = $_SESSION['apartment_id'] ?? null;

if (($currentApartmentId === null || $currentApartmentId === '') && $currentUserId > 0 && $hasUserApartmentId && $currentRole !== 'superadmin') {
    try {
        $stmt = $pdo->prepare("
            SELECT apartment_id
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
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
        $stmt = $pdo->prepare("
            SELECT apartment_name
            FROM apartments
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([(int)$currentApartmentId]);
        $apartment = $stmt->fetch();

        if ($apartment) {
            $currentApartmentName = $apartment['apartment_name'];
        }
    } catch (Throwable $e) {
        $currentApartmentName = 'Apartment ID ' . (int)$currentApartmentId;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid security token. Please refresh the page.';
    } elseif (($_POST['confirm_save'] ?? '') !== 'yes') {
        $error = 'Action was not confirmed. Please try again and confirm the creation.';
    } else {
        try {
            $fullName = trim($_POST['full_name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $contact = trim($_POST['contact_number'] ?? '');
            $identityNumber = strtoupper(trim($_POST['identity_number'] ?? ''));
            $residentType = trim($_POST['resident_type'] ?? '');
            $stayStartDate = trim($_POST['stay_start_date'] ?? '');
            $stayEndDate = trim($_POST['stay_end_date'] ?? '');
            $verificationNote = trim($_POST['verification_note'] ?? '');
            $password = $_POST['password'] ?? '';
            $unitId = (int)($_POST['unit_id'] ?? 0);

            if ($hasFullName && $fullName === '') {
                throw new Exception('Please enter the resident full name.');
            }

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }

            if ($contact !== '' && !preg_match('/^01[0-9]-?[0-9]{7,8}$/', $contact)) {
                throw new Exception('Contact number format must be like 011-58606387 or 01158606387.');
            }

            if ($hasIdentityNumber && $identityNumber === '') {
                throw new Exception('Please enter the resident IC / Passport number.');
            }

            if ($hasIdentityNumber && $identityNumber !== '' && !preg_match('/^(\\d{6}-?\\d{2}-?\\d{4}|[A-Z0-9]{6,12})$/', $identityNumber)) {
                throw new Exception('IC / Passport format must be like 990101-01-1234 or A12345678.');
            }

            if ($hasResidentType && $residentType === '') {
                throw new Exception('Please select the resident type.');
            }

            if ($hasStayStartDate && $stayStartDate === '') {
                throw new Exception('Please select the stay start date.');
            }

            if ($hasStayEndDate && $stayEndDate !== '' && $hasStayStartDate && $stayStartDate !== '' && $stayEndDate < $stayStartDate) {
                throw new Exception('Stay end date cannot be earlier than stay start date.');
            }

            if ($hasStayEndDate && $residentType === 'Tenant' && $stayEndDate === '') {
                throw new Exception('Please select the contract / stay end date for tenant.');
            }

            if (strlen($password) < 6 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
                throw new Exception('Temporary password must be at least 6 characters and include letters and numbers.');
            }

            if ($unitId <= 0) {
                throw new Exception('Please select the verified unit for this resident.');
            }

            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);

            if ($stmt->fetch()) {
                throw new Exception('This email is already registered.');
            }

            if ($hasIdentityNumber && $identityNumber !== '') {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE identity_number = ? LIMIT 1");
                $stmt->execute([$identityNumber]);

                if ($stmt->fetch()) {
                    throw new Exception('This IC / Passport number is already registered.');
                }
            }

            $stmt = $pdo->prepare("
                SELECT *
                FROM units
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$unitId]);
            $unit = $stmt->fetch();

            if (!$unit) {
                throw new Exception('Selected unit not found.');
            }

            $residentApartmentId = null;

            if ($hasUserApartmentId) {
                if ($currentRole === 'superadmin') {
                    $residentApartmentId = $unit['apartment_id'] ?? null;
                } else {
                    if (empty($currentApartmentId)) {
                        throw new Exception('This admin account is not assigned to any apartment. Please contact superadmin.');
                    }

                    if ((int)($unit['apartment_id'] ?? 0) !== (int)$currentApartmentId) {
                        throw new Exception('You can only assign residents to your own apartment units.');
                    }

                    $residentApartmentId = (int)$currentApartmentId;
                }
            }

            $pdo->beginTransaction();

            $columns = ['email', $passwordColumn, 'role', 'status'];
            $marks = ['?', '?', '?', '?'];
            $values = [
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                'resident',
                'active'
            ];

            if ($hasUserApartmentId) {
                $columns[] = 'apartment_id';
                $marks[] = '?';
                $values[] = $residentApartmentId;
            }

            if ($hasFullName) {
                array_unshift($columns, 'full_name');
                array_unshift($marks, '?');
                array_unshift($values, $fullName ?: null);
            }

            if ($contactColumn !== null) {
                $columns[] = $contactColumn;
                $marks[] = '?';
                $values[] = $contact ?: null;
            }

            if ($hasIdentityNumber) {
                $columns[] = 'identity_number';
                $marks[] = '?';
                $values[] = $identityNumber ?: null;
            }

            if ($hasResidentType) {
                $columns[] = 'resident_type';
                $marks[] = '?';
                $values[] = $residentType ?: null;
            }

            if ($hasStayStartDate) {
                $columns[] = 'stay_start_date';
                $marks[] = '?';
                $values[] = $stayStartDate ?: null;
            }

            if ($hasStayEndDate) {
                $columns[] = 'stay_end_date';
                $marks[] = '?';
                $values[] = $stayEndDate ?: null;
            }

            if ($hasVerificationNote) {
                $columns[] = 'verification_note';
                $marks[] = '?';
                $values[] = $verificationNote ?: null;
            }

            if ($hasMustChange) {
                $columns[] = 'must_change_password';
                $marks[] = '?';
                $values[] = 1;
            }

            if ($hasUserCreatedAt) {
                $columns[] = 'created_at';
                $marks[] = 'NOW()';
            }

            $quotedColumns = array_map(fn($col) => '`' . str_replace('`', '', $col) . '`', $columns);

            $stmt = $pdo->prepare("
                INSERT INTO users
                (" . implode(', ', $quotedColumns) . ")
                VALUES
                (" . implode(', ', $marks) . ")
            ");
            $stmt->execute($values);

            $residentId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare("
                INSERT INTO resident_units
                (resident_id, unit_id, status, created_at)
                VALUES
                (?, ?, 'active', NOW())
            ");
            $stmt->execute([$residentId, $unitId]);

            if (function_exists('log_audit')) {
                log_audit(
                    'RESIDENT_ACCOUNT_CREATED',
                    'Admin created resident account ' . $email . ' and assigned unit ' . ($unit['unit_no'] ?? $unitId)
                );
            }

            $pdo->commit();

            $unitLabel =
                'Block ' . ($unit['block_no'] ?? '-') .
                ' / Floor ' . ($unit['floor_no'] ?? '-') .
                ' / Unit ' . ($unit['unit_no'] ?? '-');

            $emailSent = send_resident_welcome_email(
                $email,
                $fullName,
                $password,
                $currentApartmentName,
                $unitLabel,
                $contact,
                $residentType,
                $stayStartDate,
                $stayEndDate
            );

            if ($emailSent) {
                $message = 'Resident account created successfully. Welcome email and simple user manual have been sent to the resident.';
            } else {
                $message = 'Resident account created successfully. Email was not sent. Please check your XAMPP/PHP mail or SMTP setting.';
            }

            $_POST = [];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e->getMessage();
        }
    }
}

if ($currentRole === 'superadmin') {
    $units = safe_rows_add_resident($pdo, "
        SELECT
            MIN(un.id) AS id,
            un.block_no,
            un.floor_no,
            un.unit_no,
            un.apartment_id,
            a.apartment_name
        FROM units un
        LEFT JOIN apartments a ON a.id = un.apartment_id
        GROUP BY
            un.apartment_id,
            a.apartment_name,
            un.block_no,
            un.floor_no,
            un.unit_no
        ORDER BY a.apartment_name ASC, un.block_no ASC, un.floor_no ASC, un.unit_no ASC
        LIMIT 2000
    ");
} elseif (!empty($currentApartmentId)) {
    $units = safe_rows_add_resident($pdo, "
        SELECT
            MIN(un.id) AS id,
            un.block_no,
            un.floor_no,
            un.unit_no,
            un.apartment_id,
            NULL AS apartment_name
        FROM units un
        WHERE un.apartment_id = ?
        GROUP BY
            un.apartment_id,
            un.block_no,
            un.floor_no,
            un.unit_no
        ORDER BY un.block_no ASC, un.floor_no ASC, un.unit_no ASC
        LIMIT 2000
    ", [(int)$currentApartmentId]);
} else {
    $units = [];
}

$unitOptionsForJs = array_map(function ($unit) {
    return [
        'id' => (int)($unit['id'] ?? 0),
        'block_no' => (string)($unit['block_no'] ?? ''),
        'floor_no' => (string)($unit['floor_no'] ?? ''),
        'unit_no' => (string)($unit['unit_no'] ?? ''),
        'apartment_id' => (int)($unit['apartment_id'] ?? 0),
        'apartment_name' => (string)($unit['apartment_name'] ?? '')
    ];
}, $units);

$selectedUnitId = (int)($_POST['unit_id'] ?? 0);
$selectedUnitForJs = null;

foreach ($unitOptionsForJs as $unitOption) {
    if ((int)$unitOption['id'] === $selectedUnitId) {
        $selectedUnitForJs = $unitOption;
        break;
    }
}

if ($currentRole === 'superadmin') {
    $totalResidentAccounts = safe_count_add_resident($pdo, "
        SELECT COUNT(*)
        FROM users
        WHERE role = 'resident'
    ");
} else {
    $totalResidentAccounts = $hasUserApartmentId && !empty($currentApartmentId)
        ? safe_count_add_resident($pdo, "
            SELECT COUNT(*)
            FROM users
            WHERE role = 'resident'
            AND apartment_id = ?
        ", [(int)$currentApartmentId])
        : 0;
}

$profileInitial = strtoupper(substr(trim($currentEmail ?: 'A'), 0, 1));
if ($profileInitial === '') {
    $profileInitial = 'A';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Resident Account - <?= e(APP_NAME) ?></title>
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
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
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
            padding: 18px 30px 18px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .topbar {
            min-height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 18px;
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
            font-size: 1.8rem;
            line-height: 1.1;
            font-weight: 950;
            letter-spacing: -0.06em;
        }

        .page-sub {
            color: var(--muted);
            margin-top: 7px;
            font-size: .9rem;
            font-weight: 750;
            line-height: 1.5;
            max-width: 760px;
        }

        .top-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
            min-width: 330px;
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
            padding: 15px 16px;
            border-radius: 18px;
            margin-bottom: 18px;
            font-weight: 850;
            line-height: 1.45;
            box-shadow: var(--shadow-soft);
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

        .content-grid {
            display: grid;
            grid-template-columns: minmax(440px, 0.95fr) minmax(440px, 1.05fr);
            gap: 26px;
            align-items: stretch;
            flex: 1 1 auto;
            min-height: 0;
            overflow: hidden;
        }

        .content-grid > .panel,
        .content-grid > div,
        .content-grid > div > .panel {
            height: 100%;
        }

        .content-grid > div > .panel {
            display: flex;
            flex-direction: column;
        }

        .content-grid > div > .panel .panel-body {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .content-grid > div > .panel .promo-slider {
            margin-top: auto;
        }

        .panel {
            background: rgba(255,255,255,.96);
            border: 1px solid rgba(229,231,235,.95);
            border-radius: 26px;
            box-shadow: var(--shadow);
            overflow: hidden;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .panel-header {
            padding: 20px 22px;
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
            padding: 22px;
            flex: 1 1 auto;
            min-height: 0;
            display: flex;
            flex-direction: column;
        }

        .panel-body > form {
            flex: 1 1 auto;
            min-height: 0;
            display: flex;
            flex-direction: column;
            overflow: visible;
        }

        .note-box {
            background: #fff7f7;
            color: #991b1b;
            border: 1px solid #fecaca;
            padding: 12px 15px;
            border-radius: 18px;
            font-size: .82rem;
            font-weight: 850;
            line-height: 1.5;
            margin-bottom: 16px;
        }

        .privacy-note {
            background: #f8fafc;
            color: #475569;
            border: 1px solid var(--border);
            padding: 12px 14px;
            border-radius: 16px;
            font-size: .78rem;
            font-weight: 800;
            line-height: 1.5;
            margin-bottom: 16px;
        }

        textarea {
            width: 100%;
            min-height: 90px;
            resize: vertical;
            padding: 13px 14px;
            border: 1px solid var(--border);
            border-radius: 15px;
            font-weight: 850;
            outline: none;
            background: white;
        }

        textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(220,38,38,.10);
        }

        .field {
            margin-bottom: 10px;
        }

        .unit-select-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 15px;
        }

        .unit-preview {
            background: #fff7f7;
            color: #991b1b;
            border: 1px solid #fecaca;
            border-radius: 15px;
            padding: 12px 14px;
            font-size: .8rem;
            font-weight: 850;
            line-height: 1.45;
            margin-top: -3px;
            margin-bottom: 15px;
        }

        .unit-preview strong {
            color: #111827;
        }
        .wizard-pane {
            display: none;
        }

        .wizard-pane.active {
            display: flex;
            flex-direction: column;
            flex: 0 0 auto;
            min-height: 0;
        }

        .two-field-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .wizard-actions {
            display: flex;
            gap: 10px;
            justify-content: space-between;
            margin-top: 10px;
            padding-top: 8px;
        }


        /* 100% zoom fix: keep Next/Create buttons visible inside the add resident wizard */
        .panel:first-child .panel-body {
            overflow-y: auto;
            scrollbar-width: thin;
        }

        .panel:first-child .panel-body::-webkit-scrollbar {
            width: 6px;
        }

        .panel:first-child .panel-body::-webkit-scrollbar-thumb {
            background: #fecaca;
            border-radius: 999px;
        }

        .btn-secondary {
            background: white;
            color: #111827;
            border: 1px solid var(--border);
        }

        .wizard-hint {
            margin-top: 8px;
            color: var(--muted);
            font-size: .74rem;
            font-weight: 800;
            line-height: 1.35;
        }
.field-error {
            display: none;
            margin-top: 7px;
            color: #dc2626;
            font-size: .72rem;
            font-weight: 900;
            line-height: 1.45;
        }

        .field.has-error input,
        .field.has-error select,
        .field.has-error textarea {
            border-color: #ef4444;
            box-shadow: 0 0 0 4px rgba(239, 68, 68, .10);
        }

        .field.has-error .field-error {
            display: block;
        }

        label {
            display: block;
            font-size: .72rem;
            font-weight: 950;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 8px;
        }

        input, select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: 15px;
            font-weight: 850;
            outline: none;
            background: white;
        }

        input:focus, select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(220,38,38,.10);
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

        .process-list {
            display: grid;
            gap: 10px;
            flex: 0 0 auto;
        }

        .process-item {
            background: #fbfdff;
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 12px 13px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }

        .process-number {
            width: 34px;
            height: 34px;
            border-radius: 13px;
            background: var(--primary-soft);
            color: var(--primary);
            display: grid;
            place-items: center;
            font-size: .82rem;
            font-weight: 950;
            flex: 0 0 auto;
        }

        .process-title {
            font-weight: 950;
            margin-bottom: 4px;
        }

        .process-text {
            color: var(--muted);
            font-size: .78rem;
            line-height: 1.55;
            font-weight: 750;
        }

        .promo-slider {
            margin-top: 18px;
            border-radius: 22px;
            overflow: hidden;
            border: 1px solid #fecaca;
            box-shadow: 0 18px 42px rgba(220, 38, 38, .10);
            position: relative;
            min-height: 185px;
            background: #fff7f7;
        }

        .promo-slide {
            min-height: 185px;
            display: none;
            padding: 22px 24px;
            position: relative;
            overflow: hidden;
            background:
                radial-gradient(circle at 82% 18%, rgba(255,255,255,.75), transparent 24%),
                linear-gradient(135deg, #fff7f7 0%, #fee2e2 48%, #fff 100%);
        }

        .promo-slide.active {
            display: grid;
            grid-template-columns: 1.15fr .85fr;
            gap: 18px;
            align-items: center;
            animation: promoFade .45s ease both;
        }

        .promo-kicker {
            color: var(--primary);
            font-size: .68rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .1em;
            margin-bottom: 8px;
        }

        .promo-title {
            font-size: 1.25rem;
            line-height: 1.16;
            font-weight: 950;
            letter-spacing: -.055em;
            color: #111827;
            margin-bottom: 10px;
        }

        .promo-text {
            color: var(--muted);
            font-size: .8rem;
            line-height: 1.52;
            font-weight: 800;
            max-width: 360px;
        }

        .promo-art {
            height: 145px;
            border-radius: 24px;
            background: rgba(255,255,255,.62);
            border: 1px solid rgba(255,255,255,.85);
            display: grid;
            place-items: center;
            position: relative;
        }

        .promo-art-main {
            width: 76px;
            height: 76px;
            border-radius: 26px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            display: grid;
            place-items: center;
            font-size: 1.85rem;
            box-shadow: 0 22px 35px rgba(220, 38, 38, .22);
            z-index: 2;
        }

        .promo-art-chip {
            position: absolute;
            min-width: 72px;
            height: 36px;
            border-radius: 999px;
            background: white;
            border: 1px solid #fecaca;
            box-shadow: 0 10px 24px rgba(15, 23, 42, .08);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            color: var(--primary);
            font-size: .7rem;
            font-weight: 950;
        }

        .promo-art-chip.one {
            top: 22px;
            left: 18px;
        }

        .promo-art-chip.two {
            right: 18px;
            bottom: 24px;
        }

        .promo-dots {
            position: absolute;
            left: 24px;
            bottom: 18px;
            display: flex;
            gap: 7px;
            z-index: 5;
        }

        .promo-dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: rgba(153, 27, 27, .22);
            border: 0;
            cursor: pointer;
            transition: .2s ease;
        }

        .promo-dot.active {
            width: 24px;
            background: var(--primary);
        }

        @keyframes promoFade {
            from {
                opacity: 0;
                transform: translateY(8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }


        .local-banner-card {
            margin-top: 18px;
            border-radius: 22px;
            overflow: hidden;
            border: 1px solid #fecaca;
            box-shadow: 0 18px 42px rgba(220, 38, 38, .10);
            background: linear-gradient(135deg, #e9fbfb, #fff7f7);
            position: relative;
            padding: 8px;
            flex: 1 1 auto;
            min-height: 250px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .local-banner-slide {
            display: none;
            width: 100%;
            height: 100%;
            object-fit: contain;
            object-position: center center;
            border-radius: 18px;
            animation: bannerFade .45s ease both;
        }

        .local-banner-slide.active {
            display: block;
        }

        .local-banner-dots {
            position: absolute;
            left: 18px;
            bottom: 14px;
            display: flex;
            gap: 7px;
            z-index: 2;
        }

        .local-banner-dot {
            width: 8px;
            height: 8px;
            border: 0;
            border-radius: 999px;
            background: rgba(255,255,255,.75);
            cursor: pointer;
            transition: .2s ease;
        }

        .local-banner-dot.active {
            width: 24px;
            background: #dc2626;
        }

        @keyframes bannerFade {
            from {
                opacity: 0;
                transform: translateY(6px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 720px) {
            .local-banner-card {
                min-height: 180px;
            }
        }

        .small-stat {
            margin-top: 14px;
            background: #fff7f7;
            border: 1px solid #fecaca;
            border-radius: 18px;
            padding: 16px;
        }

        .small-stat-label {
            color: var(--muted);
            font-size: .68rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 8px;
        }

        .small-stat-value {
            font-size: 1.7rem;
            font-weight: 950;
            color: #111827;
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
                overflow: visible;
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
                overflow: visible;
                padding: 22px 18px 50px;
            }

            .content-grid {
                overflow: visible;
            }
        }

        @media (max-width: 1100px) {
            .content-grid {
                grid-template-columns: 1fr;
                align-items: start;
            }

            .content-grid > .panel,
            .content-grid > div,
            .content-grid > div > .panel {
                height: auto;
            }

            .top-actions {
                min-width: 0;
            }
        }

        @media (max-width: 720px) {
            .topbar {
                flex-direction: column;
                align-items: flex-start;
            }

            .unit-select-grid {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .top-actions {
                width: 100%;
            }

            .promo-slide.active {
                grid-template-columns: 1fr;
            }

            .promo-art {
                height: 140px;
            }

            .top-btn,
            .btn {
                width: 100%;
            }

            .side-nav {
                grid-template-columns: 1fr;
            }

            .profile-dropdown {
                right: 0;
                width: min(270px, calc(100vw - 36px));
            }
        }
    </style>

<style>
    /* 100% zoom fix: make Step 2 submit button fully visible without hiding any function */
    .main {
        padding-top: 14px !important;
        padding-bottom: 12px !important;
    }

    .topbar {
        min-height: 54px !important;
        margin-bottom: 12px !important;
    }

    .page-kicker {
        margin-bottom: 3px !important;
    }

    .page-title {
        font-size: 1.62rem !important;
        line-height: 1 !important;
    }

    .page-sub {
        margin-top: 5px !important;
        font-size: .84rem !important;
        line-height: 1.35 !important;
    }

    .content-grid {
        gap: 18px !important;
    }

    .panel-header {
        padding: 14px 20px !important;
        min-height: 48px !important;
    }

    .panel-body {
        padding: 16px 20px !important;
    }

    .note-box {
        padding: 9px 13px !important;
        border-radius: 15px !important;
        font-size: .78rem !important;
        line-height: 1.35 !important;
        margin-bottom: 12px !important;
    }

    .two-field-grid {
        gap: 9px !important;
    }

    .field {
        margin-bottom: 7px !important;
    }

    label {
        font-size: .68rem !important;
        margin-bottom: 5px !important;
    }

    input,
    select {
        min-height: 40px !important;
        padding: 8px 13px !important;
        border-radius: 14px !important;
        font-size: .84rem !important;
    }

    textarea {
        min-height: 66px !important;
        padding: 10px 13px !important;
        border-radius: 14px !important;
        font-size: .82rem !important;
        line-height: 1.3 !important;
    }

    .unit-select-grid {
        gap: 9px !important;
        margin-bottom: 9px !important;
    }

    .unit-preview {
        padding: 9px 12px !important;
        margin-top: -1px !important;
        margin-bottom: 9px !important;
        border-radius: 14px !important;
        font-size: .76rem !important;
        line-height: 1.25 !important;
    }

    .wizard-actions {
        margin-top: 6px !important;
        padding-top: 4px !important;
        align-items: center !important;
    }

    .wizard-actions .btn {
        min-height: 42px !important;
        padding: 10px 14px !important;
        border-radius: 14px !important;
        font-size: .8rem !important;
    }

    .wizard-hint {
        margin-top: 5px !important;
        font-size: .68rem !important;
        line-height: 1.25 !important;
    }

    .process-list {
        gap: 8px !important;
    }

    .process-item {
        padding: 12px 14px !important;
        min-height: 68px !important;
    }

    .process-title {
        font-size: .95rem !important;
    }

    .process-text {
        font-size: .76rem !important;
        line-height: 1.35 !important;
    }

    .local-banner-card {
        margin-top: 12px !important;
        min-height: 205px !important;
        padding: 6px !important;
    }

    @media (max-height: 760px) {
        .main {
            padding-top: 10px !important;
            padding-bottom: 10px !important;
        }

        .topbar {
            min-height: 48px !important;
            margin-bottom: 10px !important;
        }

        .page-title {
            font-size: 1.5rem !important;
        }

        .page-sub {
            font-size: .8rem !important;
        }

        .panel-header {
            padding: 12px 18px !important;
            min-height: 44px !important;
        }

        .panel-body {
            padding: 14px 18px !important;
        }

        textarea {
            min-height: 58px !important;
        }

        .local-banner-card {
            min-height: 180px !important;
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
                <h1 class="page-title">Add Resident Account</h1>
                <p class="page-sub">
                    Create a verified resident account in 2 simple steps without scrolling through a long form.
                </p>
            </div>

            <div class="top-actions">
                <a href="admin_residents_manage.php" class="top-btn">
                    <i class="fas fa-list"></i>
                    Manage Residents
                </a>

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

        <section class="content-grid">
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="fas fa-user-plus"></i>
                        Create Resident Account
                    </div>
                </div>

                <div class="panel-body">
                    <div class="note-box">
                        Resident self-registration is disabled. Admin must verify the resident in real life before creating the account.
                    </div>

                    <form method="POST" data-safe-confirm="1" id="addResidentForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_resident">

                        <div class="wizard-pane active" data-wizard-step="1">
                            <?php if ($hasFullName): ?>
                                <div class="field">
                                    <label>Full Name</label>
                                    <input
                                        type="text"
                                        name="full_name"
                                        placeholder="Example: Tan Kai Ming"
                                        value="<?= e($_POST['full_name'] ?? '') ?>"
                                        required
                                        data-step-required="1"
                                    >
                                    <div class="field-error"></div>
                                </div>
                            <?php endif; ?>

                            <div class="field">
                                <label>Email</label>
                                <input
                                    type="email"
                                    name="email"
                                    placeholder="resident@email.com"
                                    value="<?= e($_POST['email'] ?? '') ?>"
                                    required
                                    data-step-required="1"
                                >
                                <div class="field-error"></div>
                            </div>

                            <?php if ($contactColumn !== null): ?>
                                <div class="field">
                                    <label>Contact Number</label>
                                    <input
                                        type="text"
                                        name="contact_number"
                                        placeholder="Example: 011-58606387"
                                        value="<?= e($_POST['contact_number'] ?? '') ?>"
                                    >
<div class="field-error"></div>
                                </div>
                            <?php endif; ?>

                                <?php if ($hasIdentityNumber): ?>
                                <div class="field">
                                    <label>IC / Passport Number</label>
                                    <input
                                        type="text"
                                        name="identity_number"
                                        placeholder="Example: 990101-01-1234 or A12345678"
                                        value="<?= e($_POST['identity_number'] ?? '') ?>"
                                        required
                                        data-step-required="1"
                                    >
<div class="field-error"></div>
                                </div>
                            <?php endif; ?>

                            <?php if ($hasResidentType): ?>
                                <div class="field">
                                    <label>Resident Type</label>
                                    <select name="resident_type" required data-step-required="1">
                                        <option value="">-- Select resident type --</option>
                                        <option value="Owner" <?= ($_POST['resident_type'] ?? '') === 'Owner' ? 'selected' : '' ?>>Owner / Buyer</option>
                                        <option value="Tenant" <?= ($_POST['resident_type'] ?? '') === 'Tenant' ? 'selected' : '' ?>>Tenant / Renter</option>
                                        <option value="Family Member" <?= ($_POST['resident_type'] ?? '') === 'Family Member' ? 'selected' : '' ?>>Family Member</option>
                                        <option value="Other" <?= ($_POST['resident_type'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
                                    </select>
                                    <div class="field-error"></div>
                                </div>
                            <?php endif; ?>

                            <div class="wizard-actions">
                                <div></div>

                                <button type="button" class="btn btn-primary" id="nextStepBtn">
                                    Next
                                    <i class="fas fa-arrow-right"></i>
                                </button>
                            </div>

                            <div class="wizard-hint">
                                Step 1 collects resident identity details for verification.
                            </div>
                        </div>

                        <div class="wizard-pane" data-wizard-step="2">
                            <div class="two-field-grid">
                                <?php if ($hasStayStartDate): ?>
                                    <div class="field">
                                        <label>Stay Start Date</label>
                                        <input
                                            type="date"
                                            name="stay_start_date"
                                            value="<?= e($_POST['stay_start_date'] ?? '') ?>"
                                            required
                                        >
                                        <div class="field-error"></div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($hasStayEndDate): ?>
                                    <div class="field">
                                        <label>Stay End / Contract End</label>
                                        <input
                                            type="date"
                                            name="stay_end_date"
                                            value="<?= e($_POST['stay_end_date'] ?? '') ?>"
                                        >
                                        <div class="field-error"></div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($hasStayEndDate): ?>
                                <div class="process-text" style="margin-top:-4px;margin-bottom:14px;">
                                    Leave blank for owner / no fixed end date. Tenant should have a contract end date.
                                </div>
                            <?php endif; ?>

                            <?php if ($hasVerificationNote): ?>
                                <div class="field">
                                    <label>Verification Note</label>
                                    <textarea
                                        name="verification_note"
                                        placeholder="Example: Verified by tenancy agreement / owner confirmation / management office record"
                                    ><?= e($_POST['verification_note'] ?? '') ?></textarea>
                                </div>
                            <?php endif; ?>

                            <div class="field">
                                <label>Temporary Password</label>
                                <input
                                    type="text"
                                    name="password"
                                    placeholder="Example: Resident123"
                                    required
                                >
<div class="field-error"></div>
                            </div>

                            <label>Verified Unit</label>
                            <div class="unit-select-grid">
                                <div class="field">
                                    <select id="unitBlockSelect" required>
                                        <option value="">1. Select Block</option>
                                    </select>
                                    <div class="field-error"></div>
                                </div>

                                <div class="field">
                                    <select id="unitFloorSelect" required disabled>
                                        <option value="">2. Select Floor</option>
                                    </select>
                                    <div class="field-error"></div>
                                </div>

                                <div class="field">
                                    <select id="unitUnitSelect" name="unit_id" required disabled>
                                        <option value="">3. Select Unit</option>
                                    </select>
                                    <div class="field-error"></div>
                                </div>
                            </div>

                            <div class="unit-preview" id="unitPreview">
                                Selected unit:
                                <strong>Not selected yet</strong>
                            </div>

                            <div class="wizard-actions">
                                <button type="button" class="btn btn-secondary" id="backStepBtn">
                                    <i class="fas fa-arrow-left"></i>
                                    Back
                                </button>

                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-plus"></i>
                                    Create Resident Account
                                </button>
                            </div>

                            <div class="wizard-hint">
                                Step 2 sets stay period, unit and first login password.
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div>
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title">
                            <i class="fas fa-circle-info"></i>
                            Add Resident Flow
                        </div>
                    </div>

                    <div class="panel-body">
                        <div class="process-list">
                            <div class="process-item">
                                <div class="process-number">1</div>
                                <div>
                                    <div class="process-title">Verify resident in real life</div>
                                    <div class="process-text">
                                        Admin checks IC / passport and confirms the person is really staying in this apartment.
                                    </div>
                                </div>
                            </div>

                            <div class="process-item">
                                <div class="process-number">2</div>
                                <div>
                                    <div class="process-title">Create account</div>
                                    <div class="process-text">
                                        Enter resident name, email, contact number, IC / passport, resident type, stay period and temporary password.
                                    </div>
                                </div>
                            </div>

                            <div class="process-item">
                                <div class="process-number">3</div>
                                <div>
                                    <div class="process-title">Assign verified unit</div>
                                    <div class="process-text">
                                        The resident account will be connected to the selected unit immediately.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="local-banner-card" id="residentLocalBanner">
                            <img
                                src="resident_registration_banner_1.png"
                                alt="SmartVMS Resident Registration Banner 1"
                                class="local-banner-slide active"
                            >
                            <img
                                src="resident_registration_banner_2.png"
                                alt="SmartVMS Resident Registration Banner 2"
                                class="local-banner-slide"
                            >
                            <img
                                src="resident_registration_banner_3.png"
                                alt="SmartVMS Resident Registration Banner 3"
                                class="local-banner-slide"
                            >

                            <div class="local-banner-dots">
                                <button type="button" class="local-banner-dot active" data-local-banner="0" aria-label="Banner 1"></button>
                                <button type="button" class="local-banner-dot" data-local-banner="1" aria-label="Banner 2"></button>
                                <button type="button" class="local-banner-dot" data-local-banner="2" aria-label="Banner 3"></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>
</div>

<?php if ($message): ?>
<script>
Swal.fire({
    icon: 'success',
    title: 'Success',
    text: <?= json_encode($message) ?>,
    confirmButtonColor: '#dc2626'
});
</script>
<?php endif; ?>

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
    const localBannerSlides = document.querySelectorAll('#residentLocalBanner .local-banner-slide');
    const localBannerDots = document.querySelectorAll('#residentLocalBanner .local-banner-dot');
    let localBannerIndex = 0;

    function showLocalBanner(index) {
        if (!localBannerSlides.length) {
            return;
        }

        localBannerIndex = index % localBannerSlides.length;

        localBannerSlides.forEach(function (slide, slideIndex) {
            slide.classList.toggle('active', slideIndex === localBannerIndex);
        });

        localBannerDots.forEach(function (dot, dotIndex) {
            dot.classList.toggle('active', dotIndex === localBannerIndex);
        });
    }

    if (localBannerSlides.length) {
        let localBannerTimer = setInterval(function () {
            showLocalBanner(localBannerIndex + 1);
        }, 3000);

        localBannerDots.forEach(function (dot) {
            dot.addEventListener('click', function () {
                clearInterval(localBannerTimer);
                showLocalBanner(Number(dot.dataset.localBanner || 0));

                localBannerTimer = setInterval(function () {
                    showLocalBanner(localBannerIndex + 1);
                }, 3000);
            });
        });
    }

    const promoSlides = document.querySelectorAll('#residentPromoSlider .promo-slide');
    const promoDots = document.querySelectorAll('#residentPromoSlider .promo-dot');
    let promoIndex = 0;

    function showPromoSlide(index) {
        if (!promoSlides.length) {
            return;
        }

        promoIndex = index % promoSlides.length;

        promoSlides.forEach(function (slide, slideIndex) {
            slide.classList.toggle('active', slideIndex === promoIndex);
        });

        promoDots.forEach(function (dot, dotIndex) {
            dot.classList.toggle('active', dotIndex === promoIndex);
        });
    }

    if (promoSlides.length) {
        let promoTimer = setInterval(function () {
            showPromoSlide(promoIndex + 1);
        }, 3000);

        promoDots.forEach(function (dot) {
            dot.addEventListener('click', function () {
                clearInterval(promoTimer);
                showPromoSlide(Number(dot.dataset.promoDot || 0));

                promoTimer = setInterval(function () {
                    showPromoSlide(promoIndex + 1);
                }, 3000);
            });
        });
    }

    const wizardPanes = document.querySelectorAll('[data-wizard-step]');
    const wizardIndicators = document.querySelectorAll('[data-step-indicator]');
    const nextStepBtn = document.getElementById('nextStepBtn');
    const backStepBtn = document.getElementById('backStepBtn');
    const addResidentForm = document.getElementById('addResidentForm');

    function showWizardStep(step) {
        wizardPanes.forEach(function (pane) {
            pane.classList.toggle('active', pane.dataset.wizardStep === String(step));
        });

        wizardIndicators.forEach(function (indicator) {
            indicator.classList.toggle('active', indicator.dataset.stepIndicator === String(step));
        });
    }

    function setFieldError(field, message) {
        if (!field) {
            return true;
        }

        const fieldWrap = field.closest('.field');
        const errorBox = fieldWrap ? fieldWrap.querySelector('.field-error') : null;

        field.setCustomValidity(message || '');

        if (fieldWrap) {
            fieldWrap.classList.toggle('has-error', !!message);
        }

        if (errorBox) {
            errorBox.textContent = message || '';
        }

        return !message;
    }

    function formatContactNumber(value) {
        const digits = (value || '').replace(/\D/g, '').slice(0, 11);

        if (digits.length <= 3) {
            return digits;
        }

        return digits.slice(0, 3) + '-' + digits.slice(3);
    }

    function formatIdentityNumber(value) {
        const raw = (value || '').toUpperCase().replace(/[^A-Z0-9]/g, '');

        // Passport format: keep as uppercase letters/numbers only, no dash.
        if (/[A-Z]/.test(raw)) {
            return raw.slice(0, 12);
        }

        // IC format: YYMMDD-SS-XXXX
        const digits = raw.replace(/\D/g, '').slice(0, 12);

        if (digits.length <= 6) {
            return digits;
        }

        if (digits.length <= 8) {
            return digits.slice(0, 6) + '-' + digits.slice(6);
        }

        return digits.slice(0, 6) + '-' + digits.slice(6, 8) + '-' + digits.slice(8);
    }

    function setupAutoFormatter() {
        const contactInput = addResidentForm?.querySelector('[name="contact_number"]');
        const identityInput = addResidentForm?.querySelector('[name="identity_number"]');

        if (contactInput) {
            contactInput.setAttribute('inputmode', 'numeric');
            contactInput.setAttribute('maxlength', '12');
            contactInput.setAttribute('placeholder', 'Example: 011-58606387');

            contactInput.addEventListener('input', function () {
                const cursorAtEnd = contactInput.selectionStart === contactInput.value.length;
                contactInput.value = formatContactNumber(contactInput.value);

                if (cursorAtEnd) {
                    contactInput.setSelectionRange(contactInput.value.length, contactInput.value.length);
                }
            });

            contactInput.addEventListener('blur', function () {
                contactInput.value = formatContactNumber(contactInput.value);
            });
        }

        if (identityInput) {
            identityInput.setAttribute('maxlength', '14');
            identityInput.setAttribute('placeholder', 'Example: 990101-01-1234 or A12345678');

            identityInput.addEventListener('input', function () {
                const cursorAtEnd = identityInput.selectionStart === identityInput.value.length;
                identityInput.value = formatIdentityNumber(identityInput.value);

                if (cursorAtEnd) {
                    identityInput.setSelectionRange(identityInput.value.length, identityInput.value.length);
                }
            });

            identityInput.addEventListener('blur', function () {
                identityInput.value = formatIdentityNumber(identityInput.value);
            });
        }
    }

    function validateResidentFormats(stepOnly = null) {
        let valid = true;

        const fullName = addResidentForm?.querySelector('[name="full_name"]');
        const email = addResidentForm?.querySelector('[name="email"]');
        const contact = addResidentForm?.querySelector('[name="contact_number"]');
        const identity = addResidentForm?.querySelector('[name="identity_number"]');
        const residentType = addResidentForm?.querySelector('[name="resident_type"]');
        const stayStart = addResidentForm?.querySelector('[name="stay_start_date"]');
        const stayEnd = addResidentForm?.querySelector('[name="stay_end_date"]');
        const password = addResidentForm?.querySelector('[name="password"]');

        const nameRegex = /^[A-Za-z@.'\-\s]{2,80}$/;
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        const phoneRegex = /^01[0-9]-?[0-9]{7,8}$/;
        const identityRegex = /^(\d{6}-?\d{2}-?\d{4}|[A-Z0-9]{6,12})$/i;

        if (stepOnly === null || stepOnly === 1) {
            if (fullName) {
                const nameValue = fullName.value.trim();
                valid = setFieldError(
                    fullName,
                    nameValue === ''
                        ? 'Full name is required.'
                        : (!nameRegex.test(nameValue) ? 'Full name should only contain letters, spaces, @, dot, apostrophe or hyphen.' : '')
                ) && valid;
            }

            if (email) {
                const emailValue = email.value.trim();
                valid = setFieldError(
                    email,
                    emailValue === ''
                        ? 'Email is required.'
                        : (!emailRegex.test(emailValue) ? 'Please enter a valid email address.' : '')
                ) && valid;
            }

            if (contact) {
                contact.value = formatContactNumber(contact.value);
                const contactValue = contact.value.trim();

                valid = setFieldError(
                    contact,
                    contactValue !== '' && !phoneRegex.test(contactValue)
                        ? 'Phone format must start with 01 and follow 011-58606387.'
                        : ''
                ) && valid;
            }

            if (identity) {
                identity.value = formatIdentityNumber(identity.value);
                const identityValue = identity.value.trim().toUpperCase();

                valid = setFieldError(
                    identity,
                    identityValue === ''
                        ? 'IC / Passport number is required.'
                        : (!identityRegex.test(identityValue) ? 'Use IC format 990101-01-1234 or passport A12345678.' : '')
                ) && valid;
            }

            if (residentType) {
                valid = setFieldError(
                    residentType,
                    residentType.value === '' ? 'Please select resident type.' : ''
                ) && valid;
            }
        }

        if (stepOnly === null || stepOnly === 2) {
            if (stayStart) {
                valid = setFieldError(
                    stayStart,
                    stayStart.value === '' ? 'Stay start date is required.' : ''
                ) && valid;
            }

            if (stayEnd) {
                let endError = '';

                if (residentType && residentType.value === 'Tenant' && stayEnd.value === '') {
                    endError = 'Tenant / renter must have a contract end date.';
                } else if (stayStart && stayStart.value !== '' && stayEnd.value !== '' && stayEnd.value < stayStart.value) {
                    endError = 'Stay end date cannot be earlier than start date.';
                }

                valid = setFieldError(stayEnd, endError) && valid;
            }

            if (password) {
                const passwordValue = password.value;
                const strongEnough = passwordValue.length >= 6 && /[A-Za-z]/.test(passwordValue) && /[0-9]/.test(passwordValue);

                valid = setFieldError(
                    password,
                    !strongEnough ? 'Password must be at least 6 characters and include letters and numbers.' : ''
                ) && valid;
            }

            if (blockSelect) {
                valid = setFieldError(blockSelect, blockSelect.value === '' ? 'Please select block.' : '') && valid;
            }

            if (floorSelect) {
                valid = setFieldError(floorSelect, floorSelect.value === '' ? 'Please select floor.' : '') && valid;
            }

            if (unitSelect) {
                valid = setFieldError(unitSelect, unitSelect.value === '' ? 'Please select unit.' : '') && valid;
            }
        }

        return valid;
    }

    setupAutoFormatter();

    if (addResidentForm) {
        addResidentForm.addEventListener('input', function (event) {
            if (event.target.matches('input, textarea')) {
                const stepPane = event.target.closest('[data-wizard-step]');
                const step = stepPane ? Number(stepPane.dataset.wizardStep) : null;
                validateResidentFormats(step);
            }
        });

        addResidentForm.addEventListener('change', function (event) {
            if (event.target.matches('select, input[type="date"]')) {
                const stepPane = event.target.closest('[data-wizard-step]');
                const step = stepPane ? Number(stepPane.dataset.wizardStep) : null;
                validateResidentFormats(step);
            }
        });
    }

    if (nextStepBtn) {
        nextStepBtn.addEventListener('click', function () {
            if (!validateResidentFormats(1)) {
                const firstInvalid = addResidentForm.querySelector(':invalid');

                if (firstInvalid) {
                    firstInvalid.reportValidity();
                }

                return;
            }

            showWizardStep(2);
        });
    }

    if (backStepBtn) {
        backStepBtn.addEventListener('click', function () {
            showWizardStep(1);
        });
    }

    if (addResidentForm) {
        addResidentForm.addEventListener('invalid', function () {
            showWizardStep(1);
        }, true);
    }

    const unitOptions = <?= json_encode($unitOptionsForJs, JSON_UNESCAPED_UNICODE) ?>;
    const selectedUnit = <?= json_encode($selectedUnitForJs, JSON_UNESCAPED_UNICODE) ?>;

    const blockSelect = document.getElementById('unitBlockSelect');
    const floorSelect = document.getElementById('unitFloorSelect');
    const unitSelect = document.getElementById('unitUnitSelect');
    const unitPreview = document.getElementById('unitPreview');

    function uniqueSorted(values) {
        return [...new Set(values.filter(value => value !== null && value !== ''))].sort(function (a, b) {
            return String(a).localeCompare(String(b), undefined, {numeric: true, sensitivity: 'base'});
        });
    }

    function resetSelect(select, placeholder, disabled = true) {
        select.innerHTML = '';
        const option = document.createElement('option');
        option.value = '';
        option.textContent = placeholder;
        select.appendChild(option);
        select.disabled = disabled;
    }

    function fillSelect(select, values, placeholder) {
        resetSelect(select, placeholder, false);

        values.forEach(function (value) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = value;
            select.appendChild(option);
        });
    }

    function updatePreview() {
        const selected = unitOptions.find(function (unit) {
            return String(unit.id) === String(unitSelect.value);
        });

        if (!selected) {
            unitPreview.innerHTML = 'Selected unit: <strong>Not selected yet</strong>';
            return;
        }

        let text = 'Block ' + selected.block_no + ' / Floor ' + selected.floor_no + ' / Unit ' + selected.unit_no;

        if (selected.apartment_name) {
            text = selected.apartment_name + ' - ' + text;
        }

        unitPreview.innerHTML = 'Selected unit: <strong>' + text + '</strong>';
    }

    if (blockSelect && floorSelect && unitSelect) {
        const blocks = uniqueSorted(unitOptions.map(function (unit) {
            return unit.block_no;
        }));

        fillSelect(blockSelect, blocks, '1. Select Block');
        resetSelect(floorSelect, '2. Select Floor', true);
        resetSelect(unitSelect, '3. Select Unit', true);

        blockSelect.addEventListener('change', function () {
            const selectedBlock = blockSelect.value;
            resetSelect(floorSelect, '2. Select Floor', true);
            resetSelect(unitSelect, '3. Select Unit', true);
            updatePreview();

            if (!selectedBlock) {
                return;
            }

            const floors = uniqueSorted(unitOptions
                .filter(function (unit) {
                    return unit.block_no === selectedBlock;
                })
                .map(function (unit) {
                    return unit.floor_no;
                }));

            fillSelect(floorSelect, floors, '2. Select Floor');
        });

        floorSelect.addEventListener('change', function () {
            const selectedBlock = blockSelect.value;
            const selectedFloor = floorSelect.value;
            resetSelect(unitSelect, '3. Select Unit', true);
            updatePreview();

            if (!selectedBlock || !selectedFloor) {
                return;
            }

            resetSelect(unitSelect, '3. Select Unit', false);

            unitOptions
                .filter(function (unit) {
                    return unit.block_no === selectedBlock && String(unit.floor_no) === String(selectedFloor);
                })
                .sort(function (a, b) {
                    return String(a.unit_no).localeCompare(String(b.unit_no), undefined, {numeric: true, sensitivity: 'base'});
                })
                .forEach(function (unit) {
                    const option = document.createElement('option');
                    option.value = unit.id;
                    option.textContent = unit.unit_no;
                    unitSelect.appendChild(option);
                });
        });

        unitSelect.addEventListener('change', updatePreview);

        if (selectedUnit) {
            blockSelect.value = selectedUnit.block_no;
            blockSelect.dispatchEvent(new Event('change'));

            floorSelect.value = String(selectedUnit.floor_no);
            floorSelect.dispatchEvent(new Event('change'));

            unitSelect.value = String(selectedUnit.id);
            updatePreview();
        }
    }

    const safeForms = document.querySelectorAll('form[data-safe-confirm="1"]');

    safeForms.forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.dataset.confirmed === 'yes') {
                return;
            }

            event.preventDefault();

            if (!validateResidentFormats(null) || (form && !form.checkValidity())) {
                const invalidField = form.querySelector(':invalid');

                if (invalidField && invalidField.closest('[data-wizard-step="1"]')) {
                    showWizardStep(1);
                } else {
                    showWizardStep(2);
                }

                setTimeout(function () {
                    form.reportValidity();
                }, 50);

                return;
            }

            Swal.fire({
                icon: 'question',
                title: 'Create resident account?',
                text: 'Please confirm the resident details and verified unit are correct before creating this account.',
                showCancelButton: true,
                confirmButtonText: 'Yes, create account',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#64748b',
                reverseButtons: true
            }).then(function (result) {
                if (result.isConfirmed) {
                    let confirmInput = form.querySelector('input[name="confirm_save"]');

                    if (!confirmInput) {
                        confirmInput = document.createElement('input');
                        confirmInput.type = 'hidden';
                        confirmInput.name = 'confirm_save';
                        form.appendChild(confirmInput);
                    }

                    confirmInput.value = 'yes';
                    form.dataset.confirmed = 'yes';
                    form.submit();
                }
            });
        });
    });

    const profileMenu = document.getElementById('profileMenu');
    const profileTrigger = document.getElementById('profileTrigger');

    if (profileMenu && profileTrigger) {
        profileTrigger.addEventListener('click', function (event) {
            event.stopPropagation();
            profileMenu.classList.toggle('open');
        });

        document.addEventListener('click', function (event) {
            if (!profileMenu.contains(event.target)) {
                profileMenu.classList.remove('open');
            }
        });
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
});
</script>

</body>
</html>
