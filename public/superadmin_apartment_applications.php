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
            // Service/free-month period starts only after superadmin approval.
            $startDate = date('Y-m-d');

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
            // Use the password chosen by the apartment applicant.
            // apartment_application.php should store this as a bcrypt/argon hash.
            // For old pending records that accidentally stored plain text, hash it here once.
            $storedPassword = (string)($app['admin_password_hash'] ?? '');
            if ($storedPassword === '') {
                throw new Exception('Application password is missing. Please reject this application and ask the applicant to submit again.');
            }

            $isValidHash = password_get_info($storedPassword)['algo'] !== 0;
            if ($isValidHash) {
                $hash = $storedPassword;
            } else {
                $hash = password_hash($storedPassword, PASSWORD_DEFAULT);
                if ($hasApplicationPasswordHash) {
                    $fixStmt = $pdo->prepare("UPDATE apartment_applications SET admin_password_hash = ?, updated_at = NOW() WHERE id = ?");
                    $fixStmt->execute([$hash, $applicationId]);
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO users
                (apartment_id, full_name, email, contact_number, phone, password_hash, must_change_password, role, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 0, 'admin', 'active', NOW())
            ");
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
            $stmt = $pdo->prepare("
                UPDATE apartment_applications
                SET status = 'approved', apartment_id = ?, admin_user_id = ?, admin_temp_password = NULL, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$apartmentId, $adminId, $superId ?: null, $applicationId]);

            add_audit_sa($pdo, 'APARTMENT_APPLICATION_APPROVED', "Application {$app['application_ref']} approved. Apartment #$apartmentId created. Admin: $adminEmail");
            $pdo->commit();

            $msg = 'Application approved successfully. Apartment, units, parking slots, subscription and admin account were generated.';
            $msgType = 'success';
            $createdSummary = [
                'application_ref' => $app['application_ref'],
                'apartment_name' => $apartmentName,
                'apartment_id' => $apartmentId,
                'admin_email' => $adminEmail,
                'admin_password' => 'Password set by applicant',
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
    <?php if ($createdSummary): ?><div class="summary"><h3><i class="fas fa-circle-check" style="color:var(--green)"></i> Approved Application Summary</h3><div class="summary-row"><div class="summary-label">Reference</div><div><?= h($createdSummary['application_ref']) ?></div></div><div class="summary-row"><div class="summary-label">Apartment</div><div><?= h($createdSummary['apartment_name']) ?> (#<?= h($createdSummary['apartment_id']) ?>)</div></div><div class="summary-row"><div class="summary-label">Admin Email</div><div><?= h($createdSummary['admin_email']) ?></div></div><div class="summary-row"><div class="summary-label">Admin Password</div><div><?= h($createdSummary['admin_password']) ?></div></div><div class="summary-row"><div class="summary-label">Generated Units</div><div><?= h($createdSummary['units']) ?> units</div></div><div class="summary-row"><div class="summary-label">Parking Slots</div><div>Resident: <?= h($createdSummary['resident_slots']) ?> / Visitor: <?= h($createdSummary['visitor_slots']) ?></div></div><div class="summary-row"><div class="summary-label">System Fee</div><div>RM <?= h($createdSummary['monthly_fee']) ?> / month after first month free. Start date: <?= h($createdSummary['start_date']) ?></div></div></div><?php endif; ?>

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
                <div class="app-sub">Floors per block: <?= (int)$app['floors_per_block'] ?> · Units per floor: <?= (int)$app['units_per_floor'] ?> · Service starts after approval · Submitted: <?= h($app['created_at']) ?><?php if (!empty($app['notes'])): ?><br>Notes: <?= h($app['notes']) ?><?php endif; ?><?php if ($app['status'] === 'rejected' && !empty($app['rejection_reason'])): ?><br>Reject reason: <?= h($app['rejection_reason']) ?><?php endif; ?><?php if ($app['status'] === 'approved'): ?><br>Admin Email: <?= h($app['contact_email']) ?> · Password set by applicant<?php endif; ?></div>
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
