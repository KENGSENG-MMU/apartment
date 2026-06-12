<?php
require_once '../core/security.php';
require_login(['visitor']);

$pdo = db();

$visitorId = (int)($_SESSION['uid'] ?? 0);
$visitorEmail = $_SESSION['email'] ?? '';

function safe_text_history($value) {
    return $value !== null && $value !== '' ? $value : '-';
}

function has_column_history(PDO $pdo, string $table, string $column): bool {
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

function safe_count_history(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function booking_status_class_history($status) {
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

$hasFullName = has_column_history($pdo, 'users', 'full_name');
$hasProfilePhoto = has_column_history($pdo, 'users', 'profile_photo');
$hasPurpose = has_column_history($pdo, 'bookings', 'purpose');
$hasVisitorType = has_column_history($pdo, 'bookings', 'visitor_type');
$hasVisitType = has_column_history($pdo, 'bookings', 'visit_type');
$hasQrToken = has_column_history($pdo, 'bookings', 'qr_token');
$hasSlotId = has_column_history($pdo, 'bookings', 'slot_id');
$hasVisitorIc = has_column_history($pdo, 'bookings', 'visitor_ic');
$hasVisitorContact = has_column_history($pdo, 'bookings', 'visitor_contact');

$visitorNameSql = $hasFullName ? "u.full_name AS visitor_account_name" : "NULL AS visitor_account_name";
$visitorPhotoSql = $hasProfilePhoto ? "u.profile_photo AS profile_photo" : "NULL AS profile_photo";
$residentNameSql = $hasFullName ? "res.full_name AS resident_name" : "NULL AS resident_name";

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
$visitorName = $visitorAccount['visitor_account_name'] ?: explode('@', $visitorEmail)[0];
$defaultVisitorName = $visitorName;
$visitorInitial = strtoupper(substr($visitorName ?: 'V', 0, 1));

$visitorProfilePhoto = '';
if (!empty($visitorAccount['profile_photo'])) {
    $photoPath = ltrim((string)$visitorAccount['profile_photo'], '/');
    if (preg_match('/^https?:\/\//i', $photoPath)) {
        $visitorProfilePhoto = $photoPath;
    } elseif (file_exists(__DIR__ . '/' . $photoPath)) {
        $visitorProfilePhoto = $photoPath;
    }
}

$purposeSelectSql = $hasPurpose ? "b.purpose" : "NULL AS purpose";
$visitorTypeSelectSql = $hasVisitorType ? "b.visitor_type" : "NULL AS visitor_type";
$visitTypeSelectSql = $hasVisitType ? "b.visit_type" : "NULL AS visit_type";
$qrTokenSelectSql = $hasQrToken ? "b.qr_token" : "NULL AS qr_token";
$visitorIcSelectSql = $hasVisitorIc ? "b.visitor_ic" : "NULL AS visitor_ic";
$visitorContactSelectSql = $hasVisitorContact ? "b.visitor_contact" : "NULL AS visitor_contact";
$slotJoin = $hasSlotId ? "LEFT JOIN parking_slots ps ON ps.id = b.slot_id" : "LEFT JOIN parking_slots ps ON 1 = 0";

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

    {$slotJoin}

    WHERE b.visitor_user_id = ?

    ORDER BY b.created_at DESC

    LIMIT 80
");
$stmt->execute([$visitorId]);
$history = $stmt->fetchAll();

$total = safe_count_history($pdo, "SELECT COUNT(*) FROM bookings WHERE visitor_user_id = ?", [$visitorId]);
$pending = safe_count_history($pdo, "SELECT COUNT(*) FROM bookings WHERE visitor_user_id = ? AND status = 'pending'", [$visitorId]);
$active = safe_count_history($pdo, "
    SELECT COUNT(*)
    FROM bookings
    WHERE visitor_user_id = ?
    AND status IN ('approved','allocated','waiting','checked_in')
", [$visitorId]);
$closed = safe_count_history($pdo, "
    SELECT COUNT(*)
    FROM bookings
    WHERE visitor_user_id = ?
    AND status IN ('completed','checked_out','closed','rejected','cancelled','expired')
", [$visitorId]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Visit History - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
            --header: #1e293b;
            --shadow: 0 18px 42px rgba(15, 23, 42, .08);
            --green: #16a34a;
            --amber: #d97706;
            --red: #dc2626;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', sans-serif; }
        body {
            min-height: 100vh;
            color: var(--text);
            background:
                radial-gradient(circle at 10% 18%, rgba(191, 219, 254, .45), transparent 10%),
                radial-gradient(circle at 88% 26%, rgba(219, 234, 254, .42), transparent 11%),
                radial-gradient(circle at 15% 84%, rgba(203, 213, 225, .35), transparent 9%),
                radial-gradient(circle at 84% 86%, rgba(191, 219, 254, .28), transparent 9%),
                linear-gradient(180deg, #f8fbff 0%, var(--bg) 100%);
            overflow-x: hidden;
        }
        a { text-decoration: none; }
        .cute-scene { position: fixed; inset: 0; pointer-events: none; z-index: 0; overflow: hidden; }
        .cloud { position: absolute; width: 92px; height: 30px; border: 2px solid #dbe7f3; border-radius: 999px; background: rgba(255,255,255,.72); }
        .cloud:before, .cloud:after { content: ""; position: absolute; background: rgba(255,255,255,.92); border: 2px solid #dbe7f3; border-bottom: none; border-radius: 999px 999px 0 0; }
        .cloud:before { width: 36px; height: 28px; left: 14px; top: -18px; }
        .cloud:after { width: 46px; height: 34px; right: 14px; top: -24px; }
        .cloud-left { left: 6%; top: 24%; }
        .cloud-right { right: 12%; top: 20%; transform: scale(.85); }
        .sparkle { position: absolute; color: #f6c55d; opacity: .78; font-size: 1.2rem; animation: floatSparkle 4s ease-in-out infinite; }
        .sp1 { left: 18%; top: 34%; }
        .sp2 { right: 16%; top: 48%; color: #9fc5ff; animation-delay: .8s; }
        .sp3 { left: 12%; bottom: 20%; animation-delay: 1.6s; }
        .sp4 { right: 18%; bottom: 24%; color: #cbd5e1; animation-delay: 2.1s; }
        @keyframes floatSparkle { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-8px); } }
        .cute-bush { position: absolute; right: 8%; bottom: 4%; width: 136px; height: 72px; }
        .cute-bush span { position:absolute; bottom:0; border-radius:999px 999px 18px 18px; background:#cfe9c7; border:2px solid #a7d19b; }
        .cute-bush span:nth-child(1){ width:58px; height:44px; left:0; }
        .cute-bush span:nth-child(2){ width:84px; height:62px; left:34px; }
        .cute-bush span:nth-child(3){ width:48px; height:38px; right:0; }

        .visitor-navbar {
            width: 100%; height: 64px; padding: 0 5%; background: var(--header); color: #e5e7eb;
            border-bottom: 1px solid rgba(255,255,255,.08); box-shadow: 0 10px 28px rgba(15,23,42,.16);
            display: flex; align-items: center; justify-content: space-between; gap: 18px;
            position: sticky; top: 0; z-index: 100;
        }
        .logo { font-size: 1.3rem; font-weight: 900; letter-spacing: -.045em; color: #fff; }
        .logo span { color: #3b82f6; }
        .nav-links { display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
        .nav-links a {
            color: #e5e7eb; background: rgba(255,255,255,.03); border: 1px solid rgba(255,255,255,.08);
            padding: 8px 13px; border-radius: 14px; font-size: .78rem; font-weight: 900;
            display: inline-flex; align-items: center; gap: 7px; transition: .18s ease;
        }
        .nav-links a:hover { background: rgba(255,255,255,.07); transform: translateY(-1px); }
        .nav-links a.active { background: rgba(59,130,246,.18); border-color: rgba(96,165,250,.45); color: #fff; }
        .nav-links a.logout { color: #fca5a5; }

        .page { width: min(980px, calc(100% - 34px)); margin: 34px auto 70px; position: relative; z-index: 1; }
        .title-box {
            display: flex; align-items: center; gap: 18px; margin-bottom: 20px;
        }
        .title-sticker {
            width: 66px; height: 66px; border-radius: 20px; background: #fff5ea; border: 2px solid #f3d0ae;
            display:flex; align-items:center; justify-content:center; color:#7b8794; font-size:1.45rem;
            transform: rotate(-8deg); box-shadow: 0 14px 28px rgba(148,163,184,.14); position: relative;
        }
        .title-sticker:after {
            content: "♡"; position: absolute; right: -11px; bottom: -12px; color: #fb8ca8; font-size: 1.9rem;
        }
        .page-title { font-size: clamp(2.05rem, 3.5vw, 2.9rem); font-weight: 900; letter-spacing: -.07em; line-height: 1.05; margin-bottom: 8px; }
        .page-sub { color: #677489; font-size: .98rem; font-weight: 760; line-height: 1.55; }

        .history-head {
            background: var(--card); border: 1px solid var(--border); border-radius: 28px; box-shadow: var(--shadow);
            padding: 24px 26px; display: grid; grid-template-columns: 1.1fr .55fr; gap: 16px; align-items: center;
            margin-bottom: 18px; position: relative; overflow: hidden;
        }
        .history-head:before {
            content: ""; position: absolute; left: 22px; right: 22px; top: 0; height: 4px;
            background: linear-gradient(90deg, #172554, #2563eb 78%); border-radius: 999px;
        }
        .history-head:after {
            content: ""; position: absolute; right: -12px; top: -10px; width: 126px; height: 126px;
            background: radial-gradient(circle, rgba(191,219,254,.55) 0%, rgba(191,219,254,.2) 48%, transparent 70%);
            border-radius: 50%;
        }
        .account-mini {
            justify-self: end; min-width: 210px; background: #f8fbff; border: 1px solid var(--border); border-radius: 20px;
            padding: 14px 16px; position: relative; z-index: 1;
        }
        .account-label { color: #718096; font-size: .68rem; font-weight: 900; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 6px; }
        .account-email { font-size: .92rem; font-weight: 900; color: #334155; }
        .account-name { color: #64748b; font-size: .84rem; font-weight: 800; margin-top: 3px; }

        .summary-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 18px; }
        .summary-card {
            background: var(--card); border: 1px solid var(--border); border-radius: 20px; box-shadow: 0 14px 30px rgba(15,23,42,.05);
            padding: 16px 16px 15px; position: relative; overflow: hidden;
        }
        .summary-card:before { content:""; position:absolute; left:14px; right:14px; top:0; height:3px; border-radius:999px; background:#cbd5e1; }
        .summary-card.total:before { background: linear-gradient(90deg, #172554, #2563eb); }
        .summary-card.pending:before { background: #f59e0b; }
        .summary-card.active:before { background: #22c55e; }
        .summary-card.closed:before { background: #94a3b8; }
        .summary-num { font-size: 1.65rem; font-weight: 900; letter-spacing: -.06em; margin-bottom: 4px; }
        .summary-label { color: var(--muted); font-size: .68rem; font-weight: 900; text-transform: uppercase; letter-spacing: .06em; }

        .empty {
            background: var(--card); border: 1px dashed #cbd5e1; border-radius: 24px; padding: 58px 24px; text-align: center;
            color: #64748b; font-weight: 850; box-shadow: var(--shadow);
        }
        .empty i { display: inline-flex; width: 56px; height: 56px; border-radius: 50%; background: #eff6ff; color: var(--blue); align-items: center; justify-content: center; font-size: 1.3rem; margin-bottom: 12px; }

        .history-list { display: grid; gap: 14px; }
        .booking-card {
            background: var(--card); border: 1px solid var(--border); border-radius: 24px; box-shadow: var(--shadow); padding: 18px;
        }
        .booking-top { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; margin-bottom: 14px; }
        .booking-name { font-size: 1.08rem; font-weight: 900; line-height: 1.4; margin-bottom: 6px; }
        .plate { display:inline-flex; background:#111827; color:#fff; border:2px solid #334155; padding:5px 10px; border-radius:10px; font-family:monospace; font-weight:900; letter-spacing:.06em; font-size:.8rem; margin-left:6px; }
        .small { color: var(--muted); font-size: .79rem; line-height: 1.55; font-weight: 760; }
        .badge { padding: 6px 11px; border-radius: 999px; font-size: .66rem; font-weight: 900; display:inline-flex; text-transform:uppercase; letter-spacing:.05em; white-space:nowrap; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-approved { background: #dcfce7; color: #166534; }
        .badge-waiting { background: #e0f2fe; color: #075985; }
        .badge-checkedin { background: #dbeafe; color: #1d4ed8; }
        .badge-completed { background: #f1f5f9; color: #475569; }
        .badge-rejected { background: #fee2e2; color: #991b1b; }
        .badge-default { background: #f3f4f6; color: #374151; }
        .info-grid { display:grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 10px; margin-bottom: 14px; }
        .info-box { background:#f8fafc; border:1px solid var(--border); border-radius:16px; padding:12px; min-height:96px; }
        .info-label { font-size:.66rem; font-weight:900; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; margin-bottom:7px; }
        .info-value { font-weight: 900; color:#0f172a; line-height:1.45; word-break:break-word; font-size:.86rem; }
        .btn {
            border:none; cursor:pointer; padding:11px 15px; border-radius: 999px; font-weight:900; font-size:.82rem; display:inline-flex; align-items:center; justify-content:center; gap:8px; transition:.18s ease;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { background: linear-gradient(135deg, #38bdf8, #2563eb); color:#fff; box-shadow:0 14px 26px rgba(37,99,235,.18); }

        @media (max-width: 900px) {
            .history-head { grid-template-columns: 1fr; }
            .account-mini { justify-self: stretch; }
            .summary-row, .info-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 720px) {
            .visitor-navbar { height: auto; padding: 14px 5%; align-items: flex-start; flex-direction: column; }
            .nav-links { width: 100%; display:grid; grid-template-columns: 1fr 1fr; }
            .nav-links a { justify-content: center; }
            .summary-row, .info-grid { grid-template-columns: 1fr; }
            .booking-top, .title-box { flex-direction: column; align-items: flex-start; }
            .plate { display:block; width:max-content; margin:8px 0 0; }
            .btn { width: 100%; }
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

        <a href="visitor_history.php" class="active">
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
    <div class="title-box">
        <div class="title-sticker"><i class="fas fa-clock-rotate-left"></i></div>
        <div>
            <h1 class="page-title">Visit History</h1>
            <p class="page-sub">View your previous visit requests, approval status, parking slot and QR pass access.</p>
        </div>
    </div>

    <section class="history-head">
        <div>
            <div style="display:inline-flex;align-items:center;gap:8px;background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;border-radius:999px;padding:6px 12px;font-size:.72rem;font-weight:900;margin-bottom:12px;">
                <i class="fas fa-receipt"></i> Visitor Records
            </div>
            <div class="page-sub">Track all your submitted bookings in one place. Once a request is approved, you can open the QR pass directly from the record card.</div>
        </div>

        <div class="account-mini">
            <div class="account-label">Visitor Account</div>
            <div class="account-email"><?= e($visitorEmail) ?></div>
            <div class="account-name"><?= e($visitorName) ?></div>
        </div>
    </section>

    <div class="summary-row">
        <div class="summary-card total">
            <div class="summary-num"><?= (int)$total ?></div>
            <div class="summary-label">Total Records</div>
        </div>
        <div class="summary-card pending">
            <div class="summary-num" style="color:var(--amber)"><?= (int)$pending ?></div>
            <div class="summary-label">Pending</div>
        </div>
        <div class="summary-card active">
            <div class="summary-num" style="color:var(--green)"><?= (int)$active ?></div>
            <div class="summary-label">Active Pass</div>
        </div>
        <div class="summary-card closed">
            <div class="summary-num" style="color:#64748b"><?= (int)$closed ?></div>
            <div class="summary-label">Closed / Expired</div>
        </div>
    </div>

    <?php if (empty($history)): ?>
        <div class="empty">
            <i class="fas fa-inbox"></i>
            <div>No visit record yet.</div>
        </div>
    <?php else: ?>
        <div class="history-list">
            <?php foreach ($history as $booking): ?>
                <?php
                    $residentName = $booking['resident_name'] ?: $booking['resident_email'];
                    $unitText = 'No unit';
                    if (!empty($booking['unit_no'])) {
                        $unitText = 'Block ' . $booking['block_no'] . ' / Floor ' . $booking['floor_no'] . ' / Unit ' . $booking['unit_no'];
                    }
                    $slotText = 'Not assigned yet';
                    if (!empty($booking['parking_block']) && !empty($booking['parking_slot_no'])) {
                        $slotText = $booking['parking_block'] . ' ' . $booking['parking_slot_no'];
                    }
                    $canViewPass = in_array($booking['status'], ['approved', 'allocated', 'waiting', 'checked_in'], true);
                ?>
                <article class="booking-card">
                    <div class="booking-top">
                        <div>
                            <div class="booking-name">
                                <?= e(safe_text_history($booking['visitor_name'])) ?>
                                <span class="plate"><?= e(safe_text_history($booking['plate_no'])) ?></span>
                            </div>
                            <div class="small">Submitted: <?= e(date('d M Y, g:i A', strtotime($booking['created_at'] ?? 'now'))) ?></div>
                        </div>
                        <span class="badge <?= e(booking_status_class_history($booking['status'])) ?>"><?= e(safe_text_history($booking['status'])) ?></span>
                    </div>

                    <div class="info-grid">
                        <div class="info-box">
                            <div class="info-label">Visit Time</div>
                            <div class="info-value">
                                <?= e(date('d M Y', strtotime($booking['start_time'] ?? 'now'))) ?><br>
                                <?= e(date('g:i A', strtotime($booking['start_time'] ?? 'now'))) ?> - <?= e(date('g:i A', strtotime($booking['end_time'] ?? 'now'))) ?>
                            </div>
                        </div>
                        <div class="info-box">
                            <div class="info-label">Resident</div>
                            <div class="info-value"><?= e(safe_text_history($residentName)) ?></div>
                            <div class="small"><?= e($unitText) ?></div>
                        </div>
                        <div class="info-box">
                            <div class="info-label">Purpose</div>
                            <div class="info-value"><?= e(safe_text_history($booking['purpose'])) ?></div>
                        </div>
                        <div class="info-box">
                            <div class="info-label">Parking Slot</div>
                            <div class="info-value"><?= e($slotText) ?></div>
                        </div>
                    </div>

                    <?php if ($canViewPass): ?>
                        <a href="visitor_pass.php?id=<?= (int)$booking['id'] ?>" class="btn btn-primary">
                            <i class="fas fa-qrcode"></i>
                            View QR Pass
                        </a>
                    <?php else: ?>
                        <div class="small">QR pass will be available after resident approval.</div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
