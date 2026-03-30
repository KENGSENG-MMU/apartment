<?php
// 文件路径: public/admin_bookings.php
require_once '../core/security.php';

// 【安全防御】只允许 Admin 和 SuperAdmin 访问
require_login(['admin', 'superadmin']); 

$pdo = db();
$admin_name = explode('@', $_SESSION['email'])[0];
$msg = '';

// 1. 处理强制取消预约的请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("CSRF Token Error.");
    }

    $booking_id = (int)$_POST['booking_id'];
    $plate_no = $_POST['plate_no'];

    // 只有在还没进门之前（pending, approved, allocated）才能取消
    $checkStmt = $pdo->prepare("SELECT status, slot_id FROM bookings WHERE id = ?");
    $checkStmt->execute([$booking_id]);
    $booking = $checkStmt->fetch();

    if ($booking && in_array($booking['status'], ['pending', 'approved', 'allocated'])) {
        try {
            $pdo->beginTransaction();
            
            // 如果已经分配了车位，必须把车位释放出来！(极致的资源回收逻辑)
            if ($booking['slot_id']) {
                $pdo->prepare("UPDATE parking_slots SET status = 'available' WHERE id = ?")->execute([$booking['slot_id']]);
            }
            
            // 强制更改状态为 cancelled
            $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?")->execute([$booking_id]);
            
            // 写入最高级别审计日志
            log_audit('BOOKING_FORCE_CANCELLED', "Admin forcefully cancelled booking ID $booking_id (Plate: $plate_no)");
            
            $pdo->commit();
            $msg = "✅ Booking for $plate_no has been forcefully cancelled.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "❌ Error cancelling booking.";
        }
    } else {
        $msg = "❌ Cannot cancel this booking. It might already be checked in, completed, or rejected.";
    }
}

// 2. 获取所有的预约数据 (连表查询住户信息和车位信息)
$stmt = $pdo->query("
    SELECT b.*, u.email as resident_email, p.block_name, p.slot_no 
    FROM bookings b
    LEFT JOIN users u ON b.resident_id = u.id
    LEFT JOIN parking_slots p ON b.slot_id = p.id
    ORDER BY b.created_at DESC
");
$all_bookings = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Bookings - <?= APP_NAME ?></title>
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
        
        .table-card { background: var(--surface); border-radius: 16px; border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); overflow: hidden; }
        .table-header { padding: 20px 25px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .table-title { font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 10px; color: var(--primary); }
        
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px 25px; font-size: 0.8rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid var(--border); background: #f8fafc; }
        td { padding: 15px 25px; font-size: 0.9rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover { background: #f8fafc; }
        
        .plate-badge { font-family: monospace; font-weight: bold; background: #f1f5f9; border: 1px solid #cbd5e1; padding: 4px 8px; border-radius: 6px; letter-spacing: 1px; color: #334155; }
        
        /* 状态颜色体系 */
        .st-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .st-pending { background: #fef3c7; color: #b45309; }
        .st-approved, .st-allocated { background: #e0e7ff; color: #3730a3; }
        .st-checked_in { background: #dcfce7; color: #166534; }
        .st-completed { background: #f1f5f9; color: #475569; }
        .st-rejected, .st-cancelled { background: #fee2e2; color: #991b1b; }
        
        .btn-cancel { background: #ffffff; color: var(--danger); border: 1px solid #fca5a5; padding: 6px 12px; border-radius: 6px; font-weight: 700; font-size: 0.8rem; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 5px; }
        .btn-cancel:hover { background: #fee2e2; }
        .btn-disabled { background: #f1f5f9; color: #94a3b8; border: 1px solid #e2e8f0; cursor: not-allowed; }
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
                <h1 class="page-title">Global Bookings Manager</h1>
                <p class="page-sub">Monitor and manage all visitor appointments across the property.</p>
            </div>
            <div style="text-align: right;">
                <p style="font-weight: 600; font-size: 0.9rem;">Admin: <?= htmlspecialchars(ucfirst($admin_name)) ?></p>
            </div>
        </div>

        <div class="table-card">
            <div class="table-header">
                <div class="table-title"><i class="fas fa-list"></i> Full Booking Database</div>
            </div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Date / Time</th>
                            <th>Visitor Info</th>
                            <th>Target Unit</th>
                            <th>Allocated Parking</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($all_bookings)): ?>
                            <tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">No bookings found in the system.</td></tr>
                        <?php else: ?>
                            <?php foreach($all_bookings as $b): ?>
                            <tr>
                                <td style="color: var(--text-muted); font-size: 0.85rem; font-weight: 600;">
                                    <?= date('d M Y', strtotime($b['start_time'])) ?><br>
                                    <span style="color: var(--text-main);"><?= date('h:i A', strtotime($b['start_time'])) ?></span>
                                </td>
                                <td>
                                    <div style="font-weight: 700; color: var(--primary); margin-bottom: 4px;"><?= e($b['visitor_name']) ?></div>
                                    <span class="plate-badge"><?= e($b['plate_no']) ?></span>
                                </td>
                                <td>
                                    <div style="font-weight: 600;"><i class="fas fa-home" style="color:#94a3b8;"></i> <?= explode('@', $b['resident_email'])[0] ?></div>
                                </td>
                                <td style="font-size: 0.85rem; font-weight: 600; color: #475569;">
                                    <?= $b['slot_id'] ? e($b['block_name'] . ' - ' . $b['slot_no']) : '<span style="color:#9ca3af; font-style:italic;">Not Assigned</span>' ?>
                                </td>
                                <td>
                                    <span class="st-badge st-<?= strtolower($b['status']) ?>"><?= e($b['status']) ?></span>
                                </td>
                                <td>
                                    <?php if(in_array($b['status'], ['pending', 'approved', 'allocated'])): ?>
                                        <form method="POST" action="" style="margin:0;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                                            <input type="hidden" name="plate_no" value="<?= e($b['plate_no']) ?>">
                                            <button type="button" class="btn-cancel" onclick="confirmCancel(this.form, '<?= e($b['plate_no']) ?>')">
                                                <i class="fas fa-times-circle"></i> Cancel
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button class="btn-cancel btn-disabled" disabled title="Cannot modify completed or active bookings">
                                            <i class="fas fa-lock"></i> Locked
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // 操作提示弹窗
        <?php if($msg): ?>
            Swal.fire({
                icon: '<?= strpos($msg, '✅') !== false ? 'success' : 'error' ?>',
                title: '<?= strpos($msg, '✅') !== false ? 'Success' : 'Notice' ?>',
                text: '<?= str_replace(['✅ ', '❌ '], '', $msg) ?>',
                timer: 3000,
                showConfirmButton: false
            });
        <?php endif; ?>

        // 极其危险的强制取消确认框
        function confirmCancel(form, plateNo) {
            Swal.fire({
                title: 'Force Cancel Booking?',
                html: `Are you sure you want to cancel the booking for <b>${plateNo}</b>?<br><br><span style="color:#ef4444; font-size:0.9rem;">If a parking slot was allocated, it will be immediately released.</span>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, Cancel it'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        }
    </script>
</body>
</html>