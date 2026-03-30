<?php
// 文件路径: public/superadmin_config.php
require_once '../core/security.php';

// 【安全防御】绝对拦截！只有 SuperAdmin 才能访问此页！
require_login(['superadmin']);

$pdo = db();
$admin_name = explode('@', $_SESSION['email'])[0];
$msg = '';
$msgType = '';

// 1. 处理表单提交 (修改配置)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 安全验证
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("❌ CSRF Token Validation Failed.");
    }

    $grace_minutes = (int)$_POST['grace_minutes'];
    $ocr_threshold = (float)$_POST['ocr_threshold'];
    $log_retention_days = (int)$_POST['log_retention_days'];

    try {
        // 更新 system_config 表 (ID 永远是 1)
        $stmt = $pdo->prepare("UPDATE system_config SET grace_minutes = ?, ocr_threshold = ?, log_retention_days = ? WHERE id = 1");
        $stmt->execute([$grace_minutes, $ocr_threshold, $log_retention_days]);
        
        $msg = "✅ System configuration updated successfully!";
        $msgType = "success";

        // 写入最高级别的审计日志
        log_audit('CONFIG_UPDATED', "SuperAdmin updated Config: Grace=$grace_minutes, OCR=$ocr_threshold, Retention=$log_retention_days");
    } catch (PDOException $e) {
        $msg = "❌ Error updating configuration.";
        $msgType = "error";
    }
}

// 2. 获取当前最新的配置参数
$config = $pdo->query("SELECT * FROM system_config WHERE id = 1")->fetch();
// 如果数据库没数据，给个默认值防止报错
if (!$config) {
    $config = ['grace_minutes' => 15, 'ocr_threshold' => 0.75, 'log_retention_days' => 90];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Config - <?= APP_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* 保持与 Admin Dash 一致的专业后台风格 */
        :root { --bg: #f8fafc; --surface: #ffffff; --primary: #0f172a; --accent: #4f46e5; --text-main: #1e293b; --text-muted: #64748b; --border: #e2e8f0; }
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
        
        .config-card { background: var(--surface); padding: 35px; border-radius: 16px; border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); max-width: 800px; }
        
        .form-row { margin-bottom: 25px; display: flex; flex-direction: column; }
        .form-row label { font-size: 0.95rem; font-weight: 700; color: var(--primary); margin-bottom: 5px; display: flex; align-items: center; gap: 8px; }
        .form-row .desc { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 10px; }
        
        input[type="number"], input[type="text"] { width: 100%; max-width: 300px; padding: 12px 15px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 1rem; font-weight: 600; color: var(--text-main); background: #f8fafc; transition: 0.2s; }
        input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); background: white; }
        
        .btn-save { background: var(--accent); color: white; border: none; padding: 14px 30px; border-radius: 8px; font-size: 1rem; font-weight: 700; cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 10px; margin-top: 10px; }
        .btn-save:hover { background: #4338ca; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3); }
        
        .alert { padding: 15px 20px; border-radius: 8px; font-weight: 600; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; }
        .alert.success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .alert.error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="brand">Smart<span>VMS</span></div>
        <a href="admin_dash.php" class="nav-link"><i class="fas fa-chart-pie"></i> Dashboard</a>
        <a href="#" class="nav-link"><i class="fas fa-parking"></i> Parking Slots</a>
        <a href="#" class="nav-link"><i class="fas fa-ban"></i> Blacklist</a>
        <a href="#" class="nav-link"><i class="fas fa-file-alt"></i> Audit Logs</a>
        <a href="superadmin_config.php" class="nav-link active"><i class="fas fa-cog"></i> System Config</a>
        
        <div class="spacer"></div>
        <a href="../core/logout.php" class="nav-link" style="color: #ef4444;"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <div class="main-content">
        <div class="header-flex">
            <div>
                <h1 class="page-title">Super Admin Settings</h1>
                <p class="page-sub">Configure core engine parameters and compliance rules.</p>
            </div>
            <div style="text-align: right;">
                <p style="font-weight: 600; font-size: 0.9rem; color: #ef4444;"><i class="fas fa-crown"></i> Super Admin</p>
                <p style="color: var(--text-muted); font-size: 0.85rem;"><?= htmlspecialchars($admin_name) ?></p>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="alert <?= $msgType ?>"><i class="fas <?= $msgType == 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?>"></i> <?= $msg ?></div>
        <?php endif; ?>

        <div class="config-card">
            <form method="POST" action="">
                <?= csrf_field() ?>
                
                <div class="form-row">
                    <label><i class="fas fa-clock" style="color: var(--accent);"></i> Booking Grace Period (Minutes)</label>
                    <div class="desc">How long a visitor can arrive late before the system marks the booking as expired (Anti-congestion logic).</div>
                    <input type="number" name="grace_minutes" value="<?= e($config['grace_minutes']) ?>" min="0" max="120" required>
                </div>
                
                <hr style="border: 0; border-top: 1px solid var(--border); margin: 25px 0;">

                <div class="form-row">
                    <label><i class="fas fa-robot" style="color: var(--accent);"></i> AI OCR Confidence Threshold</label>
                    <div class="desc">Minimum confidence level required for automatic gate opening without guard confirmation (e.g., 0.75 = 75%).</div>
                    <input type="number" step="0.01" name="ocr_threshold" value="<?= e($config['ocr_threshold']) ?>" min="0.50" max="0.99" required>
                </div>
                
                <hr style="border: 0; border-top: 1px solid var(--border); margin: 25px 0;">

                <div class="form-row">
                    <label><i class="fas fa-user-shield" style="color: var(--accent);"></i> PDPA Log Retention (Days)</label>
                    <div class="desc">Number of days to keep visitor entry/exit logs before automatic archival (Compliance with PDPA).</div>
                    <input type="number" name="log_retention_days" value="<?= e($config['log_retention_days']) ?>" min="30" max="365" required>
                </div>

                <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Configuration</button>
            </form>
        </div>
    </div>

</body>
</html>