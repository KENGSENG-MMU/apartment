<?php
require_once '../core/security.php';
require_login(['visitor']);

$pdo = db();

$visitorId = (int)($_SESSION['uid'] ?? 0);
$visitorEmail = $_SESSION['email'] ?? '';

$message = '';
$error = '';

function table_exists_profile(PDO $pdo, string $table): bool {
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

function has_column_profile(PDO $pdo, string $table, string $column): bool {
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

function ensure_column_profile(PDO $pdo, string $table, string $column, string $definition): void {
    if (!table_exists_profile($pdo, $table)) {
        return;
    }

    if (!has_column_profile($pdo, $table, $column)) {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (Throwable $e) {
            // Ignore if database does not allow ALTER.
        }
    }
}

function safe_count_profile(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function validate_password_profile(string $password): ?string {
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters.';
    }

    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must contain at least one uppercase letter.';
    }

    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must contain at least one lowercase letter.';
    }

    if (!preg_match('/[0-9]/', $password)) {
        return 'Password must contain at least one number.';
    }

    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password must contain at least one special character.';
    }

    return null;
}

function visitor_profile_photo_url(?string $photo): string {
    if (!$photo) {
        return '';
    }

    $photo = trim((string)$photo);

    if ($photo === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $photo)) {
        return $photo;
    }

    $photo = ltrim($photo, '/');

    if (file_exists(__DIR__ . '/' . $photo)) {
        return $photo;
    }

    return '';
}

function delete_visitor_profile_photo_file(?string $photo): void {
    if (!$photo) {
        return;
    }

    $photo = ltrim((string)$photo, '/');

    if ($photo === '' || str_starts_with($photo, 'http')) {
        return;
    }

    $fullPath = __DIR__ . '/' . $photo;

    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

/*
|--------------------------------------------------------------------------
| Database compatibility
|--------------------------------------------------------------------------
*/
ensure_column_profile($pdo, 'users', 'full_name', 'VARCHAR(150) NULL AFTER email');
ensure_column_profile($pdo, 'users', 'contact_number', 'VARCHAR(30) NULL AFTER full_name');
ensure_column_profile($pdo, 'users', 'profile_photo', 'VARCHAR(255) NULL AFTER contact_number');
ensure_column_profile($pdo, 'users', 'updated_at', 'DATETIME NULL');

$hasFullName = has_column_profile($pdo, 'users', 'full_name');
$hasContact = has_column_profile($pdo, 'users', 'contact_number');
$hasProfilePhoto = has_column_profile($pdo, 'users', 'profile_photo');
$hasUpdatedAt = has_column_profile($pdo, 'users', 'updated_at');

$passwordColumn = null;

if (has_column_profile($pdo, 'users', 'password_hash')) {
    $passwordColumn = 'password_hash';
} elseif (has_column_profile($pdo, 'users', 'password')) {
    $passwordColumn = 'password';
}

$nameSql = $hasFullName ? "full_name" : "NULL AS full_name";
$contactSql = $hasContact ? "contact_number" : "NULL AS contact_number";
$photoSql = $hasProfilePhoto ? "profile_photo" : "NULL AS profile_photo";

$stmt = $pdo->prepare("
    SELECT
        id,
        email,
        role,
        {$nameSql},
        {$contactSql},
        {$photoSql}
    FROM users
    WHERE id = ?
    AND role = 'visitor'
    LIMIT 1
");
$stmt->execute([$visitorId]);
$visitor = $stmt->fetch();

if (!$visitor) {
    header('Location: ../core/logout.php');
    exit;
}

$currentName = $visitor['full_name'] ?: explode('@', $visitorEmail)[0];
$currentContact = $visitor['contact_number'] ?: '';
$visitorProfilePhoto = visitor_profile_photo_url($visitor['profile_photo'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        try {
            $action = $_POST['action'] ?? '';

            if ($action === 'update_profile') {
                $fullName = trim($_POST['full_name'] ?? '');
                $contactNumber = trim($_POST['contact_number'] ?? '');

                if ($fullName === '') {
                    throw new Exception('Please enter your full name.');
                }

                if ($contactNumber === '') {
                    throw new Exception('Please enter your phone number.');
                }

                $fields = [];
                $values = [];

                if ($hasFullName) {
                    $fields[] = 'full_name = ?';
                    $values[] = $fullName;
                }

                if ($hasContact) {
                    $fields[] = 'contact_number = ?';
                    $values[] = $contactNumber;
                }

                if ($hasUpdatedAt) {
                    $fields[] = 'updated_at = NOW()';
                }

                if (!empty($fields)) {
                    $values[] = $visitorId;

                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET " . implode(', ', $fields) . "
                        WHERE id = ?
                    ");
                    $stmt->execute($values);
                }

                if (isset($_SESSION['name'])) {
                    $_SESSION['name'] = $fullName;
                }

                $message = 'Profile updated successfully.';
            } elseif ($action === 'upload_photo') {
                if (!$hasProfilePhoto) {
                    throw new Exception('Profile photo column is missing. Please run: ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) NULL;');
                }

                if (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Please choose a valid image.');
                }

                $file = $_FILES['profile_photo'];

                if ($file['size'] > 2 * 1024 * 1024) {
                    throw new Exception('Photo must be less than 2MB.');
                }

                $tmp = $file['tmp_name'];
                $info = @getimagesize($tmp);

                if (!$info) {
                    throw new Exception('Uploaded file must be an image.');
                }

                $allowed = [
                    IMAGETYPE_JPEG => 'jpg',
                    IMAGETYPE_PNG => 'png',
                    IMAGETYPE_WEBP => 'webp'
                ];

                if (!isset($allowed[$info[2]])) {
                    throw new Exception('Only JPG, PNG and WEBP images are allowed.');
                }

                $uploadDir = __DIR__ . '/uploads/profiles';

                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0775, true);
                }

                $ext = $allowed[$info[2]];
                $fileName = 'visitor_' . $visitorId . '_' . time() . '.' . $ext;
                $relativePath = 'uploads/profiles/' . $fileName;
                $targetPath = $uploadDir . '/' . $fileName;

                if (!move_uploaded_file($tmp, $targetPath)) {
                    throw new Exception('Failed to upload profile photo.');
                }

                delete_visitor_profile_photo_file($visitor['profile_photo'] ?? null);

                $fields = ['profile_photo = ?'];
                $values = [$relativePath];

                if ($hasUpdatedAt) {
                    $fields[] = 'updated_at = NOW()';
                }

                $values[] = $visitorId;

                $stmt = $pdo->prepare("
                    UPDATE users
                    SET " . implode(', ', $fields) . "
                    WHERE id = ?
                ");
                $stmt->execute($values);

                $message = 'Profile photo updated successfully.';
            } elseif ($action === 'remove_photo') {
                if ($hasProfilePhoto) {
                    delete_visitor_profile_photo_file($visitor['profile_photo'] ?? null);

                    $fields = ['profile_photo = NULL'];

                    if ($hasUpdatedAt) {
                        $fields[] = 'updated_at = NOW()';
                    }

                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET " . implode(', ', $fields) . "
                        WHERE id = ?
                    ");
                    $stmt->execute([$visitorId]);
                }

                $message = 'Profile photo removed successfully.';
            } elseif ($action === 'change_password') {
                if (!$passwordColumn) {
                    throw new Exception('Password column was not found in users table.');
                }

                $currentPassword = $_POST['current_password'] ?? '';
                $newPassword = $_POST['new_password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';

                if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
                    throw new Exception('Please fill in all password fields.');
                }

                $stmt = $pdo->prepare("
                    SELECT {$passwordColumn}
                    FROM users
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmt->execute([$visitorId]);
                $hash = (string)$stmt->fetchColumn();

                if (!password_verify($currentPassword, $hash)) {
                    throw new Exception('Current password is incorrect.');
                }

                if ($newPassword !== $confirmPassword) {
                    throw new Exception('New password and confirm password do not match.');
                }

                $passwordError = validate_password_profile($newPassword);

                if ($passwordError) {
                    throw new Exception($passwordError);
                }

                $fields = [
                    "{$passwordColumn} = ?"
                ];

                $values = [
                    password_hash($newPassword, PASSWORD_DEFAULT)
                ];

                if ($hasUpdatedAt) {
                    $fields[] = 'updated_at = NOW()';
                }

                $values[] = $visitorId;

                $stmt = $pdo->prepare("
                    UPDATE users
                    SET " . implode(', ', $fields) . "
                    WHERE id = ?
                ");
                $stmt->execute($values);

                $message = 'Password changed successfully.';
            } else {
                throw new Exception('Invalid action.');
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    $_SESSION['visitor_profile_flash'] = [
        'message' => $message,
        'error' => $error
    ];

    header('Location: visitor_profile.php');
    exit;
}

if (isset($_SESSION['visitor_profile_flash']) && is_array($_SESSION['visitor_profile_flash'])) {
    $message = $_SESSION['visitor_profile_flash']['message'] ?? '';
    $error = $_SESSION['visitor_profile_flash']['error'] ?? '';
    unset($_SESSION['visitor_profile_flash']);
}

$stmt = $pdo->prepare("
    SELECT
        id,
        email,
        role,
        {$nameSql},
        {$contactSql},
        {$photoSql}
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$visitorId]);
$visitor = $stmt->fetch();

$currentName = $visitor['full_name'] ?: explode('@', $visitorEmail)[0];
$currentContact = $visitor['contact_number'] ?: '';
$visitorProfilePhoto = visitor_profile_photo_url($visitor['profile_photo'] ?? '');
$visitorInitial = strtoupper(substr($currentName ?: 'V', 0, 1));
$initial = $visitorInitial;
$defaultVisitorName = $currentName;

$totalVisits = safe_count_profile($pdo, "SELECT COUNT(*) FROM bookings WHERE visitor_user_id = ?", [$visitorId]);
$pendingVisits = safe_count_profile($pdo, "SELECT COUNT(*) FROM bookings WHERE visitor_user_id = ? AND status = 'pending'", [$visitorId]);
$activeVisits = safe_count_profile($pdo, "
    SELECT COUNT(*)
    FROM bookings
    WHERE visitor_user_id = ?
    AND status IN ('approved', 'allocated', 'waiting', 'checked_in')
", [$visitorId]);
$completedVisits = safe_count_profile($pdo, "
    SELECT COUNT(*)
    FROM bookings
    WHERE visitor_user_id = ?
    AND status IN ('completed', 'checked_out', 'closed')
", [$visitorId]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Visitor Profile - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --bg: #eef3f8;
            --card: rgba(255,255,255,.96);
            --text: #0f172a;
            --muted: #64748b;
            --border: #dbe5f0;
            --blue: #2563eb;
            --blue-soft: #eff6ff;
            --green: #16a34a;
            --red: #dc2626;
            --header: #1e293b;
            --shadow: 0 18px 42px rgba(15, 23, 42, .08);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', sans-serif; }
        body {
            min-height: 100vh;
            color: var(--text);
            background:
                radial-gradient(circle at 10% 20%, rgba(191, 219, 254, .46), transparent 10%),
                radial-gradient(circle at 88% 24%, rgba(219, 234, 254, .46), transparent 11%),
                radial-gradient(circle at 14% 86%, rgba(203, 213, 225, .34), transparent 9%),
                radial-gradient(circle at 83% 86%, rgba(191, 219, 254, .26), transparent 9%),
                linear-gradient(180deg, #f8fbff 0%, var(--bg) 100%);
            overflow-x: hidden;
        }
        a { text-decoration: none; }
        .cute-scene { position: fixed; inset: 0; pointer-events: none; z-index: 0; overflow: hidden; }
        .cloud { position: absolute; width: 92px; height: 30px; border: 2px solid #dbe7f3; border-radius: 999px; background: rgba(255,255,255,.72); }
        .cloud:before, .cloud:after { content:""; position:absolute; background:rgba(255,255,255,.92); border:2px solid #dbe7f3; border-bottom:none; border-radius:999px 999px 0 0; }
        .cloud:before { width:36px; height:28px; left:14px; top:-18px; }
        .cloud:after { width:46px; height:34px; right:14px; top:-24px; }
        .cloud-left { left: 6%; top: 22%; }
        .cloud-right { right: 12%; top: 18%; transform: scale(.85); }
        .sparkle { position: absolute; color: #f6c55d; opacity:.78; font-size:1.2rem; animation: floatSparkle 4s ease-in-out infinite; }
        .sp1 { left:16%; top: 35%; } .sp2 { right:18%; top: 45%; color:#9fc5ff; animation-delay:.8s; }
        .sp3 { left:10%; bottom: 17%; animation-delay:1.4s; } .sp4 { right:15%; bottom: 22%; color:#cbd5e1; animation-delay:2s; }
        @keyframes floatSparkle { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-8px); } }
        .cute-bush { position:absolute; right:8%; bottom:4%; width:136px; height:72px; }
        .cute-bush span { position:absolute; bottom:0; border-radius:999px 999px 18px 18px; background:#cfe9c7; border:2px solid #a7d19b; }
        .cute-bush span:nth-child(1){ width:58px; height:44px; left:0; }
        .cute-bush span:nth-child(2){ width:84px; height:62px; left:34px; }
        .cute-bush span:nth-child(3){ width:48px; height:38px; right:0; }

        .visitor-navbar {
            width:100%; height:64px; padding:0 5%; background:var(--header); color:#e5e7eb; border-bottom:1px solid rgba(255,255,255,.08);
            box-shadow:0 10px 28px rgba(15,23,42,.16); display:flex; align-items:center; justify-content:space-between; gap:18px; position:sticky; top:0; z-index:100;
        }
        .logo { font-size:1.3rem; font-weight:900; letter-spacing:-.045em; color:#fff; }
        .logo span { color:#3b82f6; }
        .nav-links { display:flex; align-items:center; justify-content:flex-end; gap:10px; flex-wrap:wrap; }
        .nav-links > a { color:#e5e7eb; background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.08); padding:8px 13px; border-radius:14px; font-size:.78rem; font-weight:900; display:inline-flex; align-items:center; gap:7px; transition:.18s ease; }
        .nav-links > a:hover { background:rgba(255,255,255,.07); transform:translateY(-1px); }
        .nav-links > a.active { background:rgba(59,130,246,.18); border-color:rgba(96,165,250,.45); color:#fff; }

        .page { width:min(1000px, calc(100% - 36px)); margin: 34px auto 70px; position:relative; z-index:1; }
        .title-box { display:flex; align-items:center; gap:18px; margin-bottom:20px; }
        .title-sticker { width:66px; height:66px; border-radius:20px; background:#fff5ea; border:2px solid #f3d0ae; display:flex; align-items:center; justify-content:center; color:#7b8794; font-size:1.45rem; transform:rotate(-8deg); box-shadow:0 14px 28px rgba(148,163,184,.14); position:relative; }
        .title-sticker:after { content:"♡"; position:absolute; right:-11px; bottom:-12px; color:#fb8ca8; font-size:1.9rem; }
        .page-title { font-size:clamp(2.05rem, 3.5vw, 2.9rem); font-weight:900; letter-spacing:-.07em; line-height:1.05; margin-bottom:8px; }
        .page-sub { color:#677489; font-size:.98rem; font-weight:760; line-height:1.55; }
        .alert { padding:14px 15px; border-radius:16px; margin-bottom:16px; font-weight:850; line-height:1.45; }
        .alert.success { background:#ecfdf3; color:#027a48; border:1px solid #abefc6; }
        .alert.error { background:#fef3f2; color:#b42318; border:1px solid #fecdca; }

        .profile-grid { display:grid; grid-template-columns: .88fr 1.12fr; gap:18px; align-items:start; }
        .card { background:var(--card); border:1px solid var(--border); border-radius:28px; box-shadow:var(--shadow); overflow:hidden; }
        .card-head { padding:18px 22px; border-bottom:1px solid #edf0f3; font-weight:900; display:flex; align-items:center; gap:10px; }
        .card-body { padding:22px; }

        .profile-card { padding:28px 22px; text-align:center; position:relative; overflow:hidden; }
        .profile-card:after {
            content:""; position:absolute; right:-16px; top:-12px; width:120px; height:120px; border-radius:50%;
            background: radial-gradient(circle, rgba(191,219,254,.56) 0%, rgba(191,219,254,.22) 48%, transparent 70%);
        }
        .avatar {
            width:104px; height:104px; border-radius:50%; margin:0 auto 16px; display:flex; align-items:center; justify-content:center;
            background:linear-gradient(135deg,#e9f2ff,#cfe1ff); color:var(--blue); font-size:2rem; font-weight:900; border:6px solid #f6f9fd;
            box-shadow:0 16px 28px rgba(148,163,184,.16); position:relative; z-index:1; overflow:hidden;
        }
        .avatar img { width:100%; height:100%; object-fit:cover; }
        .profile-name { font-size:1.15rem; font-weight:900; margin-bottom:4px; }
        .profile-email { color:var(--muted); font-size:.88rem; font-weight:780; margin-bottom:10px; }
        .profile-badge { display:inline-flex; align-items:center; gap:7px; padding:7px 12px; border-radius:999px; background:#ecfdf3; color:#15803d; border:1px solid #bbf7d0; font-size:.74rem; font-weight:900; margin-bottom:16px; }
        .photo-actions { display:grid; gap:9px; margin: 0 auto 18px; max-width:240px; }
        .hidden-file { display:none; }
        .mini-stats { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .mini-stat { background:#f8fafc; border:1px solid var(--border); border-radius:16px; padding:14px 12px; text-align:left; }
        .mini-stat-num { font-size:1.35rem; font-weight:900; letter-spacing:-.05em; margin-bottom:3px; }
        .mini-stat-label { font-size:.66rem; font-weight:900; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); }

        .fields-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .field { margin-bottom: 12px; }
        .field.full { grid-column: 1 / -1; }
        label { display:block; font-size:.68rem; font-weight:900; color:var(--muted); text-transform:uppercase; letter-spacing:.07em; margin-bottom:7px; }
        input {
            width:100%; padding:12px 13px; border:1px solid var(--border); border-radius:15px; background:#fff; color:var(--text);
            font-weight:800; outline:none;
        }
        input[readonly] { background:#f8fafc; color:#475569; }
        input:focus { border-color:#93c5fd; box-shadow:0 0 0 4px rgba(37,99,235,.10); }
        .btn { border:none; cursor:pointer; padding:11px 16px; border-radius:999px; font-weight:900; font-size:.82rem; display:inline-flex; align-items:center; justify-content:center; gap:8px; transition:.18s ease; text-decoration:none; }
        .btn:hover { transform:translateY(-1px); }
        .btn-primary { background:linear-gradient(135deg,#38bdf8,#2563eb); color:#fff; box-shadow:0 14px 26px rgba(37,99,235,.18); }
        .btn-outline { background:#fff; color:var(--blue); border:1px solid #93c5fd; }
        .btn-danger { background:#fff1f2; color:#dc2626; border:1px solid #fecaca; }
        .btn-full { width:100%; }
        .password-note { background:linear-gradient(135deg, #f8fbff, #eff6ff); color:#475569; border:1px solid #cfe1ff; padding:13px 15px; border-radius:16px; font-size:.82rem; font-weight:800; line-height:1.55; margin-bottom:16px; }
        .settings-card { margin-top: 18px; }

        @media (max-width: 900px) { .profile-grid { grid-template-columns:1fr; } }
        @media (max-width: 720px) {
            .visitor-navbar { height:auto; padding:14px 5%; align-items:flex-start; flex-direction:column; }
            .nav-links { width:100%; display:grid; grid-template-columns:1fr 1fr; }
            .nav-links > a { justify-content:center; }
            .title-box { flex-direction:column; align-items:flex-start; }
            .fields-grid { grid-template-columns:1fr; }
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
<div class="cute-scene">
    <div class="cloud cloud-left"></div>
    <div class="cloud cloud-right"></div>
    <div class="sparkle sp1">✦</div>
    <div class="sparkle sp2">✧</div>
    <div class="sparkle sp3">✦</div>
    <div class="sparkle sp4">✧</div>
    <div class="cute-bush"><span></span><span></span><span></span></div>
</div>

<nav class="visitor-navbar">
    <div class="logo">Smart<span>VMS</span></div>

    <div class="nav-links">
        <a href="visitor_book.php" class="">
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
            <button type="button" class="profile-trigger active" aria-label="Visitor profile menu">
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
    <div class="title-box">
        <div class="title-sticker"><i class="fas fa-id-badge"></i></div>
        <div>
            <h1 class="page-title">Visitor Profile</h1>
            <p class="page-sub">Manage your profile photo, personal details and password.</p>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert success"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="profile-grid">
        <section class="card">
            <div class="profile-card">
                <div class="avatar">
                    <?php if (!empty($visitorProfilePhoto)): ?>
                        <img src="<?= e($visitorProfilePhoto) ?>" alt="Visitor photo">
                    <?php else: ?>
                        <?= e($visitorInitial) ?>
                    <?php endif; ?>
                </div>

                <div class="profile-name"><?= e($currentName) ?></div>
                <div class="profile-email"><?= e($visitorEmail) ?></div>
                <div class="profile-badge"><i class="fas fa-check-circle"></i> Visitor Account</div>

                <div class="photo-actions">
                    <form method="POST" enctype="multipart/form-data" id="visitorPhotoForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="upload_photo">
                        <input class="hidden-file" id="visitorPhotoInput" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" onchange="document.getElementById('visitorPhotoForm').submit()" required>
                        <button type="button" class="btn btn-outline btn-full" onclick="document.getElementById('visitorPhotoInput').click()">
                            <i class="fas fa-camera"></i>
                            Change Photo
                        </button>
                    </form>

                    <?php if (!empty($visitorProfilePhoto)): ?>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="remove_photo">
                            <button type="submit" class="btn btn-danger btn-full" onclick="return confirm('Remove your profile photo?');">
                                <i class="fas fa-trash"></i>
                                Remove Photo
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="mini-stats">
                    <div class="mini-stat">
                        <div class="mini-stat-num"><?= (int)$totalVisits ?></div>
                        <div class="mini-stat-label">Total Visits</div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-num" style="color:#d97706;"><?= (int)$pendingVisits ?></div>
                        <div class="mini-stat-label">Pending</div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-num" style="color:#16a34a;"><?= (int)$activeVisits ?></div>
                        <div class="mini-stat-label">Active</div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-num" style="color:#475569;"><?= (int)$completedVisits ?></div>
                        <div class="mini-stat-label">Completed</div>
                    </div>
                </div>
            </div>
        </section>

        <div>
            <section class="card" style="margin-bottom:18px;">
                <div class="card-head"><i class="fas fa-user-pen"></i> General Information</div>
                <div class="card-body">
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_profile">
                        <div class="fields-grid">
                            <div class="field">
                                <label>Email</label>
                                <input type="email" value="<?= e($visitorEmail) ?>" readonly>
                            </div>
                            <div class="field">
                                <label>Role</label>
                                <input type="text" value="Visitor" readonly>
                            </div>
                            <div class="field">
                                <label>Full Name</label>
                                <input type="text" name="full_name" value="<?= e($currentName) ?>" placeholder="Enter your full name" required>
                            </div>
                            <div class="field">
                                <label>Phone Number</label>
                                <input type="text" name="contact_number" value="<?= e($currentContact) ?>" placeholder="Example: 0123456789" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Profile</button>
                    </form>
                </div>
            </section>

            <section class="card settings-card" id="accountSettings">
                <div class="card-head"><i class="fas fa-lock"></i> Account Settings</div>
                <div class="card-body">
                    <div class="password-note">
                        Password changes are moved to a separate settings page for better security.
                        You need to enter your current password before creating a new password.
                    </div>

                    <a href="visitor_settings.php" class="btn btn-outline">
                        <i class="fas fa-gear"></i>
                        Open Settings
                    </a>
                </div>
            </section>
        </div>
    </div>
</main>

<?php if ($message): ?>
<script>
Swal.fire({ icon: 'success', title: 'Success', text: <?= json_encode($message) ?>, confirmButtonColor: '#2563eb' });
</script>
<?php endif; ?>

<?php if ($error): ?>
<script>
Swal.fire({ icon: 'error', title: 'Error', text: <?= json_encode($error) ?>, confirmButtonColor: '#2563eb' });
</script>
<?php endif; ?>

</body>
</html>
