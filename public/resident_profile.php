<?php
require_once '../core/security.php';
require_login(['resident']);

$pdo = db();
$residentId = (int)($_SESSION['uid'] ?? 0);
$residentEmail = $_SESSION['email'] ?? '';
$message = '';
$error = '';

function rp_has_column(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function rp_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function rp_count(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function rp_safe($value): string {
    return ($value !== null && $value !== '') ? (string)$value : '-';
}

function rp_unit_badge(array $r): string {
    if (empty($r['unit_no'])) return 'No Unit Assigned';

    $unit = (string)$r['unit_no'];

    if (strpos($unit, '-') !== false) {
        return 'Unit ' . $unit;
    }

    $block = (string)($r['block_no'] ?? '');
    $floor = (string)($r['floor_no'] ?? '');

    return ($block !== '' && $floor !== '')
        ? 'Unit ' . $block . '-' . $floor . '-' . $unit
        : 'Unit ' . $unit;
}

function rp_unit_detail(array $r): string {
    if (empty($r['unit_no'])) return 'No Unit Assigned';

    return 'Block ' . rp_safe($r['block_no'] ?? '') .
           ' / Floor ' . rp_safe($r['floor_no'] ?? '') .
           ' / Unit ' . rp_safe($r['unit_no'] ?? '');
}

function rp_load(PDO $pdo, int $residentId, bool $hasFullName, bool $hasContact, bool $hasPhoto) {
    $nameSql = $hasFullName ? "u.full_name AS resident_name" : "NULL AS resident_name";
    $contactSql = $hasContact ? "u.contact_number AS resident_contact" : "NULL AS resident_contact";
    $photoSql = $hasPhoto ? "u.profile_photo AS profile_photo" : "NULL AS profile_photo";

    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.email,
            {$nameSql},
            {$contactSql},
            {$photoSql},
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
        LEFT JOIN units un 
            ON un.id = ru.unit_id
        LEFT JOIN apartments a 
            ON a.id = un.apartment_id
        WHERE u.id = ?
        LIMIT 1
    ");

    $stmt->execute([$residentId]);
    return $stmt->fetch();
}

$hasFullName = rp_has_column($pdo, 'users', 'full_name');
$hasContact = rp_has_column($pdo, 'users', 'contact_number');
$hasPhoto = rp_has_column($pdo, 'users', 'profile_photo');

$resident = rp_load($pdo, $residentId, $hasFullName, $hasContact, $hasPhoto);

if (!$resident) {
    $resident = [
        'email' => $residentEmail,
        'resident_name' => '',
        'resident_contact' => '',
        'profile_photo' => '',
        'unit_id' => null,
        'apartment_name' => '',
        'address' => '',
        'block_no' => '',
        'floor_no' => '',
        'unit_no' => ''
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'update_profile') {
                $fullName = trim($_POST['full_name'] ?? '');
                $phone = trim($_POST['contact_number'] ?? '');

                if ($hasFullName && $fullName === '') {
                    throw new Exception('Please enter your full name.');
                }

                if ($hasFullName && strlen($fullName) > 120) {
                    throw new Exception('Full name is too long.');
                }

                if ($hasContact && $phone === '') {
                    throw new Exception('Please enter your phone number.');
                }

                if ($hasContact && strlen($phone) > 30) {
                    throw new Exception('Phone number is too long.');
                }

                $set = [];
                $values = [];

                if ($hasFullName) {
                    $set[] = "full_name = ?";
                    $values[] = $fullName;
                }

                if ($hasContact) {
                    $set[] = "contact_number = ?";
                    $values[] = $phone;
                }

                if (!$set) {
                    throw new Exception('Profile columns are not available in users table.');
                }

                $values[] = $residentId;

                $stmt = $pdo->prepare("UPDATE users SET " . implode(', ', $set) . " WHERE id = ?");
                $stmt->execute($values);

                if (function_exists('log_audit')) {
                    log_audit('RESIDENT_PROFILE_UPDATED', 'Resident updated profile details. Resident ID: ' . $residentId);
                }

                $message = 'Profile updated successfully.';
            }

            if ($action === 'upload_photo') {
                if (!$hasPhoto) {
                    throw new Exception('Please run the profile_photo SQL first before uploading a photo.');
                }

                if (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] === UPLOAD_ERR_NO_FILE) {
                    throw new Exception('Please select a profile photo.');
                }

                if ($_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Photo upload failed. Please try again.');
                }

                if ($_FILES['profile_photo']['size'] > 2 * 1024 * 1024) {
                    throw new Exception('Profile photo must be less than 2MB.');
                }

                $tmp = $_FILES['profile_photo']['tmp_name'];
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($tmp);

                $allowed = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp'
                ];

                if (!isset($allowed[$mime])) {
                    throw new Exception('Only JPG, PNG, and WEBP photos are allowed.');
                }

                $dir = __DIR__ . '/uploads/profiles';

                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }

                $fileName = 'resident_' . $residentId . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
                $target = $dir . '/' . $fileName;
                $dbPath = 'uploads/profiles/' . $fileName;

                if (!move_uploaded_file($tmp, $target)) {
                    throw new Exception('Unable to save profile photo.');
                }

                $oldPhoto = (string)($resident['profile_photo'] ?? '');

                $stmt = $pdo->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
                $stmt->execute([$dbPath, $residentId]);

                if ($oldPhoto !== '' && strpos($oldPhoto, 'uploads/profiles/') === 0) {
                    $oldFile = __DIR__ . '/' . $oldPhoto;

                    if (is_file($oldFile)) {
                        @unlink($oldFile);
                    }
                }

                if (function_exists('log_audit')) {
                    log_audit('RESIDENT_PROFILE_PHOTO_UPDATED', 'Resident updated profile photo. Resident ID: ' . $residentId);
                }

                $message = 'Profile photo updated successfully.';
            }

            if ($action === 'remove_photo') {
                if (!$hasPhoto) {
                    throw new Exception('Profile photo column is not available in users table.');
                }

                $oldPhoto = (string)($resident['profile_photo'] ?? '');

                $stmt = $pdo->prepare("UPDATE users SET profile_photo = NULL WHERE id = ?");
                $stmt->execute([$residentId]);

                if ($oldPhoto !== '' && strpos($oldPhoto, 'uploads/profiles/') === 0) {
                    $oldFile = __DIR__ . '/' . $oldPhoto;

                    if (is_file($oldFile)) {
                        @unlink($oldFile);
                    }
                }

                if (function_exists('log_audit')) {
                    log_audit('RESIDENT_PROFILE_PHOTO_REMOVED', 'Resident removed profile photo. Resident ID: ' . $residentId);
                }

                $message = 'Profile photo removed successfully.';
            }

            $resident = rp_load($pdo, $residentId, $hasFullName, $hasContact, $hasPhoto);

        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$residentName = trim((string)($resident['resident_name'] ?? ''));

if ($residentName === '') {
    $residentName = explode('@', $residentEmail)[0];
}

$residentContact = (string)($resident['resident_contact'] ?? '');
$profilePhoto = trim((string)($resident['profile_photo'] ?? ''));
$profilePhotoUrl = '';

if ($hasPhoto && $profilePhoto !== '') {
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

$heroUnitText = rp_unit_badge($resident);
$unitDetailText = rp_unit_detail($resident);

$totalBookings = rp_count($pdo, "SELECT COUNT(*) FROM bookings WHERE resident_id = ?", [$residentId]);
$pendingBookings = rp_count($pdo, "SELECT COUNT(*) FROM bookings WHERE resident_id = ? AND status = 'pending'", [$residentId]);
$myVehicles = rp_count($pdo, "SELECT COUNT(*) FROM resident_vehicles WHERE resident_id = ? AND status = 'active'", [$residentId]);
$feedbackCount = rp_table_exists($pdo, 'resident_feedback') ? rp_count($pdo, "SELECT COUNT(*) FROM resident_feedback WHERE resident_id = ?", [$residentId]) : 0;
$notificationCount = rp_table_exists($pdo, 'notifications') ? rp_count($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0", [$residentId]) : 0;

$initials = strtoupper(substr($residentName, 0, 1));

if ($initials === '') {
    $initials = 'U';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Resident Profile - <?= e(APP_NAME) ?></title>
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
}

.unit-badge {
    min-width: 210px;
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
    white-space: nowrap;
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

.main-grid {
    display: grid;
    grid-template-columns: 360px 1fr;
    gap: 24px;
    align-items: stretch;
    margin-bottom: 24px;
}

.card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 22px;
    box-shadow: var(--shadow-sm);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
}

.profile-card {
    padding: 32px 28px;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
}

.avatar-wrap {
    width: 126px;
    height: 126px;
    border-radius: 50%;
    background:
        radial-gradient(circle at 35% 28%, #bfdbfe 0 18px, transparent 19px),
        radial-gradient(circle at 65% 35%, #93c5fd 0 23px, transparent 24px),
        linear-gradient(135deg, #dbeafe, #f8fafc);
    color: var(--navy);
    border: 8px solid #f1f5f9;
    box-shadow: 0 20px 35px rgba(15, 23, 42, 0.12);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    font-weight: 900;
    overflow: hidden;
    margin-bottom: 18px;
}

.avatar-wrap img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-name {
    color: var(--navy);
    font-size: 1.18rem;
    font-weight: 900;
    margin: 14px 0 8px;
}

.profile-meta {
    color: var(--muted);
    font-size: 0.88rem;
    line-height: 1.55;
    font-weight: 650;
}

.photo-actions {
    width: 100%;
    max-width: 250px;
}

.hidden-file-input {
    display: none !important;
}

.note {
    width: 100%;
    color: var(--muted);
    background: var(--blue-soft);
    border: 1px solid #bfdbfe;
    border-radius: 14px;
    padding: 12px 14px;
    margin-top: 16px;
    font-size: 0.8rem;
    font-weight: 700;
    line-height: 1.45;
}

.info-card {
    padding: 32px;
}

.card-title {
    color: var(--navy);
    font-size: 1.25rem;
    font-weight: 900;
    margin-bottom: 22px;
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

.form-field {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.form-field label,
.readonly-box .mini-label {
    color: var(--muted);
    font-size: 0.73rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.55px;
}

.form-control,
.readonly-box {
    width: 100%;
    min-height: 58px;
    border-radius: 16px;
    border: 1px solid var(--line);
    background: #ffffff;
    color: var(--navy);
    padding: 0 18px;
    font-size: 0.95rem;
    font-weight: 800;
    outline: none;
    box-shadow: none;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.form-control:focus {
    border-color: #bfdbfe;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.08);
}

.form-control[readonly] {
    background: #f8fafc;
    color: #64748b;
}

.readonly-box .mini-value {
    color: var(--navy);
    font-size: 0.95rem;
    font-weight: 900;
    margin-top: 4px;
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
    font-size: 0.86rem;
    font-weight: 900;
    transition: 0.22s ease;
}

.btn-primary {
    color: #ffffff;
    background: var(--blue);
    box-shadow: 0 12px 22px rgba(37, 99, 235, 0.18);
}

.btn-primary:hover {
    background: var(--blue-dark);
    transform: translateY(-2px);
}

.btn-outline {
    color: var(--blue);
    background: #ffffff;
    border: 1px solid #bfdbfe;
}

.btn-outline:hover {
    background: var(--blue-soft);
}

.btn-danger {
    width: 100%;
    color: #dc2626;
    background: var(--red-soft);
    border: 1px solid #fecaca;
    margin-top: 10px;
}

.btn-danger:hover {
    background: #ffe4e6;
}

.btn.full {
    width: 100%;
}

.change-photo-btn:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.form-actions {
    margin-top: 22px;
}

.settings-card {
    padding: 30px;
}

.settings-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 24px;
}

.settings-content p {
    color: var(--muted);
    font-size: 0.95rem;
    font-weight: 650;
    line-height: 1.55;
    max-width: 760px;
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

@media (max-width: 900px) {
    .main-grid {
        grid-template-columns: 1fr;
    }

    .page-header,
    .settings-content {
        flex-direction: column;
        align-items: flex-start;
    }

    .unit-badge {
        width: 100%;
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

    .form-row {
        grid-template-columns: 1fr;
    }

    .info-card,
    .profile-card,
    .settings-card {
        padding: 24px;
    }

    .nav-btn {
        padding: 9px 11px;
        font-size: 0.76rem;
    }
}
    </style>

<style id="resident-profile-dashboard-nav-lou-final">
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
        width: min(1120px, calc(100% - 56px)) !important;
        margin: 0 auto !important;
        padding: 48px 0 72px !important;
        position: relative !important;
        z-index: 1 !important;
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

    .unit-badge {
        border-radius: 22px !important;
        background: rgba(255, 255, 255, .76) !important;
        border: 1px solid #dbeafe !important;
        box-shadow: 0 12px 30px rgba(15, 23, 42, .055) !important;
    }

    .profile-card,
    .info-card,
    .settings-card {
        border-radius: 34px !important;
        background: rgba(255, 255, 255, .90) !important;
        border: 1px solid rgba(219, 229, 240, .95) !important;
        box-shadow: 0 24px 70px rgba(15, 23, 42, .10) !important;
        backdrop-filter: blur(18px) !important;
        -webkit-backdrop-filter: blur(18px) !important;
    }

    .avatar-circle,
    .profile-avatar,
    .avatar-wrap img {
        box-shadow: 0 18px 45px rgba(15, 23, 42, .13) !important;
    }

    .settings-card {
        margin-top: 28px !important;
    }

    .nav-btn.logout,
    .nav-btn[href="resident_invite.php"],
    .nav-btn[href="resident_requests.php"],
    .nav-btn[href="resident_vehicles.php"],
    .nav-btn[href="resident_feedback.php"] {
        display: none !important;
    }

    @media (max-width: 900px) {
        .page-header {
            padding: 28px 24px !important;
        }
    }
</style>


<style id="resident-profile-compact-no-scroll-final">
    html,
    body {
        height: 100% !important;
    }

    body {
        overflow: hidden !important;
    }

    .page {
        width: min(1120px, calc(100% - 56px)) !important;
        height: calc(100vh - 76px) !important;
        margin: 0 auto !important;
        padding: 26px 0 22px !important;
        display: flex !important;
        flex-direction: column !important;
        gap: 16px !important;
        overflow: hidden !important;
    }

    .page-header {
        min-height: 132px !important;
        height: auto !important;
        margin-bottom: 0 !important;
        padding: 24px 32px !important;
        border-radius: 32px !important;
    }

    .header-kicker {
        min-height: 30px !important;
        padding: 0 12px !important;
        margin-bottom: 8px !important;
        font-size: .78rem !important;
    }

    .header-info h1 {
        font-size: clamp(2.25rem, 3vw, 3.15rem) !important;
        margin: 0 0 7px !important;
        line-height: .98 !important;
    }

    .header-info p {
        font-size: .9rem !important;
        line-height: 1.35 !important;
        margin: 0 !important;
    }

    .unit-badge {
        min-height: 66px !important;
        padding: 12px 15px !important;
        border-radius: 20px !important;
    }

    .main-grid {
        display: grid !important;
        grid-template-columns: 320px minmax(0, 1fr) !important;
        gap: 18px !important;
        align-items: start !important;
        margin: 0 !important;
        flex: 0 0 auto !important;
    }

    .profile-card,
    .info-card {
        height: auto !important;
        min-height: 0 !important;
        padding: 24px 26px !important;
        border-radius: 32px !important;
    }

    .profile-card {
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: flex-start !important;
    }

    .avatar-wrap {
        margin-bottom: 12px !important;
    }

    .avatar-circle,
    .profile-avatar,
    .avatar-wrap img {
        width: 92px !important;
        height: 92px !important;
        border-radius: 50% !important;
    }

    .photo-actions,
    .profile-photo-actions {
        width: 100% !important;
        display: grid !important;
        gap: 8px !important;
        margin: 12px 0 13px !important;
    }

    .profile-card .btn,
    .photo-actions .btn,
    .profile-photo-actions .btn {
        min-height: 38px !important;
        padding: 0 15px !important;
        border-radius: 999px !important;
        font-size: .78rem !important;
    }

    .profile-name {
        margin: 8px 0 5px !important;
        font-size: 1.02rem !important;
        line-height: 1.2 !important;
    }

    .profile-card p,
    .profile-meta,
    .resident-unit-text {
        font-size: .8rem !important;
        line-height: 1.34 !important;
        margin: 0 !important;
    }

    .card-title {
        margin: 0 0 16px !important;
        font-size: 1.14rem !important;
    }

    .form-group {
        margin-bottom: 12px !important;
    }

    .form-group label {
        margin-bottom: 7px !important;
        font-size: .7rem !important;
    }

    .input-field,
    .form-control,
    input[type="text"],
    input[type="email"],
    input[type="tel"] {
        min-height: 48px !important;
        height: 48px !important;
        border-radius: 16px !important;
        padding: 0 16px !important;
        font-size: .88rem !important;
    }

    .form-row {
        gap: 12px !important;
        margin-bottom: 12px !important;
    }

    .form-actions {
        margin-top: 10px !important;
    }

    .form-actions .btn,
    .info-card .btn {
        min-height: 44px !important;
        padding: 0 18px !important;
        border-radius: 999px !important;
        font-size: .84rem !important;
    }

    .settings-card {
        margin-top: 0 !important;
        padding: 18px 24px !important;
        border-radius: 30px !important;
        min-height: 92px !important;
        flex: 0 0 auto !important;
    }

    .settings-content {
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) auto !important;
        gap: 18px !important;
        align-items: center !important;
    }

    .settings-card .card-title {
        margin: 0 0 6px !important;
        font-size: 1.05rem !important;
    }

    .settings-content p {
        margin: 0 !important;
        font-size: .8rem !important;
        line-height: 1.32 !important;
    }

    .note {
        display: none !important;
    }

    .settings-content .btn {
        min-height: 42px !important;
        padding: 0 18px !important;
        border-radius: 999px !important;
        white-space: nowrap !important;
    }

    .alert {
        position: fixed !important;
        top: 90px !important;
        left: 50% !important;
        transform: translateX(-50%) !important;
        z-index: 3000 !important;
        width: min(520px, calc(100% - 40px)) !important;
    }

    @media (max-height: 760px) {
        .page {
            padding-top: 18px !important;
            gap: 12px !important;
        }

        .page-header {
            min-height: 116px !important;
            padding: 20px 28px !important;
        }

        .header-info h1 {
            font-size: 2.45rem !important;
        }

        .profile-card,
        .info-card {
            padding: 20px 22px !important;
        }

        .avatar-circle,
        .profile-avatar,
        .avatar-wrap img {
            width: 78px !important;
            height: 78px !important;
        }

        .settings-card {
            min-height: 78px !important;
            padding: 14px 20px !important;
        }
    }

    @media (max-width: 980px) {
        body {
            overflow-y: auto !important;
        }

        .page {
            height: auto !important;
            display: block !important;
            overflow: visible !important;
        }

        .main-grid {
            grid-template-columns: 1fr !important;
            margin-top: 18px !important;
        }

        .settings-card {
            margin-top: 18px !important;
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
                        <?= e($initials) ?>
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
                            <?= e($initials) ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="dropdown-name"><?= e($residentName) ?></div>
                        <div class="dropdown-unit"><?= e($unitDetailText) ?></div>
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
                <i class="fas fa-user-shield"></i>
                Resident Profile
            </div>

            <h1>User Profile</h1>
            <p>Manage your personal details, profile photo and account settings.</p>
        </div>

        <div class="unit-badge">
            <div class="unit-icon">
                <i class="fas fa-building"></i>
            </div>
            <div>
                <small>Current Unit</small>
                <strong><?= e($heroUnitText) ?></strong>
            </div>
        </div>
    </section>

    <?php if ($message): ?>
        <div class="alert success"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="main-grid">
        <div class="card profile-card">
            <div class="avatar-wrap">
                <?php if ($profilePhotoUrl): ?>
                    <img src="<?= e($profilePhotoUrl) ?>" alt="Profile photo">
                <?php else: ?>
                    <?= e($initials) ?>
                <?php endif; ?>
            </div>

            <div class="photo-actions">
                <form method="POST" enctype="multipart/form-data" id="profilePhotoForm">
                    <?= csrf_field() ?>

                    <input type="hidden" name="action" value="upload_photo">

                    <input
                        id="profilePhotoInput"
                        class="hidden-file-input"
                        type="file"
                        name="profile_photo"
                        accept="image/jpeg,image/png,image/webp"
                        onchange="document.getElementById('profilePhotoForm').submit()"
                        required
                    >

                    <button
                        type="button"
                        class="btn btn-outline full change-photo-btn"
                        onclick="document.getElementById('profilePhotoInput').click()"
                        <?= $hasPhoto ? '' : 'disabled' ?>
                    >
                        <i class="fas fa-camera"></i>
                        Change Profile Photo
                    </button>
                </form>

                <?php if ($profilePhotoUrl): ?>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove_photo">

                        <button type="submit" class="btn btn-danger" onclick="return confirm('Remove your profile photo?');">
                            <i class="fas fa-trash"></i>
                            Remove Photo
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="profile-name"><?= e($residentName) ?></div>

            <div class="profile-meta">
                <?= e(rp_safe($resident['apartment_name'] ?? 'Apartment Not Assigned')) ?>
                <br>
                <?= e($unitDetailText) ?>
                <br>
                <?= e(rp_safe($resident['address'] ?? 'Address Not Available')) ?>
            </div>

            <?php if (!$hasPhoto): ?>
                <div class="note">
                    Run the profile_photo SQL first to enable photo upload.
                </div>
            <?php endif; ?>
        </div>

        <div class="card info-card">
            <div class="card-title">General Information</div>

            <form method="POST">
                <?= csrf_field() ?>

                <input type="hidden" name="action" value="update_profile">

                <div class="form-grid">
                    <div class="form-field">
                        <label>Full Name</label>
                        <input
                            class="form-control"
                            type="text"
                            name="full_name"
                            value="<?= e($residentName) ?>"
                            placeholder="Full name"
                            <?= $hasFullName ? '' : 'readonly' ?>
                        >
                    </div>

                    <div class="form-field">
                        <label>Phone Number</label>
                        <input
                            class="form-control"
                            type="text"
                            name="contact_number"
                            value="<?= e($residentContact) ?>"
                            placeholder="Example: 012-3456789"
                            <?= $hasContact ? '' : 'readonly' ?>
                        >
                    </div>

                    <div class="form-row">
                        <div class="readonly-box">
                            <div class="mini-label">Email</div>
                            <div class="mini-value"><?= e($residentEmail) ?></div>
                        </div>

                        <div class="readonly-box">
                            <div class="mini-label">Unit</div>
                            <div class="mini-value"><?= e($heroUnitText) ?></div>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary <?= ($hasFullName || $hasContact) ? '' : 'disabled-style' ?>">
                        <i class="fas fa-check"></i>
                        Update Profile
                    </button>
                </div>
            </form>
        </div>
    </section>

    <section class="card settings-card">
        <div class="card-title">Account Settings</div>

        <div class="settings-content">
            <div>
                <p>
                    Password changes are moved to a separate settings page for better security.
                    You need to enter your current password before creating a new password.
                </p>

                <div class="note">
                    Password is managed in Account Settings for better security.
                </div>
            </div>

            <a href="resident_settings.php" class="btn btn-outline">
                <i class="fas fa-gear"></i>
                Open Settings
            </a>
        </div>
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
