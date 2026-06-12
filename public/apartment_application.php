<?php
// File: public/apartment_application.php
require_once '../core/security.php';

$pdo = db();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$msg = '';
$msgType = '';
$submittedRef = '';

$malaysiaStates = [
    'Johor', 'Kedah', 'Kelantan', 'Melaka', 'Negeri Sembilan', 'Pahang',
    'Penang', 'Perak', 'Perlis', 'Sabah', 'Sarawak', 'Selangor',
    'Terengganu', 'Kuala Lumpur', 'Putrajaya', 'Labuan'
];

function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function app_table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function app_column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function normalize_blocks_public($raw): array {
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $parts = preg_split('/[,\n]+/', (string)$raw);
    }

    $blocks = [];
    foreach ($parts as $part) {
        $b = trim((string)$part);
        $b = preg_replace('/^block\s+/i', '', $b);
        $b = preg_replace('/^tower\s+/i', '', $b);
        $b = preg_replace('/[^A-Za-z0-9\-]/', '', $b);
        $b = strtoupper(trim($b));
        if ($b !== '' && preg_match('/[A-Z]/', $b) && !in_array($b, $blocks, true)) {
            $blocks[] = $b;
        }
    }
    return $blocks;
}

function make_application_ref(): string {
    return 'APP-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function valid_password(string $password): bool {
    return strlen($password) >= 8
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[a-z]/', $password)
        && preg_match('/[0-9]/', $password)
        && preg_match('/[^A-Za-z0-9]/', $password);
}

$hasApplicationTable = app_table_exists($pdo, 'apartment_applications');
$hasPasswordHash = $hasApplicationTable && app_column_exists($pdo, 'apartment_applications', 'admin_password_hash');
$hasBlockCount = $hasApplicationTable && app_column_exists($pdo, 'apartment_applications', 'block_count');
$needsSql = !$hasApplicationTable || !$hasPasswordHash || !$hasBlockCount;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        die('CSRF Token Validation Failed.');
    }

    try {
        if ($needsSql) {
            throw new Exception('Application table is not ready. Please run smartvms_apartment_application_setup_V2.sql first.');
        }

        $apartmentName = trim($_POST['apartment_name'] ?? '');
        $addressLine = trim($_POST['address_line'] ?? '');
        $postcode = trim($_POST['postcode'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $contactEmail = strtolower(trim($_POST['contact_email'] ?? ''));
        $adminPassword = (string)($_POST['admin_password'] ?? '');
        $confirmPassword = (string)($_POST['admin_password_confirm'] ?? '');
        $blockCount = (int)($_POST['block_count'] ?? 0);
        $blocks = normalize_blocks_public($_POST['block_names'] ?? []);
        $floorsPerBlock = (int)($_POST['floors_per_block'] ?? 0);
        $unitsPerFloor = (int)($_POST['units_per_floor'] ?? 0);
        $residentSlots = (int)($_POST['resident_parking_slots'] ?? 0);
        $visitorSlots = (int)($_POST['visitor_parking_slots'] ?? 0);
        $monthlyFee = (float)($_POST['monthly_fee'] ?? 300);
        $preferredStartDate = trim($_POST['preferred_start_date'] ?? date('Y-m-d'));
        $notes = trim($_POST['notes'] ?? '');

        if ($apartmentName === '' || !preg_match('/^[A-Za-z0-9 .\'&()\-]{3,120}$/', $apartmentName)) throw new Exception('Please enter a valid apartment name.');
        if ($addressLine === '' || strlen($addressLine) < 8 || !preg_match('/[A-Za-z]/', $addressLine) || !preg_match('/\d/', $addressLine)) throw new Exception('Please enter a complete address with building/lot number and road name.');
        if (!preg_match('/^\d{5}$/', $postcode)) throw new Exception('Please enter a valid 5-digit Malaysian postcode.');
        if ($city === '' || !preg_match('/^[A-Za-z .\'\-]{2,80}$/', $city)) throw new Exception('Please enter a valid city/town name.');
        if ($state === '' || !in_array($state, $malaysiaStates, true)) throw new Exception('Please select a valid Malaysian state.');
        if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) throw new Exception('Please enter a valid admin email.');
        if (!valid_password($adminPassword)) throw new Exception('Admin password must be at least 8 characters and include uppercase, lowercase, number and symbol.');
        if ($adminPassword !== $confirmPassword) throw new Exception('Confirm password does not match.');
        if ($blockCount < 1 || $blockCount > 12) throw new Exception('Number of blocks must be between 1 and 12.');
        if (count($blocks) !== $blockCount) throw new Exception('Please enter a valid name for every block.');
        if (count(array_unique($blocks)) !== count($blocks)) throw new Exception('Block names cannot be duplicated.');
        if ($floorsPerBlock < 1 || $floorsPerBlock > 80) throw new Exception('Floors per block must be between 1 and 80.');
        if ($unitsPerFloor < 1 || $unitsPerFloor > 50) throw new Exception('Units per floor must be between 1 and 50.');
        if ((count($blocks) * $floorsPerBlock * $unitsPerFloor) > 5000) throw new Exception('Too many units. Please reduce block/floor/unit count for demo use.');
        if ($residentSlots < 0 || $residentSlots > 3000 || $visitorSlots < 0 || $visitorSlots > 3000) throw new Exception('Parking slots must be between 0 and 3000.');
        if (($residentSlots + $visitorSlots) <= 0) throw new Exception('Please enter at least one parking slot.');
        if ($visitorSlots > $residentSlots && $residentSlots > 0) throw new Exception('Visitor parking should not be more than resident parking for a normal apartment setup.');
        if ($monthlyFee <= 0) throw new Exception('Monthly system fee must be more than RM 0.');
        if ($preferredStartDate !== '' && !DateTime::createFromFormat('Y-m-d', $preferredStartDate)) throw new Exception('Invalid preferred start date.');

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([$contactEmail]);
        if ((int)$stmt->fetchColumn() > 0) throw new Exception('This email already has an account in SmartVMS. Please use another email.');

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM apartment_applications WHERE contact_email = ? AND status = 'pending'");
        $stmt->execute([$contactEmail]);
        if ((int)$stmt->fetchColumn() > 0) throw new Exception('This email already has a pending application. Please wait for superadmin review.');

        $ref = make_application_ref();
        $adminName = $apartmentName . ' Admin';
        $passwordHash = password_hash($adminPassword, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("\n            INSERT INTO apartment_applications\n            (application_ref, apartment_name, address_line, postcode, city, state, contact_name, contact_email, admin_password_hash, contact_phone,\n             block_count, block_names, floors_per_block, units_per_floor, resident_parking_slots, visitor_parking_slots, monthly_fee,\n             preferred_start_date, notes, status, ip_address, created_at)\n            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())\n        ");
        $stmt->execute([
            $ref, $apartmentName, $addressLine, $postcode, $city, $state, $adminName, $contactEmail, $passwordHash,
            $blockCount, implode(',', $blocks), $floorsPerBlock, $unitsPerFloor, $residentSlots, $visitorSlots, $monthlyFee,
            $preferredStartDate ?: null, $notes, $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        $submittedRef = $ref;
        $msg = 'Application submitted successfully. Please wait for superadmin approval.';
        $msgType = 'success';
        $_POST = [];
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $msgType = 'error';
    }
}

$defaultDate = date('Y-m-d');
$postBlockCount = (int)($_POST['block_count'] ?? 1);
if ($postBlockCount < 1) $postBlockCount = 1;
if ($postBlockCount > 12) $postBlockCount = 12;
$postBlocks = $_POST['block_names'] ?? [];
if (!is_array($postBlocks)) $postBlocks = normalize_blocks_public($postBlocks);
$defaultBlockLetters = range('A', 'L');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Apartment Application - <?= h(APP_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--bg:#f8fafc;--surface:#fff;--primary:#4f46e5;--primary-dark:#3730a3;--primary-soft:#e0e7ff;--text:#0f172a;--muted:#64748b;--border:#e5e7eb;--green:#16a34a;--red:#dc2626;--shadow:0 18px 45px rgba(15,23,42,.08)}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Plus Jakarta Sans',sans-serif}body{min-height:100vh;background:linear-gradient(115deg,#f8fafc 0%,#fff 55%,#eef2ff 100%);color:var(--text)}
.header{padding:22px 6vw;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border);background:rgba(255,255,255,.82);backdrop-filter:blur(10px);position:sticky;top:0;z-index:5}.brand{display:flex;align-items:center;gap:12px}.brand-icon{width:46px;height:46px;border-radius:16px;background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:white;display:grid;place-items:center;box-shadow:0 12px 28px rgba(79,70,229,.24)}.brand-title{font-weight:950;line-height:1}.brand-title span{color:var(--primary)}.brand-sub{font-size:.68rem;color:var(--muted);font-weight:900;letter-spacing:.08em;margin-top:4px}.login-btn{border:1px solid var(--border);background:white;color:#334155;text-decoration:none;padding:11px 16px;border-radius:999px;font-weight:900;box-shadow:0 10px 25px rgba(15,23,42,.06)}
.main{padding:34px 6vw 50px}.top{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin-bottom:20px}.eyebrow{color:var(--primary);font-size:.72rem;font-weight:950;letter-spacing:.12em;text-transform:uppercase;margin-bottom:7px}h1{font-size:2.3rem;letter-spacing:-.07em;line-height:1}.subtitle{color:var(--muted);font-weight:800;font-size:.93rem;max-width:820px;margin-top:10px;line-height:1.5}.layout{display:grid;grid-template-columns:minmax(650px,1fr) 360px;gap:18px;align-items:start}.card{background:rgba(255,255,255,.96);border:1px solid var(--border);border-radius:22px;box-shadow:var(--shadow);overflow:hidden}.card-head{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;font-weight:950}.card-head i{color:var(--primary)}.card-body{padding:18px}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.field.full{grid-column:1/-1}.block-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;grid-column:1/-1}label{display:block;font-size:.68rem;color:#64748b;font-weight:950;text-transform:uppercase;letter-spacing:.06em;margin-bottom:7px}input,textarea,select{width:100%;border:1px solid #dbe3ef;border-radius:14px;padding:13px 14px;font-weight:850;color:#0f172a;background:white;outline:none}textarea{min-height:92px;resize:vertical}input:focus,textarea:focus,select:focus{border-color:var(--primary);box-shadow:0 0 0 4px rgba(79,70,229,.12)}.input-valid{border-color:#22c55e!important}.input-invalid{border-color:#ef4444!important;box-shadow:0 0 0 4px rgba(239,68,68,.1)!important}.field-error{min-height:16px;margin-top:5px;color:var(--red);font-size:.7rem;font-weight:900}.hint{color:var(--muted);font-size:.72rem;font-weight:800;margin-top:5px;line-height:1.45}.divider{height:1px;background:var(--border);margin:18px 0}.section-title{display:flex;align-items:center;gap:8px;font-weight:950;margin-bottom:14px}.section-title i{color:var(--primary)}.alert{padding:13px 15px;border-radius:16px;font-weight:900;margin-bottom:16px;display:flex;gap:10px;align-items:flex-start}.alert.success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}.alert.error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}.alert.warn{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}.js-alert{display:none}.js-alert.show{display:flex}.actions{display:flex;justify-content:flex-end;gap:10px;margin-top:18px}.btn{border:0;border-radius:14px;padding:13px 17px;font-weight:950;cursor:pointer;display:inline-flex;align-items:center;gap:8px}.btn-primary{background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:white;box-shadow:0 12px 26px rgba(79,70,229,.22)}.btn-light{background:#f8fafc;color:#334155;border:1px solid var(--border)}.side-note{padding:18px}.step{display:flex;gap:12px;padding:14px 0;border-bottom:1px solid #eef2f7}.step:last-child{border-bottom:0}.step-no{width:28px;height:28px;border-radius:10px;background:var(--primary-soft);color:var(--primary);display:grid;place-items:center;font-weight:950;flex:0 0 auto}.step-title{font-weight:950}.step-text{color:var(--muted);font-size:.78rem;font-weight:800;line-height:1.45;margin-top:3px}.success-box{padding:18px;border:1px solid #bbf7d0;background:#f0fdf4;border-radius:18px;margin-top:14px}.success-box b{color:#166534}@media(max-width:1080px){.layout{grid-template-columns:1fr}.form-grid,.block-grid{grid-template-columns:1fr}.top{display:block}}
</style>
</head>
<body>
<header class="header"><div class="brand"><div class="brand-icon"><i class="fas fa-shield-alt"></i></div><div><div class="brand-title">Smart<span>VMS</span></div><div class="brand-sub">APARTMENT APPLICATION</div></div></div><a class="login-btn" href="login.php"><i class="fas fa-right-to-bracket"></i> Staff Login</a></header>
<main class="main">
    <div class="top"><div><div class="eyebrow">SmartVMS Subscription Application</div><h1>Apply to Use SmartVMS</h1><p class="subtitle">Apartment management fills in the apartment information. After superadmin approval, the system will automatically create units, parking slots, subscription and the apartment admin account.</p></div></div>
    <section class="layout">
        <div class="card"><div class="card-head"><i class="fas fa-building"></i> Apartment Application Form</div><div class="card-body">
                <?php if ($needsSql): ?><div class="alert warn"><i class="fas fa-triangle-exclamation"></i><div>Application table is not ready. Please run <b>smartvms_apartment_application_setup_V2.sql</b> first.</div></div><?php endif; ?>
                <?php if ($msg): ?><div class="alert <?= h($msgType) ?>"><i class="fas fa-<?= $msgType === 'success' ? 'circle-check' : 'circle-exclamation' ?>"></i><div><?= h($msg) ?><?php if ($submittedRef): ?><div class="success-box">Application Reference: <b><?= h($submittedRef) ?></b></div><?php endif; ?></div></div><?php endif; ?>
                <div class="alert error js-alert" id="formAlert"><i class="fas fa-circle-exclamation"></i><div>Please correct the highlighted fields before submitting.</div></div>
                <form method="POST" id="applicationForm" novalidate>
                    <?= csrf_field() ?>
                    <div class="section-title"><i class="fas fa-building-user"></i> Apartment Information</div>
                    <div class="form-grid">
                        <div class="field"><label>Apartment Name</label><input type="text" name="apartment_name" placeholder="Example: Happy Residence" value="<?= h($_POST['apartment_name'] ?? '') ?>" required><div class="field-error" data-error-for="apartment_name"></div></div>
                        <div class="field"><label>Preferred Start Date</label><input type="date" name="preferred_start_date" value="<?= h($_POST['preferred_start_date'] ?? $defaultDate) ?>"><div class="field-error" data-error-for="preferred_start_date"></div></div>
                        <div class="field full"><label>Address Line</label><textarea name="address_line" placeholder="Example: No 12, Jalan Bukit Beruang"><?= h($_POST['address_line'] ?? '') ?></textarea><div class="field-error" data-error-for="address_line"></div><div class="hint">Must include building/lot number and road name.</div></div>
                        <div class="field"><label>Postcode</label><input type="text" name="postcode" maxlength="5" placeholder="Example: 75450" value="<?= h($_POST['postcode'] ?? '') ?>" required><div class="field-error" data-error-for="postcode"></div></div>
                        <div class="field"><label>City / Town</label><input type="text" name="city" placeholder="Example: Bukit Beruang" value="<?= h($_POST['city'] ?? '') ?>" required><div class="field-error" data-error-for="city"></div></div>
                        <div class="field full"><label>State</label><select name="state" required><option value="">Select state</option><?php foreach ($malaysiaStates as $ms): ?><option value="<?= h($ms) ?>" <?= (($_POST['state'] ?? '') === $ms) ? 'selected' : '' ?>><?= h($ms) ?></option><?php endforeach; ?></select><div class="field-error" data-error-for="state"></div></div>
                    </div>
                    <div class="divider"></div>
                    <div class="section-title"><i class="fas fa-user-lock"></i> Apartment Admin Account</div>
                    <div class="form-grid">
                        <div class="field full"><label>Admin Email</label><input type="email" name="contact_email" placeholder="Example: admin@apartment.com" value="<?= h($_POST['contact_email'] ?? '') ?>" required><div class="field-error" data-error-for="contact_email"></div><div class="hint">After approval, this email will be used as the apartment admin login email.</div></div>
                        <div class="field"><label>Admin Password</label><input type="password" name="admin_password" placeholder="Minimum 8 characters" required autocomplete="new-password"><div class="field-error" data-error-for="admin_password"></div></div>
                        <div class="field"><label>Confirm Password</label><input type="password" name="admin_password_confirm" placeholder="Re-enter password" required autocomplete="new-password"><div class="field-error" data-error-for="admin_password_confirm"></div></div>
                    </div>
                    <div class="divider"></div>
                    <div class="section-title"><i class="fas fa-layer-group"></i> Building / Unit Information</div>
                    <div class="form-grid">
                        <div class="field full"><label>Number of Blocks / Towers</label><select name="block_count" id="blockCount" required><?php for($i=1;$i<=12;$i++): ?><option value="<?= $i ?>" <?= $postBlockCount === $i ? 'selected' : '' ?>><?= $i ?> block<?= $i>1?'s':'' ?></option><?php endfor; ?></select><div class="field-error" data-error-for="block_count"></div><div class="hint">Select how many blocks first. Then enter each block name below.</div></div>
                        <div class="block-grid" id="blockNameGrid">
                            <?php for($i=0;$i<$postBlockCount;$i++): $val = $postBlocks[$i] ?? $defaultBlockLetters[$i] ?? ('B'.($i+1)); ?>
                            <div class="field block-name-field"><label>Block <?= $i+1 ?> Name</label><input type="text" name="block_names[]" value="<?= h($val) ?>" placeholder="Example: <?= h($defaultBlockLetters[$i] ?? ('B'.($i+1))) ?>" required><div class="field-error" data-error-for="block_names_<?= $i ?>"></div></div>
                            <?php endfor; ?>
                        </div>
                        <div class="field"><label>Floors Per Block</label><input type="number" name="floors_per_block" min="1" max="80" value="<?= h($_POST['floors_per_block'] ?? '13') ?>" required><div class="field-error" data-error-for="floors_per_block"></div></div>
                        <div class="field"><label>Units Per Floor</label><input type="number" name="units_per_floor" min="1" max="50" value="<?= h($_POST['units_per_floor'] ?? '8') ?>" required><div class="field-error" data-error-for="units_per_floor"></div></div>
                    </div>
                    <div class="divider"></div>
                    <div class="section-title"><i class="fas fa-square-parking"></i> Parking / Subscription</div>
                    <div class="form-grid">
                        <div class="field"><label>Resident Parking Slots</label><input type="number" name="resident_parking_slots" min="0" max="3000" value="<?= h($_POST['resident_parking_slots'] ?? '120') ?>" required><div class="field-error" data-error-for="resident_parking_slots"></div></div>
                        <div class="field"><label>Visitor Parking Slots</label><input type="number" name="visitor_parking_slots" min="0" max="3000" value="<?= h($_POST['visitor_parking_slots'] ?? '30') ?>" required><div class="field-error" data-error-for="visitor_parking_slots"></div></div>
                        <div class="field full"><label>Monthly System Fee (RM)</label><input type="number" name="monthly_fee" min="1" step="0.01" value="<?= h($_POST['monthly_fee'] ?? '300.00') ?>" required><div class="field-error" data-error-for="monthly_fee"></div></div>
                        <div class="field full"><label>Notes / Request</label><textarea name="notes" placeholder="Optional: special setup request, preferred package, etc."><?= h($_POST['notes'] ?? '') ?></textarea><div class="field-error" data-error-for="notes"></div></div>
                    </div>
                    <div class="actions"><button type="reset" class="btn btn-light"><i class="fas fa-rotate-left"></i> Reset</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Application</button></div>
                </form>
            </div></div>
        <aside class="card"><div class="card-head"><i class="fas fa-list-check"></i> What Happens Next?</div><div class="side-note">
            <div class="step"><div class="step-no">1</div><div><div class="step-title">Submit application</div><div class="step-text">Apartment management submits address, building structure, parking and admin login details.</div></div></div>
            <div class="step"><div class="step-no">2</div><div><div class="step-title">Superadmin reviews</div><div class="step-text">SmartVMS superadmin checks the application details.</div></div></div>
            <div class="step"><div class="step-no">3</div><div><div class="step-title">Account activated</div><div class="step-text">After approval, apartment, units, parking slots and subscription are generated automatically.</div></div></div>
            <div class="step"><div class="step-no">4</div><div><div class="step-title">Admin starts using</div><div class="step-text">The admin uses the email and password entered in this application to log in.</div></div></div>
        </div></aside>
    </section>
</main>
<script>
const MY_POSTCODE_PREFIX={Johor:['80','81','82','83','84','85','86'],Kedah:['05','06','07','08','09'],Kelantan:['15','16','17','18'],Melaka:['75','76','77','78'],"Negeri Sembilan":['70','71','72','73'],Pahang:['25','26','27','28','39'],Penang:['10','11','12','13','14'],Perak:['30','31','32','33','34','35','36'],Perlis:['01'],Sabah:['88','89','90','91'],Sarawak:['93','94','95','96','97','98'],Selangor:['40','41','42','43','44','45','46','47','48'],Terengganu:['20','21','22','23'],"Kuala Lumpur":['50','51','52','53','54','55','56','57','58','59','60'],Putrajaya:['62'],Labuan:['87']};
const DEFAULT_BLOCKS=['A','B','C','D','E','F','G','H','I','J','K','L'];
function q(n){return document.querySelector(`[name="${n}"]`)}
function qa(n){return [...document.querySelectorAll(`[name="${n}"]`)]}
function err(n,m){let el=q(n),e=document.querySelector(`[data-error-for="${n}"]`);if(!el)return false;el.classList.toggle('input-invalid',!!m);el.classList.toggle('input-valid',!m&&el.value.trim()!=='');if(e)e.textContent=m||'';return !m}
function errBlock(i,m){let el=qa('block_names[]')[i],e=document.querySelector(`[data-error-for="block_names_${i}"]`);if(!el)return false;el.classList.toggle('input-invalid',!!m);el.classList.toggle('input-valid',!m&&el.value.trim()!=='');if(e)e.textContent=m||'';return !m}
function cleanBlock(v){return String(v||'').trim().replace(/^block\s+/i,'').replace(/^tower\s+/i,'').replace(/[^A-Za-z0-9\-]/g,'').toUpperCase()}
function passwordOk(v){return v.length>=8&&/[A-Z]/.test(v)&&/[a-z]/.test(v)&&/[0-9]/.test(v)&&/[^A-Za-z0-9]/.test(v)}
function rebuildBlocks(){const grid=document.getElementById('blockNameGrid');const old=qa('block_names[]').map(x=>x.value);const n=+q('block_count').value||1;grid.innerHTML='';for(let i=0;i<n;i++){let val=old[i]||DEFAULT_BLOCKS[i]||`B${i+1}`;grid.insertAdjacentHTML('beforeend',`<div class="field block-name-field"><label>Block ${i+1} Name</label><input type="text" name="block_names[]" value="${val}" placeholder="Example: ${DEFAULT_BLOCKS[i]||`B${i+1}`}" required><div class="field-error" data-error-for="block_names_${i}"></div></div>`)}qa('block_names[]').forEach(el=>el.addEventListener('input',validate));validate()}
function validate(){let ok=true;let apt=q('apartment_name').value.trim(),address=q('address_line').value.trim(),pc=q('postcode').value.trim(),city=q('city').value.trim(),state=q('state').value,email=q('contact_email').value.trim(),pass=q('admin_password').value,confirm=q('admin_password_confirm').value,blockCount=+q('block_count').value,blockInputs=qa('block_names[]'),blocks=blockInputs.map(x=>cleanBlock(x.value)),floors=+q('floors_per_block').value,units=+q('units_per_floor').value,rs=+q('resident_parking_slots').value,vs=+q('visitor_parking_slots').value,fee=+q('monthly_fee').value;
ok&=err('apartment_name',/^[A-Za-z0-9 .'&()\-]{3,120}$/.test(apt)?'':'Enter a valid apartment name.');
ok&=err('address_line',address.length>=8&&/[A-Za-z]/.test(address)&&/\d/.test(address)?'':'Address must include building/lot number and road name.');
ok&=err('postcode',/^\d{5}$/.test(pc)?'':'Postcode must be 5 digits.');
ok&=err('city',/^[A-Za-z .'\-]{2,80}$/.test(city)?'':'Enter a valid city/town.');
ok&=err('state',state?'':'Please select state.');if(state&&pc&&MY_POSTCODE_PREFIX[state]){ok&=err('postcode',MY_POSTCODE_PREFIX[state].some(p=>pc.startsWith(p))?'':`Postcode does not look correct for ${state}.`)}
ok&=err('contact_email',/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)?'':'Enter valid admin email.');
ok&=err('admin_password',passwordOk(pass)?'':'Use 8+ chars with uppercase, lowercase, number and symbol.');
ok&=err('admin_password_confirm',pass&&confirm===pass?'':'Password confirmation does not match.');
ok&=err('block_count',blockCount>=1&&blockCount<=12?'':'Select 1-12 blocks.');
let seen=new Set();blocks.forEach((b,i)=>{let m='';if(!b)m='Enter block name.';else if(!/[A-Z]/.test(b))m='Block name must include letters.';else if(seen.has(b))m='Duplicate block name.';seen.add(b);ok&=errBlock(i,m)});
if(blocks.filter(Boolean).length!==blockCount){ok&=err('block_count','Please fill all block names.');}
ok&=err('floors_per_block',floors>=1&&floors<=80?'':'Floors must be 1-80.');ok&=err('units_per_floor',units>=1&&units<=50?'':'Units per floor must be 1-50.');
if(blockCount*floors*units>5000){ok&=err('units_per_floor','Total generated units cannot exceed 5000.');}
ok&=err('resident_parking_slots',rs>=0&&rs<=3000?'':'Resident slots must be 0-3000.');ok&=err('visitor_parking_slots',vs>=0&&vs<=3000?'':'Visitor slots must be 0-3000.');
if(rs+vs<=0){ok&=err('resident_parking_slots','Enter at least one parking slot.');}if(vs>rs&&rs>0){ok&=err('visitor_parking_slots','Visitor parking should not be more than resident parking.');}
ok&=err('monthly_fee',fee>0?'':'Monthly fee must be more than RM 0.');document.getElementById('formAlert').classList.toggle('show',!ok);return !!ok}
document.getElementById('blockCount').addEventListener('change',rebuildBlocks);document.getElementById('applicationForm').addEventListener('submit',e=>{if(!validate()){e.preventDefault();document.getElementById('formAlert').scrollIntoView({behavior:'smooth',block:'center'});}});document.querySelectorAll('input,textarea,select').forEach(el=>el.addEventListener('input',validate));validate();
</script>
</body>
</html>
