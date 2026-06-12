<?php
require_once '../core/security.php';
require_login(['visitor', 'resident', 'guard', 'admin', 'superadmin']);

$pdo = db();

if (file_exists('../core/parking_auto.php')) {
    require_once '../core/parking_auto.php';

    if (function_exists('run_parking_automation')) {
        run_parking_automation($pdo);
    }
}

$currentUserId = (int)($_SESSION['uid'] ?? 0);
$currentRole = $_SESSION['role'] ?? '';
$currentEmail = $_SESSION['email'] ?? '';

function safe_text($value) {
    return $value !== null && $value !== '' ? $value : '-';
}

function has_column_pass(PDO $pdo, string $table, string $column): bool {
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

function generate_qr_token_pass(): string {
    return bin2hex(random_bytes(24));
}

function status_class_pass($status) {
    return match ($status) {
        'pending' => 'badge-pending',
        'approved', 'allocated' => 'badge-approved',
        'waiting' => 'badge-waiting',
        'checked_in' => 'badge-checkedin',
        'completed', 'checked_out', 'closed' => 'badge-completed',
        'rejected', 'cancelled', 'expired' => 'badge-rejected',
        default => 'badge-default'
    };
}

function pass_status_text($status) {
    return match ($status) {
        'pending' => 'Waiting for resident approval',
        'approved' => 'Approved',
        'allocated' => 'Approved with parking slot',
        'waiting' => 'Approved but waiting for parking slot',
        'checked_in' => 'Visitor already checked in',
        'completed', 'checked_out', 'closed' => 'Visit completed',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
        default => safe_text($status)
    };
}

function can_show_qr($status): bool {
    return in_array($status, ['approved', 'allocated', 'waiting', 'checked_in'], true);
}

$hasFullName = has_column_pass($pdo, 'users', 'full_name');
$hasContact = has_column_pass($pdo, 'users', 'contact_number');

$hasPurpose = has_column_pass($pdo, 'bookings', 'purpose');
$hasQrToken = has_column_pass($pdo, 'bookings', 'qr_token');
$hasSlotId = has_column_pass($pdo, 'bookings', 'slot_id');
$hasVisitorType = has_column_pass($pdo, 'bookings', 'visitor_type');
$hasVisitType = has_column_pass($pdo, 'bookings', 'visit_type');
$hasUpdatedAt = has_column_pass($pdo, 'bookings', 'updated_at');

$bookingId = (int)($_GET['id'] ?? 0);

if ($bookingId <= 0 && $currentRole === 'visitor') {
    $stmt = $pdo->prepare("
        SELECT id
        FROM bookings
        WHERE visitor_user_id = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$currentUserId]);
    $latest = $stmt->fetch();

    if ($latest) {
        $bookingId = (int)$latest['id'];
    }
}

if ($bookingId <= 0) {
    http_response_code(404);
    die('Booking pass not found.');
}

$visitorNameSql = $hasFullName ? "vu.full_name AS visitor_account_name" : "NULL AS visitor_account_name";
$visitorContactSql = $hasContact ? "vu.contact_number AS visitor_contact" : "NULL AS visitor_contact";

$residentNameSql = $hasFullName ? "res.full_name AS resident_name" : "NULL AS resident_name";
$residentContactSql = $hasContact ? "res.contact_number AS resident_contact" : "NULL AS resident_contact";

$purposeSql = $hasPurpose ? "b.purpose" : "NULL AS purpose";
$qrTokenSql = $hasQrToken ? "b.qr_token" : "NULL AS qr_token";
$visitorTypeSql = $hasVisitorType ? "b.visitor_type" : "NULL AS visitor_type";
$visitTypeSql = $hasVisitType ? "b.visit_type" : "NULL AS visit_type";

$slotJoin = $hasSlotId
    ? "LEFT JOIN parking_slots ps ON ps.id = b.slot_id"
    : "LEFT JOIN parking_slots ps ON 1 = 0";

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
        {$purposeSql},
        {$qrTokenSql},
        {$visitorTypeSql},
        {$visitTypeSql},

        vu.email AS visitor_email,
        {$visitorNameSql},
        {$visitorContactSql},

        res.email AS resident_email,
        {$residentNameSql},
        {$residentContactSql},

        a.apartment_name,
        a.address,
        un.block_no,
        un.floor_no,
        un.unit_no,

        ps.block_name AS parking_block,
        ps.slot_no AS parking_slot_no,
        ps.status AS parking_status

    FROM bookings b

    LEFT JOIN users vu ON vu.id = b.visitor_user_id
    LEFT JOIN users res ON res.id = b.resident_id

    LEFT JOIN resident_units ru
        ON ru.resident_id = b.resident_id
        AND ru.status = 'active'

    LEFT JOIN units un ON un.id = ru.unit_id
    LEFT JOIN apartments a ON a.id = un.apartment_id

    {$slotJoin}

    WHERE b.id = ?
    LIMIT 1
");
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    http_response_code(404);
    die('Booking not found.');
}

$allowed = false;

if ($currentRole === 'visitor' && (int)$booking['visitor_user_id'] === $currentUserId) {
    $allowed = true;
}

if ($currentRole === 'resident' && (int)$booking['resident_id'] === $currentUserId) {
    $allowed = true;
}

if (in_array($currentRole, ['guard', 'admin', 'superadmin'], true)) {
    $allowed = true;
}

if (!$allowed) {
    http_response_code(403);
    die('403 Forbidden: You do not have permission to view this visitor pass.');
}

if ($hasQrToken && empty($booking['qr_token']) && can_show_qr($booking['status'])) {
    $newToken = generate_qr_token_pass();

    $sql = "
        UPDATE bookings
        SET qr_token = ?
    ";

    if ($hasUpdatedAt) {
        $sql .= ", updated_at = NOW()";
    }

    $sql .= " WHERE id = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $newToken,
        $bookingId
    ]);

    $booking['qr_token'] = $newToken;
}

$qrText = $booking['qr_token'] ?: $booking['plate_no'];

$visitorDisplayName = $booking['visitor_name'] ?: ($booking['visitor_account_name'] ?: $booking['visitor_email']);
$residentDisplayName = $booking['resident_name'] ?: $booking['resident_email'];

$unitText = 'No unit assigned';

if (!empty($booking['unit_no'])) {
    $unitText =
        'Block ' . $booking['block_no'] .
        ' / Floor ' . $booking['floor_no'] .
        ' / Unit ' . $booking['unit_no'];
}

$slotText = 'Not assigned';

if (!empty($booking['parking_block']) && !empty($booking['parking_slot_no'])) {
    $slotText = $booking['parking_block'] . ' ' . $booking['parking_slot_no'];
}

$now = time();
$startTimestamp = strtotime($booking['start_time'] ?? 'now');
$endTimestamp = strtotime($booking['end_time'] ?? 'now');

$isExpiredByTime = $endTimestamp < $now && !in_array($booking['status'], ['completed', 'checked_out', 'closed'], true);
$isUpcoming = $startTimestamp > $now;
$isActiveTime = $startTimestamp <= $now && $endTimestamp >= $now;

$showQr = can_show_qr($booking['status']);
$passReady = $showQr && !$isExpiredByTime;

$validityText = 'Not active';

if ($isUpcoming) {
    $validityText = 'Upcoming';
}

if ($isActiveTime) {
    $validityText = 'Currently valid';
}

if ($isExpiredByTime) {
    $validityText = 'Expired by time';
}

if (in_array($booking['status'], ['completed', 'checked_out', 'closed'], true)) {
    $validityText = 'Completed';
}

if (in_array($booking['status'], ['rejected', 'cancelled', 'expired'], true)) {
    $validityText = 'Not valid';
}

if (function_exists('log_audit')) {
    try {
        log_audit('VISITOR_PASS_VIEWED', 'Visitor pass viewed for booking #' . $bookingId . ' by ' . $currentRole);
    } catch (Throwable $e) {
        // ignore audit error
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Visitor Pass - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --success: #16a34a;
            --warning: #d97706;
            --danger: #dc2626;
            --info: #0284c7;
            --text: #111827;
            --muted: #6b7280;
            --border: #e5e7eb;
            --shadow: 0 18px 45px rgba(15, 23, 42, 0.08);
        }

        * {
            box-sizing: border-box;
            font-family: 'Plus Jakarta Sans', sans-serif;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(37, 99, 235, 0.14), transparent 30%),
                linear-gradient(180deg, #f8fafc 0%, #eef2f7 100%);
            color: var(--text);
        }

        .topbar {
            background: rgba(255,255,255,.94);
            backdrop-filter: blur(14px);
            padding: 16px 5%;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .logo {
            font-weight: 900;
            font-size: 1.25rem;
            letter-spacing: -0.04em;
            color: #111827;
            white-space: nowrap;
        }

        .logo span {
            color: var(--primary);
        }

        .nav-links {
            display: flex;
            gap: 9px;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-end;
        }

        .nav-links a {
            text-decoration: none;
            color: #334155;
            background: white;
            border: 1px solid var(--border);
            padding: 9px 12px;
            border-radius: 12px;
            font-weight: 900;
            font-size: .82rem;
        }

        .nav-links a.logout {
            color: #dc2626;
        }

        .container {
            max-width: 1120px;
            margin: 35px auto;
            padding: 0 20px 60px;
        }

        .pass-shell {
            display: grid;
            grid-template-columns: .95fr 1.05fr;
            gap: 22px;
            align-items: start;
        }

        .qr-panel {
            background: linear-gradient(135deg, #0f172a, #1d4ed8);
            color: white;
            border-radius: 30px;
            padding: 30px;
            box-shadow: var(--shadow);
            position: sticky;
            top: 92px;
        }

        .pass-title {
            font-size: 2rem;
            font-weight: 900;
            letter-spacing: -0.05em;
            margin-bottom: 8px;
        }

        .pass-sub {
            color: rgba(255,255,255,.78);
            font-weight: 700;
            line-height: 1.55;
            margin-bottom: 22px;
        }

        .qr-card {
            background: white;
            color: #111827;
            border-radius: 26px;
            padding: 24px;
            text-align: center;
            margin-bottom: 18px;
        }

        #qrcode {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 260px;
        }

        #qrcode img,
        #qrcode canvas {
            border-radius: 12px;
        }

        .qr-locked {
            min-height: 260px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 18px;
            padding: 18px;
            color: #64748b;
            font-weight: 900;
            line-height: 1.5;
        }

        .qr-locked i {
            display: block;
            font-size: 3rem;
            margin-bottom: 12px;
            color: #94a3b8;
        }

        .token-box {
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.22);
            border-radius: 18px;
            padding: 15px;
            margin-bottom: 14px;
        }

        .token-label {
            font-size: .68rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: rgba(255,255,255,.7);
            margin-bottom: 7px;
        }

        .token-value {
            font-family: monospace;
            font-weight: 900;
            word-break: break-all;
            line-height: 1.45;
        }

        .warning-box {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fcd34d;
            padding: 14px;
            border-radius: 16px;
            font-size: .84rem;
            font-weight: 800;
            line-height: 1.55;
            margin-top: 14px;
        }

        .success-box {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
            padding: 14px;
            border-radius: 16px;
            font-size: .84rem;
            font-weight: 800;
            line-height: 1.55;
            margin-top: 14px;
        }

        .danger-box {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
            padding: 14px;
            border-radius: 16px;
            font-size: .84rem;
            font-weight: 800;
            line-height: 1.55;
            margin-top: 14px;
        }

        .panel {
            background: rgba(255,255,255,.97);
            border: 1px solid var(--border);
            border-radius: 24px;
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 22px;
        }

        .panel-header {
            padding: 20px 22px;
            border-bottom: 1px solid var(--border);
            font-weight: 900;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .panel-title {
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .panel-body {
            padding: 22px;
        }

        .plate {
            display: inline-block;
            background: #111827;
            color: white;
            border: 2px solid #334155;
            padding: 8px 12px;
            border-radius: 12px;
            font-family: monospace;
            font-weight: 900;
            letter-spacing: .08em;
            font-size: 1rem;
        }

        .badge {
            padding: 6px 10px;
            border-radius: 999px;
            font-size: .68rem;
            font-weight: 900;
            display: inline-flex;
            text-transform: uppercase;
            letter-spacing: .04em;
            white-space: nowrap;
        }

        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-approved {
            background: #dcfce7;
            color: #166534;
        }

        .badge-waiting {
            background: #e0f2fe;
            color: #075985;
        }

        .badge-checkedin {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-completed {
            background: #f1f5f9;
            color: #475569;
        }

        .badge-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-default {
            background: #f3f4f6;
            color: #374151;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .info-box {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 15px;
        }

        .info-label {
            font-size: .7rem;
            font-weight: 900;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 7px;
        }

        .info-value {
            font-weight: 900;
            color: #111827;
            line-height: 1.45;
            word-break: break-word;
        }

        .timeline {
            display: grid;
            gap: 12px;
        }

        .timeline-item {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 15px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }

        .timeline-icon {
            width: 38px;
            height: 38px;
            border-radius: 14px;
            background: #dbeafe;
            color: #1d4ed8;
            display: flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
        }

        .timeline-title {
            font-weight: 900;
            color: #111827;
        }

        .small {
            color: #64748b;
            font-size: .8rem;
            margin-top: 4px;
            line-height: 1.5;
            font-weight: 700;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .btn {
            border: none;
            cursor: pointer;
            padding: 11px 15px;
            border-radius: 13px;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            font-size: .84rem;
            transition: .2s;
            white-space: nowrap;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 14px 25px rgba(37,99,235,.22);
        }

        .btn-light {
            background: white;
            color: #111827;
            border: 1px solid var(--border);
        }

        .btn-dark {
            background: #111827;
            color: white;
        }

        @media print {
            .topbar,
            .actions {
                display: none !important;
            }

            body {
                background: white;
            }

            .container {
                margin: 0;
                max-width: 100%;
                padding: 0;
            }

            .pass-shell {
                grid-template-columns: 1fr 1fr;
            }

            .qr-panel,
            .panel {
                box-shadow: none;
            }
        }

        @media (max-width: 960px) {
            .pass-shell {
                grid-template-columns: 1fr;
            }

            .qr-panel {
                position: static;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 620px) {
            .topbar {
                flex-direction: column;
                align-items: flex-start;
            }

            .nav-links {
                width: 100%;
                display: grid;
                grid-template-columns: 1fr 1fr;
            }

            .nav-links a {
                text-align: center;
            }

            .actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }
    

        /* ===== Visitor Pass cute unified redesign ===== */
        :root {
            --bg: #eef3f8;
            --card: #ffffff;
            --text: #111827;
            --muted: #667085;
            --border: #dfe8f3;
            --blue: #2563eb;
            --blue-dark: #1d4ed8;
            --blue-soft: #eff6ff;
            --pink: #fb9db7;
            --yellow: #f9c96b;
            --green: #16a34a;
            --shadow: 0 20px 52px rgba(15,23,42,.08);
            --header-bg: #182437;
            --header-text: #ffffff;
            --header-muted: #d6e1f2;
        }

        body {
            min-height: 100vh;
            color: var(--text);
            overflow-x: hidden;
            position: relative;
            background:
                radial-gradient(circle at 18% 18%, rgba(203,213,225,.28), transparent 28%),
                radial-gradient(circle at 85% 20%, rgba(219,234,254,.55), transparent 30%),
                linear-gradient(180deg, #fbfdff 0%, var(--bg) 100%) !important;
        }

        a { text-decoration: none; }

        .cute-bg {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }

        .cloud {
            position: absolute;
            width: 110px;
            height: 36px;
            border: 2px solid #dce7f3;
            border-radius: 999px;
            background: rgba(255,255,255,.65);
            box-shadow: 0 10px 25px rgba(148,163,184,.10);
        }

        .cloud:before,
        .cloud:after {
            content: "";
            position: absolute;
            background: rgba(255,255,255,.85);
            border: 2px solid #dce7f3;
            border-bottom: none;
            border-radius: 999px 999px 0 0;
        }

        .cloud:before { width: 42px; height: 32px; left: 18px; top: -20px; }
        .cloud:after { width: 54px; height: 42px; right: 18px; top: -29px; }
        .cloud-left { left: 6%; top: 19%; }
        .cloud-right { right: 15%; top: 16%; transform: scale(.85); }

        .sparkle {
            position: absolute;
            color: #f6c765;
            font-size: 1.4rem;
            opacity: .75;
            animation: floatSparkle 4s ease-in-out infinite;
        }

        .s1 { left: 19%; top: 22%; }
        .s2 { left: 13%; top: 62%; color: #9cc8ff; animation-delay: .6s; }
        .s3 { right: 12%; top: 42%; color: #f6c765; animation-delay: 1.1s; }
        .s4 { right: 19%; top: 67%; color: #c6d8ee; animation-delay: 1.7s; }

        @keyframes floatSparkle {
            0%, 100% { transform: translateY(0) scale(1); opacity: .55; }
            50% { transform: translateY(-9px) scale(1.08); opacity: .95; }
        }

        .cute-plant {
            position: absolute;
            left: 8%;
            bottom: 8%;
            width: 120px;
            height: 150px;
            opacity: .95;
        }

        .pot {
            position: absolute;
            bottom: 0;
            left: 25px;
            width: 72px;
            height: 62px;
            background: #fff4e6;
            border: 2px solid #e7c8a5;
            border-radius: 28px 28px 30px 30px;
            box-shadow: 0 12px 22px rgba(148,163,184,.16);
        }

        .pot span {
            position: absolute;
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: #64748b;
            top: 26px;
        }

        .pot span:first-child { left: 19px; }
        .pot span:nth-child(2) { right: 19px; }
        .pot em {
            position: absolute;
            width: 18px;
            height: 9px;
            border: 2px solid #64748b;
            border-top: 0;
            border-radius: 0 0 999px 999px;
            left: 26px;
            bottom: 17px;
        }

        .leaf {
            position: absolute;
            background: #b9e2ae;
            border: 2px solid #8abd87;
            width: 45px;
            height: 28px;
            border-radius: 50% 0 50% 0;
        }

        .leaf-one { left: 37px; top: 45px; transform: rotate(-42deg); }
        .leaf-two { left: 61px; top: 48px; transform: rotate(30deg); }
        .leaf-three { left: 48px; top: 20px; width: 34px; height: 52px; transform: rotate(16deg); }

        .cute-blob {
            position: absolute;
            border: 2px solid rgba(147,197,253,.55);
            background: rgba(255,255,255,.42);
            border-radius: 60% 40% 50% 50%;
        }

        .blob-one { width: 46px; height: 28px; right: 20%; top: 52%; transform: rotate(-14deg); }
        .blob-two { width: 42px; height: 28px; left: 18%; bottom: 19%; transform: rotate(18deg); }

        .visitor-navbar {
            height: 64px;
            min-height: 64px;
            width: 100%;
            padding: 0 5%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--header-bg);
            color: var(--header-text);
            border-bottom: 1px solid rgba(255,255,255,.06);
            box-shadow: 0 10px 28px rgba(15,23,42,.12);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .logo {
            font-size: 1.35rem;
            line-height: 1;
            font-weight: 900;
            letter-spacing: -.04em;
            color: #fff;
            white-space: nowrap;
        }

        .logo span { color: var(--blue); }

        .nav-links {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: nowrap;
        }

        .nav-links a {
            min-height: 38px;
            padding: 9px 15px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: .86rem;
            line-height: 1;
            font-weight: 800;
            text-decoration: none;
            color: var(--header-muted);
            background: rgba(255,255,255,.04);
            border: 1px solid rgba(255,255,255,.10);
        }

        .nav-links a:hover,
        .nav-links a.active {
            color: #fff;
            border-color: rgba(147,197,253,.48);
            background: rgba(37,99,235,.20);
        }

        .nav-links a.logout {
            color: #ef4444;
            background: rgba(255,255,255,.96);
            border-color: rgba(255,255,255,.90);
        }

        .container.visitor-pass-page {
            position: relative;
            z-index: 2;
            max-width: 1140px;
            margin: 28px auto 0;
            padding: 0 20px 60px;
        }

        .page-title-box {
            width: min(760px, 100%);
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 14px;
            text-align: left;
        }

        .title-sticker {
            position: relative;
            width: 60px;
            height: 60px;
            border-radius: 19px;
            display: grid;
            place-items: center;
            transform: rotate(-8deg);
            color: #64748b;
            background: #fff7ed;
            border: 1px solid #fed7aa;
            box-shadow: 0 14px 24px rgba(251,146,60,.12);
            flex: 0 0 60px;
        }

        .title-sticker i { font-size: 1.35rem; }
        .title-sticker b {
            position: absolute;
            width: 26px;
            height: 26px;
            right: -11px;
            bottom: -8px;
            display: grid;
            place-items: center;
            border-radius: 999px;
            background: #ffd3de;
            border: 1px solid #ffadc1;
            color: #ef5b87;
            font-size: .86rem;
            transform: rotate(8deg);
        }

        .page-kicker {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            height: 27px;
            padding: 0 11px;
            border-radius: 999px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: var(--blue);
            font-size: .74rem;
            font-weight: 900;
            margin-bottom: 6px;
        }

        .page-title {
            color: #fff;
            font-size: clamp(2.3rem, 4vw, 3.25rem);
            line-height: .98;
            font-weight: 950;
            letter-spacing: -.07em;
            text-shadow: 0 12px 26px rgba(15,23,42,.16);
        }

        .page-sub {
            margin-top: 8px;
            color: #52657e;
            font-size: .98rem;
            line-height: 1.6;
            font-weight: 760;
        }

        .tiny-heart { color: #fb7185; font-weight: 900; }

        .pass-title-box {
            width: min(1040px, 100%);
            justify-content: space-between;
            background: rgba(255,255,255,.72);
            border: 1px solid rgba(219,232,243,.85);
            border-radius: 30px;
            padding: 20px 22px;
            box-shadow: 0 20px 52px rgba(15,23,42,.06);
            backdrop-filter: blur(16px);
        }

        .page-title-copy { flex: 1; min-width: 0; }
        .pass-title-box .page-title { color: #111827; text-shadow: none; }
        .hero-status-card {
            min-width: 230px;
            padding: 16px;
            border-radius: 22px;
            background: rgba(255,255,255,.88);
            border: 1px solid #dfe8f3;
            display: grid;
            gap: 8px;
            box-shadow: 0 14px 30px rgba(15,23,42,.04);
        }

        .hero-status-card strong {
            font-size: 1.1rem;
            letter-spacing: .06em;
        }

        .hero-status-card small {
            color: #64748b;
            font-weight: 800;
            line-height: 1.35;
        }

        .pass-shell {
            grid-template-columns: minmax(390px, 440px) minmax(0, 1fr);
            gap: 24px;
            align-items: start;
        }

        .qr-panel {
            top: 84px;
            border-radius: 34px;
            padding: 30px;
            background:
                radial-gradient(circle at 88% 12%, rgba(96,165,250,.28), transparent 28%),
                linear-gradient(145deg, #172554 0%, #1d4ed8 72%, #2563eb 100%);
            overflow: hidden;
            box-shadow: 0 28px 70px rgba(29,78,216,.20);
        }

        .qr-panel::before,
        .qr-panel::after {
            content: "";
            position: absolute;
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: rgba(255,255,255,.10);
        }

        .qr-panel::before { right: -40px; top: -36px; }
        .qr-panel::after { left: -56px; bottom: -54px; }

        .pass-title,
        .pass-sub,
        .qr-card,
        .token-box,
        .qr-panel .actions,
        .qr-panel .success-box,
        .qr-panel .warning-box,
        .qr-panel .danger-box {
            position: relative;
            z-index: 1;
        }

        .pass-title {
            font-size: 2.15rem;
            letter-spacing: -.07em;
        }

        .qr-card {
            border-radius: 28px;
            padding: 24px;
            box-shadow: inset 0 0 0 1px rgba(15,23,42,.06), 0 20px 44px rgba(15,23,42,.14);
        }

        #qrcode {
            min-height: 250px;
        }

        #qrcode img,
        #qrcode canvas {
            width: min(250px, 100%) !important;
            height: auto !important;
            border-radius: 16px;
        }

        .token-box {
            border-radius: 20px;
            background: rgba(255,255,255,.14);
            box-shadow: inset 0 1px 0 rgba(255,255,255,.12);
        }

        .token-value {
            font-size: .82rem;
        }

        .panel {
            border-radius: 28px;
            margin-bottom: 20px;
            background: rgba(255,255,255,.82);
            border: 1px solid rgba(219,232,243,.92);
            box-shadow: 0 20px 52px rgba(15,23,42,.07);
            backdrop-filter: blur(16px);
        }

        .panel-header {
            min-height: 58px;
            padding: 0 22px;
            background: rgba(255,255,255,.58);
        }

        .panel-title i {
            color: var(--blue);
        }

        .panel-body {
            padding: 20px 22px 22px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0,1fr));
            gap: 14px;
        }

        .info-box {
            border-radius: 18px;
            background: rgba(248,251,255,.82);
            border: 1px solid #dfe8f3;
            padding: 15px 16px;
            min-height: 74px;
        }

        .info-label {
            color: #6b7f99;
            font-size: .72rem;
            letter-spacing: .07em;
            font-weight: 900;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .info-value {
            color: #0f172a;
            font-size: .98rem;
            font-weight: 900;
            line-height: 1.35;
        }

        .small {
            margin-top: 4px;
            color: #64748b;
            font-size: .78rem;
            font-weight: 750;
            line-height: 1.35;
        }

        .plate {
            border-radius: 12px;
            background: #0f172a;
            color: #fff;
            border: 0;
            padding: 9px 13px;
            box-shadow: 0 12px 22px rgba(15,23,42,.16);
        }

        .badge {
            padding: 7px 12px;
            border-radius: 999px;
            font-size: .68rem;
            font-weight: 950;
        }

        .timeline {
            gap: 12px;
        }

        .timeline-item {
            border-radius: 18px;
            background: rgba(248,251,255,.72);
            border: 1px solid #dfe8f3;
            padding: 14px;
        }

        .timeline-icon {
            border-radius: 15px;
            background: #eff6ff;
            color: var(--blue);
        }

        .btn {
            border-radius: 16px;
            min-height: 42px;
        }

        .btn-dark {
            background: #0f172a;
            color: #fff;
        }

        .btn-light {
            background: #fff;
            border: 1px solid #dbeafe;
            color: #0f172a;
        }

        .btn-primary {
            background: linear-gradient(135deg, #38bdf8, #2563eb 70%);
        }

        .success-box,
        .warning-box,
        .danger-box {
            border-radius: 18px;
            font-size: .84rem;
        }

        @media (max-width: 1050px) {
            .pass-title-box {
                flex-direction: column;
                align-items: flex-start;
            }

            .hero-status-card {
                width: 100%;
            }
        }

        @media (max-width: 960px) {
            .pass-shell {
                grid-template-columns: 1fr;
            }

            .qr-panel {
                position: relative;
                top: auto;
            }
        }

        @media (max-width: 720px) {
            .visitor-navbar {
                height: auto;
                min-height: 64px;
                padding: 12px 18px;
                gap: 12px;
                flex-wrap: wrap;
            }

            .nav-links {
                width: 100%;
                justify-content: flex-start;
                overflow-x: auto;
                padding-bottom: 3px;
            }

            .nav-links a {
                font-size: .78rem;
                padding: 8px 12px;
            }

            .container.visitor-pass-page {
                padding: 0 14px 44px;
            }

            .page-title-box {
                flex-direction: column;
                align-items: flex-start;
                margin-bottom: 18px;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }
        }
</style>
</head>
<body>

<nav class="visitor-navbar">
    <div class="logo">Smart<span>VMS</span></div>

    <div class="nav-links">
        <?php if ($currentRole === 'visitor'): ?>
            <a href="visitor_book.php">
                <i class="fas fa-calendar-plus"></i>
                Book Visit
            </a>

            <?php
            if (file_exists('notification_badge.php')) {
                include 'notification_badge.php';
            } else {
            ?>
                <a href="notifications.php">
                    <i class="fas fa-bell"></i>
                    Notifications
                </a>
            <?php } ?>

            <a href="visitor_history.php">
                <i class="fas fa-clock-rotate-left"></i>
                History
            </a>

            <a href="visitor_profile.php">
                <i class="fas fa-user"></i>
                Profile
            </a>
        <?php endif; ?>

        <?php if ($currentRole === 'resident'): ?>
            <a href="resident.php">
                <i class="fas fa-home"></i>
                Resident
            </a>
        <?php endif; ?>

        <?php if ($currentRole === 'guard'): ?>
            <a href="guard_in.php">
                <i class="fas fa-arrow-right-to-bracket"></i>
                Guard In
            </a>
            <a href="guard_out.php">
                <i class="fas fa-arrow-right-from-bracket"></i>
                Guard Out
            </a>
        <?php endif; ?>

        <?php if (in_array($currentRole, ['admin', 'superadmin'], true)): ?>
            <a href="admin_dash.php">
                <i class="fas fa-gauge"></i>
                Admin
            </a>
        <?php endif; ?>

        <a href="../core/logout.php" class="logout">
            <i class="fas fa-sign-out-alt"></i>
            Logout
        </a>
    </div>
</nav>

<div class="container">
    <div class="pass-shell">
        <div class="qr-panel">
            <h1 class="pass-title">Visitor Pass</h1>
            <p class="pass-sub">
                Show this QR code to the guard at the entrance. The guard can scan the QR code or verify the vehicle plate number.
            </p>

            <div class="qr-card">
                <?php if ($passReady): ?>
                    <div id="qrcode"></div>
                <?php else: ?>
                    <div class="qr-locked">
                        <div>
                            <i class="fas fa-lock"></i>
                            QR pass is not valid now.
                            <br>
                            <?= e(pass_status_text($booking['status'])) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="token-box">
                <div class="token-label">QR Token / Manual Fallback</div>
                <div class="token-value" id="tokenText">
                    <?= e($qrText) ?>
                </div>
            </div>

            <div class="actions">
                <button type="button" class="btn btn-dark" onclick="copyToken()">
                    <i class="fas fa-copy"></i>
                    Copy Token
                </button>

                <button type="button" class="btn btn-light" onclick="window.print()">
                    <i class="fas fa-print"></i>
                    Print Pass
                </button>
            </div>

            <?php if ($passReady): ?>
                <div class="success-box">
                    This pass can be used for guard verification.
                </div>
            <?php elseif ($booking['status'] === 'pending'): ?>
                <div class="warning-box">
                    This booking is still pending. QR pass will be available after resident approval.
                </div>
            <?php elseif ($booking['status'] === 'waiting'): ?>
                <div class="warning-box">
                    Booking is approved but visitor parking is full. Guard can still verify the pass based on system rules.
                </div>
            <?php else: ?>
                <div class="danger-box">
                    This pass is not valid for entry now.
                </div>
            <?php endif; ?>
        </div>

        <div>
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="fas fa-id-card"></i>
                        Pass Status
                    </div>

                    <span class="badge <?= e(status_class_pass($booking['status'])) ?>">
                        <?= e($booking['status']) ?>
                    </span>
                </div>

                <div class="panel-body">
                    <div class="info-grid">
                        <div class="info-box">
                            <div class="info-label">Booking ID</div>
                            <div class="info-value">#<?= (int)$booking['id'] ?></div>
                        </div>

                        <div class="info-box">
                            <div class="info-label">Pass Validity</div>
                            <div class="info-value"><?= e($validityText) ?></div>
                        </div>

                        <div class="info-box">
                            <div class="info-label">Vehicle Plate</div>
                            <div class="info-value">
                                <span class="plate"><?= e(safe_text($booking['plate_no'])) ?></span>
                            </div>
                        </div>

                        <div class="info-box">
                            <div class="info-label">Parking Slot</div>
                            <div class="info-value"><?= e($slotText) ?></div>
                            <div class="small"><?= e(safe_text($booking['parking_status'])) ?></div>
                        </div>
                    </div>

                    <div class="actions">
                        <?php if ($currentRole === 'guard'): ?>
                            <a href="guard_scan.php" class="btn btn-primary">
                                <i class="fas fa-shield-halved"></i>
                                Open Guard Scan
                            </a>
                        <?php endif; ?>

                        <?php if ($currentRole === 'visitor'): ?>
                            <a href="visitor_book.php" class="btn btn-light">
                                <i class="fas fa-arrow-left"></i>
                                Back to My Bookings
                            </a>
                        <?php endif; ?>

                        <?php if ($currentRole === 'resident'): ?>
                            <a href="resident.php" class="btn btn-light">
                                <i class="fas fa-arrow-left"></i>
                                Back to Resident
                            </a>
                        <?php endif; ?>

                        <?php if (in_array($currentRole, ['admin', 'superadmin'], true)): ?>
                            <a href="admin_bookings.php?search=<?= urlencode($booking['plate_no']) ?>" class="btn btn-light">
                                <i class="fas fa-calendar-check"></i>
                                Admin Booking
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="fas fa-user"></i>
                        Visitor Details
                    </div>
                </div>

                <div class="panel-body">
                    <div class="info-grid">
                        <div class="info-box">
                            <div class="info-label">Visitor Name</div>
                            <div class="info-value"><?= e(safe_text($visitorDisplayName)) ?></div>
                        </div>

                        <div class="info-box">
                            <div class="info-label">Visitor Email</div>
                            <div class="info-value"><?= e(safe_text($booking['visitor_email'])) ?></div>
                        </div>

                        <div class="info-box">
                            <div class="info-label">Visitor Type</div>
                            <div class="info-value"><?= e(safe_text($booking['visitor_type'])) ?></div>
                        </div>

                        <div class="info-box">
                            <div class="info-label">Visit Type</div>
                            <div class="info-value"><?= e(safe_text($booking['visit_type'])) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="fas fa-house-user"></i>
                        Resident & Unit Details
                    </div>
                </div>

                <div class="panel-body">
                    <div class="info-grid">
                        <div class="info-box">
                            <div class="info-label">Resident</div>
                            <div class="info-value"><?= e(safe_text($residentDisplayName)) ?></div>
                            <div class="small"><?= e(safe_text($booking['resident_email'])) ?></div>
                        </div>

                        <div class="info-box">
                            <div class="info-label">Unit</div>
                            <div class="info-value"><?= e($unitText) ?></div>
                        </div>

                        <div class="info-box">
                            <div class="info-label">Apartment</div>
                            <div class="info-value"><?= e(safe_text($booking['apartment_name'])) ?></div>
                        </div>

                        <div class="info-box">
                            <div class="info-label">Purpose</div>
                            <div class="info-value"><?= e(safe_text($booking['purpose'])) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="fas fa-clock"></i>
                        Visit Timeline
                    </div>
                </div>

                <div class="panel-body">
                    <div class="timeline">
                        <div class="timeline-item">
                            <div class="timeline-icon">
                                <i class="fas fa-calendar-plus"></i>
                            </div>

                            <div>
                                <div class="timeline-title">Booking Submitted</div>
                                <div class="small">
                                    <?= e(date('d M Y, g:i A', strtotime($booking['created_at'] ?? 'now'))) ?>
                                </div>
                            </div>
                        </div>

                        <div class="timeline-item">
                            <div class="timeline-icon">
                                <i class="fas fa-right-to-bracket"></i>
                            </div>

                            <div>
                                <div class="timeline-title">Expected Entry</div>
                                <div class="small">
                                    <?= e(date('d M Y, g:i A', strtotime($booking['start_time'] ?? 'now'))) ?>
                                </div>
                            </div>
                        </div>

                        <div class="timeline-item">
                            <div class="timeline-icon">
                                <i class="fas fa-right-from-bracket"></i>
                            </div>

                            <div>
                                <div class="timeline-title">Expected Exit</div>
                                <div class="small">
                                    <?= e(date('d M Y, g:i A', strtotime($booking['end_time'] ?? 'now'))) ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($isExpiredByTime): ?>
                        <div class="danger-box">
                            This pass has passed the expected exit time.
                        </div>
                    <?php elseif ($isUpcoming): ?>
                        <div class="warning-box">
                            This visit is upcoming. Guard should verify the actual visit time before allowing entry.
                        </div>
                    <?php elseif ($isActiveTime && $passReady): ?>
                        <div class="success-box">
                            This visit is currently within the approved visit time.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const qrText = <?= json_encode($qrText) ?>;
const passReady = <?= $passReady ? 'true' : 'false' ?>;

if (passReady) {
    if (typeof QRCode !== 'undefined') {
        new QRCode(document.getElementById("qrcode"), {
            text: qrText,
            width: 260,
            height: 260,
            correctLevel: QRCode.CorrectLevel.H
        });
    } else {
        document.getElementById("qrcode").innerHTML = `
            <div class="qr-locked">
                <div>
                    <i class="fas fa-triangle-exclamation"></i>
                    QR library failed to load.<br>
                    Use manual token below.
                </div>
            </div>
        `;
    }
}

function copyToken() {
    const token = document.getElementById('tokenText').innerText.trim();

    navigator.clipboard.writeText(token).then(() => {
        Swal.fire({
            icon: 'success',
            title: 'Copied',
            text: 'QR token copied. Guard can paste this token manually.',
            confirmButtonColor: '#2563eb'
        });
    }).catch(() => {
        Swal.fire({
            icon: 'warning',
            title: 'Copy Failed',
            text: 'Please copy the token manually.',
            confirmButtonColor: '#2563eb'
        });
    });
}
</script>

</body>
</html>