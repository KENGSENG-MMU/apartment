<?php
// 文件路径: public/guard_logs.php
require_once '../core/security.php';

// 限制只有保安、Admin、SuperAdmin 可访问
require_login(['guard', 'admin', 'superadmin']);

$pdo = db();
$guard_id = $_SESSION['uid'];

// 获取最近的 20 条门禁记录 (手机端展示不需要太多，20条足够)
$stmt = $pdo->query("
    SELECT g.*, b.visitor_name, u.email as resident_email 
    FROM gate_logs g 
    LEFT JOIN bookings b ON g.booking_id = b.id 
    LEFT JOIN users u ON b.resident_id = u.id
    ORDER BY g.action_time DESC LIMIT 20
");
$logs = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Live Logs - <?= APP_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root { --primary: #4f46e5; --bg: #111827; --surface: #1f2937; --text: #f9fafb; --success: #10b981; --danger: #ef4444; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background: var(--bg); color: var(--text); display: flex; flex-direction: column; min-height: 100vh; padding-bottom: 70px; }
        
        .header { padding: 20px; display: flex; justify-content: space-between; align-items: center; background: var(--surface); border-bottom: 1px solid #374151; position: sticky; top: 0; z-index: 10; }
        .logo { font-size: 1.2rem; font-weight: 700; }
        .logo span { color: var(--primary); }
        .user-badge { background: #374151; padding: 5px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; display: flex; align-items: center; gap: 8px; }
        
        .main-content { flex: 1; padding: 15px; }
        .page-title { font-size: 1.2rem; margin-bottom: 15px; text-align: left; padding-left: 5px; color: #9ca3af; text-transform: uppercase; letter-spacing: 1px; }
        
        /* 手机端优化的列表项 */
        .log-item { background: var(--surface); border-radius: 12px; padding: 15px; margin-bottom: 12px; border: 1px solid #374151; display: flex; flex-direction: column; gap: 8px; }
        
        .log-header { display: flex; justify-content: space-between; align-items: center; }
        .plate-box { background: #111827; border: 1px solid #4b5563; padding: 4px 10px; border-radius: 6px; font-family: monospace; font-weight: 800; font-size: 1.1rem; color: #f9fafb; letter-spacing: 1px; }
        .time-text { font-size: 0.8rem; color: #9ca3af; font-weight: 600; }
        
        .log-body { display: flex; justify-content: space-between; align-items: flex-end; }
        .info-col { flex: 1; }
        .v-name { font-weight: 700; font-size: 0.95rem; margin-bottom: 2px; }
        .r-unit { font-size: 0.8rem; color: #6b7280; }
        
        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: flex; align-items: center; gap: 5px; }
        .st-allow { background: #064e3b; color: #34d399; border: 1px solid #059669; }
        .st-deny { background: #7f1d1d; color: #f87171; border: 1px solid #b91c1c; }
        .action-tag { font-size: 0.7rem; font-weight: 800; background: #374151; padding: 2px 6px; border-radius: 4px; margin-right: 5px; }

        .deny-reason { font-size: 0.8rem; color: #f87171; margin-top: 5px; font-weight: 600; background: #450a0a; padding: 6px; border-radius: 6px; border-left: 3px solid #ef4444; }
        
        /* 底部导航 */
        .bottom-nav { background: var(--surface); display: flex; padding: 10px 0; border-top: 1px solid #374151; position: fixed; bottom: 0; width: 100%; padding-bottom: env(safe-area-inset-bottom); z-index: 10; }
        .nav-item { flex: 1; display: flex; flex-direction: column; align-items: center; color: #9ca3af; text-decoration: none; font-size: 0.75rem; font-weight: 600; gap: 5px; opacity: 0.7; }
        .nav-item.active { color: var(--primary); opacity: 1; }
        .nav-item i { font-size: 1.3rem; }
    </style>
</head>
<body>

    <div class="header">
        <div class="logo">Smart<span>VMS</span></div>
        <div class="user-badge"><i class="fas fa-shield-alt"></i> Guard Post</div>
    </div>

    <div class="main-content">
        <h2 class="page-title"><i class="fas fa-history"></i> Recent Gate Activity</h2>
        
        <?php if(empty($logs)): ?>
            <div style="text-align: center; padding: 50px 20px; color: #6b7280;">
                <i class="fas fa-clipboard-list" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.5;"></i>
                <p>No activity recorded yet.</p>
            </div>
        <?php else: ?>
            <?php foreach($logs as $log): ?>
                <div class="log-item">
                    <div class="log-header">
                        <div class="plate-box"><?= e($log['plate_no']) ?></div>
                        <div class="time-text"><?= date('h:i A', strtotime($log['action_time'])) ?></div>
                    </div>
                    
                    <div class="log-body">
                        <div class="info-col">
                            <?php if($log['visitor_name']): ?>
                                <div class="v-name"><?= e($log['visitor_name']) ?></div>
                                <div class="r-unit">To: <?= explode('@', $log['resident_email'])[0] ?></div>
                            <?php else: ?>
                                <div class="v-name" style="color:#9ca3af;">Unknown / Walk-in</div>
                            <?php endif; ?>
                        </div>
                        
                        <div>
                            <?php if($log['decision'] === 'ALLOW'): ?>
                                <span class="status-badge st-allow">
                                    <span class="action-tag"><?= e($log['gate_action']) ?></span> <i class="fas fa-check"></i> ALLOW
                                </span>
                            <?php else: ?>
                                <span class="status-badge st-deny">
                                    <span class="action-tag"><?= e($log['gate_action']) ?></span> <i class="fas fa-times"></i> DENY
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if($log['decision'] === 'DENY'): ?>
                        <div class="deny-reason"><i class="fas fa-exclamation-triangle"></i> <?= e($log['reason']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <div style="text-align: center; margin-top: 20px; font-size: 0.8rem; color: #4b5563;">
            Showing last 20 records.
        </div>
    </div>

    <div class="bottom-nav">
        <a href="guard_scan.php" class="nav-link nav-item"><i class="fas fa-qrcode"></i><span>Scan</span></a>
        <a href="guard_logs.php" class="nav-link nav-item active"><i class="fas fa-list"></i><span>Live Logs</span></a>
        <a href="../core/logout.php" class="nav-link nav-item"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a> 
    </div>

</body>
</html>