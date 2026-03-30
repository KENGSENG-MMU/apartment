<?php
// 文件路径: public/admin_slots.php
require_once '../core/security.php';

// 【安全防御】只允许 Admin 和 SuperAdmin 访问
require_login(['admin', 'superadmin']); 

$pdo = db();
$admin_name = explode('@', $_SESSION['email'])[0];
$msg = '';

// 1. 处理表单提交 (添加新车位 / 修改状态)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("CSRF Token Error.");
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_slot') {
        // 添加新车位
        $block = $_POST['block_name'];
        $slot_no = strtoupper(trim($_POST['slot_no']));
        $type = $_POST['slot_type'];

        try {
            $stmt = $pdo->prepare("INSERT INTO parking_slots (block_name, slot_no, slot_type, status) VALUES (?, ?, ?, 'available')");
            $stmt->execute([$block, $slot_no, $type]);
            $msg = "✅ Parking slot $block-$slot_no added successfully!";
            log_audit('SLOT_ADDED', "Admin added new slot: $block-$slot_no ($type)");
        } catch (PDOException $e) {
            $msg = "❌ Error: Slot number might already exist.";
        }
    } elseif ($action === 'update_status') {
        // 更新车位状态 (例如设为维修中)
        $slot_id = (int)$_POST['slot_id'];
        $new_status = $_POST['new_status'];
        
        $pdo->prepare("UPDATE parking_slots SET status = ? WHERE id = ?")->execute([$new_status, $slot_id]);
        $msg = "✅ Slot status updated to " . strtoupper($new_status) . "!";
        log_audit('SLOT_UPDATED', "Admin updated slot ID $slot_id to $new_status");
    }
}

// 2. 获取所有的停车位数据
$stmt = $pdo->query("SELECT * FROM parking_slots ORDER BY block_name ASC, slot_no ASC");
$slots = $stmt->fetchAll();

// 3. 统计各 Block 的车位情况
$stats = $pdo->query("SELECT block_name, COUNT(*) as total, SUM(CASE WHEN status='available' THEN 1 ELSE 0 END) as available FROM parking_slots GROUP BY block_name")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parking Management - <?= APP_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        :root { --bg: #f8fafc; --surface: #ffffff; --primary: #0f172a; --accent: #4f46e5; --text-main: #1e293b; --text-muted: #64748b; --border: #e2e8f0; --success: #10b981; --warning: #f59e0b; --danger: #ef4444; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background: var(--bg); color: var(--text-main); display: flex; min-height: 100vh; }
        
        .sidebar { width: 260px; background: var(--primary); color: white; display: flex; flex-direction: column; padding: 20px 0; }
        .brand { font-size: 1.5rem; font-weight: 800; padding: 0 25px 30px; border-bottom: 1px solid #1e293b; margin-bottom: 20px; }
        .brand span { color: var(--accent); }
        .nav-link { display: flex; align-items: center; gap: 15px; padding: 15px 25px; color: #94a3b8; text-decoration: none; font-weight: 600; transition: 0.2s; }
        .nav-link:hover, .nav-link.active { background: #1e293b; color: white; border-right: 4px solid var(--accent); }
        .nav-link i { font-size: 1.2rem; width: 25px; }
        .spacer { flex: 1; }
        
        .main-content { flex: 1; padding: 30px 40px; overflow-y: auto; }
        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .page-title { font-size: 1.8rem; font-weight: 800; }
        .page-sub { color: var(--text-muted); font-size: 0.95rem; margin-top: 5px; }
        
        .dashboard-grid { display: grid; grid-template-columns: 1fr 2fr; gap: 20px; }
        .panel-card { background: var(--surface); border-radius: 16px; border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); padding: 25px; }
        .panel-title { font-size: 1.1rem; font-weight: 700; border-bottom: 1px solid var(--border); padding-bottom: 15px; margin-bottom: 20px; }
        
        /* 表单样式 */
        .form-group { margin-bottom: 15px; }
        label { display: block; font-size: 0.85rem; font-weight: 700; color: var(--text-muted); margin-bottom: 8px; }
        input, select { width: 100%; padding: 10px 15px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.95rem; background: #f8fafc; }
        input:focus, select:focus { outline: none; border-color: var(--accent); background: white; }
        .btn-add { width: 100%; padding: 12px; background: var(--accent); color: white; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.2s; margin-top: 10px; }
        .btn-add:hover { background: #4338ca; }
        
        /* 表格样式 */
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px 15px; font-size: 0.8rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid var(--border); }
        td { padding: 12px 15px; font-size: 0.95rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        
        .slot-badge { font-family: monospace; font-weight: bold; font-size: 1rem; color: #334155; }
        .type-badge { padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; background: #f1f5f9; color: #475569; }
        
        .st-available { color: var(--success); font-weight: 700; }
        .st-reserved { color: var(--accent); font-weight: 700; }
        .st-maintenance { color: var(--danger); font-weight: 700; }
        
        .action-select { padding: 6px; border-radius: 6px; font-size: 0.85rem; font-weight: 600; border: 1px solid #cbd5e1; cursor: pointer; }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="brand">Smart<span>VMS</span></div>
        <a href="admin_dash.php" class="nav-link"><i class="fas fa-chart-pie"></i> Dashboard</a>
        <a href="admin_bookings.php" class="nav-link"><i class="fas fa-calendar-alt"></i> All Bookings</a>
        <a href="admin_slots.php" class="nav-link"><i class="fas fa-parking"></i> Parking Slots</a>
        
        <a href="admin_users.php" class="nav-link"><i class="fas fa-users"></i> Manage Users</a> 
        
        <a href="admin_blacklist.php" class="nav-link"><i class="fas fa-ban"></i> Blacklist</a>
        <a href="admin_audit.php" class="nav-link"><i class="fas fa-file-shield"></i> Audit Logs</a>
        <?php if($_SESSION['role'] === 'superadmin'): ?>
            <a href="superadmin_config.php" class="nav-link"><i class="fas fa-cog"></i> System Config</a>
        <?php endif; ?>
        
        <div class="spacer"></div>
        <a href="../core/logout.php" class="nav-link" style="color: #ef4444;"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <div class="main-content">
        <div class="header-flex">
            <div>
                <h1 class="page-title">Parking Resource Center</h1>
                <p class="page-sub">Manage physical parking bays and maintenance states.</p>
            </div>
            <div style="text-align: right;">
                <p style="font-weight: 600; font-size: 0.9rem;">Admin: <?= htmlspecialchars(ucfirst($admin_name)) ?></p>
            </div>
        </div>

        <div class="dashboard-grid">
            <div class="panel-card" style="align-self: start;">
                <div class="panel-title"><i class="fas fa-plus-square"></i> Add New Slot</div>
                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_slot">
                    
                    <div class="form-group">
                        <label>Block / Zone</label>
                        <select name="block_name" required>
                            <option value="Block A">Block A</option>
                            <option value="Block B">Block B</option>
                            <option value="Block C">Block C</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Slot Number</label>
                        <input type="text" name="slot_no" placeholder="e.g. VA-02" required style="font-family: monospace; font-weight: bold; text-transform: uppercase;">
                    </div>
                    
                    <div class="form-group">
                        <label>Allocation Type</label>
                        <select name="slot_type" required>
                            <option value="Visitor">Visitor Bay (Auto-assign)</option>
                            <option value="Resident">Resident Bay (Private)</option>
                            <option value="Disabled">OKU / Disabled</option>
                            <option value="Loading">Loading Zone</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn-add"><i class="fas fa-save"></i> Create Slot</button>
                </form>
                
                <div style="margin-top: 30px;">
                    <h4 style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 10px; text-transform: uppercase;">Zone Overview</h4>
                    <?php foreach($stats as $s): ?>
                        <div style="display: flex; justify-content: space-between; font-size: 0.9rem; font-weight: 600; border-bottom: 1px solid #f1f5f9; padding: 8px 0;">
                            <span><?= e($s['block_name']) ?></span>
                            <span style="color: var(--success);"><?= $s['available'] ?> / <?= $s['total'] ?> Free</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="panel-card">
                <div class="panel-title"><i class="fas fa-layer-group"></i> Manage Existing Bays</div>
                <div style="overflow-x: auto; max-height: 600px;">
                    <table>
                        <thead>
                            <tr>
                                <th>Location</th>
                                <th>Slot ID</th>
                                <th>Type</th>
                                <th>Live Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($slots)): ?>
                                <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;">No parking slots found in database.</td></tr>
                            <?php else: ?>
                                <?php foreach($slots as $slot): ?>
                                <tr>
                                    <td style="font-weight: 600; color: var(--text-muted);"><?= e($slot['block_name']) ?></td>
                                    <td><span class="slot-badge"><?= e($slot['slot_no']) ?></span></td>
                                    <td><span class="type-badge"><?= e($slot['slot_type']) ?></span></td>
                                    <td>
                                        <span class="st-<?= strtolower($slot['status']) ?>">
                                            <i class="fas <?= $slot['status']=='available'?'fa-check-circle':($slot['status']=='reserved'?'fa-car':'fa-tools') ?>"></i> 
                                            <?= ucfirst($slot['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="POST" action="" style="margin: 0;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="slot_id" value="<?= $slot['id'] ?>">
                                            <select name="new_status" class="action-select" onchange="this.form.submit()" <?= $slot['status'] == 'reserved' ? 'disabled title="Cannot edit while in use"' : '' ?>>
                                                <option value="available" <?= $slot['status'] == 'available' ? 'selected' : '' ?>>Set Available</option>
                                                <option value="maintenance" <?= $slot['status'] == 'maintenance' ? 'selected' : '' ?>>Set Maintenance</option>
                                            </select>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        <?php if($msg): ?>
            Swal.fire({
                icon: '<?= strpos($msg, '✅') !== false ? 'success' : 'error' ?>',
                title: '<?= strpos($msg, '✅') !== false ? 'Success' : 'Error' ?>',
                text: '<?= str_replace(['✅ ', '❌ '], '', $msg) ?>',
                timer: 2000,
                showConfirmButton: false
            });
        <?php endif; ?>
    </script>
</body>
</html>