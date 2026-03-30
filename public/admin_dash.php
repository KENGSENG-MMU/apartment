<?php
// 文件路径: public/admin_dash.php
require_once '../core/security.php';

// 【安全防御】只允许 Admin 和 SuperAdmin 访问
require_login(['admin', 'superadmin']); 

$pdo = db();
$admin_name = explode('@', $_SESSION['email'])[0];

// 1. 获取高管级 KPI 数据
$todayBookings = $pdo->query("SELECT COUNT(*) FROM bookings WHERE DATE(start_time) = CURDATE()")->fetchColumn();
$blockedEntries = $pdo->query("SELECT COUNT(*) FROM gate_logs WHERE decision = 'DENY' AND DATE(action_time) = CURDATE()")->fetchColumn();

$slotStats = $pdo->query("SELECT 
    SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available,
    SUM(CASE WHEN status != 'available' THEN 1 ELSE 0 END) as occupied
    FROM parking_slots WHERE slot_type = 'Visitor'")->fetch();
$occupied = $slotStats['occupied'] ?? 0;
$available = $slotStats['available'] ?? 0;
$total_visitor_slots = $occupied + $available ?: 1; 
$occupancy_rate = round(($occupied / $total_visitor_slots) * 100);

// 2. 获取实时大门进出日志 (最近 8 条)
$recentLogs = $pdo->query("
    SELECT g.*, b.visitor_name, u.email as resident_email 
    FROM gate_logs g 
    LEFT JOIN bookings b ON g.booking_id = b.id 
    LEFT JOIN users u ON b.resident_id = u.id
    ORDER BY g.action_time DESC LIMIT 8
")->fetchAll();

// 3. 【核心新增】获取图表数据：所有历史预约的状态分布
$chartDataStmt = $pdo->query("SELECT status, COUNT(*) as count FROM bookings GROUP BY status");
$chartLabels = [];
$chartValues = [];
$statusColors = [
    'pending' => '#f59e0b',    // Warning Yellow
    'approved' => '#3b82f6',   // Blue
    'allocated' => '#6366f1',  // Indigo
    'checked_in' => '#10b981', // Emerald Green
    'completed' => '#64748b',  // Slate Gray
    'rejected' => '#ef4444'    // Red
];
$chartBgColors = [];

while ($row = $chartDataStmt->fetch()) {
    $status = strtolower($row['status']);
    $chartLabels[] = ucfirst($status);
    $chartValues[] = $row['count'];
    $chartBgColors[] = $statusColors[$status] ?? '#cbd5e1';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?= APP_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        :root { --bg: #f8fafc; --surface: #ffffff; --primary: #0f172a; --accent: #4f46e5; --text-main: #1e293b; --text-muted: #64748b; --border: #e2e8f0; --success: #10b981; --danger: #ef4444; --warning: #f59e0b; }
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
        
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .kpi-card { background: var(--surface); padding: 25px; border-radius: 16px; border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); display: flex; align-items: center; gap: 20px; }
        .kpi-icon { width: 55px; height: 55px; border-radius: 14px; display: flex; justify-content: center; align-items: center; font-size: 1.6rem; }
        .kpi-info h3 { font-size: 2rem; font-weight: 800; margin-bottom: 2px; line-height: 1; }
        .kpi-info p { color: var(--text-muted); font-size: 0.85rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        
        /* 布局大网格：左边是表格，右边是图表 */
        .dashboard-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
        
        .panel-card { background: var(--surface); border-radius: 16px; border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); overflow: hidden; display: flex; flex-direction: column; }
        .panel-header { padding: 20px 25px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .panel-title { font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        
        .btn-export { background: var(--bg); border: 1px solid var(--border); padding: 8px 15px; border-radius: 8px; font-weight: 600; font-size: 0.85rem; cursor: pointer; color: var(--text-main); display: flex; align-items: center; gap: 8px; transition: 0.2s; }
        .btn-export:hover { background: #e2e8f0; }
        
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px 25px; font-size: 0.8rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid var(--border); background: #f8fafc; }
        td { padding: 15px 25px; font-size: 0.95rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover { background: #f8fafc; }
        
        .plate-badge { font-family: monospace; font-weight: bold; background: #f1f5f9; border: 1px solid #cbd5e1; padding: 4px 8px; border-radius: 6px; letter-spacing: 1px; color: #334155; }
        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; }
        .bg-success { background: #dcfce7; color: #166534; }
        .bg-danger { background: #fee2e2; color: #991b1b; }
        
        /* 图表容器 */
        .chart-container { padding: 20px; flex: 1; display: flex; justify-content: center; align-items: center; min-height: 300px; }
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
                <h1 class="page-title">Admin Control Center</h1>
                <p class="page-sub">Today's overview and real-time analytics.</p>
            </div>
            <div style="text-align: right;">
                <p style="font-weight: 600; font-size: 0.9rem;">Admin: <?= htmlspecialchars(ucfirst($admin_name)) ?></p>
                <p style="color: var(--text-muted); font-size: 0.85rem;"><?= date('l, d M Y') ?></p>
            </div>
        </div>

        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-icon" style="background: #e0e7ff; color: var(--accent);"><i class="fas fa-calendar-check"></i></div>
                <div class="kpi-info">
                    <h3><?= $todayBookings ?></h3>
                    <p>Bookings Today</p>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background: #fee2e2; color: var(--danger);"><i class="fas fa-shield-virus"></i></div>
                <div class="kpi-info">
                    <h3><?= $blockedEntries ?></h3>
                    <p>Security Blocks Today</p>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background: #fef3c7; color: var(--warning);"><i class="fas fa-chart-line"></i></div>
                <div class="kpi-info">
                    <h3><?= $occupancy_rate ?>%</h3>
                    <p>Visitor Parking Load</p>
                </div>
            </div>
        </div>

        <div class="dashboard-grid">
            
            <div class="panel-card">
                <div class="panel-header">
                    <div class="panel-title"><i class="fas fa-list"></i> Live Gate Security Logs</div>
                    <button class="btn-export" onclick="window.location.href='../api/export_logs.php'"><i class="fas fa-download"></i> Export CSV</button>
                </div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Plate No</th>
                                <th>Action</th>
                                <th>Decision</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($recentLogs)): ?>
                                <tr><td colspan="5" style="text-align:center; color:var(--text-muted); padding: 30px;">No gate activity recorded yet.</td></tr>
                            <?php else: ?>
                                <?php foreach($recentLogs as $log): ?>
                                <tr>
                                    <td style="color: var(--text-muted); font-size: 0.85rem; font-weight: 600;">
                                        <?= date('H:i:s', strtotime($log['action_time'])) ?><br>
                                        <?= date('d M', strtotime($log['action_time'])) ?>
                                    </td>
                                    <td><span class="plate-badge"><?= e($log['plate_no']) ?></span></td>
                                    <td><span style="font-weight:700; color: var(--text-main);"><i class="fas <?= $log['gate_action']=='ENTRY'?'fa-arrow-right':'fa-arrow-left' ?>"></i> <?= e($log['gate_action']) ?></span></td>
                                    <td>
                                        <?php if($log['decision'] === 'ALLOW'): ?>
                                            <span class="status-badge bg-success">✅ ALLOW</span>
                                        <?php else: ?>
                                            <span class="status-badge bg-danger">❌ DENY</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:0.85rem; color: var(--text-muted); max-width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= e($log['reason']) ?>">
                                        <?= e($log['reason']) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="panel-card">
                <div class="panel-header">
                    <div class="panel-title"><i class="fas fa-chart-pie"></i> Overall Booking Status</div>
                </div>
                <div class="chart-container">
                    <?php if(empty($chartLabels)): ?>
                        <p style="color: var(--text-muted); font-size: 0.9rem;">No data available to chart.</p>
                    <?php else: ?>
                        <canvas id="statusChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    <script>
        <?php if(!empty($chartLabels)): ?>
        const ctx = document.getElementById('statusChart').getContext('2d');
        const statusChart = new Chart(ctx, {
            type: 'doughnut', // 甜甜圈图
            data: {
                labels: <?= json_encode($chartLabels) ?>,
                datasets: [{
                    data: <?= json_encode($chartValues) ?>,
                    backgroundColor: <?= json_encode($chartBgColors) ?>,
                    borderWidth: 0,
                    hoverOffset: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%', // 控制圆环粗细
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { padding: 20, font: { family: "'Plus Jakarta Sans', sans-serif", size: 12, weight: 'bold' } }
                    },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        padding: 12,
                        titleFont: { family: "'Plus Jakarta Sans', sans-serif", size: 14 },
                        bodyFont: { family: "'Plus Jakarta Sans', sans-serif", size: 13, weight: 'bold' }
                    }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>