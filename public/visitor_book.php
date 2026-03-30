<?php
// 文件路径: public/visitor_book.php
require_once '../core/security.php';

// 只有访客可以访问此页
require_login(['visitor', 'admin']); 

$pdo = db();
$msg = '';
$msgType = '';

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 【安全】CSRF 验证
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("CSRF Token Error");
    }

    $visitor_name = trim($_POST['visitor_name']);
    $plate_no = strtoupper(trim($_POST['plate_no']));
    $resident_id = (int)$_POST['resident_id'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    
    // 【高级特征】生成独一无二的 32 位安全 Token (用于二维码凭证)
    $qr_token = bin2hex(random_bytes(16));

    try {
        // 插入数据库，初始状态为 'pending' (等待住户审批)
        $stmt = $pdo->prepare("INSERT INTO bookings (plate_no, visitor_name, resident_id, start_time, end_time, status, qr_token) VALUES (?, ?, ?, ?, ?, 'pending', ?)");
        $stmt->execute([$plate_no, $visitor_name, $resident_id, $start_time, $end_time, $qr_token]);
        
        $msg = "✅ Booking submitted successfully! Waiting for Resident's approval.";
        $msgType = "success";
        
        // 写入审计日志
        log_audit('BOOKING_CREATED', "Plate: $plate_no applied to visit Resident ID: $resident_id");
    } catch (PDOException $e) {
        $msg = "❌ Error submitting booking. Please try again.";
        $msgType = "error";
    }
}

// 获取所有住户名单，供访客选择
$residents = $pdo->query("SELECT id, email FROM users WHERE role = 'resident' AND status = 'active'")->fetchAll();

// 获取当前访客自己最近的预约记录 (用于界面展示)
// 注：真实系统访客表会有自己的 ID，这里我们简化，用最新的 3 条做展示
$recent_bookings = $pdo->query("SELECT * FROM bookings ORDER BY id DESC LIMIT 3")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book a Visit - <?= APP_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #4f46e5; --bg: #f3f4f6; --surface: #ffffff; --text: #1f2937; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background: var(--bg); color: var(--text); padding-bottom: 50px; }
        
        .header { background: var(--primary); color: white; padding: 40px 20px 60px 20px; text-align: center; border-bottom-left-radius: 30px; border-bottom-right-radius: 30px; }
        .header h1 { font-size: 1.5rem; margin-bottom: 5px; }
        .header p { font-size: 0.9rem; opacity: 0.8; }
        
        .container { max-width: 500px; margin: -30px auto 0; padding: 0 15px; }
        .card { background: var(--surface); border-radius: 16px; padding: 25px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); margin-bottom: 20px; }
        
        .form-group { margin-bottom: 18px; }
        label { display: block; font-size: 0.85rem; font-weight: 600; color: #4b5563; margin-bottom: 8px; }
        input, select { width: 100%; padding: 12px 15px; border: 1px solid #d1d5db; border-radius: 10px; font-size: 1rem; background: #f9fafb; transition: 0.2s; }
        input:focus, select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); background: white; }
        
        .btn { width: 100%; padding: 14px; background: var(--primary); color: white; border: none; border-radius: 10px; font-weight: 700; font-size: 1rem; cursor: pointer; transition: 0.2s; display: flex; justify-content: center; align-items: center; gap: 8px; }
        .btn:active { transform: scale(0.98); }
        
        .alert { padding: 15px; border-radius: 10px; margin-bottom: 20px; font-size: 0.9rem; font-weight: 600; display: none; }
        .alert.show { display: block; }
        .alert.success { background: #d1fae5; color: #065f46; border: 1px solid #34d399; }
        .alert.error { background: #fee2e2; color: #991b1b; border: 1px solid #f87171; }

        /* 历史记录样式 */
        .history-item { border: 1px solid #e5e7eb; border-radius: 12px; padding: 15px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
        .h-plate { font-family: monospace; font-weight: bold; font-size: 1.1rem; color: var(--primary); }
        .h-status { font-size: 0.75rem; font-weight: 700; padding: 4px 10px; border-radius: 20px; text-transform: uppercase; }
        .st-pending { background: #fef3c7; color: #b45309; }
        .st-approved { background: #d1fae5; color: #047857; }
        .st-allocated { background: #e0e7ff; color: #4338ca; }
    </style>
</head>
<body>

    <div class="header">
        <h1>New Visit Application</h1>
        <p>Register your vehicle for entry</p>
    </div>

    <div class="container">
        <div class="alert <?= $msgType ?> <?= $msg ? 'show' : '' ?>">
            <?= $msg ?>
        </div>

        <div class="card">
            <form method="POST" action="">
                <?= csrf_field() ?>
                
                <div class="form-group">
                    <label>Your Full Name</label>
                    <input type="text" name="visitor_name" placeholder="e.g. Ali Bin Abu" required>
                </div>
                
                <div class="form-group">
                    <label>Vehicle Plate Number</label>
                    <input type="text" name="plate_no" placeholder="e.g. WXX1234" style="text-transform: uppercase; font-family: monospace; font-weight: bold;" required>
                </div>

                <div class="form-group">
                    <label>Resident to Visit</label>
                    <select name="resident_id" required>
                        <option value="">-- Select Resident --</option>
                        <?php foreach($residents as $r): ?>
                            <option value="<?= $r['id'] ?>">Unit: <?= explode('@', $r['email'])[0] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: flex; gap: 10px;">
                    <div class="form-group" style="flex: 1;">
                        <label>Expected Entry</label>
                        <input type="datetime-local" name="start_time" value="<?= date('Y-m-d\TH:i') ?>" required>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label>Expected Exit</label>
                        <input type="datetime-local" name="end_time" value="<?= date('Y-m-d\TH:i', strtotime('+2 hours')) ?>" required>
                    </div>
                </div>

                <button type="submit" class="btn"><i class="fas fa-paper-plane"></i> Submit Request</button>
            </form>
        </div>

        <h3 style="font-size: 1rem; margin: 30px 0 10px 5px; color: #4b5563;">My Recent Bookings</h3>
        <div class="card" style="padding: 15px;">
            <?php if(empty($recent_bookings)): ?>
                <p style="text-align:center; color:#9ca3af; font-size:0.9rem; padding: 20px 0;">No history found.</p>
            <?php else: ?>
                <?php foreach($recent_bookings as $b): ?>
                    <div class="history-item">
                        <div>
                            <div class="h-plate"><?= e($b['plate_no']) ?></div>
                            <div style="font-size: 0.8rem; color: #6b7280; margin-top: 3px;"><?= date('d M, g:i A', strtotime($b['start_time'])) ?></div>
                        </div>
                        <div class="history-item">
                        <div>
                            <div class="h-plate"><?= e($b['plate_no']) ?></div>
                            <div style="font-size: 0.8rem; color: #6b7280; margin-top: 3px;"><?= date('d M, g:i A', strtotime($b['start_time'])) ?></div>
                        </div>
                        <div style="text-align: right;">
                            <div class="h-status st-<?= strtolower($b['status']) ?>"><?= e($b['status']) ?></div>
                            
                            <?php if(in_array($b['status'], ['allocated', 'checked_in'])): ?>
                                <div style="margin-top: 8px;">
                                    <a href="visitor_pass.php?id=<?= $b['id'] ?>" style="background: #4f46e5; color: white; padding: 4px 10px; border-radius: 6px; text-decoration: none; font-size: 0.75rem; font-weight: 700;"><i class="fas fa-ticket-alt"></i> View Pass</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <div style="text-align: center; margin-top: 20px;">
            <a href="../core/logout.php" style="color: #6b7280; text-decoration: none; font-size: 0.9rem; font-weight: 600;"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

</body>
</html>