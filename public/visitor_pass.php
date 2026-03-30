<?php
// 文件路径: public/visitor_pass.php
require_once '../core/security.php';

// 【安全】限制只有访客和管理员可以查看
require_login(['visitor', 'admin', 'superadmin']);

$pdo = db();
$booking_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// 查询完整的预约信息和分配到的车位
$stmt = $pdo->prepare("
    SELECT b.*, p.block_name, p.slot_no, u.email as resident_email 
    FROM bookings b 
    LEFT JOIN parking_slots p ON b.slot_id = p.id 
    LEFT JOIN users u ON b.resident_id = u.id 
    WHERE b.id = ? AND b.status IN ('allocated', 'checked_in')
");
$stmt->execute([$booking_id]);
$pass = $stmt->fetch();

if (!$pass) {
    die("<div style='text-align:center; padding:50px; font-family:sans-serif;'><h2>❌ Invalid or Expired Pass</h2><a href='visitor_book.php'>Go Back</a></div>");
}

$resident_unit = explode('@', $pass['resident_email'])[0];
$parking_info = $pass['block_name'] ? $pass['block_name'] . ' - ' . $pass['slot_no'] : 'General Visitor Bay';
// 我们利用免费的 API 将数据库里的 qr_token 转换成真实的图片
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=" . urlencode($pass['qr_token']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Digital Entry Pass - <?= APP_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root { --bg: #f3f4f6; --primary: #4f46e5; --text: #1f2937; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background: var(--bg); display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
        
        /* 登机牌风格卡片 */
        .ticket { background: #ffffff; width: 100%; max-width: 380px; border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.1); overflow: hidden; position: relative; }
        
        .ticket-header { background: var(--primary); color: white; padding: 25px 20px; text-align: center; }
        .ticket-header h2 { font-weight: 800; font-size: 1.4rem; letter-spacing: 1px; }
        .ticket-header p { opacity: 0.8; font-size: 0.85rem; margin-top: 5px; }
        
        /* 锯齿分割线 (像真实的门票) */
        .divider { height: 20px; background: radial-gradient(circle, var(--bg) 10px, transparent 11px) repeat-x; background-size: 24px 20px; background-position: top; margin-top: -10px; position: relative; z-index: 10; }
        
        .ticket-body { padding: 30px 25px; text-align: center; }
        .qr-box { background: #f9fafb; border: 2px dashed #cbd5e1; padding: 15px; border-radius: 16px; display: inline-block; margin-bottom: 25px; }
        .qr-box img { width: 160px; height: 160px; display: block; }
        
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; text-align: left; }
        .info-item label { color: #6b7280; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .info-item .val { color: var(--text); font-weight: 800; font-size: 1.05rem; margin-top: 3px; }
        
        .plate-highlight { background: #f1f5f9; border: 1px solid #cbd5e1; padding: 6px 12px; border-radius: 8px; font-family: monospace; font-size: 1.2rem; font-weight: 800; color: #334155; display: inline-block; margin-top: 4px; letter-spacing: 1px; }
        
        .status-banner { background: #dcfce7; color: #166534; text-align: center; padding: 12px; font-weight: 700; font-size: 0.9rem; border-top: 1px dashed #cbd5e1; }
        .status-banner.checked_in { background: #e0e7ff; color: #3730a3; }
        
        .btn-back { display: block; width: 100%; text-align: center; padding: 15px; color: #6b7280; text-decoration: none; font-weight: 700; font-size: 0.9rem; transition: 0.2s; }
        .btn-back:hover { color: var(--primary); }
    </style>
</head>
<body>

    <div class="ticket">
        <div class="ticket-header">
            <h2>Smart<span>VMS</span> Entry Pass</h2>
            <p>Show this to guard or scan at entry</p>
        </div>
        <div class="divider"></div>
        
        <div class="ticket-body">
            <div class="qr-box">
                <img src="<?= $qr_url ?>" alt="QR Token">
            </div>
            
            <div class="info-grid">
                <div class="info-item">
                    <label>Visitor Name</label>
                    <div class="val"><?= e($pass['visitor_name']) ?></div>
                </div>
                <div class="info-item">
                    <label>Visiting Unit</label>
                    <div class="val"><?= e($resident_unit) ?></div>
                </div>
                
                <div class="info-item" style="grid-column: span 2;">
                    <label>Allocated Parking</label>
                    <div class="val" style="color: var(--primary); font-size: 1.2rem;"><?= e($parking_info) ?></div>
                </div>

                <div class="info-item" style="grid-column: span 2;">
                    <label>Vehicle Plate</label>
                    <br>
                    <div class="plate-highlight"><?= e($pass['plate_no']) ?></div>
                </div>
                
                <div class="info-item">
                    <label>Valid From</label>
                    <div class="val" style="font-size: 0.85rem;"><?= date('d M, h:i A', strtotime($pass['start_time'])) ?></div>
                </div>
                <div class="info-item">
                    <label>Valid Until</label>
                    <div class="val" style="font-size: 0.85rem;"><?= date('d M, h:i A', strtotime($pass['end_time'])) ?></div>
                </div>
            </div>
        </div>
        
        <?php if($pass['status'] === 'checked_in'): ?>
            <div class="status-banner checked_in"><i class="fas fa-car-side"></i> Vehicle Currently Inside</div>
        <?php else: ?>
            <div class="status-banner"><i class="fas fa-check-circle"></i> Approved & Ready for Entry</div>
        <?php endif; ?>
    </div>

    <div style="position: absolute; bottom: 20px; width: 100%; text-align: center;">
        <a href="visitor_book.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>

</body>
</html>