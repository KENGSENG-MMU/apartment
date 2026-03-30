<?php
// 文件路径: public/admin_blacklist.php
require_once '../core/security.php';

// 【安全防御】只允许 Admin 和 SuperAdmin 访问
require_login(['admin', 'superadmin']); 

$pdo = db();
$admin_name = explode('@', $_SESSION['email'])[0];
$msg = '';

// 1. 处理封禁/解封的 POST 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("CSRF Token Error.");
    }

    $target_id = (int)$_POST['user_id'];
    $action = $_POST['action']; // 'ban' 或 'unban'
    
    // 防止 Admin 封禁自己或 SuperAdmin
    $checkStmt = $pdo->prepare("SELECT role, email FROM users WHERE id = ?");
    $checkStmt->execute([$target_id]);
    $target_user = $checkStmt->fetch();

    if ($target_user && in_array($target_user['role'], ['admin', 'superadmin'])) {
        $msg = "❌ Error: Cannot change status of Admin accounts.";
    } else {
        $new_status = ($action === 'ban') ? 'blacklisted' : 'active';
        $pdo->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$new_status, $target_id]);
        
        // 写入最高级别审计日志
        $log_action = ($action === 'ban') ? 'USER_BLACKLISTED' : 'USER_RESTORED';
        log_audit($log_action, "Admin changed status of {$target_user['email']} to $new_status");
        
        $msg = "✅ Account status updated successfully!";
    }
}

// 2. 获取所有访客和住户的名单
$stmt = $pdo->query("SELECT id, email, role, phone, status, created_at FROM users WHERE role IN ('visitor', 'resident') ORDER BY role, id DESC");
$users_list = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blacklist Management - <?= APP_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        /* 保持 Admin 控制台统一的高级风格 */
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
        .page-sub { color: var(--text-muted); font-size: 0.95rem; margin-top: 5px; }
        
        .table-card { background: var(--surface); border-radius: 16px; border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); overflow: hidden; }
        .table-header { padding: 20px 25px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .table-title { font-size: 1.1rem; font-weight: 700; color: var(--danger); display: flex; align-items: center; gap: 10px; }
        
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px 25px; font-size: 0.8rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid var(--border); background: #f8fafc; }
        td { padding: 15px 25px; font-size: 0.95rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover { background: #f8fafc; }
        
        .role-badge { padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .role-resident { background: #e0e7ff; color: #3730a3; }
        .role-visitor { background: #f1f5f9; color: #475569; }
        
        .status-active { color: var(--success); font-weight: 700; display: flex; align-items: center; gap: 6px; }
        .status-banned { color: var(--danger); font-weight: 700; display: flex; align-items: center; gap: 6px; }
        
        .btn-action { padding: 8px 15px; border-radius: 8px; font-weight: 600; font-size: 0.85rem; cursor: pointer; border: none; display: flex; align-items: center; gap: 6px; transition: 0.2s; }
        .btn-ban { background: #fee2e2; color: #b91c1c; }
        .btn-ban:hover { background: #fca5a5; }
        .btn-unban { background: #dcfce7; color: #15803d; }
        .btn-unban:hover { background: #86efac; }
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
                <h1 class="page-title">Risk Control & Blacklist</h1>
                <p class="page-sub">Manage user access and block unauthorized entities.</p>
            </div>
            <div style="text-align: right;">
                <p style="font-weight: 600; font-size: 0.9rem;">Admin: <?= htmlspecialchars(ucfirst($admin_name)) ?></p>
                <p style="color: var(--text-muted); font-size: 0.85rem;"><?= date('l, d M Y') ?></p>
            </div>
        </div>

        <div class="table-card">
            <div class="table-header">
                <div class="table-title"><i class="fas fa-shield-virus"></i> Account Access Control</div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Account / Email</th>
                        <th>Role</th>
                        <th>Contact (Masked)</th>
                        <th>Current Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($users_list as $u): ?>
                    <tr>
                        <td style="font-weight: 600;"><?= e($u['email']) ?></td>
                        <td><span class="role-badge role-<?= strtolower($u['role']) ?>"><?= e($u['role']) ?></span></td>
                        <td style="font-family: monospace; color: var(--text-muted);"><?= mask_phone($u['phone']) ?></td>
                        <td>
                            <?php if($u['status'] === 'active'): ?>
                                <span class="status-active"><i class="fas fa-check-circle"></i> Active</span>
                            <?php else: ?>
                                <span class="status-banned"><i class="fas fa-times-circle"></i> Blacklisted</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" action="" style="margin:0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                
                                <?php if($u['status'] === 'active'): ?>
                                    <button type="button" class="btn-action btn-ban" onclick="confirmAction(this.form, 'ban', '<?= e($u['email']) ?>')">
                                        <i class="fas fa-gavel"></i> Restrict Access
                                    </button>
                                    <input type="hidden" name="action" value="ban">
                                <?php else: ?>
                                    <button type="button" class="btn-action btn-unban" onclick="confirmAction(this.form, 'unban', '<?= e($u['email']) ?>')">
                                        <i class="fas fa-undo"></i> Restore Access
                                    </button>
                                    <input type="hidden" name="action" value="unban">
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // 如果后端有处理结果，显示弹窗
        <?php if($msg): ?>
            Swal.fire({
                icon: '<?= strpos($msg, '✅') !== false ? 'success' : 'error' ?>',
                title: '<?= strpos($msg, '✅') !== false ? 'Success' : 'Action Failed' ?>',
                text: '<?= str_replace(['✅ ', '❌ '], '', $msg) ?>',
                timer: 2000,
                showConfirmButton: false
            });
        <?php endif; ?>

        // 极其专业的操作确认弹窗
        function confirmAction(form, actionType, email) {
            let isBan = actionType === 'ban';
            Swal.fire({
                title: isBan ? 'Restrict Account?' : 'Restore Account?',
                html: isBan ? `Are you sure you want to blacklist <b>${email}</b>?<br><br><span style="color:#ef4444; font-size:0.9rem;">They will not be able to log in or book visits.</span>` 
                            : `Restore access for <b>${email}</b>?`,
                icon: isBan ? 'warning' : 'question',
                showCancelButton: true,
                confirmButtonColor: isBan ? '#ef4444' : '#10b981',
                cancelButtonColor: '#64748b',
                confirmButtonText: isBan ? 'Yes, Blacklist' : 'Yes, Restore'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit(); // 确认后提交表单
                }
            });
        }
    </script>
</body>
</html>