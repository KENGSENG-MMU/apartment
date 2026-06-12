<?php
require_once '../core/security.php';
require_login(['resident']);

$pdo = db();

$residentId = (int)($_SESSION['uid'] ?? 0);
$residentEmail = $_SESSION['email'] ?? '';
$message = '';
$error = '';

function rs_has_column(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}
function rs_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}
function rs_password_error(string $password): string {
    if (strlen($password) < 8) return 'New password must be at least 8 characters.';
    if (!preg_match('/[a-z]/', $password)) return 'New password must include at least one lowercase letter.';
    if (!preg_match('/[A-Z]/', $password)) return 'New password must include at least one uppercase letter.';
    if (!preg_match('/[0-9]/', $password)) return 'New password must include at least one number.';
    if (!preg_match('/[^A-Za-z0-9]/', $password)) return 'New password must include at least one special character.';
    return '';
}

$hasFullName = rs_has_column($pdo, 'users', 'full_name');
$hasPasswordHash = rs_has_column($pdo, 'users', 'password_hash');
$hasPassword = rs_has_column($pdo, 'users', 'password');
$hasProfilePhoto = rs_has_column($pdo, 'users', 'profile_photo');

$nameSql = $hasFullName ? "full_name" : "NULL AS full_name";
$passwordSql = $hasPasswordHash ? "password_hash AS password_value" : ($hasPassword ? "password AS password_value" : "NULL AS password_value");
$photoSql = $hasProfilePhoto ? "profile_photo" : "NULL AS profile_photo";

$stmt = $pdo->prepare("SELECT id, email, {$nameSql}, {$passwordSql}, {$photoSql} FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$residentId]);
$user = $stmt->fetch();

if (!$user) {
    $user = ['email' => $residentEmail, 'full_name' => '', 'password_value' => '', 'profile_photo' => ''];
}

$residentName = trim((string)($user['full_name'] ?? ''));
if ($residentName === '') $residentName = explode('@', $residentEmail)[0];

$profilePhoto = trim((string)($user['profile_photo'] ?? ''));
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
if (rs_table_exists($pdo, 'notifications')) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$residentId]);
        $notificationCount = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $notificationCount = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        try {
            if (!$hasPasswordHash && !$hasPassword) {
                throw new Exception('Password column not found in users table.');
            }

            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
                throw new Exception('Please fill in all password fields.');
            }

            $passwordError = rs_password_error($newPassword);
            if ($passwordError !== '') throw new Exception($passwordError);

            if ($newPassword !== $confirmPassword) {
                throw new Exception('New password and confirm password do not match.');
            }

            $storedPassword = (string)($user['password_value'] ?? '');
            $passwordOk = false;

            if ($storedPassword !== '') {
                $info = password_get_info($storedPassword);
                $passwordOk = !empty($info['algo'])
                    ? password_verify($currentPassword, $storedPassword)
                    : hash_equals($storedPassword, $currentPassword);
            }

            if (!$passwordOk) {
                throw new Exception('Current password is incorrect.');
            }

            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $passwordColumn = $hasPasswordHash ? 'password_hash' : 'password';

            $stmt = $pdo->prepare("UPDATE users SET {$passwordColumn} = ? WHERE id = ?");
            $stmt->execute([$newHash, $residentId]);

            if (function_exists('log_audit')) {
                log_audit('RESIDENT_PASSWORD_CHANGED', 'Resident changed password from settings page. Resident ID: ' . $residentId);
            }

            $message = 'Password changed successfully.';
            $user['password_value'] = $newHash;

        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Resident Settings - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
:root {
    --surface: rgba(255, 255, 255, 0.94);
    --line: #e2e8f0;
    --navy: #0f172a;
    --text: #334155;
    --muted: #64748b;
    --blue: #2563eb;
    --blue-dark: #1e40af;
    --blue-soft: #eff6ff;
    --red: #ef4444;
    --red-soft: #fff1f2;
    --green: #16a34a;
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
        radial-gradient(circle at 10% 18%, rgba(147, 197, 253, 0.13) 0 72px, transparent 74px),
        radial-gradient(circle at 90% 30%, rgba(191, 219, 254, 0.18) 0 95px, transparent 97px),
        radial-gradient(circle at 16% 82%, rgba(186, 230, 253, 0.14) 0 62px, transparent 64px),
        radial-gradient(circle at 86% 87%, rgba(219, 234, 254, 0.24) 0 86px, transparent 88px),
        linear-gradient(180deg, #ffffff 0%, #f3f6fb 100%);
    overflow-x: hidden;
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
    width: min(920px, calc(100% - 48px));
    margin: 0 auto;
    padding: 42px 0 68px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 24px;
    margin-bottom: 24px;
    padding-bottom: 22px;
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
    font-size: 2.5rem;
    font-weight: 900;
    color: var(--navy);
    letter-spacing: -1.5px;
    line-height: 1.08;
    margin-bottom: 10px;
}

.header-info p {
    color: var(--muted);
    font-size: 1rem;
    font-weight: 650;
    max-width: 640px;
}

.user-badge {
    min-width: 230px;
    padding: 14px 20px;
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.82);
    border: 1px solid var(--line);
    box-shadow: var(--shadow-sm);
    display: flex;
    align-items: center;
    gap: 12px;
}

.user-icon {
    width: 38px;
    height: 38px;
    border-radius: 12px;
    background: var(--blue-soft);
    color: var(--blue);
    display: flex;
    align-items: center;
    justify-content: center;
}

.user-badge small {
    display: block;
    color: var(--muted);
    font-size: 0.68rem;
    font-weight: 900;
    letter-spacing: 0.7px;
    text-transform: uppercase;
    margin-bottom: 2px;
}

.user-badge strong {
    display: block;
    color: var(--navy);
    font-size: 0.92rem;
    font-weight: 900;
}

.settings-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 24px;
    box-shadow: var(--shadow-md);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    padding: 34px;
}

.card-top {
    display: flex;
    justify-content: space-between;
    gap: 20px;
    align-items: flex-start;
    margin-bottom: 24px;
}

.card-top h2 {
    color: var(--navy);
    font-size: 1.35rem;
    font-weight: 900;
    margin-bottom: 8px;
}

.card-top p {
    color: var(--muted);
    font-size: 0.92rem;
    font-weight: 650;
    line-height: 1.5;
}

.card-icon {
    width: 52px;
    height: 52px;
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

.form-grid {
    display: grid;
    gap: 18px;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
}

.field {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.field label {
    color: var(--muted);
    font-size: 0.73rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.55px;
}

input {
    width: 100%;
    min-height: 58px;
    border-radius: 16px;
    border: 1px solid var(--line);
    background: #ffffff;
    color: var(--navy);
    padding: 0 16px;
    font-size: 0.92rem;
    font-weight: 800;
    outline: none;
    box-shadow: none;
}

input:focus {
    border-color: #bfdbfe;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.08);
}

input::placeholder {
    color: #94a3b8;
}

.note {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    color: var(--blue-dark);
    background: var(--blue-soft);
    border: 1px solid #bfdbfe;
    border-radius: 16px;
    padding: 14px 16px;
    font-size: 0.84rem;
    font-weight: 750;
    line-height: 1.45;
}

.note i {
    margin-top: 2px;
    color: var(--blue);
}

.button-row {
    display: flex;
    gap: 14px;
    align-items: center;
    margin-top: 22px;
    flex-wrap: wrap;
}

.btn {
    border: 0;
    cursor: pointer;
    text-decoration: none;
    min-height: 48px;
    padding: 0 24px;
    border-radius: 999px;
    font-size: 0.88rem;
    font-weight: 900;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    transition: 0.22s ease;
}

.btn-main {
    background: var(--blue);
    color: #ffffff;
    box-shadow: 0 12px 22px rgba(37, 99, 235, 0.18);
}

.btn-main:hover {
    background: var(--blue-dark);
    transform: translateY(-2px);
}

.btn-outline {
    background: #ffffff;
    color: var(--blue);
    border: 1px solid #bfdbfe;
}

.btn-outline:hover {
    background: var(--blue-soft);
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
}

@media (max-width: 820px) {
    .page-header,
    .card-top {
        flex-direction: column;
        align-items: flex-start;
    }

    .user-badge {
        width: 100%;
    }

    .form-row {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 620px) {
    .page {
        width: min(100% - 28px, 920px);
        padding-top: 26px;
    }

    .header-info h1 {
        font-size: 2rem;
    }

    .settings-card {
        padding: 24px;
    }

    .btn {
        width: 100%;
    }

    .nav-btn {
        padding: 9px 11px;
        font-size: 0.76rem;
    }
}
    </style>

<style id="resident-settings-dashboard-nav-lou-final">
    html,
    body {
        height: 100% !important;
    }

    body {
        min-height: 100vh !important;
        background: #eef6ff !important;
        overflow: hidden !important;
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
        width: min(1120px, calc(100% - 56px)) !important;
        height: calc(100vh - 76px) !important;
        margin: 0 auto !important;
        padding: 42px 0 36px !important;
        display: grid !important;
        grid-template-columns: 360px minmax(0, 1fr) !important;
        grid-template-rows: auto minmax(0, 1fr) !important;
        gap: 24px 28px !important;
        overflow: hidden !important;
        position: relative !important;
        z-index: 1 !important;
    }

    .page-header {
        grid-column: 1 / -1 !important;
        min-height: 148px !important;
        margin: 0 !important;
        padding: 28px 34px !important;
        border-radius: 34px !important;
        background: rgba(255, 255, 255, .90) !important;
        border: 1px solid rgba(219, 229, 240, .95) !important;
        box-shadow: 0 24px 70px rgba(15, 23, 42, .10) !important;
        backdrop-filter: blur(18px) !important;
        -webkit-backdrop-filter: blur(18px) !important;
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) 255px !important;
        gap: 22px !important;
        align-items: center !important;
        position: relative !important;
        overflow: hidden !important;
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
        margin-bottom: 10px !important;
    }

    .header-info h1 {
        margin: 0 0 9px !important;
        color: #0b1220 !important;
        font-size: clamp(2.45rem, 3.2vw, 3.55rem) !important;
        line-height: .96 !important;
        letter-spacing: -.06em !important;
        font-weight: 900 !important;
    }

    .header-info p {
        max-width: 650px !important;
        margin: 0 !important;
        color: #64748b !important;
        font-size: .98rem !important;
        line-height: 1.42 !important;
        font-weight: 730 !important;
    }

    .user-badge {
        min-height: 74px !important;
        padding: 13px 16px !important;
        border-radius: 22px !important;
        background: rgba(255, 255, 255, .76) !important;
        border: 1px solid #dbeafe !important;
        box-shadow: 0 12px 30px rgba(15, 23, 42, .055) !important;
        display: flex !important;
        align-items: center !important;
        gap: 12px !important;
    }

    .user-icon {
        width: 46px !important;
        height: 46px !important;
        border-radius: 16px !important;
        background: #eff6ff !important;
        color: #2563eb !important;
    }

    .settings-card {
        grid-column: 1 / -1 !important;
        margin: 0 !important;
        padding: 30px 34px !important;
        border-radius: 34px !important;
        background: rgba(255, 255, 255, .91) !important;
        border: 1px solid rgba(219, 229, 240, .95) !important;
        box-shadow: 0 24px 70px rgba(15, 23, 42, .10) !important;
        backdrop-filter: blur(18px) !important;
        -webkit-backdrop-filter: blur(18px) !important;
        min-height: 0 !important;
        overflow: hidden !important;
    }

    .card-top {
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) 58px !important;
        align-items: start !important;
        gap: 18px !important;
        margin-bottom: 20px !important;
    }

    .card-top h2 {
        margin: 0 0 8px !important;
        color: #0f172a !important;
        font-size: 1.35rem !important;
        font-weight: 900 !important;
        letter-spacing: -.025em !important;
    }

    .card-top p {
        margin: 0 !important;
        color: #64748b !important;
        font-size: .92rem !important;
        font-weight: 730 !important;
        line-height: 1.35 !important;
    }

    .lock-icon {
        width: 58px !important;
        height: 58px !important;
        border-radius: 20px !important;
        background: #eff6ff !important;
        color: #2563eb !important;
        border: 1px solid #bfdbfe !important;
    }

    .form-group {
        margin-bottom: 15px !important;
    }

    .form-group label {
        margin-bottom: 7px !important;
        color: #64748b !important;
        font-size: .72rem !important;
        font-weight: 900 !important;
        letter-spacing: .055em !important;
    }

    .form-control,
    input[type="password"] {
        height: 52px !important;
        min-height: 52px !important;
        border-radius: 17px !important;
        padding: 0 17px !important;
        background: rgba(255,255,255,.74) !important;
        border: 1px solid #dbe5f0 !important;
        font-size: .9rem !important;
        font-weight: 800 !important;
    }

    .form-row {
        gap: 14px !important;
        margin-bottom: 14px !important;
    }

    .password-note {
        min-height: 46px !important;
        padding: 0 16px !important;
        border-radius: 17px !important;
        background: #eff6ff !important;
        border: 1px solid #bfdbfe !important;
        color: #1d4ed8 !important;
        display: flex !important;
        align-items: center !important;
        gap: 10px !important;
        font-size: .82rem !important;
        font-weight: 850 !important;
        line-height: 1.25 !important;
        margin: 2px 0 18px !important;
    }

    .button-row {
        display: flex !important;
        align-items: center !important;
        gap: 12px !important;
        margin-top: 0 !important;
    }

    .btn {
        height: 48px !important;
        min-height: 48px !important;
        padding: 0 22px !important;
        border-radius: 999px !important;
        font-size: .86rem !important;
        font-weight: 900 !important;
    }

    .btn-main {
        background: linear-gradient(135deg, #38bdf8, #2563eb) !important;
        box-shadow: 0 16px 30px rgba(37, 99, 235, .22) !important;
    }

    .btn-outline {
        background: rgba(255,255,255,.76) !important;
        border: 1px solid #bfdbfe !important;
        color: #2563eb !important;
    }

    .nav-btn.logout,
    .nav-btn[href="resident_invite.php"],
    .nav-btn[href="resident_requests.php"],
    .nav-btn[href="resident_vehicles.php"],
    .nav-btn[href="resident_feedback.php"] {
        display: none !important;
    }

    .alert {
        position: fixed !important;
        top: 90px !important;
        left: 50% !important;
        transform: translateX(-50%) !important;
        z-index: 3000 !important;
        width: min(520px, calc(100% - 40px)) !important;
    }

    @media (max-width: 980px) {
        body {
            overflow-y: auto !important;
        }

        .page {
            height: auto !important;
            display: block !important;
            overflow: visible !important;
            padding: 28px 0 50px !important;
        }

        .page-header {
            grid-template-columns: 1fr !important;
            margin-bottom: 18px !important;
        }
    }
</style>


<style id="resident-settings-fit-content-final">
    body {
        overflow: hidden !important;
    }

    .page {
        width: min(980px, calc(100% - 64px)) !important;
        height: auto !important;
        min-height: auto !important;
        margin: 0 auto !important;
        padding: 34px 0 0 !important;
        display: flex !important;
        flex-direction: column !important;
        gap: 18px !important;
        overflow: visible !important;
    }

    .page-header {
        min-height: 138px !important;
        height: auto !important;
        margin: 0 !important;
        padding: 24px 32px !important;
        border-radius: 30px !important;
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) 250px !important;
        gap: 20px !important;
        align-items: center !important;
    }

    .header-kicker {
        height: 32px !important;
        min-height: 32px !important;
        margin-bottom: 9px !important;
        padding: 0 14px !important;
        font-size: .8rem !important;
    }

    .header-info h1 {
        font-size: clamp(2.35rem, 3vw, 3.15rem) !important;
        line-height: .98 !important;
        margin: 0 0 8px !important;
    }

    .header-info p {
        font-size: .92rem !important;
        line-height: 1.38 !important;
        margin: 0 !important;
    }

    .user-badge {
        min-height: 66px !important;
        padding: 12px 15px !important;
        border-radius: 20px !important;
    }

    .user-icon {
        width: 42px !important;
        height: 42px !important;
        border-radius: 15px !important;
    }

    .settings-card {
        width: 100% !important;
        height: auto !important;
        min-height: 0 !important;
        margin: 0 !important;
        padding: 26px 32px 28px !important;
        border-radius: 30px !important;
        flex: 0 0 auto !important;
        overflow: visible !important;
    }

    .card-top {
        margin-bottom: 18px !important;
        grid-template-columns: minmax(0, 1fr) 54px !important;
    }

    .card-top h2 {
        font-size: 1.28rem !important;
        margin-bottom: 7px !important;
    }

    .card-top p {
        font-size: .88rem !important;
        line-height: 1.34 !important;
    }

    .lock-icon {
        width: 54px !important;
        height: 54px !important;
        border-radius: 18px !important;
    }

    .form-group {
        margin-bottom: 13px !important;
    }

    .form-group label {
        margin-bottom: 7px !important;
        font-size: .7rem !important;
    }

    .form-control,
    input[type="password"] {
        height: 48px !important;
        min-height: 48px !important;
        border-radius: 16px !important;
        padding: 0 16px !important;
        font-size: .88rem !important;
    }

    .form-row {
        gap: 14px !important;
        margin-bottom: 13px !important;
    }

    .password-note {
        min-height: 44px !important;
        margin: 0 0 16px !important;
        padding: 10px 15px !important;
        border-radius: 16px !important;
        font-size: .8rem !important;
    }

    .button-row {
        gap: 12px !important;
        margin-top: 0 !important;
    }

    .btn {
        height: 46px !important;
        min-height: 46px !important;
        padding: 0 20px !important;
        border-radius: 999px !important;
        font-size: .84rem !important;
    }

    @media (max-width: 900px) {
        body {
            overflow-y: auto !important;
        }

        .page {
            width: min(100% - 30px, 980px) !important;
            padding: 24px 0 40px !important;
        }

        .page-header {
            grid-template-columns: 1fr !important;
        }

        .form-row {
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
            <?php if ($notificationCount > 0): ?>
                <span class="nav-notification-badge">
                    <?= $notificationCount > 99 ? '99+' : (int)$notificationCount ?>
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
                        <div class="dropdown-unit"><?= e($residentEmail) ?></div>
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

<main class="page">
    <section class="page-header">
        <div class="header-info">
            <div class="header-kicker">
                <i class="fas fa-shield-halved"></i>
                Account Security
            </div>

            <h1>Account Settings</h1>
            <p>Change your resident account password securely using your current password.</p>
        </div>

        <div class="user-badge">
            <div class="user-icon">
                <i class="fas fa-user-shield"></i>
            </div>
            <div>
                <small>Resident</small>
                <strong><?= e($residentName) ?></strong>
            </div>
        </div>
    </section>

    <section class="settings-card">
        <div class="card-top">
            <div>
                <h2>Change Password</h2>
                <p>For security, please enter your current password before creating a new password.</p>
            </div>

            <div class="card-icon">
                <i class="fas fa-lock"></i>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert success"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <?= csrf_field() ?>

            <div class="form-grid">
                <div class="field">
                    <label>Current Password</label>
                    <input type="password" name="current_password" placeholder="Enter current password" required>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label>New Password</label>
                        <input type="password" name="new_password" placeholder="A-Z, a-z, number, symbol" required>
                    </div>

                    <div class="field">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" placeholder="Re-enter new password" required>
                    </div>
                </div>

                <div class="note">
                    <i class="fas fa-circle-info"></i>
                    <span>
                        Password must be at least 8 characters and include uppercase letter, lowercase letter, number, and special character.
                    </span>
                </div>
            </div>

            <div class="button-row">
                <button type="submit" class="btn btn-main">
                    <i class="fas fa-save"></i>
                    Save New Password
                </button>

                <a href="resident_profile.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i>
                    Back to Profile
                </a>
            </div>
        </form>
    </section>
</main>

<?php if ($message): ?>
<script>
Swal.fire({
    icon: 'success',
    title: 'Success',
    text: <?= json_encode($message) ?>,
    confirmButtonColor: '#2563eb',
    background: '#ffffff',
    color: '#0f172a'
});
</script>
<?php endif; ?>

<?php if ($error): ?>
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

<?php require_once __DIR__ . '/resident_notification_popup.php'; ?>
</body>
</html>
