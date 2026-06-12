<?php
require_once '../core/security.php';
require_login(['resident', 'admin']);

header('Content-Type: application/json');

$pdo = db();

function json_response($success, $message, $extra = []) {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit;
}

function estimate_waiting_time(PDO $pdo): int {
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM bookings
        WHERE status = 'waiting'
    ");

    $count = (int)$stmt->fetchColumn();

    return ($count + 1) * 10;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Invalid request method.');
}

$csrf = $_POST['csrf_token'] ?? '';

if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    json_response(false, 'Invalid security token. Please refresh the page.');
}

$bookingId = (int)($_POST['booking_id'] ?? 0);
$action = strtolower(trim($_POST['action'] ?? ''));

if ($bookingId <= 0) {
    json_response(false, 'Invalid booking selected.');
}

if (!in_array($action, ['approve', 'reject'], true)) {
    json_response(false, 'Invalid action.');
}

$currentUserId = (int)($_SESSION['uid'] ?? 0);
$currentRole = $_SESSION['role'] ?? '';

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT *
        FROM bookings
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    if (!$booking) {
        $pdo->rollBack();
        json_response(false, 'Booking not found.');
    }

    if ($currentRole === 'resident' && (int)$booking['resident_id'] !== $currentUserId) {
        $pdo->rollBack();
        json_response(false, 'You are not allowed to manage this booking.');
    }

    if (!in_array($booking['status'], ['pending'], true)) {
        $pdo->rollBack();
        json_response(false, 'This booking is already processed.');
    }

    if ($action === 'reject') {
        $update = $pdo->prepare("
            UPDATE bookings
            SET status = 'rejected'
            WHERE id = ?
        ");
        $update->execute([$bookingId]);

        if (function_exists('log_audit')) {
            log_audit('BOOKING_REJECTED', 'Booking rejected. Booking ID: ' . $bookingId);
        }

        $pdo->commit();

        json_response(true, 'Visitor booking rejected successfully.', [
            'status' => 'rejected'
        ]);
    }

    /*
        Approve booking:
        1. Find available fixed visitor slot
        2. If found, booking = allocated, slot = reserved
        3. If no slot, booking = waiting
    */
    $slotStmt = $pdo->prepare("
        SELECT id, block_name, slot_no
        FROM parking_slots
        WHERE slot_type = 'Visitor'
        AND status = 'available'
        ORDER BY id ASC
        LIMIT 1
        FOR UPDATE
    ");
    $slotStmt->execute();
    $slot = $slotStmt->fetch();

    if ($slot) {
        $updateBooking = $pdo->prepare("
            UPDATE bookings
            SET status = 'allocated',
                slot_id = ?
            WHERE id = ?
        ");
        $updateBooking->execute([
            (int)$slot['id'],
            $bookingId
        ]);

        $updateSlot = $pdo->prepare("
            UPDATE parking_slots
            SET status = 'reserved'
            WHERE id = ?
        ");
        $updateSlot->execute([(int)$slot['id']]);

        $slotLabel = trim(($slot['block_name'] ?? '') . ' ' . ($slot['slot_no'] ?? ''));

        if (function_exists('log_audit')) {
            log_audit('BOOKING_APPROVED', 'Booking approved and assigned slot ' . $slotLabel . '. Booking ID: ' . $bookingId);
        }

        $pdo->commit();

        json_response(true, 'Visitor approved and parking slot allocated.', [
            'status' => 'allocated',
            'waiting' => false,
            'slot' => $slotLabel
        ]);
    }

    /*
        No parking slot available.
        Move booking to waiting queue.
    */
    $estimatedWait = estimate_waiting_time($pdo);

    $updateBooking = $pdo->prepare("
        UPDATE bookings
        SET status = 'waiting',
            slot_id = NULL
        WHERE id = ?
    ");
    $updateBooking->execute([$bookingId]);

    if (function_exists('log_audit')) {
        log_audit('BOOKING_WAITING', 'Booking moved to waiting queue. Booking ID: ' . $bookingId);
    }

    $pdo->commit();

    json_response(true, 'Parking is full. Visitor moved to waiting queue.', [
        'status' => 'waiting',
        'waiting' => true,
        'estimated_wait' => $estimatedWait
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response(false, 'System error: ' . $e->getMessage());
}