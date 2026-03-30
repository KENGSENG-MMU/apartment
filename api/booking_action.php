<?php
// 文件路径: api/booking_action.php
require_once '../core/security.php';
require_login(['resident', 'admin']);
header('Content-Type: application/json');

$pdo = db();
$booking_id = (int)($_POST['booking_id'] ?? 0);
$action = $_POST['action'] ?? '';
$resident_id = $_SESSION['uid'];

// 1. 安全校验：确认这个预约真的是属于当前住户的，并且状态是 pending
$stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? AND resident_id = ? AND status = 'pending'");
$stmt->execute([$booking_id, $resident_id]);
$booking = $stmt->fetch();

if (!$booking) {
    echo json_encode(['success' => false, 'message' => 'Invalid booking or already processed.']);
    exit;
}

if ($action === 'approve') {
    // 2. 【拿A核心：动态分配】寻找 1 个可用的访客停车位
    $slotStmt = $pdo->query("SELECT id, slot_no, block_name FROM parking_slots WHERE slot_type = 'Visitor' AND status = 'available' LIMIT 1");
    $slot = $slotStmt->fetch();

    if ($slot) {
        try {
            $pdo->beginTransaction();
            // 更新预约状态为 allocated，并绑定车位 ID
            $pdo->prepare("UPDATE bookings SET status = 'allocated', slot_id = ? WHERE id = ?")->execute([$slot['id'], $booking_id]);
            // 把该停车位标记为 reserved (已被预订)
            $pdo->prepare("UPDATE parking_slots SET status = 'reserved' WHERE id = ?")->execute([$slot['id']]);
            $pdo->commit();

            log_audit('BOOKING_APPROVED', "Booking $booking_id approved. Slot: " . $slot['slot_no']);
            
            echo json_encode(['success' => true, 'slot' => $slot['block_name'] . ' - ' . $slot['slot_no']]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Database error during allocation.']);
        }
    } else {
        // 如果 50 个车位全满了！
        echo json_encode(['success' => false, 'message' => 'Visitor Parking is FULL! Cannot approve right now.']);
    }
} elseif ($action === 'reject') {
    // 3. 拒绝访问
    $pdo->prepare("UPDATE bookings SET status = 'rejected' WHERE id = ?")->execute([$booking_id]);
    log_audit('BOOKING_REJECTED', "Booking $booking_id rejected by resident.");
    echo json_encode(['success' => true]);
}
?>