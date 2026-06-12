<?php
require_once '../core/security.php';
require_login(['visitor']);

$pdo = db();

$visitorId = (int)($_SESSION['uid'] ?? 0);
$visitorEmail = $_SESSION['email'] ?? '';

$message = '';
$error = '';

function vs_table_exists(PDO $pdo, string $table): bool {
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

function vs_has_column(PDO $pdo, string $table, string $column): bool {
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

function vs_ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
    if (!vs_table_exists($pdo, $table)) {
        return;
    }

    if (!vs_has_column($pdo, $table, $column)) {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (Throwable $e) {
            // Ignore if ALTER is blocked.
        }
    }
}

function vs_validate_password(string $password): ?string {
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

function vs_photo_url(?string $photo): string {
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

vs_ensure_column($pdo, 'users', 'full_name', 'VARCHAR(150) NULL AFTER email');
vs_ensure_column($pdo, 'users', 'contact_number', 'VARCHAR(30) NULL AFTER full_name');
vs_ensure_column($pdo, 'users', 'profile_photo', 'VARCHAR(255) NULL AFTER contact_number');
vs_ensure_column($pdo, 'users', 'updated_at', 'DATETIME NULL');

$hasFullName = vs_has_column($pdo, 'users', 'full_name');
$hasProfilePhoto = vs_has_column($pdo, 'users', 'profile_photo');
$hasUpdatedAt = vs_has_column($pdo, 'users', 'updated_at');

$passwordColumn = null;

if (vs_has_column($pdo, 'users', 'password_hash')) {
    $passwordColumn = 'password_hash';
} elseif (vs_has_column($pdo, 'users', 'password')) {
    $passwordColumn = 'password';
}

$nameSql = $hasFullName ? "full_name" : "NULL AS full_name";
$photoSql = $hasProfilePhoto ? "profile_photo" : "NULL AS profile_photo";

$stmt = $pdo->prepare("
    SELECT
        id,
        email,
        role,
        {$nameSql},
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
$defaultVisitorName = $currentName;
$visitorInitial = strtoupper(substr($currentName ?: 'V', 0, 1));
$visitorProfilePhoto = vs_photo_url($visitor['profile_photo'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        try {
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

            $passwordError = vs_validate_password($newPassword);

            if ($passwordError) {
                throw new Exception($passwordError);
            }

            $fields = ["{$passwordColumn} = ?"];
            $values = [password_hash($newPassword, PASSWORD_DEFAULT)];

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
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    $_SESSION['visitor_settings_flash'] = [
        'message' => $message,
        'error' => $error
    ];

    header('Location: visitor_settings.php');
    exit;
}

if (isset($_SESSION['visitor_settings_flash']) && is_array($_SESSION['visitor_settings_flash'])) {
    $message = $_SESSION['visitor_settings_flash']['message'] ?? '';
    $error = $_SESSION['visitor_settings_flash']['error'] ?? '';
    unset($_SESSION['visitor_settings_flash']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Visitor Settings - <?= e(APP_NAME) ?></title>
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
            --red: #dc2626;
            --header: #1e293b;
            --shadow: 0 18px 42px rgba(15, 23, 42, .08);
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
                radial-gradient(circle at 10% 20%, rgba(191, 219, 254, .46), transparent 10%),
                radial-gradient(circle at 88% 24%, rgba(219, 234, 254, .46), transparent 11%),
                radial-gradient(circle at 14% 86%, rgba(203, 213, 225, .34), transparent 9%),
                radial-gradient(circle at 83% 86%, rgba(191, 219, 254, .26), transparent 9%),
                linear-gradient(180deg, #f8fbff 0%, var(--bg) 100%);
            overflow-x: hidden;
        }

        a {
            text-decoration: none;
        }

        .cute-scene {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }

        .cloud {
            position: absolute;
            width: 92px;
            height: 30px;
            border: 2px solid #dbe7f3;
            border-radius: 999px;
            background: rgba(255,255,255,.72);
        }

        .cloud:before,
        .cloud:after {
            content:"";
            position:absolute;
            background:rgba(255,255,255,.92);
            border:2px solid #dbe7f3;
            border-bottom:none;
            border-radius:999px 999px 0 0;
        }

        .cloud:before {
            width:36px;
            height:28px;
            left:14px;
            top:-18px;
        }

        .cloud:after {
            width:46px;
            height:34px;
            right:14px;
            top:-24px;
        }

        .cloud-left {
            left: 6%;
            top: 22%;
        }

        .cloud-right {
            right: 12%;
            top: 18%;
            transform: scale(.85);
        }

        .sparkle {
            position: absolute;
            color: #f6c55d;
            opacity:.78;
            font-size:1.2rem;
            animation: floatSparkle 4s ease-in-out infinite;
        }

        .sp1 { left:16%; top: 35%; }
        .sp2 { right:18%; top: 45%; color:#9fc5ff; animation-delay:.8s; }
        .sp3 { left:10%; bottom: 17%; animation-delay:1.4s; }
        .sp4 { right:15%; bottom: 22%; color:#cbd5e1; animation-delay:2s; }

        @keyframes floatSparkle {
            0%,100% { transform: translateY(0); }
            50% { transform: translateY(-8px); }
        }

        .cute-bush {
            position:absolute;
            right:8%;
            bottom:4%;
            width:136px;
            height:72px;
        }

        .cute-bush span {
            position:absolute;
            bottom:0;
            border-radius:999px 999px 18px 18px;
            background:#cfe9c7;
            border:2px solid #a7d19b;
        }

        .cute-bush span:nth-child(1){ width:58px; height:44px; left:0; }
        .cute-bush span:nth-child(2){ width:84px; height:62px; left:34px; }
        .cute-bush span:nth-child(3){ width:48px; height:38px; right:0; }

        .visitor-navbar {
            width:100%;
            height:64px;
            padding:0 5%;
            background:var(--header);
            color:#e5e7eb;
            border-bottom:1px solid rgba(255,255,255,.08);
            box-shadow:0 10px 28px rgba(15,23,42,.16);
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:18px;
            position:sticky;
            top:0;
            z-index:100;
        }

        .logo {
            font-size:1.3rem;
            font-weight:900;
            letter-spacing:-.045em;
            color:#fff;
        }

        .logo span {
            color:#3b82f6;
        }

        .nav-links {
            display:flex;
            align-items:center;
            justify-content:flex-end;
            gap:10px;
            flex-wrap:wrap;
        }

        .nav-links > a {
            color:#e5e7eb;
            background:rgba(255,255,255,.03);
            border:1px solid rgba(255,255,255,.08);
            padding:8px 13px;
            border-radius:14px;
            font-size:.78rem;
            font-weight:900;
            display:inline-flex;
            align-items:center;
            gap:7px;
            transition:.18s ease;
        }

        .nav-links > a:hover {
            background:rgba(255,255,255,.07);
            transform:translateY(-1px);
        }

        .page {
            width:min(920px, calc(100% - 36px));
            margin: 34px auto 70px;
            position:relative;
            z-index:1;
        }

        .title-box {
            display:flex;
            align-items:center;
            gap:18px;
            margin-bottom:20px;
        }

        .title-sticker {
            width:66px;
            height:66px;
            border-radius:20px;
            background:#fff5ea;
            border:2px solid #f3d0ae;
            display:flex;
            align-items:center;
            justify-content:center;
            color:#7b8794;
            font-size:1.45rem;
            transform:rotate(-8deg);
            box-shadow:0 14px 28px rgba(148,163,184,.14);
            position:relative;
        }

        .title-sticker:after {
            content:"♡";
            position:absolute;
            right:-11px;
            bottom:-12px;
            color:#fb8ca8;
            font-size:1.9rem;
        }

        .page-title {
            font-size:clamp(2.05rem, 3.5vw, 2.9rem);
            font-weight:900;
            letter-spacing:-.07em;
            line-height:1.05;
            margin-bottom:8px;
        }

        .page-sub {
            color:#677489;
            font-size:.98rem;
            font-weight:760;
            line-height:1.55;
        }

        .settings-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 28px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .card-head {
            padding: 20px 24px;
            border-bottom: 1px solid #edf0f3;
            font-weight: 900;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-body {
            padding: 24px;
        }

        .note {
            background: linear-gradient(135deg, #f8fbff, #eff6ff);
            color: #475569;
            border: 1px solid #cfe1ff;
            padding: 14px 16px;
            border-radius: 16px;
            font-size: .84rem;
            font-weight: 800;
            line-height: 1.55;
            margin-bottom: 18px;
        }

        .fields-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .field {
            margin-bottom: 14px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            display:block;
            font-size:.68rem;
            font-weight:900;
            color:var(--muted);
            text-transform:uppercase;
            letter-spacing:.07em;
            margin-bottom:7px;
        }

        input {
            width:100%;
            padding:13px 14px;
            border:1px solid var(--border);
            border-radius:15px;
            background:#fff;
            color:var(--text);
            font-weight:800;
            outline:none;
        }

        input:focus {
            border-color:#93c5fd;
            box-shadow:0 0 0 4px rgba(37,99,235,.10);
        }

        .btn-row {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 8px;
        }

        .btn {
            border:none;
            cursor:pointer;
            padding:12px 18px;
            border-radius:999px;
            font-weight:900;
            font-size:.84rem;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            transition:.18s ease;
            text-decoration:none;
        }

        .btn:hover {
            transform:translateY(-1px);
        }

        .btn-primary {
            background:linear-gradient(135deg,#38bdf8,#2563eb);
            color:#fff;
            box-shadow:0 14px 26px rgba(37,99,235,.18);
        }

        .btn-outline {
            background:#fff;
            color:var(--blue);
            border:1px solid #93c5fd;
        }

        .alert {
            padding:14px 15px;
            border-radius:16px;
            margin-bottom:16px;
            font-weight:850;
            line-height:1.45;
        }

        .alert.success {
            background:#ecfdf3;
            color:#027a48;
            border:1px solid #abefc6;
        }

        .alert.error {
            background:#fef3f2;
            color:#b42318;
            border:1px solid #fecdca;
        }

        @media (max-width: 720px) {
            .visitor-navbar {
                height:auto;
                padding:14px 5%;
                align-items:flex-start;
                flex-direction:column;
            }

            .nav-links {
                width:100%;
                display:grid;
                grid-template-columns:1fr 1fr;
            }

            .nav-links > a {
                justify-content:center;
            }

            .title-box {
                flex-direction:column;
                align-items:flex-start;
            }

            .fields-grid {
                grid-template-columns:1fr;
            }

            .btn {
                width: 100%;
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
            background: #ffffff;
            border: 1px solid #dbe5f0;
            box-shadow: 0 24px 55px rgba(15, 23, 42, .20);
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
            background: #ffffff;
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
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
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

        .dropdown-link {
            min-height: 52px;
            padding: 12px 13px;
            border-radius: 16px;
            color: #0f172a !important;
            background: #ffffff !important;
            border: 1px solid transparent !important;
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
            border-color: #e2e8f0 !important;
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

        .dropdown-logout,
        .dropdown-logout strong {
            color: #dc2626 !important;
        }

        .dropdown-logout i {
            background: #fff1f2;
            color: #dc2626;
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
        <a href="visitor_book.php">
            <i class="fas fa-calendar-plus"></i>
            Book Visit
        </a>

        <?php
        if (file_exists('notification_badge.php')) {
            include 'notification_badge.php';
        }
        ?>

        <a href="visitor_history.php">
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
                <span class="profile-trigger-name"><?= e($defaultVisitorName) ?></span>
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
                        <div class="dropdown-name"><?= e($defaultVisitorName) ?></div>
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
        <div class="title-sticker"><i class="fas fa-lock"></i></div>
        <div>
            <h1 class="page-title">Account Settings</h1>
            <p class="page-sub">Change your visitor account password securely.</p>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert success"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="settings-card">
        <div class="card-head">
            <i class="fas fa-shield-halved"></i>
            Change Password
        </div>

        <div class="card-body">
            <div class="note">
                For security, please enter your current password before creating a new password.
                Password must include uppercase, lowercase, number, and special character.
            </div>

            <form method="POST" autocomplete="off">
                <?= csrf_field() ?>

                <div class="fields-grid">
                    <div class="field full">
                        <label>Current Password</label>
                        <input type="password" name="current_password" placeholder="Enter current password" required>
                    </div>

                    <div class="field">
                        <label>New Password</label>
                        <input type="password" name="new_password" placeholder="A-Z, a-z, number, symbol" required>
                    </div>

                    <div class="field">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" placeholder="Re-enter new password" required>
                    </div>
                </div>

                <div class="btn-row">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        Save New Password
                    </button>

                    <a href="visitor_profile.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i>
                        Back to Profile
                    </a>
                </div>
            </form>
        </div>
    </section>
</main>

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

</body>
</html>
