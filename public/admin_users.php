<?php
// 文件路径: public/admin_users.php
require_once '../core/security.php';
require_login(['admin', 'superadmin']); 

$pdo = db();
$admin_name = explode('@', $_SESSION['email'])[0];
$msg = '';

// 1. 处理创建新账号的请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("CSRF Token Error.");
    }

    $email = trim($_POST['email']);
    $role = $_POST['role'];
    $phone = trim($_POST['phone']);
    $password = $_POST['password'];

    // 检查邮箱是否重复
    $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $check->execute([$email]);
    
    if ($check->fetch()) {
        $msg = "❌ Error: Email already exists in the system.";
    } else {
        // 加密密码并插入新用户
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, role, phone, status) VALUES (?, ?, ?, ?, 'active')");
        if($stmt->execute([$email, $hashed, $role, $phone])) {
            $msg = "✅ New $role account created successfully!";
            log_audit('USER_CREATED', "Admin created new $role: $email");
        }
    }
}

// 2. 获取所有的员工和住户 (排除访客，因为访客自己注册)
$stmt = $pdo->query("SELECT * FROM users WHERE role IN ('guard', 'resident', 'admin', 'superadmin') ORDER BY role, id DESC");
$staff_users = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - <?= APP_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        /* 保持后台统一样式 */
        :root { --bg: #f8fafc; --surface: #ffffff; --primary: #0f172a; --accent: #4f46e5; --text-main: #1e293b; --text-muted: #64748b; --border: #e2e8f0; --success: #10b981; --danger: #ef4444; }
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
        
        .dashboard-grid { display: grid; grid-template-columns: 1fr 2fr; gap: 20px; }
        .panel-card { background: var(--surface); border-radius: 16px; border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); padding: 25px; align-self: start; }
        .panel-title { font-size: 1.1rem; font-weight: 700; border-bottom: 1px solid var(--border); padding-bottom: 15px; margin-bottom: 20px; }
        
        /* 表单样式 */
        .form-group { margin-bottom: 15px; }
        label { display: block; font-size: 0.85rem; font-weight: 700; color: var(--text-muted); margin-bottom: 8px; }
        input, select { width: 100%; padding: 10px 15px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.95rem; background: #f8fafc; }
        input:focus, select:focus { outline: none; border-color: var(--accent); background: white; }
        .btn-add { width: 100%; padding: 12px; background: var(--accent); color: white; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.2s; margin-top: 10px; }
        .btn-add:hover { background: #4338ca; }
        
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px 15px; font-size: 0.8rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid var(--border); }
        td { padding: 12px 15px; font-size: 0.95rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        
        .role-badge { padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; }
        .r-superadmin, .r-admin { background: #fee2e2; color: #991b1b; }
        .r-guard { background: #e0f2fe; color: #0369a1; }
        .r-resident { background: #e0e7ff; color: #3730a3; }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="brand">Smart<span>VMS</span></div>
        <a href="admin_dash.php" class="nav-link"><i class="fas fa-chart-pie"></i> Dashboard</a>
        <a href="admin_bookings.php" class="nav-link"><i class="fas fa-calendar-alt"></i> All Bookings</a>
        <a href="admin_slots.php" class="nav-link"><i class="fas fa-parking"></i> Parking Slots</a>
        <a href="admin_users.php" class="nav-link active"><i class="fas fa-users"></i> Manage Users</a>
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
                <h1 class="page-title">User & Staff Management</h1>
                <p style="color: var(--text-muted); font-size: 0.95rem;">Create and manage internal accounts (Guards & Residents).</p>
            </div>
        </div>

        <div class="dashboard-grid">
            <div class="panel-card">
                <div class="panel-title"><i class="fas fa-user-plus"></i> Create New Account</div>
                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label>Email / Username</label>
                        <input type="email" name="email" placeholder="e.g. guard_b@apt.com" required>
                    </div>
                    <div class="form-group">
                        <label>Account Role</label>
                        <select name="role" required>
                            <option value="resident">Resident (Tenant / Owner)</option>
                            <option value="guard">Security Guard</option>
                            <?php if($_SESSION['role'] === 'superadmin'): ?>
                                <option value="admin">System Admin</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" name="phone" placeholder="e.g. 0123456789" required>
                    </div>
                    <div class="form-group">
                        <label>Initial Password</label>
                        <input type="password" name="password" placeholder="Min 6 characters" required minlength="6">
                    </div>
                    <button type="submit" class="btn-add"><i class="fas fa-save"></i> Create Account</button>
                </form>
            </div>

            <div class="panel-card" style="overflow-x: auto; max-height: 700px;">
                <div class="panel-title"><i class="fas fa-users-cog"></i> Internal System Users</div>
                <table>
                    <thead>
                        <tr>
                            <th>Email / ID</th>
                            <th>Role</th>
                            <th>Phone</th>
                            <th>Created On</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($staff_users as $u): ?>
                        <tr>
                            <td style="font-weight: 600; color: #334155;"><?= e($u['email']) ?></td>
                            <td><span class="role-badge r-<?= strtolower($u['role']) ?>"><?= e($u['role']) ?></span></td>
                            <td style="font-family: monospace; color: var(--text-muted);"><?= mask_phone($u['phone']) ?></td>
                            <td style="font-size: 0.85rem; color: var(--text-muted);"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        <?php if($msg): ?>
            Swal.fire({
                icon: '<?= strpos($msg, '✅') !== false ? 'success' : 'error' ?>',
                title: '<?= strpos($msg, '✅') !== false ? 'Success' : 'Notice' ?>',
                text: '<?= str_replace(['✅ ', '❌ '], '', $msg) ?>',
                timer: 3000,
                showConfirmButton: false
            });
        <?php endif; ?>
    </script>
</body>
</html>