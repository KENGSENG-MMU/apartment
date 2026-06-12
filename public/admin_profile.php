<?php
require_once '../core/security.php';
require_login(['admin', 'superadmin']);

$pdo = db();

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return '<input type="hidden" name="csrf_token" value="' . e($_SESSION['csrf_token']) . '">';
    }
}

function ap_has_table(PDO $pdo, string $table): bool {
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

function ap_has_col(PDO $pdo, string $table, string $column): bool {
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

function ap_first_col(PDO $pdo, string $table, array $columns): ?string {
    foreach ($columns as $column) {
        if (ap_has_col($pdo, $table, $column)) {
            return $column;
        }
    }

    return null;
}

function ap_rows(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function ap_one(PDO $pdo, string $sql, array $params = []): ?array {
    $rows = ap_rows($pdo, $sql, $params);
    return $rows[0] ?? null;
}

function ap_dt($value): string {
    $ts = strtotime((string)$value);
    return $ts ? date('d M Y, g:i A', $ts) : '-';
}

function ap_value($value): string {
    return ($value !== null && $value !== '') ? (string)$value : '-';
}

$hasUsers = ap_has_table($pdo, 'users');
$nameCol = $hasUsers ? ap_first_col($pdo, 'users', ['full_name', 'name', 'username']) : null;
$contactCol = $hasUsers ? ap_first_col($pdo, 'users', ['contact_number', 'phone', 'mobile']) : null;
$statusCol = $hasUsers ? ap_first_col($pdo, 'users', ['status', 'account_status']) : null;
$createdCol = $hasUsers ? ap_first_col($pdo, 'users', ['created_at', 'date_created']) : null;
$apartmentCol = $hasUsers ? ap_first_col($pdo, 'users', ['apartment_id']) : null;
$passwordCol = $hasUsers ? ap_first_col($pdo, 'users', ['password_hash', 'password']) : null;
$mustChangeCol = $hasUsers ? ap_first_col($pdo, 'users', ['must_change_password']) : null;
$updatedCol = $hasUsers ? ap_first_col($pdo, 'users', ['updated_at', 'date_updated']) : null;

// Make sure admin profile can always save phone number for demo/project use.
if ($hasUsers && !$contactCol) {
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN contact_number VARCHAR(30) DEFAULT NULL");
        $contactCol = 'contact_number';
    } catch (Throwable $e) {
        // If database does not allow ALTER, the phone field will remain read-only.
    }
}

function ap_ensure_avatar_table(PDO $pdo): void {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS admin_profile_avatars (
                user_id INT NOT NULL PRIMARY KEY,
                avatar_path VARCHAR(255) NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // Avatar upload will be disabled if table cannot be created.
    }
}

function ap_avatar_path(PDO $pdo, int $userId): ?string {
    try {
        ap_ensure_avatar_table($pdo);
        $stmt = $pdo->prepare("SELECT avatar_path FROM admin_profile_avatars WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $path = $stmt->fetchColumn();
        return $path ? (string)$path : null;
    } catch (Throwable $e) {
        return null;
    }
}

function ap_set_avatar_path(PDO $pdo, int $userId, string $path): void {
    ap_ensure_avatar_table($pdo);
    $stmt = $pdo->prepare("
        INSERT INTO admin_profile_avatars (user_id, avatar_path, updated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE avatar_path = VALUES(avatar_path), updated_at = NOW()
    ");
    $stmt->execute([$userId, $path]);
}

function ap_remove_avatar_path(PDO $pdo, int $userId): void {
    try {
        ap_ensure_avatar_table($pdo);
        $stmt = $pdo->prepare("DELETE FROM admin_profile_avatars WHERE user_id = ?");
        $stmt->execute([$userId]);
    } catch (Throwable $e) {
        // ignore
    }
}

function ap_public_file_from_url_path(string $path): string {
    $path = ltrim(str_replace('\\', '/', $path), '/');
    return __DIR__ . '/' . $path;
}


$currentUserId = (int)($_SESSION['uid'] ?? $_SESSION['user_id'] ?? 0);
$currentEmail = (string)($_SESSION['email'] ?? '');
$currentRole = (string)($_SESSION['role'] ?? 'admin');
$currentApartmentId = $_SESSION['apartment_id'] ?? null;

$admin = null;

if ($currentUserId > 0 && $hasUsers) {
    $admin = ap_one($pdo, "SELECT * FROM users WHERE id = ? LIMIT 1", [$currentUserId]);
}

if (!$admin && $currentEmail !== '' && $hasUsers) {
    $admin = ap_one($pdo, "SELECT * FROM users WHERE email = ? LIMIT 1", [$currentEmail]);

    if ($admin && !empty($admin['id'])) {
        $currentUserId = (int)$admin['id'];
        $_SESSION['uid'] = $currentUserId;
        $_SESSION['user_id'] = $currentUserId;
    }
}

if (!$admin) {
    $admin = [
        'id' => $currentUserId,
        'email' => $currentEmail,
        'role' => $currentRole
    ];
}

if (($currentApartmentId === null || $currentApartmentId === '') && $apartmentCol && !empty($admin[$apartmentCol])) {
    $currentApartmentId = (int)$admin[$apartmentCol];
    $_SESSION['apartment_id'] = $currentApartmentId;
}

$apartmentName = 'No Apartment Assigned';
$apartmentAddress = '-';

if ($currentRole === 'superadmin') {
    $apartmentName = 'All Apartments';
    $apartmentAddress = 'Superadmin View';
} elseif (!empty($currentApartmentId) && ap_has_table($pdo, 'apartments')) {
    $aptNameCol = ap_first_col($pdo, 'apartments', ['apartment_name', 'name']);
    $aptAddressCol = ap_first_col($pdo, 'apartments', ['address', 'apartment_address', 'location']);

    $select = 'id';
    $select .= $aptNameCol ? ", `$aptNameCol` AS apartment_name" : ", CONCAT('Apartment ', id) AS apartment_name";
    $select .= $aptAddressCol ? ", `$aptAddressCol` AS apartment_address" : ", NULL AS apartment_address";

    $apt = ap_one($pdo, "SELECT $select FROM apartments WHERE id = ? LIMIT 1", [(int)$currentApartmentId]);

    if ($apt) {
        $apartmentName = ap_value($apt['apartment_name'] ?? null);
        $apartmentAddress = ap_value($apt['apartment_address'] ?? null);
    }
}

$message = $_SESSION['profile_success'] ?? '';
$error = $_SESSION['profile_error'] ?? '';
unset($_SESSION['profile_success'], $_SESSION['profile_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrf = $_POST['csrf_token'] ?? '';

        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
            throw new Exception('Invalid security token. Please refresh the page.');
        }

        if (!$hasUsers || $currentUserId <= 0) {
            throw new Exception('Admin account was not found.');
        }

        $action = $_POST['action'] ?? '';

        if ($action === 'upload_avatar') {
            if ($currentUserId <= 0) {
                throw new Exception('Admin account was not found.');
            }

            if (empty($_FILES['avatar_file']) || ($_FILES['avatar_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                throw new Exception('Please choose an avatar image.');
            }

            if (($_FILES['avatar_file']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                throw new Exception('Avatar upload failed. Please try again.');
            }

            if ((int)($_FILES['avatar_file']['size'] ?? 0) > 2 * 1024 * 1024) {
                throw new Exception('Avatar image must be smaller than 2MB.');
            }

            $tmpFile = (string)($_FILES['avatar_file']['tmp_name'] ?? '');
            $imageInfo = @getimagesize($tmpFile);

            if (!$imageInfo || empty($imageInfo['mime'])) {
                throw new Exception('Please upload a valid image file.');
            }

            $mime = strtolower((string)$imageInfo['mime']);
            $extMap = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif'
            ];

            if (!isset($extMap[$mime])) {
                throw new Exception('Avatar only supports JPG, PNG, WEBP or GIF.');
            }

            $uploadDir = __DIR__ . '/uploads/admin_profiles';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }

            $oldAvatar = ap_avatar_path($pdo, $currentUserId);
            $fileName = 'admin_' . $currentUserId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extMap[$mime];
            $targetFile = $uploadDir . '/' . $fileName;
            $relativePath = 'uploads/admin_profiles/' . $fileName;

            if (!move_uploaded_file($tmpFile, $targetFile)) {
                throw new Exception('Cannot save avatar image. Please check folder permission.');
            }

            ap_set_avatar_path($pdo, $currentUserId, $relativePath);

            if ($oldAvatar && $oldAvatar !== $relativePath) {
                $oldFile = ap_public_file_from_url_path($oldAvatar);
                if (is_file($oldFile)) {
                    @unlink($oldFile);
                }
            }

            if (function_exists('log_audit')) {
                log_audit('ADMIN_AVATAR_UPDATED', 'Admin updated profile avatar.');
            }

            $_SESSION['profile_success'] = 'Profile avatar updated successfully.';
            header('Location: admin_profile.php');
            exit;
        }

        if ($action === 'remove_avatar') {
            if ($currentUserId <= 0) {
                throw new Exception('Admin account was not found.');
            }

            $oldAvatar = ap_avatar_path($pdo, $currentUserId);
            ap_remove_avatar_path($pdo, $currentUserId);

            if ($oldAvatar) {
                $oldFile = ap_public_file_from_url_path($oldAvatar);
                if (is_file($oldFile)) {
                    @unlink($oldFile);
                }
            }

            if (function_exists('log_audit')) {
                log_audit('ADMIN_AVATAR_REMOVED', 'Admin removed profile avatar.');
            }

            $_SESSION['profile_success'] = 'Profile avatar removed successfully.';
            header('Location: admin_profile.php');
            exit;
        }

        if ($action === 'update_profile') {
            $sets = [];
            $params = [];

            $newName = trim($_POST['full_name'] ?? '');
            $newEmail = strtolower(trim($_POST['email'] ?? ''));
            $newContact = trim($_POST['contact_number'] ?? '');

            if ($nameCol) {
                if ($newName === '') {
                    throw new Exception('Please enter your full name.');
                }

                if (!preg_match("/^[A-Za-z@.'\\-\\s]{2,80}$/", $newName)) {
                    throw new Exception('Full name should only contain letters, spaces, @, dot, apostrophe or hyphen.');
                }

                $sets[] = "`$nameCol` = ?";
                $params[] = $newName;
            }

            if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }

            $exists = ap_one($pdo, "SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1", [$newEmail, $currentUserId]);
            if ($exists) {
                throw new Exception('This email is already used by another account.');
            }

            $sets[] = "email = ?";
            $params[] = $newEmail;

            if ($contactCol) {
                if ($newContact !== '' && !preg_match('/^01[0-9]-?[0-9]{7,8}$/', $newContact)) {
                    throw new Exception('Phone format must be like 011-58606387 or 01158606387.');
                }

                $sets[] = "`$contactCol` = ?";
                $params[] = $newContact ?: null;
            }

            if ($updatedCol) {
                $sets[] = "`$updatedCol` = NOW()";
            }

            if (!$sets) {
                throw new Exception('No editable profile fields found.');
            }

            $params[] = $currentUserId;
            $stmt = $pdo->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?");
            $stmt->execute($params);

            $_SESSION['email'] = $newEmail;

            if (function_exists('log_audit')) {
                log_audit('ADMIN_PROFILE_UPDATED', 'Admin updated profile information.');
            }

            $_SESSION['profile_success'] = 'Profile updated successfully.';
            header('Location: admin_profile.php');
            exit;
        }

        if ($action === 'change_password') {
            if (!$passwordCol) {
                throw new Exception('Password column was not found.');
            }

            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if ($currentPassword === '') {
                throw new Exception('Please enter your current password.');
            }

            $storedPassword = (string)($admin[$passwordCol] ?? '');
            $passwordOk = password_verify($currentPassword, $storedPassword);

            // Some older demo databases may store plain text passwords.
            if (!$passwordOk && hash_equals($storedPassword, $currentPassword)) {
                $passwordOk = true;
            }

            if (!$passwordOk) {
                throw new Exception('Current password is incorrect.');
            }

            if (strlen($newPassword) < 8) {
                throw new Exception('New password must be at least 8 characters.');
            }

            if (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword) || !preg_match('/[^A-Za-z0-9]/', $newPassword)) {
                throw new Exception('New password must include uppercase, lowercase, number and special character.');
            }

            if ($newPassword !== $confirmPassword) {
                throw new Exception('Confirm password does not match.');
            }

            $sets = ["`$passwordCol` = ?"];
            $params = [password_hash($newPassword, PASSWORD_DEFAULT)];

            if ($mustChangeCol) {
                $sets[] = "`$mustChangeCol` = 0";
            }

            if ($updatedCol) {
                $sets[] = "`$updatedCol` = NOW()";
            }

            $params[] = $currentUserId;

            $stmt = $pdo->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?");
            $stmt->execute($params);

            if (function_exists('log_audit')) {
                log_audit('ADMIN_PASSWORD_CHANGED', 'Admin changed account password.');
            }

            $_SESSION['profile_success'] = 'Password changed successfully.';
            header('Location: admin_profile.php');
            exit;
        }
    } catch (Throwable $e) {
        $_SESSION['profile_error'] = $e->getMessage();
        header('Location: admin_profile.php');
        exit;
    }
}

$displayName = $nameCol ? ap_value($admin[$nameCol] ?? null) : ap_value($admin['email'] ?? $currentEmail);
$displayEmail = ap_value($admin['email'] ?? $currentEmail);
$displayContact = $contactCol ? ap_value($admin[$contactCol] ?? null) : '-';
$displayStatus = $statusCol ? ap_value($admin[$statusCol] ?? null) : 'active';
$displayRole = ap_value($admin['role'] ?? $currentRole);
$createdAt = $createdCol ? ap_dt($admin[$createdCol] ?? null) : '-';

$profileInitial = strtoupper(substr(trim($displayName !== '-' ? $displayName : $displayEmail), 0, 1));
if ($profileInitial === '') {
    $profileInitial = 'A';
}

ap_ensure_avatar_table($pdo);
$avatarUrl = $currentUserId > 0 ? ap_avatar_path($pdo, $currentUserId) : null;
if ($avatarUrl && !is_file(ap_public_file_from_url_path($avatarUrl))) {
    $avatarUrl = null;
}

$appTitle = defined('APP_NAME') ? APP_NAME : 'SmartVMS';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Profile - <?= e($appTitle) ?></title>
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
            --green-soft: #dcfce7;
            --blue: #2563eb;
            --blue-soft: #dbeafe;
            --text: #0f172a;
            --muted: #64748b;
            --line: #e5e7eb;
            --shadow: 0 18px 45px rgba(15, 23, 42, .08);
            --shadow-soft: 0 10px 25px rgba(15, 23, 42, .06);
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
                radial-gradient(circle at 84% 4%, rgba(220, 38, 38, .13), transparent 28%),
                linear-gradient(135deg, #fff7f7 0%, #f4f6fb 45%, #eef2f7 100%);
            color: var(--text);
        }

        a { color: inherit; }

        .dashboard-shell {
            display: grid;
            grid-template-columns: 260px minmax(0, 1fr);
            height: 100vh;
            overflow: hidden;
        }

        .main {
            min-width: 0;
            height: 100vh;
            overflow: hidden;
            padding: 22px 30px 20px;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            min-height: 58px;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 14px;
            flex: 0 0 auto;
        }

        .page-kicker {
            color: var(--primary);
            font-size: .72rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .12em;
            margin-bottom: 5px;
        }

        .page-title {
            font-size: 1.85rem;
            line-height: 1.05;
            font-weight: 950;
            letter-spacing: -.07em;
        }

        .page-sub {
            margin-top: 7px;
            color: #475569;
            font-size: .88rem;
            font-weight: 800;
            line-height: 1.45;
        }

        .top-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .top-btn {
            height: 42px;
            border: 0;
            border-radius: 999px;
            padding: 0 16px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #475569;
            background: white;
            text-decoration: none;
            font-size: .82rem;
            font-weight: 950;
            box-shadow: var(--shadow-soft);
            border: 1px solid rgba(229,231,235,.95);
        }

        .top-btn.primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border-color: transparent;
            box-shadow: 0 14px 28px rgba(220,38,38,.22);
        }

        .profile-layout {
            flex: 1 1 auto;
            min-height: 0;
            display: grid;
            grid-template-columns: 390px minmax(0, 1fr);
            gap: 18px;
            overflow: hidden;
        }

        .panel {
            min-height: 0;
            border: 1px solid rgba(229,231,235,.95);
            border-radius: 26px;
            background: rgba(255,255,255,.96);
            box-shadow: var(--shadow);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .panel-head {
            height: 56px;
            padding: 0 20px;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex: 0 0 auto;
        }

        .panel-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 950;
            letter-spacing: -.04em;
        }

        .panel-title i {
            color: var(--primary);
        }

        .panel-body {
            padding: 20px;
            flex: 1 1 auto;
            min-height: 0;
        }

        .summary-card {
            text-align: center;
            padding: 26px 20px 20px;
            border-bottom: 1px solid var(--line);
            background:
                radial-gradient(circle at 30% 10%, rgba(254,202,202,.65), transparent 28%),
                linear-gradient(135deg, #fff, #fff7f7);
        }

        .big-avatar {
            width: 132px;
            height: 132px;
            border-radius: 34px;
            margin: 0 auto 14px;
            display: grid;
            place-items: center;
            color: white;
            font-size: 3rem;
            font-weight: 950;
            background:
                radial-gradient(circle at 30% 25%, rgba(255,255,255,.75), transparent 22%),
                linear-gradient(135deg, var(--primary), var(--primary-dark));
            box-shadow: 0 22px 42px rgba(220,38,38,.22);
            position: relative;
            overflow: hidden;
        }

        .big-avatar::before {
            content: "";
            position: absolute;
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: rgba(255,255,255,.45);
            filter: blur(8px);
            left: 14px;
            top: 14px;
        }

        .big-avatar span {
            position: relative;
            z-index: 2;
        }

        .admin-name {
            font-size: 1.2rem;
            font-weight: 950;
            letter-spacing: -.04em;
        }

        .admin-email {
            margin-top: 5px;
            color: #64748b;
            font-size: .86rem;
            font-weight: 850;
            word-break: break-word;
        }

        .role-pill {
            margin: 14px auto 0;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            height: 34px;
            padding: 0 13px;
            border-radius: 999px;
            background: var(--primary-soft);
            color: var(--primary);
            font-size: .72rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .detail-list {
            padding: 18px 20px 20px;
            display: grid;
            gap: 10px;
        }

        .detail-line {
            min-height: 48px;
            display: grid;
            grid-template-columns: 28px 1fr;
            gap: 10px;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #eef2f7;
        }

        .detail-line:last-child {
            border-bottom: 0;
        }

        .detail-line i {
            color: #94a3b8;
            text-align: center;
        }

        .detail-label {
            color: #64748b;
            font-size: .72rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .detail-value {
            margin-top: 3px;
            color: #0f172a;
            font-size: .86rem;
            font-weight: 950;
            word-break: break-word;
        }

        .right-grid {
            min-height: 0;
            display: grid;
            grid-template-rows: auto 1fr;
            gap: 18px;
            overflow: hidden;
        }

        .mini-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .mini-card {
            border: 1px solid rgba(229,231,235,.95);
            background: rgba(255,255,255,.96);
            border-radius: 22px;
            box-shadow: var(--shadow-soft);
            padding: 16px;
            min-height: 88px;
            display: flex;
            align-items: center;
            gap: 13px;
        }

        .mini-icon {
            width: 42px;
            height: 42px;
            border-radius: 16px;
            display: grid;
            place-items: center;
            background: var(--primary-soft);
            color: var(--primary);
            flex: 0 0 auto;
        }

        .mini-icon.blue {
            background: var(--blue-soft);
            color: var(--blue);
        }

        .mini-icon.green {
            background: var(--green-soft);
            color: var(--green);
        }

        .mini-title {
            font-size: .72rem;
            color: #64748b;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .mini-value {
            margin-top: 4px;
            font-size: .9rem;
            font-weight: 950;
            color: #0f172a;
            word-break: break-word;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            min-height: 0;
        }

        .form-card {
            min-height: 0;
            border: 1px solid var(--line);
            border-radius: 22px;
            overflow: hidden;
            background: white;
            display: flex;
            flex-direction: column;
        }

        .form-card-head {
            height: 52px;
            padding: 0 18px;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 950;
        }

        .form-card-head i {
            color: var(--primary);
        }

        .form-body {
            padding: 18px;
            display: grid;
            gap: 13px;
        }

        label {
            display: block;
            color: #64748b;
            font-size: .68rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 7px;
        }

        input {
            width: 100%;
            height: 44px;
            border: 1px solid var(--line);
            border-radius: 15px;
            background: #fff;
            outline: none;
            padding: 0 14px;
            color: #0f172a;
            font-size: .84rem;
            font-weight: 850;
        }

        input:focus {
            border-color: #fca5a5;
            box-shadow: 0 0 0 4px #fee2e2;
        }

        input[readonly] {
            background: #f8fafc;
            color: #94a3b8;
        }

        .hint {
            color: #64748b;
            font-size: .72rem;
            font-weight: 800;
            line-height: 1.45;
        }

        .btn {
            height: 44px;
            border: 0;
            border-radius: 15px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-weight: 950;
            font-size: .82rem;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-primary {
            color: white;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            box-shadow: 0 14px 24px rgba(220,38,38,.20);
        }

        .btn-light {
            color: #334155;
            background: #f8fafc;
            border: 1px solid var(--line);
        }

        .alert {
            border-radius: 18px;
            padding: 13px 15px;
            margin-bottom: 12px;
            font-size: .82rem;
            font-weight: 850;
            display: flex;
            gap: 9px;
            align-items: center;
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


        .avatar-form {
            margin-top: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .avatar-upload-btn,
        .avatar-remove-btn {
            height: 34px;
            border-radius: 999px;
            border: 1px solid #fecaca;
            padding: 0 13px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            font-size: .72rem;
            font-weight: 950;
            cursor: pointer;
            background: white;
            color: var(--primary);
            box-shadow: var(--shadow-soft);
        }

        .avatar-remove-btn {
            background: #fff1f2;
            color: #991b1b;
        }

        .big-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .security-open-card {
            border: 1px solid var(--line);
            border-radius: 22px;
            background:
                radial-gradient(circle at 85% 18%, rgba(220,38,38,.10), transparent 30%),
                linear-gradient(135deg, #ffffff, #fff7f7);
            padding: 22px;
            display: grid;
            align-content: center;
            gap: 13px;
            min-height: 100%;
        }

        .security-open-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1rem;
            font-weight: 950;
            letter-spacing: -.035em;
        }

        .security-open-title i {
            color: var(--primary);
        }

        .security-open-text {
            color: #64748b;
            font-size: .8rem;
            font-weight: 800;
            line-height: 1.45;
        }

        .password-modal {
            position: fixed;
            inset: 0;
            z-index: 3000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(15,23,42,.48);
            backdrop-filter: blur(7px);
        }

        .password-modal.show {
            display: flex;
        }

        .password-modal-card {
            width: min(500px, 94vw);
            border: 1px solid var(--line);
            border-radius: 24px;
            background: #ffffff;
            box-shadow: 0 28px 70px rgba(15,23,42,.26);
            overflow: hidden;
        }

        .password-modal-card .form-card-head {
            justify-content: space-between;
        }

        .password-modal-close {
            width: 34px;
            height: 34px;
            border: 0;
            border-radius: 12px;
            background: #fff1f2;
            color: var(--primary);
            cursor: pointer;
            display: grid;
            place-items: center;
        }

        @media (max-width: 1180px) {
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

            .main {
                height: auto;
                overflow: visible;
            }

            .profile-layout,
            .right-grid,
            .form-grid {
                grid-template-columns: 1fr;
                grid-template-rows: auto;
                overflow: visible;
            }
        }

        @media (max-width: 760px) {
            .main {
                padding: 20px 16px 36px;
            }

            .topbar {
                flex-direction: column;
                align-items: flex-start;
            }

            .top-actions,
            .top-btn {
                width: 100%;
                justify-content: center;
            }

            .mini-grid {
                grid-template-columns: 1fr;
            }

            .profile-layout {
                gap: 14px;
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
                <div class="page-kicker">Admin Account</div>
                <h1 class="page-title">Admin Profile</h1>
                <p class="page-sub">
                    View and update your admin account information, contact details and login password.
                </p>
            </div>

            <div class="top-actions">
                <a href="admin_dashboard.php" class="top-btn primary">
                    <i class="fas fa-arrow-left"></i>
                    Dashboard
                </a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert success">
                <i class="fas fa-check-circle"></i>
                <?= e($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert error">
                <i class="fas fa-circle-exclamation"></i>
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <section class="profile-layout">
            <aside class="panel">
                <div class="summary-card">
                    <div class="big-avatar">
                        <?php if ($avatarUrl): ?>
                            <img src="<?= e($avatarUrl) ?>?v=<?= time() ?>" alt="Admin avatar">
                        <?php else: ?>
                            <span><?= e($profileInitial) ?></span>
                        <?php endif; ?>
                    </div>

                    <form method="POST" enctype="multipart/form-data" class="avatar-form" id="avatarUploadForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="upload_avatar">
                        <input type="file" name="avatar_file" id="avatarFile" accept="image/png,image/jpeg,image/webp,image/gif" hidden>
                        <label for="avatarFile" class="avatar-upload-btn">
                            <i class="fas fa-camera"></i>
                            Change Avatar
                        </label>
                    </form>

                    <?php if ($avatarUrl): ?>
                        <form method="POST" class="avatar-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="remove_avatar">
                            <button type="submit" class="avatar-remove-btn">
                                <i class="fas fa-trash"></i>
                                Remove
                            </button>
                        </form>
                    <?php endif; ?>

                    <div class="admin-name"><?= e($displayName) ?></div>
                    <div class="admin-email"><?= e($displayEmail) ?></div>
                    <div class="role-pill">
                        <i class="fas fa-user-shield"></i>
                        <?= e($displayRole) ?>
                    </div>
                </div>

                <div class="detail-list">
                    <div class="detail-line">
                        <i class="fas fa-envelope"></i>
                        <div>
                            <div class="detail-label">Email</div>
                            <div class="detail-value"><?= e($displayEmail) ?></div>
                        </div>
                    </div>

                    <div class="detail-line">
                        <i class="fas fa-phone"></i>
                        <div>
                            <div class="detail-label">Phone</div>
                            <div class="detail-value"><?= e($displayContact) ?></div>
                        </div>
                    </div>

                    <div class="detail-line">
                        <i class="fas fa-building"></i>
                        <div>
                            <div class="detail-label">Apartment</div>
                            <div class="detail-value"><?= e($apartmentName) ?></div>
                        </div>
                    </div>

                    <div class="detail-line">
                        <i class="fas fa-circle-check"></i>
                        <div>
                            <div class="detail-label">Account Status</div>
                            <div class="detail-value"><?= e(ucfirst($displayStatus)) ?></div>
                        </div>
                    </div>

                    <div class="detail-line">
                        <i class="fas fa-calendar-plus"></i>
                        <div>
                            <div class="detail-label">Created At</div>
                            <div class="detail-value"><?= e($createdAt) ?></div>
                        </div>
                    </div>
                </div>
            </aside>

            <div class="right-grid">
                <div class="mini-grid">
                    <div class="mini-card">
                        <div class="mini-icon">
                            <i class="fas fa-user-gear"></i>
                        </div>
                        <div>
                            <div class="mini-title">Role</div>
                            <div class="mini-value"><?= e(ucfirst($displayRole)) ?></div>
                        </div>
                    </div>

                    <div class="mini-card">
                        <div class="mini-icon blue">
                            <i class="fas fa-building"></i>
                        </div>
                        <div>
                            <div class="mini-title">Apartment</div>
                            <div class="mini-value"><?= e($apartmentName) ?></div>
                        </div>
                    </div>

                    <div class="mini-card">
                        <div class="mini-icon green">
                            <i class="fas fa-shield-halved"></i>
                        </div>
                        <div>
                            <div class="mini-title">Security</div>
                            <div class="mini-value">Password Protected</div>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-head">
                        <div class="panel-title">
                            <i class="fas fa-pen-to-square"></i>
                            Profile Settings
                        </div>
                    </div>

                    <div class="panel-body">
                        <div class="form-grid">
                            <form method="POST" class="form-card" autocomplete="off">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_profile">

                                <div class="form-card-head">
                                    <i class="fas fa-address-card"></i>
                                    Edit Information
                                </div>

                                <div class="form-body">
                                    <div>
                                        <label>Full Name</label>
                                        <input
                                            type="text"
                                            name="full_name"
                                            value="<?= e($nameCol ? ($admin[$nameCol] ?? '') : $displayName) ?>"
                                            <?= $nameCol ? 'required' : 'readonly' ?>
                                        >
                                    </div>

                                    <div>
                                        <label>Email</label>
                                        <input
                                            type="email"
                                            name="email"
                                            value="<?= e($displayEmail) ?>"
                                            required
                                        >
                                    </div>

                                    <div>
                                        <label>Phone Number</label>
                                        <input
                                            type="text"
                                            name="contact_number"
                                            placeholder="Example: 011-58606387"
                                            value="<?= e($contactCol ? ($admin[$contactCol] ?? '') : '') ?>"
                                            <?= $contactCol ? '' : 'readonly' ?>
                                        >
                                    </div>

                                    <div class="hint">
                                        Updating email will also update your current login session email.
                                    </div>

                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i>
                                        Save Profile
                                    </button>
                                </div>
                            </form>

                            <div class="security-open-card">
                                <div class="security-open-title">
                                    <i class="fas fa-lock"></i>
                                    Change Password
                                </div>
                                <div class="security-open-text">
                                    Password form is hidden by default for privacy. Click the button below when you want to update your login password.
                                </div>
                                <button type="button" class="btn btn-primary" id="openPasswordModal">
                                    <i class="fas fa-key"></i>
                                    Open Password Form
                                </button>
                            </div>

                            <div class="password-modal" id="passwordModal" aria-hidden="true">
                            <form method="POST" class="password-modal-card" autocomplete="off">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="change_password">

                                <div class="form-card-head">
                                    <span><i class="fas fa-lock"></i> Change Password</span>
                                    <button type="button" class="password-modal-close" id="closePasswordModal" aria-label="Close password form">
                                        <i class="fas fa-xmark"></i>
                                    </button>
                                </div>

                                <div class="form-body">
                                    <div>
                                        <label>Current Password</label>
                                        <input
                                            type="password"
                                            name="current_password"
                                            placeholder="Enter current password"
                                            <?= $passwordCol ? 'required' : 'readonly' ?>
                                        >
                                    </div>

                                    <div>
                                        <label>New Password</label>
                                        <input
                                            type="password"
                                            name="new_password"
                                            placeholder="At least 8 characters"
                                            <?= $passwordCol ? 'required' : 'readonly' ?>
                                        >
                                    </div>

                                    <div>
                                        <label>Confirm Password</label>
                                        <input
                                            type="password"
                                            name="confirm_password"
                                            placeholder="Re-enter new password"
                                            <?= $passwordCol ? 'required' : 'readonly' ?>
                                        >
                                    </div>

                                    <div class="hint">
                                        Password must include uppercase, lowercase, number and special character.
                                    </div>

                                    <button type="submit" class="btn btn-primary" <?= $passwordCol ? '' : 'disabled' ?>>
                                        <i class="fas fa-key"></i>
                                        Update Password
                                    </button>
                                </div>
                            </form>
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
setTimeout(function () {
    const alertBox = document.querySelector('.alert.success');
    if (alertBox) {
        alertBox.style.opacity = '0';
        alertBox.style.transform = 'translateY(-8px)';
    }
}, 3000);
</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const passwordForms = document.querySelectorAll('form');

    passwordForms.forEach(function (form) {
        form.addEventListener('submit', function () {
            const btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.style.opacity = '.75';
            }
        });
    });

    const avatarFile = document.getElementById('avatarFile');
    const avatarUploadForm = document.getElementById('avatarUploadForm');
    if (avatarFile && avatarUploadForm) {
        avatarFile.addEventListener('change', function () {
            if (avatarFile.files && avatarFile.files.length > 0) {
                avatarUploadForm.submit();
            }
        });
    }

    const passwordModal = document.getElementById('passwordModal');
    const openPasswordModal = document.getElementById('openPasswordModal');
    const closePasswordModal = document.getElementById('closePasswordModal');

    function setPasswordModal(open) {
        if (!passwordModal) {
            return;
        }
        passwordModal.classList.toggle('show', open);
        passwordModal.setAttribute('aria-hidden', open ? 'false' : 'true');
    }

    if (openPasswordModal) {
        openPasswordModal.addEventListener('click', function () {
            setPasswordModal(true);
        });
    }

    if (closePasswordModal) {
        closePasswordModal.addEventListener('click', function () {
            setPasswordModal(false);
        });
    }

    if (passwordModal) {
        passwordModal.addEventListener('click', function (event) {
            if (event.target === passwordModal) {
                setPasswordModal(false);
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            setPasswordModal(false);
        }
    });
});
</script>
</body>
</html>
