<?php
// 文件路径: public/resident_vehicles.php
require_once '../core/security.php';
require_login(['resident', 'admin']); 

$pdo = db();
$resident_id = $_SESSION['uid'];
$resident_email = explode('@', $_SESSION['email'])[0];
$msg = '';

// 1. 处理添加 / 删除车辆的请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("CSRF Token Error");
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $plate_no = strtoupper(trim($_POST['plate_no']));
        $vehicle_model = trim($_POST['vehicle_model']);

        // 检查车牌是否已经被注册过 (防止一车多占)
        $check = $pdo->prepare("SELECT id FROM resident_vehicles WHERE plate_no = ?");
        $check->execute([$plate_no]);
        
        if ($check->fetch()) {
            $msg = "❌ Plate number $plate_no is already registered in the system.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO resident_vehicles (resident_id, plate_no, vehicle_model) VALUES (?, ?, ?)");
            $stmt->execute([$resident_id, $plate_no, $vehicle_model]);
            $msg = "✅ Vehicle $plate_no added to your whitelist successfully!";
            log_audit('RESIDENT_VEHICLE_ADDED', "Resident $resident_id added vehicle $plate_no");
        }
    } elseif ($action === 'delete') {
        $vehicle_id = (int)$_POST['vehicle_id'];
        // 确保只能删除自己的车
        $stmt = $pdo->prepare("DELETE FROM resident_vehicles WHERE id = ? AND resident_id = ?");
        $stmt->execute([$vehicle_id, $resident_id]);
        $msg = "✅ Vehicle removed successfully.";
        log_audit('RESIDENT_VEHICLE_REMOVED', "Resident $resident_id removed vehicle ID $vehicle_id");
    }
}

// 2. 获取当前住户名下的所有车辆
$stmt = $pdo->prepare("SELECT * FROM resident_vehicles WHERE resident_id = ? ORDER BY created_at DESC");
$stmt->execute([$resident_id]);
$vehicles = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Vehicles - <?= APP_NAME ?></title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root { --bg: #f3f4f6; --surface: #ffffff; --primary: #4f46e5; --text-main: #111827; --text-muted: #6b7280; --border: #e5e7eb; --success: #10b981; --danger: #ef4444; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background: var(--bg); color: var(--text-main); }
        
        /* 顶部导航 (增加子菜单) */
        .navbar { background: var(--surface); padding: 15px 5%; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 2px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 100; }
        .logo { font-size: 1.25rem; font-weight: 800; } .logo span { color: var(--primary); }
        
        .nav-links { display: flex; gap: 20px; }
        .nav-link { color: var(--text-muted); text-decoration: none; font-weight: 700; font-size: 0.95rem; padding: 8px 12px; border-radius: 8px; transition: 0.2s; }
        .nav-link:hover { background: #f1f5f9; color: var(--primary); }
        .nav-link.active { background: #e0e7ff; color: var(--primary); }
        
        .user-profile { display: flex; align-items: center; gap: 10px; font-weight: 600; font-size: 0.9rem; }
        .avatar { width: 35px; height: 35px; background: var(--primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        
        .container { max-width: 900px; margin: 30px auto; padding: 0 20px; display: grid; grid-template-columns: 1fr 2fr; gap: 25px; }
        @media (max-width: 768px) { .container { grid-template-columns: 1fr; } }
        
        .page-header { grid-column: 1 / -1; margin-bottom: 10px; }
        .page-title { font-size: 1.5rem; font-weight: 800; }
        .page-sub { color: var(--text-muted); font-size: 0.95rem; margin-top: 5px; }
        
        .card { background: var(--surface); border-radius: 16px; border: 1px solid var(--border); padding: 25px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); align-self: start; }
        .card-title { font-size: 1.1rem; font-weight: 700; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid var(--border); padding-bottom: 15px; }
        
        /* 表单样式 */
        .form-group { margin-bottom: 18px; }
        label { display: block; font-size: 0.85rem; font-weight: 700; color: var(--text-muted); margin-bottom: 8px; }
        input { width: 100%; padding: 12px 15px; border: 1px solid #d1d5db; border-radius: 10px; font-size: 1rem; background: #f9fafb; transition: 0.2s; }
        input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); background: white; }
        .btn-submit { width: 100%; padding: 14px; background: var(--primary); color: white; border: none; border-radius: 10px; font-weight: 700; font-size: 1rem; cursor: pointer; transition: 0.2s; display: flex; justify-content: center; align-items: center; gap: 8px; }
        .btn-submit:hover { background: #4338ca; transform: translateY(-2px); }
        
        /* 车辆列表样式 */
        .vehicle-list { display: flex; flex-direction: column; gap: 15px; }
        .vehicle-item { border: 1px solid var(--border); border-radius: 12px; padding: 20px; display: flex; justify-content: space-between; align-items: center; transition: 0.2s; }
        .vehicle-item:hover { border-color: #cbd5e1; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .v-plate { font-family: monospace; font-weight: 800; font-size: 1.4rem; color: var(--text-main); letter-spacing: 1px; }
        .v-model { color: var(--text-muted); font-size: 0.9rem; font-weight: 600; margin-top: 5px; display: flex; align-items: center; gap: 6px; }
        
        .btn-del { background: #fee2e2; color: var(--danger); border: none; padding: 10px 15px; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.2s; }
        .btn-del:hover { background: #fca5a5; }
        
        .empty-state { text-align: center; padding: 40px 20px; color: var(--text-muted); }
        .empty-state i { font-size: 3rem; color: #cbd5e1; margin-bottom: 15px; }
    </style>
</head>
<body>

    <nav class="navbar">
        <div class="logo">Smart<span>VMS</span></div>
        <div class="nav-links">
            <a href="resident.php" class="nav-link"><i class="fas fa-home"></i> Dashboard</a>
            <a href="resident_vehicles.php" class="nav-link active"><i class="fas fa-car"></i> My Vehicles</a>
        </div>
        <div class="user-profile">
            <span>Unit: <?= htmlspecialchars($resident_email) ?></span>
            <div class="avatar"><?= strtoupper(substr($resident_email, 0, 1)) ?></div>
            <a href="../core/logout.php" style="margin-left:15px; color:var(--danger); text-decoration:none;"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </nav>

    <div class="container">
        <div class="page-header">
            <h1 class="page-title">Vehicle Management</h1>
            <p class="page-sub">Register your personal vehicles for automated gate access.</p>
        </div>

        <div class="card">
            <div class="card-title"><i class="fas fa-plus-circle" style="color: var(--primary);"></i> Register New Vehicle</div>
            <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">
                
                <div class="form-group">
                    <label>Plate Number</label>
                    <input type="text" name="plate_no" placeholder="e.g. JDT9999" style="text-transform: uppercase; font-family: monospace; font-weight: bold;" required>
                </div>
                
                <div class="form-group">
                    <label>Vehicle Model / Color</label>
                    <input type="text" name="vehicle_model" placeholder="e.g. Honda Civic - Black" required>
                </div>
                
                <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Save Vehicle</button>
            </form>
        </div>

        <div class="card">
            <div class="card-title"><i class="fas fa-list" style="color: var(--primary);"></i> My Registered Vehicles</div>
            
            <div class="vehicle-list">
                <?php if(empty($vehicles)): ?>
                    <div class="empty-state">
                        <i class="fas fa-car-crash"></i>
                        <h3>No vehicles found</h3>
                        <p style="font-size: 0.9rem; margin-top: 5px;">Register your vehicle to get seamless gate access.</p>
                    </div>
                <?php else: ?>
                    <?php foreach($vehicles as $v): ?>
                        <div class="vehicle-item">
                            <div>
                                <div class="v-plate"><?= e($v['plate_no']) ?></div>
                                <div class="v-model"><i class="fas fa-car-side"></i> <?= e($v['vehicle_model']) ?></div>
                                <div style="font-size: 0.75rem; color: #9ca3af; margin-top: 8px;">Registered: <?= date('d M Y', strtotime($v['created_at'])) ?></div>
                            </div>
                            
                            <form method="POST" action="" style="margin: 0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="vehicle_id" value="<?= $v['id'] ?>">
                                <button type="button" class="btn-del" onclick="confirmDelete(this.form, '<?= e($v['plate_no']) ?>')">
                                    <i class="fas fa-trash-alt"></i> Remove
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // 优雅的消息提示
        <?php if($msg): ?>
            Swal.fire({
                icon: '<?= strpos($msg, '✅') !== false ? 'success' : 'error' ?>',
                title: '<?= strpos($msg, '✅') !== false ? 'Success' : 'Notice' ?>',
                text: '<?= str_replace(['✅ ', '❌ '], '', $msg) ?>',
                timer: 2500,
                showConfirmButton: false
            });
        <?php endif; ?>

        // 删除确认防误触
        function confirmDelete(form, plateNo) {
            Swal.fire({
                title: 'Remove Vehicle?',
                text: `Are you sure you want to remove ${plateNo} from your whitelist? It will no longer have auto-access.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, Remove it'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        }
    </script>
</body>
</html>