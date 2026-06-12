<?php
// File: public/free_trial.php
require_once __DIR__ . '/../core/security.php';
$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function table_exists_trial(PDO $pdo, string $table): bool { $stmt=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $stmt->execute([$table]); return (int)$stmt->fetchColumn()>0; }
function col_exists_trial(PDO $pdo, string $table, string $col): bool { $stmt=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?"); $stmt->execute([$table,$col]); return (int)$stmt->fetchColumn()>0; }
function app_ref_trial(): string { return 'APP-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)),0,6)); }
function normalize_blocks_trial($raw): array {
    $parts = is_array($raw) ? $raw : preg_split('/[,\n]+/', (string)$raw);
    $out=[]; foreach($parts as $p){ $b=strtoupper(trim((string)$p)); $b=preg_replace('/^BLOCK\s+/i','',$b); $b=preg_replace('/^TOWER\s+/i','',$b); $b=preg_replace('/[^A-Z0-9\-]/','',$b); if($b!=='' && preg_match('/[A-Z]/',$b) && !in_array($b,$out,true)) $out[]=$b; }
    return $out;
}
function valid_pw_trial(string $p): bool { return strlen($p)>=8 && preg_match('/[A-Z]/',$p) && preg_match('/[a-z]/',$p) && preg_match('/[0-9]/',$p) && preg_match('/[^A-Za-z0-9]/',$p); }
$states=['Johor','Kedah','Kelantan','Melaka','Negeri Sembilan','Pahang','Penang','Perak','Perlis','Sabah','Sarawak','Selangor','Terengganu','Kuala Lumpur','Putrajaya','Labuan'];
$interests=['Visitor Booking','QR Gate Verification','License Plate Recognition','Resident Parking Subscription','Visitor Parking Queue','Multi-apartment Admin'];
$msg=''; $type=''; $submittedRef='';
$ready = table_exists_trial($pdo,'apartment_applications') && col_exists_trial($pdo,'apartment_applications','admin_password_hash') && col_exists_trial($pdo,'apartment_applications','block_count');
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) die('CSRF Token Validation Failed.');
    try{
        if(!$ready) throw new Exception('Please run smartvms_marketing_pages_setup.sql first.');
        $contactName=trim($_POST['contact_name'] ?? '');
        $email=strtolower(trim($_POST['contact_email'] ?? ''));
        $phone=trim($_POST['contact_phone'] ?? '');
        $apartment=trim($_POST['apartment_name'] ?? '');
        $address=trim($_POST['address_line'] ?? '');
        $postcode=trim($_POST['postcode'] ?? '');
        $city=trim($_POST['city'] ?? '');
        $state=trim($_POST['state'] ?? '');
        $password=(string)($_POST['admin_password'] ?? '');
        $confirm=(string)($_POST['admin_password_confirm'] ?? '');
        $propertyType='Apartment / Condominium';
        $blockCount=(int)($_POST['block_count'] ?? 0);
        $blocks=normalize_blocks_trial($_POST['block_names'] ?? []);
        $floors=(int)($_POST['floors_per_block'] ?? 0);
        $units=(int)($_POST['units_per_floor'] ?? 0);
        $resident=(int)($_POST['resident_parking_slots'] ?? 0);
        $visitor=(int)($_POST['visitor_parking_slots'] ?? 0);
        $fee=(float)($_POST['monthly_fee'] ?? 300);
        $interestList = isset($_POST['interests']) && is_array($_POST['interests']) ? array_values(array_intersect($interests, $_POST['interests'])) : [];
        if(!preg_match('/^[A-Za-z .\'-]{2,120}$/',$contactName)) throw new Exception('Please enter a valid name.');
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new Exception('Please enter a valid email.');
        if(!preg_match('/^(\+?6?01)[0-9\- ]{7,12}$/',$phone)) throw new Exception('Please enter a valid Malaysia phone number.');
        if(!valid_pw_trial($password)) throw new Exception('Password must be at least 8 characters and include uppercase, lowercase, number and symbol.');
        if($password!==$confirm) throw new Exception('Confirm password does not match.');
        if($apartment==='' || strlen($apartment)<3) throw new Exception('Please enter your apartment name.');
        if($address==='' || strlen($address)<8 || !preg_match('/\d/',$address) || !preg_match('/[A-Za-z]/',$address)) throw new Exception('Please enter a complete address with building/lot number and road name.');
        if(!preg_match('/^\d{5}$/',$postcode)) throw new Exception('Please enter a valid 5-digit postcode.');
        if($city==='' || !preg_match('/^[A-Za-z .\'-]{2,80}$/',$city)) throw new Exception('Please enter a valid city/town.');
        if(!in_array($state,$states,true)) throw new Exception('Please select a valid Malaysian state.');
        if(empty($interestList)) throw new Exception('Please select at least one interested module.');
        if($blockCount<1 || $blockCount>12) throw new Exception('Number of blocks must be between 1 and 12.');
        if(count($blocks)!==$blockCount) throw new Exception('Please enter a valid name for every block.');
        if($floors<1 || $floors>80 || $units<1 || $units>50) throw new Exception('Floor/unit values are not valid.');
        if(($blockCount*$floors*$units)>5000) throw new Exception('Too many units for demo setup.');
        if($resident<0 || $visitor<0 || ($resident+$visitor)<=0) throw new Exception('Please enter valid parking slots.');
        if($visitor>$resident && $resident>0) throw new Exception('Visitor parking should not be more than resident parking for normal apartment setup.');
        if($fee<=0) throw new Exception('Monthly fee must be more than RM 0.');
        $dup=$pdo->prepare("SELECT COUNT(*) FROM users WHERE email=?"); $dup->execute([$email]); if((int)$dup->fetchColumn()>0) throw new Exception('This email already exists as a user account.');
        $dup=$pdo->prepare("SELECT COUNT(*) FROM apartment_applications WHERE contact_email=? AND status='pending'"); $dup->execute([$email]); if((int)$dup->fetchColumn()>0) throw new Exception('A pending first-month-free application already exists for this email.');
        $ref=app_ref_trial();
        $notes = 'Property Type: '.$propertyType."\nInterested modules: ".implode(', ', $interestList);
        $stmt=$pdo->prepare("INSERT INTO apartment_applications
            (application_ref, apartment_name, address_line, postcode, city, state, contact_name, contact_email, admin_password_hash, block_count, contact_phone, block_names, floors_per_block, units_per_floor, resident_parking_slots, visitor_parking_slots, monthly_fee, preferred_start_date, notes, status, ip_address, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, 'pending', ?, NOW())");
        $stmt->execute([$ref,$apartment,$address,$postcode,$city,$state,$contactName,$email,password_hash($password,PASSWORD_DEFAULT),$blockCount,$phone,implode(',',$blocks),$floors,$units,$resident,$visitor,$fee,$notes,$_SERVER['REMOTE_ADDR'] ?? null]);
        $_POST=[]; $type='success'; $submittedRef=$ref; $msg='Free trial application submitted. Our superadmin will review and approve your apartment account.';
    }catch(Throwable $e){$type='error';$msg=$e->getMessage();}
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>SmartVMS | First Month Free</title><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"><style>
:root{--green:#dc2626;--dark:#7f1d1d;--navy:#071638;--muted:#747b95;--line:#e8edf5;--bg:#fbfcfe;--red:#dc2626}*{box-sizing:border-box}body{margin:0;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;background:var(--bg);color:var(--navy)}a{text-decoration:none;color:inherit}.nav{height:86px;border-bottom:1px solid var(--line);background:white;display:flex;align-items:center;justify-content:center}.nav-inner{width:min(1240px,calc(100% - 42px));display:flex;align-items:center;gap:34px}.brand{font-size:1.55rem;font-weight:950;letter-spacing:-.06em;margin-right:auto}.brand b{color:var(--green)}.brand i{color:var(--green);margin-right:9px}.menu{display:flex;gap:28px;color:#68708b;font-weight:850;font-size:.88rem}.nav-btn{padding:13px 22px;border-radius:8px;font-weight:950}.nav-btn.green{background:var(--dark);color:white}.nav-btn.light{background:#f6f7fb}.wrap{width:min(820px,calc(100% - 38px));margin:0 auto 80px;padding-top:70px}.title{text-align:center;margin-bottom:34px}.title h1{font-size:2.2rem;margin:0 0 8px;letter-spacing:-.05em}.title p{margin:0;font-weight:950}.card{background:white;border:1px solid var(--line);border-radius:24px;box-shadow:0 22px 60px rgba(7,22,56,.07);padding:30px}.section-title{font-weight:950;margin:26px 0 16px;padding-top:22px;border-top:1px solid var(--line);display:flex;gap:9px;align-items:center}.section-title:first-of-type{margin-top:0;padding-top:0;border-top:0}.section-title i{color:var(--green)}.field{margin-bottom:18px}.label{display:block;font-size:.78rem;font-weight:950;margin-bottom:8px}.req{color:var(--red)}input,select,textarea{width:100%;height:52px;border:1px solid transparent;background:#f7f8fb;border-radius:9px;padding:0 16px;font-weight:850;color:var(--navy);outline:none}textarea{height:96px;padding-top:14px;resize:vertical}input:focus,select:focus,textarea:focus{border-color:var(--green);background:white;box-shadow:0 0 0 4px rgba(220,38,38,.13)}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.checks{display:grid;gap:10px}.check{display:flex;align-items:center;gap:10px;font-size:.86rem;font-weight:850;color:#303a56}.check input{width:18px;height:18px}.block-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}.submit{background:var(--dark);color:white;border:0;border-radius:9px;padding:16px 28px;font-weight:950;cursor:pointer;margin-top:12px}.hint{color:var(--muted);font-size:.78rem;font-weight:800;margin-top:7px}.alert{padding:14px 16px;border-radius:14px;margin-bottom:18px;font-weight:900}.alert.success{background:#fee2e2;color:#991b1b}.alert.error{background:#fee2e2;color:#991b1b}@media(max-width:720px){.menu{display:none}.grid,.block-grid{grid-template-columns:1fr}.wrap{padding-top:38px}.card{padding:22px}.nav-inner{gap:12px}}
</style></head><body>
<header class="nav"><div class="nav-inner"><a href="admin_landing.php" class="brand"><i class="fa-solid fa-shield-halved"></i>Smart<b>VMS</b></a><div class="menu"><a href="admin_landing.php#features">Products</a><a href="product_demo.php">Product Demo</a><a href="admin_landing.php#features">Pricing</a></div><a class="nav-btn green" href="product_demo.php">Join Demo</a><a class="nav-btn light" href="login.php">Login</a></div></header>
<main class="wrap"><div class="title"><h1>Get Your First Month Free</h1><p>First month free. No credit card required. ✌️</p></div>
<form class="card" method="post" id="trialForm" novalidate><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><?php if($msg): ?><div class="alert <?= h($type) ?>"><?= h($msg) ?><?php if($submittedRef): ?> Reference: <?= h($submittedRef) ?><?php endif; ?></div><?php endif; ?>
<div class="section-title"><i class="fa-solid fa-user"></i> Contact & Admin Login</div>
<div class="field"><label class="label">Name <span class="req">*</span></label><input name="contact_name" value="<?= h($_POST['contact_name'] ?? '') ?>" required></div>
<div class="field"><label class="label">Admin Login Email <span class="req">*</span></label><input type="email" name="contact_email" placeholder="Enter apartment admin login email" value="<?= h($_POST['contact_email'] ?? '') ?>" required><div class="hint">This email will be used by the apartment admin to log in after approval.</div></div>
<div class="field"><label class="label">Mobile phone number <span class="req">*</span></label><input name="contact_phone" placeholder="Please start with Malaysia code e.g. +60 / 011-12345678" value="<?= h($_POST['contact_phone'] ?? '') ?>" required></div>
<div class="grid"><div class="field"><label class="label">Admin Password <span class="req">*</span></label><input type="password" name="admin_password" placeholder="At least 8 characters" required><div class="hint">Must include uppercase, lowercase, number and symbol.</div></div><div class="field"><label class="label">Confirm Password <span class="req">*</span></label><input type="password" name="admin_password_confirm" required></div></div>
<div class="section-title"><i class="fa-solid fa-list-check"></i> Interested in?</div><div class="checks"><?php foreach($interests as $i): ?><label class="check"><input type="checkbox" name="interests[]" value="<?= h($i) ?>"> <?= h($i) ?></label><?php endforeach; ?></div>
<div class="section-title"><i class="fa-solid fa-building"></i> Apartment Information</div>
<input type="hidden" name="property_type" value="Apartment / Condominium">
<div class="field"><label class="label">Name of Your Apartment <span class="req">*</span></label><input name="apartment_name" placeholder="e.g. Happy Residence" value="<?= h($_POST['apartment_name'] ?? '') ?>" required></div>
<div class="field"><label class="label">Address Line <span class="req">*</span></label><textarea name="address_line" placeholder="No 12, Jalan Example, Taman Example"><?= h($_POST['address_line'] ?? '') ?></textarea></div>
<div class="grid"><div class="field"><label class="label">Postcode <span class="req">*</span></label><input name="postcode" maxlength="5" value="<?= h($_POST['postcode'] ?? '') ?>" required></div><div class="field"><label class="label">City / Town <span class="req">*</span></label><input name="city" value="<?= h($_POST['city'] ?? '') ?>" required></div></div>
<div class="field"><label class="label">State <span class="req">*</span></label><select name="state" required><option value="">Please Select</option><?php foreach($states as $s): ?><option value="<?= h($s) ?>"><?= h($s) ?></option><?php endforeach; ?></select></div>
<div class="section-title"><i class="fa-solid fa-layer-group"></i> Building / Unit Setup</div>
<div class="field"><label class="label">How many blocks / towers? <span class="req">*</span></label><select name="block_count" id="blockCount" required><option value="">Please Select</option><?php for($i=1;$i<=12;$i++): ?><option value="<?= $i ?>"><?= $i ?> block<?= $i>1?'s':'' ?></option><?php endfor; ?></select><div class="hint">After selecting, block name fields will appear below.</div></div>
<div class="block-grid" id="blockNames"></div>
<div class="grid"><div class="field"><label class="label">Floors Per Block <span class="req">*</span></label><input type="number" name="floors_per_block" min="1" max="80" value="<?= h($_POST['floors_per_block'] ?? '5') ?>"></div><div class="field"><label class="label">Units Per Floor <span class="req">*</span></label><input type="number" name="units_per_floor" min="1" max="50" value="<?= h($_POST['units_per_floor'] ?? '4') ?>"></div></div>
<div class="section-title"><i class="fa-solid fa-square-parking"></i> Parking Setup</div>
<div class="grid"><div class="field"><label class="label">Resident Parking Slots <span class="req">*</span></label><input type="number" name="resident_parking_slots" min="0" max="3000" value="<?= h($_POST['resident_parking_slots'] ?? '40') ?>"></div><div class="field"><label class="label">Visitor Parking Slots <span class="req">*</span></label><input type="number" name="visitor_parking_slots" min="0" max="3000" value="<?= h($_POST['visitor_parking_slots'] ?? '10') ?>"></div></div>
<div class="field"><label class="label">Monthly System Fee (RM)</label><input type="number" name="monthly_fee" min="1" step="0.01" value="<?= h($_POST['monthly_fee'] ?? '300.00') ?>"><small style="display:block;margin-top:8px;color:#747b95;font-weight:850;">The first month starts from the day your application is approved by the superadmin.</small></div>
<button class="submit" type="submit">Submit First Month Free</button>
</form></main>
<script>
const blockCount=document.getElementById('blockCount'); const blockNames=document.getElementById('blockNames');
function renderBlocks(){ const n=parseInt(blockCount.value||0,10); blockNames.innerHTML=''; for(let i=1;i<=n;i++){ const wrap=document.createElement('div'); wrap.className='field'; const suggested=String.fromCharCode(64+i); wrap.innerHTML=`<label class="label">Block ${i} Name <span class="req">*</span></label><input name="block_names[]" placeholder="Example: ${suggested}" value="${suggested}" required>`; blockNames.appendChild(wrap);} }
blockCount.addEventListener('change',renderBlocks);
document.getElementById('trialForm').addEventListener('submit',function(e){
 const phone=this.contact_phone.value.trim(); if(!/^(\+?6?01)[0-9\- ]{7,12}$/.test(phone)){e.preventDefault();alert('Please enter a valid Malaysia phone number.');this.contact_phone.focus();return;}
 if(this.querySelectorAll('input[name="interests[]"]:checked').length===0){e.preventDefault();alert('Please select at least one interested module.');return;}
 if(this.admin_password.value!==this.admin_password_confirm.value){e.preventDefault();alert('Confirm password does not match.');this.admin_password_confirm.focus();}
});
</script></body></html>
