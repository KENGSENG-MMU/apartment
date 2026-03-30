<?php
// 文件路径: public/admin_audit.php
require_once '../core/security.php';

// 【安全防御】只允许 Admin 和 SuperAdmin 访问，这是最高机密
require_login(['admin', 'superadmin']); 

$pdo = db();
$admin_name = explode('@', $_SESSION['email'])[0];

// 1. 获取最近的 100 条系统操作日志，并联合查询出操作人的 Email
$stmt = $pdo->query("
    SELECT a.*, u.email as operator_email, u.role as operator_role
    FROM audit_logs a
    LEFT JOIN users u ON a.user_id = u.id
    ORDER BY a.created_at DESC LIMIT 100
");
$logs = $stmt->fetchAll();

// 2. 统计各种操作的安全级别分布 (用于顶部小 KPI)
$stats = $pdo->query("
    SELECT 
        SUM(CASE WHEN action LIKE '%FAILED%' OR action LIKE '%BLOCKED%' THEN 1 ELSE 0 END) as alerts,
        SUM(CASE WHEN action LIKE '%LOGIN%' THEN 1 ELSE 0 END) as logins,
        SUM(CASE WHEN action NOT LIKE '%LOGIN%' AND action NOT LIKE '%FAILED%' THEN 1 ELSE 0 END) as ops
    FROM audit_logs
")->fetch();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs - <?= APP_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
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
        
        /* 顶部安全指示器 */
        .security-banner { display: flex; gap: 20px; margin-bottom: 30px; }
        .sec-card { flex: 1; background: var(--surface); padding: 20px; border-radius: 12px; border: 1px solid var(--border); border-left: 4px solid var(--accent); display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .sec-card.alert { border-left-color: var(--danger); }
        .sec-card.success { border-left-color: var(--success); }
        .sec-val { font-size: 1.5rem; font-weight: 800; }
        .sec-label { font-size: 0.8rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; }
        
        .table-card { background: var(--surface); border-radius: 16px; border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); overflow: hidden; }
        .table-header { padding: 20px 25px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .table-title { font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 10px; color: var(--primary); }
        
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px 25px; font-size: 0.8rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid var(--border); background: #f8fafc; }
        td { padding: 15px 25px; font-size: 0.9rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover { background: #f8fafc; }
        
        .action-badge { padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 800; letter-spacing: 0.5px; }
        .bg-auth { background: #e0e7ff; color: #3730a3; } /* 登录类 */
        .bg-danger { background: #fee2e2; color: #991b1b; } /* 失败类 */
        .bg-sys { background: #f3e8ff; color: #6b21a8; } /* 系统配置类 */
        .bg-info { background: #e0f2fe; color: #0369a1; } /* 其他操作类 */
        
        .ip-badge { font-family: monospace; background: #f1f5f9; padding: 3px 6px; border-radius: 4px; color: #475569; font-weight: 600; font-size: 0.85rem; }
        .device-text { font-size: 0.75rem; color: #94a3b8; margin-top: 4px; max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: inline-block; }
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
                <h1 class="page-title">System Audit Trail</h1>
                <p class="page-sub">Immutable record of all critical system events and access attempts.</p>
            </div>
            <div style="text-align: right;">
                <p style="font-weight: 600; font-size: 0.9rem;">Admin: <?= htmlspecialchars(ucfirst($admin_name)) ?></p>
            </div>
        </div>

        <div class="security-banner">
            <div class="sec-card alert">
                <div>
                    <div class="sec-label">Security Alerts / Failures</div>
                    <div class="sec-val" style="color: var(--danger);"><?= (int)$stats['alerts'] ?></div>
                </div>
                <i class="fas fa-shield-virus" style="font-size: 2rem; color: #fee2e2;"></i>
            </div>
            <div class="sec-card success">
                <div>
                    <div class="sec-label">Access Authentications</div>
                    <div class="sec-val" style="color: var(--success);"><?= (int)$stats['logins'] ?></div>
                </div>
                <i class="fas fa-fingerprint" style="font-size: 2rem; color: #dcfce7;"></i>
            </div>
            <div class="sec-card">
                <div>
                    <div class="sec-label">System Operations</div>
                    <div class="sec-val" style="color: var(--accent);"><?= (int)$stats['ops'] ?></div>
                </div>
                <i class="fas fa-server" style="font-size: 2rem; color: #e0e7ff;"></i>
            </div>
        </div>

        <div class="table-card">
            <div class="table-header">
                <div class="table-title"><i class="fas fa-list-ol"></i> Activity Log (Last 100 Events)</div>
                <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: 600;"><i class="fas fa-lock"></i> PDPA Compliant</span>
            </div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Action Type</th>
                            <th>Operator</th>
                            <th>Event Details</th>
                            <th>Network & Device</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($logs)): ?>
                            <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;">No audit logs available.</td></tr>
                        <?php else: ?>
                            <?php foreach($logs as $log): 
                                // 根据 action 关键字决定 Badge 颜色
                                $action = strtoupper($log['action']);
                                $badgeClass = 'bg-info';
                                if (strpos($action, 'LOGIN_SUCCESS') !== false) $badgeClass = 'bg-auth';
                                elseif (strpos($action, 'FAILED') !== false || strpos($action, 'BLOCKED') !== false || strpos($action, 'UNAUTHORIZED') !== false) $badgeClass = 'bg-danger';
                                elseif (strpos($action, 'CONFIG') !== false || strpos($action, 'BLACKLIST') !== false) $badgeClass = 'bg-sys';
                                
                                // 分离 Details 和 Device Info
                                $parts = explode('| Device:', $log['detail']);
                                $detailStr = trim($parts[0]);
                                $deviceStr = isset($parts[1]) ? trim($parts[1]) : 'Unknown Device';
                            ?>
                            <tr>
                                <td style="white-space: nowrap; color: var(--text-muted); font-weight: 600;">
                                    <?= date('d M, Y', strtotime($log['created_at'])) ?><br>
                                    <span style="color: var(--text-main);"><?= date('H:i:s', strtotime($log['created_at'])) ?></span>
                                </td>
                                <td><span class="action-badge <?= $badgeClass ?>"><?= e($action) ?></span></td>
                                <td>
                                    <?php if($log['operator_email']): ?>
                                        <div style="font-weight: 700; color: var(--primary);"><?= e($log['operator_email']) ?></div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;"><?= e($log['operator_role']) ?></div>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-style: italic;">System / Anonymous</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-weight: 500; color: #334155; max-width: 300px; line-height: 1.4;">
                                    <?= e($detailStr) ?>
                                </td>
                                <td>
                                    <div class="ip-badge"><i class="fas fa-globe-asia"></i> IP: <?= e($log['ip_addr']) ?></div><br>
                                    <span class="device-text" title="<?= e($deviceStr) ?>"><i class="fas fa-laptop"></i> <?= e($deviceStr) ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</body>
</html>