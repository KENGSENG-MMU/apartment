<?php
// 文件路径: public/resident.php
require_once '../core/security.php';
require_login(['resident', 'admin']); 

$pdo = db();
$resident_id = $_SESSION['uid'];
$resident_email = explode('@', $_SESSION['email'])[0]; // 用 email 前缀模拟房号

// 1. 获取该住户所有 Pending 的请求
$stmt = $pdo->prepare("SELECT * FROM bookings WHERE resident_id = ? AND status = 'pending' ORDER BY created_at ASC");
$stmt->execute([$resident_id]);
$pending_requests = $stmt->fetchAll();

// 2. 统计 KPI 数据 (超真实的 Dashboard 数据)
// KPI: 今日活跃访客 (已入住或已分配)
$activeStmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE resident_id = ? AND status IN ('allocated', 'checked_in') AND DATE(start_time) = CURDATE()");
$activeStmt->execute([$resident_id]);
$active_visitors = $activeStmt->fetchColumn();

// KPI: 剩余访客车位
$slotsStmt = $pdo->query("SELECT 
    (SELECT COUNT(*) FROM parking_slots WHERE slot_type = 'Visitor') as total_slots,
    (SELECT COUNT(*) FROM parking_slots WHERE slot_type = 'Visitor' AND status = 'available') as available_slots
");
$slotData = $slotsStmt->fetch();
$available_slots = $slotData['available_slots'];
$total_slots = $slotData['total_slots'] ?: 50; // 防止除以0
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resident Portal - <?= APP_NAME ?></title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* 保持之前的高颜值 CSS */
        :root { --bg: #f3f4f6; --surface: #ffffff; --primary: #4f46e5; --text-main: #111827; --text-muted: #6b7280; --border: #e5e7eb; --success: #10b981; --danger: #ef4444; --warning: #f59e0b; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background: var(--bg); color: var(--text-main); }
        .navbar { background: var(--surface); padding: 15px 5%; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .logo { font-size: 1.25rem; font-weight: 800; } .logo span { color: var(--primary); }
        .user-profile { display: flex; align-items: center; gap: 10px; font-weight: 600; font-size: 0.9rem; }
        .avatar { width: 35px; height: 35px; background: var(--primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .container { max-width: 1000px; margin: 30px auto; padding: 0 20px; }
        .page-header { margin-bottom: 25px; } .page-title { font-size: 1.5rem; font-weight: 700; } .page-sub { color: var(--text-muted); font-size: 0.9rem; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: var(--surface); padding: 20px; border-radius: 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); border: 1px solid var(--border); }
        .stat-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; margin-bottom: 15px; }
        .stat-val { font-size: 1.8rem; font-weight: 800; margin-bottom: 5px; line-height: 1; }
        .stat-label { color: var(--text-muted); font-size: 0.85rem; font-weight: 600; text-transform: uppercase; }
        .card { background: var(--surface); border-radius: 16px; border: 1px solid var(--border); overflow: hidden; }
        .card-header { padding: 20px; border-bottom: 1px solid var(--border); font-size: 1.1rem; font-weight: 700; display: flex; justify-content: space-between; }
        .request-item { padding: 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .plate-box { background: #f1f5f9; border: 2px solid #cbd5e1; padding: 5px 12px; border-radius: 6px; font-family: monospace; font-weight: 700; font-size: 1.1rem; color: #334155; }
        .v-name { font-weight: 700; font-size: 1rem; } .v-time { color: var(--text-muted); font-size: 0.85rem; }
        .action-btns { display: flex; gap: 10px; }
        .btn { padding: 8px 16px; border-radius: 8px; font-weight: 600; font-size: 0.85rem; cursor: pointer; border: none; display: flex; align-items: center; gap: 6px; }
        .btn-approve { background: var(--success); color: white; } .btn-reject { background: #f3f4f6; color: var(--danger); border: 1px solid #e5e7eb; }
        .tag { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; background: #fef3c7; color: #b45309; }
    </style>
</head>
<body>

    <nav class="navbar">
        <div class="logo">Smart<span>VMS</span></div>
        <div class="user-profile">
            <span>Unit: <?= htmlspecialchars($resident_email) ?></span>
            <div class="avatar"><?= strtoupper(substr($resident_email, 0, 1)) ?></div>
            <a href="../core/logout.php" style="margin-left:15px; color:var(--danger); text-decoration:none;"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </nav>

    <div class="container">
        <div class="page-header">
            <h1 class="page-title">Welcome back!</h1>
            <p class="page-sub">Manage your visitors and view parking capacity.</p>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: #e0e7ff; color: var(--primary);"><i class="fas fa-user-clock"></i></div>
                <div class="stat-val"><?= count($pending_requests) ?></div>
                <div class="stat-label">Pending Approvals</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #d1fae5; color: var(--success);"><i class="fas fa-car-side"></i></div>
                <div class="stat-val"><?= $active_visitors ?></div>
                <div class="stat-label">Active Visitors Today</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #fef3c7; color: var(--warning);"><i class="fas fa-parking"></i></div>
                <div class="stat-val"><?= $available_slots ?> <span style="font-size:1rem; color:var(--text-muted)">/ <?= $total_slots ?></span></div>
                <div class="stat-label">Visitor Parking Left</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                Pending Visitor Requests
            </div>
            
            <div id="request-list">
                <?php if (empty($pending_requests)): ?>
                    <div style="padding: 40px; text-align: center; color: var(--text-muted);">
                        <i class="fas fa-check-circle" style="font-size: 3rem; color: #d1d5db; margin-bottom: 10px;"></i>
                        <p>You're all caught up! No pending requests.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($pending_requests as $req): ?>
                    <div class="request-item" id="req-<?= $req['id'] ?>">
                        <div style="display: flex; gap: 15px; align-items: center;">
                            <div class="plate-box"><?= e($req['plate_no']) ?></div>
                            <div>
                                <div class="v-name"><?= e($req['visitor_name']) ?> <span class="tag">Pending</span></div>
                                <div class="v-time"><i class="far fa-clock"></i> <?= date('d M Y, g:i A', strtotime($req['start_time'])) ?></div>
                            </div>
                        </div>
                        <div class="action-btns">
                            <button class="btn btn-reject" onclick="handleBooking(<?= $req['id'] ?>, 'reject', '<?= e($req['plate_no']) ?>')"><i class="fas fa-times"></i> Reject</button>
                            <button class="btn btn-approve" onclick="handleBooking(<?= $req['id'] ?>, 'approve', '<?= e($req['plate_no']) ?>')"><i class="fas fa-check"></i> Approve</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function handleBooking(bookingId, action, plateNo) {
            let actionText = action === 'approve' ? 'Approving and allocating parking...' : 'Rejecting request...';
            
            Swal.fire({
                title: actionText,
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            // 向我们刚写的后端大脑发送真实请求
            let formData = new FormData();
            formData.append('booking_id', bookingId);
            formData.append('action', action);
            formData.append('csrf_token', '<?= e($_SESSION['csrf_token'] ?? '') ?>');

            fetch('../api/booking_action.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (action === 'approve') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Visitor Approved!',
                            html: `
                                <p>Plate: <b>${plateNo}</b></p>
                                <p style="margin-top:10px;">Allocated Parking:</p>
                                <p style="font-size:1.5rem; font-weight:bold; color:#10b981;">${data.slot}</p>
                            `,
                            confirmButtonColor: '#4f46e5'
                        }).then(() => location.reload()); // 刷新以更新 KPI
                    } else {
                        Swal.fire('Rejected', 'Request has been denied.', 'info').then(() => location.reload());
                    }
                } else {
                    Swal.fire('Error', data.message, 'error'); // 车位满了会在这里报错！
                }
            })
            .catch(() => Swal.fire('Error', 'Server connection failed.', 'error'));
        }
    </script>
</body>
</html>