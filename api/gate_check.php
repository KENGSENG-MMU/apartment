<?php
// 文件路径: api/gate_check.php
require_once '../core/security.php';

// 只允许保安和管理员调用此接口
require_login(['guard', 'admin', 'superadmin']);
header('Content-Type: application/json');

$plateNo = strtoupper(trim($_POST['plate_no'] ?? ''));
$gateAction = strtoupper(trim($_POST['gate_action'] ?? 'ENTRY')); // ENTRY 或 EXIT
$guard_id = $_SESSION['uid'];

if (empty($plateNo)) {
    echo json_encode(['success' => false, 'reason' => 'Plate number is empty.']);
    exit;
}

$pdo = db();

// ==========================================
// 🚗 通用防潜回检查函数 (检查最后一次大门动作)
// ==========================================
function checkLastAction($pdo, $plateNo) {
    $stmtLog = $pdo->prepare("SELECT gate_action FROM gate_logs WHERE plate_no = ? ORDER BY id DESC LIMIT 1");
    $stmtLog->execute([$plateNo]);
    return $stmtLog->fetchColumn();
}

if ($gateAction === 'ENTRY') {
    // ==========================================
    // 🚪 进门逻辑 (ENTRY)
    // ==========================================
    $lastAction = checkLastAction($pdo, $plateNo);
    if ($lastAction === 'ENTRY') {
        // 不管是住户还是访客，只要已经在里面了，就不准再进！
        $pdo->prepare("INSERT INTO gate_logs (plate_no, gate_action, decision, reason, guard_id) VALUES (?, 'ENTRY', 'DENY', 'Anti-passback: Vehicle already inside', ?)")->execute([$plateNo, $guard_id]);
        echo json_encode(['success' => false, 'reason' => 'Anti-passback violation: Vehicle is already inside!']);
        exit;
    }

    // 🌟 通道 1：先检查是不是【住户白名单车辆】
    $stmtRes = $pdo->prepare("
        SELECT v.*, u.email as resident_email 
        FROM resident_vehicles v 
        LEFT JOIN users u ON v.resident_id = u.id 
        WHERE v.plate_no = ?
    ");
    $stmtRes->execute([$plateNo]);
    $residentVehicle = $stmtRes->fetch();

    if ($residentVehicle) {
        // 匹配到住户车辆！直接放行，不走访客逻辑
        $resident_name = "Resident (" . explode('@', $residentVehicle['resident_email'])[0] . ")";
        
        $pdo->prepare("INSERT INTO gate_logs (plate_no, gate_action, decision, reason, guard_id) VALUES (?, 'ENTRY', 'ALLOW', 'Resident Whitelist', ?)")->execute([$plateNo, $guard_id]);
        
        echo json_encode([
            'success' => true, 
            'action' => 'ENTRY', 
            'visitor_name' => $resident_name . ' 👑', // 加上小皇冠标识
            'parking_slot' => 'Private Resident Bay', 
            'plate_no' => $plateNo
        ]);
        exit;
    }

    // 🌟 通道 2：如果不是住户，则检查【访客预约表】
    // (获取 Super Admin 配置的 Grace Time，如果没有则默认 15 分钟)
    $graceTime = $pdo->query("SELECT grace_minutes FROM system_config WHERE id = 1")->fetchColumn() ?: 15;

    $stmt = $pdo->prepare("
        SELECT b.id as booking_id, b.visitor_name, b.status, p.id as slot_id, p.block_name, p.slot_no
        FROM bookings b
        LEFT JOIN parking_slots p ON b.slot_id = p.id
        WHERE b.plate_no = ? AND b.status IN ('approved', 'allocated')
        AND b.start_time <= NOW() AND b.end_time >= NOW() - INTERVAL ? MINUTE
        ORDER BY b.id DESC LIMIT 1
    ");
    $stmt->execute([$plateNo, $graceTime]);
    $booking = $stmt->fetch();

    if ($booking) {
        // 访客放行，更新状态
        $pdo->prepare("UPDATE bookings SET status = 'checked_in' WHERE id = ?")->execute([$booking['booking_id']]);
        $pdo->prepare("INSERT INTO gate_logs (booking_id, plate_no, gate_action, decision, reason, guard_id) VALUES (?, ?, 'ENTRY', 'ALLOW', 'Valid Visitor Booking', ?)")->execute([$booking['booking_id'], $plateNo, $guard_id]);

        $parking_info = $booking['block_name'] ? $booking['block_name'] . ' - ' . $booking['slot_no'] : 'Visitor Bay';
        echo json_encode(['success' => true, 'action' => 'ENTRY', 'visitor_name' => $booking['visitor_name'], 'parking_slot' => $parking_info, 'plate_no' => $plateNo]);
    } else {
        // 啥都没查到 -> 拦截
        $pdo->prepare("INSERT INTO gate_logs (plate_no, gate_action, decision, reason, guard_id) VALUES (?, 'ENTRY', 'DENY', 'No valid booking or unregistered resident', ?)")->execute([$plateNo, $guard_id]);
        echo json_encode(['success' => false, 'reason' => 'Access Denied: Unregistered vehicle or no active booking.']);
    }

} elseif ($gateAction === 'EXIT') {
    // ==========================================
    // 🚪 出门逻辑 (EXIT)
    // ==========================================
    $lastAction = checkLastAction($pdo, $plateNo);
    if ($lastAction !== 'ENTRY') {
        $pdo->prepare("INSERT INTO gate_logs (plate_no, gate_action, decision, reason, guard_id) VALUES (?, 'EXIT', 'DENY', 'Vehicle not checked in', ?)")->execute([$plateNo, $guard_id]);
        echo json_encode(['success' => false, 'reason' => 'Vehicle is not currently inside the premises!']);
        exit;
    }

    // 🌟 通道 1：检查是否是住户车辆出门
    $stmtRes = $pdo->prepare("SELECT * FROM resident_vehicles WHERE plate_no = ?");
    $stmtRes->execute([$plateNo]);
    if ($stmtRes->fetch()) {
        $pdo->prepare("INSERT INTO gate_logs (plate_no, gate_action, decision, reason, guard_id) VALUES (?, 'EXIT', 'ALLOW', 'Resident Exited', ?)")->execute([$plateNo, $guard_id]);
        echo json_encode(['success' => true, 'action' => 'EXIT', 'message' => 'Have a safe trip, Resident!']);
        exit;
    }

    // 🌟 通道 2：访客车辆出门 -> 需要释放车位！
    $stmt = $pdo->prepare("
        SELECT b.id as booking_id, b.visitor_name, p.id as slot_id, p.block_name, p.slot_no
        FROM bookings b
        LEFT JOIN parking_slots p ON b.slot_id = p.id
        WHERE b.plate_no = ? AND b.status = 'checked_in'
        ORDER BY b.id DESC LIMIT 1
    ");
    $stmt->execute([$plateNo]);
    $booking = $stmt->fetch();

    if ($booking) {
        try {
            $pdo->beginTransaction();
            // 释放车位
            if ($booking['slot_id']) {
                $pdo->prepare("UPDATE parking_slots SET status = 'available' WHERE id = ?")->execute([$booking['slot_id']]);
            }
            // 完结订单
            $pdo->prepare("UPDATE bookings SET status = 'completed' WHERE id = ?")->execute([$booking['booking_id']]);
            // 写入日志
            $pdo->prepare("INSERT INTO gate_logs (booking_id, plate_no, gate_action, decision, reason, guard_id) VALUES (?, ?, 'EXIT', 'ALLOW', 'Visitor Checked Out', ?)")->execute([$booking['booking_id'], $plateNo, $guard_id]);
            
            $pdo->commit();

            $released_info = $booking['block_name'] ? $booking['block_name'] . '-' . $booking['slot_no'] : 'Visitor Bay';
            echo json_encode(['success' => true, 'action' => 'EXIT', 'message' => "Visitor Exited. Slot $released_info released."]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'reason' => 'Database error during exit process.']);
        }
    } else {
         echo json_encode(['success' => false, 'reason' => 'System error: Cannot find matched check-in record.']);
    }
}
?>