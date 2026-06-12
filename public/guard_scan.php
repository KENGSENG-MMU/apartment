<?php
require_once '../core/security.php';
require_login(['guard', 'admin', 'superadmin']);

$pdo = db();

$currentRole = $_SESSION['role'] ?? 'guard';
$currentEmail = $_SESSION['email'] ?? 'guard';
$userName = explode('@', $currentEmail)[0];

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function safe_text($value) {
    return $value !== null && $value !== '' ? $value : '-';
}

function table_exists_guard_scan(PDO $pdo, string $table): bool {
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

function has_column_guard_scan(PDO $pdo, string $table, string $column): bool {
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

function safe_count_guard_scan(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function badge_class_guard_scan($value) {
    $value = strtolower((string)$value);

    return match ($value) {
        'allow', 'entry', 'visitor', 'resident' => 'badge-success',
        'deny', 'blacklist', 'unknown' => 'badge-danger',
        'exit' => 'badge-warning',
        default => 'badge-default'
    };
}

function unit_text_guard_scan($row): string {
    if (empty($row['unit_no'])) {
        return '-';
    }

    return 'Block ' . $row['block_no'] .
        ' / Floor ' . $row['floor_no'] .
        ' / Unit ' . $row['unit_no'];
}

function slot_text_guard_scan($row): string {
    if (!empty($row['parking_block']) && !empty($row['parking_slot_no'])) {
        return $row['parking_block'] . ' ' . $row['parking_slot_no'];
    }

    return '-';
}

$hasGateLogs = table_exists_guard_scan($pdo, 'gate_logs');
$hasInputValue = $hasGateLogs && has_column_guard_scan($pdo, 'gate_logs', 'input_value');
$hasVehicleType = $hasGateLogs && has_column_guard_scan($pdo, 'gate_logs', 'vehicle_type');
$hasBookingSlotId = has_column_guard_scan($pdo, 'bookings', 'slot_id');

$inputValueSql = $hasInputValue ? "gl.input_value" : "NULL AS input_value";
$vehicleTypeSql = $hasVehicleType ? "gl.vehicle_type" : "NULL AS vehicle_type";

$slotJoinSql = $hasBookingSlotId
    ? "LEFT JOIN parking_slots ps ON ps.id = b.slot_id"
    : "LEFT JOIN parking_slots ps ON 1 = 0";

$recentLogs = [];

if ($hasGateLogs) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                gl.id,
                gl.booking_id,
                gl.plate_no,
                {$inputValueSql},
                {$vehicleTypeSql},
                gl.gate_action,
                gl.decision,
                gl.reason,
                gl.created_at,

                b.visitor_name,
                b.status AS booking_status,

                res.email AS resident_email,

                un.block_no,
                un.floor_no,
                un.unit_no,

                ps.block_name AS parking_block,
                ps.slot_no AS parking_slot_no

            FROM gate_logs gl

            LEFT JOIN bookings b ON b.id = gl.booking_id
            LEFT JOIN users res ON res.id = b.resident_id

            LEFT JOIN resident_units ru
                ON ru.resident_id = b.resident_id
                AND ru.status = 'active'

            LEFT JOIN units un ON un.id = ru.unit_id

            {$slotJoinSql}

            ORDER BY gl.created_at DESC
            LIMIT 8
        ");
        $stmt->execute();
        $recentLogs = $stmt->fetchAll();
    } catch (Throwable $e) {
        $recentLogs = [];
    }
}

$todayLogs = $hasGateLogs ? safe_count_guard_scan($pdo, "SELECT COUNT(*) FROM gate_logs WHERE DATE(created_at) = CURDATE()") : 0;
$todayAllow = $hasGateLogs ? safe_count_guard_scan($pdo, "SELECT COUNT(*) FROM gate_logs WHERE DATE(created_at) = CURDATE() AND decision = 'ALLOW'") : 0;
$todayDeny = $hasGateLogs ? safe_count_guard_scan($pdo, "SELECT COUNT(*) FROM gate_logs WHERE DATE(created_at) = CURDATE() AND decision = 'DENY'") : 0;
$checkedInNow = safe_count_guard_scan($pdo, "SELECT COUNT(*) FROM bookings WHERE status = 'checked_in'");
$availableSlots = safe_count_guard_scan($pdo, "SELECT COUNT(*) FROM parking_slots WHERE slot_type = 'Visitor' AND status = 'available'");
$activeBlacklist = safe_count_guard_scan($pdo, "SELECT COUNT(*) FROM blacklisted_plates WHERE status = 'active'");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Guard Scan - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>

    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        :root {
            --sage-bg: #dfe8dc;
            --sage-bg-2: #edf4ea;
            --sage-accent: #8ea88a;
            --sage-deep: #5f7c67;
            --sage-line: rgba(95, 124, 103, 0.18);
            --mint: #cfe2cf;
            --forest: #30463a;
            --gold: #d2b36b;
            --success: #2f855a;
            --danger: #c2413b;
            --warning: #b7791f;
            --text: #1f2d24;
            --muted: #617369;
            --card: rgba(255, 255, 255, 0.40);
            --card-strong: rgba(255, 255, 255, 0.56);
            --shadow: 0 18px 50px rgba(62, 81, 64, 0.10);
        }

        * {
            box-sizing: border-box;
            font-family: 'Plus Jakarta Sans', sans-serif;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            color: var(--text);
            background:
                radial-gradient(circle at 10% 8%, rgba(181, 201, 178, 0.55), transparent 25%),
                radial-gradient(circle at 92% 18%, rgba(207, 226, 207, 0.70), transparent 24%),
                linear-gradient(180deg, var(--sage-bg-2) 0%, var(--sage-bg) 100%);
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background:
                linear-gradient(180deg, rgba(255,255,255,0.22), rgba(255,255,255,0.06));
            pointer-events: none;
            z-index: -2;
        }

        a {
            text-decoration: none;
        }

        .guard-navbar {
            background: rgba(255,255,255,.42);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            padding: 14px 5%;
            border-bottom: 1px solid rgba(255,255,255,.45);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 20;
            box-shadow: 0 8px 30px rgba(62, 81, 64, 0.08);
        }

        .logo {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-weight: 900;
            font-size: 1.16rem;
            letter-spacing: -0.04em;
            color: var(--text);
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            border-radius: 14px;
            background: linear-gradient(135deg, #6d8a73, #4c6652);
            color: white;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 24px rgba(76, 102, 82, 0.20);
        }

        .logo span {
            color: var(--gold);
        }

        .nav-right {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 800;
            color: #415247;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .nav-link {
            color: #4c6152;
            background: rgba(255,255,255,.55);
            border: 1px solid rgba(255,255,255,.55);
            padding: 9px 12px;
            border-radius: 12px;
            font-size: .82rem;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            box-shadow: 0 8px 18px rgba(62, 81, 64, 0.05);
        }

        .nav-link.logout {
            color: var(--danger);
        }

        .container {
            max-width: 1180px;
            margin: 28px auto;
            padding: 0 20px 60px;
            position: relative;
        }

        .deco-wrap {
            position: absolute;
            inset: 0;
            pointer-events: none;
            z-index: 0;
        }

        .deco-guard {
            position: absolute;
            width: 88px;
            height: 88px;
            border-radius: 28px;
            background: rgba(255,255,255,.35);
            border: 1px solid rgba(255,255,255,.48);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            box-shadow: 0 18px 45px rgba(62, 81, 64, 0.08);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 6px;
            color: var(--sage-deep);
        }

        .deco-guard i {
            font-size: 1.55rem;
        }

        .deco-guard span {
            font-size: .68rem;
            font-weight: 900;
            color: #5d7262;
        }

        .deco-1 { top: 82px; right: -10px; transform: rotate(8deg); }
        .deco-2 { top: 420px; left: -18px; transform: rotate(-8deg); }
        .deco-3 { bottom: 160px; right: 28px; transform: rotate(6deg); }

        .header-row,
        .summary-row,
        .layout,
        .panel {
            position: relative;
            z-index: 1;
        }

        .header-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 18px;
        }

        .page-title {
            font-size: clamp(2rem, 4vw, 3rem);
            line-height: 1.05;
            font-weight: 900;
            letter-spacing: -0.065em;
            color: #26372d;
        }

        .page-sub {
            margin-top: 8px;
            color: var(--muted);
            font-weight: 650;
            line-height: 1.6;
            max-width: 760px;
        }

        .operator-card {
            min-width: 250px;
            background: var(--card);
            border: 1px solid rgba(255,255,255,.52);
            border-radius: 20px;
            padding: 15px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }

        .operator-label {
            color: var(--muted);
            font-size: .72rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 6px;
        }

        .operator-email {
            font-size: .88rem;
            font-weight: 850;
            word-break: break-word;
        }

        .operator-role {
            margin-top: 10px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(236, 253, 243, 0.85);
            color: #1f8a56;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: .72rem;
            font-weight: 850;
            text-transform: uppercase;
        }

        .summary-row {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }

        .summary-card {
            background: var(--card);
            border: 1px solid rgba(255,255,255,.50);
            border-radius: 22px;
            padding: 14px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }

        .summary-value {
            font-size: 1.42rem;
            font-weight: 900;
            letter-spacing: -0.05em;
            margin-bottom: 3px;
            color: #2d4335;
        }

        .summary-label {
            color: var(--muted);
            font-size: .7rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .055em;
        }

        .layout {
            display: grid;
            grid-template-columns: 1.08fr .92fr;
            gap: 22px;
            align-items: start;
        }

        .panel {
            background: var(--card);
            border: 1px solid rgba(255,255,255,.52);
            border-radius: 26px;
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 22px;
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }

        .panel-header {
            padding: 18px 22px;
            border-bottom: 1px solid rgba(255,255,255,.40);
            font-weight: 900;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            background: rgba(255,255,255,.12);
        }

        .panel-title {
            display: inline-flex;
            align-items: center;
            gap: 9px;
        }

        .panel-title i {
            color: var(--sage-deep);
        }

        .panel-body {
            padding: 22px;
        }

        .camera-box {
            position: relative;
            background: linear-gradient(180deg, #26372d 0%, #30463a 100%);
            border-radius: 22px;
            overflow: hidden;
            min-height: 360px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(255,255,255,.18);
        }

        video {
            width: 100%;
            min-height: 360px;
            max-height: 500px;
            object-fit: cover;
            display: block;
        }

        .camera-placeholder {
            color: #eef6ec;
            text-align: center;
            padding: 40px;
            font-weight: 800;
            line-height: 1.55;
        }

        .camera-placeholder i {
            display: block;
            font-size: 3rem;
            margin-bottom: 14px;
            opacity: .7;
        }

        .scan-frame {
            position: absolute;
            width: 72%;
            height: 34%;
            border: 3px solid #86efac;
            border-radius: 22px;
            box-shadow: 0 0 0 9999px rgba(15, 23, 42, .20);
            pointer-events: none;
        }

        .scan-frame::before,
        .scan-frame::after {
            content: "";
            position: absolute;
            left: 8%;
            right: 8%;
            height: 2px;
            background: rgba(134,239,172,.95);
            box-shadow: 0 0 14px rgba(134,239,172,.75);
        }
        .scan-frame::before { top: 50%; }
        .scan-frame::after { display: none; }

        .camera-mode-badge {
            position: absolute;
            top: 14px;
            left: 14px;
            background: rgba(255,255,255,.14);
            color: white;
            border: 1px solid rgba(255,255,255,.20);
            border-radius: 999px;
            padding: 8px 11px;
            font-size: .76rem;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            backdrop-filter: blur(10px);
        }

        .camera-status {
            position: absolute;
            left: 14px;
            right: 14px;
            bottom: 14px;
            background: rgba(255,255,255,.10);
            color: #f0fdf4;
            border: 1px solid rgba(255,255,255,.14);
            border-radius: 14px;
            padding: 10px 12px;
            font-size: .88rem;
            font-weight: 800;
            line-height: 1.45;
            backdrop-filter: blur(8px);
        }

        .camera-actions {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-top: 16px;
        }

        .btn {
            border: 1px solid rgba(255,255,255,.42);
            cursor: pointer;
            padding: 13px 14px;
            border-radius: 16px;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: .86rem;
            transition: .2s;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        .btn:hover { transform: translateY(-1px); }

        .btn-primary {
            background: linear-gradient(135deg, #6d8a73, #56705d);
            color: white;
            box-shadow: 0 14px 25px rgba(86, 112, 93, .18);
        }

        .btn-dark {
            background: rgba(48,70,58,.92);
            color: white;
        }

        .btn-light {
            background: rgba(255,255,255,.48);
            color: #1f2d24;
        }

        .btn-entry { background: linear-gradient(135deg, #3ba86d, #2f855a); color: white; }
        .btn-exit { background: linear-gradient(135deg, #d06b5a, #c2413b); color: white; }

        .verify-box { display: grid; gap: 16px; }

        label {
            display: block;
            font-size: .75rem;
            font-weight: 900;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 8px;
        }

        input {
            width: 100%;
            padding: 15px 16px;
            border: 1px solid rgba(255,255,255,.45);
            border-radius: 16px;
            font-size: 1rem;
            font-weight: 900;
            outline: none;
            background: rgba(255,255,255,.58);
            color: var(--text);
            backdrop-filter: blur(10px);
        }

        input:focus {
            border-color: #8ea88a;
            box-shadow: 0 0 0 4px rgba(142,168,138,.14);
        }

        .plate-input {
            text-transform: uppercase;
            font-family: monospace;
            letter-spacing: .06em;
        }

        .verify-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .hint {
            background: rgba(255,255,255,.38);
            color: #4c6152;
            border: 1px solid rgba(255,255,255,.45);
            padding: 14px;
            border-radius: 22px;
            font-size: .84rem;
            font-weight: 800;
            line-height: 1.55;
            backdrop-filter: blur(12px);
        }

        .result-box {
            background: rgba(255,255,255,.33);
            border: 1px solid rgba(255,255,255,.42);
            border-radius: 22px;
            padding: 18px;
            min-height: 115px;
            white-space: pre-wrap;
            font-size: .86rem;
            color: #334155;
            font-weight: 800;
            line-height: 1.55;
            backdrop-filter: blur(12px);
        }

        .status-card {
            border-radius: 20px;
            padding: 20px;
            margin-top: 16px;
            display: none;
            backdrop-filter: blur(12px);
        }

        .status-allow { background: rgba(220,252,231,.76); color: #166534; border: 1px solid rgba(91, 224, 140, 0.7); }
        .status-deny { background: rgba(254,226,226,.76); color: #991b1b; border: 1px solid rgba(252,165,165,.70); }
        .status-title { font-size: 1.15rem; font-weight: 900; margin-bottom: 8px; }
        .status-details { font-size: .88rem; font-weight: 800; line-height: 1.6; }

        .activity-list { display: grid; gap: 10px; }

        .log-card {
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 13px;
            align-items: center;
            background: rgba(255,255,255,.42);
            border: 1px solid rgba(255,255,255,.48);
            border-radius: 16px;
            padding: 13px;
            backdrop-filter: blur(12px);
        }

        .log-card.allow { border-left: 4px solid var(--success); }
        .log-card.deny { border-left: 4px solid var(--danger); }
        .log-title { color: var(--text); font-weight: 850; margin-bottom: 4px; }
        .log-meta { color: var(--muted); font-size: .8rem; line-height: 1.5; font-weight: 600; }
        .log-badges { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }

        .plate {
            display: inline-block;
            background: #2f4337;
            color: #ffffff;
            border-radius: 9px;
            padding: 6px 9px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-weight: 900;
            letter-spacing: .055em;
            font-size: .86rem;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 5px 8px;
            font-size: .68rem;
            font-weight: 850;
            text-transform: uppercase;
            letter-spacing: .04em;
            white-space: nowrap;
        }

        .badge-success { background: #ecfdf3; color: #bd0000; }
        .badge-danger { background: #fef3f2; color: var(--danger); }
        .badge-warning { background: #fffbeb; color: #92400e; }
        .badge-default { background: #f3f4f6; color: #4b5563; }

        .page-decor {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
        }

        .decor-guard-left {
            position: fixed;
            left: -58px;
            bottom: 10px;
            width: 500px;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            filter: drop-shadow(0 18px 28px rgba(62, 81, 64, 0.12));
        }

        .decor-guard-left img {
            width: 100%;
            height: auto;
            display: block;
        }

        .decor-shield-right {
            position: fixed;
            right: 78px;
            top: 195px;
            width: 185px;
            height: 185px;
            transform: rotate(14deg);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .decor-shield-right::before {
            content: "";
            position: absolute;
            inset: 8px;
            border-radius: 28px;
            background: rgba(255,255,255,.26);
            border: 1px solid rgba(138, 92, 255, 0.55);
            box-shadow: 0 18px 42px rgba(98, 79, 168, 0.12);
        }

        .shield-svg {
            position: relative;
            width: 145px;
            height: 145px;
            z-index: 1;
        }

        .empty {
            text-align: center;
            padding: 32px;
            border: 1px dashed rgba(95,124,103,.28);
            color: var(--muted);
            border-radius: 22px;
            font-weight: 700;
            background: rgba(255,255,255,.32);
            backdrop-filter: blur(12px);
        }

        canvas { display: none; }

        @media (max-width: 1050px) {
            .layout { grid-template-columns: 1fr; }
            .summary-row { grid-template-columns: repeat(3, 1fr); }
            .header-row { flex-direction: column; }
            .operator-card { min-width: 0; }
            .deco-wrap, .page-decor { display: none; }
        }

        @media (max-width: 720px) {
            .summary-row, .camera-actions, .verify-actions { grid-template-columns: 1fr 1fr; }
            .log-card { grid-template-columns: 1fr; }
            .log-badges { justify-content: flex-start; }
        }

        @media (max-width: 520px) {
            .summary-row, .camera-actions, .verify-actions { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<nav class="guard-navbar">
    <a href="guard_scan.php" class="logo">
        <span class="logo-icon"><i class="fas fa-shield-halved"></i></span>
        Smart<span>VMS</span> Guard
    </a>

    <div class="nav-right">
        <span><?= e($userName) ?></span>

        <?php if (in_array($currentRole, ['admin', 'superadmin'], true)): ?>
            <a href="admin_dash.php" class="nav-link">
                <i class="fas fa-gauge-high"></i>
                Admin
            </a>

            <a href="admin_gate_logs.php" class="nav-link">
                <i class="fas fa-clock-rotate-left"></i>
                Logs
            </a>
        <?php endif; ?>

        <a href="../core/logout.php" class="nav-link logout">
            <i class="fas fa-sign-out-alt"></i>
            Logout
        </a>
    </div>
</nav>

<div class="page-decor" aria-hidden="true">
    <div class="decor-guard-left">
        <img src="guard.png" alt="Cute guard illustration">
    </div>

    <div class="decor-shield-right">
        <svg class="shield-svg" viewBox="0 0 160 160" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M80 14L135 34V79C135 111 112 134 80 146C48 134 25 111 25 79V34L80 14Z" fill="#ffffff" stroke="#111111" stroke-width="6"/>
            <path d="M80 28L123 43V78C123 102 106 121 80 132C54 121 37 102 37 78V43L80 28Z" fill="#14a7d8" stroke="#111111" stroke-width="5"/>
            <path d="M80 28V132" stroke="#111111" stroke-width="5"/>
            <path d="M37 78H123" stroke="#111111" stroke-width="5"/>
        </svg>
    </div>
</div>

<div class="container">
<div class="header-row">
        <div>
            <h1 class="page-title">Guard Verification</h1>
            <p class="page-sub">
                One camera area for QR code and vehicle plate recognition. Start the camera, scan QR or plate, then verify entry or exit.
            </p>
        </div>

        <div class="operator-card">
            <div class="operator-label">Current Operator</div>
            <div class="operator-email"><?= e($currentEmail) ?></div>
            <div class="operator-role">
                <i class="fas fa-user-shield"></i>
                <?= e($currentRole) ?>
            </div>
        </div>
    </div>

    <section class="summary-row">
        <div class="summary-card">
            <div class="summary-value"><?= $todayLogs ?></div>
            <div class="summary-label">Today Logs</div>
        </div>

        <div class="summary-card">
            <div class="summary-value" style="color:#027a48;"><?= $todayAllow ?></div>
            <div class="summary-label">Allowed</div>
        </div>

        <div class="summary-card">
            <div class="summary-value" style="color:#b42318;"><?= $todayDeny ?></div>
            <div class="summary-label">Denied</div>
        </div>

        <div class="summary-card">
            <div class="summary-value"><?= $checkedInNow ?></div>
            <div class="summary-label">Checked In</div>
        </div>

        <div class="summary-card">
            <div class="summary-value" style="color:#21486d;"><?= $availableSlots ?></div>
            <div class="summary-label">Free Slots</div>
        </div>

        <div class="summary-card">
            <div class="summary-value" style="color:#b42318;"><?= $activeBlacklist ?></div>
            <div class="summary-label">Blacklist</div>
        </div>
    </section>

    <div class="layout">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">
                    <i class="fas fa-camera"></i>
                    Camera Scanner
                </div>
            </div>

            <div class="panel-body">
                <div class="camera-box">
                    <video id="camera" autoplay playsinline muted style="display:none;"></video>

                    <div id="cameraPlaceholder" class="camera-placeholder">
                        <i class="fas fa-camera"></i>
                        <div>Camera is not started</div>
                    </div>

                    <div class="scan-frame"></div>

                    <div class="camera-mode-badge" id="cameraModeBadge">
                        <i class="fas fa-circle-dot"></i>
                        Ready
                    </div>

                    <div class="camera-status" id="cameraStatus">
                        Press Start Camera first. Then use Scan Plate or Scan QR.
                    </div>
                </div>

                <div class="camera-actions">
                    <button class="btn btn-primary" onclick="startCamera()">
                        <i class="fas fa-camera"></i>
                        Start Camera
                    </button>

                    <button class="btn btn-dark" onclick="scanPlateFromCamera()">
                        <i class="fas fa-car"></i>
                        Scan Plate
                    </button>

                    <button class="btn btn-primary" onclick="scanQrFromCamera()">
                        <i class="fas fa-qrcode"></i>
                        Scan QR
                    </button>

                    <button class="btn btn-light" onclick="stopCamera()">
                        <i class="fas fa-stop"></i>
                        Stop
                    </button>
                </div>

                <canvas id="captureCanvas"></canvas>
            </div>
        </div>

        <div>
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="fas fa-shield-halved"></i>
                        Verify Access
                    </div>
                </div>

                <div class="panel-body">
                    <div class="verify-box">
                        <div>
                            <label>QR Token / Plate Number</label>
                            <input type="text" id="verifyInput" class="plate-input" placeholder="Scan or enter QR token / plate number">
                        </div>

                        <div class="verify-actions">
                            <button class="btn btn-entry" onclick="verifyGate('ENTRY')">
                                <i class="fas fa-arrow-right-to-bracket"></i>
                                ENTRY
                            </button>

                            <button class="btn btn-exit" onclick="verifyGate('EXIT')">
                                <i class="fas fa-arrow-right-from-bracket"></i>
                                EXIT
                            </button>
                        </div>

                        <div class="hint">
                            Scan result will be filled here. If OCR is not accurate, the guard can correct it manually before pressing ENTRY or EXIT.
                        </div>

                        <div id="ocrOutput" class="result-box">Scan result will appear here.</div>

                        <div id="statusCard" class="status-card">
                            <div id="statusTitle" class="status-title"></div>
                            <div id="statusDetails" class="status-details"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="fas fa-circle-info"></i>
                        Simple Flow
                    </div>
                </div>

                <div class="panel-body">
                    <div class="hint">
                        1. Press <strong>Start Camera</strong><br>
                        2. Show QR code or vehicle plate inside the green frame<br>
                        3. Press <strong>Scan QR</strong> or <strong>Scan Plate</strong><br>
                        4. Press <strong>ENTRY</strong> or <strong>EXIT</strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <section class="panel">
        <div class="panel-header">
            <div class="panel-title">
                <i class="fas fa-clock-rotate-left"></i>
                Recent Gate Activity
            </div>
        </div>

        <div class="panel-body">
            <?php if (empty($recentLogs)): ?>
                <div class="empty">
                    No gate activity yet.
                </div>
            <?php else: ?>
                <div class="activity-list">
                    <?php foreach ($recentLogs as $log): ?>
                        <?php
                            $decisionClass = strtolower((string)$log['decision']) === 'allow' ? 'allow' : 'deny';
                        ?>

                        <div class="log-card <?= e($decisionClass) ?>">
                            <div>
                                <span class="plate"><?= e(safe_text($log['plate_no'])) ?></span>
                            </div>

                            <div>
                                <div class="log-title">
                                    <?= e(safe_text($log['reason'])) ?>
                                </div>

                                <div class="log-meta">
                                    <?= e(date('d M Y, g:i A', strtotime($log['created_at'] ?? 'now'))) ?>
                                    · Visitor: <?= e(safe_text($log['visitor_name'])) ?>
                                    · Unit: <?= e(unit_text_guard_scan($log)) ?>
                                    · Slot: <?= e(slot_text_guard_scan($log)) ?>
                                </div>
                            </div>

                            <div class="log-badges">
                                <span class="badge <?= e(badge_class_guard_scan($log['gate_action'])) ?>">
                                    <?= e(safe_text($log['gate_action'])) ?>
                                </span>

                                <span class="badge <?= e(badge_class_guard_scan($log['decision'])) ?>">
                                    <?= e(safe_text($log['decision'])) ?>
                                </span>

                                <span class="badge <?= e(badge_class_guard_scan($log['vehicle_type'])) ?>">
                                    <?= e(safe_text($log['vehicle_type'])) ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<script>
let stream = null;

async function startCamera() {
    const video = document.getElementById('camera');
    const placeholder = document.getElementById('cameraPlaceholder');
    const status = document.getElementById('cameraStatus');
    const badge = document.getElementById('cameraModeBadge');

    try {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            throw new Error('Camera is not supported by this browser.');
        }

        if (stream) {
            status.textContent = 'Camera is already running. You can scan QR or plate now.';
            return;
        }

        // Front camera first. If browser cannot provide it, fallback to any camera.
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: 'user',
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                },
                audio: false
            });
        } catch (frontCameraError) {
            stream = await navigator.mediaDevices.getUserMedia({
                video: true,
                audio: false
            });
        }

        video.srcObject = stream;
        await video.play();

        video.style.display = 'block';
        placeholder.style.display = 'none';

        badge.innerHTML = '<i class="fas fa-video"></i> Camera On';
        status.textContent = 'Camera started. Place QR code or plate inside the green frame.';

        Swal.fire({
            icon: 'success',
            title: 'Camera Started',
            text: 'You can now scan QR code or plate number.',
            confirmButtonColor: '#17324d'
        });
    } catch (error) {
        status.textContent = 'Unable to access camera. Please allow camera permission and use localhost.';

        Swal.fire({
            icon: 'error',
            title: 'Camera Error',
            text: 'Unable to access camera. Please allow camera permission and open the page using http://localhost.',
            confirmButtonColor: '#17324d'
        });
    }
}

function stopCamera() {
    const video = document.getElementById('camera');
    const placeholder = document.getElementById('cameraPlaceholder');
    const status = document.getElementById('cameraStatus');
    const badge = document.getElementById('cameraModeBadge');

    if (stream) {
        stream.getTracks().forEach(track => track.stop());
        stream = null;
    }

    video.srcObject = null;
    video.style.display = 'none';
    placeholder.style.display = 'block';

    badge.innerHTML = '<i class="fas fa-circle-dot"></i> Ready';
    status.textContent = 'Camera stopped. Press Start Camera to scan again.';
}

function captureFrame() {
    const video = document.getElementById('camera');
    const canvas = document.getElementById('captureCanvas');
    const ctx = canvas.getContext('2d');

    if (!stream || video.videoWidth === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Camera Not Started',
            text: 'Please start the camera first.',
            confirmButtonColor: '#17324d'
        });

        return null;
    }

    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;

    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

    return canvas;
}

function getMiddleCropCanvas(sourceCanvas) {
    const cropCanvas = document.createElement('canvas');
    const cropWidth = sourceCanvas.width;
    const cropHeight = Math.floor(sourceCanvas.height * 0.48);
    const cropY = Math.floor(sourceCanvas.height * 0.28);

    cropCanvas.width = cropWidth;
    cropCanvas.height = cropHeight;

    const cropCtx = cropCanvas.getContext('2d');
    cropCtx.drawImage(
        sourceCanvas,
        0,
        cropY,
        cropWidth,
        cropHeight,
        0,
        0,
        cropWidth,
        cropHeight
    );

    return cropCanvas;
}

function cleanPlateText(value) {
    return String(value || '')
        .toUpperCase()
        .replace(/[^A-Z0-9]/g, '')
        .trim();
}

function extractPlateCandidate(value) {
    const text = cleanPlateText(value);
    const match = text.match(/[A-Z]{1,3}[0-9]{1,4}[A-Z]?/);

    if (match && match[0].length >= 3) {
        return match[0];
    }

    if (text.length >= 3 && text.length <= 10) {
        return text;
    }

    return '';
}

async function scanPlateFromCamera() {
    const canvas = captureFrame();
    const verifyInput = document.getElementById('verifyInput');
    const ocrOutput = document.getElementById('ocrOutput');
    const status = document.getElementById('cameraStatus');
    const badge = document.getElementById('cameraModeBadge');

    if (!canvas) {
        return;
    }

    badge.innerHTML = '<i class="fas fa-car"></i> Plate Scan';
    status.textContent = 'Scanning plate number. Hold the plate clearly inside the green frame.';
    ocrOutput.textContent = 'Scanning plate number... Please wait.';

    Swal.fire({
        title: 'Scanning Plate',
        text: 'Please wait while OCR reads the plate.',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    try {
        if (typeof Tesseract === 'undefined') {
            throw new Error('Tesseract library not loaded.');
        }

        const cropCanvas = getMiddleCropCanvas(canvas);

        const result = await Tesseract.recognize(cropCanvas, 'eng', {
            tessedit_char_whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'
        });

        const rawText = result.data.text || '';
        const possiblePlate = extractPlateCandidate(rawText);

        if (!possiblePlate) {
            ocrOutput.textContent =
                'OCR Raw Text:\n' + rawText.trim() +
                '\n\nNo clear plate detected. Please move closer or type manually.';

            Swal.fire({
                icon: 'warning',
                title: 'Plate Not Clear',
                text: 'OCR could not detect a clear plate number. Please try again or type manually.',
                confirmButtonColor: '#17324d'
            });

            return;
        }

        verifyInput.value = possiblePlate;

        ocrOutput.textContent =
            'OCR Raw Text:\n' + rawText.trim() +
            '\n\nDetected Plate:\n' + possiblePlate;

        status.textContent = 'Plate detected: ' + possiblePlate + '. Please press ENTRY or EXIT.';

        Swal.fire({
            icon: 'success',
            title: 'Plate Detected',
            html: `
                <p>Detected plate number:</p>
                <p style="font-family:monospace;font-size:1.4rem;font-weight:900;margin-top:8px;">
                    ${escapeHtml(possiblePlate)}
                </p>
                <p style="margin-top:10px;font-size:.85rem;">Check the value, then press ENTRY or EXIT.</p>
            `,
            confirmButtonColor: '#17324d'
        });
    } catch (error) {
        ocrOutput.textContent = 'OCR failed. Please try again or enter plate manually.';

        Swal.fire({
            icon: 'error',
            title: 'OCR Failed',
            text: 'Unable to read plate number. Please enter manually.',
            confirmButtonColor: '#17324d'
        });
    }
}

function scanQrFromCamera() {
    const canvas = captureFrame();
    const verifyInput = document.getElementById('verifyInput');
    const ocrOutput = document.getElementById('ocrOutput');
    const status = document.getElementById('cameraStatus');
    const badge = document.getElementById('cameraModeBadge');

    if (!canvas) {
        return;
    }

    badge.innerHTML = '<i class="fas fa-qrcode"></i> QR Scan';
    status.textContent = 'Scanning QR code. Hold the QR code inside the green frame.';

    if (typeof jsQR === 'undefined') {
        ocrOutput.textContent = 'QR library failed to load. Please type QR token manually.';

        Swal.fire({
            icon: 'error',
            title: 'QR Library Error',
            text: 'QR scanner library failed to load.',
            confirmButtonColor: '#17324d'
        });

        return;
    }

    const ctx = canvas.getContext('2d');
    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const qrCode = jsQR(imageData.data, imageData.width, imageData.height);

    if (qrCode && qrCode.data) {
        const qrText = qrCode.data.trim();

        verifyInput.value = qrText;

        ocrOutput.textContent =
            'QR Code Detected:\n' + qrText;

        status.textContent = 'QR detected. Please press ENTRY or EXIT.';

        Swal.fire({
            icon: 'success',
            title: 'QR Code Detected',
            html: `
                <p>QR token has been filled into the verification field.</p>
                <p style="margin-top:10px;font-family:monospace;font-weight:900;word-break:break-all;">
                    ${escapeHtml(qrText)}
                </p>
            `,
            confirmButtonColor: '#17324d'
        });
    } else {
        ocrOutput.textContent =
            'No QR code detected. Please move closer, improve lighting, or scan again.';

        Swal.fire({
            icon: 'warning',
            title: 'No QR Code Detected',
            text: 'Please place the QR code clearly inside the camera view.',
            confirmButtonColor: '#17324d'
        });
    }
}

function verifyGate(action) {
    const verifyInput = document.getElementById('verifyInput');
    const value = verifyInput.value.trim();

    if (!value) {
        Swal.fire({
            icon: 'warning',
            title: 'Missing Input',
            text: 'Please scan or enter QR token / plate number.',
            confirmButtonColor: '#17324d'
        });
        return;
    }

    Swal.fire({
        title: action === 'ENTRY' ? 'Verifying Entry...' : 'Verifying Exit...',
        text: 'Please wait',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    const formData = new FormData();
    formData.append('input', value);
    formData.append('gate_action', action);
    formData.append('csrf_token', '<?= e($_SESSION['csrf_token'] ?? '') ?>');

    fetch('../api/gate_check.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        showGateResult(data, action);
    })
    .catch(error => {
        Swal.fire({
            icon: 'error',
            title: 'Connection Failed',
            text: 'Unable to connect to verification API.',
            confirmButtonColor: '#17324d'
        });
    });
}

function showGateResult(data, action) {
    const statusCard = document.getElementById('statusCard');
    const statusTitle = document.getElementById('statusTitle');
    const statusDetails = document.getElementById('statusDetails');

    statusCard.style.display = 'block';
    statusCard.className = 'status-card ' + (data.success ? 'status-allow' : 'status-deny');

    statusTitle.textContent = data.success ? 'ACCESS ALLOWED' : 'ACCESS DENIED';

    let details = '';
    details += 'Action: ' + action + '<br>';
    details += 'Message: ' + escapeHtml(data.message || '-') + '<br>';

    if (data.vehicle_type) {
        details += 'Vehicle Type: ' + escapeHtml(data.vehicle_type) + '<br>';
    }

    if (data.plate_no) {
        details += 'Plate: ' + escapeHtml(data.plate_no) + '<br>';
    }

    if (data.visitor_name) {
        details += 'Visitor: ' + escapeHtml(data.visitor_name) + '<br>';
    }

    if (data.slot) {
        details += 'Parking Slot: ' + escapeHtml(data.slot) + '<br>';
    }

    if (data.unit) {
        details += 'Unit: ' + escapeHtml(data.unit) + '<br>';
    }

    if (data.resident_email) {
        details += 'Resident: ' + escapeHtml(data.resident_email) + '<br>';
    }

    if (data.next_waiting_assigned) {
        details += '<br>Next waiting visitor assigned: YES<br>';

        if (data.next_waiting_visitor) {
            details += 'Next Visitor: ' + escapeHtml(data.next_waiting_visitor) + '<br>';
        }

        if (data.next_waiting_plate) {
            details += 'Next Plate: ' + escapeHtml(data.next_waiting_plate) + '<br>';
        }
    }

    statusDetails.innerHTML = details;

    Swal.fire({
        icon: data.success ? 'success' : 'error',
        title: data.success ? 'Access Allowed' : 'Access Denied',
        html: details,
        confirmButtonColor: data.success ? '#16803c' : '#b42318'
    });
}

function escapeHtml(text) {
    return String(text ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

window.addEventListener('beforeunload', function() {
    stopCamera();
});
</script>

</body>
</html>
