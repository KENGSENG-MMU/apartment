<?php
require_once '../core/security.php';
require_login(['superadmin']);

$pdo = db();
$currentEmail = $_SESSION['email'] ?? 'superadmin@smartvms.local';
$currentName = explode('@', $currentEmail)[0] ?: 'Superadmin';

function sa_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function sa_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function sa_count(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function sa_value(PDO $pdo, string $sql, array $params = [], $default = null) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : $value;
    } catch (Throwable $e) {
        return $default;
    }
}

function sa_rows(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function sa_money($amount): string {
    return 'RM ' . number_format((float)$amount, 2);
}

function sa_date($value): string {
    if (!$value) return '-';
    $time = strtotime((string)$value);
    return $time ? date('d M Y', $time) : '-';
}

$hasApartments = sa_table_exists($pdo, 'apartments');
$hasUsers = sa_table_exists($pdo, 'users');
$hasUnits = sa_table_exists($pdo, 'units');
$hasParkingSlots = sa_table_exists($pdo, 'parking_slots');
$hasParkingApartmentId = $hasParkingSlots && sa_column_exists($pdo, 'parking_slots', 'apartment_id');
$hasSetupProfiles = sa_table_exists($pdo, 'apartment_setup_profiles');
$hasSubscriptions = sa_table_exists($pdo, 'apartment_subscriptions');
$hasSubscriptionPayments = sa_table_exists($pdo, 'apartment_subscription_payments');

$totalApartments = $hasApartments ? sa_count($pdo, "SELECT COUNT(*) FROM apartments") : 0;
$activeApartments = $hasApartments ? sa_count($pdo, "SELECT COUNT(*) FROM apartments WHERE COALESCE(status,'active') = 'active'") : 0;
$totalAdmins = $hasUsers ? sa_count($pdo, "SELECT COUNT(*) FROM users WHERE role = 'admin'") : 0;
$activeAdmins = $hasUsers ? sa_count($pdo, "SELECT COUNT(*) FROM users WHERE role = 'admin' AND COALESCE(status,'active') = 'active'") : 0;

$activeSubscriptions = $hasSubscriptions ? sa_count($pdo, "SELECT COUNT(*) FROM apartment_subscriptions WHERE status = 'active'") : 0;
$attentionSubscriptions = $hasSubscriptions ? sa_count($pdo, "SELECT COUNT(*) FROM apartment_subscriptions WHERE status IN ('unpaid','suspended')") : 0;
$monthlyRevenue = $hasSubscriptions ? (float)sa_value($pdo, "SELECT COALESCE(SUM(monthly_fee),0) FROM apartment_subscriptions WHERE status = 'active'", [], 0) : 0;
$thisMonthPaid = $hasSubscriptionPayments ? (float)sa_value($pdo, "SELECT COALESCE(SUM(amount),0) FROM apartment_subscription_payments WHERE billing_month = ? AND payment_status = 'paid'", [date('Y-m')], 0) : 0;

$totalUnits = $hasUnits ? sa_count($pdo, "SELECT COUNT(*) FROM units") : 0;
$residentSlots = $hasParkingSlots ? sa_count($pdo, "SELECT COUNT(*) FROM parking_slots WHERE slot_type = 'Resident'") : 0;
$visitorSlots = $hasParkingSlots ? sa_count($pdo, "SELECT COUNT(*) FROM parking_slots WHERE slot_type = 'Visitor'") : 0;

$recentApartments = $hasApartments ? sa_rows($pdo, "SELECT id, apartment_name, address, status, created_at FROM apartments ORDER BY id DESC LIMIT 8") : [];

$apartmentsView = [];
foreach ($recentApartments as $apt) {
    $aptId = (int)($apt['id'] ?? 0);
    $adminCount = $hasUsers ? sa_count($pdo, "SELECT COUNT(*) FROM users WHERE role = 'admin' AND apartment_id = ?", [$aptId]) : 0;
    $adminEmail = $hasUsers ? sa_value($pdo, "SELECT email FROM users WHERE role = 'admin' AND apartment_id = ? ORDER BY id ASC LIMIT 1", [$aptId], '-') : '-';
    $unitCount = $hasUnits ? sa_count($pdo, "SELECT COUNT(*) FROM units WHERE apartment_id = ?", [$aptId]) : 0;

    if ($hasParkingApartmentId) {
        $rSlots = sa_count($pdo, "SELECT COUNT(*) FROM parking_slots WHERE apartment_id = ? AND slot_type = 'Resident'", [$aptId]);
        $vSlots = sa_count($pdo, "SELECT COUNT(*) FROM parking_slots WHERE apartment_id = ? AND slot_type = 'Visitor'", [$aptId]);
    } else {
        $rSlots = 0;
        $vSlots = 0;
    }

    $sub = null;
    if ($hasSubscriptions) {
        $rows = sa_rows($pdo, "SELECT status, monthly_fee, next_billing_date FROM apartment_subscriptions WHERE apartment_id = ? ORDER BY id DESC LIMIT 1", [$aptId]);
        $sub = $rows[0] ?? null;
    }

    $apartmentsView[] = [
        'id' => $aptId,
        'name' => $apt['apartment_name'] ?? '-',
        'address' => $apt['address'] ?? '-',
        'status' => $apt['status'] ?? 'active',
        'created_at' => $apt['created_at'] ?? null,
        'admin_count' => $adminCount,
        'admin_email' => $adminEmail,
        'unit_count' => $unitCount,
        'resident_slots' => $rSlots,
        'visitor_slots' => $vSlots,
        'subscription_status' => $sub['status'] ?? 'not set',
        'monthly_fee' => $sub['monthly_fee'] ?? null,
        'next_billing_date' => $sub['next_billing_date'] ?? null,
    ];
}

$recentAudit = sa_table_exists($pdo, 'audit_logs') ? sa_rows($pdo, "
    SELECT al.action, COALESCE(al.detail, al.details, al.description, '') AS detail, al.created_at, u.email
    FROM audit_logs al
    LEFT JOIN users u ON u.id = al.user_id
    ORDER BY al.created_at DESC, al.id DESC
    LIMIT 6
") : [];

$setupWarning = [];
if (!$hasSubscriptions) $setupWarning[] = 'apartment_subscriptions table not found. Run smartvms_superadmin_apartment_setup.sql.';
if (!$hasSetupProfiles) $setupWarning[] = 'apartment_setup_profiles table not found. Run smartvms_superadmin_apartment_setup.sql.';
if ($hasParkingSlots && !$hasParkingApartmentId) $setupWarning[] = 'parking_slots.apartment_id is missing. Run smartvms_superadmin_apartment_setup.sql for multi-apartment parking.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Superadmin Dashboard - <?= e(APP_NAME) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --primary:#4f46e5;
            --primary-dark:#312e81;
            --primary-soft:#e0e7ff;
            --bg:#f8fafc;
            --panel:#ffffff;
            --text:#0f172a;
            --muted:#64748b;
            --border:#e5e7eb;
            --blue:#2563eb;
            --green:#16a34a;
            --orange:#f97316;
            --shadow:0 18px 45px rgba(15,23,42,.08);
            --soft-shadow:0 10px 24px rgba(15,23,42,.07);
        }
        *{box-sizing:border-box;margin:0;padding:0;font-family:'Plus Jakarta Sans',sans-serif;}
        body{min-height:100vh;background:radial-gradient(circle at 78% 12%,rgba(79,70,229,.14),transparent 28%),linear-gradient(135deg,#f8faff 0%,#f8fafc 42%,#eef2ff 100%);color:var(--text);}
        .app{display:grid;grid-template-columns:270px 1fr;min-height:100vh;}
        .sidebar{background:rgba(255,255,255,.92);border-right:1px solid var(--border);padding:26px 20px;display:flex;flex-direction:column;gap:18px;position:sticky;top:0;height:100vh;overflow:auto;}
        .brand{display:flex;align-items:center;gap:13px;padding:0 4px 12px;}
        .brand-icon{width:56px;height:56px;border-radius:20px;background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff;display:grid;place-items:center;box-shadow:0 18px 30px rgba(79,70,229,.20);font-size:1.25rem;}
        .brand-title{font-size:1.25rem;font-weight:950;letter-spacing:-.04em;}
        .brand-title span{color:var(--primary);}
        .brand-sub{color:var(--muted);font-size:.75rem;font-weight:900;text-transform:uppercase;letter-spacing:.08em;margin-top:2px;}
        .apartment-box{border:1px solid #c7d2fe;border-radius:20px;background:#f8faff;padding:16px;display:flex;align-items:center;gap:12px;}
        .apartment-box i{width:42px;height:42px;border-radius:15px;background:#e0e7ff;color:var(--primary);display:grid;place-items:center;}
        .apartment-box small{display:block;color:var(--muted);font-weight:950;font-size:.68rem;text-transform:uppercase;letter-spacing:.08em;margin-bottom:3px;}
        .apartment-box strong{font-size:.93rem;}
        .nav-section{font-size:.72rem;color:#94a3b8;font-weight:950;text-transform:uppercase;letter-spacing:.12em;margin:8px 8px 4px;}
        .nav-link{display:flex;align-items:center;gap:12px;text-decoration:none;color:#475569;font-weight:900;font-size:.9rem;padding:12px 14px;border-radius:15px;transition:.18s ease;}
        .nav-link i{width:18px;text-align:center;color:#94a3b8;}
        .nav-link:hover{background:#eef2ff;color:var(--primary);}
        .nav-link:hover i{color:var(--primary);}
        .nav-link.active{background:linear-gradient(135deg,#4f46e5,#3730a3);color:#fff;box-shadow:0 18px 28px rgba(79,70,229,.24);}
        .nav-link.active i{color:#fff;}
        .nav-link.logout{background:#fff1f2;color:#991b1b;margin-top:auto;}
        .main{padding:34px 38px;min-width:0;}
        .topbar{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:22px;}
        .eyebrow{color:var(--primary);font-size:.72rem;font-weight:950;text-transform:uppercase;letter-spacing:.18em;margin-bottom:5px;}
        h1{font-size:2.1rem;line-height:1.05;letter-spacing:-.07em;font-weight:950;}
        .sub{color:#475569;font-size:.92rem;font-weight:800;margin-top:8px;max-width:800px;line-height:1.5;}
        .top-actions{display:flex;align-items:center;gap:10px;}
        .search-pill{height:46px;width:320px;border:1px solid var(--border);border-radius:16px;background:white;display:flex;align-items:center;gap:10px;padding:0 16px;color:#64748b;font-weight:850;box-shadow:var(--soft-shadow);}
        .round{width:46px;height:46px;border:0;border-radius:16px;background:white;color:#64748b;display:grid;place-items:center;box-shadow:var(--soft-shadow);text-decoration:none;}
        .user-badge{width:50px;height:50px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:white;display:grid;place-items:center;font-weight:950;box-shadow:0 18px 30px rgba(79,70,229,.25);}
        .warn{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:18px;padding:14px 16px;margin:0 0 18px;font-weight:850;font-size:.84rem;display:grid;gap:6px;}
        .stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:18px;}
        .stat{background:rgba(255,255,255,.96);border:1px solid rgba(229,231,235,.95);border-radius:22px;box-shadow:var(--soft-shadow);padding:18px;display:flex;align-items:center;justify-content:space-between;gap:14px;min-height:106px;}
        .stat-label{color:#64748b;text-transform:uppercase;letter-spacing:.07em;font-weight:950;font-size:.7rem;margin-bottom:10px;}
        .stat-value{font-size:1.8rem;font-weight:950;letter-spacing:-.06em;line-height:1;color:var(--blue);}
        .stat-sub{margin-top:8px;color:#64748b;font-weight:850;font-size:.78rem;}
        .stat-icon{width:48px;height:48px;border-radius:18px;display:grid;place-items:center;background:#e0e7ff;color:var(--primary);font-size:1.05rem;}
        .stat-icon.blue{background:#dbeafe;color:#2563eb;}.stat-icon.green{background:#dcfce7;color:#16a34a;}.stat-icon.orange{background:#ffedd5;color:#ea580c;}.stat-icon.purple{background:#ede9fe;color:#7c3aed;}
        .layout{display:grid;grid-template-columns:minmax(640px,1fr) 380px;gap:18px;align-items:start;}
        .panel{background:rgba(255,255,255,.97);border:1px solid rgba(229,231,235,.95);border-radius:22px;box-shadow:var(--shadow);overflow:hidden;}
        .panel-head{height:62px;border-bottom:1px solid var(--border);padding:0 20px;display:flex;align-items:center;justify-content:space-between;gap:14px;}
        .panel-title{font-weight:950;letter-spacing:-.04em;display:flex;align-items:center;gap:10px;}.panel-title i{color:var(--primary);}
        .panel-link{color:var(--primary);font-weight:950;text-decoration:none;font-size:.78rem;}
        .apt-list{padding:14px;display:grid;gap:12px;max-height:620px;overflow:auto;}
        .apt-card{border:1px solid #e5e7eb;border-radius:18px;padding:15px;background:#fff;display:grid;grid-template-columns:1fr auto;gap:12px;}
        .apt-name{font-weight:950;font-size:1rem;letter-spacing:-.04em;margin-bottom:4px;}.apt-address{color:#64748b;font-weight:800;font-size:.78rem;line-height:1.35;}
        .pill{display:inline-flex;width:fit-content;align-items:center;gap:7px;padding:6px 10px;border-radius:999px;font-weight:950;font-size:.65rem;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap;}
        .pill.active,.pill.paid{background:#dcfce7;color:#166534;}.pill.unpaid,.pill.suspended{background:#fee2e2;color:#991b1b;}.pill.inactive,.pill.cancelled,.pill.not{background:#f1f5f9;color:#475569;}
        .apt-metrics{grid-column:1/-1;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:8px;}
        .mini{background:#f8fafc;border:1px solid #eef2f7;border-radius:14px;padding:10px;}.mini b{display:block;font-size:1rem;}.mini span{display:block;color:#64748b;font-size:.68rem;font-weight:950;text-transform:uppercase;margin-top:3px;}
        .right-stack{display:grid;gap:18px;}
        .quick-grid{padding:16px;display:grid;gap:10px;}.quick{display:flex;align-items:center;gap:12px;padding:14px;border:1px solid #e5e7eb;border-radius:16px;text-decoration:none;color:#0f172a;font-weight:950;background:#fff;}.quick i{width:38px;height:38px;border-radius:14px;background:#e0e7ff;color:var(--primary);display:grid;place-items:center;}.quick:hover{border-color:#c7d2fe;background:#f8faff;}
        .audit{padding:12px 16px;display:grid;gap:10px;}.audit-item{border-bottom:1px solid #eef2f7;padding:10px 0;}.audit-item:last-child{border-bottom:0}.audit-action{font-weight:950;font-size:.78rem;}.audit-detail{color:#64748b;font-size:.74rem;font-weight:800;line-height:1.4;margin-top:4px;max-height:38px;overflow:hidden;}.audit-date{color:#94a3b8;font-size:.68rem;font-weight:900;margin-top:5px;}
        .empty{padding:40px 20px;text-align:center;color:#64748b;font-weight:850;}
        @media(max-width:1200px){.app{grid-template-columns:1fr}.sidebar{position:relative;height:auto}.stats{grid-template-columns:repeat(2,1fr)}.layout{grid-template-columns:1fr}.search-pill{display:none}}
        @media(max-width:720px){.main{padding:22px}.stats{grid-template-columns:1fr}.apt-card{grid-template-columns:1fr}.apt-metrics{grid-template-columns:repeat(2,1fr)}}
    </style>
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-icon"><i class="fas fa-shield-halved"></i></div>
            <div><div class="brand-title">Smart<span>VMS</span></div><div class="brand-sub">Superadmin Panel</div></div>
        </div>
        <div class="apartment-box">
            <i class="fas fa-building-shield"></i>
            <div><small>Superadmin View</small><strong>All Apartments</strong></div>
        </div>

        <div class="nav-section">Main</div>
        <a href="superadmin_dash.php" class="nav-link active"><i class="fas fa-chart-line"></i> Dashboard</a>
        <a href="superadmin_apartment_applications.php" class="nav-link"><i class="fas fa-file-signature"></i> Apartment Applications</a>
        <a href="superadmin_register_apartment.php" class="nav-link"><i class="fas fa-building-circle-check"></i> Manual Register</a>
        <a href="admin_users.php" class="nav-link"><i class="fas fa-users-gear"></i> System Users</a>
        <a href="superadmin_config.php" class="nav-link"><i class="fas fa-sliders"></i> System Config</a>

        <div class="nav-section">Records</div>
        <a href="admin_audit_logs.php" class="nav-link"><i class="fas fa-clipboard-list"></i> Audit Logs</a>
        <a href="admin_dash.php" class="nav-link"><i class="fas fa-user-tie"></i> Admin View</a>
        <a href="logout.php" class="nav-link logout"><i class="fas fa-right-from-bracket"></i> Logout</a>
    </aside>

    <main class="main">
        <div class="topbar">
            <div>
                <div class="eyebrow">Platform Control Center</div>
                <h1>Superadmin Dashboard</h1>
                <div class="sub">Manage apartment customers, subscriptions, generated units, parking capacity, and system-wide admin accounts.</div>
            </div>
            <div class="top-actions">
                <div class="search-pill"><i class="fas fa-magnifying-glass"></i> SmartVMS platform overview</div>
                <a class="round" href="superadmin_config.php" title="System Config"><i class="fas fa-sliders"></i></a>
                <a class="round" href="admin_audit_logs.php" title="Audit Logs"><i class="fas fa-clipboard-list"></i></a>
                <div class="user-badge"><?= e(strtoupper(substr($currentName,0,1))) ?></div>
            </div>
        </div>

        <?php if (!empty($setupWarning)): ?>
            <div class="warn">
                <?php foreach ($setupWarning as $w): ?>
                    <div><i class="fas fa-triangle-exclamation"></i> <?= e($w) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="stats">
            <div class="stat">
                <div><div class="stat-label">Apartments</div><div class="stat-value"><?= (int)$activeApartments ?></div><div class="stat-sub">Active / <?= (int)$totalApartments ?> total</div></div>
                <div class="stat-icon blue"><i class="fas fa-building"></i></div>
            </div>
            <div class="stat">
                <div><div class="stat-label">Monthly Revenue</div><div class="stat-value" style="font-size:1.45rem;color:var(--green)"><?= e(sa_money($monthlyRevenue)) ?></div><div class="stat-sub">Active system subscriptions</div></div>
                <div class="stat-icon green"><i class="fas fa-money-bill-wave"></i></div>
            </div>
            <div class="stat">
                <div><div class="stat-label">Subscriptions</div><div class="stat-value" style="color:var(--orange)"><?= (int)$activeSubscriptions ?></div><div class="stat-sub"><?= (int)$attentionSubscriptions ?> need attention</div></div>
                <div class="stat-icon orange"><i class="fas fa-receipt"></i></div>
            </div>
            <div class="stat">
                <div><div class="stat-label">Apartment Admins</div><div class="stat-value"><?= (int)$activeAdmins ?></div><div class="stat-sub">Active / <?= (int)$totalAdmins ?> total</div></div>
                <div class="stat-icon purple"><i class="fas fa-user-tie"></i></div>
            </div>
        </section>

        <div class="layout">
            <section class="panel">
                <div class="panel-head">
                    <div class="panel-title"><i class="fas fa-city"></i> Recent Apartment Accounts</div>
                    <a class="panel-link" href="superadmin_apartment_applications.php">Review Applications</a>
                </div>
                <div class="apt-list">
                    <?php if (empty($apartmentsView)): ?>
                        <div class="empty">No apartment account found yet.</div>
                    <?php endif; ?>
                    <?php foreach ($apartmentsView as $apt): ?>
                        <?php $statusClass = strtolower(str_replace(' ', '', (string)$apt['subscription_status'])); ?>
                        <div class="apt-card">
                            <div>
                                <div class="apt-name"><?= e($apt['name']) ?></div>
                                <div class="apt-address"><?= e($apt['address']) ?></div>
                                <div class="apt-address" style="margin-top:6px;">Admin: <?= e($apt['admin_email']) ?> · Created <?= e(sa_date($apt['created_at'])) ?></div>
                            </div>
                            <div style="display:grid;gap:8px;justify-items:end;align-content:start;">
                                <span class="pill <?= e($statusClass ?: 'not') ?>"><?= e($apt['subscription_status']) ?></span>
                                <?php if ($apt['monthly_fee'] !== null): ?>
                                    <span style="font-weight:950;font-size:.82rem;"><?= e(sa_money($apt['monthly_fee'])) ?>/month</span>
                                <?php endif; ?>
                                <?php if ($apt['next_billing_date']): ?>
                                    <span style="color:#64748b;font-size:.72rem;font-weight:850;">Next: <?= e(sa_date($apt['next_billing_date'])) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="apt-metrics">
                                <div class="mini"><b><?= (int)$apt['unit_count'] ?></b><span>Units</span></div>
                                <div class="mini"><b><?= (int)$apt['resident_slots'] ?></b><span>Resident Slots</span></div>
                                <div class="mini"><b><?= (int)$apt['visitor_slots'] ?></b><span>Visitor Slots</span></div>
                                <div class="mini"><b><?= (int)$apt['admin_count'] ?></b><span>Admins</span></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <aside class="right-stack">
                <section class="panel">
                    <div class="panel-head"><div class="panel-title"><i class="fas fa-bolt"></i> Quick Actions</div></div>
                    <div class="quick-grid">
                        <a class="quick" href="superadmin_register_apartment.php"><i class="fas fa-building-circle-check"></i><span>Register New Apartment</span></a>
                        <a class="quick" href="admin_users.php"><i class="fas fa-users-gear"></i><span>Manage System Users</span></a>
                        <a class="quick" href="superadmin_config.php"><i class="fas fa-sliders"></i><span>System Configuration</span></a>
                        <a class="quick" href="admin_dash.php"><i class="fas fa-user-tie"></i><span>Open Admin View</span></a>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-head"><div class="panel-title"><i class="fas fa-layer-group"></i> Platform Capacity</div></div>
                    <div class="quick-grid">
                        <div class="mini"><b><?= (int)$totalUnits ?></b><span>Total Units Generated</span></div>
                        <div class="mini"><b><?= (int)$residentSlots ?></b><span>Total Resident Parking Slots</span></div>
                        <div class="mini"><b><?= (int)$visitorSlots ?></b><span>Total Visitor Parking Slots</span></div>
                        <div class="mini"><b><?= e(sa_money($thisMonthPaid)) ?></b><span>Paid This Month</span></div>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-head"><div class="panel-title"><i class="fas fa-clock-rotate-left"></i> Recent Audit Logs</div><a href="admin_audit_logs.php" class="panel-link">View All</a></div>
                    <div class="audit">
                        <?php if (empty($recentAudit)): ?>
                            <div class="empty" style="padding:18px;">No recent audit logs.</div>
                        <?php endif; ?>
                        <?php foreach ($recentAudit as $log): ?>
                            <div class="audit-item">
                                <div class="audit-action"><?= e($log['action'] ?? '-') ?></div>
                                <div class="audit-detail"><?= e($log['detail'] ?? '') ?></div>
                                <div class="audit-date"><?= e(sa_date($log['created_at'] ?? null)) ?> <?= !empty($log['email']) ? '· ' . e($log['email']) : '' ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            </aside>
        </div>
    </main>
</div>
</body>
</html>
