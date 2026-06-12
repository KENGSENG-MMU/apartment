<?php
require_once '../core/security.php';
require_login(['resident', 'visitor']);

header('Content-Type: application/json; charset=utf-8');

function feedback_json_response(bool $ok, string $message = '', array $extra = []): void {
    echo json_encode(array_merge([
        'ok' => $ok,
        'message' => $message
    ], $extra));
    exit;
}

function ensure_auto_feedback_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS resident_feedback (
            id INT AUTO_INCREMENT PRIMARY KEY,
            resident_id INT NOT NULL,
            rating TINYINT NOT NULL DEFAULT 5,
            category VARCHAR(50) NOT NULL DEFAULT 'General',
            subject VARCHAR(150) NOT NULL,
            message TEXT NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'open',
            admin_reply TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL,
            INDEX idx_resident_feedback_resident (resident_id),
            INDEX idx_resident_feedback_status (status),
            INDEX idx_resident_feedback_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        feedback_json_response(false, 'Invalid request.');
    }

    $csrf = (string)($data['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        feedback_json_response(false, 'Invalid security token. Please refresh the page.');
    }

    $userId = (int)($_SESSION['uid'] ?? 0);
    $userRole = (string)($_SESSION['role'] ?? 'user');
    if ($userId <= 0) {
        feedback_json_response(false, 'Session expired. Please login again.');
    }

    $functionKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($data['function_key'] ?? 'smartvms_function'));
    $functionName = trim((string)($data['function_name'] ?? 'SmartVMS Function'));
    $rating = (int)($data['rating'] ?? 0);
    $comment = trim((string)($data['comment'] ?? ''));
    $pageUrl = trim((string)($data['page_url'] ?? ''));

    if ($functionName === '') {
        $functionName = 'SmartVMS Function';
    }

    if ($rating < 1 || $rating > 5) {
        feedback_json_response(false, 'Please choose a rating from 1 to 5.');
    }

    if (mb_strlen($comment) > 1200) {
        feedback_json_response(false, 'Comment is too long. Please keep it below 1200 characters.');
    }

    $pdo = db();
    ensure_auto_feedback_table($pdo);

    $subject = 'Auto Feedback - ' . mb_substr($functionName, 0, 120);
    $message = "User Role: {$userRole}\nFunction: {$functionName}\nFunction Key: {$functionKey}\nPage: {$pageUrl}\n\nComment:\n" . ($comment !== '' ? $comment : '-');

    $stmt = $pdo->prepare("
        INSERT INTO resident_feedback
            (resident_id, rating, category, subject, message, status, created_at)
        VALUES
            (?, ?, 'System Experience', ?, ?, 'open', NOW())
    ");

    $stmt->execute([
        $userId,
        $rating,
        $subject,
        $message
    ]);

    if (function_exists('log_audit')) {
        log_audit(
            'AUTO_FEEDBACK_SUBMITTED',
            ucfirst($userRole) . ' submitted auto feedback for ' . $functionName . '. Rating: ' . $rating
        );
    }

    feedback_json_response(true, 'Feedback saved successfully.');

} catch (Throwable $e) {
    feedback_json_response(false, 'Unable to save feedback. ' . $e->getMessage());
}
