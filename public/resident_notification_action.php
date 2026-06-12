<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../core/security.php';
    require_login(['resident']);

    $pdo = db();
    $residentId = (int)($_SESSION['uid'] ?? 0);

    if ($residentId <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Invalid resident session.']);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE notifications
        SET is_read = 1
        WHERE user_id = ?
        AND is_read = 0
    ");
    $stmt->execute([$residentId]);

    echo json_encode([
        'ok' => true,
        'updated' => $stmt->rowCount()
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to update notifications.'
    ]);
}
