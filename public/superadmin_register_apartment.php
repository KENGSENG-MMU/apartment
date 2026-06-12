<?php
// File: public/superadmin_register_apartment.php
require_once '../core/security.php';
require_login(['superadmin']);

$pdo = db();
$superName = $_SESSION['email'] ?? 'superadmin';
$superId = (int)($_SESSION['uid'] ?? 0);
$msg = '';
$msgType = '';
$createdSummary = null;
$malaysiaStates = [
    'Johor', 'Kedah', 'Kelantan', 'Melaka', 'Negeri Sembilan', 'Pahang',
    'Penang', 'Perak', 'Perlis', 'Sabah', 'Sarawak', 'Selangor',
    'Terengganu', 'Kuala Lumpur', 'Putrajaya', 'Labuan'
];

function h($v) {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function normalize_blocks(string $raw): array {
    $parts = preg_split('/[,\n]+/', $raw);
    $blocks = [];

    foreach ($parts as $part) {
        $b = trim($part);
        $b = preg_replace('/^block\s+/i', '', $b);
        $b = preg_replace('/^tower\s+/i', '', $b);
        $b = preg_replace('/[^A-Za-z0-9\-]/', '', $b);
        $b = strtoupper(trim($b));

        if ($b !== '' && !in_array($b, $blocks, true)) {
            $blocks[] = $b;
        }
    }

    return $blocks;
}

function next_month_date(string $startDate): string {
    $dt = DateTime::createFromFormat('Y-m-d', $startDate) ?: new DateTime();
    $dt->modify('+1 month');
    return $dt->format('Y-m-d');
}

function create_temp_password(): string {
    return 'SmartVMS@' . random_int(1000, 9999);
}

function add_audit(PDO $pdo, string $action, string $detail): void {
    try {
        if (function_exists('log_audit')) {
            log_audit($action, $detail);
            return;
        }

        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, detail, ip_addr, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$_SESSION['uid'] ?? null, $action, $detail, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
    } catch (Throwable $e) {
        // Keep registration working even if audit log fails.
    }
}

$hasParkingApartmentId = table_exists($pdo, 'parking_slots') && column_exists($pdo, 'parking_slots', 'apartment_id');
$hasSetupProfile = table_exists($pdo, 'apartment_setup_profiles');
$hasApartmentSubscription = table_exists($pdo, 'apartment_subscriptions');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        die('CSRF Token Validation Failed.');
    }

    $apartmentName = trim($_POST['apartment_name'] ?? '');
    $addressLine = trim($_POST['address_line'] ?? ($_POST['address'] ?? ''));
    $city = trim($_POST['city'] ?? '');
    $postcode = trim($_POST['postcode'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $address = trim($addressLine . ', ' . $postcode . ' ' . $city . ', ' . $state, " ,");
    $adminName = trim($_POST['admin_name'] ?? '');
    $adminEmail = strtolower(trim($_POST['admin_email'] ?? ''));
    $adminPhone = trim($_POST['admin_phone'] ?? '');
    $blockRaw = trim($_POST['block_names'] ?? '');
    $blocks = normalize_blocks($blockRaw);
    $floorsPerBlock = max(1, min(80, (int)($_POST['floors_per_block'] ?? 1)));
    $unitsPerFloor = max(1, min(50, (int)($_POST['units_per_floor'] ?? 1)));
    $residentSlots = max(0, min(3000, (int)($_POST['resident_parking_slots'] ?? 0)));
    $visitorSlots = max(0, min(3000, (int)($_POST['visitor_parking_slots'] ?? 0)));
    $monthlyFee = max(0, (float)($_POST['monthly_fee'] ?? 300));
    $startDate = trim($_POST['start_date'] ?? date('Y-m-d'));

    try {
        if ($apartmentName === '' || !preg_match('/^[A-Za-z0-9 .\'&()\-]{3,80}$/', $apartmentName)) {
            throw new Exception('Please enter a valid apartment name.');
        }
        if ($addressLine === '' || strlen($addressLine) < 8 || !preg_match('/[A-Za-z]/', $addressLine) || !preg_match('/\d/', $addressLine)) {
            throw new Exception('Please enter a complete address line with building/lot number and road name.');
        }
        if ($city === '' || !preg_match('/^[A-Za-z .\'\-]{2,60}$/', $city)) {
            throw new Exception('Please enter a valid city/town name.');
        }
        if (!preg_match('/^\d{5}$/', $postcode)) {
            throw new Exception('Please enter a valid 5-digit Malaysian postcode.');
        }
        if ($state === '' || !in_array($state, $malaysiaStates, true)) {
            throw new Exception('Please select a valid Malaysian state.');
        }
        if ($adminName === '' || !preg_match('/^[A-Za-z .\'\-]{3,80}$/', $adminName)) {
            throw new Exception('Please enter a valid admin name.');
        }
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) throw new Exception('Please enter a valid admin email.');
        if ($adminPhone === '' || !preg_match('/^(01[0-9]-?\d{7,8}|0[3-9]-?\d{7,8})$/', str_replace(' ', '', $adminPhone))) {
            throw new Exception('Please enter a valid Malaysian phone number, for example 011-12345678.');
        }
        if (count($blocks) === 0) throw new Exception('Please enter at least one block name, for example A,B,C.');
        if (count($blocks) > 12) throw new Exception('Please enter no more than 12 blocks for one apartment setup.');
        if ($floorsPerBlock < 1 || $floorsPerBlock > 80) throw new Exception('Floors per block must be between 1 and 80.');
        if ($unitsPerFloor < 1 || $unitsPerFloor > 50) throw new Exception('Units per floor must be between 1 and 50.');
        if (($residentSlots + $visitorSlots) <= 0) throw new Exception('Please enter at least one parking slot.');
        if ($visitorSlots > $residentSlots && $residentSlots > 0) throw new Exception('Visitor parking slots should not be more than resident parking slots for a normal apartment setup.');
        if ($monthlyFee <= 0) throw new Exception('Monthly system fee must be more than RM 0.');
        if (!DateTime::createFromFormat('Y-m-d', $startDate)) throw new Exception('Invalid subscription start date.');

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([$adminEmail]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new Exception('This admin email already exists. Please use another email.');
        }

        $totalUnits = count($blocks) * $floorsPerBlock * $unitsPerFloor;
        if ($totalUnits > 5000) {
            throw new Exception('Too many units generated at once. Please reduce block/floor/unit count for demo use.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO apartments (apartment_name, address, status, created_at) VALUES (?, ?, 'active', NOW())");
        $stmt->execute([$apartmentName, $address]);
        $apartmentId = (int)$pdo->lastInsertId();

        $tempPassword = create_temp_password();
        $hash = password_hash($tempPassword, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("\n            INSERT INTO users\n            (apartment_id, full_name, email, contact_number, phone, password_hash, must_change_password, role, status, created_at)\n            VALUES (?, ?, ?, ?, ?, ?, 1, 'admin', 'active', NOW())\n        ");
        $stmt->execute([$apartmentId, $adminName, $adminEmail, $adminPhone, $adminPhone, $hash]);
        $adminId = (int)$pdo->lastInsertId();

        $unitStmt = $pdo->prepare("\n            INSERT INTO units (apartment_id, block_no, unit_no, floor_no, status, created_at)\n            VALUES (?, ?, ?, ?, 'active', NOW())\n        ");

        $createdUnits = 0;
        foreach ($blocks as $block) {
            for ($floor = 1; $floor <= $floorsPerBlock; $floor++) {
                for ($unit = 1; $unit <= $unitsPerFloor; $unit++) {
                    $unitNo = sprintf('%s-%02d-%02d', $block, $floor, $unit);
                    $unitStmt->execute([$apartmentId, $block, $unitNo, $floor]);
                    $createdUnits++;
                }
            }
        }

        if ($hasParkingApartmentId) {
            $slotStmt = $pdo->prepare("\n                INSERT INTO parking_slots (apartment_id, block_name, slot_no, slot_type, status, created_at, updated_at)\n                VALUES (?, ?, ?, ?, 'available', NOW(), NOW())\n            ");
        } else {
            $slotStmt = $pdo->prepare("\n                INSERT INTO parking_slots (block_name, slot_no, slot_type, status, created_at, updated_at)\n                VALUES (?, ?, ?, 'available', NOW(), NOW())\n            ");
        }

        $blockResidentSeq = array_fill_keys($blocks, 0);
        for ($i = 1; $i <= $residentSlots; $i++) {
            $block = $blocks[($i - 1) % count($blocks)];
            $blockResidentSeq[$block]++;
            $slotNo = sprintf('%s-R%03d', $block, $blockResidentSeq[$block]);
            $blockName = 'Block ' . $block;

            if ($hasParkingApartmentId) {
                $slotStmt->execute([$apartmentId, $blockName, $slotNo, 'Resident']);
            } else {
                $slotStmt->execute([$blockName, $slotNo, 'Resident']);
            }
        }

        $blockVisitorSeq = array_fill_keys($blocks, 0);
        for ($i = 1; $i <= $visitorSlots; $i++) {
            $block = $blocks[($i - 1) % count($blocks)];
            $blockVisitorSeq[$block]++;
            $slotNo = sprintf('%s-V%03d', $block, $blockVisitorSeq[$block]);
            $blockName = 'Block ' . $block;

            if ($hasParkingApartmentId) {
                $slotStmt->execute([$apartmentId, $blockName, $slotNo, 'Visitor']);
            } else {
                $slotStmt->execute([$blockName, $slotNo, 'Visitor']);
            }
        }

        if ($hasSetupProfile) {
            $stmt = $pdo->prepare("\n                INSERT INTO apartment_setup_profiles\n                (apartment_id, block_names, floors_per_block, units_per_floor, total_units, resident_parking_slots, visitor_parking_slots, created_by, created_at)\n                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())\n            ");
            $stmt->execute([
                $apartmentId,
                implode(',', $blocks),
                $floorsPerBlock,
                $unitsPerFloor,
                $createdUnits,
                $residentSlots,
                $visitorSlots,
                $superId ?: null
            ]);
        }

        if ($hasApartmentSubscription) {
            $stmt = $pdo->prepare("\n                INSERT INTO apartment_subscriptions\n                (apartment_id, plan_name, monthly_fee, start_date, next_billing_date, status, notes, created_at)\n                VALUES (?, 'Monthly SmartVMS Plan', ?, ?, ?, 'active', ?, NOW())\n            ");
            $stmt->execute([
                $apartmentId,
                $monthlyFee,
                $startDate,
                next_month_date($startDate),
                'Apartment registered by superadmin. Admin account auto-generated.'
            ]);
        }

        add_audit($pdo, 'APARTMENT_REGISTERED', "Apartment #$apartmentId created: $apartmentName. Admin: $adminEmail. Units: $createdUnits. Parking: R$residentSlots / V$visitorSlots");

        $pdo->commit();

        $msg = 'Apartment registered successfully.';
        $msgType = 'success';
        $createdSummary = [
            'apartment_id' => $apartmentId,
            'apartment_name' => $apartmentName,
            'admin_email' => $adminEmail,
            'admin_password' => $tempPassword,
            'blocks' => implode(', ', $blocks),
            'units' => $createdUnits,
            'resident_slots' => $residentSlots,
            'visitor_slots' => $visitorSlots,
            'monthly_fee' => number_format($monthlyFee, 2),
            'start_date' => $startDate,
        ];

        $_POST = [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = $e->getMessage();
        $msgType = 'error';
    }
}

$recentApartments = [];
try {
    $sql = "\n        SELECT\n            a.id, a.apartment_name, a.address, a.status, a.created_at,\n            u.full_name AS admin_name, u.email AS admin_email,\n            asp.block_names, asp.total_units, asp.resident_parking_slots, asp.visitor_parking_slots,\n            sub.monthly_fee, sub.status AS subscription_status, sub.next_billing_date\n        FROM apartments a\n        LEFT JOIN users u ON u.apartment_id = a.id AND u.role = 'admin'\n        LEFT JOIN apartment_setup_profiles asp ON asp.apartment_id = a.id\n        LEFT JOIN apartment_subscriptions sub ON sub.apartment_id = a.id\n        ORDER BY a.id DESC\n        LIMIT 8\n    ";

    if (!$hasSetupProfile || !$hasApartmentSubscription) {
        $sql = "\n            SELECT\n                a.id, a.apartment_name, a.address, a.status, a.created_at,\n                u.full_name AS admin_name, u.email AS admin_email,\n                NULL AS block_names, NULL AS total_units, NULL AS resident_parking_slots, NULL AS visitor_parking_slots,\n                NULL AS monthly_fee, NULL AS subscription_status, NULL AS next_billing_date\n            FROM apartments a\n            LEFT JOIN users u ON u.apartment_id = a.id AND u.role = 'admin'\n            ORDER BY a.id DESC\n            LIMIT 8\n        ";
    }

    $recentApartments = $pdo->query($sql)->fetchAll();
} catch (Throwable $e) {
    $recentApartments = [];
}

$defaultStart = date('Y-m-d');
$needsSql = !$hasParkingApartmentId || !$hasSetupProfile || !$hasApartmentSubscription;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Apartment - <?= h(APP_NAME) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg: #f8fafc;
            --surface: #ffffff;
            --primary: #4f46e5;
            --primary-dark: #3730a3;
            --primary-soft: #e0e7ff;
            --text: #0f172a;
            --muted: #64748b;
            --border: #e5e7eb;
            --green: #16a34a;
            --blue: #2563eb;
            --orange: #ea580c;
            --shadow: 0 18px 45px rgba(15, 23, 42, .08);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { min-height: 100vh; display: grid; grid-template-columns: 260px 1fr; background: linear-gradient(115deg, #f8fafc 0%, #fff 55%, #eef2ff 100%); color: var(--text); }
        .sidebar { background: rgba(255,255,255,.94); border-right: 1px solid var(--border); padding: 22px 18px; display: flex; flex-direction: column; gap: 18px; }
        .brand { display: flex; align-items: center; gap: 12px; padding: 8px 4px 18px; }
        .brand-icon { width: 46px; height: 46px; border-radius: 16px; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; display: grid; place-items: center; box-shadow: 0 12px 28px rgba(79,70,229,.24); }
        .brand-title { font-size: 1.05rem; font-weight: 950; line-height: 1; }
        .brand-title span { color: var(--primary); }
        .brand-sub { font-size: .68rem; color: var(--muted); font-weight: 900; letter-spacing: .08em; margin-top: 4px; }
        .section-label { color: #94a3b8; font-size: .68rem; font-weight: 950; text-transform: uppercase; letter-spacing: .08em; margin: 6px 6px; }
        .nav-link { text-decoration: none; color: #475569; font-weight: 900; font-size: .86rem; display: flex; align-items: center; gap: 10px; padding: 12px 14px; border-radius: 14px; }
        .nav-link:hover, .nav-link.active { background: #eef2ff; color: var(--primary); }
        .nav-link i { width: 18px; text-align: center; }
        .spacer { flex: 1; }
        .main { min-width: 0; padding: 34px 38px; overflow-y: auto; }
        .topbar { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; margin-bottom: 20px; }
        .eyebrow { color: var(--primary); font-size: .72rem; font-weight: 950; letter-spacing: .12em; text-transform: uppercase; margin-bottom: 6px; }
        h1 { font-size: 1.9rem; letter-spacing: -.06em; line-height: 1.05; }
        .subtitle { color: var(--muted); font-weight: 800; font-size: .9rem; margin-top: 8px; max-width: 850px; }
        .admin-pill { background: white; border: 1px solid var(--border); border-radius: 999px; padding: 10px 14px; font-weight: 900; color: #334155; box-shadow: 0 10px 25px rgba(15,23,42,.06); }
        .layout { display: grid; grid-template-columns: minmax(640px, 1fr) 420px; gap: 18px; align-items: start; }
        .card { background: rgba(255,255,255,.96); border: 1px solid var(--border); border-radius: 22px; box-shadow: var(--shadow); overflow: hidden; }
        .card-head { padding: 16px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; font-weight: 950; }
        .card-head i { color: var(--primary); }
        .card-body { padding: 18px; }
        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .field.full { grid-column: 1 / -1; }
        label { display: block; font-size: .68rem; color: #64748b; font-weight: 950; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 7px; }
        input, textarea, select { width: 100%; border: 1px solid #dbe3ef; border-radius: 14px; padding: 12px 13px; font-size: .9rem; font-weight: 800; color: #0f172a; background: white; outline: none; }
        textarea { min-height: 86px; resize: vertical; }
        input:focus, textarea:focus, select:focus { border-color: #c7d2fe; box-shadow: 0 0 0 4px rgba(79,70,229,.10); }
        .input-invalid { border-color: #ef4444 !important; box-shadow: 0 0 0 4px rgba(239,68,68,.10) !important; }
        .input-valid { border-color: #22c55e !important; }
        .field-error { min-height: 17px; color: #dc2626; font-size: .72rem; font-weight: 900; margin-top: 5px; line-height: 1.35; }
        .form-alert-js { display: none; background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; border-radius: 16px; padding: 12px 14px; margin-bottom: 14px; font-size: .82rem; font-weight: 900; line-height: 1.45; }
        .form-alert-js.show { display: block; }
        .hint { color: #64748b; font-size: .75rem; font-weight: 800; line-height: 1.45; margin-top: 6px; }
        .divider { height: 1px; background: var(--border); margin: 18px 0; }
        .section-title { font-size: .86rem; font-weight: 950; color: #0f172a; display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
        .section-title i { color: var(--primary); }
        .btn { border: 0; border-radius: 14px; padding: 13px 18px; font-weight: 950; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; }
        .btn-primary { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; box-shadow: 0 15px 34px rgba(79,70,229,.20); }
        .btn-light { background: white; color: #334155; border: 1px solid var(--border); }
        .actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }
        .alert { border-radius: 16px; padding: 13px 15px; margin-bottom: 16px; font-weight: 900; display: flex; gap: 10px; align-items: flex-start; }
        .alert.success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert.error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .alert.warning { background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; }
        .summary { background: #f8fafc; border: 1px solid var(--border); border-radius: 18px; padding: 14px; margin-bottom: 16px; }
        .summary h3 { font-size: 1rem; margin-bottom: 10px; }
        .summary-row { display: grid; grid-template-columns: 145px 1fr; gap: 10px; padding: 7px 0; border-top: 1px solid #e2e8f0; font-size: .84rem; font-weight: 850; }
        .summary-row:first-of-type { border-top: 0; }
        .summary-label { color: #64748b; }
        .password-box { background: #111827; color: white; padding: 9px 11px; border-radius: 11px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; display: inline-block; letter-spacing: .03em; }
        .mini-card { border: 1px solid var(--border); border-radius: 16px; padding: 12px; margin-bottom: 10px; background: #fff; }
        .mini-top { display: flex; justify-content: space-between; gap: 10px; align-items: start; }
        .mini-name { font-weight: 950; letter-spacing: -.02em; }
        .mini-sub { color: #64748b; font-size: .76rem; font-weight: 850; margin-top: 4px; line-height: 1.4; }
        .badge { padding: 6px 9px; border-radius: 999px; font-size: .63rem; font-weight: 950; text-transform: uppercase; white-space: nowrap; }
        .badge.green { background: #dcfce7; color: #166534; }
        .badge.orange { background: #ffedd5; color: #9a3412; }
        .stats-line { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 10px; }
        .stat-mini { background: #f8fafc; border: 1px solid #eef2f7; border-radius: 12px; padding: 8px; }
        .stat-mini b { display: block; font-size: .94rem; }
        .stat-mini span { color: #64748b; font-size: .62rem; font-weight: 950; text-transform: uppercase; }
        @media (max-width: 1180px) { body { grid-template-columns: 1fr; } .sidebar { display: none; } .layout { grid-template-columns: 1fr; } }
        @media (max-width: 700px) { .main { padding: 22px; } .form-grid { grid-template-columns: 1fr; } .topbar { flex-direction: column; } }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-icon"><i class="fas fa-shield-halved"></i></div>
            <div>
                <div class="brand-title">Smart<span>VMS</span></div>
                <div class="brand-sub">SUPERADMIN</div>
            </div>
        </div>

        <div class="section-label">Management</div>
        <a href="superadmin_dash.php" class="nav-link"><i class="fas fa-chart-pie"></i> Dashboard</a>
        <a href="superadmin_register_apartment.php" class="nav-link active"><i class="fas fa-building-circle-check"></i> Register Apartment</a>
        <a href="superadmin_config.php" class="nav-link"><i class="fas fa-gear"></i> System Config</a>
        <a href="#" class="nav-link"><i class="fas fa-receipt"></i> Subscription Payments</a>
        <a href="#" class="nav-link"><i class="fas fa-clipboard-list"></i> Audit Logs</a>

        <div class="spacer"></div>
        <a href="../core/logout.php" class="nav-link" style="color:#991b1b;background:#fff1f2;"><i class="fas fa-arrow-right-from-bracket"></i> Logout</a>
    </aside>

    <main class="main">
        <div class="topbar">
            <div>
                <div class="eyebrow">SmartVMS System Provider</div>
                <h1>Register New Apartment</h1>
                <p class="subtitle">Superadmin creates a new apartment customer, auto-generates units, parking slots, monthly subscription, and the apartment admin account.</p>
            </div>
            <div class="admin-pill"><i class="fas fa-crown" style="color:var(--primary)"></i> <?= h($superName) ?></div>
        </div>

        <?php if ($needsSql): ?>
            <div class="alert warning">
                <i class="fas fa-triangle-exclamation"></i>
                <div>
                    Please run <b>smartvms_superadmin_apartment_setup.sql</b> first. It adds apartment subscription tables and apartment_id for parking slots.
                </div>
            </div>
        <?php endif; ?>

        <?php if ($msg): ?>
            <div class="alert <?= h($msgType) ?>">
                <i class="fas <?= $msgType === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation' ?>"></i>
                <div><?= h($msg) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($createdSummary): ?>
            <div class="summary">
                <h3><i class="fas fa-circle-check" style="color:var(--green)"></i> Created Apartment Summary</h3>
                <div class="summary-row"><div class="summary-label">Apartment</div><div><?= h($createdSummary['apartment_name']) ?> (#<?= h($createdSummary['apartment_id']) ?>)</div></div>
                <div class="summary-row"><div class="summary-label">Admin Email</div><div><?= h($createdSummary['admin_email']) ?></div></div>
                <div class="summary-row"><div class="summary-label">Temporary Password</div><div><span class="password-box"><?= h($createdSummary['admin_password']) ?></span></div></div>
                <div class="summary-row"><div class="summary-label">Blocks</div><div><?= h($createdSummary['blocks']) ?></div></div>
                <div class="summary-row"><div class="summary-label">Generated Units</div><div><?= h($createdSummary['units']) ?></div></div>
                <div class="summary-row"><div class="summary-label">Parking Slots</div><div>Resident: <?= h($createdSummary['resident_slots']) ?> / Visitor: <?= h($createdSummary['visitor_slots']) ?></div></div>
                <div class="summary-row"><div class="summary-label">System Fee</div><div>RM <?= h($createdSummary['monthly_fee']) ?> / month from <?= h($createdSummary['start_date']) ?></div></div>
            </div>
        <?php endif; ?>

        <section class="layout">
            <div class="card">
                <div class="card-head"><i class="fas fa-building"></i> Apartment Setup Form</div>
                <div class="card-body">
                    <form method="POST" id="apartmentSetupForm" novalidate>
                        <div id="formAlert" class="form-alert-js"><i class="fas fa-circle-exclamation"></i> Please fix the highlighted fields before generating the apartment.</div>
                        <?= csrf_field() ?>

                        <div class="section-title"><i class="fas fa-building-user"></i> Apartment Information</div>
                        <div class="form-grid">
                            <div class="field">
                                <label>Apartment Name</label>
                                <input type="text" name="apartment_name" value="<?= h($_POST['apartment_name'] ?? '') ?>" placeholder="Example: Ixoro Apartment" required>
                                <div class="field-error" data-error-for="apartment_name"></div>
                            </div>
                            <div class="field">
                                <label>Subscription Start Date</label>
                                <input type="date" name="start_date" value="<?= h($_POST['start_date'] ?? $defaultStart) ?>" required>
                                <div class="field-error" data-error-for="start_date"></div>
                            </div>
                            <div class="field full">
                                <label>Address Line</label>
                                <textarea name="address_line" placeholder="Example: No. 12, Jalan Ixoro 3, Taman Melaka Raya" required><?= h($_POST['address_line'] ?? $_POST['address'] ?? '') ?></textarea>
                                <div class="field-error" data-error-for="address_line"></div>
                                <div class="hint">Must include building/lot number and road name. Example: No. 12, Jalan Ixoro 3.</div>
                            </div>
                            <div class="field">
                                <label>Postcode</label>
                                <input type="text" name="postcode" value="<?= h($_POST['postcode'] ?? '') ?>" placeholder="Example: 75450" maxlength="5" required>
                                <div class="field-error" data-error-for="postcode"></div>
                            </div>
                            <div class="field">
                                <label>City / Town</label>
                                <input type="text" name="city" value="<?= h($_POST['city'] ?? '') ?>" placeholder="Example: Melaka" required>
                                <div class="field-error" data-error-for="city"></div>
                            </div>
                            <div class="field full">
                                <label>State</label>
                                <select name="state" required>
                                    <option value="">Select state</option>
                                    <?php foreach ($malaysiaStates as $ms): ?>
                                        <option value="<?= h($ms) ?>" <?= (($_POST['state'] ?? '') === $ms) ? 'selected' : '' ?>><?= h($ms) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="field-error" data-error-for="state"></div>
                            </div>
                        </div>

                        <div class="divider"></div>

                        <div class="section-title"><i class="fas fa-user-tie"></i> Apartment Admin Account</div>
                        <div class="form-grid">
                            <div class="field">
                                <label>Admin Name</label>
                                <input type="text" name="admin_name" value="<?= h($_POST['admin_name'] ?? '') ?>" placeholder="Example: Ixoro Admin" required>
                                <div class="field-error" data-error-for="admin_name"></div>
                            </div>
                            <div class="field">
                                <label>Admin Phone</label>
                                <input type="text" name="admin_phone" value="<?= h($_POST['admin_phone'] ?? '') ?>" placeholder="Example: 011-12345678" required>
                                <div class="field-error" data-error-for="admin_phone"></div>
                            </div>
                            <div class="field full">
                                <label>Admin Email</label>
                                <input type="email" name="admin_email" value="<?= h($_POST['admin_email'] ?? '') ?>" placeholder="Example: admin@ixoro.com" required>
                                <div class="field-error" data-error-for="admin_email"></div>
                                <div class="hint">The system will create this admin account automatically and show a temporary password after submission.</div>
                            </div>
                        </div>

                        <div class="divider"></div>

                        <div class="section-title"><i class="fas fa-layer-group"></i> Building / Unit Auto Generation</div>
                        <div class="form-grid">
                            <div class="field full">
                                <label>Block Names</label>
                                <input type="text" name="block_names" value="<?= h($_POST['block_names'] ?? '') ?>" placeholder="Example: A,B,C or Tower A,Tower B" required>
                                <div class="field-error" data-error-for="block_names"></div>
                                <div class="hint">Use comma between blocks. Example: A,B,C. The system will generate unit numbers like A-01-01.</div>
                            </div>
                            <div class="field">
                                <label>Floors Per Block</label>
                                <input type="number" name="floors_per_block" min="1" max="80" value="<?= h($_POST['floors_per_block'] ?? '13') ?>" required>
                                <div class="field-error" data-error-for="floors_per_block"></div>
                            </div>
                            <div class="field">
                                <label>Units Per Floor</label>
                                <input type="number" name="units_per_floor" min="1" max="50" value="<?= h($_POST['units_per_floor'] ?? '8') ?>" required>
                                <div class="field-error" data-error-for="units_per_floor"></div>
                            </div>
                        </div>

                        <div class="divider"></div>

                        <div class="section-title"><i class="fas fa-square-parking"></i> Parking Slot Auto Generation</div>
                        <div class="form-grid">
                            <div class="field">
                                <label>Resident Parking Slots</label>
                                <input type="number" name="resident_parking_slots" min="0" max="3000" value="<?= h($_POST['resident_parking_slots'] ?? '120') ?>" required>
                                <div class="field-error" data-error-for="resident_parking_slots"></div>
                            </div>
                            <div class="field">
                                <label>Visitor Parking Slots</label>
                                <input type="number" name="visitor_parking_slots" min="0" max="3000" value="<?= h($_POST['visitor_parking_slots'] ?? '30') ?>" required>
                                <div class="field-error" data-error-for="visitor_parking_slots"></div>
                            </div>
                            <div class="field full">
                                <label>Monthly System Fee (RM)</label>
                                <input type="number" name="monthly_fee" min="0" step="0.01" value="<?= h($_POST['monthly_fee'] ?? '300.00') ?>" required>
                                <div class="field-error" data-error-for="monthly_fee"></div>
                                <div class="hint">This is the monthly fee apartment management pays to use SmartVMS.</div>
                            </div>
                        </div>

                        <div class="actions">
                            <a href="superadmin_register_apartment.php" class="btn btn-light"><i class="fas fa-rotate-left"></i> Reset</a>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-wand-magic-sparkles"></i> Generate Apartment</button>
                        </div>
                    </form>
                </div>
            </div>

            <aside class="card">
                <div class="card-head"><i class="fas fa-clock-rotate-left"></i> Recent Apartment Accounts</div>
                <div class="card-body">
                    <?php if (!$recentApartments): ?>
                        <div class="mini-sub">No apartment data found.</div>
                    <?php endif; ?>

                    <?php foreach ($recentApartments as $apt): ?>
                        <div class="mini-card">
                            <div class="mini-top">
                                <div>
                                    <div class="mini-name"><?= h($apt['apartment_name']) ?></div>
                                    <div class="mini-sub"><?= h($apt['address']) ?></div>
                                    <div class="mini-sub">Admin: <?= h($apt['admin_email'] ?: '-') ?></div>
                                </div>
                                <span class="badge <?= ($apt['subscription_status'] ?? 'active') === 'active' ? 'green' : 'orange' ?>">
                                    <?= h($apt['subscription_status'] ?: $apt['status']) ?>
                                </span>
                            </div>

                            <div class="stats-line">
                                <div class="stat-mini"><b><?= h($apt['total_units'] ?? '-') ?></b><span>Units</span></div>
                                <div class="stat-mini"><b><?= h($apt['resident_parking_slots'] ?? '-') ?></b><span>Resident Parking</span></div>
                                <div class="stat-mini"><b><?= h($apt['visitor_parking_slots'] ?? '-') ?></b><span>Visitor Parking</span></div>
                            </div>

                            <div class="mini-sub" style="margin-top:10px;">
                                Fee: <?= $apt['monthly_fee'] !== null ? 'RM ' . h(number_format((float)$apt['monthly_fee'], 2)) : '-' ?>
                                <?php if (!empty($apt['next_billing_date'])): ?>
                                    · Next bill: <?= h($apt['next_billing_date']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </aside>
        </section>
    </main>

<script>
(function () {
    const form = document.getElementById('apartmentSetupForm');
    if (!form) return;

    const alertBox = document.getElementById('formAlert');
    const fields = [
        'apartment_name', 'start_date', 'address_line', 'postcode', 'city', 'state',
        'admin_name', 'admin_phone', 'admin_email', 'block_names',
        'floors_per_block', 'units_per_floor', 'resident_parking_slots',
        'visitor_parking_slots', 'monthly_fee'
    ];

    const malaysiaPostcodePrefix = {
        'Johor': ['79','80','81','82','83','84','85','86'],
        'Kedah': ['05','06','07','08','09'],
        'Kelantan': ['15','16','17','18'],
        'Melaka': ['75','76','77','78'],
        'Negeri Sembilan': ['70','71','72','73'],
        'Pahang': ['25','26','27','28','29'],
        'Penang': ['10','11','12','13','14'],
        'Perak': ['30','31','32','33','34','35','36'],
        'Perlis': ['01'],
        'Sabah': ['88','89','90','91'],
        'Sarawak': ['93','94','95','96','97','98'],
        'Selangor': ['40','41','42','43','44','45','46','47','48','63','64','68'],
        'Terengganu': ['20','21','22','23','24'],
        'Kuala Lumpur': ['50','51','52','53','54','55','56','57','58','59','60'],
        'Putrajaya': ['62'],
        'Labuan': ['87']
    };

    function get(name) {
        return form.elements[name];
    }

    function val(name) {
        const field = get(name);
        return field ? field.value.trim() : '';
    }

    function setError(name, message) {
        const field = get(name);
        const error = form.querySelector('[data-error-for="' + name + '"]');
        if (!field) return false;

        field.classList.remove('input-valid', 'input-invalid');
        if (message) {
            field.classList.add('input-invalid');
            if (error) error.textContent = message;
            return false;
        }

        field.classList.add('input-valid');
        if (error) error.textContent = '';
        return true;
    }

    function numberValue(name) {
        const n = Number(val(name));
        return Number.isFinite(n) ? n : NaN;
    }

    function splitBlocks(raw) {
        return raw.split(',')
            .map(b => b.trim().replace(/^block\s+/i, '').replace(/^tower\s+/i, '').replace(/[^A-Za-z0-9-]/g, '').toUpperCase())
            .filter(Boolean);
    }

    function validateField(name) {
        const value = val(name);

        if (name === 'apartment_name') {
            if (!value) return setError(name, 'Apartment name is required.');
            if (!/^[A-Za-z0-9 .'&()\-]{3,80}$/.test(value)) return setError(name, 'Use 3-80 characters only. Letters, numbers, spaces and - & () are allowed.');
            return setError(name, '');
        }

        if (name === 'start_date') {
            if (!value) return setError(name, 'Subscription start date is required.');
            const selected = new Date(value + 'T00:00:00');
            if (Number.isNaN(selected.getTime())) return setError(name, 'Invalid date.');
            return setError(name, '');
        }

        if (name === 'address_line') {
            if (!value) return setError(name, 'Address line is required.');
            if (value.length < 8) return setError(name, 'Address is too short. Enter a complete building/road address.');
            if (!/[A-Za-z]/.test(value) || !/\d/.test(value)) return setError(name, 'Address should include both number and road/building name.');
            if (/[,]{2,}|[.]{3,}/.test(value)) return setError(name, 'Address has too many repeated punctuation marks.');
            return setError(name, '');
        }

        if (name === 'postcode') {
            if (!/^\d{5}$/.test(value)) return setError(name, 'Postcode must be 5 digits.');
            const state = val('state');
            if (state && malaysiaPostcodePrefix[state]) {
                const prefix = value.substring(0, 2);
                if (!malaysiaPostcodePrefix[state].includes(prefix)) {
                    return setError(name, 'Postcode does not look correct for ' + state + '.');
                }
            }
            return setError(name, '');
        }

        if (name === 'city') {
            if (!value) return setError(name, 'City / town is required.');
            if (!/^[A-Za-z .'\-]{2,60}$/.test(value)) return setError(name, 'City should contain letters only.');
            return setError(name, '');
        }

        if (name === 'state') {
            if (!value) return setError(name, 'Please select the apartment state.');
            setError(name, '');
            validateField('postcode');
            return true;
        }

        if (name === 'admin_name') {
            if (!value) return setError(name, 'Admin name is required.');
            if (!/^[A-Za-z .'\-]{3,80}$/.test(value)) return setError(name, 'Admin name should contain letters only.');
            return setError(name, '');
        }

        if (name === 'admin_phone') {
            const cleaned = value.replace(/\s+/g, '');
            if (!cleaned) return setError(name, 'Admin phone is required.');
            if (!/^(01[0-9]-?\d{7,8}|0[3-9]-?\d{7,8})$/.test(cleaned)) return setError(name, 'Use Malaysian phone format, for example 011-12345678.');
            return setError(name, '');
        }

        if (name === 'admin_email') {
            if (!value) return setError(name, 'Admin email is required.');
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value)) return setError(name, 'Enter a valid email address.');
            return setError(name, '');
        }

        if (name === 'block_names') {
            if (!value) return setError(name, 'Block names are required. Example: A,B,C.');
            const blocks = splitBlocks(value);
            if (blocks.length === 0) return setError(name, 'Enter at least one valid block name.');
            if (blocks.length > 12) return setError(name, 'Maximum 12 blocks only for one setup.');
            if (new Set(blocks).size !== blocks.length) return setError(name, 'Duplicate block names are not allowed.');
            return setError(name, '');
        }

        if (name === 'floors_per_block') {
            const n = numberValue(name);
            if (!Number.isInteger(n) || n < 1 || n > 80) return setError(name, 'Floors per block must be between 1 and 80.');
            return setError(name, '');
        }

        if (name === 'units_per_floor') {
            const n = numberValue(name);
            if (!Number.isInteger(n) || n < 1 || n > 50) return setError(name, 'Units per floor must be between 1 and 50.');
            return setError(name, '');
        }

        if (name === 'resident_parking_slots') {
            const n = numberValue(name);
            if (!Number.isInteger(n) || n < 0 || n > 3000) return setError(name, 'Resident parking must be between 0 and 3000.');
            return setError(name, '');
        }

        if (name === 'visitor_parking_slots') {
            const n = numberValue(name);
            if (!Number.isInteger(n) || n < 0 || n > 3000) return setError(name, 'Visitor parking must be between 0 and 3000.');
            const resident = numberValue('resident_parking_slots');
            if (Number.isFinite(resident) && resident > 0 && n > resident) return setError(name, 'Visitor parking should not be more than resident parking.');
            return setError(name, '');
        }

        if (name === 'monthly_fee') {
            const n = Number(value);
            if (!Number.isFinite(n) || n <= 0) return setError(name, 'Monthly fee must be more than RM 0.');
            if (n > 100000) return setError(name, 'Monthly fee is too high. Please check again.');
            return setError(name, '');
        }

        return true;
    }

    function validateAll() {
        let ok = true;
        fields.forEach(name => {
            if (!validateField(name)) ok = false;
        });

        const blocks = splitBlocks(val('block_names'));
        const floors = numberValue('floors_per_block');
        const units = numberValue('units_per_floor');
        const totalUnits = blocks.length * floors * units;
        if (blocks.length && Number.isFinite(totalUnits) && totalUnits > 5000) {
            setError('units_per_floor', 'This setup will generate ' + totalUnits + ' units. Maximum 5000 units only.');
            ok = false;
        }

        const resident = numberValue('resident_parking_slots');
        const visitor = numberValue('visitor_parking_slots');
        if (Number.isFinite(resident) && Number.isFinite(visitor) && resident + visitor <= 0) {
            setError('resident_parking_slots', 'Enter at least one parking slot.');
            setError('visitor_parking_slots', 'Enter at least one parking slot.');
            ok = false;
        }

        return ok;
    }

    fields.forEach(name => {
        const field = get(name);
        if (!field) return;
        field.addEventListener('input', () => validateField(name));
        field.addEventListener('change', () => validateField(name));
    });

    form.addEventListener('submit', function (e) {
        if (!validateAll()) {
            e.preventDefault();
            if (alertBox) alertBox.classList.add('show');
            const firstInvalid = form.querySelector('.input-invalid');
            if (firstInvalid) firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else if (alertBox) {
            alertBox.classList.remove('show');
        }
    });
})();
</script>

</body>
</html>
<?php
// File: public/superadmin_apartment_applications.php
require_once '../core/security.php';
require_login(['superadmin']);

$pdo = db();
$superName = $_SESSION['email'] ?? 'superadmin';
$superId = (int)($_SESSION['uid'] ?? 0);
$msg = '';
$msgType = '';
$createdSummary = null;

function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function table_exists_sa(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function column_exists_sa(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function normalize_blocks_sa(string $raw): array {
    $parts = preg_split('/[,\n]+/', $raw);
    $blocks = [];
    foreach ($parts as $part) {
        $b = trim($part);
        $b = preg_replace('/^block\s+/i', '', $b);
        $b = preg_replace('/^tower\s+/i', '', $b);
        $b = preg_replace('/[^A-Za-z0-9\-]/', '', $b);
        $b = strtoupper(trim($b));
        if ($b !== '' && !in_array($b, $blocks, true)) $blocks[] = $b;
    }
    return $blocks;
}

function next_month_date_sa(string $startDate): string {
    $dt = DateTime::createFromFormat('Y-m-d', $startDate) ?: new DateTime();
    $dt->modify('+1 month');
    return $dt->format('Y-m-d');
}

function create_temp_password_sa(): string {
    return 'SmartVMS@' . random_int(1000, 9999);
}

function add_audit_sa(PDO $pdo, string $action, string $detail): void {
    try {
        if (function_exists('log_audit')) {
            log_audit($action, $detail);
            return;
        }
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, detail, ip_addr, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$_SESSION['uid'] ?? null, $action, $detail, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
    } catch (Throwable $e) {}
}

$hasApplications = table_exists_sa($pdo, 'apartment_applications');
$hasApplicationPasswordHash = $hasApplications && column_exists_sa($pdo, 'apartment_applications', 'admin_password_hash');
$hasParkingApartmentId = table_exists_sa($pdo, 'parking_slots') && column_exists_sa($pdo, 'parking_slots', 'apartment_id');
$hasSetupProfile = table_exists_sa($pdo, 'apartment_setup_profiles');
$hasApartmentSubscription = table_exists_sa($pdo, 'apartment_subscriptions');
$needsSql = !$hasApplications || !$hasApplicationPasswordHash || !$hasParkingApartmentId || !$hasSetupProfile || !$hasApartmentSubscription;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        die('CSRF Token Validation Failed.');
    }

    $action = $_POST['action'] ?? '';
    $applicationId = (int)($_POST['application_id'] ?? 0);

    try {
        if (!$hasApplications) throw new Exception('Application table is not ready. Please run smartvms_apartment_application_setup_V2.sql first.');
        if ($applicationId <= 0) throw new Exception('Invalid application ID.');

        if ($action === 'approve') {
            $stmt = $pdo->prepare("SELECT * FROM apartment_applications WHERE id = ? AND status = 'pending' LIMIT 1");
            $stmt->execute([$applicationId]);
            $app = $stmt->fetch();
            if (!$app) throw new Exception('Pending application not found. It may already be reviewed.');

            $apartmentName = trim($app['apartment_name']);
            $address = trim($app['address_line'] . ', ' . $app['postcode'] . ' ' . $app['city'] . ', ' . $app['state'], ' ,');
            $adminName = $apartmentName . ' Admin';
            $adminEmail = strtolower(trim($app['contact_email']));
            $adminPhone = '';
            $blocks = normalize_blocks_sa((string)$app['block_names']);
            $floorsPerBlock = max(1, min(80, (int)$app['floors_per_block']));
            $unitsPerFloor = max(1, min(50, (int)$app['units_per_floor']));
            $residentSlots = max(0, min(3000, (int)$app['resident_parking_slots']));
            $visitorSlots = max(0, min(3000, (int)$app['visitor_parking_slots']));
            $monthlyFee = max(1, (float)$app['monthly_fee']);
            $startDate = $app['preferred_start_date'] ?: date('Y-m-d');

            if ($apartmentName === '') throw new Exception('Apartment name is missing.');
            if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) throw new Exception('Admin email is invalid.');
            if (count($blocks) === 0) throw new Exception('Block names are missing.');
            $totalUnits = count($blocks) * $floorsPerBlock * $unitsPerFloor;
            if ($totalUnits > 5000) throw new Exception('Too many units generated at once. Please reject and ask applicant to reduce the setup size.');

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $stmt->execute([$adminEmail]);
            if ((int)$stmt->fetchColumn() > 0) throw new Exception('This admin email already exists in users table.');

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO apartments (apartment_name, address, status, created_at) VALUES (?, ?, 'active', NOW())");
            $stmt->execute([$apartmentName, $address]);
            $apartmentId = (int)$pdo->lastInsertId();

            $tempPassword = create_temp_password_sa();
            $hash = password_hash($tempPassword, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("\n                INSERT INTO users\n                (apartment_id, full_name, email, contact_number, phone, password_hash, must_change_password, role, status, created_at)\n                VALUES (?, ?, ?, ?, ?, ?, 1, 'admin', 'active', NOW())\n            ");
            $stmt->execute([$apartmentId, $adminName, $adminEmail, $adminPhone, $adminPhone, $hash]);
            $adminId = (int)$pdo->lastInsertId();

            $unitStmt = $pdo->prepare("\n                INSERT INTO units (apartment_id, block_no, unit_no, floor_no, status, created_at)\n                VALUES (?, ?, ?, ?, 'active', NOW())\n            ");

            $createdUnits = 0;
            foreach ($blocks as $block) {
                for ($floor = 1; $floor <= $floorsPerBlock; $floor++) {
                    for ($unit = 1; $unit <= $unitsPerFloor; $unit++) {
                        $unitNo = sprintf('%s-%02d-%02d', $block, $floor, $unit);
                        $unitStmt->execute([$apartmentId, $block, $unitNo, $floor]);
                        $createdUnits++;
                    }
                }
            }

            if ($hasParkingApartmentId) {
                $slotStmt = $pdo->prepare("\n                    INSERT INTO parking_slots (apartment_id, block_name, slot_no, slot_type, status, created_at, updated_at)\n                    VALUES (?, ?, ?, ?, 'available', NOW(), NOW())\n                ");
            } else {
                $slotStmt = $pdo->prepare("\n                    INSERT INTO parking_slots (block_name, slot_no, slot_type, status, created_at, updated_at)\n                    VALUES (?, ?, ?, 'available', NOW(), NOW())\n                ");
            }

            $blockResidentSeq = array_fill_keys($blocks, 0);
            for ($i = 1; $i <= $residentSlots; $i++) {
                $block = $blocks[($i - 1) % count($blocks)];
                $blockResidentSeq[$block]++;
                $slotNo = sprintf('%s-R%03d', $block, $blockResidentSeq[$block]);
                $blockName = 'Block ' . $block;
                if ($hasParkingApartmentId) $slotStmt->execute([$apartmentId, $blockName, $slotNo, 'Resident']);
                else $slotStmt->execute([$blockName, $slotNo, 'Resident']);
            }

            $blockVisitorSeq = array_fill_keys($blocks, 0);
            for ($i = 1; $i <= $visitorSlots; $i++) {
                $block = $blocks[($i - 1) % count($blocks)];
                $blockVisitorSeq[$block]++;
                $slotNo = sprintf('%s-V%03d', $block, $blockVisitorSeq[$block]);
                $blockName = 'Block ' . $block;
                if ($hasParkingApartmentId) $slotStmt->execute([$apartmentId, $blockName, $slotNo, 'Visitor']);
                else $slotStmt->execute([$blockName, $slotNo, 'Visitor']);
            }

            if ($hasSetupProfile) {
                $stmt = $pdo->prepare("\n                    INSERT INTO apartment_setup_profiles\n                    (apartment_id, block_names, floors_per_block, units_per_floor, total_units, resident_parking_slots, visitor_parking_slots, created_by, created_at)\n                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())\n                ");
                $stmt->execute([$apartmentId, implode(',', $blocks), $floorsPerBlock, $unitsPerFloor, $createdUnits, $residentSlots, $visitorSlots, $superId ?: null]);
            }

            if ($hasApartmentSubscription) {
                $stmt = $pdo->prepare("\n                    INSERT INTO apartment_subscriptions\n                    (apartment_id, plan_name, monthly_fee, start_date, next_billing_date, status, notes, created_at)\n                    VALUES (?, 'Monthly SmartVMS Plan', ?, ?, ?, 'active', ?, NOW())\n                ");
                $stmt->execute([$apartmentId, $monthlyFee, $startDate, next_month_date_sa($startDate), 'Created from approved apartment application ' . $app['application_ref']]);
            }

            $stmt = $pdo->prepare("\n                UPDATE apartment_applications\n                SET status = 'approved', apartment_id = ?, admin_user_id = ?, admin_temp_password = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()\n                WHERE id = ?\n            ");
            $stmt->execute([$apartmentId, $adminId, $tempPassword, $superId ?: null, $applicationId]);

            add_audit_sa($pdo, 'APARTMENT_APPLICATION_APPROVED', "Application {$app['application_ref']} approved. Apartment #$apartmentId created. Admin: $adminEmail");
            $pdo->commit();

            $msg = 'Application approved successfully. Apartment, units, parking slots, subscription and admin account were generated.';
            $msgType = 'success';
            $createdSummary = [
                'application_ref' => $app['application_ref'],
                'apartment_name' => $apartmentName,
                'apartment_id' => $apartmentId,
                'admin_email' => $adminEmail,
                'admin_password' => $passwordNote,
                'blocks' => implode(', ', $blocks),
                'units' => $createdUnits,
                'resident_slots' => $residentSlots,
                'visitor_slots' => $visitorSlots,
                'monthly_fee' => number_format($monthlyFee, 2),
                'start_date' => $startDate,
            ];
        } elseif ($action === 'reject') {
            $reason = trim($_POST['rejection_reason'] ?? 'Rejected by superadmin.');
            if ($reason === '') $reason = 'Rejected by superadmin.';
            $stmt = $pdo->prepare("UPDATE apartment_applications SET status = 'rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ? AND status = 'pending'");
            $stmt->execute([$reason, $superId ?: null, $applicationId]);
            if ($stmt->rowCount() <= 0) throw new Exception('Pending application not found or already reviewed.');
            add_audit_sa($pdo, 'APARTMENT_APPLICATION_REJECTED', "Application #$applicationId rejected. Reason: $reason");
            $msg = 'Application rejected.';
            $msgType = 'success';
        } else {
            throw new Exception('Invalid action.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = $e->getMessage();
        $msgType = 'error';
    }
}

$status = $_GET['status'] ?? 'pending';
$allowedStatus = ['pending', 'approved', 'rejected', 'all'];
if (!in_array($status, $allowedStatus, true)) $status = 'pending';
$search = trim($_GET['search'] ?? '');

$applications = [];
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
if ($hasApplications) {
    try {
        foreach (['pending', 'approved', 'rejected'] as $s) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM apartment_applications WHERE status = ?");
            $stmt->execute([$s]);
            $counts[$s] = (int)$stmt->fetchColumn();
        }
        $counts['all'] = $counts['pending'] + $counts['approved'] + $counts['rejected'];

        $where = [];
        $params = [];
        if ($status !== 'all') { $where[] = 'status = ?'; $params[] = $status; }
        if ($search !== '') {
            $where[] = "(application_ref LIKE ? OR apartment_name LIKE ? OR contact_email LIKE ? OR city LIKE ? OR state LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $sql = "SELECT * FROM apartment_applications" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY FIELD(status,'pending','approved','rejected'), id DESC LIMIT 200";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $applications = $stmt->fetchAll();
    } catch (Throwable $e) {
        $applications = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Apartment Applications - <?= h(APP_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--bg:#f8fafc;--surface:#fff;--primary:#4f46e5;--primary-dark:#3730a3;--primary-soft:#e0e7ff;--text:#0f172a;--muted:#64748b;--border:#e5e7eb;--green:#16a34a;--red:#dc2626;--orange:#ea580c;--blue:#2563eb;--shadow:0 18px 45px rgba(15,23,42,.08)}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Plus Jakarta Sans',sans-serif} body{min-height:100vh;display:grid;grid-template-columns:260px 1fr;background:linear-gradient(115deg,#f8fafc 0%,#fff 55%,#eef2ff 100%);color:var(--text)}.sidebar{background:rgba(255,255,255,.94);border-right:1px solid var(--border);padding:22px 18px;display:flex;flex-direction:column;gap:18px}.brand{display:flex;align-items:center;gap:12px;padding:8px 4px 18px}.brand-icon{width:46px;height:46px;border-radius:16px;background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:white;display:grid;place-items:center;box-shadow:0 12px 28px rgba(79,70,229,.24)}.brand-title{font-size:1.05rem;font-weight:950;line-height:1}.brand-title span{color:var(--primary)}.brand-sub{font-size:.68rem;color:var(--muted);font-weight:900;letter-spacing:.08em;margin-top:4px}.section-label{color:#94a3b8;font-size:.68rem;font-weight:950;text-transform:uppercase;letter-spacing:.08em;margin:6px}.nav-link{text-decoration:none;color:#475569;font-weight:900;font-size:.86rem;display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:14px}.nav-link:hover,.nav-link.active{background:#eef2ff;color:var(--primary)}.nav-link i{width:18px;text-align:center}.spacer{flex:1}.main{min-width:0;padding:34px 38px;overflow-y:auto}.topbar{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:20px}.eyebrow{color:var(--primary);font-size:.72rem;font-weight:950;letter-spacing:.12em;text-transform:uppercase;margin-bottom:6px}h1{font-size:1.9rem;letter-spacing:-.06em;line-height:1.05}.subtitle{color:var(--muted);font-weight:800;font-size:.9rem;margin-top:8px;max-width:850px}.admin-pill{background:white;border:1px solid var(--border);border-radius:999px;padding:10px 14px;font-weight:900;color:#334155;box-shadow:0 10px 25px rgba(15,23,42,.06)}.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}.stat{background:white;border:1px solid var(--border);border-radius:20px;padding:17px 18px;box-shadow:0 10px 25px rgba(15,23,42,.05)}.stat-value{font-size:1.65rem;font-weight:950;letter-spacing:-.05em}.stat-label{color:var(--muted);font-size:.68rem;text-transform:uppercase;font-weight:950;letter-spacing:.06em;margin-top:6px}.card{background:rgba(255,255,255,.96);border:1px solid var(--border);border-radius:22px;box-shadow:var(--shadow);overflow:hidden}.card-head{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px}.card-title{font-weight:950;display:flex;align-items:center;gap:10px}.card-title i{color:var(--primary)}.filters{display:grid;grid-template-columns:1fr 190px auto auto;gap:10px;padding:16px 18px;border-bottom:1px solid var(--border)}input,select,textarea{width:100%;border:1px solid #dbe3ef;border-radius:14px;padding:12px 13px;font-size:.86rem;font-weight:850;color:#0f172a;background:white;outline:none}textarea{resize:vertical}.btn{border:0;border-radius:14px;padding:11px 15px;font-weight:950;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:8px;text-decoration:none}.btn-primary{background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:white}.btn-light{background:white;color:#334155;border:1px solid var(--border)}.btn-green{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}.btn-red{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}.alert{border-radius:16px;padding:13px 15px;margin-bottom:16px;font-weight:900;display:flex;gap:10px;align-items:flex-start}.alert.success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}.alert.error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}.alert.warning{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}.summary{background:#f8fafc;border:1px solid var(--border);border-radius:18px;padding:14px;margin-bottom:16px}.summary h3{font-size:1rem;margin-bottom:10px}.summary-row{display:grid;grid-template-columns:160px 1fr;gap:10px;padding:7px 0;border-top:1px solid #e2e8f0;font-size:.84rem;font-weight:850}.summary-row:first-of-type{border-top:0}.summary-label{color:#64748b}.password-box{background:#111827;color:white;padding:9px 11px;border-radius:11px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;display:inline-block;letter-spacing:.03em}.app-list{padding:14px;display:grid;gap:12px}.app-card{border:1px solid var(--border);border-radius:18px;background:white;padding:14px}.app-top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:10px}.app-name{font-weight:950;font-size:1rem}.app-sub{color:#64748b;font-size:.78rem;font-weight:850;margin-top:4px;line-height:1.45}.pill{display:inline-flex;padding:6px 10px;border-radius:999px;font-size:.66rem;font-weight:950;text-transform:uppercase}.pill.pending{background:#fff7ed;color:#c2410c}.pill.approved{background:#dcfce7;color:#166534}.pill.rejected{background:#fee2e2;color:#991b1b}.detail-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px;margin:12px 0}.detail{border:1px solid #eef2f7;border-radius:14px;padding:10px;background:#f8fafc}.detail-label{font-size:.62rem;color:#64748b;font-weight:950;text-transform:uppercase}.detail-value{font-size:.86rem;font-weight:950;margin-top:4px}.app-actions{display:flex;gap:10px;align-items:flex-start;margin-top:12px;flex-wrap:wrap}.reject-form{display:flex;gap:8px;flex:1;min-width:320px}.empty{padding:48px 18px;text-align:center;color:#64748b;font-weight:850}.footer-note{padding:12px 18px;border-top:1px solid var(--border);color:#64748b;font-size:.76rem;font-weight:850}@media(max-width:1200px){body{grid-template-columns:1fr}.sidebar{display:none}.stats,.detail-grid{grid-template-columns:repeat(2,1fr)}.filters{grid-template-columns:1fr}}@media(max-width:700px){.main{padding:24px 18px}.stats,.detail-grid{grid-template-columns:1fr}.reject-form{min-width:100%;display:grid}.topbar{display:block}.admin-pill{margin-top:12px;display:inline-flex}}
</style>
</head>
<body>
<aside class="sidebar">
    <div class="brand"><div class="brand-icon"><i class="fas fa-shield-halved"></i></div><div><div class="brand-title">Smart<span>VMS</span></div><div class="brand-sub">SUPERADMIN</div></div></div>
    <div class="section-label">Management</div>
    <a class="nav-link" href="superadmin_dash.php"><i class="fas fa-chart-pie"></i> Dashboard</a>
    <a class="nav-link active" href="superadmin_apartment_applications.php"><i class="fas fa-file-signature"></i> Apartment Applications</a>
    <a class="nav-link" href="superadmin_register_apartment.php"><i class="fas fa-building-circle-check"></i> Manual Register</a>
    <a class="nav-link" href="#"><i class="fas fa-gear"></i> System Config</a>
    <a class="nav-link" href="#"><i class="fas fa-receipt"></i> Subscription Payments</a>
    <a class="nav-link" href="#"><i class="fas fa-clipboard-list"></i> Audit Logs</a>
    <div class="spacer"></div>
    <a class="nav-link" href="logout.php"><i class="fas fa-right-from-bracket"></i> Logout</a>
</aside>
<main class="main">
    <div class="topbar"><div><div class="eyebrow">SmartVMS System Provider</div><h1>Apartment Applications</h1><p class="subtitle">Review apartment applications submitted from the public registration form. Approving an application will generate apartment, units, parking slots, subscription and admin account automatically.</p></div><div class="admin-pill"><i class="fas fa-crown" style="color:var(--primary)"></i> <?= h($superName) ?></div></div>

    <?php if ($needsSql): ?><div class="alert warning"><i class="fas fa-triangle-exclamation"></i><div>Please run <b>smartvms_apartment_application_setup_V2.sql</b> first. It creates the application table and required apartment setup tables.</div></div><?php endif; ?>
    <?php if ($msg): ?><div class="alert <?= h($msgType) ?>"><i class="fas <?= $msgType === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation' ?>"></i><div><?= h($msg) ?></div></div><?php endif; ?>
    <?php if ($createdSummary): ?><div class="summary"><h3><i class="fas fa-circle-check" style="color:var(--green)"></i> Approved Application Summary</h3><div class="summary-row"><div class="summary-label">Reference</div><div><?= h($createdSummary['application_ref']) ?></div></div><div class="summary-row"><div class="summary-label">Apartment</div><div><?= h($createdSummary['apartment_name']) ?> (#<?= h($createdSummary['apartment_id']) ?>)</div></div><div class="summary-row"><div class="summary-label">Admin Email</div><div><?= h($createdSummary['admin_email']) ?></div></div><div class="summary-row"><div class="summary-label">Admin Password</div><div><span class="password-box"><?= h($createdSummary['admin_password']) ?></span></div></div><div class="summary-row"><div class="summary-label">Generated Units</div><div><?= h($createdSummary['units']) ?> units</div></div><div class="summary-row"><div class="summary-label">Parking Slots</div><div>Resident: <?= h($createdSummary['resident_slots']) ?> / Visitor: <?= h($createdSummary['visitor_slots']) ?></div></div><div class="summary-row"><div class="summary-label">System Fee</div><div>RM <?= h($createdSummary['monthly_fee']) ?> / month from <?= h($createdSummary['start_date']) ?></div></div></div><?php endif; ?>

    <section class="stats"><div class="stat"><div class="stat-value" style="color:var(--orange)"><?= (int)$counts['pending'] ?></div><div class="stat-label">Pending</div></div><div class="stat"><div class="stat-value" style="color:var(--green)"><?= (int)$counts['approved'] ?></div><div class="stat-label">Approved</div></div><div class="stat"><div class="stat-value" style="color:var(--red)"><?= (int)$counts['rejected'] ?></div><div class="stat-label">Rejected</div></div><div class="stat"><div class="stat-value" style="color:var(--blue)"><?= (int)$counts['all'] ?></div><div class="stat-label">Total Applications</div></div></section>

    <section class="card">
        <div class="card-head"><div class="card-title"><i class="fas fa-file-signature"></i> Apartment Application List</div><a class="btn btn-light" href="apartment_application.php" target="_blank"><i class="fas fa-up-right-from-square"></i> Public Form</a></div>
        <form class="filters" method="GET"><input type="text" name="search" value="<?= h($search) ?>" placeholder="Search reference, apartment, email, city or state"><select name="status"><option value="pending" <?= $status==='pending'?'selected':'' ?>>Pending</option><option value="approved" <?= $status==='approved'?'selected':'' ?>>Approved</option><option value="rejected" <?= $status==='rejected'?'selected':'' ?>>Rejected</option><option value="all" <?= $status==='all'?'selected':'' ?>>All</option></select><button class="btn btn-primary" type="submit"><i class="fas fa-search"></i></button><a class="btn btn-light" href="superadmin_apartment_applications.php"><i class="fas fa-rotate-left"></i></a></form>
        <div class="app-list">
        <?php if (!$hasApplications): ?>
            <div class="empty">Application table not found. Run the setup SQL first.</div>
        <?php elseif (!$applications): ?>
            <div class="empty">No application found.</div>
        <?php else: ?>
            <?php foreach ($applications as $app):
                $blocks = normalize_blocks_sa((string)$app['block_names']);
                $unitTotal = count($blocks) * (int)$app['floors_per_block'] * (int)$app['units_per_floor'];
            ?>
            <article class="app-card">
                <div class="app-top"><div><div class="app-name"><?= h($app['apartment_name']) ?></div><div class="app-sub"><b><?= h($app['application_ref']) ?></b> · <?= h($app['address_line']) ?>, <?= h($app['postcode']) ?> <?= h($app['city']) ?>, <?= h($app['state']) ?><br>Admin Email: <?= h($app['contact_email']) ?></div></div><span class="pill <?= h($app['status']) ?>"><?= h($app['status']) ?></span></div>
                <div class="detail-grid"><div class="detail"><div class="detail-label">Blocks</div><div class="detail-value"><?= h($app['block_names']) ?></div></div><div class="detail"><div class="detail-label">Generated Units</div><div class="detail-value"><?= (int)$unitTotal ?></div></div><div class="detail"><div class="detail-label">Parking</div><div class="detail-value">R<?= (int)$app['resident_parking_slots'] ?> / V<?= (int)$app['visitor_parking_slots'] ?></div></div><div class="detail"><div class="detail-label">Monthly Fee</div><div class="detail-value">RM <?= h(number_format((float)$app['monthly_fee'],2)) ?></div></div></div>
                <div class="app-sub">Floors per block: <?= (int)$app['floors_per_block'] ?> · Units per floor: <?= (int)$app['units_per_floor'] ?> · Preferred start: <?= h($app['preferred_start_date'] ?: '-') ?> · Submitted: <?= h($app['created_at']) ?><?php if (!empty($app['notes'])): ?><br>Notes: <?= h($app['notes']) ?><?php endif; ?><?php if ($app['status'] === 'rejected' && !empty($app['rejection_reason'])): ?><br>Reject reason: <?= h($app['rejection_reason']) ?><?php endif; ?><?php if ($app['status'] === 'approved'): ?><br>Admin Email: <?= h($app['contact_email']) ?><?php if (!empty($app['admin_temp_password'])): ?> · Temporary Password: <span class="password-box"><?= h($app['admin_temp_password']) ?></span><?php else: ?> · Password set by applicant<?php endif; ?><?php endif; ?></div>
                <?php if ($app['status'] === 'pending'): ?>
                <div class="app-actions">
                    <form method="POST" data-confirm="Approve this application and generate apartment setup now?"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="application_id" value="<?= (int)$app['id'] ?>"><button class="btn btn-green" type="submit"><i class="fas fa-check"></i> Approve & Generate</button></form>
                    <form method="POST" class="reject-form" data-confirm="Reject this application?"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="application_id" value="<?= (int)$app['id'] ?>"><input type="text" name="rejection_reason" placeholder="Reject reason, e.g. incomplete address"><button class="btn btn-red" type="submit"><i class="fas fa-xmark"></i> Reject</button></form>
                </div>
                <?php endif; ?>
            </article>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>
        <div class="footer-note">Approving a pending application creates the apartment profile, generated unit list, parking slots, subscription, and apartment admin account.</div>
    </section>
</main>
<script>
document.querySelectorAll('form[data-confirm]').forEach(form=>{form.addEventListener('submit',e=>{if(!confirm(form.getAttribute('data-confirm'))){e.preventDefault();}})});
</script>
</body>
</html>
